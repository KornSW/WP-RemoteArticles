<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Shortcode;

use KornSW\RemoteArticles\Embedding\LinkMode;

/**
 * Validated attributes of one [remote_articles] shortcode.
 *
 * Attributes never carry URLs, credentials or trust decisions (doc/security.md, section 1): only the alias of a configured
 * source, a category and display options with fixed value ranges. Unknown attributes are ignored.
 */
final class ShortcodeOptions {

  public const DEFAULT_COUNT = 10;
  public const MAXIMUM_COUNT = 50;

  /**
   * @param LinkMode|null $linkMode Overrides the link mode of the source (null = use the source's setting).
   */
  private function __construct(
    public readonly string $alias,
    public readonly string $category,
    public readonly int $count,
    public readonly string $order,
    public readonly bool $showExcerptOnly,
    public readonly bool $showImage,
    public readonly ?LinkMode $linkMode
  ) {
  }

  /**
   * Validates the raw shortcode attributes.
   *
   * @param array<string, mixed> $attributes Attributes as passed by WordPress (keys in lower case).
   * @throws \InvalidArgumentException With a German message if a required attribute is missing or invalid.
   */
  public static function fromAttributes(array $attributes): ShortcodeOptions {
    $alias = strtolower(trim((string)($attributes['alias'] ?? '')));
    if ($alias === '') {
      throw new \InvalidArgumentException('Das Attribut „alias“ fehlt, z. B. [remote_articles alias="blog" category="rezepte"].');
    }
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $alias) !== 1) {
      throw new \InvalidArgumentException('Das Attribut „alias“ ist ungültig.');
    }

    $category = trim((string)($attributes['category'] ?? ''));
    if ($category === '') {
      throw new \InvalidArgumentException('Das Attribut „category“ fehlt (Slug oder ID der Kategorie auf der Quelle).');
    }
    if (preg_match('/^[\p{L}\p{N}%_-]{1,200}$/u', $category) !== 1) {
      throw new \InvalidArgumentException('Das Attribut „category“ ist ungültig.');
    }

    $count = self::DEFAULT_COUNT;
    if (isset($attributes['count']) && preg_match('/^\d{1,3}$/', trim((string)$attributes['count'])) === 1) {
      $count = max(1, min(self::MAXIMUM_COUNT, (int)$attributes['count']));
    }

    $order = 'desc';
    if (strtolower(trim((string)($attributes['order'] ?? ''))) === 'asc') {
      $order = 'asc';
    }

    $showExcerptOnly = strtolower(trim((string)($attributes['display'] ?? ''))) === 'excerpt';
    $showImage = in_array(strtolower(trim((string)($attributes['image'] ?? ''))), ['1', 'yes', 'ja', 'true'], true);

    $linkMode = null;
    if (isset($attributes['links'])) {
      $linkMode = LinkMode::tryFrom(strtolower(trim((string)$attributes['links'])));
      if ($linkMode === null) {
        throw new \InvalidArgumentException('Das Attribut „links“ erlaubt nur „remove“, „new_window“ oder „keep“.');
      }
    }

    return new ShortcodeOptions($alias, $category, $count, $order, $showExcerptOnly, $showImage, $linkMode);
  }

}
