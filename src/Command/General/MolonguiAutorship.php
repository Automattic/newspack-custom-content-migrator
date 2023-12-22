<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

class MolonguiAutorship implements InterfaceCommand {

	const POSTMETA_ORIGINAL_MOLOGUI_USER = 'newspack_molongui_original_user';

	/**
	 * @var null|self
	 */
	private static $instance = null;

	/**
	 * @var Posts
	 */
	private $posts;

	/**
	 * @var CoAuthorPlus
	 */
	private $cap;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts  = new Posts();
		$this->cap  = new CoAuthorPlus();
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
			self::$instance = new $class;
		}

		return self::$instance;
	}

	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator molongui-to-cap',
			[ $this, 'cmd_molongui_to_cap' ],
			[
				'shortdesc' => 'Converts Molongui authorship to CAP.',
			]
		);
	}

	public function cmd_molongui_to_cap( $pos_args, $assoc_args ) {
		global $wpdb;

		$log_error = 'molongui-to-cap_ERR.txt';

		$authors_molongui_values = $wpdb->get_col( "select distinct meta_value from wp_postmeta where meta_key = '_molongui_author';" );
		if ( ! $authors_molongui_values ) {
			WP_CLI::warning( 'No authors found.' );
			return;
		}

		/**

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
				$wpuser_id = (int) str_replace( 'user-', '', $author_molongui_value );
				$author_wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from wp_users where ID = %d;", $wpuser_id ) );
				if ( ! $author_wpuser_row ) {
					WP_CLI::error( sprintf( 'WP_User with ID %d not found (postmeta key: user-%s).', $wpuser_id, $wpuser_id ) );
				}

				// Create CAP GA.
				$cap_args = $this->get_cap_creation_args_for_mologui_wpuser( $wpuser_id );
				$cap_id = $this->cap->create_guest_author( $cap_args );
				if ( is_wp_error( $cap_id ) ) {
					$msg = sprintf( 'Error creating CAP GA for Molongui user %s: %s', 'user-' . $wpuser_id, $cap_id->get_error_message() );
					$this->logger->log( $log_error, $msg, $this->logger::ERROR, false );
					continue;
				}

				// Save custom postmeta to GA saying which Molongui user this was.
				update_post_meta( $cap_id, self::POSTMETA_ORIGINAL_MOLOGUI_USER, $author_molongui_value );

				WP_CLI::success( sprintf( "Created GA ID %s for Molongui user %s.", $cap_id, 'user-' . $wpuser_id ) );

			} elseif ( 0 === strpos( $author_molongui_value, 'guest-' ) ) {

				/**
				 * Molongui uses its own Guest type user.
				 * In this case, the postmeta key_value is 'guest-{ID}' (where meta_key = '_molongui_author').
				 */

				$guest_id = (int) str_replace( 'guest-', '', $author_molongui_value );
				$author_guest_row = $wpdb->get_row( $wpdb->prepare( "select * from wp_posts where ID = %d and post_type = 'guest_author';", $guest_id ) );
				if ( ! $author_guest_row ) {
					WP_CLI::error( sprintf( 'Guest author with ID %d not found (postmeta key: guest-%s).', $guest_id, $guest_id ) );
				}

				// Create CAP GA.

				// Save postmeta to CAP GA saying which Molongui user this is.

			} else {
				WP_CLI::error( sprintf( 'Unsupported Molongui author postmeta key: %s. Add support for this type then rerun command.', $author_molongui_value ) );
			}
		}

		/**
		 * Assign GAs to posts.
		 */

		WP_CLI::line( 'Done.' );
	}

	/**
	 * Get CAP creation args from Molongui WP_User.
	 * Converts all Molongui WP_User meta to CAP meta.
	 *
	 * @param int $wpuser_id WP_User ID.
	 *
	 * @return array CAP creation args, see \NewspackCustomContentMigrator\Logic\CoAuthorPlus::create_guest_author().
	 */
	public function get_cap_creation_args_for_mologui_wpuser( int $wpuser_id ): array {
		global $wpdb;

		$author_wpuser_row = $wpdb->get_row( $wpdb->prepare( "select * from wp_users where ID = %d;", $wpuser_id ), ARRAY_A );

		$cap_args = [
			'display_name' => $author_wpuser_row['display_name'],
			'user_email' => $author_wpuser_row['user_email'],
		];

		$first_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'first_name';", $wpuser_id ) );
		if ( $first_name ) {
			$cap_args['first_name'] = $first_name;
		}

		$last_name = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'last_name';", $wpuser_id ) );
		if ( $last_name ) {
			$cap_args['last_name'] = $last_name;
		}

		$description = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'description';", $wpuser_id ) );
		if ( $description ) {
			$cap_args['description'] = $description;
		}

		$avatar = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_image_id';", $wpuser_id ) );
		if ( $avatar ) {
			$cap_args['avatar'] = $avatar;
		}

		/**
		 * Newspack usermeta fields...
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
		$newspack_job_title = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'newspack_job_title';", $wpuser_id ) );
		$molongui_author_job = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_job';", $wpuser_id ) );
		if ( ! $newspack_job_title && $molongui_author_job ) {
			update_user_meta( $wpuser_id, 'newspack_job_title', $molongui_author_job );
		}

		// Employer/company meta.
		$newspack_employer = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'newspack_employer';", $wpuser_id ) );
		$molongui_author_company = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_company';", $wpuser_id ) );
		if ( ! $newspack_employer && $molongui_author_company ) {
			update_user_meta( $wpuser_id, 'newspack_employer', $molongui_author_company );
		}

		// Phone meta.
		$newspack_phone_number = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'newspack_phone_number';", $wpuser_id ) );
		$phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		if ( ! $newspack_phone_number && $phone ) {
			update_user_meta( $wpuser_id, 'newspack_phone_number', $phone );
		}

		/**
		 * Other Molongui usermeta fields to be appended to description/bio.
		 */
		$htmls_append_to_bio = [];

		// Mail.
		$show_mail = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_show_meta_mail';", $wpuser_id ) );
		if ( $show_mail ) {
			$htmls_append_to_bio[] = sprintf( '<a href="mailto:%s">%s</a>', $author_wpuser_row['user_email'], $author_wpuser_row['user_email'] );
		}

		// Phone.
		$show_phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_show_meta_phone';", $wpuser_id ) );
		$phone = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_phone';", $wpuser_id ) );
		if ( $show_phone && $phone ) {
			$htmls_append_to_bio[] = sprintf( 'Phone: %s', $phone );
		}

		/**
		 * Molongui social sites usermeta fields, also appended to bio.
		 */

		// FB.
		$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'facebook';", $wpuser_id ) );
		if ( ! $facebook_url ) {
			$facebook_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_facebook';", $wpuser_id ) );
		}
		if ( $facebook_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Facebook" target="_blank">%s</a>', $facebook_url );
		}

		// IG.
		$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'instagram';", $wpuser_id ) );
		if ( ! $instagram_url ) {
			$instagram_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_instagram';", $wpuser_id ) );
		}
		if ( $instagram_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Instagram" target="_blank">%s</a>', $instagram_url );
		}

		// LI.
		$linkedin_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'linkedin';", $wpuser_id ) );
		if ( $linkedin_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="LinkedIn" target="_blank">%s</a>', $linkedin_url );
		}

		// Myspace.
		$myspace_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'myspace';", $wpuser_id ) );
		if ( $myspace_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Myspace" target="_blank">%s</a>', $myspace_url );
		}

		// Pinterest.
		$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'pinterest';", $wpuser_id ) );
		if ( ! $pininterest_url ) {
			$pininterest_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_pinterest';", $wpuser_id ) );
		}
		if ( $pininterest_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Pinterest" target="_blank">%s</a>', $pininterest_url );
		}

		// Medium.
		$medium_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_medium';", $wpuser_id ) );
		if ( $medium_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Medium" target="_blank">%s</a>', $medium_url );
		}

		// Soundcloud.
		$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'soundcloud';", $wpuser_id ) );
		if ( ! $soundcloud_url ) {
			$soundcloud_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_soundcloud';", $wpuser_id ) );
		}
		if ( $soundcloud_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Soundcloud" target="_blank">%s</a>', $soundcloud_url );
		}

		// Tumblr.
		$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'tumblr';", $wpuser_id ) );
		if ( ! $tumblr_url ) {
			$tumblr_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_tumblr';", $wpuser_id ) );
		}
		if ( $tumblr_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Tumblr" target="_blank">%s</a>', $tumblr_url );
		}

		// Twitter.
		$twitter_with_at = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'twitter';", $wpuser_id ) );
		// 'twitter' usermeta must begin with "@". Get handle from it.
		$twitter_handle = null;
		if ( $twitter_with_at && "@" == substr( $twitter_with_at, 0, 1 ) ) {
			$twitter_handle = substr( $twitter_with_at, 1 );
			$htmls_append_to_bio[] = sprintf( '<a href="Twitter" target="_blank">https://twitter.com/%s</a>', $twitter_handle );
		}
		if ( ! $twitter_handle ) {
			$twitter_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_twitter';", $wpuser_id ) );
			if ( $twitter_url ) {
				$htmls_append_to_bio[] = sprintf( '<a href="Twitter" target="_blank">%s</a>', $twitter_url );
			}
		}

		// YT.
		$youtube_url =  $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'youtube';", $wpuser_id ) );
		if ( ! $youtube_url ) {
			$youtube_url =  $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_youtube';", $wpuser_id ) );
		}
		if ( $youtube_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="YouTube" target="_blank">%s</a>', $youtube_url );
		}

		// Wiki.
		$wikipedia_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'wikipedia';", $wpuser_id ) );
		if ( $wikipedia_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Wikipedia" target="_blank">%s</a>', $wikipedia_url );
		}

		// Spotify.
		$spotify_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_spotify';", $wpuser_id ) );
		if ( $spotify_url ) {
			$htmls_append_to_bio[] = sprintf( '<a href="Spotify" target="_blank">%s</a>', $spotify_url );
		}

		/**
		 * Skype call link and WhatsApp chat links seem way to private, like phone and email. Not adding appending these to desription for now. Not saving them anywhere, actually.
		 */
		// Skype.
		$skype_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_skype';", $wpuser_id ) );
		if ( $skype_link ) {
			$skype_link;
		}

		// WhatsApp.
		$whatsapp_chat_link = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_usermeta where user_id = %d and meta_key = 'molongui_author_whatsapp';", $wpuser_id ) );
		if ( $whatsapp_chat_link ) {
			$whatsapp_chat_link;
		}

		// Append $htmls_append_to_bio to
		foreach ( $htmls_append_to_bio as $key => $html ) {
			// Add a delimiter to $description first.
			if ( 0 == $key && ! empty( $description ) ) {
				$description .= "\n<br>";
			} elseif ( $key > 0 ) {
				$description .= ". ";
			}

			$description .= $html;
		}

		// Update description
		if ( $description ) {
			$cap_args['description'] = $description;
		}

		return $cap_args;
	}
}
