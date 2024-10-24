<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\WordPressXMLHandler;
use stdClass;
use WP_CLI;
use WP_Error;

/**
 * Class TheParkRecordMigrator
 */
class TheParkRecordMigrator implements InterfaceCommand {

	const GUEST_AUTHOR_SCRAPE_META = 'newspack_guest_author_scrape_done';
	const ORIGINAL_POST_ID_META    = 'original_post_id';
	const IMAGE_SCRAPED_POST_META  = 'newspack_image_scraped';
	const API_URL_POST             = 'https://www.parkrecord.com/wp-json/wp/v2/posts';
	const API_URL_MEDIA            = 'https://www.parkrecord.com/wp-json/wp/v2/media';

	/**
	 * TheParkRecordMigrator Instance.
	 *
	 * @var TheParkRecordMigrator
	 */
	private static $instance;

	/**
	 * CoAuthorsPlusHelper instance.
	 *
	 * @var CoAuthorsPlusHelper $co_author_plus
	 */
	private CoAuthorsPlusHelper $co_author_plus;

	/**
	 * DOMDocument instance.
	 *
	 * @var DOMDocument $dom
	 */
	private DOMDocument $dom;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments $attachments
	 */
	private Attachments $attachments;

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
			self::$instance                 = new $class();
			self::$instance->co_author_plus = new CoAuthorsPlusHelper();
			self::$instance->dom            = new DOMDocument();
			libxml_use_internal_errors( true );

			self::$instance->attachments = new Attachments();
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

		WP_CLI::add_command(
			'newspack-content-migrator the-park-record-handle-missing-import-posts',
			[ $this, 'cmd_missing_import_posts' ],
			[
				'shortdesc' => 'The XML import discarded some posts. This command will attempt to handle them',
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

		WP_CLI::add_command(
			'newspack-content-migrator the-park-record-scrape-attachment-images',
			[ $this, 'cmd_scrape_attachment_images' ],
			[
				'shortdesc' => 'Loops through all attachment rows in the DB and downloads the images locally.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'credentials',
						'description' => 'Application username and password.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator the-park-record-fix-broken-images',
			[ $this, 'cmd_fix_broken_images' ],
			[
				'shortdesc' => 'Fixes broken images from a pre-processed list of broken images.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'broken-images-file-path',
						'description' => 'The path to the file containing the broken images.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Custom command to scrape guest authors from The Park Record's current site.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
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

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$posts_channel_children          = $rss->childNodes->item( 1 )->childNodes;
		$count_of_posts_channel_children = count( $posts_channel_children );

		foreach ( $posts_channel_children as $index => $child ) {
			ConsoleColor::white( $index + 1 . '/' . $count_of_posts_channel_children )
						->bright_white(
							round( ( ( $index + 1 ) / $count_of_posts_channel_children ) * 100, 2 ) . '%%'
						)->output();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'item' === $child->nodeName ) {
				$post = WordPressXMLHandler::get_parsed_data( $child, [] )['post'];

				$console = ConsoleColor::white( 'Processing Post ID: ' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$new_post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %d",
						'original_post_id',
						$post['ID']
					)
				);

				if ( ! empty( $new_post_id ) && $new_post_id !== $post['ID'] ) {
					$console->cyan( $post['ID'] )->bright_white( '→' );
				} else {
					$new_post_id = $post['ID'];
				}

				echo "\n";
				$console->underlined_cyan( $new_post_id )->output();

				if ( array_key_exists( $new_post_id, $processed_ids ) ) {
					ConsoleColor::white( "Post ID {$new_post_id} already processed." )->output();
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
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'no_url' );
					continue;
				}

				ConsoleColor::white( 'Scraping URL:' )->cyan( $url )->output();
				$response = wp_remote_get( $url );

				$response_code = wp_remote_retrieve_response_code( $response );

				if ( 200 !== $response_code ) {
					ConsoleColor::red( "Error fetching URL. Status Code ({$response_code})" )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_fetching_url' );
					continue;
				}

				$body = wp_remote_retrieve_body( $response );

				if ( is_wp_error( $body ) ) {
					ConsoleColor::red( 'Error fetching URL.' )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_fetching_url' );
					continue;
				}

				$html = new DOMDocument();
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- I need this to run a first pass, then I will check for errors.
				@$html->loadHTML( $body );

				$author = $this->find_address_tag( $html );

				if ( false === $author ) {
					$author = $this->find_byline_tag( $html );
				}

				if ( false === $author ) {
					ConsoleColor::yellow( 'No address nor byline found.' )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'no_address' );
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

				$db_post     = get_post( $new_post_id );
				$post_author = get_user_by( 'id', $db_post->post_author );

				// If we just have a name, add the author as a coauthor.
				if ( empty( $author->email ) ) {
					$maybe_guest_author_id = $this->co_author_plus->create_guest_author( [ 'display_name' => $author->name ] );

					if ( is_wp_error( $maybe_guest_author_id ) && 'duplicate-field' === $maybe_guest_author_id->get_error_code() ) {
						$maybe_guest_author_id = $this->co_author_plus->coauthors_plus->get_coauthor_by( 'user_login', sanitize_title( $author->name ) );

						if ( ! is_wp_error( $maybe_guest_author_id ) ) {
							$maybe_guest_author_id = $maybe_guest_author_id->ID;
						}
					}

					if ( is_wp_error( $maybe_guest_author_id ) ) {
						ConsoleColor::red( 'Error creating guest author:' )->underlined_red( $maybe_guest_author_id->get_error_message() )->output();
						update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_creating_guest_author' );
						continue;
					}

					$this->co_author_plus->coauthors_plus->add_coauthors( $new_post_id, [ $maybe_guest_author_id ], false, 'id' );

					$author_terms = wp_get_post_terms( $new_post_id, 'author', [ 'fields' => 'names' ] );

					if ( 1 !== count( $author_terms ) ) {
						ConsoleColor::red( 'Error adding guest author.' )->output();
						update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_adding_guest_author' );
						continue;
					}

					ConsoleColor::green( 'Guest Author Added:' )->bright_green( $author_terms[0] )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'guest_author_created' );
					continue;
				}

				// If we have an email, and it matches the post, just check the display name to see if it needs updating.
				if ( $author->email === $post_author->user_email ) {
					if ( $author->name === $post_author->display_name ) {
						ConsoleColor::green( 'Author already set.' )->output();
						update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'author_already_set' );
						continue;
					}

					$update = wp_update_user(
						[
							'ID'           => $post_author->ID,
							'display_name' => $author->name,
							'user_pass'    => '',
						]
					);

					if ( is_wp_error( $update ) ) {
						ConsoleColor::red( 'Error updating author.' )->output();
						update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_updating_author_display_name_1' );
						continue;
					}

					ConsoleColor::green( 'Only display name update required:' )->bright_green( $author->name )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'author_updated' );
				} else {
					// if we have an email, and it doesn't match the post, then we need to see if user exists.
					$user = get_user_by( 'email', $author->email );

					$user_login = substr( $author->email, 0, strpos( $author->email, '@' ) );

					if ( ! $user ) {
						$user = get_user_by( 'login', $user_login );
					}

					// if the user doesn't exist, create the user and update the post author.
					if ( ! $user ) {
						$maybe_user_id = wp_insert_user(
							[
								'user_email'   => $author->email,
								'user_login'   => $user_login,
								'user_pass'    => wp_generate_password(),
								'display_name' => $author->name,
							]
						);

						if ( is_wp_error( $maybe_user_id ) ) {
							ConsoleColor::red( 'Error creating user:' )->underlined_red( $maybe_user_id->get_error_message() )->output();
							update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_creating_user_2' );
							continue;
						}

						$user = get_user_by( 'id', $maybe_user_id );
					} else { // phpcs:ignore Universal.ControlStructures.DisallowLonelyIf.Found -- I don't want to potentially immediately update a user that's just been properly created.
						// If the user exists, check if the display name needs updating.
						if ( $author->name !== $user->display_name ) {
							$update = wp_update_user(
								[
									'ID'           => $user->ID,
									'display_name' => $author->name,
									'user_pass'    => '',
								]
							);

							if ( is_wp_error( $update ) ) {
								ConsoleColor::red( 'Error updating author:' )->underlined_red( $update->get_error_message() )->output();
								update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_updating_author_display_name_2' );
								continue;
							}

							ConsoleColor::green( 'Author Display Name Updated:' )->bright_green( $user->display_name )->output();
						}
					}

					// if the user exists, and the display name is the same, update the post author.
					$update = wp_update_post(
						[
							'ID'          => $new_post_id,
							'post_author' => $user->ID,
						]
					);

					if ( is_wp_error( $update ) ) {
						ConsoleColor::red( 'Error updating author.' )->output();
						update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'error_updating_post_author' );
						continue;
					}

					ConsoleColor::green( 'Post Author Updated:' )->bright_green( $user->display_name )->output();
					update_post_meta( $new_post_id, self::GUEST_AUTHOR_SCRAPE_META, 'post_author_updated' );
				}
			}
		}
	}

	/**
	 * This function is necessary to handle a situation created by the standard WordPress XML importer where some
	 * posts are not imported because there is a post with the same title and post_date already.
	 *
	 * @param array $args The positional arguments.
	 * @param array $assoc_args The associative arguments.
	 *
	 * @return void
	 */
	public function cmd_missing_import_posts( array $args, array $assoc_args ): void {
		$xml_path = $assoc_args['xml-path'];

		$xml = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$posts_channel_children          = $rss->childNodes->item( 1 )->childNodes;
		$count_of_posts_channel_children = count( $posts_channel_children );

		global $wpdb;

		$post_columns         = [
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_content_filtered',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
			'comment_count',
		];
		$flipped_post_columns = array_flip( $post_columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$authors_by_login = $wpdb->get_results(
			"SELECT user_login, ID FROM $wpdb->users",
			OBJECT_K
		);

		foreach ( $posts_channel_children as $index => $child ) {
			ConsoleColor::white( $index + 1 . '/' . $count_of_posts_channel_children )
						->bright_white(
							round( ( ( $index + 1 ) / $count_of_posts_channel_children ) * 100, 2 ) . '%%'
						)->output();
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'item' === $child->nodeName ) {
				$post = WordPressXMLHandler::get_parsed_data( $child, $authors_by_login )['post'];
				unset( $post['guid'] );

				$console = ConsoleColor::white( 'Post ID:' )->bright_white( $post['ID'] );

				if ( 'draft' === $post['post_status'] ) {
					$console->white( 'Post Status:' )->bright_yellow( 'DRAFT' )->output();
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$imported_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT p.post_name, p.post_title, p.post_date, pm.meta_id, pm.post_id as post_meta_post_id FROM $wpdb->posts p INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %d",
						self::ORIGINAL_POST_ID_META,
						$post['ID']
					)
				);

				if ( $imported_post ) {
					$checks    = [
						'post_name'  => $imported_post->post_name === $post['post_name'],
						'post_title' => $imported_post->post_title === $post['post_title'],
						'post_date'  => $imported_post->post_date === $post['post_date'],
					];
					$check_sum = array_reduce(
						$checks,
						fn( $carry, $item ) => $carry && $item,
						true
					);

					if ( $check_sum ) {
						$console->green( 'OK' )->output();
						continue;
					}

					// HERE we need to handle importing the post, and updating the incorrect meta.
					// Does Post ID already exist?
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$post_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT ID FROM $wpdb->posts WHERE ID = %d",
							$post['ID']
						)
					);

					if ( $post_id ) {
						unset( $post['ID'] );
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$maybe_inserted = $wpdb->insert(
						$wpdb->posts,
						array_intersect_key( $post, $flipped_post_columns )
					);

					if ( ! $maybe_inserted ) {
						$console->red( 'Error importing post 1:' )->output();
						continue;
					}

					$inserted_post_id = $wpdb->insert_id;

					$console->green( 'Post imported:' )->bright_green( $inserted_post_id )->output();

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->postmeta,
						[
							'post_id' => $inserted_post_id,
						],
						[
							'meta_id' => $imported_post->meta_id,
						]
					);

					foreach ( $post['meta'] as $meta ) {
						add_post_meta( $inserted_post_id, $meta['meta_key'], $meta['meta_value'] );
					}

					// Afterwards we should continue.
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$imported_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT ID, post_name, post_title, post_date FROM $wpdb->posts WHERE post_title = %s AND post_date = %s",
						$post['post_title'],
						$post['post_date']
					)
				);

				if ( $imported_post ) {
					$console->yellow( 'Post already exists:' )->bright_yellow( $imported_post->ID )->output();
					add_post_meta( $imported_post->ID, self::ORIGINAL_POST_ID_META, $post['ID'] );
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT ID FROM $wpdb->posts WHERE ID = %d",
						$post['ID']
					)
				);

				if ( $post_id ) {
					unset( $post['ID'] );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$maybe_inserted = $wpdb->insert(
					$wpdb->posts,
					array_intersect_key( $post, $flipped_post_columns )
				);

				if ( ! $maybe_inserted ) {
					$console->red( 'Error importing post 2' )->output();
					continue;
				}

				$inserted_post_id = $wpdb->insert_id;

				$console->green( 'Post imported 2:' )->bright_green( $inserted_post_id )->output();
				add_post_meta( $inserted_post_id, self::ORIGINAL_POST_ID_META, $post['ID'] );

				foreach ( $post['meta'] as $meta ) {
					add_post_meta( $inserted_post_id, $meta['meta_key'], $meta['meta_value'] );
				}
			}
		}
	}

	/**
	 * Downloads attachment images locally.
	 *
	 * This is necessary because the images are hosted on S3, and we don't have direct access to them.
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function cmd_scrape_attachment_images( array $args ): void {
		global $wpdb;

		$credentials = $args[0];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
						p.ID as post_id,
						pm.meta_value as original_post_id
					FROM $wpdb->posts p
						LEFT JOIN $wpdb->postmeta pm
							ON p.ID = pm.post_id
					WHERE p.post_type = 'attachment'
					  AND p.post_mime_type LIKE %s
					  AND pm.meta_key = %s
					  AND pm.post_id NOT IN (
						  SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s
					  )",
				$wpdb->esc_like( 'image/' ) . '%',
				self::ORIGINAL_POST_ID_META,
				self::IMAGE_SCRAPED_POST_META
			)
		);

		foreach ( $attachments as $attachment ) {
			echo "\n";
			$original_post_id = $attachment->original_post_id ?? $attachment->post_id;

			$console = ConsoleColor::white( 'Processing Attachment ID:' )->bright_white( $attachment->post_id );

			if ( $original_post_id !== $attachment->post_id ) {
				$console->white( '→' )->underlined_white( $original_post_id );
			}

			$console->output();

			$maybe_attachment_data = $this->get_attachment_info_from_source( $original_post_id, $credentials );

			if ( is_wp_error( $maybe_attachment_data ) ) {
				ConsoleColor::red( "Error fetching source URL. {$maybe_attachment_data->get_error_message()}" )->output();
				continue;
			}

			$local_file_path = wp_upload_dir()['basedir'] . '/' . $maybe_attachment_data['uploads_path'];

			if ( file_exists( $local_file_path ) ) {
				ConsoleColor::green( 'File already exists.' )
							->white( $maybe_attachment_data['full_size_source_url'] )
							->underlined_bright_white( $local_file_path )
							->output();
				update_post_meta( $attachment->post_id, self::IMAGE_SCRAPED_POST_META, 'already_downloaded' );
				update_post_meta( $attachment->post_id, self::IMAGE_SCRAPED_POST_META . ':path', $local_file_path );
				continue;
			}

			if ( empty( $maybe_attachment_data['full_size_source_url'] ) ) {
				ConsoleColor::yellow( 'No source URL found.' )->output();
				$maybe_attachment_data['full_size_source_url'] = 'https://www.parkrecord.com/wp-content/uploads/sites/11/' . $maybe_attachment_data['uploads_path'];
			}

			$maybe_downloaded_file_path = download_url( $maybe_attachment_data['full_size_source_url'] );

			if ( is_wp_error( $maybe_downloaded_file_path ) ) {
				ConsoleColor::red( "Error downloading file. {$maybe_downloaded_file_path->get_error_message()}" )->output();
				continue;
			}

			ConsoleColor::white( 'Attempting to move' )
						->cyan( $maybe_downloaded_file_path )
						->white( 'to' )
						->underlined_bright_cyan( $local_file_path )
						->output();
			$maybe_moved = rename( $maybe_downloaded_file_path, $local_file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- global $wp_filesytem returns null?

			if ( ! $maybe_moved ) {
				ConsoleColor::red( 'Error moving file.' )->output();
				continue;
			}

			ConsoleColor::green( 'File downloaded and moved.' )->output();
			update_post_meta( $attachment->post_id, self::IMAGE_SCRAPED_POST_META, 'downloaded' );
		}
	}

	/**
	 * This command uses a pre-processed list of posts with URLs pointing to images that don't exist (broken images).
	 * With that list, this command will look at each post_id & image_url and attempt to find that image in the DB.
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 * @throws Exception When the file is not found.
	 */
	public function cmd_fix_broken_images( array $args ): void {
		$broken_images_file_path = $args[0];

		$previous_post_id = 0;

		global $wpdb;

		foreach ( ( new FileImportFactory() )->get_file( $broken_images_file_path )->getIterator() as $row ) {
			echo "\n";
			$post_id   = intval( $row['post_id'] );
			$image_url = $row['image_url'];

			if ( $post_id !== $previous_post_id ) { // Want to keep the output of which Post ID we're processing clean.
				echo "\n";
				ConsoleColor::white( '----------------------------------------' )->output();
				$console          = ConsoleColor::cyan( 'Processing Post ID:' )->bright_cyan( $post_id );
				$previous_post_id = $post_id;

				$original_post_id = $this->get_original_post_id( $post_id );

				if ( $original_post_id !== $post_id ) {
					$console->white( '→' )->underlined_bright_cyan( $original_post_id )->output();
				} else {
					$console->output();
				}
			}

			ConsoleColor::white( 'Post Image URL:' )->bright_white( $image_url )->output();
			$filename = basename( $image_url );
			ConsoleColor::white( 'Filename:' )->bright_white( $filename )->output();
			$decoded_filename  = esc_sql( sanitize_file_name( rawurlencode( $filename ) ) );
			$decoded_image_url = str_replace( $filename, $decoded_filename, $image_url );

			ConsoleColor::bright_blue( 'Near Exact Match' )->output();
			$search_file_path    = $this->convert_to_local_path( $decoded_image_url );
			$search_console      = ConsoleColor::white( 'Path:' )->bright_white( $search_file_path )->white( 'Filename:' )->bright_white( $decoded_filename );
			$maybe_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $this->convert_to_local_path( $decoded_image_url ), $decoded_filename );

			if ( null === $maybe_attachment_id ) {
				$search_console->white( '❌' )->output();

				ConsoleColor::bright_blue( 'Size Suffix Removed' )->output();
				$size_suffix_removed_filename  = preg_replace( '/-\d+x\d+\./', '.', $decoded_filename );
				$size_suffix_removed_image_url = preg_replace( '/-\d+x\d+\./', '.', $decoded_image_url );
				$search_file_path              = $this->convert_to_local_path( $size_suffix_removed_image_url );
				$search_console                = ConsoleColor::white( 'Path:' )->bright_white( $search_file_path )->white( 'Filename:' )->bright_white( $size_suffix_removed_filename );
				$maybe_attachment_id           = $this->attachments->maybe_get_existing_attachment_id( $search_file_path, $size_suffix_removed_filename );
			}

			if ( null === $maybe_attachment_id ) {
				$search_console->white( '❌' )->output();

				ConsoleColor::bright_blue( 'Scaled Filename' )->output();
				$just_filename       = preg_replace( '/(-\d+x\d+)$/', '', pathinfo( $decoded_filename, PATHINFO_FILENAME ) );
				$just_extension      = pathinfo( $decoded_filename, PATHINFO_EXTENSION );
				$scaled_filename     = $just_filename . '-scaled.' . $just_extension;
				$scaled_image_url    = str_replace( $decoded_filename, $scaled_filename, $decoded_image_url );
				$search_file_path    = $this->convert_to_local_path( $scaled_image_url );
				$search_console      = ConsoleColor::white( 'Path:' )->bright_white( $search_file_path )->white( 'Filename:' )->bright_white( $scaled_filename );
				$maybe_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $search_file_path, $scaled_filename );
			}

			if ( null === $maybe_attachment_id ) {
				$search_console->white( '❌' )->output();

				ConsoleColor::bright_blue( 'Regexp Search' )->output();
				$just_filename       = pathinfo( $decoded_filename, PATHINFO_FILENAME );
				$just_extension      = pathinfo( $decoded_filename, PATHINFO_EXTENSION );
				$regexp_filename     = preg_replace( '/(-scaled\d+)|(-\d+x\d+)/', '', $just_filename ) . '.' . $just_extension;
				$regexp_path         = str_replace( $decoded_filename, $regexp_filename, $this->convert_to_local_path( $decoded_image_url ) );
				$regexp_partial_path = str_replace( trailingslashit( wp_upload_dir()['basedir'] ), '', $regexp_path );
				$regexp_partial_path = str_replace( ".$just_extension", '', $regexp_partial_path );
				$reg_exp             = "$regexp_partial_path(.+)?\.$just_extension";
				$search_console      = ConsoleColor::white( 'Path:' )->bright_white( $regexp_partial_path )->white( 'Filename:' )->bright_white( $regexp_filename );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$maybe_attachment_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT p.ID 
							FROM $wpdb->posts p 
							    INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id 
            				WHERE p.post_type = 'attachment' 
            				  AND pm.meta_key = '_wp_attached_file' 
            				  AND pm.meta_value REGEXP %s",
						$reg_exp
					)
				);
			}

			if ( null === $maybe_attachment_id || 0 === $maybe_attachment_id ) {
				$search_console->white( '❌' )->output();
				ConsoleColor::red( 'No attachment found.' )->output();
				continue;
			}

			if ( is_int( $maybe_attachment_id ) ) {
				$search_console->white( '✅' )->green( 'Attachment ID:' )->bright_green( $maybe_attachment_id )->output();
				$attachment_url = wp_get_attachment_url( $maybe_attachment_id );

				preg_match( '/(-\d+x\d+)$/', pathinfo( $decoded_filename, PATHINFO_FILENAME ), $matches );

				if ( ! empty( $matches ) ) {
					$size_suffix               = $matches[0];
					$attachment_filename       = basename( $attachment_url );
					$attachment_file_extension = pathinfo( $attachment_filename, PATHINFO_EXTENSION );
					$attachment_url            = str_replace( ".{$attachment_file_extension}", $size_suffix . '.' . $attachment_file_extension, $attachment_url );
				}

				if ( str_contains( $image_url, 'sites/11' ) ) {
					$image_url = str_replace( 'sites/11/', '', $image_url );
				}

				$console = ConsoleColor::green( 'Attachment URL:' )->bright_green( $attachment_url );
				if ( $image_url === $attachment_url ) {
					$console->bright_white( 'No change needed.' )->output();
					continue;
				}

				$console->output();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$replaced = $wpdb->query(
					$wpdb->prepare(
						"UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s) WHERE ID = %d",
						$image_url,
						$attachment_url,
						$row['post_id']
					)
				);

				if ( ! $replaced ) {
					ConsoleColor::red( 'Error replacing image URL.' )->output();
					ConsoleColor::white( $wpdb->last_query )->output();
				} else {
					ConsoleColor::green( 'Image URL replaced.' )->output();
				}
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
	 * Retrieves the full size source URL for an attachment.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $credentials The credentials to use for the request.
	 *
	 * @return array|WP_Error
	 */
	public function get_attachment_info_from_source( int $attachment_id, string $credentials = '' ): array|WP_Error {
		$args = [];

		if ( ! empty( $credentials ) ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$attachment = json_decode( wp_remote_retrieve_body( wp_remote_get( self::API_URL_MEDIA . '/' . $attachment_id, $args ) ), true );

		if ( empty( $attachment ) ) {
			return new WP_Error( 'empty_response', 'No attachment data returned.' );
		}

		if ( isset( $attachment['data']['status'] ) && $attachment['data']['status'] >= 400 ) {
			return new WP_Error( 'bad_response', 'Bad response from API.' );
		}

		return [
			'wp_url'               => $attachment['guid']['rendered'],
			'uploads_path'         => $attachment['media_details']['file'], // '2021/06/file.jpg'
			'full_size_source_url' => $attachment['media_details']['sizes']['full']['source_url'] ?? '',
		];
	}

	/**
	 * Gets the originak post ID.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int
	 */
	public function get_original_post_id( int $post_id ): int {
		$original_post_id = get_post_meta( $post_id, self::ORIGINAL_POST_ID_META, true );

		if ( empty( $original_post_id ) ) {
			return $post_id;
		}

		return intval( $original_post_id );
	}

	/**
	 * Converts the image URL to a local path.
	 *
	 * @param string $image_url The image URL.
	 *
	 * @return string
	 */
	public function convert_to_local_path( string $image_url ): string {
		$path = wp_parse_url( $image_url, PHP_URL_PATH );

		return $this->get_home_path() . str_replace( 'sites/11/', '', $path );
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
		$author->original = $address->ownerDocument->saveHTML( $address ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$author->source   = 'address';
		$author->name     = '';
		$author->email    = '';

		foreach ( $address->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '#text' === $child->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$text_content = trim( $child->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				if ( is_email( $text_content ) ) {
					$author->email = $text_content;
				} elseif ( empty( $author->name ) ) {
						$author->name = $text_content;
				} else {
					$author->name .= " $text_content";
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
		$author->original = $byline->ownerDocument->saveHTML( $byline ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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

	/**
	 * Get the home path.
	 *
	 * @return string
	 */
	private function get_home_path(): string {
		return str_replace( '/wp-content/uploads', '', wp_upload_dir()['basedir'] );
	}
}
