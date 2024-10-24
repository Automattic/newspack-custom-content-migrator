<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTimeImmutable;
use DateTimeZone;
use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\Taxonomy;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\CsvIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Post;
use WP_Query;
use WP_User;

/**
 * Custom migration scripts.
 */
class WindyCityMigrator implements InterfaceCommand {
	const LOG_FILE             = 'windy-city-migrator.log';
	const ORIGINAL_ID_META_KEY = '_newspack_original_id';

	const DEFAULT_AUTHOR_ID = 2;

	const MYSQL_DATETIME_FORMAT = 'Y-m-d H:i:s';


	/**
	 * CSV input file.
	 *
	 * @var array $csv_input_file CSV input file.
	 */
	private array $csv_input_file = [
		'type'        => 'assoc',
		'name'        => 'csv-input-file',
		'description' => 'Path to CSV input file.',
		'optional'    => false,
	];

	private array $refresh_existing = [
		'type'        => 'flag',
		'name'        => 'refresh-existing',
		'description' => 'Will refresh existing content rather than create new',
		'optional'    => true,
	];

	/**
	 * @var Attachments.
	 */
	private $attachments_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var CsvIterator.
	 */
	private $csv_iterator;

	/**
	 * @var DateTimeZone.
	 */
	private DateTimeZone $site_timezone;

	/**
	 * @var Redirection
	 */
	private Redirection $redirection;

	/**
	 * @var Posts
	 */
	private Posts $posts;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Set things up and check a few things before continuing.
	 */
	public function preflight(): void {
		static $checked = false;

		if ( $checked ) {
			// It looks like this gets called at least more than once pr. run, so bail if we already checked.
			return;
		}

		$this->attachments_logic         = new Attachments();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
		$this->logger                    = new Logger();
		$this->csv_iterator              = new CsvIterator();
		$this->site_timezone             = new DateTimeZone( 'America/Chicago' );
		$this->redirection               = new Redirection();
		$this->posts                     = new Posts();
		$this->taxonomy                  = new Taxonomy();

		$check_required_plugins = [
			'newspack-listings/newspack-listings.php' => 'Newspack listings',
			'redirection/redirection.php'             => 'Redirection',
		];

		if ( wp_timezone()->getName() !== $this->site_timezone->getName() ) {
			WP_CLI::error( "Timezones don't match!" );
		}

		foreach ( $check_required_plugins as $plugin => $plugin_name ) {
			if ( ! is_plugin_active( $plugin ) ) {
				WP_CLI::error( '"' . $plugin_name . '" plugin not found. Install and activate it before using the migration commands.' );
			}
		}

		$checked = true;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator windy-city-migrator',
			[ $this, 'cmd_windy_city_migrator' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Custom migration scripts for Windy City.',
				'synopsis'      => [
					$this->csv_input_file,
					...BatchLogic::get_batch_args(),
					[
						'type'        => 'assoc',
						'name'        => 'pdf-folder-path',
						'description' => 'Path to PDF folder.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-author-display-name',
						'description' => 'Default author display name.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-author-email',
						'description' => 'Default author email.',
						'optional'    => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-content',
						'description' => 'Refresh the content of the posts that were already imported.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator windy-city-pdf-listings',
			[ $this, 'cmd_windy_city_pdf_listings' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Import PDF listings from CSV.',
				'synopsis'      => [
					... BatchLogic::get_batch_args(),
					$this->refresh_existing,
					[
						'type'        => 'assoc',
						'name'        => 'csv-input-file',
						'description' => 'Path to CSV input file.',
						'optional'    => false,
					],
				],
			],
		);

		WP_CLI::add_command(
			'newspack-content-migrator windy-city-group-listings',
			[ $this, 'cmd_windy_city_group_listings' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Import community group listings from CSV.',
				'synopsis'      => [
					... BatchLogic::get_batch_args(),
					$this->refresh_existing,
					[
						'type'        => 'assoc',
						'name'        => 'csv-input-file',
						'description' => 'Path to CSV input file.',
						'optional'    => false,
					],
				],
			],
		);

		WP_CLI::add_command(
			'newspack-content-migrator windy-city-place-listings',
			[ $this, 'cmd_windy_city_place_listings' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Import place listings from CSV.',
				'synopsis'      => [
					... BatchLogic::get_batch_args(),
					$this->refresh_existing,
					[
						'type'        => 'assoc',
						'name'        => 'csv-input-file',
						'description' => 'Path to CSV input file.',
						'optional'    => false,
					],
				],
			],
		);

		WP_CLI::add_command(
			'newspack-content-migrator windy-city-archive-date-categories',
			[ $this, 'cmd_windy_city_archive_date_categories' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Juggle categories to move them under the correct date in archives.',
				'synopsis'      => [
					BatchLogic::$num_items,
					[
						'type'        => 'assoc',
						'name'        => 'archive-sub-category-id',
						'description' => 'The ID of a category under "archive". to process',
						'optional'    => false,
					],
				],
			],
		);

		WP_CLI::add_command(
			'newspack-content-migrator windy-city-fix-html-entites',
			[ $this, 'cmd_windy_city_fix_html_entites' ],
			[
				'before_invoke' => [ $this, 'preflight' ],
				'shortdesc'     => 'Fix HTML entities in Windy City content.',
				'synopsis'      => [
					BatchLogic::$num_items,
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Post work from this post ID. Note that this is not default batching behaviour.',
						'optional'    => true,
					],
				],
			],
		);
	}

	/**
	 * @throws \Exception
	 */
	public function cmd_windy_city_archive_date_categories( array $pos_args, array $assoc_args ): void {
		$log_file       = __FUNCTION__ . '.log';
		$num_items      = $assoc_args['num-items'] ?? false;
		$sub_cat_id     = $assoc_args['archive-sub-category-id'];

		$slug_prefixes = [
			17  => 'ns',
			98  => 'id',
			185 => 'bl',
			271 => 'lv',
			314 => 'qc',
			338 => 'wc',
		];

		if ( ! array_key_exists( $sub_cat_id, $slug_prefixes ) ) {
			WP_CLI::error( 'Invalid sub category ID' );
		}

		$slug_prefix_for_cat = $slug_prefixes[ $sub_cat_id ];

		$post_ids = $this->posts->get_all_posts_ids_in_category( $sub_cat_id, 'post', [ 'publish' ] );
		if ( $num_items ) {
			$post_ids = array_slice( $post_ids, 0, $num_items );
		}

		WP_CLI::log( sprintf( 'Processing %d posts', count( $post_ids ) ) );
		foreach ( $post_ids as $post_id ) {
			$date  = get_post_datetime( $post_id );
			$cat_slug = $slug_prefix_for_cat . '-' . $date->format( 'Y-m-d' );
			$cat_name = $date->format( 'F j, Y' );
			$cat_id = $this->get_or_create_category( $cat_slug, $cat_name, $sub_cat_id );
			$post_cats = wp_get_post_categories( $post_id );
			if ( in_array( $cat_id, $post_cats, true ) ) {
				WP_CLI::log( sprintf( 'Post ID %d already has category "%s" added', $post_id, get_cat_name( $cat_id ) ) );
				continue;
			}
			wp_set_post_categories( $post_id, [ $cat_id ], true );
			$this->logger->log(
				$log_file,
				sprintf(
					'Post ID %d got category "%s" added',
					$post_id,
					get_cat_name( $cat_id )
				),
				Logger::SUCCESS
			);
		}

	}

	/**
	 * Get category ID if it exists, otherwise create it.
	 *
	 * @param string $slug Slug for category.
	 * @param string $name Category name to get/create.
	 * @param int    $parent_id     Parent category ID.
	 * @return int  Category ID.
	 */
	private function get_or_create_category( string $slug, string $name, int $parent_id ): int {
		$category_id = get_term_by( 'slug', $slug, 'category' );

		// Great! We have it already.
		if ( $category_id ) {
			return $category_id->term_id;
		}

		// Add category.
		return wp_insert_category(
			[
				'category_nicename' => $slug,
				'cat_name'          => $name,
				'taxonomy'          => 'category',
				'category_parent'   => $parent_id,
			]
		);
	}

	/**
	 * Import community group listings from CSV.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws ExitException
	 */
	public function cmd_windy_city_group_listings( array $pos_args, array $assoc_args ): void {
		$log_file      = __FUNCTION__ . '.log';
		$csv_file_path = $assoc_args['csv-input-file'];
		$refresh       = $assoc_args['refresh-existing'] ?? false;
		$post_type     = 'newspack_lst_generic';

		$batch_args      = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$groups_category = 340;

		WP_CLI::log( sprintf( '%s %d community group listings', ( $refresh ? 'Refreshing' : 'Creating' ), $batch_args['total'] ) );
		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row_no => $row ) {
			$num_item_processing = $row_no + 1;
			WP_CLI::log( sprintf( 'Processing row %d/%d', $num_item_processing, $batch_args['total'] ) );

			$replaced_text = $this->replace_html_entities( [
				'post_title'   => $row['COMCATEGORY'],
				'post_content' => $row['COMBODY'],
			] );

			$post_content  = make_clickable( $replaced_text['post_content'] );
			$post_content  = serialize_block( $this->gutenberg_block_generator->get_html( $post_content ) );
			$listing_title = $replaced_text['post_title'];

			$query = new WP_Query( [
				'post_type' => $post_type,
				'category'  => $groups_category,
				'title'     => $listing_title,
			] );

			$post_data = [
				'post_title'    => $listing_title,
				'post_content'  => $post_content,
				'post_status'   => 'publish',
				'post_author'   => self::DEFAULT_AUTHOR_ID,
				'post_type'     => $post_type,
				'post_category' => [ $groups_category ],
				'meta_input'    => [
					'_wp_page_template' => 'default',
				],
			];
			if ( $query->found_posts < 1 ) {
				$listing_id = wp_insert_post( $post_data );
				$verb       = 'created';
			} else {
				if ( ! $refresh ) {
					WP_CLI::log( sprintf( 'Row with title "%s" has already been imported – skipping', $post_data['post_title'] ) );
					continue;
				}
				$listing_id      = $query->posts[0]->ID;
				$post_data['ID'] = $listing_id;
				wp_update_post( $post_data );
				$verb = 'updated';
			}
			if ( ! $listing_id || is_wp_error( $listing_id ) ) {
				WP_CLI::error( sprintf( 'Failed to create/update post for row %d', $num_item_processing ) );
			}

			$url           = wp_parse_url( $row['COMCANONICALURL'] );
			$redirect_path = $url['path'] . '?' . $url['query'];
			$this->redirection->create_redirection_rule_in_group(
				'Community Group: ' . $listing_title,
				$redirect_path,
				"/?p=$listing_id",
				'Migration'
			);

			$this->logger->log(
				$log_file,
				sprintf(
					'Community group listing with title "%s" for has been %s. ID %d: %s',
					$listing_title,
					$verb,
					$listing_id,
					get_permalink( $listing_id )
				),
				Logger::SUCCESS
			);
		}

	}

	/**
	 * Import place listings from CSV.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws ExitException
	 */
	public function cmd_windy_city_place_listings( array $pos_args, array $assoc_args ): void {
		$log_file      = __FUNCTION__ . '.log';
		$csv_file_path = $assoc_args['csv-input-file'];
		$refresh       = $assoc_args['refresh-existing'] ?? false;
		$post_type     = 'newspack_lst_place';
		$cat_id        = 330; // Bars

		$batch_args = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );

		WP_CLI::log( sprintf( '%s %d place listings', ( $refresh ? 'Refreshing' : 'Creating' ), $batch_args['total'] ) );
		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row_no => $row ) {
			$num_item_processing = $row_no + 1;
			WP_CLI::log( sprintf( 'Processing row %d/%d', $num_item_processing, $batch_args['total'] ) );
			$content_blocks = [];

			$replaced_fields = $this->replace_html_entities( array_intersect_key( $row, array_flip( [ 'bar_name', 'description', 'hours' ] ) ) );
			$address_data    = array_intersect_key( $row, array_flip( [ 'address', 'city', 'state', 'zipcode' ] ) );

			$listing_title    = $replaced_fields['bar_name'];
			$content_blocks[] = $this->gutenberg_block_generator->get_paragraph(
				sprintf(
					'<a href="https://www.google.com/maps/search/?api=1&query=%s" target="_blank" rel="noreferrer noopener">%s</a>',
					urlencode( implode( ',', $address_data ) ),
					implode( ', ', $address_data )
				)
			);
			$content_blocks[] = $this->gutenberg_block_generator->get_paragraph(
				// Urls in data  have no protocol. We'll just guess it's https.
				sprintf( '<a href="https://%1$s">%1$s</a><br>%2$s', $row['website'], $row['phone'] )
			);

			$content_blocks[] = $this->gutenberg_block_generator->get_html( $replaced_fields['description'] );
			// Put an empty paragraph between description and hours HTML blocks so the content does not display in one line.
			$content_blocks[] = $this->gutenberg_block_generator->get_paragraph('');
			$content_blocks[] = $this->gutenberg_block_generator->get_html( $replaced_fields['hours'] );

			$query = new WP_Query( [
				'post_type' => $post_type,
				'cat'  => $cat_id,
				'title'     => $listing_title,
			] );

			$post_data = [
				'post_title'    => $listing_title,
				'post_status'   => 'publish',
				'post_author'   => self::DEFAULT_AUTHOR_ID,
				'post_type'     => $post_type,
				'post_content'  => serialize_blocks( $content_blocks ),
				'post_category' => [ $cat_id ],
				'meta_input'    => [
					'newspack_listings_hide_author'       => 1,
					'newspack_listings_hide_publish_date' => 1,
				],
			];

			if ( $query->found_posts < 1 ) {
				$listing_id = wp_insert_post( $post_data );
				$verb       = 'created';
			} else {
				if ( ! $refresh ) {
					WP_CLI::log( sprintf( 'Place with title "%s" has already been imported – skipping', $listing_title ) );
					continue;
				}
				$listing_id      = $query->posts[0]->ID;
				$post_data['ID'] = $listing_id;
				wp_update_post( $post_data );
				$verb = 'updated';
			}
			if ( ! $listing_id || is_wp_error( $listing_id ) ) {
				WP_CLI::error( sprintf( 'Failed to %s post for row %d', $verb, $num_item_processing ) );
			}

			$featured_img_id = $this->attachments_logic->import_attachment_for_post( $listing_id, $row['image'], $listing_title );
			if ( ! is_wp_error( $featured_img_id ) ) {
				set_post_thumbnail( $listing_id, $featured_img_id );
			}

			$url           = wp_parse_url( $row['canonical_url'] );
			$redirect_path = $url['path'] . '?' . $url['query'];
			$this->redirection->create_redirection_rule_in_group(
				'Place listing: ' . $listing_title,
				$redirect_path,
				"/?p=$listing_id",
				'Migration'
			);

			$this->logger->log(
				$log_file,
				sprintf(
					'Place listing with title "%s" for has been %s. ID %d: %s',
					$listing_title,
					$verb,
					$listing_id,
					get_permalink( $listing_id )
				),
				Logger::SUCCESS
			);
		}
	}

	/**
	 * @throws ExitException
	 */
	public function cmd_windy_city_pdf_listings( array $pos_args, array $assoc_args ): void {
		$log_file      = __FUNCTION__ . '.log';
		$csv_file_path = $assoc_args['csv-input-file'];
		$refresh       = $assoc_args['refresh-existing'] ?? false;

		$batch_args = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );

		// Keys here correspond to the publication names in the CSV file.
		$archive_cats = [
			'Windy City Times' => 339, // Archives -> Windy City Times -> print edition.
			'nightspots'       => 333, // Archives -> Nightspots -> print edition.
		];

		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row_no => $row ) {
			$num_item_prcoessing = $row_no + 1;
			if ( $num_item_prcoessing % 10 === 0 ) {
				WP_CLI::log( sprintf( 'Processing row %d/%d', $num_item_prcoessing, $batch_args['total'] ) );
			}

			$post_type     = 'newspack_lst_generic';
			$pub           = $row['IPUBNAME'];
			$cat_id        = $archive_cats[ $pub ];
			$date          = DateTimeImmutable::createFromFormat( 'Y-m-d', $row['IDATE'], $this->site_timezone );
			$listing_title = $date->format( 'F j, Y' );

			$query = new WP_Query( [
				'post_type' => $post_type,
				'cat'  => $cat_id,
				'title'     => $listing_title,
			] );

			$post_data = [
				'post_title'    => $listing_title,
				'post_status'   => 'publish',
				'post_author'   => self::DEFAULT_AUTHOR_ID,
				'post_type'     => $post_type,
				'post_category' => [ $cat_id ],
				'post_date'     => $date->format( self::MYSQL_DATETIME_FORMAT ),
				'meta_input'    => [
					'newspack_featured_image_position' => 'hidden',
					'_wp_page_template'                => 'default',
				],
			];
			if ( $query->found_posts < 1 ) {
				$listing_id = wp_insert_post( $post_data );
			} else {
				if ( ! $refresh ) {
					WP_CLI::log( sprintf( 'Row with title "%s" for pub "%s" has already been imported – skipping', $listing_title, $pub ) );
					continue;
				}
				$listing_id      = $query->posts[0]->ID;
				$post_data['ID'] = $listing_id;
				wp_update_post( $post_data );
			}
			if ( ! $listing_id || is_wp_error( $listing_id ) ) {
				WP_CLI::error( sprintf( 'Failed to create/update post for row %d', $num_item_prcoessing ) );
			}

			$featured_img_id = $this->attachments_logic->import_attachment_for_post( $listing_id, $row['IIMAGE'], $listing_title );
			if ( ! is_wp_error( $featured_img_id ) ) {
				set_post_thumbnail( $listing_id, $featured_img_id );
			}

			$pdf_id = $this->attachments_logic->import_attachment_for_post( $listing_id, $row['IPDF'], $listing_title );
			if ( ! is_wp_error( $pdf_id ) ) {
				$pdf_post = get_post( $pdf_id );
				$block    = $this->gutenberg_block_generator->get_file_pdf( $pdf_post, $listing_title, false, 800 );
				wp_update_post( [ 'ID' => $listing_id, 'post_content' => serialize_block( $block ) ] ); // Only content in post is the file PDF block.
			}

			$this->logger->log(
				$log_file,
				sprintf(
					'PDF listing with title "%s" for pub "%s" has been imported to ID %d: %s',
					$listing_title,
					$pub,
					$listing_id,
					get_permalink( $listing_id )
				),
				Logger::SUCCESS
			);
		}
	}

	public function cmd_windy_city_fix_html_entites( array $pos_args, array $assoc_args ): void {
		foreach ( $this->get_posts_with_html_entities( $assoc_args ) as $post ) {
			$this->fix_entities_in_post( $post );
		}
	}

	/**
	 * Replace select HTML entities in an array of items.
	 *
	 * @param array $items_to_replace Keyed array (e.g. ['excerpt' => '...', 'content' => '...']) to replace entities in multiple items.
	 *
	 * @return array the keyed array with replaced entities.
	 */
	private function replace_html_entities( array $items_to_replace ): array {
		static $search, $replace = null;
		if ( ! $search ) {
			$replacements = [
				8194  => '&nbsp;',
				8230  => '…',
				8212  => '—',
				243   => 'ó',
				241   => 'ñ',
				233   => 'é',
				232   => 'è',
				224   => 'à',
				225   => 'á',
				169   => '©',
				162   => '¢',
				151   => '—',
				91    => '[',
				92    => '\\',
				93    => ']',
				41    => ')',
				'041' => ')',
				40    => '(',
				'040' => '(',
				39    => "'",
				'039' => "'",
				34    => '"',
				'034' => '"',
			];
			$search       = array_map( fn( $entity ) => "/&#{$entity};?/", array_keys( $replacements ) );
			$replace      = array_values( $replacements );
		}

		return preg_replace( $search, $replace, $items_to_replace );
	}

	/**
	 * Replace select HTML entities in post content, excerpt and subtitle.
	 *
	 * @param WP_Post $post
	 *
	 * @return WP_Post
	 */
	private function fix_entities_in_post( WP_Post $post ): WP_Post {
		if ( ! str_contains( $post->post_excerpt . $post->post_content, '&#', ) ) {
			return $post;
		}

		$items_to_replace = [ 'excerpt' => $post->post_excerpt, 'content' => $post->post_content ];
		if ( 'post' === $post->post_type ) {
			$subtitle = get_post_meta( $post->ID, 'newspack_post_subtitle', true );
			if ( $subtitle && str_contains( $subtitle, '&#' ) ) {
				$items_to_replace['newspack_post_subtitle'] = get_post_meta( $post->ID, 'newspack_post_subtitle', true );
			}
		}

		$replaced = $this->replace_html_entities( $items_to_replace );

		$post->post_excerpt = $replaced['excerpt'];
		$post->post_content = $replaced['content'];
		wp_update_post( $post );
		if ( ! empty( $replaced['newspack_post_subtitle'] ) ) {
			update_post_meta( $post->ID, 'newspack_post_subtitle', $replaced['newspack_post_subtitle'] );
		}
		$this->logger->log( 'html-entities.log', sprintf( 'Replaced HTML entities in post id: %d', $post->ID ), Logger::SUCCESS );

		return $post;
	}

	/**
	 * Get an iterator from high to low post IDs (iterates backwards).
	 *
	 * @param array $assoc_args The assoc args array.
	 * @param bool  $log_progress Whether to output progress to log - default to true.
	 *
	 * @return iterable
	 */
	private function get_posts_with_html_entities( array $assoc_args = [], bool $log_progress = true ): iterable {
		$post_id_passed = $assoc_args['post-id'] ?? false;
		$num_posts      = $assoc_args['num-items'] ?? false;

		if ( empty( $assoc_args['num-items'] ) && $post_id_passed ) {
			$post_ids = [ $assoc_args['post-id'] ];

		} else {
			global $wpdb;
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts 
          					WHERE post_type = 'post'
          					AND ID < %d
          					AND ( post_excerpt LIKE '%&#%' OR post_content LIKE '%&#%' )
          					ORDER BY ID DESC",
					[ empty( $post_id_passed ) ? PHP_INT_MAX : $post_id_passed ]
				)
			);
		}
		WP_CLI::log( sprintf( 'Found a total of %d posts with HTML entities that need replacing.', count( $post_ids ) ) );
		if ( $num_posts ) {
			$post_ids = array_slice( $post_ids, 0, $num_posts );
		}
		$total_posts = count( $post_ids );
		WP_CLI::log( sprintf( 'Processing %d posts this run', $total_posts ) );

		$home_url   = home_url();
		$counter    = 0;
		$post_count = count( $post_ids );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			++$counter;
			if ( $counter <= $post_count && $post instanceof \WP_Post ) {
				if ( $log_progress ) {
					WP_CLI::log(
						sprintf(
							'Processing %s %d/%d: %s',
							$post->post_type,
							$counter,
							$total_posts,
							"{$home_url}?p={$post_id}"
						)
					);
				}
				yield $post;
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator windy-city-migrator`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_windy_city_migrator( $args, $assoc_args ) {
		$csv_file_path               = $assoc_args[ $this->csv_input_file['name'] ];
		$default_author_display_name = $assoc_args['default-author-display-name'];
		$default_author_email        = $assoc_args['default-author-email'];
		$pdf_folder_path             = $assoc_args['pdf-folder-path'];
		$refresh_content             = isset( $assoc_args['refresh-content'] ) ? true : false;
		$batch_args                  = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$total_entries               = $this->csv_iterator->count_csv_file_entries( $csv_file_path, ',' );
		$entries                     = $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] );
		$existing_original_ids       = $this->get_existing_original_ids();

		$this->logger->log( self::LOG_FILE, sprintf( 'Migrating %d entries.', $total_entries ) );

		foreach ( $entries as $index => $entry ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Migrating entry %d/%d.', $index + 1, $batch_args['end'] ), Logger::LINE );

			// Check if post exists.
			if ( ! $refresh_content && in_array( $entry['GUID'], $existing_original_ids ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Article already exists: ' . $entry['TITLE'], Logger::WARNING );
				continue;
			}

			// Post Author.
			$author_id = $this->get_create_author( $entry['AUTHOR'], $default_author_display_name, $default_author_email );
			if ( false === $author_id ) {
				continue;
			}

			// Create post.
			$post_data = [
				'post_title'     => $entry['TITLE'],
				'post_content'   => $entry['BODY'],
//				'post_excerpt'   => $entry['SUMMARY'], Uncommented because not in content refresh data
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'post_author'    => $author_id,
				// The data before content refresh had this key as "ACOMMENTS"
				'comment_status' => 'yes' === $entry['COMMENTS'] ? 'open' : 'closed',
			];

			// Dates are in Central Time and should be converted to UTC.
			// The data before content refresh had this as "DATE"
			$gmt_date = new \DateTime( $entry['ACTUALDATE'], new \DateTimeZone( 'America/Chicago' ) );
			$gmt_date->setTimezone( new \DateTimeZone( 'UTC' ) );
			$post_data['post_date_gmt'] = $gmt_date->format( 'Y-m-d H:i:s' );

			if ( ! empty( $entry['CANONICALURL'] ) ) {
				// Canonical URL is in the format: https://www.windycitymediagroup.com/lgbt/{post-slug}/69796.html
				// We need to extract the post slug from the URL.
				$canonical_url = $entry['CANONICALURL'];
				preg_match( '/\/lgbt\/(?<post_slug>[^\/]+)\/\d+\.html/', $canonical_url, $matches );
				if ( ! empty( $matches['post_slug'] ) ) {
					$post_slug              = $matches['post_slug'];
					$post_data['post_name'] = $post_slug;
				} else {
					$this->logger->log( self::LOG_FILE, ' -- Error extracting post slug from canonical URL: ' . $canonical_url, Logger::WARNING );
				}
			}

			// Create or get the post.
			$post_id = $this->get_create_post( $entry['GUID'], $post_data );


			// Migrate post content.
			$post_content = $entry['BODY'];

			// Galleries.
			$gallery_content = '';
			if ( ! empty( $entry['MORE_IMAGES'] ) && 'NULL' !== $entry['MORE_IMAGES'] ) {
				$gallery_content = $entry['MORE_IMAGES'];
			}
			// Gallery data is in the entry attributes GALLERY1...GALLERY8.
			for ( $i = 1; $i <= 8; $i++ ) {
				if ( ! empty( $entry[ 'GALLERY' . $i ] ) ) {
					$gallery_content .= $entry[ 'GALLERY' . $i ];
				}
			}

			$post_content = $this->migrate_gallery( $post_id, $post_content, $gallery_content );

			// If the post contains a gallery, we need to hide the featured image.
			if ( ! empty( $gallery_content ) ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			}

			// Embeds.
			if ( ! empty( $entry['EMBED'] ) ) {
				$post_content = $this->migrate_embed( $post_content, $entry['EMBED'] );
			}

			// PDFs.
			$post_content = $this->migrate_pdfs( $post_id, $post_content, $pdf_folder_path );

			// Update post content.
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $post_content,
				]
			);

			// Post Tags.
			$post_tags = explode( ';', $entry['TAGS'] );
			$post_tags = array_map( 'trim', $post_tags );
			wp_set_post_tags( $post_id, $post_tags );

			// Post Categories.
			$post_categories = explode( ';', $entry['CATEGORY'] );
			$catgories_ids   = [];
			foreach ( $post_categories as $category_index => $post_category ) {
				$post_category = trim( $post_category );

				if ( 0 !== $category_index ) {
					$parent_category_id = get_cat_ID( $post_categories[ $category_index - 1 ] );
					$category_id        = wp_create_category( $post_category, $parent_category_id );
				} else {
					$category_id = wp_create_category( $post_category );
				}

				if ( ! is_wp_error( $category_id ) ) {
					$catgories_ids[] = $category_id;
				}
			}
			wp_set_post_categories( $post_id, $catgories_ids );

			// Featured Image.
			if ( ! empty( $entry['FEATURED'] ) && 'NULL' !== $entry['FEATURED'] ) {
				$attachment_id = $this->attachments_logic->import_external_file( $entry['FEATURED'], $entry['TITLE'], $entry['FEATURED_CAPTION'], null, null, $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log( self::LOG_FILE, ' -- Error importing attachment (' . $entry['FEATURED'] . '): ' . $attachment_id->get_error_message(), Logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			} elseif ( ! empty( $gallery_content ) ) {
				// If the post contains a gallery, we'll set the first image as the featured image.
				$gallery_images = explode( ';', $gallery_content );
				$first_image    = trim( current( $gallery_images ) );
				$first_image    = current( explode( '|', $first_image ) );

				$attachment_id = $this->attachments_logic->import_external_file( $first_image, $entry['TITLE'], null, null, null, $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log( self::LOG_FILE, ' -- Error importing attachment (' . $first_image . '): ' . $attachment_id->get_error_message(), Logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			// A few meta fields.
			update_post_meta( $post_id, self::ORIGINAL_ID_META_KEY, $entry['GUID'] );
			update_post_meta( $post_id, 'newspack_post_subtitle', $entry['SUBTITLE'] );

			$this->logger->log( self::LOG_FILE, ' -- Article migrated with ID: ' . $post_id, Logger::SUCCESS );
		}
	}

	/**
	 * Get or create post.
	 * If post exists, return its ID.
	 * If post does not exist, create it and return its ID.
	 *
	 * @param string $original_id Original ID.
	 * @param array  $post_data Post data.
	 *
	 * @return int|bool Post ID or false on failure.
	 */
	private function get_create_post( $original_id, $post_data ) {
		global $wpdb;

		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::ORIGINAL_ID_META_KEY, $original_id ) );

		if ( $post_id ) {
			// Post exists.
			$this->logger->log( self::LOG_FILE, ' -- Post already exists with ID: ' . $post_id, Logger::LINE );

			return $post_id;
		}

		// Post does not exist.
		// Create post.
		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			$this->logger->log( self::LOG_FILE, ' -- Error creating post: ' . $post_id->get_error_message(), Logger::WARNING );

			return false;
		}

		$this->logger->log( self::LOG_FILE, ' -- Post created with ID: ' . $post_id, Logger::LINE );

		return $post_id;
	}

	/**
	 * Get or create author.
	 *
	 * @param string $display_name Author name.
	 * @param string $default_author_display_name Default author display name.
	 * @param string $default_author_email Default author email.
	 *
	 * @return int|bool Author ID or false on failure.
	 */
	private function get_create_author( $display_name, $default_author_display_name, $default_author_email ) {
		if ( empty( $display_name ) ) {
			$username = sanitize_user( $default_author_display_name, true );
			$author   = get_user_by( 'login', $username );

			if ( $author instanceof WP_User ) {
				return $author->ID;
			}

			$author_id = wp_insert_user(
				[
					'display_name' => $default_author_display_name,
					'user_login'   => $username,
					'user_email'   => $default_author_email,
					'user_pass'    => wp_generate_password(),
					'role'         => 'author',
				]
			);

			if ( is_wp_error( $author_id ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );

				return false;
			}

			$this->logger->log( self::LOG_FILE, ' -- Author (' . $default_author_display_name . ') created with ID: ' . $author_id, Logger::SUCCESS );

			return $author_id;
		}

		// check if the display name is an email.
		if ( str_contains( $display_name, '@' ) ) {
			$display_name = explode( '@', $display_name )[0];
		}

		// Author name is not empty.
		$username = sanitize_user( $display_name, true );
		// check if username is longer than 60 chars.
		if ( strlen( $username ) > 60 ) {
			$username = substr( $username, 0, 60 );
		}
		$author = get_user_by( 'login', $username );

		if ( $author instanceof WP_User ) {
			return $author->ID;
		}

		$author_id = wp_insert_user(
			[
				'display_name' => $display_name,
				'user_login'   => $username,
				'user_pass'    => wp_generate_password(),
				'role'         => 'author',
			]
		);

		if ( is_wp_error( $author_id ) ) {
			$this->logger->log( self::LOG_FILE, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );

			return false;
		}

		$this->logger->log( self::LOG_FILE, ' -- Author (' . $display_name . ') created with ID: ' . $author_id, Logger::SUCCESS );

		return $author_id;
	}

	/**
	 * Migrate gallery.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_content Post content.
	 * @param string $gallery Gallery.
	 *
	 * @return string Post content.
	 */
	private function migrate_gallery( $post_id, $post_content, $gallery ) {
		if ( empty( $gallery ) ) {
			return $post_content;
		}

		$gallery = trim( $gallery );
		$gallery = trim( $gallery, ':' );
		// The data before content refresh had this as ':'
		$gallery_images = explode( ' : ', $gallery );
		$gallery_images = array_map( 'trim', $gallery_images );

		$gallery_image_ids = [];
		foreach ( $gallery_images as $gallery_image ) {
			if ( empty( $gallery_image ) ) {
				continue;
			}

			// $gallery_image is in the format: https://www.windycitymediagroup.com/images/publications/wct/2019-11-06/cover.jpg|Caption
			$gallery_image = explode( '|', $gallery_image );
			$gallery_image = array_map( 'trim', $gallery_image );
			$image_url     = $gallery_image[0];
			$caption       = $gallery_image[1] ?? null;
			$attachment_id = $this->attachments_logic->import_external_file( $image_url, null, $caption, null, null, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Error importing attachment (' . $image_url . '): ' . $attachment_id->get_error_message(), Logger::WARNING );
				continue;
			}

			$this->logger->log( self::LOG_FILE, ' -- Imported attachment with ID: ' . $attachment_id, Logger::LINE );
			$gallery_image_ids[] = $attachment_id;
		}

		$gallery_content = empty( $gallery_image_ids ) ? '' : serialize_block(
			$this->gutenberg_block_generator->get_jetpack_slideshow( $gallery_image_ids )
		);

		if ( ! empty( $gallery_content ) ) {
			$this->logger->log( self::LOG_FILE, ' -- With gallery. ' );
		}

		return $gallery_content . $post_content;
	}

	/**
	 * Migrate embed.
	 *
	 * @param string $post_content Post content.
	 * @param string $embed Embed.
	 *
	 * @return string Post content.
	 */
	private function migrate_embed( $post_content, $embed ) {
		if ( empty( $embed ) ) {
			return $post_content;
		}

		$embed = preg_replace( '/\\\\/', '', $embed );

		// Youtube Embed.
		if ( str_contains( $embed, 'youtube.com' ) || str_contains( $embed, 'youtu.be' ) ) {
			preg_match( '/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?(?P<id>[^#&?"\']*).*/', $embed, $video_id_matcher );
			$youtube_id = array_key_exists( 'id', $video_id_matcher ) ? $video_id_matcher['id'] : null;

			if ( ! $youtube_id ) {
				$this->logger->log( self::LOG_FILE, ' -- Error extracting youtube ID from embed: ' . $embed, Logger::WARNING );

				return $post_content;
			}

			$media_content = serialize_block(
				$this->gutenberg_block_generator->get_youtube( $youtube_id )
			);

			return $post_content . $media_content;
		}

		// Get iframe src.
		preg_match( '/src="(?P<iframe_src>[^"]+)"/', $embed, $iframe_src_matcher );
		$iframe_src = array_key_exists( 'iframe_src', $iframe_src_matcher ) ? $iframe_src_matcher['iframe_src'] : null;

		if ( ! $iframe_src ) {
			$this->logger->log( self::LOG_FILE, ' -- Error extracting iframe src from embed: ' . $embed, Logger::WARNING );

			return $post_content;
		}

		return $post_content . serialize_block(
				$this->gutenberg_block_generator->get_iframe( $iframe_src )
			);
	}

	/**
	 * Migrate PDFs.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_content Post content.
	 * @param string $pdf_folder_path PDF folder path.
	 *
	 * @return string Post content.
	 */
	private function migrate_pdfs( $post_id, $post_content, $pdf_folder_path ) {
		// The PDF links are hardcoded in the post content in the format (https://)?windycitytimes.com/pdf/1stWardAldProcoMoreno.pdf.
		// We need to extract the PDF file name from the link and then find the file in the PDF folder.
		// And Replace the URL with the filename linked to the PDF file.
		$pattern = '/(?P<pdf_link>(https?:\/\/)?windycitytimes.com\/pdf\/(?P<pdf_file_name>(.*?)\.pdf))/im';
		preg_match_all( $pattern, $post_content, $matches );

		if ( empty( $matches['pdf_file_name'] ) ) {
			return $post_content;
		}

		foreach ( $matches['pdf_file_name'] as $match_index => $pdf_file_name ) {
			$pdf_file_path = $pdf_folder_path . '/' . $pdf_file_name;

			if ( ! file_exists( $pdf_file_path ) ) {
				$this->logger->log( self::LOG_FILE, ' -- PDF file does not exist: ' . $pdf_file_path, Logger::WARNING );
				continue;
			}

			$attachment_id = $this->attachments_logic->import_external_file( $pdf_file_path, null, null, null, null, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Error importing attachment (' . $pdf_file_path . '): ' . $attachment_id->get_error_message(), Logger::WARNING );
				continue;
			}

			$this->logger->log( self::LOG_FILE, ' -- Imported PDF with ID: ' . $attachment_id, Logger::LINE );

			// Replace the URL with the filename linked to the PDF file.
			$html_link    = '<a href="' . wp_get_attachment_url( $attachment_id ) . '">' . $pdf_file_name . '</a>';
			$post_content = str_replace( $matches['pdf_link'][ $match_index ], $html_link, $post_content );
		}

		return $post_content;
	}

	/**
	 * Get existing original IDs.
	 *
	 * @return array Existing original IDs.
	 */
	private function get_existing_original_ids() {
		global $wpdb;

		$existing_original_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s", self::ORIGINAL_ID_META_KEY ) );

		return $existing_original_ids;
	}
}
