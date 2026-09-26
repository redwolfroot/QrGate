# Stripe für QrGate einrichten

**Anleitung für Vereine und Privatpersonen in Österreich** · Stand: September 2026

Mit Stripe können Ihre Besucher im QrGate-Shop online mit Karte bezahlen (Visa, Mastercard, Amex, dazu Apple Pay und Google Pay). Das Geld geht von Stripe auf Ihr Bankkonto. Sie brauchen dafür **kein Unternehmen**: Stripe akzeptiert Vereine, Personengesellschaften und Einzelpersonen.

Diese Anleitung deckt nur die **Stripe-Seite** ab. Am Ende haben Sie zwei Schlüssel, die Sie in QrGate eintragen.

> **Zeitplan:** Planen Sie die Einrichtung **mindestens zwei Wochen vor dem Vorverkaufsstart** ein. Stripe prüft Ihre Angaben, fragt manchmal Dokumente nach, und die erste Auszahlung kommt erst nach etwa einer Woche (siehe [Auszahlungen](#auszahlungen)).

---

## Kurzfassung

- [ ] 1. Stripe-Konto anlegen und Zwei-Faktor-Anmeldung einschalten
- [ ] 2. Konto aktivieren (Verein oder Einzelperson, Bankverbindung, Ausweis)
- [ ] 3. Öffentliche Angaben und Abrechnungsbeschreibung setzen
- [ ] 4. Kartenzahlung, Apple Pay und Google Pay prüfen
- [ ] 5. Shop-Domain für Apple Pay eintragen
- [ ] 6. **Testschlüssel** holen, in QrGate eintragen, Testkauf und Test-Storno machen
- [ ] 7. **Live-Schlüssel** holen, in QrGate eintragen
- [ ] 8. Einmal echt kaufen und wieder stornieren

---

## Vorab: Was Sie wissen sollten

| Thema | Stand |
| --- | --- |
| **Kosten** | Keine Grund- oder Monatsgebühr. Pro Kartenzahlung: **1,5 % + 0,25 €** (Standardkarten aus dem EWR), 2,8 % + 0,25 € (Premiumkarten aus dem EWR), 2,5 % + 0,25 € (Karten aus dem Vereinigten Königreich), 3,15 % + 0,25 € (übrige ausländische Karten), jeweils plus 2 % bei Währungsumrechnung. Rückerstattungen kosten nichts extra. Eine **Zahlungsanfechtung** (Chargeback) kostet 20 € pro Fall. Aktuelle Preise: [stripe.com/at/pricing](https://stripe.com/at/pricing) |
| **Was das bei Tickets heißt** | 5 €-Ticket ≈ 0,33 € Gebühr (6,5 %) · 12 €-Ticket ≈ 0,43 € (3,6 %) · 25 €-Ticket ≈ 0,63 € (2,5 %). Der feste Anteil von 0,25 € fällt bei günstigen Tickets stark ins Gewicht. Sie können ihn nicht senken, aber in den Ticketpreis einrechnen. |
| **Währung und Mindestbetrag** | QrGate rechnet in **Euro** ab. Eine Bestellung muss mindestens **0,50 €** kosten. |
| **Zahlungsarten** | Nur **Karte** (inklusive Apple Pay und Google Pay). eps-Überweisung, Klarna, PayPal und Ähnliches gibt es im QrGate-Shop nicht. Bar an der Abendkasse ist unabhängig von Stripe weiterhin möglich. |
| **Wie abgebucht wird** | QrGate reserviert den Betrag zuerst nur auf der Karte und zieht ihn erst ein, wenn die Tickets angelegt sind. Scheitert etwas dazwischen, wird die Reservierung aufgehoben und es fließt kein Geld. |
| **Erstattungen** | Storniert ein Besucher über den Link in seiner Ticket-E-Mail (bis 24 Stunden vor der Veranstaltung) oder storniert die Kasse bzw. der Admin ein bezahltes Ticket, erstattet QrGate den Betrag **automatisch und vollständig** über Stripe. Die ursprüngliche Stripe-Gebühr bekommen Sie in der Regel nicht zurück. |

> **Recht und Steuern:** Ob Ihre Ticketeinnahmen umsatzsteuerpflichtig sind (Stichwort Kleinunternehmerregelung), ob sie beim Verein steuerlich begünstigt sind oder als wirtschaftlicher Geschäftsbetrieb gelten, ob eine Privatperson mit regelmäßigem Ticketverkauf eine Gewerbeberechtigung braucht und ob Ihre Veranstaltung bei der Gemeinde bzw. Landesbehörde angemeldet werden muss oder Abgaben (z. B. an die Gemeinde) und AKM-Gebühren bei Musik anfallen, entscheidet nicht Stripe und auch nicht QrGate. Klären Sie das vor dem Start mit dem Finanzamt Österreich, einer Steuerberatung oder der Beratung Ihres Dachverbands.

---

## 1. Was Sie bereithalten sollten

**Verein (im Zentralen Vereinsregister eingetragen)**

- Vollständiger Vereinsname und Sitz **genau so, wie sie im Zentralen Vereinsregister (ZVR) stehen** (Stripe gleicht 1:1 ab)
- **ZVR-Zahl**
- Name, Geburtsdatum und Privatadresse einer **vertretungsbefugten Person laut Statuten** (z. B. Obfrau/Obmann oder Kassierin/Kassier); je nach Statuten fragt Stripe weitere Funktionärinnen und Funktionäre ab
- **Vereinskonto** (IBAN), das auf den Vereinsnamen läuft
- Foto von Reisepass, Personalausweis oder Führerschein der vertretungsbefugten Person
- Website (Ihre Shop-Adresse) und eine Support-E-Mail-Adresse
- Steuernummer des Vereins, falls Stripe danach fragt
- Falls Stripe Nachweise anfordert: **Auszug aus dem Vereinsregister** (Stripe akzeptiert ihn ausdrücklich, Name und Anschrift müssen erkennbar sein) und die **Statuten**. Informationen zum ZVR finden Sie auf [oesterreich.gv.at](https://www.oesterreich.gv.at/de/lexicon/Z/Seite.991732).

**Gruppe ohne Vereinsgründung** (z. B. Theatergruppe, Elterninitiative)

Eine Gruppe, die nie als Verein angezeigt wurde, ist rechtlich kein Verein und hat im ZVR keinen Eintrag. Dann bleiben drei Wege:

- **Ein Konto auf eine Einzelperson** (siehe „Privatperson“). Beachten Sie: Das Geld gehört dann rechtlich dieser Person, auch steuerlich.
- **Ein Konto für mehrere Personen als Personengesellschaft** (z. B. GesbR). Stripe verlangt dann einen Nachweis mit Name und Anschrift der Gesellschaft; was genau zählt, sagt Stripe Ihnen im Dashboard.
- **Einen Verein gründen.** Das dauert Wochen und ist für einen nahen Termin meist zu spät.

**Privatperson**

Stripe kennt keinen Typ „Privatperson“. Sie wählen **Einzelperson / Einzelunternehmen** (englisch *Individual / Sole proprietor*). Dafür brauchen Sie:

- Vollständigen Namen, Geburtsdatum, Wohnanschrift, Telefonnummer
- Foto von Reisepass, Personalausweis oder Führerschein
- Eine **eigene IBAN** (Konto auf Ihren Namen)
- Website (Ihre Shop-Adresse) und E-Mail-Adresse
- Steuernummer bzw. UID (`ATU…`), falls Stripe danach fragt
- Falls Stripe einen Adressnachweis verlangt: z. B. Meldezettel, aktuellen Kontoauszug oder eine aktuelle Rechnung eines Versorgers

Für alle gilt: Die Angaben in Stripe müssen mit Ausweis, Register und Bankkonto **exakt übereinstimmen** (Schreibweise, Adresse). Die meisten Verzögerungen entstehen durch kleine Abweichungen.

---

## 2. Konto anlegen

1. Öffnen Sie [dashboard.stripe.com/register](https://dashboard.stripe.com/register).
2. Geben Sie E-Mail-Adresse, Namen und ein **eigenes Passwort** ein, das Sie sonst nirgends verwenden. Als Land wählen Sie **Österreich**.
3. Bestätigen Sie Ihre E-Mail-Adresse über den Link, den Stripe schickt.
4. Schalten Sie sofort die **Zwei-Faktor-Anmeldung** ein: [dashboard.stripe.com/settings/user](https://dashboard.stripe.com/settings/user). Stripe empfiehlt Passkey oder Authenticator-App; SMS nur als Notlösung.

> **Wichtig:** Das **Land** des Kontos lässt sich nach der Aktivierung nicht mehr ändern. Wählen Sie Österreich (oder das Land, in dem Verein bzw. Person tatsächlich ansässig ist).

Nach dem Anlegen können Sie Stripe schon testen (siehe Schritt 6), ohne dass das Konto aktiviert ist. **Echte Zahlungen** sind erst nach der Aktivierung möglich.

---

## 3. Konto aktivieren

Gehen Sie auf [dashboard.stripe.com/account/onboarding](https://dashboard.stripe.com/account/onboarding). Stripe führt Sie durch mehrere Seiten. Die Wortlaute ändern sich gelegentlich, die Reihenfolge ist im Kern immer gleich.

### 3.1 Rechtsform wählen

| Sie sind … | Wählen Sie … |
| --- | --- |
| Verein (im ZVR eingetragen) | **Gemeinnützige Organisation / Verein** (englisch *Non-profit* bzw. *Association*). Wählen Sie den Eintrag, der Ihrer Rechtsform am nächsten kommt. Steht dort eine Auswahl der Struktur, nehmen Sie **Sonstige**. |
| Mehrere Personen ohne Verein | **Personengesellschaft** (*Partnership*), z. B. GesbR |
| Privatperson | **Einzelunternehmen / Einzelperson** (*Individual / Sole proprietor*) |

Falls Sie versehentlich den falschen Typ gewählt haben: Die Rechtsform lässt sich später in den Kontoeinstellungen anpassen. Bei größeren Fehlern ist es einfacher, ein neues Konto anzulegen, solange noch nichts aktiviert ist.

### 3.2 Angaben zur Organisation bzw. Person

- **Verein:** Name, ZVR-Zahl, Sitz und Anschrift
- **Person:** Name, Geburtsdatum, Wohnanschrift

### 3.3 Angaben zum Geschäft

- **Branche:** Wählen Sie etwas wie *Veranstaltungen / Ticketverkauf* (*Events, Ticketing*).
- **Produktbeschreibung:** z. B. „Eintrittskarten für unsere Theateraufführungen und Konzerte“. Nennen Sie ehrlich, was Sie verkaufen.
- **Website:** Die Adresse Ihres QrGate-Shops. Der Shop muss dafür bereits erreichbar sein und Ihren Verein bzw. Ihre Veranstaltung erkennbar nennen. Auf der Seite sollten Kontakt, Impressum und Datenschutzhinweise vorhanden sein.
- **Erwartetes Volumen:** Schätzen Sie realistisch. Für einen Vereinsabend genügt eine kleine Zahl.

### 3.4 Vertretungsbefugte Person

Die Person, die das Konto verantwortet (beim Verein z. B. Obfrau/Obmann oder Kassierin/Kassier, sonst Sie selbst): Name, Geburtsdatum, Privatanschrift, Telefon, E-Mail. Stripe fragt bei Vereinen ggf. nach, ob es weitere vertretungsbefugte Personen gibt.

### 3.5 Identitätsnachweis

Stripe verlangt ein Foto von Reisepass, Personalausweis oder Führerschein, oft per Handykamera. Achten Sie auf gutes Licht und darauf, dass nichts abgeschnitten ist.

> **So werden Dokumente angenommen:** in **Farbe**, lesbar und vollständig (bei Ausweisen mit Rückseite beide Seiten), **nicht abgelaufen**, als **PDF** (Scan) oder **JPEG/PNG** (Foto). **Keine Screenshots.** Der Name muss genau mit den Angaben im Konto übereinstimmen.
>
> Weitere Unterlagen laden Sie **nur im Dashboard** hoch, unter [Einstellungen → Unternehmen → Kontostatus](https://dashboard.stripe.com/account/status). Schicken Sie Dokumente nie per E-Mail.

### 3.6 Bankkonto für Auszahlungen

- IBAN eintragen (Konto in Österreich bzw. im SEPA-Raum, üblicherweise `AT…`). Kontoinhaber ist beim Verein **der Verein**, bei der Einzelperson **Sie selbst**.
- Stripe kann eine kleine Testüberweisung oder einen Kontoauszug zur Bestätigung anfordern.

### 3.7 Öffentliche Angaben und Abrechnungsbeschreibung

Unter [Einstellungen → Öffentliche Angaben](https://dashboard.stripe.com/settings/public) (Business → Public details):

- **Öffentlicher Name:** Vereins- oder Veranstaltungsname, so wie Ihre Besucher ihn kennen
- **Support-E-Mail und -Telefon:** erscheint auf Belegen
- **Abrechnungsbeschreibung** (*Statement descriptor*, max. 22 Zeichen): Das steht auf dem Kontoauszug des Käufers, z. B. `MV MUSTERDORF TICKETS` oder `THEATERVEREIN GRAZ`.

> **Warum das wichtig ist:** Erkennt ein Käufer die Abbuchung nicht wieder, stellt er sie bei seiner Bank in Frage. Das kostet 20 € Gebühr und Ärger, auch wenn die Zahlung korrekt war. Ein klarer Name verhindert das.

### 3.8 Wartezeit

Nach dem Absenden prüft Stripe Ihre Angaben, meist innerhalb weniger Tage. Achten Sie auf E-Mails von Stripe und im Dashboard auf Hinweise wie „Weitere Informationen erforderlich“. Reichen Sie Nachweise **zügig** ein, sonst verzögern sich Auszahlungen.

> **Stripe fragt nie per E-Mail, Telefon oder Chat nach Ihren API-Schlüsseln oder Ihrem Passwort.** Wer das tut, ist nicht von Stripe.

---

## 4. Kartenzahlung, Apple Pay und Google Pay prüfen

1. Öffnen Sie [Einstellungen → Zahlungsmethoden](https://dashboard.stripe.com/settings/payment_methods) (*Settings → Payment methods*).
2. Stellen Sie sicher, dass **Karten** aktiviert ist (ist es standardmäßig).
3. **Apple Pay** und **Google Pay** sollten ebenfalls aktiv sein. Beide laufen über die normale Karte und kosten nicht extra.

Andere Zahlungsmethoden in dieser Liste (EPS, Klarna, PayPal …) müssen Sie **nicht** aktivieren. QrGate bietet ohnehin nur Karte an.

---

## 5. Shop-Domain für Apple Pay eintragen

Damit der Apple-Pay-Knopf im Shop erscheint, muss Stripe die Adresse Ihres Shops kennen. Google Pay lässt sich damit ebenfalls freischalten.

1. Öffnen Sie [Einstellungen → Zahlungsmethoden-Domains](https://dashboard.stripe.com/settings/payment_method_domains).
2. Klicken Sie auf **Neue Domain hinzufügen**.
3. Tragen Sie die Adresse Ihres Shops **ohne** `https://` und ohne Pfad ein, z. B. `tickets.mein-verein.at`.
4. **Speichern und fortfahren**.

Nutzen Sie mehrere Schreibweisen (z. B. mit und ohne `www.`), tragen Sie jede einzeln ein. Der Shop muss über **HTTPS** laufen.

Ohne diesen Schritt funktioniert die normale Karteneingabe trotzdem. Es fehlt nur der Apple-Pay-Knopf.

---

## 6. Testschlüssel holen und alles ausprobieren

Stripe hat zwei getrennte Welten: einen **Testbereich** (*Sandbox*, früher „Testmodus“), in dem nur Spielgeld fließt, und den **Live-Modus** mit echtem Geld. Jede Welt hat eigene Schlüssel. Testen Sie **immer zuerst im Testbereich**.

### 6.1 Testschlüssel anzeigen

1. Öffnen Sie [dashboard.stripe.com/test/apikeys](https://dashboard.stripe.com/test/apikeys). Steht dort oben nicht „Sandbox“ bzw. „Test-Modus“, wechseln Sie dorthin (Kontoname oben links → Sandbox wählen).
2. Sie sehen zwei Schlüssel:
   - **Veröffentlichbarer Schlüssel** (*Publishable key*), beginnt mit `pk_test_`
   - **Geheimer Schlüssel** (*Secret key*), beginnt mit `sk_test_`. In der Sandbox können Sie ihn jederzeit einblenden.

### 6.2 In QrGate eintragen

1. In QrGate als Admin anmelden → links im Menü **Zahlung**.
2. **Publishable Key** und **Secret Key** einfügen.
3. Das Feld **Webhook Secret** **leer lassen** (wird nicht gebraucht).
4. **Speichern**. Oben erscheint jetzt das Kennzeichen **TESTMODUS**.
5. Unter **Veranstaltung → Verkauf → Zahlungsarten im Shop** wählen Sie **Karte und Bar an der Abendkasse** oder **Nur Karte**. Kartenzahlung erscheint im Shop nur, wenn beide Schlüssel eingetragen sind und diese Einstellung Karte enthält.

### 6.3 Testkauf im Shop

Buchen Sie im Shop ein Ticket und zahlen Sie mit einer Testkarte. Verwenden Sie **beliebige** Werte für Ablaufdatum (in der Zukunft), Prüfnummer (CVC) und Postleitzahl.

| Kartennummer | Verhalten |
| --- | --- |
| `4242 4242 4242 4242` | Zahlung gelingt |
| `4000 0027 6000 3184` | Zahlung gelingt nach zusätzlicher Bestätigung (3-D Secure, wie bei echten österreichischen Banken) |
| `4000 0000 0000 0002` | Karte wird abgelehnt |

Prüfen Sie:

- [ ] Die Bestellung geht durch, das Ticket kommt per E-Mail an.
- [ ] Bei der abgelehnten Karte erscheint eine Fehlermeldung, es wird **kein** Ticket erzeugt.
- [ ] Im Stripe-Dashboard unter [Zahlungen](https://dashboard.stripe.com/test/payments) steht die Zahlung als **Erfolgreich**.

### 6.4 Test-Storno

Stornieren Sie das Testticket über den Link in der E-Mail (oder im Admin). In Stripe unter Zahlungen muss die Zahlung jetzt als **Erstattet** erscheinen, und der Besucher bekommt die Storno-E-Mail mit dem Hinweis auf die Erstattung. Meldet die Storno-E-Mail stattdessen, die automatische Erstattung habe nicht geklappt, stimmt etwas mit dem Secret Key nicht (siehe [Probleme](#häufige-probleme)).

---

## 7. Live gehen

Sobald Stripe Ihr Konto aktiviert hat und die Tests sauber liefen:

### 7.1 Live-Schlüssel anzeigen

1. Öffnen Sie [dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys) im **Live-Modus** (nicht Sandbox).
2. Kopieren Sie den **Veröffentlichbaren Schlüssel** (`pk_live_…`).
3. Beim geheimen Schlüssel haben Sie zwei Möglichkeiten:

**Variante A: Standard-Geheimschlüssel (einfach)**

Klicken Sie in der Liste **Standardschlüssel** auf **Live-Schlüssel anzeigen**, bestätigen Sie ggf. mit Ihrer Zwei-Faktor-Anmeldung und kopieren Sie den Wert (`sk_live_…`). Dieser Schlüssel darf in Ihrem Stripe-Konto alles.

**Variante B: Eingeschränkter Schlüssel (sicherer, empfohlen, wenn Dritte den Schlüssel eintragen)**

Ein eingeschränkter Schlüssel (*Restricted key*) darf nur, was Sie erlauben. Gelangt er in falsche Hände, kann damit weder Geld ausgezahlt noch das Konto verändert werden.

1. Auf der Seite API-Schlüssel: **Eingeschränkten Schlüssel erstellen**.
2. Name vergeben, z. B. `QrGate Shop`.
3. Berechtigungen setzen. Alles andere bleibt auf **Keine**:
   - **PaymentIntents** (Zahlungen): **Schreiben**
   - **Rückerstattungen** (*Refunds*; in manchen Oberflächen als „Charges and Refunds“ zusammengefasst): **Schreiben**
4. **Schlüssel erstellen**, Zwei-Faktor-Bestätigung durchführen.
5. Den Schlüssel (`rk_live_…`) **sofort kopieren**. Stripe zeigt ihn nur dieses eine Mal an. Geht er verloren, erstellen Sie einen neuen.

> Die Bezeichnungen der Berechtigungen können sich in der Stripe-Oberfläche ändern. **Erstellen Sie den eingeschränkten Schlüssel zuerst in der Sandbox** und wiederholen Sie damit Testkauf **und Test-Storno** aus Schritt 6. Klappt beides, richten Sie denselben Schlüssel im Live-Modus ein. Klappt die Erstattung nicht, fehlt die Rückerstattungs-Berechtigung.

### 7.2 In QrGate umstellen

1. Admin → **Zahlung**.
2. Live-Schlüssel eintragen (`pk_live_…` und `sk_live_…` bzw. `rk_live_…`).
3. **Speichern**. Das Kennzeichen wechselt auf **LIVE**.

> **Beide Schlüssel müssen aus demselben Modus stammen.** Ein `pk_live_…` mit einem `sk_test_…` (oder umgekehrt) führt dazu, dass Zahlungen nicht funktionieren.

### 7.3 Echter Test

Kaufen Sie einmal **selbst** ein echtes, möglichst günstiges Ticket (mindestens 0,50 €) mit Ihrer eigenen Karte und stornieren Sie es danach. Damit sehen Sie den kompletten Weg mit echtem Geld. Die Stripe-Gebühr für diesen Test bekommen Sie in der Regel nicht zurück; das ist der Preis der Sicherheit, meist wenige Cent.

---

## Im laufenden Betrieb

### Auszahlungen

- Stripe überweist Ihre Einnahmen **abzüglich der Gebühren** auf das hinterlegte Bankkonto: [dashboard.stripe.com/payouts](https://dashboard.stripe.com/payouts).
- Die **erste Auszahlung** kommt in vielen europäischen Ländern erst rund **7 Werktage** nach der ersten erfolgreichen Zahlung. Danach zahlt Stripe standardmäßig innerhalb von etwa **3 Werktagen** aus. Das lässt sich nicht abkürzen. Den genauen Zeitplan für Ihr Konto sehen Sie im Dashboard unter *Einstellungen → Auszahlungen*. Planen Sie also nicht mit dem Geld für die Veranstaltung ein, bevor die erste Auszahlung eingegangen ist.

### Die Zahlungsliste lesen

Im Dashboard unter [Zahlungen](https://dashboard.stripe.com/payments) können neben erfolgreichen Zahlungen auch Einträge mit Status wie **Nicht erfasst** oder **Abgebrochen** auftauchen. Das sind Bestellungen, bei denen jemand im Bezahlschritt abgebrochen hat oder die Reservierung ablief. Es wurde **kein Geld bewegt**, die Reservierung auf der Karte wird automatisch aufgehoben.

### Erstattungen

- Stornos über QrGate (Storno-Link, Admin) erstatten automatisch.
- Zahlungen, die an der Abendkasse **bar** bezahlt wurden, erstattet der Verein selbst. Dafür ist Stripe nicht zuständig.
- Erstatten Sie **nicht** zusätzlich von Hand im Stripe-Dashboard, wenn QrGate schon storniert hat. Sonst wäre die Erstattung doppelt versucht (Stripe lehnt eine zweite volle Erstattung derselben Zahlung ab).

### Zahlungsanfechtungen

Bestreitet ein Käufer eine Zahlung bei seiner Bank, meldet Stripe das per E-Mail und im Dashboard unter **Anfechtungen**. Es gibt eine Frist zum Antworten (meist einige Tage). Reagieren Sie rechtzeitig mit Nachweisen, z. B. der Bestellbestätigung. Eine eingegangene Anfechtung kostet 20 €.

### Vergünstigungen für Gemeinnützige

Stripe bietet gemeinnützigen Organisationen teilweise angepasste Konditionen an. Das ist kein Automatismus und nicht öffentlich beziffert; Sie müssten es bei Stripe anfragen ([stripe.com/industries/nonprofits](https://stripe.com/industries/nonprofits)). Bei kleinen Ticketvolumen lohnt sich der Aufwand meist nicht.

---

## Häufige Probleme

| Problem | Ursache und Lösung |
| --- | --- |
| Im Shop gibt es keine Kartenzahlung | Publishable **und** Secret Key müssen in QrGate unter **Zahlung** eingetragen sein, und unter **Veranstaltung → Zahlungsarten im Shop** muss Karte enthalten sein. Das Kennzeichen oben auf der Seite „Zahlung“ zeigt **NICHT EINGERICHTET**, solange ein Schlüssel fehlt. |
| „Die Kartenzahlung ist gerade nicht verfügbar“ beim Bezahlen | Meist passen die Schlüssel nicht zusammen (Live mit Test gemischt), ein Schlüssel wurde abgelaufen bzw. erneuert, oder das Konto ist noch nicht aktiviert. Beide Schlüssel neu kopieren und speichern. |
| Es funktioniert nur mit Testkarten | Sie haben noch die Testschlüssel (`…_test_…`) eingetragen, oder Ihr Konto ist noch nicht für Live freigegeben. Siehe Schritt 7. |
| Apple-Pay-Knopf fehlt | Domain in Schritt 5 nicht (oder mit anderer Schreibweise) eingetragen, Shop nicht über HTTPS, Gerät ohne Apple Pay, oder Apple Pay in Schritt 4 nicht aktiv. Karteneingabe geht trotzdem. |
| Google Pay fehlt | Google Pay wird nur angeboten, wenn der Browser dazu bereit ist (Chrome mit angemeldetem Google-Konto und hinterlegter Karte). |
| Die Storno-E-Mail meldet, die automatische Erstattung habe nicht geklappt | Der Secret Key darf keine Erstattungen ausführen (bei eingeschränktem Schlüssel: Berechtigung **Rückerstattungen: Schreiben** fehlt), oder die Zahlung wurde bereits erstattet. Prüfen Sie die Zahlung im Stripe-Dashboard. |
| Bestellung unter 0,50 € klappt nicht | Stripe verlangt mindestens 0,50 €. QrGate lehnt kleinere Beträge ab. |
| Stripe fragt nach weiteren Dokumenten | Im Dashboard unter [Kontostatus](https://dashboard.stripe.com/account/status) steht ein Hinweis mit Upload-Möglichkeit. Namen und Adressen müssen exakt mit den Nachweisen übereinstimmen (beim Verein z. B. mit dem ZVR-Auszug). Unterlagen nie per E-Mail schicken. |
| Auszahlungen bleiben aus | Erste Auszahlung braucht rund eine Woche. Danach: Bankverbindung und Verifizierung im Dashboard prüfen. |
| Schlüssel verloren | Im Dashboard den Schlüssel **rotieren** oder **ablaufen lassen** und einen neuen erstellen, dann in QrGate ersetzen. Live-Schlüssel, die Sie selbst erstellt haben, zeigt Stripe kein zweites Mal an. |

---

## Sicherheit

- **Zwei-Faktor-Anmeldung** für alle, die Zugriff auf das Stripe-Konto haben.
- Jede Person, die mitarbeiten soll, bekommt einen **eigenen Zugang** über *Einstellungen → Team* statt Ihr Passwort.
- Der **Secret Key** (`sk_…` bzw. `rk_…`) ist wie ein Passwort: nicht per E-Mail, Messenger oder in Screenshots weitergeben, nicht in Dokumente kopieren. Der **Publishable Key** (`pk_…`) ist dagegen unkritisch und wird ohnehin im Browser der Käufer verwendet.
- Trägt jemand anderes als Sie den Schlüssel in QrGate ein, nutzen Sie dafür einen **Passwort-Manager** mit Freigabefunktion oder übergeben Sie ihn persönlich. Verwenden Sie dann am besten **Variante B** (eingeschränkter Schlüssel).
- Wechselt eine Person mit Schlüsselzugriff das Amt oder verlässt den Verein, **rotieren** Sie den Schlüssel: [dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys) → ⋯ → *Schlüssel rotieren*, danach in QrGate unter **Zahlung** den neuen Schlüssel speichern.

---

## Was Sie am Ende in QrGate eintragen

Admin → **Zahlung**:

| Feld in QrGate | Wert aus Stripe | Beginnt mit |
| --- | --- | --- |
| Publishable Key | Veröffentlichbarer Schlüssel | `pk_live_` (Test: `pk_test_`) |
| Secret Key | Geheimer bzw. eingeschränkter Schlüssel | `sk_live_` oder `rk_live_` (Test: `sk_test_` / `rk_test_`) |
| Webhook Secret | *leer lassen* | |

---

## Nützliche Links

| Thema | Adresse |
| --- | --- |
| Registrieren | [dashboard.stripe.com/register](https://dashboard.stripe.com/register) |
| Konto aktivieren | [dashboard.stripe.com/account/onboarding](https://dashboard.stripe.com/account/onboarding) |
| Kontostatus und Dokumente hochladen | [dashboard.stripe.com/account/status](https://dashboard.stripe.com/account/status) |
| API-Schlüssel (Live) | [dashboard.stripe.com/apikeys](https://dashboard.stripe.com/apikeys) |
| API-Schlüssel (Test) | [dashboard.stripe.com/test/apikeys](https://dashboard.stripe.com/test/apikeys) |
| Zahlungsmethoden-Domains | [dashboard.stripe.com/settings/payment_method_domains](https://dashboard.stripe.com/settings/payment_method_domains) |
| Preise Österreich | [stripe.com/at/pricing](https://stripe.com/at/pricing) |
| Akzeptierte Verifizierungsdokumente nach Land | [docs.stripe.com/acceptable-verification-documents](https://docs.stripe.com/acceptable-verification-documents) |
| Nachweise für Vereine, Personengesellschaften, Non-Profits | [support.stripe.com/questions/documents-for-business-verification-of-unincorporated-entities-partnerships-or-non-profits](https://support.stripe.com/questions/documents-for-business-verification-of-unincorporated-entities-partnerships-or-non-profits) |
| Wartezeit erste Auszahlung | [support.stripe.com/questions/default-payout-speeds-in-europe-and-canada](https://support.stripe.com/questions/default-payout-speeds-in-europe-and-canada) |
| Zentrales Vereinsregister (ZVR) | [oesterreich.gv.at](https://www.oesterreich.gv.at/de/lexicon/Z/Seite.991732) |
| Stripe-Testkarten | [docs.stripe.com/testing](https://docs.stripe.com/testing) |

> Die Stripe-Oberfläche ändert sich von Zeit zu Zeit. Menünamen und Reihenfolge können leicht abweichen. Die Links oben führen Sie direkt zur richtigen Seite.
