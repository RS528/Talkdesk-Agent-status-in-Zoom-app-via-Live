<?php
declare(strict_types=1);

define('API_RESPONSE', true);

// Alles schrijfbaars (cache, log, lock) alleen voor de webserver-gebruiker.
umask(0077);

require_once __DIR__ . '/../src/env.php';
load_env(__DIR__ . '/../.env');          // eerst env laden: CACHE_DIR wordt hieruit gelezen

require_once __DIR__ . '/headers.php';
require_once __DIR__ . '/../src/zoom_auth.php';
require_once __DIR__ . '/../src/TalkdeskClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

function respond(int $code, array $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_FLAGS);
    exit;
}

/** Geef alleen de velden terug die de frontend nodig heeft (nooit e-mail). */
function public_user(array $u): array
{
    return [
        'name'        => (string)($u['name'] ?? ''),
        'number'      => (string)($u['number'] ?? ''),
        'status'      => (string)($u['status'] ?? ''),
        'statusType'  => (string)($u['statusType'] ?? ''),
        'statusLabel' => (string)($u['statusLabel'] ?? ''),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    respond(405, ['success' => false, 'error' => 'Methode niet toegestaan.']);
}

// Authenticatie VOORDAT er iets met Talkdesk of de cache gebeurt.
app_require_token();

$mode = $_GET['mode'] ?? '';
if (!is_string($mode) || ($mode !== '' && $mode !== 'list')) {
    respond(400, ['success' => false, 'error' => 'Ongeldig verzoek.']);
}

try {

    if ($mode === 'list') {
        // Eerst de bestaande cache proberen (geen Talkdesk-call).
        $cacheFile = CACHE_DIR . '/status.json';
        $users = [];

        if (is_file($cacheFile)) {
            $json = file_get_contents($cacheFile);
            $data = $json === false ? null : json_decode($json, true);

            if (is_array($data) && isset($data['users']) && is_array($data['users'])) {
                $users = $data['users'];
            }
        }

        // Cache ontbreekt, is leeg of ongeldig (bijv. eerste start of nieuwe CACHE_DIR):
        // vul hem dan eenmalig via Talkdesk. Dit gebruikt de 8-seconden cache + lock.
        if ($users === []) {
            $users = get_cached_status_list();
        }
    } else {
        // Normale aanvraag: gebruikt cache, roept Talkdesk alleen aan als die verlopen is.
        $users = get_cached_status_list();
    }

    $users = array_map('public_user', array_filter($users, 'is_array'));

    respond(200, [
        'success' => true,
        'count'   => count($users),
        'users'   => array_values($users),
    ]);

} catch (Throwable $e) {
    // Details alleen in de serverlog, nooit naar de client.
    log_error('status.php fout', ['error' => $e->getMessage()]);
    respond(500, ['success' => false, 'error' => 'Kan Talkdesk gegevens niet ophalen.']);
}