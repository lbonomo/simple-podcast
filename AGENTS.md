# Release workflow

## Update version

When asked to update the version, use the same value in all of these places:

- `lb-simple-podcast.php`: the `Version:` header and the `LB_SP_VERSION` constant
- `package.json` and `package-lock.json`: the root `version` and `packages[""].version`
- `readme.txt`: `Stable tag` and a new changelog entry

Use the version supplied by the user. If none is provided, bump the minor version.

## Publish

When asked to publish:

1. Update the version using the steps above.
2. Commit and push to a feature branch; never push directly to `main`.
3. Create and merge a pull request targeting `main`.
4. Publish a GitHub release whose tag and title exactly match the version (for example, `0.2.0`, without a `v` prefix).
