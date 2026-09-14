/*==============================================================================
  03 – DYSKI I I/O
  Opóźnienia per plik, najbardziej obciążające zapytania, aktywne żądania I/O.
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 1. Latencja per plik bazy (od startu instancji) */
SELECT
    DB_NAME(vfs.database_id)                                                   AS [Baza],
    mf.name                                                                    AS [Plik logiczny],
    CASE mf.type WHEN 0 THEN 'DANE' WHEN 1 THEN 'LOG' ELSE mf.type_desc END    AS [Typ],
    mf.physical_name                                                           AS [Sciezka],
    CAST(vfs.size_on_disk_bytes / 1024.0 / 1024 AS DECIMAL(18,1))              AS [Rozmiar MB],
    vfs.num_of_reads                                                           AS [Odczyty],
    vfs.num_of_writes                                                          AS [Zapisy],
    CAST(vfs.num_of_bytes_read / 1024.0 / 1024 AS DECIMAL(18,1))               AS [Odczytano MB],
    CAST(vfs.num_of_bytes_written / 1024.0 / 1024 AS DECIMAL(18,1))            AS [Zapisano MB],
    CAST(vfs.io_stall_read_ms * 1.0 / NULLIF(vfs.num_of_reads,0) AS DECIMAL(18,1))   AS [Latencja odczytu ms],
    CAST(vfs.io_stall_write_ms * 1.0 / NULLIF(vfs.num_of_writes,0) AS DECIMAL(18,1)) AS [Latencja zapisu ms],
    CAST(vfs.io_stall * 1.0 / NULLIF(vfs.num_of_reads + vfs.num_of_writes,0) AS DECIMAL(18,1)) AS [Latencja srednia ms],
    CAST(vfs.io_stall / 1000.0 AS DECIMAL(18,1))                               AS [Laczny stall sek]
FROM sys.dm_io_virtual_file_stats(NULL, NULL) AS vfs
JOIN sys.master_files AS mf
     ON mf.database_id = vfs.database_id AND mf.file_id = vfs.file_id
ORDER BY [Latencja srednia ms] DESC;
/* Punkty odniesienia (pliki danych):
   < 10 ms      – dobrze
   10 – 20 ms   – akceptowalnie
   20 – 50 ms   – wolno, warto się przyjrzeć
   > 50 ms      – problem z podsystemem dyskowym
   Dla plików LOG oczekuj < 5 ms (zapis sekwencyjny).
   Uwaga: to średnia od startu instancji – patrz pkt 2 na pomiar bieżący. */

/*------------------------------------------------------------------ 2. Pomiar różnicowy – latencja w oknie 30 sekund */
IF OBJECT_ID('tempdb..#io_start') IS NOT NULL DROP TABLE #io_start;
SELECT database_id, file_id, num_of_reads, num_of_writes,
       io_stall_read_ms, io_stall_write_ms,
       num_of_bytes_read, num_of_bytes_written
INTO #io_start
FROM sys.dm_io_virtual_file_stats(NULL, NULL);

WAITFOR DELAY '00:00:30';

SELECT
    DB_NAME(e.database_id)                                                     AS [Baza],
    mf.name                                                                    AS [Plik],
    CASE mf.type WHEN 0 THEN 'DANE' WHEN 1 THEN 'LOG' ELSE mf.type_desc END    AS [Typ],
    e.num_of_reads  - s.num_of_reads                                           AS [Odczyty w oknie],
    e.num_of_writes - s.num_of_writes                                          AS [Zapisy w oknie],
    CAST((e.num_of_bytes_read - s.num_of_bytes_read) / 1024.0 / 1024 AS DECIMAL(18,2))    AS [Odczyt MB],
    CAST((e.num_of_bytes_written - s.num_of_bytes_written) / 1024.0 / 1024 AS DECIMAL(18,2)) AS [Zapis MB],
    CAST((e.io_stall_read_ms - s.io_stall_read_ms) * 1.0
         / NULLIF(e.num_of_reads - s.num_of_reads, 0) AS DECIMAL(18,1))        AS [Latencja odczytu ms],
    CAST((e.io_stall_write_ms - s.io_stall_write_ms) * 1.0
         / NULLIF(e.num_of_writes - s.num_of_writes, 0) AS DECIMAL(18,1))      AS [Latencja zapisu ms]
FROM sys.dm_io_virtual_file_stats(NULL, NULL) AS e
JOIN #io_start AS s ON s.database_id = e.database_id AND s.file_id = e.file_id
JOIN sys.master_files AS mf ON mf.database_id = e.database_id AND mf.file_id = e.file_id
WHERE (e.num_of_reads - s.num_of_reads) + (e.num_of_writes - s.num_of_writes) > 0
ORDER BY [Latencja zapisu ms] DESC, [Latencja odczytu ms] DESC;

DROP TABLE #io_start;

/*------------------------------------------------------------------ 3. Zaległe żądania I/O (tu i teraz) */
SELECT
    DB_NAME(mf.database_id)              AS [Baza],
    mf.physical_name                     AS [Plik],
    r.io_pending                         AS [Oczekuje na OS],
    r.io_pending_ms_ticks                AS [Czeka ms],
    r.io_type                            AS [Typ IO]
FROM sys.dm_io_pending_io_requests AS r
JOIN sys.dm_io_virtual_file_stats(NULL, NULL) AS vfs ON vfs.file_handle = r.io_handle
JOIN sys.master_files AS mf ON mf.database_id = vfs.database_id AND mf.file_id = vfs.file_id
ORDER BY r.io_pending_ms_ticks DESC;

/*------------------------------------------------------------------ 4. TOP 25 zapytań wg odczytów logicznych */
SELECT TOP (25)
    DB_NAME(qt.dbid)                                             AS [Baza],
    OBJECT_NAME(qt.objectid, qt.dbid)                            AS [Obiekt],
    qs.execution_count                                           AS [Wykonania],
    qs.total_logical_reads                                       AS [Odczyty log. razem],
    qs.total_logical_reads / NULLIF(qs.execution_count,0)        AS [Odczyty log. srednio],
    qs.total_physical_reads                                      AS [Odczyty fiz. razem],
    qs.total_logical_writes                                      AS [Zapisy log. razem],
    qs.total_elapsed_time / NULLIF(qs.execution_count,0) / 1000  AS [Czas srednio ms],
    SUBSTRING(qt.text, (qs.statement_start_offset / 2) + 1,
        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1) AS [Zapytanie],
    qp.query_plan                                                AS [Plan]
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle)    AS qt
CROSS APPLY sys.dm_exec_query_plan(qs.plan_handle) AS qp
ORDER BY qs.total_logical_reads DESC;

/*------------------------------------------------------------------ 5. TOP 25 zapytań wg odczytów fizycznych (presja na dysk) */
SELECT TOP (25)
    DB_NAME(qt.dbid)                                             AS [Baza],
    qs.execution_count                                           AS [Wykonania],
    qs.total_physical_reads                                      AS [Odczyty fiz. razem],
    qs.total_physical_reads / NULLIF(qs.execution_count,0)       AS [Odczyty fiz. srednio],
    qs.total_elapsed_time / NULLIF(qs.execution_count,0) / 1000  AS [Czas srednio ms],
    SUBSTRING(qt.text, (qs.statement_start_offset / 2) + 1,
        ((CASE qs.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
          ELSE qs.statement_end_offset END - qs.statement_start_offset) / 2) + 1) AS [Zapytanie]
FROM sys.dm_exec_query_stats AS qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) AS qt
WHERE qs.total_physical_reads > 0
ORDER BY qs.total_physical_reads DESC;

/*------------------------------------------------------------------ 6. Autogrowth – czy pliki rosną w trakcie pracy */
SELECT
    DB_NAME(mf.database_id)                                   AS [Baza],
    mf.name                                                   AS [Plik],
    CASE mf.type WHEN 0 THEN 'DANE' WHEN 1 THEN 'LOG' ELSE mf.type_desc END AS [Typ],
    CAST(mf.size * 8.0 / 1024 AS DECIMAL(18,1))               AS [Rozmiar MB],
    CASE WHEN mf.is_percent_growth = 1
         THEN CAST(mf.growth AS VARCHAR(10)) + ' %'
         ELSE CAST(CAST(mf.growth * 8.0 / 1024 AS DECIMAL(18,1)) AS VARCHAR(20)) + ' MB'
    END                                                       AS [Przyrost],
    CASE WHEN mf.max_size = -1 THEN 'bez limitu'
         WHEN mf.max_size = 268435456 THEN 'log: 2 TB'
         ELSE CAST(CAST(mf.max_size * 8.0 / 1024 AS DECIMAL(18,1)) AS VARCHAR(20)) + ' MB'
    END                                                       AS [Limit]
FROM sys.master_files AS mf
ORDER BY [Baza], [Typ];
-- Przyrost procentowy i drobne przyrosty (1 MB / 10%) to częsta przyczyna zamrożeń.
-- Dla plików danych włącz Instant File Initialization (uprawnienie "Perform volume maintenance tasks").
