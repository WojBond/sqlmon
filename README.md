# Dashboardy monitorujące obciążenie MS SQL Server — SSMS 22

Zestaw skryptów diagnostycznych do wklejania w oknie zapytań SSMS 22 plus kolektor historii metryk.
Wymagania: uprawnienie `VIEW SERVER STATE`, SQL Server 2016 lub nowszy (testowane pod 2019/2022/2025).

---

## Zawartość

| Plik | Do czego służy | Kiedy uruchamiać |
|---|---|---|
| `00_przeglad_serwera.sql` | Dashboard główny: instancja, CPU, pamięć, sesje, TOP oczekiwania, bazy | Zawsze jako pierwszy |
| `01_cpu_i_zapytania.sql` | Historia CPU, kolejka schedulerów, TOP zapytania i procedury wg CPU | Gdy CPU > 70% |
| `02_pamiec.sql` | Bilans RAM, PLE, buffer pool, granty pamięci, memory clerks | Gdy PLE spada / `RESOURCE_SEMAPHORE` |
| `03_io_dyski.sql` | Latencja per plik (także pomiar różnicowy 30 s), TOP zapytania wg odczytów | Gdy `PAGEIOLATCH*` / `WRITELOG` |
| `04_oczekiwania.sql` | Wait stats z interpretacją + pomiar w oknie 30 s + kto czeka teraz | **Zawsze — to punkt startowy diagnozy** |
| `05_aktywnosc_i_blokady.sql` | Aktywne żądania, drzewo blokad, otwarte transakcje, deadlocki | Gdy „system stoi” |
| `06_tempdb_i_indeksy.sql` | tempdb, kontencja PAGELATCH, brakujące/martwe indeksy, fragmentacja, statystyki | Analiza tygodniowa |
| `07_query_store.sql` | Historia zapytań przetrwająca restart, regresje planów, profil dobowy | Po włączeniu Query Store |
| `08_kolektor_historii.sql` | Baza `DBA_Monitoring` + procedura + job Agenta co minutę | **Jednorazowo — instalacja** |
| `09_kolektor_zapytan_xe.sql` | Sesja Extended Events `sqlmon_zapytania` — kto uruchomił długie zapytanie | Jednorazowo, jeśli chcesz historię wg użytkownika |
| `sqlmon.php` | Pulpit webowy (XAMPP). Te same metryki co skrypty, odświeżane automatycznie | Codzienny podgląd; instrukcja w PDF |
| `index.php` | Strona startowa: kafelek na serwer, wiersz na instancję | Punkt wejścia — od niego zaczynasz |
| `konfiguracja.php` | GUI: serwery, instancje, loginy, hasła, progi. Dane w SQLite | Przy wdrożeniu i każdej zmianie instancji |
| `wspolne.php` | Magazyn konfiguracji i szyfrowanie haseł. Dołączany przez oba powyższe | Nie uruchamia się samodzielnie |

---

## Jak używać w SSMS 22

### Tryb siatki i eksport
Ustaw `Ctrl+D` (Results to Grid). Skrypty zwracają po kilka zestawów wyników naraz — zakładka **Results** pokazuje je jeden pod drugim. Kolumna `[Plan]` zawiera XML planu wykonania — kliknięcie otwiera go graficznie w nowej zakładce.

Do eksportu na wykres: zaznacz siatkę → `Ctrl+Shift+C` (kopiuje z nagłówkami) → wklej do Excela.

### Skróty do wbudowanych narzędzi
- **Activity Monitor** — `Ctrl+Alt+A`. Cztery wykresy (CPU, Waiting Tasks, I/O, Batch Requests) plus rozwijane sekcje z procesami i najdroższymi zapytaniami. Uwaga: sam potrafi obciążyć serwer, nie zostawiaj go otwartego na stałe.
- **Raporty standardowe** — prawy klik na węźle serwera → **Reports → Standard Reports**. Najprzydatniejsze: *Server Dashboard*, *Performance – Top Queries by Total CPU Time*, *Performance – Top Queries by Average IO*, *Activity – All Blocking Transactions*.
- **Raporty bazy** — prawy klik na bazie → **Reports**: *Disk Usage*, *Index Usage Statistics*, *Top Transactions by Age*.
- **Query Store** — po włączeniu pojawia się węzeł pod bazą z gotowymi wykresami: *Top Resource Consuming Queries*, *Regressed Queries*, *Queries With High Variation*, *Query Wait Statistics*. To najbliższy odpowiednik prawdziwego dashboardu wbudowany w SSMS.

### Raporty niestandardowe (RDL)
SSMS 22 nadal obsługuje **Reports → Custom Reports** — wskazujesz plik `.rdl` i renderuje się jak raport SSRS, w kontekście zaznaczonego węzła Object Explorera. Uwagi praktyczne:
- Stary *SQL Server Performance Dashboard Reports* (2012 / Tiger Toolbox) nie jest już rozwijany i pod nowszymi wersjami potrafi się sypać — nie polecam jako fundamentu.
- Własne `.rdl` tworzy się w SQL Server Data Tools / Report Builder. Sensowne, jeśli chcesz wykresy CPU i latencji IO z tabel `DBA_Monitoring` bez wychodzenia z SSMS.
- Raport wykonuje się na poświadczeniach aktualnie zalogowanego użytkownika SSMS.

---

## Kolejność diagnozy (metodyka)

0. **`index.php`** — rzut oka na kafelki serwerów; kolor paska mówi, która maszyna wymaga uwagi. Szybsze niż uruchamianie skryptów.
1. **`04_oczekiwania.sql`, sekcja 2** — pomiar różnicowy w oknie 30 s. Mówi, na co serwer czeka *teraz*, a nie od restartu.
2. Dominujący typ oczekiwania kieruje dalej:

| Dominujący wait | Idź do | Typowa przyczyna |
|---|---|---|
| `PAGEIOLATCH_SH/EX` | `03` | Wolne dyski, za mało RAM, skanowanie zamiast seeka |
| `WRITELOG`, `LOGBUFFER` | `03` | Latencja dysku logu, wiele drobnych transakcji |
| `SOS_SCHEDULER_YIELD` | `01` | Presja CPU albo masowe skany. Duża liczba przy średniej poniżej 1 ms to zwykle skany, nie brak CPU — rozstrzyga kolejka runnable |
| `CXPACKET`, `CXCONSUMER` | `01` | Źle dobrany MAXDOP / Cost Threshold. Przy MAXDOP 1 te oczekiwania nie wystąpią wcale — ich brak nie jest wtedy dobrą wiadomością, tylko konsekwencją ustawienia |
| `RESOURCE_SEMAPHORE` | `02` | Braki pamięci, przeszacowane granty |
| `LCK_M_*` | `05` | Długie transakcje, brak indeksów, izolacja |
| `PAGELATCH_*` na `2:1:x` | `06` | Kontencja alokacji tempdb |
| `PAGELATCH_EX` na innej bazie | `06` | Ostatnia strona indeksu przy rosnących kluczach — na 2019+ `OPTIMIZE_FOR_SEQUENTIAL_KEY` |
| `THREADPOOL` | `05` | Zator blokad lub lawina sesji |
| `ASYNC_NETWORK_IO` | — | Problem po stronie aplikacji/sieci, nie serwera |

3. Dopiero potem TOP zapytania — bo optymalizowanie zapytań przy zapchanym dysku to leczenie objawów.

---

## Progi orientacyjne

| Metryka | Zdrowo | Niepokojąco |
|---|---|---|
| CPU SQL | < 60% | > 80% utrzymujące się |
| Runnable tasks | 0 | > 1–2 na rdzeń, utrzymujące się |
| Latencja odczytu (dane) | < 10 ms | > 25 ms |
| Latencja zapisu (log) | < 5 ms | > 15 ms |
| PLE | ok. 300 s na każde 4 GB buffer poola (96 GB → ~7200 s) | gwałtowne spadki lub połowa typowej wartości |
| Memory Grants Pending | 0 | > 0 |
| Zablokowane sesje | 0 | rosnące łańcuchy |
| Plany jednorazowe (adhoc) | < 30% cache | > 50% → `optimize for ad hoc workloads` |

---

## Instalacja kolektora historii

```
1. Otwórz 08_kolektor_historii.sql w SSMS 22
2. Uruchom całość (F5) — utworzy bazę DBA_Monitoring, tabele, procedurę, widoki i job
3. Sprawdź: SQL Server Agent → Jobs → "DBA - Zbieranie metryk wydajnosci"
4. Po kilku godzinach: SELECT * FROM DBA_Monitoring.dbo.vw_ObciazenieGodzinowe
```

Narzut: pojedynczy snapshot to ułamek sekundy i kilkadziesiąt wierszy. Przy retencji 30 dni baza rośnie do ok. 300–800 MB (zależnie od liczby plików baz).

Jeśli SQL Server Agent jest niedostępny (Express Edition), można wywoływać `usp_ZbierzMetryki` z Harmonogramu zadań Windows przez `sqlcmd`.

---

## Warto dołożyć (darmowe, sprawdzone)

- **sp_WhoIsActive** (Adam Machanic) — najlepszy zamiennik `sp_who2`; jedno polecenie pokazuje aktywność z planami i blokadami.
- **First Responder Kit** (Brent Ozar) — `sp_Blitz` (audyt konfiguracji), `sp_BlitzFirst` (co boli w tej chwili), `sp_BlitzCache` (najgorsze plany), `sp_BlitzIndex`.
- **Glenn Berry Diagnostic Queries** — obszerny zestaw zapytań DMV aktualizowany dla każdej wersji SQL Server.

Wszystkie instaluje się jako procedury w bazie narzędziowej (np. `DBA_Monitoring`) i wywołuje z SSMS.
