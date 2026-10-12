<?php

/**
 * Minimale .env-loader, zonder Composer-dependency nodig.
 * Leest KEY=VALUE regels uit .env en zet ze in de omgeving.
 */
function load_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
