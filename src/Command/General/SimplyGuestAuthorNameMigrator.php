<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class SimplyGuestAuthorNameMigrator implements InterfaceCommand {

	private $log_file;

	/**
	 * @var CoAuthorPlus.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic = null;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
	}

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
			'newspack-content-migrator migrate-simply-guest-author-names',
			[ $this, 'cmd_migrate_simply_guest_author_names' ],
			[
				'shortdesc' => 'Migrate Simply Guest Author Names to CoAuthorsPlus.',
			]
		);

	}

	/**
	 * Migrate Simply Guest Author Names to CoAuthorsPlus.
	 */
	public function cmd_migrate_simply_guest_author_names( $args, $assoc_args ) {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$this->log_file = str_replace(__NAMESPACE__ . '\\', '', __CLASS__) . '_' . __FUNCTION__ . '.log';

		$report = [
			'wp-user-missing' => 0,
			'sfly-mismatch' => 0,
			'sfly-exists' => 0,
			'gas-exist' => 0,
			'each' => [],
		];

		$this->logger->log( $this->log_file, 'Starting migration...' );

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				// 'fields'      => 'ids',
			),
			function( $post ) use ( &$report ) {

				$post_id = $post->ID;
				$single_report = '-';

				$this->logger->log( $this->log_file, 'Post id: ' . $post_id );
				$this->logger->log( $this->log_file, 'Author id: ' . $post->post_author );
				
				// Post Author.
				$wp_user = get_userdata( $post->post_author );

				if( is_a( $wp_user, 'WP_user' ) ) {
					$this->logger->log( $this->log_file, 'WP User: ' . $wp_user->display_name );
				}
				else {
					$this->logger->log( $this->log_file, 'WP User: (not exists)', $this->logger::WARNING );
					$report['wp-user-missing']++;
					$single_report .= 'wp-user-missing';
				}

				// Simply meta.
				$sfly = array(
					'desc'  => get_post_meta( $post_id, 'sfly_guest_author_description', true ),
					'email' => get_post_meta( $post_id, 'sfly_guest_author_email', true ),
					'names' => get_post_meta( $post_id, 'sfly_guest_author_names', true ),
					'link'  => get_post_meta( $post_id, 'sfly_guest_link', true ),
				);
				
				if( ! empty( $sfly['names'] ) ) {

					$this->logger->log( $this->log_file, 'Simply Names: ' . $sfly['names'] );
					$report['sfly-exists']++;
					$single_report .= 'sfly-exists';

				}
				else if( ! empty( $sfly['desc'] ) || ! empty( $sfly['email'] )  || ! empty( $sfly['link'] ) ) {

					$this->logger->log( $this->log_file, 'Simply Names Mismatch: ' . print_r( $sfly, true ), $this->logger::WARNING );
					$report['sfly-mismatch']++;

				}


				// Existing GAs.
				$gas = array_map( function( $ga ) {
					return $ga->display_name;
				}, $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id ));
				
				if( ! empty( $gas ) ) {
					$this->logger->log( $this->log_file, 'Existing GAs: ' . implode( ', ', $gas ), $this->logger::WARNING );					
					$report['gas-exist']++;
					$single_report .= 'gas-exist';

				}

				$this->logger->log( $this->log_file, 'Report: ' . $single_report );

				if( ! isset( $report['each'][$single_report] ) ) $report['each'][$single_report] = 0;
				$report['each'][$single_report]++;

				// get or create GA
				// assign to post
		
			},
			0
		);

		wp_cache_flush();

		$this->logger->log( $this->log_file, print_r( $report, true ) );

		$this->logger->log( $this->log_file, 'Done.', $this->logger::SUCCESS );

		WP_CLI::success( 'Done.' );

	}

}
