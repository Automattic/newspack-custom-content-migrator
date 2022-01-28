<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class WashingtonMonthlyMigrator implements InterfaceMigrator {

	CONST PARENT_ISSUES_CATEGORY = 'Magazine';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator washingtonmonthly-transform-custom-taxonomies',
			[ $this, 'cmd_transform_taxonomies' ],
			[
				'shortdesc' => 'Transform custom taxonomies to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator washingtonmonthly-migrate-acf-authors-to-cap',
			[ $this, 'cmd_migrate_acf_authors_to_cap' ],
			[
				'shortdesc' => 'Migrates authors custom made with Advanced Custom Fields to Co-Authors Plus.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator washingtonmonthly-update-cap-twitter-handles-to-links',
			[ $this, 'cmd_change_cap_twitter_handles_to_links' ],
			[
				'shortdesc' => 'Previous code prepends Author\'s Twitter handles to their bios. This command upgrades them to clickable links.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator washingtonmonthly-extract-all-acf-author-ids-from-custom-data-file',
			[ $this, 'cmd_extract_acf_authors_from_data_file' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator washingtonmonthly-fix-missing-acf-authors',
			[ $this, 'cmd_fix_acf_authors' ],
		);
	}

	/**
	 * Helper command which parses the scraped file authors.txt containing HTML <table> of WP Dashboard's People page.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_acf_authors( $args, $assoc_args ) {
		global $wpdb;

		// A list of ACF authors' names and all corresponding IDs (a combo of actual post_type='people' and post_type='oembed_cache' ones).
		$acf_authors_scraped_names_ids = $this->get_scraped_acf_authors_ids();
		// Some ACF authors exist as GAs already.
		$some_existing_gas_names_ids = $this->get_some_existing_gas();
		// Some ACF authors are referenced to post_type='oembed_cache'. This array maps those to actual post_type='people' objects which should be used instead.
		$acf_authors_ocache_ids_to_people_ids = $this->get_acf_authors_ocache_ids_to_people_ids();

		$results = $wpdb->get_results( "select p.ID, pm.meta_key, pm.meta_value
			from `wp_rbTMja_posts` p
			join `wp_rbTMja_postmeta` pm on pm.post_id = p.ID
			where p.post_type = 'post'
			and pm.meta_key = 'author'
			and pm.meta_value <> '';
			", ARRAY_A
		);

		$known_missing_meta_acf_ids = [ 55, 195, 58069, 263, 621 ];
		$acf_postids_meta_ids_not_found_at_all = [];
		$gas_dont_exist = [];

		foreach ( $results as $key_result => $result ) {
			// echo sprintf( "(%d)/(%d)\n", $key_result+1, count( $results ) );

			$post_id = $result[ 'ID' ];

			// Meta author IDs may represent either valid 'people' post types or the 'oembed_cache' types (which we then need to search for further).
			$acf_authors_meta_ids = unserialize( $result[ 'meta_value' ] );
			$post_ga_ids = [];
			foreach ( $acf_authors_meta_ids as $acf_authors_meta_id ) {

				// find this author's name
				$acf_author_name_ids = $this->recursive_search_acf_author_ids( $acf_authors_scraped_names_ids, $acf_authors_meta_id );
				if ( is_null( $acf_author_name_ids ) ) {
					$acf_postids_meta_ids_not_found_at_all[ $post_id ] = $acf_authors_meta_id;
					continue;
				}

				$author_name = array_key_first( $acf_author_name_ids );
				if ( is_null( $author_name ) || empty( $author_name ) ) {
					// $author_name_not_found_at_all
					$d=1;
				}

				$ga_id = $some_existing_gas_names_ids[ $author_name ] ?? null;
				// search directly in db
				if ( is_null( $ga_id ) ) {
					$ga_id_row = $wpdb->get_row( $wpdb->prepare( " select ID from {$wpdb->posts} where post_type = 'guest-author' and post_title = %s; ", $author_name ), ARRAY_A );
					$ga_id = $ga_id_row[ 'ID' ] ?? null;
				}
				if ( is_null( $ga_id ) ) {
					$gas_dont_exist[] = $author_name;
					continue;
				} else {
					$post_ga_ids[] = $ga_id;
				}
			}

			if ( ! empty( $post_ga_ids ) ) {
				// update
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $post_ga_ids, $post_id );
				echo sprintf( "saved postID %s GA_ids %s\n", $post_id, implode( ',', $post_ga_ids ) );
			}

		}

		// These two are the same, so just these 5 IDs missing. Probably historical reasons.
		$acf_postids_meta_ids_not_found_at_all;
		$known_missing_meta_acf_ids; // <<< prefilled

		// Empty. Success!
		$gas_dont_exist;

		return;
	}

	/**
	 * Manually determined, ACF authors' IDs which are of post_type='oembed_cache' as keys, and values are actual post_type='people' which should be used instead of th OPCache ones.
	 *
	 * @return string[]
	 */
	private function get_acf_authors_ocache_ids_to_people_ids() {
		return [ 131888 => '550', 131887 => '462', 123382 => '117831', 95969 => '270', 70949 => '222', 65893 => '664', 61729 => '268', 59583 => '59578', 57087 => '47', 560 => '120', 109 => '49', 112 => '74', 113 => '84', 114 => '87', 132 => '138', 136 => '145', 44 => '95', 48 => '93', 51 => '57', 83 => '88', 98 => '665', ];
	}

	/**
	 * Some authors already exist as guest authors.
	 * @return int[]
	 */
	private function get_some_existing_gas() {
		return [
			'Poy Winichakul' => 138336,
			'Robin A. Johnson' => 139686,
			'Harris Solomon' => 138404,
			'Martha Lincoln' => 138408,
			'Christopher Ali' => 139630,
			'Josh Axelrod' => 139149,
			'Rob Wolfe' => 139150,
			'Ella Creamer' => 139691,
			'Annie Pforzheimer' => 139450,
			'Jakob Cansler' => 138390,
			'Ciara Torres-Spelliscy' => 139917,
			'Seymour Hersh' => 139919,
			'Seth Tillman' => 139920,
			'Robert Kuttner' => 139921,
			'Barbara Raskin' => 139922,
			'Robert Janus' => 139923,
			'Beverly Kempton' => 139926,
			'David Gelman' => 139927,
			'Jude Wanniski' => 139928,
			'Jane Jacobs' => 139929,
			'Joseph Porter Clerk, Jr.' => 139930,
			'Erwin Knoll' => 139931,
			'Richard Harwood' => 139932,
			'Robert Benson' => 139933,
			'Marvin Kitman' => 139934,
			'Thomas Bethell' => 139935,
			'Peter Lisagor' => 139936,
			'David Hapgood' => 139937,
			'Matthew L. M. Fletcher' => 139938,
			'Jonathan Cohn' => 139939,
			// ---
			"Erwin Chemerinsky" => 128177,
			"Carter Dougherty" => 137352,
			"Garphil Julien" => 137392,
			"Lester Reingold" => 137331,
			"Lydia Polgreen" => 137285,
			"Matthew Miller." => 136683,
			"T. A. Frank" => 135903,
			"Michael OHare" => 135989,
			"Pamela Kond&amp;#233;" => 136383,
			"Mar&amp;#237;a Enchautegui" => 136424,
			"Lori Billingsley &amp;amp; Jacquee Minor" => 136481,
			"Abigail Swisher" => 136496,
			"Jenny Gold" => 136503,
			"Elizabeth Hewitt" => 136502,
			"Daniel McGraw" => 136500,
			"Kirk Carapezza and Lydia Emmanouilidou" => 136508,
			"Luba Ostashevsky" => 136510,
			"Nick Chiles" => 136512,
			"Aaron Loewenberg" => 136513,
			"Richard Ned Lebow and Daniel P. Tompkins" => 136489,
			"Jamie Martines" => 136518,
			"Natalie Orenstein" => 136519,
			"Kristina Rodriguez" => 136522,
			"Katie Parham" => 136523,
			"Lara Burt" => 136527,
			"Ben Stocking" => 136528,
			"Doug Levin" => 136529,
			"Dena Simmons" => 136532,
			"Lillian Mongeau" => 136535,
			"Justin Snider" => 136537,
			"Greg Fischer, Edwin M. Lee and Sam Liccardo" => 136539,
			"Donald E. Heller" => 136543,
			"Heather Schoenfeld" => 136547,
			"Iris Palmer" => 136548,
			"Valerie Smith" => 136552,
			"Lydia Emmanouilidou" => 136557,
			"Mark Paige" => 136559,
			"Mike Males and Anthony Bernier" => 136563,
			"Norman Kelley" => 136566,
			"Anthony Carnevale" => 136572,
			"Nora Howe and Thomas Kerr-Vanderslice" => 136574,
			"Amanda Wahlstedt" => 136579,
			"Lee Kern" => 136585,
			"Paul Wood" => 136587,
			"Paul Glastris and Nancy LeTourneau" => 136584,
			"Jesse Lee" => 136593,
			"Daniel Gifford" => 136599,
			"Catherine E. Lhamon" => 136602,
			"R. Shep Melnick" => 136603,
			"Sandeep Vaheesan" => 136612,
			"John Ehrett" => 136619,
			"Sam Jefferies" => 136618,
			"Sheree Crute" => 136613,
			"Christopher B. Leinberger" => 136607,
			"Steve Silberstein" => 136611,
			"Anne Kim and Saahil Desai" => 136621,
			"Tara García Mathewson" => 136627,
			"Kevin Escudero" => 136633,
			"Samuel Jay Keyser" => 136638,
			"Alec MacGillis" => 136664,
			"Frank Islam and Ed Crego" => 137148,
			"Kathleen Kennedy Townsend" => 137156,
			"Andrew Levison" => 137173,
			"Jared Bass and Clare McCann" => 137174,
			"Haley Samsel" => 137177,
			"Kaila Philo" => 137182,
			"Jonathan Zimmerman" => 137183,
			"Noah Berlatsky" => 137186,
			"Maddy Crowell" => 137187,
			"Tabitha Sanders" => 137196,
			"Leo Hindery, Jr." => 137197,
			"Judy Estrin and Sam Gill" => 137198,
			"Carolyn J. Heinrich" => 137204,
			"Jeff Hauser and Eleanor Eagan" => 137215,
			"Michael Waters" => 137216,
			"Sheldon&nbsp; Himelfarb" => 137219,
			"Michelle Miller-Adams" => 137225,
			"Mary Alice McCarthy and Debra Bragg" => 137223,
			"Allan Golston" => 137227,
			"Lisa Khoury" => 137228,
			"Graham Vyse" => 137229,
			"Simon Lazarus" => 137237,
			"Matthew Sheffield" => 137238,
			"Mneesha Gellman" => 137240,
			"Norman I. Gelman" => 137246,
			"John Halpin and Ruy Teixeira" => 136292,
			"Beth Baltzan and Francesco Cerruti" => 137253,
			"Chris Lu and Harin Contractor" => 137257,
			"Ryan LaRochelle and Luisa S. Deprez" => 137258,
			"Andrew Hanna and Alistair Somerville" => 137259,
			"Chris Lu" => 137263,
			"Ali Noorani" => 137264,
			"Paul Glastris and Eric Cortellessa" => 139974,
			"Max Moran" => 137273,
			"William Vaillancourt" => 137274,
			"Abdul Malik Mujahid&nbsp;" => 137275,
			"Sandi Jacobs" => 137279,
			"Dante Atkins" => 137280,
			"Lauren Adler" => 137283,
			"Giulia Heyward" => 137286,
			"Greg   Mitchell" => 137294,
			"Daniel A. Hanley" => 137298,
			"Daniel Epps and Maria Glover" => 137300,
			"Caryl Rivers and Rosalind C. Barnett" => 137302,
			"Art Brodsky" => 137303,
			"Nicole Girten, Giulia Heyward, and Ellie Vance" => 137308,
			"Holly Brewer" => 137335,
			"Luke Goldstein" => 137338,
			"Neil Kinkopf" => 137349,
			"Daniel Schuman" => 137354,
			"John Jameson" => 137360,
			"W. Wat Hopkins" => 137363,
			"Amna Khalid" => 137365,
			"Jeffrey Aaron Snyder" => 137366,
			"Dale M. Brumfield" => 137368,
			"Adam Bobrow" => 137371,
			"Michael Sweikar" => 137372,
			"Brooke LePage" => 137375,
			"Michelle Liu" => 137376,
			"Margaret Carlson" => 137380,
			"Philippa PB Hughes" => 137381,
			"Gail Helt" => 137385,
			"Kate M. Nicholson" => 137386,
			"Alexandra Spring" => 137388,
			"Ruben J. Garcia" => 137389,
			"Joy Ashford" => 137390,
			"Gordon Witkin" => 137393,
			"Jackie Calmes" => 137404,
			"John D. Marks" => 137408,
			"Storer H. Rowley" => 137412,
			"Luisa S. Deprez" => 137413,
			"Kai Bird" => 137414,
			"Harvey J. Graff" => 137415,
			"Joanna L. Grossman" => 137417,
			"Brian Alexander" => 137416,
		];
	}

	private function recursive_search_acf_author_ids( $acf_authors_scraped_names_ids, $id ) {
		foreach ( $acf_authors_scraped_names_ids as $acf_author_name => $acf_author_ids ) {
			if ( in_array( $id, $acf_author_ids ) ) {
				return [ $acf_author_name => $acf_author_ids ];
			}
		}

		return null;
	}

	private function escape_regex_subject( $subject ) {
		// PHP sanitize string for matching.
		$special_chars = [ ".", "\\", "+", "*", "?", "[", "^", "]", "$", "(", ")", "{", "}", "=", "!", "<", ">", "|", ":", ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\'. $special_char, $subject_escaped );
		}

		return $subject_escaped;
	}

	/**
	 * Helper command which parses the scraped file authors.txt containing HTML <table> of WP Dashboard's People page.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_extract_acf_authors_from_data_file( $args, $assoc_args ) {
		$contents_raw = file_get_contents( '/srv/www/washingtonmonthly/authors.txt' );
		$lines = explode( "\n" ,$contents_raw );
		$filtered_lines = [];
		foreach ( $lines as $line ) {
			$tr_opened = false;
			if ( 0 === strpos( $line, '<tr id="post-' ) ) {
				$tr_opened = true;
				$matches = [];
				preg_match_all( '|'. $this->escape_regex_subject( '<tr id="post-' ) .'(\d+)|', $line, $matches );
				$id = $matches[1][0] ?? null;
				if ( is_null( $id) ) {
					$d=1;
				}
			}
			if ( 0 === strpos( $line, '<div class="post_title">' ) ) {
				$matches = [];
				preg_match_all( '|'. $this->escape_regex_subject( '<div class="post_title">' ) .'([^\<]+)|', $line, $matches );
				$name = $matches[1][0] ?? null;
				if ( is_null( $name ) ) {
					$d=1;
				}
				$name = str_replace( '  ', ' ', $name );
				$filtered_lines[ $name ][] = $id;
			}
			if ( 0 === strpos( $line, '</tr' ) ) {
				$tr_opened = false;
			}
		}

		return $filtered_lines;
	}

	/**
	 * Callable for `newspack-content-migrator washingtonmonthly-transform-custom-taxonomies`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_transform_taxonomies( $args, $assoc_args ) {
		// Get all issues.
		$issues_taxonomies = $this->get_all_issues_taxonomies();
		// Get all posts in taxonomies.
		$posts_in_termtaxonomies = [];

		foreach ( $issues_taxonomies as $issue_taxonomy ) {
			$term_taxonomy_id = $issue_taxonomy[ 'term_taxonomy_id' ];
			$posts_in_termtaxonomies[ $term_taxonomy_id ] = $this->get_posts_in_issue( $term_taxonomy_id );
		}

		// Get/create the parent category.
		$parent_cat_id = wp_create_category( self::PARENT_ISSUES_CATEGORY );
		if ( is_wp_error( $parent_cat_id ) ) {
			WP_CLI::error( sprintf( 'Could not create/get parent category %s.', self::PARENT_ISSUES_CATEGORY ) );
		}

        // Get/create subcategories with name and slug, parent category should be "Issues".
		$errors = [];
		$categories = [];
		WP_CLI::log( sprintf( 'Creating %d categories...', count( $issues_taxonomies ) ) );
		foreach ( $issues_taxonomies as $issue_taxonomy ) {
			$category_name = $issue_taxonomy[ 'name' ];
			$category_slug = $issue_taxonomy[ 'slug' ];

			$issue_cat_id = wp_insert_category( [
				'cat_name' => $category_name,
				'category_nicename' => $category_slug,
				'category_parent' => $parent_cat_id
			] );
			if ( is_wp_error( $issue_cat_id ) ) {
				$msg = sprintf( 'Error creating cat %s %s.', $category_name, $category_slug );
				WP_CLI::warning( $msg );
				$errors[] = $msg;
				continue;
			}

			$categories[ $issue_taxonomy[ 'term_taxonomy_id' ] ] = $issue_cat_id;
		}
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done creating cats.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while creating cats: %s', implode( "\n", $errors ) ) );
		}

		// Assign new categories to all the posts issues.
		WP_CLI::log( 'Assigning posts to their new categories...' );
		$errors = [];
		foreach ( $posts_in_termtaxonomies as $term_taxonomy_id => $posts ) {
			$cat_id = $categories[ $term_taxonomy_id ] ?? null;
			if ( is_null( $cat_id ) ) {
				$msg = sprintf( 'Could not fetch category for term_taxonomy_id %d).', (int) $term_taxonomy_id );
				WP_CLI::warning( $msg );
				$errors[] = $msg;
			}

			foreach ( $posts as $post_id ) {
				$assigned = wp_set_post_categories( $post_id, $cat_id, true );
				if ( is_wp_error( $assigned ) ) {
					$msg = sprintf( 'Could not assign category %d to post %d.', (int) $cat_id, (int) $post_id );
					WP_CLI::warning( $msg );
					$errors[] = $msg;
				}
			}
		}

		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done assigning cats to posts issues.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while assigning cats: %s', implode( "\n", $errors ) ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator washingtonmonthly-migrate-acf-authors-to-cap`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_acf_authors_to_cap( $args, $assoc_args )
	{
		// Create all CAP GAs.
		$errors = [];
		$acf_authors = $this->get_all_acf_authors();
		$acf_authors_to_gas = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'CAP GAs created', count( $acf_authors ) );
		WP_CLI::log( 'Converting ACP Authors to CAP GAs...' );
		foreach ( $acf_authors as $acf_author_post_id => $acf_author ) {
			$progress->tick();
			$twitter_byline = ! empty( $acf_author[ 'twitter_username' ] )
				? sprintf ( 'Follow %s on Twitter @%s. ', $acf_author[ 'first_name' ], $acf_author[ 'twitter_username' ] )
				: '';
			$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [
				'display_name' => $acf_author[ 'first_name' ] . ( ! empty( $acf_author[ 'last_name' ] ) ? ' '. $acf_author[ 'last_name' ] : '' ),
				'first_name' => $acf_author[ 'first_name' ],
				'last_name' => $acf_author[ 'last_name' ],
				'description' => $twitter_byline . ( $acf_author[ 'short_bio' ] ?? '' ),
				'avatar' => ( $acf_author[ 'headshot' ] ?? null ),
			] );
			if ( is_wp_error( $guest_author_id ) ) {
				$errors[] = $guest_author_id->get_error_message();
			}
			$acf_authors_to_gas[ $acf_author_post_id ] = $guest_author_id;
		}
		$progress->finish();
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done creating CAP GAs.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while creating CAP GAs: %s', implode( "\n", $errors ) ) );
		}

		// Assign GAs to their posts.
		$errors = [];
		$posts_with_acf_authors = $this->get_posts_acf_authors();
		WP_CLI::log( 'Assigning CAP GAs to Posts...' );
		$i = 0;
		foreach ( $posts_with_acf_authors as $post_id => $acf_ids ) {
			$i++;
			$ga_ids = [];
			foreach ( $acf_ids as $acf_id ) {
				$ga_ids[] = $acf_authors_to_gas[ $acf_id ] ?? null;
			}
			if ( is_null( $ga_ids ) ) {
				$errors[] = sprintf( 'Could not locate GA for acf_id %d', $acf_id );
				WP_CLI::success( sprintf( '(%d/%d) Possible error with assigning Post ID %d, check log file when finished.', $i, count( $posts_with_acf_authors ), $post_id ) );
				continue;
			}
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
			WP_CLI::success( sprintf( '(%d/%d) Post ID %d got GA(s) %s', $i, count( $posts_with_acf_authors ), $post_id, implode( ',', $ga_ids ) ) );
		}
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done assigning GAs to Posts.' );
		} else {
			$log_file = 'wm_err_authors.log';
			$msg = sprintf( 'Errors while assigning GAs to posts and saved to log %s', $log_file );
			WP_CLI::error( $msg );
			file_put_contents( $log_file, $msg );
		}
	}

	/**
	 * Callable for `newspack-content-migrator washingtonmonthly-migrate-acf-authors-to-cap`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_change_cap_twitter_handles_to_links( $args, $assoc_args )
	{
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare( "select meta_id, meta_value from " . $wpdb->postmeta . " where meta_key = 'cap-description' and meta_value like 'Follow%on Twitter%' and meta_id <> 6837300;" ), ARRAY_A );
		foreach ( $results as $res ) {
			$meta_id = $res['meta_id'];
			$meta_value = $res['meta_value'];

			$matches = [];
			preg_match( '|(Follow\s(?!on\sTwitter).*on\sTwitter\s@([^.]+)\.)|imxs', $meta_value, $matches );
			$line_twitter = $matches[1] ?? null;
			$handle       = $matches[2] ?? null;
			if ( is_null( $line_twitter ) || is_null( $handle ) ) {
				throw new \RuntimeException( sprintf( 'Regex not matched well for meta_id %d (possibly already updated).', $meta_id ) );
			}

			$new_line_twitter = $line_twitter;
			$new_line_twitter = str_replace( '@' . $handle, '<a href="https://twitter.com/' . $handle . '" target="_blank">@' . $handle . '</a>', $new_line_twitter );

			WP_CLI::log( $line_twitter );
			WP_CLI::log( $new_line_twitter );
			WP_CLI::log( '' );

			$meta_value_updated = $meta_value;
			$meta_value_updated = str_replace( $line_twitter, $new_line_twitter, $meta_value_updated );

			$wpdb->query( $wpdb->prepare( "update " . $wpdb->postmeta . " set meta_value = %s where meta_id = %d;", $meta_value_updated, $meta_id ) );
		}
	}

	/**
	 * Gets a list of all the Issues as Taxonomies.
	 *
	 * @return array Taxonomies as subarrays described by key value pairs term_taxonomy_id, term_id, name, slug.
	 */
	private function get_all_issues_taxonomies() {
		global $wpdb;

		$issues_taxonomies = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"select tt.taxonomy, tt.term_taxonomy_id, tt.term_id, t.name, t.slug
			from `wp_rbTMja_term_taxonomy` tt
			join `wp_rbTMja_terms` t on t.term_id = tt.term_id
			where tt.taxonomy = 'issues'
			order by tt.term_taxonomy_id;"
		), ARRAY_A );
		foreach ( $results as $result ) {
			$issues_taxonomies[] = [
				'term_taxonomy_id' => $result[ 'term_taxonomy_id' ],
				'term_id' => $result[ 'term_id' ],
				'name' => $result[ 'name' ],
				'slug' => $result[ 'slug' ],
			];
		}

		return $issues_taxonomies;
	}

	private function get_posts_in_issue( $term_taxonomy_id ) {
		global $wpdb;

		$posts = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `wp_rbTMja_term_relationships` WHERE term_taxonomy_id = %d;", (int) $term_taxonomy_id
		), ARRAY_A );
		foreach ( $results as $result ) {
			$posts[] = $result[ 'object_id' ];
		}

		return $posts;
	}

	/**
	 * Gets all existng ACF Authors.
	 *
	 * @return array ACF Authors data. Key is original ACF Author's post ID, and subarray are meta fields which make up their data
	 *               (e.g. first_name, etc.)
	 */
	private function get_all_acf_authors() {
		global $wpdb;

		$acf_authors = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"select p.ID, pm.meta_key, pm.meta_value
			from wp_rbTMja_posts p
			join wp_rbTMja_postmeta pm on pm.post_id = p.ID
			where post_type = 'people'
			and meta_key in ( 'first_name', 'last_name', 'headshot', 'short_bio', 'twitter_username' );"
		), ARRAY_A );
		foreach ( $results as $result ) {
			$acf_authors[ $result[ 'ID' ] ] = array_merge(
				$acf_authors[ $result[ 'ID' ] ] ?? [],
				[ $result[ 'meta_key' ] => $result[ 'meta_value' ] ]
			);
		}

		return $acf_authors;
	}

	/**
	 * Gets a list of all the Posts and their Authors.
	 *
	 * @return array Keys are Post IDs, value is a sub array of one or more ACF Author Post IDs.
	 */
	private function get_posts_acf_authors() {
		global $wpdb;

		$posts_with_acf_authors = [];

		$results = $wpdb->get_results(
			"select p.ID, pm.meta_key, pm.meta_value
			from `wp_rbTMja_posts` p
			join `wp_rbTMja_postmeta` pm on pm.post_id = p.ID
			where p.post_type = 'post'
			and pm.meta_key = 'author'
			and pm.meta_value <> '' ; ",
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$posts_with_acf_authors[ $result[ 'ID' ] ] = unserialize( $result[ 'meta_value' ] );
		}

		return $posts_with_acf_authors;
	}

	private function get_scraped_acf_authors_ids() {
		return [
			'Poy Winichakul' => [ 0 => '132331', ],
			'Robin A. Johnson' => [ 0 => '132266', ],
			'Harris Solomon' => [ 0 => '132188', ],
			'Martha Lincoln' => [ 0 => '132187', ],
			'Ciara Torres-Spelliscy' => [ 0 => '132038', ],
			'Meredith Kolodner' => [ 0 => '131888', 1 => '550', ],
			'Sarah Butrymowicz' => [ 0 => '131887', 1 => '462', ],
			'Christopher Ali' => [ 0 => '131877', ],
			'Josh Axelrod' => [ 0 => '131792', ],
			'Rob Wolfe' => [ 0 => '131791', ],
			'Ella Creamer' => [ 0 => '131687', ],
			'Seymour Hersh' => [ 0 => '131551', ],
			'Seth Tillman' => [ 0 => '131526', ],
			'Robert Kuttner' => [ 0 => '131522', ],
			'Barbara Raskin' => [ 0 => '131519', ],
			'Robert Janus' => [ 0 => '131513', ],
			'Beverly Kempton' => [ 0 => '131495', ],
			'David Gelman' => [ 0 => '131494', ],
			'Jude Wanniski' => [ 0 => '131448', ],
			'Jane Jacobs' => [ 0 => '131440', ],
			'Joseph Porter Clerk, Jr.' => [ 0 => '131430', ],
			'Erwin Knoll' => [ 0 => '131429', ],
			'Annie Pforzheimer' => [ 0 => '131294', ],
			'Richard Harwood' => [ 0 => '131285', ],
			'Robert Benson' => [ 0 => '131277', ],
			'Marvin Kitman' => [ 0 => '131271', ],
			'Thomas Bethell' => [ 0 => '131193', ],
			'Peter Lisagor' => [ 0 => '131164', ],
			'David Hapgood' => [ 0 => '131152', ],
			'Jakob Cansler' => [ 0 => '131143', ],
			'Matthew L. M. Fletcher' => [ 0 => '131129', ],
			'Jonathan Cohn' => [ 0 => '130861', ],
			'Joanna L. Grossman' => [ 0 => '130800', ],
			'Brian Alexander' => [ 0 => '130780', ],
			'Harvey J. Graff' => [ 0 => '130745', ],
			'Kai Bird' => [ 0 => '130679', ],
			'Luisa S. Deprez' => [ 0 => '130407', ],
			'Storer H. Rowley' => [ 0 => '130367', ],
			'Samuel P. Harrington, M.D.' => [ 0 => '130206', ],
			'David Wood' => [ 0 => '130185', ],
			'Matthew Metz and Janelle London' => [ 0 => '130180', ],
			'John D. Marks' => [ 0 => '130161', ],
			'Laura Jedeed' => [ 0 => '130127', ],
			'Michael McGough' => [ 0 => '130090', ],
			'Mia Brett' => [ 0 => '130068', ],
			'Jackie Calmes' => [ 0 => '129975', ],
			'Joshua Douglas' => [ 0 => '129873', ],
			'James North' => [ 0 => '129858', ],
			'Jeet Heer' => [ 0 => '129802', ],
			'Timi Iwayemi' => [ 0 => '129772', ],
			'Sion Bell' => [ 0 => '129770', ],
			'Alex Dalton' => [ 0 => '129728', ],
			'Janis Hirsch' => [ 0 => '129650', ],
			'Jordan Barab' => [ 0 => '129625', ],
			'Taylor Sisk' => [ 0 => '129538', ],
			'Nathan Newman' => [ 0 => '129512', ],
			'Gordon Witkin' => [ 0 => '129497', ],
			'Garphil Julien' => [ 0 => '129447', ],
			'Gregory Svirnovskiy' => [ 0 => '129332', ],
			'Joy Ashford' => [ 0 => '129307', ],
			'Ruben J. Garcia' => [ 0 => '129285', ],
			'Alexandra Spring' => [ 0 => '129267', ],
			'Donald F. Kettl and Paul Glastris' => [ 0 => '129152', ],
			'Kate M. Nicholson' => [ 0 => '128990', ],
			'Gail Helt' => [ 0 => '128976', ],
			'Anna Brugmann' => [ 0 => '128915', ],
			'Tabatha Abu El-Haj' => [ 0 => '128888', ],
			'Robe Imbriano' => [ 0 => '128823', ],
			'Philippa PB Hughes' => [ 0 => '128789', ],
			'Margaret Carlson' => [ 0 => '128742', ],
			'David Faris' => [ 0 => '128639', ],
			'Kenneth L. Woodward' => [ 0 => '128623', ],
			'Lauren Wolfe' => [ 0 => '128577', ],
			'Michelle Liu' => [ 0 => '128559', ],
			'Brooke LePage' => [ 0 => '128481', ],
			'Sameer M. Siddiqi' => [ 0 => '128435', ],
			'Jodie Adams Kirshner' => [ 0 => '128403', ],
			'Michael Sweikar' => [ 0 => '128385', ],
			'Adam Bobrow' => [ 0 => '128344', ],
			'Marcus Courtney' => [ 0 => '128343', ],
			'Emily Crockett' => [ 0 => '128304', ],
			'Dale M. Brumfield' => [ 0 => '128237', ],
			'Erwin Chemerinsky' => [ 0 => '128177', ],
			'Jeffrey Aaron Snyder' => [ 0 => '128161', ],
			'Amna Khalid' => [ 0 => '128160', ],
			'Peggy Li' => [ 0 => '128054', ],
			'W. Wat Hopkins' => [ 0 => '127972', ],
			'Dan Froomkin' => [ 0 => '127954', ],
			'Reginald C. Oh' => [ 0 => '127946', ],
			'John Jameson' => [ 0 => '127903', ],
			'Jake Williams' => [ 0 => '127902', ],
			'D\'Juan Hopewell' => [ 0 => '127687', ],
			'Lindsay M. Chervinsky' => [ 0 => '127650', ],
			'Guy Cecil' => [ 0 => '127611', ],
			'Marci Harris' => [ 0 => '127327', ],
			'Daniel Schuman' => [ 0 => '127326', ],
			'John F. Kelly' => [ 0 => '127158', ],
			'Carter Dougherty' => [ 0 => '127116', ],
			'Jeff Stein' => [ 0 => '127107', ],
			'Amel Ahmed' => [ 0 => '126994', ],
			'Neil Kinkopf' => [ 0 => '126951', ],
			'Rosa Brooks' => [ 0 => '126918', ],
			'Andrea Katz' => [ 0 => '126908', ],
			'Elaine Shannon' => [ 0 => '126901', ],
			'Shannon Brownlee and Jeanne Lenzer' => [ 0 => '126794', ],
			'Brianne Gorod' => [ 0 => '126673', ],
			'Andrew Cohen' => [ 0 => '126631', ],
			'Barbara J. Risman' => [ 0 => '126508', ],
			'Jasper Craven' => [ 0 => '126411', ],
			'Holly Brewer and Timothy Noah' => [ 0 => '126360', ],
			'Frank O. Bowman, III' => [ 0 => '126270', ],
			'Luke Goldstein' => [ 0 => '126260', ],
			'Amelia Strauss and Daniel Schuman' => [ 0 => '126031', ],
			'Chris Matthews' => [ 0 => '125979', ],
			'Holly Brewer' => [ 0 => '125874', ],
			'Bill Scher' => [ 0 => '125690', ],
			'Derek Shearer' => [ 0 => '125598', ],
			'Jamie Stiehm' => [ 0 => '125209', ],
			'Lester Reingold' => [ 0 => '125105', ],
			'James Walker' => [ 0 => '125067', ],
			'Eli Lehrer' => [ 0 => '124991', ],
			'Zach Harris' => [ 0 => '124605', ],
			'Gabby Birenbaum' => [ 0 => '124572', ],
			'Barry Mitzman' => [ 0 => '124568', ],
			'Jodi Enda' => [ 0 => '124309', ],
			'Nikki Usher' => [ 0 => '124278', ],
			'Stan Draenos' => [ 0 => '124276', ],
			'Paul W. Gleason' => [ 0 => '124274', ],
			'Yevgeny Shrago' => [ 0 => '124264', ],
			'Dan Zibel' => [ 0 => '124123', ],
			'Jennifer Taub' => [ 0 => '123841', ],
			'Kim Wehle' => [ 0 => '123707', ],
			'Marc Ambinder' => [ 0 => '123661', ],
			'Paul Gleason' => [ 0 => '123482', ],
			'Vincent Stehle' => [ 0 => '123382', 1 => '117831', ],
			'Garrett Epps' => [ 0 => '123240', ],
			'Chris Slevin' => [ 0 => '123181', ],
			'Jem Bartholomew' => [ 0 => '122964', ],
			'Jane Chong' => [ 0 => '122944', ],
			'LaRence Snowden' => [ 0 => '122787', ],
			'Jill Wine-Banks' => [ 0 => '122768', ],
			'Paul Glastris and Grace Gedye' => [ 0 => '122086', ],
			'Nicole Girten, Giulia Heyward, and Ellie Vance' => [ 0 => '122012', ],
			'Eric Patashnik' => [ 0 => '121958', ],
			'Emily Nonko' => [ 0 => '121905', ],
			'Joseph Winters' => [ 0 => '121828', ],
			'Rob Richie and David Daley' => [ 0 => '121797', ],
			'Art Brodsky' => [ 0 => '121566', ],
			'Caryl Rivers and Rosalind C. Barnett' => [ 0 => '121319', ],
			'Nicole Girten' => [ 0 => '121298', ],
			'Daniel Epps and Maria Glover' => [ 0 => '121228', ],
			'Jared Brey' => [ 0 => '121048', ],
			'Daniel A. Hanley' => [ 0 => '120994', ],
			'Jeff Hauser' => [ 0 => '120617', ],
			'Ken Weisbrode' => [ 0 => '120551', ],
			'Ellie Vance' => [ 0 => '120429', ],
			'Greg   Mitchell' => [ 0 => '120357', ],
			'Vikas Saini and Shannon Brownlee' => [ 0 => '119936', ],
			'Phillip Longman and Udit Thakur' => [ 0 => '119934', ],
			'Phillip Longman and Harris Meyer' => [ 0 => '119931', ],
			'Jamie Merisotis and Jesse O’Connell' => [ 0 => '119860', ],
			'John McQuaid' => [ 0 => '119778', ],
			'Cecilia Muñoz' => [ 0 => '119777', ],
			'Casey Burgat' => [ 0 => '119676', ],
			'Giulia Heyward' => [ 0 => '119438', ],
			'Lydia Polgreen' => [ 0 => '119310', ],
			'Andrea Beaty' => [ 0 => '119158', ],
			'Lauren Adler' => [ 0 => '119097', ],
			'Maresa Strano' => [ 0 => '118896', ],
			'Sarah Holder' => [ 0 => '118862', ],
			'Dante Atkins' => [ 0 => '118574', ],
			'Sandi Jacobs' => [ 0 => '118532', ],
			'Robert H. Lande' => [ 0 => '117873', ],
			'Eleanor Eagan' => [ 0 => '117766', ],
			'Abdul Malik Mujahid&nbsp;' => [ 0 => '116678', ],
			'William Vaillancourt' => [ 0 => '116640', ],
			'Max Moran' => [ 0 => '116386', ],
			'Paul Glastris and Eric Cortellessa' => [ 0 => '116129', ],
			'David Cole' => [ 0 => '116075', ],
			'Gaby Del Valle' => [ 0 => '116072', ],
			'Julie Rovner' => [ 0 => '116062', ],
			'Francis Fukuyama' => [ 0 => '116058', ],
			'Cady Stanton' => [ 0 => '115903', ],
			'Stephanie Griffith' => [ 0 => '115626', ],
			'Trevor Sutton' => [ 0 => '115202', ],
			'Ali Noorani' => [ 0 => '115037', ],
			'Chris Lu' => [ 0 => '114511', ],
			'Kristina Karisch' => [ 0 => '114034', ],
			'Betsy Hammond' => [ 0 => '113924', ],
			'Dale Mezzacappa' => [ 0 => '113922', ],
			'Andrew Hanna and Alistair Somerville' => [ 0 => '113594', ],
			'Ryan LaRochelle and Luisa S. Deprez' => [ 0 => '113225', ],
			'Chris Lu and Harin Contractor' => [ 0 => '113153', ],
			'Chris Taylor' => [ 0 => '112402', ],
			'David DeBatto' => [ 0 => '112272', ],
			'Andy Green' => [ 0 => '111757', ],
			'Beth Baltzan and Francesco Cerruti' => [ 0 => '111756', ],
			'Julianne Smith' => [ 0 => '111755', ],
			'Brigid Schulte' => [ 0 => '111754', ],
			'Alex Kotlowitz' => [ 0 => '111753', ],
			'John Halpin and Ruy Teixeira' => [ 0 => '111752', ],
			'Jean P. Bordewich' => [ 0 => '111751', ],
			'Lowell Weiss' => [ 0 => '111750', ],
			'Norman I. Gelman' => [ 0 => '111739', ],
			'Thomas Danielian' => [ 0 => '111210', ],
			'David Broder' => [ 0 => '111113', ],
			'Suzanne Gordon and Steve Early' => [ 0 => '110960', ],
			'Simon Clark' => [ 0 => '110442', ],
			'Maresa Strano and Lydia Bean' => [ 0 => '110350', ],
			'Mneesha Gellman' => [ 0 => '110124', ],
			'Charles Dunst' => [ 0 => '109557', ],
			'Matthew Sheffield' => [ 0 => '109500', ],
			'Simon Lazarus' => [ 0 => '106816', ],
			'Carol Schaeffer' => [ 0 => '106450', ],
			'Will Stancil' => [ 0 => '106449', ],
			'Jake Maher' => [ 0 => '106100', ],
			'Joe Nocera' => [ 0 => '106039', ],
			'Nicole Lynn Lewis' => [ 0 => '105532', ],
			'Arthur Rizer and Jonathan Haggerty' => [ 0 => '105325', ],
			'Julie Rodin Zebrak' => [ 0 => '105019', ],
			'Graham Vyse' => [ 0 => '104932', ],
			'Lisa Khoury' => [ 0 => '104534', ],
			'Allan Golston' => [ 0 => '104512', ],
			'Aaron Keyak and Steve Rabinowitz' => [ 0 => '103943', ],
			'Michelle Miller-Adams' => [ 0 => '103424', ],
			'Rebecca Klein-Collins' => [ 0 => '103374', ],
			'Mary Alice McCarthy and Debra Bragg' => [ 0 => '103372', ],
			'James Kirchick' => [ 0 => '103370', ],
			'Marc R. Stanley' => [ 0 => '103352', ],
			'David Edward Burke' => [ 0 => '102325', ],
			'Sheldon&nbsp; Himelfarb' => [ 0 => '101825', ],
			'J.J. Gould' => [ 0 => '101488', ],
			'Shaoul Sussman' => [ 0 => '101060', ],
			'Michael Waters' => [ 0 => '100862', ],
			'Jeff Hauser and Eleanor Eagan' => [ 0 => '97545', ],
			'Bill Pascrell Jr.' => [ 0 => '96358', ],
			'Michael Fitzgerald' => [ 0 => '96344', ],
			'Beth Baltzan' => [ 0 => '96306', ],
			'Stephen Trynosky' => [ 0 => '95992', ],
			'John Pfaff' => [ 0 => '95973', ],
			'Paul Baumann' => [ 0 => '95969', 1 => '270', ],
			'Cassidy McDonald' => [ 0 => '95964', ],
			'Robert Manduca' => [ 0 => '95677', ],
			'Lara Putnam and Gabriel Perez-Putnam' => [ 0 => '94833', ],
			'Kerry Murphy Healey' => [ 0 => '94470', ],
			'Leo Hindery, Jr. and Bob Kerrey' => [ 0 => '93741', ],
			'Carolyn J. Heinrich' => [ 0 => '93704', ],
			'Kimberly Mutcherson' => [ 0 => '91726', ],
			'Mark A. Graber' => [ 0 => '91723', ],
			'Rebecca Shimoni Stoil' => [ 0 => '91672', ],
			'Adam Diamond' => [ 0 => '91530', ],
			'Claire Kelloway' => [ 0 => '91525', ],
			'Judy Estrin and Sam Gill' => [ 0 => '91499', ],
			'Leo Hindery, Jr.' => [ 0 => '91065', ],
			'Tabitha Sanders' => [ 0 => '90980', ],
			'Brian Highsmith' => [ 0 => '90712', ],
			'Matthew Buck' => [ 0 => '89476', ],
			'John Vogel' => [ 0 => '89415', ],
			'Nathan M. Jensen' => [ 0 => '89147', ],
			'James Cargas' => [ 0 => '88398', ],
			'William V. Glastris Jr.' => [ 0 => '88139', ],
			'Matthew Green' => [ 0 => '88093', ],
			'Haley Edwards' => [ 0 => '88089', ],
			'Maddy Crowell' => [ 0 => '88042', ],
			'Noah Berlatsky' => [ 0 => '87991', ],
			'Megan Hart' => [ 0 => '87787', ],
			'David K. Shipler' => [ 0 => '86936', ],
			'Jonathan Zimmerman' => [ 0 => '86623', ],
			'Kaila Philo' => [ 0 => '86536', ],
			'Alice J. Gallin-Dwyer' => [ 0 => '85838', ],
			'Jesse Lee and Talia Dessel' => [ 0 => '85619', ],
			'Matt Bernardini' => [ 0 => '85580', ],
			'Rachel Mabe' => [ 0 => '84678', ],
			'Haley Samsel' => [ 0 => '84623', ],
			'Stephen Phillips' => [ 0 => '84246', ],
			'Charles Epp' => [ 0 => '84243', ],
			'Jared Bass and Clare McCann' => [ 0 => '84239', ],
			'Andrew Levison' => [ 0 => '83042', ],
			'John Gomperts' => [ 0 => '82877', ],
			'Bryan Caplan' => [ 0 => '82741', ],
			'Grace Gedye' => [ 0 => '82564', ],
			'Daniel Block' => [ 0 => '82380', ],
			'Melissa Deckman' => [ 0 => '81717', ],
			'Stacy Mitchell' => [ 0 => '81705', ],
			'Rebecca Pilar Buckwalter-Poza' => [ 0 => '81691', ],
			'Ben Paviour' => [ 0 => '81690', ],
			'David A. Walsh' => [ 0 => '81594', ],
			'Eric Cortellessa' => [ 0 => '81464', ],
			'Maurice Sykes' => [ 0 => '80075', ],
			'Marshall Steinbaum' => [ 0 => '79546', ],
			'Suzanne Gordon and Jasper Craven' => [ 0 => '78854', ],
			'Marc F. Plattner' => [ 0 => '76687', ],
			'Richard R. John' => [ 0 => '76682', ],
			'Nathan J. Robinson' => [ 0 => '76670', ],
			'Kathleen Kennedy Townsend' => [ 0 => '76535', ],
			'Fran Quigley' => [ 0 => '76405', ],
			'James Kwak' => [ 0 => '76356', ],
			'Paul S. Hewitt and Phillip Longman' => [ 0 => '76349', ],
			'Kim Wilcox' => [ 0 => '75862', ],
			'Daniel Heimpel' => [ 0 => '75199', ],
			'Juliet Amann and Emily Langhorne' => [ 0 => '75087', ],
			'Joel Clement' => [ 0 => '74635', ],
			'Frank Islam and Ed Crego' => [ 0 => '74425', ],
			'Demi Lee' => [ 0 => '74203', ],
			'Jordan Haedtler' => [ 0 => '72815', ],
			'Wendy J. Schiller' => [ 0 => '72228', ],
			'Anne Kim' => [ 0 => '72100', ],
			'Neena Satija' => [ 0 => '71905', ],
			'Roger McNamee' => [ 0 => '71862', ],
			'Hollie Russon Gilman and Elena Souris' => [ 0 => '71847', ],
			'Nicole Narea' => [ 0 => '71779', ],
			'Randy Fertel' => [ 0 => '71713', ],
			'Andrs Martinez' => [ 0 => '71083', ],
			'Laura McGann' => [ 0 => '71085', ],
			'Stan Jones' => [ 0 => '71088', ],
			'Steven Teles' => [ 0 => '71095', ],
			'Mark Thompson' => [ 0 => '71097', ],
			'Richard Lee Colvin' => [ 0 => '71101', ],
			'Michael Bobelian' => [ 0 => '71061', ],
			'Walter Isaacson' => [ 0 => '71050', ],
			'George M. Woodwell' => [ 0 => '71000', ],
			'Gregg Herken' => [ 0 => '71004', ],
			'Washington Monthly Editorial Staff' => [ 0 => '71009', ],
			'A 40th Anniversary Collection' => [ 0 => '71011', ],
			'Katherine Boo' => [ 0 => '71013', ],
			'James Boyd' => [ 0 => '71015', ],
			'Taylor Branch' => [ 0 => '71017', ],
			'David Adamr' => [ 0 => '70971', ],
			'Paul Brown' => [ 0 => '70974', ],
			'Rhett Butler' => [ 0 => '70976', ],
			'Michael Grunwald' => [ 0 => '70980', ],
			'Mark A. R. Kleiman' => [ 0 => '70984', ],
			'Marcelo Leite' => [ 0 => '70986', ],
			'Mark Rice-Oxley' => [ 0 => '70990', ],
			'Roger D. Stone' => [ 0 => '70993', ],
			'Patrick C. Doherty' => [ 0 => '70997', ],
			'Loren Jenkins' => [ 0 => '70945', ],
			'Michael O\'Donnell' => [ 0 => '70949', 1 => '222', ],
			'Jonathan Gruber' => [ 0 => '70958', ],
			'Paul Kedrosky' => [ 0 => '70962', ],
			'Michael OHare' => [ 0 => '70965', ],
			'Matthew Blake' => [ 0 => '70923', ],
			'Margaret Talbot' => [ 0 => '70934', ],
			'Ajay K. Mehrotra' => [ 0 => '70890', ],
			'Britt Peterson' => [ 0 => '70894', ],
			'Thomas E. Ricks' => [ 0 => '70896', ],
			'Contributing Editors' => [ 0 => '70898', ],
			'David Axe' => [ 0 => '70904', ],
			'Max Stier' => [ 0 => '70908', ],
			'Kenneth Ballen' => [ 0 => '70865', ],
			'Flynt Leverett' => [ 0 => '70886', ],
			'Hillary Mann Leverett' => [ 0 => '70887', ],
			'Sean Wilentz' => [ 0 => '70840', ],
			'Lawrence B. Wilkerson' => [ 0 => '70842', ],
			'Stephen N. Xenakis' => [ 0 => '70844', ],
			'Ted Widmer' => [ 0 => '70846', ],
			'Jason Berry' => [ 0 => '70849', ],
			'Chris Kimball' => [ 0 => '70861', ],
			'Carl Levin' => [ 0 => '70809', ],
			'Richard Lugar' => [ 0 => '70811', ],
			'Leon E. Panetta' => [ 0 => '70814', ],
			'Nancy Pelosi' => [ 0 => '70816', ],
			'William J. Perry' => [ 0 => '70818', ],
			'Paul R. Pillar' => [ 0 => '70820', ],
			'Tim Roemer' => [ 0 => '70822', ],
			'John Shattuck' => [ 0 => '70824', ],
			'Anne-Marie Slaughter' => [ 0 => '70826', ],
			'William H. Taft IV' => [ 0 => '70829', ],
			'Gore Vidal' => [ 0 => '70833', ],
			'John Balz' => [ 0 => '70835', ],
			'Thomas G. Wenski' => [ 0 => '70838', ],
			'Chris Dodd' => [ 0 => '70777', ],
			'Kenneth M. Duberstein' => [ 0 => '70780', ],
			'Richard Armitage' => [ 0 => '70781', ],
			'Editor\'s Note' => [ 0 => '70783', ],
			'Eric Fair' => [ 0 => '70785', ],
			'Carl Ford' => [ 0 => '70787', ],
			'Lee F. Gunn' => [ 0 => '70790', ],
			'Chuck Hagel' => [ 0 => '70792', ],
			'Lee H. Hamilton' => [ 0 => '70794', ],
			'Thomas H. Kean' => [ 0 => '70795', ],
			'John Hutson' => [ 0 => '70799', ],
			'Claudia Kennedy' => [ 0 => '70802', ],
			'John Kerry' => [ 0 => '70804', ],
			'Harold Hongju Koh' => [ 0 => '70807', ],
			'Gregory Warner' => [ 0 => '70755', ],
			'Bob Barr' => [ 0 => '70760', ],
			'Rand Beers' => [ 0 => '70763', ],
			'Jimmy Carter' => [ 0 => '70766', ],
			'Steve Cheney' => [ 0 => '70768', ],
			'Amy Chua' => [ 0 => '70770', ],
			'Richard Cizik' => [ 0 => '70772', ],
			'Jack Cloonan' => [ 0 => '70775', ],
			'Tom Tancredo' => [ 0 => '70732', ],
			'Sir Richard Branson' => [ 0 => '70734', ],
			'Bud Cummins' => [ 0 => '70737', ],
			'David Francis' => [ 0 => '70741', ],
			'Doron Taussig' => [ 0 => '70708', ],
			'Andrew Tilghman' => [ 0 => '70711', ],
			'R. J. Hillhouse' => [ 0 => '70713', ],
			'Wesley K. Clark' => [ 0 => '70717', ],
			'N. Thomas Connally' => [ 0 => '70719', ],
			'Humor Cabinet' => [ 0 => '70672', ],
			'the Humor Cabinet' => [ 0 => '70683', ],
			'Joseph Neff' => [ 0 => '70688', ],
			'Tim Noah' => [ 0 => '70690', ],
			'T. A. Frank, David Freedlander,' => [ 0 => '70694', ],
			'Eric Zimmermann' => [ 0 => '70695', ],
			'David Wallace-Wells' => [ 0 => '70697', ],
			'Emily Green' => [ 0 => '70664', ],
			'Theodore C. Sorensen' => [ 0 => '70666', ],
			'Jeff Lord' => [ 0 => '70670', ],
			'Brendan L. Koerner' => [ 0 => '70645', ],
			'Garth Stewart' => [ 0 => '70650', ],
			'Melissa Tryon' => [ 0 => '70653', ],
			'Alexander Konetzki' => [ 0 => '70619', ],
			'Bill Richardson' => [ 0 => '70629', ],
			'Ross Cohen' => [ 0 => '70634', ],
			'Clint Douglas' => [ 0 => '70636', ],
			'Andrew Exum' => [ 0 => '70638', ],
			'Nathaniel Fick' => [ 0 => '70640', ],
			'Ken Ward Jr.' => [ 0 => '70596', ],
			'Randall Balmer' => [ 0 => '70600', ],
			'Wesley Clark' => [ 0 => '70602', ],
			'Ronald Glasser' => [ 0 => '70605', ],
			'Jeffrey H. Birnbaum' => [ 0 => '70566', ],
			'William J. Dobson' => [ 0 => '70568', ],
			'Nicholas Schmidle' => [ 0 => '70577', ],
			'Asia Policy Point' => [ 0 => '70582', ],
			'Stephen Flynn' => [ 0 => '70585', ],
			'Dick Armey' => [ 0 => '70538', ],
			'Tom Daschle' => [ 0 => '70540', ],
			'Michael Brendan Dougherty' => [ 0 => '70542', ],
			'David Gergen' => [ 0 => '70544', ],
			'Paul Kiel' => [ 0 => '70549', ],
			'Daniel Levy' => [ 0 => '70554', ],
			'Thomas Mann' => [ 0 => '70556', ],
			'John Nichols' => [ 0 => '70558', ],
			'Conor Clarke' => [ 0 => '70500', ],
			'Bruce Fein' => [ 0 => '70503', ],
			'Joseph Galloway' => [ 0 => '70505', ],
			'Jeffrey Hart' => [ 0 => '70507', ],
			'William A. Niskanen' => [ 0 => '70511', ],
			'Cliff Schecter' => [ 0 => '70513', ],
			'Ron Suskind' => [ 0 => '70517', ],
			'Richard Viguerie' => [ 0 => '70520', ],
			'Barbara T. Dreyfuss' => [ 0 => '70522', ],
			'Robert Dreyfuss' => [ 0 => '70477', ],
			'Bruce Bartlett' => [ 0 => '70495', ],
			'Paul Bauman' => [ 0 => '70497', ],
			'Chris Suellentrop' => [ 0 => '70448', ],
			'Eric Boehlert' => [ 0 => '70455', ],
			'Jeffrey Van der Veer' => [ 0 => '70461', ],
			'David Kusnet' => [ 0 => '70465', ],
			'Dean Starkman' => [ 0 => '70467', ],
			'David Madland' => [ 0 => '70420', ],
			'Sean Naylor' => [ 0 => '70423', ],
			'William Perkins' => [ 0 => '70425', ],
			'James Beale' => [ 0 => '70436', ],
			'Jeffrey Birnbaum' => [ 0 => '70438', ],
			'Greg Colvin' => [ 0 => '70440', ],
			'Douglas McGray' => [ 0 => '70403', ],
			'Richard Kahlenberg' => [ 0 => '70415', ],
			'Paul Waldman' => [ 0 => '70391', ],
			'Alexander Bolton' => [ 0 => '70395', ],
			'Andrew R. Graybill' => [ 0 => '70399', ],
			'Sebastian Mallaby' => [ 0 => '70363', ],
			'Robert McChesney' => [ 0 => '70365', ],
			'John Podesta' => [ 0 => '70366', ],
			'Jennifer Weber' => [ 0 => '70371', ],
			'Carl Cannon' => [ 0 => '70374', ],
			'William Lee Miller' => [ 0 => '70384', ],
			'Robert Gordon' => [ 0 => '70338', ],
			'Derek Douglas' => [ 0 => '70339', ],
			'a panel of five writers' => [ 0 => '70344', ],
			'William Saletan' => [ 0 => '70347', ],
			'Gene Sperling' => [ 0 => '70349', ],
			'Isaac Chotiner' => [ 0 => '70352', ],
			'Jason Zengerle' => [ 0 => '70314', ],
			'Christopher Lehmann' => [ 0 => '70322', ],
			'Margy Waller' => [ 0 => '70330', ],
			'David Corn' => [ 0 => '70284', ],
			'Clay Risen' => [ 0 => '70291', ],
			'Bill Lambrecht' => [ 0 => '70252', ],
			'Hans Nichols' => [ 0 => '70257', ],
			'Nancy Soderberg' => [ 0 => '70259', ],
			'Michael Tomasky' => [ 0 => '70262', ],
			'Robert Burnett' => [ 0 => '70266', ],
			'Victor R. Fuchs' => [ 0 => '70270', ],
			'T. A. Frank' => [ 0 => '70272', ],
			'Aram Roston' => [ 0 => '70275', ],
			'William Beutler' => [ 0 => '70214', ],
			'Geoff Earle' => [ 0 => '70217', ],
			'Monthly Readers' => [ 0 => '70225', ],
			'Victor S. Navasky' => [ 0 => '70228', ],
			'Mark Z. Barabak' => [ 0 => '70233', ],
			'Sen. Joseph Biden' => [ 0 => '70235', ],
			'Chris Cillizza' => [ 0 => '70238', ],
			'Nikolas Gvosdev' => [ 0 => '70246', ],
			'Marjorie Williams' => [ 0 => '70188', ],
			'Daniel Franklin' => [ 0 => '70197', ],
			'A.G. Newmyer III' => [ 0 => '70198', ],
			'Marianne Lavelle' => [ 0 => '70204', ],
			'Daniel Schorr' => [ 0 => '70208', ],
			'Matthew Harwood' => [ 0 => '70153', ],
			'Ayelish McGarvey' => [ 0 => '70157', ],
			'A panel of writers' => [ 0 => '70162', ],
			'experts' => [ 0 => '70163', ],
			'Martin Walker' => [ 0 => '70168', ],
			'David Whitman' => [ 0 => '70170', ],
			'Justin Rood' => [ 0 => '70172', ],
			'Clara Bingham' => [ 0 => '70177', ],
			'Derek Chollet' => [ 0 => '70179', ],
			'Matthew Quirk' => [ 0 => '70145', ],
			'Jaideep Singh' => [ 0 => '70147', ],
			'Sasha Issenberg' => [ 0 => '70117', ],
			'Arthur Levine' => [ 0 => '70120', ],
			'Steve Cieslewicz' => [ 0 => '70135', ],
			'John W. Dean' => [ 0 => '70137', ],
			'Richard Gid Powers' => [ 0 => '70143', ],
			'E.J. Dionne' => [ 0 => '70079', ],
			'Mickey Edwards' => [ 0 => '70082', ],
			'Nancy Sinnott Dwight' => [ 0 => '70083', ],
			'Dan Ephron' => [ 0 => '70086', ],
			'Daniel Farber' => [ 0 => '70088', ],
			'Sebastian Malla' => [ 0 => '70096', ],
			'Grover Norquist' => [ 0 => '70100', ],
			'Jessica North' => [ 0 => '70102', ],
			'Gideon Rose' => [ 0 => '70104', ],
			'David Sirota' => [ 0 => '70107', ],
			'Jonathan Baskin' => [ 0 => '70108', ],
			'Cass R. Sunstein' => [ 0 => '70110', ],
			'Alexander Barnes Dryer' => [ 0 => '70049', ],
			'Sam Jaffe' => [ 0 => '70064', ],
			'Tony Mauro' => [ 0 => '70067', ],
			'Christopher Buckley' => [ 0 => '70076', ],
			'James M. Perry' => [ 0 => '70018', ],
			'Adam Clymer' => [ 0 => '70027', ],
			'James G. Forsyth' => [ 0 => '70030', ],
			'Ron Rapoport' => [ 0 => '70037', ],
			'Robert Schlesinger' => [ 0 => '70039', ],
			'Chuck Todd' => [ 0 => '70042', ],
			'Alan Bjerga' => [ 0 => '70045', ],
			'James Warren' => [ 0 => '69981', ],
			'Nancy Birdsall' => [ 0 => '69985', ],
			'Brian Deese' => [ 0 => '69986', ],
			'Steve Braun' => [ 0 => '69988', ],
			'Grenville Byford' => [ 0 => '69991', ],
			'Stefan Halper' => [ 0 => '69993', ],
			'Jonathan Clarke' => [ 0 => '69994', ],
			'Alexander S. Kirshner' => [ 0 => '69997', ],
			'Marc S. Tucker' => [ 0 => '70004', ],
			'Richard Whitmire' => [ 0 => '70006', ],
			'Josh Benson' => [ 0 => '70008', ],
			'Nicholas Kulish' => [ 0 => '69945', ],
			'Jefferson Morley' => [ 0 => '69949', ],
			'Steven Mufson' => [ 0 => '69951', ],
			'Eric Pfeiffer' => [ 0 => '69953', ],
			'Alexander Kirshner' => [ 0 => '69960', ],
			'Russ Baker' => [ 0 => '69962', ],
			'Mark Katz' => [ 0 => '69967', ],
			'Jai Singh' => [ 0 => '69974', ],
			'Margaret Sullivan' => [ 0 => '69976', ],
			'Robert Poe' => [ 0 => '69916', ],
			'Kirby D. Schroeder' => [ 0 => '69920', ],
			'David Segal' => [ 0 => '69922', ],
			'Spencer Ackerman' => [ 0 => '69925', ],
			'David Wessel' => [ 0 => '69937', ],
			'Peter Bergen' => [ 0 => '69939', ],
			'Barbara Demick' => [ 0 => '69942', ],
			'Sarah Wildman' => [ 0 => '69905', ],
			'Max Blumenthal' => [ 0 => '69907', ],
			'Emily Bazelon' => [ 0 => '69878', ],
			'Bill Keller' => [ 0 => '69884', ],
			'Ann Cooper' => [ 0 => '69885', ],
			'Eli J. Lake' => [ 0 => '69887', ],
			'Merrill Goozner' => [ 0 => '69842', ],
			'Michael Hirsh' => [ 0 => '69848', ],
			'Cassius Peck' => [ 0 => '69852', ],
			'Tom Wicker' => [ 0 => '69857', ],
			'Jonathan Chait' => [ 0 => '69860', ],
			'Joseph Epstein' => [ 0 => '69864', ],
			'Gordon Silverstein' => [ 0 => '69874', ],
			'Wen Stephenson' => [ 0 => '69811', ],
			'Sidney Blumenthal' => [ 0 => '69815', ],
			'Michael C. Boyer' => [ 0 => '69817', ],
			'Lincoln Caplan' => [ 0 => '69819', ],
			'Matthew Dallek' => [ 0 => '69837', ],
			'Tad Fallows' => [ 0 => '69840', ],
			'David Garrow' => [ 0 => '69776', ],
			'Alexander Gourevitch' => [ 0 => '69779', ],
			'Richard Just' => [ 0 => '69782', ],
			'Dave Marash' => [ 0 => '69785', ],
			'Jay Mathews' => [ 0 => '69788', ],
			'Damien Cave' => [ 0 => '69794', ],
			'Debra J. Dickerson' => [ 0 => '69797', ],
			'Robert Knisely' => [ 0 => '69802', ],
			'Charles Lane' => [ 0 => '69804', ],
			'Robert S. McIntyre' => [ 0 => '69737', ],
			'Brian Montopoli' => [ 0 => '69739', ],
			'Noam Scheiber' => [ 0 => '69743', ],
			'Carl Sferrazza Anthony' => [ 0 => '69747', ],
			'Joshua Micah Marshall, Laura Rozen,' => [ 0 => '69751', ],
			'Les Aspin' => [ 0 => '69754', ],
			'John Heilemann' => [ 0 => '69758', ],
			'James Bennet' => [ 0 => '69760', ],
			'Amy Waldman' => [ 0 => '69764', ],
			'Kurt W. Bassuener' => [ 0 => '69769', ],
			'Eric A. Witte' => [ 0 => '69770', ],
			'Phillip Carter' => [ 0 => '69699', ],
			'Soyoung Ho' => [ 0 => '69706', ],
			'Kukula Kapoor Glastris' => [ 0 => '69708', ],
			'Robert J. Shapiro' => [ 0 => '69715', ],
			'Nina Teicholz' => [ 0 => '69717', ],
			'Ricardo Bayon' => [ 0 => '69719', ],
			'Ben Fritz' => [ 0 => '69723', ],
			'Anatol Lieven' => [ 0 => '69732', ],
			'Andrew J. Rotherham' => [ 0 => '69662', ],
			'Courtney Rubin' => [ 0 => '69664', ],
			'Liesl Schillinger' => [ 0 => '69666', ],
			'Amia Srinivasan' => [ 0 => '69668', ],
			'Alexander Stone' => [ 0 => '69670', ],
			'Christian Caryl' => [ 0 => '69673', ],
			'Josh Gottheimer' => [ 0 => '69677', ],
			'Jane Mayer' => [ 0 => '69685', ],
			'Neil Munro' => [ 0 => '69687', ],
			'David Propson' => [ 0 => '69690', ],
			'Reihan Salam' => [ 0 => '69692', ],
			'Paul Weinstein Jr.' => [ 0 => '69695', ],
			'Gregory A. Maniatis' => [ 0 => '69638', ],
			'Vince Morris' => [ 0 => '69642', ],
			'Alan Wirzbicki' => [ 0 => '69647', ],
			'Sandy Bergo' => [ 0 => '69650', ],
			'Ana Marie Cox' => [ 0 => '69653', ],
			'Phillip J. Longman' => [ 0 => '69657', ],
			'Peter Savodnik' => [ 0 => '69594', ],
			'John Schwartz' => [ 0 => '69596', ],
			'Ann Crittenden' => [ 0 => '69606', ],
			'Dawn Johnsen' => [ 0 => '69610', ],
			'Brent Kendall' => [ 0 => '69613', ],
			'Siddharth Mohandas' => [ 0 => '69620', ],
			'Eric Schaeffer' => [ 0 => '69624', ],
			'Major Garrett' => [ 0 => '69557', ],
			'Michael Isikoff' => [ 0 => '69562', ],
			'Barry Newman' => [ 0 => '69564', ],
			'Susan Wieler' => [ 0 => '69571', ],
			'John L. Allen, Jr.' => [ 0 => '69573', ],
			'Tyler Cabot' => [ 0 => '69575', ],
			'Bruce Clark' => [ 0 => '69577', ],
			'Joshua Epstein' => [ 0 => '69579', ],
			'George Glastris' => [ 0 => '69581', ],
			'John Gould' => [ 0 => '69583', ],
			'Jeff Greenfield' => [ 0 => '69586', ],
			'Michael Behar' => [ 0 => '69518', ],
			'Hope Cristol' => [ 0 => '69522', ],
			'David J. Garrow' => [ 0 => '69524', ],
			'David Cay Johnston' => [ 0 => '69527', ],
			'Ryan Lizza' => [ 0 => '69529', ],
			'2-Mar' => [ 0 => '69532', ],
			'Paul Offner' => [ 0 => '69534', ],
			'Lorraine Adams' => [ 0 => '69537', ],
			'E. Fuller Torrey' => [ 0 => '69552', ],
			'Siobhan Gorman' => [ 0 => '69476', ],
			'Alan Greenblatt' => [ 0 => '69479', ],
			'Dayn Perry' => [ 0 => '69483', ],
			'Eric Umansky' => [ 0 => '69485', ],
			'Michael Crowley' => [ 0 => '69490', ],
			'Gary Hart' => [ 0 => '69494', ],
			'Alex Heard' => [ 0 => '69496', ],
			'Katherine Marsh' => [ 0 => '69501', ],
			'Whit Mason' => [ 0 => '69503', ],
			'Seth Mnookin' => [ 0 => '69506', ],
			'Michael Mosettig' => [ 0 => '69508', ],
			'Charles Pekow' => [ 0 => '69510', ],
			'Ken Silverstein' => [ 0 => '69513', ],
			'Susan Threadgill' => [ 0 => '69516', ],
			'Amy Graham' => [ 0 => '69438', ],
			'David Bowman' => [ 0 => '69445', ],
			'David Carr' => [ 0 => '69447', ],
			'Nicholas Confessore' => [ 0 => '69450', ],
			'Sen. John McCain' => [ 0 => '69455', ],
			'Frank Ahrens' => [ 0 => '69460', ],
			'Charles Moskos' => [ 0 => '69465', ],
			'Robert Shapiro' => [ 0 => '69467', ],
			'Tom Farley' => [ 0 => '69472', ],
			'Deborah Cohen' => [ 0 => '69473', ],
			'Wayne Turner' => [ 0 => '69403', ],
			'Paul Demoulin' => [ 0 => '69406', ],
			'Ronald D. Glasser, M.D.' => [ 0 => '69408', ],
			'E. Fuller Torrey, M.D.' => [ 0 => '69411', ],
			'Nancy Watzman' => [ 0 => '69413', ],
			'Matthew Miller.' => [ 0 => '69420', ],
			'Kenneth S. Baer' => [ 0 => '69423', ],
			'Greg Critser' => [ 0 => '69425', ],
			'Loch Johnson' => [ 0 => '69427', ],
			'Brendan I. Koerner' => [ 0 => '69429', ],
			'Myra MacPherson' => [ 0 => '69431', ],
			'Terry Edmonds' => [ 0 => '69436', ],
			'Michael Schaffer' => [ 0 => '69377', ],
			'Julie Wakefield' => [ 0 => '69380', ],
			'Winkler Weinberg' => [ 0 => '69382', ],
			'Ted Geltner' => [ 0 => '69383', ],
			'Tom Malinowski' => [ 0 => '69385', ],
			'Georgia N. Alexakis' => [ 0 => '69387', ],
			'Jamin Raskin' => [ 0 => '69390', ],
			'Andrew Webb' => [ 0 => '69395', ],
			'Ted Halstead' => [ 0 => '69398', ],
			'Michael Lind' => [ 0 => '69399', ],
			'Peter Schuck' => [ 0 => '69361', ],
			'Erik Wemple' => [ 0 => '69364', ],
			'Peter Cary' => [ 0 => '69367', ],
			'Brendan Koerner' => [ 0 => '69371', ],
			'Bill Kovach' => [ 0 => '69373', ],
			'Tom Rosenstiel' => [ 0 => '69374', ],
			'Paul Taylor' => [ 0 => '69324', ],
			'Ethan Wallison' => [ 0 => '69327', ],
			'Beth Austin' => [ 0 => '69329', ],
			'Jon Meacham' => [ 0 => '69331', ],
			'Lynda McDonnell' => [ 0 => '69337', ],
			'Charlie Peters' => [ 0 => '69342', ],
			'Alexander Reid' => [ 0 => '69348', ],
			'Jonathan Tepperman' => [ 0 => '69353', ],
			'Melvin Goodman' => [ 0 => '69357', ],
			'Michael Eskenazi' => [ 0 => '69280', ],
			'Howard Isenstein' => [ 0 => '69282', ],
			'Robert Parry' => [ 0 => '69284', ],
			'Michael Doyle' => [ 0 => '69288', ],
			'Alexander Nguyen' => [ 0 => '69291', ],
			'Tracy Thompson' => [ 0 => '69295', ],
			'Alexandra Robbins' => [ 0 => '69298', ],
			'Jonathan Schorr' => [ 0 => '69300', ],
			'James Carville' => [ 0 => '69303', ],
			'Tom Woll' => [ 0 => '69305', ],
			'Sen. Byron Dorgan' => [ 0 => '69307', ],
			'Danny Kennedy' => [ 0 => '69309', ],
			'Theodore Marmor' => [ 0 => '69311', ],
			'Michael Gerber' => [ 0 => '69317', ],
			'Rachel Marcus' => [ 0 => '69318', ],
			'Donald L. Barlett' => [ 0 => '69320', ],
			'James B. Steele' => [ 0 => '69321', ],
			'Running Mates' => [ 0 => '69239', ],
			'The Stiff Man Has A Spine' => [ 0 => '69241', ],
			'Shooting the Whistleblower' => [ 0 => '69243', ],
			'What Lou Gerstner Could Teach Bill Clinton' => [ 0 => '69245', ],
			'Amanda Ripley' => [ 0 => '69248', ],
			'is an editor of The Washington Monthly' => [ 0 => '69250', ],
			'David Callahan' => [ 0 => '69252', ],
			'Robert Maranto' => [ 0 => '69255', ],
			'William Speed Weed' => [ 0 => '69258', ],
			'Stephen Pomper' => [ 0 => '69262', ],
			'John Solomon' => [ 0 => '69264', ],
			'Senator Byron Dorgan' => [ 0 => '69268', ],
			'David Nather' => [ 0 => '69270', ],
			'Esther Pan' => [ 0 => '69272', ],
			'Joe Conason' => [ 0 => '69274', ],
			'Gene Lyons' => [ 0 => '69275', ],
			'Jerry Landay' => [ 0 => '69277', ],
			'Suzannah Lessard' => [ 0 => '69198', ],
			'Art Levine' => [ 0 => '69200', ],
			'Matthew Miller' => [ 0 => '69202', ],
			'Joseph Nocera' => [ 0 => '69205', ],
			'Jonathan Rowe' => [ 0 => '69207', ],
			'Scott Shuger' => [ 0 => '69210', ],
			'Judith Silverstein' => [ 0 => '69212', ],
			'Kip Sullivan' => [ 0 => '69214', ],
			'Jason DeParle' => [ 0 => '69217', ],
			'Lanny Davis' => [ 0 => '69221', ],
			'Carol Innerst' => [ 0 => '69223', ],
			'Ralph Peters' => [ 0 => '69225', ],
			'George Stephanopoulos' => [ 0 => '69227', ],
			'Inside the Globe' => [ 0 => '69229', ],
			'The Wrong Answer to Littleton' => [ 0 => '69232', ],
			'All Expenses Paid' => [ 0 => '69235', ],
			'Getting Past the Spin' => [ 0 => '69237', ],
			'Maureen Dowd' => [ 0 => '69162', ],
			'Senator Byron L. Dorgan' => [ 0 => '69164', ],
			'Dale Bumpers' => [ 0 => '69167', ],
			'Fitzhugh Mullan' => [ 0 => '69169', ],
			'Seth Grossman' => [ 0 => '69172', ],
			'Robert Worth' => [ 0 => '69174', ],
			'Joseph A. Califano Jr.' => [ 0 => '69176', ],
			'Rick Shenkman' => [ 0 => '69179', ],
			'Elizabeth Austin' => [ 0 => '69181', ],
			'Alexandra Starr' => [ 0 => '69183', ],
			'Todd Gitlin' => [ 0 => '69186', ],
			'Steven Schier' => [ 0 => '69189', ],
			'James Fallows' => [ 0 => '69193', ],
			'David Ignatius' => [ 0 => '69195', ],
			'Suzanne Gordon and Phillip Longman' => [ 0 => '68955', ],
			'Jack Schneider' => [ 0 => '68645', ],
			'Alec MacGillis' => [ 0 => '68628', ],
			'Olivia White' => [ 0 => '68469', ],
			'Dan Mauer' => [ 0 => '68451', ],
			'Chayenne Polimedio' => [ 0 => '68426', ],
			'Isabelle Ross' => [ 0 => '68344', ],
			'Elizabeth Wydra' => [ 0 => '68203', ],
			'Jonathan Taplin' => [ 0 => '68126', ],
			'Brink Lindsey and Steven Teles' => [ 0 => '68099', ],
			'Casey Burgat and Kevin R. Kosar' => [ 0 => '67641', ],
			'John Merrow and Mary Levy, with a reply by Tom Toch' => [ 0 => '67306', ],
			'William A. Galston' => [ 0 => '67302', ],
			'Alex Caton' => [ 0 => '67103', ],
			'Jim Kuhnhenn' => [ 0 => '67095', ],
			'Daniel M. Ashe and Nathaniel P. Reed' => [ 0 => '66997', ],
			'Robert P. Saldin' => [ 0 => '66873', ],
			'Nassir Ghaemi' => [ 0 => '66618', ],
			'Tony Hanna' => [ 0 => '66579', ],
			'Anne Stuhldreher' => [ 0 => '66368', ],
			'Mark Paul, William Darity Jr., and Darrick Hamilton' => [ 0 => '66290', ],
			'Brenda Palms Barber' => [ 0 => '66208', ],
			'Kyle Spencer' => [ 0 => '66202', ],
			'Ida Rademacher and Maureen Conway' => [ 0 => '66028', ],
			'Brent Parton' => [ 0 => '65969', ],
			'Natasha Warikoo' => [ 0 => '65967', ],
			'Mike Males' => [ 0 => '65893', 1 => '664', ],
			'Daniel Oppenheimer' => [ 0 => '65825', ],
			'Rebecca Wexler' => [ 0 => '65800', ],
			'Samuel Jay Keyser' => [ 0 => '65750', ],
			'Barbara Kiviat' => [ 0 => '65732', ],
			'Alan Richard' => [ 0 => '65658', ],
			'Justin Vogt' => [ 0 => '65417', ],
			'Timothy Broderick' => [ 0 => '65354', ],
			'Kevin Escudero' => [ 0 => '65121', ],
			'Sarah Stankorb' => [ 0 => '65026', ],
			'Stuart Anderson' => [ 0 => '65006', ],
			'Stuart Gottlieb' => [ 0 => '65000', ],
			'Josh Wyner' => [ 0 => '64955', ],
			'Chris Zubak-Skees and Gordon Witkin' => [ 0 => '64799', ],
			'Tara García Mathewson' => [ 0 => '64723', ],
			'Emily Hanford' => [ 0 => '64698', ],
			'Gen. Wesley Clark' => [ 0 => '64542', ],
			'Zachary Roth and Cliff Schecter' => [ 0 => '64476', ],
			'Jack Markell' => [ 0 => '64372', ],
			'Thomas Geoghegan' => [ 0 => '64247', ],
			'Anne Kim and Saahil Desai' => [ 0 => '64165', ],
			'Hollie Russon Gilman' => [ 0 => '64134', ],
			'John Ehrett' => [ 0 => '64056', ],
			'Sam Jefferies' => [ 0 => '64055', ],
			'Laura Colarusso' => [ 0 => '64054', ],
			'David Greenberg' => [ 0 => '64052', ],
			'Heather Boerner' => [ 0 => '64021', ],
			'Anna Gorman' => [ 0 => '64020', ],
			'Sheree Crute' => [ 0 => '64019', ],
			'Sandeep Vaheesan' => [ 0 => '63995', ],
			'Steve Silberstein' => [ 0 => '63947', ],
			'Ida Rademacher, Jeremy Smith, and David Mitchell' => [ 0 => '63915', ],
			'Asha Rangappa' => [ 0 => '63826', ],
			'Jay Walljasper' => [ 0 => '63769', ],
			'Christopher B. Leinberger' => [ 0 => '63765', ],
			'Wendy Cervantes' => [ 0 => '63753', ],
			'Karen A. Tramontano' => [ 0 => '63718', ],
			'Max Rose' => [ 0 => '63670', ],
			'R. Shep Melnick' => [ 0 => '63604', ],
			'Catherine E. Lhamon' => [ 0 => '63506', ],
			'Justin King and David Newville' => [ 0 => '63364', ],
			'Jeremie Greer and Emanuel Nieves' => [ 0 => '63291', ],
			'Daniel Gifford' => [ 0 => '63083', ],
			'Justin King and Aleta Sprague' => [ 0 => '63078', ],
			'Steven Pearlstein' => [ 0 => '62993', ],
			'Joshua Micah Marshall' => [ 0 => '62879', ],
			'Joshua Alvarez' => [ 0 => '62865', ],
			'Peregrine Frissell' => [ 0 => '62834', ],
			'Jesse Lee' => [ 0 => '62727', ],
			'Edgardo Padin-Rivera' => [ 0 => '62724', ],
			'Aaron Pallas' => [ 0 => '62691', ],
			'James T. Hamilton' => [ 0 => '62580', ],
			'Joel Berg' => [ 0 => '62473', ],
			'Kevin Carty' => [ 0 => '62409', ],
			'Paul Wood' => [ 0 => '62408', ],
			'Brett Dakin' => [ 0 => '62406', ],
			'Lee Kern' => [ 0 => '62402', ],
			'Paul Glastris and Nancy LeTourneau' => [ 0 => '62348', ],
			'Yasmine Askari' => [ 0 => '62094', ],
			'Anthony B. Pinn and Tom Krattenmaker' => [ 0 => '62080', ],
			'Randolph Court and Robert D. Atkinson' => [ 0 => '62039', ],
			'Daniel Stid' => [ 0 => '62023', ],
			'Amanda Wahlstedt' => [ 0 => '62019', ],
			'Ta-Nehisi Coates' => [ 0 => '62011', ],
			'Carolyn Shapiro' => [ 0 => '61943', ],
			'William Doyle' => [ 0 => '61869', ],
			'Michael Waldman' => [ 0 => '61769', ],
			'Robert Rothman' => [ 0 => '61729', 1 => '268', ],
			'Nora Howe and Thomas Kerr-Vanderslice' => [ 0 => '61617', ],
			'Joe Dempsey' => [ 0 => '61605', ],
			'Anthony Carnevale' => [ 0 => '61581', ],
			'Eric Potash' => [ 0 => '61382', ],
			'Timothy Pratt' => [ 0 => '61252', ],
			'Alexander Stern' => [ 0 => '61231', ],
			'Kevin R. Kosar and Adam Chan' => [ 0 => '61211', ],
			'Jennifer Miller' => [ 0 => '61209', ],
			'Norman Kelley' => [ 0 => '61206', ],
			'Steve Early' => [ 0 => '61199', ],
			'Phillip Carter and Paul Glastris' => [ 0 => '61109', ],
			'Mike Males and Anthony Bernier' => [ 0 => '61082', ],
			'Martin Longman and Paul Glastris' => [ 0 => '60873', ],
			'Karen Kornbluh' => [ 0 => '60871', ],
			'Robert McChesney and John Podesta' => [ 0 => '60868', ],
			'Mark Paige' => [ 0 => '60840', ],
			'Laura Dukess' => [ 0 => '60721', ],
			'Lydia Emmanouilidou' => [ 0 => '60674', ],
			'Ira Shapiro' => [ 0 => '60633', ],
			'Mariella Puerto' => [ 0 => '60632', ],
			'Fred Kaplan' => [ 0 => '60490', ],
			'Patrick C. Doherty and Christopher B. Leinberger' => [ 0 => '60479', ],
			'Valerie Smith' => [ 0 => '60410', ],
			'Richard Samans' => [ 0 => '60364', ],
			'Adam Talbot, Brian Angler, Kevin Seefried, and Jeff Nussbaum' => [ 0 => '60168', ],
			'Benjamin Haas' => [ 0 => '60153', ],
			'Iris Palmer' => [ 0 => '60139', ],
			'Heather Schoenfeld' => [ 0 => '60112', ],
			'Victoria Bassetti' => [ 0 => '60025', ],
			'Brianna Yamasaki' => [ 0 => '59833', ],
			'Janie Carnock' => [ 0 => '59817', ],
			'Donald E. Heller' => [ 0 => '59759', ],
			'Ronald E. Neumann' => [ 0 => '59685', ],
			'Carl M. Cannon' => [ 0 => '59666', ],
			'Nathan Jensen and Jason Wiens' => [ 0 => '59625', ],
			'Greg Fischer, Edwin M. Lee and Sam Liccardo' => [ 0 => '59583', 1 => '59578', ],
			'Mark Murray' => [ 0 => '59520', ],
			'Justin Snider' => [ 0 => '59487', ],
			'E. Andrew Balas' => [ 0 => '59424', ],
			'Lillian Mongeau' => [ 0 => '59383', ],
			'Phillip Longman and Suzanne Gordon' => [ 0 => '59302', ],
			'Reed DesRosiers' => [ 0 => '59297', ],
			'Dena Simmons' => [ 0 => '59292', ],
			'Sammi Wong' => [ 0 => '59198', ],
			'Abner J. Mikva' => [ 0 => '59182', ],
			'Doug Levin' => [ 0 => '59142', ],
			'Ben Stocking' => [ 0 => '59085', ],
			'Lara Burt' => [ 0 => '58975', ],
			'Sierra Mannie' => [ 0 => '58971', ],
			'Mark Strand' => [ 0 => '58944', ],
			'Ted Turner' => [ 0 => '58933', ],
			'Katie Parham' => [ 0 => '58928', ],
			'Kristina Rodriguez' => [ 0 => '58913', ],
			'Suzanne Gordon' => [ 0 => '58905', ],
			'Katherine Oh' => [ 0 => '58904', ],
			'Natalie Orenstein' => [ 0 => '58899', ],
			'Jamie Martines' => [ 0 => '58883', ],
			'Anna Duncan and Kaylan Connally' => [ 0 => '58865', ],
			'Gary Barker' => [ 0 => '58823', ],
			'Randolph Court' => [ 0 => '58813', ],
			'Richard Ned Lebow and Simon Reich' => [ 0 => '58805', ],
			'Aaron Loewenberg' => [ 0 => '58764', ],
			'Nick Chiles' => [ 0 => '58629', ],
			'Leslie Smith' => [ 0 => '58515', ],
			'Luba Ostashevsky' => [ 0 => '58502', ],
			'Ben Barrett' => [ 0 => '58500', ],
			'Kirk Carapezza and Lydia Emmanouilidou' => [ 0 => '58432', ],
			'Julie Kliegman' => [ 0 => '58409', ],
			'Samuel Buntz' => [ 0 => '58401', ],
			'Joshua Kurlantzick' => [ 0 => '57755', ],
			'Allison Hamblin' => [ 0 => '58400', ],
			'Jenny Gold' => [ 0 => '58399', ],
			'Elizabeth Hewitt' => [ 0 => '58398', ],
			'Paul S. Appelbaum' => [ 0 => '58397', ],
			'Daniel McGraw' => [ 0 => '58396', ],
			'Greg Sargent' => [ 0 => '58395', ],
			'Norman Ornstein' => [ 0 => '58394', ],
			'Barry C. Lynn and Phillip Longman' => [ 0 => '58393', ],
			'Abigail Swisher' => [ 0 => '58349', ],
			'Roy Neel' => [ 0 => '58285', ],
			'Dane Stangler and Colin Tomkins-Bergh' => [ 0 => '58278', ],
			'Colin Tomkins-Bergh' => [ 0 => '58263', ],
			'Linda Gibbs and Robert Doar' => [ 0 => '58201', ],
			'William Berkson' => [ 0 => '58028', ],
			'Howard Darmstadter' => [ 0 => '57695', ],
			'Richard Ned Lebow and Daniel P. Tompkins' => [ 0 => '57643', ],
			'Algernon Austin' => [ 0 => '57637', ],
			'Klara Bilgin' => [ 0 => '57625', ],
			'Sarah P. Weeldreyer' => [ 0 => '57492', ],
			'Amy Stackhouse' => [ 0 => '57095', ],
			'Amy Swan' => [ 0 => '57094', ],
			'Claire Iseli' => [ 0 => '57088', ],
			'Carl Iseli' => [ 0 => '57087', 1 => '47', ],
			'Diane Straus' => [ 0 => '57085', ],
			'Sandy Vargas' => [ 0 => '11391', ],
			'Kathryn Widrig and Jordan Marsillo' => [ 0 => '11392', ],
			'kwidrigjmarsillo' => [ 0 => '11393', ],
			'Ryan Skinnell' => [ 0 => '11394', ],
			'Carla D. Thompson' => [ 0 => '11395', ],
			'Susan Walters' => [ 0 => '11396', ],
			'Jonathan Schwabish' => [ 0 => '11397', ],
			'James Bruno' => [ 0 => '11398', ],
			'Michael S. Johnson' => [ 0 => '11399', ],
			'Jason Flom' => [ 0 => '11400', ],
			'Anil Kalhan' => [ 0 => '11401', ],
			'Daryl Shore' => [ 0 => '11402', ],
			'Robin D. Ferriby' => [ 0 => '11403', ],
			'Carla Javits' => [ 0 => '11404', ],
			'James R. Knickman' => [ 0 => '11405', ],
			'Bob &amp;amp; Robin Schwartz' => [ 0 => '11406', ],
			'Lori Billingsley &amp;amp; Jacquee Minor' => [ 0 => '11407', ],
			'Alan Morrison' => [ 0 => '629', ],
			'Richard C. Auxier and Tracy Gordon' => [ 0 => '630', ],
			'Kathleen J. Frydl' => [ 0 => '631', ],
			'June Shih' => [ 0 => '632', ],
			'Martin Kobren' => [ 0 => '633', ],
			'Andrew L. Yarrow' => [ 0 => '634', ],
			'Phil Keisling, Stephanie Hawke, and Taylor Woods' => [ 0 => '635', ],
			'Jonathan Schwabish and Elaine Waxman' => [ 0 => '636', ],
			'Minor Sinclair' => [ 0 => '637', ],
			'Nick Warshaw' => [ 0 => '638', ],
			'Glenn Loury' => [ 0 => '639', ],
			'Timothy Zick' => [ 0 => '640', ],
			'Shayna Cook' => [ 0 => '641', ],
			'Josiah Lee Auspitz' => [ 0 => '642', ],
			'Richard W. Johnson and Karen E. Smith' => [ 0 => '643', ],
			'Jeremy Smith and David S. Mitchell' => [ 0 => '644', ],
			'Raymond C. Offenheiser' => [ 0 => '645', ],
			'Lisa Schohl' => [ 0 => '646', ],
			'Pat Sparrow' => [ 0 => '647', ],
			'acarl' => [ 0 => '648', ],
			'Jeff Hamond' => [ 0 => '649', ],
			'Bill Pitkin' => [ 0 => '650', ],
			'Neill Coleman' => [ 0 => '651', ],
			'Michael Wilkos' => [ 0 => '652', ],
			'Floyd J. Malveaux' => [ 0 => '653', ],
			'Michael M. Weinstein' => [ 0 => '654', ],
			'Cheryl Hughes' => [ 0 => '655', ],
			'Abbie Starker' => [ 0 => '656', ],
			'Saahil Desai' => [ 0 => '657', ],
			'Jessica Swarner' => [ 0 => '658', ],
			'Alicia Mundy' => [ 0 => '659', ],
			'Bethany McLean' => [ 0 => '660', ],
			'Brian S. Feldman' => [ 0 => '661', ],
			'Allen C. Guelzo' => [ 0 => '662', ],
			'Floyd J. Malveaux and Julie Kennedy Lesch' => [ 0 => '663', ],
			'An-Li Herring' => [ 0 => '666', ],
			'Daniel Carpenter' => [ 0 => '552', ],
			'Lee Drutman and Steven Teles' => [ 0 => '553', ],
			'David Blankenhorn, William Galston, Jonathan Rauch' => [ 0 => '554', ],
			'James McBride' => [ 0 => '555', ],
			'Theodoric Meyer' => [ 0 => '556', ],
			'Kirk Carapezza and Mallory Noe-Payne' => [ 0 => '557', ],
			'Alexandra Ma' => [ 0 => '558', ],
			'Kenneth Megan' => [ 0 => '559', ],
			'Tammy Booth' => [ 0 => '560', 1 => '120', ],
			'Chris Lehmann' => [ 0 => '561', ],
			'Paul Glastris and Daniel Luzer' => [ 0 => '562', ],
			'Seth Stoughton' => [ 0 => '563', ],
			'Chris Coons and Tammy Baldwin' => [ 0 => '564', ],
			'Alexander Russo' => [ 0 => '565', ],
			'Daniel P. Tompkins' => [ 0 => '566', ],
			'Eleni Kounalakis' => [ 0 => '567', ],
			'Konstantin Kakaes' => [ 0 => '568', ],
			'Monica Potts' => [ 0 => '569', ],
			'Jordan Fraade' => [ 0 => '570', ],
			'David Osborne' => [ 0 => '571', ],
			'Heather Rogers' => [ 0 => '572', ],
			'Alan B. Morrison' => [ 0 => '573', ],
			'Ivy Love' => [ 0 => '574', ],
			'Ashley Simpson Baird' => [ 0 => '575', ],
			'Isabella Sanchez' => [ 0 => '576', ],
			'Harry Brighouse' => [ 0 => '578', ],
			'Rodney Smolla' => [ 0 => '579', ],
			'Rick Larsen' => [ 0 => '580', ],
			'Derek Kilmer' => [ 0 => '581', ],
			'Sonja West' => [ 0 => '582', ],
			'Pamela Kond&amp;#233;' => [ 0 => '583', ],
			'Karlanna Lewis' => [ 0 => '584', ],
			'Phil LaRue' => [ 0 => '585', ],
			'David J. Hayes' => [ 0 => '586', ],
			'Rick Perlstein' => [ 0 => '587', ],
			'Becca Stanek' => [ 0 => '588', ],
			'Mel Jones' => [ 0 => '589', ],
			'Arianna Skibell' => [ 0 => '590', ],
			'Alicia Robb' => [ 0 => '591', ],
			'Stephen Rose' => [ 0 => '592', ],
			'Anthe Mitrakos' => [ 0 => '593', ],
			'Mamie Voight and Colleen Campbell' => [ 0 => '594', ],
			'Alexander Holt' => [ 0 => '595', ],
			'Mary Alice McCarthy' => [ 0 => '596', ],
			'Samuel R. Bagenstos' => [ 0 => '597', ],
			'Caroline Fredrickson' => [ 0 => '598', ],
			'Jared Bernstein' => [ 0 => '599', ],
			'Leah Douglas' => [ 0 => '600', ],
			'Liz Ben-Ishai' => [ 0 => '601', ],
			'Celeste Bott' => [ 0 => '602', ],
			'Tom Donnelly' => [ 0 => '603', ],
			'Chris Berdik' => [ 0 => '604', ],
			'Lindsey Tepe' => [ 0 => '605', ],
			'John S. Gomperts' => [ 0 => '606', ],
			'Sanford Levinson' => [ 0 => '607', ],
			'Steve Sanders' => [ 0 => '608', ],
			'Amy Gutmann' => [ 0 => '609', ],
			'Olivia Golden' => [ 0 => '610', ],
			'Kate Gerwin' => [ 0 => '611', ],
			'Stefan Hankin and Rasto Ivanic' => [ 0 => '612', ],
			'Michael Purzycki' => [ 0 => '613', ],
			'Alan McQuinn' => [ 0 => '614', ],
			'Susan D. Rozelle' => [ 0 => '615', ],
			'John Heltman' => [ 0 => '616', ],
			'Anne-Marie Slaughter and Ben Scott' => [ 0 => '617', ],
			'Victoria Finkle' => [ 0 => '618', ],
			'Frances E. Lee' => [ 0 => '619', ],
			'Chris Heller' => [ 0 => '620', ],
			'Ev Ehrlich' => [ 0 => '622', ],
			'West Wing Writers' => [ 0 => '623', ],
			'Samuel Bieler' => [ 0 => '624', ],
			'Mar&amp;#237;a Enchautegui' => [ 0 => '625', ],
			'Merle H. Weiner' => [ 0 => '626', ],
			'Elizabeth Lower-Basch' => [ 0 => '627', ],
			'David Ball' => [ 0 => '628', ],
			'Barry Mitnick' => [ 0 => '512', ],
			'Jason Delisle' => [ 0 => '513', ],
			'Aaron Panofsky' => [ 0 => '514', ],
			'George Kieffer' => [ 0 => '515', ],
			'Simon Lazarus and Elisabeth Stein' => [ 0 => '516', ],
			'Heather Boushey' => [ 0 => '517', ],
			'Ann O\'Leary' => [ 0 => '518', ],
			'Carl Chancellor and Richard D. Kahlenberg' => [ 0 => '519', ],
			'Judith Warner' => [ 0 => '520', ],
			'Alan S. Blinder' => [ 0 => '521', ],
			'Christian E. Weller and John Halpin' => [ 0 => '522', ],
			'Joseph E. Stiglitz' => [ 0 => '523', ],
			'Sally Satel' => [ 0 => '524', ],
			'Bailey Miller' => [ 0 => '525', ],
			'Carl Chancellor' => [ 0 => '526', ],
			'Nancy LeTourneau' => [ 0 => '527', ],
			'Peter M. Shane' => [ 0 => '528', ],
			'Josh Swanner' => [ 0 => '529', ],
			'Gilad Edelman' => [ 0 => '530', ],
			'John Beaton' => [ 0 => '531', ],
			'Bonnie Tamres-Moore' => [ 0 => '532', ],
			'Matt Connolly' => [ 0 => '533', ],
			'Donald F. Kettl' => [ 0 => '534', ],
			'Kent Greenfield' => [ 0 => '535', ],
			'Kevin R. Kosar' => [ 0 => '536', ],
			'Sabrina Shankman' => [ 0 => '537', ],
			'Jeff Nussbaum' => [ 0 => '538', ],
			'John J. Dilulio Jr.' => [ 0 => '539', ],
			'Daniel Bush' => [ 0 => '540', ],
			'Kukula Glastris' => [ 0 => '541', ],
			'Charles Ellison' => [ 0 => '542', ],
			'Lisa Guernsey' => [ 0 => '544', ],
			'Amar Bhide' => [ 0 => '545', ],
			'Melissa Bass and Austin Vitale' => [ 0 => '546', ],
			'Nichole Dobo' => [ 0 => '547', ],
			'Emmanuel Felton' => [ 0 => '548', ],
			'Abbie Lieberman' => [ 0 => '549', ],
			'Alysia Santo' => [ 0 => '551', ],
			'Jonathan Ladd' => [ 0 => '478', ],
			'John Patty' => [ 0 => '479', ],
			'Stephen Mihm' => [ 0 => '480', ],
			'Laura Bornfreund' => [ 0 => '481', ],
			'Sarah Carr' => [ 0 => '482', ],
			'David Atkins' => [ 0 => '483', ],
			'Lisa Solod' => [ 0 => '484', ],
			'D.R. Tucker' => [ 0 => '485', ],
			'Chad Stanton' => [ 0 => '486', ],
			'Michelle Cottle' => [ 0 => '487', ],
			'John Halpin' => [ 0 => '488', ],
			'Ruy Teixeira and John Halpin' => [ 0 => '489', ],
			'Paul Glastris and Haley Sweetland Edwards' => [ 0 => '490', ],
			'Stanley Greenberg' => [ 0 => '491', ],
			'Jonas Chartock' => [ 0 => '492', ],
			'Sarah Garland' => [ 0 => '493', ],
			'Jackie Mader' => [ 0 => '494', ],
			'Kirk Carapezza' => [ 0 => '495', ],
			'CJ Libassi' => [ 0 => '496', ],
			'Joel Dodge' => [ 0 => '497', ],
			'John Bradshaw' => [ 0 => '498', ],
			'David Paul Kuhn' => [ 0 => '499', ],
			'Lawrence Wilkerson' => [ 0 => '500', ],
			'Dane Stangler and Jordan Bell-Masterson' => [ 0 => '501', ],
			'Stephen Burd and Rachel Fishman' => [ 0 => '502', ],
			'Zachary M. Schrag' => [ 0 => '503', ],
			'Amy J. Binder' => [ 0 => '504', ],
			'Laura Colarusso and Jon Marcus' => [ 0 => '505', ],
			'Matt Connolly and Phillip Longman' => [ 0 => '506', ],
			'Ben Miller' => [ 0 => '507', ],
			'John D. Donahue' => [ 0 => '508', ],
			'Janet Napolitano' => [ 0 => '509', ],
			'Peter Mancuso' => [ 0 => '510', ],
			'Francis Wilkinson' => [ 0 => '511', ],
			'Luke O\'Neil' => [ 0 => '393', ],
			'Michael Shellenberger and Ted Nordhaus' => [ 0 => '394', ],
			'Eric B. Schnurer' => [ 0 => '395', ],
			'Ed Gerwin' => [ 0 => '396', ],
			'Rick Valelly' => [ 0 => '397', ],
			'Atul Grover' => [ 0 => '398', ],
			'Christopher Walker' => [ 0 => '399', ],
			'Stuart A. Reid' => [ 0 => '400', ],
			'Candice Chen' => [ 0 => '401', ],
			'Timothy Noah' => [ 0 => '402', ],
			'Alan Ehrenhalt' => [ 0 => '403', ],
			'Thomas Toch and Taylor White' => [ 0 => '404', ],
			'Moshe Z. Marvit' => [ 0 => '405', ],
			'Stefan Hankin' => [ 0 => '406', ],
			'Robert D. Atkinson' => [ 0 => '407', ],
			'Ben Florsheim' => [ 0 => '408', ],
			'Daniel Gorfine' => [ 0 => '409', ],
			'Christopher Flavelle' => [ 0 => '410', ],
			'Megan McArdle' => [ 0 => '411', ],
			'Ben Gharagozli and Christopher Moraff' => [ 0 => '412', ],
			'Robert Kelchen' => [ 0 => '413', ],
			'Devin Castles, Katelyn Fossett, and Ben Florsheim' => [ 0 => '414', ],
			'Dane Stangler' => [ 0 => '415', ],
			'Cass Sunstein' => [ 0 => '416', ],
			'Martin Longman' => [ 0 => '417', ],
			'Rachel Cohen' => [ 0 => '418', ],
			'Christopher Moraff' => [ 0 => '419', ],
			'Ellen Hazelkorn' => [ 0 => '420', ],
			'Ali Wyne' => [ 0 => '421', ],
			'Paul Gottlieb' => [ 0 => '422', ],
			'Thad Hall' => [ 0 => '423', ],
			'Curtis Gans' => [ 0 => '424', ],
			'Zach Wenner, Jonny Dorsey, and Fagan Harris' => [ 0 => '425', ],
			'Shawn Brimley' => [ 0 => '426', ],
			'Laura Kasinof' => [ 0 => '427', ],
			'Richard Florida' => [ 0 => '428', ],
			'John M. Bridgeland and Alan Khazei' => [ 0 => '429', ],
			'Mark Edwards' => [ 0 => '430', ],
			'Harry J. Holzer' => [ 0 => '431', ],
			'Dorian Friedman' => [ 0 => '432', ],
			'James M. Glaser and Timothy J. Ryan' => [ 0 => '433', ],
			'Eleanor Clift' => [ 0 => '434', ],
			'Peggy Orchowski' => [ 0 => '435', ],
			'David Payne' => [ 0 => '436', ],
			'Anya Kamenetz' => [ 0 => '437', ],
			'Jill Barshay' => [ 0 => '438', ],
			'Charles Epp and Steven Maynard-Moody' => [ 0 => '439', ],
			'Siddhartha Mahanta' => [ 0 => '440', ],
			'Phillip Longman and Paul S. Hewitt' => [ 0 => '441', ],
			'Pearl Sydenstricker' => [ 0 => '442', ],
			'Liz Willen' => [ 0 => '443', ],
			'Johann Koehler' => [ 0 => '444', ],
			'Richard Skinner' => [ 0 => '445', ],
			'Andre Perry' => [ 0 => '446', ],
			'Anne Hyslop' => [ 0 => '447', ],
			'Ari Rabin-Havt' => [ 0 => '448', ],
			'Conor Williams' => [ 0 => '449', ],
			'Amy Rothschild' => [ 0 => '450', ],
			'Ali Gharib' => [ 0 => '451', ],
			'Clare McCann' => [ 0 => '452', ],
			'Megan Carolan' => [ 0 => '453', ],
			'Julie Ershadi' => [ 0 => '454', ],
			'Matt Andrews' => [ 0 => '455', ],
			'Summer Jiang' => [ 0 => '456', ],
			'Robert Shireman' => [ 0 => '457', ],
			'Rachel Fishman' => [ 0 => '458', ],
			'Michelle Wein' => [ 0 => '459', ],
			'Sara Neufeld' => [ 0 => '460', ],
			'Corey Robin' => [ 0 => '461', ],
			'Matt Krupnick' => [ 0 => '463', ],
			'Donald Heller' => [ 0 => '464', ],
			'Josh Freedman' => [ 0 => '465', ],
			'Jon Caulkins' => [ 0 => '466', ],
			'Jonathan Rauch' => [ 0 => '467', ],
			'Chris Mooney' => [ 0 => '468', ],
			'John Winslow' => [ 0 => '469', ],
			'John Stoehr' => [ 0 => '470', ],
			'Bill White' => [ 0 => '471', ],
			'Diana G. Carew' => [ 0 => '472', ],
			'David Kendall' => [ 0 => '473', ],
			'Moira Campion McConaghy' => [ 0 => '474', ],
			'Pamela Cantor' => [ 0 => '475', ],
			'Gene I. Maeroff' => [ 0 => '476', ],
			'Julia Azari' => [ 0 => '477', ],
			'Noah Feldman' => [ 0 => '301', ],
			'David Karol' => [ 0 => '302', ],
			'Adam Kirsch' => [ 0 => '303', ],
			'Stephen Burd' => [ 0 => '304', ],
			'Danny Vinik and Minjae Park' => [ 0 => '305', ],
			'James P. Rooney' => [ 0 => '306', ],
			'Rachel Fishman and Robert Kelchen' => [ 0 => '307', ],
			'Simon van Zuylen-Wood' => [ 0 => '308', ],
			'Joshua Green' => [ 0 => '309', ],
			'Derrick Haynes' => [ 0 => '310', ],
			'Naomi Schaefer Riley' => [ 0 => '311', ],
			'Ximena Ortiz' => [ 0 => '312', ],
			'Max Ehrenfreund' => [ 0 => '313', ],
			'Michael Gunter' => [ 0 => '314', ],
			'Alex Runner' => [ 0 => '315', ],
			'Sebastian Jones and Daniel Luzer' => [ 0 => '316', ],
			'Matthew Kahn' => [ 0 => '317', ],
			'Anne Kim and Carl Rist' => [ 0 => '318', ],
			'Lowry Heussler' => [ 0 => '319', ],
			'Sean McElwee' => [ 0 => '320', ],
			'Dan Hopkins' => [ 0 => '321', ],
			'Andrew Rudalevige' => [ 0 => '322', ],
			'Richard Vedder' => [ 0 => '323', ],
			'Terrence M. McCoy' => [ 0 => '324', ],
			'Do Hyun Kim' => [ 0 => '325', ],
			'Will Marshall' => [ 0 => '326', ],
			'Tim Heffernan' => [ 0 => '327', ],
			'David Dagan and Steven M. Teles' => [ 0 => '328', ],
			'Lina Khan' => [ 0 => '329', ],
			'Haley Sweetland Edwards' => [ 0 => '330', ],
			'Alonzo Hamby' => [ 0 => '331', ],
			'Julius Simonelli' => [ 0 => '332', ],
			'Jamaal Abdul-Alim' => [ 0 => '333', ],
			'Greg Anrig' => [ 0 => '334', ],
			'Aaron David Miller' => [ 0 => '335', ],
			'Jonathan Mahler' => [ 0 => '336', ],
			'Darren Linvill' => [ 0 => '337', ],
			'Susan Ginsburg' => [ 0 => '338', ],
			'Betsey Stevenson and Justin Wolfers' => [ 0 => '339', ],
			'Andrew Cherlin' => [ 0 => '340', ],
			'Louis P. Masur' => [ 0 => '341', ],
			'Sherry Salway Black' => [ 0 => '342', ],
			'Nicholas Lemann' => [ 0 => '343', ],
			'Douglas A. Blackmon' => [ 0 => '344', ],
			'Taylor Branch and Haley Sweetland Edwards' => [ 0 => '345', ],
			'Elijah Anderson' => [ 0 => '346', ],
			'Thomas J. Sugrue' => [ 0 => '347', ],
			'Glenn C. Loury' => [ 0 => '348', ],
			'Isabel Sawhill' => [ 0 => '350', ],
			'Jelani Cobb' => [ 0 => '351', ],
			'Andrea Gillespie' => [ 0 => '352', ],
			'Dan Farber' => [ 0 => '353', ],
			'Jordan Michael Smith' => [ 0 => '354', ],
			'Mark Bauerlein' => [ 0 => '355', ],
			'Elizabeth Winkler' => [ 0 => '356', ],
			'David Dayen' => [ 0 => '357', ],
			'Rhiannon M. Kirkland' => [ 0 => '358', ],
			'Sanjay Kapoor' => [ 0 => '359', ],
			'Norman Matloff' => [ 0 => '360', ],
			'Peter Orszag' => [ 0 => '361', ],
			'David Kamin' => [ 0 => '362', ],
			'Daniel Kurtz-Phelan' => [ 0 => '363', ],
			'Moshe Z. Marvit and Jason Bacasa' => [ 0 => '364', ],
			'Georgia Levenson Keohane' => [ 0 => '365', ],
			'Clyde Prestowitz' => [ 0 => '366', ],
			'Tim Weiner' => [ 0 => '367', ],
			'David Dagan' => [ 0 => '368', ],
			'Emily Menkes' => [ 0 => '369', ],
			'Bill Fay' => [ 0 => '370', ],
			'Alison Gash' => [ 0 => '371', ],
			'Jennifer Victor' => [ 0 => '372', ],
			'Elias Vlanton' => [ 0 => '373', ],
			'Edward Glaeser' => [ 0 => '374', ],
			'Bill Gardner' => [ 0 => '375', ],
			'Eric McGhee' => [ 0 => '376', ],
			'Jeffrey Goldberg' => [ 0 => '377', ],
			'Michael Petrilli' => [ 0 => '378', ],
			'Adam Garfinkle' => [ 0 => '379', ],
			'Jeff Nussbaum and Ryan Jacobs' => [ 0 => '380', ],
			'Charles Kenny' => [ 0 => '381', ],
			'Louis Barbash' => [ 0 => '382', ],
			'John Carlos Frey' => [ 0 => '383', ],
			'John F. Wasik' => [ 0 => '384', ],
			'Devin Castles' => [ 0 => '385', ],
			'Robert Reischauer and Michael McPherson' => [ 0 => '386', ],
			'Raymond A. Smith' => [ 0 => '387', ],
			'Wick Sloane' => [ 0 => '388', ],
			'Paul Stephens' => [ 0 => '389', ],
			'Anne Kim and Ed Kilgore' => [ 0 => '390', ],
			'Kelly Kleiman' => [ 0 => '391', ],
			'Katelyn Fossett' => [ 0 => '392', ],
			'Paul Pillar' => [ 0 => '256', ],
			'Phillip Longman and Lina Khan' => [ 0 => '257', ],
			'Christopher Hitchens, as told to Art Levine' => [ 0 => '258', ],
			'Phil Angelides' => [ 0 => '259', ],
			'Kathleen Geier' => [ 0 => '260', ],
			'John Mangin' => [ 0 => '261', ],
			'Sara Mead' => [ 0 => '262', ],
			'Mark Schmitt and Brink Lindsey' => [ 0 => '264', ],
			'Michael Clifford Longman' => [ 0 => '265', ],
			'Laura M. Colarusso' => [ 0 => '266', ],
			'Alison Fairbrother' => [ 0 => '267', ],
			'Bill Tucker' => [ 0 => '269', ],
			'Keach Hagey' => [ 0 => '271', ],
			'Mark C. Taylor' => [ 0 => '272', ],
			'Gregory McIsaac' => [ 0 => '273', ],
			'James Maguire' => [ 0 => '274', ],
			'Don Taylor' => [ 0 => '275', ],
			'Elbert Ventura' => [ 0 => '276', ],
			'Danny Vinik' => [ 0 => '277', ],
			'Minjae Park' => [ 0 => '278', ],
			'Peter Ross Range' => [ 0 => '279', ],
			'Ben Jacobs' => [ 0 => '280', ],
			'Paul Glastris and Phillip Longman' => [ 0 => '281', ],
			'Barry C. Lynn and Lina Khan' => [ 0 => '282', ],
			'Dana Goldstein' => [ 0 => '283', ],
			'Anya Schoolman' => [ 0 => '284', ],
			'Blake Fleetwood' => [ 0 => '285', ],
			'Mark Schmitt' => [ 0 => '286', ],
			'Reid Cramer' => [ 0 => '287', ],
			'Elizabeth Lesly Stevens' => [ 0 => '288', ],
			'Peter Beck' => [ 0 => '289', ],
			'Gregory Koger' => [ 0 => '290', ],
			'Seth Masket' => [ 0 => '291', ],
			'Hans Noel' => [ 0 => '292', ],
			'Jennifer Nicoll Victor' => [ 0 => '293', ],
			'Josh Barro' => [ 0 => '294', ],
			'Luigi Zingalesto' => [ 0 => '295', ],
			'Christine Chun' => [ 0 => '296', ],
			'Tina Gerhardt' => [ 0 => '297', ],
			'Pankaj Mishra' => [ 0 => '298', ],
			'A. Gary Shilling' => [ 0 => '299', ],
			'Brendan Doherty' => [ 0 => '300', ],
			'Ramesh Ponnuru' => [ 0 => '206', ],
			'Ron Klain' => [ 0 => '207', ],
			'Joseph Thorndike' => [ 0 => '208', ],
			'Rick Ungar' => [ 0 => '209', ],
			'Samuel Knight and Justin Spees' => [ 0 => '210', ],
			'Susan Headden' => [ 0 => '211', ],
			'Benjamin Ginsberg' => [ 0 => '212', ],
			'Paul Hockenos' => [ 0 => '213', ],
			'Maggie Severns' => [ 0 => '214', ],
			'Alexander Heffner' => [ 0 => '215', ],
			'Heather Hurlburt' => [ 0 => '216', ],
			'Elaine Kamarck' => [ 0 => '217', ],
			'Ryan Cooper' => [ 0 => '218', ],
			'Colin Woodard' => [ 0 => '219', ],
			'Jeffrey Leonard' => [ 0 => '220', ],
			'James K. Galbraith' => [ 0 => '221', ],
			'Joshua Hammer' => [ 0 => '223', ],
			'Eric D. Isaacs' => [ 0 => '224', ],
			'Mike Lofgren' => [ 0 => '225', ],
			'Michael Kinsley' => [ 0 => '226', ],
			'Washington Monthly' => [ 0 => '227', ],
			'Derek Cressman' => [ 0 => '228', ],
			'David Weigel' => [ 0 => '229', ],
			'Thomas Mann and Norman Ornstein' => [ 0 => '230', ],
			'Dahlia Lithwick' => [ 0 => '231', ],
			'James Traub' => [ 0 => '232', ],
			'David Roberts' => [ 0 => '233', ],
			'Michael Konczal' => [ 0 => '234', ],
			'Elizabeth Dickinson' => [ 0 => '235', ],
			'Michael Mandel' => [ 0 => '236', ],
			'Walter Shapiro' => [ 0 => '237', ],
			'Harris Wofford' => [ 0 => '238', ],
			'Justin Peters' => [ 0 => '239', ],
			'Bradley Silverman' => [ 0 => '240', ],
			'Jeanne Lenzer and Keith Epstein' => [ 0 => '241', ],
			'Austin Frakt' => [ 0 => '242', ],
			'Aaron Carroll' => [ 0 => '243', ],
			'Jamie Merisotis' => [ 0 => '244', ],
			'Siyu Hu' => [ 0 => '245', ],
			'Elon Green' => [ 0 => '246', ],
			'Harold Pollack and Greg Anrig' => [ 0 => '247', ],
			'Rich Yeselson' => [ 0 => '248', ],
			'Kelly McEvers' => [ 0 => '249', ],
			'Larry Bartels' => [ 0 => '250', ],
			'Rob Atkinson' => [ 0 => '251', ],
			'Adele Stan' => [ 0 => '252', ],
			'Mark Jarmuth' => [ 0 => '253', ],
			'Matthew Zeitlin' => [ 0 => '254', ],
			'Paul Glastris, Ryan Cooper, and Siyu Hu' => [ 0 => '255', ],
			'Jamie Malanowski' => [ 0 => '108', ],
			'Debra Dickerson' => [ 0 => '109', 1 => '49', ],
			'Peter Laufer' => [ 0 => '110', ],
			'Markos Kounalakis' => [ 0 => '111', ],
			'Zachary Roth' => [ 0 => '112', 1 => '74', ],
			'Rachel Morris' => [ 0 => '113', 1 => '84', ],
			'T.A. Frank' => [ 0 => '114', 1 => '87', ],
			'Phil Keisling' => [ 0 => '115', ],
			'Ben Wallace-Wells' => [ 0 => '116', ],
			'Jacob Hacker' => [ 0 => '117', ],
			'Nick Penniman' => [ 0 => '118', ],
			'Washington Monthly Election Day Blog' => [ 0 => '119', ],
			'Windhorse' => [ 0 => '121', ],
			'Phillip Longman' => [ 0 => '122', ],
			'Eric Martin' => [ 0 => '123', ],
			'Neil Sinhababu' => [ 0 => '124', ],
			'dday' => [ 0 => '125', ],
			'Cheryl Rofer' => [ 0 => '126', ],
			'Inkblot' => [ 0 => '127', ],
			'David Moore' => [ 0 => '128', ],
			'Charles Homans' => [ 0 => '129', ],
			'Mariah Blake' => [ 0 => '130', ],
			'publius' => [ 0 => '131', ],
			'Michael Murphy' => [ 0 => '132', 1 => '138', ],
			'Jesse Singal' => [ 0 => '133', ],
			'Kevin Carey' => [ 0 => '134', ],
			'John Pollack' => [ 0 => '135', ],
			'The Editors' => [ 0 => '136', 1 => '145', ],
			'Ben Adler' => [ 0 => '137', ],
			'Avi Zenilman' => [ 0 => '139', ],
			'Washington Monthly Staff' => [ 0 => '140', ],
			'Ben Wildavsky' => [ 0 => '141', ],
			'Camille Esch' => [ 0 => '142', ],
			'Tim Murphy' => [ 0 => '143', ],
			'Erin Dillon' => [ 0 => '144', ],
			'Pedro de la Torre III' => [ 0 => '146', ],
			'Daniel Fromson' => [ 0 => '147', ],
			'Randolph Brickey' => [ 0 => '148', ],
			'Richard D. Kahlenberg' => [ 0 => '149', ],
			'Jon Marcus' => [ 0 => '150', ],
			'Daniel Luzer' => [ 0 => '151', ],
			'Jon Smith' => [ 0 => '152', ],
			'Paul Craft' => [ 0 => '153', ],
			'Eric Hoover' => [ 0 => '154', ],
			'Erin Carlyle' => [ 0 => '155', ],
			'Ben Miller and Phuong Ly' => [ 0 => '156', ],
			'Steven Hill' => [ 0 => '157', ],
			'Daniel Luzer and Ben Miller' => [ 0 => '158', ],
			'Guest Author' => [ 0 => '159', ],
			'Dean Pajevic' => [ 0 => '160', ],
			'Test Author' => [ 0 => '161', ],
			'Adam Dinwiddie' => [ 0 => '162', ],
			'Sebastian Jones' => [ 0 => '163', ],
			'Benjamin J. Dueholm' => [ 0 => '164', ],
			'John Gravois' => [ 0 => '165', ],
			'Marshall Allen' => [ 0 => '166', ],
			'Meg Stalcup and Joshua Craze' => [ 0 => '167', ],
			'Christopher Preble' => [ 0 => '168', ],
			'Matthew Yglesias' => [ 0 => '169', ],
			'Steven M. Teles' => [ 0 => '170', ],
			'Ed Kilgore' => [ 0 => '171', ],
			'Joshua Tucker' => [ 0 => '172', ],
			'Andrew Gelman' => [ 0 => '173', ],
			'Layered Tech' => [ 0 => '174', ],
			'Jonathan Bernstein' => [ 0 => '175', ],
			'Lee Drutman' => [ 0 => '176', ],
			'John Sides' => [ 0 => '177', ],
			'Mark Kleiman' => [ 0 => '178', ],
			'Jonathan Zasloff' => [ 0 => '179', ],
			'Henry Farrell' => [ 0 => '180', ],
			'Keith Humphreys' => [ 0 => '181', ],
			'Brendan Nyhan' => [ 0 => '182', ],
			'Michael O\'Hare' => [ 0 => '183', ],
			'Harold Pollack' => [ 0 => '184', ],
			'Lesley Rosenthal' => [ 0 => '185', ],
			'Andrew Sabl' => [ 0 => '186', ],
			'James Wimberley' => [ 0 => '187', ],
			'Erik Voeten' => [ 0 => '188', ],
			'Alyssa Rosenberg' => [ 0 => '189', ],
			'Barry C. Lynn' => [ 0 => '190', ],
			'Sylvester Schieber and Phillip Longman' => [ 0 => '191', ],
			'Peter Moskos' => [ 0 => '192', ],
			'Joshua Yaffa' => [ 0 => '193', ],
			'Jesse Zwick' => [ 0 => '194', ],
			'Jim Sleeper' => [ 0 => '196', ],
			'Melissa Bass' => [ 0 => '197', ],
			'Jacob Heilbrunn' => [ 0 => '198', ],
			'Thomas Toch' => [ 0 => '199', ],
			'Geoffrey Cain' => [ 0 => '200', ],
			'Jonathan Alter' => [ 0 => '201', ],
			'Stephen L. Carter' => [ 0 => '202', ],
			'William D. Cohan' => [ 0 => '203', ],
			'Albert Hunt' => [ 0 => '204', ],
			'Sarah Binder' => [ 0 => '205', ],
			'Kevin Drum' => [ 0 => '43', ],
			'Charles Peters' => [ 0 => '44', 1 => '95', ],
			'Stan Collender' => [ 0 => '45', ],
			'Samuel Knight' => [ 0 => '46', ],
			'Bruce Reed' => [ 0 => '48', 1 => '93', ],
			'Nick Confessore' => [ 0 => '50', ],
			'Amy Sullivan' => [ 0 => '51', 1 => '57', ],
			'Jay Jaroch' => [ 0 => '52', ],
			'Ezra Klein' => [ 0 => '54', ],
			'Benjamin Wallace-Wells' => [ 0 => '56', ],
			'Laura Rozen' => [ 0 => '58', ],
			'Brad Plumer' => [ 0 => '59', ],
			'Katha Pollitt' => [ 0 => '60', ],
			'Garance Franke-Ruta' => [ 0 => '61', ],
			'Julie Saltman' => [ 0 => '62', ],
			'Praktike' => [ 0 => '63', ],
			'Marc Lynch (Abu Aardvark)' => [ 0 => '64', ],
			'Dan Drezner' => [ 0 => '65', ],
			'Ezekiel Emanuel' => [ 0 => '66', ],
			'Michael Hiltzik' => [ 0 => '67', ],
			'Lindsay Beyerstein' => [ 0 => '68', ],
			'Leon Fuerth' => [ 0 => '69', ],
			'Jacob Hacker and Paul Pierson' => [ 0 => '70', ],
			'Avedon Carol' => [ 0 => '71', ],
			'Steve Benen' => [ 0 => '72', ],
			'Shannon Brownlee' => [ 0 => '73', ],
			'Hilzoy' => [ 0 => '75', ],
			'Shakespeare\'s Sister' => [ 0 => '76', ],
			'Christina Larson' => [ 0 => '77', ],
			'Jonathan Dworkin' => [ 0 => '78', ],
			'Stephanie Mencimer' => [ 0 => '79', ],
			'Roxanne Cooper' => [ 0 => '80', ],
			'Ogged' => [ 0 => '81', ],
			'Steve Waldman' => [ 0 => '82', ],
			'Rebecca Sinderbrand' => [ 0 => '83', 1 => '88', ],
			'Alan Wolfe' => [ 0 => '85', ],
			'Suzanne Nossel' => [ 0 => '86', ],
			'Paul Glastris' => [ 0 => '89', ],
			'Ruy Teixeira' => [ 0 => '91', ],
			'Matthew Cooper' => [ 0 => '92', ],
			'Nicholas Thompson' => [ 0 => '94', ],
			'Suzanne Mettler' => [ 0 => '96', ],
			'Christopher Hayes' => [ 0 => '97', ],
			'Steven Waldman' => [ 0 => '98', 1 => '665', ],
			'Benjamin Dueholm' => [ 0 => '99', ],
			'Paul Begala' => [ 0 => '100', ],
			'Justin Spees' => [ 0 => '101', ],
			'Gregg Easterbrook' => [ 0 => '102', ],
			'Douglas Brinkley' => [ 0 => '103', ],
			'Melinda Henneberger' => [ 0 => '104', ],
			'Avi Klein' => [ 0 => '105', ],
			'Ryan Grim' => [ 0 => '106', ],
			'James Verini' => [ 0 => '107', ],
		];
	}
}
