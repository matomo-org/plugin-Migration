# Matomo Migration Plugin

[![Build Status](https://travis-ci.com/matomo-org/plugin-Migration.svg?branch=4.x-dev)](https://travis-ci.com/matomo-org/plugin-Migration)

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

Both Matomo instances may be on different servers with proper firewall rules that restrict database access on target instance.
In such case, the easiest way for source server to access target database is to create a ssh tunnel on new port (e.g. 3307) in another terminal.
Then, execute to the above command with `--target-db-port=3307` instead to access port 3306 on target host. Example:
```
ssh -NL 3307:localhost:3306 targetuser@targethost
```

Matomo instance and files in folders may be owned by a special user (e.g. `www-data`) with restricted ssh access.
The abovementioned may be run either under root (e.g. `sudo ...`), or the special user (`sudo -u www-data ...`).

Please note that the migration can take a while depending on the amount of data that needs to be copied.

The migration tool will create a new website in the target Matomo and copy all the data from the source website to this newly created target website.

No data from the original Matomo instance will be deleted, only new data will be added to the new Matomo instance.

No premium feature data is currently being migrated.
