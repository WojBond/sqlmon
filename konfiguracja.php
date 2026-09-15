<?php
/*==============================================================================
  SQLMON – konfiguracja
  Zarządzanie serwerami, instancjami i ustawieniami globalnymi.
  Dane trafiają do SQLite (dane/sqlmon.sqlite), hasła w postaci zaszyfrowanej.
==============================================================================*/

declare(strict_types=1);
require __DIR__ . '/wspolne.php';

sesjaStart();

try {
    $mag = Magazyn::daj();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>SQLMON</title>'
       . '<body style="font:14px system-ui;padding:40px"><h1>Błąd magazynu konfiguracji</h1><p>'
       . h($e->getMessage()) . '</p>'
       . '<p>Sprawdź, czy katalog danych istnieje i czy proces serwera WWW ma do niego prawo zapisu.</p>';
    exit;
}

$ust        = $mag->ustawienia();
$pierwszy   = $ust['haslo_admin_hash'] === '';   // nie ustawiono jeszcze hasła administratora
$komunikat  = '';
$blad       = '';

/*------------------------------------------------------- USTAWIENIE HASŁA / LOGOWANIE */

if ($pierwszy && ($_POST['akcja'] ?? '') === 'zaloz_admina') {
    $h1 = (string)($_POST['haslo1'] ?? '');
    $h2 = (string)($_POST['haslo2'] ?? '');
    if (strlen($h1) < 8)        $blad = 'Hasło musi mieć co najmniej 8 znaków.';
    elseif ($h1 !== $h2)        $blad = 'Hasła nie są identyczne.';
    else {
        $mag->ustaw('haslo_admin_hash', password_hash($h1, PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION['sqlmon_admin'] = true;
        header('Location: konfiguracja.php?ok=zalozono');
        exit;
    }
}

if (!$pierwszy && ($_POST['akcja'] ?? '') === 'loguj') {
    $podane = $_POST['haslo'] ?? '';
    if (is_string($podane) && password_verify($podane, $ust['haslo_admin_hash'])) {
        session_regenerate_id(true);
        $_SESSION['sqlmon_admin'] = true;
        header('Location: konfiguracja.php');
        exit;
    }
    $blad = 'Nieprawidłowe hasło.';
    sleep(1);   // spowolnienie prób zgadywania
}

if (($_GET['wyloguj'] ?? '') !== '') {
    $_SESSION = [];
    session_destroy();
    header('Location: konfiguracja.php');
    exit;
}

/*------------------------------------------------------------------ EKRAN LOGOWANIA */

if (!czyAdmin()) {
    ekranLogowania($pierwszy, $blad);
    exit;
}

/*------------------------------------------------------------------ AKCJE ZAPISU */

$akcja = $_POST['akcja'] ?? '';

if ($akcja !== '' && !in_array($akcja, ['loguj', 'zaloz_admina'], true)) {
    if (!sprawdzCsrf()) {
        $blad = 'Nieprawidłowy token formularza. Odśwież stronę i spróbuj ponownie.';
        $akcja = '';
    }
}

try {
    switch ($akcja) {

        case 'zapisz_serwer':
            $id    = ($_POST['id'] ?? '') !== '' ? (int)$_POST['id'] : null;
            $nazwa = trim((string)($_POST['nazwa'] ?? ''));
            $host  = trim((string)($_POST['host'] ?? ''));
            if ($nazwa === '' || $host === '') {
                throw new RuntimeException('Nazwa i adres serwera są wymagane.');
            }
            $mag->zapiszSerwer($id, $nazwa, $host,
                trim((string)($_POST['opis'] ?? '')), (int)($_POST['kolejnosc'] ?? 0));
            $komunikat = $id === null ? 'Dodano serwer.' : 'Zapisano serwer.';
            break;

        case 'usun_serwer':
            $mag->usunSerwer((int)$_POST['id']);
            $komunikat = 'Usunięto serwer wraz z jego instancjami.';
            break;

        case 'zapisz_instancje':
            $id = ($_POST['id'] ?? '') !== '' ? (int)$_POST['id'] : null;
            $d  = [
                'serwer_id'     => (int)($_POST['serwer_id'] ?? 0),
                'nazwa'         => trim((string)($_POST['nazwa'] ?? '')),
                'instancja'     => trim((string)($_POST['instancja'] ?? '')),
                'port'          => trim((string)($_POST['port'] ?? '')),
                'baza'          => trim((string)($_POST['baza'] ?? 'master')) ?: 'master',
                'baza_historii' => trim((string)($_POST['baza_historii'] ?? '')),
                'windows_auth'  => !empty($_POST['windows_auth']),
                'uzytkownik'    => trim((string)($_POST['uzytkownik'] ?? '')),
                'aktywna'       => !empty($_POST['aktywna']),
                'kolejnosc'     => (int)($_POST['kolejnosc'] ?? 0),
            ];
            if ($d['nazwa'] === '' || $d['serwer_id'] === 0) {
                throw new RuntimeException('Nazwa instancji i serwer są wymagane.');
            }
            if ($d['baza_historii'] !== '' && !bazaOk($d['baza_historii'])) {
                throw new RuntimeException('Nieprawidłowa nazwa bazy historii.');
            }
            // Puste pole hasła przy edycji = zostaw dotychczasowe.
            $haslo = (string)($_POST['haslo'] ?? '');
            $mag->zapiszInstancje($id, $d,
                ($id !== null && $haslo === '') ? null : $haslo);
            $komunikat = $id === null ? 'Dodano instancję.' : 'Zapisano instancję.';
            break;

        case 'usun_instancje':
            $mag->usunInstancje((int)$_POST['id']);
            $komunikat = 'Usunięto instancję.';
            break;

        case 'zapisz_ustawienia':
            foreach (array_keys(Magazyn::DOMYSLNE) as $k) {
                if ($k === 'haslo_admin_hash') continue;
                if ($k === 'haslo_pulpitu')    continue;   // obsługiwane osobno
                if (isset($_POST[$k])) $mag->ustaw($k, trim((string)$_POST[$k]));
            }
            if (($_POST['zmien_haslo_pulpitu'] ?? '') !== '') {
                $mag->ustaw('haslo_pulpitu', (string)($_POST['haslo_pulpitu'] ?? ''));
            }
            if (($_POST['nowe_haslo_admin'] ?? '') !== '') {
                $nowe = (string)$_POST['nowe_haslo_admin'];
                if (strlen($nowe) < 8) throw new RuntimeException('Hasło administratora musi mieć co najmniej 8 znaków.');
                $mag->ustaw('haslo_admin_hash', password_hash($nowe, PASSWORD_DEFAULT));
            }
            $komunikat = 'Zapisano ustawienia.';
            break;

        case 'test':
            $inst = $mag->instancja((int)$_POST['id']);
            if (!$inst) throw new RuntimeException('Nie ma takiej instancji.');
            $komunikat = testPolaczenia($mag, $inst);
            break;
    }
} catch (Throwable $e) {
    $blad = $e->getMessage();
}

$ust = $mag->ustawienia();   // odśwież po ewentualnym zapisie

/*------------------------------------------------------------------- FUNKCJE */

function testPolaczenia(Magazyn $mag, array $inst): string
{
    if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Brak sterownika pdo_sqlsrv — nie da się przetestować połączenia.');
    }
    $p   = $mag->parametryPolaczenia($inst);
    $dsn = sprintf('sqlsrv:Server=%s;Database=%s;TrustServerCertificate=1;LoginTimeout=5',
                   $p['host'], $p['baza']);
    $t0  = microtime(true);
    $pdo = $p['windows_auth']
        ? new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
        : new PDO($dsn, $p['uzytkownik'], $p['haslo'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $w = $pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS VARCHAR(50)) AS wersja,
                             CAST(SERVERPROPERTY('Edition') AS VARCHAR(80)) AS edycja,
                             @@SERVERNAME AS nazwa")->fetch(PDO::FETCH_ASSOC);

    // Bez VIEW SERVER STATE pulpit połączy się, ale nie pokaże niczego sensownego.
    $upr = 'VIEW SERVER STATE: OK';
    try { $pdo->query('SELECT TOP (1) session_id FROM sys.dm_exec_sessions')->fetch(); }
    catch (Throwable $e) { $upr = 'UWAGA: brak uprawnienia VIEW SERVER STATE — pulpit nie odczyta metryk'; }

    return sprintf('Połączono w %d ms. %s — %s, wersja %s. %s',
        (int)round((microtime(true) - $t0) * 1000),
        $w['nazwa'], $w['edycja'], $w['wersja'], $upr);
}

function ekranLogowania(bool $pierwszy, string $blad): void
{
    $csrf = tokenCsrf();
    ?><!doctype html><html lang="pl"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SQLMON — konfiguracja</title><?php style(); ?></head>
    <body class="logowanie">
      <form method="post" class="karta waska">
        <h1>SQL<span>MON</span></h1>
        <?php if ($pierwszy): ?>
          <p class="wstep">Pierwsze uruchomienie. Ustaw hasło administratora — będzie
             potrzebne do zmiany konfiguracji serwerów i instancji.</p>
          <input type="hidden" name="akcja" value="zaloz_admina">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <label>Hasło (min. 8 znaków)<input type="password" name="haslo1" required autofocus></label>
          <label>Powtórz hasło<input type="password" name="haslo2" required></label>
          <button type="submit">Ustaw hasło</button>
        <?php else: ?>
          <input type="hidden" name="akcja" value="loguj">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <label>Hasło administratora<input type="password" name="haslo" required autofocus></label>
          <button type="submit">Zaloguj</button>
        <?php endif; ?>
        <?php if ($blad !== ''): ?><p class="blad"><?= h($blad) ?></p><?php endif; ?>
      </form>
    </body></html><?php
}

function style(): void { ?>
<style>
:root{
  --papier:#e6e9ed; --karta:#f4f6f8; --ramka:#c3ccd6; --atrament:#131c26;
  --atrament-2:#5b6b7c; --granat:#1c3d5a; --akcent:#2f6f9f;
  --ok:#2f7d46; --alarm:#b3402f; --uwaga:#a86a10;
  --mono:"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;
}
@media (prefers-color-scheme: dark){
  :root{ --papier:#0f151c; --karta:#161f29; --ramka:#2a3744; --atrament:#dde5ec;
         --atrament-2:#8496a6; --granat:#8fb6d8; --akcent:#7fb3dd; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--papier);color:var(--atrament);
  font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
body.logowanie{display:flex;align-items:center;justify-content:center;min-height:100vh}
h1{font:600 20px/1 var(--mono);letter-spacing:.22em;text-transform:uppercase;
  color:var(--granat);margin:0 0 18px}
h1 span{color:var(--akcent)}
h2{font-size:15px;color:var(--granat);margin:0 0 10px}
h3{font-size:13px;color:var(--akcent);margin:16px 0 6px}
.pasek{display:flex;align-items:center;gap:14px;padding:12px 20px;
  background:var(--karta);border-bottom:1px solid var(--ramka);flex-wrap:wrap}
.pasek h1{margin:0;font-size:15px}
.pasek a{color:var(--akcent);text-decoration:none;font-size:13px}
.pasek a:hover{text-decoration:underline}
.pasek .rozpychacz{flex:1}
main{padding:20px;max-width:1180px;margin:0 auto}
.karta{background:var(--karta);border:1px solid var(--ramka);border-radius:6px;
  padding:18px;margin-bottom:18px}
.karta.waska{width:340px}
.wstep{color:var(--atrament-2);font-size:13px;margin:0 0 14px}
label{display:block;margin-bottom:10px;font-size:12px;color:var(--atrament-2)}
label input,label select{display:block;width:100%;margin-top:3px;padding:7px 8px;
  border:1px solid var(--ramka);border-radius:4px;background:var(--papier);
  color:var(--atrament);font:13px/1.3 inherit}
label.poziomo{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--atrament)}
label.poziomo input{width:auto;margin:0}
.siatka{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0 14px}
button,.przycisk{padding:7px 14px;border:1px solid var(--ramka);border-radius:4px;
  background:var(--granat);color:#fff;font:13px inherit;cursor:pointer;text-decoration:none;
  display:inline-block}
button.drugi,.przycisk.drugi{background:transparent;color:var(--akcent)}
button.grozny{background:var(--alarm)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:var(--granat);color:#fff;text-align:left;padding:7px 9px;font-weight:600}
td{padding:7px 9px;border-bottom:1px solid var(--ramka);vertical-align:middle}
tr:last-child td{border-bottom:none}
td.akcje{white-space:nowrap;text-align:right}
td.akcje form{display:inline}
.mono{font-family:var(--mono);font-size:12px}
.znacznik{font-size:11px;padding:2px 6px;border-radius:3px;border:1px solid var(--ramka);
  color:var(--atrament-2)}
.znacznik.on{color:var(--ok);border-color:var(--ok)}
.znacznik.off{color:var(--atrament-2)}
.komunikat,.blad{padding:10px 14px;border-radius:5px;margin-bottom:16px;font-size:13px}
.komunikat{background:rgba(47,125,70,.12);border:1px solid var(--ok);color:var(--ok)}
.blad{background:rgba(179,64,47,.12);border:1px solid var(--alarm);color:var(--alarm)}
.podpowiedz{font-size:12px;color:var(--atrament-2);margin:4px 0 14px}
details{margin-bottom:16px}
summary{cursor:pointer;color:var(--akcent);font-size:13px;padding:4px 0}
.pusto{color:var(--atrament-2);font-style:italic;padding:14px 0}
</style><?php }

/*--------------------------------------------------------------------- WIDOK */

$csrf     = tokenCsrf();
$serwery  = $mag->serwery();
$edycjaS  = isset($_GET['serwer'])    ? $mag->serwer((int)$_GET['serwer'])       : null;
$edycjaI  = isset($_GET['instancja']) ? $mag->instancja((int)$_GET['instancja']) : null;
$nowaDlaS = isset($_GET['nowa_dla'])  ? (int)$_GET['nowa_dla'] : 0;
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SQLMON — konfiguracja</title><?php style(); ?></head>
<body>

<div class="pasek">
  <h1>SQL<span>MON</span></h1>
  <span class="znacznik">konfiguracja</span>
  <span class="rozpychacz"></span>
  <a href="sqlmon.php">Pulpit →</a>
  <a href="konfiguracja.php?wyloguj=1">Wyloguj</a>
</div>

<main>

<?php if ($komunikat !== ''): ?><div class="komunikat"><?= h($komunikat) ?></div><?php endif; ?>
<?php if ($blad !== ''):      ?><div class="blad"><?= h($blad) ?></div><?php endif; ?>

<!-- ============================================================ SERWERY -->
<div class="karta">
  <h2>Serwery i instancje</h2>
  <p class="podpowiedz">Serwer to maszyna fizyczna lub wirtualna. Jeden serwer może
     mieć wiele instancji SQL Server — domyślną i nazwane. Adres podaje się raz,
     przy serwerze; instancja dokłada do niego własną nazwę i port.</p>

  <?php if (!$serwery): ?>
    <p class="pusto">Nie ma jeszcze żadnego serwera. Dodaj pierwszy formularzem poniżej.</p>
  <?php endif; ?>

  <?php foreach ($serwery as $s): $inst = $mag->instancje((int)$s['id']); ?>
    <h3><?= h($s['nazwa']) ?>
        <span class="mono">(<?= h($s['host']) ?>)</span>
        <?php if ($s['opis'] !== ''): ?><span class="znacznik"><?= h($s['opis']) ?></span><?php endif; ?>
        <a href="?serwer=<?= (int)$s['id'] ?>" class="mono">edytuj</a>
    </h3>

    <table>
      <tr>
        <th>Instancja</th><th>Adres połączenia</th><th>Logowanie</th>
        <th>Historia</th><th>Stan</th><th></th>
      </tr>
      <?php if (!$inst): ?>
        <tr><td colspan="6" class="pusto">Brak instancji na tym serwerze.</td></tr>
      <?php endif; ?>
      <?php foreach ($inst as $i): ?>
        <tr>
          <td><?= h($i['nazwa']) ?></td>
          <td class="mono"><?= h(Magazyn::zbudujHost($i)) ?></td>
          <td>
            <?php if ($i['windows_auth']): ?>
              <span class="znacznik">Windows</span>
            <?php else: ?>
              <span class="mono"><?= h($i['uzytkownik'] ?: '—') ?></span>
              <?php if ($i['haslo_szyfr'] === ''): ?>
                <span class="znacznik off">bez hasła</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="mono"><?= h($i['baza_historii'] ?: '—') ?></td>
          <td>
            <span class="znacznik <?= $i['aktywna'] ? 'on' : 'off' ?>">
              <?= $i['aktywna'] ? 'aktywna' : 'wyłączona' ?></span>
          </td>
          <td class="akcje">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="akcja" value="test">
              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
              <button type="submit" class="drugi">Testuj</button>
            </form>
            <a class="przycisk drugi" href="?instancja=<?= (int)$i['id'] ?>">Edytuj</a>
            <form method="post" onsubmit="return confirm('Usunąć instancję <?= h($i['nazwa']) ?>?')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="akcja" value="usun_instancje">
              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
              <button type="submit" class="grozny">Usuń</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p style="margin:8px 0 18px">
      <a class="przycisk drugi" href="?nowa_dla=<?= (int)$s['id'] ?>">+ Dodaj instancję</a>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Usunąć serwer <?= h($s['nazwa']) ?> wraz z instancjami?')">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="akcja" value="usun_serwer">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
        <button type="submit" class="grozny">Usuń serwer</button>
      </form>
    </p>
  <?php endforeach; ?>
</div>

<!-- ==================================================== FORMULARZ SERWERA -->
<div class="karta">
  <h2><?= $edycjaS ? 'Edycja serwera: ' . h($edycjaS['nazwa']) : 'Nowy serwer' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="akcja" value="zapisz_serwer">
    <input type="hidden" name="id" value="<?= $edycjaS ? (int)$edycjaS['id'] : '' ?>">
    <div class="siatka">
      <label>Nazwa wyświetlana
        <input name="nazwa" required maxlength="60"
               value="<?= h($edycjaS['nazwa'] ?? '') ?>" placeholder="ASTAT"></label>
      <label>Adres (host lub IP)
        <input name="host" required maxlength="120"
               value="<?= h($edycjaS['host'] ?? '') ?>" placeholder="10.10.10.20"></label>
      <label>Opis
        <input name="opis" maxlength="120"
               value="<?= h($edycjaS['opis'] ?? '') ?>" placeholder="Serwer produkcyjny"></label>
      <label>Kolejność
        <input name="kolejnosc" type="number" value="<?= (int)($edycjaS['kolejnosc'] ?? 0) ?>"></label>
    </div>
    <button type="submit"><?= $edycjaS ? 'Zapisz zmiany' : 'Dodaj serwer' ?></button>
    <?php if ($edycjaS): ?><a class="przycisk drugi" href="konfiguracja.php">Anuluj</a><?php endif; ?>
  </form>
</div>

<!-- ================================================== FORMULARZ INSTANCJI -->
<?php if ($serwery): ?>
<div class="karta">
  <h2><?= $edycjaI ? 'Edycja instancji: ' . h($edycjaI['nazwa']) : 'Nowa instancja' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="akcja" value="zapisz_instancje">
    <input type="hidden" name="id" value="<?= $edycjaI ? (int)$edycjaI['id'] : '' ?>">

    <div class="siatka">
      <label>Serwer
        <select name="serwer_id" required>
          <?php $wyb = (int)($edycjaI['serwer_id'] ?? $nowaDlaS); ?>
          <?php foreach ($serwery as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $wyb === (int)$s['id'] ? 'selected' : '' ?>>
              <?= h($s['nazwa']) ?> (<?= h($s['host']) ?>)</option>
          <?php endforeach; ?>
        </select></label>

      <label>Nazwa wyświetlana
        <input name="nazwa" required maxlength="60"
               value="<?= h($edycjaI['nazwa'] ?? '') ?>" placeholder="PROD"></label>

      <label>Nazwa instancji SQL
        <input name="instancja" maxlength="60"
               value="<?= h($edycjaI['instancja'] ?? '') ?>" placeholder="puste = domyślna"></label>

      <label>Port
        <input name="port" type="number" min="1" max="65535"
               value="<?= h((string)($edycjaI['port'] ?? '')) ?>" placeholder="puste = 1433"></label>

      <label>Baza kontekstu
        <input name="baza" maxlength="128"
               value="<?= h($edycjaI['baza'] ?? 'master') ?>"></label>

      <label>Baza historii
        <input name="baza_historii" maxlength="128"
               value="<?= h($edycjaI['baza_historii'] ?? 'DBA_Monitoring') ?>"
               placeholder="puste = bez zakładki Historia"></label>

      <label>Login SQL
        <input name="uzytkownik" maxlength="128" autocomplete="off"
               value="<?= h($edycjaI['uzytkownik'] ?? '') ?>"></label>

      <label>Hasło
        <input name="haslo" type="password" autocomplete="new-password"
               placeholder="<?= $edycjaI ? 'bez zmian' : '' ?>"></label>

      <label>Kolejność
        <input name="kolejnosc" type="number"
               value="<?= (int)($edycjaI['kolejnosc'] ?? 0) ?>"></label>
    </div>

    <label class="poziomo">
      <input type="checkbox" name="windows_auth" value="1"
        <?= !empty($edycjaI['windows_auth']) ? 'checked' : '' ?>>
      Uwierzytelnianie Windows (login i hasło zostaną wyczyszczone)</label>

    <label class="poziomo">
      <input type="checkbox" name="aktywna" value="1"
        <?= (!$edycjaI || $edycjaI['aktywna']) ? 'checked' : '' ?>>
      Aktywna — widoczna w przełączniku pulpitu</label>

    <p class="podpowiedz">
      Adres połączenia powstaje z adresu serwera oraz pól powyżej:
      <span class="mono">HOST</span>,
      <span class="mono">HOST\INSTANCJA</span>,
      <span class="mono">HOST,PORT</span> lub
      <span class="mono">HOST\INSTANCJA,PORT</span>.
      Przy edycji puste pole hasła oznacza „zostaw dotychczasowe”.
    </p>

    <button type="submit"><?= $edycjaI ? 'Zapisz zmiany' : 'Dodaj instancję' ?></button>
    <?php if ($edycjaI): ?><a class="przycisk drugi" href="konfiguracja.php">Anuluj</a><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<!-- ================================================ USTAWIENIA GLOBALNE -->
<div class="karta">
  <h2>Ustawienia globalne</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="akcja" value="zapisz_ustawienia">

    <h3>Zachowanie pulpitu</h3>
    <div class="siatka">
      <label>Odświeżanie (s, 0 = ręcznie)
        <input name="odswiezanie_sek" type="number" min="0" max="3600"
               value="<?= h($ust['odswiezanie_sek']) ?>"></label>
      <label>Strona powrotu
        <input name="strona_glowna" value="<?= h($ust['strona_glowna']) ?>"
               placeholder="np. index.php"></label>
      <label>Adres serwera aplikacji
        <input name="adres_iis" value="<?= h($ust['adres_iis']) ?>"
               placeholder="10.10.10.25"></label>
      <label>Pomijane oczekiwania (po przecinku)
        <input name="pomijaj_oczekiwania" value="<?= h($ust['pomijaj_oczekiwania']) ?>"></label>
      <label>Limit długiego zapytania (h)
        <input name="limit_dlugiego_zapytania_h" type="number" min="1" max="720"
               value="<?= h($ust['limit_dlugiego_zapytania_h']) ?>"></label>
    </div>

    <h3>Progi ostrzeżeń</h3>
    <p class="podpowiedz">Dla PLE wyższa wartość jest lepsza, więc próg uwagi jest
       większy od progu alarmu. Orientacyjnie: 300 s na każde 4 GB bufora —
       przy 96 GB daje to około 7200 s, nie 900.</p>
    <div class="siatka">
      <label>CPU — uwaga (%)   <input name="prog_cpu_uwaga" type="number" value="<?= h($ust['prog_cpu_uwaga']) ?>"></label>
      <label>CPU — alarm (%)   <input name="prog_cpu_alarm" type="number" value="<?= h($ust['prog_cpu_alarm']) ?>"></label>
      <label>PLE — uwaga (s)   <input name="prog_ple_uwaga" type="number" value="<?= h($ust['prog_ple_uwaga']) ?>"></label>
      <label>PLE — alarm (s)   <input name="prog_ple_alarm" type="number" value="<?= h($ust['prog_ple_alarm']) ?>"></label>
      <label>Latencja — uwaga (ms) <input name="prog_latencja_uwaga" type="number" value="<?= h($ust['prog_latencja_uwaga']) ?>"></label>
      <label>Latencja — alarm (ms) <input name="prog_latencja_alarm" type="number" value="<?= h($ust['prog_latencja_alarm']) ?>"></label>
      <label>Blokady — uwaga  <input name="prog_blokady_uwaga" type="number" value="<?= h($ust['prog_blokady_uwaga']) ?>"></label>
      <label>Blokady — alarm  <input name="prog_blokady_alarm" type="number" value="<?= h($ust['prog_blokady_alarm']) ?>"></label>
      <label>Długie zapytanie (ms) <input name="prog_dlugie_zapytanie" type="number" value="<?= h($ust['prog_dlugie_zapytanie']) ?>"></label>
    </div>

    <h3>Dostęp</h3>
    <div class="siatka">
      <label>Hasło do pulpitu
        <input name="haslo_pulpitu" type="password" autocomplete="new-password"
               placeholder="<?= $ust['haslo_pulpitu'] !== '' ? 'ustawione — wpisz nowe' : 'puste = bez logowania' ?>"></label>
      <label>Nowe hasło administratora
        <input name="nowe_haslo_admin" type="password" autocomplete="new-password"
               placeholder="zostaw puste, by nie zmieniać"></label>
    </div>
    <label class="poziomo">
      <input type="checkbox" name="zmien_haslo_pulpitu" value="1">
      Zastosuj wpisane hasło pulpitu (zaznacz także, gdy chcesz je skasować)</label>

    <button type="submit">Zapisz ustawienia</button>
  </form>
</div>

<details>
  <summary>Gdzie leżą dane i jak są chronione</summary>
  <div class="karta">
    <p>Konfiguracja trzymana jest w pliku SQLite:</p>
    <p class="mono"><?= h(SQLMON_BAZA) ?></p>
    <p>Klucz szyfrujący hasła:</p>
    <p class="mono"><?= h(SQLMON_KLUCZ) ?></p>
    <p class="podpowiedz">
      Hasła do instancji są szyfrowane algorytmem AES-256-GCM. Ponieważ pulpit musi
      je odszyfrować, żeby się połączyć, klucz leży na tym samym serwerze — kto ma
      dostęp do obu plików, ma hasła. Szyfrowanie chroni przed przypadkowym
      podejrzeniem i przed wyciekiem samej kopii bazy, nie przed kimś z dostępem
      do systemu plików.
    </p>
    <p class="podpowiedz">
      Dlatego: używaj konta SQL wyłącznie do odczytu, z uprawnieniem
      <span class="mono">VIEW SERVER STATE</span> (nigdy <span class="mono">sa</span>),
      a tam gdzie się da — uwierzytelniania Windows, przy którym hasła nie ma wcale.
      Katalog danych możesz przenieść poza drzewo serwera WWW, definiując
      <span class="mono">SQLMON_DANE</span> przed dołączeniem
      <span class="mono">wspolne.php</span>.
    </p>
    <p class="podpowiedz">
      Kopia zapasowa: skopiuj oba pliki razem. Sam plik bazy bez klucza jest
      bezużyteczny — haseł nie da się odtworzyć.
    </p>
  </div>
</details>

</main>
</body></html>
