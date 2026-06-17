<?php

declare(strict_types=1);

namespace Ebanx\Repository;

/**
 * Storage abstraction for account balances.
 *
 * The contract is intentionally tiny: an account is just an id mapped to an
 * integer balance. The only way to *mutate* state is `transaction()`, which
 * runs a read-modify-write over the whole store as a single atomic unit -- this
 * is what lets a transfer debit the origin and credit the destination without
 * any window where the two are out of sync. Keeping the interface this small
 * lets the business logic stay agnostic about *where* state lives (in-memory for
 * tests, a file for the running server).
 */
interface AccountRepository
{
    /**
     * Current balance of an account, or null when it does not exist.
     *
     * Read-only and single-key, so it needs no transaction.
     */
    public function find(string $id): ?int;

    /**
     * Atomically read the whole state, hand it to $apply (by reference) to
     * mutate, then persist the result -- all as one indivisible operation.
     *
     * If $apply throws, nothing is persisted. Whatever $apply returns is
     * returned to the caller, so an operation can compute its response inside
     * the same critical section that changes the state.
     *
     * @template T
     * @param callable(array<string, int> &): T $apply
     * @return T
     */
    public function transaction(callable $apply): mixed;

    /**
     * Drop all accounts, returning the store to its empty initial state.
     */
    public function reset(): void;
}
