<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Utils\PHP as PHP_Utils;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Newspack_Scraper_Migrator_Util;
use \Newspack_Scraper_Migrator_HTML_Parser;
use \WP_CLI;
use Symfony\Component\DomCrawler\Crawler as Crawler;

/**
 * Custom migration scripts for Lookout Local.
 */
class LookoutLocalMigrator implements InterfaceCommand {

	const MEDIA_CREDIT_META              = '_media_credit';
	const DATA_EXPORT_TABLE              = 'Record';
	const CUSTOM_ENTRIES_TABLE           = 'newspack_entries';
	const LOOKOUT_S3_SCHEMA_AND_HOSTNAME = 'https://lookout-local-brightspot.s3.amazonaws.com';

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
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners/civic-groups">Civic Groups</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/partners">Partners</a>
	 * <a class="navigation-item-link" href="https://lookout.co/santacruz/lookout-educator-page">For Educators</a>
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
	 * Current working directory.
	 *
	 * @var false|string Current working directory.
	 */
	private $cwd;

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
	 * Constructor.
	 */
	private function __construct() {
		// Set it in advance because works differently in different environments.
		$this->cwd = __DIR__;
		if ( ! file_exists( $this->cwd ) ) {
			$this->cwd = getcwd();
		}

		// Newspack_Scraper_Migrator is not autoloaded.
		require realpath( $this->cwd . '/../../../vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-util.php' );
		require realpath( $this->cwd . '/../../../vendor/automattic/newspack-cms-importers/newspack-scraper-migrator/includes/class-newspack-scraper-migrator-html-parser.php' );

		$this->attachments = new Attachments();
		$this->logger      = new Logger();
		$this->scraper     = new Newspack_Scraper_Migrator_Util();
		$this->crawler     = new Crawler();
		$this->data_parser = new Newspack_Scraper_Migrator_HTML_Parser();
		$this->cap         = new CoAuthorPlus();
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
			'newspack-content-migrator lookoutlocal-create-custom-table',
			[ $this, 'cmd_create_custom_table' ],
			[
				'shortdesc' => 'Extracts all posts JSONs from the huge `Record` table into a new custom table called self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-scrape-posts',
			[ $this, 'cmd_scrape_posts' ],
			[
				'shortdesc' => 'Main command. Scrape posts from live and imports them. Make sure to run lookoutlocal-create-custom-table first.',
				'synopsis'  => [],
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
	}

	public function cmd_scrape_posts( $pos_args, $assoc_args ) {
		global $wpdb;


		/**
		 * Prepare logs and caching.
		 */

		// Log files.
		$log_path             = $this->cwd . '/logs_and_cache';
		$log_wrong_urls       = 'll_debug__wrong_urls.log';
		$log_all_author_names = 'll_debug__all_author_names.log';
		$log_all_tags         = 'll_debug__all_tags.log';

		// Hit timestamp on all logs.
		$ts = sprintf( 'Started: %s', date( 'Y-m-d H:i:s' ) );
		$this->logger->log( $log_wrong_urls, $ts, false );
		$this->logger->log( $log_all_author_names, $ts, false );
		$this->logger->log( $log_all_tags, $ts, false );

		// Create folders for caching stuff.
		// Cache section (category) data to files (because SQLs on `Result` table are super slow).
		$section_data_cache_path = $log_path . '/cache/section_data';
		if ( ! file_exists( $section_data_cache_path ) ) {
			mkdir( $section_data_cache_path, 0777, true );
		}
		// Cache scraped HTMLs (in case we need to repeat scraping/identifying data from HTMLs).
		$scraped_htmls_cache_path = $log_path . '/cache/scraped_htmls';
		if ( ! file_exists( $scraped_htmls_cache_path ) ) {
			mkdir( $scraped_htmls_cache_path, 0777, true );
		}


		/**
		 * We will first loop through all the posts to get their URLs.
		 * URLs are hard to find, since we must crawl their DB export and search through relational data, and all queries are super slow since it's one 6 GB table.
		 */

		// Get rows from our custom posts table (table was created by command lookoutlocal-create-custom-table).
		$entries_table       = self::CUSTOM_ENTRIES_TABLE;
		$newspack_table_rows = $wpdb->get_results( "select slug, data from {$entries_table}", ARRAY_A );

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

// TODO remove dev helper:
// if ( 'debris-flow-evacuations-this-winter' != $slug ) { continue ; }

			WP_CLI::line( sprintf( '%d/%d Getting URL for slug %s ...', $key_row + 1, count( $newspack_table_rows ), $slug ) );

			// Get post URL.
			$url          = $this->get_post_url( $newspack_table_row, $section_data_cache_path );
			$posts_urls[] = [
				'_id'   => $row_data['_id'],
				'_type' => $row_data['_type'],
				'slug'  => $slug,
				'url'   => $url,
			];

// TODO dev test import one post
if ( $key_row >= 2 ) { break; }

		}


		/**
		 * Now that we have the URLs, we will scrape them and import the posts.
		 */
		$post_authors           = [];
		$debug_all_author_names = [];
		$debug_wrong_posts_urls = [];
		$debug_all_tags         = [];
		foreach ( $posts_urls as $key_url_data => $url_data ) {

			$url  = $url_data['url'];
			$slug = $url_data['slug'];

// TODO remove dev helper:
// if ( 'ucsc-archive-10-000-photos-santa-cruz-history' != $slug ) { continue ; }

			WP_CLI::line( sprintf( '%d/%d Scraping and importing URL %s ...', $key_url_data + 1, count( $posts_urls ), $url ) );

			// If a "publish"-ed post with same URL exists, skip it.
			$post_id = $wpdb->get_var( $wpdb->prepare(
				"select wpm.post_id
					from {$wpdb->postmeta} wpm
					join wp_posts wp on wp.ID = wpm.post_id 
					where wpm.meta_key = %s
					and wpm.meta_value = %s
					and wp.post_status = 'publish' ; ",
				'newspackmigration_url',
				$url
			) );
			if ( $post_id ) {
				WP_CLI::line( sprintf( 'Already imported ID %d URL %s, skipping.', $post_id, $url ) );
				continue;
			}

			// HTML cache filename and path.
			$html_cached_filename  = $slug . '.html';
			$html_cached_file_path = $scraped_htmls_cache_path . '/' . $html_cached_filename;

			// Get HTML from cache or fetch from HTTP.
			$html = file_exists( $html_cached_file_path ) ? file_get_contents( $html_cached_file_path ) : null;
			if ( is_null( $html ) ) {
				$get_result = $this->wp_remote_get_with_retry( $url );
				if ( is_array( $get_result ) ) {
					// Not OK.
					$debug_wrong_posts_urls[] = $url;
					$this->logger->log( $log_wrong_urls, sprintf( 'URL:%s CODE:%s MESSAGE:%s', $url, $get_result['response']['code'], $get_result['response']['message'] ), $this->logger::WARNING );
					continue;
				}

				$html = $get_result;

				// Cache HTML to file.
				file_put_contents( $html_cached_file_path, $html );
			}

			// Crawl and extract all useful data from HTML
			$crawled_data = $this->crawl_post_data_from_html( $html, $url );

			// Create post.
			$post_args = [
				'post_title'   => $crawled_data['post_title'],
				'post_content' => $crawled_data['post_content'],
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_name'    => $slug,
				'post_date'    => $crawled_data['post_date'],
			];
			$post_id   = wp_insert_post( $post_args );
			WP_CLI::success( sprintf( "Created post ID %d", $post_id ) );

			// Collect postmeta in this array.
			$postmeta = [
				'newspackmigration_url'                => $url,
				'newspackmigration_slug'               => $slug,
				// E.g. "lo-sc".
				'newspackmigration_script_source'      => $crawled_data['script_data']['source'] ?? '',
				// E.g. "uc-santa-cruz". This is a backup value to help debug categories, if needed.
				'newspackmigration_script_sectionName' => $crawled_data['script_data']['sectionName'],
				// E.g. "Promoted Content".
				'newspackmigration_script_tags'        => $crawled_data['script_data']['tags'] ?? null,
				'newspackmigration_presentedBy'        => $crawled_data['presented_by'] ?? '',
			];

			// Import featured image.
			if ( isset( $crawled_data['featured_image_src'] ) ) {
				WP_CLI::line( "Downloading featured image ..." );
				$attachment_id   = $this->attachments->import_external_file(
					$crawled_data['featured_image_src'],
					$title       = null,
					$crawled_data['featured_image_caption'],
					$description = null,
					$crawled_data['featured_image_alt'],
					$post_id,
					$args        = []
				);
				set_post_thumbnail( $post_id, $attachment_id );
				// Credit goes as Newspack credit meta.
				if ( $crawled_data['featured_image_credit'] ) {
					$postmeta[ self::MEDIA_CREDIT_META ] = $crawled_data['featured_image_credit'];
				}
			}

			// Authors.
			$ga_ids = [];
			// Get/create GAs.
			foreach ( $crawled_data['post_authors'] as $author_name ) {
				$ga = $this->cap->get_guest_author_by_display_name( $author_name );
				if ( $ga ) {
					$ga_id = $ga->ID;
				} else {
					$ga_id = $this->cap->create_guest_author( [ 'display_name' => $author_name ] );
				}
				$ga_ids[] = $ga_id;
			}
			if ( empty( $ga_ids ) ) {
				throw new \UnexpectedValueException( sprintf( 'Could not get any authors for post %s', $url ) );
			}
			// Assign GAs to post.
			$this->cap->assign_guest_authors_to_post( $ga_ids, $post_id, false );
			// Also collect all author names for easier debugging/QA-ing.
			$debug_all_author_names = array_merge( $debug_all_author_names, $crawled_data['post_authors'] );

			// Categories.
			$category_parent_id = 0;
			if ( $crawled_data['category_parent_name'] ) {
				// Get or create parent category.
				$category_parent_id = wp_create_category( $crawled_data['category_parent_name'], 0 );
				if ( is_wp_error( $category_parent_id ) ) {
					throw new \UnexpectedValueException( sprintf( 'Could not get or create parent category %s for post %s error message: %s', $crawled_data['category_parent_name'], $url, $category_parent_id->get_error_message() ) );
				}
			}
			// Get or create primary category.
			$category_id = wp_create_category( $crawled_data['category_name'], $category_parent_id );
			if ( is_wp_error( $category_id ) ) {
				throw new \UnexpectedValueException( sprintf( 'Could not get or create parent category %s for post %s error message: %s', $crawled_data['category_name'], $url, $category_id->get_error_message() ) );
			}
			// Set category.
			wp_set_post_categories( $post_id, [ $category_id ] );

			// Assign tags.
			$tags = $crawled_data['script_data']['tags'];
			// wp_set_post_tags() also takes a CSV of tags, so this might work out of the box. But we're saving
			wp_set_post_tags( $post_id, $tags );
			// Collect all tags for QA.
			$debug_all_tags = array_merge( $debug_all_tags, [ $tags ] );

			// Save the postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				if ( ! empty( $meta_value ) ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

			$d = 1;
		}

		// Debug and QA info.
		if ( ! empty( $debug_wrong_posts_urls ) ) {
			WP_CLI::warning( "❗️ Check $log_wrong_urls for invalid URLs." );
		}
		if ( ! empty( $debug_all_author_names ) ) {
			$this->logger->log( $log_all_author_names, implode( "\n", $debug_all_author_names ), false );
			WP_CLI::warning( "⚠️️ QA the following: $log_all_author_names " );
		}
		if ( ! empty( $debug_all_tags ) ) {
			$this->logger->log( $log_all_tags, implode( "\n", $debug_all_tags ), false );
			WP_CLI::warning( "⚠️️ QA the following: $log_all_tags ." );
		}
	}

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
		$url = sprintf(
			'https://lookout.co/santacruz/%s/story/%s/%s',
			$section_slug,
			$date_slug,
			$slug
		);

		return $url;
	}

	/**
	 * Crawls all useful post data from HTML.
	 *
	 * @param string $html                    HTML.
	 * @param array  &$debug_all_author_names Stores all author names for easier QA/debugging.
	 * @param array  &$debug_all_tags         Stores all tags for easier QA/debugging.
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
		$script_json = ltrim( $script_json, 'var dataLayer = ' );
		$script_json = rtrim( $script_json, ';' );
		$script_data = json_decode( $script_json, true );
		$script_data = $script_data[0] ?? null;
		if ( is_null( $script_data ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get <script> element data for post %s', $url ) );
		}

		$data['script_data'] = $script_data;

		// Title, subtitle, content.
		$title = $this->filter_selector( 'h1.headline', $this->crawler );
		if ( empty( $title ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get title for post %s', $url ) );
		}
		$data['post_title'] = $title;

		$subtitle           = $this->filter_selector( 'div.subheadline', $this->crawler ) ?? null;
		$data['post_title'] = $subtitle ?? null;

		$post_content = $this->filter_selector( 'div#pico', $this->crawler, false, false );
		if ( empty( $post_content ) ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get post content for post %s', $url ) );
		}
		$data['post_content'] = $post_content;

		// Date. <script> element has both date and time of publishing.
		$matched = preg_match( '/(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})/', $script_data['publishDate'], $matches_date );
		if ( false === $matched ) {
			throw new \UnexpectedValueException( sprintf( 'Could not get date for post %s', $url ) );
		}
		$post_date         = sprintf( '%s-%s-%s %s:%s:00', $matches_date[3], $matches_date[1], $matches_date[2], $matches_date[4], $matches_date[5] );
		$data['post_date'] = $post_date;

		// Authors.
		$authors_text         = $this->filter_selector( 'div.author-name', $this->crawler );
		$post_authors         = $this->format_authors( $authors_text );
		$data['post_authors'] = $post_authors;

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

		// Category.
		// Section name is located both in <meta> element:
		// <meta property="article:section" content="UC Santa Cruz">
		// and in <script> element data:
		// $script_data['sectionName]
		// but in <script> it's in a slug form, e.g. "uc-santa-cruz", so we'll use <meta> for convenience.
		$section_meta          = $this->filter_selector_element( 'meta[property="article:section"]', $this->crawler );
		$category_name         = $section_meta->getAttribute( 'content' );
		$data['category_name'] = $category_name;

		// Parent category.
		// E.g. "higher-ed"
		$section_parent_slug          = $script_data['sectionParentPath'] ?? null;
		$category_parent_name         = self::SECTIONS[ $section_parent_slug ] ?? null;
		$data['category_parent_name'] = $category_parent_name;

		// Tags.
		$tags         = $script_data['tags'] ?? null;
		$data['tags'] = $tags;

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
	public function format_authors( $authors_text ) {
		// Remove 'written by: ' and explode authors by comma, e.g. "Written by: Scott Rappaport, UC Santa Cruz".
		$authors_text = str_replace( 'Written by: ', '', $authors_text );
		$authors_text = str_replace( ', ', ',', $authors_text );

		$authors = explode( ',', $authors_text );

		return $authors;
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
	public function filter_selector_element( $selector, $dom_crawler ) {
		$found_element = $this->data_parser->get_element_by_selector( $selector, $dom_crawler, $single = true );

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
		$json = $wpdb->get_var( "select data from newspack_entries where slug = 'university-of-california-academic-workers-uaw-strike-update';" );
		$data = json_decode( $json, true );
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
			// TODO -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?

			// Post excerpt.
			// TODO -- find excerpt.


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
			// TODO -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );


			// Authors.
			// TODO - search these two fields. Find bios, avatars, etc by checking staff pages at https://lookout.co/santacruz/about .
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
			// ? TODO - search where not empty and see how it's used.
			$data['hasSource.source'];
			// Can be single entry:
			// "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			// "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


			// Categories.
			// TODO -- is this a taxonomy?
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
			// TODO -- find tags
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
