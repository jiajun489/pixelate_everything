# Wallet & Bet Service

A small REST API (Laravel 13, PHP 8.4, PostgreSQL) for player wallets and bets.

## How to run

Requirements: PHP 8.4, Composer, PostgreSQL 14+.

```sh
composer install
cp .env.example .env            # set DB_* for your Postgres
php artisan key:generate
php artisan migrate
php artisan serve               # http://localhost:8000
```

Create a player to test with (players are not modelled beyond an id):

```sh
php artisan tinker --execute 'echo App\Models\User::factory()->create()->id;'
```

`wallet-betting.postman_collection.json` is an end-to-end run of every rule, with assertions on each response. Set `base_url` and `player_id`, then run it top to bottom with Postman's Collection Runner. The last folder closes the wallet permanently, so each full run needs a fresh `player_id`.

### Tests

```sh
php artisan test                 # SQLite in memory, fast; skips the 5 concurrency tests

createdb wallet_test             # once
DB_CONNECTION=pgsql DB_DATABASE=wallet_test php artisan test   # full suite, including concurrency
```

The concurrency tests run real parallel PHP processes against Postgres. They only run on a database whose name ends in `_test`, because they truncate tables.

**Postgres is required for the guarantees.** SQLite ignores `SELECT ... FOR UPDATE`, and the CHECK constraints are only added on Postgres.

## API

All routes are under `/api/v1`. Amounts are integers in the smallest currency unit (cents).

### Swagger / OpenAPI docs

Generated from the code by [Scramble](https://scramble.dedoc.co), so they track the validation rules and resources automatically.

- Interactive docs: `/docs/api` (e.g. http://localhost:8000/docs/api)
- OpenAPI 3.1 spec: `/docs/api.json`. Import it into Swagger UI, Postman or a client generator.
- Export to a file: `php artisan scramble:export --path=api.json`

Only served when `APP_ENV=local`. To expose them elsewhere, define a `viewApiDocs` gate.

Endpoint summaries come from the controller method PHPDoc; the overview page is `info.description` in `config/scramble.php`.

| Method | Path | Body |
|---|---|---|
| POST | `/players/{player}/wallet` | – |
| GET | `/players/{player}/wallet` | – |
| POST | `/players/{player}/wallet/deposits` | `{"amount": 1000, "reference": "dep-1"}` |
| POST | `/players/{player}/wallet/withdrawals` | `{"amount": 500, "reference": "wd-1"}` |
| GET | `/players/{player}/wallet/transactions` | – (paginated, oldest first) |
| POST | `/players/{player}/wallet/close` | – |
| POST | `/players/{player}/bets` | `{"stake": 1000, "odds_bps": 25000}` |
| GET | `/players/{player}/bets` | – (paginated, newest first) |
| GET | `/bets/{bet}` | – |
| POST | `/bets/{bet}/settle` | `{"outcome": "WON"}` or `"LOST"` |
| POST | `/bets/{bet}/cancel` | – |

Business rule failures return `{"error": "<code>", "message": "..."}`: 409 for state conflicts (`wallet_exists`, `wallet_closed`, `wallet_not_empty`, `wallet_has_pending_bets`, `reference_conflict`, `bet_already_resolved`), 422 for `insufficient_funds` and `balance_limit_exceeded`, 404 for `player_not_found`, `wallet_not_found`, `bet_not_found` (or `not_found` for a malformed id). Validation failures return Laravel's standard 422.

## How the rules are enforced

- **One serialization point.** Every balance change and every bet state change runs in a transaction that first locks the wallet row (`SELECT ... FOR UPDATE`). Concurrent requests for one wallet queue up; different wallets don't block each other.
- **One write path.** `WalletService::post()` is the only code that changes a balance, and it always writes the ledger row in the same transaction.
- **The database backs the code.** CHECK constraints reject a negative balance, a closed wallet with money in it, and a ledger row where `balance_after != balance_before ± amount`. A unique `(wallet_id, reference)` index makes double-applying a reference impossible.

| Rule | Where |
|---|---|
| 1. One wallet per player | unique `wallets.user_id`; `createOrFirst` handles the create race |
| 2. Never negative, even concurrently | row lock + check in `post()` + DB CHECK |
| 3. Integers only | `bigint` columns, odds in basis points, `intdiv`; the API rejects floats (`10.0`, `1e3`) and numeric strings |
| 4. Ledger explains the balance | type, amount, before, after, reference per row; tests replay the ledger from 0 |
| 5. Reference applied once | unique index; a replay returns the original transaction |
| 6. Close only when empty with no pending bets | checked under the wallet lock |
| 7. Atomic bet placement | bet insert and stake debit share one transaction |
| 8. PENDING → final, exactly once | status checked under the wallet lock; one `bet:{id}:settlement` reference per bet |
| 9. Settlement through the ledger | `bet_payout` / `bet_refund` rows; LOST writes nothing because nothing moves |

## Assumptions

- **Players:** a player is a `users` row; the id in the URL is trusted. No authentication.
- **Who settles:** anyone who calls the endpoint. In reality this would be an internal or admin-only caller.
- **Currency:** one, unnamed. All amounts are in the smallest unit.
- **Odds:** decimal odds as integer basis points, `odds_bps = odds × 10,000` (2.50 → 25,000). Allowed range 1.01–100.00.
- **Payout:** `floor(stake × odds_bps / 10,000)`. It includes the stake (decimal odds), and rounding goes down, in the house's favour.
- **Stake:** 100–1,000,000 units.
- **LOST writes no ledger row.** The spec says settlement goes through the wallet, but a loss moves no money: the stake already left the wallet as a `bet_stake` row when the bet was placed. A zero-amount row would explain nothing and would break the ledger's `amount > 0` constraint. The loss is recorded on the bet itself (`status = LOST`, `payout = 0`, `resolved_at`).
- **Amounts:** deposits and withdrawals 1 to 10^12 units. A deposit cannot push the balance above 10^15. Payouts are exempt, so a winning bet can always settle.
- **References:** 1–64 chars of `A–Z a–z 0–9 _ -`, unique per wallet. Replaying with the same type and amount returns the original (200). Reusing it for a different type or amount returns 409. Replays still work after the wallet is closed. A rejected request (for example insufficient funds) writes nothing, so its reference can be retried. System references (`bet:…`) contain `:`, which clients cannot use, so the two namespaces never collide.
- **Closed wallets:** terminal. They cannot be reopened, and the player cannot open a second wallet.
- **Bet placement is not idempotent.** The spec requires references only for deposits and withdrawals. A client retry places a second bet.
- **Logging (extra):** committed money events and rule rejections go to `storage/logs/wallet.log` and the `audit_events` table. Every API request/response goes to `api_logs`. Both tables are pruned after 30 days by the scheduler.
- **API docs (extra):** OpenAPI docs generated by Scramble (`dedoc/scramble`) at `/docs/api`, see [Swagger / OpenAPI docs](#swagger--openapi-docs).

## What I would change for production

- **Auth:** authentication, a player can only touch their own wallet, and settlement restricted to an internal service.
- **Bet idempotency:** a client reference on bet placement, same scheme as deposits.
- **Money:** a currency column per wallet, plus a proper amount/currency value object.
- **Settlement:** driven by market/event results (settle every bet on a market in one job) instead of one HTTP call per bet.
- **Hot wallets:** the per-wallet row lock serializes one wallet's traffic. That's fine for players, but a house or jackpot wallet would need sharded balances or an append-only ledger with periodic snapshots.
- **Observability:** ship logs to a real pipeline (ELK, Datadog, etc.) instead of DB tables, mask sensitive body fields, and add metrics and tracing.
- **Ledger reconciliation:** a scheduled job that recomputes every balance from the ledger and alerts on drift.
- **Operations:** rate limiting, cursor pagination for long histories, and a migration path that doesn't rely on a Postgres-only `ALTER TABLE`.
