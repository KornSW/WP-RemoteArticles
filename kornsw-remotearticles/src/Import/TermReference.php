<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * A category or tag named in an import rule ("kochen" = category, "#kochen" = tag).
 */
final class TermReference {

  public const TAXONOMY_CATEGORY = 'category';
  public const TAXONOMY_TAG = 'post_tag';

  /**
   * @param string $taxonomy TAXONOMY_CATEGORY or TAXONOMY_TAG.
   * @param string $value Slug, ID (remote side) or name (local side) as written in the rule.
   */
  public function __construct(
    public readonly string $taxonomy,
    public readonly string $value
  ) {
  }

  /**
   * Tells whether this reference names a tag.
   */
  public function isTag(): bool {
    return $this->taxonomy === self::TAXONOMY_TAG;
  }

  /**
   * Returns the reference in rule notation, e.g. "#kochen".
   */
  public function toNotation(): string {
    if ($this->isTag()) {
      return '#' . $this->value;
    }
    return $this->value;
  }

}
