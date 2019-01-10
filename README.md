# Matomo Migration Plugin

[![Build Status](https://travis-ci.org/matomo-org/plugin-Migration.svg?branch=master)](https://travis-ci.org/matomo-org/plugin-Migration)

## Description

Lets you copy a Matomo Mjeasurable (Website, Mobile App, ...) including all tracked raw data and generated reports
from one Matomo instance to another Matomo instance.

### Requirements

* You need access to your Matomo server and be able to execute a command on the console see usage.
* Make sure you have updated both Matomo servers to the same Matomo version or have at least the same DB structure.

### Usage

To start a migration execute this command:

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

No premium feature data is currently being migrated.
