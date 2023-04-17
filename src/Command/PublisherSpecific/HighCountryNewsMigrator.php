<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use \WP_CLI;

class HighCountryNewsMigrator implements InterfaceCommand {

	/**
	 * HighCountryNewsMigrator Instance.
	 *
	 * @var HighCountryNewsMigrator
	 */
	private static $instance;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Redirection $redirection
	 */
	private $redirection;

	/**
	 * Get Instance.
	 *
	 * @return HighCountryNewsMigrator
	 */
	public static function get_instance(): HighCountryNewsMigrator {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
			self::$instance->coauthorsplus_logic = new CoAuthorPlus();
			self::$instance->redirection = new Redirection();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-authors-from-scrape',
			[ $this, 'cmd_migrate_authors_from_scrape' ],
			[
				'shortdesc' => 'Authors will not be properly linked after importing XMLs. This script will set authors based on saved postmeta.',
			]
		);

		// Need to import Authors/Users
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-users-from-json',
			[ $this, 'cmd_migrate_users_from_json' ],
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
				],
			]
		);

		// Then images
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-images-from-json',
			[ $this, 'cmd_migrate_images_from_json' ],
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
			'newspack-content-migrator highcountrynews-migrate-issues-from-json',
			[ $this, 'cmd_migrate_issues_from_json' ],
			[
				'shortdesc' => 'Migrate issues from JSON data.',
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

		// Then tags, Topics?
		// Then posts

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-articles-from-json',
			[ $this, 'cmd_migrate_articles_from_json' ],
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
				],
			]
		);
	}

	public function cmd_migrate_authors_from_scrape() {
		$last_processed_post_id = PHP_INT_MAX;

		if ( file_exists( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' ) ) {
			$last_processed_post_id = (int) file_get_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' );
		}

		global $wpdb;

		$posts_and_authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'plone_author' AND post_id < %d ORDER BY post_id DESC",
				$last_processed_post_id
			)
		);

		foreach ( $posts_and_authors as $record ) {
			WP_CLI::log( "Processing post ID {$record->post_id} ($record->meta_value)..." );
			$author_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->users WHERE display_name = %s",
					$record->meta_value
				)
			);

			if ( $author_id ) {
				WP_CLI::log( "Author ID: $author_id" );
				$wpdb->update(
					$wpdb->posts,
					[ 'post_author' => $author_id ],
					[ 'ID' => $record->post_id ]
				);
			} else {
				WP_CLI::log( 'Author not found.' );
			}

			file_put_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt', $record->post_id );
		}
	}

	/**
	 * Function to process users from a Plone JSON users file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_users_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			WP_CLI::log( 'Row Number: ' . $row_number . ' - ' . $row['username'] );

			$date_created = new DateTime( 'now', new DateTimeZone( 'America/Denver' ) );

			if ( ! empty( $row['date_created'] ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row['date_created'], new DateTimeZone( 'America/Denver' ) );
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
	 * @throws Exception
	 */
	public function cmd_migrate_images_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		$creators = [];

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

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

			$created_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['created'], new DateTimeZone( 'America/Denver' ) );
			$updated_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['modified'], new DateTimeZone( 'America/Denver' ) );

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
				$file_blob_path        = WP_CONTENT_DIR . '/high_country_news/blobs/' . $row['image']['blob_path'];
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
					update_post_meta( $result, 'UID', $row['UID'] );
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

					update_post_meta( $attachment_id, 'UID', $row['UID'] );
				}
			} else {
				echo WP_CLI::colorize( '%rNo image found for this row...' ) . "\n";
			}
		}
	}

	/**
	 * Migrate publication issues from JSON file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_issues_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		$parent_category_id = wp_create_category( 'Issues' );

		foreach ( $iterator as $row_number => $row ) {
			$description = '';

			if ( ! empty( $row['title'] ) ) {
				$description .= $row['title'] . "\n\n";
			}

			$description .= $row['description'] . "\n\n";
			$description .= 'Volume: ' . $row['publicationVolume'] . "\n";
			$description .= 'Issue: ' . $row['publicationIssue'] . "\n";

			$publication_date = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $row['publicationDate'] );

			if ( $publication_date instanceof DateTime ) {
				$description .= 'Date: ' . $publication_date->format( 'l, F jS, Y' ) . "\n";
			}

			wp_insert_category(
				[
					'taxonomy'             => 'category',
					'cat_name'             => $row['id'],
					'category_description' => $description,
					'category_nicename'    => $row['title'],
					'category_parent'      => $parent_category_id,
				]
			);
		}
	}

	/**
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_articles_from_json( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		// Need to create some additional parent categories based off of live site.
		$main_categories = [
			'Features',
			'Public Lands',
			'Indigenous Affairs',
			'Water',
			'Climate Change',
			'Wildfire',
			'Arts & Culture',
		];

		foreach ( $main_categories as $category ) {
			wp_create_category( $category );
		}

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			$post_date_string = $row['effective'] ?? $row['created'] ?? '1970-01-01T00:00:00+00:00';
			$post_modified_string = $row['modified'] ?? '1970-01-01T00:00:00+00:00';
			$post_date     = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_date_string, new DateTimeZone( 'America/Denver' ) );
			$post_modified = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_modified_string, new DateTimeZone( 'America/Denver' ) );

			$post_data               = [
				'post_category' => [],
				'meta_input'    => [],
			];
			$post_data['post_title'] = $row['title'];
			$post_data['post_status'] = 'public' === $row['review_state'] ? 'publish' : 'draft';
			$post_data['post_date']  = $post_date->format( 'Y-m-d H:i:s' );
			$post_date->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_date_gmt'] = $post_date->format( 'Y-m-d H:i:s' );
			$post_data['post_modified'] = $post_modified->format( 'Y-m-d H:i:s' );
			$post_modified->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_modified_gmt'] = $post_modified->format( 'Y-m-d H:i:s' );

			if ( str_contains( $row['@id'], '/issues/' ) ) {
				$issue_category_id = get_cat_ID( 'Issues' );

				if ( 0 !== $issue_category_id ) {
					$post_data['post_category'][] = $issue_category_id;
				}

				$issues_position = strpos( $row['@id'], '/issues/' ) + 8;
				$issue_number    = substr( $row['@id'], $issues_position, strpos( $row['@id'], '/', $issues_position ) - $issues_position );

				$issue_number_category_id = get_cat_ID( $issue_number );

				if ( 0 !== $issue_number_category_id ) {
					$post_data['post_category'][] = $issue_number_category_id;
				}
			} else {
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
			$post_data['meta_input']['UID'] = $row['UID'];

			$post_content = '';

			if ( ! empty( $row['intro'] ) ) {
				$intro = htmlspecialchars( $row['intro'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$intro = $this->replace_weird_chars( $intro );
				$intro = utf8_decode( $intro );
				$intro = html_entity_decode( $intro, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );

				$dom = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( $intro );
//				var_dump([$dom->childNodes, $dom->firstChild, $dom->firstChild->childNodes, $dom->lastChild]);die();
				foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						$this->remove_attributes( $child );
					}
				}
				$post_content .= $this->inner_html( $dom->lastChild->firstChild );
			}

			if ( ! empty( $row['text'] ) ) {
				$text = htmlspecialchars( $row['text'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = $this->replace_weird_chars( $text );
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
//					var_dump( [ 'tag' => $script_tag->ownerDocument->saveHTML( $script_tag), 'name' => $script_tag->nodeName, 'parent' => $script_tag->parentNode->nodeName ] );
						$script_tag->nodeValue = '';
					}

					$img_tags = $dom->getElementsByTagName( 'img' );

					foreach ( $img_tags as $img_tag ) {
						/* @var DOMElement $img_tag */
						// $src should look like this: resolveuid/191b2acc464b44f592c547229b393b4e.
						$src         = $img_tag->getAttribute( 'src' );
						$uid         = str_replace( 'resolveuid/', '', $src );
						$first_slash = strpos( $uid, '/' );
						if ( is_numeric( $first_slash ) ) {
							$uid = substr( $uid, 0, $first_slash );
						}
						echo WP_CLI::colorize( "%BImage SRC: $src - UID: $uid%n\n" );
						$attachment_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'UID' AND meta_value = '$uid'" );

						if ( $attachment_id ) {
							$attachment_url = wp_get_attachment_url( $attachment_id );
							echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
							$img_tag->setAttribute( 'src', $attachment_url );
						} else {
							echo WP_CLI::colorize( "%yImage not found...%n\n" );
						}
					}

					$post_content .= $this->inner_html( $dom->lastChild->firstChild );
				}
			}

			$post_data['post_content'] = $post_content;

			// handle featured image.
			if ( ! empty( $row['image'] ) ) {
				$attachment_id = $wpdb->get_var(
					"SELECT post_id 
					FROM $wpdb->postmeta 
					WHERE meta_key = '_wp_attached_file' 
					  AND meta_value LIKE '%{$row['image']['filename']}' 
					LIMIT 1"
				);

				if ( $attachment_id ) {
					$post_data['meta_input']['_thumbnail_id'] = $attachment_id;
				}
			} else {
				echo WP_CLI::colorize( "%yNo featured image...%n\n" );
			}

			$result = wp_insert_post( $post_data );

			if ( ! is_wp_error( $result ) ) {
				// handle redirects.
				echo WP_CLI::colorize( "%gPost successfully created, Post ID: $result%n\n");

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
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
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
				echo WP_CLI::colorize("%rError creating post: {$result->get_error_message()}%n\n");
			}
		}
	}

	private function replace_weird_chars( $string ): string {
		return strtr(
			$string,
			[
				'“' => '"',
				'”' => '"',
				'‘' => "'",
				'’' => "'",
				'…' => '...',
			]
		);
	}

	private function remove_attributes( DOMElement $element, $level = "\t" ) {
//		echo "{$level}Removing attributes from $element->nodeName\n";

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
//			echo "{$level}Child: $child->nodeName\n";
			if ( $child instanceof DOMElement ) {
				$this->remove_attributes( $child, $level );
			}
		}
	}

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
						$text_content = trim( $node->nextSibling->textContent );
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
					){
						$text_content = preg_replace("/\s+/", " ", $text_content);
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
