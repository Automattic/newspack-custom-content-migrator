<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Search Light New Mexico.
 */
class SearchLightNMMigrator implements InterfaceCommand {
	// Logs.
	const SUBTITLE_LOGS   = 'SLNM_subtitles.log';
	const SHORTCODES_LOGS = 'SLNM_shortcodes.log';

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
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator searchlightnm-migrate-post-subtitle',
			array( $this, 'searchlightnm_migrate_post_subtitle' ),
			array(
				'shortdesc' => 'Migrate post subtitle from content.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlightnm-clean-post-content',
			array( $this, 'searchlightnm_clean_post_content' ),
			array(
				'shortdesc' => 'Clean post content from shortcodes.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-migrate-post-subtitle`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_migrate_post_subtitle( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		foreach ( $posts as $post ) {
			if ( strpos( $post->post_content, '[vc_row' ) !== false ) {
				$live_url     = str_replace( get_site_url(), 'https://searchlightnm.org', get_permalink( $post ) );
				$live_content = $this->newspack_scraper_migrator_get_raw_html( $live_url );

				if ( $live_content ) {
					$possible_subtitle = $this->get_subtitle_from_content( $live_content );

					if ( $possible_subtitle ) {
						update_post_meta( $post->ID, 'newspack_post_subtitle', $possible_subtitle );
						$this->log( self::SUBTITLE_LOGS, sprintf( 'Setting post %d subtitle: %s', $post->ID, $possible_subtitle ) );
					}
				}
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-clean-post-content`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_clean_post_content( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		foreach ( $posts as $post ) {
			$post_content_blocks = array();

			foreach ( parse_blocks( $post->post_content ) as $content_block ) {
				// remove shortcodes from classic blocks that starts with a shortcode.
				if ( ! $content_block['blockName'] && substr( $content_block['innerHTML'], 0, 1 ) === '[' ) {
					$content_block['innerHTML']    = $this->strip_nonscodes( $content_block['innerHTML'] );
					$content_block['innerContent'] = array_map(
						function( $inner_content ) {
							return $this->strip_nonscodes( $inner_content );
						},
						$content_block['innerContent']
					);
				}

				$post_content_blocks[] = $content_block;
			}

			$post_content_without_shortcodes = serialize_blocks( $post_content_blocks );

			if ( $post_content_without_shortcodes !== $post->post_content ) {
				$update = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $post_content_without_shortcodes,
					)
				);
				if ( is_wp_error( $update ) ) {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Failed to update post %d because %s', $post->ID, $update->get_error_message() ) );
				} else {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Post %d cleaned from shortcodes.', $post->ID ) );
				}
			}
		}
	}

	/**
	 * Strip all shortcodes from the content.
	 *
	 * @param string $text Content to strip the shortcodes from.
	 * @return string
	 */
	private function strip_nonscodes( $text ) {
		global $shortcode_tags;

		if ( ! ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) ) {
			$exclude_codes = join( '|', array_map( 'preg_quote', array_keys( $shortcode_tags ) ) );

			$text = preg_replace( '/\[(?!(' . $exclude_codes . "))(.*?)(?:(\/))?\](?:(.+?)\[\/\2\])?/s", '', $text );  // strip shortcode
		}

		return $text;
	}

	/**
	 * Get possible post subtitle from the content.
	 *
	 * @param string $content Post content with subtitle.
	 * @return string|false
	 */
	private function get_subtitle_from_content( $content ) {
		$this->dom_crawler->clear();
		$this->dom_crawler->add( $content );
		$subtitle_1 = $this->dom_crawler->filter( '.post-content .heading-text  h2' );
		$subtitle_2 = $this->dom_crawler->filterXPath( "//div[contains(@class, 'post-content')]//div[contains(@class, 'uncode_text_column') and contains(@class, 'vc_custom')][1]//b/parent::p/following-sibling::p[1]" );
		$subtitle_3 = $this->dom_crawler->filterXPath( "//div[@id='page-header']//div[contains(@class, 'uncont')]//div[contains(@class, 'vc_row')][1]//div[contains(@class, 'uncode_text_column')][1]/p[2]" );
		if ( 1 === $subtitle_1->count() ) {
			return trim( $subtitle_1->getNode( 0 )->textContent );
		} elseif ( 1 === $subtitle_2->count() ) {
			return trim( $subtitle_2->getNode( 0 )->textContent );
		} elseif ( 1 === $subtitle_3->count() ) {
			return trim( $subtitle_3->getNode( 0 )->textContent );
		}

		return false;
	}

	/**
	 * Get the raw HTML output from a URL.
	 *
	 * @param string $url URL to get HTML for.
	 *
	 * @return string|bool HTML or false in case of an error.
	 */
	private function newspack_scraper_migrator_get_raw_html( $url ) {
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 60,
					'user-agent' => 'Newspack Scraper Migrator',
				)
			);
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );

			return $body;
		} catch ( \Exception $e ) {
			return false;
		}
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
