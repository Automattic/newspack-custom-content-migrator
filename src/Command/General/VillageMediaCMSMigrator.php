<?php

namespace NewspackCustomContentMigrator\Command\General;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use stdClass;
use WP_User;
use WP_CLI;

/**
 * Class VillageMediaCMSMigrator.
 * General purpose importer for Village Media CMS XML files.
 *
 * @package NewspackCustomContentMigrator\Command\General
 */
class VillageMediaCMSMigrator implements InterfaceCommand {

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static ?InterfaceCommand $instance = null;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments|null Attachments instance.
	 */
	protected ?Attachments $attachments;

	/**
	 * Gutenberg block generator.
	 *
	 * @var GutenbergBlockGenerator|null
	 */
	protected ?GutenbergBlockGenerator $block_generator;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance                  = new $class();
			self::$instance->attachments     = new Attachments();
			self::$instance->block_generator = new GutenbergBlockGenerator();
		}

		return self::$instance;
	}

	/**
	 * Register commands.
	 *
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-migrate-xmls',
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
					[
						'type'        => 'assoc',
						'name'        => 'start-at-row-number',
						'description' => 'Row number to start at.',
						'optional'    => true,
						'default'     => 0,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone',
						'description' => 'Timezone to use for dates.',
						'optional'    => true,
						'default'     => 'America/New_York',
					],
				],
			]
		);
	}

	/**
	 * Migrates XML files from Village Media Export.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws Exception When the XML file cannot be loaded, or timezone is invalid.
	 */
	public function cmd_migrate_xmls( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $file_path ), LIBXML_PARSEHUGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents         = $dom->getElementsByTagName( 'content' );
		$row_number_start = $assoc_args['start-at-row-number'];
		$gmt_timezone     = new DateTimeZone( 'GMT' );

		foreach ( $contents as $row_number => $content ) {
			/* @var DOMElement $content */

			if ( $row_number < $row_number_start ) {
				continue;
			}

			echo WP_CLI::colorize( "Row number: %B{$row_number}%n\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

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

			$images      = [];
			$has_gallery = false;

			foreach ( $content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '#text' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				switch ( $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					case 'id':
						$post_data['meta_input']['original_article_id'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_imported = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT post_id 
								FROM $wpdb->postmeta 
								WHERE meta_key = 'original_article_id' 
								  AND meta_value = %s",
								$node->nodeValue
							)
						); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

						if ( ! is_null( $post_imported ) ) {
							WP_CLI::log( 'Post already imported, skipping...' );
							continue 3;
						}
						break;
					case 'title':
						$post_data['post_title'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						WP_CLI::log( 'Post Title: ' . $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'slug':
						$post_data['post_name'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'dateupdated':
						$date                       = $this->get_date_time( $node->nodeValue, $assoc_args['timezone'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['post_modified'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( $gmt_timezone );
						$post_data['post_modified_gmt'] = $date->format( 'Y-m-d H:i:s' );
						break;
					case 'datepublish':
						$date                   = $this->get_date_time( $node->nodeValue, $assoc_args['timezone'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['post_date'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( $gmt_timezone );
						$post_data['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
						$post_data['post_status']   = 'publish';
						break;
					case 'intro':
						$post_data['post_excerpt']                         = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['meta_input']['newspack_post_subtitle'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'description':
						$post_data['post_content'] = '<!-- wp:html -->' . $node->nodeValue . '<!-- /wp:html -->'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'author':
						$author = $this->handle_author( $node );

						if ( ! is_null( $author ) ) {
							$post_data['post_author'] = $author->ID;
						}

						break;
					case 'tags':
						foreach ( $node->childNodes as $tag ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( '#text' === $tag->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								continue;
							}
							$term = $this->handle_tag( $tag );

							if ( 'category' === $term['type'] ) {
								$post_data['post_category'][] = $term['term_id'];
							} elseif ( 'post_tag' === $term['type'] ) {
								$post_data['tags_input'][] = $term['term_id'];
							}
						}

						break;
					case 'medias':
						foreach ( $node->childNodes as $media ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( '#text' === $media->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								continue;
							}

							$images[] = $media;
						}

						break;
					case 'gallery':
						$has_gallery = true;
						break;
				}
			}

			$post_id = wp_insert_post( $post_data, true );

			if ( ! is_wp_error( $post_id ) ) {

				$attachment_ids = [];
				foreach ( $images as $image ) {
					$attachment = $this->handle_media( $image, $post_id, $assoc_args['timezone'] );

					if ( $attachment['is_gallery_item'] && ! is_null( $attachment ) ) {
						$attachment_ids[] = $attachment['attachment_id'];
					}
				}

				if ( $has_gallery && ! empty( $attachment_ids ) ) {
					$post_data['ID']           = $post_id;
					$post_data['post_content'] .= serialize_blocks(
						[
							$this->block_generator->get_gallery(
								$attachment_ids,
								3,
								'full',
								'none',
								true
							)
						]
					);
					wp_update_post( $post_data );
				}
			}
		}
	}

	/**
	 * Convenience function to handle Author nodes. It will attempt to find the author first, and if none is found,
	 * it will create a new one.
	 *
	 * @param DOMElement $author Author node.
	 *
	 * @return WP_User|stdClass
	 */
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
			switch ( $attribute->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				case 'id':
					$author_data['meta_input']['original_author_id'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'email':
					$author_data['user_email'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'firstname':
					$author_data['first_name'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'lastname':
					$author_data['last_name'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'username':
					$author_data['user_login'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
		$user = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

	/**
	 * Convenience function to handle Tag nodes and category creation.
	 *
	 * @param DOMElement $tag XML Tag node.
	 *
	 * @return array|void
	 */
	protected function handle_tag( DOMElement $tag ) {
		$tag_type  = $tag->getAttribute( 'type' );
		$tag_label = $tag->getAttribute( 'label' );

		WP_CLI::log( 'Handling tag - Type: ' . $tag_type . ' | Label: ' . $tag_label );

		if ( 'Category' === $tag->getAttribute( 'type' ) ) {
			return [
				'type'    => 'category',
				'term_id' => wp_create_category( $tag->getAttribute( 'label' ) ),
			];
		} elseif ( 'Tag' === $tag->getAttribute( 'type' ) ) {
			$post_tag = wp_create_tag( $tag->getAttribute( 'label' ) );

			if ( is_wp_error( $post_tag ) ) {
				WP_CLI::warning( 'Error creating tag: ' . $post_tag->get_error_message() );
			} else {
				return [
					'type'    => 'post_tag',
					'term_id' => $post_tag[ 'term_id' ],
				];
			}
		}

		WP_CLI::warning( 'Unknown tag type: ' . $tag_type );
	}

	/**
	 * Convenience function to handle Media nodes and image attachment creation.
	 *
	 * @param DOMElement $media XML Media node.
	 * @param int $post_id Post ID to attach the media to.
	 * @param string $timezone Timezone to use for the media date.
	 *
	 * @return array|null
	 * @throws Exception If the media file cannot be downloaded.
	 */
	protected function handle_media( DOMElement $media, int $post_id = 0, string $timezone = 'America/New_York' ): array|null {
		$name = $media->getAttribute( 'name' );
		// $filename    = $media->getAttribute( 'filename' );
		$url         = $media->getAttribute( 'url' );
		$description = $media->getAttribute( 'description' );
		// $mime_type   = $media->getAttribute( 'mimetype' );
		$date = $this->get_date_time( $media->getAttribute( 'added' ), $timezone );

		$post_date = $date->format( 'Y-m-d H:i:s' );
		$date->setTimezone( new DateTimeZone( 'GMT' ) );
		$post_date_gmt = $date->format( 'Y-m-d H:i:s' );

		$attribution = $media->getAttribute( 'attribution' );

		if ( ! empty( $attribution ) ) {
			$attribution = "by $attribution";
		}

		$original_id       = $media->getAttribute( 'id' );
		$is_featured_image = (bool) intval( $media->getElementsByTagName( 'isfeatured' )->item( 0 )->nodeValue );
		$is_gallery_item   = (bool) intval( $media->getElementsByTagName( 'isgalleryitem' )->item( 0 )->nodeValue );

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
					'ID'            => $attachment_id,
					'post_date'     => $post_date,
					'post_date_gmt' => $post_date_gmt,
				]
			);

			if ( $is_featured_image ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}

			return [
				'attachment_id'   => $attachment_id,
				'is_featured'     => $is_featured_image,
				'is_gallery_item' => $is_gallery_item,
			];
		}

		return null;
	}

	/**
	 * Attempts to parse a date time string into a DateTime object.
	 *
	 * @param string $date_time Date time string.
	 * @param string $timezone Timezone to use for the date.
	 *
	 * @return DateTime
	 * @throws Exception If the timezone cannot be parsed.
	 */
	private function get_date_time( string $date_time, string $timezone = 'America/New_York' ): DateTime {
		$date = DateTime::createFromFormat(
			'Y-m-d\TH:i:s.u',
			$date_time,
			new DateTimeZone( $timezone )
		);

		if ( is_bool( $date ) ) {
			$date = DateTime::createFromFormat(
				'Y-m-d\TH:i:s',
				$date_time,
				new DateTimeZone( $timezone )
			);

			if ( is_bool( $date ) ) {
				WP_CLI::warning( 'Unable to parse date: ' . $date_time );
				$date = new DateTime( 'now', new DateTimeZone( $timezone ) );
			}
		}

		return $date;
	}
}
