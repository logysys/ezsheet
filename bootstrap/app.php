<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use App\Http\Middleware\TrackVisitor;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // EZ-REL-20261016-3 (order matters: both before Inertia shares auth.user)
            \App\Http\Middleware\EnsureSessionEpoch::class,          // logout everywhere
            \App\Http\Middleware\StrictCsrfForSessionRoutes::class,  // CSRF on session/owner/upload routes
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
		
		$middleware->alias([
            'track.visitor' => TrackVisitor::class,
        ]);

		
		// Legacy routes stay exempt here; EZ-REL-20261016-3 re-enables CSRF for the
		// session / owner / upload / sign-in routes in StrictCsrfForSessionRoutes.
		$middleware->validateCsrfTokens(except: [
			'*',
		]);
	
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson() || $request->is('wall/*')) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ], 404);
            }
        });
    })->create();
