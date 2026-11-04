# Repository verification

The root Makefile exposes `install`, `dev`, `test`, `lint`, `build`, `clean`,
`package`, and `security`. Existing project targets are retained. `audit-lint`,
`audit-test`, `audit-build`, and `audit-security` run the commands used by the
additional GitHub Actions workflow. CI uses Python 3.12, PHP 8.3, Node 22, or
.NET 8 as applicable. Run Python commands inside an activated virtual environment.

`tools/check_sources.py` checks tracked PHP/Python/JSON syntax. It is a lint gate,
not a substitute for behavioral tests. WordPress unit tests use mocks; activation,
database upgrades, HPOS and actual mail/payment behavior additionally need an
isolated WordPress + WooCommerce installation. Do not use a production shop for tests.

`package` may be an existing project release target. Where newly supplied, it makes
`dist/source.zip` from the committed HEAD with `git archive`; this is a source
archive, not a compiled or deployed release. `clean` never removes databases,
credentials or user configuration. Dependency audits query the package registry;
scanner failures must be investigated and are not successful security checks.

The separate secrets job uses [Gitleaks 8.30.1](https://github.com/gitleaks/gitleaks/releases/tag/v8.30.1)
and redacts secret values from logs. Historical findings require credential rotation
and separate remediation; CI does not rewrite history or rotate credentials.

## Display name and compatibility

The display name is **Lolisoft Press addon Hosting Benchmarks**. Plugin folder names, main-file
basenames, text domains, constants, option names, hooks, REST namespaces and
database tables retain their existing identities. Existing installations and
settings therefore keep their upgrade paths. Historical release ZIPs remain historical.

Limited public naming screening on 4 November 2026 found no indexed exact-name
collision for the full proposed name. The prefix was also screened. An existing
Belgian software business uses “Lolisoft”; this screening is not trademark clearance.
The descriptive references to WooCommerce, WordPress and iFirma identify compatibility
and do not imply endorsement. Formal clearance remains unresolved; consult
[EUIPO availability guidance](https://www.euipo.europa.eu/pt/trade-marks/before-applying/availability)
and [EUIPO search](https://www.euipo.europa.eu/en/search-ip) before commercial registration.
