<?php

namespace Tests\Feature\Provider;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * routes/api_provider.php is where the provider app's new endpoints go. These tests keep it (and the provider routes in
 * routes/api_v1.php) honest: the file is really loaded, a route written in it inherits every guard it should, and no route anywhere
 * repeats another's method and URI (Laravel would silently serve the first), repeats a name (the second would hide the first
 * from route()), or is reachable without a provider token.
 */
class ProviderRouteConventionsTest extends TestCase
{
    private function routes(): array
    {
        return Route::getRoutes()->getRoutes();
    }

    /** The middleware that really run, with aliases ("auth:sanctum") resolved to their classes, as "php artisan route:list" shows them. */
    private function middlewareOf(LaravelRoute $route): string
    {
        $resolved = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

        return implode(' | ', array_map('strval', $resolved));
    }

    // --- The file --------------------------------------------------------------------------------------

    public function test_the_provider_route_file_exists_and_the_application_loads_it(): void
    {
        $file = realpath(base_path('routes/api_provider.php'));

        $this->assertNotFalse($file, 'routes/api_provider.php is missing');
        $this->assertContains($file, array_map('realpath', get_included_files()), 'bootstrap/app.php does not load routes/api_provider.php');
        $this->assertStringContainsString("base_path('routes/api_provider.php')", file_get_contents(base_path('bootstrap/app.php')));
    }

    public function test_the_file_contains_no_closures_so_the_routes_can_be_cached(): void
    {
        $code = file_get_contents(base_path('routes/api_provider.php'));
        // A closure handler is "fn (" or "function (" as the second argument of a verb; the group callbacks are the only ones allowed.
        preg_match_all('/Route::(get|post|put|patch|delete|options|any|match)\s*\([^;]*?(fn\s*\(|function\s*\()/s', $code, $found);

        $this->assertSame([], $found[0], 'A route in routes/api_provider.php uses a closure: "php artisan route:cache" would fail');
    }

    public function test_a_route_written_in_the_file_gets_the_prefix_the_name_and_all_three_guards(): void
    {
        // The real file with one probe route where the template says "(routes go here)", loaded the way bootstrap/app.php loads it.
        $template = file_get_contents(base_path('routes/api_provider.php'));
        $this->assertStringContainsString('// (routes go here)', $template, 'The marker the guards rely on was removed from routes/api_provider.php');

        $copy = tempnam(sys_get_temp_dir(), 'api_provider_');
        file_put_contents($copy, str_replace('// (routes go here)', "Route::get('/__probe', fn () => 'ok')->name('probe');", $template));

        Route::prefix('api/v1')->middleware('api')->group($copy);
        Route::getRoutes()->refreshNameLookups();
        @unlink($copy);

        $probe = Route::getRoutes()->getByName('provider.probe');

        $this->assertNotNull($probe, 'A route written in the file is not named provider.<name>');
        $this->assertSame('api/v1/provider/__probe', $probe->uri());

        $middleware = $this->middlewareOf($probe);
        $this->assertStringContainsString('Authenticate:sanctum', $middleware, 'A route in the file does not require a token');
        $this->assertStringContainsString('CheckForAnyAbility:provider', $middleware, 'A route in the file accepts a non-provider token');
        $this->assertStringContainsString('EnsureProviderApproved', $middleware, 'A route in the file is open to an unapproved provider');
    }

    // --- Every route, in either file ---------------------------------------------------------------------------

    public function test_no_two_routes_share_a_method_and_uri(): void
    {
        $seen = [];
        $repeated = [];

        foreach ($this->routes() as $route) {
            // {id} and {uuid} are the same URI to the router, so compare with parameter names removed.
            $uri = preg_replace('/\{[^}]+\}/', '{}', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                if (isset($seen["{$method} {$uri}"])) {
                    $repeated[] = "{$method} /{$route->uri()}";
                }
                $seen["{$method} {$uri}"] = true;
            }
        }

        $this->assertSame([], $repeated, 'These routes repeat another route, so only the first one is ever reached');
    }

    public function test_no_two_routes_share_a_name(): void
    {
        $names = array_filter(array_map(fn (LaravelRoute $r) => $r->getName(), $this->routes()));

        $this->assertSame([], array_keys(array_filter(array_count_values($names), fn (int $n) => $n > 1)), 'Route names must be unique: route("name") would return the wrong URL');
    }

    public function test_every_provider_route_needs_a_provider_token_and_all_but_one_need_an_approved_provider(): void
    {
        // Open to a provider an admin has not approved yet, on purpose: reading their own profile.
        $openBeforeApproval = ['GET api/v1/provider/profile'];

        $provider = array_filter($this->routes(), fn (LaravelRoute $r) => $r->uri() === 'api/v1/provider' || str_starts_with($r->uri(), 'api/v1/provider/'));
        $this->assertGreaterThan(40, count($provider), 'Expected the provider routes to be present');

        $problems = [];
        foreach ($provider as $route) {
            $label = array_values(array_diff($route->methods(), ['HEAD']))[0].' '.$route->uri();
            $middleware = $this->middlewareOf($route);

            str_contains($middleware, 'Authenticate:sanctum') || $problems[] = "{$label} does not require a token";
            str_contains($middleware, 'CheckForAnyAbility:provider') || $problems[] = "{$label} accepts a non-provider token";
            str_contains($middleware, 'EnsureProviderApproved') || in_array($label, $openBeforeApproval, true) || $problems[] = "{$label} is open to an unapproved provider";
        }

        $this->assertSame([], $problems);
    }
}
