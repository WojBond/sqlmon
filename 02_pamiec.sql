/*==============================================================================
  02 – PAMIĘĆ
  Wykorzystanie RAM, PLE, buffer pool, granty pamięci, konsumenci (clerks).
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 1. Bilans pamięci */
SELECT
    pm.physical_memory_in_use_kb / 1024                AS [SQL: pamiec w uzyciu MB],
    pm.large_page_allocations_kb / 1024                AS [Large pages MB],
    pm.locked_page_allocations_kb / 1024               AS [Locked pages MB],
    pm.page_fault_count                                AS [Page faults],
    pm.memory_utilization_percentage                   AS [% pamieci rezydentnej],
    pm.available_commit_limit_kb / 1024                AS [Limit commit MB],
    pm.process_physical_memory_low                     AS [Sygnal: malo pamieci fiz],
    pm.process_virtual_memory_low                      AS [Sygnal: malo pamieci wirt],
    sm.total_physical_memory_kb / 1024                 AS [RAM serwera MB],
    sm.available_physical_memory_kb / 1024             AS [RAM wolny MB],
    sm.system_memory_state_desc                        AS [Stan pamieci OS]
FROM sys.dm_os_process_memory AS pm
CROSS JOIN sys.dm_os_sys_memory AS sm;

/*------------------------------------------------------------------ 2. Kluczowe liczniki pamięci */
SELECT
    RTRIM(counter_name)   AS [Licznik],
    RTRIM(instance_name)  AS [Instancja],
    cntr_value            AS [Wartosc]
FROM sys.dm_os_performance_counters
WHERE counter_name IN (
        'Page life expectancy', 'Buffer cache hit ratio', 'Free list stalls/sec',
        'Lazy writes/sec', 'Checkpoint pages/sec', 'Memory Grants Pending',
        'Memory Grants Outstanding', 'Target Server Memory (KB)',
        'Total Server Memory (KB)', 'Stolen Server Memory (KB)',
        'Database Cache Memory (KB)', 'Free Memory (KB)')
ORDER BY [Licznik], [Instancja];
-- PLE: nie ma jednej "magicznej" wartości 300. Punkt odniesienia:
-- ok. 300 s na każde 4 GB buffer poola, a przede wszystkim – stabilność w czasie.

/*------------------------------------------------------------------ 3. PLE per węzeł NUMA */
SELECT
    RTRIM(instance_name)  AS [Wezel NUMA],
    cntr_value            AS [PLE sek]
FROM sys.dm_os_performance_counters
WHERE counter_name = 'Page life expectancy'
ORDER BY [Wezel NUMA];

/*------------------------------------------------------------------ 4. Buffer pool wg baz danych */
SELECT
    ISNULL(DB_NAME(bd.database_id), N'Resource DB')                       AS [Baza],
    COUNT(*)                                                              AS [Strony],
    CAST(COUNT(*) * 8.0 / 1024 AS DECIMAL(18,1))                          AS [Cache MB],
    CAST(100.0 * COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (), 0) AS DECIMAL(5,2)) AS [% buffer poola],
    SUM(CASE WHEN bd.is_modified = 1 THEN 1 ELSE 0 END)                   AS [Strony brudne]
FROM sys.dm_os_buffer_descriptors AS bd
GROUP BY bd.database_id
ORDER BY [Strony] DESC;

/*------------------------------------------------------------------ 5. TOP 15 konsumentów pamięci (memory clerks) */
SELECT TOP (15)
    type                                                          AS [Typ clerk],
    CAST(SUM(pages_kb) / 1024.0 AS DECIMAL(18,1))                 AS [Pamiec MB],
    CAST(SUM(virtual_memory_reserved_kb) / 1024.0 AS DECIMAL(18,1)) AS [VM zarezerwowana MB],
    CAST(SUM(virtual_memory_committed_kb) / 1024.0 AS DECIMAL(18,1)) AS [VM commit MB]
FROM sys.dm_os_memory_clerks
GROUP BY type
ORDER BY SUM(pages_kb) DESC;

/*------------------------------------------------------------------ 6. Granty pamięci – aktualne i oczekujące */
SELECT
    mg.session_id                                  AS [Sesja],
    mg.request_time                                AS [Zgloszenie],
    mg.grant_time                                  AS [Przyznano],
    CASE WHEN mg.grant_time IS NULL THEN 'CZEKA' ELSE 'OK' END AS [Status],
    mg.requested_memory_kb / 1024                  AS [Zadana MB],
    mg.granted_memory_kb / 1024                    AS [Przyznana MB],
    mg.used_memory_kb / 1024                       AS [Uzyta MB],
    mg.max_used_memory_kb / 1024                   AS [Max uzyta MB],
    mg.ideal_memory_kb / 1024                      AS [Idealna MB],
    mg.query_cost                                  AS [Koszt planu],
    mg.dop                                         AS [DOP],
    qt.text                                        AS [Zapytanie]
FROM sys.dm_exec_query_memory_grants AS mg
OUTER APPLY sys.dm_exec_sql_text(mg.sql_handle) AS qt
ORDER BY mg.requested_memory_kb DESC;
-- Wiersze ze statusem CZEKA + wait RESOURCE_SEMAPHORE = klasyczny objaw braku pamięci
-- lub przeszacowanych grantów (nieaktualne statystyki, konwersje typów).

/*------------------------------------------------------------------ 7. Największe obiekty w cache (TOP 20) */
SELECT TOP (20)
    DB_NAME(bd.database_id)                                   AS [Baza],
    o.name                                                    AS [Obiekt],
    i.name                                                    AS [Indeks],
    i.type_desc                                               AS [Typ indeksu],
    CAST(COUNT(*) * 8.0 / 1024 AS DECIMAL(18,1))              AS [Cache MB]
FROM sys.dm_os_buffer_descriptors AS bd
JOIN sys.allocation_units AS au ON au.allocation_unit_id = bd.allocation_unit_id
JOIN sys.partitions AS p ON (au.type IN (1,3) AND au.container_id = p.hobt_id)
                          OR (au.type = 2 AND au.container_id = p.partition_id)
JOIN sys.objects AS o ON o.object_id = p.object_id
LEFT JOIN sys.indexes AS i ON i.object_id = p.object_id AND i.index_id = p.index_id
WHERE bd.database_id = DB_ID()          -- uruchom w kontekście badanej bazy!
  AND o.is_ms_shipped = 0
GROUP BY bd.database_id, o.name, i.name, i.type_desc
ORDER BY COUNT(*) DESC;
