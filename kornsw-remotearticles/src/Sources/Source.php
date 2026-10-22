<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Sources;

use KornSW\RemoteArticles\Embedding\LinkMode;

/**
 * A configured source WordPress installation, addressed in shortcodes by its alias.
 */
final class Source {

  public const ALIAS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

  /**
   * @param string $alias Unique short name used in shortcodes.
   * @param string $baseUrl Base URL of the installation (https, without trailing slash).
   * @param string $userName User name for application passwords ('' = anonymous access).
   * @param string $encryptedPassword Encrypted application password ('' = none).
   * @param LinkMode $linkMode Default treatment of links to pages of the source.
   * @param int $cacheTtlSeconds How long fetched data counts as fresh.
   * @param string $customCss Additional CSS entered by an administrator.
   */
  public function __construct(
    public readonly string $alias,
    public readonly string $baseUrl,
    public readonly string $userName,
    public readonly string $encryptedPassword,
    public readonly LinkMode $linkMode,
    public readonly int $cacheTtlSeconds,
    public readonly string $customCss
  ) {
  }

  /**
   * Tells whether credentials are stored for this source.
   */
  public function hasCredentials(): bool {
    return $this->userName !== '' && $this->encryptedPassword !== '';
  }

  /**
   * Returns the host of the base URL in lower case.
   */
  public function getHost(): string {
    $host = parse_url($this->baseUrl, PHP_URL_HOST);
    if (!is_string($host)) {
      return '';
    }
    return strtolower($host);
  }

  /**
   * Creates a source from its stored representation; returns null for invalid data.
   *
   * @param array<string, mixed> $data
   */
  public static function fromArray(array $data): ?Source {
    $alias = $data['alias'] ?? null;
    $baseUrl = $data['baseUrl'] ?? null;
    if (!is_string($alias) || preg_match(self::ALIAS_PATTERN, $alias) !== 1 || !is_string($baseUrl) || $baseUrl === '') {
      return null;
    }

    $linkMode = LinkMode::tryFrom((string)($data['linkMode'] ?? ''));
    if ($linkMode === null) {
      $linkMode = LinkMode::NewWindow;
    }

    return new Source(
      $alias,
      $baseUrl,
      (string)($data['userName'] ?? ''),
      (string)($data['encryptedPassword'] ?? ''),
      $linkMode,
      max(0, (int)($data['cacheTtlSeconds'] ?? 900)),
      (string)($data['customCss'] ?? '')
    );
  }

  /**
   * Returns the representation stored in the option.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array {
    return [
      'alias' => $this->alias,
      'baseUrl' => $this->baseUrl,
      'userName' => $this->userName,
      'encryptedPassword' => $this->encryptedPassword,
      'linkMode' => $this->linkMode->value,
      'cacheTtlSeconds' => $this->cacheTtlSeconds,
      'customCss' => $this->customCss,
    ];
  }

}
