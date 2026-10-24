<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Route;
use ReflectionFunction;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `php artisan route:cache` is a standard production optimisation. It used to
 * fail because 12 route names were registered twice (a web page and its JSON
 * twin, for example admin.reservations.index), and the same collision meant
 * route('admin.reservations.index') returned whichever route happened to be
 * registered last.
 *
 * These checks keep the route table clean so that stays fixed.
 */
class RouteHygieneTest extends TestCase
{
    public function test_every_route_name_is_unique(): void
    {
        $byName = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== null) {
                $byName[$route->getName()][] = $route->methods()[0] . ' /' . $route->uri();
            }
        }

        $duplicates = array_filter($byName, fn(array $routes) => count($routes) > 1);
        $report = collect($duplicates)->map(fn($routes, $name) => "{$name}: " . implode(' | ', $routes))->implode("\n - ");

        $this->assertSame([], array_keys($duplicates), "These route names are registered more than once:\n - " . $report);
        $this->assertGreaterThan(100, count($byName), 'Expected to inspect the whole named route table.');
    }

    public function test_the_api_and_web_routes_no_longer_share_names(): void
    {
        // The pairs that used to clash now resolve unambiguously to the page...
        $this->assertSame('/admin/reservations', route('admin.reservations.index', [], false));
        $this->assertSame('/activities', route('public.activities.index', [], false));
        // ...and the JSON twins have their own, prefixed names.
        $this->assertSame('/api/v1/admin/reservations', route('api.admin.reservations.index', [], false));
        $this->assertSame('/api/v1/public/activities', route('api.public.activities.index', [], false));
    }

    public function test_the_project_defines_no_closure_routes(): void
    {
        // Framework routes (the /up health check, the local storage disk) are closures
        // too, but they live in vendor/ and are exempt; only the project's own route
        // files are checked.
        $projectRoutes = realpath(base_path('routes'));
        $closures = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getAction('uses');

            if ($action instanceof Closure && str_starts_with((string) (new ReflectionFunction($action))->getFileName(), $projectRoutes)) {
                $closures[] = $route->methods()[0] . ' /' . $route->uri();
            }
        }

        $this->assertSame([], $closures, 'Use a controller instead of a closure for: ' . implode(', ', $closures));
    }

    public function test_route_cache_can_be_built(): void
    {
        // Runs the real command in a separate process, writing to a temporary file via
        // APP_ROUTES_CACHE. That keeps this test from touching bootstrap/cache or from
        // replacing the application instance the rest of the suite is using.
        $target = tempnam(sys_get_temp_dir(), 'routes-cache-');
        $defaultCache = base_path('bootstrap/cache/routes-v7.php');
        $defaultExisted = file_exists($defaultCache);

        try {
            $process = new Process([PHP_BINARY, 'artisan', 'route:cache'], base_path(), [
                'APP_ROUTES_CACHE' => $target,
                'APP_ENV' => 'testing',
            ], null, 120);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), "route:cache failed:\n" . $process->getErrorOutput() . $process->getOutput());
            $this->assertStringContainsString('api/v1/ping', (string) file_get_contents($target), 'The cache file should contain the routes.');
            $this->assertSame($defaultExisted, file_exists($defaultCache), 'The test must not create or remove the real route cache.');
        } finally {
            @unlink($target);
        }
    }

    // --- The language switchers that used to be closures ---------------------

    public function test_the_admin_language_switch_remembers_a_supported_language(): void
    {
        $response = $this->from('/admin/login')->get('/admin/locale/ar');

        $response->assertRedirect('/admin/login');
        $response->assertSessionHas('admin_locale', 'ar');
        $response->assertSessionMissing('public_locale');
    }

    public function test_the_public_language_switch_remembers_a_supported_language(): void
    {
        $response = $this->from('/about')->get('/locale/ar');

        $response->assertRedirect('/about');
        $response->assertSessionHas('public_locale', 'ar');
        $response->assertSessionMissing('admin_locale');
    }

    public function test_an_unsupported_language_is_ignored_by_both_switches(): void
    {
        $this->from('/about')->get('/locale/fr')->assertRedirect('/about')->assertSessionMissing('public_locale');
        $this->from('/admin/login')->get('/admin/locale/fr')->assertRedirect('/admin/login')->assertSessionMissing('admin_locale');
    }
}
