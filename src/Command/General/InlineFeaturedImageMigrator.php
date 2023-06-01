<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;
use \WP_Query;
use Symfony\Component\DomCrawler\Crawler as Crawler;

/**
 * InlineFeaturedImageMigrator.
 */
class InlineFeaturedImageMigrator implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * PostLogic Instance.
	 *
	 * @var $post_logic PostLogic.
	 */
	private $post_logic;

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->post_logic = new PostLogic();
		$this->logger     = new Logger();
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
			'newspack-content-migrator remove-featured-images-from-beginning-of-postcontent',
			[ $this, 'cmd_remove_featured_images_from_beginning_of_postcontent' ],
			[
				'shortdesc' => 'Goes through all the Posts, and removes all occurrences of featured image from beginning of Post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Post IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator set-first-image-from-content-as-featured-image',
			[ $this, 'cmd_set_first_image_from_content_as_featured_image' ],
			[
				'shortdesc' => "Runs through all the Posts, and in case it doesn't have a featured image, finds the first <img> element in Post content and sets it as featured image.",
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator hide-featured-image-if-used-in-post-content',
			[ $this, 'cmd_hide_featured_image_if_used_in_post_content' ],
			[
				'shortdesc' => 'Hides featured image for post if that same image is used anywhere in post_content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Post IDs to process.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Process post IDs from this ID.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Process post IDs to this ID.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator hide-featured-image-if-used-in-post-content` command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_hide_featured_image_if_used_in_post_content( $pos_args, $assoc_args ) {
		$post_ids     = isset( $assoc_args['post-ids-csv'] ) && ! empty( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from = isset( $assoc_args['post-id-from'] ) && ! empty( $assoc_args['post-id-from'] ) ? $assoc_args['post-id-from'] : null;
		$post_id_to   = isset( $assoc_args['post-id-to'] ) && ! empty( $assoc_args['post-id-to'] ) ? $assoc_args['post-id-to'] : null;

		// If --post-ids-csv, can't use --post-id-from and --post-id-to.
		if ( $post_ids && ( $post_id_from || $post_id_to ) ) {
			WP_CLI::error( "Can't use both --post-ids-csv and --post-id-from/--post-id-to." );
		}

		// If --post-id-from used, must use --post-id-to too.
		if ( ( $post_id_from && ! $post_id_to ) || ( ! $post_id_from && $post_id_to ) ) {
			WP_CLI::error( 'Muse provide both --post-id-from and --post-id-to.' );
		}

		global $wpdb;
		$log = 'hide-featured-image-if-used-in-post-content.log';

		// Will first use --post-ids-csv if provided.
		if ( $post_ids ) {
			WP_CLI::line( 'Checking provided --post-ids-csv...' );
		}

		// Get post ID range if specified.
		if ( ! $post_ids && ( $post_id_from && $post_id_to ) ) {
			WP_CLI::line( sprintf( 'Fetching post IDs in range between %d and %d...', $post_id_from, $post_id_to ) );
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select ID
					from {$wpdb->posts}
					where post_type = 'post'
					and post_status IN ( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ) 
					and ID >= %d
					and ID <= %d ;",
					$post_id_from,
					$post_id_to
				)
			);
			if ( empty( $post_ids ) ) {
				WP_CLI::error( 'No post IDs found in range.' );
			}
		}

		// Use all posts if no post IDs specified.
		if ( ! $post_ids ) {
			WP_CLI::line( 'Fetching all post IDs...' );
			$post_ids = $this->post_logic->get_all_posts_ids();
		}

		// Go through IDs.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post_id_exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			if ( ! $post_id_exists ) {
				$this->logger->log( $log, sprintf( 'Post ID does not exist %d', $post_id ), $this->logger::WARNING );
				continue;
			}

			// Get featured image ID.
			$thumbnail_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'", $post_id ) );
			if ( ! $thumbnail_id ) {
				$this->logger->log( $log, sprintf( 'Post has no featured image %d', $post_id ) );
				continue;
			}

			// Get post_content.
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			if ( ! $post_content ) {
				$this->logger->log( $log, sprintf( 'Post has no post_content %d', $post_id ) );
				continue;
			}

			// Get featured image URL.
			$featured_image_url                  = wp_get_attachment_url( $thumbnail_id );
			$featured_image_url_no_host          = str_replace( home_url(), '', $featured_image_url );
			$featured_image_used_in_post_content = false !== strpos( $post_content, $featured_image_url_no_host );
			if ( ! $featured_image_used_in_post_content ) {
				$this->logger->log( $log, sprintf( 'Featured image not found in post_content %d', $post_id ) );
				continue;
			}

			// Set featured image as hidden if it's used in post_content.
			if ( $featured_image_used_in_post_content ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
				$this->logger->log( $log, sprintf( 'Featured image hidden %d', $post_id ) );
				continue;
			}

			$this->logger->log( $log, sprintf( 'No changes to post %d', $post_id ) );
		}

		WP_CLI::line( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Callable for the set-first-image-from-content-as-featured-image command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_set_first_image_from_content_as_featured_image( $args, $assoc_args ) {
		$time_start = microtime( true );

		$posts_wo_featured_img_query = new WP_Query(
			[
				'meta_query'     => [
					[
						'key'     => '_thumbnail_id',
						'value'   => '?',
						'compare' => 'NOT EXISTS',
					],
				],
				'posts_per_page' => -1,
			]
		);
		$posts_wo_featured_img       = $posts_wo_featured_img_query->get_posts();
		if ( empty( $posts_wo_featured_img ) ) {
			WP_CLI::line( 'No posts without featured image found.' );
			exit;
		}

		$crawler          = new Crawler();
		$attachment_logic = new AttachmentsLogic();
		foreach ( $posts_wo_featured_img as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) ID %d ...', $k + 1, count( $posts_wo_featured_img ), $post->ID ) );

			// Find the first <img>.
			$crawler->clear();
			$crawler->add( $post->post_content );
			$img_data  = $crawler->filterXpath( '//img' )->extract( [ 'src', 'title', 'alt' ] );
			$img_src   = $img_data[0][0] ?? null;
			$img_title = $img_data[0][1] ?? null;
			$img_alt   = $img_data[0][2] ?? null;
			if ( ! $img_src ) {
				WP_CLI::line( '× no images found in Post.' );
				continue;
			}

			// Check if there's already an attachment with this image.
			$is_src_fully_qualified = ( 0 == strpos( $img_src, 'http' ) );
			if ( ! $is_src_fully_qualified ) {
				WP_CLI::line( sprintf( '× skipping, img src `%s` not fully qualified URL', $img_src ) );
				continue;
			}

			// Import attachment if it doesn't exist.
			$att_id     = attachment_url_to_postid( $img_src );
			$attachment = get_post( $att_id );
			if ( $attachment ) {
				WP_CLI::line( sprintf( '✓ found img %s as att. ID %d', $img_src, $att_id ) );
			} else {
				WP_CLI::line( sprintf( '- importing img `%s`...', $img_src ) );
				$att_id = $attachment_logic->import_external_file( $img_src, $img_title, $img_alt, $description = null, $img_alt, $post->ID );
				if ( is_wp_error( $att_id ) ) {
					WP_CLI::warning( sprintf( '❗ error importing img `%s` : %s', $img_src, $att_id->get_error_message() ) );
					continue;
				}
			}

			// Set attachment as featured image.
			$result_featured_set = set_post_thumbnail( $post, $att_id );
			if ( ! $result_featured_set ) {
				WP_CLI::warning( sprintf( '❗ could not set att.ID %s as featured image', $att_id ) );
			} else {
				WP_CLI::line( sprintf( '👍 set att.ID %s as featured image', $att_id ) );
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for remove-featured-images-from-beginning-of-postcontent command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_remove_featured_images_from_beginning_of_postcontent( $args, $assoc_args ) {
		$post_ids = isset( $assoc_args['post-ids-csv'] )
			? explode( ',', $assoc_args['post-ids-csv'] )
			: $this->post_logic->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending' ] );

		global $wpdb;
		$log_dir = 'logs_de_duped_featured_images';

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );
		foreach ( $post_ids as $key_id => $id ) {
			WP_CLI::log( sprintf( '(%d)(%d) %d', $key_id + 1, count( $post_ids ), $id ) );

			$thumbnail_id = get_post_thumbnail_id( $id );
			if ( ! $thumbnail_id ) {
				continue;
			}

			/**
			 * Replacement 1: Search for image block of featured image in post content by referencing attachment ID.
			 */
			$regex    = '#^(<!-- wp:image[^{]*{[^}]*"id":' . absint( $thumbnail_id ) . '.*\/wp:image -->)#isU';
			$content  = get_post_field( 'post_content', $id );
			$replaced = preg_replace( $regex, '', $content, 1 );

			/**
			 * Replacement 2: Search for image block of featured image in post content by referencing the image URL.
			 */
			if ( $content === $replaced ) {
				$image_src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
				if ( ! $image_src ) {
					continue;
				}

				$image_path = wp_parse_url( $image_src[0] )['path'];
				$image_path = explode( '.', $image_path )[0]; // Remove media extension (jpg, etc.).

				$src_regex = '#^<!-- wp:image.*' . addslashes( $image_path ) . '.*\/wp:image -->#isU';
				$replaced  = preg_replace( $src_regex, '', $content, 1 );
			}

			/**
			 * Replacement 3: If still no luck, search for image block via the attachment page.
			 */
			if ( $content === $replaced ) {
				$image_page = get_permalink( $thumbnail_id );
				if ( ! $image_page ) {
					continue;
				}

				$page_path = wp_parse_url( $image_page )['path'];

				$page_regex = '#^<!-- wp:image.*' . addslashes( $page_path ) . '.*\/wp:image -->#isU';
				$replaced   = preg_replace( $page_regex, '', $content, 1 );
			}

			/**
			 * Replacement 4: Search for HTML img elements with relative source.
			 */
			if ( $content === $replaced ) {
				$src = wp_parse_url( $image_src[0] )['path'];
				/**
				 * - [^>] means "not a closing bracket".
				 * - [^"] means "not a double quote".
				 * - [^"]* means "zero or more characters that are not a double quote".
				 * - #isU means "case insensitive, single line".
				 */
				$img_element_regex = '#^<img[^>]*src="' . addslashes( $src ) . '[^"]*"[^>]*>#isU';
				$replaced          = preg_replace( $img_element_regex, '', $content, 1 );
			}

			// Persist.
			if ( $content != $replaced ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $replaced ],
					[ 'ID' => $id ]
				);
				WP_CLI::success( 'Updated' );

				// Log before and after for easier debugging.
				// Create directory "logs" if it doesn't exist.
				if ( ! file_exists( $log_dir ) ) {
					// phpcs:ignore
					$created = mkdir( $log_dir, 0777, true );
				}
				$this->logger->log( sprintf( '%s/%d_before.txt', $log_dir, $id ), $content, false );
				$this->logger->log( sprintf( '%s/%d_after.txt', $log_dir, $id ), $replaced, false );
			}
		}

		WP_CLI::line( sprintf( 'Finished. See %s/ folder for logs.', $log_dir ) );
	}
}
