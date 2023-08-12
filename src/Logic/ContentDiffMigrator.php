<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use \WP_CLI;
use \WP_User;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackCustomContentMigrator\Utils\PHP as PHPUtil;
use wpdb;

/**
 * Class ContentDiffMigrator and main logic.
 *
 * @package NewspackCustomContentMigrator\Logic
 */
class ContentDiffMigrator {

	// Postmeta telling us what the old live ID was.
	const SAVED_META_LIVE_POST_ID = 'newspackcontentdiff_live_id';

	// Data array keys.
	const DATAKEY_POST              = 'post';
	const DATAKEY_POSTMETA          = 'postmeta';
	const DATAKEY_COMMENTS          = 'comments';
	const DATAKEY_COMMENTMETA       = 'commentmeta';
	const DATAKEY_USERS             = 'users';
	const DATAKEY_USERMETA          = 'usermeta';
	const DATAKEY_TERMRELATIONSHIPS = 'term_relationships';
	const DATAKEY_TERMTAXONOMY      = 'term_taxonomy';
	const DATAKEY_TERMS             = 'terms';
	const DATAKEY_TERMMETA          = 'termmeta';

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
	 * Global $wpdb.
	 *
	 * @var wpdb Global $wpdb.
	 */
	private $wpdb;

	/**
	 * WpBlockManipulator.
	 *
	 * @var WpBlockManipulator.
	 */
	private $wp_block_manipulator;

	/**
	 * HtmlElementManipulator.
	 *
	 * @var HtmlElementManipulator
	 */
	private $html_element_manipulator;

	/**
	 * Crawler.
	 *
	 * @var Crawler.
	 */
	private $dom_crawler;

	/**
	 * ContentDiffMigrator constructor.
	 *
	 * @param object $wpdb Global $wpdb.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb                     = $wpdb;
		$this->wp_block_manipulator     = new WpBlockManipulator();
		$this->html_element_manipulator = new HtmlElementManipulator();
	}

	/**
	 * Gets a diff of new Posts, Pages and Attachments from the Live Site.
	 *
	 * @param string $live_table_prefix Table prefix for the Live Site.
	 *
	 * @return     array Result from $wpdb->get_results.
	 * @throws     \RuntimeException Throws exception if any live tables do not match the collation of their corresponding Core WP DB table.
	 * @deprecated Since large JOINs can time out on Atomic, this was eprecated in favor of `get_posts_rows_for_content_diff` and
	 * `filter_new_live_ids`. And there's also the new `filter_modified_live_ids` method.
	 */
	public function get_live_diff_content_ids( $live_table_prefix ) {
		if ( ! $this->are_table_collations_matching( $live_table_prefix ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}

		$ids              = [];
		$live_posts_table = esc_sql( $live_table_prefix ) . 'posts';
		$posts_table      = $this->wpdb->prefix . 'posts';

		// Get all Posts and Pages except revisions and trashed items.
		$sql_posts = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'post', 'page' )
			AND lwp.post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
			AND wp.ID IS NULL;";
		// phpcs:ignore -- no SQL parameters used.
		$results   = $this->wpdb->get_results( $sql_posts, ARRAY_A );
		foreach ( $results as $result ) {
			$ids[] = $result['ID'];
		}

		// Get attachments.
		$sql_attachments = "SELECT lwp.ID FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
			WHERE lwp.post_type IN ( 'attachment' )
			AND wp.ID IS NULL;";
		// phpcs:ignore -- no SQL parameters used.
		$results         = $this->wpdb->get_results( $sql_attachments, ARRAY_A );
		foreach ( $results as $result ) {
			$ids[] = $result['ID'];
		}

		return $ids;
	}

	/**
	 * Gets records from the $posts_table, $post_types, returns the minimal set of columns needed to determine whether a post
	 * doesn't exist in DB and needs to be inserted, or has been modified and needs to be updated.
	 *
	 * @param string $posts_table   Name of posts table.
	 * @param array  $post_types    Post types to fetch.
	 * @param array  $post_statuses Post statuses to fetch.
	 *
	 * @return array Associative array with columns specified in used query.
	 */
	public function get_posts_rows_for_content_diff( string $posts_table, array $post_types, array $post_statuses ) {
		// Get post types and statuses placeholders for $wpdb::prepare.
		$post_types_placeholders        = array_fill( 0, count( $post_types ), '%s' );
		$post_types_placeholders_csv    = implode( ',', $post_types_placeholders );
		$post_statuses_placeholders     = array_fill( 0, count( $post_statuses ), '%s' );
		$post_statuses_placeholders_csv = implode( ',', $post_statuses_placeholders );

		// $wpdb->prepare can't handle table names, so we'll additionally str_replace {TABLE}.
		// phpcs:disable
		$sql_replace_table = $this->wpdb->prepare(
			"SELECT ID, post_name, post_title, post_status, post_type, post_date, post_modified
				FROM {TABLE}
				WHERE post_type IN ( $post_types_placeholders_csv )
				AND post_status IN ( $post_statuses_placeholders_csv );",
			array_merge( $post_types, $post_statuses )
		);
		$posts_table_escaped = esc_sql( $posts_table );
		$results             = $this->wpdb->get_results(  str_replace( '{TABLE}', $posts_table_escaped, $sql_replace_table), ARRAY_A );

		// Return empty array instead of null.
		$results = is_null( $results ) ? [] : $results;

		return $results;
	}

	/**
	 * Gets attachments' "old_id"=>"new_id" IDs mapping from the postmeta.
	 *
	 * @return array Imported attachment IDs, keys are old/live IDs, values are new/local/Staging IDs.
	 */
	public function get_imported_attachment_id_mapping_from_db(): array {

		$attachment_ids_map = [];

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT wpm.post_id, wpm.meta_value
					FROM {$this->wpdb->postmeta} wpm
					JOIN {$this->wpdb->posts} wp ON wp.ID = wpm.post_id 
					WHERE wpm.meta_key = %s
					AND wp.post_type = 'attachment';",
				self::SAVED_META_LIVE_POST_ID,
			),
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$attachment_ids_map[ $result['meta_value'] ] = $result['post_id'];
		}

		return $attachment_ids_map;
	}

	/**
	 * Gets posts' and pages' "old_id"=>"new_id" IDs mapping from the postmeta.
	 *
	 * @return array Imported post and pages IDs, keys are old/live IDs, values are new/local/Staging IDs.
	 */
	public function get_imported_post_id_mapping_from_db( $post_types = [ 'post', 'page' ] ): array {

		$post_ids_map = [];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated dynamically.
		$post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%d' ) );
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT wpm.post_id, wpm.meta_value
					FROM {$this->wpdb->postmeta} wpm
					JOIN {$this->wpdb->posts} wp ON wp.ID = wpm.post_id 
					WHERE wpm.meta_key = %s
					AND wp.post_type IN ( {$post_types_placeholders} );",
				array_merge(
					[ self::SAVED_META_LIVE_POST_ID ] ,
					$post_types
				)
			),
			ARRAY_A
		);
		// phpcs:disable

		foreach ( $results as $result ) {
			$post_ids_map[ $result['meta_value'] ] = $result['post_id'];
		}

		return $post_ids_map;
	}

	/**
	 * Finds unique records in live posts table which don't exist in local posts table.
	 * Uses programmatic approach which requires less memory, but is a bit slower to run.
	 *
	 * Outputs progress by 10% increments to the CLI.
	 *
	 * @param array $results_live_posts  Rows from live posts table.
	 * @param array $results_local_posts Rows from local posts table.
	 *
	 * @return array IDs of posts found.
	 */
	public function filter_new_live_ids( array $results_live_posts, array $results_local_posts ): array {
		// Search unique on live.
		$ids = [];

		$percent_progress = null;
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress meter by 10% increments.
			$last_percent_progress = $percent_progress;
			$this->get_progress_percentage( count( $results_live_posts ), $key_live_post + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			$found = false;
			foreach ( $results_local_posts as $key_local_post => $local_post ) {
				if (
					$live_post['post_name'] == $local_post['post_name']
					&& $live_post['post_title'] == $local_post['post_title']
					&& $live_post['post_type'] == $local_post['post_type']
					&& $live_post['post_status'] == $local_post['post_status']
					&& $live_post['post_date'] == $local_post['post_date']
				) {
					// Remove the local post which was found (break; was done), to make the next search a bit faster.
					unset( $results_local_posts[ $key_local_post ] );

					$found = true;
					break;
				}
			}

			// Unique on live, add to $ids.
			if ( false === $found ) {
				$ids[] = $live_post['ID'];
			}
		}

		return $ids;
	}

	/**
	 * Finds records in live posts table which have a newer post_modified date.
	 * Uses programmatic approach which requires less memory, but is a bit slower to run.
	 *
	 * Outputs progress by 10% increments to the CLI.
	 *
	 * @param array $results_live_posts  Rows from live posts table.
	 * @param array $results_local_posts Rows from local posts table.
	 *
	 * @return array $ids_modified {
	 *     IDs of posts found.
	 *
	 *     @type int live_id  Live Post ID.
	 *     @type int local_id Matching Local Post ID.
	 * }
	 */
	public function filter_modified_live_ids( array $results_live_posts, array $results_local_posts ): array {

		// Check if modified date is different. Posts which were already imported with Content Diff will have the original meta ID.
		// But posts which were imported just by raw table import won't have the meta. So a full comparisson is needed.
		$ids_modified = [];

		$percent_progress = null;
		foreach ( $results_live_posts as $key_live_post => $live_post ) {

			// Output progress meter by 10% increments.
			$last_percent_progress = $percent_progress;
			$this->get_progress_percentage( count( $results_live_posts ), $key_live_post + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			$modified = false;
			foreach ( $results_local_posts as $key_local_post => $local_post ) {
				if (
					$live_post['post_name'] == $local_post['post_name']
					&& $live_post['post_title'] == $local_post['post_title']
					&& $live_post['post_status'] == $local_post['post_status']
					&& $live_post['post_date'] == $local_post['post_date']
					&& $live_post['post_modified'] > $local_post['post_modified']
				) {
					$modified = true;
					break;
				}
			}

			// Modified on live, add to $ids_modified.
			if ( true === $modified ) {
				$ids_modified[] = [
					'live_id'  => (int) $live_post['ID'],
					'local_id' => (int) $local_post['ID'],
				];
			}
		}

		return $ids_modified;
	}

	/**
	 * Fetches a Post and all core WP relational objects belonging to the post. Can fetch from a custom table prefix.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $table_prefix Table prefix to fetch from.
	 *
	 * @return array $args {
	 *     Post and all core WP Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` row.
	 *     @type array self::DATAKEY_POSTMETA          Post's `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          Post's `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       Post's `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             Post's `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          Post's `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS Post's `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      Post's `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             Post's `terms` rows.
	 * }
	 */
	public function get_post_data( $post_id, $table_prefix ) {

		$data = $this->get_empty_data_array();

		// Get Post.
		$post_row                   = $this->select_post_row( $table_prefix, $post_id );
		$data[ self::DATAKEY_POST ] = $post_row;

		// Get Post Metas.
		$data[ self::DATAKEY_POSTMETA ] = $this->select_postmeta_rows( $table_prefix, $post_id );

		// Get Post Author User.
		$author_row                    = $this->select_user_row( $table_prefix, $data[ self::DATAKEY_POST ]['post_author'] );
		$data[ self::DATAKEY_USERS ][] = $author_row;

		// Get Post Author User Metas.
		if ( is_array( $author_row ) && array_key_exists( 'ID', $author_row ) ) {
			$data[ self::DATAKEY_USERMETA ] = array_merge(
				$data[ self::DATAKEY_USERMETA ],
				$this->select_usermeta_rows( $table_prefix, $author_row['ID'] )
			);
		}

		// Get Comments.
		if ( $post_row['comment_count'] > 0 ) {
			$comment_rows                   = $this->select_comment_rows( $table_prefix, $post_id );
			$data[ self::DATAKEY_COMMENTS ] = $comment_rows;

			// Get Comment Metas.
			foreach ( $comment_rows as $key_comment => $comment ) {
				$data[ self::DATAKEY_COMMENTMETA ] = array_merge(
					$data[ self::DATAKEY_COMMENTMETA ],
					$this->select_commentmeta_rows( $table_prefix, $comment['comment_ID'] )
				);

				// Get Comment User (if the same User was not already fetched).
				if ( $comment['user_id'] > 0 && empty( $this->filter_array_elements( $data[ self::DATAKEY_USERS ], 'ID', $comment['user_id'] ) ) ) {
					$comment_user_row              = $this->select_user_row( $table_prefix, $comment['user_id'] );
					$data[ self::DATAKEY_USERS ][] = $comment_user_row;

					// Get Get Comment User Metas.
					$data[ self::DATAKEY_USERMETA ] = array_merge(
						$data[ self::DATAKEY_USERMETA ],
						$this->select_usermeta_rows( $table_prefix, $comment_user_row['ID'] )
					);
				}
			}
		}

		// Get Term Relationships.
		$term_relationships_rows                 = $this->select_term_relationships_rows( $table_prefix, $post_id );
		$data[ self::DATAKEY_TERMRELATIONSHIPS ] = $term_relationships_rows;

		// Get all wp_term_taxonomy records.
		$term_ids                            = [];
		$keys_with_missing_term_taxonomy_ids = [];
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $key_termrelationship_row => $term_relationship_row ) {
			$term_taxonomy_id = $term_relationship_row['term_taxonomy_id'];
			$term_taxonomy    = $this->select_term_taxonomy_row( $table_prefix, $term_taxonomy_id );

			// In case that the term_taxonomy record is missing from Live DB for this $term_taxonomy_id.
			if ( is_null( $term_taxonomy ) ) {
				$keys_with_missing_term_taxonomy_ids[] = $key_termrelationship_row;
				continue;
			}

			$data[ self::DATAKEY_TERMTAXONOMY ][] = $term_taxonomy;
			$term_ids[]                           = $term_taxonomy['term_id'];
		}

		// Clean up $data[ self::DATAKEY_TERMRELATIONSHIPS ] in case some $term_taxonomy_id records are missing from live DB.
		if ( ! empty( $keys_with_missing_term_taxonomy_ids ) ) {
			foreach ( $keys_with_missing_term_taxonomy_ids as $key_with_missing_term_taxonomy_id ) {
				unset( $data[ self::DATAKEY_TERMRELATIONSHIPS ][ $key_with_missing_term_taxonomy_id ] );
			}
			$data[ self::DATAKEY_TERMRELATIONSHIPS ] = array_values( $data[ self::DATAKEY_TERMRELATIONSHIPS ] );
		}

		// Get Terms.
		$missing_term_ids = [];
		foreach ( $term_ids as $term_id ) {
			$term_row = $this->select_term_row( $table_prefix, $term_id );

			// In case some terms records are missing in Live DB.
			if ( is_null( $term_row ) || empty( $term_row ) ) {
				$missing_term_ids[] = $term_id;
				continue;
			}

			$data[ self::DATAKEY_TERMS ][] = $term_row;
		}

		// Get Term Metas.
		foreach ( $term_ids as $term_id ) {
			// Skip if term rows were missing.
			if ( in_array( $term_id, $missing_term_ids ) ) {
				continue;
			}

			$termmeta_rows = $this->select_termmeta_rows( $table_prefix, $term_id );
			if ( is_null( $termmeta_rows ) || empty( $termmeta_rows ) ) {
				continue;
			}

			$data[ self::DATAKEY_TERMMETA ] = array_merge(
				$data[ self::DATAKEY_TERMMETA ],
				$termmeta_rows
			);
		}

		return $data;
	}

	/**
	 * Checks local Categories, and returns those which might have wrong parent term_ids that don't exist.
	 *
	 * @param string $table_prefix DB table prefix which is to be used for this query.
	 *
	 * @return array $args {
	 *     Categories which have nonexistent parent term_id.
	 *
	 *     @type string term_id          wp_terms.term_id.
	 *     @type string name             wp_terms.name.
	 *     @type string slug             wp_terms.slug.
	 *     @type string term_taxonomy_id wp_termtaxonomy.term_taxonomy_id.
	 *     @type string taxonomy         wp_termtaxonomy.taxonomy, will be 'category'.
	 *     @type string parent           wp_termtaxonomy.parent which is not found in wp_terms and is wrong.
	 * }
	 */
	public function get_categories_with_nonexistent_parents( $table_prefix ): array {

		$terms         = esc_sql( $table_prefix . 'terms' );
		$term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used by wrapper and query fully sanitized.
		$categories_with_nonexistent_parents = $this->wpdb->get_results(
			"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.parent
			FROM {$terms} t
	        JOIN {$term_taxonomy} tt
				ON t.term_id = tt.term_id AND tt.taxonomy = 'category' AND parent <> 0
	        LEFT JOIN {$terms} ttparent
				ON ttparent.term_id = tt.parent
			WHERE ttparent.term_id IS NULL;",
			ARRAY_A
		);
		// phpcs:enable

		return $categories_with_nonexistent_parents;
	}

	/**
	 * Sets these wp_term_taxnomy.term_taxonomy_ids' parents to 0.
	 *
	 * @param string $table_prefix      DB table prefix.
	 * @param array  $term_taxonomy_ids term_taxonomy_ids.
	 *
	 * @return void
	 */
	public function reset_categories_parents( string $table_prefix, array $term_taxonomy_ids ): void {
		$placeholders  = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );
		// phpcs:disable -- wpdb::prepare used by wrapper and query fully sanitized.
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$term_taxonomy} SET parent = 0 WHERE term_taxonomy_ID IN ( {$placeholders} );",
				$term_taxonomy_ids
			)
		);
		// phpcs:enable
	}

	/**
	 * Recreates all categories from Live to local.
	 *
	 * @param string $live_table_prefix Live DB table prefix.
	 *
	 * @return array Map of all live to local categories. Keys are live category term_ids, and values are their corresponding
	 *               local category term_ids.
	 */
	public function recreate_categories( $live_table_prefix ) {
		$table_prefix             = $this->wpdb->prefix;
		$live_terms_table         = esc_sql( $live_table_prefix . 'terms' );
		$live_termstaxonomy_table = esc_sql( $live_table_prefix . 'term_taxonomy' );

		// Get all live site's hierarchical categories, ordered by parent for easy hierarchical reconstruction.
		// phpcs:disable -- wpdb::prepare is used by wrapper.
		$live_categories = $this->wpdb->get_results(
			"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
			FROM $live_terms_table t
	        JOIN $live_termstaxonomy_table tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy IN ( 'category' )
			ORDER BY tt.parent;",
			ARRAY_A
		);
		// phpcs:enable

		// Go through all the $live_taxonomies and get or create them on local, and mark their term_id changes in $category_term_id_updates.
		$category_term_id_updates = [];
		foreach ( $live_categories as $live_category ) {
			$live_category_tree    = $this->get_category_tree( $live_table_prefix, $live_category );
			$created_category_tree = $this->get_or_create_category_tree( $table_prefix, $live_category_tree );

			$category_term_id_updates[ $live_category['term_id'] ] = $created_category_tree['term_id'];
		}

		return $category_term_id_updates;
	}

	/**
	 * Fetches the category's tree by retrieving all the parent categories down to the top parent.
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param array  $category {
	 *    Category data array.
	 *
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type string parent      Category parent term_id.
	 * }
	 *
	 * @return array {
	 *     A nested array of categories, where 'parent' key is either another subarray category, or '0' if no parent.
	 *
	 *     @type string       term_id     Category term_id.
	 *     @type string       taxonomy    Should always be 'category'.
	 *     @type string       name        Category name.
	 *     @type string       slug        Category slug.
	 *     @type string       description Category description.
	 *     @type string       count       Category count.
	 *     @type string|array parent      Either nested parent subarray category containing all the same keys and values, or '0'.
	 * }
	 */
	public function get_category_tree( $table_prefix, $category ) {

		$category_tree = $category;

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		$parent_term_id = $category['parent'];
		if ( 0 != $parent_term_id ) {
			// phpcs:disable -- wpdb::prepare used by wrapper.
			$parent_row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM {$table_terms} t
			        JOIN {$table_term_taxonomy} tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy IN ( 'category' )
					AND t.term_id = %s
					ORDER BY tt.parent;",
					$parent_term_id
				),
				ARRAY_A
			);
			// phpcs:enable

			// This is either root category, or go level up recursively.
			if ( 0 == $parent_row['parent'] ) {
				$category_tree['parent'] = $parent_row;
			} else {
				$category_tree['parent'] = $this->get_category_tree( $table_prefix, $parent_row );
			}
		}

		return $category_tree;
	}

	/**
	 * Rebuilds the full tree of a category. Either taxes an existing category, or creates it.
	 *
	 * @param string $table_prefix  DB table prefix.
	 * @param array  $category_tree {
	 *     A nested array of categories, where 'parent' key is either another subarray category, or '0' if no parent.
	 *     This is being read as a parameter and will be rebuilt node by node.
	 *
	 *     @type string       term_id     Category term_id.
	 *     @type string       taxonomy    Should always be 'category'.
	 *     @type string       name        Category name.
	 *     @type string       slug        Category slug.
	 *     @type string       description Category description.
	 *     @type string       count       Category count.
	 *     @type string|array parent      Either nested parent subarray category containing all the same keys and values, or '0'.
	 * }
	 *
	 * @return array {
	 *     A nested array of categories, where 'parent' key is either another subarray category, or '0' if no parent.
	 *     This is the resulting category tree, either existing categories fetched or new ones created.
	 *
	 *     @type string       term_id     Category term_id.
	 *     @type string       taxonomy    Should always be 'category'.
	 *     @type string       name        Category name.
	 *     @type string       slug        Category slug.
	 *     @type string       description Category description.
	 *     @type string       count       Category count.
	 *     @type string|array parent      Either nested parent subarray category containing all the same keys and values, or '0'.
	 * }
	 */
	public function get_or_create_category_tree( $table_prefix, $category_tree ) {

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// If this is the top parent category, get or create it.
		if ( 0 == $category_tree['parent'] ) {

			// Get or create this top parent category.
			$category_top_parent_row     = $this->get_category_array_by_name_and_parent( $table_prefix, $category_tree['name'], 0 );
			$category_top_parent_term_id = $category_top_parent_row['term_id'] ?? null;
			if ( ! $category_top_parent_term_id ) {
				// Insert it if it doesn't exist.
				$category_top_parent_term_id = $this->wp_insert_category(
					$table_prefix,
					[
						'cat_name'             => $category_tree['name'],
						'category_description' => $category_tree['description'],
						'category_parent'      => 0,
					]
				);
			}
			// Get this parent category's full array.
			$category_top_parent = $this->get_category_array_by_term_id( $table_prefix, $category_top_parent_term_id );

			return $category_top_parent;
		}

		// If this is not top parent category, keep going deeper recursively until reaching it.
		if ( 0 != $category_tree['parent'] ) {
			$current_parent_tree = $this->get_or_create_category_tree( $table_prefix, $category_tree['parent'] );
		}

		// For a non-top-parent category, get or create its tree and return.
		$category_row     = $this->get_category_array_by_name_and_parent( $table_prefix, $category_tree['name'], $current_parent_tree['term_id'] );
		$category_term_id = $category_row['term_id'] ?? null;
		if ( ! $category_term_id ) {
			$category_term_id = $this->wp_insert_category(
				$table_prefix,
				[
					'cat_name'             => $category_tree['name'],
					'category_description' => $category_tree['description'],
					'category_parent'      => $current_parent_tree['term_id'],
				]
			);
		}
		$category = $this->get_category_array_by_term_id( $table_prefix, $category_term_id );

		// This is the reubuilt category tree.
		$rebuilt_category_tree           = $category;
		$rebuilt_category_tree['parent'] = $current_parent_tree;

		return $rebuilt_category_tree;
	}

	/**
	 * Gets a category array with all the related data by term_id.
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param string $term_id      term_id.
	 *
	 * @return array {
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type string parent      Category parent's term_id.
	 * }
	 */
	public function get_category_array_by_term_id( $table_prefix, $term_id ) {

		$category_data = $this->get_term_and_taxonomy_array( $table_prefix, [ 'term_id' => $term_id ], 'category' );

		return $category_data;
	}

	/**
	 * Gets Term and Taxonomy data array by either term_id or Term name.
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param array  $where        Where clause. Must provide either 'term_id' or 'term_name' key and value.
	 * @param string $taxonomy     Taxonomy.
	 *
	 * @return array|null {
	 *     @type string term_id     Term term_id.
	 *     @type string taxonomy    Term taxonomy, e.g. 'category' or 'post_tag'.
	 *     @type string name        Term name.
	 *     @type string slug        Term slug.
	 *     @type string description Taxonomy description.
	 *     @type string count       Term count.
	 *     @type string parent      Term parent's term_id.
	 * }
	 */
	public function get_term_and_taxonomy_array( $table_prefix, array $where, $taxonomy ) {

		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		$query_and_clause    = '';
		$query_and_parameter = null;
		if ( isset( $where['term_id'] ) ) {
			$query_and_clause    = ' AND t.term_id = %s ';
			$query_and_parameter = $where['term_id'];
		} elseif ( isset( $where['term_name'] ) ) {
			$query_and_clause    = ' AND t.name = %s ';
			$query_and_parameter = $where['term_name'];
		} else {
			return null;
		}

		// phpcs:disable -- wpdb::prepare used by wrapper.
		$term_taxonomy_data = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, tt.term_taxonomy_id, t.name, t.slug, tt.parent, tt.description, tt.count
				FROM $table_terms t
		        JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
				WHERE tt.taxonomy = %s
				{$query_and_clause} ;",
				$taxonomy,
				$query_and_parameter
			),
			ARRAY_A
		);
		// phpcs:enable

		return $term_taxonomy_data;
	}

	/**
	 * Gets category by its name and parent.
	 *
	 * @param string $table_prefix    DB table prefix.
	 * @param string $cat_name        Category name.
	 * @param string $cat_parent      Category parent's term_id.
	 *
	 * @return array {
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type string parent      Category parent's term_id.
	 * }
	 */
	public function get_category_array_by_name_and_parent( $table_prefix, $cat_name, $cat_parent ) {
		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used by wrapper.
		$category = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM $table_terms t
			        JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = 'category'
					AND tt.parent = %s
					AND t.name = %s;",
				$cat_parent,
				$cat_name
			),
			ARRAY_A
		);
		// phpcs:enable

		return $category;
	}

	/**
	 * Gets category by its name, description and parent
	 *
	 * @param string $table_prefix    DB table prefix.
	 * @param string $cat_name        Category name.
	 * @param string $cat_description Category description.
	 * @param string $cat_parent      Category parent's term_id.
	 *
	 * @return array {
	 *     @type string term_id     Category term_id.
	 *     @type string taxonomy    Should always be 'category'.
	 *     @type string name        Category name.
	 *     @type string slug        Category slug.
	 *     @type string description Category description.
	 *     @type string count       Category count.
	 *     @type string parent      Category parent's term_id.
	 * }
	 */
	public function get_category_array_by_name_description_and_parent( $table_prefix, $cat_name, $cat_description, $cat_parent ) {
		$table_terms         = esc_sql( $table_prefix . 'terms' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );

		// phpcs:disable -- wpdb::prepare used by wrapper.
		$category = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, t.slug, tt.parent, tt.description, tt.count
					FROM $table_terms t
			        JOIN $table_term_taxonomy tt ON t.term_id = tt.term_id
					WHERE tt.taxonomy = 'category'
					AND tt.parent = %s
					AND t.name = %s
					AND tt.description = %s;",
				$cat_parent,
				$cat_name,
				$cat_description
			),
			ARRAY_A
		);
		// phpcs:enable

		return $category;
	}

	/**
	 * A wrapper for \wp_insert_category().
	 *
	 * @param string $table_prefix DB table prefix.
	 * @param array  $catarr       $catarr argument for \wp_insert_category(), see \wp_insert_category().
	 *
	 * @return int|\WP_Error The ID number of the new or updated Category on success. Zero or a WP_Error on failure,
	 *                       depending on param `$wp_error`.
	 */
	public function wp_insert_category( $table_prefix, $catarr ) {
		$category_top_parent_term_id = wp_insert_category( $catarr );

		return $category_top_parent_term_id;
	}

	/**
	 * Imports all the Post related data.
	 *
	 * @param int   $post_id                  Post Id.
	 * @param array $data                     Array containing all the data, @see
	 *                                        \NewspackCustomContentMigrator\Logic\ContentDiffMigrator::get_post_data
	 *                                        for structure.
	 * @param array $category_term_id_updates Category term_ids updates. Keys are old Live category term_ids, and values are
	 *                                        corresponding Categories on local (Staging) term_ids.
	 *
	 * @return array List of errors which occurred.
	 */
	public function import_post_data( $post_id, $data, $category_term_id_updates ) {
		$error_messages = [];

		// Insert Post Metas.
		foreach ( $data[ self::DATAKEY_POSTMETA ] as $postmeta_row ) {
			try {
				$this->insert_postmeta_row( $postmeta_row, $post_id );
			} catch ( \Exception $e ) {
				$error_messages[] = $e->getMessage();
			}
		}

		// Get existing Author User or insert a new one.
		$author_id_old = $data[ self::DATAKEY_POST ]['post_author'];
		$author_row    = ! is_null( $author_id_old ) ? $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $author_id_old ) : [];
		$usermeta_rows = is_array( $author_row ) && array_key_exists( 'ID', $author_row ) ? $this->filter_array_elements( $data[ self::DATAKEY_USERMETA ], 'user_id', $author_row['ID'] ) : [];
		$user_existing = is_array( $author_row ) && array_key_exists( 'user_login', $author_row ) ? $this->get_user_by( 'login', $author_row['user_login'] ) : false;
		$author_id_new = null;
		if ( $user_existing instanceof WP_User ) {
			$author_id_new = (int) $user_existing->ID;
		} elseif ( is_null( $author_row ) ) {
			// Some source posts might have author value 0.
			$author_id_new = 0;
		} else {
			// Insert a new Author User.
			try {
				$author_id_new = $this->insert_user( $author_row );
				foreach ( $usermeta_rows as $usermeta_row ) {
					$this->insert_usermeta_row( $usermeta_row, $author_id_new );
				}
			} catch ( \Exception $e ) {
				$error_messages[] = $e->getMessage();
			}
		}

		// Update inserted Post's Author.
		if ( ! is_null( $author_id_new ) && $author_id_new != $author_id_old ) {
			try {
				$this->update_post_author( $post_id, $author_id_new );
			} catch ( \Exception $e ) {
				$error_messages[] = $e->getMessage();
			}
		}

		// Insert Comments.
		$comment_ids_updates = [];
		foreach ( $data[ self::DATAKEY_COMMENTS ] as $comment_row ) {
			$comment_id_old = (int) $comment_row['comment_ID'];

			// Insert the Comment User.
			$comment_user_id_old = (int) $comment_row['user_id'];
			$comment_user_id_new = null;
			if ( 0 === $comment_user_id_old ) {
				$comment_user_id_new = 0;
			} else {
				// Get existing Comment User or insert a new one.
				$comment_user_row      = $this->filter_array_element( $data[ self::DATAKEY_USERS ], 'ID', $comment_user_id_old );
				$comment_user_existing = null;
				if ( ! is_null( $comment_user_row ) ) {
					$comment_usermeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_USERMETA ], 'user_id', $comment_user_row['ID'] );
					$comment_user_existing = $this->get_user_by( 'login', $comment_user_row['user_login'] );
				}
				if ( $comment_user_existing instanceof WP_User ) {
					$comment_user_id_new = (int) $comment_user_existing->ID;
				} else {
					// Insert a new Comment User.
					try {
						$comment_user_id_new = $this->insert_user( $comment_user_row );
						foreach ( $comment_usermeta_rows as $comment_usermeta_row ) {
							$this->insert_usermeta_row( $comment_usermeta_row, $comment_user_id_new );
						}
					} catch ( \Exception $e ) {
						$error_messages[] = $e->getMessage();
					}
				}
			}

			// Insert Comment and Comment Metas.
			$commentmeta_rows = $this->filter_array_elements( $data[ self::DATAKEY_COMMENTMETA ], 'comment_id', $comment_id_old );
			$comment_id_new   = null;
			try {
				$comment_id_new                         = $this->insert_comment( $comment_row, $post_id, $comment_user_id_new );
				$comment_ids_updates[ $comment_id_old ] = $comment_id_new;
				foreach ( $commentmeta_rows as $commentmeta_row ) {
						$this->insert_commentmeta_row( $commentmeta_row, $comment_id_new );
				}
			} catch ( \Exception $e ) {
				$error_messages[] = $e->getMessage();
			}
		}

		// Loop through all comments, and update their Parent IDs.
		foreach ( $comment_ids_updates as $comment_id_old => $comment_id_new ) {
			$comment_row        = $this->filter_array_element( $data[ self::DATAKEY_COMMENTS ], 'comment_ID', $comment_id_old );
			$comment_parent_old = $comment_row['comment_parent'];
			$comment_parent_new = $comment_ids_updates[ $comment_parent_old ] ?? null;
			if ( ( $comment_parent_old > 0 ) && $comment_parent_new && ( $comment_parent_old != $comment_parent_new ) ) {
				try {
					$this->update_comment_parent( $comment_id_new, $comment_parent_new );
				} catch ( \Exception $e ) {
					$error_messages[] = $e->getMessage();
				}
			}
		}

		// Import 'category' and 'post_tag' taxonomies.
		$inserted_term_taxonomy_ids = [];
		foreach ( $data[ self::DATAKEY_TERMRELATIONSHIPS ] as $key_term_relationship_row => $term_relationship_row ) {

			$live_term_taxonomy_id  = $term_relationship_row['term_taxonomy_id'];
			$live_term_taxonomy_row = $this->filter_array_element( $data[ self::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', $live_term_taxonomy_id );
			$live_term_id           = $live_term_taxonomy_row['term_id'];
			$live_taxonomy          = $live_term_taxonomy_row['taxonomy'];
			$live_term_row          = $this->filter_array_element( $data[ self::DATAKEY_TERMS ], 'term_id', $live_term_id );

			// Validate live term row, it could be missing or invalid.
			if ( is_null( $live_term_row ) ) {
				$error_messages[] = sprintf( 'Faulty term relationship record in live DB, term skipped: posts.ID=%d > term_relationships has term_taxonomy_id=%d > term_taxonomy has term_id=%d >> term_id does not exist in live DB table.', $data['post']['ID'], $live_term_taxonomy_id, $live_term_taxonomy_row['term_id'] );
				continue;
			}

			$live_term_name         = $live_term_row['name'];

			// These are the values we're going to get first, then update.
			$local_term_id             = null;
			$local_term_taxonomy_id    = null;
			$local_term_taxonomy_count = null;
			// Helper vars.
			$local_term_taxonomy_data = null;
			$local_term_name          = null;

			// If it's a category, all of them have already been recreated on Staging -- see $category_term_id_updates. Now just get the local corresponding term_taxonomy_id for this $live_term_taxonomy_id.
			if ( 'category' == $live_term_taxonomy_row['taxonomy'] ) {

				$local_term_id             = $category_term_id_updates[ $live_term_id ];
				$local_term_taxonomy_data  = $this->get_term_and_taxonomy_array( $this->wpdb->prefix, [ 'term_id' => $local_term_id ], 'category' );
				$local_term_taxonomy_id    = $local_term_taxonomy_data['term_taxonomy_id'];
				$local_term_name           = $local_term_taxonomy_data['name'];
				$local_term_taxonomy_count = $local_term_taxonomy_data['count'];

			} elseif ( 'post_tag' == $live_term_taxonomy_row['taxonomy'] ) {

				// Get or insert this Tag.
				$local_term_taxonomy_data = $this->get_term_and_taxonomy_array( $this->wpdb->prefix, [ 'term_name' => $live_term_name ], 'post_tag' );
				if ( is_null( $local_term_taxonomy_data ) || empty( $local_term_taxonomy_data ) ) {

					// Create a new Tag.
					$term_insert_result = $this->wp_insert_term( $live_term_name, 'post_tag' );
					if ( is_wp_error( $term_insert_result ) ) {
						$error_messages[] = sprintf(
							"Error occurred while inserting post_tag '%s' live_term_id=%s at live_post_ID=%s :%s",
							$live_term_name,
							$live_term_id,
							$post_id,
							$term_insert_result->get_error_message()
						);

						continue;
					}

					$local_term_id             = $term_insert_result['term_id'];
					$local_term_taxonomy_id    = $term_insert_result['term_taxonomy_id'];
					$local_term_taxonomy_data  = $this->get_term_and_taxonomy_array( $this->wpdb->prefix, [ 'term_id' => $local_term_id ], 'post_tag' );
					$local_term_taxonomy_count = $local_term_taxonomy_data['count'];

				} else {

					// Use the existing Tag.
					$local_term_taxonomy_id    = $local_term_taxonomy_data['term_taxonomy_id'];
					$local_term_id             = $local_term_taxonomy_data['term_id'];
					$local_term_taxonomy_count = $local_term_taxonomy_data['count'];
				}
			}

			/**
			 * We need to check if the same $local_term_taxonomy_id has already been inserted. This can happen if there are two
			 * tags which have the same name but different case, e.g. first tag with name 'reseñas' and second with name 'Reseñas'.
			 * WP distinguishes these Tags, but we should clean them up as we get the chance and merge them.
			 */
			$term_relationship_is_double = in_array( $local_term_taxonomy_id, $inserted_term_taxonomy_ids );

			if ( ! is_null( $local_term_taxonomy_id ) && ! $term_relationship_is_double ) {
				// Insert the Term Relationship record.
				$this->insert_term_relationship( $post_id, $local_term_taxonomy_id );

				// Increment wp_term_taxonomy.count.
				$this->wpdb->update( $this->wpdb->term_taxonomy, [ 'count' => ( (int) $local_term_taxonomy_count + 1 ) ], [ 'term_taxonomy_id' => $local_term_taxonomy_id ] );

				$inserted_term_taxonomy_ids[] = $local_term_taxonomy_id;
			}
		}

		return $error_messages;
	}

	/**
	 * Updates Post's post_parent ID.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_parent_id New post_parent ID for this post.
	 */
	public function update_post_parent( $post_id, $new_parent_id ) {
		$this->wpdb->update( $this->wpdb->posts, [ 'post_parent' => $new_parent_id ], [ 'ID' => $post_id ] );
	}

	/**
	 * Updates Posts' Thumbnail IDs with new Thumbnail IDs after insertion.
	 *
	 * @param array  $new_post_ids                Imported local Post IDs.
	 * @param array  $imported_attachment_ids_map Keys are IDs on Live Site, values are IDs of imported posts on Local Site.
	 * @param string $log_file_path               Optional. Full path to a log file. If provided, the method will save and append
	 *                                            a detailed output of all the changes made.
	 */
	public function update_featured_images( $new_post_ids, $imported_attachment_ids_map, $log_file_path ) {
		if ( empty( $new_post_ids ) || empty( $imported_attachment_ids_map ) ) {
			return;
		}

		/**
		 * We should only be updating old live site's Post IDs which had '_thumbnail_id's that are found in our "old_id" attachment mapping.
		 *
		 * Explanation why:
		 * for example, we could have imported two different attachments,
		 *      {"post_type":"attachment","id_old":1111,"id_new":999}
		 *      {"post_type":"attachment","id_old":1223,"id_new":1111}
		 * and there could be two posts currently on Staging
		 *      one with _thumbnail_id 1111
		 *          --> this one needs to be updated from 1111 to 999
		 *      second also with _thumbnail_id 1111, but let's say this post was created directly on staging and used the existing ID 1111
		 *          --> this one's _thumbnail_id MUST NOT be updated from 1111 to 999
		 *
		 * So, we should only update _thumbnail_ids for those posts that were imported by us.
		 */

		// Loop through posts, and update their _thumbnail_id if needed.
		foreach ( $new_post_ids as $new_post_id ) {

			// Get Post's current _thumbnail_id.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- correctly prepared.
			$current_thumbnail_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT meta_value
					FROM {$this->live_table_prefix}postmeta
					WHERE meta_key = '_thumbnail_id'
					AND post_id = %d",
					$new_post_id
				)
			);
			// phpcs:enable

			// Check if this _thumbnail_id is used as a key in $imported_attachment_ids_map (keys are "old_id"s, values are "new_id"s).
			if ( ! array_key_exists( $current_thumbnail_id, $imported_attachment_ids_map ) ) {
				continue;
			}

			// Get the new _thumbnail_id and update it.
			$new_thumbnail_id = $imported_attachment_ids_map[ $current_thumbnail_id ];
			$updated          = $this->wpdb->update(
				$this->wpdb->postmeta,
				[ 'meta_value' => $new_thumbnail_id ],
				[
					'post_id'  => $new_post_id,
					'meta_key' => '_thumbnail_id',
				]
			);

			// Log.
			if ( false != $updated && $updated > 0 && ! is_null( $log_file_path ) ) {
				$this->log(
					$log_file_path,
					json_encode(
						[
							'post_id' => (int) $new_post_id,
							'id_old'  => (int) $current_thumbnail_id,
							'id_new'  => (int) $new_thumbnail_id,
						]
					)
				);
			}
		}
	}

	/**
	 * Updates Gutenberg Blocks' attachment IDs with new attachment IDs in created `post_content` and `post_excerpt` fields.
	 *
	 * @param array  $imported_post_ids            An array of newly imported Post IDs. Will only fetch an do replacements in these.
	 * @param array  $known_attachment_ids_updates An array of known Attachment IDs which were updated; keys are old IDs, values are
	 *                                             new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local. Explanation and example --
	 *                                             let's take hostname.com and a local image https://hostname.com/wp-content/2022/09/22/a.jpg
	 *                                             as local image. Searching for this image's attachment ID will work just fine using
	 *                                             the full URL. But perhaps if this site is using an S3 bucket, and if some of
	 *                                             the URLs in post_content use https://hostname.s3.amazonaws.com/wp-content/uploads/2022/09/22/a.jpg
	 *                                             we should then add value 'hostname.s3.amazonaws.com' in this array here, so that
	 *                                             \attachment_url_to_postid can query the attachment ID by treating this S3 hostname
	 *                                             as an alias of the local one.
	 * @param string $log_file_path                Optional. Full path to a log file. If provided, will save and append a detailed
	 *                                             output of all the changes made.
	 *
	 * @return void
	 */
	public function update_blocks_ids( $imported_post_ids, array $known_attachment_ids_updates, array $local_hostname_aliases = [], $log_file_path = null ) {

		// Filter the $local_hostname_aliases argument -- remove the local host if the user entered it, just leaving additional hostname aliases here.
		if ( ! empty( $local_hostname_aliases ) ) {
			$siteurl_parsed     = wp_parse_url( get_option( 'siteurl' ) );
			$local_hostname     = $siteurl_parsed['host'];
			$key_local_hostname = array_search( $local_hostname, $local_hostname_aliases );
			if ( false !== $key_local_hostname ) {
				unset( $local_hostname_aliases[ $key_local_hostname ] );
				unset( $local_hostname_aliases[ $key_local_hostname ] );
			}
		}

		// Fetch imported posts.
		$post_ids_new = array_values( $imported_post_ids );
		$posts_table  = $this->wpdb->posts;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids_new ), '%d' ) );
		// phpcs:disable -- wpdb::prepare used by wrapper.
		$sql          = $this->wpdb->prepare(
			"SELECT ID, post_content, post_excerpt FROM $posts_table pm WHERE ID IN ( $placeholders );",
			$post_ids_new
		);
		$results      = $this->wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		// Loop through all imported posts, and do all the replacements.
		foreach ( $results as $key_result => $result ) {
			$id              = $result['ID'];
			$content_before  = $result['post_content'];
			$content_updated = $result['post_content'];
			$excerpt_before  = $result['post_excerpt'];
			$excerpt_updated = $result['post_excerpt'];

			// wp:image and wp:gallery.
			$content_updated = $this->update_image_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_image_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:audio.
			$content_updated = $this->update_audio_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_audio_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:video.
			$content_updated = $this->update_video_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_video_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:file.
			$content_updated = $this->update_file_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_file_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:cover.
			$content_updated = $this->update_cover_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_cover_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:media-text.
			$content_updated = $this->update_mediatext_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_mediatext_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:jetpack/tiled-gallery.
			$content_updated = $this->update_jetpacktiledgallery_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_jetpacktiledgallery_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:jetpack/slideshow.
			$content_updated = $this->update_jetpackslideshow_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_jetpackslideshow_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			// wp:jetpack/image-compare.
			$content_updated = $this->update_jetpackimagecompare_blocks_ids( $content_updated, $known_attachment_ids_updates, $local_hostname_aliases );
			$excerpt_updated = $this->update_jetpackimagecompare_blocks_ids( $excerpt_updated, $known_attachment_ids_updates, $local_hostname_aliases );

			// Persist.
			if ( $content_before != $content_updated || $excerpt_before != $excerpt_updated ) {
				$updated = $this->wpdb->update(
					$this->wpdb->posts,
					[
						'post_content' => $content_updated,
						'post_excerpt' => $excerpt_updated,
					],
					[ 'ID' => $id ]
				);
			}

			// Log updates.
			if ( ! is_null( $log_file_path ) ) {
				// Log the post ID that was checked.
				$log_entry = [ 'id_new' => $id ];

				// And if any updates were made, log them fully.
				if ( $content_before != $content_updated ) {
					$log_entry = array_merge(
						$log_entry,
						[
							'post_content_before' => $content_before,
							'post_content_after'  => $content_updated,
						]
					);
				}

				if ( $excerpt_before != $excerpt_updated ) {
					$log_entry = array_merge(
						$log_entry,
						[
							'post_excerpt_before' => $excerpt_before,
							'post_excerpt_after'  => $excerpt_updated,
						]
					);
				}

				$this->log( $log_file_path, json_encode( $log_entry ) );
			}
		}
	}

	/**
	 * Searches for all wp:image blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content                      post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_image_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Get all blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:image', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// We've seen some wp:image blocks with no ID, skip them.
			if ( ! isset( $block_updated['attrs']['id'] ) ) {
				continue;
			}

			// Get attachment ID from block header.
			$att_id = isset( $block_updated['attrs']['id'] ) ? $block_updated['attrs']['id'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <img> element from innerHTML -- there must be just one inside the image block.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No images.
				continue;
			}
			$img_html         = $matches[0][0][0];
			$img_html_updated = $img_html;

			// Get img src.
			$src = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update ID in image element `class` attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

			// Update the whole img HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs in block header.
			$block_updated['attrs']['id'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:audio blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_audio_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Match all wp:audio blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:audio', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through all audio blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $key_match => $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get attachment ID from block header.
			$att_id = isset( $block_updated['attrs']['id'] ) ? $block_updated['attrs']['id'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <audio> element from innerHTML.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'audio', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No audio element.
				continue;
			}
			$audio_html         = $matches[0][0][0];
			$audio_html_updated = $audio_html;

			// Get audio src.
			$src = $this->html_element_manipulator->get_attribute_value( 'src', $audio_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update the whole audio HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $audio_html, $audio_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $audio_html, $audio_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs in block header.
			$block_updated['attrs']['id'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:video blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_video_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Match all wp:video blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:video', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through all video blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get attachment ID from block header.
			$att_id = isset( $block_updated['attrs']['id'] ) ? $block_updated['attrs']['id'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <video> element from innerHTML.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'video', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No video element.
				continue;
			}
			$video_html         = $matches[0][0][0];
			$video_html_updated = $video_html;

			// Get video src.
			$src = $this->html_element_manipulator->get_attribute_value( 'src', $video_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update the whole video HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $video_html, $video_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $video_html, $video_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update ID in block header.
			$block_updated['attrs']['id'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:file blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_file_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Match all wp:file blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:file', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through all file blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $key_match => $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get attachment ID from block header.
			$att_id = isset( $block_updated['attrs']['id'] ) ? $block_updated['attrs']['id'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <a> elementa from innerHTML.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'a', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No <a> elements.
				continue;
			}
			$a_html         = $matches[0][0][0];
			$a_html_updated = $a_html;

			// Get a href/src.
			$src = $this->html_element_manipulator->get_attribute_value( 'href', $a_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update the whole a HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $a_html, $a_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $a_html, $a_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update ID in block header.
			$block_updated['attrs']['id'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:cover blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_cover_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Get all blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:cover', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get attachment ID from block header.
			$att_id = isset( $block_updated['attrs']['id'] ) ? $block_updated['attrs']['id'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <img> element from innerHTML.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No <img>s.
				continue;
			}
			$img_html         = $matches[0][0][0];
			$img_html_updated = $img_html;

			// Get <img> src.
			$src = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update ID in image element `class` attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

			// Update the whole img HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs in block header.
			$block_updated['attrs']['id'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:media-text blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_mediatext_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Match all wp:media-text blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:media-text', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through all media-text blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $key_match => $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get mediaID (attachment ID) from block header.
			$att_id = isset( $block_updated['attrs']['mediaId'] ) ? $block_updated['attrs']['mediaId'] : null;
			if ( ! $att_id ) {
				return $content_updated;
			}

			// Get the first <img> element from innerHTML.
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0][0][0] ) || empty( $matches[0][0][0] ) ) {
				// No <img>s.
				continue;
			}
			$img_html         = $matches[0][0][0];
			$img_html_updated = $img_html;

			// Get img src.
			$src = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

			// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
			if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
				$new_att_id = $known_attachment_ids_updates[ $att_id ];
			} else {
				$new_att_id = null;
				if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
					$src_cleaned = $this->clean_attachment_url_for_query( $src );
					$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
				}
				if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
					// Attachment ID not found.
					continue;
				}

				// Add to known Att IDs updates.
				if ( $new_att_id != $att_id ) {
					$known_attachment_ids_updates[ $att_id ] = $new_att_id;
				}
			}

			// If it's the same ID, don't update anything.
			if ( $att_id === $new_att_id ) {
				continue;
			}

			// Cast to integer type for proper JSON encoding.
			$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

			// Update ID in image element `class` attribute.
			$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

			// Update the whole img HTML element in Block HTML.
			$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
			$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update mediaId in block header.
			$block_updated['attrs']['mediaId'] = $new_att_id;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all wp:jetpack/tiled-gallery blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_jetpacktiledgallery_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Get all blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/tiled-gallery', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all <img> elements from innerHTML.
			$matches_images = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
				// No <img>s.
				continue;
			}

			// Loop through all <img> elements.
			foreach ( $matches_images[0] as $match_image ) {

				// Vars.
				$img_html         = $match_image[0];
				$img_html_updated = $img_html;

				// Get data-id and src attributes.
				$att_id = $this->html_element_manipulator->get_attribute_value( 'data-id', $img_html );
				$src    = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

				// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
				if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
					$new_att_id = $known_attachment_ids_updates[ $att_id ];
				} else {
					$new_att_id = null;
					if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
						$src_cleaned = $this->clean_attachment_url_for_query( $src );
						$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
					}
					if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
						// Attachment ID not found.
						continue;
					}

					// Add to known Att IDs updates.
					if ( $new_att_id != $att_id ) {
						$known_attachment_ids_updates[ $att_id ] = $new_att_id;
					}
				}

				// If it's the same ID, don't update anything.
				if ( $att_id === $new_att_id ) {
					continue;
				}

				// Cast to integer type for proper JSON encoding.
				$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

				// Update `data-id` attribute.
				$img_html_updated = $this->update_image_element_attribute( 'data-id', [ $att_id => $new_att_id ], $img_html_updated );

				// Update the whole img HTML element in Block HTML.
				$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update 'ids' in gallery block header.
			$block_ids         = $block_updated['attrs']['ids'];
			$block_ids_updated = $block_ids;
			foreach ( $block_ids as $key => $id ) {
				// Update ID, or leave it the same.
				$block_ids_updated[ $key ] = $known_attachment_ids_updates[ $id ] ?? $id;
			}
			$block_updated['attrs']['ids'] = $block_ids_updated;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all jetpack/slideshow blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_jetpackslideshow_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Get all blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/slideshow', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all <img> elements from innerHTML.
			$matches_images = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
				// No <img>s.
				continue;
			}

			// Loop through all <img> elements.
			foreach ( $matches_images[0] as $match_image ) {

				// Vars.
				$img_html         = $match_image[0];
				$img_html_updated = $img_html;

				// Get data-id and src attributes.
				$att_id = $this->html_element_manipulator->get_attribute_value( 'data-id', $img_html );
				$src    = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

				// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
				if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
					$new_att_id = $known_attachment_ids_updates[ $att_id ];
				} else {
					$new_att_id = null;
					if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
						$src_cleaned = $this->clean_attachment_url_for_query( $src );
						$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
					}
					if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
						// Attachment ID not found.
						continue;
					}

					// Add to known Att IDs updates.
					if ( $new_att_id != $att_id ) {
						$known_attachment_ids_updates[ $att_id ] = $new_att_id;
					}
				}

				// If it's the same ID, don't update anything.
				if ( $att_id === $new_att_id ) {
					continue;
				}

				// Cast to integer type for proper JSON encoding.
				$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

				// Update `data-id` attribute.
				$img_html_updated = $this->update_image_element_attribute( 'data-id', [ $att_id => $new_att_id ], $img_html_updated );
				// Update ID in image element `class` attribute.
				$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

				// Update the whole img HTML element in Block HTML.
				$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update 'ids' in gallery block header.
			$block_ids         = $block_updated['attrs']['ids'];
			$block_ids_updated = $block_ids;
			foreach ( $block_ids as $key => $id ) {
				// Update ID, or leave it the same.
				$block_ids_updated[ $key ] = $known_attachment_ids_updates[ $id ] ?? $id;
			}
			$block_updated['attrs']['ids'] = $block_ids_updated;

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Searches for all jetpack/image-compare blocks, and checks and if necessary updates their attachment IDs based on `src` URL.
	 *
	 * @param string $content post_content.
	 * @param array  $known_attachment_ids_updates Array with known attachment ID updates; keys are old IDs, values are new IDs.
	 * @param array  $local_hostname_aliases       An array of image hostnames to be looked up as local hostnames.
	 *
	 * @return string Updated post_content.
	 */
	public function update_jetpackimagecompare_blocks_ids( string $content, array &$known_attachment_ids_updates, array $local_hostname_aliases = [] ): string {

		// Get all blocks.
		$matches = $this->wp_block_manipulator->match_wp_block( 'wp:jetpack/image-compare', $content );
		if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through blocks and update their IDs.
		$content_updated = $content;
		foreach ( $matches[0] as $match ) {

			// Vars.
			$block_html                 = $match[0];
			$block                      = parse_blocks( $block_html )[0];
			$block_updated              = $block;
			$block_innerhtml_updated    = $block['innerHTML'];
			$block_innercontent_updated = $block['innerContent'][0];

			// Get all <img> elements from innerHTML.
			$matches_images = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $block_innerhtml_updated );
			if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
				// No <img>s.
				continue;
			}

			// Loop through all <img> elements.
			foreach ( $matches_images[0] as $match_image ) {

				// Vars.
				$img_html         = $match_image[0];
				$img_html_updated = $img_html;

				// Get id and src attributes.
				$att_id = $this->html_element_manipulator->get_attribute_value( 'id', $img_html );
				$src    = $this->html_element_manipulator->get_attribute_value( 'src', $img_html );

				// Update this attachment ID -- first check if we have this ID on the record, if not query the DB by file src.
				if ( isset( $known_attachment_ids_updates[ $att_id ] ) ) {
					$new_att_id = $known_attachment_ids_updates[ $att_id ];
				} else {
					$new_att_id = null;
					if ( $this->should_url_be_queried_as_local_attachment( $src, $local_hostname_aliases ) ) {
						$src_cleaned = $this->clean_attachment_url_for_query( $src );
						$new_att_id  = $this->attachment_url_to_postid( $src_cleaned, $local_hostname_aliases );
					}
					if ( is_null( $new_att_id ) || 0 === $new_att_id ) {
						// Attachment ID not found.
						continue;
					}

					// Add to known Att IDs updates.
					if ( $new_att_id != $att_id ) {
						$known_attachment_ids_updates[ $att_id ] = $new_att_id;
					}
				}

				// If it's the same ID, don't update anything.
				if ( $att_id === $new_att_id ) {
					continue;
				}

				// Cast to integer type for proper JSON encoding.
				$new_att_id = ( is_numeric( $new_att_id ) && (int) $new_att_id == $new_att_id ) ? (int) $new_att_id : $new_att_id;

				// Update `id` attribute.
				$img_html_updated = $this->update_image_element_attribute( 'id', [ $att_id => $new_att_id ], $img_html_updated );
				// Update ID in image element `class` attribute.
				$img_html_updated = $this->update_image_element_class_attribute( [ $att_id => $new_att_id ], $img_html_updated );

				// Update the whole img HTML element in Block HTML.
				$block_innerhtml_updated    = str_replace( $img_html, $img_html_updated, $block_innerhtml_updated );
				$block_innercontent_updated = str_replace( $img_html, $img_html_updated, $block_innercontent_updated );
			}

			// Apply innerHTML and innerContent changes to the block.
			$block_updated['innerHTML']       = $block_innerhtml_updated;
			$block_updated['innerContent'][0] = $block_innercontent_updated;

			// Update IDs in block header, or leave them the same if they haven't changed.
			$block_updated['attrs']['imageBefore']['id'] = $known_attachment_ids_updates[ $block_updated['attrs']['imageBefore']['id'] ] ?? $block_updated['attrs']['imageBefore']['id'];
			$block_updated['attrs']['imageAfter']['id']  = $known_attachment_ids_updates[ $block_updated['attrs']['imageAfter']['id'] ] ?? $block_updated['attrs']['imageAfter']['id'];

			// Update block with new content.
			$content_updated = str_replace( serialize_block( $block ), serialize_block( $block_updated ), $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Updates <img> element's attribute value. Only for attributes which use double quotes for values. Update to the new value is
	 * only done if the exact old value is found.
	 *
	 * @param string $attribute_name Name of attribute whose value is being updated.
	 * @param array  $value_update   Key is old attribute value, value is new attribute value.
	 * @param string $content        HTML content.
	 *
	 * @return string|string[]
	 */
	public function update_image_element_attribute( $attribute_name, $value_update, $content ) {

		$content_updated = $content;

		// Pattern for matching any Gutenberg block with the attribute using quotes for value.
		$pattern = '|
			(
				\<img
				[^\>]*        # zero or more characters except closing angle bracket
				' . $attribute_name . '="
			)
			(
				\d+           # attribute value
			)
			(
				"             # value closing double quote
				[^\>]*        # zero or more characters except closing angle bracket
				\>            # closing angle bracket (can be either prepended with forward slash, or without)
			)
		|xims';

		$matches = [];
		preg_match_all( $pattern, $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {

			// Loop through all ID values in $matches[2].
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = null;
				if ( isset( $value_update[ $id ] ) ) {
					$id_new = $value_update[ $id ];
				}

				// Check if this ID was updated.
				if ( ! is_null( $id_new ) ) {
					// Update just this specific block's header where this ID was matched (by $key_match).
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( '%s="%d"', $attribute_name, $id ),
						sprintf( '%s="%d"', $attribute_name, $id_new ),
						$matched_block_header
					);

					// Replace block with new ID in content.
					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates the ID in <img> element's class attribute, e.g. `class="wp-image-123"`. Update to new ID is only done if old ID is
	 * used in class attribute value.
	 *
	 * @param array  $ids_updates An array of Attachment IDs to update; keys are old IDs, values are new IDs.
	 * @param string $content     HTML content.
	 *
	 * @return string|string[]
	 */
	public function update_image_element_class_attribute( $ids_updates, $content ) {

		$content_updated = $content;

		// Pattern for matching <img> element's class value which contains the att.ID.
		$pattern_img_class_id = '|
			(
				\<img
				[^\>]*       # zero or more characters except closing angle bracket
				class="
				[^"]*        # zero or more characters except class closing double quote
				wp-image-
			)
			(
				\b(\d+)(?!\d).*?\b   # ID not followed by any other digits so that we dont replace a substring
			)
			(
				[^\>]*       # zero or more characters except closing angle bracket
				/\>          # closing angle bracket
			)
		|xims';

		$matches = [];
		preg_match_all( $pattern_img_class_id, $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {

			// Loop through all ID values in $matches[2].
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = null;
				if ( isset( $ids_updates[ $id ] ) ) {
					$id_new = $ids_updates[ $id ];
				}

				// Check if this ID was updated.
				if ( ! is_null( $id_new ) ) {
					// Update just this specific block's header where this ID was matched (by $key_match).
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( 'wp-image-%d', $id ),
						sprintf( 'wp-image-%d', $id_new ),
						$matched_block_header
					);

					// Replace block with new ID in content.
					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates attachment ID in Gutenberg blocks' headers which contain a single ID.
	 *
	 * @param string $block_designation Block name/designation.
	 * @param array  $id_update         An array; key is old ID, value is new ID.
	 * @param string $content           HTML content.
	 *
	 * @return string|string[]
	 */
	public function update_gutenberg_blocks_headers_single_id( $block_designation, $id_update, $content ) {

		$content_updated = $content;

		// Pattern for matching any Gutenberg block's "id" attribute value, uses sprintf for placeholder injection.
		$block_designation_escaped = $this->escape_regex_pattern_string( $block_designation );
		$pattern_block_id_sprintf  = '|
			(
				\<\!--       # beginning of the block element
				\s           # followed by a space
				%s           # element name/designation
				\s           # followed by a space
				{            # opening brace
				[^}]*        # zero or more characters except closing brace
				"id"\:       # id attribute
			)
			(
				\d+          # id value
			)
			(
				[^\d\>]+     # any following char except numeric and comment closing angle bracket
			)
		|xims';

		$matches = [];
		preg_match_all( sprintf( $pattern_block_id_sprintf, $block_designation_escaped ), $content, $matches );
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {

			// Loop through all ID values in $matches[2].
			foreach ( $matches[2] as $key_match => $id ) {
				$id_new = null;
				if ( isset( $id_update[ $id ] ) ) {
					$id_new = $id_update[ $id ];
				}

				// Check if this ID was updated.
				if ( ! is_null( $id_new ) ) {
					// Update just this specific block's header where this ID was matched (by $key_match).
					$matched_block_header         = $matches[0][ $key_match ];
					$matched_block_header_updated = str_replace(
						sprintf( '"id":%d', $id ),
						sprintf( '"id":%d', $id_new ),
						$matched_block_header
					);

					// Replace block with new ID in content.
					$content_updated = str_replace(
						$matched_block_header,
						$matched_block_header_updated,
						$content_updated
					);
				}
			}
		}

		return $content_updated;
	}

	/**
	 * Updates attachment ID in Gutenberg blocks' headers which contain multiple CSV IDs.
	 *
	 * @param array  $imported_attachment_ids An array of imported Attachment IDs to update; keys are old IDs, values are new IDs.
	 * @param string $content                 HTML content.
	 *
	 * @return string|string[]|null
	 */
	public function update_gutenberg_blocks_headers_multiple_ids( $imported_attachment_ids, $content ) {

		// Pattern for matching Gutenberg block's multiple CSV IDs attribute value.
		$pattern_csv_ids = '|
			(
				\<\!--       # beginning of the block element
				\s           # followed by a space
				wp\:[^\s]+   # element name/designation
				\s           # followed by a space
				{            # opening brace
				[^}]*        # zero or more characters except closing brace
				"ids"\:      # ids attribute
				\[           # opening square bracket containing CSV IDs
			)
			(
				 [\d,]+      # coma separated IDs
			)
			(
				\]           # closing square bracket containing CSV IDs
				[^\d\>]+     # any following char except numeric and comment closing angle bracket
			)
		|xims';

		// Loop through all CSV IDs matches, and prepare replacements.
		preg_match_all( $pattern_csv_ids, $content, $matches );
		$ids_csv_replacements = [];
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			// Loop through all $matches[2], which are the CSV IDs, and either update them, or leave them alone.
			foreach ( $matches[2] as $key_match => $ids_csv ) {
				$ids         = explode( ',', $ids_csv );
				$ids_updated = [];
				foreach ( $ids as $key_id => $id ) {
					if ( isset( $imported_attachment_ids[ $id ] ) ) {
						$ids_updated[ $key_id ] = $imported_attachment_ids[ $id ];
					} else {
						$ids_updated[ $key_id ] = $id;
					}
				}

				// If IDs were updated, store the "before CSV IDs" and "after CSV IDs" in $ids_csv_replacements.
				if ( $ids_updated != $ids ) {
					$ids_csv_replacements[ $key_match ] = [
						'before_csv_ids' => implode( ',', $ids ),
						'after_csv_ids'  => implode( ',', $ids_updated ),
					];
				}
			}
		}

		// Replace every CSV IDs string which was updated.
		$content_updated = $content;
		foreach ( $ids_csv_replacements as $key_match => $changes ) {
			$ids_csv_before = $changes['before_csv_ids'];
			$ids_csv_after  = $changes['after_csv_ids'];

			// Make the replacement to just this specific WP Block header where these CSV IDs were found.
			$matched_block_header         = $matches[0][ $key_match ];
			$matched_block_header_updated = str_replace(
				sprintf( '"ids":[%s]', $ids_csv_before ),
				sprintf( '"ids":[%s]', $ids_csv_after ),
				$matched_block_header
			);

			// Update the entire block in content.
			$content_updated = str_replace( $matched_block_header, $matched_block_header_updated, $content_updated );
		}

		return $content_updated;
	}

	/**
	 * Returns an empty data array.
	 *
	 * @return array $args {
	 *     And empty array with structured keys which will contain all Post and Post-related data.
	 *
	 *     @type array self::DATAKEY_POST              Contains `posts` row.
	 *     @type array self::DATAKEY_POSTMETA          Post's `postmeta` rows.
	 *     @type array self::DATAKEY_COMMENTS          Post's `comments` rows.
	 *     @type array self::DATAKEY_COMMENTMETA       Post's `commentmeta` rows.
	 *     @type array self::DATAKEY_USERS             Post's `users` rows (for the Post Author, and the Comment Users).
	 *     @type array self::DATAKEY_USERMETA          Post's `usermeta` rows.
	 *     @type array self::DATAKEY_TERMRELATIONSHIPS Post's `term_relationships` rows.
	 *     @type array self::DATAKEY_TERMTAXONOMY      Post's `term_taxonomy` rows.
	 *     @type array self::DATAKEY_TERMS             Post's `terms` rows.
	 * }
	 */
	private function get_empty_data_array() {
		return [
			self::DATAKEY_POST              => [],
			self::DATAKEY_POSTMETA          => [],
			self::DATAKEY_COMMENTS          => [],
			self::DATAKEY_COMMENTMETA       => [],
			self::DATAKEY_USERS             => [],
			self::DATAKEY_USERMETA          => [],
			self::DATAKEY_TERMRELATIONSHIPS => [],
			self::DATAKEY_TERMTAXONOMY      => [],
			self::DATAKEY_TERMS             => [],
			self::DATAKEY_TERMMETA          => [],
		];
	}

	/**
	 * Checks if a Term Taxonomy exists.
	 *
	 * @param int    $term_id  term_id.
	 * @param string $taxonomy Taxonomy.
	 *
	 * @return int|null term_taxonomy_id or null.
	 */
	public function get_existing_term_taxonomy( $term_id, $taxonomy ) {
		// phpcs:disable -- wpdb::prepare used by wrapper.
		$var = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT tt.term_taxonomy_id
			FROM {$this->wpdb->term_taxonomy} tt
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s;",
				$term_id,
				$taxonomy
			)
		);
		// phpcs:enable

		return is_numeric( $var ) ? (int) $var : $var;
	}

	/**
	 * Selects a row from the posts table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id      Post ID.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_post_row( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'posts', [ 'ID' => $post_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the postmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id      Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
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
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
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
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_usermeta_rows( $table_prefix, $user_id ) {
		return $this->select( $table_prefix . 'usermeta', [ 'user_id' => $user_id ] );
	}

	/**
	 * Selects rows from the comments table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_comment_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'comments', [ 'comment_post_ID' => $post_id ] );
	}

	/**
	 * Selects rows from the commentmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $comment_id Comment ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_commentmeta_rows( $table_prefix, $comment_id ) {
		return $this->select( $table_prefix . 'commentmeta', [ 'comment_id' => $comment_id ] );
	}

	/**
	 * Selects rows from the term_relationships table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $post_id Post ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_term_relationships_rows( $table_prefix, $post_id ) {
		return $this->select( $table_prefix . 'term_relationships', [ 'object_id' => $post_id ] );
	}

	/**
	 * Selects a row from the term_taxonomy table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_taxonomy_id term_taxonomy_id.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_term_taxonomy_row( $table_prefix, $term_taxonomy_id ) {
		return $this->select( $table_prefix . 'term_taxonomy', [ 'term_taxonomy_id' => $term_taxonomy_id ], $select_just_one_row = true );
	}

	/**
	 * Selects a row from the terms table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_id Term ID.
	 *
	 * @return array|null Associative array return from $wpdb::get_row, or null if no results.
	 */
	public function select_term_row( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'terms', [ 'term_id' => $term_id ], $select_just_one_row = true );
	}

	/**
	 * Selects rows from the termmeta table.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param int    $term_id Term ID.
	 *
	 * @return array Associative array with subarray rows from $wpdb::get_results.
	 */
	public function select_termmeta_rows( $table_prefix, $term_id ) {
		return $this->select( $table_prefix . 'termmeta', [ 'term_id' => $term_id ] );
	}

	/**
	 * Simple reusable select query with custom `where` conditions.
	 *
	 * @param string $table_name          Table name to select from.
	 * @param array  $where_conditions    Keys are columns, values are their values.
	 * @param bool   $select_just_one_row Select just one row will use wpdb::get_row. Default is false which uses wpdb::get_results.
	 *
	 * @return array|null wpdb results in associative array form. If $select_just_one_row is used, the result is an array or null.
	 *                    Otherwise, the result is an array with subarray rows, or an empty array.
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
			$sql_sprintf   = $sql . $where_sprintf;

			// phpcs:ignore -- $sql_sprintf is escaped.
			$sql = $this->wpdb->prepare( $sql_sprintf, array_values( $where_conditions ) );
		}

		if ( true === $select_just_one_row ) {
			// phpcs:ignore -- $wpdb::prepare was used above.
			return $this->wpdb->get_row( $sql, ARRAY_A );
		} else {
			// phpcs:ignore -- $wpdb::prepare was used above.
			return $this->wpdb->get_results( $sql, ARRAY_A );
		}
	}

	/**
	 * Inserts Post.
	 *
	 * @param array $post_row `post` row.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted Post ID.
	 */
	public function insert_post( $post_row ) {
		$insert_post_row = $post_row;
		$orig_id         = $insert_post_row['ID'];
		unset( $insert_post_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->posts, $insert_post_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting post, ID %d, post row %s', $orig_id, json_encode( $post_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a post_meta record.
	 *
	 * @param array $postmeta_row ARRAY_A formatted wp_postmeta row with values to be inserted.
	 * @param int   $post_id      Post ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_postmeta_row( $postmeta_row, $post_id ) {
		$insert_postmeta_row = $postmeta_row;
		unset( $insert_postmeta_row['meta_id'] );
		$insert_postmeta_row['post_id'] = $post_id;

		$inserted = $this->wpdb->insert( $this->wpdb->postmeta, $insert_postmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error in insert_postmeta_row, post_id %s, postmeta_row %s', $post_id, json_encode( $postmeta_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts a User.
	 *
	 * @param array $user_row `user` row.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted User ID.
	 */
	public function insert_user( $user_row ) {
		$insert_user_row = $user_row;
		unset( $insert_user_row['ID'] );

		$inserted = $this->wpdb->insert( $this->wpdb->users, $insert_user_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user, ID %d, user_row %s', $user_row['ID'], json_encode( $user_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts User Meta.
	 *
	 * @param array $usermeta_row `usermeta` row.
	 * @param int   $user_id       User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted umeta_id.
	 */
	public function insert_usermeta_row( $usermeta_row, $user_id ) {
		$insert_usermeta_row = $usermeta_row;
		unset( $insert_usermeta_row['umeta_id'] );
		$insert_usermeta_row['user_id'] = $user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->usermeta, $insert_usermeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting user meta, user_id %d, $usermeta_row %s', $user_id, json_encode( $usermeta_row ) ) );
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
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted comment_id.
	 */
	public function insert_comment( $comment_row, $new_post_id, $new_user_id ) {
		$insert_comment_row = $comment_row;
		unset( $insert_comment_row['comment_ID'] );
		$insert_comment_row['comment_post_ID'] = $new_post_id;
		$insert_comment_row['user_id']         = $new_user_id;

		$inserted = $this->wpdb->insert( $this->wpdb->comments, $insert_comment_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment, $new_post_id %d, $new_user_id %d, $comment_row %s', $new_post_id, $new_user_id, json_encode( $comment_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts Comment Metas with an updated comment_id.
	 *
	 * @param array $commentmeta_row Comment Meta rows.
	 * @param int   $new_comment_id  New Comment ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_commentmeta_row( $commentmeta_row, $new_comment_id ) {
		$insert_commentmeta_row = $commentmeta_row;
		unset( $insert_commentmeta_row['meta_id'] );
		$insert_commentmeta_row['comment_id'] = $new_comment_id;

		$inserted = $this->wpdb->insert( $this->wpdb->commentmeta, $insert_commentmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting comment meta, $new_comment_id %d, $commentmeta_row %s', $new_comment_id, json_encode( $commentmeta_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Comment's parent ID.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @param int $comment_id         Comment ID.
	 * @param int $comment_parent_new new Comment Parent ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_comment_parent( $comment_id, $comment_parent_new ) {
		$updated = $this->wpdb->update( $this->wpdb->comments, [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating comment parent, $comment_id %d, $comment_parent_new %d', $comment_id, $comment_parent_new ) );
		}

		return $updated;
	}

	/**
	 * Inserts into `terms` table.
	 *
	 * @param array $term_row `term` row.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted term_id.
	 */
	public function insert_term( $term_row ) {
		$insert_term_row = $term_row;
		if ( isset( $insert_term_row['term_id'] ) ) {
			unset( $insert_term_row['term_id'] );
		}

		$inserted = $this->wpdb->insert( $this->wpdb->terms, $insert_term_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term, $term_row %s', json_encode( $term_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Wrapper of WP's native \wp_insert_term. @see \wp_insert_term.
	 *
	 * @param string       $term_name The term name to add.
	 * @param string       $taxonomy  The taxonomy to which to add the term.
	 * @param array|string $args {
	 *     Optional. Array or query string of arguments for inserting a term.
	 *
	 *     @type string $alias_of    Slug of the term to make this term an alias of.
	 *                               Default empty string. Accepts a term slug.
	 *     @type string $description The term description. Default empty string.
	 *     @type int    $parent      The id of the parent term. Default 0.
	 *     @type string $slug        The term slug to use. Default empty string.
	 * }
	 *
	 * @return array|WP_Error {
	 *     An array of the new term data, WP_Error otherwise.
	 *
	 *     @type int        $term_id          The new term ID.
	 *     @type int|string $term_taxonomy_id The new term taxonomy ID. Can be a numeric string.
	 * }
	 */
	public function wp_insert_term( $term_name, $taxonomy, $args = [] ) {
		return \wp_insert_term( $term_name, $taxonomy, $args );
	}

	/**
	 * Inserts Term Meta.
	 *
	 * @param array $termmeta_row `usermeta` row.
	 * @param int   $term_id      User ID.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted meta_id.
	 */
	public function insert_termmeta_row( $termmeta_row, $term_id ) {
		$insert_termmeta_row = $termmeta_row;
		unset( $insert_termmeta_row['meta_id'] );
		$insert_termmeta_row['term_id'] = $term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->termmeta, $insert_termmeta_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term meta, $term_id %d, $termmeta_row %s', $term_id, json_encode( $termmeta_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_taxonomy` table.
	 *
	 * @param array $term_taxonomy_row `term_taxonomy` row.
	 * @param int   $new_term_id       New `term_id` value to be set.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted term_taxonomy_id.
	 */
	public function insert_term_taxonomy( $term_taxonomy_row, $new_term_id ) {
		$insert_term_taxonomy_row = $term_taxonomy_row;
		if ( isset( $insert_term_taxonomy_row['term_taxonomy_id'] ) ) {
			unset( $insert_term_taxonomy_row['term_taxonomy_id'] );
		}
		$insert_term_taxonomy_row['term_id'] = $new_term_id;

		$inserted = $this->wpdb->insert( $this->wpdb->term_taxonomy, $insert_term_taxonomy_row );
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term_taxonomy, $new_term_id %d, term_taxonomy_id %s', $new_term_id, json_encode( $term_taxonomy_row ) ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Inserts into `term_relationships` table.
	 *
	 * @param int $object_id        `object_id` column.
	 * @param int $term_taxonomy_id `term_taxonomy_id` column.
	 *
	 * @throws \RuntimeException In case insert fails.
	 *
	 * @return int Inserted object_id.
	 */
	public function insert_term_relationship( $object_id, $term_taxonomy_id ) {
		$inserted = $this->wpdb->insert(
			$this->wpdb->term_relationships,
			[
				'object_id'        => $object_id,
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);
		if ( 1 != $inserted ) {
			throw new \RuntimeException( sprintf( 'Error inserting term relationship, $object_id %d, $term_taxonomy_id %d', $object_id, $term_taxonomy_id ) );
		}

		return $this->wpdb->insert_id;
	}

	/**
	 * Updates a Post's Author.
	 *
	 * @throws \RuntimeException In case update fails.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $new_author_id New Author ID.
	 *
	 * @return int|false Return from $wpdb::update -- the number of rows updated, or false on error.
	 */
	public function update_post_author( $post_id, $new_author_id ) {
		$updated = $this->wpdb->update( $this->wpdb->posts, [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ] );
		if ( 1 != $updated ) {
			throw new \RuntimeException( sprintf( 'Error updating post author, $post_id %d, $new_author_id %d', $post_id, $new_author_id ) );
		}

		return $updated;
	}

	/**
	 * Gets a list of all the tables in the active DB.
	 *
	 * @return array List of all tables in DB.
	 */
	public function get_all_db_tables() {
		$all_tables        = [];
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
	 * @param array  $skip_tables  Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case not all live DB core WP tables are found.
	 */
	public function validate_core_wp_db_tables_exist_in_db( $table_prefix, $skip_tables = [] ) {
		$all_tables = $this->get_all_db_tables();
		foreach ( self::CORE_WP_TABLES as $table ) {
			if ( in_array( $table, $skip_tables ) ) {
				continue;
			}
			$tablename = $table_prefix . $table;
			if ( ! in_array( $tablename, $all_tables ) ) {
				throw new \RuntimeException( sprintf( 'Core WP DB table %s not found.', $tablename ) );
			}
		}
	}

	/**
	 * This function will compare Core WP Tables against the Live WP tables
	 * brought in for a content migration/refresh. This will be
	 * useful for determining whether a collation
	 * migration is necessary.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function get_collation_comparison_of_live_and_core_wp_tables( string $table_prefix, array $skip_tables = [] ): array {
		$validated_tables = [];

		$core_tables = array_diff( self::CORE_WP_TABLES, $skip_tables );
		foreach ( $core_tables as $table ) {
			$core_table = esc_sql( $this->wpdb->prefix . $table );
			$live_table = esc_sql( $table_prefix . $table );

			// phpcs:ignore -- query fully sanitized.
			$core_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$core_table'" );
			// phpcs:ignore -- query fully sanitized.
			$live_table_status = $this->wpdb->get_row( "SHOW TABLE STATUS WHERE name LIKE '$live_table'" );

			if ( is_null( $live_table_status ) ) {
				WP_CLI::warning( "Live table `$live_table` does not exist, skipping table." );
				continue;
			}

			// phpcs:ignore -- ignore CamelCase param.
			$match_test = $live_table_status->Collation === $core_table_status->Collation;

			$validated_tables[] = [
				'table'                => $table,
				'core_table_name'      => $core_table,
				// phpcs:ignore -- ignore CamelCase param.
				'core_table_collation' => $core_table_status->Collation,
				'live_table_name'      => $live_table,
				// phpcs:ignore -- ignore CamelCase param.
				'live_table_collation' => $live_table_status->Collation,
				'match'                => $match_test ? 'YES' : 'NO',
				'match_bool'           => $match_test,
			];
		}

		if ( empty( $validated_tables ) ) {
			throw new \RuntimeException( 'Unable to validate collation on content diff tables. Please verify live table prefix.' );
		}

		return $validated_tables;
	}

	/**
	 * Convenience function that only returns tables which have a different collation
	 * than the Core WP DB tables.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return array
	 */
	public function filter_for_different_collated_tables( string $table_prefix, array $skip_tables = [] ): array {
		$collation_comparison = $this->get_collation_comparison_of_live_and_core_wp_tables( $table_prefix, $skip_tables );
		return array_filter(
			$collation_comparison,
			fn( $validated_table ) => false === $validated_table['match_bool']
		);
	}

	/**
	 * Convenience function which returns a simple boolean value indicating whether all Live
	 * DB tables have matching collations with their corresponding Core WP DB tables.
	 *
	 * @param string $table_prefix Table prefix.
	 * @param array  $skip_tables Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException Throws exception if unable to find live tables with given prefix.
	 * @return bool
	 */
	public function are_table_collations_matching( string $table_prefix, array $skip_tables = [] ): bool {
		return empty( $this->filter_for_different_collated_tables( $table_prefix, $skip_tables ) );
	}

	/**
	 * This function will handle the operation to move data from the
	 * incompatibly collated table to the new compatible table.
	 *
	 * @param string $prefix Live table prefix.
	 * @param string $table The Core WP Table to address.
	 * @param int    $records_per_transaction The amount of records to process per transaction.
	 * @param int    $sleep_in_seconds Delay in seconds between each DB transaction.
	 * @param string $prefix_for_backup Custom prefix for table to be backed up to.
	 *
	 * @throws \RuntimeException Throws various exceptions if unable to complete required SQL operations.
	 */
	public function copy_table_data_using_proper_collation( string $prefix, string $table, int $records_per_transaction = 5000, int $sleep_in_seconds = 1, string $prefix_for_backup = 'bak_' ) {
		$backup_table              = esc_sql( $prefix_for_backup . $prefix . $table );
		$source_table              = esc_sql( $prefix . $table );
		$match_collation_for_table = esc_sql( $this->wpdb->prefix . $table );

		$rename_sql = "RENAME TABLE $source_table TO $backup_table";
		// phpcs:ignore -- query fully sanitized.
		$rename_result             = $this->wpdb->query( $rename_sql );

		if ( is_wp_error( $rename_result ) ) {
			throw new \RuntimeException( "Unable to rename table: '$rename_sql'\n" . $rename_result->get_error_message() );
		}

		$create_like_table_sql = "CREATE TABLE {$source_table} LIKE $match_collation_for_table";
		// phpcs:ignore -- query fully sanitized.
		$create_result         = $this->wpdb->query( $create_like_table_sql );

		if ( is_wp_error( $create_result ) ) {
			throw new \RuntimeException( "Unable to create table: '$create_like_table_sql'\n" . $create_result->get_error_message() );
		}

		$limiter = [
			'start' => 0,
			'limit' => $records_per_transaction,
		];

		$table_columns_sql = "SHOW COLUMNS FROM $source_table";
		// phpcs:ignore -- query fully sanitized.
		$table_columns_results = $this->wpdb->get_results( $table_columns_sql );
		$table_columns         = implode( ',', array_map( fn( $column_row) => "`$column_row->Field`", $table_columns_results ) );
		// phpcs:ignore -- query fully sanitized.
		$count                 = $this->wpdb->get_row( "SELECT COUNT(*) as counter FROM $backup_table;" );

		if ( 0 === $count ) {
			throw new \RuntimeException( "Table '$backup_table' has 0 rows. No need to continue." );
		}

		$iterations = ceil( $count->counter / $limiter['limit'] );
		for ( $i = 1; $i <= $iterations; $i++ ) {
			$insert_sql = "INSERT INTO `{$source_table}`({$table_columns}) SELECT {$table_columns} FROM {$backup_table} LIMIT {$limiter['start']}, {$limiter['limit']}";
			// phpcs:ignore -- query fully sanitized.
			$insert_result = $this->wpdb->query( $insert_sql );

			if ( ! is_wp_error( $insert_result ) && ( false !== $insert_result ) && ( 0 !== $insert_result ) ) {
				$limiter['start'] = $limiter['start'] + $limiter['limit'];
			} else {
				$db_error = ( '' != $this->wpdb->last_error ) ? 'DB error message: ' . $this->wpdb->last_error : 'No DB error message available -- check error and debug logs.';
				WP_CLI::error( sprintf( "Got up to (not including) %s. Failed running SQL '%s'. %s", $limiter['start'], $insert_sql, $db_error ) );
			}

			if ( $sleep_in_seconds ) {
				sleep( $sleep_in_seconds );
			}
		}
	}

	/**
	 * Wrapper for WP's native \get_user_by(), for easier testing.
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
	 * Finds for current post ID by old live DB ID, by searching for the self::SAVED_META_LIVE_POST_ID post meta.
	 *
	 * @param int|string $id_live  Post ID from live DB.
	 * @param string     $meta_key Name of postmeta which contains old post ID.
	 *
	 * @return string|null Current Post ID.
	 */
	public function get_current_post_id_by_custom_meta( $id_live, $meta_key ) {

		// phpcs:disable -- wpdb::prepare is used correctly.
		$post_id_new = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT post_id
			FROM {$this->wpdb->postmeta}
			WHERE meta_key = %s
			AND meta_value = %s",
				$meta_key,
				$id_live
			)
		);
		// phpcs:enable

		return $post_id_new;
	}

	/**
	 * Finds a local post ID by live DB's ID, by comparing the live DB post record to the local DB.
	 *
	 * @param int    $id_live           Old live site's post object ID.
	 * @param string $live_table_prefix Live DB tables' prefix.
	 *
	 * @return int|null Current Post ID.
	 */
	public function get_current_post_id_by_comparing_with_live_db( $id_live, $live_table_prefix ) {

		$live_posts_table = $live_table_prefix . 'posts';
		$posts_table      = $this->wpdb->posts;

		// phpcs:disable -- wpdb::prepare is used correctly.
		$post_id_new = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT wp.ID
			FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
				AND wp.post_type = lwp.post_type
			WHERE lwp.ID = %d ;",
				$id_live
			)
		);
		// phpcs:enable

		return $post_id_new;
	}

	/**
	 * Wrapper for WP's native \get_post(), for easier testing.
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
	 * Cleans up the attachment file URL by just keeping scheme, host and path.
	 *
	 * @param string $url Attachment file URL.
	 *
	 * @return string Cleaned URL.
	 */
	public function clean_attachment_url_for_query( $url ) {
		$parsed_url = parse_url( $url );

		$url_cleaned = sprintf(
			'%s://%s%s',
			$parsed_url['scheme'],
			$parsed_url['host'],
			$parsed_url['path'],
		);

		return $url_cleaned;
	}

	/**
	 * Checks if this $url should be queried as local attachment -- does it have the same hostname as 'siteurl', or is the hostname
	 * one of $local_hostname_aliases.
	 *
	 * @param string $url                    Attachment file URL.
	 * @param array  $local_hostname_aliases Array of hostnames to use as local hostname aliases.
	 *
	 * @return bool Should this URL be queried as local attachment.
	 */
	public function should_url_be_queried_as_local_attachment( $url, $local_hostname_aliases ) {
		$url_parsed = parse_url( $url );
		$url_host   = $url_parsed['host'];

		$siteurl        = get_option( 'siteurl' );
		$siteurl_parsed = wp_parse_url( $siteurl );
		$siteurl_host   = $siteurl_parsed['host'];

		return $siteurl_host == $url_host || in_array( $url_host, $local_hostname_aliases );
	}

	/**
	 * Wrapper for WP's native \attachment_url_to_postid(), for easier testing.
	 *
	 * @param string $url                    The URL to resolve.
	 * @param array  $local_hostname_aliases Array of hostnames to use as local hostname aliases.
	 *
	 * @return int The found post ID, or 0 on failure.
	 */
	public function attachment_url_to_postid( $url, $local_hostname_aliases = [] ) {

		// If $url hostname has one of the given aliases, substitute its hostname with the local hostname.
		if ( ! empty( $local_hostname_aliases ) ) {
			$parsed_url = wp_parse_url( $url );
			if ( in_array( $parsed_url['host'], $local_hostname_aliases ) ) {
				$siteurl        = get_option( 'siteurl' );
				$siteurl_parsed = wp_parse_url( $siteurl );

				$url = str_replace( '//' . $parsed_url['host'], '//' . $siteurl_parsed['host'], $url );
			}
		}

		// phpcs:ignore
		$post_id = attachment_url_to_postid( $url );

		return $post_id;
	}

	/**
	 * Filters a multidimensional array and searches for a subarray with a key and value.
	 *
	 * @param array $array Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return null|array The array which matches the $key $value filter, or null.
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
	 * @param array $array Array being searched and filtered.
	 * @param mixed $key   Array key to search for.
	 * @param mixed $value Array value to search for.
	 *
	 * @return array An array with sub-arrays which match the $key $value filter, or an empty array if nothing is found.
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

	/**
	 * A simple progress meter which updates percentage progress of a counter in terms of a given percentage number increment. You
	 * get to tell it the percentage increment, for example, update the status progress by every "5%" change, then it
	 * updates the $current_percent at 0%, 5%, 10%, 15%, 20%, ..., 100%.
	 *
	 * @param int $total_count       Total number of steps.
	 * @param int $current_count     Current step, starting from 1.
	 * @param int $percent_increment The percentage increment by which the progress update should be done.
	 * @param int $current_percent   Current percentage progress.
	 *
	 * @return void
	 */
	public function get_progress_percentage( $total_count, $current_count, $percent_increment, &$current_percent = null ) {

		// Initialize 0%.
		if ( is_null( $current_percent ) ) {
			$current_percent = 0;
		}

		// Get what the next regular increase in percentage will be.
		$next_percent_increase = $current_percent + $percent_increment;
		$next_percent_increase = $next_percent_increase >= 100 ? 100 : $next_percent_increase;

		// Get actual precentage at this count.
		$current_percent_actual = $current_count * 100 / $total_count;

		// First check if $current_count ($current_percent_actual) has already exceeded the regular $next_percent_increase.
		if ( $current_percent_actual > $next_percent_increase ) {
			// Speed up to $current_percent_actual.
			while ( ( $current_percent + $percent_increment ) <= $current_percent_actual ) {
				$current_percent += $percent_increment;
			}
		} else {
			// Get which "current count" number will make the percentage increase to the $next_percent_increase amount.
			$required_current_count_for_increase = $next_percent_increase * $total_count / 100;

			// Increase percentage if reached.
			if ( $current_count >= $required_current_count_for_increase ) {
				$current_percent = $next_percent_increase;
			}
		}
	}

	/**
	 * Escapes special characters in string to be used in PHP regex patterns/expressions.
	 *
	 * @param string $subject Subject.
	 *
	 * @return string
	 */
	private function escape_regex_pattern_string( string $subject ): string {
		$special_chars   = [ '.', '\\', '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':' ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\' . $special_char, $subject_escaped );
		}

		// Space.
		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}

	/**
	 * Logs error message to file.
	 *
	 * @param string $file Path to log file.
	 * @param string $msg  Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND );
	}
}
