# Anmeldung und Konto

Wie sich Nutzer bei QuickPoll anmelden, wie die E-Mail-Adresse bestätigt wird,
wie Google eingebunden ist und welche Regeln dahinter stehen. Die technischen
Stellen sind jeweils genannt, damit sich jede Aussage im Code nachprüfen lässt.

## Überblick

| Thema | Datei | Tabelle |
|---|---|---|
| Registrierung mit beliebiger E-Mail-Adresse | `register.php` | `nutzer` |
| E-Mail bestätigen, Link erneut senden, neue Adresse bestätigen | `verify_email.php` | `konto_token` |
| Login, Drosselung | `login.php`, `includes/security.php` | `login_versuch` |
| Passwort vergessen / zurücksetzen | `forgot_password.php`, `reset_password.php` | `passwort_reset` |
| Google-Anmeldung | `auth_google.php`, `auth_google_callback.php`, `includes/oauth_google.php` | `nutzer_identitaet` |
| Angemeldete Geräte, Abmelden aus der Ferne | `includes/security.php`, `settings.php` | `sitzung` |
| Mengenbegrenzungen | `rate_limit_allow()` in `includes/security.php` | `rate_limit` |
| Kontoverwaltung | `settings.php`, `includes/account.php` | – |
| E-Mails (Text + HTML, de/en/ar) | `includes/mail.php` | – |

## E-Mail-Bestätigung

1. Registrierung legt das Konto **unbestätigt** an (`email_bestaetigt_am` ist `NULL`),
   meldet an und verschickt einen Link, der 24 Stunden gilt.
2. Unbestätigte Konten können Umfragen anlegen und bearbeiten, aber **nicht
   veröffentlichen** und **kein Abo abschließen**. Die Prüfung sitzt auf dem
   Server (`survey_edit.php`, `subscription.php`), der Hinweisbalken ist nur die Erklärung dazu.
3. Der Link zeigt eine Seite mit einer Schaltfläche. Erst der Klick (POST mit
   CSRF-Token) bestätigt. Grund: Mail-Scanner und Link-Vorschauen rufen Links
   automatisch auf und dürfen damit nichts auslösen.
4. In der Datenbank steht nur der SHA-256-Hash des Tokens. Ein Token gilt
   einmal, nur für die Adresse, an die es ging, und verfällt, sobald ein neues
   angefordert wird.
5. „Link erneut senden“: höchstens 3 Mails pro Stunde und Konto.

Konten, die es vor diesem Stand gab, gelten nach der Migration als bestätigt.
Ein erfolgreicher Passwort-Reset bestätigt die Adresse ebenfalls, denn wer den
Link öffnet, hat Zugriff auf das Postfach.

## E-Mail-Adresse ändern

Die Adresse ist Loginname und Rückweg ins Konto. Sie wechselt deshalb erst,
wenn das **neue** Postfach den Link bestätigt hat:

- Formular verlangt das aktuelle Passwort.
- Bestätigungslink geht an die neue Adresse, ein Hinweis an die bisherige.
- Bis zur Bestätigung gilt die bisherige Adresse; die Änderung lässt sich abbrechen.
- Gehört die neue Adresse schon zu einem anderen Konto, antwortet die Seite
  wie immer, verschickt aber nichts (sonst ließe sich abfragen, wer registriert ist).
  Entscheidend ist am Ende der `UNIQUE`-Index auf `nutzer.email`.
- Offene Passwort-Reset-Links werden beim Wechsel gelöscht (sie gingen an die alte Adresse).

## Sitzungen

PHP speichert die Sitzung; die Tabelle `sitzung` macht sie **sichtbar und
widerrufbar**. Beim Login entsteht ein Zufallstoken, das nur in der PHP-Sitzung
liegt. In der Tabelle steht sein SHA-256-Hash zusammen mit Zeitpunkt,
Browser-Kennung und einem HMAC der IP-Adresse.

- Angemeldet ist nur, wer Nutzer-ID **und** passende Zeile hat (`current_user()`).
  Fehlt die Zeile, ist die Sitzung beendet, egal was im Cookie steht.
- Einstellungen → „Angemeldete Geräte“: einzelne Geräte oder alle anderen abmelden.
- Passwort ändern meldet alle **anderen** Geräte ab, Passwort-Reset **alle**.
- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` unter HTTPS, neue Sitzungs-ID bei
  jedem Login, Abmeldung nach 2 Stunden ohne Aktivität.

Nach dem Update auf diesen Stand müssen sich alle einmal neu anmelden, weil
ältere Sitzungen keine Zeile in `sitzung` haben.

## Mengenbegrenzungen

| Aktion | Grenze | gezählt je |
|---|---|---|
| Login-Fehlversuche | 5 / 15 min, zusätzlich 20 / 15 min | E-Mail-Adresse, IP |
| Registrierung (auch über Google) | 5 / Stunde | IP |
| Passwort-Link anfordern | 10 / Stunde, außerdem 1 Mail / 2 min je Konto | IP |
| Bestätigungslink erneut senden | 3 / Stunde | Konto |
| E-Mail-Adresse ändern | 3 / Stunde | Konto |
| Passwort-Abfrage bei sensiblen Änderungen | 10 / 15 min | Konto |
| Google-Anmeldung starten | 20 / Stunde | IP |
| Hinweis-Mail „du hast schon ein Konto“ | 2 / Tag | E-Mail-Adresse |
| Zugangscode einer Umfrage | 10 / 15 min | IP und Umfrage |
| Abgaben einer Umfrage | 60 / Stunde | IP und Umfrage |

Gezählt wird mit einem HMAC als Schlüssel, IP-Adressen stehen nie im Klartext
in der Datenbank. Jede ausgelöste Grenze steht im Sicherheitsprotokoll.

**Hinter einem Reverse-Proxy oder CDN** sieht PHP als `REMOTE_ADDR` die
Adresse des Proxys; ohne weitere Angabe teilen sich dann alle Besucher ein
Kontingent. Zwei Wege:

- Der Webserver setzt die echte Adresse ein (Apache: `mod_remoteip`), oder
- `TRUSTED_PROXIES` nennt die Adressen bzw. Netze des eigenen Proxys
  (`10.0.0.5, 10.0.0.0/8`). Nur wenn die Anfrage wirklich von dort kommt,
  liest QuickPoll `X-Forwarded-For` – von rechts nach links, bis zur ersten
  Adresse, die kein eigener Proxy ist. Einträge links davon kann der Client
  frei erfinden, sie zählen nicht.

Ohne `TRUSTED_PROXIES` wird der Header nie ausgewertet. `tests/security_test.php`
prüft die Auswertung mit gefälschten Kopfzeilen.

## Registrierung verrät keine Konten

`register.php` antwortet immer gleich: Weiterleitung auf „Fast geschafft –
schau in dein Postfach“, egal ob die Adresse neu ist oder schon ein Konto hat.

| Adresse | was passiert |
|---|---|
| neu | Konto wird angelegt, Bestätigungslink geht an die Adresse |
| hat schon ein Konto | nichts ändert sich; die **Inhaberin** bekommt eine Mail „jemand wollte ein Konto mit deiner Adresse anlegen“ (höchstens zwei am Tag) |

Der Passwort-Hash wird berechnet, bevor feststeht, welcher Fall vorliegt –
beide dauern gleich lang. Nach der Registrierung ist niemand angemeldet;
der Weg führt über die Bestätigung und den Login. „Passwort vergessen“
antwortet schon seit Version 2 für beide Fälle gleich.

Im Entwicklungsmodus zeigt die Seite zusätzlich die Mail aus dem Log an – das
unterscheidet die Fälle sichtbar und ist der Grund, warum der Vergleich nur im
Produktionsmodus getestet wird.

## Aufräumen

`maintenance_purge()` löscht:

| was | wann |
|---|---|
| unbestätigte Konten ohne Stripe-Kunden, samt ihren Entwürfen | 7 Tage nach der Registrierung |
| abgelaufene Bestätigungs- und Reset-Links | 1 Tag nach Ablauf |
| Sitzungen ohne Aktivität | nach dem Sitzungs-Timeout |
| Login-Fehlversuche, Zähler der Mengenbegrenzung | nach 1 bzw. 2 Tagen |
| verarbeitete Webhook-Ereignisse | nach 90 Tagen |

Damit blockiert eine Registrierung mit fremder Adresse diese höchstens eine
Woche. Die Funktion läuft bei etwa jedem 50. Login mit. Verlässlich wird es
mit einem täglichen Cron:

```
php database/maintenance.php
```

Das Skript gibt aus, wie viele Zeilen es je Regel entfernt hat, und lässt
sich nur auf der Kommandozeile starten.

## Sicherheitsprotokoll

`storage/logs/security.log`, eine Zeile je Ereignis: Zeit, Ereignis,
Nutzer-ID, ein Kürzel der IP-Adresse (HMAC, nicht umkehrbar) und Details als
JSON. Protokolliert werden: `login_ok`, `login_failed`, `login_throttled`,
`rate_limited`, `csrf_rejected`, `password_changed`, `password_reset`,
`email_change_requested`, `email_changed`, `provider_linked`,
`provider_unlinked`, `google_failed`, `reauth_ok`, `sessions_ended`,
`account_deleted`, `webhook_rejected`.

Nicht im Protokoll: Passwörter, Tokens, E-Mail-Adressen und IP-Adressen im
Klartext. Zeilenumbrüche in Werten werden entfernt, damit niemand über ein
Formularfeld eigene Zeilen ins Protokoll schreibt. Es gibt keine automatische
Auswertung und keinen Alarm – das Protokoll muss jemand lesen oder an ein
vorhandenes Monitoring anschließen. Der Ordner `storage/` ist per `.htaccess`
gesperrt; die Datei wächst unbegrenzt und sollte mit `logrotate` oder von Hand
gekürzt werden.

## Google-Anmeldung

OpenID Connect, Authorization-Code-Flow mit PKCE, ohne Bibliothek.

```
Browser            QuickPoll                              Google
   │  POST auth_google.php (CSRF-Token)                      │
   │ ───────────────► state, nonce, verifier in die Sitzung  │
   │ ◄─────────────── 302 zu Google (state, nonce, challenge)│
   │ ───────────────────────────────────────────────────────►│ Anmeldung
   │ ◄─────────────────────────────────────────────────────── 302 ?code&state
   │  GET auth_google_callback.php                           │
   │ ───────────────► state prüfen (einmalig, 10 Minuten)    │
   │                  code + verifier + client_secret ──────►│
   │                  ◄────────────────────────── ID-Token   │
   │                  Signatur, iss, aud, exp, nonce prüfen  │
   │ ◄─────────────── angemeldet                             │
```

Was `google_verify_id_token()` prüft, alles muss stimmen:

1. Algorithmus genau `RS256` (kein `none`, kein `HS256`)
2. Signatur mit dem Google-Schlüssel, den das Token per `kid` nennt
   (Schlüssel von Googles JWKS-Adresse, 6 Stunden zwischengespeichert in `storage/cache/`)
3. Aussteller `iss` ist Google
4. Empfänger `aud` (und `azp`, falls vorhanden) ist unsere Client-ID
5. `exp` nicht abgelaufen, `iat` nicht in der Zukunft (60 Sekunden Toleranz)
6. `nonce` ist der Wert aus unserer Sitzung
7. `sub` vorhanden

Zusätzlich muss `email_verified` wahr sein. `tests/oauth_test.php` füttert die
Funktion mit gefälschten und kaputten Tokens (42 Prüfungen, auch zu `auth_time`).

### Welches Konto gehört zu einer Google-Identität?

| Lage | Ergebnis |
|---|---|
| Google-Kennung (`sub`) ist schon verknüpft | Anmeldung in dieses Konto |
| Aus den Einstellungen gestartet („Google verknüpfen“, mit Passwort) | Verknüpfung mit dem angemeldeten Konto |
| Niemand hat diese E-Mail-Adresse | neues Konto, bestätigt, ohne Passwort |
| Es gibt ein Konto mit dieser E-Mail-Adresse, aber ohne Verknüpfung | **abgelehnt** mit Hinweis: mit Passwort anmelden, dann in den Einstellungen verknüpfen |

Die letzte Zeile ist der Schutz vor Kontoübernahme: Eine übereinstimmende
E-Mail-Adresse allein reicht nie, um in ein bestehendes Konto zu kommen.
Zugeordnet wird über `sub`, nicht über die Adresse; eine Google-Kennung kann
nur an einem QuickPoll-Konto hängen (`UNIQUE (anbieter, anbieter_id)`).

Gespeichert werden Anbieter, `sub` und E-Mail-Adresse. Kein Access-Token, kein
Refresh-Token, kein Profilbild; angefragt wird nur `openid email`.

Verknüpfung entfernen geht nur mit Passwort. Konten ohne Passwort (über Google
entstanden) legen eines über einen Link per E-Mail fest; solange keines
existiert, bleibt Google verknüpft.

### Konto ohne Passwort löschen: erneute Anmeldung bei Google

Wer sich nur über Google angemeldet hat, hat kein Passwort, mit dem sich das
Löschen bestätigen ließe. Statt eines schwachen Ersatzes (Adresse eintippen)
verlangt QuickPoll eine frische Anmeldung bei Google:

1. Einstellungen → „Mit Google bestätigen“ startet den Ablauf im Modus
   `reauth`, mit `prompt=login` und `max_age=0`. Google fragt die Anmeldung
   neu ab, auch wenn dort noch eine Sitzung läuft.
2. Der Callback prüft das ID-Token wie beim Login und zusätzlich:
   - `auth_time` ist vorhanden und höchstens 10 Minuten alt – eine alte
     Google-Sitzung reicht nicht;
   - `sub` ist genau die mit diesem Konto verknüpfte Google-Identität.
3. Erst dann ist das Löschen freigegeben, für 10 Minuten und nur in dieser
   Sitzung.

Fehlt `auth_time`, ist es zu alt oder war es ein anderes Google-Konto, bleibt
das Konto bestehen. Der Ersatz-Anbieter `tests/mock_oidc.php` kann genau diese
Fälle erzeugen (`stale_auth`, `no_auth_time`, anderes Konto).

### Einrichten

1. [Google Cloud Console](https://console.cloud.google.com/) → Projekt anlegen.
2. **APIs & Dienste → OAuth-Zustimmungsbildschirm**: Typ „Extern“, App-Name
   QuickPoll, Support-E-Mail. Bereiche: nur `openid` und `.../auth/userinfo.email`.
   Solange die App im Status „Test“ ist, die eigenen Google-Adressen als Testnutzer eintragen.
3. **APIs & Dienste → Anmeldedaten → Anmeldedaten erstellen → OAuth-Client-ID**,
   Typ „Webanwendung“. Unter „Autorisierte Weiterleitungs-URIs“ exakt eintragen:
   ```
   http://localhost/quickpoll/auth_google_callback.php
   ```
   (bzw. `BASE_URL` + `/auth_google_callback.php`; für die Live-Seite zusätzlich die HTTPS-Adresse)
4. Client-ID und Clientschlüssel in `includes/config.php`:
   ```php
   define('GOOGLE_CLIENT_ID', '1234567890-abc.apps.googleusercontent.com');
   define('GOOGLE_CLIENT_SECRET', 'GOCSPX-…');
   ```
5. Login-Seite neu laden: „Mit Google fortfahren“ erscheint. Ohne beide Werte
   gibt es die Schaltfläche nicht.

Der Clientschlüssel gehört nicht ins Repository (`config.php` steht in `.gitignore`).

**Fehlersuche:** Details stehen nie im Browser, sondern in `storage/logs/php-error.log`
(Zeilen mit `Google sign-in`).
`redirect_uri_mismatch` bei Google heißt: `BASE_URL` und die eingetragene
Weiterleitungs-URI unterscheiden sich (auch `localhost` ≠ `127.0.0.1`, Port, Groß-/Kleinschreibung).
Scheitert der Abruf mit einem Zertifikatsfehler, fehlt PHP das CA-Bundle
(`curl.cainfo` bzw. `openssl.cafile` in `php.ini`; XAMPP liefert eines mit).

### Ohne Google-Projekt ausprobieren

`tests/mock_oidc.php` spielt lokal Google und stellt echte, signierte Tokens aus:

```
C:\xampp\php\php.exe -S 127.0.0.1:8090 tests\mock_oidc.php
```

```php
define('GOOGLE_CLIENT_ID', 'test-client.apps.example');
define('GOOGLE_CLIENT_SECRET', 'test-secret');
define('GOOGLE_TEST_BASE', 'http://127.0.0.1:8090');
```

Wer sich „anmeldet“, steht in einer kleinen JSON-Datei (siehe Kopf der Datei).
`GOOGLE_TEST_BASE` wirkt nur, wenn `APP_ENV` nicht `production` ist.

### Microsoft und Apple

Geprüft, nicht eingebaut:

- **Apple** verlangt ein kostenpflichtiges Entwicklerkonto, einen signierten
  Client-Schlüssel (ES256) und liefert die Antwort per POST. Ohne Konto lässt
  sich das nicht testen.
- **Microsoft** wäre technisch nah an Google, braucht aber eine eigene
  Aussteller-Prüfung je Mandant und eine App-Registrierung in Entra.

Eine Schaltfläche ohne getesteten Ablauf dahinter wäre schlechter als keine.
`nutzer_identitaet.anbieter` und die Aufteilung in `oauth_google.php` lassen
weitere Anbieter zu, ohne das Datenmodell zu ändern.

## E-Mails

| Anlass | an |
|---|---|
| Adresse bestätigen | Kontoadresse |
| Passwort zurücksetzen / festlegen | Kontoadresse |
| Neue Adresse bestätigen | **neue** Adresse |
| Änderung der Adresse angefordert | bisherige Adresse |
| Passwort wurde geändert | Kontoadresse |
| Google-Anmeldung verknüpft | Kontoadresse |

Jede Mail gibt es als Text und als HTML, in der Sprache des Kontos, Arabisch
mit `dir="rtl"`. Im Entwicklungsmodus wird nichts versendet: Der Text steht in
`storage/logs/mail.log`, die HTML-Fassung der letzten Mail in
`storage/logs/mail-preview.html`, und die Seite zeigt den Link an. Diese
Anzeige gibt es nur bei Aufrufen vom eigenen Rechner (`is_local_dev()`), auch
wenn `APP_ENV` versehentlich nicht auf `production` steht.

Produktiv nutzt `send_mail()` PHPs `mail()`. Für zuverlässige Zustellung
(SPF/DKIM) später auf SMTP umstellen; es muss nur diese eine Funktion geändert werden.

## Tests

Im Repository, ohne Datenbank:

```
C:\xampp\php\php.exe tests\run.php
```

`oauth_test.php` erzeugt ein eigenes Schlüsselpaar und prüft die Token-Prüfung
gegen Fälschungen (falscher Schlüssel, falscher Empfänger, abgelaufen, `alg: none`,
HS256 mit dem öffentlichen Schlüssel, geänderter Inhalt, falsche Nonce …).

Die Abläufe mit Datenbank (Registrierung bis Kontolöschung, Google von Start
bis Callback, erneute Anmeldung vor dem Löschen) wurden mit Skripten gegen
einen lokalen Server durchgespielt; sie liegen nicht im Repository. Was
gelaufen ist und mit welchem Ergebnis, steht in `docs/IMPLEMENTATION_REPORT.md`.

## Bekannte Grenzen

- Wer eine fremde Adresse registriert, blockiert sie bis zu 7 Tage (oder bis
  die Inhaberin „Passwort vergessen“ nutzt – der Reset beendet alle Sitzungen
  und übergibt das Konto).
- Die Antwortzeit der Registrierung ist für beide Fälle angeglichen, aber
  nicht gemessen gleich: der Mailversand kann sich um Millisekunden unterscheiden.
- Keine Zwei-Faktor-Anmeldung. Für eine Umfrage-App mit E-Mail-Bestätigung,
  Drosselung, widerrufbaren Sitzungen und optionaler Google-Anmeldung (dort
  greift die Zwei-Faktor-Einstellung des Google-Kontos) wurde sie bewusst
  zurückgestellt; TOTP wäre der nächste Schritt.
- Passwörter werden auf Länge geprüft, nicht gegen Listen bekannter,
  geleakter Passwörter.
- Das Sicherheitsprotokoll wird geschrieben, aber nicht automatisch ausgewertet.
- Echte Google-Server und echter Mailversand ließen sich in der
  Entwicklungsumgebung nicht ansprechen; getestet wurde gegen `tests/mock_oidc.php`
  und das Mail-Log. Vor dem Livegang einmal mit echtem Google-Client und echter
  Mailadresse durchklicken.
