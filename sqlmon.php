<?php
/*==============================================================================
  SQLMON – lekki monitor obciążenia MS SQL Server
  Jeden plik PHP, bez zewnętrznych bibliotek. Do uruchomienia pod XAMPP.

  Wymaga rozszerzenia pdo_sqlsrv (lub sqlsrv). Instrukcja instalacji wyświetli
  się automatycznie, jeśli sterownika brakuje.

  Wrzuć plik do C:\xampp\htdocs\sqlmon\sqlmon.php i otwórz
  http://localhost/sqlmon/sqlmon.php
==============================================================================*/


/*--------------------------------------------------------------- KONFIGURACJA */

/*  Od wersji 10.0.0 konfiguracja nie mieszka już w tym pliku.
    Serwery, instancje, loginy i hasła trzyma baza SQLite, a zarządza nimi
    konfiguracja.php. Dzięki temu ten sam plik pulpitu działa wszędzie bez
    edycji kodu, a aktualizacja wersji nie nadpisuje ustawień.

    Katalog danych można przenieść poza drzewo serwera WWW, definiując
    SQLMON_DANE PRZED dołączeniem wspolne.php, np.:
        define('SQLMON_DANE', 'C:/sqlmon-dane');                                */

require __DIR__ . '/wspolne.php';

date_default_timezone_set('Europe/Warsaw');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');
sesjaStart();

try {
    $mag = Magazyn::daj();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>SQLMON</title>'
       . '<body style="font:14px system-ui;padding:40px;max-width:700px">'
       . '<h1>Nie udało się otworzyć konfiguracji</h1><p>' . h($e->getMessage()) . '</p>'
       . '<p>Najczęstsza przyczyna: proces serwera WWW nie ma prawa zapisu do katalogu danych.</p>';
    exit;
}

$UST       = $mag->ustawienia();
$INSTANCJE = $mag->instancje(null, true);

/*  Tablica $KONFIG jest zachowana, bo reszta pliku i tak z niej korzysta —
    zmieniło się tylko to, skąd pochodzą wartości. Klucz 'serwery' jest
    indeksowany identyfikatorem instancji, nie nazwą: nazwy mogą się powtarzać
    między serwerami ("PROD" na ASTAT i "PROD" na ARSEN to dwie różne rzeczy).  */
$KONFIG = [
    'serwery'                    => [],
    'odswiezanie_sek'            => (int)$UST['odswiezanie_sek'],
    'haslo_dostepu'              => (string)$UST['haslo_pulpitu'],
    'strona_glowna'              => (string)$UST['strona_glowna'],
    'adres_iis'                  => (string)$UST['adres_iis'],
    'pomijaj_oczekiwania'        => array_values(array_filter(array_map(
                                        'trim', explode(',', (string)$UST['pomijaj_oczekiwania'])))),
    'limit_dlugiego_zapytania_h' => (int)$UST['limit_dlugiego_zapytania_h'],
    'baza_historii'              => '',   // ustalana per instancja, niżej
    'progi' => [
        'cpu_uwaga'        => (int)$UST['prog_cpu_uwaga'],
        'cpu_alarm'        => (int)$UST['prog_cpu_alarm'],
        'ple_uwaga'        => (int)$UST['prog_ple_uwaga'],
        'ple_alarm'        => (int)$UST['prog_ple_alarm'],
        'latencja_uwaga'   => (int)$UST['prog_latencja_uwaga'],
        'latencja_alarm'   => (int)$UST['prog_latencja_alarm'],
        'blokady_uwaga'    => (int)$UST['prog_blokady_uwaga'],
        'blokady_alarm'    => (int)$UST['prog_blokady_alarm'],
        'dlugie_zapytanie' => (int)$UST['prog_dlugie_zapytanie'],
    ],
];

/*  Pulpit pracuje w zakresie JEDNEGO serwera. Wyboru serwera dokonuje się na
    stronie startowej (index.php), a tutaj przełącznik pokazuje już tylko
    instancje tej maszyny. Bez parametru bierzemy pierwszy serwer z listy —
    dzięki temu wejście wprost na sqlmon.php nadal działa.                     */
$SERWER_ID = isset($_GET['serwer_id']) && is_numeric($_GET['serwer_id'])
    ? (int)$_GET['serwer_id'] : 0;

if ($SERWER_ID === 0 && $INSTANCJE) {
    $SERWER_ID = (int)$INSTANCJE[0]['serwer_id'];
}

$SERWER = $SERWER_ID ? $mag->serwer($SERWER_ID) : null;

/*  $KONFIG['serwery'] zawiera WSZYSTKIE aktywne instancje, bo z endpointu
    korzysta także strona startowa, która odpytuje instancje wszystkich maszyn
    naraz. Zawężenie do jednego serwera dotyczy wyłącznie przełącznika
    w interfejsie — lista do niego powstaje niżej, jako $SERWERY_WIDOK.        */
foreach ($INSTANCJE as $i) {
    $KONFIG['serwery'][(string)$i['id']] = $mag->parametryPolaczenia($i)
        + ['etykieta'   => $i['nazwa'],
           'serwer_id'  => (int)$i['serwer_id'],
           'baza_historii' => $i['baza_historii']];
}

/*----------------------------------------------------------------- MECHANIZM */

function sterownikDostepny(): bool
{
    return in_array('sqlsrv', PDO::getAvailableDrivers(), true);
}

function polacz(array $serwer, ?string $baza = null): PDO
{
    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=1;LoginTimeout=5',
        $serwer['host'],
        $baza ?? $serwer['baza']
    );

    // UWAGA: pdo_sqlsrv obsługuje w konstruktorze tylko wąski zbiór atrybutów.
    // PDO::ATTR_TIMEOUT i PDO::ATTR_DEFAULT_FETCH_MODE powodują błąd IMSSP,
    // dlatego limit czasu ustawiamy w DSN (LoginTimeout), a resztę po połączeniu.
    $opcje = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    $pdo = empty($serwer['windows_auth'])
        ? new PDO($dsn, $serwer['uzytkownik'], $serwer['haslo'], $opcje)
        : new PDO($dsn, null, null, $opcje);

    // Ustawienia opcjonalne – gdy sterownik ich nie zna, po prostu je pomijamy.
    try { $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
        try { $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 20); } catch (Throwable $e) {}
    }

    return $pdo;
}

function pobierz(PDO $pdo, string $sql, array $parametry = []): array
{
    $pelne = "SET NOCOUNT ON; SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; " . $sql;

    if ($parametry) {
        $stmt = $pdo->prepare($pelne);
        $stmt->execute($parametry);
    } else {
        $stmt = $pdo->query($pelne);
    }

    $wynik = [];
    do {
        try {
            $partia = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($partia) { $wynik = $partia; }
        } catch (PDOException $e) {
            // zestaw bez kolumn (np. po SET/DECLARE) – pomijamy
        }
        try {
            $dalej = $stmt->nextRowset();
        } catch (PDOException $e) {
            $dalej = false;
        }
    } while ($dalej);
    return $wynik;
}

/** Sekcja odporna na błąd – jeśli zapytanie padnie, zwraca komunikat zamiast wywracać stronę. */
function sekcja(PDO $pdo, string $tytul, string $opis, string $sql, array $parametry = []): array
{
    try {
        $wiersze = pobierz($pdo, $sql, $parametry);
        return [
            'tytul'   => $tytul,
            'opis'    => $opis,
            'kolumny' => $wiersze ? array_keys($wiersze[0]) : [],
            'wiersze' => $wiersze,
            'blad'    => null,
        ];
    } catch (Throwable $e) {
        return [
            'tytul' => $tytul, 'opis' => $opis, 'kolumny' => [], 'wiersze' => [],
            'blad'  => $e->getMessage(),
        ];
    }
}

function stan($wartosc, $uwaga, $alarm, bool $mniejLepiej = true): string
{
    if ($wartosc === null) return 'brak';
    if ($mniejLepiej) {
        if ($wartosc >= $alarm) return 'alarm';
        if ($wartosc >= $uwaga) return 'uwaga';
    } else {
        if ($wartosc <= $alarm) return 'alarm';
        if ($wartosc <= $uwaga) return 'uwaga';
    }
    return 'dobry';
}

/*------------------------------------------------------------------ ZAPYTANIA */

const Q_CPU_RING = <<<SQL
DECLARE @ts_now BIGINT = (SELECT cpu_ticks / (cpu_ticks / ms_ticks) FROM sys.dm_os_sys_info);
SELECT TOP (60)
    CONVERT(VARCHAR(5), DATEADD(ms, -1 * (@ts_now - rb.[timestamp]), GETDATE()), 108) AS czas,
    rb.SQLProcessUtilization AS cpu_sql,
    100 - rb.SystemIdle - rb.SQLProcessUtilization AS cpu_inne
FROM (
    SELECT
        x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/SystemIdle)[1]','int') AS SystemIdle,
        x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/ProcessUtilization)[1]','int') AS SQLProcessUtilization,
        x.record.value('(./Record/@id)[1]','int') AS record_id,
        x.[timestamp]
    FROM (
        SELECT [timestamp], CONVERT(XML, record) AS record
        FROM sys.dm_os_ring_buffers
        WHERE ring_buffer_type = N'RING_BUFFER_SCHEDULER_MONITOR'
          AND record LIKE N'%<SystemHealth>%'
    ) AS x
) AS rb
ORDER BY rb.record_id DESC;
SQL;

/**
 * Zapytanie kafelków KPI. Limit dla metryki "najdłuższe zapytanie" jest
 * konfigurowalny — powyżej niego niemal na pewno mamy sesję zaparkowaną,
 * a nie realne zapytanie użytkownika.
 */
function qKafelki(int $limitGodzin = 24): string
{
    $limitGodzin = max(1, min(720, $limitGodzin));
    return str_replace('/*LIMIT_MS*/86400000',
                       (string)($limitGodzin * 3600000), Q_KAFELKI_SZABLON);
}

const Q_KAFELKI_SZABLON = <<<SQL
SELECT
    -- PLE: na serwerach NUMA licznik "Buffer Manager" potrafi mylić. Jeśli są
    -- węzły "Buffer Node", bierzemy z nich minimum (najgorszy węzeł decyduje).
    ISNULL(
      (SELECT MIN(cntr_value) FROM sys.dm_os_performance_counters
        WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Node%'),
      (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters
        WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Manager%')
    )                                                                                      AS ple,
    (SELECT physical_memory_in_use_kb / 1024 FROM sys.dm_os_process_memory)                AS pamiec_mb,
    (SELECT available_physical_memory_kb / 1024 FROM sys.dm_os_sys_memory)                 AS wolne_mb,
    (SELECT COUNT(*) FROM sys.dm_exec_sessions WHERE is_user_process = 1)                  AS sesje,
    -- Tylko sesje użytkowników. Warunek session_id > 50 był heurystyką z SQL 2005
    -- i na nowszych wersjach wpuszczał sesje systemowe.
    (SELECT COUNT(*) FROM sys.dm_exec_requests AS r
       JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
      WHERE s.is_user_process = 1)                                                         AS zapytania,
    -- Samoblokada wątków przy wymianie równoległej to nie blokada biznesowa.
    (SELECT COUNT(*) FROM sys.dm_exec_requests
      WHERE blocking_session_id <> 0
        AND blocking_session_id <> session_id)                                             AS blokady,
    (SELECT ISNULL(SUM(runnable_tasks_count),0) FROM sys.dm_os_schedulers
      WHERE status = N'VISIBLE ONLINE')                                                    AS runnable,
    (SELECT COUNT(*) FROM sys.dm_exec_query_memory_grants WHERE grant_time IS NULL)        AS granty,
    (SELECT CAST(SUM(user_object_reserved_page_count + internal_object_reserved_page_count
                     + version_store_reserved_page_count) * 8.0 / 1024 AS DECIMAL(18,1))
       FROM tempdb.sys.dm_db_file_space_usage)                                             AS tempdb_mb,
    -- Wartość w ms (PHP dzieli przez 1000 przy wyświetlaniu, próg też jest w ms).
    -- Bez filtra na background/sleeping kafelek pokazywał zadanie systemowe
    -- działające od startu instancji, czyli zawsze cały uptime.
    -- Sesje zaparkowane na wiecznym oczekiwaniu (Service Broker, WAITFOR, CDC,
    -- replikacja) mają status 'running'/'suspended' i is_user_process = 1,
    -- więc trzeba je wyciąć po wait_type i command. Dodatkowo limit @LIMIT_H
    -- godzin — realne zapytanie ERP nigdy tyle nie trwa.
    (SELECT ISNULL(MAX(r.total_elapsed_time), 0)
       FROM sys.dm_exec_requests AS r
       JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
      WHERE s.is_user_process = 1
        AND r.session_id <> @@SPID
        AND r.status NOT IN ('background', 'sleeping')
        -- SQL Server Agent i konta usługowe trzymają sesje od startu instancji.
        -- To one dawały w tym kafelku wartość równą czasowi pracy serwera.
        AND ISNULL(s.program_name, '') NOT LIKE 'SQLAgent%'
        AND ISNULL(s.login_name, '')   NOT LIKE 'NT SERVICE%'
        AND r.total_elapsed_time < /*LIMIT_MS*/86400000
        AND ISNULL(r.wait_type, '') NOT IN (
              'WAITFOR', 'BROKER_RECEIVE_WAITFOR', 'BROKER_TASK_STOP',
              'BROKER_TRANSMITTER', 'BROKER_EVENTHANDLER',
              'SP_SERVER_DIAGNOSTICS_SLEEP', 'XE_LIVE_TARGET_TVF',
              'XE_TIMER_EVENT', 'FT_IFTS_SCHEDULER_IDLE_WAIT',
              'REDO_THREAD_PENDING_WORK', 'LOGMGR_QUEUE', 'CHECKPOINT_QUEUE',
              'DIRTY_PAGE_POLL', 'LAZYWRITER_SLEEP', 'REQUEST_FOR_DEADLOCK_SEARCH',
              'SQLTRACE_INCREMENTAL_FLUSH_SLEEP', 'HADR_FILESTREAM_IOMGR_IOCOMPLETION')
        AND r.command NOT IN (
              'BRKR TASK', 'TASK MANAGER', 'LOG WRITER', 'LAZY WRITER',
              'CHECKPOINT', 'RESOURCE MONITOR', 'TRACE QUEUE TASK',
              'XE TIMER', 'XE DISPATCHER', 'GHOST CLEANUP', 'SIGNAL HANDLER',
              'DB STARTUP', 'FSAGENT', 'UNKNOWN TOKEN',
              'REPLICATION LOG READER', 'CDC CAPTURE'))                                    AS najdluzsze_ms,
    (SELECT DATEDIFF(HOUR, sqlserver_start_time, GETDATE()) FROM sys.dm_os_sys_info)       AS uptime_h,
    CAST(SERVERPROPERTY('ProductVersion') AS VARCHAR(50))                                  AS wersja,
    CAST(SERVERPROPERTY('Edition') AS VARCHAR(80))                                         AS edycja,
    (SELECT cpu_count FROM sys.dm_os_sys_info)                                             AS rdzenie,
    (SELECT COUNT(DISTINCT parent_node_id) FROM sys.dm_os_schedulers
      WHERE status = N'VISIBLE ONLINE')                                                    AS numa,
    (SELECT CAST(value_in_use AS INT) FROM sys.configurations
      WHERE name = 'max degree of parallelism')                                            AS maxdop,
    (SELECT CAST(value_in_use AS INT) FROM sys.configurations
      WHERE name = 'cost threshold for parallelism')                                       AS ctfp;
SQL;

/**
 * Zapytanie o dominujące oczekiwania. Lista dodatkowo pomijanych typów pochodzi
 * z konfiguracji — wstawiana jest jako literały, więc filtrujemy ją do znaków
 * dozwolonych w nazwach typów oczekiwań (litery, cyfry, podkreślenie).
 */
function qWaity(array $pomijaj = []): string
{
    $czyste = [];
    foreach ($pomijaj as $typ) {
        $typ = strtoupper(trim((string)$typ));
        if ($typ !== '' && preg_match('/^[A-Z0-9_]+$/', $typ)) {
            $czyste[] = "N'" . $typ . "'";
        }
    }
    $dodatkowe = $czyste
        ? "\n      AND wait_type NOT IN (" . implode(', ', $czyste) . ")"
        : '';

    return str_replace('/*POMIJAJ*/', $dodatkowe, Q_WAITY_SZABLON);
}

const Q_WAITY_SZABLON = <<<SQL
WITH W AS (
    SELECT wait_type, wait_time_ms, waiting_tasks_count, signal_wait_time_ms
    FROM sys.dm_os_wait_stats
    WHERE waiting_tasks_count > 0
      AND wait_type NOT LIKE 'SLEEP%' AND wait_type NOT LIKE 'BROKER%'
      AND wait_type NOT LIKE 'XE[_]%' AND wait_type NOT LIKE 'QDS[_]%'
      AND wait_type NOT LIKE 'HADR[_]%' AND wait_type NOT LIKE 'PREEMPTIVE[_]XE%'
      AND wait_type NOT LIKE 'FT[_]%' AND wait_type NOT LIKE 'SQLTRACE[_]%'
      AND wait_type NOT LIKE 'CLR[_]%' AND wait_type NOT LIKE 'SOS[_]WORK%'
      AND wait_type NOT LIKE 'PWAIT[_]%' AND wait_type NOT LIKE 'DBMIRROR%'
      AND wait_type NOT LIKE 'PARALLEL[_]REDO%' AND wait_type NOT LIKE 'SP[_]SERVER%'
      AND wait_type NOT IN (
            N'WAITFOR', N'LAZYWRITER_SLEEP', N'LOGMGR_QUEUE', N'CHECKPOINT_QUEUE',
            N'REQUEST_FOR_DEADLOCK_SEARCH', N'DIRTY_PAGE_POLL',
            N'DISPATCHER_QUEUE_SEMAPHORE', N'ONDEMAND_TASK_QUEUE', N'SERVER_IDLE_CHECK',
            N'RESOURCE_QUEUE', N'MEMORY_ALLOCATION_EXT', N'KSOURCE_WAKEUP',
            N'SOS_WORK_DISPATCHER', N'WAIT_XTP_HOST_WAIT', N'WAIT_XTP_OFFLINE_CKPT_NEW_LOG',
            N'WAIT_XTP_CKPT_CLOSE', N'WAIT_XTP_RECOVERY', N'VDI_CLIENT_OTHER',
            N'WAIT_FOR_RESULTS', N'WAITFOR_TASKSHUTDOWN', N'SNI_HTTP_ACCEPT',
            N'STARTUP_DEPENDENCY_MANAGER', N'BAD_PAGE_PROCESS', N'DBMIRRORING_CMD',
            N'POPULATE_LOCK_ORDINALS', N'QDS_SHUTDOWN_QUEUE', N'EXECSYNC',
            N'PVS_PREALLOCATE', N'PERSISTENT_VERSION_STORE_CLEANUP',
            N'VERSION_CLEANUP_TASK', N'HADR_FILESTREAM_IOMGR_IOCOMPLETION')/*POMIJAJ*/
)
SELECT TOP (12)
    wait_type                                                             AS [Typ oczekiwania],
    CAST(100.0 * wait_time_ms / SUM(wait_time_ms) OVER () AS DECIMAL(5,2)) AS [Udzial %],
    CAST(wait_time_ms / 1000.0 AS DECIMAL(18,1))                          AS [Sekundy],
    waiting_tasks_count                                                   AS [Liczba],
    CAST(wait_time_ms * 1.0 / NULLIF(waiting_tasks_count,0) AS DECIMAL(18,2)) AS [Srednio ms],
    CASE
      WHEN wait_type LIKE 'PAGEIOLATCH%'       THEN 'Odczyt stron z dysku'
      WHEN wait_type LIKE 'WRITELOG%'          THEN 'Zapis logu transakcyjnego'
      WHEN wait_type LIKE 'LOGBUFFER%'         THEN 'Bufor logu'
      WHEN wait_type LIKE 'PAGELATCH%'         THEN 'Kontencja stron w pamieci'
      WHEN wait_type = 'LCK_M_S'               THEN 'Czeka na odczyt – blokuje go pisarz'
      WHEN wait_type = 'LCK_M_U'               THEN 'Czeka na aktualizacje – blokuje go czytelnik'
      WHEN wait_type IN ('LCK_M_X','LCK_M_IX') THEN 'Czeka na zapis – konflikt z innym dostepem'
      WHEN wait_type LIKE 'LCK[_]%'            THEN 'Blokady miedzy transakcjami'
      WHEN wait_type = 'SOS_SCHEDULER_YIELD'   THEN 'Presja CPU'
      WHEN wait_type IN ('CXPACKET','CXCONSUMER') THEN 'Rownoleglosc zapytan'
      WHEN wait_type = 'RESOURCE_SEMAPHORE'    THEN 'Brak pamieci na granty'
      WHEN wait_type LIKE 'THREADPOOL%'        THEN 'Brak wolnych workerow'
      WHEN wait_type LIKE 'ASYNC_NETWORK_IO%'  THEN 'Klient wolno odbiera wyniki'
      WHEN wait_type LIKE 'BACKUPIO%'          THEN 'Operacje backupu'
      WHEN wait_type LIKE 'ASYNC_IO_COMPLETION%' THEN 'Dlugie operacje I/O – backup, autogrowth'
      WHEN wait_type LIKE 'IO_COMPLETION%'     THEN 'I/O poza stronami danych'
      WHEN wait_type = 'PREEMPTIVE_OS_AUTHENTICATIONOPS' THEN 'Uwierzytelnianie Windows / AD'
      WHEN wait_type LIKE 'PREEMPTIVE_OS_%'    THEN 'Wywolania systemowe poza kontrola SQL'
      WHEN wait_type = 'OLEDB'                 THEN 'Serwery polaczone lub odpytywanie DMV'
      WHEN wait_type LIKE 'RESOURCE_SEMAPHORE_QUERY_COMPILE%' THEN 'Kolejka kompilacji planow'
      ELSE '' END                                                         AS [Znaczenie]
FROM W
ORDER BY wait_time_ms DESC;
SQL;

const Q_IO = <<<SQL
SELECT TOP (20)
    DB_NAME(vfs.database_id)                        AS [Baza],
    mf.name                                         AS [Plik],
    CASE mf.type WHEN 0 THEN 'DANE' WHEN 1 THEN 'LOG' ELSE 'INNE' END AS [Typ],
    CAST(vfs.size_on_disk_bytes / 1048576.0 AS DECIMAL(18,0))          AS [Rozmiar MB],
    CAST(vfs.io_stall_read_ms * 1.0 / NULLIF(vfs.num_of_reads,0) AS DECIMAL(18,1))   AS [Odczyt ms],
    CAST(vfs.io_stall_write_ms * 1.0 / NULLIF(vfs.num_of_writes,0) AS DECIMAL(18,1)) AS [Zapis ms],
    vfs.num_of_reads                                AS [Odczyty],
    vfs.num_of_writes                               AS [Zapisy]
FROM sys.dm_io_virtual_file_stats(NULL, NULL) AS vfs
JOIN sys.master_files AS mf
     ON mf.database_id = vfs.database_id AND mf.file_id = vfs.file_id
ORDER BY (vfs.io_stall * 1.0 / NULLIF(vfs.num_of_reads + vfs.num_of_writes, 0)) DESC;
SQL;

const Q_AKTYWNOSC = <<<SQL
SELECT TOP (30)
    r.session_id                        AS [Sesja],
    r.blocking_session_id               AS [Blokuje ja],
    r.status                            AS [Status],
    DB_NAME(r.database_id)              AS [Baza],
    s.login_name                        AS [Login],
    s.host_name                         AS [Host],
    LEFT(ISNULL(s.program_name, ''), 40) AS [Program],
    r.wait_type                         AS [Oczekiwanie],
    r.total_elapsed_time                AS [Trwa ms],
    r.cpu_time                          AS [CPU ms],
    r.logical_reads                     AS [Odczyty],
    r.open_transaction_count            AS [Transakcje],
    LEFT(LTRIM(REPLACE(REPLACE(REPLACE(
        SUBSTRING(qt.text, (r.statement_start_offset / 2) + 1,
            ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
              ELSE r.statement_end_offset END - r.statement_start_offset) / 2) + 1),
        CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' ')), 300)  AS [Instrukcja]
FROM sys.dm_exec_requests AS r
JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE s.is_user_process = 1
ORDER BY r.total_elapsed_time DESC;
SQL;

const Q_TOP_ZAPYTANIA = <<<SQL
SELECT TOP (20)
    DB_NAME(qt.dbid)                                            AS [Baza],
    ISNULL(OBJECT_NAME(qt.objectid, qt.dbid), '(adhoc)')        AS [Obiekt],
    qs.execution_count                                          AS [Wykonania],
    qs.total_worker_time / 1000                                 AS [CPU razem ms],
    qs.total_worker_time / NULLIF(qs.execution_count,0) / 1000  AS [CPU srednio ms],
    qs.total_elapsed_time / NULLIF(qs.execution_count,0) / 1000 AS [Czas srednio ms],
    qs.total_logical_reads / NULLIF(qs.execution_count,0)       AS [Odczyty srednio],
    CONVERT(VARCHAR(19), qs.last_execution_time, 120)           AS [Ostatnio],
    LEFT(LTRIM(REPLACE(REPLACE(REPLACE(
        SUBSTRING(qt.text, (qs.statement_start_offset / 2) + 1,
            ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
              ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1),
        CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' ')), 300)    AS [Zapytanie]
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) AS qt
ORDER BY qs.total_worker_time DESC;
SQL;

const Q_BAZY = <<<SQL
SELECT
    d.name                                                            AS [Baza],
    d.state_desc                                                      AS [Stan],
    d.recovery_model_desc                                             AS [Model odzysku],
    CAST(SUM(CASE WHEN mf.type = 0 THEN mf.size END) * 8.0 / 1024 AS DECIMAL(18,0)) AS [Dane MB],
    CAST(SUM(CASE WHEN mf.type = 1 THEN mf.size END) * 8.0 / 1024 AS DECIMAL(18,0)) AS [Log MB],
    ISNULL(NULLIF(d.log_reuse_wait_desc, 'NOTHING'), '-')             AS [Blokada logu],
    CASE WHEN d.is_query_store_on = 1 THEN 'tak' ELSE 'nie' END       AS [Query Store]
FROM sys.databases AS d
JOIN sys.master_files AS mf ON mf.database_id = d.database_id
GROUP BY d.name, d.state_desc, d.recovery_model_desc, d.log_reuse_wait_desc, d.is_query_store_on
ORDER BY [Dane MB] DESC;
SQL;

/*  Łańcuchy blokad wraz z pełnym opisem sesji blokującej.

    Sedno: sesja blokująca bardzo często NIE MA aktywnego żądania — jest
    "sleeping" z otwartą, niezatwierdzoną transakcją. Nie ma jej więc w
    sys.dm_exec_requests i nie widać jej w zakładce Aktywność. Zostawał sam
    numer sesji, a zanim ktokolwiek zdążył ją sprawdzić w SSMS, rozłączała się.

    Dlatego treść jej ostatniego polecenia bierzemy z
    sys.dm_exec_connections.most_recent_sql_handle, które przeżywa zakończenie
    żądania, a nie z sql_handle bieżącego żądania.                              */
const Q_BLOKADY = <<<SQL
SELECT
    r.session_id                          AS [Sesja czekajaca],
    r.wait_time                           AS [Czeka ms],
    r.wait_type                           AS [Typ],
    r.wait_resource                       AS [Zasob],
    DB_NAME(r.database_id)                AS [Baza],
    s.login_name                          AS [Czeka login],

    r.blocking_session_id                 AS [Sesja blokujaca],
    ISNULL(b.status, 'rozlaczona')        AS [Blokujacy stan],
    b.login_name                          AS [Blokujacy login],
    b.host_name                           AS [Blokujacy host],
    LEFT(ISNULL(b.program_name, ''), 40)  AS [Blokujacy program],
    b.open_transaction_count              AS [Blokujacy otwarte transakcje],
    CASE WHEN b.session_id IS NULL THEN NULL
         ELSE DATEDIFF(SECOND, b.last_request_end_time, GETDATE()) END
                                          AS [Blokujacy bezczynny s],
    tr.transakcja_s                       AS [Blokujacy transakcja s],
    tr.log_kb                             AS [Blokujacy log KB],
    bc.client_net_address                 AS [Blokujacy adres],
    LEFT(LTRIM(REPLACE(REPLACE(bq.text, CHAR(13), ' '), CHAR(10), ' ')), 300)
                                          AS [Blokujacy ostatnie zapytanie],

    LEFT(LTRIM(REPLACE(REPLACE(qt.text, CHAR(13), ' '), CHAR(10), ' ')), 300)
                                          AS [Zapytanie czekajacego]
FROM sys.dm_exec_requests AS r
JOIN sys.dm_exec_sessions AS s
     ON s.session_id = r.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt

-- Sesja blokująca: LEFT JOIN, bo mogła się już rozłączyć.
LEFT JOIN sys.dm_exec_sessions AS b
     ON b.session_id = r.blocking_session_id
-- TOP (1), bo przy MARS jedna sesja ma kilka połączeń i zwykły JOIN
-- zwielokrotniłby wiersze blokad.
OUTER APPLY (
    SELECT TOP (1) c2.client_net_address, c2.most_recent_sql_handle
    FROM sys.dm_exec_connections AS c2
    WHERE c2.session_id = r.blocking_session_id
    ORDER BY c2.connect_time DESC
) AS bc
OUTER APPLY sys.dm_exec_sql_text(bc.most_recent_sql_handle) AS bq

-- Najstarsza otwarta transakcja blokującego i ile zdążyła zapisać do logu.
-- Duży log oznacza, że KILL wywoła długi rollback, w trakcie którego blokada
-- NADAL jest trzymana.
OUTER APPLY (
    SELECT TOP (1)
        DATEDIFF(SECOND, dt.database_transaction_begin_time, GETDATE()) AS transakcja_s,
        dt.database_transaction_log_bytes_used / 1024                   AS log_kb
    FROM sys.dm_tran_session_transactions AS st
    JOIN sys.dm_tran_database_transactions AS dt
         ON dt.transaction_id = st.transaction_id
    WHERE st.session_id = r.blocking_session_id
    ORDER BY dt.database_transaction_begin_time
) AS tr

WHERE r.blocking_session_id <> 0
  AND r.blocking_session_id <> r.session_id   -- samoblokada wątków równoległych
ORDER BY r.wait_time DESC;
SQL;

/*------------------------------------------------------- AKTYWNOŚĆ UŻYTKOWNIKÓW */

const Q_UZ_PODSUMOWANIE = <<<SQL
SELECT
    s.login_name                                                     AS [Login],
    COUNT(*)                                                         AS [Sesje],
    SUM(CASE WHEN r.session_id IS NOT NULL THEN 1 ELSE 0 END)        AS [Pracuje teraz],
    SUM(CASE WHEN s.status = 'sleeping' THEN 1 ELSE 0 END)           AS [Uspione],
    SUM(s.open_transaction_count)                                    AS [Otwarte transakcje],
    SUM(s.cpu_time)                                                  AS [CPU ms],
    SUM(s.logical_reads)                                             AS [Odczyty logiczne],
    SUM(s.writes)                                                    AS [Zapisy],
    SUM(s.memory_usage) * 8                                          AS [Pamiec KB],
    COUNT(DISTINCT s.host_name)                                      AS [Stacje],
    COUNT(DISTINCT s.program_name)                                   AS [Aplikacje],
    ISNULL(MAX(r.total_elapsed_time), 0)                             AS [Najdluzsze zadanie ms],
    CONVERT(VARCHAR(19), MIN(s.login_time), 120)                     AS [Najstarsza sesja],
    CONVERT(VARCHAR(19), MAX(s.last_request_end_time), 120)          AS [Ostatnia aktywnosc]
FROM sys.dm_exec_sessions AS s
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = s.session_id
WHERE s.is_user_process = 1
GROUP BY s.login_name
ORDER BY SUM(s.cpu_time) DESC;
SQL;

const Q_UZ_STACJE = <<<SQL
SELECT
    s.login_name                                                     AS [Login],
    ISNULL(s.host_name, '(brak)')                                    AS [Stacja],
    LEFT(ISNULL(s.program_name, '(brak)'), 60)                       AS [Aplikacja],
    ISNULL(DB_NAME(s.database_id), '-')                              AS [Baza],
    COUNT(*)                                                         AS [Sesje],
    SUM(CASE WHEN r.session_id IS NOT NULL THEN 1 ELSE 0 END)        AS [Pracuje teraz],
    SUM(s.cpu_time)                                                  AS [CPU ms],
    SUM(s.logical_reads)                                             AS [Odczyty logiczne],
    SUM(s.open_transaction_count)                                    AS [Otwarte transakcje],
    CONVERT(VARCHAR(19), MAX(s.last_request_end_time), 120)          AS [Ostatnia aktywnosc]
FROM sys.dm_exec_sessions AS s
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = s.session_id
WHERE s.is_user_process = 1
GROUP BY s.login_name, s.host_name, s.program_name, s.database_id
ORDER BY SUM(s.cpu_time) DESC;
SQL;

const Q_UZ_TERAZ = <<<SQL
SELECT TOP (40)
    s.login_name                        AS [Login],
    ISNULL(s.host_name, '-')            AS [Stacja],
    r.session_id                        AS [Sesja],
    r.blocking_session_id               AS [Blokuje ja],
    r.status                            AS [Status],
    DB_NAME(r.database_id)              AS [Baza],
    r.command                           AS [Komenda],
    r.wait_type                         AS [Oczekiwanie],
    r.total_elapsed_time                AS [Trwa ms],
    r.cpu_time                          AS [CPU ms],
    r.logical_reads                     AS [Odczyty],
    r.open_transaction_count            AS [Transakcje],
    LEFT(ISNULL(s.program_name, ''), 40) AS [Program],
    LEFT(LTRIM(REPLACE(REPLACE(REPLACE(
        SUBSTRING(qt.text, (r.statement_start_offset / 2) + 1,
            ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
              ELSE r.statement_end_offset END - r.statement_start_offset) / 2) + 1),
        CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' ')), 300)  AS [Instrukcja]
FROM sys.dm_exec_requests AS r
JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE s.is_user_process = 1
ORDER BY r.total_elapsed_time DESC;
SQL;

const Q_UZ_TRANSAKCJE = <<<SQL
SELECT
    s.login_name                                                   AS [Login],
    ISNULL(s.host_name, '-')                                       AS [Stacja],
    s.session_id                                                   AS [Sesja],
    s.status                                                       AS [Status sesji],
    ISNULL(bt.baza_transakcji, ISNULL(DB_NAME(s.database_id), '-')) AS [Baza],
    DATEDIFF(SECOND, tat.transaction_begin_time, GETDATE())        AS [Transakcja trwa s],
    CONVERT(VARCHAR(19), tat.transaction_begin_time, 120)          AS [Otwarta od],
    CAST(ISNULL(bt.log_uzyty, 0) / 1024.0 AS DECIMAL(18,1))        AS [Log KB],
    CASE tat.transaction_state
        WHEN 2 THEN 'aktywna' WHEN 3 THEN 'zakonczona (odczyt)'
        WHEN 4 THEN 'commit 2PC' WHEN 5 THEN 'przygotowana'
        WHEN 6 THEN 'zatwierdzona' WHEN 7 THEN 'wycofywana'
        WHEN 8 THEN 'wycofana' ELSE 'inna' END                     AS [Stan],
    LEFT(ISNULL(s.program_name, ''), 40)                           AS [Program],
    LEFT(LTRIM(REPLACE(REPLACE(qt.text, CHAR(13), ' '), CHAR(10), ' ')), 300) AS [Ostatnie zapytanie]
FROM sys.dm_tran_active_transactions AS tat
JOIN sys.dm_tran_session_transactions AS tst ON tst.transaction_id = tat.transaction_id
JOIN sys.dm_exec_sessions AS s ON s.session_id = tst.session_id
LEFT JOIN sys.dm_exec_connections AS c ON c.session_id = s.session_id
OUTER APPLY sys.dm_exec_sql_text(c.most_recent_sql_handle) AS qt
OUTER APPLY (
    SELECT TOP (1)
        DB_NAME(dt.database_id)                  AS baza_transakcji,
        dt.database_transaction_log_bytes_used   AS log_uzyty
    FROM sys.dm_tran_database_transactions AS dt
    WHERE dt.transaction_id = tat.transaction_id
      AND dt.database_id <> 2                    -- pomijamy tempdb
    ORDER BY dt.database_transaction_log_bytes_used DESC
) AS bt
WHERE s.is_user_process = 1
ORDER BY tat.transaction_begin_time;
SQL;

const Q_UZ_TEMPDB = <<<SQL
SELECT
    s.login_name                                                              AS [Login],
    ISNULL(s.host_name, '-')                                                  AS [Stacja],
    COUNT(*)                                                                  AS [Sesje],
    CAST(SUM(su.user_objects_alloc_page_count - su.user_objects_dealloc_page_count)
         * 8.0 / 1024 AS DECIMAL(18,1))                                       AS [Obiekty uzytkownika MB],
    CAST(SUM(su.internal_objects_alloc_page_count - su.internal_objects_dealloc_page_count)
         * 8.0 / 1024 AS DECIMAL(18,1))                                       AS [Obiekty wewnetrzne MB],
    LEFT(ISNULL(MAX(s.program_name), ''), 40)                                 AS [Program]
FROM tempdb.sys.dm_db_session_space_usage AS su
JOIN sys.dm_exec_sessions AS s ON s.session_id = su.session_id
WHERE s.is_user_process = 1
GROUP BY s.login_name, s.host_name
HAVING SUM(su.user_objects_alloc_page_count + su.internal_objects_alloc_page_count) > 0
ORDER BY SUM(su.internal_objects_alloc_page_count + su.user_objects_alloc_page_count) DESC;
SQL;

// Połączenia z pominięciem serwera aplikacyjnego – SSMS, Excel, skrypty, integracje.
// Adres serwera IIS podajesz w konfiguracji; jego sesje są tu pomijane.
const Q_UZ_OBCE = <<<SQL
SELECT
    ISNULL(c.client_net_address, '-')                              AS [Adres klienta],
    ISNULL(s.host_name, '(brak)')                                  AS [Stacja],
    s.login_name                                                   AS [Login],
    LEFT(ISNULL(s.program_name, '(brak)'), 60)                     AS [Aplikacja],
    -- UWAGA: client_interface_name jest kolumną sys.dm_exec_sessions,
    -- a NIE sys.dm_exec_connections. Do 10.1.0 stał tu alias "c", przez co
    -- cała sekcja kończyła się błędem 207 (Invalid column name).
    LEFT(ISNULL(s.client_interface_name, '-'), 30)                 AS [Sterownik],
    COUNT(*)                                                       AS [Sesje],
    SUM(CASE WHEN r.session_id IS NOT NULL THEN 1 ELSE 0 END)      AS [Pracuje teraz],
    SUM(s.cpu_time)                                                AS [CPU ms],
    SUM(s.logical_reads)                                           AS [Odczyty],
    SUM(s.open_transaction_count)                                  AS [Otwarte transakcje],
    CONVERT(VARCHAR(19), MIN(s.login_time), 120)                   AS [Najstarsza sesja],
    CONVERT(VARCHAR(19), MAX(s.last_request_end_time), 120)        AS [Ostatnia aktywnosc]
FROM sys.dm_exec_sessions AS s
LEFT JOIN sys.dm_exec_connections AS c ON c.session_id = s.session_id
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = s.session_id
WHERE s.is_user_process = 1
  AND (? = '' OR ISNULL(c.client_net_address, '') <> ?)
GROUP BY c.client_net_address, s.host_name, s.login_name,
         s.program_name, s.client_interface_name
ORDER BY SUM(s.cpu_time) DESC;
SQL;

const Q_UZ_KONTEKST = <<<SQL
SELECT
    CONVERT(VARCHAR(66), s.context_info, 1)  AS [Token aplikacji],
    s.login_name                        AS [Login],
    s.session_id                        AS [Sesja],
    ISNULL(s.host_name, '-')            AS [Stacja],
    s.status                            AS [Status],
    ISNULL(DB_NAME(s.database_id), '-') AS [Baza],
    s.cpu_time                          AS [CPU ms],
    s.logical_reads                     AS [Odczyty],
    s.open_transaction_count            AS [Transakcje],
    CASE WHEN r.session_id IS NOT NULL THEN r.total_elapsed_time ELSE NULL END AS [Trwa ms],
    LEFT(ISNULL(s.program_name, ''), 40) AS [Aplikacja],
    LEFT(LTRIM(REPLACE(REPLACE(ISNULL(qt.text, ''), CHAR(13), ' '), CHAR(10), ' ')), 300) AS [Zapytanie]
FROM sys.dm_exec_sessions AS s
LEFT JOIN sys.dm_exec_connections AS c ON c.session_id = s.session_id
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = s.session_id
OUTER APPLY sys.dm_exec_sql_text(ISNULL(r.sql_handle, c.most_recent_sql_handle)) AS qt
WHERE s.is_user_process = 1
  AND s.context_info IS NOT NULL
  AND s.context_info <> 0x
ORDER BY s.login_name, s.session_id;
SQL;

/*--------------------------------------------- ZAPYTANIA Z ATRYBUCJĄ LOGINU */

// Lista loginów do filtra – z sesji bieżących oraz z kolektora XE, jeśli działa.
const Q_ZAP_LOGINY = <<<SQL
SELECT DISTINCT s.login_name AS login
FROM sys.dm_exec_sessions AS s
WHERE s.is_user_process = 1 AND s.login_name IS NOT NULL
ORDER BY s.login_name;
SQL;

// Zapytania wykonywane w tej chwili – jedyne miejsce z pewną atrybucją loginu.
const Q_ZAP_TERAZ = <<<SQL
SELECT TOP (40)
    s.login_name                        AS [Login],
    CONVERT(VARCHAR(66), s.context_info, 1) AS [Token aplikacji],
    ISNULL(s.host_name, '-')            AS [Stacja],
    r.session_id                        AS [Sesja],
    DB_NAME(r.database_id)              AS [Baza],
    r.status                            AS [Status],
    r.wait_type                         AS [Oczekiwanie],
    r.total_elapsed_time                AS [Trwa ms],
    r.cpu_time                          AS [CPU ms],
    r.logical_reads                     AS [Odczyty],
    r.granted_query_memory * 8 / 1024   AS [Pamiec MB],
    LEFT(ISNULL(s.program_name, ''), 40) AS [Aplikacja],
    LEFT(LTRIM(REPLACE(REPLACE(REPLACE(
        SUBSTRING(qt.text, (r.statement_start_offset / 2) + 1,
            ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
              ELSE r.statement_end_offset END - r.statement_start_offset) / 2) + 1),
        CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' ')), 400)  AS [Zapytanie]
FROM sys.dm_exec_requests AS r
JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE s.is_user_process = 1
  AND (? = '' OR s.login_name = ?)
ORDER BY r.total_elapsed_time DESC;
SQL;

// Ostatnie zapytanie każdej sesji – także tych uśpionych.
// Pokazuje, co dany użytkownik puścił jako ostatnie, nawet gdy już nie pracuje.
const Q_ZAP_OSTATNIE = <<<SQL
SELECT TOP (60)
    s.login_name                        AS [Login],
    ISNULL(s.host_name, '-')            AS [Stacja],
    s.session_id                        AS [Sesja],
    s.status                            AS [Status sesji],
    ISNULL(DB_NAME(s.database_id), '-') AS [Baza],
    s.cpu_time                          AS [CPU sesji ms],
    s.logical_reads                     AS [Odczyty sesji],
    s.open_transaction_count            AS [Transakcje],
    CONVERT(VARCHAR(19), s.last_request_start_time, 120) AS [Start ostatniego],
    CONVERT(VARCHAR(19), s.last_request_end_time, 120)   AS [Koniec ostatniego],
    DATEDIFF(SECOND, s.last_request_end_time, GETDATE()) AS [Bezczynna s],
    LEFT(ISNULL(s.program_name, ''), 40) AS [Aplikacja],
    LEFT(LTRIM(REPLACE(REPLACE(REPLACE(ISNULL(qt.text, ''),
        CHAR(13), ' '), CHAR(10), ' '), CHAR(9), ' ')), 400) AS [Ostatnie zapytanie]
FROM sys.dm_exec_sessions AS s
LEFT JOIN sys.dm_exec_connections AS c ON c.session_id = s.session_id
OUTER APPLY sys.dm_exec_sql_text(c.most_recent_sql_handle) AS qt
WHERE s.is_user_process = 1
  AND (? = '' OR s.login_name = ?)
ORDER BY s.last_request_end_time DESC;
SQL;

// Sesja kolektora (skrypt 09): stan, przesunięcie strefy i surowy bufor w jednym odczycie.
// XML celowo NIE jest parsowany po stronie SQL Server — .nodes()/.value() na buforze
// z kilkoma tysiącami zdarzeń potrafi trwać dłużej niż limit zapytania (HYT00).
// Pobranie target_data jako tekstu jest natychmiastowe, parsowanie robi PHP.
const Q_ZAP_XE_BUFOR = <<<SQL
SELECT
    (SELECT COUNT(*) FROM sys.dm_xe_sessions WHERE name = N'sqlmon_zapytania') AS dziala,
    DATEDIFF(MINUTE, GETUTCDATE(), GETDATE())                                  AS przesuniecie_min,
    (SELECT TOP (1) st.target_data
     FROM sys.dm_xe_sessions AS s
     JOIN sys.dm_xe_session_targets AS st ON st.event_session_address = s.address
     WHERE s.name = N'sqlmon_zapytania' AND st.target_name = N'ring_buffer')  AS dane;
SQL;

/**
 * Rozbiór bufora ring_buffer sesji sqlmon_zapytania.
 * Zwraca listę zdarzeń posortowaną od najnowszego.
 */
function zdarzeniaXe(string $dane, int $przesuniecieMin): array
{
    if ($dane === '') return [];
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('Brak rozszerzenia PHP SimpleXML (włącz je w php.ini).');
    }
    $xml = simplexml_load_string($dane, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NONET);
    if ($xml === false) {
        throw new RuntimeException('Nie udało się odczytać XML z bufora sesji Extended Events.');
    }

    $zdarzenia = [];
    foreach ($xml->event as $ev) {
        $akcje = []; $dane_ = [];
        foreach ($ev->action as $a) { $akcje[(string)$a['name']] = (string)$a->value; }
        foreach ($ev->data as $d)   { $dane_[(string)$d['name']]  = (string)$d->value; }

        $czasUtc = (string)$ev['timestamp'];
        try {
            $czas = (new DateTime($czasUtc))->modify(($przesuniecieMin >= 0 ? '+' : '') . $przesuniecieMin . ' minutes');
            $czasTxt = $czas->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $czasTxt = $czasUtc;
        }

        $tekst = $dane_['statement'] ?? '';
        if ($tekst === '') $tekst = $dane_['batch_text'] ?? '';
        $tekst = trim((string)preg_replace('/\s+/u', ' ', $tekst));

        $zdarzenia[] = [
            'klucz'     => $czasUtc,
            'login'     => $akcje['server_principal_name'] ?? '',
            'stacja'    => $akcje['client_hostname'] ?? '',
            'aplikacja' => $akcje['client_app_name'] ?? '',
            'baza'      => $akcje['database_name'] ?? '',
            'czas'      => $czasTxt,
            'czas_us'   => (int)($dane_['duration'] ?? 0),
            'cpu_us'    => (int)($dane_['cpu_time'] ?? 0),
            'odczyty'   => (int)($dane_['logical_reads'] ?? 0),
            'wiersze'   => (int)($dane_['row_count'] ?? 0),
            'tekst'     => $tekst,
        ];
    }
    usort($zdarzenia, fn($x, $y) => strcmp($y['klucz'], $x['klucz']));
    return $zdarzenia;
}

function historiaXeWiersze(array $zdarzenia, string $login): array
{
    $wynik = [];
    foreach ($zdarzenia as $z) {
        if ($login !== '' && $z['login'] !== $login) continue;
        $wynik[] = [
            'Login'     => $z['login'] !== '' ? $z['login'] : '-',
            'Stacja'    => $z['stacja'] !== '' ? $z['stacja'] : '-',
            'Czas'      => $z['czas'],
            'Baza'      => $z['baza'] !== '' ? $z['baza'] : '-',
            'Czas ms'   => round($z['czas_us'] / 1000, 1),
            'CPU ms'    => round($z['cpu_us'] / 1000, 1),
            'Odczyty'   => $z['odczyty'],
            'Wiersze'   => $z['wiersze'],
            'Aplikacja' => mb_substr($z['aplikacja'] !== '' ? $z['aplikacja'] : '-', 0, 40),
            'Zapytanie' => mb_substr($z['tekst'], 0, 400),
        ];
        if (count($wynik) >= 100) break;
    }
    return $wynik;
}

function historiaXeSuma(array $zdarzenia): array
{
    $g = [];
    foreach ($zdarzenia as $z) {
        $k = $z['login'] !== '' ? $z['login'] : '-';
        $g[$k] ??= ['n' => 0, 'cpu' => 0, 'czas' => 0, 'max' => 0, 'odczyty' => 0];
        $g[$k]['n']++;
        $g[$k]['cpu']     += $z['cpu_us'];
        $g[$k]['czas']    += $z['czas_us'];
        $g[$k]['max']      = max($g[$k]['max'], $z['czas_us']);
        $g[$k]['odczyty'] += $z['odczyty'];
    }
    uasort($g, fn($x, $y) => $y['cpu'] <=> $x['cpu']);
    $wynik = [];
    foreach ($g as $k => $v) {
        $wynik[] = [
            'Login'          => $k,
            'Zapytania'      => $v['n'],
            'CPU razem ms'   => round($v['cpu'] / 1000, 1),
            'CPU srednio ms' => round($v['cpu'] / $v['n'] / 1000, 1),
            'Czas razem ms'  => round($v['czas'] / 1000, 1),
            'Czas max ms'    => round($v['max'] / 1000, 1),
            'Odczyty razem'  => $v['odczyty'],
        ];
    }
    return $wynik;
}

/*------------------------------------------------------- HISTORIA (kolektor) */

function historiaSeria(PDO $pdo, string $baza, int $godzin): array
{
    $sql = "SELECT CONVERT(VARCHAR(5), czas, 108) AS czas,
                   cpu_sql_proc AS cpu_sql, cpu_inne_proc AS cpu_inne,
                   ple_sek AS ple, sesje_uzytkownikow AS sesje,
                   zablokowane_sesje AS blokady, runnable_tasks AS runnable
            FROM [$baza].dbo.Snapshot_Serwer
            WHERE czas > DATEADD(HOUR, -$godzin, SYSDATETIME())
            ORDER BY czas;";
    return pobierz($pdo, $sql);
}

function historiaGodzinowa(PDO $pdo, string $baza): array
{
    $sql = "SELECT TOP (48)
                CONVERT(VARCHAR(10), data, 120) AS [Data],
                RIGHT('0' + CAST(godzina AS VARCHAR(2)), 2) + ':00' AS [Godzina],
                cpu_sql_srednio AS [CPU sr %], cpu_sql_max AS [CPU max %],
                ple_min AS [PLE min], sesje_srednio AS [Sesje sr],
                zapytania_max AS [Zapytania max], blokady_max AS [Blokady max],
                runnable_max AS [Runnable max],
                CAST(tempdb_srednio_mb AS DECIMAL(18,0)) AS [tempdb MB],
                najdluzsze_zapytanie_sek AS [Najdl. zapytanie s]
            FROM [$baza].dbo.vw_ObciazenieGodzinowe
            ORDER BY [Data] DESC, godzina DESC;";
    return pobierz($pdo, $sql);
}

/*----------------------------------------------------------------- LOGOWANIE */

$wymagaHasla = $KONFIG['haslo_dostepu'] !== '';
$zalogowany  = !$wymagaHasla || !empty($_SESSION['sqlmon_ok']);

if ($wymagaHasla && isset($_POST['haslo'])) {
    // is_string() jest konieczne: przy wysłaniu haslo[]=x parametr jest tablicą,
    // a hash_equals() rzuca wtedy TypeError i strona zwraca błąd 500.
    if (is_string($_POST['haslo']) && hash_equals($KONFIG['haslo_dostepu'], $_POST['haslo'])) {
        session_regenerate_id(true);   // ochrona przed utrwaleniem identyfikatora sesji
        $_SESSION['sqlmon_ok'] = true;
        $zalogowany = true;
    } else {
        $bladLogowania = 'Nieprawidłowe hasło.';
    }
}

/*------------------------------------------------------------------- ROUTING */

if (($_GET['akcja'] ?? '') === 'dane') {

    header('Content-Type: application/json; charset=utf-8');

    if (!$zalogowany) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'blad' => 'Sesja wygasła. Odśwież stronę i zaloguj się ponownie.']);
        exit;
    }
    if (!sterownikDostepny()) {
        echo json_encode(['ok' => false, 'blad' => 'Brak sterownika pdo_sqlsrv.']);
        exit;
    }

    // Rzutowanie na tekst jest konieczne: przy ?serwer[]=x parametr jest tablicą,
    // a użycie jej jako klucza tablicy rzuca TypeError.
    $nazwaSerwera = is_string($_GET['serwer'] ?? null)
        ? $_GET['serwer'] : (string)(array_key_first($KONFIG['serwery']) ?? '');
    $widok        = is_string($_GET['widok'] ?? null) ? $_GET['widok'] : 'przeglad';

    if (!$KONFIG['serwery']) {
        echo json_encode(['ok' => false, 'blad' =>
            'Nie skonfigurowano żadnej instancji. Otwórz konfiguracja.php i dodaj serwer.'],
            JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($KONFIG['serwery'][$nazwaSerwera])) {
        echo json_encode(['ok' => false, 'blad' => 'Nieznana instancja: ' . $nazwaSerwera]);
        exit;
    }

    try {
        $pdo  = polacz($KONFIG['serwery'][$nazwaSerwera]);
        $odp  = ['ok' => true, 'czas' => date('H:i:s'), 'serwer' => $nazwaSerwera, 'sekcje' => []];
        $prog = $KONFIG['progi'];

        if ($widok === 'przeglad') {

            $k = pobierz($pdo, qKafelki($KONFIG['limit_dlugiego_zapytania_h'] ?? 24))[0] ?? [];
            $seria = array_reverse(pobierz($pdo, Q_CPU_RING));
            $cpuTeraz = $seria ? (int)end($seria)['cpu_sql'] : null;

            $odp['seria'] = $seria;
            $odp['naglowek'] = [
                'wersja'  => $k['wersja'] ?? '?',
                'edycja'  => $k['edycja'] ?? '?',
                'rdzenie' => $k['rdzenie'] ?? '?',
                'uptime'  => $k['uptime_h'] ?? 0,
                'numa'    => $k['numa'] ?? null,
                'maxdop'  => $k['maxdop'] ?? null,
                'ctfp'    => $k['ctfp'] ?? null,
            ];
            $odp['kafelki'] = [
                ['etykieta' => 'CPU procesu SQL', 'wartosc' => $cpuTeraz, 'jednostka' => '%',
                 'stan' => stan($cpuTeraz, $prog['cpu_uwaga'], $prog['cpu_alarm'])],
                ['etykieta' => 'Page life expectancy', 'wartosc' => $k['ple'] ?? null, 'jednostka' => 's',
                 'stan' => stan($k['ple'] ?? null, $prog['ple_uwaga'], $prog['ple_alarm'], false)],
                ['etykieta' => 'Sesje użytkowników', 'wartosc' => $k['sesje'] ?? null, 'jednostka' => '',
                 'stan' => 'neutralny'],
                ['etykieta' => 'Aktywne zapytania', 'wartosc' => $k['zapytania'] ?? null, 'jednostka' => '',
                 'stan' => 'neutralny'],
                ['etykieta' => 'Zablokowane sesje', 'wartosc' => $k['blokady'] ?? null, 'jednostka' => '',
                 'stan' => stan($k['blokady'] ?? null, $prog['blokady_uwaga'], $prog['blokady_alarm'])],
                ['etykieta' => 'Zadania w kolejce CPU', 'wartosc' => $k['runnable'] ?? null, 'jednostka' => '',
                 'stan' => stan($k['runnable'] ?? null, 1, 4)],
                ['etykieta' => 'Granty pamięci w kolejce', 'wartosc' => $k['granty'] ?? null, 'jednostka' => '',
                 'stan' => stan($k['granty'] ?? null, 1, 3)],
                ['etykieta' => 'Pamięć SQL', 'wartosc' => $k['pamiec_mb'] ?? null, 'jednostka' => 'MB',
                 'stan' => 'neutralny'],
                ['etykieta' => 'Wolny RAM systemu', 'wartosc' => $k['wolne_mb'] ?? null, 'jednostka' => 'MB',
                 'stan' => stan($k['wolne_mb'] ?? null, 1024, 300, false)],
                ['etykieta' => 'tempdb w użyciu', 'wartosc' => $k['tempdb_mb'] ?? null, 'jednostka' => 'MB',
                 'stan' => 'neutralny'],
                ['etykieta' => 'Najdłuższe zapytanie', 'wartosc' => isset($k['najdluzsze_ms']) ? round($k['najdluzsze_ms'] / 1000, 1) : null,
                 'jednostka' => 's', 'stan' => stan($k['najdluzsze_ms'] ?? null, $prog['dlugie_zapytanie'], $prog['dlugie_zapytanie'] * 4)],
                ['etykieta' => 'Czas pracy instancji', 'wartosc' => $k['uptime_h'] ?? null, 'jednostka' => 'h',
                 'stan' => 'neutralny'],
            ];

            $odp['sekcje'][] = sekcja($pdo, 'Dominujące oczekiwania',
                'Narastająco od startu instancji, z pominięciem oczekiwań bezczynnych. Do diagnozy "tu i teraz" użyj pomiaru różnicowego ze skryptu 04.',
                qWaity($KONFIG['pomijaj_oczekiwania'] ?? []));
            $odp['sekcje'][] = sekcja($pdo, 'Blokady w tej chwili',
                'Pusta tabela to dobra wiadomość.', Q_BLOKADY);

        } elseif ($widok === 'aktywnosc') {
            $odp['sekcje'][] = sekcja($pdo, 'Aktywne żądania',
                'Posortowane od najdłużej działających.', Q_AKTYWNOSC);
            $odp['sekcje'][] = sekcja($pdo, 'Łańcuchy blokad',
                'Sesja blokująca to ta, którą trzeba zdiagnozować. Stan "sleeping" przy otwartej '
                . 'transakcji oznacza, że aplikacja nie zatwierdziła zmian — wtedy kolumna '
                . '"Blokujacy ostatnie zapytanie" pokazuje, co ta sesja zrobiła przed zaśnięciem.',
                Q_BLOKADY);

        } elseif ($widok === 'io') {
            $odp['sekcje'][] = sekcja($pdo, 'Opóźnienia plików baz danych',
                'Średnia od startu instancji. Dane poniżej 10 ms, log poniżej 5 ms.', Q_IO);
            $odp['sekcje'][] = sekcja($pdo, 'Bazy danych',
                'Rozmiary plików i model odzyskiwania.', Q_BAZY);

        } elseif ($widok === 'zapytania') {

            $login = trim((string)($_GET['login'] ?? ''));

            // Lista loginów do rozwijanego filtra.
            try {
                $odp['loginy'] = array_column(pobierz($pdo, Q_ZAP_LOGINY), 'login');
            } catch (Throwable $e) {
                $odp['loginy'] = [];
            }
            $odp['login'] = $login;

            $odp['sekcje'][] = sekcja($pdo, 'Zapytania wykonywane teraz',
                'Jedyne miejsce z pewną atrybucją użytkownika — sesja właśnie pracuje, więc wiadomo czyja.',
                Q_ZAP_TERAZ, [$login, $login]);

            $odp['sekcje'][] = sekcja($pdo, 'Ostatnie zapytanie każdej sesji',
                'Obejmuje też sesje uśpione. Pokazuje, co użytkownik puścił jako ostatnie, zanim przeszedł w bezczynność.',
                Q_ZAP_OSTATNIE, [$login, $login]);

            // Historia z Extended Events. Jeden odczyt surowego bufora, rozbiór w PHP.
            // Rozróżniamy: sesja nie działa / działa, ale bufor pusty / błąd odczytu.
            try {
                $xeOdczyt = pobierz($pdo, Q_ZAP_XE_BUFOR)[0] ?? [];
                if ((int)($xeOdczyt['dziala'] ?? 0) === 0) {
                    $odp['xe_stan'] = 'brak';
                } else {
                    $zdarzenia = zdarzeniaXe((string)($xeOdczyt['dane'] ?? ''),
                        (int)($xeOdczyt['przesuniecie_min'] ?? 0));
                    $historia = historiaXeWiersze($zdarzenia, $login);
                    if (!$historia) {
                        $odp['xe_stan'] = 'pusta';
                    } else {
                        $odp['xe_stan'] = 'dziala';
                        $suma = historiaXeSuma($zdarzenia);
                        $odp['sekcje'][] = [
                            'tytul'   => 'Podsumowanie historii wg loginu',
                            'opis'    => 'Agregat z bufora Extended Events — kto sumarycznie zjadł najwięcej CPU.',
                            'kolumny' => array_keys($suma[0]),
                            'wiersze' => $suma,
                            'blad'    => null,
                        ];
                        $odp['sekcje'][] = [
                            'tytul'   => 'Historia zapytań wg użytkownika',
                            'opis'    => 'Z sesji Extended Events "sqlmon_zapytania". Rejestruje zapytania powyżej progu czasu. '
                                       . 'W buforze: ' . count($zdarzenia) . ' zdarzeń, pokazane najnowsze 100.',
                            'kolumny' => array_keys($historia[0]),
                            'wiersze' => $historia,
                            'blad'    => null,
                        ];
                    }
                }
            } catch (Throwable $e) {
                $odp['xe_stan'] = 'blad';
                $odp['xe_blad'] = $e->getMessage();
            }

            $odp['sekcje'][] = sekcja($pdo, 'Najdroższe zapytania wg CPU (cache planów)',
                'Agregat wszystkich wykonań — bez podziału na użytkowników, bo cache planów takiej informacji nie przechowuje.',
                Q_TOP_ZAPYTANIA);

        } elseif ($widok === 'uzytkownicy') {
            $odp['sekcje'][] = sekcja($pdo, 'Token sesji aplikacji (CONTEXT_INFO)',
                'Wartość binarna ustawiana przez aplikację. Asseco SOFTLAB ERP trzyma tu własny identyfikator sesji — nie nadpisuj go.',
                Q_UZ_KONTEKST);
            $iis = (string)$KONFIG['adres_iis'];
            $odp['sekcje'][] = sekcja($pdo, 'Połączenia spoza serwera aplikacji',
                $iis !== ''
                    ? 'Sesje omijające ERP — SSMS, Excel, skrypty, integracje. Ruch z ' . $iis . ' jest pominięty.'
                    : 'Wszystkie połączenia w rozbiciu na adres, stację i aplikację.',
                Q_UZ_OBCE, [$iis, $iis]);
            $odp['sekcje'][] = sekcja($pdo, 'Zużycie zasobów wg loginu',
                'Liczniki CPU i odczytów narastają przez cały czas trwania sesji, nie dotyczą tylko bieżącego zapytania.',
                Q_UZ_PODSUMOWANIE);
            $odp['sekcje'][] = sekcja($pdo, 'Skąd łączą się użytkownicy',
                'Rozbicie na stację roboczą, aplikację i bazę. Pokazuje, który moduł generuje ruch.',
                Q_UZ_STACJE);
            $odp['sekcje'][] = sekcja($pdo, 'Co robią w tej chwili',
                'Wyłącznie sesje z aktywnym żądaniem, od najdłużej działających.', Q_UZ_TERAZ);
            $odp['sekcje'][] = sekcja($pdo, 'Otwarte transakcje wg użytkownika',
                'Sesja ze statusem "sleeping" i otwartą transakcją to typowy sprawca blokad.',
                Q_UZ_TRANSAKCJE);
            $odp['sekcje'][] = sekcja($pdo, 'Zużycie tempdb wg użytkownika',
                'Obiekty wewnętrzne to sortowania, hasze i tabele robocze planu wykonania.',
                Q_UZ_TEMPDB);

        } elseif ($widok === 'historia') {
            $baza = (string)($KONFIG['serwery'][$nazwaSerwera]['baza_historii'] ?? '');
            if ($baza === '') {
                $odp['blad_historii'] = 'Kolektor historii nie jest skonfigurowany.';
            } elseif (!bazaOk($baza)) {
                $odp['blad_historii'] = 'Nieprawidłowa nazwa bazy historii w konfiguracji: ' . $baza;
            } else {
                try {
                    $godzin = max(1, min(168, (int)($_GET['godzin'] ?? 6)));
                    $odp['seria']  = historiaSeria($pdo, $baza, $godzin);
                    $odp['godzin'] = $godzin;
                    $odp['sekcje'][] = [
                        'tytul' => 'Obciążenie w rozbiciu na godziny',
                        'opis'  => 'Ostatnie 48 godzin pracy. Pokazuje, kiedy naprawdę boli.',
                        'kolumny' => [], 'wiersze' => historiaGodzinowa($pdo, $baza), 'blad' => null,
                    ];
                    if ($odp['sekcje'][0]['wiersze']) {
                        $odp['sekcje'][0]['kolumny'] = array_keys($odp['sekcje'][0]['wiersze'][0]);
                    }
                } catch (Throwable $e) {
                    $odp['blad_historii'] = 'Nie udało się odczytać bazy ' . $baza . '. '
                        . 'Uruchom skrypt 08_kolektor_historii.sql na tej instancji. (' . $e->getMessage() . ')';
                }
            }
        }

        echo json_encode($odp, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'blad' => 'Nie udało się połączyć z instancją ' . $nazwaSerwera . ': ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/*--------------------------------------------------------------- WIDOK HTML */

/* Przełącznik pokazuje wyłącznie instancje wybranego serwera. */
$serwery = [];
foreach ($KONFIG['serwery'] as $id => $cfg) {
    if ($SERWER_ID && $cfg['serwer_id'] !== $SERWER_ID) continue;
    $serwery[$id] = $cfg['etykieta'];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SQLMON — obciążenie MS SQL Server</title>
<style>
:root{
  --papier:#e6e9ed;
  --karta:#f4f6f8;
  --siatka:#c3ccd6;
  --siatka-gruba:#a7b4c2;
  --atrament:#131c26;
  --atrament-2:#5b6b7c;
  --kreska:#1a5c9e;
  --kreska-2:#b0741c;
  --dobry:#256b5a;
  --uwaga:#a86a12;
  --alarm:#9d1f26;
  --ramka:#b9c3cd;
  --cien:0 1px 0 rgba(19,28,38,.06);
  --mono:"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;
  --tekst:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
}
html[data-tryb="noc"]{
  --papier:#0f151c;
  --karta:#161f29;
  --siatka:#25313e;
  --siatka-gruba:#33424f;
  --atrament:#dfe7ee;
  --atrament-2:#8496a6;
  --kreska:#4d9de0;
  --kreska-2:#e0a33c;
  --dobry:#4bbf9a;
  --uwaga:#e0a33c;
  --alarm:#e26a70;
  --ramka:#2a3744;
  --cien:none;
}
*{box-sizing:border-box}
body{
  margin:0;padding:0;background:var(--papier);color:var(--atrament);
  font:14px/1.5 var(--tekst);
}
a{color:var(--kreska)}

/* --- pasek górny: listwa aparatury --- */
.listwa{
  position:sticky;top:0;z-index:20;
  display:flex;align-items:center;gap:18px;flex-wrap:wrap;
  padding:10px 18px;background:var(--karta);
  border-bottom:1px solid var(--ramka);box-shadow:var(--cien);
}
.marka{font:600 13px/1 var(--mono);letter-spacing:.22em;text-transform:uppercase}
.marka span{color:var(--kreska)}
.serwer-nazwa{font:600 12px/1 var(--mono);letter-spacing:.14em;text-transform:uppercase;
  color:var(--atrament);padding:5px 9px;border:1px solid var(--ramka);border-radius:2px;
  background:var(--papier)}
.serwer-host{font-weight:400;letter-spacing:.04em;text-transform:none;color:var(--atrament-2);
  margin-left:7px}
.marka .wersja{font:500 10px/1 var(--mono);letter-spacing:.06em;text-transform:none;
  color:var(--atrament-2);margin-left:8px;padding:2px 5px;border:1px solid var(--ramka);
  border-radius:3px;font-style:normal;cursor:help}
.powrot{
  display:inline-flex;align-items:center;text-decoration:none;color:var(--atrament-2);
  font:12px/1 var(--mono);letter-spacing:.08em;padding:6px 10px;
  border:1px solid var(--ramka);border-radius:2px;background:var(--papier);
}
.powrot:hover{color:var(--kreska);border-color:var(--kreska)}
.powrot:focus-visible{outline:2px solid var(--kreska);outline-offset:1px}
.listwa label{font:11px/1 var(--mono);letter-spacing:.12em;text-transform:uppercase;color:var(--atrament-2)}
select,button{
  font:12px/1 var(--mono);color:var(--atrament);background:var(--papier);
  border:1px solid var(--ramka);border-radius:2px;padding:6px 9px;cursor:pointer;
}
select:focus-visible,button:focus-visible,.zakladka:focus-visible{outline:2px solid var(--kreska);outline-offset:1px}
.zegar{margin-left:auto;font:11px/1 var(--mono);color:var(--atrament-2);letter-spacing:.08em}
.dioda{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--dobry);margin-right:6px;vertical-align:middle}
.dioda.pracuje{background:var(--kreska-2);animation:puls 1s infinite}
.dioda.blad{background:var(--alarm)}
@keyframes puls{50%{opacity:.25}}

/* --- zakładki --- */
.zakladki{display:flex;gap:0;padding:0 18px;background:var(--karta);border-bottom:1px solid var(--ramka);overflow-x:auto}
.zakladka{
  border:0;border-bottom:2px solid transparent;background:none;border-radius:0;
  padding:10px 14px;white-space:nowrap;color:var(--atrament-2);
  font:12px/1 var(--mono);letter-spacing:.1em;text-transform:uppercase;
}
.zakladka[aria-selected="true"]{color:var(--atrament);border-bottom-color:var(--kreska)}

main{padding:18px;max-width:1600px;margin:0 auto}

/* --- rejestrator: element sygnaturowy --- */
.rejestrator{
  background:var(--karta);border:1px solid var(--ramka);border-radius:2px;
  padding:14px 16px 8px;margin-bottom:16px;box-shadow:var(--cien);
}
.rejestrator header{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin-bottom:10px}
.rejestrator h2{margin:0;font:600 12px/1 var(--mono);letter-spacing:.18em;text-transform:uppercase}
.legenda{display:flex;gap:14px;margin-left:auto;font:11px/1 var(--mono);color:var(--atrament-2)}
.legenda i{display:inline-block;width:14px;height:2px;margin-right:5px;vertical-align:middle}
canvas{display:block;width:100%;height:190px}
.metryka-info{font:11px/1 var(--mono);color:var(--atrament-2);letter-spacing:.06em;padding:6px 0 0}

/* --- kafelki --- */
.kafelki{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:1px;
  background:var(--ramka);border:1px solid var(--ramka);border-radius:2px;margin-bottom:16px;overflow:hidden}
.kafel{background:var(--karta);padding:12px 14px;position:relative}
.kafel .etykieta{font:10px/1.3 var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--atrament-2)}
.kafel .liczba{font:400 27px/1.15 var(--mono);margin-top:7px;font-variant-numeric:tabular-nums}
.kafel .jedn{font-size:13px;color:var(--atrament-2);margin-left:3px}
.kafel::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:transparent}
.kafel[data-stan="dobry"]::before{background:var(--dobry)}
.kafel[data-stan="uwaga"]::before{background:var(--uwaga)}
.kafel[data-stan="alarm"]::before{background:var(--alarm)}
.kafel[data-stan="alarm"] .liczba{color:var(--alarm)}
.kafel[data-stan="uwaga"] .liczba{color:var(--uwaga)}

/* --- pasek filtrowania --- */
.filtr{
  display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  background:var(--karta);border:1px solid var(--ramka);border-radius:2px;
  padding:11px 16px;margin-bottom:16px;box-shadow:var(--cien);
}
.filtr label{font:10.5px/1 var(--mono);letter-spacing:.1em;text-transform:uppercase;color:var(--atrament-2)}
.filtr input[type=search]{
  font:12.5px var(--mono);padding:6px 9px;min-width:230px;flex:1 1 230px;
  border:1px solid var(--ramka);border-radius:2px;background:var(--papier);color:var(--atrament);
}
.filtr input[type=search]:focus-visible,.filtr select:focus-visible{outline:2px solid var(--kreska);outline-offset:1px}
.filtr .wynik{font:11px/1 var(--mono);color:var(--atrament-2);letter-spacing:.06em;margin-left:auto}
.filtr .czysc{padding:5px 9px;font-size:11px}
.plakietka{
  display:inline-block;padding:3px 8px;border:1px solid var(--kreska);border-radius:2px;
  font:11px/1.4 var(--mono);color:var(--kreska);
}
tr.ukryty{display:none}

/* --- sekcje tabelaryczne --- */
.sekcja{background:var(--karta);border:1px solid var(--ramka);border-radius:2px;margin-bottom:16px;box-shadow:var(--cien)}
.sekcja > header{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--ramka)}
.sekcja h2{margin:0;font:600 12px/1 var(--mono);letter-spacing:.16em;text-transform:uppercase}
.sekcja p{margin:0;color:var(--atrament-2);font-size:12.5px}
.sekcja .csv{margin-left:auto;padding:4px 8px;font-size:11px}
.przewijak{overflow-x:auto}
table{border-collapse:collapse;width:100%;font:12.5px/1.45 var(--mono);font-variant-numeric:tabular-nums}
th{
  text-align:left;padding:7px 12px;position:sticky;top:0;background:var(--karta);
  border-bottom:1px solid var(--siatka-gruba);font-weight:600;white-space:nowrap;
  font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--atrament-2);
}
td{padding:6px 12px;border-bottom:1px solid var(--siatka);vertical-align:top}
tbody tr:hover td{background:var(--papier)}
td.liczba{text-align:right}
td.sql{font-size:11.5px;color:var(--atrament-2);max-width:520px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:zoom-in}
td.sql.rozwin{white-space:pre-wrap;overflow:visible;max-width:none;color:var(--atrament)}
td em{font-style:normal;color:var(--alarm)}
.pusto{padding:22px 16px;color:var(--atrament-2);font-size:13px}
.blad{padding:12px 16px;background:color-mix(in srgb,var(--alarm) 10%,transparent);
  border-left:3px solid var(--alarm);font-size:13px;margin-bottom:16px;border-radius:2px}
.uwaga-xe{padding:11px 16px;background:var(--karta);border:1px solid var(--ramka);
  border-left:3px solid var(--uwaga);font-size:12.5px;margin-bottom:16px;border-radius:2px;
  color:var(--atrament-2)}
.uwaga-xe.info{border-left-color:var(--ramka)}
.uwaga-xe code{font:11.5px var(--mono);background:var(--papier);padding:2px 5px;border-radius:2px;
  color:var(--atrament)}

/* --- ekran logowania i braku sterownika --- */
.brama{max-width:640px;margin:60px auto;padding:26px 28px;background:var(--karta);
  border:1px solid var(--ramka);border-radius:2px}
.brama h1{font:600 15px/1.3 var(--mono);letter-spacing:.14em;text-transform:uppercase;margin:0 0 14px}
.brama code{font:12px var(--mono);background:var(--papier);padding:2px 5px;border-radius:2px}
.brama ol{padding-left:20px}
.brama li{margin-bottom:9px}
input[type=password]{font:13px var(--mono);padding:8px 10px;border:1px solid var(--ramka);
  border-radius:2px;background:var(--papier);color:var(--atrament);width:220px}

@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
@media (max-width:640px){
  main{padding:12px}
  .listwa{gap:10px;padding:9px 12px}
  .zakladki{padding:0 8px}
  td.sql{max-width:220px}
}
</style>
</head>
<body>

<?php if (!sterownikDostepny()): ?>
<div class="brama">
  <h1>Brak sterownika SQL Server</h1>
  <p>PHP nie ma rozszerzenia <code>pdo_sqlsrv</code>. Bez niego nie połączy się z instancją. Instalacja pod XAMPP:</p>
  <ol>
    <li>Sprawdź swoją wersję PHP i tryb wątkowości — na tej instalacji:
        <code>PHP <?= PHP_VERSION ?> · <?= ZEND_THREAD_SAFE ? 'Thread Safe (TS)' : 'Non Thread Safe (NTS)' ?> · <?= PHP_INT_SIZE * 8 ?>-bit</code>.
        XAMPP z Apache używa wersji <strong>TS</strong>.</li>
    <li>Pobierz „Microsoft Drivers for PHP for SQL Server” z witryny Microsoftu i rozpakuj.
        Weź pliki pasujące do wersji wyżej, np. <code>php_sqlsrv_82_ts_x64.dll</code>
        i <code>php_pdo_sqlsrv_82_ts_x64.dll</code>.</li>
    <li>Skopiuj obie biblioteki do <code>C:\xampp\php\ext\</code>.</li>
    <li>W <code>C:\xampp\php\php.ini</code> dopisz na końcu sekcji rozszerzeń:<br>
        <code>extension=php_sqlsrv_82_ts_x64.dll</code><br>
        <code>extension=php_pdo_sqlsrv_82_ts_x64.dll</code></li>
    <li>Zainstaluj <strong>Microsoft ODBC Driver 18 for SQL Server</strong> (osobny instalator MSI).</li>
    <li>Zrestartuj Apache w panelu XAMPP i odśwież tę stronę.</li>
  </ol>
  <p>Jeśli po restarcie nadal nie działa, sprawdź <code>phpinfo()</code> — sekcja <code>sqlsrv</code> musi być widoczna.</p>
</div>

<?php elseif (!$zalogowany): ?>
<div class="brama">
  <h1>SQLMON</h1>
  <p>Podaj hasło dostępu.</p>
  <?php if (!empty($bladLogowania)): ?><p style="color:var(--alarm)"><?= htmlspecialchars($bladLogowania) ?></p><?php endif; ?>
  <form method="post">
    <input type="password" name="haslo" autofocus autocomplete="current-password">
    <button type="submit">Wejdź</button>
  </form>
</div>

<?php else: ?>

<div class="listwa">
  <a class="powrot" href="<?= htmlspecialchars($KONFIG['strona_glowna'] !== '' ? $KONFIG['strona_glowna'] : 'index.php') ?>" title="Wróć do listy serwerów">← Serwery</a>
  <div class="marka">SQL<span>MON</span><em class="wersja" title="<?= htmlspecialchars(implode(' · ', APP_CHANGELOG[APP_VERSION] ?? [])) ?>">v<?= APP_VERSION ?></em></div>

  <?php if ($SERWER): ?>
    <div class="serwer-nazwa" title="<?= htmlspecialchars($SERWER['opis'] ?: $SERWER['host']) ?>">
      <?= htmlspecialchars($SERWER['nazwa']) ?>
      <span class="serwer-host"><?= htmlspecialchars($SERWER['host']) ?></span>
    </div>
  <?php endif; ?>

  <label for="wybor-serwera">Instancja</label>
  <select id="wybor-serwera">
    <?php foreach ($serwery as $id => $etykieta): ?>
      <option value="<?= htmlspecialchars((string)$id) ?>"><?= htmlspecialchars($etykieta) ?></option>
    <?php endforeach; ?>
    <?php if (!$serwery): ?><option value="">— brak instancji —</option><?php endif; ?>
  </select>

  <label for="wybor-odswiezania">Odświeżanie</label>
  <select id="wybor-odswiezania">
    <option value="0">ręcznie</option>
    <option value="10">10 s</option>
    <option value="30" selected>30 s</option>
    <option value="60">60 s</option>
    <option value="300">5 min</option>
  </select>

  <button id="odswiez">Odśwież teraz</button>
  <button id="tryb-nocny" title="Przełącz kontrast">Tryb nocny</button>
  <a class="powrot" href="konfiguracja.php" title="Serwery, instancje i ustawienia">Konfiguracja</a>

  <div class="zegar"><span class="dioda" id="dioda"></span><span id="stempel">—</span></div>
</div>

<div class="zakladki" role="tablist">
  <button class="zakladka" role="tab" data-widok="przeglad"  aria-selected="true">Przegląd</button>
  <button class="zakladka" role="tab" data-widok="aktywnosc" aria-selected="false">Aktywność</button>
  <button class="zakladka" role="tab" data-widok="uzytkownicy" aria-selected="false">Użytkownicy</button>
  <button class="zakladka" role="tab" data-widok="io"        aria-selected="false">Dyski i bazy</button>
  <button class="zakladka" role="tab" data-widok="zapytania" aria-selected="false">Zapytania</button>
  <button class="zakladka" role="tab" data-widok="historia"  aria-selected="false">Historia</button>
</div>

<main id="tresc"></main>

<script>
(() => {
'use strict';

const ODSWIEZANIE_START = <?= (int)$KONFIG['odswiezanie_sek'] ?>;
const tresc   = document.getElementById('tresc');
const dioda   = document.getElementById('dioda');
const stempel = document.getElementById('stempel');

let widok    = 'przeglad';
let serwer   = document.getElementById('wybor-serwera').value;
let interwal = null;
let ostatnie = null;
let login    = '';
let szukaj   = '';

/* --- filtrowanie tekstowe po stronie przeglądarki --- */
function filtrujTekst() {
  const fraza = szukaj.trim().toLowerCase();
  const wynik = document.getElementById('filtr-wynik');
  let widoczne = 0, wszystkie = 0;

  document.querySelectorAll('.sekcja tbody tr').forEach(tr => {
    wszystkie++;
    const pasuje = !fraza || tr.textContent.toLowerCase().includes(fraza);
    tr.classList.toggle('ukryty', !pasuje);
    if (pasuje) widoczne++;
  });

  if (wynik) {
    wynik.textContent = fraza
      ? `${widoczne} z ${wszystkie} wierszy`
      : `${wszystkie} wierszy`;
  }
}

/* ---------- narzędzia ---------- */

const esc = t => String(t ?? '').replace(/[&<>"]/g, z => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[z]));

const liczba = w => {
  if (w === null || w === undefined || w === '') return '—';
  if (typeof w === 'number' || (!isNaN(w) && String(w).trim() !== '')) {
    const n = Number(w);
    return Number.isInteger(n) ? n.toLocaleString('pl-PL') : n.toLocaleString('pl-PL', {maximumFractionDigits: 2});
  }
  return w;
};

const jestLiczba = w => w !== null && w !== '' && !isNaN(w) && typeof w !== 'boolean';

const kolor = nazwa => getComputedStyle(document.documentElement).getPropertyValue(nazwa).trim();

/* ---------- rejestrator: wykres taśmowy na siatce milimetrowej ---------- */

function rysujRejestrator(canvas, dane, opcje) {
  const dpr = window.devicePixelRatio || 1;
  const szer = canvas.clientWidth, wys = canvas.clientHeight;
  canvas.width = szer * dpr; canvas.height = wys * dpr;
  const c = canvas.getContext('2d');
  c.setTransform(dpr, 0, 0, dpr, 0, 0);
  c.clearRect(0, 0, szer, wys);

  const marL = 42, marP = 10, marG = 10, marD = 22;
  const w = szer - marL - marP, h = wys - marG - marD;
  const maks = opcje.maks;

  // siatka drobna
  c.strokeStyle = kolor('--siatka'); c.lineWidth = 1;
  for (let i = 0; i <= 20; i++) {
    const x = marL + w * i / 20;
    c.beginPath(); c.moveTo(Math.round(x) + .5, marG); c.lineTo(Math.round(x) + .5, marG + h); c.stroke();
  }
  for (let i = 0; i <= 10; i++) {
    const y = marG + h * i / 10;
    c.beginPath(); c.moveTo(marL, Math.round(y) + .5); c.lineTo(marL + w, Math.round(y) + .5); c.stroke();
  }
  // siatka gruba + opis osi
  c.strokeStyle = kolor('--siatka-gruba');
  c.fillStyle = kolor('--atrament-2');
  c.font = '10px ui-monospace, Consolas, monospace';
  c.textAlign = 'right';
  for (let i = 0; i <= 4; i++) {
    const y = marG + h * i / 4;
    c.beginPath(); c.moveTo(marL, Math.round(y) + .5); c.lineTo(marL + w, Math.round(y) + .5); c.stroke();
    c.fillText(Math.round(maks - maks * i / 4) + (opcje.jednostka || ''), marL - 7, y + 3.5);
  }

  if (!dane.length) {
    c.textAlign = 'center';
    c.fillText('brak danych', marL + w / 2, marG + h / 2);
    return;
  }

  // próg alarmowy
  if (opcje.prog) {
    const y = marG + h - h * Math.min(opcje.prog / maks, 1);
    c.save(); c.setLineDash([4, 4]); c.strokeStyle = kolor('--alarm'); c.globalAlpha = .7;
    c.beginPath(); c.moveTo(marL, y); c.lineTo(marL + w, y); c.stroke(); c.restore();
  }

  const x = i => marL + (dane.length === 1 ? w / 2 : w * i / (dane.length - 1));
  const y = v => marG + h - h * Math.min(Math.max(Number(v) || 0, 0) / maks, 1);

  // ślady
  opcje.serie.forEach(seria => {
    if (seria.wypelnij) {
      c.beginPath(); c.moveTo(x(0), marG + h);
      dane.forEach((d, i) => c.lineTo(x(i), y(d[seria.pole])));
      c.lineTo(x(dane.length - 1), marG + h); c.closePath();
      c.fillStyle = kolor(seria.kolor); c.globalAlpha = .12; c.fill(); c.globalAlpha = 1;
    }
    c.beginPath();
    dane.forEach((d, i) => i ? c.lineTo(x(i), y(d[seria.pole])) : c.moveTo(x(i), y(d[seria.pole])));
    c.strokeStyle = kolor(seria.kolor);
    c.lineWidth = seria.grubosc || 1.6;
    c.setLineDash(seria.kreskowany ? [3, 3] : []);
    c.lineJoin = 'round';
    c.stroke();
    c.setLineDash([]);
  });

  // podpisy czasu
  c.fillStyle = kolor('--atrament-2'); c.textAlign = 'center';
  const krok = Math.max(1, Math.ceil(dane.length / 8));
  dane.forEach((d, i) => {
    if (i % krok === 0 || i === dane.length - 1) c.fillText(d.czas, x(i), marG + h + 14);
  });
}

/* ---------- budowanie widoku ---------- */

function kafelki(lista) {
  return '<div class="kafelki">' + lista.map(k => `
    <div class="kafel" data-stan="${esc(k.stan)}">
      <div class="etykieta">${esc(k.etykieta)}</div>
      <div class="liczba">${liczba(k.wartosc)}<span class="jedn">${esc(k.jednostka)}</span></div>
    </div>`).join('') + '</div>';
}

function tabela(s, indeks) {
  if (s.blad) {
    return `<section class="sekcja"><header><h2>${esc(s.tytul)}</h2></header>
            <div class="blad">Nie udało się pobrać danych: ${esc(s.blad)}</div></section>`;
  }
  const naglowek = `<header><h2>${esc(s.tytul)}</h2><p>${esc(s.opis)}</p>
      ${s.wiersze.length ? `<button class="csv" data-sekcja="${indeks}">Pobierz CSV</button>` : ''}</header>`;

  if (!s.wiersze.length) {
    return `<section class="sekcja">${naglowek}<div class="pusto">Brak wierszy — nic do zgłoszenia.</div></section>`;
  }

  const kol = s.kolumny.length ? s.kolumny : Object.keys(s.wiersze[0]);
  const dlugie = k => /zapytanie|instrukcja|zasob|znaczenie|program|aplikacja/i.test(k);

  const body = s.wiersze.map(r => '<tr>' + kol.map(k => {
    const v = r[k];
    if (dlugie(k)) return `<td class="sql" title="kliknij, aby rozwinąć">${esc(v)}</td>`;
    if (jestLiczba(v)) return `<td class="liczba">${liczba(v)}</td>`;
    return `<td>${esc(v ?? '—')}</td>`;
  }).join('') + '</tr>').join('');

  return `<section class="sekcja" data-indeks="${indeks}">${naglowek}
    <div class="przewijak"><table>
      <thead><tr>${kol.map(k => `<th>${esc(k)}</th>`).join('')}</tr></thead>
      <tbody>${body}</tbody>
    </table></div></section>`;
}

/* Opis instancji pod wykresem CPU. Ostrzeżenie o Cost Threshold ma sens tylko
   wtedy, gdy równoległość jest w ogóle włączona — przy MAXDOP 1 (typowym
   wymogu dostawców ERP) żadne zapytanie nie idzie równolegle i próg jest
   martwym ustawieniem. */
function opisSerwera(n) {
  const maxdop = (n.maxdop === null || n.maxdop === undefined) ? null : Number(n.maxdop);
  const czesci = [
    n.edycja,
    `wersja ${n.wersja}`,
    `${n.rdzenie} rdzeni logicznych`,
  ];
  if (n.numa && Number(n.numa) > 1) czesci.push(`${n.numa} węzły NUMA`);
  if (maxdop !== null) {
    czesci.push(maxdop === 0 ? 'MAXDOP 0 (bez limitu)'
              : maxdop === 1 ? 'MAXDOP 1 — bez równoległości'
              : `MAXDOP ${maxdop}`);
  }
  if (n.ctfp !== null && n.ctfp !== undefined) {
    czesci.push(maxdop !== 1 && Number(n.ctfp) <= 5
      ? `Cost Threshold ${n.ctfp} ⚠ domyślna wartość`
      : `Cost Threshold ${n.ctfp}`);
  }
  czesci.push(`praca od ${n.uptime} h`);
  return czesci.join(' · ');
}

function rejestrator(id, tytul, podpis, legenda) {
  return `<div class="rejestrator">
    <header><h2>${esc(tytul)}</h2>
      <div class="legenda">${legenda}</div>
    </header>
    <canvas id="${id}"></canvas>
    <div class="metryka-info">${esc(podpis)}</div>
  </div>`;
}

/* ---------- pobieranie ---------- */

/*  Pojedyncze nieudane żądanie nie powinno kasować całego widoku — przy
    odświeżaniu co 30 s jedna zerwana odpowiedź zostawiała pustą stronę.
    Zamiast tego pokazujemy pasek nad zachowaną treścią i próbujemy ponownie.  */

const LIMIT_CZASU_MS = 45000;   // dłużej niż limit zapytania po stronie SQL (20 s)
let bylUdanyOdczyt = false;

function pasekAwarii(tekst, szczegol) {
  document.getElementById('pasek-awarii')?.remove();
  const p = document.createElement('div');
  p.id = 'pasek-awarii';
  p.className = 'blad';
  p.textContent = szczegol ? `${tekst} — ${szczegol}` : tekst;
  if (bylUdanyOdczyt) {
    p.textContent += '. Pokazane dane pochodzą z poprzedniego odczytu.';
    tresc.prepend(p);
  } else {
    tresc.innerHTML = '';
    tresc.appendChild(p);
  }
}

async function jednoZadanie(url) {
  const stoper = new AbortController();
  const budzik = setTimeout(() => stoper.abort(), LIMIT_CZASU_MS);
  try {
    return await fetch(url, {
      headers: {'Accept': 'application/json'},
      cache: 'no-store',
      signal: stoper.signal,
    });
  } finally {
    clearTimeout(budzik);
  }
}

async function wczytaj() {
  dioda.className = 'dioda pracuje';
  const godzin = document.getElementById('zakres-historii')?.value || 6;
  const url = `?akcja=dane&widok=${widok}&serwer=${encodeURIComponent(serwer)}`
            + `&godzin=${godzin}&login=${encodeURIComponent(login)}`;

  let odp;
  try {
    odp = await jednoZadanie(url);
  } catch (e) {
    // Zerwane połączenie zdarza się przy recyklingu procesu serwera WWW.
    // Jedna cicha próba ponowna, zanim zawiadomimy użytkownika.
    try {
      await new Promise(r => setTimeout(r, 1500));
      odp = await jednoZadanie(url);
    } catch (e2) {
      dioda.className = 'dioda blad';
      pasekAwarii(
        e2.name === 'AbortError'
          ? `Serwer WWW nie odpowiedział w ciągu ${LIMIT_CZASU_MS / 1000} s`
          : 'Przerwana łączność z serwerem WWW',
        e2.name === 'AbortError' ? null : e2.message);
      return;
    }
  }

  if (odp.status === 401) {
    dioda.className = 'dioda blad';
    pasekAwarii('Sesja wygasła. Odśwież stronę i zaloguj się ponownie');
    return;
  }

  // Gdy PHP przerwie się błędem, dostaniemy HTML zamiast JSON. Bez tego
  // użytkownik widziałby wyłącznie komunikat parsera o nieoczekiwanym znaku.
  const tekst = await odp.text();
  let d;
  try {
    d = JSON.parse(tekst);
  } catch (e) {
    dioda.className = 'dioda blad';
    pasekAwarii(
      `Serwer WWW zwrócił odpowiedź, która nie jest danymi (HTTP ${odp.status})`,
      tekst.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) || 'pusta odpowiedź');
    return;
  }

  ostatnie = d;

  if (!d.ok) {
    dioda.className = 'dioda blad';
    pasekAwarii(d.blad || 'Nieznany błąd po stronie serwera');
    return;
  }

  document.getElementById('pasek-awarii')?.remove();
  narysuj(d);
  stempel.textContent = 'odczyt ' + d.czas;
  dioda.className = 'dioda';
  bylUdanyOdczyt = true;
}

function narysuj(d) {
  let html = '';

  if (widok === 'zapytania') {
    const opcje = ['<option value="">wszyscy użytkownicy</option>']
      .concat((d.loginy || []).map(l =>
        `<option value="${esc(l)}" ${l === d.login ? 'selected' : ''}>${esc(l)}</option>`))
      .join('');

    html += `<div class="filtr">
      <label for="filtr-login">Użytkownik</label>
      <select id="filtr-login">${opcje}</select>
      <label for="filtr-tekst">Szukaj w treści</label>
      <input type="search" id="filtr-tekst" placeholder="fragment zapytania, baza, aplikacja…"
             value="${esc(szukaj)}" autocomplete="off">
      <button class="czysc" id="filtr-czysc">Wyczyść</button>
      <span class="wynik" id="filtr-wynik"></span>
    </div>`;

    if (d.xe_stan === 'brak') {
      html += `<div class="uwaga-xe">Historia zapytań wg użytkownika jest niedostępna —
        sesja Extended Events <code>sqlmon_zapytania</code> nie działa na tej instancji.
        Uruchom skrypt <code>09_kolektor_zapytan_xe.sql</code>, aby zbierać kto co uruchamiał.</div>`;
    } else if (d.xe_stan === 'pusta') {
      html += `<div class="uwaga-xe info">Sesja Extended Events <code>sqlmon_zapytania</code> działa,
        ale jej bufor nie zawiera jeszcze ${d.login ? `zapytań użytkownika <code>${esc(d.login)}</code>`
        : 'żadnych zapytań'} dłuższych niż próg ze skryptu 09 (domyślnie 3 s).
        Bufor zbiera dane od startu sesji i zeruje się po restarcie instancji lub ponownym uruchomieniu skryptu.</div>`;
    } else if (d.xe_stan === 'blad') {
      html += `<div class="uwaga-xe">Nie udało się odczytać historii z sesji Extended Events
        <code>sqlmon_zapytania</code>: <code>${esc(d.xe_blad || 'nieznany błąd')}</code></div>`;
    }
  }

  if (widok === 'przeglad') {
    html += rejestrator('wykres-cpu', 'Obciążenie procesora · ostatnia godzina',
      opisSerwera(d.naglowek),
      `<span><i style="background:var(--kreska)"></i>proces SQL Server</span>
       <span><i style="background:var(--kreska-2)"></i>pozostałe procesy</span>`);
    html += kafelki(d.kafelki);
  }

  if (widok === 'historia') {
    if (d.blad_historii) {
      html += `<div class="blad">${esc(d.blad_historii)}</div>`;
    } else {
      html += `<div class="rejestrator">
        <header><h2>Historia obciążenia</h2>
          <label for="zakres-historii" style="font:11px/1 var(--mono);color:var(--atrament-2)">Zakres</label>
          <select id="zakres-historii">
            ${[1,6,12,24,72,168].map(g => `<option value="${g}" ${g == d.godzin ? 'selected' : ''}>${g} h</option>`).join('')}
          </select>
          <div class="legenda">
            <span><i style="background:var(--kreska)"></i>CPU SQL %</span>
            <span><i style="background:var(--kreska-2)"></i>sesje</span>
          </div>
        </header>
        <canvas id="wykres-hist"></canvas>
        <div class="metryka-info">Dane z kolektora DBA_Monitoring, próbkowanie co minutę.</div>
      </div>`;
    }
  }

  (d.sekcje || []).forEach((s, i) => html += tabela(s, i));
  tresc.innerHTML = html;

  if (widok === 'zapytania') {
    document.getElementById('filtr-login').addEventListener('change', e => {
      login = e.target.value;
      wczytaj();
    });

    const pole = document.getElementById('filtr-tekst');
    let opoznienie = null;
    pole.addEventListener('input', e => {
      szukaj = e.target.value;
      clearTimeout(opoznienie);
      opoznienie = setTimeout(filtrujTekst, 150);
    });

    document.getElementById('filtr-czysc').addEventListener('click', () => {
      login = ''; szukaj = '';
      wczytaj();
    });

    filtrujTekst();
  }

  if (widok === 'przeglad') {
    rysujRejestrator(document.getElementById('wykres-cpu'), d.seria || [], {
      maks: 100, jednostka: '%', prog: 85,
      serie: [
        {pole: 'cpu_sql',  kolor: '--kreska',   wypelnij: true},
        {pole: 'cpu_inne', kolor: '--kreska-2', kreskowany: true, grubosc: 1.2},
      ],
    });
  }

  if (widok === 'historia' && d.seria) {
    const maks = Math.max(100, ...d.seria.map(r => Number(r.sesje) || 0));
    rysujRejestrator(document.getElementById('wykres-hist'), d.seria, {
      maks: maks, jednostka: '',
      serie: [
        {pole: 'cpu_sql', kolor: '--kreska',   wypelnij: true},
        {pole: 'sesje',   kolor: '--kreska-2', grubosc: 1.2},
      ],
    });
    document.getElementById('zakres-historii')?.addEventListener('change', wczytaj);
  }
}

/* ---------- eksport CSV ---------- */

function csv(indeks) {
  const s = ostatnie.sekcje[indeks];
  const kol = s.kolumny.length ? s.kolumny : Object.keys(s.wiersze[0]);

  /* Dwie rzeczy naprawiane przy eksporcie:
     1. Separator dziesiętny. Excel z polskimi ustawieniami czyta "7.57" jako
        datę i zamienia na "lip.57". Liczby eksportujemy więc z przecinkiem.
     2. Wstrzyknięcie formuły. Komórka zaczynająca się od = + - @ (albo od tabulatora
        czy CR) jest przez Excela wykonywana jako formuła, a wartości pochodzą
        z nazw programów i treści zapytań, czyli z zewnątrz. Poprzedzamy apostrofem. */
  const pole = v => {
    if (v === null || v === undefined) return '""';
    if (jestLiczba(v)) return `"${String(v).replace('.', ',')}"`;
    let t = String(v);
    if (/^[=+\-@\t\r]/.test(t)) t = "'" + t;
    return `"${t.replace(/"/g, '""')}"`;
  };

  // Eksportujemy to, co widać – z uwzględnieniem filtra tekstowego.
  const fraza = szukaj.trim().toLowerCase();
  const wiersze = fraza
    ? s.wiersze.filter(r => kol.map(k => r[k]).join(' ').toLowerCase().includes(fraza))
    : s.wiersze;

  const tekst = [kol.map(k => `"${String(k).replace(/"/g, '""')}"`).join(';')]
    .concat(wiersze.map(r => kol.map(k => pole(r[k])).join(';')))
    .join('\r\n');

  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['\uFEFF' + tekst], {type: 'text/csv;charset=utf-8'}));
  a.download = `sqlmon_${serwer}_${s.tytul.replace(/\W+/g, '_').toLowerCase()}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ---------- zdarzenia ---------- */

tresc.addEventListener('click', e => {
  if (e.target.matches('.csv')) csv(Number(e.target.dataset.sekcja));
  if (e.target.matches('td.sql')) e.target.classList.toggle('rozwin');
});

document.querySelectorAll('.zakladka').forEach(z => z.addEventListener('click', () => {
  document.querySelectorAll('.zakladka').forEach(x => x.setAttribute('aria-selected', 'false'));
  z.setAttribute('aria-selected', 'true');
  widok = z.dataset.widok;
  szukaj = '';
  wczytaj();
}));

document.getElementById('wybor-serwera').addEventListener('change', e => { serwer = e.target.value; wczytaj(); });
document.getElementById('odswiez').addEventListener('click', wczytaj);

document.getElementById('wybor-odswiezania').addEventListener('change', e => {
  clearInterval(interwal);
  const sek = Number(e.target.value);
  if (sek) interwal = setInterval(wczytaj, sek * 1000);
  localStorage.setItem('sqlmon_odswiezanie', sek);
});

document.getElementById('tryb-nocny').addEventListener('click', () => {
  const noc = document.documentElement.dataset.tryb === 'noc';
  document.documentElement.dataset.tryb = noc ? '' : 'noc';
  localStorage.setItem('sqlmon_tryb', noc ? '' : 'noc');
  if (ostatnie && ostatnie.ok) narysuj(ostatnie);
});

window.addEventListener('resize', () => { if (ostatnie && ostatnie.ok) narysuj(ostatnie); });

/* ---------- start ---------- */

document.documentElement.dataset.tryb = localStorage.getItem('sqlmon_tryb') || '';
const zapisane = localStorage.getItem('sqlmon_odswiezanie');
const sek = zapisane !== null ? Number(zapisane) : ODSWIEZANIE_START;
document.getElementById('wybor-odswiezania').value = String(sek);
if (sek) interwal = setInterval(wczytaj, sek * 1000);

wczytaj();
})();
</script>

<?php endif; ?>
</body>
</html>
