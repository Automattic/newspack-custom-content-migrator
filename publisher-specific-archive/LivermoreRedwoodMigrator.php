<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DOMDocument;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use WP_CLI;

/**
 * Fixes some problematic tags likely introduced by the Village Media CMS migrator.
 *
 * @package NewspackCustomContentMigrator\Command\PublisherSpecific
 */
class LivermoreRedwoodMigrator implements InterfaceCommand {

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static ?InterfaceCommand $instance = null;

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
	 * Required by InterfaceCommand.
	 *
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-fix-village-media-tags',
			[ $this, 'embarcadero_fix_village_media_tags' ],
			[
				'shortdesc' => 'This command will add tags from a Village Media export to specific posts, and then delete tags that were created erroneously from these posts.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file',
						'description' => 'The file path to the Village Media export.',
					],
				],
			]
		);
	}

	/**
	 * This command fixes an issue where tags were accidentally created with term_id's as names. This is a likely
	 * a bug with the Village Media CMS migrator. This command will correct those tags by looking at the original
	 * export file and resetting the tags for posts. Any tags that were removed and no longer have any posts
	 * associated with it will be deleted as part of this command.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function embarcadero_fix_village_media_tags( $args, $assoc_args ) {
		$file_path = $assoc_args['file'];

		$target_post_ids             = [ 0 ];
		$placeholder_target_post_ids = implode( ', ', array_fill( 0, count( $target_post_ids ), '%d' ) );

		global $wpdb;

		// phpcs:disable -- Query has been escaped and placeholder has been properly generated.
		$original_article_ids = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
    				meta_value, 
    				post_id 
				FROM $wpdb->postmeta 
				WHERE meta_key = 'original_article_id' 
				  AND post_id IN ( $placeholder_target_post_ids )",
				...$target_post_ids
			),
			OBJECT_K
		);
		// phpcs:enable
		$original_article_ids = array_map(
			function ( $original_article_id ) {
				return $original_article_id->post_id;
			},
			$original_article_ids
		);

		$dom = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$dom->loadXML( file_get_contents( $file_path ), LIBXML_PARSEHUGE );
		$contents = $dom->getElementsByTagName( 'content' );

		$custom_objects = [];
		foreach ( $contents as $content ) {
			$custom_object = [];
			foreach ( $content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '#text' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				if ( 'id' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					if ( ! array_key_exists( $node->nodeValue, $original_article_ids ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						continue 2;
					}

					$custom_object['original_article_id'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$custom_object['post_id']             = $original_article_ids[ $node->nodeValue ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					unset( $original_article_ids[ $node->nodeValue ] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}

				if ( 'tags' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					foreach ( $node->childNodes as $tag ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( '#text' === $tag->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							continue;
						}

						$tag_type  = $tag->getAttribute( 'type' );
						$tag_label = $tag->getAttribute( 'label' );

						if ( 'Tag' === $tag_type ) {
							$custom_object['tags'][] = strtolower( $tag_label );
						}
					}
				}
			}

			if ( ! empty( $custom_object ) ) {
				$custom_objects[] = $custom_object;
			}
		}

		$all_tags_remove = [];
		foreach ( $custom_objects as $custom_object ) {
			$post_id              = $custom_object['post_id'];
			$current_tags         = wp_get_post_tags( $post_id );
			$current_tags_by_slug = [];
			$tags_to_remove       = [];

			ConsoleColor::white( 'Post ID:' )->bright_white( $post_id )->output();

			foreach ( $current_tags as $current_tag ) {
				$current_tags_by_slug[ $current_tag->slug ] = strtolower( $current_tag->name );
				if ( ! in_array( strtolower( $current_tag->name ), $custom_object['tags'], true ) ) {
					$tags_to_remove[] = $current_tag->term_id;
				}
			}

			ConsoleColor::white( 'Removing these tags' )->output();
			( new ConsoleTable() )->output_data( $tags_to_remove );
			wp_remove_object_terms( $post_id, $tags_to_remove, 'post_tag' );

			$all_tags_remove = array_merge( $all_tags_remove, $tags_to_remove );

			$tags_to_add = [];
			foreach ( $custom_object['tags'] as $tag_name ) {
				if ( ! in_array( $tag_name, $current_tags_by_slug, true ) ) {
					$tags_to_add[] = $tag_name;
				}
			}

			ConsoleColor::white( 'Adding these tags' )->output();
			( new ConsoleTable() )->output_data( $tags_to_add );

			foreach ( $tags_to_add as $tag_name ) {
				$post_tag = term_exists( $tag_name, 'post_tag' );
				if ( ! $post_tag ) {
					$post_tag = wp_create_tag( $tag_name );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $post_id,
						'term_taxonomy_id' => $post_tag['term_taxonomy_id'],
					]
				);
			}
		}

		$all_tags_remove = array_unique( $all_tags_remove );
		ConsoleColor::white( 'All tags to remove' )->output();
		( new ConsoleTable() )->output_data( $all_tags_remove );

		foreach ( $all_tags_remove as $tag_term_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tag_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->term_relationships tr INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.term_id = %d",
					$tag_term_id
				)
			);

			if ( $tag_count > 0 ) {
				ConsoleColor::bright_yellow( "Tag with term_id $tag_term_id has $tag_count posts associated with it." )->output();
			} else {
				ConsoleColor::green( "Tag with term_id $tag_term_id has no posts associated with it." )->output();
				wp_delete_term( $tag_term_id, 'post_tag' );
			}
		}
	}
}
