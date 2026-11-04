# WordPress.org submission preparation

Copyright (c) 2026 Hayk Jomardyan - Lolisoft.

Build with `python tools/build_wordpress_org.py` (Python 3.10+ and PHP 7.4+ for translation tokenization). This produces the exact installation ZIP and its SHA-256/per-file manifest in `wordpress-org/`. ZIP contents are CRC-checked, have one plugin folder each, include the runtime PHP/assets/readable sources/license/COPYRIGHT.txt/BUILD.txt/readme, and preserve existing options, hooks and tables. Directory-only slug/domain mappings are explicit in tools/wordpress-org.json; private installation identities remain unchanged. No dev vendor/node_modules tree or old release ZIP is included.

## Upload candidates

- `wordpress-org/lolisoft-hosting-benchmarks.zip` — directory candidate; source `.`.

WordPress.org approval is decided by the Plugin Review Team; a clean automated check is not approval.

## Submission checklist

- Confirm the real WordPress.org account/Contributors field; no GitHub identity, placeholder company or automated assistant is asserted as an account.
- Run the official Plugin Check plugin on the built ZIP and resolve every reported error and warning. The added workflow runs all default checks without ignores and blocks errors while retaining warnings for manual review; its actual result must be reviewed.
- Exercise activation, admin/frontend workflows, uninstall/data retention and upgrade compatibility on the WordPress/WooCommerce versions you intend to claim. Set `Tested up to` only after that verification; unsupported historical version claims were removed.
- Verify GPL compatibility and preserve all bundled third-party notices. Readable sources and exact frontend build instructions are included in BUILD.md.
- Review privacy/external-service disclosure in readme.txt. Do not add opt-out tracking, external executable updates, built-in feature paywalls, unsolicited public credits or unlicensed assets.
- Directory artwork is optional and is not required for installation. Add real screenshots/artwork only after checking rights.
- Submit the chosen ZIP at https://wordpress.org/plugins/developers/add/ using the confirmed account; this repository does not submit, create an account or publish SVN/releases automatically.

Official guidance: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/ and https://developer.wordpress.org/plugins/wordpress-org/common-issues/

## Recorded local validation

The upload archives were installed, activated, bootstrapped through WordPress init, and deactivated in a disposable WordPress 7.1.4 site with WooCommerce 11.2.2. `Tested up to: 7.1` records that observed version. This smoke check plus the repository unit/build checks does not claim that every administration, payment, refund, email or external-provider path has been exercised. The final activation and database checks use MariaDB 11.8.9 on a disposable loopback server; initial SQLite checks were superseded for MySQL-specific schemas. External provider credentials and live provider requests were not used. Official Plugin Check findings are retained without ignoring categories/codes; remaining findings prevent a claim that the package is ready for approval.

Directory edition folder/text domain: `lolisoft-hosting-benchmarks`; source/private folder and stored options, hooks, REST namespaces and block names remain unchanged. The packaging script rewrites only literal translation domains, header/catalog metadata and the documented JavaScript translation domain. Deactivate an older private release before replacing it with this directory edition; existing settings and records are reused.

Archive members use ZIP_STORED with a fixed Unix creator, timestamps and permissions so the upload ZIP does not depend on the host zlib version or checkout line endings. This increases archive size slightly; manifests verify every runtime file.
