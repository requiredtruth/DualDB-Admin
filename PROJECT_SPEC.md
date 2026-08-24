# DualDB-Admin 0.1.0 contract

## Runtime

`main.php` is both the router for PHP's built-in web server and the CLI program. It requires PHP 8.1 or later plus PDO SQLite; PDO MySQL is optional. `config.php` is authoritative when present and environment variables are fallback only. No framework, Composer, or Docker is required.

## Database behavior

The web UI provides Dashboard, collapsed TABLES and TABLE GROUPS navigation, group management from selected current tables, bounded Browse/CRUD, CSV/JSON upload, bidirectional transfer, SQL, and CLI help. CLI provides `setup-demo`, `status`, `tables`, `schema`, `rows`, `import`, `transfer`, `sql`, and `export`. Identifiers are allow-listed and quoted; mutation values use prepared statements. Web mutations require a session CSRF token.

CSV headers define columns. A missing explicit table name uses the filename plus the configured prefix; collisions become `_2`, `_3`, and so on. Transfer intentionally replaces the destination table and processes all rows in bounded batches.

## Android

The Android application is a native Java WebView console, not a bundled PHP interpreter and not Cordova. It can open a trusted DualDB-Admin PHP server, including one running locally under a phone terminal environment. It rejects credentials embedded in URLs, blocks WebView file/content access, keeps navigation on the configured origin, and sends other origins to the system browser. App-private Documents storage is created without broad storage permission.
