<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Shortcode;

use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Remote\ArticleRepository;
use KornSW\RemoteArticles\Remote\RemoteException;
use KornSW\RemoteArticles\Rendering\ArticleListRenderer;
use KornSW\RemoteArticles\Rendering\AssetLoader;
use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * The shortcode [remote_articles alias="…" category="…"].
 *
 * Errors are shown only to users who can edit posts; visitors see nothing instead of technical details.
 */
final class RemoteArticlesShortcode {

  public const TAG = 'remote_articles';

  private int $instanceCounter = 0;

  public function __construct(
    private readonly SourceRepository $sourceRepository,
    private readonly ArticleRepository $articleRepository,
    private readonly ArticleListRenderer $articleListRenderer,
    private readonly AssetLoader $assetLoader
  ) {
  }

  /**
   * Registers the shortcode.
   */
  public function register(): void {
    add_shortcode(self::TAG, [$this, 'render']);
  }

  /**
   * Renders the shortcode (callback of add_shortcode()).
   *
   * @param mixed $attributes Attributes as passed by WordPress ('' if there are none).
   */
  public function render(mixed $attributes): string {
    $rawAttributes = [];
    if (is_array($attributes)) {
      $rawAttributes = $attributes;
    }

    try {
      $options = ShortcodeOptions::fromAttributes($rawAttributes);
    } catch (\InvalidArgumentException $exception) {
      return $this->renderError($exception->getMessage());
    }

    $source = $this->sourceRepository->find($options->alias);
    if ($source === null) {
      return $this->renderError(sprintf('Es gibt keine Quelle mit dem Alias „%s“ (Einstellungen → Remote Articles).', $options->alias));
    }

    $linkMode = $source->linkMode;
    if ($options->linkMode instanceof LinkMode) {
      $linkMode = $options->linkMode;
    }

    try {
      $categoryId = $this->articleRepository->resolveCategoryId($source, $options->category);
      $posts = $this->articleRepository->getPosts($source, $categoryId, $options->count, $options->order);
    } catch (RemoteException $exception) {
      return $this->renderError($exception->getMessage());
    }

    $this->instanceCounter++;
    $this->assetLoader->enqueueFor($source);
    return $this->articleListRenderer->render($source, $posts, $options, $linkMode, $this->instanceCounter);
  }

  /**
   * Shows an error to editors; visitors get an empty string.
   */
  private function renderError(string $message): string {
    if (!current_user_can('edit_posts')) {
      return '';
    }
    return '<div class="kornsw-ra-error" role="alert"><strong>Remote Articles:</strong> ' . esc_html($message) . '</div>';
  }

}
