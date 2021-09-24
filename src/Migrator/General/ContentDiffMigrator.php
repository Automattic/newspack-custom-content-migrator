<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_User;

class ContentDiffMigrator implements InterfaceMigrator {

	const LIVE_DIFF_CONTENT_IDS_CSV = 'newspack-live-diff-content-ids-csv.txt';

	const DATAKEY_POST = 'post';
	const DATAKEY_POSTMETA = 'postmeta';
	const DATAKEY_COMMENTS = 'comment';
	const DATAKEY_COMMENTMETA = 'commentmeta';
	const DATAKEY_USERS = 'users';
	const DATAKEY_USERMETA = 'usermeta';
	const DATAKEY_TERMRELATIONSHIPS = 'term_relationships';
	const DATAKEY_TERMTAXONOMY = 'term_taxonomy';
	const DATAKEY_TERMS = 'terms';
	const DATAKEY_TERMMETA = 'termmeta';

	/**
	 * @var null|InterfaceMigrator Instance.
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
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-search-new-content-on-live',
			[ $this, 'cmd_search_new_content_on_live' ],
			[
				'shortdesc' => 'Searches for new posts existing in the Live site tables and not in the local site tables, and exports the IDs to a file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir',
						'description' => 'Folder to export the IDs to.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-import-new-live-content',
			[ $this, 'cmd_migrate_live_content' ],
			[
				'shortdesc' => 'Migrates content from Live site tables to local site tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'import-dir',
						'description' => 'Folder containing the file with list of IDs to migrate.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_search_new_content_on_live( $args, $assoc_args ) {
		$export_dir = $assoc_args[ 'export-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;

		WP_CLI::log( 'Searching for new content on Live Site...' );
		$ids = $this->get_live_diff_content_ids( $live_table_prefix );

		$file = $export_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV;
		file_put_contents( $export_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV, implode( ',', $ids ) );

		WP_CLI::success( sprintf( 'Diff content IDs exported to %s .', $file ) );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-import-new-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		$import_dir = $assoc_args[ 'import-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;

		$file = $import_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV;
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( 'File not found.' );
		}
		$post_ids = explode( ',', file_get_contents( $file ) );
		if ( empty( $post_ids ) ) {
			WP_CLI::error( 'File does not contain valid CSV IDs.' );
		}

		$imported_post_ids = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d/%d) migrating %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$data = $this->get_data( $post_id, $live_table_prefix );
			$new_post_id = $this->import_data( $data );

			$imported_post_ids[ $post_id ] = $new_post_id;
		}

		// Flush the cache in order for the `$wpdb->update()`s to sink in.
		wp_cache_flush();

		WP_CLI::log( 'Updating the parent IDs...' );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			$this->update_post_parent( $post_id, $imported_post_ids );
		}

		wp_cache_flush();
	}

	/**
	 * Gets a diff of new Posts and Pages from the Live Site.
	 *
	 * @param string $live_table_prefix Table prefix for the Live Site.
	 *
	 * @return array Result from $wpdb->get_results.
	 */
	private function get_live_diff_content_ids( $live_table_prefix ) {
		global $wpdb;

		$live_posts_table = esc_sql( $live_table_prefix ) . 'posts';
		$posts_table = $wpdb->prefix . 'posts';
		$sql = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'post', 'page' )
			AND wp.ID IS NULL;";
		$results = $wpdb->get_results( $sql, ARRAY_A );
// TODO TEMP DEV -- bangor test DB:
// $results = [ 0 => [ 'ID' => '3013008', ], 1 => [ 'ID' => '3019843', ], 2 => [ 'ID' => '3122938', ], 3 => [ 'ID' => '3132278', ], 4 => [ 'ID' => '3132302', ], 5 => [ 'ID' => '3132653', ], 6 => [ 'ID' => '3134700', ], 7 => [ 'ID' => '3134704', ], 8 => [ 'ID' => '3134705', ], 9 => [ 'ID' => '3134710', ], 10 => [ 'ID' => '3134717', ], 11 => [ 'ID' => '3134722', ], 12 => [ 'ID' => '3134726', ], 13 => [ 'ID' => '3134728', ], 14 => [ 'ID' => '3134732', ], 15 => [ 'ID' => '3134733', ], 16 => [ 'ID' => '3134738', ], 17 => [ 'ID' => '3134740', ], 18 => [ 'ID' => '3134743', ], 19 => [ 'ID' => '3134744', ], 20 => [ 'ID' => '3134745', ], 21 => [ 'ID' => '3134748', ], 22 => [ 'ID' => '3134749', ], 23 => [ 'ID' => '3134750', ], 24 => [ 'ID' => '3134753', ], 25 => [ 'ID' => '3134758', ], 26 => [ 'ID' => '3134761', ], 27 => [ 'ID' => '3134763', ], 28 => [ 'ID' => '3134764', ], 29 => [ 'ID' => '3134767', ], 30 => [ 'ID' => '3134771', ], 31 => [ 'ID' => '3134775', ], 32 => [ 'ID' => '3134776', ], 33 => [ 'ID' => '3134779', ], 34 => [ 'ID' => '3134781', ], 35 => [ 'ID' => '3134782', ], 36 => [ 'ID' => '3134792', ], 37 => [ 'ID' => '3134793', ], 38 => [ 'ID' => '3134797', ], 39 => [ 'ID' => '3134799', ], 40 => [ 'ID' => '3134803', ], 41 => [ 'ID' => '3134804', ], 42 => [ 'ID' => '3134805', ], 43 => [ 'ID' => '3134806', ], 44 => [ 'ID' => '3134810', ], 45 => [ 'ID' => '3134813', ], 46 => [ 'ID' => '3134819', ], 47 => [ 'ID' => '3134826', ], 48 => [ 'ID' => '3134829', ], 49 => [ 'ID' => '3134833', ], 50 => [ 'ID' => '3134841', ], 51 => [ 'ID' => '3134843', ], 52 => [ 'ID' => '3134846', ], 53 => [ 'ID' => '3134848', ], 54 => [ 'ID' => '3134849', ], 55 => [ 'ID' => '3134851', ], 56 => [ 'ID' => '3134854', ], 57 => [ 'ID' => '3134860', ], 58 => [ 'ID' => '3134864', ], 59 => [ 'ID' => '3134868', ], 60 => [ 'ID' => '3134869', ], 61 => [ 'ID' => '3134876', ], 62 => [ 'ID' => '3134879', ], 63 => [ 'ID' => '3134880', ], 64 => [ 'ID' => '3134881', ], 65 => [ 'ID' => '3134893', ], 66 => [ 'ID' => '3134896', ], 67 => [ 'ID' => '3134900', ], 68 => [ 'ID' => '3134903', ], 69 => [ 'ID' => '3134907', ], 70 => [ 'ID' => '3134911', ], 71 => [ 'ID' => '3134915', ], 72 => [ 'ID' => '3134918', ], 73 => [ 'ID' => '3134920', ], 74 => [ 'ID' => '3134930', ], 75 => [ 'ID' => '3134953', ], 76 => [ 'ID' => '3134960', ], 77 => [ 'ID' => '3134968', ], 78 => [ 'ID' => '3134973', ], 79 => [ 'ID' => '3134977', ], 80 => [ 'ID' => '3134979', ], 81 => [ 'ID' => '3134980', ], 82 => [ 'ID' => '3134988', ], 83 => [ 'ID' => '3134992', ], 84 => [ 'ID' => '3134997', ], 85 => [ 'ID' => '3135006', ], 86 => [ 'ID' => '3135009', ], 87 => [ 'ID' => '3135013', ], 88 => [ 'ID' => '3135024', ], 89 => [ 'ID' => '3135029', ], 90 => [ 'ID' => '3135034', ], 91 => [ 'ID' => '3135036', ], 92 => [ 'ID' => '3135044', ], 93 => [ 'ID' => '3135053', ], 94 => [ 'ID' => '3135054', ], 95 => [ 'ID' => '3135064', ], 96 => [ 'ID' => '3135065', ], 97 => [ 'ID' => '3135074', ], 98 => [ 'ID' => '3135077', ], 99 => [ 'ID' => '3135088', ],];

		$ids = [];
		foreach ( $results as $result ) {
			$ids[] = $result[ 'ID' ];
		}

		return $ids;
	}

	/**
	 * Fetches a Post and all core WP relational objects belonging to the post. Can fetch from a custom table prefix.
	 *
	 * @param int    $post_id           Post ID.
	 * @param string $live_table_prefix Table prefix to fetch from.
	 *
	 * @return array $args {
	 *     Post and all core WP Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` rows.
	 *     @type array self::DATAKEY_POSTMETA          `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             `terms` rows.
	 * }
	 */
	private function get_data( $post_id, $table_prefix ) {
		$data = [
			self::DATAKEY_POST,
			// If self::DATAKEY_POST have parent, the array elements also get keys self::DATAKEY_POST_PARENT and self::DATAKEY_POST_PARENTMETA.
			self::DATAKEY_POSTMETA,
			self::DATAKEY_COMMENTS,
			self::DATAKEY_COMMENTMETA,
			self::DATAKEY_USERS ,
			self::DATAKEY_USERMETA,
			self::DATAKEY_TERMRELATIONSHIPS,
			self::DATAKEY_TERMTAXONOMY,
			self::DATAKEY_TERMS,
		];

		// Get Post.
		$post_row = $this->select( $table_prefix . 'posts', [ 'ID' => $post_id ], $select_just_one_row = true );
		if ( empty( $post_row ) ) {
			// TODO empty
		}
		$data[ self::DATAKEY_POST ] = $post_row;

		// Get Post Metas.
		$data[ self::DATAKEY_POSTMETA ] = $this->select( $table_prefix . 'postmeta', [ 'post_id' => $post_id ] );

		// Get Post Author User.
		$user = $this->select( $table_prefix . 'users', [ 'ID' => $data[ self::DATAKEY_POST ][ 'post_author' ] ], $select_just_one_row = true );
		$data[ self::DATAKEY_USERS ][] = $user;

		// Get Post Author User Metas.
		$data[ self::DATAKEY_USERMETA ] = array_merge(
			$data[ self::DATAKEY_USERMETA ],
			$this->select( $table_prefix . 'usermeta', [ 'user_id' => $user[ 'ID' ] ] )
		);

		// Get Comments.
		if ( $post_row[ 'comment_count' ] > 0 ) {
			$comments = $this->select( $table_prefix . 'comments', [ 'comment_post_ID' => $post_id ] );
			$data[ self::DATAKEY_COMMENTS ] = array_merge(
				$data[ self::DATAKEY_COMMENTS ],
				$comments
			);

			// Get Comment Metas.
			foreach ( $comments as $key_comment => $comment ) {
				$data[ self::DATAKEY_COMMENTMETA ] = array_merge(
					$data[ self::DATAKEY_COMMENTMETA ],
					$this->select( $table_prefix . 'commentmeta', [ 'comment_id' => $comment[ 'ID' ] ] )
				);

				// Get Comment User if not already fetched.
				if ( $comment[ 'user_id' ] > 0 && ! $this->filter_subarray( $data[ self::DATAKEY_USERS ], 'ID', $comment[ 'user_id' ] ) ) {
					$comment_user = $this->select( $table_prefix . 'users', [ 'ID' => $comment[ 'user_id' ] ], $select_just_one_row = true );
					$data[ self::DATAKEY_USERS ][] = $comment_user;

					// Get Get Comment User Metas.
					$data[ self::DATAKEY_USERMETA ] = array_merge(
						$data[ self::DATAKEY_USERMETA ],
						$this->select( $table_prefix . 'usermeta', [ 'user_id' => $comment_user[ 'ID' ] ] )
					);
				}
			}
		}

		// Get Term Relationships.
		$term_relationships = $this->select( $table_prefix . 'term_relationships', [ 'object_id' => $post_id ] );
		$data[ self::DATAKEY_TERMRELATIONSHIPS ] = array_merge(
			$data[ self::DATAKEY_TERMRELATIONSHIPS ],
			$term_relationships
		);

		// Get Term Taxonomies.
		foreach ( $term_relationships as $term_relationship ) {
			$term_taxonomy_id = $term_relationship[ 'term_taxonomy_id' ];
			$term_taxonomy = $this->select( $table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $term_taxonomy_id ], $select_just_one_row = true );
			$data[ self::DATAKEY_TERMTAXONOMY ][] = $term_taxonomy;

			// Get Terms and Term Metas.
			$term_id = $term_taxonomy[ 'term_id' ];
			$data[ self::DATAKEY_TERMS ] = $this->select( $table_prefix . 'terms', [ 'term_id' => $term_id ], $select_just_one_row = true );
			$data[ self::DATAKEY_TERMMETA ] = $this->select( $table_prefix . 'termmeta', [ 'term_id' => $term_id ] );
		}

		return $data;
	}

	/**
	 * Checks if a Term Taxonomy exists.
	 *
	 * @param string $term_name Term name.
	 * @param string $term_slug Term slug.
	 * @param string $taxonomy  Taxonomy
	 *
	 * @return string|null term_taxonomy_id or null.
	 */
	private function get_existing_term_taxonomy( $term_name, $term_slug, $taxonomy ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT tt.term_taxonomy_id
			FROM {$wpdb->term_taxonomy} tt
			JOIN {$wpdb->terms} t
		        ON tt.term_id = t.term_id
			WHERE t.name = %s
			AND t.slug = %s
		    AND tt.taxonomy = %s;",
			$term_name,
			$term_slug,
			$taxonomy
		) );
	}

	/**
	 * Simple select query with custom `where` conditions.
	 *
	 * @param string $table_name          Table name to select from.
	 * @param array  $where_conditions    Keys are columns, values are their values.
	 * @param bool   $select_just_one_row Select just one row. Default is false.
	 *
	 * @return array|void|null Result from $wpdb->get_results, or from $wpdb->get_row if $select_just_one_row is set to true.
	 */
	private function select( $table_name, $where_conditions, $select_just_one_row = false ) {
		global $wpdb;

		$sql = 'SELECT * FROM ' . esc_sql( $table_name );

		if ( ! empty( $where_conditions ) ) {
			$where_sprintf = '';
			foreach ( $where_conditions as $column => $value ) {
				$where_sprintf .= ( ! empty( $where_sprintf ) ? ' AND' : '' )
				                  . ' ' . esc_sql( $column ) . ' = %s';
			}
			$where_sprintf = ' WHERE ' . $where_sprintf;

			$sql = $sql . $wpdb->prepare( $where_sprintf, array_values( $where_conditions ) );
		}

		if ( true === $select_just_one_row ) {
			return $wpdb->get_row( $sql, ARRAY_A );
		} else {
			return $wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Imports all the Post data.
	 *
	 * @param array $data {
	 *     Post and all core WP Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` rows.
	 *     @type array self::DATAKEY_POSTMETA          `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             `terms` rows.
	 * }
	 *
	 * @return int Imported Post ID.
	 */
	private function import_data( $data ) {
		global $wpdb;

		// Insert Post and Post Metas.
		$post_id = $this->insert_post( $data[ self::DATAKEY_POST ], $data[ self::DATAKEY_POSTMETA ] );

		// Insert Author User.
		$author_row_id = $data[ self::DATAKEY_POST ][ 'post_author' ];
		$author_row = $this->filter_subarray( $data[ self::DATAKEY_USERS ], 'ID', $author_row_id );
		$author_existing = get_user_by( 'user_login', $author_row[ 'user_login' ] );
		if ( $author_existing instanceof WP_User ) {
			$author_id = $author_existing->ID;
		} else {
			$author_metas_rows = $this->filter_subarray( $data[ self::DATAKEY_USERMETA ], 'user_id', $author_row_id );
			$author_id = $this->insert_user( $author_row, $author_metas_rows );
		}

		// Update inserted Post's Author.
		$wpdb->update( $wpdb->posts, [ 'post_author' => $author_id ], [ 'ID' => $post_id ] );

		// Insert Comments.
		foreach ( $data[ self::DATAKEY_COMMENTS ] as $comment_row ) {

			// Insert the Comment User.
			$comment_user_row_id = $comment_row[ 'user_id' ];
			if ( 0 == $comment_user_row_id ) {
				$comment_user_id = 0;
			} else {
				$comment_user_row = $this->filter_subarray( $data[ self::DATAKEY_USERS ], 'ID', $comment_user_row_id );
				$comment_user_existing = get_user_by( 'user_login', $comment_user_row[ 'user_login' ] );

				if ( $comment_user_existing instanceof WP_User ) {
					$comment_user_id = $comment_user_existing->ID;
				} else {
					$comment_user_meta_rows = $this->filter_subarray( $data[ self::DATAKEY_USERMETA ], 'user_id', $comment_user_row_id );
					$comment_user_id = $this->insert_user( $comment_user_row, $comment_user_meta_rows );
				}
			}

			// Insert Comment and Comment Metas.
			$commentmeta_rows = $this->filter_subarray( $data[ self::DATAKEY_COMMENTMETA ], 'comment_id' , $comment_row[ 'comment_ID' ] );
			$this->insert_comment( $post_id, $comment_user_id, $comment_row, $commentmeta_rows );
		}

		// Insert Terms.
		$terms_ids_updates = [];
		$term_taxonomy_ids_updates = [];
		foreach ( $data[ self::DATAKEY_TERMS ] as $term_row ) {

			$term_existing = term_exists( $term_row[ 'name' ] );
			if ( null == $term_existing ) {
				$term_id = $this->insert_term( $term_row );
			} else {
				$term_id = $term_existing;
			}
			$terms_ids_updates[ $term_row[ 'term_id' ] ] = $term_id;

			// Insert Term Taxonomy records.
			/*
			 * A Term can be shared by multiple Taxonomies in WP (e.g. Term "blue" by a Taxonomies "category" and "color").
			 * That's why instead of simply looping through all Term Taxonomies and inserting them, we're inserting each Term's
			 * Term Taxonomies at this point.
			 */
			$term_taxonomy_rows = $this->filter_subarray( $data[ self::DATAKEY_TERMTAXONOMY ], 'term_id', $term_row[ 'term_id' ] );
			foreach ( $term_taxonomy_rows as $term_taxonomy_row ) {
				$term_taxonomy_id_existing = $this->get_existing_term_taxonomy( $term_row[ 'name' ], $term_row[ 'slug' ], $term_taxonomy_row[ 'taxonomy' ] );
				if ( $term_taxonomy_id_existing ) {
					$term_taxonomy_id = $term_taxonomy_id_existing;
				} else {
					$term_taxonomy_id = $this->insert_term_taxonomy( $term_id, $term_taxonomy_row );
				}
				$term_taxonomy_ids_updates[ $term_taxonomy_row[ 'term_taxonomy_id' ] ] = $term_taxonomy_id;
			}
		}

		// Insert Term Relationships.
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $term_relationship ) {
			$term_relationship[ 'object_id' ] = $post_id;
			$term_relationship[ 'term_taxonomy_id' ] = $term_taxonomy_ids_updates[ $term_relationship[ 'term_taxonomy_id' ] ] ?? $term_relationship[ 'term_taxonomy_id' ];
			$this->insert_term_relationship( $term_taxonomy_id );
		}

		return $post_id;
	}

	/**
	 * Loops through imported posts and updates their parents IDs.
	 *
	 * @param array $imported_post_ids Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 */
	private function update_post_parent( $post_id, $imported_post_ids ) {
		global $wpdb;

		$post = get_post( $post_id );
		$new_parent_id = $imported_post_ids[ $post->post_parent ] ?? null;
		if ( $post->post_parent > 0 && $new_parent_id ) {
			$wpdb->update( $wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post->ID ] );
		}
	}

	/**
	 * Inserts Post and its Meta.
	 *
	 * @param array $post_row      `post` row.
	 * @param array $postmeta_rows `postmeta` rows.
	 *
	 * @return int Inserted Post ID.
	 */
	private function insert_post( $post_row, $postmeta_rows ) {
		global $wpdb;

		unset( $post_row[ 'ID' ] );

		$inserted = $wpdb->insert( $wpdb->posts, $post_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$post_id = $wpdb->insert_id;

		// Insert Post Metas.
		foreach ( $postmeta_rows as $postmeta_row ) {

			unset( $postmeta_row[ 'meta_id' ] );
			$postmeta_row[ 'post_id' ] = $post_id;

			$inserted = $this->insert( $wpdb->postmeta, $postmeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}
		}

		return $post_id;
	}

	/**
	 * Inserts a User and its Meta.
	 *
	 * @param array $user_row      `user` row.
	 * @param array $usermeta_rows `usermeta` rows.
	 *
	 * @return int Inserted User ID.
	 */
	private function insert_user( $user_row, $usermeta_rows ) {
		global $wpdb;

		unset( $user_row[ 'ID' ] );

		$inserted = $wpdb->insert( $wpdb->users, $user_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$user_id = $wpdb->insert_id;

		// Insert User Metas.
		foreach ( $usermeta_rows as $usermeta_row ) {

			unset( $usermeta_row[ 'umeta_id' ] );
			$usermeta_row[ 'user_id' ] = $user_id;

			$inserted = $wpdb->insert( $wpdb->usermeta, $usermeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}
		}

		return $user_id;
	}

	/**
	 * Inserts a Comment and its Meta.
	 *
	 * @param int   $post_id          Post ID.
	 * @param int   $user_id          User ID.
	 * @param array $comment_row      `comment` row.
	 * @param array $commentmeta_rows `commentmeta` rows.
	 *
	 * @return int Inserted comment_id.
	 */
	private function insert_comment( $post_id, $user_id, $comment_row, $commentmeta_rows ) {
		global $wpdb;

		unset( $comment_row[ 'comment_ID' ] );
		$comment_row[ 'comment_post_ID' ] = $post_id;
		$comment_row[ 'user_id' ] = $user_id;

		$inserted = $wpdb->insert( $wpdb->comments, $comment_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$comment_id = $wpdb->insert_id;

		// Insert Comment Metas.
		foreach ( $commentmeta_rows as $usermeta_row ) {

			unset( $usermeta_row[ 'meta_id' ] );
			$usermeta_row[ 'comment_id' ] = $comment_id;

			$inserted = $wpdb->insert( $wpdb->commentmeta, $usermeta_row );
			if ( 1 != $inserted ) {
				// TODO error
			}
		}

		return $comment_id;
	}

	/**
	 * Inserts into `terms` table.
	 *
	 * @param array $term_row `term` row.
	 *
	 * @return int Inserted term_id.
	 */
	private function insert_term( $term_row ) {
		global $wpdb;

		unset( $term_row[ 'term_id' ] );

		$inserted = $wpdb->insert( $wpdb->terms, $term_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$term_id = $wpdb->insert_id;

		return $term_id;
	}

	/**
	 * Inserts into `term_taxonomy` table.
	 *
	 * @param int   $term_id           `term_id` column.
	 * @param array $term_taxonomy_row `term_taxonomy` row.
	 *
	 * @return int Inserted term_taxonomy_id.
	 */
	private function insert_term_taxonomy( $term_id, $term_taxonomy_row ) {
		global $wpdb;

		unset( $term_taxonomy_row[ 'term_taxonomy_id' ] );
		$term_taxonomy_row[ 'term_id' ] = $term_id;

		$inserted = $wpdb->insert( $wpdb->term_taxonomy, $term_taxonomy_row );
		if ( 1 != $inserted ) {
			// TODO error
		}
		$term_taxonomy_id = $wpdb->insert_id;

		return $term_taxonomy_id;
	}

	/**
	 * Inserts into `term_relationships` table.
	 *
	 * @param int $object_id        `object_id` column.
	 * @param int $term_taxonomy_id `term_taxonomy_id` column.
	 */
	private function insert_term_relationship( $object_id, $term_taxonomy_id ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->term_relationships,
			[
				'object_id' => $object_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);
		if ( 1 != $inserted ) {
			// TODO error
		}
	}

	/**
	 * Filters a multidimensional array and searches for a subarray with a key and value.
	 *
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for
	 *
	 * @return null
	 */
	private function filter_subarray( $array, $key, $value ) {
		foreach ( $array as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				return $subarray;
			}
		}

		return null;
	}
}
