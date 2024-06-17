<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use Symfony\Component\DomCrawler\Crawler;
use WP_CLI;

/**
 * Custom migration scripts for Enterprise.
 */
class EnterpriseMigrator implements InterfaceCommand {
	const ORIGINAL_ID_META_KEY    = '_newspack_original_id';
	const STORY_ISSUE_ORIGINAL_ID = '_newspack_story_issue_original_id';

	const DEFAULT_AUTHOR = [
		'display_name' => 'Enterprise Staff',
		'email'        => 'staff@enterprise.news',
	];

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * JsonIterator instance.
	 *
	 * @var JsonIterator
	 */
	private $json_iterator;

	/**
	 * Instance of Attachments logic.
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * Crawler instance.
	 *
	 * @var Crawler Crawler instance.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger                    = new Logger();
		$this->json_iterator             = new JsonIterator();
		$this->attachments               = new Attachments();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
		$this->crawler                   = new Crawler();
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
			'newspack-content-migrator enterprise-migrate-content',
			[ $this, 'cmd_enterprise_migrate_content' ],
			[
				'shortdesc' => 'Searches all asset JSONs and finds equivalent already imported attachment post_ids. Accuracy of this should be high but it is expected that it is not perfect, HTML galleries for QA and verification of results are produced.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'parsing-issue-json-file',
						'description' => 'The path to the JSON file containing parsing issues (ParsingIssue.json).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-json-file',
						'description' => 'The path to the JSON file containing stories (Story.json).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-tag-json-file',
						'description' => 'The path to the JSON file containing stories tags (StoryTag.json).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'language',
						'description' => 'The language of the content to be migrated (1: Arabic, 2: English). Default: 2.',
						'default'     => 2,
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'brand-id',
						'description' => 'The brand ID of the content to be migrated.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-content',
						'description' => 'Refresh content if it already exists.',
						'default'     => false,
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator enterprise-migrate-content`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_enterprise_migrate_content( $args, $assoc_args ) {
		$log_file = 'cmd_enterprise_migrate_content.log';

		$parsing_issue_json_file = $assoc_args['parsing-issue-json-file'];
		$story_json_file         = $assoc_args['story-json-file'];
		$story_tag_json_file     = $assoc_args['story-tag-json-file'];
		$language                = $assoc_args['language'];
		$brand_id                = $assoc_args['brand-id'];
		$refresh_content         = isset( $assoc_args['refresh-content'] ) ? true : false;

		$author_id = $this->get_unique_author_id();

		if ( is_wp_error( $author_id ) ) {
			$this->logger->log( $log_file, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );
			return false;
		}

		if ( ! in_array( $language, [ 1, 2 ] ) ) {
			$this->logger->log( $log_file, 'Invalid language. Please provide a valid language (1: Arabic, 2: English).', Logger::ERROR );
			return;
		}

		$existing_original_ids = $this->get_existing_original_ids();

		$total_stories = $this->json_iterator->count_json_array_entries( $story_json_file );
		$counter       = 0;

		foreach ( $this->json_iterator->items( $story_json_file ) as $story ) {
			++$counter;
			$this->logger->log( $log_file, "Processing story {$counter} of {$total_stories}...", Logger::INFO );

			if ( $language !== $story->LanguageId ) {
				continue;
			}

			if ( $story->WebExclude ) {
				$this->logger->log( $log_file, "Story with ID {$story->StoryId} is excluded from the web. Skipping.", Logger::INFO );
				continue;
			}

			if ( empty( $story->StoryContent ) ) {
				$this->logger->log( $log_file, "Story with ID {$story->StoryId} has no content. Skipping.", Logger::WARNING );
				continue;
			}

			// Skip the story if it's already imported and we're not refreshing content.
			if ( in_array( $story->StoryId, $existing_original_ids ) && ! $refresh_content ) {
				$this->logger->log( $log_file, "Story with ID {$story->StoryId} already imported. Skipping.", Logger::INFO );
				continue;
			}

			$wp_story_post_id = $this->migrate_story( $story, $brand_id, $author_id, $log_file );
			$this->logger->log( $log_file, "Migrated story with ID {$story->StoryId} to post ID {$wp_story_post_id}.", Logger::SUCCESS );
		}
	}

	/**
	 * Migrate story.
	 *
	 * @param object $story Story object.
	 * @param string $brand_id Brand ID.
	 * @param string $author_id Author ID.
	 * @param string $log_file Log file.
	 * @return int|WP_Error Story post ID or WP_Error.
	 */
	private function migrate_story( $story, $brand_id, $author_id, $log_file ) {
		$post_content = $this->clean_content( $story->StoryContent );

		// Generate post.
		$post_data = [
			'post_title'   => $story->Head,
			'post_content' => $post_content,
			'post_status'  => $story->IsPublished ? 'publish' : 'draft',
			'post_date'    => $story->PublishDate->{'$date'},
			'post_author'  => $author_id,
			'post_type'    => 'post',
		];

		// Insert or get the existing post.
		$wp_post_id = $this->get_post_id_by_meta( self::ORIGINAL_ID_META_KEY, $story->StoryId );

		if ( ! $wp_post_id ) {
			$wp_post_id = wp_insert_post( $post_data );
		} else {
			$post_data['ID'] = $wp_post_id;
			wp_update_post( $post_data );
		}

		// Audio File.
		if ( ! empty( $story->VoiceFileUrl ) ) {
			$audio_file_post_id = $this->attachments->import_external_file( $story->VoiceFileUrl, null, null, null, null, $wp_post_id );

			if ( is_wp_error( $audio_file_post_id ) ) {
				$this->logger->log( $log_file, sprintf( 'Could not upload file %s: %s', $story->VoiceFileUrl, $audio_file_post_id->get_error_message() ), Logger::WARNING );
			} else {
				wp_update_post(
					[
						'ID'           => $wp_post_id,
						'post_content' => serialize_block( $this->gutenberg_block_generator->get_audio( $audio_file_post_id ) ) . $post_content,
					]
				);
			}
		}

		// Post category.
		$cateogry_id = wp_create_category( $story->SectionHead );
		wp_set_post_categories( $wp_post_id, $cateogry_id );

		// Post tags.
		wp_set_post_tags( $wp_post_id, implode( ',', $story->StoryTags ) );

		// Post subtitle.
		update_post_meta( $wp_post_id, 'newspack_post_subtitle', $story->StoryTeaser );

		// Featured image.
		if ( ! empty( $story->WebImageURL ) ) {
			$file_post_id = $this->attachments->import_external_file( $story->WebImageURL, null, null, null, null, $wp_post_id );

			if ( is_wp_error( $file_post_id ) ) {
				$this->logger->log( $log_file, sprintf( 'Could not upload file %s: %s', $story->WebImageURL, $file_post_id->get_error_message() ), Logger::WARNING );
			} else {
				set_post_thumbnail( $wp_post_id, $file_post_id );
			}
		}

		// A few meta fields.
		update_post_meta( $wp_post_id, self::ORIGINAL_ID_META_KEY, $story->StoryId );
		update_post_meta( $wp_post_id, self::STORY_ISSUE_ORIGINAL_ID, $story->IssueId );

		// Set the post brand.
		wp_set_post_terms( $wp_post_id, [ $brand_id ], 'brand' );

		return $wp_post_id;
	}

	/**
	 * Clean content.
	 *
	 * @param string $content Content to clean.
	 *
	 * @return string Cleaned content.
	 */
	private function clean_content( $content ) {
		// Remove the subscription block.
		$this->crawler->clear();
		$this->crawler->add( $content );

		// Remove the subscription block.
		$subscribe_box_panel = $this->crawler->filter( '.subscribe-box-panel' );
		if ( $subscribe_box_panel->count() > 0 ) {
			$subscribe_box_panel->each(
				function ( $node ) {
					$node->getNode( 0 )->parentNode->removeChild( $node->getNode( 0 ) );
				}
			);
		}

		return $this->crawler->html();
	}

	/**
	 * Add or return author ID by display name.
	 *
	 * @return int|WP_Error Author ID or WP_Error.
	 */
	private function get_unique_author_id() {
		$username = sanitize_user( self::DEFAULT_AUTHOR['display_name'], true );
		$author   = get_user_by( 'login', $username );

		if ( $author instanceof \WP_User ) {
			return $author->ID;
		}

		$author_id = wp_insert_user(
			[
				'display_name' => self::DEFAULT_AUTHOR['display_name'],
				'user_login'   => $username,
				// 'user_email'   => self::DEFAULT_AUTHOR['email'],
				'user_pass'    => wp_generate_password(),
				'role'         => 'author',
			]
		);

		return $author_id;
	}

		/**
		 * Get existing original IDs.
		 *
		 * @return array Existing original IDs.
		 */
	private function get_existing_original_ids() {
		global $wpdb;

		$existing_original_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s", self::ORIGINAL_ID_META_KEY ) );

		return $existing_original_ids;
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param string $meta_name Meta name.
	 * @param string $meta_value Meta value.
	 * @return int|null
	 */
	private function get_post_id_by_meta( $meta_name, $meta_value ) {
		global $wpdb;

		if ( empty( $meta_value ) ) {
			return null;
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
				$meta_name,
				$meta_value
			)
		);
	}
}
