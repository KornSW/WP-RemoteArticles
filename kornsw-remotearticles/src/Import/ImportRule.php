<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Import;

/**
 * One import rule: posts in any of the remote terms are imported into all of the local terms.
 */
final class ImportRule {

  /**
   * @param list<TermReference> $remoteTerms Categories / tags of the source (slug or ID).
   * @param list<TermReference> $localTerms Categories / tags of the own site (name; created on demand).
   */
  public function __construct(
    public readonly array $remoteTerms,
    public readonly array $localTerms
  ) {
  }

}
