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
	}

	public function cmd_import_xmls( $args, $assoc_args ) {
		// $xml_file = isset( $assoc_args[ 'xml-file' ] ) ? true : false;

		$time_start = microtime( true );

		// TEMP DEV tests.
		// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/Kreatio_export/XML_data/custom_converter_test_export.xml';
		// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/Kreatio_export/XML_data/delta_export_test.xml';
		// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/1/Kreatio_export/XML_data/export_runtest.xml';
		// Live exports.
		// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/1/Kreatio_export/XML_data/delta_export.xml';
		$xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/1/Kreatio_export/XML_data/export.xml';

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
				WP_CLI::warming( $msg );
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
