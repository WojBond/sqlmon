/*==============================================================================
  06 – TEMPDB I INDEKSY
  Wykorzystanie tempdb, kontencja stron alokacyjnych, brakujące i martwe indeksy.
==============================================================================*/
SET NOCOUNT ON;
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;

/*==============================  TEMPDB  =====================================*/

/*------------------------------------------------------------------ 1. Zajętość tempdb */
SELECT
    CAST(SUM(unallocated_extent_page_count) * 8.0 / 1024 AS DECIMAL(18,1))       AS [Wolne MB],
    CAST(SUM(user_object_reserved_page_count) * 8.0 / 1024 AS DECIMAL(18,1))     AS [Obiekty uzytkownika MB],
    CAST(SUM(internal_object_reserved_page_count) * 8.0 / 1024 AS DECIMAL(18,1)) AS [Obiekty wewnetrzne MB],
    CAST(SUM(version_store_reserved_page_count) * 8.0 / 1024 AS DECIMAL(18,1))   AS [Version store MB],
    CAST(SUM(mixed_extent_page_count) * 8.0 / 1024 AS DECIMAL(18,1))             AS [Extenty mieszane MB]
FROM tempdb.sys.dm_db_file_space_usage;

/*------------------------------------------------------------------ 2. Konfiguracja plików tempdb */
SELECT
    mf.file_id                                        AS [ID pliku],
    mf.name                                           AS [Plik],
    mf.type_desc                                      AS [Typ],
    mf.physical_name                                  AS [Sciezka],
    CAST(mf.size * 8.0 / 1024 AS DECIMAL(18,1))       AS [Rozmiar MB],
    CASE WHEN mf.is_percent_growth = 1
         THEN CAST(mf.growth AS VARCHAR(10)) + ' %'
         ELSE CAST(CAST(mf.growth * 8.0 / 1024 AS DECIMAL(18,1)) AS VARCHAR(20)) + ' MB' END AS [Przyrost],
    (SELECT cpu_count FROM sys.dm_os_sys_info)        AS [Logiczne CPU]
FROM tempdb.sys.database_files AS mf
ORDER BY mf.file_id;
/* Zasada: liczba plików danych tempdb = liczba rdzeni (max 8), wszystkie tej samej
   wielkości i z jednakowym, stałym przyrostem. Od SQL Server 2016 instalator ustawia to sam. */

/*------------------------------------------------------------------ 3. Kontencja PAGELATCH (tu i teraz) */
-- UWAGA: do 9.1.x ta sekcja filtrowala wylacznie 'resource_description LIKE 2:%',
-- czyli tempdb. Kontencja na ostatniej stronie indeksu w bazie produkcyjnej
-- (typowa przy wstawianiu rosnacych kluczy, np. kolumn IDENTITY) nie pojawiala sie
-- w wyniku wcale, co prowadzilo do falszywego wniosku "problemu nie ma".
SELECT
    wt.session_id                    AS [Sesja],
    wt.wait_type                     AS [Typ],
    wt.wait_duration_ms              AS [Czeka ms],
    wt.resource_description          AS [Zasob],
    CASE
      WHEN wt.resource_description LIKE '2:1:1'  THEN 'tempdb PFS – kontencja alokacji'
      WHEN wt.resource_description LIKE '2:1:2'  THEN 'tempdb GAM – kontencja alokacji'
      WHEN wt.resource_description LIKE '2:1:3'  THEN 'tempdb SGAM – kontencja alokacji'
      WHEN wt.resource_description LIKE '2:%'    THEN 'tempdb – inna strona'
      ELSE 'Baza uzytkownika – prawdopodobnie ostatnia strona indeksu'
    END                              AS [Interpretacja],
    s.login_name                     AS [Login],
    s.program_name                   AS [Program],
    qt.text                          AS [Zapytanie]
FROM sys.dm_os_waiting_tasks AS wt
LEFT JOIN sys.dm_exec_sessions AS s ON s.session_id = wt.session_id
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = wt.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE wt.wait_type LIKE 'PAGELATCH%';
-- "2:1:1" = PFS, "2:1:2" = GAM, "2:1:3" = SGAM w tempdb – klasyczna kontencja alokacji.
-- Zasob w innej bazie ma postac baza:plik:strona – rozwin go sekcja 3a ponizej.

/*------------------------------------------------------------------ 3a. Rozwinięcie zasobu na konkretny obiekt */
-- Podstaw trzy liczby odczytane z kolumny [Zasob] powyzej. Wymaga SQL Server 2019+.
/*
DECLARE @baza INT = 9, @plik INT = 1, @strona INT = 12345;

SELECT
    OBJECT_SCHEMA_NAME(pi.object_id, @baza) AS [Schemat],
    OBJECT_NAME(pi.object_id, @baza)        AS [Obiekt],
    pi.index_id                             AS [Indeks],
    pi.page_type_desc                       AS [Typ strony],
    pi.is_page_compressed                   AS [Skompresowana]
FROM sys.dm_db_page_info(@baza, @plik, @strona, 'DETAILED') AS pi;
*/
-- Jesli wskazany obiekt to tabela z rosnacym kluczem (IDENTITY, sekwencja, data),
-- a instancja to SQL Server 2019 lub nowsza, wlasciwym lekarstwem jest:
--   ALTER INDEX <nazwa_PK> ON <schemat>.<tabela> SET (OPTIMIZE_FOR_SEQUENTIAL_KEY = ON);
-- Zmiana jest odwracalna i nie wymaga przebudowy indeksu.

/*------------------------------------------------------------------ 3b. Czy tempdb jest poprawnie skonfigurowana */
SELECT
    mf.file_id                                   AS [Plik],
    mf.name                                      AS [Nazwa],
    mf.size / 128                                AS [Rozmiar MB],
    mf.growth                                    AS [Przyrost],
    mf.is_percent_growth                         AS [Przyrost procentowy],
    CASE WHEN mf.is_percent_growth = 1
         THEN 'ZMIEN na staly przyrost w MB' ELSE 'ok' END AS [Uwaga]
FROM sys.master_files AS mf
WHERE mf.database_id = 2 AND mf.type = 0;
-- Pliki danych tempdb powinny byc rownej wielkosci, miec rowny staly przyrost,
-- a ich liczba powinna odpowiadac liczbie rdzeni logicznych (maksymalnie 8).


/*------------------------------------------------------------------ 4. Sesje zużywające tempdb */
SELECT TOP (25)
    su.session_id                                                              AS [Sesja],
    DB_NAME(s.database_id)                                                     AS [Baza sesji],
    s.login_name                                                               AS [Login],
    s.host_name                                                                AS [Host],
    s.program_name                                                             AS [Program],
    CAST((su.user_objects_alloc_page_count - su.user_objects_dealloc_page_count)
         * 8.0 / 1024 AS DECIMAL(18,1))                                        AS [Obiekty uzytkownika MB],
    CAST((su.internal_objects_alloc_page_count - su.internal_objects_dealloc_page_count)
         * 8.0 / 1024 AS DECIMAL(18,1))                                        AS [Obiekty wewnetrzne MB],
    qt.text                                                                    AS [Zapytanie]
FROM tempdb.sys.dm_db_session_space_usage AS su
JOIN sys.dm_exec_sessions AS s ON s.session_id = su.session_id
LEFT JOIN sys.dm_exec_requests AS r ON r.session_id = su.session_id
OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) AS qt
WHERE su.session_id > 50
ORDER BY (su.internal_objects_alloc_page_count + su.user_objects_alloc_page_count) DESC;

/*==============================  INDEKSY  ====================================*/
-- UWAGA: poniższe zapytania działają w kontekście BIEŻĄCEJ bazy. Ustaw ją w SSMS (Ctrl+U).

/*------------------------------------------------------------------ 5. Brakujące indeksy – TOP 25 */
SELECT TOP (25)
    DB_NAME(mid.database_id)                                    AS [Baza],
    mid.[statement]                                             AS [Tabela],
    CAST((migs.user_seeks + migs.user_scans)
         * migs.avg_total_user_cost
         * migs.avg_user_impact / 100.0 AS DECIMAL(18,1))       AS [Wskaznik korzysci],
    migs.user_seeks                                             AS [Seeks],
    migs.user_scans                                             AS [Scans],
    CAST(migs.avg_user_impact AS DECIMAL(5,1))                  AS [Szacowana poprawa %],
    CAST(migs.avg_total_user_cost AS DECIMAL(18,2))             AS [Sredni koszt],
    migs.last_user_seek                                         AS [Ostatnie uzycie],
    mid.equality_columns                                        AS [Kolumny = ],
    mid.inequality_columns                                      AS [Kolumny > < ],
    mid.included_columns                                        AS [Kolumny INCLUDE],
    'CREATE INDEX IX_' + REPLACE(REPLACE(REPLACE(
        ISNULL(mid.equality_columns, '') + ISNULL('_' + mid.inequality_columns, ''),
        '[',''), ']',''), ', ','_')
      + ' ON ' + mid.[statement] + ' ('
      + ISNULL(mid.equality_columns, '')
      + CASE WHEN mid.equality_columns IS NOT NULL AND mid.inequality_columns IS NOT NULL THEN ', ' ELSE '' END
      + ISNULL(mid.inequality_columns, '') + ')'
      + ISNULL(' INCLUDE (' + mid.included_columns + ')', '')   AS [Propozycja DDL]
FROM sys.dm_db_missing_index_group_stats AS migs
JOIN sys.dm_db_missing_index_groups AS mig ON mig.index_group_handle = migs.group_handle
JOIN sys.dm_db_missing_index_details AS mid ON mid.index_handle = mig.index_handle
WHERE mid.database_id = DB_ID()
ORDER BY [Wskaznik korzysci] DESC;
-- Nie twórz ich hurtowo! To sugestie optymalizatora, często nakładające się na siebie.
-- Konsoliduj podobne propozycje i weryfikuj wpływ na operacje zapisu.

/*------------------------------------------------------------------ 6. Indeksy nieużywane / kosztowne w utrzymaniu */
SELECT
    OBJECT_SCHEMA_NAME(i.object_id)                       AS [Schemat],
    OBJECT_NAME(i.object_id)                              AS [Tabela],
    i.name                                                AS [Indeks],
    i.type_desc                                           AS [Typ],
    ISNULL(ius.user_seeks, 0)                             AS [Seeks],
    ISNULL(ius.user_scans, 0)                             AS [Scans],
    ISNULL(ius.user_lookups, 0)                           AS [Lookups],
    ISNULL(ius.user_updates, 0)                           AS [Aktualizacje],
    ISNULL(ius.user_seeks, 0) + ISNULL(ius.user_scans, 0) + ISNULL(ius.user_lookups, 0) AS [Odczyty razem],
    ius.last_user_seek                                    AS [Ostatni seek],
    ius.last_user_update                                  AS [Ostatnia aktualizacja],
    CAST(SUM(ps.used_page_count) * 8.0 / 1024 AS DECIMAL(18,1)) AS [Rozmiar MB]
FROM sys.indexes AS i
JOIN sys.objects AS o ON o.object_id = i.object_id
LEFT JOIN sys.dm_db_index_usage_stats AS ius
       ON ius.object_id = i.object_id AND ius.index_id = i.index_id AND ius.database_id = DB_ID()
LEFT JOIN sys.dm_db_partition_stats AS ps
       ON ps.object_id = i.object_id AND ps.index_id = i.index_id
WHERE o.is_ms_shipped = 0
  AND i.type_desc IN ('NONCLUSTERED', 'CLUSTERED')
  AND i.is_primary_key = 0
  AND i.is_unique_constraint = 0
GROUP BY i.object_id, i.name, i.type_desc, ius.user_seeks, ius.user_scans,
         ius.user_lookups, ius.user_updates, ius.last_user_seek, ius.last_user_update
HAVING ISNULL(ius.user_seeks, 0) + ISNULL(ius.user_scans, 0) + ISNULL(ius.user_lookups, 0)
       < ISNULL(ius.user_updates, 0)
ORDER BY ISNULL(ius.user_updates, 0) DESC;
-- Statystyki użycia zerują się przy restarcie instancji – oceniaj po min. kilku tygodniach pracy.

/*------------------------------------------------------------------ 7. Fragmentacja indeksów > 100 stron */
SELECT
    OBJECT_SCHEMA_NAME(ips.object_id)                         AS [Schemat],
    OBJECT_NAME(ips.object_id)                                AS [Tabela],
    i.name                                                    AS [Indeks],
    ips.index_type_desc                                       AS [Typ],
    CAST(ips.avg_fragmentation_in_percent AS DECIMAL(5,1))    AS [Fragmentacja %],
    ips.page_count                                            AS [Strony],
    CAST(ips.page_count * 8.0 / 1024 AS DECIMAL(18,1))        AS [Rozmiar MB],
    CAST(ips.avg_page_space_used_in_percent AS DECIMAL(5,1))  AS [Wypelnienie strony %],
    CASE WHEN ips.avg_fragmentation_in_percent > 30 THEN 'REBUILD'
         WHEN ips.avg_fragmentation_in_percent > 10 THEN 'REORGANIZE'
         ELSE 'bez akcji' END                                 AS [Sugestia]
FROM sys.dm_db_index_physical_stats(DB_ID(), NULL, NULL, NULL, 'LIMITED') AS ips
JOIN sys.indexes AS i ON i.object_id = ips.object_id AND i.index_id = ips.index_id
WHERE ips.page_count > 100
  AND ips.avg_fragmentation_in_percent > 10
ORDER BY ips.avg_fragmentation_in_percent DESC;
-- Tryb 'LIMITED' jest lekki. 'SAMPLED'/'DETAILED' obciążają serwer – nie w godzinach szczytu.

/*------------------------------------------------------------------ 8. Nieaktualne statystyki */
SELECT TOP (30)
    OBJECT_SCHEMA_NAME(s.object_id)                    AS [Schemat],
    OBJECT_NAME(s.object_id)                           AS [Tabela],
    s.name                                             AS [Statystyka],
    sp.last_updated                                    AS [Aktualizacja],
    DATEDIFF(DAY, sp.last_updated, GETDATE())          AS [Dni temu],
    sp.rows                                            AS [Wiersze],
    sp.rows_sampled                                    AS [Probka],
    CAST(100.0 * sp.rows_sampled / NULLIF(sp.rows,0) AS DECIMAL(5,1)) AS [% probki],
    sp.modification_counter                            AS [Modyfikacje od aktualizacji]
FROM sys.stats AS s
CROSS APPLY sys.dm_db_stats_properties(s.object_id, s.stats_id) AS sp
JOIN sys.objects AS o ON o.object_id = s.object_id
WHERE o.is_ms_shipped = 0
  AND sp.rows > 1000
  AND sp.modification_counter > 0
ORDER BY sp.modification_counter DESC;
