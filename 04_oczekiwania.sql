/*==============================================================================
  04 – OCZEKIWANIA (WAIT STATS)
  Najważniejsza metryka diagnostyczna: na co czeka serwer.
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 0. Kiedy ostatnio zerowano statystyki */
SELECT
    sqlserver_start_time                                             AS [Start instancji],
    DATEDIFF(HOUR, sqlserver_start_time, GETDATE())                  AS [Uptime godz],
    (SELECT CAST(wait_time_ms / 1000.0 / 60 / 60 AS DECIMAL(18,1))
       FROM sys.dm_os_wait_stats WHERE wait_type = N'SLEEP_TASK')    AS [Wskaznik pomocniczy]
FROM sys.dm_os_sys_info;
-- Reset statystyk (tylko świadomie!):  DBCC SQLPERF('sys.dm_os_wait_stats', CLEAR);

/*------------------------------------------------------------------ 1. Oczekiwania narastające od startu – TOP 20 */
WITH Waity AS (
    SELECT
        wait_type,
        wait_time_ms,
        waiting_tasks_count,
        signal_wait_time_ms,
        wait_time_ms - signal_wait_time_ms AS resource_wait_ms
    FROM sys.dm_os_wait_stats
    WHERE waiting_tasks_count > 0
      AND wait_type NOT IN (
        N'BROKER_EVENTHANDLER', N'BROKER_RECEIVE_WAITFOR', N'BROKER_TASK_STOP',
        N'BROKER_TO_FLUSH', N'BROKER_TRANSMITTER', N'CHECKPOINT_QUEUE', N'CHKPT',
        N'CLR_AUTO_EVENT', N'CLR_MANUAL_EVENT', N'CLR_SEMAPHORE',
        N'DBMIRROR_DBM_EVENT', N'DBMIRROR_EVENTS_QUEUE', N'DBMIRROR_WORKER_QUEUE',
        N'DBMIRRORING_CMD', N'DIRTY_PAGE_POLL', N'DISPATCHER_QUEUE_SEMAPHORE',
        N'EXECSYNC', N'FSAGENT', N'FT_IFTS_SCHEDULER_IDLE_WAIT', N'FT_IFTSHC_MUTEX',
        N'HADR_CLUSAPI_CALL', N'HADR_FILESTREAM_IOMGR_IOCOMPLETION', N'HADR_LOGCAPTURE_WAIT',
        N'HADR_NOTIFICATION_DEQUEUE', N'HADR_TIMER_TASK', N'HADR_WORK_QUEUE',
        N'KSOURCE_WAKEUP', N'LAZYWRITER_SLEEP', N'LOGMGR_QUEUE', N'MEMORY_ALLOCATION_EXT',
        N'ONDEMAND_TASK_QUEUE', N'PARALLEL_REDO_DRAIN_WORKER', N'PARALLEL_REDO_WORKER_WAIT_WORK',
        N'PREEMPTIVE_XE_GETTARGETSTATE', N'PWAIT_ALL_COMPONENTS_INITIALIZED',
        N'PWAIT_DIRECTLOGCONSUMER_GETNEXT', N'QDS_ASYNC_QUEUE',
        N'QDS_CLEANUP_STALE_QUERIES_TASK_MAIN_LOOP_SLEEP', N'QDS_PERSIST_TASK_MAIN_LOOP_SLEEP',
        N'QDS_SHUTDOWN_QUEUE', N'REDO_THREAD_PENDING_WORK', N'REQUEST_FOR_DEADLOCK_SEARCH',
        N'RESOURCE_QUEUE', N'SERVER_IDLE_CHECK', N'SLEEP_BPOOL_FLUSH', N'SLEEP_DBSTARTUP',
        N'SLEEP_DCOMSTARTUP', N'SLEEP_MASTERDBREADY', N'SLEEP_MASTERMDREADY',
        N'SLEEP_MASTERUPGRADED', N'SLEEP_MSDBSTARTUP', N'SLEEP_SYSTEMTASK', N'SLEEP_TASK',
        N'SLEEP_TEMPDBSTARTUP', N'SNI_HTTP_ACCEPT', N'SP_SERVER_DIAGNOSTICS_SLEEP',
        N'SQLTRACE_BUFFER_FLUSH', N'SQLTRACE_INCREMENTAL_FLUSH_SLEEP', N'SQLTRACE_WAIT_ENTRIES',
        N'VDI_CLIENT_OTHER', N'WAIT_FOR_RESULTS', N'WAITFOR', N'WAITFOR_TASKSHUTDOWN',
        N'WAIT_XTP_RECOVERY', N'WAIT_XTP_HOST_WAIT', N'WAIT_XTP_OFFLINE_CKPT_NEW_LOG',
        N'WAIT_XTP_CKPT_CLOSE', N'XE_DISPATCHER_JOIN', N'XE_DISPATCHER_WAIT', N'XE_TIMER_EVENT',
        -- Dopisane w 9.2.0. SOS_WORK_DISPATCHER potrafi samodzielnie zajac ponad 80%
        -- calosci i calkowicie przyslonic realne sygnaly.
        N'SOS_WORK_DISPATCHER', N'PVS_PREALLOCATE', N'PERSISTENT_VERSION_STORE_CLEANUP',
        N'VERSION_CLEANUP_TASK', N'POPULATE_LOCK_ORDINALS', N'BAD_PAGE_PROCESS',
        N'STARTUP_DEPENDENCY_MANAGER')
)
SELECT TOP (20)
    wait_type                                                                  AS [Typ oczekiwania],
    CAST(100.0 * wait_time_ms / SUM(wait_time_ms) OVER () AS DECIMAL(5,2))     AS [% udzialu],
    CAST(wait_time_ms / 1000.0 AS DECIMAL(18,1))                               AS [Sekundy razem],
    CAST(resource_wait_ms / 1000.0 AS DECIMAL(18,1))                           AS [Sek. na zasob],
    CAST(signal_wait_time_ms / 1000.0 AS DECIMAL(18,1))                        AS [Sek. sygnal CPU],
    waiting_tasks_count                                                        AS [Liczba oczekiwan],
    CAST(wait_time_ms * 1.0 / NULLIF(waiting_tasks_count,0) AS DECIMAL(18,2))  AS [Srednio ms],
    CASE
      WHEN wait_type LIKE 'PAGEIOLATCH%'      THEN 'I/O odczyt stron z dysku – wolne dyski lub brak pamieci/indeksow'
      WHEN wait_type LIKE 'WRITELOG%'         THEN 'Zapis logu transakcyjnego – latencja dysku logu, za duzo malych transakcji'
      WHEN wait_type LIKE 'PAGELATCH%'        THEN 'Kontencja w pamieci – hot page, tempdb, wstawianie na koniec indeksu'
      WHEN wait_type LIKE 'LCK[_]%'           THEN 'Blokady – dlugie transakcje, brak indeksow, poziom izolacji'
      WHEN wait_type = 'SOS_SCHEDULER_YIELD'  THEN 'Presja CPU lub skanowanie duzych wolumenow danych'
      WHEN wait_type = 'CXPACKET'             THEN 'Rownoleglosc – sprawdz MAXDOP i Cost Threshold for Parallelism'
      WHEN wait_type = 'CXCONSUMER'           THEN 'Rownoleglosc (zwykle lagodne) – konsument czeka na producenta'
      WHEN wait_type = 'RESOURCE_SEMAPHORE'   THEN 'Brak pamieci na granty zapytan – zle oszacowania lub za malo RAM'
      WHEN wait_type LIKE 'THREADPOOL%'       THEN 'Brak workerow – blokady lub za duzo sesji'
      WHEN wait_type LIKE 'ASYNC_NETWORK_IO%' THEN 'Klient wolno odbiera wyniki – aplikacja, siec, zbyt duze zestawy'
      WHEN wait_type LIKE 'RESOURCE_GOVERNOR%' THEN 'Limity Resource Governora'
      WHEN wait_type LIKE 'BACKUPIO%'         THEN 'I/O backupu'
      WHEN wait_type LIKE 'IO_COMPLETION%'    THEN 'Operacje I/O inne niz strony danych (np. sortowanie do tempdb)'
      WHEN wait_type LIKE 'LOGBUFFER%'        THEN 'Bufor logu – bardzo intensywne zapisy'
      ELSE 'Sprawdz dokumentacje sys.dm_os_wait_stats'
    END                                                                        AS [Interpretacja]
FROM Waity
ORDER BY wait_time_ms DESC;

/*------------------------------------------------------------------ 2. Oczekiwania w oknie 30 sekund (pomiar różnicowy – NAJWAŻNIEJSZY) */
IF OBJECT_ID('tempdb..#w_start') IS NOT NULL DROP TABLE #w_start;
SELECT wait_type, wait_time_ms, waiting_tasks_count, signal_wait_time_ms
INTO #w_start FROM sys.dm_os_wait_stats;

WAITFOR DELAY '00:00:30';

WITH Delta AS (
    SELECT
        e.wait_type,
        e.wait_time_ms        - s.wait_time_ms        AS wait_ms,
        e.waiting_tasks_count - s.waiting_tasks_count AS tasks,
        e.signal_wait_time_ms - s.signal_wait_time_ms AS signal_ms
    FROM sys.dm_os_wait_stats AS e
    JOIN #w_start AS s ON s.wait_type = e.wait_type
    WHERE e.wait_time_ms - s.wait_time_ms > 0
      AND e.wait_type NOT LIKE 'SLEEP%'
      AND e.wait_type NOT LIKE 'BROKER%'
      AND e.wait_type NOT LIKE 'XE[_]%'
      AND e.wait_type NOT LIKE 'HADR[_]%'
      AND e.wait_type NOT LIKE 'QDS[_]%'
      AND e.wait_type NOT LIKE 'PREEMPTIVE[_]XE%'
      AND e.wait_type NOT IN (N'WAITFOR', N'LAZYWRITER_SLEEP', N'LOGMGR_QUEUE',
            N'CHECKPOINT_QUEUE', N'REQUEST_FOR_DEADLOCK_SEARCH', N'DIRTY_PAGE_POLL',
            N'CLR_AUTO_EVENT', N'CLR_MANUAL_EVENT', N'DISPATCHER_QUEUE_SEMAPHORE',
            N'FT_IFTS_SCHEDULER_IDLE_WAIT', N'ONDEMAND_TASK_QUEUE', N'SERVER_IDLE_CHECK',
            N'SP_SERVER_DIAGNOSTICS_SLEEP', N'RESOURCE_QUEUE', N'SQLTRACE_INCREMENTAL_FLUSH_SLEEP')
)
SELECT TOP (20)
    wait_type                                                        AS [Typ oczekiwania],
    CAST(100.0 * wait_ms / SUM(wait_ms) OVER () AS DECIMAL(5,2))     AS [% w oknie],
    wait_ms                                                          AS [ms w oknie 30s],
    tasks                                                            AS [Liczba],
    CAST(wait_ms * 1.0 / NULLIF(tasks,0) AS DECIMAL(18,2))           AS [Srednio ms],
    signal_ms                                                        AS [ms sygnal CPU]
FROM Delta
ORDER BY wait_ms DESC;

DROP TABLE #w_start;

/*------------------------------------------------------------------ 3. Kto czeka w tej chwili */
SELECT
    wt.session_id                     AS [Sesja],
    wt.wait_duration_ms               AS [Czeka ms],
    wt.wait_type                      AS [Typ oczekiwania],
    wt.blocking_session_id            AS [Blokuje ja sesja],
    wt.resource_description           AS [Zasob],
    s.login_name                      AS [Login],
    s.host_name                       AS [Host],
    s.program_name                    AS [Program],
    DB_NAME(r.database_id)            AS [Baza],
    r.command                         AS [Komenda],
    qt.text                           AS [Zapytanie]
FROM sys.dm_os_waiting_tasks AS wt
LEFT JOIN sys.dm_exec_sessions AS s ON s.session_id = wt.session_id
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = wt.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE wt.session_id > 50
ORDER BY wt.wait_duration_ms DESC;
