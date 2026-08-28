# Agent instructions

## Purpose

This repository contains `jkudish/laravel-ai-librarium`, a Laravel-native research abstraction built on the official Laravel AI SDK.

## Sibling implementation

- The TypeScript sibling is [`jkudish/librarium`](https://github.com/jkudish/librarium), normally checked out locally at `../librarium`.
- Consult its accepted, released interchange contract and semantic fixtures when implementing cross-language behavior. Do not copy its CLI, Node, filesystem, or private runtime architecture into this package.

## Architecture boundaries

- Use Laravel AI SDK primitives before adding custom AI integration code.
- Keep the core package free of application-owned persistence, Eloquent models, migrations, UI, and TypeScript runtime artifacts.
- TypeScript Librarium and this package share semantic interchange, not internal runtime architecture.
- Consumers own evidence policy, verification thresholds, conversation storage, and domain workflows.
- Substantial optional SDKs or browser runtimes belong in separate adapter packages.
- Do not add selector, execution-policy, provider-roster, fallback, budget, or deadline APIs until their PlanMode checkpoint is approved.

## Code conventions

- Use PHP 8.4+, Laravel 13, strict types, explicit parameter and return types, constructor property promotion, and readonly value objects where appropriate.
- Prefer native Laravel service providers, container bindings, facades, configuration, events, queues, and testing tools.
- Use `config()`, never `env()` outside configuration files.
- Use Pest for tests, Pint for formatting, and Larastan/PHPStan for static analysis.
- Do not commit `composer.lock` for this library.

## Verification

GitHub Actions are not this repository's verification authority. GitHub is the
collaboration/status surface; signoff is manual and non-enforced unless a future
separately authorized ruleset changes that policy.

```bash
composer test
composer analyse
composer format:check
composer validate --strict
composer pr:check
```

`composer pr:check` is the repository-owned exact-SHA Amp-orb gate. Run setup
separately, commit every intended change, require a clean worktree, and run the
check at the final candidate HEAD. It uses only the allowlisted offline package
plan and writes its exact-SHA, repository-, plan-, and runtime-bound mode-`0600`
receipt beneath Git administrative storage. A changed SHA, plan, repository,
PHP policy, actual runtime, or installed Composer-package set invalidates the
receipt. Never add install, live test, provider/network, publication, release,
deployment, database, Node, or application-owned steps to this plan without
separate authorization.

After the branch is pushed and an open PR points to the same exact head, a human
must separately approve the full lowercase SHA before running:

```bash
composer pr:signoff -- --approved-sha <full-lowercase-sha>
```

Use the canonical `basecamp/gh-signoff` extension. Keep `GH_SIGNOFF_TOKEN`
dedicated to this repository with Contents read, Pull requests read, and Commit
statuses read/write. Do not print or inspect the credential. Candidate checks
and all pre-write subprocesses receive neither ambient GitHub credentials nor
the dedicated token; only the final exact unforced signoff subprocess maps it
to `GH_TOKEN`. Signoff approval grants no merge, ruleset, release, publish,
deploy, production, database, or provider-call authority.

## Git workflow

- Work on feature branches, never directly on `main`.
- Commit coherent changes, run deep review, push, and open a PR.
- Never force-push, amend, or merge without explicit approval.
