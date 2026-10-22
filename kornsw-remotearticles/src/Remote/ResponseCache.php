<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Remote;

/**
 * Caches data fetched from sources in transients (database, not publicly reachable).
 *
 * Entries are kept much longer than they count as fresh: if a source fails, the last known state is shown instead of an error.
 * Cached data is raw source data; it is sanitized again on every output (doc/security.md, section 6).
 */
final class ResponseCache {

  private const RETENTION_SECONDS = 30 * DAY_IN_SECONDS;
  private const KEY_PREFIX = 'kornsw_ra_';

  /**
   * Returns fresh cached data, or loads, stores and returns new data; falls back to stale data if loading fails.
   *
   * @param string $cacheKey Any string identifying the request (it is hashed).
   * @param int $freshSeconds How long an entry counts as fresh (0 = always reload).
   * @param callable(): array<mixed> $loader Loads the data; throws RemoteException on failure.
   * @return array<mixed>
   * @throws RemoteException If loading fails and no cached data exists.
   */
  public function remember(string $cacheKey, int $freshSeconds, callable $loader): array {
    $transientName = self::KEY_PREFIX . md5($cacheKey);
    $entry = get_transient($transientName);
    $hasEntry = is_array($entry) && isset($entry['storedAt'], $entry['data']) && is_array($entry['data']);

    if ($hasEntry && $freshSeconds > 0 && time() - (int)$entry['storedAt'] < $freshSeconds) {
      return $entry['data'];
    }

    try {
      $data = $loader();
    } catch (RemoteException $exception) {
      if ($hasEntry) {
        return $entry['data'];
      }
      throw $exception;
    }

    set_transient($transientName, ['storedAt' => time(), 'data' => $data], self::RETENTION_SECONDS);
    return $data;
  }

}
