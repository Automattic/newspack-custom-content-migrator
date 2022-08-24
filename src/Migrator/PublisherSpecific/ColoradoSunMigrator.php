<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for Colorado Sun.
 */
class ColoradoSunMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachment_logic;

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthors_logic;

	/**
	 * @var WpBlockManipulator
	 */
	private $wp_block_manipulator;

	/**
	 * @var HtmlElementManipulator
	 */
	private $html_element_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->attachment_logic = new AttachmentsLogic();
		$this->coauthors_logic = new CoAuthorPlusLogic();
		$this->wp_block_manipulator = new WpBlockManipulator();
		$this->html_element_manipulator = new HtmlElementManipulator();
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
			'newspack-content-migrator coloradosun-remove-reusable-block-from-end-of-content',
			[ $this, 'cmd_remove_reusable_block_from_end_of_content' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator coloradosun-move-custom-subtitle-meta-to-newspack-subtitle',
			[ $this, 'cmd_move_custom_subtitle_meta_to_newspack_subtitle' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator coloradosun-refactor-lede-common-iframe-block-into-newspack-iframe-block',
			[ $this, 'cmd_refactor_lede_common_iframe_block_into_newspack_iframe_block' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator coloradosun-migrate-authors-to-cap',
			[ $this, 'cmd_migrate_authors_to_cap' ],
		);
	}

	public function cmd_refactor_lede_common_iframe_block_into_newspack_iframe_block( $positional_args, $assoc_args ) {

		global $wpdb;

		$post_ids = $this->posts_logic->get_all_posts_ids();

		/*
		 * Find these blocks:
		 * <!-- wp:lede-common/iframe {"src":"https://flo.uri.sh/visualisation/10378079/embed","width":"100%","height":"600"} /-->
		 */
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post = get_post( $post_id );
			$post_content_updated = $post->post_content;

			$matches = $this->wp_block_manipulator->match_wp_block_selfclosing( 'wp:lede-common/iframe', $post->post_content );
			if ( is_null( $matches ) || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
				WP_CLI::log( 'Block not found, skipping.' );
				continue;
			}

			foreach ( $matches[0] as $key_match => $match ) {
				$lede_block = $match[0];
				$lede_src = $this->wp_block_manipulator->get_attribute( $lede_block, 'src' );
				$lede_width = $this->wp_block_manipulator->get_attribute( $lede_block, 'width' );
				$lede_height = $this->wp_block_manipulator->get_attribute( $lede_block, 'height' );

				// Lede has either '%' for percentage, or nothing for pixels, while iframe has 'px' for pixels.

				// Get iframe block width value.
				$iframe_width = null;
				if ( ! is_null( $lede_width ) ) {
					$is_percent = str_ends_with( $lede_width, '%' );
					// If not percent, add 'px' for pixels.
					$iframe_width = $is_percent ? $lede_width : $lede_width . 'px';
				}

				// Get iframe block height value.
				$iframe_height = null;
				if ( ! is_null( $lede_height ) ) {
					$is_percent = str_ends_with( $lede_height, '%' );
					// If not percent, add 'px' for pixels.
					$iframe_height = $is_percent ? $lede_height : $lede_height . 'px';
				}

				// E.g. <!-- wp:newspack-blocks/iframe {"src":"https://flo.uri.sh/visualisation/10378079/embed","height":"500px","width":"99%"} /-->
				$iframe_block = sprintf(
					'<!-- wp:newspack-blocks/iframe {"src":"%s"%s%s} /-->',
					$lede_src,
					! is_null( $iframe_height ) ? sprintf( ',"height":"%s"', $iframe_height ) : '',
					! is_null( $iframe_width ) ? sprintf( ',"width":"%s"', $iframe_width ) : ''
				);

				$post_content_updated = str_replace( $lede_block, $iframe_block, $post_content_updated );
			}

			if ( $post->post_content != $post_content_updated ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );
				$this->log( 'cs_iframeblock.log', $post_id );
			}
		}

		WP_CLI::log( 'Done. Check log cs_iframeblock.log' );
		wp_cache_flush();
	}

	public function cmd_move_custom_subtitle_meta_to_newspack_subtitle( $positional_args, $assoc_args ) {
		global $wpdb;

		$rows = $wpdb->get_results( "select meta_id, post_id, meta_key, meta_value from $wpdb->postmeta where meta_key = 'dek' ;", ARRAY_A );
		foreach ( $rows as $key_row => $row ) {
			$meta_id = $row['meta_id'];
			$post_id = $row['post_id'];
			$meta_value = $row['meta_value'];

			WP_CLI::log( sprintf( '(%d)/(%d) meta_id %d', $key_row + 1, count( $rows ), $meta_id ) );

			if ( empty( $meta_value ) ) {
				WP_CLI::log( 'Empty, skipping.' );
				continue;
			}

			update_post_meta( $post_id, 'newspack_post_subtitle', $meta_value );
			delete_post_meta( $post_id, 'dek' );

			$this->log( 'cs_subtitlesmoved.log', $post_id );
		}
	}

	public function cmd_remove_reusable_block_from_end_of_content( $positional_args, $assoc_args ) {
		global $wpdb;

		// e.g. <!-- wp:block {"ref":14458} /-->
		$reusable_block_html = '<!-- wp:block {"ref":14458} /-->';
		$reusable_block_additional_search = 'wp:block {"ref":14458}';
		$results = $wpdb->get_results( "select ID, post_content from $wpdb->posts where post_type in ( 'post', 'page' ) and post_status in ( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ) ; ", ARRAY_A );

		$post_ids_where_additional_blocks_are_found = [];
		foreach ( $results as $key_result => $result ) {

			$id = $result['ID'];
			$post_content = $result['post_content'];

			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_result + 1, count( $results ), $id ) );

			// Remove strings from the end of the post_content.
			$remove_strings = [
				// Some posts end with extra breaks.
				"<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->",
				// Some posts have two or three of these at the end.
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				// More types breaks used at the end of their content.
				"<!-- wp:paragraph -->
<p><strong><br></strong></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p> </p>
<!-- /wp:paragraph -->",
				$reusable_block_html,
			];
			// Doing rtrim directly on post_content because we'll be comparing them later, and we don't want to update a Post just
			// for spaces or line breaks.
			$post_content = rtrim( $post_content );
			$post_content_updated = $post_content;
			foreach ( $remove_strings as $remove_string ) {
				$post_content_updated = $this->remove_string_from_end_of_string( $post_content_updated, $remove_string );
				$post_content_updated = rtrim( $post_content_updated );
			}

			// Double check if this block is still used anywhere else in this content.
			if ( false !== strpos( $post_content_updated, $reusable_block_additional_search ) ) {
				$post_ids_where_additional_blocks_are_found[] = $id;
			}

			if ( $post_content_updated != $post_content ) {
				// A simple control check.
				$diff = strcasecmp( $post_content_updated, $post_content );
				if ( $diff > 40 ) {
					$dbg = 1;
				}

				// Save and log.
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $id ] );
				$this->log( 'cs_reusableblocksremovedfromendofcontent.log', $id );
				$this->log( 'cs_'.$id.'_1_before.log', $post_content );
				$this->log( 'cs_'.$id.'_2_after.log', $post_content_updated );
				WP_CLI::success( 'Saved' );
			}
		}

		WP_CLI::log( 'Done. See log cs_reusableblocksremovedfromendofcontent.log' );
		WP_CLI::log( sprintf( 'Additional found blocks: %s', implode( ',', $post_ids_where_additional_blocks_are_found ) ) );

		wp_cache_flush();
	}

	/**
	 * @param $positional_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_migrate_authors_to_cap( $positional_args, $assoc_args ) {

		global $wpdb;

		/*
		 * Things this command does:
		 * for each post, there is a wp_postmeta.meta_key = 'byline'.  This is a serialized string that contains the post_id of
		 * the author from the initial data import from the currently live site.  This ID does not match up with the current
		 * wp_posts table on the staging site.  We need to move the authors over to CAP configuration.
		 */

		// Import live site's wp_posts and wp_postmeta.
		\WP_CLI::confirm( "Make sure you've imported the latest live_wp_posts and live_wp_postmeta (with properly updated hostnames) before continuing with this command." );

		// Dev helper variable.
		$distinct_types = [];

		// Get all posts.
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {


			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			$post = get_post( $post_id );


			// Get profiles/'byline' meta.
			$byline_meta_value = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key='byline' and post_id = %d;", $post_id ) );
			$byline_meta = $byline_meta_value ? unserialize( $byline_meta_value ) : null;
			if ( is_null( $byline_meta ) ) {
				// LOG no authors.
				$msg = sprintf( "ID=%d no_author_profiles", $post_id );
				$this->log( 'cs_authorprofiles_no_authors.log', $msg );
				WP_CLI::log( 'No author profiles. Skipping.' );
				continue;
			}


			// LOG this post ID, and number of its 'profile' authors.
			$this->log( 'cs_authorprofiles_saved_GAs.log', sprintf( "ID=%d number_of_authors=%d", $post_id, count( $byline_meta['profiles'] ) ) );
			WP_CLI::log( sprintf( "%d authors", count( $byline_meta['profiles'] ) ) );


			// Loop through this post's profiles.
			foreach ( $byline_meta['profiles'] as $key_profile => $profile ) {

				// Custom "profile type" variable.
				$type = $profile['type'];


				// Dev helper - check if type is known, distinct existing types are = [ 'byline', ]
				if ( ! in_array( $type , $distinct_types ) ) {
					$distinct_types = array_merge( $distinct_types, [ $type ] );
					WP_CLI::log( sprintf( "new distinct profile type = %s", $type ) );
				}


				// Get profile "atts" which contains "term_id" (profile author term_id) and "post_id" (profile author post_id).
				$atts = $profile['atts'];
				// Dev helper check if $atts has more values than just 'term_id' and 'post_id'.
				if ( count( $atts ) > 2 || ! isset( $atts['term_id'] ) || ! isset( $atts['post_id'] ) ) {
					$debug=1;
				}


				// Profile term_id -- actually, looks like we don't need the Term. Commenting out but leaving the code.
				// $term_id = $atts['term_id'] ?? null;
				// $term_row = $wpdb->get_row( $wpdb->prepare( "select * from live_wp_terms where term_id = %d ;", $term_id ), ARRAY_A );
				// // Validate.
				// if ( is_null( $term_id ) || ! $term_row ) {
				// 	WP_CLI::log( sprintf( "no term data: term_id=%s term_row=%s", $term_id, print_r( $term_row, true ) ) );
				// 	$debug=1;
				// }


				// Profile author post object.
				$profile_post_id = $atts['post_id'] ?? null;
				$profile_post_row = $wpdb->get_row( $wpdb->prepare( "select * from live_wp_posts where ID = %d ;", $profile_post_id ), ARRAY_A );
				// Validate.
				if ( is_null( $profile_post_id ) || ! $profile_post_row || ( 'profile' != $profile_post_row['post_type'] )  ) {
					WP_CLI::log( sprintf(
						"no live post data: profile_author_post_id=%s post_row=%s post_type=%s",
						$profile_post_id,
						( ! $profile_post_row ? 'null' : 'OK' ),
						$profile_post_row['post_type'] ?? 'null'
					) );
				}


				// Get profile postmeta.
				// --- email, twitter handle, live WP User associated to Post, live att_id of author avatar
				$email = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key = %s and post_id = %d ;", 'email', $profile_post_id ) );
				$twitter_handle = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key = %s and post_id = %d ;", 'twitter', $profile_post_id ) );
				$live_wp_user_id = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key = %s and post_id = %d ;", 'user_id', $profile_post_id ) );
				$live_attachment_id = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key = %s and post_id = %d ;", '_thumbnail_id', $profile_post_id ) );


				// Get actual local avatar attachment ID.
				$live_attachment_obj_row = $wpdb->get_row( $wpdb->prepare( "select * from live_wp_posts where ID = %d", $live_attachment_id ), ARRAY_A );
				// This avatar attachment file path, will be e.g. '2022/01/outcalt_01.jpg'.
				$live_attachment_wp_content_file_path = $wpdb->get_var( $wpdb->prepare( "select meta_value from live_wp_postmeta where meta_key = '_live_wp_attached_file' and post_id = %d;", $live_attachment_id ) );
				if ( $live_attachment_wp_content_file_path ) {

					// Check if local attachment exists.
					$this_hostname = get_site_url();
					$this_hostname_parsed = parse_url( $this_hostname );
					$local_attachment_url = sprintf( "https://%s/wp-content/uploads/%s", $this_hostname_parsed['host'], $live_attachment_wp_content_file_path );
					$ga_avatar_att_id = attachment_url_to_postid( $local_attachment_url );
					if ( 0 == $ga_avatar_att_id ) {
						// Import attachment if not exists.
						// Get live attachment img URL.
						$featured_img_live_url = sprintf( "https://lede-admin.coloradosun.com/wp-content/uploads/sites/15/%s", $live_attachment_wp_content_file_path );
						$ga_avatar_att_id = $this->attachment_logic->import_external_file( $featured_img_live_url );

						// Log downloading and importing avatar from their live site.
						$this->log( 'cs_authorprofiles_avatars_downloaded.log', sprintf( "live_att_id=%d URL=%s post_ID=%d", $live_attachment_id, $live_attachment_wp_content_file_path, $post_id ) );
					} else {
						$this->log( 'cs_authorprofiles_avatars_found.log', sprintf( "live_att_id=%d local_att_id=%s post_ID=%d", $live_attachment_id, $ga_avatar_att_id, $post_id ) );
					}

				}


				// Get the actual local WP author User ID.
				// --- first get the live site user data
				$live_wp_user_row = $wpdb->get_row( $wpdb->prepare( "select * from live_wp_users where ID = %d", $live_wp_user_id ), ARRAY_A );
				$live_wp_user_meta_rows = $wpdb->get_results( $wpdb->prepare( "select * from live_wp_usermeta where user_id = %d", $live_wp_user_id ), ARRAY_A );
				// --- get the local user with same ID
				//      --- first try and fetch WP author user from $post->post_author
				$wp_user_id = $post->post_author;
				$wp_user = get_user_by( 'ID', $wp_user_id );
				//      --- second try and fetch WP author user from profile's 'user_id' meta
				if ( false == $wp_user || ( $live_wp_user_row['user_login'] != $wp_user->user_login ) ) {
					$wp_user = get_user_by( 'ID', $live_wp_user_id );
				}
				// --- if this same user exists locally, use it, otherwise insert a new WP user.
				$linked_wp_user_id = null;
				if ( $live_wp_user_row['user_login'] == $wp_user->user_login ) {
					// Use existing user.
					$linked_wp_user_id = $wp_user_id;
				} else {
					// Insert new user.
					$inserted = $wpdb->insert(
						$wpdb->users,
						[
							'user_login' => $live_wp_user_row['user_login'],
							'user_pass' => $live_wp_user_row['user_pass'],
							'user_nicename' => $live_wp_user_row['user_nicename'],
							'user_email' => $live_wp_user_row['user_email'],
							'user_url' => $live_wp_user_row['user_url'],
							'user_registered' => $live_wp_user_row['user_registered'],
							'user_activation_key' => $live_wp_user_row['user_activation_key'],
							'user_status' => $live_wp_user_row['user_status'],
							'display_name' => $live_wp_user_row['display_name'],
							// These two columns don't exist in our local native WP.
							// 'spam' => $live_wp_user_row['spam'],
							// 'deleted' => $live_wp_user_row['deleted'],
						]
					);
					if (1 != $inserted ) {
						throw new \RuntimeException( sprintf( "Failed inserting user live_wp_users.ID = %d", $live_wp_user_id ) );
					}
					// Get newly inserted ID.
					$inserted_wp_user_id = $wpdb->get_var( $wpdb->prepare( "select ID from wp_users where user_login = %s ; ", $live_wp_user_row['user_login'] ) );
					$linked_wp_user_id = $inserted_wp_user_id;

					// Insert usermeta.
					foreach ( $live_wp_user_meta_rows as $live_wp_user_meta_row ) {
						$inserted = $wpdb->insert(
							$wpdb->usermeta,
							[
								'user_id' => $inserted_wp_user_id,
								'meta_key' => $live_wp_user_meta_row['meta_key'],
								'meta_value' => $live_wp_user_meta_row['meta_value'],
							]
						);
						if ( 1 != $inserted ) {
							throw new \RuntimeException( sprintf( "Failed inserting usermeta row live_wpusermeta.meta_id = %d", $live_wp_user_meta_row['meta_id'] ) );
						}
					}

					// Log user creation.
					$this->log( 'cs_authorprofiles_inserted_new_user.log', sprintf( "ID=%d, user_login=%s", $inserted_wp_user_id, $live_wp_user_row['user_login'] ) );
					WP_CLI::log( sprintf( "iimported new User new_ID=%d, user_login=%s", $inserted_wp_user_id, $live_wp_user_row['user_login'] ) );
				}


				// Prepare GA bio text.
				$ga_email_link = $email ? sprintf( "Email: <a class=\"ga_email_link\" href=\"mailto:%s\">%s</a>", $email, $email ) : '';
				$ga_twitter_link = $twitter_handle ? sprintf( "Twitter: <a class=\"newspack-ga-twitter-link\" target=\"_blank\" href=\"https://twitter.com/%s\">@%s</a>", $twitter_handle, $twitter_handle ) : '';
				$ga_bio_connect_text = sprintf(
					"%s%s%s",
					// Start with email link.
					$ga_email_link,
					// Separator, space ' '.
					( ( ! empty( $ga_email_link ) && ! empty( $ga_twitter_link ) ) ? ' ' : '' ),
					// Twitter link.
					$ga_twitter_link
				);
				$ga_bio = sprintf(
					"%s%s%s",
					// Author bio.
					$profile_post_row['post_content'],
					// Separator, new line break.
					( ( ! empty( $profile_post_row['post_content'] ) && ! empty( $ga_bio_connect_text ) ) ? "\n" : '' ),
					// Connect text
					$ga_bio_connect_text
				);


				// Get or create GA by name.
				$ga_name = $profile_post_row['post_title'];
				// Check if GA exists by author name.
				$ga = $this->coauthors_logic->get_guest_author_by_display_name( $ga_name );
				if ( $ga ) {
					$guest_author_id = $ga->ID;
				} else {
					// Create if not exists.
					$guest_author_id = $this->coauthors_logic->create_guest_author( [ 'display_name' => $ga_name, 'description' => $ga_bio, 'avatar' => $ga_avatar_att_id ] );
					// Log.
					$this->log( 'cs_authorprofiles_created_gas.log', sprintf( "ga_id=%d", $guest_author_id ) );
				}
				if ( is_null( $guest_author_id ) ) {
					throw new \RuntimeException( sprintf( "GA with name %s not found or created.", $ga_name ) );
				}


				// Link this GA to the WP User (this will be repeated for every post, but OK...).
				if ( $wp_user ) {
					$this->coauthors_logic->link_guest_author_to_wp_user( $guest_author_id, $wp_user );
				}


				// Merge existing GAs with this GA.
				$existing_guest_author_ids = $this->coauthors_logic->get_posts_existing_ga_ids( $post_id );
				$guest_author_ids = array_unique( array_merge( [ $guest_author_id ], $existing_guest_author_ids ) );


				// Assign GAs to Post.
				$this->coauthors_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );
			}
		}
	}

	public function remove_string_from_end_of_string( $subject, $remove ) {
		$subject_reverse = strrev( $subject );
		$remove_reverse = strrev( $remove );

		if ( 0 === strpos( $subject_reverse, $remove_reverse ) ) {
			return strrev( substr( $subject_reverse, strlen( $remove_reverse ) ) );
		}

		return $subject;
	}

	public function escape_regex_pattern_string( $subject ) {
		$special_chars = [ ".", "\\", "+", "*", "?", "[", "^", "]", "$", "(", ")", "{", "}", "=", "!", "<", ">", "|", ":", ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\'. $special_char, $subject_escaped );
		}

		// Space.
		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
