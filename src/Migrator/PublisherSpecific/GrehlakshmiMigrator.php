<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Grehlakshmi.
 */
class GrehlakshmiMigrator implements InterfaceMigrator {

	const EXPORT_FILE_NAME = 'grehlakshmi_export_%d.xml';
	const EXPORT_BATCH = 100;

	const LOG_PARSED  = 'grehlakshmi__parsed.log';
	const LOG_SKIPPED = 'grehlakshmi__skipped.log';

	const LOG_CATS_ASSIGNED = 'grehlakshmi__catsAssigned.log';
	const LOG_GA_ASSIGNED   = 'grehlakshmi__guestAuthorsAssigned.log';
	const LOG_GA_ERR        = 'grehlakshmi__guestAuthorsErrors.log';
	const LOG_FEATUREDIMG_SET = 'grehlakshmi__featuredImageSet.log';
	const LOG_FEATUREDIMG_ERR = 'grehlakshmi__featuredImageErr.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->attachments_logic = new AttachmentsLogic();
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
			'newspack-content-migrator grehlakshmi-import-xmls',
			[ $this, 'cmd_import_xmls' ],
			[
				'shortdesc' => 'Imports Grehlakshmi custom XML content.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator grehlakshmi-update-imported-posts-meta',
			[ $this, 'cmd_update_imported_posts_meta' ],
			[
				'shortdesc' => 'Updates all imported Post\' Tags, Categories, and properly sets all their info from metas.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator grehlakshmi-delete-all-kreatio-meta',
			[ $this, 'cmd_delete_all_kreatio_post_meta' ],
			[
				'shortdesc' => 'Deletes all the Post metas with imported Kreatio post data.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator grehlakshmi-remap-categories',
			[ $this, 'cmd_remap_categories' ],
			[
				'shortdesc' => 'Remaps Grehlakshmi categories.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_import_xmls( $args, $assoc_args ) {
		// $xml_file = isset( $assoc_args[ 'xml-file' ] ) ? true : false;

		$time_start = microtime( true );

		// Live exports.
		$xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/1/Kreatio_export/XML_data/delta_export.xml';
		// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/1/Kreatio_export/XML_data/export.xml';

		$line_number       = 0;
		$lines_total       = $this->count_file_lines( $xml_file );
		$articles_exported = 0;
		$xml_files_created = [];

		// Parse one '<wp:article>' at a time.
		if ( $handle = fopen( $xml_file, 'r' ) ) {

			$progress = \WP_CLI\Utils\make_progress_bar( 'XML processed', $lines_total );
			$data     = $this->get_empty_data_array();
			while ( ( $line = fgets( $handle ) ) !== false ) {

				// Line progress.
				$progress->tick();
				$line_number++;

				if ( 0 === strpos( $line, '<wp:article>' ) ) {
					$wp_article_xml = '';
					$wp_article_xml .= $line;
				} else if ( 0 === strpos( $line, '</wp:article>' ) ) {
					$this_article_data = [];

					$articles_exported++;
					$wp_article_xml .= $line;

					// Remove the undefined XML namespace and load up the \SimpleXMLElement object.
					$article_xml = str_replace( '<wp:', '<', $wp_article_xml );
					$article_xml = str_replace( '</wp:', '</', $article_xml );
					$xml         = simplexml_load_string( $article_xml );

					// Parse article.
					$this_article_data[ 'posts' ][] = $this->parse_xml_article( $xml, $xml_file );

					// Export this article only if the '_kreatio_article_id' postmeta doesn't exist already.
					$_kreatio_article_id = $this_article_data[ 'posts' ][0][ 'meta'][ '_kreatio_article_id' ] ?? null;
					$this_article_kreatio_id_meta = $this->get_meta( '_kreatio_article_id', $_kreatio_article_id );
					$this_meta_exists = ! empty( $this_article_kreatio_id_meta );
					if ( $_kreatio_article_id && ! $this_meta_exists ) {
						$data = array_merge_recursive( $data, $this_article_data );

						// Mute, too much info on screen. It's logged anyways.
						// WP_CLI::line( sprintf( '+ (%d) article_id %s', $articles_exported, $_kreatio_article_id ) );

						$this->log( self::LOG_PARSED, $_kreatio_article_id );
					} else {
						WP_CLI::warning( sprintf( 'x (%d) article_id %s exists, skipping.', $articles_exported, $_kreatio_article_id ) );
						$this->log( self::LOG_SKIPPED, $_kreatio_article_id );
					}

					// Export batches of articles to WXR.
					if ( count( $data[ 'posts' ] ) >= self::EXPORT_BATCH ) {
						\Newspack_WXR_Exporter::generate_export( $data );

						$xml_files_created[] = $data[ 'export_file' ];
						WP_CLI::success( sprintf( "\n" . 'Exported to file %s ...', $data[ 'export_file' ] ) );
						$data = $this->get_empty_data_array();
					}

				} else {
					$wp_article_xml .= $line;
				}

			}

			// Export the remaining articles to WXR.
			if ( count( $data[ 'posts' ] ) > 0 ) {
				\Newspack_WXR_Exporter::generate_export( $data );
				$xml_files_created[] = $data[ 'export_file' ];
				WP_CLI::success( sprintf( "\n" . 'Exported to file %s ...', $data[ 'export_file' ] ) );
			}

			fclose( $handle );
			$progress->finish();

		} else {
			\WP_CLI::error( sprintf( 'Error opening the file %s', $xml_file ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		WP_CLI::line( sprintf( '--- Total %d articles', $articles_exported ) );
		WP_CLI::line( sprintf(
			'--- Total %d WXR files created -- from %s to %s',
			count( $xml_files_created ),
			$xml_files_created[0] ?? '',
			$xml_files_created[ count( $xml_files_created ) - 1 ] ?? ''
		) );

		WP_CLI::warning( 'After importing these XMLs, remember to run `newspack-content-migrator grehlakshmi-update-imported-posts-meta` to create remaining data objects from Kreatio metas.' );
	}

	/**
	 * Callable for `newspack-content-migrator update-imported-posts`
	 */
	public function cmd_update_imported_posts_meta( $args, $assoc_args ) {
		$time_start = microtime( true );

		$posts = $this->posts_logic->get_all_posts( 'post', [ 'publish', 'pending', 'draft' ] );
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d', $key_posts + 1, count( $posts ), $post->ID ) );

			// Set Categories.
	 		$categories_meta = get_post_meta( $post->ID, '_kreatio_categories' );
	 		if ( ! empty( $categories_meta ) ) {
				$categories_meta_decoded = json_decode( $categories_meta[0], true );

		        $all_categories = [];
		        foreach ( $categories_meta_decoded as $category_meta ) {

		            // Create the Category tree.
		            $category_breadcrumbs = $category_meta[ 'article_taxonomy_properties_full_name' ];
		            $breadcrumbs = str_replace( 'Category >> ', '', $category_breadcrumbs );
		            $kreatio_categories = explode( ' >> ', $breadcrumbs );
				    $category_id = null;
		            foreach ( $kreatio_categories as $key_kreatio_categories => $kreatio_category ) {
		                $parent_id = ( 0 == $key_kreatio_categories ) ? 0 : $category_id;
		                $category_id = wp_create_category( $kreatio_category, $parent_id );
			        }

		            // Use the last child Category.
		            if ( $category_id ) {
				        $all_categories[ $category_id ] = $kreatio_category;
			        }
			    }

		        // Set Categories.
		        if ( ! empty( $all_categories ) ) {
	                wp_set_post_categories( $post->ID, array_keys( $all_categories ) );
	                $this->log( self::LOG_CATS_ASSIGNED, sprintf(
	                    '%d %s %s',
		                $post->ID,
		                implode( ',', array_keys( $all_categories ) ),
		                implode( ',', array_values( $all_categories ) )
	                ) );
			    }
		    }


			// Set Guest Authors.
			$authors_meta = get_post_meta( $post->ID, '_kreatio_authors_meta' );
			if ( ! empty( $authors_meta ) ) {
				$authors_meta_decoded = json_decode( $authors_meta[0], true );

				$all_guest_authors = [];
				foreach ( $authors_meta_decoded as $author_id => $author_meta ) {
					$author_email    = $author_meta[ 'article_author_email' ] ?? null;
					$author_fullname = $author_meta[ 'article_author_fullname' ] ?? null;

					// Create the GAs.
					try {
						$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [
							'display_name' => $author_fullname,
							'user_email' => $author_email,
						] );
						if ( is_wp_error( $guest_author_id ) ) {
							throw new \RuntimeException( $guest_author_id->get_error_message() );
						}

						$all_guest_authors[] = $guest_author_id;

					} catch ( \Exception $e ) {
						WP_CLI::warning( sprintf( "   - could not create GA full name '%s' and email %s", $author_fullname, $author_email ) );
					    $this->log( self::LOG_GA_ERR, sprintf(
					        '%d %s %s',
					        $post->ID,
						    $author_fullname,
						    $author_email
					    ) );
					}
				}

				// Assign the Authors.
				if ( ! empty( $all_guest_authors ) ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $all_guest_authors, $post->ID );
					$this->log( self::LOG_GA_ASSIGNED, sprintf(
						'%d %s',
						$post->ID,
						implode( ',', $all_guest_authors )
					) );
					WP_CLI::success( sprintf( "   + assigned GA IDs %s", implode( ',', $all_guest_authors ) ) );
				}
			}


			// Set the Featured image.
			$kreatio_thumbnail_image_meta = get_post_meta( $post->ID, '_kreatio_article_thumbnail_image_url' );
			if ( ! empty( $kreatio_thumbnail_image_meta ) ) {
				$thumbnail_image_url = $kreatio_thumbnail_image_meta[0];
				$featured_image_id = $this->attachments_logic->import_external_file( $thumbnail_image_url );
				if ( is_wp_error( $featured_image_id ) ) {
					$this->log( self::LOG_FEATUREDIMG_ERR, sprintf(
						'%d %s %s',
						$post->ID,
						$thumbnail_image_url,
						$featured_image_id->get_error_message()
					) );
				} else {
				    update_post_meta( $post->ID, '_thumbnail_id', $featured_image_id );
					WP_CLI::success( sprintf( "   + set Featured Image %d from  %s", $featured_image_id, $thumbnail_image_url ) );
					$this->log( self::LOG_FEATUREDIMG_SET, sprintf(
						'%d %s %s',
						$post->ID,
						$featured_image_id,
						$thumbnail_image_url
					) );
				}
			}

		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator delete-all-kreatio-post-metas`
	 */
	public function cmd_delete_all_kreatio_post_meta( $args, $assoc_args ) {
		WP_CLI::error( 'TODO -- command not yet available.' );
	}

	/**
	 * Callable for `newspack-content-migrator grehlakshmi-remap-categories`.
	 */
	public function cmd_remap_categories( $args, $assoc_args ) {
		$time_start = microtime( true );

		$cat_remappings = $this->get_cat_remappings();
		foreach ( $cat_remappings as $key_cat_remapping => $cat_remapping ) {

			WP_CLI::line( sprintf( '(%d/%d)', $key_cat_remapping + 1, count( $cat_remappings) ) );

			// Get the source category from the category URL segment (containing `/`-separated slugs from parent to final child).
			$exploded_source_slugs = explode( '/', $cat_remapping[ 'source_cat_urlslugs' ] );
			$source_cat_slug = trim( $exploded_source_slugs[ count( $exploded_source_slugs ) - 1 ] );
			$source_cat = get_category_by_slug( $source_cat_slug );
			$source_cat_id = $source_cat->term_id ?? null;
			if ( null === $source_cat_id ) {
				WP_CLI::warning( sprintf( 'Err Source Cat not found -- %s , %s', $source_cat_slug, $cat_remapping[ 'source_cat_urlslugs' ] ) );
				$this->log( 'g_restructure_ErrSourceCatNotFound.log', sprintf( '%s ; %s', $source_cat_slug, $cat_remapping[ 'source_cat_urlslugs' ] ) );
				continue;
			}

			// Get the destination category, either by picking the parent, or the child.
			$destination_cat_parent_name = trim( $cat_remapping[ 'destination_cat_parent' ] );
			$destination_cat_child_name = trim( $cat_remapping[ 'destination_cat_child' ] );
			$destination_cat_parent_id = wp_create_category( $destination_cat_parent_name );
			$destination_cat_id = $destination_cat_parent_id;
			if ( ! empty( $destination_cat_child_name ) ) {
				$destination_cat_child_id = wp_create_category( $destination_cat_child_name, $destination_cat_parent_id );
				$destination_cat_id = $destination_cat_child_id;
			}

			$msg = sprintf(
				'Moving content from "%s" (catID %d) to => "%s%s" (catID %d) ...',
				$cat_remapping[ 'source_cat_urlslugs' ],
				$source_cat_id,
				$destination_cat_parent_name,
				( ! empty( $destination_cat_child_name ) ? '/' . $destination_cat_child_name : '' ),
				$destination_cat_id
			);
			WP_CLI::line( $msg );
			$this->log( 'g_restructure_.log', sprintf( '(%d/%d)', $key_cat_remapping + 1, count( $cat_remappings) ) . ' ' . $msg );

			// Move all categories and their content (Posts, Subcategories with their Posts) from the source Category to the destination Category.
			$this->relocate_category_tree_with_content( $source_cat_id, $destination_cat_id );

			// Just another spacer.
			$this->log( 'g_restructure_.log', "\n" );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Just a caller/wrapper function for the recursive function which relocates the category tree with its content from the
	 * source Category to the destination Category.
	 */
	private function relocate_category_tree_with_content( $source_cat_id, $destination_cat_id ) {
		$source_lineage_stack = [];
		$this->recusive_category_dive( $source_lineage_stack, $source_cat_id, $destination_cat_id );
	}

	/**
	 * Recursively reconstructs categories from a source parent category, to the new destination category, and relocates all their
	 * contents:
	 *  - recursively searches all final child categories in the source cat,
	 *  - traces from the final child categories back to the source parent category, and on its way back it moves all content from
	 *    the current node to new destination node, which is rooted in the destination cat.
	 *
	 * @param array $source_lineage_stack      This stack is a way of always knowing all the IDs from the current category up to
	 *                                         the original source category.
	 *                                         Array containing this category's (the category in this recursion call) parent stack,
	 *                                         element 0 is the original source parent, element 1 its next child, etc., while the
	 *                                         last element in the stack will become this current category -- $this_cat_id.
	 * @param int   $this_cat_id               This Category ID.
	 * @param int   $destination_parent_cat_id The destination parent Category ID.
	 */
	private function recusive_category_dive( &$source_lineage_stack, $this_cat_id, $destination_parent_cat_id ) {

		// Add this term_id to the parent stack, to keep trace of this cat's lineage/hierarchy.
		$source_lineage_stack[] = $this_cat_id;

		$children_cats = get_categories( [
			'parent' => $this_cat_id,
			'hide_empty' => false,
			'number' => 0,
		] );

		// Recursively dive into all children nodes.
		if ( ! empty( $children_cats ) ) {
			foreach ( $children_cats as $child_cat ) {
				$this->recusive_category_dive( $source_lineage_stack, $child_cat->term_id, $destination_parent_cat_id );
			}
		}

		// Recreate the new category tree.
		foreach ( $source_lineage_stack as $key_ancestor => $ancestor_id ) {
			if ( 0 == $key_ancestor ) {
				// If this is the source parent, use the destination parent.
				$destination_cat = get_category( $destination_parent_cat_id );
			} else {
				// If this is a child, create or get the cat with the same name.
				$this_category_name = trim( get_category( $ancestor_id )->name );
				$this_category_parent = $destination_cat->term_id;
				// \wpdb::process_fields is returning false for [`slug`]['value'] in a \wp_insert_term's call due to a charset check mismatch. E.g.:
				//      "https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
				$destination_cat_id = wp_create_category( $this_category_name, $this_category_parent );
				$destination_cat = get_category( $destination_cat_id );
			}
		}

		// Last remaining $current_lineage_cat is our destination.
		$source_cat_id = $ancestor_id;
		$destination_cat_id = $destination_cat->term_id;

		// Move content from this source category to the destination category.
		$posts = get_posts( [
			'numberposts' => 0,
			'category'    => $source_cat_id,
			'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ],
		] );
		$this->log( 'g_restructure_.log', sprintf( '- moving %d Posts from CatID %d to CatID %d', count( $posts ), $source_cat_id, $destination_cat_id ) );
		foreach ( $posts as $post ) {
			wp_set_post_categories( $post->ID, $destination_cat_id );
		}

		// Remove this cat from the parent stack when exiting this recursion.
		array_pop( $source_lineage_stack );
	}

	/**
	 * Returns an array of arrays with keys & values:
	 *      'source_cat_urlslugs'    - the `/`-separated actual URL slugs of the current categories to be remapped
	 *      'destination_cat_parent' - new destination parent category
	 *      'destination_cat_child'  - new child category -- sometimes empty
	 *
	 * @return string[]
	 */
	private function get_cat_remappings() {
		$remapping_raw = [
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80-%E0%A4%95%E0%A5%87%E0%A4%AF%E0%A4%B0>>>ब्यूटी ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B8%E0%A5%8D%E0%A4%95%E0%A4%BF%E0%A4%A8-%E0%A4%95%E0%A5%87%E0%A4%AF%E0%A4%B0>>>ब्यूटी>स्किन ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B9%E0%A5%87%E0%A4%AF%E0%A4%B0-%E0%A4%95%E0%A5%87%E0%A4%AF%E0%A4%B0>>>ब्यूटी>हेयर",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%AE%E0%A5%87%E0%A4%95%E0%A4%85%E0%A4%AA>>>ब्यूटी>मेकअप",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80-%E0%A4%A5%E0%A5%80%E0%A4%AE%E0%A5%8D%E0%A4%B8>>>ब्यूटी  ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B8%E0%A5%87%E0%A4%B2%E0%A4%BF%E0%A4%AC%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%9F%E0%A5%80-%E0%A4%AE%E0%A4%82%E0%A4%A4%E0%A5%8D%E0%A4%B0%E0%A4%BE>>>एंटरटेनमेंट>सेलिब्रिटी",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80-%E0%A4%B5%E0%A4%B0%E0%A5%8D%E0%A4%B2%E0%A5%8D%E0%A4%A1>>>ब्यूटी ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%AE%E0%A5%87%E0%A4%95%E0%A4%93%E0%A4%B5%E0%A4%B0>>>ब्यूटी>मेकअप",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B8%E0%A5%8D%E0%A4%B5%E0%A4%AF%E0%A4%82-%E0%A4%95%E0%A4%B0%E0%A5%87%E0%A4%82>>>ब्यूटी ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B5%E0%A5%80%E0%A4%A1%E0%A4%BF%E0%A4%AF%E0%A5%8B>>>ब्यूटी ",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%9A%E0%A4%BE%E0%A4%87%E0%A4%A8%E0%A5%80%E0%A4%B8>>>खाना खज़ाना>चाइनीस",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%A8%E0%A4%BE%E0%A4%B0%E0%A5%8D%E0%A4%A5-%E0%A4%87%E0%A4%82%E0%A4%A1%E0%A4%BF%E0%A4%AF%E0%A4%A8>>>खाना खज़ाना>नार्थ इंडियन",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%B8%E0%A4%BE%E0%A4%89%E0%A4%A5-%E0%A4%87%E0%A4%82%E0%A4%A1%E0%A4%BF%E0%A4%AF%E0%A4%A8>>>खाना खज़ाना>साउथ इंडियन",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%95%E0%A4%B6%E0%A5%8D%E0%A4%AE%E0%A5%80%E0%A4%B0%E0%A5%80>>>खाना खज़ाना>कश्मीरी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%97%E0%A5%81%E0%A4%9C%E0%A4%B0%E0%A4%BE%E0%A4%A4%E0%A5%80-%E0%A4%B0%E0%A4%BE%E0%A4%9C%E0%A4%B8%E0%A5%8D%E0%A4%A5%E0%A4%BE%E0%A4%A8%E0%A5%80>>>खाना खज़ाना>गुजराती-राजस्थानी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%A5%E0%A4%BE%E0%A4%88>>>खाना खज़ाना>थाई",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%81%E0%A4%9C%E0%A5%80%E0%A4%A8/%E0%A4%87%E0%A4%9F%E0%A4%BE%E0%A4%B2%E0%A4%BF%E0%A4%AF%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C/%E0%A4%A1%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%82%E0%A4%95%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C/%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%B0%E0%A5%8D%E0%A4%9F%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C/%E0%A4%AE%E0%A5%87%E0%A4%A8-%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C/%E0%A4%B8%E0%A5%8D%E0%A4%A8%E0%A5%88%E0%A4%95%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8B%E0%A4%B0%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%9C/%E0%A4%A1%E0%A5%87%E0%A4%9C%E0%A4%BC%E0%A4%B0%E0%A5%8D%E0%A4%9F>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%86%E0%A4%9A%E0%A4%BE%E0%A4%B0>>>खाना खज़ाना>आचार",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%9C%E0%A5%80%E0%A4%B0%E0%A5%8B-%E0%A4%86%E0%A4%AF%E0%A4%B2-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AE%E0%A5%81%E0%A4%B0%E0%A4%AC%E0%A5%8D%E0%A4%AC%E0%A5%87>>>खाना खज़ाना>मुरब्बे",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%97%E0%A5%8B-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A5%80%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%A8%E0%A4%AE%E0%A4%95%E0%A5%80%E0%A4%A8-%E0%A4%9A%E0%A4%BE%E0%A4%9F>>>खाना खज़ाना>नमकीन चाट",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9F%E0%A5%80-%E0%A4%AA%E0%A4%BE%E0%A4%B0%E0%A5%8D%E0%A4%9F%E0%A5%80-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AE%E0%A4%BE%E0%A4%87%E0%A4%95%E0%A5%8D%E0%A4%B0%E0%A5%8B%E0%A4%B5%E0%A5%87%E0%A4%B5-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>माइक्रोवेव रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%85%E0%A4%A8%E0%A5%8D%E0%A4%AF-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%95%E0%A5%8D%E0%A4%B5%E0%A4%BF%E0%A4%95-%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%B5%E0%A5%8D%E0%A4%B0%E0%A4%A4-%E0%A4%A4%E0%A5%8D%E0%A4%AF%E0%A5%8C%E0%A4%B9%E0%A4%BE%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%B5%E0%A5%8D%E0%A4%B0%E0%A4%A4-%E0%A4%A4%E0%A5%8D%E0%A4%AF%E0%A5%8C%E0%A4%B9%E0%A4%BE%E0%A4%B0/%E0%A4%B5%E0%A5%8D%E0%A4%B0%E0%A4%A4-%E0%A4%95%E0%A5%87-%E0%A4%B5%E0%A5%8D%E0%A4%AF%E0%A4%82%E0%A4%9C%E0%A4%A8>>>खाना खज़ाना>व्रत के व्यंजन",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%B5%E0%A5%8D%E0%A4%B0%E0%A4%A4-%E0%A4%A4%E0%A5%8D%E0%A4%AF%E0%A5%8C%E0%A4%B9%E0%A4%BE%E0%A4%B0/%E0%A4%AE%E0%A4%BF%E0%A4%A0%E0%A4%BE%E0%A4%88>>>खाना खज़ाना>मिठाई",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8/%E0%A4%86%E0%A4%B0%E0%A5%8D%E0%A4%9F%E0%A4%BF%E0%A4%95%E0%A4%B2>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8-%E0%A4%B5%E0%A4%B0%E0%A5%8D%E0%A4%B2%E0%A5%8D%E0%A4%A1>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8/%E0%A4%A6%E0%A4%BE%E0%A4%A6%E0%A5%80-%E0%A4%AE%E0%A4%BE%E0%A4%81-%E0%A4%95%E0%A5%87-%E0%A4%A8%E0%A5%81%E0%A4%B8%E0%A5%8D%E0%A4%96%E0%A5%87>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%95%E0%A4%BF%E0%A4%9A%E0%A4%A8/%E0%A4%9F%E0%A4%BF%E0%A4%AA%E0%A5%8D%E0%A4%B8-%E0%A4%8F%E0%A4%82%E0%A4%A1-%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%95%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AA%E0%A4%A8%E0%A5%80%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AA%E0%A5%8D%E0%A4%AF%E0%A4%BE%E0%A4%9C>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%97%E0%A5%8B%E0%A4%AD%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%86%E0%A4%B2%E0%A5%82>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%9A%E0%A4%BF%E0%A4%95%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AE%E0%A4%9F%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%95%E0%A5%88%E0%A4%AA%E0%A5%8D%E0%A4%B8%E0%A4%BF%E0%A4%95%E0%A4%AE>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AE%E0%A5%88%E0%A4%95%E0%A4%B0%E0%A5%8B%E0%A4%A8%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%A8%E0%A5%82%E0%A4%A1%E0%A4%B2%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AA%E0%A4%BE%E0%A4%B8%E0%A5%8D%E0%A4%A4%E0%A4%BE>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%B8%E0%A5%8D%E0%A4%AA%E0%A5%88%E0%A4%97%E0%A4%BF%E0%A4%9F%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%86%E0%A4%87%E0%A4%B8%E0%A4%95%E0%A5%8D%E0%A4%B0%E0%A5%80%E0%A4%AE>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AA%E0%A4%A4%E0%A5%8D%E0%A4%A4%E0%A4%BE%E0%A4%97%E0%A5%8B%E0%A4%AD%E0%A5%80>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%97%E0%A4%BE%E0%A4%9C%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%A8%E0%A5%80%E0%A4%82%E0%A4%AC%E0%A5%82>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%9F%E0%A4%AE%E0%A4%BE%E0%A4%9F%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%AE%E0%A4%9F%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%B8%E0%A5%8D%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%93%E0%A4%A8%E0%A4%BF%E0%A4%AF%E0%A4%A8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%9A%E0%A4%BE%E0%A4%B5%E0%A4%B2>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%A6%E0%A4%BE%E0%A4%B2%E0%A5%87>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%95%E0%A5%81%E0%A4%95%E0%A4%B0%E0%A5%80/%E0%A4%B0%E0%A5%87%E0%A4%B8%E0%A4%BF%E0%A4%AA%E0%A5%80/%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%87%E0%A4%82%E0%A4%97%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A5%8D%E0%A4%B8/%E0%A4%B8%E0%A5%8D%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%89%E0%A4%89%E0%A4%9F%E0%A5%8D%E0%A4%B8>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%AC%E0%A5%89%E0%A4%B2%E0%A5%80%E0%A4%B5%E0%A5%81%E0%A4%A1>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%9F%E0%A5%80%E0%A4%B5%E0%A5%80-%E0%A4%95%E0%A4%BE%E0%A4%B0%E0%A5%8D%E0%A4%A8%E0%A4%B0>>>एंटरटेनमेंट>टीवी कार्नर",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%9F%E0%A5%80%E0%A4%B5%E0%A5%80-%E0%A4%95%E0%A4%BE%E0%A4%B0%E0%A5%8D%E0%A4%A8%E0%A4%B0>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%97%E0%A5%87%E0%A4%AE%E0%A5%8D%E0%A4%B8>>>एंटरटेनमेंट ",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%86%E0%A4%B0%E0%A5%8D%E0%A4%9F-%E0%A4%97%E0%A5%88%E0%A4%B2%E0%A4%B0%E0%A5%80>>>एंटरटेनमेंट ",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%B8%E0%A5%87%E0%A4%B2%E0%A4%BF%E0%A4%AC%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%9F%E0%A5%80>>>एंटरटेनमेंट>सेलिब्रिटी",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%97%E0%A5%89%E0%A4%B8%E0%A4%BF%E0%A4%AA>>>एंटरटेनमेंट ",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%A1%E0%A5%87%E0%A4%B2%E0%A5%80-%E0%A4%A1%E0%A5%8B%E0%A5%9B>>>एंटरटेनमेंट ",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A4%AC%E0%A5%89%E0%A4%95%E0%A5%8D%E0%A4%B8-%E0%A4%91%E0%A4%AB%E0%A4%BF%E0%A4%B8>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A5%9E%E0%A4%BF%E0%A4%B2%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%9B>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%87%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%B5%E0%A5%8D%E0%A4%AF%E0%A5%82>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F/%E0%A5%9E%E0%A4%BF%E0%A4%B2%E0%A5%8D%E0%A4%AE-%E0%A4%B0%E0%A4%BF%E0%A4%B5%E0%A5%8D%E0%A4%AF%E0%A5%81>>>एंटरटेनमेंट>बॉलिवुड",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80/%E0%A4%AA%E0%A5%8B%E0%A4%B8%E0%A5%8D%E0%A4%9F-%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80>>>हेल्थ>प्रेगनेंसी",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80/%E0%A4%AE%E0%A4%BE%E0%A4%81-%E0%A4%AC%E0%A4%A8%E0%A4%A8%E0%A5%87-%E0%A4%B8%E0%A5%87-%E0%A4%AA%E0%A4%B9%E0%A4%B2%E0%A5%87>>>हेल्थ>प्रेगनेंसी",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80/%E0%A4%95%E0%A5%8D%E0%A4%AF%E0%A4%BE-%E0%A4%95%E0%A4%B0%E0%A5%87-%E0%A4%9C%E0%A4%AC-%E0%A4%AE%E0%A4%BE%E0%A4%81-%E0%A4%AC%E0%A4%A8%E0%A5%87>>>हेल्थ>प्रेगनेंसी",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%97%E0%A4%A8%E0%A5%87%E0%A4%82%E0%A4%B8%E0%A5%80-%E0%A5%9E%E0%A5%82%E0%A4%A1>>>हेल्थ>प्रेगनेंसी",
			"https://www.grehlakshmi.com/category/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%B6%E0%A5%89%E0%A4%AA%E0%A4%BF%E0%A4%82%E0%A4%97>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%AE%E0%A5%87%E0%A4%95%E0%A4%85%E0%A4%AA>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%A5%E0%A5%80%E0%A4%AE%E0%A5%8D%E0%A4%B8>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%AC%E0%A5%8D%E0%A4%B0%E0%A4%BE%E0%A4%87%E0%A4%A1%E0%A4%B2-%E0%A4%AE%E0%A4%82%E0%A4%A4%E0%A5%8D%E0%A4%B0%E0%A4%BE>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%A1%E0%A5%87%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BF%E0%A4%A8%E0%A5%87%E0%A4%B6%E0%A4%A8-%E0%A4%B5%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%82%E0%A4%97>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%A7%E0%A4%B0%E0%A5%8D%E0%A4%AE/%E0%A4%85%E0%A4%A7%E0%A5%8D%E0%A4%AF%E0%A4%BE%E0%A4%A4%E0%A5%8D%E0%A4%AE>>>लाइफस्टाइल>आध्यात्म",
			"https://www.grehlakshmi.com/category/%E0%A4%A7%E0%A4%B0%E0%A5%8D%E0%A4%AE/%E0%A4%95%E0%A4%A5%E0%A4%BE-%E0%A4%AA%E0%A5%82%E0%A4%9C%E0%A4%BE>>>लाइफस्टाइल>धर्म",
			"https://www.grehlakshmi.com/category/%E0%A4%A7%E0%A4%B0%E0%A5%8D%E0%A4%AE/%E0%A4%95%E0%A4%B0%E0%A5%8D%E0%A4%AE-%E0%A4%95%E0%A4%BE%E0%A4%82%E0%A4%A1>>>लाइफस्टाइल>धर्म",
			"https://www.grehlakshmi.com/category/%E0%A4%A7%E0%A4%B0%E0%A5%8D%E0%A4%AE/%E0%A4%B8%E0%A4%82%E0%A4%B8%E0%A5%8D%E0%A4%95%E0%A4%BE%E0%A4%B0>>>लाइफस्टाइल>धर्म",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%95%E0%A4%A5%E0%A4%BE-%E0%A4%95%E0%A4%B9%E0%A4%BE%E0%A4%A8%E0%A5%80>>>कथा-कहानी  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%95%E0%A4%B5%E0%A4%BF%E0%A4%A4%E0%A4%BE-%E0%A4%B6%E0%A4%BE%E0%A4%AF%E0%A4%B0%E0%A5%80>>>कथा-कहानी>कविता-शायरी",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%85%E0%A4%A8%E0%A5%81%E0%A4%AD%E0%A4%B5>>>लव सेक्स>रिलेशनशिप",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%B8%E0%A4%95%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%B8-%E0%A4%AE%E0%A4%82%E0%A4%A4%E0%A5%8D%E0%A4%B0%E0%A4%BE>>>कथा-कहानी ",
			"https://www.grehlakshmi.com/category/%E0%A4%B8%E0%A4%BE%E0%A4%B9%E0%A4%BF%E0%A4%A4%E0%A5%8D%E0%A4%AF/%E0%A4%87%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%B5%E0%A5%8D%E0%A4%AF%E0%A5%82>>>एंटरटेनमेंट>सेलिब्रिटी",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%AE%E0%A5%87%E0%A4%B0%E0%A5%80-%E0%A4%95%E0%A4%B2%E0%A4%AE-%E0%A4%B8%E0%A5%87>>>लाइफस्टाइल>धर्म",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%AE%E0%A5%87%E0%A4%B0%E0%A4%BE-%E0%A4%9C%E0%A4%BE%E0%A4%AF%E0%A4%95%E0%A4%BE>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%AE%E0%A5%87%E0%A4%B0%E0%A4%BE-%E0%A4%98%E0%A4%B0>>>लाइफस्टाइल>होम",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%AE%E0%A4%BE%E0%A4%AF-%E0%A4%AE%E0%A5%87%E0%A4%95%E0%A4%93%E0%A4%B5%E0%A4%B0>>>ब्यूटी>मेकअप",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%95%E0%A4%A8%E0%A5%8D%E0%A4%AB%E0%A5%87%E0%A4%B6%E0%A4%A8>>>कथा-कहानी ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%AC%E0%A5%8D%E0%A4%B2%E0%A5%89%E0%A4%97/%E0%A4%8F%E0%A4%95%E0%A5%8D%E0%A4%B8%E0%A4%AA%E0%A4%B0%E0%A5%8D%E0%A4%9F-%E0%A4%8F%E0%A4%A1%E0%A4%B5%E0%A4%BE%E0%A4%87%E0%A4%B8>>>एंटरटेनमेंट ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%97%E0%A4%AA%E0%A4%B6%E0%A4%AA>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%B0%E0%A4%BF%E0%A4%B5%E0%A5%8D%E0%A4%AF%E0%A5%82>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%B8%E0%A5%88%E0%A4%AE%E0%A5%8D%E0%A4%AA%E0%A4%B2%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%8F%E0%A4%82%E0%A4%A1-%E0%A4%B0%E0%A4%9C%E0%A4%BF%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B6%E0%A4%A8>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%87%E0%A4%B5%E0%A5%87%E0%A4%82%E0%A4%9F-%E0%A4%95%E0%A4%BE%E0%A4%82%E0%A4%9F%E0%A5%87%E0%A4%B8%E0%A5%8D%E0%A4%9F>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%9A%E0%A4%B9%E0%A4%B2-%E0%A4%AA%E0%A4%B9%E0%A4%B2>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%97%E0%A5%83%E0%A4%B9%E0%A4%B2%E0%A4%95%E0%A5%8D%E0%A4%B7%E0%A5%8D%E0%A4%AE%E0%A5%80-%E0%A4%95%E0%A5%8D%E0%A4%B2%E0%A4%AC/%E0%A4%AA%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B8-%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%80%E0%A4%9C>>>लाइफस्टाइल ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%AB%E0%A5%88%E0%A4%B6%E0%A4%A8>>>फैशन  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%B8%E0%A5%87%E0%A4%B2%E0%A4%BF%E0%A4%AC%E0%A5%8D%E0%A4%B0%E0%A4%BF%E0%A4%9F%E0%A5%80-%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2>>>एंटरटेनमेंट>सेलिब्रिटी",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%AB%E0%A5%88%E0%A4%B6%E0%A4%A8-%E0%A4%97%E0%A5%81%E0%A4%B0%E0%A5%81>>>फैशन  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%A1%E0%A5%8D%E0%A4%B8>>>फैशन>ट्रेंड्स",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%AB%E0%A5%88%E0%A4%B6%E0%A4%A8-%E0%A4%AC%E0%A4%BE%E0%A4%AF-%E0%A4%93%E0%A4%95%E0%A5%87%E0%A4%9C%E0%A4%A8>>>फैशन  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%AB%E0%A5%88%E0%A4%B6%E0%A4%A8-%E0%A4%AE%E0%A5%87%E0%A4%95%E0%A4%93%E0%A4%B5%E0%A4%B0>>>फैशन  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%B9%E0%A5%8B%E0%A4%AE-%E0%A4%A1%E0%A5%87%E0%A4%95%E0%A5%8B%E0%A4%B0>>>लाइफस्टाइल>होम",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%B9%E0%A5%8B%E0%A4%AE-%E0%A4%86%E0%A4%87%E0%A4%A1%E0%A4%BF%E0%A4%AF%E0%A4%BE%E0%A4%9C>>>लाइफस्टाइल>होम",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%B9%E0%A4%BE%E0%A4%89%E0%A4%B8-%E0%A4%95%E0%A5%80%E0%A4%AA%E0%A4%BF%E0%A4%82%E0%A4%97-%E0%A4%9F%E0%A4%BF%E0%A4%AA%E0%A5%8D%E0%A4%B8>>>लाइफस्टाइल>होम",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A4%BE%E0%A4%87%E0%A4%B2/%E0%A4%AB%E0%A5%88%E0%A4%B6%E0%A4%A8-%E0%A4%B5%E0%A4%B0%E0%A5%8D%E0%A4%B2%E0%A5%8D%E0%A4%A1>>>फैशन ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B8%E0%A5%8D%E0%A4%B5%E0%A4%AF%E0%A4%82-%E0%A4%95%E0%A4%B0%E0%A5%87%E0%A4%82>>>फैशन ",
			"https://www.grehlakshmi.com/category/%E0%A4%AC%E0%A5%8D%E0%A4%AF%E0%A5%82%E0%A4%9F%E0%A5%80/%E0%A4%B5%E0%A5%80%E0%A4%A1%E0%A4%BF%E0%A4%AF%E0%A5%8B>>>फैशन ",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%AB%E0%A4%BF%E0%A4%9F%E0%A4%A8%E0%A5%87%E0%A4%B8>>>हेल्थ>फिटनेस",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%AF%E0%A5%8B%E0%A4%97%E0%A4%BE>>>हेल्थ>योगा",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%B5%E0%A5%81%E0%A4%AE%E0%A4%A8-%E0%A4%95%E0%A5%87%E0%A4%AF%E0%A4%B0>>>हेल्थ  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%AB%E0%A5%88%E0%A4%AE%E0%A4%BF%E0%A4%B2%E0%A5%80-%E0%A4%95%E0%A5%87%E0%A4%AF%E0%A4%B0>>>हेल्थ  ",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%98%E0%A4%B0%E0%A5%87%E0%A4%B2%E0%A5%82-%E0%A4%89%E0%A4%AA%E0%A4%9A%E0%A4%BE%E0%A4%B0>>>हेल्थ>दादी माँ के नुस्खे",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%AE%E0%A5%87%E0%A4%A1%E0%A4%BF%E0%A4%9F%E0%A5%87%E0%A4%B6%E0%A4%A8>>>लाइफस्टाइल>आध्यात्म",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%8F%E0%A4%95%E0%A5%8D%E0%A4%B8%E0%A4%AA%E0%A4%B0%E0%A5%8D%E0%A4%9F-%E0%A4%AE%E0%A4%82%E0%A4%A4%E0%A5%8D%E0%A4%B0%E0%A4%BE>>>हेल्थ>एक्सपर्ट मंत्रा",
			"https://www.grehlakshmi.com/category/%E0%A4%B9%E0%A5%87%E0%A4%B2%E0%A5%8D%E0%A4%A5/%E0%A4%AC%E0%A5%80-%E0%A4%8F%E0%A4%AE-%E0%A4%86%E0%A4%88>>>हेल्थ>फिटनेस",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%A6%E0%A4%BE%E0%A4%AE%E0%A5%8D%E0%A4%AA%E0%A4%A4%E0%A5%8D%E0%A4%AF>>>लव सेक्स>रिलेशनशिप",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%AC%E0%A5%89%E0%A4%B8-%E0%A4%91%E0%A4%AB%E0%A4%BF%E0%A4%B8>>>लव सेक्स>रिलेशनशिप",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%A6%E0%A5%8B%E0%A4%B8%E0%A5%8D%E0%A4%A4>>>लव सेक्स>रिलेशनशिप",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%91%E0%A4%A8%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%A8-%E0%A4%B0%E0%A4%BF%E0%A4%B6%E0%A5%8D%E0%A4%A4%E0%A5%87>>>लव सेक्स>रिलेशनशिप",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8>>>लव सेक्स ",
			"https://www.grehlakshmi.com/category/%E0%A4%B2%E0%A4%B5-%E0%A4%B8%E0%A5%87%E0%A4%95%E0%A5%8D%E0%A4%B8/%E0%A4%B0%E0%A4%BF%E0%A4%B2%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B6%E0%A4%BF%E0%A4%AA/%E0%A4%97%E0%A5%81%E0%A4%B0%E0%A5%81-%E0%A4%AE%E0%A4%82%E0%A4%A4%E0%A5%8D%E0%A4%B0%E0%A4%BE>>>लव सेक्स>Q&A",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%A8%E0%A5%8D%E0%A4%AF%E0%A5%82-%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F>>>लाइफस्टाइल>पेरेंटिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%AC%E0%A5%87%E0%A4%B8%E0%A5%8D%E0%A4%9F-%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F>>>लाइफस्टाइल>पेरेंटिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%AA%E0%A5%8C%E0%A4%B7%E0%A5%8D%E0%A4%9F%E0%A4%BF%E0%A4%95-%E0%A4%86%E0%A4%B9%E0%A4%BE%E0%A4%B0>>>खाना खज़ाना>रेसिपी",
			"https://www.grehlakshmi.com/category/%E0%A4%AA%E0%A5%87%E0%A4%B0%E0%A5%87%E0%A4%82%E0%A4%9F%E0%A4%BF%E0%A4%82%E0%A4%97/%E0%A4%B8%E0%A4%82%E0%A4%B8%E0%A5%8D%E0%A4%95%E0%A4%BE%E0%A4%B0-%E0%A4%B5%E0%A4%BF%E0%A4%9A%E0%A4%BE%E0%A4%B0>>>लाइफस्टाइल>पेरेंटिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%AC%E0%A5%80%E0%A4%9A>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%B9%E0%A4%BF%E0%A4%B2%E0%A5%8D%E0%A4%B8>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%B9%E0%A4%BF%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A5%8B%E0%A4%B0%E0%A4%BF%E0%A4%95%E0%A4%B2>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%B5%E0%A4%BE%E0%A4%87%E0%A4%B2%E0%A5%8D%E0%A4%A1%E0%A4%B2%E0%A4%BE%E0%A4%87%E0%A4%AB>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%B9%E0%A4%A8%E0%A5%80%E0%A4%AE%E0%A5%82%E0%A4%A8>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%B5%E0%A5%80%E0%A4%95%E0%A5%87%E0%A4%82%E0%A4%A1-%E0%A4%8F%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%9F%E0%A5%87%E0%A4%A8%E0%A4%AE%E0%A5%87%E0%A4%82%E0%A4%9F>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%9F%E0%A5%8D%E0%A4%B0%E0%A5%87%E0%A4%B5%E0%A4%B2/%E0%A4%87%E0%A4%82%E0%A4%9F%E0%A4%B0%E0%A4%A8%E0%A5%87%E0%A4%B6%E0%A4%A8%E0%A4%B2>>>लाइफस्टाइल>ट्रेवल",
			"https://www.grehlakshmi.com/category/%E0%A4%89%E0%A4%A4%E0%A5%8D%E0%A4%B8%E0%A4%B5/%E0%A4%B6%E0%A4%BE%E0%A4%A6%E0%A5%80>>>लाइफस्टाइल>वेडिंग",
			"https://www.grehlakshmi.com/category/%E0%A4%89%E0%A4%A4%E0%A5%8D%E0%A4%B8%E0%A4%B5/%E0%A4%A4%E0%A5%8D%E0%A4%AF%E0%A5%8B%E0%A4%B9%E0%A4%BE%E0%A4%B0>>>लाइफस्टाइल>उत्सव",
			"https://www.grehlakshmi.com/category/%E0%A4%B0%E0%A4%BE%E0%A4%B6%E0%A4%BF%E0%A4%AB%E0%A4%B2>>>लाइफस्टाइल>ऐस्ट्रो",
			"https://www.grehlakshmi.com/category/%E0%A4%B0%E0%A4%BE%E0%A4%B6%E0%A4%BF%E0%A4%AB%E0%A4%B2/%E0%A4%AA%E0%A4%82%E0%A4%9A%E0%A4%BE%E0%A4%82%E0%A4%97>>>लाइफस्टाइल>ऐस्ट्रो",
			"https://www.grehlakshmi.com/category/%E0%A4%B0%E0%A4%BE%E0%A4%B6%E0%A4%BF%E0%A4%AB%E0%A4%B2/%E0%A4%B5%E0%A4%BE%E0%A4%B8%E0%A5%8D%E0%A4%A4%E0%A5%81>>>लाइफस्टाइल>ऐस्ट्रो",
			"https://www.grehlakshmi.com/category/%E0%A4%B0%E0%A4%BE%E0%A4%B6%E0%A4%BF%E0%A4%AB%E0%A4%B2/%E0%A4%AB%E0%A5%87%E0%A4%82%E0%A4%97%E0%A4%B6%E0%A5%81%E0%A4%88>>>लाइफस्टाइल>ऐस्ट्रो",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%AC%E0%A4%BF%E0%A5%9B%E0%A4%A8%E0%A5%87%E0%A4%B8-%E0%A4%B5%E0%A5%81%E0%A4%AE%E0%A4%A8>>>लाइफस्टाइल>मनी",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%B8%E0%A4%95%E0%A5%8D%E0%A4%B8%E0%A5%87%E0%A4%B8-%E0%A4%B8%E0%A5%8D%E0%A4%9F%E0%A5%8B%E0%A4%B0%E0%A5%80>>>लाइफस्टाइल>सक्सेस स्टोरी",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%AC%E0%A4%9A%E0%A4%A4>>>लाइफस्टाइल>मनी",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%A8%E0%A4%BF%E0%A4%B5%E0%A5%87%E0%A4%B6>>>लाइफस्टाइल>मनी",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%AC%E0%A4%9C%E0%A4%9F-%E0%A4%B6%E0%A5%89%E0%A4%AA%E0%A4%BF%E0%A4%82%E0%A4%97>>>लाइफस्टाइल>मनी",
			"https://www.grehlakshmi.com/category/%E0%A4%AE%E0%A4%A8%E0%A5%80/%E0%A4%AE%E0%A4%A8%E0%A5%80-%E0%A4%AA%E0%A5%8D%E0%A4%B2%E0%A4%BE%E0%A4%A8%E0%A4%BF%E0%A4%82%E0%A4%97>>>लाइफस्टाइल>मनी",
			"https://www.grehlakshmi.com/category/%E0%A4%9C%E0%A4%B0%E0%A4%BE-%E0%A4%B9%E0%A4%9F-%E0%A4%95%E0%A5%87/%E0%A4%B9%E0%A4%BE%E0%A4%AF-%E0%A4%AE%E0%A5%88-%E0%A4%B6%E0%A4%B0%E0%A5%8D%E0%A4%AE-%E0%A4%B8%E0%A5%87-%E0%A4%B2%E0%A4%BE%E0%A4%B2-%E0%A4%B9%E0%A5%81%E0%A4%88>>>कथा-कहानी>हाय मै शर्म से लाल हुई",
			"https://www.grehlakshmi.com/category/%E0%A4%9C%E0%A4%B0%E0%A4%BE-%E0%A4%B9%E0%A4%9F-%E0%A4%95%E0%A5%87/%E0%A4%9C%E0%A4%AC-%E0%A4%AE%E0%A5%88-%E0%A4%9B%E0%A5%8B%E0%A4%9F%E0%A4%BE-%E0%A4%AC%E0%A4%9A%E0%A5%8D%E0%A4%9A%E0%A4%BE-%E0%A4%A5%E0%A4%BE>>>कथा-कहानी>जब मै छोटा बच्चा था",
			"https://www.grehlakshmi.com/category/%E0%A4%9C%E0%A4%B0%E0%A4%BE-%E0%A4%B9%E0%A4%9F-%E0%A4%95%E0%A5%87/%E0%A4%85%E0%A4%9C%E0%A4%AC-%E0%A4%97%E0%A4%9C%E0%A4%AC>>>एंटरटेनमेंट>अजब-गजब",
			"https://www.grehlakshmi.com/category/%E0%A4%9C%E0%A4%B0%E0%A4%BE-%E0%A4%B9%E0%A4%9F-%E0%A4%95%E0%A5%87/%E0%A4%AB%E0%A4%9F%E0%A4%BE%E0%A4%AB%E0%A4%9F-%E0%A4%9F%E0%A4%BF%E0%A4%AA%E0%A5%8D%E0%A4%B8>>>लाइफस्टाइल>होम",
		];

		$remapping_array = [];
		foreach ( $remapping_raw as $remapping ) {
			$remapping_exploded = explode( '>>>', $remapping );

			$from_cats_url_slugs     = trim( $remapping_exploded[0] );
			$pos_from_cats_url_slugs = strpos( $from_cats_url_slugs, 'category/' );
			$from_cats_url_slugs     = substr( $from_cats_url_slugs, $pos_from_cats_url_slugs + strlen( 'category/' ) );
			$from_cats_url_slugs     = urldecode( $from_cats_url_slugs );

			$to_categories = explode( '>', $remapping_exploded[1] );
			$destination_cat_parent = trim( $to_categories[0] );
			$destination_cat_child  = $to_categories[1] ?? null;

			$remapping_array[] = [
				'source_cat_urlslugs'    => $from_cats_url_slugs,
				'destination_cat_parent' => $destination_cat_parent,
				'destination_cat_child'  => $destination_cat_child,
			];
		}

		return $remapping_array;
	}

	/**
	 * Pulls the single XML article's data for WXR export.
	 *
	 * @param \SimpleXMLElement $xml SimpleXMLElement.
	 *
	 * @return array Single Post's data for the wxr-exporter.
	 */
	// private function import_xml_article( $xml_k, $xml_v ) {
	private function parse_xml_article( $xml ) {

		// Resulting Post data.
		$data = [];

		// Bits of data to be added to $data in the end.
		$authors_meta = [];
		$kreatio_article_publish_date = null;
		$kreatio_article_created_at = null;

		// Loops single Kreatio article.
		foreach ( $xml as $xml_k => $xml_v ) {

			// The \SimpleXMLElement class has a __toString() method.
			$xml_v_tostring = (string) $xml_v;

			switch ( $xml_k ) {

				// General data.
				case 'article_id':
					$data[ 'meta' ][ '_kreatio_article_id' ] = $xml_v_tostring;
					break;
				case 'article_external_id':
					$data[ 'meta' ][ '_kreatio_article_external_id' ] = $xml_v_tostring;
					break;
				case 'article_created_at':
					$kreatio_article_created_at = $xml_v_tostring;
					break;
				case 'article_publish_date':
					$kreatio_article_publish_date = $xml_v_tostring;
					break;
				case 'article_title':
					$data[ 'title' ] = $xml_v_tostring;
					break;
				case 'article_summary':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'excerpt' ] = $xml_v_tostring;
					}
					break;
				case 'article_content':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'content' ] = $xml_v_tostring;
					}
					break;
				case 'article_custom_content':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_custom_content' ] = $xml_v_tostring;
					}
					break;
				case 'article_url_part':
					$data[ 'url' ] = $xml_v_tostring;
					break;
				case 'article_is_draft':
					if ( 'true' == $xml_v_tostring ) {
						$data[ 'meta' ][ '_kreatio_article_is_draft' ] = $xml_v_tostring;
					}
					break;
				case 'article_premium':
					if ( 'default' != $xml_v_tostring ) {
						$data[ 'meta' ][ '_kreatio_article_premium' ] = $xml_v_tostring;
					}
					break;
				case 'article_article_type':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_article_type' ] = $xml_v_tostring;
					}
					break;
				case 'article_status':
					if ( ! empty( $xml_v_tostring ) && 'published' == $xml_v_tostring ) {
						$data[ 'status' ] = 'publish';
					}
					break;
				case 'article_title_image_url':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'featured_image' ] = $xml_v_tostring;
					}
					break;
				case 'article_title_image_name':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_title_image_name' ] = $xml_v_tostring;
					}
					break;
				case 'article_thumbnail_image_url':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_thumbnail_image_url' ] = $xml_v_tostring;
					}
					break;

				// Authors.
				case 'article_authors':
					// We're going to save all the authors' info as meta, then create the authors after the import.
					$authors_meta = [];
					foreach ( $xml_v as $article_author_k => $article_author_v ) {
						// Get each author's data.
						$current_author_meta = [];
						foreach ( $article_author_v as $k => $v ) {
							// Using \SimpleXMLElement::__toString().
							$v_tostring = (string) $v;
							switch ( $k ) {

								// All individual authors should have all an `article_author_id` value.
								case 'article_author_id':
									$current_author_meta[ 'article_author_id' ] = $v_tostring;
									break;

								// All individual authors should have all an `article_author_fullname` value.
								// These come in latin alphabet caracters.
								case 'article_author_fullname':
									// Remove some double spacings.
									if ( ! empty( $v_tostring ) ) {
										$v_replaced = str_replace( '  ', ' ', $v_tostring );
										$current_author_meta[ 'article_author_fullname' ] = $v_replaced;
									}
									break;

								// All individual authors should have all an `article_author_email` value.
								case 'article_author_email':
									if ( ! empty( $v_tostring ) ) {
										$current_author_meta[ 'article_author_email' ] = $v_tostring;
									}
									break;
							}
						}

						// Add author data to all the $authors_meta.
						$current_author_key = $current_author_meta[ 'article_author_id' ] ?? count( $authors_meta );
						foreach ( $current_author_meta as $s_k => $s_v ) {
							$authors_meta[ $current_author_key ][ $s_k ] = $s_v;
						}
					}
					break;

				// This is the "joint author alias" which actually gets displayed on Kreatio posts.
				// it's in Hindi alphabet, e.g. "गृहलक्ष्मी टीम" (meaning "Grehlakshmi Team").
				case 'article_author_alias':
					$data[ 'meta' ][ '_kreatio_article_author_alias' ] = $xml_v_tostring;
					break;

				// Tags.
				case 'article_tags':
					foreach ( $xml_v as $article_tag_k => $article_tag_v ) {

						$data_tag_index = isset( $data[ 'tags' ] ) ? count( $data[ 'tags' ] ) : 0;

						foreach ( $article_tag_v as $k => $v ) {

							// Using \SimpleXMLElement::__toString().
							$article_tag_v_tostring = (string) $v;

							switch ( $k ) {
								case 'article_tag_name':
									$data[ 'tags' ][ $data_tag_index ][ 'name' ] = $article_tag_v_tostring;
									break;
								case 'article_tag_alias_name':
									if ( ! empty( $article_tag_v_tostring ) ) {
										$data[ 'tags' ][ $data_tag_index ][ 'slug' ] = $article_tag_v_tostring;
									}
									break;
							}
						}
					}
					break;

				// Categories.
				case 'article_taxonomies':

					$categories_meta = [];
					foreach ( $xml_v as $article_taxonomy_k => $article_taxonomy_v ) {

						foreach ( $article_taxonomy_v as $k => $v ) {

							$current_kreatio_article_taxonomy_label = (string) $article_taxonomy_v->{'article_taxonomy_label'};
							if ( 'category' == $current_kreatio_article_taxonomy_label ) {

								// Here just save all categories info as meta. Cats need to be built up in WP first, with proper
								// hierarchy.
								$current_category_meta = [];
								foreach ( $article_taxonomy_v as $article_taxonomy_category_k => $article_taxonomy_category_v ) {
								// foreach ( $v as $article_taxonomy_category_k => $article_taxonomy_category_v ) {

									// \SimpleXMLElement::__toString().
									$v_tostring = (string) $article_taxonomy_category_v;

									switch ( $article_taxonomy_category_k ) {
										case 'article_taxonomy_id':
											$current_category_meta[ 'article_taxonomy_id' ] = $v_tostring;
											break;
										case 'article_taxonomy_parent_id':
											if ( ! empty( $v_tostring ) ) {
												$current_category_meta[ 'article_taxonomy_parent_id' ] = $v_tostring;
											}
											break;
										case 'article_taxonomy_name':
											$current_category_meta[ 'article_taxonomy_name' ] = $v_tostring;
											break;
										case 'article_taxonomy_full_alias_name':
											if ( ! empty( $v_tostring ) ) {
												$current_category_meta[ 'article_taxonomy_full_alias_name' ] = $v_tostring;
											}
											break;

										case 'article_taxonomy_properties':

											// Iterate over Kreatio-Taxonomy-Category-properties nodes.
											foreach ( $article_taxonomy_category_v as $article_taxonomy_category_property_k => $article_taxonomy_category_property_v ) {

												// \SimpleXMLElement::__toString().
												$v_property_tostring = (string) $article_taxonomy_category_property_v;

												switch ( $article_taxonomy_category_property_k ) {
													case 'article_taxonomy_properties_full_name':
														if ( ! empty( $v_property_tostring ) ) {
															$current_category_meta['article_taxonomy_properties_full_name'] = $v_property_tostring;
														}
														break;
													case 'article_taxonomy_properties_alias_name':
														if ( ! empty( $v_property_tostring ) ) {
															$current_category_meta['article_taxonomy_properties_alias_name'] = $v_property_tostring;
														}
														break;
												}

											}
											break;

									}
								}

								// Add this category infor to the $categories_meta.
								$current_category_key = $current_category_meta[ 'article_taxonomy_id' ] ?? count( $categories_meta );
								if ( ! empty( $current_category_meta ) ) {
									$categories_meta[ $current_category_key ] = $current_category_meta;
								}

							} else if ( 'section' == $current_kreatio_article_taxonomy_label ) {
								// Nothing.
								$b=1;
							} else if ( 'source' == $current_kreatio_article_taxonomy_label ) {
								// Nothing.
								$b=1;
							}
						}
					}

					// Set all categories as JSON encoded meta.
					if ( ! empty( $categories_meta) ) {
						$data[ 'meta' ][ '_kreatio_categories' ] = json_encode( $categories_meta );
					}

					break;

				// Extra meta.
				case 'article_meta_keywords':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_meta_keywords' ] = $xml_v_tostring;
					}
					break;
				case 'article_meta_description':
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_meta_description' ] = $xml_v_tostring;
					}
					break;
			}
		}

		// Add all the $authors_meta info as meta.
		if ( ! empty( $authors_meta) ) {
			$data[ 'meta' ][ '_kreatio_authors_meta' ] = json_encode( $authors_meta );
		}

		// Use one out of the two available date fields as published date.
		$article_date = ( isset ( $kreatio_article_publish_date ) && ! empty( $kreatio_article_publish_date ) )
			? $kreatio_article_publish_date
			: ( isset( $kreatio_article_created_at ) && ! empty( $kreatio_article_created_at ) ? $kreatio_article_created_at : null );
		if ( null !== $article_date ) {
			// Convert Kreatio date format to WP timestamp.
			$timezone_pos   = strrpos( $article_date, ' ' );
			$timezone_part  = substr( $article_date, $timezone_pos + 1 );
			$timestamp_part = substr( $article_date, 0, $timezone_pos );
			try {
				$datetime = \DateTime::createFromFormat ( 'Y-m-d H:i:s' , $timestamp_part, new \DateTimeZone( $timezone_part ) );
				$data[ 'date' ] = $datetime->format( 'Y-m-d H:i:s' );
			} catch ( \Exception $e ) {
				$msg = sprintf( 'Invalid date %s', $article_date );
				WP_CLI::warning( $msg );
				// TODO log
			}
		}

		return $data;
	}

	/**
	 * Gets an initialized, empty aray for the wxr-exporter.
	 *
	 * @param string $dir If null, getcwd() will be used.
	 *
	 * @return array
	 */
	private function get_empty_data_array( $dir = null ) {

		$dir = $dir ?? getcwd();

		return [
			'site_title'  => "Grehlakshmi - The Hindi Women's Fashion, Beauty ...",
			'site_url'    => 'https://www.grehlakshmi.com',
			'export_file' => $this->get_export_file( $dir ),
			'posts'       => [],
		];
	}

	/**
	 * Returns the next export file name by increasing the numeric suffix to the file name.
	 *
	 * @param string $dir
	 *
	 * @return string
	 */
	private function get_export_file( $dir = __DIR__ ) {
		$number = 0;
		do {
			$full_path = $dir . '/' . sprintf( self::EXPORT_FILE_NAME, ++$number );
		} while( file_exists( $full_path ) );

		return $full_path;
	}

	/**
	 * Count number of lines in a file.
	 *
	 * @param string $file File full path.
	 *
	 * @return int Number of lines in file.
	 */
	private function count_file_lines( $file ) {
		$file        = new \SplFileObject( $file, 'r' );
		$file->seek( PHP_INT_MAX );
		$lines_total = $file->key() + 1;

		return $lines_total;
	}

	/**
	 * Returns the first meta row with given key and value.
	 *
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	private function get_meta( $meta_key, $meta_value ) {
		global $wpdb;

		// Do a direct SQL call for speed (> 700k posts expected).
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->postmeta} WHERE meta_key = %s and meta_value = %s ;",
				$meta_key,
				$meta_value
			),
			ARRAY_A
		);

		return $row;
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
