/*==============================================================================
  08 – KOLEKTOR HISTORII METRYK
  Tworzy bazę DBA_Monitoring, tabele i procedurę zbierającą snapshoty metryk.
  Uruchamiana co minutę przez SQL Server Agent daje wykresy obciążenia w czasie.
  URUCHOM JEDNORAZOWO na monitorowanej instancji.
==============================================================================*/
SET NOCOUNT ON;

/*------------------------------------------------------------------ 1. Baza */
IF DB_ID('DBA_Monitoring') IS NULL
BEGIN
    CREATE DATABASE DBA_Monitoring;
    ALTER DATABASE DBA_Monitoring SET RECOVERY SIMPLE;
END
GO

USE DBA_Monitoring;
GO

/*------------------------------------------------------------------ 2. Tabele */
IF OBJECT_ID('dbo.Snapshot_Serwer') IS NULL
CREATE TABLE dbo.Snapshot_Serwer
(
    id                  BIGINT IDENTITY(1,1) PRIMARY KEY,
    czas                DATETIME2(0)   NOT NULL DEFAULT SYSDATETIME(),
    cpu_sql_proc        INT            NULL,
    cpu_inne_proc       INT            NULL,
    ple_sek             BIGINT         NULL,
    pamiec_uzyta_mb     INT            NULL,
    pamiec_wolna_os_mb  INT            NULL,
    sesje_uzytkownikow  INT            NULL,
    aktywne_zapytania   INT            NULL,
    zablokowane_sesje   INT            NULL,
    zadania_oczekujace  INT            NULL,
    runnable_tasks      INT            NULL,
    batch_req_sec       DECIMAL(18,2)  NULL,
    transakcje_sec      DECIMAL(18,2)  NULL,
    compilations_sec    DECIMAL(18,2)  NULL,
    tempdb_uzyte_mb     DECIMAL(18,1)  NULL,
    granty_oczekujace   INT            NULL,
    najdluzsza_transakcja_sek INT      NULL,
    najdluzsze_zapytanie_sek  INT      NULL
);
GO

-- Dla instalacji sprzed v9.1.0 — dołożenie kolumny bez utraty historii.
IF COL_LENGTH('dbo.Snapshot_Serwer', 'najdluzsze_zapytanie_sek') IS NULL
    ALTER TABLE dbo.Snapshot_Serwer ADD najdluzsze_zapytanie_sek INT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Snapshot_Serwer_czas')
    CREATE INDEX IX_Snapshot_Serwer_czas ON dbo.Snapshot_Serwer(czas);
GO

IF OBJECT_ID('dbo.Snapshot_Waity') IS NULL
CREATE TABLE dbo.Snapshot_Waity
(
    id            BIGINT IDENTITY(1,1) PRIMARY KEY,
    czas          DATETIME2(0)   NOT NULL DEFAULT SYSDATETIME(),
    wait_type     NVARCHAR(60)   NOT NULL,
    wait_time_ms  BIGINT         NOT NULL,   -- narastająco
    tasks_count   BIGINT         NOT NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Snapshot_Waity_czas')
    CREATE INDEX IX_Snapshot_Waity_czas ON dbo.Snapshot_Waity(czas, wait_type);
GO

IF OBJECT_ID('dbo.Snapshot_IO') IS NULL
CREATE TABLE dbo.Snapshot_IO
(
    id                BIGINT IDENTITY(1,1) PRIMARY KEY,
    czas              DATETIME2(0)  NOT NULL DEFAULT SYSDATETIME(),
    baza              SYSNAME       NULL,
    plik              SYSNAME       NULL,
    typ_pliku         CHAR(4)       NULL,
    num_reads         BIGINT        NULL,
    num_writes        BIGINT        NULL,
    io_stall_read_ms  BIGINT        NULL,
    io_stall_write_ms BIGINT        NULL
);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Snapshot_IO_czas')
    CREATE INDEX IX_Snapshot_IO_czas ON dbo.Snapshot_IO(czas);
GO

/*------------------------------------------------------------------ 3. Procedura zbierająca */
CREATE OR ALTER PROCEDURE dbo.usp_ZbierzMetryki
    @retencja_dni INT = 30
AS
BEGIN
    SET NOCOUNT ON;
    SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

    DECLARE @ts_now BIGINT = (SELECT cpu_ticks / (cpu_ticks / ms_ticks) FROM sys.dm_os_sys_info);
    DECLARE @cpu_sql INT, @cpu_inne INT;

    SELECT TOP (1)
        @cpu_sql  = rb.SQLProcessUtilization,
        @cpu_inne = 100 - rb.SystemIdle - rb.SQLProcessUtilization
    FROM (
        SELECT
            x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/SystemIdle)[1]','int')         AS SystemIdle,
            x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/ProcessUtilization)[1]','int') AS SQLProcessUtilization,
            x.record.value('(./Record/@id)[1]','int')                                                   AS record_id
        FROM (
            SELECT CONVERT(XML, record) AS record
            FROM sys.dm_os_ring_buffers
            WHERE ring_buffer_type = N'RING_BUFFER_SCHEDULER_MONITOR'
              AND record LIKE N'%<SystemHealth>%'
        ) AS x
    ) AS rb
    ORDER BY rb.record_id DESC;

    INSERT INTO dbo.Snapshot_Serwer
    (
        cpu_sql_proc, cpu_inne_proc, ple_sek, pamiec_uzyta_mb, pamiec_wolna_os_mb,
        sesje_uzytkownikow, aktywne_zapytania, zablokowane_sesje, zadania_oczekujace,
        runnable_tasks, batch_req_sec, transakcje_sec, compilations_sec,
        tempdb_uzyte_mb, granty_oczekujace, najdluzsza_transakcja_sek,
        najdluzsze_zapytanie_sek
    )
    SELECT
        @cpu_sql,
        @cpu_inne,
        -- PLE odporne na NUMA: minimum z węzłów, a gdy ich nie ma – Buffer Manager.
        ISNULL(
          (SELECT MIN(cntr_value) FROM sys.dm_os_performance_counters
            WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Node%'),
          (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters
            WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Manager%')
        ),
        (SELECT physical_memory_in_use_kb / 1024 FROM sys.dm_os_process_memory),
        (SELECT available_physical_memory_kb / 1024 FROM sys.dm_os_sys_memory),
        (SELECT COUNT(*) FROM sys.dm_exec_sessions WHERE is_user_process = 1),
        -- Tylko sesje użytkowników (było: session_id > 50).
        (SELECT COUNT(*) FROM sys.dm_exec_requests AS r
           JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
          WHERE s.is_user_process = 1),
        -- Bez samoblokad wątków równoległych.
        (SELECT COUNT(*) FROM sys.dm_exec_requests
          WHERE blocking_session_id <> 0 AND blocking_session_id <> session_id),
        (SELECT COUNT(*) FROM sys.dm_os_waiting_tasks AS w
           JOIN sys.dm_exec_sessions AS s ON s.session_id = w.session_id
          WHERE s.is_user_process = 1),
        (SELECT SUM(runnable_tasks_count) FROM sys.dm_os_schedulers WHERE status = N'VISIBLE ONLINE'),
        (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters WHERE counter_name = 'Batch Requests/sec'),
        (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters
          WHERE counter_name = 'Transactions/sec' AND instance_name = '_Total'),
        (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters WHERE counter_name = 'SQL Compilations/sec'),
        (SELECT CAST(SUM(user_object_reserved_page_count + internal_object_reserved_page_count
                         + version_store_reserved_page_count) * 8.0 / 1024 AS DECIMAL(18,1))
           FROM tempdb.sys.dm_db_file_space_usage),
        (SELECT COUNT(*) FROM sys.dm_exec_query_memory_grants WHERE grant_time IS NULL),
        (SELECT ISNULL(MAX(DATEDIFF(SECOND, transaction_begin_time, GETDATE())), 0)
           FROM sys.dm_tran_active_transactions AS t
           JOIN sys.dm_tran_session_transactions AS st ON st.transaction_id = t.transaction_id
           JOIN sys.dm_exec_sessions AS s ON s.session_id = st.session_id
          WHERE s.is_user_process = 1),
        -- Najdłuższe realnie działające zapytanie użytkownika. Poza statusami
        -- background/sleeping trzeba wyciąć sesje zaparkowane na wiecznym
        -- oczekiwaniu (Service Broker, WAITFOR, replikacja, CDC) — mają status
        -- running/suspended i is_user_process = 1, a żyją od startu instancji.
        -- Limit dobowy: realne zapytanie ERP nigdy tyle nie trwa.
        (SELECT ISNULL(MAX(r.total_elapsed_time), 0) / 1000
           FROM sys.dm_exec_requests AS r
           JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
          WHERE s.is_user_process = 1
            AND r.session_id <> @@SPID
            AND r.status NOT IN ('background', 'sleeping')
            AND r.total_elapsed_time < 86400000
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
                  'REPLICATION LOG READER', 'CDC CAPTURE'));

    INSERT INTO dbo.Snapshot_Waity (wait_type, wait_time_ms, tasks_count)
    SELECT wait_type, wait_time_ms, waiting_tasks_count
    FROM sys.dm_os_wait_stats
    WHERE waiting_tasks_count > 0
      AND wait_type NOT LIKE 'SLEEP%'
      AND wait_type NOT LIKE 'BROKER%'
      AND wait_type NOT LIKE 'XE[_]%'
      AND wait_type NOT LIKE 'QDS[_]%'
      AND wait_type NOT LIKE 'HADR[_]%'
      AND wait_type NOT LIKE 'PREEMPTIVE[_]XE%'
      AND wait_type NOT LIKE 'SQLTRACE[_]%'
      AND wait_type NOT LIKE 'CLR[_]%'
      AND wait_type NOT LIKE 'FT[_]%'
      AND wait_type NOT LIKE 'PWAIT[_]%'
      AND wait_type NOT LIKE 'DBMIRROR%'
      AND wait_type NOT LIKE 'PARALLEL[_]REDO%'
      AND wait_type NOT LIKE 'SP[_]SERVER%'
      AND wait_type NOT IN (N'WAITFOR', N'LAZYWRITER_SLEEP', N'LOGMGR_QUEUE', N'CHECKPOINT_QUEUE',
            N'REQUEST_FOR_DEADLOCK_SEARCH', N'DIRTY_PAGE_POLL',
            N'DISPATCHER_QUEUE_SEMAPHORE', N'ONDEMAND_TASK_QUEUE', N'SERVER_IDLE_CHECK',
            N'RESOURCE_QUEUE', N'MEMORY_ALLOCATION_EXT', N'KSOURCE_WAKEUP',
            N'SOS_WORK_DISPATCHER', N'EXECSYNC', N'VDI_CLIENT_OTHER',
            N'WAIT_XTP_HOST_WAIT', N'WAIT_XTP_OFFLINE_CKPT_NEW_LOG', N'WAIT_XTP_CKPT_CLOSE',
            N'STARTUP_DEPENDENCY_MANAGER', N'POPULATE_LOCK_ORDINALS',
            N'PERSISTENT_VERSION_STORE_CLEANUP', N'VERSION_CLEANUP_TASK');

    INSERT INTO dbo.Snapshot_IO (baza, plik, typ_pliku, num_reads, num_writes, io_stall_read_ms, io_stall_write_ms)
    SELECT
        DB_NAME(vfs.database_id), mf.name,
        CASE mf.type WHEN 0 THEN 'DANE' WHEN 1 THEN 'LOG' ELSE 'INNE' END,
        vfs.num_of_reads, vfs.num_of_writes, vfs.io_stall_read_ms, vfs.io_stall_write_ms
    FROM sys.dm_io_virtual_file_stats(NULL, NULL) AS vfs
    JOIN sys.master_files AS mf ON mf.database_id = vfs.database_id AND mf.file_id = vfs.file_id;

    -- Retencja
    DELETE FROM dbo.Snapshot_Serwer WHERE czas < DATEADD(DAY, -@retencja_dni, SYSDATETIME());
    DELETE FROM dbo.Snapshot_Waity  WHERE czas < DATEADD(DAY, -@retencja_dni, SYSDATETIME());
    DELETE FROM dbo.Snapshot_IO     WHERE czas < DATEADD(DAY, -@retencja_dni, SYSDATETIME());
END
GO

/*------------------------------------------------------------------ 4. Widoki raportowe */
CREATE OR ALTER VIEW dbo.vw_ObciazenieGodzinowe
AS
SELECT
    CAST(czas AS DATE)                                  AS data,
    DATEPART(HOUR, czas)                                AS godzina,
    AVG(cpu_sql_proc)                                   AS cpu_sql_srednio,
    MAX(cpu_sql_proc)                                   AS cpu_sql_max,
    AVG(ple_sek)                                        AS ple_srednio,
    MIN(ple_sek)                                        AS ple_min,
    AVG(sesje_uzytkownikow)                             AS sesje_srednio,
    MAX(aktywne_zapytania)                              AS zapytania_max,
    MAX(zablokowane_sesje)                              AS blokady_max,
    MAX(runnable_tasks)                                 AS runnable_max,
    AVG(tempdb_uzyte_mb)                                AS tempdb_srednio_mb,
    MAX(najdluzsza_transakcja_sek)                      AS najdluzsza_transakcja_sek,
    MAX(najdluzsze_zapytanie_sek)                       AS najdluzsze_zapytanie_sek
FROM dbo.Snapshot_Serwer
GROUP BY CAST(czas AS DATE), DATEPART(HOUR, czas);
GO

CREATE OR ALTER VIEW dbo.vw_WaityPrzyrostowo
AS
SELECT
    w.czas,
    w.wait_type,
    w.wait_time_ms - LAG(w.wait_time_ms) OVER (PARTITION BY w.wait_type ORDER BY w.czas) AS przyrost_ms,
    w.tasks_count  - LAG(w.tasks_count)  OVER (PARTITION BY w.wait_type ORDER BY w.czas) AS przyrost_zadan
FROM dbo.Snapshot_Waity AS w;
GO

CREATE OR ALTER VIEW dbo.vw_LatencjaIO
AS
SELECT
    i.czas, i.baza, i.plik, i.typ_pliku,
    CAST((i.io_stall_read_ms - LAG(i.io_stall_read_ms) OVER (PARTITION BY i.baza, i.plik ORDER BY i.czas)) * 1.0
         / NULLIF(i.num_reads - LAG(i.num_reads) OVER (PARTITION BY i.baza, i.plik ORDER BY i.czas), 0)
         AS DECIMAL(18,1)) AS latencja_odczytu_ms,
    CAST((i.io_stall_write_ms - LAG(i.io_stall_write_ms) OVER (PARTITION BY i.baza, i.plik ORDER BY i.czas)) * 1.0
         / NULLIF(i.num_writes - LAG(i.num_writes) OVER (PARTITION BY i.baza, i.plik ORDER BY i.czas), 0)
         AS DECIMAL(18,1)) AS latencja_zapisu_ms
FROM dbo.Snapshot_IO AS i;
GO

/*------------------------------------------------------------------ 5. Zadanie SQL Server Agent (co 1 minutę) */
USE msdb;
GO
IF EXISTS (SELECT 1 FROM msdb.dbo.sysjobs WHERE name = N'DBA - Zbieranie metryk wydajnosci')
    EXEC msdb.dbo.sp_delete_job @job_name = N'DBA - Zbieranie metryk wydajnosci', @delete_unused_schedule = 1;
GO

EXEC msdb.dbo.sp_add_job
     @job_name = N'DBA - Zbieranie metryk wydajnosci',
     @description = N'Snapshot metryk obciazenia do bazy DBA_Monitoring',
     @enabled = 1;

EXEC msdb.dbo.sp_add_jobstep
     @job_name   = N'DBA - Zbieranie metryk wydajnosci',
     @step_name  = N'Zbierz metryki',
     @subsystem  = N'TSQL',
     @database_name = N'DBA_Monitoring',
     @command    = N'EXEC dbo.usp_ZbierzMetryki @retencja_dni = 30;',
     @retry_attempts = 1,
     @retry_interval = 1;

EXEC msdb.dbo.sp_add_schedule
     @schedule_name  = N'Co minute 24/7',
     @freq_type      = 4,          -- codziennie
     @freq_interval  = 1,
     @freq_subday_type     = 4,    -- co N minut
     @freq_subday_interval = 1,
     @active_start_time    = 000000,
     @active_end_time      = 235959;

EXEC msdb.dbo.sp_attach_schedule
     @job_name      = N'DBA - Zbieranie metryk wydajnosci',
     @schedule_name = N'Co minute 24/7';

EXEC msdb.dbo.sp_add_jobserver
     @job_name  = N'DBA - Zbieranie metryk wydajnosci',
     @server_name = N'(local)';
GO

/*------------------------------------------------------------------ 6. Przykładowe raporty z historii */
/*
USE DBA_Monitoring;

-- Profil dobowy obciążenia
SELECT * FROM dbo.vw_ObciazenieGodzinowe ORDER BY data DESC, godzina;

-- Ostatnie 2 godziny minuta po minucie
SELECT czas, cpu_sql_proc, cpu_inne_proc, ple_sek, sesje_uzytkownikow,
       aktywne_zapytania, zablokowane_sesje, runnable_tasks, tempdb_uzyte_mb
FROM dbo.Snapshot_Serwer
WHERE czas > DATEADD(HOUR, -2, SYSDATETIME())
ORDER BY czas;

-- Szczyty CPU
SELECT TOP 50 czas, cpu_sql_proc, cpu_inne_proc, aktywne_zapytania, zablokowane_sesje
FROM dbo.Snapshot_Serwer
ORDER BY cpu_sql_proc DESC;

-- Dominujące oczekiwania w wybranym oknie
SELECT wait_type, SUM(przyrost_ms) AS ms_razem, SUM(przyrost_zadan) AS liczba
FROM dbo.vw_WaityPrzyrostowo
WHERE czas BETWEEN '2026-08-07 08:00' AND '2026-08-07 12:00'
  AND przyrost_ms > 0
GROUP BY wait_type
ORDER BY ms_razem DESC;

-- Latencja dysków w czasie
SELECT czas, baza, plik, typ_pliku, latencja_odczytu_ms, latencja_zapisu_ms
FROM dbo.vw_LatencjaIO
WHERE czas > DATEADD(HOUR, -6, SYSDATETIME())
  AND (latencja_odczytu_ms > 20 OR latencja_zapisu_ms > 10)
ORDER BY czas DESC;
*/
