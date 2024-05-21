<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Error;

/**
 * A migrator instance for PCIJ's archive.
 */
class PCIJMigrator implements InterfaceCommand {

	const SITE_TIMEZONE = 'Asia/Manila';

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Class containing custom Co-Authors Plus logic.
	 *
	 * @var CoAuthorPlus $co_authors_plus_logic
	 */
	private CoAuthorPlus $co_authors_plus_logic;

	/**
	 * Class containing custom Attachments logic.
	 *
	 * @var Attachments $attachments
	 */
	private Attachments $attachments;

	/**
	 * Constructor
	 *
	 * @return void
	 */
	private function __construct() {
		$this->co_authors_plus_logic = new CoAuthorPlus();
		$this->attachments           = new Attachments();
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
	 * See InterfaceCommand::register_commands()
	 *
	 * @inheritDoc
	 * @throws Exception Throws exceptions if issues that prevent migration are encountered.
	 */
	public function register_commands() {
		$before_invoke = [
			'before_invoke' => [ $this, 'site_requirements' ],
		];

		WP_CLI::add_command(
			'newspack-content-migrator pcij-migrate-database',
			[ $this, 'cmd_migrate_database' ],
			[
				'shortdesc' => 'Handles migrating PCIJ\'s entire database to WordPress',
				'synopsis'  => [],
				...$before_invoke,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator pcij-migrate-authors',
			[ $this, 'cmd_migrate_authors_as_guest_authors' ],
			[
				'shortdesc' => "Handles migrating Authors from PCIJ's old database to WordPress",
				'synopsis'  => [],
				...$before_invoke,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator pcij-migrate-articles',
			function ( $args, $assoc_args ) {
				$assoc_args['source'] = MigrateTable::ARTICLES->value;
				$this->cmd_migrate_legacy_items( $args, $assoc_args );
			},
			[
				'shortdesc' => 'Handles migrating Articles from PCIJ to WordPress',
				'synopsis'  => [],
				...$before_invoke,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator pcij-migrate-blogs',
			function ( $args, $assoc_args ) {
				$assoc_args['source'] = MigrateTable::BLOGS->value;
				$this->cmd_migrate_legacy_items( $args, $assoc_args );
			},
			[
				'shortdesc' => 'Handles migrating Blogs from PCIJ to WordPress',
				'synopsis'  => [],
				...$before_invoke,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator pcij-migrate-data-items',
			function ( $args, $assoc_args ) {
				$assoc_args['source'] = MigrateTable::DATA->value;
				$this->cmd_migrate_legacy_items( $args, $assoc_args );
			},
			[
				'shortdesc' => 'Handles migrating Data entries from PCIJ to WordPress',
				'synopsis'  => [],
				...$before_invoke,
			]
		);
	}

	/**
	 * This function will ensure that the site meets the necessary requirements for the migration.
	 *
	 * @return void
	 * @throws ExitException Exits if necessary plugins or site configuration are missing.
	 */
	public function site_requirements(): void {
		static $checked = false;

		if ( $checked ) {
			return;
		}

		if ( ! $this->co_authors_plus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin is not installed or active.' );
		}

		if ( get_option( 'timezone_string', false ) !== self::SITE_TIMEZONE ) {
			WP_CLI::error( sprintf( "Site timezone should be '%s'. Make sure it's set correctly before running the migration commands", self::SITE_TIMEZONE ) );
		}

		$checked = true;
	}

	/**
	 * This function will execute all the necessary commands to migrate PCIJ's database to WordPress.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws ExitException Exits if weird value given by CAP GA retrieval.
	 */
	public function cmd_migrate_database( $args, $assoc_args ): void {
		ConsoleColor::title_output( 'MIGRATING AUTHORS' );
		$this->cmd_migrate_authors_as_guest_authors( $args, $assoc_args );

		echo "\n\n";
		ConsoleColor::title_output( 'MIGRATING TAGS' );
		$this->cmd_migrate_tags( $args, $assoc_args );

		echo "\n\n";
		ConsoleColor::title_output( 'MIGRATING SUBJECTS' );
		$this->cmd_migrate_subjects_as_tags( $args, $assoc_args );

		echo "\n\n";
		ConsoleColor::title_output( 'MIGRATING ARTICLES' );
		$assoc_args['source'] = MigrateTable::ARTICLES->value;
		$this->cmd_migrate_legacy_items( $args, $assoc_args );

		echo "\n\n";
		ConsoleColor::title_output( 'MIGRATING BLOGS' );
		$assoc_args['source'] = MigrateTable::BLOGS->value;
		$this->cmd_migrate_legacy_items( $args, $assoc_args );

		echo "\n\n";
		ConsoleColor::title_output( 'MIGRATING DATA' );
		$assoc_args['source'] = MigrateTable::DATA->value;
		$this->cmd_migrate_legacy_items( $args, $assoc_args );
	}

	/**
	 * This function handles legacy author migration to Newspack/WordPress as CAP authors.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws ExitException Exits if weird value given by CAP GA retrieval.
	 */
	public function cmd_migrate_authors_as_guest_authors( $args, $assoc_args ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_authors = $wpdb->get_results( 'SELECT * FROM authors' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_authors_count = $wpdb->get_var( 'SELECT COUNT(*) FROM authors' );
		$legacy_authors_count = number_format( $legacy_authors_count );

		ConsoleColor::white( "$legacy_authors_count authors to process..." )->output();
		foreach ( $legacy_authors as $key => $legacy_author ) {
			echo "\n\n\n";

			$current_key = number_format( $key + 1 );
			ConsoleColor::white( "Processing $current_key out of $legacy_authors_count" )->output();
			ConsoleColor::white( 'Original Author ID:' )->underlined_bright_white( $legacy_author->id )->output();

			$guest_author_args = [
				'display_name' => $legacy_author->name,
				'user_email'   => ! empty( $legacy_author->email ) ? $legacy_author->email : '',
				'description'  => $legacy_author->bio,
			];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy_author_id_exists = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d",
					ImportMeta::AUTHOR_ID_KEY->value,
					$legacy_author->id
				)
			);

			if ( ! empty( $legacy_author_id_exists ) ) {
				ConsoleColor::yellow( 'It seems this legacy author has been previously imported...' )->output();
				$existing_guest_authors = [];

				foreach ( $legacy_author_id_exists as $existing_author_id ) {
					$existing_guest_author = $this->co_authors_plus_logic->get_guest_author_by_id( $existing_author_id->post_id );

					if ( $existing_guest_author ) {
						$existing_guest_authors[] = (array) $existing_guest_author;
					} else {
						ConsoleColor::bright_yellow( 'Guest Author Record does not exist. Original Author ID:' )
							->underlined_bright_yellow( $existing_author_id->meta_vaue )
							->bright_yellow( 'Post ID:' )
							->underlined_bright_yellow( $existing_author_id->post_id )
							->output();
					}
				}

				( new ConsoleTable() )->output_data( $existing_guest_authors );

				if ( 1 === count( $legacy_author_id_exists ) ) {
					ConsoleColor::yellow( 'Only 1 previously imported Guest Author exists...' );
					$existing_guest_author_details = [
						'display_name' => $existing_guest_authors[0]['display_name'],
						'user_email'   => $existing_guest_authors[0]['user_email'],
						'description'  => $existing_guest_authors[0]['description'],
					];

					$diff_details = array_diff_assoc( $guest_author_args, $existing_guest_author_details );

					if ( ! empty( $diff_details ) ) {
						// Attempt to update guest author.
						ConsoleColor::yellow( 'Update necessary.' )->output();
						( new ConsoleTable() )->output_value_comparison(
							[],
							$existing_guest_author_details,
							$guest_author_args,
							true,
							'Existing Details',
							'Incoming GA Args'
						);

						$this->co_authors_plus_logic->update_guest_author( $legacy_author_id_exists[0]->post_id, $diff_details );
					} else {
						ConsoleColor::white( 'No update required' )->output();
					}

					continue;
				}
			}

			( new ConsoleTable() )->output_data( [ $guest_author_args ] );

			$guest_author_by_display_name = $this->co_authors_plus_logic->get_guest_author_by_display_name(
				$guest_author_args['display_name']
			);

			if ( $guest_author_by_display_name ) {
				// Should output info to determine if we need to force create.
				ConsoleColor::magenta( 'Existing Guest Author found by Display Name' )->output();
				if ( is_object( $guest_author_by_display_name ) ) {
					$guest_author_by_display_name = $guest_author_by_display_name->ID;
				} else {
					WP_CLI::error( 'Unknown value given by CAP GA retrieval' );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$import_key_exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d AND post_id = %d",
						ImportMeta::AUTHOR_ID_KEY->value,
						$legacy_author->id,
						$guest_author_by_display_name
					)
				);

				if ( empty( $import_key_exists ) ) {
					add_post_meta( $guest_author_by_display_name, ImportMeta::AUTHOR_ID_KEY->value, $legacy_author->id );
				}
				continue;
			}

			$guest_author_id = $this->co_authors_plus_logic->create_guest_author( $guest_author_args );

			if ( is_wp_error( $guest_author_id ) ) {
				if ( 'duplicate-field' === $guest_author_id->get_error_code() ) {
					$guest_author_by_user_login = $this->co_authors_plus_logic->get_guest_author_by_user_login(
						sanitize_title( $guest_author_args['display_name'] )
					);

					if ( ! is_wp_error( $guest_author_by_user_login ) ) {
						$guest_author_id = $guest_author_by_user_login->ID;
					}
				} else {
					ConsoleColor::red( 'Guest Author Creation Error:' )
								->bright_red( '(' )
								->underlined_bright_red( $guest_author_id->get_error_code() )
								->bright_red( ') ' )
								->bright_red( $guest_author_id->get_error_message() )->output();
					continue;
				}
			}

			$author_meta = [
				ImportMeta::AUTHOR_ID_KEY->value => $legacy_author->id,
			];

			if ( ! empty( $legacy_author->position ) ) {
				$author_meta['position'] = $legacy_author->position;
			}

			if ( ! empty( $legacy_author->indicator ) ) {
				$author_meta['indicator'] = $legacy_author->indicator;
			}

			if ( ! empty( $legacy_author->created_at ) ) {
				$author_meta['created_at'] = $legacy_author->created_at;
			}

			if ( ! empty( $legacy_author->deleted_at ) ) {
				$author_meta['deleted_at'] = $legacy_author->deleted_at;
			}

			foreach ( $author_meta as $meta_key => $meta_value ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$meta_exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s AND post_id = %d",
						$meta_key,
						$meta_value,
						$guest_author_id
					)
				);

				if ( ! $meta_exists ) {
					add_post_meta( $guest_author_id, $meta_key, $meta_value );
				}
			}

			ConsoleColor::green( 'Guest Author successfully created...' )->output();
		}
	}

	/**
	 * This command handles the migration of tags from PCIJ's old database to WordPress.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_migrate_tags( $args, $assoc_args ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_tags       = $wpdb->get_results( 'SELECT * FROM tags' );
		$legacy_tags_count = number_format( count( $legacy_tags ) );

		ConsoleColor::white( "$legacy_tags_count tags to process..." )->output();

		foreach ( $legacy_tags as $key => $legacy_tag ) {
			echo "\n\n\n";

			$current_key = number_format( $key + 1 );
			ConsoleColor::white( "Processing $current_key out of $legacy_tags_count" )->output();
			ConsoleColor::white( 'Original Tag ID:' )
						->underlined_bright_white( $legacy_tag->id )
						->white( 'Name:' )
						->underlined_bright_white( $legacy_tag->text )
						->output();

			$tag = wp_create_tag( $legacy_tag->text );

			if ( is_wp_error( $tag ) ) {
				ConsoleColor::red( 'Tag Creation Error:' )
							->bright_red( 'Legacy Tag ID:' )
							->underlined_bright_white( $legacy_tag->id )
							->bright_red( $tag->get_error_message() )
							->output();
				continue;
			}

			/*
			 * `wp_create_tag()` uses `term_exists()` under the hood. If the tag already exists, it will return
			 * an array with `term_id` and `term_taxonomy_id` as strings. However, if the tag is freshly created
			 * it will return an array with `term_id` and `term_taxonomy_id` as integers. To avoid doing this
			 * check twice, we'll just check if `term_id` is an integer and proceed with adding the meta. This
			 * saves a DB query.
			 */
			if ( is_int( $tag['term_id'] ) ) {
				add_term_meta( $tag['term_id'], ImportMeta::TAG_ID_KEY->value, $legacy_tag->id );
				add_term_meta( $tag['term_id'], ImportMeta::TAG_DATA_KEY->value . '_created_at', $legacy_tag->created_at );
				add_term_meta( $tag['term_id'], ImportMeta::TAG_DATA_KEY->value . '_updated_at', $legacy_tag->updated_at );
				add_term_meta( $tag['term_id'], ImportMeta::TAG_DATA_KEY->value . '_deleted_at', $legacy_tag->deleted_at );

				ConsoleColor::green( 'Tag successfully created...' )->output();
			} else {
				ConsoleColor::yellow( 'Tag already exists...' )->output();
			}
		}
	}

	/**
	 * This command handles the migration of subjects from PCIJ's old database to WordPress tags.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_migrate_subjects_as_tags( $args, $assoc_args ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_subjects       = $wpdb->get_results( 'SELECT * FROM subjects' );
		$legacy_subjects_count = number_format( count( $legacy_subjects ) );

		ConsoleColor::white( "$legacy_subjects_count subjects to process..." )->output();

		foreach ( $legacy_subjects as $key => $legacy_tag ) {
			echo "\n\n\n";

			$current_key = number_format( $key + 1 );
			ConsoleColor::white( "Processing $current_key out of $legacy_subjects_count" )->output();
			ConsoleColor::white( 'Original Subject ID:' )
						->underlined_bright_white( $legacy_tag->id )
						->white( 'Name:' )
						->underlined_bright_white( $legacy_tag->text )
						->output();

			$tag = wp_create_tag( $legacy_tag->text );

			if ( is_wp_error( $tag ) ) {
				ConsoleColor::red( 'Tag Creation Error:' )
							->bright_red( 'Legacy Subject ID:' )
							->underlined_bright_white( $legacy_tag->id )
							->bright_red( $tag->get_error_message() )
							->output();
				continue;
			}

			if ( is_int( $tag['term_id'] ) ) {
				add_term_meta( $tag['term_id'], ImportMeta::SUBJECT_ID_KEY->value, $legacy_tag->id );

				ConsoleColor::green( 'Tag successfully created...' )->output();
			} else {
				ConsoleColor::yellow( 'Tag already exists...' )->output();
			}
		}
	}

	/**
	 * This repeatable function handles the migration of legacy content from three main PCIJ tables:
	 * Articles, Blogs, and Data. It will create a new post for each legacy item and handle the
	 * necessary post meta and taxonomy relationships.
	 *
	 * @param array $args Positional Arguments.
	 * @param array $assoc_args {
	 * Associative arguments.
	 *
	 *     @type string $source The name of the source table.
	 * }
	 *
	 * @return void
	 * @throws ExitException Exits if the source table is invalid.
	 */
	public function cmd_migrate_legacy_items( $args, $assoc_args ): void {
		$source = $assoc_args['source'];
		$table  = MigrateTable::tryFrom( $source );

		if ( ! $table ) {
			WP_CLI::error( 'Invalid source table' );
		}

		$legacy_items_by_slug = match ( $table ) {
			MigrateTable::ARTICLES => $this->get_articles_to_migrate_by_slug(),
			MigrateTable::BLOGS    => $this->get_blogs_to_migrate_by_slug(),
			MigrateTable::DATA     => $this->get_data_items_to_migrate_by_slug(),
		};

		$legacy_items_count = count( $legacy_items_by_slug );
		$legacy_items_count = number_format( $legacy_items_count );
		ConsoleColor::white( "$legacy_items_count $table->value to process..." )->output();

		foreach ( $legacy_items_by_slug as $key => $legacy_item ) {
			echo "\n\n\n";

			$current_key = number_format( $key + 1 );
			ConsoleColor::white( "Processing $current_key out of $legacy_items_count" )->output();

			$legacy_item_ids          = explode( ',', $legacy_item->legacy_ids );
			$published_legacy_item_id = array_shift( $legacy_item_ids );

			$legacy_item = $this->get_legacy_item( $table, $published_legacy_item_id );
			ConsoleColor::white( 'Original ' . ucwords( $table->singular() ) . ' ID:' )
						->underlined_bright_white( $legacy_item->id )
						->white( 'Slug' )
						->underlined_bright_white( $legacy_item->slug )
						->output();

			$post_id = $this->has_item_been_imported( $table, $legacy_item->id );
			if ( $post_id ) {
				ConsoleColor::magenta( 'It seems this legacy ' . $table->singular() . ' has been previously imported...' )->output();
				ConsoleColor::magenta( 'Post ID:' )->underlined_bright_magenta( $post_id )->output();
				continue;
			}

			$imported_post_id = $this->create_post_from_legacy_item( $table, $legacy_item );
			if ( is_wp_error( $imported_post_id ) ) {
				ConsoleColor::red( 'Post Creation Error:' )->bright_red( $imported_post_id->get_error_message() )->output();
				continue;
			}
			ConsoleColor::white( 'Post ID:' )
						->underlined_bright_white( $imported_post_id )
						->white( 'URL' )
						->underlined_bright_white( get_site_url( null, "/?p=$imported_post_id" ) )
						->output();
			$this->add_imported_item_id_to_cache( $table, $legacy_item->id, $imported_post_id );

			// Handle authors.
			$this->cmd_set_post_authors_from_legacy_item(
				[],
				[
					'source'         => $table->value,
					'legacy-item-id' => $legacy_item->id,
					'post-id'        => $imported_post_id,
				]
			);

			// Need to handle featured image.
			$featured_image_result = $this->handle_featured_image_for_legacy_item( $legacy_item, $imported_post_id );
			if ( empty( $legacy_item->deleted_at ) ) { // If the legacy item is deleted, don't need to worry about featured image.
				ConsoleColor::underlined_black_with_white_background( 'Featured Image Result' )->output();
				if ( is_wp_error( $featured_image_result ) ) {
					switch ( $featured_image_result->get_error_code() ) {
						case 'null-featured-image':
						case 'not-an-image':
							ConsoleColor::yellow( $featured_image_result->get_error_message() )->output();
							break;
						case 'file-not-found':
							// Extra emphasis to make sure user notices and verifies whether the file exists or not.
							ConsoleColor::black_with_yellow_background( $featured_image_result->get_error_message() )->output();
							break;
						default:
							ConsoleColor::red( $featured_image_result->get_error_message() )->output();
							break;
					}
				} elseif ( false === $featured_image_result ) {
					ConsoleColor::bright_yellow( 'No error occurred, but no featured image was set.' )->output();
				} else {
					ConsoleColor::green( 'Featured Image successfully set...' )->output();
				}
			}

			// Need to handle categories and tags.
			if ( in_array( $table, [ MigrateTable::ARTICLES, MigrateTable::BLOGS ], true ) ) {
				$this->cmd_set_post_tags_from_legacy_tags_item(
					[],
					[
						'source'         => $table->value,
						'legacy-item-id' => $legacy_item->id,
						'post-id'        => $imported_post_id,
					]
				);
			}

			if ( in_array( $table, [ MigrateTable::BLOGS, MigrateTable::DATA ], true ) ) {
				$this->cmd_set_post_tags_from_legacy_subjects_item(
					[],
					[
						'source'         => $table->value,
						'legacy-item-id' => $legacy_item->id,
						'post-id'        => $imported_post_id,
					]
				);
			}

			if ( ! empty( $legacy_item_ids ) ) {
				$additional_legacy_item_versions       = $this->get_legacy_items( $table, $legacy_item_ids );
				$count_additional_legacy_item_versions = count( $additional_legacy_item_versions );
				ConsoleColor::white( "Processing $count_additional_legacy_item_versions revisions..." )->output();

				foreach ( $additional_legacy_item_versions as $additional_legacy_item_version ) {
					$post_id = $this->has_item_been_imported( $table, $additional_legacy_item_version->id );
					if ( $post_id ) {
						ConsoleColor::magenta( "It seems this legacy {$table->singular()} has been previously imported..." )->output();
						ConsoleColor::magenta( 'Post ID:' )->underlined_bright_magenta( $post_id )->output();
						continue;
					}

					$imported_revision_id = $this->create_post_from_legacy_item( $table, $additional_legacy_item_version, $imported_post_id );
					if ( is_wp_error( $imported_revision_id ) ) {
						ConsoleColor::red( 'Post Revision Creation Error:' )->bright_red( $imported_revision_id->get_error_message() )->output();
						continue;
					}
					ConsoleColor::white( 'Revision Original ID:' )
								->underlined_bright_white( $additional_legacy_item_version->id )
								->white( 'Post ID:' )
								->underlined_bright_white( $imported_revision_id )
								->output();
					$this->add_imported_item_id_to_cache( $table, $additional_legacy_item_version->id, $imported_revision_id );
				}
			}
		}
	}

	/**
	 * This function contains the SQL query which obtains the articles that must be migrated, taking into account
	 * any previously migrated articles. It returns an array of objects, each containing the slug and the legacy
	 * article IDs.
	 *
	 * @return array|object[]
	 */
	public function get_articles_to_migrate_by_slug(): array {
		global $wpdb;

		/*
		 * To get a little more background on this query, see the following links:
		 *
		 * @link https://eddiescodeshop.blog/2020/software-development/mysql-when-to-order-before-group/
		 * @link https://popsql.com/learn-sql/mysql/how-to-get-the-first-row-per-group-in-mysql
		 *
		 * PCIJ's old database has a `version` column in the `articles` table. This column is used to track
		 * the latest version of an article. To provide the best experience for PCIJ staff, this importer
		 * will import the first version of an article, then import the rest of the versions as revisions.
		 * This query helps in achieving that by getting all articles by unique slug, and the corresponding
		 * article IDs in order of `version`. The first article ID is the published one that should be imported.
		 * The following article IDs are revisions that should be imported as revisions.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
				WITH ordered_articles as ( 
					SELECT ROW_NUMBER() over ( 
						PARTITION BY slug ORDER BY version, deleted_at 
						) as virtual_id, articles.* 
						FROM articles 
					) 
				SELECT slug, GROUP_CONCAT( id ) as legacy_ids 
				FROM ordered_articles 
				GROUP BY slug ) as slug_and_article_ids 
				WHERE slug_and_article_ids.slug NOT IN (
					SELECT DISTINCT REPLACE( p.post_name, '__trashed', '' ) 
					FROM $wpdb->posts p 
					    INNER JOIN $wpdb->postmeta pm 
					        ON p.ID = pm.post_id 
					WHERE pm.meta_key = %s 
				)",
				ImportMeta::ARTICLE_ID_KEY->value
			)
		);
	}

	/**
	 * This function contains the SQL query which obtains the blogs that must be migrated, taking into account
	 * any previously migrated blogs. It returns an array of objects, each containing the slug and the legacy
	 * blog IDs.
	 *
	 * @return array|object[]
	 */
	public function get_blogs_to_migrate_by_slug(): array {
		global $wpdb;

		/*
		 * To get a little more background on this query, see the following links:
		 *
		 * @link https://eddiescodeshop.blog/2020/software-development/mysql-when-to-order-before-group/
		 * @link https://popsql.com/learn-sql/mysql/how-to-get-the-first-row-per-group-in-mysql
		 *
		 * PCIJ's old database has a `version` column in the `blogs` table. This column is used to track
		 * the latest version of a blog. To provide the best experience for PCIJ staff, this importer
		 * will import the first version of a blog, then import the rest of the versions as revisions.
		 * This query helps in achieving that by getting all blogs by unique slug, and the corresponding
		 * blog IDs in order of `version`. The first blog ID is the published one that should be imported.
		 * The following blog IDs are revisions that should be imported as revisions.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
				WITH ordered_blogs as ( 
					SELECT ROW_NUMBER() over ( 
						PARTITION BY slug ORDER BY version, deleted_at 
						) as virtual_id, blogs.* 
						FROM blogs 
					) 
				SELECT slug, GROUP_CONCAT( id ) as legacy_ids 
				FROM ordered_blogs 
				GROUP BY slug ) as slugs_and_blog_ids 
				WHERE slugs_and_blog_ids.slug NOT IN (
					SELECT DISTINCT REPLACE( p.post_name, '__trashed', '' ) 
					FROM $wpdb->posts p 
					    INNER JOIN $wpdb->postmeta pm 
					        ON p.ID = pm.post_id 
					WHERE pm.meta_key = %s )",
				ImportMeta::BLOG_ID_KEY->value
			)
		);
	}

	/**
	 * This function contains the SQL query which obtains the data items that must be migrated, taking into account
	 * any previously migrated data items. It returns an array of objects, each containing the slug and the legacy
	 * data item IDs.
	 *
	 * @return array|object[]
	 */
	public function get_data_items_to_migrate_by_slug(): array {
		global $wpdb;

		/*
		 * To get a little more background on this query, see the following links:
		 *
		 * @link https://eddiescodeshop.blog/2020/software-development/mysql-when-to-order-before-group/
		 * @link https://popsql.com/learn-sql/mysql/how-to-get-the-first-row-per-group-in-mysql
		 *
		 * PCIJ's old database has a `version` column in the `data` table. This column is used to track
		 * the latest version of a data entry. To provide the best experience for PCIJ staff, this importer
		 * will import the first version of a data entry, then import the rest of the versions as revisions.
		 * This query helps in achieving that by getting all data entries by unique slug, and the corresponding
		 * data entry IDs in order of `version`. The first data entry ID is the published one that should be imported.
		 * The following data entry IDs are revisions that should be imported as revisions.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
				WITH ordered_data_items as ( 
					SELECT ROW_NUMBER() over ( 
						PARTITION BY slug ORDER BY version, deleted_at 
						) as virtual_id, data.* 
						FROM data 
					) 
				SELECT slug, GROUP_CONCAT( id ) as legacy_ids 
				FROM ordered_data_items 
				GROUP BY slug ) as slugs_and_data_item_ids 
				WHERE slugs_and_data_item_ids.slug NOT IN (
					SELECT DISTINCT REPLACE( p.post_name, '__trashed', '' ) 
					FROM $wpdb->posts p 
					    INNER JOIN $wpdb->postmeta pm 
					        ON p.ID = pm.post_id 
					WHERE pm.meta_key = %s )",
				ImportMeta::DATA_ID_KEY->value
			)
		);
	}

	/**
	 * Command to add the legacy authors from a PCIJ article to a post.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args {
	 *     Associative arguments.
	 *
	 *     @type string $source The name of the source table.
	 *     @type int    $legacy-item-id The legacy item ID.
	 *     @type int    $post-id The post ID.
	 * }
	 *
	 * @return void
	 * @throws ExitException When the source table is invalid.
	 */
	public function cmd_set_post_authors_from_legacy_item( $args, $assoc_args ): void {
		$source         = $assoc_args['source'];
		$legacy_item_id = $assoc_args['legacy-item-id'];
		$post_id        = $assoc_args['post-id'];

		$table = MigrateTable::tryFrom( $source );

		if ( ! $table ) {
			WP_CLI::error( 'Invalid source table' );
		}

		$legacy_authors = $this->get_legacy_authors( $table, $legacy_item_id );
		$authors        = [];

		if ( empty( $legacy_authors ) ) {
			ConsoleColor::yellow( "No legacy authors found for this {$table->singular()}..." )->output();
			return;
		}

		foreach ( $legacy_authors as $legacy_author ) {
			if ( ! empty( $legacy_author->custom_author_id ) ) {
				ConsoleColor::yellow( 'Custom Author Name:' )->underlined_bright_yellow( $legacy_author->custom_author_name )->output();
				$author = $this->co_authors_plus_logic->get_guest_author_by_display_name( $legacy_author->custom_author_name );

				if ( empty( $author ) ) {
					$author_id = $this->co_authors_plus_logic->create_guest_author( [ 'display_name' => $legacy_author->custom_author_name ] );

					if ( is_wp_error( $author_id ) ) {
						if ( 'duplicate-field' === $author_id->get_error_code() ) {
							$author_id = $this->co_authors_plus_logic->get_guest_author_by_user_login(
								sanitize_title( $legacy_author->custom_author_name )
							);

							if ( ! is_wp_error( $author_id ) ) {
								$authors[] = $author_id;
								continue;
							}
						} else {
							ConsoleColor::red( 'Custom Guest Author Creation Error:' )
										->bright_red( '(' )
										->underlined_bright_red( $author_id->get_error_code() )
										->bright_red( ') ' )
										->bright_red( $author_id->get_error_message() )->output();
							continue;
						}
					}
					$authors[] = $this->co_authors_plus_logic->get_guest_author_by_id( $author_id );
					continue;
				}

				$authors[] = $author;
				continue;
			}

			ConsoleColor::white( 'Original Author ID:' )
						->underlined_bright_white( $legacy_author->author_id )
						->white( 'Name:' )
						->underlined_bright_white( $legacy_author->name )
						->output();
			$author = $this->get_author_by_legacy_author_id( $legacy_author->author_id );

			if ( false === $author ) {
				ConsoleColor::red( 'Author not found...' )->output();
				( new ConsoleTable() )->output_data( [ $legacy_author ] );
				continue;
			}

			$authors[] = $author;
		}

		if ( empty( $authors ) ) {
			ConsoleColor::magenta( 'No authors to assign to post...' )->output();
			return;
		}

		$this->co_authors_plus_logic->assign_authors_to_post( $authors, $post_id );

		ConsoleColor::green( 'Authors successfully assigned to post...' )->output();
	}

	/**
	 * Checks if an item has been imported.
	 *
	 * @param MigrateTable $table The table to check.
	 * @param int          $legacy_item_id The legacy item ID.
	 *
	 * @return bool|int False if not imported, or the post ID if imported.
	 */
	public function has_item_been_imported( MigrateTable $table, int $legacy_item_id ): bool|int {
		if ( ! wp_cache_get( $table->get_cache_key()->value, CacheKey::GROUP->value ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$imported_items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value, post_id FROM $wpdb->postmeta WHERE meta_key = %s",
					$table->get_import_id_meta_key()->value
				),
				OBJECT_K
			);
			if ( empty( $imported_items ) ) {
				$imported_items = [];
			} else {
				$imported_items = array_map( fn( $imported_item ) => $imported_item->post_id, $imported_items );
			}

			/*
			 * $imported_items looks like this:
			 * [
			 *      123 (Original Article/Blog/Data ID) => 456 (Post ID),
			 *      ...
			 * ]
			 */

			wp_cache_set( $table->get_cache_key()->value, $imported_items, CacheKey::GROUP->value, 60 * 60 * 24 );
		}

		return wp_cache_get( $table->get_cache_key()->value, CacheKey::GROUP->value )[ $legacy_item_id ] ?? false;
	}

	/**
	 * Handles adding an imported item ID to the cache.
	 *
	 * @param MigrateTable $table The table the legacy ID comes from.
	 * @param int          $legacy_item_id The legacy article ID.
	 * @param int          $post_id The post ID.
	 *
	 * @return bool
	 */
	public function add_imported_item_id_to_cache( MigrateTable $table, int $legacy_item_id, int $post_id ): bool {
		return wp_cache_set(
			$table->get_cache_key()->value,
			wp_cache_get( $table->get_cache_key()->value, CacheKey::GROUP->value ) + [ $legacy_item_id => $post_id ],
			CacheKey::GROUP->value,
		);
	}

	/**
	 * Creates a post from a legacy article.
	 *
	 * @param MigrateTable $table The source table of the legacy item.
	 * @param object       $legacy_item The legacy item object.
	 * @param int          $parent_post_id The parent post ID.
	 *
	 * @return int|WP_Error The post ID if successful, or false if not.
	 */
	public function create_post_from_legacy_item( MigrateTable $table, object $legacy_item, int $parent_post_id = 0 ): int|WP_Error {
		$post_args = match ( $table ) {
			MigrateTable::ARTICLES => $this->get_post_args_for_article( $legacy_item, $parent_post_id ),
			MigrateTable::BLOGS    => $this->get_post_args_for_blog( $legacy_item, $parent_post_id ),
			MigrateTable::DATA     => $this->get_post_args_for_data( $legacy_item, $parent_post_id ),
		};

		$post_id = wp_insert_post( $post_args, true );

		if ( ! is_wp_error( $post_id ) ) {
			global $wpdb;
			// Annoying but has to be done because of the $post_name sanitization that happens in wp_insert_post.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				[
					'post_name' => $post_args['post_name'],
				],
				[
					'ID' => $post_id,
				]
			);
			add_post_meta( $post_id, $table->get_import_id_meta_key()->value, $legacy_item->id, true );
			$this->save_meta_data_for_imported_item( $table, $legacy_item, $post_id );
		}

		return $post_id;
	}

	/**
	 * This function will help with getting the necessary post arguments for a legacy article.
	 *
	 * @param object $legacy_article The legacy article object.
	 * @param int    $parent_post_id The parent post ID.
	 *
	 * @return array
	 */
	public function get_post_args_for_article( object $legacy_article, int $parent_post_id = 0 ): array {
		$post_args = [
			'post_author'       => 1,
			'post_content'      => $legacy_article->body,
			'post_title'        => $legacy_article->title,
			'post_excerpt'      => $legacy_article->summary,
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_name'         => $legacy_article->slug,
			'post_modified'     => $legacy_article->modified,
			'post_modified_gmt' => $legacy_article->modified,
			'post_type'         => 'post',
		];

		switch ( $legacy_article->status ) {
			case 0:
			case 1:
				$post_args['post_status']   = 'draft';
				$post_args['post_date']     = $legacy_article->created;
				$post_args['post_date_gmt'] = $legacy_article->created;
				break;
			case 2:
				$post_args['post_status']   = 'publish';
				$post_args['post_date']     = $legacy_article->pubdate;
				$post_args['post_date_gmt'] = $legacy_article->pubdate;
				break;
		}

		if ( $parent_post_id ) {
			$post_args['post_parent'] = $parent_post_id;
			$post_args['post_name']   = $parent_post_id . '-revision-v1';
			$post_args['post_type']   = 'revision';
			$post_args['post_status'] = 'inherit';
		}

		if ( null !== $legacy_article->deleted_at ) {
			$post_args['post_status'] = 'trash';
			$post_args['post_name']   = $legacy_article->slug . '__trashed';
		}

		return $post_args;
	}

	/**
	 * Gets the necessary post arguments for a legacy blog.
	 *
	 * @param object $legacy_blog The legacy blog object.
	 * @param int    $parent_post_id The parent post ID.
	 *
	 * @return array
	 */
	public function get_post_args_for_blog( object $legacy_blog, int $parent_post_id = 0 ): array {
		// Blog seems to be identical to an article, so we'll just use the same function.
		// If we discover there is a difference, we can refactor this.
		return $this->get_post_args_for_article( $legacy_blog, $parent_post_id );
	}

	/**
	 * Gets the necessary post arguments for a legacy data item.
	 *
	 * @param object $legacy_data_item The legacy data item object.
	 * @param int    $parent_post_id The parent post ID.
	 *
	 * @return array
	 */
	public function get_post_args_for_data( object $legacy_data_item, int $parent_post_id = 0 ): array {
		$legacy_data_item->created = $legacy_data_item->created_at;
		$legacy_data_item->summary = '';
		// Data is mostly identical to an article, except for the data points above.
		// We can use the same function for now, but if further modifications, we can refactor this.
		return $this->get_post_args_for_article( $legacy_data_item, $parent_post_id );
	}

	/**
	 * This function will help with saving important legacy item metadata for future reference.
	 *
	 * @param MigrateTable $table The source table of the legacy item.
	 * @param object       $legacy_item The legacy item.
	 * @param int          $post_id The post ID.
	 *
	 * @return bool
	 */
	public function save_meta_data_for_imported_item( MigrateTable $table, object $legacy_item, int $post_id ): bool {
		$meta_data = match ( $table ) {
			MigrateTable::ARTICLES => $this->get_post_meta_for_article( $legacy_item ),
			MigrateTable::BLOGS    => $this->get_post_meta_for_blog( $legacy_item ),
			MigrateTable::DATA     => $this->get_post_meta_for_data( $legacy_item ),
		};

		$tally = true;
		foreach ( $meta_data as $meta_key => $meta_value ) {
			if ( ! str_starts_with( $meta_key, $table->get_import_data_meta_key()->value ) ) {
				$meta_key = $table->get_import_data_meta_key()->value . "_$meta_key";
			}

			$tally = $tally && add_post_meta( $post_id, $meta_key, $meta_value, true );
		}

		return $tally;
	}

	/**
	 * This function will help with getting legacy article data that we'd like to save as post meta.
	 *
	 * @param object $legacy_article The legacy article object.
	 *
	 * @return array
	 */
	public function get_post_meta_for_article( object $legacy_article ): array {
		return [
			'slug'              => $legacy_article->slug,
			'pubdate'           => $legacy_article->pubdate,
			'summary'           => $legacy_article->summary,
			'pre_head'          => $legacy_article->pre_head,
			'post_head'         => $legacy_article->post_head,
			'publishing_date'   => $legacy_article->publishing_date,
			'tagline'           => $legacy_article->tagline,
			'tagline_date'      => $legacy_article->tagline_date,
			'general_notes'     => $legacy_article->general_notes,
			'series_indicator'  => $legacy_article->series_indicator,
			'ireport_indicator' => $legacy_article->ireport_indicator,
			'related_articles'  => $legacy_article->related_articles,
			'owner'             => $legacy_article->owner,
			'subjects'          => $legacy_article->subjects,
			'last_edit'         => $legacy_article->last_edit,
			'subject'           => $legacy_article->subject,
			'current_editor'    => $legacy_article->current_editor,
			'last_edited'       => $legacy_article->last_edited,
		];
	}

	/**
	 * Gets the necessary post arguments for a legacy blog.
	 *
	 * @param object $legacy_blog The legacy blog object.
	 *
	 * @return array
	 */
	public function get_post_meta_for_blog( object $legacy_blog ): array {
		$legacy_blog->related_articles = null;
		$legacy_blog->last_edit        = null;

		$meta_data = $this->get_post_meta_for_article( $legacy_blog );

		unset( $meta_data['related_articles'] );
		unset( $meta_data['last_edit'] );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$meta_data['Category'] = $legacy_blog->Category;

		return $meta_data;
	}

	/**
	 * Gets the necessary post arguments for a legacy data item.
	 *
	 * @param object $legacy_data_item The legacy data item object.
	 *
	 * @return array
	 */
	public function get_post_meta_for_data( object $legacy_data_item ): array {
		return [
			'slug'           => $legacy_data_item->slug,
			'pubdate'        => $legacy_data_item->pubdate,
			'general_notes'  => $legacy_data_item->general_notes,
			'owner'          => $legacy_data_item->owner,
			'last_edit'      => $legacy_data_item->last_edit,
			'current_editor' => $legacy_data_item->current_editor,
			'last_edited'    => $legacy_data_item->last_edited,
		];
	}

	/**
	 * This function will handle the retrieval and upload of a legacy article's featured image.
	 *
	 * @param object $legacy_item The legacy article object.
	 * @param int    $post_id The post ID.
	 *
	 * @return bool|WP_Error
	 */
	public function handle_featured_image_for_legacy_item( object $legacy_item, int $post_id ): bool|WP_Error {
		if ( empty( $legacy_item->featured_image ) ) {
			return new WP_Error( 'null-featured-image', 'No featured image to upload.' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$featured_image = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM files WHERE id = %d',
				$legacy_item->featured_image
			)
		);

		if ( ! $featured_image->is_image ) {
			return new WP_Error( 'not-an-image', 'This does not point to an image.' );
		}

		$media_cache_dir = defined( 'ATOMIC_SITE_ID' ) ? '/tmp/media/' : WP_CONTENT_DIR . '/media/';

		if ( ! file_exists( $media_cache_dir . $featured_image->path ) ) {
			return new WP_Error( 'file-not-found', 'The file does not exist.' );
		}

		$result = $this->attachments->import_external_file(
			$media_cache_dir . $featured_image->path,
			null,
			$featured_image->caption,
			null,
			null,
			$post_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (bool) add_post_meta( $post_id, '_thumbnail_id', $result, true );
	}

	/**
	 * This function handles getting tags from a legacy article or blog and setting them to a post.
	 *
	 * @param array $args Positional Arguments.
	 * @param array $assoc_args {
	 * Associative arguments.
	 *
	 *     @type string $source The name of the source table.
	 *     @type int $legacy-item-id The legacy item ID.
	 *     @type int $post-id The post ID.
	 * }
	 *
	 * @return void
	 * @throws ExitException When the source table is invalid.
	 */
	public function cmd_set_post_tags_from_legacy_tags_item( $args, $assoc_args ): void {
		$source         = $assoc_args['source'];
		$legacy_item_id = $assoc_args['legacy-item-id'];
		$post_id        = $assoc_args['post-id'];

		$table = MigrateTable::tryFrom( $source );

		if ( ! $table ) {
			WP_CLI::error( 'Invalid source table' );
		} elseif ( MigrateTable::DATA === $table ) {
			ConsoleColor::yellow( 'Data items do not have tags...' )->output();
			return;
		}

		$relationship_table = match ( $table ) {
			MigrateTable::ARTICLES => 'tag_article',
			MigrateTable::BLOGS    => 'tag_blog',
		};

		$foreign_key = $table->get_foreign_column_name();

		global $wpdb;

		// phpcs:disable -- Need uncached query, and this has been prepared; interpolated values are generated from enum values.
		$legacy_item_tag_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tag_id FROM $relationship_table WHERE $foreign_key = %d",
				$legacy_item_id
			)
		);
		// phpcs:enable

		$this->assign_legacy_meta_items_to_post( ImportMeta::TAG_ID_KEY, $post_id, $legacy_item_tag_ids );
	}

	/**
	 * This function handles getting subjects from a legacy blog or data item and setting them to a post.
	 *
	 * @param array $args Positional Arguments.
	 * @param array $assoc_args {
	 * Associative arguments.
	 *
	 *     @type string $source The name of the source table.
	 *     @type int $legacy-item-id The legacy item ID.
	 *     @type int $post-id The post ID.
	 * }
	 *
	 * @return void
	 * @throws ExitException When the source table is invalid.
	 */
	public function cmd_set_post_tags_from_legacy_subjects_item( $args, $assoc_args ): void {
		$source         = $assoc_args['source'];
		$legacy_item_id = $assoc_args['legacy-item-id'];
		$post_id        = $assoc_args['post-id'];

		$table = MigrateTable::tryFrom( $source );

		if ( ! $table ) {
			WP_CLI::error( 'Invalid source table' );
		} elseif ( MigrateTable::ARTICLES === $table ) {
			ConsoleColor::yellow( 'Articles do not have subjects...' )->output();
			return;
		}

		$relationship_table = match ( $table ) {
			MigrateTable::BLOGS => 'subject_blog',
			MigrateTable::DATA => 'subject_data',
		};

		$foreign_key = $table->get_foreign_column_name();

		global $wpdb;

		// phpcs:disable -- Need uncached query, and this has been prepared; interpolated values are generated from enum values.
		$legacy_subject_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.id FROM subjects s INNER JOIN $relationship_table r ON s.id = r.subject_id WHERE r.$foreign_key = %d",
				$legacy_item_id
			)
		);
		// phpcs:enable

		$this->assign_legacy_meta_items_to_post( ImportMeta::SUBJECT_ID_KEY, $post_id, $legacy_subject_ids );
	}

	/**
	 * This function handles the actual assignment of Post Tags to Posts from a provided list of legacy Tag/Subject IDs
	 * that have already been imported.
	 *
	 * @param ImportMeta $meta_item The meta item to assign to the post, either a Tag or a Subject.
	 * @param int        $post_id The post ID.
	 * @param int[]      $legacy_meta_item_ids The legacy Tag/Subject IDs.
	 *
	 * @return void
	 * @throws ExitException Only legacy Tags and Subjects can be assigned as Post Tags.
	 */
	private function assign_legacy_meta_items_to_post( ImportMeta $meta_item, int $post_id, array $legacy_meta_item_ids ): void {
		$meta_key = match ( $meta_item ) {
			ImportMeta::TAG_ID_KEY, ImportMeta::SUBJECT_ID_KEY => $meta_item->value,
			default => WP_CLI::error( 'Invalid meta item' ),
		};

		$count_legacy_meta_item_ids = count( $legacy_meta_item_ids );

		if ( 0 === $count_legacy_meta_item_ids ) {
			$meta_item_name = match ( $meta_item ) {
				ImportMeta::TAG_ID_KEY    => 'tags',
				ImportMeta::SUBJECT_ID_KEY => 'subjects',
			};
			ConsoleColor::yellow( "No legacy $meta_item_name to assign to post..." )->output();
			return;
		}

		$legacy_meta_item_ids_placeholder = implode( ',', array_fill( 0, $count_legacy_meta_item_ids, '%d' ) );

		global $wpdb;

		// phpcs:disable -- Need uncached query, and this has been prepared, escaped, and proper placeholders inserted.
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, t.slug, tt.taxonomy 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt 
				        ON t.term_id = tt.term_id 
				    INNER JOIN $wpdb->termmeta tm 
				        ON t.term_id = tm.term_id 
				WHERE tm.meta_key = %s 
				  AND tm.meta_value IN ( $legacy_meta_item_ids_placeholder )",
				$meta_key,
				...$legacy_meta_item_ids
			)
		);
		// phpcs:enable

		wp_remove_object_terms(
			$post_id,
			wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'ids' ] ),
			'post_tag'
		);

		foreach ( $tags as $tag ) {
			ConsoleColor::white( 'Adding tag:' )
						->underlined_bright_white( $tag->name )
						->white( 'to post...' )
						->output();
			$result = wp_set_post_terms( $post_id, $tag->slug, $tag->taxonomy, true );

			if ( is_wp_error( $result ) ) {
				ConsoleColor::red( 'Error:' )
							->bright_red( $result->get_error_message() )
							->output();
			} elseif ( false === $result ) {
				ConsoleColor::yellow( 'Tag already assigned to post...' )->output();
			} else {
				ConsoleColor::green( 'Tag successfully assigned to post...' )->output();
			}
		}
	}

	/**
	 * Convenience function to get a legacy items by its ID.
	 *
	 * @param MigrateTable $table The table to query. Values: 'articles', 'blogs', 'data'.
	 * @param int          $legacy_item_id The legacy article ID.
	 *
	 * @return object|WP_Error
	 */
	public function get_legacy_item( MigrateTable $table, int $legacy_item_id ): object {
		$legacy_items = $this->get_legacy_items( $table, [ $legacy_item_id ] );

		if ( is_wp_error( $legacy_items ) ) {
			return $legacy_items;
		}

		return ! empty( $legacy_items ) && 1 === count( $legacy_items ) ?
			$legacy_items[0]
			: new WP_Error( 'legacy_item_not_found', "Legacy $table->value not found" );
	}

	/**
	 * Convenience function to get many legacy items by their IDs.
	 *
	 * @param MigrateTable $table The table to query. Values: 'articles', 'blogs', 'data'.
	 * @param array        $legacy_item_ids The legacy article IDs.
	 *
	 * @return WP_Error|object[]
	 */
	public function get_legacy_items( MigrateTable $table, array $legacy_item_ids ): WP_Error|array {
		if ( empty( $legacy_item_ids ) ) {
			return new WP_Error( 'no_legacy_item_ids', "No legacy $table->value IDs provided" );
		}

		$legacy_item_ids_placeholder = implode( ',', array_fill( 0, count( $legacy_item_ids ), '%d' ) );

		global $wpdb;

		// phpcs:disable -- Need uncached query, and this has been prepared, escaped, and proper placeholders inserted.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table->value WHERE id IN ( $legacy_item_ids_placeholder )",
				...$legacy_item_ids
			)
		);
		//phpcs:enable
	}

	/**
	 * Convenience function to get legacy authors associated to an article ID.
	 *
	 * @param MigrateTable $table The table to query. Values: 'articles', 'blogs', 'data'.
	 * @param int          $legacy_item_id The legacy item ID.
	 *
	 * @return object[]
	 */
	public function get_legacy_authors( MigrateTable $table, int $legacy_item_id ): array {
		global $wpdb;

		$relationship_table = match ( $table ) {
			MigrateTable::ARTICLES => 'author_article',
			MigrateTable::BLOGS    => 'author_blog',
			MigrateTable::DATA     => 'author_data',
		};

		$custom_author_relationship_table = match ( $table ) {
			MigrateTable::ARTICLES => 'article_custom_authors',
			MigrateTable::BLOGS    => 'blog_custom_authors',
			MigrateTable::DATA     => 'data_custom_authors',
		};

		// phpcs:disable -- Need uncached values, and this has been prepared, values are escaped via use of enum values.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
    				rel.author_id, 
    				rel.custom_author_id, 
    				cus_rel.author as custom_author_name, 
    				a.name 
				FROM $relationship_table rel 
				    LEFT JOIN $custom_author_relationship_table cus_rel 
				        ON rel.custom_author_id = cus_rel.id 
				LEFT JOIN authors a 
				    ON rel.author_id = a.id 
				WHERE rel.{$table->get_foreign_column_name()} = %d",
				$legacy_item_id
			)
		);
		// phpcs:enable
	}

	/**
	 * This function will obtain a Guest Author by their legacy author ID.
	 *
	 * @param int $legacy_author_id The legacy author ID.
	 *
	 * @return false|object
	 */
	public function get_author_by_legacy_author_id( int $legacy_author_id ): bool|object {
		if ( ! wp_cache_get( CacheKey::IMPORTED_AUTHOR->value, CacheKey::GROUP->value ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$imported_authors = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value, post_id FROM $wpdb->postmeta WHERE meta_key = %s",
					ImportMeta::AUTHOR_ID_KEY->value
				),
				OBJECT_K
			);
			if ( empty( $imported_authors ) ) {
				$imported_authors = [];
			} else {
				$imported_authors = array_map( fn( $imported_article ) => $this->co_authors_plus_logic->get_guest_author_by_id( $imported_article->post_id ), $imported_authors );
			}

			/*
			 * $imported_authors looks like this:
			 * [
			 *      123 (Original Author ID) => Guest Author Object,
			 *      ...
			 * ]
			 */

			wp_cache_set( CacheKey::IMPORTED_AUTHOR->value, $imported_authors, CacheKey::GROUP->value, 60 * 60 * 24 );
		}

		return wp_cache_get( CacheKey::IMPORTED_AUTHOR->value, CacheKey::GROUP->value )[ $legacy_author_id ] ?? false;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Since this migrator is only for PCIJ, it doesn't make sense to put this in a separate file.
enum CacheKey: string {
	case GROUP             = 'newspack_pcij_migration_group';
	case IMPORTED_ARTICLES = 'newspack_pcij_articles_migration_migrated_articles';
	case IMPORTED_BLOGS    = 'newspack_pcij_articles_migration_migrated_blogs';
	case IMPORTED_DATA     = 'newspack_pcij_articles_migration_migrated_data';
	case IMPORTED_AUTHOR   = 'newspack_pcij_articles_migration_migrated_authors';
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Since this migrator is only for PCIJ, it doesn't make sense to put this in a separate file.
enum MigrateTable: string {
	case ARTICLES = 'articles';
	case BLOGS    = 'blogs';
	case DATA     = 'data';

	/**
	 * Get the singular form of the table name.
	 *
	 * @return string
	 */
	public function singular(): string {
		return match ( $this ) {
			self::ARTICLES => 'article',
			self::BLOGS    => 'blog',
			self::DATA     => 'data',
		};
	}

	/**
	 * Get the foreign column name for the table.
	 *
	 * @return string
	 */
	public function get_foreign_column_name(): string {
		return match ( $this ) {
			self::ARTICLES => 'article_id',
			self::BLOGS    => 'blog_id',
			self::DATA     => 'data_id',
		};
	}

	/**
	 * Get the cache key for the table.
	 *
	 * @return CacheKey
	 */
	public function get_cache_key(): CacheKey {
		return match ( $this ) {
			self::ARTICLES => CacheKey::IMPORTED_ARTICLES,
			self::BLOGS    => CacheKey::IMPORTED_BLOGS,
			self::DATA     => CacheKey::IMPORTED_DATA,
		};
	}

	/**
	 * Get the import ID meta key for the table.
	 *
	 * @return ImportMeta
	 */
	public function get_import_id_meta_key(): ImportMeta {
		return match ( $this ) {
			self::ARTICLES => ImportMeta::ARTICLE_ID_KEY,
			self::BLOGS    => ImportMeta::BLOG_ID_KEY,
			self::DATA     => ImportMeta::DATA_ID_KEY,
		};
	}

	/**
	 * Get the import data meta key for the table.
	 *
	 * @return ImportMeta
	 */
	public function get_import_data_meta_key(): ImportMeta {
		return match ( $this ) {
			self::ARTICLES => ImportMeta::ARTICLE_DATA_KEY,
			self::BLOGS    => ImportMeta::BLOG_DATA_KEY,
			self::DATA     => ImportMeta::DATA_DATA_KEY,
		};
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Since this migrator is only for PCIJ, it doesn't make sense to put this in a separate file.
enum ImportMeta: string {
	case AUTHOR_ID_KEY  = 'newspack_pcij_legacy_author_id';
	case ARTICLE_ID_KEY = 'newspack_pcij_legacy_article_id';
	case DATA_ID_KEY    = 'newspack_pcij_legacy_data_id';
	case BLOG_ID_KEY    = 'newspack_pcij_legacy_blog_id';
	case TAG_ID_KEY     = 'newspack_pcij_legacy_tag_id';
	case SUBJECT_ID_KEY = 'newspack_pcij_legacy_subject_id';

	case ARTICLE_DATA_KEY = 'newspack_pcij_migration_legacy_article_meta';
	case DATA_DATA_KEY    = 'newspack_pcij_migration_legacy_data_meta';
	case BLOG_DATA_KEY    = 'newspack_pcij_migration_legacy_blog_meta';
	case TAG_DATA_KEY     = 'newspack_pcij_migration_legacy_tag_meta';
	case SUBJECT_DATA_KEY = 'newspack_pcij_migration_legacy_subject_meta';
}
