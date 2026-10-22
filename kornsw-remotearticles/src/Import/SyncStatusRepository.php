<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * Stores the result of the last synchronization per source (one option, not autoloaded).
 */
final class SyncStatusRepository {

  public const OPTION_NAME = 'kornsw_ra_sync_status';

  /**
   * Returns the status of a source, or null if it was never synchronized.
   *
   * @return array{time: int, success: bool, message: string, pending: int}|null
   */
  public function find(string $alias): ?array {
    $statuses = $this->loadAll();
    $status = $statuses[$alias] ?? null;
    if (!is_array($status) || !isset($status['time'], $status['success'], $status['message'])) {
      return null;
    }
    return [
      'time' => (int)$status['time'],
      'success' => (bool)$status['success'],
      'message' => (string)$status['message'],
      'pending' => (int)($status['pending'] ?? 0),
    ];
  }

  /**
   * Stores the status of a source.
   *
   * @param int $pendingCount Posts left over for a follow-up run.
   */
  public function save(string $alias, bool $success, string $message, int $pendingCount): void {
    $statuses = $this->loadAll();
    $statuses[$alias] = [
      'time' => time(),
      'success' => $success,
      'message' => $message,
      'pending' => $pendingCount,
    ];
    update_option(self::OPTION_NAME, $statuses, false);
  }

  /**
   * Removes the status of a deleted source.
   */
  public function delete(string $alias): void {
    $statuses = $this->loadAll();
    unset($statuses[$alias]);
    update_option(self::OPTION_NAME, $statuses, false);
  }

  /**
   * @return array<string, mixed>
   */
  private function loadAll(): array {
    $statuses = get_option(self::OPTION_NAME, []);
    if (!is_array($statuses)) {
      return [];
    }
    return $statuses;
  }

}
