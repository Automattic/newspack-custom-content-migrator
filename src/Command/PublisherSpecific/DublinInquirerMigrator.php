<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DOMDocument;
use Exception;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

class DublinInquirerMigrator implements InterfaceCommand {

	/**
	 * DublinInquirerMigrator Instance.
	 *
	 * @var DublinInquirerMigrator
	 */
	private static $instance;


	/**
	 * Logger instance.
	 *
	 * @var Logger|null Logger instance.
	 */
	protected ?Logger $logger;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments|null Attachments instance.
	 */
	protected ?Attachments $attachments;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus|null CoAuthorPlus instance.
	 */
	protected ?CoAuthorPlus $coauthorplus;

	/**
	 * Redirection instance.
	 *
	 * @var Redirection|null Redirection instance.
	 */
	protected ?Redirection $redirection;

	/**
	 * SimpleLocalAvatars instance.
	 *
	 * @var SimpleLocalAvatars|null SimpleLocalAvatars instance.
	 */
	protected ?SimpleLocalAvatars $simple_local_avatars;

	/**
	 * HtmlElementManipulator instance.
	 *
	 * @var HtmlElementManipulator|null HtmlElementManipulator instance.
	 */
	protected ?HtmlElementManipulator $html_element_manipulator;

	/**
	 * Get Instance.
	 *
	 * @return DublinInquirerMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance                           = new $class();
			self::$instance->logger                   = new Logger();
			self::$instance->attachments              = new Attachments();
			self::$instance->coauthorplus             = new CoAuthorPlus();
			self::$instance->simple_local_avatars     = new SimpleLocalAvatars();
			self::$instance->redirection              = new Redirection();
			self::$instance->html_element_manipulator = new HtmlElementManipulator();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-tags',
			[ $this, 'cmd_import_tags' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer tags.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-issues',
			[ $this, 'cmd_import_issues' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer tags.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-authors',
			[ $this, 'cmd_import_authors' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer authors.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-artwork',
			[ $this, 'cmd_import_artwork' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer authors.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-articles',
			[ $this, 'cmd_import_articles' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer authors.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-article-author-rels',
			[ $this, 'cmd_import_article_author_rels' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer authors.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-import-comments',
			[ $this, 'cmd_import_comments' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer comments.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start importing from this row.',
						'optional'    => true,
						'default'     => 0,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-fix-article-dates',
			[ $this, 'cmd_fix_article_dates' ],
			[
				'shortdesc' => 'Migrate Dublin Inquirer comments.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dublin-inquirer-fix-issue-as-categories',
			[ $this, 'cmd_add_issue_to_post' ],
			[
				'shortdesc' => 'Add issues as category to posts',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'import-file',
						'description' => 'CSV file with tags to import.',
						'optional'    => false,
						'default'     => false,
					],
				],
			]
		);
	}

	/**
	 * Import Dublin Inquirer Tags.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_import_tags( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-tags-import-' . gmdate( 'YmdHis' ) . '.log';

		foreach ( $iterator as $row ) {
			$this->logger->log( $log_file_name, 'Tag Slug: ' . $row['slug'] );

			$slug_exists = term_exists( $row['slug'] );

			if ( $slug_exists ) {
				$this->logger->log( $log_file_name, 'Tag Slug already exists. Skipping.' );
				continue;
			}

			$term_record = wp_insert_term( $row['name'], 'post_tag', [ 'slug' => $row['slug'] ] );
			add_option( "tag_id_{$term_record['term_id']}", $row['id'] );
		}
	}

	/**
	 * Import Dublin Inquirer Issues.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_import_issues( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-issues-import-' . gmdate( 'YmdHis' ) . '.log';

		$category_id = wp_create_category( 'Issues' );

		foreach ( $iterator as $row ) {
			$this->logger->log( $log_file_name, "Issue Date: {$row['issue_date']} Published: {$row['published']}" );

			$text_date = date( 'M jS, Y', strtotime( $row['issue_date'] ) );

			$slug        = sanitize_title( $row['issue_date'] );
			$slug_exists = term_exists( $slug );

			if ( $slug_exists ) {
				$this->logger->log( $log_file_name, 'Issue Slug already exists. Skipping.' );
				continue;
			}

			$term_record = wp_insert_term(
				$text_date,
				'category',
				[
					'parent'      => $category_id,
					'slug'        => $slug,
					'description' => 'true' === strtolower( $row['published'] ) ? 'Published' : 'Unpublished',
				]
			);
			add_option( "issue_id_{$term_record['term_id']}", $row['id'] );
		}
	}

	/**
	 * Import Dublin Inquirer authors.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_import_authors( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-authors-import-' . gmdate( 'YmdHis' ) . '.log';

		$authors_map = [];

		$separators = [
			' and ',
			' & ',
			',',
		];

		foreach ( $iterator as $row ) {
			$authors_map[ $row['id'] ] = [];
			$this->logger->log( $log_file_name, "Author: {$row['full_name']} | Slug: {$row['slug']}" );

			/*
			foreach ( $separators as $separator ) {
				if ( str_contains( $row['full_name'], $separator ) ) {
					$this->logger->log( $log_file_name, "Multiple authors found. Separator: {$separator}" );
					$names = explode( $separator, $row['full_name'] );

					foreach ( $names as $key => $name ) {
						$name = trim( $name );

						$description = substr( $row['bio'], stripos( $row['bio'], $name ) );

						if ( isset( $names[ $key + 1 ] ) ) { // If there is another name in the list.
							$description = substr(
								$row['bio'],
								stripos( $row['bio'], $name ),
								strpos( $row['bio'], $names[ $key + 1 ] )
							);
						}

						$ids = $this->handle_author( $name, sanitize_title( $name ), $row['created_at'], $log_file_name, $description );

						if ( ! empty( $ids ) ) {
							$authors_map[ $row['id'] ][] = $ids;
						}
					}
					continue 2;
				}
			}*/
			$names = $this->handle_extracting_author_names( $row['full_name'], [] );

			if ( ! empty( $names ) ) {
				$this->logger->log( $log_file_name, 'Multiple authors found:' . count( $names ) );

				foreach ( $names as $key => $name ) {
					if ( empty( $name ) ) {
						continue;
					}

					$description = substr( $row['bio'], stripos( $row['bio'], $name ) );

					if ( isset( $names[ $key + 1 ] ) ) { // If there is another name in the list.
						$description = substr(
							$row['bio'],
							stripos( $row['bio'], $name ),
							strpos( $row['bio'], $names[ $key + 1 ] )
						);
					}

					$ids = $this->handle_author( $name, sanitize_title( $name ), $row['created_at'], $log_file_name, $description );

					if ( ! empty( $ids ) ) {
						$authors_map[ $row['id'] ][] = $ids;
					}
				}

				continue;
			}

			$authors_map[ $row['id'] ] = $this->handle_author(
				$row['full_name'],
				$row['slug'],
				$row['created_at'],
				$log_file_name,
				$row['bio']
			);
		}

		file_put_contents( 'authors-map.json', wp_json_encode( $authors_map ) );
	}

	/**
	 * Import Dublin Inquirer artwork.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_import_artwork( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-artwork-import-' . gmdate( 'YmdHis' ) . '.log';

		foreach ( $iterator as $row ) {
			global $wpdb;

			$attachment_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = %s",
					$row['hashed_id']
				)
			);

			if ( is_numeric( $attachment_id ) ) {
				$this->logger->log( $log_file_name, "Artwork already exists: {$row['hashed_id']}" );
				continue;
			}

			$url = "https://dgrkrqgws56a8.cloudfront.net/artwork/{$row['hashed_id']}/{$row['image']}";
			$this->logger->log( $log_file_name, "Importing: {$url}" );
			$attachment_id = $this->attachments->import_external_file( $url, $row['hashed_id'], $row['caption'] );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( $log_file_name, "Error importing: {$attachment_id->get_error_message()}" );
				continue;
			}

			$created_at = date( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) );
			$updated_at = date( 'Y-m-d H:i:s', strtotime( $row['updated_at'] ) );

			$wpdb->update(
				$wpdb->posts,
				[
					'post_date'         => $created_at,
					'post_date_gmt'     => $created_at,
					'post_modified'     => $updated_at,
					'post_modified_gmt' => $updated_at,
				],
				[
					'ID' => $attachment_id,
				]
			);

			add_post_meta( $attachment_id, 'original_artwork_id', $row['id'] );
			// TODO do we need to set image sizes?
		}
	}

	public function cmd_import_articles( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-authors-import-' . gmdate( 'YmdHis' ) . '.log';

		global $wpdb;

		foreach ( $iterator as $row ) {
			$original_article_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_article_id' AND meta_value = %s",
					$row['id']
				)
			);

			if ( is_numeric( $original_article_id ) ) {
				$this->logger->log( $log_file_name, "Article already exists: {$row['title']}" );
				continue;
			}

			$article_data = [];
			$this->logger->log( $log_file_name, "Importing: {$row['title']}" );

			$post_content = $row['content'];
			if ( ! empty( $row['content'] ) ) {
				$dom           = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( utf8_decode( htmlentities( $post_content ) ) );
				$post_content = html_entity_decode( $dom->saveHTML( $dom->documentElement->firstChild->firstChild ) );
				$artwork_tags = $this->html_element_manipulator->match_elements_with_closing_tags( 'artwork', $post_content );

				if ( is_array( $artwork_tags ) ) {
					foreach ( $artwork_tags[0] as $artwork_tag ) {
						// regex for finding html attribute content called id
						$artwork_id = $this->html_element_manipulator->get_attribute_value( 'id', $artwork_tag[0] );

						if ( empty( $artwork_id ) ) {
							$this->logger->log( $log_file_name, 'Artwork import error: ' . wp_json_encode( $artwork_tag ) );
							continue;
						}

						$image_html = $this->get_image_html_by_artwork_id( $artwork_id, $log_file_name );

						if ( ! is_null( $image_html ) ) {
							$post_content = str_replace( $artwork_tag[0], $image_html, $post_content );
						}
					}
				}
			}

			$slug_parts = explode( '/', $row['slug'] );
			$post_name  = array_pop( $slug_parts );

			$created_at = date( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) );
			$updated_at = date( 'Y-m-d H:i:s', strtotime( $row['updated_at'] ) );

			$thumbnail_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_artwork_id' AND meta_value = %d",
					(int) $row['featured_artwork_id']
				)
			);

			if ( ! is_null( $thumbnail_id ) ) {
				$article_data['meta_input']['_thumbnail_id'] = $thumbnail_id;
			}

			$category_name   = $row['category'];
			$category_name   = str_replace( '-', ' ', $category_name );
			$category_name   = ucwords( $category_name );
			$category_exists = term_exists( $row['category'], 'category' );

			if ( is_null( $category_exists ) ) {
				$category_exists = wp_insert_term( $category_name, 'category', [ 'slug' => $row['category'] ] );
			}

			if ( ! is_wp_error( $category_exists ) ) {
				$article_data['post_category'] = [ $category_exists['term_id'] ];
			}
			$article_data['post_title']                        = $row['title'];
			$article_data['post_content']                      = $post_content;
			$article_data['post_name']                         = $post_name;
			$article_data['post_excerpt']                      = $row['excerpt'];
			$article_data['post_date']                         = $created_at;
			$article_data['post_date_gmt']                     = $created_at;
			$article_data['post_modified']                     = $updated_at;
			$article_data['post_modified_gmt']                 = $updated_at;
			$article_data['post_status']                       = 'publish';
			$article_data['post_type']                         = 'post';
			$article_data['meta_input']['original_article_id'] = $row['id'];

			$post_id = wp_insert_post( $article_data );

			if ( is_wp_error( $post_id ) ) {
				$this->logger->log( $log_file_name, "Article Import Error: {$post_id->get_error_message()}" );
				continue;
			}

			// Add category to post
			wp_set_post_terms( $post_id, $category_exists['term_id'], 'category', true );

			$original_tag_ids = str_replace( [ '{', '}' ], '', $row['tag_ids'] );
			$original_tag_ids = explode( ',', $original_tag_ids );
			$original_tag_ids = array_filter( $original_tag_ids, fn( $id ) => is_numeric( $id ) );

			if ( ! empty( $original_tag_ids ) ) {
				$tags = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT 
       					option_name 
					FROM $wpdb->options 
					WHERE option_name LIKE 'tag_id_%' 
					  AND option_value IN (" . implode( ',', $original_tag_ids ) . ')'
					)
				);
				$tags = array_map(
					function ( $tag ) {
						return intval( str_replace( 'tag_id_', '', $tag->option_name ) );
					},
					$tags
				);

				wp_set_post_terms( $post_id, $tags, 'post_tag', true );
			}

			$former_slugs = str_replace( [ '{', '}' ], '', $row['former_slugs'] );
			$former_slugs = explode( ',', $former_slugs );

			foreach ( $former_slugs as $former_slug ) {

				$this->redirection->create_redirection_rule(
					$row['title'],
					"http://dublininquirer.com{$former_slug}",
					get_permalink( $post_id )
				);
			}
		}
	}

	public function cmd_add_issue_to_post( $args, $assoc_args) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-issues-as-categories-fix-' . gmdate( 'YmdHis' ) . '.log';

		global $wpdb;

		foreach ( $iterator as $row ) {
			$this->logger->log( $log_file_name, "Processing {$row['id']}, Issue ID: {$row['issue_id']}" );
			$option = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name 
					FROM $wpdb->options 
					WHERE option_value = %s AND option_name LIKE 'issue_id_%'",
					$row['issue_id']
				)
			);

			if ( $option ) {
				$this->logger->log( $log_file_name, "Found option {$option}" );
				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_article_id' AND meta_value = %s",
						$row['id']
					)
				);

				if ( $post_id ) {
					$this->logger->log( $log_file_name, "Found post {$post_id}" );
					$term_id = intval( str_replace( 'issue_id_', '', $option ) );
					wp_set_post_terms( $post_id, $term_id, 'category', true );
				}
			}
		}
	}

	public function cmd_import_article_author_rels( $args, $assoc_args ) {
		$authors_map = (array) wp_json_file_decode( 'authors-map.json' );

		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		$log_file_name = 'dublin-inquirer-article-authors-rels-import-' . gmdate( 'YmdHis' ) . '.log';

		global $wpdb;

		foreach ( $iterator as $row ) {
			$this->logger->log( $log_file_name, "Processing article: {$row['article_id']} - Author: {$row['author_id']}" );

			if ( $row['article_id'] <= 3778 ) {
				$this->logger->log( $log_file_name, "Article skipped: {$row['article_id']}" );
				continue;
			}

			$post = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT 
       						ID, 
       						post_author 
						FROM $wpdb->posts p 
						    INNER JOIN $wpdb->postmeta pm ON pm.post_id = p.ID 
						WHERE pm.meta_key = 'original_article_id' 
						  AND pm.meta_value = %s",
					$row['article_id']
				)
			);

			if ( is_null( $post ) ) {
				$this->logger->log( $log_file_name, "Article not found: {$row['article_id']}" );
				continue;
			}

			$author_ids = $authors_map[ $row['author_id'] ] ?? null;

			if ( is_null( $author_ids ) ) {
				$this->logger->log( $log_file_name, "Author not found: {$row['author_id']}" );
				continue;
			}

			if ( 0 === $post->post_author ) {
				$wpdb->update(
					$wpdb->posts,
					[
						'post_author' => $author_ids[0]->user_id,
					],
					[
						'ID' => $post->ID,
					]
				);
			}

			foreach ( $author_ids as $author_id ) {
				$this->coauthorplus->assign_guest_authors_to_post( [ $author_id->guest_author_id ], $post->ID, true );
			}
		}
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_comments( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->set_start( $start )
			->getIterator();

		$log_file_name = 'dublin-inquirer-article-authors-rels-import-' . gmdate( 'YmdHis' ) . '.log';

		global $wpdb;

		foreach ( $iterator as $row ) {
			$this->logger->log( $log_file_name, "Processing comment: {$row['id']}" );

			if ( $row['article_id'] <= 3778 ) {
				$this->logger->log( $log_file_name, "Comment skipped due to article_id: {$row['article_id']}" );
				continue;
			}

			/*
			if ( 'approved' !== $row['status'] ) {
				$this->logger->log( $log_file_name, "Comment not approved: {$row['id']}" );
				continue;
			}*/

			if ( ! empty( $row['user_id'] ) ) {
				$this->logger->log( $log_file_name, "Comment has user id: {$row['id']}" );
				continue;
			}

			$comment_date = date( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) );

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts p 
						INNER JOIN $wpdb->postmeta pm ON pm.post_id = p.ID 
					WHERE pm.meta_key = 'original_article_id' 
						AND pm.meta_value = %s",
					$row['article_id']
				)
			);

			if ( is_null( $post_id ) ) {
				$this->logger->log( $log_file_name, "Post not found: {$row['article_id']}" );
				continue;
			}

			$parent_comment_id = 0;
			if ( ! empty( $row['parent_id'] ) ) {
				$parent_comment_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 
       						cm.comment_ID 
						FROM $wpdb->comments c 
						    INNER JOIN $wpdb->commentmeta cm ON cm.comment_id = c.comment_ID 
						WHERE cm.meta_key = 'original_comment_id' 
						  AND cm.meta_value = %d",
						intval( $row['parent_id'] )
					)
				);
			}

			$content = $row['content'];
			if ( ! empty( $content ) ) {
				$dom           = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( utf8_decode( htmlentities( $content ) ) );
				$content = html_entity_decode( $dom->saveHTML( $dom->documentElement->firstChild->firstChild ) );
			}

			wp_insert_comment(
				[
					'comment_approved'     => 'approved' === $row['status'] ? 1 : 0,
					'comment_author'       => $row['nickname'],
					'comment_author_email' => $row['email_address'],
					'comment_content'      => $content,
					'comment_date'         => $comment_date,
					'comment_date_gmt'     => $comment_date,
					'comment_parent'       => $parent_comment_id,
					'comment_post_ID'      => $post_id,
					'comment_meta'         => [
						'original_comment_id' => $row['id'],
						'original_article_id' => $row['article_id'],
						'original_parent_id'  => intval( $row['parent_id'] ),
						'original_user_email' => $row['email_address'],
						'original_status'     => $row['status'],
						'original_created_at' => $row['created_at'],
						'marked_as_spam'      => $row['marked_as_spam'],
					],
				]
			);
		}
	}

	public function cmd_fix_article_dates( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )
			->get_file( $file_path )
			->getIterator();

		foreach ( $iterator as $row ) {
			$slug_date = '0';
			$slug_parts = explode( '/', $row['slug'] );
			if ( ! array_key_exists( 3, $slug_parts ) ) {
				WP_CLI::log( "SLUG NOT VALID: " . $row['slug'] );
			} else {
				$slug_date  = $slug_parts[1] . '-' . $slug_parts[2] . '-' . $slug_parts[3];
			}

			$post_date     = $row['created_at'];
			WP_CLI::log( 'Original date: ' . $post_date );
			$new_post_date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $post_date, new \DateTimeZone( 'GMT' ) );
			if ( is_bool( $new_post_date ) ) {
				$new_post_date = \DateTime::createFromFormat( 'Y-m-d H:i:s.u', $post_date, new \DateTimeZone( 'GMT' ) );
				if ( is_bool( $new_post_date ) ) {
					WP_CLI::warning('Date not valid: ' . $post_date);
					continue;
				}
			}
			WP_CLI::log( 'Original date: ' . $post_date . ' New date: ' . $new_post_date->format( 'Y-m-d H:i:s' ) . ' Slug Date: ' . $slug_date );

			if ( ! is_bool( \DateTime::createFromFormat( 'Y-m-d', $slug_date ) ) && $slug_date !== $new_post_date->format( 'Y-m-d' ) ) {
				WP_CLI::warning( 'Slug date does not match post date, updating...' );
				$new_post_date->setDate( intval( $slug_parts[1] ), intval( $slug_parts[2] ), intval( $slug_parts[3] ) );
			}

			$post_modified_date = $row['updated_at'];
			WP_CLI::log( 'Original modified date: ' . $post_modified_date );
			$new_post_modified_date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $post_modified_date, new \DateTimeZone( 'GMT' ) );
			if ( is_bool( $new_post_modified_date ) ) {
				$new_post_modified_date = \DateTime::createFromFormat( 'Y-m-d H:i:s.u', $post_modified_date, new \DateTimeZone( 'GMT' ) );
				if ( is_bool( $new_post_modified_date ) ) {
					WP_CLI::warning('Date not valid: ' . $post_modified_date);
					continue;
				}
			}
			WP_CLI::log( 'Original modified date: ' . $post_modified_date . ' New modified date: ' . $new_post_modified_date->format( 'Y-m-d H:i:s' ) );

			global $wpdb;

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 
       						post_id 
					FROM $wpdb->postmeta 
					WHERE meta_key = 'original_article_id' 
					  AND meta_value = %d",
					$row['id']
				)
			);

			if ( is_null( $post_id ) ) {
				WP_CLI::warning('Post not found: ' . $row['id']);
				continue;
			}

			$wpdb->update(
				$wpdb->posts,
				[
					'post_date'     => $new_post_date->format( 'Y-m-d H:i:s' ),
					'post_modified' => $new_post_modified_date->format( 'Y-m-d H:i:s' ),
				],
				[
					'ID' => $post_id,
				]
			);
		}
	}

	/**
	 * Get image html by artwork id.
	 *
	 * @param string $artwork_id Artwork id.
	 * @param string $log_file_name Log file name.
	 *
	 * @return string|null
	 */
	private function get_image_html_by_artwork_id( string $artwork_id, string $log_file_name ) {
		global $wpdb;

		$attachment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_excerpt, guid FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = %s",
				$artwork_id
			)
		);

		if ( is_null( $attachment ) ) {
			$this->logger->log( $log_file_name, "Artwork not found: {$artwork_id}" );
			return null;
		}

		return $this->get_image_html( $attachment );
	}

	private function get_image_html( \stdClass $attachment ) {
		$image_html = '<figure><img src="{url}"/>{caption}</figure>';
		$caption    = '<figcaption>{caption_text}</figcaption>';

		if ( ! empty( $attachment->post_excerpt ) ) {
			$caption = strtr(
				$caption,
				[
					'{caption_text}' => $attachment->post_excerpt,
				]
			);
		} else {
			$caption = '';
		}

		return strtr(
			$image_html,
			[
				'{url}'     => $attachment->guid,
				'{caption}' => $caption,
			]
		);
	}

	private function handle_extracting_author_names( string $name, array $names ) {
		$separators = [
			' and ',
			' & ',
			',',
		];

		foreach ( $separators as $separator ) {
			if ( str_contains( $name, $separator ) ) {
				$exploded = explode( $separator, $name );
				foreach ( $exploded as $particle ) {
					$extracted_names = $this->handle_extracting_author_names( trim( $particle ), $names );
					$names           = array_merge( $names, $extracted_names );
				}

				return $names;
			}
		}

		return [ $name ];
	}

	/**
	 * Handle author creation.
	 *
	 * @param string $name Name of the author.
	 * @param string $slug Slug of the author.
	 * @param string $created_at Date the author was created.
	 * @param string $log_file_name Name of the log file.
	 * @param string $description Bio.
	 *
	 * @return array
	 */
	private function handle_author( string $name, string $slug, string $created_at, string $log_file_name, string $description = '' ): array {
		$user = username_exists( $slug );

		if ( false !== $user ) {
			$this->logger->log( $log_file_name, "User {$name} {$slug} already exists. Skipping." );
			$guest_author = $this->coauthorplus->get_or_create_guest_author_from_user( $user );

			return [
				'user_id'         => $user,
				'guest_author_id' => $guest_author->ID,
			];
		}

		$name_parts = explode( ' ', $name );
		$last_name  = array_pop( $name_parts );
		$first_name = implode( ' ', $name_parts );

		$user_registered = date( 'Y-m-d H:i:s', strtotime( $created_at ) );

		$user_id = wp_insert_user(
			[
				'user_pass'       => wp_generate_password( 12, true ),
				'user_login'      => $slug,
				'user_nicename'   => $slug,
				'display_name'    => $name,
				'first_name'      => $first_name,
				'last_name'       => $last_name,
				'description'     => $description,
				'user_registered' => $user_registered,
				'role'            => 'author',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			$this->logger->log( $log_file_name, "Error {$user_id->get_error_message()}." );
			return [];
		}

		$this->logger->log( $log_file_name, "New User ID: {$user_id}" );

		$avatar_url    = "https://dgrkrqgws56a8.cloudfront.net/portraits/{$slug}/image.jpg";
		$attachment_id = $this->attachments->import_external_file( $avatar_url, $slug, $name, "$name's Avatar" );

		if ( ! is_wp_error( $attachment_id ) ) {
			$this->simple_local_avatars->import_avatar( $user_id, $attachment_id );
		}

		$guest_author_id = $this->coauthorplus->create_guest_author_from_wp_user( $user_id );

		return [
			'user_id'         => $user_id,
			'guest_author_id' => $guest_author_id,
		];
	}
}
