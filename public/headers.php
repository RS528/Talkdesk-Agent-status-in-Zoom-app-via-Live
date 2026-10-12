<?php
declare(strict_types=1);

/*
 * Beveiligingsheaders. Include dit bestand VOORDAT er output wordt verstuurd.
 *
 * - Voor JSON-endpoints: define('API_RESPONSE', true) vóór de require.
 * - Roep load_env() aan vóór deze include (CSP_REPORT_ONLY wordt uit .env gelezen).
 * - Beschikbaar na include: $cspNonce (voor <script nonce> en <style nonce>).
 */

// Nooit PHP-fouten/paden naar de browser sturen.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header_remove('X-Powered-By');

header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$cspNonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

if (defined('API_RESPONSE')) {
    // JSON-endpoint: niets laden, nergens in een frame.
    $csp = "default-src 'none'; frame-ancestors 'none'";
} else {
    $csp = implode('; ', [
        "default-src 'none'",
        "script-src 'self' 'nonce-{$cspNonce}' https://appssdk.zoom.us",
        "style-src 'nonce-{$cspNonce}'",
        "img-src 'self' data:",
        "connect-src 'self' https://*.zoom.us",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'self' https://*.zoom.us https://*.zoom.com",
    ]);
}

// Tip: zet CSP_REPORT_ONLY=1 in .env om de CSP eerst te testen zonder iets te blokkeren.
$cspHeader = getenv('CSP_REPORT_ONLY') === '1'
    ? 'Content-Security-Policy-Report-Only'
    : 'Content-Security-Policy';

header($cspHeader . ': ' . $csp);
