# Matomo Migration Plugin

[![Build Status](https://travis-ci.org/matomo-org/plugin-Migration.svg?branch=master)](https://travis-ci.org/matomo-org/plugin-Migration)

## Description

**Beta version: The Migration feature is currently in beta. As such it may contain bugs, or may not work as expected. Please <a href="https://github.com/matomo-org/plugin-Migration/issues">report any issues</a> you encounter or any other feedback back to us.**

Lets you copy a Matomo Measurable (Website, Mobile App, ...) including all tracked raw data and generated reports
from one Matomo instance to another Matomo instance.

### Requirements

* You need access to your Matomo server and be able to execute a command on the console see usage.
* Make sure you have updated both Matomo servers to the same Matomo version or have at least the same DB structure.
* Wanting to migrate from Matomo On-Premise to Matomo for WordPress? [Learn more about this here](https://matomo.org/faq/wordpress/how-do-i-migrate-all-my-data-from-matomo-on-premise-to-matomo-for-wordpress/)

### Usage

Before executing the migration command we always recommend to make a backup of the target database and ideally also test
it first with the `dry-run` flag (dry-run can take a long time as well and will give you an idea of how long migration
will take).

To start a migration execute the `migration:measurable` command, example:

```
 ./console migration:measurable --source-idsite=1 --target-db-host=192.168.1.1 --target-db-username=root --target-db-password=secure --target-db-name=piwik2
```

Optional parameters are:

```
 --target-db-prefix=piwik_
 --target-db-port=3306
 --skip-logs
 --skip-archives
 --dry-run
 --disable-db-transactions
```

Please note that the migration can take a while depending on the amount of data that needs to be copied.

No data from the original Matomo instance will be deleted, only new data will be added to the new Matomo instance.

No premium feature data is currently being migrated.
