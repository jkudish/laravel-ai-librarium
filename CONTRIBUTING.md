# Contributing

Open an issue before starting a large API or provider change. Keep application
persistence, Eloquent models, UI, and domain workflows outside the core package.

Source names, options, Run schemas, and shared fixtures belong to
[librarium-spec](https://github.com/jkudish/librarium-spec). Propose changes to
shared semantics there. The JavaScript sibling is
[jkudish/librarium](https://github.com/jkudish/librarium); it shares Run JSON
and fixtures with this package, not its runtime architecture.

## Setup

Use PHP 8.4 or newer with Composer.

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

Run one file while you work, for example
`vendor/bin/pest tests/Feature/RunLifecycleTest.php`.

Tests use Testbench, Laravel AI SDK fakes, and HTTP fakes. They make no
provider calls. Compatibility is checked on PHP 8.4 with `--prefer-lowest` and
on PHP 8.5 with the highest allowed dependencies; see the
[release checklist](docs/release-checklist.md).

`composer format` changes files. Use it only when you intend to apply Pint
formatting.

The Redis store test needs a disposable loopback Redis 7+ server:

```bash
LIBRARIUM_REDIS_TEST_PORT=<port> composer test:redis
```

Live tests are paid, opt-in release checks and require separate authorization.
See [live provider canaries](docs/live-provider-canaries.md).

## Pull requests

Before requesting review, commit the intended changes, require a clean
worktree, and run:

```bash
composer pr:check
```

This local exact-SHA check does not push, open a pull request, sign off, merge,
publish, release, deploy, or call a provider.

In the pull request, include the behavior changed, the checks run, and any
provider or compatibility claim that still needs evidence.
