# Development Contract Snapshot

`v1/` is a development-only terminal snapshot derived from
[`jkudish/librarium`](https://github.com/jkudish/librarium) candidate commit
`889b0366b5baafff117198632e04b24e521ac93d`. The candidate's accepted domain
corpus vocabulary in `src/contracts/domain/semantic.ts` adds `specialized`;
this snapshot carries that value into the terminal schema and enum coverage
fixture while retaining the candidate's terminal-only structure.

Pinned facts:

- Contract directory: `v1`
- Snapshot version: `1`
- Contract scope: terminal interchange only
- Manifest SHA-256: `4b8667d98ea5334793e95ebf3c15c96518d42a5e0369ac5ed66fb599b0e1793f`
- Checksum index SHA-256: `ba0ecf38a6ade68d3b49d4c0d8bbbae74f05202ed718dd319c0c1bb0d341415f`
- Interchange schema SHA-256: `ef938d851eee61bb2ce96c11669b355179bbd4e6b82cea4a71914671ab3450a1`
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
