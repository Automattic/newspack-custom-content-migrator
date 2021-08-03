<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \WP_CLI;
use \WP_Query;

/**
 * Custom migration scripts for MissionlocalMigrator.
 */
class MissionlocalMigrator implements InterfaceMigrator {

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->attachments_logic = new AttachmentsLogic();
		$this->crawler = new Crawler();
	}

	/**
	 * Singleton get_instance().
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
		WP_CLI::add_command(
			'newspack-content-migrator missionlocal-check-numeric-upload-paths-in-content',
			[ $this, 'cmd_check_numeric_upload_paths_in_content' ],
			[
				'shortdesc' => 'Removes the extra numeric paths in uploads subfolders, e.g. ``.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator missionlocal-check-numeric-upload-paths-in-content` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_check_numeric_upload_paths_in_content( $args, $assoc_args ) {
		$time_start = microtime( true );

		$posts = $this->posts_logic->get_all_posts();
		foreach ( $posts as $key_posts => $post ) {

			WP_CLI::line( sprintf( '(%d/%d) ID %s', $key_posts + 1, count( $posts ), $post->ID ) );
			$pattern = '|/mission/wp-content/uploads/[\d]{4}/[\d]{2}/[\d]{8}/|';
			$matches = null;
			preg_match_all( $pattern, $post->post_content, $matches, PREG_OFFSET_CAPTURE );
			if ( ! empty( $matches[0] ) ) {
				$debug = 1;
				WP_CLI::warning( sprintf( '(%d/%d) ID %s', $key_posts + 1, count( $posts ), $post->ID ) );
				foreach ( $matches[0] as $match ) {
					$this->log( 'found_numeric_paths.log', '%d %s', $post->ID, print_r( $match, true ) );
				}
			}

		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
