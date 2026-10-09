# Development Contract Snapshot

`v1/` is a byte-for-byte copy of `contracts/v1` from
[`jkudish/librarium`](https://github.com/jkudish/librarium/tree/4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3) commit
`4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3`. It carries no PHP-local adjustments.

Pinned facts (from `tests/Support/typescript-pin.json`):

- Upstream ref: `4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3`
- Release tag: `none`
- Package version: `2.0.0`
- Contract directory: `v1`
- Contract scope: terminal interchange only
- Manifest SHA-256: `7afd208b5ed7598f7700f0c7f73bdd395431d8143e25a05a1a25aa14aa9c09ac`
- Checksum index SHA-256: `0185cfba96f8c802879729d7981c03cdb479390d8032a5a255bc9c576d5c64a0`
- Interchange schema SHA-256: `d1a3471c72175bc487d7c50c56031d78b7f3e4c7af8527d7c45c146313ed87a4`
- Schemas: 1
- Top-level schema definitions: 7
- Valid fixtures: 18
- Invalid fixtures: 41
- Semantic rules: 6
- Manifest entries: 62, including `manifest.json`
- Checksum entries: 62, including `manifest.json`
- Total snapshot files: 63, including `checksums.sha256`

Do not edit these files by hand. Regenerate the snapshot, the Profile
inventory, and every pin with:

```bash
composer typescript:repin -- /path/to/librarium <ref>
```

The checkout must be clean with `<ref>` checked out and its npm
dependencies installed. The PHP verifier itself runs offline and adds no
TypeScript, npm, or Node runtime dependency. The snapshot is test support
only and is not part of the package's public API.

This is a development pin to ref `4c0d3d6383e37d37972fd3fe5f947d5a3972bbb3`. Before the final package
release, rerun `composer typescript:repin` against the accepted Librarium
`v2.0.0` Git tag; the release check blocks until the pin names it.
