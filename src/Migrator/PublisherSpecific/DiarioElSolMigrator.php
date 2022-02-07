<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Diario el Sol.
 */
class DiarioElSolMigrator implements InterfaceMigrator {
	// Logs.
	const ADMINS_LOGS     = 'DES_authors.log';
	const CATEGORIES_LOGS = 'DES_categories.log';
	const TAGS_LOGS       = 'DES_tags.log';
	const POSTS_LOGS      = 'DES_posts.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Crawler
	 */
	private $crawler;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->crawler             = new Crawler();
		$this->attachments_logic   = new AttachmentsLogic();
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
		WP_CLI::add_command(
			'newspack-content-migrator diario-el-sol-import-authors',
			array( $this, 'cmd_des_import_authors' ),
			array(
				'shortdesc' => 'Import Diario El Sol authoristrators from a JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'authors-json-path',
						'description' => 'JSON file path that contains the authoristrators to import.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator diario-el-sol-import-categories',
			array( $this, 'cmd_des_import_categories' ),
			array(
				'shortdesc' => 'Import Diario El Sol categories from a JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'categories-json-path',
						'description' => 'JSON file path that contains the categories to import.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator diario-el-sol-import-tags',
			array( $this, 'cmd_des_import_tags' ),
			array(
				'shortdesc' => 'Import Diario El Sol tags from a JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'tags-json-path',
						'description' => 'JSON file path that contains the tags to import.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator diario-el-sol-import-posts',
			array( $this, 'cmd_des_import_posts' ),
			array(
				'shortdesc' => 'Import Diario El Sol posts from a JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'posts-json-path',
						'description' => 'JSON file path that contains the posts to import.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'imported-cache-path',
						'description' => 'Cache file containing the original_id of the imported notes (original_id per line).',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator diario-el-sol-import-authors`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_des_import_authors( $args, $assoc_args ) {
		$authors_json_path = $assoc_args['authors-json-path'] ?? null;
		if ( ! file_exists( $authors_json_path ) ) {
			WP_CLI::error( sprintf( 'Author export %s not found.', $authors_json_path ) );
		}

		$time_start = microtime( true );
		$authors    = Items::fromFile( $authors_json_path, array( 'decoder' => new ExtJsonDecoder( true ) ) );

		$added_authors    = 0;
		$added_co_authors = 0;
		foreach ( $authors as $author ) {
			// $authors keys: _id, email, name
			if ( ! array_key_exists( '_id', $author ) || ! array_key_exists( '$oid', $author['_id'] ) ) {
				$this->log( self::ADMINS_LOGS, printf( 'Skipped row: %s', wp_json_encode( $author ) ) );
			}

			$original_id  = $author['_id']['$oid'];
			$author_email = trim( $author['email'] );

			$author_data = array(
				'user_email'    => $author_email,
				'user_pass'     => '',
				'user_login'    => $author_email,
				'user_nicename' => sanitize_title( $author['name'] ),
				'nickname'      => $author['name'],
				'display_name'  => $author['name'],
				'role'          => 'authoristrator',
			);

			if ( empty( $author_email ) ) {
				if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
					WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
					$this->log( self::ADMINS_LOGS, 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
					continue;
				}
				try {
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						array(
							'display_name' => sanitize_title( $author['name'] ),
						)
					);
					if ( is_wp_error( $guest_author_id ) ) {
						WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
						$this->log( self::ADMINS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
					}

					// Set original ID.
					$added_co_authors++;
					update_post_meta( $guest_author_id, 'original_id', $original_id );
					$this->log( self::ADMINS_LOGS, sprintf( 'Imported co-author: %s', $original_id ) );
					WP_CLI::line( sprintf( 'Imported co-author: %s', $original_id ) );
				} catch ( \Exception $e ) {
					WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
					$this->log( self::ADMINS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
				}

				continue;
			}

			// Check if we have already imported the author.
			$existing_authors = $this->get_users_by_meta_data( 'original_id', $original_id );

			if ( empty( $existing_authors ) > 0 ) {
				// Create Author.
				$author_id = wp_insert_user( $author_data );
				if ( is_wp_error( $author_id ) ) {
					$this->log( self::ADMINS_LOGS, sprintf( '[%s]: %s [%s]', $original_id, $author_id->get_error_message(), wp_json_encode( $author_data ) ) );
					WP_CLI::warning( sprintf( 'Error creating user %s -- %s [%s]', $original_id, $author_id->get_error_message(), wp_json_encode( $author_data ) ) );
					continue;
				}

				$added_authors++;

				// Set Author Meta.
				update_user_meta( $author_id, 'original_id', $original_id );
				$this->log( self::ADMINS_LOGS, sprintf( 'Imported author: %s', $original_id ) );
				WP_CLI::line( sprintf( 'Imported author: %s', $original_id ) );
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d authors, and %d co-authors took %d mins.', $added_authors, $added_co_authors, floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator diario-el-sol-import-categories`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_des_import_categories( $args, $assoc_args ) {
		$categories_json_path = $assoc_args['categories-json-path'] ?? null;
		if ( ! file_exists( $categories_json_path ) ) {
			WP_CLI::error( sprintf( 'Categories export %s not found.', $categories_json_path ) );
		}

		$time_start = microtime( true );
		$categories = Items::fromFile( $categories_json_path, array( 'decoder' => new ExtJsonDecoder( true ) ) );

		$added_categories = 0;
		foreach ( $categories as $category ) {
			// $category keys: _id, name, url, domain, createdAt, __v
			if ( ! array_key_exists( '_id', $category ) || ! array_key_exists( '$oid', $category['_id'] ) ) {
				$this->log( self::CATEGORIES_LOGS, printf( 'Skipped row: %s', wp_json_encode( $category ) ) );
			}

			$original_id = $category['_id']['$oid'];

			// Check if we have already imported the category.
			$category_id = wp_insert_category(
				array(
					'cat_name'          => $category['name'],
					'category_nicename' => $category['url'],
				)
			);

			if ( is_wp_error( $category_id ) ) {
				$this->log( self::CATEGORIES_LOGS, sprintf( '[%s]: %s [%s]', $original_id, $category_id->get_error_message(), wp_json_encode( $category ) ), false );
				WP_CLI::warning( sprintf( 'Error creating category %s -- %s [%s]', $original_id, $category_id->get_error_message(), wp_json_encode( $category ) ) );
				continue;
			}

			$added_categories++;

			// Set Category Meta.
			update_term_meta( $category_id, 'original_id', $original_id );
			$this->log( self::CATEGORIES_LOGS, sprintf( 'Imported category: %s', $original_id ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d categories took %d mins.', $added_categories, floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator diario-el-sol-import-tags`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_des_import_tags( $args, $assoc_args ) {
		$tags_json_path = $assoc_args['tags-json-path'] ?? null;
		if ( ! file_exists( $tags_json_path ) ) {
			WP_CLI::error( sprintf( 'Tags export %s not found.', $tags_json_path ) );
		}

		$time_start = microtime( true );
		$tags       = Items::fromFile( $tags_json_path, array( 'decoder' => new ExtJsonDecoder( true ) ) );

		$added_tags = 0;
		foreach ( $tags as $tag ) {
			// $tag keys: _id, domain, tagId, name, url, embed, solId, createdAt, quantity, __v, bgColor, fontColor
			if ( ! array_key_exists( '_id', $tag ) || ! array_key_exists( '$oid', $tag['_id'] ) ) {
				$this->log( self::TAGS_LOGS, printf( 'Skipped row: %s', wp_json_encode( $tag ) ) );
			}

			$original_id = $tag['_id']['$oid'];

			// Check if we have already imported the tag.
			$created_tag = wp_insert_term(
				$tag['name'],
				'post_tag',
				array( 'slug' => ltrim( $tag['url'], '/' ) )
			);

			if ( is_wp_error( $created_tag ) ) {
				$this->log( self::TAGS_LOGS, sprintf( '[%s]: %s [%s]', $original_id, $created_tag->get_error_message(), wp_json_encode( $tag ) ), false );
				WP_CLI::warning( sprintf( 'Error creating user %s -- %s [%s]', $original_id, $created_tag->get_error_message(), wp_json_encode( $tag ) ) );
				continue;
			}

			$added_tags++;

			// Set Tag Meta.
			update_term_meta( $created_tag['term_id'], 'original_id', $original_id );
			foreach ( array( 'embed', 'solId', 'quantity', 'bgColor', 'fontColor' ) as $tag_meta_to_import ) {
				if ( array_key_exists( $tag_meta_to_import, $tag ) && ! is_null( $tag[ $tag_meta_to_import ] ) ) {
					update_term_meta( $created_tag['term_id'], "imported_$tag_meta_to_import", $tag[ $tag_meta_to_import ] );
				}
			}

			$this->log( self::TAGS_LOGS, sprintf( 'Imported tag: %s', $original_id ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d tags took %d mins.', $added_tags, floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator diario-el-sol-import-posts`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_des_import_posts( $args, $assoc_args ) {
		$posts_json_path = $assoc_args['posts-json-path'] ?? null;
		$cache_path      = $assoc_args['imported-cache-path'] ?? null;

		if ( ! file_exists( $posts_json_path ) ) {
			WP_CLI::error( sprintf( 'Posts export %s not found.', $posts_json_path ) );
		}

		global $wpdb;

		$time_start = microtime( true );
		$posts      = Items::fromFile( $posts_json_path, array( 'decoder' => new ExtJsonDecoder( true ) ) );

		$added_posts = 0;

		// Cache.
		$imported_notes = array();
		if ( file_exists( $cache_path ) ) {
			$imported_notes = file( $cache_path, FILE_IGNORE_NEW_LINES );
		}

		foreach ( $posts as $post ) {
			$original_id = $post['_id']['$oid'];

			// Check if we have already imported the post.
			if ( in_array( $original_id, $imported_notes ) ) {
				$this->log( self::POSTS_LOGS, sprintf( 'Skipped post %s as it\'s already imported', $original_id ) );
				continue;
			} else {
				// Get post author.
				$author_id          = 0;
				$co_authors         = array();
				$original_author_id = $post['author']['$oid'];
				$possible_authors   = $this->get_users_by_meta_data( 'original_id', $original_author_id );

				if ( count( $possible_authors ) > 0 ) {
					$author_id = $possible_authors[0]->ID;
				} else {
					// Check if the author is a guest author using the post meta set to the co-author "post" entity.
					$possible_co_authors = $this->get_co_author_by_meta_data( 'original_id', $original_author_id );
					if ( empty( $possible_co_authors ) ) {
						WP_CLI::warning( sprintf( 'Author %s of the post %s do not exists. The post will not have an author and will be imported', $original_author_id, $original_id ) );
						$this->log( self::POSTS_LOGS, sprintf( 'Author %s of the post %s do not exists. The post will not have an author and will be imported', $original_author_id, $original_id ) );
					} else {
						$co_author = $this->coauthorsplus_logic->get_guest_author_by_id( $possible_co_authors[0]->ID );
						if ( $co_author ) {
							$co_authors[] = $co_author->ID;
						}
					}
				}

				preg_match( '/\/(?P<title>.+)\.html$/', $post['urls']['title'], $possible_post_name );

				// Create Post.
				$post_data = array(
					'ID'            => null,
					'post_type'     => 'post',
					'post_title'    => $post['title'],
					'post_content'  => $post['body'],
					'post_excerpt'  => $post['epigraph'],
					'post_status'   => $post['isPublished'] ? 'publish' : 'draft',
					'post_author'   => $author_id,
					'post_date'     => $post['publishedAt']['$date'],
					'post_modified' => $post['lastUpdate']['$date'],
					'post_name'     => array_key_exists( 'title', $possible_post_name ) ? $possible_post_name['title'] : sanitize_title( $post['title'] ),
				);

				$post_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) ) {
					$this->log( self::POSTS_LOGS, sprintf( '[%s]: %s [%s]', $original_id, $post_id->get_error_message(), wp_json_encode( $post_data ) ) );
					WP_CLI::warning( sprintf( 'Error creating post %s -- %s [%s]', $original_id, $post_id->get_error_message(), wp_json_encode( $post_data ) ) );
					continue;
				}

				// Set co-authors if exists.
				if ( ! empty( $co_authors ) ) {
					$this->coauthors_guest_authors->assign_guest_authors_to_post( $co_authors, $post_id );
					$this->log( self::POSTS_LOGS, sprintf( 'Post %s with co-authors: %s', $post_id, wp_json_encode( $co_authors ) ) );
				}

				// Set all post images from the post body as attachments.
				$post_content_updated = $post['body'];
				$this->crawler->clear();
				$this->crawler->add( $post['body'] );
				$images = $this->crawler->filterXpath( '//img' )->extract( array( 'src', 'title', 'alt' ) );
				foreach ( $images as $image ) {
					$img_src   = $image[0] ?? null;
					$img_title = $image[1] ?? null;
					$img_alt   = $image[2] ?? null;
					if ( $img_src ) {
						// Check if there's already an attachment with this image.
						$is_src_fully_qualified = ( 0 == strpos( $img_src, 'http' ) );
						if ( ! $is_src_fully_qualified ) {
							$this->log( self::POSTS_LOGS, sprintf( 'skipping, img src `%s` from post % s as not fully qualified URL', $img_src, $original_id ) );
							continue;
						}

						$attachment_id = $this->import_attachment( $img_src, $img_alt, $img_title, $post_id );
						if ( $attachment_id ) {
							$post_content_updated = str_replace( $img_src, wp_get_attachment_url( $attachment_id ), $post_content_updated );
						}
					}
				}

				// Update the Post content.
				if ( $post_content_updated !== $post['body'] ) {
					$wpdb->update(
						$wpdb->prefix . 'posts',
						array( 'post_content' => $post_content_updated ),
						array( 'ID' => $post_id )
					);
				}

				// Import and set the post's featured image.
				$featured_image_link  = null;
				$featured_image_title = null;

				if ( count( $post['gallery'] ) > 0 && array_key_exists( 'highlighted', $post['gallery'][0] ) && array_key_exists( 'path', $post['gallery'][0]['highlighted'] ) ) {
					$featured_image_link = $post['gallery'][0]['highlighted']['path'];
					$img_title           = $post['gallery'][0]['highlighted']['title'] ?? null;
				} elseif ( count( $post['image'] ) > 0 && array_key_exists( 'highlighted', $post['image'] ) ) {
					$featured_image_link = $post['image']['highlighted']['path'];
					$img_title           = $post['image']['highlighted']['name'] ?? null;
				}

				if ( ! is_null( $featured_image_link ) ) {
					$att_id = $this->import_attachment( $featured_image_link, $featured_image_title, $img_title, $post_id );

					// Set attachment as featured image.
					$result_featured_set = set_post_thumbnail( $post_id, $att_id );
					if ( ! $result_featured_set ) {
						WP_CLI::warning( sprintf( '❗ could not set att.ID %s as featured image', $att_id ) );
						$this->log( self::POSTS_LOGS, sprintf( '❗ could not set att.ID %s as featured image', $att_id ) );
					} else {
						WP_CLI::line( sprintf( '👍 set att.ID %s as featured image', $att_id ) );
						$this->log( self::POSTS_LOGS, sprintf( '👍 set att.ID %s as featured image', $att_id ) );
					}
				}

				$added_posts++;

				// Set Post Meta.
				// Categories.
				$raw_categories     = $this->get_term_by_meta( 'original_id', $post['categories'] );
				$created_categories = array();
				if ( empty( $raw_categories ) ) {
					// Sometimes categories are set from the tags (e.g. note #oid = 59ce420ae101583a8815faa5).
					$categories_from_tags = $this->get_term_by_meta( 'original_id', $post['categories'], 'post_tag' );
					foreach ( $categories_from_tags as $category_from_tag ) {
						$created_category = category_exists( $category_from_tag->name );
						if ( $created_category ) {
							$created_categories[] = $created_category;
						} else {
							$created_category = wp_insert_category(
								array(
									'cat_name'          => $category_from_tag->name,
									'category_nicename' => $category_from_tag->slug,
								),
								true
							);
							if ( is_wp_error( $created_category ) ) {
								WP_CLI::error( sprintf( 'Error creating Category from Tag $s: %s', $category_from_tag->name, $created_category->get_error_message() ) );
							} else {
								$created_categories[] = $created_category;
							}
						}
					}
				}

				wp_set_post_categories(
					$post_id,
					empty( $raw_categories ) ? $created_categories : array_map(
						function( $category ) {
							return $category->term_id;
						},
						$raw_categories
					)
				);

				// Tags.
				$tags = $this->get_term_by_meta( 'original_id', $post['tags'], 'post_tag' );

				$result = wp_set_post_tags(
					$post_id,
					array_map(
						function( $tag ) {
							return $tag->name;
						},
						$tags
					)
				);
				if ( is_wp_error( $result ) ) {
					$this->log( self::POSTS_LOGS, sprintf( '%d %s %s', $post_id, wp_json_encode( $result ), $result->get_error_message() ) );
					WP_CLI::warning( sprintf( 'Error setting post tags %s -- %s', wp_json_encode( $result ), $result->get_error_message() ) );
				}

				WP_CLI::warning( sprintf( 'Setting Post %d tags %s', $post_id, wp_json_encode( $result ) ) );

				// Set the rest of post meta.
				update_post_meta( $post_id, 'original_id', $original_id );
				foreach ( array( 'isPushDown', 'isHighlighted', 'articleRssLink', 'sendNotification', 'notShowOnLast', 'notShowOnRanking', 'isRss', 'isSponsored' ) as $post_meta_to_import ) {
					if ( array_key_exists( $post_meta_to_import, $post ) && ! is_null( $post[ $post_meta_to_import ] ) ) {
						update_term_meta( $post_id, "imported_$post_meta_to_import", $post[ $post_meta_to_import ] );
					}
				}
				WP_CLI::line( sprintf( 'Imported Post %s with ID: %d.', $original_id, $post_id ) );

				if ( file_exists( $cache_path ) ) {
					file_put_contents( $cache_path, $original_id . PHP_EOL, FILE_APPEND | LOCK_EX );
				}
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d posts took %d mins.', $added_posts, floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Get Users list by meta.
	 *
	 * @param string $meta_key meta key to filter with.
	 * @param string $meta_value meta value to filter.
	 * @return mixed[]
	 */
	private function get_users_by_meta_data( $meta_key, $meta_value ) {
		$user_query = new \WP_User_Query(
			array(
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);

		return $user_query->get_results();
	}

	/**
	 * Get Cp-authors list by meta.
	 *
	 * @param string $meta_key meta key to filter with.
	 * @param string $meta_value meta value to filter.
	 * @return mixed[]
	 */
	private function get_co_author_by_meta_data( $meta_key, $meta_value ) {
		$query = new \WP_Query(
			array(
				'meta_key'    => $meta_key,
				'meta_value'  => $meta_value,
				'post_type'   => 'guest-author',
				'post_status' => array( 'publish', 'draft' ),
			)
		);
		return $query->posts;
	}

	/**
	 * Get term by custom meta/taxonomy
	 *
	 * @param string     $meta_key Meta to look for.
	 * @param string[][] $raw_meta_values Raw Meta value indexed by 'oid' key.
	 * @param string     $taxonomy Term taxonomy.
	 * @return array List of terms found.
	 */
	private function get_term_by_meta( $meta_key, $raw_meta_values, $taxonomy = 'category' ) {
		$meta_values = array_map(
			function( $tag ) {
				return $tag['$oid'];
			},
			$raw_meta_values
		);

		$args = array(
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key'     => $meta_key,
					'value'   => $meta_values,
					'compare' => 'IN',
				),
			),
			'taxonomy'   => $taxonomy,
		);

		return get_terms( $args );
	}

	/**
	 * Import image as post attachment.
	 *
	 * @param string $img_src Image public URL.
	 * @param string $img_alt Image alternative text.
	 * @param string $img_title Image title.
	 * @param int    $post_id Post ID to be set as parent for the generated attachment post.
	 * @return int|false Attachment post ID if created, false otherwise.
	 */
	private function import_attachment( $img_src, $img_alt, $img_title, $post_id ) {
		// Import attachment if it doesn't exist.
		$att_id     = attachment_url_to_postid( $img_src );
		$attachment = get_post( $att_id );
		if ( $attachment ) {
			return $att_id;
		} else {
			WP_CLI::line( sprintf( '- importing img `%s`...', $img_src ) );
			$att_id = $this->attachments_logic->import_external_file( $img_src, $img_title, $img_alt, null, $img_alt, $post_id );
			if ( is_wp_error( $att_id ) ) {
				$this->log( self::POSTS_LOGS, sprintf( 'Error importing image `%s` : %s', $img_src, $att_id->get_error_message() ) );
				return false;
			}

			return $att_id;
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
