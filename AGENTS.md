# Local development and testing

The local WordPress environment uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (Docker). See [docs/setup.md](docs/setup.md) for full setup and troubleshooting details.

## Setup

Run all commands from the repository root:

```bash
composer install
npm ci && npm run build
npm run env:start
```

Integration and unit PHPUnit tests load the WordPress test suite via `tests/phpunit/bootstrap.php`, which defaults to `wp-env`'s `tests-mysql` container (`DB_NAME`: `wordpress_test`, `DB_USER`: `root`, `DB_PASSWORD`: `password`, `ABSPATH`: `/var/www/html`). If `wordpress_test` does not exist yet in the `tests-mysql` container, create it once:

```bash
docker exec <tests-mysql-container> mysql -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
```

## Running tests and checks

- **PHPUnit (full suite — unit and integration, requires `wp-env` running):**
  ```bash
  npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit
  ```
- **PHPUnit (unit suite only, also requires `wp-env` running because `bootstrap.php` boots WordPress):**
  ```bash
  npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit --testsuite unit
  ```
- **E2E tests (Playwright, requires `wp-env` running):**
  ```bash
  WP_BASE_URL=http://localhost:8888 npm run test:e2e
  ```
- **JavaScript unit tests (Jest, runs on host):**
  ```bash
  npm test
  ```
- **Code style and static analysis (runs on host):**
  ```bash
  composer phpcs
  composer phpstan
  npm run lint:js
  ```

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
