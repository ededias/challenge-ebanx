<?php

declare(strict_types=1);

namespace Ebanx\Repository;

/**
 * Storage abstraction for account balances.
 *
 * The contract is intentionally tiny: an account is just an id mapped to an
 * integer balance. Keeping the interface this small lets the business logic
 * stay agnostic about *where* state lives (in-memory for tests, a file for the
 * running server) without leaking any transport or persistence concern.
 */
interface AccountRepository
{
    /**
     * Current balance of an account, or null when it does not exist.
     */
    public function find(string $id): ?int;

    /**
     * Create or overwrite the balance of an account.
     */
    public function save(string $id, int $balance): void;

    /**
     * Drop all accounts, returning the store to its empty initial state.
     */
    public function reset(): void;
}
