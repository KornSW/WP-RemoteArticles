<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Remote;

/**
 * A post as delivered by the REST API of a source. All HTML in here is foreign and not yet sanitized.
 */
final class RemotePost {

  /**
   * @param string $title Plain text title (no HTML).
   * @param string $link Absolute URL of the original post.
   * @param int|null $publishedTimestamp Publication time (UTC), or null if unknown.
   * @param string $contentHtml Rendered content (foreign HTML).
   * @param string $excerptHtml Rendered excerpt (foreign HTML).
   * @param string $imageUrl URL of the featured image ('' = none, not yet validated).
   * @param string $imageAlt Alternative text of the featured image.
   */
  public function __construct(
    public readonly int $id,
    public readonly string $title,
    public readonly string $link,
    public readonly ?int $publishedTimestamp,
    public readonly string $contentHtml,
    public readonly string $excerptHtml,
    public readonly string $imageUrl,
    public readonly string $imageAlt
  ) {
  }

  /**
   * Creates a post from one entry of /wp/v2/posts; returns null if required fields are missing.
   *
   * @param array<mixed> $data
   */
  public static function fromApiData(array $data): ?RemotePost {
    if (!isset($data['id'], $data['link']) || !is_int($data['id']) || !is_string($data['link'])) {
      return null;
    }

    $publishedTimestamp = null;
    if (isset($data['date_gmt']) && is_string($data['date_gmt'])) {
      $parsedTimestamp = strtotime($data['date_gmt'] . ' UTC');
      if ($parsedTimestamp !== false) {
        $publishedTimestamp = $parsedTimestamp;
      }
    }

    $imageUrl = '';
    $imageAlt = '';
    $featuredMedia = $data['_embedded']['wp:featuredmedia'][0] ?? null;
    if (is_array($featuredMedia) && isset($featuredMedia['source_url']) && is_string($featuredMedia['source_url'])) {
      $imageUrl = $featuredMedia['source_url'];
      if (isset($featuredMedia['alt_text']) && is_string($featuredMedia['alt_text'])) {
        $imageAlt = $featuredMedia['alt_text'];
      }
    }

    return new RemotePost(
      $data['id'],
      self::toPlainText(self::getRendered($data, 'title')),
      $data['link'],
      $publishedTimestamp,
      self::getRendered($data, 'content'),
      self::getRendered($data, 'excerpt'),
      $imageUrl,
      $imageAlt
    );
  }

  /**
   * Returns the "rendered" member of a field such as title or content.
   *
   * @param array<mixed> $data
   */
  private static function getRendered(array $data, string $field): string {
    $rendered = $data[$field]['rendered'] ?? '';
    if (!is_string($rendered)) {
      return '';
    }
    return $rendered;
  }

  /**
   * Converts rendered HTML (e.g. a title with entities) to plain text.
   */
  private static function toPlainText(string $html): string {
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

}
