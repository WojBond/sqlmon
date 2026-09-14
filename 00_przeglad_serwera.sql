/*==============================================================================
  00 – PRZEGLĄD SERWERA (dashboard główny)
  Uruchom w SSMS 22 w trybie "Results to Grid" (Ctrl+D).
  Zwraca 6 zestawów wyników – szybki obraz kondycji instancji.
  Wymagania: VIEW SERVER STATE. Zgodne z SQL Server 2016+ (2019/2022/2025).
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 1. Instancja */
SELECT
    SERVERPROPERTY('MachineName')                          AS [Serwer],
    SERVERPROPERTY('InstanceName')                         AS [Instancja],
    SERVERPROPERTY('ProductVersion')                       AS [Wersja],
    SERVERPROPERTY('ProductLevel')                         AS [Poziom],
    SERVERPROPERTY('Edition')                              AS [Edycja],
    si.cpu_count                                           AS [Logiczne CPU],
    si.cpu_count / NULLIF(si.hyperthread_ratio,0)          AS [Sockety],
    si.physical_memory_kb / 1024                           AS [RAM serwera MB],
    si.sqlserver_start_time                                AS [Start SQL],
    DATEDIFF(HOUR, si.sqlserver_start_time, GETDATE())     AS [Uptime godz],
    si.committed_target_kb / 1024                          AS [Target pamieci SQL MB]
FROM sys.dm_os_sys_info AS si;

/*------------------------------------------------------------------ 2. CPU – ostatnie 60 min */
DECLARE @ts_now BIGINT = (SELECT cpu_ticks / (cpu_ticks / ms_ticks) FROM sys.dm_os_sys_info);

SELECT TOP (60)
    DATEADD(ms, -1 * (@ts_now - rb.[timestamp]), GETDATE())      AS [Czas],
    rb.SQLProcessUtilization                                     AS [CPU SQL %],
    100 - rb.SystemIdle - rb.SQLProcessUtilization               AS [CPU inne procesy %],
    rb.SystemIdle                                                AS [Bezczynny %]
FROM (
    SELECT
        x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/SystemIdle)[1]','int')         AS SystemIdle,
        x.record.value('(./Record/SchedulerMonitorEvent/SystemHealth/ProcessUtilization)[1]','int') AS SQLProcessUtilization,
        x.record.value('(./Record/@id)[1]','int')                                                   AS record_id,
        x.[timestamp]
    FROM (
        SELECT [timestamp], CONVERT(XML, record) AS record
        FROM sys.dm_os_ring_buffers
        WHERE ring_buffer_type = N'RING_BUFFER_SCHEDULER_MONITOR'
          AND record LIKE N'%<SystemHealth>%'
    ) AS x
) AS rb
ORDER BY rb.record_id DESC;

/*------------------------------------------------------------------ 3. Pamięć */
SELECT
    (SELECT physical_memory_in_use_kb / 1024 FROM sys.dm_os_process_memory)          AS [SQL uzywa MB],
    (SELECT available_physical_memory_kb / 1024 FROM sys.dm_os_sys_memory)           AS [Wolny RAM systemu MB],
    (SELECT system_memory_state_desc FROM sys.dm_os_sys_memory)                      AS [Stan pamieci OS],
    -- Na serwerach NUMA bierzemy minimum z wezlow (decyduje najgorszy wezel).
    ISNULL(
      (SELECT MIN(cntr_value) FROM sys.dm_os_performance_counters
        WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Node%'),
      (SELECT TOP (1) cntr_value FROM sys.dm_os_performance_counters
        WHERE counter_name = 'Page life expectancy' AND object_name LIKE '%Buffer Manager%')
    )                                                                                AS [PLE sek],
    (SELECT COUNT(*) FROM sys.dm_exec_query_memory_grants WHERE grant_time IS NULL)  AS [Oczekuje na grant pamieci],
    (SELECT CAST(value_in_use AS INT) FROM sys.configurations
      WHERE name = 'max server memory (MB)')                                         AS [Max server memory MB];

/*------------------------------------------------------------------ 4. Sesje i przepustowość */
SELECT
    (SELECT COUNT(*) FROM sys.dm_exec_sessions WHERE is_user_process = 1)                       AS [Sesje uzytkownikow],
    (SELECT COUNT(*) FROM sys.dm_exec_requests AS r
       JOIN sys.dm_exec_sessions AS se ON se.session_id = r.session_id
      WHERE se.is_user_process = 1)                                                            AS [Aktywne zapytania],
    (SELECT COUNT(*) FROM sys.dm_exec_requests
      WHERE blocking_session_id <> 0 AND blocking_session_id <> session_id)                    AS [Zablokowane],
    (SELECT COUNT(*) FROM sys.dm_os_waiting_tasks AS wt
       JOIN sys.dm_exec_sessions AS se ON se.session_id = wt.session_id
      WHERE se.is_user_process = 1)                                                            AS [Zadania w oczekiwaniu],
    (SELECT cntr_value FROM sys.dm_os_performance_counters
      WHERE counter_name = 'Batch Requests/sec')                                               AS [Batch req (licznik narastajacy)],
    (SELECT cntr_value FROM sys.dm_os_performance_counters
      WHERE counter_name = 'Transactions/sec' AND instance_name = '_Total')                    AS [Transakcje (licznik narastajacy)];

/*------------------------------------------------------------------ 5. TOP 10 oczekiwań od startu */
WITH Waity AS (
    SELECT
        wait_type,
        wait_time_ms / 1000.0                                        AS Sek_calkowite,
        (wait_time_ms - signal_wait_time_ms) / 1000.0                AS Sek_zasob,
        signal_wait_time_ms / 1000.0                                 AS Sek_sygnal,
        waiting_tasks_count                                          AS Liczba,
        100.0 * wait_time_ms / NULLIF(SUM(wait_time_ms) OVER (), 0)  AS Procent
    FROM sys.dm_os_wait_stats
    WHERE waiting_tasks_count > 0
      AND wait_type NOT IN (
        N'BROKER_EVENTHANDLER', N'BROKER_RECEIVE_WAITFOR', N'BROKER_TASK_STOP',
        N'BROKER_TO_FLUSH', N'BROKER_TRANSMITTER', N'CHECKPOINT_QUEUE',
        N'CHKPT', N'CLR_AUTO_EVENT', N'CLR_MANUAL_EVENT', N'CLR_SEMAPHORE',
        N'DBMIRROR_DBM_EVENT', N'DBMIRROR_EVENTS_QUEUE', N'DBMIRROR_WORKER_QUEUE',
        N'DBMIRRORING_CMD', N'DIRTY_PAGE_POLL', N'DISPATCHER_QUEUE_SEMAPHORE',
        N'EXECSYNC', N'FSAGENT', N'FT_IFTS_SCHEDULER_IDLE_WAIT', N'FT_IFTSHC_MUTEX',
        N'HADR_CLUSAPI_CALL', N'HADR_FILESTREAM_IOMGR_IOCOMPLETION', N'HADR_LOGCAPTURE_WAIT',
        N'HADR_NOTIFICATION_DEQUEUE', N'HADR_TIMER_TASK', N'HADR_WORK_QUEUE',
        N'KSOURCE_WAKEUP', N'LAZYWRITER_SLEEP', N'LOGMGR_QUEUE', N'MEMORY_ALLOCATION_EXT',
        N'ONDEMAND_TASK_QUEUE', N'PARALLEL_REDO_DRAIN_WORKER', N'PARALLEL_REDO_WORKER_WAIT_WORK',
        N'PREEMPTIVE_XE_GETTARGETSTATE', N'PWAIT_ALL_COMPONENTS_INITIALIZED',
        N'PWAIT_DIRECTLOGCONSUMER_GETNEXT', N'QDS_ASYNC_QUEUE', N'QDS_CLEANUP_STALE_QUERIES_TASK_MAIN_LOOP_SLEEP',
        N'QDS_PERSIST_TASK_MAIN_LOOP_SLEEP', N'QDS_SHUTDOWN_QUEUE', N'REDO_THREAD_PENDING_WORK',
        N'REQUEST_FOR_DEADLOCK_SEARCH', N'RESOURCE_QUEUE', N'SERVER_IDLE_CHECK',
        N'SLEEP_BPOOL_FLUSH', N'SLEEP_DBSTARTUP', N'SLEEP_DCOMSTARTUP', N'SLEEP_MASTERDBREADY',
        N'SLEEP_MASTERMDREADY', N'SLEEP_MASTERUPGRADED', N'SLEEP_MSDBSTARTUP',
        N'SLEEP_SYSTEMTASK', N'SLEEP_TASK', N'SLEEP_TEMPDBSTARTUP', N'SNI_HTTP_ACCEPT',
        N'SP_SERVER_DIAGNOSTICS_SLEEP', N'SQLTRACE_BUFFER_FLUSH', N'SQLTRACE_INCREMENTAL_FLUSH_SLEEP',
        N'SQLTRACE_WAIT_ENTRIES', N'VDI_CLIENT_OTHER', N'WAIT_FOR_RESULTS', N'WAITFOR',
        N'WAITFOR_TASKSHUTDOWN', N'WAIT_XTP_RECOVERY', N'WAIT_XTP_HOST_WAIT',
        N'WAIT_XTP_OFFLINE_CKPT_NEW_LOG', N'WAIT_XTP_CKPT_CLOSE', N'XE_DISPATCHER_JOIN',
        N'XE_DISPATCHER_WAIT', N'XE_TIMER_EVENT')
)
SELECT TOP (10)
    wait_type                              AS [Typ oczekiwania],
    CAST(Procent AS DECIMAL(5,2))          AS [% czasu],
    CAST(Sek_calkowite AS DECIMAL(18,1))   AS [Sekundy razem],
    CAST(Sek_zasob AS DECIMAL(18,1))       AS [Sekundy na zasob],
    CAST(Sek_sygnal AS DECIMAL(18,1))      AS [Sekundy sygnal CPU],
    Liczba                                 AS [Liczba oczekiwan],
    CAST(Sek_calkowite * 1000.0 / NULLIF(Liczba,0) AS DECIMAL(18,2)) AS [Srednio ms]
FROM Waity
ORDER BY Procent DESC;

/*------------------------------------------------------------------ 6. Bazy: rozmiar i model odzysku */
SELECT
    d.name                                                            AS [Baza],
    d.state_desc                                                      AS [Stan],
    d.recovery_model_desc                                             AS [Model odzysku],
    CAST(SUM(CASE WHEN mf.type = 0 THEN mf.size END) * 8.0 / 1024 AS DECIMAL(18,1)) AS [Dane MB],
    CAST(SUM(CASE WHEN mf.type = 1 THEN mf.size END) * 8.0 / 1024 AS DECIMAL(18,1)) AS [Log MB],
    d.log_reuse_wait_desc                                             AS [Blokada reuse logu],
    d.is_query_store_on                                               AS [Query Store],
    d.compatibility_level                                             AS [Poziom zgodnosci]
FROM sys.databases AS d
JOIN sys.master_files AS mf ON mf.database_id = d.database_id
GROUP BY d.name, d.state_desc, d.recovery_model_desc, d.log_reuse_wait_desc,
         d.is_query_store_on, d.compatibility_level
ORDER BY [Dane MB] DESC;
