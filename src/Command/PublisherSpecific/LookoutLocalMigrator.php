<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
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

	const DATA_EXPORT_TABLE = 'Record';
	const CUSTOM_ENTRIES_TABLE = 'newspack_entries';
	const LOOKOUT_S3_SCHEMA_AND_HOSTNAME = 'https://lookout-local-brightspot.s3.amazonaws.com';

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
		$this->logger = new Logger();
		$this->scraper = new Newspack_Scraper_Migrator_Util();
		$this->crawler = new Crawler();
		$this->data_parser = new Newspack_Scraper_Migrator_HTML_Parser();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-get-posts-from-data-export-table',
			[ $this, 'cmd_get_posts_from_data_export_table' ],
			[
				'shortdesc' => 'Extracts all posts JSONs from the huge `Record` table into a new custom table called self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-import-posts-programmatically',
			[ $this, 'cmd_import_posts' ],
			[
				'shortdesc' => 'Abandoned. This is not feasible. Will combine with scraping. (old description: Imports posts from JSONs in  self::CUSTOM_ENTRIES_TABLE.)',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev',
			[ $this, 'cmd_dev' ],
			[
				'shortdesc' => 'Temp dev command for various research snippets.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-scrape-posts',
			[ $this, 'cmd_scrape_posts' ],
			[
				'shortdesc' => 'Make sure to run lookoutlocal-get-posts-from-data-export-table first, which gets all posts data in separate table. This scrapes posts from live.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_scrape_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		// Logs.
		$log_wrong_urls = 'll_wrong_urls.log';

		// Timestamp logs.
		$this->logger->log( $log_wrong_urls, sprintf( "Started: %s", date( 'Y-m-d H:i:s' ) ), false );

		// Cache section data to files, because SQLs on Result table are super slow.
		$temp_section_data_cache_path = $this->cwd . '/temp_section_data_cache_path/section_data';
		if ( ! file_exists( $temp_section_data_cache_path ) ) {
			mkdir( $temp_section_data_cache_path, 0777, true );
		}

		// Get post entries.
		$entries_table = self::CUSTOM_ENTRIES_TABLE;
		$results = $wpdb->get_results( "select slug, data from {$entries_table}", ARRAY_A );


// // START get URL.
//
// 		// Get post slugs and URLs.
// 		/**
// 		 * @var array $urls_data {
// 		 *      @type string slug Post slug.
// 		 *      @type string url  Post url.
// 		 * }
// 		 */
// 		$urls_data = [];
// 		$record_table = self::DATA_EXPORT_TABLE;
// 		foreach ( $results as $key_result => $result ) {
// 			WP_CLI::line( sprintf( "%d/%d", $key_result + 1, count( $results ) ) );
//
// // TODO remove dev helper:
// // if ( 'debris-flow-evacuations-this-winter' != $result['slug'] ) { continue ; }
//
//
// 			$slug = $result['slug'];
// 			$data = json_decode( $result['data'], true);
//
// 			// Example post URL:
// 			// https://lookout.co/santacruz/environment/story/2020-11-18/debris-flow-evacuations-this-winter
//
// 			/**
// 			 * Tried getting URL/ permalink from `Record` by "cms.directory.pathTypes", but it's not there in that format:
// 			 *      select data from Record where data like '%00000175-41f4-d1f7-a775-edfd1bd00000:00000175-dd52-dd02-abf7-dd72cf3b0000%' and data like '%environment%';
// 			 * It's probably split by two objects separated by ":", but that's difficult to locate in `Record`.
// 			 *
// 			 * Next trying to just get name of category "environment", and date "2020-11-18" from `Record`, then composing the URL manually.
// 			 * Will debug when doing curls if path is not correct, and extend this.
// 			 *      select data from Record where data like '{"cms.site.owner"%' and data like '%"_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"%' and data like '%"_id":"00000175-8030-d826-abfd-ec7086fa0000"%' order by id desc limit 1;
// 			 */
//
// 			// Get (what I believe to be) category data entry from Record table.
// 			if ( ! isset( $data['sectionable.section']['_ref'] ) || ! isset( $data['sectionable.section']['_type'] ) ) {
// 				$d=1;
// 				continue;
// 			}
// 			$id_like = sprintf( '"_id":"%s"', $data['sectionable.section']['_ref'] );
// 			$type_like = sprintf( '"_type":"%s"', $data['sectionable.section']['_type'] );
// 			$section_data_temp_cache_file_name = $data['sectionable.section']['_type'] . '__' . $data['sectionable.section']['_ref'];
// 			$section_data_temp_cache_file_path = $temp_section_data_cache_path . '/' . $section_data_temp_cache_file_name;
// 			if ( ! file_exists( $section_data_temp_cache_file_path ) ) {
// 				$sql = "select data from {$record_table} where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%' order by id desc limit 1;";
// 				WP_CLI::line( sprintf( "Getting section info" ) );
// 				$section_result = $wpdb->get_var( $sql );
// 				file_put_contents( $section_data_temp_cache_file_path, $section_result );
// 			} else {
// 				$section_result = file_get_contents( $section_data_temp_cache_file_path );
// 			}
// 			$section = json_decode( $section_result, true );
//
// 			// Check if section data is valid.
// 			if ( ! $section || ! isset( $section['cms.directory.paths'] ) || ! $section['cms.directory.paths'] ) {
// 				$d=1;
// 			}
//
// 			// Get last exploded url segment from, e.g. "cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-32a8-d1f7-a775-feedba580000/environment"
// 			if ( ! isset( $section['cms.directory.paths'][0] ) ) {
// 				$d=1;
// 			}
// 			$section_paths_exploded = explode( '/', $section['cms.directory.paths'][0] );
// 			$section_slug = end( $section_paths_exploded );
// 			if ( ! $section_slug ) {
// 				$d=1;
// 			}
//
// 			// Get date slug, e.g. '2020-11-18'.
// 			$date_slug = date( 'Y-m-d', $data['cms.content.publishDate'] / 1000 );
//
// 			// Compose URL.
// 			$url = sprintf(
// 				'https://lookout.co/santacruz/%s/story/%s/%s',
// 				$section_slug,
// 				$date_slug,
// 				$slug
// 			);
//
// // END get URL.
//
// 			$urls_data[] = [
// 				'_id' => $data["_id"],
// 				'_type' => $data["_type"],
// 				'slug' => $slug,
// 				'url'  => $url,
// 			];
// // TODO remove dev helper:
// if ( $key_result >= 10 ) { break; }
//
// 		}

$urls_data = array ( 0 =>  array ( '_id' => '00000175-80c1-dffc-a7fd-ecfd2f060000', '_type' => '0a0520eb-35b6-3762-a20e-86739324b125', 'slug' => 'santa-cruz-food-banks-covid-19-hungry-help', 'url' => 'https://lookout.co/santacruz/guides/story/2020-10-31/santa-cruz-food-banks-covid-19-hungry-help', ), 1 =>  array ( '_id' => '00000175-9f9c-ddb0-a57f-dfddbe550000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'ucsc-archive-10-000-photos-santa-cruz-history', 'url' => 'https://lookout.co/santacruz/ucsc-cabrillo/story/2020-11-06/ucsc-archive-10-000-photos-santa-cruz-history', ), 2 =>  array ( '_id' => '00000175-aefd-dfc0-afff-fefd2f6f0000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'santa-cruz-wildfires-covid-flu-season-health', 'url' => 'https://lookout.co/santacruz/environment/story/2020-11-09/santa-cruz-wildfires-covid-flu-season-health', ), 3 =>  array ( '_id' => '00000175-af10-dfc0-afff-fffc47b60000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'cannabis-climate-change-santa-cruz', 'url' => 'https://lookout.co/santacruz/environment/story/2020-11-09/cannabis-climate-change-santa-cruz', ), 4 =>  array ( '_id' => '00000175-af24-ddb0-a57f-ef65639e0000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'robots-farms-harvest-watsonville-santa-cruz-rural', 'url' => 'https://lookout.co/santacruz/business-technology/story/2020-11-09/robots-farms-harvest-watsonville-santa-cruz-rural', ), 5 =>  array ( '_id' => '00000175-b31d-de97-ab7d-f35f56790000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'biden-win-santa-cruz-lookout-local-recovery-2021', 'url' => 'https://lookout.co/santacruz/the-here-now/story/2020-11-17/biden-win-santa-cruz-lookout-local-recovery-2021', ), 6 =>  array ( '_id' => '00000175-b8f1-ddd1-abf5-fff7e72a0000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'illuminee-small-business-profile', 'url' => 'https://lookout.co/santacruz/business-technology/story/2020-11-11/illuminee-small-business-profile', ), 7 =>  array ( '_id' => '00000175-be94-d297-a1f5-bed656d10000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'cabrillo-college-culinary-arts-hospitality-management-classes-continue-amidst-the-covid-19-pandemic', 'url' => 'https://lookout.co/santacruz/coast-life/story/2020-11-13/cabrillo-college-culinary-arts-hospitality-management-classes-continue-amidst-the-covid-19-pandemic', ), 8 =>  array ( '_id' => '00000175-c2c0-ddb2-ab7f-e6e73f0d0000', '_type' => '4f8e492c-6f2f-390e-bc61-f176d3a37ab9', 'slug' => 'debris-flow-evacuations-this-winter', 'url' => 'https://lookout.co/santacruz/environment/story/2020-11-18/debris-flow-evacuations-this-winter', ), 9 =>  array ( '_id' => '00000175-c33c-d297-a1f5-d77ea9d40000', '_type' => 'a7753743-f4e0-30aa-b2fc-221aed805f42', 'slug' => 'debris-flow-safety-rain-winter-santa-cruz-mountains-mudslides', 'url' => 'https://lookout.co/santacruz/guides/story/2020-12-12/debris-flow-safety-rain-winter-santa-cruz-mountains-mudslides', ), );
$wrong_urls = [
	'https://lookout.co/santacruz/coast-life/story/2020-11-13/cabrillo-college-culinary-arts-hospitality-management-classes-continue-amidst-the-covid-19-pandemic',
	'https://lookout.co/santacruz/the-here-now/story/2020-11-17/biden-win-santa-cruz-lookout-local-recovery-2021',
	'https://lookout.co/santacruz/business-technology/story/2020-11-09/robots-farms-harvest-watsonville-santa-cruz-rural',
	'https://lookout.co/santacruz/environment/story/2020-11-09/cannabis-climate-change-santa-cruz',
	'https://lookout.co/santacruz/guides/story/2020-10-31/santa-cruz-food-banks-covid-19-hungry-help',
];

// Loop through URLs and scrape.
		foreach ( $urls_data as $url_data ) {

// TODO debug
if ( in_array( $url_data['url'], $wrong_urls ) ) { continue; }

			$url = $url_data['url'];

			// TODO load HTML from file.

			$get_result = $this->wp_remote_get_with_retry( $url );
			if ( is_array( $get_result ) ) {
				// Not OK.

				// Log.
				$this->logger->log( $log_wrong_urls, sprintf( "%s CODE:%s MESSAGE:%s", $url, $get_result['response']['code'], $get_result['response']['message'] ) );

				continue;
			}

			$html = $get_result;

			// TODO save HTML to file.

			$this->crawler->clear();
			$this->crawler->add( $html );

			// $img_data = $this->crawler->filterXpath( '//img' )->extract( [ 'src', 'title', 'alt' ] );

			// $image = $images->getIterator()[0];
			// $img_raw_html = $image->ownerDocument->saveHTML( $image );
			// $img_src = $image->getAttribute( 'src' );

			$selectors = [
				'title'          => 'h1.pSt-tTl',
				'content'        => 'div.body',
			];
			$title = $this->parse_title( $selectors[ 'title' ], $url );
			$featured_image = $this->parse_featured_image( $selectors[ 'featured_image' ], $url );
			$author = $this->parse_author( $selectors[ 'author' ], $url );
			$excerpt = $this->parse_excerpt( $selectors[ 'excerpt' ], $url );
			$date = $this->parse_date( $selectors[ 'date' ], $url );
			$content = $this->parse_content( $selectors[ 'content' ], $url );
			$categories = $this->parse_categories( $selectors[ 'categories' ], $url );
			$tags = $this->parse_tags( $selectors[ 'tags' ], $url );

			$post = $this->data_parser->parse( $html, $url, $selectors );

			$title = $this->crawler->filterXpath( 'div[@class="headline"]' );
			$title = $title->getIterator();
			count( $title )
			[0]->textContent;
			$subtitle = $this->crawler->filterXpath( 'div[@class="subheadline"]' );
			//
			// h1 headline
			// div subheadline
			$post_content;
			$post_date;


			// Create post.
			$post_args = [
				'post_title' => $title,
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_type' => 'post',
				'post_name' => $slug,
				'post_date' => $post_date,
			];
			$post_id = wp_insert_post( $post_args );



			// Get more postmeta.
			$postmeta = [
				"newspack_commentable.enableCommenting" => $data["commentable.enableCommenting"],
			];
			if ( $subheadline ) {
				$postmeta['newspack_post_subtitle'] = $subheadline;
			}


			// Get more post data to update all at once.
			$post_modified = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified'	=> $post_modified,
			];


			// Post URL.
			// TODO -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?


			// Post excerpt.
			// TODO -- find excerpt.


			// Featured image.
			$caption = $data['lead'][ 'caption' ];
			$hide_caption = $data['lead'][ 'hideCaption' ];
			$credit = $data['lead'][ 'credit' ];
			$alt = $data['lead'][ 'altText' ];
			// TODO -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );


			// Authors.

			// div author-name

			// Categories.
			$data["sectionable.section"];

			$data["sectionable.secondarySections"];


			// "Presented by"
			// div brand-content-name


			// Tags.
			$data["taggable.tags"];


			// Save postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}


			// Update post data.
			if ( ! empty( $post_update_data ) ) {
				$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );
			}






			$d = 1;
		}

		// Get response.
	}

	/**
	 * @param $url     URL to scrape.
	 * @param $retried Number of times this function was retried.
	 * @param $retries Number of times to retry.
	 * @param $sleep   Number of seconds to sleep between retries.
	 *
	 * @return string|array Body HTML string or Response array from \wp_remote_get() in case of error.
	 */
	private function wp_remote_get_with_retry( $url, $retried = 0, $retries = 3, $sleep = 2 ) {

		$response = wp_remote_get( $url, [
			'timeout' => 60,
			'user-agent' => 'Newspack Scraper Migrator',
		] );

		// Retry if error, or if response code is not 200 and retries are not exhausted.
		if (
			( is_wp_error( $response ) || ( 200 != $response["response"]["code"] ) )
			&& ( $retried < $retries )
		) {
			sleep( $sleep );
			$retried++;
			$response = $this->wp_remote_get_with_retry( $url, $retried, $retries, $sleep );
		}

		// If everything is fine, return body.
		if ( ! is_wp_error( $response ) && ( 200 == $response["response"]["code"] ) ) {
			$body = wp_remote_retrieve_body( $response );

			return $body;
		}

		// If not OK, return response array.
		return $response;
	}

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
		foreach ( $data["authorable.authors"] as $data_author ) {
			$authorable_author_id = $data_author['_ref'];
			$authorable_author_type = $data_author['_type'];
			$id_like = sprintf( '"_id":"%s"', $authorable_author_id );
			$type_like = sprintf( '"_type":"%s"', $authorable_author_type );
			// Find author in DB.
			$author_json = $wpdb->get_var( "select data from Record where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%';" );
// Dev test:
// $author_json = <<<JSON
// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano"],"cms.directory.pathTypes":{"00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano":"PERMALINK"},"cms.content.publishDate":1660858690827,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660927400870,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","features.disabledFeatures":[],"shared.content.rootId":null,"shared.content.sourceId":null,"shared.content.version":null,"canonical.canonicalUrl":null,"promotable.hideFromDynamicResults":false,"catimes.seo.suppressSeoSiteDisplayName":false,"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"cms.seo.keywords":[],"cms.seo.robots":[],"commentable.enableCommenting":false,"feed.disableFeed":false,"feed.renderFullContent":false,"feed.enabledFeedItemTypes":[],"image":{"_ref":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"cover":{"_ref":"00000182-b2de-d6aa-a783-b6dff3bf0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"section":{"_ref":"00000175-7fd0-dffc-a7fd-7ffd9e6a0000","_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"},"name":"Thomas Sawano","firstName":"Thomas","lastName":"Sawano","title":"Newsroom Intern","email":"thomas@lookoutlocal.com","fullBiography":"Thomas Sawano joins the Lookout team after two-and-a-half years at City on a Hill Press, the student-run newspaper at UCSC. While there, he reported on the university, arts and culture events, and the city of Santa Cruz. Thomas is deeply interested in local politics and feels fortunate to have begun his journalistic career in this town.<br/><br/>Thomas graduated in 2022 with degrees in Cognitive Science and Philosophy. Though hailing from Los Angeles, he has vowed to never live there again on account of traffic and a lack of actual weather. Thomas loves traveling, going to music festivals, and watching documentaries about the outdoors. He has recently picked up rock climbing, and hopes the sport won’t damage his typing hands <i>too </i>badly.<br/><br/>","shortBiography":"","affiliation":"Lookout Santa Cruz","isExternal":false,"theme.lookout-local.:core:page:Page.hbs._template":null,"theme.lookout-local.:core:promo:Promo.hbs.breaking":false,"theme.lookout-local.:core:promo:Promo.hbs.imageDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.descriptionDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.categoryDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.timestampDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.moreCoverageLinksDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.promoAlignment":null,"theme.lookout-local.:core:promo:Promo.hbs._template":null,"theme.lookout-local.:core:promo:Promo.amp.hbs._template":null,"cms.directory.pathsMode":"MANUAL","_id":"00000182-b2df-d6aa-a783-b6dfd7b50000","_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"}
// JSON;
			$author = json_decode( $author_json, true );
			// Also exist ['cover']['_ref'] and ['section']['_ref'].
			$full_name = $author['name'];
			$first_name = $author['firstName'];
			$last_name = $author['lastName'];
			$email = $author['email'];
			$bio = $author['fullBiography'];
			$short_bio = $author['shortBiography'];
			// E.g. "Newsroom Intern"
			$title = $author['title'];
			// E.g. "Lookout Santa Cruz"
			$affiliation = $author['affiliation'];
			// External to their publication.
			$is_external = $author['isExternal'];

			// Avatar image.
			$image_ref = $author['image']['_ref'];
			$image_type = $author['image']['_type'];
			$sql = "select data from Record where data like '{\"cms.site.owner\"%' and data like '%\"_id\":\"{$image_ref}\"%' and data like '%\"_type\":\"{$image_type}\"%' ;";
			$image_json = $wpdb->get_var( $sql );
// Dev test:
// $image_json = <<<JSON
// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.content.publishDate":1660858629241,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660858674492,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","shared.content.version":"00000182-b2e4-daa2-a5fe-b2ed30fe0000","taggable.tags":[],"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"type":{"_ref":"a95896f6-e74f-3667-a305-b6a50d72056a","_type":"982a8b2a-7600-3bb0-ae68-740f77cd85d3"},"titleFallbackDisabled":false,"file":{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/thomas-sawano-white.png","contentType":"image/png","metadata":{"cms.edits":{},"originalFilename":"Thomas Sawano white.png","http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["1074663"],"Content-Type":["image/png"]},"resizes":[{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/resizes/500/thomas-sawano-white.png","contentType":"image/png","metadata":{"width":500,"height":500,"http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["349214"],"Content-Type":["image/png"]}}}],"width":1080,"File Type":{"Detected File Type Long Name":"Portable Network Graphics","Detected File Type Name":"PNG","Detected MIME Type":"image/png","Expected File Name Extension":"png"},"PNG-IHDR":{"Filter Method":"Adaptive","Interlace Method":"No Interlace","Compression Type":"Deflate","Image Height":"1080","Color Type":"True Color with Alpha","Image Width":"1080","Bits Per Sample":"8"},"PNG-pHYs":{"Pixels Per Unit X":"3780","Pixels Per Unit Y":"3780","Unit Specifier":"Metres"},"PNG-tEXt":{"Textual Data":"Comment: xr:d:DAE5wFeyjSQ:518,j:33207655899,t:22081821"},"height":1080,"cms.crops":{},"cms.focus":{"x":0.4397042465484525,"y":0.2428842504743833}}},"keywords":[],"keywordsFallbackDisabled":false,"dateUploaded":1660858629241,"caption":"","captionFallbackDisabled":false,"credit":"","creditFallbackDisabled":false,"altText":"Thomas Sawano","bylineFallbackDisabled":false,"instructionsFallbackDisabled":false,"sourceFallbackDisabled":false,"copyrightNoticeFallbackDisabled":false,"headlineFallbackDisabled":false,"categoryFallbackDisabled":false,"supplementalCategory":[],"supplementalCategoryFallbackDisabled":false,"writerFallbackDisabled":false,"countryFallbackDisabled":false,"countryCodeFallbackDisabled":false,"origTransRefFallbackDisabled":false,"metadataStateFallbackDisabled":false,"cityFallbackDisabled":false,"width":1080,"height":1080,"_id":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"}
// JSON;
			$image = json_decode( $image_json, true );
			if ( 's3' != $image['file']['storage'] ) {
				// Debug this.
				$d=1;
			}
			$image_url = self::LOOKOUT_S3_SCHEMA_AND_HOSTNAME . '/' . $image['file']['path'];
			$image_title = $image['file']['metadata']['originalFilename'];
			$image_alt = $image['altText'];
		}
		$authorable_author_id = $data["authorable.authors"]['_ref'];
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
				$line = str_replace( "\\\\", "\\", $line ); // Replace double escapes with just one escape.
				$data = json_decode( $line, true );
				if ( ! $data ) {
					$line = str_replace( "\\\\", "\\", $line ); // Replace double escapes with just one escape.
					$data = json_decode( $line, true );
					if ( $data ) { $jsons[] = $data; }
				} else { $jsons[] = $data; }
			} else { $jsons[] = $data; }
		}
		$d=1;
		$jsons_long = json_encode( $jsons );
		return;

	}

	/**
	 * Callable for `newspack-content-migrator lookoutlocal-get-posts-from-data-export-table`.
	 *
	 * @param array $pos_args   Array of positional arguments.
	 * @param array $assoc_args Array of associative arguments.
	 *
	 * @return void
	 */
	public function cmd_get_posts_from_data_export_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Table names.
		$record_table = self::DATA_EXPORT_TABLE;
		$custom_table = self::CUSTOM_ENTRIES_TABLE;

		// Check if Record table is here.
		$count_record_table = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_NAME = %s;", $record_table ) );
		if ( 1 != $count_record_table ) {
			WP_CLI::error( sprintf( 'Table %s not found.', $record_table ) );
		}

		$continue = PHP_Utils::readline( sprintf( "Continuing will truncate the existing %s table. Continue? [y/n] ", $record_table ) );
		if ( 'y' !== $continue ) {
			WP_CLI::error( 'Aborting.' );
		}

		// Create/truncate custom table.
		$this->create_custom_table( $custom_table, $truncate = true );

		// Read from $record_table and write just posts entries to $custom_table.
		$offset = 0;
		$batchSize = 1000;
		$total_rows = $wpdb->get_var( "SELECT count(*) FROM {$record_table}" );
		$total_batches = ceil( $total_rows / $batchSize );
		while ( true ) {

			WP_CLI::line( sprintf( "%d/%d getting posts from %s into %s ...", $offset, $total_rows, $record_table, $custom_table ) );

			// Query in batches.
			$sql = "SELECT * FROM {$record_table} ORDER BY id, typeId ASC LIMIT $batchSize OFFSET $offset";
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( count( $rows ) > 0 ) {
				foreach ( $rows as $row ) {

					// Get row JSON data. It might be readily decodable, or double backslashes may have to be removed up to two times.
					$data_result = $row[ 'data' ];
					$data = json_decode( $data_result, true );
					if ( ! $data ) {
						$data_result = str_replace( "\\\\", "\\", $data_result ); // Replace double escapes with just one escape.
						$data = json_decode( $data_result, true );
						if ( ! $data ) {
							$data_result = str_replace( "\\\\", "\\", $data_result ); // Replace double escapes with just one escape.
							$data = json_decode( $data_result, true );
						}
					}

					// Check if this is a post.
					$slug = $data['sluggable.slug'] ?? null;
					$title = $data['headline'] ?? null;
					$post_content = $data['body'] ?? null;
					$is_a_post = $slug && $title && $post_content;
					if ( ! $is_a_post ) {
						continue;
					}

					// Insert to custom table
					$wpdb->insert( $custom_table, [ 'slug' => $slug, 'data' => json_encode( $data ) ] );
				}

				$offset += $batchSize;
			} else {
				break;
			}
		}

		// Group by slugs and leave just the most recent entry.

		WP_CLI::line( 'Done' );
	}

	public function cmd_import_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		$data_jsons = $wpdb->get_col( "SELECT data from %s", self::CUSTOM_ENTRIES_TABLE );
		foreach ( $data_jsons as $data_json ) {
			$data = json_encode( $data_json, true );

			// Get post data.
			$slug = $data['sluggable.slug'];
			$title = $data['headline'];
			$subheadline = $data['subHeadline'];
			$post_content = $data['body'];
			$post_date = $this->convert_epoch_timestamp_to_wp_format( $data['cms.content.publishDate'] );

			// Create post.
			$post_args = [
				'post_title' => $title,
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_type' => 'post',
				'post_name' => $slug,
				'post_date' => $post_date,
			];
			$post_id = wp_insert_post( $post_args );


			// Get more postmeta.
			$postmeta = [
				"newspack_commentable.enableCommenting" => $data["commentable.enableCommenting"],
			];
			if ( $subheadline ) {
				$postmeta['newspack_post_subtitle'] = $subheadline;
			}


			// Get more post data to update all at once.
			$post_modified = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified'	=> $post_modified,
			];


			// Post URL.
			// TODO -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?

			// Post excerpt.
			// TODO -- find excerpt.


			// Featured image.
			$data['lead'];
			// These two fields:
			//     "_id": "00000184-6982-da20-afed-7da6f7680000",
			//     "_type": "52f00ba5-1f41-3845-91f1-1ad72e863ccb"
			$data['lead'][ 'leadImage' ];
			// Can be single entry:
			//      "_ref": "0000017b-75b6-dd26-af7b-7df6582f0000",
			//      "_type": "4da1a812-2b2b-36a7-a321-fea9c9594cb9"
			$caption = $data['lead'][ 'caption' ];
			$hide_caption = $data['lead'][ 'hideCaption' ];
			$credit = $data['lead'][ 'credit' ];
			$alt = $data['lead'][ 'altText' ];
			// TODO -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );


			// Authors.
			// TODO - search these two fields. Find bios, avatars, etc by checking staff pages at https://lookout.co/santacruz/about .
			$data["authorable.authors"];
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "0000017e-5a2e-d675-ad7e-5e2fd5a00000",
			//          "_type": "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
			//      }
			// ]
			$data["authorable.oneOffAuthors"];
			// Can be multiple entries:
			// [
			// 	{
			// 		"name":"Corinne Purtill",
			// 		"_id":"d6ce0bcd-d952-3539-87b9-71bdb93e98c7",
			// 		"_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// 	},
			// 	{
			// 		"name":"Sumeet Kulkarni",
			// 		"_id":"434ebcb2-e65c-32a6-8159-fb606c93ee0b",
			// 		"_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// 	}
			// ]

			$data["authorable.primaryAuthorBioOverride"];
			// ? TODO - search where not empty and see how it's used.
			$data["hasSource.source"];
			// Can be single entry:
			//      "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			//      "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


			// Categories.
			// TODO -- is this a taxonomy?
			$data["sectionable.section"];
			// Can be single entry:
			//      "_ref": "00000180-62d1-d0a2-adbe-76d9f9e7002e",
			//      "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			$data["sectionable.secondarySections"];
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "00000175-7fd0-dffc-a7fd-7ffd9e6a0000",
			//          "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			//      }
			// ]


			// Tags.
			$data["taggable.tags"];
			// TODO -- find tags
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "00000175-ecb8-dadf-adf7-fdfe01520000",
			//          "_type": "90602a54-e7fb-3b69-8e25-236e50f8f7f5"
			//      }
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
		$readable = date('Y-m-d H:i:s', $timestamp_seconds);

		return $readable;
	}

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
