# Laravel AI Librarium

A Laravel-native research query abstraction built on the official [Laravel AI SDK](https://github.com/laravel/ai).

> This package is under active development and does not yet have a stable release.

## Direction

```php
use Jkudish\LaravelAiLibrarium\Facades\Librarium;

$research = Librarium::query('Compare current agent memory architectures.');
```

The initial package intentionally exposes only the query boundary. Profiles, provider selection, execution policies, fan-out, fallback, and durable execution will be added after their shared semantic contracts are approved.

## Boundaries

- The core package does not publish database tables or own consumer persistence.
- Applications own evidence policy, verification thresholds, conversations, and domain workflows.
- TypeScript Librarium remains the local-agent and CLI runtime; both implementations share a language-neutral interchange rather than runtime internals.
- Substantial optional integrations, including Firecrawl, live in separate adapter packages.

## Requirements

- PHP 8.4+
- Laravel 13
- Laravel AI SDK 0.10.3+

## Development

```bash
composer install
composer test
composer analyse
composer format
```
