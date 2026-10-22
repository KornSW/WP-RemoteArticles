<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

use KornSW\RemoteArticles\Remote\RemoteClient;
use KornSW\RemoteArticles\Remote\RemoteException;
use KornSW\RemoteArticles\Rendering\AssetLoader;
use KornSW\RemoteArticles\Sources\Source;
use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * Adapter for galleries of the plugin "Share-one-Drive" (doc/security.md, section 8).
 *
 * The gallery loads its images only via script and AJAX. Instead of running foreign scripts, the plugin:
 * 1. replaces the gallery module by a placeholder carrying a signed URL of its own REST endpoint;
 * 2. on request of that URL (by assets/gallery.js), reads the anonymous gallery nonce from the original page and requests
 *    "shareonedrive-get-gallery" from admin-ajax.php of the source, as a visitor of the original page would;
 * 3. extracts only data (URLs on Microsoft media hosts, titles, sizes) and resolves video streams to their Microsoft URL;
 * 4. returns that data as JSON; the script builds the gallery with DOM methods and opens it in the plugin's lightbox.
 *
 * The image URLs contain access tokens that expire after a few hours, so galleries are cached for one hour only.
 *
 * The same endpoint serves galleries of "NextGEN Gallery" (kind "nextgen"): their placeholders in the REST content are
 * matched by order to the galleries on the original page (NextGenGalleryParser); those image URLs do not expire.
 */
final class ShareOneDriveGalleries {

  public const REST_NAMESPACE = 'kornsw-remotearticles/v1';
  public const REST_ROUTE = '/gallery';
  public const LAST_ERROR_OPTION = 'kornsw_ra_gallery_last_error';

  /** A gallery counts as fresh for one hour ... */
  private const FRESH_SECONDS = HOUR_IN_SECONDS;
  /** ... and serves as fallback for three hours, still within the lifetime of the Microsoft tokens (about four hours). */
  private const STALE_SECONDS = 3 * HOUR_IN_SECONDS;
  private const NONCE_CACHE_SECONDS = 6 * HOUR_IN_SECONDS;
  /** The gallery request of the source can take far longer than a normal request when the source rebuilds its cache. */
  private const GALLERY_TIMEOUT_SECONDS = 25;
  private const VIDEO_TIMEOUT_SECONDS = 15;
  private const ENDPOINT_TIME_LIMIT_SECONDS = 60;
  private const SIGNATURE_CONTEXT = 'kornsw-remotearticles|gallery';
  /** NextGEN galleries come from the original page; its image URLs do not expire. */
  private const NEXTGEN_CACHE_SECONDS = 6 * HOUR_IN_SECONDS;
  private const KIND_SHARE_ONE_DRIVE = 'sod';
  private const KIND_NEXTGEN = 'nextgen';

  public function __construct(
    private readonly SourceRepository $sourceRepository,
    private readonly RemoteClient $remoteClient,
    private readonly ShareOneDriveResponseParser $responseParser,
    private readonly NextGenGalleryParser $nextGenGalleryParser,
    private readonly GalleryPlaceholderInserter $placeholderInserter,
    private readonly AssetLoader $assetLoader
  ) {
  }

  /**
   * Registers the REST route.
   */
  public function register(): void {
    add_action('rest_api_init', [$this, 'registerRoute']);
  }

  /**
   * Registers GET /wp-json/kornsw-remotearticles/v1/gallery?d=…&s=…
   */
  public function registerRoute(): void {
    register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
      'methods' => 'GET',
      'callback' => [$this, 'handleRequest'],
      // Public on purpose: visitors load galleries. Only requests signed by this site are served (see handleRequest()).
      'permission_callback' => '__return_true',
      'args' => [
        'd' => ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9_-]{1,4000}$'],
        's' => ['type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{64}$'],
      ],
    ]);
  }

  /**
   * Replaces the gallery modules in sanitized content of the given source by placeholders.
   *
   * @param string $documentUrl URL of the original post (its page carries the nonce of the gallery requests).
   */
  public function embed(string $sanitizedHtml, Source $source, string $documentUrl): string {
    $result = $this->placeholderInserter->insert(
      $sanitizedHtml,
      function (ShareOneDriveModule $module) use ($source, $documentUrl): string {
        return $this->buildEndpointUrl(['alias' => $source->alias, 'document' => $documentUrl, 'kind' => self::KIND_SHARE_ONE_DRIVE, 'module' => $module->toArray()]);
      },
      function (int $index) use ($source, $documentUrl): string {
        return $this->buildEndpointUrl(['alias' => $source->alias, 'document' => $documentUrl, 'kind' => self::KIND_NEXTGEN, 'index' => $index]);
      }
    );
    if ($result['count'] > 0) {
      $this->assetLoader->enqueueGallery();
    }
    return $result['html'];
  }

  /**
   * Serves the items of a gallery to the gallery script.
   */
  public function handleRequest(\WP_REST_Request $request): \WP_REST_Response {
    $payload = (string)$request->get_param('d');
    $signature = (string)$request->get_param('s');
    if (!hash_equals($this->sign($payload), $signature)) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Ungültige Anfrage.'], 403);
    }

    $base64 = strtr($payload, '-_', '+/');
    $base64 = str_pad($base64, (int)(ceil(strlen($base64) / 4) * 4), '=');
    $data = json_decode((string)base64_decode($base64, true), true);
    if (!is_array($data)) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Ungültige Anfrage.'], 400);
    }
    $source = $this->sourceRepository->find((string)($data['alias'] ?? ''));
    $documentUrl = (string)($data['document'] ?? '');
    $kind = (string)($data['kind'] ?? self::KIND_SHARE_ONE_DRIVE);
    if ($source === null) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Galerie nicht verfügbar.'], 404);
    }

    // Slow sources (see GALLERY_TIMEOUT_SECONDS) must not hit the default PHP time limit of 30 seconds.
    if (function_exists('set_time_limit')) {
      set_time_limit(self::ENDPOINT_TIME_LIMIT_SECONDS);
    }

    try {
      if ($kind === self::KIND_NEXTGEN) {
        $items = $this->getNextGenItems($source, $documentUrl, max(0, (int)($data['index'] ?? 0)));
        $height = $this->getThumbnailHeight($items);
      } else {
        $module = null;
        if (isset($data['module']) && is_array($data['module'])) {
          $module = ShareOneDriveModule::fromArray($data['module']);
        }
        if ($module === null) {
          return new \WP_REST_Response(['items' => [], 'message' => 'Galerie nicht verfügbar.'], 404);
        }
        $items = $this->getItems($source, $documentUrl, $module);
        $height = $module->targetHeight;
      }
    } catch (RemoteException | \InvalidArgumentException $exception) {
      // Visitors get no details (doc/security.md, section 6); administrators see the last error on the settings page.
      update_option(self::LAST_ERROR_OPTION, ['time' => time(), 'alias' => $source->alias, 'message' => $exception->getMessage()], false);
      return new \WP_REST_Response(['items' => [], 'message' => 'Die Galerie konnte nicht geladen werden.'], 502);
    }

    $response = new \WP_REST_Response(['items' => $items, 'height' => $height], 200);
    $response->header('Cache-Control', 'public, max-age=600');
    return $response;
  }

  /**
   * Returns the last error of a gallery request (for administrators), or null.
   *
   * @return array{time: int, alias: string, message: string}|null
   */
  public function getLastError(): ?array {
    $error = get_option(self::LAST_ERROR_OPTION, null);
    if (!is_array($error) || !isset($error['time'], $error['alias'], $error['message'])) {
      return null;
    }
    return ['time' => (int)$error['time'], 'alias' => (string)$error['alias'], 'message' => (string)$error['message']];
  }

  /**
   * Returns the gallery items: fresh from the cache, else loaded from the source, else (on failure) the last state as long
   * as its media URLs are still valid.
   *
   * @return list<array<string, string|int>>
   * @throws RemoteException|\InvalidArgumentException
   */
  private function getItems(Source $source, string $documentUrl, ShareOneDriveModule $module): array {
    $cacheName = 'kornsw_ra_gal_' . md5($source->alias . '|' . $source->baseUrl . '|' . (string)wp_json_encode($module->toArray()));
    $entry = get_transient($cacheName);
    $hasEntry = is_array($entry) && isset($entry['storedAt'], $entry['items']) && is_array($entry['items']);
    if ($hasEntry && time() - (int)$entry['storedAt'] < self::FRESH_SECONDS) {
      return $entry['items'];
    }

    try {
      $items = $this->loadAndResolveItems($source, $documentUrl, $module);
    } catch (RemoteException | \InvalidArgumentException $exception) {
      if ($hasEntry) {
        return $entry['items'];
      }
      throw $exception;
    }

    set_transient($cacheName, ['storedAt' => time(), 'items' => $items], self::STALE_SECONDS);
    return $items;
  }

  /**
   * Loads the items from the source and resolves the video streams.
   *
   * @return list<array<string, string|int>>
   * @throws RemoteException|\InvalidArgumentException
   */
  private function loadAndResolveItems(Source $source, string $documentUrl, ShareOneDriveModule $module): array {
    $items = [];
    foreach ($this->loadItems($source, $documentUrl, $module) as $item) {
      if ($item->type === 'video') {
        $resolvedItem = $this->resolveVideo($source, $item);
        if ($resolvedItem === null) {
          continue;
        }
        $item = $resolvedItem;
      }
      $items[] = $item->toArray();
    }
    return $items;
  }

  /**
   * Requests the gallery from the source; renews the nonce once if the cached one was rejected.
   *
   * @return list<GalleryItem>
   * @throws RemoteException|\InvalidArgumentException
   */
  private function loadItems(Source $source, string $documentUrl, ShareOneDriveModule $module): array {
    $sourceHost = $source->getHost();
    $nonceName = 'kornsw_ra_sod_nonce_' . md5($source->alias . '|' . $source->baseUrl);
    $nonce = get_transient($nonceName);
    $isCachedNonce = is_string($nonce) && $nonce !== '';
    if (!$isCachedNonce) {
      $nonce = $this->readNonce($source, $documentUrl);
      set_transient($nonceName, $nonce, self::NONCE_CACHE_SECONDS);
    }

    $body = $this->remoteClient->postAdminAjax($source, $module->buildRequestFields($nonce), $documentUrl, self::GALLERY_TIMEOUT_SECONDS);
    try {
      return $this->responseParser->parse($body, $sourceHost);
    } catch (\InvalidArgumentException $exception) {
      if (!$isCachedNonce) {
        throw $exception;
      }
    }

    // The cached nonce has expired: read a fresh one and try once more.
    $nonce = $this->readNonce($source, $documentUrl);
    set_transient($nonceName, $nonce, self::NONCE_CACHE_SECONDS);
    $body = $this->remoteClient->postAdminAjax($source, $module->buildRequestFields($nonce), $documentUrl, self::GALLERY_TIMEOUT_SECONDS);
    return $this->responseParser->parse($body, $sourceHost);
  }

  /**
   * Reads the anonymous gallery nonce that Share-one-Drive prints into pages with galleries ("ShareoneDrive_vars").
   *
   * @throws RemoteException
   */
  private function readNonce(Source $source, string $documentUrl): string {
    $page = $this->remoteClient->getPage($source, $documentUrl);
    if (preg_match('/"gallery_nonce"\s*:\s*"([a-f0-9]{10})"/', $page, $matches) !== 1) {
      throw new RemoteException(sprintf('Auf der Originalseite der Quelle „%s“ fehlen die Angaben der Galerie.', $source->alias));
    }
    return $matches[1];
  }

  /**
   * Resolves the stream URL of a video (served by the source only without foreign referer) to its Microsoft URL.
   */
  private function resolveVideo(Source $source, GalleryItem $item): ?GalleryItem {
    try {
      $target = $this->remoteClient->getRedirectTarget($source, $item->mediaUrl, self::VIDEO_TIMEOUT_SECONDS);
    } catch (RemoteException) {
      return null;
    }
    if ($target === null || !$this->responseParser->isAllowedMediaUrl($target)) {
      return null;
    }
    return $item->withMediaUrl($target);
  }

  /**
   * Returns the items of the n-th NextGEN gallery of the original page (page galleries cached for six hours).
   *
   * @return list<array<string, string|int>>
   * @throws RemoteException
   */
  private function getNextGenItems(Source $source, string $documentUrl, int $index): array {
    $cacheName = 'kornsw_ra_ngg_' . md5($source->alias . '|' . $source->baseUrl . '|' . $documentUrl);
    $galleries = get_transient($cacheName);
    if (!is_array($galleries)) {
      $page = $this->remoteClient->getPage($source, $documentUrl);
      $galleries = [];
      foreach ($this->nextGenGalleryParser->parse($page, $documentUrl, $source->getHost()) as $galleryItems) {
        $items = [];
        foreach ($galleryItems as $item) {
          $items[] = $item->toArray();
        }
        $galleries[] = $items;
      }
      set_transient($cacheName, $galleries, self::NEXTGEN_CACHE_SECONDS);
    }

    if (!isset($galleries[$index]) || !is_array($galleries[$index])) {
      throw new RemoteException(sprintf('Die NextGEN-Galerie Nr. %d fehlt auf der Originalseite der Quelle „%s“.', $index + 1, $source->alias));
    }
    return $galleries[$index];
  }

  /**
   * Row height for NextGEN galleries: the height of their thumbnails (no upscaling), within sensible limits.
   *
   * @param list<array<string, string|int>> $items
   */
  private function getThumbnailHeight(array $items): int {
    $height = 0;
    foreach ($items as $item) {
      $height = max($height, (int)$item['height']);
    }
    if ($height === 0) {
      return 160;
    }
    return max(80, min(400, $height));
  }

  /**
   * Builds the signed URL of the gallery endpoint.
   *
   * @param array<string, mixed> $request Alias, document URL, kind and the gallery parameters of that kind.
   */
  private function buildEndpointUrl(array $request): string {
    $json = (string)wp_json_encode($request);
    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    return add_query_arg(['d' => $payload, 's' => $this->sign($payload)], rest_url(self::REST_NAMESPACE . self::REST_ROUTE));
  }

  /**
   * Signs a payload with a key derived from the salts of this installation.
   */
  private function sign(string $payload): string {
    return hash_hmac('sha256', $payload, hash_hmac('sha256', self::SIGNATURE_CONTEXT, wp_salt('auth')));
  }

}
