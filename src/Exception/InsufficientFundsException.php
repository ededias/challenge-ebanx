<?php

declare(strict_types=1);

namespace Ebanx\Exception;

/**
 * Raised when a withdraw or transfer would drive an existing account negative.
 *
 * The spec's contract table does not name this case, so we make a deliberate
 * choice: an operation that cannot be fulfilled on the account is collapsed into
 * the same "404 0" the spec already uses for an unknown account. The account may
 * well exist, but from the caller's point of view the operation is simply
 * rejected with no state change -- see the HTTP layer for the mapping.
 */
final class InsufficientFundsException extends \RuntimeException
{
    public function __construct(public readonly string $accountId)
    {
        parent::__construct(\sprintf('Account "%s" has insufficient funds.', $accountId));
    }
}
