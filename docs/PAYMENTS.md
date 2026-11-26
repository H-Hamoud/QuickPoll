# Zahlung und Abo (Stripe)

Wie der Pro-Tarif abgerechnet wird, was QuickPoll dabei selbst tut und was
Stripe überlässt. Am Ende steht eine Checkliste, mit der sich der ganze
Ablauf im Stripe-Testmodus durchspielen lässt.

## Grundsätze

1. **Keine Kartendaten bei QuickPoll.** Bezahlt wird auf Stripes eigener Seite
   (Checkout), verwaltet im Kundenportal von Stripe. QuickPoll sieht und
   speichert weder Kartennummern noch Bankdaten.
2. **Stripe ist das Original, die Datenbank die Kopie.** Wann immer sich etwas
   geändert haben könnte, fragt QuickPoll bei Stripe den aktuellen Stand ab
   und übernimmt ihn (`billing_sync_user()` → `billing_apply()`).
3. **Dem Browser wird nichts geglaubt.** `?session_id=…` und `?portal=1` lösen
   nur eine Rückfrage bei Stripe aus. Kein Formularfeld und kein URL-Parameter
   kann einen Tarif setzen.
4. **Geheime Schlüssel bleiben auf dem Server.** Secret Key und Webhook-Secret
   stehen in `.env` oder `includes/config.php` (beide nicht in Git) und
   erscheinen nie in HTML, JavaScript oder Logs.

| Datei | Aufgabe |
|---|---|
| `includes/billing.php` | Regeln (welcher Stripe-Status heißt Pro?), Abgleich mit Stripe, Rechnungen, Mails |
| `subscription.php` | Abo-Seite: Stand, Kaufen, Portal, Rechnungen |
| `stripe_webhook.php` | nimmt Ereignisse von Stripe entgegen |
| `database/billing_reconcile.php` | Abgleich aller Abos per Kommandozeile (Cron) |
| `lib/stripe-php/` | offizielle Stripe-Bibliothek (Fremdcode, Version in `lib/stripe-php/VERSION`) |
| `tests/billing_test.php` | Tests der Regeln und der Signaturprüfung |
| `tests/mock_stripe.php` | lokaler Stripe-Ersatz für automatische Tests |

## Einrichten

### 1. Stripe-Konto (Testmodus)

1. Im [Stripe-Dashboard](https://dashboard.stripe.com/) oben den **Testmodus** einschalten.
2. **Produktkatalog → Produkt hinzufügen**: „QuickPoll Pro“, wiederkehrend,
   monatlich, 2,00 €. Die **Preis-ID** (`price_…`) notieren.
3. **Entwickler → API-Schlüssel**: Geheimschlüssel (`sk_test_…`) notieren.
4. **Einstellungen → Billing → Kundenportal** aktivieren und erlauben:
   Zahlungsmethode aktualisieren, Rechnungsverlauf, Abo kündigen
   (**zum Ende des Abrechnungszeitraums**). Ohne diese Einstellung öffnet
   sich das Portal nicht.
5. Optional **Einstellungen → Billing → Abos und E-Mails**: Wie oft Stripe eine
   fehlgeschlagene Zahlung wiederholt und was danach passiert („Abo kündigen“
   oder „als unbezahlt markieren“). QuickPoll kommt mit beidem zurecht.

### 2. Schlüssel eintragen

In `.env` (Vorlage: `.env.example`) oder in `includes/config.php`:

```
STRIPE_SECRET_KEY=sk_test_…
STRIPE_PRICE_ID=price_…
STRIPE_WEBHOOK_SECRET=whsec_…
```

`STRIPE_PUBLISHABLE_KEY` wird nicht gebraucht: QuickPoll bindet kein
Stripe-JavaScript ein, die Bezahlseite liegt vollständig bei Stripe.

Der angezeigte Preis (2 € pro Monat) steht in `subscription.php`; er muss zum
Preis bei Stripe passen. Abgebucht wird immer, was bei Stripe hinterlegt ist.

### 3. Webhook

**Lokal** mit der [Stripe CLI](https://docs.stripe.com/stripe-cli):

```
stripe login
stripe listen --forward-to localhost/quickpoll/stripe_webhook.php
```

Die CLI zeigt ein `whsec_…` an; das ist `STRIPE_WEBHOOK_SECRET` für die lokale Entwicklung.

**Auf dem Server**: Entwickler → Webhooks → Endpunkt hinzufügen,
`https://DOMAIN/stripe_webhook.php`, mit diesen Ereignissen:

```
checkout.session.completed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
invoice.paid
invoice.payment_failed
invoice.upcoming
customer.deleted
```

Das Signatur-Secret des Endpunkts ist das `STRIPE_WEBHOOK_SECRET` des Servers.
Wer von einem älteren Stand kommt: Der Endpunkt braucht jetzt auch die
`invoice.*`-Ereignisse.

## Preise und Tarife

Die Abo-Seite zeigt die Preise so, wie sie **bei Stripe** hinterlegt sind –
Betrag, Währung und Intervall stehen nicht im Code.

- `STRIPE_PRICE_ID` ist der Monatspreis. Optional nennt
  `STRIPE_PRICE_ID_YEARLY` einen zweiten, jährlichen Preis desselben Produkts;
  dann bietet die Seite beide an.
- `billing_plans()` fragt die Preise bei Stripe ab und merkt sie sich eine
  Stunde in `storage/cache/stripe_prices.json`. Ist Stripe nicht erreichbar,
  gilt die letzte bekannte Antwort; gibt es keine, ein fest hinterlegter
  Ersatzwert. Ein inaktiver oder einmaliger Preis wird übersprungen und im
  Fehlerlog gemeldet. Nach einer Preisänderung bei Stripe dauert es also bis
  zu einer Stunde, bis die Seite sie zeigt (oder die Cache-Datei löschen).
- Das Kaufformular schickt nur `plan=month` oder `plan=year`. Die Preis-ID
  dazu kommt aus der Konfiguration; eine vom Browser mitgeschickte Preis-ID
  wird nie verwendet.
- Was ein Konto tatsächlich zahlt, steht nach dem Abgleich in
  `nutzer.abo_preis_cent`, `abo_waehrung`, `abo_intervall` – gelesen aus dem
  Abo bei Stripe, nicht aus dem aktuellen Angebot. Wer zu einem alten Preis
  abgeschlossen hat, sieht weiter seinen Preis.
- **Wechsel monatlich ↔ jährlich** läuft über das Kundenportal
  (`flow_data.type = subscription_update`). Dafür muss im Stripe-Dashboard
  unter Kundenportal „Abos wechseln“ eingeschaltet und beide Preise müssen
  dort zugelassen sein. Wie anteilig abgerechnet wird, entscheidet Stripe
  nach dieser Einstellung. Ohne die Einstellung öffnet QuickPoll das Portal
  ohne den direkten Einstieg.

Zwei Schalter für Stripes Kassenseite:

| Einstellung | Wirkung | Voraussetzung bei Stripe |
|---|---|---|
| `STRIPE_PROMO_CODES=1` | Feld für Gutscheincodes (`allow_promotion_codes`) | Gutscheine und Aktionscodes im Dashboard anlegen |
| `STRIPE_AUTOMATIC_TAX=1` | Steuer über Stripe Tax (`automatic_tax`, Adresse wird am Kunden gespeichert) | Stripe Tax einrichten (Ursprungsadresse, Registrierungen); sonst lehnt Stripe den Checkout ab |

Beide sind aus, solange sie niemand einschaltet. QuickPoll rechnet selbst
keine Steuern und prüft keine Gutscheine – es reicht die Schalter nur durch.

## Ablauf beim Kauf

```
Browser                QuickPoll                               Stripe
  │ „Pro abonnieren“ (POST, CSRF)                                 │
  │ ─────────────────► Kunde anlegen (einmalig), Checkout-Session ►│
  │ ◄───────────────── 302 zur Bezahlseite                         │
  │ ──────────────────────────────────────────────────────────────►│ Karte, 3-D Secure …
  │                    ◄──────────── Webhook: checkout.session.completed, invoice.paid
  │                    Stand bei Stripe abfragen → Datenbank
  │ ◄────────────────────────────────────────── 302 ?session_id=…  │
  │ ─────────────────► Session bei Stripe abrufen:                 │
  │                    gehört sie zu diesem Konto? abgeschlossen?  │
  │                    Stand bei Stripe abfragen → Datenbank       │
  │ ◄───────────────── Abo-Seite                                   │
```

Webhook und Rückkehr des Browsers können in beliebiger Reihenfolge eintreffen;
beide führen denselben Abgleich aus. Der Stripe-Kunde wird **vor** dem Checkout
angelegt und im Konto gespeichert, damit sich jedes spätere Ereignis über die
Kunden-ID zuordnen lässt (`UNIQUE` auf `nutzer.stripe_customer_id`).

Voraussetzung für den Kauf ist eine bestätigte E-Mail-Adresse. Ein zweites Abo
für dasselbe Konto lehnt der Server ab.

## Zustände eines Abos

`nutzer.stripe_status` hält Stripes Status fest, `nutzer.abo_status`
(`aktiv`/`inaktiv`) die Folgerung daraus: Hat das Konto Pro?

| Stripe-Status | Pro-Zugriff | Anzeige | Mail |
|---|---|---|---|
| `trialing` | ja | Testphase, erste Abbuchung am … | – |
| `active` | ja | Aktiv, nächste Abbuchung am … | Zahlung erhalten / Abo verlängert |
| `active` + Kündigung vorgemerkt | **ja, bis zum Enddatum** | Gekündigt, Pro aktiv bis … | Kündigung bestätigt |
| `past_due` | ja (Schonfrist, Stripe versucht es erneut) | Zahlung offen | Zahlung fehlgeschlagen |
| `unpaid`, `paused` | nein | Pausiert | Abo beendet |
| `incomplete` | nein | Unvollständig, erneut versuchen | – |
| `incomplete_expired`, `canceled` | nein | Beendet, wieder abonnieren | Abo beendet |

Eine vorgemerkte Kündigung nimmt den Zugriff nicht weg. `abo_endet_am` hält
das Datum fest; der Status bleibt `active`, bis Stripe das Abo selbst beendet
(`customer.subscription.deleted`). Als Sicherheitsnetz endet der Zugriff einen
Tag nach diesem Datum auch dann, wenn dieses letzte Ereignis nie ankommt
(`is_pro()` in `includes/security.php`).

Nach dem Ende gelten wieder die Grenzen des Free-Tarifs. Umfragen und
Antworten bleiben erhalten; es lassen sich nur keine weiteren anlegen, solange
mehr als drei existieren.

Hat ein Kunde mehrere Abos bei Stripe (abgebrochener erster Versuch neben dem
bezahlten), zählt das mit Zugriff, sonst das neueste (`billing_pick_subscription()`).

## Webhook

`stripe_webhook.php` in vier Schritten:

1. **Signatur prüfen** mit `\Stripe\Webhook::constructEvent()`: falsches
   Secret, veränderter Inhalt oder älter als 5 Minuten → `400`.
2. **Schon gesehen?** Jede Ereignis-ID kommt in `stripe_ereignis`
   (Primärschlüssel). Eine zweite Zustellung wird mit `200` beantwortet und
   ändert nichts, auch wenn beide gleichzeitig eintreffen.
3. **Aktuellen Stand holen**: Der Inhalt des Ereignisses wird nicht kopiert.
   QuickPoll fragt die Abos des Kunden bei Stripe ab. Verspätete oder
   vertauschte Ereignisse können deshalb keinen älteren Stand zurückbringen.
4. **Eine Transaktion** für Ereignis-ID und neuen Stand (`SELECT … FOR UPDATE`
   auf das Konto). Ist Stripe in Schritt 3 nicht erreichbar, wird nichts
   gespeichert und mit `500` geantwortet; Stripe stellt dann erneut zu.

Mails gehen erst nach dem Commit hinaus. Unbekannte Kunden und nicht benötigte
Ereignistypen werden mit `200` quittiert und als `ignoriert` vermerkt.

## Wenn ein Webhook fehlt

- Die Abo-Seite gleicht beim Öffnen selbst ab, wenn der letzte Abgleich älter
  als ein Tag ist oder der bezahlte Zeitraum abgelaufen ist.
- Für den Server ein täglicher Cron-Job:
  ```
  php database/billing_reconcile.php
  ```
  Er prüft alle Konten mit Stripe-Kunde, deren Zeitraum abgelaufen oder deren
  letzter Abgleich älter als ein Tag ist (`--all` prüft alle).

## Abo verwalten

Alles Weitere läuft im Kundenportal von Stripe; QuickPoll öffnet es über eine
kurzlebige Portal-Sitzung für genau diesen Kunden:

| Schaltfläche | öffnet |
|---|---|
| Zahlungsmethode ändern / aktualisieren | Portal direkt bei der Zahlungsmethode |
| Kündigen | Portal direkt bei der Kündigung |
| Abo verwalten, Kündigung zurücknehmen | Startseite des Portals (Rechnungen, Zahlungsmethoden, Abo fortsetzen) |

Nach der Rückkehr fragt QuickPoll den Stand ab und zeigt, was sich geändert
hat. Die Rechnungen auf der Abo-Seite kommen bei jedem Aufruf direkt von
Stripe und verlinken auf Stripes Rechnungsseite.

Es gibt genau einen bezahlten Tarif; ein Tarifwechsel ist deshalb nicht
eingebaut. Wird das Konto gelöscht, kündigt QuickPoll das Abo vorher bei
Stripe; gelingt das nicht, wird nicht gelöscht. Ändert sich die
E-Mail-Adresse des Kontos, wird sie beim Stripe-Kunden nachgezogen.

## E-Mails

| Mail | Auslöser |
|---|---|
| Zahlung erhalten, Pro ist aktiv | `invoice.paid` der ersten Rechnung (Betrag > 0) |
| Abo wurde verlängert | `invoice.paid` einer Verlängerung |
| Abo verlängert sich bald | `invoice.upcoming` (Vorlauf in Stripe einstellbar), nicht bei vorgemerkter Kündigung |
| Zahlung fehlgeschlagen | `invoice.payment_failed` einer Verlängerung |
| Kündigung bestätigt | Enddatum taucht zum ersten Mal auf |
| Abo beendet | Pro-Zugriff endet |

Alle in der Sprache des Kontos (Deutsch, Englisch, Arabisch), als Text und HTML.
Jede Mail geht einmal hinaus: Ereignisse werden nur einmal verarbeitet, und
„Kündigung“ sowie „Ende“ hängen am Zustandswechsel in der Datenbank, nicht an
einem bestimmten Ereignis. Stripe kann zusätzlich eigene Belege verschicken
(Einstellungen → E-Mails); dann die eigenen Mails oder die von Stripe abschalten.

## Checkliste für den Stripe-Testmodus

Diese Schritte brauchen ein echtes Stripe-Testkonto und sind **von Hand
durchzugehen**; in der Entwicklungsumgebung dieses Projekts war Stripe nicht
erreichbar (siehe „Tests“).

| # | Schritt | Erwartung |
|---|---|---|
| 1 | Mit bestätigtem Konto „Pro abonnieren“, Karte `4242 4242 4242 4242` | Rückkehr auf die Abo-Seite, Stempel „Aktiv“, Rechnung in der Liste, Mail „Zahlung erhalten“ |
| 2 | Im Dashboard unter Entwickler → Ereignisse ein Ereignis **erneut senden** | Antwort `200 duplicate`, keine zweite Mail |
| 3 | Zweites Konto, Karte `4000 0000 0000 0002` (abgelehnt) | Stripe zeigt den Fehler, Konto bleibt Free |
| 4 | Karte `4000 0025 0000 3155` (3-D Secure) | nach der Bestätigung „Aktiv“ |
| 5 | „Kündigen“ im Portal | „Gekündigt“, „Pro aktiv bis …“, CSV-Export geht weiter, Mail „Kündigung bestätigt“ |
| 6 | „Kündigung zurücknehmen“ im Portal | wieder „Aktiv“ mit nächster Abbuchung |
| 7 | Abo mit Karte `4000 0000 0000 0341` (lässt sich hinterlegen, Abbuchung schlägt fehl) und eine **Testuhr** (Billing → Testuhren) einen Monat vorstellen | „Zahlung offen“, Pro bleibt vorerst, Mail „Zahlung fehlgeschlagen“ |
| 8 | Testuhr weiter vorstellen, bis Stripe aufgibt | „Beendet“ oder „Pausiert“, Free-Grenzen gelten, Mail „Abo beendet“ |
| 9 | Gekündigtes Abo: Testuhr über das Enddatum stellen | „Beendet“, „Pro wieder abonnieren“ |
| 10 | Webhook-Weiterleitung stoppen, im Portal kündigen, Abo-Seite öffnen | Stand stimmt trotzdem (Abgleich bei der Rückkehr) |
| 11 | `php database/billing_reconcile.php --all` | „corrected 0“ |
| 12 | Konto mit aktivem Abo löschen | Abo ist im Dashboard gekündigt |

`stripe trigger invoice.payment_failed` und ähnliche Befehle der CLI erzeugen
Ereignisse für **neue** Testkunden; QuickPoll quittiert sie als „unknown customer“.

## Tests

**Im Repository** (`php tests/run.php`, ohne Datenbank und ohne Netz):
`billing_test.php` prüft, welcher Stripe-Status Pro bedeutet, dass eine
vorgemerkte Kündigung den Zugriff behält, die Auswahl bei mehreren Abos,
welche Zustandswechsel welche Mail auslösen, beide Formen des Periodenendes
(alte und neue Stripe-API) und die Signaturprüfung der Stripe-Bibliothek
(falsches Secret, veränderter Inhalt, Wiederholung nach Ablauf).

**Abläufe**: Beim Bau wurde der ganze Lebenszyklus gegen `tests/mock_stripe.php`
durchgespielt, mit der echten Stripe-Bibliothek und echt signierten Webhooks:
Kauf, fremde und erfundene Session-IDs, doppelte und gleichzeitige Zustellung,
verspätete Ereignisse, fehlgeschlagene Verlängerung bis „unpaid“ und zurück,
Kündigung, Rücknahme, Ende, erneuter Kauf, Testphase, abgelehnte erste Zahlung,
Ausfall von Stripe, Abgleich ohne Webhook, Kontolöschung; dazu die
Preisauswahl (erfundene Preis-IDs im Formular werden ignoriert, `plan=year`
nimmt den konfigurierten Jahrespreis, Gutschein- und Steuer-Schalter kommen
bei „Stripe“ an) und der Wechsel des Intervalls über das Portal. Der Ersatz
ist **nicht Stripe**: Er kennt zwei Preise, rechnet keine Steuern, prüft keine
Gutscheine, kennt keine anteilige Abrechnung und glaubt jede „Zahlung“. Was
echtes Stripe tut, zeigt nur die Checkliste oben. Ergebnisse der Läufe:
`docs/IMPLEMENTATION_REPORT.md`.

Der Ersatz lässt sich auch selbst starten (Kopf von `tests/mock_stripe.php`);
`STRIPE_TEST_BASE` wirkt nur, wenn `APP_ENV` nicht `production` ist.

## Vor dem Livegang

- Live-Schlüssel (`sk_live_…`), Live-Preis-ID und eigenen Live-Webhook eintragen
- Kundenportal auch im Livemodus aktivieren
- HTTPS (Stripe stellt Webhooks nur an HTTPS-Adressen zu)
- Cron-Job für `database/billing_reconcile.php` (täglich) und `database/maintenance.php`
- Im Kundenportal „Abos wechseln“ nur einschalten, wenn ein Jahrespreis konfiguriert ist
- `impressum.php`/`datenschutz.php` und Stripes Angaben (Firmenname, Support-Adresse) ausfüllen
- Mailversand prüfen (`docs/AUTH.md`)

## Bekannte Grenzen

- Ein bezahlter Tarif (Pro) in höchstens zwei Intervallen, eine Währung je Preis.
- Jahrespreis, Intervallwechsel, Gutscheinfeld und Stripe Tax sind nur gegen
  den Stripe-Ersatz geprüft. Vor dem Einschalten im Testmodus von Stripe
  durchspielen – besonders Stripe Tax, das ohne Einrichtung den Checkout
  ablehnt.
- Steuern sind eine rechtliche Frage (Kleinunternehmer, Umsatzsteuer, OSS).
  Der Schalter ersetzt keine Klärung mit Steuerberatung.
- Erstattungen und Zahlungsstreitfälle werden im Stripe-Dashboard bearbeitet;
  QuickPoll reagiert nicht eigens darauf.
- Wird das Konto gelöscht, bleibt der Kunde mit seinen Rechnungen bei Stripe
  bestehen (Aufbewahrungspflichten); nur das Abo wird beendet.
- Andere Zahlungsanbieter sind bewusst nicht eingebaut. Zahlungsarten wie
  SEPA-Lastschrift oder PayPal lassen sich in Stripe für Checkout einschalten,
  ohne dass sich am Code etwas ändert.
- Gegen das echte Stripe ist dieser Stand nicht gelaufen (siehe „Tests“).
