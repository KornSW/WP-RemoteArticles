<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Remote;

use KornSW\RemoteArticles\Sources\Source;

/**
 * Reads categories and posts of a source via its REST API, with caching.
 */
final class ArticleRepository {

  public function __construct(
    private readonly RemoteClient $remoteClient,
    private readonly ResponseCache $responseCache
  ) {
  }

  /**
   * Resolves a category given as ID or slug to its ID.
   *
   * @throws RemoteException If the category does not exist or the source cannot be read.
   */
  public function resolveCategoryId(Source $source, string $category): int {
    if (preg_match('/^\d{1,10}$/', $category) === 1) {
      return (int)$category;
    }

    $query = ['slug' => $category, '_fields' => 'id'];
    $categories = $this->responseCache->remember(
      $this->buildCacheKey($source, '/wp/v2/categories', $query),
      $source->cacheTtlSeconds,
      function () use ($source, $query): array {
        return $this->remoteClient->getJson($source, '/wp/v2/categories', $query);
      }
    );

    $firstCategory = $categories[0] ?? null;
    if (!is_array($firstCategory) || !isset($firstCategory['id']) || !is_int($firstCategory['id'])) {
      throw new RemoteException(sprintf('Die Kategorie „%s“ gibt es auf der Quelle „%s“ nicht.', $category, $source->alias));
    }
    return $firstCategory['id'];
  }

  /**
   * Returns the newest (or oldest) published posts of a category.
   *
   * @param string $order "desc" (newest first) or "asc".
   * @return list<RemotePost>
   * @throws RemoteException If the source cannot be read and nothing is cached.
   */
  public function getPosts(Source $source, int $categoryId, int $count, string $order): array {
    $query = [
      'categories' => $categoryId,
      'per_page' => $count,
      'orderby' => 'date',
      'order' => $order,
      '_embed' => 'wp:featuredmedia',
      '_fields' => 'id,date_gmt,link,title,content,excerpt,_links,_embedded',
    ];
    $postData = $this->responseCache->remember(
      $this->buildCacheKey($source, '/wp/v2/posts', $query),
      $source->cacheTtlSeconds,
      function () use ($source, $query): array {
        return $this->remoteClient->getJson($source, '/wp/v2/posts', $query);
      }
    );

    $posts = [];
    foreach ($postData as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $post = RemotePost::fromApiData($entry);
      if ($post !== null) {
        $posts[] = $post;
      }
    }
    return $posts;
  }

  /**
   * Builds a cache key that changes whenever the source configuration relevant for the response changes.
   *
   * @param array<string, string|int> $query
   */
  private function buildCacheKey(Source $source, string $route, array $query): string {
    return implode('|', [$source->alias, $source->baseUrl, $source->userName, $route, http_build_query($query)]);
  }

}
