/*==============================================================================
  05 – AKTYWNOŚĆ BIEŻĄCA I BLOKADY
  "Kto teraz męczy serwer" – odpowiednik Activity Monitora, ale z detalami.
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*------------------------------------------------------------------ 1. Aktywne żądania */
SELECT
    r.session_id                                            AS [Sesja],
    r.blocking_session_id                                   AS [Blokuje ja],
    r.status                                                AS [Status],
    r.command                                               AS [Komenda],
    DB_NAME(r.database_id)                                  AS [Baza],
    s.login_name                                            AS [Login],
    s.host_name                                             AS [Host],
    s.program_name                                          AS [Program],
    r.wait_type                                             AS [Typ oczekiwania],
    r.wait_time                                             AS [Czeka ms],
    r.last_wait_type                                        AS [Ostatnie oczekiwanie],
    r.wait_resource                                         AS [Zasob],
    r.total_elapsed_time                                    AS [Trwa ms],
    r.cpu_time                                              AS [CPU ms],
    r.logical_reads                                         AS [Odczyty logiczne],
    r.writes                                                AS [Zapisy],
    r.granted_query_memory * 8 / 1024                       AS [Pamiec grant MB],
    r.open_transaction_count                                AS [Otwarte transakcje],
    r.percent_complete                                      AS [% ukonczenia],
    r.estimated_completion_time / 1000                      AS [Pozostalo sek],
    r.start_time                                            AS [Start],
    SUBSTRING(qt.text, (r.statement_start_offset / 2) + 1,
        ((CASE r.statement_end_offset WHEN -1 THEN DATALENGTH(qt.text)
          ELSE r.statement_end_offset END - r.statement_start_offset) / 2) + 1) AS [Aktualna instrukcja],
    qt.text                                                 AS [Caly batch],
    qp.query_plan                                           AS [Plan]
FROM sys.dm_exec_requests AS r
JOIN sys.dm_exec_sessions AS s ON s.session_id = r.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle)     AS qt
OUTER APPLY sys.dm_exec_query_plan(r.plan_handle)  AS qp
WHERE r.session_id <> @@SPID
  AND s.is_user_process = 1
ORDER BY r.total_elapsed_time DESC;

/*------------------------------------------------------------------ 2. Łańcuchy blokad (drzewo) */
WITH Blokady AS (
    SELECT
        r.session_id,
        r.blocking_session_id,
        r.wait_type,
        r.wait_time,
        r.wait_resource,
        DB_NAME(r.database_id) AS baza,
        r.sql_handle
    FROM sys.dm_exec_requests AS r
    -- Warunek samoblokady jest KONIECZNY: przy zapytaniu rownoleglym watki potrafia
    -- czekac na siebie nawzajem (blocking_session_id = session_id), co zapetlalo
    -- ponizsza rekurencje i konczylo sie bledem 530.
    WHERE r.blocking_session_id <> 0
      AND r.blocking_session_id <> r.session_id

    UNION

    SELECT
        s.session_id, 0, NULL, 0, NULL, DB_NAME(s.database_id), NULL
    FROM sys.dm_exec_sessions AS s
    WHERE s.session_id IN (SELECT blocking_session_id FROM sys.dm_exec_requests
                            WHERE blocking_session_id <> 0
                              AND blocking_session_id <> session_id)
),
Drzewo AS (
    SELECT session_id, blocking_session_id, wait_type, wait_time, wait_resource, baza, sql_handle,
           0 AS poziom,
           CAST(RIGHT('00000' + CAST(session_id AS VARCHAR(5)), 5) AS VARCHAR(1000)) AS sciezka
    FROM Blokady
    WHERE blocking_session_id = 0

    UNION ALL

    SELECT b.session_id, b.blocking_session_id, b.wait_type, b.wait_time, b.wait_resource, b.baza, b.sql_handle,
           d.poziom + 1,
           CAST(d.sciezka + ' > ' + RIGHT('00000' + CAST(b.session_id AS VARCHAR(5)), 5) AS VARCHAR(1000))
    FROM Blokady AS b
    JOIN Drzewo AS d ON d.session_id = b.blocking_session_id
)
SELECT
    REPLICATE('    ', d.poziom) + CAST(d.session_id AS VARCHAR(10)) AS [Hierarchia sesji],
    d.poziom                                                        AS [Poziom],
    d.blocking_session_id                                           AS [Blokuje ja],
    d.wait_type                                                     AS [Typ oczekiwania],
    d.wait_time                                                     AS [Czeka ms],
    d.wait_resource                                                 AS [Zasob],
    d.baza                                                          AS [Baza],
    s.login_name                                                    AS [Login],
    s.host_name                                                     AS [Host],
    s.program_name                                                  AS [Program],
    s.last_request_start_time                                       AS [Ostatnie zadanie],
    qt.text                                                         AS [Zapytanie]
FROM Drzewo AS d
LEFT JOIN sys.dm_exec_sessions AS s ON s.session_id = d.session_id
OUTER APPLY sys.dm_exec_sql_text(d.sql_handle) AS qt
ORDER BY d.sciezka
OPTION (MAXRECURSION 50);   -- zabezpieczenie przed cyklem w lancuchu blokad
-- Poziom 0 = korzeń blokady. To jego trzeba zdiagnozować (często: otwarta transakcja bez commit).

/*------------------------------------------------------------------ 3. Sesje z otwartymi transakcjami (śpiące = najgorsze) */
SELECT
    s.session_id                                     AS [Sesja],
    s.status                                         AS [Status sesji],
    ISNULL(bt.baza_transakcji, DB_NAME(s.database_id)) AS [Baza],
    tat.transaction_begin_time                       AS [Transakcja od],
    DATEDIFF(SECOND, tat.transaction_begin_time, GETDATE()) AS [Trwa sek],
    CAST(ISNULL(bt.log_uzyty, 0) / 1024.0 AS DECIMAL(18,1)) AS [Log KB],
    CASE tat.transaction_type
        WHEN 1 THEN 'Odczyt/zapis' WHEN 2 THEN 'Tylko odczyt'
        WHEN 3 THEN 'System' WHEN 4 THEN 'Rozproszona' END AS [Typ transakcji],
    CASE tat.transaction_state
        WHEN 0 THEN 'Inicjalizacja' WHEN 1 THEN 'Nie zainicjowana'
        WHEN 2 THEN 'AKTYWNA' WHEN 3 THEN 'Zakonczona (odczyt)'
        WHEN 4 THEN 'Commit 2PC' WHEN 5 THEN 'Przygotowana'
        WHEN 6 THEN 'Zatwierdzona' WHEN 7 THEN 'Wycofywana' WHEN 8 THEN 'Wycofana' END AS [Stan],
    s.login_name                                     AS [Login],
    s.host_name                                      AS [Host],
    s.program_name                                   AS [Program],
    s.last_request_end_time                          AS [Koniec ostatniego zadania],
    qt.text                                          AS [Ostatnie zapytanie]
FROM sys.dm_tran_active_transactions AS tat
JOIN sys.dm_tran_session_transactions AS tst ON tst.transaction_id = tat.transaction_id
JOIN sys.dm_exec_sessions AS s ON s.session_id = tst.session_id
LEFT JOIN sys.dm_exec_connections AS c ON c.session_id = s.session_id
OUTER APPLY sys.dm_exec_sql_text(c.most_recent_sql_handle) AS qt
OUTER APPLY (
    SELECT TOP (1)
        DB_NAME(dt.database_id)                AS baza_transakcji,
        dt.database_transaction_log_bytes_used AS log_uzyty
    FROM sys.dm_tran_database_transactions AS dt
    WHERE dt.transaction_id = tat.transaction_id
      AND dt.database_id <> 2                  -- pomijamy tempdb
    ORDER BY dt.database_transaction_log_bytes_used DESC
) AS bt
WHERE s.is_user_process = 1
ORDER BY tat.transaction_begin_time;
-- Uwaga: sys.dm_tran_session_transactions nie ma kolumny database_id.
-- Baza, w ktorej transakcja faktycznie pracuje, pochodzi z sys.dm_tran_database_transactions.

/*------------------------------------------------------------------ 4. Podsumowanie blokad wg obiektu */
SELECT
    DB_NAME(l.resource_database_id)     AS [Baza],
    l.resource_type                     AS [Typ zasobu],
    CASE WHEN l.resource_type = 'OBJECT'
         THEN OBJECT_NAME(l.resource_associated_entity_id, l.resource_database_id)
         ELSE CAST(l.resource_associated_entity_id AS VARCHAR(30)) END AS [Zasob],
    l.request_mode                      AS [Tryb],
    l.request_status                    AS [Status],
    COUNT(*)                            AS [Liczba blokad],
    COUNT(DISTINCT l.request_session_id) AS [Liczba sesji]
FROM sys.dm_tran_locks AS l
WHERE l.request_session_id > 50
GROUP BY l.resource_database_id, l.resource_type, l.resource_associated_entity_id,
         l.request_mode, l.request_status
HAVING COUNT(*) > 10
ORDER BY [Liczba blokad] DESC;

/*------------------------------------------------------------------ 5. Sesje wg zużycia zasobów (narastająco) */
SELECT TOP (30)
    s.session_id                        AS [Sesja],
    s.login_name                        AS [Login],
    s.host_name                         AS [Host],
    s.program_name                      AS [Program],
    s.status                            AS [Status],
    DB_NAME(s.database_id)              AS [Baza],
    s.cpu_time                          AS [CPU ms],
    s.memory_usage * 8                  AS [Pamiec KB],
    s.reads                             AS [Odczyty],
    s.writes                            AS [Zapisy],
    s.logical_reads                     AS [Odczyty logiczne],
    s.open_transaction_count            AS [Otwarte transakcje],
    s.login_time                        AS [Zalogowany od],
    s.last_request_start_time           AS [Ostatnie zadanie]
FROM sys.dm_exec_sessions AS s
WHERE s.is_user_process = 1
ORDER BY s.cpu_time DESC;

/*------------------------------------------------------------------ 6. Ostatnie deadlocki (z sesji system_health) */
SELECT TOP (20)
    xed.value('@timestamp', 'datetime2')     AS [Czas UTC],
    xed.query('.')                           AS [Raport deadlock XML]
FROM (
    SELECT CAST(target_data AS XML) AS TargetData
    FROM sys.dm_xe_session_targets AS st
    JOIN sys.dm_xe_sessions AS s ON s.address = st.event_session_address
    WHERE s.name = N'system_health' AND st.target_name = N'ring_buffer'
) AS Dane
CROSS APPLY TargetData.nodes('RingBufferTarget/event[@name="xml_deadlock_report"]') AS x(xed)
ORDER BY [Czas UTC] DESC;
-- Kliknij komórkę XML, aby otworzyć graf zakleszczenia.
