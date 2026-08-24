# DualDB-Admin

DualDB-Admin is a single-file PHP SQLite/MySQL administration tool with the same entry point for a responsive web UI and structured CLI. The repository also includes a native Android WebView console for administering a trusted local or self-hosted instance from a phone or tablet.

```sh
./install.sh
```

No Composer, framework, Docker image, or JavaScript package installation is used.

## Start locally

```sh
cp config.example.php config.php
php -f main.php setup-demo
./run.sh
# open http://localhost:3232
```

The default SQLite file is created under `data/`. Configure MySQL in `config.php`; environment variables are fallback only:

```text
DUALDB_SQLITE_PATH
DUALDB_MYSQL_DSN
DUALDB_MYSQL_USER
DUALDB_MYSQL_PASSWORD
```

Never commit `config.php`, database files, exports, or credentials.

## Web features

- Dashboard and database selector.
- TABLES and TABLE GROUPS sidebars start collapsed; groups are built from selected current tables.
- Browse up to 50 rows at a time, inspect columns, add/edit/delete rows through primary keys, and explicitly drop a table.
- Upload CSV/JSON with an optional explicit table or a configurable prefix plus filename-derived table name.
- Auto-number collisions as `_2`, `_3`, and so on instead of silently mixing imports.
- Replace-and-copy a complete table between SQLite and MySQL in bounded batches.
- Run explicit SQL and display rows/affected counts.
- Session CSRF validation for every mutation and prepared values for CRUD/import/transfer.

## CLI

```sh
php -f main.php help
php -f main.php setup-demo
php -f main.php status
php -f main.php tables sqlite
php -f main.php schema sqlite demo_items
php -f main.php rows sqlite demo_items 50
php -f main.php import sqlite fixture.csv optional_table import_
php -f main.php transfer sqlite mysql demo_items
php -f main.php sql sqlite "SELECT * FROM demo_items"
php -f main.php export sqlite demo_items json
```

Errors are explicit:

```text
error: MySQL is not configured
error: unsafe identifier: invalid-name
error: CSV row width does not match header
error: source and destination must differ
```

## Android APK source

The Android project uses native Java, compile/target SDK 35, minimum SDK 19, and Java 8. It contains no Cordova dependency, which avoids the historical unresolved `Whitelist` compilation failure.

```sh
./android/build-apk.sh
```

The script uses a local Gradle installation when available, otherwise it can build with installed Android SDK `aapt`, `d8`, and `apksigner` tools. The generated debug signing key is temporary, randomly protected, and never retained. Output is `build/DualDBAdmin.apk`.

A tested demonstration APK and its SHA-256 checksum are published under [GitHub Releases](../../releases). The Android demo is only a client console: it requires a trusted DualDB-Admin PHP server and does not bundle PHP, database credentials, TLS, authentication, or a public service.

The APK is the phone interface, not a PHP runtime. Run the PHP application locally in a phone terminal environment or point the app at a trusted self-hosted instance. URL user information is rejected; do not place database credentials in a URL.

## Limitations and safety

- The SQL runner and drop/transfer actions are intentionally powerful. Do not expose this admin interface publicly; use authentication and a trusted private transport in front of it when accessed beyond the local device.
- This release does not implement user accounts, TLS, or authorization. The local PHP server binds to `localhost` by default.
- MySQL needs the PDO MySQL extension and an existing server/schema. DualDB-Admin does not install or configure MySQL.
- Transfer recreates destination columns as text for portability; indexes, constraints, triggers, generated columns, and native types are not preserved.
- Import derives text columns from trusted CSV headers or JSON keys. Headers must be safe SQL identifiers.
- Browse pages are capped at 50 rows. CLI export and transfer traverse the full table in 500-row batches.
- App-private Android Documents storage is not a substitute for database backups.

## Support

Donations can fund additional production time and may request priority for a compatible direction through the funded-direction issue template using a public transaction hash. They do not guarantee implementation or purchase ownership, returns, deadlines, or support. See [SUPPORT.md](SUPPORT.md) and verify the asset and exact network before sending.

MIT licensed.
