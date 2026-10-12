<?php

require_once __DIR__ . '/../src/env.php';
load_env(__DIR__ . '/../.env');

require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/../src/zoom_auth.php';

$appToken = zoom_require_context();

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

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talkdesk Status</title>

    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES) ?>">
        html {
            height: 100%;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 16px;
            box-sizing: border-box;
            height: 100%;
            display: flex;
            flex-direction: column;
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

        .user-list {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding-right: 4px;
        }

        .app-header,
        .search,
        #callMessage {
            flex: 0 0 auto;
        }

        .user {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            box-sizing: border-box;
            background: #fff;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .user:hover {
            background: #f3e8ff;
        }

        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #999;
            margin-right: 0;
            flex: 0 0 12px;
        }

        .indicator.available {
            background: #22c55e;
        }

        .indicator.call {
            background: #ef4444;
        }

        .indicator.break {
            background: #f59e0b;
        }

        .indicator.unknown {
            background: #9ca3af;
        }

        .indicator.other {
            background: #3b82f6;
        }

        .info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .name {
            font-weight: bold;
            font-size: 15px;
            overflow-wrap: anywhere;
            white-space: normal;
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
            white-space: normal;
            overflow-wrap: anywhere;
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

        .call-icon:hover {
            opacity: 0.8;
        }

        .call-icon:active {
            transform: scale(0.94);
        }

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
    <input type="text" id="searchColleague" placeholder="Zoek collega" autocomplete="off">
</div>

<div id="callMessage"></div>

<div id="userList" class="user-list">
    <div class="status">Collega's laden...</div>
</div>

<script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES) ?>">
const APP_TOKEN = <?= json_encode($appToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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


/* ---------- Status helpers ---------- */

function getIndicatorClass(statusType) {
    statusType = String(statusType || 'unknown').trim().toLowerCase();

    if (statusType === 'available') return 'available';

    if (
        statusType === 'busy' ||
        statusType === 'on a call' ||
        statusType === 'after call work (acw)'
    ) {
        return 'call';
    }

    if (statusType === 'away') return 'break';
    if (statusType === 'offline') return 'unknown';

    return 'other';
}


/* ---------- Zoeken ---------- */

function applySearch() {
    const searchInput = document.getElementById('searchColleague');
    const searchTerm = searchInput.value.trim().toLowerCase();

    document.querySelectorAll('.user-list .user').forEach(function (user) {
        const nameElement = user.querySelector('.name');

        if (!nameElement) {
            return;
        }

        const name = nameElement.textContent.trim().toLowerCase();

        user.style.display =
            (searchTerm === '' || name.includes(searchTerm)) ? 'flex' : 'none';
    });
}

document.getElementById('searchColleague').addEventListener('input', applySearch);


/* ---------- Collega's laden ---------- */

async function loadColleagues() {
    const userList = document.getElementById('userList');

    try {
        const response = await fetch(
            'status.php?mode=list&t=' + Date.now(),
            {
                cache: 'no-store',
                headers: { 'X-App-Token': APP_TOKEN }
            }
        );

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const data = await response.json();

        if (!data || data.success !== true || !Array.isArray(data.users)) {
            throw new Error('Ongeldige response');
        }

        userList.innerHTML = '';

        data.users
            .slice()
            .sort(function (a, b) {
                return String(a.name || '').localeCompare(
                    String(b.name || ''),
                    'nl',
                    { sensitivity: 'base' }
                );
            })
            .forEach(function (user) {
                const number = String(user.number || '').trim();
                const indicatorClass = getIndicatorClass(user.statusType);

                const element = document.createElement('div');
                element.className = 'user';
                element.dataset.number = number;

                const indicator = document.createElement('div');
                indicator.className = 'indicator ' + indicatorClass;
                indicator.setAttribute('data-indicator', '');

                const info = document.createElement('div');
                info.className = 'info';

                const name = document.createElement('div');
                name.className = 'name';
                name.textContent = user.name || 'Onbekend';
                info.appendChild(name);

                if (number !== '') {
                    const numberWrap = document.createElement('div');
                    numberWrap.className = 'number';

                    const link = document.createElement('a');
                    link.href = '#';
                    link.className = 'phone-number';
                    link.dataset.number = number;
                    link.textContent = number;

                    numberWrap.appendChild(link);
                    info.appendChild(numberWrap);
                }

                const status = document.createElement('div');
                status.className = 'status';
                status.setAttribute('data-status', '');
                status.textContent = user.statusLabel || 'Klik voor status';
                info.appendChild(status);

                element.appendChild(indicator);
                element.appendChild(info);

                if (number !== '') {
                    const callIcon = document.createElement('button');
                    callIcon.type = 'button';
                    callIcon.className = 'phone-number call-icon ' + indicatorClass;
                    callIcon.dataset.number = number;
                    callIcon.setAttribute('data-callicon', '');
                    callIcon.setAttribute('aria-label', 'Bel ' + (user.name || number));
                    callIcon.title = 'Bel ' + number;

                    element.appendChild(callIcon);
                }

                userList.appendChild(element);
            });

        applySearch();

    } catch (error) {
        console.error('Collega’s laden mislukt:', error);
        userList.innerHTML = '';

        const message = document.createElement('div');
        message.className = 'status';
        message.textContent =
            'Laden mislukt: ' + (error.message || String(error));

        userList.appendChild(message);
    }
}

loadColleagues();


/* ---------- Status verversen bij klik op collega ---------- */

async function refreshStatusForUser(element) {
    const number = String(element.dataset.number || '').trim();

    if (!number) {
        return;
    }

    const statusElement = element.querySelector('[data-status]');

    if (statusElement) {
        statusElement.textContent = 'Status ophalen...';
    }

    try {
        const response = await fetch(
            'status.php?t=' + Date.now(),
            {
                cache: 'no-store',
                headers: { 'X-App-Token': APP_TOKEN }
            }
        );

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const data = await response.json();

        if (!data || data.success !== true || !Array.isArray(data.users)) {
            throw new Error('Ongeldige statusresponse');
        }

        const user = data.users.find(function (u) {
            return String(u.number || '').trim() === number;
        });

        if (!user) {
            throw new Error('Gebruiker niet gevonden');
        }

        if (statusElement) {
            statusElement.textContent =
                user.statusLabel || user.status || 'Onbekend';
        }

        const indicator = element.querySelector('[data-indicator]');

        if (indicator) {
            indicator.className =
                'indicator ' + getIndicatorClass(user.statusType);
        }

        const callIcon = element.querySelector('[data-callicon]');

        if (callIcon) {
            callIcon.className =
                'phone-number call-icon ' + getIndicatorClass(user.statusType);
        }

    } catch (error) {
        console.error('Talkdesk status ophalen mislukt:', error);

        if (statusElement) {
            statusElement.textContent = 'Status niet beschikbaar';
        }
    }
}

document.addEventListener('click', function (event) {
    if (event.target.closest('.phone-number, .number')) {
        return;
    }

    const user = event.target.closest('.user');

    if (!user) {
        return;
    }

    refreshStatusForUser(user);
});
</script>

</body>
</html>