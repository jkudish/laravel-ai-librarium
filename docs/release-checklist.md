# PHP 1.0 release checklist

The candidate is `release/1.0-prep`, not `main`. This checklist authorizes no
provider call, signoff, merge, tag, release, migration or publication.

## Offline compatibility

In isolated checkouts with separate vendor directories:

```bash
# PHP 8.4
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist
composer test
composer analyse
composer format:check
composer validate --strict
composer test:conformance
composer show --direct

# PHP 8.5: same checks after highest resolution
composer update --prefer-stable --no-interaction --prefer-dist
```

Retain PHP/Composer versions and resolved package inventory with the candidate
evidence. Do not commit the library's lock file. Tests must prevent provider
traffic. Shared selection, valid/invalid Runs, and run-store contract fixtures
come from the exact `librarium-spec` pin. Read the shared unencrypted folder
fixture and prove quick/queued-deep/find/refresh smoke tests through all stores.

## Shared data and readiness

`composer release:check` checks stable runtime constraints, compatibility
floors, the dated first-release changelog and canonical evidence gates in
`tools/release-readiness.json`. The development spec pin deliberately blocks
publication until replaced by a compatible stable release. Do not restore the
removed TypeScript repin or vendored contract machinery.

Gate evidence is a durable HTTPS URI without userinfo/query/fragment plus its
lowercase SHA-256. The checker validates shape, not remote evidence content.
Reconcile gates from accepted PlanMode outcomes; historical receipts are not
proof that the new candidate ran those scenarios. Pricing remains owned by
`laravel-ai-pricing ^0.3.1`, not the spec package.

## Exact candidate

After committing every intended change and requiring a clean worktree:

```bash
composer pr:check
```

Setup runs separately. The offline gate stores an exact-SHA, repository, plan,
runtime and Composer-package-bound private receipt under Git administrative
storage. Any drift invalidates it. It performs no installation, provider call,
release, deployment or GitHub status write.

Open the authorized draft PR to the candidate branch and request independent
review. Only a separate human approval of its full lowercase SHA permits
`pr:signoff`. This worker must never run signoff. Signoff grants no merge or
publication authority. Inspect the package archive to exclude credentials,
vendor, tests, tools, local environment files and composer.lock.

## External gates

Live canaries need newly scoped approval, exact heads, isolated credentials,
one paid submission, an enforceable per-call/aggregate cap and sanitized
receipts. Follow [live canary policy](live-provider-canaries.md). Offline
fakes prove package behavior, not provider enforcement. No old approval is
reused. Final publication, tag creation, release and merge each need explicit
authority, and the changelog date must be set to the actual publication date.
