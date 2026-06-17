<?php

declare(strict_types=1);

namespace Ebanx\Service;

use Ebanx\Exception\AccountNotFoundException;
use Ebanx\Repository\AccountRepository;

/**
 * The whole banking domain, with no knowledge of HTTP.
 *
 * Every method returns plain data (or throws a domain exception); turning that
 * into status codes and JSON is the transport layer's job. Each mutating
 * operation runs inside a single repository transaction, so the read, the
 * decision and the write happen as one atomic step.
 */
final class AccountService
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    /**
     * Wipe all state. Backs `POST /reset`.
     */
    public function reset(): void
    {
        $this->accounts->reset();
    }

    /**
     * Balance of an existing account. Backs `GET /balance`.
     *
     * @throws AccountNotFoundException when the account was never created.
     */
    public function getBalance(string $id): int
    {
        $balance = $this->accounts->find($id);
        if ($balance === null) {
            throw new AccountNotFoundException($id);
        }

        return $balance;
    }

    /**
     * Credit an account, creating it on first deposit.
     *
     * @return array{destination: array{id: string, balance: int}}
     */
    public function deposit(string $destination, int $amount): array
    {
        return $this->accounts->transaction(static function (array &$state) use ($destination, $amount): array {
            $balance = ($state[$destination] ?? 0) + $amount;
            $state[$destination] = $balance;

            return ['destination' => ['id' => $destination, 'balance' => $balance]];
        });
    }

    /**
     * Debit an existing account.
     *
     * @return array{origin: array{id: string, balance: int}}
     * @throws AccountNotFoundException when the origin account does not exist.
     */
    public function withdraw(string $origin, int $amount): array
    {
        return $this->accounts->transaction(static function (array &$state) use ($origin, $amount): array {
            if (!\array_key_exists($origin, $state)) {
                throw new AccountNotFoundException($origin);
            }

            $balance = $state[$origin] - $amount;
            $state[$origin] = $balance;

            return ['origin' => ['id' => $origin, 'balance' => $balance]];
        });
    }

    /**
     * Move funds from an existing origin to a destination, creating the
     * destination if needed. Debit and credit happen in the same transaction,
     * so the two balances are never observable out of sync.
     *
     * @return array{origin: array{id: string, balance: int}, destination: array{id: string, balance: int}}
     * @throws AccountNotFoundException when the origin account does not exist.
     */
    public function transfer(string $origin, string $destination, int $amount): array
    {
        return $this->accounts->transaction(static function (array &$state) use ($origin, $destination, $amount): array {
            if (!\array_key_exists($origin, $state)) {
                throw new AccountNotFoundException($origin);
            }

            $originBalance = $state[$origin] - $amount;
            $destinationBalance = ($state[$destination] ?? 0) + $amount;

            $state[$origin] = $originBalance;
            $state[$destination] = $destinationBalance;

            return [
                'origin' => ['id' => $origin, 'balance' => $originBalance],
                'destination' => ['id' => $destination, 'balance' => $destinationBalance],
            ];
        });
    }
}
