<?php

use Illuminate\Filesystem\Filesystem;
use LaravelArchitect\Architect\Tests\TestCase;

uses(TestCase::class);

function cleanModule(): void
{
    $files = new Filesystem();

    foreach ([
        app_path('Models/Widget.php'),
        app_path('Http/Requests/Widget'),
        app_path('Services/Widget'),
        app_path('Repositories/Widget'),
        app_path('Widget'),
        app_path('Http/Controllers/Widget'),
        app_path('Http/Controllers/Api/Widget'),
        base_path('tests/Feature/Widget'),
        resource_path('views/widgets'),
    ] as $path) {
        $files->isDirectory($path) ? $files->deleteDirectory($path) : $files->delete($path);
    }

    // Remove controller folders left empty by earlier runs, so folder detection starts clean.
    foreach (['Web', 'Api'] as $folder) {
        $dir = app_path("Http/Controllers/{$folder}");

        if ($files->isDirectory($dir) && $files->allFiles($dir) === [] && $files->directories($dir) === []) {
            $files->deleteDirectory($dir);
        }
    }

    foreach ($files->glob(database_path('migrations/*_create_widgets_table.php')) as $migration) {
        $files->delete($migration);
    }
}

function routeFiles(): array
{
    return [base_path('routes/web.php'), base_path('routes/api.php')];
}

beforeEach(function () {
    cleanModule();

    // Remember the route files so each test leaves them as it found them.
    $this->routeBackup = [];
    foreach (routeFiles() as $file) {
        $this->routeBackup[$file] = file_exists($file) ? file_get_contents($file) : null;
    }
});

afterEach(function () {
    cleanModule();

    foreach ($this->routeBackup as $file => $content) {
        $content === null ? @unlink($file) : file_put_contents($file, $content);
    }
});

it('scaffolds model, migration, requests, service, repository, test and views', function () {
    $this->artisan('make:module', ['name' => 'Widget'])->assertExitCode(0);

    expect(file_exists(app_path('Models/Widget.php')))->toBeTrue();
    expect(glob(database_path('migrations/*_create_widgets_table.php')))->toHaveCount(1);
    expect(file_exists(app_path('Http/Requests/Widget/StoreWidgetRequest.php')))->toBeTrue();
    expect(file_exists(app_path('Http/Requests/Widget/UpdateWidgetRequest.php')))->toBeTrue();
    expect(file_get_contents(app_path('Services/Widget/WidgetService.php')))
        ->toContain('namespace App\Services\Widget;');
    expect(file_get_contents(app_path('Repositories/Widget/WidgetRepository.php')))
        ->toContain('namespace App\Repositories\Widget;');
    expect(file_exists(base_path('tests/Feature/Widget/WidgetTest.php')))->toBeTrue();

    foreach (['index', 'create', 'edit', 'show'] as $page) {
        expect(file_exists(resource_path("views/widgets/{$page}.blade.php")))->toBeTrue();
    }

    expect(file_get_contents(app_path('Http/Controllers/Widget/WidgetController.php')))
        ->toContain('class WidgetController')
        ->toContain('public function store');
    expect(file_get_contents(app_path('Http/Controllers/Widget/WidgetController.php')))
        ->toContain('namespace App\Http\Controllers\Widget;');
    $routes = file_get_contents(base_path('routes/web.php'));
    expect($routes)
        ->toContain('use App\Http\Controllers\Widget\WidgetController;')
        ->toContain("->controller(WidgetController::class)->group(")
        ->not->toContain('\\App\\Http');
});

it('adds api routes and an api controller with --api, and does not add them twice', function () {
    $this->artisan('make:module', ['name' => 'Widget', '--api' => true])->assertExitCode(0);

    expect(file_get_contents(app_path('Http/Controllers/Api/Widget/WidgetController.php')))
        ->toContain('namespace App\Http\Controllers\Api\Widget;')
        ->not->toContain('function create');
    $routes = file_get_contents(base_path('routes/api.php'));
    expect($routes)
        ->toContain('use Illuminate\Support\Facades\Route;')
        ->toContain('use App\Http\Controllers\Api\Widget\WidgetController;')
        ->toContain("Route::post('/', 'store')->name('store');")
        ->toContain("Route::patch('/{widget}', 'update');")
        ->not->toContain("'create'")
        ->not->toContain("'edit'")
        ->not->toContain('apiResource');
    expect(is_dir(resource_path('views/widgets')))->toBeFalse();

    $this->artisan('make:module', ['name' => 'Widget', '--api' => true, '--force' => true])->assertExitCode(0);
    expect(substr_count(file_get_contents(base_path('routes/api.php')), "->name('widgets.')"))->toBe(1);
});

it('skips parts with --except', function () {
    $this->artisan('make:module', ['name' => 'Widget', '--except' => ['views', 'test', 'migration', 'controller', 'routes']])
        ->assertExitCode(0);

    expect(file_exists(app_path('Http/Controllers/Widget/WidgetController.php')))->toBeFalse();
    expect(file_exists(base_path('routes/web.php')) ? file_get_contents(base_path('routes/web.php')) : '')
        ->not->toContain('widgets');

    expect(file_exists(app_path('Models/Widget.php')))->toBeTrue();
    expect(glob(database_path('migrations/*_create_widgets_table.php')))->toBe([]);
    expect(is_dir(resource_path('views/widgets')))->toBeFalse();
    expect(file_exists(base_path('tests/Feature/Widget/WidgetTest.php')))->toBeFalse();
});

it('rejects an unknown part and an invalid name', function () {
    $this->artisan('make:module', ['name' => 'Widget', '--except' => ['bogus']])->assertExitCode(1);
    $this->artisan('make:module', ['name' => '!!!'])->assertExitCode(1);
});

it('imports the controller once and keeps existing imports intact', function () {
    file_put_contents(base_path('routes/web.php'), "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Http\\Controllers\\HomeController;\n\nRoute::get('/', HomeController::class);\n");

    $this->artisan('make:module', ['name' => 'Widget', '--except' => ['model', 'migration', 'requests', 'service', 'repository', 'test', 'views']])
        ->assertExitCode(0);

    $routes = file_get_contents(base_path('routes/web.php'));

    expect($routes)
        ->toContain("use App\\Http\\Controllers\\HomeController;\nuse App\\Http\\Controllers\\Widget\\WidgetController;")
        ->toContain("Route::get('/', HomeController::class);")
        ->toContain("->controller(WidgetController::class)->group(");
    expect(substr_count($routes, 'use Illuminate\\Support\\Facades\\Route;'))->toBe(1);
});

it('never uses a Web/ controller folder, even if one is left over', function () {
    mkdir(app_path('Http/Controllers/Web'), 0777, true);

    try {
        $this->artisan('make:module', ['name' => 'Widget', '--except' => ['model', 'migration', 'requests', 'service', 'repository', 'test', 'views', 'routes']])
            ->assertExitCode(0);

        expect(file_exists(app_path('Http/Controllers/Widget/WidgetController.php')))->toBeTrue();
        expect(file_exists(app_path('Http/Controllers/Web/Widget/WidgetController.php')))->toBeFalse();
    } finally {
        (new Illuminate\Filesystem\Filesystem())->deleteDirectory(app_path('Http/Controllers/Web'));
    }
});
