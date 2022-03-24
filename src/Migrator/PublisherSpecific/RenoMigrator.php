<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Reno News.
 */
class RenoMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
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
		try {
			$pdo = new \PDO( 'pgsql:host=localhost;port=5432;dbname=renovvv;', 'postgres', 'root', [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		} catch ( \PDOException $e ) {
			// Check whether Postgres is installed and creds are valid.
			die( $e->getMessage() );
		}

		// Query all articles.
		$articles_statement = $pdo->query(
			"select id, headline, body, gyro_creation_date, sub_headline, summary, seo, issue_id
			from gyro_article
			where status = 'live' ; "
		);
		while( $result = $articles_statement->fetch( \PDO::FETCH_ASSOC )  ) {

			// Basic post data.
			$article_id = $result['id'];
			$post_title = $result['headline'];
			$post_content = $result['body'];
			$post_date = $result['gyro_creation_date'];
			$article_sub_headline = $result['sub_headline'];
			$article_summary = $result['summary'];
			$post_excerpt = $article_sub_headline. "\n\n" . $article_summary;
			$post_permalink = $result['seo'];
			$article_issue_id = $result['issue_id'];

			// Featured image.
			$featimage_statement = $pdo->query( sprintf(
				"select image, credit, caption
				from gyro_featured_image
				where article_id = %d",
				$article_id
			) );
			$featimage_result = $featimage_statement->fetch( \PDO::FETCH_ASSOC );
			$featimage_att_id = null;
			if ( $featimage_result ) {
				$featimage_result['image'];
				$featimage_result['credit'];
				$featimage_result['caption'];

				// ... save and get att.ID.
				$featimage_att_id;
			}

			// Category.
			$category_statement = $pdo->query( sprintf(
				"select id, name
				from gyro_category
				join gyro_article on gyro_category.id = gyro_article.category_id
				where gyro_article.id = %d",
				$article_id
			) );
			$category_result = $category_statement->fetch( \PDO::FETCH_ASSOC );
			$cat_id = null;
			if ( $category_result ) {
				// ... save and get cat.ID.
				$cat_id;
			}

	        // Save Post.
			$post_id = wp_insert_post( [
				'post_title' => $post_title,
				'post_content' => $post_content,
				'post_date' => $post_date,
				'post_excerpt' => $post_excerpt,
				'featured_image' => $featimage_att_id,
				'permalink' => $post_permalink,
				'category_id' => $cat_id,
			] );

			// Guest Author.
			$author_statement = $pdo->query( sprintf(
				"select name, image, email, bio
				from gyro_author
				join gyro_article_authors on gyro_author.id = gyro_article_authors.author_id
				where gyro_article_authors.article_id = %d",
				$article_id
			) );
			$author_result = $author_statement->fetch( \PDO::FETCH_ASSOC );
			$ga_id = null;
			if ( $author_result ) {
				// Get or create GA.
				$ga_id;

				// Assign GA to post.
				$post_id;
			}

	        // Save some post metas
			$post_metas = [
				'_newspackarchive_article_id' => $article_id,
				'_newspackarchiveissue_id' => $article_issue_id,
				'_newspackarchivesub_headline' => $article_sub_headline,
				'_newspackarchivesummary' => $article_summary,
			];
			foreach ( $post_metas as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

	}
}
