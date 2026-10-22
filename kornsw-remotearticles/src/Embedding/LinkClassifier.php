<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * Decides what kind of target a (resolved) link has. Only links to pages of the source are subject to the link mode;
 * links to media of the source (images, videos, downloads) are never touched, otherwise galleries would break.
 */
final class LinkClassifier {

  public const TARGET_FRAGMENT = 'fragment';
  public const TARGET_SOURCE_PAGE = 'source_page';
  public const TARGET_SOURCE_MEDIA = 'source_media';
  public const TARGET_EXTERNAL = 'external';

  private const MEDIA_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico',
    'mp4', 'webm', 'ogv', 'mov', 'm4v', 'mp3', 'ogg', 'wav', 'm4a',
    'pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
  ];

  private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];

  private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];

  public function __construct(
    private readonly UrlResolver $urlResolver
  ) {
  }

  /**
   * Classifies a resolved link target (see UrlResolver::resolveLinkUrl()).
   *
   * @return string One of the TARGET_* constants.
   */
  public function classify(string $resolvedUrl, EmbeddingContext $context): string {
    if (str_starts_with($resolvedUrl, '#')) {
      return self::TARGET_FRAGMENT;
    }
    if (!str_starts_with(strtolower($resolvedUrl), 'https://')) {
      return self::TARGET_EXTERNAL;
    }
    if ($this->urlResolver->getComparableHost($resolvedUrl) !== $context->sourceHost) {
      return self::TARGET_EXTERNAL;
    }

    $path = strtolower((string)parse_url($resolvedUrl, PHP_URL_PATH));
    if (str_contains($path, '/wp-content/uploads/')) {
      return self::TARGET_SOURCE_MEDIA;
    }
    if (in_array($this->getExtension($path), self::MEDIA_EXTENSIONS, true)) {
      return self::TARGET_SOURCE_MEDIA;
    }
    return self::TARGET_SOURCE_PAGE;
  }

  /**
   * Returns "image", "video" or '' depending on the file type the URL points to.
   */
  public function getMediaType(string $resolvedUrl): string {
    $extension = $this->getExtension(strtolower((string)parse_url($resolvedUrl, PHP_URL_PATH)));
    if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
      return 'image';
    }
    if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
      return 'video';
    }
    return '';
  }

  /**
   * Returns the lower-case file extension of a URL path, or ''.
   */
  private function getExtension(string $path): string {
    $lastSegment = basename($path);
    $dotPosition = strrpos($lastSegment, '.');
    if ($dotPosition === false) {
      return '';
    }
    return substr($lastSegment, $dotPosition + 1);
  }

}
