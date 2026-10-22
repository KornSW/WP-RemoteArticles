<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Rendering;

use KornSW\RemoteArticles\Embedding\EmbeddingContext;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Remote\RemotePost;
use KornSW\RemoteArticles\Shortcode\ShortcodeOptions;
use KornSW\RemoteArticles\Sources\Source;

/**
 * Renders a list of remote posts. Everything taken from the source is either escaped (title, date, image) or passed through
 * the HtmlSanitizer (content, excerpt); nothing foreign reaches the page unprocessed.
 */
final class ArticleListRenderer {

  public function __construct(
    private readonly HtmlSanitizer $htmlSanitizer,
    private readonly UrlResolver $urlResolver
  ) {
  }

  /**
   * Renders the posts of one shortcode instance.
   *
   * @param list<RemotePost> $posts
   * @param int $instanceNumber Running number of the shortcode on the current page (keeps IDs unique).
   */
  public function render(Source $source, array $posts, ShortcodeOptions $options, LinkMode $linkMode, int $instanceNumber): string {
    if (count($posts) === 0) {
      return '<div class="kornsw-ra kornsw-ra-empty"><p>Derzeit sind hier keine Beiträge vorhanden.</p></div>';
    }

    $html = '<div class="kornsw-ra" data-kornsw-ra-source="' . esc_attr($source->alias) . '">';
    foreach ($posts as $post) {
      $html .= $this->renderPost($source, $post, $options, $linkMode, $instanceNumber);
    }
    $html .= '</div>';
    return $html;
  }

  /**
   * Renders one post as an article element; the article is the scope for the post's style sheets.
   */
  private function renderPost(Source $source, RemotePost $post, ShortcodeOptions $options, LinkMode $linkMode, int $instanceNumber): string {
    $articleId = 'kornsw-ra-' . $instanceNumber . '-' . $post->id;
    $documentUrl = $this->urlResolver->resolveLinkUrl($post->link, $source->baseUrl . '/');
    if ($documentUrl === null || str_starts_with($documentUrl, '#')) {
      $documentUrl = $source->baseUrl . '/';
    }

    $context = new EmbeddingContext(
      $documentUrl,
      $this->urlResolver->normalizeHost($source->getHost()),
      $this->urlResolver->normalizeHost((string)wp_parse_url(home_url('/'), PHP_URL_HOST)),
      $linkMode,
      '#' . $articleId,
      'kra-' . $instanceNumber . '-' . $post->id . '-'
    );

    $html = '<article class="kornsw-ra-article" id="' . esc_attr($articleId) . '">';
    if ($options->showImage) {
      $html .= $this->renderImage($post, $context);
    }
    $html .= '<h2 class="kornsw-ra-title">' . $this->renderTitle($post, $documentUrl, $linkMode) . '</h2>';
    if ($post->publishedTimestamp !== null) {
      $html .= '<p class="kornsw-ra-meta"><time datetime="' . esc_attr(gmdate('c', $post->publishedTimestamp)) . '">'
        . esc_html(wp_date((string)get_option('date_format'), $post->publishedTimestamp)) . '</time></p>';
    }

    $foreignHtml = $post->contentHtml;
    if ($options->showExcerptOnly) {
      $foreignHtml = $post->excerptHtml;
    }
    $html .= '<div class="kornsw-ra-content">' . $this->htmlSanitizer->sanitize($foreignHtml, $context) . '</div>';
    $html .= '</article>';
    return $html;
  }

  /**
   * Renders the title, linked to the original according to the link mode.
   */
  private function renderTitle(RemotePost $post, string $documentUrl, LinkMode $linkMode): string {
    $title = esc_html($post->title);
    if ($linkMode === LinkMode::Remove) {
      return $title;
    }
    if ($linkMode === LinkMode::NewWindow) {
      return '<a href="' . esc_url($documentUrl) . '" target="_blank" rel="noopener noreferrer">' . $title . '</a>';
    }
    return '<a href="' . esc_url($documentUrl) . '">' . $title . '</a>';
  }

  /**
   * Renders the featured image if its URL is acceptable.
   */
  private function renderImage(RemotePost $post, EmbeddingContext $context): string {
    if ($post->imageUrl === '') {
      return '';
    }
    $imageUrl = $this->urlResolver->resolveResourceUrl($post->imageUrl, $context->documentUrl);
    if ($imageUrl === null) {
      return '';
    }
    return '<figure class="kornsw-ra-image"><img src="' . esc_url($imageUrl) . '" alt="' . esc_attr($post->imageAlt) . '" loading="lazy" decoding="async"></figure>';
  }

}
