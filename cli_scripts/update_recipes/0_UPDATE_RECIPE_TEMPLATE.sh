#!/bin/bash

# ---------- USER SET VARIABLES:
# Local WP DB table prefix.
TABLE_PREFIX=wp_
# The --default-character-set param for mysql commands: utf8, utf8mb4, latin1.
DB_DEFAULT_CHARSET=utf8mb4
# To provide content from the Live site:
#   1. either set location of LIVE_JETPACK_ARCHIVE,
#   2. or set both LIVE_HTDOCS_FILES and LIVE_SQL_DUMP_FILE, and leave LIVE_JETPACK_ARCHIVE empty,
#      and comment out `purge_temp_folders`.
LIVE_JETPACK_ARCHIVE=/tmp/launch/jetpack_rewind_backup.tar.gz
LIVE_HTDOCS_FILES=""
LIVE_SQL_DUMP_FILE=""
# Hostname replacements to perform on the Live DB dump before importing it. Keys are live hostname, values are this site's hostname:
LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS=(
  # [publisher.com]=publisher-launch.newspackstaging.com
  # [www.publisher.com]=publisher-launch.newspackstaging.com
)
# Staging site hostname -- site from which this site was cloned, e.g. "publisher-staging.newspackstaging.com"
STAGING_SITE_HOSTNAME=""
# Leave this empty in most case. In rare cases where the Live site uses a different table prefix than this local site, set the Live prefix here.
JETPACK_TABLE_PREFIX=""
# Temp folder for this script to run -- ! WARNING ! this folder will be deleted and completely purged.
TEMP_DIR=/tmp/launch/temp


# START -----------------------------------------------------------------------------

TIME_START=`date +%s`
. ./../inc/functions.sh

set_config
validate_all_params

echo_ts "purging temp dir $TEMP_DIR ..."
purge_temp_folders

# --- prepare:

get_vip_search_replace

echo_ts 'unpacking Jetpack Rewind backup archive and preparing contents for import...'
unpack_jetpack_archive

echo_ts "checking $THIS_PLUGINS_NAME plugin status..."
update_plugin_status

echo_ts "backing up current DB to ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql..."
dump_db ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql

# --- export:

echo_ts 'exporting Staging site Pages...'
wp_cli newspack-content-migrator export-all-staging-pages --output-dir=$TEMP_DIR_MIGRATOR

echo_ts 'exporting Staging site Menus...'
wp_cli newspack-content-migrator export-menus --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site custom CSS..."
wp_cli newspack-content-migrator export-current-theme-custom-css --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging pages settings..."
wp_cli newspack-content-migrator export-pages-settings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site identity settings..."
wp_cli newspack-content-migrator export-customize-site-identity-settings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Reader Revenue and Donation products..."
wp_cli newspack-content-migrator export-reader-revenue --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Listings..."
wp_cli newspack-content-migrator export-listings --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Campaigns..."
wp_cli newspack-content-migrator export-campaigns --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Ad units..."
wp_cli newspack-content-migrator export-ads --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Staging site Newsletters..."
wp_cli newspack-content-migrator export-newsletters --output-dir=$TEMP_DIR_MIGRATOR

echo_ts "exporting Reusable Blocks..."
wp_cli newspack-content-migrator export-reusable-blocks --output-dir=$TEMP_DIR_MIGRATOR

# --- import DB:

echo_ts 'preparing Live SQL dump for import...'
prepare_live_sql_dump_for_import

echo_ts 'importing Live DB tables...'
import_live_sql_dump

echo_ts 'switching Staging tables with Live tables...'
replace_staging_tables_with_live_tables

# --- import Staging site data:

echo_ts 'importing Pages from Staging and Live...'
wp_cli newspack-content-migrator import-staging-site-pages --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Menus from Staging...'
wp_cli newspack-content-migrator import-menus --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing custom CSS from Staging...'
wp_cli newspack-content-migrator import-custom-css-file --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Pages settings from Staging...'
wp_cli newspack-content-migrator import-pages-settings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing identity settings from Staging...'
wp_cli newspack-content-migrator import-customize-site-identity-settings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Reader Revenue products from Staging...'
wp_cli newspack-content-migrator import-reader-revenue --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Listings from Staging...'
wp_cli newspack-content-migrator import-listings --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Campaigns from Staging...'
wp_cli newspack-content-migrator import-campaigns --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Ads from Staging...'
wp_cli newspack-content-migrator import-ads --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Newsletters from Staging...'
wp_cli newspack-content-migrator import-newsletters --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'importing Reusable Blocks from Staging...'
wp_cli newspack-content-migrator import-reusable-blocks --input-dir=$TEMP_DIR_MIGRATOR

echo_ts 'updating WooComm settings...'
wp_cli newspack-content-migrator woocomm-setup

echo_ts 'importing content previously converted to blocks from Staging...'
import_blocks_content_from_staging_site

echo_ts 'syncing files from Live site...'
update_files_from_live_site

# --- finish:

echo_ts 'cleaning up some options...'
clean_up_options

echo_ts 'updating seo settings...'
wp_cli newspack-content-migrator update-seo-settings

echo_ts 'setting file permissions to public content (some warnings are expected)...'
set_public_content_file_permissions

echo_ts 'dropping temp DB tables (prefixed with `live_` and `staging_`)...'
drop_temp_db_tables

echo_ts 'flushing WP cache...'
wp_cli cache flush

# END -------------------------------------------------------------------------------

TIME_END=`date +%s`
echo_ts "Finished in $(((TIME_END-TIME_START)/60)) minutes!"
