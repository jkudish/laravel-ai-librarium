# Development Contract Snapshot

`v1/` is an exact, development-only copy of `contracts/v1` from
[`jkudish/librarium`](https://github.com/jkudish/librarium) at commit
`27236d2bdab90d0a77396666ae4acc344fcfb949`.

Pinned facts:

- Contract directory: `v1`
- Snapshot version: `1`
- Contract scope: terminal interchange only
- Manifest SHA-256: `4b8667d98ea5334793e95ebf3c15c96518d42a5e0369ac5ed66fb599b0e1793f`
- Checksum index SHA-256: `740b34d67fa2edfe25af93174080a6f9afb3d2a59b85919eb4fe7f2ead80bebd`
- Interchange schema SHA-256: `a9a0092fc26f64111d8e4ff0ade4d7e72d382e5f9f005649928b1b777e412002`
- Schemas: 1
- Top-level schema definitions: 7
- Valid fixtures: 17
- Invalid fixtures: 40
- Semantic rules: 6
- Manifest entries: 60, including `manifest.json`
- Checksum entries: 60, including `manifest.json`
- Total snapshot files: 61, including `checksums.sha256`

The source generator prunes execution, lifecycle, artifact, and other runtime
contracts from this terminal snapshot. The PHP verifier runs offline. It does
not add a TypeScript, npm, or Node runtime dependency. The snapshot is test
support only and is not part of the package's public API.

This commit pin is a development baseline. Before the final package release,
replace it with the accepted Librarium v2 Git tag and repin all SHA-256 values.
A commit hash alone is not the release provenance gate.
