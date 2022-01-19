<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for The Bay Net.
 */
class TheBayNetMigrator implements InterfaceMigrator {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
			'newspack-content-migrator thebaynet-restructure-cats',
			[ $this, 'cmd_restructure_cats' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-fix-xmls',
			[ $this, 'cmd_fix_xmls' ],
			[
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'xml-file',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-update-user-fullnames',
			[ $this, 'cmd_update_user_fullnames' ],
			[
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'authors-file',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-unslugify-slugs',
			[ $this, 'cmd_unslugify_slugs' ],
			[
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'user-ids-csv',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-convert-users-to-gas',
			[ $this, 'cmd_convert_users_to_gas' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-remove-mmddyyyy-categories',
			[ $this, 'cmd_remove_mmddyyyy_categories' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator thebaynet-add-gallery-category',
			[ $this, 'cmd_add_gallery_category' ],
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_add_gallery_category( $args, $assoc_args ) {
		global $wpdb;

		$gal_cat = get_category_by_slug( 'gallery' );
		if ( false == $gal_cat ) {
			echo "gallery cat not found\n";
			exit;
		}

		$errors = [];
		$results = $wpdb->get_results( "select ID, post_content from wp_posts where post_type = 'post' and post_status = 'publish' and post_content like '%[gallery id%' ; ", ARRAY_A );
		foreach ( $results as $key_result => $result ) {
			$post_id = $result['ID'];
			echo sprintf( "%d/%d %d\n", $key_result+1, count($results), $post_id );

			$set = wp_set_post_categories( $post_id, [ $gal_cat->term_id ], true );
			if ( false == $set || is_wp_error( $set ) ) {
				$errors[] = $post_id;
			}
		}

		if ( ! empty( $errors) ) {
			echo sprintf( "errors:\n%s\n", implode(',', $errors));
		}
		return;
	}
	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_remove_mmddyyyy_categories( $args, $assoc_args ) {
		$categories = get_categories([
			'hide_empty'             => false,
			'number' => 0,
		]);

		$not_deleted = [];
		$error = [];
		foreach ( $categories as $key_category => $category ) {
			echo sprintf( "%d/%d %d %s\n", $key_category+1, count( $categories ), $category->term_id, $category->name );
			$matches = [];
			preg_match( '|(\d{2}/\d{2}/\d{4})|', $category->name, $matches );
			if ( isset( $matches[1] ) && $matches[1] == $category->name ) {
				$deleted = wp_delete_category( $category->term_id );
				if ( is_wp_error( $deleted ) ) {
					$error[ $category->term_id ] = $category->name;
				} else {
					$deleted[ $category->term_id ] = $category->name;
				}
			} else {
				$not_deleted[ $category->term_id ] = $category->name;
			}
		}

		echo sprintf( "\nnot deleted:\n%s\n", implode( ',', array_keys( $not_deleted ) ) );
		echo sprintf( "\nerrors:\n%s\n", implode( ',', array_keys( $error ) ) );
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_convert_users_to_gas( $args, $assoc_args ) {
		$cap_logic = new CoAuthorPlus;
		global $wpdb;

		$results = $wpdb->get_results( "select * from wp_users where user_email = '' ; ", ARRAY_A );
		// $results = $wpdb->get_results( "select * from wp_users where ID in (
		//      6665,7044,6317,6090,7120,6085,6885,7328,7284,7056,7184,5760,6956,6354,7088,6434,6741,7319,7267,5702,4496,6030,6671,5832,5824,6894,7086,6472,6212,5204,6077,6731,7105,6908,7179,6937,5853,7206,7282,7067,6171,6359,6523,5695,6361,6326,6163,5798,6258,6193,6294,6893,7154,6033,6043,7325,5700,7247,6499,6878,6421,5970,5976,6449,5699,5761,7194,6496,7134,4393,6052,5991,6412,6268,7202,5979,6269,6170,5903,6096,7065,6813,6553,6351,6578,5931,6784,6098,7109,6355,6448,7167,6622,7195,6256,6546,6476,7326,4544,6733,6942,6943,6492,6494,7294,7228,6964,4655,6666,4252,4451,4152,4024,6855,6899,6839,6959,6297,4664,5128,4467,5398,6659,6924,6658,4377,5519,6547,4205,4362,5631,6757,4606,6982,4777,4879,6846,7253,6752,4674,4055,6972,5329,5213,5395,4364,4154,6515,6184,6756,6977,4515,4411,5550,6334,5632,5086,5238,5240,6973,6843,6298,6295,4611,4175,4815,7258,6903,6904,6133,4659,6296,4484,4358,5406,6191,6907,6902,4004,6755,3986,6386,4040,5891,5633,4494,6377,4429,7177,6838,6533,6204,4581,4978,4466,4717,6568,4320,5998,6990,6606,5694,4282,6604,6906,5511,6975,6614,6521,6176,6955,5847,6474,6624,6626,5634,6150,7327,4713,6744,4167,6032,6247,6250,6070,5812,5902,6429,4051,6615,7024,7123,6005,6433,4483,5698,6608,6042,6729,5203
		// ) ; ", ARRAY_A );

		foreach ( $results as $key_result => $result ) {
			$user_id = $result['ID'];
			$display_name = $result['display_name'];
			try {

				// Create GA
				$ga_id = $cap_logic->create_guest_author([
					'display_name' => $display_name,
				]);

				// Assign posts to GA.
				$post_results = $wpdb->get_results( "select * from wp_posts where post_author = $user_id and post_type in ('post', 'page') ; ", ARRAY_A );

				foreach ( $post_results as $key_post => $post_result ) {
					echo sprintf( "%d/%d %d -- %d/%d\n", $key_result+1, count($results), $user_id, $key_post+1, count($post_results) );
					$post_id = $post_result['ID'];
					$cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id );
				}

				$user = get_user_by( 'ID', $user_id );
				$cap_logic->link_guest_author_to_wp_user( $ga_id, $user );

			} catch ( \Exception $e ) {
				$d=1;
			}
		}

		wp_cache_flush();
		$d=1;
	}

	/**
	 * Prepare unslugified version of username slugs (logins).
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_unslugify_slugs( $args, $assoc_args ) {
		$user_ids_csv = $assoc_args['user-ids-csv'] ?? null;
		global $wpdb;

		$results = $wpdb->get_results( "select ID, user_login from wp_users where ID in ($user_ids_csv);", ARRAY_A );
		foreach ( $results as $key_result => $result ) {
			$user_id = $result['ID'];
			$user_login = $result['user_login'];
			$unslugified_login = ucwords( str_replace( '-', ' ', $user_login ), ' ' );
			echo sprintf( "$user_id \n$user_login \n$unslugified_login \n\n" );
		}
	}

	/**
	 * Update author names with their full names.
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_user_fullnames( $args, $assoc_args ) {
		$authors_file = $assoc_args[ 'authors-file' ] ?? null;
		global $wpdb;

		$authors = include $authors_file;
		foreach ( $authors as $author_nicename => $author_full_name ) {
			if ( strlen( $author_nicename ) > 60 ) {
				$author_nicename_60 = substr( $author_nicename, 0, 60 );
				unset( $authors[ $author_nicename ] );
				$authors[ $author_nicename_60 ] = $author_full_name;
			}
		}
		$results = $wpdb->get_results( "select ID, user_login, user_nicename from wp_users;", ARRAY_A );
		$not_updated_user_ids = [];
		$error_ids = [];
		foreach ( $results as $key_result => $result ) {
			$user_ID = $result['ID'];
			$user_login = $result['user_login'];
			// User_nicename is what's displayed on post template.
			$user_nicename = $result['user_nicename'];

			echo sprintf( "%d/%d ID %d\n", $key_result+1, count($results), $user_ID );

			if ( isset( $authors[ $user_nicename ] ) ) {
				$author_display_name = $authors[ $user_nicename ];
				$author_display_name_60 = substr( $author_display_name, 0, 60);
				$updated = wp_update_user( array(
					'ID' => $user_ID,
					// Can be long.
					'first_name' => $author_display_name,
					'nickname' => $author_display_name,
					'display_name' => $author_display_name,
					// 60 chars max.
					'user_nicename' => $author_display_name_60,
				) );
				if ( is_wp_error( $updated ) ) {
					$d=1;
					$error_ids[] = $user_ID;
					echo sprintf( "xxx\n" );
				}
			} else {
				$not_updated_user_ids[] = $user_ID;
				echo sprintf( "---\n" );
			}
		}

		echo sprintf( "not updated IDs:\n%s", implode(',', $not_updated_user_ids) );
		echo sprintf( "error IDs:\n%s", implode(',', $error_ids) );
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_xmls( $args, $assoc_args ) {
		$xml_file = $assoc_args[ 'xml-file' ] ?? null;
		$contents = file_get_contents( $xml_file );
		$contents_exploded = explode( "\n", $contents );
		$updated_content = false;

		foreach ( $contents_exploded as $key_line => $line ) {

			if ( false != strpos( $line, '<dc:creator></dc:creator>' ) ) {
				continue;
			}

			if ( false != strpos( $line, '<dc:creator>' ) ) {
				$start_string = '<dc:creator><![CDATA[';
				$pos_start = strpos( $line, $start_string );
				if ( false == $pos_start ) {
					\WP_CLI::error( sprintf( 'not found %s line %d', $start_string, $key_line + 1 ) );
				}
				$pos_start += strlen( $start_string );

				$end_string = ']]></dc:creator>';
				$pos_end = strpos( $line, $end_string );
				if ( false == $pos_end ) {
					\WP_CLI::error( sprintf( 'not found %s line %d', $end_string, $key_line + 1 ) );
				}

				$author = substr( $line, $pos_start, $pos_end - $pos_start );
				if ( strlen( $author ) > 60 ) {
					$new_author = substr( $author, 0, 60 );
					$new_line = substr( $line, 0, $pos_start )
						. $new_author
						. $end_string;
					$contents_exploded[ $key_line ] = $new_line;

					\WP_CLI::log( '  + shortened line CDATA ' . ( $key_line + 1 ) . "\n" );
					$updated_content = true;
				}
			}
		}

		if ( true == $updated_content ) {
			$pathinfo = pathinfo( $xml_file );
			$new_xml_file = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '_FIXED.' . $pathinfo['extension'];

			$saved = file_put_contents( $new_xml_file, implode( "\n", $contents_exploded ) );
			if ( false == $saved ) {
				// debug;
				$d=1;
			}
			\WP_CLI::success( 'updated ' . $new_xml_file );
		}

		\WP_CLI::log( 'done' );
	}

	/**
	 * Migrate video content from meta into regular post content.
	 */
	public function cmd_restructure_cats( $args, $assoc_args ) {

		$time_start = microtime( true );

		WP_CLI::line( '1/4 Converting Categories to Tags.' );
		$this->convert_categories_to_tags( [
			"Anne Arundel County" => "Anne Arundel County",
			"Calvert County" => "Calvert County",
			"Charles County" => "Charles County",
			"Prince George's County" => "Prince George's County",
			"St. Mary's County" => "St. Mary's County",
			"*LiveUpdates" => "*LiveUpdates",
			"Holiday" => "Holiday",
			"Recipes" => "Recipes",
			"Spring" => "Spring",
			"Holiday Spotlight" => "Holiday",
			"holiday spotlight" => "Holiday",
			"*pressreleases" => "*pressreleases",
			"*topheadlines" => "breaking news",
		] );


		WP_CLI::line( '2/4 Renaming Categories.' );
		$this->rename_categories( [
			"*TheBayToday" => "My Town",
			"Crime" => "Crime & Courts",
			"Courts" => "Crime & Courts",
			"Lifestyle" => "Community",
			"Family" => "Community",
			"Arts & Theater" => "Culture",
			"Elections" => "Government & Politics",
			"Attractions" => "My Town",
			"Community Spotlight" => "Community",
			"Notable Death" => "Community",
			"Health & Wellness Spotlight" => "Health & Wellness",
			"Worship" => "Community",
			"Government Spotlight" => "Government & Politics",
			"First Responders" => "Fire & Rescue",
			"Lifestyle Spotlight" => "Community - Lifestyle",
			"Automotive Spotlight" => "Classifieds - Automotive",
			"Pet of the Week" => "Classifieds - Pets",
			"Public Libraries of Southern Maryland" => "Community",
			"Dr. Jay Lipoff" => "Community",
			"Economy & Business Spotlight" => "Economy & Business",
			"Home and Garden Spotlight" => "Home & Garden",
			"Great Mills" => "Community",
			"Real Estate Spotlight" => "Real Estate Listings",
			"Health" => "Health & Wellness",
			"Politics" => "Government & Politics",
			"Opinion" => "Opinions",
			// ---- 2nd round of Category renaming
			"Community" => "My Town",
			"Community - Lifestyle" => "My Town - Lifestyle",
			"Culture" => "My Town",
			"First Responder Spotlight" => "Fire & Rescue",
			"Public Works" => "Government & Politics",
			"Real Estate Listings" => "Real Estate",
			"Southern Maryland Business" => "Economy & Business",
			"Travel & Tourism" => "My Town",
			"Wedding Spotlight" => "Weddings",
			// ---- 3rd round of Category renaming
			"Outdoors Spotlight" => "Outdoors",
			"Classifieds - Automotive" => "Automotive",
			"Classifieds - Pets" => "Pets",
		] );

		WP_CLI::line( '3/4 Removing Categories.' );
		$this->remove_categories( [ "Uncategorised", ] );


		/**
		 * Do this one manually since there's only one such case:
		 *      - "Entertainment Spotlight" Cat to become "Enterainment" which is a subcategory of "My Town".
		 */
		WP_CLI::line( '4/4 Relocating `Entertainment Spotlight` to `My Town` > `Entertainment`.' );
		// Get current Cat.
		$cat_name_old = 'Entertainment Spotlight';
		$category_old_id = get_cat_ID( $cat_name_old );
		if ( 0 === $category_old_id ) {
			WP_CLI::warning( sprintf( 'Old Cat %s not found', $cat_name_old ) );
			$this->log( 'tbn__relocate_categories__oldCatNotFound.log', sprintf( "%s", $cat_name_old ) );
		}
		// Get destination Cat.
		$cat_new_parent_name = 'My Town';
		$cat_new_name = 'Entertainment';
		$category_new_parent_id = get_cat_ID( $cat_new_parent_name );
		if ( $category_new_parent_id ) {
			$category_new_id = wp_create_category( $cat_new_name, $category_new_parent_id );
		}
		if ( ! isset( $category_new_id ) || ! $category_new_id || is_wp_error( $category_new_id ) ) {
			WP_CLI::warning( sprintf( 'Could not create/fetch new Cat %s', $cat_new_name ) );
			$this->log( 'tbn__rename_categories__newCatNotCreated.log', sprintf( "%s", $cat_new_name ) );
		}
		if ( 0 != $category_old_id && 0 != $category_new_id ) {
			WP_CLI::line( sprintf( 'Fetching Posts in Cat %d `%s`...', $category_old_id, $cat_name_old ) );
			$posts = $this->get_all_posts_in_category( $category_old_id );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Working', count( $posts ) );

			WP_CLI::line( sprintf( 'Moving %d Posts from CatID %d `%s` to CatID %d `%s`', count( $posts ), $category_old_id, $cat_name_old, $category_new_id, $cat_new_name ) );
			foreach ( $posts as $post ) {
				$progress->tick();
				// Remove old Cat.
				wp_remove_object_terms( $post->ID, $category_old_id, 'category' );
				// Set new Cat.
				wp_set_post_categories( $post->ID, $category_new_id, true );
			}
			$progress->finish();

			// Delete cat, or log if posts still found there.
			$posts = $this->get_all_posts_in_category( $category_old_id );
			if ( empty( $posts ) ) {
				wp_delete_category( $category_old_id );
				WP_CLI::success( sprintf( 'Cat %d `%s` deleted.', $category_old_id, $cat_name_old ) );
			} else {
				WP_CLI::warning( sprintf( 'Cat %d `%s` not emptied out successfully', $category_old_id, $cat_name_old ) );
				$this->log( 'tbn__convert_entertainment_cat__catNotEmpty.log', sprintf( "%d %s", $category_old_id, $cat_name_old ) );
			}
		}


		wp_cache_flush();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

	}

	/**
	 * Converts Categories to Tags.
	 *
	 * @param array $mapping Keys are Category names, values are Tag names.
	 */
	private function convert_categories_to_tags( $mapping ) {
		foreach ( $mapping as $cat_name => $tag_name ) {
			$category_id = get_cat_ID( $cat_name );
			if ( 0 == $category_id ) {
				WP_CLI::warning( sprintf( 'Cat %s not found', $cat_name ) );
				$this->log( 'tbn__convert_categories_to_tags__catNotFound.log', sprintf( "%s", $cat_name ) );
				continue;
			}

			WP_CLI::line( sprintf( 'Fetching Posts in Cat %d `%s`...', $category_id, $cat_name ) );
			$posts = $this->get_all_posts_in_category( $category_id );

			WP_CLI::line( sprintf( 'Converting Category `%s` to Tag `%s` for %d Posts...', $cat_name, $tag_name, count( $posts ) ) );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Working', count( $posts ) );
			foreach ( $posts as $post ) {
				$progress->tick();
				// Remove Cat.
				wp_remove_object_terms( $post->ID, $category_id, 'category' );
				// Set Tags.
				wp_set_post_tags( $post->ID, $tag_name, true );
			}
			$progress->finish();

			// Delete cat, or log if posts still found there.
			$posts = $this->get_all_posts_in_category( $category_id );
			if ( empty( $posts ) ) {
				wp_delete_category( $category_id );
				WP_CLI::success( sprintf( 'Cat %d `%s` converted to Tag `%s` and deleted.', $category_id, $cat_name, $tag_name ) );
			} else {
				WP_CLI::warning( sprintf( 'Cat %d %s not emptied out successfully', $category_id, $cat_name ) );
				$this->log( 'tbn__convert_categories_to_tags__catNotEmpty.log', sprintf( "%d %s", $category_id, $cat_name ) );
			}
		}
	}

	/**
	 * Renames Category -- by creating a new one, and removing all Posts from current Cat and adding the new Cat to the Post,
	 * and finally deleting the old Cat.
	 *
	 * @param array $mapping Keys are current Cat name, values are new Cat name.
	 */
	private function rename_categories( $mapping ) {
		foreach ( $mapping as $cat_name_old => $cat_name_new ) {
			$category_old_id = get_cat_ID( $cat_name_old );
			if ( 0 === $category_old_id ) {
				WP_CLI::warning( sprintf( 'Old Cat %s not found', $cat_name_old ) );
				$this->log( 'tbn__rename_categories__oldCatNotFound.log', sprintf( "%s", $cat_name_old ) );
				continue;
			}

			$category_new_id = wp_create_category( $cat_name_new );
			if ( ! $category_new_id || is_wp_error( $category_new_id ) ) {
				WP_CLI::warning( sprintf( 'Could not create/fetch new Cat `%s`', $cat_name_new ) );
				$this->log( 'tbn__rename_categories__newCatNotCreated.log', sprintf( "%s", $cat_name_new ) );
				continue;
			}

			WP_CLI::line( sprintf( 'Fetching Posts in Cat %d `%s`...', $category_old_id, $cat_name_old ) );
			$posts = $this->get_all_posts_in_category( $category_old_id );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Working', count( $posts ) );

			WP_CLI::line( sprintf( 'Renaming Category `%s` to `%s` for %d Posts', $cat_name_old, $cat_name_new, count( $posts ) ) );
			foreach ( $posts as $post ) {
				$progress->tick();
				// Remove old Cat.
				wp_remove_object_terms( $post->ID, $category_old_id, 'category' );
				// Set new Cat.
				wp_set_post_categories( $post->ID, $category_new_id, true );
			}
			$progress->finish();

			// Delete cat, or log if posts still found there.
			$posts = $this->get_all_posts_in_category( $category_old_id );
			if ( empty( $posts ) ) {
				wp_delete_category( $category_old_id );
				WP_CLI::success( sprintf( 'Cat %d `%s` converted to Cat %d `%s` and deleted.', $category_old_id, $cat_name_old, $category_new_id, $cat_name_new ) );
			} else {
				WP_CLI::warning( sprintf( 'Cat %d %s not emptied out successfully', $category_old_id, $cat_name_old ) );
				$this->log( 'tbn__rename_categories__catNotEmpty.log', sprintf( "%d %s", $category_old_id, $cat_name_old ) );
			}
		}
	}

	/**
	 * Makes all posts in a Category uncategorized.
	 *
	 * @param array $categories Category names.
	 */
	private function remove_categories( $categories ) {
		foreach ( $categories as $category_name ) {
			$category_id = get_cat_ID( $category_name );
			if ( 0 === $category_id ) {
				WP_CLI::warning( sprintf( 'Cat %s not found', $category_name ) );
				$this->log( 'tbn__remove_categories__CatNotFound.log', sprintf( "%s", $category_name ) );
				continue;
			}

			WP_CLI::line( sprintf( 'Fetching Posts in Cat %d `%s`...', $category_id, $category_name ) );
			$posts = $this->get_all_posts_in_category( $category_id );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Working', count( $posts ) );

			WP_CLI::line( sprintf( 'Removing Category `%s` from %d Posts', $category_name, count( $posts ) ) );
			foreach ( $posts as $post ) {
				$progress->tick();
				wp_remove_object_terms( $post->ID, $category_id, 'category' );
			}
			$progress->finish();

			// Delete cat, or log if posts still found there.
			$posts = $this->get_all_posts_in_category( $category_id );
			if ( empty( $posts ) ) {
				wp_delete_category( $category_id );
				WP_CLI::success( sprintf( 'Cat %d `%s` deleted.', $category_id, $category_name ) );
			} else {
				WP_CLI::warning( sprintf( 'Cat %d `%s` not emptied out successfully', $category_id, $category_name ) );
				$this->log( 'tbn__remove_categories__catNotEmpty.log', sprintf( "%d %s", $category_id, $category_name ) );
			}
		}
	}

	/**
	 * Returns all Posts in Category.
	 *
	 * @param int $category_id Cat ID.
	 *
	 * @return int[]|\WP_Post[]
	 */
	private function get_all_posts_in_category( $category_id ) {
		return get_posts( [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			// `'post_status' => 'any'` doesn't work as expected.
			'post_status'    => [ 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' ],
			'cat'            => $category_id,
		] );
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
