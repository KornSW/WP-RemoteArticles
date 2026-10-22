<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

use KornSW\RemoteArticles\Embedding\EmbeddingContext;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Remote\RemoteClient;
use KornSW\RemoteArticles\Remote\RemoteException;
use KornSW\RemoteArticles\Sources\Source;

/**
 * Mirrors the posts selected by the import rules of a source into local capsules.
 *
 * 1. resolve the remote categories / tags of the rules to IDs;
 * 2. list the IDs and modification times of all matching posts (cheap);
 * 3. load full data only for new or changed posts, in batches and limited per run;
 * 4. assign the local terms (created on demand) to every matching capsule;
 * 5. delete capsules whose post is gone or no longer matches any rule.
 *
 * Step 5 runs only if the listing of step 2 was complete; an incomplete picture must never delete capsules.
 */
final class Synchronizer {

  private const PAGE_SIZE = 100;
  private const MAXIMUM_PAGES_PER_TERM = 20;
  private const DETAIL_BATCH_SIZE = 20;
  private const MAXIMUM_DETAILS_PER_RUN = 300;
  /** Time budget for loading details; must stay well below typical PHP time limits (30 s) of a manual sync. */
  private const TIME_BUDGET_SECONDS = 15;
  private const FOLLOW_UP_DELAY_SECONDS = MINUTE_IN_SECONDS;
  private const LOCK_SECONDS = 15 * MINUTE_IN_SECONDS;
  private const EXCERPT_WORDS = 55;

  public function __construct(
    private readonly RemoteClient $remoteClient,
    private readonly CapsuleStore $capsuleStore,
    private readonly ImportRuleParser $importRuleParser,
    private readonly HtmlSanitizer $htmlSanitizer,
    private readonly UrlResolver $urlResolver,
    private readonly SyncStatusRepository $syncStatusRepository
  ) {
  }

  /**
   * Synchronizes one source and stores the result as its sync status. If posts are left over (time budget or per-run
   * limit), a follow-up run is scheduled shortly afterwards.
   *
   * @return array{success: bool, message: string}
   */
  public function synchronize(Source $source): array {
    $lockName = 'kornsw_ra_sync_lock_' . $source->alias;
    if (get_transient($lockName) !== false) {
      return ['success' => false, 'message' => sprintf('Für „%s“ läuft bereits eine Synchronisation.', $source->alias)];
    }
    set_transient($lockName, time(), self::LOCK_SECONDS);

    $pendingCount = 0;
    try {
      $summary = $this->runSynchronization($source);
      $pendingCount = $summary['pending'];
      $result = ['success' => true, 'message' => $summary['message']];
    } catch (RemoteException | \InvalidArgumentException | \RuntimeException $exception) {
      $result = ['success' => false, 'message' => $exception->getMessage()];
    } finally {
      delete_transient($lockName);
    }

    if ($pendingCount > 0) {
      wp_schedule_single_event(time() + self::FOLLOW_UP_DELAY_SECONDS, SyncScheduler::CRON_HOOK);
    }
    $this->syncStatusRepository->save($source->alias, $result['success'], $result['message'], $pendingCount);
    return $result;
  }

  /**
   * Performs the synchronization.
   *
   * @return array{message: string, pending: int} German summary and number of posts left for a follow-up run.
   * @throws RemoteException|\InvalidArgumentException|\RuntimeException
   */
  private function runSynchronization(Source $source): array {
    $startTime = microtime(true);
    $rules = $this->importRuleParser->parse($source->importRules);

    // remote post ID => [modified, local terms keyed by notation]
    $matchingPosts = [];
    foreach ($rules as $rule) {
      foreach ($rule->remoteTerms as $remoteTerm) {
        $remoteTermId = $this->resolveRemoteTermId($source, $remoteTerm);
        foreach ($this->listPostIds($source, $remoteTerm, $remoteTermId) as $remoteId => $modifiedGmt) {
          if (!isset($matchingPosts[$remoteId])) {
            $matchingPosts[$remoteId] = ['modified' => $modifiedGmt, 'terms' => []];
          }
          foreach ($rule->localTerms as $localTerm) {
            $matchingPosts[$remoteId]['terms'][$localTerm->toNotation()] = $localTerm;
          }
        }
      }
    }

    $existingCapsules = $this->capsuleStore->findByAlias($source->alias);

    $idsToLoad = [];
    foreach ($matchingPosts as $remoteId => $matchingPost) {
      $isNew = !isset($existingCapsules[$remoteId]);
      if ($isNew || $existingCapsules[$remoteId]['remoteModified'] !== $matchingPost['modified']) {
        $idsToLoad[] = $remoteId;
      }
    }
    // Newest posts first (higher IDs are newer), so that a partial run already shows the current posts.
    rsort($idsToLoad);
    $pendingCount = max(0, count($idsToLoad) - self::MAXIMUM_DETAILS_PER_RUN);
    $idsToLoad = array_slice($idsToLoad, 0, self::MAXIMUM_DETAILS_PER_RUN);

    $createdCount = 0;
    $updatedCount = 0;
    $batches = array_chunk($idsToLoad, self::DETAIL_BATCH_SIZE);
    foreach ($batches as $batchIndex => $batch) {
      if (microtime(true) - $startTime > self::TIME_BUDGET_SECONDS) {
        foreach (array_slice($batches, $batchIndex) as $skippedBatch) {
          $pendingCount += count($skippedBatch);
        }
        break;
      }
      foreach ($this->loadPostDetails($source, $batch) as $postData) {
        $capsuleData = $this->buildCapsuleData($source, $postData);
        if ($capsuleData === null || !isset($matchingPosts[$capsuleData->remoteId])) {
          continue;
        }
        $existingPostId = 0;
        if (isset($existingCapsules[$capsuleData->remoteId])) {
          $existingPostId = $existingCapsules[$capsuleData->remoteId]['postId'];
        }
        $postId = $this->capsuleStore->save($capsuleData, $existingPostId);
        $existingCapsules[$capsuleData->remoteId] = ['postId' => $postId, 'remoteModified' => $capsuleData->modifiedGmt];
        if ($existingPostId > 0) {
          $updatedCount++;
        } else {
          $createdCount++;
        }
      }
    }

    // Terms are assigned to every matching capsule: rules may have changed even if the post did not.
    $deletedCount = 0;
    foreach ($existingCapsules as $remoteId => $capsule) {
      if (isset($matchingPosts[$remoteId])) {
        $this->capsuleStore->assignTerms($capsule['postId'], array_values($matchingPosts[$remoteId]['terms']));
      } else {
        $this->capsuleStore->delete($capsule['postId']);
        $deletedCount++;
      }
    }

    $message = sprintf(
      '%d Beiträge passen zu den Regeln: %d neu, %d aktualisiert, %d entfernt.',
      count($matchingPosts),
      $createdCount,
      $updatedCount,
      $deletedCount
    );
    if ($pendingCount > 0) {
      $message .= sprintf(' %d weitere werden in Kürze automatisch übernommen.', $pendingCount);
    }
    return ['message' => $message, 'pending' => $pendingCount];
  }

  /**
   * Resolves a remote category or tag (slug or ID) to its ID.
   *
   * @throws RemoteException If it does not exist on the source.
   */
  public function resolveRemoteTermId(Source $source, TermReference $remoteTerm): int {
    if (preg_match('/^\d{1,10}$/', $remoteTerm->value) === 1) {
      return (int)$remoteTerm->value;
    }

    $route = '/wp/v2/categories';
    $notFoundMessage = 'Die Kategorie „%s“ gibt es auf der Quelle „%s“ nicht.';
    if ($remoteTerm->isTag()) {
      $route = '/wp/v2/tags';
      $notFoundMessage = 'Das Schlagwort „%s“ gibt es auf der Quelle „%s“ nicht.';
    }
    $terms = $this->remoteClient->getJson($source, $route, ['slug' => $remoteTerm->value, '_fields' => 'id']);
    $firstTerm = $terms[0] ?? null;
    if (!is_array($firstTerm) || !isset($firstTerm['id']) || !is_int($firstTerm['id'])) {
      throw new RemoteException(sprintf($notFoundMessage, $remoteTerm->value, $source->alias));
    }
    return $firstTerm['id'];
  }

  /**
   * Lists IDs and modification times of all published posts in a remote category or tag.
   *
   * @return array<int, string> Modification time (UTC) keyed by post ID.
   * @throws RemoteException If the listing is incomplete.
   */
  private function listPostIds(Source $source, TermReference $remoteTerm, int $remoteTermId): array {
    $filterParameter = 'categories';
    if ($remoteTerm->isTag()) {
      $filterParameter = 'tags';
    }

    $posts = [];
    for ($page = 1; $page <= self::MAXIMUM_PAGES_PER_TERM; $page++) {
      $pagePosts = $this->remoteClient->getJson($source, '/wp/v2/posts', [
        $filterParameter => $remoteTermId,
        'per_page' => self::PAGE_SIZE,
        'page' => $page,
        'orderby' => 'id',
        'order' => 'asc',
        '_fields' => 'id,modified_gmt',
      ]);
      foreach ($pagePosts as $pagePost) {
        if (is_array($pagePost) && isset($pagePost['id']) && is_int($pagePost['id'])) {
          $posts[$pagePost['id']] = (string)($pagePost['modified_gmt'] ?? '');
        }
      }
      if (count($pagePosts) < self::PAGE_SIZE) {
        return $posts;
      }
    }
    throw new RemoteException(sprintf(
      '„%s“ enthält mehr als %d Beiträge; das übersteigt die Obergrenze des Imports.',
      $remoteTerm->toNotation(),
      self::PAGE_SIZE * self::MAXIMUM_PAGES_PER_TERM
    ));
  }

  /**
   * Loads full data of the given posts, including author and featured image.
   *
   * @param list<int> $remoteIds
   * @return list<array<mixed>>
   */
  private function loadPostDetails(Source $source, array $remoteIds): array {
    $posts = $this->remoteClient->getJson($source, '/wp/v2/posts', [
      'include' => implode(',', $remoteIds),
      'per_page' => self::DETAIL_BATCH_SIZE,
      'orderby' => 'include',
      '_embed' => 'author,wp:featuredmedia',
      '_fields' => 'id,date_gmt,modified_gmt,slug,link,title,content,excerpt,_links,_embedded',
    ]);
    return array_values(array_filter($posts, 'is_array'));
  }

  /**
   * Converts REST data into capsule data; returns null if required fields are missing or invalid.
   *
   * @param array<mixed> $postData
   */
  private function buildCapsuleData(Source $source, array $postData): ?CapsuleData {
    if (!isset($postData['id'], $postData['link'], $postData['date_gmt']) || !is_int($postData['id'])) {
      return null;
    }
    $remoteId = $postData['id'];

    $originalUrl = $this->urlResolver->resolveLinkUrl((string)$postData['link'], $source->baseUrl . '/');
    if ($originalUrl === null || str_starts_with($originalUrl, '#')) {
      return null;
    }
    $publishedTimestamp = strtotime((string)$postData['date_gmt'] . ' UTC');
    if ($publishedTimestamp === false) {
      return null;
    }

    $rawContent = $this->getRendered($postData, 'content');
    $context = new EmbeddingContext(
      $originalUrl,
      $this->urlResolver->normalizeHost($source->getHost()),
      $this->urlResolver->normalizeHost((string)wp_parse_url(home_url('/'), PHP_URL_HOST)),
      $source->linkMode,
      '#' . CapsuleStore::buildContentElementId($source->alias, $remoteId),
      'kra-c' . $remoteId . '-'
    );
    $fallbackContent = str_replace(['[', ']'], ['&#91;', '&#93;'], $this->htmlSanitizer->sanitize($rawContent, $context));

    $excerptText = $this->toPlainText($this->getRendered($postData, 'excerpt'));
    if ($excerptText === '') {
      $excerptText = $this->toPlainText($rawContent);
    }
    $excerpt = str_replace(['[', ']'], ['(', ')'], wp_trim_words($excerptText, self::EXCERPT_WORDS));

    $authorName = '';
    $author = $postData['_embedded']['author'][0] ?? null;
    if (is_array($author) && isset($author['name']) && is_string($author['name'])) {
      $authorName = $this->toPlainText($author['name']);
    }

    $slug = (string)($postData['slug'] ?? '');
    $title = $this->toPlainText($this->getRendered($postData, 'title'));
    if ($slug === '') {
      $slug = $title;
    }

    return new CapsuleData(
      $source->alias,
      $remoteId,
      $title,
      $slug,
      $originalUrl,
      gmdate('Y-m-d H:i:s', $publishedTimestamp),
      (string)($postData['modified_gmt'] ?? ''),
      $rawContent,
      $fallbackContent,
      $excerpt,
      $authorName,
      $source->importAuthorId,
      $this->buildImageData($postData, $originalUrl)
    );
  }

  /**
   * Extracts the featured image with its sizes; every URL is validated.
   *
   * @param array<mixed> $postData
   * @return array{url: string, width: int, height: int, alt: string, mimeType: string, sizes: array<string, array{url: string, width: int, height: int}>}|null
   */
  private function buildImageData(array $postData, string $originalUrl): ?array {
    $media = $postData['_embedded']['wp:featuredmedia'][0] ?? null;
    if (!is_array($media) || !isset($media['source_url']) || !is_string($media['source_url'])) {
      return null;
    }
    $url = $this->urlResolver->resolveResourceUrl($media['source_url'], $originalUrl);
    if ($url === null) {
      return null;
    }

    $mimeType = (string)($media['mime_type'] ?? 'image/jpeg');
    if (preg_match('#^image/(jpeg|png|gif|webp|avif)$#', $mimeType) !== 1) {
      return null;
    }

    $sizes = [];
    $remoteSizes = $media['media_details']['sizes'] ?? [];
    if (is_array($remoteSizes)) {
      foreach ($remoteSizes as $sizeName => $size) {
        if (!is_string($sizeName) || !is_array($size) || !isset($size['source_url']) || !is_string($size['source_url'])) {
          continue;
        }
        $sizeUrl = $this->urlResolver->resolveResourceUrl($size['source_url'], $originalUrl);
        if ($sizeUrl === null || preg_match('/^[a-z0-9_-]{1,50}$/i', $sizeName) !== 1) {
          continue;
        }
        $sizes[$sizeName] = [
          'url' => $sizeUrl,
          'width' => max(0, (int)($size['width'] ?? 0)),
          'height' => max(0, (int)($size['height'] ?? 0)),
        ];
      }
    }

    return [
      'url' => $url,
      'width' => max(0, (int)($media['media_details']['width'] ?? 0)),
      'height' => max(0, (int)($media['media_details']['height'] ?? 0)),
      'alt' => $this->toPlainText((string)($media['alt_text'] ?? '')),
      'mimeType' => $mimeType,
      'sizes' => $sizes,
    ];
  }

  /**
   * Returns the "rendered" member of a REST field.
   *
   * @param array<mixed> $postData
   */
  private function getRendered(array $postData, string $field): string {
    $rendered = $postData[$field]['rendered'] ?? '';
    if (!is_string($rendered)) {
      return '';
    }
    return $rendered;
  }

  /**
   * Converts HTML to plain text.
   */
  private function toPlainText(string $html): string {
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/u', ' ', $text));
  }

}
