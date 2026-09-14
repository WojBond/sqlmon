/*==============================================================================
  09 – KOLEKTOR ZAPYTAŃ (EXTENDED EVENTS)
  Zakłada sesję 'sqlmon_zapytania', która rejestruje zakończone zapytania
  powyżej progu czasu trwania wraz z loginem, który je uruchomił.

  Zasila zakładkę "Zapytania" w sqlmon.php — sekcję historii wg użytkownika.
  Bez tej sesji zakładka działa, ale pokazuje wyłącznie stan bieżący
  i cache planów, bez informacji kto co uruchamiał wcześniej.

  URUCHOM JEDNORAZOWO na monitorowanej instancji.
==============================================================================*/
SET NOCOUNT ON;

/*------------------------------------------------------------------ 0. Próg */
-- Rejestrujemy tylko zapytania trwające dłużej niż próg, żeby nie zalać
-- bufora drobnicą. 3 000 000 mikrosekund = 3 sekundy.
-- Na obciążonej instancji OLTP nie schodź poniżej 1 sekundy.
DECLARE @prog_mikrosekund BIGINT = 3000000;

/*------------------------------------------------------------------ 1. Usunięcie poprzedniej wersji sesji */
IF EXISTS (SELECT 1 FROM sys.server_event_sessions WHERE name = N'sqlmon_zapytania')
BEGIN
    IF EXISTS (SELECT 1 FROM sys.dm_xe_sessions WHERE name = N'sqlmon_zapytania')
        ALTER EVENT SESSION sqlmon_zapytania ON SERVER STATE = STOP;
    DROP EVENT SESSION sqlmon_zapytania ON SERVER;
END
GO

/*------------------------------------------------------------------ 2. Utworzenie sesji */
-- Uwaga: wartość progu jest wstawiana literalnie, bo CREATE EVENT SESSION
-- nie przyjmuje zmiennych w predykacie. Zmieniasz próg = edytujesz obie liczby.
CREATE EVENT SESSION sqlmon_zapytania ON SERVER

ADD EVENT sqlserver.rpc_completed (
    ACTION (
        sqlserver.server_principal_name,
        sqlserver.database_name,
        sqlserver.client_hostname,
        sqlserver.client_app_name,
        sqlserver.session_id
    )
    WHERE (duration > 3000000)
),

ADD EVENT sqlserver.sql_batch_completed (
    ACTION (
        sqlserver.server_principal_name,
        sqlserver.database_name,
        sqlserver.client_hostname,
        sqlserver.client_app_name,
        sqlserver.session_id
    )
    WHERE (duration > 3000000)
)

ADD TARGET package0.ring_buffer (
    SET max_memory = 8192,          -- 8 MB; ok. kilku tysięcy zdarzeń
        max_events_limit = 5000
)

WITH (
    MAX_MEMORY = 8192 KB,
    EVENT_RETENTION_MODE = ALLOW_SINGLE_EVENT_LOSS,   -- nigdy nie spowalniaj serwera
    MAX_DISPATCH_LATENCY = 15 SECONDS,
    STARTUP_STATE = ON                                 -- przetrwa restart instancji
);
GO

/*------------------------------------------------------------------ 3. Start */
ALTER EVENT SESSION sqlmon_zapytania ON SERVER STATE = START;
GO

/*------------------------------------------------------------------ 4. Weryfikacja */
SELECT
    s.name                                  AS [Sesja],
    CASE WHEN d.name IS NULL THEN 'ZATRZYMANA' ELSE 'DZIALA' END AS [Stan],
    s.startup_state                         AS [Start z instancja]
FROM sys.server_event_sessions AS s
LEFT JOIN sys.dm_xe_sessions AS d ON d.name = s.name
WHERE s.name = N'sqlmon_zapytania';

-- Podgląd zebranych zdarzeń (to samo źródło, z którego czyta pulpit):
SELECT TOP (50)
    x.value('(@timestamp)[1]', 'DATETIME2')                                   AS [Czas UTC],
    x.value('(action[@name="server_principal_name"]/value)[1]', 'NVARCHAR(128)') AS [Login],
    x.value('(action[@name="database_name"]/value)[1]', 'NVARCHAR(128)')      AS [Baza],
    x.value('(action[@name="client_app_name"]/value)[1]', 'NVARCHAR(256)')    AS [Program],
    CAST(x.value('(data[@name="duration"]/value)[1]', 'BIGINT') / 1000000.0
         AS DECIMAL(18,2))                                                     AS [Sekundy],
    x.value('(data[@name="statement"]/value)[1]', 'NVARCHAR(MAX)')            AS [Zapytanie]
FROM (
    SELECT CAST(t.target_data AS XML) AS dane
    FROM sys.dm_xe_sessions AS s
    JOIN sys.dm_xe_session_targets AS t ON t.event_session_address = s.address
    WHERE s.name = N'sqlmon_zapytania' AND t.target_name = N'ring_buffer'
) AS src
CROSS APPLY src.dane.nodes('//event') AS e(x)
ORDER BY [Czas UTC] DESC;

/*------------------------------------------------------------------ 5. Odinstalowanie */
/*
ALTER EVENT SESSION sqlmon_zapytania ON SERVER STATE = STOP;
DROP EVENT SESSION sqlmon_zapytania ON SERVER;
*/

/*==============================================================================
  UWAGI

  - Bufor pierścieniowy żyje w pamięci i zeruje się przy restarcie instancji.
    Do trwałej historii użyj Query Store (skrypt 07) — te narzędzia się
    uzupełniają: Query Store zna plany i regresje, ta sesja zna loginy.
  - Narzut przy progu 3 sekund jest pomijalny. Obniżenie progu do zera
    zamieniłoby sesję w trace i realnie obciążyło serwer.
  - ALLOW_SINGLE_EVENT_LOSS oznacza, że przy nawale zdarzeń część przepadnie.
    To celowe: monitoring nigdy nie powinien spowalniać produkcji.
==============================================================================*/
