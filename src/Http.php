<?php
declare(strict_types=1);

/** Ошибка HTTP-запроса: status = 0 для сбоев соединения, иначе код ответа сервера. */
class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}

/**
 * Выполняет запрос и возвращает [код ответа, тело]. Исключение — только при сбое соединения.
 * $caBundle — путь к PEM с корневым сертификатом (например, Минцифры), если его нет в системе.
 *
 * Транспорт: HTTP_TRANSPORT=curl|stream. По умолчанию curl, а если он собран с NSS
 * (CentOS 7 и т.п.), то потоки PHP на OpenSSL: NSS не разбирает сертификат MAX
 * («Unrecognized Object Identifier»).
 */
function httpRequest(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    float $timeout = 20.0,
    ?string $caBundle = null
): array {
    if (httpTransport() === 'stream') {
        return httpRequestStream($method, $url, $headers, $body, $timeout, $caBundle);
    }
    return httpRequestCurl($method, $url, $headers, $body, $timeout, $caBundle);
}

function httpTransport(): string
{
    $configured = strtolower(env('HTTP_TRANSPORT', '') ?? '');
    if ($configured === 'curl' || $configured === 'stream') {
        return $configured;
    }
    $ssl = function_exists('curl_version') ? (curl_version()['ssl_version'] ?? '') : '';
    return !function_exists('curl_init') || preg_match('#^NSS\b#i', $ssl) ? 'stream' : 'curl';
}

function httpRequestCurl(
    string $method,
    string $url,
    array $headers,
    ?string $body,
    float $timeout,
    ?string $caBundle
): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int)ceil($timeout),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    if ($caBundle !== null && $caBundle !== '') {
        $options[CURLOPT_CAINFO] = resolvePath($caBundle);
    }
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($response === false) {
        throw new HttpException("Запрос не удался: $error");
    }
    return [$status, (string)$response];
}

/** Запрос через потоки PHP (расширение openssl); нужен allow_url_fopen=On. */
function httpRequestStream(
    string $method,
    string $url,
    array $headers,
    ?string $body,
    float $timeout,
    ?string $caBundle
): array {
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        throw new HttpException('Запрос не удался: в php.ini выключен allow_url_fopen (или задайте HTTP_TRANSPORT=curl)');
    }

    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($caBundle !== null && $caBundle !== '') {
        $ssl['cafile'] = resolvePath($caBundle);
    }
    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => $timeout,
        'ignore_errors' => true,
        'follow_location' => 0,
    ];
    if ($body !== null) {
        $http['content'] = $body;
    }

    $response = @file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
    $responseHeaders = $http_response_header ?? [];
    if ($response === false || !$responseHeaders) {
        $error = error_get_last()['message'] ?? 'неизвестная ошибка';
        throw new HttpException('Запрос не удался: ' . preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $error));
    }

    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) ? (int)$m[1] : 0;
    return [$status, $response];
}

/** GET с разбором JSON; любой код, кроме 2xx, превращается в HttpException. */
function httpGetJson(string $url, array $query = [], float $timeout = 20.0): mixed
{
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    [$status, $body] = httpRequest('GET', $url, ['Accept: application/json'], null, $timeout);
    if ($status < 200 || $status >= 300) {
        throw new HttpException("HTTP $status", $status);
    }
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new HttpException('Ответ не является корректным JSON', $status);
    }
    return $data;
}
