// Runs a PHP script of this repository with PHP 8.5 compiled to WebAssembly (no native PHP needed).
// Setup once:   cd tests/php-wasm && npm install
// Usage:        node tests/php-wasm/run.mjs tests/run-tests.php
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const [script, ...scriptArguments] = process.argv.slice(2);
if (script === undefined) {
  console.error('Usage: node tests/php-wasm/run.mjs <script relative to the repository root> [arguments]');
  process.exit(2);
}

const php = new PHP(await loadNodeRuntime('8.5', { emscriptenOptions: { processId: 1 } }));
php.mkdir('/repo');
await php.mount('/repo', createNodeFsMountHandler(repositoryRoot));
const response = await php.cli(['php', '/repo/' + script.replaceAll('\\', '/'), ...scriptArguments]);
process.stdout.write(await response.stdoutText);
process.stderr.write(await response.stderrText);
process.exit(await response.exitCode);
