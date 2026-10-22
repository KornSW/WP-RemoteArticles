<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * Everything the embedding pipeline needs to know about one embedded post.
 */
final class EmbeddingContext {

  /**
   * @param string $documentUrl Absolute URL of the original post; relative URLs in its content are resolved against it.
   * @param string $sourceHost Host name of the source installation (lower case).
   * @param string $ownHost Host name of the own installation (lower case).
   * @param LinkMode $linkMode Treatment of links that point to pages of the source.
   * @param string $scopeSelector CSS selector of the wrapper element; foreign style sheets are limited to it.
   * @param string $idPrefix Prefix for all element IDs of the foreign content (avoids collisions and DOM clobbering).
   */
  public function __construct(
    public readonly string $documentUrl,
    public readonly string $sourceHost,
    public readonly string $ownHost,
    public readonly LinkMode $linkMode,
    public readonly string $scopeSelector,
    public readonly string $idPrefix
  ) {
  }

}
