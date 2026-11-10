<?php

use LaravelArchitect\Architect\Tests\TestCase;

uses(TestCase::class);

it('creates a service class in app/Services', function () {
    $path = app_path('Services/OrderService.php');

    @unlink($path);

    $this->artisan('make:service', ['name' => 'OrderService'])
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))
        ->toContain('namespace App\Services;')
        ->toContain('class OrderService');

    @unlink($path);
});
