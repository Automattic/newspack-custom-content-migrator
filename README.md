# newspack-custom-content-migrator
Custom migration tasks for launching and migrating Newspack sites on Atomic.

## Setup

Run `composer install`

## Migrators

Migrators are simple classes which provide export and import functionality for various types content. They register their actionable commands with the WP CLI.

To create a new migrator, use an existing one as a template, then add it to list of migrators in the main plugin file.
