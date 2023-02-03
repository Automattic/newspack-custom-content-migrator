<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \WP_CLI;
use \WP_Query;
use Symfony\Component\DomCrawler\Crawler as Crawler;

class FeaturedImagesMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator de-dupe-featured-images', array( $this, 'cmd_de_dupe_featured_images' ), [
			'shortdesc' => "Goes through all the Posts, and removes the first image from Post content if that image is already used as the Featured image too.",
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'post-ids',
					'description' => 'Post IDs to migrate.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
		WP_CLI::add_command(
			'newspack-content-migrator set-first-image-from-content-as-featured-image',
			[ $this, 'cmd_set_first_image_from_content_as_featured_image' ],
			[
				'shortdesc' => "Runs through all the Posts, and in case it doesn't have a featured image, finds the first <img> element in Post content and sets it as featured image by either finding it in the DB or by downloading and importing it from HTTP.",
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator set-featured-images-from-first-attachment',
			[ $this, 'cmd_set_first_image_from_attachment_as_featured_image' ],
			[
				'shortdesc' => "Runs through all the Posts, and resets featured image as the first attachment image that has this post as parent.",
			]
		);
	}

	/**
	 * Callable for the set-first-image-from-content-as-featured-image command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_set_first_image_from_content_as_featured_image( $args, $assoc_args ) {
		$time_start = microtime( true );

		$posts_wo_featured_img_query = new WP_Query([
			'meta_query' => [
				[
					'key' => '_thumbnail_id',
					'value' => '?',
					'compare' => 'NOT EXISTS'
				]
			],
			'posts_per_page' => -1,
		]);
		$posts_wo_featured_img = $posts_wo_featured_img_query->get_posts();
		if ( empty( $posts_wo_featured_img ) ) {
			WP_CLI::line( 'No posts without featured image found.' );
			exit;
		}

		$crawler = new Crawler();
		$attachment_logic = new AttachmentsLogic();
		foreach ( $posts_wo_featured_img as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) ID %d ...', $k + 1, count( $posts_wo_featured_img ), $post->ID ) );

			// Find the first <img>.
			$crawler->clear();
			$crawler->add( $post->post_content );
			$img_data =  $crawler->filterXpath( '//img' )->extract( [ 'src', 'title', 'alt' ] );
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
			$att_id = attachment_url_to_postid( $img_src );
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
	 * Callable for de-dupe-featured-images command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_de_dupe_featured_images( $args, $assoc_args ) {

		if ( ! isset( $assoc_args[ 'post-ids' ] ) ) {
			$post_ids = get_posts( [
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );
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
				$replaced = preg_replace( $src_regex, '', $content, 1 );
			}

			// If still no luck, see if we can use the attachment page.
			if ( $content === $replaced ) {
				$image_page = get_permalink( $thumbnail_id );
				if ( ! $image_page ) {
					continue;
				}

				$page_path = wp_parse_url( $image_page )['path'];

				$page_regex = '#<!-- wp:image.*' . addslashes( $page_path ) . '.*\/wp:image -->#isU';
				$replaced = preg_replace( $page_regex, '', $content, 1 );
			}

			if ( $content != $replaced ) {
				$updated = [
					'ID'           => $id,
					'post_content' => $replaced
				];
				$result = wp_update_post( $updated );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf(
						'Failed to update post #%d because %s',
						$id,
						$result->get_error_messages()
					) );
				} else {
					WP_CLI::success( sprintf( 'Updated #%d', $id ) );
				}
			}
		}

		WP_CLI::line( sprintf(
			'Finished processing %d records in %d seconds',
			count( $post_ids ),
			time() - $started
		) );

	}

	/**
	 * Callable for newspack-content-migrator set-featured-images-from-first-attachment command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_set_first_image_from_attachment_as_featured_image( $args, $assoc_args ) {
		WP_CLI::line( sprintf( "Setting Posts' featured images from first image attachment..." ) );

		global $wpdb;
		$image_attachments_with_parents = $wpdb->get_results(
			"SELECT wp.ID, wp.post_parent
			FROM {$wpdb->posts} wp
			JOIN {$wpdb->posts} wp2
			ON wp2.ID = wp.post_parent AND wp2.post_type = 'post'
			WHERE wp.post_type = 'attachment'
			AND wp.post_mime_type LIKE 'image/%'
			GROUP BY wp.post_parent;"
		);
		WP_CLI::line( sprintf( 'Found %s attachment images with parent posts set.', count( $image_attachments_with_parents ) ) );
		foreach ( $image_attachments_with_parents as $key_image_attachments_with_parents => $attachment ) {
			$parent_id     = $attachment->post_parent;
			$attachment_id = $attachment->ID;
			if ( ! has_post_thumbnail( $parent_id ) ) {
				set_post_thumbnail( $parent_id, $attachment_id );
				WP_CLI::line( sprintf( '(%d/%d) Set featured image on post %s from attachment %s.', $key_image_attachments_with_parents + 1, count( $image_attachments_with_parents ), $parent_id, $attachment_id ) );
			} else {
				WP_CLI::line( sprintf( '(%d/%d) Skipping, Post %d already has a featured image %d.', $key_image_attachments_with_parents + 1, count( $image_attachments_with_parents ), $parent_id, $attachment_id ) );
			}
		}
	}

}
