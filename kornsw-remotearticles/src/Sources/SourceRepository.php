<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Sources;

/**
 * Stores the configured sources in one option (not autoloaded), keyed by alias.
 */
final class SourceRepository {

  public const OPTION_NAME = 'kornsw_ra_sources';

  /**
   * Returns all sources, sorted by alias.
   *
   * @return array<string, Source>
   */
  public function getAll(): array {
    $storedSources = get_option(self::OPTION_NAME, []);
    if (!is_array($storedSources)) {
      return [];
    }

    $sources = [];
    foreach ($storedSources as $storedSource) {
      if (!is_array($storedSource)) {
        continue;
      }
      $source = Source::fromArray($storedSource);
      if ($source !== null) {
        $sources[$source->alias] = $source;
      }
    }
    ksort($sources);
    return $sources;
  }

  /**
   * Returns the source with the given alias, or null.
   */
  public function find(string $alias): ?Source {
    $sources = $this->getAll();
    return $sources[$alias] ?? null;
  }

  /**
   * Adds or replaces a source.
   */
  public function save(Source $source): void {
    $sources = $this->getAll();
    $sources[$source->alias] = $source;
    $this->store($sources);
  }

  /**
   * Removes a source; unknown aliases are ignored.
   */
  public function delete(string $alias): void {
    $sources = $this->getAll();
    unset($sources[$alias]);
    $this->store($sources);
  }

  /**
   * Writes all sources back to the option.
   *
   * @param array<string, Source> $sources
   */
  private function store(array $sources): void {
    $storedSources = [];
    foreach ($sources as $alias => $source) {
      $storedSources[$alias] = $source->toArray();
    }
    update_option(self::OPTION_NAME, $storedSources, false);
  }

}
