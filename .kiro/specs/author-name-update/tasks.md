# Implementation Plan

- [x] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - Author Name and URI Metadata
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists
  - **Scoped approach**: The bug is deterministic and the input domain is a fixed set of metadata fields, so enumerate the concrete failing reads instead of generating inputs
  - Assert the `Author` header in `lb-simple-podcast.php` equals "Lucas Bonomo"
  - Assert the `Author URI` header equals `https://lucasbonomo.com`
  - Assert `languages/lb-simple-podcast.pot` line 1 is `# Copyright (C) 2026 Lucas Bonomo` and the "Author of the plugin" entry has `msgid "Lucas Bonomo"`
  - Assert `grep -r "Leandro Bonomo"` (excluding `node_modules`, `vendor`, `.git`, build artifacts) returns no matches
  - Run on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct - it proves the bug exists)
  - Document counterexamples (header returns "Leandro Bonomo" and no Author URI; POT line 1 and the author msgid contain "Leandro Bonomo")
  - _Requirements: 1.1, 1.2, 1.3, 1.4_

- [x] 2. Write preservation tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Non-Author Metadata and Behavior Unchanged
  - **IMPORTANT**: Follow observation-first methodology
  - Observe on UNFIXED code: `Version` header, `LB_SP_VERSION`, `package.json`, `package-lock.json` and readme Stable tag are all 0.7.0
  - Observe: Plugin Name, Description, Requires at least, Requires PHP, License, License URI and Text Domain header values
  - Observe: `composer.json` name `lbonomo/lb-simple-podcast`, readme `Contributors: lbonomo`, and the GitHub updater URL `https://github.com/lbonomo/simple-podcast/`
  - Write enumerated checks (property-based generation is not warranted for this small fixed field set) asserting each observed value
  - Record a baseline copy of both files so the post-fix diff can be limited to the intended lines
  - Run on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 3. Fix for incorrect author name and missing Author URI

  - [x] 3.1 Implement the fix
    - In `lb-simple-podcast.php`, change `Author:            Leandro Bonomo` to `Author:            Lucas Bonomo`
    - Add ` * Author URI:        https://lucasbonomo.com` directly after the `Author:` line, aligned with neighboring header fields
    - In `languages/lb-simple-podcast.pot`, change line 1 to `# Copyright (C) 2026 Lucas Bonomo`
    - In `languages/lb-simple-podcast.pot`, change `msgid "Leandro Bonomo"` to `msgid "Lucas Bonomo"`
    - Do not change `Version`, `LB_SP_VERSION`, `package.json`, `package-lock.json`, `readme.txt`, `composer.json`, the GitHub updater URL, or `lb-simple-podcast.zip`
    - _Bug_Condition: isBugCondition(input) where Author = "Leandro Bonomo", or Author URI is missing, or the POT file contains "Leandro Bonomo"_
    - _Expected_Behavior: Author = "Lucas Bonomo", Author URI = "https://lucasbonomo.com", POT contains no "Leandro Bonomo"_
    - _Preservation: Version 0.7.0, other header fields, runtime behavior and account identifiers unchanged_
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 3.3, 3.4_

  - [x] 3.2 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - Author Name and URI Metadata
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 3.3 Verify preservation tests still pass
    - **Property 2: Preservation** - Non-Author Metadata and Behavior Unchanged
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Confirm `git diff` touches only the Author line, the new Author URI line, POT line 1 and the POT author msgid
    - Run the PHPUnit suite (`composer test`) to confirm the plugin still loads and registers its components
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.
