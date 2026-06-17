# EBANX Banking API

A tiny HTTP API that tracks account balances in memory, built for the EBANX
technical challenge. It implements `reset`, `balance`, `deposit`, `withdraw` and
`transfer` over a handful of endpoints, with the banking rules kept completely
separate from the HTTP transport.

## The challenge

The API must satisfy this exact contract (status code **and** body):

| Operation | Request | Response |
|-----------|---------|----------|
| Reset state | `POST /reset` | `200 OK` |
| Balance, unknown account | `GET /balance?account_id=1234` | `404 0` |
| Balance, existing account | `GET /balance?account_id=100` | `200 20` |
| Create / deposit | `POST /event {"type":"deposit","destination":"100","amount":10}` | `201 {"destination":{"id":"100","balance":10}}` |
| Withdraw, unknown account | `POST /event {"type":"withdraw","origin":"200","amount":10}` | `404 0` |
| Withdraw, existing account | `POST /event {"type":"withdraw","origin":"100","amount":5}` | `201 {"origin":{"id":"100","balance":15}}` |
| Transfer | `POST /event {"type":"transfer","origin":"100","destination":"300","amount":15}` | `201 {"origin":{"id":"100","balance":0},"destination":{"id":"300","balance":15}}` |
| Transfer, unknown origin | `POST /event {"type":"transfer","origin":"200","destination":"300","amount":15}` | `404 0` |

> **The `0` is a bare token, not a string.** The not-found body is the number
> `0`, not `"0"`. This is the kind of detail the transport test suite guards
> (see [Testing](#testing)).

## Architecture

The code is organised in layers so the banking rules never know HTTP or storage
exists. Dependencies point inward: transport → service → repository interface.

```
HTTP request
    │
    ▼
public/index.php            ← wiring + raw I/O only (no logic)
    │
    ▼
Http\Controller             ← maps routes to the service, owns spec quirks
    │                         (the bare `0`, the `OK` reset body)
    ▼
Service\AccountService      ← the whole banking domain, returns plain data
    │                         or throws AccountNotFoundException
    ▼
Repository\AccountRepository  ← tiny storage contract: find / transaction / reset
    ├── InMemoryAccountRepository   (used by the unit tests, no mocks)
    └── FileAccountRepository       (used by the running server)
```

### Why a *file* repository for an in-memory challenge?

PHP runs on a **shared-nothing** model: every request re-executes the script
from a clean slate, so a plain in-memory array would be empty again on the very
next request. `FileAccountRepository` bridges that gap by keeping state in a
single JSON file, guarded by `flock()` so a read-modify-write is never
interleaved. It is *not* database durability — just the minimum needed to keep
state alive between requests of the same running process. The whole concern is
hidden behind `AccountRepository`, so the domain never knows it exists.

### Design decisions

- **Pure domain.** `AccountService` returns arrays / throws exceptions; turning
  that into status codes and JSON is the controller's job. This is what makes
  the rules trivial to unit test against a real repository (no mocks).
- **`Response` value object.** A transport-agnostic status + body, so the
  controller can be tested without touching PHP's global output machinery.
- **Atomic mutations.** The only way to change state is
  `AccountRepository::transaction()`, which performs the read, the decision and
  the write as a single unit under one exclusive lock. A transfer therefore
  debits the origin and credits the destination with no window where the two are
  out of sync, and a rejected operation persists nothing. This also closes the
  read-then-write (lost-update) race a separate `find()` + `save()` would leave
  open.
- **No `@` error suppression.** Failure paths are handled explicitly
  (`is_file` before `fopen`, an explicit `is_dir`/`mkdir` guard) so real errors
  surface instead of being silently swallowed.

## Behaviour beyond the spec

The contract table covers the cases the challenge specifies. Two situations it
is silent about, and the deliberate choices made for them:

- **Insufficient funds (`withdraw` / `transfer` that would go negative).**
  Rejected with **`404 0`** — the same response the spec uses for an unknown
  account — and **no state changes**. Rationale: the spec only ever answers a
  request it cannot fulfil on an account with `404 0`, so an existing account
  that cannot cover the amount is collapsed into that same "operation rejected"
  signal rather than inventing a new status code. Withdrawing down to exactly
  `0` is allowed (it is not negative). See `InsufficientFundsException`.
- **Invalid `amount` (negative or zero).** Intentionally **out of scope**: the
  spec only uses positive integers and asks not to anticipate. `amount` is
  assumed positive; no extra validation is added. (A negative amount would let a
  "deposit" reduce a balance — a known, accepted limitation given the spec.)

## Project structure

```
public/index.php                  Front controller (entry point)
src/
  Http/Controller.php             Routing + spec quirks
  Http/Response.php               Status + body value object
  Service/AccountService.php      Banking domain rules
  Repository/AccountRepository.php        Storage contract
  Repository/InMemoryAccountRepository.php
  Repository/FileAccountRepository.php
  Exception/AccountNotFoundException.php
tests/
  AccountServiceTest.php          Domain suite (real in-memory repo)
  EventApiTest.php                Transport suite (exact status + body)
data/                             Runtime state (git-ignored, auto-created)
```

## Requirements

- PHP **8.2+** (developed against 8.4)
- [Composer](https://getcomposer.org/)

## Install

```bash
composer install
```

## Running the server

```bash
composer serve            # php -S 0.0.0.0:8080 public/index.php
```

Or directly:

```bash
php -S 0.0.0.0:8080 public/index.php
```

> **Always pass `public/index.php` as the router.** Without it, PHP's built-in
> server looks for literal files named `/reset`, `/event`, … never finds them,
> and answers every route with its own HTML `404 Not Found` — the application is
> never invoked. The trailing `public/index.php` is what routes all requests
> through the app.

Quick check:

```bash
curl -s -X POST localhost:8080/reset                       # OK
curl -s localhost:8080/balance?account_id=1234             # 0   (404)
curl -s -X POST localhost:8080/event \
  -d '{"type":"deposit","destination":"100","amount":10}'  # {"destination":{"id":"100","balance":10}}
```

### Configuration

| Variable | Default | Purpose |
|----------|---------|---------|
| `EBANX_STORE_PATH` | `data/accounts.json` | Where the JSON state file lives. |

The store file (and its directory) is created automatically on the first state
mutation — typically the initial `POST /reset`. State is runtime-only and
git-ignored.

## Testing

```bash
composer test             # phpunit
```

Two suites:

- **`AccountServiceTest`** — exercises the banking rules against a real
  `InMemoryAccountRepository` (no mocks). Every assertion is about the *effect on
  state*, not just the return value.
- **`EventApiTest`** — replicates the official acceptance flow at the controller
  boundary, pinning the **exact** HTTP status and the **exact** serialised body.
  This is what catches shape/quoting bugs a domain test cannot (e.g. the bare
  `0` vs the quoted `"0"`).

## Exposing the local server (ngrok)

To let the EBANX acceptance suite reach your machine, expose the running server
with a tunnel:

```bash
# 1. start the server (with the router!)
composer serve

# 2. in another terminal, tunnel the same port
ngrok http 8080
```

ngrok prints a public `https://…ngrok…` URL — submit that as the API base URL.
Make sure the server is started **with the router** (see above), otherwise the
suite will receive PHP's built-in HTML 404 for every endpoint.
