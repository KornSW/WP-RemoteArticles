<?php

declare(strict_types=1);

namespace KornSW\RemoteArticles\Embedding;

/**
 * How links that point to pages of the source site are treated.
 */
enum LinkMode: string {

  /** The link is removed, its text stays as plain text. */
  case Remove = 'remove';

  /** The link opens the original page in a new window. */
  case NewWindow = 'new_window';

  /** The link stays unchanged. */
  case Keep = 'keep';

  /**
   * Returns the German label shown in the admin UI.
   */
  public function getLabel(): string {
    return match ($this) {
      LinkMode::Remove => 'Links entfernen',
      LinkMode::NewWindow => 'Im neuen Fenster auf der Originalseite öffnen',
      LinkMode::Keep => 'Unverändert lassen',
    };
  }

}
