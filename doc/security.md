# Sicherheit

Stand: 22.10.2026. Umgesetzt: Abschnitte 1, 2, 6 und 7 sowie die Standardstufe „nicht vertrauenswürdig“, abgesichert durch `tests/run-tests.php`. Die Abschnitte 3 bis 5 betreffen die Stufe „vertrauenswürdig“, die noch nicht gebaut ist. Dieses Dokument ist verbindlich. Jede Implementierung der [Einbettungs-Pipeline](epics/epic-einbettungs-pipeline.md) muss die hier festgelegten Schutzmaßnahmen umsetzen. Wird eine davon aufgeweicht, muss dieses Dokument vorher angepasst und die Änderung begründet werden.

## Worum es geht

Das Plugin holt fremde Inhalte auf die eigene Seite und kann in einer ausdrücklich freigeschalteten Stufe sogar fremdes JavaScript ausführen. Außerdem leitet es Aufrufe an die Quelle weiter. Das berührt genau die Stellen, an denen Webseiten typischerweise angegriffen werden:

- fremder Code im eigenen Browser-Kontext (Cross-Site-Scripting);
- ein Server, der im Auftrag anderer Anfragen verschickt (offenes Relay, Server-Side Request Forgery);
- zwischengespeicherte Inhalte, die man vergiften kann.

Dieses Dokument beschreibt, wer angreifen könnte, wo und mit welchem Ziel, welche Gegenmaßnahme greift und welches Restrisiko bewusst bleibt.

## Schutzgüter

1. **Die Sitzungen angemeldeter Benutzer** der eigenen Seite, vor allem von Administratoren und Redakteuren.
2. **Der eigene Server:** keine Anfragen an interne Systeme, keine Überlastung.
3. **Die Zugangsdaten zu den Quellen,** die im Admin-Bereich hinterlegt sind.
4. **Die Quellseiten:** Sie dürfen nicht über unser Plugin missbraucht werden.
5. **Die Integrität der Inhalte:** Kein Dritter darf manipulierte Inhalte in den Zwischenspeicher bringen.
6. **Die Privatsphäre der Besucher.**

## Mögliche Angreifer

| Angreifer | Möglichkeiten |
| --- | --- |
| Anonymer Besucher | Ruft beliebige URLs der eigenen Seite auf, auch die Weiterleitungs-Route. |
| Autor/Mitarbeiter der eigenen Seite | Darf Shortcodes mit beliebigen Attributen in Beiträge schreiben. |
| Kompromittierte oder bösartige Quellseite | Liefert beliebiges HTML, CSS, JavaScript und beliebige API-Antworten. |
| Angreifer im Netzwerk zwischen eigener Seite und Quelle | Könnte Antworten der Quelle verändern. |
| Administrator der eigenen Seite | Wird als vertrauenswürdig angenommen. Er kann WordPress ohnehin vollständig steuern. |

## Grundprinzipien

1. **Standard ist sicher.** Jede neue Quelle startet als *nicht vertrauenswürdig*. In dieser Stufe läuft kein einziges fremdes Skript, und die Weiterleitungs-Route ist für diese Quelle abgeschaltet.
2. **Vertrauen ist eine ausdrückliche Entscheidung eines Administrators,** getroffen pro Quelle, mit Warnhinweis und jederzeit widerrufbar.
3. **Kein Seiteninhalt bestimmt Ziele.** URLs, Zugangsdaten und Vertrauensstufen stehen nur in der Admin-Konfiguration, nie im Shortcode.
4. **Positivlisten statt Negativlisten.** Erlaubt ist nur, was ausdrücklich vorgesehen ist: Elemente, Attribute, Pfade, Methoden, Header, Dateitypen.
5. **Die Hilfsmittel zur Konfliktvermeidung sind keine Sicherheitsgrenzen.** Gemeint sind Shim, eigener Gültigkeitsbereich pro Quelle und Unterroute. Die Sicherheit beruht allein auf den hier beschriebenen Maßnahmen.

## Die Vertrauensstufen

| | Nicht vertrauenswürdig (Standard) | Vertrauenswürdig |
| --- | --- | --- |
| HTML der Quelle | gereinigt nach Positivliste | gereinigt; Skripte der Quelle bleiben erhalten |
| Fremdes JavaScript | **nie** | nur von der Quelle selbst, umgeschrieben, nicht für privilegierte Benutzer (s. u.) |
| Weiterleitungs-Route | aus | an, eng begrenzt |
| Interaktion (z. B. Lightbox) | eigener Code des Plugins | eigener Code oder Original-Skript |

**Wann eine Quelle vertrauenswürdig sein darf:** nur, wenn sie unter derselben Verantwortung steht wie die eigene Seite, also die eigenen Seiten. Wer eine solche Quelle übernimmt, kann über die Skripte auf die eigene Seite durchgreifen. Dieses Risiko ist bei eigenen Seiten nicht größer als das bereits bestehende, denn wer die eigene Quellseite kontrolliert, hat in der Regel auch Zugang zu denselben Zugangsdaten, Hostern und Personen.

## Angriffsflächen und Gegenmaßnahmen

### 1. Abruf von der Quelle (Server → Quelle)

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **SSRF:** Jemand bringt den Server dazu, interne Adressen (Datenbank, Cloud-Metadaten, Router) abzufragen. | Ziele kommen ausschließlich aus der Admin-Konfiguration (Alias → Basis-URL). Abgerufen wird mit `wp_safe_remote_*`, das private und reservierte Adressbereiche ablehnt. **Weiterleitungen werden nicht automatisch verfolgt.** Eine Weiterleitung wird nur akzeptiert, wenn sie auf denselben Host und dasselbe Schema zeigt. |
| **Manipulation unterwegs** (Man-in-the-Middle) | Nur `https://`, Zertifikatsprüfung immer an. Basis-URLs mit `http://` werden nicht gespeichert. |
| **Überlastung** durch riesige oder langsame Antworten | Größenlimit pro Antwort, kurze Zeitlimits, Begrenzung der Beitragsanzahl pro Shortcode, Zwischenspeicher. |
| **Shortcode-Attribute als Hebel:** Ein Autor versucht URLs, Pfade oder Vertrauensstufen einzuschleusen. | Der Shortcode nimmt nur Alias, Kategorie (Slug oder ID, strenges Muster) und Darstellungsoptionen mit festen Wertebereichen an. Unbekannte Attribute werden ignoriert. Ein Alias, der nicht existiert, führt zu keiner Anfrage. |

### 2. Verarbeitung des fremden HTML

Gilt für **beide** Vertrauensstufen. Die Reinigung läuft über einen echten HTML-Parser (WordPress HTML API bzw. DOM), **nie über reguläre Ausdrücke**.

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **Skripte** über `<script>`, `on…`-Attribute, `javascript:`-/`vbscript:`-URLs, `data:`-URLs außerhalb von Bildern, SVG mit Skript, `<object>`, `<embed>` | In der Standardstufe wird all das entfernt. Erlaubt sind nur Elemente und Attribute einer Positivliste. URLs werden auf `https:` (und Ankerlinks) geprüft, `data:` nur für Bilder. |
| **`<base>`-Einschleusung**, die alle relativen Links der eigenen Seite umbiegen würde | `<base>`, `<meta>`, `<link>`, `<title>` werden immer entfernt. |
| **Gefälschte Formulare** (Phishing, z. B. „Passwort erneut eingeben“) | `<form>`, `<input>`, `<button type="submit">` und Verwandte werden in der Standardstufe entfernt. |
| **Fremde iframes** | Nur iframes zu einer Positivliste bekannter Dienste (z. B. YouTube, Vimeo), immer mit `sandbox`- und `referrerpolicy`-Attributen. |
| **CSS-Angriffe:** Überlagern der eigenen Seite (Clickjacking per `position: fixed`), Datenabfluss per `url()` an fremde Server, `@import`, `expression()` | `<style>`-Blöcke werden auf den Container des Beitrags beschränkt (alle Selektoren bekommen dessen Präfix). `@import` und `expression()` werden entfernt. `url()` ist nur für die Quelle und die eigene Seite erlaubt. In der Standardstufe wird `position: fixed` bzw. `sticky` in fremdem CSS entfernt. Die Lightbox liefert das Plugin dann selbst. |
| **Tabnabbing** über Links mit `target="_blank"` | Solche Links bekommen immer `rel="noopener noreferrer"`. |
| **Gemischte Inhalte** (`http://`-Ressourcen auf einer `https://`-Seite) | Werden auf `https://` umgestellt oder entfernt. |
| **Ausbruch aus Attributen** in eigenen Vorlagen | Titel, Datum, Autor usw. werden beim Einsetzen in eigene Vorlagen immer maskiert (`esc_html`, `esc_attr`, `esc_url`). Nur der gereinigte Beitragsinhalt wird als HTML ausgegeben. |

Die WordPress-Standardfilter (`wp_kses_post`) werden **nicht** verwendet. Sie sind für Redakteursinhalte gedacht, beschädigen legitime Layout-Styles (z. B. bei Galerien) und decken CSS-Angriffe nicht ab. Die eigene Reinigung ist strenger bei Gefährlichem und schonender beim Layout.

### 3. Ausführung fremder Skripte (nur Stufe „vertrauenswürdig“)

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **Übernahme von Administrator- oder Redakteurssitzungen** durch ein bösartiges Skript | **Benutzer mit Bearbeitungsrechten (`edit_posts`) bekommen Einbettungen grundsätzlich in der sicheren Stufe ohne fremde Skripte**, auch bei vertrauenswürdigen Quellen. Fremde Skripte erreichen damit nur Besucher ohne Rechte zum Verändern der Seite. Optional lässt sich das je Quelle auf „nur nicht angemeldete Besucher“ verschärfen, etwa für Mitgliederseiten. Im Admin-Bereich wird nie etwas Fremdes ausgeführt. |
| **Skripte von Dritt-Hosts**, die eine Quellseite einbindet (Tracking, Werbung, CDNs) | Ausgeführt werden nur Skripte vom Host der Quelle und lokale Core-Bibliotheken. Alles andere wird entfernt, es sei denn, ein Administrator gibt den Host pro Quelle ausdrücklich frei. |
| **Nachträglich ausgetauschte Skripte** | Die Weiterleitungs-Route liefert Skripte nur über IDs aus, die beim Einsammeln vergeben wurden. Sie lädt nie eine frei angegebene URL. |
| **Missverständnis „Hülle = Sandbox“** | Der eigene Gültigkeitsbereich pro Quelle (`wp`, `jQuery`, `window`-Proxy) und das Shim verhindern Konflikte, **nicht Missbrauch**. Ein Skript kann `document` und damit die Seite weiterhin erreichen. Das ist dokumentiert und bewusst so. Die Sicherheit beruht auf der Vertrauensentscheidung und der Ausnahme für privilegierte Benutzer. |

### 4. Weiterleitungs-Route (Browser → eigene Seite → Quelle)

Sie ist die heikelste neue Angriffsfläche, weil jeder sie aufrufen kann. Sie existiert nur für vertrauenswürdige Quellen.

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **Offenes Relay / SSRF:** Jemand nutzt die Route, um beliebige Adressen abzurufen. | Die Route besteht aus Alias und Pfad. Die Ziel-URL wird **immer** aus der konfigurierten Basis-URL und einem normalisierten Pfad gebaut. Abgelehnt werden: `..`, kodierte Varianten davon, Backslashes, `@`, doppelte Schrägstriche, absolute URLs im Pfad und Steuerzeichen. |
| **Zugriff auf beliebige Pfade der Quelle** | Positivliste der Pfadklassen: `wp-json/…`, `wp-admin/admin-ajax.php`, sowie unter `wp-content/` und `wp-includes/` nur Dateitypen wie `.js`, `.css`, Schriften und Bilder. Alles andere wird abgelehnt, insbesondere `wp-login.php`, `xmlrpc.php` und sonstige Dateien unter `wp-admin/`. |
| **Missbrauch hinterlegter Zugangsdaten:** Ein anonymer Besucher handelt über die Route mit unseren Rechten auf der Quelle. | **Hinterlegte Zugangsdaten werden niemals an weitergeleitete Anfragen angehängt.** Sie dienen nur dem serverseitigen Einsammeln von Inhalten. Weitergeleitete Anfragen erreichen die Quelle immer als anonymer Besucher. |
| **Weitergabe der eigenen Sitzung an die Quelle** | Cookies, `Authorization` und andere Header des Besuchers werden **nie** weitergereicht. Weitergegeben wird nur eine Positivliste: `Content-Type`, `Accept` und die von WordPress-Skripten gesetzte Nonce-Kopfzeile der Quelle. `Set-Cookie` der Quelle wird verworfen. |
| **Fremde Seiten unter der eigenen Domain** (eine HTML-Antwort der Quelle, direkt im Browser geöffnet, liefe mit den Rechten der eigenen Seite) | Jede Antwort der Route trägt `Content-Security-Policy: sandbox` und `X-Content-Type-Options: nosniff`. Direkt geöffnete Antworten laufen dadurch in einem abgeschotteten Ursprung ohne Zugriff auf die eigene Seite. Skripte, die die Antwort per `fetch`/AJAX lesen, stört das nicht. Der `Content-Type` wird gegen die Pfadklasse geprüft. |
| **Verbotene HTTP-Methoden** | Nur `GET`, `HEAD`, `POST`. Für Dateien nur `GET`/`HEAD`. |
| **Überlastung der eigenen Seite oder der Quelle** (Verstärker-Angriff) | Drosselung pro IP-Adresse, Größenlimit für Anfragen und Antworten, kurze Zeitlimits, Zwischenspeicher für `GET`-Dateien. |
| **Cache-Vergiftung** | Zwischengespeichert werden nur `GET`-Antworten für Dateien. Der Schlüssel besteht aus Alias, normalisiertem Pfad und normalisierten Parametern. `POST`-Antworten und Datenendpunkte werden nicht gespeichert. Weil nie Zugangsdaten mitgeschickt werden, ist jede gespeicherte Antwort ohnehin für alle gleich. |
| **Fremde Seiten nutzen die Route** (CSRF: ein Besucher wird auf fremder Seite zu einem Aufruf gebracht) | Der Aufruf erreicht die Quelle nur als anonymer Besucher und kann nichts bewirken, was nicht jeder anonym ohnehin dürfte. |

### 5. Rückfall für relative Aufrufe

Gemeint sind REST-Routen, AJAX-Aktionen und Dateien, die es lokal nicht gibt und die zur Quelle weitergeleitet werden.

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **Übersteuern lokaler Funktionen** | Der Rückfall greift **nur**, wenn die Route, Aktion oder Datei lokal **nicht** existiert. Was lokal vorhanden ist, wird nie umgeleitet. |
| **Erraten beliebiger Ziele** | Weitergeleitet werden nur Routen und Aktionen, die beim Einsammeln einer vertrauenswürdigen Quelle tatsächlich gesehen wurden. |
| **Falsche Quelle bei mehreren Quellen** | Die Zuordnung über die aufrufende Seite (`Referer`) ist nur ein Wegweiser, keine Berechtigung: Jede Quelle bekommt nur ihre eigenen, gelernten Ziele, und es gelten alle Regeln aus Abschnitt 4. |

### 6. Konfiguration und gespeicherte Daten

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| Unbefugtes Ändern von Quellen und Vertrauensstufen | Nur mit `manage_options`, jede Änderung mit Nonce-Prüfung. |
| Auslesen der Zugangsdaten | Gespeichert verschlüsselt (libsodium, Schlüssel aus den WordPress-Salts). Sie werden nie zurück ins Formular geschrieben, nie geloggt und erscheinen in keiner Fehlermeldung. Ein leeres Feld behält den gespeicherten Wert. |
| Manipulierte Zwischenspeicher-Inhalte | Der Zwischenspeicher liegt in der Datenbank (Transients/Optionen), nicht als öffentlich abrufbare Datei. Gespeicherte Inhalte gelten weiterhin als fremd und werden bei **jeder** Ausgabe nach dem aktuellen Regelstand gereinigt. Verbesserungen der Reinigung wirken deshalb sofort auch für ältere Einträge. |
| Informationen in Fehlermeldungen | Besucher sehen keine technischen Details. Ausführliche Fehler nur für Benutzer mit `manage_options`. |

### 7. Import als lokale Beiträge („Kapseln“)

Importierte Beiträge sind echte WordPress-Beiträge. Sie sind damit für alle Themes, Plugins, Feeds und die REST API der eigenen Seite sichtbar. Fremder Inhalt gelangt so an Stellen, die das Plugin nicht selbst ausgibt.

| Bedrohung | Gegenmaßnahme |
| --- | --- |
| **Fremde Shortcodes:** Text wie `[shortcode]` im Quellinhalt würde bei normaler Ausgabe von der eigenen Seite ausgeführt. Ein Autor der Quelle könnte so lokale Shortcodes auslösen. | Das rohe HTML der Quelle liegt nur in einer Meta-Angabe und durchläuft nie `do_shortcode`. Für die Ausgabe ersetzt das Plugin den Inhalt im `the_content`-Filter **vor** WordPress' Shortcode-Verarbeitung durch einen Platzhalter und setzt das Ergebnis der Pipeline **nach** allen anderen Filtern ein. In `post_content` stehen eckige Klammern als `&#91;`/`&#93;`. |
| **Rohes HTML an Stellen, die das Plugin nicht kontrolliert** (Themes, Seitenbaukästen, SEO-Plugins lesen `post_content` direkt; Ausgabe bei deaktiviertem Plugin) | `post_content` enthält nur die **beim Import gereinigte** Fassung, `post_excerpt` reinen Text. Das rohe HTML steht ausschließlich in der Meta-Angabe und wird nur durch die Pipeline ausgegeben. Diese Meta-Angabe ist geschützt (Unterstrich-Präfix), also weder in der REST API noch in den Benutzerfeldern sichtbar. |
| **Unbemerktes Verändern** fremden Inhalts unter eigenem Namen | Bearbeiten und Löschen von Kapseln und ihren Bild-Anhängen sind über `map_meta_cap` für **alle** Benutzer gesperrt. Änderungen kommen nur aus der Synchronisation, die mit den Regeln der Admin-Seite arbeitet. |
| **Fremde Daten in Titel, Auszug, Slug, Autorname, Bildangaben.** Themes geben Titel, Auszüge und Autorennamen meist ohne Maskierung aus, weil WordPress dort HTML erlaubt. | Titel, Auszug und Autorname werden in reinen Text umgewandelt und **maskiert** gespeichert bzw. ausgegeben. Ein `&lt;script&gt;` im Titel der Quelle bleibt so Text. Slugs laufen über `sanitize_title`. Bild-URLs werden wie alle URLs geprüft (nur https). Größenangaben werden als Zahlen gespeichert. |
| **Bild-Anhänge ohne Datei** werden von anderen Plugins wie normale Medien behandelt (z. B. Bildbearbeitung, Neuberechnung von Größen). | Die Anhänge haben keine lokale Datei (`get_attached_file` liefert nichts). Sie sind in der Mediathek ausgeblendet und für alle Benutzer gesperrt. Ihre URLs liefert nur das Plugin, aus geprüften Angaben. |
| **Massenhaftes Anlegen** von Beiträgen durch eine große oder bösartige Quelle | Die Synchronisation läuft im Hintergrund und in Stapeln, mit einer Obergrenze pro Lauf und einer Sperre gegen parallele Läufe. |
| **Doppelter Inhalt für Suchmaschinen** | Die Einzelansicht einer Kapsel setzt `rel="canonical"` auf den Originalbeitrag. |
| **Fremde Autoren als lokale Benutzer** | Es werden nie Benutzer angelegt. Ohne Übersteuerung erscheint der Name des Originalautors nur als Text. Das Autoren-Archiv verweist dann auf den Beitrag selbst. |
| **Zielkategorien und Schlagwörter auf Anweisung der Quelle** | Welche lokalen Begriffe entstehen, bestimmen ausschließlich die Import-Regeln eines Administrators. Kategorien und Schlagwörter der Quelle selbst werden nicht übernommen. |

## Privatsphäre

- Bilder, Videos und (in der Stufe „vertrauenswürdig“) Skripte lädt der Browser des Besuchers **direkt von der Quelle**. Die Quelle sieht dadurch IP-Adresse und Browserdaten der Besucher. Bei eigenen Quellseiten ist das unkritisch, gehört aber in die Datenschutzerklärung. Für fremde Quellen kann später ein Laden über die eigene Seite ergänzt werden.
- Erlaubte Fremddienste (z. B. YouTube) übertragen ebenfalls Besucherdaten. Sie bleiben deshalb eine ausdrückliche Freigabe.

## Restrisiken und warum sie vertretbar sind

| Restrisiko | Bewertung |
| --- | --- |
| Eine **vertrauenswürdige Quelle wird gehackt** und liefert bösartige Skripte. | Betroffen sind nur Besucher ohne Bearbeitungsrechte, und nur deren Sicht auf die eingebetteten Bereiche bzw. die Seite. Die Seite selbst kann über deren Sitzungen nicht verändert werden. Die Vertrauensstufe ist auf eigene Seiten beschränkt und mit einem Klick widerrufbar. |
| **Automatismen anderer Plugins beim Veröffentlichen** (Newsletter, Social-Media-Posts) greifen auch für importierte Beiträge. | Kein Sicherheitsrisiko im engeren Sinn, aber ungewollte Aussendungen sind möglich. Hinweis im Schnellstart: vor dem ersten Import prüfen. |
| **Mitglieder-Sitzungen** (angemeldete Besucher ohne Bearbeitungsrechte) bei vertrauenswürdigen Quellen | Auf Mitgliederseiten je Quelle „nur nicht angemeldete Besucher“ aktivieren. |
| **DNS-Rebinding:** Die Quelle löst zwischen Prüfung und Abruf plötzlich auf eine interne Adresse auf. | Theoretisch möglich, setzt die Kontrolle über den DNS der Quelle voraus. Bei eigenen Quellen praktisch ausgeschlossen. Bei fremden Quellen ist die Weiterleitungs-Route ohnehin aus. |
| **Lücken im Reinigungscode** | Verwendet wird ein echter HTML-Parser mit Positivlisten. Zusätzlich gibt es eine Testsammlung bekannter XSS-Angriffsmuster, die bei jeder Änderung laufen muss. |
| **Neue Browser-Funktionen**, die die Positivlisten umgehen | Positivlisten sind gegen Neues robust, weil Unbekanntes standardmäßig entfernt wird. |

## Pflichten für die Umsetzung

- Jede Maßnahme dieses Dokuments bekommt einen automatisierten Test, vor allem die Reinigung (XSS-Testvektoren), die Pfadprüfung der Weiterleitungs-Route (Traversal-Varianten) und die Header-Positivlisten.
- Änderungen an Reinigung, Weiterleitungs-Route oder Vertrauensstufen werden in diesem Dokument nachgezogen, bevor sie umgesetzt werden.
- Die Admin-Seite erklärt bei der Stufe „vertrauenswürdig“ in einem Satz, was sie bedeutet.
