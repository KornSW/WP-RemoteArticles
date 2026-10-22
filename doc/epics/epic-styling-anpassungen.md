# Epic: Styling-Anpassungen

## Ziel

Die eingebetteten Beiträge passen optisch zur eigenen Seite, ohne dass das Quell-HTML von Hand angepasst werden muss.

## Idee

- Das Plugin umschließt die Ausgabe mit eigenen, eindeutig präfixierten CSS-Klassen (Liste, Beitrag, Titel, Meta, Inhalt). So kann das Theme oder zusätzliches CSS gezielt eingreifen.
- Ein schlichtes Standard-Stylesheet sorgt für eine brauchbare Grunddarstellung. Es ist abschaltbar.
- Optional ein Feld für eigenes CSS je Quelle oder je Darstellungsvorlage im Admin-Bereich.
- Störende Elemente aus dem Quell-HTML lassen sich per CSS-Selektor ausblenden oder entfernen, z. B. Teilen-Buttons oder „Weiterlesen“-Blöcke der Quelle.

## Offene Fragen

1. Reicht CSS, oder braucht es unterschiedliche HTML-Vorlagen, z. B. Karten, Liste oder Volltext?
2. Sollen Styles der Quelle, etwa die Block-Styles des Quell-Themes, bewusst mitgeladen werden, oder soll alles im Stil der eigenen Seite erscheinen (Vorschlag)?
3. Welche konkreten Anpassungen sind heute schon absehbar?
