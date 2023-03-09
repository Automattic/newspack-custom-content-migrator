<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMElement;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use \WP_CLI;

class ChulaVistaMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments|null Attachments instance.
	 */
	protected $attachments;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance              = new $class();
			self::$instance->attachments = new Attachments();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator chula-vista-migrate-xmls',
			[ $this, 'cmd_migrate_xmls' ],
			[
				'shortdesc' => 'Migrates XML files from Chula Vista.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file-path',
						'description' => 'Path to XML file.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	public function cmd_migrate_xmls( $args, $assoc_args ) {
		$file_path = $args[0];

		$dom = new \DOMDocument();
		$dom->loadXML( file_get_contents( $file_path ), LIBXML_PARSEHUGE );
		$contents = $dom->getElementsByTagName( 'content' );

		foreach ( $contents as $content ) {
			/* @var DOMElement $content */
//			var_dump( $content->nodeName );

			$post_data = [
				'post_author'       => 0,
				'post_date'         => '',
				'post_date_gmt'     => '',
				'post_content'      => '',
				'post_title'        => '',
				'post_excerpt'      => '',
				'post_status'       => '',
				'post_type'         => 'post',
				'post_name'         => '',
				'post_modified'     => '',
				'post_modified_gmt' => '',
				'post_category'     => [],
				'tags_input'        => [],
				'meta_input'        => [],
			];

			$images = [];

			foreach ( $content->childNodes as $node ) {
				if ( '#text' === $node->nodeName ) {
					continue;
				}

				switch ( $node->nodeName ) {
					case 'id':
						$post_data['meta_input']['original_article_id'] = $node->nodeValue;
						break;
					case 'title':
						$post_data['post_title'] = $node->nodeValue;
						break;
					case 'slug':
						$post_data['post_name'] = $node->nodeValue;
						break;
					case 'dateupdated':
						$date                       = DateTime::createFromFormat( 'Y-m-d\TH:i:s', $node->nodeValue, new DateTimeZone( 'America/Los_Angeles' ) );
						$post_data['post_modified'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( new DateTimeZone( 'Etc/GMT' ) );
						$post_data['post_modified_gmt'] = $date->format( 'Y-m-d H:i:s' );
						break;
					case 'datepublish':
						$date                   = DateTime::createFromFormat( 'Y-m-d\TH:i:s', $node->nodeValue, new DateTimeZone( 'America/Los_Angeles' ) );
						$post_data['post_date'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( new DateTimeZone( 'GMT' ) );
						$post_data['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
						break;
					case 'intro':
						$post_data['post_excerpt'] = $node->nodeValue;
						break;
					case 'description':
						$post_data['post_content'] = '<!-- wp:html -->' . $node->nodeValue . '<!-- /wp:html -->';
						break;
					case 'author':
						$author = $this->handle_author( $node );

						if ( ! is_null( $author ) ) {
							$post_data['post_author'] = $author->ID;
						}

						break;
					case 'tags':
						foreach ( $node->childNodes as $tag ) {
							if ( '#text' === $tag->nodeName ) {
								continue;
							}
							$term = $this->handle_tag( $tag );

							if ( 'category' === $term['type'] ) {
								$post_data['post_category'][] = $term['term_id'];
							}
						}
						break;
					case 'medias':
						foreach ( $node->childNodes as $media ) {
							if ( '#text' === $media->nodeName ) {
								continue;
							}

							$images[] = $media;
						}
						break;
					default:
//						var_dump( "\t" . $node->nodeName . ': ' . $node->nodeValue );
				}
			}

			$post_id = wp_insert_post( $post_data, true );

			if ( ! is_wp_error( $post_id ) ) {
				foreach ( $images as $image ) {
					$this->handle_media( $image, $post_id );
				}
			}
		}
		die();
	}

	protected function handle_author( DOMElement $author ) {
		$author_data = [
			'user_login' => '',
			'user_pass'  => wp_generate_password( 12 ),
			'user_email' => '',
			'first_name' => '',
			'last_name'  => '',
			'role'       => 'author',
			'meta_input' => [],
		];

		foreach ( $author->attributes as $attribute ) {
			switch ( $attribute->nodeName ) {
				case 'id':
					$author_data['meta_input']['original_author_id'] = $attribute->nodeValue;
					break;
				case 'email':
					$author_data['user_email'] = $attribute->nodeValue;
					break;
				case 'firstname':
					$author_data['first_name'] = $attribute->nodeValue;
					break;
				case 'lastname':
					$author_data['last_name'] = $attribute->nodeValue;
					break;
				case 'username':
					$author_data['user_login'] = $attribute->nodeValue;
					break;
			}
		}

		WP_CLI::log( 'Attempting to create Author: ' . $author_data['user_login'] . ' (' . $author_data['user_email'] . ')' );

		$user = get_user_by( 'email', $author_data['user_email'] );

		if ( $user ) {
			WP_CLI::log( 'Found existing user with email: ' . $author_data['user_email'] );

			return $user;
		}

		$user = get_user_by( 'login', $author_data['user_login'] );

		if ( $user ) {
			WP_CLI::log( 'Found existing user with login: ' . $author_data['user_login'] );

			return $user;
		}

		global $wpdb;
		$user = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
       				u.* 
				FROM $wpdb->users u INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id 
				WHERE um.meta_key = 'original_author_id' 
				  AND um.meta_value = %s",
				$author_data['meta_input']['original_author_id']
			)
		);

		if ( $user ) {
			WP_CLI::log( 'Found existing user with original_author_id: ' . $author_data['meta_input']['original_author_id'] . ' (' . $user->ID . ')' );

			return $user;
		}

		$user_id = wp_insert_user( $author_data );
		WP_CLI::log( 'Created user: ' . $user_id );

		return get_user_by( 'id', $user_id );
	}

	protected function handle_tag( DOMElement $tag ) {
		$tag_type  = $tag->getAttribute( 'type' );
		$tag_label = $tag->getAttribute( 'label' );

		WP_CLI::log( 'Handling tag - Type: ' . $tag_type . ' | Label: ' . $tag_label );

		if ( 'Category' === $tag->getAttribute( 'type' ) ) {
			return [
				'type'    => 'category',
				'term_id' => wp_create_category( $tag->getAttribute( 'label' ) ),
			];
		}

		WP_CLI::warning( 'Unknown tag type: ' . $tag_type );
	}

	protected function handle_media( DOMElement $media, int $post_id = 0 ) {
		$name = $media->getAttribute( 'name' );
//		$filename    = $media->getAttribute( 'filename' );
		$url         = $media->getAttribute( 'url' );
		$description = $media->getAttribute( 'description' );
//		$mime_type   = $media->getAttribute( 'mimetype' );
		$date        = DateTime::createFromFormat(
			'Y-m-d\TH:i:s.u',
			$media->getAttribute( 'added' ),
			new DateTimeZone( 'America/Los_Angeles' )
		);
		$attribution = $media->getAttribute( 'attribution' );
		if ( ! empty( $attribution ) ) {
			$attribution = "by $attribution";
		}
		$original_id = $media->getAttribute( 'id' );
		$is_featured_image = (bool) intval( $media->getElementsByTagName( 'isfeatured' )->item( 0 )->nodeValue );

		$attachment_id = $this->attachments->import_external_file(
			$url,
			sanitize_title( $name ),
			$attribution,
			$description,
			null,
			$post_id,
			[
				'meta_input' => [
					'original_post_id' => $original_id,
				],
			]
		);

		if ( is_numeric( $attachment_id ) ) {
			WP_CLI::log( 'Created attachment: ' . $attachment_id );
			wp_update_post(
				[
					'ID'        => $attachment_id,
					'post_date' => $date->format( 'Y-m-d H:i:s' ),
				]
			);

			if ( $is_featured_image ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}
	}
}
