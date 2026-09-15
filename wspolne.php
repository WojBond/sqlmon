<?php
/*==============================================================================
  SQLMON – warstwa wspólna
  Magazyn konfiguracji (SQLite), szyfrowanie haseł, uwierzytelnianie.

  Dołączany przez sqlmon.php i konfiguracja.php.
==============================================================================*/

declare(strict_types=1);

/*------------------------------------------------------------------ WERSJA */

define('APP_VERSION', '10.1.2');
define('APP_CHANGELOG', [
    '10.1.2' => [
        'Nieudane odświeżenie nie kasuje już widoku — pasek nad zachowanymi danymi',
        'Jedna cicha próba ponowna po zerwanym połączeniu',
        'Limit czasu żądania 45 s zamiast czekania w nieskończoność',
        'Odpowiedź HTML zamiast JSON pokazywana jako treść błędu, nie jako błąd parsera',
    ],
    '10.1.1' => [
        'Sekcja "Połączenia spoza serwera aplikacji": poprawiony alias kolumny client_interface_name',
        'Kafelek "Najdłuższe zapytanie" pomija sesje SQL Server Agent i kont NT SERVICE',
    ],
    '10.1.0' => [
        'index.php jest stroną startową: kafelek na serwer, wiersz na instancję',
        'Kafelek serwera przyjmuje stan najgorszej ze swoich instancji',
        'Pulpit pracuje w zakresie jednego serwera — przełącznik pokazuje tylko jego instancje',
        'Przycisk powrotu nazywa się teraz "Serwery" i prowadzi do index.php',
        'Numer wersji przeniesiony do wspolne.php — wspólny dla wszystkich ekranów',
    ],
    '10.0.0' => [
        'Konfiguracja przeniesiona z kodu do bazy SQLite — koniec edycji pliku przy wdrożeniu',
        'Nowy ekran konfiguracja.php: serwery, instancje, ustawienia i progi z poziomu GUI',
        'Model dwupoziomowy: serwer (maszyna) → wiele instancji (domyślna, nazwane, porty)',
        'Hasła instancji szyfrowane AES-256-GCM, klucz poza bazą',
        'Test połączenia z GUI, ze sprawdzeniem uprawnienia VIEW SERVER STATE',
        'Baza historii ustawiana per instancja, nie globalnie',
        'Osobne hasło administratora (password_hash) i opcjonalne hasło pulpitu',
    ],
    '9.2.0' => [
        'Nowy skrypt 09_kolektor_zapytan_xe.sql — pulpit odsyłał do nieistniejącego pliku',
        'Skrypt 06: diagnostyka PAGELATCH obejmuje wszystkie bazy, nie tylko tempdb',
        'Skrypt 04: dopisane wykluczenie SOS_WORK_DISPATCHER i sześciu innych oczekiwań bezczynnych',
        'Skrypt 05: drzewo blokad odporne na samoblokadę wątków równoległych',
        'Skrypt 00: metryki spójne z pulpitem (sesje użytkowników, samoblokady, PLE na NUMA)',
    ],
    '9.1.2' => [
        'Eksport CSV: liczby z przecinkiem dziesiętnym (polski Excel czytał 7.57 jako lipiec)',
        'Eksport CSV: ochrona przed wykonaniem formuły z komórki (= + - @)',
        'Logowanie i routing odporne na parametry przesłane jako tablica',
        'session_regenerate_id po poprawnym logowaniu',
        'Walidacja nazwy bazy historii przed wstawieniem do zapytania',
    ],
    '9.1.1' => [
        'Kafelek "Najdłuższe zapytanie": wykluczenie sesji zaparkowanych na wiecznym '
        . 'oczekiwaniu (Service Broker, WAITFOR, replikacja, CDC) + limit godzinowy',
        'Ostrzeżenie o Cost Threshold tylko przy MAXDOP innym niż 1',
        'Czytelny opis dla PREEMPTIVE_OS_*, OLEDB i kolejki kompilacji planów',
    ],
    '9.1.0' => [
        'Kafelek "Najdłuższe zapytanie" pomija sesje systemowe, tła i sesję dashboardu',
        'Kafelek "Aktywne zapytania" liczy wyłącznie sesje użytkowników (było: session_id > 50)',
        'Kafelek "Zablokowane sesje" pomija samoblokady wątków równoległych',
        'Page life expectancy odporne na NUMA — minimum z węzłów Buffer Node',
        'Konfigurowalna lista pomijanych typów oczekiwań (domyślnie OLEDB)',
        'Nagłówek pokazuje MAXDOP, Cost Threshold i liczbę węzłów NUMA',
        'Kolektor historii: te same poprawki metryk + nowa kolumna najdluzsze_zapytanie_sek',
    ],
    '9.0.0' => ['Wersja bazowa przekazana do poprawek'],
]);

/*------------------------------------------------------------------ ŚCIEŻKI */

// Katalog z danymi. Domyślnie podkatalog "dane" obok skryptów, ale MOŻNA
// i WARTO wskazać katalog poza drzewem serwera WWW — wtedy nawet przy awarii
// konfiguracji Apache nikt nie pobierze pliku bazy przez przeglądarkę.
// Przykład: define('SQLMON_DANE', 'C:/sqlmon-dane');
if (!defined('SQLMON_DANE')) {
    define('SQLMON_DANE', __DIR__ . DIRECTORY_SEPARATOR . 'dane');
}

define('SQLMON_BAZA',  SQLMON_DANE . DIRECTORY_SEPARATOR . 'sqlmon.sqlite');
define('SQLMON_KLUCZ', SQLMON_DANE . DIRECTORY_SEPARATOR . 'klucz.php');

/*============================================================== SEJF (hasła) */

/**
 * Szyfrowanie haseł do instancji SQL Server.
 *
 * WAŻNE, przeczytaj zanim uznasz to za zabezpieczenie:
 * Pulpit musi umieć odszyfrować hasło, żeby połączyć się z instancją. Klucz
 * leży więc na tym samym serwerze co baza. Kto ma dostęp do obu plików, ma
 * hasła. To chroni przed:
 *   - przypadkowym podejrzeniem haseł przy przeglądaniu bazy SQLite,
 *   - wyciekiem samej kopii bazy (backup, kopia na pendrive),
 *   - wysłaniem pliku bazy do analizy komuś z zewnątrz.
 * To NIE chroni przed kimś, kto ma dostęp do systemu plików serwera.
 *
 * Realne zabezpieczenie to: konto SQL tylko do odczytu z VIEW SERVER STATE
 * (nigdy sa), katalog danych poza drzewem WWW i uwierzytelnianie Windows
 * tam, gdzie jest możliwe — wtedy hasła w ogóle nie ma.
 */
final class Sejf
{
    private string $klucz;

    public function __construct()
    {
        $this->klucz = self::wczytajLubUtworzKlucz();
    }

    private static function wczytajLubUtworzKlucz(): string
    {
        if (is_file(SQLMON_KLUCZ)) {
            /** @noinspection PhpIncludeInspection */
            $k = require SQLMON_KLUCZ;
            if (is_string($k) && strlen($k) === 64 && ctype_xdigit($k)) {
                return hex2bin($k);
            }
            throw new RuntimeException('Plik klucza jest uszkodzony: ' . SQLMON_KLUCZ);
        }

        Magazyn::przygotujKatalog();
        $surowy = random_bytes(32);

        // Klucz zapisujemy jako plik PHP: nawet gdy serwer WWW przestanie
        // wykonywać PHP i zacznie serwować pliki jako tekst, przeglądarka
        // dostanie pusty wynik, a nie zawartość klucza.
        $tresc = "<?php\n"
               . "// Klucz szyfrujący SQLMON. NIE KASOWAĆ i NIE ZMIENIAĆ.\n"
               . "// Utracenie tego pliku oznacza konieczność wpisania haseł od nowa.\n"
               . "// Wygenerowano: " . date('Y-m-d H:i:s') . "\n"
               . "return '" . bin2hex($surowy) . "';\n";

        if (file_put_contents(SQLMON_KLUCZ, $tresc, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać klucza: ' . SQLMON_KLUCZ);
        }
        @chmod(SQLMON_KLUCZ, 0600);

        return $surowy;
    }

    /** Zwraca ciąg postaci  v1:<base64 iv>:<base64 tag>:<base64 szyfrogram> */
    public function zaszyfruj(string $jawne): string
    {
        if ($jawne === '') return '';
        $iv  = random_bytes(12);                 // GCM: 96 bitów to wartość zalecana
        $tag = '';
        $szyfr = openssl_encrypt($jawne, 'aes-256-gcm', $this->klucz,
                                 OPENSSL_RAW_DATA, $iv, $tag);
        if ($szyfr === false) {
            throw new RuntimeException('Szyfrowanie nie powiodło się.');
        }
        return 'v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($szyfr);
    }

    public function odszyfruj(string $zapis): string
    {
        if ($zapis === '') return '';
        $czesci = explode(':', $zapis, 4);
        if (count($czesci) !== 4 || $czesci[0] !== 'v1') {
            throw new RuntimeException('Nieznany format zaszyfrowanego hasła.');
        }
        $jawne = openssl_decrypt(
            base64_decode($czesci[3]), 'aes-256-gcm', $this->klucz,
            OPENSSL_RAW_DATA, base64_decode($czesci[1]), base64_decode($czesci[2])
        );
        if ($jawne === false) {
            // Najczęstsza przyczyna: podmieniony lub odtworzony plik klucza.
            throw new RuntimeException(
                'Nie udało się odszyfrować hasła. Czy plik klucza jest ten sam co przy zapisie?');
        }
        return $jawne;
    }
}

/*=========================================================== MAGAZYN (SQLite) */

final class Magazyn
{
    private PDO $db;
    private Sejf $sejf;
    private static ?Magazyn $instancja = null;

    public static function daj(): Magazyn
    {
        return self::$instancja ??= new self();
    }

    private function __construct()
    {
        self::przygotujKatalog();

        $this->db = new PDO('sqlite:' . SQLMON_BAZA, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec('PRAGMA busy_timeout = 5000');

        $this->sejf = new Sejf();
        $this->migruj();
    }

    public static function przygotujKatalog(): void
    {
        if (!is_dir(SQLMON_DANE) && !@mkdir(SQLMON_DANE, 0700, true) && !is_dir(SQLMON_DANE)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu danych: ' . SQLMON_DANE);
        }
        // Zapora dla Apache, na wypadek gdyby katalog danych leżał w drzewie WWW.
        $ht = SQLMON_DANE . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht,
                "# Katalog danych SQLMON – brak dostępu przez WWW\n" .
                "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        $idx = SQLMON_DANE . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($idx)) @file_put_contents($idx, '');
    }

    public function pdo(): PDO  { return $this->db; }
    public function sejf(): Sejf { return $this->sejf; }

    /*---------------------------------------------------------- SCHEMAT */

    private function migruj(): void
    {
        $wersja = (int)$this->db->query('PRAGMA user_version')->fetchColumn();

        if ($wersja < 1) {
            $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS serwery (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nazwa       TEXT    NOT NULL UNIQUE,
    host        TEXT    NOT NULL,
    opis        TEXT    NOT NULL DEFAULT '',
    kolejnosc   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS instancje (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    serwer_id       INTEGER NOT NULL REFERENCES serwery(id) ON DELETE CASCADE,
    nazwa           TEXT    NOT NULL,
    instancja       TEXT    NOT NULL DEFAULT '',
    port            INTEGER NULL,
    baza            TEXT    NOT NULL DEFAULT 'master',
    baza_historii   TEXT    NOT NULL DEFAULT 'DBA_Monitoring',
    windows_auth    INTEGER NOT NULL DEFAULT 0,
    uzytkownik      TEXT    NOT NULL DEFAULT '',
    haslo_szyfr     TEXT    NOT NULL DEFAULT '',
    aktywna         INTEGER NOT NULL DEFAULT 1,
    kolejnosc       INTEGER NOT NULL DEFAULT 0,
    UNIQUE (serwer_id, nazwa)
);

CREATE TABLE IF NOT EXISTS ustawienia (
    klucz    TEXT PRIMARY KEY,
    wartosc  TEXT NOT NULL
);
SQL);
            $this->db->exec('PRAGMA user_version = 1');
        }
    }

    /*------------------------------------------------------- USTAWIENIA */

    public const DOMYSLNE = [
        'odswiezanie_sek'            => '30',
        'strona_glowna'              => 'index.php',
        'adres_iis'                  => '',
        'pomijaj_oczekiwania'        => 'OLEDB',
        'limit_dlugiego_zapytania_h' => '24',
        'prog_cpu_uwaga'             => '70',
        'prog_cpu_alarm'             => '85',
        'prog_ple_uwaga'             => '900',
        'prog_ple_alarm'             => '300',
        'prog_latencja_uwaga'        => '20',
        'prog_latencja_alarm'        => '50',
        'prog_blokady_uwaga'         => '1',
        'prog_blokady_alarm'         => '5',
        'prog_dlugie_zapytanie'      => '30000',
        'haslo_pulpitu'              => '',   // puste = pulpit bez logowania
        'haslo_admin_hash'           => '',   // puste = pierwsze uruchomienie
    ];

    public function ustawienia(): array
    {
        $z = [];
        foreach ($this->db->query('SELECT klucz, wartosc FROM ustawienia') as $w) {
            $z[$w['klucz']] = $w['wartosc'];
        }
        return $z + self::DOMYSLNE;
    }

    public function ustaw(string $klucz, string $wartosc): void
    {
        $st = $this->db->prepare(
            'INSERT INTO ustawienia (klucz, wartosc) VALUES (?, ?)
             ON CONFLICT(klucz) DO UPDATE SET wartosc = excluded.wartosc');
        $st->execute([$klucz, $wartosc]);
    }

    /*---------------------------------------------------------- SERWERY */

    public function serwery(): array
    {
        return $this->db->query(
            'SELECT * FROM serwery ORDER BY kolejnosc, nazwa')->fetchAll();
    }

    public function serwer(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM serwery WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function zapiszSerwer(?int $id, string $nazwa, string $host,
                                 string $opis, int $kolejnosc): int
    {
        if ($id === null) {
            $st = $this->db->prepare(
                'INSERT INTO serwery (nazwa, host, opis, kolejnosc) VALUES (?, ?, ?, ?)');
            $st->execute([$nazwa, $host, $opis, $kolejnosc]);
            return (int)$this->db->lastInsertId();
        }
        $st = $this->db->prepare(
            'UPDATE serwery SET nazwa = ?, host = ?, opis = ?, kolejnosc = ? WHERE id = ?');
        $st->execute([$nazwa, $host, $opis, $kolejnosc, $id]);
        return $id;
    }

    public function usunSerwer(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM serwery WHERE id = ?');
        $st->execute([$id]);   // instancje znikają kaskadowo
    }

    /*-------------------------------------------------------- INSTANCJE */

    /** Instancje danego serwera, albo wszystkie gdy $serwerId === null. */
    public function instancje(?int $serwerId = null, bool $tylkoAktywne = false): array
    {
        $sql = 'SELECT i.*, s.nazwa AS serwer_nazwa, s.host AS serwer_host
                FROM instancje i JOIN serwery s ON s.id = i.serwer_id';
        $gdzie = [];
        $arg   = [];
        if ($serwerId !== null) { $gdzie[] = 'i.serwer_id = ?'; $arg[] = $serwerId; }
        if ($tylkoAktywne)      { $gdzie[] = 'i.aktywna = 1'; }
        if ($gdzie) $sql .= ' WHERE ' . implode(' AND ', $gdzie);
        $sql .= ' ORDER BY s.kolejnosc, s.nazwa, i.kolejnosc, i.nazwa';

        $st = $this->db->prepare($sql);
        $st->execute($arg);
        return $st->fetchAll();
    }

    public function instancja(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT i.*, s.nazwa AS serwer_nazwa, s.host AS serwer_host
             FROM instancje i JOIN serwery s ON s.id = i.serwer_id WHERE i.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * @param string|null $hasloJawne null = zostaw dotychczasowe hasło bez zmian
     */
    public function zapiszInstancje(?int $id, array $d, ?string $hasloJawne): int
    {
        $pola = [
            'serwer_id'     => (int)$d['serwer_id'],
            'nazwa'         => (string)$d['nazwa'],
            'instancja'     => (string)($d['instancja'] ?? ''),
            'port'          => $d['port'] !== null && $d['port'] !== '' ? (int)$d['port'] : null,
            'baza'          => (string)($d['baza'] ?? 'master'),
            'baza_historii' => (string)($d['baza_historii'] ?? ''),
            'windows_auth'  => !empty($d['windows_auth']) ? 1 : 0,
            'uzytkownik'    => (string)($d['uzytkownik'] ?? ''),
            'aktywna'       => !empty($d['aktywna']) ? 1 : 0,
            'kolejnosc'     => (int)($d['kolejnosc'] ?? 0),
        ];

        // Uwierzytelnianie Windows nie używa loginu ani hasła – czyścimy oba,
        // żeby w bazie nie zostawał osierocony szyfrogram.
        if ($pola['windows_auth'] === 1) {
            $pola['uzytkownik'] = '';
            $hasloJawne = '';
        }

        if ($id === null) {
            $pola['haslo_szyfr'] = $this->sejf->zaszyfruj((string)$hasloJawne);
            $kol = implode(', ', array_keys($pola));
            $zn  = implode(', ', array_fill(0, count($pola), '?'));
            $st  = $this->db->prepare("INSERT INTO instancje ($kol) VALUES ($zn)");
            $st->execute(array_values($pola));
            return (int)$this->db->lastInsertId();
        }

        if ($hasloJawne !== null) {
            $pola['haslo_szyfr'] = $this->sejf->zaszyfruj($hasloJawne);
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($pola)));
        $st  = $this->db->prepare("UPDATE instancje SET $set WHERE id = ?");
        $st->execute([...array_values($pola), $id]);
        return $id;
    }

    public function usunInstancje(int $id): void
    {
        $st = $this->db->prepare('DELETE FROM instancje WHERE id = ?');
        $st->execute([$id]);
    }

    /** Wiersz instancji zamieniony na tablicę zrozumiałą dla polacz(). */
    public function parametryPolaczenia(array $inst): array
    {
        return [
            'host'         => self::zbudujHost($inst),
            'baza'         => $inst['baza'] !== '' ? $inst['baza'] : 'master',
            'uzytkownik'   => $inst['uzytkownik'],
            'haslo'        => $inst['haslo_szyfr'] !== ''
                              ? $this->sejf->odszyfruj($inst['haslo_szyfr']) : '',
            'windows_auth' => (bool)$inst['windows_auth'],
        ];
    }

    /**
     * Składa adres w formacie akceptowanym przez sterownik:
     *   HOST                    – instancja domyślna, port 1433
     *   HOST\INSTANCJA          – instancja nazwana (SQL Browser)
     *   HOST,PORT               – port jawny
     *   HOST\INSTANCJA,PORT     – oba naraz
     */
    public static function zbudujHost(array $inst): string
    {
        $h = $inst['serwer_host'] ?? $inst['host'] ?? 'localhost';
        if (!empty($inst['instancja'])) $h .= '\\' . $inst['instancja'];
        if (!empty($inst['port']))      $h .= ',' . (int)$inst['port'];
        return $h;
    }

    /** Etykieta w przełączniku: "SERWER / INSTANCJA" albo sama nazwa. */
    public static function etykieta(array $inst): string
    {
        $s = $inst['serwer_nazwa'] ?? '';
        return $s !== '' && $s !== $inst['nazwa'] ? $s . ' / ' . $inst['nazwa'] : $inst['nazwa'];
    }
}

/*========================================================= UWIERZYTELNIANIE */

function sesjaStart(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function tokenCsrf(): string
{
    sesjaStart();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function sprawdzCsrf(): bool
{
    sesjaStart();
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function czyAdmin(): bool
{
    sesjaStart();
    return !empty($_SESSION['sqlmon_admin']);
}

/** Walidacja nazwy bazy używanej jako identyfikator SQL (nie da się jej związać). */
function bazaOk(string $baza): bool
{
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_$#-]{0,127}$/', $baza);
}

function h(?string $t): string
{
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}
