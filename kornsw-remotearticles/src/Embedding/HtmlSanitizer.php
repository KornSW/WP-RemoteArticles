<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * Turns the rendered HTML of a foreign post into HTML that is safe to embed (trust level "not trusted", see doc/security.md).
 *
 * Works on a real HTML5 parser (\Dom\HTMLDocument) with allow lists:
 * - elements outside the allow list are removed (dangerous ones with their content) or unwrapped (harmless ones);
 * - attributes outside the allow list are removed, URLs are resolved and validated, styles are sanitized;
 * - IDs are prefixed (no collisions, no DOM clobbering), style sheets are scoped to the wrapper;
 * - links to pages of the source follow the link mode; links to media are marked for the plugin's own lightbox.
 */
final class HtmlSanitizer {

  /** Marker attribute evaluated by the plugin's lightbox script. */
  public const MEDIA_ATTRIBUTE = 'data-kornsw-ra-media';

  private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

  /** Elements removed together with their content. */
  private const REMOVED_ELEMENTS = [
    'script' => true, 'noscript' => true, 'template' => true, 'object' => true, 'embed' => true,
    'applet' => true, 'param' => true, 'form' => true, 'input' => true, 'button' => true,
    'select' => true, 'option' => true, 'optgroup' => true, 'textarea' => true, 'datalist' => true,
    'output' => true, 'base' => true, 'meta' => true, 'link' => true, 'title' => true,
    'head' => true, 'frame' => true, 'frameset' => true, 'svg' => true, 'math' => true,
    'canvas' => true, 'dialog' => true, 'slot' => true, 'portal' => true, 'fencedframe' => true,
    'map' => true, 'area' => true, 'xmp' => true, 'plaintext' => true, 'noembed' => true, 'noframes' => true,
  ];

  /** Elements kept (with sanitized attributes). All others are unwrapped. */
  private const ALLOWED_ELEMENTS = [
    'a' => true, 'abbr' => true, 'address' => true, 'article' => true, 'aside' => true, 'audio' => true,
    'b' => true, 'bdi' => true, 'bdo' => true, 'blockquote' => true, 'br' => true, 'caption' => true,
    'cite' => true, 'code' => true, 'col' => true, 'colgroup' => true, 'dd' => true, 'del' => true,
    'details' => true, 'dfn' => true, 'div' => true, 'dl' => true, 'dt' => true, 'em' => true,
    'figcaption' => true, 'figure' => true, 'footer' => true, 'h1' => true, 'h2' => true, 'h3' => true,
    'h4' => true, 'h5' => true, 'h6' => true, 'header' => true, 'hr' => true, 'i' => true, 'img' => true,
    'ins' => true, 'kbd' => true, 'li' => true, 'mark' => true, 'ol' => true, 'p' => true,
    'picture' => true, 'pre' => true, 'q' => true, 'rp' => true, 'rt' => true, 'ruby' => true, 's' => true,
    'samp' => true, 'section' => true, 'small' => true, 'source' => true, 'span' => true, 'strong' => true,
    'sub' => true, 'summary' => true, 'sup' => true, 'table' => true, 'tbody' => true, 'td' => true,
    'tfoot' => true, 'th' => true, 'thead' => true, 'time' => true, 'tr' => true, 'track' => true,
    'u' => true, 'ul' => true, 'var' => true, 'video' => true, 'wbr' => true,
  ];

  private const GLOBAL_ATTRIBUTES = [
    'class' => true, 'id' => true, 'title' => true, 'lang' => true, 'dir' => true, 'style' => true,
    'role' => true, 'translate' => true,
  ];

  private const ELEMENT_ATTRIBUTES = [
    'a' => ['href' => true, 'target' => true, 'hreflang' => true],
    'img' => ['src' => true, 'srcset' => true, 'sizes' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true],
    'video' => ['src' => true, 'poster' => true, 'width' => true, 'height' => true, 'controls' => true, 'autoplay' => true, 'muted' => true, 'loop' => true, 'playsinline' => true, 'preload' => true],
    'audio' => ['src' => true, 'controls' => true, 'autoplay' => true, 'muted' => true, 'loop' => true, 'preload' => true],
    'source' => ['src' => true, 'srcset' => true, 'sizes' => true, 'type' => true, 'media' => true],
    'track' => ['src' => true, 'kind' => true, 'srclang' => true, 'label' => true, 'default' => true],
    'td' => ['colspan' => true, 'rowspan' => true, 'headers' => true],
    'th' => ['colspan' => true, 'rowspan' => true, 'headers' => true, 'scope' => true, 'abbr' => true],
    'col' => ['span' => true],
    'colgroup' => ['span' => true],
    'ol' => ['start' => true, 'reversed' => true, 'type' => true],
    'li' => ['value' => true],
    'blockquote' => ['cite' => true],
    'q' => ['cite' => true],
    'del' => ['cite' => true, 'datetime' => true],
    'ins' => ['cite' => true, 'datetime' => true],
    'time' => ['datetime' => true],
    'details' => ['open' => true],
  ];

  private const RESOURCE_URL_ATTRIBUTES = ['src' => true, 'poster' => true];

  private const ALLOWED_IFRAME_HOSTS = [
    'youtube.com' => true,
    'youtube-nocookie.com' => true,
    'player.vimeo.com' => true,
  ];

  public function __construct(
    private readonly UrlResolver $urlResolver,
    private readonly CssSanitizer $cssSanitizer,
    private readonly LinkClassifier $linkClassifier
  ) {
  }

  /**
   * Sanitizes a fragment of foreign HTML.
   *
   * @param string $html Rendered HTML of the source (e.g. content.rendered of the REST API).
   * @return string HTML that is safe to output inside the wrapper element described by the context.
   */
  public function sanitize(string $html, EmbeddingContext $context): string {
    if (trim($html) === '') {
      return '';
    }

    $document = \Dom\HTMLDocument::createFromString(
      '<!DOCTYPE html><html><head></head><body></body></html>',
      LIBXML_NOERROR,
      'UTF-8'
    );
    $container = $document->body;
    if ($container === null) {
      return '';
    }
    // Parsing as a fragment in body context: a "</body>" or "</div>" in the foreign HTML cannot escape the container.
    $container->innerHTML = $html;

    $this->sanitizeChildren($container, $context);
    return $container->innerHTML;
  }

  /**
   * Sanitizes all children of a node. The next sibling is fetched first because the current child may be removed.
   */
  private function sanitizeChildren(\Dom\Node $parent, EmbeddingContext $context): void {
    $child = $parent->firstChild;
    while ($child !== null) {
      $nextSibling = $child->nextSibling;
      $this->sanitizeNode($child, $context);
      $child = $nextSibling;
    }
  }

  /**
   * Sanitizes a single node and its subtree.
   */
  private function sanitizeNode(\Dom\Node $node, EmbeddingContext $context): void {
    if ($node instanceof \Dom\Text) {
      return;
    }
    if (!($node instanceof \Dom\Element)) {
      // Comments and anything else that is not text or an element.
      $node->remove();
      return;
    }

    if ($node->namespaceURI !== self::HTML_NAMESPACE) {
      $node->remove();
      return;
    }

    $elementName = strtolower($node->localName);

    if (isset(self::REMOVED_ELEMENTS[$elementName])) {
      $node->remove();
      return;
    }
    if ($elementName === 'style') {
      $this->sanitizeStyleElement($node, $context);
      return;
    }
    if ($elementName === 'iframe') {
      $this->sanitizeIframe($node, $context);
      return;
    }
    if (!isset(self::ALLOWED_ELEMENTS[$elementName])) {
      $this->sanitizeChildren($node, $context);
      $this->unwrap($node);
      return;
    }

    $this->sanitizeAttributes($node, $elementName, $context);
    $this->sanitizeChildren($node, $context);

    if ($elementName === 'a') {
      $this->applyLinkRules($node, $context);
    }
  }

  /**
   * Replaces the content of a style element by the sanitized, scoped style sheet (or removes the element).
   */
  private function sanitizeStyleElement(\Dom\Element $styleElement, EmbeddingContext $context): void {
    $styleSheet = $this->cssSanitizer->sanitizeStyleSheet((string)$styleElement->textContent, $context);
    if ($styleSheet === '') {
      $styleElement->remove();
      return;
    }
    foreach ($styleElement->getAttributeNames() as $attributeName) {
      $styleElement->removeAttribute($attributeName);
    }
    $styleElement->textContent = $styleSheet;
  }

  /**
   * Keeps iframes of well-known video services only, with a fixed, restrictive set of attributes.
   */
  private function sanitizeIframe(\Dom\Element $iframe, EmbeddingContext $context): void {
    $resolvedUrl = $this->urlResolver->resolveResourceUrl((string)$iframe->getAttribute('src'), $context->documentUrl);
    if ($resolvedUrl === null || !isset(self::ALLOWED_IFRAME_HOSTS[$this->urlResolver->getComparableHost($resolvedUrl)])) {
      $iframe->remove();
      return;
    }

    $width = (string)$iframe->getAttribute('width');
    $height = (string)$iframe->getAttribute('height');
    $title = (string)$iframe->getAttribute('title');

    foreach ($iframe->getAttributeNames() as $attributeName) {
      $iframe->removeAttribute($attributeName);
    }
    while ($iframe->firstChild !== null) {
      $iframe->firstChild->remove();
    }

    $iframe->setAttribute('src', $resolvedUrl);
    if (preg_match('/^\d{1,4}%?$/', $width) === 1) {
      $iframe->setAttribute('width', $width);
    }
    if (preg_match('/^\d{1,4}%?$/', $height) === 1) {
      $iframe->setAttribute('height', $height);
    }
    if ($title !== '') {
      $iframe->setAttribute('title', $title);
    }
    $iframe->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups');
    $iframe->setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture');
    $iframe->setAttribute('allowfullscreen', '');
    $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    $iframe->setAttribute('loading', 'lazy');
  }

  /**
   * Removes every attribute outside the allow lists and sanitizes the remaining values.
   */
  private function sanitizeAttributes(\Dom\Element $element, string $elementName, EmbeddingContext $context): void {
    $elementAttributes = self::ELEMENT_ATTRIBUTES[$elementName] ?? [];

    foreach ($element->getAttributeNames() as $attributeName) {
      $name = strtolower($attributeName);
      $value = (string)$element->getAttribute($attributeName);

      $isAllowed = isset(self::GLOBAL_ATTRIBUTES[$name]) || isset($elementAttributes[$name]);
      $isDataOrAria = preg_match('/^(data|aria)-[a-z0-9_.-]+$/', $name) === 1 && !str_starts_with($name, 'data-kornsw-ra');
      if (!$isAllowed && !$isDataOrAria) {
        $element->removeAttribute($attributeName);
        continue;
      }

      $sanitizedValue = $this->sanitizeAttributeValue($name, $value, $elementName, $context);
      if ($sanitizedValue === null) {
        $element->removeAttribute($attributeName);
      } elseif ($sanitizedValue !== $value) {
        $element->setAttribute($attributeName, $sanitizedValue);
      }
    }
  }

  /**
   * Sanitizes one attribute value.
   *
   * @return string|null The sanitized value, or null if the attribute must be removed.
   */
  private function sanitizeAttributeValue(string $name, string $value, string $elementName, EmbeddingContext $context): ?string {
    if ($name === 'style') {
      $declarations = $this->cssSanitizer->sanitizeDeclarations($value, $context);
      if ($declarations === '') {
        return null;
      }
      return $declarations;
    }

    if ($name === 'id') {
      return $this->prefixIdentifier($value, $context);
    }
    if ($name === 'headers') {
      $prefixedIdentifiers = [];
      $identifiers = preg_split('/\s+/', trim($value));
      if ($identifiers === false) {
        return null;
      }
      foreach ($identifiers as $identifier) {
        if ($identifier !== '') {
          $prefixedIdentifiers[] = $this->prefixIdentifier($identifier, $context);
        }
      }
      return implode(' ', $prefixedIdentifiers);
    }

    if ($name === 'href') {
      $resolvedUrl = $this->urlResolver->resolveLinkUrl($value, $context->documentUrl);
      if ($resolvedUrl !== null && str_starts_with($resolvedUrl, '#')) {
        return '#' . $this->prefixIdentifier(substr($resolvedUrl, 1), $context);
      }
      return $resolvedUrl;
    }

    if ($name === 'src' && $elementName === 'img' && $this->urlResolver->isImageDataUrl($value)) {
      return trim($value);
    }
    if (isset(self::RESOURCE_URL_ATTRIBUTES[$name]) || $name === 'cite') {
      return $this->urlResolver->resolveResourceUrl($value, $context->documentUrl);
    }
    if ($name === 'srcset') {
      return $this->sanitizeSrcset($value, $context);
    }

    if ($name === 'target') {
      if (strtolower(trim($value)) === '_blank') {
        return '_blank';
      }
      return null;
    }

    if (str_starts_with($name, 'data-') && preg_match('/javascript:|vbscript:|data:/i', $value) === 1) {
      return null;
    }
    return $value;
  }

  /**
   * Resolves every candidate of a srcset; invalid candidates are dropped.
   */
  private function sanitizeSrcset(string $srcset, EmbeddingContext $context): ?string {
    $candidates = [];
    foreach (explode(',', $srcset) as $candidate) {
      $parts = preg_split('/\s+/', trim($candidate));
      if ($parts === false || $parts[0] === '') {
        continue;
      }
      $resolvedUrl = $this->urlResolver->resolveResourceUrl($parts[0], $context->documentUrl);
      if ($resolvedUrl === null) {
        continue;
      }
      $descriptor = '';
      if (isset($parts[1]) && preg_match('/^\d+(\.\d+)?[wx]$/', $parts[1]) === 1) {
        $descriptor = ' ' . $parts[1];
      }
      $candidates[] = $resolvedUrl . $descriptor;
    }
    if (count($candidates) === 0) {
      return null;
    }
    return implode(', ', $candidates);
  }

  /**
   * Applies the link mode to links pointing to pages of the source and marks links to images and videos for the lightbox.
   */
  private function applyLinkRules(\Dom\Element $anchor, EmbeddingContext $context): void {
    $href = (string)$anchor->getAttribute('href');
    if ($href === '') {
      $this->unwrap($anchor);
      return;
    }

    $targetKind = $this->linkClassifier->classify($href, $context);

    if ($targetKind === LinkClassifier::TARGET_SOURCE_PAGE) {
      if ($context->linkMode === LinkMode::Remove) {
        $this->unwrap($anchor);
        return;
      }
      if ($context->linkMode === LinkMode::NewWindow) {
        $anchor->setAttribute('target', '_blank');
      }
    }

    if ($targetKind === LinkClassifier::TARGET_SOURCE_MEDIA) {
      $mediaType = $this->linkClassifier->getMediaType($href);
      if ($mediaType !== '') {
        $anchor->setAttribute(self::MEDIA_ATTRIBUTE, $mediaType);
      }
    }

    if ($anchor->getAttribute('target') === '_blank') {
      $anchor->setAttribute('rel', 'noopener noreferrer');
    }
  }

  /**
   * Prefixes an identifier so that it cannot collide with IDs of the own page.
   */
  private function prefixIdentifier(string $identifier, EmbeddingContext $context): string {
    return $context->idPrefix . trim($identifier);
  }

  /**
   * Replaces an element by its children.
   */
  private function unwrap(\Dom\Element $element): void {
    $parent = $element->parentNode;
    if ($parent === null) {
      return;
    }
    while ($element->firstChild !== null) {
      $parent->insertBefore($element->firstChild, $element);
    }
    $element->remove();
  }

}
