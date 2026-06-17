<?php

declare(strict_types=1);

namespace Ebanx\Repository;

/**
 * Plain in-memory implementation, backed by a PHP array.
 *
 * This is the canonical reference implementation of the contract and the one
 * the unit tests exercise directly (no mocks): state lives for the lifetime of
 * the object, which is exactly one test case.
 */
final class InMemoryAccountRepository implements AccountRepository
{
    /** @var array<string, int> */
    private array $balances = [];

    public function find(string $id): ?int
    {
        return $this->balances[$id] ?? null;
    }

    public function save(string $id, int $balance): void
    {
        $this->balances[$id] = $balance;
    }

    public function reset(): void
    {
        $this->balances = [];
    }
}
