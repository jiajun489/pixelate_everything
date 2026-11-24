<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogApiRequest
{
    private const MASKED_HEADERS = ['authorization', 'cookie', 'x-xsrf-token', 'php-auth-pw'];

    private const MASK = '********';

    private const MAX_BODY_LENGTH = 10_000;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_log_started_at', hrtime(true));

        return $next($request);
    }

    /**
     * Runs after the response is sent, so a logging failure can never change what the client received.
     */
    public function terminate(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('api_log_started_at');
        $responseBody = $response->getContent();

        try {
            DB::table('api_logs')->insert([
                'method' => $request->method(),
                'path' => $request->path(),
                'query' => $request->getQueryString(),
                'request_headers' => json_encode($this->maskHeaders($request->headers->all())),
                'request_body' => $this->limit($request->getContent()),
                'response_code' => $response->getStatusCode(),
                'response_body' => $responseBody === false ? null : $this->limit($responseBody),
                'ip_address' => $request->ip(),
                'duration_ms' => $startedAt === null ? null : intdiv(hrtime(true) - $startedAt, 1_000_000),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, list<string|null>>  $headers
     * @return array<string, list<string|null>>
     */
    private function maskHeaders(array $headers): array
    {
        foreach (self::MASKED_HEADERS as $name) {
            if (array_key_exists($name, $headers)) {
                $headers[$name] = [self::MASK];
            }
        }

        return $headers;
    }

    private function limit(string $body): ?string
    {
        return $body === '' ? null : mb_substr($body, 0, self::MAX_BODY_LENGTH);
    }
}
