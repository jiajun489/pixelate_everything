<?php

namespace Tests\Feature;

use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiRequestLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_rejected_request_is_logged_with_its_raw_body_and_masked_credentials(): void
    {
        $wallet = Wallet::factory()->create();
        $path = "api/v1/players/{$wallet->user_id}/wallet/deposits";
        $rawBody = '{"amount":10.0,"reference":"float-amount"}';

        $this->call('POST', "/{$path}?source=test", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
        ], content: $rawBody)->assertUnprocessable();

        $log = DB::table('api_logs')->sole();

        $this->assertSame('POST', $log->method);
        $this->assertSame($path, $log->path);
        $this->assertSame('source=test', $log->query);
        $this->assertSame(422, (int) $log->response_code);
        $this->assertSame($rawBody, $log->request_body);
        $this->assertStringContainsString('"amount"', $log->response_body);
        $this->assertSame(['********'], json_decode($log->request_headers, true)['authorization']);
        $this->assertStringNotContainsString('secret-token', $log->request_headers);
    }

    public function test_non_api_routes_are_not_logged(): void
    {
        $this->get('/up')->assertOk();

        $this->assertSame(0, DB::table('api_logs')->count());
    }

    public function test_a_logging_failure_is_reported_without_changing_the_response(): void
    {
        Exceptions::fake();
        $wallet = Wallet::factory()->create();
        Schema::drop('api_logs');

        $this->postJson(route('api.v1.wallet.deposit', $wallet->user), [
            'amount' => 100,
            'reference' => 'still-works',
        ])->assertCreated();

        Exceptions::assertReported(QueryException::class);
    }
}
