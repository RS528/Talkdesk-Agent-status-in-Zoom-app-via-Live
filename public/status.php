<?php
declare(strict_types=1);

// Alles schrijfbaars (cache, log, lock) alleen voor de webserver-gebruiker.
umask(0077);

require_once __DIR__ . '/../src/env.php';
load_env(__DIR__ . '/../.env');   // eerst env laden: CACHE_DIR wordt hieruit gelezen

require_once __DIR__ . '/../src/TalkdeskClient.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

try {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0750, true);
    }

    // Gebruikt de 8-seconden cache + lock: Talkdesk wordt maar door 1 request tegelijk aangeroepen.
    $users = get_cached_status_list();

    // Alleen velden die de frontend nodig heeft (nooit e-mail).
    $out = [];
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $out[] = [
            'name'        => (string)($u['name'] ?? ''),
            'number'      => (string)($u['number'] ?? ''),
            'status'      => (string)($u['status'] ?? ''),
            'statusType'  => (string)($u['statusType'] ?? ''),
            'statusLabel' => (string)($u['statusLabel'] ?? ''),
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    // Details alleen in de serverlog, niet naar de client.
    log_error('status.php fout', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Kan Talkdesk gegevens niet ophalen.']);
}