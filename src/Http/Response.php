<?php

declare(strict_types=1);

namespace Ebanx\Http;

/**
 * A transport-agnostic HTTP response: a status code plus an already-rendered
 * body. Keeping it a plain value object lets the controller be unit tested
 * without touching PHP's global output machinery.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = 'application/json',
    ) {
    }

    /**
     * Build a JSON response from a data structure.
     */
    public static function json(int $status, mixed $data): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Build a plain-text response (used for `OK` and health checks).
     */
    public static function text(int $status, string $body): self
    {
        return new self($status, $body, 'text/plain');
    }
}
