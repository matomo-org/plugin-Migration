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

__NOTE:__ The migration tool will create a __new website__ in the target Matomo, using the next available `siteid`, and copy all the data from the source website to this newly created target website.
Remember to update the tracking code to match the new `siteid`.

To start a migration execute the `migration:measurable` command, example:

```
 ./console migration:measurable --source-idsite=1 --target-db-host=192.168.1.1 --target-db-username=root --target-db-password=secure --target-db-name=piwik2
```

Optional parameters are:

```
 --target-db-prefix=piwik_                          Target database table prefix (default: "")
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

### Custom alerts

If CustomAlerts is active on the source, the selected website's alert definitions and triggered history are copied.
Install CustomAlerts with matching tables on the destination. Shared alerts are assigned only to the new destination
website; definitions needed only by historical snapshots are copied without assigning them to the website.

Alerts are migrated after premium plugin configuration. Report references and custom-metric IDs in each definition
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
migration without writing data. Unknown ID-like columns cause validation to fail before copying, so a plugin schema
change cannot silently leave references untranslated. The output identifies site tables without a migration definition.

Some plugins require additional destination configuration:

* Advertising Conversion Export URLs must point to the destination. Copied access tokens require the same
  `[AdvertisingConversionExport]` salt; otherwise regenerate them on the destination.
* Custom Reports for all or several websites are copied as reports of the migrated website. A site-specific report
  takes precedence over a shared report with the same name. Existing custom metrics with matching names and formulas
  are reused; conflicting names receive a numbered suffix.
* Search-console accounts are instance settings and must be configured on the destination. The copied Bing site
  settings require the same `[SearchEngineKeywordsPerformance]` encryption key, or Bing must be configured again.
  Imported rows already present for the same property and day are preserved.
* Roll-ups regain relationships as their member sites are migrated, in either order. Unmigrated members are reported.
  An all-websites roll-up aggregates the destination's websites. Follow the printed archive-invalidation command
  when membership changes require reports to be rebuilt. Source database and table prefix distinguish site mappings.

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

The synthetic plugin-data tests run without premium packages. `MigratePremiumPluginsTest` additionally creates
and compares real data through the installed premium plugins; it skips when none are installed. To require all
12 supported premium plugins in a licensed test installation, set `MIGRATION_REQUIRE_PREMIUM_PLUGINS=1` when
running the suite. Premium packages and license keys must not be included in this repository.
