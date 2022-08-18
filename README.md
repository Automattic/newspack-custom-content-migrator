# Newspack Custom Content Migrator

This plugin is a set of WP CLI commands, and scripts used during Newspack sites Live Launches and/or Content Updates.

This Plugin consists of various Migrators (which perform reusable or publisher-specific content migration), and the "Content Diff" logic.

## Installation

Run `composer install`.

## Usage

The Plugin is installed on a the Staging Site, and executed there to import the most recent content from the current live site.

## Migrators

Migrators are classes which perform content migration and data reformatting functionality. They are organized like this:

- located in [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) are WP CLI Command classes
- located in [`src/MigrationLogic`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/MigrationLogic) are business logic classes

There are two kinds of `Migrators`:

- **General purpose** -- located in [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General), these contain the reusable WP data migration logic, used by multiple Live Launches;
- **Publisher-specific** -- located in [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific), these are custom functionalities used one-time only for individual Publisher's specific needs.

## Content Diff

The Content Diff is a functionality which updates Staging site's content by syncing the newest/freshest content from the Live site on top of the Staging site.

It fetches the newest content from the JP Rewind backup archive, by importing "live site's DB tables" side-by-side to the existing local WP tables, and then searches and imports the live site's newest content, and imports the missing "content diff" on top of the Staging site.


## Creating a Migrator

### New Migrator Class

Take any existing migrator from the [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) and copy it either into the [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General) or the [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific) with a new name.

Migrator classes implement the [`InterfaceMigrator`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/InterfaceMigrator.php) which simply makes sure they register WP CLI commands.

### Register the New Migrator

The new Migrator should be registered in the [`newspack-custom-content-migrator.php`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/newspack-custom-content-migrator.php).

After creating a new Migrator, run `composer dump-autoload` to update the autoloading files.

## Running the Content Diff

The Knife uses the [content_diff_update.sh script](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/cli_content_diff_update/content_diff_update.sh) to run the whole CD update automatically.

Alternatively, the [Content Diff CLI command class](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/General/ContentDiffMigrator.php) exposes commands which we can run manually to first detect the newest content (`newspack-content-migrator content-diff-search-new-content-on-live`) and then import it (`newspack-content-migrator content-diff-migrate-live-content`).
