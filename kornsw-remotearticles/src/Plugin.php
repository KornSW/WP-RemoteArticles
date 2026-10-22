<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles;

use KornSW\RemoteArticles\Admin\SettingsPage;
use KornSW\RemoteArticles\Embedding\CssSanitizer;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\LinkClassifier;
use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Remote\ArticleRepository;
use KornSW\RemoteArticles\Remote\RemoteClient;
use KornSW\RemoteArticles\Remote\ResponseCache;
use KornSW\RemoteArticles\Rendering\ArticleListRenderer;
use KornSW\RemoteArticles\Rendering\AssetLoader;
use KornSW\RemoteArticles\Shortcode\RemoteArticlesShortcode;
use KornSW\RemoteArticles\Sources\SecretBox;
use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * Composition root: creates the services and registers all WordPress hooks.
 */
final class Plugin {

  private static ?Plugin $instance = null;

  private function __construct(
    private readonly RemoteArticlesShortcode $shortcode,
    private readonly SettingsPage $settingsPage
  ) {
  }

  /**
   * Activation hook: creates the options (not autoloaded). Existing data is never touched; uninstalling keeps it as well.
   */
  public static function activate(): void {
    add_option(SourceRepository::OPTION_NAME, [], '', false);
    add_option(Settings::OPTION_NAME, Settings::getDefaults(), '', false);
  }

  /**
   * Boots the plugin once (hooked to plugins_loaded).
   */
  public static function boot(): void {
    if (self::$instance !== null) {
      return;
    }
    self::$instance = self::create();
    self::$instance->registerHooks();
  }

  /**
   * Wires all services.
   */
  private static function create(): Plugin {
    $settings = new Settings();
    $sourceRepository = new SourceRepository();
    $secretBox = SecretBox::fromWordPressSalts();
    $remoteClient = new RemoteClient($secretBox);
    $articleRepository = new ArticleRepository($remoteClient, new ResponseCache());

    $urlResolver = new UrlResolver();
    $htmlSanitizer = new HtmlSanitizer($urlResolver, new CssSanitizer($urlResolver), new LinkClassifier($urlResolver));
    $articleListRenderer = new ArticleListRenderer($htmlSanitizer, $urlResolver);

    $shortcode = new RemoteArticlesShortcode($sourceRepository, $articleRepository, $articleListRenderer, new AssetLoader($settings));
    $settingsPage = new SettingsPage($sourceRepository, $settings, $secretBox, $remoteClient);
    return new Plugin($shortcode, $settingsPage);
  }

  /**
   * Registers the hooks of all features.
   */
  private function registerHooks(): void {
    add_action('init', [$this->shortcode, 'register']);
    if (is_admin()) {
      $this->settingsPage->register();
    }
  }

}
