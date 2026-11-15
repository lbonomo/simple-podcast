# Bugfix Requirements Document

## Introduction

The plugin "lb-simple-podcast" credits the wrong author name. The name "Leandro Bonomo" appears in the plugin header of `lb-simple-podcast.php`, in the translation template `languages/lb-simple-podcast.pot` (copyright line and the author msgid), and the plugin has no author website. The author must be "Lucas Bonomo" with the website https://lucasbonomo.com. The version must not change.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN the plugin header in `lb-simple-podcast.php` is read (for example on the WordPress Plugins screen) THEN the system shows the author as "Leandro Bonomo"
1.2 WHEN the plugin header is read THEN the system provides no Author URI, so the author name does not link to https://lucasbonomo.com
1.3 WHEN `languages/lb-simple-podcast.pot` is read or used for translation THEN the system contains "Leandro Bonomo" in the copyright line and as the author msgid
1.4 WHEN the author field is read from the Plugins screen or the readme metadata THEN the system exposes no author website pointing to https://lucasbonomo.com

### Expected Behavior (Correct)

2.1 WHEN the plugin header in `lb-simple-podcast.php` is read THEN the system SHALL show the author as "Lucas Bonomo"
2.2 WHEN the plugin header is read THEN the system SHALL include `Author URI: https://lucasbonomo.com`
2.3 WHEN `languages/lb-simple-podcast.pot` is read or used for translation THEN the system SHALL contain "Lucas Bonomo" in the copyright line and as the author msgid, matching the plugin header
2.4 WHEN the repository is searched (excluding `node_modules`, `vendor`, `.git` and build artifacts) THEN the system SHALL contain no occurrence of "Leandro Bonomo"

### Unchanged Behavior (Regression Prevention)

3.1 WHEN the plugin version is read (`Version:` header, `LB_SP_VERSION`, `package.json`, `package-lock.json`, `readme.txt` Stable tag) THEN the system SHALL CONTINUE TO report 0.7.0
3.2 WHEN the plugin is loaded THEN the system SHALL CONTINUE TO register its feed, meta box, player, SEO headers and MCP ability as before
3.3 WHEN the plugin header is parsed THEN the system SHALL CONTINUE TO expose the same Plugin Name, Description, Requires, License and Text Domain values
3.4 WHEN the GitHub updater, the Composer package name (`lbonomo/lb-simple-podcast`) and the readme `Contributors: lbonomo` WordPress.org username are used THEN the system SHALL CONTINUE TO use the same values, since they are account identifiers and not the author's display name
