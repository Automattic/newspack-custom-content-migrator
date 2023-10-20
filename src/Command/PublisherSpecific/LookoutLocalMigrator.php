<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\PHP as PHP_Utils;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Newspack_Scraper_Migrator_Util;
use \Newspack_Scraper_Migrator_HTML_Parser;
use \WP_CLI;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use DOMElement;

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

		$this->attachments = new Attachments();
		$this->logger      = new Logger();
		$this->scraper     = new Newspack_Scraper_Migrator_Util();
		$this->crawler     = new Crawler();
		$this->data_parser = new Newspack_Scraper_Migrator_HTML_Parser();
		$this->cap         = new CoAuthorPlus();
		$this->posts       = new Posts();
		$this->gutenberg   = new GutenbergBlockGenerator();
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
	public function clean_up_scraped_html( $post_id, $url, $post_content, $log_need_oembed_resave, $log_err_img_download, $log_unknown_enchancement_divs ) {

		$post_content_updated = null;

		$this->crawler->clear();
		$this->crawler->add( $post_content );

		/**
		 * CONTENT TYPE 1. STORY STACK
		 *   - content is stored in: div.story-stack-story
		 *   - e.g. https://lookout.co/santacruz/coast-life/story/2023-05-19/pescadero-day-trip-sea-lions-ano-nuevo-award-winning-tavern-baby-goats
		 *
		 * To locate the content in story stack, it's needed to traverse through all div.story-stack-story and find two elements:
		 *      - find .story-stack-story-title, and if exists we will encapsulate it in a div.rich-text-body so that it can be fed to the rich-text-body crawler
		 *      - find div.rich-text-body (which contains the .story-stack-story-body), and also simply feed it to crawler
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

		// The main crawler.
		if ( $div_content_crawlers ) {

			foreach ( $div_content_crawlers as $div_content_crawler ) {

				// Traverse all the child nodes.
				foreach ( $div_content_crawler->childNodes->getIterator() as $key_domelement => $domelement ) {

					// Skip if blank.
					$html_domelement = $domelement->ownerDocument->saveHTML( $domelement );
					if ( empty( trim( $html_domelement ) ) ) {
						continue;
					}

					// div.enhancement elements can get transformed or skipped.
					$custom_html = null;
					$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( 'enhancement' == $domelement->getAttribute( 'class' ) );
					if ( $is_div_class_enhancement ) {
						$custom_html = $this->transform_div_enchancement( $domelement, $post_id, $url, $log_need_oembed_resave, $log_err_img_download, $log_unknown_enchancement_divs );
					}

					// If $custom_html is null, the element's original HTML will be used. If it's a string other than null (empty or transformed), element's HTML will be substituted/transformed.
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

		return $post_content_updated;
	}

	/**
	 * This function transforms, skips or whitelists a DOMElement and returns the resulting HTML:
	 *      - if null is returned, the HTML will be used as is
	 *      - if empty string is returned, the HTML will be skipped
	 *      - if custom HTML string is returned, the HTML will be replaced with it
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
		string $log_unknown_enchancement_divs,
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
				$attachment_id    = $this->get_or_download_image( $log_err_img_download, $image_data[ 'src' ], $title = null, $caption = null, $description = null, $image_data[ 'alt' ], $post_id, $image_data[ 'credit' ] );
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




		} else {

			/**
			 * Debug all other types of div.enchancement.
			 */
			$dbg_enchancement_html = $domelement->ownerDocument->saveHTML( $domelement );
			$this->logger->log(
				$log_unknown_enchancement_divs,
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
	public function qa_remaining_div_enhancements( $log, $post_id, $post_content ) {

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
			$is_div_class_enhancement = ( isset( $domelement->tagName ) && 'div' == $domelement->tagName ) && ( 'enhancement' == $domelement->getAttribute( 'class' ) );
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
		$log_wrong_urls = 'll2_debug__wrong_urls.log';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_wrong_urls, $ts, false );

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
					$this->logger->log( $log_wrong_urls, sprintf( 'URL: %s CODE: %s MESSAGE: %s', $url, $get_result['response']['code'], $msg ), $this->logger::WARNING );
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
		WP_CLI::line( sprintf( '❗️  %s', $log_wrong_urls ) );
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
		$log_wrong_urls                   = 'll2_debug__wrong_urls.log';
		$log_all_author_names             = 'll2_debug__all_author_names.log';
		$log_all_tags                     = 'll2_debug__all_tags.log';
		$log_all_tags_promoted_content    = 'll2_debug__all_tags_promoted_content.log';
		$log_err_importing_featured_image = 'll2_err__featured_image.log';
		$log_err_img_download             = 'll2_err__img_download.log';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_failed_imports, $ts, false );
		$this->logger->log( $log_wrong_urls, $ts, false );
		$this->logger->log( $log_all_author_names, $ts, false );
		$this->logger->log( $log_all_tags, $ts, false );
		$this->logger->log( $log_all_tags_promoted_content, $ts, false );
		$this->logger->log( $log_err_importing_featured_image, $ts, false );
		$this->logger->log( $log_err_img_download, $ts, false );

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
			 * Create or update post.
			 */
			WP_CLI::line( sprintf( "\n" . '%d/%d Importing %s ...', $key_html_file + 1, count( $html_files ), $url ) );

			// Crawl and extract all useful data from HTML
			try {
				$crawled_data = $this->crawl_post_data_from_html( $html, $url );
			} catch ( \UnexpectedValueException $e ) {
				$this->logger->log( $log_failed_imports, sprintf( 'URL: %s MESSAGE: %s', $url, $e->getMessage() ), $this->logger::WARNING );
				continue;
			}

			// Get slug from URL.
			$slug = $this->get_slug_from_url( $url );

			// QA.
			if ( empty( $crawled_data['post_title'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'post_title not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['post_content'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'post_content not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $slug ) ) {
				throw new \UnexpectedValueException( sprintf( 'slug not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['post_date'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'post_date not found for ID %d URL %s', $post_id, $url ) );
			} elseif ( empty( $crawled_data['category_name'] ) ) {
				throw new \UnexpectedValueException( sprintf( 'category_name not found for ID %d URL %s', $post_id, $url ) );
			}

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
			$ga_ids = [];
			// Get/create GAs.
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
		$log_enhancements              = 'll2_qa__enhancements.log';
		$log_need_oembed_resave        = 'll2__need_oembed_resave.log';
		$log_err_img_download          = 'll2_err__img_download.log';
		$log_unknown_enchancement_divs = 'll2_err__unknown_enchancement_divs.json';
		// Hit timestamps on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_post_ids_updated, $ts, false );
		$this->logger->log( $log_gas_urls_updated, $ts, false );
		$this->logger->log( $log_err_gas_updated, $ts, false );
		$this->logger->log( $log_enhancements, $ts, false );
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

			$post_content_updated = $this->clean_up_scraped_html( $post_id, $original_url, $post_content, $log_need_oembed_resave, $log_err_img_download, $log_unknown_enchancement_divs );
			if ( is_null( $post_content_updated ) ) {
				throw new \UnexpectedValueException( 'Check post_content_updated is null -- due to unknown template.' );
			}

			// If post_content was updated.
			if ( ! empty( $post_content_updated ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );
				$this->logger->log( $log_post_ids_updated, sprintf( 'Updated %d', $post_id ), $this->logger::SUCCESS );
			}

			// QA remaining 'div.enhancement's.
			$this->qa_remaining_div_enhancements( $log_enhancements, $post_id, ! empty( $post_content_updated ) ? $post_content_updated : $post_content );
		}


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


		WP_CLI::line(
			'Done. QA the following logs:'
			. "\n  - ❗  ERRORS: $log_err_gas_updated"
			. "\n  - ♻️️  $log_need_oembed_resave"
			. "\n  - ⚠️  $log_enhancements"
			. "\n  - 👍  $log_post_ids_updated"
			. "\n  - 👍  $log_gas_urls_updated"
			. "\n  - 👍  $log_unknown_enchancement_divs"
		);
		wp_cache_flush();
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
		 * CONTENT TYPE 2.
		 *      - div#pico
		 */
		if ( ! $div_content_crawler ) {
			/**
			 * There can be multiple div#pico elements.
			 * This here was for when I thought there was just a single div#pico element:
			 *      $post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
			 */
			$post_content_crawler = $this->filter_selector_element( 'div#pico', $this->crawler, $single = false );
			if ( $post_content_crawler ) {
				foreach ( $post_content_crawler->getIterator() as $post_content_node ) {
					$post_content .= ! empty( $post_content ) ? "\n\n" : '';
					$post_content .= $post_content_node->ownerDocument->saveHTML( $post_content_node );
				}
			}

			$data['_layout_type'] = self::META_POST_LAYOUT_REGULAR;
		}


		if ( empty( $post_content ) ) {
			$post_content = $this->filter_selector( 'div.rich-text-article-body-content', $this->crawler, false, false );
		}
		if ( empty( $post_content ) ) {
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
		if ( is_null( $authors_text ) ) {
			$authors_text = 'NO_AUTHOR_FOUND';
		}
		$data['post_authors'] = $this->filter_author_names( $authors_text );
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
		$section_meta_crawler  = $this->filter_selector_element( 'meta[property="article:section"]', $this->crawler );
		$category_name         = $section_meta_crawler->getAttribute( 'content' );
		$data['category_name'] = $category_name;
		if ( ! $category_name ) {
			throw new \UnexpectedValueException( sprintf( 'NOT FOUND category_name %s', $url ) );
		}

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
		$featured_image_credit = trim( $featured_image_credit, ' ()' );

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
		global $wpdb;

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
		WP_CLI::confirm( 'Delete all posts?' );

		$post_ids = $this->posts->get_all_posts_ids();
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

		$continue = PHP_Utils::readline( sprintf( 'Continuing will truncate the existing %s table. Continue? [y/n] ', $record_table ) );
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

	public function should_url_be_skipped( $url ) {
		$skip_urls = [
			'https://lookout.co/santacruz/about',
			'https://lookout.co/santacruz/access-democracy',
			'https://lookout.co/santacruz/advertise-with-us',
			'https://lookout.co/santacruz/aptos',
			'https://lookout.co/santacruz/best-of-lookout',
			'https://lookout.co/santacruz/business-technology',
			'https://lookout.co/santacruz/business-technology/hiring',
			'https://lookout.co/santacruz/business-technology/local-business',
			'https://lookout.co/santacruz/business-technology/recovery',
			'https://lookout.co/santacruz/business-technology/tech',
			'https://lookout.co/santacruz/capitola-and-soquel',
			'https://lookout.co/santacruz/city-life',
			'https://lookout.co/santacruz/civic-life',
			'https://lookout.co/santacruz/civic-life/development',
			'https://lookout.co/santacruz/civic-life/government',
			'https://lookout.co/santacruz/civic-life/politics',
			'https://lookout.co/santacruz/coast-life',
			'https://lookout.co/santacruz/community-voices',
			'https://lookout.co/santacruz/company-q-a',
			'https://lookout.co/santacruz/coronavirus',
			'https://lookout.co/santacruz/coronavirus/covid-economy',
			'https://lookout.co/santacruz/coronavirus/covid-en-espanol',
			'https://lookout.co/santacruz/coronavirus/covid-k-12',
			'https://lookout.co/santacruz/coronavirus/covid-pm',
			'https://lookout.co/santacruz/coronavirus/covid-south-county',
			'https://lookout.co/santacruz/coronavirus/covid-today',
			'https://lookout.co/santacruz/coronavirus/pandemic-life',
			'https://lookout.co/santacruz/coronavirus/vaccine-watch',
			'https://lookout.co/santacruz/editorials',
			'https://lookout.co/santacruz/education',
			'https://lookout.co/santacruz/education/higher-ed',
			'https://lookout.co/santacruz/education/higher-ed/cabrillo-college',
			'https://lookout.co/santacruz/election-2022',
			'https://lookout.co/santacruz/election-2022/statewatch',
			'https://lookout.co/santacruz/endorsements',
			'https://lookout.co/santacruz/environment',
			'https://lookout.co/santacruz/environment/climate',
			'https://lookout.co/santacruz/environment/wildfires',
			'https://lookout.co/santacruz/faq-membership',
			'https://lookout.co/santacruz/food-drink',
			'https://lookout.co/santacruz/food-drink/restaurants-dining',
			'https://lookout.co/santacruz/footer-nav-partners',
			'https://lookout.co/santacruz/guides',
			'https://lookout.co/santacruz/guides/santa-cruz-food-insecurity',
			'https://lookout.co/santacruz/health-wellness',
			'https://lookout.co/santacruz/health-wellness/fitness',
			'https://lookout.co/santacruz/high-school-student-access',
			'https://lookout.co/santacruz/in-the-news1',
			'https://lookout.co/santacruz/j1z4nvffccq-123',
			'https://lookout.co/santacruz/job-board',
			'https://lookout.co/santacruz/jobs',
			'https://lookout.co/santacruz/jobs-climate-environment',
			'https://lookout.co/santacruz/lookout-educator-page',
			'https://lookout.co/santacruz/lookout-pm-friday',
			'https://lookout.co/santacruz/lookout-pm/',
			'https://lookout.co/santacruz/meet-the-team',
			'https://lookout.co/santacruz/membership',
			'https://lookout.co/santacruz/my-account',
			'https://lookout.co/santacruz/news',
			'https://lookout.co/santacruz/news/lookout-pm-archive',
			'https://lookout.co/santacruz/news/student-access-engagement',
			'https://lookout.co/santacruz/news/sunday-reads-archive',
			'https://lookout.co/santacruz/news/weekender-archive',
			'https://lookout.co/santacruz/newsletter-text-center',
			'https://lookout.co/santacruz/notice-of-right-to-opt-out',
			'https://lookout.co/santacruz/partners',
			'https://lookout.co/santacruz/partners/civic',
			'https://lookout.co/santacruz/partners/civic-groups',
			'https://lookout.co/santacruz/partners/marketing',
			'https://lookout.co/santacruz/people/aidan-warzecha-watson',
			'https://lookout.co/santacruz/people/alex-sibille',
			'https://lookout.co/santacruz/people/amber-turpin',
			'https://lookout.co/santacruz/people/ameen-taheri',
			'https://lookout.co/santacruz/people/anna-hamai',
			'https://lookout.co/santacruz/people/arianna-fabian',
			'https://lookout.co/santacruz/people/arshnoor-bhatia',
			'https://lookout.co/santacruz/people/ashley-holmes',
			'https://lookout.co/santacruz/people/ayan-morshed',
			'https://lookout.co/santacruz/people/beki-san-martin',
			'https://lookout.co/santacruz/people/blaire-hobbs',
			'https://lookout.co/santacruz/people/brittany-ramirez',
			'https://lookout.co/santacruz/people/chris-neely',
			'https://lookout.co/santacruz/people/christian-abraham',
			'https://lookout.co/santacruz/people/dan-evans',
			'https://lookout.co/santacruz/people/dieter-holger',
			'https://lookout.co/santacruz/people/dylan-reisig',
			'https://lookout.co/santacruz/people/emily-choo',
			'https://lookout.co/santacruz/people/franny-trinidad',
			'https://lookout.co/santacruz/people/gabriel-castilla',
			'https://lookout.co/santacruz/people/gabrielle-gillette',
			'https://lookout.co/santacruz/people/giovanni-moujaes',
			'https://lookout.co/santacruz/people/grace-stetson',
			'https://lookout.co/santacruz/people/haneen-zain',
			'https://lookout.co/santacruz/people/hanna-merzbach',
			'https://lookout.co/santacruz/people/henry-bellevin',
			'https://lookout.co/santacruz/people/hillary-ojeda',
			'https://lookout.co/santacruz/people/ilana-packer',
			'https://lookout.co/santacruz/people/isabel-swafford',
			'https://lookout.co/santacruz/people/isabella-cueto',
			'https://lookout.co/santacruz/people/izzy-krause',
			'https://lookout.co/santacruz/people/jamie-keil',
			'https://lookout.co/santacruz/people/jean-yi',
			'https://lookout.co/santacruz/people/jed-williams',
			'https://lookout.co/santacruz/people/jessica-m-pasko',
			'https://lookout.co/santacruz/people/jody-biehl',
			'https://lookout.co/santacruz/people/kate-hull',
			'https://lookout.co/santacruz/people/kaya-henkes-power',
			'https://lookout.co/santacruz/people/ken-doctor',
			'https://lookout.co/santacruz/people/kevin-painchaud',
			'https://lookout.co/santacruz/people/lara-aguirre-medina',
			'https://lookout.co/santacruz/people/laura-sutherland',
			'https://lookout.co/santacruz/people/laurie-love',
			'https://lookout.co/santacruz/people/lily-belli',
			'https://lookout.co/santacruz/people/liza-monroy',
			'https://lookout.co/santacruz/people/lookout-santa-cruz-staff',
			'https://lookout.co/santacruz/people/mallory-pickett',
			'https://lookout.co/santacruz/people/maren-detlefs',
			'https://lookout.co/santacruz/people/mark-conley',
			'https://lookout.co/santacruz/people/max-chun',
			'https://lookout.co/santacruz/people/neil-strebig',
			'https://lookout.co/santacruz/people/nick-ibarra',
			'https://lookout.co/santacruz/people/nik-altenberg',
			'https://lookout.co/santacruz/people/patrick-riley',
			'https://lookout.co/santacruz/people/riley-engel',
			'https://lookout.co/santacruz/people/sherene-tagharobi',
			'https://lookout.co/santacruz/people/tamsin-mcmahon',
			'https://lookout.co/santacruz/people/thomas-frey',
			'https://lookout.co/santacruz/people/thomas-sawano',
			'https://lookout.co/santacruz/people/tulsi-kamath',
			'https://lookout.co/santacruz/people/wallace-baine',
			'https://lookout.co/santacruz/people/will-mccahill',
			'https://lookout.co/santacruz/people/xingyu-lai',
			'https://lookout.co/santacruz/places',
			'https://lookout.co/santacruz/places/681132629-123',
			'https://lookout.co/santacruz/places/705586772-123',
			'https://lookout.co/santacruz/places/750591046-123',
			'https://lookout.co/santacruz/places/atxrza5d5re-123',
			'https://lookout.co/santacruz/places/gallery/cruz-hotel-renderings',
			'https://lookout.co/santacruz/places/igxkpnfsi8g-123',
			'https://lookout.co/santacruz/places/vdpnlm4c1je-123',
			'https://lookout.co/santacruz/pleasure-point-live-oak',
			'https://lookout.co/santacruz/privacy-policy',
			'https://lookout.co/santacruz/recreation-sports',
			'https://lookout.co/santacruz/reset-password',
			'https://lookout.co/santacruz/s7ggddsforg-123',
			'https://lookout.co/santacruz/s7ggddsforg-123',
			'https://lookout.co/santacruz/san-lorenzo-valley',
			'https://lookout.co/santacruz/santa-cruz',
			'https://lookout.co/santacruz/santa-cruz-county-obituaries',
			'https://lookout.co/santacruz/santa-cruz-county-obituaries',
			'https://lookout.co/santacruz/santa-cruz-puzzle-center',
			'https://lookout.co/santacruz/santa-cruz-puzzle-center/news-quiz',
			'https://lookout.co/santacruz/santa-cruz-puzzle-center/wordrow-puzzles',
			'https://lookout.co/santacruz/santa-cruz-puzzles',
			'https://lookout.co/santacruz/scholarship',
			'https://lookout.co/santacruz/scotts-valley',
			'https://lookout.co/santacruz/storm-2023-and-recovery',
			'https://lookout.co/santacruz/storm-2023-and-recovery/mid-county',
			'https://lookout.co/santacruz/storm-2023-and-recovery/santa-cruz',
			'https://lookout.co/santacruz/storm-2023-and-recovery/santa-cruz-mountains',
			'https://lookout.co/santacruz/storm-2023-and-recovery/south-county',
			'https://lookout.co/santacruz/storm-2023-and-recovery/storm-recovery-updates',
			'https://lookout.co/santacruz/student-access',
			'https://lookout.co/santacruz/student-stories',
			'https://lookout.co/santacruz/sudoku',
			'https://lookout.co/santacruz/test-404',
			'https://lookout.co/santacruz/things-to-do',
			'https://lookout.co/santacruz/topic/2024-u-s-senate-candidates-in-santa-cruz',
			'https://lookout.co/santacruz/topic/aging-in-santa-cruz',
			'https://lookout.co/santacruz/topic/aptos',
			'https://lookout.co/santacruz/topic/aptos-property-dispute',
			'https://lookout.co/santacruz/topic/best-of-lookout-2022',
			'https://lookout.co/santacruz/topic/best-of-lookout-santa-cruz',
			'https://lookout.co/santacruz/topic/black-lives-matter-mural-coverage',
			'https://lookout.co/santacruz/topic/cabrillo-college-renaming',
			'https://lookout.co/santacruz/topic/capitola-soquel',
			'https://lookout.co/santacruz/topic/community-voices',
			'https://lookout.co/santacruz/topic/conversations-with-jody',
			'https://lookout.co/santacruz/topic/conversations-with-jody-crystal-ross',
			'https://lookout.co/santacruz/topic/coverage-of-the-2023-santa-cruz-county-fair',
			'https://lookout.co/santacruz/topic/covid-dashboard',
			'https://lookout.co/santacruz/topic/election-2022',
			'https://lookout.co/santacruz/topic/election-2022-california-attorney-general',
			'https://lookout.co/santacruz/topic/election-2022-california-controller',
			'https://lookout.co/santacruz/topic/election-2022-california-governor',
			'https://lookout.co/santacruz/topic/election-2022-santa-cruz-county-ballot-measures-explained',
			'https://lookout.co/santacruz/topic/election-2022-santa-cruz-county-supervisors-races',
			'https://lookout.co/santacruz/topic/election-2022-santa-cruz-mayors-race',
			'https://lookout.co/santacruz/topic/election-2022-state-assembly-district-races',
			'https://lookout.co/santacruz/topic/election-reaction-what-santa-cruz-county-residents-are-saying-about-election-2022',
			'https://lookout.co/santacruz/topic/evan-quarnstrom-op-eds',
			'https://lookout.co/santacruz/topic/farmers-market-coverage',
			'https://lookout.co/santacruz/topic/farmers-market-fridays',
			'https://lookout.co/santacruz/topic/how-i-got-my-job',
			'https://lookout.co/santacruz/topic/in-the-public-interest-archive',
			'https://lookout.co/santacruz/topic/instagram',
			'https://lookout.co/santacruz/topic/instagram',
			'https://lookout.co/santacruz/topic/job-board-listings',
			'https://lookout.co/santacruz/topic/joby',
			'https://lookout.co/santacruz/topic/ken-doctor-newsletter',
			'https://lookout.co/santacruz/topic/laurie-love-on-wine',
			'https://lookout.co/santacruz/topic/letter-from-the-editor',
			'https://lookout.co/santacruz/topic/letters-to-the-editor',
			'https://lookout.co/santacruz/topic/lily-belli-on-food',
			'https://lookout.co/santacruz/topic/local-elections',
			'https://lookout.co/santacruz/topic/lookout-athlete-of-the-month',
			'https://lookout.co/santacruz/topic/lookout-candidate-forum-fred-keeley-and-joy-schendledecker',
			'https://lookout.co/santacruz/topic/lookout-candidate-forum-justin-cummings-and-shebreh-kalantari-johnson',
			'https://lookout.co/santacruz/topic/lookout-pm-archive',
			'https://lookout.co/santacruz/topic/measure-d-coverage',
			'https://lookout.co/santacruz/topic/measure-n-coverage',
			'https://lookout.co/santacruz/topic/measure-o-coverage',
			'https://lookout.co/santacruz/topic/meet-your-local-farmers-market-managers',
			'https://lookout.co/santacruz/topic/more-from-claudia-sternbach',
			'https://lookout.co/santacruz/topic/more-from-daniel-delong',
			'https://lookout.co/santacruz/topic/more-from-doug-erickson',
			'https://lookout.co/santacruz/topic/more-from-jeri-ross',
			'https://lookout.co/santacruz/topic/more-from-laura-leroy',
			'https://lookout.co/santacruz/topic/more-from-marissa-messina',
			'https://lookout.co/santacruz/topic/more-from-mike-rotkin',
			'https://lookout.co/santacruz/topic/more-from-rick-longinotti',
			'https://lookout.co/santacruz/topic/more-from-tenzin-chogkyi',
			'https://lookout.co/santacruz/topic/morning-lookout-archive',
			'https://lookout.co/santacruz/topic/obituaries',
			'https://lookout.co/santacruz/topic/opinion-from-rosemary-menard',
			'https://lookout.co/santacruz/topic/otter-841-coverage',
			'https://lookout.co/santacruz/topic/oversized-vehicle-ordinance-coverage',
			'https://lookout.co/santacruz/topic/partner-content',
			'https://lookout.co/santacruz/topic/partner-content-calmatters',
			'https://lookout.co/santacruz/topic/partner-content-chalkbeat',
			'https://lookout.co/santacruz/topic/partner-content-civil-eats',
			'https://lookout.co/santacruz/topic/partner-content-edsource',
			'https://lookout.co/santacruz/topic/partner-content-inside-climate-news',
			'https://lookout.co/santacruz/topic/partner-content-kaiser-health-news',
			'https://lookout.co/santacruz/topic/partner-content-los-angeles-times',
			'https://lookout.co/santacruz/topic/partner-content-open-campus',
			'https://lookout.co/santacruz/topic/partner-content-propublica',
			'https://lookout.co/santacruz/topic/partner-content-reveal',
			'https://lookout.co/santacruz/topic/partner-content-the-marshall-project',
			'https://lookout.co/santacruz/topic/pesticides-in-the-pajaro-valley',
			'https://lookout.co/santacruz/topic/places-housing',
			'https://lookout.co/santacruz/topic/pleasure-point-live-oak',
			'https://lookout.co/santacruz/topic/prep-sports-roundups',
			'https://lookout.co/santacruz/topic/president-joe-biden-visits-santa-cruz-county',
			'https://lookout.co/santacruz/topic/promoted-content',
			'https://lookout.co/santacruz/topic/promoted-content',
			'https://lookout.co/santacruz/topic/recovery-and-equity-in-schools',
			'https://lookout.co/santacruz/topic/remembrance-2022',
			'https://lookout.co/santacruz/topic/resources-how-to-help',
			'https://lookout.co/santacruz/topic/roe-v-wade-coverage',
			'https://lookout.co/santacruz/topic/san-lorenzo-valley',
			'https://lookout.co/santacruz/topic/santa-cruz-county-business-roundup',
			'https://lookout.co/santacruz/topic/scotts-valley',
			'https://lookout.co/santacruz/topic/seeds-of-change',
			'https://lookout.co/santacruz/topic/six-blocks-a-lookout-series-at-development-happening-around-front-street-in-downtown-santa-cruz',
			'https://lookout.co/santacruz/topic/sponsored-content',
			'https://lookout.co/santacruz/topic/sponsored-content',
			'https://lookout.co/santacruz/topic/statewatch',
			'https://lookout.co/santacruz/topic/stories-from-diamond-technology-institute-students',
			'https://lookout.co/santacruz/topic/student-lookout',
			'https://lookout.co/santacruz/topic/sunday-reads-archive',
			'https://lookout.co/santacruz/topic/the-21-community-builders-who-will-inspire-and-shape-santa-cruz-county-in-2021',
			'https://lookout.co/santacruz/topic/uc-academic-workers-strike-2022',
			'https://lookout.co/santacruz/topic/unsung-santa-cruz-2022',
			'https://lookout.co/santacruz/topic/watsonville',
			'https://lookout.co/santacruz/topic/weekenderhttps://lookout.co/santacruz/',
			'https://lookout.co/santacruz/topic/welcome-to-lookout',
			'https://lookout.co/santacruz/topic/welcome-to-lookout',
			'https://lookout.co/santacruz/topic/westside-santa-cruz',
			'https://lookout.co/santacruz/ucsc-cabrillo',
			'https://lookout.co/santacruz/wallace-baine',
			'https://lookout.co/santacruz/wallace-baine/the-here-now',
			'https://lookout.co/santacruz/watsonville',
			'https://lookout.co/santacruz/weather',
			'https://lookout.co/santacruz/welcome-to-lookouts-member-center',
			'https://lookout.co/santacruz/word-search',
			'https://lookout.co/santacruz/community-voices/forums',
			'https://lookout.co/santacruz/job-board/story/2022-02-08/chief-program-officer-digital-nest',
			'https://lookout.co/santacruz/job-board/story/2022-02-09/new-local-hub-for-job-seekers-lookout-launches-job-board',
			'https://lookout.co/santacruz/job-board/story/2022-03-14/hire-local-10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-03-20/10-hot-jobs-hiring-santa-cruz-county-job-board',
			'https://lookout.co/santacruz/job-board/story/2022-03-28/hot-jobs-10-open-roles-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-04-01/correspondent-lookout-santa-cruz-hiring-job',
			'https://lookout.co/santacruz/job-board/story/2022-04-01/multimedia-correspondent-lookout-hiring-jobs',
			'https://lookout.co/santacruz/job-board/story/2022-04-04/10-hot-jobs-in-santa-cruz-county-this-week',
			'https://lookout.co/santacruz/job-board/story/2022-04-11/10-hot-jobs-in-santa-cruz-county-hiring-open-positions',
			'https://lookout.co/santacruz/job-board/story/2022-04-18/10-hot-jobs-in-santa-cruz-county-hiring-open-positions',
			'https://lookout.co/santacruz/job-board/story/2022-04-25/10-hot-jobs-in-santa-cruz-county-hiring-open-positions-april-25',
			'https://lookout.co/santacruz/job-board/story/2022-05-02/10-hot-jobs-in-santa-cruz-county-hiring-open-positions',
			'https://lookout.co/santacruz/job-board/story/2022-05-09/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-05-16/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-05-20/business-development-residency',
			'https://lookout.co/santacruz/job-board/story/2022-05-23/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-05-31/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-06-06/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-06-13/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-06-13/admissions-and-records-analyst',
			'https://lookout.co/santacruz/job-board/story/2022-06-16/10-top-jobs-at-the-county-of-santa-cruz-health-services-agency',
			'https://lookout.co/santacruz/job-board/story/2022-06-16/education-events-manager-job-hiring-lookout-santa-cruz',
			'https://lookout.co/santacruz/job-board/story/2022-06-20/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-06-27/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-07-05/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-07-11/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-07-13/santa-cruz-metro-launches-new-recruitment-incentives-addressing-unprecedented-crisis',
			'https://lookout.co/santacruz/job-board/story/2022-07-17/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-07-24/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-07-31/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-08-07/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-08-14/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-08-17/internships-news-job-hiring-lookout-santa-cruz-business',
			'https://lookout.co/santacruz/job-board/story/2022-08-17/internships-news-job-hiring-lookout-santa-cruz-education',
			'https://lookout.co/santacruz/job-board/story/2022-08-17/internships-news-job-hiring-lookout-santa-cruz-social-media',
			'https://lookout.co/santacruz/job-board/story/2022-08-19/10-top-jobs-at-the-county-of-santa-cruz-health-services-agency-august-2022',
			'https://lookout.co/santacruz/job-board/story/2022-08-21/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-08-26/10-top-jobs-at-santa-cruz-county-bank',
			'https://lookout.co/santacruz/job-board/story/2022-08-27/10-hot-jobs-in-santa-cruz-county',
			'https://lookout.co/santacruz/job-board/story/2022-09-03/10-hot-jobs-in-santa-cruz-county-september-4',
			'https://lookout.co/santacruz/job-board/story/2022-09-11/10-hot-jobs-in-santa-cruz-county-september-11',
			'https://lookout.co/santacruz/job-board/story/2022-09-17/10-hot-jobs-in-santa-cruz-county-september-18',
			'https://lookout.co/santacruz/job-board/story/2022-09-25/10-hot-jobs-in-santa-cruz-county-september-25',
			'https://lookout.co/santacruz/job-board/story/2022-10-02/10-hot-jobs-in-santa-cruz-county-october-2',
			'https://lookout.co/santacruz/job-board/story/2022-10-09/10-hot-jobs-in-santa-cruz-county-october-9',
			'https://lookout.co/santacruz/job-board/story/2022-10-16/10-hot-jobs-in-santa-cruz-county-october-16',
			'https://lookout.co/santacruz/job-board/story/2022-10-23/10-hot-jobs-in-santa-cruz-county-october-23',
			'https://lookout.co/santacruz/job-board/story/2022-10-30/10-hot-jobs-in-santa-cruz-county-october-30',
			'https://lookout.co/santacruz/job-board/story/2022-11-06/10-hot-jobs-in-santa-cruz-county-november-6',
			'https://lookout.co/santacruz/job-board/story/2022-11-11/membership-audience-growth-manager',
			'https://lookout.co/santacruz/job-board/story/2022-11-13/10-hot-jobs-in-santa-cruz-county-november-13',
			'https://lookout.co/santacruz/job-board/story/2022-11-20/10-hot-jobs-in-santa-cruz-county-november-20',
			'https://lookout.co/santacruz/job-board/story/2022-11-27/10-hot-jobs-in-santa-cruz-county-november-27',
			'https://lookout.co/santacruz/job-board/story/2023-01-01/10-hot-jobs-in-santa-cruz-county-january-1',
			'https://lookout.co/santacruz/job-board/story/2023-01-08/10-hot-jobs-in-santa-cruz-county-january-8',
			'https://lookout.co/santacruz/job-board/story/2023-01-15/10-hot-jobs-in-santa-cruz-county-january-15',
			'https://lookout.co/santacruz/job-board/story/2023-01-22/10-hot-jobs-in-santa-cruz-county-january-22',
			'https://lookout.co/santacruz/job-board/story/2023-01-29/10-hot-jobs-in-santa-cruz-county-january-29',
			'https://lookout.co/santacruz/job-board/story/2023-02-05/10-hot-jobs-in-santa-cruz-county-february-3',
			'https://lookout.co/santacruz/job-board/story/2023-02-12/10-hot-jobs-in-santa-cruz-county-february-12',
			'https://lookout.co/santacruz/job-board/story/2023-02-19/10-hot-jobs-in-santa-cruz-county-february-19',
			'https://lookout.co/santacruz/job-board/story/2023-02-26/10-hot-jobs-in-santa-cruz-county-february-26',
			'https://lookout.co/santacruz/job-board/story/2023-03-05/10-hot-jobs-in-santa-cruz-county-march-5',
			'https://lookout.co/santacruz/job-board/story/2023-03-12/10-hot-jobs-in-santa-cruz-county-march-12',
			'https://lookout.co/santacruz/job-board/story/2023-03-19/10-hot-jobs-in-santa-cruz-county-march-12',
			'https://lookout.co/santacruz/job-board/story/2023-03-26/10-hot-jobs-in-santa-cruz-county-march-26',
			'https://lookout.co/santacruz/job-board/story/2023-04-02/10-hot-jobs-in-santa-cruz-county-april-2',
			'https://lookout.co/santacruz/job-board/story/2023-04-09/10-hot-jobs-in-santa-cruz-county-april-9',
			'https://lookout.co/santacruz/job-board/story/2023-04-16/10-hot-jobs-in-santa-cruz-county-april-16',
			'https://lookout.co/santacruz/job-board/story/2023-04-23/10-hot-jobs-in-santa-cruz-county-april-23',
			'https://lookout.co/santacruz/job-board/story/2023-04-30/10-hot-jobs-in-santa-cruz-county-april-30',
			'https://lookout.co/santacruz/job-board/story/2023-05-07/10-hot-jobs-in-santa-cruz-county-may-7',
			'https://lookout.co/santacruz/job-board/story/2023-05-14/10-hot-jobs-in-santa-cruz-county-may-14',
			'https://lookout.co/santacruz/job-board/story/2023-05-21/10-hot-jobs-in-santa-cruz-county-may-21',
			'https://lookout.co/santacruz/job-board/story/2023-05-28/10-hot-jobs-in-santa-cruz-county-may-28',
			'https://lookout.co/santacruz/job-board/story/2023-06-04/10-hot-jobs-in-santa-cruz-county-june-4',
			'https://lookout.co/santacruz/job-board/story/2023-06-11/10-hot-jobs-in-santa-cruz-county-june-11',
			'https://lookout.co/santacruz/job-board/story/2023-06-18/10-hot-jobs-in-santa-cruz-county-june-18',
			'https://lookout.co/santacruz/job-board/story/2023-06-25/10-hot-jobs-in-santa-cruz-county-june-25',
			'https://lookout.co/santacruz/job-board/story/2023-07-02/10-hot-jobs-in-santa-cruz-county-july-2',
			'https://lookout.co/santacruz/job-board/story/2023-07-07/insurance-agency-representative',
			'https://lookout.co/santacruz/job-board/story/2023-07-07/insurance-sales-agency-manager',
			'https://lookout.co/santacruz/job-board/story/2023-07-09/10-hot-jobs-in-santa-cruz-county-july-9',
			'https://lookout.co/santacruz/job-board/story/2023-07-16/10-hot-jobs-in-santa-cruz-county-july-16',
			'https://lookout.co/santacruz/job-board/story/2023-07-23/10-hot-jobs-in-santa-cruz-county-july-23',
			'https://lookout.co/santacruz/job-board/story/2023-07-30/10-hot-jobs-in-santa-cruz-county-july-30',
			'https://lookout.co/santacruz/job-board/story/2023-08-01/medical-care-service-worker',
			'https://lookout.co/santacruz/job-board/story/2023-08-02/assistant-food-beverage-manager',
			'https://lookout.co/santacruz/job-board/story/2023-08-02/event-manager',
			'https://lookout.co/santacruz/job-board/story/2023-08-02/front-desk-manager-hospitality',
			'https://lookout.co/santacruz/job-board/story/2023-08-02/guest-experience-associate',
			'https://lookout.co/santacruz/job-board/story/2023-08-02/guest-experience-supervisor-hospitality',
			'https://lookout.co/santacruz/job-board/story/2023-08-03/directing-attorney',
			'https://lookout.co/santacruz/job-board/story/2023-08-06/10-hot-jobs-in-santa-cruz-county-august-6',
			'https://lookout.co/santacruz/job-board/story/2023-08-09/health-equity-officer-county-santa-cruz',
			'https://lookout.co/santacruz/job-board/story/2023-08-09/medical-assistant-county-santa-cruz',
			'https://lookout.co/santacruz/job-board/story/2023-08-15/dining-baker',
			'https://lookout.co/santacruz/job-board/story/2023-08-15/facilities-senior-building-maintenance-worker',
			'https://lookout.co/santacruz/job-board/story/2023-08-20/10-hot-jobs-in-santa-cruz-county-august-20',
			'https://lookout.co/santacruz/job-board/story/2023-08-22/resource-planner-ii-iii',
			'https://lookout.co/santacruz/job-board/story/2023-08-23/brand-ambassador',
			'https://lookout.co/santacruz/job-board/story/2023-08-25/physical-therapist',
			'https://lookout.co/santacruz/job-board/story/2023-08-27/10-hot-jobs-in-santa-cruz-county-august-27',
			'https://lookout.co/santacruz/job-board/story/2023-08-28/patient-care-coordinator',
			'https://lookout.co/santacruz/job-board/story/2023-08-28/physical-therapist',
			'https://lookout.co/santacruz/job-board/story/2023-08-29/college-programs-coordinator',
			'https://lookout.co/santacruz/job-board/story/2023-09-03/10-hot-jobs-in-santa-cruz-county-september-3',
			'https://lookout.co/santacruz/job-board/story/2023-09-07/program-manager',
			'https://lookout.co/santacruz/job-board/story/2023-09-10/10-hot-jobs-in-santa-cruz-county-september-10',
			'https://lookout.co/santacruz/job-board/story/2023-09-11/safe-routes-to-school-educator-program-coordinator',
			'https://lookout.co/santacruz/job-board/story/2023-09-11/temp-advanced-accounts-payable-coordinator',
			'https://lookout.co/santacruz/job-board/story/2023-09-15/facilities-asset-coordinator',
			'https://lookout.co/santacruz/job-board/story/2023-09-17/10-hot-jobs-in-santa-cruz-county-september-17',
			'https://lookout.co/santacruz/job-board/story/2023-09-18/dining-storekeeper',
			'https://lookout.co/santacruz/job-board/story/2023-09-19/housekeeper',
			'https://lookout.co/santacruz/job-board/story/2023-09-22/criminal-justice-investigative-correspondent-lookout-santa-cruz-hiring-job',
			'https://lookout.co/santacruz/job-board/story/2023-09-24/10-hot-jobs-in-santa-cruz-county-september-24',
			'https://lookout.co/santacruz/job-board/story/2023-09-26/associate-director-residential-community-service-program',
			'https://lookout.co/santacruz/job-board/story/2023-10-01/10-hot-jobs-in-santa-cruz-county-october-1',
			'https://lookout.co/santacruz/job-board/story/2023-10-02/dining-associate-director',
			'https://lookout.co/santacruz/job-board/story/2023-10-03/chief-building-official-chief-building-inspector',
			'https://lookout.co/santacruz/job-board/story/2023-10-03/information-technology-system-administration-analyst-ii',
			'https://lookout.co/santacruz/job-board/story/2023-10-05/job-fair',
			'https://lookout.co/santacruz/job-board/story/2023-10-06/elementary-school-teacher-assistant',
			'https://lookout.co/santacruz/job-board/story/2023-10-06/trick-or-treating-associate',
			'https://lookout.co/santacruz/job-board/story/2023-10-08/10-hot-jobs-in-santa-cruz-county-october-8',
			'https://lookout.co/santacruz/job-board/story/2023-10-11/financial-planning-and-analysis-director',
			'https://lookout.co/santacruz/job-board/story/2023-10-12/senior-medical-billing-technician',
			'https://lookout.co/santacruz/job-board/story/2023-10-15/10-hot-jobs-in-santa-cruz-county-october-15',
			'https://lookout.co/santacruz/job-board/story/2023-10-17/assistant-director-residential-education',
			'https://lookout.co/santacruz/job-board/story/2023-10-17/insurance-agency-representative',
			'https://lookout.co/santacruz/job-board/story/2023-10-17/insurance-sales-agency-manager',
			'https://lookout.co/santacruz/job-board/story/2023-10-17/member-services-representative-teller-part-time',
			'https://lookout.co/santacruz/partners/civic-groups/county-park-friends-building-our-shared-dreams-in-santa-cruz-subtitulos-en-espanol-123',
			'https://lookout.co/santacruz/partners/civic-groups/story/2022-01-28/whats-railbanking-and-why-are-santa-cruz-transit-experts-discussing-it',
			'https://lookout.co/santacruz/partners/civic-groups/story/2022-10-21/gray-whale-survival-predatory-orcas-spotted-in-baja-calfing-lagoon',
			'https://lookout.co/santacruz/partners/civic-groups/story/2023-01-03/unsung-santa-cruz-pauline-seales-climate-activism-youth-justice',
			'https://lookout.co/santacruz/partners/marketing/2023-01-19/expert-santa-cruz-county-property-management-with-a-locals-touch',
			'https://lookout.co/santacruz/partners/marketing/2023-03-15/the-iconic-catalyst-club-a-must-visit-for-music-aficionados-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/2023-03-15/we-march-because-we-believe-join-the-march-to-end-homelessness-in-santa-cruz-on-april-1',
			'https://lookout.co/santacruz/partners/marketing/2023-nexties-event-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/779740564-123',
			'https://lookout.co/santacruz/partners/marketing/d0xqnl8e2yk-123',
			'https://lookout.co/santacruz/partners/marketing/launchpad-2023-returns-for-its-sixth-year-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/sereno-1-percent-for-good-123',
			'https://lookout.co/santacruz/partners/marketing/story/2021-11-03/bay-federal-raises-nearly-11-000-for-monterey-bay-national-marine-sanctuary-foundation',
			'https://lookout.co/santacruz/partners/marketing/story/2022-06-15/7-close-to-home-rv-getaways-around-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2022-07-18/money-talks-5-steps-to-strengthen-your-financial-relationship',
			'https://lookout.co/santacruz/partners/marketing/story/2022-07-29/sts9-brings-their-psychedelic-noise-pop-show-to-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-01-02/tribute-table-program-honors-loved-ones-improves-key-state-parks-infrastructure',
			'https://lookout.co/santacruz/partners/marketing/story/2023-01-18/get-down-at-downtown-fridays-now-through-april-14-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-02-02/a-medicinal-league-of-its-own-exploring-the-health-benefits-of-kava',
			'https://lookout.co/santacruz/partners/marketing/story/2023-02-07/honorees-for-2023-nexties-announced-featuring-mak-nova-cruz-foam-jessica-yarr-and-more',
			'https://lookout.co/santacruz/partners/marketing/story/2023-02-14/how-to-spot-a-sextortion-or-romance-scam-and-what-to-do-about-it',
			'https://lookout.co/santacruz/partners/marketing/story/2023-02-15/protecting-monterey-bay-one-wrapped-bus-and-ride-at-a-time',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-01/inside-the-shoppers-corner-team-6-cant-miss-grocery-recommendations',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-02/sockshop-donates-30-000-worth-of-shoes-to-santa-cruz-womens-center',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-09/uc-santa-cruz-arts-division-to-break-ground-on-new-state-of-the-art-social-documentation-lab',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-10/through-the-rising-scholars-network-cabrillo-expands-opportunities-for-justice-impacted-students',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-13/santa-cruz-community-builder-of-the-year-isabel-contreras-founder-of-mi-gente-ca',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-14/santa-cruz-health-wellness-leader-of-the-year-campesina-womb-justice',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-15/nexties-winner-spotlight-mariaelena-de-la-garza-lifetime-achievement',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-15/nexts-winner-spotlight-jessica-yarr-foodie',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-16/calling-all-artists-apply-today-for-the-2023-santa-cruz-county-open-studios-art-tour',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-20/santa-cruz-musician-of-the-year-mak-nova',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-23/santa-cruz-best-new-business-of-the-year-collective-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-23/santa-cruz-giveback-person-of-the-year-oscar-corcoles',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-29/kevin-weatherwaxs-unconventional-educational-path-leads-to-ucsc-ph-d-and-piatt-fellowship-win',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-30/resolving-homelessness-the-need-for-both-temporary-shelter-and-affordable-housing-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-31/protecting-the-monterey-bay-one-ride-at-a-time-how-to-get-involved',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-31/santa-cruz-band-of-the-year-superblume',
			'https://lookout.co/santacruz/partners/marketing/story/2023-03-31/the-no-boring-socks-legacy-sockshop-celebrates-35-years-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-06/a-charming-and-beautiful-well-maintained-manufactured-home-in-the-heart-of-the-pleasure-point-area-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-10/santa-cruz-county-celebrates-youth-success-at-the-your-future-is-our-business-2023-luncheon',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-13/tannery-arts-center-to-host-free-public-art-talk-tour-event-featuring-new-sculpture-installations-and-mural',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-14/bringing-self-love-and-self-care-to-santa-cruz-skin-care-with-terra-self',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-17/santa-cruz-symphony-season-finale-features-pulitzer-prize-winning-composer-caroline-shaw-and-cabrillo-chorus',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-17/shoppers-corner-customer-spotlight-emily-matheson',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-17/women-build-day-brings-new-homes-to-santa-cruzs-live-oak-neighborhood',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-21/branciforte-branch-library-set-for-grand-reopening-after-two-years',
			'https://lookout.co/santacruz/partners/marketing/story/2023-04-26/local-artisans-and-community-come-together-for-tannery-arts-center-spring-art-market',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-02/shoppers-corner-customer-spotlight',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-02/top-10-volunteer-opportunities-in-santa-cruz-county-may-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-04/ending-the-stigma-an-appeal-to-support-namis-mental-health-resources-support-and-services',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-04/housing-santa-cruz-county-hosts-community-conversations-and-celebrations-for-affordable-housing-month',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-05/85-years-old-and-leading-the-charge-in-sustainable-grocery-store-practices-in-california',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-05/watsonville-farmers-market-el-mercado-tackles-food-insecurity-promotes-healthy-living',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-09/celebrate-the-volunteers-who-make-santa-cruz-county-great-get-tickets-now-for-the-be-the-difference-awards',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-09/stunning-single-level-home-for-sale-in-beautiful-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-05-22/santa-cruz-introduces-new-santa-cruzer-shuttle-service-for-easy-access-to-beach-and-wharf-events',
			'https://lookout.co/santacruz/partners/marketing/story/2023-06-05/top-10-volunteer-opportunities-in-santa-cruz-county-june-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-06-09/5-steps-to-budgeting-your-dream-vacation-with-santa-cruz-bay-federal-credit-union',
			'https://lookout.co/santacruz/partners/marketing/story/2023-06-22/cabrillo-stages-the-hunchback-of-notre-dame-kicks-off-july-6-under-new-artistic-director-andrea-hart',
			'https://lookout.co/santacruz/partners/marketing/story/2023-06-26/coveted-prospect-heights-home-hits-the-santa-cruz-market-for-the-first-time-in-over-50-years',
			'https://lookout.co/santacruz/partners/marketing/story/2023-06-30/top-10-volunteer-opportunities-in-santa-cruz-county-july-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-06/federal-grant-funds-santa-cruz-county-wic-outreach-to-immigrants-and-farmworkers',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-07/for-the-second-consecutive-year-cabrillo-college-robotics-club-wins-first-place-in-world-competition',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-10/soquel-vineyards-a-glimpse-into-the-history-and-exquisite-tasting-experience-at-their-santa-cruz-mountains-winery',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-13/santa-cruz-metro-to-hold-virtual-public-meeting-for-previewing-possible-network-changes',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-20/three-ucsc-alumni-spearhead-equitable-dental-care-access-to-santa-cruz-county-seniors',
			'https://lookout.co/santacruz/partners/marketing/story/2023-07-31/interns-shine-at-santa-cruz-shakespeares-fringe-production',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-01/cabrillo-festival-of-contemporary-music-announces-61st-season',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-03/shoppers-corner-customer-spotlight-britney-and-scott-williams',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-04/top-10-volunteer-opportunities-in-santa-cruz-county-august-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-08/a-childhood-cancer-survivor-becomes-a-cancer-researcher-at-uc-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-11/resilience-in-action-czu-fire-victims-rebuild-with-the-support-of-financial-guidance-from-santa-cruz-county-bank',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-15/santa-cruz-county-family-resource-collective-distributes-nearly-2m-in-aid-to-storm-impacted-residents',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-17/being-proactive7-steps-to-preparing-your-home-for-winter',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-21/community-bridges-offers-ongoing-emergency-assistance-to-the-town-of-pajaro-after-flooding-disaster',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-22/shoppers-corner-customer-spotlight-akiko-minami',
			'https://lookout.co/santacruz/partners/marketing/story/2023-08-29/shoppers-corner-customer-spotlight-aaron-and-romah-hinde',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-06/10-volunteer-education-opportunities-top-10-get-involved-september-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-06/santa-cruz-symphony-announces-2023-24-season-with-musical-prodigy-and-stellar-repertoire',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-06/your-modern-oasis-awaits-rent-this-immaculate-scotts-valley-townhome',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-08/santa-cruz-metro-offers-free-fares-to-santa-cruz-county-fair',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-12/shoppers-corner-customer-spotlight-tendo-kironde',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-13/uc-santa-cruz-professor-contributes-to-unveiling-the-first-complete-sequence-of-the-human-y-chromosome',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-18/10-must-see-santa-cruz-county-open-studios-art-tour',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-18/decades-of-dedication-lisa-berkowitzs-lifelong-commitment-to-meals-on-wheels',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-18/santa-cruz-metro-unveils-striking-new-wildlife-buses-as-one-ride-at-a-time-campaign-expands',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-19/construction-skills-sustainability-and-local-demand-in-cabrillo-colleges-cem-program',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-22/coastal-charmer-santa-cruz-home-offers-beachside-bliss-and-modern-comforts',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-27/groundbreaking-indian-rapper-to-play-at-uc-santa-cruz-quarry-amphitheater',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-27/riding-the-electric-wave-navigating-the-road-to-e-bike-safety-in-santa-cruz',
			'https://lookout.co/santacruz/partners/marketing/story/2023-09-28/new-townhomes-for-sale-in-soquel-california',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-04/top-ten-ways-to-volunteer-and-help-seniors-in-santa-cruz-county-this-ocotober-2023',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-11/five-uc-santa-cruz-projects-awarded-grant-funding-to-tackle-climate-change-challenges',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-11/santa-cruz-symphony-welcomes-nancy-zhou-as-the-newest-artist-in-residence',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-16/where-to-next-plan-for-the-future-at-cabrillos-college-and-career-family-night',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-16/where-to-next-plan-for-the-future-at-cabrillos-college-and-career-family-night',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-17/celebrating-40-years-of-santa-cruz-county-generosity-wine-roses-returns-on-november-4',
			'https://lookout.co/santacruz/partners/marketing/story/2023-10-17/shoppers-corner-customer-spotlight',
		];
		$skip_url_paths = [
			'https://lookout.co/santacruz/partners/civic-groups',
			'https://lookout.co/santacruz/partners/',
		];

		// Is $url in list of specific URLs to be skipped?
		$in_specific_urls = in_array( $url, $skip_urls );

		// Is $url in list of specific URLs paths to be skipped?
		$in_list_of_url_paths = false;
		foreach ( $skip_url_paths as $skip_url_path ) {
			$in_list_of_url_paths = $in_list_of_url_paths || false !== strpos( $url, $skip_url_path );
		}

		// Should $url be skipped from scraping/importing?
		return $in_specific_urls || $in_list_of_url_paths;
	}
}
