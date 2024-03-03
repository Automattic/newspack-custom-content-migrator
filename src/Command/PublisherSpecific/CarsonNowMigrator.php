<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \DateTimeImmutable;
use DateTimeZone;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\GutenbergBlockManipulator;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use \WP_CLI;
use WP_CLI\ExitException;
use \WP_Post;

class CarsonNowMigrator implements InterfaceCommand {

	const SKIP_IMPORTING_POST = [];

	private array $refresh_existing = [
		'type'        => 'flag',
		'name'        => 'refresh-existing',
		'description' => 'Will refresh existing content rather than create new',
		'optional'    => true,
	];


	/**
	 * @var DateTimeZone
	 */
	private $utc_timezone;

	/**
	 * @var DateTimeZone
	 */
	private $site_timezone;

	const DRUPAL_DATE_FORMAT = 'Y-m-d\TH:i:s';
	const WP_DATE_FORMAT     = 'Y-m-d H:i:s';

	const DEFAULT_EDITOR_USER_ID = 3;
	const DEFAULT_READER_USER_ID = 2;

	const READER_CONTENT_CATEGORY_ID = 2;

	/**
	 * @var DateTimeImmutable
	 */
	private $reader_content_cutoff_date;

	private function __construct() {
		$this->reader_content_cutoff_date = DateTimeImmutable::createFromFormat( self::DRUPAL_DATE_FORMAT, '2023-01-01T00:00:00' );
		$this->logger                     = new Logger();
		$this->gutenberg_block_generator  = new GutenbergBlockGenerator();
		$this->attachments                = new Attachments();

		$this->utc_timezone  = new DateTimeZone( 'UTC' );
		$this->site_timezone = new DateTimeZone( 'America/Los_Angeles' );
	}

	public static function get_instance(): self {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	public function add_fg_hooks(): void {
		// Mappings and pre-registration.
		add_filter( 'fgd2wp_map_taxonomy', [ $this, 'fg_filter_map_taxonomy' ], 11, 3 );
		add_filter( 'fgd2wp_get_node_types', [ $this, 'fg_filter_get_node_types' ], 11, 1 );
		add_filter( 'fgd2wp_pre_register_post_type', [ $this, 'fg_filter_fgd2wp_pre_register_post_type' ], 11, 3 );

		// Massaging the data before inserting.
		add_filter( 'fgd2wp_pre_insert_post', [ $this, 'pre_insert_reader_content' ], 11, 2 );
		add_filter( 'fgd2wp_pre_insert_post', [ $this, 'pre_insert_with_event_date' ], 12, 2 );
		add_filter( 'fgd2wp_pre_insert_post', [ $this, 'pre_insert_set_post_author' ], 15, 2 );

		// Actions
		add_action( 'fgd2wp_post_insert_post', [ $this, 'fg_action_fgd2wp_post_insert_post' ], 11, 5 );
	}


	/**
	 * @throws \Exception
	 */
	public function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator cn-wrap-import',
			[ $this, 'cmd_wrap_drupal_import' ],
			[
				'shortdesc'     => 'Wrap the import command from FG Drupal.',
				'synopsis'      => [],
				'before_invoke' => [ $this, 'preflight_check' ],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cn-import-images',
			[ $this, 'cmd_import_images' ],
			[
				'shortdesc'     => 'Import images.',
				'synopsis'      => [
					$this->refresh_existing,
				],
				'before_invoke' => [ $this, 'preflight_check' ],
			]
		);
	}


	/**
	 * @throws ExitException
	 */
	public function preflight_check(): void {
		static $checked = false;

		if ( $checked ) {
			// It looks like this gets called at least more than once pr. run, so bail if we already checked.
			return;
		}

		if ( ! is_plugin_active( 'newspack-listings/newspack-listings.php' ) ) {
			WP_CLI::error( '"Newspack listings" plugin not found. Install and activate it before using the migration commands.' );
		}

		if ( ! is_plugin_active( 'fg-drupal-to-wp-premium/fg-drupal-to-wp-premium.php' ) ) {
			WP_CLI::error( '"fg-drupal-to-wp-premium" plugin not found. Install and activate it before using the migration commands.' );
		}

		if ( get_option( 'timezone_string', false ) !== $this->site_timezone->getName() ) {
			WP_CLI::error( sprintf( "Site timezone should be '%s'. Make sure it's set correctly before running the migration commands", $this->site_timezone->getName() ) );
		}

		$checked = true;
	}

	public function cmd_import_images( array $pos_args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$refresh              = $assoc_args['refresh-existing'] ?? false;


		foreach ( $this->get_wp_posts_iterator( [ 'post', 'page', 'newspack_lst_event' ] ) as $post ) {
			if ( ! $refresh && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				$this->logger->log( $log_file, sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post->ID ), $command_meta_version ) );
				continue;
			}

			$nid = get_post_meta( $post->ID, '_fgd2wp_old_node_id', true );
			if ( empty( $nid ) ) {
				continue;
			}
			$images      = $this->get_drupal_images_from_nid( $nid );
			$attachments = [];
			foreach ( $images as $img ) {
				$attachments[] = $this->attachments->import_attachment_for_post( $post->ID, $img['url'], '', $img['attributes'] );
			}
			if ( ! empty( $attachments ) ) {
				// First is featured.
				set_post_thumbnail( $post->ID, $attachments[0] );
				add_post_meta( $post->ID, 'newspack_featured_image_position', 'hidden' );
				$post = $this->add_gallery_or_image_to_post( $post, $attachments );
				wp_update_post( $post );
			}
			if ( ! $refresh ) {
				MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
			}
		}
	}

	private function add_gallery_or_image_to_post( WP_Post $post, array $images ): WP_Post {
		if ( empty( $images ) ) {
			return $post;
		}
		if ( count( $images ) > 1 ) {
			$gallery_class               = 'cn-gallery';
			$post->post_content          = serialize_blocks( GutenbergBlockManipulator::remove_blocks_with_class( $gallery_class, $post->post_content ) );
			$block                       = $this->gutenberg_block_generator->get_jetpack_slideshow( $images );
			$block['attrs']['className'] = $gallery_class;
			$gallery                     = serialize_block( $block );
			$post->post_content          = $gallery . $post->post_content;
			$this->logger->log( 'images.log', sprintf( 'Added gallery to post %s', get_permalink( $post ) ) );
		} else { // Some galleries only have one image – in that case we don't need a gallery block.
			$attachment_post = get_post( current( $images ) );
			if ( ! $attachment_post instanceof WP_Post ) {
				return $post;
			}
			$img_block_class    = 'cn-gallery-1-img';
			$post->post_content = serialize_blocks( GutenbergBlockManipulator::remove_blocks_with_class( $img_block_class, $post->post_content ) );
			$img_block          = serialize_block( $this->gutenberg_block_generator->get_image( $attachment_post, 'full', false, $img_block_class ) );
			$post->post_content = $img_block . $post->post_content;
			$this->logger->log( 'images.log', sprintf( 'Added image block to post %s', get_permalink( $post ) ) );
		}

		return $post;
	}

	/**
	 * Run the import.
	 *
	 * We simply wrap the import command from FG Drupal and add our hooks before running the import.
	 * Note that we can't batch this at all, so timeouts might be a thing.
	 */
	public function cmd_wrap_drupal_import( array $pos_args, array $assoc_args ): void {
		add_action( 'fgd2wp_pre_dispatch', [ $this, 'add_fg_hooks' ] );
		// Note that the 'launch' arg is important – without it the hooks above will not be registered.
		WP_CLI::runcommand( 'import-drupal import', [ 'launch' => false ] );
	}

	public function get_dates_from_nid( int $nid ): array {
		$sql = "SELECT field_date_value FROM drupal_content_field_date cfd
					JOIN drupal_node n ON cfd.nid = n.nid AND cfd.vid = n.vid
					WHERE cfd.nid = $nid 
					  AND cfd.field_date_value IS NOT NULL 
					ORDER BY DELTA;";
		global $wpdb;

		$dates = [];
		foreach ( $wpdb->get_col( $sql ) as $date ) {
			$dates[] = DateTimeImmutable::createFromFormat( self::DRUPAL_DATE_FORMAT, $date, $this->utc_timezone )->setTimezone( $this->site_timezone );
		}

		return $dates;
	}

	public function get_image_data_from_fid( $fid, $fallback_to_imagecache = true ): array {

		global $wpdb;
		$result = $wpdb->get_results( "SELECT * FROM drupal_files WHERE fid = $fid", ARRAY_A );
		if ( empty( $result ) ) {
			return [];
		}
		$field_image = $result[0];

		$img_url = 'https://www.carsonnow.org/' . $field_image['filepath'];

		if ( $fallback_to_imagecache ) {
			$request = wp_remote_head( $img_url, [ 'redirection' => 5 ] );
			if ( empty( $request['response']['code'] ) || $request['response']['code'] === 404 ) {
				$img_url = str_replace( '/files/', '/files/imagecache/galleryformatter_slide/', $img_url );
			}
		}

		return [
			'filename'  => $field_image['filename'],
			'url'       => $img_url,
			'timestamp' => $field_image['timestamp'],
		];
	}

	public function get_drupal_images_from_nid( int $nid ) {
		$sql = "SELECT field_images_fid, field_images_data 
					FROM drupal_content_field_images cfi 
					JOIN drupal_node n ON cfi.nid = n.nid AND cfi.vid = n.vid 
					WHERE cfi.nid = $nid
					  AND cfi.field_images_list = 1
					  AND cfi.field_images_fid IS NOT NULL
					ORDER BY delta";
		global $wpdb;
		$rows   = $wpdb->get_results( $sql, ARRAY_A );
		$images = [];
		foreach ( $rows as $row ) {
			$img  = $this->get_image_data_from_fid( $row['field_images_fid'] );
			$data = maybe_unserialize( $row['field_images_data'] );
			if ( ! empty( $data ) ) {
				$img['attributes'] = $data;
			}
			$images[] = $img;
		}

		return $images;
	}


	// Filter and action implementations below.
	public function fg_filter_map_taxonomy( $wp_taxonomy, $taxonomy ) {
		switch ( strtolower( $taxonomy ) ) {
			case 'topics':
				$wp_taxonomy = 'post_tag';
				break;
			case 'businesses':
				$wp_taxonomy = 'category';
				break;
		}

		return $wp_taxonomy;
	}


	public function fg_filter_fgd2wp_pre_register_post_type( $post_type, $node_type ) {
		// Map the reader_content to post.
		if ( 'reader_content' === $node_type ) {
			$post_type = 'post';
		}

		return $post_type;

	}

	public function fg_filter_get_node_types( $node_types ) {
		$types_to_migrate = [
			'story',
			'page',
			'reader_content',
		];

		return array_filter( $node_types, fn( $type ) => in_array( $type, $types_to_migrate ), ARRAY_FILTER_USE_KEY );
	}

	public function pre_insert_reader_content( $new_post, $node ): array {
		if ( empty( $new_post ) || 'reader_content' !== $node['type'] ) {
			return $new_post;
		}

		if ( ! $node['status'] ) {
			// Don't import drafts for this type.
			return [];
		}
		// Make this content type into a post.
		$new_post['post_type'] = 'post';

		// And give it a category.
		$new_post['post_category'][] = self::READER_CONTENT_CATEGORY_ID;

		return $new_post;
	}

	public function pre_insert_with_event_date( $new_post, $node ) {
		if ( empty( $new_post ) ) {
			return $new_post;
		}

		$dates = $this->get_dates_from_nid( $node['nid'] );
		if ( empty( $dates ) ) {
			return $new_post;
		}

		$content     = $new_post['post_content'];
		$date_blocks = [];
		foreach ( $dates as $date ) {
			if ( $date < $this->reader_content_cutoff_date ) {
				return self::SKIP_IMPORTING_POST;
			}

			$block         = serialize_block( [
				'blockName'    => 'newspack-listings/event-dates',
				'attrs'        => [
					'startDate' => $date->format( self::WP_DATE_FORMAT ),
					'showTime'  => true,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			] );
			$date_blocks[] = $block;
		}

		$content = implode( "\n", $date_blocks ) . $content;

		$new_post['post_content'] = $content;
		$new_post['post_type']    = 'newspack_lst_event';

		return $new_post;
	}

	private function should_fix_author( $new_post ) {
		static $authors_to_fix = null;
		if ( null === $authors_to_fix ) {
			$admin  = get_user_by( 'login', 'admin' );
			$adminn = get_user_by( 'login', 'adminnewspack' );
			if ( ! $admin instanceof \WP_User || ! $adminn instanceof \WP_User ) {
				WP_CLI::error( 'Could not find admin users. Are they set up correctly?' );
			}
			$authors_to_fix = [ $admin->ID, $adminn->ID ];
		}

		return empty( $new_post['post_author'] ) || in_array( $new_post['post_author'], $authors_to_fix );
	}

	public function pre_insert_set_post_author( $new_post, $node ) {
		if ( empty( $new_post ) || ! $this->should_fix_author( $new_post ) ) {
			return $new_post;
		}

		$new_post['post_author'] = ( 'reader_content' === $node['type'] ) ? self::DEFAULT_READER_USER_ID : self::DEFAULT_EDITOR_USER_ID;

		return $new_post;
	}

	public function fg_action_fgd2wp_post_insert_post( $new_post_id, $node, $post_type, $entity_type ): void {
		$old_url = trailingslashit( $this->get_fg_options( 'url' ) ) . 'node/' . $node['nid'];
		$new_url = get_permalink( $new_post_id );
		$this->logger->log(
			'imported-posts.log',
			sprintf(
				"Migrated '%s' %s to %s as '%s'",
				$node['type'],
				$old_url,
				$new_url,
				$post_type
			)
		);
	}

	private function get_fg_options( string $key = '' ) {
		static $options = null;
		if ( null === $options ) {
			$options = get_option( 'fgd2wp_options' );
		}
		if ( empty( $key ) ) {
			return $options;
		}

		return $options[ $key ] ?? '';
	}

	private function get_wp_posts_iterator( array $post_types, array $post_statuses = [ 'publish' ], array $args = [], bool $log_progress = true ): iterable {
		if ( ! empty( $args['post-id'] ) ) {
			$all_ids = [ $args['post-id'] ];
		} else {
			global $wpdb;
			$post_type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$post_status_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
			$all_ids                  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type IN ( $post_type_placeholders )
			AND post_status IN ( $post_status_placeholders ) ;",
					[ ...$post_types, ...$post_statuses ],
				)
			);
			if ( ! empty( $args['num-posts'] ) ) {
				$all_ids = array_slice( $all_ids, 0, $args['num-posts'] );
			}
		}
		$total_posts = count( $all_ids );
		$home_url    = home_url();
		$counter     = 0;
		if ( $log_progress ) {
			WP_CLI::log( sprintf( 'Processing %d posts', count( $all_ids ) ) );
		}

		foreach ( $all_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				if ( $log_progress ) {
					WP_CLI::log( sprintf( 'Processing post %d/%d: %s', ++$counter, $total_posts, "${home_url}?p=${post_id}" ) );
				}
				yield $post;
			}
		}
	}
}
