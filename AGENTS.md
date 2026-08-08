# Agent instructions

## Purpose

This repository contains `jkudish/laravel-ai-librarium`, a Laravel-native research abstraction built on the official Laravel AI SDK.

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

```bash
composer test
composer analyse
composer format
composer validate --strict
```

## Git workflow

- Work on feature branches, never directly on `main`.
- Commit coherent changes, run deep review, push, and open a PR.
- Never force-push, amend, or merge without explicit approval.
