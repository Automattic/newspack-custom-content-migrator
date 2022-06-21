<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use XMLReader;
use DOMDocument;

/**
 * Custom migration scripts for Posts' content.
 */
class XMLMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

    /**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
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
	}

	/**
	 * Initial function for this migrator, shared code which illustrates how we can parse XMLs in PHP.
	 *
	 * @return void
	 */
	public function demo_parse_xml() {

		// Get path to file (e.g. argument).
		$xml_path = '/abs/path/to/file.xml';
		if ( ! file_exists( $xml_path)) {
			WP_CLI::error( 'not found ' . $xml_path );
		}

		// Use XMLReader on XML file.
		$reader = new XMLReader;
		$reader->open( $xml_path );

		// Read forward to first <item> element.
		while ( ( $is_item = $reader->read() ) && $reader->name !== 'item' );

		$doc = new DOMDocument;
		while ( $is_item ) {

			// Here's how to reference namespaces as objects, e.g. properties found in the XML file like xmlns:wp, xmlns:content, xmlns:excerpt.
			$xml_element  = simplexml_import_dom( $reader->expand( $doc ) );
			$wp_base      = $xml_element->children( "http://wordpress.org/export/1.2/" );
			$content_base = $xml_element->children( "http://purl.org/rss/1.0/modules/content/" );
			$excerpt_base = $xml_element->children( "http://wordpress.org/export/1.2/excerpt/" );

			// Read data, various examples follow.

			//      <wp:post_type><![CDATA[attachment]]></wp:post_type>
			$post_type = (string) $wp_base->post_type;

			//       <wp:post_id>549949</wp:post_id>
			$post_id = (string) $wp_base->post_id;

			//      <title><![CDATA[Newspack test title]]></title>
			$title = (string) $xml_element->title;

			//      <wp:attachment_url><![CDATA[https://host.com/wp-content/uploads/2022/01/i.png]]></wp:attachment_url>
			$attachment_url = (string) $wp_base->attachment_url;

			//      <content:encoded><![CDATA[Newspack test desc]]></content:encoded>
			$att_description = (string) $content_base->encoded;

			//      <excerpt:encoded><![CDATA[Newspack test caption]]></excerpt:encoded>
			$att_caption = (string) $excerpt_base->encoded;

			//      <wp:postmeta>
			//          <wp:meta_key><![CDATA[meta1]]></wp:meta_key>
			//          <wp:meta_value><![CDATA[aaa]]></wp:meta_value>
			//      </wp:postmeta>
			//      <wp:postmeta>
			//          <wp:meta_key><![CDATA[meta2]]></wp:meta_key>
			//          <wp:meta_value><![CDATA[bbb]]></wp:meta_value>
			//      </wp:postmeta>
			for ( $key_postmeta = 0; $key_postmeta + 1 <= $wp_base->postmeta->count() ; $key_postmeta++ ) {
				$meta_key   = (string) $wp_base->postmeta[ $key_postmeta ]->meta_key;
				$meta_value = (string) $wp_base->postmeta[ $key_postmeta ]->meta_value;
			}

			// Next <item> when done reading this one.
			$is_item = $reader->next( 'item' );
		}
	}
}
