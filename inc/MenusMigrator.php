<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Exports and imports menus and associated content.
 */
class MenusMigrator implements InterfaceMigrator {

	/**
	 * @var string Menu file name.
	 */
	const MENU_EXPORT_FILE = 'newspack-menu-export.json';

	/**
	 * @var string Posts file name.
	 */
	const POSTS_EXPORT_FILE = 'newspack-menu-export-posts.xml';

	/**
	 * @var null|MenusMigrator Instance.
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
	 * @return MenusMigrator|null
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
			],
		] );

		WP_CLI::add_command( 'newspack-live-migrate import-menus', array( $this, 'cmd_import_menus' ), [
			'shortdesc' => 'Imports custom menus and new pages from the export JSON file.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'input-dir',
					'description' => 'Exported Menus JSON and XML files directory.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for export-menus command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_menus( $args, $assoc_args ) {
		$export_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;

		WP_CLI::line( sprintf( 'Exporting active menus' ) );
		$this->export_menus( $export_dir );

		wp_cache_flush();
	}

	/**
	 * Export the site menus into two files: one for the menu info, one for the posts linked in the menu.
	 *
	 * @param string $export_dir
	 */
	public function export_menus( $export_dir ) {
		// Get all menus info gathered together.
		$menu_ids = array_unique( get_nav_menu_locations() );
		$locations = array_flip( get_nav_menu_locations() );
		$menus = [];

		foreach ( $menu_ids as $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( ! $menu ) {
				continue;
			}

			$menu_data = [
				'menu'       => $menu,
				'menu_items' => [],
				'location'   => $locations[ $menu_id ],
			];

			$menu_items = wp_get_nav_menu_items( $menu_id );
			foreach ( $menu_items as $menu_item ) {
				$menu_data['menu_items'][] = wp_setup_nav_menu_item( $menu_item );
			}

			$menus[] = $menu_data;
		}

		// Export menus and menu items.
		$menu_file = $export_dir . '/' . self::MENU_EXPORT_FILE;
		$open_menu_file = fopen( $menu_file, 'w' );
		if ( ! $open_menu_file ) {
			WP_CLI::error( 'Error creating or opening output file: ' . $menu_file );
		}
		$write = fputs( $open_menu_file, json_encode( $menus ) );
		if ( ! $write ) {
			WP_CLI::error( 'Error writing to output file: ' . $menu_file );
		}
		fclose( $open_menu_file );

		// Export posts linked in menu items.
		$post_ids = [];
		foreach ( $menus as $menu ) {
			foreach ( $menu['menu_items'] as $menu_item ) {
				if ( 'post' !== $menu_item->object && 'page' !== $menu_item->object ) {
					continue;
				}

				$post_ids[] = $menu_item->object_id;

				// Add a meta value so the import can correctly associate new and existing posts.
				update_post_meta( $menu_item->object_id, 'newspack_menu_original_post_id', $menu_item->object_id );
			}
		}
		$output = WP_CLI::runcommand( 'export --dir="' . $export_dir . '" --post__in="' . implode( ' ', $post_ids ) . '" --with_attachments --filename_format="' . self::POSTS_EXPORT_FILE . '"' );
		
		WP_CLI::line( $output );
		WP_CLI::line( 'Completed menu export: ' . $menu_file );
	}

	/**
	 * Callable for import-menus command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_menus( $args, $assoc_args ) {
		$directory = isset( $assoc_args['input-dir'] ) ? $assoc_args['input-dir'] : null;

		if ( ! $directory ) {
			WP_CLI::error( 'Invalid directory' );
		}

		WP_CLI::line( sprintf( 'Importing menus from: ' . $directory ) );
		$this->import_menus( $directory . '/' . self::MENU_EXPORT_FILE, $directory . '/' . self::POSTS_EXPORT_FILE);
		wp_cache_flush();
	}

	/**
	 * Import menus and any associated posts.
	 *
	 * @param string $menu_file Full path to menu json file.
	 * @param string $posts_file Full path to posts xml file.
	 */
	public function import_menus( $menu_file, $posts_file ) {
		global $wpdb;

		$menu_read = file_get_contents( $menu_file );
		if ( empty( $menu_read ) ) {
			WP_CLI::error( 'Error reading from menu file' );
		}

		$output = WP_CLI::runcommand( 'import ' . $posts_file . ' --authors=create' );
		WP_CLI::line( $output );

		$menu_item_parent_mapping = [];
		$menu_item_object_mapping = [];

		// Populate object mapping.
		$raw_mapped_ids = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key = 'newspack_menu_original_post_id'", ARRAY_A );
		foreach ( $raw_mapped_ids as $raw_id ) {
			$menu_item_object_mapping[ $raw_id['meta_value'] ] = $raw_id['post_id'];
		}

		$menus = json_decode( $menu_read );
		foreach ( $menus as $menu ) {

			// Get the menu. It will have been created by the XML import if needed.
			$menu_object = wp_get_nav_menu_object( $menu->menu->slug );
			if ( ! $menu_object ) {
				WP_CLI::error( 'Error rebuilding menu: ' . $menu->menu->slug );
			}
			$menu_id = $menu_object->term_id;

			// Delete existing items in menu.
			$existing_items = wp_get_nav_menu_items( $menu_id );
			foreach ( $existing_items as $existing_item ) {
				wp_delete_post( $existing_item->ID, true );
			}

			// Create new menu items.
			foreach ( $menu->menu_items as $menu_item ) {
				// Map old post references to new ones if needed.
				if ( 'post_type' === $menu_item->type && isset( $menu_item_object_mapping[ $menu_item->object_id ] ) ) {
					$menu_item_object_id = $menu_item_object_mapping[ $menu_item->object_id ];
				} else {
					$menu_item_object_id = $menu_item->object_id;
				}

				// Don't reference old menu items as parents.
				$menu_item_parent = $menu_item->menu_item_parent;
				if ( '0' !== $menu_item_parent && isset( $menu_item_parent_mapping[ $menu_item_parent ] ) ) {
					$menu_item_parent = $menu_item_parent_mapping[ $menu_item_parent ];
				}

				$menu_item_args = [
					'menu-item-object-id'   => $menu_item_object_id,
					'menu-item-object'      => $menu_item->object,
					'menu-item-parent-id'   => $menu_item_parent,
					'menu-item-position'    => $menu_item->menu_order,
					'menu-item-type'        => $menu_item->type,
					'menu-item-title'       => $menu_item->title,
					'menu-item-url'         => $menu_item->url,
					'menu-item-description' => $menu_item->description,
					'menu-item-attr-title'  => $menu_item->attr_title,
					'menu-item-target'      => $menu_item->target,
					'menu-item-classes'     => implode( ' ', $menu_item->classes ),
					'menu-item-xfn'         => $menu_item->xfn,
					'menu-item-status'      => 'publish',
				];
				$menu_item_id = wp_update_nav_menu_item( $menu_id, 0, $menu_item_args );
				if ( ! is_wp_error( $menu_item_id ) ) {
					$menu_item_parent_mapping[ $menu_item->ID ] = $menu_item_id;
				}
			}

			// Set menus where possible.
			$valid_locations = get_registered_nav_menus();
			$set_menus       = get_theme_mod( 'nav_menu_locations', [] );
			if ( isset( $valid_locations[ $menu->location ] ) ) {
				$set_menus[ $menu->location ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $set_menus );
			}
		}

		WP_CLI::line( 'Completed menu import' );
	}
}
