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

				$this->logger->log( $log, 'Post id: ' . $post->ID );

                // check for shortcodes
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

					preg_match( '/wp-image-(\d+)/', $shortcode_match[5], $img_matches );

					if( 0 == count( $img_matches ) ) {

						$img_type = 'External ' . $shortcode_match[5];

					}
					else if( 2 == count( $img_matches ) ) {

						$img_type = 'Media ' . $img_matches[1];
					}
					else {

						$this->logger->log( $log, print_r( $img_matches, true) );
						$this->logger->log( $log, 'Media Credit incorrect image count.', $this->logger::ERROR, true );

					}

					$this->logger->log( $log, $img_type . ' => ' . trim( $shortcode_match[3] ) );

				}



                // update postmeta
				// remove shortcode open and close tags

                // exit();

			},
            1, 100
		);

		wp_cache_flush();

		$this->logger->log( $log, 'Done.', $this->logger::SUCCESS );
	}
}
