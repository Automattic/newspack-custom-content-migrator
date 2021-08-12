<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for OurWeekly.
 */
class OurWeeklyMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator our-weekly-authors',
			[ $this, 'cmd_update_authors' ],
			[
				'shortdesc' => 'Updates OurWeekly authors. The import contains only slugs, and some are too long.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator our-weekly-settings',
			[ $this, 'cmd_set_settings' ],
			[
				'shortdesc' => 'Updates OurWeekly site settings..',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Update display name of user based on a login.
	 *
	 * @param WP_User $user User.
	 * @param string  $login_override Login override,
	 */
	private static function update_user_display_name( $user, $login_override = null ) {
		$login        = null === $login_override ? $user->user_login : $login_override;
		$display_name = ucwords( str_replace( '-', ' ', $login ) );
		$update       = wp_update_user(
			[
				'ID'           => $user->ID,
				'display_name' => $display_name,
			]
		);
		if ( is_wp_error( $update ) ) {
			WP_CLI::error( sprintf( 'Could not update user with login %s.', $imported_login ) );
		} else {
			WP_CLI::success( sprintf( '%s was updated %s.', $user->user_login, $display_name ) );
		}
	}

	/**
	 * Updates OurWeekly authors. The import contains only slugs, and some are too long.
	 * This will set display names for all users, taking into account
	 */
	public function cmd_update_authors() {
		// The logins in the export files are in some cases too long (WP has a limit of 60 chars).
		// For this reason, before import, they were clipped to 60 chars.
		// This is the list of the too-long logins, created by running:
		// $ find . -name '*.xml' | xargs  egrep '<dc:creator>(\w|\d|-){60,}' > too-long-logins.txt
		// in the export WXRs directory.
		$too_long_logins = array_unique(
			[
				'mckenzie-jackson-and-madlen-grgodjaian-california-black-media',
				'johnny-c-taylor-jr-president-ceo-thurgood-marshall-college-fund',
				'reverend-al-sharpton-national-action-network-and-dr-benjamin-f-chavis-jr-nnpa',
				'reverend-al-sharpton-national-action-network-and-dr-benjamin-f-chavis-jr-national-newspaper-publishers-association',
				'by-earl-skip-cooper-ii-president-ceo-of-the-black-business-association-editor-publisher-of-the-black-business-news-group',
				'dr-john-e-warren-intergovernmental-affairs-contributor-to-nnpa',
				'thomas-p-kimbis-executive-vp-general-counsel-solar-energy-industries-association',
				'rep-cedric-l-richmond-d-la-02-chairman-congressional-black-caucus',
				'california-assemblymembers-monique-limon-sebastian-ridley-thomas',
				'michelle-andrews-and-barbara-feder-ostrov-california-healthline',
				'by-imani-fox-and-kishana-flenory-special-to-the-trice-edney-news-wire-from-howard-university-news-servic',
				'by-freddie-allen-nnpa-news-wire-senior-washington-correspondent',
				'by-todd-luck-special-to-the-nnpa-news-wire-from-the-winston-salem-chronicle',
				'by-zenitha-prince-special-to-the-trice-edney-news-wire-from-the-afro-american-newspaper',
				'by-freddie-allen-nnpa-news-wire-senior-washington-correspondent',
				'by-jarvis-stewart-special-to-the-nnpa-news-wire-from-irmedia',
				'by-don-terry-special-to-the-nnpa-news-wire-from-the-rainbow-push-coalition',
				'by-jeffrey-l-boney-special-to-the-nnpa-news-wire-from-the-houston-forward-times',
				'by-chris-b-bennett-special-to-the-nnpa-news-wire-from-the-seattle-medium',
				'by-aubry-stone-presidentceo-california-black-chamber-of-commerce',
				'by-freddie-allen-senior-washington-correspondent-nnpa-news-wire',
				'by-j-coyden-palmer-special-to-the-nnpa-news-wire-from-the-chicago-crusader',
				'isabell-rivera-and-merdies-hayes-ow-contributor-and-managing-editor',
				'isabell-rivera-and-merdies-hayes-ow-contributor-and-managing-editor',
				'by-taylor-a-sylvain-according-to-published-reports-new-orleans-native-desiree-glapion-rogers-and-her-business-partner-cheryl-mayberry-mckissack-will-purchase-the-iconic-fashion-new-orleans-agenda',
				'special-from-california-black-media-contributor-charlene-muhammad',
				'dr-benjamin-f-chavis-jr-national-newspaper-publishers-association',
				'jay-king-president-and-ceo-california-black-chamber-of-commerce',
				'rachana-pradhan-lauren-weber-and-liz-szabo-kaiser-health-news',
				'by-margaret-fortune-secretary-treasure-of-california-state-national-action-network-and-joanne-ahola-president-of-the-sacramento-county-board-of-education',
				'seema-verma-administrator-centers-for-medicare-medicaid-services',
				'by-edward-henderson-san-diego-voice-and-viewpointnnpa-member',
				'reverend-jesse-jackson-sr-president-and-founderrainbow-push-coalition',
				'by-freddie-allen-nnpa-senior-washington-correspondent-by-freddie-allen-nnpa-senior-washington-correspondent',
				'dr-robert-k-ross-president-and-ceo-of-the-california-endowment',
				'by-manny-otiko-and-antonio-ray-harvey-california-black-media',
				'by-stacy-m-brown-nnpa-newswire-senior-national-correspondent',
				'by-nsenga-k-burton-phd-nnpa-newswire-culture-and-entertainment-editor',
				'by-dr-elaine-batchlor-ceo-the-new-martin-luther-king-jr-hospital',
				'harry-c-alford-kay-debowwe-cant-believe-what-the-democratic-party-is-trying-to-do-they-want-to-throw-out-a-democratically-elected-president-simply-for-political-gain-the-vehicle-they-are-trying',
				'pastor-william-smart-president-southern-christian-leadership-conference-southern-california-chapter',
				'dr-benjamin-f-chavis-jr-president-and-ceo-national-newspaper-publishers-association',
				'mac-shorty-founder-of-community-repower-movement-and-a-former-methodist-minister',
				'barbara-feder-ostrov-and-anna-b-ibarra-kaiser-family-foundation',
				'carol-mcgruder-co-chair-african-american-tobacco-control-leadership-council',
				'political-discourse-reaches-historic-low-among-nations-elected-leaders',
				'tiffany-dena-loftin-director-naacp-youth-and-college-division',
				'dr-raegan-mcdonald-mosley-chief-medical-officer-planned-parenthood-of-maryland',
			]
		);

		// Update display names for all users.
		$all_users = get_users();
		foreach ( $all_users as $user ) {
			self::update_user_display_name( $user );
		}

		// For the users with clipped logins, find the full login and create the display name
		// based on that.
		foreach ( $too_long_logins as $login ) {
			$imported_login = substr( $login, 0, 60 );
			$found_user     = get_user_by( 'login', $imported_login );
			if ( false === $found_user ) {
				WP_CLI::error( sprintf( 'Could not find user with login %s.', $imported_login ) );
			} else {
				self::update_user_display_name( $found_user, $login );
			}
		}
	}

	/**
	 * Update settings for OurWeekly.
	 */
	public static function cmd_set_settings() {
		// Update permalink structure. The permalink structure is:
		// /news/<year>/<month-abbreviation>/<date>/<slug>
		// e.g.:
		// /news/2021/aug/06/changing-definition-success/
		// WP does not have what here is called month-abbreviation tag, but monthnum is close enough for a
		// redirect to be performed.
		WP_CLI::line( 'Updating permalink structure.' );
		$wp_rewrite = new \WP_Rewrite();
		$wp_rewrite->set_permalink_structure( '/news/%year%/%monthnum%/%day%/%postname%/' );

		// Set site title & description.
		update_option( 'blogname', 'Our Weekly' );
		update_option( 'blogdescription', 'Black News and Entertainment Los Angeles' );

		// Update admin display name.
		wp_update_user(
			[
				'ID'           => 1, 
				'display_name' => 'Our Weekly Staff',
			] 
		);

		// Install Newspack.
		$plugins = get_plugins();
		if ( isset( $plugins['newspack-plugin/newspack.php'] ) ) {
			WP_CLI::line( 'Newspack Plugin is already installed.' );
		} else {
			WP_CLI::line( 'Installing Newspack Plugin...' );
			// Install Newspack.
			$newspack_zip = 'https://github.com/Automattic/newspack-plugin/releases/download/v1.51.1/newspack-plugin.zip';
			$is_installed = WP_CLI::runcommand( "plugin install $newspack_zip --activate" );
		}

		$themes = wp_get_themes();
		if ( isset( $themes['newspack-theme'] ) ) {
			WP_CLI::line( 'Newspack Theme is already installed.' );
		} else {
			WP_CLI::line( 'Installing Newspack Theme...' );
			// Install Newspack theme.
			$newspack_zip = 'https://github.com/Automattic/newspack-theme/releases/download/v1.43.0/newspack-theme.zip';
			$is_installed = WP_CLI::runcommand( "theme install $newspack_zip --activate" );
		}
	}
}
