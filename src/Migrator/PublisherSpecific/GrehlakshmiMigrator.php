<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Grehlakshmi.
 */
class GrehlakshmiMigrator implements InterfaceMigrator {

	const EXPORT_FILE_NAME = 'grehlakshmi_export_%d.xml';
	const EXPORT_BATCH = 100;

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
				'shortdesc' => 'Imports Grehlakshmi custom XML conten.',
				'synopsis'  => [],
			]
		);
	}

	private function get_empty_data_array( $dir = __DIR__ ) {
		return [
			'site_title'  => "Grehlakshmi - The Hindi Women's Fashion, Beauty ...",
			'site_url'    => 'https://www.grehlakshmi.com',
			'export_file' => $this->get_export_file( $dir ),
			'posts'       => [],
		];
	}

	private function get_export_file( $dir = __DIR__ ) {
		$number = 0;
		do {
			$full_path = $dir . '/' . sprintf( self::EXPORT_FILE_NAME, ++$number );
		} while( file_exists( $full_path ) );

		return $full_path;
	}

	/**
	 * Callable for `newspack-content-migrator grehlakshmi-import-xmls`
	 */
	public function cmd_import_xmls( $args, $assoc_args ) {
		// $xml_file = isset( $assoc_args[ 'xml-file' ] ) ? true : false;

		$time_start = microtime( true );

// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/Kreatio_export/XML_data/delta_export_test.xml';
$xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/Kreatio_export/XML_data/export.xml';
// $xml_file = '/srv/www/0_data_no_backup/0_grehlakshmi/Kreatio_export/XML_data/delta_export.xml';

// 		$lines_total = $this->count_file_lines( $xml_file );
		$lines_total = 3957891;

		$line_number = 0;
		$progress    = \WP_CLI\Utils\make_progress_bar( 'XML processed', $lines_total );

		// Parse one '<wp:article>' at a time.
		if ( $handle = fopen( $xml_file, 'r' ) ) {
			$i = 0;
			$data = $this->get_empty_data_array();
			while ( ( $line = fgets( $handle ) ) !== false ) {

				// Line progress.
				$progress->tick();
				$line_number++;

// // Find a specific line
// audio true -- 5 कारण जिनकी वजह से आप के लिए फार्टिंग बहुत आवश्यक है
// if ( false !== strpos( $line, '<wp:article_audio>' )
// 	&& ( false === strpos( $line, '<wp:article_audio>false</wp:article_audio>' ) )
// ) {
// 	$break=1;
// }

				if ( $line == "<wp:article>\n" ) {
					$i++;
					$wp_article_xml = '';
					$wp_article_xml .= $line;
				} else if ( $line == "</wp:article>\n" ) {
					$wp_article_xml .= $line;

					// Remove the undefined XML namespace and load the \SimpleXMLElement object.
					$article_xml = str_replace( '<wp:', '<', $wp_article_xml );
					$article_xml = str_replace( '</wp:', '</', $article_xml );
					$xml         = simplexml_load_string( $article_xml );

					// Parse this article's data.
					$data[ 'posts' ][] = $this->parse_xml_article( $xml, $xml_file );

// // Export Posts in batches.
// if ( count( $data ) >= self::EXPORT_BATCH ) {
// \Newspack_WXR_Exporter::generate_export( $data );
// $data = $this->get_empty_data_array();
// }
				} else {
					$wp_article_xml .= $line;
				}

			}

// // Export the remaining Posts.
// if ( count( $data ) >= 0 ) {
// 	\Newspack_WXR_Exporter::generate_export( $data );
// }

			fclose( $handle );
			$progress->finish();

		} else {
			\WP_CLI::error( sprintf( 'Error opening the file %s', $xml_file ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		WP_CLI::line( sprintf( '--- Total %d articles', $i ) );
		WP_CLI::line( sprintf( '--- Total %d WXR files created', 0 ) );
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
					$data[ 'meta' ][ '_kreatio_article_url_part' ] = $xml_v_tostring;
					break;
				case 'article_is_draft':
					if ( 'true' == $xml_v_tostring ) {
						$data[ 'status' ] = 'draft';
					}
					break;
				case 'article_audio':
					if ( 'true' == $xml_v_tostring ) {
						$data[ 'meta' ][ '_kreatio_audio' ] = $xml_v_tostring;
					}
					break;
				case 'article_video':
					if ( 'true' == $xml_v_tostring ) {
						$data[ 'meta' ][ '_kreatio_video' ] = $xml_v_tostring;
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
					if ( ! empty( $xml_v_tostring ) ) {
						$data[ 'meta' ][ '_kreatio_article_status' ] = $xml_v_tostring;
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

						$data_tag_index = count( $data[ 'tags' ] );

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
					foreach ( $xml_v as $article_taxonomy_k => $article_taxonomy_v ) {

						$categories_meta = [];
						foreach ( $article_taxonomy_v as $k => $v ) {

							if ( 'category' == (string) $article_taxonomy_v->{'article_taxonomy_label'} ) {

								// Here just save all categories info as meta. Cats need to be built up in WP first, with proper
								// hierarchy.
								$current_category_meta = [];
								foreach ( $v as $k => $v ) {

									// \SimpleXMLElement::__toString().
									$v_tostring = (string) $v;

									switch ( $k ) {
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
										case 'article_taxonomy_properties_full_name':
											if ( ! empty( $v_tostring ) ) {
												$current_category_meta[ 'article_taxonomy_properties_full_name' ] = $v_tostring;
											}
											break;
										case 'article_taxonomy_properties_alias_name':
											if ( ! empty( $v_tostring ) ) {
												$current_category_meta[ 'article_taxonomy_properties_alias_name' ] = $v_tostring;
											}
											break;
									}
								}

							} else if ( 'section' == (string) $article_taxonomy_v->{'article_taxonomy_label'} ) {
								// Nothing.
							} else if ( 'source' == (string) $article_taxonomy_v->{'article_taxonomy_label'} ) {
								// Nothing.
							}

							// Add this category infor to the $categories_meta.
							$current_category_key = $current_category_meta[ 'article_taxonomy_id' ] ?? count( $categories_meta );
							$categories_meta[ $current_category_key ] = $current_category_meta;
						}
					}

					// Set all categories info as JSON encoded meta.
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
			$data[ 'meta' ][ 'authors_meta' ] = json_encode( $authors_meta );
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
}
