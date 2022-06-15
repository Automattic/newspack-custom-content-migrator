<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class AttachmentsMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator attachments-get-ids-by-years',
			[ $this, 'convert_get_atts_by_years' ],
		);
	}

	/**
	 * Gets a list of attachment IDs by years for those attachments which have files on local in (/wp-content/uploads).
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative Arguments
	 *
	 * @return void
	 * @throws WP_CLI\ExitException
	 */
	public function convert_get_atts_by_years( $pos_args, $assoc_args ) {
		global $wpdb;
		$ids_years  = [];
		$ids_failed = [];

		$att_ids = $wpdb->get_results( "select ID from {$wpdb->posts} where post_type = 'attachment' ; ", ARRAY_A );
		foreach ( $att_ids as $key_att_id => $att_id_row ) {
			$att_id = $att_id_row['ID'];
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_att_id + 1, count( $att_ids ), $att_id ) );

			// Check if this attachment is in local wp-content/uploads.
			$url                        = wp_get_attachment_url( $att_id );
			$url_pathinfo               = pathinfo( $url );
			$dirname                    = $url_pathinfo['dirname'];
			$pathmarket                 = '/wp-content/uploads/';
			$pos_pathmarker             = strpos( $dirname, $pathmarket );
			$dirname_remainder          = substr( $dirname, $pos_pathmarker + strlen( $pathmarket ) );
			$dirname_remainder_exploded = explode( '/', $dirname_remainder );

			// Group by years folders.
			$year = isset( $dirname_remainder_exploded[0] ) && is_numeric( $dirname_remainder_exploded[0] ) && ( 4 === strlen( $dirname_remainder_exploded[0] ) ) ? (int) $dirname_remainder_exploded[0] : null;
			if ( is_null( $year ) ) {
				$ids_failed[ $att_id ] = $url;
			} else {
				$ids_years[ $year ][] = $att_id;
			}
		}

		// Save {$year}.txt file.
		foreach ( array_keys( $ids_years ) as $year ) {
			$att_ids = $ids_years[ $year ];
			$file    = $year . '.txt';
			file_put_contents( $file, implode( ' ', $att_ids ) . ' ' );
		}

		// Save 0_failed_ids.txt file for files which may not be on local.
		foreach ( $ids_failed as $att_id => $url ) {
			$file = '0_failed_ids.txt';
			file_put_contents( $file, $att_id . ' ' . $url . "\n", FILE_APPEND );
		}

		WP_CLI::log( sprintf( "> created {year}.txt's and %s", $file ) );
	}
}
