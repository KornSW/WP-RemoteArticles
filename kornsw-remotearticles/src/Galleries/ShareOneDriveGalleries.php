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
 */
final class ShareOneDriveGalleries {

  public const REST_NAMESPACE = 'kornsw-remotearticles/v1';
  public const REST_ROUTE = '/gallery';

  private const CACHE_SECONDS = HOUR_IN_SECONDS;
  private const NONCE_CACHE_SECONDS = 6 * HOUR_IN_SECONDS;
  private const SIGNATURE_CONTEXT = 'kornsw-remotearticles|gallery';

  public function __construct(
    private readonly SourceRepository $sourceRepository,
    private readonly RemoteClient $remoteClient,
    private readonly ShareOneDriveResponseParser $responseParser,
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
        return $this->buildEndpointUrl($source->alias, $documentUrl, $module);
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
    if (!is_array($data) || !isset($data['module']) || !is_array($data['module'])) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Ungültige Anfrage.'], 400);
    }
    $source = $this->sourceRepository->find((string)($data['alias'] ?? ''));
    $module = ShareOneDriveModule::fromArray($data['module']);
    $documentUrl = (string)($data['document'] ?? '');
    if ($source === null || $module === null) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Galerie nicht verfügbar.'], 404);
    }

    try {
      $items = $this->getItems($source, $documentUrl, $module);
    } catch (RemoteException | \InvalidArgumentException) {
      return new \WP_REST_Response(['items' => [], 'message' => 'Die Galerie konnte nicht geladen werden.'], 502);
    }

    $response = new \WP_REST_Response(['items' => $items, 'height' => $module->targetHeight], 200);
    $response->header('Cache-Control', 'public, max-age=600');
    return $response;
  }

  /**
   * Returns the gallery items (cached for one hour).
   *
   * @return list<array<string, string|int>>
   * @throws RemoteException|\InvalidArgumentException
   */
  private function getItems(Source $source, string $documentUrl, ShareOneDriveModule $module): array {
    $cacheName = 'kornsw_ra_gal_' . md5($source->alias . '|' . $source->baseUrl . '|' . (string)wp_json_encode($module->toArray()));
    $cachedItems = get_transient($cacheName);
    if (is_array($cachedItems)) {
      return $cachedItems;
    }

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

    set_transient($cacheName, $items, self::CACHE_SECONDS);
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

    $body = $this->remoteClient->postAdminAjax($source, $module->buildRequestFields($nonce), $documentUrl);
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
    $body = $this->remoteClient->postAdminAjax($source, $module->buildRequestFields($nonce), $documentUrl);
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
      $target = $this->remoteClient->getRedirectTarget($source, $item->mediaUrl);
    } catch (RemoteException) {
      return null;
    }
    if ($target === null || !$this->responseParser->isAllowedMediaUrl($target)) {
      return null;
    }
    return $item->withMediaUrl($target);
  }

  /**
   * Builds the signed URL of the gallery endpoint for one module.
   */
  private function buildEndpointUrl(string $alias, string $documentUrl, ShareOneDriveModule $module): string {
    $json = (string)wp_json_encode(['alias' => $alias, 'document' => $documentUrl, 'module' => $module->toArray()]);
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
