#!/bin/bash

# A more convenient way to execute WP CLI.
function wp_cli() {
  eval $WP_CLI_BIN --path=$WP_CLI_PATH $@
}

# Based on the last previously executed command's exit code, sets a custom variable's
# value to 1 if the exit code was 0, or 0 otherwise.
# If this seems confusing, here's the logic behind it: shell returns 0 exit code for
# success, and the vars we're setting here are logical success variables represented
# as 1 or 0.
#  - arg1: your custom name variable to set to 1 (success) or 0 (otherwise).
function set_var_by_previous_exit_code() {
  # If exit code was 0, then this is a success, so set the var to 1.
  if [ 0 = $? ]; then
    eval $1'=1'
  else
    eval $1'=0'
  fi
}

# Checks if Plugin is active, and activates it if not.
function activate_this_plugin() {
  local PLUGIN_STATUS=$(wp_cli plugin list | grep "$THIS_PLUGINS_NAME" | awk '{print $2}')
  if [[ 'active' = $PLUGIN_STATUS ]]; then
    return
  fi

  echo_ts 'plugin inactive, now activating...'
  wp_cli plugin activate $THIS_PLUGINS_NAME
  PLUGIN_STATUS=$(wp_cli plugin list | grep "$THIS_PLUGINS_NAME" | awk '{print $2}')
  if [[ 'active' != $PLUGIN_STATUS ]]; then
    echo_ts_red "ERROR: could not activate $THIS_PLUGINS_NAME Plugin. Make sure it's active then try again."
    exit
  fi
}

# If the SEARCH_REPLACE file exists it will be used, or else will be downloaded it from GH.
function get_vip_search_replace() {
  # Location of search-replace binary.
  SEARCH_REPLACE=$TEMP_DIR/search-replace

  if [ -f $SEARCH_REPLACE ]; then
    chmod 755 $SEARCH_REPLACE
    echo_ts "VIP search-replace found at $SEARCH_REPLACE and will be used"
    return
  else
    echo_ts 'downloading VIP search-replace...'
    local ARCHIVE="$TEMP_DIR/search-replace.gz"
    rm -f $ARCHIVE
    curl -Ls https://github.com/Automattic/go-search-replace/releases/download/0.0.5/go-search-replace_linux_amd64.gz \
      -o "$ARCHIVE" && \
    gzip -f -d $ARCHIVE && \
    chmod 755 $SEARCH_REPLACE
    if [ ! -f $SEARCH_REPLACE ]; then
      echo_ts_red "ERROR: search-replace bin could not be downloaded. You can provide the bin yourself and save it as $SEARCH_REPLACE"
      exit
    fi
  fi
}

# Sets config variables which can be automatically set.
function set_config() {
  THIS_PLUGINS_NAME='newspack-custom-content-migrator'
  # Tables to import from the Live Site, given here without the table prefix.
  IMPORT_TABLES=(commentmeta comments links postmeta posts term_relationships term_taxonomy termmeta terms usermeta users)
  # If left empty, the DB_NAME_LOCAL will be fetched from the user name, as the Atomic sites' convention.
  DB_NAME_LOCAL=""
  # Atomic DB host.
  DB_HOST_LOCAL=127.0.0.1
  # Path to the public folder. No ending slash.
  HTDOCS_PATH="/home/"$(whoami)"/htdocs"
  # Atomic WP CLI params.
  WP_CLI_BIN=/usr/local/bin/wp-cli
  WP_CLI_PATH="/home/"$(whoami)"/htdocs/__wp__/"

  # Migrators' output dir.
  TEMP_DIR_MIGRATOR=$TEMP_DIR/migration_exports
  # Jetpack Rewind temp dir.
  TEMP_DIR_JETPACK=$TEMP_DIR/jetpack_archive
  # Another Jetpack temp dir, where the archive initially gets extracted to.
  TEMP_DIR_JETPACK_UNZIP=$TEMP_DIR_JETPACK/unzip
  # Name of the Live SQL dump file to save after the hostname replacements are made.
  LIVE_SQL_DUMP_FILE_REPLACED=$TEMP_DIR/live_db_hostnames_replaced.sql

  # If DB name not provided sets it from the Atomic user name.
  if [ "" = "$DB_NAME_LOCAL" ]; then
    DB_NAME_LOCAL=$( whoami )
  fi

  # Set array with hostname replacements for live SQL dump file before importing it into local.
  declare -gA LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS
  LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS[$LIVE_SITE_HOSTNAME]=$STAGING_SITE_HOSTNAME
  LIVE_SQL_DUMP_HOSTNAME_REPLACEMENTS['www.'$LIVE_SITE_HOSTNAME]=$STAGING_SITE_HOSTNAME
}

function validate_all_params() {
  validate_db_connection
  validate_db_charset
  validate_table_prefix
  validate_live_db_hostname_replacements
}

function purge_temp_folders() {
  rm -rf $TEMP_DIR || true
  mkdir -p $TEMP_DIR
  mkdir -p $TEMP_DIR_MIGRATOR
  mkdir -p $TEMP_DIR_JETPACK
  mkdir -p $TEMP_DIR_JETPACK_UNZIP
}

# Extracts the Jetpack archive, and prepares its contents for import.
function unpack_jetpack_archive() {
  echo_ts 'extracting archive...'
  jetpack_archive_extract

  echo_ts "preparing the SQL dump..."
  jetpack_archive_prepare_live_sql_dump

  echo_ts 'preparing the files...'
  jetpack_archive_prepare_files_for_sync
}

function jetpack_archive_extract() {
  mkdir -p $TEMP_DIR_JETPACK_UNZIP
  eval "tar xzf $LIVE_JETPACK_ARCHIVE -C $TEMP_DIR_JETPACK_UNZIP > /dev/null 2>&1"
  if [ 0 -ne $? ]; then
    echo_ts_red "error extracting Jetpack Rewind archive $LIVE_JETPACK_ARCHIVE"
    exit
  fi
}

# Prepares the live SQL dump from the Jetpack export, sets its location to LIVE_SQL_DUMP_FILE
function jetpack_archive_prepare_live_sql_dump() {
  LIVE_SQL_DUMP_FILE=$TEMP_DIR_JETPACK/sql/live.sql

  # First get the list of all individual SQL table dump files from the Jetpack SQL export.
  local LIST_OF_TABLENAMES=""
  for KEY in "${!IMPORT_TABLES[@]}"; do
    local TABLE_FILE_FULL_PATH=$TEMP_DIR_JETPACK_UNZIP/sql/$TABLE_PREFIX${IMPORT_TABLES[KEY]}.sql
    LIST_OF_TABLENAMES="$LIST_OF_TABLENAMES $TABLE_FILE_FULL_PATH"

    # Also check if table dump exists in the Jetpack export.
    if [ ! -f $TABLE_FILE_FULL_PATH ]; then
      echo_ts_red "ERROR: table SQL dump not found $TABLE_FILE_FULL_PATH."
      exit
    fi
  done

  # Export all table dumps into the LIVE_SQL_DUMP_FILE file
  mkdir -p $TEMP_DIR_JETPACK/sql
  cat $LIST_OF_TABLENAMES > $LIVE_SQL_DUMP_FILE

  echo_ts "created $LIVE_SQL_DUMP_FILE"
}

# Prepares files for import from the Jetpack export, sets their location to LIVE_HTDOCS_FILES
function jetpack_archive_prepare_files_for_sync() {
  LIVE_HTDOCS_FILES=$TEMP_DIR_JETPACK/files

  # Only sync wp-content/uploads, from the LIVE_HTDOCS_FILES
  mkdir -p $LIVE_HTDOCS_FILES/wp-content
  mv $TEMP_DIR_JETPACK_UNZIP/wp-content/uploads $LIVE_HTDOCS_FILES/wp-content
  rm -rf $TEMP_DIR_JETPACK_UNZIP

  echo_ts "files for syncing stored to $LIVE_HTDOCS_FILES"
}

function prepare_live_sql_dump_for_import() {
  echo_ts 'replacing hostnames in Live SQL dump file...'
  replace_hostnames $LIVE_SQL_DUMP_FILE $LIVE_SQL_DUMP_FILE_REPLACED

  echo_ts 'setting `live_` table prefix to tables in Live site SQL dump...'
  sed -i "s/\`$TABLE_PREFIX/\`live_$TABLE_PREFIX/g" $LIVE_SQL_DUMP_FILE_REPLACED
  echo_ts 'created a live db dump with `live_` prefixes and replaced hostnames, '"$LIVE_SQL_DUMP_FILE_REPLACED..."
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

    echo_ts "- replacing //$HOSTNAME_FROM -> //$HOSTNAME_TO ..."
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
  # First drop the `live_wp_` tables from Staging, if any, before importing them.
  local DROP_LIVE_TABLES=""
  for KEY in "${!IMPORT_TABLES[@]}"; do
    DROP_LIVE_TABLES="$DROP_LIVE_TABLES drop table if exists live_$TABLE_PREFIX${IMPORT_TABLES[KEY]} ;"
  done
  mysql -h $DB_HOST_LOCAL --default-character-set=$DB_CHARSET ${DB_NAME_LOCAL} -e "$DROP_LIVE_TABLES"

  # Import the new live dump.
  mysql -h $DB_HOST_LOCAL --default-character-set=$DB_CHARSET ${DB_NAME_LOCAL} < $LIVE_SQL_DUMP_FILE_REPLACED
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
    $LIVE_HTDOCS_FILES/wp-content \
    $HTDOCS_PATH
}

# Dumps the DB.
#	- arg1: output file (full path)
function dump_db() {
  mysqldump -h $DB_HOST_LOCAL --max_allowed_packet=512M \
    --default-character-set=$DB_CHARSET \
    $DB_NAME_LOCAL \
    > $1
}

function clean_up_options() {
  mysql -h $DB_HOST_LOCAL -e "USE ${DB_NAME_LOCAL}; \
      DELETE FROM ${TABLE_PREFIX}options WHERE option_name LIKE '%googlesitekit%' ; "
}

function drop_temp_db_tables() {
  # Get the names of all the temporary imported Live tables.
  local SQL_SELECT_TABLES_TO_DROP="SELECT GROUP_CONCAT(table_name) AS statement \
    FROM information_schema.tables \
    WHERE table_schema = '$DB_NAME_LOCAL' \
    AND ( table_name LIKE 'live_%' \
        OR table_name LIKE 'staging_%' \
    ) ; "
  local CMD_GET_TABLES_TO_DROP="mysql -h $DB_HOST_LOCAL -sN -e \"$SQL_SELECT_TABLES_TO_DROP\""
  eval TABLES_TO_DROP_CSV=\$\($CMD_GET_TABLES_TO_DROP\)
  # Drop all these temporary Live tables.
  mysql -h $DB_HOST_LOCAL -e "USE ${DB_NAME_LOCAL}; DROP TABLES ${TABLES_TO_DROP_CSV}; "
}

function set_public_content_file_permissions() {
  find "$HTDOCS_PATH/wp-content" -type d -print0 | xargs -0 chmod 755 > /dev/null 2>&1
  find "$HTDOCS_PATH/wp-content" -type f -print0 | xargs -0 chmod 644 > /dev/null 2>&1
}

function validate_db_connection() {
  if ! mysql -h $DB_HOST_LOCAL -e "USE $DB_NAME_LOCAL;"; then
    echo_ts_red 'ERROR: DB connection not working. Check DB config params.'
    exit
  fi
}

function validate_db_charset() {
  if [ 'utf8mb4' != $DB_CHARSET ] && [ 'utf8' != $DB_CHARSET ] && [ 'latin1' != $DB_CHARSET ]; then
    echo_ts_red 'ERROR: DB_CHARSET does not have a correct value.'
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
  if [ ! -d $LIVE_HTDOCS_FILES ] || [ ! -d $LIVE_HTDOCS_FILES/wp-content ]; then
    echo_ts_red "ERROR: wp-content folder not found in $LIVE_HTDOCS_FILES. Check the LIVE_HTDOCS_FILES param."
    exit
  fi
}

function validate_live_sql_dump_file() {
  if [ ! -f $LIVE_SQL_DUMP_FILE ]; then
    echo_ts_red "ERROR: Live SQL DUMP file not found at $LIVE_SQL_DUMP_FILE. Check the LIVE_SQL_DUMP_FILE param."
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
  GREEN=`tput -T xterm-256color setaf 2; tput -T xterm-256color setab 0`
  RESET_COLOR=`tput -T xterm-256color sgr0`
  echo -e "${GREEN}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}

# Same like echo_ts, only uses red color.
# - arg1: echo string
function echo_ts_red() {
  RED=`tput -T xterm-256color setaf 1; tput -T xterm-256color setab 0`
  RESET_COLOR=`tput -T xterm-256color sgr0`
  echo -e "${RED}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}

# Same like echo_ts, only uses color yellow.
# - arg1: echo string
function echo_ts_yellow() {
  YELLOW=`tput -T xterm-256color setaf 3; tput -T xterm-256color setab 0`
  RESET_COLOR=`tput -T xterm-256color sgr0`
  echo -e "${YELLOW}- [`date +%H:%M:%S`] $@ ${RESET_COLOR}"
}
