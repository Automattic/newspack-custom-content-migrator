<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \WP_CLI;

class EmbarcaderoMigrator implements InterfaceCommand {
	const LOG_FILE                         = 'embarcadero_importer.log';
	const EMBARCADERO_ORIGINAL_ID_META_KEY = '_newspack_import_id';

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
			'newspack-content-migrator embarcadero-import-posts-content',
			array( $this, 'cmd_embarcadero_import_posts_content' ),
			[
				'shortdesc' => 'Import Embarcadero\s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-byline-email-file-path',
						'description' => 'Path to the CSV file containing the stories\'s bylines emails to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-sections-file-path',
						'description' => 'Path to the CSV file containing the stories\'s sections (categories) to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-import-posts-content".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_import_posts_content( $args, $assoc_args ) {
		$story_csv_file_path               = $assoc_args['story-csv-file-path'];
		$story_byline_emails_csv_file_path = $assoc_args['story-byline-email-file-path'];
		$story_sections_csv_file_path      = $assoc_args['story-sections-file-path'];

		// Validate co-authors plugin is active.
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$posts                 = $this->get_data_from_csv( $story_csv_file_path );
		$contributors          = $this->get_data_from_csv( $story_byline_emails_csv_file_path );
		$sections              = $this->get_data_from_csv( $story_sections_csv_file_path );
		$imported_original_ids = $this->get_imported_original_ids();

		// Skip already imported posts.
		$posts = array_filter(
			$posts,
			function( $post ) use ( $imported_original_ids ) {
				return ! in_array( $post['story_id'], $imported_original_ids );
			}
		);

		$layout = [];
		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$contributor_index = array_search( $post['byline'], array_column( $contributors, 'full_name' ) );

			$wp_contributor_id = null;
			if ( false !== $contributor_index ) {
				$contributor       = $contributors[ $contributor_index ];
				$wp_contributor_id = $this->get_or_create_contributor( $contributor['full_name'], $contributor['email_address'] );

				if ( is_wp_error( $wp_contributor_id ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not get or create contributor %s: %s', $contributor['full_name'], $wp_contributor_id->get_error_message() ), Logger::WARNING );
					$wp_contributor_id = null;
				}
			}

			// Migrate post content shortcodes.
			$post_content = $this->migrate_post_content_shortcodes( $post['story_text'] );

			// Get the post slug.
			$post_name = $this->migrate_post_slug( $post['seo_link'] );

			$post_data = [
				'post_title'   => $post['headline'],
				'post_content' => $post_content,
				'post_excerpt' => $post['front_paragraph'],
				'post_status'  => 'Yes' === $post['approved'] ? 'publish' : 'draft',
				'post_type'    => 'post',
				'post_date'    => gmdate( 'Y-m-d H:i:s', $post['date_epoch'] ),
				'post_date'    => gmdate( 'Y-m-d H:i:s', $post['date_updated_epoch'] ),
				'post_author'  => $wp_contributor_id,
			];

			if ( ! empty( $post_name ) ) {
				$post_data['post_name'] = $post_name;
			}

			if ( '0' !== $post['date_updated_epoch'] ) {
				$post_data['post_modified'] = gmdate( 'Y-m-d H:i:s', $post['date_updated_epoch'] );
			}

			// Create the post.
			$wp_post_id = wp_insert_post( $post_data );

			if ( is_wp_error( $wp_post_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not create post %s: %s', $post['headline'], $wp_post_id->get_error_message() ), Logger::WARNING );
				continue;
			}

			// Set the original ID.
			update_post_meta( $wp_post_id, self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			// Set the post subhead.
			update_post_meta( $wp_post_id, 'newspack_post_subtitle', $post['subhead'] );

			$updated_post_content = $post_content;

			// Add tag_2 to the post.
			if ( ! empty( $post['tag_2'] ) ) {
				$updated_post_content .= serialize_block(
					$this->gutenberg_block_generator->get_paragraph( '<em>' . $post['tag_2'] . '</em>' )
				);
			}

			// Set co-author if needed.
			if ( ! $wp_contributor_id && 'byline' === $post['byline_tag_option'] ) {
				$coauthors     = $this->get_co_authors_from_bylines( $post['byline'] );
				$co_author_ids = $this->get_generate_coauthor_ids( $coauthors );

				$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_author_ids, $wp_post_id );
				$this->logger->log( self::LOG_FILE, sprintf( 'Assigned co-authors %s to post "%s"', implode( ', ', $coauthors ), $post['headline'] ), Logger::LINE );
			}

			if ( 'tag' === $post['byline_tag_option'] ) {
				if ( ! $wp_contributor_id ) {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_paragraph( '<em>By ' . $post['byline'] . '</em>' )
					);
				} else {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_author_profile( $wp_contributor_id )
					);

					// Add "Bottom Byline" tag to the post.
					wp_set_post_tags( $wp_post_id, 'Bottom Byline', true );
				}
			}

			// Set categories from sections data.
			$post_section_index = array_search( $post['section_id'], array_column( $sections, 'section_id' ) );
			if ( false === $post_section_index ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find section %s for post %s', $post['section_id'], $post['headline'] ), Logger::WARNING );
			} else {
				$section     = $sections[ $post_section_index ];
				$category_id = $this->get_or_create_category( $section['section'] );

				if ( $category_id ) {
					wp_set_post_categories( $wp_post_id, [ $category_id ] );
				}
			}

			// A few meta fields.
			if ( 'Yes' === $post['baycities'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_baycities', true );
			}

			if ( 'Yes' === $post['calmatters'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_calmatters', true );
			}

			if ( 'Yes' === $post['council'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_council', true );
			}

			if ( ! empty( $post['layout'] ) ) {
				wp_set_post_tags( $wp_post_id, 'Layout ' . $post['layout'], true );
			}

			if ( ! empty( $post['hero_headline_size'] ) ) {
				wp_set_post_tags( $wp_post_id, 'Headline ' . $post['hero_headline_size'], true );
			}

			$this->logger->log( self::LOG_FILE, sprintf( 'Imported post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::SUCCESS );
		}
	}

	/**
	 * Get data from CSV file.
	 *
	 * @param string $story_csv_file_path Path to the CSV file containing the stories to import.
	 * @return array Array of data.
	 */
	private function get_data_from_csv( $story_csv_file_path ) {
		$data = [];

		if ( ! file_exists( $story_csv_file_path ) ) {
			$this->logger->log( self::LOG_FILE, 'File does not exist: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_file = fopen( $story_csv_file_path, 'r' );
		if ( false === $csv_file ) {
			$this->logger->log( self::LOG_FILE, 'Could not open file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_headers = fgetcsv( $csv_file );
		if ( false === $csv_headers ) {
			$this->logger->log( self::LOG_FILE, 'Could not read CSV headers from file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_headers = array_map( 'trim', $csv_headers );

		while ( ( $csv_row = fgetcsv( $csv_file ) ) !== false ) {
			$csv_row = array_map( 'trim', $csv_row );
			$csv_row = array_combine( $csv_headers, $csv_row );

			$data[] = $csv_row;
		}

		fclose( $csv_file );

		return $data;
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
				self::EMBARCADERO_ORIGINAL_ID_META_KEY
			)
		);
	}

	/**
	 * Get or create a contributor.
	 *
	 * @param string $full_name Full name of the contributor.
	 * @param string $email_address Email address of the contributor.
	 * @return int|null WP user ID.
	 */
	private function get_or_create_contributor( $full_name, $email_address ) {
		// Check if user exists.
		$wp_user = get_user_by( 'email', $email_address );
		if ( $wp_user ) {
			return $wp_user->ID;
		}

		// Create a WP user with the contributor role.
		$wp_user_id = wp_create_user( $email_address, wp_generate_password(), $email_address );
		if ( is_wp_error( $wp_user_id ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not create user %s: %s', $full_name, $wp_user_id->get_error_message() ), Logger::ERROR );
		}

		// Set the Contributor role.
		$user = new \WP_User( $wp_user_id );
		$user->set_role( 'contributor' );

		// Set WP User display name.
		wp_update_user(
			[
				'ID'           => $wp_user_id,
				'display_name' => $full_name,
			]
		);

		return $wp_user_id;
	}

	/**
	 * Migrate post content shortcodes.
	 *
	 * @param string $story_text Post content.
	 * @return string Migrated post content.
	 */
	private function migrate_post_content_shortcodes( $story_text ) {
		// Story text contains different shortcodes in the format: {shorcode meta meta ...}.
		return $story_text;
	}

	/**
	 * Get or create co-authors.
	 *
	 * @param string $byline Byline.
	 * @return array Array of co-authors.
	 */
	private function get_co_authors_from_bylines( $byline ) {
		// Split co-authors by ' and '.
		$coauthors                = explode( ' and ', $byline );
		$false_positive_coauthors = [ 'Ph.D', 'M.D.', 'DVM' ];

		foreach ( $coauthors as $coauthor ) {
			// Clean up the byline.
			$coauthor = trim( $coauthor );
			// Remove By, by prefixes.
			$coauthor = preg_replace( '/^By,? /i', '', $coauthor );
			// Split by comma.
			$coauthor_splits = array_map( 'trim', explode( ',', $coauthor ) );
			// If the split result in terms from the false positive list, undo the split.
			$skip_split = false;
			foreach ( $false_positive_coauthors as $false_positive_coauthor ) {
				if ( in_array( $false_positive_coauthor, $coauthor_splits ) ) {
					$skip_split = true;
					break;
				}
			}

			if ( ! $skip_split ) {
				$coauthors = array_merge( $coauthors, $coauthor_splits );
				unset( $coauthors[ array_search( $coauthor, $coauthors ) ] );
			}
		}

		return $coauthors;
	}

	/**
	 * Get or create co-authors.
	 *
	 * @param array $coauthors Array of co-authors.
	 * @return array Array of co-authors IDs.
	 */
	private function get_generate_coauthor_ids( $coauthors ) {
		$coauthor_ids = [];
		foreach ( $coauthors as $coauthor ) {
			// Set as a co-author.
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $coauthor );
			if ( $guest_author ) {
				$coauthor_ids[] = $guest_author->ID;
			} else {
				$coauthor_ids[] = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $coauthor ] );
			}
		}

		return $coauthor_ids;
	}

	/**
	 * Get or create a category.
	 *
	 * @param string $name Category name.
	 * @return int|null Category ID.
	 */
	private function get_or_create_category( $name ) {
		$term = get_term_by( 'name', $name, 'category' );
		if ( $term ) {
			return $term->term_id;
		}

		$term = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $term ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not create category %s: %s', $name, $term->get_error_message() ), Logger::ERROR );
			return null;
		}

		return $term['term_id'];
	}

	/**
	 * Get the post slug from the SEO link.
	 *
	 * @param string $seo_link SEO link.
	 * @return string Post slug.
	 */
	private function migrate_post_slug( $seo_link ) {
		// get the slug from the format: "2011/03/31/post-slug.
		return substr( $seo_link, strrpos( $seo_link, '/' ) + 1 );
	}
}
