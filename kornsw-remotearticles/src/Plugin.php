<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles;

use KornSW\RemoteArticles\Admin\SettingsPage;
use KornSW\RemoteArticles\Embedding\CssSanitizer;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\LinkClassifier;
use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Galleries\GalleryPlaceholderInserter;
use KornSW\RemoteArticles\Galleries\NextGenGalleryParser;
use KornSW\RemoteArticles\Galleries\ShareOneDriveGalleries;
use KornSW\RemoteArticles\Galleries\ShareOneDriveResponseParser;
use KornSW\RemoteArticles\Import\CapsulePresenter;
use KornSW\RemoteArticles\Import\CapsuleStore;
use KornSW\RemoteArticles\Import\ImportRuleParser;
use KornSW\RemoteArticles\Import\SyncScheduler;
use KornSW\RemoteArticles\Import\Synchronizer;
use KornSW\RemoteArticles\Import\SyncStatusRepository;
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
    private readonly CapsulePresenter $capsulePresenter,
    private readonly SyncScheduler $syncScheduler,
    private readonly ShareOneDriveGalleries $shareOneDriveGalleries,
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
   * Deactivation hook: stops the background synchronization. Sources and imported posts are kept.
   */
  public static function deactivate(): void {
    SyncScheduler::unschedule();
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
    $assetLoader = new AssetLoader($settings);
    $shareOneDriveGalleries = new ShareOneDriveGalleries(
      $sourceRepository,
      $remoteClient,
      new ShareOneDriveResponseParser($urlResolver),
      new NextGenGalleryParser($urlResolver),
      new GalleryPlaceholderInserter(),
      $assetLoader
    );
    $articleListRenderer = new ArticleListRenderer($htmlSanitizer, $urlResolver, $shareOneDriveGalleries);

    $capsuleStore = new CapsuleStore();
    $importRuleParser = new ImportRuleParser();
    $syncStatusRepository = new SyncStatusRepository();
    $synchronizer = new Synchronizer($remoteClient, $capsuleStore, $importRuleParser, $htmlSanitizer, $urlResolver, $syncStatusRepository);

    return new Plugin(
      new RemoteArticlesShortcode($sourceRepository, $articleRepository, $articleListRenderer, $assetLoader),
      new CapsulePresenter($capsuleStore, $sourceRepository, $htmlSanitizer, $urlResolver, $assetLoader, $shareOneDriveGalleries, $settings),
      new SyncScheduler($sourceRepository, $synchronizer, $syncStatusRepository),
      $shareOneDriveGalleries,
      new SettingsPage($sourceRepository, $settings, $secretBox, $remoteClient, $importRuleParser, $synchronizer, $capsuleStore, $syncStatusRepository, $shareOneDriveGalleries)
    );
  }

  /**
   * Registers the hooks of all features.
   */
  private function registerHooks(): void {
    add_action('init', [$this->shortcode, 'register']);
    add_action('init', [$this->syncScheduler, 'register']);
    $this->capsulePresenter->register();
    $this->shareOneDriveGalleries->register();
    if (is_admin()) {
      $this->settingsPage->register();
    }
  }

}
