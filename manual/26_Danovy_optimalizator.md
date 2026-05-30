# 26. Daňový optimalizátor (OSVČ)

> ⚠️ **Orientační pomůcka, ne daňové přiznání.** Výpočet vychází jen z fakturačních
> dat a zadaného profilu; nezohledňuje vše (ostatní příjmy, skutečné zálohy na daň,
> speciální slevy). Před rozhodnutím ověř u účetní / daňového poradce.

**Cesta:** `Daně → Daňový optimalizátor`. Položka se zobrazí **jen pro OSVČ**
(dodavatel s typem poplatníka *OSVČ* v Nastavení) — pro s.r.o. nedává smysl.

## K čemu to slouží

Pomáhá živnostníkovi rozhodnout, **který daňový režim se mu vyplatí**, a hlídá
**limity** — vše na jeho reálném vyfakturovaném příjmu (zaplacené faktury, kasová
metoda, přepočet na CZK).

## Uzavřený rok (retrospektiva)

Pro minulý rok porovná dva režimy a vybere levnější:

- **Paušální daň** — jedna měsíční částka dle pásma (sloučí daň i pojistné).
- **Standardní režim** — výdajový paušál (40/60/80 %) **nebo skutečné výdaje**,
  základ daně dle §7, progresivní daň 15 / 23 %, slevy (poplatník, manžel/ka,
  děti vč. daňového bonusu) a sociální + zdravotní pojistné.

Rozpad ukáže cestu **příjem → výdaje → základ → daň → pojistné → odvody celkem →
čistý příjem** a **efektivní sazbu odvodů**. Pokud byl loni příjem, přidá i
**meziroční (YoY) srovnání** čistého příjmu.

## Běžící rok (predikce)

Z dosavadního tempa (run-rate) projektuje příjem do konce roku a na **teploměru**
hlídá překročení limitů:

- **strop zvoleného pásma** paušální daně,
- **2 000 000 Kč** — limit DPH / paušálu,
- **2 536 500 Kč** — hranice okamžitého plátcovství DPH.

Když překročení 2 M hrozí na konci roku, poradí **odložit prosincové faktury do
ledna** a zůstat pod limitem.

## Profil (per rok)

Nastav jednou, dopočítá se automaticky. Uloží se k danému roku:

| Pole | Význam |
|---|---|
| Typ činnosti | Výdajový paušál 40 / 60 / 80 % (dle živnosti) |
| Výdaje | **Paušál %** nebo **Skutečné** (reálné roční výdaje z daňové evidence) |
| Pásmo paušální daně | Přihlášené pásmo (none / 1. / 2. / 3.) |
| Vedlejší činnost | Jiná minima pojistného |
| Slevy / odpočty | Manžel/ka, počet dětí, úroky hypotéky, penzijní/životní, dary |

## Dashboard

Na úvodním dashboardu je pro OSVČ karta **„čistý příjem"** s projektovaným
výsledkem běžícího roku a proklikem do optimalizátoru.

## Daňové konstanty

Sazby, limity a vyměřovací základy jsou v aplikaci jako **ověřené výchozí
hodnoty**; admin je může pro daný rok upravit bez nového nasazení v
`Nastavení → Číselníky → Daňové konstanty` (sazby se mění každý rok). „Reset na
výchozí" vrátí hodnoty z aplikace.
