# Source and reproducible build

Copyright (c) 2026 Hayk Jomardyan - Lolisoft.

Full, readable source and development manifests: https://github.com/jomardyan/Hosting-Benchmark-tools-Plugin-WP

Source root: `.`. Clone that repository, install Python 3.10+ and PHP for validation. From the repository root run:

```sh
python tools/build_wordpress_org.py
```

The deterministic archive and per-file SHA-256 manifest are written to `wordpress-org/`. The package includes readable JavaScript/PHP sources alongside any compiled assets. Development dependencies, tests, private credentials, old archives and editor files are excluded. No version bump, directory submission, SVN write or release publication is performed by this command.

Directory edition folder/text domain: `lolisoft-hosting-benchmarks`; source/private folder and stored options, hooks, REST namespaces and block names remain unchanged. The packaging script rewrites only literal translation domains, header/catalog metadata and the documented JavaScript translation domain. Deactivate an older private release before replacing it with this directory edition; existing settings and records are reused.
