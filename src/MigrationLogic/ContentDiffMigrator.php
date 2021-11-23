<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \WP_CLI;
use \WP_User;

class ContentDiffMigrator {

	// Data array keys.
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

	const CORE_WP_TABLES = [
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'terms',
		'termmeta',
		'term_relationships',
		'term_taxonomy',
		'usermeta',
		'users',
	];

	/**
	 * @var object Global $wpdb.
	 */
	private $wpdb;

	/**
	 * ContentDiffMigrator constructor.
	 *
	 * @param object $wpdb Global $wpdb.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Gets a diff of new Posts and Pages from the Live Site.
	 *
	 * @param string $live_table_prefix Table prefix for the Live Site.
	 *
	 * @return array Result from $wpdb->get_results.
	 */
	public function get_live_diff_content_ids( $live_table_prefix ) {
		$live_posts_table = esc_sql( $live_table_prefix ) . 'posts';
		$posts_table = $this->wpdb->prefix . 'posts';
		$sql = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'post', 'page' )
			AND wp.ID IS NULL;";
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

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
	public function get_data( $post_id, $table_prefix ) {

		$data = $this->get_empty_data_array();

		// Get Post.
		$post_row = $this->select_post_row( $table_prefix, $post_id );
		$data[ self::DATAKEY_POST ] = $post_row;

		// Get Post Metas.
		$data[ self::DATAKEY_POSTMETA ] = $this->select_postmeta_rows( $table_prefix, $post_id );

		// Get Post Author User.
		$author_row = $this->select_user_row( $table_prefix, $data[ self::DATAKEY_POST ][ 'post_author' ] );
		$data[ self::DATAKEY_USERS ][] = $author_row;

		// Get Post Author User Metas.
		$data[ self::DATAKEY_USERMETA ] = array_merge(
			$data[ self::DATAKEY_USERMETA ],
			$this->select_usermeta_rows( $table_prefix, $author_row[ 'ID' ] )
		);

		// Get Comments.
		if ( $post_row[ 'comment_count' ] > 0 ) {
			$comment_rows = $this->select_comment_rows( $table_prefix, $post_id );
			$data[ self::DATAKEY_COMMENTS ] = array_merge(
				$data[ self::DATAKEY_COMMENTS ],
				$comment_rows
			);

			// Get Comment Metas.
			foreach ( $comment_rows as $key_comment => $comment ) {
				$data[ self::DATAKEY_COMMENTMETA ] = array_merge(
					$data[ self::DATAKEY_COMMENTMETA ],
					$this->select_commentmeta_rows( $table_prefix, $comment[ 'comment_ID' ] )
				);

				// Get Comment User if not already fetched.
				if ( $comment[ 'user_id' ] > 0 && empty( $this->filter_array_elements( $data[ self::DATAKEY_USERS ], 'ID', $comment[ 'user_id' ] ) ) ) {
					$comment_user_row = $this->select_user_row( $table_prefix, $comment[ 'user_id' ] );
					$data[ self::DATAKEY_USERS ][] = $comment_user_row;

					// Get Get Comment User Metas.
					$data[ self::DATAKEY_USERMETA ] = array_merge(
						$data[ self::DATAKEY_USERMETA ],
						$this->select_usermeta_rows( $table_prefix, $comment_user_row[ 'ID' ] )
					);
				}
			}
		}

		// Get Term Relationships.
		$term_relationships_rows = $this->select_term_relationships_rows( $table_prefix, $post_id );
		$data[ self::DATAKEY_TERMRELATIONSHIPS ] = array_merge(
			$data[ self::DATAKEY_TERMRELATIONSHIPS ],
			$term_relationships_rows
		);

		// Get Term Taxonomies.
		foreach ( $term_relationships_rows as $term_relationship_row ) {
			$term_taxonomy_id = $term_relationship_row[ 'term_taxonomy_id' ];
			$term_taxonomy = $this->select_term_taxonomy_row( $table_prefix, $term_taxonomy_id );
			$data[ self::DATAKEY_TERMTAXONOMY ][] = $term_taxonomy;

			// Get Terms.
			$term_id = $term_taxonomy[ 'term_id' ];
			$data[ self::DATAKEY_TERMS ][] = $this->select_terms_row( $table_prefix, $term_id );
		}

		// Get Term Metas.
		foreach ( $data[ self::DATAKEY_TERMS ] as $term_row ) {
			$data[ self::DATAKEY_TERMMETA ] = array_merge(
				$this->select_termmeta_rows( $table_prefix, $term_row[ 'term_id' ] ),
				$data[ self::DATAKEY_TERMMETA ]
			);
		}

		return $data;
	}

	/**
	 * Imports all the Post related data.
	 *
	 * @param array $data Array containing all the data, @see ContentDiffMigrator::get_data for structure.
	 *
	 * @return array List of errors which occurred.
	 */
	public function import_post_data( $post_id, $data ) {
		$error_messages = [];

		// Insert Post Metas.
		foreach ( $data[ self::DATAKEY_POSTMETA ] as $postmeta_row ) {
			try {
				$this->insert_postmeta_row( $postmeta_row, $post_id );
			} catch ( \Exception $e) {
				$error_messages[] = $e->getMessage();
			}
		}

		// Get existing Author User or insert a new one.
		$author_row_id = $data[ self::DATAKEY_POST ][ 'post_author' ];
		$author_row = $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $author_row_id );
		$user_existing = $this->get_user_by( 'user_login', $author_row[ 'user_login' ] );
		if ( $user_existing instanceof WP_User ) {
			$author_id = $user_existing->ID;
		} else {
			try {
				$usermeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_USERMETA ], 'user_id', $author_row[ 'ID' ] );
				$author_id = $this->insert_user( $author_row );
			} catch ( \Exception $e) {
				$error_messages[] = $e->getMessage();
			}
			foreach ( $usermeta_rows as $usermeta_row ) {
				try {
					$this->insert_usermeta_row( $usermeta_row, $author_id );
				} catch ( \Exception $e) {
					$error_messages[] = $e->getMessage();
				}
			}
		}

		// Update inserted Post's Author.
		try {
			$this->update_post_author( $post_id, $author_id );
		} catch ( \Exception $e) {
			$error_messages[] = $e->getMessage();
		}

		// Insert Comments.
		$comment_ids_updates = [];
		foreach ( $data[ self::DATAKEY_COMMENTS ] as $comment_row ) {
			$comment_id_old = $comment_row[ 'comment_ID' ];

			// Insert the Comment User.
			$comment_user_row_id = $comment_row[ 'user_id' ];
			if ( 0 == $comment_user_row_id ) {
				$comment_user_id = 0;
			} else {
				// Get existing Comment User or insert a new one.
				$comment_user_row = $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $comment_user_row_id );
				$comment_user_existing = $this->get_user_by( 'user_login', $comment_user_row[ 'user_login' ] );
				if ( $comment_user_existing instanceof WP_User ) {
					$comment_user_id = $comment_user_existing->ID;
				} else {
					try {
						$usermeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_USERMETA ], 'user_id', $comment_user_row[ 'ID' ] );
						$comment_user_id = $this->insert_user( $comment_user_row );
					} catch ( \Exception $e) {
						$error_messages[] = $e->getMessage();
					}
					foreach ( $usermeta_rows as $usermeta_row ) {
						try {
							$this->insert_usermeta_row( $usermeta_row, $comment_user_id );
						} catch ( \Exception $e) {
							$error_messages[] = $e->getMessage();
						}
					}
				}
			}

			// Insert Comment and Comment Metas.
			try {
				$comment_id = $this->insert_comment( $comment_row, $post_id, $comment_user_id );
			} catch ( \Exception $e) {
				$error_messages[] = $e->getMessage();
			}
			$comment_ids_updates[ $comment_id_old ] = $comment_id;
			$commentmeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_COMMENTMETA ], 'comment_id' , $comment_row[ 'comment_ID' ] );
			foreach ( $commentmeta_rows as $commentmeta_row ) {
				try {
					$this->insert_commentmeta_row( $commentmeta_row, $comment_id );
				} catch ( \Exception $e) {
					$error_messages[] = $e->getMessage();
				}
			}
		}

		// Loop through all comments, and update their Parent IDs.
		foreach ( $comment_ids_updates as $comment_id_old => $comment_id_new ) {
			$comment_row = $this->filter_array_element( $data[ self::DATAKEY_COMMENTS ], 'comment_ID', $comment_id_old );
			$comment_parent_old = $comment_row[ 'comment_parent' ];
			$comment_parent_new = $comment_ids_updates[ $comment_parent_old ] ?? null;
			if ( $comment_parent_old > 0 && $comment_parent_new && ( $comment_parent_old != $comment_parent_new ) ) {
				try {
					$this->update_comment_parent( $comment_id_new, $comment_parent_new );
				} catch ( \Exception $e) {
					$error_messages[] = $e->getMessage();
				}
			}
		}

		// Insert Terms.
		$terms_ids_updates = [];
		$term_taxonomy_ids_updates = [];
		foreach ( $data[ self::DATAKEY_TERMS ] as $term_row ) {

			$term_id_existing = $this->term_exists( $term_row[ 'name' ], '', null );
			if ( null == $term_id_existing ) {
				try {
					$term_id = $this->insert_term( $term_row );
				} catch ( \Exception $e) {
					$error_messages[] = $e->getMessage();
				}
			} else {
				$term_id = $term_id_existing;
			}
			$terms_ids_updates[ $term_row[ 'term_id' ] ] = $term_id;

			// Insert Term Taxonomy records.
			/*
			 * A Term can be shared by multiple Taxonomies in WP (e.g. Term "blue" by Taxonomies "category" and "color").
			 * That's why instead of simply looping through all Term Taxonomies and inserting them, we're inserting each Term's
			 * Term Taxonomies at this point.
			 */
			$term_taxonomy_rows = $this->filter_array_elements( $data[ self::DATAKEY_TERMTAXONOMY ], 'term_id', $term_row[ 'term_id' ] );
			foreach ( $term_taxonomy_rows as $term_taxonomy_row ) {
				// Get term_taxonomy or insert new.
				$term_taxonomy_id_existing = $this->get_existing_term_taxonomy( $term_row[ 'name' ], $term_row[ 'slug' ], $term_taxonomy_row[ 'taxonomy' ] );
				if ( $term_taxonomy_id_existing ) {
					$term_taxonomy_id = $term_taxonomy_id_existing;
				} else {
					try {
						$term_taxonomy_id = $this->insert_term_taxonomy( $term_taxonomy_row, $term_id );
					} catch ( \Exception $e) {
						$error_messages[] = $e->getMessage();
					}
				}

				$term_taxonomy_ids_updates[ $term_taxonomy_row[ 'term_taxonomy_id' ] ] = $term_taxonomy_id;
			}
		}

		// Insert Term Relationships.
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $term_relationship_row ) {
			$term_taxonomy_id_old = $term_relationship_row[ 'term_taxonomy_id' ];
			$term_taxonomy_id_new = $term_taxonomy_ids_updates[ $term_taxonomy_id_old ] ?? null;
			if ( is_null( $term_taxonomy_id_new ) ) {
				$this->log_insert_error( sprintf( "Error could not insert term_relationship because updated term_taxonomy_id not found, term_taxonomy_id_old='%s'", $term_taxonomy_id_old ) );
			} else {
				try {
					$this->insert_term_relationship( $post_id, $term_taxonomy_id_new );
				} catch ( \Exception $e) {
					$error_messages[] = $e->getMessage();
				}
			}
		}

		return $error_messages;
	}

	/**
	 * Loops through imported posts and updates their parents IDs.
	 *
	 * @param array $imported_post_ids Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 */
	public function update_post_parent( $post_id, $imported_post_ids ) {
		$post = $this->get_post( $post_id );
		$new_parent_id = $imported_post_ids[ $post->post_parent ] ?? null;
		if ( $post->post_parent > 0 && $new_parent_id ) {
			$this->wpdb->update( $this->wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post->ID ] );
		}
	}

	/**
	 * Returns an empty data array.
	 *
	 * @return array $args Empty data array for ContentDiffMigrator::get_data. @see ContentDiffMigrator::get_data for structure.
	 */
	private function get_empty_data_array() {
		return [
			self::DATAKEY_POST => [],
			self::DATAKEY_POSTMETA => [],
			self::DATAKEY_COMMENTS => [],
			self::DATAKEY_COMMENTMETA => [],
			self::DATAKEY_USERS => [],
			self::DATAKEY_USERMETA => [],
			self::DATAKEY_TERMRELATIONSHIPS => [],
			self::DATAKEY_TERMTAXONOMY => [],
			self::DATAKEY_TERMS => [],
			self::DATAKEY_TERMMETA => [],
		];
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
	public function get_existing_term_taxonomy( $term_name, $term_slug, $taxonomy ) {
		return $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT tt.term_taxonomy_id
			FROM {$this->wpdb->term_taxonomy} tt
			JOIN {$this->wpdb->terms} t
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
	 * Selects a row from the posts table.
	 *
	 * @param string $table_prefix
	 * @param int $post_id
	 *
	 * @return array|object|null|void Return from $wpdb::get_row.
	 */
	public function select_post_row( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'posts', [ 'ID' => $post_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the postmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $post_id Post ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_postmeta_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'postmeta', [ 'post_id' => $post_id ] );
	}

	/**
	 * Selects a row from the users table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $user_id      User ID.
	 *
	 * @return array|object|null|void Return from $wpdb::get_row.
	 */
	public function select_user_row( $table_prefix, $user_id ) {
		return $this->select( $table_prefix . 'users', [ 'ID' => $user_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the usermeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $user_id      User ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_usermeta_rows( $table_prefix, $user_id ) {
		return $this->select( $table_prefix . 'usermeta', [ 'user_id' => $user_id ] );
	}

	/**
	 * Selects rows from the comments table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $post_id Post ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_comment_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'comments', [ 'comment_post_ID' => $post_id ] );
	}

	/**
	 * Selects rows from the commentmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $comment_id Comment ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_commentmeta_rows( $table_prefix, $comment_id ) {
		return $this->select( $table_prefix . 'commentmeta', [ 'comment_id' => $comment_id ] );
	}

	/**
	 * Selects rows from the term_relationships table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $post_id Post ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_term_relationships_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'term_relationships', [ 'object_id' => $post_id ] );
	}

	/**
	 * Selects a row from the term_taxonomy table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $term_taxonomy_id term_taxonomy_id.
	 *
	 * @return array|object|null|void Return from $wpdb::get_row.
	 */
	public function select_term_taxonomy_row( $table_prefix, $term_taxonomy_id ) {
		return $this->select( $table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $term_taxonomy_id ], $select_just_one_row = true );
	}

	/**
	 * Selects a row from the terms table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $term_id Term ID.
	 *
	 * @return array|object|null|void Return from $wpdb::get_row.
	 */
	public function select_terms_row( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'terms', [ 'term_id' => $term_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the termmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int $term_id Term ID.
	 *
	 * @return array|object|null Return from $wpdb::get_results.
	 */
	public function select_termmeta_rows( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'termmeta', [ 'term_id' => $term_id ] );
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
		$sql = 'SELECT * FROM ' . esc_sql( $table_name );

		if ( ! empty( $where_conditions ) ) {
			$where_sprintf = '';
			foreach ( $where_conditions as $column => $value ) {
				$where_sprintf .= ( ! empty( $where_sprintf ) ? ' AND' : '' )
				                  . ' ' . esc_sql( $column ) . ' = %s';
			}
			$where_sprintf = ' WHERE' . $where_sprintf;
			$sql_sprintf = $sql . $where_sprintf;

			$sql = $this->wpdb->prepare( $sql_sprintf, array_values( $where_conditions ) );
		}

		if ( true === $select_just_one_row ) {
			return $this->wpdb->get_row( $sql, ARRAY_A );
		} else {
			return $this->wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Inserts Post.
	 *
	 * @param array $post_row `post` row.
	 *
	 * @return int Inserted Post ID.
	 */
	public function insert_post( $post_row ) {
		$orig_id = $post_row['ID'];
		unset( $post_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->posts, $post_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting post, ID %d', $orig_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * @param array $postmeta_rows
	 * @param int $post_id
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_postmeta_row( $postmeta_row, $post_id ) {
		$orig_meta_id = $postmeta_row[ 'meta_id' ];
		unset( $postmeta_row[ 'meta_id' ] );
		$postmeta_row[ 'post_id' ] = $post_id;

		$inserted = $this->wpdb->insert( $this->wpdb->postmeta, $postmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting post meta, meta_id %d', $orig_meta_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a User.
	 *
	 * @param array $user_row `user` row.
	 *
	 * @return int Inserted User ID.
	 */
	public function insert_user( $user_row ) {
		$orig_id = $user_row[ 'ID' ] ;
		unset( $user_row[ 'ID' ] );

		$inserted = $this->wpdb->insert( $this->wpdb->users, $user_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user, ID %d', $orig_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts User Meta.
	 *
	 * @param array $usermeta_row `usermeta` row.
	 * @param int   $user_id       User ID.
	 *
	 * @return int Inserted umeta_id.
	 */
	public function insert_usermeta_row( $usermeta_row, $user_id ) {
		$orig_umeta_id = $usermeta_row[ 'umeta_id' ];
		unset( $usermeta_row[ 'umeta_id' ] );
		$usermeta_row[ 'user_id' ] = $user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->usermeta, $usermeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user meta, umeta_id %d', $orig_umeta_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a Comment with an updated post_id and user_id.
	 *
	 * @param array $comment_row      `comment` row.
	 * @param int   $new_post_id      Post ID.
	 * @param int   $new_user_id      User ID.
	 *
	 * @return int Inserted comment_id.
	 */
	public function insert_comment( $comment_row, $new_post_id, $new_user_id ) {
		$orig_comment_id = $comment_row[ 'comment_ID' ];
		unset( $comment_row[ 'comment_ID' ] );
		$comment_row[ 'comment_post_ID' ] = $new_post_id;
		$comment_row[ 'user_id' ] = $new_user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->comments, $comment_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment, comment_ID %d', $orig_comment_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts Comment Metas with an updated comment_id.
	 *
	 * @param array $commentmeta_row Comment Meta rows.
	 * @param int   $new_comment_id  New Comment ID.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_commentmeta_row( $commentmeta_row, $new_comment_id ) {
		$orig_meta_id = $commentmeta_row[ 'meta_id' ];
		unset( $commentmeta_row[ 'meta_id' ] );
		$commentmeta_row[ 'comment_id' ] = $new_comment_id;

		$inserted = $this->wpdb->insert( $this->wpdb->commentmeta, $commentmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment meta, meta_id %d', $orig_meta_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Comment's parent ID.
	 *
	 * @param int $comment_id         Comment ID.
	 * @param int $comment_parent_new new Comment Parent ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_comment_parent( $comment_id, $comment_parent_new ) {
		$updated = $this->wpdb->update( $this->wpdb->comments, [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating comment parent, comment ID %d, comment_parent new %d', $comment_id, $comment_parent_new ) );
		}

		return $updated;
	}

	/**
	 * Inserts into `terms` table.
	 *
	 * @param array $term_row `term` row.
	 *
	 * @return int Inserted term_id.
	 */
	public function insert_term( $term_row ) {
		$orig_term_id = $term_row[ 'term_id' ];
		unset( $term_row[ 'term_id' ] );

		$inserted = $this->wpdb->insert( $this->wpdb->terms, $term_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term, term_id %d', $orig_term_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_taxonomy` table.
	 *
	 * @param array $term_taxonomy_row `term_taxonomy` row.
	 * @param int   $new_term_id       New `term_id` value to be set.
	 *
	 * @return int Inserted term_taxonomy_id.
	 */
	public function insert_term_taxonomy( $term_taxonomy_row, $new_term_id ) {
		$original_term_taxonomy_id = $term_taxonomy_row[ 'term_taxonomy_id' ];
		unset( $term_taxonomy_row[ 'term_taxonomy_id' ] );
		$term_taxonomy_row[ 'term_id' ] = $new_term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->term_taxonomy, $term_taxonomy_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term_taxonomy, term_taxonomy_id %s', $original_term_taxonomy_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_relationships` table.
	 *
	 * @param int $object_id        `object_id` column.
	 * @param int $term_taxonomy_id `term_taxonomy_id` column.
	 *
	 * @return int Inserted object_id.
	 */
	public function insert_term_relationship( $object_id, $term_taxonomy_id ) {
		if ( ! $object_id || ! $term_taxonomy_id ) {
			throw new \RuntimeException( sprintf( "insert_term_relationship parameters error, object_id='%s', term_taxonomy_id='%s'", $object_id, $term_taxonomy_id ) );
		}

		$inserted = $this->wpdb->insert(
			$this->wpdb->term_relationships,
			[
				'object_id' => $object_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term relationship, object_id %s, term_taxonomy_id %s', $object_id, $term_taxonomy_id ) );
		}

		return $this->wpdb->insert_id;;
	}

	/**
	 * Updates a Post's Author.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_author_id New Author ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_post_author( $post_id, $new_author_id ) {
		$updated = $this->wpdb->update( $this->wpdb->posts, [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating post author, post_id %s, post_author %s', $post_id, $new_author_id ) );
		}

		return $updated;
	}

	/**
	 * Wrapper for WP's native \get_user_by().
	 *
	 * @param string     $field The field to retrieve the user with. id | ID | slug | email | login.
	 * @param int|string $value A value for $field. A user ID, slug, email address, or login name.
	 *
	 * @return WP_User|false WP_User object on success, false on failure.
	 */
	public function get_user_by( $field, $value ) {
		return get_user_by( $field, $value );
	}

	/**
	 * Gets all the tables in the active DB.
	 *
	 * @return array List of all tables in DB.
	 */
	public function get_all_db_tables() {
		$all_tables = [];
		$all_tables_result = $this->wpdb->get_results( 'SHOW TABLES;', ARRAY_N );
		foreach ( $all_tables_result as $table ) {
			$all_tables[] = $table[0];
		}

		return $all_tables;
	}

	/**
	 * Checks whether all core WP DB tables are present in used DB.
	 *
	 * @param string $table_prefix Table prefix.
	 *
	 * @throws \RuntimeException In case not all live DB core WP tables are found.
	 */
	public function validate_core_wp_db_tables( $table_prefix ) {
		$all_tables = $this->get_all_db_tables();
		foreach ( self::CORE_WP_TABLES as $table ) {
			$tablename = $table_prefix . $table;
			if ( ! in_array( $tablename, $all_tables ) ) {
				throw new \RuntimeException( sprintf( 'Core WP DB table %s not found.', $tablename ) );
			}
		}
	}

	/**
	 * Wrapper for WP's native \term_exists().
	 *
	 * @param int|string $term     The term to check. Accepts term ID, slug, or name..
	 * @param string     $taxonomy Optional. The taxonomy name to use.
	 * @param int        $parent   Optional. ID of parent term under which to confine the exists search.
	 *
	 * @return mixed @see term_exists.
	 */
	public function term_exists( $term, $taxonomy = '', $parent = null ) {
		return term_exists( $term, $taxonomy, $parent );
	}

	/**
	 * Wrapper for WP's native \get_post().
	 *
	 * @param int|WP_Post|null $post   Optional. Post ID or post object. `null`, `false`, `0` and other PHP falsey
	 *                                 values return the current global post inside the loop. A numerically valid post
	 *                                 ID that points to a non-existent post returns `null`. Defaults to global $post.
	 * @param string           $output Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N, which
	 *                                 correspond to a WP_Post object, an associative array, or a numeric array,
	 *                                 respectively. Default OBJECT.
	 * @param string           $filter Optional. Type of filter to apply. Accepts 'raw', 'edit', 'db',
	 *                                 or 'display'. Default 'raw'.
	 * @return WP_Post|array|null Type corresponding to $output on success or null on failure.
	 *                            When $output is OBJECT, a `WP_Post` instance is returned.
	 */
	public function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
		return get_post( $post, $output, $filter );
	}

	/**
	 * Filters a multidimensional array and searches for a subarray with a key and value.
	 *
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for
	 *
	 * @return null|mixed
	 */
	public function filter_array_element( $array, $key, $value ) {
		foreach ( $array as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				return $subarray;
			}
		}

		return null;
	}

	/**
	 * Filters a multidimensional array and searches for all subarray elemens containing a key and value.
	 *
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for
	 *
	 * @return array
	 */
	public function filter_array_elements( $array, $key, $value ) {
		$found = [];
		foreach ( $array as $subarray ) {
			if ( isset( $subarray[ $key ] ) && $value == $subarray[ $key ] ) {
				$found[] = $subarray;
			}
		}

		return $found;
	}
}
