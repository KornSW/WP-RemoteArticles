<?php

declare(strict_types=1);

use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Shortcode\ShortcodeOptions;

// Shortcode attributes must not be a lever for URLs, paths or other injections (doc/security.md, section 1).

/**
 * Returns the error message for the attributes, or '' if they are accepted.
 *
 * @param array<string, string> $attributes
 */
function getShortcodeError(array $attributes): string {
  try {
    ShortcodeOptions::fromAttributes($attributes);
  } catch (\InvalidArgumentException $exception) {
    return $exception->getMessage();
  }
  return '';
}

$rejectedAttributes = [
  'missing alias' => ['category' => 'rezepte'],
  'missing category' => ['alias' => 'blog'],
  'URL as alias' => ['alias' => 'https://evil.example', 'category' => 'rezepte'],
  'path traversal as alias' => ['alias' => '../blog', 'category' => 'rezepte'],
  'URL as category' => ['alias' => 'blog', 'category' => 'https://evil.example/x'],
  'path in category' => ['alias' => 'blog', 'category' => 'a/../b'],
  'query in category' => ['alias' => 'blog', 'category' => 'a&per_page=100'],
  'markup in category' => ['alias' => 'blog', 'category' => '<script>'],
  'unknown link mode' => ['alias' => 'blog', 'category' => 'rezepte', 'links' => 'javascript'],
];
foreach ($rejectedAttributes as $description => $attributes) {
  TestRun::check(getShortcodeError($attributes) !== '', 'shortcode rejects ' . $description);
}

$options = ShortcodeOptions::fromAttributes(['alias' => 'Blog', 'category' => 'rezepte-für-alle', 'count' => '500', 'order' => 'ASC', 'display' => 'excerpt', 'image' => 'yes', 'links' => 'remove', 'unknown' => 'x']);
TestRun::check($options->alias === 'blog', 'alias is lower-cased');
TestRun::check($options->category === 'rezepte-für-alle', 'category slugs with umlauts are accepted');
TestRun::check($options->count === ShortcodeOptions::MAXIMUM_COUNT, 'count is limited to the maximum');
TestRun::check($options->order === 'asc' && $options->showExcerptOnly && $options->showImage, 'order, display and image are parsed');
TestRun::check($options->linkMode === LinkMode::Remove, 'link mode can be overridden');

$defaults = ShortcodeOptions::fromAttributes(['alias' => 'blog', 'category' => '42', 'count' => 'abc']);
TestRun::check($defaults->count === ShortcodeOptions::DEFAULT_COUNT && $defaults->order === 'desc', 'invalid count falls back to the default');
TestRun::check($defaults->linkMode === null && !$defaults->showImage && !$defaults->showExcerptOnly, 'defaults: source link mode, full content, no image');
