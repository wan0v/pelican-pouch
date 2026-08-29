<?php

namespace Wan0v\Pouch\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class PouchRouteProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            // Reuses the panel's existing daemon authentication, so the agent
            // can present the Wings token that is already on the node.
            Route::middleware('daemon')
                ->prefix('/api/remote/pouch')
                ->group(plugin_path('pouch', 'routes/api-remote.php'));
        });
    }
}
