<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DOMElement;
use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use Newspack_Scraper_Migrator_HTML_Parser;
use Newspack_Scraper_Migrator_Util;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
use WP_CLI;

/**
 * Custom migration scripts for Lookout Local.
 */
class LookoutLocalMigrator implements InterfaceCommand {

	const META_MEDIA_CREDIT              = '_media_credit';
	const META_IMAGE_ORIGINAL_URL        = 'newspackmigration_image_original_url';
	const META_POST_ORIGINAL_URL         = 'newspackmigration_url';
	const DATA_EXPORT_TABLE              = 'Record';
	const CUSTOM_ENTRIES_TABLE           = 'newspack_entries';
	const LOOKOUT_S3_SCHEMA_AND_HOSTNAME = 'https://lookout-local-brightspot.s3.amazonaws.com';

	const META_POST_LAYOUT_REGULAR     = 'newspackmigration_layout_regular';
	const META_POST_LAYOUT_STORY_STACK = 'newspackmigration_layout_story_stack';
	const META_POST_LAYOUT_YOUTUBE     = 'newspackmigration_layout_youtube_video';
	const META_POST_LAYOUT_VIMEO       = 'newspackmigration_layout_vimeo_video';
	const META_POST_LAYOUT_SLIDESHOW   = 'newspackmigration_layout_slideshow';

	/**
	 * Extracted from nav menu:
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/city-life">City Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/food-drink">Food &amp; Drink</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/places">Housing</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/civic-life">Civic Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/education/higher-ed">Higher Ed</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/education">K-12 Education</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/coast-life">Coast Life</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/wallace-baine">Wallace Baine</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/environment">Environment</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/health-wellness">Health &amp; Wellness</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/business-technology">Business &amp; Technology</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/recreation-sports">Recreation &amp; Sports</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/election-2022">Election 2022 </a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/santa-cruz-county-obituaries">Obituaries</a>
	 *
     * -- no content found in SITE MAP:
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/lookout-educator-page">For Educators</a>
	 *
	 * -- not importing these programmatically, as agreed with the Publisher:
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners/civic-groups">Civic Groups</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners">Partners</a>
	 */
	const SECTIONS = [
		'city-life'                    => 'City Life',
		'food-drink'                   => 'Food & Drink',
		'places'                       => 'Housing',
		'civic-life'                   => 'Civic Life',
		'higher-ed'                    => 'Higher Ed',
		'education'                    => 'K-12 Education',
		'coast-life'                   => 'Coast Life',
		'wallace-baine'                => 'Wallace Baine',
		'environment'                  => 'Environment',
		'health-wellness'              => 'Health &amp; Wellness',
		'business-technology'          => 'Business &amp; Technology',
		'recreation-sports'            => 'Recreation &amp; Sports',
		'election-2022'                => 'Election 2022 ',
		'santa-cruz-county-obituaries' => 'Obituaries',
		'civic-groups'                 => 'Civic Groups',
		'partners'                     => 'Partners',
		'lookout-educator-page'        => 'For Educators',
	];

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments Instance.
	 */
	private $attachments;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * DomCrawler instance.
	 *
	 * @var Crawler Crawler instance.
	 */
	private $crawler;

	/**
	 * Scraper instance.
	 *
	 * @var Newspack_Scraper_Migrator_Util Instance.
	 */
	private $scraper;

	/**
	 * Parser instance.
	 *
	 * @var Newspack_Scraper_Migrator_HTML_Parser Instance.
	 */
	private $data_parser;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus Instance.
	 */
	private $cap;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * Gutenberg block generator.
	 *
	 * @var GutenbergBlockGenerator Gutenberg block generator.
	 */
	private $gutenberg;

	/**
	 * Redirection instance.
	 *
	 * @var Redirection Redirection instance.
	 */
	private $redirection;

	/**
	 * HtmlElementManipulator instance.
	 *
	 * @var HtmlElementManipulator HtmlElementManipulator instance.
	 */
	private $html_element_manipulator;

	/**
	 * Used as a development QA helper.
	 * If set, no images will actually be downloaded from live, and this image will be used instead. This will prevent all image downloads and speed up dev and QA.
	 *
	 * @var string Path to a demo image.
	 */
	private $dev_fake_image_override;

	/**
	 * Constructor.
	 */
	private function __construct() {

		// If on Atomic.
		if ( '/srv/htdocs/__wp__/' == ABSPATH ) {
			$public_path    = '/srv/htdocs';
			$plugin_dir     = $public_path . '/wp-content/plugins/newspack-custom-content-migrator';
		} else {
			$public_path    = rtrim( ABSPATH, '/' );
			$plugin_dir     = $public_path . '/wp-content/plugins/newspack-custom-content-migrator';
		}

		// Newspack_Scraper_Migrator is not autoloaded.
		require realpath( $plugin_dir . '/vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-util.php' );
		require realpath( $plugin_dir . '/vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-html-parser.php' );

		$this->attachments              = new Attachments();
		$this->logger                   = new Logger();
		$this->scraper                  = new Newspack_Scraper_Migrator_Util();
		$this->crawler                  = new Crawler();
		$this->data_parser              = new Newspack_Scraper_Migrator_HTML_Parser();
		$this->cap                      = new CoAuthorPlus();
		$this->posts                    = new Posts();
		$this->gutenberg                = new GutenbergBlockGenerator();
		$this->redirection              = new Redirection();
		$this->html_element_manipulator = new HtmlElementManipulator();
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
			'newspack-content-migrator lookoutlocal-scrape1--get-all-urls-from-sitemap',
			[ $this, 'cmd_scrape1__get_urls_from_sitemap' ],
			[
				'shortdesc' => 'Gets list of URLs from sitemap to be scraped.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-save-list-of-urls',
						'description' => 'Path where to save list of URLs -- 0__all_urls.txt file.',
						'optional'    => false,
					],
				],
			]

		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-scrape2--scrape-htmls',
			[ $this, 'cmd_scrape2__scrape_htmls' ],
			[
				'shortdesc' => 'Run after `scrape1` command. Scrapes HTMLs from live and saves them to html files.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file-list-of-urls',
						'description' => 'Path to the 0__all_urls.txt file produced by previous command lookoutlocal-scrape1-get-all-urls-from-sitemap.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'path-to-save-htmls',
						'description' => 'Path where scraped HTML files will be saved to.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-import1--create-posts',
			[ $this, 'cmd_import1__create_posts' ],
			[
				'shortdesc' => 'Imports scraped HTMLs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-htmls',
						'description' => 'Path to scraped .html files.',
						'optional'    => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'reimport-posts',
						'description' => 'If this flag is set, will reimport all HTML -> post data. Otherwise posts that were already imported will be skipped.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dev-override-fake-image-path',
						'description' => 'Development helper. Path to a demo image. If set, will not actually download live image, but simply reuse this image for all downloads, and speed up dev and QA imports.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-import2--content-transform-and-cleanup',
			[ $this, 'cmd_after_import2__content_transform_and_cleanup' ],
			[
				'shortdesc' => 'Run after `import1` command. Transforms and cleans up imported content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv-file',
						'description' => 'Optional list of post IDs to transform only. Preceeds --post-ids-csv.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Optional list of post IDs to transform only.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dev-override-fake-image-path',
						'description' => 'Development helper. Path to a demo image. If set, will not actually download live image, but simply reuse this image for all downloads, and speed up dev and QA imports.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-create-posts-redirects',
			[ $this, 'cmd_create_posts_redirects' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-excerpts-to-subtitles',
			[ $this, 'cmd_excerpts_to_subtitles' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-download-images-data-src-cdn',
			[ $this, 'cmd_download_images_data_src_cdn' ],
		);

		// WP_CLI::add_command(
		// 	'newspack-content-migrator lookoutlocal-scrape-posts',
		// 	[ $this, 'cmd_scrape_posts' ],
		// 	[
		// 		'shortdesc' => 'Main command. Scrape posts from live and imports them. Make sure to run lookoutlocal-create-custom-table first.',
		// 		'synopsis'  => [
		// 			[
		// 				'type'        => 'assoc',
		// 				'name'        => 'urls-file',
		// 				'description' => 'File with URLs to scrape and import, one URL per line.',
		// 				'optional'    => true,
		// 			],
		// 		],
		// 	]
		// );

		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-deprecated-create-custom-table',
			[ $this, 'cmd_create_custom_table' ],
			[
				'shortdesc' => 'Extracts all posts JSONs from the huge `Record` table into a new custom table called self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-deprecated-get-urls-from-record-table',
			[ $this, 'cmd_get_urls_from_record_table' ],
			[
				'shortdesc' => 'This tries to extract live post URLs from Record and custom Newspack table. Make sure to run lookoutlocal-create-custom-table first.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'urls-csv',
						'description' => 'List of post URLs to scrape and import.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-deprecated-import-posts-programmatically',
			[ $this, 'cmd_deprecated_import_posts' ],
			[
				'shortdesc' => 'Tried to see if we can programmatically get all relational data from `Record` table. But the answer is no -- it is simply too dificult, better to scrape. (old description: Imports posts from JSONs in  self::CUSTOM_ENTRIES_TABLE.)',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev',
			[ $this, 'cmd_dev' ],
			[
				'shortdesc' => 'Temp dev command for various snippets.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev-delete-all-posts',
			[ $this, 'cmd_dev_delete_all_posts' ],
			[
				'shortdesc' => 'Careful. Deletes all posts.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev-prepare-html-files-for-import',
			[ $this, 'cmd_dev_prepare_html_files_for_import' ],
			[
				'shortdesc' => 'Temp dev command.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file-with-urls',
						'description' => 'List of post URLs to scrape and import.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-html-folder',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'destination-html-folder',
						'optional'    => false,
					],
				],
			]

		);
	}

	public function cmd_download_images_data_src_cdn( $pos_args, $assoc_args ) {

		global $wpdb;
		$cdn_host = 'lookout.brightspotcdn.com';
		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {

			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			$matches = $this->html_element_manipulator->match_elements_with_self_closing_tags( 'img', $post_content );
			if ( ! $matches || ! isset( $matches[0] ) ) {
				continue;
			}

			$post_content_updated = $post_content;
			foreach ( $matches[0] as $match ) {
				$img = $match[0];
				$data_src = $this->html_element_manipulator->get_attribute_value( 'data-src', $img );
				if ( false === strpos( $data_src, '//' . $cdn_host ) ) {
					continue;
				}

				WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

				// Download image.
				WP_CLI::line( sprintf( 'Downloading %s', $data_src ) );
				$att_id = $this->attachments->import_external_file(
					$data_src, $title = null, $caption = null, $description = null, $alt = null, $post_id, $args = [], $desired_filename = ''
				);

				if ( ! $att_id || is_wp_error( $att_id ) ) {
					$d=1;
				}
				WP_CLI::line( sprintf( 'Att ID %d', $att_id ) );

				// Get attachment ID URL.
				$att_url = wp_get_attachment_url( $att_id );
				if ( ! $att_url ) {
					$d=1;
				}

				// Update image HTML.
				$img_updated = $img;

				// Update src.
				// Get src value.
				$pos_src_minus_1 = strpos( $img_updated, ' src="' );
				$pos_src = $pos_src_minus_1 + 1;
				$pos_src_end = strpos( $img_updated, '"', $pos_src + 5 );
				$src = substr( $img_updated, $pos_src, $pos_src_end - $pos_src + 1 );
				// Replace src
				if ( $pos_src && $pos_src_end && ($pos_src_end > $pos_src ) ) {
					$src_new = sprintf( 'src="%s"', $att_url );
					$img_updated = str_replace( $src, $src_new, $img_updated );
				} else {
					$d=1;
				}

				// Remove data-src.
				// Get data-src value.
				$pos_datasrc = strpos( $img_updated, 'data-src="' );
				$pos_datasrc_end = strpos( $img_updated, '"', $pos_datasrc + 10 );
				$datasrc = substr( $img_updated, $pos_datasrc, $pos_datasrc_end - $pos_datasrc + 1 );
				$img_updated = str_replace( $datasrc, '', $img_updated );

				// Update image HTML.
				$post_content_updated = str_replace( $img, $img_updated, $post_content_updated );
			}

			// Update post.
			if ( $post_content_updated != $post_content ) {
				$d=1;
				$wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $post_content_updated,
					],
					[ 'ID' => $post_id ],
				);
				WP_CLI::success( sprintf( 'Updated post ID %d', $post_id ) );
			}

		}

		wp_cache_flush();
	}

	public function cmd_excerpts_to_subtitles( $pos_args, $assoc_args ) {
		global $wpdb;

		$log = 'excerpts_to_summary.log';
		$log_empty_skipped = 'excerpts_to_summary_skipped_empty.log';

		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Get excerpt.
			$excerpt = $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			if ( ! $excerpt ) {
				$this->logger->log( $log_empty_skipped, sprintf( "ID %d SKIPPED, EXCERPT EMPTY", $post_id, false )  );
				continue;
			}

			// Get current summary title and body.
			$summary_title_old = get_post_meta( $post_id, 'newspack_article_summary_title', true );
			$summary_body_old  = get_post_meta( $post_id, 'newspack_article_summary', true );

			// Update summary title and body.
			update_post_meta( $post_id, 'newspack_article_summary_title', 'Quick Take:' );
			update_post_meta( $post_id, 'newspack_article_summary', $excerpt );

			$this->logger->log( $log, sprintf( "ID %d\n- OLD_SUMMARY_TITLE: %s\n- OLD_SUMMARY_BODY: %s", $post_id, $summary_title_old, $summary_body_old, false )  );

			WP_CLI::success( 'Updated' );
		}
	}

	/**
	 * Callable for 'newspack-content-migrator lookoutlocal-create-posts-redirects'.
	 *
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_create_posts_redirects( $pos_args, $assoc_args ) {

		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );

		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$url_original = get_post_meta( $post_id, self::META_POST_ORIGINAL_URL, true );
			if ( ! $url_original ) {
				WP_CLI::warning( sprintf( 'No %s for post ID %d', self::META_POST_ORIGINAL_URL, $post_id ) );
				continue;
			}

			// Rule title.
			$rule_title = sprintf( 'Migrated post ID %d', $post_id );

			// Remove hostname and scheme from $url_from.
			$parsed_url_original = parse_url( $url_original );
			$url_from = $parsed_url_original['path'] . ( isset( $parsed_url_original[ 'query' ] ) ? '?' . $parsed_url_original[ 'query' ] : '' );

			$exists = $this->redirection->get_redirects_by_exact_from_url( $url_from );
			if ( ! empty( $exists ) ) {
				WP_CLI::line( sprintf( 'Redirect exists, skipping post ID %d', $post_id ) );
				continue;
			}

			// Get $post_id's redirection URL.
			$url_to = get_permalink( $post_id );
			$parsed_url_to = parse_url( $url_to );
			$url_to = $parsed_url_to['path'] . ( isset( $parsed_url_to[ 'query' ] ) ? '?' . $parsed_url_to[ 'query' ] : '' );

			// Create.
			$this->redirection->create_redirection_rule( $rule_title, $url_from, $url_to );
			WP_CLI::success( sprintf( 'Created redirect ID %d FROM: %s TO: %s', $post_id, $url_original, $url_to ) );
		}
	}

	/**
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_scrape1__get_urls_from_sitemap( $pos_args, $assoc_args ) {

		$path = $assoc_args['path-to-save-list-of-urls'];
		if ( ! file_exists( $path ) ) {
			mkdir( $path, 0777, true );
		}

		// Save list of URLs here.
		$log = '0__all_urls.txt';
		$log_path = $path . '/' . $log;

		// Hardcoded here is their sitemap index URL.
		$sitemap_index_url = 'https://lookout.co/santacruz/sitemap.xml';

		WP_CLI::line( "Fetching URLs from sitemap index $sitemap_index_url , please hold ..." );
		$urls = $this->fetch_and_parse_sitemap_index( $sitemap_index_url );

		if ( ! empty( $urls ) ) {
			@unlink( $log_path );
			foreach ( $urls as $url_data ) {
				file_put_contents( $log_path, $url_data['loc'] . "\n", FILE_APPEND );
			}
			WP_CLI::success( 'Done. URLs saved to ' . $log_path );
		} else {
			WP_CLI::error( 'Failed to retrieve sitemap index or URLs.' );
		}
	}

	/**
	 * Fetch and parse URLs from a sitemap index.
	 *
	 * @param $sitemap_index_url
	 *
	 * @return array
	 */
	public function fetch_and_parse_sitemap_index( $sitemap_index_url ) {
		$xml = file_get_contents( $sitemap_index_url );
		if ( false === $xml ) {
			return [];
		}

		$xml = simplexml_load_string( $xml );
		if ( $xml === false ) {
			return [];
		}

		$all_urls = [];
		foreach ( $xml->sitemap as $sitemap ) {
			$sitemap_url = (string) $sitemap->loc;
			$urls = $this->fetch_and_parse_sitemap( $sitemap_url );

			// Merge the URLs from this sitemap into the result array
			$all_urls = array_merge( $all_urls, $urls );
		}

		return $all_urls;
	}

	/**
	 * Fetch and parse URLs from a sitemap.
	 *
	 * @param $sitemap_url
	 *
	 * @return array
	 */
	public function fetch_and_parse_sitemap( $sitemap_url ) {
		$xml = file_get_contents( $sitemap_url );
		if ( false === $xml ) {
			return [];
		}

		$xml = simplexml_load_string( $xml );
		if ( false === $xml ) {
			return [];
		}

		$urls = [];
		foreach ( $xml->url as $url ) {
			$loc = (string) $url->loc;
			$lastmod = (string) $url->lastmod;

			$urls[] = [
				'loc' => $loc,
				'lastmod' => $lastmod,
			];
		}

		return $urls;
	}

	public function cmd_get_urls_from_record_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Log files.
		if ( ! file_exists( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0777, true );
		}
		$log_urls           = $this->temp_dir . '/ll__get_urls_from_db.log';
		$log_urls_not_found = $this->temp_dir . '/ll_debug__urls_not_found.log';

		// Hit timestamp on logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_urls, $ts, false );
		$this->logger->log( $log_urls_not_found, $ts, false );


		// Create folders for caching stuff.
		// Cache section (category) data to files (because SQLs on `Result` table are super slow).
		$section_data_cache_path = $this->temp_dir . '/cache_sections';
		if ( ! file_exists( $section_data_cache_path ) ) {
			mkdir( $section_data_cache_path, 0777, true );
		}

		/**
		 * Loop through all the rows from Newspack custom table and get their URLs.
		 * URLs are hard to find, since we must crawl their DB export and search through relational data, and all queries are super slow since it's one 6 GB table.
		 */

		// Get rows from our custom posts table (table was created by command lookoutlocal-create-custom-table).
		$entries_table       = self::CUSTOM_ENTRIES_TABLE;
		$newspack_table_rows = $wpdb->get_results( "select slug, data from {$entries_table}", ARRAY_A );

		// QA and debugging vars.
		$urls           = [];
		$urls_not_found = [];

		/**
		 * @var array $posts_urls All pposts URL data is stored in this array. {
		 *      @type string slug Post slug.
		 *      @type string url  Post url.
		 * }
		 */
		$posts_urls = [];
		foreach ( $newspack_table_rows as $key_row => $newspack_table_row ) {

			$row_data = json_decode( $newspack_table_row['data'], true );
			$slug     = $newspack_table_row['slug'];

			WP_CLI::line( sprintf( '%d/%d Getting URL for slug %s ...', $key_row + 1, count( $newspack_table_rows ), $slug ) );

			// Get post URL.
			$url_data = $this->get_post_url( $newspack_table_row, $section_data_cache_path );
			$url      = $url_data['url'] ?? null;
			if ( ! $url ) {
				$this->logger->log( $log_urls_not_found, sprintf( 'Not found URL for slug %s', $newspack_table_row['slug'] ), $this->logger::WARNING );
				$urls_not_found[] = $slug;
				continue;
			}

			$this->logger->log( $log_urls, $url, false );
			$urls[] = $url;
		}

		if ( ! empty( $urls_not_found ) ) {
			WP_CLI::warning( "❗️ Some URLs not found, see $log_urls_not_found" );
		}
		if ( ! empty( $urls ) ) {
			WP_CLI::warning( "👍 URLs saved to $log_urls" );
		}
	}

	/**
	 * @param $url
	 * @param $scraped_htmls_cache_path
	 *
	 * @return array Error messages if they occurred during GA info update.
	 */
	public function update_author_info( $url, $scraped_htmls_cache_path ) {
		global $wpdb;

		$errs_updating_gas = [];

		// HTML cache filename and path.
		$html_cached_filename  = $this->sanitize_filename( $url, '.html' );
		$html_cached_file_path = $scraped_htmls_cache_path . '/' . $html_cached_filename;

		// Get author page from cache if exists.
		$html = file_exists( $html_cached_file_path ) ? file_get_contents( $html_cached_file_path ) : null;
		if ( is_null( $html ) ) {

			// Remote get author page from live.
			$get_result = $this->wp_remote_get_with_retry( $url );
			if ( is_wp_error( $get_result ) || is_array( $get_result ) ) {
				// Not OK.
				$msg = is_wp_error( $get_result ) ? $get_result->get_error_message() : $get_result['response']['message'];
				$errs_updating_gas[] = sprintf( 'URL: %s CODE: %s MESSAGE: %s', $url, $get_result['response']['code'], $msg );
				return;
			}

			$html = $get_result;

			// Cache HTML to file.
			file_put_contents( $html_cached_file_path, $html );
		}

		// Crawl and extract all useful data from author page HTML.
		$crawled_data = $this->crawl_author_data_from_html( $html, $url );

		// Get or create GA.
		$ga = $this->cap->get_guest_author_by_display_name( $crawled_data['name'] );
		if ( ! $ga ) {
			$ga = $this->cap->create_guest_author( [ 'display_name' => $crawled_data['name'] ] );
		}

		// GA data to update.
		$ga_update_arr = [];

		// Name is being referenced, so that stays the same.

		// Avatar -- only import and update if not already set, because we'd be importing dupes to the Media Library.
		$ga_avatar_att_id = get_post_meta( $ga->ID, '_thumbnail_id', true );
		if ( ! $ga_avatar_att_id && $crawled_data['avatar_url'] ) {
			WP_CLI::line( sprintf( "Downloading avatar URL for author '%s' ...", $crawled_data['name'] ) );

			// First fetch attachment from Media Library if it already exists.
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s",
				self::META_IMAGE_ORIGINAL_URL,
				$crawled_data['avatar_url']
			) );
			if ( ! $attachment_id ) {
				// Download.
				$attachment_id = $this->attachments->import_external_file( $crawled_data['avatar_url'], $crawled_data['name'] );
			}

			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$errs_updating_gas[] = sprintf( "Error importing avatar image %s for author '%s' ERR: %s", $crawled_data['avatar_url'], $crawled_data['name'], is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : '/na' );
			} else {
				$ga_update_arr['avatar'] = $attachment_id;
			}
		}

		// Compose social links sentence.
		$social_blank = 'Follow ' . $crawled_data['name'] . ' on: ';
		$social       = $social_blank;
		$link_fn      = function( $href, $text ) {
			return sprintf( '<a href="%s" target="_blank" rel="noreferrer">%s</a>', $href, $text );
		};
		if ( isset( $crawled_data['social_twitter'] ) && ! empty( $crawled_data['social_twitter'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_twitter'], 'Twitter' );
		}
		if ( isset( $crawled_data['social_instagram'] ) && ! empty( $crawled_data['social_instagram'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_instagram'], 'Instagram' );
		}
		if ( isset( $crawled_data['social_facebook'] ) && ! empty( $crawled_data['social_facebook'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_facebook'], 'Facebook' );
		}
		if ( isset( $crawled_data['social_linkedin'] ) && ! empty( $crawled_data['social_linkedin'] ) ) {
			$social .= ( $social_blank != $social ) ? ', ' : '';
			$social .= $link_fn( $crawled_data['social_linkedin'], 'LinkedIn' );
		}

		// Bio = $social . $bio.
		$ga_update_arr['description'] = '';
		if ( $social_blank != $social ) {
			$ga_update_arr['description'] .= $social;
		}
		if ( $crawled_data['bio'] ) {
			$ga_update_arr['description'] .= ! empty( $ga_update_arr['description'] ) ? '. ' : '';
			$ga_update_arr['description'] .= $crawled_data['bio'];
		}

		// Email.
		if ( isset( $crawled_data['social_email'] ) && ! empty( $crawled_data['social_email'] ) ) {
			$ga_update_arr['user_email'] = $crawled_data['social_email'];
		}

		// Title.
		if ( $crawled_data['title'] ) {
			$ga_update_arr['job_title'] = $crawled_data['title'];
		}

		// Update the GA.
		$this->cap->update_guest_author( $ga->ID, $ga_update_arr );
		WP_CLI::success(
			sprintf(
				'Updated GA %s from %s',
				sprintf(
					'https://%s/wp-admin/post.php?post=%d&action=edit',
					wp_parse_url( get_site_url() )['host'],
					$ga->ID,
				),
				$url
			)
		);

		return $errs_updating_gas;
	}

	/**
	 * Fetches image attachment ID from Media Library, or downloads it and creates it if not.
	 *
	 * @param string  $src
	 * @param ?string $title
	 * @param ?string $caption
	 * @param ?string $description
	 * @param ?string $alt
	 * @param ?int    $post_id
	 * @param ?string $credit
	 * @param array   $args
	 *
	 * @return ?int Attachment ID, or null if error.
	 */
	public function get_or_download_image(
		$log,
		$src,
		$title = null,
		$caption = null,
		$description = null,
		$alt = null,
		$post_id = null,
		$credit = null,
		$args = []
	) {
		global $wpdb;

		// First fetch attachment from Media Library if it already exists.
		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s",
			self::META_IMAGE_ORIGINAL_URL,
			$src
		) );
		if ( $attachment_id ) {

			// Update meta before returning.

			$post_arr = [];
			$post_arr['post_title'] = $title;
			$post_arr['post_excerpt'] = $caption;
			$post_arr['post_content'] = $description;
			$wpdb->update( $wpdb->posts, $post_arr, [ 'ID' => $attachment_id ] );

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

			return $attachment_id;
		}

		// Download and import attachment.
		if ( $this->dev_fake_image_override ) {
			$src = $this->dev_fake_image_override;
		}
		WP_CLI::line( sprintf( "Downloading image '%s' ...", $src ) );
		$attachment_id = $this->attachments->import_external_file(
			$src,
			$title,
			$caption,
			$description,
			$alt,
			$post_id,
			$args
		);


		/**
		 * In case of "Sorry, you are not allowed to upload this file type.",
		 * retry downloading image from "//lookout.brightspotcdn.com/" by using &url GET param
		 * by swapping downloaded local tmp file's extension
		 * from .tmp to actual extension (e.g. .jpg) lets it go through.
		 */
		if (
			is_wp_error( $attachment_id )
			&& ( false !== strpos( $src, '//lookout.brightspotcdn.com/' ) )
			&& ( false != strpos( $attachment_id->get_error_message(), 'not allowed to upload this file type' ) )
		) {
			WP_CLI::line( sprintf( "Retrying to download image with manual handling ...", $src ) );
			$src_parsed = parse_url( $src );
			$new_extension  = null;
			foreach (  explode( '&', $src_parsed['query'] ) as $param ) {
				/**
				 * Image $url could look like this:
				 *      https://lookout.brightspotcdn.com/dims4/default/6bf45a2/2147483647/strip/true/crop/2000x1333+0+0/resize/1680x1120!/quality/90/?url=https%3A%2F%2Fi0.wp.com%2Fcalmatters.org%2Fwp-content%2Fuploads%2F2023%2F06%2F062023-Unhoused-LA-JAH-CM-40.jpg%3Fw%3D2000%26ssl%3D1
				 * Now let's get the URL from the &url= GET param:
				 *      ?url=https%3A%2F%2Fi0.wp.com%2Fcalmatters.org%2Fwp-content%2Fuploads%2F2023%2F06%2F062023-Unhoused-LA-JAH-CM-40.jpg%3Fw%3D2000%26ssl%3D1
				 * and decode it to get this:
				 *      https://i0.wp.com/calmatters.org/wp-content/uploads/2023/06/062023-Unhoused-LA-JAH-CM-40.jpg?w=2000&ssl=1
				 * and finally remove the GET query from it to get this:
				 *      https://i0.wp.com/calmatters.org/wp-content/uploads/2023/06/062023-Unhoused-LA-JAH-CM-40.jpg
				 * Now we can get the actual image extension from this URL.
				 */

				// Get URL from &url= GET param.
				$url_from_url_get_param = ( 0 == strpos( $param, 'url=' ) ) ? urldecode( substr( $param, 4 ) ) : null;

				// Now remove the GET query from $url_from_url_get_param.
				$url_from_url_get_param_parsed = parse_url( $url_from_url_get_param );
				$url_from_url_get_param_wo_get_params = $url_from_url_get_param_parsed['scheme'] . '://' . $url_from_url_get_param_parsed['host'] . $url_from_url_get_param_parsed['path'];

				// Get extension from URL.
				$new_extension = pathinfo( $url_from_url_get_param_wo_get_params, PATHINFO_EXTENSION );
			}

			// Download this file to local tmp again.
			$tmp_file           = download_url( $src );
			$tmp_file_extension = pathinfo( $tmp_file, PATHINFO_EXTENSION );

			// Rename $tmp_file's extension from e.g. 'tmp' to e.g. 'jpg'.
			$tmp_file_new = preg_replace( '/' . $tmp_file_extension . '$/', $new_extension, $tmp_file );
			rename( $tmp_file, $tmp_file_new );

			// Now try to import the local tmp file with the new extension.
			$attachment_id = $this->attachments->import_external_file(
				$tmp_file_new,
				$title,
				$caption,
				$description,
				$alt,
				$post_id,
				$args
			);

			if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
				WP_CLI::success( sprintf( "Imported attachment ID %d", $attachment_id ) );
			}
		}

		// Early return if attachment import failed.
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {

			// TODO -- log failed attachment import
			// Also log $url_from_get_param if ! is_null()
			$this->logger->log(
				$log,
				sprintf(
					"Failed to download attachment %s post_id %d ERR %s",
					$src,
					$post_id,
					is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/'
				)
			);
			return null;
		}


		// Save original URL as meta.
		update_post_meta( $attachment_id, self::META_IMAGE_ORIGINAL_URL, $src );

		// Save credit as Newspack credit.
		if ( $credit ) {
			// If starts with ( and ends with ), remove them.
			if ( 0 == strpos( $credit, '(' ) && ')' == substr( $credit, -1 ) ) {
				$credit = trim( $credit, '()');
			}
			update_post_meta( $attachment_id, self::META_MEDIA_CREDIT, $credit );
		}


		return $attachment_id;
	}

	/**
	 * Crawls all useful post data from HTML.
	 *
	 * @param string $html HTML.
	 * @param string $url  URL.
	 *
	 * @return array $data All posts data crawled from HTML. {
	 *      @type array   script_data            Decoded data from that one <script> element with useful post info.
	 *      @type string  post_title
	 *      @type ?string presented_by
	 * }
	 */
	public function crawl_author_data_from_html( $html, $url ) {

		$data = [];

		/**
		 * Get all post data.
		 */
		$this->crawler->clear();
		$this->crawler->add( $html );

		// Name
		$data['name'] = trim( $this->filter_selector( 'div.page-bio > h1.page-bio-author-name', $this->crawler ) );

		// Avatar image.
		$avatar_crawler     = $this->filter_selector_element( 'div.page-intro-avatar > img', $this->crawler, $single = true );
		$data['avatar_url'] = $avatar_crawler ? $avatar_crawler->getAttribute( 'src' ) : null;

		// Title, e.g. Politics and Policy Correspondent.
		$data['title'] = $this->filter_selector( 'div.page-bio > p.page-bio-author-title', $this->crawler );

		// Bio.
		$data['bio'] = $this->filter_selector( 'div.page-bio > div.page-bio-author-bio', $this->crawler );

		// Social links. Located in ul.social-bar-menu > li > a > href.
		$ul_crawler = $this->filter_selector_element( 'ul.social-bar-menu', $this->crawler, $single = true );
		// Also get entire ul.social-bar-menu HTML.
		$social_list_html               = $ul_crawler->ownerDocument->saveHTML( $ul_crawler );
		$data['social_links_full_html'] = $social_list_html ?? null;
		// <ul>
		if ( $ul_crawler ) {
			// <li>s
			$lis = $ul_crawler->getElementsByTagName( 'li' );
			foreach ( $lis as $li ) {
				// Get the first <a>.
				$as = $li->getElementsByTagName( 'a' );
				if ( $as && $as->count() > 0 ) {
					$a                   = $as[0];
					$a_html              = $a->ownerDocument->saveHTML( $a );
					$social_service_type = $a->getAttribute( 'data-social-service' );
					switch ( $social_service_type ) {
						case 'email':
							$data['social_email'] = str_replace( 'mailto:', '', $a->getAttribute( 'href' ) );
							break;
						case 'linkedin':
							// Oddly the href might have wrong value, e.g. "https://www.linkedin.com/in/https://www.linkedin.com/in/blaire-hobbs-2b278b1a0/".
							$href = $a->getAttribute( 'href' );
							// Get the last https:// occurrence in $href.
							$last_https_pos          = strrpos( $href, 'https://' );
							$href_cleaned            = substr( $href, $last_https_pos );
							$data['social_linkedin'] = $href_cleaned;
							break;
						case 'twitter':
							$href                   = $a->getAttribute( 'href' );
							$data['social_twitter'] = $href;
							break;
						case 'instagram':
							$href                     = $a->getAttribute( 'href' );
							$data['social_instagram'] = $href;
							break;
						case 'facebook':
							$href                    = $a->getAttribute( 'href' );
							$data['social_facebook'] = $href;
							break;
						default:
							throw new \UnexpectedValueException( sprintf( "A new type of social link type '%s' used on author page %s. Please update the migrator's crawl_author_data_from_html() method and add support for it.", $social_service_type, $url ) );
							break;
					}
				}
			}
		}

		return $data;
	}

	/**
	 * @param string $post_content HTML.
	 *
	 * @return string|null Cleaned HTML or null if this shouldn't be cleaned.
	 */
	public function clean_up_scraped_html( $post_id, $url, $post_content, $log_need_oembed_resave, $log_err_img_download, $log_unknown_div_enchancements ) {

		$post_content_updated = null;

		$this->crawler->clear();
		$this->crawler->add( $post_content );

		/**
		 * CONTENT TYPE 1. STORY STACK
		 *   - content is stored in: div.story-stack-story
		 *   - e.g. https://lookout.co/santacruz/coast-life/story/2023-05-19/pescadero-day-trip-sea-lions-ano-nuevo-award-winning-tavern-baby-goats
		 *
		 * This content gets "flattened"/properly encapsulated in div.rich-text-body and then crawled by "main crawler" below.
		 *
		 * To locate the content in the story stack HTML struvture, traverse through all div.story-stack-story and find two content elements:
		 *      - find .story-stack-story-title -- if exists, encapsulate it in a div.rich-text-body (so that it can be fed to the "main crawler")
		 *      - find div.rich-text-body (which contains the .story-stack-story-body) -- simply feed it to the crawler
		 */
		$story_stack_formatted_rich_text_body = '';
		$div_content_crawlers = $this->filter_selector_element( 'div.story-stack-story', $this->crawler, $single = false );
		$content_is_story_stack = (bool) $div_content_crawlers;
		if ( $content_is_story_stack ) {

			// Traverse through all div.story-stack-story elements.
			$story_crawler = $this->filter_selector_element( 'div.story-stack-story', $this->crawler, $single = false );
			foreach ( $story_crawler->getIterator() as $key_domelement => $story_stack_story_domelement ) {

				// Traverse through div.story-stack-story's child nodes.
				foreach ( $story_stack_story_domelement->childNodes->getIterator() as $key_domelement => $story_stack_story_child_domelement ) {

					if ( 'DOMElement' !== $story_stack_story_child_domelement::class ) {

						// If it's something other than the DOMElements we're searching for, encapsulate it in div.rich-text-body so that it can be fed to the crawler.
						$story_stack_formatted_rich_text_body .= '<div class="rich-text-body">';
						$story_stack_formatted_rich_text_body .= $story_stack_story_child_domelement->ownerDocument->saveHTML( $story_stack_story_child_domelement );
						$story_stack_formatted_rich_text_body .= '</div>';

					} else {

						// If it's .story-stack-story-title, encapsulate it in div.rich-text-body.
						$is_story_stack_story_title = false !== strpos( $story_stack_story_child_domelement->getAttribute( 'class' ), 'story-stack-story-title' );
						if ( $is_story_stack_story_title ) {
							$story_stack_formatted_rich_text_body .= '<div class="rich-text-body">';
							$story_stack_formatted_rich_text_body .= $story_stack_story_child_domelement->ownerDocument->saveHTML( $story_stack_story_child_domelement );
							$story_stack_formatted_rich_text_body .= '</div>';
						}

						// Find div.rich-text-body (also has .story-stack-story-body), and feed it to the crawler.
						$is_rich_text_body = ( isset( $story_stack_story_child_domelement->tagName ) && 'div' == $story_stack_story_child_domelement->tagName )
							&& ( false !== strpos( $story_stack_story_child_domelement->getAttribute( 'class' ), 'rich-text-body' ) );
						if ( $is_rich_text_body ) {
							$story_stack_formatted_rich_text_body .= $story_stack_story_child_domelement->ownerDocument->saveHTML( $story_stack_story_child_domelement );
						}
					}
				}
			}

			// Feed formatted HTML to rich-text-body crawler.
			$this->crawler->clear();
			$this->crawler->add( $story_stack_formatted_rich_text_body );

			// Reset $div_content_crawlers.
			$div_content_crawlers = null;
		}

		/**
		 * CONTENT TYPE 2.
		 *   - content is located in: div.rich-text-body
		 *   - e.g.
		 *
		 * Get all the outer content div.rich-text-body in which the body HTML is nested.
		 * There can also be multiple such divs so we loop through them and concatenate.
		 * This was back when I thought there can be only one such div:
		 *      $div_content_crawler = $this->filter_selector_element( 'div.rich-text-body', $this->crawler );
		 */
		if ( ! $div_content_crawlers ) {
			$div_content_crawlers = $this->filter_selector_element( 'div.rich-text-body', $this->crawler, $single = false );
		}


		// The "main crawler" $div_content_crawlers.
		if ( $div_content_crawlers ) {

			foreach ( $div_content_crawlers as $div_content_crawler ) {

				// Traverse all the child nodes.
				foreach ( $div_content_crawler->childNodes->getIterator() as $key_domelement => $domelement ) {
					// Skip if blank.
					$html_domelement = $domelement->ownerDocument->saveHTML( $domelement );
					if ( empty( trim( $html_domelement ) ) ) {
						continue;
					}

					// Transform or skip div.enhancement elements.
					$custom_html = null;
					$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( 'enhancement' == $domelement->getAttribute( 'class' ) );
					if ( $is_div_class_enhancement ) {
						$custom_html = $this->transform_div_enchancement( $domelement, $post_id, $url, $log_need_oembed_resave, $log_err_img_download, $log_unknown_div_enchancements );
					}

					// Value of $custom_html determines if the element's original HTML or the custom HTML will be used.
					if ( ! is_null( $custom_html ) ) {
						// Use the custom HTML.
						$domelement_html = $custom_html;
					} else {
						// Keep this $domelement's original HTML.
						$domelement_html = $domelement->ownerDocument->saveHTML( $domelement );
						$domelement_html = trim( $domelement_html );
						if ( empty( $domelement_html ) ) {
							continue;
						}
					}

					// Append HTML to post_content updated variable.
					$post_content_updated .= ! empty( $post_content_updated ) ? "\n" : '';
					$post_content_updated .= $domelement_html;
				}
			}
		}

		$is_yt_video = self::META_POST_LAYOUT_YOUTUBE == get_post_meta( $post_id, 'newspackmigration_layouttype', true );
		$is_vimeo_video = self::META_POST_LAYOUT_VIMEO == get_post_meta( $post_id, 'newspackmigration_layouttype', true );
		if ( $is_yt_video || $is_vimeo_video ) {
			$post_content_updated = $post_content;
		}

		return $post_content_updated;
	}

	/**
	 * This function transforms, skips or whitelists a DOMElement and returns the resulting HTML.
	 * The value of return $custom_html determines if the element's original HTML or the custom HTML will be used:
	 *      - if null is returned, the $domelement's HTML will be used as is
	 *      - if a string is used (either an empty string or a string with value), that will be used instead of $domelement's HTML:
	 *          - by returning an HTML string, the $domelement's HTML will be replaced with it
	 *          - by returning an empty string, the whole $domelement's HTML is skipped
	 *
	 * @param DOMElement $domelement
	 * @param int        $post_id
	 * @param string     $log_need_oembed_resave
	 * @param string     $log_err_img_download
	 *
	 * @return ?string $custom_html Resulting HTML to use instead of the original HTML.
	 *                              If it's an empty string, the original HTML will be skipped (replaced with empty).
	 *                              If it's null, the original HTML will be used (null means a literal null).
	 */
	public function transform_div_enchancement(
		DOMElement $domelement,
		int $post_id,
		string $url,
		string $log_need_oembed_resave,
		string $log_err_img_download,
		string $log_unknown_div_enchancements,
	) : ?string {

		$enhancement_crawler = new Crawler( $domelement );

		$custom_html = null;

		/**
		 * Skip ( by setting `$custom_html = '';` ) or transform 'div.enchancement's ( by setting a HTML value to $custom_html ).
		 */
		if ( $enhancement_crawler->filter( 'div > div#newsletter_signup' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div > div > script[src="https://cdn.broadstreetads.com/init-2.min.js"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > a > img[alt="Student signup banner"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'broadstreet-zone' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.promo-action' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > a > img[alt="BFCU Home Loans Ad"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > a > img[alt="Community Voices election 2022"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > a > img[alt="click here to become a Lookout member"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'span > img[alt="Shopper\'s Spotlight Lily Belli"]' )->count() ) {
			// Skip this 'div.enchancement'.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'script' )->count() > 0
			&& false !== strpos( $enhancement_crawler->filter( 'script' )->text(), '3rd Party Click Tracking' )
		) {
			// Skip this 'div.enchancement'.
			// Tracking script.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count() > 0
			&& false !== strpos( $enhancement_crawler->filter( 'div.html-module > script' )->getNode(0)->getAttribute( 'src' ), '//analytics.stacker.com' )
		) {
			// Skip this 'div.enchancement'.
			// Tracking script.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'script' )->count() > 0
			&& false !== strpos( $enhancement_crawler->filter( 'script' )->text(), '//ads.empowerlocal.co/adserve' )
		) {
			// Skip this 'div.enchancement'.
			// Ads.
			$custom_html = '';

		} elseif ( $enhancement_crawler->children()->count() === 0
			&& empty( trim( $enhancement_crawler->getNode(0)->nodeValue ) )
		) {
			// Skip this 'div.enchancement'.
			// Totally empty div.enchancement.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , '//lookout.brightspotcdn.com' )
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , 'support-local-journalism-2.png' )
		) {
			// Skip this 'div.enchancement'.
			// Button CTA to become a Lookout member.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , '//lookout.brightspotcdn.com' )
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , 'support-lookout-banner-2.png' )
		) {
			// Skip this 'div.enchancement'.
			// Lookout membership CTA.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , '//lookout.brightspotcdn.com' )
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , 'piano-change-banner-1.png' )
		) {
			// Skip this 'div.enchancement'.
			// Lookout membership CTA.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , '//lookout.brightspotcdn.com' )
		           && false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ) , 'gift-a-membership-banner-1.png' )
		) {
			// Skip this 'div.enchancement'.
			// Lookout membership CTA.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( strtolower( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ) ), 'shoppers corner spotlight' )
		) {
			// Skip this 'div.enchancement'.
			// Shoppers corner spotlight.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count() > 0
		           && false !== strpos( strtolower( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ) ), 'shopper’s corner customer spotlight' )
		) {
			// Skip this 'div.enchancement'.
			// Shoppers corner spotlight.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.html-module' )->count()
		           && ( '<div class="html-module"> </div>' == str_replace( ['<br>', "\n", '  ' ], '', $enhancement_crawler->filter( 'div.html-module' )->getNode(0)->ownerDocument->saveHTML( $enhancement_crawler->filter( 'div.html-module' )->getNode(0) ) ) )
		) {
			// Skip this 'div.enchancement'.
			// Empty div.html-module.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count()
		           && ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > script' )->getNode(0)->getAttribute( 'src' ), '//lookoutlocal.activehosted.com' ) )
		) {
			// Skip this 'div.enchancement'.
			// Ad AC.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count()
		           && ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > script' )->getNode(0)->getAttribute( 'src' ), '//pixel.propublica.org/pixel.js' ) )
		) {
			// Skip this 'div.enchancement'.
			// Tracking pixel.
			$custom_html = '';

		} elseif ( $enhancement_crawler->filter( 'div.html-module > div > form' )->count()
		           && ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > div > form' )->getNode(0)->getAttribute( 'action' ), '//lookoutlocal.activehosted.com/proc.php' ) )
		) {
			// Skip 'Sign up for Morning Lookout' form.
			$custom_html = '';




		} elseif ( $enhancement_crawler->filter( 'ps-promo' )->count() ) {

			/**
			 * Transform "related posts" to a div.related-link.
			 *
			 * These come in different formats.
			 */

			// First format -- e.g. https://lookout.co/santacruz/coast-life/story/2023-08-04/santa-cruz-beach-boardwalk-planning-commission-ferris-wheel-chance-rides-seaside-company
			$helper_node = $enhancement_crawler->filter( 'ps-promo > div.promo-wrapper > div.promo-content > div.promo-title-container > p.promo-title > a' )->getNode( 0 );
			if ( $helper_node ) {
				$stripped_html = str_replace( "\n", '', $helper_node->ownerDocument->saveHTML( $helper_node ) );
				$custom_html = '<div class="related-link-1">' . $stripped_html . '</div>';
			}

			// Second format -- e.g. https://lookout.co/santacruz/election-2022/story/2022-11-07/santa-cruz-county-election-2022-weekly-update-november-7
			if ( ! $helper_node ) {
				$promo_wrappers = [];
				// $helper_node = $enhancement_crawler->filter( 'ps-list-loadmore > div > ul > li > ps-promo > div > div.promo-wrapper' );
				$helper_node = $enhancement_crawler->filter( 'ps-list-loadmore > div > ul > li > ps-promo > div > div.promo-wrapper > div.promo-content > div.promo-title-container > p.promo-title > a' );
				if ( $helper_node && $helper_node->count() > 0 ) {
					foreach ( $helper_node->getIterator() as $div_promo_wrapper ) {
						$stripped_html = str_replace( "\n", '', $div_promo_wrapper->ownerDocument->saveHTML( $div_promo_wrapper ) );
						$promo_wrappers[] = $stripped_html;
					}
				}

				if ( ! empty( $promo_wrappers ) ) {
					$custom_html = '<div class="related-link-2">' . implode( "\n", $promo_wrappers ) . '</div>';
				}
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div.quote-text > blockquote' )->count() ) {

			/**
			 * Transform to quote block.
			 */

			$quote_text = null;
			$quote_cite = null;

			$helper_crawler = $enhancement_crawler->filter( 'div.enhancement > div.quote > div.quote-text > blockquote > p.quote-body' );
			if ( $helper_crawler && $helper_crawler->getNode(0) ) {
				$helper_node = $helper_crawler->getNode(0);
				$quote_text = $helper_node->textContent;
			}

			$helper_crawler = $enhancement_crawler->filter( 'div.enhancement > div.quote > div.quote-text > p.quote-attribution' );
			if ( $helper_crawler && $helper_crawler->getNode(0) ) {
				$helper_node = $helper_crawler->getNode(0);
				$quote_cite = $helper_node->textContent;
			}

			// Get block if $quote_text is found, or else keep inner HTML.
			if ( $quote_text ) {
				// Get quote block.
				$quote_block = $this->gutenberg->get_quote( $quote_text, $quote_cite );
				$custom_html = serialize_blocks( [ $quote_block ] );
			} else {
				// Keep HTML inside 'div.enhancement'.
				$helper_node = $enhancement_crawler->filter( 'div.quote-text' )->getNode( 0 );
				$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}


		} elseif ( $enhancement_crawler->filter( 'div.infobox' )->count() ) {
			// Keep HTML inside 'div.enhancement'.
			$helper_node = $enhancement_crawler->filter( 'div.infobox' )->getNode( 0 );
			$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'figure > a[href="mailto:elections@lookoutlocal.com"]' )->count() ) {
			// Keep HTML inside 'div.enhancement'.
			$helper_node = $enhancement_crawler->filter( 'figure' )->getNode( 0 );
			$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div > iframe' )->count()
			|| $enhancement_crawler->filter( 'ps-interactive-project > iframe' )->count()
		) {
			// Keep iframes.
			$helper_node = $enhancement_crawler->filter( 'div' )->getNode( 0 );
			$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div > div > div.infogram-embed' )->count()
			|| $enhancement_crawler->filter( 'div > div.infogram-embed' )->count()
		) {
			// Keep HTML inside 'div.enhancement'.
			$helper_node = $enhancement_crawler->filter( 'div' )->getNode( 0 );
			$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'figure.figure > p > img' )->count() ) {
			// Keep HTML inside 'div.enhancement'.
			$helper_node = $enhancement_crawler->filter( 'figure.figure' )->getNode( 0 );
			$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'ps-interactive-project > iframe' )->count()
			&& false !== strpos( $enhancement_crawler->filter( 'ps-interactive-project > iframe' )->getNode(0)->getAttribute('src'), '//joinsubtext.com/lilyonfood' )
		) {
			/**
			 * If 'div.enhancement' has > ps-interactive-project > iframe with src containing "//joinsubtext.com/lilyonfood", keep it.
			 */
			$iframe_crawler = $enhancement_crawler->filter( 'ps-interactive-project > iframe' );
			if ( $iframe_crawler && $iframe_crawler->getNode(0) ) {
				$src = $iframe_crawler->getNode(0)->getAttribute('src');
				if ( false !== strpos( $src, '//joinsubtext.com/lilyonfood' ) ) {
					// Keep HTML inside 'div.enhancement'.
					$helper_node = $enhancement_crawler->filter( 'ps-interactive-project' )->getNode( 0 );
					$custom_html = $helper_node->ownerDocument->saveHTML( $helper_node );
				}
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'figure.figure > a.link > img' )->count() ) {

			/**
			 * An image within an <a> link: 'div.enhancement' has > figure.figure > a.link > img.image with src containing "//lookout.brightspotcdn.com/".
			 */

			// If an <a> is surrounding the image, get it
			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > a.link' );
			$href = $helper_crawler->getNode(0) ? $helper_crawler->getNode(0)->getAttribute('href') : null;

			// Get all image data -- src, alt, caption, credit.
			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > a.link > img' );
			$src = $helper_crawler->getNode(0)->getAttribute('src');
			$alt = $helper_crawler->getNode(0)->getAttribute('alt');

			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > a.link > div.figure-content > div.figure-caption' );
			$caption = $helper_crawler->count() > 0 ? $helper_crawler->innerText() : null;

			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > a.link > div.figure-content > div.figure-credit' );
			// Not sure why this returns only the first character...
			//      $credit = $helper_crawler->innerText();
			$credit = $helper_crawler->count() > 0 ? $helper_crawler->getIterator()->current()->textContent : null;

			// Download image.
			WP_CLI::line( sprintf( 'Downloading image: %s', $src ) );
			// Dev.
			if ( $this->dev_fake_image_override ) {
				$src = $this->dev_fake_image_override;
			}
			$attachment_id = $this->get_or_download_image( $log_err_img_download, $src, $title = null, $caption, $description = null, $alt, $post_id, $credit );

			// Get Gutenberg image block.
			$attachment_post = get_post( $attachment_id );
			$image_block = $this->gutenberg->get_image( $attachment_post, 'full', false, null, null, $href );
			$custom_html = serialize_blocks( [ $image_block ] );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'figure.figure > img' )->count() ) {

			/**
			 * An image: 'div.enhancement' has > figure.figure > img.image with src containing "//lookout.brightspotcdn.com/".
			 */

			// Get all image data -- src, alt, caption, credit.
			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > img' );
			$src = $helper_crawler->getNode(0)->getAttribute('src');
			$alt = $helper_crawler->getNode(0)->getAttribute('alt');

			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > div.figure-content > div.figure-caption' );
			$caption = $helper_crawler->count() > 0 ? $helper_crawler->innerText() : null;

			$helper_crawler = $enhancement_crawler->filter( 'figure.figure > div.figure-content > div.figure-credit' );
			$credit = $helper_crawler->count() > 0 ? $helper_crawler->getIterator()->current()->textContent : null;

			// Download image.
			WP_CLI::line( sprintf( 'Downloading image: %s', $src ) );
			// Dev.
			if ( $this->dev_fake_image_override ) {
				$src = $this->dev_fake_image_override;
			}
			$attachment_id = $this->get_or_download_image( $log_err_img_download, $src, $title = null, $caption, $description = null, $alt, $post_id, $credit );

			// Get Gutenberg image block.
			$attachment_post = get_post( $attachment_id );
			$image_block = $this->gutenberg->get_image( $attachment_post, 'full', false );
			$custom_html = serialize_blocks( [ $image_block ] );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div > div > ps-youtubeplayer' )->count() ) {

			/**
			 * YT player to Gutenberg YT block.
			 */

			// Get YT video ID.
			$helper_crawler = $enhancement_crawler->filter( 'div > div > ps-youtubeplayer' );
			$yt_video_id    = $helper_crawler->getNode(0)->getAttribute('data-video-id');

			// Get Gutenberg YT block.
			if ( $yt_video_id ) {
				$yt_link     = "https://www.youtube.com/watch?v=$yt_video_id";
				$yt_block    = $this->gutenberg->get_youtube( $yt_link );
				$custom_html = serialize_blocks( [ $yt_block ] );
			}

			// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
			$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d YouTube", $post_id ), $this->logger::WARNING );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div.html-module > center > iframe' )->count()
			&& false !== strpos( $enhancement_crawler->filter( 'div.html-module > center > iframe' )->getNode(0)->getAttribute( 'src' ), '://www.youtube.com/' )
		) {

			/**
			 * YT video in iframe to Gutenberg YT block.
			 */

			// Get YT video ID.
			$yt_link = $enhancement_crawler->filter( 'div.html-module > center > iframe' )->getNode(0)->getAttribute( 'src' );
			// Get Gutenberg YT block.
			if ( $yt_link ) {
				$yt_block    = $this->gutenberg->get_youtube( $yt_link );
				$custom_html = serialize_blocks( [ $yt_block ] );
			}

			// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
			$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d YouTube", $post_id ), $this->logger::WARNING );

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div.tweet-embed' )->count()
			|| $enhancement_crawler->filter( 'blockquote.twitter-tweet' )->count()
		) {

			/**
			 * Tweet embed to Twitter block.
			 */

			// Get Twitter link.
			$twitter_link = '';

			$helper_crawler = $enhancement_crawler->filter( 'div.tweet-embed > blockquote > a' );
			if ( $enhancement_crawler->filter( 'div.tweet-embed > blockquote > a' )->count() ) {
				foreach ( $helper_crawler->getIterator() as $twitter_a_domelement ) {
					$href = $twitter_a_domelement->getAttribute( 'href' );
					if ( false !== strpos( $href, 'twitter.com' ) ) {
						$twitter_link = $href;
						break;
					}
				}
			}

			if ( empty( $twitter_link ) && $enhancement_crawler->filter( 'blockquote.twitter-tweet' )->count() ) {
				$helper_crawler = $enhancement_crawler->filter( 'blockquote.twitter-tweet > a' );
				foreach ( $helper_crawler->getIterator() as $twitter_a_domelement ) {
					$href = $twitter_a_domelement->getAttribute( 'href' );
					if ( false !== strpos( $href, 'twitter.com' ) ) {
						$twitter_link = $href;
						break;
					}
				}
			}

			if ( ! empty( $twitter_link ) ) {
				// Get Gutenberg Twitter block.
				$twitter_block = $this->gutenberg->get_twitter( $twitter_link );
				$custom_html   = serialize_blocks( [ $twitter_block ] );

				// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
				$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d Twitter", $post_id ), $this->logger::WARNING );
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'ps-carousel' )->count() ) {

			/**
			 * ps-carousel slides to Gutenberg gallery block.
			 */

			// First scrape all images data.
			/**
			 * @var array $images_data {
			 *      @type string $src           Image URL.
			 *      @type string $alt           Image alt text.
			 *      @type string $credit        Image credit.
			 *      @type string $caption       Image credit.
			 *      @type string $attachment_id Image credit.
			 * }
			 */
			$images_data = [];
			$helper_crawler = $enhancement_crawler->filter( 'ps-carousel > div.carousel-slides > div.carousel-slide' );
			$img_index = 0;
			foreach ( $helper_crawler->getIterator() as $div_slide_domelement ) {

				$images_data[ $img_index ] = [
					'src' => null,
					'alt' => null,
					'credit' => null,
					'attachment_id' => null,
				];

				// New crawler for each slide.
				$slides_info_crawler = new Crawler( $div_slide_domelement );

				// Get Credit from > div class=carousel-slide-inner ::: data-info-attribution="Cabrillo Robotics"
				$slide_inner_crawler = $slides_info_crawler->filter( 'div.carousel-slide-inner' );
				$attribution = $slide_inner_crawler->count() > 0 ? $slide_inner_crawler->getNode(0)->getAttribute('data-info-attribution') : null;
				$images_data [ $img_index ][ 'credit' ] = $attribution;

				// Get Caption from >
				$slide_inner_crawler = $slides_info_crawler->filter( 'div.carousel-slide-inner > div.carousel-slide-info > div.carousel-slide-info-content > div.carousel-slide-info-title' );
				$caption = $slide_inner_crawler->innerText();
				$caption = trim( str_replace( ' ', ' ', $caption ) );
				if ( ! empty( $caption ) ) {
					$images_data [ $img_index ][ 'caption' ] = $caption;
				}

				// Get Src and Alt from > div class=carousel-slide-inner > div.carousel-slide-media > img ::: alt src
				$slide_inner_crawler = $slides_info_crawler->filter( 'div.carousel-slide-inner > div.carousel-slide-media > img' );
				if ( $slide_inner_crawler->count() ) {
					$src = $slide_inner_crawler->getNode(0)->getAttribute('src');
					if ( ! $src ) {
						$src = $slide_inner_crawler->getNode(0)->getAttribute('data-flickity-lazyload');
					}
					if ( $src ) {
						$images_data[ $img_index ][ 'src' ] = $src;
					}
					$alt = $slide_inner_crawler->getNode(0)->getAttribute('alt');
					if ( $alt ) {
						$images_data[ $img_index ][ 'alt' ] = $alt;
					}
				}

				$img_index++;
			}

			// Import images and get attachment IDs.
			$attachment_ids = [];
			foreach ( $images_data as $image_data ) {

				if ( ! $image_data['src'] ) {
					// TODO -- log
					continue;
				}

				WP_CLI::line( sprintf( 'Downloading image: %s', $image_data['src'] ) );
				// Dev.
				if ( $this->dev_fake_image_override ) {
					$image_data[ 'src' ] = $this->dev_fake_image_override;
				}
				$attachment_id    = $this->get_or_download_image( $log_err_img_download, $image_data[ 'src' ], $title = null, $caption = $image_data[ 'caption' ], $description = null, $image_data[ 'alt' ], $post_id, $image_data[ 'credit' ] );
				$attachment_ids[] = $attachment_id;
			}

			// Get Gutenberg gallery block.
			if ( ! empty( $attachment_ids ) ) {
				$slideshow_block = $this->gutenberg->get_jetpack_slideshow( $attachment_ids );
				$custom_html     = serialize_blocks( [ $slideshow_block ] );

				// Log that this post needs manual resaving (until we figure out programmatic oembed in postmeta).
				$this->logger->log( $log_need_oembed_resave, sprintf( "PostID: %d JPSlideshow", $post_id ), $this->logger::WARNING );
			} else {
				// TODO -- log failed attachment import <-- i.e. failed gallery, but put to same log
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'blockquote.instagram-media' )->count() ) {

			/**
			 * Instagram embeds.
			 */

			$link = $enhancement_crawler->filter( 'blockquote.instagram-media' )->attr( 'data-instgrm-permalink' );
			if ( $link ) {
				$embed_block = $this->gutenberg->get_core_embed( $link );
				$custom_html = serialize_blocks( [ $embed_block ] );
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'div.facebook-embed > div.fb-post' )->count() ) {

			/**
			 * Facebook embeds.
			 */

			$link = $enhancement_crawler->filter( 'div.facebook-embed > div.fb-post' )->attr( 'data-href' );
			if ( $link ) {
				$embed_block = $this->gutenberg->get_core_embed( $link );
				$custom_html = serialize_blocks( [ $embed_block ] );
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}

		} elseif ( $enhancement_crawler->filter( 'ps-vimeoplayer' )->count() ) {

			/**
			 * Vimeo vids.
			 */

			$vimeo_video_id = $enhancement_crawler->filter( 'ps-vimeoplayer' )->getNode(0)->getAttribute( 'data-video-id' );
			$link = sprintf( "https://vimeo.com/%s", $vimeo_video_id );

			if ( $link ) {
				$embed_block = $this->gutenberg->get_core_embed( $link );
				$custom_html = serialize_blocks( [ $embed_block ] );
			}

			if ( empty( $custom_html ) ) {
				$debug = 1;
			}


		} elseif ( $enhancement_crawler->filter( 'div.banner-module-media' )->count() ) {

			/**
			 * Banner module media.
			 */

			foreach ( $enhancement_crawler->filter( 'div.banner-module-media' )->getIterator() as $banner_module_media_node ) {
				$custom_html .= $banner_module_media_node->ownerDocument->saveHTML( $banner_module_media_node );
			}


			if ( empty( $custom_html ) ) {
				$debug = 1;
			}




		} elseif ( $enhancement_crawler->filter( 'div.spotlight > div.spotlight-module-container' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count()
			&& ( 'https://platform.twitter.com/widgets.js' == $enhancement_crawler->filter( 'div.html-module > script' )->getNode( 0 )->getAttribute( 'src' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( strtolower( $enhancement_crawler->filter( 'figure > span > img' )->getNode( 0 )->getAttribute( 'alt' ) ), 'promoted content' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Promoted content banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.sponsor-block-module' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			// Sponsored content banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > div.tableauPlaceholder' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			// Sponsored content banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count()
			&& ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > script' )->getNode( 0 )->getAttribute( 'src' ), '//embed.typeform.com/next/embed.js' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Embed JS.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > div.flourish-embed' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			// Flourish embed JS.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.announcement-module' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			// Announcement module.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ), '//lookout.brightspotcdn.com' ) )
			&& ( false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'src' ), 'lookoutstaff.png' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// LL staff image.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ), 'Shoppers Spotlight' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Shoppers Spotlight banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ), 'Shoppers customer of the week' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Shoppers Spotlight banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ), 'shoppers corner' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Shoppers Spotlight banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'figure > span > img' )->count()
			&& ( false !== strpos( strtolower( $enhancement_crawler->filter( 'figure > span > img' )->getNode(0)->getAttribute( 'alt' ) ), 'shopper\'s spotlight' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Shoppers Spotlight banner.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.rich-text-module > div.rich-text-body' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > center > iframe' )->count()
				&& ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > center > iframe' )->getNode(0)->getAttribute( 'src' ), '//www.facebook.com' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Facebook iframe, different.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'script' )->count()
				&& ( false !== strpos( $enhancement_crawler->filter( 'script' )->getNode(0)->ownerDocument->saveHTML( $enhancement_crawler->filter( 'script' )->getNode(0) ), '//connect.facebook.net' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Facebook script injected directly.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > script' )->count()
				&& ( false !== strpos( $enhancement_crawler->filter( 'script' )->getNode(0)->getAttribute( 'src' ), '//embed.redditmedia.com' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Reddit script.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.html-module > div > script' )->count()
				&& ( false !== strpos( $enhancement_crawler->filter( 'div.html-module > div > script' )->getNode(0)->getAttribute( 'src' ), '//datawrapper.dwcdn.net' ) )
		) {
			// Use this 'div.enchancement' HTML fully.
			// Datawrapper script.
			$custom_html = null;

		} elseif ( $enhancement_crawler->filter( 'div.promo-image' )->count() ) {
			// Use this 'div.enchancement' HTML fully.
			// Promo images.
			$custom_html = null;




		} else {

			/**
			 * Debug all other types of div.enchancement.
			 */
			$dbg_enchancement_html = $domelement->ownerDocument->saveHTML( $domelement );
			$this->logger->log(
				$log_unknown_div_enchancements,
				json_encode( [
					'url'               => $url,
					'post_id'           => $post_id,
					'enchancement_html' => $dbg_enchancement_html
				] ),
				false
			);
			$debug = 1;

		}

		return $custom_html;
	}

	/**
	 * @param string $post_content HTML.
	 */
	public function log_used_div_enhancements( $log, $post_id, $post_content ) {

		$this->crawler->clear();
		$this->crawler->add( $post_content );

		/**
		 * Get the outer content div.rich-text-body in which the body HTML is nested.
		 */
		$div_content_crawler = $this->filter_selector_element( 'div.rich-text-body', $this->crawler );
		/**
		 * If div.rich-text-body was already removed, just temporarily surround the HTML with a new <div> so that nodes can be traversed the same way as children.
		 */
		if ( ! $div_content_crawler ) {
			$this->crawler->clear();
			$this->crawler->add( '<div>' . $post_content . '</div>' );
			$div_content_crawler = $this->filter_selector_element( 'div', $this->crawler );
		}


		/**
		 * QA 'div.enhancement's.
		 */
		foreach ( $div_content_crawler->childNodes->getIterator() as $key_domelement => $domelement ) {

			/**
			 * Examine 'div.enhancement's. If they are not one of the vetted ones, log them.
			 */
			$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( false !== strpos( $domelement->getAttribute( 'class' ), 'enhancement' ) );
			if ( $is_div_class_enhancement ) {

				/**
				 * Any remaining 'div.enhancement's will be logged and should be QAed for whether they're approved in post_content.
				 */
				$enchancement_html = $domelement->ownerDocument->saveHTML( $domelement );
				$this->logger->log(
					$log,
					sprintf(
						"===PostID %d:\n%s",
						$post_id,
						$enchancement_html
					),
					false
				);

			}
		}
	}

	public function cmd_scrape2__scrape_htmls( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * Arguments.
		 */
		$urls_file = $assoc_args['file-list-of-urls'];
		if ( ! file_exists( $urls_file ) ) {
			WP_CLI::error( "File $urls_file does not exist." );
		}
		$urls = explode( "\n", trim( file_get_contents( $urls_file ), "\n" ) );
		if ( empty( $urls ) ) {
			WP_CLI::error( "File $urls_file is empty." );
		}
		$path = $assoc_args['path-to-save-htmls'];
		if ( ! file_exists( $path ) ) {
			mkdir( $path, 0777, true );
		}

		/**
		 * Path where scrapings will be saved.
		 */
		WP_CLI::warning( sprintf( "Saving URLs to %s", $this->temp_dir ) );

		/**
		 * Logs. "2".
		 */
		$log_remote_get_err = 'll2_debug__err_remote_get.log';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_remote_get_err, $ts, false );

		foreach ( $urls as $key_url_data => $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			WP_CLI::line( sprintf( "\n" . '%d/%d Scraping and importing URL %s ...', $key_url_data + 1, count( $urls ), $url ) );

			$html_cached_filename = $this->sanitize_filename( $url, 'html' );
			$html_cached_file_path = $path . '/' . $html_cached_filename;

			// Get HTML from cache or fetch from HTTP.
			$html = file_exists( $html_cached_file_path ) ? file_get_contents( $html_cached_file_path ) : null;
			if ( is_null( $html ) ) {

				// Scrape with retries.
				$max_retries = 3;
				$sleep_retry = 3;
				$retry_count = 0;
				$get_result = false;
				while ($retry_count < $max_retries && ! $get_result) {
					// Scrape.
					$get_result = $this->wp_remote_get_with_retry( $url );
					$has_scrape_failed = is_wp_error( $get_result ) || is_array( $get_result );
					if ( $has_scrape_failed ) {
						WP_CLI::warning( sprintf( 'Failed, retrying %d/%d ...', $retry_count + 1, $max_retries ) );
						sleep( $sleep_retry );
						$retry_count++;
					}
				}

				// Not OK.
				if ( is_wp_error( $get_result ) || is_array( $get_result ) ) {
					$msg = is_wp_error( $get_result ) ? $get_result->get_error_message() : $get_result['response']['message'];
					$this->logger->log( $log_remote_get_err, sprintf( 'URL: %s CODE: %s MESSAGE: %s', $url, $get_result['response']['code'], $msg ), $this->logger::WARNING );
					continue;
				}

				// Save HTML to file.
				$html = $get_result;

				$file_content = json_encode( [
					'url' => $url,
					'html' => $html,
				] );
				file_put_contents( $html_cached_file_path, $file_content );
			}
		}

		WP_CLI::line( sprintf( 'Saved to %s 👍', $path ) );
		WP_CLI::line( sprintf( '❗️  %s', $log_remote_get_err ) );
	}

	public function cmd_import1__create_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * Args.
		 */
		$path_to_htmls = $assoc_args['path-to-htmls'];
		$html_files = glob( $path_to_htmls . '/*.html' );
		if ( empty( $html_files ) ) {
			WP_CLI::error( 'No .html files found in path.' );
		}
		$reimport_posts = isset( $assoc_args['reimport-posts'] ) ? true : false;
		$this->dev_fake_image_override = $assoc_args['dev-override-fake-image-path'] ?? null;

		/**
		 * Logs.
		 */
		$log_failed_imports               = 'll2_err__failed_imports.log';
		$log_all_author_names             = 'll2_debug__all_author_names.log';
		$log_all_tags                     = 'll2_debug__all_tags.log';
		$log_all_tags_promoted_content    = 'll2_debug__all_tags_promoted_content.log';
		$log_err_importing_featured_image = 'll2_err__featured_image.log';
		$log_err_img_download             = 'll2_err__img_download.log';
		$log_err_slideshow_img_download   = 'll2_err__slideshow_img_download.log';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_failed_imports, $ts, false );
		$this->logger->log( $log_all_author_names, $ts, false );
		$this->logger->log( $log_all_tags, $ts, false );
		$this->logger->log( $log_all_tags_promoted_content, $ts, false );
		$this->logger->log( $log_err_importing_featured_image, $ts, false );
		$this->logger->log( $log_err_img_download, $ts, false );
		$this->logger->log( $log_err_slideshow_img_download, $ts, false );

		// Debugging and QA.
		$debug_all_author_names          = [];
		$debug_all_tags                  = [];

		/**
		 * Import posts.
		 */

		$all_imported_post_ids = [];

		foreach ( $html_files as $key_html_file => $html_file ) {

			$file_content = json_decode( file_get_contents( $html_file ), true );
			$url = $file_content['url'];
			$html = $file_content['html'];


			/**
			 * Skip post ID if already imported and --reimport-posts not set.
			 */
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"select wpm.post_id
					from {$wpdb->postmeta} wpm
					join wp_posts wp on wp.ID = wpm.post_id
					where wpm.meta_key = %s
					and wpm.meta_value = %s
					and wp.post_status = 'publish' ; ",
					self::META_POST_ORIGINAL_URL,
					$url
				)
			);
			if ( ! $reimport_posts && $post_id ) {
				WP_CLI::line( sprintf( 'Already imported ID %d URL %s, skipping.', $post_id, $url ) );
				continue;
			}


			/**
			 * Crawl and extract all useful data from HTML.
			 */
			WP_CLI::line( sprintf( "\n" . '%d/%d Importing %s ...', $key_html_file + 1, count( $html_files ), $url ) );
			try {
				$crawled_data = $this->crawl_post_data_from_html( $html, $url );
			} catch ( \UnexpectedValueException $e ) {
				$this->logger->log( $log_failed_imports, sprintf( 'URL: %s MESSAGE: %s', $url, $e->getMessage() ), $this->logger::WARNING );
				continue;
			}

			// Get slug from URL.
			$slug = $this->get_slug_from_url( $url );

			$is_slideshow = self::META_POST_LAYOUT_SLIDESHOW == $crawled_data['_layout_type'];

			// QA.
			if ( empty( $crawled_data['post_title'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'post_title not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['post_content'] ) && ! $is_slideshow ) {
				throw new \UnexpectedValueException( sprintf( 'post_content not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $slug ) ) {
				throw new \UnexpectedValueException( sprintf( 'slug not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['post_date'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'post_date not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['category_name'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'category_name not found for ID %d URL %s', $post_id, $url ) );
			}

			/**
			 * Create or update post.
			 */
			$post_args = [
				'post_title'   => $crawled_data['post_title'],
				'post_content' => $crawled_data['post_content'],
				// The Publisher explicitly wanted to save theing the subtitle as the excerpt.
				'post_excerpt' => $crawled_data['post_subtitle'] ?? '',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_date'    => $crawled_data['post_date'],
			];
			// Allow initial empty post_content string for slideshow (null would throw exception).
			if ( $is_slideshow ) {
				$post_args['post_content'] = '';
			}
			if ( ! $post_id ) {
				$post_id = wp_insert_post( $post_args );
				WP_CLI::success( sprintf( 'Created post ID %d', $post_id ) );

				$all_imported_post_ids[] = $post_id;
			} else {
				$wpdb->update(
					$wpdb->posts,
					$post_args,
					[ 'ID' => $post_id ]
				);
				WP_CLI::success( sprintf( 'Updated post ID %d', $post_id ) );

				$all_imported_post_ids[] = $post_id;
			}


			// If slideshow, import images and create gallery.
			if ( $is_slideshow ) {

				// Import images.
				$image_ids = [];
				foreach ( $crawled_data['slideshow'] as $key_slide => $slide ) {
					WP_CLI::line( sprintf( 'Importing slideshow slide %d/%d %s ...', $key_slide + 1, count( $crawled_data['slideshow']  ), $slide['src'] ) );

					$img_id = $this->get_or_download_image(
						$log_err_slideshow_img_download,
						$slide['src'],
						$title = null,
						$caption = $slide['caption'],
						$description = null,
						$alt = $slide['alt'],
						$post_id,
						$credit = $slide['credit'],
						$args = []
					);
					if ( ! $img_id || is_wp_error( $img_id ) ) {
						$this->logger->log(
							$log_err_slideshow_img_download,
							sprintf(
								'PostID %s slideshow image URL %s Error %s',
								$post_id,
								$slide['src'],
								is_wp_error( $img_id ) ? $img_id->get_error_message() : '/'
							),
							$this->logger::WARNING
						);
						continue;
					}
					$image_ids[] = $img_id;
				}

				// Get JP slideshow block.
				$slideshow_block = $this->gutenberg->get_jetpack_slideshow( $image_ids );
				$slideshow_html  = serialize_blocks( [ $slideshow_block ] );

				// Update post_content by replacing it with the slideshow block.
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $slideshow_html ],
					[ 'ID' => $post_id ]
				);
			}


			/**
			 * Collect postmeta.
			 */
			$postmeta = [
				// Newspack Subtitle postmeta. The Publisher explicitly asked that the subtitle be saved as the excerpt. We should wipe it here for backwards compatibility when doing --reimport-posts.
				'newspack_post_subtitle'                  => '',
				// Basic data
				self::META_POST_ORIGINAL_URL              => $url,
				'newspackmigration_slug'                  => $slug,
				// E.g. "lo-sc".
				'newspackmigration_script_source'         => $crawled_data['script_data']['source'] ?? '',
				// E.g. "uc-santa-cruz". This is a backup value to help debug categories, if needed.
				'newspackmigration_script_sectionName'    => $crawled_data['script_data']['sectionName'],
				// E.g. "Promoted Content".
				'newspackmigration_script_tags'           => $crawled_data['script_data']['tags'] ?? '',
				'newspackmigration_presentedBy'           => $crawled_data['presented_by'] ?? '',
				'newspackmigration_tags_promoted_content' => $crawled_data['tags_promoted_content'] ?? '',
				// Author links, to be processed after import.
				'newspackmigration_author_links'          => $crawled_data['author_links'] ?? '',
				// Featured img info.
				'featured_image_src'                      => $crawled_data['featured_image_src'] ?? '',
				'featured_image_caption'                  => $crawled_data['featured_image_caption'] ?? '',
				'featured_image_alt'                      => $crawled_data['featured_image_alt'] ?? '',
				'featured_image_credit'                   => $crawled_data['featured_image_credit'] ?? '',
				// Layout type.
				'newspackmigration_layouttype'            => $crawled_data['_layout_type'] ?? '',
			];
			if ( $is_slideshow ) {
				$postmeta['slideshow'] = $crawled_data['slideshow'];
			}

			/**
			 * Import featured image.
			 */
			if ( isset( $crawled_data['featured_image_src'] ) ) {
				WP_CLI::line( 'Downloading featured image ...' );
				// Dev.
				if ( $this->dev_fake_image_override ) {
					$crawled_data['featured_image_src'] = $this->dev_fake_image_override;
				}
				$featimg_id = $this->get_or_download_image(
					$log_err_img_download,
					$crawled_data['featured_image_src'],
					$title = null,
					$crawled_data['featured_image_caption'],
					$description = null,
					$crawled_data['featured_image_alt'],
					$post_id,
					$crawled_data['featured_image_credit']
				);
				if ( ! $featimg_id || is_wp_error( $featimg_id ) ) {
					$this->logger->log( $log_err_importing_featured_image, sprintf(
						'PostID %s URL %s Error %s',
						$post_id,
						$crawled_data['featured_image_src'],
						is_wp_error( $featimg_id ) ? $featimg_id->get_error_message() : '/'
					) );
				} else {
					// Set featured image.
					set_post_thumbnail( $post_id, $featimg_id );
				}
			}

			/**
			 * Authors.
			 */
			// Allow no authors found because some of the posts are not authored, like sponsored content.
			if ( ! isset( $crawled_data['post_authors'] ) || is_null( $crawled_data['post_authors'] ) ) {
				$crawled_data['post_authors'][] = 'NO_AUTHOR_FOUND';
			}
			// Get/create GAs.
			$ga_ids = [];
			foreach ( $crawled_data['post_authors'] as $author_name ) {
				$ga = $this->cap->get_guest_author_by_display_name( $author_name );
				if ( $ga ) {
					$ga_id = $ga->ID;
				} else {
					$ga_id = $this->cap->create_guest_author( [ 'display_name' => $author_name ] );
					if ( is_wp_error( $ga_id ) ) {
						throw new \RuntimeException( sprintf( 'Could not create author %s for post %d URL %s error message: %s', $author_name, $post_id, $url, $ga_id->get_error_message() ) );
					}
				}
				$ga_ids[] = $ga_id;
			}
			if ( empty( $ga_ids ) ) {
				throw new \UnexpectedValueException( sprintf( 'Authors not found for ID %d URL %s', $post_id, $url ) );
			}
			// Assign GAs to post.
			$this->cap->assign_guest_authors_to_post( $ga_ids, $post_id, false );
			// Also collect all author names for easier debugging/QA-ing.
			$debug_all_author_names = array_merge( $debug_all_author_names, $crawled_data['post_authors'] );


			/**
			 * Categories.
			 */
			$category_parent_id = 0;
			if ( $crawled_data['category_parent_name'] ) {
				// Get or create parent category.
				$category_parent_id = wp_create_category( $crawled_data['category_parent_name'], 0 );
				if ( is_wp_error( $category_parent_id ) ) {
					throw new \UnexpectedValueException( sprintf( 'Could not get or create category_parent_name %s for ID %d URL %s error: %s', $crawled_data['category_parent_name'], $post_id, $url, $category_parent_id->get_error_message() ) );
				}
			}
			// Get or create primary category.
			$category_id = wp_create_category( $crawled_data['category_name'], $category_parent_id );
			if ( is_wp_error( $category_id ) ) {
				throw new \UnexpectedValueException( sprintf( 'Could not get or create category_name %s for ID %d URL %s error message: %s', $crawled_data['category_name'], $post_id, $url, $category_id->get_error_message() ) );
			}
			// Set category.
			wp_set_post_categories( $post_id, [ $category_id ] );


			/**
			 * Tags.
			 */
			$tags = $crawled_data['tags'];
			if ( $tags ) {
				// wp_set_post_tags() also takes a CSV of tags, so this might work out of the box. But we're saving
				wp_set_post_tags( $post_id, $tags );
				// Collect all tags for QA.
				$debug_all_tags = array_merge( $debug_all_tags, [ $tags ] );
			}


			/**
			 * Save postmeta.
			 */
			foreach ( $postmeta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

		/**
		 * Debug and QA.
		 */
		// Author names.
		if ( ! empty( $debug_all_author_names ) ) {
			$this->logger->log( $log_all_author_names, implode( "\n", $debug_all_author_names ), false );
			WP_CLI::warning( "⚠️️  QA $log_all_author_names" );
		}
		// Tags.
		if ( ! empty( $debug_all_tags ) ) {
			// Flatten multidimensional array to single.
			$debug_all_tags_flattened = [];
			array_walk_recursive(
				$debug_all_tags,
				function( $e ) use ( &$debug_all_tags_flattened ) {
					$debug_all_tags_flattened[] = $e;
				}
			);
			// Log.
			$this->logger->log( $log_all_tags, implode( "\n", $debug_all_tags_flattened ), false );
			WP_CLI::warning( "⚠️️  QA $log_all_tags" );
		}
		file_put_contents( 'll2__all_imported_post_ids.log', implode( ",", $all_imported_post_ids ) );
		WP_CLI::warning( "⚠️️  QA 'll2__all_imported_post_ids.log'" );


		WP_CLI::line( 'Done 👍' );
	}

	public function cmd_after_import2__content_transform_and_cleanup( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * Args.
		 */
		$post_ids = isset( $assoc_args['post-ids-csv-file'] ) ? explode( ',', file_get_contents( $assoc_args['post-ids-csv-file'] ) ) : null;
		if ( ! $post_ids ) {
			$post_ids = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		}
		$this->dev_fake_image_override = $assoc_args['dev-override-fake-image-path'] ?? null;

		// Folder to store scraped author pages HTMLs.
		$scrape_author_htmls_path = 'scrape_author_htmls';
		if ( ! file_exists( $scrape_author_htmls_path ) ) {
			mkdir( $scrape_author_htmls_path, 0777, true );
		}

		/**
		 * Logs.
		 */
		$log_post_ids_updated          = 'll2_updated_post_ids.log';
		$log_gas_urls_updated          = 'll2_gas_urls_updated.log';
		$log_err_gas_updated           = 'll2_err__updated_gas.log';
		$log_need_oembed_resave        = 'll2__need_oembed_resave.log';
		$log_err_img_download          = 'll2_err__img_download.log';
		$log_unknown_div_enchancements = 'll2_err__unknown_enchancements_divs.json';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_post_ids_updated, $ts, false );
		$this->logger->log( $log_gas_urls_updated, $ts, false );
		$this->logger->log( $log_err_gas_updated, $ts, false );
		$this->logger->log( $log_need_oembed_resave, $ts, false );
		$this->logger->log( $log_err_img_download, $ts, false );

		// Get post IDs.
		if ( ! $post_ids ) {
			$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );
		}

		/**
		 * Clean up post_content -- remove inserted promo or user engagement content.
		 */
		WP_CLI::line( 'Cleaning up post_content ...' );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			if ( empty( $post_id ) ) {
				continue;
			}

			$original_url = $wpdb->get_var( $wpdb->prepare( "select meta_value from wp_postmeta where meta_key = 'newspackmigration_url' and post_id = %d;", $post_id ) );
			WP_CLI::line( sprintf( "\n" . '%d/%d ID %d %s', $key_post_id + 1, count( $post_ids ), $post_id, $original_url ) );

			$post_content = $wpdb->get_var( $wpdb->prepare( "select post_content from {$wpdb->posts} where ID = %d", $post_id ) );
			$is_slideshow = self::META_POST_LAYOUT_SLIDESHOW == get_post_meta( $post_id, 'newspackmigration_layouttype', true );

			$post_content_updated = $this->clean_up_scraped_html( $post_id, $original_url, $post_content, $log_need_oembed_resave, $log_err_img_download, $log_unknown_div_enchancements );
			if ( ! $is_slideshow && is_null( $post_content_updated ) ) {
				throw new \UnexpectedValueException( 'post_content_updated is null -- due to unknown template.' );
			}

			// If post_content was updated.
			if ( ! empty( $post_content_updated ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );
				$this->logger->log( $log_post_ids_updated, sprintf( 'Updated %d', $post_id ), $this->logger::SUCCESS );
			}

			// We could look into the resulting post_content and log all used 'div.enhancement's. Commenting and skipping for now.
			// $this->log_used_div_enhancements( $log_used_enhancements, $post_id, ! empty( $post_content_updated ) ? $post_content_updated : $post_content );
		}


		WP_CLI::line(
			'Done. QA the following logs:'
			. "\n  - ❗  ERRORS: $log_err_gas_updated"
			. "\n  - ♻️️  $log_need_oembed_resave"
			. "\n  - 👍  $log_post_ids_updated"
			. "\n  - 👍  $log_gas_urls_updated"
			. "\n  - 👍  $log_unknown_div_enchancements"
		);
		wp_cache_flush();


		WP_CLI::warning( 'Skipping programmatic updating GA author data -- should be done manually' );
		return;


		/**
		 * Next update GA info by scraping and fetching their author pages from live.
		 */
		WP_CLI::line( 'Updating GA author data ...' );

		// First get all author pages URLs which were originally stored as Posts' postmeta.
		$author_pages_urls = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "\n" . "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$links_meta = get_post_meta( $post_id, 'newspackmigration_author_links' );
			if ( empty( $links_meta ) ) {
				continue;
			}

			// Flatten these multidimensional meta and add them to $author_pages_links as unique values.
			foreach ( $links_meta as $urls ) {
				foreach ( $urls as $url ) {
					if ( in_array( $url, $author_pages_urls ) ) {
						continue;
					}

					$author_pages_urls[] = $url;
				}
			}
		}

		// Now actually scrape individual author pages and update GAs with that data.
		foreach ( $author_pages_urls as $author_page_url ) {
			$errs_updating_gas = $this->update_author_info( $author_page_url, $scrape_author_htmls_path, $log_err_gas_updated );
			if ( empty( $errs_updating_gas ) ) {
				$this->logger->log( $log_gas_urls_updated, $author_page_url, false );
			} else {
				$this->logger->log( $log_err_gas_updated, implode( "\n", $errs_updating_gas ), false );
			}
		}

	}

	public function get_slug_from_url( $url ) {
		$url_path          = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$url_path_exploded = explode( '/', $url_path );
		$slug              = end( $url_path_exploded );

		return $slug;
	}

	/**
	 * Creates a unique filename for a URL string, of safe length to be a file name on OSX.
	 *
	 * @param $string
	 *
	 * @return string
	 */
	public function sanitize_filename( $string, $extension ) {

		// Calculate a hash of the input string.
		$hash = md5( $string );
		// Encode the hash using base64 encoding.
		$compressed = base64_encode( $hash );
		// Trim the encoded string to max filename length.
		$compressed = substr( $compressed, 0, 200 );

		return $compressed . '.' . $extension;
	}

	/**
	 * Tries to get post URL from relational single-table 6GB dump the Publisher sent us.
	 * This is difficult to use due to super slow queries and that the data is convoluted.
	 *
	 * @param $newspack_entries_table_row
	 * @param $section_data_cache_path
	 *
	 * @return string|null
	 */
	public function get_post_url( $newspack_entries_table_row, $section_data_cache_path ) {
		global $wpdb;

		$slug = $newspack_entries_table_row['slug'];
		$data = json_decode( $newspack_entries_table_row['data'], true );

		/**
		 * Example post URL looks like this:
		 *      https://lookout.co/santacruz/environment/story/2020-11-18/debris-flow-evacuations-this-winter
		 *
		 * Tried getting URL/ permalink from `Record` by "cms.directory.pathTypes", but it's not there in that format:
		 *      select data from Record where data like '%00000175-41f4-d1f7-a775-edfd1bd00000:00000175-dd52-dd02-abf7-dd72cf3b0000%' and data like '%environment%';
		 * It's probably split by two objects separated by ":", but that's difficult to locate in `Record`.
		 *
		 * Next, trying to just get the name of category, e.g. "environment", and date, e.g. "2020-11-18", from `Record`, then compose the URL manually.
		 * Searching by relational sections "sectionable.section", "_id" and "_type".
		 *      select data from Record where data like '{"cms.site.owner"%' and data like '%"_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"%' and data like '%"_id":"00000175-8030-d826-abfd-ec7086fa0000"%' order by id desc limit 1;
		 */

		// Get (what I believe to be) category data entry from Record table.
		if ( ! isset( $data['sectionable.section']['_ref'] ) || ! isset( $data['sectionable.section']['_type'] ) ) {
			return null;
		}
		$article_ref                       = $data['sectionable.section']['_ref'];
		$article_type                      = $data['sectionable.section']['_type'];
		$id_like                           = sprintf( '"_id":"%s"', $article_ref );
		$type_like                         = sprintf( '"_type":"%s"', $article_type );
		$section_data_temp_cache_file_name = $article_type . '__' . $article_ref;
		$section_data_temp_cache_file_path = $section_data_cache_path . '/' . $section_data_temp_cache_file_name;

		$record_table = self::DATA_EXPORT_TABLE;
		if ( ! file_exists( $section_data_temp_cache_file_path ) ) {
			$sql = "select data from {$record_table} where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%' order by id desc limit 1;";
			WP_CLI::line( sprintf( 'Querying post URL...' ) );
			$section_result = $wpdb->get_var( $sql );
			file_put_contents( $section_data_temp_cache_file_path, $section_result );
		} else {
			$section_result = file_get_contents( $section_data_temp_cache_file_path );
		}
		$section = json_decode( $section_result, true );

		// Check if section data is valid.
		if ( ! $section || ! isset( $section['cms.directory.paths'] ) || ! $section['cms.directory.paths'] ) {
			$d = 1;
		}

		// Get last exploded url segment from, e.g. "cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-32a8-d1f7-a775-feedba580000/environment"
		if ( ! isset( $section['cms.directory.paths'][0] ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get section data for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}
		$section_paths_exploded = explode( '/', $section['cms.directory.paths'][0] );
		$section_slug           = end( $section_paths_exploded );
		if ( ! $section_slug ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get section for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}

		// Get date slug, e.g. '2020-11-18'.
		$date_slug = date( 'Y-m-d', $data['cms.content.publishDate'] / 1000 );
		if ( ! $section_slug ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get date slug for article slug: %s _ref: %s and _type:%s', $slug, $article_ref, $article_type ) );
		}

		// Compose URL.
		$url_data = sprintf(
			'https://lookout.co/santacruz/%s/story/%s/%s',
			$section_slug,
			$date_slug,
			$slug
		);

		return $url_data;
	}

	/**
	 * Crawls all useful post data from HTML.
	 *
	 * @param string $html HTML.
	 * @param string $url  URL.
	 *
	 * @return array $data All posts data crawled from HTML. {
	 *      @type array   script_data            Decoded data from that one <script> element with useful post info.
	 *      @type string  post_title
	 *      @type string  subtitle
	 *      @type string  post_content
	 *      @type string  post_date
	 *      @type array   post_authors           Array of author names.
	 *      @type ?string featured_image_src
	 *      @type ?string featured_image_alt
	 *      @type ?string featured_image_caption
	 *      @type ?string featured_image_credit
	 *      @type string  category_name
	 *      @type ?string category_parent_name
	 *      @type ?string tags
	 *      @type ?string presented_by
	 *      @type ?string _layout_type		      One of self::META_POST_LAYOUT_* constants.
	 * }
	 */
	public function crawl_post_data_from_html( $html, $url ) {

		$data = [];

		/**
		 * Get all post data.
		 */
		$this->crawler->clear();
		$this->crawler->add( $html );

		// Extract some data from this <script> element which contains useful data.
		$script_json = $this->filter_selector( 'script#head-dl', $this->crawler );
		$script_json = preg_replace( '/^var dataLayer = /', '', $script_json );
		$script_json = rtrim( $script_json, ';' );
		$script_data = json_decode( $script_json, true );
		$script_data = $script_data[0] ?? null;
		if ( is_null( $script_data ) ) {
			throw new \UnexpectedValueException( 'NOT FOUND <script> element data' );
		}

		$data['script_data'] = $script_data;

		// Title, subtitle, content.
		$title = $this->filter_selector( 'h1.headline', $this->crawler );
		if ( empty( $title ) ) {
			throw new \UnexpectedValueException( 'NOT FOUND title' );
		}
		$data['post_title'] = $title;

		$subtitle              = $this->filter_selector( 'div.subheadline > h2', $this->crawler ) ?? null;
		$data['post_subtitle'] = $subtitle ?? null;

		$post_content = '';

		/**
		 * CONTENT TYPE 1. STORY STACK
		 *      - content is located in: article.story > div.story-stack and these have multiple div.story-stack-item > div.story-stack-story
		 *          => we will save all div.story-stack-story as post_content
		 *      - e.g. https://lookout.co/santacruz/coast-life/story/2023-05-19/pescadero-day-trip-sea-lions-ano-nuevo-award-winning-tavern-baby-goats
		 */
		$div_content_crawler = $this->filter_selector_element( 'article.story>div.story-stack>div.story-stack-item>div.story-stack-story', $this->crawler, $single = false );
		if ( $div_content_crawler ) {
			foreach ( $div_content_crawler->getIterator() as $div_content_crawler_story_stack_story ) {
				$post_content .= ! empty( $post_content ) ? "\n\n" : '';
				$post_content .= $div_content_crawler_story_stack_story->ownerDocument->saveHTML( $div_content_crawler_story_stack_story );
			}

			$data['_layout_type'] = self::META_POST_LAYOUT_STORY_STACK;
		}

		/**
		 * CONTENT TYPE 2a.
		 *      - div#pico
		 */
		if ( ! $div_content_crawler ) {
			/**
			 * There can be multiple div#pico elements.
			 * This here was for when I thought there was just a single div#pico element:
			 *      $post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
			 */
			$div_content_crawler = $this->filter_selector_element( 'div#pico', $this->crawler, $single = false );
			if ( $div_content_crawler ) {
				foreach ( $div_content_crawler->getIterator() as $post_content_node ) {
					$post_content .= ! empty( $post_content ) ? "\n\n" : '';
					$post_content .= $post_content_node->ownerDocument->saveHTML( $post_content_node );
				}

				$data['_layout_type'] = self::META_POST_LAYOUT_REGULAR;
			}
		}


		/**
		 * CONTENT TYPE 2b.
		 *      - div.page-main-content > article.story > div.page-article-container > div.page-article-body > div.rich-text-article-body > div.rich-text-body
		 * e.g. https://lookout.co/santacruz/news/newsletter/2022-12-09/fresh-truffle-dungeness-crab-season-delayed-pesticides-watsonville-pajaro-valley-organic-transition-santa-cruz-voting-data-mapped-flynn-creek-circus-capitola-mall-january-6-committee-denver-riggleman-morning-lookout-osmosys-app-workaround
		 */
		if ( ! $div_content_crawler ) {
			$div_content_crawler = $this->filter_selector_element( 'div.page-main-content > article.story > div.page-article-container > div.page-article-body > div.rich-text-article-body > div.rich-text-body', $this->crawler, $single = false );
			if ( $div_content_crawler ) {
				foreach ( $div_content_crawler->getIterator() as $post_content_node ) {
					$post_content .= ! empty( $post_content ) ? "\n\n" : '';
					$post_content .= $post_content_node->ownerDocument->saveHTML( $post_content_node );
				}

				$data['_layout_type'] = self::META_POST_LAYOUT_REGULAR;
			}
		}


		/**
		 * CONTENT TYPE 3. Vimeo video in header
		 */
		if ( ! $div_content_crawler ) {
			/**
			 * There can be multiple div#pico elements.
			 * This here was for when I thought there was just a single div#pico element:
			 *      $post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
			 */
			$div_content_crawler = $this->filter_selector_element( 'div.page-lead > div.video-page-player > ps-vimeoplayer', $this->crawler, $single = true );
			if ( $div_content_crawler ) {
				$video_id = $div_content_crawler->getAttribute( 'data-video-id' );
				if ( ! $video_id ) {
					throw new \UnexpectedValueException( 'NOT FOUND YT video_id' );
				}
				$vimeo_url = sprintf( 'https://vimeo.com/%s', $video_id );

				// Get block HTML.
				$block = $this->gutenberg->get_vimeo( $vimeo_url );
				$block_html = serialize_blocks( [ $block ] );

				// Append video block to content.
				$post_content .= ! empty( $post_content ) ? "\n\n" : '';
				$post_content .= $block_html;

				// Search more post_content if available.
				$div_content_crawler_helper = $this->filter_selector_element( 'div.page-description-body', $this->crawler, $single = true );
				if ( $div_content_crawler_helper ) {
					$description_html = $div_content_crawler_helper->ownerDocument->saveHtml( $div_content_crawler_helper );

					// Append.
					$post_content .= ! empty( $post_content ) ? "\n\n" : '';
					$post_content .= $description_html;
				}

				$data['_layout_type'] = self::META_POST_LAYOUT_YOUTUBE;
			}
		}


		/**
		 * CONTENT TYPE 4. Youtube video in header
		 *      - div#pico
		 */
		if ( ! $div_content_crawler ) {
			/**
			 * There can be multiple div#pico elements.
			 * This here was for when I thought there was just a single div#pico element:
			 *      $post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
			 */
			$div_content_crawler = $this->filter_selector_element( 'div.page-lead > div.video-page-player > ps-youtubeplayer', $this->crawler, $single = true );
			if ( $div_content_crawler ) {
				$video_id = $div_content_crawler->getAttribute( 'data-video-id' );
				if ( ! $video_id ) {
					throw new \UnexpectedValueException( 'NOT FOUND YT video_id' );
				}
				$yt_url = sprintf( 'https://www.youtube.com/watch?v=%s', $video_id );

				// Get block HTML.
				$block = $this->gutenberg->get_youtube( $yt_url );
				$block_html = serialize_blocks( [ $block ] );

				// Append video block to content.
				$post_content .= ! empty( $post_content ) ? "\n\n" : '';
				$post_content .= $block_html;

				// Search more post_content if available.
				$div_content_crawler_helper = $this->filter_selector_element( 'div.page-description-body', $this->crawler, $single = true );
				if ( $div_content_crawler_helper ) {
					$description_html = $div_content_crawler_helper->ownerDocument->saveHtml( $div_content_crawler_helper );

					// Append.
					$post_content .= ! empty( $post_content ) ? "\n\n" : '';
					$post_content .= $description_html;
				}

				$data['_layout_type'] = self::META_POST_LAYOUT_YOUTUBE;
			}
		}


		/**
		 * CONTENT TYPE 5. Slideshow
		 */
		if ( ! $div_content_crawler ) {
			/**
			 * There can be multiple div#pico elements.
			 * This here was for when I thought there was just a single div#pico element:
			 *      $post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
			 */
			$slides = [];
			$div_content_crawler = $this->filter_selector_element( 'div.gallery-page-slides > div.gallery-page-slide > div.gallery-slide', $this->crawler, $single = false );
			if ( $div_content_crawler ) {

				foreach ( $div_content_crawler->getIterator() as $key_slide => $slide_crawler ) {
					// First slide is the "featured image".
					if ( 0 == $key_slide ) {
						continue;
					}

					$slide_element_html = $slide_crawler->ownerDocument->saveHTML( $slide_crawler );
					$slide_helper_crawler = new Crawler( $slide_element_html );

					$img_element = $this->filter_selector_element( 'div.gallery-slide > div.gallery-slide-media > img', $slide_helper_crawler, $single = true );
					$img_alt = $img_element->getAttribute( 'alt' );
					$img_src = $img_element->getAttribute( 'src' );
					if ( 0 === strpos( $img_src, 'data:image') ) {
						$img_src = $img_element->getAttribute( 'data-src' );
					}
					if ( ! $img_src ) {
						throw new \UnexpectedValueException( 'NOT FOUND img_src' );
					}

					$description_element = $this->filter_selector_element( 'div.gallery-slide > div.gallery-slide-meta > p.gallery-slide-description', $slide_helper_crawler, $single = true );
					if ( ! $description_element ) {
						throw new \UnexpectedValueException( 'NOT FOUND description_element' );
					}
					$img_caption = '';
					$img_credit  = '';
					foreach ( $description_element->childNodes as $child ) {
						// Clean up HTML.
						$child_html = trim( $description_element->ownerDocument->saveHTML( $child ), "  \t\n\r\0\x0B" );

						// Get credit.
						if ( false !== strpos( $child_html, '<span class="gallery-slide-credit">' ) ) {
							$img_credit = trim( str_replace( [ '<span class="gallery-slide-credit">', '</span>' ], '', $child_html ) );
							if ( ! $img_credit ) {
								throw new \UnexpectedValueException( 'NOT FOUND img_credit' );
							}
							$img_credit = $this->format_featured_image_credit( $img_credit );
						}

						// Get caption/description.
						if ( $child_html ) {
							$img_caption .= $child_html;
						}
					}

					$slides[] = [
						'post_url' => $url,
						'src'      => $img_src,
						'alt'      => $img_alt ?? null,
						'credit'   => $img_credit ?? null,
						'caption'  => $img_caption ?? null,
					];
				}

				$data['_layout_type'] = self::META_POST_LAYOUT_SLIDESHOW;
			}

			if ( empty( $slides ) ) {
				throw new \UnexpectedValueException( 'NOT FOUND slides' );
			}
		}
		$is_slideshow = self::META_POST_LAYOUT_SLIDESHOW == $data['_layout_type'] ?? null;

		if ( ! empty( $slides ) ) {
			$data['slideshow'] = $slides;
		}

		if ( empty( $post_content ) ) {
			$post_content = $this->filter_selector( 'div.rich-text-article-body-content', $this->crawler, false, false );
		}
		// Allow empty post content only for slideshow.
		if ( empty( $post_content ) && ! $is_slideshow ) {
			throw new \UnexpectedValueException( 'NOT FOUND post_content' );
		}
		$data['post_content'] = $post_content;

		// Date. <script> element has both date and time of publishing.
		$matched = preg_match( '/(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})/', $script_data['publishDate'], $matches_date );
		if ( false === $matched ) {
			throw new \UnexpectedValueException( 'NOT FOUND publishDate' );
		}
		$post_date         = sprintf( '%s-%s-%s %s:%s:00', $matches_date[3], $matches_date[1], $matches_date[2], $matches_date[4], $matches_date[5] );
		$data['post_date'] = $post_date;

		// Authors.
		// div.author-name might or might not have <a>s with links to author page.
		$authors_text         = $this->filter_selector( 'div.author-name', $this->crawler );
		if ( $authors_text ) {
			$data['post_authors'] = $this->filter_author_names( $authors_text );
		}
		$data['author_links'] = [];
		// If there is one or more links to author pages, save them to be processed after import.
		$author_link_crawler = $this->filter_selector_element( 'div.author-name > a', $this->crawler, $single = false );
		if ( $author_link_crawler ) {
			foreach ( $author_link_crawler->getIterator() as $author_link_node ) {
				$data['author_links'][] = $author_link_node->getAttribute( 'href' );
			}
		}

		// Featured image.
		$featured_image = $this->filter_selector_element( 'div.page-lead-media > figure > img', $this->crawler );
		if ( $featured_image ) {
			$featured_image_src         = $featured_image->getAttribute( 'src' );
			$data['featured_image_src'] = $featured_image_src;

			$featured_image_alt         = $featured_image->getAttribute( 'alt' ) ?? null;
			$data['featured_image_alt'] = $featured_image_alt;

			$featured_image_caption         = $this->filter_selector( 'div.page-lead-media > figure > div.figure-content > div.figure-caption', $this->crawler ) ?? null;
			$data['featured_image_caption'] = $featured_image_caption;

			$featured_image_credit         = $this->filter_selector( 'div.page-lead-media > figure > div.figure-content > div.figure-credit', $this->crawler );
			$featured_image_credit         = $this->format_featured_image_credit( $featured_image_credit ) ?? null;
			$data['featured_image_credit'] = $featured_image_credit;
		}

		/**
		 * Category i.e. "Section".
		 * Section name is located both in <meta> element:
		 *      <meta property="article:section" content="UC Santa Cruz">
		 * and in <script> element data:
		 *      $script_data['sectionName]
		 * but in <script> it's in a slug form, e.g. "uc-santa-cruz", so we'll use <meta> for convenience.
		 */
		$category_name = null;
		$section_meta_crawler  = $this->filter_selector_element( 'meta[property="article:section"]', $this->crawler );
		if ( $section_meta_crawler ) {
			$category_name         = $section_meta_crawler->getAttribute( 'content' );
		} else {
			if ( ! isset( $script_data['sectionName'] ) || empty( $script_data['sectionName'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'NOT FOUND category_name %s', $url ) );
			}
			$category_name = ucfirst( $script_data['sectionName'] );
		}
		if ( ! $category_name ) {
			throw new \UnexpectedValueException( sprintf( 'NOT FOUND category_name %s', $url ) );
		}
		$data['category_name'] = $category_name;

		// Parent category.
		// E.g. "higher-ed"
		$section_parent_slug          = $script_data['sectionParentPath'] ?? null;
		$category_parent_name         = self::SECTIONS[ $section_parent_slug ] ?? null;
		$data['category_parent_name'] = $category_parent_name;

		// Tags.
		$tags      = [];
		$a_crawler = $this->filter_selector_element( 'div.tags > a', $this->crawler, $single = false );
		if ( $a_crawler && $a_crawler->getIterator()->count() > 0 ) {
			foreach ( $a_crawler as $a_node ) {
				$tags[] = $a_node->nodeValue;
			}
		}
		// Tag "Promoted Content" found in <script> element too.
		$tags_promoted_content = $script_data['tags'] ?? null;
		// Add both tags.
		$data['tags']                  = ! empty( $tags ) ? $tags : null;
		$data['tags_promoted_content'] = $tags_promoted_content;

		// Presented by.
		/**
		 * E.g. "Promoted Content"
		 * This data is also found in <meta property="article:tag" content="Promoted Content">.
		 */
		$presented_by         = $this->filter_selector( 'div.brand-content-name', $this->crawler ) ?? null;
		$data['presented_by'] = $presented_by;

		return $data;
	}

	public function format_featured_image_credit( $featured_image_credit ) {
		if ( $featured_image_credit ) {
			$featured_image_credit = trim( $featured_image_credit, ' ()' );
		}

		return $featured_image_credit;
	}

	/**
	 * @param $authors_text
	 *
	 * @return array
	 */
	public function filter_author_names( $authors_text ) {

		// Replace   with regular spaces.
		$authors_text = str_replace( ' ', ' ', $authors_text );

		$authors_text = trim( $authors_text );
		$authors_text = preg_replace( '/^By: /', '', $authors_text );
		$authors_text = preg_replace( '/^By /', '', $authors_text );
		$authors_text = preg_replace( '/^Written by: /', '', $authors_text );
		$authors_text = preg_replace( '/^Written by /', '', $authors_text );

		// Explode names by comma.
		$authors_text = str_replace( ', ', ',', $authors_text );
		$author_names = explode( ',', $authors_text );

		// Trim all names (wo/ picking up " " spaces).
		$author_names = array_map(
			function( $value ) {
				return trim( $value, '  ' );
			},
			$author_names
		);

		return $author_names;
	}

	/**
	 * Crawls content by CSS selector.
	 * Can get text only, or full HTML content.
	 * Can sanitize text optionally
	 *
	 * @param $selector
	 * @param $dom_crawler
	 * @param $get_text
	 * @param $sanitize_text
	 *
	 * @return string|null
	 */
	public function filter_selector( $selector, $dom_crawler, $get_text = true, $sanitize_text = true ) {
		$text = null;

		$found_element = $this->data_parser->get_element_by_selector( $selector, $dom_crawler, $single = true );
		if ( $found_element && true === $get_text ) {
			// Will return text cleared from formatting.
			$text = $found_element->textContent;
		} elseif ( $found_element && false === $get_text ) {
			// Will return HTML.
			$text = $found_element->ownerDocument->saveHTML( $found_element );
		}
		if ( $found_element && true === $sanitize_text ) {
			$text = sanitize_text_field( $text );
		}

		return $text;
	}

	/**
	 * Gets Crawler node by CSS selector.
	 *
	 * @param $selector
	 * @param $dom_crawler
	 *
	 * @return false|Crawler
	 */
	public function filter_selector_element( $selector, $dom_crawler, $single = true ) {
		$found_element = $this->data_parser->get_element_by_selector( $selector, $dom_crawler, $single );

		return $found_element;
	}

	/**
	 * @param $url     URL to scrape.
	 * @param $retried Number of times this function was retried.
	 * @param $retries Number of times to retry.
	 * @param $sleep   Number of seconds to sleep between retries.
	 *
	 * @return string|array Body HTML string or Response array from \wp_remote_get() in case of error.
	 */
	public function wp_remote_get_with_retry( $url, $retried = 0, $retries = 3, $sleep = 2 ) {

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 60,
				'user-agent' => 'Newspack Scraper Migrator',
			]
		);

		// Retry if error, or if response code is not 200 and retries are not exhausted.
		if (
			( is_wp_error( $response ) || ( 200 != $response['response']['code'] ) )
			&& ( $retried < $retries )
		) {
			sleep( $sleep );
			$retried++;
			$response = $this->wp_remote_get_with_retry( $url, $retried, $retries, $sleep );
		}

		// If everything is fine, return body.
		if ( ! is_wp_error( $response ) && ( 200 == $response['response']['code'] ) ) {
			$body = wp_remote_retrieve_body( $response );

			return $body;
		}

		// If not OK, return response array.
		return $response;
	}

	/**
	 * Temp dev command for stuff and things.
	 *
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_dev( $pos_args, $assoc_args ) {


		/**
		 * Get which of the provided URLs were already imported and their post IDs.
		 */
		global $wpdb;
		$urls = include( '/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/374_urls.php' );
		$csv = 'url,post_id' . "\n";
		foreach ( $urls as $url ) {
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::META_POST_ORIGINAL_URL, $url ) );
			if ( $post_id ) {
				$csv .= sprintf( '%s,%d' . "\n", $url, $post_id );
			} else {
				$csv .= sprintf( '%s,%s' . "\n", $url, '' );
			}
		}
		file_put_contents( '374_urls_to_post_ids.csv', $csv );
		return;


		/**
		 * Extract URLs to live posts from LL's backend CMS editor HTML files.
		 */
		$paths_to_cms_htmls = [
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/1_2',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/3',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/4',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/5',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/6',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/7',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/8',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/9',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/10',
			'/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape_537/CMS_sources/11',
		];
		$public_urls = [];

		// Get all .html files in $path_to_cms_htmls.
		foreach ( $paths_to_cms_htmls as $path_to_cms_htmls ) {
			$files = glob( $path_to_cms_htmls . '/*.html' );
			foreach ( $files as $file ) {

				// Get HTML and feed to crawler.
				$html = file_get_contents( $file );
				$this->crawler->clear();
				$this->crawler->add( $html );

				// $data['name'] = trim( $this->filter_selector( 'div.widget-urlsItemLabel > a', $this->crawler ) );

				$public_url_crawler = $this->filter_selector_element( 'div.widget-urlsItemLabel > a', $this->crawler, $single = true );
				$public_url = $public_url_crawler ? $public_url_crawler->getAttribute( 'href' ) : null;
				if ( is_null( $public_url ) ) {
					$d=1;
				}

				$public_urls[] = $public_url;
			}
		}

		$public_urls = array_unique( $public_urls );
		file_put_contents( 'public_urls.txt', implode( "\n", $public_urls ) );

		return;

		/**
		 * Get list of remaining URLs to scrape and import.
		 *          /Users/ivanuravic/www/lookoutlocalx/app/public/0_run_scrapeimport/0_1_prelaunch_test/0__all_urls.txt
		 *  minus   all in DB
		 *  minus   not imported.
		 */
		global $wpdb;
		$all_urls = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocalx/app/public/0_run_scrapeimport/0_1_prelaunch_test/0__all_urls.txt' ) );
		$not_imported = [  ];
		$remaining = [];
		foreach ( $all_urls as $url ) {
			if ( ! $url || empty( $url ) ) {
				continue;
			}
			if ( in_array( $url, $not_imported ) ) {
				continue;
			}
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::META_POST_ORIGINAL_URL, $url ) );
			if ( $post_id ) {
				continue;
			}

			$remaining[] = $url;
		}

		return;


		/**
		 * Compare URLs they found VS URLs sitemap has.
		 *  => result, we got all of these, none were missed.
		 */
		$their_urls = [  ];
		$sitemap_urls = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocalx/app/public/0_run_scrapeimport/0_2_prelaunch_scrape/0__all_urls.txt' ) );
		$new_urls = [];
		foreach ( $their_urls as $url ) {
			$url = trim( $url );
			if ( ! in_array( $url, $sitemap_urls ) ) {
				$new_urls[] = $url;
			}
		}

		return;



		global $wpdb;

		$urls = [];
		$lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocalx/app/public/0_run_scrapeimport/ll2_err__unknown_enchancements_divs.json' ) );
		foreach ( $lines as $line ) {
			$data = json_decode( $line, true );
			if ( ! $data ) {
				continue;
			}

			$urls[ $data['url'] ] = true;
		}

		$urls = array_keys( $urls );


		return;

		/**
		 * Prepare out list of URLs.
		 * - GET SUCCESSFULLY IMPORTED URLS "2nd_import_after_demo_full_round.txt"
		 * == ALL URLS /Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape/0__all_urls.txt
		 * - MINUS NOT FOUND HTML FILES (probably 404)
		 * - MINUS ll2_err__failed_imports.log (import1, template)
		 * - MINUS ll2_err__unknown_enchancements_divs.json
		 */
		$path = '/Users/ivanuravic/www/lookoutlocalx/app/public/0_scrape/0_STAGING_IMPORT/files';
		/**
		 * List of all URLs obtained from their sitemap roughly around Oct 15th.
		 */
		$all_urls_file = '0__all_urls.txt';
		$all_urls = array_diff( explode( "\n", file_get_contents( $path . '/' . $all_urls_file ) ) , [ '' ] );

		/**
		 * List of URLs which were not successfully scraped from their live (most probably 404s) and therefore HTML files aren't availab.e
		 */
		$not_found_html_files_file = 'not_found_url_files.txt';
		$not_found_html_files = array_diff( explode( "\n", file_get_contents( $path . '/' . $not_found_html_files_file ) ) , [ '' ] );

		/**
		 * List of URLs which were successfully imported by import1-* command, due to a different template.
		 */
		$ll2_err__failed_imports = 'll2_err__failed_imports.log';
		$ll2_err__failed_imports_urls = [];
		$file_stream = fopen( $path . '/' . $ll2_err__failed_imports, 'r' );
		while ( ( $line = fgets( $file_stream ) ) !== false ) {
			if ( false !== strpos($line, 'URL:' ) ) {
				// Extract the URL from the line.
				$url = preg_match('/https:\/\/[^\s]+/', $line, $matches);
				if ( $url ) {
					$ll2_err__failed_imports_urls[] = $matches[0];
				}
			}
		}
		fclose( $file_stream );

		/**
		 * List of URLs which still have undetected/unvetted div.enchancements.
		 */
		$ll2_err__unknown_enchancements_divs_json = 'll2_err__unknown_enchancements_divs.json';
		$ll2_err__unknown_enchancements_divs_lines = explode( "\n", file_get_contents( $path . '/' . $ll2_err__unknown_enchancements_divs_json ) );
		$urls_w_unknown_enchancements = [];
		foreach ( $ll2_err__unknown_enchancements_divs_lines as $json ) {
			$data = json_decode( $json, true );
			if ( $data ) {
				$urls_w_unknown_enchancements[] = $data['url'];
			}
		}

		/**
		 * Combine URLs into selected.
		 */
		$selected_urls = $all_urls;
		$selected_urls = array_diff( $selected_urls, $not_found_html_files );
		$selected_urls = array_diff( $selected_urls, $ll2_err__failed_imports_urls );
		$selected_urls = array_diff( $selected_urls, $urls_w_unknown_enchancements );

		/**
		 * Get diff of non selected.
		 */
		$nonselected_urls = array_diff( $all_urls, $selected_urls );

		/**
		 * Save results to files.
		 */
		file_put_contents( $path . '/0__selected_urls.txt', implode( "\n", $selected_urls ) );
		file_put_contents( $path . '/0__nonselected_urls.txt', implode( "\n", $nonselected_urls ) );

		return;

		/**
		 * Locate "authorable.authors".
		 */
		// take example post from live with known author "Thomas Sawano"
		$json = $wpdb->get_var( "select data from newspack_entries where slug = 'editorial-newsletter-test-do-not-publish';" );
		// $json = $wpdb->get_var( "select data from newspack_entries where slug = 'ucsc-archive-10-000-photos-santa-cruz-history';" );
		$data = json_decode( $json, true );

		// Draft status.
		$draft  = $data['cms.content.draft'] ?? false;
		$draft2 = 'cms.content.draft' == $data['dari.visibilities'][0] ?? false;

		/**
		 * Has:
		 * authorable.authors = {array[1]}
		 * 0 = {array[2]}
		 * _ref = "00000182-b2df-d6aa-a783-b6dfd7b50000"
		 * _type = "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
		 */
		foreach ( $data['authorable.authors'] as $data_author ) {
			$authorable_author_id   = $data_author['_ref'];
			$authorable_author_type = $data_author['_type'];
			$id_like                = sprintf( '"_id":"%s"', $authorable_author_id );
			$type_like              = sprintf( '"_type":"%s"', $authorable_author_type );
			// Find author in DB.
			$author_json = $wpdb->get_var( "select data from Record where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%';" );
			// Dev test:
			// $author_json = <<<JSON
			// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano"],"cms.directory.pathTypes":{"00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano":"PERMALINK"},"cms.content.publishDate":1660858690827,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660927400870,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","features.disabledFeatures":[],"shared.content.rootId":null,"shared.content.sourceId":null,"shared.content.version":null,"canonical.canonicalUrl":null,"promotable.hideFromDynamicResults":false,"catimes.seo.suppressSeoSiteDisplayName":false,"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"cms.seo.keywords":[],"cms.seo.robots":[],"commentable.enableCommenting":false,"feed.disableFeed":false,"feed.renderFullContent":false,"feed.enabledFeedItemTypes":[],"image":{"_ref":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"cover":{"_ref":"00000182-b2de-d6aa-a783-b6dff3bf0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"section":{"_ref":"00000175-7fd0-dffc-a7fd-7ffd9e6a0000","_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"},"name":"Thomas Sawano","firstName":"Thomas","lastName":"Sawano","title":"Newsroom Intern","email":"thomas@lookoutlocal.com","fullBiography":"Thomas Sawano joins the Lookout team after two-and-a-half years at City on a Hill Press, the student-run newspaper at UCSC. While there, he reported on the university, arts and culture events, and the city of Santa Cruz. Thomas is deeply interested in local politics and feels fortunate to have begun his journalistic career in this town.<br/><br/>Thomas graduated in 2022 with degrees in Cognitive Science and Philosophy. Though hailing from Los Angeles, he has vowed to never live there again on account of traffic and a lack of actual weather. Thomas loves traveling, going to music festivals, and watching documentaries about the outdoors. He has recently picked up rock climbing, and hopes the sport won’t damage his typing hands <i>too </i>badly.<br/><br/>","shortBiography":"","affiliation":"Lookout Santa Cruz","isExternal":false,"theme.lookout-local.:core:page:Page.hbs._template":null,"theme.lookout-local.:core:promo:Promo.hbs.breaking":false,"theme.lookout-local.:core:promo:Promo.hbs.imageDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.descriptionDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.categoryDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.timestampDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.moreCoverageLinksDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.promoAlignment":null,"theme.lookout-local.:core:promo:Promo.hbs._template":null,"theme.lookout-local.:core:promo:Promo.amp.hbs._template":null,"cms.directory.pathsMode":"MANUAL","_id":"00000182-b2df-d6aa-a783-b6dfd7b50000","_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"}
			// JSON;
			$author = json_decode( $author_json, true );
			// Also exist ['cover']['_ref'] and ['section']['_ref'].
			$full_name  = $author['name'];
			$first_name = $author['firstName'];
			$last_name  = $author['lastName'];
			$email      = $author['email'];
			$bio        = $author['fullBiography'];
			$short_bio  = $author['shortBiography'];
			// E.g. "Newsroom Intern"
			$title = $author['title'];
			// E.g. "Lookout Santa Cruz"
			$affiliation = $author['affiliation'];
			// External to their publication.
			$is_external = $author['isExternal'];

			// Avatar image.
			$image_ref  = $author['image']['_ref'];
			$image_type = $author['image']['_type'];
			$sql        = "select data from Record where data like '{\"cms.site.owner\"%' and data like '%\"_id\":\"{$image_ref}\"%' and data like '%\"_type\":\"{$image_type}\"%' ;";
			$image_json = $wpdb->get_var( $sql );
			// Dev test:
			// $image_json = <<<JSON
			// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.content.publishDate":1660858629241,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660858674492,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","shared.content.version":"00000182-b2e4-daa2-a5fe-b2ed30fe0000","taggable.tags":[],"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"type":{"_ref":"a95896f6-e74f-3667-a305-b6a50d72056a","_type":"982a8b2a-7600-3bb0-ae68-740f77cd85d3"},"titleFallbackDisabled":false,"file":{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/thomas-sawano-white.png","contentType":"image/png","metadata":{"cms.edits":{},"originalFilename":"Thomas Sawano white.png","http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["1074663"],"Content-Type":["image/png"]},"resizes":[{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/resizes/500/thomas-sawano-white.png","contentType":"image/png","metadata":{"width":500,"height":500,"http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["349214"],"Content-Type":["image/png"]}}}],"width":1080,"File Type":{"Detected File Type Long Name":"Portable Network Graphics","Detected File Type Name":"PNG","Detected MIME Type":"image/png","Expected File Name Extension":"png"},"PNG-IHDR":{"Filter Method":"Adaptive","Interlace Method":"No Interlace","Compression Type":"Deflate","Image Height":"1080","Color Type":"True Color with Alpha","Image Width":"1080","Bits Per Sample":"8"},"PNG-pHYs":{"Pixels Per Unit X":"3780","Pixels Per Unit Y":"3780","Unit Specifier":"Metres"},"PNG-tEXt":{"Textual Data":"Comment: xr:d:DAE5wFeyjSQ:518,j:33207655899,t:22081821"},"height":1080,"cms.crops":{},"cms.focus":{"x":0.4397042465484525,"y":0.2428842504743833}}},"keywords":[],"keywordsFallbackDisabled":false,"dateUploaded":1660858629241,"caption":"","captionFallbackDisabled":false,"credit":"","creditFallbackDisabled":false,"altText":"Thomas Sawano","bylineFallbackDisabled":false,"instructionsFallbackDisabled":false,"sourceFallbackDisabled":false,"copyrightNoticeFallbackDisabled":false,"headlineFallbackDisabled":false,"categoryFallbackDisabled":false,"supplementalCategory":[],"supplementalCategoryFallbackDisabled":false,"writerFallbackDisabled":false,"countryFallbackDisabled":false,"countryCodeFallbackDisabled":false,"origTransRefFallbackDisabled":false,"metadataStateFallbackDisabled":false,"cityFallbackDisabled":false,"width":1080,"height":1080,"_id":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"}
			// JSON;
			$image = json_decode( $image_json, true );
			if ( 's3' != $image['file']['storage'] ) {
				// Debug this.
				$d = 1;
			}
			$image_url   = self::LOOKOUT_S3_SCHEMA_AND_HOSTNAME . '/' . $image['file']['path'];
			$image_title = $image['file']['metadata']['originalFilename'];
			$image_alt   = $image['altText'];
		}
		$authorable_author_id = $data['authorable.authors']['_ref'];
		// ,"_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"

		return;


		/**
		 * Get post data from newspack_entries
		 */
		$json = $wpdb->get_var( "SELECT data FROM newspack_entries where slug = 'first-image-from-nasas-james-webb-space-telescope-reveals-thousands-of-galaxies-in-stunning-detail';" );
		$data = json_decode( $json, true );
		return;


		/**
		 * Decode JSONs from file
		 */
		$lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocal/app/public/0_examine_DB_export/search/authorable_oneoff.log' ) );
		$jsons = [];
		foreach ( $lines as $line ) {
			$data = json_decode( $line, true );
			if ( ! $data ) {
				$line = str_replace( '\\\\', '\\', $line ); // Replace double escapes with just one escape.
				$data = json_decode( $line, true );
				if ( ! $data ) {
					$line = str_replace( '\\\\', '\\', $line ); // Replace double escapes with just one escape.
					$data = json_decode( $line, true );
					if ( $data ) {
						$jsons[] = $data; }
				} else {
					$jsons[] = $data; }
			} else {
				$jsons[] = $data; }
		}
		$d          = 1;
		$jsons_long = json_encode( $jsons );
		return;

	}

	public function cmd_dev_delete_all_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		WP_CLI::confirm( 'Delete all posts created by scraper ?' );

		$post_ids = $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'newspackmigration_url' and meta_value <> ''" );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			wp_delete_post( $post_id, true );
		}

		WP_CLI::success( 'All posts permanently deleted.' );
	}

	public function cmd_dev_prepare_html_files_for_import( $pos_args, $assoc_args ) {
		$urls = explode( "\n", file_get_contents( $assoc_args['file-with-urls'] ) );
		$source_path = $assoc_args['source-html-folder'];
		$destination_path = $assoc_args['destination-html-folder'];

		// Delete all files from $destination_path.
		$destination_files = glob( $destination_path . '/*' );
		foreach ( $destination_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		foreach ( $urls as $key_url => $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			// Get this URL's HTML scraping filename.
			$filename = $this->sanitize_filename( $url, 'html' );
			$file_path = $source_path . '/' . $filename;

			// Copy HTML file to $destination_path.
			if ( is_file( $file_path ) ) {
				copy( $file_path, $destination_path . '/' . $filename );
				WP_CLI::line( sprintf( "%d/%d %s %s \n", $key_url + 1, count( $urls ), $filename, $url ) );
			} else {
				WP_CLI::error( sprintf( 'Can not find file %s for URL %s', $filename, $url ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator lookoutlocal-create-custom-table`.
	 *
	 * Tried to see if we can get all relational data ourselves from `Record` table.
	 * The answer is no -- it is simply too difficult, better to scrape.
	 *
	 * @param array $pos_args   Array of positional arguments.
	 * @param array $assoc_args Array of associative arguments.
	 *
	 * @return void
	 */
	public function cmd_create_custom_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Table names.
		$record_table = self::DATA_EXPORT_TABLE;
		$custom_table = self::CUSTOM_ENTRIES_TABLE;

		// Check if Record table is here.
		$count_record_table = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_NAME = %s;', $record_table ) );
		if ( 1 != $count_record_table ) {
			WP_CLI::error( sprintf( 'Table %s not found.', $record_table ) );
		}

		echo esc_html( sprintf( 'Continuing will truncate the existing %s table. Continue? [y/n] ', $record_table ) );
		$continue = strtolower( trim( fgets( STDIN ) ) );
		if ( 'y' !== $continue ) {
			WP_CLI::error( 'Aborting.' );
		}

		// Create/truncate custom table.
		$this->create_custom_table( $custom_table, $truncate = true );

		// Read from $record_table and write just posts entries to $custom_table.
		$offset        = 0;
		$batchSize     = 1000;
		$total_rows    = $wpdb->get_var( "SELECT count(*) FROM {$record_table}" );
		$total_batches = ceil( $total_rows / $batchSize );
		while ( true ) {

			WP_CLI::line( sprintf( '%d/%d getting posts from %s into %s ...', $offset, $total_rows, $record_table, $custom_table ) );

			// Query in batches.
			$sql  = "SELECT * FROM {$record_table} ORDER BY id, typeId ASC LIMIT $batchSize OFFSET $offset";
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( count( $rows ) > 0 ) {
				foreach ( $rows as $row ) {

					// Get row JSON data. It might be readily decodable, or double backslashes may have to be removed up to two times.
					$data_result = $row['data'];
					$data        = json_decode( $data_result, true );
					if ( ! $data ) {
						$data_result = str_replace( '\\\\', '\\', $data_result ); // Replace double escapes with just one escape.
						$data        = json_decode( $data_result, true );
						if ( ! $data ) {
							$data_result = str_replace( '\\\\', '\\', $data_result ); // Replace double escapes with just one escape.
							$data        = json_decode( $data_result, true );
						}
					}

					// Skip drafts.
					$draft = $data['cms.content.draft'] ?? false;
					// $draft2 = 'cms.content.draft' == $data['dari.visibilities'][0] ?? false;
					if ( $draft ) {
						continue;
					}

					// Check if this is a post.
					$slug         = $data['sluggable.slug'] ?? null;
					$title        = $data['headline'] ?? null;
					$post_content = $data['body'] ?? null;
					$is_a_post    = $slug && $title && $post_content;
					if ( ! $is_a_post ) {
						continue;
					}

					// Insert to custom table
					$wpdb->insert(
						$custom_table,
						[
							'slug' => $slug,
							'data' => json_encode( $data ),
						]
					);
				}

				$offset += $batchSize;
			} else {
				break;
			}
		}

		// Group by slugs and leave just the most recent entry.

		WP_CLI::line( 'Done' );
	}

	public function cmd_deprecated_import_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		$data_jsons = $wpdb->get_col( 'SELECT data from %s', self::CUSTOM_ENTRIES_TABLE );
		foreach ( $data_jsons as $data_json ) {
			$data = json_encode( $data_json, true );

			// Get post data.
			$slug         = $data['sluggable.slug'];
			$title        = $data['headline'];
			$subheadline  = $data['subHeadline'];
			$post_content = $data['body'];
			$post_date    = $this->convert_epoch_timestamp_to_wp_format( $data['cms.content.publishDate'] );

			// Create post.
			$post_args = [
				'post_title'   => $title,
				'post_content' => $post_content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_date'    => $post_date,
			];
			$post_id   = wp_insert_post( $post_args );


			// Get more postmeta.
			$postmeta = [
				'newspackmigration_commentable.enableCommenting' => $data['commentable.enableCommenting'],
			];
			if ( $subheadline ) {
				$postmeta['newspackmigration_post_subtitle'] = $subheadline;
			}


			// Get more post data to update all at once.
			$post_modified    = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified' => $post_modified,
			];


			// Post URL.
			// Next -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?

			// Post excerpt.
			// Next -- find excerpt.


			// Featured image.
			$data['lead'];
			// These two fields:
			// "_id": "00000184-6982-da20-afed-7da6f7680000",
			// "_type": "52f00ba5-1f41-3845-91f1-1ad72e863ccb"
			$data['lead']['leadImage'];
			// Can be single entry:
			// "_ref": "0000017b-75b6-dd26-af7b-7df6582f0000",
			// "_type": "4da1a812-2b2b-36a7-a321-fea9c9594cb9"
			$caption      = $data['lead']['caption'];
			$hide_caption = $data['lead']['hideCaption'];
			$credit       = $data['lead']['credit'];
			$alt          = $data['lead']['altText'];
			// Next -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );


			// Authors.
			// Next - search these two fields. Find bios, avatars, etc by checking staff pages at https://lookout.co/santacruz/about .
			$data['authorable.authors'];
			// Can be multiple entries:
			// [
			// {
			// "_ref": "0000017e-5a2e-d675-ad7e-5e2fd5a00000",
			// "_type": "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
			// }
			// ]
			$data['authorable.oneOffAuthors'];
			// Can be multiple entries:
			// [
			// {
			// "name":"Corinne Purtill",
			// "_id":"d6ce0bcd-d952-3539-87b9-71bdb93e98c7",
			// "_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// },
			// {
			// "name":"Sumeet Kulkarni",
			// "_id":"434ebcb2-e65c-32a6-8159-fb606c93ee0b",
			// "_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// }
			// ]

			$data['authorable.primaryAuthorBioOverride'];
			// ? Next - search where not empty and see how it's used.
			$data['hasSource.source'];
			// Can be single entry:
			// "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			// "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


			// Categories.
			// Next -- is this a taxonomy?
			$data['sectionable.section'];
			// Can be single entry:
			// "_ref": "00000180-62d1-d0a2-adbe-76d9f9e7002e",
			// "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			$data['sectionable.secondarySections'];
			// Can be multiple entries:
			// [
			// {
			// "_ref": "00000175-7fd0-dffc-a7fd-7ffd9e6a0000",
			// "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			// }
			// ]


			// Tags.
			$data['taggable.tags'];
			// Next -- find tags
			// Can be multiple entries:
			// [
			// {
			// "_ref": "00000175-ecb8-dadf-adf7-fdfe01520000",
			// "_type": "90602a54-e7fb-3b69-8e25-236e50f8f7f5"
			// }
			// ]


			// Save postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}


			// Update post data.
			if ( ! empty( $post_update_data ) ) {
				$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );
			}
		}

	}

	public function convert_epoch_timestamp_to_wp_format( $timestamp ) {
		$timestamp_seconds = intval( $timestamp ) / 1000;
		$readable          = date( 'Y-m-d H:i:s', $timestamp_seconds );

		return $readable;
	}

	/**
	 * @param $table_name
	 * @param $truncate
	 *
	 * @return void
	 */
	public function create_custom_table( $table_name, $truncate = false ) {
		global $wpdb;

		$wpdb->get_results(
			"CREATE TABLE IF NOT EXISTS {$table_name} (
				`id` INT unsigned NOT NULL AUTO_INCREMENT,
				`slug` TEXT,
				`data` TEXT,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB;"
		);

		if ( true === $truncate ) {
			$wpdb->get_results( "TRUNCATE TABLE {$table_name};" );
		}
	}
}
