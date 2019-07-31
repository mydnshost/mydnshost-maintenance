# mydnshost-maintenance
Maintenance Container

This container runs various scripts under cron to perform maintenance tasks:

	- Every 6 Hours:
		- Database Backups
		- Bind Zone Backups
	- Every Hour
		- Update StatusCake checks
	- Every Minute
		- Gather Statistics from bind servers

The following mounts are required:

	- /output
		- The directory to store output from scripts should be mounted in to this directory
	- /var/run/docker.sock
		- The docker socket should be mounted into the container
	- /bind
		- The bind zone files should be mounted to /bind

## Backup DB

This will look for any container with a label of `uk.co.mydnshost.maintenance.db.backup` set to `"true"` and then will attempt to backup named databases from it.

Credentials for mysqldump can be passed with labels `uk.co.mydnshost.maintenance.db.user` and `uk.co.mydnshost.maintenance.db.pass` and the list of databases to dump can be passed as a comma separated string `uk.co.mydnshost.maintenance.db.dbs`

Backups are stored in `/output/mysqlbackup/<containername>/<date>/<database>/backup-<time>.sql`

The last 30 days of backups are kept.

## Backup Zone Files

This will create a backup of all files under `/bind/` into `/output/bindbackup/<date>/backup-<time>.tgz`

The last 30 days of backups are kept.

## Update StatusCake

This will make changes to a test domain and update StatusCake tests to look for the updated data.

Example ENV VARS Required:

  - API_URL=https://api.mydnshost.co.uk/
  	- The API Endpoint to use to make changes
  - API_DOMAIN=test.example.org
  	- The domain we are using for putting tests under
  - API_RRNAMES=foobar,bazqux
  	- List of RRNAMES to update. These will be updated in turn and cycled though to reduce the change of a test firing and failing between us updating the record and then updating the check.
  	- A Records are created for each `<RRNAME>.<API_DOMAIN>` with an IP Address related to the date/time the change was made, statuscake will then be updated to point at the new `RRNAME` and the expected value.
	- A TXT record named `active` will also be created to remember between runs which `RRNAME` was last named.
  - API_DOMAINKEY=SomeKey
  	- DomainKey to access the domain via the API.
  - STATUSCAKE_USER=SomeUser
  	- StatusCake Username (If blank, this cron script will not run)
  - STATUSCAKE_APIKEY=SomeKey
  	- StatusCake API
  - STATUSCAKE_TESTIDS=12345,67890
  	- The TestIDs for status cake to update. There should be 1 test for each nameserver we monitor.


## Gather Statistics

This will gather statistics from bind servers and put them into influx.

Example ENV VARS Required:

  - INFLUX_HOST=influxdb
  	- Server that hosts influx
  - INFLUX_PORT=8086
  	- Influx Port
  - INFLUX_USER=
  	- Influx Username
  - INFLUX_PASS=
  	- Influx Password
  - INFLUX_DB=MyDNSHost
  	- Influx Database
  - INFLUX_BIND_SLAVES=ns1=1.1.1.1, ns2=2.2.2.2, ns3=3.3.3.3
  	- List of bind servers to get statistics from. This should be a comma-separated list of `<name>=<ip>` to reach the server.
  	- We need the XML statistics API enabled on the server, and this container host needs to be allowed access it.
  	- If this is empty, this cron script will not run.


