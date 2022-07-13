<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use Simple_Local_Avatars;
use stdClass;
use \WP_CLI;

class BerkeleysideMigrator implements InterfaceMigrator {

	/**
	 * BerkeleysideMigrator Instance.
	 *
	 * @var BerkeleysideMigrator
	 */
	private static $instance;

	/**
	 * CoAuthorsPlus Helper Class.
	 *
	 * @var CoAuthorPlusMigrator.
	 */
	private $cap_logic;

	/**
	 * Template mapping, old postmeta value => new postmeta value.
	 *
	 * @var int[] $template_mapping
	 */
	protected array $template_mapping = [
		'page-templates/post_template-single-photo-lead.php' => 'single-wide.php',
		'page-templates/post_template-single-wide.php'  => 'single-wide.php',
		'page-templates/post_template-single-short.php' => 'default',
	];

	/**
	 * Media Credit Mapping for postmeta.
	 *
	 * @var array|string[] $media_credit_mapping
	 */
	protected array $media_credit_mapping = [
		'photo_credit_name'  => 'media_credit',
		'photo_credit_url'   => 'media_credit_url',
		'photo_organization' => 'navis_media_credit_or',
	];

	/**
	 * Custom mapping for custom postmeta types => tags.
	 *
	 * @var array|string[] $postmeta_to_tag_mapping
	 */
	protected array $postmeta_to_tag_mapping = [
		'lead_story_front_page_article' => 'Home: Lead',
		'lead_story_front_page_photo'   => 'Home: Lead Photo',
		'breaking_story'                => 'Home: Breaking',
		'highlight_story'               => 'Home: Highlight',
		'timeline_story'                => 'Home: Timeline',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cap_logic = new CoAuthorPlusLogic();
	}

	/**
	 * Get Instance.
	 *
	 * @return BerkeleysideMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}


	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-import-postmeta-to-postcontent',
			[ $this, 'cmd_import_postmeta_to_postcontent' ],
			[
				'shortdesc' => 'Takes ACF content in wp_postmeta and transfers it to wp_posts.post_content',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-convert-templates-to-newspack',
			[ $this, 'cmd_convert_templates_to_newspack' ],
			[
				'shortdesc' => 'Looks at a list of previously used templates and updates them to conform to Newspack standard',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-update-acf-media-credit',
			[ $this, 'cmd_update_acf_media_credit' ],
			[
				'shortdesc' => 'Updates a list of postmeta keys to new keys',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-display-updated-date-correctly',
			[ $this, 'cmd_display_updated_date_correctly' ],
			[
				'shortdesc' => 'Looks for Berkeleyside metadata to display updated date correctly on staging site.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-update-related-posts-block',
			[ $this, 'cmd_update_related_posts_block' ],
			[
				'shortdesc' => 'Looks at postmeta for related post information and attempts to recreate it using a post block',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-migrate-user-avatars',
			[ $this, 'cmd_migrate_user_avatars' ],
			[
				'shortdesc' => 'Migrating data from User Profile Picture to Simple Local Avatars',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-replace-postmeta-with-tags',
			[ $this, 'cmd_replace_postmeta_with_tags' ],
			[
				'shortdesc' => 'Takes a list of custom postmeta types (article_type) and converts them to tags. Then associates posts with those tags.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-acf-authors-to-cap',
			[ $this, 'cmd_acf_authors_to_cap' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-add-user-title-to-yoast',
			[ $this, 'cmd_add_user_title_to_yoast' ],
		);
	}

	public function cmd_acf_authors_to_cap( $args, $assoc_args ) {
		if ( ! $this->cap_logic->is_coauthors_active() ) {
			WP_CLI::error( 'CAP plugin needs to be active to run this command.' );
		}

		global $wpdb;

		$posts_with_opinion_category = $wpdb->get_results(
			"SELECT 
       				object_id as post_id 
			FROM $wpdb->term_relationships 
			WHERE term_taxonomy_id = (
    			SELECT 
    			       tt.term_taxonomy_id 
    			FROM $wpdb->term_taxonomy tt 
    			    INNER JOIN $wpdb->terms t ON tt.term_id = t.term_id 
    			WHERE t.slug = 'opinion' 
    			  AND tt.taxonomy = 'category'
			)"
		);

		$count_of_posts = count( $posts_with_opinion_category );
		foreach ( $posts_with_opinion_category as $key_post_id => $row ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, $count_of_posts, $row->post_id ) );

			$post                        = get_post( $row->post_id );
			$meta_opinionator_author     = get_post_meta( $row->post_id, 'opinionator_author', true );
			$meta_opinionator_author_bio = get_post_meta( $row->post_id, 'opinionator_author_bio', true );

			// If no GA user to create by name, skip.
			if ( empty( $meta_opinionator_author ) ) {
				$this->log( 'berkeleyside__meta_empty_authorname.log', sprintf( '%d %s %s', $row->post_id, $meta_opinionator_author, $meta_opinionator_author_bio ), false );
				WP_CLI::log( '  x skipped, empty author name' );
				continue;
			}

			// If no bio, WP User Author should be enough as it is.
			if ( empty( $meta_opinionator_author_bio ) ) {
				$this->log( 'berkeleyside__meta_empty_authorbio.log', sprintf( '%d %s %s', $row->post_id, $meta_opinionator_author, $meta_opinionator_author_bio ), false );
				WP_CLI::log( '  x skipped, empty author bio' );
				continue;
			}

			WP_CLI::log( "  Author: $meta_opinionator_author" );

			$exploded = explode( ',', $meta_opinionator_author );

			$leaders = [
				'and ',
				'by ',
			];

			$full_names_to_process = [];
			while ( $particle = array_shift( $exploded ) ) {
				$particle = trim( $particle );

				$obliterated = explode( ' and ', $particle );
				if ( count( $obliterated ) > 1 ) {
					$particle = array_shift( $obliterated );
					$exploded = array_merge( $exploded, $obliterated );
				}

				foreach ( $leaders as $leader ) {
					if ( str_starts_with( $particle, $leader ) ) {
						$particle = substr( $particle, strlen( $leader ) );
						$particle = trim( $particle );
					}

					if ( str_ends_with( $particle, '.' ) ) {
						$particle = substr( $particle, -1 );
					}
				}

				$particle = preg_replace( '/\s\s+/', ' ', $particle );

				if ( ! empty( $particle ) ) {
					$full_names_to_process[] = $particle;
				}
			}

			foreach ( $full_names_to_process as $key => $full_name ) {
				WP_CLI::log( "    full_name: $full_name" );
				$exploded_name = explode( ' ', $full_name );
				$last_name     = array_pop( $exploded_name );
				$first_name    = implode( ' ', $exploded_name );

				if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
					$user = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT 
	                            sub.user_id
							FROM (
				                  SELECT 
				                        GROUP_CONCAT(DISTINCT user_id) as user_id, 
				                         COUNT(umeta_id) as counter
				                  FROM $wpdb->usermeta
				                  WHERE (meta_key = 'first_name' AND meta_value = '%s')
				                     OR (meta_key = 'last_name' AND meta_value = '%s')
				                  GROUP BY user_id
				                  HAVING counter = 2
						) as sub WHERE LOCATE( sub.user_id, ',' ) = 0",
							$first_name,
							$last_name
						)
					);

					if ( ! is_null( $user ) ) {
						WP_CLI::log( '      USER EXISTS!' );
						$user_description = $wpdb->get_row(
							"SELECT 
       						umeta_id, 
       						meta_value 
						FROM $wpdb->usermeta 
						WHERE user_id = $user->user_id 
						  AND meta_key = 'description' 
						  AND meta_value = ''"
						);

						if ( is_null( $user_description ) ) {
							$wpdb->insert(
								$wpdb->usermeta,
								[
									'user_id'    => $user->user_id,
									'meta_key'   => 'description',
									'meta_value' => $meta_opinionator_author_bio
								]
							);
						} else {
							$wpdb->update(
								$wpdb->usermeta,
								[
									'meta_value' => $meta_opinionator_author_bio,
								],
								[
									'umeta_id' => $user_description->umeta_id,
								]
							);
						}
					} else {
						WP_CLI::log( '      CREATING GUEST AUTHOR.' );
						// Get/Create GA.
						$ga_id = $this->cap_logic->create_guest_author(
							[
								'display_name' => $full_name,
								'description'  => $meta_opinionator_author_bio,
								'first_name'   => $first_name,
								'last_name'    => $last_name,
							]
						);

						// Assign GA.
						$append = 0 !== $key;
						$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID, $append );
					}
				}
			}

			/*// Get WP Author user.
			$user = get_user_by( 'id', $post->post_author );

			$guest_author_data = [
				'display_name' => $meta_opinionator_author,
				'description'  => $meta_opinionator_author_bio,
			];


			$user_avatar = get_user_meta( $user->ID, 'simple_local_avatar', true );

			if ( ! empty( $user_avatar ) ) {
				$guest_author_data['avatar'] = $user_avatar['media_id'];
			}

			// Get/Create GA.
			$ga_id = $this->cap_logic->create_guest_author( $guest_author_data );

			// Link WP User and GA.
			$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $user );

			// Assign GA.
			$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID );*/
		}

		WP_CLI::log( 'Done.' );
	}

	public function cmd_import_postmeta_to_postcontent( $args, $assoc_args ) {
		global $wpdb;
		$target_slug = 'news-wire';

		$target_category_term_taxonomy_id = $wpdb->get_row(
			"SELECT 
       			tt.term_taxonomy_id 
			FROM $wpdb->term_taxonomy tt 
			    LEFT JOIN $wpdb->terms t ON tt.term_id = t.term_id
			WHERE t.slug = '$target_slug'"
		);
		$target_category_term_taxonomy_id = $target_category_term_taxonomy_id->term_taxonomy_id;

		$posts_associated_with_category_sql = "SELECT 
       		p.ID, 
       		p.post_content, 
       		GROUP_CONCAT(CONCAT(pm.meta_key, ':', pm.meta_value) ORDER BY pm.meta_key SEPARATOR '|') as meta_fields
		FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta pm on p.ID = pm.post_id
		WHERE p.ID IN (
		    SELECT 
		           tr.object_id 
		    FROM $wpdb->term_relationships tr 
		    WHERE tr.term_taxonomy_id = $target_category_term_taxonomy_id
		    )
		AND pm.meta_key LIKE 'wire_stories_%'
		OR pm.meta_key = '_wp_page_template'
		AND p.post_content NOT LIKE '%wire-stories-list%'
		GROUP BY p.ID";

		$posts_associated_with_category = $wpdb->get_results( $posts_associated_with_category_sql );

		$html_template      = '<em>Heads up: We sometimes link to sites that limit access for non-subscribers.</em><ul class="wire-stories-list">{list_items}</ul>';
		$list_item_template = '<li><a href="{url}" target="_blank">{description}</a> {source}</li>';
		foreach ( $posts_associated_with_category as $post ) {
			$links       = [];
			$meta_fields = explode( '|', $post->meta_fields );

			$had_short_template = array_filter( $meta_fields, fn( $field ) => '_wp_page_template:default' == $field );
			if ( ! empty( $post->post_content ) || ! empty( $had_short_template ) ) {
				continue;
			}

			foreach ( $meta_fields as $meta_field ) {
				$exploded_meta_fields = explode( ':', $meta_field );
				$key                  = $exploded_meta_fields[0];
				$value                = $exploded_meta_fields[1] ?? null;
				$array_key            = substr( $key, 0, strlen( 'wire_stories_' ) + 2 );

				$attributes = [];

				if ( str_ends_with( $key, 'story_link' ) ) {
					$attributes['{url}'] = $value;
				} elseif ( str_ends_with( $key, 'story_source' ) ) {
					$attributes['{source}'] = "($value)";
				} elseif ( str_ends_with( $key, 'story_title' ) ) {
					$attributes['{description}'] = $value;
				}

				if ( array_key_exists( $array_key, $links ) ) {
					$links[ $array_key ] = strtr( $links[ $array_key ], $attributes );
				} else {
					$links[ $array_key ] = strtr( $list_item_template, $attributes );
				}
			}

			$list_items = implode( "\n", array_filter( $links, fn( $link ) => ! str_contains( $link, '{description}' ) ) );

			$html = strtr( $html_template, [ '{list_items}' => $list_items ] );

			$post_content = $html;
			if ( ! empty( $post->post_content ) ) {
				$post_content = "$post->post_content<br>$html";
			}

			WP_CLI::line( 'Updating Post ID:' . $post->ID );
			$wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $post_content,
				],
				[
					'ID' => $post->ID,
				]
			);

			$row = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = 'newspack_featured_image_position' AND post_id = $post->ID" ) ;

			if ( is_null( $row ) ) {
				$wpdb->insert(
					$wpdb->postmeta,
					[
						'meta_key' => 'newspack_featured_image_position',
						'meta_value' => 'large',
						'post_id' => $post->ID,
					]
				);
			} else {
				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_value' => 'large',
					],
					[
						'meta_id' => $row->meta_id,
					]
				);
			}
		}
	}

	public function cmd_convert_templates_to_newspack( $args, $assoc_args ) {
		global $wpdb;

		foreach ( $this->template_mapping as $old_template => $new_template ) {
			WP_CLI::line( $old_template );

			$posts_with_old_template = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT 
	                            ID, 
	                            post_title, 
	                            post_date, 
	                            post_status, 
	                            post_type 
							FROM wp_live_posts 
							WHERE ID IN (
	                            SELECT 
	                                   post_id 
	                            FROM wp_live_postmeta 
	                            WHERE meta_key = %s 
	                              AND meta_value = %s
	                        )',
					'_wp_page_template',
					$old_template
				)
			);

			foreach ( $posts_with_old_template as $post ) {
				$corresponding_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT 
	                            ID 
							FROM $wpdb->posts 
							WHERE post_title = %s
							  AND post_date = %s
							  AND post_status = %s
							  AND post_type = %s",
						$post->post_title,
						$post->post_date,
						$post->post_status,
						$post->post_type
					)
				);

				if ( ! is_null( $corresponding_post ) ) {
					WP_CLI::line( "$post->ID => $corresponding_post->ID" );

					$row = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_wp_page_template' AND post_id = $corresponding_post->ID" );

					if ( is_null( $row ) ) {
						$wpdb->insert(
							$wpdb->postmeta,
							[
								'meta_key' => '_wp_page_template',
								'meta_value' => $new_template,
								'post_id' => $corresponding_post->ID,
							]
						);
					} else {
						$wpdb->update(
							$wpdb->postmeta,
							[
								'meta_value' => $new_template,
							],
							[
								'meta_id' => $row->meta_id,
							]
						);
					}

					$row2 = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = 'newspack_featured_image_position' AND post_id = $corresponding_post->ID" );

					if ( is_null( $row2 ) ) {
						$wpdb->insert(
							$wpdb->postmeta,
							[
								'meta_key' => 'newspack_featured_image_position',
								'meta_value' => 'above',
								'post_id' => $corresponding_post->ID,
							]
						);
					} else {
						$wpdb->update(
							$wpdb->postmeta,
							[
								'meta_value' => 'above',
							],
							[
								'meta_id' => $row2->meta_id,
							]
						);
					}
				} else {
					WP_CLI::line( "$posts_with_old_template->ID X" );
				}
			}
		}
	}

	public function cmd_update_acf_media_credit( $args, $assoc_args ) {
		global $wpdb;

		$timestamp = gmdate( 'Ymd_His', time() );
		$file_path = "/tmp/updated_acf_media_credit_$timestamp.txt";

		$results = [];
		foreach ( $this->media_credit_mapping as $old_key => $new_key ) {
			$old_key_count = $wpdb->get_row( "SELECT COUNT(*) AS counter, GROUP_CONCAT( meta_id ) as meta_ids FROM $wpdb->postmeta WHERE meta_key = '$old_key'" );
			file_put_contents( $file_path, "$old_key: $old_key_count->meta_ids\n", FILE_APPEND );
			$old_key_count = $old_key_count->counter;

			$new_key_count = $wpdb->get_row( "SELECT COUNT(*) AS counter, GROUP_CONCAT( meta_id ) as meta_ids FROM $wpdb->postmeta WHERE meta_key = '_$new_key'" );
			file_put_contents( $file_path, "$new_key: $new_key_count->meta_ids\n", FILE_APPEND );
			$new_key_count = $new_key_count->counter;

			$updated_count = $wpdb->query( "UPDATE $wpdb->postmeta SET meta_key = REPLACE( meta_key, '$old_key', '_$new_key') WHERE meta_key = '$old_key'" );
			if ( is_numeric( $updated_count ) ) {
				$formatted_updated_count = number_format( $updated_count );
				file_put_contents( $file_path, "Updated: $formatted_updated_count\n", FILE_APPEND );
			}

			$results[] = [
				'old_key'         => $old_key,
				'old_key_count'   => number_format( $old_key_count ),
				'new_key'         => $new_key,
				'new_key_count'   => number_format( $new_key_count ),
				'updated_count'   => number_format( $updated_count ),
				'new_and_updated' => number_format( $new_key_count + $updated_count ),
			];
		}

		WP_CLI\Utils\format_items(
			'table',
			$results,
			[
				'old_key',
				'old_key_count',
				'new_key',
				'new_key_count',
				'updated_count',
				'new_and_updated',
			]
		);
	}

	public function cmd_display_updated_date_correctly( $args, $assoc_args ) {
		global $wpdb;

		$post_ids_with_updated_time = $wpdb->get_results(
			"SELECT 
       			post_id 
			FROM $wpdb->postmeta 
			WHERE meta_key = 'display_updated_date_and_time' 
			  AND meta_value = 1"
		);
		$post_ids_with_updated_time = array_map( fn( $row ) => $row->post_id, $post_ids_with_updated_time );

		WP_CLI::line( 'Count of posts with updated time: ' . count( $post_ids_with_updated_time ) );

		$post_ids_without_updated_time_sql = "SELECT 
       			p.ID 
			FROM $wpdb->posts p 
			LEFT JOIN (
			    SELECT post_id, MAX(meta_key) AS meta_key, MAX(meta_value) AS meta_value
			    FROM $wpdb->postmeta
			    WHERE meta_key = 'newspack_hide_updated_date'
			    GROUP BY post_id
			) as sub ON p.ID = sub.post_id
			WHERE p.post_type = 'post'
			  AND sub.post_id IS NULL";

		if ( ! empty( $post_ids_with_updated_time ) ) {
			$post_ids_with_updated_time_concatenated = implode( ',', $post_ids_with_updated_time );
			$post_ids_without_updated_time_sql      .= " AND p.ID NOT IN ($post_ids_with_updated_time_concatenated)";
		}
		$post_ids_without_updated_time = $wpdb->get_results( $post_ids_without_updated_time_sql );

		$interval     = 300;
		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Inserting Newspack Hide Updated Date data', count( $post_ids_without_updated_time ) );
		while ( ! empty( $post_ids_without_updated_time ) ) {
			$interval --;

			if ( 0 == $interval ) {
				sleep( 2 );
				$interval = 300;
			}

			$post = array_shift( $post_ids_without_updated_time );

			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => $post->ID,
					'meta_key'   => 'newspack_hide_updated_date',
					'meta_value' => '1',
				]
			);
			$progress_bar->tick();
		}
		$progress_bar->finish();

		// This should only be run when the site is in production.
		/*
		$result = $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = 'display_updated_date_and_time' AND post_id IN ($post_ids_with_updated_time_concatenated)" );

		if ( is_numeric( $result ) ) {
			WP_CLI::line( "Count of deleted posts with old postmeta of updated time: $result" );
		}*/
	}

	public function cmd_update_related_posts_block( $args, $assoc_args ) {
		global $wpdb;

		$deleted_empty_postmeta = $wpdb->query(
			"DELETE FROM $wpdb->postmeta 
				WHERE meta_key = 'berkeleyside_related-post-by-id' 
				  AND meta_value = ''"
		);

		if ( is_numeric( $deleted_empty_postmeta ) ) {
			$formatted_deleted_empty_postmeta = number_format( $deleted_empty_postmeta );
			WP_CLI::line( "Deleted $formatted_deleted_empty_postmeta empty related post meta rows" );
		}

		$post_ids_with_related_posts = $wpdb->get_results(
			"SELECT 
       				post_id, 
       				meta_id,
       				meta_value 
				FROM $wpdb->postmeta 
				WHERE meta_key = 'berkeleyside_related-post-by-id'
					AND meta_value != ''"
		);

		$count_post_ids_with_related_posts = count( $post_ids_with_related_posts );
		$old_related_post_ids              = [];
		$progress_bar                      = WP_CLI\Utils\make_progress_bar( 'Processing related post data', $count_post_ids_with_related_posts );
		foreach ( $post_ids_with_related_posts as &$post ) {
			$separator = ', ';

			if ( str_contains( $post->meta_value, ',' ) ) {
				$separator = ',';
			} elseif ( str_contains( $post->meta_value, '. ' ) ) {
				$separator = '. ';
			}

			$old_post_ids = explode( $separator, $post->meta_value );

			foreach ( $old_post_ids as $key => $old_post_id ) {
				$old_post_id = trim( $old_post_id );
				if ( is_numeric( $old_post_id ) ) {
					$old_post_id = (int) $old_post_id;
					$old_post_ids[ $key ] = $old_post_id;
					$old_related_post_ids[ $old_post_id ] = null;
				} else {
					unset( $old_post_ids[ $key ] );
				}
			}

			$post->meta_value = $old_post_ids;
			$progress_bar->tick();
		}
		$progress_bar->finish();

		$old_to_new_post_ids = [];
		if ( ! empty( $old_related_post_ids ) ) {
			$old_to_new_post_ids = $this->get_old_to_new_post_ids( array_keys( $old_related_post_ids ) );
		}

		$block_template = '<!-- wp:newspack-blocks/homepage-articles 
		{
			"showExcerpt":false,
			"showDate":false,
			"showAuthor":false,
			"showAvatar":false,
			"postLayout":"grid",
			"specificPosts":[{post_ids}],
			"typeScale":2,
			"sectionHeader":"Related stories",
			"specificMode":true
		} /-->';

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Inserting homepage block to posts', $count_post_ids_with_related_posts );
		$updated      = 0;
		foreach ( $post_ids_with_related_posts as $post ) {
			$new_post_ids = [];
			foreach ( $post->meta_value as $old_post_id ) {
				if ( is_numeric( $old_post_id ) && array_key_exists( $old_post_id, $old_to_new_post_ids ) ) {
					$new_post_ids[] = $old_to_new_post_ids[ (int) $old_post_id ]->new_post_id;
				}
			}

			if ( ! empty( $new_post_ids ) ) {
				$new_post_ids_concatenated = implode( ',', $new_post_ids );
				$block                     = strtr(
					$block_template,
					[
						'{post_ids}' => $new_post_ids_concatenated,
					]
				);

				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE $wpdb->posts 
							SET post_content = CONCAT(post_content, %s, %s) 
							WHERE ID = %d",
						'<br>',
						$block,
						$post->post_id
					)
				);

				if ( is_numeric( $result ) ) {
					$updated ++;
					$wpdb->delete(
						$wpdb->postmeta,
						[
							'meta_id' => $post->meta_id,
						]
					);
				}
			}

			$progress_bar->tick();
		}
		$progress_bar->finish();

		WP_CLI::line( "Total posts with related posts: $count_post_ids_with_related_posts" );
		WP_CLI::line( "Total posts updated: $updated" );
	}

	public function cmd_migrate_user_avatars( $args, $assoc_args ) {
		global $wpdb;

		$users_and_posts_with_avatars = $wpdb->get_results(
			"SELECT 
       			sub.umeta_id, 
       			sub.user_id, 
       			p.ID as post_id 
			FROM (
    			SELECT * FROM wp_live_usermeta 
    			WHERE meta_key = 'wp_live_metronet_image_id' 
    			  AND meta_value != 0 
    			  AND meta_value NOT LIKE '0.%') as sub
			LEFT JOIN wp_live_posts p ON p.ID = sub.meta_value
			WHERE p.ID IS NOT NULL"
		);

		$old_to_new_post_ids = $this->get_old_to_new_post_ids( array_map( fn( $row ) => $row->post_id, $users_and_posts_with_avatars ) );

		$simple_avatars_class = new Simple_Local_Avatars();

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Creating Simple Local Avatar data', count( $users_and_posts_with_avatars ) );
		foreach ( $users_and_posts_with_avatars as $user_and_post_with_avatar ) {
			if ( $old_to_new_post_ids[ $user_and_post_with_avatar->post_id ] ) {
				$simple_avatars_class->assign_new_user_avatar(
					$old_to_new_post_ids[ $user_and_post_with_avatar->post_id ]->new_post_id,
					$user_and_post_with_avatar->user_id
				);

				// Updating, instead of deleting. Making it so it's not possible to obtain correct Post ID.
				$wpdb->update(
					'wp_live_usermeta',
					[
						'meta_value' => "0.$user_and_post_with_avatar->post_id",
					],
					[
						'umeta_id' => $user_and_post_with_avatar->umeta_id,
					]
				);
			}
			$progress_bar->tick();
		}
		$progress_bar->finish();
	}

	public function cmd_replace_postmeta_with_tags( $args, $assoc_args ) {
		global $wpdb;

		$meta_values = [];

		foreach ( $this->postmeta_to_tag_mapping as $meta_value => $tag ) {
			$meta_values[] = "'$meta_value'";

			$tag = wp_create_tag( $tag );

			$tag = $tag['term_taxonomy_id'];

			$this->postmeta_to_tag_mapping[ $meta_value ] = $tag;
		}

		if ( ! empty( $meta_values ) ) {
			$meta_values = implode( ',', $meta_values );

			$postmeta_with_target_types = $wpdb->get_results(
				"SELECT
				       *
				FROM $wpdb->postmeta
				WHERE meta_key = 'article_type'
				  AND meta_value IN ($meta_values)
				ORDER BY post_id DESC"
			);

			$progress_bar = WP_CLI\Utils\make_progress_bar( 'Associating Posts to Tags', count( $postmeta_with_target_types ) );
			foreach ( $postmeta_with_target_types as $postmeta ) {
				$wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $postmeta->post_id,
						'term_taxonomy_id' => $this->postmeta_to_tag_mapping[ $postmeta->meta_value ],
					]
				);

				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_value' => "0.$postmeta->meta_value",
					],
					[
						'meta_id' => $postmeta->meta_id,
					]
				);

				if ( 'lead_story_front_page_photo' === $postmeta->meta_value ) {
					update_post_meta( $postmeta->post_id, 'newspack_featured_image_position', 'above' );
				}

				$progress_bar->tick();
			}

			$progress_bar->finish();
		}
	}

	public function cmd_add_user_title_to_yoast( $args, $assoc_args ) {
		global $wpdb;

		$users_and_titles = $wpdb->get_results(
			"SELECT 
       			user_id, 
       			meta_value 
			FROM $wpdb->usermeta 
			WHERE meta_key = 'berkeleyside_title' 
			  AND meta_value != ''"
		);

		$progress_bar  = WP_CLI\Utils\make_progress_bar( 'Updating User Meta with Job Title', count( $users_and_titles ) );
		$updated_users = [];
		foreach ( $users_and_titles as $user_and_title ) {
			$meta = get_user_meta( $user_and_title->user_id, 'wpseo_user_schema', true );

			$updated_user = [
				'User_ID'       => $user_and_title->user_id,
				'Title'         => $user_and_title->meta_value,
				'Previous_Meta' => $meta,
			];

			if ( is_array( $meta ) ) {
				$meta['jobTitle'] = $user_and_title->meta_value;

				$wpdb->update(
					$wpdb->usermeta,
					[
						'meta_key'   => 'wpseo_user_schema',
						'meta_value' => serialize( $meta ),
					],
					[
						'user_id'  => $user_and_title->user_id,
						'meta_key' => 'wpseo_user_schema',
					]
				);
			} else {
				$meta = [ 'jobTitle' => $user_and_title->meta_value ];

				$wpdb->insert(
					$wpdb->usermeta,
					[
						'user_id'    => $user_and_title->user_id,
						'meta_key'   => 'wpseo_user_schema',
						'meta_value' => serialize( $meta ),
					]
				);
			}

			$updated_user['Updated_Meta'] = $meta;
			$updated_users[] = $updated_user;
			$progress_bar->tick();
		}
		$progress_bar->finish();

		WP_CLI\Utils\format_items( 'table', $updated_users, [ 'User_ID', 'Title', 'Previous_Meta', 'Updated_Meta' ] );
	}

	/**
	 * Converts Post IDs from "live" tables to corresponding Post IDs from staging/production tables.
	 *
	 * @param int[] $old_post_ids Array of old post IDs from "live" table.
	 *
	 * @return array|object|stdClass[]|null
	 */
	private function get_old_to_new_post_ids( array $old_post_ids ) {
		if ( empty( $old_post_ids ) ) {
			return [];
		}

		global $wpdb;

		$old_related_post_ids_concatenated = implode( ',', $old_post_ids );

		return $wpdb->get_results(
			"SELECT
							sub.ID as old_post_id,
							p.ID as new_post_id
						FROM $wpdb->posts p
						LEFT JOIN (
						    SELECT 
						           ID, 
						           post_name, 
						           post_date, 
						           post_type, 
						           post_status
						    FROM wp_live_posts
						    WHERE ID IN ($old_related_post_ids_concatenated)						    
						) as sub 
						    ON sub.post_name = p.post_name 
						           AND sub.post_date = p.post_date 
						           AND sub.post_type = p.post_type 
						           AND sub.post_status = p.post_status
						WHERE sub.ID IS NOT NULL",
			OBJECT_K
		);
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
