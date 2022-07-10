<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackPostImageDownloader\Downloader;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_Query;
use \WP_CLI;
use \DirectoryIterator;
use \SimpleXMLElement;

class TownNewsMigrator implements InterfaceMigrator {
	const GALLERIES_MEDIA_NOT_SUPPORTED_LOG = 'townnews_galleries_media_not_supported.log';
	const GALLERIES_LOG                     = 'townnews_galleries_migration.log';
	const AUTHORS_LOG                       = 'townnews_authors_migration.log';

	/**
	 * @var Downloader.
	 */
	private $downloader;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->downloader          = new Downloader();
		$this->posts_logic         = new PostsLogic();
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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
            'newspack-content-migrator migrate-collections-from-town-news',
            [ $this, 'migrate_collections_from_town_news' ],
            [
				'shortdesc' => 'Migrate collections content from town news to WordPress.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-path',
						'description' => 'Folder path that contains the exported data it should contain the data as YEAR/MONTH/article.xml',
						'optional'    => false,
						'repeating'   => false,

					],
				],
			]
        );

		WP_CLI::add_command(
            'newspack-content-migrator town-news-co-authors',
            [ $this, 'town_mews_co_authors' ],
            [
				'shortdesc' => 'Fix migrated co-authors.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-path',
						'description' => 'Folder path that contains the exported data it should contain the data as YEAR/MONTH/article.xml',
						'optional'    => false,
						'repeating'   => false,

					],
				],
			]
        );
	}

	/**
	 * Callable for newspack-content-migrator migrate-collections-from-town-news command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function migrate_collections_from_town_news( $args, $assoc_args ) {
		$export_path = $assoc_args['export-path'];

		if ( ! is_dir( $export_path ) ) {
			WP_CLI::error( 'Invalid export dir.' );
		}

		$export_dir = new DirectoryIterator( $export_path );
		foreach ( $export_dir as $year ) {
			if ( ! $year->isDot() && $year->isDir() ) {
				WP_CLI::line( sprintf( 'Migrating year %s', $year->getFilename() ) );

				$year_dir = new DirectoryIterator( $year->getFileInfo()->getPathname() );
				foreach ( $year_dir as $month ) {
					if ( ! $month->isDot() ) {
						WP_CLI::line( sprintf( 'Migrating month %s', $month->getFilename() ) );

						$month_path = $month->getFileInfo()->getPathname();
						foreach ( glob( $month_path . '/*.xml' ) as $article_file ) {
							$this->migrate_article_from_xml( $article_file, $month_path );
						}
					}
				}
			}
		}
	}

	/**
	 * Callable for newspack-content-migrator town-news-co-authors command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function town_mews_co_authors( $args, $assoc_args ) {
		global $coauthors_plus;
		$export_path = $assoc_args['export-path'];

		if ( ! is_dir( $export_path ) ) {
			WP_CLI::error( 'Invalid export dir.' );
		}

		$export_dir = new DirectoryIterator( $export_path );
		foreach ( $export_dir as $year ) {
			if ( ! $year->isDot() && $year->isDir() ) {
				WP_CLI::line( sprintf( 'Migrating year %s', $year->getFilename() ) );

				$year_dir = new DirectoryIterator( $year->getFileInfo()->getPathname() );
				foreach ( $year_dir as $month ) {
					if ( ! $month->isDot() ) {
						WP_CLI::line( sprintf( 'Migrating month %s', $month->getFilename() ) );

						$month_path = $month->getFileInfo()->getPathname();
						foreach ( glob( $month_path . '/*.xml' ) as $article_file ) {
							$this->fix_article_byline( $article_file );
						}
					}
				}
			}
		}

		// Remove co-authors with empty posts.
		$args         = array(
			'posts_per_page' => -1,
			'post_type'      => $coauthors_plus->guest_authors->post_type,
			'post_status'    => 'any',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		$author_posts = ( new WP_Query( $args ) )->get_posts();
		foreach ( $author_posts as $author_post ) {
			$co_author   = $this->coauthorsplus_logic->get_guest_author_by_id( $author_post->ID );
			$posts_count = $coauthors_plus->get_guest_author_post_count( $co_author );

			if ( 0 === $posts_count ) {
				$this->coauthorsplus_logic->coauthors_guest_authors->delete( $author_post->ID );
				$this->log( self::AUTHORS_LOG, sprintf( 'Deleting co-author %s', $author_post->post_title ) );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Migrate an article from XML file.
	 *
	 * @param string $xml_path XML file to migrate.
	 * @param string $xml_folder_path XML container folder path.
	 * @return void
	 */
	private function migrate_article_from_xml( $xml_path, $xml_folder_path ) {
		$xml_content = file_get_contents( $xml_path );

		$xml_element = new SimpleXMLElement( $xml_content );
		$xml_element->registerXPathNamespace( 'n', 'http://iptc.org/std/NITF/2006-10-18/' );

		$document_type = (string) current( $xml_element->xpath( '//n:identified-content/n:classifier[@type="tncms:asset"]' ) )->attributes()->value;

		if ( 'collection' === $document_type ) {
			$collection_post_id = $this->migrate_collection( $xml_folder_path, $xml_element, $xml_path );
			if ( $collection_post_id ) {
				$this->log( self::GALLERIES_LOG, sprintf( 'Collection migrated from %s: %d', $xml_path, $collection_post_id ) );
			}
		}
	}

	/**
	 * Migrate an article from XML file.
	 *
	 * @param string $xml_path XML file to migrate.
	 * @return void
	 */
	private function fix_article_byline( $xml_path ) {
		$xml_content = file_get_contents( $xml_path );

		$xml_element = new SimpleXMLElement( $xml_content );
		$xml_element->registerXPathNamespace( 'n', 'http://iptc.org/std/NITF/2006-10-18/' );

		$original_id   = (string) $xml_element->xpath( '//n:head//n:doc-id' )[0]->attributes()->{'id-string'};
		$document_type = (string) current( $xml_element->xpath( '//n:identified-content/n:classifier[@type="tncms:asset"]' ) )->attributes()->value;

		if ( 'article' === $document_type ) {
			$byline_details = $xml_element->xpath( '//n:body.head//n:byline' );

			$original_posts = get_posts(
				[
					'meta_query' => [
						[
							'key'   => '_newspack_import_id',
							'value' => $original_id,
						],
					],
				]
			);

			if ( ! $original_posts || count( $original_posts ) > 1 ) {
				$this->log( self::AUTHORS_LOG, sprintf( 'Skipping %s with original_id = %s', $xml_path, $original_id ) );
				return false;
			}

			$this->set_article_authors( current( $original_posts )->ID, $byline_details, $xml_element, $xml_path );
		}
	}

	/**
	 * Fix post author if needed.
	 *
	 * @param int               $post_id Post to fix its author.
	 * @param mixed[]           $byline_details Byline details.
	 * @param \SimpleXMLElement $xml_element Post XML element.
	 * @return boolean
	 */
	private function set_article_authors( $post_id, $byline_details, $xml_element ) {
		$display_name       = (string) $byline_details[0];
		$co_authors_details = [];

		// Remove "By" from co-authors names.
		if ( str_starts_with( strtolower( $display_name ), 'by' ) ) {
			$display_name       = preg_replace( '/(by\s?:?\s)/i', '', $display_name );
			$co_authors_details = [
				'display_name' => $display_name,
			];
		}

		if ( isset( $byline_details[1] ) ) { // Fix messed up authors display name.
			$first_name_node = $xml_element->xpath( '//n:byline/n:person/n:name.given' );
			$last_name_node  = $xml_element->xpath( '//n:byline/n:person/n:name.family' );
			$email_node      = $xml_element->xpath( '//n:byline/n:virtloc[@class="email"]' );
			$avatar_node     = $xml_element->xpath( '//n:byline/n:virtloc[@class="avatar"]' );

			$first_name   = count( $first_name_node ) > 0 ? (string) $first_name_node[0] : '';
			$last_name    = count( $last_name_node ) > 0 ? (string) $last_name_node[0] : '';
			$email        = count( $email_node ) > 0 ? (string) $email_node[0] : '';
			$avatar       = count( $avatar_node ) > 0 ? (string) $avatar_node[0] : '';
			$display_name = trim( ! empty( $display_name ) ? $display_name : "$first_name $last_name" );
			$display_name = trim( empty( $display_name ) ? $email : $display_name );

			if ( empty( $display_name ) ) {
				$this->log( self::AUTHORS_LOG, sprintf( 'Skipping post %d, the display name is empty', $post_id ) );
				return false;
			}

			try {
				$avatar_att_id = empty( $avatar ) ? null : $this->downloader->import_external_file( $avatar );
			} catch ( \Exception $e ) {
				$avatar_att_id = null;
				$this->log( self::AUTHORS_LOG, sprintf( "Can't download the avatar for the author %s from %s: %s", $display_name, $avatar, $e->getMessage() ) );
			}

			$co_authors_details = [
				'display_name' => $display_name,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
			];

			if ( $avatar_att_id ) {
				$co_authors_details['avatar'] = $avatar_att_id;
			}

			if ( ! empty( $email ) ) {
				$co_authors_details['user_login'] = $email;
				$co_authors_details['user_email'] = $email;
			}
		}

		if ( ! empty( $co_authors_details ) ) {
			try {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $co_authors_details );
				if ( is_wp_error( $guest_author_id ) ) {
					$this->log( self::AUTHORS_LOG, sprintf( 'Could not create GA for post %d with display name: %s', $post_id, $display_name ) );
					return false;
				}

				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post_id );
				$this->log( self::AUTHORS_LOG, sprintf( 'Fixed %d post author to: %s', $post_id, $display_name ) );
				return true;
			} catch ( \Exception $e ) {
				$this->log( self::AUTHORS_LOG, sprintf( "Could not create GA full name '%s': %s", $display_name, $e->getMessage() ) );
				return false;
			}
		}
	}

	/**
	 * Migrate a collection from an XML element to a gallery post.
	 *
	 * @param string            $all_media_path Media folder path.
	 * @param \SimpleXMLElement $xml_element XML element to migrate.
	 * @param string            $xml_path XML file path.
	 * @return int|false        Migrated post ID, false otherwise.
	 */
	private function migrate_collection( $all_media_path, $xml_element, $xml_path ) {
		$original_id   = (string) $xml_element->xpath( '//n:head//n:doc-id' )[0]->attributes()->{'id-string'};
		$post_title    = (string) $xml_element->xpath( '//n:body//n:hedline/n:hl1' )[0];
		$post_date     = (string) $xml_element->xpath( '//n:head//n:date.release' )[0]->attributes()->norm;
		$post_modified = $this->get_post_modified( $xml_element, $post_date );

		$original_posts = get_posts(
			[
				'meta_query' => [
					[
						'key'   => 'original_id',
						'value' => $original_id,
					],
				],
			]
        );

		if ( ! empty( $original_posts ) ) {
			$this->log( self::GALLERIES_LOG, sprintf( "Skipping %s as it's already migrated Original_id = %s", $xml_path, $original_id ) );
			return false;
		}

		// Gallery content.
		$gallery_images = [];
		foreach ( $xml_element->xpath( '//n:body/n:body.content/n:media' ) as $media_element ) {
			$media_type   = (string) $media_element->attributes()->{'media-type'};
			$media_source = (string) $media_element->{'media-reference'}[0]->attributes()->source;
			if ( 'image' !== $media_type && ! str_ends_with( $media_source, '.jpg' ) && ! str_ends_with( $media_source, '.png' ) ) {
				// print_r( $media_element );
				$this->log( self::GALLERIES_MEDIA_NOT_SUPPORTED_LOG, "Media type not supported: $media_source" );
				// die();
			}

			$media_caption_item = $media_element->{'media-caption'};
			$media_caption      = $media_caption_item ? (string) $media_caption_item[0]->value : '';
			$media_path         = sprintf( '%s/%s', $all_media_path, $media_source );

			if ( is_file( $media_path ) ) {
				$media_id = $this->downloader->import_external_file( $media_path, $media_source, $media_caption );

				if ( is_wp_error( $media_id ) ) {
					$this->log( self::GALLERIES_LOG, sprintf( "Can't create a media from %s: %s", $media_source, $media_id ) );
					continue;
				}

				$gallery_images[] = $media_id;
			}
		}

		if ( empty( $gallery_images ) ) {
			$this->log( self::GALLERIES_LOG, sprintf( 'Gallery empty for %s', $xml_path ) );
			return false;
		}

		$post_content = $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $gallery_images );

		$post_id = wp_insert_post(
            array(
				'post_date'      => $post_date,
				'post_title'     => $post_title,
				'post_content'   => $post_content,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_name'      => sanitize_title( $post_title ),
				'post_modified'  => $post_modified,
				'post_type'      => 'post',
            )
        );

		if ( is_wp_error( $post_id ) ) {
			$this->log( self::GALLERIES_LOG, sprintf( "Can't add colleciton from %s: %s", $xml_path, $post_id ) );
			return false;
		}

		update_post_meta( $post_id, 'original_id', $original_id );

		return $post_id;
	}

	/**
	 * Get post modified date.
	 *
	 * @param \SimpleXMLElement $xml_element XML element.
	 * @param string            $default Default value.
	 * @return string|null
	 */
	private function get_post_modified( $xml_element, $default = null ) {
		$revision = $xml_element->xpath( '//n:head/n:revision-history' );
		return ( $revision && count( $revision ) > 0 ) ? (string) $revision[0]->attributes()->norm : $default;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::warning( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
