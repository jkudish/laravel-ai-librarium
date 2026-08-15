# Development Contract Snapshot

`v1/` is an exact, development-only copy of `contracts/v1` from
[`jkudish/librarium`](https://github.com/jkudish/librarium) at commit
`a838881f5804052690f61c783256cc00edd0acee`.

Pinned facts:

- Contract directory: `v1`
- Snapshot version: `1`
- Contract scope: terminal interchange only
- Manifest SHA-256: `d06553f4bb56bc50598ae0ee98b1da6172120bcf47c7f837fc04a5b5b16709ce`
- Checksum index SHA-256: `32857dd761dde938e28b36477d6a7c7e10cd8bb06e8395ac19590413470e7724`
- Interchange schema SHA-256: `637a0d93418bc742ecbcb07d84cecacd1f61168d8389645da349fdeebb6264cd`
- Schemas: 1
- Top-level schema definitions: 7
- Valid fixtures: 17
- Invalid fixtures: 39
- Semantic rules: 5
- Manifest entries: 59, including `manifest.json`
- Checksum entries: 59, including `manifest.json`
- Total snapshot files: 60, including `checksums.sha256`

The source generator prunes execution, lifecycle, artifact, and other runtime
contracts from this terminal snapshot. The PHP verifier runs offline. It does
not add a TypeScript, npm, or Node runtime dependency. The snapshot is test
support only and is not part of the package's public API.

This commit pin is a development baseline. Before the final package release,
replace it with the accepted Librarium v2 Git tag and repin all SHA-256 values.
A commit hash alone is not the release provenance gate.
