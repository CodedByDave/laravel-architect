<?php

use LaravelArchitect\Architect\Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    $files = new Illuminate\Filesystem\Filesystem();
    foreach (['Customer', 'Services/Customer', 'Repositories/Customer'] as $dir) {
        $files->deleteDirectory(app_path($dir));
    }
});

it('creates a service and repository inside each layer folder', function () {
    $this->artisan('make:domain', ['name' => 'Customer'])->assertExitCode(0);

    $service = app_path('Services/Customer/CustomerService.php');
    $repository = app_path('Repositories/Customer/CustomerRepository.php');

    expect(file_exists($service))->toBeTrue();
    expect(file_exists($repository))->toBeTrue();
    expect(file_get_contents($service))
        ->toContain('namespace App\Services\Customer;')
        ->toContain('class CustomerService');
    expect(file_get_contents($repository))
        ->toContain('namespace App\Repositories\Customer;')
        ->toContain('class CustomerRepository');
});

it('creates only the repository with --repository', function () {
    $this->artisan('make:domain', ['name' => 'Customer', '--repository' => true])->assertExitCode(0);

    expect(file_exists(app_path('Repositories/Customer/CustomerRepository.php')))->toBeTrue();
    expect(file_exists(app_path('Services/Customer/CustomerService.php')))->toBeFalse();
});

it('rejects an invalid name', function () {
    $this->artisan('make:domain', ['name' => '!!!'])->assertExitCode(1);
});

it('groups by domain folder with --modular', function () {
    $this->artisan('make:domain', ['name' => 'Customer', '--modular' => true])->assertExitCode(0);

    expect(file_get_contents(app_path('Customer/Services/CustomerService.php')))
        ->toContain('namespace App\Customer\Services;');
    expect(file_exists(app_path('Customer/Repositories/CustomerRepository.php')))->toBeTrue();
});
