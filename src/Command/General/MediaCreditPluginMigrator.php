<?php
/**
 * Newspack Custom Content Migrator: Media Credit Plugin Migrator.
 * 
 * The Media Credit Plugin uses the same postmeta keys as Newspack Plugin. These keys, 
 * '_media_credit' and '_media_credit_url', are stored in postmeta for attachments (images).
 * 
 * In most cases no migration is needed since the postmeta keys are the same, but the
 * Media Credit Plugin also allowed Admins to add freeform text by using a [media-credit] 
 * shortcode within posts' post_content.
 * 
 * The command(s) below can be used to process posts with this shortcode.
 * 
 * @link: https://wordpress.org/plugins/media-credit/
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Media Credit Plugin.
 */
class MediaCreditPluginMigrator implements InterfaceCommand {

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * PostsLogic
	 * 
	 * @var PostsLogic
	 */
	private $posts_logic = null;

	/**
	 * Instance
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
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
			'newspack-content-migrator migrate-media-credit-plugin',
			[ $this, 'cmd_migrate_media_credit_plugin' ],
			[
				'shortdesc' => 'Migrate Media Credit Plugin postmeta and shortcodes.',
			]
		);
	}

	/**
	 * Migrate Media Credit Plugin postmeta and shortcodes
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_media_credit_plugin( $pos_args, $assoc_args ) {

		$log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $log, 'Starting migration.' );


		global $wpdb;
		$wpdb->set_prefix( 'live_' );


		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish' ),
                's'              => '[media-credit',
                'search_columns' => array( 'post_content' ),
			),
			function ( $post ) use ( $log ) {

				$this->logger->log( $log, '---- Post id: ' . $post->ID );

                // Get shortcodes.
				preg_match_all( '/' . get_shortcode_regex( array( 'media-credit' ) ) . '/', $post->post_content, $shortcode_matches, PREG_SET_ORDER );

				$this->logger->log( $log, 'Shortcodes found: ' . count( $shortcode_matches ) );

				foreach( $shortcode_matches as $shortcode_match ) {

					// [0] => [media-credit name="Photo courtesy of the family)" align="aligncenter" width="300"]<img class="wp-image-1022982 size-full" src="https://spokesman-recorder-newspack.newspackstaging.com/wp-content/uploads/2011/03/Picture-32-300x201-1.png" width="300" height="201" />[/media-credit]
					// [1] => 
					// [2] => media-credit
					// [3] =>  name="Photo courtesy of the family)" align="aligncenter" width="300"
					// [4] => 
					// [5] => <img class="wp-image-1022982 size-full" src="https://spokesman-recorder-newspack.newspackstaging.com/wp-content/uploads/2011/03/Picture-32-300x201-1.png" width="300" height="201" />
					// [6] => 

					if( 7 != count( $shortcode_match ) || 'media-credit' != $shortcode_match[2] ) {
	
						$this->logger->log( $log, print_r( $shortcode_match, true) );
						$this->logger->log( $log, 'Media Credit incorrect parse error.', $this->logger::ERROR, true );

					}

					$this->logger->log( $log, '-- Atts: ' . $shortcode_match[3] );

					$atts = shortcode_parse_atts( $shortcode_match[3] );

					$atts_has_name = array_key_exists( 'name', $atts );
					$atts_has_id = array_key_exists( 'id', $atts );

					// Both types?
					if( $atts_has_name && $atts_has_id ) {

						$this->logger->log( $log, print_r( $shortcode_match, true) );
						$this->logger->log( $log, 'Atts has both types.', $this->logger::ERROR, true );

					}
					// ID.
					else if( $atts_has_id ) {

						$this->logger->log( $log, 'User ID not used by publisher.', $this->logger::WARNING );
						return;

					}
					else {
						
						if( ! $atts_has_name ) {
							$this->logger->log( $log, 'No type?', $this->logger::ERROR, true );
						}
					}
					
					
					// Match img tag.
					preg_match( '/wp-image-(\d+)/', $shortcode_match[5], $img_matches );

					if( 0 == count( $img_matches ) ) {

						$this->logger->log( $log, 'Image: External' );
						$this->logger->log( $log, print_r( $shortcode_match, true) );
						return;

					}
					else if( 2 == count( $img_matches ) ) {

						$this->logger->log( $log, 'Image ID: ' . $img_matches[1] );

						$img_postmeta = get_post_meta( $img_matches[1], '_media_credit', true );

						$this->logger->log( $log, 'DB postmeta: ' . $img_postmeta );

						// Check for matching DB value.
						if( $atts['name'] == $img_postmeta ) {
							$this->logger->log( $log, 'Atts postmeta match.' );
							return;
						}

						// If DB value is blank, then set it
						if( empty( trim( $img_postmeta ) ) ) {
							
							$this->logger->log( $log, 'DO update_post_meta' );
							return;

						}

						$this->logger->log( $log, 'DIFFERENT!', $this->logger::WARNING );
						return;
					
					}
					else {

						$this->logger->log( $log, print_r( $img_matches, true) );
						$this->logger->log( $log, 'Media Credit incorrect image count.', $this->logger::ERROR, true );

					}

				}

                // update postmeta or <figcaption>
				// remove shortcode open and close tags

                // exit();

			},
            1, 100
		);

		wp_cache_flush();

		$this->logger->log( $log, 'Done.', $this->logger::SUCCESS );
	}
}
