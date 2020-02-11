# newspack-custom-content-migrator
Custom migration tasks for launching and migrating Newspack sites on Atomic.

## Setup

- `composer install`

## Composer usage

Once a new class is added, run `composer dump-autoload` to have it registered with the autoloader.

## Migrators

Migrators are simple classes which provide export and import functionality for various types content. They register their export and import commands with the WP CLI.

Create a migrator class/file modelled after an existing one, then add it to list in the main plugin file.
