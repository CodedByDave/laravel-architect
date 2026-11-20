# Laravel Architect

[![CI](https://github.com/CodedByDave/laravel-architect/actions/workflows/ci.yml/badge.svg)](https://github.com/CodedByDave/laravel-architect/actions/workflows/ci.yml)

Organize Laravel codebases into clean, configurable architectural layers.

Laravel ships with MVC, but no generators for the layers many teams add on top, such as services and repositories. Laravel Architect adds those commands, and a read-only scanner that tells you where classes are misplaced and why.

> **Status:** early release (`v0.x`). Expect changes before `1.0`.

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require laravel-architect/architect --dev
```

The service provider is registered automatically through Laravel package discovery.

## Usage

### Create a service

```bash
php artisan make:service OrderService
```

Creates `app/Services/OrderService.php`.

Use a slash to group classes in a subfolder:

```bash
php artisan make:service Payment/PaymentService
```

Creates `app/Services/Payment/PaymentService.php` with the namespace `App\Services\Payment`.

### Create a repository

```bash
php artisan make:repository Payment/PaymentRepository
```

Creates a repository class under `app/Repositories/Payment/`.

### Create a domain (service + repository)

```bash
php artisan make:domain Customer
```

Creates both classes in their layer folders:

```
app/Services/Customer/CustomerService.php
app/Repositories/Customer/CustomerRepository.php
```

| Option | Effect |
|---|---|
| `--service` | Create only the service |
| `--repository` | Create only the repository |
| `--modular` | Group by domain instead: `app/Customer/Services/...` and `app/Customer/Repositories/...` |
| `--force` | Overwrite existing files |

`make:service` and `make:repository` also accept `--force`.

### Scaffold a full module

```bash
php artisan make:module Customer
```

Creates everything for one feature in a single step:

```
app/Models/Customer.php
database/migrations/xxxx_create_customers_table.php
app/Http/Requests/Customer/StoreCustomerRequest.php
app/Http/Requests/Customer/UpdateCustomerRequest.php
app/Services/Customer/CustomerService.php
app/Repositories/Customer/CustomerRepository.php
app/Http/Controllers/Customer/CustomerController.php    (resource controller)
routes/web.php   (+ use ...\CustomerController; and explicit get/post/put/patch/delete routes)
tests/Feature/Customer/CustomerTest.php        (Pest if installed, otherwise PHPUnit)
resources/views/customers/{index,create,edit,show}.blade.php
```

| Option | Effect |
|---|---|
| `--api` | API module: `Api/Customer/CustomerController` and explicit routes in `routes/api.php`, no blade views |
| `--except=views` | Skip parts: `model`, `migration`, `requests`, `service`, `repository`, `controller`, `routes`, `test`, `views`. Repeatable |
| `--modular` | Put the service and repository under `app/Customer/` instead of the layer folders |
| `--force` | Overwrite existing files |

The routes are written out explicitly (no `Route::resource`), grouped under one prefix and controller:

```php
Route::prefix('customers')->name('customers.')->controller(CustomerController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{customer}', 'show')->name('show');
    Route::get('/{customer}/edit', 'edit')->name('edit');
    Route::put('/{customer}', 'update')->name('update');
    Route::patch('/{customer}', 'update');
    Route::delete('/{customer}', 'destroy')->name('destroy');
});
```

With `--api` the `create` and `edit` routes are left out. Running it again never duplicates a route. The blade views are bare starter pages with no layout, so wrap them in your own. With `--api`, if `bootstrap/app.php` doesn't register API routes yet, the command tells you to run `php artisan install:api`.

### Scan your project

```bash
php artisan architect:scan
```

Read-only: no files are changed. It reports controllers, models, routes, layers and recommendations, for example:

```
* Move ShopOptionsRepository into the Shop folder: app/Repositories/ShopOptionsRepository.php -> app/Repositories/Shop/ShopOptionsRepository.php
    Why: ShopOptionsRepository reads and writes ShopOption model through getOptions() and updateOptions(), so it only deals with Shop concerns. Shop already holds ShopService, so keep them together.
```

| Option | Effect |
|---|---|
| `--layer=repositories` | Scan only one layer. Repeatable. Choose from `repositories`, `services`, `actions`, `controllers`, `models` |
| `--deep` | Look for misplaced classes anywhere under `app/`, not only at the top of each layer folder |
| `--json` | Print the full result as JSON |
| `-v` | Per-controller details |

```bash
php artisan architect:scan --layer=repositories
php artisan architect:scan --deep --layer=services --layer=repositories
```

**How domains are found.** Any business folder under `app/` (for example `app/Customer`, `app/Modules/Order` or `app/Repositories/Invoice`) counts as a domain, with no configuration. A loose class whose name contains the domain, such as `CustomerAccountRepository`, is recommended into that folder.

**Why each reason is specific.** The scanner reads each class: the tables and models it uses, its public methods, any external API it calls, and what the domain folder already holds. So `PaymongoRepository` and `ShopOptionsRepository` get different explanations.

To map provider names to a domain (`PaymongoRepository` belongs to `Payment`), edit `domains` in the config:

```php
'domains' => [
    'Payment' => [
        'label' => 'payment gateway',
        'keywords' => ['paymongo', 'stripe', 'paypal', 'gcash'],
    ],
],
```

The scan only recommends moves. After moving a class, update its namespace and the places that import it.

### Architecture report

```bash
php artisan architect:report
```

Scores the project from 0 to 100 and lists what to fix. It is read-only and builds on the same scanner as `architect:scan`.

It reports:

- **Score and grade** for how well the code follows the layers (errors cost more than warnings, normalised by project size).
- **Inventory per domain**: controllers, models, services, repositories, actions, form requests and matching tests.
- **Layer violations**: a repository calling a service, a model calling a controller, a service returning a `Response`.
- **Misplaced classes**, with the suggested move (same detector as `architect:scan`).
- **Fat controllers**: methods over 25 lines, too many methods or lines, direct Eloquent or `DB::` calls, inline validation.
- **Coverage gaps**: a domain with a controller but no service or repository, and services with no matching test.
- **Naming problems**: `OrderServices`, a repository without the `Repository` suffix, a class that does not match its file name.
- **Orphans**: services, repositories and actions that nothing injects or uses (providers, routes, config and Blade views count as uses).

| Option | Effect |
|---|---|
| `--format=table\|json\|markdown` | Output format (default `table`) |
| `--output=report.md` | Write the report to a file (markdown, or JSON with `--format=json`) |
| `--domain=Customer` | Report on one domain only |
| `--min-score=80` | Exit with code 1 when the score is lower, so CI can fail the build |
| `--deep` | Look for misplaced classes anywhere under `app/` |

Thresholds for fat controllers live under `report` in `config/architect.php`.

A test counts as matching when its file name contains the service's class name or its domain name (`OrderServiceTest`, `OrderFlowTest`).
## Customizing

Publish the config file:

```bash
php artisan vendor:publish --tag=architect-config
```

Publish the stubs to edit the generated code:

```bash
php artisan vendor:publish --tag=architect-stubs
```

Published stubs are placed in `stubs/architect` and used instead of the package defaults.

## Roadmap

- [x] `make:service`
- [x] `make:repository`
- [x] `make:domain`
- [x] `architect:scan` (read-only analysis, scoped and deep scans, misplaced-class recommendations)
- [x] `architect:report` (score, inventory, violations, fat controllers, gaps, naming, orphans)
- [ ] `architect:routes --dry-run` (group and split route files)
- [ ] Controller reorganization with namespace and import updates
- [ ] `architect:check` (detect architecture violations)

Every command that moves or rewrites files will support `--dry-run` and will refuse to run on a dirty git tree.

## Testing

```bash
composer install
vendor/bin/pest
```

CI runs the suite on PHP 8.2, 8.3 and 8.4 with Laravel 11 and 12, on Ubuntu and Windows.

## Contributing

Issues and pull requests are welcome at <https://github.com/CodedByDave/laravel-architect>. Work happens on feature branches that are tested by CI and merged through pull requests. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.