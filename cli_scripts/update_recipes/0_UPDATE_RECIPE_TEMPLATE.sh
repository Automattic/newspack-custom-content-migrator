#!/bin/bash

# # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # #
# This file contains the most recent curated update recipe. Copy it to this same dir  #
# and give it the Publisher's name. Commit these individual recipe files to master.   #
# # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # #

# ---------- USER SET VARIABLES:
# Local DB table prefix. In a rare case when the VaultPress SQL dump uses a different
# table prefix than the local DB one, you may set it in the VAULTPRESS_TABLE_PREFIX var.
TABLE_PREFIX=wp_
# The --default-character-set param for mysql(dump) commands; utf8, utf8mb4, latin1.
DB_DEFAULT_CHARSET=utf8mb4
# To provide content for import from the Live site,
#   1. either set path to VaultPress archive in LIVE_VAULTPRESS_ARCHIVE
#   2. or set both LIVE_FILES and LIVE_SQL_DUMP_FILE and leave
#      LIVE_VAULTPRESS_ARCHIVE as an empty string ( LIVE_VAULTPRESS_ARCHIVE="" ).
LIVE_VAULTPRESS_ARCHIVE=/tmp/live_export/vaultpress.tar.gz
# Hostname replacements to perform on the Live DB dump before importing it.
# Associative array with REPLACE_HOST_FROM -> REPLACE_HOST_TO as key-value pairs.
# Pure host names, no pre- or post-slashes. One replacement per domain or subdomain.
# It's recommended to also replace the origin Staging site's hostname before cloning.
declare -A LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS=(
  # Here are three example entries. Edit and uncomment these, and enter at least one:
  # [publisher.com]=publisher-launch.newspackstaging.com
  # [www.publisher.com]=publisher-launch.newspackstaging.com
  # [publisher-newspack.newspackstaging.com]=publisher-launch.newspackstaging.com
)

# ---------- AUTOMATICALLY SET AND DEFAULT VARIABLES, no need to change these:
THIS_PLUGINS_NAME='newspack-custom-content-migrator'
# Temp folder for script's resources. No ending slash. Will be purged.
TEMP_DIR=/tmp/launch/tmp_update
# If LIVE_VAULTPRESS_ARCHIVE is given, this var will be set automatically. Otherwise,
# set path to the folder containing Live files, no ending slash. Should contain wp-content.
LIVE_HTDOCS_FILES=""
# If LIVE_VAULTPRESS_ARCHIVE is given, this var will be set automatically. Otherwise,
# set path to Live SQL dump file. This dump should contain only tables from IMPORT_TABLES.
LIVE_SQL_DUMP_FILE=""
# Set the VAULTPRESS_TABLE_PREFIX if the VaultPress SQL dump has a different prefix than
# the local Staging/Launch DB.
VAULTPRESS_TABLE_PREFIX=""
# Tables to import fully from the Live Site, given here without the table prefix.
declare -a IMPORT_TABLES=(commentmeta comments links postmeta posts term_relationships term_taxonomy termmeta terms usermeta users)
# If left empty, the DB_NAME_LOCAL will be fetched from the user name, as a convention on
# Atomic environment. But if a DB schema name is given, it will be used.
DB_NAME_LOCAL=""
# Atomic DB host.
DB_HOST_LOCAL=127.0.0.1
# Path to the public folder. No ending slash.
HTDOCS_PATH=/srv/htdocs
# Atomic WP CLI params.
WP_CLI_BIN=/usr/local/bin/wp-cli
WP_CLI_PATH=/srv/htdocs/__wp__/
# If this var is left empty, the VIP's search-replace tool will be downloaded from
# https://github.com/Automattic/go-search-replace, otherwise full path to binary.
SEARCH_REPLACE=""

# ---------- SCRIPT VARIABLES, do not change these:
# Migration Plugin's output dir (the Plugin uses hard-coded file names).
TEMP_DIR_MIGRATOR=$TEMP_DIR/migration_exports
# VaultPress export temp dir, where the SQL dump and files get extracted to.
TEMP_DIR_VAULTPRESS=$TEMP_DIR/vaultpress_archive
# Another VP temp dir, where the archive initially gets extracted to.
TEMP_DIR_VAULTPRESS_UNZIP=$TEMP_DIR_VAULTPRESS/unzip
# Name of file where to save the Live SQL dump after hostname replacements are made.
LIVE_SQL_DUMP_FILE_REPLACED=$TEMP_DIR/live_db_hostnames_replaced.sql

# START -----------------------------------------------------------------------------

# --- init:

TIME_START=`date +%s`
. ./../inc/functions.sh

echo_ts 'purging temp folder...'
prepare_temp_folders

set_auto_config_variables
validate_all_config_params

# --- prepare:

download_vip_search_replace

echo_ts 'starting to unpack the VaultPress archive and prepare contents for import...'
unpack_vaultpress_archive

echo_ts "checking $THIS_PLUGINS_NAME plugin's status..."
update_plugin_status

echo_ts "backing up current DB to ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql..."
back_up_staging_site_db

# --- export:

echo_ts 'exporting Staging site pages...'
export_staging_site_pages

echo_ts 'exporting Staging site menus...'
export_staging_site_menus

echo_ts "exporting Staging site active theme custom CSS..."
export_staging_site_custom_css

echo_ts "exporting Staging pages settings..."
export_staging_site_page_settings

echo_ts "exporting Staging site identity settings..."
export_staging_site_identity_settings

echo_ts "exporting Staging site donation products..."
export_staging_site_donation_products

echo_ts "exporting Staging site Listings..."
export_staging_site_listings

echo_ts "exporting Staging site campaigns..."
export_staging_site_campaigns

echo_ts "exporting Staging site ads..."
export_staging_site_ads

echo_ts "exporting Staging site newsletters..."
export_staging_site_newsletters

echo_ts "exporting Reusable blocks..."
export_staging_site_reusable_blocks

# --- import:

echo_ts 'preparing Live site SQL dump for import...'
prepare_live_sql_dump_for_import

echo_ts 'importing Live DB tables...'
import_live_sql_dump

echo_ts 'switching Staging site tables with Live site tables...'
replace_staging_tables_with_live_tables

echo_ts 'activating this plugin after the table switch...'
wp_cli plugin activate $THIS_PLUGINS_NAME

if [[ 1 == $IS_EXPORTED_STAGING_PAGES ]]; then
  echo_ts 'importing all Pages from the Staging site and new pages from the Live site...'
  wp_cli newspack-content-migrator import-staging-site-pages --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Pages from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_STAGING_MENUS ]]; then
  echo_ts 'importing Menus from the Staging site...'
  wp_cli newspack-content-migrator import-menus --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Menus from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_CUSTOM_CSS ]]; then
  echo_ts 'importing custom CSS from the Staging site...'
  wp_cli newspack-content-migrator import-custom-css-file --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing custom CSS from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_PAGES_SETTINGS ]]; then
  echo_ts 'importing pages settings from the Staging site...'
  wp_cli newspack-content-migrator import-pages-settings --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing pages settings from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_PAGES_IDENTITY_SETTINGS ]]; then
  echo_ts 'importing identity settings from the Staging site...'
  wp_cli newspack-content-migrator import-customize-site-identity-settings --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing pages settings from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_DONATION_PRODUCTS ]]; then
  echo_ts 'importing reader revenue products from the Staging site...'
  wp_cli newspack-content-migrator import-reader-revenue --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing reader revenue products from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_LISTINGS ]]; then
  echo_ts 'importing listings from the Staging site...'
  wp_cli newspack-content-migrator import-listings --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing listings from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_CAMPAIGNS ]]; then
  echo_ts 'importing campaigns from the Staging site...'
  wp_cli newspack-content-migrator import-campaigns --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Newspack Campaigns from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_ADS ]]; then
  echo_ts 'importing ads from the Staging site...'
  wp_cli newspack-content-migrator import-ads --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Ads from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_NEWSLETTERS ]]; then
  echo_ts 'importing newsletters from the Staging site...'
  wp_cli newspack-content-migrator import-newsletters --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Newsletters from the Staging site.'; fi

if [[ 1 == $IS_EXPORTED_REUSABLE_BLOCKS ]]; then
  echo_ts 'importing Reusable Blocks from the Staging site...'
  wp_cli newspack-content-migrator import-reusable-blocks --input-dir=$TEMP_DIR_MIGRATOR
else echo_ts_yellow 'Skipping importing Reusable Blocks from the Staging site.'; fi

echo_ts 'importing Staging content previously converted to blocks...'
import_blocks_content_from_staging_site

echo_ts 'updating WooComm settings...'
wp_cli newspack-content-migrator woocomm-setup

echo_ts 'syncing files from Live site...'
update_files_from_live_site

# --- finish:

echo_ts 'cleaning up options...'
clean_up_options

echo_ts 'updating seo settings...'
wp_cli newspack-content-migrator update-seo-settings

echo_ts 'setting file permissions to public content...'
set_public_content_file_permissions

# # Recommended to keep these tables for a short while after the launch, for easier problem fixing
# echo_ts 'dropping temp DB tables (prefixed with `live_` and `staging_`)...'
# drop_temp_db_tables

echo_ts 'flushing WP cache...'
wp_cli cache flush

# END -------------------------------------------------------------------------------

TIME_END=`date +%s`
echo_ts "Finished in $(((TIME_END-TIME_START)/60)) minutes!"
