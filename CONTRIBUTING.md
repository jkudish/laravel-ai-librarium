# Contributing

Open an issue before starting a large API or provider change. Keep application
persistence, migrations, UI, and domain workflows outside the core package.

## Setup

```bash
composer install
```

Do not commit `composer.lock`.

## Checks

```bash
composer test
composer test:conformance
composer analyse
composer format:check
composer validate --strict
```

`composer format` changes files. Use it only when you intend to apply Pint
formatting. Live tests are paid, opt-in release checks and require separate
authorization.

Before requesting review, commit the intended changes, require a clean
worktree, and run:

```bash
composer pr:check
```

This local exact-SHA check does not push, open a pull request, sign off, merge,
publish, release, deploy, or call a provider.

Include the behavior changed, the checks run, and any provider or compatibility
claim that still needs evidence in the pull request.
