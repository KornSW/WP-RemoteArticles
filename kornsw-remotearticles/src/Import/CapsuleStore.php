<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * Persists imported posts ("capsules") as real WordPress posts plus image attachments without local files
 * (doc/security.md, section 7).
 *
 * Identity of a capsule: meta "_kornsw_ra_origin" = "<alias>:<post ID on the source>". The raw foreign HTML is kept in a
 * protected meta field only; post_content holds a sanitized fallback with neutralized shortcode brackets.
 */
final class CapsuleStore {

  public const META_ORIGIN = '_kornsw_ra_origin';
  public const META_ALIAS = '_kornsw_ra_alias';
  public const META_ORIGINAL_URL = '_kornsw_ra_original_url';
  public const META_REMOTE_MODIFIED = '_kornsw_ra_remote_modified';
  public const META_RAW_CONTENT = '_kornsw_ra_raw_content';
  public const META_AUTHOR_NAME = '_kornsw_ra_author_name';
  public const META_IMAGE = '_kornsw_ra_image';

  /** Marks post_content of capsules, so that only the capsule's own content is replaced on output. */
  public const CONTENT_MARKER = '<!-- kornsw-ra-capsule -->';

  /** @var array<string, int> Local term IDs resolved during this request, keyed by "taxonomy|name". */
  private array $localTermIds = [];

  /**
   * Builds the identity key of a capsule.
   */
  public static function buildOriginKey(string $alias, int $remoteId): string {
    return $alias . ':' . $remoteId;
  }

  /**
   * Builds the HTML id of the element wrapping a capsule's content (also the scope of its style sheets).
   */
  public static function buildContentElementId(string $alias, int $remoteId): string {
    return 'kornsw-ra-capsule-' . $alias . '-' . $remoteId;
  }

  /**
   * Tells whether the post is an imported post.
   */
  public function isCapsule(int $postId): bool {
    if ($postId <= 0) {
      return false;
    }
    return get_post_meta($postId, self::META_ORIGIN, true) !== '';
  }

  /**
   * Tells whether the attachment is the image of an imported post (it has no local file).
   */
  public function isImageCapsule(int $attachmentId): bool {
    if ($attachmentId <= 0) {
      return false;
    }
    return is_array(get_post_meta($attachmentId, self::META_IMAGE, true));
  }

  /**
   * Returns the capsules of a source.
   *
   * @return array<int, array{postId: int, remoteModified: string}> Keyed by the post ID on the source.
   */
  public function findByAlias(string $alias): array {
    $postIds = get_posts([
      'post_type' => 'post',
      'post_status' => 'any',
      'meta_key' => self::META_ALIAS,
      'meta_value' => $alias,
      'fields' => 'ids',
      'nopaging' => true,
      'suppress_filters' => true,
    ]);

    $capsules = [];
    foreach ($postIds as $postId) {
      $origin = (string)get_post_meta((int)$postId, self::META_ORIGIN, true);
      $separatorPosition = strrpos($origin, ':');
      if ($separatorPosition === false) {
        continue;
      }
      $remoteId = (int)substr($origin, $separatorPosition + 1);
      $capsules[$remoteId] = [
        'postId' => (int)$postId,
        'remoteModified' => (string)get_post_meta((int)$postId, self::META_REMOTE_MODIFIED, true),
      ];
    }
    return $capsules;
  }

  /**
   * Creates or updates a capsule.
   *
   * @param int $existingPostId ID of the existing capsule, or 0 to create a new one.
   * @return int The post ID.
   * @throws \RuntimeException If WordPress refuses to store the post.
   */
  public function save(CapsuleData $data, int $existingPostId): int {
    // Titles and excerpts are HTML in WordPress and are output unescaped by most themes: the plain texts of the source
    // are therefore stored escaped, so that "&lt;script&gt;" in a remote title can never become markup.
    $postData = [
      'post_type' => 'post',
      'post_status' => 'publish',
      'post_title' => esc_html($data->title),
      'post_name' => sanitize_title($data->slug),
      'post_content' => self::CONTENT_MARKER . $data->fallbackContent,
      'post_excerpt' => esc_html($data->excerpt),
      'post_author' => $data->localAuthorId,
      'post_date_gmt' => $data->publishedGmt,
      'post_date' => get_date_from_gmt($data->publishedGmt),
      'comment_status' => 'closed',
      'ping_status' => 'closed',
      'meta_input' => [
        self::META_ORIGIN => self::buildOriginKey($data->alias, $data->remoteId),
        self::META_ALIAS => $data->alias,
        self::META_ORIGINAL_URL => $data->originalUrl,
        self::META_REMOTE_MODIFIED => $data->modifiedGmt,
        self::META_RAW_CONTENT => $data->rawContent,
        self::META_AUTHOR_NAME => $data->authorName,
      ],
    ];
    if ($existingPostId > 0) {
      $postData['ID'] = $existingPostId;
      $postData['edit_date'] = true;
    }

    // wp_insert_post() expects slashed data.
    $postId = wp_insert_post(wp_slash($postData), true);
    if (is_wp_error($postId)) {
      throw new \RuntimeException($postId->get_error_message());
    }

    // Publishing schedules pingbacks and enclosure checks for all links in the content; imported posts must not do that.
    delete_post_meta($postId, '_pingme');
    delete_post_meta($postId, '_encloseme');

    $this->saveImage($postId, $data);
    return $postId;
  }

  /**
   * Assigns the local terms of the import rules (created on demand). Other terms of the post are replaced.
   *
   * @param list<TermReference> $localTerms
   */
  public function assignTerms(int $postId, array $localTerms): void {
    $termIdsByTaxonomy = [
      TermReference::TAXONOMY_CATEGORY => [],
      TermReference::TAXONOMY_TAG => [],
    ];
    foreach ($localTerms as $localTerm) {
      $termId = $this->getOrCreateLocalTerm($localTerm);
      if ($termId > 0) {
        $termIdsByTaxonomy[$localTerm->taxonomy][] = $termId;
      }
    }

    foreach ($termIdsByTaxonomy as $taxonomy => $termIds) {
      wp_set_object_terms($postId, array_values(array_unique($termIds)), $taxonomy, false);
    }
  }

  /**
   * Deletes a capsule together with its image attachment.
   */
  public function delete(int $postId): void {
    $thumbnailId = (int)get_post_meta($postId, '_thumbnail_id', true);
    if ($this->isImageCapsule($thumbnailId)) {
      wp_delete_attachment($thumbnailId, true);
    }
    wp_delete_post($postId, true);
  }

  /**
   * Deletes all capsules of a source (used when the source is deleted).
   *
   * @return int Number of deleted capsules.
   */
  public function deleteAllForAlias(string $alias): int {
    $capsules = $this->findByAlias($alias);
    foreach ($capsules as $capsule) {
      $this->delete($capsule['postId']);
    }
    return count($capsules);
  }

  /**
   * Creates, updates or removes the image attachment (without local file) of a capsule.
   */
  private function saveImage(int $postId, CapsuleData $data): void {
    $currentThumbnailId = (int)get_post_meta($postId, '_thumbnail_id', true);
    $currentImageId = 0;
    if ($this->isImageCapsule($currentThumbnailId)) {
      $currentImageId = $currentThumbnailId;
    }

    if ($data->image === null) {
      if ($currentImageId > 0) {
        wp_delete_attachment($currentImageId, true);
      }
      delete_post_meta($postId, '_thumbnail_id');
      return;
    }

    $attachmentData = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'post_parent' => $postId,
      'post_title' => esc_html($data->title),
      'post_mime_type' => $data->image['mimeType'],
      'guid' => $data->image['url'],
    ];
    if ($currentImageId > 0) {
      $attachmentData['ID'] = $currentImageId;
    }
    $attachmentId = wp_insert_post(wp_slash($attachmentData), true);
    if (is_wp_error($attachmentId)) {
      return;
    }

    update_post_meta($attachmentId, self::META_IMAGE, wp_slash($data->image));
    update_post_meta($attachmentId, '_wp_attachment_image_alt', wp_slash($data->image['alt']));
    update_post_meta($attachmentId, '_wp_attachment_metadata', ['width' => $data->image['width'], 'height' => $data->image['height']]);
    update_post_meta($postId, '_thumbnail_id', $attachmentId);
  }

  /**
   * Returns the ID of the local category or tag with the given name, creating it if necessary.
   */
  private function getOrCreateLocalTerm(TermReference $localTerm): int {
    $cacheKey = $localTerm->taxonomy . '|' . $localTerm->value;
    if (isset($this->localTermIds[$cacheKey])) {
      return $this->localTermIds[$cacheKey];
    }

    $termId = 0;
    $existingTerm = get_term_by('name', $localTerm->value, $localTerm->taxonomy);
    if ($existingTerm === false) {
      $existingTerm = get_term_by('slug', sanitize_title($localTerm->value), $localTerm->taxonomy);
    }
    if ($existingTerm instanceof \WP_Term) {
      $termId = $existingTerm->term_id;
    } else {
      $insertedTerm = wp_insert_term(wp_slash($localTerm->value), $localTerm->taxonomy);
      if (!is_wp_error($insertedTerm)) {
        $termId = (int)$insertedTerm['term_id'];
      }
    }

    $this->localTermIds[$cacheKey] = $termId;
    return $termId;
  }

}
