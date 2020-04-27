# Newspack Custom Content Migrator

This plugin is a set of WP CLI commands (**Migrator classes**), and scripts scripts (**Recipes**) used during Newspack sites Live Launches and/or Content Updates.

- [Installation](https://github.com/Automattic/newspack-custom-content-migrator#installation)
- [Usage](https://github.com/Automattic/newspack-custom-content-migrator#usage)
- [Migrators and Recipes](https://github.com/Automattic/newspack-custom-content-migrator#migrators-and-recipes)
- [Creating a Migrator](https://github.com/Automattic/newspack-custom-content-migrator#creating-a-migrator)
- [Creating a Recipe](https://github.com/Automattic/newspack-custom-content-migrator#creating-a-recipe)
- [Running the Update Recipe](https://github.com/Automattic/newspack-custom-content-migrator#running-the-update-recipe)
- [Sharing the Recipes and Teamwork Flow](https://github.com/Automattic/newspack-custom-content-migrator#sharing-the-recipes-and-teamwork-flow)

## Installation

Run `composer install`.

## Usage

### Newspack Live Launch

The Plugin is installed on a Launch Site, and executed there, to import the most recent content from the current live site.

In Newspack's Live Launch workflow, we begin by creating the **Staging Site**. This is a Newspack site-in-development, initially crated as a clone of their current **live site**. The Staging site will become the Publisher's (client's) new live site.

Once the Staging site development is complete, it is cloned into the Launch Site. The **Launch Site** needs a content update from the original Live site, before it can be switched to live. And that is where this Plugin is executed -- on the Launch Site.

### Newspack Staging Site Update

Staging Sites occasionally need to get their content refreshed. In that scenario, the Plugin gets installed on the Staging site (or a new updated clone of the Staging site), and the Update Recipe is executed here.

## Migrators and Recipes

The Plugin consists of the Migrators which register WP CLI commands, and Recipe shell scripts which get to execute these commands in proper order.

### Migrators

Migrators are simple PHP classes located in [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) which register WP CLI Commands.

They contain logic/scripts to customize the content migration and update.

There's two kinds of `Migrator` classes:

- **General purpose** -- located in [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General), these contain the standard WP content update logic, used by multiple Live Launches;
- **Publisher-specific** -- located in [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific), these are custom scripts for individual Publisher's specific needs. They are used only once during that particular Publisher's Launch.

### Recipes

Recipes are shell scripts located in the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes), which execute the Migrator Commands in specific order.

The [`0_UPDATE_RECIPE_TEMPLATE.sh`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) always contains the most recent version of the Update Recipe.   

They also contain some glue-logic, for example switching current DB tables to newly imported ones. But as a rule, the Recipes should be as thin as possible, and all the migration logic should go into the Migrators as new WP CLI commands.

## Creating a Migrator

### New Migrator Class

Take any existing migrator from the [`src/Migrator`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator) and copy it either into the [`src/Migrator/General`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/General) or the [`src/Migrator/PublisherSpecific`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/src/Migrator/PublisherSpecific) with a new name.

Migrator classes implement the [`InterfaceMigrator`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Migrator/InterfaceMigrator.php) which simply makes sure they register WP CLI commands.

### Register the New Migrator

The new Migrator should be registered in the [`newspack-custom-content-migrator.php`](https://github.com/Automattic/newspack-custom-content-migrator/blob/master/newspack-custom-content-migrator.php).

## Creating a Recipe

As mentioned, the [`0_UPDATE_RECIPE_TEMPLATE.sh`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) always contains the most recent version of the Update Recipe.

Start by creating a copy of the `0_UPDATE_RECIPE_TEMPLATE.sh` into the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) folder, and give it a custom name, e.g. `update_publishername.sh`.

Next, edit the configuration variables at the top of the script. The config vars in the Update Recipe script are self-documented, with comments above their definitions and examples.

## Running the Update Recipe

Once you've created a custom Update Recipe in the [`cli_scripts/update_recipes`](https://github.com/Automattic/newspack-custom-content-migrator/tree/master/cli_scripts/update_recipes) folder, download the Plugin to the Launch Site, and execute the Recipe scipt.

## Sharing the Recipes and Teamwork Flow

The current workflow is a simplest one, and consists of:
- generate PRs for every update to the Plugin, Migrators and Recipes,
- keep all the Migrators and Recipe scripts on the `master` branch, so that it's easily shared between team members.
