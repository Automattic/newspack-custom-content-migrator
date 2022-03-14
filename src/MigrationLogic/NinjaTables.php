<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \WP_CLI;
use \NinjaTablesAdmin;
use \NinjaTables\Classes\ArrayHelper;

/**
 * NinjaTables Plugin Migrator Logic.
 */
class NinjaTables {
	/**
	 * @var null|NinjaTablesAdmin
	 */
	public $ninja_tables_admin;

	/**
	 * NinjaTables constructor.
	 */
	public function __construct() {
		$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

		$ninja_tables                = $plugin_path . '/ninja-tables/ninja-tables.php';
		$ninja_tables_class          = $plugin_path . '/ninja-tables/includes/NinjaTableClass.php';
		$ninja_tables_admin          = $plugin_path . '/ninja-tables/admin/NinjaTablesAdmin.php';
		$included_ninja_tables       = is_file( $ninja_tables ) && include_once $ninja_tables;
		$included_ninja_tables_class = is_file( $ninja_tables_class ) && include_once $ninja_tables_class;
		$included_ninja_tables_admin = is_file( $ninja_tables_admin ) && include_once $ninja_tables_admin;

		if ( false === $included_ninja_tables || false === $included_ninja_tables_class || false === $included_ninja_tables_admin ) {
			// Ninja Tables is a dependency, and will have to be installed before the public functions/commands can be used.
			return;
		}

		$this->ninja_tables_admin = new NinjaTablesAdmin();
	}

	/**
	 * Export data function, based on exportData method from the NinjaTables plugin.
	 *
	 * @param int    $table_id Table to export ID.
	 * @param string $file_path File path where to export the data.
	 * @param string $format Data format to export to. It can be CSV or JSON.
	 */
	public function export_data( $table_id, $file_path, $format ) {
		$table_columns  = \ninja_table_get_table_columns( $table_id, 'admin' );
		$table_settings = \ninja_table_get_table_settings( $table_id, 'admin' );

		if ( 'csv' === $format ) {
			$sorting_type  = ArrayHelper::get( $table_settings, 'sorting_type', 'by_created_at' );
			$table_columns = \ninja_table_get_table_columns( $table_id, 'admin' );
			$data          = \ninjaTablesGetTablesDataByID( $table_id, $table_columns, $sorting_type, true );
			$header        = array();

			foreach ( $table_columns as $item ) {
				$header[ $item['key'] ] = $item['name'];
			}

			$export_data = array();

			foreach ( $data as $item ) {
				$temp = array();
				foreach ( $header as $accessor => $name ) {
					$value = ArrayHelper::get( $item, $accessor );
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					$temp[] = $value;
				}
				array_push( $export_data, $temp );
			}
			$this->export_as_csv( $export_data, $file_path );
		} elseif ( $format == 'json' ) {
			$table = get_post( $table_id );

			$data_provider = \ninja_table_get_data_provider( $table_id );
			$rows          = array();
			if ( 'default' === $data_provider ) {
				$raw_rows = \ninja_tables_DbTable()
					->select( array( 'position', 'owner_id', 'attribute', 'value', 'settings', 'created_at', 'updated_at' ) )
					->where( 'table_id', $table_id )
					->get();
				foreach ( $raw_rows as $row ) {
					$row->value = json_decode( $row->value, true );
					$rows[]     = $row;
				}
			}

			$matas    = get_post_meta( $table_id );
			$all_meta = array();

			$excluded_meta_keys = array(
				'_ninja_table_cache_object',
				'_ninja_table_cache_html',
				'_external_cached_data',
				'_last_external_cached_time',
				'_last_edited_by',
				'_last_edited_time',
				'__ninja_cached_table_html',
			);

			foreach ( $matas as $meta_key => $meta_value ) {
				if ( ! in_array( $meta_key, $excluded_meta_keys, true ) ) {
					if ( isset( $meta_value[0] ) ) {
						$meta_value            = maybe_unserialize( $meta_value[0] );
						$all_meta[ $meta_key ] = $meta_value;
					}
				}
			}

			$export_data = array(
				'post'          => $table,
				'columns'       => $table_columns,
				'settings'      => $table_settings,
				'data_provider' => $data_provider,
				'metas'         => $all_meta,
				'rows'          => array(),
				'original_rows' => $rows,
			);

			file_put_contents( $file_path, wp_json_encode( $export_data ) );
		} else {
			WP_CLI::error( sprintf( 'The %s export format is not supported, please choose either csv or json.', $format ) );
		}
	}

	/**
	 * Fill file with CSV data from given array.
	 *
	 * @param mixed[] $data Data to save in the file as CSV.
	 * @param string  $file_path File path where to save the CSV file.
	 */
	private function export_as_csv( $data, $file_path ) {
		$f = fopen( $file_path, 'w' );

		foreach ( $data as $row ) {
			fputcsv( $f, $row );
		}

		fclose( $f );
	}
}
