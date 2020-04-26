#!/bin/bash

# # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # #
# This file contains the most recent curated update recipe. Copy it to this same dir  #
# and give it the Publisher's name. Commit these individual recipe files to master.   #
# # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # # #

# ---------- USER SET VARIABLES:
# DB params
DB_NAME=dbname
TABLE_PREFIX=wp_
# The --default-character-set param for mysql(dump) commands; utf8, utf8mb4, latin1.
DB_DEFAULT_CHARSET=utf8mb4
# Location of Live site files. No ending slash. Should contain wp-content.
# Remove any sensitive data or unsupported content, since this is synced to htdocs.
LIVE_FILES=/tmp/launch/live_dump/files
# Live site's SQL dump file. This dump needs to contain only tables specified in
# the IMPORT_TABLES variable (see below).
LIVE_SQL_DUMP_FILE=/tmp/launch/live_dump/sql/live.sql
# Hostname replacements to perform on the Live DB dump before importing it.
# Associative array with REPLACE_HOST_FROM -> REPLACE_HOST_TO as key-value pairs.
# Pure host names, no pre- or post-slashes. One replacement per domain or subdomain.
# It's recommended to also replace the origin Staging site's hostname before cloning.
declare -A LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS=(
  # Dummy example entries, edit these:
  [publisherlive.org]=publisher-launch.newspackstaging.com
  [www.publisherlive.org]=publisher-launch.newspackstaging.com
  [publisher-newspack.newspackstaging.com]=publisher-launch.newspackstaging.com
)

# ---------- DEFAULT VARIABLES FOR ATOMIC, probably no need to change these:
THIS_PLUGINS_NAME='newspack-custom-content-migrator'
# Temp folder for script's resources. No ending slash. Will be purged.
TEMP_DIR=/tmp/launch/tmp_update
# Name of file where to save the Live SQL dump after hostname replacements.
LIVE_SQL_DUMP_FILE_REPLACED="${TEMP_DIR}/live_db_hostnames_replaced.sql"
# A separate temp dir for Migration Plugin's output files (the Plugin uses hard-coded
# file names).
MIGRATOR_TEMP_DIR=$TEMP_DIR/migration_exports
# Atomic DB host.
DB_HOST=127.0.0.1
# Path to the public folder. No ending slash.
HTDOCS_PATH=/srv/htdocs
# Atomic WP CLI params.
WP_CLI_BIN=/usr/local/bin/wp-cli
WP_CLI_PATH=/srv/htdocs/__wp__/
# Tables to import fully from the Live Site, given here without the table prefix.
declare -a IMPORT_TABLES=(commentmeta comments links postmeta posts term_relationships term_taxonomy termmeta terms usermeta users)
# VIP's search-replace tool. If this var is left empty, it will be downloaded from
# https://github.com/Automattic/go-search-replace, otherwise full path to binary.
SEARCH_REPLACE=""

# START -----------------------------------------------------------------------------

TIME_START=`date +%s`
. ./../inc/functions.sh

# --- prepare:

echo_ts "checking $THIS_PLUGINS_NAME plugin's status..."
update_plugin_status

echo_ts 'purging the temp folder...'
purge_temp_folder

validate_user_config_params

echo_ts "backing up current DB to ${TEMP_DIR}/${DB_NAME}_backup_${DB_DEFAULT_CHARSET}.sql..."
backup_staging_site_db

# --- export:

echo_ts 'exporting Staging site pages...'
export_staging_site_pages

echo_ts 'exporting Staging site menus...'
export_staging_site_menus

echo_ts "exporting Staging site active theme custom CSS..."
export_staging_site_custom_css

echo_ts "exporting Staging pages settings..."
export_staging_site_page_settings

echo_ts 'backing up the Newspack Content Converter Plugin table...'
back_up_newspack_content_migrator_staging_table

echo_ts 'preparing Live site SQL dump for import...'
prepare_live_sql_dump_for_import

# --- import:

echo_ts 'importing Live DB tables...'
import_live_sql_dump

echo_ts 'switching Staging site tables to Live site tables...'
replace_staging_tables_with_live_tables

echo_ts 'activating this plugin after the table switch...'
wp_cli plugin activate $THIS_PLUGINS_NAME

if [[ 1 == $IS_EXPORTED_STAGING_PAGES ]]; then
  echo_ts 'importing Pages from the Staging site...'
  wp_cli newspack-content-migrator import-staging-site-pages --input-dir=$MIGRATOR_TEMP_DIR
fi

if [[ 1 == $IS_EXPORTED_STAGING_MENUS ]]; then
  echo_ts 'importing Menus from the Staging site...'
  wp_cli newspack-content-migrator import-menus --input-dir=$MIGRATOR_TEMP_DIR
fi

if [[ 1 == $IS_EXPORTED_CUSTOM_CSS ]]; then
  echo_ts 'importing custom CSS from the Staging site...'
  wp_cli newspack-content-migrator import-custom-css-file --input-dir=$MIGRATOR_TEMP_DIR
fi

if [[ 1 == $IS_EXPORTED_PAGES_SETTINGS ]]; then
  echo_ts 'importing pages settings from the Staging site...'
  wp_cli newspack-content-migrator import-pages-settings --input-dir=$MIGRATOR_TEMP_DIR
fi

if [[ 1 == $IS_BACKED_UP_STAGING_NCC_TABLE ]]; then
  echo_ts 'importing Staging content site which was previously already converted to blocks...'
  import_blocks_content_from_staging_site
fi

echo_ts 'syncing files from Live site...'
update_files_from_live_site

# --- finish:

echo_ts 'cleaning up options...'
clean_up_options

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
