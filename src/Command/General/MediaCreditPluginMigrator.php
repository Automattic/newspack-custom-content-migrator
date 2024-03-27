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

                // Search for posts with media-credit shortcodes.
				's'              => '[media-credit',
                'search_columns' => array( 'post_content' ),

				// Order by date DESC so newest credit will be set into blank postmeta.
				'orderby'        => 'date',
				'order'          => 'DESC',

			),
			function ( $post ) {

				$this->logger->log( $this->log, '---- Post id: ' . $post->ID );

                // Process [media-credit] shortcodes within [caption] shortcode.

				$shortcode_matches = $this->get_caption_shortcode_matches( $post->post_content );

				foreach( $shortcode_matches as $shortcode_match ) {

					$post->post_content = str_replace( $shortcode_match[0], $this->process_caption_shortcode_match( $shortcode_match ), $post->post_content );

				}

				// Process [media-credit] shortcodes remaining in content and convert to [caption] shortcode.

				$shortcode_matches = $this->get_media_credit_shortcode_matches( $post->post_content );

				foreach( $shortcode_matches as $shortcode_match ) {

					$media_credit_info = $this->process_media_credit_shortcode( $shortcode_match );

					$new_caption_shortcode = '[caption';
					if( isset( $media_credit_info[2] ) && is_numeric( $media_credit_info[2] ) && $media_credit_info[2] > 0 ) {
						$new_caption_shortcode .= ' id="attachment_' . esc_attr( $media_credit_info[2] ) . '"';
					} 
					if( isset( $media_credit_info[1]['align'] ) ) $new_caption_shortcode .= ' align="' . esc_attr( $media_credit_info[1]['align'] ) . '"';
					if( isset( $media_credit_info[1]['width'] ) ) $new_caption_shortcode .= ' width="' . esc_attr( $media_credit_info[1]['width'] ) . '"';
					$new_caption_shortcode .= ']';
					
					$new_caption_shortcode .= $shortcode_match[5]; 

					if( ! empty( $media_credit_info[0] ) ) {
						$new_caption_shortcode .= $media_credit_info[0];
					}
					
					$new_caption_shortcode .= '[/caption]';

					$post->post_content = str_replace( $shortcode_match[0], $new_caption_shortcode, $post->post_content );

				}

				// Update post.
				if( $this->dry_run ) {

					$this->logger->log( $this->log, 'Dry-run only: update post content.' );
	
				}
				else {
	
					wp_update_post( array(
						'ID'           => $post->ID,
						'post_content' => $post->post_content,
					));
				  
				}

			},
            1, 100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Get [caption] shortcode matches from content.
	 *
	 * @param string $content
	 * @return array $shortcode_matches
	 */
	private function get_caption_shortcode_matches( $content ) {

		preg_match_all( '/' . get_shortcode_regex( array( 'caption' ) ) . '/', $content, $shortcode_matches, PREG_SET_ORDER );

		return $shortcode_matches;

	}

	/**
	 * Get [media-credit] shortcode matches from content.
	 *
	 * @param string $content
	 * @return array $shortcode_matches
	 */
	private function get_media_credit_shortcode_matches( $content ) {

		preg_match_all( '/' . get_shortcode_regex( array( 'media-credit' ) ) . '/', $content, $shortcode_matches, PREG_SET_ORDER );

		return $shortcode_matches;

	}

	/**
	 * Process a [caption] shortcode match.
	 * 
	 * Array:
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

		// Remove media credit shortcode.
		$updated_shortcode = str_replace( $media_credit_shortcode_matches[0][0], $media_credit_shortcode_matches[0][5], $shortcode_match[0] );

		// Add credit into caption if needed.
		$media_credit_info = $this->process_media_credit_shortcode( $media_credit_shortcode_matches[0] );

		if( ! empty( $media_credit_info ) && is_array( $media_credit_info ) && ! empty( $media_credit_info[0] ) ) {
			$updated_shortcode = str_replace( '[/caption]', ' (Credit: ' . esc_html( $media_credit_info[0] ) . ')[/caption]', $updated_shortcode );
		}

		return $updated_shortcode;

	}

	/**
	 * Process a [media-credit] shortcode.
	 *
	 * Array:
	 * [0] => [media-credit name|id="" ... ]<img class="wp-image-1022982 ..." ... />[/media-credit]
	 * [1] => 
	 * [2] => media-credit
	 * [3] =>  name|id="" ...
	 * [4] => 
	 * [5] => <img class="wp-image-1022982 ..." ... />
	 * [6] => 

	 * @param array $shortcode_match
	 * @return array [append, atts, attachment_id]
	 */
	private function process_media_credit_shortcode( $shortcode_match ) {

		global $wpdb;

		// Data integrity check.
		if( 7 != count( $shortcode_match ) || 'media-credit' != $shortcode_match[2] ) {

			$this->logger->log( $this->log, 
				'Media Credit parse error: ' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true 
			);

		}
		
		// Parse attachment_id.
		$attachment_id = $this->get_attachment_id_from_content( $shortcode_match[5] );

		// Parse attributes.
		$atts = shortcode_parse_atts( $shortcode_match[3] );

		if( isset( $atts['name'] ) ) $atts['name'] = trim( $atts['name'] );

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

			return array( null, $atts, $attachment_id );

		}

		// Data integrity check.
		if( false == array_key_exists( 'name', $atts ) ) {

			$this->logger->log( $this->log,
				'Media credit attributes name missing:' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);

		}
		
		// Verify image prior to doing possible db updates.
		if( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {

			// Errors and warnings already logged by $this->get_attachment_id_from_content(). 

			return array( $atts['name'], $atts, null );

		}

		// Get DB credit string.
		$img_postmeta = trim( get_post_meta( $attachment_id, '_media_credit', true ) );

		// If shortcode string matches db string, no more processing needed.
		if( $img_postmeta == $atts['name'] ) {

			$this->logger->log( $this->log, 'DB already matches HTML.' );

			return array( null, $atts, $attachment_id );

		}

		// If DB value is blank, then set it to the shortcode value.
		if( empty( $img_postmeta ) ) {

			if( $this->dry_run ) {

				$this->logger->log( $this->log, 'Dry-run only: update post meta => ' . $attachment_id . ' _media_credit ' . $atts['name'] );

			}
			else {

				$this->logger->log( $this->log, 'Img match postmeta updated.' );

				update_post_meta( $attachment_id, '_media_credit', $atts['name'] );

			}

			return array( null, $atts, $attachment_id );

		}

		// Return the shortcode credit name.
		return array( $atts['name'], $atts, $attachment_id );
		
	}

	/**
	 * Get attachment id from content.
	 * 
	 * Example: <img class="wp-image-1022982 ..." ... />
	 *
	 * @param string $content
	 * @return null|int $attachment_id
	 */
	private function get_attachment_id_from_content ( $content ) {

		global $wpdb;

		// Attempt to get image ID.
		if( false == preg_match( '/wp-image-(\d+)/', $content, $img_match ) ) {

			$this->logger->log( $this->log,
				'Media credit missing or external image: ' . $content,
				$this->logger::WARNING
			);

			return;

		}

		// Data integrity for img match.
		if( 2 != count( $img_match ) || ! is_numeric( $img_match[1] ) || ! ( $img_match[1] > 0 ) ) {

			$this->logger->log( $this->log,
				'Media Credit image match error: ' . $content,
				$this->logger::ERROR,
				true
			);

		}

		// Verify ID exists in db.
		$attachment_id = $wpdb->get_var( $wpdb->prepare("select ID from {$wpdb->posts} where post_type = 'attachment' and ID = %d", $img_match[1] ) );

		if( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {

			$this->logger->log( $this->log,
				'Media credit missing db attachment: ' . $content,
				$this->logger::WARNING
			);

			return;

		}
		
		return $attachment_id;
		
	}

}
