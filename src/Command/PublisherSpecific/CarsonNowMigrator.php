<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\GutenbergBlockManipulator;
use NewspackCustomContentMigrator\Utils\Logger;
use Newspack\MigrationTools\Util\MigrationMeta;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Post;

class CarsonNowMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	const SKIP_IMPORTING_POST = [];

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

	/**
	 * @throws ExitException
	 */
	private function __construct() {
		$this->reader_content_cutoff_date = DateTimeImmutable::createFromFormat( self::DRUPAL_DATE_FORMAT, '2023-01-01T00:00:00' );
		$this->logger                     = new Logger();
		$this->gutenberg_block_generator  = new GutenbergBlockGenerator();
		$this->attachments                = new Attachments();

		$this->utc_timezone  = new DateTimeZone( 'UTC' );
		$this->site_timezone = new DateTimeZone( 'America/Los_Angeles' );

		$required_plugins = [
			'newspack-listings',
			'fg-drupal-to-wp-premium',
			'newspack-content-byline',
		];

		array_map(
			function ( $plugin ) {
				if ( ! is_plugin_active( "$plugin/$plugin.php" ) ) {
					WP_CLI::error( sprintf( '"%s" plugin not found. Install and activate it before using the migration commands.', $plugin ) );
				}
			},
			$required_plugins
		);

		if ( get_option( 'timezone_string', false ) !== $this->site_timezone->getName() ) {
			WP_CLI::error( sprintf( "Site timezone should be '%s'. Make sure it's set correctly before running the migration commands", $this->site_timezone->getName() ) );
		}

		if ( ! defined( 'NCCM_SOURCE_WEBSITE_URL' ) ) {
			WP_CLI::error( 'NCCM_SOURCE_WEBSITE_URL is not defined in wp-config.php' );
		}
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
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {

		$refresh_existing_arg = [
			'type'        => 'flag',
			'name'        => 'refresh-existing',
			'description' => 'Will refresh existing content rather than create new',
			'optional'    => true,
		];

		$num_posts_arg = [
			'type'        => 'assoc',
			'name'        => 'num-posts',
			'description' => 'Number of posts to process',
			'optional'    => true,
		];

		$max_post_id_arg = [
			'type'        => 'assoc',
			'name'        => 'max-post-id',
			'description' => 'Maximum post ID to include in run (inclusive).',
			'optional'    => true,
		];

		$min_post_id_arg = [
			'type'        => 'assoc',
			'name'        => 'min-post-id',
			'description' => 'Minimum post ID to include in run (inclusive).',
			'optional'    => true,
		];

		WP_CLI::add_command(
			'newspack-content-migrator cn-wrap-import',
			self::get_command_closure( 'cmd_wrap_drupal_import' ),
			[
				'shortdesc'     => 'Wrap the import command from FG Drupal.',
				'synopsis'      => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cn-add-inline-bylines',
			self::get_command_closure( 'cmd_add_inline_bylines' ),
			[
				'shortdesc'     => 'Add inline bylines.',
				'synopsis'      => [
					$refresh_existing_arg,
					$num_posts_arg,
					$min_post_id_arg,
					$max_post_id_arg,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cn-import-images',
			self::get_command_closure( 'cmd_import_images' ),
			[
				'shortdesc'     => 'Import images.',
				'synopsis'      => [
					$refresh_existing_arg,
					$num_posts_arg,
					$min_post_id_arg,
					$max_post_id_arg,
				],
			]
		);
	}

	/**
	 * Add the byline to the content if there is one.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_add_inline_bylines( array $pos_args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$refresh              = $assoc_args['refresh-existing'] ?? false;

		foreach ( $this->get_wp_posts_iterator( [ 'post', 'page', 'newspack_lst_event' ], $assoc_args ) as $post ) {
			if ( ! $refresh && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log( sprintf( 'Post ID %d %s is at MigrationMeta version %s, skipping', $post->ID, get_permalink( $post->ID ), $command_meta_version ) );
				continue;
			}

			$nid = get_post_meta( $post->ID, '_fgd2wp_old_node_id', true );
			if ( empty( $nid ) ) {
				continue;
			}

			$drupal_byline = $this->get_drupal_byline( $nid );
			if ( empty( $drupal_byline ) || $drupal_byline === get_the_author_meta( 'display_name', $post->post_author ) ) {
				MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
				continue;
			}

			$post_blocks = parse_blocks( $post->post_content );
			if ( GutenbergBlockManipulator::find_blocks_with_class( \Newspack_Content_Byline::BYLINE_BLOCK_CLASS_NAME, $post_blocks ) ) {
				// Remove existing byline blocks if any.
				$post_blocks = GutenbergBlockManipulator::remove_blocks_with_class( \Newspack_Content_Byline::BYLINE_BLOCK_CLASS_NAME, $post->post_content );
			}
			// Add the byline block to the beginning of the content.
			array_unshift( $post_blocks, \Newspack_Content_Byline::get_post_meta_bound_byline_block() );

			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => serialize_blocks( $post_blocks ),
				] 
			);
			// Set the byline as metadata too.
			update_post_meta( $post->ID, \Newspack_Content_Byline::BYLINE_META_KEY, $drupal_byline );

			$this->logger->log( $log_file, sprintf( '%d Added a byline "%s" in %s', $post->ID, $drupal_byline, get_permalink( $post ) ) );
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}
	}

	/**
	 * Get the drupal byline for a given node.
	 *
	 * @param int $nid
	 *
	 * @return string byline
	 */
	private function get_drupal_byline( int $nid ): string {
		$prefix = $this->get_prefix();
		$sql    = "SELECT field_byline_value FROM {$prefix}content_field_byline cfb
					JOIN drupal_node n ON cfb.nid = n.nid AND cfb.vid = n.vid
					WHERE cfb.nid = $nid";
		global $wpdb;

		$byline = $wpdb->get_var( $sql );

		return empty( $byline ) ? '' : trim( $byline );
	}

	public function cmd_import_images( array $pos_args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$refresh              = $assoc_args['refresh-existing'] ?? false;

		foreach ( $this->get_wp_posts_iterator( [ 'post', 'page', 'newspack_lst_event' ], $assoc_args ) as $post ) {
			if ( ! $refresh && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log( sprintf( 'Post ID %d %s is at MigrationMeta version %s, skipping', $post->ID, get_permalink( $post->ID ), $command_meta_version ) );
				continue;
			}

			$nid = get_post_meta( $post->ID, '_fgd2wp_old_node_id', true );
			if ( empty( $nid ) ) {
				continue;
			}
			$attachments = [];
			foreach ( $this->get_drupal_images_from_nid( $nid ) as $img ) {
				$attrs                 = $img['attributes'];
				$attrs['post_excerpt'] = $attrs['description'] ?? '';
				$attrs['post_title']   = empty( $attrs['title'] ) ? $attrs['post_excerpt'] : $attrs['title'];
				$attrs['alt']          = empty( $attrs['alt'] ) ? $attrs['post_title'] : $attrs['alt'];
				$attachments[]         = $this->attachments->import_attachment_for_post( $post->ID, $img['url'], $attrs['alt'], $attrs );
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
			$gallery_class                       = 'cn-gallery';
			$blocks                              = GutenbergBlockManipulator::remove_blocks_with_class( $gallery_class, $post->post_content );
			$gallery_block                       = $this->gutenberg_block_generator->get_jetpack_slideshow( $images );
			$gallery_block['attrs']['className'] = $gallery_class;
			$post->post_content                  = serialize_blocks( [ $gallery_block, ...$blocks ] );

			$this->logger->log( 'images.log', sprintf( 'Added gallery to post ID %d: %s', $post->ID, get_permalink( $post ) ) );
		} else {
			$attachment_post = get_post( current( $images ) );
			if ( ! $attachment_post instanceof WP_Post ) {
				return $post;
			}
			$img_block_class = 'cn-gallery-1-img';
			$blocks          = GutenbergBlockManipulator::remove_blocks_with_class( $img_block_class, $post->post_content );
			$img_block       = $this->gutenberg_block_generator->get_image( $attachment_post, 'full', false, $img_block_class );

			$post->post_content = serialize_blocks( [ $img_block, ...$blocks ] );

			$this->logger->log( 'images.log', sprintf( 'Added image block to post ID %d: %s', $post->ID, get_permalink( $post ) ) );
		}

		return $post;
	}

	/**
	 * Filter the options for the FG Drupal to WP plugin to use environment variables for the database connection.
	 * Put these variables in your .env file locally (or comment out locally).
	 *
	 * @param array $options The options to filter.
	 *
	 * @return array The filtered options.
	 * @throws ExitException
	 */
	public function filter_fgd2wp_options( $options ) {
		$options['hostname'] = getenv( 'DB_HOST' );
		$options['database'] = getenv( 'DB_NAME' );
		$options['username'] = getenv( 'DB_USER' );
		$options['password'] = getenv( 'DB_PASSWORD' );
		if ( empty( $options['hostname'] ) || empty( $options['database'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
			WP_CLI::error( 'Could not get database connection details from environment variables.' );
		}

		$options['prefix'] = $this->get_prefix();

		return $options;
	}

	/**
	 * Run the import.
	 *
	 * We simply wrap the import command from FG Drupal and add our hooks before running the import.
	 * Note that we can't batch this at all, so timeouts might be a thing.
	 */
	public function cmd_wrap_drupal_import( array $pos_args, array $assoc_args ): void {
		add_filter( 'option_fgd2wp_options', [ $this, 'filter_fgd2wp_options' ] );
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
		$prefix = $this->get_prefix();
		global $wpdb;
		$result = $wpdb->get_results( "SELECT * FROM ${prefix}files WHERE fid = $fid", ARRAY_A );
		if ( empty( $result ) ) {
			return [];
		}
		$field_image = $result[0];

		$img_url = trailingslashit( NCCM_SOURCE_WEBSITE_URL ) . $field_image['filepath'];

		if ( $fallback_to_imagecache ) {
			$request = wp_remote_head( $img_url, [ 'redirection' => 5 ] );
			if ( is_wp_error( $request ) || 404 === $request['response']['code'] ?? 404 ) {
				$img_url = str_replace( '/files/', '/files/imagecache/galleryformatter_slide/', $img_url );
			}
		}

		return [
			'filename'  => $field_image['filename'],
			'url'       => $img_url,
			'timestamp' => $field_image['timestamp'],
		];
	}


	/**
	 * Get the prefix for the drupal tables.
	 *
	 * @return string
	 */
	private function get_prefix(): string {
		if ( defined( 'NCCM_DRUPAL_PREFIX' ) && ! empty( trim( NCCM_DRUPAL_PREFIX ) ) ) {
			return NCCM_DRUPAL_PREFIX;
		}

		return 'drupal_';
	}

	public function get_drupal_images_from_nid( int $nid ) {
		$prefix = $this->get_prefix();
		$sql    = "SELECT field_images_fid, field_images_data 
					FROM {$prefix}content_field_images cfi 
					JOIN {$prefix}node n ON cfi.nid = n.nid AND cfi.vid = n.vid 
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

			$block         = serialize_block(
				[
					'blockName'    => 'newspack-listings/event-dates',
					'attrs'        => [
						'startDate' => $date->format( self::WP_DATE_FORMAT ),
						'showTime'  => true,
					],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				] 
			);
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
		$old_url = trailingslashit( NCCM_SOURCE_WEBSITE_URL ) . 'node/' . $node['nid'];
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

	private function get_wp_posts_iterator( array $post_types, array $assoc_args, array $post_statuses = [ 'publish' ], bool $log_progress = true ): iterable {
		if ( ! empty( $assoc_args['post-id'] ) ) {
			$all_ids = [ $assoc_args['post-id'] ];
		} else {
			$min_post_id = $assoc_args['min-post-id'] ?? 0;
			$max_post_id = $assoc_args['max-post-id'] ?? PHP_INT_MAX;
			$num_posts   = $assoc_args['num-posts'] ?? PHP_INT_MAX;
			global $wpdb;
			$post_type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			$post_status_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
			$all_ids                  = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type IN ( $post_type_placeholders )
			AND post_status IN ( $post_status_placeholders )
			AND ID BETWEEN %d AND %d
			ORDER BY ID DESC
			LIMIT %d",
					[ ...$post_types, ...$post_statuses, $min_post_id, $max_post_id, $num_posts ]
				)
			);
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
