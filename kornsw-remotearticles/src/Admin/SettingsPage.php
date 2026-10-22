<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Admin;

use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Remote\RemoteClient;
use KornSW\RemoteArticles\Remote\RemoteException;
use KornSW\RemoteArticles\Settings;
use KornSW\RemoteArticles\Shortcode\RemoteArticlesShortcode;
use KornSW\RemoteArticles\Shortcode\ShortcodeOptions;
use KornSW\RemoteArticles\Sources\SecretBox;
use KornSW\RemoteArticles\Sources\Source;
use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * Admin page "Einstellungen → Remote Articles": manages the sources and the general settings.
 *
 * Every action requires manage_options and a valid nonce (doc/security.md, section 6). Stored passwords are never written
 * back into the form; an empty password field keeps the stored password.
 */
final class SettingsPage {

  private const PAGE_SLUG = 'kornsw-remotearticles';
  private const CAPABILITY = 'manage_options';

  private const ACTION_SAVE_SOURCE = 'kornsw_ra_save_source';
  private const ACTION_DELETE_SOURCE = 'kornsw_ra_delete_source';
  private const ACTION_TEST_SOURCE = 'kornsw_ra_test_source';
  private const ACTION_SAVE_SETTINGS = 'kornsw_ra_save_settings';

  private const NOTICE_TRANSIENT_PREFIX = 'kornsw_ra_notice_';
  private const MAXIMUM_CACHE_MINUTES = 1440;
  private const MAXIMUM_CUSTOM_CSS_LENGTH = 20000;

  public function __construct(
    private readonly SourceRepository $sourceRepository,
    private readonly Settings $settings,
    private readonly SecretBox $secretBox,
    private readonly RemoteClient $remoteClient
  ) {
  }

  /**
   * Registers the menu entry and the form handlers.
   */
  public function register(): void {
    add_action('admin_menu', [$this, 'addMenuEntry']);
    add_action('admin_post_' . self::ACTION_SAVE_SOURCE, [$this, 'handleSaveSource']);
    add_action('admin_post_' . self::ACTION_DELETE_SOURCE, [$this, 'handleDeleteSource']);
    add_action('admin_post_' . self::ACTION_TEST_SOURCE, [$this, 'handleTestSource']);
    add_action('admin_post_' . self::ACTION_SAVE_SETTINGS, [$this, 'handleSaveSettings']);
  }

  /**
   * Adds the page below "Einstellungen".
   */
  public function addMenuEntry(): void {
    add_options_page('Remote Articles', 'Remote Articles', self::CAPABILITY, self::PAGE_SLUG, [$this, 'renderPage']);
  }

  /**
   * Saves a new or edited source.
   */
  public function handleSaveSource(): void {
    $this->verifyRequest(self::ACTION_SAVE_SOURCE);

    $isEdit = isset($_POST['is_edit']) && $_POST['is_edit'] === '1';
    $alias = strtolower(trim(sanitize_text_field(wp_unslash((string)($_POST['alias'] ?? '')))));
    if (preg_match(Source::ALIAS_PATTERN, $alias) !== 1) {
      $this->redirectWithNotice('error', 'Der Alias darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (max. 40 Zeichen).');
    }

    $existingSource = $this->sourceRepository->find($alias);
    if (!$isEdit && $existingSource !== null) {
      $this->redirectWithNotice('error', sprintf('Eine Quelle mit dem Alias „%s“ gibt es bereits.', $alias));
    }
    if ($isEdit && $existingSource === null) {
      $this->redirectWithNotice('error', sprintf('Die Quelle „%s“ gibt es nicht mehr.', $alias));
    }

    $baseUrl = $this->validateBaseUrl(trim(wp_unslash((string)($_POST['base_url'] ?? ''))));
    if ($baseUrl === null) {
      $this->redirectWithNotice('error', 'Die Adresse muss eine vollständige https-Adresse sein, z. B. https://www.beispiel.de (ohne Parameter).');
    }

    $userName = sanitize_text_field(wp_unslash((string)($_POST['user_name'] ?? '')));
    $encryptedPassword = '';
    if ($existingSource !== null) {
      $encryptedPassword = $existingSource->encryptedPassword;
    }
    $newPassword = trim(wp_unslash((string)($_POST['password'] ?? '')));
    if (isset($_POST['remove_password']) && $_POST['remove_password'] === '1') {
      $encryptedPassword = '';
    } elseif ($newPassword !== '') {
      $encryptedPassword = $this->secretBox->encrypt($newPassword);
    }

    $linkMode = LinkMode::tryFrom(sanitize_key(wp_unslash((string)($_POST['link_mode'] ?? ''))));
    if ($linkMode === null) {
      $linkMode = LinkMode::NewWindow;
    }

    $cacheMinutes = max(0, min(self::MAXIMUM_CACHE_MINUTES, (int)($_POST['cache_minutes'] ?? 15)));
    $customCss = substr(str_replace('<', '', wp_unslash((string)($_POST['custom_css'] ?? ''))), 0, self::MAXIMUM_CUSTOM_CSS_LENGTH);

    $this->sourceRepository->save(new Source(
      $alias,
      $baseUrl,
      $userName,
      $encryptedPassword,
      $linkMode,
      $cacheMinutes * 60,
      $customCss
    ));
    $this->redirectWithNotice('success', sprintf('Die Quelle „%s“ wurde gespeichert.', $alias));
  }

  /**
   * Deletes a source.
   */
  public function handleDeleteSource(): void {
    $this->verifyRequest(self::ACTION_DELETE_SOURCE);
    $alias = sanitize_key(wp_unslash((string)($_POST['alias'] ?? '')));
    $this->sourceRepository->delete($alias);
    $this->redirectWithNotice('success', sprintf('Die Quelle „%s“ wurde gelöscht.', $alias));
  }

  /**
   * Tests the connection to a source by reading one category.
   */
  public function handleTestSource(): void {
    $this->verifyRequest(self::ACTION_TEST_SOURCE);
    $alias = sanitize_key(wp_unslash((string)($_POST['alias'] ?? '')));
    $source = $this->sourceRepository->find($alias);
    if ($source === null) {
      $this->redirectWithNotice('error', sprintf('Die Quelle „%s“ gibt es nicht.', $alias));
    }

    try {
      $this->remoteClient->getJson($source, '/wp/v2/categories', ['per_page' => 1, '_fields' => 'id']);
    } catch (RemoteException $exception) {
      $this->redirectWithNotice('error', $exception->getMessage());
    }
    $this->redirectWithNotice('success', sprintf('Die Verbindung zur Quelle „%s“ funktioniert.', $alias));
  }

  /**
   * Saves the general settings.
   */
  public function handleSaveSettings(): void {
    $this->verifyRequest(self::ACTION_SAVE_SETTINGS);
    $this->settings->setDefaultStyleSheetEnabled(isset($_POST['use_default_style_sheet']) && $_POST['use_default_style_sheet'] === '1');
    $this->redirectWithNotice('success', 'Die Einstellungen wurden gespeichert.');
  }

  /**
   * Renders the admin page.
   */
  public function renderPage(): void {
    if (!current_user_can(self::CAPABILITY)) {
      return;
    }

    $sources = $this->sourceRepository->getAll();
    $editedSource = null;
    if (isset($_GET['edit'])) {
      $editedSource = $this->sourceRepository->find(sanitize_key(wp_unslash((string)$_GET['edit'])));
    }

    echo '<div class="wrap">';
    echo '<h1>Remote Articles</h1>';
    $this->renderNotice();
    $this->renderSourceTable($sources);
    $this->renderSourceForm($editedSource);
    $this->renderUsage();
    $this->renderSettingsForm();
    echo '</div>';
  }

  /**
   * Renders the list of configured sources with their actions.
   *
   * @param array<string, Source> $sources
   */
  private function renderSourceTable(array $sources): void {
    echo '<h2>Quellen</h2>';
    if (count($sources) === 0) {
      echo '<p>Noch keine Quelle angelegt. Lege unten die erste WordPress-Installation an.</p>';
      return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Alias</th><th>Adresse</th><th>Zugangsdaten</th><th>Links auf die Quelle</th><th>Zwischenspeicher</th><th>Aktionen</th>';
    echo '</tr></thead><tbody>';
    foreach ($sources as $source) {
      $credentialsLabel = 'keine (öffentlich)';
      if ($source->hasCredentials()) {
        $credentialsLabel = 'Benutzer „' . $source->userName . '“';
      }

      echo '<tr>';
      echo '<td><code>' . esc_html($source->alias) . '</code></td>';
      echo '<td>' . esc_html($source->baseUrl) . '</td>';
      echo '<td>' . esc_html($credentialsLabel) . '</td>';
      echo '<td>' . esc_html($source->linkMode->getLabel()) . '</td>';
      echo '<td>' . esc_html((string)intdiv($source->cacheTtlSeconds, 60)) . ' Min.</td>';
      echo '<td>';
      echo '<a class="button" href="' . esc_url($this->getPageUrl(['edit' => $source->alias])) . '">Bearbeiten</a> ';
      $this->renderActionButton(self::ACTION_TEST_SOURCE, $source->alias, 'Verbindung testen', '');
      $this->renderActionButton(
        self::ACTION_DELETE_SOURCE,
        $source->alias,
        'Löschen',
        sprintf('Quelle „%s“ wirklich löschen? Seiten, die sie verwenden, zeigen danach nichts mehr an.', $source->alias)
      );
      echo '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
  }

  /**
   * Renders a small POST form with one button for an action on a source.
   */
  private function renderActionButton(string $action, string $alias, string $label, string $confirmation): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
    echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
    echo '<input type="hidden" name="alias" value="' . esc_attr($alias) . '">';
    wp_nonce_field($action);
    $onClick = '';
    if ($confirmation !== '') {
      $onClick = ' onclick="return confirm(' . esc_attr((string)wp_json_encode($confirmation)) . ');"';
    }
    echo '<button type="submit" class="button"' . $onClick . '>' . esc_html($label) . '</button> ';
    echo '</form>';
  }

  /**
   * Renders the form to add a source or to edit the given one.
   */
  private function renderSourceForm(?Source $source): void {
    $isEdit = $source !== null;
    $alias = '';
    $baseUrl = '';
    $userName = '';
    $linkMode = LinkMode::NewWindow;
    $cacheMinutes = 15;
    $customCss = '';
    if ($source !== null) {
      $alias = $source->alias;
      $baseUrl = $source->baseUrl;
      $userName = $source->userName;
      $linkMode = $source->linkMode;
      $cacheMinutes = intdiv($source->cacheTtlSeconds, 60);
      $customCss = $source->customCss;
    }

    if ($isEdit) {
      echo '<h2>Quelle „' . esc_html($alias) . '“ bearbeiten</h2>';
    } else {
      echo '<h2>Neue Quelle anlegen</h2>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE_SOURCE) . '">';
    if ($isEdit) {
      echo '<input type="hidden" name="is_edit" value="1">';
    }
    wp_nonce_field(self::ACTION_SAVE_SOURCE);
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="kornsw-ra-alias">Alias</label></th><td>';
    $readOnly = '';
    if ($isEdit) {
      $readOnly = ' readonly';
    }
    echo '<input type="text" id="kornsw-ra-alias" name="alias" class="regular-text" required pattern="[a-z0-9][a-z0-9\-]{0,39}" value="' . esc_attr($alias) . '"' . $readOnly . '>';
    echo '<p class="description">Kurzname für den Shortcode, z. B. <code>blog</code>. Nur Kleinbuchstaben, Ziffern und Bindestriche.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-base-url">Adresse der WordPress-Installation</label></th><td>';
    echo '<input type="url" id="kornsw-ra-base-url" name="base_url" class="regular-text" required placeholder="https://www.beispiel.de" value="' . esc_attr($baseUrl) . '">';
    echo '<p class="description">Startadresse der Quellseite (nur https). Bei Installationen in einem Unterverzeichnis mit diesem Verzeichnis.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-user-name">Benutzername (optional)</label></th><td>';
    echo '<input type="text" id="kornsw-ra-user-name" name="user_name" class="regular-text" autocomplete="off" value="' . esc_attr($userName) . '">';
    echo '<p class="description">Nur nötig, wenn die Quelle Inhalte nicht öffentlich bereitstellt.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-password">Anwendungspasswort (optional)</label></th><td>';
    $passwordPlaceholder = '';
    if ($source !== null && $source->hasCredentials()) {
      $passwordPlaceholder = 'gespeichert – leer lassen, um es zu behalten';
    }
    echo '<input type="password" id="kornsw-ra-password" name="password" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($passwordPlaceholder) . '">';
    if ($source !== null && $source->hasCredentials()) {
      echo '<p><label><input type="checkbox" name="remove_password" value="1"> Gespeichertes Passwort entfernen</label></p>';
    }
    echo '<p class="description">Ein Anwendungspasswort der Quellseite (Benutzer → Profil → Anwendungspasswörter). Es wird verschlüsselt gespeichert und nur für das Abrufen der Inhalte verwendet.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-link-mode">Links auf die Quellseite</label></th><td>';
    echo '<select id="kornsw-ra-link-mode" name="link_mode">';
    foreach (LinkMode::cases() as $mode) {
      echo '<option value="' . esc_attr($mode->value) . '"' . selected($mode->value, $linkMode->value, false) . '>' . esc_html($mode->getLabel()) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Gilt für Links auf Seiten und Beiträge der Quelle. Links auf Bilder und Dateien bleiben immer erhalten. Im Shortcode mit <code>links="…"</code> überschreibbar.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-cache-minutes">Zwischenspeicher</label></th><td>';
    echo '<input type="number" id="kornsw-ra-cache-minutes" name="cache_minutes" min="0" max="' . esc_attr((string)self::MAXIMUM_CACHE_MINUTES) . '" value="' . esc_attr((string)$cacheMinutes) . '"> Minuten';
    echo '<p class="description">So lange gelten geladene Beiträge als aktuell. Ist die Quelle nicht erreichbar, wird der zuletzt geladene Stand gezeigt.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="kornsw-ra-custom-css">Eigenes CSS (optional)</label></th><td>';
    echo '<textarea id="kornsw-ra-custom-css" name="custom_css" class="large-text code" rows="6">' . esc_textarea($customCss) . '</textarea>';
    echo '<p class="description">Wird auf Seiten mit Beiträgen dieser Quelle geladen. Klassen: <code>.kornsw-ra</code>, <code>.kornsw-ra-article</code>, <code>.kornsw-ra-title</code>, <code>.kornsw-ra-meta</code>, <code>.kornsw-ra-content</code>.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('Quelle speichern');
    if ($isEdit) {
      echo '<p><a href="' . esc_url($this->getPageUrl([])) . '">Abbrechen und neue Quelle anlegen</a></p>';
    }
    echo '</form>';
  }

  /**
   * Explains the shortcode.
   */
  private function renderUsage(): void {
    echo '<h2>Verwendung</h2>';
    echo '<p><code>[' . esc_html(RemoteArticlesShortcode::TAG) . ' alias="blog" category="rezepte"]</code></p>';
    echo '<table class="widefat striped" style="max-width:60em"><tbody>';
    echo '<tr><td><code>alias</code></td><td>Alias der Quelle (Pflicht)</td></tr>';
    echo '<tr><td><code>category</code></td><td>Slug oder ID der Kategorie auf der Quelle (Pflicht)</td></tr>';
    echo '<tr><td><code>count</code></td><td>Anzahl der Beiträge, 1 bis ' . esc_html((string)ShortcodeOptions::MAXIMUM_COUNT) . ' (Standard ' . esc_html((string)ShortcodeOptions::DEFAULT_COUNT) . ')</td></tr>';
    echo '<tr><td><code>order</code></td><td><code>desc</code> = neueste zuerst (Standard), <code>asc</code> = älteste zuerst</td></tr>';
    echo '<tr><td><code>display</code></td><td><code>full</code> = ganzer Beitrag (Standard), <code>excerpt</code> = nur Auszug</td></tr>';
    echo '<tr><td><code>image</code></td><td><code>yes</code> = Beitragsbild anzeigen</td></tr>';
    echo '<tr><td><code>links</code></td><td><code>remove</code>, <code>new_window</code> oder <code>keep</code> – überschreibt die Einstellung der Quelle</td></tr>';
    echo '</tbody></table>';
  }

  /**
   * Renders the general settings form.
   */
  private function renderSettingsForm(): void {
    echo '<h2>Allgemein</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE_SETTINGS) . '">';
    wp_nonce_field(self::ACTION_SAVE_SETTINGS);
    echo '<p><label><input type="checkbox" name="use_default_style_sheet" value="1"' . checked($this->settings->isDefaultStyleSheetEnabled(), true, false) . '> ';
    echo 'Standard-Stylesheet des Plugins laden (abschalten, wenn das Theme die Darstellung vollständig übernimmt)</label></p>';
    submit_button('Einstellungen speichern');
    echo '</form>';
  }

  /**
   * Shows the notice left by the last action of the current user.
   */
  private function renderNotice(): void {
    $transientName = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
    $notice = get_transient($transientName);
    if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
      return;
    }
    delete_transient($transientName);

    $noticeClass = 'notice-success';
    if ($notice['type'] === 'error') {
      $noticeClass = 'notice-error';
    }
    echo '<div class="notice ' . esc_attr($noticeClass) . ' is-dismissible"><p>' . esc_html((string)$notice['message']) . '</p></div>';
  }

  /**
   * Stops unless the current user may manage the plugin and the nonce of the action is valid.
   */
  private function verifyRequest(string $action): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die('Keine Berechtigung.', '', ['response' => 403]);
    }
    check_admin_referer($action);
  }

  /**
   * Stores a notice for the current user and returns to the admin page.
   */
  private function redirectWithNotice(string $type, string $message): never {
    set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), ['type' => $type, 'message' => $message], 60);
    wp_safe_redirect($this->getPageUrl([]));
    exit;
  }

  /**
   * Returns the URL of the admin page with additional query arguments.
   *
   * @param array<string, string> $arguments
   */
  private function getPageUrl(array $arguments): string {
    return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $arguments), admin_url('options-general.php'));
  }

  /**
   * Accepts only absolute https URLs without credentials, query or fragment; returns them without trailing slash.
   */
  private function validateBaseUrl(string $url): ?string {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return null;
    }
    if (strtolower($parts['scheme']) !== 'https' || $parts['host'] === '') {
      return null;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      return null;
    }
    if (preg_match('#^[a-z0-9.-]+$#i', $parts['host']) !== 1) {
      return null;
    }

    $normalizedUrl = 'https://' . strtolower($parts['host']);
    if (isset($parts['port'])) {
      $normalizedUrl .= ':' . (int)$parts['port'];
    }
    if (isset($parts['path'])) {
      $path = rtrim($parts['path'], '/');
      if (preg_match('#^(/[a-z0-9._~%-]+)*$#i', $path) !== 1) {
        return null;
      }
      $normalizedUrl .= $path;
    }
    return $normalizedUrl;
  }

}
