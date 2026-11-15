# Author Name Update Bugfix Design

## Overview

The plugin credits the author as "Leandro Bonomo" and exposes no author website. The fix replaces the name with "Lucas Bonomo" in the plugin header and the translation template, and adds `Author URI: https://lucasbonomo.com` to the plugin header. The change is metadata only: no runtime code, version, or account identifier is touched.

## Glossary

- **Bug_Condition (C)**: A metadata read (plugin header or `.pot` file) that yields "Leandro Bonomo" or lacks the Author URI
- **Property (P)**: The metadata reports "Lucas Bonomo" and the Author URI `https://lucasbonomo.com`
- **Preservation**: Version 0.7.0, other header fields, plugin runtime behavior and account identifiers stay unchanged
- **Plugin header**: The docblock at the top of `lb-simple-podcast.php` parsed by WordPress
- **POT file**: `languages/lb-simple-podcast.pot`, the translation template with a copyright line and an "Author of the plugin" msgid
- **Account identifiers**: Composer package `lbonomo/lb-simple-podcast`, readme `Contributors: lbonomo`, GitHub URL `https://github.com/lbonomo/simple-podcast/`

## Bug Details

### Bug Condition

The bug manifests whenever plugin metadata is read from the plugin header or the POT file. The header has `Author: Leandro Bonomo` and no `Author URI` line. The POT file has `Leandro Bonomo` in line 1 (copyright) and in the `msgid` of the "Author of the plugin" entry.

**Formal Specification:**
```
FUNCTION isBugCondition(input)
  INPUT: input of type MetadataRead (file, field)
  OUTPUT: boolean

  RETURN (input.file = "lb-simple-podcast.php" AND input.field = "Author"
            AND value(input) = "Leandro Bonomo")
         OR (input.file = "lb-simple-podcast.php" AND input.field = "Author URI"
            AND value(input) is missing)
         OR (input.file = "languages/lb-simple-podcast.pot"
            AND content(input) CONTAINS "Leandro Bonomo")
END FUNCTION
```

### Examples

- Plugins screen shows author "Leandro Bonomo" with no link. Expected: "Lucas Bonomo" linking to https://lucasbonomo.com.
- `get_plugin_data()` returns `AuthorURI` as an empty string. Expected: `https://lucasbonomo.com`.
- POT line 1 reads `# Copyright (C) 2026 Leandro Bonomo`. Expected: `Lucas Bonomo`.
- POT entry `msgid "Leandro Bonomo"` under `#. Author of the plugin`. Expected: `msgid "Lucas Bonomo"`.
- Edge case: `Contributors: lbonomo` in `readme.txt` is not a bug input and must remain.

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- `Version:` header, `LB_SP_VERSION`, `package.json`, `package-lock.json` and `readme.txt` Stable tag remain 0.7.0
- Plugin Name, Description, Requires at least, Requires PHP, License, License URI and Text Domain header values are unchanged
- Feed, meta box, player, SEO headers and MCP ability register and behave as before
- The GitHub updater URL, Composer package name and readme `Contributors: lbonomo` are unchanged

**Scope:**
All inputs outside the Author header field, the Author URI header field, and the two "Leandro Bonomo" occurrences in the POT file are unaffected. This includes all PHP code below the header, JS sources, templates, and other POT entries.

## Hypothesized Root Cause

1. **Outdated metadata**: The author name was set to "Leandro Bonomo" when the plugin was created and never corrected.
2. **Missing header field**: The `Author URI` line was never added to the plugin header.
3. **Generated POT carrying the old name**: The POT file was generated from the header (author msgid) and the copyright line, so it copied the old name. It must be edited to match, and a later regeneration (for example `wp i18n make-pot`) will keep it consistent once the header is fixed.

## Correctness Properties

Property 1: Bug Condition - Author name and URI are correct

_For any_ metadata read where the bug condition holds (isBugCondition returns true), the fixed plugin SHALL report the author as "Lucas Bonomo", expose `Author URI: https://lucasbonomo.com` in the plugin header, and contain "Lucas Bonomo" in the POT copyright line and author msgid, with no occurrence of "Leandro Bonomo" remaining in the repository (excluding `node_modules`, `vendor`, `.git` and build artifacts).

**Validates: Requirements 2.1, 2.2, 2.3, 2.4**

Property 2: Preservation - Non-author metadata and behavior unchanged

_For any_ metadata read or runtime input where the bug condition does NOT hold, the fixed plugin SHALL produce the same result as the original, preserving version 0.7.0, the other header fields, plugin runtime behavior, and the account identifiers (Composer package, readme Contributors, GitHub URL).

**Validates: Requirements 3.1, 3.2, 3.3, 3.4**

## Fix Implementation

### Changes Required

**File**: `lb-simple-podcast.php`

**Specific Changes**:
1. **Author name**: Change `Author:            Leandro Bonomo` to `Author:            Lucas Bonomo`.
2. **Author URI**: Add ` * Author URI:        https://lucasbonomo.com` directly after the `Author:` line, using the same column alignment as neighboring header fields.

**File**: `languages/lb-simple-podcast.pot`

**Specific Changes**:
3. **Copyright line**: Change line 1 to `# Copyright (C) 2026 Lucas Bonomo`.
4. **Author msgid**: Change `msgid "Leandro Bonomo"` to `msgid "Lucas Bonomo"`.

**Not changed**: `Version`, `LB_SP_VERSION`, `package.json`, `package-lock.json`, `readme.txt`, `composer.json`, the GitHub updater URL. `lb-simple-podcast.zip` is a build artifact and is not edited.

## Testing Strategy

### Validation Approach

First confirm on the unfixed files that the defect is present, then verify the fix and that nothing else changed.

### Exploratory Bug Condition Checking

**Goal**: Show the bug on unfixed files before editing.

**Test Plan**: Parse the plugin header and search the repository. Run on the UNFIXED code.

**Test Cases**:
1. **Header author**: Parse `Author` from `lb-simple-podcast.php` and assert it equals "Lucas Bonomo" (will fail on unfixed code)
2. **Header Author URI**: Assert `Author URI` equals `https://lucasbonomo.com` (will fail on unfixed code)
3. **POT content**: Assert the POT file has no "Leandro Bonomo" and has "Lucas Bonomo" in line 1 and the author msgid (will fail on unfixed code)
4. **Repo search**: `grep -r "Leandro Bonomo"` excluding `node_modules`, `vendor`, `.git`, build output returns no matches (will fail on unfixed code)

**Expected Counterexamples**:
- Header returns "Leandro Bonomo" and no Author URI
- POT lines 1 and 29 contain "Leandro Bonomo"
- Possible causes: outdated metadata, missing header line, POT copied from old header

### Fix Checking

**Goal**: For all inputs where the bug condition holds, the fixed files give the expected values.

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition(input) DO
  result := read_fixed(input)
  ASSERT result.author = "Lucas Bonomo"
     AND result.authorUri = "https://lucasbonomo.com"
     AND NOT contains(result.pot, "Leandro Bonomo")
END FOR
```

### Preservation Checking

**Goal**: For all inputs where the bug condition does NOT hold, results match the original.

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT read_original(input) = read_fixed(input)
END FOR
```

**Testing Approach**: Compare the header fields and a diff of both files before and after. The input domain is a small fixed set of metadata fields, so enumerating every field is exhaustive, and property-based generation adds little.

**Test Plan**: Capture header fields and the file diff on UNFIXED code, then assert only the intended lines differ.

**Test Cases**:
1. **Version preservation**: `Version`, `LB_SP_VERSION`, `package.json`, `package-lock.json`, readme Stable tag all equal 0.7.0
2. **Other header fields**: Plugin Name, Description, Requires, License, License URI, Text Domain equal their original values
3. **Account identifiers**: `composer.json` name, readme `Contributors: lbonomo`, and the GitHub updater URL are unchanged
4. **Diff scope**: `git diff` touches only the Author line, the new Author URI line, POT line 1 and the POT author msgid

### Unit Tests

- Parse the plugin header and check Author, Author URI and the unchanged fields
- Check POT copyright line and author msgid
- Check the repository contains no "Leandro Bonomo"

### Property-Based Tests

- Not warranted. The domain is a fixed set of header fields, covered by enumerated unit checks.

### Integration Tests

- Load the plugin in WordPress and confirm `get_plugin_data()` returns the new author, author URI and version 0.7.0
- Confirm the Plugins screen shows "Lucas Bonomo" linked to https://lucasbonomo.com
- Confirm the plugin activates and the feed, meta box, player and MCP ability still register (run `composer test` / PHPUnit suite)
