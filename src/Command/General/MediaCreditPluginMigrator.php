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

	private $dry_run = false;

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;
	private $log;

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

		WP_CLI::add_command(
			'newspack-content-migrator migrate-media-credit-plugin-captions',
			[ $this, 'cmd_migrate_media_credit_plugin_captions' ],
			[
				'shortdesc' => 'Migrate Media Credit Plugin caption shortcodes.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry-run simulation only. No updates.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
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

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Starting migration.' );


		global $wpdb;
		$wpdb->set_prefix( 'live_' );

// ORDER POSTS by date DESC so NEWEST post will save to the BLANK DB value
// ORDER POSTS by date DESC so NEWEST post will save to the BLANK DB value
// ORDER POSTS by date DESC so NEWEST post will save to the BLANK DB value
// ORDER POSTS by date DESC so NEWEST post will save to the BLANK DB value
// ORDER POSTS by date DESC so NEWEST post will save to the BLANK DB value


// <figcaption>
// remove shortcode open and close tags



		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish' ),
                's'              => '[media-credit',
                'search_columns' => array( 'post_content' ),


			),
			function ( $post ) {

				$this->logger->log( $this->log, '---- Post id: ' . $post->ID );

				// update postmeta or <figcaption>
				// remove shortcode open and close tags
				
			},
            1, 100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Migrate Media Credit Plugin within caption shortcode.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_media_credit_plugin_captions( $pos_args, $assoc_args ) {

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';
		
		$this->logger->log( $this->log, 'Starting migration.' );

		if( isset( $assoc_args['dry-run'] ) ) {

			$this->dry_run = true;
			$this->logger->log( $this->log, 'with --dry-run.' );

		}

		global $wpdb;
		$wpdb->set_prefix( 'live_' );


		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish' ),
                // Search for caption shortcode.
				's'              => '[caption',
                'search_columns' => array( 'post_content' ),
				// Order by date DESC so newest caption will be set into blank postmeta.
				'orderby'        => 'date',
				'order'          => 'DESC',

			),
			function ( $post ) {

				$this->logger->log( $this->log, '---- Post id: ' . $post->ID );

                // Get shortcodes within content.
				preg_match_all( '/' . get_shortcode_regex( array( 'caption' ) ) . '/', $post->post_content, $shortcode_matches, PREG_SET_ORDER );

				$this->logger->log( $this->log, 'Shortcodes found: ' . count( $shortcode_matches ) );

				$this->logger->log( $this->log, $post->post_content );

				foreach( $shortcode_matches as $shortcode_match ) {

					$post->post_content = str_replace( $shortcode_match[0], $this->process_caption_shortcode_match( $shortcode_match ), $post->post_content );

				} // each shortcode

				// update post
				if( $this->dry_run ) {

					$this->logger->log( $this->log, 
						'Dry-run only: update post content.',
						$this->logger::INFO
					);
	
				}
				else {
	
					echo "do update here";
					exit();
	
				}

				$this->logger->log( $this->log, $post->post_content );

				exit();
	

			},
            1, 100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Process a caption shortcode match.
	 * 
	 * Array
	 * [0] => [caption id="attachment_1045738" ... ][media-credit name="" ... ]<img class="wp-image-1045738" ... />[/media-credit] text caption[/caption]
	 * [1] =>
	 * [2] => caption
	 * [3] =>  id="attachment_1045738" ...
	 * [4] =>
	 * [5] => [media-credit name="" ... ]<img class="wp-image-1045738" ... />[/media-credit] text caption
	 * [6] =>
	 *
	 * @param string $shortcode_match
	 * @return string $updated_shortcode
	 */
	private function process_caption_shortcode_match( $shortcode_match ) {

		// No need to process if media-credit shortcode not found.
		if( false === strpos( $shortcode_match[5], '[media-credit' ) ) {
			return $shortcode_match[0];
		}

		// Get media-credit shortcode.
		$media_credit_shortcode_matches = $this->get_media_credit_shortcode_matches( $shortcode_match[5] );

		// Only one shortcode should be found.
		if( 1 != count( $media_credit_shortcode_matches ) ) {
			
			$this->logger->log( $this->log,
				'Caption shortcode contained multiple media-credit shortcodes: ' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);
		}

		// Process media-credit.
		$media_credit_name = $this->process_media_credit_shortcode( $media_credit_shortcode_matches[0] );

		// Remove media credit shortcode.
		$updated_shortcode = str_replace( $media_credit_shortcode_matches[0][0], $media_credit_shortcode_matches[0][5], $shortcode_match[0] );

		// Add credit into caption.
		if( ! empty( $media_credit_name ) ) {
			$updated_shortcode = str_replace( '[/caption]', ' (Credit: ' . $media_credit_name . ')[/caption]', $updated_shortcode );
		}

		return $updated_shortcode;

	}

	/**
	 * Get media-credit shortcode matches from content.
	 *
	 * @param string $content
	 * @return array $shortcode_matches
	 */
	private function get_media_credit_shortcode_matches( $content ) {

		preg_match_all( '/' . get_shortcode_regex( array( 'media-credit' ) ) . '/', $content, $shortcode_matches, PREG_SET_ORDER );

		return $shortcode_matches;

	}

	/**
	 * Process a single media credit shortcode.
	 *
	 * [0] => [media-credit name|id="" ... ]<img class="wp-image-1022982 ..." ... />[/media-credit]
	 * [1] => 
	 * [2] => media-credit
	 * [3] =>  name|id="" ...
	 * [4] => 
	 * [5] => <img ... />
	 * [6] => 

	 * @param array $shortcode_match
	 * @return null|string $media_credit_name
	 */
	private function process_media_credit_shortcode( $shortcode_match ) {

		// Data integrity check.
		if( 7 != count( $shortcode_match ) || 'media-credit' != $shortcode_match[2] ) {

			$this->logger->log( $this->log, 
				'Media Credit parse error: ' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true 
			);

		}

		// Parse attributes.
		$atts = shortcode_parse_atts( $shortcode_match[3] );

		// Attributes integrity check.
		if( array_key_exists( 'name', $atts ) && array_key_exists( 'id', $atts ) ) {

			$this->logger->log( $this->log,
				'Media credit attributes error:' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);

		}

		// TODO: User id.
		if( array_key_exists( 'id', $atts ) ) {

			$this->logger->log( $this->log,
				'TODO: User ID not used by initial publisher.',
				$this->logger::WARNING
			);

			return;

		}

		// Data integrity check.
		if( false == array_key_exists( 'name', $atts ) ) {

			$this->logger->log( $this->log,
				'Media credit attributes name missing:' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);

		}
		
		$atts['name'] = trim( $atts['name'] );

		// Attempt to match image ID.
		if( false == preg_match( '/wp-image-(\d+)/', $shortcode_match[5], $img_match ) ) {

			$this->logger->log( $this->log,
				'Media credit missing or external image: ' . print_r( $shortcode_match, true ),
				$this->logger::WARNING
			);

			// Return the shortcode credit name.
			return $atts['name'];

		}

		// Data integrity for img.
		if( 2 != count( $img_match ) ) {

			$this->logger->log( $this->log,
				'Media Credit image matche error: ' . print_r( $img_match, true ),
				$this->logger::ERROR,
				true
			);

		}

// Verify ID is an image.
echo "here";
// Not an image, return name as is.
exit();




		// Compare to DB credit string.
		$img_postmeta = trim( get_post_meta( $img_match[1], '_media_credit', true ) );

		// If shortcode string matches db string, no more processing needed.
		if( $img_postmeta == $atts['name'] ) {
			return;
		}

		// If DB value is blank, then set it to the shortcode value.
		if( empty( $img_postmeta ) ) {

			if( $this->dry_run ) {

				$this->logger->log( $this->log, 
					'Dry-run only: update post meta => ' . $img_match[1] . ' _media_credit ' . $atts['name'],
					$this->logger::INFO
				);

			}
			else {

				update_post_meta( $img_match[1], '_media_credit', $atts['name'] );

			}

			return;

		}

		// Return the shortcode credit name.
		return $atts['name'];
		
	}

}
