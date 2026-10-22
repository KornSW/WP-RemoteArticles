<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

use KornSW\RemoteArticles\Embedding\UrlResolver;

/**
 * Extracts the images and videos from the response of "shareonedrive-get-gallery" (JSON with an "html" member).
 *
 * Only data is taken over, never markup: per entry the type, title, URLs and dimensions. Image URLs must point to the
 * Microsoft media hosts; video streams must be the "shareonedrive-stream" action on admin-ajax.php of the source.
 */
final class ShareOneDriveResponseParser {

  private const ALLOWED_MEDIA_HOST_SUFFIXES = [
    'svc.ms',
    'microsoftpersonalcontent.com',
    'sharepoint.com',
    '1drv.com',
    'onedrive.com',
    'live.com',
  ];

  private const MAXIMUM_ITEMS = 500;

  public function __construct(
    private readonly UrlResolver $urlResolver
  ) {
  }

  /**
   * Parses the response body.
   *
   * @param string $sourceHost Lower-case host of the source (video streams must be served by it).
   * @return list<GalleryItem>
   * @throws \InvalidArgumentException If the body is not a gallery response (e.g. "-1" for an invalid nonce).
   */
  public function parse(string $responseBody, string $sourceHost): array {
    try {
      $data = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      throw new \InvalidArgumentException('Die Galerie-Antwort der Quelle ist kein JSON.');
    }
    if (!is_array($data) || !isset($data['html']) || !is_string($data['html'])) {
      throw new \InvalidArgumentException('Die Galerie-Antwort der Quelle enthält keine Galerie.');
    }

    $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>', LIBXML_NOERROR, 'UTF-8');
    $body = $document->body;
    if ($body === null) {
      return [];
    }
    $body->innerHTML = $data['html'];

    $items = [];
    foreach ($body->querySelectorAll('.image-container.entry a[data-type]') as $link) {
      $item = $this->parseEntry($link, $sourceHost);
      if ($item !== null) {
        $items[] = $item;
      }
      if (count($items) >= self::MAXIMUM_ITEMS) {
        break;
      }
    }
    return $items;
  }

  /**
   * Tells whether a URL is https on one of the Microsoft media hosts.
   */
  public function isAllowedMediaUrl(string $url): bool {
    if ($this->urlResolver->resolveResourceUrl($url, 'https://invalid.invalid/') !== $url) {
      return false;
    }
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    foreach (self::ALLOWED_MEDIA_HOST_SUFFIXES as $suffix) {
      if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Converts one entry; returns null if it is incomplete or a URL is not allowed.
   */
  private function parseEntry(\Dom\Element $link, string $sourceHost): ?GalleryItem {
    $type = (string)$link->getAttribute('data-type');
    if ($type !== 'image' && $type !== 'video') {
      return null;
    }

    $image = $link->querySelector('img');
    if ($image === null) {
      return null;
    }
    $thumbnailUrl = (string)$image->getAttribute('data-src');
    $thumbnailRetinaUrl = (string)$image->getAttribute('data-src-retina');
    if (!$this->isAllowedMediaUrl($thumbnailUrl)) {
      return null;
    }
    if ($thumbnailRetinaUrl !== '' && !$this->isAllowedMediaUrl($thumbnailRetinaUrl)) {
      $thumbnailRetinaUrl = '';
    }

    $mediaUrl = (string)$link->getAttribute('href');
    if ($type === 'video') {
      $mediaUrl = $this->extractVideoStreamUrl((string)$link->getAttribute('data-options'), $sourceHost);
      if ($mediaUrl === null) {
        return null;
      }
    } elseif (!$this->isAllowedMediaUrl($mediaUrl)) {
      return null;
    }

    $title = trim((string)$link->getAttribute('title'));
    $width = max(1, (int)$image->getAttribute('width'));
    $height = max(1, (int)$image->getAttribute('height'));
    return new GalleryItem($type, $title, $mediaUrl, $thumbnailUrl, $thumbnailRetinaUrl, $width, $height);
  }

  /**
   * Reads the stream URL from the lightbox options ("html5video:{h264:'…'}"); it must be the stream action of the source.
   */
  private function extractVideoStreamUrl(string $options, string $sourceHost): ?string {
    if (preg_match("/h264\s*:\s*'([^']+)'/", $options, $matches) !== 1) {
      return null;
    }
    $streamUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($this->urlResolver->resolveResourceUrl($streamUrl, 'https://invalid.invalid/') !== $streamUrl) {
      return null;
    }

    $parts = parse_url($streamUrl);
    if (!is_array($parts) || strtolower((string)($parts['host'] ?? '')) !== $sourceHost) {
      return null;
    }
    if (!str_ends_with((string)($parts['path'] ?? ''), '/wp-admin/admin-ajax.php')) {
      return null;
    }
    parse_str((string)($parts['query'] ?? ''), $query);
    if (($query['action'] ?? '') !== 'shareonedrive-stream') {
      return null;
    }
    return $streamUrl;
  }

}
