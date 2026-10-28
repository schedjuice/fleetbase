<?php

namespace Tests\Feature;

use App\Http\Controllers\SpxDropoffController;
use Illuminate\Http\Request;
use Tests\TestCase;

class SpxDropoffRoutesTest extends TestCase
{
    public static function routes(): array
    {
        return [
            'list'   => ['GET', 'int/v1/parcel2u/spx-dropoff-points', 'index'],
            'tag'    => ['PUT', 'int/v1/parcel2u/spx-dropoff-points/place_abc123', 'tag'],
            'untag'  => ['DELETE', 'int/v1/parcel2u/spx-dropoff-points/place_abc123', 'untag'],
            'assign' => ['PATCH', 'int/v1/parcel2u/orders/order_abc123/spx-dropoff', 'assignToOrder'],
        ];
    }

    /**
     * @dataProvider routes
     */
    public function test_route_is_protected_and_names_its_controller_method(string $method, string $uri, string $action): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/' . $uri, $method));

        // ControllerResolver (AuthorizationGuard) needs Class@method, see RouteServiceProvider.
        $this->assertSame(SpxDropoffController::class . '@' . $action, $route->getActionName());
        $this->assertContains('fleetbase.protected', $route->gatherMiddleware());
    }
}
