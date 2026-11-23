# Dastresi-Ban Template Storage Upgrade

This selected source sample demonstrates how Dastresi-Ban migrated older access-status templates and history to dedicated tables while retaining the original option data and a checked backup.

The [integration fixture](tests/acceptance/check.php) creates its own randomly named database. It requires WordPress core and a disposable MySQL or MariaDB service whose test account can create and remove that isolated database. Set `ACP_TEST_WP_ROOT` and `ACP_TEST_DB_HOST`; use `ACP_TEST_DB_USER` and `ACP_TEST_DB_PASSWORD` if required. Run `php tests/acceptance/check.php` from this folder.

It checks preservation of legacy tables, interruption before the new data becomes active, retention of the raw backup, retry, preservation of identifiers and of the default template and history. The production migration entry is in [the original migration class](src/Database/AccessStatusMigration.php). See [LICENSE](LICENSE).
