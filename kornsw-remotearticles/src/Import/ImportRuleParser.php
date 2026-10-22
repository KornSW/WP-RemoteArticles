<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * Parses the import rules of a source. One rule per line:
 *
 *   RemoteKat1, RemoteKat2 > LokaleKat
 *   rezepte, #backen > Kochen, #familie
 *
 * Left: categories (slug or ID) and tags ("#" + slug or ID) of the source. Right: names of local categories and tags.
 * Empty lines and lines starting with "//" are ignored.
 */
final class ImportRuleParser {

  private const REMOTE_VALUE_PATTERN = '/^[\p{L}\p{N}%_-]{1,200}$/u';
  private const LOCAL_VALUE_PATTERN = '/^[\p{L}\p{N} _.&\'-]{1,100}$/u';
  private const MAXIMUM_RULES = 50;

  /**
   * Parses all rules.
   *
   * @return list<ImportRule>
   * @throws \InvalidArgumentException With a German message naming the faulty line.
   */
  public function parse(string $rulesText): array {
    $rules = [];
    $lines = preg_split('/\R/u', $rulesText);
    if ($lines === false) {
      throw new \InvalidArgumentException('Die Import-Regeln konnten nicht gelesen werden.');
    }

    foreach ($lines as $index => $line) {
      $trimmedLine = trim($line);
      if ($trimmedLine === '' || str_starts_with($trimmedLine, '//')) {
        continue;
      }
      $rules[] = $this->parseLine($trimmedLine, $index + 1);
      if (count($rules) > self::MAXIMUM_RULES) {
        throw new \InvalidArgumentException(sprintf('Es sind höchstens %d Import-Regeln erlaubt.', self::MAXIMUM_RULES));
      }
    }
    return $rules;
  }

  /**
   * Parses one rule line.
   */
  private function parseLine(string $line, int $lineNumber): ImportRule {
    $sides = explode('>', $line);
    if (count($sides) !== 2) {
      throw new \InvalidArgumentException(sprintf('Import-Regel in Zeile %d: genau ein „>“ zwischen Quelle und Ziel erwartet, z. B. „rezepte > Kochen“.', $lineNumber));
    }

    $remoteTerms = $this->parseTermList($sides[0], self::REMOTE_VALUE_PATTERN, $lineNumber, 'Quelle');
    $localTerms = $this->parseTermList($sides[1], self::LOCAL_VALUE_PATTERN, $lineNumber, 'Ziel');
    return new ImportRule($remoteTerms, $localTerms);
  }

  /**
   * Parses a comma-separated list of terms.
   *
   * @return list<TermReference>
   */
  private function parseTermList(string $list, string $valuePattern, int $lineNumber, string $sideLabel): array {
    $terms = [];
    foreach (explode(',', $list) as $item) {
      $trimmedItem = trim($item);
      if ($trimmedItem === '') {
        continue;
      }

      $taxonomy = TermReference::TAXONOMY_CATEGORY;
      $value = $trimmedItem;
      if (str_starts_with($trimmedItem, '#')) {
        $taxonomy = TermReference::TAXONOMY_TAG;
        $value = trim(substr($trimmedItem, 1));
      }

      if (preg_match($valuePattern, $value) !== 1) {
        throw new \InvalidArgumentException(sprintf('Import-Regel in Zeile %d: „%s“ ist als %s nicht zulässig.', $lineNumber, $trimmedItem, $sideLabel));
      }
      $terms[] = new TermReference($taxonomy, $value);
    }

    if (count($terms) === 0) {
      throw new \InvalidArgumentException(sprintf('Import-Regel in Zeile %d: Auf der Seite „%s“ fehlt eine Kategorie oder ein Schlagwort.', $lineNumber, $sideLabel));
    }
    return $terms;
  }

}
