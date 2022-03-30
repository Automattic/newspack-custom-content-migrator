<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackPostImageDownloader\Downloader as PostImageDownloader;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Reno News.
 */
class RenoMigrator implements InterfaceMigrator {

	// Post metas.
	const META_POST_ARTICLE_ID = '_newspackarchive_article_id';
	const META_POST_ISSUE_ID = '_newspackarchive_issue_id';
	const META_POST_HEADLINE = '_newspackarchivesub_headline';
	const META_POST_SUMMARY = '_newspackarchive_summary';
	const META_POST_CAT_NAME = '_newspackarchive_cat_name';
	const META_POST_CAT_ID = '_newspackarchive_cat_id';
	const META_POST_AUTHOR_ID = '_newspackarchive_author_id';
	const META_POST_AUTHOR_NAME = '_newspackarchive_author_name';
	const META_POST_AUTHOR_IMAGE = '_newspackarchive_author_image';
	const META_POST_AUTHOR_BIO = '_newspackarchive_author_bio';
	const META_POST_AUTHOR_EMAIL = '_newspackarchive_author_email';

	// Featured image metas.
	const META_FEATIMG_PATH = '_newspackarchive_featimg_path';
	const META_FEATIMG_CREDIT = '_newspackarchive_featimg_credit';
	const META_FEATIMG_CAPTION = '_newspackarchive_featimg_caption';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|PostImageDownloader.
	 */
	private $post_image_downloader;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->post_image_downloader = new PostImageDownloader();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator reno-postgress-archive-import',
			[ $this, 'cmd_postgress_archive_import' ],
			[
				'shortdesc' => 'Imports Reno\'s Postgres archive content.',
			]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_postgress_archive_import( $args, $assoc_args ) {

		// Config.
		$postgres_host = 'host.docker.internal';
		$postgres_port = '5432';
		$postgres_dbname = 'renopsql';
		$postgres_user = 'root';
		$postgres_pass = 'root';
		$log = 'reno.log';
		// Make the image path publicly available.
		$images_path = '/srv/htdocs/wp-content';
		$images_path = '/var/www/reno.test/public/wp-content';


		// Test Postgres connection.
		try {
			$pdo = new \PDO( "pgsql:host=$postgres_host;port=$postgres_port;dbname=$postgres_dbname;", $postgres_user, $postgres_pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		} catch ( \PDOException $e ) {
			die( $e->getMessage() );
		}


		// TS the log.
		$this->log( $log, sprintf( 'Starting %s.', gmdate( 'Y-m-d h:i:s a', time() ) ) );


		// Count all articles.
		$count_statement = $pdo->query( "select count(*) as total from gyro_article where status = 'live' ;" );
		$count_result = $count_statement->fetch( \PDO::FETCH_ASSOC );
		$total = $count_result['total'];


		// Query articles.
		$articles_statement = $pdo->query(
			"select id, headline, body, gyro_creation_date, sub_headline, summary, seo, issue_id
			from gyro_article
			where status = 'live' ; "
		);
		$i = 0;
		while( $result = $articles_statement->fetch( \PDO::FETCH_ASSOC )  ) {

			// Get basic post data.
			$article_id = $result['id'];
			$post_title = $result['headline'];
			$post_content = $result['body'];
			$post_date = $result['gyro_creation_date'];
			$article_sub_headline = $result['sub_headline'];
			$article_summary = $result['summary'];
			$post_excerpt = $article_sub_headline
			                . ( ! empty( $article_sub_headline ) && ! empty( $article_summary ) ?  "\n\n" : '' ) .
			                $article_summary;
			$post_excerpt = empty( $post_excerpt ) ? null : $post_excerpt;
			$post_slug = $result['seo'];
			$article_issue_id = $result['issue_id'];


			// Start.
			$i++;
			\WP_CLI::Log( sprintf( '(%d)/(%d) %d', $i, $total, $article_id ) );
			$post_metas = [];


			// Category.
			$category_statement = $pdo->query( sprintf(
				"select gyro_category.id, name
				from gyro_category
				join gyro_article on gyro_category.id = gyro_article.category_id
				where gyro_article.id = %d",
				$article_id
			) );
			$category_result = $category_statement->fetch( \PDO::FETCH_ASSOC );
			$cat_id = null;
			if ( $category_result ) {
				$article_cat_id = $category_result['id'];
				$article_cat_name = $category_result['name'];

				// Save and get cat.ID.
				if ( isset( $article_cat_name ) && ! empty( $article_cat_name ) ) {
					$cat_id = wp_create_category( $article_cat_name );
					if ( is_wp_error( $cat_id ) ) {
						$this->log( $log, sprintf( 'ERR creating category $article_id=%s $article_cat_id=%s $article_cat_name=%s : %s', $article_id, $article_cat_id, $article_cat_name, $cat_id->get_error_message() ) );
					} else {
						$post_metas = array_merge(
							$post_metas,
							[
								self::META_POST_CAT_NAME => $article_cat_name,
								self::META_POST_CAT_ID => $article_cat_id
							]
						);
					}
				}
			}


	        // Save Post.
			$post_id = wp_insert_post( [
				'post_status' => 'publish',
				'post_title' => $post_title,
				'post_content' => $post_content,
				'post_date' => $post_date,
				'post_excerpt' => $post_excerpt,
				'post_name' => $post_slug,
				'post_category' => [ $cat_id ],
			] );
			if ( is_wp_error( $post_id ) ) {
				$this->log( $log, sprintf( 'ERR saving post $article_id=%s : %s', $article_id, $post_id->get_error_message() ) );
				continue;
			}


			// Import and attach a featured image.
			$featimage_statement = $pdo->query( sprintf(
				"select image, credit, caption
				from gyro_featured_image
				where article_id = %d",
				$article_id
			) );
			$featimage_result = $featimage_statement->fetch( \PDO::FETCH_ASSOC );
			if ( $featimage_result && isset( $featimage_result['image'] ) && ! empty( $featimage_result['image'] ) ) {
				$featimg_path = $featimage_result['image'];
				$featimg_credit = $featimage_result['credit'];
				$featimg_caption = $featimage_result['caption'];

				$this->attach_featured_image_to_post( $post_id, $log, $images_path, $featimg_path, $featimg_caption, $featimg_credit );
			}


			// Guest Author.
			$author_statement = $pdo->query( sprintf(
				"select gyro_author.id, gyro_author.name, gyro_author.image, gyro_author.email, gyro_author.bio
				from gyro_author
				join gyro_article_authors on gyro_author.id = gyro_article_authors.author_id
				where gyro_article_authors.article_id = %d",
				$article_id
			) );
			$author_result = $author_statement->fetch( \PDO::FETCH_ASSOC );
			$ga_id = null;
			if ( $author_result ) {
				$author_id = $author_result['id'];
				$author_name = $author_result['name'];
				$author_image = $author_result['image'];
				$author_bio = $author_result['bio'];
				$author_email = $author_result['email'];

				// Get/create GA and assign it to Post.
				$ga_id = $this->get_or_create_guest_author( $post_id, $log, $images_path, $author_id, $author_name, $author_email, $author_bio );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id );

				// Save some post metas.
				$post_metas = array_merge(
					$post_metas,
					[
						self::META_POST_AUTHOR_ID => $author_id,
						self::META_POST_AUTHOR_NAME => $author_name,
					]
				);
				if ( ! empty( $author_image ) ) {
					$post_metas[ self::META_POST_AUTHOR_IMAGE ] = $author_image;
				}
				if ( ! empty( $author_bio ) ) {
					$post_metas[ self::META_POST_AUTHOR_BIO ] = $author_bio;
				}
				if ( ! empty( $author_email ) ) {
					$post_metas[ self::META_POST_AUTHOR_EMAIL ] = $author_email;
				}
			}


			// Save some post metas
			$post_metas = array_merge(
				$post_metas,
				[
					self::META_POST_ARTICLE_ID => $article_id,
					self::META_POST_ISSUE_ID => $article_issue_id,
					self::META_POST_HEADLINE => $article_sub_headline,
					self::META_POST_SUMMARY => $article_summary,
				]
			);
			foreach ( $post_metas as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}
	}

	public function attach_featured_image_to_post( $post_id, $log, $images_path, $featimg_path, $featimg_caption, $featimg_credit ) {

		$featimg_full_path = $images_path . '/' . $featimg_path;
		if ( file_exists( $featimg_full_path ) ) {

			// Import att img.
			$featimage_att_id = $this->post_image_downloader->import_external_file( $featimg_full_path, null, $featimg_caption, $featimg_credit, $featimg_caption, $post_id );

			if ( is_wp_error( $featimage_att_id ) ) {
				$this->log( $log, sprintf( 'ERR importing featImg=%s $post_id=%d : %s', $featimg_full_path, $post_id, $featimage_att_id->get_error_message() ) );
			} else {

				// Set feat img.
				set_post_thumbnail( get_post( $post_id ), $featimage_att_id );

				// Save some metas.
				$featimg_metas = [
					self::META_FEATIMG_PATH => $featimg_path,
					self::META_FEATIMG_CREDIT => $featimg_credit,
					self::META_FEATIMG_CAPTION => $featimg_caption,
				];
				foreach ( $featimg_metas as $meta_key => $meta_value ) {
					update_post_meta( $featimage_att_id, $meta_key, $meta_value );
				}
			}
		} else {
			$this->log( $log, sprintf( 'ERR featImg not found, $post_id=%d, $img_path=%s', $post_id, $featimg_full_path ) );
		}
	}

	public function get_or_create_guest_author( $post_id, $log, $images_path, $author_id, $author_name, $author_email, $author_bio ) {
		// GA data.
		$ga_fullname = sanitize_text_field( $author_name );
		$ga_login = sanitize_title( sanitize_text_field( $author_name ) );
		$ga_email = sanitize_email( $author_email );
		$ga_description = wp_filter_post_kses( $author_bio );

		// Get or create GA.
		$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $ga_login );
		if ( $guest_author ) {
			$ga_id = $guest_author->ID;

			return $ga_id;
		} else {
			if ( empty( $ga_fullname ) ) {
				$this->log( $log, sprintf( 'ERR empty author name $post_id=%d $author_id=%d', $post_id, $author_id ) );

				return null;
			} else {

				// Get GA data.
				$ga_data = [
					'display_name' => $ga_fullname,
					'user_login' => $ga_login,
				];
				if ( ! empty( $author_email ) ) {
					$ga_data['user_email'] = $ga_email;
				}
				if ( ! empty( $ga_description ) ) {
					$ga_data['description'] = $ga_description;
				}

				// Import avatar image.
				if ( ! empty( $author_image ) ) {
					$author_image_full_path = $images_path . '/' . $author_image;
					if ( file_exists( $author_image_full_path ) ) {
						$authorimg_att_id = $this->post_image_downloader->import_external_file( $author_image_full_path );
						if ( is_wp_error( $authorimg_att_id ) ) {
							$this->log( $log, sprintf( 'ERR importing author avatar image $post_id=%d $author_image=%s $author_image_full_path=%s : %s', $post_id, $author_image, $author_image_full_path, $authorimg_att_id->get_error_message() ) );
						} else {
							$ga_data['avatar'] = $authorimg_att_id;
						}
					} else {
						$this->log( $log, sprintf( 'ERR avatar img not found $post_id=%d $author_image=%s $author_image_full_path=%s', $post_id, $author_image, $author_image_full_path ) );
					}
				}

				// Create GA.
				$ga_id = $this->coauthorsplus_logic->create_guest_author( $ga_data );

				return $ga_id;
			}
		}

		return null;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
