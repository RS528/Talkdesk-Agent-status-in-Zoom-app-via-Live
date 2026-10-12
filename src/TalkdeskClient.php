<?php

/*
 * =========================================================
 * TALKDESK BASE URL
 * =========================================================
 *
 * Deze constant is leidend.
 *
 * Belangrijk:
 * talkdesk_fetch() gebruikte eerder getenv('TALKDESK_BASE_URL').
 * Daardoor kon de waarde ontbreken terwijl deze constant wel
 * correct was gedefinieerd.
 */

if (!defined('TALKDESK_BASE_URL')) {
    define(
        'TALKDESK_BASE_URL',
        'https://api.talkdeskapp.eu'
    );
}


/**
 * Talkdesk Guardian Users + Reference Hub Numbers
 * ------------------------------------------------
 *
 * FLOW:
 *
 * 1. Guardian Users:
 *
 *    /guardian/users?per_page=250&page=1
 *
 *    Gebruikt:
 *    - name
 *    - email
 *    - presence
 *
 * 2. Reference Hub:
 *
 *    /data-tables/6abfa8e48c1f8b54740ab65c/rows
 *
 *    Gebruikt:
 *    - email
 *    - nummer
 *
 * 3. Match:
 *
 *    Guardian.email
 *        =
 *    ReferenceHub.email
 *
 * 4. Eindresultaat:
 *
 *    name
 *    number
 *    status
 *    statusLabel
 *
 * Email wordt NIET teruggegeven aan de frontend.
 */


/* =========================================================
 * CONFIGURATIE
 * ========================================================= */

/*
 * Cachemap. Zet bij voorkeur CACHE_DIR in .env op een map BUITEN de webroot,
 * bijv. /var/lib/talkdeskstatus (chmod 750, eigenaar = webserver-gebruiker).
 * load_env() moet dus VOOR het includen van dit bestand worden aangeroepen.
 */
if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', rtrim((string)(getenv('CACHE_DIR') ?: __DIR__ . '/../cache'), '/'));
}

const LOG_FILE = CACHE_DIR . '/talkdesk.log';

/*
 * Maximale grootte van de logfile.
 *
 * 10 MiB = 10 * 1024 * 1024 bytes.
 *
 * Zodra de logfile groter wordt, worden de oudste
 * logregels verwijderd totdat alleen de meest recente
 * data overblijft.
 */
const MAX_LOG_SIZE = 10 * 1024 * 1024;

const REFERENCE_TABLE_ID = '6abfa8e48c1f8b54740ab65c';

/*
 * Cacheduur:
 * 900 seconden = 15 minuten.
 */
const CACHE_TTL = 900;

/*
 * Maximum aantal pagina's.
 */
const MAX_PAGES = 100;


/* =========================================================
 * DEBUG / LOGGING
 * ========================================================= */

/**
 * Houdt de logfile binnen MAX_LOG_SIZE.
 *
 * De oudste data wordt verwijderd.
 * De nieuwste logdata blijft behouden.
 *
 * De functie verwacht een reeds geopend en gelockt
 * logfile-handle.
 */
function trim_log_file($handle): void
{
    $stats = fstat($handle);

    if (
        $stats === false ||
        !isset($stats['size'])
    ) {
        return;
    }

    $fileSize = (int)$stats['size'];

    if ($fileSize <= MAX_LOG_SIZE) {
        return;
    }

    /*
     * Lees alleen de laatste MAX_LOG_SIZE bytes.
     */
    if (
        fseek(
            $handle,
            -MAX_LOG_SIZE,
            SEEK_END
        ) !== 0
    ) {
        return;
    }

    $data = fread(
        $handle,
        MAX_LOG_SIZE
    );

    if ($data === false) {
        return;
    }

    /*
     * We willen niet midden in een logregel beginnen.
     *
     * Zoek daarom de eerste newline in de behouden data
     * en begin daarna met de eerste volledige logregel.
     */
    $newlinePosition = strpos(
        $data,
        PHP_EOL
    );

    if ($newlinePosition !== false) {

        $data =
            substr(
                $data,
                $newlinePosition + strlen(PHP_EOL)
            );
    }

    /*
     * Ga terug naar het begin van het bestand.
     */
    if (
        fseek(
            $handle,
            0,
            SEEK_SET
        ) !== 0
    ) {
        return;
    }

    /*
     * Schrijf uitsluitend de nieuwste data terug.
     */
    $written = fwrite(
        $handle,
        $data
    );

    if ($written === false) {
        return;
    }

    /*
     * Verwijder de oude rest van het bestand.
     */
    ftruncate(
        $handle,
        strlen($data)
    );

    fflush($handle);
}


function log_debug(
    string $message,
    array $context = [],
    string $level = 'INFO'
): void {

    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0750, true);
    }

    $timestamp = date('Y-m-d H:i:s');

    $line =
        '[' . $timestamp . '] ' .
        '[' . $level . '] ' .
        $message;

    if (!empty($context)) {

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }

    $line .= PHP_EOL;

    /*
     * Openen als append.
     */
    $handle = fopen(
        LOG_FILE,
        'ab'
    );

    if ($handle === false) {
        return;
    }

    /*
     * Lock voorkomt dat meerdere gelijktijdige
     * requests de logfile tegelijkertijd aanpassen.
     */
    if (
        !flock(
            $handle,
            LOCK_EX
        )
    ) {
        fclose($handle);
        return;
    }

    /*
     * Schrijf nieuwe logregel.
     */
    fwrite(
        $handle,
        $line
    );

    fflush($handle);

    /*
     * Controleer direct daarna of de logfile
     * groter is geworden dan 10 MiB.
     *
     * Indien nodig worden de oudste regels verwijderd.
     */
    trim_log_file($handle);

    /*
     * Lock vrijgeven en bestand sluiten.
     */
    flock(
        $handle,
        LOCK_UN
    );

    fclose($handle);
}


function log_info(
    string $message,
    array $context = []
): void {

    log_debug(
        $message,
        $context,
        'INFO'
    );
}


function log_warning(
    string $message,
    array $context = []
): void {

    log_debug(
        $message,
        $context,
        'WARNING'
    );
}


function log_error(
    string $message,
    array $context = []
): void {

    log_debug(
        $message,
        $context,
        'ERROR'
    );
}


/* =========================================================
 * CACHE
 * ========================================================= */

function cache_path(string $name): string
{
    return CACHE_DIR . '/' . $name . '.json';
}


/**
 * Leest cache.
 *
 * De TTL wordt hier NIET globaal toegepast.
 * De aanroeper bepaalt of de inhoud nog geldig is.
 */
function cache_get(string $name): ?array
{
    $path = cache_path($name);

    if (!file_exists($path)) {

        log_info(
            'Cache bestaat niet',
            [
                'cache' => $name
            ]
        );

        return null;
    }

    $contents = file_get_contents($path);

    if ($contents === false) {

        log_warning(
            'Cachebestand kon niet worden gelezen',
            [
                'cache' => $name
            ]
        );

        return null;
    }

    $data = json_decode(
        $contents,
        true
    );

    if (!is_array($data)) {

        log_warning(
            'Cache bevat geen geldige JSON',
            [
                'cache' => $name
            ]
        );

        return null;
    }

    return $data;
}


function cache_set(
    string $name,
    array $data
): void {

    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0750, true);
    }

    $path = cache_path($name);

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {

        log_error(
            'Cache kon niet naar JSON worden omgezet',
            [
                'cache' => $name
            ]
        );

        return;
    }

    $success = file_put_contents(
        $path,
        $json,
        LOCK_EX
    );

    if ($success !== false) {
        @chmod($path, 0600);
    }

    if ($success === false) {

        log_error(
            'Cache kon niet worden opgeslagen',
            [
                'cache' => $name
            ]
        );

        return;
    }

    log_info(
        'Cache opgeslagen',
        [
            'cache' => $name
        ]
    );
}


/* =========================================================
 * ACCESS TOKEN
 * ========================================================= */

function get_access_token(): string
{
    log_info(
        'Access token controleren'
    );

    $cached =
        cache_get('token');


    if (
        is_array($cached) &&
        !empty($cached['access_token']) &&
        !empty($cached['expires_at']) &&
        (int)$cached['expires_at'] > time()
    ) {

        log_info(
            'Geldig access token uit cache gebruikt'
        );

        return (string)$cached['access_token'];
    }


    log_info(
        'Geen geldig access token in cache; nieuw token ophalen'
    );


    $clientId =
        getenv('TALKDESK_CLIENT_ID');

    $clientSecret =
        getenv('TALKDESK_CLIENT_SECRET');

    $tokenUrl =
        getenv('TALKDESK_TOKEN_URL');


    if (!$clientId) {

        throw new RuntimeException(
            'TALKDESK_CLIENT_ID ontbreekt.'
        );
    }


    if (!$clientSecret) {

        throw new RuntimeException(
            'TALKDESK_CLIENT_SECRET ontbreekt.'
        );
    }


    if (!$tokenUrl) {

        throw new RuntimeException(
            'TALKDESK_TOKEN_URL ontbreekt.'
        );
    }


    $basicAuth =
        base64_encode(
            $clientId . ':' . $clientSecret
        );


    $ch =
        curl_init($tokenUrl);


    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST =>
                true,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_HTTPHEADER =>
                [
                    "Authorization: Basic $basicAuth",
                    'Content-Type: application/x-www-form-urlencoded',
                ],

            CURLOPT_POSTFIELDS =>
                http_build_query(
                    [
                        'grant_type' =>
                            'client_credentials',
                    ]
                ),

            CURLOPT_TIMEOUT =>
                30,

            CURLOPT_CONNECTTIMEOUT =>
                10,
        ]
    );


    $response =
        curl_exec($ch);


    $status =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    $curlError =
        curl_error($ch);


    curl_close($ch);


    log_info(
        'Token API response ontvangen',
        [
            'http_status' =>
                $status,

            'response_length' =>
                strlen((string)$response),

            'curl_error' =>
                $curlError ?: null
        ]
    );


    if (
        $response === false ||
        $status < 200 ||
        $status >= 300
    ) {

        log_error(
            'Token ophalen mislukt',
            [
                'http_status' =>
                    $status,

                'curl_error' =>
                    $curlError ?: null
            ]
        );

        throw new RuntimeException(
            "Token ophalen mislukt: HTTP $status"
        );
    }


    $data =
        json_decode(
            $response,
            true
        );


    if (!is_array($data)) {

        log_error(
            'Token response is geen geldige JSON'
        );

        throw new RuntimeException(
            'Token response bevat geen geldige JSON.'
        );
    }


    if (empty($data['access_token'])) {

        log_error(
            'Geen access_token aanwezig in token response'
        );

        throw new RuntimeException(
            'Geen access_token in token-response.'
        );
    }


    $expiresIn =
        (int)(
            $data['expires_in'] ?? 3600
        );


    /*
     * 60 seconden veiligheidsmarge.
     */
    $expiresAt =
        time() +
        max(60, $expiresIn - 60);


    cache_set(
        'token',
        [
            'access_token' =>
                $data['access_token'],

            'expires_at' =>
                $expiresAt
        ]
    );


    log_info(
        'Nieuw access token succesvol opgeslagen',
        [
            'expires_in' =>
                $expiresIn
        ]
    );


    return (string)$data['access_token'];
}


/* =========================================================
 * TALKDESK API REQUEST
 * ========================================================= */

function talkdesk_fetch(
    string $path,
    string $method = 'GET',
    ?array $body = null
): array {

    /*
     * Gebruik de PHP constant.
     *
     * NIET getenv().
     */
    $baseUrl =
        rtrim(
            TALKDESK_BASE_URL,
            '/'
        );


    if ($baseUrl === '') {

        throw new RuntimeException(
            'TALKDESK_BASE_URL ontbreekt.'
        );
    }


    /*
     * Absolute URL ondersteunen.
     */
    if (
        str_starts_with(
            $path,
            'http://'
        ) ||
        str_starts_with(
            $path,
            'https://'
        )
    ) {

        $url = $path;

    } else {

        $url =
            $baseUrl .
            '/' .
            ltrim(
                $path,
                '/'
            );
    }


    log_info(
        'Talkdesk API request',
        [
            'method' =>
                $method,

            'url' =>
                $url
        ]
    );


    $token =
        get_access_token();


    $ch =
        curl_init($url);


    $headers = [
        "Authorization: Bearer $token",
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
    ];


    $options = [

        CURLOPT_CUSTOMREQUEST =>
            strtoupper($method),

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_HTTPHEADER =>
            $headers,

        CURLOPT_TIMEOUT =>
            30,

        CURLOPT_CONNECTTIMEOUT =>
            10,

        CURLOPT_FOLLOWLOCATION =>
            false,
    ];


    if ($body !== null) {

        $encodedBody =
            json_encode(
                $body,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );


        if ($encodedBody === false) {

            throw new RuntimeException(
                'Request body kon niet naar JSON worden omgezet.'
            );
        }


        $options[CURLOPT_POSTFIELDS] =
            $encodedBody;
    }


    curl_setopt_array(
        $ch,
        $options
    );


    $startTime =
        microtime(true);


    $response =
        curl_exec($ch);


    $duration =
        round(
            microtime(true) -
            $startTime,
            3
        );


    $status =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    $curlError =
        curl_error($ch);


    curl_close($ch);


    log_info(
        'Talkdesk API response',
        [
            'method' =>
                $method,

            'url' =>
                $url,

            'http_status' =>
                $status,

            'duration_seconds' =>
                $duration,

            'response_length' =>
                strlen((string)$response),

            'curl_error' =>
                $curlError ?: null
        ]
    );


    if (
        $response === false ||
        $status < 200 ||
        $status >= 300
    ) {

        log_error(
            'Talkdesk API request mislukt',
            [
                'http_status' =>
                    $status,

                'curl_error' =>
                    $curlError ?: null
            ]
        );

        throw new RuntimeException(
            "Talkdesk API fout: HTTP $status"
        );
    }


    $decoded =
        json_decode(
            $response,
            true
        );


    if (!is_array($decoded)) {

        log_error(
            'Talkdesk response is geen geldige JSON',
            [
                'http_status' =>
                    $status
            ]
        );

        throw new RuntimeException(
            'Talkdesk API gaf geen geldige JSON terug.'
        );
    }


    return $decoded;
}


/* =========================================================
 * PAGINATION
 * ========================================================= */

/**
 * Haalt alle pagina's van een endpoint op.
 *
 * Deze functie ondersteunt:
 *
 * 1. _embedded als directe lijst
 *
 *    "_embedded": [
 *        {...},
 *        {...}
 *    ]
 *
 * 2. _embedded met een entries-key
 *
 *    "_embedded": {
 *        "entries": [...]
 *    }
 *
 * 3. Andere array-lijsten.
 */
function fetch_all_pages(
    string $path,
    int $maxPages = MAX_PAGES
): array {

    log_info(
        'Start pagination',
        [
            'path' =>
                $path
        ]
    );


    $allEntries = [];

    $page = 1;


    while ($page <= $maxPages) {

        $separator =
            str_contains(
                $path,
                '?'
            )
                ? '&'
                : '?';


        $pagePath =
            $path .
            $separator .
            'page=' .
            $page;


        log_info(
            'Pagina ophalen',
            [
                'page' =>
                    $page,

                'path' =>
                    $pagePath
            ]
        );


        $response =
            talkdesk_fetch(
                $pagePath
            );


        $entries = [];


        /*
         * =====================================================
         * _embedded
         * =====================================================
         */
        if (
            isset($response['_embedded']) &&
            is_array($response['_embedded'])
        ) {

            $embedded =
                $response['_embedded'];


            /*
             * _embedded is direct een lijst.
             *
             * Bijvoorbeeld:
             *
             * "_embedded": [
             *     {...},
             *     {...}
             * ]
             */
            if (array_is_list($embedded)) {

                $entries =
                    $embedded;

            /*
             * _embedded bevat bijvoorbeeld:
             *
             * {
             *     "entries": [...]
             * }
             */
            } else {

                foreach (
                    [
                        'entries',
                        'rows',
                        'users',
                        'items',
                        'data'
                    ]
                    as $key
                ) {

                    if (
                        isset($embedded[$key]) &&
                        is_array($embedded[$key])
                    ) {

                        $entries =
                            $embedded[$key];

                        break;
                    }
                }
            }
        }


        /*
         * =====================================================
         * Directe lijst
         * =====================================================
         */
        if (
            empty($entries) &&
            array_is_list($response)
        ) {

            $entries =
                $response;
        }


        /*
         * =====================================================
         * Fallback voor een response met rows/items/data
         * =====================================================
         */
        if (empty($entries)) {

            foreach (
                [
                    'rows',
                    'entries',
                    'items',
                    'data'
                ]
                as $key
            ) {

                if (
                    isset($response[$key]) &&
                    is_array($response[$key])
                ) {

                    $candidate =
                        $response[$key];


                    if (
                        array_is_list($candidate)
                    ) {

                        $entries =
                            $candidate;

                        break;
                    }
                }
            }
        }


        if (!is_array($entries)) {
            $entries = [];
        }


        $entryCount =
            count($entries);


        $allEntries =
            array_merge(
                $allEntries,
                $entries
            );


        /*
         * Bij voorkeur gebruiken we total_pages.
         */
        $totalPages =
            isset($response['total_pages'])
                ? (int)$response['total_pages']
                : null;


        $perPage =
            isset($response['per_page'])
                ? (int)$response['per_page']
                : $entryCount;


        log_info(
            'Pagina verwerkt',
            [
                'page' =>
                    $page,

                'entries' =>
                    $entryCount,

                'per_page' =>
                    $perPage,

                'total_pages' =>
                    $totalPages,

                'total_so_far' =>
                    count($allEntries)
            ]
        );


        /*
         * Als Talkdesk expliciet total_pages geeft,
         * gebruiken we die informatie.
         */
        if (
            $totalPages !== null &&
            $totalPages > 0
        ) {

            if ($page >= $totalPages) {

                log_info(
                    'Pagination afgerond via total_pages',
                    [
                        'last_page' =>
                            $page,

                        'total_pages' =>
                            $totalPages,

                        'total_entries' =>
                            count($allEntries)
                    ]
                );

                break;
            }

        } else {

            /*
             * Geen total_pages:
             *
             * Stop bij een lege pagina of wanneer
             * minder records dan per_page terugkomen.
             */
            if (
                $entryCount === 0 ||
                (
                    $perPage > 0 &&
                    $entryCount < $perPage
                )
            ) {

                log_info(
                    'Pagination afgerond',
                    [
                        'last_page' =>
                            $page,

                        'total_entries' =>
                            count($allEntries)
                    ]
                );

                break;
            }
        }


        $page++;
    }


    if ($page > $maxPages) {

        log_warning(
            'MAX_PAGES bereikt',
            [
                'max_pages' =>
                    $maxPages,

                'total_entries' =>
                    count($allEntries)
            ]
        );
    }


    return $allEntries;
}


/* =========================================================
 * EMAIL NORMALISEREN
 * ========================================================= */

function normalize_email(
    ?string $email
): string {

    return strtolower(
        trim(
            (string)$email
        )
    );
}


/* =========================================================
 * GUARDIAN USERS
 * ========================================================= */

function get_guardian_users(): array
{
    $start =
        microtime(true);


    log_info(
        '========================================'
    );

    log_info(
        'START get_guardian_users()'
    );

    log_info(
        '========================================'
    );


    try {

        /*
         * Guardian cache bewust uit.
         *
         * We willen tijdens deze fase altijd de
         * actuele Guardian users ophalen.
         */
        log_info(
            'Guardian API ophalen - CACHE UITGESCHAKELD'
        );


        $response =
            talkdesk_fetch(
                '/guardian/users?per_page=250&page=1'
            );


        if (!is_array($response)) {

            log_error(
                'Guardian response is geen array',
                [
                    'type' =>
                        gettype($response)
                ]
            );

            return [];
        }


        log_info(
            'Guardian response ontvangen',
            [
                'top_level_keys' =>
                    array_keys($response)
            ]
        );


        /*
         * Guardian gebruikt _embedded als DIRECTE lijst
         * van users.
         */
        $users = [];


        if (
            isset($response['_embedded']) &&
            is_array($response['_embedded'])
        ) {

            $users =
                $response['_embedded'];
        }


        log_info(
            'Guardian users te verwerken',
            [
                'count' =>
                    count($users)
            ]
        );


        $result = [];

        $skipped = 0;


        foreach (
            $users
            as $index => $user
        ) {

            if (!is_array($user)) {

                log_warning(
                    'Guardian user is geen array',
                    [
                        'index' =>
                            $index,

                        'type' =>
                            gettype($user)
                    ]
                );

                $skipped++;

                continue;
            }


            $name =
                $user['name']
                ?? $user['Name']
                ?? '';

            $email =
                $user['email']
                ?? $user['Email']
                ?? '';


            $presence =
                $user['presence']
                ?? $user['Presence']
                ?? '';

            $presenceType =
                $user['presence_type']
                ?? $user['PresenceType']
                ?? '';


            log_info(
                'Guardian user gevonden',
                [
                    'index' =>
                        $index,

                    'name' =>
                        $name,

                    'has_email' =>
                        !empty($email),

                    'presence' =>
                        $presence,

                    'presence_type' =>
                        $presenceType

                ]
            );


            if (
                trim((string)$email) === ''
            ) {

                log_warning(
                    'Guardian user overgeslagen: geen email',
                    [
                        'index' =>
                            $index,

                        'name' =>
                            $name
                    ]
                );

                $skipped++;

                continue;
            }


            $normalizedEmail =
                normalize_email(
                    $email
                );


            $result[] = [

                'name' =>
                    trim((string)$name),

                'email' =>
                    $normalizedEmail,

                'presence' =>
                    trim((string)$presence),

                'presence_type' =>
                    trim((string)$presenceType)

            ];
        }


        log_info(
            'Guardian users verwerkt',
            [
                'raw_count' =>
                    count($users),

                'valid' =>
                    count($result),

                'skipped' =>
                    $skipped,

                'duration_seconds' =>
                    round(
                        microtime(true) -
                        $start,
                        3
                    )
            ]
        );


        return $result;


    } catch (Throwable $e) {

        log_error(
            'Fout bij ophalen Guardian users',
            [
                'message' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine()
            ]
        );


        return [];
    }
}


/* =========================================================
 * REFERENCE HUB
 * ========================================================= */

/**
 * Haalt Reference Hub rows op.
 *
 * Tabel:
 *
 * InternalUsers
 *
 * ID:
 *
 * 6abfa8e48c1f8b54740ab65c
 *
 * Gebruikt:
 *
 * email
 * nummer
 */
/**
 * Haalt Reference Hub rows op.
 *
 * Tabel:
 * InternalUsers
 *
 * ID:
 * 6abfa8e48c1f8b54740ab65c
 *
 * Gebruikt:
 * - email
 * - nummer
 */
function get_reference_users(): array
{
    $start = microtime(true);

    log_info('========================================');
    log_info('START get_reference_users()');
    log_info('========================================');

    /*
     * Cache controleren.
     */
    $cached = cache_get('reference_users');

    if (
        is_array($cached) &&
        isset($cached['cached_at']) &&
        isset($cached['users']) &&
        is_array($cached['users'])
    ) {
        $cacheAge =
            time() -
            (int)$cached['cached_at'];

        if (
            $cacheAge >= 0 &&
            $cacheAge < CACHE_TTL
        ) {
            /*
             * Een lege cache nooit gebruiken.
             */
            if (count($cached['users']) > 0) {
                log_info(
                    'Reference Hub uit cache',
                    [
                        'count' =>
                            count($cached['users']),

                        'cache_age_seconds' =>
                            $cacheAge
                    ]
                );

                return $cached['users'];
            }

            log_warning(
                'Reference Hub cache bevat 0 users; cache wordt genegeerd'
            );
        }
    }

    /*
     * Reference Hub API ophalen.
     */
    $endpoint =
        '/data-tables/' .
        REFERENCE_TABLE_ID .
        '/rows';

    log_info(
        'Reference Hub API ophalen',
        [
            'table_id' =>
                REFERENCE_TABLE_ID,

            'endpoint' =>
                $endpoint
        ]
    );

    $rawRows =
        fetch_all_pages(
            $endpoint
        );

    log_info(
        'Ruwe Reference Hub rows ontvangen',
        [
            'count' =>
                count($rawRows)
        ]
    );

    /*
     * Reference Hub rows verwerken.
     *
     * De daadwerkelijke API-structuur is:
     *
     * [
     *     'id' => ...,
     *     'key' => ...,
     *     'value' => [
     *         'naam' => ...,
     *         'email' => ...,
     *         'nummer' => ...
     *     ]
     * ]
     *
     * We lezen daarom uitsluitend uit row['value'].
     */
    $users = [];
    $skipped = 0;

    foreach (
        $rawRows
        as $index => $row
    ) {
        if (!is_array($row)) {
            $skipped++;

            log_warning(
                'Reference Hub row is geen array',
                [
                    'row_index' =>
                        $index
                ]
            );

            continue;
        }

        /*
         * Data staat in row['value'].
         */
        $value = [];

        if (
            isset($row['value']) &&
            is_array($row['value'])
        ) {
            $value = $row['value'];
        }

        /*
         * Velden uit Reference Hub.
         */
        $name =
            isset($value['naam'])
                ? trim((string)$value['naam'])
                : '';

        $email =
            isset($value['email'])
                ? normalize_email($value['email'])
                : '';

        $number =
            isset($value['nummer'])
                ? trim((string)$value['nummer'])
                : '';

        /*
         * Zonder email kunnen we geen match maken
         * met Guardian.
         */
        if ($email === '') {
            $skipped++;

            log_warning(
                'Reference Hub row overgeslagen: geen email',
                [
                    'row_index' =>
                        $index
                ]
            );

            continue;
        }

        /*
         * Email wordt de interne index.
         */
        $users[$email] = [
            'name' =>
                $name,

            'number' =>
                $number
        ];

        log_info(
            'Reference Hub user gevonden',
            [
                'index' =>
                    $index,

                'name' =>
                    $name,

                'has_email' =>
                    true,

                'has_number' =>
                    $number !== ''
            ]
        );
    }

    log_info(
        'Reference Hub verwerkt',
        [
            'raw_rows' =>
                count($rawRows),

            'valid' =>
                count($users),

            'skipped' =>
                $skipped,

            'duration_seconds' =>
                round(
                    microtime(true) -
                    $start,
                    3
                )
        ]
    );

    /*
     * Alleen een niet-lege dataset cachen.
     */
    if (
        count($users) > 0
    ) {
        cache_set(
            'reference_users',
            [
                'cached_at' =>
                    time(),

                'users' =>
                    $users
            ]
        );
    } else {
        log_warning(
            'Reference Hub resultaat is leeg; cache wordt NIET overschreven'
        );
    }

    return $users;
}


/* =========================================================
 * USERS COMBINEREN
 * ========================================================= */

function get_status_list(): array
{
    $startTime =
        microtime(true);


    log_info(
        '========================================'
    );

    log_info(
        'START get_status_list()'
    );

    log_info(
        '========================================'
    );


    /*
     * Guardian.
     */
    $guardianUsers =
        get_guardian_users();


    log_info(
        'Guardian users beschikbaar',
        [
            'count' =>
                count($guardianUsers)
        ]
    );


    /*
     * Reference Hub.
     */
    $referenceUsers =
        get_reference_users();


    log_info(
        'Reference Hub users beschikbaar',
        [
            'count' =>
                count($referenceUsers)
        ]
    );


    $result = [];

    $matched = 0;

    $unmatched = 0;

    $withoutNumber = 0;


    foreach (
        $guardianUsers
        as $user
    ) {

        $email =
            normalize_email(
                $user['email'] ?? null
            );


        if ($email === '') {

            log_warning(
                'User overgeslagen: email ontbreekt',
                [
                    'name' =>
                        $user['name'] ?? null
                ]
            );

            continue;
        }


        /*
         * Match met Reference Hub.
         */
        $reference =
            $referenceUsers[$email]
            ?? null;


        if (
            $reference === null
        ) {

            $unmatched++;


            /*
             * Geen email in de logfile.
             */
            log_warning(
                'Geen Reference Hub match',
                [
                    'name' =>
                        $user['name'] ?? 'Onbekend'
                ]
            );


            continue;
        }


        /*
         * Nummer.
         */
        $number =
            $reference['number']
            ?? null;


        if (
            $number === null ||
            $number === ''
        ) {

            $withoutNumber++;


            log_warning(
                'Reference Hub match gevonden maar nummer ontbreekt',
                [
                    'name' =>
                        $user['name'] ?? 'Onbekend'
                ]
            );


            continue;
        }


        /*
         * Presence.
         */
        $presence =
            trim(
                (string)(
                    $user['presence']
                    ?? ''
                )
            );


        if ($presence === '') {
            $presence = 'unknown';
        }


        /*
         * Definitieve frontend-output.
         *
         * Geen email.
         */
        $result[] = [

            'name' =>
                $user['name'] ?? 'Onbekend',

            'number' =>
                $number,

            'status' =>
                $presence,

            'statusType' =>
                $user['presence_type'] ?? 'unknown',

            'statusLabel' =>
                $presence ?: 'Onbekend'
        ];


        $matched++;
    }


    $duration =
        round(
            microtime(true) -
            $startTime,
            3
        );


    log_info(
        '========================================'
    );


    log_info(
        'RESULTAAT',
        [
            'guardian_users' =>
                count($guardianUsers),

            'reference_users' =>
                count($referenceUsers),

            'matched' =>
                $matched,

            'unmatched' =>
                $unmatched,

            'without_number' =>
                $withoutNumber,

            'final_result' =>
                count($result),

            'duration_seconds' =>
                $duration
        ]
    );


    log_info(
        'EINDE get_status_list()'
    );


    log_info(
        '========================================'
    );


    return $result;
}

/**
 * Geeft de actuele statuslijst uit de tijdelijke cache.
 *
 * Als de cache ontbreekt of ouder is dan STATUS_CACHE_TTL,
 * wordt get_status_list() uitgevoerd en het resultaat opnieuw
 * opgeslagen.
 */
function get_cached_status_list(): array
{
    $cacheFile = CACHE_DIR . '/status.json';
    $lockFile  = CACHE_DIR . '/status.lock';

    $cacheTtl = 8; // seconden


    /**
     * Interne functie om de cache te lezen.
     */
    $readCache = function () use ($cacheFile): ?array {

        if (!file_exists($cacheFile)) {
            return null;
        }

        $cached = file_get_contents($cacheFile);

        if ($cached === false || trim($cached) === '') {
            return null;
        }

        $data = json_decode($cached, true);

        if (
            !is_array($data) ||
            !isset($data['users']) ||
            !is_array($data['users'])
        ) {
            return null;
        }

        return $data;
    };


    /**
     * Controleer of de cache nog geldig is.
     */
    $cacheIsValid = function () use ($cacheFile, $cacheTtl): bool {

        if (!file_exists($cacheFile)) {
            return false;
        }

        return (time() - filemtime($cacheFile)) < $cacheTtl;
    };


    /*
     * Eerst zonder lock kijken.
     */
    if ($cacheIsValid()) {

        $data = $readCache();

        if ($data !== null) {
            return $data['users'];
        }
    }


    /*
     * Cache is verlopen.
     * Zorg dat slechts één request Talkdesk aanspreekt.
     */
    $lockHandle = fopen($lockFile, 'c');

    if ($lockHandle === false) {
        throw new RuntimeException(
            'Kan status-cache lock niet openen.'
        );
    }

    try {

        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException(
                'Kan status-cache lock niet verkrijgen.'
            );
        }


        /*
         * Opnieuw controleren nadat de lock is verkregen.
         *
         * Een ander request kan de cache inmiddels
         * al vernieuwd hebben.
         */
        if ($cacheIsValid()) {

            $data = $readCache();

            if ($data !== null) {
                return $data['users'];
            }
        }


        /*
         * Cache ontbreekt of is verlopen.
         *
         * Nu pas wordt Talkdesk geraadpleegd.
         */
        $users = get_status_list();


        /*
         * Nieuwe cachedata.
         */
        $cacheData = [
            'success' => true,
            'count'   => count($users),
            'users'   => $users
        ];


        /*
         * Eerst naar een tijdelijk bestand schrijven.
         * Daarna atomair vervangen.
         */
        $temporaryFile = $cacheFile . '.tmp';

        $written = file_put_contents(
            $temporaryFile,
            json_encode(
                $cacheData,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'Kan status-cache niet schrijven.'
            );
        }

        if (!rename($temporaryFile, $cacheFile)) {

            @unlink($temporaryFile);

            throw new RuntimeException(
                'Kan status-cache niet vervangen.'
            );
        }


        return $users;

    } finally {

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

