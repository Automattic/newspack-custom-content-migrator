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
			]
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

		$log_error = 'molongui-to-cap_ERR.txt';

		// Get Molongui authors.
		$authors_molongui_values = $wpdb->get_col( "select distinct meta_value from wp_postmeta where meta_key = '_molongui_author';" );
		if ( ! $authors_molongui_values ) {
			WP_CLI::warning( 'No authors found.' );
			return;
		}

		/**
		 * Create GAs.
		 */
		foreach ( $authors_molongui_values as $key_author_molongui_value => $author_molongui_value ) {
			WP_CLI::line( sprintf( '%d/%d %s', $key_author_molongui_value + 1, count( $authors_molongui_values ), $author_molongui_value ) );

			/**
			 * Convert different Mologui user types to CAP GAs.
			 */
			if ( 0 === strpos( $author_molongui_value, 'user-' ) ) {

				/**
				 * Mologui uses an existing WP_User which it extends with custom meta.
				 * In this case, the postmeta key_value is 'user-{ID}' (where meta_key = '_molongui_author').
				 */
				$wpuser_id         = (int) str_replace( 'user-', '', $author_molongui_value );
				// phpcs:disable -- Allow querying users table.
				$author_wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->users} where ID = %d;", $wpuser_id ), ARRAY_A );
				// phpcs:enable
				if ( ! $author_wpuser_row ) {
					WP_CLI::error( sprintf( 'WP_User with ID %d not found (postmeta key: user-%s).', $wpuser_id, $wpuser_id ) );
				}

				// Create CAP GA.
				$cap_args = $this->get_cap_creation_args_for_mologui_wpuser( $wpuser_id );
				$cap_id   = $this->cap->create_guest_author( $cap_args );
				if ( is_wp_error( $cap_id ) ) {
					$msg = sprintf( 'Error creating CAP GA for Molongui user %s: %s', 'user-' . $wpuser_id, $cap_id->get_error_message() );
					$this->logger->log( $log_error, $msg, $this->logger::ERROR, false );
					continue;
				}

				// Save custom postmeta to GA saying which Molongui user this was.
				update_post_meta( $cap_id, self::POSTMETA_ORIGINAL_MOLOGUI_USER, $author_molongui_value );

				WP_CLI::success( sprintf( "Created GA ID %s from '%s' %s", $cap_id, 'user-' . $wpuser_id, $cap_args['display_name'] ) );

			} elseif ( 0 === strpos( $author_molongui_value, 'guest-' ) ) {

				/**
				 * Molongui uses its own Guest type user.
				 * In this case, the postmeta key_value is 'guest-{ID}' (where meta_key = '_molongui_author').
				 */
				$guest_id  = (int) str_replace( 'guest-', '', $author_molongui_value );
				$guest_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d and post_type = 'guest_author';", $guest_id ), ARRAY_A );
				if ( ! $guest_row ) {
					WP_CLI::error( sprintf( 'Guest author with ID %d not found (postmeta key: guest-%s).', $guest_id, $guest_id ) );
				}

				// Create CAP GA.
				$cap_args = $this->get_cap_creation_args_for_mologui_guestauthor( $guest_id );
				$cap_id   = $this->cap->create_guest_author( $cap_args );
				if ( is_wp_error( $cap_id ) ) {
					$msg = sprintf( 'Error creating CAP GA for Molongui user %s: %s', 'user-' . $wpuser_id, $cap_id->get_error_message() );
					$this->logger->log( $log_error, $msg, $this->logger::ERROR, false );
					continue;
				}

				// Save custom postmeta to GA saying which Molongui user this was.
				update_post_meta( $cap_id, self::POSTMETA_ORIGINAL_MOLOGUI_USER, $author_molongui_value );

				WP_CLI::success( sprintf( "Created GA ID %s from '%s' %s", $cap_id, 'guest-' . $guest_id, $cap_args['display_name'] ) );

			} else {
				WP_CLI::error( sprintf( 'Unsupported Molongui author postmeta key: %s. Add support for this type then rerun command.', $cap_args['display_name'] ) );
			}
		}


		/**
		 * Assign GAs to posts.
		 */
		$post_ids                         = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
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
				$molongui_author = $molongui_author_row['meta_value'];

				// Get GA IDs for this Mologui author.
				if ( isset( $cached_mologui_authors_to_ga_ids[ $molongui_author ] ) ) {
					$ga_id = $cached_mologui_authors_to_ga_ids[ $molongui_author ];
				} else {
					$ga_id = $wpdb->get_var( $wpdb->prepare( "select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s;", self::POSTMETA_ORIGINAL_MOLOGUI_USER, $molongui_author ) );
					if ( ! $ga_id ) {
						$msg = sprintf( 'Error fetching GA for Molongui user %s and assigning it to Post ID %d', $molongui_author, $post_id );
						$this->logger->log( $log_error, $msg, $this->logger::ERROR, false );
						continue;
					}
					$cached_mologui_authors_to_ga_ids[ $molongui_author ] = $ga_id;
				}

				$ga_ids[] = $ga_id;
			}

			// Assign GAs to Post.
			$this->cap->assign_guest_authors_to_post( $ga_ids, $post_id, false );
		}

		if ( file_exists( $log_error ) ) {
			WP_CLI::warning( sprintf( 'There were errors. See %s.', $log_error ) );
		}

		WP_CLI::line( 'Done.' );
		wp_cache_flush();
	}

	/**
	 * Get CAP creation args from Molongui guest author.
	 * Converts all Molongui Guest author meta to CAP meta.
	 *
	 * @param int $guest_id Mologui guest author ID.
	 *
	 * @return array CAP creation args, see \NewspackCustomContentMigrator\Logic\CoAuthorPlus::create_guest_author().
	 *
	 * @throws \UnexpectedValueException If Mologui guest author with $guest_id not found.
	 */
	public function get_cap_creation_args_for_mologui_guestauthor( int $guest_id ): array {
		global $wpdb;

		$author_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d and post_type = 'guest_author';", $guest_id ), ARRAY_A );
		if ( ! $author_row ) {
			throw new \UnexpectedValueException( sprintf( 'Mologui guest user with ID %d not found.', $guest_id ) );
		}

		// Main CAP GA creation args.
		$cap_args = [];

		// Basic info.
		$display_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_display_name';", $guest_id ) );
		if ( $display_name ) {
			$cap_args['display_name'] = $display_name;
		}
		$first_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_first_name';", $guest_id ) );
		if ( $first_name ) {
			$cap_args['first_name'] = $first_name;
		}
		$last_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_last_name';", $guest_id ) );
		if ( $last_name ) {
			$cap_args['last_name'] = $last_name;
		}
		$email = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_mail';", $guest_id ) );
		if ( $email ) {
			$cap_args['user_email'] = $email;
		}
		$description = $wpdb->get_var( $wpdb->prepare( "select post_content from {$wpdb->posts} where ID = %d;", $guest_id ) );
		if ( $description ) {
			$cap_args['description'] = $description;
		}
		$avatar = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_thumbnail_id';", $guest_id ) );
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
		 * ... and their corresponding Molongui usermeta fields:
		 *      _molongui_guest_author_web
		 *      _molongui_guest_author_job
		 *      _molongui_guest_author_company
		 *      _molongui_guest_author_company_link
		 *      _molongui_guest_author_box_display
		 *
		 * Save these as Newspack postmeta.
		 */
		// Author web -- append to description/bio.
		$authorweb_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_web';", $guest_id ) );
		if ( $authorweb_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Web</a>', $authorweb_url );
		}
		// Job meta.
		$molongui_guest_author_job = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = '_molongui_guest_author_job';", $guest_id ) );
		if ( $molongui_guest_author_job ) {
			update_post_meta( $guest_id, 'newspack_job_title', $molongui_guest_author_job );
		}
		// Employer/company meta.
		$molongui_guest_author_company = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = '_molongui_guest_author_company';", $guest_id ) );
		if ( $molongui_guest_author_company ) {
			update_post_meta( $guest_id, 'newspack_employer', $molongui_guest_author_company );
		}

		/**
		 * Molongui social sites usermeta fields, also appended to bio.
		 */
		// FB.
		$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_facebook';", $guest_id ) );
		if ( $facebook_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Facebook</a>', $facebook_url );
		}
		// IG.
		$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_instagram';", $guest_id ) );
		if ( $instagram_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Instagram</a>', $instagram_url );
		}
		// Twitter.
		$twitter_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_twitter';", $guest_id ) );
		if ( $twitter_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Twitter</a>', $twitter_url );
		}
		// Tumblr.
		$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_tumblr';", $guest_id ) );
		if ( $tumblr_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Tumblr</a>', $tumblr_url );
		}
		// YouTube.
		$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_youtube';", $guest_id ) );
		if ( $youtube_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">YouTube</a>', $youtube_url );
		}
		// Medium.
		$medium_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_medium';", $guest_id ) );
		if ( $medium_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Medium</a>', $medium_url );
		}
		// Pinterest.
		$pinterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_pinterest';", $guest_id ) );
		if ( $pinterest_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Pinterest</a>', $pinterest_url );
		}
		// Soundcloud.
		$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_soundcloud';", $guest_id ) );
		if ( $soundcloud_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Soundcloud</a>', $soundcloud_url );
		}
		// Spotify.
		$spotify_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_spotify';", $guest_id ) );
		if ( $spotify_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Spotify</a>', $spotify_url );
		}

		/**
		 * Skype call link and WhatsApp chat links seem way to private, like phone and email. Not adding appending these to desription for now. Not saving them anywhere, actually.
		 */
		// Skype.
		$skype_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_skype';", $guest_id ) );
		if ( $skype_link ) {
			$skype_link;
		}
		// WhatsApp.
		$whatsapp_chat_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_whatsapp';", $guest_id ) );
		if ( $whatsapp_chat_link ) {
			$whatsapp_chat_link;
		}

		/**
		 * Other Molongui usermeta fields to be appended to description/bio, if the display box is checked.
		 */
		// Mail.
		$show_mail = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_show_meta_mail';", $guest_id ) );
		$mail      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_mail';", $guest_id ) );
		if ( $mail && $show_mail ) {
			$htmls_append_to_bio[] = sprintf( '<a href="mailto:%s">%s</a>', $mail, $mail );
		}
		// Phone.
		$show_phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_show_meta_phone';", $guest_id ) );
		$phone      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where post_id = %d and meta_key = '_molongui_guest_author_phone';", $guest_id ) );
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
	 * @param int $wpuser_id WP_User ID.
	 *
	 * @return array CAP creation args, see \NewspackCustomContentMigrator\Logic\CoAuthorPlus::create_guest_author().
	 *
	 * @throws \UnexpectedValueException If WP_User with $wpuser_id not found.
	 */
	public function get_cap_creation_args_for_mologui_wpuser( int $wpuser_id ): array {
		global $wpdb;

		// phpcs:disable -- Allow querying users table.
		$wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->users} where ID = %d;", $wpuser_id ), ARRAY_A );
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
		$first_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'first_name';", $wpuser_id ) );
		if ( $first_name ) {
			$cap_args['first_name'] = $first_name;
		}
		$last_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'last_name';", $wpuser_id ) );
		if ( $last_name ) {
			$cap_args['last_name'] = $last_name;
		}
		$description = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'description';", $wpuser_id ) );
		if ( $description ) {
			$cap_args['description'] = $description;
		}
		$avatar = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_image_id';", $wpuser_id ) );
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
		$newspack_job_title  = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'newspack_job_title';", $wpuser_id ) );
		$molongui_author_job = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_job';", $wpuser_id ) );
		if ( ! $newspack_job_title && $molongui_author_job ) {
			update_user_meta( $wpuser_id, 'newspack_job_title', $molongui_author_job );
		}
		// Employer/company meta.
		$newspack_employer       = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'newspack_employer';", $wpuser_id ) );
		$molongui_author_company = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_company';", $wpuser_id ) );
		if ( ! $newspack_employer && $molongui_author_company ) {
			update_user_meta( $wpuser_id, 'newspack_employer', $molongui_author_company );
		}
		// Phone meta.
		$newspack_phone_number = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'newspack_phone_number';", $wpuser_id ) );
		$phone                 = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		if ( ! $newspack_phone_number && $phone ) {
			update_user_meta( $wpuser_id, 'newspack_phone_number', $phone );
		}

		/**
		 * Other Molongui usermeta fields to be appended to description/bio.
		 */
		// Mail.
		$show_mail = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_show_meta_mail';", $wpuser_id ) );
		if ( $wpuser_row['user_email'] && $show_mail ) {
			$htmls_append_to_bio[] = sprintf( '<a href="mailto:%s">%s</a>', $wpuser_row['user_email'], $wpuser_row['user_email'] );
		}
		// Phone.
		$show_phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_show_meta_phone';", $wpuser_id ) );
		$phone      = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		if ( $show_phone && $phone ) {
			$htmls_append_to_bio[] = sprintf( 'Phone: %s', $phone );
		}

		/**
		 * Molongui social sites usermeta fields, also appended to bio.
		 */
		// Wiki.
		$wikipedia_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'wikipedia';", $wpuser_id ) );
		if ( $wikipedia_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Wikipedia</a>', $wikipedia_url );
		}
		// LI.
		$linkedin_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'linkedin';", $wpuser_id ) );
		if ( $linkedin_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">LinkedIn</a>', $linkedin_url );
		}
		// FB.
		$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'facebook';", $wpuser_id ) );
		if ( ! $facebook_url ) {
			$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_facebook';", $wpuser_id ) );
		}
		if ( $facebook_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Facebook</a>', $facebook_url );
		}
		// IG.
		$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'instagram';", $wpuser_id ) );
		if ( ! $instagram_url ) {
			$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_instagram';", $wpuser_id ) );
		}
		if ( $instagram_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Instagram</a>', $instagram_url );
		}
		// Twitter.
		$twitter_with_at = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'twitter';", $wpuser_id ) );
		// 'twitter' usermeta must begin with "@". Get handle from it.
		$twitter_handle = null;
		if ( $twitter_with_at && '@' == substr( $twitter_with_at, 0, 1 ) ) {
			$twitter_handle        = substr( $twitter_with_at, 1 );
			$htmls_append_to_bio[] = sprintf( '<a href="https://twitter.com/%s" target="_blank">Twitter</a>', $twitter_handle );
		}
		if ( ! $twitter_handle ) {
			$twitter_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_twitter';", $wpuser_id ) );
			if ( $twitter_url ) {
				$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Twitter</a>', $twitter_url );
			}
		}
		// Tumblr.
		$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'tumblr';", $wpuser_id ) );
		if ( ! $tumblr_url ) {
			$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_tumblr';", $wpuser_id ) );
		}
		if ( $tumblr_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Tumblr</a>', $tumblr_url );
		}
		// YT.
		$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'youtube';", $wpuser_id ) );
		if ( ! $youtube_url ) {
			$youtube_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_youtube';", $wpuser_id ) );
		}
		if ( $youtube_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">YouTube</a>', $youtube_url );
		}
		// Medium.
		$medium_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_medium';", $wpuser_id ) );
		if ( $medium_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Medium</a>', $medium_url );
		}
		// Pinterest.
		$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'pinterest';", $wpuser_id ) );
		if ( ! $pininterest_url ) {
			$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_pinterest';", $wpuser_id ) );
		}
		if ( $pininterest_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Pinterest</a>', $pininterest_url );
		}
		// Soundcloud.
		$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'soundcloud';", $wpuser_id ) );
		if ( ! $soundcloud_url ) {
			$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_soundcloud';", $wpuser_id ) );
		}
		if ( $soundcloud_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Soundcloud</a>', $soundcloud_url );
		}
		// Spotify.
		$spotify_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_spotify';", $wpuser_id ) );
		if ( $spotify_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Spotify</a>', $spotify_url );
		}
		// Myspace.
		$myspace_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'myspace';", $wpuser_id ) );
		if ( $myspace_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="%s" target="_blank">Myspace</a>', $myspace_url );
		}

		/**
		 * Skype call link and WhatsApp chat links seem way to private, like phone and email. Not adding appending these to desription for now. Not saving them anywhere, actually.
		 */
		// Skype.
		$skype_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_skype';", $wpuser_id ) );
		if ( $skype_link ) {
			$skype_link;
		}

		// WhatsApp.
		$whatsapp_chat_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->usermeta} where user_id = %d and meta_key = 'molongui_author_whatsapp';", $wpuser_id ) );
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
