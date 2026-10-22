<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

use KornSW\RemoteArticles\Sources\SourceRepository;

/**
 * Runs due synchronizations in the background. One hourly WP-Cron event checks every source with import rules against
 * its own interval (default 4 hours). WP-Cron runs only on page requests; on quiet sites runs may start later.
 */
final class SyncScheduler {

  public const CRON_HOOK = 'kornsw_ra_sync_due';

  public function __construct(
    private readonly SourceRepository $sourceRepository,
    private readonly Synchronizer $synchronizer,
    private readonly SyncStatusRepository $syncStatusRepository
  ) {
  }

  /**
   * Registers the cron handler and makes sure the hourly event exists.
   */
  public function register(): void {
    add_action(self::CRON_HOOK, [$this, 'runDueSynchronizations']);
    if (wp_next_scheduled(self::CRON_HOOK) === false) {
      wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
    }
  }

  /**
   * Removes the cron event (plugin deactivation). Imported posts are kept.
   */
  public static function unschedule(): void {
    wp_clear_scheduled_hook(self::CRON_HOOK);
  }

  /**
   * Synchronizes every source whose interval has elapsed or whose last run left posts over.
   */
  public function runDueSynchronizations(): void {
    foreach ($this->sourceRepository->getAll() as $source) {
      if (!$source->hasImportRules()) {
        continue;
      }
      $status = $this->syncStatusRepository->find($source->alias);
      $intervalSeconds = $source->syncIntervalHours * HOUR_IN_SECONDS;
      // A few minutes of tolerance, so that a 4-hour interval is not shifted to 5 hours by the hourly event.
      $isIntervalElapsed = $status === null || time() - $status['time'] >= $intervalSeconds - 10 * MINUTE_IN_SECONDS;
      $hasPendingPosts = $status !== null && $status['pending'] > 0;
      if ($isIntervalElapsed || $hasPendingPosts) {
        $this->synchronizer->synchronize($source);
      }
    }
  }

}
