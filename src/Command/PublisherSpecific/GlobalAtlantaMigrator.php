<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use XMLReader;
use DOMDocument;

/**
 * Custom migration scripts for Global Atlanta.
 */
class GlobalAtlantaMigrator implements InterfaceCommand {
	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator global-atlanta-fix-featured-images-from-xml',
			[ $this, 'cmd_global_atlanta_fix_featured_images_from_xml' ],
			[
				'shortdesc' => 'Fix imported posts featured images from original XML.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'xml-file-path',
						'description' => 'XML file path containing the WP export.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator global-atlanta-migrate-region-tax',
			[ $this, 'cmd_global_atlanta_migrate_region_tax' ],
			[
				'shortdesc' => 'Migrate region taxonomy',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator global-atlanta-fix-featured-images-from-xml`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_global_atlanta_fix_featured_images_from_xml( $args, $assoc_args ) {
		$xml_file = $assoc_args['xml-file-path'];

		if ( ! file_exists( $xml_file ) ) {
			WP_CLI::error( 'The provided file does not exist.' );
		}

		$attachments = []; // [attachment_id => attachment_name].
		$posts       = []; // [post_id => ['title' => post_title, 'attachment_id' => attachment_id]].

		// Use XMLReader on XML file.
		$reader = new XMLReader();
		$reader->open( $xml_file );

		// Read forward to first <item> element.
		while ( ( $is_item = $reader->read() ) && $reader->name !== 'item' );

		$doc = new DOMDocument();
		while ( $is_item ) {

			// Here's how to reference namespaces as objects, e.g. properties found in the XML file like xmlns:wp, xmlns:content, xmlns:excerpt.
			$xml_element = simplexml_import_dom( $reader->expand( $doc ) );
			$wp_base     = $xml_element->children( 'http://wordpress.org/export/1.2/' );

			$post_type = (string) $wp_base->post_type;
			$post_id   = (string) $wp_base->post_id;
			$title     = (string) $xml_element->title;

			for ( $key_postmeta = 0; $key_postmeta + 1 <= $wp_base->postmeta->count(); $key_postmeta++ ) {
				$meta_key   = (string) $wp_base->postmeta[ $key_postmeta ]->meta_key;
				$meta_value = (string) $wp_base->postmeta[ $key_postmeta ]->meta_value;

				if ( 'attachment' === $post_type && '_wp_attached_file' === $meta_key ) {
					$attachments[ $post_id ] = $meta_value;
				} elseif ( 'post' === $post_type && '_thumbnail_id' === $meta_key ) {
					$posts[ $post_id ] = ['title' => $title, 'attachment_id' => $meta_value];
				}
			}

			// Next <item> when done reading this one.
			$is_item = $reader->next( 'item' );
		}

		foreach ( $posts as $post_id => $post ) {
			if ( ! isset( $attachments[ $post['attachment_id'] ] ) ) {
				WP_CLI::warning( sprintf( 'This post have already a thumbnail: %s', $post['title'] ) );
				continue;
			}
			$attachment_id = $this->get_attachment_id_by_filename( pathinfo( $attachments[ $post['attachment_id'] ], PATHINFO_FILENAME ) );

			if ( ! $attachment_id ) {
				WP_CLI::warning( sprintf( 'This post is to be fixed manually: %s (attachment_id = %d)', $post['title'], $post['attachment_id'] ) );
				continue;
			}

			$live_post = get_page_by_title( $post['title'], \OBJECT, 'post' );

			if ( ! $live_post ) {
				WP_CLI::warning( sprintf( 'This post is to be fixed manually: %s (attachment_id = %d)', $post['title'], $post['attachment_id'] ) );
				continue;
			}

			set_post_thumbnail( $live_post->ID, $attachment_id );
			WP_CLI::success( sprintf( 'This post is fixed: %d', $live_post->ID ) );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator global-atlanta-migrate-region-tax`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_global_atlanta_migrate_region_tax( $args, $assoc_args ) {
		global $wpdb;

		$posts = $wpdb->get_results(
            "SELECT p.ID, t.name, t.term_id
		FROM wp_posts p
		INNER JOIN wp_term_relationships tr ON (p.ID = tr.object_id)
		INNER JOIN wp_term_taxonomy tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id)
		INNER JOIN wp_terms t ON (tt.term_id = t.term_id)
		WHERE p.post_type = 'post'
		AND p.post_status = 'publish'
		AND tt.taxonomy = 'region';"
        );

		foreach ( $posts as $post ) {
			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', $post->term_id );
			WP_CLI::success( sprintf( 'Primary category for the post %d is set to: %s', $post->ID, $post->name ) );
		}

		// Switch 'region' taxonomy to 'category' taxonomy.
		$wpdb->update(
			$wpdb->term_taxonomy,
			[ 'taxonomy' => 'category' ],
			[ 'taxonomy' => 'region' ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Get attachment ID by it's filename
	 *
	 * @param string $filename attachment filename.
	 * @return int|false
	 */
	private function get_attachment_id_by_filename( $filename ) {
		global $wpdb;
		$sql         = $wpdb->prepare( "SELECT * FROM  $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value like %s", "%$filename%" );
		$attachments = $wpdb->get_results( $sql );
		return $attachments[0]->post_id ?? false;
	}
}
