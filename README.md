# Sentinel Publication Workflow

## Running tests
Uses WP core test suite (wp-phpunit) against a dedicated MySQL database `spw_test`
(the suite drops tables; the bootstrap refuses `DB_NAME=local`).

1. Create DB: `CREATE DATABASE IF NOT EXISTS spw_test;`
2. `composer install`
3. `composer test`

Defaults: host `127.0.0.1:10096`, user/pass `root`/`root`. Override with env
`SPW_TEST_DB_HOST`, `SPW_TEST_DB_USER`, `SPW_TEST_DB_PASSWORD`, `SPW_TEST_DB_NAME`.

Notes: PHP needs the `mysqli` extension (Local's PHP ships without a php.ini; point `PHPRC` at an ini that loads it).
`wp-phpunit` is pinned to 7.1.2 (7.1.3 was published with missing files). WP core is found by walking up to `wp-settings.php`
(override with `SPW_WP_ABSPATH`).
