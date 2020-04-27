# Newspack Custom Content Migrator

The plugin is a set of WP CLI commands (**Migrator classes**), and shell scripts (called "**Recipes**") used during Newspack sites Live Launches and/or content updates.

## Usage

### Newspack Live Launch

The Plugin is installed on the Launch Site, and executed there, to import the most recent content from the current live site.

In Newspack's Live Launch workflow, we begin by creating the **Staging Site**, which is a Newspack site-in-development. This site will become the Publisher's (client's) new live site.

The Staging site is first created as a clone of the Publisher's current live site, and then "Newspackified". Once the Staging site development is complete, it is cloned into the Launch Site.

The **Launch Site** needs another content update from the original Live site, before it can be switched to live. That's where this Plugin is executed.

### Newspack Staging Site Update

Occasionally Staging Sites need to get their content refreshed. In this scenario, the Plugin is installed on the Staging site (or a new updated clone of the Staging site), and gets executed here.

## Install

Run `composer install`.

## Migrators and Recipes

As mentioned, the Plugin consists of the WP CLI commands, and shell scripts which execute these commands in the proper order.

### Migrators

Migrators are simple PHP classes located in [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) which register WP CLI Commands.

They contain logic/scripts to customize the content migration and update.

There's two kinds of `Migrator` classes:

- General purpose, located in [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General) -- these are basic content migration logic, which are used during standard WP content migrations 
- Publisher-specific, located in [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific) -- these are custom scripts for individual Publisher's specific needs. They are used only once for that particular Publisher

### Recipes**

Recipes are shell scripts which execute the Migrator Commands in specific order. They also contain some glue-logic, for example switching current DB tables to newly imported ones.

As a rule, all the migration logic should go into the Migrators, and the Recipes should be as light as possible, serving only as an interface to the Commands.

Recipes are located in the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) folder.

The [`0_UPDATE_RECIPE_TEMPLATE.sh`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) always contains the most recent version of the Update Recipe.   

## Creating a Migrator

### New Migrator Class

Take any existing migrator from the [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) and copy it either into the [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General) or the [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific) folder.

Migrator classes implement the [`InterfaceMigrator`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/InterfaceMigrator.php) which makes sure they register WP CLI commands.

### Register the New Migrator

The new Migrator should be registered in the [`newspack-custom-content-migrator.php`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/newspack-custom-content-migrator.php).

## Creating a Recipe for a Publisher Launch

As mentioned, the [`0_UPDATE_RECIPE_TEMPLATE.sh`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) always contains the most recent version of the Update Recipe.

Start by creating a copy of the `0_UPDATE_RECIPE_TEMPLATE.sh` into the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) folder and give it the name of the Publisher, e.g. `update_publishername.sh`, and edit the configuration variables at the top of the script.

The config vars in the Update Recipe script are self-documented, with comments above their definitions and examples.

## Running the Update Recipe

By now you should have a custom Update Recipe in the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) folder.

Download it to the Launch Site, and execute the recipe.

## Sharing the Recipes and Teamwork Flow

The current workflow consists of:
- generating PRs for every update to the Plugin, Migrators and Recipes,
- keeping all the Migrators and Recipe scripts on the `master` branch, so that it's easily shared between team members.
