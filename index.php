<?php
/*==============================================================================
  SQLMON – strona startowa
  Kafelek na każdy skonfigurowany serwer, wewnątrz wiersz na każdą instancję.

  Nie łączy się z SQL Serverem samodzielnie. Listę serwerów czyta z bazy
  konfiguracji, a metryki pobiera z endpointu JSON pulpitu, więc hasła
  nie opuszczają sqlmon.php.

  Wrzuć obok sqlmon.php, konfiguracja.php i wspolne.php.
==============================================================================*/

declare(strict_types=1);
require __DIR__ . '/wspolne.php';

date_default_timezone_set('Europe/Warsaw');
sesjaStart();

try {
    $mag = Magazyn::daj();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>SQLMON</title>'
       . '<body style="font:14px system-ui;padding:40px;max-width:700px">'
       . '<h1>Nie udało się otworzyć konfiguracji</h1><p>' . h($e->getMessage()) . '</p>';
    exit;
}

$ust      = $mag->ustawienia();
$serwery  = $mag->serwery();

// Do każdego serwera dokładamy jego aktywne instancje. Serwer bez aktywnych
// instancji też pokazujemy — inaczej zniknąłby bez wyjaśnienia.
$widok = [];
foreach ($serwery as $s) {
    $widok[] = $s + ['instancje' => $mag->instancje((int)$s['id'], true)];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SQLMON — serwery SQL Server</title>
<style>
:root{
  --papier:#e6e9ed; --karta:#f4f6f8; --siatka:#c3ccd6; --siatka-gruba:#a7b4c2;
  --atrament:#131c26; --atrament-2:#5b6b7c; --kreska:#1a5c9e; --kreska-2:#b0741c;
  --dobry:#256b5a; --uwaga:#a86a12; --alarm:#9d1f26; --ramka:#b9c3cd;
  --cien:0 1px 0 rgba(19,28,38,.06);
  --mono:"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;
  --tekst:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
}
html[data-tryb="noc"]{
  --papier:#0f151c; --karta:#161f29; --siatka:#25313e; --siatka-gruba:#33424f;
  --atrament:#dfe7ee; --atrament-2:#8496a6; --kreska:#4d9de0; --kreska-2:#e0a33c;
  --dobry:#4bbf9a; --uwaga:#e0a33c; --alarm:#e26a70; --ramka:#2a3744; --cien:none;
}
*{box-sizing:border-box}
body{margin:0;background:var(--papier);color:var(--atrament);font:14px/1.5 var(--tekst)}

.listwa{display:flex;align-items:center;gap:18px;flex-wrap:wrap;
  padding:10px 18px;background:var(--karta);border-bottom:1px solid var(--ramka);box-shadow:var(--cien)}
.marka{font:600 13px/1 var(--mono);letter-spacing:.22em;text-transform:uppercase}
.marka span{color:var(--kreska)}
.marka em{font:500 10px/1 var(--mono);font-style:normal;letter-spacing:.06em;color:var(--atrament-2);
  margin-left:8px;padding:2px 5px;border:1px solid var(--ramka);border-radius:3px}
.zegar{margin-left:auto;font:11px/1 var(--mono);color:var(--atrament-2);letter-spacing:.08em}
button,.link{font:12px/1 var(--mono);color:var(--atrament);background:var(--papier);
  border:1px solid var(--ramka);border-radius:2px;padding:6px 9px;cursor:pointer;
  text-decoration:none;display:inline-block}
button:focus-visible,a:focus-visible{outline:2px solid var(--kreska);outline-offset:2px}

main{max-width:1180px;margin:0 auto;padding:22px 18px 40px}
.wstep{font:11px/1 var(--mono);letter-spacing:.14em;text-transform:uppercase;
  color:var(--atrament-2);margin:0 0 14px}

.rzad{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px}

.serwer{background:var(--karta);border:1px solid var(--ramka);border-radius:2px;
  box-shadow:var(--cien);overflow:hidden;position:relative;
  transition:border-color .12s,transform .12s}
.serwer:hover{border-color:var(--kreska);transform:translateY(-1px)}
.serwer::before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--siatka-gruba)}
.serwer[data-stan="dobry"]::before{background:var(--dobry)}
.serwer[data-stan="uwaga"]::before{background:var(--uwaga)}
.serwer[data-stan="alarm"]::before{background:var(--alarm)}

.serwer > header{padding:14px 18px 11px;border-bottom:1px solid var(--siatka);
  display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.serwer h2{margin:0;font:600 17px/1 var(--mono);letter-spacing:.12em}
.serwer .host{font:11px/1 var(--mono);color:var(--atrament-2);letter-spacing:.05em}
.serwer .opis{font-size:12.5px;color:var(--atrament-2);width:100%;margin-top:2px}

.instancja{display:block;text-decoration:none;color:inherit;
  border-bottom:1px solid var(--siatka);transition:background .12s}
.instancja:last-of-type{border-bottom:none}
.instancja:hover{background:color-mix(in srgb,var(--kreska) 6%,transparent)}

.i-naglowek{display:flex;align-items:center;gap:9px;padding:9px 18px 5px}
.i-nazwa{font:600 12.5px/1 var(--mono);letter-spacing:.09em}
.i-adres{font:10.5px/1 var(--mono);color:var(--atrament-2);letter-spacing:.04em}
.i-status{margin-left:auto;font:10.5px/1 var(--mono);color:var(--atrament-2);letter-spacing:.06em}

canvas{display:block;width:100%;height:34px}

.odczyty{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--siatka);
  border-top:1px solid var(--siatka)}
.odczyt{background:var(--karta);padding:7px 12px 9px}
.odczyt .et{font:9px/1.3 var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--atrament-2)}
.odczyt .lb{font:400 16px/1.2 var(--mono);margin-top:3px;font-variant-numeric:tabular-nums}
.odczyt[data-stan="alarm"] .lb{color:var(--alarm)}
.odczyt[data-stan="uwaga"] .lb{color:var(--uwaga)}

.dioda{width:7px;height:7px;border-radius:50%;background:var(--siatka-gruba);flex:0 0 auto}
.dioda[data-stan="dobry"]{background:var(--dobry)}
.dioda[data-stan="uwaga"]{background:var(--uwaga)}
.dioda[data-stan="alarm"]{background:var(--alarm)}
.dioda.pracuje{background:var(--kreska-2);animation:puls 1s infinite}
@keyframes puls{50%{opacity:.25}}

.stopka-karty{display:flex;align-items:center;gap:8px;padding:9px 18px;
  border-top:1px solid var(--siatka);font:10.5px/1 var(--mono);letter-spacing:.08em;
  color:var(--atrament-2);text-decoration:none}
.stopka-karty .strzalka{margin-left:auto;color:var(--kreska)}
a.stopka-karty:hover{background:color-mix(in srgb,var(--kreska) 6%,transparent)}

.komunikat{padding:9px 18px;font-size:12px;color:var(--alarm);
  background:color-mix(in srgb,var(--alarm) 9%,transparent)}
.pusto{padding:14px 18px;font-size:12.5px;color:var(--atrament-2);font-style:italic}

.brak-konfiguracji{margin-top:10px;padding:18px 20px;background:var(--karta);
  border:1px solid var(--ramka);border-left:3px solid var(--uwaga);border-radius:2px}
.brak-konfiguracji h2{margin:0 0 8px;font-size:16px}
code{font:12px var(--mono);background:var(--papier);padding:2px 5px;border-radius:2px}

@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
@media (max-width:560px){.odczyty{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<div class="listwa">
  <div class="marka">SQL<span>MON</span><em>v<?= h(APP_VERSION) ?></em></div>
  <button id="odswiez">Odśwież</button>
  <button id="tryb-nocny">Tryb nocny</button>
  <a class="link" href="konfiguracja.php">Konfiguracja</a>
  <div class="zegar" id="stempel">—</div>
</div>

<main>
  <p class="wstep">Monitorowane serwery</p>

  <?php if (!$widok): ?>
    <div class="brak-konfiguracji">
      <h2>Nie skonfigurowano jeszcze żadnego serwera</h2>
      <p>Otwórz <a href="konfiguracja.php"><code>konfiguracja.php</code></a>, dodaj serwer,
         a następnie jego instancje. Po powrocie tutaj pojawi się kafelek na każdy serwer.</p>
    </div>
  <?php else: ?>

  <div class="rzad">
  <?php foreach ($widok as $s): ?>
    <section class="serwer" data-serwer="<?= (int)$s['id'] ?>">
      <header>
        <h2><?= h($s['nazwa']) ?></h2>
        <span class="host"><?= h($s['host']) ?></span>
        <?php if ($s['opis'] !== ''): ?><div class="opis"><?= h($s['opis']) ?></div><?php endif; ?>
      </header>

      <?php if (!$s['instancje']): ?>
        <div class="pusto">Brak aktywnych instancji na tym serwerze.</div>
      <?php endif; ?>

      <?php foreach ($s['instancje'] as $i): ?>
        <a class="instancja"
           data-instancja="<?= (int)$i['id'] ?>"
           href="sqlmon.php?serwer_id=<?= (int)$s['id'] ?>&amp;serwer=<?= (int)$i['id'] ?>">
          <div class="i-naglowek">
            <span class="dioda"></span>
            <span class="i-nazwa"><?= h($i['nazwa']) ?></span>
            <span class="i-adres"><?= h(Magazyn::zbudujHost($i)) ?></span>
            <span class="i-status">odczyt…</span>
          </div>
          <canvas></canvas>
          <div class="odczyty">
            <div class="odczyt" data-metryka="cpu"><div class="et">CPU</div><div class="lb">—</div></div>
            <div class="odczyt" data-metryka="sesje"><div class="et">Sesje</div><div class="lb">—</div></div>
            <div class="odczyt" data-metryka="zapytania"><div class="et">Zapytania</div><div class="lb">—</div></div>
            <div class="odczyt" data-metryka="blokady"><div class="et">Blokady</div><div class="lb">—</div></div>
          </div>
        </a>
      <?php endforeach; ?>

      <?php if ($s['instancje']): ?>
        <a class="stopka-karty" href="sqlmon.php?serwer_id=<?= (int)$s['id'] ?>">
          <span class="podsumowanie">
            <?= count($s['instancje']) ?>
            <?= count($s['instancje']) === 1 ? 'instancja' : (count($s['instancje']) < 5 ? 'instancje' : 'instancji') ?>
          </span>
          <span class="strzalka">otwórz pulpit →</span>
        </a>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>

  <?php endif; ?>
</main>

<script>
(() => {
'use strict';

const wiersze  = [...document.querySelectorAll('.instancja')];
const serwery  = [...document.querySelectorAll('.serwer')];
const stempel  = document.getElementById('stempel');

const kolor  = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const liczba = w => (w === null || w === undefined || w === '') ? '—'
  : Number(w).toLocaleString('pl-PL', {maximumFractionDigits: 0});

const RANGI = {dobry: 0, neutralny: 0, brak: 0, uwaga: 1, alarm: 2};

/* ---------- miniatura taśmy rejestratora ---------- */
function rysuj(canvas, seria) {
  const dpr = window.devicePixelRatio || 1;
  const szer = canvas.clientWidth, wys = canvas.clientHeight;
  if (!szer) return;
  canvas.width = szer * dpr; canvas.height = wys * dpr;
  const c = canvas.getContext('2d');
  c.setTransform(dpr, 0, 0, dpr, 0, 0);
  c.clearRect(0, 0, szer, wys);

  c.strokeStyle = kolor('--siatka'); c.lineWidth = 1;
  for (let i = 1; i < 3; i++) {
    const y = wys * i / 3;
    c.beginPath(); c.moveTo(0, Math.round(y) + .5); c.lineTo(szer, Math.round(y) + .5); c.stroke();
  }

  if (!seria || !seria.length) return;

  const x = i => seria.length === 1 ? szer / 2 : szer * i / (seria.length - 1);
  const y = v => wys - (wys - 3) * Math.min(Math.max(Number(v) || 0, 0) / 100, 1) - 1.5;

  c.beginPath(); c.moveTo(x(0), wys);
  seria.forEach((d, i) => c.lineTo(x(i), y(d.cpu_sql)));
  c.lineTo(x(seria.length - 1), wys); c.closePath();
  c.fillStyle = kolor('--kreska'); c.globalAlpha = .13; c.fill(); c.globalAlpha = 1;

  c.beginPath();
  seria.forEach((d, i) => i ? c.lineTo(x(i), y(d.cpu_sql)) : c.moveTo(x(i), y(d.cpu_sql)));
  c.strokeStyle = kolor('--kreska'); c.lineWidth = 1.4; c.lineJoin = 'round'; c.stroke();
}

/* ---------- odczyt jednej instancji ---------- */
async function sprawdz(wiersz) {
  const dioda  = wiersz.querySelector('.dioda');
  const status = wiersz.querySelector('.i-status');
  dioda.className = 'dioda pracuje';
  wiersz.parentElement.querySelector(`.komunikat[data-dla="${wiersz.dataset.instancja}"]`)?.remove();

  const ustaw = (metryka, wartosc, stan) => {
    const el = wiersz.querySelector(`[data-metryka="${metryka}"]`);
    el.querySelector('.lb').textContent = liczba(wartosc);
    el.dataset.stan = stan || 'neutralny';
  };

  const awaria = (tekst, stan, szczegol) => {
    dioda.className = 'dioda';
    dioda.dataset.stan = stan;
    status.textContent = tekst;
    wiersz._stan = stan;
    if (szczegol) {
      const p = document.createElement('div');
      p.className = 'komunikat';
      p.dataset.dla = wiersz.dataset.instancja;
      p.textContent = szczegol;
      wiersz.after(p);
    }
  };

  try {
    const odp = await fetch(
      `sqlmon.php?akcja=dane&widok=przeglad&serwer=${encodeURIComponent(wiersz.dataset.instancja)}`,
      {headers: {'Accept': 'application/json'}, cache: 'no-store'});

    if (odp.status === 401) { awaria('wymaga zalogowania', 'uwaga'); return; }

    const d = await odp.json();

    if (!d.ok) { awaria('brak połączenia', 'alarm', d.blad); return; }

    const daj = etykieta => d.kafelki.find(k => k.etykieta === etykieta) || {};
    const cpu = daj('CPU procesu SQL');
    const ses = daj('Sesje użytkowników');
    const zap = daj('Aktywne zapytania');
    const blo = daj('Zablokowane sesje');
    const ple = daj('Page life expectancy');

    ustaw('cpu', cpu.wartosc, cpu.stan);
    ustaw('sesje', ses.wartosc, null);
    ustaw('zapytania', zap.wartosc, null);
    ustaw('blokady', blo.wartosc, blo.stan);

    rysuj(wiersz.querySelector('canvas'), d.seria);
    wiersz._seria = d.seria;

    const najgorszy = [cpu, blo, ple].reduce(
      (a, k) => (RANGI[k.stan] ?? 0) > (RANGI[a] ?? 0) ? k.stan : a, 'dobry');

    wiersz._stan = najgorszy;
    dioda.className = 'dioda';
    dioda.dataset.stan = najgorszy;
    status.textContent = najgorszy === 'alarm' ? 'wymaga uwagi'
      : najgorszy === 'uwaga' ? 'podwyższone obciążenie' : 'pracuje normalnie';

  } catch (e) {
    awaria('błąd odczytu', 'alarm', e.message);
  }
}

/* Kafelek serwera przyjmuje stan najgorszej ze swoich instancji — dzięki temu
   pojedyncza instancja w alarmie jest widoczna z poziomu listy serwerów. */
function podsumujSerwery() {
  serwery.forEach(s => {
    const swoje = [...s.querySelectorAll('.instancja')];
    if (!swoje.length) { delete s.dataset.stan; return; }
    s.dataset.stan = swoje.reduce(
      (a, w) => (RANGI[w._stan] ?? 0) > (RANGI[a] ?? 0) ? w._stan : a, 'dobry');
  });
}

async function wszystkie() {
  await Promise.all(wiersze.map(sprawdz));
  podsumujSerwery();
  stempel.textContent = 'odczyt ' + new Date().toLocaleTimeString('pl-PL');
}

/* ---------- sterowanie ---------- */
document.getElementById('odswiez').addEventListener('click', wszystkie);

document.getElementById('tryb-nocny').addEventListener('click', () => {
  const noc = document.documentElement.dataset.tryb === 'noc';
  document.documentElement.dataset.tryb = noc ? '' : 'noc';
  localStorage.setItem('sqlmon_tryb', noc ? '' : 'noc');
  wiersze.forEach(w => rysuj(w.querySelector('canvas'), w._seria));
});

window.addEventListener('resize',
  () => wiersze.forEach(w => rysuj(w.querySelector('canvas'), w._seria)));

document.documentElement.dataset.tryb = localStorage.getItem('sqlmon_tryb') || '';
if (wiersze.length) {
  wszystkie();
  setInterval(wszystkie, <?= max(15, (int)$ust['odswiezanie_sek']) * 2 * 1000 ?>);
}
})();
</script>
</body>
</html>
