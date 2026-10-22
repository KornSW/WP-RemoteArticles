<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

/**
 * Parameters of a gallery of the plugin "Share-one-Drive" (WP Cloud Plugins) as found in the content of a post.
 * All values are validated with strict patterns: they end up in a request to the source.
 */
final class ShareOneDriveModule {

  private function __construct(
    public readonly string $listToken,
    public readonly string $accountId,
    public readonly string $driveId,
    public readonly string $folderId,
    public readonly string $folderPath,
    public readonly string $sort,
    public readonly int $targetHeight
  ) {
  }

  /**
   * Creates a module from the data attributes of its element; returns null if a value is missing or invalid.
   *
   * @param array<string, string> $attributes Attribute name => value (data-token, data-account-id, ...).
   */
  public static function fromAttributes(array $attributes): ?ShareOneDriveModule {
    $listToken = $attributes['data-token'] ?? '';
    $accountId = $attributes['data-account-id'] ?? '';
    $driveId = $attributes['data-drive-id'] ?? '';
    $folderId = $attributes['data-id'] ?? '';
    $folderPath = $attributes['data-path'] ?? '';
    $sort = $attributes['data-sort'] ?? 'name:asc';
    $targetHeight = $attributes['data-targetheight'] ?? '';

    if (preg_match('/^[a-f0-9]{32}$/', $listToken) !== 1) {
      return null;
    }
    if (preg_match('/^[A-Za-z0-9!]{1,64}$/', $accountId) !== 1 || preg_match('/^[A-Za-z0-9!]{1,64}$/', $driveId) !== 1) {
      return null;
    }
    if (preg_match('/^[A-Za-z0-9!%._-]{0,128}$/', $folderId) !== 1) {
      return null;
    }
    if (preg_match('#^[A-Za-z0-9+/=]{0,1024}$#', $folderPath) !== 1) {
      return null;
    }
    if (preg_match('/^[a-z_]{1,32}:(asc|desc)$/', $sort) !== 1) {
      $sort = 'name:asc';
    }

    $height = 300;
    if (preg_match('/^\d{2,3}$/', $targetHeight) === 1) {
      $height = max(80, min(600, (int)$targetHeight));
    }

    return new ShareOneDriveModule($listToken, $accountId, $driveId, $folderId, $folderPath, $sort, $height);
  }

  /**
   * Returns the module as a compact array (used in the signed request of the gallery endpoint).
   *
   * @return array<string, string|int>
   */
  public function toArray(): array {
    return [
      'token' => $this->listToken,
      'account' => $this->accountId,
      'drive' => $this->driveId,
      'folder' => $this->folderId,
      'path' => $this->folderPath,
      'sort' => $this->sort,
      'height' => $this->targetHeight,
    ];
  }

  /**
   * Restores a module from toArray(); the values are validated again.
   *
   * @param array<string, mixed> $data
   */
  public static function fromArray(array $data): ?ShareOneDriveModule {
    return self::fromAttributes([
      'data-token' => (string)($data['token'] ?? ''),
      'data-account-id' => (string)($data['account'] ?? ''),
      'data-drive-id' => (string)($data['drive'] ?? ''),
      'data-id' => (string)($data['folder'] ?? ''),
      'data-path' => (string)($data['path'] ?? ''),
      'data-sort' => (string)($data['sort'] ?? ''),
      'data-targetheight' => (string)($data['height'] ?? ''),
    ]);
  }

  /**
   * Returns the form fields of the request "shareonedrive-get-gallery".
   *
   * @return array<string, string>
   */
  public function buildRequestFields(string $nonce): array {
    return [
      'action' => 'shareonedrive-get-gallery',
      'type' => 'gallery',
      '_ajax_nonce' => $nonce,
      'listtoken' => $this->listToken,
      'account_id' => $this->accountId,
      'drive_id' => $this->driveId,
      'lastFolder' => $this->folderId,
      'folderPath' => $this->folderPath,
      'sort' => $this->sort,
      'mobile' => '',
      'query' => '',
    ];
  }

}
