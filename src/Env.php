<?php
declare(strict_types=1);

/** Читает файл .env; переменные, уже заданные в окружении сервера, не перезаписываются. */
function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim(preg_replace('/^export\s+/', '', trim($name)));
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($name !== '' && getenv($name) === false) {
            putenv("$name=$value");
        }
    }
}

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

/** Относительные пути считаются от корня проекта. */
function resolvePath(string $path): string
{
    if ($path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path))) {
        return $path;
    }
    return BOT_ROOT . '/' . $path;
}

/**
 * Корневой сертификат Минцифры для MAX API: RUSSIAN_CA_BUNDLE или certs/russian_trusted_root_ca.pem.
 * RUSSIAN_CA_BUNDLE=system оставляет системное хранилище сертификатов.
 */
function russianCaBundle(): ?string
{
    $configured = env('RUSSIAN_CA_BUNDLE');
    if ($configured === 'system') {
        return null;
    }
    if ($configured !== null) {
        return $configured;
    }
    $bundled = BOT_ROOT . '/certs/russian_trusted_root_ca.pem';
    return is_readable($bundled) ? $bundled : null;
}
