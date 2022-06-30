<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Sponsors as SponsorsLogic;
use \WP_CLI;
use XMLReader;
use DOMDocument;

/**
 * Custom migration scripts for Commonwealth Beacon.
 */
class CommonwealthBeaconMigrator implements InterfaceMigrator {
	/**
	 * @var SponsorsLogic.
	 */
	private $sponsors_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->sponsors_logic = new SponsorsLogic();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator commonwealth-beacon-sponsors-migrator',
			array( $this, 'new_orleans_tribune_albums_migration' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'xml-path',
						'description' => 'XML including an export of sponsors data file path.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator commonwealth-beacon-sponsors-migrator`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function new_orleans_tribune_albums_migration( $args, $assoc_args ) {
		$xml_path = $assoc_args['xml-path'];
		if ( ! file_exists( $xml_path ) ) {
			WP_CLI::error( sprintf( 'There is no XML file at %s', $xml_path ) );
		}

		$reader = new XMLReader();
		$reader->open( $xml_path );

		// Read forward to first <item> element.
		while ( ( $is_item = $reader->read() ) && $reader->name !== 'item' );

		$doc = new DOMDocument();
		while ( $is_item ) {

			// Here's how to reference namespaces as objects, e.g. properties found in the XML file like xmlns:wp, xmlns:content, xmlns:excerpt.
			$xml_element  = simplexml_import_dom( $reader->expand( $doc ) );
			$wp_base      = $xml_element->children( 'http://wordpress.org/export/1.2/' );
			$content_base = $xml_element->children( 'http://purl.org/rss/1.0/modules/content/' );

			$title         = (string) $xml_element->title;
			$slug          = (string) $wp_base->post_name;
			$description   = (string) $content_base->encoded;
			$thumbnail     = '';
			$url           = '';
			$post_date     = (string) $wp_base->post_date;
			$post_modified = (string) $wp_base->post_modified;

			for ( $key_postmeta = 0; $key_postmeta + 1 <= $wp_base->postmeta->count(); $key_postmeta++ ) {
				$meta_key   = (string) $wp_base->postmeta[ $key_postmeta ]->meta_key;
				$meta_value = (string) $wp_base->postmeta[ $key_postmeta ]->meta_value;

				if ( 'post_image' === $meta_key ) {
					$thumbnail = $meta_value;
				} elseif ( 'link_to_sponsor_website' === $meta_key ) {
					$url = $meta_value;
				}
			}

			$sponsor_id = $this->sponsors_logic->insert_sponsor( $title, $slug, $description, $thumbnail, $url, $post_date, $post_modified );
			WP_CLI::success( sprintf( '%s imported as a new sponsor: %d', $title, $sponsor_id ) );

			// Next <item> when done reading this one.
			$is_item = $reader->next( 'item' );
		}
	}
}
