<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

use KornSW\RemoteArticles\Embedding\UrlResolver;

/**
 * Extracts the galleries of the plugin "NextGEN Gallery" from the original page of a post.
 *
 * NextGEN replaces its shortcodes by placeholders ("ngg_shortcode_0_placeholder") and inserts the galleries only at the
 * very end of the page output, which never happens for the REST API. The original page carries the finished galleries
 * as plain HTML (links to the images with thumbnails). Only data is taken over: image URLs on the host of the source,
 * titles and sizes.
 */
final class NextGenGalleryParser {

  /** Containers of the post content; galleries outside (e.g. in widgets) do not belong to the placeholders. */
  private const CONTENT_SELECTORS = ['.entry-content', '.post-content', '.wp-block-post-content', 'article', 'main'];

  private const MAXIMUM_ITEMS_PER_GALLERY = 500;

  public function __construct(
    private readonly UrlResolver $urlResolver
  ) {
  }

  /**
   * Returns the galleries of the post content in document order (index = number of the placeholder).
   *
   * @param string $sourceHost Lower-case host of the source; all image URLs must be on it.
   * @return list<list<GalleryItem>>
   */
  public function parse(string $pageHtml, string $pageUrl, string $sourceHost): array {
    $document = \Dom\HTMLDocument::createFromString($pageHtml, LIBXML_NOERROR, 'UTF-8');

    $scope = null;
    foreach (self::CONTENT_SELECTORS as $selector) {
      $scope = $document->querySelector($selector);
      if ($scope !== null) {
        break;
      }
    }
    if ($scope === null) {
      $scope = $document->body;
    }
    if ($scope === null) {
      return [];
    }

    $galleries = [];
    foreach ($scope->querySelectorAll('[data-nextgen-gallery-id]') as $galleryElement) {
      // Nested markers belong to the outer gallery.
      $parent = $galleryElement->parentElement;
      if ($parent !== null && $parent->closest('[data-nextgen-gallery-id]') !== null) {
        continue;
      }
      $galleries[] = $this->parseGallery($galleryElement, $pageUrl, $sourceHost);
    }
    return $galleries;
  }

  /**
   * @return list<GalleryItem>
   */
  private function parseGallery(\Dom\Element $galleryElement, string $pageUrl, string $sourceHost): array {
    $items = [];
    foreach ($galleryElement->querySelectorAll('a') as $link) {
      $image = $link->querySelector('img');
      if ($image === null) {
        continue;
      }

      $imageUrl = $this->resolveSourceUrl((string)$link->getAttribute('href'), $pageUrl, $sourceHost);
      $thumbnailUrl = $this->resolveSourceUrl((string)$image->getAttribute('src'), $pageUrl, $sourceHost);
      if ($imageUrl === null || $thumbnailUrl === null) {
        continue;
      }

      $title = trim((string)$link->getAttribute('data-title'));
      if ($title === '') {
        $title = trim((string)$image->getAttribute('alt'));
      }
      $items[] = new GalleryItem(
        'image',
        $title,
        $imageUrl,
        $thumbnailUrl,
        '',
        max(1, (int)$image->getAttribute('width')),
        max(1, (int)$image->getAttribute('height'))
      );
      if (count($items) >= self::MAXIMUM_ITEMS_PER_GALLERY) {
        break;
      }
    }
    return $items;
  }

  /**
   * Resolves a URL of the page; only https URLs on the host of the source are accepted.
   */
  private function resolveSourceUrl(string $url, string $pageUrl, string $sourceHost): ?string {
    $resolvedUrl = $this->urlResolver->resolveResourceUrl($url, $pageUrl);
    if ($resolvedUrl === null) {
      return null;
    }
    if (strtolower((string)parse_url($resolvedUrl, PHP_URL_HOST)) !== $sourceHost) {
      return null;
    }
    return $resolvedUrl;
  }

}
