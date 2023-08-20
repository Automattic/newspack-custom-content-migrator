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

		WP_CLI::add_command(
			'newspack-content-migrator global-atlanta-migrate-region-from-xml',
			[ $this, 'cmd_global_atlanta_migrate_region_from_xml' ],
			[
				'shortdesc' => 'Migrate region taxonomy',
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
	 * Callable for `newspack-content-migrator global-atlanta-migrate-region-from-xml`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_global_atlanta_migrate_region_from_xml( $args, $assoc_args ) {
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

			$title     = (string) $xml_element->title;
			$status    = (string) $wp_base->status;
			$post_type = (string) $wp_base->post_type;

			if ( 'publish' !== $status ) {
				$is_item = $reader->next( 'item' );
				continue;
			}

			for ( $key_category = 0; $key_category + 1 <= $xml_element->category->count(); $key_category++ ) {
				$category_element = $xml_element->category[ $key_category ];
				$domain           = (string) $category_element->attributes()['domain'];
				if ( 'region' === $domain ) {
					$region             = (string) $category_element;
					$region_category_id = wp_create_category( $region );

					if ( is_wp_error( $region_category_id ) ) {
						WP_CLI::warning( sprintf( 'Can\'t create region category: %s: %s', $region, $region_category_id->get_error_message() ) );
						continue;
					}

					$live_posts = $this->get_posts_by_title( $title );
					if ( ! $live_posts ) {
						WP_CLI::warning( sprintf( 'Post title mismatch: %s (%s)', $title, $post_type ) );
						continue;
					}

					foreach ( $live_posts as $live_post ) {
						if ( $live_post->post_title !== $title ) {
							WP_CLI::warning( sprintf( 'Post title mismatch: %s (%s)', $title, $post_type ) );
							continue;
						}

						// Set category to post.
						wp_set_post_categories( $live_post->ID, [ $region_category_id ], true );

						// Set category as main category for the post.
						update_post_meta( $live_post->ID, '_yoast_wpseo_primary_category', $region_category_id );

						WP_CLI::success( sprintf( 'Region category %s is set for the post %d', $region, $live_post->ID ) );
					}
				}
			}

			// Next <item> when done reading this one.
			$is_item = $reader->next( 'item' );
		}

		wp_cache_flush();
	}

	/**
	 * Get post by title.
	 *
	 * @param string $title post title.
	 * @return object[]|false post object or false if not found.
	 */
	private function get_posts_by_title( $title ) {
		global $wpdb;
		$sql   = $wpdb->prepare( "SELECT * FROM  $wpdb->posts WHERE post_title = %s AND post_status != 'trash'", $title );
		$posts = $wpdb->get_results( $sql );
		return $posts ?? false;
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
