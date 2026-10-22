<?php

declare(strict_types=1);

// Standalone tests of the embedding pipeline (doc/security.md requires them for every change of the sanitizing).
// Run: php tests/run-tests.php   or without native PHP: node tests/php-wasm/run.mjs tests/run-tests.php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/security-tests.php';
require __DIR__ . '/embedding-tests.php';
require __DIR__ . '/shortcode-tests.php';

exit(TestRun::finish());
