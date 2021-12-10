#!/bin/bash
#
# Executes the content diff update -- finds new content in Rewind archive, and imports it to local site.
#

# ---------- VARIABLES CAN EITHER BE SET HERE MANUALLY ... :
# Local WP DB table prefix.
TABLE_PREFIX=wp_
# The --default-character-set param for mysql commands: utf8, utf8mb4, latin1.
DB_CHARSET=utf8mb4
# Full path to JP archive.
LIVE_JETPACK_ARCHIVE=""
# Live site hostname without the www. prefix, e.g. publisher.com.
LIVE_SITE_HOSTNAME=""
# Staging site hostname -- site from which this site was cloned, e.g. "publisher-staging.newspackstaging.com".
STAGING_SITE_HOSTNAME=""
# Temp folder for this script to run -- ! WARNING ! this folder will be deleted and completely purged.
TEMP_DIR=/tmp/launch/temp
# Leave this empty in most cases. In rare cases where the Live site uses a different table prefix than this local site, set the Live prefix here.
JETPACK_TABLE_PREFIX=""

# ---------- ... OR PROVIDED BY CLI PARAMETERS, WHICH THEN OVERRIDE THE ASSIGNMENTS ABOVE :
while true; do
  case "$1" in
    --staging-hostname ) STAGING_SITE_HOSTNAME="$2"; shift 2 ;;
    --live-hostname ) LIVE_SITE_HOSTNAME="$2"; shift 2 ;;
    --live-jp-archive ) LIVE_JETPACK_ARCHIVE="$2"; shift 2 ;;
    --table-prefix ) TABLE_PREFIX="$2"; shift 2 ;;
    --db-charset ) DB_CHARSET="$2"; shift 2 ;;
    --temp-dir ) TEMP_DIR="$2"; shift 2 ;;
    * ) break ;;
  esac
done

# START -----------------------------------------------------------------------------

TIME_START=`date +%s`
. ./inc/functions.sh

set_config
validate_all_params

echo_ts "purging temp dir $TEMP_DIR ..."
purge_temp_folders

# --- prepare:

get_vip_search_replace

echo_ts 'unpacking Jetpack Rewind backup archive and preparing contents for import...'
unpack_jetpack_archive

echo_ts "checking $THIS_PLUGINS_NAME plugin status..."
activate_this_plugin

echo_ts "backing up Staging DB to ${TEMP_DIR}/${DB_NAME_LOCAL}_StagingBackup_${DB_CHARSET}.sql..."
dump_db ${TEMP_DIR}/${DB_NAME_LOCAL}_StagingBackup_${DB_CHARSET}.sql

# --- import DB:

echo_ts 'preparing Live SQL dump for import...'
prepare_live_sql_dump_for_import

echo_ts 'importing Live DB tables...'
import_live_sql_dump

# --- import Live site content diff:

echo_ts 'Searching for new content from Live...'
wp_cli newspack-content-migrator content-diff-search-new-content-on-live --export-dir=$TEMP_DIR_MIGRATOR --live-table-prefix=live_$TABLE_PREFIX

echo_ts 'importing new content from Live...'
wp_cli newspack-content-migrator content-diff-migrate-live-content \
    --import-dir=$TEMP_DIR_MIGRATOR \
    --live-table-prefix=live_$TABLE_PREFIX ;

echo_ts 'syncing files from Live site...'
update_files_from_live_site

# --- finish:

echo_ts 'cleaning up some options...'
clean_up_options

echo_ts 'updating seo settings...'
wp_cli newspack-content-migrator update-seo-settings

echo_ts 'setting file permissions to public content...'
set_public_content_file_permissions

echo_ts 'dropping temp DB tables (prefixed with `live_` and `staging_`)...'
drop_temp_db_tables

echo_ts 'cleaning up tmp resources...'
rm -rf $LIVE_JETPACK_ARCHIVE > /dev/null 2>&1"
rm -rf $TEMP_DIR_JETPACK > /dev/null 2>&1"
rm -rf $TEMP_DIR_JETPACK_UNZIP/* > /dev/null 2>&1"

echo_ts 'flushing WP cache...'
wp_cli cache flush

# END -------------------------------------------------------------------------------

TIME_END=`date +%s`
echo_ts "Finished in $(((TIME_END-TIME_START)/60)) minutes!"
