<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * Everything stored for one imported post. All texts are already plain text, all URLs already validated.
 */
final class CapsuleData {

  /**
   * @param string $publishedGmt Publication time (UTC) in MySQL format.
   * @param string $modifiedGmt Modification time on the source, used to detect changes.
   * @param string $rawContent Rendered HTML of the source, unmodified (only ever output through the pipeline).
   * @param string $fallbackContent Content sanitized at import time, with neutralized shortcode brackets.
   * @param string $authorName Plain text name of the original author.
   * @param int $localAuthorId Local user shown as author (0 = use $authorName).
   * @param array{url: string, width: int, height: int, alt: string, mimeType: string, sizes: array<string, array{url: string, width: int, height: int}>}|null $image
   */
  public function __construct(
    public readonly string $alias,
    public readonly int $remoteId,
    public readonly string $title,
    public readonly string $slug,
    public readonly string $originalUrl,
    public readonly string $publishedGmt,
    public readonly string $modifiedGmt,
    public readonly string $rawContent,
    public readonly string $fallbackContent,
    public readonly string $excerpt,
    public readonly string $authorName,
    public readonly int $localAuthorId,
    public readonly ?array $image
  ) {
  }

}
