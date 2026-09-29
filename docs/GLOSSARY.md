# Glossary: English terms of the app

The English texts are the source of every translation (`t('verein', '…')`, `$l->t()`), so they have to be right first.
This list fixes one English term per concept; `L10nTest` checks the source strings against the "not" column.
German is the shipped translation (`l10n/de.json`).

**Open decisions are marked ❓** – they change what users read everywhere, so they are decided before the texts are
reworked.

## Style

- **US English** (Nextcloud's convention): recognize, organization, totaling, canceled, anonymize.
- **Sentence case** for buttons, labels, headings and tabs: "Add member", "Fee categories", not "Add Member".
- Nextcloud's own words: **account** (not "user", "NC account", "login"), **Email** (not "E-mail"), **team folder**,
  **Files**, **Calendar app**, **administrator**.
- Buttons name the action: "Delete fee", "Create 3 letters" – not "OK", "Yes".
- Ellipsis is the single character **…** ("Loading…"), never "...".
- No abbreviations in the UI ("Memb. ID", "yrs.", "No." → "Member ID", "years", "#").
- Tab names in running text: the **Club** tab (bold in docs; in the UI as “Club” tab with curly quotes).
- Dates and amounts are never written into the text: placeholders, formatted in the reader's locale
  (31/12/2026 or 12/31/2026, €1,234.56 – not "31.12.2026", "1.234,56 €"). Date format hints only as
  "(e.g. {example})" generated from the locale.

## Terms

| Concept (German) | English | Not |
|---|---|---|
| Verein | **club** | association, organization |
| Vereinsverwaltung (app name) | ❓ **Club Management** (navigation: **Clubs**) | Verein, Club management dashboard |
| Mitglied | **member** | |
| Person (one human, shared by clubs) | **person** | |
| Mitgliedschaft | **membership** | |
| Eintritt / Eintrittsdatum | **joined** / **date joined** | join date |
| Austritt / Austrittsdatum | **left** / **date left** | leave date |
| Gründungsmitglied | **founding member** | |
| Ehrenmitglied | **honorary member** | |
| verstorben | **deceased** | |
| deaktivieren | **deactivate** (member) | lock, suspend |
| Vereinsfunktion (Mitglied, Kassierer, Vorstand) | ❓ **position**: Member, Treasurer, Board | club role (clashes with permission roles) |
| Vorstand | **board** | committee |
| Kassierer | **treasurer** | cashier |
| Rolle (Rechte-Paket) | **role** | |
| Recht / Berechtigung | **permission** | right |
| automatische Rechte | **automatic permissions** | automatic rights |
| Nextcloud-Konto (verknüpft) | **Nextcloud account** (**linked** account) | user, NC account |
| Anrede | **title** | salutation |
| Anrede-Werte Herr / Frau / Divers / Firma | **Mr** / **Ms** / ❓ **Mx** / **Company** | Diverse |
| Geburtsdatum | **date of birth** | birth date |
| Postleitzahl | **postal code** | ZIP |
| E-Mail | **Email** | E-mail, e-mail |
| Beitrag | **fee** (in letters: **membership fee**) | contribution, dues |
| Beitragskategorie | **fee category** | fee rate |
| beitragsfrei | **fee-exempt** | fee-free |
| Beitragslauf | **annual fee run** | |
| anteilig | **pro rata** | |
| fällig / Fälligkeit | **due** / **due date** | |
| Status offen / bezahlt / überfällig / storniert | ❓ **unpaid** / **paid** / **overdue** / **canceled** | open, cancelled |
| Mahnwesen (Reiter, Funktion) | ❓ **payment reminders** | dunning |
| Mahnstufen 1 / 2 / 3 | ❓ **payment reminder** / **second reminder** / **final notice** | first/second dunning letter |
| Mahnschreiben | ❓ **reminder letter** | dunning letter |
| Mahnlauf | ❓ **reminder run** | dunning run |
| SEPA-Lastschrift | **SEPA direct debit** | |
| Einzug / einziehen | **collection** / **collect** | debit (as a verb for us) |
| Einzugsdatum | **collection date** | |
| Lastschriftmandat | **direct debit mandate** (short: **mandate**) | |
| Mandatsreferenz | **mandate reference** | |
| Unterschriftsdatum (Mandat) | **date of signature** | mandate date, signature date |
| Gläubiger-ID | **creditor identifier** (short: **creditor ID**) | |
| Verwendungszweck | **payment reference** | remittance information, reference |
| Bankkonto / Kontoinhaber | **bank account** / **account holder** | |
| Finanzen (Reiter) | **Finances** | Finance |
| Änderungsprotokoll (Reiter „Protokoll“) | **Audit log** | change log, log |
| Datenauskunft (Art. 15 DSGVO) | **personal data export** | data information |
| Meine Daten / Selbstauskunft | **My data** | self-disclosure |
| anonymisieren | **anonymize** | |
| Sicherung / wiederherstellen | **backup** / **restore** | |
| Team-Ordner | **team folder** | |
| Vereinstermine (Kalender) | **Club events** | |
| Jubiläum | **membership anniversary** | anniversary alone |

## Found in the current texts (reworked with the glossary)

- Same concept, several words: "Fee rate"/"category", "E-mail"/"Email"/"e-mail", "Birth date"/"date of birth",
  "board"/"Board", tab "Club" named as "Club" and "Clubs", "Log"/"Change log", "rights"/"permissions",
  "NC account"/"Nextcloud account"/"user".
- Literal translations: "Diverse", "Data information", "Concerns" (Betrifft), "Former", "faulty", "run" (ausgeführt),
  "Board gets the role", "self-disclosure", "Memb. yrs".
- British spelling mixed in: "Recognised", "totalling", "cancelled".
- German formats in English text: "DD.MM.YYYY", "1.234,56 €" (letters and PDFs), "max. 100,000".
- Not translatable at all: salutation values shown raw on "My data" (Herr/Frau), permission descriptions in
  `RoleService` (German constants), Nextcloud log messages in German (Nextcloud logs are English), app name
  "Verein" in `info.xml` and the page title, default organization name "Vereins-App" in PDFs.
- PDFs use Helvetica, which cannot print "Ł", Cyrillic etc. (a PDF font with full Unicode is part of this work).
