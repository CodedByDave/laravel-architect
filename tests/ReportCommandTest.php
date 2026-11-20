<?php

use LaravelArchitect\Architect\Report\ReportBuilder;
use LaravelArchitect\Architect\Tests\TestCase;

uses(TestCase::class);

function reportProject(array $files): string
{
    $base = sys_get_temp_dir().'/architect_report_'.uniqid();

    foreach ($files as $path => $content) {
        $full = $base.'/'.$path;
        is_dir(dirname($full)) || mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    return $base;
}

function messyProject(): string
{
    return reportProject([
        'app/Models/Customer.php' => "<?php\nnamespace App\\Models;\nclass Customer {}\n",
        'app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order {}\n",
        'app/Models/Product.php' => "<?php\nnamespace App\\Models;\nclass Product {}\n",
        'app/Http/Requests/StoreOrderRequest.php' => "<?php\nnamespace App\\Http\\Requests;\nclass StoreOrderRequest {}\n",

        // Calls Eloquent and the DB facade directly.
        'app/Http/Controllers/CustomerController.php' => <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        use App\Models\Customer;
        use Illuminate\Support\Facades\DB;
        class CustomerController
        {
            public function store() { DB::table('customers')->insert([]); return Customer::create([]); }
            public function update() { $this->validate(request(), []); }
        }
        PHP,

        // Clean: uses the service.
        'app/Http/Controllers/OrderController.php' => <<<'PHP'
        <?php
        namespace App\Http\Controllers;
        use App\Services\OrderService;
        class OrderController
        {
            public function __construct(private OrderService $orders) {}
            public function index() { return $this->orders->all(); }
        }
        PHP,

        // A controller with no service or repository behind it.
        'app/Http/Controllers/ProductController.php' => "<?php\nnamespace App\\Http\\Controllers;\nclass ProductController { public function index() {} }\n",

        'app/Services/OrderService.php' => <<<'PHP'
        <?php
        namespace App\Services;
        use App\Repositories\OrderRepository;
        class OrderService
        {
            public function __construct(private OrderRepository $orders) {}
            public function all() { return $this->orders->all(); }
            public function export() { return response()->json([]); }
        }
        PHP,

        // Repository depending on a service (violation), also wrongly named.
        'app/Repositories/OrderRepository.php' => <<<'PHP'
        <?php
        namespace App\Repositories;
        use App\Services\OrderService;
        class OrderRepository
        {
            public function all(OrderService $service) { return []; }
        }
        PHP,

        'app/Repositories/CustomerStore.php' => "<?php\nnamespace App\\Repositories;\nclass CustomerStore {}\n",
        'app/Services/ReportServices.php' => "<?php\nnamespace App\\Services;\nclass ReportServices {}\n",
        'app/Services/InvoiceRepository.php' => "<?php\nnamespace App\\Services;\nclass InvoiceRepository {}\n",
        'app/Services/Mismatch.php' => "<?php\nnamespace App\\Services;\nclass SomethingElseService {}\n",
        'tests/Unit/OrderServiceTest.php' => "<?php\n",
    ]);
}

function findingsOf(array $report, string $category): array
{
    return array_values(array_filter($report['findings'], fn (array $f): bool => $f['category'] === $category));
}

it('counts the layers per domain', function () {
    $report = (new ReportBuilder(messyProject()))->build();

    expect($report['inventory']['domains']['Order'])->toMatchArray(['controllers' => 1, 'models' => 1, 'services' => 1, 'repositories' => 1, 'requests' => 1, 'tests' => 1]);
    expect($report['inventory']['domains']['Customer'])->toMatchArray(['controllers' => 1, 'models' => 1, 'services' => 0, 'repositories' => 0]);
    expect($report['inventory']['totals'])->toMatchArray(['controllers' => 3, 'models' => 3, 'requests' => 1]);
});

it('flags a repository that depends on a service and a service that returns a response', function () {
    $violations = findingsOf((new ReportBuilder(messyProject()))->build(), 'layer_violation');
    $messages = array_column($violations, 'message');

    expect($violations)->toHaveCount(2);
    expect(array_filter($messages, fn ($m) => str_contains($m, 'OrderRepository (repository) depends on a higher layer: OrderService (service)')))->toHaveCount(1);
    expect(array_filter($messages, fn ($m) => str_contains($m, 'OrderService (service) returns an HTTP response from export()')))->toHaveCount(1);
    expect(array_unique(array_column($violations, 'severity')))->toBe(['error']);
});

it('flags controllers that touch Eloquent or the DB directly, or validate inline', function () {
    $fat = findingsOf((new ReportBuilder(messyProject()))->build(), 'fat_controller');

    expect($fat)->toHaveCount(1);
    expect($fat[0]['file'])->toBe('app/Http/Controllers/CustomerController.php');
    expect($fat[0]['severity'])->toBe('warning');
    expect($fat[0]['message'])->toContain('calls Eloquent directly (Customer::create)')
        ->toContain('uses the DB facade directly')
        ->toContain('validates inline');
});

it('skips the direct-database smell when no business layer is enabled', function () {
    $fat = findingsOf((new ReportBuilder(messyProject(), ['layers' => ['services' => false, 'repositories' => false, 'actions' => false]]))->build(), 'fat_controller');

    expect($fat[0]['severity'])->toBe('info');
    expect($fat[0]['message'])->not->toContain('Eloquent');
});

it('reports naming problems', function () {
    $messages = array_column(findingsOf((new ReportBuilder(messyProject()))->build(), 'naming'), 'message');
    $text = implode("\n", $messages);

    expect($text)->toContain('ReportServices ends in "Services"; use the singular "Service"')
        ->toContain('InvoiceRepository is named like a repository but lives in app/Services')
        ->toContain('CustomerStore lives in app/Repositories but its name does not end in "Repository"')
        ->toContain('The class is SomethingElseService but the file is Mismatch.php')
        ->not->toContain('OrderService lives');
});

it('reports services and repositories nothing uses', function () {
    $orphans = array_column(findingsOf((new ReportBuilder(messyProject()))->build(), 'orphan'), 'class');

    expect($orphans)->toContain('ReportServices', 'CustomerStore')
        ->not->toContain('OrderService', 'OrderRepository');
});

it('counts a binding in a provider or a route file as a use', function () {
    $base = reportProject([
        'app/Services/PayoutService.php' => "<?php\nnamespace App\\Services;\nclass PayoutService {}\n",
        'app/Providers/AppServiceProvider.php' => "<?php\nnamespace App\\Providers;\nuse App\\Services\\PayoutService;\nclass AppServiceProvider { public function register() { \$this->app->singleton(PayoutService::class); } }\n",
        'app/Repositories/LedgerRepository.php' => "<?php\nnamespace App\\Repositories;\nclass LedgerRepository {}\n",
        'config/ledger.php' => "<?php\nreturn ['repository' => 'App\\\\Repositories\\\\LedgerRepository'];\n",
    ]);

    expect(findingsOf((new ReportBuilder($base))->build(), 'orphan'))->toBe([]);
});

it('finds a domain with a controller but no service or repository, and services without tests', function () {
    $report = (new ReportBuilder(messyProject()))->build();
    $gaps = array_column(findingsOf($report, 'coverage_gap'), 'message');

    expect($gaps)->toContain('Product has a controller and a model but no service or repository.');
    expect($gaps)->toContain('Customer has a controller and a model but no service or repository.');

    $untested = array_column(findingsOf($report, 'test_gap'), 'class');
    expect($untested)->toContain('ReportServices')->not->toContain('OrderService');
});

it('scores a clean project 100 and a messy one lower', function () {
    $clean = reportProject([
        'app/Models/Order.php' => "<?php\nnamespace App\\Models;\nclass Order {}\n",
        'app/Services/OrderService.php' => "<?php\nnamespace App\\Services;\nclass OrderService {}\n",
        'app/Http/Controllers/OrderController.php' => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Services\\OrderService;\nclass OrderController { public function __construct(OrderService \$s) {} }\n",
        'tests/Feature/OrderTest.php' => "<?php\n",
    ]);

    $score = (new ReportBuilder($clean))->build()['score'];
    expect($score['value'])->toBe(100)->and($score['grade'])->toBe('A');

    $messy = (new ReportBuilder(messyProject()))->build()['score'];
    expect($messy['value'])->toBeLessThan(80)->and($messy['by_severity']['error'])->toBe(2);
});

it('can be narrowed to one domain', function () {
    $report = (new ReportBuilder(messyProject()))->build('customer');

    expect(array_keys($report['inventory']['domains']))->toBe(['Customer']);
    expect(array_unique(array_column($report['findings'], 'domain')))->toBe(['Customer']);
});

it('skips files it cannot parse instead of crashing', function () {
    $base = reportProject(['app/Services/Broken.php' => "<?php\nclass {{{\n"]);

    expect((new ReportBuilder($base))->build()['unparsed'])->toBe(['app/Services/Broken.php']);
});

it('prints the report as a table', function () {
    $this->artisan('architect:report')
        ->expectsOutputToContain('Architecture Report')
        ->expectsOutputToContain('Inventory per domain')
        ->assertExitCode(0);
});

it('prints json', function () {
    $this->artisan('architect:report', ['--format' => 'json'])->expectsOutputToContain('"score"')->assertExitCode(0);
});

it('writes a markdown report to a file', function () {
    $file = sys_get_temp_dir().'/architect_report_'.uniqid().'.md';

    $this->artisan('architect:report', ['--output' => $file])->assertExitCode(0);

    expect(file_get_contents($file))->toContain('# Architecture report')->toContain('## Inventory');
});

it('fails below the minimum score', function () {
    $this->artisan('architect:report', ['--min-score' => 101])->assertExitCode(1);
    $this->artisan('architect:report', ['--min-score' => 100])->assertExitCode(0);
});

it('rejects an unknown format and an unknown domain', function () {
    $this->artisan('architect:report', ['--format' => 'xml'])->assertExitCode(1);
    $this->artisan('architect:report', ['--domain' => 'Nope'])->assertExitCode(1);
});
