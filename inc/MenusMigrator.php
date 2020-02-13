<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

class MenusMigrator implements InterfaceMigrator {

	/**
	 * @var null|PostsMigrator Instance.
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
	 * @return PostsMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-live-migrate export-menus', array( $this, 'cmd_export_menus' ), [
			'shortdesc' => 'Exports menu elements of the staging site and associated pages when needed.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'output-dir',
					'description' => 'Output directory full path (no ending slash).',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'file-export-menus',
					'description' => 'Menu export XML filename.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );

		WP_CLI::add_command( 'newspack-live-migrate import-menus', array( $this, 'cmd_import_menus' ), [
			'shortdesc' => 'Imports custom menus and new pages from the export XML file.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'dir',
					'description' => 'Directory with exported resources, full path (no ending slash).',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'hostname-export',
					'description' => "Hostname of the site where the export was performed.",
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'hostname-import',
					'description' => "Hostname of the site where the import is being performed (this).",
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'file-menus',
					'description' => 'Exported Menus XML file.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for export-menus command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_menus( $args, $assoc_args ) {
		$export_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		$file_output_menus = isset( $assoc_args[ 'file-export-menus' ] ) ? $assoc_args[ 'file-export-menus' ] : null;

		WP_CLI::line( sprintf( 'Exporting active menus' ) );
		$this->export_menus( $export_dir, $file_output_menus );

		wp_cache_flush();
	}

	public function export_menus( $export_dir, $file_output_menus ) {
		// Get all menus info gathered together.
		$menu_ids = array_unique( get_nav_menu_locations() );
		$menus = [];
		foreach ( $menu_ids as $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( ! $menu ) {
				continue;
			}

			$menu_data = [
				'menu' => $menu,
				'menu_items' => [],
			];

			$menu_items = wp_get_nav_menu_items( $menu_id );
			foreach ( $menu_items as $menu_item ) {
				$menu_data['menu_items'][] = wp_setup_nav_menu_item( $menu_item );
			}

			$menus[] = $menu_data;
		}

		// Output testing
		// @todo output to file
		foreach ( $menus as $menu ) {
			echo "###########\n";
			echo $menu['menu']->name . "\n";
			foreach ( $menu['menu_items'] as $menu_item ) {
				echo '-' . $menu_item->title . ' (' . $menu_item->object . ' - ' . $menu_item->ID . ")\n";
			}
		}

		// types: 'page', 'post', 'custom', 'category', all others skip export

		// export menus
		// export menu items
		// export associated posts/pages

		//var_dump( $menus );
		WP_CLI::error("TODO");
	}

	/**
	 * Callable for import-menus command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_menus( $args, $assoc_args ) {
/*
		$dir = isset( $assoc_args[ 'dir' ] ) ? $assoc_args[ 'dir' ] : null;
		$file_mapping_csv = isset( $assoc_args[ 'mapping-csv-file' ] ) ? $assoc_args[ 'mapping-csv-file' ] : null;
		$file_posts = isset( $assoc_args[ 'file-posts' ] ) ? $assoc_args[ 'file-posts' ] : null;
		$hostname_export = isset( $assoc_args[ 'hostname-export' ] ) ? $assoc_args[ 'hostname-export' ] : null;
		$hostname_import = isset( $assoc_args[ 'hostname-import' ] ) ? $assoc_args[ 'hostname-import' ] : null;

		if ( is_null( $dir ) || ! file_exists( $dir ) ) {
			WP_CLI::error( 'Invalid dir.' );
		}
		if ( is_null( $file_mapping_csv ) || ! file_exists( $file_mapping_csv ) ) {
			WP_CLI::error( "Invalid mapping.csv file, which is used by the WP import command's authors option (see https://developer.wordpress.org/cli/commands/import/)." );
		}
		if ( is_null( $file_posts ) || ! file_exists( $dir . '/' . $file_posts ) ) {
			WP_CLI::error( 'Invalid posts file.' );
		}
		if ( is_null( $hostname_export ) ) {
			WP_CLI::error( 'Invalid hostname of the export site.' );
		}
		if ( is_null( $hostname_import ) ) {
			WP_CLI::error( 'Invalid hostname of the the current site where import is being performed.' );
		}

		WP_CLI::line( 'Importing posts...' );
		$this->import_posts( $dir, $file_posts, $file_mapping_csv );

		wp_cache_flush();*/
	}

	/**
	 * @param $dir
	 * @param $file_posts
	 * @param $file_mapping_csv
	 *
	 * @return mixed
	 */
	public function import_posts( $dir, $file_posts, $file_mapping_csv ) {
		$options = [
			'return'     => true,
			// 'parse'      => 'json',
		];
		$output = WP_CLI::runcommand( "import $dir/$file_posts --authors=$file_mapping_csv", $options );

		return $output;
	}

}
