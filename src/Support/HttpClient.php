<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Support;

use MonkeysLegion\HttpClient\HttpClient as BaseClient;
use MonkeysLegion\HttpClient\DTO\ClientConfig;
use MonkeysLegion\HttpClient\DTO\HttpResponse as BaseResponse;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Lightweight HTTP client for search engine APIs.
 * Now delegates to monkeyslegion-http-client for pooled connections,
 * keep-alive, and unified error handling.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final class HttpClient
{
    private readonly BaseClient $client;

    /**
     * @param array<string, string> $defaultHeaders Headers sent with every request.
     */
    public function __construct(
        string $baseUrl,
        array $defaultHeaders = [],
        int $timeout = 30,
        int $connectTimeout = 5,
    ) {
        $this->client = new BaseClient(new ClientConfig(
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            defaultHeaders: $defaultHeaders,
        ));
    }

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
        $queryStrings = [];
        foreach ($query as $k => $v) {
            $queryStrings[$k] = (string) $v;
        }
        $request = $this->client->newRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        $response = $request->get($path, $queryStrings);
        return $this->wrap($response);
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
        $request = $this->client->newRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        if ($body !== null) {
            $response = $request->withJson($body)->send(
                \MonkeysLegion\HttpClient\Enum\HttpMethod::POST,
                $path,
            );
        } else {
            $response = $request->post($path);
        }
        return $this->wrap($response);
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
        $request = $this->client->newRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        if ($body !== null) {
            $response = $request->put($path, $body);
        } else {
            $response = $request->send(
                \MonkeysLegion\HttpClient\Enum\HttpMethod::PUT,
                $path,
            );
        }
        return $this->wrap($response);
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
        $request = $this->client->newRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        if ($body !== null) {
            $response = $request->patch($path, $body);
        } else {
            $response = $request->send(
                \MonkeysLegion\HttpClient\Enum\HttpMethod::PATCH,
                $path,
            );
        }
        return $this->wrap($response);
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
        $request = $this->client->newRequest();
        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }
        $response = $request->delete($path);
        return $this->wrap($response);
    }

    /**
     * Wrap the base HttpResponse into our local HttpResponse.
     */
    private function wrap(BaseResponse $response): HttpResponse
    {
        return new HttpResponse(
            statusCode: $response->statusCode,
            body: $response->body,
        );
    }
}
