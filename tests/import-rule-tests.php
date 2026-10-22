<?php

declare(strict_types=1);

use KornSW\RemoteArticles\Import\ImportRuleParser;
use KornSW\RemoteArticles\Import\TermReference;

// Import rules: syntax, categories vs. tags, and rejection of anything else (they are entered by administrators, but
// end up in REST queries and local term names).

$parser = new ImportRuleParser();

$rules = $parser->parse("rezepte, 17 > Kochen\r\n\r\n// Kommentar\n#backen, rezepte-f%c3%bcr > Kochen, #Familie & Freunde\n");
TestRun::check(count($rules) === 2, 'two rules are parsed, empty lines and comments are ignored');
TestRun::check(
  $rules[0]->remoteTerms[0]->value === 'rezepte' && $rules[0]->remoteTerms[1]->value === '17' && !$rules[0]->remoteTerms[0]->isTag(),
  'remote categories by slug and ID'
);
TestRun::check($rules[0]->localTerms[0]->taxonomy === TermReference::TAXONOMY_CATEGORY && $rules[0]->localTerms[0]->value === 'Kochen', 'local category by name');
TestRun::check($rules[1]->remoteTerms[0]->isTag() && $rules[1]->remoteTerms[0]->value === 'backen', 'remote tag with "#"');
TestRun::check($rules[1]->remoteTerms[1]->value === 'rezepte-f%c3%bcr', 'percent-encoded remote slugs are accepted');
TestRun::check($rules[1]->localTerms[1]->isTag() && $rules[1]->localTerms[1]->value === 'Familie & Freunde', 'local tag names with spaces and "&"');
TestRun::check($rules[1]->localTerms[1]->toNotation() === '#Familie & Freunde', 'notation of a tag');
TestRun::check(count($parser->parse("  \n// nur Kommentar\n")) === 0, 'no rules = no import');

$invalidRules = [
  'missing ">"' => 'rezepte Kochen',
  'two ">"' => 'a > b > c',
  'empty remote side' => ' > Kochen',
  'empty local side' => 'rezepte > ',
  'URL as remote term' => 'https://evil.example/x > Kochen',
  'query in remote term' => 'a&per_page=100 > Kochen',
  'markup in local name' => 'rezepte > <script>',
  'slash in local name' => 'rezepte > a/b',
];
foreach ($invalidRules as $description => $rulesText) {
  $message = '';
  try {
    $parser->parse($rulesText);
  } catch (\InvalidArgumentException $exception) {
    $message = $exception->getMessage();
  }
  TestRun::check($message !== '', 'import rules reject ' . $description);
}

$message = '';
try {
  $parser->parse("a > b\nc d");
} catch (\InvalidArgumentException $exception) {
  $message = $exception->getMessage();
}
TestRun::check(str_contains($message, 'Zeile 2'), 'error messages name the line', $message);
