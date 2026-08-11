# Development Contract Snapshot

`v1/` is an exact, development-only copy of `contracts/v1` from
[`jkudish/librarium`](https://github.com/jkudish/librarium) at commit
`b3fbacef70ff41975eba131756f3f59cfddf8bd2`.

Pinned facts:

- Contract directory: `v1`
- Snapshot version: `1`
- Contract scope: terminal interchange only
- Manifest SHA-256: `32055999bc9a72cf9cc7a7fd5f14f1a40df36bd542dd1cb74d83da485b562404`
- Checksum index SHA-256: `0d1e11fdc7e3461d0f692a3cd2aa18c2fd713ff639289602664d20c69677129a`
- Schemas: 1
- Top-level schema definitions: 7
- Valid fixtures: 17
- Invalid fixtures: 34
- Semantic rules: 5
- Manifest entries: 54, including `manifest.json`
- Checksum entries: 54, including `manifest.json`
- Total snapshot files: 55, including `checksums.sha256`

The source generator prunes execution, lifecycle, artifact, and other runtime
contracts from this terminal snapshot. The PHP verifier runs offline. It does
not add a TypeScript, npm, or Node runtime dependency. The snapshot is test
support only and is not part of the package's public API.

This commit pin is a development baseline. Before the final package release,
replace it with the accepted Librarium v2 Git tag and repin both SHA-256 values.
A commit hash alone is not the release provenance gate.
