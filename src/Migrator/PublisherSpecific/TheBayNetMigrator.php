<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
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
