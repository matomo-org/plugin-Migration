# Matomo Migration Plugin

[![Build Status](https://github.com/matomo-org/plugin-Migration/actions/workflows/matomo-tests.yml/badge.svg?branch=4.x-dev)](https://github.com/matomo-org/plugin-Migration/actions/workflows/matomo-tests.yml)

## Description

Lets you copy a Matomo Measurable (Website, Mobile App, ...) including all tracked raw data and generated reports
from one Matomo instance to another Matomo instance.

### Requirements

* You need access to source Matomo server and be able to execute a command on the console.
* You need access to target Matomo database.
* Make sure you have updated both Matomo servers to the same Matomo version or have at least the same DB structure.
* Wanting to migrate from Matomo On-Premise to Matomo for WordPress? [Learn more about this here](https://matomo.org/faq/wordpress/how-do-i-migrate-all-my-data-from-matomo-on-premise-to-matomo-for-wordpress/)

### Usage

Before executing the migration command we always recommend to make a backup of the target database and ideally also test
it first with the `dry-run` flag (dry-run is faster but can take a long time as well and will give you an idea of how long migration
will take).

By default, the migration creates a **new website** in the target Matomo using the next available `siteid`.
Remember to update the tracking code to match that ID. To use a site that already exists on the destination, pass
`--target-idsite`; see [Migrating into an existing website](#migrating-into-an-existing-website).

To start a migration execute the `migration:measurable` command, example:

```
 ./console migration:measurable --source-idsite=1 --target-db-host=192.168.1.1 --target-db-username=root --target-db-password=secure --target-db-name=piwik2
```

Optional parameters are:

```
 --target-db-prefix=piwik_                          Target database table prefix (default: "")
 --target-idsite=7                                 Use this existing destination website instead of creating one
 --target-site-is-offline                          Confirm destination tracking and archiving stay stopped while importing into --target-idsite
 --start-date=2024-01-01                           First day to migrate, in the source website's timezone
 --end-date=2024-12-31                             Last day to migrate, in the source website's timezone
 --target-db-port=3306                              Target database port (default: "3306")
 --target-db-enable-ssl                             Used for establishing secure connections using SSL with target database host
 --target-db-ssl-ca=/etc/ssl/certs/cert.pem         The path name to the certificate authority file (default: "/etc/ssl/certs/cert.pem")
 --target-db-ssl-no-verify                          Disable server certificate validation of the target database
 --skip-logs                                        Skip migration of logs (Raw tracking data)
 --skip-archives                                    Skip migration of archives (Report data)
 --dry-run                                          Enable debug mode where it does not insert anything.
 --skip-plugin-data=FormAnalytics,Funnels          Skip the data of the listed plugins
 --disable-db-transactions                          Disable the usage of MySQL database transactions
```

Both Matomo instances may be on different servers with proper firewall rules that restrict database access on target instance.
In such case, the easiest way for source server to access target database is to create a [ssh tunnel](https://www.ssh.com/academy/ssh/tunneling) on new port (e.g. 3307) in another terminal.
Then, execute to the above command with `--target-db-port=3307` instead to access port 3306 on target host. Example:
```
ssh -NL 3307:localhost:3306 targetuser@targethost
```
This command should be run on the source Matomo server. It essentially maps port 3307 of the server to port 3306 of the server you are migrating to and is specified by `targethost`.
The `targethost` should be replaced by a valid IP or domain name referencing the server you are migrating to and must be accessible to the server that you are migrating from. So, the source server should be able to [ping](https://www.redhat.com/sysadmin/ping-usage-basics) the target server.
The `targetuser` should be replaced with a valid SSH user account that has been setup on the server that you are migrating to. It's preferable to setup [an SSH key](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/generating-a-new-ssh-key-and-adding-it-to-the-ssh-agent) on the source server and use [the ssh-copy-id command](https://www.ssh.com/academy/ssh/copy-id) to add it to the authorized keys for the `targetuser`, but using the user's password should work too.

An alternative to using an SSH tunnel is to make a backup of your MySQL database, copy it to the new server, import it into a temporary database, and then migrate using that database name.
Remember to delete the temporary database after completing the migration, and checking that everything works.
For more information about this process please refer to: [How can I move Matomo from one server to another, also migrating the data from one mysql server to another?](https://matomo.org/faq/how-to-install/faq_76/).

Matomo instance and files in folders may be owned by a special user (e.g. `www-data`) with restricted ssh access.
The abovementioned may be run either under root (e.g. `sudo ...`), or the special user (`sudo -u www-data ...`).

Please note that the migration can take a while depending on the amount of data that needs to be copied.

No data from the original Matomo instance will be deleted, only new data will be added to the new Matomo instance.

Archive tables and their ID sequences are prepared before site data is copied, so creating a missing archive month
does not commit an in-progress migration. With database transactions enabled and transactional destination tables,
copying errors roll back the copied data. Empty archive tables and reserved sequence values from preparation can
remain after a failure. A dry run does not create these tables or change their sequences.

After a rolled-back failure, correct its cause and run the command again. Without `--target-idsite`, each successful
run creates a new website. The command does not resume or deduplicate a partially committed migration. If transactions
were disabled, restore the destination backup before retrying an import into an existing site, or remove the
incomplete newly created destination website.

### Migrating into an existing website

Pass `--target-idsite=7` alongside the source and target database options to import into destination Site ID 7.
The ID must already exist and have the same measurable type as the source (for example, website or roll-up); it can
be empty or contain data. This option does not reserve an unused ID or create a
website with a chosen ID. Create the destination website through Matomo first.

The destination keeps its name, main URL, timezone, currency and site settings. Missing source alias URLs are added,
and its creation date is moved earlier when needed for the imported history. Existing visits and reports remain.
Matching goals, custom dimensions, segments, alerts and premium configuration are reused; missing definitions are
added. Custom dimensions match by name and scope; premium definitions use the identity columns declared by each
plugin definition. Existing configuration takes precedence when those identities match.
When CustomDimensions is inactive on the source, imported log dimension columns are cleared so their values cannot
appear under unrelated destination dimensions. Existing destination values and definitions are kept.
Alert-history deduplication uses a connection-local indexed lookup and requires `CREATE TEMPORARY TABLES` on the
destination database. The lookup is removed after use, including on failure; permanent CustomAlerts indexes are unchanged.
Children of reused premium definitions must also match: for example, the migration stops if a source funnel has a
step missing from its matching destination funnel, instead of changing the destination funnel. Extra destination
children are preserved, but make source reports incompatible with that configuration.

Conflicting goal and custom-dimension IDs are remapped, including conversion values, dimension log columns, segments
and plugin references. The command lists changed IDs: update `trackGoal()` and `setCustomDimension()` calls when
switching tracking to the destination. If the destination lacks free dimension columns, the command stops before
copying data and explains how to add capacity.

Visits are **appended**, without deduplication. An overlap warning identifies destination visits in the selected
source period. Repeating a successful import can duplicate visits and interactions even when configuration is
reused. Ecommerce orders already present with the same order ID, and their items, are skipped. Stop source tracking
or use a historical cutoff that excludes visits already collected at the destination. Avoid editing destination
configuration during the import.

Imports into an existing website rebuild reports by default, including when the website is currently empty.
To preserve source archives in a compatible empty destination, stop destination tracking and archiving first and
add `--target-site-is-offline` alongside `--target-idsite`. Keep them stopped until the migration completes.
The option confirms that you have paused those processes; it does not stop or lock them. The migration checks the
destination again before copying, but that check cannot protect against writes that happen later.

Even with this option, source reports are not copied when the destination already has visits or archived reports, uses another timezone,
needs different goal or custom-dimension IDs, or reuses premium definitions with different settings. Copying them
could hide destination data or report against the wrong definitions. Instead, the command prints the destination
`core:invalidate-report-data` and `core:archive` commands
needed to rebuild reports for the imported visits, including when `--skip-archives` is used. Before running them,
ensure the destination has the retained raw data from **both** instances for the affected periods. Rebuilding can
replace existing destination totals with incomplete data if either instance purged its raw data. **Reports whose
raw data has been deleted cannot be rebuilt or merged this way.** If preserving such history is required, use a new
website or a compatible empty destination with unchanged goal and dimension IDs and equivalent premium definitions;
for an existing empty destination, pause its tracking and archiving and supply `--target-site-is-offline`.

Run the suggested invalidation commands with `-v` to see dates skipped by destination log-retention settings.
If those settings exclude imported dates, first verify that the raw data needed from both instances is present and
will remain available until rebuilding completes. Then repeat the invalidation command with
`--ignore-log-deletion-limit` and run `core:archive`. This flag bypasses a safeguard: do not use it to rebuild
historical totals when the underlying raw data is missing.

If reports cannot be copied, `--skip-logs` fails because rebuilding requires raw data. Core checks run before copying.
Premium configuration conflicts discovered during ID translation roll back the import when transactions are enabled.
Use both `--skip-logs --skip-archives` only when intentionally copying configuration and alert history without visits
or reports.

### Selecting dates

`--start-date` and `--end-date` accept inclusive calendar dates in `YYYY-MM-DD` format. Either bound may be omitted.
Dateless property data, such as Bing's current crawl errors stored under `0000-00-00`, is copied with either or both
bounds. Existing destination property rows with the same key are preserved.
For example, add `--target-idsite=7 --end-date=2024-12-31` to import historical data before tracking began on the
destination on 1 January 2025, provided there is no earlier overlap.

Visits are selected by their last action in the source website's timezone. Each selected visit is copied in full,
including earlier actions and its premium-plugin interactions. Independent plugin logs use their UTC timestamps;
search-console imports use their stored calendar dates. Site configuration and custom-alert history are not filtered
by these dates.

When reports can be copied, only complete report periods within the range are included. The command prints rebuild
instructions only for excluded weeks, months and years, and for earlier days touched by visits crossing the start
boundary. Complete copied periods are not invalidated. The rebuilt partial periods include retained raw data and
copied subperiod reports; they cannot recover history outside the imported range. With `--skip-logs`, partial periods
cannot be rebuilt from this import.
Retention settings can also skip excluded weeks, months and years. Use `-v` to inspect the skipped dates and add
`--ignore-log-deletion-limit` only after checking that the retained raw data or copied subperiod reports needed to
build those periods are available. Keep the printed `--periods` selection so complete copied periods stay intact.

### Custom alerts

If CustomAlerts is active on the source, the selected website's alert definitions and triggered history are copied.
Install CustomAlerts with matching tables on the destination. Shared alerts are assigned only to the destination
website; definitions needed only by historical snapshots are copied without assigning them to the website.

Alerts are migrated after premium plugin configuration. Report references, custom-metric IDs and goal and custom-dimension metric references in each definition
and historical snapshot are translated independently, preserving the report used when the alert fired. If a known
reference cannot be translated because its data is missing or its plugin is excluded, the migration stops with an
explanation. With database transactions enabled, changes are rolled back. A dry run also reports unresolved references.
Alert owners and notification settings are retained; ensure the owners exist and have access to the destination
website and configure its notification integrations.

### Premium plugin data

The migration copies the selected website's plugin configuration and raw data for the plugins listed below.
Install and activate the same plugin versions on both instances before migrating. Only plugins activated and loaded
on the source are considered, and the destination tables must have matching columns.

| Plugin | Data copied |
| --- | --- |
| Heatmaps & Session Recordings | Configured heatmaps and recordings, recorded pageviews, events and shared content |
| Funnels | Funnels, their steps, and the steps recorded for visits |
| Form Analytics | Forms and visit interactions with forms, pages and fields |
| Multi Channel Conversion Attribution | Goal attribution settings |
| A/B Testing | Experiments, variations, strategies and visit assignments |
| Media Analytics | Media and playback interactions |
| Crash Analytics | Crashes, groups, visit events and stack traces |
| Advertising Conversion Export | Export settings and advertising click IDs |
| SEO Web Vitals | Stored PageSpeed audits |
| Custom Reports | Site reports, applicable shared reports and their custom metrics |
| Search Engine Keywords Performance | Imported Google, Bing and Yandex property data |
| Roll-Up Reporting | Relationships between migrated sites and roll-ups |

IDs in the copied plugin rows, segments and archived reports are translated to the destination IDs. Site-local goal
and custom-dimension IDs remain unchanged because the command creates a new destination website. Shared rows are
copied when referenced, and unrelated websites' data is excluded. Reports that reference plugin records excluded
from migration are omitted instead of pointing to unrelated records on the destination.

Use `--skip-plugin-data=FormAnalytics,Funnels` to exclude the named plugins. `--skip-logs` also excludes plugin visit
logs and imported search-console data; `--skip-archives` excludes archived reports. `--dry-run` validates the
migration without writing data. Unknown ID-like columns cause validation to fail before copying. Plugin schema changes
may require updated migration definitions. The output identifies site tables without a migration definition.

If a live segment or a custom-report filter references known plugin data that is missing or excluded, migration
stops with an explanation instead of retaining a source ID that could select unrelated destination data. Dry runs
check these references too. Correct the reference or include the required plugin data before retrying.
Soft-deleted segments remain deleted; their plugin references and hashes are retained without validation or rewriting.

Some plugins require additional destination configuration:

* Advertising Conversion Export URLs must point to the destination. Copied access tokens require the same
  `[AdvertisingConversionExport]` salt; otherwise regenerate them on the destination.
* Custom Reports for all or several websites are copied as reports of the migrated website. A site-specific report
  takes precedence over a shared report with the same name; alert references use the retained report, while the
  duplicate's archives are omitted. Existing custom metrics with matching names and formulas
  are reused; conflicting names receive a numbered suffix and are shortened if needed to fit the destination column.
  A metric referencing another source website stops migration if that website's ID matches the new destination ID:
  preserving that formula would change its result. Review the metric's site-specific references before retrying.
* Search-console accounts are instance settings and must be configured on the destination. The copied Bing site
  settings require the same `[SearchEngineKeywordsPerformance]` encryption key, or Bing must be configured again.
  Unreadable encrypted properties on the source stop the migration with an error instead of silently omitting their
  historical data. Restore the source encryption configuration or explicitly exclude the plugin's data.
  Imported rows already present for the same property and day are preserved.
* Roll-ups regain relationships as their member sites are migrated, in either order. Unmigrated members are reported.
  An all-websites roll-up aggregates the destination's websites. Follow the printed archive-invalidation command
  when membership changes require reports to be rebuilt. Source database endpoint, database name and table prefix
  distinguish site mappings; use the same source connection settings when migrating related sites.
  Cloned sources sharing both a salt and identical connection settings cannot be distinguished. Configure distinct,
  stable database endpoints when consolidating those sources.

Users Flow and Cohorts use the archived reports already copied by the migration. This change does not copy global
authentication settings, user accounts, notification integrations or scheduled email reports. Activity Log is not
migrated: it is an instance-wide audit history with user and object references, rather than data owned by one website.

Plugins can describe additional tables through the `Migration.addPluginDefinitions` event. See
`PluginData/PluginDefinitions.php` and the included definitions for the extension API.

### Testing

From a configured Matomo test installation, run:

```
vendor/bin/phpunit -c tests/PHPUnit/phpunit.xml.dist plugins/Migration/tests
```

The synthetic plugin-data tests run without premium packages. `PremiumDefinitionsTest` also exercises the built-in
transforms and report-ID rewrites without a database or licensed plugins, so this coverage runs in CI.
`MigratePremiumPluginsTest` additionally creates
and compares real data through the installed premium plugins; it skips when none are installed. To require all
12 supported premium plugins in a licensed test installation, set `MIGRATION_REQUIRE_PREMIUM_PLUGINS=1` when
running the suite. Premium packages and license keys must not be included in this repository.

The timezone CI job runs the focused `MigrationTimezone` cases with the database server in
`Pacific/Auckland`, on MySQL and MariaDB. It verifies the actual server offset before testing site-day
boundaries, daylight-saving changes, imported visits and report-rebuilding commands.
To reproduce it locally with Bash, Git and Docker available, run from this plugin's directory:

```bash
bash tests/ci/run-timezone-suite.sh
MIGRATION_TIMEZONE_DB_IMAGE=mariadb:10.6 bash tests/ci/run-timezone-suite.sh
```

The runner builds a disposable PHP/Matomo environment from `6.x-dev` and removes its containers and network
afterward. It uses no premium packages or existing database volumes. Set `MIGRATION_TIMEZONE_MATOMO_REF`
to a Matomo branch, tag or commit to check another supported version.
