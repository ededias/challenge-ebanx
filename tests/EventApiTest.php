<?php

declare(strict_types=1);

namespace Ebanx\Tests;

use Ebanx\Http\Controller;
use Ebanx\Repository\InMemoryAccountRepository;
use Ebanx\Service\AccountService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Replicates the official EBANX acceptance suite at the transport boundary.
 *
 * Every assertion pins the *exact* HTTP status and the *exact* serialised body
 * (the raw string the server would write to the socket), so this catches the
 * quoting/shape bugs a domain test cannot -- e.g. the spec's bare `0` versus a
 * quoted `"0"`. The controller runs against a real in-memory repository; no HTTP
 * server is spun up, but json_encode is exercised end to end.
 */
final class EventApiTest extends TestCase
{
    private Controller $controller;

    protected function setUp(): void
    {
        $this->controller = new Controller(
            new AccountService(new InMemoryAccountRepository()),
        );
    }

    /** Issue POST /event with a JSON body, returning [status, body]. */
    private function event(array $payload): array
    {
        $response = $this->controller->handle(
            'POST',
            '/event',
            [],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return [$response->status, $response->body];
    }

    /** Issue GET /balance?account_id=..., returning [status, body]. */
    private function balance(string $accountId): array
    {
        $response = $this->controller->handle('GET', '/balance', ['account_id' => $accountId], '');

        return [$response->status, $response->body];
    }

    #[Test]
    public function reset_returns_200_OK(): void
    {
        $response = $this->controller->handle('POST', '/reset', [], '');

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->body);
    }

    #[Test]
    public function balance_of_a_non_existing_account_is_404_bare_zero(): void
    {
        // The spec's body is the bare token 0, NOT the quoted string "0".
        self::assertSame([404, '0'], $this->balance('1234'));
    }

    #[Test]
    public function deposit_into_a_new_account_is_201_with_destination(): void
    {
        self::assertSame(
            [201, '{"destination":{"id":"100","balance":10}}'],
            $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 10]),
        );
    }

    #[Test]
    public function deposit_into_an_existing_account_accumulates(): void
    {
        $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 10]);

        self::assertSame(
            [201, '{"destination":{"id":"100","balance":20}}'],
            $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 10]),
        );
    }

    #[Test]
    public function balance_of_an_existing_account_is_200_bare_number(): void
    {
        $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 20]);

        self::assertSame([200, '20'], $this->balance('100'));
    }

    #[Test]
    public function withdraw_from_a_non_existing_account_is_404_bare_zero(): void
    {
        self::assertSame(
            [404, '0'],
            $this->event(['type' => 'withdraw', 'origin' => '200', 'amount' => 10]),
        );
    }

    #[Test]
    public function withdraw_from_an_existing_account_is_201_with_origin(): void
    {
        $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 20]);

        self::assertSame(
            [201, '{"origin":{"id":"100","balance":15}}'],
            $this->event(['type' => 'withdraw', 'origin' => '100', 'amount' => 5]),
        );
    }

    #[Test]
    public function transfer_is_201_with_origin_and_destination(): void
    {
        $this->event(['type' => 'deposit', 'destination' => '100', 'amount' => 15]);

        self::assertSame(
            [201, '{"origin":{"id":"100","balance":0},"destination":{"id":"300","balance":15}}'],
            $this->event(['type' => 'transfer', 'origin' => '100', 'destination' => '300', 'amount' => 15]),
        );
    }

    #[Test]
    public function transfer_from_a_non_existing_account_is_404_bare_zero(): void
    {
        self::assertSame(
            [404, '0'],
            $this->event(['type' => 'transfer', 'origin' => '200', 'destination' => '300', 'amount' => 15]),
        );
    }

    #[Test]
    public function an_unknown_route_is_404(): void
    {
        $response = $this->controller->handle('GET', '/nope', [], '');

        self::assertSame(404, $response->status);
    }
}
