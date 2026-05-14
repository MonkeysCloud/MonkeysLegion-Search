<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Support;

use MonkeysLegion\Search\Exceptions\ConnectionException;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Lightweight cURL-based HTTP client for search engine APIs.
 *
 * Uses ext-curl directly for maximum performance and zero
 * external dependencies. Supports JSON request/response,
 * configurable timeouts, and authentication headers.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class HttpClient
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int DEFAULT_CONNECT_TIMEOUT = 5;

    /**
     * @param array<string, string> $defaultHeaders Headers sent with every request.
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $defaultHeaders = [],
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {}

    /**
     * Send a GET request.
     *
     * @param string               $path    URL path (appended to baseUrl).
     * @param array<string, mixed> $query   Query string parameters.
     * @param array<string, string> $headers Extra headers.
     *
     * @return HttpResponse
     */
    public function get(
        string $path,
        array $query = [],
        array $headers = [],
    ): HttpResponse {
        $url = $this->buildUrl($path, $query);
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * Send a POST request with a JSON body.
     *
     * @param string                $path    URL path.
     * @param array<string, mixed>|null $body JSON body.
     * @param array<string, string> $headers Extra headers.
     *
     * @return HttpResponse
     */
    public function post(
        string $path,
        ?array $body = null,
        array $headers = [],
    ): HttpResponse {
        $url = $this->buildUrl($path);
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * Send a PUT request with a JSON body.
     *
     * @param string                $path    URL path.
     * @param array<string, mixed>|null $body JSON body.
     * @param array<string, string> $headers Extra headers.
     *
     * @return HttpResponse
     */
    public function put(
        string $path,
        ?array $body = null,
        array $headers = [],
    ): HttpResponse {
        $url = $this->buildUrl($path);
        return $this->request('PUT', $url, $body, $headers);
    }

    /**
     * Send a PATCH request with a JSON body.
     *
     * @param string                $path    URL path.
     * @param array<string, mixed>|null $body JSON body.
     * @param array<string, string> $headers Extra headers.
     *
     * @return HttpResponse
     */
    public function patch(
        string $path,
        ?array $body = null,
        array $headers = [],
    ): HttpResponse {
        $url = $this->buildUrl($path);
        return $this->request('PATCH', $url, $body, $headers);
    }

    /**
     * Send a DELETE request.
     *
     * @param string                $path    URL path.
     * @param array<string, mixed>|null $body Optional JSON body.
     * @param array<string, string> $headers Extra headers.
     *
     * @return HttpResponse
     */
    public function delete(
        string $path,
        ?array $body = null,
        array $headers = [],
    ): HttpResponse {
        $url = $this->buildUrl($path);
        return $this->request('DELETE', $url, $body, $headers);
    }

    // ── Internal ───────────────────────────────────────────────

    /**
     * Execute a cURL request.
     *
     * @param string                    $method  HTTP method.
     * @param string                    $url     Full URL.
     * @param array<string, mixed>|null $body    JSON-encodable body.
     * @param array<string, string>     $headers Extra headers.
     *
     * @return HttpResponse
     *
     * @throws ConnectionException On cURL failure.
     */
    private function request(
        string $method,
        string $url,
        ?array $body,
        array $headers,
    ): HttpResponse {
        $ch = curl_init();

        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $allHeaders['Content-Type'] ??= 'application/json';
        $allHeaders['Accept'] ??= 'application/json';

        $headerList = [];
        foreach ($allHeaders as $name => $value) {
            $headerList[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerList,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new ConnectionException(
                "cURL request failed [{$errno}]: {$error} — {$method} {$url}",
                $errno,
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var string $responseBody */
        return new HttpResponse(
            statusCode: $statusCode,
            body: $responseBody,
        );
    }

    /**
     * Build a full URL from base, path, and query params.
     *
     * @param string               $path  URL path.
     * @param array<string, mixed> $query Query parameters.
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
