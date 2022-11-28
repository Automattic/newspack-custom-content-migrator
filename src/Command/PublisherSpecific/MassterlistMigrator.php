<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackPostImageDownloader\Downloader;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Search Light New Mexico.
 */
class MassterlistMigrator implements InterfaceCommand {
	// Logs.
	const JOBS_LOGS     = 'Massterlist_jobs.log';
	const EDITIONS_LOGS = 'Massterlist_editions.log';

	const JOBS_COMPANY_ICON_MEDIA_ID  = 612;
	const JOBS_LOCATION_ICON_MEDIA_ID = 610;
	const JOBS_URL_ICON_MEDIA_ID      = 611;
	const JOBS_FULLTIME_ICON_MEDIA_ID = 609;

	/**
	 * @var Downloader.
	 */
	private $downloader;

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->downloader  = new Downloader();
		$this->dom_crawler = new Crawler();
	}

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

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
            'newspack-content-migrator massterlist-migrate-jobs',
            array( $this, 'massterlist_migrate_jobs' ),
            array(
				'shortdesc' => 'Migrate jobs listings from the last 30 days. To use this command you need the tables from the editions and jobs databases of the publisher, that you need to import locally, prefix the editions tables with editions_, and the jobs tables with _jobs, and then import them to the WP site where you\'re going to execute the commands',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'jobs-ids',
						'description' => 'Jobs IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
            )
		);

		WP_CLI::add_command(
			'newspack-content-migrator massterlist-migrate-editions',
			array( $this, 'massterlist_migrate_editions' ),
			array(
				'shortdesc' => 'Migrate editions posts. To use this command you need the tables from the editions and jobs databases of the publisher, that you need to import locally, prefix the editions tables with editions_, and the jobs tables with _jobs, and then import them to the WP site where you\'re going to execute the commands',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'editions-ids',
						'description' => 'Editions IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator massterlist-migrate-jobs`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function massterlist_migrate_jobs( $args, $assoc_args ) {
		global $wpdb;

		$jobs_ids = isset( $assoc_args['jobs-ids'] ) ? $assoc_args['jobs-ids'] : null;

		// Make sure NGG DB tables are available.
		$this->validate_db_tables_exist( [ 'jobs_users', 'jobs_jobs' ] );

		// Create or get the `Jobs` category.
		$cateogry_id = wp_create_category( 'Jobs' );

		// Read the jobs from the old DB.
		$jobs_sql = 'SELECT * FROM jobs_jobs';
		if ( $jobs_ids ) {
			$jobs_sql .= $wpdb->prepare( ' WHERE id IN (%s)', $jobs_ids );
		}

		if ( ! $jobs_ids ) {
			$jobs_sql .= str_contains( $jobs_sql, 'WHERE' ) ? ' and ' : ' WHERE ';
			$jobs_sql .= 'published_at BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE()';
		}

		$jobs = $wpdb->get_results( $jobs_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		foreach ( $jobs as $job ) {
			$job_content = $this->generate_job_content_from_posts( $job );

			if ( $this->post_exists( 'job_original_id', $job['id'] ) ) {
				WP_CLI::warning( sprintf( "Skipping job %d as it's already imported!", $job['id'] ) );
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'     => $job['title'],
					'post_content'   => $job_content,
					'post_status'    => 1 === intval( $job['is_active'] ) ? 'publish' : 'draft',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_name'      => $job['slug'],
					'post_date'      => $job['published_at'],
					'post_modified'  => $job['updated_at'],
					'post_type'      => 'newspack_lst_mktplce',
				)
			);

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( sprintf( "Couldn't save the job with the ID %d: %s", $job['id'], $post_id->get_error_message() ) );
			} else {
				WP_CLI::success( sprintf( 'Job %d was migrated successfully as a post %d', $job['id'], $post_id ) );
				wp_set_post_categories( $post_id, [ $cateogry_id ] );
				update_post_meta( $post_id, 'job_original_id', $job['id'] );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator massterlist-migrate-editions`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function massterlist_migrate_editions( $args, $assoc_args ) {
		global $wpdb;

		$editions_ids = isset( $assoc_args['editions-ids'] ) ? $assoc_args['editions-ids'] : null;

		// Make sure NGG DB tables are available.
		$this->validate_db_tables_exist( [ 'editions_users', 'editions_posts', 'editions_editions' ] );

		// Create or get the `Editions` category.
		$cateogry_id = wp_create_category( 'Editions' );

		// Read the editions from the old DB.
		$editions_sql = 'SELECT * FROM editions_editions';
		if ( $editions_ids ) {
			$editions_sql .= $wpdb->prepare( ' WHERE id IN (%s)', $editions_ids );
		}

		$editions = $wpdb->get_results( $editions_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		foreach ( $editions as $edition ) {
			if ( $this->post_exists( 'edition_original_id', $edition['id'] ) ) {
				WP_CLI::warning( sprintf( "Skipping edition %d as it's already imported!", $edition['id'] ) );
				continue;
			}

			$posts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM editions_posts WHERE edition_id = %d ORDER BY `order`;', $edition['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

			$edition_content = $this->generate_edition_content_from_posts( $posts );

			$post_id = wp_insert_post(
				array(
					'post_title'     => $edition['title'],
					'post_content'   => $edition_content,
					'post_status'    => 1 === intval( $edition['status'] ) ? 'publish' : 'draft',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_name'      => sanitize_title( $edition['title'] ),
					'post_date'      => $edition['publish_date'],
					'post_modified'  => $edition['updated_at'],
					'post_type'      => 'post',
				)
			);

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( sprintf( "Couldn't save the edition with the ID %d: %s", $edition['id'], $post_id->get_error_message() ) );
			} else {
				WP_CLI::success( sprintf( 'Edition %d was migrated successfully as a post %d', $edition['id'], $post_id ) );
				wp_set_post_categories( $post_id, [ $cateogry_id ] );
				update_post_meta( $post_id, 'edition_original_id', $edition['id'] );
			}
		}
	}

	/**
	 * Generate an edition content from its posts.
	 *
	 * @param mixed[] $posts Edition raw posts.
	 * @return string
	 */
	private function generate_edition_content_from_posts( $posts ) {
		$edition_content_blocks = [];

		$kal_posts = array_filter(
            $posts,
            function( $post ) {
				return 8 === intval( $post['type'] );
			}
        );

		$ads_before_first_section = array_filter(
            $posts,
            function( $post ) {
				return 6 === intval( $post['type'] ) && 1000 >= $post['order'];
			}
        );

		$ads_before_second_section = array_filter(
            $posts,
            function( $post ) {
				return 6 === intval( $post['type'] ) && 1000 < $post['order'] && 2000 >= $post['order'];
			}
        );

		$job_posts = array_filter(
            $posts,
            function( $post ) {
				return 4 === intval( $post['type'] );
			}
        );

		$other_posts = array_filter(
            $posts,
            function( $post ) {
				// return ! in_array( $post['type'], [ 4, 8, 6 ] ) || ( 6 === intval( $post['type'] ) && 2000 < $post['order'] );
				return ! in_array( $post['type'], [ 4, 8, 6 ] );
			}
        );

		$edition_content_blocks[] = $this->get_kal_content( $kal_posts );

		// $edition_content_blocks[] = $this->get_ads_before_body_content( $ads_before_first_section );

		$edition_content_blocks[] = $this->get_other_posts_content( $other_posts, $ads_before_second_section );

		// if ( 1 !== ( new \DateTime( $edition['publish_date'] ) )->format( 'w' ) ) {
		// $edition_content_blocks[] = $this->get_jobs_content( $job_posts );
		// }

		$edition_content_blocks[] = $this->generate_title_block( 'How to Contact MASSterList' );
		$edition_content_blocks[] = $this->generate_paragraph_block( "Send tips to Matt Murphy: Editor@MASSterList.com. For advertising inquiries and job board postings, please contact Dylan Rossiter: Publisher@MASSterList.com or (857) 370-1156. Follow <a href='https://twitter.com/massterlist'>@MASSterList</a> on Twitter." );

		return join( "\n", $edition_content_blocks );
	}

	/**
	 * Generate a job post content.
	 *
	 * @param mixed[] $job Raw job.
	 * @return string
	 */
	private function generate_job_content_from_posts( $job ) {
		$company_icon_url   = wp_get_attachment_url( self::JOBS_COMPANY_ICON_MEDIA_ID );
		$location_icon_url  = wp_get_attachment_url( self::JOBS_LOCATION_ICON_MEDIA_ID );
		$url_icon_url       = wp_get_attachment_url( self::JOBS_URL_ICON_MEDIA_ID );
		$full_time_icon_url = wp_get_attachment_url( self::JOBS_FULLTIME_ICON_MEDIA_ID );

		$company   = ( empty( $job['company_name'] ) ) ? '' : '<img class="wp-image-124" style="width: 20px;" src="' . $company_icon_url . '" alt="">  ' . $job['company_name'] . '<br>';
		$locaiton  = ( empty( $job['location'] ) ) ? '' : '<img class="wp-image-124" style="width: 20px;" src="' . $location_icon_url . '" alt="">  ' . $job['location'] . '<br>';
		$url       = ( empty( $job['url'] ) ) ? '' : '<img class="wp-image-124" style="width: 20px;" src="' . $url_icon_url . '" alt="">  <a href="' . $job['url'] . '">' . $job['url'] . '</a><br>';
		$full_tile = ( 1 === intval( $job['is_fulltime'] ) ) ? '' : '<img class="wp-image-124" style="width: 20px;" src="' . $full_time_icon_url . '" alt="">  Full Time';

		$details_content = "<!-- wp:paragraph --><p>$company$locaiton$url$full_tile</p><!-- /wp:paragraph -->";

		return $details_content . $job['description'];
	}

	/**
	 * Generate Keller at large content from raw posts.
	 *
	 * @param mixed[] $posts Raw posts.
	 * @return string
	 */
	private function get_kal_content( $posts ) {
		$blocks = count( $posts ) > 0 ? [ $this->generate_title_block( 'Keller at Large' ) ] : [];

		foreach ( $posts as $post ) {
			$blocks[] = $this->generate_post_content( $post );
		}

		return join( "\n", $blocks );
	}

	/**
	 * Generate Ads content from raw posts.
	 *
	 * @param mixed[] $posts Raw posts.
	 * @return string
	 */
	private function get_ads_before_body_content( $posts ) {
		$blocks = [];

		foreach ( $posts as $post ) {
			$blocks[] = $this->generate_image_block( $post );
		}

		return join( "\n", $blocks );
	}

	/**
	 * Generate posts content from raw posts.
	 *
	 * @param mixed[] $posts Raw posts.
	 * @param mixed[] $ads_posts Ads raw posts.
	 * @return string
	 */
	private function get_other_posts_content( $posts, $ads_posts ) {
		$blocks        = [];
		$grouped_posts = $this->group_posts_by( $posts, 'type' );

		if ( array_key_exists( 1, $grouped_posts ) ) {
			$blocks[] = $this->generate_title_block( 'Happening Today' );
			foreach ( $grouped_posts[1] as $post ) {
				$blocks[] = $this->generate_post_content( $post );
			}
		}

		// $blocks[] = $this->get_ads_before_body_content( $ads_posts );

		if ( array_key_exists( 2, $grouped_posts ) ) {
			$blocks[] = $this->generate_title_block( "Today's Stories" );

			foreach ( $grouped_posts[2] as $post ) {
				$sponsored = $post['type'] == 5;
				if ( ! $sponsored ) {
					$blocks[] = $this->generate_post_content( $post, $sponsored );
				}
			}
		}

		if ( array_key_exists( 3, $grouped_posts ) ) {
			$blocks[]          = $this->generate_title_block( "Today's Headlines" );
			$grouped_headlines = $this->group_headlines_by( $grouped_posts[3], 'alttext' );
			$headlines         = [1 => 'Metro', 2 => 'Massachusetts', 3 => 'Nation'];
			foreach ( $headlines as $type => $headline ) {
				if ( array_key_exists( $type, $grouped_headlines ) ) {
					$blocks[] = $this->generate_title_block( $headline, 3 );
					foreach ( $grouped_headlines[ $type ] as $post ) {
						$blocks[] = $this->generate_link_block( $post, true );
					}
				}
			}
		}

		return join( "\n", $blocks );
	}

	/**
	 * Generate Jobs content from raw posts.
	 *
	 * @param mixed[] $posts Raw posts.
	 * @return string
	 */
	private function get_jobs_content( $posts ) {
		if ( empty( $posts ) ) {
			return '';
		}

		$blocks   = [ $this->generate_title_block( 'Jobs' ) ];
		$blocks[] = $this->generate_paragraph_block( 'Reach MASSterList and the State House News Service’s connected audience in the political and public policy worlds in Massachusetts. Contact Dylan Rossiter: Publisher@MASSterList.com or call (857) 370-1156 for more information.' );
		$blocks[] = $this->generate_title_block( 'Recent postings to the MASSterList Job Board:', 3 );

		foreach ( $posts as $post ) {
			$blocks[] = $this->generate_job_link_block( $post );
		}

		return join( "\n", $blocks );
	}

	/**
	 * Generate a post content from raw post.
	 *
	 * @param mixed[] $post Raw post.
	 * @param boolean $sponsored If post is sponsored.
	 * @return string
	 */
	private function generate_post_content( $post, $sponsored = false ) {
		$is_image = 6 === intval( $post['type'] );
		$content  = '';
		if ( $sponsored ) {
			$content .= $this->generate_separator_block( $post );
			$content .= $this->generate_title_block( 'Sponsored', 5, true, true );
		}

		$content .= $is_image ? '' : $this->generate_title_block( $post['title'], 3 );
		$content .= $is_image ? '' : $this->generate_paragraph_block( trim( $post['body'] ), $sponsored );
		$content .= $is_image ? '' : $this->generate_link_block( $post );
		$content .= $this->generate_image_block( $post );

		if ( $sponsored ) {
			$content .= $this->generate_separator_block( $post );
		}

		return $content;
	}

	/**
	 * Generate title block.
	 *
	 * @param string  $title Title content.
	 * @param integer $level Title level.
	 * @param boolean $is_italic If title is italic.
	 * @param boolean $centered If title is centered.
	 * @return string
	 */
	private function generate_title_block( $title, $level = 2, $is_italic = false, $centered = false ) {
		if ( empty( $title ) ) {
			return '';
		}

		$tag   = "h$level";
		$level = 2 === $level ? '' : ' {' . ( $centered ? '"textAlign":"center",' : '' ) . '"level":' . $level . '}';
		$title = $is_italic ? "<em>$title</em>" : $title;
		return "<!-- wp:heading$level --><$tag" . ( $centered ? ' class="has-text-align-center"' : '' ) . ">$title</$tag><!-- /wp:heading -->";
	}

	/**
	 * Generate a paragraph block
	 *
	 * @param string  $raw_content Paragraph content.
	 * @param boolean $sponsored If the paragraph is sponsored.
	 * @return string
	 */
	private function generate_paragraph_block( $raw_content, $sponsored = false ) {
		$content = '';

		$this->dom_crawler->clear();
		$this->dom_crawler->add( $raw_content );
		$paragraphs = $this->dom_crawler->filterXPath( '//p' );
		foreach ( $paragraphs as $paragraph ) {
			$paragraph_content = $paragraph->ownerDocument->saveHTML( $paragraph );
			if ( $sponsored ) {
				$paragraph_content = str_replace( [ '<p>', '</p>' ], [ '<p><em>', '</em></p>' ], $paragraph_content );
			}
			$content .= "<!-- wp:paragraph -->$paragraph_content<!-- /wp:paragraph -->";
		}
		return $content;
	}

	/**
	 * Generate a link block.
	 *
	 * @param mixed[] $post Raw post.
	 * @param boolean $with_title If we need to add title to the link.
	 * @return string
	 */
	private function generate_link_block( $post, $with_title = false ) {
		if (
			! array_key_exists( 'linkurl', $post ) || empty( $post['linkurl'] )
			|| ! array_key_exists( 'linktext', $post ) || empty( $post['linktext'] )
		) {
			return '';
		}
		return '<!-- wp:paragraph --><p><a href="' . $post['linkurl'] . '" data-type="URL">' . ( $with_title ? $post['title'] . ' - ' : '' ) . $post['linktext'] . '</a></p><!-- /wp:paragraph -->';
	}

	/**
	 * Generate a job link.
	 *
	 * @param mixed[] $post Raw post.
	 * @return string
	 */
	private function generate_job_link_block( $post ) {
		if (
			! array_key_exists( 'linkurl', $post ) || empty( $post['linkurl'] )
			|| ! array_key_exists( 'body', $post ) || empty( $post['body'] )
		) {
			return '';
		}
		return '<!-- wp:paragraph --><p><a href="' . $post['linkurl'] . '" data-type="URL">' . $post['title'] . ', ' . $post['body'] . '</a></p><!-- /wp:paragraph -->';
	}

	/**
	 * Generate an image block.
	 *
	 * @param mixed[] $post Raw post.
	 * @return string
	 */
	private function generate_image_block( $post ) {
		if ( ! array_key_exists( 'imageurl', $post ) || empty( $post['imageurl'] ) ) {
			return '';
		}

		$with_link = array_key_exists( 'linkurl', $post ) && ! empty( $post['linkurl'] );
		$image_url = 'http://massterlist.com/images/' . $post['imageurl'];
		try {
			$image_id = $this->downloader->import_external_file(
				$image_url,
				$post['imageurl'],
				null,
				null,
				$post['alttext']
			);
		} catch ( \Exception $e ) {
			WP_CLI::warning( sprintf( "Can't download this image (%s) from the post %d: %s", $image_url, $post['id'], $e->getMessage() ) );
			return '';
		}

		if ( is_wp_error( $image_id ) ) {
			WP_CLI::warning( sprintf( "ERROR importing image %s : %s\n", $image_url, $image_id->get_error_message() ) );
			return '';
		}

		$attachment_url = wp_get_attachment_url( $image_id );
		$caption        = ( 'Yes' === $post['linktext'] || null === $post['linktext'] ) ? '<figcaption>Advertisement</figcaption>' : '';
		$body           = empty( $post['body'] ) ? '' : $this->generate_paragraph_block( trim( $post['body'] ) );

		return $with_link ? '<!-- wp:image {"align":"center","id":' . $image_id . ',"sizeSlug":"large","linkDestination":"custom"} -->
		<figure class="wp-block-image aligncenter size-large"><a href="' . $post['linkurl'] . '" target="_blank" rel=" noreferrer noopener"><img src="' . $attachment_url . '" alt="' . $post['alttext'] . '" class="wp-image-' . $image_id . '"/></a>' . $caption . '</figure>
		<!-- /wp:image -->' . $body
		: '<!-- wp:image {"align":"center","id":' . $image_id . ',"sizeSlug":"large","linkDestination":"none"} -->
		<figure class="wp-block-image aligncenter size-large"><img src="' . $attachment_url . '" alt="' . $post['alttext'] . '" class="wp-image-' . $image_id . '"/>' . $caption . '</figure>
		<!-- /wp:image -->' . $body;
	}

	/**
	 * Generate a separator block.
	 *
	 * @return string
	 */
	private function generate_separator_block() {
		return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
	}

	/**
	 * Group posts by an attribute
	 *
	 * @param mixed[] $posts Posts to group.
	 * @param string  $attribute Attribute to group the posts with.
	 * @return mixed[]
	 */
	private function group_posts_by( $posts, $attribute ) {
		$grouped_posts = array();

		foreach ( $posts as $item ) {
			$post_type                     = in_array( $item[ $attribute ], [ 2, 5, 6, 7, 8 ] ) ? 2 : $item[ $attribute ];
			$grouped_posts[ $post_type ][] = $item;
		}

		ksort( $grouped_posts, SORT_NUMERIC );

		return $grouped_posts;
	}

	/**
	 * Group headlines by an attribute.
	 *
	 * @param mixed[] $posts Headline posts to group.
	 * @param string  $attribute Attributes to group the headline posts with.
	 * @return mixed[]
	 */
	private function group_headlines_by( $posts, $attribute ) {
		$grouped_posts = array();

		foreach ( $posts as $item ) {
			$grouped_posts[ $item[ $attribute ] ][] = $item;
		}

		ksort( $grouped_posts, SORT_NUMERIC );

		return $grouped_posts;
	}

	/**
	 * Checks if DB tables exist locally.
	 *
	 * @param array $tables Tables to check.
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
	private function validate_db_tables_exist( $tables ) {
		global $wpdb;

		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'select * from information_schema.tables where table_schema = %s AND table_name = %s limit 1;', DB_NAME, $table ), ARRAY_A );
			if ( is_null( $row ) || empty( $row ) ) {
				throw new \Exception( sprintf( 'TTable %s not found in DB.', $table ) );
			}
		}

		return true;
	}

	/**
	 * Check if posts exist by meta.
	 *
	 * @param string $meta_key Meta to check the existance of the post with.
	 * @param mixed  $meta_value Meta value.
	 * @return boolean
	 */
	private function post_exists( $meta_key, $meta_value ) {
		$existing_posts = get_posts( [ 'meta_query' => [ ['key' => $meta_key, 'value' => $meta_value] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return 0 < count( $existing_posts );
	}
}
