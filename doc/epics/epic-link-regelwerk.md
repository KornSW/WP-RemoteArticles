# Epic: Link-Regelwerk

## Ziel

Links in eingebetteten Beiträgen, die auf die Quellseite zeigen, werden nach einem einstellbaren Regelwerk behandelt. So landen Besucher nicht ungewollt auf der fremden Seite, oder sie landen dort bewusst und erkennbar.

## Idee

Je Quelle (mit Überschreibung je Shortcode) gilt ein Modus für Links auf die Quelldomain:

| Modus | Verhalten |
| --- | --- |
| Entfernen | Der Link wird entfernt, der Linktext bleibt als normaler Text stehen. |
| Neues Fenster | Der Link öffnet die Originalseite in einem neuen Fenster (`target="_blank"`, `rel="noopener"`). |
| Unverändert | Der Link bleibt, wie er ist. |
| Lokal nachbilden | Später, siehe Epic [Links lokal nachbilden](epic-links-lokal-nachbilden.md). |

- Erkennung über die Domain der Quelle. Relative Links im Quell-HTML werden zuvor zu absoluten Links auf die Quelle aufgelöst.
- Links auf **fremde dritte Seiten** sind nicht betroffen, sofern nicht anders eingestellt.
- **Medien-Links** (Bilder, Downloads, Galerie-Bilder der Lightbox) sind keine Navigations-Links. Sie dürfen nicht entfernt werden, sonst brechen Galerien. Die Regeln gelten nur für Links auf Seiten und Beiträge.

## Offene Fragen

1. Soll es neben dem globalen Modus feinere Regeln geben, z. B. nach URL-Muster (`/kategorie/...` entfernen, `/shop/...` im neuen Fenster)?
2. Sollen Links auf andere Beiträge derselben eingebetteten Kategorie anders behandelt werden, etwa als Sprung zum eingebetteten Beitrag auf derselben Seite?
3. Wie soll der Titel eines Beitrags behandelt werden: verlinkt auf das Original, oder gar nicht verlinkt?
