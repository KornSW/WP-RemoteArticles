<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

/**
 * Replaces galleries in already sanitized HTML by placeholders that the plugin's gallery script fills asynchronously:
 *
 * - Share-one-Drive gallery modules (other modules of that plugin, e.g. file lists, stay hidden without script);
 * - NextGEN placeholders "ngg_shortcode_N_placeholder" (NextGEN never inserts its galleries into REST content).
 */
final class GalleryPlaceholderInserter {

  public const PLACEHOLDER_ATTRIBUTE = 'data-kornsw-ra-gallery';

  /** Marker for NextGEN placeholders; attributes "data-kornsw-ra-*" never survive the sanitizer, so it cannot be forged. */
  private const NEXTGEN_MARKER_ATTRIBUTE = 'data-kornsw-ra-nextgen';
  private const NEXTGEN_PATTERN = '#(?:<p>\s*)?ngg_shortcode_\d+_placeholder(?:\s*</p>)?#';
  private const NEXTGEN_PLACEHOLDER_HEIGHT = 160;

  /**
   * Replaces all supported galleries.
   *
   * @param callable(ShareOneDriveModule): string $buildShareOneDriveUrl Returns the signed endpoint URL for a module.
   * @param callable(int): string $buildNextGenUrl Returns the signed endpoint URL for the n-th NextGEN gallery of the post.
   * @return array{html: string, count: int}
   */
  public function insert(string $sanitizedHtml, callable $buildShareOneDriveUrl, callable $buildNextGenUrl): array {
    $hasShareOneDrive = str_contains($sanitizedHtml, 'ShareoneDrive');
    $hasNextGen = str_contains($sanitizedHtml, 'ngg_shortcode_');
    if (!$hasShareOneDrive && !$hasNextGen) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }

    $html = $sanitizedHtml;
    if ($hasNextGen) {
      $html = $this->markNextGenPlaceholders($html);
    }

    $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>', LIBXML_NOERROR, 'UTF-8');
    $body = $document->body;
    if ($body === null) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }
    $body->innerHTML = $html;

    $count = 0;
    foreach ($body->querySelectorAll('.wpcp-module.ShareoneDrive[data-list="gallery"]') as $moduleElement) {
      $module = ShareOneDriveModule::fromAttributes($this->readDataAttributes($moduleElement));
      if ($module === null) {
        continue;
      }
      $target = $moduleElement->closest('.wpcp-container');
      if ($target === null) {
        $target = $moduleElement;
      }
      $target->replaceWith($this->createPlaceholder($document, $buildShareOneDriveUrl($module), $module->targetHeight));
      $count++;
    }

    foreach ($body->querySelectorAll('[' . self::NEXTGEN_MARKER_ATTRIBUTE . ']') as $marker) {
      $index = (int)$marker->getAttribute(self::NEXTGEN_MARKER_ATTRIBUTE);
      $marker->replaceWith($this->createPlaceholder($document, $buildNextGenUrl($index), self::NEXTGEN_PLACEHOLDER_HEIGHT));
      $count++;
    }

    if ($count === 0) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }
    return ['html' => $body->innerHTML, 'count' => $count];
  }

  /**
   * Replaces the NextGEN placeholder texts by marker elements, numbered in document order (= order of the galleries on
   * the original page).
   */
  private function markNextGenPlaceholders(string $html): string {
    $index = 0;
    $result = preg_replace_callback(
      self::NEXTGEN_PATTERN,
      function () use (&$index): string {
        $marker = '<div ' . self::NEXTGEN_MARKER_ATTRIBUTE . '="' . $index . '"></div>';
        $index++;
        return $marker;
      },
      $html
    );
    if (!is_string($result)) {
      return $html;
    }
    return $result;
  }

  /**
   * Creates a placeholder element with loading indicator.
   */
  private function createPlaceholder(\Dom\HTMLDocument $document, string $endpointUrl, int $height): \Dom\Element {
    $placeholder = $document->createElement('div');
    $placeholder->setAttribute('class', 'kornsw-ra-gallery kornsw-ra-gallery-loading');
    $placeholder->setAttribute(self::PLACEHOLDER_ATTRIBUTE, $endpointUrl);
    $placeholder->setAttribute('style', '--kornsw-ra-gallery-height:' . $height . 'px');
    $placeholder->setAttribute('aria-busy', 'true');
    $label = $document->createElement('span');
    $label->setAttribute('class', 'kornsw-ra-gallery-loading-label');
    $label->textContent = 'Galerie wird geladen …';
    $placeholder->appendChild($label);
    return $placeholder;
  }

  /**
   * @return array<string, string>
   */
  private function readDataAttributes(\Dom\Element $element): array {
    $attributes = [];
    foreach ($element->getAttributeNames() as $name) {
      if (str_starts_with($name, 'data-')) {
        $attributes[$name] = (string)$element->getAttribute($name);
      }
    }
    return $attributes;
  }

}
