# Newspack Custom Content Migrator

This plugin is a set of WP CLI commands (**Migrator classes**), and scripts (**Recipes**) used during Newspack sites Live Launches and/or Content Updates.

## TOC

- [Installation](https://github.com/Automattic/newspack-custom-content-migrator#installation)
- [Usage](https://github.com/Automattic/newspack-custom-content-migrator#usage)
- [Migrators and Content Diff](https://github.com/Automattic/newspack-custom-content-migrator#migrators-and-content-diff)
- [Migrators](https://github.com/Automattic/newspack-custom-content-migrator#migrators)
- [Content Diff](https://github.com/Automattic/newspack-custom-content-migrator#content-diff)
- [Creating a Migrator](https://github.com/Automattic/newspack-custom-content-migrator#creating-a-migrator)
- [Running the Content Diff](https://github.com/Automattic/newspack-custom-content-migrator#running-the-content-diff)

## Installation

Run `composer install`.

## Usage


The Plugin is installed on a the Staging Site, and executed there to import the most recent content from the current live site.

## Migrators and Content Diff

This Plugin consists of various Migrators (which all register their WP CLI commands), and the "Content Diff" logic.

The Migrators either perform Publisher specific functionality (PublisherSpecific migrators which do data transformations specific to one single Publisher's site), or reusable functionality (General migrators we can reuse).

The Content Diff is a functionality which updates Staging site's content by syncing the newest/freshest content from the Live site on top of the Staging site.

### Migrators

Migrators are simple PHP classes located in [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) which register WP CLI Commands.

They contain logic/scripts to customize the content migration and update.

There's two kinds of `Migrator` classes:

- **General purpose** -- located in [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General), these contain the standard WP content update logic, used by multiple Live Launches;
- **Publisher-specific** -- located in [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific), these are custom scripts for individual Publisher's specific needs. They are used only once during that particular Publisher's Launch.

### Content Diff

The Content Diff is a functionality which updates Staging site's content by syncing the newest/freshest content from the Live site on top of the Staging site.

It can be run via the [content_diff_update.sh script](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/cli_content_diff_update/content_diff_update.sh), just by providing the JP Rewind archive location, and other necessary info. The Knife uses this script to run the whole CD update automatically



## Creating a Migrator

### New Migrator Class

Take any existing migrator from the [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) and copy it either into the [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General) or the [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific) with a new name.

Migrator classes implement the [`InterfaceMigrator`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/InterfaceMigrator.php) which simply makes sure they register WP CLI commands.

### Register the New Migrator

The new Migrator should be registered in the [`newspack-custom-content-migrator.php`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/newspack-custom-content-migrator.php).

After creating a new Migrator, run `composer dump-autoload` to update the autoloading files.

## Running the Content Diff

The Content Diff imports the newest content from the "live site's DB tables" on top of the existing local (Staging site) content. It works with a JP Rewind backup archive, pulls the live site's content from it and imports the "content diff" to Staging.

The [Content Diff CLI command class](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/General/ContentDiffMigrator.php) exposes commands which we can run manually to first detect the newest content (command `newspack-content-migrator content-diff-search-new-content-on-live`), and then import it (command `newspack-content-migrator content-diff-migrate-live-content`).
