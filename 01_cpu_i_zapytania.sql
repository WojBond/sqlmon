/*==============================================================================
  01 – OBCIĄŻENIE CPU
  Historia CPU, rozkład na bazy danych, najcięższe zapytania i procedury.
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 1. Historia CPU – 256 minut */
DECLARE @ts_now BIGINT = (SELECT cpu_ticks / (cpu_ticks / ms_ticks) FROM sys.dm_os_sys_info);

SELECT TOP (256)
    DATEADD(ms, -1 * (@ts_now - rb.[timestamp]), GETDATE())  AS [Czas],
    rb.SQLProcessUtilization                                 AS [CPU SQL %],
    100 - rb.SystemIdle - rb.SQLProcessUtilization           AS [CPU inne %],
    rb.SystemIdle                                            AS [Bezczynny %],
    REPLICATE('#', rb.SQLProcessUtilization / 2)             AS [Wykres CPU SQL]
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

/*------------------------------------------------------------------ 2. Presja na schedulery (kolejka runnable) */
SELECT
    s.scheduler_id                       AS [Scheduler],
    s.cpu_id                             AS [CPU],
    s.current_tasks_count                AS [Zadania biezace],
    s.runnable_tasks_count               AS [W kolejce runnable],
    s.pending_disk_io_count              AS [Oczekujace IO],
    s.work_queue_count                   AS [Kolejka pracy],
    s.load_factor                        AS [Load factor]
FROM sys.dm_os_schedulers AS s
WHERE s.status = N'VISIBLE ONLINE'
ORDER BY s.runnable_tasks_count DESC, s.scheduler_id;
-- Stale > 0 w kolumnie "W kolejce runnable" = brakuje CPU (objaw: waity SOS_SCHEDULER_YIELD).

/*------------------------------------------------------------------ 3. Rozkład CPU wg baz danych (z cache planów) */
WITH CPU_baz AS (
    SELECT
        pa.value                                   AS database_id,
        SUM(qs.total_worker_time / 1000)           AS CPU_ms
    FROM sys.dm_exec_query_stats AS qs
    CROSS APPLY sys.dm_exec_plan_attributes(qs.plan_handle) AS pa
    WHERE pa.attribute = 'dbid'
    GROUP BY pa.value
)
SELECT
    ISNULL(DB_NAME(CAST(database_id AS INT)), N'Adhoc / systemowe') AS [Baza],
    CPU_ms                                                         AS [CPU ms],
    CAST(100.0 * CPU_ms / NULLIF(SUM(CPU_ms) OVER (), 0) AS DECIMAL(5,2)) AS [% CPU]
FROM CPU_baz
ORDER BY CPU_ms DESC;

/*------------------------------------------------------------------ 4. TOP 25 zapytań wg całkowitego CPU */
SELECT TOP (25)
    DB_NAME(qt.dbid)                                            AS [Baza],
    OBJECT_NAME(qt.objectid, qt.dbid)                           AS [Obiekt],
    qs.execution_count                                          AS [Wykonania],
    qs.total_worker_time / 1000                                 AS [CPU razem ms],
    qs.total_worker_time / NULLIF(qs.execution_count,0) / 1000  AS [CPU srednio ms],
    qs.total_elapsed_time / NULLIF(qs.execution_count,0) / 1000 AS [Czas srednio ms],
    qs.total_logical_reads / NULLIF(qs.execution_count,0)       AS [Odczyty log. srednio],
    qs.total_rows / NULLIF(qs.execution_count,0)                AS [Wiersze srednio],
    qs.last_execution_time                                      AS [Ostatnie wykonanie],
    SUBSTRING(qt.text, (qs.statement_start_offset / 2) + 1,
        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1) AS [Zapytanie],
    qp.query_plan                                               AS [Plan]
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle)   AS qt
CROSS APPLY sys.dm_exec_query_plan(qs.plan_handle) AS qp
ORDER BY qs.total_worker_time DESC;

/*------------------------------------------------------------------ 5. TOP 25 zapytań wg średniego CPU (min. 10 wykonań) */
SELECT TOP (25)
    DB_NAME(qt.dbid)                                            AS [Baza],
    qs.execution_count                                          AS [Wykonania],
    qs.total_worker_time / NULLIF(qs.execution_count,0) / 1000  AS [CPU srednio ms],
    qs.max_worker_time / 1000                                   AS [CPU max ms],
    qs.total_elapsed_time / NULLIF(qs.execution_count,0) / 1000 AS [Czas srednio ms],
    CASE WHEN qs.total_worker_time > qs.total_elapsed_time THEN 'TAK' ELSE 'nie' END AS [Rownolegle],
    SUBSTRING(qt.text, (qs.statement_start_offset / 2) + 1,
        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1) AS [Zapytanie],
    qp.query_plan                                               AS [Plan]
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle)    AS qt
CROSS APPLY sys.dm_exec_query_plan(qs.plan_handle) AS qp
WHERE qs.execution_count >= 10
ORDER BY qs.total_worker_time / qs.execution_count DESC;

/*------------------------------------------------------------------ 6. TOP 20 procedur składowanych wg CPU */
SELECT TOP (20)
    DB_NAME(ps.database_id)                                     AS [Baza],
    OBJECT_NAME(ps.object_id, ps.database_id)                   AS [Procedura],
    ps.execution_count                                          AS [Wykonania],
    ps.total_worker_time / 1000                                 AS [CPU razem ms],
    ps.total_worker_time / NULLIF(ps.execution_count,0) / 1000  AS [CPU srednio ms],
    ps.total_elapsed_time / NULLIF(ps.execution_count,0) / 1000 AS [Czas srednio ms],
    ps.total_logical_reads / NULLIF(ps.execution_count,0)       AS [Odczyty srednio],
    ps.cached_time                                              AS [Plan w cache od],
    ps.last_execution_time                                      AS [Ostatnie wykonanie]
FROM sys.dm_exec_procedure_stats AS ps
ORDER BY ps.total_worker_time DESC;

/*------------------------------------------------------------------ 7. Cache planów – marnotrawstwo (single-use adhoc) */
SELECT
    objtype                                              AS [Typ planu],
    COUNT(*)                                             AS [Liczba planow],
    SUM(CASE WHEN usecounts = 1 THEN 1 ELSE 0 END)       AS [Plany jednorazowe],
    CAST(SUM(CAST(size_in_bytes AS BIGINT)) / 1024.0 / 1024 AS DECIMAL(18,1)) AS [Rozmiar MB],
    CAST(SUM(CASE WHEN usecounts = 1 THEN CAST(size_in_bytes AS BIGINT) ELSE 0 END)
         / 1024.0 / 1024 AS DECIMAL(18,1))               AS [Zmarnowane MB]
FROM sys.dm_exec_cached_plans
GROUP BY objtype
ORDER BY [Zmarnowane MB] DESC;
-- Duży udział "Adhoc" jednorazowych => rozważ "optimize for ad hoc workloads" = 1.
