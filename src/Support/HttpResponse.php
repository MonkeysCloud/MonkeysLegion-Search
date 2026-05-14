<?php
declare(strict_types=1);

namespace MonkeysLegion\Search\Support;

/**
 * MonkeysLegion Framework — Search Package
 *
 * Immutable HTTP response wrapper.
 *
 * @copyright 2026 MonkeysCloud Team
 * @license   MIT
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}

    /**
     * Decode the response body as JSON.
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException On invalid JSON.
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        /** @var array<string, mixed> */
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the response indicates success (2xx).
     */
    public bool $isSuccess {
        get => $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Whether the response indicates a client error (4xx).
     */
    public bool $isClientError {
        get => $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Whether the response indicates a server error (5xx).
     */
    public bool $isServerError {
        get => $this->statusCode >= 500;
    }
}
