#!/usr/bin/env bash
# Matomo - free/libre analytics platform
# https://matomo.org - GPL v3 or later
set -euo pipefail
cd /var/www/html

# Match the ordinary plugin tests, with a disposable database and the plugin under review.
cat > config/config.ini.php <<'INI'
; <?php exit; ?> DO NOT REMOVE THIS LINE
[database]
host = database
username = root
password =
dbname = matomo_tests
adapter = PDO_MYSQL
tables_prefix =
[database_tests]
host = database
username = root
password =
dbname = matomo_tests
adapter = PDO_MYSQL
tables_prefix =
[tests]
request_uri = "/"
enable_logging = 1
[log]
log_writers[] = file
[General]
INI
printf 'date.timezone = "%s"\nmemory_limit = 768M\n' "$TZ" > /etc/php/8.3/cli/conf.d/99-migration.ini
cp /etc/php/8.3/cli/conf.d/99-migration.ini /etc/php/8.3/apache2/conf.d/99-migration.ini

# Fail if the hints were ignored: changing a site's timezone alone does not exercise this environment.
php <<'PHP'
<?php
$db = new PDO('mysql:host=database;dbname=matomo_tests', 'root', '');
$state = $db->query('SELECT @@system_time_zone AS system_zone, @@global.time_zone AS global_zone, @@session.time_zone AS session_zone, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds')->fetch(PDO::FETCH_ASSOC);
$expected = (new DateTimeZone(getenv('MYSQL_TIMEZONE')))->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
echo 'Database timezone: ', json_encode($state), PHP_EOL;
echo 'PHP timezone before Matomo bootstrap: ', date_default_timezone_get(), PHP_EOL;
if ($expected === 0 || (int) $state['offset_seconds'] !== $expected || $state['global_zone'] !== 'SYSTEM'
    || $state['session_zone'] !== 'SYSTEM' || date_default_timezone_get() !== getenv('TZ')) {
    throw new RuntimeException('The regression job requires the configured non-UTC server timezone.');
}
PHP

mkdir -p tmp
chown -R www-data:www-data tmp
apache2ctl start
trap 'apache2ctl stop' EXIT
for ((attempt=0; attempt<30; attempt++)); do
    if curl --fail --silent http://localhost/piwik.php >/dev/null; then
        break
    fi
    sleep 1
done
curl --fail --silent http://localhost/piwik.php >/dev/null

vendor/bin/phpunit -c tests/PHPUnit/phpunit.xml --group MigrationTimezone \
    --fail-on-warning --fail-on-risky --do-not-cache-result --log-junit=/tmp/timezone-results.xml plugins/Migration/tests
php <<'PHP'
<?php
$result = simplexml_load_file('/tmp/timezone-results.xml');
if ($result === false || count($result->xpath('//testcase')) === 0 || count($result->xpath('//skipped | //failure | //error | //warning')) !== 0) {
    throw new RuntimeException('The timezone suite must execute tests without skips or failures.');
}
echo 'Verified ', count($result->xpath('//testcase')), ' timezone regression cases.', PHP_EOL;
PHP
