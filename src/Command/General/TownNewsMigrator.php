<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \DirectoryIterator;
use \SimpleXMLElement;
use \WP_CLI;

class TownNewsMigrator implements InterfaceCommand {
	const LOG_FILE                       = 'townnews_importer.log';
	const TOWN_NEWS_ORIGINAL_ID_META_KEY = '_newspack_import_id';
	const DEFAULT_CO_AUTHOR_DISPLAY_NAME = 'Staff';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger                    = new Logger();
		$this->attachments               = new Attachments();
		$this->coauthorsplus_logic       = new CoAuthorPlus();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
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
			'newspack-content-migrator town-news-migrate-content',
			array( $this, 'cmd_migrate_content' ),
			[
				'shortdesc' => 'Migrate TownNews content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir-path',
						'description' => 'Directory path of a TownNews export the holder should contains content in the format: export_dir/year/month/*.{xml,jpg}',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-author-id',
						'description' => 'Default author for posts without author.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator town-news-migrate-featured-tag',
			array( $this, 'cmd_migrate_featured_tag' ),
			[
				'shortdesc' => 'Migrate TownNews post featured tag.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir-path',
						'description' => 'Directory path of a TownNews export the holder should contains content in the format: export_dir/year/month/*.{xml,jpg}',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator town-news-fix-tags',
			array( $this, 'cmd_fix_tags' ),
			[
				'shortdesc' => 'Fix tags.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir-path',
						'description' => 'Directory path of a TownNews export the holder should contains content in the format: export_dir/year/month/*.{xml,jpg}',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for newspack-content-migrator town-news-migrate-content.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_content( $args, $assoc_args ) {
		$export_dir_path   = $assoc_args['export-dir-path'];
		$default_author_id = isset( $assoc_args['default-author-id'] ) ? intval( $assoc_args['default-author-id'] ) : null;

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// Already imported posts original IDs.
		$imported_original_ids = $this->get_imported_original_ids();

		$export_dir_iterator = new DirectoryIterator( $export_dir_path );
		foreach ( $export_dir_iterator as $year_dir ) {
			$year = intval( $year_dir->getFilename() );

			if ( $year_dir->isDot() || ! checkdate( 1, 1, $year ) ) {
				continue;
			}

			$this->logger->log( self::LOG_FILE, sprintf( 'Importing content for year %d', $year ) );

			$year_dir_iterator = new DirectoryIterator( $year_dir->getPathname() );
			foreach ( $year_dir_iterator as $month_dir ) {
				$month = intval( $month_dir->getFilename() );

				if ( $month_dir->isDot() || ! checkdate( $month, 1, $year ) ) {
					continue;
				}

				$this->logger->log( self::LOG_FILE, sprintf( 'Importing content for month %d', $month ) );

				$month_dir_iterator = new DirectoryIterator( $month_dir->getPathname() );

				foreach ( $month_dir_iterator as $file ) {
					if ( 'xml' === $file->getExtension() ) {
						$post_id = $this->import_post_from_xml( $file->getPathname(), $month_dir->getPathname(), $imported_original_ids, $default_author_id );

						if ( $post_id ) {
							$this->logger->log( self::LOG_FILE, sprintf( 'Post %d is imported from %s', $post_id, $file->getFilename() ), Logger::SUCCESS );
						}
					}
				}
			}
		}
	}

	/**
	 * Callable for newspack-content-migrator town-news-migrate-featured-tag.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_featured_tag( $args, $assoc_args ) {
		global $wpdb;
		$export_dir_path    = $assoc_args['export-dir-path'];
		$fixed_ids_filepath = 'featured-fixed.log';
		$fixed_ids          = is_file( $fixed_ids_filepath ) ? file( $fixed_ids_filepath, FILE_IGNORE_NEW_LINES ) : [];

		$export_dir_iterator = new DirectoryIterator( $export_dir_path );
		foreach ( $export_dir_iterator as $year_dir ) {
			$year = intval( $year_dir->getFilename() );

			if ( $year_dir->isDot() || ! checkdate( 1, 1, $year ) ) {
				continue;
			}

			$year_dir_iterator = new DirectoryIterator( $year_dir->getPathname() );
			foreach ( $year_dir_iterator as $month_dir ) {
				$month = intval( $month_dir->getFilename() );

				if ( $month_dir->isDot() || ! checkdate( $month, 1, $year ) ) {
					continue;
				}

				$this->logger->log( self::LOG_FILE, sprintf( 'Importing content for month %d', $month ) );

				$month_dir_iterator = new DirectoryIterator( $month_dir->getPathname() );

				foreach ( $month_dir_iterator as $file ) {
					if ( 'xml' === $file->getExtension() ) {
						$xml_doc = new SimpleXMLElement( file_get_contents( $file->getPathname() ) );
						$this->register_element_namespace( $xml_doc );

						$file_type  = (string) $xml_doc->xpath( '//tn:identified-content/tn:classifier[@type="tncms:asset"]' )[0]->attributes()->value;
						$tn_id      = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:doc-id', 'id-string' );
						$post_title = $this->get_element_by_xpath( $xml_doc, '//tn:body/tn:body.head/tn:hedline/tn:hl1' );
						$featured   = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:identified-content/tn:classifier[@type="tncms:flag"]', 'value' );

						if ( in_array( $tn_id, $fixed_ids ) ) {
							continue;
						}

						if ( ! in_array( $file_type, [ 'article', 'collection' ] ) ) {
							// Save fixed IDs.
							file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
							continue;
						}

						if ( 'featured' === $featured ) {
							// Get post by meta.
							$post_id = $wpdb->get_var(
								$wpdb->prepare(
									"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
									self::TOWN_NEWS_ORIGINAL_ID_META_KEY,
									$tn_id
								)
							);

							if ( $post_id ) {
								// Set featured tag.
								WP_CLI::line( sprintf( 'Post with tn_id %s (%d) is fixed.', $tn_id, $post_id ) );
								wp_set_post_tags( $post_id, 'Featured', true );
								// Save fixed IDs.
								file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
								continue;
							}

							if ( ! $post_id ) {
								WP_CLI::line( sprintf( 'Post with tn_id %s not found: %s', $tn_id, $post_title ) );
								continue;
							}
						}

						// Save fixed IDs.
						file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
					}
				}
			}
		}
	}

	/**
	 * Callable for newspack-content-migrator town-news-fix-tags.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_tags( $args, $assoc_args ) {
		global $wpdb;
		$export_dir_path         = $assoc_args['export-dir-path'];
		$fixed_ids_filepath      = 'fixed-tags.log';
		$fixed_tags_log_filepath = 'fixed-tags-logs.log';
		$fixed_ids               = is_file( $fixed_ids_filepath ) ? file( $fixed_ids_filepath, FILE_IGNORE_NEW_LINES ) : [];

		// Delete all tags terms and relationships.
		// $wpdb->query( "DELETE FROM $wpdb->terms WHERE term_id IN (SELECT term_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'post_tag');" );
		// $wpdb->query( "DELETE FROM $wpdb->term_taxonomy WHERE taxonomy = 'post_tag';" );
		// $wpdb->query( "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id NOT IN (SELECT term_taxonomy_id FROM $wpdb->term_taxonomy);" );
		// wp_cache_flush();
		// die();

		$export_dir_iterator = new DirectoryIterator( $export_dir_path );
		foreach ( $export_dir_iterator as $year_dir ) {
			$year = intval( $year_dir->getFilename() );

			if ( $year_dir->isDot() || ! checkdate( 1, 1, $year ) ) {
				continue;
			}

			$year_dir_iterator = new DirectoryIterator( $year_dir->getPathname() );
			foreach ( $year_dir_iterator as $month_dir ) {
				$month = intval( $month_dir->getFilename() );

				if ( $month_dir->isDot() || ! checkdate( $month, 1, $year ) ) {
					continue;
				}

				$this->logger->log( self::LOG_FILE, sprintf( 'Importing content for month %d', $month ) );

				$month_dir_iterator = new DirectoryIterator( $month_dir->getPathname() );

				foreach ( $month_dir_iterator as $file ) {
					if ( 'xml' === $file->getExtension() ) {
						$xml_doc = new SimpleXMLElement( file_get_contents( $file->getPathname() ) );
						$this->register_element_namespace( $xml_doc );

						$file_type  = (string) $xml_doc->xpath( '//tn:identified-content/tn:classifier[@type="tncms:asset"]' )[0]->attributes()->value;
						$tn_id      = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:doc-id', 'id-string' );
						$post_title = $this->get_element_by_xpath( $xml_doc, '//tn:body/tn:body.head/tn:hedline/tn:hl1' );
						$tags       = array_map(
							function( $tag ) {
								return ucwords( (string) $tag );
							},
							explode( ',', $this->get_element_by_xpath_attribute( $xml_doc, '//tn:meta[@name="tncms-flags"]', 'content' ) )
						);
						$keywords   = array_map(
							function( $keyword ) {
								return ucwords( (string) $keyword[0]->attributes()['key'] );
							},
							$xml_doc->xpath( '//tn:head/tn:docdata/tn:key-list/tn:keyword' )
						);

						if ( in_array( $tn_id, $fixed_ids ) ) {
							continue;
						}

						if ( ! in_array( $file_type, [ 'article', 'collection' ] ) ) {
							// Save fixed IDs.
							file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
							continue;
						}

						if ( ! empty( $tags ) ) {
							// Get post by meta.
							$post_id = $wpdb->get_var(
								$wpdb->prepare(
									"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
									self::TOWN_NEWS_ORIGINAL_ID_META_KEY,
									$tn_id
								)
							);

							if ( $post_id ) {
								// Set tags.
								$this->logger->log( $fixed_tags_log_filepath, sprintf( 'Post with tn_id %s (%d) is tagged: %s', $tn_id, $post_id, implode( ', ', $tags ) ), Logger::SUCCESS );
								wp_set_post_tags( $post_id, $tags, true );
								// Set YOAST Keywords.
								update_post_meta( $post_id, '_yoast_wpseo_focuskw', implode( ' ', $keywords ) );
								$this->logger->log( $fixed_tags_log_filepath, sprintf( 'Post with tn_id %s (%d) is set YOAST Keywords: %s', $tn_id, $post_id, implode( ' ', $keywords ) ), Logger::SUCCESS );
								// Save fixed IDs.
								file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
								continue;
							}

							if ( ! $post_id ) {
								$this->logger->log( $fixed_tags_log_filepath, sprintf( 'Post with tn_id %s not found: %s', $tn_id, $post_title ), Logger::WARNING );
								continue;
							}
						}

						// Save fixed IDs.
						file_put_contents( $fixed_ids_filepath, $tn_id . "\n", FILE_APPEND );
					}
				}
			}
		}
	}

	/**
	 * Get imported posts original IDs.
	 *
	 * @return array
	 */
	private function get_imported_original_ids() {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
				self::TOWN_NEWS_ORIGINAL_ID_META_KEY
			)
		);
	}

	/**
	 * Import a TownNews post from the exported XML file.
	 *
	 * @param string $xml_path The post XML.
	 * @param string $dir_path The post XML directory path.
	 * @param array  $imported_original_ids Already imported posts original IDs.
	 * @param ?int   $default_author_id Default author ID for posts without author in case it's set.
	 *
	 * @return int|false    The imported post ID, false otherwise.
	 */
	private function import_post_from_xml( $xml_path, $dir_path, $imported_original_ids, $default_author_id ) {
		if ( ! file_exists( $xml_path ) ) {
			$this->logger->log( self::LOG_FILE, 'not found ' . $xml_path );
			return false;
		}

		$xml_doc = new SimpleXMLElement( file_get_contents( $xml_path ) );
		$this->register_element_namespace( $xml_doc );

		$file_type = (string) $xml_doc->xpath( '//tn:identified-content/tn:classifier[@type="tncms:asset"]' )[0]->attributes()->value;

		if ( ! in_array( $file_type, [ 'article', 'collection' ] ) ) {
			return false;
		}

		$tn_id = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:doc-id', 'id-string' );

		// Skip already imported posts.
		if ( in_array( $tn_id, $imported_original_ids, true ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( "Skipping post '%s' as it already exists", $tn_id ) );
			return false;
		}

		$status          = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:docdata', 'management-status' );
		$featured_image  = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:head/tn:meta[@name="tncms-view-preview"]', 'content' );
		$title           = $this->get_element_by_xpath( $xml_doc, '//tn:body/tn:body.head/tn:hedline/tn:hl1' );
		$subtitle        = $this->get_element_by_xpath( $xml_doc, '//tn:body/tn:body.head/tn:hedline/tn:hl2' );
		$author_fullname = $this->get_element_by_xpath( $xml_doc, '//tn:body/tn:body.head/tn:byline[not(contains(@class, "tncms-author"))]' );
		$author_meta     = $xml_doc->xpath( '//tn:body/tn:body.head/tn:byline[contains(@class, "tncms-author")]' );
		$pubdate         = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:head/tn:docdata/tn:date.release', 'norm' );
		$categories      = array_map(
			function( $keyword ) {
				return (string) $keyword[0]->attributes()['position.section'];
			},
			$xml_doc->xpath( '//tn:head/tn:pubdata' )
		);
		$tags            = array_map(
			function( $tag ) {
				return ucwords( (string) $tag );
			},
			explode( ',', $this->get_element_by_xpath_attribute( $xml_doc, '//tn:meta[@name="tncms-flags"]', 'content' ) )
		);
		$keywords        = array_map(
			function( $keyword ) {
				return ucwords( (string) $keyword[0]->attributes()['key'] );
			},
			$xml_doc->xpath( '//tn:head/tn:docdata/tn:key-list/tn:keyword' )
		);

		// Add article.
		$post_id = $this->create_post( $tn_id, $title, 'usable' === $status ? 'publish' : 'draft', $pubdate );
		if ( ! $post_id ) {
			$this->logger->log( self::LOG_FILE, sprintf( "Skipping post '%s' as it already exists", $tn_id ) );
			return;
		}

		// Set post author if exists.
		$this->set_post_author( $post_id, $author_fullname, $author_meta, $default_author_id );

		// Set post content.
		$post_content = 'collection' === $file_type
		? $this->get_collection_content( $xml_doc, $dir_path, $post_id )
		: $this->get_article_content( $xml_doc, $dir_path, $post_id );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $post_content,
			]
		);

		// Set post tags and categories.
		wp_set_post_tags( $post_id, $tags );

		// Set YOAST keywords.
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', implode( ' ', $keywords ) );

		$categories_to_add = [];
		foreach ( $categories as $raw_category ) {
			$category_hierarchy = explode( '/', $raw_category );

			// Create the categories hierarchy.
			foreach ( $category_hierarchy as $category_index => $category ) {
				$category_name = $this->get_category_name_from_tn_slug( $category );
				if ( 0 !== $category_index ) {
					$parent_category_id = get_cat_ID( $this->get_category_name_from_tn_slug( $category_hierarchy[ $category_index - 1 ] ) );
					$category_id        = wp_create_category( $category_name, $parent_category_id );
				} else {
					$category_id = wp_create_category( $category_name );
				}

				if ( ! is_wp_error( $category_id ) ) {
					$categories_to_add[] = $category_id;
				}
			}
		}

		wp_set_post_categories( $post_id, $categories_to_add );
		if ( count( $categories_to_add ) > 1 ) {
			// Set the last item in the path as primary to keep the url structure.
			update_post_meta( $post_id, '_yoast_wpseo_primary_category', end( $categories_to_add ) );
		}

		// Set post subtitle.
		if ( ! empty( $subtitle ) ) {
			update_post_meta( $post_id, 'newspack_post_subtitle', $subtitle );
		}

		// Set featured image.
		if ( ! empty( $featured_image ) ) {
			$this->set_featured_image( $post_id, $dir_path, $featured_image );
		}

		// Hide featured images for collections.
		if ( 'collection' === $file_type ) {
			update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
		}

		return $post_id;
	}

	/**
	 * Create empty post from the TownNews ID.
	 *
	 * @param string $tn_id The post TownNews original ID.
	 * @param string $title The post title.
	 * @param string $status The post status.
	 * @param string $pubdate The post publication date.
	 * @return int|false The new Post ID, false if it already exists.
	 */
	private function create_post( $tn_id, $title, $status, $pubdate ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_status' => $status,
				'post_date'   => ( new \DateTime( $pubdate ) )->format( 'Y-m-d H:i:s' ),
			]
		);
		update_post_meta( $post_id, self::TOWN_NEWS_ORIGINAL_ID_META_KEY, $tn_id );

		return $post_id;
	}

	/**
	 * Get post by original TownNews ID.
	 *
	 * @param string $tn_id Original TownNews ID.
	 * @return int|false post ID if it exists, false otherwise.
	 */
	private function get_post_by_tn_id( $tn_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_exists = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s;",
				self::TOWN_NEWS_ORIGINAL_ID_META_KEY,
				$tn_id
			)
		);

		return $post_exists ? intval( $post_exists->post_id ) : false;
	}

	/**
	 * Generate article content from article node.
	 *
	 * @param \SimpleXMLElement $xml_doc Content XML node.
	 * @param string            $dir_path Directory path of the XML node.
	 * @param int               $post_id Post ID.
	 * @return string
	 */
	private function get_article_content( $xml_doc, $dir_path, $post_id ) {
		// Import attached media.
		$content        = [];
		$content_parent = $xml_doc->xpath( '//tn:body/tn:body.content' );
		foreach ( $content_parent[0]->children() as $element ) {
			if ( 'media' === $element->getName() ) {
				$content[] = $this->migrate_media_tag( $element, $dir_path, $post_id );
			} elseif ( 'block' === $element->getName() && 'breakout' === (string) $element->attributes()['class'] ) {
				$this->register_element_namespace( $element );
				$is_editor_note = "Editor's Note" === $this->get_element_by_xpath_attribute( $element, 'tn:classifier[@value="Editor\'s Note"]', 'value' );

				if ( $is_editor_note ) {
					if ( $element->attributes()->class ) {
						// If it does, add the new class to the existing "class" attribute.
						$classes                      = $element->attributes()->class;
						$classes                     .= ' newspack-editor-note';
						$element->attributes()->class = $classes;
					} else {
						// If it doesn't, simply add the new "class" attribute.
						$element->addAttribute( 'class', 'newspack-editor-note' );
					}
				}

				$content[] = $element->asXML();
			} else {
				$content[] = $element->asXML();
			}
		}

		return wp_specialchars_decode( join( "\n", $content ) );
	}

	/**
	 * Generate collection content from collection node.
	 *
	 * @param \SimpleXMLElement $xml_doc Content XML node.
	 * @param string            $dir_path Directory path of the XML node.
	 * @param int               $post_id Post ID.
	 * @return string
	 */
	private function get_collection_content( $xml_doc, $dir_path, $post_id ) {
		$media_ids      = [];
		$media_elements = $xml_doc->xpath( '//tn:body/tn:body.content/tn:media' );
		foreach ( $media_elements as $element ) {
			$media_type = (string) $element->attributes()['media-type'];
			if ( 'image' !== $media_type ) {
				if ( 'collection' !== $media_type ) {
					$this->logger->log( self::LOG_FILE, sprintf( "Couldn't download media for the gallery %d, media type not supported: %s", $post_id, $media_type ), Logger::WARNING );
				}

				continue;
			}

			$attachment_id = $this->import_attachment_from_media_element( $element, $dir_path, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( "Couldn't download media for the gallery %d: %s", $post_id, $attachment_id->get_error_message() ), Logger::WARNING );
				continue;
			}

			$media_ids[] = $attachment_id;
		}

		return serialize_block( $this->gutenberg_block_generator->get_jetpack_slideshow( $media_ids ) );
	}

	/**
	 * Set post author/co-author.
	 *
	 * @param int               $post_id Post ID.
	 * @param string            $display_name Author full name.
	 * @param \SimpleXMLElement $author_meta XML element containing author meta.
	 * @param ?int              $default_author_id Default author ID for posts without author in case it's set.
	 * @return void
	 */
	private function set_post_author( $post_id, $display_name, $author_meta, $default_author_id ) {
		$first_name                 = '';
		$last_name                  = '';
		$email                      = '';
		$avatar                     = '';

		if ( ! empty( $author_meta ) ) {
			$author_meta = current( $author_meta );
			$this->register_element_namespace( $author_meta );
			$first_name = $this->get_element_by_xpath( $author_meta, 'tn:person/tn:name.given' );
			$last_name  = $this->get_element_by_xpath( $author_meta, 'tn:person/tn:name.family' );
			$email      = $this->get_element_by_xpath( $author_meta, 'tn:virtloc[@class="email"]' );
			$avatar     = $this->get_element_by_xpath( $author_meta, 'tn:virtloc[@class="avatar"]' );
		}

		// clean byline.
		$display_name = str_starts_with( strtolower( $display_name ), 'by ' ) ? substr( $display_name, 3 ) : $display_name;
		// if no display_name use email address.
		if ( empty( $display_name ) ) {
			$display_name = $first_name . ' ' . $last_name;
			if ( empty( trim( $display_name ) ) ) {
				// Let's do our very best to get a display name that is NOT the email.
				$display_name = sanitize_user( strstr( $email, '@', true ) );
			}
		}

		// Setting default author/co-author in case the article doesn't have any.
		if ( empty( trim( $display_name ) ) ) {
			if ( $default_author_id ) {
				wp_update_post(
					[
						'ID'          => $post_id,
						'post_author' => $default_author_id,
					]
				);
				$this->logger->log( self::LOG_FILE, sprintf( 'Setting the author %d as a default author for the post %d.', $default_author_id, $post_id ), Logger::WARNING );
			} else {
				$default_co_author_id = $this->get_staff_author_id();
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $default_co_author_id ], $post_id );
				$this->logger->log( self::LOG_FILE, sprintf( 'Setting the co-author %d as a default author for the post %d.', $default_co_author_id, $post_id ), Logger::WARNING );
			}
			return;
		}

		$author = get_user_by( 'email', $email );
		if ( $author ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_author' => $author->ID,
				]
			);
		} else {
			// Set as a co-author.
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $display_name );
			if ( $guest_author ) {
				$author_id = $guest_author->ID;
			} else {
				$avatar_id = null;
				if ( ! empty( $avatar ) ) {
					$avatar_id = $this->attachments->import_external_file( $avatar, $display_name );

					if ( is_wp_error( $avatar_id ) ) {
						$this->logger->log( self::LOG_FILE, sprintf( "Can't download user avatar for the post %d: %s", $post_id, $avatar_id->get_error_message() ), Logger::WARNING );
						$avatar_id = null;
					}
				}
				$author_id = $this->coauthorsplus_logic->create_guest_author(
					[
						'display_name'  => $display_name,
						'first_name'    => $first_name,
						'last_name'     => $last_name,
						'user_email'    => $email,
						'avatar'        => $avatar_id,
					]
				);
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $author_id ], $post_id );
		}
	}

	/**
	 * Set a post featured image from an image file.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $dir_path Directory where the media file exists.
	 * @param string $image_file_path Media filename.
	 * @return void
	 */
	private function set_featured_image( $post_id, $dir_path, $image_file_path ) {
		$attachment_id = $this->import_media_from_file( $dir_path, $image_file_path, $post_id );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		} else {
			$this->logger->log( self::LOG_FILE, sprintf( "Couldn't import the media '%s' as a featured image for the post %d.", "$dir_path/$image_file_path", $post_id ), Logger::WARNING );
		}
	}

	/**
	 * Migrate Media tag to an HTML tag.
	 *
	 * @param \SimpleXMLElement $element Media element.
	 * @param string            $dir_path Directory path where the media file exists /export_path/year/month.
	 * @param int               $parent_id Media parent post ID.
	 * @return \SimpleXMLElement
	 */
	private function migrate_media_tag( $element, $dir_path, $parent_id ) {
		$media_type = (string) $element->attributes()['media-type'];
		$this->register_element_namespace( $element );

		switch ( $media_type ) {
			case 'image':
				$media_title   = $this->get_element_by_xpath_attribute( $element, 'tn:media-metadata[@name="title"]', 'value' );
				$attachment_id = $this->import_attachment_from_media_element( $element, $dir_path, $parent_id );

				if ( is_wp_error( $attachment_id ) ) {
					return '';
				}

				$attachment_url = wp_get_attachment_url( $attachment_id );
				$caption_tag    = ! empty( $media_caption ) ? '<figcaption>' . $media_caption . '</figcaption>' : '';

				return '<figure>
	<img src="' . $attachment_url . '" alt="' . $media_title . '">
	' . $caption_tag . '
</figure>';
			case 'article':
				return '';
			case 'file':
				$media_caption = $this->get_element_by_xpath( $element, 'tn:media-caption' );
				$media_title   = $this->get_element_by_xpath_attribute( $element, 'tn:media-metadata[@name="title"]', 'value' );
				$attachment_id = $this->import_attachment_from_media_element( $element, $dir_path, $parent_id );

				if ( is_wp_error( $attachment_id ) ) {
					return '';
				}

				$attachment_url = wp_get_attachment_url( $attachment_id );
				$link_content   = ! empty( $media_caption ) ? $media_caption : ( ! empty( $media_title ) ? $media_title : basename( $attachment_url ) );

				return '<a href="' . $attachment_url . '">' . $link_content . '</a>';
			case 'collection':
				$collection_id       = $element->xpath( 'tn:media-metadata[@name="id"]' );
				$collection_filepath = ! empty( $collection_id ) ? $dir_path . '/' . (string) $collection_id[0]->attributes()['value'] . '.xml' : null;
				if ( ! is_file( $collection_filepath ) ) {
					return '';
				}

				return $this->generate_gallery_from_xml_collection( $collection_filepath, $dir_path, $parent_id );
			case 'video':
				$video_id       = $element->xpath( 'tn:media-metadata[@name="id"]' );
				$video_filepath = ! empty( $video_id ) ? $dir_path . '/' . (string) $video_id[0]->attributes()['value'] . '.xml' : null;
				if ( ! is_file( $video_filepath ) ) {
					return '';
				}

				return $this->generate_video_from_xml_collection( $element, $video_filepath, $dir_path, $parent_id );
			case 'audio':
				$media_caption = $this->get_element_by_xpath( $element, 'tn:media-caption' );
				$attachment_id = $this->import_attachment_from_media_element( $element, $dir_path, $parent_id );

				if ( is_wp_error( $attachment_id ) ) {
					return '';
				}

				$attachment_url = wp_get_attachment_url( $attachment_id );
				$caption_tag    = ! empty( $media_caption ) ? '<figcaption>' . $media_caption . '</figcaption>' : '';

				return '<figure>
				<audio controls>
				  <source src="' . $attachment_url . '" type="audio/mpeg">
				</video>
				' . $caption_tag . '
			  </figure>';
			default:
				return false;
		}
	}

	/**
	 * Import attachment from a media XML element.
	 *
	 * @param \SimpleXMLElement $element Media element.
	 * @param string            $dir_path Directory path where the media file exists /export_path/year/month.
	 * @param int               $parent_id Media parent post ID.
	 * @return int|WP_Error
	 */
	private function import_attachment_from_media_element( $element, $dir_path, $parent_id ) {
		$this->register_element_namespace( $element );

		$media_source   = $this->get_element_by_xpath_attribute( $element, 'tn:media-reference[@source][not(contains(@name, "tncms-view-preview"))]', 'source' );
		$media_title    = $this->get_element_by_xpath_attribute( $element, 'tn:media-metadata[@name="title"]', 'value' );
		$media_id       = $this->get_element_by_xpath_attribute( $element, 'tn:media-metadata[@name="id"]', 'value' );
		$media_poster   = $this->get_element_by_xpath_attribute( $element, 'tn:media-reference[@name="tncms-view-poster"]', 'source' );
		$media_caption  = $this->get_element_by_xpath( $element, 'tn:media-caption' );
		$media_producer = $this->get_element_by_xpath( $element, 'tn:media-producer' );

		$media_title   = ! empty( $media_title ) ? $media_title : ( ! empty( $media_id ) ? $media_id : '' );
		$attachment_id = $this->import_media_from_file( $dir_path, $media_source, $parent_id, $media_title, $media_caption );

		if ( ! is_wp_error( $attachment_id ) ) {
			if ( ! empty( $media_producer ) ) {
				update_post_meta( $attachment_id, '_townnews_media_producer', $media_producer );
			}

			if ( ! empty( $media_poster ) ) {
				$poster_id = $this->import_media_from_file( $dir_path, $media_poster, $parent_id );
				if ( ! is_wp_error( $poster_id ) ) {
					update_post_meta( $attachment_id, '_townnews_media_poster_id', $poster_id );
				}
			}
		}

		return $attachment_id;
	}

	/**
	 * Import media from a file
	 *
	 * @param string $dir_path Directory where the media file exists.
	 * @param string $media_source Media filename.
	 * @param int    $parent_id Media post parent ID.
	 * @param string $media_title Media title.
	 * @param string $media_caption Media caption.
	 * @return int|WP_Error
	 */
	private function import_media_from_file( $dir_path, $media_source, $parent_id = null, $media_title = '', $media_caption = null ) {
		// the meta value should be in the format /year/month/media.jpg to be unique.
		$media_meta_value = str_replace( dirname( $dir_path, 2 ), '', $dir_path ) . "/$media_source";

		$attachment_id = $this->get_post_by_tn_id( $media_meta_value );

		// Do not import media if already imported.
		if ( $attachment_id ) {
			return $attachment_id;
		}

		$media_file_path = "$dir_path/$media_source";
		$attachment_id   = $this->attachments->import_external_file( $media_file_path, $media_title, $media_caption, null, $media_title, $parent_id );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( "Couldn't import the media '%s' as a featured image for the post %d.", $media_file_path, $parent_id ), Logger::WARNING );
		} else {
			update_post_meta( $attachment_id, self::TOWN_NEWS_ORIGINAL_ID_META_KEY, $media_meta_value );
		}

		return $attachment_id;
	}

	/**
	 * Register element namespace to be able to use xpath queries.
	 *
	 * @param \SimpleXMLElement $element Element to register a namespace for it.
	 * @param string            $namespace Namespace to use, defaults to `tn`.
	 * @return void
	 */
	private function register_element_namespace( $element, $namespace = 'tn' ) {
		foreach ( $element->getDocNamespaces() as $str_prefix => $str_namespace ) {
			if ( strlen( $str_prefix ) == 0 ) {
				$str_prefix = $namespace;
			}
			$element->registerXPathNamespace( $str_prefix, $str_namespace );
		}
	}

	/**
	 * Generate a Jetpack Slideshow from a colleciton XML.
	 *
	 * @param string $xml_filepath Collection XML filepath.
	 * @param string $dir_path Directory path where the media file exists /export_path/year/month.
	 * @param int    $parent_id Media parent post ID.
	 * @return string
	 */
	private function generate_gallery_from_xml_collection( $xml_filepath, $dir_path, $parent_id ) {
		$xml_doc = new SimpleXMLElement( file_get_contents( $xml_filepath ) );
		$this->register_element_namespace( $xml_doc );

		$media_elements    = $xml_doc->xpath( '//tn:body.content/tn:media[@media-type="image"]' );
		$gallery_media_ids = array_values(
			array_filter(
				array_map(
					function( $media_element ) use ( $dir_path, $parent_id ) {
						$attachment_id = $this->import_attachment_from_media_element( $media_element, $dir_path, $parent_id );
						return is_wp_error( $attachment_id ) ? null : $attachment_id;
					},
					$media_elements
				)
			)
		);

		return serialize_block( $this->gutenberg_block_generator->get_jetpack_slideshow( $gallery_media_ids ) );
	}

	/**
	 * Generate a Jetpack Slideshow from a colleciton XML.
	 *
	 * @param \SimpleXMLElement $element Parent element to look into.
	 * @param string            $xml_filepath Collection XML filepath.
	 * @param string            $dir_path Directory path where the media file exists /export_path/year/month.
	 * @param int               $parent_id Media parent post ID.
	 * @return string
	 */
	private function generate_video_from_xml_collection( $element, $xml_filepath, $dir_path, $parent_id ) {
		$xml_doc = new SimpleXMLElement( file_get_contents( $xml_filepath ) );
		$this->register_element_namespace( $xml_doc );

		$source = $this->get_element_by_xpath_attribute( $xml_doc, '//tn:body.content/tn:media[@media-type="video"]/tn:media-reference[@source]', 'source' );

		if ( str_contains( $source, 'youtube.com' ) || str_contains( $source, 'youtu.be' ) ) {
			preg_match( '/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?(?P<id>[^#&?]*).*/', $source, $video_id_matcher );
			$youtube_id = array_key_exists( 'id', $video_id_matcher ) ? $video_id_matcher['id'] : null;

			if ( ! $youtube_id ) {
				$this->logger->log( self::LOG_FILE, sprintf( "Couldn't get the YouTube video ID from the URL %s from the file %s", $source, $xml_filepath ) );
				return '';
			}

			return "https://www.youtube.com/embed/$youtube_id";
		}

		// A video file.
		$attachment_id = $this->import_attachment_from_media_element( $element, $dir_path, $parent_id );

		if ( is_wp_error( $attachment_id ) ) {
			return '';
		}

		$poster_id      = get_post_meta( $attachment_id, '_townnews_media_poster_id', true );
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$poster_url     = $poster_id ? wp_get_attachment_url( $poster_id ) : '';
		$caption_tag    = ! empty( $media_caption ) ? '<figcaption>' . $media_caption . '</figcaption>' : '';
		$poster_tag     = ! empty( $poster_url ) ? ' poster="' . $poster_url . '"' : '';

		return '<figure>
		<video controls' . $poster_tag . '" width="100%">
		  <source src="' . $attachment_url . '" type="video/mp4">
		</video>
		' . $caption_tag . '
	  </figure>';
	}

	/**
	 * Generate a default co-author for posts without author.
	 *
	 * @return int
	 */
	private function get_staff_author_id() {
		return $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => self::DEFAULT_CO_AUTHOR_DISPLAY_NAME ] );
	}

	/**
	 * Get XML element value by xpath.
	 *
	 * @param \SimpleXMLElement $parent_element Parent element to look into.
	 * @param string            $xpath XPath of the element we're looking for.
	 * @param string            $default Default value if the element doesn't exists, empty string by default.
	 * @return string Element value if found, $default otherwise.
	 */
	private function get_element_by_xpath( $parent_element, $xpath, $default = '' ) {
		$element_node = $parent_element->xpath( $xpath );
		return count( $element_node ) > 0 ? (string) $element_node[0] : $default;
	}

	/**
	 * Get XML element value by xpath.
	 *
	 * @param \SimpleXMLElement $parent_element Parent element to look into.
	 * @param string            $xpath XPath of the element we're looking for.
	 * @param string            $attribute Element attribute that contains the content.
	 * @param string            $default Default value if the element doesn't exists, empty string by default.
	 * @return string Element value if found, $default otherwise.
	 */
	private function get_element_by_xpath_attribute( $parent_element, $xpath, $attribute, $default = '' ) {
		$element_node = $parent_element->xpath( $xpath );
		return count( $element_node ) > 0 ? (string) $element_node[0]->attributes()[ $attribute ] : $default;
	}

	/**
	 * Generate category name from Townnews category slugs.
	 *
	 * @param string $category_slug Category slug.
	 * @return string
	 */
	private function get_category_name_from_tn_slug( $category_slug ) {
		return ucfirst( str_replace( '_', ' ', trim( $category_slug ) ) );
	}
}
