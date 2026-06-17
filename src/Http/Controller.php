<?php

declare(strict_types=1);

namespace Ebanx\Http;

use Ebanx\Exception\AccountNotFoundException;
use Ebanx\Exception\InsufficientFundsException;
use Ebanx\Service\AccountService;

/**
 * Translates HTTP requests into AccountService calls and the results back into
 * responses. It owns the spec's quirks (the bare `0` body, the `OK` reset
 * response) and nothing else -- all banking rules live in the service.
 */
final class Controller
{
    /**
     * The spec's answer to "this account does not exist": HTTP 404, body `0`.
     *
     * Kept as an int (not the string '0') so json_encode renders the bare token
     * `0` the official suite compares against -- a string would serialise to the
     * quoted `"0"` and fail the exact-body check.
     */
    private const NOT_FOUND = 0;

    public function __construct(private readonly AccountService $service)
    {
    }

    /**
     * @param array<string, mixed> $query parsed query string (for GET /balance)
     * @param string               $body  raw request body (JSON for POST /event)
     */
    public function handle(string $method, string $path, array $query, string $body): Response
    {
        return match (true) {
            $method === 'POST' && $path === '/reset'   => $this->reset(),
            $method === 'GET'  && $path === '/balance' => $this->balance($query),
            $method === 'POST' && $path === '/event'   => $this->event($body),
            $method === 'GET'  && $path === '/'        => Response::text(200, 'OK'),
            default                                     => Response::text(404, 'Not Found'),
        };
    }

    private function reset(): Response
    {
        $this->service->reset();

        return Response::text(200, 'OK');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function balance(array $query): Response
    {
        $id = (string) ($query['account_id'] ?? '');

        try {
            return Response::json(200, $this->service->getBalance($id));
        } catch (AccountNotFoundException) {
            return Response::json(404, self::NOT_FOUND);
        }
    }

    private function event(string $body): Response
    {
        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            return Response::json(422, self::NOT_FOUND);
        }

        $type = (string) ($payload['type'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);

        try {
            return match ($type) {
                'deposit' => Response::json(
                    201,
                    $this->service->deposit((string) ($payload['destination'] ?? ''), $amount),
                ),
                'withdraw' => Response::json(
                    201,
                    $this->service->withdraw((string) ($payload['origin'] ?? ''), $amount),
                ),
                'transfer' => Response::json(
                    201,
                    $this->service->transfer(
                        (string) ($payload['origin'] ?? ''),
                        (string) ($payload['destination'] ?? ''),
                        $amount,
                    ),
                ),
                default => Response::json(422, self::NOT_FOUND),
            };
        } catch (AccountNotFoundException | InsufficientFundsException) {
            // The spec collapses both "unknown account" and "operation that
            // cannot be fulfilled on it" (insufficient funds) into 404 0.
            return Response::json(404, self::NOT_FOUND);
        }
    }
}
