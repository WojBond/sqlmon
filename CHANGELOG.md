# SQLMON — historia zmian

Numer wersji ma jedno źródło prawdy: stała `APP_VERSION` w `sqlmon.php`.
Nazwa paczki jest z niej generowana przez `zbuduj_paczke.sh`.

## 10.0.0

Wersja uniwersalna. **Zmiana łamiąca zgodność**: konfiguracja nie mieszka już
w kodzie, więc po aktualizacji trzeba raz przejść przez `konfiguracja.php`.

### Konfiguracja w bazie zamiast w pliku

- Serwery, instancje, loginy, hasła, progi i ustawienia trzyma SQLite
  (`dane/sqlmon.sqlite`). Ten sam `sqlmon.php` działa na każdym wdrożeniu bez
  edycji kodu, a aktualizacja wersji nie nadpisuje ustawień.
- Nowy ekran **`konfiguracja.php`**: dodawanie i edycja serwerów oraz instancji,
  ustawienia globalne, progi ostrzeżeń, zmiana haseł.
- **Model dwupoziomowy.** Serwer to maszyna (nazwa, adres, opis). Instancja
  należy do serwera i dokłada nazwę instancji oraz port. Adres połączenia
  powstaje automatycznie: `HOST`, `HOST\INSTANCJA`, `HOST,PORT` lub oba naraz.
- Instancje są indeksowane identyfikatorem, nie nazwą — „PROD" może istnieć
  na kilku serwerach naraz i nie będą się nadpisywać.
- **Baza historii ustawiana per instancja.** Wcześniej była jedna wartość dla
  całego pulpitu, co nie działało, gdy kolektor stoi tylko na części instancji.
- Instancję można wyłączyć bez kasowania — znika z przełącznika, konfiguracja
  zostaje.
- **Test połączenia** wprost z GUI: mierzy czas, pokazuje nazwę, edycję i wersję
  instancji oraz sprawdza, czy konto ma `VIEW SERVER STATE`.

### Hasła

- Hasła do instancji szyfrowane AES-256-GCM. Klucz w `dane/klucz.php`, poza bazą,
  generowany przy pierwszym uruchomieniu.
- Przy edycji instancji puste pole hasła oznacza „zostaw dotychczasowe".
- Przełączenie na uwierzytelnianie Windows czyści login i szyfrogram, żeby
  w bazie nie zostawał osierocony sekret.
- Osobne **hasło administratora** (`password_hash`) chroni ekran konfiguracji.
  Hasło pulpitu pozostaje opcjonalne i niezależne.
- Formularze zapisu chronione tokenem CSRF.

### Zakres ochrony haseł — przeczytaj

Pulpit musi odszyfrować hasło, żeby połączyć się z instancją, więc klucz leży
na tym samym serwerze. Kto ma dostęp do obu plików, ma hasła. Szyfrowanie chroni
przed przypadkowym podejrzeniem przy przeglądaniu bazy i przed wyciekiem samej
kopii pliku — nie przed kimś z dostępem do systemu plików. Realne zabezpieczenie
to konto SQL tylko do odczytu, katalog danych poza drzewem WWW (stała
`SQLMON_DANE`) i uwierzytelnianie Windows tam, gdzie się da.

### Migracja z 9.x

1. Wgraj `sqlmon.php`, `konfiguracja.php` i `wspolne.php`.
2. Otwórz `konfiguracja.php` — poprosi o ustawienie hasła administratora.
3. Przepisz serwery i instancje ze starej tablicy `$KONFIG`. Progi i ustawienia
   znajdziesz w tej samej sekcji ekranu.
4. Usuń stary plik pulpitu, żeby nie zostawiać haseł w kodzie.

## 9.2.0

Pierwsza wersja, w której przejrzano komplet skryptów `.sql`, a nie tylko pulpit.

### Braki

- **Brakował skrypt `09_kolektor_zapytan_xe.sql`**, mimo że `sqlmon.php` wprost
  odsyła do niego użytkownika, gdy sesja Extended Events nie działa. Skrypt został
  dopisany: zakłada sesję `sqlmon_zapytania` z progiem 3 s, buforem pierścieniowym
  i trybem `ALLOW_SINGLE_EVENT_LOSS`.
- README nie wymieniał w spisie zawartości ani pulpitu, ani skryptu 09.

### Błędy w skryptach

- **`06_tempdb_i_indeksy.sql`, sekcja PAGELATCH** filtrowała wyłącznie
  `resource_description LIKE '2:%'`, czyli tempdb. Kontencja na ostatniej stronie
  indeksu w bazie produkcyjnej nie pojawiała się w wyniku w ogóle, co prowadziło
  do fałszywego wniosku, że problemu nie ma. Sekcja obejmuje teraz wszystkie bazy,
  interpretuje zasób i została uzupełniona o rozwinięcie strony na konkretny obiekt
  (`sys.dm_db_page_info`) oraz kontrolę konfiguracji plików tempdb.
- **`04_oczekiwania.sql`** nie wykluczał `SOS_WORK_DISPATCHER` ani sześciu innych
  oczekiwań bezczynnych, które pulpit odfiltrowuje. Ten jeden typ potrafi zająć
  ponad 80% zestawienia i przysłonić realne sygnały — skrypt opisany w README jako
  punkt startowy diagnozy dawał przez to inny obraz niż pulpit.
- **`05_aktywnosc_i_blokady.sql`, drzewo blokad** zapętlało się przy samoblokadzie
  wątków zapytania równoległego (`blocking_session_id = session_id`) i kończyło
  błędem 530. Dodano warunek wykluczający oraz `OPTION (MAXRECURSION 50)`.
- **`00_przeglad_serwera.sql`** używał heurystyki `session_id > 50`, liczył
  samoblokady jako blokady i czytał PLE bez uwzględnienia węzłów NUMA — czyli
  trzech błędów poprawionych wcześniej w pulpicie. Metryki są teraz spójne.

### README

- Progi orientacyjne uzgodnione z instrukcją PDF (runnable tasks, PLE).
- Uwaga o środowiskach z MAXDOP 1: brak `CXPACKET` nie jest tam dobrą wiadomością.
- Nowy wiersz w tabeli kierującej: `PAGELATCH_EX` poza tempdb.

## 9.1.2

### Poprawki

- **Eksport CSV — separator dziesiętny.** Liczby były eksportowane z kropką,
  którą Excel z polskimi ustawieniami interpretował jako datę (`7.57` → `lip.57`).
  Wartości liczbowe eksportowane są teraz z przecinkiem.
- **Eksport CSV — ochrona przed formułą.** Komórka zaczynająca się od `=`, `+`,
  `-` lub `@` była wykonywana przez Excela jako formuła. Ponieważ dane pochodzą
  z nazw programów i treści zapytań, jest to droga wstrzyknięcia. Takie komórki
  są poprzedzane apostrofem.
- **Parametry przesłane jako tablica powodowały błąd 500.** `haslo[]=x` wywracał
  `hash_equals()`, a `?serwer[]=x` — odwołanie do klucza tablicy. Oba miejsca
  sprawdzają teraz typ.
- **`session_regenerate_id()` po poprawnym logowaniu** — ochrona przed
  utrwaleniem identyfikatora sesji.
- **Walidacja nazwy bazy historii** przed wstawieniem jej do zapytania jako
  identyfikatora (nie da się jej sparametryzować).

### Instrukcja obsługi

- Nowy rozdział „Wartości referencyjne”: zakresy dla kafelków, średnie czasy
  oczekiwań, latencja plików, przeliczenie progu PLE względem wielkości bufora.
- Nowy rozdział „Na co zwracać uwagę”: pułapki interpretacyjne, kombinacje
  wskazań, specyfika środowisk ERP z MAXDOP 1, rutyna przeglądu.
- Nowy rozdział „Zapytania pogłębiające” — gotowe zapytania diagnostyczne.

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
