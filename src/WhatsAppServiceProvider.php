<?php

namespace DevClick\WhatsApp;

use DevClick\WhatsApp\Http\NoStore;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp.php', 'whatsapp');
        $this->app->bind(WhatsApp::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! is_callable([$handler, 'renderable'])) {
                return;
            }
            $handler->renderable(function (\Throwable $exception, Request $request): ?JsonResponse {
                if (! in_array(NoStore::class, $request->route()?->gatherMiddleware() ?? [], true)) {
                    return null;
                }
                if ($exception instanceof WhatsAppException) {
                    return $exception->render();
                }
                if ($exception instanceof AuthenticationException) {
                    return response()->json(['success' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Sign in before using WhatsApp.'], 401)->header('Cache-Control', 'no-store');
                }
                if ($exception instanceof ValidationException) {
                    return response()->json(['success' => false, 'code' => 'INVALID_INPUT', 'message' => 'Check the request fields, recipient and text.'], 422)->header('Cache-Control', 'no-store');
                }
                if ($exception instanceof HttpExceptionInterface) {
                    $status = $exception->getStatusCode();

                    return response()->json(['success' => false, 'code' => $status === 429 ? 'RATE_LIMITED' : 'REQUEST_REJECTED', 'message' => $status === 429 ? 'Too many requests. Wait before trying again.' : 'The request was rejected.'], $status, $exception->getHeaders())->header('Cache-Control', 'no-store');
                }

                return response()->json(['success' => false, 'code' => 'SERVICE_UNAVAILABLE', 'message' => 'WhatsApp is temporarily unavailable.'], 503)->header('Cache-Control', 'no-store');
            });
        });
        $this->publishes([__DIR__.'/../config/whatsapp.php' => config_path('whatsapp.php')], 'whatsapp-config');
        foreach (['read' => 20, 'connect' => 5, 'send' => 5] as $operation => $perMinute) {
            RateLimiter::for('devclick-whatsapp-'.$operation, function (Request $request) use ($perMinute) {
                $key = hash('sha256', json_encode([config('whatsapp.app_id'), $request->user()?->getAuthIdentifier() ?? $request->ip()], JSON_THROW_ON_ERROR));

                return Limit::perMinute($perMinute)->by($key);
            });
        }
        $this->app->make(Kernel::class)->prependToMiddlewarePriority(NoStore::class);
        if (config('whatsapp.routes_enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/whatsapp.php');
        }
    }
}
