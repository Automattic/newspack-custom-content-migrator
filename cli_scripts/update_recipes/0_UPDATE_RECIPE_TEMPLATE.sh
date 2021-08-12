#!/bin/bash

# ---------- USER SET VARIABLES:
# Local WP DB table prefix.
TABLE_PREFIX=wp_
# The --default-character-set param for mysql commands: utf8, utf8mb4, latin1.
DB_DEFAULT_CHARSET=utf8mb4
# To provide content from the Live site:
#   1. either set location of LIVE_JETPACK_ARCHIVE
#   2. or set both LIVE_HTDOCS_FILES and LIVE_SQL_DUMP_FILE, and leave LIVE_JETPACK_ARCHIVE empty
LIVE_JETPACK_ARCHIVE=/tmp/live_export/jetpack_rewind_backup.tar.gz
LIVE_HTDOCS_FILES=""
LIVE_SQL_DUMP_FILE=""
# Hostname replacements to perform on the Live DB dump before importing it. Keys are live hostname, values are this site's hostname:
declare -A LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS=(
  # [publisher.com]=publisher-launch.newspackstaging.com
  # [www.publisher.com]=publisher-launch.newspackstaging.com
)
# Temp folder for this script -- will be completely deleted and purged!
TEMP_DIR=/tmp/launch/tmp_update
# In rare cases that the Live site used a different prefix than this local site, set the Live prefix here, or leave empty.
JETPACK_TABLE_PREFIX=""


# START -----------------------------------------------------------------------------

TIME_START=`date +%s`
. ./../inc/functions.sh

echo_ts 'purging temp folder...'
prepare_temp_folders

set_config_variables
validate_all_config_params

# --- prepare:

download_vip_search_replace

echo_ts 'starting to unpack the Jetpack Rewind archive and prepare contents for import...'
unpack_jetpack_archive

echo_ts "checking $THIS_PLUGINS_NAME plugin's status..."
update_plugin_status

echo_ts "backing up current DB to ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql..."
dump_db ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql

# --- export:

echo_ts 'exporting Staging site pages...'
wp_cli newspack-content-migrator export-all-staging-pages --output-dir=$TEMP_DIR_MIGRATOR

echo_ts 'exporting Staging site menus...'
wp_cli newspack-content-migrator export-menus --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site active theme custom CSS..."
wp_cli newspack-content-migrator export-current-theme-custom-css --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging pages settings..."
wp_cli newspack-content-migrator export-pages-settings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site identity settings..."
wp_cli newspack-content-migrator export-customize-site-identity-settings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site donation products..."
wp_cli newspack-content-migrator export-reader-revenue --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Listings..."
wp_cli newspack-content-migrator export-listings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site campaigns..."
wp_cli newspack-content-migrator export-campaigns --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site ads..."
wp_cli newspack-content-migrator export-ads --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site newsletters..."
wp_cli newspack-content-migrator export-newsletters --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Reusable blocks..."
wp_cli newspack-content-migrator export-reusable-blocks --output-dir=$TEMP_DIR_MIGRATOR

# --- import:

echo_ts 'preparing Live site SQL dump for import...'
prepare_live_sql_dump_for_import

echo_ts 'importing Live DB tables...'
import_live_sql_dump

echo_ts 'switching Staging site tables with Live site tables...'
replace_staging_tables_with_live_tables

echo_ts 'activating this plugin after the table switch...'
wp_cli plugin activate $THIS_PLUGINS_NAME

echo_ts 'importing all Pages from the Staging site and new pages from the Live site...'
wp_cli newspack-content-migrator import-staging-site-pages --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Menus from the Staging site...'
wp_cli newspack-content-migrator import-menus --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing custom CSS from the Staging site...'
wp_cli newspack-content-migrator import-custom-css-file --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing pages settings from the Staging site...'
wp_cli newspack-content-migrator import-pages-settings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing identity settings from the Staging site...'
wp_cli newspack-content-migrator import-customize-site-identity-settings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing reader revenue products from the Staging site...'
wp_cli newspack-content-migrator import-reader-revenue --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing listings from the Staging site...'
wp_cli newspack-content-migrator import-listings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing campaigns from the Staging site...'
wp_cli newspack-content-migrator import-campaigns --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing ads from the Staging site...'
wp_cli newspack-content-migrator import-ads --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing newsletters from the Staging site...'
wp_cli newspack-content-migrator import-newsletters --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Reusable Blocks from the Staging site...'
wp_cli newspack-content-migrator import-reusable-blocks --input-dir=$TEMP_DIR_MIGRATOR

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
