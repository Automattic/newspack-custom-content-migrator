<?php

namespace NewspackCustomContentMigrator\Command\General;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use Symfony\Component\DomCrawler\Crawler;
use WP_CLI;

class PloneCMSMigrator implements InterfaceCommand {

	/**
	 * PloneCMSMigrator constructor.
	 *
	 * @var InterfaceCommand|null The single instance of the class.
	 */
	private static $instance;

	/**
	 * Gutenberg block generator.
	 *
	 * @var GutenbergBlockGenerator|null
	 */
	protected ?GutenbergBlockGenerator $block_generator;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Redirection $redirection
	 */
	private $redirection;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments|null
	 */
	protected ?Attachments $attachments;

	/**
	 * @var string The prefix for the JSON filename containing a map of article to image UIDs.
	 */
	private string $article_image_tree_filename_prefix = 'plone_article_image_tree_';

	/**
	 * @var string The path to the JSON file containing a map of article to image UIDs.
	 */
	private string $article_image_tree_path = WP_CONTENT_DIR . '/';

	/**
	 * Static function to instantiate this class.
	 *
	 * @return PloneCMSMigrator
	 */
	public static function get_instance(): PloneCMSMigrator {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance                      = new $class();
			self::$instance->attachments         = new Attachments();
			self::$instance->redirection         = new Redirection();
			self::$instance->block_generator     = new GutenbergBlockGenerator();
			self::$instance->coauthorsplus_logic = new CoAuthorPlus();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator plone-cms-migrate-users',
			[ $this, 'cmd_migrate_users' ],
			[
				'shortdesc' => 'Migrate users from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone',
						'description' => 'Timezone to use for dates (default: America/New_York)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'America/New_York',
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator plone-cms-migrate-images',
			[ $this, 'cmd_migrate_images' ],
			[
				'shortdesc' => 'Migrate images from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'positional',
						'name'        => 'blob-path',
						'description' => 'Full path to blob assets (/srv/htdocs/wp-content/blobs)',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone',
						'description' => 'Timezone to use for dates (default: America/New_York)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'America/New_York',
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator plone-save-gallery-metadata-for-images',
			[ $this, 'cmd_save_gallery_metadata_for_images' ],
			[
				'shortdesc' => 'Plone JSON files have a gallery data point which specifies if an image belongs to a gallery. This command saves that data to the postmeta table.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator plone-cms-migrate-articles',
			[ $this, 'cmd_migrate_articles' ],
			[
				'shortdesc' => 'Migrate JSON data from the old site.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone',
						'description' => 'Timezone to use for dates (default: America/New_York)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'America/New_York',
					],
					[
						'type'        => 'assoc',
						'name'        => 'primary-categories',
						'description' => 'List of categories to use as the primary category for articles where assigned. Articles where these appear will also be used as the parent category.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Function to process users from a Plone JSON users file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception If the file is not found.
	 */
	public function cmd_migrate_users( $args, $assoc_args ): void {
		$file_path    = $args[0];
		$start        = $assoc_args['start'] ?? 0;
		$end          = $assoc_args['end'] ?? PHP_INT_MAX;
		$timezone     = $assoc_args['timezone'] ?? 'America/New_York';
		$timezone_obj = new DateTimeZone( $timezone );

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			WP_CLI::log( 'Row Number: ' . $row_number . ' - ' . $row['username'] );

			$date_created = new DateTime( 'now', $timezone_obj );

			if ( ! empty( $row['date_created'] ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row['date_created'], $timezone_obj );
			}

			$result = wp_insert_user(
				[
					'user_login'      => $row['username'],
					'user_pass'       => wp_generate_password(),
					'user_email'      => $row['email'],
					'display_name'    => $row['fullname'],
					'first_name'      => $row['first_name'],
					'last_name'       => $row['last_name'],
					'user_registered' => $date_created->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber',
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::log( $result->get_error_message() );
			} else {
				WP_CLI::success( "User {$row['email']} created." );
			}
		}
	}

	/**
	 * Function to process images from a Plone JSON image file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception If the file is not found.
	 */
	public function cmd_migrate_images( $args, $assoc_args ) {
		$file_path    = $args[0];
		$blob_path    = $args[1];
		$start        = $assoc_args['start'] ?? 0;
		$end          = $assoc_args['end'] ?? PHP_INT_MAX;
		$timezone     = $assoc_args['timezone'] ?? 'America/New_York';
		$timezone_obj = new DateTimeZone( $timezone );

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		if ( ! str_ends_with( $blob_path, '/' ) ) {
			$blob_path .= '/';
		}

		$creators           = [];
		$article_image_tree = [];
		global $wpdb;
		$import_uids = $wpdb->get_results( "SELECT meta_value, post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_image_UID'", OBJECT_K );
		$import_uids = array_map( fn( $row ) => $row->post_id, $import_uids );

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			if ( ! empty( $row['parent'] ) ) {
				if ( ! array_key_exists( $row['parent']['UID'], $article_image_tree ) ) {
					$article_image_tree[ $row['parent']['UID'] ] = [ $row['UID'] ];
				} else {
					$article_image_tree[ $row['parent']['UID'] ][] = $row['UID'];
				}
			}

			$post_author = 0;

			WP_CLI::log( 'Looking for user: ' . $row['creators'][0] );
			if ( array_key_exists( $row['creators'][0], $creators ) ) {
				$post_author = $creators[ $row['creators'][0] ];
				echo WP_CLI::colorize( '%yFound user in array... ' . $post_author . '%n' ) . "\n";
			} else {
				$user = get_user_by( 'login', $row['creators'][0] );

				if ( ! $user ) {
					echo WP_CLI::colorize( '%rUser not found in DB...' ) . "\n";
				} else {
					echo WP_CLI::colorize( '%YUser found in DB, updating role... ' . $row['creators'][0] . ' => ' . $user->ID . '%n' ) . "\n";
					$user->set_role( 'author' );
					$creators[ $row['creators'][0] ] = $user->ID;
					$post_author                     = $user->ID;
				}
			}

			if ( array_key_exists( $row['UID'], $import_uids ) ) {
				echo WP_CLI::colorize( '%yImage already exists, skipping... ' . $import_uids[ $row['UID'] ] . '%n' ) . "\n";
				continue;
			}

			$created_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['created'], $timezone_obj );
			$updated_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['modified'], $timezone_obj );

			$caption = '';

			if ( ! empty( $row['description'] ) ) {
				$caption = $row['description'];
			}

			if ( ! empty( $row['credit'] ) ) {
				if ( ! empty( $caption ) ) {
					$caption .= '<br />';
				}

				$caption .= 'Credit: ' . $row['credit'];
			}

			// check image param, if not empty, it is a blob
			if ( ! empty( $row['image'] ) ) {
				echo WP_CLI::colorize( '%wHandling blob...' ) . "\n";
				$filename              = $row['image']['filename'];
				$destination_file_path = WP_CONTENT_DIR . '/uploads/' . $filename;
				$file_blob_path        = $blob_path . $row['image']['blob_path'];
				file_put_contents( $destination_file_path, file_get_contents( $file_blob_path ) );

				$result = media_handle_sideload(
					[
						'name'     => $filename,
						'tmp_name' => $destination_file_path,
					],
					0,
					$row['description'],
					[
						'post_title'        => $row['id'] ?? '',
						'post_author'       => $post_author,
						'post_excerpt'      => $caption,
						'post_content'      => $row['description'] ?? '',
						'post_date'         => $created_at->format( 'Y-m-d H:i:s' ),
						'post_date_gmt'     => $created_at->format( 'Y-m-d H:i:s' ),
						'post_modified'     => $updated_at->format( 'Y-m-d H:i:s' ),
						'post_modified_gmt' => $updated_at->format( 'Y-m-d H:i:s' ),
					]
				);

				if ( is_wp_error( $result ) ) {
					echo WP_CLI::colorize( '%r' . $result->get_error_message() . '%n' ) . "\n";
				} else {
					echo WP_CLI::colorize( "%gImage {$row['id']} created.%n" ) . "\n";
					update_post_meta( $result, 'plone_image_UID', $row['UID'] );
					update_post_meta( $result, 'plone_image_url', $row['@id'] );

					if ( ! empty( $row['gallery'] ) && is_numeric( $row['gallery'] ) ) {
						update_post_meta( $result, 'plone_gallery', $row['gallery'] );
					}
				}
			} else if ( ! empty( $row['legacyPath'] ) ) {
				echo WP_CLI::colorize( '%wHandling legacyPath...' ) . "\n";
				// download image and upload it
				$attachment_id = media_sideload_image( $row['@id'] );

				if ( is_wp_error( $attachment_id ) ) {
					echo WP_CLI::colorize( '%r' . $attachment_id->get_error_message() . '%n' ) . "\n";
				} else {
					echo WP_CLI::colorize( "%gImage {$row['id']} created.%n" ) . "\n";
					wp_update_post(
						[
							'ID'                => $attachment_id,
							'post_author'       => $post_author,
							'post_excerpt'      => $caption,
							'post_content'      => $row['description'] ?? '',
							'post_date'         => $created_at->format( 'Y-m-d H:i:s' ),
							'post_date_gmt'     => $created_at->format( 'Y-m-d H:i:s' ),
							'post_modified'     => $updated_at->format( 'Y-m-d H:i:s' ),
							'post_modified_gmt' => $updated_at->format( 'Y-m-d H:i:s' ),
						]
					);

					update_post_meta( $attachment_id, 'plone_image_UID', $row['UID'] );
					update_post_meta( $attachment_id, 'plone_image_url', $row['@id'] );

					if ( ! empty( $row['gallery'] ) && is_numeric( $row['gallery'] ) ) {
						update_post_meta( $attachment_id, 'plone_gallery', $row['gallery'] );
					}
				}
			} else {
				echo WP_CLI::colorize( '%rNo image found for this row...' ) . "\n";
			}
		}

		if ( ! empty( $article_image_tree ) ) {
			$timestamp               = date( 'Y-m-d_H-i-s' );
			$filename                = $this->article_image_tree_filename_prefix . $timestamp . '.json';
			$article_image_tree_file = $this->article_image_tree_path . $filename;
			file_put_contents( $article_image_tree_file, json_encode( $article_image_tree ) );
			WP_CLI::log( 'Article image tree file created: ' . $article_image_tree_file );
		}
	}

	/**
	 * This should really just be a private method within this class, but I've made it a command for convenience.
	 * This is because I discovered this gallery data point after the initial migration, and subsequently
	 * need to record this data for images. This functionality has already been added to the main
	 * import command for plone images, so it shouldn't be necessary to run independently.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws Exception If the file cannot be found.
	 */
	public function cmd_save_gallery_metadata_for_images( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			if ( ! empty( $row['gallery'] ) && is_numeric( $row['gallery'] ) ) {
				global $wpdb;
				$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'plone_image_UID' AND meta_value = %s", $row['UID'] ) );

				if ( $post_id ) {
					update_post_meta( $post_id, 'plone_gallery', $row['gallery'] );
					echo WP_CLI::colorize( '%gGallery metadata updated.%n' ) . "\n";
				}
			} else {
				echo WP_CLI::colorize( '%wNo gallery metadata found for this row...%n' ) . "\n";
			}
		}
	}

	/**
	 * This command handles the importing of Articles from a Plone JSON file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws Exception If the file path is invalid.
	 */
	public function cmd_migrate_articles( $args, $assoc_args ) {
		$article_image_tree_wildcard_path = $this->article_image_tree_path . $this->article_image_tree_filename_prefix . '*.json';
		$article_image_tree_files         = glob( $article_image_tree_wildcard_path );

		if ( empty( $article_image_tree_files ) ) {
			WP_CLI::error( 'No article image tree files found. Did you already import images?' );
		}

		$target_article_image_tree_full_path = array_pop( $article_image_tree_files );

		$article_image_tree = json_decode( file_get_contents( $target_article_image_tree_full_path ), true );

		$main_categories = explode( ',', str_replace( ' ', '', $assoc_args['primary-categories'] ?? '' ) );

		foreach ( $main_categories as $category ) {
			wp_create_category( $category );
		}

		global $wpdb;

		$file_path    = $args[0];
		$start        = $assoc_args['start'] ?? 0;
		$end          = $assoc_args['end'] ?? PHP_INT_MAX;
		$timezone     = $assoc_args['timezone'] ?? 'America/New_York';
		$timezone_obj = new DateTimeZone( $timezone );

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		$plone_article_uids = $wpdb->get_results( "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = 'plone_article_UID'", OBJECT_K );
		$plone_article_uids = array_map( fn( $row ) => $row->post_id, $plone_article_uids );

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			if ( array_key_exists( $row['UID'], $plone_article_uids ) ) {
				echo WP_CLI::colorize( "%wArticle already imported (Post ID: {$plone_article_uids[ $row['UID'] ]}). Skipping...%n\n" );
				continue;
			}

			$post_date_string     = $row['effective'] ?? $row['created'] ?? '1970-01-01T00:00:00+00:00';
			$post_modified_string = $row['modified'] ?? '1970-01-01T00:00:00+00:00';
			$post_date            = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_date_string, $timezone_obj );
			$post_modified        = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_modified_string, $timezone_obj );

			$post_data                = [
				'post_category' => [],
				'meta_input'    => [],
			];
			$post_data['post_title']  = $row['title'];
			$post_data['post_status'] = 'public' === $row['review_state'] ? 'publish' : 'draft';
			$post_data['post_date']   = $post_date->format( 'Y-m-d H:i:s' );
			$post_date->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_date_gmt'] = $post_date->format( 'Y-m-d H:i:s' );
			$post_data['post_modified'] = $post_modified->format( 'Y-m-d H:i:s' );
			$post_modified->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_modified_gmt'] = $post_modified->format( 'Y-m-d H:i:s' );

			$main_categories      = array_intersect( $row['subjects'], $main_categories );
			$remaining_categories = array_diff( $row['subjects'], $main_categories );

			$main_category_id = 0;

			if ( count( $main_categories ) >= 1 ) {
				$main_category_id             = wp_create_category( array_shift( $main_categories ) );
				$post_data['post_category'][] = $main_category_id;
			}

			foreach ( $remaining_categories as $category ) {
				$category_id                  = wp_create_category( $category, $main_category_id );
				$post_data['post_category'][] = $category_id;
			}

			// Author Section.
			$author_id = 0;

			if ( ! empty( $row['creators'] ) ) {
				$author_by_login = get_user_by( 'login', $row['creators'][0] );

				if ( $author_by_login instanceof WP_User ) {
					$author_id = $author_by_login->ID;
				}
			}

			$post_data['post_author'] = $author_id;

			$post_data['meta_input']['newspack_post_subtitle'] = $row['subheadline'];
			$post_data['meta_input']['plone_article_UID']      = $row['UID'];

			$post_content = '';
			if ( ! empty( $row['intro'] ) ) {
				$intro = htmlspecialchars( $row['intro'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$intro = $this->replace_unicode_chars( $intro );
				$intro = utf8_decode( $intro );
				$intro = html_entity_decode( $intro, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );

				$dom           = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( $intro );
				foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						$this->remove_attributes( $child );
					}
				}
				$post_content .= $this->inner_html( $dom->lastChild->firstChild );
			}

			if ( ! empty( $row['text'] ) ) {
				$text = htmlspecialchars( $row['text'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = $this->replace_unicode_chars( $text );
				$text = utf8_decode( $text );
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = trim( $text );

				if ( ! empty( $text ) ) {
					$dom           = new DOMDocument();
					$dom->encoding = 'utf-8';
					@$dom->loadHTML( $text );
					foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
						if ( $child instanceof DOMElement ) {
							$this->remove_attributes( $child );
						}
					}

					$script_tags = $dom->getElementsByTagName( 'script' );

					foreach ( $script_tags as $script_tag ) {
						$script_tag->nodeValue = '';
					}

					$img_tags = $dom->getElementsByTagName( 'img' );
					foreach ( $img_tags as $img_tag ) {
						/* @var DOMElement $img_tag */
						$src         = $img_tag->getAttribute( 'src' );
						$uid         = str_replace( 'resolveuid/', '', $src );
						$first_slash = strpos( $uid, '/' );
						if ( is_numeric( $first_slash ) ) {
							$uid = substr( $uid, 0, $first_slash );
						}
						echo WP_CLI::colorize( "%BImage SRC: $src - UID: $uid%n\n" );
						$attachment_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_image_UID' AND meta_value = '$uid'" );

						if ( $attachment_id ) {
							$attachment_url = wp_get_attachment_url( $attachment_id );
							echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
							$img_tag->setAttribute( 'src', $attachment_url );
						} else {
							$filename = trim( basename( $src ) );
							echo WP_CLI::colorize( "%BImage filename: $filename%n\n" );
							$attachment_id = $wpdb->get_var(
								"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = '%$filename'"
							);

							if ( $attachment_id ) {
								$attachment_url = wp_get_attachment_url( $attachment_id );
								echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
								$img_tag->setAttribute( 'src', $attachment_url );
							} else {
								echo WP_CLI::colorize( "%yImage not found...%n\n" );
							}
						}
					}

					$post_content .= $this->inner_html( $dom->lastChild->firstChild );
				}
			}

			$first_thousand_post_content_chars = substr( $post_content, 0, 1000 );

			// handle featured image.
			if ( ! empty( $row['image'] ) ) {
				$filename_without_extension = pathinfo( $row['image']['filename'], PATHINFO_FILENAME );
				if ( str_ends_with( $filename_without_extension, '-thumb' ) ) {
					$filename_without_extension = str_replace( '-thumb', '', $filename_without_extension );
				}

				$attachment_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id 
						FROM $wpdb->postmeta 
						WHERE meta_key = '_wp_attached_file' 
						  AND meta_value LIKE %s
						LIMIT 1",
						'%' . $filename_without_extension . '%'
					)
				);

				if ( $attachment_id ) {
					$post_data['meta_input']['_thumbnail_id'] = $attachment_id;

					if ( str_contains( $first_thousand_post_content_chars, $filename_without_extension ) ) {
						$post_data['meta_input']['newspack_featured_image_position'] = 'hidden';
					}
				}
			} else {
				echo WP_CLI::colorize( "%yNo featured image...%n\n" );

				// regex for finding img HTML element in string.
				$regex = '/<img[^>]+>/i';
				preg_match_all( $regex, $first_thousand_post_content_chars, $matches );
				$images = $matches[0];

				if ( isset( $images[0] ) ) { // If there is an image in the first thousand chars.
					// regex for finding src attribute in img HTML element.
					$regex = '/src="([^"]*)"/i';
					preg_match( $regex, $images[0], $matches );
					$image_src     = $matches[1];
					$uploads_url   = wp_upload_dir()['baseurl'];
					$image_src     = str_replace( $uploads_url, '', $image_src );
					$attachment_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT post_id 
							FROM $wpdb->postmeta 
							WHERE meta_key = '_wp_attached_file' 
							  AND meta_value = %s
							LIMIT 1",
							$image_src
						)
					);

					if ( $attachment_id ) {
						$post_data['meta_input']['_thumbnail_id']                    = $attachment_id;
						$post_data['meta_input']['newspack_featured_image_position'] = 'hidden';
					}
				}
			}


			$util    = new \Newspack_Scraper_Migrator_Util();
			$html    = $util->newspack_scraper_migrator_get_raw_html( $row['@id'] );
			$crawler = new Crawler( $html );
			$crawler->clear();
			$crawler->add( $html );
			$background_image_url = '';
			if ( $crawler->filter( 'article header img' )->count() ) {
				$background_image_url = $crawler->filter( 'article header img' )->attr( 'src' );
				echo WP_CLI::colorize( '%wFound background image: ' . $background_image_url . '%n' ) . "\n";
			}

			$photo_credit = '';

			if ( $crawler->filter( 'article header .photo-credit' )->count() ) {
				$photo_credit = $crawler->filter( 'article header .photo-credit' )->html();
			}

			if ( ! empty( $background_image_url ) ) {
				if ( str_ends_with( $background_image_url, '/image' ) ) {
					$background_image_url = str_replace( '/image', '', $background_image_url );
				}

				$image_post_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_image_url' AND meta_value LIKE '$background_image_url%' LIMIT 1" );

				if ( $image_post_id ) {
					$post_data['meta_input']['_thumbnail_id']                    = $image_post_id;
					$post_data['meta_input']['newspack_featured_image_position'] = 'above';
					echo WP_CLI::colorize( "%cBackground image found in DB, set as featured image above title (attachment_id: {$image_post_id})...%n\n" );
				} else {
					echo WP_CLI::colorize( '%wBackground image not found in DB, attempting to download...%n' ) . "\n";
					$response = wp_remote_get( $background_image_url );

					if ( ! is_wp_error( $response ) && 200 === $response['response']['code'] ) {
						$result = $this->attachments->import_external_file( $background_image_url, null, $photo_credit );

						if ( ! is_wp_error( $result ) ) {
							$post_data['meta_input']['_thumbnail_id']                    = $result;
							$post_data['meta_input']['newspack_featured_image_position'] = 'above';
							echo WP_CLI::colorize( '%cBackground image successfully set as background featured image!%n' ) . "\n";
						}
					} else {
						echo WP_CLI::colorize( '%r' . $response->get_error_message() . '%n' ) . "\n";
					}
				}
			}

			$image_ids_from_tree = $article_image_tree[ $row['UID'] ] ?? [];
			$gallery_images_ids  = array_diff( $image_ids_from_tree, $row['references']['isReferencing'] ?? [] );

			if ( ! empty( $gallery_images_ids ) ) {

				$imploded_uids  = "'" . implode( "','", $gallery_images_ids ) . "'";
				$attachment_ids = $wpdb->get_col(
					"SELECT post_id 
					FROM $wpdb->postmeta 
					WHERE meta_key = 'plone_image_UID' AND meta_value IN ($imploded_uids)",
				);

				if ( ! empty( $attachment_ids ) ) {
					$imploded_attachment_ids           = implode( ',', $attachment_ids );
					$attachment_ids_grouped_by_gallery = $wpdb->get_results(
						"SELECT meta_value, GROUP_CONCAT( post_id ) as attachment_ids 
						FROM {$wpdb->postmeta} 
						WHERE meta_key = 'plone_gallery' AND post_id IN ({$imploded_attachment_ids}) 
						GROUP BY meta_value"
					);

					if ( ! empty( $attachment_ids_grouped_by_gallery ) ) {
						foreach ( $attachment_ids_grouped_by_gallery as $gallery_attachment_ids ) {
							$search       = '[GALLERY:' . $gallery_attachment_ids->meta_value . ']';
							$replacement  = serialize_blocks(
								[
									$this->block_generator->get_jetpack_slideshow( explode( ',', $gallery_attachment_ids->attachment_ids ) )
								]
							);
							$post_content = str_replace( $search, $replacement, $post_content );
						}
					} else {
						echo WP_CLI::colorize( '%wCould not find corresponding attachment rows in postmeta table...%n' ) . "\n";
					}
				} else {
					echo WP_CLI::colorize( '%wNo attachment found for this row...%n' ) . "\n";
				}
			} else {
				echo WP_CLI::colorize( '%wNo gallery images found for this row...%n' ) . "\n";
			}

			$post_data['post_content'] = $post_content;
			$result                    = wp_insert_post( $post_data );

			if ( ! is_wp_error( $result ) ) {
				echo WP_CLI::colorize( "%gPost successfully created, Post ID: $result%n\n" );

				// handle redirects.
				foreach ( $row['aliases'] as $alias ) {
					$old_url = str_replace( '/hcn/hcn/', 'https://hcn.org/', $alias );
					$new_url = get_post_permalink( $result );
					$this->redirection->create_redirection_rule(
						"$result-{$row['id']}",
						$old_url,
						$new_url
					);
				}

				if ( ! empty( $row['author'] ) ) {
					$guest_author_names      = explode( ' ', $row['author'] );
					$guest_author_last_name  = array_pop( $guest_author_names );
					$guest_author_first_name = implode( ' ', $guest_author_names );
					$guest_author_id         = $this->coauthorsplus_logic->create_guest_author(
						[
							'display_name' => $row['author'],
							'first_name'   => $guest_author_first_name,
							'last_name'    => $guest_author_last_name,
						]
					);

					if ( ! is_array( $guest_author_id ) ) {
						$guest_author_id = [ intval( $guest_author_id ) ];
					}

					$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_id, $result );
				}
			} else {
				echo WP_CLI::colorize( "%rError creating post: {$result->get_error_message()}%n\n" );
			}
		}
	}

	/**
	 * Replaces unicode characters with their ASCII equivalents.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private function replace_unicode_chars( string $string ): string {
		return strtr(
			$string,
			[
				'“' => '"',
				'”' => '"',
				'‘' => "'",
				'’' => "'",
				'…' => '...',
				'―' => '-',
				'—' => '-',
				'–' => '-',
				' ' => ' ',
			]
		);
	}

	/**
	 * This function will recursively remove all attributes from all elements in the DOM tree,
	 * save for a few exceptions: src, href, title, alt, target.
	 *
	 * @param DOMElement $element
	 * @param $level
	 *
	 * @return void
	 */
	private function remove_attributes( DOMElement $element, $level = "\t" ) {
		if ( 'blockquote' === $element->nodeName ) {
			$class = $element->getAttribute( 'class' );
			if ( str_contains( $class, 'instagram-media' ) ) {
				return;
			}
		}

		$attribute_names = [];
		foreach ( $element->attributes as $attribute ) {
			$attribute_names[] = $attribute->name;
		}

		foreach ( $attribute_names as $attribute_name ) {
			if ( ! in_array( $attribute_name, [ 'src', 'href', 'title', 'alt', 'target' ] ) ) {
				$element->removeAttribute( $attribute_name );
			}
		}

		foreach ( $element->childNodes as $child ) {
			$level .= "\t";

			if ( $child instanceof DOMElement ) {
				$this->remove_attributes( $child, $level );
			}
		}
	}

	/**
	 * Convenience method to get the inner HTML of a particular HTML tag.
	 *
	 * @param DOMElement $element
	 *
	 * @return string
	 */
	private function inner_html( DOMElement $element ) {
		$inner_html = '';

		$doc = $element->ownerDocument;

		foreach ( $element->childNodes as $node ) {

			if ( $node instanceof DOMElement ) {
				if ( $node->childNodes->length > 1 && ! in_array( $node->nodeName, [ 'a', 'em', 'strong' ] ) ) {
					$inner_html .= $this->inner_html( $node );
				} else if ( 'a' === $node->nodeName ) {
					$html = $doc->saveHTML( $node );

					if ( $node->previousSibling && '#text' === $node->previousSibling->nodeName ) {
						$html = " $html";
					}

					if ( $node->nextSibling && '#text' === $node->nextSibling->nodeName ) {
						$text_content    = trim( $node->nextSibling->textContent );
						$first_character = substr( $text_content, 0, 1 );

						if ( ! in_array( $first_character, [ '.', ':' ] ) ) {
							$html = "$html ";
						}
					}

					$inner_html .= $html;
				} else {
					$inner_html .= $doc->saveHTML( $node );
				}
			} else {

				if ( '#text' === $node->nodeName ) {
					$text_content = $node->textContent;

					if ( $node->previousSibling && 'a' == $node->previousSibling->nodeName ) {
						$text_content = ltrim( $text_content );
					}

					if ( $node->nextSibling && 'a' == $node->nextSibling->nodeName ) {
						$text_content = rtrim( $text_content );
					}

					// If this text is surrounded on both ends by links, probably doesn't need any page breaks in between text
					// Also removing page breaks if the parent element is a <p> tag
					if (
						( $node->previousSibling && $node->nextSibling && 'a' == $node->previousSibling->nodeName && 'a' == $node->nextSibling->nodeName ) ||
						'p' === $element->nodeName
					) {
						$text_content = preg_replace( "/\s+/", " ", $text_content );
					}

					$inner_html .= $text_content;
				} else {
					$inner_html .= $doc->saveHTML( $node );
				}
			}
		}

		if ( 'p' === $element->nodeName && ! empty( $inner_html ) ) {
			if ( $element->hasAttributes() && 'post-aside' === $element->getAttribute( 'class' ) ) {
				return '<p class="post-aside">' . $inner_html . '</p>';
			}

			return '<p>' . $inner_html . '</p>';
		}

		return $inner_html;
	}
}