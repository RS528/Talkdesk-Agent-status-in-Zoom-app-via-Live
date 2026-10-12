<?php

header("Content-Security-Policy: frame-ancestors 'self' https://*.zoom.us https://*.zoom.com");
header("Referrer-Policy: strict-origin-when-cross-origin");
header('Cache-Control: no-store, no-cache, must-revalidate, private');

umask(0077);

require_once __DIR__ . '/../src/env.php';
load_env(__DIR__ . '/../.env');
require_once __DIR__ . '/../src/TalkdeskClient.php';

/*
 * Data rechtstreeks uit de client halen (Guardian + Reference Hub, match op e-mail).
 * Gebruikt dezelfde 8-seconden cache, dus Talkdesk wordt niet overbelast.
 */
function load_users(): ?array
{
    try {
        if (!is_dir(CACHE_DIR)) {
            mkdir(CACHE_DIR, 0750, true);
        }
        return get_cached_status_list();
    } catch (Throwable $e) {
        if (function_exists('log_error')) {
            log_error('app.php fout', ['error' => $e->getMessage()]);
        } else {
            error_log('app.php fout: ' . $e->getMessage());
        }
        return null;
    }
}

/* Statuskleur op basis van Guardian presence_type (zelfde logica als de NEW-versie). */
function indicator_class(string $statusType): string
{
    $statusType = strtolower(trim($statusType));
    if ($statusType === '') {
        $statusType = 'unknown';
    }

    if ($statusType === 'available') {
        return 'available';
    }
    if (in_array($statusType, ['busy', 'on a call', 'after call work (acw)'], true)) {
        return 'call';
    }
    if ($statusType === 'away') {
        return 'break';
    }
    if ($statusType === 'offline' || $statusType === 'unknown') {
        return 'unknown';
    }
    return 'other';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/* Bouwt de HTML van de collega-lijst (wordt ook door de JS-verversing gebruikt). */
function render_users(array $data): string
{
    $html = '';

    $users = array_values(array_filter($data, 'is_array'));

    // Alfabetisch op naam (hoofdletter- en accent-ongevoelig, Nederlandse volgorde als intl beschikbaar is).
    $collator = class_exists('Collator') ? new Collator('nl_NL') : null;
    if ($collator) {
        $collator->setStrength(Collator::PRIMARY);
    }

    usort($users, function (array $a, array $b) use ($collator): int {
        $nameA = (string)($a['name'] ?? '');
        $nameB = (string)($b['name'] ?? '');

        if ($collator) {
            return $collator->compare($nameA, $nameB);
        }
        return strcmp(mb_strtolower($nameA, 'UTF-8'), mb_strtolower($nameB, 'UTF-8'));
    });

    foreach ($users as $user) {
        $name   = h((string)($user['name'] ?? 'Onbekend'));
        $number = h(trim((string)($user['number'] ?? '')));
        $status = h((string)($user['statusLabel'] ?? 'Onbekend'));
        $class  = indicator_class((string)($user['statusType'] ?? ''));

        $html .= '<div class="user">';
        $html .= '<div class="indicator ' . $class . '"></div>';
        $html .= '<div class="info">';
        $html .= '<div class="name">' . $name . '</div>';

        if ($number !== '') {
            $html .= '<div class="number"><a href="#" class="phone-number" data-number="' . $number . '">' . $number . '</a></div>';
        }

        $html .= '<div class="status">' . $status . '</div>';
        $html .= '</div>';

        if ($number !== '') {
            $html .= '<button type="button" class="phone-number call-icon ' . $class . '"'
                   . ' data-number="' . $number . '"'
                   . ' aria-label="Bel ' . $name . '" title="Bel ' . $number . '"></button>';
        }

        $html .= '</div>';
    }

    return $html;
}

/* ---------- Alleen de lijst (voor automatisch verversen via JS) ---------- */

if (($_GET['partial'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    $data = load_users();

    if (!is_array($data)) {
        http_response_code(503);
        exit;
    }

    echo render_users($data);
    exit;
}

/* ---------- Volledige pagina ---------- */

header('Content-Type: text/html; charset=utf-8');

$logoPath = __DIR__ . '/../logo/vipcom.png';
$logoData = '';
if (is_file($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$phoneIcons = [
    'available' => 'telefoon-groen.svg',
    'call'      => 'telefoon-rood.svg',
    'break'     => 'telefoon-oranje.svg',
    'unknown'   => 'telefoon-grijs.svg',
    'other'     => 'telefoon-blauw.svg',
];

$iconCss = '';
foreach ($phoneIcons as $statusClass => $iconFile) {
    $iconPath = __DIR__ . '/../logo/' . $iconFile;
    if (is_file($iconPath)) {
        $iconData = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($iconPath));
        $iconCss .= '.call-icon.' . $statusClass . ' { background-image: url(\'' . $iconData . '\'); }' . "\n";
    }
}

$data = load_users();

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talkdesk Status</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 16px;
            background: #f5f6f8;
            color: #222;
        }

        h1 {
            margin: 0 0 16px 0;
            font-size: 22px;
        }

        .app-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .vipcom-logo {
            height: 28px;
            width: auto;
            display: block;
        }

        .search {
            margin-bottom: 12px;
        }

        .search input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .search input:focus {
            border-color: #999;
        }

        .user {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #999;
            flex: 0 0 12px;
        }

        .indicator.available { background: #22c55e; }
        .indicator.break     { background: #f59e0b; }
        .indicator.call      { background: #ef4444; }
        .indicator.unknown   { background: #9ca3af; }
        .indicator.other     { background: #3b82f6; }

        .info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .name {
            font-weight: bold;
            font-size: 15px;
            overflow-wrap: anywhere;
        }

        .number {
            color: #777;
            font-size: 12px;
            margin-top: 2px;
        }

        .number a {
            display: inline-block;
            padding: 2px 0;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .number a:hover {
            text-decoration: underline;
        }

        .status {
            font-size: 13px;
            color: #555;
            margin-top: 4px;
            overflow-wrap: anywhere;
        }

        .error {
            color: #b00020;
            background: #fff0f0;
            padding: 12px;
            border-radius: 6px;
        }

        #callMessage {
            display: none;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            word-break: break-word;
        }

        #callMessage.info {
            display: block;
            background: #eef2ff;
            color: #333;
        }

        #callMessage.error {
            display: block;
            background: #fff0f0;
            color: #b00020;
        }

        .call-icon {
            display: block;
            flex: 0 0 36px;
            width: 36px;
            height: 36px;
            margin: 0 0 0 auto;
            padding: 0;
            border: 0;
            border-radius: 9px;
            background-color: transparent;
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            cursor: pointer;
            transition: transform 0.1s ease, opacity 0.15s ease;
        }

        .call-icon:hover  { opacity: 0.8; }
        .call-icon:active { transform: scale(0.94); }
        .call-icon:focus-visible {
            outline: 2px solid #6b7280;
            outline-offset: 2px;
        }

        <?= $iconCss ?>
    </style>

    <script src="https://appssdk.zoom.us/sdk.min.js"></script>
</head>

<body>

<h1 class="app-header">
    <span>Talkdesk Status</span>
    <?php if ($logoData !== ''): ?>
        <img src="<?= $logoData ?>" alt="VIPCOM" class="vipcom-logo">
    <?php endif; ?>
</h1>

<div class="search">
    <input type="text" id="searchColleague" placeholder="Zoek op naam of nummer" autocomplete="off">
</div>

<div id="callMessage"></div>

<div id="userList">
<?php
if (is_array($data)) {
    echo render_users($data);
} else {
    echo '<div class="error">Kan de Talkdesk-status niet ophalen.</div>';
}
?>
</div>

<script>
let zoomReady = null;

function initializeZoom() {
    if (typeof zoomSdk === 'undefined') {
        zoomReady = Promise.reject(
            new Error('zoomSdk niet beschikbaar (niet in de Zoom-client?)')
        );
        zoomReady.catch(function () {});
        return;
    }

    zoomReady = zoomSdk.config({
        version: '0.16.37',
        capabilities: ['makePhoneCall']
    });

    zoomReady.catch(function (error) {
        console.error('Zoom config mislukt:', error);
    });
}

initializeZoom();


/* ---------- Meldingen ---------- */

function showMessage(type, text) {
    const box = document.getElementById('callMessage');
    box.className = type;
    box.textContent = text;
}


/* ---------- Bellen ---------- */

document.addEventListener('click', async function (event) {
    const link = event.target.closest('.phone-number');

    if (!link) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const number = String(link.dataset.number || '').trim();

    if (!number) {
        return;
    }

    showMessage('info', 'Bellen naar ' + number + '...');

    try {
        await zoomReady;
        await zoomSdk.makePhoneCall({ phoneNumber: number });

        showMessage('info', 'Gesprek gestart naar ' + number);

        setTimeout(function () {
            showMessage('', '');
        }, 3000);

    } catch (error) {
        const detail = (error && (error.message || error.code))
            ? ' (' + (error.code ? error.code + ': ' : '') + (error.message || '') + ')'
            : ' (' + String(error) + ')';

        showMessage('error', 'Bellen via Zoom Phone is niet gelukt' + detail);
    }
});


/* ---------- Zoeken ---------- */

function applySearch() {
    const searchTerm = document.getElementById('searchColleague').value.trim().toLowerCase();
    // Zoekterm alleen met cijfers/+, zodat "06 12" ook "0612..." vindt.
    const searchDigits = searchTerm.replace(/[^\d+]/g, '');

    document.querySelectorAll('#userList .user').forEach(function (user) {
        const nameElement = user.querySelector('.name');

        if (!nameElement) {
            return;
        }

        const name = nameElement.textContent.trim().toLowerCase();
        const numberElement = user.querySelector('.number');
        const number = numberElement ? numberElement.textContent.trim().toLowerCase() : '';
        const numberDigits = number.replace(/[^\d+]/g, '');

        const match =
            searchTerm === '' ||
            name.includes(searchTerm) ||
            number.includes(searchTerm) ||
            (searchDigits !== '' && numberDigits.includes(searchDigits));

        user.style.display = match ? 'flex' : 'none';
    });
}

document.getElementById('searchColleague').addEventListener('input', applySearch);


/* ---------- Lijst automatisch verversen (vervangt de oude meta-refresh) ---------- */

async function refreshList() {
    if (document.hidden) {
        return;
    }

    try {
        const response = await fetch('app.php?partial=1&t=' + Date.now(), { cache: 'no-store' });

        if (!response.ok) {
            return; // oude lijst laten staan bij een tijdelijke fout
        }

        document.getElementById('userList').innerHTML = await response.text();
        applySearch(); // zoekfilter behouden na verversen

    } catch (error) {
        console.error('Verversen mislukt:', error);
    }
}

setInterval(refreshList, 5000);
</script>

</body>
</html>