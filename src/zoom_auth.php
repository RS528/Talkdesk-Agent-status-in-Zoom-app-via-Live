<?php
declare(strict_types=1);

/*
 * Zoom App-authenticatie zonder cookies.
 *
 * Flow:
 *  1. Zoom laadt app.php en stuurt de header "x-zoom-app-context" mee
 *     (AES-256-GCM, sleutel = SHA-256 van je Zoom App Client Secret).
 *  2. app.php ontsleutelt die (alleen Zoom kan een geldige header maken, want de
 *     sleutel is je Client Secret) en geeft een kortlevend, HMAC-gesigneerd token
 *     mee in de pagina. Wie de app mag gebruiken wordt in de Zoom Marketplace beheerd.
 *  3. De JS stuurt dat token als header "X-App-Token" mee naar status.php,
 *     dat het token verifieert voordat Talkdesk wordt aangeraakt.
 *
 * Vereiste .env-variabelen:
 *   ZOOM_CLIENT_SECRET       Client Secret van je Zoom App (NIET de Talkdesk-secret)
 *   ZOOM_ALLOWED_ACCOUNT_ID  (optioneel) Eén of meer Zoom account-id's, komma-gescheiden.
 *                            Alleen ingesteld = alleen deze accounts krijgen toegang.
 *   ZOOM_LOG_ACCOUNT_ID      (optioneel) 1 = toon het account-id uit de context op het scherm
 *                            (en in de errorlog) in plaats van de app. Zet na gebruik op 0.
 *   ZOOM_CLIENT_SECRET_ALT   (optioneel, alleen voor tests) extra secret(s), bijv. de development-secret
 *   APP_TOKEN_TTL            (optioneel) geldigheid in seconden, standaard 28800 (8 uur)
 */

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): ?string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? null : $decoded;
}

/** Ontsleutelt de x-zoom-app-context header. Geeft null bij elke afwijking. */
function zoom_decrypt_context(string $header, string $clientSecret): ?array
{
    $buf = b64url_decode(trim($header));
    if ($buf === null || strlen($buf) < 8 || strlen($buf) > 8192) {
        return null;
    }

    $pos = 0;
    $take = static function (int $len) use ($buf, &$pos): ?string {
        if ($len < 0 || $pos + $len > strlen($buf)) {
            return null;
        }
        $chunk = substr($buf, $pos, $len);
        $pos += $len;
        return $chunk;
    };

    // Opbouw: ivLen(1) iv | aadLen(2, LE) aad | cipherLen(4, LE) cipher | tag (rest)
    $b = $take(1);              if ($b === null) return null;
    $iv = $take(ord($b));       if ($iv === null) return null;

    $b = $take(2);              if ($b === null) return null;
    $aad = $take(unpack('v', $b)[1]);        if ($aad === null) return null;

    $b = $take(4);              if ($b === null) return null;
    $cipher = $take(unpack('V', $b)[1]);     if ($cipher === null) return null;

    // De tag is de rest van de buffer. Voor het geval een variant er een
    // lengtebyte voor zet, proberen we die ook.
    $rest = substr($buf, $pos);
    if ($rest === '' || $rest === false) {
        return null;
    }
    $tags = [$rest];
    if (strlen($rest) > 1 && ord($rest[0]) === strlen($rest) - 1) {
        $tags[] = substr($rest, 1);
    }

    $key = hash('sha256', $clientSecret, true);

    foreach ($tags as $tag) {
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($plain === false) {
            continue;
        }
        $data = json_decode($plain, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return null;
}

/** Diagnose: toont alleen lengtes van de onderdelen van de header, nooit inhoud. */
function zoom_context_structure(string $header): string
{
    $buf = b64url_decode(trim($header));
    if ($buf === null || strlen($buf) < 8) {
        return 'geen geldige base64';
    }
    $pos = 0;
    $total = strlen($buf);
    $ivLen = ord($buf[$pos++]);
    $pos += $ivLen;
    if ($pos + 2 > $total) return "totaal=$total ivLen=$ivLen (buffer te kort)";
    $aadLen = unpack('v', substr($buf, $pos, 2))[1];
    $pos += 2 + $aadLen;
    if ($pos + 4 > $total) return "totaal=$total ivLen=$ivLen aadLen=$aadLen (buffer te kort)";
    $cipherLen = unpack('V', substr($buf, $pos, 4))[1];
    $pos += 4 + $cipherLen;
    $rest = $total - $pos;
    return "totaal=$total ivLen=$ivLen aadLen=$aadLen cipherLen=$cipherLen rest(tag)=$rest";
}

function app_token_key(string $secret): string
{
    // Aparte sleutel voor tokens, afgeleid van de Zoom-secret.
    return hash_hmac('sha256', 'talkdeskstatus-app-token-v1', $secret, true);
}

function app_token_issue(array $ctx, string $secret, int $ttl): string
{
    $payload = b64url_encode(json_encode([
        'exp' => time() + $ttl,
        'aid' => (string)($ctx['aid'] ?? ''),
        'uid' => (string)($ctx['uid'] ?? ''),
    ]));
    $sig = b64url_encode(hash_hmac('sha256', $payload, app_token_key($secret), true));
    return $payload . '.' . $sig;
}

function app_token_verify(string $token, string $secret): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 2 || strlen($token) > 1024) {
        return false;
    }

    $expected = b64url_encode(hash_hmac('sha256', $parts[0], app_token_key($secret), true));
    if (!hash_equals($expected, $parts[1])) {
        return false;
    }

    $json = b64url_decode($parts[0]);
    $payload = $json === null ? null : json_decode($json, true);

    return is_array($payload)
        && isset($payload['exp'])
        && (int)$payload['exp'] > time();
}

function zoom_secret_or_fail(): string
{
    $secret = (string)getenv('ZOOM_CLIENT_SECRET');
    if ($secret === '') {
        error_log('talkdeskstatus: ZOOM_CLIENT_SECRET ontbreekt');
        http_response_code(500);
        exit('Server niet correct geconfigureerd.');
    }
    return $secret;
}

/**
 * Voor app.php: controleert de Zoom-context en geeft een app-token terug.
 * Faalt dicht: zonder geldige, verse Zoom-context krijgt niemand de pagina.
 */
function zoom_require_context(): string
{
    $secret = zoom_secret_or_fail();
    $header = $_SERVER['HTTP_X_ZOOM_APP_CONTEXT'] ?? '';

    // Primaire secret + optionele extra secrets (ZOOM_CLIENT_SECRET_ALT, komma-gescheiden),
    // bijv. de development-secret tijdens een test. Gebruik ALT niet in productie.
    $secrets = [$secret];
    foreach (explode(',', (string)getenv('ZOOM_CLIENT_SECRET_ALT')) as $alt) {
        $alt = trim($alt);
        if ($alt !== '') {
            $secrets[] = $alt;
        }
    }

    $ctx = null;
    $matched = -1;
    if ($header !== '') {
        foreach ($secrets as $i => $candidate) {
            $c = zoom_decrypt_context($header, $candidate);
            if ($c !== null) {
                $ctx = $c;
                $matched = $i;
                break;
            }
        }
    }
    if ($matched > 0) {
        error_log('talkdeskstatus: context ontsleuteld met een ALT-secret (niet de primaire)');
    }

    if ($header === '') {
        error_log('talkdeskstatus: GEEN x-zoom-app-context header ontvangen (redirect, proxy of verkeerde Home URL?)');
    } elseif ($ctx === null) {
        error_log('talkdeskstatus: x-zoom-app-context ontvangen (' . strlen($header) . ' tekens) maar ontsleutelen mislukt (controleer ZOOM_CLIENT_SECRET)');
    }

    if ($ctx === null && getenv('ZOOM_LOG_ACCOUNT_ID') === '1') {
        // Diagnosemodus: laat zien waarom de context niet geldig is. Geen secrets of waarden.
        $ua = preg_replace('/[^\x20-\x7E]/', '?', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300));
        $params = implode(',', array_map(static fn($k) => preg_replace('/[^A-Za-z0-9_]/', '?', (string)$k), array_keys($_GET)));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('DIAGNOSE' . PHP_EOL
            . 'Context-header: ' . ($header === '' ? 'NIET ontvangen' : 'ontvangen (' . strlen($header) . ' tekens) maar ontsleutelen mislukt') . PHP_EOL
            . 'Opbouw header: ' . zoom_context_structure($header) . PHP_EOL
            . 'Secrets geprobeerd: ' . count($secrets) . PHP_EOL
            . 'Query-parameternamen: ' . ($params === '' ? '(geen)' : $params) . PHP_EOL
            . 'User-Agent: ' . $ua . PHP_EOL
            . 'Zet ZOOM_LOG_ACCOUNT_ID weer op 0.');
    }

    if ($ctx === null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Open deze app vanuit de Zoom-client.');
    }

    // Replay-bescherming: de header is maar kort geldig (ts is in ms of s).
    if (isset($ctx['ts']) && is_numeric($ctx['ts'])) {
        $ts = (float)$ctx['ts'];
        $ts = $ts > 1e12 ? $ts / 1000 : $ts;
        if (abs(time() - $ts) > 300) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Sessie verlopen. Heropen de app in Zoom.');
        }
    }

    $aid = (string)($ctx['aid'] ?? '');

    // Hulpmiddel om je eigen account-id te vinden (zet daarna weer op 0).
    // Toont het id op het scherm in plaats van de app. Het account-id is geen secret
    // en dit is alleen zichtbaar na een geldige, ontsleutelde Zoom-context.
    if (getenv('ZOOM_LOG_ACCOUNT_ID') === '1') {
        error_log('talkdeskstatus: Zoom account-id in context = "' . $aid . '"');
        header('Content-Type: text/plain; charset=utf-8');
        $fields = [];
        foreach ($ctx as $k => $v) {
            $fields[] = $k . ' (' . gettype($v) . (is_scalar($v) ? ', lengte ' . strlen((string)$v) : '') . ')';
        }
        exit('Context-header: ' . strlen($header) . ' tekens, ontsleuteld met ' . ($matched === 0 ? 'de primaire secret' : 'een ALT-secret') . PHP_EOL
            . 'Opbouw header: ' . zoom_context_structure($header) . PHP_EOL
            . 'Zoom account-id in context: "' . $aid . '"' . PHP_EOL
            . 'Velden in context (alleen namen): ' . implode(', ', $fields) . PHP_EOL
            . 'Zet ZOOM_LOG_ACCOUNT_ID weer op 0.');
    }

    // Optioneel: beperk tot specifieke Zoom-accounts. Is de lijst ingesteld, dan
    // faalt dit dicht (ook als er geen aid in de context zit).
    $allowed = array_values(array_filter(array_map('trim', explode(',', (string)getenv('ZOOM_ALLOWED_ACCOUNT_ID')))));
    if ($allowed !== [] && !in_array($aid, $allowed, true)) {
        error_log('talkdeskstatus: Zoom account-id niet toegestaan');
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Geen toegang.');
    }

    $ttl = (int)(getenv('APP_TOKEN_TTL') ?: 28800);
    return app_token_issue($ctx, $secret, max(300, $ttl));
}

/** Voor status.php: controleert het X-App-Token. */
function app_require_token(): void
{
    $secret = zoom_secret_or_fail();
    $token  = (string)($_SERVER['HTTP_X_APP_TOKEN'] ?? '');

    if ($token === '' || !app_token_verify($token, $secret)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'error' => 'Niet geautoriseerd.']);
        exit;
    }
}