# SQLMON — historia zmian

Numer wersji ma jedno źródło prawdy: stała `APP_VERSION` w `sqlmon.php`.
Nazwa paczki jest z niej generowana przez `zbuduj_paczke.sh`.

## 9.1.1

- **Kafelek „Najdłuższe zapytanie" nadal pokazywał uptime.** Filtr z 9.1.0 wycinał
  tylko statusy `background` i `sleeping`, ale sesje zaparkowane na wiecznym
  oczekiwaniu (aktywacja Service Brokera, `WAITFOR`, log reader replikacji, CDC)
  mają status `running`/`suspended` i `is_user_process = 1`. Dołożone wykluczenia
  po `wait_type` i `command` oraz konfigurowalny limit
  `limit_dlugiego_zapytania_h` (domyślnie 24 h).
- **Ostrzeżenie o Cost Threshold pokazywane tylko przy MAXDOP ≠ 1.** Przy MAXDOP 1
  — typowym wymogu dostawców ERP — żadne zapytanie nie idzie równolegle, więc próg
  jest ustawieniem martwym i ostrzeżenie wprowadzało w błąd.
- Czytelne opisy dla `PREEMPTIVE_OS_*`, `OLEDB` i kolejki kompilacji planów.
- Kolektor historii dostał ten sam filtr.

## 9.1.0

### Poprawki błędów

- **Kafelek „Najdłuższe zapytanie" pokazywał czas pracy instancji.** Metryka brała
  maksimum z `sys.dm_exec_requests` bez filtra na status, więc łapała zadanie
  systemowe działające od startu serwera (obserwowane 1 915 889 s przy uptime 533 h).
  Dodano `is_user_process = 1`, wykluczenie `@@SPID` oraz statusów
  `background` i `sleeping`.
- **Kafelek „Aktywne zapytania" zawyżał wynik.** Warunek `session_id > 50` to
  heurystyka z SQL Server 2005; na nowszych wersjach sesje systemowe dostają
  wyższe identyfikatory. Zastąpiony złączeniem z `sys.dm_exec_sessions`.
  W obserwowanym środowisku licznik spadł z 82 na 61.
- **Kafelek „Zablokowane sesje" mógł świecić przy zwykłej równoległości.**
  Dodano `blocking_session_id <> session_id`, żeby pominąć wzajemne oczekiwanie
  wątków w ramach jednego planu równoległego.
- **Page life expectancy na serwerach NUMA.** `TOP (1)` bez `ORDER BY` zwracał
  dowolny wiersz licznika. Teraz brane jest minimum z węzłów `Buffer Node`
  (najgorszy węzeł decyduje), a przy braku NUMA — licznik `Buffer Manager`.

### Nowe funkcje

- **Konfigurowalna lista pomijanych typów oczekiwań** — `pomijaj_oczekiwania`
  w `$KONFIG`, domyślnie `['OLEDB']`. W środowisku, w którym DMV-y odpytuje
  własny monitoring, `OLEDB` generuje setki milionów mikrooczekiwań
  (obserwowane 184 mln przy średniej 0,02 ms), które zaśmiecają tabelę.
  Jeśli używasz serwerów połączonych i chcesz je widzieć — usuń wpis.
  Nazwy typów są filtrowane wyrażeniem `^[A-Z0-9_]+$` przed wstawieniem do SQL.
- **Nagłówek instancji pokazuje MAXDOP, Cost Threshold for Parallelism
  i liczbę węzłów NUMA.** Cost Threshold ≤ 5 (wartość domyślna od 2000 roku)
  jest oznaczany ostrzeżeniem — przy niej drobne zapytania niepotrzebnie idą
  równolegle, co nabija `SOS_SCHEDULER_YIELD`.
- **Plakietka wersji** w listwie, z changelogiem pod kursorem.

### Kolektor historii (`08_kolektor_historii.sql`)

- Te same poprawki metryk: `aktywne_zapytania`, `zablokowane_sesje`,
  `zadania_oczekujace`, `ple_sek`.
- Nowa kolumna `najdluzsze_zapytanie_sek` w `dbo.Snapshot_Serwer`
  wraz z `ALTER TABLE` dla istniejących instalacji (historia nie jest tracona).
- Kolumna dodana do widoku `dbo.vw_ObciazenieGodzinowe` i do zakładki Historia.

## 9.0.0

Wersja bazowa przekazana do poprawek: dziewięć skryptów diagnostycznych,
dashboard PHP z zakładkami, wykresy canvas, eksport CSV, tryb nocny.
