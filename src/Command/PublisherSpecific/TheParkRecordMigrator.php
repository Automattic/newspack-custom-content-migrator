<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DOMDocument;
use DOMNode;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\WordPressXMLHandler;
use stdClass;
use WP_CLI;

/**
 * Class TheParkRecordMigrator
 */
class TheParkRecordMigrator implements InterfaceCommand {

	const GUEST_AUTHOR_SCRAPE_META = 'newspack_guest_author_scrape_done';
	const API_URL_POST = 'https://www.parkrecord.com/wp-json/wp/v2/posts';

	/**
	 * TheParkRecordMigrator Instance.
	 *
	 * @var TheParkRecordMigrator
	 */
	private static $instance;

	/**
	 * TheParkRecordMigrator constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get instance.
	 *
	 * @return mixed|TheParkRecordMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * Inherited method to register commands.
	 *
	 * @return void
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator the-park-record-guest-author-scrape',
			[ $this, 'cmd_guest_author_scrape' ],
			[
				'shortdesc' => 'Scrape guest authors from The Park Record.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'xml-path',
						'description' => 'The path to XML files containing imported posts',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 *
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_guest_author_scrape( array $args, array $assoc_args ) {
		$xml_path = $assoc_args['xml-path'];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$processed_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s",
				self::GUEST_AUTHOR_SCRAPE_META
			)
		);
		$processed_ids = array_flip( $processed_ids );

		$xml = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$posts_channel_children = $rss->childNodes->item( 1 )->childNodes;

		foreach ( $posts_channel_children as $child ) {
			if ( 'item' === $child->nodeName ) {
				$post = WordPressXMLHandler::get_parsed_data( $child, [] )['post'];

				ConsoleColor::white( 'Processing Post ID: ' )->bright_blue( $post['ID'] )->output();

				if ( array_key_exists( $post['ID'], $processed_ids ) ) {
					ConsoleColor::white( "Post ID {$post['ID']} already processed." )->output();
					continue;
				}

				if ( 'publish' !== $post['post_status'] ) {
					ConsoleColor::white( 'Post status:' )->yellow( $post['post_status'] )->white( 'Skipping...' )->output();
					continue;
				}

				if ( 'post' !== $post['post_type'] ) {
					ConsoleColor::white( 'Post type:' )->yellow( $post['post_type'] )->white( 'Skipping...' )->output();
					continue;
				}

				$url = $this->get_post_permalink( $post['ID'] );

				if ( empty( $url ) ) {
					ConsoleColor::bright_yellow( 'Unable to find permalink.' )->output();
					update_post_meta( $post['ID'], self::GUEST_AUTHOR_SCRAPE_META, 'no_url' );
					continue;
				}

				ConsoleColor::white( 'Scraping URL:' )->cyan( $url )->output();
				$response = wp_remote_get( $url );

				$response_code = wp_remote_retrieve_response_code( $response );

				if ( 200 !== $response_code ) {
					ConsoleColor::red( "Error fetching URL. Status Code ({$response_code})" )->output();
					update_post_meta( $post['ID'], self::GUEST_AUTHOR_SCRAPE_META, 'error_fetching_url' );
					continue;
				}

				$body = wp_remote_retrieve_body( $response );

				if ( is_wp_error( $body ) ) {
					ConsoleColor::red( 'Error fetching URL.' )->output();
					update_post_meta( $post['ID'], self::GUEST_AUTHOR_SCRAPE_META, 'error_fetching_url' );
					continue;
				}

				$html = new DOMDocument();
				@$html->loadHTML( $body );

				/*$address = $html->getElementsByTagName( 'address' )->item( 0 );

				if ( ! $address || empty( $address->textContent ) ) {

					//#article-byline > div > div.col > div.editor-name > h6 > a:nth-child(1)
					////*[@id="article-byline"]/div/div[1]/div[2]/h6/a[1]

					$byline = $html->getElementById( 'article-byline' );

					if ( ! $byline || empty( trim( $byline->textContent ) ) ) {
						ConsoleColor::yellow( 'No address nor byline found.' )->output();
						update_post_meta( $post['ID'], self::GUEST_AUTHOR_SCRAPE_META, 'no_address' );
						continue;
					}

					$author_name  = '';
					$author_email = '';

					$link_elements = $html->getElementsByTagName( 'a' );

					foreach ( $link_elements as $link_element ) {
						if ( 'author' !== $link_element->getAttribute( 'rel' ) ) {
							continue;
						}

						if ( is_email( $link_element->textContent ) ) {
							$author_email = $link_element->textContent;
						} elseif ( empty( $author_name ) ) {
							$author_name = $link_element->textContent;
						}
					}

					ConsoleColor::green( 'Author Name:' )->bright_green( $author_name )->green( 'Author Email:' )->bright_green( $author_email )->output();
					continue;
				}

				ConsoleColor::green( $html->saveHTML( $address ) )->output();*/

				$author = $this->find_address_tag( $html );

				if ( false === $author ) {
					$author = $this->find_byline_tag( $html );
				}

				if ( false === $author ) {
					ConsoleColor::yellow( 'No address nor byline found.' )->output();
					update_post_meta( $post['ID'], self::GUEST_AUTHOR_SCRAPE_META, 'no_address' );
					continue;
				}

				$console = ConsoleColor::green( 'Author Name:' );

				if ( 'address' === $author->source ) {
					ConsoleColor::yellow( 'Original Author HTML:' )->bright_yellow( $author->original )->output();
					$console->green( $author->name );
				} else {
					$console->underlined_bright_green( $author->name );
				}

				if ( 'address' === $author->source ) {
					if ( ! empty( $author->email ) ) {
						$console->green( 'Author Email:' )->green( $author->email );
					}
				} else {
					$console->green( 'Author Email:' )->underlined_bright_green( $author->email );
				}

				$console->output();

				$db_post     = get_post( $post['ID'] );
				$post_author = get_user_by( 'id', $db_post->post_author );

				// If we just have a name, add the author as a coauthor.

				// If we have an email, and it matches the post, just check the display name to see if it needs updating.
				// if we have an email, and it doesn't match the post, then we need to see if user exists
				// If the user exists, check if the display name needs updating
				// if the user exists, and the display name is the same, update the post author.
				// if the user doesn't exist, create the user and update the post author.

			}
		}
	}

	/**
	 * Retrieve the post permalink from the API.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $post The post as an array.
	 *
	 * @return string
	 */
	public function get_post_permalink( int $post_id = 0, array $post = [] ): string {
		if ( ! empty( $post_id ) ) {
			$post = json_decode( wp_remote_retrieve_body( wp_remote_get( self::API_URL_POST . '/' . $post_id ) ), true );
		}

		return $post['link'] ?? '';
	}

	/**
	 * Convenience function that attempts to find the address tag in the HTML.
	 *
	 * @param DOMDocument $html The HTML to search.
	 *
	 * @return object|bool
	 */
	public function find_address_tag( DOMDocument $html ): object|bool {

		$address = $html->getElementsByTagName( 'address' )->item( 0 );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $address || empty( trim( $address->textContent ) ) ) {
			return false;
		}

		$author           = new stdClass();
		$author->original = $address->ownerDocument->saveHTML( $address );
		$author->source   = 'address';
		$author->name     = '';
		$author->email    = '';

		foreach ( $address->childNodes as $child ) {
			if ( '#text' === $child->nodeName ) {
				$text_content = trim( $child->textContent );

				if ( is_email( $text_content ) ) {
					$author->email = $text_content;
				} else {
					if ( empty( $author->name ) ) {
						$author->name = $text_content;
					} else {
						$author->name .= " $text_content";
					}
				}
			}
		}

		return $author;
	}

	/**
	 * Convenience function that attempts to find the byline tag in the HTML.
	 *
	 * @param DOMDocument $html The HTML to search.
	 *
	 * @return object|bool
	 */
	public function find_byline_tag( DOMDocument $html ): object|bool {
		$byline = $html->getElementById( 'article-byline' );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $byline || empty( trim( $byline->textContent ) ) ) {
			return false;
		}

		$author           = new stdClass();
		$author->original = $byline->ownerDocument->saveHTML( $byline );
		$author->source   = 'byline';
		$author->name     = '';
		$author->email    = '';

		$link_elements = $html->getElementsByTagName( 'a' );

		foreach ( $link_elements as $link_element ) {
			if ( 'author' !== $link_element->getAttribute( 'rel' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$text_content = trim( $link_element->textContent );
			if ( is_email( $text_content ) ) {
				$author->email = $text_content;
			} elseif ( empty( $author_name ) && ! empty( $text_content ) ) {
				$author->name = $text_content;
			}
		}

		return $author;
	}

	/**
	 * Get the inner HTML of a DOMNode.
	 *
	 * @param DOMNode $node The node to get the inner HTML of.
	 *
	 * @return string
	 */
	public function inner_html( DOMNode $node ): string {
		// @see https://stackoverflow.com/questions/2087103/how-to-get-innerhtml-of-domnode
		$inner_html = '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $node->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$inner_html .= $node->ownerDocument->saveHTML( $child );
		}

		return $inner_html;
	}

	/**
	 * Get a list of posts from the API.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Number of posts per page.
	 *
	 * @return array
	 */
	public function get_posts_list( int $page = 1, int $per_page = 100 ): array {
		$url = add_query_arg(
			[
				'order'    => 'desc',
				'orderby'  => 'id',
				'page'     => $page,
				'per_page' => $per_page,
			],
			self::API_URL_POST
		);

		$response = wp_remote_get( $url );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$posts              = json_decode( wp_remote_retrieve_body( $response ) );
		$posts['last_page'] = false;

		if ( count( $posts ) < $per_page ) {
			$posts['last_page'] = true;
		}

		return $posts;
	}
}
