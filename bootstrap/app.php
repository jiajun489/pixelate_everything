<?php

use App\Http\Middleware\LogApiRequest;
use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['reference']);
        $middleware->api(append: [LogApiRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Same {"error","message"} shape as DomainRuleException, so clients can tell "no such player" from "no wallet yet".
        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $model = $exception->getPrevious() instanceof ModelNotFoundException ? $exception->getPrevious()->getModel() : null;

            [$error, $message] = match ($model) {
                User::class => ['player_not_found', 'This player does not exist.'],
                Wallet::class => ['wallet_not_found', 'This player has no wallet.'],
                Bet::class => ['bet_not_found', 'This bet does not exist.'],
                default => ['not_found', 'The requested resource was not found.'],
            };

            return response()->json(['error' => $error, 'message' => $message], 404);
        });
    })->create();
