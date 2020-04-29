#!/bin/bash

# A more convenient way to execute WP CLI.
function wp_cli() {
 eval $WP_CLI_BIN --path=$WP_CLI_PATH $@
}

# Based on the last previously executed command's exit code, sets a custom variable's
# value to 0 if the exit code was 0, or 1 otherwise.
#  - arg1: custom name variable to set to 0 or 1.
function set_var_by_previous_exit_code() {
  if [ 0 != $? ]; then
    eval $1'=1'
  else
    eval $1'=0'
  fi
}

# Checks if Plugin is active, and activates it if not.
function update_plugin_status() {
  local PLUGIN_STATUS=$(wp_cli plugin list | grep "$THIS_PLUGINS_NAME" | awk '{print $2}')
  if [[ 'active' = $PLUGIN_STATUS ]]; then
    return
  fi

  echo_ts 'plugin inactive, activating now...'
  wp_cli plugin activate $THIS_PLUGINS_NAME
  PLUGIN_STATUS=$(wp_cli plugin list | grep "$THIS_PLUGINS_NAME" | awk '{print $2}')
  if [[ 'active' != $PLUGIN_STATUS ]]; then
    echo_ts_red "ERROR: could not activate $THIS_PLUGINS_NAME Plugin. Make sure it's actvated then try again."
    exit
  fi
}

function validate_and_set_user_config_params() {
  validate_and_download_vip_search_replace
  validate_and_set_db_name
  validate_db_connection
  validate_db_default_charset
  validate_table_prefix
  validate_live_files
  validate_live_sql_dump_file
  validate_live_db_hostname_replacements
}

function purge_temp_folder() {
  rm -rf $TEMP_DIR || true
  mkdir -p $TEMP_DIR
  mkdir -p $MIGRATOR_TEMP_DIR
}

function backup_staging_site_db() {
  dump_db ${TEMP_DIR}/${DB_NAME_LOCAL}_backup_${DB_DEFAULT_CHARSET}.sql
}

function export_staging_site_pages() {
  wp_cli newspack-content-migrator export-all-staging-pages --output-dir=$MIGRATOR_TEMP_DIR
  set_var_by_previous_exit_code IS_EXPORTED_STAGING_PAGES
}

function export_staging_site_menus() {
  wp_cli newspack-content-migrator export-menus --output-dir=$MIGRATOR_TEMP_DIR
  set_var_by_previous_exit_code IS_EXPORTED_STAGING_MENUS
}

function export_staging_site_custom_css() {
  wp_cli newspack-content-migrator export-current-theme-custom-css --output-dir=$MIGRATOR_TEMP_DIR
  set_var_by_previous_exit_code IS_EXPORTED_CUSTOM_CSS
}

function export_staging_site_page_settings() {
  wp_cli newspack-content-migrator export-pages-settings --output-dir=$MIGRATOR_TEMP_DIR
  set_var_by_previous_exit_code IS_EXPORTED_PAGES_SETTINGS
}

function back_up_newspack_content_migrator_staging_table() {
  wp_cli newspack-content-migrator back-up-converter-plugin-staging-table
  set_var_by_previous_exit_code IS_BACKED_UP_STAGING_NCC_TABLE

  if [ 0 != $IS_BACKED_UP_STAGING_NCC_TABLE ]; then
    echo_ts 'content converter table not found or backed up. Continuing...'
  fi
}

function prepare_live_sql_dump_for_import() {
  echo_ts 'replacing hostnames in the Live SQL dump file...'
  replace_hostnames $LIVE_SQL_DUMP_FILE $LIVE_SQL_DUMP_FILE_REPLACED

  echo_ts 'setting `live_` table prefix to all the tables in the Live site SQL dump ...'
  sed -i "s/\`$TABLE_PREFIX/\`live_$TABLE_PREFIX/g" $LIVE_SQL_DUMP_FILE_REPLACED
}

# Replace multiple hostnames in a file using the VIP's search-replace tool.
#  - arg1: input file
#  - arg2: output file
#  - using global LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS
function replace_hostnames() {
  local SQL_IN=$1
  local SQL_OUT=$2

  i=0
  for HOSTNAME_FROM in "${!LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS[@]}"; do
    HOSTNAME_TO=${LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS[$HOSTNAME_FROM]}
  
    # We can't output the result of individual replacement to one same file, so each
    # replacement is done to a new temporary output file.
    ((i++))
    if [[ 1 == $i ]]; then
      TMP_IN_FILE=$SQL_IN
    else
      TMP_IN_FILE=$TEMP_DIR/live_replaced_$((i-1)).sql
    fi
    TMP_OUT_FILE=$TEMP_DIR/live_replaced_$i.sql
  
    echo_ts "replacing //$HOSTNAME_FROM -> //$HOSTNAME_TO..."
    cat $TMP_IN_FILE | $SEARCH_REPLACE //$HOSTNAME_FROM //$HOSTNAME_TO > $TMP_OUT_FILE
  
    # Remove previous temp TMP_IN_FILE.
    if [[ $i > 1 ]]; then
      rm $TMP_IN_FILE
    fi
  done

  # The final replaced file.
  mv $TMP_OUT_FILE $SQL_OUT
}

function import_live_sql_dump() {
  mysql -h $DB_HOST_LOCAL --default-character-set=$DB_DEFAULT_CHARSET ${DB_NAME_LOCAL} < $LIVE_SQL_DUMP_FILE_REPLACED
}

# Syncs files from the live archive.
function update_files_from_live_site() {
  rsync -r \
    --exclude=wp-content/mu-plugins \
    --exclude=wp-content/plugins \
    --exclude=wp-content/themes \
    --exclude=wp-content/index.php \
    --exclude=wp-content/advanced-cache.php \
    --exclude=wp-content/object-cache.php \
    --exclude=wp-content/upgrade \
    --exclude=wp-content/wp-config.php \
    $LIVE_FILES/wp-content \
    $HTDOCS_PATH
}

# Dumps the DB.
#	- arg1: output file (full path)
function dump_db() {
  mysqldump -h $DB_HOST_LOCAL --max_allowed_packet=512M \
    --default-character-set=$DB_DEFAULT_CHARSET \
    $DB_NAME_LOCAL \
    > $1
}

# Replaces tables defined in $IMPORT_TABLES with Live Site tables (imported with the
# `live_` table name prefix).
function replace_staging_tables_with_live_tables() {
  for TABLE in "${IMPORT_TABLES[@]}"; do
      # Add prefix `staging_` to current table.
      mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL; RENAME TABLE $TABLE_PREFIX$TABLE TO staging_$TABLE_PREFIX$TABLE;"
      # Use the live table.
      mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL; RENAME TABLE live_$TABLE_PREFIX$TABLE TO $TABLE_PREFIX$TABLE;"
  done
}

# Imports previously converted blocks contents from the `staging_ncc_wp_posts_backup`
# table.
function import_blocks_content_from_staging_site() {
  echo_ts "deleting the Newspack Content Converter plugin..."
  wp_cli plugin deactivate newspack-content-converter
  wp_cli plugin delete newspack-content-converter
  # The NCC plugin will drop the table only if 'deleted' from Dashboard, but not from CLI;
  # this needs update, but for now manually drop ncc_wp_posts table and clean the options.
  mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL; DROP TABLE IF EXISTS ncc_wp_posts; "
  mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL; DELETE FROM ${TABLE_PREFIX}options \
    WHERE option_name IN ( 'ncc-conversion_batch_size', 'ncc-conversion_max_batches', 'ncc-convert_post_statuses_csv', \
    'ncc-convert_post_types_csv', 'ncc-is_queued_conversion', 'ncc-patching_batch_size', 'ncc-patching_max_batches', \
    'ncc-is_queued_retry_failed_conversion', 'ncc-conversion_queued_batches_csv', 'ncc-retry_conversion_failed_queued_batches_csv', \
    'ncc-retry_conversion_failed_max_batches' ) ; "
  
  echo_ts "reinstalling the Newspack Content Converter Plugin..."
  wp_cli plugin install --force https://github.com/Automattic/newspack-content-converter/releases/latest/download/newspack-content-converter.zip
  wp_cli plugin activate newspack-content-converter
  
  echo_ts "updating wp_posts and NCC Plugins's table with block contents from Staging site..."
  wp_cli newspack-content-migrator import-blocks-content-from-staging-site --table-prefix=$TABLE_PREFIX
}

function clean_up_options() {
  mysql -h $DB_HOST_LOCAL -e "USE ${DB_NAME_LOCAL}; \
      DELETE FROM ${TABLE_PREFIX}options WHERE option_name IN ( 'googlesitekit_search_console_property' ) ; "
}

function drop_temp_db_tables() {
  # Get the names of all the temporary imported Live tables.
  local SQL_SELECT_TABLES_TO_DROP="SELECT GROUP_CONCAT(table_name) AS statement \
    FROM information_schema.tables \
    WHERE table_schema = '$DB_NAME_LOCAL' \
    AND table_name LIKE 'live_%' \
    AND table_name LIKE 'staging_%' ; "
  local CMD_GET_TABLES_TO_DROP="mysql -h $DB_HOST_LOCAL -sN -e \"$SQL_SELECT_TABLES_TO_DROP\""
  eval TABLES_TO_DROP_CSV=\$\($CMD_GET_TABLES_TO_DROP\)
  # Drop all these temporary Live tables.
  mysql -h $DB_HOST_LOCAL -e "USE ${DB_NAME_LOCAL}; DROP TABLES ${TABLES_TO_DROP_CSV}; "
}

function set_public_content_file_permissions() {
  find "$HTDOCS_PATH/wp-content" -type d -print0 | xargs -0 chmod 755
  find "$HTDOCS_PATH/wp-content" -type f -print0 | xargs -0 chmod 644
}

# Checks if SEARCH_REPLACE is set, an if it isn't it downloads the search-replace
# tool and sets its path.
function validate_and_download_vip_search_replace() {
  # If it's set, use it
  if [ "" -ne "$SEARCH_REPLACE" ]; then
    chmod 755 $SEARCH_REPLACE
    return
  fi

  echo_ts 'downloading the search-replace bin...'
  local ARCHIVE="$TEMP_DIR/search-replace.gz"
  SEARCH_REPLACE=$TEMP_DIR/search-replace
  rm -f $ARCHIVE
  curl -Ls https://github.com/Automattic/go-search-replace/releases/download/0.0.5/go-search-replace_linux_amd64.gz \
    -o "$ARCHIVE" && \
  gzip -f -d $ARCHIVE && \
  chmod 755 $SEARCH_REPLACE

  if [ ! -f $SEARCH_REPLACE ]; then
    echo_ts_red 'ERROR: search-replace bin could not be downloaded. You can provide the bin yourself and set its path in the SEARCH_REPLACE var.'
    exit
  fi
}

# Checks the DB_NAME_LOCAL, an if it is empty, it fetches it from the Atomic user name.
function validate_and_set_db_name() {
  if [ "" = "$DB_NAME_LOCAL" ]; then
    DB_NAME_LOCAL=$( whoami )
  fi
}

function validate_db_connection() {
  if ! mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL;"; then
    echo_ts_red 'ERROR: DB connection not working. Check DB config params.'
    exit
  fi
}

function validate_db_default_charset() {
  if [ 'utf8mb4' != $DB_DEFAULT_CHARSET ] && [ 'utf8' != $DB_DEFAULT_CHARSET ] && [ 'latin1' != $DB_DEFAULT_CHARSET ]; then
    echo_ts_red 'ERROR: DB_DEFAULT_CHARSET does not have a correct value.'
    exit
  fi
}

function validate_table_prefix() {
  local SQL_COUNT_TABLES_W_PREFIX="SELECT COUNT(*) table_name \
    FROM information_schema.tables \
    WHERE table_type='BASE TABLE' \
    AND table_schema='$DB_NAME_LOCAL' \
    AND table_name LIKE '$TABLE_PREFIX%'; "
  local CMD_COUNT_TABLES="mysql -h $DB_HOST_LOCAL -sN -e \"$SQL_COUNT_TABLES_W_PREFIX\""
  eval COUNT=\$\($CMD_COUNT_TABLES\)

  if [ 0 = $COUNT ]; then
    echo_ts_red "ERROR: no tables with prefix $TABLE_PREFIX found; check the TABLE_PREFIX variable."
    exit
  fi
}

function validate_live_files() {
  if [ ! -d $LIVE_FILES ] || [ ! -d $LIVE_FILES/wp-content ]; then
    echo_ts_red "ERROR: wp-content folder not found in $LIVE_FILES. Check the LIVE_FILES param."
    exit
  fi
}

function validate_live_sql_dump_file() {
  if [ ! -f $LIVE_SQL_DUMP_FILE ]; then
    echo_ts_red "ERROR: Live SQL DUMP file not found at $LIVE_SQL_DUMP_FILE. Check LIVE_SQL_DUMP_FILE param."
    exit
  fi
}

function validate_live_db_hostname_replacements() {
  if [ ${#LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS[@]} -eq 0 ]; then
    echo_ts_red "ERROR: hostname replacements not defined in LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS variable."
    exit
  fi
}

# Echoes a green string with a timestamp.
# - arg1: echo string
function echo_ts() {
  GREEN=`tput setaf 2; tput setab 0`
  RESET_COLOR=`tput sgr0`
  echo -e "${GREEN}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}

# Same like echo_ts, only uses red color.
# - arg1: echo string
function echo_ts_red() {
  RED=`tput setaf 1; tput setab 0`
  RESET_COLOR=`tput sgr0`
  echo -e "${RED}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}

# Same like echo_ts, only uses color yellow.
# - arg1: echo string
function echo_ts_yellow() {
  YELLOW=`tput setaf 3; tput setab 0`
  RESET_COLOR=`tput sgr0`
  echo -e "${YELLOW}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}
