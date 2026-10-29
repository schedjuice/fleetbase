<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Where to send an already-authenticated request that hits a guest-only route.
     *
     * App\Http\Middleware\RedirectIfAuthenticated (the `guest` alias in
     * App\Http\Kernel) redirects to this constant. It was dropped when the stock
     * Laravel provider was replaced, so the alias would fatal with "Undefined
     * constant" the moment any route actually used it. No route does today,
     * which is why nothing caught it — the console is served separately, so the
     * only sensible in-app target is the API root.
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->routes(
            function () {
                Route::get(
                    '/health',
                    function (Request $request) {
                        return response()->json(
                            [
                                'status' => 'ok',
                                'time' => microtime(true) - $request->attributes->get('request_start_time')
                            ]
                        );
                    }
                );

                // Console SPX booking export. Same int/v1 internal prefix and
                // fleetbase.protected group the packages use for console
                // routes (CoreServiceProvider, fleetops routes.php).
                //
                // The explicit '__invoke' is required: the bare Class::class form
                // leaves the route's controller action without a method name, and
                // Fleetbase\Support\ControllerResolver (used by the
                // AuthorizationGuard middleware) reflects on Class@method and
                // fatals with "Method ...::...() does not exist".
                Route::prefix('int/v1')
                    ->middleware('fleetbase.protected')
                    ->group(
                        function () {
                            Route::post(
                                'orders/spx-booking-export',
                                [\App\Http\Controllers\SpxBookingExportController::class, '__invoke']
                            );

                            $spxDropoff = \App\Http\Controllers\SpxDropoffController::class;
                            Route::get('parcel2u/spx-dropoff-points', [$spxDropoff, 'index']);
                            Route::put('parcel2u/spx-dropoff-points/{place}', [$spxDropoff, 'tag']);
                            Route::delete('parcel2u/spx-dropoff-points/{place}', [$spxDropoff, 'untag']);
                            Route::patch('parcel2u/orders/{order}/spx-dropoff', [$spxDropoff, 'assignToOrder']);
                        }
                    );
            }
        );
    }
}
