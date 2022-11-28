<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;
use \WP_User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Custom migration scripts for Prison Journalism Project.
 */
class PrisonJournalismMigrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->dom_crawler = new Crawler();
		$this->posts_logic = new PostsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
	}

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
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
				'newspack-content-migrator prisonjournalism-fix-double-images',
			[ $this, 'cmd_fix_double_images' ],
		);
		WP_CLI::add_command(
				'newspack-content-migrator prisonjournalism-fix-authors',
			[ $this, 'cmd_fix_authors' ],
			[
				'shortdesc' => 'Imports authors from file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'authors-file',
						'description' => 'PHP file containing a formatted array of author info.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator prisonjournalism-fix-authors`.
	 */
	public function cmd_fix_authors( $args, $assoc_args ) {
		$authors_file = $assoc_args[ 'authors-file' ] ?? null;
		if ( ! file_exists( $authors_file ) ) {
			WP_CLI::error( 'Authors file not found.' );
		}

		$authors_info = include $authors_file;
		foreach ( $authors_info as $key_author_info => $author_info ) {
			WP_CLI::log( sprintf( '(%d/%d) importing author', $key_author_info + 1, count( $authors_info) ) );

			if ( count( $author_info ) != 5 ) {
				$debug = 1;
			}

			$first_name = $author_info[0];
			$last_name = $author_info[1];
			$byline = $author_info[2];
			$writer_bio = $author_info[3];
			// Removes all chars except alpha numeric chars and period.
			$user_login = preg_replace('/[^\.\w]/u', '', $byline );
			// $user_login = preg_replace('/[\W\s^\.]/u', '', $byline );

			// Create GAs CAP fields:
			// 	First Name > First Name
			// 	Last Name > Last Name
			// 	Byline > Display Name
			// 	Writer Bio > Biographical Info
			$ga_id = $this->coauthorsplus_logic->create_guest_author( [
				'display_name' => $byline,
				'user_login' => $user_login,
				'first_name' => $first_name,
				'last_name' => $last_name,
				'description' => $writer_bio,
			] );

			$msg = sprintf( "GA ID %d created for '%s' '%s' '%s'", $ga_id, $first_name, $last_name, $byline );
			$this->log( 'authorsfix.log', $msg );
			WP_CLI::log( $msg );

			// Map GA to User:
			// first name "Alex Edward", last name "Taylor", became a user with username AlexEdwardTaylor.
			$user = get_user_by( 'login', $user_login );
			if ( $user instanceof WP_User ) {
				$this->coauthorsplus_logic->link_guest_author_to_wp_user( $ga_id, $user );

				$msg = sprintf( "linked GA ID %d to User ID %d '%s' '%s'", $ga_id, $user->ID, $user->user_nicename, $user->user_login );
				$this->log( 'authorsfix.log', $msg );
				WP_CLI::log( $msg );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator prisonjournalism-fix-double-images`.
	 */
	public function cmd_fix_double_images( $args, $assoc_args ) {
		global $wpdb;

		$posts = $this->posts_logic->get_all_posts();
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::log( sprintf( '- (%d/%d) ID %d ', $key_posts + 1, count( $posts ), $post->ID ) );

			$this->dom_crawler->clear();
			$this->dom_crawler->add( $post->post_content );
			$images = $this->dom_crawler->filter( 'img[class="thumb-image"]' );

			if ( ! empty( $images ) ) {
				if ( $images->getIterator()->count() == 0 ) {
					WP_CLI::log( 'no img, skipping' );
					continue;
				}

				$image = $images->getIterator()[0];
				$img_raw_html = $image->ownerDocument->saveHTML( $image );
				$img_src = $image->getAttribute( 'src' );

				// DOM Crawler's `<img` ends with the `>`, and HTML source's with a `/>` or a ` />`. Dirty way to work, but let's simply provide these two options, too.
				$img_raw_html_alt1 = rtrim( $image->ownerDocument->saveHTML( $image ), '>' ) . '/>';
				$img_raw_html_alt2 = rtrim( $image->ownerDocument->saveHTML( $image ), '>' ) . ' />';

				$post_content_updated = $post->post_content;
				$post_content_updated = str_replace( $img_raw_html, '', $post_content_updated );
				$post_content_updated = str_replace( $img_raw_html_alt1, '', $post_content_updated );
				$post_content_updated = str_replace( $img_raw_html_alt2, '', $post_content_updated );
				$img_is_duplicate = false != strpos( $post_content_updated, $img_src );

				if ( $img_is_duplicate && $post_content_updated != $post->post_content) {
					$wpdb->update(
						$wpdb->prefix . 'posts',
						[ 'post_content' => $post_content_updated ],
						[ 'ID' => $post->ID ]
					);
					WP_CLI::success( 'Fixed.' );
					$this->log( 'pjp_updated.log', $post->ID . ' ' . $img_src );
				} else {
					WP_CLI::warning( 'img[class="thumb-image"] is not duplicate' );
					$this->log( 'pjp_imgWithClassThumbImageIsNotDuplicate.log', $post->ID );
				}
			}
		}

		// Second replacement.
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::log( sprintf( '- (%d/%d) ID %d ', $key_posts + 1, count( $posts ), $post->ID ) );

			$post_content_updated = $post->post_content;
			// Multi-line inline styling coming from Squarespace's export.
			preg_match_all( '/style="[^"]*padding-bottom\:([\d\.]+)\%;[^"]*"/xims', $post_content_updated, $matches, PREG_OFFSET_CAPTURE );
			if ( ! empty( $matches[0] ) ) {
				foreach ( $matches[0] as $match ) {
					$style = $match[0];
					$post_content_updated = str_replace( $style, '', $post_content_updated );
					if ( $post_content_updated != $post->post_content) {
						$wpdb->update(
							$wpdb->prefix . 'posts',
							[ 'post_content' => $post_content_updated ],
							[ 'ID' => $post->ID ]
						);
						WP_CLI::success( 'Fixed.' );
						$this->log( 'pjp_updated2ndDivReplacement.log', $post->ID );
					}
				}
			}
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();
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
