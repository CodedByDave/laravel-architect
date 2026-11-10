# Architect

Organize Laravel codebases into clean, configurable architectural layers.

> Status: early skeleton. Only `make:service` exists so far.

## Before you publish

1. Replace `yourname/architect` in `composer.json` with your real vendor/package name.
2. Replace the `YourName\Architect` namespace everywhere (composer.json, src/, tests/).
3. Run `composer dump-autoload`.

## Try it locally in a Laravel app

```bash
# inside your test Laravel app
composer config repositories.architect path ../architect
composer require yourname/architect:@dev

php artisan make:service OrderService
# => app/Services/OrderService.php
```

Publish config and stubs (optional):

```bash
php artisan vendor:publish --tag=architect-config
php artisan vendor:publish --tag=architect-stubs
```

## Run the package tests

```bash
composer install
vendor/bin/pest
```

## Roadmap

- [x] `make:service`
- [ ] `make:repository` (interface + Eloquent implementation + binding)
- [ ] `architect:scan` / `architect:report` (read-only, nikic/php-parser)
- [ ] `architect:routes --dry-run`
- [ ] Controller reorganization with namespace/import rewriting
- [ ] `architect:check` (architecture violations)

## Safety principles

- Every file-moving command supports `--dry-run`.
- Refuse to run on a dirty git tree.
- Layers are opt-in via `config/architect.php`.
