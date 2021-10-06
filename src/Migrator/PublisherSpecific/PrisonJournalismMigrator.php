<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \WP_CLI;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Custom migration scripts for Prison Journalism Project.
 */
class PrisonJournalismMigrator implements InterfaceMigrator {

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
	 * Constructor.
	 */
	private function __construct() {
		$this->dom_crawler = new Crawler();
		$this->posts_logic = new PostsLogic();
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
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
				'newspack-content-migrator prisonjournalism-fix-double-images',
			[ $this, 'cmd_fix_double_images' ],
		);
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
				if ( $images->getIterator()->count() > 1 ) {
					WP_CLI::warning( 'more than one img[class="thumb-image"], skipping' );
					$this->log( 'pjp_moreThanOneImgWithClassThumbImage.log', $post->ID );
					continue;
				}

				$image = $images->getIterator()[0];
				$img_raw_html = $image->ownerDocument->saveHTML( $image );
				$img_src = $image->getAttribute( 'src' );

				$post_content_updated = $post->post_content;
				$post_content_updated = str_replace( $img_raw_html, '', $post_content_updated );
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
		$posts = $this->posts_logic->get_all_posts();
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::log( sprintf( '- (%d/%d) ID %d ', $key_posts + 1, count( $posts ), $post->ID ) );

			$post_content_updated = $post->post_content;
			preg_match_all( '/style="padding-bottom\:([\d\.]+)\%;"/', $post_content_updated, $matches, PREG_OFFSET_CAPTURE );
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
