<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class NextgenGalleryMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var array SELECT * FROM `wp_ngg_gallery` table in ARRAY_A format.
	 */
	private $galleries_rows;

	/**
	 * Sets up Co-Authors Plus plugin dependencies.
	 *
	 * @return InterfaceMigrator|null
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
		WP_CLI::add_command( 'newspack-content-migrator nextgen-gallery-to-gutenberg-gallery-blocks',
			[ $this, 'cmd_nextgen_galleries_to_gutenberg_gallery_blocks' ],
			[
				'shortdesc' => 'Import NextGen images to Media Library, and converts NextGen Gallery Blocks to Gutenberg Gallery blocks.',
				// 'synopsis'  => [
				// 	[
				// 		'type'        => 'assoc',
				// 		'name'        => 'unset-author-tags',
				// 		'description' => 'If used, will unset these author tags from the posts.',
				// 		'optional'    => true,
				// 		'repeating'   => false,
				// 	],
				// ],
			]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nextgen_galleries_to_gutenberg_gallery_blocks( $args, $assoc_args ) {
		global $wpdb;

		$this->galleries_rows = $wpdb->get_results( " select * from {$wpdb->prefix}ngg_gallery ; ", ARRAY_A );

		$ngg_options = get_option( 'ngg_options' );

		$this->import_ngg_images_to_media_library( $ngg_options );

		// Loop through Posts, and find NextGen Gallery shortcodes and blocks.

		// Echo: -- remove NextGen image folder.
	}

	/**
	 * @param array $ngg_options NGG Options value.
	 */
	public function import_ngg_images_to_media_library( $ngg_options ) {
		global $wpdb;

		$gallery_path = ABSPATH . $ngg_options[ 'gallerypath' ];

		// Import NGG images.
		$images_rows = $wpdb->get_results( " select * from {$wpdb->prefix}ngg_pictures ; ", ARRAY_A );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing images...', count( $images_rows ) );
		foreach ( $images_rows as $key_image_row => $image_row ) {
			$progress->tick();

			// Img info.
			$filename = $image_row[ 'filename' ];
			$description = $image_row[ 'description' ];
			$alt = $image_row[ 'alttext' ];

			// Gallery and path info.
			$gallery_row = $this->get_gallery_row_by_gid( $image_row[ 'galleryid' ] );
			$image_path = ABSPATH . $gallery_row[ 'path' ];
			$image_file_full_path = $image_path . $filename;

			// Check if file exists.
			if ( ! file_exists( $image_file_full_path ) ) {
				echo sprintf( "ERROR ngg_image pid %d not found at %s\n", $image_row[ 'pid' ], $image_file_full_path );
			}


		}
		$progress->finish();

		// wp_ngg_album
		// wp_ngg_gallery
	}

	/**
	 * Returns `wp_ngg_gallery` row with given gid.
	 *
	 * @param int $gid
	 *
	 * @return array|null
	 */
	private function get_gallery_row_by_gid( $gid ) {
		foreach ( $this->galleries_rows as $key_gallery_row => $gallery_row ) {
			if ( $gid == $gallery_row[ 'gid' ] ) {
				return $this->galleries_rows[ $key_gallery_row ];
			}
		}

		return null;
	}
}
