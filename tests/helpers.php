<?php

declare(strict_types=1);

use KornSW\RemoteArticles\Embedding\CssSanitizer;
use KornSW\RemoteArticles\Embedding\EmbeddingContext;
use KornSW\RemoteArticles\Embedding\HtmlSanitizer;
use KornSW\RemoteArticles\Embedding\LinkClassifier;
use KornSW\RemoteArticles\Embedding\LinkMode;
use KornSW\RemoteArticles\Embedding\UrlResolver;

/**
 * Creates the context used by all tests: a post on https://quelle.example embedded into https://eigene.example.
 */
function createTestContext(LinkMode $linkMode = LinkMode::NewWindow): EmbeddingContext {
  return new EmbeddingContext(
    'https://quelle.example/2026/10/beitrag/',
    'quelle.example',
    'eigene.example',
    $linkMode,
    '#kornsw-ra-1',
    'kra-1-'
  );
}

/**
 * Sanitizes HTML with the real pipeline classes.
 */
function sanitizeForTest(string $html, LinkMode $linkMode = LinkMode::NewWindow): string {
  $urlResolver = new UrlResolver();
  $sanitizer = new HtmlSanitizer($urlResolver, new CssSanitizer($urlResolver), new LinkClassifier($urlResolver));
  return $sanitizer->sanitize($html, createTestContext($linkMode));
}

/**
 * Re-parses sanitized output and returns a list of problems that would make it unsafe (empty list = safe).
 *
 * @return list<string>
 */
function findSecurityProblems(string $sanitizedHtml): array {
  $problems = [];
  $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>', LIBXML_NOERROR, 'UTF-8');
  $document->body->innerHTML = $sanitizedHtml;

  $forbiddenElements = ['script', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'base', 'meta', 'link',
    'svg', 'math', 'template', 'noscript', 'frame', 'frameset', 'applet', 'canvas', 'dialog'];

  foreach ($document->body->querySelectorAll('*') as $element) {
    $name = strtolower($element->localName);
    if (in_array($name, $forbiddenElements, true)) {
      $problems[] = 'forbidden element <' . $name . '>';
    }
    if ($name === 'iframe') {
      $src = (string)$element->getAttribute('src');
      if (!str_starts_with($src, 'https://www.youtube.com/') && !str_starts_with($src, 'https://player.vimeo.com/')) {
        $problems[] = 'iframe to ' . $src;
      }
      if (!$element->hasAttribute('sandbox')) {
        $problems[] = 'iframe without sandbox';
      }
    }
    foreach ($element->getAttributeNames() as $attributeName) {
      $value = (string)$element->getAttribute($attributeName);
      $lowerName = strtolower($attributeName);
      if (str_starts_with($lowerName, 'on')) {
        $problems[] = 'event handler ' . $attributeName;
      }
      if (in_array($lowerName, ['srcdoc', 'formaction', 'action', 'xlink:href', 'name'], true)) {
        $problems[] = 'forbidden attribute ' . $attributeName;
      }
      if (in_array($lowerName, ['href', 'src', 'poster', 'cite'], true)) {
        $isAllowedUrl = str_starts_with($value, 'https://') || str_starts_with($value, '#')
          || str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:')
          || preg_match('#^data:image/(png|gif|jpeg|webp|avif);base64,#', $value) === 1;
        if (!$isAllowedUrl) {
          $problems[] = 'unsafe URL in ' . $attributeName . ': ' . $value;
        }
      }
      if ($lowerName === 'srcset' && preg_match('/javascript:|data:/i', $value) === 1) {
        $problems[] = 'unsafe srcset: ' . $value;
      }
      if ($lowerName === 'style') {
        $problems = array_merge($problems, findCssProblems($value));
      }
      if ($lowerName === 'id' && !str_starts_with($value, 'kra-1-')) {
        $problems[] = 'unprefixed id ' . $value;
      }
      if ($lowerName === 'target' && $value === '_blank' && $element->getAttribute('rel') !== 'noopener noreferrer') {
        $problems[] = 'target=_blank without noopener';
      }
    }
    if ($name === 'style') {
      $problems = array_merge($problems, findCssProblems((string)$element->textContent));
      foreach (preg_split('/}/', (string)$element->textContent) as $rule) {
        $selector = trim(explode('{', $rule)[0]);
        if ($selector !== '' && !str_starts_with($selector, '@') && !str_starts_with($selector, '#kornsw-ra-1')
          && preg_match('/^(from|to|\d)/', $selector) !== 1) {
          $problems[] = 'unscoped CSS rule: ' . $selector;
        }
      }
    }
  }
  return $problems;
}

/**
 * Returns problems found in sanitized CSS.
 *
 * @return list<string>
 */
function findCssProblems(string $css): array {
  $problems = [];
  if (preg_match('/expression\s*\(|javascript:|vbscript:|@import|behavior|-moz-binding|\\\\|<\//i', $css) === 1) {
    $problems[] = 'dangerous CSS: ' . $css;
  }
  if (preg_match('/position\s*:\s*(fixed|sticky)/i', $css) === 1) {
    $problems[] = 'position fixed/sticky: ' . $css;
  }
  if (preg_match_all('/url\("([^"]*)"\)/i', $css, $matches) > 0) {
    foreach ($matches[1] as $url) {
      $isAllowed = str_starts_with($url, 'https://quelle.example/') || str_starts_with($url, 'https://eigene.example/')
        || str_starts_with($url, 'data:image/');
      if (!$isAllowed) {
        $problems[] = 'CSS url to foreign host: ' . $url;
      }
    }
  }
  return $problems;
}
