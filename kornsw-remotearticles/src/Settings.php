<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles;

/**
 * General plugin settings (one option, not autoloaded).
 */
final class Settings {

  public const OPTION_NAME = 'kornsw_ra_settings';

  /**
   * Tells whether the plugin's default style sheet is loaded.
   */
  public function isDefaultStyleSheetEnabled(): bool {
    $settings = $this->load();
    return (bool)$settings['useDefaultStyleSheet'];
  }

  /**
   * Stores whether the default style sheet is loaded.
   */
  public function setDefaultStyleSheetEnabled(bool $isEnabled): void {
    $settings = $this->load();
    $settings['useDefaultStyleSheet'] = $isEnabled;
    update_option(self::OPTION_NAME, $settings, false);
  }

  /**
   * Returns the post template (file or slug as stored in "_wp_page_template") used for imported posts; '' = theme default.
   */
  public function getCapsuleTemplate(): string {
    $settings = $this->load();
    return (string)$settings['capsuleTemplate'];
  }

  /**
   * Stores the post template for imported posts ('' = theme default).
   */
  public function setCapsuleTemplate(string $template): void {
    $settings = $this->load();
    $settings['capsuleTemplate'] = $template;
    update_option(self::OPTION_NAME, $settings, false);
  }

  /**
   * Returns the default values of all settings.
   *
   * @return array<string, mixed>
   */
  public static function getDefaults(): array {
    return [
      'useDefaultStyleSheet' => true,
      'capsuleTemplate' => '',
    ];
  }

  /**
   * Loads the settings, completed with defaults.
   *
   * @return array<string, mixed>
   */
  private function load(): array {
    $storedSettings = get_option(self::OPTION_NAME, []);
    if (!is_array($storedSettings)) {
      $storedSettings = [];
    }
    return array_merge(self::getDefaults(), $storedSettings);
  }

}
