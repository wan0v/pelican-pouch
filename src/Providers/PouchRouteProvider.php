<?php

namespace Wan0v\Pouch\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Wan0v\Pouch\Http\Middleware\AuthenticatePouchAgent;

class PouchRouteProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            // Not the core `daemon` group: the agent authenticates with its own
            // credential, scoped to exactly this endpoint. See
            // AuthenticatePouchAgent for why.
            //
            // Core leaves /api/remote entirely unthrottled; sync builds the full
            // configuration plus its hash on every call, so it gets a limit.
            Route::middleware([
                SubstituteBindings::class,
                AuthenticatePouchAgent::class,
                'throttle:120,1',
            ])
                ->prefix('/api/remote/pouch')
                ->group(plugin_path('pouch', 'routes/api-remote.php'));
        });
    }
}
