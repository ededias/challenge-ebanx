<?php

declare(strict_types=1);

namespace Ebanx\Tests;

use Ebanx\Exception\AccountNotFoundException;
use Ebanx\Exception\InsufficientFundsException;
use Ebanx\Repository\InMemoryAccountRepository;
use Ebanx\Service\AccountService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the banking rules against a real repository (no mocks): every
 * assertion is about the *effect on state*, not just the returned value.
 */
final class AccountServiceTest extends TestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        $this->service = new AccountService(new InMemoryAccountRepository());
    }

    #[Test]
    public function deposit_creates_the_account_on_first_use(): void
    {
        $result = $this->service->deposit('100', 10);

        self::assertSame(['destination' => ['id' => '100', 'balance' => 10]], $result);
        self::assertSame(10, $this->service->getBalance('100'));
    }

    #[Test]
    public function deposits_accumulate(): void
    {
        $this->service->deposit('100', 10);
        $this->service->deposit('100', 10);

        self::assertSame(20, $this->service->getBalance('100'));
    }

    #[Test]
    public function getting_the_balance_of_an_unknown_account_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->getBalance('1234');
    }

    #[Test]
    public function withdraw_debits_an_existing_account(): void
    {
        $this->service->deposit('100', 20);

        $result = $this->service->withdraw('100', 5);

        self::assertSame(['origin' => ['id' => '100', 'balance' => 15]], $result);
        self::assertSame(15, $this->service->getBalance('100'));
    }

    #[Test]
    public function withdraw_from_an_unknown_account_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->withdraw('200', 10);
    }

    #[Test]
    public function withdraw_down_to_exactly_zero_is_allowed(): void
    {
        $this->service->deposit('100', 20);

        $result = $this->service->withdraw('100', 20);

        self::assertSame(['origin' => ['id' => '100', 'balance' => 0]], $result);
    }

    #[Test]
    public function withdraw_more_than_the_balance_is_rejected_and_leaves_state_untouched(): void
    {
        $this->service->deposit('100', 10);

        try {
            $this->service->withdraw('100', 20);
            self::fail('Expected InsufficientFundsException');
        } catch (InsufficientFundsException) {
            // expected
        }

        self::assertSame(10, $this->service->getBalance('100'));
    }

    #[Test]
    public function transfer_moves_funds_and_creates_the_destination(): void
    {
        $this->service->deposit('100', 15);

        $result = $this->service->transfer('100', '300', 15);

        self::assertSame([
            'origin' => ['id' => '100', 'balance' => 0],
            'destination' => ['id' => '300', 'balance' => 15],
        ], $result);
        self::assertSame(0, $this->service->getBalance('100'));
        self::assertSame(15, $this->service->getBalance('300'));
    }

    #[Test]
    public function transfer_with_insufficient_funds_is_atomic_and_changes_nothing(): void
    {
        $this->service->deposit('100', 10);

        try {
            $this->service->transfer('100', '300', 20);
            self::fail('Expected InsufficientFundsException');
        } catch (InsufficientFundsException) {
            // expected
        }

        // Origin is not debited and the destination is never created: the failed
        // transfer left no partial state behind.
        self::assertSame(10, $this->service->getBalance('100'));
        $this->expectException(AccountNotFoundException::class);
        $this->service->getBalance('300');
    }

    #[Test]
    public function transfer_from_an_unknown_account_throws(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->service->transfer('200', '300', 15);
    }

    #[Test]
    public function reset_wipes_all_state(): void
    {
        $this->service->deposit('100', 50);

        $this->service->reset();

        $this->expectException(AccountNotFoundException::class);
        $this->service->getBalance('100');
    }

    #[Test]
    public function reading_a_balance_has_no_side_effect(): void
    {
        $this->service->deposit('100', 42);

        self::assertSame(42, $this->service->getBalance('100'));
        self::assertSame(42, $this->service->getBalance('100'));
    }

    #[Test]
    public function a_full_session_keeps_consistent_balances(): void
    {
        $this->service->reset();
        $this->service->deposit('100', 100);
        $this->service->withdraw('100', 30);
        $this->service->transfer('100', '200', 20);

        self::assertSame(50, $this->service->getBalance('100'));
        self::assertSame(20, $this->service->getBalance('200'));
    }
}
