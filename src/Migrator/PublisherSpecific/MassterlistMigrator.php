<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackPostImageDownloader\Downloader;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Search Light New Mexico.
 */
class MassterlistMigrator implements InterfaceMigrator {
	// Logs.
	const JOBS_LOGS     = 'Massterlist_jobs.log';
	const EDITIONS_LOGS = 'Massterlist_editions.log';

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
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
            'newspack-content-migrator massterlist-migrate-jobs',
            array( $this, 'massterlist_migrate_jobs' ),
            array(
				'shortdesc' => 'Migrate jobs listings.',
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

		WP_CLI::add_command(
			'newspack-content-migrator massterlist-migrate-editions',
			array( $this, 'massterlist_migrate_editions' ),
			array(
				'shortdesc' => 'Migrate editions posts.',
				'synopsis'  => array(),
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
			$posts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM editions_posts WHERE edition_id = %d ORDER BY `order`;', $edition['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

			$edition_content = $this->generate_edition_content_from_posts( $posts, $edition );

			print_r( $edition_content );
			// print_r( $posts );
			die();
		}
	}

	private function generate_edition_content_from_posts( $posts, $edition ) {
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
				return ! in_array( $post['type'], [ 4, 8, 6 ] ) || ( 6 === intval( $post['type'] ) && 2000 < $post['order'] );
			}
        );

		$edition_content_blocks[] = $this->get_kal_content( $kal_posts );

		$edition_content_blocks[] = $this->get_ads_before_body_content( $ads_before_first_section );

		if ( 1 === ( new \DateTime( $edition['publish_date'] ) )->format( 'w' ) ) {
			var_dump( $edition );
			die();
		}

		$edition_content_blocks[] = $this->get_other_posts_content( $other_posts, $ads_before_second_section );

		if ( 1 !== ( new \DateTime( $edition['publish_date'] ) )->format( 'w' ) ) {
			$edition_content_blocks[] = $this->get_jobs_content( $job_posts );
		}

		$edition_content_blocks[] = $this->generate_title_block( 'How to Contact MASSterList' );
		$edition_content_blocks[] = $this->generate_paragraph_block( "Send tips to Matt Murphy: Editor@MASSterList.com. For advertising inquiries and job board postings, please contact Dylan Rossiter: Publisher@MASSterList.com or (857) 370-1156. Follow <a href='https://twitter.com/massterlist'>@MASSterList</a> on Twitter." );

		return join( "\n", $edition_content_blocks );
	}

	private function get_kal_content( $posts ) {
		$blocks = count( $posts ) > 0 ? [ $this->generate_title_block( 'Keller at Large' ) ] : [];

		foreach ( $posts as $post ) {
			$blocks[] = $this->generate_post_content( $post );
		}

		return join( "\n", $blocks );
	}

	private function get_ads_before_body_content( $posts ) {
		$blocks = [];

		foreach ( $posts as $post ) {
			$blocks[] = $this->generate_image_block( $post );
		}

		return join( "\n", $blocks );
	}

	private function get_other_posts_content( $posts, $ads_posts ) {
		$blocks        = [];
		$grouped_posts = $this->group_posts_by( $posts, 'type' );

		if ( array_key_exists( 1, $grouped_posts ) ) {
			$blocks[] = $this->generate_title_block( 'Happening Today' );
			foreach ( $grouped_posts[1] as $post ) {
				$blocks[] = $this->generate_post_content( $post );
			}
		}

		$blocks[] = $this->get_ads_before_body_content( $ads_posts );

		if ( array_key_exists( 2, $grouped_posts ) ) {
			$blocks[] = $this->generate_title_block( "Today's Stories" );

			foreach ( $grouped_posts[2] as $post ) {
				$sponsored = $post['type'] == 5;
				$blocks[]  = $this->generate_post_content( $post, $sponsored );
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

	private function generate_title_block( $title, $level = 2, $is_italic = false, $centered = false ) {
		if ( empty( $title ) ) {
			return '';
		}

		$tag   = "h$level";
		$level = 2 === $level ? '' : ' {' . ( $centered ? '"textAlign":"center",' : '' ) . '"level":' . $level . '}';
		$title = $is_italic ? "<em>$title</em>" : $title;
		return "<!-- wp:heading$level --><$tag" . ( $centered ? ' class="has-text-align-center"' : '' ) . ">$title</$tag><!-- /wp:heading -->";
	}

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

	private function generate_link_block( $post, $with_title = false ) {
		if (
			! array_key_exists( 'linkurl', $post ) || empty( $post['linkurl'] )
			|| ! array_key_exists( 'linktext', $post ) || empty( $post['linktext'] )
		) {
			return '';
		}
		return '<!-- wp:paragraph --><p><a href="' . $post['linkurl'] . '" data-type="URL">' . ( $with_title ? $post['title'] . ' - ' : '' ) . $post['linktext'] . '</a></p><!-- /wp:paragraph -->';
	}

	private function generate_job_link_block( $post ) {
		if (
			! array_key_exists( 'linkurl', $post ) || empty( $post['linkurl'] )
			|| ! array_key_exists( 'body', $post ) || empty( $post['body'] )
		) {
			return '';
		}
		return '<!-- wp:paragraph --><p><a href="' . $post['linkurl'] . '" data-type="URL">' . $post['title'] . ', ' . $post['body'] . '</a></p><!-- /wp:paragraph -->';
	}

	private function generate_image_block( $post ) {
		if ( ! array_key_exists( 'imageurl', $post ) || empty( $post['imageurl'] ) ) {
			return '';
		}

		$with_link = array_key_exists( 'linkurl', $post ) && ! empty( $post['linkurl'] );
		$image_url = 'http://massterlist.com/images/' . $post['imageurl'];
		$image_id  = $this->downloader->import_external_file(
			$image_url,
			$post['imageurl'],
			null,
			null,
			$post['alttext']
		);

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

	private function generate_separator_block() {
		return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';
	}

	private function group_posts_by( $posts, $attribute ) {
		$grouped_posts = array();

		foreach ( $posts as $item ) {
			$post_type                     = in_array( $item[ $attribute ], [ 2, 5, 6, 7, 8 ] ) ? 2 : $item[ $attribute ];
			$grouped_posts[ $post_type ][] = $item;
		}

		ksort( $grouped_posts, SORT_NUMERIC );

		return $grouped_posts;
	}

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
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
