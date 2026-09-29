# Glossary: English terms of the app

The English texts are the source of every translation (`t('verein', '…')`, `$l->t()`), so they have to be right first.
This list fixes one English term per concept. German is the shipped translation (`l10n/de.json`).

**Checked by a test:** every word in `backticks` in the "Not" column must not appear in any source string
(`L10nTest::testSourceStringsFollowTheGlossary`, whole words, case-insensitive). Add a row here and the test enforces it.

## Style

- **US English** (Nextcloud's convention): recognize, organization, totaling, canceled, anonymize.
- **Sentence case** for buttons, labels, headings and tabs: "Add member", "Fee categories".
- Nextcloud's own words: **account** (not "user", "login"), **Email**, **team folder**, **Files**, **Calendar app**,
  **administrator**.
- Buttons name the action: "Delete fee", "Create 3 letters" – not "OK".
- Quotation marks in texts are typographic: “Club” tab (German „Verein“). Straight `"` break HTML attributes.
- Ellipsis is the single character **…** ("Loading…"), never "...".
- No abbreviations in the UI ("Memb. ID", "yrs.").
- Dates and amounts never appear formatted in a text: they are placeholders, formatted in the reader's locale –
  `js/format.js` in the browser, `L10n\Formats` in PHP (letters and PDFs in the document language). "DD.MM.YYYY" only
  where it describes what the CSV import accepts.

## Terms

| Concept (German) | English | Not |
|---|---|---|
| Verein | **club** | `association` |
| Vereinsverwaltung (app name) | **Club Management**; menu entry **Clubs** | |
| Mitglied / Person / Mitgliedschaft | **member** / **person** / **membership** | |
| Eintritt / Austritt | **joined**, **date joined** / **left**, **date left** | `join date`, `leave date` |
| Gründungsmitglied / Ehrenmitglied / verstorben | **founding member** / **honorary member** / **deceased** | |
| deaktivieren | **deactivate** (a member) | |
| Vereinsfunktion (Mitglied, Kassierer, Vorstand) | **position**: Member, Treasurer, Board | `club role` |
| Vorstand / Kassierer | **board** / **treasurer** | `committee`, `cashier` |
| Rolle (Rechte-Paket) | **role** | |
| Recht / Berechtigung | **permission** | `rights` |
| automatische Rechte | **automatic permissions** | |
| Nextcloud-Konto | **Nextcloud account**, **linked** account | `user`, `NC account` |
| Anrede | **title** | `salutation` |
| Herr / Frau / Divers / Firma | **Mr** / **Ms** / **Mx** / **Company** | `Diverse` |
| Geburtsdatum | **date of birth** | `birth date` |
| Postleitzahl | **postal code** | |
| E-Mail | **Email** | `e-mail` |
| Beitrag | **fee** (in letters: **membership fee**) | `dues` |
| Beitragskategorie | **fee category** | `fee rate` |
| beitragsfrei | **fee-exempt** | `fee-free` |
| Beitragslauf | **annual fee run** | |
| anteilig | **pro rata** | |
| fällig / Fälligkeit | **due** / **due date** | |
| Status offen / bezahlt / überfällig / storniert | **unpaid** / **paid** / **overdue** / **canceled** | `cancelled` |
| Mahnwesen | **payment reminders** | `dunning`, `dunned` |
| Mahnstufen 1 / 2 / 3 | **payment reminder** / **second reminder** / **final notice** | |
| Mahnschreiben / Mahnlauf | **reminder letter** / **reminder run** | |
| SEPA-Lastschrift / Einzug | **SEPA direct debit** / **collection**, **collect** | |
| Einzugsdatum | **collection date** | |
| Lastschriftmandat / Mandatsreferenz | **direct debit mandate** (**mandate**) / **mandate reference** | |
| Unterschriftsdatum (Mandat) | **date of signature** | `mandate date`, `signature date` |
| Gläubiger-ID | **creditor identifier** (short: **creditor ID**) | |
| Verwendungszweck | **payment reference** | `remittance information` |
| Bankkonto / Bankverbindung / Kontoinhaber | **bank account** / **bank details** / **account holder** | |
| Finanzen (Reiter) | **Finances** | `Finance` |
| Änderungsprotokoll (Reiter „Protokoll“) | **audit log** | `change log` |
| Datenauskunft (Art. 15 DSGVO) | **personal data export** | `data information` |
| Meine Daten | **My data** | `self-disclosure` |
| anonymisieren | **anonymize** | `anonymise` |
| Sicherung / wiederherstellen | **backup** / **restore** | |
| Team-Ordner | **team folder** | |
| Vereinstermine (Kalender) / Jubiläum | **Club events** / **membership anniversary** | |

## Spelling

British spellings that are not used: `recognise`, `recognised`, `totalling`, `organisation`, `colour`, `licence`
(checked like the "Not" column).
