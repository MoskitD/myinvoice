# 13. Banka — import výpisů, e-mailová avíza a párování plateb

Místo ručního označování faktur jako zaplacených, naimportuj **GPC výpis**
z banky a systém automaticky spáruje platby s fakturami podle variabilního
symbolu a částky.

GPC (ABO) je standardní český formát pro elektronickou výměnu výpisů. Umí ho
exportovat: **KB**, **Fio Bank**, **ČSOB**, **Raiffeisenbank**, **Česká
spořitelna**, **mBank**, a další.

Alternativně může systém zpracovávat i **bankovní e-mailová avíza** z IMAP
schránky. To se hodí ve chvíli, kdy banka posílá oznámení o příchozí platbě
rychleji než pravidelný výpis.

## 13.1 Stažení GPC výpisu z banky

Postup je v každé bance trochu jiný:

| Banka | Cesta v internet bankingu |
|---|---|
| **KB** | Účet → Historie pohybů → Export → formát „GPC ABO" |
| **Fio** | Přehled účtu → Stažení dat → formát „GPC" |
| **ČSOB** | Účet → Výpisy → Stáhnout → formát „ABO" |
| **Raiffeisen** | Detail účtu → Pohyby → Export → ABO formát |
| **ČS** | Detail účtu → Výpisy → formát „ABO" |

Stáhni soubor (typicky `.gpc` nebo `.abo`, někdy `.txt`). Velikost ~10–100 KB
na měsíc obvykle.

## 13.2 Upload výpisu do MyInvoice

V hlavním menu **Banka → Nahrát výpis**.

![Upload výpisu](img/11_banka_upload.webp)

Vyber soubor (drag & drop nebo klik). Po nahrání:

1. **Hash kontrola** (SHA-256) — pokud je stejný soubor už importovaný, hláška
   „Tento výpis už byl importovaný" + zrušení.
2. **Validace bankovního účtu** — server zkontroluje, že číslo účtu z hlavičky
   výpisu patří některé z měn aktuálního dodavatele.
3. **Parsing transakcí** — přečte všechny řádky.
4. **Auto-matching** — pro každou kreditní transakci s VS hledá fakturu se
   shodným varsymbolem **a** sumou v rozmezí ± 0,01 (tolerance haléře).
5. **Update faktur** — spárované faktury → status `paid`, `paid_at` =
   `transakce.datum_zaúčtování`.

Hláška o výsledku:

```
Importováno: 12 transakcí, spárováno: 8, k manuálnímu párování: 4.
```

## 13.3 Seznam výpisů

**Banka → Výpisy** ukáže historii.

| Sloupec | Význam |
|---|---|
| Datum | Datum výpisu |
| Číslo | Číslo výpisu z banky |
| Účet | Číslo účtu / IBAN |
| Měna | CZK / EUR / … |
| Příchozí | Suma kreditních transakcí |
| Odchozí | Suma debetních transakcí |
| Spárováno | `12/14` — 12 z 14 transakcí spárováno na faktury |
| Importováno | Datum + uživatel |

## 13.4 Detail výpisu

Klik na řádek → detail.

Tabulka transakcí:

| Sloupec | Význam |
|---|---|
| Datum | Datum zaúčtování |
| Částka | + (kredit) / − (debet) |
| Měna | |
| Protistrana | Název + číslo účtu (pokud bance zaslala) |
| VS | Variabilní symbol z transakce |
| KS / SS | Konstantní / specifický symbol |
| Popis | Poznámka z banky |
| Stav | `Spárováno` (zelená) / `Bez shody` (šedá) / `Ignorováno` (oranž.) |
| Faktura | Pokud spárováno, číslo faktury (klikatelné) |

### 13.4.1 Manuální párování

Pro transakce, které se nespárovaly automaticky (typicky chybí VS, nebo
částka nesedí kvůli částečné platbě, devizovému kurzu, bankovnímu poplatku):

1. Klik **Spárovat** → otevře se modal s vyhledávačem.
2. Najdeš fakturu (číslo / klient / částka).
3. Vyber a potvrď.

Faktura → status `paid`, `paid_at` = datum transakce. Activity log: `bank.matched_manual`.

### 13.4.2 Ignorovat transakci

Pro transakce, které nejsou platby faktur (poplatky, převody mezi vlastními
účty, refundace, …):

1. Klik **Ignorovat**.
2. Status → `Ignorováno`. Pro reporting se nepočítá.

### 13.4.3 Vytvoření přijaté faktury z výpisu (doklad o úhradě)

U **odchozí (záporné) platby**, ke které ještě nemáš v systému přijatou fakturu,
můžeš rovnou založit její koncept přímo z výpisu:

1. Detail výpisu → najdi odchozí transakci → klik **Vytvořit fakturu**.
2. Vyber **existujícího dodavatele** (nebo klik **Nový dodavatel** a založ ho).
   Dodavatel se nezakládá automaticky — musíš ho potvrdit.
3. Potvrď → vznikne **koncept přijaté faktury** v hrubé částce platby
   (1 položka, 0 % DPH) a rovnou se otevře v editoru.
4. V editoru doplň **rozpad DPH**, skutečné **číslo dokladu** a nahraj **PDF**.

Variabilní symbol z platby se předvyplní do pole VS; číslo dokladu dostane
dočasný placeholder `BANK-{id}` (přepiš ho na reálné číslo z faktury). Platba se
zároveň **spáruje** na nově vzniklý koncept (vazba, ne `paid` — to potvrdíš až po
finalizaci faktury).

> 💡 **Tlačítko „Otevřít"** u spárované transakce přeskočí na navázanou fakturu
> (vydanou i přijatou).

## 13.5 Reverse: zrušení spárování

Pokud automatika spárovala chybně:

1. Detail výpisu → najdi transakci → klik **Zrušit párování**.
2. Faktura → status zpět na předchozí (`sent` / `issued`).
3. Activity log: `bank.unmatched`.

## 13.6 Cron — automatický scan

Místo ručního uploadu můžeš nastavit **cron**, který bude pravidelně skenovat
adresář (např. `private/bank-incoming/`) a importovat nové výpisy:

```bash
cmd/cron-bank-scan.sh        # každých 30 minut
```

Setup:

1. Banka pravidelně exportuje výpis e-mailem nebo SFTP do `private/bank-incoming/`
2. Cron každých 30 min spustí `php api/bin/cron-bank-scan.php`
3. Skript projde nové soubory, importuje, přesune do `private/bank-archive/`

## 13.7 Bankovní e-mailová avíza přes IMAP

Bankovní avízo je e-mail od banky, který obsahuje údaje o platbě. MyInvoice umí
takové e-maily pravidelně načítat přes IMAP, vytěžit z nich VS, částku, měnu,
datum a cílový účet a vytvořit z nich bankovní transakci stejně jako z výpisu.

Konfigurace je v **Systém → Bankovní účty**.

### 13.7.1 Bankovní účty

Sekce **Měny + bankovní účty** zůstává čistý seznam účtů dodavatele. Účet zde
nastavuješ stejně jako pro PDF faktury, QR platby a GPC výpisy:

- měna a označení účtu,
- české číslo účtu + kód banky,
- případně IBAN/BIC,
- výchozí účet pro danou měnu,
- aktivní/neaktivní stav.

Nastavení bankovních avíz je oddělené níže, aby se běžné bankovní údaje
nemíchaly s parsery a IMAP účty.

### 13.7.2 Mapování bankovních avíz

Sekce **Mapování bankovních avíz** určuje, jak se vytěžený e-mail napojí na
konkrétní bankovní účet dodavatele.

| Sloupec | Význam |
|---|---|
| Bankovní účet | Účet z měn dodavatele, proti kterému se porovnává cílový účet v e-mailu |
| IMAP účet | Konkrétní schránka, ze které se má avízo pro tento účet brát; „Žádný IMAP účet" = výchozí stav bez skenování, „Všechny IMAP účty" = neomezeno |
| Parser | Konkrétní parser provider; „Automatický výběr" = systém zkusí všechny aktivní providery |
| Tolerance | Povolená odchylka částky při párování faktury, např. `1.00` pro ±1 Kč |
| Aktivní | Vypnutý řádek se při scanování nepoužije |

Mapování se vyhodnocuje až po úspěšném vytěžení e-mailu. Pokud e-mail přijde
z jiného IMAP účtu nebo ho zpracoval jiný parser, než je v mapování nastaveno,
řádek se nepoužije.

Nové nebo nenastavené mapování začíná volbou **Žádný IMAP účet**. Takový řádek
se při scanování nepoužije, dokud nezvolíš konkrétní IMAP účet nebo vědomě
nepovolíš variantu **Všechny IMAP účty**.

### 13.7.3 IMAP účty pro bankovní avíza

Každý dodavatel může mít více IMAP účtů, typicky jeden pro každou banku.

| Pole | Význam |
|---|---|
| Název | Popisek v UI, např. „RB avíza" |
| Host / port / šifrování | Připojení k IMAP serveru |
| Uživatel / heslo | Přístup ke schránce; heslo se ukládá šifrovaně |
| Složka | IMAP složka, např. `INBOX` nebo `INBOX.Banka` |
| Procházet | Ověří připojení a nabídne složky ze serveru |
| Max. zpráv na běh | Kolik nejnovějších e-mailů cron načte při jednom běhu |
| Zpracovat od data | Starší e-maily se ignorují i když spadnou do limitu |
| Po úspěchu | Co udělat se zpracovanou zprávou |

Polling zprávy standardně **neoznačuje jako přečtené**. Systém si úspěšně
zpracované e-maily pamatuje v databázi podle `Message-ID` / UID / fallback
hashe, takže funguje i s účtem, kde aplikace nemůže zprávy přesouvat nebo
označovat. Pokud má účet zápis povolený, můžeš zvolit doplňkovou akci po
úspěchu: neměnit zprávu, přidat flag, přesunout do jiné složky nebo označit
jako přečtené.

### 13.7.4 Parser provideri

Provider říká, jak poznat e-mail dané banky a jak z něj vytěžit platební údaje.

Typy providerů:

- **Systémový provider** — dodaný aplikací, např. Raiffeisenbank.
- **Regex provider** — vlastní provider dodavatele, konfigurovaný v UI.

U regex provideru nastavuješ:

| Pole | Význam |
|---|---|
| Název / kód | Interní identifikace provideru |
| Odesílatel | Whitelist e-mailů, např. `info@rb.cz` |
| Regex předmětu | Volitelný pattern pro subject, např. `Pohyb\s+na\s+účtě` |
| Regex těla | Volitelný pattern, který musí být v těle e-mailu |
| Vytěžená pole | Regexy pro VS, částku, měnu, datum, cílový účet atd. |

Povinná vytěžená pole:

- `variable_symbol`
- `amount`
- `currency`
- `posted_at`
- `recipient_account`

Volitelná pole:

- `counterparty_account`
- `counterparty_name`
- `constant_symbol`
- `message`
- `bank_ref`

Regex parser používá první zachycenou skupinu nebo pojmenovanou skupinu se
stejným názvem jako pole. Pro částku umí formáty typu `+1.234,56`, datum např.
`01. 06. 2026 10:15`.

### 13.7.5 Příklad regex provideru pro Raiffeisenbank

Následující příklad je **anonymizovaný**. Čísla účtů, variabilní symbol, název
protistrany i zpráva jsou fiktivní. Do manuálu nikdy nedávej reálné e-maily
z banky s osobními údaji, zůstatky nebo skutečnými čísly účtů.

Testovací text e-mailu může vypadat např. takto:

```text
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
```

Základní nastavení provideru:

| Pole | Hodnota |
|---|---|
| Název | `Raiffeisenbank regex test` |
| Kód | `raiffeisenbank_regex` |
| Aktivní provider | Ano |
| Odesílatel | `info@rb.cz` |
| Regex předmětu | viz níže |
| Regex těla | `Variabilní\s+symbol` |
| Normalizer config | `{}` |

Regex předmětu:

```text
Pohyb\s+na\s+účtě|Pohyb\s+na\s+ucte
```

Regexy pro vytěžená pole:

| Pole | Regex |
|---|---|
| Datum platby | `Datum\s+a\s+čas\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})` |
| Cílový účet | `Na\s+účet\s*([0-9-]+/[0-9]{4})` |
| Částka | `Částka\s+v\s+měně\s+účtu\s*([+\-]?[0-9 .]+,[0-9]{2})\s*[A-Z]{3}` |
| Měna | `Částka\s+v\s+měně\s+účtu\s*[+\-]?[0-9 .]+,[0-9]{2}\s*([A-Z]{3})` |
| Protiúčet | `Z\s+účtu\s*([0-9-]+/[0-9]{4})` |
| Název protistrany | `Z\s+účtu\s*[0-9-]+/[0-9]{4}\s*([^\n]+?)\s*Variabilní\s+symbol` |
| Variabilní symbol | `Variabilní\s+symbol\s*([0-9]+)` |
| Konstantní symbol | `Konstantní\s+symbol\s*([0-9]+)` |
| Zpráva | `Zpráva\s+pro\s+příjemce\s*(.*?)\s*Disponibilní\s+zůstatek` |
| Reference banky | prázdné |

> 🛈 Do UI zadávej regex bez krajních oddělovačů (`/.../`). Parser je doplní
> sám.

### 13.7.6 Test parseru a zpracované e-maily

V sekci **Parser provideri** můžeš vložit testovací e-mail, odesílatele a
předmět. Test ukáže, který provider se použil a jaká pole se vytěžila.

Sekce **Zpracované e-maily** je debug přehled:

- zobrazuje `Message-ID` / fallback hash,
- IMAP účet,
- stav zpracování,
- použitý provider,
- vytěžené platební údaje,
- navázanou transakci nebo fakturu.

Smazání záznamu zde nemaže transakci ani fakturu. Maže jen deduplikační záznam,
takže je možné stejný e-mail znovu zpracovat při dalším scanu. Používej to jen
jako emergency/debug akci.

### 13.7.7 Cron pro e-mailová avíza

Pro automatické zpracování nastav samostatný cron:

```bash
cmd/cron-bank-email-notices.sh   # každých 30 minut
```

Skript spustí `php api/bin/cron-bank-email-notices.php`, projde aktivní IMAP
účty dodavatele, načte nejnovější zprávy podle limitu a zapíše heartbeat do
plánovaných úloh.

## 13.8 Tipy

- **Nahraj výpis **denně/týdně** — čím čerstvější, tím dříve se ti vyfiltrují
  faktury po splatnosti správně.
- **Auto-match funguje jen s VS** — bez VS musíš párovat ručně. Apeluj na
  klienty, aby VS vyplňovali (typicky ho v bance nabízí, když napíšeš číslo
  faktury jako popis).
- **Platby kartou** (bez VS) se zkusí spárovat na přijatou fakturu i podle
  **částky + podobnosti názvu** dodavatele (název protistrany na výpisu nemusí
  přesně sedět s názvem dodavatele). Spáruje se jen při jednoznačné shodě;
  jinak nech na ručním párování / založení dokladu (viz 13.4.3).
- **Tolerance ± 0,01** — částečné platby (klient pošle míň) se nespárují
  automaticky, musíš ručně. Zvaž nastavení tolerance v `cfg.php` →
  `bank.matching.tolerance`; u bankovních e-mailových avíz ji nastavíš přímo v
  mapování účtu.
- **Devizový kurz** — pokud klient pošle EUR a faktura je v CZK, transakce
  nebude spárovaná (jiná měna). Manuálně.
- **Bankovní poplatek** — pokud banka strhla ze 100 EUR poplatek 1.5 EUR
  a klient zaplatil 100, dostáváš 98.5. Manuálně označíš jako částečně
  zaplacené nebo přijmeš tuto „ztrátu" jako bank fee.
