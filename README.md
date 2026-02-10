<p align="center"><a href="https://dbdiff.github.io/DBDiff/" target="_blank" rel="noopener noreferrer"><img width="100" src="https://avatars3.githubusercontent.com/u/12562465?s=200&v=4" alt="DBDiff logo"></a></p>

<p align="center">
	<a href="https://github.com/DBDiff/DBDiff/actions/workflows/tests.yml"><img src="https://github.com/DBDiff/DBDiff/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/downloads" alt="Total Downloads"></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/d/monthly" alt="Monthly Downloads"></a>
	<a href="https://github.com/dbdiff/dbdiff/graphs/contributors"><img src="https://img.shields.io/github/contributors/dbdiff/dbdiff.svg" /></a>
	<a href="https://packagist.org/packages/dbdiff/dbdiff"><img src="https://poser.pugx.org/dbdiff/dbdiff/license" alt="License"></a>
</p>

<p align="center">
	<strong>DBDiff</strong> is an automated database schema and data diff tool. It compares two databases, local or remote, and produces a migration file of the differences automatically.
</p>

<p align="center">
	When used alongside a <a href="#compatible-migration-tools">compatible database migration tool</a>, it can help enable database version control within your team or enterprise.
</p>


## Features
-   Works on Windows, Linux & Mac command-line/Terminal because it has been developed in PHP
-   Connects to a source and target database to do the comparison diff, locally and remotely
-   Diffs can include changes to the schema and/or data, both in valid SQL to bring the target up-to-date with the source
-   **Deterministic Output**: SQL is generated in a stable, predictable order (tables, columns, and data), ensuring consistent migrations and test pass rates across different environments.
-   Some tables and/or fields can be ignored in the comparison with a YAML collection in the config file (see File Examples)
-   Diffs are SUPER fast and this tool has been tested with databases of multiple tables of millions of rows
-   Since this diff tool is being used for migrations, it provides up and down SQL in the same file
-   Works with existing migration tools like Flyway and Simple DB Migrate by specifying output template files/formats
-   Is Unicode aware, can work with UTF8 data, which includes foreign characters/symbols
-   Works with just MySQL for now, but we will be expanding to other DBs in the future on request (please create an issue and vote on it!)
-   Runs on parallel threads to speed up the diff process

## Pre-requisites
1. You will need to have access to the command-line (Terminal/CMD/PowerShell)
2. You will need to have git installed
3. You will need to have PHP installed (version 7.4.x, 8.3.x, or 8.4.x)
4. You will need to have Composer installed

_Note: Make a note of where `composer.phar` is installed as we will need it later on during Setup_

* PHP 7.4.x
* PHP 8.3.x
* PHP 8.4.x

## Supported MySQL Database Versions

_Other versions may work but are not actively supported. Feel free to contribute a PR to add official support._

* MySQL 8.0.x
* MySQL 8.4.x (LTS)
* MySQL 9.3.x (Innovation)

## Installation
On the command-line, use `git` to clone the ssh version:

	git clone git@github.com:DBDiff/DBDiff.git

**Or** use `git` to clone the https version:

	git clone https://github.com/DBDiff/DBDiff.git

**Or** download the .zip archive and unzip it to a folder of your choosing e.g. dbdiff:

	https://github.com/DBDiff/DBDiff/archive/master.zip

**Or** use `composer` to include `DBDiff` as a project dependency:

	php composer.phar require "dbdiff/dbdiff:@dev"

**Or** use `composer` to install `DBDiff` globally:

	composer global require "dbdiff/dbdiff:@dev"

## Create a PHAR build

Please first ensure in your `php.ini` file the `phar.readonly` setting is set to `false` , for example:

```ini
[Phar]
; http://php.net/phar.readonly
phar.readonly = false
```

Then in the root of the dbdiff repository to produce a Phar build simply run:

```
$ ./scripts/build
```

A `dist` folder should be created containing the following files:

* dbdiff.phar
* dbdiff.phar.gz

Feel free to rename `dbdiff.phar` to `dbdiff` and move it to `/usr/local/bin` or another directory of your choice.

You can also add it to your system's path if you wish to make it globally available on your system as a utility.

## Docker

You may now use `docker` and `docker-compose` to create a local environment for DBDiff (for testing or production), including a PHP server with a database and the DBDiff CLI available as a service.

Please ensure you have `docker` and/or `docker-compose` installed locally, as well as a download of the git repository, before continuing.

_Note: Please run these commands from the root of the DBDiff folder. Also the commands may need to be prepended with `sudo` on some systems._

### Docker Standalone DBDiff CLI with PHP 7.3

```bash
# Build DBDiff CLI Image
docker build --tag "dbdiff:latest" --file "docker/Dockerfile" .
```

```bash
# Run DBDiff CLI Image as a Container
docker run -i -t --ipc=host --shm-size="1g" "dbdiff:latest" <command>
```

```bash
# Remove DBDiff CLI Image
docker image rm dbdiff:latest
```

### Cross-Version Testing

You can easily test DBDiff against any combination of PHP and MySQL using the provided `start.sh` script.

```bash
# Run tests for a specific combination
./start.sh 8.3 8.0

# Run all 9 version combinations in parallel (3x speedup)
./start.sh all all --parallel
```

See the [DOCKER.md](DOCKER.md) file for extensive documentation on the Dockerized test runner, including flags for **fast restarts**, **recording fixtures**, and **automated CI**.

### Removing Docker Compose DBDiff Environment

```bash
docker-compose down
```

## Setup

_Make sure you are in the root of your application for all the following steps, using 'cd' to navigate on the command line to where you have placed your "dbdiff" folder_

_We are going to assume that `composer.phar` is installed inside your "dbdiff" folder. If it is installed elsewhere you will need to use it's exact path_

1. If you didn't install `DBDiff` with `composer`, install the dependencies of the project with: `php composer.phar install`
2. Make a `.dbdiff` file by following the [File Examples](#file-examples) and place it in the root of your "dbdiff" directory
3. Type `./dbdiff {dbdiff command here e.g. server1.db1:server1.db2}` to start the app! See [Command-Line API](#command-line-api) for more details on which commands you can run.

You should see something like...

	ℹ Now calculating schema diff for table `foo`
	ℹ Now calculating data diff for table `foo`
	ℹ Now generating UP migration
	ℹ Now generating DOWN migration
	ℹ Writing migration file to /path/to/dbdiff/migration.sql
	✔ Completed

Congratulations you have installed and ran DBDiff!

## Command-Line API

_Note: The command-line parameters will always override the settings in the `.dbdiff` config file_

-   **--server1=user:password@host1:port** - Specify the source db connection details. If there is only one server the --server1 flag can be omitted
-   **--server2=user:password@host2:port** - Specify the target db connection details (if it’s different to server1)
-   **--template=templates/simple-db-migrate.tmpl** - Specifies the output template, if any. By default will be plain SQL
-   **--type=schema** or **data** or **all** - Specifies the type of diff to do either on the schema, data or both. schema is the default
-   **--include=up** or **down** or **all** - Specified whether to include the up, down or both data in the output. up is the default
-   **--nocomments=true** - By default automated comments starting with the hash (\#) character are included in the output file, which can be removed with this parameter
-   **--config=config.yaml** - By default, DBDiff will look for a `.dbdiff` file in the current directory which is valid YAML, which may also be overridden with a config file that lists the database host, user, port and password of the source and target DBs in YAML format (instead of using the command line for it), or any of the other settings e.g. the format, template, type, include, nocomments. Please note: a command-line parameter will always override any config file.
-   **server1.db1.table1:server2.db2.table3** or **server1.db1:server2.db2** - The penultimate parameter is what to compare. This tool can compare just one table or all tables (entire db) from the database
-   **--output=./output-dir/today-up-schema.sql** - The last parameter is an output file and/or directory to output the diff to, which by default will output to the same directory the command is run in if no directory is specified. If a directory is specified, it should exist, otherwise an error will be thrown. If this path is not specified, the default file name becomes migration.sql in the current directory

## Usage Examples

### Example 1
`$ ./dbdiff server1.db1:server2.db2`

This would by default look for the `.dbdiff` config file for the DB connection details, if it’s not there the tool would return an error. If it’s there, the connection details would be used to compare the SQL of only the schema and output a commented migration.sql file inside the current directory which includes only the up SQL as per default

### Example 2
`$ ./dbdiff server1.development.table1:server2.production.table1 --nocomments=true --type=data`

This would by default look for the `.dbdiff` config file for the DB connection details, if it’s not there the tool would return an error. If it’s there, the connection details would be used to compare the SQL of only the data of the specified table1 inside each database and output a .sql file which has no comments inside the current directory which includes only the up SQL as per default

### Example 3
`$ ./dbdiff --config=config.conf --template=templates/simple-db-migrate.tmpl --include=all server1.db1:server2.db2 --output=./sql/simple-schema.sql`

Instead of looking for `.dbdiff`, this would look for `config.conf` (which should be valid YAML) for the settings, and then override any of those settings from `config.conf` for the --template and --include parameters given in the command-line parameters - thus comparing the source with the target database and outputting an SQL file called simple-schema.sql to the ./sql folder, which should already exist otherwise the program will throw an error, and which includes only the schema as an up and down SQL diff in the simple-db-migrate format (as specified by the template). This example would work perfectly alongside the simple-db-migrate tool

## File Examples

### .dbdiff
	server1:
		user: user
		password: password
		port: port # for MySQL this is 3306
		host: host1 # usually localhost or 127.0.0.1
	server2:
		user: user
		password: password
		port: port # for MySQL this is 3306
		host: host1 # usually localhost or 127.0.0.1
	template: templates/simple-db-migrate.tmpl
	type: all
	include: all
	nocomments: true
	tablesToIgnore:
	- table1
	- table2
	- table3
    - parallelTables: 8          # optional, defaults to 1
    - skipIdenticalTables: true  # enables the CHECKSUM-based short-circuit
	fieldsToIgnore:
		table1:
			- field1
			- field2
			- field3
		table4:
			- field1
			- field4

### simple-db-migrate.tmpl

	SQL_UP = u"""
	{{ $up }}
	"""
	SQL_DOWN = u"""
	{{ $down }}
	"""

## How Does the Diff Actually Work?

The following comparisons run in exactly the following order:

-   When comparing multiple tables: all comparisons should be run
-   When comparing just one table with another: only run the schema and data comparisons

### Overall Comparison
-   Check both databases exist and are accessible, if not, throw an error
-   The database collation is then compared between the source and the target and any differences noted for the output

### Schema Comparison
-   Looks to see if there are any differences in column numbers, name, type, collation or attributes
-   Any new columns in the source, which are not found in the target, are added

### Checksum Comparison
-   If the `skipIdenticalTables` setting is enabled, the checksum of each table is calculated and compared to see if they are identical. If they are identical, the table is skipped and no further comparisons are run for that table.

### Data Comparison
-   And then for each table, the table storage type (e.g. MyISAM, CSV), the collation (e.g. utf8\_general\_ci), and number of rows are compared, in that order. If there are any differences they are noted before moving onto the next test
-   Next, both changed rows as well as missing rows from each table are recorded

## Compatible Migration Tools

| Project | Language / Package Manager | Description |
|---------|--------|-------------|
| [Simple DB Migrate](https://github.com/guilhermechapiewski/simple-db-migrate)          | Python / PIP | Generic database migration tool inpired on Rails migrations |
| [Flyway](https://github.com/flyway/flyway)                | Java / Maven | Database Migrations Made Easy |
	
Please do [let us know](https://akalsoftware.com/) if you're using any other migration tools with DBDiff, other than the ones listed here, so we can add it.

## Questions & Support 💡

* Create a new [issue](https://github.com/dbdiff/dbdiff/issues/new/choose) if you can't find yours [being addressed](https://github.com/dbdiff/dbdiff/issues)
* Watch this space, as we're in the process of creating a discourse forum for all the DBDiff community
- The documentation so far is what you see on this page, however this will slowly be expanded onto it's own website
* If you are a company or organisation interested in commercial support packages for DBDiff please [get in touch](https://akalsoftware.com/)

## Contributions 💖

Please make sure to read the [Contributing Guide](https://github.com/dbdiff/dbdiff/blob/master/.github/CONTRIBUTING.md) before making a pull request.

Thank you to all the people who already contributed to DBDiff!

<a href="https://github.com/dbdiff/dbdiff/graphs/contributors"><img src="https://img.shields.io/github/contributors/dbdiff/dbdiff.svg" /></a>

## Releasing 🚀

DBDiff uses automated workflows for versioning and distribution:

### 1. Automated Release (Recommended)
You can trigger a formal release directly from the **GitHub Actions** tab:
- Select the **"Release DBDiff"** workflow.
- Click **"Run workflow"** and specify the new version (e.g. `v2.0.0`).
- This will automatically:
    - Build the production PHAR using `scripts/build`.
    - Create and push the Git tag.
    - Create a GitHub Release with build assets attached.
    - Notify Packagist to update the stable version.

### 2. Manual/Local Release
If you need to tag a release locally:
```bash
./scripts/release.sh v2.0.0
git push origin v2.0.0
```
Then manually upload the files from the `dist/` folder to the GitHub Release page.

---

## Feedback 💬

If you've made it down here, you're probably a fan 😉

Could you please kindly spare 2 minutes to give us your feedback on DBDiff:

https://forms.gle/gjdJxZxdVsz7BRxg7

We read each and every suggestion that comes through.

## License

[MIT](http://opensource.org/licenses/MIT)

<p align="center">Made with 💖 by 
<a href="https://akalsoftware.com/" target="_blank" rel="noopener noreferrer"><img width="150" src="images/akal-logo.svg" alt="Akal Logo"></a></p>
