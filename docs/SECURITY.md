# Sicherheitsprüfung

Stand: Version 3.4, November 2026. Geprüft entlang der **OWASP Top 10:2025**.

Dieses Dokument sagt, was geprüft wurde, was dabei gefunden und behoben
wurde, wie jede Korrektur getestet ist und was offen bleibt. Es behauptet
nicht, QuickPoll sei „sicher“. Eine Prüfung findet, wonach sie sucht; sie
beweist nicht, dass es nichts anderes gibt.

## Umfang und Vorgehen

- **Geprüft:** der gesamte eigene PHP-Code (Seiten, `includes/`, `database/`),
  `assets/app.js`, die `.htaccess`-Dateien, die Konfigurationsvorlagen und die
  Einbindung der Stripe-Bibliothek.
- **Wie:** Code gelesen, Kategorie für Kategorie; verdächtige Stellen mit
  gezielten Anfragen ausprobiert (`curl`, gefälschte Kopfzeilen, erfundene
  Parameter); jede Korrektur mit einem Test abgesichert, der ohne die Korrektur
  fehlschlägt.
- **Umgebung:** lokale Server (PHP 8.3, MariaDB), Entwicklungs- und
  Produktionsmodus, Ersatz-Server für Google (`tests/mock_oidc.php`) und Stripe
  (`tests/mock_stripe.php`).
- **Nicht geprüft:** echter Apache mit HTTPS-Zertifikat, echtes Google, echtes
  Stripe, echter Mailversand, Lastverhalten, der Stripe-Fremdcode selbst. Kein
  externer Penetrationstest, kein automatischer Scanner.

Schweregrade: **Hoch** = fremde Daten lesbar/änderbar oder Kontoübernahme
ohne Mithilfe des Opfers · **Mittel** = Missbrauch mit Mithilfe des Opfers,
unter besonderen Bedingungen oder mit begrenztem Schaden · **Niedrig** =
Informationsleck oder Härtung · **Hinweis** = kein Angriff, aber falsches Verhalten.

## Befunde dieser Prüfung

Alle elf sind behoben. „Wie getestet“ nennt den Test, der die Korrektur belegt.

| Nr. | Schwachstelle | Schwere | Komponente | Korrektur | Wie getestet | Was bleibt |
|---|---|---|---|---|---|---|
| 1 | **Offene Weiterleitung.** Die Sprach- und Design-Umschaltung leitete auf die angefragte Adresse zurück. `//evil.example/%2e%2e/index.php?lang=en` ergab `Location: //evil.example/…` – ein Link auf QuickPoll, der auf eine fremde Seite führt (Phishing). | Mittel | `includes/i18n.php`, `preferences_boot()` | Ziel wird aus dem Skriptnamen gebaut, nicht aus der Anfrage; nur die eigenen Parameter bleiben erhalten. | Vorher mit `curl` nachgestellt; danach prüft der Ablauftest dieselbe Anfrage und mehrere Varianten: Ziel ist immer eine eigene Seite. | Keine weiteren Weiterleitungen mit Nutzereingabe gefunden. |
| 2 | **Konten erraten.** Die Registrierung meldete „bereits registriert“. | Niedrig | `register.php` | Gleiche Antwort für neue und bekannte Adressen; die Inhaberin erfährt es per Mail (höchstens 2 am Tag). Kein Auto-Login mehr. | Produktionsmodus: Status, Weiterleitung und Seite für beide Fälle verglichen; Mail-Log geprüft. | Antwortzeit angeglichen, nicht gemessen identisch. |
| 3 | **Umfrage fluten.** Die Mehrfach-Sperre hängt an IP und Browser-Kennung; ein Skript mit wechselnder Kennung konnte beliebig viele Antworten abgeben. | Mittel | `survey.php` | Höchstens 60 Abgaben je Stunde von einem Anschluss je Umfrage; dazu optional Höchstzahl und Zugangscode. | Ablauftest: 60 Abgaben mit je neuer Browser-Kennung werden angenommen, die 61. wird mit Erklärung abgewiesen und nicht gespeichert. | Verteilte Angriffe (viele Anschlüsse) nicht; kein CAPTCHA. |
| 4 | **Links aus der Host-Kopfzeile.** Ohne `BASE_URL` baute die Anwendung Links in Mails aus der Anfrage. Ein gefälschter Host hätte Reset-Links auf eine fremde Adresse zeigen lassen. | Mittel (nur bei fehlender Einstellung) | `includes/bootstrap.php` | In Produktion startet die Anwendung ohne `BASE_URL` nicht (500 „Configuration error“). | Server im Produktionsmodus ohne `BASE_URL`: jede Seite antwortet 500, nichts wird verschickt. | Im Entwicklungsmodus gilt weiter die Anfrage – gewollt. |
| 5 | **Texte ohne Escaping.** An einzelnen Stellen wurden übersetzte Texte ohne `e()` ausgegeben. Die Texte stammen aus den eigenen Sprachdateien, nicht von Nutzern. | Niedrig | `analytics.php`, `results.php` | Ausgabe über `e()`. | Durchsicht aller `<?=`-Ausgaben; `tests/template_test.php` sucht nach kaputten Attributen. | Kein automatischer Beweis, dass jede Ausgabe escaped ist. |
| 6 | **Konto löschen ohne Nachweis.** Konten ohne Passwort (nur Google) bestätigten das Löschen durch Eintippen der eigenen Adresse – das kann jeder an einem offenen Gerät. | Mittel | `settings.php`, `auth_google_callback.php` | Frische Anmeldung bei Google nötig (`prompt=login`, `max_age=0`, `auth_time` höchstens 10 Minuten alt, gleiche Google-Identität). | Ablauftest gegen den Ersatz-Anbieter: Löschen ohne Bestätigung, alte Anmeldung, fehlendes `auth_time` und anderes Google-Konto werden abgelehnt; `tests/security_test.php` prüft die Altersregel. | Gegen echtes Google nicht gelaufen. Dass die Freigabe nach 10 Minuten verfällt, ist im Code, aber nicht durch einen Test mit Wartezeit belegt. |
| 7 | **Adresse blockieren.** Wer eine fremde Adresse registrierte, blockierte sie auf Dauer. | Niedrig | `includes/account.php`, `database/maintenance.php` | Unbestätigte Konten ohne Zahlung werden nach 7 Tagen gelöscht. | Ablauftest mit Konten verschiedenen Alters; bestätigte und zahlende Konten bleiben. | Bis zu 7 Tage Blockade; „Passwort vergessen“ löst sie sofort. |
| 8 | **Mengenbegrenzung hinter einem Proxy.** Hinter Proxy oder CDN sah PHP nur dessen Adresse: ein Kontingent für alle. Die naheliegende „Lösung“, `X-Forwarded-For` zu glauben, ließe jeden seine Adresse erfinden. | Mittel (je nach Betrieb) | `includes/security.php`, `client_ip()` | `TRUSTED_PROXIES`: Die Kopfzeile zählt nur, wenn die Anfrage vom eigenen Proxy kommt, gelesen von rechts. | `tests/security_test.php` (11 Fälle mit gefälschten Kopfzeilen); Ablauftest: wechselnde `X-Forwarded-For` hebeln die Login-Sperre nicht aus. | Falsch gesetzte `TRUSTED_PROXIES` öffnen die Lücke wieder. |
| 9 | **Cookie ohne `Secure` hinter TLS-Proxy.** Endet HTTPS am Proxy, sieht PHP „HTTP“ – Sitzungs-Cookie ohne `Secure`, kein HSTS. | Mittel (je nach Betrieb) | `includes/security.php`, `is_https()` | Steht `BASE_URL` auf `https://`, gilt die Seite als HTTPS. `X-Forwarded-Proto` wird bewusst nicht ausgewertet. | `tests/security_test.php`; Ablauftest: Server mit `https`-Adresse setzt `Secure` und HSTS, ohne nicht. | Mit echtem Zertifikat nicht geprüft. |
| 10 | **Kein Protokoll.** Fehlgeschlagene Logins, abgewiesene Anfragen und Kontoänderungen wurden nirgends festgehalten. | Niedrig | `includes/security.php`, `security_log()` | `storage/logs/security.log`, 16 Ereignisarten, ohne Passwörter, Adressen oder IPs im Klartext. | Ablauftest: Ereignisse erscheinen; ein Zeilenumbruch im Namen erzeugt keine eigene Zeile; Passwort und Adresse stehen nicht im Log. | Niemand wird benachrichtigt (siehe A09). |
| 11 | **Aufrufe verfälscht.** Jeder Abruf zählte als Aufruf, auch Bots, Link-Vorschauen und eigene Vorschauen. Kein Angriff, aber eine falsche Abschlussquote. | Hinweis | `survey.php`, `is_automated_request()` | Bekannte Bots, Vorab-Abrufe, Eigentümer und Vorschauen zählen nicht. | Ablauftest mit verschiedenen Kennungen. | Bots, die sich als Browser ausgeben, zählen mit. |

Dazu ein Fehler ohne Sicherheitsfolge, der hier nicht verschwiegen werden
soll: In den Versionen 3.1 bis 3.3 schrieben zwei Auswahlfelder
`value=\"…\"` ins HTML. Über die Oberfläche ließ sich deshalb keine Frage
hinzufügen („Unbekannter Fragetyp“), und der Statusfilter der Liste wirkte
nicht. Die damaligen Tests schickten die Werte direkt und merkten es nicht.
Seit 3.4 sucht `tests/template_test.php` nach solchen Attributen, und ein
Browser-Test klickt den Weg wirklich durch.

## OWASP Top 10:2025 – Punkt für Punkt

### A01 Broken Access Control

- **Schutz:** Jede Abfrage auf Umfragen, Fragen, Antworten und Rechnungen
  enthält `nutzer_id = ?`. Öffentliche Umfragen sind nur über ein
  Zufallstoken (128 Bit) erreichbar. Entwürfe, beendete und archivierte
  Umfragen nehmen keine Antworten an; die Vorschau ist nur für die Eigentümerin.
  Unbestätigte Konten können nicht veröffentlichen und nicht kaufen.
  Skripte in `database/` und `tests/` laufen nur auf der Kommandozeile.
  Weiterleitungen gehen nur auf eigene Seiten (Befund 1). Anfragen an fremde
  Server (früher eigene Kategorie SSRF) gehen nur an fest konfigurierte
  Adressen von Google und Stripe; keine Adresse kommt aus Nutzereingaben.
- **Getestet:** Ein zweites Konto ruft Editor, Ergebnisse, Export und
  Einzelantworten eines fremden Kontos auf (kein Zugriff, nichts vom fremden
  Inhalt auf der Seite) und schickt mit eigenem gültigem CSRF-Token 19
  Änderungsversuche gegen die fremde Umfrage, ihre Fragen, ihre Antworten und
  eine fremde Sitzung – danach ist alles unverändert. Ohne Login führen die
  geschützten Seiten zur Anmeldung. Fremde und erfundene Stripe-Sitzungen
  schalten nichts frei; Skripte in `database/` antworten im Browser mit 403.
- **Offen:** Es gibt eine Rolle (Eigentümerin), keine Teams, keine
  Freigaben – nichts davon ist gebaut, also auch nicht geprüft. Die Prüfung
  ist Durchsicht plus Stichproben, kein vollständiger Beweis je Abfrage.

### A02 Security Misconfiguration

- **Schutz:** Fehler in Produktion nur ins Log. Kopfzeilen auf jeder Seite:
  Content-Security-Policy ohne Inline-Skripte und -Stile,
  `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS unter HTTPS.
  Seiten mit Sitzung werden nicht zwischengespeichert. Produktion ohne
  `BASE_URL` startet nicht (Befund 4). Entwicklungswerkzeuge (Tarif
  umschalten, Mail-Link auf der Seite, Ersatz-Server) sind an `APP_ENV`
  gebunden und in Produktion wirkungslos.
- **Getestet:** Kopfzeilen im Produktionsmodus; Entwicklungsfunktionen im
  Produktionsmodus aufgerufen; Befunde 8 und 9.
- **Offen:** Der Schutz der Ordner `includes/`, `database/`, `storage/`,
  `lib/`, `tests/`, `docs/` und von `.env` steht in `.htaccess`. **Nginx und
  der eingebaute PHP-Server lesen `.htaccess` nicht** – dort muss der
  Betreiber die Ordner selbst sperren, sonst sind Logs und im schlimmsten
  Fall `.env` abrufbar. Die Standardwerte (Datenbank `root` ohne Passwort)
  sind für XAMPP gedacht und für einen echten Server ungeeignet. Die
  Fehlerseite „Datenbank aktualisieren“ nutzt einen Inline-Stil, den die
  eigene CSP blockiert – sie erscheint dann ungestaltet, mehr nicht.

### A03 Software Supply Chain Failures

- **Lage:** Eine einzige Fremdbibliothek, `stripe-php` 23.0.0, als Kopie in
  `lib/stripe-php/` mit `VERSION`-Datei. Kein Composer, kein npm, kein CDN,
  keine fremden Schriften oder Skripte im Browser.
- **Offen:** Niemand meldet automatisch, wenn in `stripe-php` eine Lücke
  bekannt wird; die Kopie wird von Hand aktualisiert (Anleitung in
  `lib/README.md`). Die Herkunft der Kopie ist nicht per Prüfsumme belegt.
  PHP, MariaDB und Apache aktuell zu halten ist Sache des Betriebs. Mit
  Composer und `composer audit` wäre das besser lösbar – bewusst nicht
  eingeführt, weil das Projekt ohne Build-Schritt auf XAMPP laufen soll.

### A04 Cryptographic Failures

- **Schutz:** Passwörter und Zugangscodes mit `password_hash()` (bcrypt),
  Rehash beim Login. Links aus Mails und Sitzungskennungen sind
  Zufallswerte aus `random_bytes()`, in der Datenbank nur als SHA-256.
  IP-Adressen nur als HMAC mit `APP_SECRET`. Vergleiche mit `hash_equals()`.
  Google-ID-Token: nur RS256, Signatur mit OpenSSL gegen Googles Schlüssel.
  Webhooks: Signaturprüfung der Stripe-Bibliothek.
- **Getestet:** `tests/oauth_test.php` (42 Prüfungen, u. a. `alg: none`,
  HS256 mit dem öffentlichen Schlüssel, fremder Schlüssel, veränderter Inhalt);
  `tests/billing_test.php` (falsches Secret, veränderter Inhalt, alte Signatur).
- **Offen:** HTTPS ist Voraussetzung und liegt beim Betrieb; mit echtem
  Zertifikat wurde nicht getestet. Daten in der Datenbank sind nicht
  verschlüsselt (Antworten, Namen, Adressen). Wird `APP_SECRET` bekannt,
  lassen sich IP-Kürzel und Zugangscode-Cookies nachrechnen.

### A05 Injection

- **Schutz:** Ausschließlich Prepared Statements, echte (nicht emulierte)
  Platzhalter; Sortierung und Spaltennamen nur aus festen Listen. HTML-Ausgabe
  über `e()`, dazu die CSP als zweite Linie. CSV-Export entschärft Zellen, die
  mit `=`, `+`, `-` oder `@` beginnen. Mails: Empfänger oder Betreff mit
  Zeilenumbruch werden abgelehnt. Protokoll: Werte als JSON, kein Zeilenumbruch.
- **Getestet:** Umfrage mit `<script>alert(1)</script>` im Titel angelegt
  und auf den Seiten des Kontos gerendert: der Text erscheint nur escaped.
  Browser-Tests ohne CSP-Verstöße und ohne JavaScript-Fehler in drei Sprachen;
  Protokoll-Test mit Zeilenumbruch in einem Formularfeld.
- **Offen:** Kein automatischer Scanner; die Aussage „immer `e()`“ beruht
  auf Durchsicht und Stichproben.

### A06 Insecure Design

- **Entscheidungen:** Stripe ist das Original, die Datenbank die Kopie –
  kein Tarif aus URL oder Formular. Konten werden nie allein über eine
  gleiche E-Mail-Adresse mit Google verknüpft. Sensible Änderungen brauchen
  das Passwort oder eine frische Google-Anmeldung. Eine neue
  E-Mail-Adresse gilt erst nach Bestätigung durch die **neue** Adresse.
  Bestätigungslinks wirken erst per Klick auf eine Schaltfläche (POST), damit
  Mail-Scanner sie nicht auslösen. Mengenbegrenzungen für alles, was Mails
  auslöst oder sich erraten lässt.
- **Offen:** Die Mehrfach-Sperre ohne Login ist umgehbar (anderes Gerät,
  anderes Netz) – grundsätzlich, nicht behebbar ohne Konten für Teilnehmende.
  Der Zugangscode ist ein gemeinsames Kennwort, keine persönliche Einladung.
  Kein CAPTCHA. Keine Zwei-Faktor-Anmeldung.

### A07 Authentication Failures

- **Schutz:** Login-Sperre nach 5 Fehlversuchen je Adresse und 20 je
  Anschluss in 15 Minuten; gleiche Antwort und gleiche Rechenzeit für
  unbekannte Adressen. Sitzungen in der Datenbank, einzeln widerrufbar, neue
  Kennung bei jedem Login, Ende nach 2 Stunden ohne Aktivität. Passwort-Reset
  beendet alle Sitzungen. Registrierung und „Passwort vergessen“ verraten
  keine Konten. Google: Code-Flow mit `state`, `nonce` und PKCE.
- **Getestet:** Ablauftests zu Registrierung, Bestätigung, Login, Sperre,
  Reset, Sitzungswiderruf, Adresswechsel, Google (Login, Verknüpfen, Trennen,
  falscher `state`, wiederverwendeter Code, fremde Identität).
- **Offen:** Keine Zwei-Faktor-Anmeldung. Passwörter werden nur auf Länge
  geprüft (8–72 Zeichen), nicht gegen Listen geleakter Passwörter. Die Sperre
  je Adresse lässt sich missbrauchen, um jemanden 15 Minuten auszusperren.
  Gegen echtes Google nicht gelaufen.

### A08 Software or Data Integrity Failures

- **Schutz:** Webhooks nur mit gültiger Signatur; jede Ereignis-ID wird
  genau einmal verarbeitet, Ereignis und Änderung in einer Transaktion; der
  Stand wird bei Stripe abgefragt statt aus dem Ereignis übernommen. Keine
  `unserialize()`-Aufrufe auf fremde Daten, keine Auto-Updates, kein
  nachgeladener Code. Der Zeitstempel für die Bearbeitungszeit und das
  Zugangscode-Cookie sind mit HMAC signiert.
- **Getestet:** doppelte, gleichzeitige, verspätete und gefälschte
  Webhooks; veränderter Zeitstempel und fremdes Code-Cookie.
- **Offen:** Die Stripe-Bibliothek wird beim Einspielen nicht gegen eine
  Prüfsumme geprüft (siehe A03).

### A09 Security Logging & Alerting Failures

- **Schutz:** `storage/logs/security.log` (Befund 10), `php-error.log`,
  im Entwicklungsmodus `mail.log`.
- **Offen – das ist die schwächste Stelle:** Es gibt **keinen Alarm**.
  Niemand erfährt von 500 fehlgeschlagenen Logins, solange niemand die Datei
  liest. Die Logs liegen auf demselben Server wie die Anwendung, werden
  nicht rotiert und nicht gegen Veränderung geschützt. Für einen echten
  Betrieb: `logrotate`, Weiterleitung an ein vorhandenes Monitoring oder
  wenigstens ein täglicher Bericht per Cron.

### A10 Mishandling of Exceptional Conditions

- **Schutz:** Ein zentraler Fehlerbehandler; in Produktion sieht der Browser
  nur eine allgemeine Meldung. Im Zweifel wird abgelehnt: Ist Stripe nicht
  erreichbar, antwortet der Webhook mit 500 (Stripe wiederholt), und niemand
  wird auf Verdacht freigeschaltet oder gesperrt; ein Abo mit überschrittenem
  Enddatum endet auch ohne Webhook. Fehlt eine Tabelle, erklärt eine eigene
  Seite, was zu tun ist. Mehrschrittige Änderungen laufen in Transaktionen;
  doppelte Registrierung fängt die UNIQUE-Regel ab, nicht ein vorheriges Nachsehen.
- **Getestet:** Ausfall des Stripe-Ersatzes an mehreren Stellen (Kauf,
  Rückkehr, Webhook, Kündigung beim Löschen), Google mit Fehlern und Abbruch,
  ungültige Formulareingaben (Stichproben), zwei gleichzeitige Webhooks.
- **Offen:** Ausfall der Datenbank mitten in einer Anfrage, volle Platte
  und Zeitüberschreitungen unter Last sind nicht getestet. Schlägt der
  Mailversand fehl, steht das im Fehlerlog, aber die Nutzerin erfährt es nicht.

## Offene Risiken, nach Gewicht

1. **Betrieb:** HTTPS, gesperrte Ordner auf Nicht-Apache-Servern, echtes
   Datenbankpasswort, aktuelle Server-Software – nichts davon kann der Code erzwingen.
2. **Kein Alarm** bei Angriffen (A09).
3. **Keine Zwei-Faktor-Anmeldung**, keine Prüfung gegen geleakte Passwörter (A07).
4. **Ungetestet gegen echte Dienste:** Google, Stripe, Mailversand, HTTPS.
5. **Lieferkette von Hand** (A03).
6. **Mehrfach-Sperre und Flutschutz** sind Hürden, keine Garantien (A06).

## Vor dem Livegang

- [ ] `APP_ENV=production`, eigenes `APP_SECRET`, eigener Datenbanknutzer mit Passwort
- [ ] `BASE_URL` mit `https://`; HTTPS-Zertifikat; prüfen, dass das Sitzungs-Cookie `Secure` trägt
- [ ] Von außen aufrufen und 403/404 erwarten: `/.env`, `/includes/config.php`, `/storage/logs/security.log`, `/database/schema.sql`, `/tests/run.php`
- [ ] Hinter Proxy/CDN: `TRUSTED_PROXIES` setzen und mit zwei Geräten prüfen, dass die Login-Sperre je Anschluss zählt
- [ ] Cron: `database/maintenance.php` und `database/billing_reconcile.php` täglich; Logs rotieren
- [ ] Einmal mit echtem Google-Client, echter Mailadresse und Stripe-Testmodus durchklicken (`docs/AUTH.md`, `docs/PAYMENTS.md`)
- [ ] Jemanden bestimmen, der `security.log` und `php-error.log` liest
