<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * Sanitizes foreign CSS (style attributes and style sheets) according to doc/security.md.
 *
 * The sanitizer fails closed: anything it cannot parse reliably is dropped.
 * - no escapes, comments that cannot be removed, "<" (breaking out of a style element);
 * - no expression(), javascript:, behavior, -moz-binding, @import, @font-face and other at-rules except grouping and keyframes;
 * - url() only to the source or the own site (https), or inline raster images;
 * - no position: fixed / sticky (overlaying the own page);
 * - style sheets are scoped to the wrapper element, IDs get the context's prefix.
 */
final class CssSanitizer {

  private const BLOCKED_PROPERTIES = [
    'behavior' => true,
    '-moz-binding' => true,
  ];

  private const GROUPING_AT_RULES = [
    'media' => true,
    'supports' => true,
    'container' => true,
    'layer' => true,
  ];

  private const KEYFRAMES_AT_RULES = [
    'keyframes' => true,
    '-webkit-keyframes' => true,
  ];

  private const MAXIMUM_NESTING_DEPTH = 4;

  public function __construct(
    private readonly UrlResolver $urlResolver
  ) {
  }

  /**
   * Sanitizes a list of declarations as found in a style attribute.
   *
   * @return string The sanitized declarations, or '' if nothing survived.
   */
  public function sanitizeDeclarations(string $declarations, EmbeddingContext $context): string {
    $css = $this->removeComments($declarations);
    if ($css === null || $this->containsForbiddenCharacters($css)) {
      return '';
    }

    $sanitizedDeclarations = [];
    foreach ($this->splitOutsideBrackets($css, ';') as $declaration) {
      $sanitizedDeclaration = $this->sanitizeDeclaration($declaration, $context);
      if ($sanitizedDeclaration !== '') {
        $sanitizedDeclarations[] = $sanitizedDeclaration;
      }
    }
    return implode(';', $sanitizedDeclarations);
  }

  /**
   * Sanitizes a complete style sheet (content of a style element) and scopes it to the wrapper element.
   *
   * @return string The sanitized style sheet, or '' if it could not be parsed or nothing survived.
   */
  public function sanitizeStyleSheet(string $styleSheet, EmbeddingContext $context): string {
    $css = $this->removeComments($styleSheet);
    if ($css === null || $this->containsForbiddenCharacters($css)) {
      return '';
    }
    $sanitizedRules = $this->sanitizeRules($css, $context, 0);
    if ($sanitizedRules === null) {
      return '';
    }
    return $sanitizedRules;
  }

  /**
   * Removes comments; returns null if an unterminated comment remains.
   */
  private function removeComments(string $css): ?string {
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $css);
    if (!is_string($withoutComments) || str_contains($withoutComments, '/*')) {
      return null;
    }
    return $withoutComments;
  }

  /**
   * Escapes and markup are rejected entirely: they are the classic way to hide forbidden keywords from filters.
   */
  private function containsForbiddenCharacters(string $css): bool {
    return str_contains($css, '\\') || str_contains($css, '<');
  }

  /**
   * Sanitizes a sequence of rules; returns null if the CSS is malformed.
   */
  private function sanitizeRules(string $css, EmbeddingContext $context, int $depth): ?string {
    if ($depth > self::MAXIMUM_NESTING_DEPTH) {
      return null;
    }

    $result = '';
    $position = 0;
    $length = strlen($css);

    while ($position < $length) {
      $blockStart = $this->findOutsideStrings($css, ['{', ';', '}'], $position);
      if ($blockStart === null) {
        if (trim(substr($css, $position)) !== '') {
          return null;
        }
        break;
      }

      $prelude = trim(substr($css, $position, $blockStart - $position));
      $delimiter = $css[$blockStart];

      if ($delimiter === '}') {
        return null;
      }
      if ($delimiter === ';') {
        // Statements without a block (@import, @charset, @namespace, stray declarations) are dropped.
        $position = $blockStart + 1;
        continue;
      }

      $blockEnd = $this->findMatchingBrace($css, $blockStart);
      if ($blockEnd === null) {
        return null;
      }
      $blockContent = substr($css, $blockStart + 1, $blockEnd - $blockStart - 1);
      $position = $blockEnd + 1;

      $sanitizedRule = $this->sanitizeRule($prelude, $blockContent, $context, $depth);
      if ($sanitizedRule === null) {
        return null;
      }
      $result .= $sanitizedRule;
    }

    return $result;
  }

  /**
   * Sanitizes one qualified rule or at-rule with block.
   */
  private function sanitizeRule(string $prelude, string $blockContent, EmbeddingContext $context, int $depth): ?string {
    if ($prelude === '') {
      return null;
    }

    if (str_starts_with($prelude, '@')) {
      if (preg_match('/^@([a-z-]+)\s*(.*)$/is', $prelude, $matches) !== 1) {
        return null;
      }
      $atRuleName = strtolower($matches[1]);
      $atRuleCondition = trim($matches[2]);

      if (isset(self::GROUPING_AT_RULES[$atRuleName])) {
        if (preg_match('/url\s*\(|[{};]/i', $atRuleCondition) === 1) {
          return '';
        }
        $innerRules = $this->sanitizeRules($blockContent, $context, $depth + 1);
        if ($innerRules === null) {
          return null;
        }
        if ($innerRules === '') {
          return '';
        }
        return '@' . $atRuleName . ' ' . $atRuleCondition . '{' . $innerRules . '}';
      }

      if (isset(self::KEYFRAMES_AT_RULES[$atRuleName])) {
        if (preg_match('/^-?[_a-z][_a-z0-9-]*$/i', $atRuleCondition) !== 1) {
          return '';
        }
        $keyframes = $this->sanitizeKeyframes($blockContent, $context);
        if ($keyframes === null) {
          return null;
        }
        return '@' . $atRuleName . ' ' . $atRuleCondition . '{' . $keyframes . '}';
      }

      // @font-face, @page, @property and unknown at-rules are dropped.
      return '';
    }

    $scopedSelectors = $this->scopeSelectorList($prelude, $context);
    if ($scopedSelectors === '') {
      return '';
    }
    $declarations = $this->sanitizeDeclarations($blockContent, $context);
    if ($declarations === '') {
      return '';
    }
    return $scopedSelectors . '{' . $declarations . '}';
  }

  /**
   * Sanitizes the frames of a keyframes rule (selectors are percentages, "from" or "to").
   */
  private function sanitizeKeyframes(string $css, EmbeddingContext $context): ?string {
    $result = '';
    $position = 0;
    $length = strlen($css);

    while ($position < $length) {
      $blockStart = $this->findOutsideStrings($css, ['{', '}'], $position);
      if ($blockStart === null) {
        if (trim(substr($css, $position)) !== '') {
          return null;
        }
        break;
      }
      if ($css[$blockStart] === '}') {
        return null;
      }
      $frameSelector = trim(substr($css, $position, $blockStart - $position));
      $blockEnd = $this->findMatchingBrace($css, $blockStart);
      if ($blockEnd === null) {
        return null;
      }
      $position = $blockEnd + 1;

      if (preg_match('/^(from|to|\d{1,3}(\.\d+)?%)(\s*,\s*(from|to|\d{1,3}(\.\d+)?%))*$/i', $frameSelector) !== 1) {
        continue;
      }
      $declarations = $this->sanitizeDeclarations(substr($css, $blockStart + 1, $blockEnd - $blockStart - 1), $context);
      if ($declarations !== '') {
        $result .= $frameSelector . '{' . $declarations . '}';
      }
    }

    return $result;
  }

  /**
   * Prefixes every selector of a selector list with the scope selector and renames IDs.
   *
   * @return string The scoped selector list, or '' if a selector is not acceptable.
   */
  private function scopeSelectorList(string $selectorList, EmbeddingContext $context): string {
    if (preg_match('/url\s*\(|[{};@]/i', $selectorList) === 1) {
      return '';
    }

    $scopedSelectors = [];
    foreach ($this->splitOutsideBrackets($selectorList, ',') as $selector) {
      $trimmedSelector = trim($selector);
      if ($trimmedSelector === '') {
        return '';
      }

      $prefixedSelector = preg_replace('/#(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', '#' . $context->idPrefix . '$1', $trimmedSelector);
      if (!is_string($prefixedSelector)) {
        return '';
      }

      // Rules for the whole document (html, body, :root) are redirected to the wrapper element.
      $documentRelativeSelector = preg_replace('/^(?:(?:html|body|:root)(?=$|[\s.#:\[>+~])\s*)+/i', '', $prefixedSelector);
      if (!is_string($documentRelativeSelector)) {
        return '';
      }

      if ($documentRelativeSelector === '') {
        $scopedSelectors[] = $context->scopeSelector;
      } elseif (preg_match('/^[.#:\[]/', $documentRelativeSelector) === 1 && $documentRelativeSelector !== $prefixedSelector) {
        $scopedSelectors[] = $context->scopeSelector . $documentRelativeSelector;
      } else {
        $scopedSelectors[] = $context->scopeSelector . ' ' . $documentRelativeSelector;
      }
    }
    return implode(',', $scopedSelectors);
  }

  /**
   * Sanitizes a single "property: value" declaration.
   *
   * @return string The sanitized declaration, or '' if it is dropped.
   */
  private function sanitizeDeclaration(string $declaration, EmbeddingContext $context): string {
    $colonPosition = strpos($declaration, ':');
    if ($colonPosition === false) {
      return '';
    }

    $property = strtolower(trim(substr($declaration, 0, $colonPosition)));
    $value = trim(substr($declaration, $colonPosition + 1));

    if ($value === '' || preg_match('/^(--[a-z0-9_-]+|-?[a-z][a-z-]*)$/', $property) !== 1) {
      return '';
    }
    if (isset(self::BLOCKED_PROPERTIES[$property])) {
      return '';
    }
    if (preg_match('/expression\s*\(|javascript:|vbscript:|@import|image-set\s*\(|-moz-binding|behavior\s*:/i', $value) === 1) {
      return '';
    }
    if ($property === 'position' && preg_match('/fixed|sticky/i', $value) === 1) {
      return '';
    }

    $sanitizedValue = $this->sanitizeUrlsInValue($value, $context);
    if ($sanitizedValue === null) {
      return '';
    }
    return $property . ':' . $sanitizedValue;
  }

  /**
   * Validates every url() of a value and rewrites it to a quoted, resolved URL.
   *
   * @return string|null The value with sanitized URLs, or null if one of the URLs is not allowed.
   */
  private function sanitizeUrlsInValue(string $value, EmbeddingContext $context): ?string {
    if (stripos($value, 'url') === false) {
      return $value;
    }

    $allowedHosts = [
      $context->sourceHost => true,
      $context->ownHost => true,
    ];
    $isValid = true;

    $result = preg_replace_callback(
      '/url\s*\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'()]*))\s*\)/i',
      function (array $matches) use ($context, $allowedHosts, &$isValid): string {
        $rawUrl = $matches[1] . ($matches[2] ?? '') . ($matches[3] ?? '');
        if ($this->urlResolver->isImageDataUrl($rawUrl)) {
          return 'url("' . $rawUrl . '")';
        }
        $resolvedUrl = $this->urlResolver->resolveResourceUrl($rawUrl, $context->documentUrl);
        if ($resolvedUrl === null) {
          $isValid = false;
          return '';
        }
        if (!isset($allowedHosts[$this->urlResolver->getComparableHost($resolvedUrl)])) {
          $isValid = false;
          return '';
        }
        return 'url("' . $resolvedUrl . '")';
      },
      $value
    );

    if (!is_string($result) || !$isValid) {
      return null;
    }
    // A "url" that was not matched by the pattern above (unusual spelling) is not trusted.
    $withoutSanitizedUrls = preg_replace('/url\("[^"]*"\)/i', '', $result);
    if (!is_string($withoutSanitizedUrls) || stripos($withoutSanitizedUrls, 'url') !== false) {
      return null;
    }
    return $result;
  }

  /**
   * Splits a string at the delimiter, ignoring delimiters inside quotes and brackets.
   *
   * @return list<string>
   */
  private function splitOutsideBrackets(string $text, string $delimiter): array {
    $parts = [];
    $current = '';
    $depth = 0;
    $quote = '';
    $length = strlen($text);

    for ($index = 0; $index < $length; $index++) {
      $character = $text[$index];
      if ($quote !== '') {
        if ($character === $quote) {
          $quote = '';
        }
        $current .= $character;
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
      } elseif ($character === '(' || $character === '[') {
        $depth++;
      } elseif (($character === ')' || $character === ']') && $depth > 0) {
        $depth--;
      } elseif ($character === $delimiter && $depth === 0) {
        $parts[] = $current;
        $current = '';
        continue;
      }
      $current .= $character;
    }
    $parts[] = $current;
    return $parts;
  }

  /**
   * Finds the first of the given characters outside of quoted strings.
   *
   * @param list<string> $characters
   */
  private function findOutsideStrings(string $text, array $characters, int $offset): ?int {
    $quote = '';
    $length = strlen($text);
    for ($index = $offset; $index < $length; $index++) {
      $character = $text[$index];
      if ($quote !== '') {
        if ($character === $quote) {
          $quote = '';
        }
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
        continue;
      }
      if (in_array($character, $characters, true)) {
        return $index;
      }
    }
    return null;
  }

  /**
   * Finds the closing brace that matches the opening brace at the given position.
   */
  private function findMatchingBrace(string $text, int $openingBracePosition): ?int {
    $depth = 0;
    $quote = '';
    $length = strlen($text);
    for ($index = $openingBracePosition; $index < $length; $index++) {
      $character = $text[$index];
      if ($quote !== '') {
        if ($character === $quote) {
          $quote = '';
        }
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
      } elseif ($character === '{') {
        $depth++;
      } elseif ($character === '}') {
        $depth--;
        if ($depth === 0) {
          return $index;
        }
      }
    }
    return null;
  }

}
