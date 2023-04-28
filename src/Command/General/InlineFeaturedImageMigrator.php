<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
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
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
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
			'newspack-content-migrator de-dupe-featured-images',
			array( $this, 'cmd_de_dupe_featured_images' ),
			[
				'shortdesc' => 'Goes through all the Posts, and removes the first image from Post content if that image is already used as the Featured image too.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
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
	}

	/**
	 * Callable for the set-first-image-from-content-as-featured-image command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_set_first_image_from_content_as_featured_image( $pos_args, $assoc_args ) {
		global $wpdb;

		$log = 'set_first_image_from_content_as_featured_image.log';

		// Get all posts without featured image.
		$post_ids_wo_featured_img = $wpdb->get_col( "SELECT ID FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish' AND ID NOT IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = 'a_thumbnail_id' ) ORDER BY ID DESC ;" );
		if ( empty( $post_ids_wo_featured_img ) ) {
			WP_CLI::line( 'No posts without featured image found.' );
			exit;
		}

		$crawler          = new Crawler();
		$attachment_logic = new AttachmentsLogic();
		foreach ( $post_ids_wo_featured_img as $k => $post_id ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) ID %d ...', $k + 1, count( $post_ids_wo_featured_img ), $post_id ) );

			$post = get_post( $post_id );

			// Find the first <img>.
			$crawler->clear();
			$crawler->add( $post->post_content );
			$img_data  = $crawler->filterXpath( '//img' )->extract( [ 'src', 'title', 'alt' ] );
			$img_src   = $img_data[0][0] ?? null;
			$img_title = $img_data[0][1] ?? null;
			$img_alt   = $img_data[0][2] ?? null;
			if ( ! $img_src ) {
				WP_CLI::line( '× skipping, no images found in Post' );
				continue;
			}

			// Check if there's already an attachment with this image.
			$is_src_fully_qualified = ( 0 == strpos( $img_src, 'http' ) );
			if ( ! $is_src_fully_qualified ) {
				$this->logger->log( $log, sprintf( 'postID %d ERROR skipping -- src %s is not a fully qualified URL', $post_id, $img_src ), Logger::WARNING );
				continue;
			}

			// Import attachment if it doesn't exist.
			$att_id     = attachment_url_to_postid( $img_src );
			$attachment = get_post( $att_id );
			if ( ! $attachment ) {
				$this->logger->log( $log, sprintf( 'postID %d attachment not found, downloading %s', $post_id, $img_src ) );
				$att_id = $attachment_logic->import_external_file( $img_src, $img_title, $img_alt, $description = null, $img_alt, $post_id );
				if ( is_wp_error( $att_id ) ) {
					$this->logger->log( $log, sprintf( 'postID %d ERROR importing img %s : %s', $post_id, $img_src, $att_id->get_error_message() ), Logger::WARNING );
					continue;
				}
			}

			// Set attachment as featured image.
			$result_featured_set = set_post_thumbnail( $post, $att_id );
			if ( ! $result_featured_set ) {
				$this->logger->log( $log, sprintf( 'postID %d ERROR could not set att.ID %s as featured image', $post_id, $att_id ), Logger::WARNING );
			} else {
				$this->logger->log( $log, sprintf( 'postID %d att.ID %s set as featured image', $post_id, $att_id ), Logger::SUCCESS );
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌. See %s', $log ) );
	}

	/**
	 * Callable for de-dupe-featured-images command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_de_dupe_featured_images( $pos_args, $assoc_args ) {

		if ( ! isset( $assoc_args['post-ids'] ) ) {
			$post_ids = get_posts(
				[
					'post_type'      => 'post',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			);
		}

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );

		$started = time();

		foreach ( $post_ids as $id ) {
			$thumbnail_id = get_post_thumbnail_id( $id );
			if ( ! $thumbnail_id ) {
				continue;
			}

			$regex    = '#\s*(<!-- wp:image[^{]*{[^}]*"id":' . absint( $thumbnail_id ) . '.*\/wp:image -->)#isU';
			$content  = get_post_field( 'post_content', $id );
			$replaced = preg_replace( $regex, '', $content, 1 );

			// If we are unable to find the featured images by ID, see if we can use the image URL.
			if ( $content === $replaced ) {
				$image_src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
				if ( ! $image_src ) {
					continue;
				}

				$image_path = wp_parse_url( $image_src[0] )['path'];
				$image_path = explode( '.', $image_path )[0]; // Remove media extension (jpg, etc.).

				$src_regex = '#<!-- wp:image.*' . addslashes( $image_path ) . '.*\/wp:image -->#isU';
				$replaced  = preg_replace( $src_regex, '', $content, 1 );
			}

			// If still no luck, see if we can use the attachment page.
			if ( $content === $replaced ) {
				$image_page = get_permalink( $thumbnail_id );
				if ( ! $image_page ) {
					continue;
				}

				$page_path = wp_parse_url( $image_page )['path'];

				$page_regex = '#<!-- wp:image.*' . addslashes( $page_path ) . '.*\/wp:image -->#isU';
				$replaced   = preg_replace( $page_regex, '', $content, 1 );
			}

			if ( $content != $replaced ) {
				$updated = [
					'ID'           => $id,
					'post_content' => $replaced,
				];
				$result  = wp_update_post( $updated );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning(
						sprintf(
							'Failed to update post #%d because %s',
							$id,
							$result->get_error_messages()
						)
					);
				} else {
					WP_CLI::success( sprintf( 'Updated #%d', $id ) );
				}
			}
		}

		WP_CLI::line(
			sprintf(
				'Finished processing %d records in %d seconds',
				count( $post_ids ),
				time() - $started
			)
		);

	}

}
