# Newspack Custom Content Migrator

This plugin is a set of WP CLI commands and scripts used during Newspack sites Live Launches and/or Content Updates.

This Plugin consists of various Migrators (which perform reusable or publisher-specific content migration), and the "Content Diff" logic.

## Installation

Run `composer install`.

### Running PHPCS
* Run `composer run-script phpcs` to check for coding standards issues on all files. If you pass `-- path/to/file(s)` it will only run on that.
* Run `composer run-script phpcbf` to automatically fix coding standards issues on all files. If you pass `-- path/to/file(s)` it will only run on that.
* Run `composer run-script phpcs-unstaged-diff` to check the lines in files that have changes that are not staged yet.

## Working with the NMT (newspack-migration-tools)
We are aiming to have all re-usable logic in the NMT. We pull in the NMT with composer, so that means that you need to keep your branch updated. Whenever code has been merged to trunk in the NMT, do a `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT into this repository. We point to the `dev-trunk` branch in this repo's composer file so run `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT. If nothing happens when you update, then run `composer clear-cache` and try again.

Here is a oneliner (well – there are three lines for readability) that is safe to use even if you have the NMT symlinked into the `vendor` directory:

```bash
rm -rf vendor/automattic/newspack-migration-tools && git checkout trunk && composer update automattic/newspack-migration-tools && git add composer.lock 
git commit -m 'Updating NMT composer pointer'
git push 
```

### Working on the NMT and this repository at the same time
It's likely that you'll have changes to both the NMT and the branch you are working in on the NCCM (this repo) too. To avoid working in the `vendor` directory, an easy way is to create a directory called `dev` in the root of this repository, go into that directory and then clone the NMT so you end up with a structure like: `dev/newspack-migration-tools`. Once you have that checked out into the `dev` directory, then (from the root of this repo) run `composer run-script update-with-nmt-symlinked`. This will symlink the NMT into the `vendor` directory so you can work on both at the same time. If you need to update the NMT, then go into the `dev/newspack-migration-tools` directory and do your work there. Once you have merged your changes to `trunk` in the NMT, then come back to this repo and run `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT.

## Usage

The Plugin is installed on a the Staging Site, and executed there to import the most recent content from the current live site.

## Migrators

Migrators are classes which perform content migration and data reformatting functionality. They are organized like this:

- located in [`src/Command`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command) are WP CLI Command classes
- located in [`src/Logic`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Logic) are business logic classes

There are two kinds of `Commands`:

- **General purpose** -- located in [`src/Command/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command/General), these contain the reusable WP data migration logic, used by multiple Live Launches;
- **Publisher-specific** -- located in [`src/Command/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command/PublisherSpecific), these are custom functionalities used one-time only for individual Publisher's specific needs.

## Content Diff

The Content Diff is a functionality which updates Staging site's content by syncing the newest/freshest content from the Live site on top of the Staging site.

It fetches the newest content from the JP Rewind backup archive, by importing "live site's DB tables" side-by-side to the existing local WP tables, and then searches and imports the live site's newest content, and imports the missing "content diff" on top of the Staging site.


## Creating a Migrator

### New Command Class

Take any existing migrator from the [`src/Command`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command) and copy it either into the [`src/Command/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command/General) or the [`src/Command/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Command/PublisherSpecific) with a new name.

Command classes implement the [`InterfaceCommand`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Command/InterfaceCommand.php) which simply makes sure they register WP CLI commands.

### Register the New Command

The new Command should be registered in the [`newspack-custom-content-migrator.php`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/newspack-custom-content-migrator.php).

After creating a new Command, run `composer dump-autoload` to update the autoloading files.

## Running the Content Diff

The Knife uses the [content_diff_update.sh script](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/cli_content_diff_update/content_diff_update.sh) to run the whole CD update automatically.

Alternatively, the [Content Diff CLI command class](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/General/ContentDiffMigrator.php) exposes commands which we can run manually to first detect the newest content (`newspack-content-migrator content-diff-search-new-content-on-live`) and then import it (`newspack-content-migrator content-diff-migrate-live-content`).

## Creating a release
* Update the version number in newspack-custom-content-migrator.php
* Git that with the version number
* Run `composer run-script release`
* Create a new release (with the browser) on Github and upload the .zip file from the release you just built.
