# Release workflow

## "update version"
Update the version to the same value in all places:
- `lb-simple-podcast.php`: the `Version:` header and the `LB_SP_VERSION` constant
- `package.json` and `package-lock.json` (root `version` and `packages[""].version`)
- `readme.txt`: `Stable tag` and a new entry in the changelog

If the user gives a number, use it. Otherwise bump the minor version.

## "publish it"
1. Update version (see above).
2. Commit and push to a feature branch (never directly to `main`).
3. Create a pull request to `main` and merge it.
4. Publish a new GitHub release whose tag and title are exactly the version (for example `0.2.0`, no `v` prefix).
