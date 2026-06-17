<?php

declare(strict_types=1);

namespace Ebanx\Exception;

/**
 * Raised when an operation targets an account that does not exist.
 *
 * The HTTP layer translates this into the spec's "404 with body 0" response.
 */
final class AccountNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $accountId)
    {
        parent::__construct(\sprintf('Account "%s" does not exist.', $accountId));
    }
}
