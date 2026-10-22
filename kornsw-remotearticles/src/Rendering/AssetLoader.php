<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Rendering;

use KornSW\RemoteArticles\Settings;
use KornSW\RemoteArticles\Sources\Source;

/**
 * Loads style sheets and the lightbox only on pages that actually contain an embedding.
 */
final class AssetLoader {

  public const STYLE_HANDLE = 'kornsw-remotearticles';
  public const LIGHTBOX_HANDLE = 'kornsw-remotearticles-lightbox';

  /** @var array<string, bool> Aliases whose custom CSS was already added. */
  private array $aliasesWithCustomCss = [];

  public function __construct(
    private readonly Settings $settings
  ) {
  }

  /**
   * Enqueues the assets for an embedding of the given source (assets enqueued during rendering are printed in the footer).
   */
  public function enqueueFor(Source $source): void {
    $this->registerAssets();
    wp_enqueue_style(self::STYLE_HANDLE);
    wp_enqueue_style(self::LIGHTBOX_HANDLE);
    wp_enqueue_script(self::LIGHTBOX_HANDLE);

    if ($source->customCss !== '' && !isset($this->aliasesWithCustomCss[$source->alias])) {
      // Custom CSS is entered by administrators; "<" is removed so that it cannot close the style element.
      wp_add_inline_style(self::STYLE_HANDLE, str_replace('<', '', $source->customCss));
      $this->aliasesWithCustomCss[$source->alias] = true;
    }
  }

  /**
   * Registers the assets once. Without the default style sheet, the style handle carries only custom CSS.
   */
  private function registerAssets(): void {
    if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
      return;
    }

    $styleSheetUrl = false;
    if ($this->settings->isDefaultStyleSheetEnabled()) {
      $styleSheetUrl = KORNSW_RA_URL . 'assets/remote-articles.css';
    }
    wp_register_style(self::STYLE_HANDLE, $styleSheetUrl, [], KORNSW_RA_VERSION);
    wp_register_style(self::LIGHTBOX_HANDLE, KORNSW_RA_URL . 'assets/lightbox.css', [], KORNSW_RA_VERSION);
    wp_register_script(
      self::LIGHTBOX_HANDLE,
      KORNSW_RA_URL . 'assets/lightbox.js',
      [],
      KORNSW_RA_VERSION,
      ['in_footer' => true, 'strategy' => 'defer']
    );
  }

}
