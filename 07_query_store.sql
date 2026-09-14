/*==============================================================================
  07 – QUERY STORE
  Historia wydajności zapytań przetrwająca restart i wyczyszczenie cache planów.
  Wymaga SQL Server 2016+. Uruchamiaj w kontekście konkretnej bazy.
==============================================================================*/
SET NOCOUNT ON;

/*------------------------------------------------------------------ 1. Stan Query Store dla bazy */
SELECT
    DB_NAME()                                AS [Baza],
    actual_state_desc                        AS [Stan faktyczny],
    desired_state_desc                       AS [Stan docelowy],
    readonly_reason                          AS [Powod trybu RO],
    current_storage_size_mb                  AS [Zajete MB],
    max_storage_size_mb                      AS [Limit MB],
    interval_length_minutes                  AS [Interwal agregacji min],
    stale_query_threshold_days               AS [Retencja dni],
    query_capture_mode_desc                  AS [Tryb przechwytywania],
    size_based_cleanup_mode_desc             AS [Auto-czyszczenie],
    wait_stats_capture_mode_desc             AS [Zbieranie wait stats]
FROM sys.database_query_store_options;

/*------------------------------------------------------------------ 2. Włączenie Query Store (zalecana konfiguracja) */
/*
ALTER DATABASE [NAZWA_BAZY] SET QUERY_STORE = ON;
ALTER DATABASE [NAZWA_BAZY] SET QUERY_STORE (
    OPERATION_MODE            = READ_WRITE,
    MAX_STORAGE_SIZE_MB       = 2048,
    INTERVAL_LENGTH_MINUTES   = 15,
    CLEANUP_POLICY            = (STALE_QUERY_THRESHOLD_DAYS = 30),
    SIZE_BASED_CLEANUP_MODE   = AUTO,
    QUERY_CAPTURE_MODE        = AUTO,          -- odsiewa zapytania trywialne
    WAIT_STATS_CAPTURE_MODE   = ON,
    MAX_PLANS_PER_QUERY       = 200
);
*/

/*------------------------------------------------------------------ 3. TOP 25 zapytań wg CPU – ostatnie 24 h */
SELECT TOP (25)
    q.query_id                                                          AS [ID zapytania],
    OBJECT_NAME(q.object_id)                                            AS [Obiekt],
    SUM(rs.count_executions)                                            AS [Wykonania],
    CAST(SUM(rs.avg_cpu_time * rs.count_executions) / 1000.0 AS DECIMAL(18,1))       AS [CPU razem ms],
    CAST(AVG(rs.avg_cpu_time) / 1000.0 AS DECIMAL(18,2))                AS [CPU srednio ms],
    CAST(AVG(rs.avg_duration) / 1000.0 AS DECIMAL(18,2))                AS [Czas srednio ms],
    CAST(MAX(rs.max_duration) / 1000.0 AS DECIMAL(18,2))                AS [Czas max ms],
    CAST(AVG(rs.avg_logical_io_reads) AS DECIMAL(18,1))                 AS [Odczyty log. srednio],
    CAST(AVG(rs.avg_query_max_used_memory) * 8.0 / 1024 AS DECIMAL(18,1)) AS [Pamiec srednio MB],
    COUNT(DISTINCT p.plan_id)                                           AS [Liczba planow],
    MIN(qt.query_sql_text)                                              AS [Tekst zapytania]
FROM sys.query_store_query AS q
JOIN sys.query_store_query_text AS qt ON qt.query_text_id = q.query_text_id
JOIN sys.query_store_plan AS p ON p.query_id = q.query_id
JOIN sys.query_store_runtime_stats AS rs ON rs.plan_id = p.plan_id
JOIN sys.query_store_runtime_stats_interval AS rsi ON rsi.runtime_stats_interval_id = rs.runtime_stats_interval_id
WHERE rsi.start_time > DATEADD(HOUR, -24, SYSDATETIMEOFFSET())
GROUP BY q.query_id, q.object_id
ORDER BY [CPU razem ms] DESC;

/*------------------------------------------------------------------ 4. Zapytania z regresją wydajności (nowy plan gorszy od starego) */
WITH Statystyki AS (
    SELECT
        q.query_id,
        p.plan_id,
        MIN(qt.query_sql_text)                       AS tekst,
        SUM(rs.count_executions)                     AS wykonania,
        AVG(rs.avg_duration)                         AS sredni_czas_us,
        MIN(rsi.start_time)                          AS pierwszy_raz,
        MAX(rsi.end_time)                            AS ostatni_raz
    FROM sys.query_store_query AS q
    JOIN sys.query_store_query_text AS qt ON qt.query_text_id = q.query_text_id
    JOIN sys.query_store_plan AS p ON p.query_id = q.query_id
    JOIN sys.query_store_runtime_stats AS rs ON rs.plan_id = p.plan_id
    JOIN sys.query_store_runtime_stats_interval AS rsi ON rsi.runtime_stats_interval_id = rs.runtime_stats_interval_id
    WHERE rsi.start_time > DATEADD(DAY, -7, SYSDATETIMEOFFSET())
    GROUP BY q.query_id, p.plan_id
    HAVING SUM(rs.count_executions) > 5
)
SELECT
    a.query_id                                                       AS [ID zapytania],
    a.plan_id                                                        AS [Plan stary],
    b.plan_id                                                        AS [Plan nowy],
    CAST(a.sredni_czas_us / 1000.0 AS DECIMAL(18,2))                 AS [Stary czas ms],
    CAST(b.sredni_czas_us / 1000.0 AS DECIMAL(18,2))                 AS [Nowy czas ms],
    CAST(b.sredni_czas_us * 1.0 / NULLIF(a.sredni_czas_us,0) AS DECIMAL(10,2)) AS [Wspolczynnik regresji],
    a.wykonania                                                      AS [Wykonania starego],
    b.wykonania                                                      AS [Wykonania nowego],
    a.tekst                                                          AS [Tekst zapytania]
FROM Statystyki AS a
JOIN Statystyki AS b
     ON b.query_id = a.query_id
    AND b.plan_id > a.plan_id
    AND b.sredni_czas_us > a.sredni_czas_us * 2
ORDER BY [Wspolczynnik regresji] DESC;
-- Naprawa punktowa: sp_query_store_force_plan @query_id = X, @plan_id = Y;  (wymuszenie starego planu)

/*------------------------------------------------------------------ 5. Oczekiwania wg kategorii – ostatnie 24 h */
SELECT
    ws.wait_category_desc                                            AS [Kategoria oczekiwania],
    CAST(SUM(ws.total_query_wait_time_ms) / 1000.0 AS DECIMAL(18,1)) AS [Sekundy razem],
    SUM(ws.total_query_wait_time_ms)                                 AS [ms razem],
    COUNT(DISTINCT ws.query_id)                                      AS [Liczba zapytan]
FROM sys.query_store_wait_stats AS ws
JOIN sys.query_store_runtime_stats_interval AS rsi
     ON rsi.runtime_stats_interval_id = ws.runtime_stats_interval_id
WHERE rsi.start_time > DATEADD(HOUR, -24, SYSDATETIMEOFFSET())
GROUP BY ws.wait_category_desc
ORDER BY [ms razem] DESC;

/*------------------------------------------------------------------ 6. Obciążenie w rozbiciu na godziny (profil dobowy) */
SELECT
    CAST(rsi.start_time AS DATE)                                       AS [Data],
    DATEPART(HOUR, rsi.start_time)                                     AS [Godzina],
    SUM(rs.count_executions)                                           AS [Wykonania],
    CAST(SUM(rs.avg_cpu_time * rs.count_executions) / 1000000.0 AS DECIMAL(18,1))     AS [CPU sek],
    CAST(SUM(rs.avg_duration * rs.count_executions) / 1000000.0 AS DECIMAL(18,1))     AS [Czas sek],
    CAST(SUM(rs.avg_logical_io_reads * rs.count_executions) AS BIGINT) AS [Odczyty logiczne]
FROM sys.query_store_runtime_stats AS rs
JOIN sys.query_store_runtime_stats_interval AS rsi
     ON rsi.runtime_stats_interval_id = rs.runtime_stats_interval_id
WHERE rsi.start_time > DATEADD(DAY, -7, SYSDATETIMEOFFSET())
GROUP BY CAST(rsi.start_time AS DATE), DATEPART(HOUR, rsi.start_time)
ORDER BY [Data] DESC, [Godzina];
-- Ten zestaw najlepiej wyeksportować i wykreślić – pokazuje, kiedy naprawdę boli.
