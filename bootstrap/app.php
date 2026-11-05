<?php

use App\Http\Middleware\LogHttpAccess;
use App\Http\Middleware\LogoutBannedUser;
use App\Http\Middleware\SetEdgeCacheHeaders;
use App\Http\Middleware\SetSecurityHeaders;
use App\Support\PermanentMailFailure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Features\SupportFileUploads\MissingFileUploadsTraitException;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Web-only application: there is no JSON API. The UI is server-rendered (Blade + Livewire),
 * so no `api` route file, no Sanctum guard, and no JSON exception rendering.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(SetSecurityHeaders::class);
        $middleware->prepend(SetEdgeCacheHeaders::class);
        $middleware->prepend(LogHttpAccess::class);
        $middleware->web(append: LogoutBannedUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);

        $exceptions->map(fn (ComponentNotFoundException $e): Throwable => in_array(request()->route()?->uri(), array_map(
            fn (string $path): string => ltrim($path, '/'),
            [EndpointResolver::componentJsPath(), EndpointResolver::componentCssPath(), EndpointResolver::componentGlobalCssPath()],
        ), true)
            ? new NotFoundHttpException($e->getMessage(), $e)
            : $e);

        $exceptions->map(fn (MissingFileUploadsTraitException $e): Throwable => new BadRequestHttpException($e->getMessage(), $e));

        $exceptions->dontRetryWhen(fn (Throwable $e): bool => PermanentMailFailure::isPermanent($e));
        $exceptions->dontReportWhen(fn (Throwable $e): bool => PermanentMailFailure::isPermanent($e));

        $exceptions->render(function (InvalidSignatureException $e, $request) {
            Log::warning('Invalid signed URL rejected', [
                'route' => $request->route()?->getName(),
                'url' => $request->fullUrl(),
                'scheme' => $request->getScheme(),
                'secure' => $request->isSecure(),
                'x_forwarded_proto' => $request->header('X-Forwarded-Proto'),
                'x_forwarded_for' => $request->header('X-Forwarded-For'),
                'x_real_ip' => $request->header('X-Real-IP'),
                'host_header' => $request->header('Host'),
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'client_ip' => $request->ip(),
                'trusted_proxies' => $request->getTrustedProxies(),
            ]);

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (! in_array($request->route()?->getName(), ['verification.verify', 'verification.send'], true)) {
                return null;
            }

            return redirect()->route('verification.notice')->with('status', 'verification-throttled');
        });
    })
    ->create();
