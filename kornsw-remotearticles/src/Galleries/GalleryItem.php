<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

/**
 * One image or video of an embedded gallery. All URLs are validated (https, allowed hosts).
 */
final class GalleryItem {

  /**
   * @param string $type "image" or "video".
   * @param string $mediaUrl Large image, or the video stream (for videos possibly still a URL of the source that has to be resolved).
   * @param string $thumbnailUrl Preview in the gallery.
   * @param string $thumbnailRetinaUrl Preview in double resolution ('' = none).
   */
  public function __construct(
    public readonly string $type,
    public readonly string $title,
    public readonly string $mediaUrl,
    public readonly string $thumbnailUrl,
    public readonly string $thumbnailRetinaUrl,
    public readonly int $width,
    public readonly int $height
  ) {
  }

  /**
   * Returns a copy with another media URL (used after resolving video streams).
   */
  public function withMediaUrl(string $mediaUrl): GalleryItem {
    return new GalleryItem($this->type, $this->title, $mediaUrl, $this->thumbnailUrl, $this->thumbnailRetinaUrl, $this->width, $this->height);
  }

  /**
   * Returns the item for the JSON response of the gallery endpoint.
   *
   * @return array<string, string|int>
   */
  public function toArray(): array {
    return [
      'type' => $this->type,
      'title' => $this->title,
      'url' => $this->mediaUrl,
      'thumbnail' => $this->thumbnailUrl,
      'thumbnailRetina' => $this->thumbnailRetinaUrl,
      'width' => $this->width,
      'height' => $this->height,
    ];
  }

}
