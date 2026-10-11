# PHP 1.0 release checklist

This checklist separates package checks that are safe to run offline from live,
cross-repository, publication, and human-authorization gates. It prepares a
candidate; it does not authorize provider calls, GitHub writes, publication,
release, deployment, or merge.

## Read the current blockers

Run the fail-closed local readiness guard:

```bash
composer release:check
```

The command is expected to fail until every entry in
`tools/release-readiness.json` is complete, `tests/Support/typescript-pin.json`
pins the accepted TypeScript `v2.0.0` tag, `laravel-ai-pricing` uses a stable
constraint, and `CHANGELOG.md` has exactly one section, a dated 1.0.0 heading.
It fails closed if the vendored contract or Profile inventory drifts from the
pin record. It also pins the release compatibility policy to PHP
8.4+, Laravel 13.30+, Testbench 11.0+, `laravel-ai-pricing` 0.3+,
Laravel AI SDK 1.0.1+, Laravel Prompts 0.3.15+, CommonMark 2.10.2+, and
Standard Webhooks 1.0+.

A gate may be marked `complete` only with an `https` evidence URI without
userinfo, a query string, or a fragment, plus the lowercase SHA-256 digest of
the referenced evidence artifact:

```json
{
    "uri": "https://example.com/durable-evidence",
    "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
}
```

The digest gives reviewers a stable content identity; the offline checker
validates its shape but does not fetch or authenticate the referenced content.
Keep PlanMode as the task authority; reconcile the manifest from accepted
PlanMode outcomes rather than independently declaring a gate complete.

`release:check` makes no network or provider call. It checks repository state
only. Exact-SHA package verification comes later because recording its result
in the manifest would change the SHA being certified.

## Prove the supported compatibility matrix

This library does not commit `composer.lock`. Run each lane in a clean,
disposable checkout, then retain the PHP version, Composer version, and
`composer show --direct` output with the candidate evidence. Never reuse a
vendor directory between lanes.

### Lowest supported lane

Use PHP 8.4 and resolve the lowest stable versions allowed by `composer.json`:

```bash
php -v
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist
composer compatibility:check
composer show --direct
```

The resolved inventory must include Laravel 13.30, Laravel AI SDK 1.0.1,
`laravel-ai-pricing` 0.3.0, Laravel Prompts 0.3.15, CommonMark 2.10.2, and
Standard Webhooks 1.0.0. Each runtime floor is the lowest version this lane
installs and passes:

- Laravel starts at 13.30 because Composer's advisory filter blocks every
  earlier `laravel/framework` 13 release (PKSA-d5tc-s1qs-h781, debug-page XSS,
  affects below 13.30.0).
- Development dependencies are ranges, so this lane resolves the older Pest 4,
  PHPUnit 12, and Testbench 11.0 toolchain that Laravel 13.30 accepts, while
  the highest lane resolves the latest Pest 5, PHPUnit 13, and Testbench.
  Pest 5.3 needs PHPUnit 13.1, which only Testbench Core 11.6 accepts, and
  that conflicts with `laravel/framework` below 13.35.
- Laravel AI SDK 1.0.0 is excluded by
  [GHSA-6qhr-3g93-pxhw](https://github.com/laravel/ai/security/advisories/GHSA-6qhr-3g93-pxhw).
- Prompts below 0.3.15 fail the interactive console tests.
- Composer's advisory filter blocks CommonMark releases below 2.10.2.

`prefer-lowest` is the resolver input;
inspect the resulting inventory rather than assuming it selected the intended
floor. If another constraint raises a package above its declared minimum,
record the effective floor and reconcile `composer.json` before release. Keep
the `php-8.4-lowest-compatibility` manifest gate blocked until the complete lane
passes and its evidence is retained.

### Highest supported lane

Use PHP 8.5 and resolve the latest stable versions allowed by the same candidate:

```bash
php -v
composer update --prefer-stable --no-interaction --prefer-dist
composer compatibility:check
composer show --direct
```

This lane must exercise the latest allowed Laravel 13 and Laravel AI SDK 1.x.
Neither lane may add `composer.lock` to Git. Dependency resolution may access
Composer repositories during setup; `compatibility:check` itself runs only the
installed platform check, test suite, static analysis, and formatting check.
Keep the `php-8.5-highest-compatibility` manifest gate blocked until the
complete lane passes and its evidence is retained.

## Reconcile shared contracts

Run the focused conformance suite:

```bash
composer test:conformance
```

It verifies three independent boundaries:

1. the checksum-pinned terminal interchange schema and semantic fixtures;
2. the checksum-pinned TypeScript v2 Profile inventory against the PHP
   matrix, landed Profile semantics, and `quick`, `visibility`, and `deep`
   memberships; and
3. an application-defined Driver executing through the public Laravel query
   runtime without provider access.

`tests/Support/typescript-pin.json` records the pinned `jkudish/librarium`
ref, commit, tag, and every digest. Repin the contract snapshot, the Profile
inventory, the snapshot README, and the matrix authority block together from a
clean checkout with its npm dependencies installed:

```bash
composer typescript:repin -- /path/to/librarium <ref>
```

The PHP-only DataForSEO Profiles and `perplexity-low-frozen` remain explicit
extensions and are not rows in the TypeScript inventory. SerpBase Search and
News are shared parity rows.

## Complete external gates

Before creating the final package candidate:

- finish the SearchAPI/Firecrawl fidelity decision and SearchAPI canaries;
- finish DataForSEO acceptance;
- complete every remaining release-required canary under
  `live-provider-canaries.md`, each with separate approval and spend controls;
- publish a stable `laravel-ai-pricing` release, then replace `dev-main` with
  its compatible stable constraint;
- repin against the accepted TypeScript `v2.0.0` tag with
  `composer typescript:repin`; and
- set the final publication date on the single 1.0.0 changelog section.

These are completion gates, not authorization to perform them. A canary approval
does not authorize a retry or another Profile. Publication and GitHub writes
require their own explicit authorization.

PlanMode/Agentsy dogfood (#2594) remains a nonblocking follow-up rather than a
package release gate. Package and consumer compatibility—including the
application-defined Driver conformance proof above—remain required.

## Certify the exact candidate

After all intended files are committed and the worktree is clean:

```bash
composer release:check
composer pr:check
```

`pr:check` reruns the repository-owned ordered offline plan and writes its
exact-SHA receipt beneath Git administrative storage. Inspect the package
archive separately:

```bash
composer archive --format=tar --dir=/tmp
tar -tf /tmp/jkudish-laravel-ai-librarium-*.tar
```

The archive must contain runtime package files, package-owned
`config/librarium.php`, and documentation, but not `.agents`, `.github`,
`tests`, `tools`, credentials, local environment files, `vendor`, or
`composer.lock`.

Only after the branch and open pull request point at that same full SHA may a
human explicitly approve the exact lowercase SHA for `composer pr:signoff`.
That attestation still does not authorize merge, tag creation, publication, or
release.
