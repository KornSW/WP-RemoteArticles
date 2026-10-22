<?php
/**
 * Plugin Name:       KornSW RemoteArticles
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-RemoteArticles/master/doc/kornsw-remotearticles.update.json
 * Plugin URI: https://github.com/KornSW/WP-RemoteArticles
 * Description:       Bettet die Beiträge einer Kategorie aus einer fremden WordPress-Installation per Shortcode ein – mit Galerien, Link-Regelwerk und anpassbarem Styling.
 * Version:           1.0.2
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            KornSW
 * License:           GPL-3.0
 * Text Domain:       kornsw-remotearticles
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}


/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWREMOTE86CD_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswremote86cd_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_RA_FILE', __FILE__);
define('KORNSW_RA_DIR', plugin_dir_path(__FILE__));
define('KORNSW_RA_URL', plugin_dir_url(__FILE__));
define('KORNSW_RA_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\RemoteArticles\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $relativeClassName = substr($className, strlen($namespacePrefix));
  $fileName = KORNSW_RA_DIR . 'src/' . str_replace('\\', '/', $relativeClassName) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

register_activation_hook(__FILE__, [\KornSW\RemoteArticles\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\KornSW\RemoteArticles\Plugin::class, 'deactivate']);
add_action('plugins_loaded', [\KornSW\RemoteArticles\Plugin::class, 'boot']);
