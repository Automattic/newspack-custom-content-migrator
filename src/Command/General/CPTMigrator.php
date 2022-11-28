<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Command\General\PostsMigrator;
use \WP_CLI;

class CPTMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
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
		WP_CLI::add_command( 'newspack-content-migrator cpt-converter', array( $this, 'cmd_convert_cpt' ), [
			'shortdesc' => 'Converts posts of one type to another.',
			'synopsis'  => [
				[
					'type'        => 'positional',
					'name'        => 'from',
					'description' => 'The name of the custom post type to convert.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'positional',
					'name'        => 'to',
					'description' => 'Which post type to convert to.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'post-id',
					'description' => 'ID of a specific post to convert.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'assign-category',
					'description' => 'ID of a post category to apply to the converted posts.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'assign-tag',
					'description' => 'Slug of a post tag to apply to the converted posts.',
					'optional'    => true,
					'repeating'   => false,
				],
			]
		] );
	}

	/**
	 * Callable for the `newspack-content-migrator cpt-converter` command.
	 *
	 * Runs through each post converting to the desired post type.
	 *
	 * @param  array $args
	 * @param  array $assoc_args
	 */
	public function cmd_convert_cpt( $args, $assoc_args ) {

		// Get the to and from post types.
		$cpt_from = $args[0];
		$cpt_to   = $args[1];

		// Make sure we have a valid post type.
		if ( ! \in_array( $cpt_from, get_post_types() ) ) {
			// Register the post type temporarily.
			register_post_type( $cpt_from );
		}

		// Check the "to" post type is valid. It should probably be a core one, really.
		if ( ! \in_array( $cpt_to, get_post_types() ) ) {
			WP_CLI::error( sprintf( __('Post type %s does not exist!' ), $cpt_to ) );
		}

		$post_id         = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;
		$assign_category = isset( $assoc_args[ 'assign-category' ] ) ? (int) $assoc_args['assign-category'] : false;
		$assign_tag      = isset( $assoc_args[ 'assign-tag' ] ) ? $assoc_args['assign-tag'] : false;

		// Grab the posts to convert then.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
		} else {
			$posts = get_posts( [
				'posts_per_page' => -1,
				'post_type'      => $cpt_from,
				'post_status'    => 'any',
			] );
		}

		// Don't try to convert nothing, 'cause that won't work.
		if ( empty( $posts ) ) {
			WP_CLI::error( 'There are no posts to convert!' );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting posts', count( $posts ) );
		$failed = 0;

		// Convert the posts!
		foreach ( $posts as $post ) {

			$converted = set_post_type( $post->ID, $cpt_to );

			if ( 1 === $converted ) {

				// Set a meta to help us remember what CPT this was converted from.
				add_post_meta( $post->ID, '_cpt_converted_from', $cpt_from );

				// For posts, and if specified, assign a category/tag.
				if ( 'post' == $cpt_to && ( $assign_category || $assign_tag ) ) {

					if ( $assign_category ) {
						wp_set_post_terms( $post->ID, $assign_category, 'category' );
					}

					if ( $assign_tag ) {
						wp_set_post_terms( $post->ID, $assign_tag, 'post_tag' );
					}

				}

			} else {
				// Log the failure so we know to re-run.
				$failed++;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Finished processing, with %d failures.', $failed ) );

	}

}
