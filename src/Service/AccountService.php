<?php

declare(strict_types=1);

namespace Ebanx\Service;

use Ebanx\Exception\AccountNotFoundException;
use Ebanx\Repository\AccountRepository;

/**
 * The whole banking domain, with no knowledge of HTTP.
 *
 * Every method returns plain data (or throws a domain exception); turning that
 * into status codes and JSON is the transport layer's job. This is what makes
 * the rules trivial to unit test against a real repository.
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
        $balance = ($this->accounts->find($destination) ?? 0) + $amount;
        $this->accounts->save($destination, $balance);

        return ['destination' => ['id' => $destination, 'balance' => $balance]];
    }

    /**
     * Debit an existing account.
     *
     * @return array{origin: array{id: string, balance: int}}
     * @throws AccountNotFoundException when the origin account does not exist.
     */
    public function withdraw(string $origin, int $amount): array
    {
        $balance = $this->accounts->find($origin);
        if ($balance === null) {
            throw new AccountNotFoundException($origin);
        }

        $balance -= $amount;
        $this->accounts->save($origin, $balance);

        return ['origin' => ['id' => $origin, 'balance' => $balance]];
    }

    /**
     * Move funds from an existing origin to a destination, creating the
     * destination if needed.
     *
     * @return array{origin: array{id: string, balance: int}, destination: array{id: string, balance: int}}
     * @throws AccountNotFoundException when the origin account does not exist.
     */
    public function transfer(string $origin, string $destination, int $amount): array
    {
        $originBalance = $this->accounts->find($origin);
        if ($originBalance === null) {
            throw new AccountNotFoundException($origin);
        }

        $originBalance -= $amount;
        $destinationBalance = ($this->accounts->find($destination) ?? 0) + $amount;

        $this->accounts->save($origin, $originBalance);
        $this->accounts->save($destination, $destinationBalance);

        return [
            'origin' => ['id' => $origin, 'balance' => $originBalance],
            'destination' => ['id' => $destination, 'balance' => $destinationBalance],
        ];
    }
}
