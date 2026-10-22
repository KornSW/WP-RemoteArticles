<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Galleries;

/**
 * Replaces Share-one-Drive gallery modules in already sanitized HTML by placeholders that the plugin's gallery script
 * fills asynchronously. Other modules of the plugin (file lists, players) stay as they are (hidden without script).
 */
final class GalleryPlaceholderInserter {

  public const PLACEHOLDER_ATTRIBUTE = 'data-kornsw-ra-gallery';

  /**
   * Replaces all gallery modules.
   *
   * @param callable(ShareOneDriveModule): string $buildEndpointUrl Returns the (signed) URL the script loads the gallery from.
   * @return array{html: string, count: int}
   */
  public function insert(string $sanitizedHtml, callable $buildEndpointUrl): array {
    if (!str_contains($sanitizedHtml, 'ShareoneDrive')) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }

    $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>', LIBXML_NOERROR, 'UTF-8');
    $body = $document->body;
    if ($body === null) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }
    $body->innerHTML = $sanitizedHtml;

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

      $placeholder = $document->createElement('div');
      $placeholder->setAttribute('class', 'kornsw-ra-gallery kornsw-ra-gallery-loading');
      $placeholder->setAttribute(self::PLACEHOLDER_ATTRIBUTE, $buildEndpointUrl($module));
      $placeholder->setAttribute('style', '--kornsw-ra-gallery-height:' . $module->targetHeight . 'px');
      $target->replaceWith($placeholder);
      $count++;
    }

    if ($count === 0) {
      return ['html' => $sanitizedHtml, 'count' => 0];
    }
    return ['html' => $body->innerHTML, 'count' => $count];
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
