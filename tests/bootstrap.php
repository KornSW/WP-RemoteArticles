<?php

declare(strict_types=1);

// Standalone test bootstrap: PSR-4 autoloader for the plugin classes, a minimal assertion helper. No WordPress is loaded,
// so only classes without WordPress calls (namespace KornSW\RemoteArticles\Embedding) can be tested here.

spl_autoload_register(function (string $className): void {
  $namespacePrefix = 'KornSW\\RemoteArticles\\';
  if (strncmp($className, $namespacePrefix, strlen($namespacePrefix)) !== 0) {
    return;
  }
  $relativeClassName = substr($className, strlen($namespacePrefix));
  $fileName = __DIR__ . '/../kornsw-remotearticles/src/' . str_replace('\\', '/', $relativeClassName) . '.php';
  if (is_readable($fileName)) {
    require_once $fileName;
  }
});

/**
 * Collects assertion results and prints a summary.
 */
final class TestRun {

  private static int $passedCount = 0;

  /** @var list<string> */
  private static array $failures = [];

  /**
   * Records a passed or failed check.
   */
  public static function check(bool $condition, string $description, string $details = ''): void {
    if ($condition) {
      self::$passedCount++;
      return;
    }
    $message = 'FAIL: ' . $description;
    if ($details !== '') {
      $message .= "\n      " . str_replace("\n", "\n      ", $details);
    }
    self::$failures[] = $message;
  }

  /**
   * Prints the summary and returns the process exit code.
   */
  public static function finish(): int {
    foreach (self::$failures as $failure) {
      echo $failure, "\n";
    }
    echo sprintf("\n%d passed, %d failed\n", self::$passedCount, count(self::$failures));
    if (count(self::$failures) > 0) {
      return 1;
    }
    return 0;
  }

}
