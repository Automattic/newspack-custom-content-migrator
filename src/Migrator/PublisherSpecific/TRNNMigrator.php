<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Error;

/**
 * Custom migration scripts for The Real News Network.
 */
class TRNNMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command(
			'newspack-content-migrator trnn-migrate-synopses',
			[ $this, 'cmd_trnn_migrate_synopses' ],
			[
				'shortdesc' => 'Adds synopses from CPT into migrated Stories posts.',
				'synopsis' => [
					[
						'type'        => 'positional',
						'name'        => 'post_id',
						'description' => __('ID of a specific post to process'),
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Add synopses content from CPT into migrated Stories posts.
	 */
	public function cmd_trnn_migrate_synopses( $args, $assoc_args ) {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( empty( $args ) ) {
			$posts = get_posts(
				[
					'post_type'      => 'post',
					'posts_per_page' => -1,
					'meta_query'     => [
						[
							'key'     => '_cpt_converted_from',
							'value'   => 'trnn_story',
							'compare' => '=',
						],
					],
				] 
			);
		} else {
			list( $post_id ) = $args;
			$posts = [
				get_post( $post_id )
			];
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( __( 'No posts found.' ) );
		} else {
			WP_CLI::line( sprintf(
				__( 'Found %d posts to migrate synopses for.' ),
				count( $posts )
			) );
		}

		foreach ( $posts as $post ) {
			WP_CLI::line( sprintf( __( 'Checking post %d' ), $post->ID ) );

			$updated_content = $post->post_content;
			$synopses_ids = get_post_meta( $post->ID, 'synopsis', true );
			if ( ! $synopses_ids ) {
				$synopses_ids = [];
			}

			WP_CLI::line( sprintf( __( '%d synopses found for post' ), count( $synopses_ids ) ) );

			foreach ( $synopses_ids as $synopsis_id ) {
				$synopsis = get_post( $synopsis_id );
				if ( ! $synopsis ) {
					continue;
				}
				$updated_content .= $synopsis->post_content;
			}

			if ( $post->post_content !== $updated_content ) {

				if ( $dry_run ) {
					$result = true;
				} else {
					$result = $wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $updated_content ], [ 'ID' => $post->ID ] );
				}

				if ( ! $result ) {
					WP_CLI::line( sprintf( __( 'Error updating post %d.' ), $post->ID ) );
				} else {
					WP_CLI::line( sprintf( __( 'Updated post %d' ), $post->ID ) );
				}

			} else {
				WP_CLI::line( sprintf( __( 'No update made for post %d' ), $post->ID ) );
			}
		}

		wp_cache_flush();
		WP_CLI::line( __( 'Completed' ) );
	}
}
