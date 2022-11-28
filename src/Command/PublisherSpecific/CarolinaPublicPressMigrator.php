<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use \WP_Error;

/**
 * Custom migration scripts for Carolina Public Press.
 */
class CarolinaPublicPressMigrator implements InterfaceCommand {

	/**
	 * @var null|PostsMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		// Migrate Author CPT to WP User accounts.
		WP_CLI::add_command(
			'newspack-content-migrator carolinapublicpress-meta-to-content',
			[ $this, 'cmd_carolinapublicpress_meta_to_content' ],
			[
				'shortdesc' => 'Migrates the Carolina Public Press content from meta fields to post_content.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'post_id',
						'description' => __('ID of a specific post to process'),
						'optional'    => true,
					]
				],
			]
		);

	}

	/**
	 * Create users where needed from the 'authors' CPT and assign them to posts.
	 */
	public function cmd_carolinapublicpress_meta_to_content( $args, $assoc_args ) {

		list( $post_id ) = $args;

		if ( ! $post_id ) {
			$posts = get_posts( [
				'posts_per_page' => -1,
				'meta_key'	     => 'page_content',
				'meta_compare'   => 'EXISTS',
				'post_type'	     => [ 'post', 'page' ],
			] );
		} else {
			$posts = [ get_post( $post_id ) ];
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( __('No posts found.') );
		} else {
			WP_CLI::line( sprintf(
				__('Found %d posts to check co-authors on.'),
				count( $posts )
			) );
		}

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( __('Checking post %d'), $post->ID ) );

			// This will be our end post_content.
			$content = '';

			// Grab the meta that defines layout.
			$layouts = get_post_meta( $post->ID, 'page_content', true );
			if ( empty( $layouts ) ) {
				continue;
			}

			WP_CLI::line( sprintf( 'Found a layout, processing %d chunks.', count( $layouts ) ) );

			// Store the content chunks for assembling as post_content later.
			$content_chunks = [];

			foreach ( $layouts as $index => $layout ) {
				$mapped_content = $this->get_mapped_content( $post->ID, $layout, $index );
				if ( $mapped_content ) {
					$content_chunks[] = $mapped_content;
				}
			}

			if ( ! empty( $content_chunks ) ) {
				$content = implode( "\n\n", $content_chunks );
			}

			$result = wp_update_post( [
				'ID' => $post->ID,
				'post_content' => $content,
			] );

			if ( ! $result ) {
				WP_CLI::warning( sprintf( "Failed to update: %d", $post->ID ) );
			} else {
				WP_CLI::success( sprintf( 'Updated %d', $post->ID ) );
			}
		}

	}

	private function get_mapped_content( $post_id, $layout, $index ) {

		switch ( $layout ) {

			case 'one_column':
				$copy = get_post_meta( $post_id, 'page_content_' . $index . '_full_width_column', true );
				return $this->process_copy( $copy );

			case 'two_columns':
				$column_1 = get_post_meta( $post_id, 'page_content_' . $index . '_column_1', true );
				$column_2 = get_post_meta( $post_id, 'page_content_' . $index . '_column_2', true );
				return sprintf( '<div class="wp-block-columns">
						<div class="wp-block-column">%s</div>
						<div class="wp-block-column">%s</div>
					</div>',
					$this->process_copy( $column_1 ),
					$this->process_copy( $column_2 )
				);

			case 'three_columns':
				$column_1 = get_post_meta( $post_id, 'page_content_' . $index . '_column_1', true );
				$column_2 = get_post_meta( $post_id, 'page_content_' . $index . '_column_2', true );
				$column_3 = get_post_meta( $post_id, 'page_content_' . $index . '_column_3', true );
				return sprintf( '<div class="wp-block-columns">
						<div class="wp-block-column">%s</div>
						<div class="wp-block-column">%s</div>
						<div class="wp-block-column">%s</div>
					</div>',
					$this->process_copy( $column_1 ),
					$this->process_copy( $column_2 ),
					$this->process_copy( $column_3 )
				);

			case 'one_third_two_thirds':
				$column_1 = get_post_meta( $post_id, 'page_content_' . $index . '_column_1', true );
				$column_2 = get_post_meta( $post_id, 'page_content_' . $index . '_column_2', true );
				return sprintf( '<div class="wp-block-columns">
						<div class="wp-block-column" style="flex-basis:33.33%%">%s</div>
						<div class="wp-block-column" style="flex-basis:66.66%%">%s</div>
					</div>',
					$this->process_copy( $column_1 ),
					$this->process_copy( $column_2 )
				);

			case 'two_thirds_one_third':
				$column_1 = get_post_meta( $post_id, 'page_content_' . $index . '_column_1', true );
				$column_2 = get_post_meta( $post_id, 'page_content_' . $index . '_column_2', true );
				return sprintf( '<div class="wp-block-columns">
						<div class="wp-block-column" style="flex-basis:66.66%%">%s</div>
						<div class="wp-block-column" style="flex-basis:33.33%%">%s</div>
					</div>',
					$this->process_copy( $column_1 ),
					$this->process_copy( $column_2 )
				);

			case 'divider':
				return '<div class="hr"></div>';

			default:
				return '';
		}

	}

	private function process_copy( $copy ) {

		$paragraphs = explode( "&nbsp;", $copy );

		$trimmed = [];

		foreach ( $paragraphs as $paragraph ) {
			$trimmed[] = trim( $paragraph );
		}

		$final_copy = implode( "\n\n", $trimmed );

		return $final_copy;

	}

}
