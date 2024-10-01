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
 * The main command below can be used to remove the old shortcode (while saving freeform text
 * into postmeta), then a secondary command can be used to export the saved postmeta into SQL
 * for visual review/clean-up and phpmyadmin execution.
 * 
 * @link: https://wordpress.org/plugins/media-credit/
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use Newspack\MigrationTools\Util\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Media Credit Plugin.
 */
class MediaCreditPluginMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	const POSTMETA_KEY_OTHER_CREDITS = 'newspack_media_credit_other_credits';

	/**
	 * Dry Run
	 *
	 * @var boolean $dry_run
	 */
	private $dry_run = false;

	/**
	 * Log (file path)
	 *
	 * @var string $log
	 */
	private $log;

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * Report for dry-run
	 *
	 * @var array $report
	 */
	private $report = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator migrate-media-credit-plugin',
			self::get_command_closure( 'cmd_migrate_media_credit_plugin' ),
			[
				'shortdesc' => 'Migrate Media Credit Plugin postmeta and shortcodes.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry-run simulation only. No updates. Show report.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator review-media-credit-plugin-other-credits',
			self::get_command_closure( 'cmd_review_media_credit_plugin_other_credits' ),
			[
				'shortdesc' => 'Review Media Credit Plugin "other credits" that are saved in postmeta. Log will contain possible SQL to run.',
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

		$this->logger->log( $this->log, 'Doing migration.' );
		
		if ( isset( $assoc_args['dry-run'] ) ) {

			$this->dry_run = true;
			$this->logger->log( $this->log, 'with --dry-run.' );

		}

		$batch = 0;

		do {

			++$batch;

			$this->logger->log( $this->log, '------ Batch: ' . $batch );

			$args = array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => '100',
				// Search for posts with media-credit shortcodes.
				's'              => '[media-credit',
				'search_columns' => array( 'post_content' ),
				// Order by date DESC so newest credit will be set into blank postmeta.
				'orderby'        => 'date',
				'order'          => 'DESC',
			);

			// Add paging when doing dry run. Otherwise if no posts are updated, the same set will be fetched.
			// (When not dry-run, the posts are actually updated, thus the 's' (search) will find the next set automatically).
			if ( $this->dry_run ) {
				$args['paged'] = $batch;
			}

			$posts = get_posts( $args );

			foreach ( $posts as $post ) {

				$this->logger->log( $this->log, '---- Post id: ' . $post->ID );

				$new_post_content = $this->process_post_content( $post->ID, $post->post_content );

				// Error if replacments were not successful ( new == old ).
				if ( $new_post_content == $post->post_content ) {

					$this->logger->log(
						$this->log,
						'New content is the same as old content.',
						$this->logger::ERROR,
						true
					);
		
				}
					
				// Update post.
				if ( $this->dry_run ) {

					$this->logger->log( $this->log, 'Dry-run: WP update post.' );

				} else {

					$this->logger->log( $this->log, 'WP update post.' );

					wp_update_post(
						array(
							'ID'           => $post->ID,
							'post_content' => $new_post_content,
						)
					);
				
				}           
			} // foreach post.

			wp_cache_flush();

			// PHPCS: can't do count() directly in the while loop, must set variable.
			$posts_count = count( $posts );

		} while ( $posts_count > 0 ); // get_posts.

		if ( $this->dry_run ) {
			$this->log_report();
		}

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Review Media Credit Plugin other credits saved in postmeta. Log a list of SQL
	 * for visual review and execution.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_review_media_credit_plugin_other_credits( $pos_args, $assoc_args ) {

		global $wpdb;

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Review "other credits", choose a single SQL line per attachment (or none), then run remaining SQL.' );

		// Get attachments that have required postmeta.
		$args = array(
			'post_type' => 'attachment',
			'meta_key'  => self::POSTMETA_KEY_OTHER_CREDITS,
			'fields'    => 'ids',
			'orderby'   => 'date',
			'order'     => 'DESC',
		);

		$attachment_ids = get_posts( $args );

		foreach ( $attachment_ids as $attachment_id ) {
			
			// Log the existing media credit value.
			$this->logger->log( $this->log, '' );
			$this->logger->log( $this->log, '-- Attachment ID: ' . $attachment_id . ' / Current media credit: ' . get_post_meta( $attachment_id, '_media_credit', true ) );
			$this->logger->log( $this->log, '' );

			// Get "other credits" (multiple rows may exist (single = false)).
			$other_credits = get_post_meta( $attachment_id, self::POSTMETA_KEY_OTHER_CREDITS, false );
			
			// Log SQL statements to a file for human-review.
			// SQL updates in this loop are NOT executed, they are only logged.
			// They must be run by-hand (phpmyadmin) later if requested by human-reviewer.
			foreach ( $other_credits as $other_credit ) {
				
				$sql = $wpdb->prepare(
					"
					UPDATE $wpdb->postmeta set meta_value = %s where meta_key = %s and post_id = %d;
					",
					$other_credit,
					'_media_credit',
					$attachment_id
				);

				$this->logger->log( $this->log, trim( $sql ) );

			} // each "other credit"

		} // each attachment

		$this->logger->log( $this->log, '' );

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Process a single post's content for shortcodes.
	 * 
	 * Replace "shortode inside shortcode" [caption ][media-credit ] with just [caption ]
	 * Then replace remaining [media-credit ] with [caption ]
	 * 
	 * @param int    $post_id Post ID.
	 * @param string $post_content Before shortcode replacements.
	 * @return string $new_post_content After shortcode replacements.
	 */
	private function process_post_content( $post_id, $post_content ) {

		// Process [media-credit] shortcodes already within [caption] shortcode.

		// Match array indexes:
		// [0] => [caption id="attachment_1045738" ... ][media-credit name="" ... ]<img class="wp-image-1045738" ... />[/media-credit] text caption[/caption]
		// [1] =>
		// [2] => caption
		// [3] =>  id="attachment_1045738" ...
		// [4] =>
		// [5] => [media-credit name="" ... ]<img class="wp-image-1045738" ... />[/media-credit] text caption
		// [6] =>
		// (phpcs: comment with period at end).

		$shortcode_matches = $this->get_caption_shortcode_matches( $post_content );

		foreach ( $shortcode_matches as $shortcode_match ) {

			$old_shortcode_string = $shortcode_match[0];

			$this->logger->log( $this->log, 'Old string: ' . $old_shortcode_string );

			$new_shortcode_string = $this->process_caption_shortcode_match( $post_id, $shortcode_match );

			$this->logger->log( $this->log, 'New string: ' . $new_shortcode_string );

			$post_content = str_replace( $old_shortcode_string, $new_shortcode_string, $post_content );

		}

		// Process [media-credit] shortcodes remaining in content and convert to [caption] shortcode.

		// Match array indexes:
		// [0] => [media-credit name|id="" ... ]<img class="wp-image-1022982 ..." ... />[/media-credit]
		// [1] => 
		// [2] => media-credit
		// [3] =>  name|id="" ...
		// [4] => 
		// [5] => <img class="wp-image-1022982 ..." ... />
		// [6] => 
		// (phpcs: comment with period at end).
	
		$shortcode_matches = $this->get_media_credit_shortcode_matches( $post_content );

		foreach ( $shortcode_matches as $shortcode_match ) {

			$old_shortcode_string = $shortcode_match[0];

			$this->logger->log( $this->log, 'Old string: ' . $old_shortcode_string );

			$media_credit_info = $this->process_media_credit_shortcode( $post_id, $shortcode_match );

			$new_shortcode_string = '[caption';

			if ( isset( $media_credit_info[2] ) && is_numeric( $media_credit_info[2] ) && $media_credit_info[2] > 0 ) {
				$new_shortcode_string .= ' id="attachment_' . esc_attr( $media_credit_info[2] ) . '"';
			} 
			if ( isset( $media_credit_info[1]['align'] ) ) {
				$new_shortcode_string .= ' align="' . esc_attr( $media_credit_info[1]['align'] ) . '"';
			}
			if ( isset( $media_credit_info[1]['width'] ) ) {
				$new_shortcode_string .= ' width="' . esc_attr( $media_credit_info[1]['width'] ) . '"';
			}
			
			$new_shortcode_string .= ']';
			
			$new_shortcode_string .= trim( $shortcode_match[5] );

			if ( ! empty( $media_credit_info[0] ) ) {
				$new_shortcode_string .= ' ' . esc_html( trim( $media_credit_info[0] ) );
			}
			
			$new_shortcode_string .= '[/caption]';

			$this->logger->log( $this->log, 'New string: ' . $new_shortcode_string );

			$post_content = str_replace( $old_shortcode_string, $new_shortcode_string, $post_content );
		
		}

		return $post_content;
	}

	/**
	 * Get [caption] shortcode matches from content.
	 *
	 * @param string $content Content to parse for [caption].
	 * @return array $shortcode_matches
	 */
	private function get_caption_shortcode_matches( $content ) {

		preg_match_all( '/' . get_shortcode_regex( array( 'caption' ) ) . '/', $content, $shortcode_matches, PREG_SET_ORDER );

		return $shortcode_matches;
	}

	/**
	 * Get [media-credit] shortcode matches from content.
	 *
	 * @param string $content Content to parse for [media-credit].
	 * @return array $shortcode_matches
	 */
	private function get_media_credit_shortcode_matches( $content ) {

		preg_match_all( '/' . get_shortcode_regex( array( 'media-credit' ) ) . '/', $content, $shortcode_matches, PREG_SET_ORDER );

		return $shortcode_matches;
	}

	/**
	 * Process a [caption] shortcode match.
	 *
	 * @param int    $post_id    PostID.
	 * @param string $shortcode_match Array of parsed shortcode.
	 * @return string $updated_shortcode
	 */
	private function process_caption_shortcode_match( $post_id, $shortcode_match ) {

		// No need to process if media-credit shortcode not found.
		if ( false === strpos( $shortcode_match[5], '[media-credit' ) ) {
			return $shortcode_match[0];
		}

		// Get media-credit shortcode.
		$media_credit_shortcode_matches = $this->get_media_credit_shortcode_matches( $shortcode_match[5] );

		// Only one shortcode should be found.
		if ( 1 != count( $media_credit_shortcode_matches ) ) {
			
			$this->logger->log(
				$this->log,
				'Caption shortcode contained multiple media-credit shortcodes: ' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);
		}

		$updated_shortcode = $shortcode_match[0];

		// Remove media credit preceeding line break if exists.
		$updated_shortcode = preg_replace( '/\]\s+\[media-credit/i', '][media-credit', $updated_shortcode );

		// Remove media credit shortcode.
		$updated_shortcode = str_replace( $media_credit_shortcode_matches[0][0], trim( $media_credit_shortcode_matches[0][5] ), $updated_shortcode );

		// Add credit into caption if needed.
		$media_credit_info = $this->process_media_credit_shortcode( $post_id, $media_credit_shortcode_matches[0] );

		if ( ! empty( $media_credit_info ) && is_array( $media_credit_info ) && ! empty( $media_credit_info[0] ) ) {
			$updated_shortcode = str_replace( '[/caption]', ' (Credit: ' . esc_html( trim( $media_credit_info[0] ) ) . ')[/caption]', $updated_shortcode );
		}

		return $updated_shortcode;
	}

	/**
	 * Process a [media-credit] shortcode.
	 *
	 * @param int   $post_id PostID.
	 * @param array $shortcode_match Attay of parsed shortcode.
	 * @return array [append, atts, attachment_id]
	 */
	private function process_media_credit_shortcode( $post_id, $shortcode_match ) {

		global $wpdb;

		// Data integrity check.
		if ( 7 != count( $shortcode_match ) || 'media-credit' != $shortcode_match[2] ) {

			$this->logger->log(
				$this->log, 
				'Media Credit parse error: ' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true 
			);

		}
		
		// Parse attachment_id.
		$attachment_id = $this->get_attachment_id_from_content( $shortcode_match[5] );

		// Parse attributes.
		$atts = shortcode_parse_atts( $shortcode_match[3] );

		if ( isset( $atts['name'] ) ) {
			$atts['name'] = trim( $atts['name'] );
		}

		// Attributes integrity check.
		if ( array_key_exists( 'name', $atts ) && array_key_exists( 'id', $atts ) ) {

			$this->logger->log(
				$this->log,
				'Media credit attributes error:' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);

		}

		// TODO: User id.
		if ( array_key_exists( 'id', $atts ) ) {

			$this->logger->log(
				$this->log,
				'TODO: User ID not used by initial publisher.',
				$this->logger::WARNING
			);

			return array( null, $atts, $attachment_id );

		}

		// Data integrity check.
		if ( false == array_key_exists( 'name', $atts ) ) {

			$this->logger->log(
				$this->log,
				'Media credit attributes name missing:' . print_r( $shortcode_match, true ),
				$this->logger::ERROR,
				true
			);

		}
		
		// Verify image prior to doing possible db updates.
		if ( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {

			// Errors and warnings already logged by $this->get_attachment_id_from_content(). 

			return array( $atts['name'], $atts, null );

		}

		// Get DB credit string.
		$img_postmeta = trim( get_post_meta( $attachment_id, '_media_credit', true ) );

		// If shortcode string matches db string, no more processing needed.
		if ( $img_postmeta == $atts['name'] ) {

			$this->logger->log( $this->log, 'Postmeta _media_credit matches.' );

			if ( $this->dry_run ) {
				$this->report( $post_id, $attachment_id, 'equal', $img_postmeta, $atts['name'] );
			}

			return array( null, $atts, $attachment_id );

		}

		// If DB value is blank, then set it to the shortcode value.
		if ( empty( $img_postmeta ) ) {

			if ( $this->dry_run ) {

				$this->logger->log( $this->log, 'Dry-run: Img ' . $attachment_id . ' postmeta _media_credit updated: ' . $atts['name'] );

			} else {
				
				$this->logger->log( $this->log, 'Img ' . $attachment_id . ' postmeta _media_credit updated: ' . $atts['name'] );
			
				update_post_meta( $attachment_id, '_media_credit', $atts['name'] );

			}

			if ( $this->dry_run ) {
				$this->report( $post_id, $attachment_id, 'insert', $img_postmeta, $atts['name'] );
			}

			return array( null, $atts, $attachment_id );

		}

		$this->logger->log( $this->log, 'Different HTML vs DB credits', $this->logger::WARNING );

		if ( $this->dry_run ) {

			$this->report( $post_id, $attachment_id, 'different', $img_postmeta, $atts['name'] );

		} else {

			// Save these differences to postmeta so Publisher can hand-review and pick the one they want.
			// Since there may be multiple media credits per attachment_id we want to save each one.
			// Use "add post meta" with argument "$unique = false" so multiple values can be saved.
			add_post_meta( $attachment_id, self::POSTMETA_KEY_OTHER_CREDITS, $atts['name'], false );

		}

		return array( null, $atts, $attachment_id );
	}

	/**
	 * Get attachment id from content.
	 * 
	 * Example: <img class="wp-image-1022982 ..." ... />
	 *
	 * @param string $content Content to parse for image id.
	 * @return null|int $attachment_id
	 */
	private function get_attachment_id_from_content( $content ) {

		global $wpdb;

		// Attempt to get image ID.
		if ( false == preg_match( '/wp-image-(\d+)/', $content, $img_match ) ) {

			$this->logger->log(
				$this->log,
				'Media credit missing or external image: ' . $content,
				$this->logger::WARNING
			);

			return;

		}

		// Data integrity for img match.
		if ( 2 != count( $img_match ) || ! is_numeric( $img_match[1] ) || ! ( $img_match[1] > 0 ) ) {

			$this->logger->log(
				$this->log,
				'Media Credit image match error: ' . $content,
				$this->logger::ERROR,
				true
			);

		}

		// Verify ID exists in db.
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = 'attachment' and ID = %d", $img_match[1] ) );

		if ( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {

			$this->logger->log(
				$this->log,
				'Media credit missing db attachment: ' . $content,
				$this->logger::WARNING
			);

			return;

		}
		
		return $attachment_id;
	}

	/**
	 * Store report jsons for custom media credits.
	 *
	 * @param int    $post_id PostID.
	 * @param int    $attachment_id Image ID from posts table.
	 * @param string $compare Postmeta to HTML comparison.
	 * @param string $img_postmeta Existing postmeta medit credit.
	 * @param string $atts_name HTML media credit.
	 * @return void
	 */
	private function report( $post_id, $attachment_id, $compare, $img_postmeta, $atts_name ) {

		if ( ! isset( $this->report[ $attachment_id ] ) ) {
			$this->report[ $attachment_id ] = array();
		}
		
		$lookup = json_encode( array( $compare, $img_postmeta, $atts_name ) );

		// Don't save duplicates.
		foreach ( $this->report[ $attachment_id ] as $arr ) {
			
			// Remove post_id from the comparison.
			array_shift( $arr ); 

			if ( json_encode( $arr ) == $lookup ) {
				return;
			}       
		}

		$this->report[ $attachment_id ][] = array( $post_id, $compare, $img_postmeta, $atts_name );
	}

	/**
	 * Output report.
	 *
	 * @return void
	 */
	private function log_report() {
		
		$this->logger->log( $this->log, '------ Report:' );

		foreach ( $this->report as $attachment_id => $names ) {

			if ( 1 == count( $names ) && 'equal' == $names[0][1] ) {
				continue;
			}
			if ( 1 == count( $names ) && 'insert' == $names[0][1] ) {
				continue;
			}

			$this->logger->log( $this->log, '---- Attachment_id = ' . $attachment_id );

			foreach ( $names as $name ) {

				$this->logger->log( $this->log, 'Post ' . $name[0] . ': ' . $name[1] . ': ' . $name[2] . ' => ' . $name[3] );

			}       
		}
	}
}
