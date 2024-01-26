<?php
/**
 * Newspack Custom Content Migrator Molongui plugin autorship to CAP.
 *
 * @package NewspackCustomContentMigrator.
 */

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Migrates Molongui plugin autorship to CAP.
 */
class MolonguiAutorship implements InterfaceCommand {

	const ERROR_LOG                      = 'molongui-to-cap_ERR.txt';
	const POSTMETA_ORIGINAL_MOLOGUI_USER = 'newspack_molongui_original_user';

	/**
	 * Instance.
	 *
	 * @var null|self
	 */
	private static $instance = null;

	/**
	 * Posts instance.
	 *
	 * @var Posts
	 */
	private $posts;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus
	 */
	private $cap;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts  = new Posts();
		$this->cap    = new CoAuthorPlus();
		$this->logger = new Logger();
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
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator molongui-to-cap',
			[ $this, 'cmd_molongui_to_cap' ],
			[
				'shortdesc' => 'Converts Molongui authorship to CAP.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'table-prefix-mologui-data',
						'description' => "Specify in which tables should the conversion script look for Mologui authorship data. Consider the case when a content refresh is performed, and that in such a scenariothe  WP_User IDs and wp_post IDs will change. After a content refresh, the local DB's Mologui postmeta might still be pointing to old IDs of Mologui's author records. To fix this old ID VS new ID discrepancy, it's easiest to import the live DB side by side with the local DB, using some prefix like `live_` then run this command feeding it this prefix. Otherwise, if you are provide the local DB prefix here, just make sure that Mologui meta is still pointing to valid local records, e.g. postmeta 'user-95' still points to correct wp_user.ID entry, and same for 'guest-xx' entries pointing to valid wp_posts rows.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dry-run',
						'description' => 'Will not actually create GAs and assign them to posts, just display simulated outcomes.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		);
	}

	/**
	 * Migrates Molongui plugin autorship to CAP.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_molongui_to_cap( array $pos_args, array $assoc_args ): void {
		global $wpdb;

		$table_prefix_mologui = $assoc_args['table-prefix-mologui-data'];
		$dry_run              = $assoc_args['dry-run'] ?? false;

		// Get Molongui authors.
		$molongui_authors_values = $wpdb->get_col( "select distinct meta_value from {$wpdb->postmeta} where meta_key = '_molongui_author';" );
		if ( ! $molongui_authors_values ) {
			WP_CLI::warning( 'No authors found.' );
			return;
		}

		// Used for dry runs.
		$dry_run_mologui_to_gas = [];

		/**
		 * Convert all Mologui authors to CAP GAs.
		 */
		foreach ( $molongui_authors_values as $key_molongui_author_value => $molongui_author_value ) {
			WP_CLI::line( sprintf( '%d/%d %s', $key_molongui_author_value + 1, count( $molongui_authors_values ), $molongui_author_value ) );

			/**
			 * Mologui uses an existing WP_User which it extends with custom meta.
			 * In this case, the postmeta key_value is 'user-{ID}' (where meta_key = '_molongui_author').
			 */
			if ( 0 === strpos( $molongui_author_value, 'user-' ) ) {

				$wpuser_id = (int) str_replace( 'user-', '', $molongui_author_value );
				// phpcs:disable -- Allow querying users table.
				$author_wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from {$table_prefix_mologui}users where ID = %d;", $wpuser_id ), ARRAY_A );
				// phpcs:enable
				if ( ! $author_wpuser_row ) {
					$msg = sprintf( 'WP_User with ID %d not found in %s table (referenced by postmeta key `user-%s`).', $wpuser_id, $table_prefix_mologui . 'users', $wpuser_id );
					$this->logger->log( self::ERROR_LOG, $msg, $this->logger::ERROR, false );
					continue;
				}

				// Create CAP GA.
				$cap_args = $this->get_cap_creation_args_for_mologui_wpuser( $table_prefix_mologui, $wpuser_id );

				if ( $dry_run ) {
					$cap_id = '{DRY_RUN}';
					$dry_run_mologui_to_gas[ $molongui_author_value ] = $cap_args['display_name'];
				} else {
					$cap_id = $this->cap->create_guest_author( $cap_args );
					if ( is_wp_error( $cap_id ) ) {
						$msg = sprintf( 'Error creating CAP GA for Molongui user %s: %s', 'user-' . $wpuser_id, $cap_id->get_error_message() );
						$this->logger->log( self::ERROR_LOG, $msg, $this->logger::ERROR, false );
						continue;
					}
	
					// Save custom postmeta to GA saying which Molongui user this was.
					// There could be both a WP_User and a guest author with the same email, so we need to save both of them, i.e. add_post_meta, not update.
					add_post_meta( $cap_id, self::POSTMETA_ORIGINAL_MOLOGUI_USER, $molongui_author_value );
				}

				WP_CLI::success( sprintf( "Created GA ID %s from original ID '%s' %s", $cap_id, 'user-' . $wpuser_id, $cap_args['display_name'] ) );

			} elseif ( 0 === strpos( $molongui_author_value, 'guest-' ) ) {

				/**
				 * Molongui has a Guest type user without Dashboard access.
				 * In this case, the postmeta key_value is 'guest-{ID}' (where meta_key = '_molongui_author').
				 */
				$guest_id = (int) str_replace( 'guest-', '', $molongui_author_value );
				// phpcs:Ignore -- $wpdb->prepare is used.
				$guest_row = $wpdb->get_row( $wpdb->prepare( "select * from {$table_prefix_mologui}posts where ID = %d and post_type = 'guest_author';", $guest_id ), ARRAY_A );
				if ( ! $guest_row ) {
					$msg = sprintf( 'Guest author with ID %d not found in %s table (referenced by postmeta key: `guest-%s`).', $guest_id, $table_prefix_mologui . 'posts', $guest_id );
					$this->logger->log( self::ERROR_LOG, $msg, $this->logger::ERROR, false );
					continue;
				}

				// Create CAP GA.
				$cap_args = $this->get_cap_creation_args_for_mologui_guestauthor( $guest_id );

				if ( $dry_run ) {
					$cap_id = '{DRY_RUN}';
					$dry_run_mologui_to_gas[ $molongui_author_value ] = $cap_args['display_name'];
				} else {
					$cap_id = $this->cap->create_guest_author( $cap_args );
					if ( is_wp_error( $cap_id ) ) {
						$msg = sprintf( 'Error creating CAP GA for Molongui user %s: %s', 'guest-' . $guest_id, $cap_id->get_error_message() );
						$this->logger->log( self::ERROR_LOG, $msg, $this->logger::ERROR, false );
						continue;
					}
	
					// Save custom postmeta to GA saying which Molongui user this was.
					// There could be both a WP_User and a guest author with the same email, so we need to save both of them, i.e. add_post_meta, not update.
					add_post_meta( $cap_id, self::POSTMETA_ORIGINAL_MOLOGUI_USER, $molongui_author_value );
				}

				WP_CLI::success( sprintf( "Created GA ID %s from original ID '%s' %s", $cap_id, 'guest-' . $guest_id, $cap_args['display_name'] ) );

			} else {
				WP_CLI::error( sprintf( 'Unsupported Molongui author type in postmeta key: %s. Add support for this type then rerun command.', $cap_args['display_name'] ) );
			}
		}


		/**
		 * Assign GAs to posts.
		 */
		$post_ids                         = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		$post_ids                         = [];
		$cached_mologui_authors_to_ga_ids = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d %s', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Get Mologui authors for this post.
			$molongui_authors_rows = $wpdb->get_results( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_author';", $post_id ), ARRAY_A );
			if ( ! $molongui_authors_rows ) {
				continue;
			}

			$ga_ids = [];
			foreach ( $molongui_authors_rows as $molongui_author_row ) {

				$molongui_author_value = $molongui_author_row['meta_value'];

				// For dry run, just display authors that will be assigned to this post and continue.
				if ( $dry_run ) {
					$dry_run_author_name = $dry_run_mologui_to_gas[ $molongui_author_value ] ?? null;
					if ( $dry_run_author_name ) {
						\WP_CLI::success( sprintf( "Post ID %d , assigning GA '%s'", $post_id, $dry_run_author_name ) );
					} else {
						\WP_CLI::warning( sprintf( "ERROR -- Post ID %d , not found GA for Molongui author '%s'", $post_id, $molongui_author_value ) );
					}
					continue;
				}

				// Get GA IDs for this Mologui author.
				if ( isset( $cached_mologui_authors_to_ga_ids[ $molongui_author_value ] ) ) {
					$ga_id = $cached_mologui_authors_to_ga_ids[ $molongui_author_value ];
				} else {
					$ga_id = $wpdb->get_var( $wpdb->prepare( "select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s;", self::POSTMETA_ORIGINAL_MOLOGUI_USER, $molongui_author_value ) );
					if ( ! $ga_id ) {
						$msg = sprintf( 'Error fetching GA for Molongui user %s and assigning it to Post ID %d', $molongui_author_value, $post_id );
						$this->logger->log( self::ERROR_LOG, $msg, $this->logger::ERROR, false );
						continue;
					}
					$cached_mologui_authors_to_ga_ids[ $molongui_author_value ] = $ga_id;
				}

				$ga_ids[] = $ga_id;
			}
			
			// Assign GAs to Post.
			$this->cap->assign_guest_authors_to_post( $ga_ids, $post_id, false );
		}


		if ( file_exists( self::ERROR_LOG ) ) {
			WP_CLI::warning( sprintf( 'There were errors. See %s.', self::ERROR_LOG ) );
		}

		WP_CLI::line( 'Done.' );
		wp_cache_flush();
	}

	/**
	 * Get CAP creation args from Molongui guest author.
	 * Converts all Molongui Guest author meta to CAP meta.
	 *
	 * @param string $table_prefix_mologui Mologui data table prefix.
	 * @param int    $guest_id Mologui guest author ID.
	 *
	 * @return array CAP creation args, see \NewspackCustomContentMigrator\Logic\CoAuthorPlus::create_guest_author().
	 *
	 * @throws \UnexpectedValueException If Mologui guest author with $guest_id not found.
	 */
	public function get_cap_creation_args_for_mologui_guestauthor( string $table_prefix_mologui, int $guest_id ): array {
		global $wpdb;

		// phpcs:Ignore -- $wpdb->prepare is used.
		$author_row = $wpdb->get_row( $wpdb->prepare( "select * from {$table_prefix_mologui}posts where ID = %d and post_type = 'guest_author';", $guest_id ), ARRAY_A );
		if ( ! $author_row ) {
			throw new \UnexpectedValueException( sprintf( 'Mologui guest user with ID %d not found.', $guest_id ) );
		}

		// Main CAP GA creation args.
		$cap_args = [];

		// Basic info.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$display_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_display_name';", $guest_id ) );
		if ( $display_name ) {
			$cap_args['display_name'] = $display_name;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$first_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_first_name';", $guest_id ) );
		if ( $first_name ) {
			$cap_args['first_name'] = $first_name;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$last_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_last_name';", $guest_id ) );
		if ( $last_name ) {
			$cap_args['last_name'] = $last_name;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$email = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_mail';", $guest_id ) );
		if ( $email ) {
			$cap_args['user_email'] = $email;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$description = $wpdb->get_var( $wpdb->prepare( "select post_content from {$table_prefix_mologui}posts where ID = %d;", $guest_id ) );
		if ( $description ) {
			$cap_args['description'] = $description;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$avatar = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_thumbnail_id';", $guest_id ) );
		if ( $avatar ) {
			$cap_args['avatar'] = $avatar;
		}

		// HTML that will be appended to bio/description.
		$htmls_append_to_bio = [];

		/**
		 * Newspack usermeta/postmeta fields...
		 *      'newspack_job_title'
		 *      'newspack_role'
		 *      'newspack_employer'
		 *      'newspack_phone_number'
		 * ... and their corresponding Molongui meta fields:
		 *      _molongui_guest_author_web
		 *      _molongui_guest_author_job
		 *      _molongui_guest_author_company
		 *      _molongui_guest_author_company_link
		 *      _molongui_guest_author_box_display
		 *
		 * Save these as Newspack postmeta.
		 */
		// Author web -- append to description/bio.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$authorweb_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_web';", $guest_id ) );
		if ( $authorweb_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Web</a>', $authorweb_url );
		}
		// Job meta.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$molongui_guest_author_job = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_job';", $guest_id ) );
		if ( $molongui_guest_author_job ) {
			update_post_meta( $guest_id, 'newspack_job_title', $molongui_guest_author_job );
		}
		// Employer/company meta.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$molongui_guest_author_company = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_company';", $guest_id ) );
		if ( $molongui_guest_author_company ) {
			update_post_meta( $guest_id, 'newspack_employer', $molongui_guest_author_company );
		}

		/**
		 * Molongui social sites meta fields, also appended to bio.
		 */
		// FB.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_facebook';", $guest_id ) );
		if ( $facebook_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Facebook</a>', $facebook_url );
		}
		// IG.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_instagram';", $guest_id ) );
		if ( $instagram_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Instagram</a>', $instagram_url );
		}
		// Twitter.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$twitter_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_twitter';", $guest_id ) );
		if ( $twitter_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Twitter</a>', $twitter_url );
		}
		// Tumblr.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_tumblr';", $guest_id ) );
		if ( $tumblr_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Tumblr</a>', $tumblr_url );
		}
		// YouTube.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_youtube';", $guest_id ) );
		if ( $youtube_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">YouTube</a>', $youtube_url );
		}
		// Medium.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$medium_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_medium';", $guest_id ) );
		if ( $medium_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Medium</a>', $medium_url );
		}
		// Pinterest.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$pinterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_pinterest';", $guest_id ) );
		if ( $pinterest_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Pinterest</a>', $pinterest_url );
		}
		// Soundcloud.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_soundcloud';", $guest_id ) );
		if ( $soundcloud_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Soundcloud</a>', $soundcloud_url );
		}
		// Spotify.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$spotify_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_spotify';", $guest_id ) );
		if ( $spotify_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Spotify</a>', $spotify_url );
		}

		/**
		 * Skype call link and WhatsApp chat links seem way to private, like phone and email. Not adding appending these to desription for now. Not saving them anywhere, actually.
		 */
		// Skype.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$skype_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_skype';", $guest_id ) );
		if ( $skype_link ) {
			$skype_link;
		}
		// WhatsApp.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$whatsapp_chat_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_whatsapp';", $guest_id ) );
		if ( $whatsapp_chat_link ) {
			$whatsapp_chat_link;
		}

		/**
		 * Other Molongui meta fields to be appended to description/bio, if the display box is checked.
		 */
		// Mail.
		// phpcs:disable -- $wpdb->prepare is used.
		$show_mail = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_show_meta_mail';", $guest_id ) );
		$mail      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_mail';", $guest_id ) );
		// phpcs:enable
		if ( $mail && $show_mail ) {
			$htmls_append_to_bio[] = sprintf( '<a href="mailto:%s">%s</a>', $mail, $mail );
		}
		// Phone.
		// phpcs:disable -- $wpdb->prepare is used.
		$show_phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_show_meta_phone';", $guest_id ) );
		$phone      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}postmeta where post_id = %d and meta_key = '_molongui_guest_author_phone';", $guest_id ) );
		// phpcs:enable
		if ( $mail && $show_phone ) {
			$htmls_append_to_bio[] = sprintf( 'Phone: %s', $phone );
		}

		// Append $htmls_append_to_bio to description.
		foreach ( $htmls_append_to_bio as $key => $html ) {
			// Add a delimiter to $description first.
			if ( 0 == $key && ! empty( $description ) ) {
				$description .= "\n";
			} elseif ( $key > 0 ) {
				$description .= ' ';
			}

			$description .= $html . '.';
		}

		// Update description.
		if ( $description ) {
			$cap_args['description'] = $description;
		}

		return $cap_args;
	}

	/**
	 * Get CAP creation args from Molongui WP_User.
	 * Converts all Molongui WP_User meta to CAP meta.
	 *
	 * @param string $table_prefix_mologui Mologui data table prefix.
	 * @param int    $wpuser_id WP_User ID.
	 *
	 * @return array CAP creation args, see \NewspackCustomContentMigrator\Logic\CoAuthorPlus::create_guest_author().
	 *
	 * @throws \UnexpectedValueException If WP_User with $wpuser_id not found.
	 */
	public function get_cap_creation_args_for_mologui_wpuser( string $table_prefix_mologui, int $wpuser_id ): array {
		global $wpdb;

		// phpcs:disable -- Allow querying users table.
		$wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from {$table_prefix_mologui}users where ID = %d;", $wpuser_id ), ARRAY_A );
		// phpcs:enable
		if ( ! $wpuser_row ) {
			throw new \UnexpectedValueException( sprintf( 'WP_User with ID %d not found.', $wpuser_id ) );
		}

		// Main CAP GA creation args.
		$cap_args = [
			'display_name' => $wpuser_row['display_name'],
			'user_email'   => $wpuser_row['user_email'],
		];

		// HTML that will be appended to bio/description.
		$htmls_append_to_bio = [];

		// Basic info.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$first_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'first_name';", $wpuser_id ) );
		if ( $first_name ) {
			$cap_args['first_name'] = $first_name;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$last_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'last_name';", $wpuser_id ) );
		if ( $last_name ) {
			$cap_args['last_name'] = $last_name;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$description = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'description';", $wpuser_id ) );
		if ( $description ) {
			$cap_args['description'] = $description;
		}
		// phpcs:Ignore -- $wpdb->prepare is used.
		$avatar = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_image_id';", $wpuser_id ) );
		if ( $avatar ) {
			$cap_args['avatar'] = $avatar;
		}

		/**
		 * Newspack usermeta/postmeta fields...
		 *      'newspack_job_title'
		 *      'newspack_role'
		 *      'newspack_employer'
		 *      'newspack_phone_number'
		 * ... and their corresponding Molongui usermeta fields:
		 *      'molongui_author_job'
		 *      'molongui_author_company'
		 *      'molongui_author_company_link'
		 *
		 * Merge these like this: if the Newspack usermeta is empty, try and load up Molongui's equivalent.
		 *
		 * Newspack Plugin checks both if usermeta or postmeta called like this exist, and use them, but it checks for usermeta
		 * first and only if it's empty also checks for postmeta. So we will save these as usermeta.
		 */
		// Job meta.
		// phpcs:disable -- $wpdb->prepare is used.
		$newspack_job_title  = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'newspack_job_title';", $wpuser_id ) );
		$molongui_author_job = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_job';", $wpuser_id ) );
		// phpcs:enable
		if ( ! $newspack_job_title && $molongui_author_job ) {
			update_user_meta( $wpuser_id, 'newspack_job_title', $molongui_author_job );
		}
		// Employer/company meta.
		// phpcs:disable -- $wpdb->prepare is used.
		$newspack_employer       = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'newspack_employer';", $wpuser_id ) );
		$molongui_author_company = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_company';", $wpuser_id ) );
		// phpcs:enable
		if ( ! $newspack_employer && $molongui_author_company ) {
			update_user_meta( $wpuser_id, 'newspack_employer', $molongui_author_company );
		}
		// Phone meta.
		// phpcs:disable -- $wpdb->prepare is used.
		$newspack_phone_number = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'newspack_phone_number';", $wpuser_id ) );
		$phone                 = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		// phpcs:enable
		if ( ! $newspack_phone_number && $phone ) {
			update_user_meta( $wpuser_id, 'newspack_phone_number', $phone );
		}

		/**
		 * Other Molongui usermeta fields to be appended to description/bio.
		 */
		// Mail.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$show_mail = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_show_meta_mail';", $wpuser_id ) );
		if ( $wpuser_row['user_email'] && $show_mail ) {
			$htmls_append_to_bio[] = sprintf( '<a href="mailto:%s">%s</a>', $wpuser_row['user_email'], $wpuser_row['user_email'] );
		}
		// Phone.
		// phpcs:disable -- $wpdb->prepare is used.
		$show_phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_show_meta_phone';", $wpuser_id ) );
		$phone      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		// phpcs:enable
		if ( $show_phone && $phone ) {
			$htmls_append_to_bio[] = sprintf( 'Phone: %s', $phone );
		}

		/**
		 * Molongui social sites usermeta fields, also appended to bio.
		 */
		// Wiki.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$wikipedia_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'wikipedia';", $wpuser_id ) );
		if ( $wikipedia_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Wikipedia</a>', $wikipedia_url );
		}
		// LI.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$linkedin_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'linkedin';", $wpuser_id ) );
		if ( $linkedin_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">LinkedIn</a>', $linkedin_url );
		}
		// FB.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'facebook';", $wpuser_id ) );
		if ( ! $facebook_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_facebook';", $wpuser_id ) );
		}
		if ( $facebook_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Facebook</a>', $facebook_url );
		}
		// IG.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'instagram';", $wpuser_id ) );
		if ( ! $instagram_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_instagram';", $wpuser_id ) );
		}
		if ( $instagram_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Instagram</a>', $instagram_url );
		}
		// Twitter.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$twitter_with_at = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'twitter';", $wpuser_id ) );
		// 'twitter' usermeta must begin with "@". Get handle from it.
		$twitter_handle = null;
		if ( $twitter_with_at && '@' == substr( $twitter_with_at, 0, 1 ) ) {
			$twitter_handle        = substr( $twitter_with_at, 1 );
			$htmls_append_to_bio[] = sprintf( '<a href="https://twitter.com/%s" target="_blank">Twitter</a>', $twitter_handle );
		}
		if ( ! $twitter_handle ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$twitter_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_twitter';", $wpuser_id ) );
			if ( $twitter_url ) {
				$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Twitter</a>', $twitter_url );
			}
		}
		// Tumblr.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'tumblr';", $wpuser_id ) );
		if ( ! $tumblr_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_tumblr';", $wpuser_id ) );
		}
		if ( $tumblr_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Tumblr</a>', $tumblr_url );
		}
		// YT.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'youtube';", $wpuser_id ) );
		if ( ! $youtube_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_youtube';", $wpuser_id ) );
		}
		if ( $youtube_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">YouTube</a>', $youtube_url );
		}
		// Medium.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$medium_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_medium';", $wpuser_id ) );
		if ( $medium_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Medium</a>', $medium_url );
		}
		// Pinterest.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'pinterest';", $wpuser_id ) );
		if ( ! $pininterest_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_pinterest';", $wpuser_id ) );
		}
		if ( $pininterest_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Pinterest</a>', $pininterest_url );
		}
		// Soundcloud.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'soundcloud';", $wpuser_id ) );
		if ( ! $soundcloud_url ) {
			// phpcs:Ignore -- $wpdb->prepare is used.
			$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_soundcloud';", $wpuser_id ) );
		}
		if ( $soundcloud_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Soundcloud</a>', $soundcloud_url );
		}
		// Spotify.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$spotify_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_spotify';", $wpuser_id ) );
		if ( $spotify_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Spotify</a>', $spotify_url );
		}
		// Myspace.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$myspace_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'myspace';", $wpuser_id ) );
		if ( $myspace_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Myspace</a>', $myspace_url );
		}

		/**
		 * Skype call link and WhatsApp chat links seem way to private, like phone and email. Not adding appending these to desription for now. Not saving them anywhere, actually.
		 */
		// Skype.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$skype_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_skype';", $wpuser_id ) );
		if ( $skype_link ) {
			$skype_link;
		}

		// WhatsApp.
		// phpcs:Ignore -- $wpdb->prepare is used.
		$whatsapp_chat_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$table_prefix_mologui}usermeta where user_id = %d and meta_key = 'molongui_author_whatsapp';", $wpuser_id ) );
		if ( $whatsapp_chat_link ) {
			$whatsapp_chat_link;
		}

		// Append $htmls_append_to_bio to description.
		foreach ( $htmls_append_to_bio as $key => $html ) {
			// Add a delimiter to $description first.
			if ( 0 == $key && ! empty( $description ) ) {
				$description .= "\n";
			} elseif ( $key > 0 ) {
				$description .= ' ';
			}

			$description .= $html . '.';
		}

		// Update description.
		if ( $description ) {
			$cap_args['description'] = $description;
		}

		return $cap_args;
	}
}
