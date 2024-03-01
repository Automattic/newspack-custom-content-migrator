<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \DateTimeImmutable;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Utils\Logger;
use PDO;
use PDOException;
use WP_CLI;
use WP_CLI\ExitException;

class CarsonNowMigrator implements InterfaceCommand {

	const SITE_TIMEZONE = 'America/Los_Angeles';

	const DRUPAL_DATE_FORMAT = 'Y-m-d\TH:i:s';
	const WP_DATE_FORMAT     = 'Y-m-d H:i:s';

	/**
	 * @var DateTimeImmutable
	 */
	private $reader_content_cutoff_date;

	private function __construct() {
		$this->reader_content_cutoff_date = DateTimeImmutable::createFromFormat( self::DRUPAL_DATE_FORMAT, '2023-01-01T00:00:00' );
		$this->logger                     = new Logger();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
		$this->attachments               = new Attachments();
	}

	private function get_or_create_category( string $name, int $parent_id = 0 ) {
		$term = get_term_by( 'name', $name, 'category' );
		if ( $term ) {
			return $term->term_id;
		}

		$args = [];

		if ( $parent_id ) {
			$args['parent'] = $parent_id;
		}

		$term = wp_insert_term( $name, 'category', $args );
		if ( is_wp_error( $term ) || empty( $term['term_id'] ) ) {
			return null;
		}

		return $term['term_id'];
	}

	public static function get_instance(): self {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	public function add_fg_hooks(): void {
		// TODO explain why each is here.
		$weight = 11;
		add_filter( 'fgd2wp_map_taxonomy', [ $this, 'fg_filter_map_taxonomy' ], $weight, 3 );
		add_filter( 'fgd2wp_get_node_types', [ $this, 'fg_filter_get_node_types' ], $weight, 1 );
		add_filter( 'fgd2wp_pre_register_post_type', [ $this, 'fg_filter_fgd2wp_pre_register_post_type' ], $weight, 3 );
		add_filter( 'fgd2wp_pre_insert_post', [ $this, 'fg_filter_fgd2wp_pre_insert_post' ], $weight, 2 );
//		add_filter( 'fgd2wp_get_drupal6_field_image', [ $this, 'fg_filter_image_field' ], $weight, 3 );

		//	add_filter('fgd2wp_import_media_gallery', 'cn_filter_fgd2wp_import_media_gallery', 20, 3);


		add_action( 'fgd2wp_post_insert_post', [ $this, 'fg_action_fgd2wp_post_insert_post' ], $weight, 5 );
	}


	/**
	 * @throws \Exception
	 */
	public function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator cn-wrap-import',
			[ $this, 'cmd_wrap_import' ],
			[
				'shortdesc'     => 'Wrap the import command from FG Drupal.',
				'synopsis'      => [],
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

		if ( get_option( 'timezone_string', false ) !== self::SITE_TIMEZONE ) {
			WP_CLI::error( sprintf( "Site timezone should be '%s'. Make sure it's set correctly before running the migration commands", self::SITE_TIMEZONE ) );
		}

		$checked = true;
	}


	/**
	 * Run the import.
	 *
	 * We simply wrap the import command from FG Drupal and add our hooks before running the import.
	 * Note that we can't batch this at all, so timeouts might be a thing.
	 */
	public function cmd_wrap_import( array $pos_args, array $assoc_args ): void {
		add_action( 'fgd2wp_pre_dispatch', [ $this, 'add_fg_hooks' ] );
		// Note that the 'launch' arg is important – without it the hooks above will not be registered.
		WP_CLI::runcommand( 'import-drupal import', [ 'launch' => false ] );
	}

	public function get_dates_from_nid( int $nid ) {
		$sql   = "SELECT MAX(vid), delta, field_date_value AS dateval
					FROM content_field_date
					WHERE nid = $nid
					  AND field_date_value IS NOT NULL
					GROUP BY nid, field_date_value, delta, dateval
					ORDER BY delta";
		$rows  = $this->drupal_query( $sql );
		$dates = [];
		foreach ( $rows as $row ) {
			// TODO. See if date is more than some date we agree on and if not - skip.
			$dates[] = $row['dateval'];
		}

		return $dates;
	}

	public function get_image_data_from_fid( $fid, $fallback_to_imagecache = true ): array {

		$sql    = "SELECT * FROM files WHERE fid = $fid";
		$result = $this->drupal_query( $sql );
		if ( empty( $result[0] ) ) {
			return [];
		}
		$field_image = $result[0];

		if ( $fallback_to_imagecache ) {
			$img_url = trailingslashit( $this->get_fg_options( 'url' ) ) . $field_image['filepath'];

			$request = wp_remote_head( $img_url, [ 'redirection' => 5 ] );
			if ( empty( $request['response']['code'] ) || $request['response']['code'] === 404 ) {
				$field_image['filepath'] = str_replace( '/files/', '/files/imagecache/galleryformatter_slide/', $field_image['filepath'] );
			}
		}

		return [
			'filename'  => $field_image['filename'],
			'uri'       => $field_image['filepath'],
			'timestamp' => $field_image['timestamp'],
		];
	}

	public function get_drupal_images_from_nid( $nid ) {
		$sql = "SELECT field_images_fid AS fid, field_images_data AS data 
					FROM content_field_images cfi 
					JOIN node ON cfi.nid = node.nid AND cfi.vid = node.vid 
					WHERE cfi.nid = $nid 
					  AND cfi.field_images_fid IS NOT NULL
					ORDER BY delta";
		$rows   = $this->drupal_query( $sql );
		$images = [];
		foreach ( $rows as $row ) {
			$img              = $this->get_image_data_from_fid( $row['fid'] );
			$data             = maybe_unserialize( $row['data'] );
			$img['attributs'] = [
				'image_alt'     => $data['alt'] ?? '',
				'description'   => $data['description'] ?? '',
				'image_caption' => $data['title'] ?? '',
			];
			$images[]         = $img;
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

	public function fg_filter_image_field( $image, $node_id, $node_type ) {

		$img = $this->get_drupal_images_from_nid( $node_id );
		if ( ! empty( $img ) ) {
			$image = $img[0]; // Not sure if smart with only one. Maybe call this filter _featured-image_ and figure out the rest.
		}

		return $image;
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

	private function on_pre_insert_reader_content( $new_post, $node ): array {
		if ( ! $node['status'] ) {
			// Don't import drafts for this type.
			return [];
		}
		$new_post['post_type'] = 'post';

		if ( empty( $new_post['post_category'] ) ) {
			$new_post['post_category'] = [];
		}
		$new_post['post_category'][] = $this->get_or_create_category( 'Reader Content' );

		return $new_post;
	}

	private function on_pre_insert_with_event_date( $dates, $new_post, $node ): array {

		$content = $new_post['post_content'];
		foreach ( $dates as $date ) {
			$post_date = DateTimeImmutable::createFromFormat( self::DRUPAL_DATE_FORMAT, $date, 'UTC' );
			if ( $post_date < $this->reader_content_cutoff_date ) {
				return [];
			}
			$block   = serialize_block( [
				'blockName'    => 'newspack-listings/event-dates',
				'attrs'        => [
					'startDate' => $date, //TODO. Timexone?
					'showTime'  => true,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			] );
			$content = $block . $content;
		}

		$new_post['post_content'] = $content;
		$new_post['post_type']    = 'newspack_lst_event';

		return $new_post;
	}


	public function fg_filter_fgd2wp_pre_insert_post( $new_post, $node ) {
//		$node['migration_data']['images'] = $this->get_drupal_images_from_nid( $node['nid'] );

		if ( 'reader_content' === $node['type'] ) {
			$new_post = $this->on_pre_insert_reader_content( $new_post, $node );
		}

		$dates = $this->get_dates_from_nid( $node['nid'] );
		if ( ! empty( $dates ) ) {
			$new_post = $this->on_pre_insert_with_event_date( $dates, $new_post, $node );
		}
		// TODO. Have the image thingy "cache" the images and empty it here. We can add gallery then if more img.

		return $new_post;
	}

	private function import_images_for_post( $new_post_id, $node ): array {
		$images = [];
		foreach ($this->get_drupal_images_from_nid( $node['nid'] ) as $delta => $img) {
			$trut = '';
//			$attachment = $this->attachments->import_attachment_for_post( $new_post_id, $img['uri'], $img['filename'], $img['attributs'])
		}
		return $images;
	}


	public function fg_action_fgd2wp_post_insert_post( $new_post_id, $node, $post_type, $entity_type ): void {
		$this->import_images_for_post( $new_post_id, $node);

		WP_CLI::log( trailingslashit( $this->get_fg_options( 'url' ) ) . 'node/' . $node['nid'] . ' ==> ' . get_permalink( $new_post_id ) );
	}

	// Stolen from class-fg-drupal-to-wp-admin.php
	private function drupal_query( $sql, $display_error = true ) {
		global $drupal_db;
		$result = [];

		try {
			$query = $drupal_db->query( $sql, PDO::FETCH_ASSOC );
			if ( is_object( $query ) ) {
				foreach ( $query as $row ) {
					$result[] = $row;
				}
			}

		} catch ( PDOException $o_0 ) {
			if ( $display_error ) {
				// TODO. Do something cool.
			}
		}

		return $result;
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
}
