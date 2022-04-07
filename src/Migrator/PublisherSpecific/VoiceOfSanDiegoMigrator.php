<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Spheres of Influence.
 */
class VoiceOfSanDiegoMigrator implements InterfaceMigrator {
	// Logs.
	const BYLINES_LOGS = 'VOSD_bylines.log';

	const STAGING_URL = 'https://voiceofsandiego.org/'; // this should be public or run locally
	const LIVE_URL    = 'https://live-voiceofsandiego.pantheonsite.io/';

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->dom_crawler = new Crawler();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator verify-bylines',
			array( $this, 'vosd_verify_bylines' ),
			array(
				'shortdesc' => 'Verify migrated bylines from live site.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator remove-featured-images-from-post-body',
			array( $this, 'vosd_remove_featured_images_from_post_body' ),
			array(
				'shortdesc' => 'Verify migrated bylines from live site.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator verify-bylines`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function vosd_verify_bylines( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => 1000,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			WP_CLI::line( sprintf( '(%d/%d) %s:', $index, $total_posts, $post->post_date ) );
			$staging_post_url = self::STAGING_URL . "$post->post_name";
			$live_post_url    = self::LIVE_URL . "$post->post_name";

			$staging_post_content = $this->get_url_content( $staging_post_url );
			$live_post_content    = $this->get_url_content( $live_post_url );

			if ( ! $staging_post_content || ! $live_post_content ) {
				continue;
			}

			$staging_author = $this->get_author_from_staging_content( $staging_post_content );
			$live_author    = $this->get_author_from_live_content( $live_post_content );

			if ( $live_author !== $staging_author ) {
				$this->log( self::BYLINES_LOGS, sprintf( 'Live author (%s), Staging author (%s), Post (%d)', $live_author, $staging_author, $post->ID ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator remove-featured-images-from-post-body`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function vosd_remove_featured_images_from_post_body( $args, $assoc_args ) {
		global $wpdb;

		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		foreach ( $posts as $post ) {
			if ( substr( $post->post_content, 0, strlen( '[caption' ) ) === '[caption' ) {
				$start_with_images[] = $post->ID;

				$pattern = get_shortcode_regex();
				if ( preg_match_all( '/' . $pattern . '/s', $post->post_content, $matches ) && array_key_exists( 2, $matches ) && in_array( 'caption', $matches[2] ) ) {
					$index = 0;
					$count = 0;
					// Remove the first image shortcode from the content.
					foreach ( $matches[2] as $match ) {
						if ( 'caption' === $match ) {
							// Found our first image.
							$index = $count;
							break; // We've done enough.
						}
						$count++;
					};
					// Remove first gallery from content.
					$content = str_replace( $matches[0][ $index ], '', $post->post_content );

					if ( $content !== $post->post_content ) {
						$wpdb->update(
							$wpdb->prefix . 'posts',
							array( 'post_content' => $content ),
							array( 'ID' => $post->ID )
						);

						WP_CLI::line( sprintf( 'Updated post: %d', $post->ID ) );
					}
				}
			}
		}
	}

	/**
	 * Get page HTML content from a given URL.
	 *
	 * @param string $url URL to get its content.
	 * @return string|false HTML content or false if it's not found.
	 */
	private function get_url_content( $url ) {
		$response = wp_remote_get( $url );
		if ( 404 === wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Get author byline from staging post content using dom crawler.
	 *
	 * @param string $content Post content to get the author byline from.
	 * @return string|null Author byline, or null if it's not found.
	 */
	private function get_author_from_staging_content( $content ) {
		$this->dom_crawler->clear();
		$this->dom_crawler->add( $content );
		$dom = $this->dom_crawler->filter( '.byline' );
		return $dom->getNode( 0 ) ? trim( str_ireplace( 'by', '', $dom->getNode( 0 )->nodeValue ) ) : null;
	}

	/**
	 * Get author byline from live post content using dom crawler.
	 *
	 * @param string $content Post content to get the author byline from.
	 * @return string|null Author byline, or null if it's not found.
	 */
	private function get_author_from_live_content( $content ) {
		$this->dom_crawler->clear();
		$this->dom_crawler->add( $content );
		$dom = $this->dom_crawler->filter( '.byline-cell .vo-byline.-author > cite' );
		return $dom->getNode( 0 ) ? trim( $dom->getNode( 0 )->nodeValue ) : null;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
