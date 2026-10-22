<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

use KornSW\RemoteArticles\Embedding\EmbeddingContext;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Rendering\AssetLoader;
use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * Makes capsules behave like normal posts of the own site (doc/security.md, section 7):
 *
 * - content: replaced by the output of the embedding pipeline (raw HTML from meta, sanitized on every output);
 *   a placeholder is set before WordPress runs shortcodes and replaced after all other content filters,
 *   so foreign content never passes through do_shortcode;
 * - featured image: attachments without local files deliver the image sizes of the source;
 * - author: name of the original author as text, unless a local user was chosen;
 * - canonical URL: the original post;
 * - editing and deleting capsules and their images is impossible for every user.
 */
final class CapsulePresenter {

  private const PLACEHOLDER_PREFIX = 'KORNSWRACAPSULE';
  private const LOCKED_CAPABILITIES = ['edit_post' => true, 'delete_post' => true, 'publish_post' => true];

  private string $placeholderToken;

  public function __construct(
    private readonly CapsuleStore $capsuleStore,
    private readonly SourceRepository $sourceRepository,
    private readonly HtmlSanitizer $htmlSanitizer,
    private readonly UrlResolver $urlResolver,
    private readonly AssetLoader $assetLoader
  ) {
    $this->placeholderToken = bin2hex(random_bytes(8));
  }

  /**
   * Registers all filters.
   */
  public function register(): void {
    add_filter('the_content', [$this, 'insertPlaceholder'], 9);
    add_filter('the_content', [$this, 'replacePlaceholders'], PHP_INT_MAX);

    add_filter('image_downsize', [$this, 'filterImageDownsize'], 10, 3);
    add_filter('wp_get_attachment_url', [$this, 'filterAttachmentUrl'], 10, 2);
    add_filter('wp_get_attachment_image_attributes', [$this, 'filterImageAttributes'], 10, 2);

    add_filter('the_author', [$this, 'filterAuthorName']);
    add_filter('get_the_author_display_name', [$this, 'filterAuthorDisplayName'], 10, 2);
    add_filter('author_link', [$this, 'filterAuthorLink'], 10, 2);

    add_filter('get_canonical_url', [$this, 'filterCanonicalUrl'], 10, 2);
    add_filter('wpseo_canonical', [$this, 'filterSeoPluginCanonical']);
    add_filter('rank_math/frontend/canonical', [$this, 'filterSeoPluginCanonical']);

    add_filter('map_meta_cap', [$this, 'lockCapsules'], 10, 4);
    add_filter('display_post_states', [$this, 'addPostState'], 10, 2);
    add_filter('ajax_query_attachments_args', [$this, 'hideImageCapsulesInMediaModal']);
    add_action('pre_get_posts', [$this, 'hideImageCapsulesInMediaLibrary']);
  }

  /**
   * the_content, priority 9 (before wpautop and do_shortcode): replaces the content of a capsule by a placeholder.
   * Only content carrying the capsule marker is replaced, not other texts filtered while a capsule is the current post.
   */
  public function insertPlaceholder(mixed $content): mixed {
    $post = get_post();
    if (!is_string($content) || $post === null || !str_contains($content, CapsuleStore::CONTENT_MARKER)) {
      return $content;
    }
    if (!$this->capsuleStore->isCapsule($post->ID)) {
      return $content;
    }
    return self::PLACEHOLDER_PREFIX . $post->ID . 'X' . $this->placeholderToken;
  }

  /**
   * the_content, last priority: replaces placeholders by the sanitized content of the capsule.
   */
  public function replacePlaceholders(mixed $content): mixed {
    if (!is_string($content) || !str_contains($content, self::PLACEHOLDER_PREFIX)) {
      return $content;
    }
    $pattern = '#(?:<p>\s*)?' . self::PLACEHOLDER_PREFIX . '(\d+)X' . $this->placeholderToken . '(?:\s*</p>)?#';
    $result = preg_replace_callback(
      $pattern,
      function (array $matches): string {
        return $this->renderCapsuleContent((int)$matches[1]);
      },
      $content
    );
    if (!is_string($result)) {
      return $content;
    }
    return $result;
  }

  /**
   * Renders the content of a capsule through the embedding pipeline.
   */
  private function renderCapsuleContent(int $postId): string {
    $origin = (string)get_post_meta($postId, CapsuleStore::META_ORIGIN, true);
    $alias = (string)get_post_meta($postId, CapsuleStore::META_ALIAS, true);
    $originalUrl = (string)get_post_meta($postId, CapsuleStore::META_ORIGINAL_URL, true);
    $rawContent = (string)get_post_meta($postId, CapsuleStore::META_RAW_CONTENT, true);
    $remoteId = (int)substr($origin, (int)strrpos($origin, ':') + 1);

    $source = $this->sourceRepository->find($alias);
    $linkMode = LinkMode::NewWindow;
    $sourceHost = $this->urlResolver->getComparableHost($originalUrl);
    if ($source !== null) {
      $linkMode = $source->linkMode;
      $sourceHost = $this->urlResolver->normalizeHost($source->getHost());
    }

    $elementId = CapsuleStore::buildContentElementId($alias, $remoteId);
    $context = new EmbeddingContext(
      $originalUrl,
      $sourceHost,
      $this->urlResolver->normalizeHost((string)wp_parse_url(home_url('/'), PHP_URL_HOST)),
      $linkMode,
      '#' . $elementId,
      'kra-c' . $remoteId . '-'
    );

    $this->assetLoader->enqueueFor($source);
    return '<div class="kornsw-ra-content" id="' . esc_attr($elementId) . '">' . $this->htmlSanitizer->sanitize($rawContent, $context) . '</div>';
  }

  /**
   * image_downsize: delivers the matching image size of the source for image capsules.
   *
   * @param array<mixed>|false $downsize
   * @param string|array<int> $size
   * @return array<mixed>|false
   */
  public function filterImageDownsize(mixed $downsize, int $attachmentId, mixed $size): mixed {
    $image = $this->getImageData($attachmentId);
    if ($image === null) {
      return $downsize;
    }
    $selectedSize = $this->selectImageSize($image, $size);
    return [$selectedSize['url'], $selectedSize['width'], $selectedSize['height'], $selectedSize['url'] !== $image['url']];
  }

  /**
   * wp_get_attachment_url: the original image of the source.
   */
  public function filterAttachmentUrl(mixed $url, int $attachmentId): mixed {
    $image = $this->getImageData($attachmentId);
    if ($image === null) {
      return $url;
    }
    return $image['url'];
  }

  /**
   * wp_get_attachment_image_attributes: srcset from the image sizes of the source.
   *
   * @param array<string, string> $attributes
   * @return array<string, string>
   */
  public function filterImageAttributes(array $attributes, \WP_Post $attachment): array {
    $image = $this->getImageData($attachment->ID);
    if ($image === null) {
      return $attributes;
    }

    $candidates = [];
    foreach (array_merge($image['sizes'], ['full' => ['url' => $image['url'], 'width' => $image['width'], 'height' => $image['height']]]) as $size) {
      if ($size['width'] > 0) {
        $candidates[$size['width']] = esc_url($size['url']) . ' ' . $size['width'] . 'w';
      }
    }
    ksort($candidates);
    if (count($candidates) > 1) {
      $attributes['srcset'] = implode(', ', $candidates);
      if (!isset($attributes['sizes'])) {
        $attributes['sizes'] = '(max-width: ' . max(array_keys($candidates)) . 'px) 100vw, ' . max(array_keys($candidates)) . 'px';
      }
    }
    return $attributes;
  }

  /**
   * the_author: name of the original author for capsules without a chosen local author.
   */
  public function filterAuthorName(mixed $authorName): mixed {
    $originalAuthorName = $this->getOriginalAuthorName(get_post());
    if ($originalAuthorName === null) {
      return $authorName;
    }
    return $originalAuthorName;
  }

  /**
   * get_the_author_display_name (used e.g. by the post author block).
   */
  public function filterAuthorDisplayName(mixed $displayName, mixed $userId): mixed {
    if ((int)$userId !== 0) {
      return $displayName;
    }
    $originalAuthorName = $this->getOriginalAuthorName(get_post());
    if ($originalAuthorName === null) {
      return $displayName;
    }
    return $originalAuthorName;
  }

  /**
   * author_link: there is no local author archive for original authors; the link stays on the post itself.
   */
  public function filterAuthorLink(mixed $link, mixed $authorId): mixed {
    $post = get_post();
    if ((int)$authorId !== 0 || $this->getOriginalAuthorName($post) === null) {
      return $link;
    }
    return get_permalink($post);
  }

  /**
   * get_canonical_url: the original post is the canonical version of a capsule.
   */
  public function filterCanonicalUrl(mixed $canonicalUrl, \WP_Post $post): mixed {
    if (!$this->capsuleStore->isCapsule($post->ID)) {
      return $canonicalUrl;
    }
    $originalUrl = (string)get_post_meta($post->ID, CapsuleStore::META_ORIGINAL_URL, true);
    if ($originalUrl === '') {
      return $canonicalUrl;
    }
    return $originalUrl;
  }

  /**
   * Canonical filters of common SEO plugins (Yoast SEO, Rank Math), which replace the core canonical tag.
   */
  public function filterSeoPluginCanonical(mixed $canonicalUrl): mixed {
    if (!is_singular('post')) {
      return $canonicalUrl;
    }
    $post = get_queried_object();
    if (!($post instanceof \WP_Post)) {
      return $canonicalUrl;
    }
    return $this->filterCanonicalUrl($canonicalUrl, $post);
  }

  /**
   * map_meta_cap: nobody may edit, publish or delete capsules or their images; only the synchronization changes them.
   *
   * @param list<string> $capabilities
   * @param array<mixed> $arguments
   * @return list<string>
   */
  public function lockCapsules(array $capabilities, string $capability, int $userId, array $arguments): array {
    if (!isset(self::LOCKED_CAPABILITIES[$capability]) || !isset($arguments[0])) {
      return $capabilities;
    }
    $postId = (int)$arguments[0];
    if ($this->capsuleStore->isCapsule($postId) || $this->capsuleStore->isImageCapsule($postId)) {
      return ['do_not_allow'];
    }
    return $capabilities;
  }

  /**
   * display_post_states: marks capsules in the post list.
   *
   * @param array<string, string> $states
   * @return array<string, string>
   */
  public function addPostState(array $states, \WP_Post $post): array {
    if ($this->capsuleStore->isCapsule($post->ID)) {
      $states['kornsw_ra'] = 'Importiert von „' . esc_html((string)get_post_meta($post->ID, CapsuleStore::META_ALIAS, true)) . '“';
    }
    return $states;
  }

  /**
   * Hides image capsules in the media modal of the editor.
   *
   * @param array<string, mixed> $query
   * @return array<string, mixed>
   */
  public function hideImageCapsulesInMediaModal(array $query): array {
    $query['meta_query'] = $this->appendImageCapsuleExclusion($query['meta_query'] ?? []);
    return $query;
  }

  /**
   * Hides image capsules in the list view of the media library.
   */
  public function hideImageCapsulesInMediaLibrary(\WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
      return;
    }
    $query->set('meta_query', $this->appendImageCapsuleExclusion($query->get('meta_query')));
  }

  /**
   * @return array<mixed>
   */
  private function appendImageCapsuleExclusion(mixed $metaQuery): array {
    $conditions = [];
    if (is_array($metaQuery)) {
      $conditions = $metaQuery;
    }
    $conditions[] = ['key' => CapsuleStore::META_IMAGE, 'compare' => 'NOT EXISTS'];
    return $conditions;
  }

  /**
   * Returns the escaped name of the original author if the post is a capsule without a chosen local author.
   */
  private function getOriginalAuthorName(?\WP_Post $post): ?string {
    if ($post === null || (int)$post->post_author !== 0 || !$this->capsuleStore->isCapsule($post->ID)) {
      return null;
    }
    $authorName = (string)get_post_meta($post->ID, CapsuleStore::META_AUTHOR_NAME, true);
    if ($authorName === '') {
      $authorName = (string)get_post_meta($post->ID, CapsuleStore::META_ALIAS, true);
    }
    return esc_html($authorName);
  }

  /**
   * Returns the stored image data of an image capsule, or null for normal attachments.
   *
   * @return array{url: string, width: int, height: int, sizes: array<string, array{url: string, width: int, height: int}>}|null
   */
  private function getImageData(int $attachmentId): ?array {
    $image = get_post_meta($attachmentId, CapsuleStore::META_IMAGE, true);
    if (!is_array($image) || !isset($image['url'], $image['sizes']) || !is_array($image['sizes'])) {
      return null;
    }
    return [
      'url' => (string)$image['url'],
      'width' => (int)($image['width'] ?? 0),
      'height' => (int)($image['height'] ?? 0),
      'sizes' => $image['sizes'],
    ];
  }

  /**
   * Picks the image size of the source that matches the requested size best.
   *
   * @param array{url: string, width: int, height: int, sizes: array<string, array{url: string, width: int, height: int}>} $image
   * @return array{url: string, width: int, height: int}
   */
  private function selectImageSize(array $image, mixed $size): array {
    $original = ['url' => $image['url'], 'width' => $image['width'], 'height' => $image['height']];

    if (is_string($size)) {
      if ($size === 'full') {
        return $original;
      }
      if (isset($image['sizes'][$size])) {
        return $image['sizes'][$size];
      }
    }

    $requestedWidth = 0;
    if (is_array($size) && isset($size[0])) {
      $requestedWidth = (int)$size[0];
    } elseif (is_string($size)) {
      $registeredSizes = wp_get_registered_image_subsizes();
      if (isset($registeredSizes[$size])) {
        $requestedWidth = (int)$registeredSizes[$size]['width'];
      }
    }
    if ($requestedWidth <= 0) {
      return $original;
    }

    $bestSize = $original;
    foreach ($image['sizes'] as $candidate) {
      $isLargeEnough = $candidate['width'] >= $requestedWidth;
      $isSmallerThanBest = $candidate['width'] < $bestSize['width'] || $bestSize['width'] === 0;
      if ($isLargeEnough && $isSmallerThanBest) {
        $bestSize = $candidate;
      }
    }
    return $bestSize;
  }

}
