# Epic: Einbettungs-Pipeline

Status: **Konzept beschlossen** (22.10.2026), siehe [Entscheidung](../decisions/decision-einbettung-ueber-umschreiben-und-vertrauensstufen.md). Sicherheitsvorgaben: [security.md](../security.md).

## Ziel

Shortcodes, Galerien und Skripte aus Quellbeiträgen funktionieren eingebettet so weit wie möglich weiter. Die Quelle braucht dafür kein Plugin, und es sind keine Infrastruktur-Freigaben wie CORS nötig. Bei normalen Shortcodes soll man danach Ruhe haben. Das ist der eigentliche Mehrwert des Plugins gegenüber anderen Lösungen. 100 % werden bei hochkomplexen Plugins nicht erreichbar sein, das ist bewusst akzeptiert.

## Ausgangslage (geprüft am 22.10.2026)

- Die REST API liefert `content.rendered`: **Die Quelle hat die Shortcodes bereits ausgeführt.** Den Rohinhalt (`content.raw`) gibt es nur mit Bearbeitungsrechten auf der Quelle.
- Shortcodes lokal noch einmal auszuführen scheidet aus: Plugins wie KornSW AutoGallery lesen ihre Daten (Bilder) vom Dateisystem der Quelle.
- AutoGallery liefert Styles und Lightbox-Skript inline mit. Die Galerie selbst braucht das Skript nicht: Jede Kachel ist ein normaler Link auf das Bild, das Layout steckt in `style`-Attributen.
- Skripte, die Plugins über den Seitenkopf laden (`wp_enqueue_*`), und ihre Konfigurationsobjekte (`wp_localize_script`) fehlen in `content.rendered`.
- WordPress erzeugt URLs fast immer **absolut**. Skript-Dateien bekommen ihre URLs fast immer über Konfigurationswerte (`ajaxurl`, `wpApiSettings.root`, `wp.apiFetch`-Root, Webpack-`publicPath`).
- Inhalte werden für einen anonymen Besucher gerendert.

## Was an Skripten vorkommen kann

| Art | Beispiel | Fundort |
| --- | --- | --- |
| Inline-Skripte im Inhalt | AutoGallery-Lightbox | `content.rendered` |
| Konfigurations-Skripte | `var x = {"ajaxurl":"https://quelle.de/…","nonce":"…"}` | Kopf/Fuß der Originalseite |
| Skript-Dateien | `<script src="…/wp-content/plugins/x/front.js" id="x-js">` | Kopf/Fuß der Originalseite |
| Nachladende Skripte | Webpack-Chunks, `import()` | in Skript-Dateien |
| Datenaufrufe | `admin-ajax.php`, `/wp-json/…` | zur Laufzeit |
| Fremddienste | YouTube, Karten, Analytics | externe Hosts |
| Block-Skripte neuer Art | Interactivity API mit ES-Modulen und Import-Maps | Kopf/Fuß |

## Die Pipeline

1. **Einsammeln:** `content.rendered` holen, dazu die Originalseite des Beitrags. Aus ihr werden die Skripte und Styles von Plugins und Core übernommen, erkennbar an `id="<handle>-js"` bzw. `-css` und am Pfad `wp-content/plugins/…`. Theme-Assets bleiben draußen. Dabei werden die REST-Routen und AJAX-Aktionen gelernt, die die Quelle verwendet.
2. **Abgleichen:** Core-Bibliotheken wie `jquery` oder `wp-*` werden lokal geladen statt von der Quelle.
3. **Reinigen:** HTML und CSS werden nach den Positivlisten aus [security.md](../security.md) gereinigt, je nach Vertrauensstufe mit oder ohne Skripte.
4. **Umschreiben nach URL-Klassen:**
   - Statische Dateien (`wp-content/`, `wp-includes/`, Uploads) zeigen absolut auf die Quelle und laden direkt von dort, ohne CORS-Problem.
   - Datenendpunkte (`admin-ajax.php`, `/wp-json/`) sowie Dateien, die CORS brauchen (ES-Module, Webfonts), gehen über die Unterroute der Quelle: `/<route>/<alias>/…`.
   - Hostnamen werden in allen Schreibweisen ersetzt: `https://quelle.de`, `//quelle.de`, `https:\/\/quelle.de` (JSON), `https%3A%2F%2Fquelle.de` (URL-kodiert). Der Host wird dabei durch Host **plus Unterroute** ersetzt, damit auch zusammengesetzte URLs richtig landen.
   - Relative URLs im HTML werden gegen die Quelle aufgelöst.
   - Fremddienste bleiben unverändert, sofern freigegeben.
5. **Ausführen nach Vertrauensstufe:**
   - *Nicht vertrauenswürdig* (Standard): keine fremden Skripte; Interaktion durch eigenen Code des Plugins, zuerst eine Lightbox für Bild-Links und bekannte Galerie-Markups (z. B. `.tkag-tile`).
   - *Vertrauenswürdig* (eigene Seiten): Die umgeschriebenen Skripte laufen, aber nie für Benutzer mit Bearbeitungsrechten (siehe [security.md](../security.md)).

## Bausteine der Stufe „vertrauenswürdig“

- **Weiterleitungs-Route** pro Quelle (`/<route>/<alias>/…`): eng begrenzt auf bekannte WordPress-Pfadklassen, ohne Cookies und ohne hinterlegte Zugangsdaten. Details und Grenzen in [security.md](../security.md), Abschnitt 4.
- **Eigener Gültigkeitsbereich pro Quelle:** Skript-Dateien der Quelle werden in eine Funktion gehüllt, die `window`, `self`, `globalThis`, `wp`, `jQuery` und `$` überdeckt. Dahinter stehen pro Quelle eigene Instanzen: ein `window`-Proxy sowie eigenes `wp` und `jQuery`. Core-Bibliotheken mit Zustand (`wp.apiFetch`, `wp.hooks`, `wp.ajax`, `jQuery`) werden in diesem Rahmen ein zweites Mal geladen und haben damit eigene Root-URL, eigene Nonce und eigene Einstellungen. Oberste `var`-Deklarationen werden auf den `window`-Proxy umgeleitet, damit Skripte untereinander weiter Daten austauschen können. Das dient der Konfliktvermeidung, ist **keine** Sicherheitsgrenze.
- **Shim für hart codierte Pfade:** Hängt sich in `fetch`, `XMLHttpRequest`, `jQuery.ajax` und `sendBeacon`. Kommt ein relativer Aufruf laut Call-Stack aus einem Quell-Skript, leitet es ihn auf die Unterroute der Quelle um. Inline-Skripte werden dafür mit `//# sourceURL=…` markiert.
- **Serverseitiger Rückfall:** REST-Routen, AJAX-Aktionen und Dateien, die es lokal nicht gibt, aber bei einer vertrauenswürdigen Quelle beim Einsammeln gesehen wurden, werden zur Quelle weitergeleitet. Lokal Vorhandenes wird nie übersteuert.

## Bekannte Grenzen

- `eval` und `new Function` laufen im echten globalen Rahmen und entkommen dem eigenen Gültigkeitsbereich.
- ES-Module und Import-Maps (Interactivity API) lassen sich nicht umhüllen. Das ist der schwierigste Fall und kommt zuletzt.
- Dasselbe Plugin auf beiden Seiten mit hart codiertem relativen Pfad, den das Shim nicht zuordnen kann, landet beim lokalen Plugin.
- Lokal vorhandene Dateien unter demselben Pfad liefert der Webserver selbst aus.
- Alles läuft auf der Quelle als anonymer Besucher. Nonces in zwischengespeicherten Konfigurationen verfallen nach 12 bis 24 Stunden, der Zwischenspeicher muss deshalb kürzer sein.

## Ausbaustufen

1. Einsammeln von `content.rendered`, Reinigen (Standardstufe), Umschreiben der statischen URLs, eigene Lightbox.
2. Einsammeln der Assets der Originalseite, Abgleichen der Core-Bibliotheken, Stufe „vertrauenswürdig“ mit Weiterleitungs-Route.
3. Eigener Gültigkeitsbereich pro Quelle und Shim.
4. Serverseitiger Rückfall für relative Aufrufe.
5. Sonderfälle wie die Interactivity API, falls nötig.

## Offene Fragen

1. Welche Shortcodes bzw. Plugins sind in den Quellbeiträgen tatsächlich im Einsatz? Daraus ergibt sich, wie weit die Stufen 2 bis 4 nötig sind.
2. Gibt es Quellbeiträge mit geschützten Inhalten, die eingebettet anders erscheinen sollen als für anonyme Besucher?
3. Name der Unterroute, z. B. `/remote-articles/<alias>/…`.
