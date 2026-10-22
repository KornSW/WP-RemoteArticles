<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * Resolves and validates URLs found in foreign content.
 *
 * Only absolute https URLs leave this class (plus mailto:, tel: and fragments for links);
 * everything else is rejected. http is upgraded to https to avoid mixed content.
 */
final class UrlResolver {

  private const IMAGE_DATA_URL_PATTERN = '#^data:image/(png|gif|jpeg|webp|avif);base64,[a-z0-9+/=]+$#i';

  /**
   * Resolves a URL used to load a resource (image, video, iframe, CSS) to an absolute https URL.
   *
   * @param string $url The URL as found in the content, possibly relative.
   * @param string $baseUrl Absolute URL of the document the content belongs to.
   * @return string|null The absolute https URL, or null if the URL is not acceptable.
   */
  public function resolveResourceUrl(string $url, string $baseUrl): ?string {
    $cleanUrl = $this->cleanUrl($url);
    if ($cleanUrl === '' || str_starts_with($cleanUrl, '#')) {
      return null;
    }
    return $this->resolveToHttps($cleanUrl, $baseUrl);
  }

  /**
   * Resolves the target of a link. Besides https URLs, fragments, mailto: and tel: are allowed.
   *
   * @return string|null The resolved link target, or null if the link must be dropped.
   */
  public function resolveLinkUrl(string $url, string $baseUrl): ?string {
    $cleanUrl = $this->cleanUrl($url);
    if ($cleanUrl === '') {
      return null;
    }
    if (str_starts_with($cleanUrl, '#')) {
      return $cleanUrl;
    }
    $scheme = $this->getScheme($cleanUrl);
    if ($scheme === 'mailto' || $scheme === 'tel') {
      if (preg_match('#^[a-z]+:[^\s"<>\\\\`]+$#i', $cleanUrl) === 1) {
        return $cleanUrl;
      }
      return null;
    }
    return $this->resolveToHttps($cleanUrl, $baseUrl);
  }

  /**
   * Tells whether the URL is an inline image of a raster format (SVG is excluded on purpose).
   */
  public function isImageDataUrl(string $url): bool {
    return preg_match(self::IMAGE_DATA_URL_PATTERN, $this->cleanUrl($url)) === 1;
  }

  /**
   * Returns the lower-case host of an absolute URL without a leading "www.", or '' if there is none.
   */
  public function getComparableHost(string $absoluteUrl): string {
    $host = parse_url($absoluteUrl, PHP_URL_HOST);
    if (!is_string($host)) {
      return '';
    }
    return $this->normalizeHost($host);
  }

  /**
   * Lower-cases a host name and removes a leading "www.", so that "www.example.org" and "example.org" compare equal.
   */
  public function normalizeHost(string $host): string {
    $normalizedHost = strtolower(trim($host));
    if (str_starts_with($normalizedHost, 'www.')) {
      $normalizedHost = substr($normalizedHost, 4);
    }
    return $normalizedHost;
  }

  /**
   * Removes characters that browsers ignore inside URLs (used to hide schemes such as "java\nscript:").
   */
  private function cleanUrl(string $url): string {
    $withoutControlCharacters = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    if (!is_string($withoutControlCharacters)) {
      return '';
    }
    return trim($withoutControlCharacters);
  }

  /**
   * Returns the lower-case scheme of a URL, or '' for relative URLs.
   */
  private function getScheme(string $url): string {
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) === 1) {
      return strtolower($matches[1]);
    }
    return '';
  }

  /**
   * Resolves an absolute or relative URL against the base URL and validates the result.
   */
  private function resolveToHttps(string $url, string $baseUrl): ?string {
    $absoluteUrl = null;

    if (str_starts_with($url, '//')) {
      $absoluteUrl = 'https:' . $url;
    } else {
      $scheme = $this->getScheme($url);
      if ($scheme === 'https') {
        $absoluteUrl = $url;
      } elseif ($scheme === 'http') {
        $absoluteUrl = 'https:' . substr($url, 5);
      } elseif ($scheme !== '') {
        return null;
      } else {
        $absoluteUrl = $this->resolveRelative($url, $baseUrl);
      }
    }

    if ($absoluteUrl === null) {
      return null;
    }
    return $this->validateAbsoluteUrl($absoluteUrl);
  }

  /**
   * Resolves a relative URL (path, query or fragment) against an absolute base URL.
   */
  private function resolveRelative(string $relativeUrl, string $baseUrl): ?string {
    $baseParts = parse_url($baseUrl);
    if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
      return null;
    }

    $origin = 'https://' . $baseParts['host'];
    if (isset($baseParts['port'])) {
      $origin .= ':' . $baseParts['port'];
    }
    $basePath = '/';
    if (isset($baseParts['path']) && $baseParts['path'] !== '') {
      $basePath = $baseParts['path'];
    }

    if (str_starts_with($relativeUrl, '/')) {
      return $origin . $this->removeDotSegments($relativeUrl);
    }
    if (str_starts_with($relativeUrl, '?')) {
      return $origin . $basePath . $relativeUrl;
    }

    $lastSlashPosition = strrpos($basePath, '/');
    $baseDirectory = '/';
    if ($lastSlashPosition !== false) {
      $baseDirectory = substr($basePath, 0, $lastSlashPosition + 1);
    }
    return $origin . $this->removeDotSegments($baseDirectory . $relativeUrl);
  }

  /**
   * Removes "." and ".." segments from the path part (query and fragment stay untouched).
   */
  private function removeDotSegments(string $pathWithSuffix): string {
    $suffix = '';
    $suffixPosition = strcspn($pathWithSuffix, '?#');
    if ($suffixPosition < strlen($pathWithSuffix)) {
      $suffix = substr($pathWithSuffix, $suffixPosition);
    }
    $path = substr($pathWithSuffix, 0, $suffixPosition);

    $resultSegments = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if (count($resultSegments) > 1) {
          array_pop($resultSegments);
        }
        continue;
      }
      $resultSegments[] = $segment;
    }

    $normalizedPath = implode('/', $resultSegments);
    if (!str_starts_with($normalizedPath, '/')) {
      $normalizedPath = '/' . $normalizedPath;
    }
    if ((str_ends_with($path, '/.') || str_ends_with($path, '/..')) && !str_ends_with($normalizedPath, '/')) {
      $normalizedPath .= '/';
    }
    return $normalizedPath . $suffix;
  }

  /**
   * Accepts only well-formed absolute https URLs without credentials and without characters that could break out of attributes or CSS.
   */
  private function validateAbsoluteUrl(string $absoluteUrl): ?string {
    $encodedUrl = str_replace(' ', '%20', $absoluteUrl);
    if (preg_match('#["<>\\\\`\s]#', $encodedUrl) === 1) {
      return null;
    }
    $parts = parse_url($encodedUrl);
    if (!is_array($parts)) {
      return null;
    }
    if (!isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
      return null;
    }
    if (!isset($parts['host']) || $parts['host'] === '') {
      return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return null;
    }
    return $encodedUrl;
  }

}
