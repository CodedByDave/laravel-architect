<?php

use LaravelArchitect\Architect\Tests\TestCase;

uses(TestCase::class);

it('rejects an unknown layer', function () {
    $this->artisan('architect:scan', ['--layer' => ['bogus']])->assertExitCode(1);
});

it('runs a scoped repository scan', function () {
    $this->artisan('architect:scan', ['--layer' => ['repositories']])
        ->expectsOutputToContain('Scope')
        ->assertExitCode(0);
});

it('runs a deep scan', function () {
    $this->artisan('architect:scan', ['--deep' => true])->assertExitCode(0);
});
