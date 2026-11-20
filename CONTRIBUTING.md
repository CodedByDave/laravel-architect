# Contributing

`main` is always releasable. Every change, whether a feature, fix or doc update, is made on its own branch, tested by CI, and merged through a pull request.

## Workflow

```bash
git checkout main
git pull
git checkout -b feature/make-module-controller     # 1. branch from an up-to-date main

# ...make changes, then run the tests locally
composer install
vendor/bin/pest

git add -A
git commit -m "feat: add controller to make:module"  # 2. commit
git push -u origin feature/make-module-controller    # 3. push: CI runs on the branch
```

4. Open a pull request into `main` and wait for CI to pass (all PHP / Laravel / OS jobs).
5. Squash-merge, then delete the branch.

## Branch names

| Prefix | Use for | Example |
|---|---|---|
| `feature/` | New commands or behaviour | `feature/make-module` |
| `fix/` | Bug fixes | `fix/route-import-clash` |
| `docs/` | README and documentation only | `docs/scan-options` |
| `chore/` | CI, tooling, dependencies, refactors | `chore/branching-workflow` |

Use lowercase and hyphens, and keep the name short and specific.

## Commit messages

Start with the same type as the branch: `feat:`, `fix:`, `docs:`, `chore:`, `test:`.

## Rules

- Never commit directly to `main`.
- Every new command or option comes with a test in `tests/`.
- CI must be green before merging.
- Releases are tags on `main`, for example `git tag v0.2.0 && git push --tags`. Packagist picks them up automatically.

## Recommended GitHub settings

In the repository settings, add a branch protection rule for `main`:

- Require a pull request before merging
- Require status checks to pass (select the `CI` jobs)
- Automatically delete head branches after merge
