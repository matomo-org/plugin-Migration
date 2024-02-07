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
 --target-db-enable-ssl=1                           Used for establishing secure connections using SSL with target database host (default: 0)
 --target-db-ssl-ca=/etc/ssl/certs/cert.pem         The path name to the certificate authority file (default: "/etc/ssl/certs/cert.pem")
 --target-db-ssl-no-verify=1                        Disable server certificate validation of the target database (default: 0)
 --skip-logs                                        Skip migration of logs (Raw tracking data)
 --skip-archives                                    Skip migration of archives (Report data)
 --dry-run                                          Enable debug mode where it does not insert anything.
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

No premium feature data is currently being migrated.
