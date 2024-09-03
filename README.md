# Newspack Custom Content Migrator

This plugin is a set of WP CLI commands and scripts used during Newspack sites Live Launches and/or Content Updates.

This Plugin consists of various Migrators (which perform reusable or publisher-specific content migration), and the "Content Diff" logic.

## Installation

Run `composer install`.

## Pulling the latest from the NMT (newspack-migration-tools)
If you have just merged stuff to `trunk` you should update the lockfile in this repository. We point to the `dev-trunk` branch in this repo's composer file so run `composer update automattic/newspack-migration-tools` to update the lockfile and get the latest from the NMT. If nothing happens when you update, then run `composer clear-cache` and try again.

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
