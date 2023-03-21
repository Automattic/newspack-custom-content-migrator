<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use \WP_CLI;

/**
 * Custom migration scripts for LkldNow.
 */
class IndyWeekMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments = new Attachments();
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
			'newspack-content-migrator indyweek-import-prints',
			[ $this, 'cmd_indyweek_import_prints' ],
			[
				'shortdesc' => 'Import the prints of Indy Week from a JSON file to Generic Listings.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'json-file-path',
						'description' => 'JSON file path containing the prints.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator indyweek-fix-prints-featured-image',
			[ $this, 'cmd_indyweek_fix_prints_featured_image' ],
			[
				'shortdesc' => 'Fix print editions featured images.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator indyweek-fix-puzzles-links',
			[ $this, 'cmd_indyweek_fix_puzzles_links' ],
			[
				'shortdesc' => 'Fix Puzzles media links.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator indyweek-fix-inline-images',
			[ $this, 'cmd_indyweek_fix_inline_images' ],
			[
				'shortdesc' => 'Fix inline images inside post content.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-import-prints`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_import_prints( $args, $assoc_args ) {
		$json_file = $assoc_args['json-file-path'];

		if ( ! file_exists( $json_file ) ) {
			WP_CLI::error( 'The provided file does not exist.' );
		}

		$file_content = file_get_contents( $json_file );

		$json_data = json_decode( $file_content );

		if ( ! $json_data ) {
			WP_CLI::error( 'The JSON file is invalid.' );
		}

		$prints = $json_data->items;

		$category_id = get_terms(
			array(
				'fields'     => 'ids',
				'taxonomy'   => 'category',
				'name'       => 'Print Edition',
				'hide_empty' => false,
			)
		)[0];

		$base_url = 'https://issuu.com/indyweeknc/docs/';

		$print_content = <<<HTML
<!-- wp:paragraph -->
<p><a href="%s" target="_blank" rel="noreferrer noopener">Click here to access</a></p>
<!-- /wp:paragraph -->
HTML;

		foreach ( $prints as $print ) {
			WP_CLI::log( 'Adding print ' . $print->title );
			$post_args = array(
				'post_title'    => $print->title,
				'post_date'     => $print->publishDate,
				'post_content'  => sprintf( $print_content, $base_url . $print->uri ),
				'post_type'     => 'newspack_lst_generic',
				'post_category' => array( $category_id ),
				'post_status'   => 'publish',
			);

			$post_id = wp_insert_post( $post_args, true );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( 'Could not add print ' . $print->title );
				continue;
			}

			WP_CLI::log( 'Downloading thumbnail...' );
			$thumbnail_id = $this->attachments->import_external_file( $print->coverUrl );

			set_post_thumbnail( $post_id, $thumbnail_id );
			WP_CLI::log( 'Print ' . $print->title . ' has been added.' );
		}

		WP_CLI::success( 'Done!' );
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-prints-featured-image`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_prints_featured_image( $args, $assoc_args ) {
		$query = new \WP_Query(
			[
				'post_type'      => 'newspack_lst_generic',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'cat'            => 8,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$posts = $query->get_posts();
		array_shift( $posts );

		foreach ( $posts as $index => $post ) {
			$fixed_index = $index + 3;
			if ( isset( $posts[ $fixed_index ] ) ) {
				set_post_thumbnail( $post->ID, get_post_thumbnail_id( $posts[ $fixed_index ]->ID ) );
				WP_CLI::success( sprintf( 'Post fixed: %d', $post->ID ) );
			} else {
				WP_CLI::warning( sprintf( 'Post to be fixed manually: %d', $post->ID ) );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-puzzles-links`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_puzzles_links( $args, $assoc_args ) {
		$page = get_post( 162921 );
		preg_match_all( '|(?P<url>https://indyweek\.com/downloads/\d+/download/(?P<attachment_name>.*?))"|', $page->post_content, $download_url_matches );

		$updated_content = $page->post_content;

		foreach ( $download_url_matches['url'] as $index => $download_url ) {
			if ( isset( $download_url_matches['attachment_name'][ $index ] ) ) {
				$original_name   = $download_url_matches['attachment_name'][ $index ];
				$attachment_name = urldecode( $original_name );
				$attachment_name = explode( '?', $attachment_name )[0];
				$attachment_name = str_replace( [ '.jpg', '.pdf' ], '', $attachment_name );
				$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

				if ( ! $attachment ) {
					$attachment_name = str_replace( ' ', '-', urldecode( $attachment_name ) );
					$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

					if ( ! $attachment ) {
						$attachment_name = str_replace( [ '[1].', '-(1).' ], '.', $attachment_name );
						$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

						if ( ! $attachment ) {
							print_r( "No attachment for $attachment_name: $original_name \n" );
							continue;
						}
					}
				}

				$updated_content = str_replace( $download_url, wp_get_attachment_url( $attachment ), $updated_content );
				wp_update_post(
					array(
						'ID'           => $page->ID,
						'post_content' => $updated_content,
					)
				);

				wp_cache_flush();
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-inline-images`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_inline_images( $args, $assoc_args ) {
		$query = new \WP_Query(
            [
				'p'              => 164709,
				'post_type'      => 'post',
				'posts_per_page' => -1,
            ]
		);

		$posts                          = $query->get_posts();
		$posts_with_more_than_one_image = 0;

		foreach ( $posts as $post ) {
			$content_blocks    = parse_blocks( $post->post_content );
			$image_block_count = 0;
			foreach ( $content_blocks as $content_block ) {
				if ( 'core/image' === $content_block['blockName'] ) {
					$image_block_count++;
				}
			}

			if ( $image_block_count > 1 ) {
				$posts_with_more_than_one_image++;
				print_r( $post->ID . " has $image_block_count images.\n" );
				die();
			}
		}

		print_r( "$posts_with_more_than_one_image posts with more than one image in their content.\n" );
	}

	/**
	 * Get attachment ID by it's filename
	 *
	 * @param string $filename attachment filename.
	 * @return int|false
	 */
	private function get_attachment_id_by_filename( $filename ) {
		global $wpdb;
		$sql         = $wpdb->prepare( "SELECT * FROM  $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value like %s", "%$filename%" );
		$attachments = $wpdb->get_results( $sql );
		return $attachments[0]->post_id ?? false;
	}
}
