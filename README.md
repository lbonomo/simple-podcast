# LB Simple Podcast

WordPress plugin (`lb-simple-podcast`) that turns standard posts into podcast episodes by associating an MP3 file with each post, publishing an Apple Podcasts–compatible RSS feed, embedding an audio player, adding SEO metadata, and exposing an MCP-ready WordPress Ability.

- **Version:** `1.1.0`
- **Requires:** WordPress `>= 6.4` (`>= 6.9` for the MCP Ability), PHP `>= 8.1`
- **License:** GPL-2.0-or-later

## Key Features

- **Audio per Post:** Gutenberg sidebar panel (`assets/src/AudioPanel.js`) and Classic Editor meta box (`inc/Admin/MetaBox.php`) to select an MP3 from the Media Library. Validates real `audio/mpeg` MIME type, file size, and audio duration (`inc/Media/AudioValidator.php`).
- **Podcast RSS Feed:** Publishes an RSS 2.0 feed with iTunes/Apple Podcasts tags at `/feed/podcast/` (`?feed=podcast`), including `<enclosure>`, persistent GUIDs, RFC 2822 dates, transient XML caching, `ETag`/`Last-Modified` conditional `304` responses, and `HEAD` support (`inc/Feed/`).
- **Automatic Frontend Player:** Prepends an `<audio controls>` player to singular post content (`inc/Frontend/PlayerInjector.php`). Customizable via `.lb-sp-player` / `.lb-sp-player__audio` CSS, theme template override (`{theme}/lb-simple-podcast/player.php`), and `lb_sp_player_*` filters.
- **Public Stats Shortcode (`[lb_podcast_stats]`):** Displays aggregated, PII-free podcast metrics (`estimated_listens`, `unique_listeners`, `published_audios`, and optional `top_episodes` / `clients`) on the frontend using static attributes (`days`, `post_id`, `show`, `limit`, `title`, `class`), strict public-episode validation, theme template override (`{theme}/lb-simple-podcast/stats.php`), on-demand CSS (`assets/css/stats.css`), and `lb_sp_stats_*` filters (`inc/Frontend/StatsShortcode.php`).
- **Podcast Admin Menu (Administrators only):**
  - **Dashboard:** Published episode counts, latest audio, 30-day request totals, and top 5 episodes (`inc/Admin/DashboardPage.php`).
  - **Setup:** Channel metadata (title, description, language, cover image, Apple category, author, owner, explicit flag, copyright) and an unread error/notice log (`inc/Admin/SettingsPage.php`, `inc/Admin/ErrorsPanel.php`).
  - **Logs:** Paginated request log viewer backed by `{prefix}lb_sp_audio_requests` (`inc/Admin/LogsPage.php`).
- **Audio Delivery, Throttling & Request Logging:** Serves MP3s via `/?lb_sp_audio=<post_id>&lb_sp_attachment=<attachment_id>` with `GET`/`HEAD` and single byte-range (`206`/`416`) support (`inc/Media/AudioDelivery.php`), per-IP rate limiting (default 120 requests/minute, `inc/Media/RequestThrottle.php`), optional private storage (`LB_SP_PRIVATE_AUDIO_DIR`), trusted-proxy IP resolution and optional IP anonymization (`LB_SP_TRUSTED_PROXIES`, `LB_SP_PROXY_HEADER`, `LB_SP_ANONYMIZE_IP`), and automatic 90-day log pruning (`lb_sp_log_retention_days`).
- **REST API & SEO:** Adds the `audio_data` field to `wp/v2/posts` (`inc/Meta/AudioMeta.php`) and injects `og:audio` plus JSON-LD `AudioObject` into `wp_head` without duplicating existing tags (`inc/Integrations/SeoHeaders.php`).
- **MCP Ability (`lb-simple-podcast/publish-post-audio`):** Registers a WordPress Ability (`inc/Integrations/McpAbility.php`, exposed as an MCP tool via `mcp-adapter`) allowing AI agents to attach an MP3 to a post via public `audio_url` (with anti-SSRF protection) or `audio_base64` (`inc/Media/AudioIngestor.php`).
- **GitHub Updates:** Checks `lbonomo/simple-podcast` GitHub Releases via `yahnis-elsts/plugin-update-checker`.

## Repository Structure

```text
├── lb-simple-podcast.php   # Plugin entrypoint, constants, updater, activation/deactivation hooks
├── inc/                    # PSR-4 PHP classes (LbSimplePodcast\*)
│   ├── Admin/              # Menu, Dashboard, Setup, Logs, ErrorsPanel, EditorAssets, MetaBox
│   ├── Feed/               # FeedRegistrar, PodcastFeedRenderer, FeedVersion, Xml
│   ├── Frontend/           # PlayerInjector, StatsShortcode
│   ├── Integrations/       # McpAbility, SeoHeaders
│   ├── Media/              # AudioDelivery, AudioIngestor, AudioValidator, RequestThrottle
│   ├── Meta/               # AudioMeta (_lb_sp_audio_id post meta & REST audio_data)
│   └── Tracking/           # RequestLog, RequestStats, ErrorLog, ClientIp
├── assets/                 # Gutenberg JS source (assets/src/), built bundle, classic JS, frontend/admin CSS
├── templates/              # Default RSS XML template (feed-podcast.php), player (player.php), and public stats (stats.php)
├── scripts/                # Feed validator CLI (check-feed.js) and local log inspector
├── specs/                  # Playwright E2E test specs
├── tests/                  # PHPUnit unit & integration suites + PHPStan bootstrap
├── bin/                    # Production zip build and verification scripts
├── docs/                   # Architecture, setup, database schema, proxy IP, and MCP docs
└── uninstall.php           # Removes plugin options and the request log table on uninstall
```

## Local Development & Testing

Local development uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (Docker) with Node.js (`>= 26`) and Composer.

### Setup

```bash
composer install
npm ci && npm run build
npm run env:start
```

If the `wordpress_test` database does not exist yet in the `tests-mysql` container, create it once:

```bash
docker exec <tests-mysql-container> mysql -uroot -ppassword -e "CREATE DATABASE IF NOT EXISTS wordpress_test"
```

### Common Commands

| Task | Command |
| --- | --- |
| PHPUnit (full suite, requires `wp-env`) | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit` |
| PHPUnit (unit suite only) | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/simple-podcast vendor/bin/phpunit --testsuite unit` |
| JavaScript unit tests (Jest) | `npm test` |
| E2E tests (Playwright, requires `wp-env`) | `WP_BASE_URL=http://localhost:8888 npm run test:e2e` |
| PHP style check (WPCS) | `composer phpcs` |
| PHP static analysis (PHPStan level 8) | `composer phpstan` |
| JS lint | `npm run lint:js` |
| Validate a podcast feed & enclosures | `npm run check:feed -- http://localhost:8888/feed/podcast/` |
| View local audio request logs | `npm run logs` |
| Build & verify release ZIP | `bin/build-zip.sh` |

## Documentation

- [Architecture overview](docs/architecture.md)
- [Development & testing setup](docs/setup.md)
- [`wp-env` reference guide (Spanish)](docs/wp-env.md)
- [Database schema & options](docs/database.md)
- [MCP Ability schema & usage](docs/mcp-tool-schema.md)
- [Client IP detection behind proxies/CDNs](docs/ip-cliente-y-proxies.md)
- [WordPress plugin readme](readme.txt)
