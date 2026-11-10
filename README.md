# Laravel Architect

Organize Laravel codebases into clean, configurable architectural layers.

Laravel ships with MVC, but no generators for the layers many teams add on top, such as services and repositories. Laravel Architect adds those commands, and aims to grow into a toolkit that helps you keep a codebase organized.

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
- [ ] `architect:scan` and `architect:report` (read-only analysis of an existing project)
- [ ] `architect:routes --dry-run` (group and split route files)
- [ ] Controller reorganization with namespace and import updates
- [ ] `architect:check` (detect architecture violations)

Every command that moves or rewrites files will support `--dry-run` and will refuse to run on a dirty git tree.

## Testing

```bash
composer install
vendor/bin/pest
```

## Contributing

Issues and pull requests are welcome at <https://github.com/CodedByDave/laravel-architect>.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.