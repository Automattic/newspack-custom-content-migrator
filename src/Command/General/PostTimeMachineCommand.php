<?php

namespace NewspackCustomContentMigrator\Command\General;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\PostTimeMachine;
use WP_CLI;

class PostTimeMachineCommand implements InterfaceCommand {

	private function __construct() {
		// Nothing here.
	}

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator post-time-machine-restore-snapshot',
			[ $this, 'cmd_post_time_machine_restore_snapshot' ],
			[
				'shortdesc' => 'Restore posts from a snapshot CSV file.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'snapshot-csv',
						'description' => 'The CSV file to restore posts from.',
						'optional'    => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator post-time-machine-test',
			[ $this, 'cmd_post_time_machine_test' ]
		);
	}

	/**
	 * This is just for testing and can be removed at some point. It WILL mess with content, so be careful.
	 *
	 * @param array $pos_args Pos args.
	 * @param array $assoc_args Assoc args.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function cmd_post_time_machine_test( array $pos_args, array $assoc_args ): void {
		$snapshot_file = PostTimeMachine::get_dated_snapshot_file_name();

		global $wpdb;
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE '% the %' AND post_type = 'post' AND post_status = 'publish' ORDER BY RAND() LIMIT 3"
		);

		foreach ( $posts as $post ) {
			$content = str_replace( ' the ', ' tha ', $post->post_content );
			// Do this before updating the post.
			PostTimeMachine::snapshot_post( $snapshot_file, $post->ID );

			wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
			WP_CLI::log( 'Updated post ' . $post->ID );
		}
		WP_CLI::log( sprintf( "Snapshot file:\n %s\n%s", $snapshot_file, file_get_contents( $snapshot_file ) ) );
	}


	/**
	 * Restore all posts to the version in the snapshot file.
	 *
	 * @param array $pos_args Pos args.
	 * @param array $assoc_args Assoc args.
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function cmd_post_time_machine_restore_snapshot( array $pos_args, array $assoc_args ): void {
		$snapshot_file = $pos_args[0];
		PostTimeMachine::restore_snapshot( $snapshot_file );
	}
}
