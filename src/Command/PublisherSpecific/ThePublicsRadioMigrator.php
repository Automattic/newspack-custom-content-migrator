<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Newspack_WXR_Exporter;
use WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for The Publics Radio.
 */
class ThePublicsRadioMigrator implements InterfaceCommand {

	private $site_title = "The Public's Radio";
	private $site_url	= 'https://thepublicsradio.org/';

	private $report = [
		'lines' => 0,
		'deleted' => 0,
		'skipped_ap' => 0,
		'skipped_maybe_ap' => 0,
		'content_types' => [],
		'skipped_article_empty_routes' => 0,
		'skipped_article_empty_content' => 0,
		'skipped_article_duplicate_routes' => 0,
		'skipped_article_no_title' => 0,
		'skipped_redirect_empty_routes' => 0,
		'skipped_redirect_empty_body' => 0,
		'skipped_topic_empty_routes' => 0,
		'skipped_topic_https_routes' => 0,
		'article_categories' => array(),
		'article_tags' => array(),
		'article_routes' => array(),
		'topic_routes' => array(),
		'media' => [],

	];

	/**
	 * Keys and types
	 *
	 */

	 private $known_item_keys = [
		'author',
		'authors',
		'articleType',
		'categories',
		'contentBody',
		'createdAt',
		'delta',
		'deletedAt',
		'published_at',
		'routes',
		'showtimes',
		'summary',
		'tags',
		'title',
		'updatedAt',
		'uuid',
	];

	private $known_item_keys_media = [
		'alternativeSizes',
		'article_uuid',
		'caption',
		'createdAt',
		'credit',
		'deletedAt',
		'durationInSeconds',
		'embedCode',
		'isPreferredThumbnail',
		'mediaType',
		'position',
		'relativePath',
		'slides',
		'updatedAt',
		'uuid'
	];

	private $known_article_types = [
		'Alert',
		'Article',
		'Episode',
		'Event',
		'News',
		'Page',
		'Promotion',
		'Redirect',
		'Show',
		'Staff',
		'Topic',
	];


	/**
	 * Parser
	 * 
	 */

	// -- wxr
	private $wxr_max_posts = 200;
	private $wxr_import_set;
		
	// -- folder and file names
	const OUTPUT_FOLDER = 'output';
	const JSON_SINGLE_FILE = self::OUTPUT_FOLDER . '/items.json';
	const JSON_SINGLE_FILE_MEDIA = self::OUTPUT_FOLDER . '/media.json';
	const JSON_BACKUP_FOLDER = 'json';

	// -- internal tracking
	private $out_files = [];
	private $json_file;
	private $json_line_number;
	private $json_line;

	// -- content refreshes and launch
	private $previous_unique_uuids = null;
	private $previous_unique_uuids_processed_again = null;
	private $previous_not_unique_urls = null;
	private $previous_max_time = null;

	/**
	 * @var CoAuthorPlus
	 */
	private $coauthorsplus_logic = null;

	/**
	 * Logger
	 * 
	 */
	private $logger;

	/**
	 * @var RedirectionLogic
	 */
	private $redirection_logic;
	
	/**
	 * Instance of \Logic\SimpleLocalAvatars.
	 *
	 * @var null|SimpleLocalAvatars
	 */
	private $sla_logic;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
		$this->redirection_logic = new RedirectionLogic();
		$this->sla_logic = new SimpleLocalAvatars();
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
			'newspack-content-migrator thepublicsradio-fix-categories',
			[ $this, 'cmd_fix_categories' ],
			[
				'shortdesc' => 'Fix categories to match Topic from old website.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-fix-html',
			[ $this, 'cmd_fix_html' ],
			[
				'shortdesc' => 'Broad function to fix HTML in post conent.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-fix-import-set',
			[ $this, 'cmd_fix_import_set' ],
			[
				'shortdesc' => 'Add missing meta value for original import set.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-fix-media-in-content',
			[ $this, 'cmd_fix_media_in_content' ],
			[
				'shortdesc' => 'Fixes media (images, pdf links, iframes, etc) in post conent.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'import-set',
						'description' => 'Idenfitier for import set. (String: [A-Za-z0-9-_]) (Ex: initial, launch, refresh2)',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'real-run',
						'description' => 'Run script with updates.  --real-run=yes',
						'optional'    => true,
						'default'	  => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-fix-pages',
			[ $this, 'cmd_fix_pages' ],
			[
				'shortdesc' => 'Move category (content type) /page/ to real wp Pages. (Requires redirection plugin.)',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-fix-staff',
			[ $this, 'cmd_fix_staff' ],
			[
				'shortdesc' => 'Move staff posts to wp users or coauthors. (Requires CoAuthorsPlus, Simple Local Avatars, and Redirection plugins).',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-import-attached-images',
			[ $this, 'cmd_import_attached_images' ],
			[
				'shortdesc' => 'Imports (downloads) Attached Images from Old Website (if not exists).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-file',
						'description' => 'CSV file (no header row) with columns: post id, old site url, caption, photo credit. Usage: --csv-file="2023/09/media-image-attach.csv"',
						'optional'    => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-import-attached-images-rewind',
			[ $this, 'cmd_import_attached_images_rewind' ],
			[
				'shortdesc' => 'Rewinds mistaken Attached images.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'real-run',
						'description' => 'Will run the DB updates. --real-run=yes',
						'optional'    => true,
						'default'	  => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-import-featured-images',
			[ $this, 'cmd_import_featured_images' ],
			[
				'shortdesc' => 'Imports (downloads) Featured Images from Old Website',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-file',
						'description' => 'CSV file (no header row) with columns: post id, old site url, caption, photo credit. Usage: --csv-file="2023/08/media-image-cover.csv"',
						'optional'    => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-import-featured-images-rewind',
			[ $this, 'cmd_import_featured_images_rewind' ],
			[
				'shortdesc' => 'Cleans up incorrectly set featured images',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-file',
						'description' => 'CSV file (no header row) with columns: post id, old site url, caption, photo credit. Usage: --csv-file="2023/08/media-image-cover.csv"',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dry-run',
						'description' => 'Run report only.  Make no updates.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-import-featured-images-rewind-two',
			[ $this, 'cmd_import_featured_images_rewind_two' ],
			[
				'shortdesc' => 'Cleans up incorrectly set featured images from post_parent',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'real-run',
						'description' => 'Will run the DB updates. --real-run=yes',
						'optional'    => true,
						'default'	  => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-parse-backups',
			[ $this, 'cmd_parse_backups' ],
			[
				'shortdesc' => 'Parse JSON backup files into a single items file.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-parse',
			[ $this, 'cmd_parse' ],
			[
				'shortdesc' => 'Parse single (reduced) JSON file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-wxr',
						'description' => 'Posts per wxr file. (Integer)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 200,
					],
					[
						'type'        => 'assoc',
						'name'        => 'import-set',
						'description' => 'Idenfitier for import set. (String: [A-Za-z0-9-_]) (Ex: initial, launch, refresh2)',
						'optional'    => false,
					]
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-parse-media',
			[ $this, 'cmd_parse_media' ],
			[
				'shortdesc' => 'Parse Media backup JSON files.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-reset-initial-for-client',
			[ $this, 'cmd_reset_initial_for_client' ],
			[
				'shortdesc' => 'Reset yoast and categories for initial for client.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-set-authors',
			[ $this, 'cmd_set_authors' ],
			[
				'shortdesc' => 'Sets CoAuthor(s) or real wp-admin user per post. CAP Plugin must be installed.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-set-primary-categories',
			[ $this, 'cmd_set_primary_categories' ],
			[
				'shortdesc' => 'Sets Yoast primary category from old content type.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thepublicsradio-set-redirects',
			[ $this, 'cmd_set_redirects' ],
			[
				'shortdesc' => 'Sets redirects from old site, additional article routes, and duplicate slugs.  Redirection plugin required.',
			]
		);

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-categories'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_categories( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing fix categories..." );

		// NO, don't move into a Topic category...Just use wp-admin > permalinks > category base: topic

		// add top level Topic category if not exists
		// if ( ! term_exists('topic', 'category')) {
		// 	wp_insert_term('Topic', 'category');
		// }
		// $topic = get_term_by('slug', 'topic', 'category');
		// $topic_id = $topic->term_id;

		// get list of "content type" categories
		$content_type_ids = get_categories( [
			'slug'   => [
				"article",
				"episode",
				"news",
				"page",
				"show",
				"staff",
			],
			'fields' => 'ids',
		]);

		// select all categories that are not content types // and don't have a parent
		$categories_to_fix = get_categories( [
			'exclude' => $content_type_ids,
			// 'parent' => 0,
		]);
		// 'exclude' => array_merge( $content_type_ids, [ $topic_id ] );

		// capitalize // and set parent to Topic and capitalize
		array_walk( $categories_to_fix, function( $cat ) { // use( $topic_id ) {
			
			// capitalize categories from old client site
			$new_name = ucwords( str_replace( '-', ' ', $cat->name ) );

			if( $new_name === $cat->name ) return;

			// show notice
			// WP_CLI::line( 'Fixing name: ' . $cat->name . ' ( slug: ' . $cat->slug . ', id: ' . $cat->cat_ID . ' ) to parent: Topic, name: ' . $new_name );
			WP_CLI::line( 'Fixing name: ' . $cat->name . ' ( slug: ' . $cat->slug . ', id: ' . $cat->cat_ID . ' ) to name: ' . $new_name );
			
			wp_update_term( $cat->cat_ID, 'category', array(
				// 'parent' => $topic_id,
				'name' => $new_name,
			));

		});

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-html'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_html( $pos_args, $assoc_args ) {
		
		global $wpdb;

		WP_CLI::line( "Doing thepublicsradio-fix-html..." );

		$results = $wpdb->get_results( "
			SELECT p.ID, p.post_content, pm.meta_value
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm 
				on pm.post_id = p.ID
				and pm.meta_key = 'newspack_tpr_url'
			WHERE p.post_content like '%<file-attachment%'
			ORDER BY ID
		");

		WP_CLI::line( 'Posts with <file-attachment>: ' . count( $results ) );

		$total_match_count = 0;

		foreach( $results as $row ) {

			preg_match_all( '/<file-attachment.*?<\/file-attachment>/', $row->post_content, $matches );
			
			if( empty( $matches ) || 0 == count( $matches ) ) {
				WP_CLI::error( 'No file-attachment matches for post: ' . $row->ID );
			}

			$total_match_count += count( $matches[0] );

			foreach( $matches[0] as $match ) {
				
				preg_match_all( '/ ([a-z]+)="(.*?)"/', $match, $atts, PREG_SET_ORDER );
				
				if( empty( $atts ) || 5 != count( $atts ) ) {
					WP_CLI::error( 'Atts count is not 5 for post ' . $row->ID . ' match ' . $match . ' atts ' . print_r( $atts , true ) );
				}
	
				$match_trimmed = trim( $match, '<>' ); // trim leading and ending brackets so this code doesn't keep replacing and replacing each run...

				$credit = '';
				if( ! empty( $atts[3][2] ) ) {
					$credit = '<span style="font-size: 12px;color: #9da0a2;box-sizing: border-box;line-height: 1.125;">By ' . $atts[3][2] . '</span>';
				}

				$caption = $atts[2][2] ?? null;
				if( is_empty( $caption ) ) $caption = 'Download File is Missing a Caption';

				$new_html = '<!--' . $match_trimmed . '--><!--newspack-tpr-converted-file-attachment--><div style="display: block;border: 1px solid #e8eded;margin: 20px 0;padding: 20px;position: relative;min-height: 35px;line-height: 1.125;"><span style="font-weight: 700;font-size: 19px;display: block;width: calc(100% - 120px);box-sizing: border-box;line-height: 1.125;">' . $atts[2][2] . '</span>' . $credit . '<a style="color: #e86a54;text-decoration: none;position: absolute;height: 28px;right: 22px;top: 50%;transform: translateY(-56%);border-radius: 3em;background: #f6f9f9;display: block;padding: 6px 16px;font-size: 14px;transition: all .2s ease-out;box-sizing: border-box;line-height: 1.125;" download="true" target="_blank" href="' . $atts[0][2]. '"> Download</a></div><!--/newspack-tpr-converted-file-attachment-->';

				// replace post content
				/* 	todo: uncomment this after testing that blank caption replacement works ok
				$wpdb->query( $wpdb->prepare( "
					UPDATE {$wpdb->posts} 
					SET post_content = REPLACE(post_content, %s, %s)
					WHERE ID = %d
				", $match, $new_html, $row->ID ) );
				*/

				WP_CLI::line( 'Replaced html in post ' . $row->ID );

			} // each match
			
		} // each db row

		WP_CLI::line( 'Total <file-attachment> match count: ' . $total_match_count );

		WP_CLI::success( 'Done' );
	
	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-import-set'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_import_set( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing fix original import set..." );

		$this->wxr_import_set = 'initial';

		do {
			
			$count = $this->cmd_fix_import_set_query();
			WP_CLI::line( "Updated " . $count . " posts ..." );

		} while( $count > 0 );

		WP_CLI::success( "Done." );

	}

	private function cmd_fix_import_set_query() {

		// select posts without an import set
		$query = new WP_Query ( [
			'posts_per_page' => 100,
			'post_type' => 'any',
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_tpr_uuid',
					'compare' => 'EXISTS',
				],
				[
					'key'     => 'newspack_tpr_import_set',
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$count = $query->post_count;

		foreach ($query->posts as $post_id ) {

			add_post_meta( $post_id, 'newspack_tpr_import_set', $this->wxr_import_set );

		}

		return $count;

	}








	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-media-in-content'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_media_in_content( $pos_args, $assoc_args ) {
		
		if( ! isset( $assoc_args[ 'import-set' ] ) || ! preg_match('/^[A-Za-z0-9-_]+$/', $assoc_args[ 'import-set' ] ) ) {
			WP_CLI::error( "Import set argument (required) must be a string: [A-Za-z0-9-_] Ex: initial, launch, refresh2" );
		}

		$real_run = false;
		if( isset( $assoc_args[ 'real-run' ] ) && preg_match( '/^yes$/', $assoc_args[ 'real-run' ] ) ) {
			$real_run = true;	
		}

		$this->wxr_import_set = $assoc_args[ 'import-set' ];

		global $wpdb;

		WP_CLI::line( "Doing thepublicsradio-fix-media-in-content..." );

		$report = [
			'post_count' => 0,
			'a_elements_to_fix' => 0,
			'a_elements_fixed' => 0,
			'file_elements_to_fix' => 0,
			'file_elements_fixed' => 0,
			'img_elements_to_fix' => 0,
			'img_elements_fixed' => 0,
			'slideshow_elements_to_fix' => 0,
			'slideshow_elements_fixed' => 0,
			'do_by_hand' => [],
		];
		
		// select all imported content by launch set with media in the content (POSTS AND PAGES)
		$results = $wpdb->get_results( $wpdb->prepare("
			SELECT p.ID, p.post_content
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm 
				on pm.post_id = p.ID
				and pm.meta_key = 'newspack_tpr_import_set'
				and pm.meta_value = %s
			WHERE p.post_content like '%<%'
		", $this->wxr_import_set ) );
		
		// SELECT umeta_id, meta_value FROM wp_usermeta where meta_key = 'description';
		// $results = $wpdb->get_results( "
		// 	SELECT umeta_id as ID, meta_value as post_content
		// 	FROM {$wpdb->usermeta}
		// 	WHERE meta_key = 'description' and meta_value like '%<%'
		// ");
		// todo: if cleaning up usermeta, but sure to update the replacement query in function: set_new_url_from_old_url

		// select meta_id, meta_value from wp_postmeta where meta_key like '%cap-description%';
		// $results = $wpdb->get_results( "
		// 	SELECT meta_id as ID, meta_value as post_content
		// 	FROM {$wpdb->postmeta}
		// 	WHERE meta_key = 'cap-description' and meta_value like '%<%'
		// ");
		// todo: if cleaning up postmeta, but sure to update the replacement query in function: set_new_url_from_old_url

		$report['post_count'] = count( $results );

		WP_CLI::line( 'Processing ' . $report['post_count'] . ' posts and pages with html..' );

		foreach( $results as $row ) {

			// each row, &report by reference
			$this->fix_in_content_media( $row, $real_run, $report );
			
		}

		print_r( $report );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-pages'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_pages( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing fix pages..." );

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		// all "Posts" in '/page/' category
		$query = new WP_Query ( [
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'category_name'  => 'page',
		]);

		foreach ($query->posts as $post ) {

			WP_CLI::line( 'Moving post to page for (' . $post->ID . ') ' . $post->post_name );

			// remove categories and tags
			wp_set_post_categories( $post->ID, array() );
			wp_set_post_terms( $post->ID, array() );

			// remove yoast primary
			delete_post_meta( $post->ID, '_yoast_wpseo_primary_category' );

			// set post type
			set_post_type( $post->ID, 'page' );

			// add a redirect:
			$this->set_redirect( get_post_meta( $post->ID, 'newspack_tpr_url', true ), '/' . $post->post_name , 'pages' );

		} // foreach

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-fix-staff'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_staff( $pos_args, $assoc_args ) {

		// make sure cap plugin is working
		$this->coauthorsplus_logic = new CoAuthorPlus();
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin must be activated. Run: wp plugin install co-authors-plus --activate' );
		}

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		// simple local avatars
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::error( 'Simple Local Avatars not found. Install and activate it before using this command.' );
		}

		WP_CLI::line( "Doing fix staff..." );
		
		global $wpdb;

		// all "Posts" in '/staff/' category
		$query = new WP_Query ( [
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'category_name'  => 'staff',
			'order'			=> 'ASC',
			'orderby'		=> 'title',
			'meta_query'    => [
				[
					'key'     => 'newspack_tpr_fix_staff_done',
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		foreach ($query->posts as $post ) {

			WP_CLI::line( 'Moving staff to wpuser or coauthor for ' . $post->post_title );

			// print_r($post);

			$tags = json_decode( get_post_meta( $post->ID, 'newspack_tpr_tags', true) );
			// print_r( $tags );

			$content = $this->set_staff_tags_to_content( $tags, $post->post_content );
			// WP_CLI::line( $content );
			
			// get featured image
			$attachment_id = get_post_thumbnail_id( $post->ID );

			// if no featured
			if( ! ( $attachment_id > 0 ) ) {

				WP_CLI::line( 'No featured image (thumbnail_id)' );

				$attachments = get_post_meta( $post->ID, 'newspack_tpr_attached_image' );

				if( 0 == count ( $attachments ) ) {
					WP_CLI::line( 'No image in postmeta (attached)' );
				}
				else if( 1 == count( $attachments ) ) {

					$attachment_id = $wpdb->get_var( $wpdb->prepare("
						SELECT ID
						FROM $wpdb->posts
						WHERE post_title = %s and post_type = 'attachment'
					", array( $attachments[0] ) ) );

					if( $attachment_id > 0 ) {
						WP_CLI::line( 'Attachment image found from single postmeta.');
					}
					else {
						// we can't do anything here...these are mising images from the old client website
						WP_CLI::line( 'Attachment image was missing from old site');
					}

				}
				else {
					WP_CLI::line( 'Todo: Multiple images, choose by hand?' );
				}
			}
			
			WP_CLI::line( 'Final attachment id is: ' . $attachment_id );
			
			// if wp user
			$user = get_user_by( 'login', $post->post_title );

			// try another way
			if ( ! ( $user instanceof \WP_User ) ) {

				$display_name_user_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM $wpdb->users WHERE display_name = %s LIMIT 1",
					$post->post_title
				));

				if ( is_numeric( $display_name_user_id ) && $display_name_user_id > 0 ) {
					$user = get_user_by( 'id', $display_name_user_id );
				}

			}


			if ( $user instanceof \WP_User ) {

				WP_CLI::line( 'WP User matched by title: ' . $post->post_title );

				update_user_meta( $user->ID, 'description', $content );

				if( $attachment_id > 0 ) {

					$sla_migrated = $this->sla_logic->assign_avatar( $user->ID, $attachment_id );

					if ( ! $sla_migrated ) {
						WP_CLI::line( 'There was an error migrating the avatar image to SLA for user ' . $user->ID . ' and attachment ' . $attachment_id );
					}

				}

			}
			// check for coauthor
			else {

				$user = $this->coauthorsplus_logic->get_guest_author_by_display_name( $post->post_title );
				
				// not found in coauthors list
				if( ! is_object( $user ) ) {
					WP_CLI::line( 'No author match in CAP GA: ' . $post->post_title );
					continue;
				}
				
				$coauthor_fields = array( 'description' => $content );
				
				if( $attachment_id > 0 ) $coauthor_fields['avatar'] = $attachment_id;

				$this->coauthorsplus_logic->update_guest_author( $user->ID, $coauthor_fields );
				
			}

			// add a redirect:
			$this->set_redirect( get_post_meta( $post->ID, 'newspack_tpr_url', true ), '/author/' . $post->post_name , 'staff' );

			// 'trash' post
			// permissions on staging does not allow this: wp_trash_post( $post->ID );
			// instead, add a post meta
			add_post_meta ( $post->ID, 'newspack_tpr_fix_staff_done', 'yes' );
			WP_CLI::line( 'Done. OK to delete post: ' . $post->post_title );
			
		} // foreach

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-import-attached-images'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_attached_images( $pos_args, $assoc_args ) {

		// set path to file
		$csv_path = wp_upload_dir()['basedir'] . '/' . $assoc_args[ 'csv-file' ];
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		// Start doing work
		WP_CLI::line( "Doing import of attached images..." );

		global $wpdb;

		$attachments = new \NewspackCustomContentMigrator\Logic\Attachments();
		
		$report = array(

			'post_id_doesnt_match' => 0,
			'error_attachment_id' => 0,

			'meta_already_exists' => 0,
			'added_new_meta' => 0,

		);

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			// csv data integrity
			if( 5 != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			list( $post_id, $uuid, $url, $caption, $credit ) = $row;

			// MAKE SURE POST ID MATCHES THE SAME UUID
			// DON'T TRUST POST ID IN CSV AS THIS COULD HAVE CHANGED BETWEEN LOCAL AND STAGING DUE TO WXR UPLOADING
			if( $uuid != get_post_meta( $post_id, 'newspack_tpr_uuid', true ) ) {
				WP_CLI::line( 'Post id doesnt match uuid for row: ' . print_r( $row, true ) );
				$report['post_id_doesnt_match']++;
				continue;
			}

			$attachment_id = $this->get_or_import_attachment_id( $url, $caption, $credit );

			if( ! ( $attachment_id > 0 ) ) {
				WP_CLI::line( 'Attachment id not gt zero: ' . print_r( $row, true ) );
				$report['error_attachment_id']++;					
				continue;
			}
			
			// skip if already imported as an attachment to this post
			$post_meta_exists = $wpdb->get_var( $wpdb->prepare("
					SELECT 1 
					FROM $wpdb->postmeta
					WHERE post_id = %d AND meta_key = 'newspack_tpr_attached_image' AND meta_value = %s
				", array( $post_id, $url ) ) );
			
			if( $post_meta_exists ) {
				WP_CLI::line( 'Skipping already imported for post: ' . $post_id );
				$report['meta_already_exists']++;
				continue;
			}

			// add to post meta
			add_post_meta( $post_id, 'newspack_tpr_attached_image', $url );

			$report['added_new_meta']++;

		}

		// close
		fclose($handle);

		print_r( $report );

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-import-attached-images-rewind'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_attached_images_rewind( $pos_args, $assoc_args ) {

		$real_run = false;
		if( isset( $assoc_args[ 'real-run' ] ) && preg_match( '/^yes$/', $assoc_args[ 'real-run' ] ) ) {
			$real_run = true;	
		}

		// Start doing work
		WP_CLI::line( "Doing rewind of attached images..." );

		$rewind_ids = $this->data_attach_ids_rewind();

		global $wpdb;

		$report = array(
			
			'rewind_id_count' => count( $rewind_ids ),
			
			'post_not_exists' => 0,
			'post_not_exists_ids' => array(),

			'post_keys' => array(),

			'removed_from_allow_post_types' => 0,
			'removed_from_allow_post_statuses' => 0,
			'removed_from_allow_post_ids' => 0,

			'remove_meta' => 0,
			'real_run_deleted' => 0,

			'post_keys_by_hand' => array(),



		);

		foreach( $rewind_ids as $post_id ) {

			$post = get_post( $post_id );
			
			// post no longer exists, skip
			if( ! ( $post instanceof \WP_Post ) ) {

				$report['post_not_exists']++;
				$report['post_not_exists_ids'][] = $post_id;
				continue;

			}

			// keep track of types that changed
			$post_key = $post->post_type . '-' . $post->post_status;
			if( empty( $report['post_keys'][$post_key] ) ) $report['post_keys'][$post_key] = 0;
			$report['post_keys'][$post_key]++;

			// just remove from anything that isn't a normal post
			$remove_meta = false;
			if( in_array( $post->post_type, array('npr_story_post', 'attachment', 'newspack_popups_cpt', 'newspack_lst_event', 'revision' ) ) ) {
				$report['removed_from_allow_post_types']++;
				$remove_meta = true;
			}
			// remove from posts that aren't published
			else if( 'post' == $post->post_type && in_array( $post->post_status, array('auto-draft') ) ) {
				$report['removed_from_allow_post_statuses']++;
				$remove_meta = true;
			}
			else if( in_array( $post->ID, [4,44,46])) {
				$report['removed_from_allow_post_ids']++;
				$remove_meta = true;
			}

			// remove
			if( $remove_meta ) {

				$report['remove_meta']++;
		
				if( $real_run ) {

					delete_post_meta( $post->ID, 'newspack_tpr_attached_image' );
					$report['real_run_deleted']++;

				}

				continue;
			}

			// this is when we need to lookup previous value
			$report['post_keys_by_hand'][] = $post_id;				

		}

		print_r( $report );

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-import-featured-images'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_featured_images( $pos_args, $assoc_args ) {

		// set path to file
		$csv_path = wp_upload_dir()['basedir'] . '/' . $assoc_args[ 'csv-file' ];
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		// Start doing work
		WP_CLI::line( "Doing import of featured images..." );

		$attachments = new \NewspackCustomContentMigrator\Logic\Attachments();
		
		$report = array(
			'post_id_doesnt_match' => 0,
			'error_attachment_id' => 0,
			'updated_post' => 0,
		);

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			// csv data integrity
			if( 5 != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			list( $post_id, $uuid, $url, $caption, $credit ) = $row;

			// MAKE SURE POST ID MATCHES THE SAME UUID
			// DON'T TRUST POST ID IN CSV AS THIS COULD HAVE CHANGED BETWEEN LOCAL AND STAGING DUE TO WXR UPLOADING
			if( $uuid != get_post_meta( $post_id, 'newspack_tpr_uuid', true ) ) {
				WP_CLI::line( 'Post id doesnt match uuid for row: ' . print_r( $row, true ) );
				$report['post_id_doesnt_match']++;
				continue;
			}

			$attachment_id = $this->get_or_import_attachment_id( $url, $caption, $credit );

			if( ! ( $attachment_id > 0 ) ) {
				WP_CLI::line( 'Attachment id not gt zero: ' . print_r( $row, true ) );
				$report['error_attachment_id']++;					
				continue;
			}

			// set it featured
			set_post_thumbnail( $post_id, $attachment_id );

			// add a checksum for future imports
			update_post_meta( $post_id, 'newspack_tpr_featured_image_checksum', md5( serialize( $row ) ) );

			$report['updated_post']++;

		}

		// close
		fclose($handle);

		print_r( $report );

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-import-featured-images-rewind'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_featured_images_rewind( $pos_args, $assoc_args ) {

		$dry_run = isset( $assoc_args[ 'dry-run' ] ) ? true : false;

		// set path to file
		$csv_path = wp_upload_dir()['basedir'] . '/' . $assoc_args[ 'csv-file' ];
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		// Start doing work
		WP_CLI::line( "Doing rewind of featured images..." );

		global $wpdb;
			
		$data_original_thumb_ids = $this->data_original_thumb_ids();

		$report = array(

			'csv_file' => $csv_path,
			'row_counter' => 0,
			'rewinding' => 0,
			'no_post' => 0,

			'post-type-attachment' => 0,
			'post-type-revision' => 0,
			'post-type-post-auto-draft' => 0,
			'post-type-npr_story_post-draft' => 0,
			'post-type-npr_story_post-publish' => 0,
			'post-type-wp_block' => 0,
			'import_set_launch' => 0,

			'unkwown_post_type' => 0,

			'thumbnail_no_longer_on_post' => 0,
			'thumbnail_data_not_gt_zero' => 0,
			'thumbnail_no_longer_on_post_report' => array(),

			'reset_original_thumb_id' => 0,
			'reset_report' => array(),

			'attachment_id_missing' => 0,
			'thumbnail_matched' => 0,
			'thumbnail_was_changed_so_skip' => 0,
			'thumbnail_matched_report' => array(),

		);

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			// csv data integrity
			if( 4 != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			$report['row_counter']++;

			// get post based on incorrect id from csv
			$post_results = $wpdb->get_results( $wpdb->prepare("
				SELECT ID, post_status, post_type
				FROM $wpdb->posts
				WHERE ID = %d
				", array( $row[0] ) ) );

			if( empty( $post_results[0] ) ) {
				$report['no_post']++; // the incorrect post id didn't return a result, great, nothing was messed up on this one
				continue;
			}

			// get post object from array
			$post = $post_results[0];

			// check to see what type of post it is
			if( $post->post_type == 'attachment' ) $report['post-type-attachment']++;
			else if( $post->post_type == 'revision' ) $report['post-type-revision']++;
			else if( $post->post_type == 'wp_block' ) $report['post-type-wp_block']++;
			else if( $post->post_type == 'post' && $post->post_status == 'auto-draft' ) $report['post-type-post-auto-draft']++;
			else if( $post->post_type == 'npr_story_post' && $post->post_status == 'draft' ) $report['post-type-npr_story_post-draft']++;
			else if( $post->post_type == 'npr_story_post' && $post->post_status == 'publish' ) $report['post-type-npr_story_post-publish']++;
			else if( 'launch' == get_post_meta( $post->ID, 'newspack_tpr_import_set', true ) ) $report['import_set_launch']++; // we have to re-run anyway...
			else {
				// skip these if we haven't determined what the effect is yet...
				WP_CLI::line( 'Unkown type -- ' . print_r( $post, true ) );
				$report['unkwown_post_type']++;
				continue;
			}

			WP_CLI::line( 'Rewinding meta for: ' . $post->ID );
			$report['rewinding']++;
			$reset_key = $post->post_type . '-' . $post->post_status;

			// for sure remove import value
			if( ! $dry_run ) delete_post_meta( $post->ID, 'newspack_tpr_featured_image_checksum' );			

			// check if the post still has a meta value, this might have been a draft or user removed incorrect thumbnail...
			$meta_thumb_id = get_post_meta( $post->ID, '_thumbnail_id', true );
			if( empty( $meta_thumb_id ) ) {
				$report['thumbnail_no_longer_on_post']++;
				if( empty( $report['thumbnail_no_longer_on_post_report'][$reset_key] ) ) $report['thumbnail_no_longer_on_post_report'][$reset_key] = 0;
				$report['thumbnail_no_longer_on_post_report'][$reset_key]++;
				continue;
			}
			else if( ! ( $meta_thumb_id > 0 ) ) {
				// data integrity check
				WP_CLI::line( 'meta_thumb_id isnt greater than zero...hmm ' . print_r( $post, true ) );
				$report['thumbnail_data_not_gt_zero']++;
				continue;
			}

			// make sure its still the same value otherwise let's not make it worse, the Client may have updated the thumbnail id
			$attachment_id = $wpdb->get_var( $wpdb->prepare("
				SELECT ID
				FROM $wpdb->posts
				WHERE post_title = %s and post_type = 'attachment'
				", array( $row[1] ) ) );

			if( empty( $attachment_id ) || ! ( $attachment_id > 0 ) ) {
				$report['attachment_id_missing']++;
				// WP_CLI::line( 'AttachmentID is missing for: ' . print_r( $row, true ) );
				continue;
			}

			// it's the same as was incorectly set, so remove it
			if( $meta_thumb_id == $attachment_id ) {				

				// see if we can roll back the _thumbnail_id from a backup
				if( isset( $data_original_thumb_ids[$post->ID] ) ) {

					// there is a backup value, use it
					if( ! $dry_run ) update_post_meta( $post->ID, '_thumbnail_id', $data_original_thumb_ids[$post->ID] );
					$report['reset_original_thumb_id']++;
					if( empty( $report['reset_report'][$reset_key] ) ) $report['reset_report'][$reset_key] = 0;
					$report['reset_report'][$reset_key]++;
					continue;

				}

				$report['thumbnail_matched']++;
				if( ! $dry_run ) delete_post_meta( $post->ID, '_thumbnail_id' );
				if( empty( $report['thumbnail_matched_report'][$reset_key] ) ) $report['thumbnail_matched_report'][$reset_key] = 0;
				$report['thumbnail_matched_report'][$reset_key]++;
				continue;
				
			} 
			
			$report['thumbnail_was_changed_so_skip']++;

		}

		// close
		fclose($handle);

		print_r( $report );

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-import-featured-images-rewind-two'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_featured_images_rewind_two( $pos_args, $assoc_args ) {

		$real_run = false;
		if( isset( $assoc_args[ 'real-run' ] ) && preg_match( '/^yes$/', $assoc_args[ 'real-run' ] ) ) {
			$real_run = true;	
		}

		// Start doing work
		WP_CLI::line( "Doing rewind two of featured images..." );

		global $wpdb;
			
		$data_two = $this->data_cover_image_rewind_two();

		$report = array(

			'row_counter' => 0,

			'no_attachment_rows' => 0,

			'unset_post_parent' => 0,
			'post_parent_already_zero' => 0,
			'post_parent_changed' => 0,

			'real_run_updated' => 0,

		);

		foreach( $data_two as $attachment_url => $error_post_id ) {

			$report['row_counter']++;

			// check if attachment still exists...it should!
			$attachment_rows = $wpdb->get_results( $wpdb->prepare("
				SELECT ID, post_parent
				FROM $wpdb->posts
				WHERE post_title = %s and post_type = 'attachment'
				", array( $attachment_url ) ) );

			// make sure we have something...all these images should exist!
			if ( is_wp_error( $attachment_rows ) || is_null( $attachment_rows ) || ! empty( $wpdb->last_error ) ) {
				WP_CLI::line( 'No attachment row: ' . $attachment_url );
				$report['no_attachment_rows']++;
				continue;
			}
			
			// loop through each row (sometimes images are put in twice...so clear the one that is messed up)
			foreach( $attachment_rows as $attachment_row ) {

				// must have a post_parent (even if it's zero)
				if( ! isset( $attachment_row->post_parent ) 
					|| ! is_numeric( $attachment_row->post_parent) 
					|| $attachment_row->post_parent < 0 ) {
					WP_CLI::line( 'Unset post_parent: ' . $attachment_url );
					$report['unset_post_parent']++;
					continue;
				}

				$post_parent_id = $attachment_row->post_parent;

				// if it's zero, skip it
				if( 0 == $post_parent_id ) {
					WP_CLI::line( 'Skipping Post parent already zero: ' . $attachment_url );
					$report['post_parent_already_zero']++;
					continue;
				}

				// if the post_parent isn't the same as the one from the Error list, then just skip it since it was changes some other way...
				if( $post_parent_id != $error_post_id ) {
					WP_CLI::line( 'Skipping Post parent has changed: ' . $attachment_url );
					$report['post_parent_changed']++;
					continue;
				}

				WP_CLI::line( 'Setting post parent from ' . $post_parent_id . ' to 0 for attachment_id = ' . $attachment_row->ID );

				if( $real_run ) {

					// detach it
					$wpdb->update( 
						$wpdb->posts, 
						array( 'post_parent' => 0 ), // new values
						array( 'ID' => $attachment_row->ID ) // where
					);

					$report['real_run_updated']++;

				}

			} // each attachment row

		} // each data

		print_r( $report );

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-parse-backups'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_parse_backups( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing parse of backups into single items file..." );

		// clear output file
		$out_handle = fopen( self::JSON_SINGLE_FILE, 'w');
		if ( ! $out_handle ) {
			WP_CLI::error( 'Output file not writable.' );
		}

		// for comparisons during refreshed and launch
		$this->set_previous_import_lookups();

		// each json file
		$json_backup_files = glob( self::JSON_BACKUP_FOLDER . '/Article/*.json' );
		foreach ( $json_backup_files as $this->json_file ) {

			$file_handle = fopen( $this->json_file, "r");
			if ( ! $file_handle ) {
				WP_CLI::error( 'File not exists: ' . $this->json_file );
			}

			$this->json_line_number = 0;
			while( !feof( $file_handle ) ) {
	
				// increment line counter and get line
				$this->json_line_number++;				
				$this->json_line = trim( fgets( $file_handle ) );
				if( empty( $this->json_line ) ) {
					WP_CLI::warning( 'Blank line ' . $this->json_line_number . ' in file ' . $this->json_file );
					continue;
				}
				
				// keep report of lines processed
				$this->report['lines']++;
	
				// parse the line for skips (deleted, AP news, already imported)
				if( $this->skip_line() ) continue;
				
				// put line in output
				fputs( $out_handle, $this->json_line . "\n" );
			
			} // each line
	
			fclose( $file_handle );
			
		}

		fclose( $out_handle );

		// compare skipped with what was previously processed
		if( ! empty( $this->previous_unique_uuids_processed_again ) ) {
			foreach( $this->previous_unique_uuids_processed_again as $k => $v ) {
				// was not reprocessed...possibly deleted on Live?
				if( 0 == $v ) $this->mylog( 'LAUNCH-reprocess-was-zero', $k );
				// each uuid should reprocess one time
				else if( 1 == $v ) continue;
				// unknown
				else $this->mylog( 'LAUNCH-reprocess-was-multiple', $k );

			}			
		}

		$this->reporting();

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-parse'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_parse( $pos_args, $assoc_args ) {

		if( isset( $assoc_args[ 'posts-per-wxr' ] ) ) {
			if( is_numeric( $assoc_args[ 'posts-per-wxr' ] ) && intval( $assoc_args[ 'posts-per-wxr' ] ) > 0 ) {
				$this->wxr_max_posts = (int) $assoc_args[ 'posts-per-wxr' ];
			}
			else {
				WP_CLI::error( "Posts per wxr argument must be greater than 0." );

			}
		}

		if( ! isset( $assoc_args[ 'import-set' ] ) || ! preg_match('/^[A-Za-z0-9-_]+$/', $assoc_args[ 'import-set' ] ) ) {
			WP_CLI::error( "Import set argument (required) must be a string: [A-Za-z0-9-_] Ex: initial, launch, refresh2" );
		}

		$this->wxr_import_set = $assoc_args[ 'import-set' ];

		WP_CLI::line( "Doing parse of single items file..." );

		// read file
		$file_handle = fopen( self::JSON_SINGLE_FILE, 'r');
		if ( ! $file_handle ) {
			WP_CLI::error( 'Single json file not readable.' );
		}

		$posts = array();

		$this->json_line_number = 0;
		while( !feof( $file_handle ) ) {

			// increment line counter and get line
			$this->json_line_number++;				
			$this->json_line = trim( fgets( $file_handle ) );
			if( empty( $this->json_line ) ) {
				WP_CLI::warning( 'Blank line ' . $this->json_line_number . ' in file ' . $this->json_file );
				continue;
			}
			
			// keep report of lines processed
			$this->report['lines']++;

			// parse the line
			$item = $this->parse_line();

			// single json file should always return an array with keys
			if( ! is_array( $item ) || 0 == count( $item ) ) {
				$this->die_on_line( 'Line produced non array or empty array.', $item ); 
			}

			// parse content
			$post = $this->parse_content( $item );

			// ignore redirects, topics, etc that are output different ways
			if( ! is_array( $post ) ) continue;

			$posts[] = $post;
	
		} // each line

		fclose( $file_handle );

		// filter out drafts and revisions based on duplicate slugs and dates
		// and log unique UUIDs for Media json parsing
		$posts = array_filter( $posts, function( $v ) {
			
			$type_slug_key = $v['meta']['newspack_tpr_article_type'] . '--' . $v['meta']['newspack_tpr_slug'];
			$uuid = $v['meta']['newspack_tpr_uuid'];
			$checksum = $v['meta']['newspack_tpr_checksum'];
			$url = $v['meta']['newspack_tpr_url'];
			$date = $v['date'];

			// if duplicate routes (urls) exist and this uuid is not the latest_uuid, log it then and skip it
			$latest_uuid_for_route = $this->report['article_routes'][$type_slug_key]['latest_uuid'];
			$latest_uuid_for_route_is_match = ( $uuid == $latest_uuid_for_route );

			// write unique UUIDs to a file for use with Media json file parsing and launch differences
			$unique_uuids_str = $uuid . ',' . $checksum . ',' . $date . ',' . $url . ',' . $latest_uuid_for_route . ',' . $latest_uuid_for_route_is_match;
			if( 5 != substr_count( $unique_uuids_str, ',' ) ) {
				WP_CLI::error('To many commas in unique uuid string: ' . $unique_uuids_str );
			}
			$this->mylog( 'unique_uuids', $unique_uuids_str );

			if( ! $latest_uuid_for_route_is_match ) {
				$this->report['skipped_article_duplicate_routes']--;
				return false;
			}

			return true;
				
		});

		$this->write_wxrs( $posts );

		$this->reporting();

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-parse-media'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_parse_media( $pos_args, $assoc_args ) {

		global $wpdb;

		WP_CLI::line( "Doing parse of media backups into single media file..." );

		// make sure the list of unique UUIDs is present and has data in order to filter out AP News (and previously imported)
		$this->set_previous_import_lookups();

		// clear output file
		// $out_handle = fopen( self::JSON_SINGLE_FILE_MEDIA, 'w');
		// if ( ! $out_handle ) {
		// 	WP_CLI::error( 'Output file not writable.' );
		// }

		// hold post info into array before putting into wxr
		// $temp_posts = array();

		$unique_article_ids = array();

		// each json file
		$json_backup_files = glob( self::JSON_BACKUP_FOLDER . '/Media/*.json' );
		foreach ( $json_backup_files as $this->json_file ) {

			$file_handle = fopen( $this->json_file, "r");
			if ( ! $file_handle ) {
				WP_CLI::error( 'File not exists: ' . $this->json_file );
			}

			$this->json_line_number = 0;
			while( !feof( $file_handle ) ) {
	
				// increment line counter and get line
				$this->json_line_number++;				
				$this->json_line = trim( fgets( $file_handle ) );
				if( empty( $this->json_line ) ) {
					WP_CLI::warning( 'Blank line ' . $this->json_line_number . ' in file ' . $this->json_file );
					continue;
				}
				
				// get media item
				$item = $this->parse_line_media();

				// null returned (deleted)
				if( null === $item ) {
					continue;
				}

				// must have related uuid
				if( ! isset( $item['article_uuid'] ) ) {
					$this->die_on_line( 'Media item does not have article uuid', $item );
				}

				// skip if it's not in the unique_uuids.csv (ie: its an AP article or already inserted if this is launch)
				if( ! isset( $this->previous_unique_uuids[$item['article_uuid']] ) ) {
					continue;
				}

				// skip if it's not the primary url in unique_uuids
				if( 1 != $this->previous_unique_uuids[$item['article_uuid']][4] ) {
					continue;
				}

				// data integrity check
				if( ! isset( $item['mediaType'] ) || preg_match('/[^A-Za-z0-9]/', $item['mediaType'] ) ) {
					$this->die_on_line( 'Media item does not have media type or non alphanumeric chars: ', $item );
				}

				// Embed: skip: already handled by blocks
				if( 'Embed' == $item['mediaType'] ) continue;

				// File: skip: doesn't seem to be used
				if( 'File' == $item['mediaType'] ) continue;

				// Slideshow: skip
				// todo: need to parse post_content in future: <slideshow-embed
				if( 'Slideshow' == $item['mediaType'] ) continue;

				// video: skip, they are stored on youtube
				if( 'Video' == $item['mediaType'] ) continue;

				// get post id
				// NO THIS MIGHT USE THE POST ID OF LOCAL MACHINE....USE UUID INSTEAD
				$post_id = $wpdb->get_var( $wpdb->prepare("
					SELECT post_id 
					FROM $wpdb->postmeta
					WHERE meta_key = 'newspack_tpr_uuid' AND meta_value = %s
				", $item['article_uuid'] ) );

				// must have a post id (was it deleted on staging?)
				if( empty( $post_id ) ) {
					$this->mylog( 'media-no-post-id-found', $item );
					continue;
				}

				WP_CLI::line( 'SQL: ' . $item['article_uuid'] . ' => ' . $post_id );
				$unique_article_ids[] = $item['article_uuid'];

				// Audio: KEEP
				// todo: possilby check the post_content to see if soundcloud exists so we don't have to side-load mp3?
				if( 'Audio' == $item['mediaType'] ) {

					$this->log_media_item_audio( 'audio', $post_id, $item ); // todo: duplicate audio files? // , true);
					continue;

				}

				// Image:
				// todo: try this if 404? https://content-prod-ripr.ripr.org/
				if( 'Image' == $item['mediaType'] ) {

					$this->log_media_item( 'image-' . strtolower( $item['position'] ), $post_id, $item );
					continue;
					
				}

				$this->die_on_line( 'No media type found: ', $item );

				// todo: client needs to keep old backups for a year or longer?

				// put line in output
				// fputs( $out_handle, $this->json_line . "\n" );
			
				// print_r($temp_posts); 

			} // each line

			fclose( $file_handle );
			
		}

		WP_CLI::line( 'Unique articles = ' . count( array_unique( $unique_article_ids ) ) );

		// fclose( $out_handle );

		// // save to a WXR
		// $wxr_data = [
		// 	'site_title'  => $this->site_title,
		// 	'site_url'    => $this->site_url,
		// 	'export_file' => self::OUTPUT_FOLDER . '/media-WXR.xml',
		// 	'posts'       => array(
		// 		array(
		// 			'author'  => 'Staff',
		// 			'title'   => 'TEMP IMPORT FOR MEDIA WXR POSTMETA',
		// 			'url'    => 'temp-import-for-media-wxr-postmeta',
		// 			'status' => 'draft',
		// 			'date'    => date('Y-m-d H:i:s', time()),
		// 			'content' => '',		
		// 			'meta' => [
		// 				'newspack_tpr_temp_import_media' => file_get_contents( self::JSON_SINGLE_FILE_MEDIA ),
		// 			],	
		// 		)
		// 	),
		// ];
		
		// Newspack_WXR_Exporter::generate_export( $wxr_data );
		
		// WP_CLI::success( 'Media exported to file: ' . $wxr_data['export_file'] );

	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-reset-initial-for-client'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_reset_initial_for_client( $pos_args, $assoc_args ) {

		/*
		WP_CLI::error('needs more testing');
		// $wpdb->query( "
		// 	delete pm2
		// 	FROM wp_postmeta pm2
		// 	join wp_postmeta pm on pm.post_id = pm2.post_id and pm.meta_key = 'newspack_tpr_import_set' and pm.meta_value = 'initial'
		// 	where pm2.meta_key = '_yoast_wpseo_primary_category'
		// ");
		// WP_CLI::line( 'Target of 10431 yoast rows deleted: ' . $wpdb->rows_affected );
		// for safety sake
		// wp_cache_flush();
		// $wpdb->query("		
		// 	delete tr
		// 	FROM wp_term_relationships tr
		// 	join wp_postmeta pm on pm.post_id = tr.object_id
		// 	join wp_term_taxonomy tt on tt.term_taxonomy_id = tr.term_taxonomy_id and tt.taxonomy = 'category'
		// 	join wp_terms t on t.term_id = tt.term_id
		// 	where pm.meta_key = 'newspack_tpr_import_set' and pm.meta_value = 'initial'
		// ");
		// WP_CLI::line( 'Target of 16257 category object rows deleted: ' . $wpdb->rows_affected );
		// for safety sake
		// wp_cache_flush();
		*/

		WP_CLI::line( "Doing cmd_reset_initial_for_client..." );
		
		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		$batch_running_total = 0;

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$time_start = time();
			$count = $this->reset_initial_for_client( $posts_per_batch );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			$batch_running_total += $count;

			WP_CLI::line('Processed ' . $count . ' rows in ' . (time() - $time_start ) . ' seconds.  Running total: ' . $batch_running_total );

		}

		WP_CLI::success( 'Done' );
		

	}

	private function reset_initial_for_client( $posts_per_batch ) {

		$primary_cats = array_flip( get_categories( [
			'slug'   => [
				"article",
				"episode",
				"news",
				"page",
				"show",
				"staff",
			],
			'fields' => 'id=>slug',
		]));

		// all posts from initial import
		$query = new WP_Query ( [
			'posts_per_page' => $posts_per_batch,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'meta_query'    => [
				[
					'key'	=> 'newspack_tpr_import_set',
					'value' => 'initial',
					'compare' => '=',
				],
				[
					'key'     => 'newspack_tpr_reset_initial_for_client',
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		$count = $query->post_count;

		foreach ($query->posts as $post ) {

			$new_cat_ids = [];

			// get old posts
			$meta_cats = json_decode( get_post_meta( $post->ID, 'newspack_tpr_categories', true) );

			foreach( $meta_cats as $k => $meta_cat_slug ) {
				$meta_cat_id = category_exists( $meta_cat_slug );
				if ( ! ( $meta_cat_id > 0 ) ) {
					$meta_cat_id = wp_insert_category( array(
						'cat_name' => ucwords( str_replace( '-', ' ', $meta_cat_slug ) ),
						'category_nicename' => $meta_cat_slug
					));
				}
				$new_cat_ids[] = $meta_cat_id;
			}

			// get primary category
			$article_type = get_post_meta( $post->ID, 'newspack_tpr_article_type', true );
			$primary_category_id = $primary_cats[strtolower($article_type)] ?? null; 
			$new_cat_ids[] = $primary_category_id;

			// remove existing categories and replace with new
			wp_set_post_categories( $post->ID, $new_cat_ids );

			// set yoast primary use UPDATE to replace
			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', $primary_category_id );

			// use update incase value already existed somehow...
			update_post_meta ( $post->ID, 'newspack_tpr_reset_initial_for_client', 'yes' );

			// WP_CLI::line( $post->ID );
			// print_r($meta_cats);
			// print_r($new_cat_ids);
			// exit;

		}

		return $count;
		
	}


	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-set-authors'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_authors( $pos_args, $assoc_args ) {

		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		$this->coauthorsplus_logic = new CoAuthorPlus();

		// Install and activate the CAP plugin if missing.
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin must be activated. Run: wp plugin install co-authors-plus --activate' );
		}
		
		WP_CLI::line( "Doing thepublicsradio-set-authors..." );

		global $wpdb;

		// get import users
		$import_user_staff = get_user_by('slug', 'staff');
		$import_user_ron = get_user_by('slug', 'ronchambers');

		// fix import author error
		$wpdb->update( 
			$wpdb->posts, 
			array( 'post_author' => $import_user_staff->ID ), 
			array( 'post_author' => $import_user_ron->ID ), 
		);
		
		// clear blank authors meta (json [] empty array) that may have been inserted during import
		delete_metadata( 'post', null, 'newspack_tpr_authors', '[]', true );

		// for launch CoAuthors Plus was already installed during WXR import which means the taxonomy of author is auto-added
		// so we need to remove that default "staff" CAP so the following code will process
		// select posts in the imported user list and aren't already set to CoAuthors
		$query = new WP_Query ( [
			
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'fields'		=> 'ids',
			'author__in' 	=> [ $import_user_staff->ID ], // only adjust imported posts
			'meta_query'    => [
				[
					'key'     => 'newspack_tpr_import_set',
					'value'		=> 'launch',
					'compare' => '=',
				],
			],
			'tax_query' 	=> [
				[
					'taxonomy' => 'author',
					'field' => 'name',
					'terms' => 'staff',
				],
			],
			
		]);

		$count = $query->post_count;

		if( $count > 0 ) {
			
			WP_CLI::line( 'Resetting ' . $count . ' posts for Launch.' );
	
			foreach ($query->posts as $post_id ) {

				WP_CLI::line( 'Resetting post id: ' . $post_id );
				wp_remove_object_terms( $post_id, 'staff', 'author' );
			}
			
			// for safety sake
			wp_cache_flush();

		}

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$time_start = time();
			$count = $this->set_authors( $posts_per_batch, $import_user_staff->ID );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			WP_CLI::line('Processed ' . $count . ' rows in ' . (time() - $time_start ) . ' seconds...');

		}

		WP_CLI::success( 'Done' );
	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-set-primary-categories'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_primary_categories( $pos_args, $assoc_args ) {

		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		WP_CLI::line( "Doing thepublicsradio-set-primary-categories..." );

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$count = $this->set_primary_categories( $posts_per_batch );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			WP_CLI::line('Processed ' . $count . ' rows...');

		}

		WP_CLI::success( 'Done' );
	}

	/**
	 * Callable for 'newspack-content-migrator thepublicsradio-set-redirects'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_redirects( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing thepublicsradio-set-redirects..." );

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		global $wpdb;

		// add -- oldsite redirects
		$old_redirects = $this->data_redirects();
		array_walk( $old_redirects, function( $value, $key ) {

			// log any that are already a page:
			if( get_page_by_path( '/' . $key ) ) {

				// comment out for running on Staging
				// $this->mylog('LAUNCH-redirect-steals-page', array(
				// 	'/' . $key .' (migration) -- IS A PAGE - remove redirect??',
				// 	$value,
				// ));

			}
			
			$this->set_redirect( '/' . $key, $value, 'migration' );

		});


		// add -- article extra routes
		$results = $wpdb->get_results("
			select meta_value, ID, post_name, post_type
			from wp_postmeta 
			join wp_posts on wp_posts.ID = wp_postmeta.post_id
			where meta_key = 'newspack_tpr_routes' and meta_value like '%\",\"%';
		");

		foreach( $results as $row ) {
			
			// routes
			$routes = json_decode($row->meta_value);

			// skip the first route: during import, the WXR used route[0] as the post_name so no point in adding redirect
			unset( $routes[0] );			

			array_walk( $routes, function( $value ) use ( $row ) {
				
				// skip routes with spaces
				if( false !== strpos( $value, ' ') ) {
					// WP_CLI::warning( 'Skipping route with space: ' . $value );
					return;
				}

				// skip any where the route and postname are the same
				if( $value == $row->post_name ) {
					// WP_CLI::warning( 'Skipping route with same postname: ' . $value . ' = ' . $row->post_name );
					return;
				}

				// log any that are already a page:
				if( get_page_by_path( '/' . $value ) ) {

					// comment out for running on Staging
					// $this->mylog('LAUNCH-redirect-steals-page', array(
					// 	'/' . $value .' (routes) -- IS A PAGE - remove redirect??',
					// 	$row,
					// 	'/' . $value,
					// 	'/?name=' . $row->post_name
					// ));

				}
				
				$this->set_redirect( '/' . $value, '/?name=' . $row->post_name, 'routes' );

			});
			
		}


		// NO, wordpress seems to be able to handle most all these correctly
		// additionally many of them that are -2 also have duplicated content as the -1 url anyway...so just let the -1 fall through
		
		// add -- redirect where old slug not equal to new slug
		// $results = $wpdb->get_results("
		// 	select p.ID, p.post_name, pm.meta_value as old_slug
		// 	from wp_posts p
		// 	join wp_postmeta pm on pm.post_id = p.ID 
		// 		and pm.meta_key = 'newspack_tpr_slug' 
		// 		and pm.meta_value <> p.post_name
		// ");

		// foreach( $results as $row ) {
			
		// 	// ignore leading and trailing - dashes as wordpress can handle these already
		// 	$test = preg_replace('/(^-)|(-$)/', '', $row->old_slug );
		// 	// ignore dots too
		// 	$test = preg_replace('/\./', '-', $test );
		// 	// ignore duplicated -- dashes too as wordpress handles this
		// 	$test = preg_replace('/-+/', '-', $test );
		// 	if( $test === $row->post_name ) {
		// 		WP_CLI::warning( 'Skipping route with dashes and dots: ' . $row->old_slug );
		// 		continue;
		// 	}

		// 	$this->set_redirect( 
		// 		get_post_meta( $row->ID, 'newspack_tpr_url', true ), 
		// 		'/?name=' . $row->post_name, 
		// 		'mismatch'
		// 	);
			
		// }

		WP_CLI::success( 'Done' );
	}


	/**
	 * Parsing
	 * 
	 */

	 private function parse_line() {

		// Json
		$json = json_decode( $this->json_line, true, 2147483647 );
		if( empty( $json ) || ! is_array( $json ) || 1 != count( $json ) ) {
			$this->die_on_line( 'JSON error on line' );
		}

		// Item
		$item = $this->get_item( $json, $this->known_item_keys );
		
		// Fix data for 'author' vs 'authors'
		// updates $item by reference
		// -- will remove 'author' key
		// -- may add 'authors' key if not exists
		$this->fix_authors( $item ); // by reference

		// return item for proccessing
		return $item;
	
	}

	private function parse_line_media() {

		// Json
		$json = json_decode( $this->json_line, true, 2147483647 );
		if( empty( $json ) || ! is_array( $json ) || 1 != count( $json ) ) {
			$this->die_on_line( 'JSON error on line' );
		}

		// Item
		$item = $this->get_item( $json, $this->known_item_keys_media );

		if( $this->is_deleted( $item ) ) return null;

		return $item;

	}

	private function skip_line() {

		// Json
		$json = json_decode( $this->json_line, true, 2147483647 );
		if( empty( $json ) || ! is_array( $json ) || 1 != count( $json ) ) {
			$this->die_on_line( 'JSON error on line' );
		}

		// Item
		$item = $this->get_item( $json, $this->known_item_keys );
		
		// Deleted
		if( $this->is_deleted( $item ) ) return true;
		
		// Verify data
		$this->verify_article_type( $item );
		$this->verify_categories( $item );

		// Fix data for 'author' vs 'authors'
		// updates $item by reference
		// -- will remove 'author' key
		// -- may add 'authors' key if not exists
		$this->fix_authors( $item ); // by reference

		// AP articles
		if( $this->skip_ap( $item ) ) return true;

		// check if already imported
		return $this->parse_check_if_already_processed( $item );
	
	}

	private function parse_content( $item ) {

		// add content type to report
		if( ! isset( $this->report['content_types'][ $item['articleType'] ] ) ) {
			$this->report['content_types'][ $item['articleType'] ] = 1;
		}
		else $this->report['content_types'][ $item['articleType'] ]++;

		// study content types
		// todo: remove this line
		$this->mylog( 'article-type-' . $item['articleType'], $item );

		// study showtimes
		if( ! empty( $item['showtimes'] ) ) {
			$this->mylog( 'showtimes', $item );
		}
		
		// process each content type
		switch( $item['articleType'] ) {

			case "Article": 
			case "Episode": 
			case "News":
			case "Page":
			case "Show":
			case "Staff":
				return $this->parse_article( $item );

			case "Redirect":
				$this->parse_redirect( $item );
				return null;
				
			case "Topic":
				$this->parse_topic( $item );
				return null;

			case "Alert":
			case "Event":
			case "Promotion":
				// [Alert] => 8
				// [Event] => 1
				// [Promotion] => 46
				// todo: set these up as redirects?  /?name=slug or /go/slug ?
				return null;

			default: 
				$this->die_on_line( 'No article type to process');
		}

	}

	private function parse_check_if_already_processed( $item ) {

		// if nothing exists in previous, then return false because not imported
		if( empty( $this->previous_unique_uuids ) ) return false;

		// don't mark non-Posts as already imported, its ok if they are reprocessed again
		if( ! preg_match('/^(Article|Episode|News|Page|Show|Staff)$/', $item['articleType'] ) ) return false;
		
		// copied form parse_article - need to re-skip
		if( empty( $item['title'] ) ) return true;
		if( empty( $item['routes'] ) ) return true;

		// -- set the body by reference for item for checksum
		// if false (blank body) return "already processed"
		// -- BY REFERENCE
		if( ! $this->fix_content_body( $item, $body_filled_from_summary ) ) return true;

		// check if previously imported by uuid
		if( isset( $this->previous_unique_uuids[$item['uuid']] ) ) {
			
			// if checksum is different - log for review
			if ( md5( serialize( $item ) ) != $this->previous_unique_uuids[$item['uuid']][0] ) {
				// log differently if primary uuid (for route)
				if( $this->previous_unique_uuids[$item['uuid']][4] == 1 ) $this->mylog( 'LAUNCH-prev-checksum-changed-primary-uuid', $item );
				else $this->mylog( 'LAUNCH-prev-checksum-changed-non-primary', $item );
			}
			else {
				$this->mylog( 'LAUNCH-prev-checksum-matched', $item['uuid'] );
			}

			// keep track of re-processed to detect deleted on Live
			$this->previous_unique_uuids_processed_again[$item['uuid']]++;

			return true;

		}

		// so it's not a matching uuid, but could it be a new uuid that is trying to take over an already imported URL?
		$url = '/' . strtolower( $item['articleType'] ) . '/' . $item['routes'][0];
		if( isset( $this->previous_not_unique_urls[$url] ) ) {
			$this->mylog( 'LAUNCH-prev-url-match', $item );
			return true;
		}

		// check if date is prior to previous max time
		$date_str = $item['published_at'] ?? $item['createdAt'];	
		if( $this->previous_max_time && strtotime( $date_str ) < $this->previous_max_time ) {
			$this->mylog( 'LAUNCH-prev-date-older', $item );
			return true;

		}

		return false;
		
	
	}

	private function parse_article( $item ) {

		// Date
		$date_str = $item['published_at'] ?? $item['createdAt'];
		$this->check_date_in_future( $date_str, $item );

		// Title
		if( $this->log_and_skip_empty( 'title', $item, 'skipped_article_no_title') ) return null;

		// Routes and Slug
		if( $this->log_and_skip_empty( 'routes', $item, 'skipped_article_empty_routes') ) return null;
		
		// -- use the first one, save the rest in meta for redirects
		$slug = $item['routes'][0]; 
		
		// -- keep a report of duplicate primary slugs within an Article Type using uuid's and dates
		$this->track_article_routes( $item, $slug, $date_str );

		// Content / Body / Summary
		$body_filled_from_summary = false;
		// -- by reference
		if( false === $this->fix_content_body( $item, $body_filled_from_summary ) ) {
			$this->log_and_skip_empty( 'contentBody', $item, 'skipped_article_empty_content');
			return null;
		}
		
		// Categories
		$categories = $meta_categories = $item['categories'] ?? array();
		$this->track_array_in_report( $categories, 'article_categories' );

		// -- and add in primary yoast category based on old article type
		$categories[] = $item['articleType'];

		// Tags
		$tags = $meta_tags = $item['tags'] ?? array();
		
		// -- for Staff type, don't track tags (they contain phone/email) nor save to Post (just keep meta_tags)
		if( 'Staff' == $item['articleType'] ) {
			$tags = array();
		}
		else {
			$this->track_array_in_report( $tags, 'article_tags' );
		}

		// showtimes
		$showtimes = $item['showtimes'] ?? array();

		// Add values to a single post array
		$post = [

			'author'  => 'Staff',
			'title'   => $item['title'],
			'url'    => $slug,
			'content' => $item['contentBody'],
			'excerpt' => $item['summary'] ?? '',
			'categories' => $categories,
			'tags' => $tags,

			// just use one date value
			// published_at -- primary
			// createdAt -- fallback
			// updatedAt -- not used
			'date'    => $date_str,

			'meta' => [

				// these will be converted to Guest Authors in CoAuthorsPlus
				'newspack_tpr_authors' => empty( $item['authors'] ) ? json_encode( array() ) : json_encode( $item['authors'] ),
				
				// this will be used for yoast primary category
				'newspack_tpr_article_type' => $item['articleType'],

				// helpful to catch changes in future imports
				'newspack_tpr_uuid' => $item['uuid'],
				'newspack_tpr_checksum' => md5( serialize( $item ) ),
				'newspack_tpr_import_set' => $this->wxr_import_set,

				// helpful for redirects if needed
				'newspack_tpr_routes' => json_encode( $item['routes'] ),
				'newspack_tpr_slug' => $slug,
				'newspack_tpr_url' => '/' . strtolower( $item['articleType'] ) . '/' . $slug,

				// keep content incase
				'newspack_tpr_categories' => json_encode( $meta_categories ),
				'newspack_tpr_tags' => json_encode( $meta_tags ),
				'newspack_tpr_showtimes' => json_encode( $showtimes ),
				'newspack_tpr_delta' => $item['delta'] ?? '',

				// other notices
				'newspack_tpr_body_filled_from_summary' => ($body_filled_from_summary) ? 'yes' : '',

			],
						
		]; // post
			
		return $post;

	}

	private function parse_redirect( $item ) {

		if( empty( $item['routes'] ) ) {
			$this->report['skipped_redirect_empty_routes']--;
			$this->mylog( 'skipped_redirect_empty_routes', $item );
			return null;
		}

		if( empty( $item['contentBody'] ) ) {
			$this->report['skipped_redirect_empty_body']--;
			$this->mylog( 'skipped_redirect_empty_body', $item );
			return null;
		}

		$redirects = array();
		foreach( $item['routes'] as $route ) {
			$redirects[] = array( 
				$route, 
				$item['contentBody'],
				$item['deletedAt'] ?? '',
				$item['published_at'] ?? '',
				$item['createdAt'] ?? '',
				$item['updatedAt'] ?? '',
			);
		}

		$this->log_to_csv( 'CSV-redirects', $redirects );

	}

	private function parse_topic( $item ) {

		// track empty routes
		if( empty( $item['routes'] ) ) {
			$this->report['skipped_topic_empty_routes']--;
			$this->mylog( 'skipped_topic_empty_routes', $item );
			return null;
		}

		// track no array
		if( ! is_array( $item['routes'] ) ) {
			$this->die_on_line( 'Topic routes not array.');
		}

		// track multiple routes
		if( count( $item['routes'] ) > 1 ) {
			$this->die_on_line( 'Topic routes is multiple.');
		}

		// set route
		$slug = $item['routes'][0];

		// skip routes that start with https://
		if( preg_match( '/^https:/', $slug ) ) {
			$this->report['skipped_topic_https_routes']--;
			$this->mylog( 'skipped_topic_https_routes', $item );
			return null;
		}

		// track duplicate routes
		if( empty( $this->report['topic_routes'][$slug] ) ) $this->report['topic_routes'][$slug] = 1;
		else $this->report['topic_routes'][$slug]++;

		// content body
		if( empty( $item['contentBody'] ) ) $item['contentBody'] = '';
		if( ! empty( $item['delta'] ) && preg_match( '#<code-embed#', $item['contentBody'] ) ) {
			$item['contentBody'] = $this->fix_delta_body( $item['delta'], $item['contentBody'] );
		}

		// todo:

		// $topic = array(
		// 	'uuid' => $item['uuid'] ?? '',
		// 	'title' => $item['title'] ?? '',
		// 	'contentBody' => $item['contentBody'],
		// 	'categories' => empty( $item['categories'] ) ? '' : implode( ", ", $item['categories'] ),
		// 	'routes' => $slug,
		// );

		// $this->log_to_csv( 'CSV-topics', array( $topic ) );

	}

	/**
	 * Getters
	 * 
	 */

	/*
	Array
		[ops] => Array
			[11] => Array
				[insert] => Array
					[code-embed] => Array
						[uuid] => 8590f2ff-acf0-4267-8eb7-1f103e13a7e6-1
						[embedCode] => <blockquote class="twitter-tweet"><p lang="en" dir="ltr">Since we don&#39;t know who is vaccinated and who isn&#39;t, reasonable to keep indoor mask mandates for a few more weeks<br><br>Allows people to finish getting vaccinated<br><br>And infection numbers to drop further<br><br>Agree that vaccinated folks are safe. Policy response should follow in weeks ahead</p>&mdash; Ashish K. Jha, MD, MPH (@ashishkjha) <a href="https://twitter.com/ashishkjha/status/1392901048885469184?ref_src=twsrc%5Etfw">May 13, 2021</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>
	*/
	private function get_delta_inserts( $delta ) {

		if( empty( $delta ) ) return array();
		$delta = $delta = json_decode( $delta, true, 2147483647 );
		if( empty( $delta['ops'] ) ) return array();
		
		$out = array(
			'embeds' => array(),
		);

		foreach( $delta['ops'] as $op ) {
			foreach( $op as $inserts ) {
				if( ! is_array( $inserts ) ) continue;
				foreach( $inserts as $key => $insert ) {
					if( 'code-embed' == $key ) {
						$out['embeds'][$insert['uuid']] = $insert['embedCode'];
					}
				}
			}
		}
		
		return $out;

	}

	private function get_item( $json, $key_check ) {

		if( empty( $json['Item'] ) ) {
			$this->die_on_line( 'Json item missing');
		}

		$item = array();
		foreach( $json['Item'] as $key => $value ) {
		
			if( ! in_array( $key, $key_check ) ) {
				$this->die_on_line( 'Unknown item key found' , $key );
			}

			// unmarshal values
			$item[$key] = $this->unmarshalValue( $value );
		}

		if( 0 == count( $item ) ) {
			$this->die_on_line( 'Unmarshaled item is blank array.', $item );
		}

		return $item;
	}

	private function get_link_from_element( $element, $attribute, $row, &$report ) {

		// test (and temporarily fix) ill formatted elements
		$had_line_break = false;
		if( preg_match( '/\n/', $element ) ) {
			$element = preg_replace('/\n/', '', $element );
			$had_line_break = true;
		}

		// parse URL from the element
		if( ! preg_match( '/' . $attribute . '=[\'"](.+?)[\'"]/', $element, $url_matches ) ) {
			$report['do_by_hand'][] = "Post " . $row->ID . " does not have URL: " . $element;
			return null;
		}

		// set easy to user variable
		$url = $url_matches[1];

		// test (and temporarily fix) ill formatted links
		$had_leading_whitespace = false;
		if( preg_match( '/^\s+/', $url ) ) {
			$url = preg_replace('/^\s+/', '', $url );
			$had_leading_whitespace = true;
		}

		// test (and temporarily fix) ill formatted links
		$had_trailing_whitespace = false;
		if( preg_match( '/\s+$/', $url ) ) {
			$url = preg_replace('/\s+$/', '', $url );
			$had_trailing_whitespace = true;
		}

		// skip known off-site urls and other anomalies
		$skips = array(
			'\/\/d3q1ytufopwvkq.cloudfront.net',
			'https?:\/\/([a-z]+.ap.org|cpa.ds.npr.org|datawrapper.dwcdn.net|docs.google.com|player.vimeo.com|w.soundcloud.com|www.youtube.com)',
			'https:&#47;&#47;public.tableau.com',
			'mailto',
			'publicfiles.fcc.gov',
		);
		if( preg_match( '/^(' . implode( '|', $skips ) . ')/', $url ) ) return;

		// we're only looking for media (must have an extension), else skip
		if( ! preg_match( '/\.([A-Za-z0-9]{3,4})$/', $url, $ext_matches ) ) return;

		// ignore certain extensions that are not media files
		// todo: when Audio service is determined, create new script for mp3 + wav in content
		if( in_array( $ext_matches[1], array( 'asp', 'aspx', 'com', 'edu', 'htm', 'html', 'net', 'org', 'php' ) ) ) return;

		// todo: doesn't start with http
		if( ! preg_match( '/^https?:\/\//', $url ) ) {
			WP_CLI::error( 'Onsite url: ' . $element );
		}

		// only match The Publics Radio domains
		if( ! preg_match('/(' . implode( '|', $this->data_domains() ) . ')/', $url ) ) return;

		// todo: handle issues previously fixed
		if( $had_line_break || $had_leading_whitespace || $had_trailing_whitespace ) {
			$report['do_by_hand'][] = "Post " . $row->ID . " had whitespace issue: " . $element;
			return null;
		}

		return $url;

	}

	private function get_or_create_wp_user( $email, $display_name ) {

		$user = get_user_by( 'email', $email );
		
		if ( false === $user ) {

			$author_data = [
				'user_login'   => $display_name,
				'user_email'   => $email,
				'display_name' => $display_name,
				'first_name'   => strstr( $display_name, ' ', true ),
				'last_name'    => strstr( $display_name, ' ' ),
				'role'         => 'author',
				'user_pass'    => wp_generate_password(),
			];

			$user_id = wp_insert_user( $author_data );

			if ( is_wp_error( $user_id ) ) {
				var_dump( $author_data );
				WP_CLI::error( $user_id->get_error_message() );
			}

			WP_CLI::line('Created WP USER for: ' . $email );

			$user = get_user_by( 'id', $user_id );
		}

		return $user;

	}

	private function get_or_import_attachment_id( $url, $caption, $credit ) {
				
		global $wpdb;
		
		$attachments = new \NewspackCustomContentMigrator\Logic\Attachments();

		// check if attachment already imported
		$attachment_id = $wpdb->get_var( $wpdb->prepare("
			SELECT ID
			FROM $wpdb->posts
			WHERE post_title = %s and post_type = 'attachment'
		", array( $url ) ) );

		if ( is_wp_error( $attachment_id ) || ! empty( $wpdb->last_error ) ) {

			WP_CLI::line( 'DB error for attachment query: ' . $url );
			return 0;

		}

		if( $attachment_id > 0 ) {

			WP_CLI::line ( 'Attachment id exists for: ' . $url );
			return $attachment_id;

		}

		// count is 0
		$attachment_id = $attachments->import_external_file( $url, $url, $caption, $caption, $caption );

		if( is_wp_error( $attachment_id ) ) {
		
			WP_CLI::line ( 'Attachment cound not be imported: ' . $url );

			if( 'Sorry, you are not allowed to upload this file type.' != $attachment_id->get_error_message() ) {
				WP_CLI::line( 'Attachment produced unknow wp error for: ' . $url );
			}

			return 0;
		}

		if( ! ( $attachment_id > 0 ) ) {

			WP_CLI::line( 'Attachment maybe imported...id is is not positive integer: ' . $url );
			return 0;
			
		}

		WP_CLI::line ( 'New attachment imported: ' . $url );

		// update photo/media credit
		update_post_meta( $attachment_id, '_media_credit', $credit );

		return $attachment_id;

	}


	/**
	 * Checks
	 * 
	 */

	private function check_date_in_future( $date_str, $item ) {
	 
		// if date is in the future, "scheduled"?
		if( strtotime( $date_str ) > time() ) {
			$this->die_on_line( 'Date in future.', $item );
		}

	}

	private function is_deleted( $item ) {

		if( ! isset( $item['deletedAt'] ) ) return false;
		
		$deleted_at = strtotime( $item['deletedAt'] );
		
		// parse error
		if ( 'false' === $deleted_at ) {
			$this->die_on_line( 'Strtotime parse error');
		}

		// check future time (?)
		if( $deleted_at > time() ) {
			$this->die_on_line( 'Future deleted time' );
		}

		// is deleted
		if ( $deleted_at > 0 ) {

			$this->report['deleted']--;
			return true;
		}

		return false;

	}

	private function skip_ap( $item ){
		
		$maybe_ap = 0;

		// -- scan authors
		if( ! empty( $item['authors'] ) ) {

			if( preg_match('/Associated Press/', json_encode( $item['authors'] ) ) ) {
				$this->report['skipped_ap']--;
				return true;
			}

			// example: By KEN MILLER and HEATHER HOLLINGSWORTH
			foreach( array_column( $item['authors'], 'displayName') as $value ) {
				if( preg_match('/^By /', $value ) ) {
					$maybe_ap++;
					break;
				}	
			}
	
			if( ! preg_match('/"uuid"/', json_encode( $item['authors'] ) ) ) {
				$maybe_ap++;
			}
	
		}
		
		// -- scan categories
		if( ! empty( $item['categories'] ) ) {

			// see if any matching ap categories
			if( ! empty( array_intersect( ['ap-stories', 'top-ap-stories'], $item['categories'] ) ) ) {
				$this->report['skipped_ap']--;
				return true;
			}

			// see if any matching psuedo-ap categories
			if( ! empty( array_intersect( ['national-news', 'sports', 'world-news'], $item['categories'] ) ) ) {
				$maybe_ap++;
			}

		}

		// -- scan content
		if( ! empty( $item['contentBody'] ) ) {
			
			// example: \n   <p>BARCELONA, Spain (AP) — 
			if( preg_match('/^\s*<p>[A-Za-z.,\-\s]* \(AP\) — /', $item['contentBody'] ) ) {
				$this->report['skipped_ap']--;
				return true;
			}

		}
		
		// -- article type
		if( 'Article' == $item['articleType'] ) {
			$maybe_ap++;
		}
		else if( 3 == $maybe_ap ) {
			$this->die_on_line( 'Skip ap for non-article type?' );
		}

		// -- check maybe
		if( 4 == $maybe_ap ) {
			$this->report['skipped_maybe_ap']--;
			return true;
		}

		return false;

	}

	private function verify_article_type( $item ) {

		if( empty( $item['articleType'] ) ) {
			$this->die_on_line( 'Article type empty' );
		}
		
		if( ! in_array( $item['articleType'], $this->known_article_types ) ) {
			$this->die_on_line( 'Unknown article type found' , $item['articleType'] );
		}

	}

	private function verify_author( $author ) {

		if( ! is_array( $author ) ) {
			$this->die_on_line( 'Author should be array', $author );
		}

		if( empty( $author['displayName'] ) ) {
			$this->die_on_line( 'Author should have displayName', $author );
		}

		$count = count( $author );
		if( $count > 2 || 0 == $count ) {
			$this->die_on_line( 'Author key count error', $author );
		}

	}

	private function verify_categories( $item ) {

		if( ! isset( $item['categories'] ) ) return;

		if( ! is_array( $item['categories'] ) ) {
			$this->die_on_line( 'Categories not array' );
		}

		if( 0 == count( $item['categories'] ) ) {
			$this->die_on_line( 'Categories array is empty' );
		}

	}

	/**
	 * Fixes
	 *
	 */

	private function fix_authors( &$item ) {

		/*
		[author] => Array
            [displayName] => Ron Chambers
            [uuid] => author-ron-chambers
        */
		$single_author = null;
		if( ! empty( $item['author'] ) ) {
	
			$this->verify_author( $item['author'] );
			$single_author = $item['author'];
			unset( $item['author'] );

			if( empty( $item['authors'] ) ) {
				$item['authors'] = array( $single_author );
				return;
			}

		}

		/*
		 [authors] => Array
            [0] => Array
                    [displayName] => Ron Chmambers
                    [uuid] => author-ron-chambers
        */
		$single_author_in_authors = false;
		if( ! empty( $item['authors'] ) ) {

			if( ! is_array( $item['authors'] ) || 0 == count( $item['authors'] ) ) {
				$this->die_on_line( 'Authors should be array with count > 0', $item );
			}

			// verify each author in array
			foreach( $item['authors'] as $author ) {

				$this->verify_author( $author );
			
				// test if a non-null single author is found in the loop
				if( null !== $single_author && json_encode( $single_author ) == json_encode( $author ) ) {
					$single_author_in_authors = true;
				}

			}

			// if single author exists and it wasn't in the loop, add it to array
			if( null !== $single_author && false === $single_author_in_authors ) {
				$item['authors'][] = $single_author;
			}

		}

	}

	private function fix_delta_body( $delta, $body ) {

		// $this->mylog('delta', $body);
		// $this->mylog('delta', $delta);

		$inserts = $this->get_delta_inserts( $delta );

		// replace element with insert
		foreach( $inserts['embeds'] as $uuid => $insert ) {

			$insert = '<!--newspack_tpr_delta_start uuid=' . $uuid . '-->' . $insert . '<!--/newspack_tpr_delta_end-->';

			$body = preg_replace( '#<code-embed.*?uuid=["\']' . $uuid . '["\'].*?</code-embed>#', $insert, $body );
			
		}

		// $this->mylog('delta', $body);

		return $body;

	}

	private function fix_content_body( &$item, &$body_filled_from_summary ) {

		if( empty( $item['contentBody'] ) ) {
			
			// try to use summary for body
			if( ! empty( $item['summary'] ) ) {

				// set body (and add a meta boolean just in case)
				$item['contentBody'] = $item['summary'];
				$body_filled_from_summary = true;

			}
			
			// so...no body and no summary...that is OK for Staff, just set to blank
			else if( 'Staff' == $item['articleType']) {
				
				// empty body is ok
				$item['contentBody'] = '';

			}

			// it's assumed content type need a body (??)
			else {

				return false;

			}	

		} // empty body

		// load in delta replacments
		if( ! empty( $item['delta'] ) && preg_match( '#<code-embed#', $item['contentBody'] ) ) {
			$item['contentBody'] = $this->fix_delta_body( $item['delta'], $item['contentBody'] );
		}

		return true;
		
	}

	private function fix_in_content_media( $row, $real_run, &$report ) {

		$elements_to_keep = "a|file-attachment|img|slideshow-embed";
		$elements_to_ignore = "b|blockquote|body|br|cite|code|div|em|figure|form|font|g|li|h[0-9]|hr|i|iframe|link|noscript|object|ol|o:p|p|param|path|s|section|script|small|span|strike|strong|sub|sup|svg|table|tbody|td|time|tr|u|ul|wbr";

		preg_match_all( '/<[^>]*?>/i', $row->post_content, $elements );

		// print_r($elements);
			
		foreach( $elements[0] as $element ) {
			
			// anchors
			if( preg_match( '/^<a /', $element ) ) {

				if( null == ( $link = $this->get_link_from_element( $element, 'href', $row, $report ) ) ) continue;
				$report['a_elements_to_fix']++;
				WP_CLI::line ( 'ANCHOR: ' . $link . ' in post: ' . $row->ID . ' old url: ' . get_post_meta( $row->ID, 'newspack_tpr_url', true ) );
				if( $this->set_new_url_from_old_url( $row->ID, $link, $real_run ) ) {
					$report['a_elements_fixed']++;
				}
				continue;

			}

			// file-attachment
			if( preg_match( '/^<file-attachment /', $element ) ) {

				if( null == ( $link = $this->get_link_from_element( $element, 'path', $row, $report ) ) ) continue;
				$report['file_elements_to_fix']++;
				WP_CLI::line ( 'FILEATTACHMENT: ' . $link . ' in post: ' . $row->ID . ' old url: ' . get_post_meta( $row->ID, 'newspack_tpr_url', true ) );
				if( $this->set_new_url_from_old_url( $row->ID, $link, $real_run ) ) {
					$report['file_elements_fixed']++;
				}
				continue;

			}

			// img
			if( preg_match( '/^<img /', $element ) ) {

				if( null == ( $link = $this->get_link_from_element( $element, 'src', $row, $report ) ) ) continue;
				$report['img_elements_to_fix']++;
				WP_CLI::line ( 'IMG: ' . $link . ' in post: ' . $row->ID . ' old url: ' . get_post_meta( $row->ID, 'newspack_tpr_url', true ) );
				if( $this->set_new_url_from_old_url( $row->ID, $link, $real_run ) ) {
					$report['img_elements_fixed']++;
				}
				continue;

			}

			// slideshow-embed
			if( preg_match( '/^<slideshow-embed /', $element ) ) {

				// get links
				if( ! preg_match_all( '/&quot;(http.*?)&quot;,?/', $element, $slidshow_images ) ) {
					$report['do_by_hand'][] = "Post " . $row->ID . " not proper slideshow: " . $element;
					continue;
				} 

				// process links
				foreach( $slidshow_images[1] as $slideshow_link ) {
					// use the get link function to make sure link is an "on-site" (or CDN) URL
					if( null == ( $slideshow_link = $this->get_link_from_element( 'src="' . $slideshow_link . '"' , 'src', $row, $report ) ) ) continue;
					$report['slideshow_elements_to_fix']++;
					WP_CLI::line ( 'SLIDESHOW: ' . $slideshow_link . ' in post: ' . $row->ID . ' old url: ' . get_post_meta( $row->ID, 'newspack_tpr_url', true ) );
					if( $this->set_new_url_from_old_url( $row->ID, $slideshow_link, $real_run ) ) {
						$report['slideshow_elements_fixed']++;
					}
				}

				continue;

			}
			
			// special cases
			if( preg_match( '/^<(a|script)>$/', $element ) ) continue;

			// elements to keep that were not processed?
			if( preg_match( '/^<(' . $elements_to_keep . ')/', $element ) ) {

				// if we get here, an unknown element was found
				WP_CLI::warning( 'Element was not processed (elements to keep)' );
				WP_CLI::line( $element );
				print_r( $elements );
				print_r( $row ); 
				exit();

			}

			// remove closing elements
			if( preg_match( '/^<\//', $element ) ) continue;
			
			// remove comments
			if( preg_match( '/^<!--/', $element ) ) continue;

			// clear elements to ignore
			if( preg_match( '/^<(' . $elements_to_ignore . ')(\s|>)/i', $element ) ) continue;

			// special cases
			if( preg_match( '/^<(e.length|t.length|\!\[CDATA\[|\!\]\]|0.5% of state budget|\\\\\/script)/', $element ) ) continue;

			// if we get here, an unknown element was found
			WP_CLI::warning( 'Element was not processed (special cases)' );
			WP_CLI::line( $element );
			print_r( $elements );
			print_r( $row ); 
			exit();

		}

	}


	/**
	 * Setters
	 * 
	 */


	private function set_authors( $posts_per_page, $import_user_staff_id ) {

		// get real wp users
		$wp_users = $this->data_wp_users();
		
		// select posts in the imported user list and aren't already set to CoAuthors
		$query = new WP_Query ( [
			
			'posts_per_page' => $posts_per_page,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'fields'		=> 'ids',
			'author__in' 	=> [ $import_user_staff_id ], // only adjust imported posts

			'meta_query'    => [
				[
					'key'     => 'newspack_tpr_authors',
					'compare' => 'EXISTS',
				],
			],

			// coauthors not set already
			'tax_query' 	=> [
				[
					'taxonomy' => 'author',
					'field' => 'slug',
					'operator' => 'NOT EXISTS',
				],
			],

		]);

		$count = $query->post_count;

		WP_CLI::line( 'Set authors for ' . $count . ' posts...' );

		foreach ($query->posts as $post_id ) {

			// get meta as json object
			$authors = json_decode( get_post_meta( $post_id, 'newspack_tpr_authors', true ) );

			if( null == $authors ) {
				WP_CLI::error( 'Null authors for post_id = ' . $post_id );
			}
			
			$authors_to_assign = array();
			foreach( $authors as $author ) {

				// check if author or real wp user
				if( isset( $wp_users[$author->displayName] ) ) {
					$authors_to_assign[] = $this->get_or_create_wp_user( $wp_users[$author->displayName], $author->displayName );
				}
				else {
					$cap_id = $this->coauthorsplus_logic->create_guest_author( [
						'display_name' => sanitize_text_field( $author->displayName )
					]);
					$authors_to_assign[] = $this->coauthorsplus_logic->get_guest_author_by_id( $cap_id );
				}

			} // authors

			if( 0 == count( $authors_to_assign ) ) {
				WP_CLI::error( 'No authors to assign post_id = ' . $post_id );
			}
			
			// assign to post
			$this->coauthorsplus_logic->assign_authors_to_post( $authors_to_assign, $post_id );
		
		} // foreach

		return $count;

	}

	private function set_new_url_from_old_url( $post_id, $old_url, $real_run = false ) {

		if( ! $real_run ) {
			WP_CLI::line( 'Real run is false.  No updates.' );
			return false;
		}

		global $wpdb;
		
		$warning_allowed_type = 'Sorry, you are not allowed to upload this file type.';
		// different code on staging/live: $warning_curl_ssl = 'cURL error 35: error:14094410:SSL routines:ssl3_read_bytes:sslv3 alert handshake failure';

		$attachments = new \NewspackCustomContentMigrator\Logic\Attachments();

		// check if existing media file exists
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT ID
			FROM wp_posts 
			WHERE post_type = 'attachment'
			AND post_title = %s
		", $old_url ) );

		// if not exists, get it
		if( null === $attachment_id ) {

			$attachment_id = $attachments->import_external_file( $old_url, $old_url );

			if( is_wp_error( $attachment_id ) ) {
				
				if( $attachment_id->get_error_message() == $warning_allowed_type ) {
					// WP_CLI::warning( $warning_allowed_type ); // already written to output
					return false;
				}
				
				// if( $attachment_id->get_error_message() == $warning_curl_ssl ) {
				// 	WP_CLI::warning( $warning_curl_ssl );
				// 	return false;
				// }

				WP_CLI::warning( 'WP error: ' . $attachment_id->get_error_message() );
				return false;

			}

		}

		// get new url
		if( ! ( $new_url = wp_get_attachment_url( $attachment_id ) ) ) {
			WP_CLI::error( 'Attachment url not found for attachment_id ' . $attachment_id );
		}

		// replace post content
		$wpdb->query( $wpdb->prepare( "
			UPDATE {$wpdb->posts} 
			SET post_content = REPLACE(post_content, %s, %s)
			WHERE ID = %d
		", $old_url, $new_url, $post_id ) );

		WP_CLI::line( 'Replaced post ' . $post_id . ' with new_url ' . $new_url );

		return true;

	}

	private function set_redirect( $url_from, $url_to, $batch ) {

		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . '): ' . $url_from,
			$url_from,
			$url_to
		);

	}

	private function set_staff_tags_to_content( $tags, $content ) {

		// clear out blank lines
		$content = preg_replace( '#<p><br></p>#', '', $content );
	
		$tag_str = '';
		foreach( $tags as $index => $tag ) {
			$tag_str .= '<p><!--newspack_tpr_tags_' . $index . '-->' . $tag . '<!--/newspack_tpr_tags_' . $index . '--></p>';
		}

		if( ! empty( $tag_str ) ) {
			$tag_str .= '<p><!--newspack_tpr_tags_spacer-->&nbsp;<!--/newspack_tpr_tags_spacer--></p>';
		}

		return $tag_str . $content;
			
	}

	/**
	 * Set Yoast primary categories from old content types
	 *
	 * @param int $posts_per_page
	 * @return int $count posts processed
	 */
	private function set_primary_categories( $posts_per_page ) {

		// get term ids for primary categories keyed by slug
		$categories = array_flip( get_categories( [
			'slug'   => [
				"article",
				"episode",
				"news",
				"page",
				"show",
				"staff",
			],
			'fields' => 'id=>slug',
		]));

		// select posts with old content type where primary category isn't set
		$query = new WP_Query ( [
			'posts_per_page' => $posts_per_page,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'category__in'  => array_values( $categories ),
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_tpr_article_type',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_yoast_wpseo_primary_category',
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$count = $query->post_count;

		foreach ($query->posts as $post_id ) {

			$content_type = get_post_meta( $post_id, 'newspack_tpr_article_type', true );

			$category_id = $categories[strtolower($content_type)] ?? null; 

			// this case should not happen
			if( null === $category_id ) {
				WP_CLI::error( 'Unknown old content type "' . $content_type . '", no category found.' );
			}

			update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_id );

		} // foreach

		return $count;

	}


	/**
	 * DynamoDB
	 * 
	 */

    /**
     * Unmarshal a value from a DynamoDB operation result into native PHP.
	 * @link https://github.com/aws/aws-sdk-php/blob/master/src/DynamoDb/Marshaler.php
     *
     * @param array $value		Value from a DynamoDB result.
     * @return mixed|WP_CLI::errror
     */
    private function unmarshalValue( $value ) {

		$type = key($value);
        $value = $value[$type];

		// DynamoDB data type descriptors:
		// S – String
		// N – Number
		// B – Binary
		// BOOL – Boolean
		// NULL – Null
		// M – Map
		// L – List
		// SS – String Set
		// NS – Number Set
		// BS – Binary Set

        switch ($type) {
            case 'S':
            case 'BOOL':
                return $value;
            case 'NULL':
                return null;
            case 'N':
                // Use type coercion to unmarshal numbers to int/float.
                return $value + 0;
            case 'M':
            case 'L':
                foreach ($value as $k => $v) {
                    $value[$k] = $this->unmarshalValue($v);
                }
                return $value;
            case 'B':
				if ( ! is_string( $value ) ) {
					$value = Psr7\Utils::streamFor( $value );
				}
				return (string) $value;
            case 'SS':
            case 'NS':
            case 'BS':
                foreach ($value as $k => $v) {
                    $value[$k] = $this->unmarshalValue([$type[0] => $v]);
                }
                return $value;
        }

        WP_CLI::die_on_line( "Unmarshal error",  $type );

    }


	/**
	 * Logging
	 * 
	 */

	 private function die_on_line( $notice, $message = '' ) {
		
		$message = ( is_object( $message ) || is_array( $message ) ) ? print_r( $message, true ) : $message;
		WP_CLI::error( 'File: ' . $this->json_file . ' Line number: ' . $this->json_line_number . ' Line: ' . $this->json_line . ' - ' . $notice . ' ' . $message );

	}

	private function load_from_csv( $csv_path, $column_count, $format ) {

		// set path to file
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		$output = array();

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			// csv data integrity
			if( $column_count != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			// put data into a lookup based on first column
			if( 'lookup_column_1' == $format ) {
				$output[$row[0]] = array_slice( $row, 1 ); 
			}
			else if( 'lookup_column_4' == $format ) {
				$output[$row[3]] = array_slice( $row, 0, 3 ); 
			}
			else if( 'max_datetime_3' == $format ) {
				if( empty( $output['max'] ) ) $output['max'] = 0;
				if( strtotime( $row[2] ) > $output['max'] ) $output['max'] = strtotime( $row[2] );
			}

		}

		// close
		fclose($handle);

		if( 0 == count( $output ) ) return null;

		return $output;

	}


	private function log_and_skip_empty( $key, $item, $log_key ) {

		if( empty( $item[$key] ) ) {
			$this->report[$log_key]--;
			$this->mylog( $log_key, $item );
			return true;
		}

		return false;
	}

	private function log_media_item_audio( $file_suffix, $post_id, $item, $include_json = false ) {

		if( empty( $item['uuid'] ) || strlen(trim($item['uuid'])) == 0) {
			WP_CLI::error( 'No uuid for audio file.' );
		}

		$arr = array(
			$post_id,
			$item['article_uuid'],
			$item['uuid'],
			'https://content-prod-ripr.thepublicsradio.org/' . $item['relativePath'],
			( ! empty( $item['caption'] ) ) ? $item['caption'] : '',
			( ! empty( $item['credit'] ) ) ? $item['credit'] : '',
		);

		if ( $include_json ) $arr[] = $this->json_line;

		// convert array into CSV string
		$f_memory = fopen( 'php://memory', 'r+' );
		fputcsv( $f_memory, $arr );
		rewind( $f_memory );
	
		$this->mylog( 'media-' . $file_suffix, trim( stream_get_contents( $f_memory ) ) );

		fclose( $f_memory );

	}

	private function log_media_item( $file_suffix, $post_id, $item, $include_json = false ) {

		$arr = array(
			$post_id,
			$item['article_uuid'],
			'https://content-prod-ripr.thepublicsradio.org/' . $item['relativePath'],
			( ! empty( $item['caption'] ) ) ? $item['caption'] : '',
			( ! empty( $item['credit'] ) ) ? $item['credit'] : '',
		);

		if ( $include_json ) $arr[] = $this->json_line;

		// convert array into CSV string
		$f_memory = fopen( 'php://memory', 'r+' );
		fputcsv( $f_memory, $arr );
		rewind( $f_memory );
	
		$this->mylog( 'media-' . $file_suffix, trim( stream_get_contents( $f_memory ) ) );

		fclose( $f_memory );

	}

	private function log_to_csv( $slug, $data ) {

		$out_file = self::OUTPUT_FOLDER . '/' . $slug . '.csv';

		// clear on each run
		if( empty( $this->out_files[$out_file] ) ) {
			file_put_contents( $out_file, '' );
			$this->out_files[$out_file] = true;
		}
		
		$file = fopen( $out_file, 'a' );
		
		foreach ($data as $row) {
			fputcsv($file, $row);
		}

		fclose($file);

	}

	private function log_to_txt( $slug, $data ) {

		$out_file = self::OUTPUT_FOLDER . '/' . $slug . '.txt';

		// clear on each run
		if( empty( $this->out_files[$out_file] ) ) {
			file_put_contents( $out_file, '' );
			$this->out_files[$out_file] = true;
		}
		
		$file = fopen( $out_file, 'a' );
		
		foreach ($data as $row) {
			fputs($file, $row);
		}

		fclose($file);

	}

	private function mylog( $slug, $message, $level = null ) {

		$out_file = self::OUTPUT_FOLDER . '/' . $slug . '.log';

		// clear on each run
		if( empty( $this->out_files[$out_file] ) ) {
			file_put_contents( $out_file, '' );
			$this->out_files[$out_file] = true;
		}
		
		$message = ( is_object( $message ) || is_array( $message ) ) ? print_r( $message, true ) : $message;

		$this->logger->log( $out_file, $message, $level );		

	}

	private function reporting() {

		// -- sort and put in separate file
		$exports = array( 'article_categories', 'article_tags', 'article_routes', 'topic_routes' );
		foreach( $exports as $key ) {
			if( empty( $this->report[$key] ) ) continue;
			arsort( $this->report[$key] );
			$this->mylog( $key, $this->report[$key] );
			unset( $this->report[$key] );
		}

		// -- sort
		if( ! empty( $this->report['media'] ) ) {
		foreach( $this->report['media'] as $tag => $values ) {
			arsort( $this->report['media'][$tag] );
			}
		}
		
		// -- log
		$this->mylog( 'report', $this->report );

	}

	private function set_previous_import_lookups(){

		// set previous unique ids for data refreshes and launch
		$unique_uuids_path = self::JSON_BACKUP_FOLDER . '/unique_uuids.log';

		// skip if file doesn't exists (first import will not have a file)
		if( ! is_file( $unique_uuids_path ) ) return;
		$csv_columns = 6;

		// uuids
		$this->previous_unique_uuids = $this->load_from_csv( $unique_uuids_path, $csv_columns, 'lookup_column_1' );

		// track to watch for deleted on live (will not be processed again)
		foreach( $this->previous_unique_uuids as $k => $v ) {
			$this->previous_unique_uuids_processed_again[$k] = 0;
		}

		// urls
		$this->previous_not_unique_urls = $this->load_from_csv( $unique_uuids_path, $csv_columns, 'lookup_column_4' );
		
		// datetime comparison
		$max_array = $this->load_from_csv( $unique_uuids_path, $csv_columns, 'max_datetime_3' );
		if( isset( $max_array['max'] ) ) $this->previous_max_time = $max_array['max'];

	}
	
	private function track_array_in_report( $arr, $report_key ) {

		array_walk( $arr, function( $v ) use( $report_key ) {
			if( empty( $this->report[$report_key][$v] ) ) $this->report[$report_key][$v] = 1;
			else $this->report[$report_key][$v]++;
		});
	
	}

	private function track_article_routes( $item, $slug, $date_str ) {
		
		$type_slug_key = $item['articleType'] . '--' . $slug;

		if( empty( $this->report['article_routes'][$type_slug_key] ) ) {
			// setup a key
			$this->report['article_routes'][$type_slug_key] = array(
				'count' => 0,
				'uuids' => array(),
				'latest_date' => null,
				'latest_uuid' => null,
			);
		}
		// increment
		$this->report['article_routes'][$type_slug_key]['count']++;

		// check for duplicate uuids
		if( isset( $this->report['article_routes'][$type_slug_key]['uuids'][$item['uuid']] ) ) {
			$this->die_on_line( 'Duplicate UUID within duplicate route.' );
		}

		// add uuid to routes list
		$this->report['article_routes'][$type_slug_key]['uuids'][$item['uuid']] = array(
			'date' => $date_str,
			'type' => $item['articleType'],
		);

		// set latest uuid by date
		if( null === $this->report['article_routes'][$type_slug_key]['latest_date'] ) {
			$this->report['article_routes'][$type_slug_key]['latest_date'] = $date_str;
			$this->report['article_routes'][$type_slug_key]['latest_uuid'] = $item['uuid'];
		}
		else if( strtotime( $date_str ) == strtotime( $this->report['article_routes'][$type_slug_key]['latest_date'] ) ) {
			$this->die_on_line( 'Duplicate route uuid dates', $item );
		}
		else if( strtotime( $date_str ) > strtotime( $this->report['article_routes'][$type_slug_key]['latest_date'] ) ) {
			$this->report['article_routes'][$type_slug_key]['latest_date'] = $date_str;
			$this->report['article_routes'][$type_slug_key]['latest_uuid'] = $item['uuid'];
		}

	}

	private function write_wxrs( $posts ) {
	
		$posts_start = 0;
		for( $i = 0; $i < count( $posts ); $i += $this->wxr_max_posts, $posts_start += $this->wxr_max_posts ) {
			$slice = array_slice( $posts, $i, $this->wxr_max_posts );
			$this->write_wxr( $slice, $posts_start, $posts_start + count( $slice ) - 1, count( $slice ) );
		}
	}

	private function write_wxr( $posts, $start, $end, $count ) {

		// set pattern for matching
		// '(?!data:image/.*?;base64)',
		// '(?!https?://)', // local site images
		$attachment_src_regex = '#<img.*src=[\'\"](https?://(content-prod-ripr.(ripr|thepublicsradio).org.*|thepublicsradio.org.*))[\'\"]#imU';

		$wxr_data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => self::OUTPUT_FOLDER . '/tpr-WXR-' . $start . '-' . $end . '-' . $count . '.xml',
			'posts'       => $posts,
			'attachment_src_regex' => $attachment_src_regex,
		];
		
		Newspack_WXR_Exporter::generate_export( $wxr_data );
		WP_CLI::success( 'Posts exported to file: ' . $wxr_data['export_file'] );
		
	}


	/**
	 * Data 
	 */

	 private function data_redirects() {

		// copied from local CSV-redircts: 2023-12-05 ( previous list below )
		// Launch: it was discovered that the CSV-redirects.csv needed to have old redirects removed.  
		// This was done using Excel and VS Code locally looking at published/created dates.  
		// All previous Old site (migration) Redirects will be deleted and the updated CSV was added to the code here
		// Also, preemptively remove leading https://thepublicsradio.org/ too
		// -- and adding "/" for absolute urls

		return [
			'Count Me In' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'daily catch' => '/page/the-daily-catch-newsletter',
			'view points' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'weekly catch' => '/show/the-weekly-catch',
			'20' => 'https://www.eventbrite.com/e/rhode-island-in-the-roaring-20s-tickets-117694303831?aff=ebdssbonlinesearch',
			'911' => '/article/rhode-island-s-emergency-911-system-share-your-story',
			'app' => '/page/download-our-apps',
			'apps' => '/page/download-our-apps',
			'art2020' => '/episode/artscape-gallery-2020-send-us-your-art-that-captures-the-year',
			'arts-forum-2022' => 'https://fringepvd.org/mayoral-forum.html',
			'arts' => '/show/artscape',
			'bubbler' => '/article/the-bubbler-article',
			'bureaumatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCDON&PAGETYPE=PLG&CHECK=LBd99hFHoeKCpDRh%2bWheQRiCxtaFReuS',
			'business' => '/page/corporate-sponsorship',
			'cab' => '/page/community-advisory-board',
			'CAB' => '/page/community-advisory-board',
			'car' => '/page/donate-your-car',
			'careers' => '/page/career-opportunities',
			'Careers' => '/page/career-opportunities',
			'CAREERS' => '/page/career-opportunities',
			'cars' => '/page/donate-your-car',
			'chasing-the-fix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix-2/',
			'chasingthefix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix-2/',
			'cicilline' => '/episode/on-political-roundtable-election-2022-u-s-rep-david-cicilline-on-the-midterm-election-common-cause-s-john-marion-on-good-government-heroux-s-upset-of-sheriff-hodgson',
			'commons' => '/page/the-publics-radio-launches-the-commons',
			'Commons' => '/page/the-publics-radio-launches-the-commons',
			'COMMONS' => '/page/the-publics-radio-launches-the-commons',
			'contact-directions' => '/page/contact-and-directions',
			'contact' => '/page/contact-and-directions',
			'coronavirus-coverage' => 'https://explore.thepublicsradio.org/coronavirus-coverage/',
			'count-me-in' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'countmein' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'CountMeIn' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'COUNTMEIN' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'covid' => '/article/the-publics-radio-covid-passages-project',
			'COVID' => '/article/the-publics-radio-covid-passages-project',
			'cranston' => 'https://explore.thepublicsradio.org/2020-elections/',
			'ctf' => 'https://explore.thepublicsradio.org/series/chasing-the-fix-2/',
			'daily%20catch' => '/page/the-daily-catch-newsletter',
			'dailycatch' => '/page/the-daily-catch-newsletter',
			'DailyCatch' => '/page/the-daily-catch-newsletter',
			'dailyeditor' => 'https://content-prod-ripr.thepublicsradio.org/articles/careers/dailyeditorjobdescription.docx.pdf',
			'donate' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'dragon' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RILEYPAGE&PAGETYPE=PLG&CHECK=skvhHoEhyc2m2oVcVhuk7a1gzMC6uhq5nDjkJobrCdg%3d',
			'Dragon' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RILEYPAGE&PAGETYPE=PLG&CHECK=skvhHoEhyc2m2oVcVhuk7a1gzMC6uhq5nDjkJobrCdg%3d',
			'DRAGON' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RILEYPAGE&PAGETYPE=PLG&CHECK=skvhHoEhyc2m2oVcVhuk7a1gzMC6uhq5nDjkJobrCdg%3d',
			'election' => 'https://explore.thepublicsradio.org/2022-elections/',
			'ELECTION' => 'https://explore.thepublicsradio.org/2022-elections/',
			'elections' => 'https://explore.thepublicsradio.org/2022-elections/',
			'ELECTIONS' => 'https://explore.thepublicsradio.org/2022-elections/',
			'endofyear' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'endofyearmatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'engineer' => '/show/engineers-corner',
			'Engineer' => '/show/engineers-corner',
			'engineerscorner' => '/show/engineers-corner',
			'entertowin' => '/page/win-an-ipad-',
			'eoy-2022-add-gift' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'eoy-2022-lapsed-giving-page' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'event' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'Event' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'events' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'Events' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'facebook' => 'https://www.facebook.com/ThePublicsRadio/',
			'fall' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SOCIAL&PAGETYPE=PLG&CHECK=RqsdFBkGPzCooETOZZic2hiCxtaFReuS',
			'farmandcoastneighbors' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=PEER&PAGETYPE=PLG&CHECK=lKzZn5ser%2bSqK20krF35cqUOstgWaB20',
			'financials' => '/page/the-publics-radio-financials',
			'fish' => '/article/safety-experts-say-new-bedford-orders-on-fish-houses-and-other-industrial-facilities-could-set-national-standard-on-covid-19',
			'fix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix-2/',
			'gala' => '/page/springgala-2019',
			'gallery' => 'https://explore.thepublicsradio.org/2020-gallery/welcome/',
			'giftcenter' => '/page/gift-services-center',
			'GiftCenter' => '/page/gift-services-center',
			'GIFTCENTER' => '/page/gift-services-center',
			'give-now' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'give' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'Give' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'GIVE' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'hamilton' => '/page/hamilton-is-returning-to-ppac-',
			'Hamilton' => '/page/hamilton-is-returning-to-ppac-',
			'HAMILTON' => '/page/hamilton-is-returning-to-ppac-',
			'hd2' => '/page/bbc-on-89-3-hd2',
			'howtohelp' => '/article/community-needs-how-you-can-help',
			'ira' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=IRADONOPG&PAGETYPE=PLG&CHECK=6WPoUSQVn4WS8j9g5YCxLq1gzMC6uhq5nDjkJobrCdg%3d',
			'Ira' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=IRADONOPG&PAGETYPE=PLG&CHECK=6WPoUSQVn4WS8j9g5YCxLq1gzMC6uhq5nDjkJobrCdg%3d',
			'IRA' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=IRADONOPG&PAGETYPE=PLG&CHECK=6WPoUSQVn4WS8j9g5YCxLq1gzMC6uhq5nDjkJobrCdg%3d',
			'isbell' => '/page/you-could-be-one-of-five-lucky-winners-',
			'lara' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SALAMANOHP&PAGETYPE=PLG&CHECK=8GZXfVZ5zd4q9KU9vzAOn71YhDw50SikSh2nq0qouhg%3d',
			'Lara' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SALAMANOHP&PAGETYPE=PLG&CHECK=8GZXfVZ5zd4q9KU9vzAOn71YhDw50SikSh2nq0qouhg%3d',
			'LARA' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SALAMANOHP&PAGETYPE=PLG&CHECK=8GZXfVZ5zd4q9KU9vzAOn71YhDw50SikSh2nq0qouhg%3d',
			'legacy' => '/page/legacy-giving',
			'limbo' => '/article/living-in-limbo-share-your-stories-of-foster-care',
			'linkedin' => 'https://www.linkedin.com/company/rhode-island-public-radio/',
			'member-match' => 'https://thepublicsradio.wedid.it/campaigns/8177',
			'membermatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'menoresydesprotegidos' => 'https://explore.thepublicsradio.org/series/menores-de-edad-y-desprotegidos/',
			'MenoresyDesprotegidos' => 'https://explore.thepublicsradio.org/series/menores-de-edad-y-desprotegidos/',
			'merger' => '/article/exciting-news-to-share',
			'metro' => 'https://explore.thepublicsradio.org/metro-desk/',
			'metroreporter' => 'https://content-prod-ripr.thepublicsradio.org/articles/careers/metroreporter.pdf',
			'mosaic' => '/show/mosaic',
			'Mosaic' => '/show/mosaic',
			'MOSAIC' => '/show/mosaic',
			'newport' => 'https://explore.thepublicsradio.org/newport-bureau/',
			'nursing-home' => '/news/she-fought-to-keep-covid-19-out-of-her-nursing-home-then-she-got-sick',
			'pawtucket' => '/show/one-square-mile',
			'paymypledge' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=PAYPLG&PAGETYPE=PLG&CHECK=qTnKM8maGmdirnhqggI3XhiCxtaFReuS',
			'polarization' => 'https://explore.thepublicsradio.org/2020-elections/polarization/',
			'possibly' => '/show/possibly-podcast',
			'potholes' => '/article/the-bubbler-potholes',
			'pressed' => '/show/press-ed',
			'property' => 'https://ripr.careasy.org/real-estate-donation',
			'protest' => '/news/a-night-of-flames-and-fury-',
			'protests' => '/news/a-night-of-flames-and-fury-',
			'prt' => '/show/political-roundtable',
			'PRT' => '/show/political-roundtable',
			'reconect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'Reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'RECONNECT' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'renew' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RLRESP&PAGETYPE=PLG&CHECK=AzNorS7Yiaf06Kpe9rmcYxiCxtaFReuS',
			'resources' => '/article/covid-19-community-resources',
			'restart' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSRECAP&PAGETYPE=PLG&CHECK=iCM4CdGNIxgeXopzOp0%2fGm3L5BYddGq6PVAl6UEf65g%3d',
			'Restart' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSRECAP&PAGETYPE=PLG&CHECK=iCM4CdGNIxgeXopzOp0%2fGm3L5BYddGq6PVAl6UEf65g%3d',
			'ReStart' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSRECAP&PAGETYPE=PLG&CHECK=iCM4CdGNIxgeXopzOp0%2fGm3L5BYddGq6PVAl6UEf65g%3d',
			'RESTART' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSRECAP&PAGETYPE=PLG&CHECK=iCM4CdGNIxgeXopzOp0%2fGm3L5BYddGq6PVAl6UEf65g%3d',
			'retreat' => 'https://explore.thepublicsradio.org/retreat/',
			'schedule' => '/schedulechange',
			'sedaris' => '/page/an-evening-with-david-sedaris',
			'shorelineaccess' => 'https://explore.thepublicsradio.org/shoreline-access/',
			'signal' => '/page/signal-coverage',
			'signal%20map' => '/page/signal-coverage',
			'signalmap' => '/page/signal-coverage',
			'smart' => '/page/smart-speakers',
			'smarty' => '/page/smart-speakers',
			'social' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SOCIAL&PAGETYPE=PLG&CHECK=RqsdFBkGPzCooETOZZic2hiCxtaFReuS',
			'south-coast' => 'https://explore.thepublicsradio.org/south-coast-bureau/',
			'south-county' => 'https://explore.thepublicsradio.org/south-county-bureau/',
			'southcoast' => 'https://explore.thepublicsradio.org/south-coast-bureau/',
			'southcounty' => 'https://explore.thepublicsradio.org/south-county-bureau/',
			'spotlight' => '/article/community-spotlight-on-food-insecurity',
			'spring-gala-2019' => '/page/springgala-2019',
			'story' => 'https://explore.thepublicsradio.org/story/',
			'STORY' => 'https://explore.thepublicsradio.org/story/',
			'sustain' => '/page/sustaining-membership',
			'sustainer-recap' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSRECAP&PAGETYPE=PLG&CHECK=iCM4CdGNIxgeXopzOp0%2fGm3L5BYddGq6PVAl6UEf65g%3d',
			'sustainer' => '/page/sustaining-membership',
			'sustainermatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESPSUS&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXBqL01nh4NeI61gzMC6uhq5nDjkJobrCdg%3d',
			'SustainerMatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESPSUS&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXBqL01nh4NeI61gzMC6uhq5nDjkJobrCdg%3d',
			'SUSTAINERMATCH' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESPSUS&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXBqL01nh4NeI61gzMC6uhq5nDjkJobrCdg%3d',
			'sustainers' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RLSUS&PAGETYPE=PLG&CHECK=m%2f77v4TX2yDiQl%2byqVkEd4HJipnY8PNT',
			'sustaining-member-match' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESPSUS&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXBqL01nh4NeI61gzMC6uhq5nDjkJobrCdg%3d',
			'svc' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCAMP&PAGETYPE=PLG&CHECK=8%2b4yUkL%2fyK%2f06Kpe9rmcYxiCxtaFReuS',
			'svc' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCAMP&PAGETYPE=PLG&CHECK=8%2b4yUkL%2fyK%2f06Kpe9rmcYxiCxtaFReuS',
			'swag' => 'https://drive.google.com/file/d/1BR3TrMYoBpH-52gvINyjRUkr_gKBe7uD/view?usp=sharing',
			'sweeps' => '/page/this-american-life-retro-pack-prize',
			'tech' => '/page/real-time-tech-updates',
			'thebubbler' => '/article/the-bubbler-potholes',
			'thecommons' => '/page/the-publics-radio-launches-the-commons',
			'TheCommons' => '/page/the-publics-radio-launches-the-commons',
			'thedailycatch' => '/page/the-daily-catch-newsletter',
			'TheDailyCatch' => '/page/the-daily-catch-newsletter',
			'theweeklycatch' => '/show/the-weekly-catch',
			'twenty' => 'https://www.eventbrite.com/e/rhode-island-in-the-roaring-20s-tickets-117694303831?aff=ebdssbonlinesearch',
			'twitter' => 'https://twitter.com/ThePublicsRadio',
			'underage' => 'https://explore.thepublicsradio.org/series/underage-and-unprotected/',
			'underageandunprotected' => 'https://explore.thepublicsradio.org/series/underage-and-unprotected/',
			'UnderageAndUnprotected' => 'https://explore.thepublicsradio.org/series/underage-and-unprotected/',
			'underagedandprotected' => 'https://explore.thepublicsradio.org/series/underage-and-unprotected/',
			'underwrite' => '/page/corporate-sponsorship',
			'underwriting' => '/page/corporate-sponsorship',
			'update' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSTUPD&PAGETYPE=PLG&CHECK=VDBfMHiGnW5iciwbJbwSOOzWDeZ%2beA1M',
			'uwpay' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=UWPAYT&PAGETYPE=PLG&CHECK=I4Y3ZEOVg80eZA%2bHIoc7SxiCxtaFReuS',
			'viewpoint' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'VIEWPOINT' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'viewpoints' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'VIEWPOINTS' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'waves' => 'https://hopin.com/events/wavs-podcast-festival',
			'WAVES' => 'https://hopin.com/events/wavs-podcast-festival',
			'waves' => 'https://hopin.com/events/wavs-podcast-festival',
			'wavs' => 'https://hopin.com/events/wavs-podcast-festival',
			'WAVS' => 'https://hopin.com/events/wavs-podcast-festival',
			'weekly-catch' => '/show/the-weekly-catch',
			'weekly' => '/show/the-weekly-catch',
			'weeklycatch' => '/show/the-weekly-catch',
			'WeeklyCatch' => '/show/the-weekly-catch',
			'wind' => '/article/the-bubbler-we-want-your-offshore-wind-questions',
			'Wind' => '/article/the-bubbler-we-want-your-offshore-wind-questions',
			'WIND' => '/article/the-bubbler-we-want-your-offshore-wind-questions',
			'year-end' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'Year-End' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'YEAR-END' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'yearend' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'YearEnd' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'YEAREND' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2023&PAGETYPE=PLG&CHECK=ZFGADtr2Cw30uA2BjXUkUq1gzMC6uhq5nDjkJobrCdg%3d',
			'youtube' => 'https://www.youtube.com/user/RIPublicRadio/',
		];

		// initial - copied from local WXR output: logs-2023-07-07-import/CSV-redirects.csv
		return [
			'/story' => 'https://explore.thepublicsradio.org/story/',
			'/STORY' => 'https://explore.thepublicsradio.org/story/',
			'/south-coast' => 'https://explore.thepublicsradio.org/south-coast-bureau/',
			'/southcoast' => 'https://explore.thepublicsradio.org/south-coast-bureau/',
			'/event' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'/events' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'/Event' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'/Events' => 'https://newportartmuseum.org/events/searching-for-peace-at-home/',
			'/metro' => 'https://explore.thepublicsradio.org/metro-desk/',
			'/renew' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RLRESP&PAGETYPE=PLG&CHECK=AzNorS7Yiaf06Kpe9rmcYxiCxtaFReuS',
			'/arts-forum-2022' => 'https://fringepvd.org/mayoral-forum.html',
			'/endofyearmatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'/cab' => 'page/community-advisory-board',
			'/CAB' => 'page/community-advisory-board',
			'/newport' => 'https://explore.thepublicsradio.org/newport-bureau/',
			'/limbo' => 'https://thepublicsradio.org/article/living-in-limbo-share-your-stories-of-foster-care',
			'/sustain' => 'https://thepublicsradio.org/page/sustaining-membership',
			'/contact-directions' => '/page/contact-and-directions',
			'/prt' => 'show/political-roundtable',
			'/PRT' => 'show/political-roundtable',
			'/sedaris' => 'https://thepublicsradio.org/page/an-evening-with-david-sedaris',
			'/spotlight' => 'article/community-spotlight-on-food-insecurity',
			'/giftcenter' => 'page/gift-services-center',
			'/GIFTCENTER' => 'page/gift-services-center',
			'/GiftCenter' => 'page/gift-services-center',
			'/twenty' => 'https://www.eventbrite.com/e/rhode-island-in-the-roaring-20s-tickets-117694303831?aff=ebdssbonlinesearch',
			'/20' => 'https://www.eventbrite.com/e/rhode-island-in-the-roaring-20s-tickets-117694303831?aff=ebdssbonlinesearch',
			'/facebook' => 'https://www.facebook.com/ThePublicsRadio/',
			'/legacy' => 'https://thepublicsradio.org/page/legacy-giving',
			'/svc' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCAMP&PAGETYPE=PLG&CHECK=8%2b4yUkL%2fyK%2f06Kpe9rmcYxiCxtaFReuS',
			'/cars' => 'https://thepublicsradio.org/page/donate-your-car',
			'/mosaic' => 'https://explore.thepublicsradio.org/mosaic-podcast/',
			'/MOSAIC' => 'https://explore.thepublicsradio.org/mosaic-podcast/',
			'/Mosaic' => 'https://explore.thepublicsradio.org/mosaic-podcast/',
			'/uwpay' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=UWPAYT&PAGETYPE=PLG&CHECK=I4Y3ZEOVg80eZA%2bHIoc7SxiCxtaFReuS',
			'/chasing-the-fix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix/',
			'/chasingthefix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix/',
			'/ctf' => 'https://explore.thepublicsradio.org/series/chasing-the-fix/',
			'/fix' => 'https://explore.thepublicsradio.org/series/chasing-the-fix/',
			'/member-match' => 'https://thepublicsradio.wedid.it/campaigns/8177',
			'/membermatch' => 'https://thepublicsradio.wedid.it/campaigns/8177',
			'/paymypledge' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=PAYPLG&PAGETYPE=PLG&CHECK=qTnKM8maGmdirnhqggI3XhiCxtaFReuS',
			'/fish' => 'https://thepublicsradio.org/article/safety-experts-say-new-bedford-orders-on-fish-houses-and-other-industrial-facilities-could-set-national-standard-on-covid-19',
			'/south-county' => 'https://explore.thepublicsradio.org/south-county-bureau/',
			'/southcounty' => 'https://explore.thepublicsradio.org/south-county-bureau/',
			'/social' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SOCIAL&PAGETYPE=PLG&CHECK=RqsdFBkGPzCooETOZZic2hiCxtaFReuS',
			'/careers' => 'https://thepublicsradio.org/page/career-opportunities',
			'/Careers' => 'https://thepublicsradio.org/page/career-opportunities',
			'/CAREERS' => 'https://thepublicsradio.org/page/career-opportunities',
			'/farmandcoastneighbors' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=PEER&PAGETYPE=PLG&CHECK=lKzZn5ser%2bSqK20krF35cqUOstgWaB20',
			'/gallery' => 'https://explore.thepublicsradio.org/2020-gallery/welcome/',
			'/underwriting' => 'https://thepublicsradio.org/page/corporate-sponsorship',
			'/coronavirus-coverage' => 'https://explore.thepublicsradio.org/coronavirus-coverage/',
			'/hamilton' => '/page/hamilton-is-returning-to-ppac-',
			'/HAMILTON' => '/page/hamilton-is-returning-to-ppac-',
			'/Hamilton' => '/page/hamilton-is-returning-to-ppac-',
			'/membermatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'/engineer' => '/show/engineers-corner',
			'/engineerscorner' => '/show/engineers-corner',
			'/Engineer' => '/show/engineers-corner',
			'/sustainer' => 'https://thepublicsradio.org/page/sustaining-membership',
			'/fall' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SOCIAL&PAGETYPE=PLG&CHECK=RqsdFBkGPzCooETOZZic2hiCxtaFReuS',
			'/eoy-2022-add-gift' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/year-end' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/YEAR-END' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/Year-End' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/yearend' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/YEAREND' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/Yearend' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/YearEnd' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/endofyear' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=EOYAG2022&PAGETYPE=PLG&CHECK=ZFGADtr2Cw2tVPZKISJlsa1gzMC6uhq5nDjkJobrCdg%3d',
			'/art2020' => 'episode/artscape-gallery-2020-send-us-your-art-that-captures-the-year',
			'/howtohelp' => 'https://thepublicsradio.org/article/community-needs-how-you-can-help',
			'/swag' => 'https://content-prod-ripr.thepublicsradio.org/articles/7e2e2902-6fb5-4c70-92dc-44a49b9263ef/thepublicsradiofallpremiumsonesheetforweb.pdf',
			'/underwrite' => 'https://thepublicsradio.org/page/corporate-sponsorship',
			'/apps' => 'https://thepublicsradio.org/page/download-our-apps',
			'/potholes' => 'https://thepublicsradio.org/article/the-bubbler-potholes',
			'/gala' => 'https://thepublicsradio.org/page/springgala-2019',
			'/spring-gala-2019' => 'https://thepublicsradio.org/page/springgala-2019',
			'/update' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SUSTUPD&PAGETYPE=PLG&CHECK=VDBfMHiGnW5iciwbJbwSOOzWDeZ%2beA1M',
			'/sweeps' => 'https://thepublicsradio.org/page/this-american-life-retro-pack-prize',
			'/pawtucket' => 'show/one-square-mile',
			'/commons' => '/page/the-publics-radio-launches-the-commons',
			'/thecommons' => '/page/the-publics-radio-launches-the-commons',
			'/Commons' => '/page/the-publics-radio-launches-the-commons',
			'/COMMONS' => '/page/the-publics-radio-launches-the-commons',
			'/TheCommons' => '/page/the-publics-radio-launches-the-commons',
			'/schedule' => 'https://thepublicsradio.org/schedulechange',
			'/wavs' => 'https://hopin.com/events/wavs-podcast-festival',
			'/waves' => 'https://hopin.com/events/wavs-podcast-festival',
			'/WAVS' => 'https://hopin.com/events/wavs-podcast-festival',
			'/WAVES' => 'https://hopin.com/events/wavs-podcast-festival',
			'/dailyeditor' => 'https://content-prod-ripr.thepublicsradio.org/articles/careers/dailyeditorjobdescription.docx.pdf',
			'/waves' => 'https://hopin.com/events/wavs-podcast-festival',
			'/eoy-2022-lapsed-giving-page' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/RECONNECT' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/Reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/reconect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/smart' => 'https://thepublicsradio.org/page/smart-speakers',
			'/donate' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'/give-now' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'/polarization' => 'https://explore.thepublicsradio.org/2020-elections/polarization/',
			'/mosaic' => 'show/mosaic',
			'/Mosaic' => 'show/mosaic',
			'/MOSAIC' => 'show/mosaic',
			'/cicilline' => 'https://thepublicsradio.org/episode/on-political-roundtable-election-2022-u-s-rep-david-cicilline-on-the-midterm-election-common-cause-s-john-marion-on-good-government-heroux-s-upset-of-sheriff-hodgson',
			'/possibly' => '/show/possibly-podcast',
			'/elections' => 'https://explore.thepublicsradio.org/2020-elections/',
			'/covid' => 'https://thepublicsradio.org/article/the-publics-radio-covid-passages-project',
			'/COVID' => 'https://thepublicsradio.org/article/the-publics-radio-covid-passages-project',
			'/dailycatch' => 'page/the-daily-catch-newsletter',
			'/DailyCatch' => 'page/the-daily-catch-newsletter',
			'/"daily catch"' => 'page/the-daily-catch-newsletter',
			'/daily%20catch' => 'page/the-daily-catch-newsletter',
			'/thedailycatch' => 'page/the-daily-catch-newsletter',
			'/TheDailyCatch' => 'page/the-daily-catch-newsletter',
			'/thebubbler' => 'https://thepublicsradio.org/article/the-bubbler-',
			'/car' => 'https://thepublicsradio.org/page/donate-your-car',
			'/isbell' => 'https://thepublicsradio.org/page/you-could-be-one-of-five-lucky-winners-',
			'/shorelineaccess' => 'https://explore.thepublicsradio.org/shoreline-access/',
			'/youtube' => 'https://www.youtube.com/user/RIPublicRadio/',
			'/elections' => 'https://explore.thepublicsradio.org/2022-elections/',
			'/ELECTIONS' => 'https://explore.thepublicsradio.org/2022-elections/',
			'/election' => 'https://explore.thepublicsradio.org/2022-elections/',
			'/ELECTION' => 'https://explore.thepublicsradio.org/2022-elections/',
			'/linkedin' => 'https://www.linkedin.com/company/rhode-island-public-radio/',
			'/sustainers' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=RLSUS&PAGETYPE=PLG&CHECK=m%2f77v4TX2yDiQl%2byqVkEd4HJipnY8PNT',
			'/nursing-home' => 'https://thepublicsradio.org/news/she-fought-to-keep-covid-19-out-of-her-nursing-home-then-she-got-sick',
			'/signal' => '/page/signal-coverage',
			'/hd2' => 'page/bbc-on-89-3-hd2',
			'/viewpoint' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'/viewpoints' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'/VIEWPOINT' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'/VIEWPOINTS' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'/"view points"' => 'https://explore.thepublicsradio.org/2022-elections/2022-elections-polarization/',
			'/911' => 'https://thepublicsradio.org/article/rhode-island-s-emergency-911-system-share-your-story',
			'/smarty' => 'https://thepublicsradio.org/page/smart-speakers',
			'/bureaumatch' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCDON&PAGETYPE=PLG&CHECK=LBd99hFHoeKCpDRh%2bWheQRiCxtaFReuS',
			'/tech' => 'https://thepublicsradio.org/page/real-time-tech-updates',
			'/signalmap' => 'https://thepublicsradio.org/page/signal-coverage',
			'/signal%20map' => 'https://thepublicsradio.org/page/signal-coverage',
			'/app' => 'https://thepublicsradio.org/page/download-our-apps',
			'/cranston' => 'https://explore.thepublicsradio.org/2020-elections/',
			'/pressed' => 'https://thepublicsradio.org/show/press-ed',
			'/svc' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=SVCAMP&PAGETYPE=PLG&CHECK=8%2b4yUkL%2fyK%2f06Kpe9rmcYxiCxtaFReuS',
			'/protest' => 'https://thepublicsradio.org/news/a-night-of-flames-and-fury-',
			'/wind' => 'article/the-bubbler-we-want-your-offshore-wind-questions',
			'/Wind' => 'article/the-bubbler-we-want-your-offshore-wind-questions',
			'/WIND' => 'article/the-bubbler-we-want-your-offshore-wind-questions',
			'/business' => '" https://thepublicsradio.org/page/corporate-sponsorship"',
			'/property' => 'https://ripr.careasy.org/real-estate-donation',
			'/retreat' => 'https://explore.thepublicsradio.org/retreat/',
			'/contact' => 'page/contact-and-directions',
			'/endofyear' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=AGRESP&PAGETYPE=PLG&CHECK=%2fkiY6AZOIXD06Kpe9rmcYxiCxtaFReuS',
			'/thebubbler' => '/article/the-bubbler-potholes',
			'/bubbler' => 'https://thepublicsradio.org/article/the-bubbler-article',
			'/metroreporter' => 'https://content-prod-ripr.thepublicsradio.org/articles/careers/metroreporter.pdf',
			'/financials' => 'https://thepublicsradio.org/page/the-publics-radio-financials',
			'/resources' => 'https://thepublicsradio.org/article/covid-19-community-resources',
			'/reconnect' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=LAPSRESP&PAGETYPE=PLG&CHECK=X5yvvWH%2fqew%2bIg2BmQh%2bQW3L5BYddGq6PVAl6UEf65g%3d',
			'/entertowin' => 'https://thepublicsradio.org/page/win-an-ipad-',
			'/give' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'/GIVE' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'/Give' => 'https://ripr.secureallegiance.com/ripr/WebModule/Donate.aspx?P=WEBGEN&PAGETYPE=PLG&CHECK=4w3q6WZdX6aCpDRh%2bWheQRiCxtaFReuS',
			'/protests' => 'https://thepublicsradio.org/news/a-night-of-flames-and-fury-',
			'/twitter' => 'https://twitter.com/ThePublicsRadio',
			'/arts' => 'show/artscape',
		];

	}

	private function data_wp_users(){
		
		// redacted due to Repo being public on gihub.
		// will need to add again LOCALLY if needed
		WP_CLI::error( "need to add to this file LOCALLY only" );

		// return [
			// see: local d: automattic-thepublicsradio-repo-clean-up-emails.txt
		// ];

	}

	private function data_domains() {
		
		// todo: "// no schema declared
		// todo: "/ on site assets
		// todo: \/ escaped urls

		return [
			// 'archive.ripr.org', // only 1 link found and it's a 404...
			'content-prod-ripr.ripr.org', // these all seem to be 404s...
			'content-prod-ripr.thepublicsradio.org',
			'explore.thepublicsradio.org',
			// 'mediad.publicbroadcasting.net', // off-site (NPR web service)
			'ripr.org', // this is a redirect, "short url", so grab files off it
			// 'riprapp.org', // 404..."App" download site ...
			// 'ripr.careasy.org', // off site donations
			// 'ripr-clock.herokuapp.com', // off-site timer
			// 'ripr-ice.streamguys1.com', // off-site streaming
			// 'ripr.secureallegiance.com', // off-site donation
			// 'riprsoundvision.com', // 404 ...
			// 'smart.ripr.org', // Amazon Echo...404...
			'thepublicsradio.org',
		];

	}

	private function data_original_thumb_ids() {
		return [
			'4832' => '14138',
			'8521' => '14139',
			'2269' => '14140',
			'9252' => '14141',
			'11531' => '14142',
			'489' => '14143',
			'3725' => '14144',
			'13361' => '14145',
			'5669' => '14146',
			'6299' => '14147',
			'8507' => '14148',
			'1339' => '14149',
			'6130' => '14150',
			'5268' => '14151',
			'908' => '14152',
			'7941' => '14153',
			'10066' => '14154',
			'12303' => '14155',
			'8813' => '14156',
			'4105' => '14157',
			'6668' => '14158',
			'2427' => '14159',
			'1135' => '14160',
			'3556' => '14161',
			'6298' => '14162',
			'7675' => '14163',
			'1507' => '14164',
			'9762' => '14165',
			'3992' => '14166',
			'14057' => '14167',
			'1504' => '14168',
			'10340' => '14169',
			'12622' => '14170',
			'13827' => '14171',
			'3442' => '14172',
			'5828' => '14173',
			'13609' => '14174',
			'3121' => '14175',
			'9982' => '14176',
			'9994' => '14177',
			'9539' => '14178',
			'1765' => '14179',
			'12909' => '14180',
			'4065' => '14181',
			'11891' => '14182',
			'12223' => '14183',
			'5877' => '14184',
			'4115' => '14185',
			'363' => '14186',
			'9579' => '14187',
			'9239' => '14188',
			'96' => '14189',
			'12619' => '14190',
			'8770' => '14191',
			'6959' => '14192',
			'6584' => '14193',
			'3272' => '14194',
			'11769' => '14195',
			'620' => '14196',
			'3816' => '14197',
			'4303' => '14198',
			'7179' => '14199',
			'3065' => '14200',
			'8243' => '14201',
			'7879' => '14202',
			'9269' => '14203',
			'6705' => '14204',
			'1506' => '14205',
			'13846' => '14206',
			'3521' => '14207',
			'3047' => '14208',
			'11031' => '14209',
			'12020' => '14210',
			'8989' => '14211',
			'13261' => '14212',
			'11291' => '14213',
			'9935' => '14214',
			'5021' => '14215',
			'3' => '14216',
			'6008' => '14217',
			'2440' => '14218',
			'5630' => '14219',
			'5011' => '14220',
			'11339' => '14221',
			'847' => '14222',
			'5662' => '14223',
			'2303' => '14224',
			'8151' => '14225',
			'4888' => '14226',
			'3789' => '14227',
			'10784' => '14228',
			'5913' => '14229',
			'5020' => '14230',
			'8517' => '14231',
			'3670' => '14232',
			'2517' => '14233',
			'4030' => '14234',
			'10756' => '14235',
			'115' => '14236',
			'1217' => '14237',
			'4357' => '14239',
			'13407' => '14240',
			'9729' => '14241',
			'12528' => '14242',
			'4709' => '14243',
			'9992' => '14244',
			'6592' => '14245',
			'12781' => '14246',
			'3535' => '14247',
			'4222' => '14248',
			'1762' => '14249',
			'350' => '14250',
			'12036' => '14251',
			'647' => '14252',
			'10549' => '14253',
			'6186' => '14254',
			'3084' => '14255',
			'8778' => '14256',
			'4229' => '14257',
			'12895' => '14258',
			'4464' => '14259',
			'157' => '14260',
			'9206' => '14261',
			'6339' => '14262',
			'7998' => '14263',
			'9694' => '14264',
			'12360' => '14265',
			'5897' => '14266',
			'9255' => '14267',
			'11535' => '14268',
			'10693' => '14269',
			'13378' => '14270',
			'9247' => '14271',
			'10352' => '14272',
			'5501' => '14273',
			'9582' => '14274',
			'12312' => '14275',
			'2430' => '14276',
			'13179' => '14277',
			'12777' => '14278',
			'7611' => '14279',
			'8258' => '14280',
			'8476' => '14281',
			'9324' => '14282',
			'4020' => '14283',
			'6447' => '14284',
			'4814' => '14285',
			'175' => '14286',
			'3491' => '14287',
			'7960' => '14288',
			'12064' => '14289',
			'1446' => '14290',
			'7670' => '14291',
			'10472' => '14292',
			'11479' => '14293',
			'5405' => '14294',
			'12072' => '14295',
			'3028' => '14296',
			'10301' => '14297',
			'9001' => '14298',
			'3066' => '14299',
			'458' => '14300',
			'10801' => '14301',
			'2728' => '14302',
			'11066' => '14303',
			'6984' => '14304',
			'5424' => '14305',
			'2389' => '14306',
			'3680' => '14307',
			'987' => '14308',
			'7396' => '14309',
			'10875' => '14310',
			'11841' => '14311',
			'11328' => '14312',
			'6377' => '14313',
			'2798' => '14314',
			'8944' => '14315',
			'5611' => '14316',
			'1636' => '14317',
			'10800' => '14318',
			'6905' => '14319',
			'563' => '14320',
			'8247' => '14554',
			'7926' => '14322',
			'10204' => '14323',
			'9986' => '14324',
			'6415' => '14325',
			'6109' => '14326',
			'1941' => '14327',
			'8504' => '14328',
			'6254' => '14329',
			'10525' => '14330',
			'8764' => '14331',
			'7145' => '14332',
			'3710' => '14333',
			'10492' => '14334',
			'5852' => '14335',
			'5411' => '14336',
			'7856' => '14337',
			'880' => '14338',
			'10364' => '14339',
			'10831' => '14340',
			'4873' => '14341',
			'5388' => '14342',
			'12640' => '14343',
			'641' => '14344',
			'11779' => '14345',
			'10291' => '14346',
			'5880' => '14347',
			'9706' => '14348',
			'6012' => '14349',
			'3109' => '14350',
			'1935' => '14351',
			'5521' => '14352',
			'10284' => '14353',
			'13293' => '14354',
			'4290' => '14355',
			'12070' => '14356',
			'1377' => '14357',
			'9554' => '14358',
			'1856' => '14359',
			'12838' => '14360',
			'12778' => '14361',
			'76' => '14362',
			'10446' => '14363',
			'3381' => '14364',
			'1484' => '14365',
			'1914' => '14366',
			'6546' => '14367',
			'12638' => '14368',
			'1207' => '14369',
			'3376' => '14370',
			'6672' => '14371',
			'6589' => '14372',
			'2698' => '14373',
			'8624' => '14374',
			'12753' => '14375',
			'13664' => '14376',
			'3254' => '14377',
			'12406' => '14378',
			'12049' => '14379',
			'13900' => '14380',
			'4970' => '14381',
			'6828' => '14382',
			'6113' => '14383',
			'7439' => '14384',
			'8460' => '14385',
			'10785' => '14386',
			'11517' => '14387',
			'9025' => '14388',
			'8120' => '14389',
			'4774' => '14390',
			'1619' => '14391',
			'9990' => '14392',
			'2033' => '14393',
			'5067' => '14394',
			'13095' => '14395',
			'12529' => '14396',
			'4091' => '14397',
			'8468' => '14398',
			'6809' => '14399',
			'7057' => '14400',
			'2526' => '14401',
			'12311' => '14402',
			'14060' => '14403',
			'1175' => '14404',
			'7359' => '14405',
			'5359' => '14406',
			'2266' => '14407',
			'11288' => '14408',
			'2554' => '14409',
			'7827' => '14410',
			'7636' => '14411',
			'5615' => '14412',
			'12098' => '14413',
			'5740' => '14414',
			'9830' => '14415',
			'9584' => '14416',
			'1479' => '14417',
			'3472' => '14418',
			'13064' => '14419',
			'7155' => '14420',
			'6534' => '14421',
			'14044' => '14422',
			'11774' => '14423',
			'11243' => '14424',
			'8457' => '14425',
			'4889' => '14426',
			'10337' => '14427',
			'1634' => '14428',
			'7193' => '14429',
			'9723' => '14430',
			'11088' => '14431',
			'5337' => '14432',
			'11603' => '14433',
			'11855' => '14434',
			'4764' => '14435',
			'1755' => '14436',
			'4502' => '14437',
			'12023' => '14438',
			'9538' => '14439',
			'3080' => '14440',
			'1456' => '14441',
			'6365' => '14442',
			'9767' => '14443',
			'9250' => '14444',
			'8175' => '14445',
			'1526' => '14446',
			'10792' => '14447',
			'6263' => '14448',
			'6049' => '14449',
			'6942' => '14450',
			'5003' => '14451',
			'5860' => '14452',
			'168' => '14453',
			'11053' => '14454',
			'11966' => '14455',
			'1078' => '14456',
			'7963' => '14457',
			'1477' => '14458',
			'3679' => '14459',
			'13653' => '14460',
			'4089' => '14461',
			'1908' => '14462',
			'12043' => '14463',
			'4587' => '14464',
			'4076' => '14673',
			'1712' => '14466',
			'13620' => '14467',
			'8160' => '14468',
			'2223' => '14469',
			'18' => '14470',
			'2852' => '14471',
			'3976' => '14472',
			'2539' => '14473',
			'2185' => '14474',
			'9705' => '14475',
			'12001' => '14476',
			'8262' => '14477',
			'5534' => '14478',
			'4829' => '14479',
			'12520' => '14480',
			'6565' => '14481',
			'3779' => '14482',
			'2669' => '14483',
			'11490' => '14484',
			'9807' => '14485',
			'7391' => '14486',
			'9279' => '14487',
			'4293' => '14488',
			'11562' => '14489',
			'10685' => '14490',
			'972' => '14491',
			'561' => '14492',
			'6112' => '14493',
			'8783' => '14494',
			'10028' => '14495',
			'4331' => '14496',
			'11914' => '14497',
			'983' => '14498',
			'9792' => '14499',
			'5304' => '14500',
			'8225' => '14501',
			'1473' => '14502',
			'11274' => '14503',
			'389' => '14504',
			'8721' => '14505',
			'1592' => '14506',
			'12813' => '14507',
			'7924' => '14508',
			'866' => '14509',
			'7846' => '14510',
			'14101' => '14511',
			'12902' => '14512',
			'9004' => '14513',
			'11446' => '14514',
			'3776' => '14515',
			'8742' => '14516',
			'4397' => '14517',
			'1415' => '14518',
			'1606' => '14519',
			'667' => '14520',
			'10704' => '14521',
			'13274' => '14522',
			'13560' => '14523',
			'3528' => '14524',
			'1732' => '14525',
			'5130' => '14526',
			'5139' => '14527',
			'9028' => '14528',
			'7162' => '14529',
			'13364' => '14530',
			'9856' => '14531',
			'1699' => '14532',
			'4881' => '14533',
			'7331' => '14534',
			'13944' => '14535',
			'7458' => '14536',
			'9364' => '14537',
			'163' => '14538',
			'8473' => '14539',
			'107' => '14540',
			'3021' => '14541',
			'8543' => '14542',
			'13622' => '14543',
			'6059' => '14544',
			'9439' => '14545',
			'9874' => '14546',
			'1199' => '14547',
			'331' => '14548',
			'4047' => '14549',
			'882' => '14550',
			'10815' => '14551',
			'12623' => '14552',
			'6119' => '14553',
			'5341' => '14555',
			'5683' => '14556',
			'10869' => '14557',
			'4826' => '14558',
			'4244' => '14559',
			'13397' => '14560',
			'6931' => '14561',
			'6582' => '14562',
			'7462' => '14563',
			'11301' => '14564',
			'12326' => '14565',
			'3750' => '14566',
			'13176' => '14567',
			'4305' => '14568',
			'13195' => '14569',
			'14094' => '14570',
			'3379' => '14571',
			'1248' => '14572',
			'11878' => '14573',
			'11745' => '14574',
			'6149' => '14575',
			'5142' => '14576',
			'11614' => '14577',
			'11391' => '14578',
			'843' => '14579',
			'11315' => '14580',
			'6362' => '14581',
			'7377' => '14582',
			'7853' => '14583',
			'3743' => '14584',
			'4469' => '14585',
			'2413' => '14586',
			'5566' => '14587',
			'10282' => '14588',
			'8015' => '14589',
			'464' => '14590',
			'5617' => '14591',
			'12134' => '14592',
			'13959' => '15034',
			'13961' => '14594',
			'7125' => '14595',
			'10959' => '14596',
			'7230' => '14597',
			'8969' => '14598',
			'5138' => '14599',
			'5013' => '14600',
			'130' => '14601',
			'9459' => '14602',
			'13375' => '14603',
			'11831' => '14604',
			'334' => '14605',
			'3230' => '14606',
			'13079' => '14607',
			'11497' => '14608',
			'8429' => '14609',
			'4256' => '14610',
			'12919' => '14611',
			'13395' => '14612',
			'921' => '14613',
			'8385' => '14614',
			'493' => '14615',
			'3813' => '14616',
			'12266' => '14617',
			'13659' => '14618',
			'8471' => '14619',
			'6435' => '14620',
			'3698' => '14621',
			'13287' => '14622',
			'10810' => '14623',
			'7293' => '14624',
			'5152' => '14625',
			'8908' => '14626',
			'4800' => '14627',
			'1711' => '14628',
			'11345' => '14629',
			'2460' => '14630',
			'8537' => '14631',
			'13' => '14632',
			'7825' => '14633',
			'6414' => '14634',
			'7599' => '14635',
			'5768' => '14636',
			'377' => '14637',
			'726' => '14638',
			'9555' => '14639',
			'633' => '14640',
			'1495' => '14641',
			'4974' => '14642',
			'5006' => '14643',
			'9572' => '14644',
			'13908' => '14645',
			'8668' => '14646',
			'5680' => '14647',
			'1215' => '14648',
			'1779' => '14649',
			'4590' => '14650',
			'7178' => '14651',
			'9189' => '14652',
			'13083' => '14653',
			'8199' => '14654',
			'7948' => '14655',
			'4969' => '14656',
			'4377' => '14657',
			'14095' => '14658',
			'9215' => '14659',
			'3432' => '14660',
			'11014' => '14661',
			'13348' => '14662',
			'8279' => '14663',
			'7876' => '14664',
			'9732' => '14665',
			'8693' => '14666',
			'3246' => '14667',
			'13710' => '14668',
			'5777' => '14669',
			'2443' => '14670',
			'2193' => '14671',
			'56' => '14672',
			'13833' => '14674',
			'2243' => '14675',
			'10841' => '14676',
			'13131' => '14677',
			'11216' => '14678',
			'10120' => '14679',
			'1510' => '14680',
			'752' => '14681',
			'1083' => '14682',
			'4295' => '14683',
			'1955' => '14684',
			'3519' => '14685',
			'7171' => '14686',
			'8805' => '14687',
			'9521' => '14688',
			'4582' => '14689',
			'8535' => '14690',
			'3733' => '14691',
			'3271' => '14692',
			'2462' => '14693',
			'6709' => '14694',
			'13843' => '14695',
			'5518' => '14696',
			'8759' => '14697',
			'5313' => '14698',
			'9326' => '14699',
			'4535' => '14700',
			'5838' => '14701',
			'9749' => '14702',
			'11448' => '14703',
			'7071' => '14704',
			'614' => '14705',
			'9864' => '14706',
			'10546' => '14707',
			'3795' => '14708',
			'1395' => '14709',
			'9809' => '14710',
			'2227' => '14711',
			'11197' => '14712',
			'5408' => '14713',
			'73' => '14714',
			'2468' => '14715',
			'4806' => '14716',
			'12030' => '14717',
			'12133' => '14718',
			'10180' => '14719',
			'11381' => '14720',
			'2249' => '14721',
			'8553' => '14722',
			'1976' => '14723',
			'5893' => '14724',
			'3348' => '14725',
			'3264' => '14726',
			'1169' => '14727',
			'12006' => '14728',
			'13368' => '14729',
			'12636' => '14730',
			'3277' => '14731',
			'3993' => '14732',
			'9311' => '14733',
			'7918' => '14734',
			'6279' => '14735',
			'8102' => '14736',
			'9813' => '14737',
			'5243' => '14738',
			'825' => '14739',
			'2210' => '14740',
			'8524' => '14741',
			'8014' => '14742',
			'4315' => '14743',
			'3026' => '14744',
			'3322' => '14745',
			'11468' => '14746',
			'619' => '14747',
			'8466' => '14748',
			'5600' => '14749',
			'7835' => '14750',
			'6932' => '14751',
			'4868' => '14752',
			'7969' => '14753',
			'5387' => '14754',
			'7469' => '14755',
			'9782' => '14756',
			'7409' => '14757',
			'11370' => '14758',
			'1264' => '14759',
			'3511' => '14760',
			'4299' => '14761',
			'13700' => '14762',
			'8707' => '14763',
			'10188' => '14764',
			'1130' => '14765',
			'5085' => '14766',
			'10602' => '14767',
			'12350' => '14768',
			'6992' => '14769',
			'11458' => '14770',
			'12213' => '14771',
			'2660' => '14772',
			'2799' => '14773',
			'13650' => '14774',
			'324' => '14775',
			'2505' => '14776',
			'6575' => '14777',
			'12848' => '14778',
			'2959' => '14779',
			'2762' => '14780',
			'1881' => '14781',
			'5506' => '14782',
			'4754' => '14783',
			'11230' => '14784',
			'9861' => '14785',
			'6689' => '14786',
			'4851' => '14787',
			'11505' => '14788',
			'4083' => '14789',
			'13141' => '14790',
			'13559' => '14791',
			'3968' => '14792',
			'12031' => '14793',
			'7819' => '14794',
			'2387' => '14795',
			'2401' => '14796',
			'5624' => '14797',
			'9696' => '14798',
			'6989' => '14799',
			'10261' => '14800',
			'6858' => '14801',
			'2232' => '14802',
			'8551' => '14803',
			'7460' => '14804',
			'8242' => '14805',
			'5046' => '14806',
			'10438' => '14807',
			'7726' => '14808',
			'928' => '14809',
			'2480' => '14810',
			'5739' => '14811',
			'12125' => '14812',
			'1187' => '15013',
			'12152' => '14814',
			'9870' => '14815',
			'13934' => '14816',
			'7950' => '14817',
			'7845' => '14818',
			'10295' => '14819',
			'7911' => '14820',
			'2778' => '14821',
			'10115' => '14822',
			'12019' => '14823',
			'13586' => '14824',
			'1777' => '14825',
			'5859' => '14826',
			'3953' => '14827',
			'13070' => '14828',
			'6682' => '14829',
			'3207' => '14830',
			'12282' => '14831',
			'6833' => '14832',
			'6839' => '14833',
			'4087' => '14834',
			'4577' => '14835',
			'7225' => '14836',
			'13568' => '14837',
			'6166' => '14838',
			'10553' => '14839',
			'10777' => '14840',
			'12615' => '14841',
			'5115' => '14842',
			'426' => '14843',
			'9966' => '14844',
			'5295' => '14845',
			'3003' => '14846',
			'12063' => '14847',
			'6290' => '14848',
			'12234' => '14849',
			'10615' => '14850',
			'9886' => '14851',
			'7824' => '14852',
			'6699' => '14853',
			'6524' => '14854',
			'6367' => '14855',
			'6349' => '14856',
			'4404' => '14857',
			'7428' => '14858',
			'1443' => '14859',
			'10521' => '14860',
			'11581' => '14861',
			'418' => '14862',
			'3085' => '14863',
			'8949' => '14864',
			'12408' => '14865',
			'10320' => '14866',
			'4064' => '14867',
			'12315' => '14868',
			'10273' => '14869',
			'13182' => '14870',
			'6056' => '14871',
			'4325' => '14872',
			'7980' => '14873',
			'1109' => '14874',
			'13879' => '14875',
			'2238' => '14876',
			'5825' => '14877',
			'11895' => '14878',
			'4001' => '14879',
			'12580' => '14880',
			'4810' => '14881',
			'3991' => '14882',
			'11884' => '14883',
			'4374' => '14884',
			'13247' => '14885',
			'10356' => '14886',
			'6559' => '14887',
			'675' => '14888',
			'8287' => '14889',
			'6967' => '14890',
			'13632' => '14891',
			'6338' => '14892',
			'6140' => '14893',
			'1101' => '14894',
			'7732' => '14895',
			'13814' => '14896',
			'2735' => '14897',
			'13689' => '15509',
			'11991' => '14899',
			'13433' => '14900',
			'674' => '14901',
			'4094' => '14902',
			'10121' => '14903',
			'10017' => '14904',
			'1657' => '14905',
			'9880' => '14906',
			'11729' => '14907',
			'8399' => '14908',
			'4489' => '14909',
			'7305' => '14910',
			'6423' => '14911',
			'12859' => '14912',
			'11213' => '14913',
			'9825' => '14914',
			'12322' => '14915',
			'2175' => '14916',
			'12920' => '14917',
			'5395' => '14918',
			'4070' => '14919',
			'8280' => '14920',
			'13277' => '14921',
			'10740' => '14922',
			'128' => '14923',
			'8943' => '14924',
			'7629' => '14925',
			'11054' => '14926',
			'7327' => '14927',
			'13442' => '14928',
			'3027' => '14929',
			'8093' => '14930',
			'11532' => '14931',
			'13056' => '14932',
			'6571' => '14933',
			'5095' => '14934',
			'14' => '14936',
			'2203' => '14937',
			'1241' => '14938',
			'2727' => '14939',
			'13339' => '14940',
			'5883' => '14941',
			'7597' => '14942',
			'7328' => '14943',
			'2294' => '14944',
			'2804' => '14945',
			'913' => '14946',
			'13796' => '14947',
			'5991' => '14948',
			'733' => '14949',
			'7616' => '14950',
			'11055' => '14951',
			'2475' => '14952',
			'2767' => '14953',
			'6405' => '14954',
			'10275' => '14955',
			'5018' => '14956',
			'13278' => '14957',
			'6393' => '14958',
			'13680' => '14959',
			'4318' => '14960',
			'10687' => '14961',
			'5546' => '14962',
			'1114' => '14963',
			'38' => '14964',
			'1887' => '14965',
			'5587' => '14966',
			'11117' => '14967',
			'5908' => '14968',
			'10983' => '14969',
			'5325' => '14970',
			'7235' => '14971',
			'10367' => '14972',
			'6665' => '14973',
			'2467' => '14974',
			'11038' => '14975',
			'1496' => '14976',
			'1878' => '14977',
			'6573' => '14978',
			'9283' => '14979',
			'9576' => '14980',
			'8184' => '14981',
			'10876' => '14982',
			'6165' => '14983',
			'11519' => '14984',
			'8903' => '14985',
			'12084' => '14986',
			'1437' => '14987',
			'9867' => '14988',
			'13284' => '14989',
			'7855' => '14990',
			'8437' => '14991',
			'8646' => '14992',
			'13084' => '14993',
			'5312' => '14994',
			'3841' => '14995',
			'11324' => '14996',
			'5292' => '14997',
			'9012' => '14998',
			'11810' => '14999',
			'5353' => '15000',
			'8914' => '15001',
			'830' => '15002',
			'4311' => '15003',
			'3978' => '15004',
			'8112' => '15005',
			'5837' => '15006',
			'10286' => '15007',
			'4035' => '15008',
			'5746' => '15009',
			'3216' => '15010',
			'12121' => '15011',
			'7090' => '15012',
			'2746' => '15015',
			'10425' => '15016',
			'5289' => '15017',
			'900' => '15018',
			'11078' => '15019',
			'12821' => '15020',
			'12327' => '15021',
			'10796' => '15022',
			'2514' => '15023',
			'357' => '15024',
			'10006' => '15025',
			'8901' => '15026',
			'10836' => '15027',
			'7663' => '15028',
			'7102' => '15029',
			'7579' => '15030',
			'693' => '15031',
			'6017' => '15032',
			'1488' => '15033',
			'1483' => '15035',
			'13388' => '15037',
			'1759' => '15038',
			'4712' => '15039',
			'5163' => '15040',
			'8540' => '15041',
			'13178' => '15042',
			'10067' => '15043',
			'9637' => '15044',
			'1900' => '15045',
			'12630' => '15046',
			'5106' => '15047',
			'2553' => '15048',
			'11744' => '15049',
			'9955' => '15050',
			'3465' => '15051',
			'7379' => '15052',
			'158' => '15053',
			'11817' => '15054',
			'10365' => '15055',
			'416' => '15056',
			'11512' => '15057',
			'12249' => '15058',
			'9190' => '15059',
			'3241' => '15060',
			'980' => '15061',
			'10349' => '15062',
			'11623' => '15063',
			'9296' => '15064',
			'8164' => '15065',
			'11899' => '15066',
			'9625' => '15067',
			'7110' => '15068',
			'11549' => '15069',
			'13396' => '15070',
			'1995' => '15071',
			'4601' => '15072',
			'3330' => '15073',
			'3577' => '15074',
			'12874' => '15075',
			'126' => '15076',
			'1723' => '15077',
			'10370' => '15078',
			'12149' => '15079',
			'13675' => '15080',
			'477' => '15081',
			'7095' => '15082',
			'6044' => '15083',
			'7630' => '15084',
			'2755' => '15085',
			'13902' => '15086',
			'11111' => '15087',
			'11589' => '15088',
			'8812' => '15089',
			'9219' => '15090',
			'2159' => '15091',
			'6849' => '15092',
			'5277' => '15093',
			'6519' => '15094',
			'10110' => '15095',
			'6267' => '15096',
			'3404' => '15097',
			'6977' => '15098',
			'2306' => '15099',
			'4245' => '15100',
			'8657' => '15101',
			'1183' => '15102',
			'9201' => '15103',
			'3602' => '15104',
			'8149' => '15105',
			'1166' => '15106',
			'11304' => '15107',
			'1235' => '15108',
			'6426' => '15109',
			'10772' => '15110',
			'7094' => '15111',
			'13663' => '15112',
			'12235' => '15113',
			'3362' => '15114',
			'1513' => '15115',
			'1252' => '15116',
			'7593' => '15117',
			'1952' => '15118',
			'10490' => '15119',
			'9500' => '15120',
			'3413' => '15121',
			'3457' => '15122',
			'1906' => '15123',
			'9975' => '15124',
			'7406' => '15125',
			'9260' => '15126',
			'1751' => '15127',
			'13367' => '15128',
			'6282' => '15129',
			'8720' => '15130',
			'12348' => '15131',
			'6370' => '15132',
			'2714' => '15133',
			'892' => '15134',
			'5153' => '15135',
			'1376' => '15136',
			'853' => '15137',
			'1685' => '15138',
			'10241' => '15139',
			'9879' => '15140',
			'6695' => '15141',
			'4760' => '15142',
			'4462' => '15143',
			'5826' => '15144',
			'11312' => '15145',
			'6697' => '15146',
			'11564' => '15147',
			'5776' => '15148',
			'6387' => '15149',
			'7624' => '15150',
			'11622' => '15151',
			'3044' => '15152',
			'367' => '15153',
			'5595' => '15154',
			'13031' => '15155',
			'8142' => '15156',
			'6074' => '15157',
			'9622' => '15158',
			'2187' => '15159',
			'7464' => '15160',
			'8253' => '15161',
			'5496' => '15162',
			'7477' => '15163',
			'451' => '15164',
			'6875' => '15165',
			'13717' => '15166',
			'3771' => '15167',
			'2144' => '15168',
			'1373' => '15169',
			'2032' => '15170',
			'2836' => '15171',
			'11515' => '15172',
			'13588' => '15173',
			'7828' => '15174',
			'5693' => '15175',
			'2784' => '15176',
			'7858' => '15177',
			'11843' => '15178',
			'8436' => '15179',
			'1625' => '15180',
			'13139' => '15181',
			'9742' => '15182',
			'2441' => '15183',
			'10949' => '15184',
			'7135' => '15185',
			'6353' => '15186',
			'6151' => '15187',
			'7079' => '15188',
			'2580' => '15189',
			'10218' => '15190',
			'4274' => '15191',
			'6334' => '15192',
			'8013' => '15194',
			'12307' => '15195',
			'3764' => '15196',
			'13943' => '15197',
			'1219' => '15198',
			'447' => '15199',
			'6328' => '15200',
			'10354' => '15201',
			'45' => '15202',
			'11775' => '15203',
			'7982' => '15204',
			'2464' => '15205',
			'634' => '15206',
			'4864' => '15207',
			'12491' => '15208',
			'6132' => '15209',
			'2171' => '15210',
			'10532' => '15211',
			'10257' => '15212',
			'5155' => '15213',
			'6545' => '15214',
			'3747' => '15215',
			'8763' => '15216',
			'2777' => '15217',
			'3523' => '15218',
			'6871' => '15219',
			'11445' => '15220',
			'12300' => '15221',
			'3853' => '15222',
			'5795' => '15223',
			'13621' => '15224',
			'9771' => '15225',
			'13183' => '15226',
			'9735' => '15227',
			'12687' => '15228',
			'12649' => '15229',
			'4054' => '15230',
			'6627' => '15231',
			'9715' => '15232',
			'3023' => '15233',
			'8620' => '15536',
			'2409' => '15235',
			'8209' => '15236',
			'3565' => '15237',
			'4838' => '15238',
			'907' => '15239',
			'10253' => '15240',
			'7167' => '15241',
			'14046' => '15242',
			'13646' => '15243',
			'6641' => '15244',
			'2405' => '15245',
			'7920' => '15246',
			'10496' => '15247',
			'7572' => '15248',
			'5560' => '15249',
			'1093' => '15250',
			'1720' => '15251',
			'9314' => '15252',
			'13878' => '15253',
			'11290' => '15254',
			'1867' => '15255',
			'11806' => '15256',
			'2258' => '15257',
			'10621' => '15258',
			'2816' => '15259',
			'1157' => '15260',
			'12250' => '15261',
			'5772' => '15262',
			'5291' => '15263',
			'9218' => '15264',
			'11981' => '15265',
			'12831' => '15266',
			'3037' => '15267',
			'1095' => '15268',
			'8888' => '15269',
			'11723' => '15270',
			'11798' => '15271',
			'3969' => '15272',
			'54' => '15273',
			'3817' => '15274',
			'11612' => '15275',
			'2271' => '15276',
			'4232' => '15277',
			'3732' => '15278',
			'1999' => '15279',
			'7083' => '15280',
			'13425' => '15281',
			'13346' => '15282',
			'12344' => '15283',
			'3821' => '15284',
			'11065' => '15285',
			'10322' => '15286',
			'2140' => '15287',
			'1753' => '15288',
			'1156' => '15289',
			'7965' => '15290',
			'4316' => '15291',
			'9629' => '15292',
			'7830' => '15293',
			'1959' => '15294',
			'10251' => '15295',
			'7843' => '15297',
			'9533' => '15298',
			'5389' => '15299',
			'3787' => '15338',
			'807' => '15302',
			'11311' => '15303',
			'6318' => '15304',
			'8743' => '15305',
			'2655' => '15306',
			'11244' => '15307',
			'4567' => '15308',
			'5251' => '15309',
			'12363' => '15310',
			'9290' => '15311',
			'6041' => '15312',
			'2851' => '15313',
			'11980' => '15314',
			'3201' => '15315',
			'12253' => '15316',
			'13974' => '15317',
			'5418' => '15318',
			'11263' => '15319',
			'10344' => '15320',
			'1656' => '15321',
			'10974' => '15322',
			'11450' => '15323',
			'9863' => '15325',
			'9063' => '15326',
			'4647' => '15327',
			'8919' => '15328',
			'5885' => '15329',
			'80' => '15330',
			'753' => '15331',
			'14066' => '15332',
			'9078' => '15333',
			'4268' => '15334',
			'11606' => '15335',
			'4542' => '15336',
			'13665' => '15337',
			'5154' => '15339',
			'7967' => '15340',
			'7551' => '15341',
			'9713' => '15342',
			'9332' => '15343',
			'3772' => '15344',
			'42' => '15345',
			'9795' => '15346',
			'11620' => '15347',
			'615' => '15348',
			'13128' => '15349',
			'649' => '15350',
			'428' => '15351',
			'13412' => '15352',
			'12039' => '15353',
			'5882' => '15354',
			'7092' => '15355',
			'826' => '15356',
			'5319' => '15357',
			'6703' => '15358',
			'12634' => '15359',
			'5637' => '15360',
			'6302' => '15361',
			'10086' => '15362',
			'13976' => '15363',
			'8926' => '15364',
			'8483' => '15365',
			'1895' => '15366',
			'11285' => '15367',
			'823' => '15368',
			'1150' => '15369',
			'2843' => '15370',
			'6385' => '15371',
			'10123' => '15372',
			'7625' => '15373',
			'6024' => '15374',
			'3209' => '15375',
			'12905' => '15376',
			'8656' => '15377',
			'12590' => '15378',
			'1201' => '15379',
			'11024' => '15380',
			'5505' => '15381',
			'5865' => '15382',
			'3735' => '15383',
			'7713' => '15384',
			'12856' => '15385',
			'2439' => '15386',
			'10094' => '15387',
			'9810' => '15388',
			'13351' => '15389',
			'4778' => '15390',
			'467' => '15391',
			'7668' => '15392',
			'9557' => '15393',
			'2521' => '15394',
			'354' => '15395',
			'7383' => '15396',
			'8461' => '15397',
			'11455' => '15398',
			'13681' => '15399',
			'10449' => '15400',
			'2749' => '15401',
			'1266' => '15402',
			'13824' => '15403',
			'2759' => '15404',
			'8923' => '15405',
			'11114' => '15406',
			'1348' => '15407',
			'9543' => '15408',
			'9019' => '15409',
			'11964' => '15410',
			'5366' => '15411',
			'7722' => '15412',
			'12628' => '15413',
			'13830' => '15414',
			'3445' => '15415',
			'12808' => '15416',
			'7329' => '15417',
			'3364' => '15418',
			'9513' => '15419',
			'13269' => '15420',
			'6138' => '15421',
			'2202' => '15422',
			'4024' => '15423',
			'346' => '15424',
			'8119' => '15425',
			'6700' => '15426',
			'13245' => '15427',
			'10572' => '15428',
			'9734' => '15429',
			'5794' => '15430',
			'10236' => '15431',
			'2515' => '15432',
			'13333' => '15433',
			'3502' => '15434',
			'10696' => '15435',
			'3390' => '15436',
			'11632' => '15437',
			'5808' => '15438',
			'13864' => '15439',
			'8219' => '15440',
			'10770' => '15441',
			'11730' => '15442',
			'12820' => '15443',
			'3291' => '15444',
			'967' => '15445',
			'9549' => '15446',
			'11850' => '15447',
			'11451' => '15448',
			'2012' => '15449',
			'1624' => '15450',
			'9567' => '15451',
			'4045' => '15452',
			'7696' => '15453',
			'3576' => '15454',
			'7693' => '15455',
			'5507' => '15456',
			'3309' => '15457',
			'9029' => '15458',
			'8272' => '15459',
			'12377' => '15460',
			'10237' => '15461',
			'13312' => '15462',
			'8661' => '15463',
			'9065' => '15464',
			'9362' => '15465',
			'2006' => '15466',
			'6156' => '15467',
			'6991' => '15468',
			'945' => '15469',
			'6938' => '15470',
			'183' => '15471',
			'8963' => '15472',
			'8501' => '15473',
			'6437' => '15474',
			'2168' => '15475',
			'6438' => '15476',
			'11481' => '15477',
			'5549' => '15478',
			'7841' => '15479',
			'11971' => '15480',
			'6181' => '15481',
			'7236' => '15482',
			'13356' => '15483',
			'13300' => '15484',
			'9537' => '15485',
			'3370' => '15486',
			'3486' => '15487',
			'1424' => '15488',
			'6599' => '15489',
			'7711' => '15490',
			'7621' => '15491',
			'5503' => '15492',
			'2250' => '15493',
			'10437' => '15494',
			'3237' => '15495',
			'5280' => '15496',
			'6630' => '15497',
			'7867' => '15498',
			'6407' => '15499',
			'3481' => '15500',
			'9878' => '15501',
			'11780' => '15502',
			'2437' => '15503',
			'1692' => '15504',
			'1761' => '15505',
			'6291' => '15506',
			'13618' => '15507',
			'330' => '15508',
			'3070' => '15510',
			'1724' => '15511',
			'11325' => '15512',
			'392' => '15513',
			'6183' => '15514',
			'3924' => '15515',
			'5370' => '15516',
			'6943' => '15517',
			'11373' => '15518',
			'8712' => '15519',
			'9714' => '15520',
			'11090' => '15521',
			'9621' => '15522',
			'5890' => '15523',
			'10447' => '15524',
			'11384' => '15525',
			'9074' => '15526',
			'758' => '15527',
			'13161' => '15528',
			'12682' => '15529',
			'13707' => '15530',
			'3410' => '15531',
			'560' => '15532',
			'677' => '15533',
			'1168' => '15534',
			'3081' => '15535',
			'2826' => '15537',
			'13921' => '15538',
			'8404' => '15539',
			'6142' => '15540',
			'3828' => '15541',
			'11287' => '15542',
			'12058' => '15543',
			'2217' => '15544',
			'10331' => '15545',
			'3571' => '15546',
			'13426' => '15547',
			'11279' => '15548',
			'1868' => '15549',
			'165' => '15550',
			'13801' => '15551',
			'2133' => '15552',
			'1969' => '15553',
			'2849' => '15554',
			'5665' => '15555',
			'13525' => '15556',
			'9816' => '15557',
			'1133' => '15558',
			'6351' => '15559',
			'10780' => '15560',
			'4351' => '15561',
			'8722' => '15562',
			'9502' => '15563',
			'7435' => '15564',
			'5259' => '15565',
			'5012' => '15566',
			'6277' => '15567',
			'2123' => '15568',
			'5745' => '15569',
			'2962' => '15570',
			'10027' => '15571',
			'8486' => '15572',
			'10806' => '15573',
			'5053' => '15575',
			'1630' => '15576',
			'964' => '15577',
			'834' => '15578',
			'2661' => '15579',
			'7928' => '15580',
			'8530' => '15581',
			'7177' => '15582',
			'13552' => '15583',
			'11390' => '15584',
			'931' => '15585',
			'4855' => '15586',
			'4205' => '15587',
			'1092' => '15588',
			'9636' => '15589',
			'12591' => '15590',
			'3939' => '15591',
			'6626' => '15592',
			'10189' => '15593',
			'2446' => '15594',
			'13905' => '15595',
			'7609' => '15596',
			'5677' => '15597',
			'9501' => '15598',
			'996' => '15599',
			'10604' => '15600',
			'8703' => '15601',
			'353' => '15602',
			'8688' => '15603',
			'11109' => '15604',
			'10729' => '15605',
			'11121' => '15606',
			'1230' => '15607',
			'8159' => '15608',
			'12892' => '15609',
			'10523' => '15610',
			'11795' => '15611',
			'9970' => '15612',
			'11205' => '15613',
			'12877' => '15614',
			'28' => '15615',
			'7649' => '15616',
			'8772' => '15617',
			'2211' => '15618',
			'15628' => '17246',
			'15738' => '15014',
			'15804' => '17134',
			'15703' => '17338',
			'15670' => '16495',
			'15668' => '16638',
			'15642' => '17057',
			'15654' => '16820',
			'15949' => '16775',
			'15864' => '17081',
			'15666' => '17173',
			'15620' => '16587',
			'15641' => '16463',
			'15700' => '16718',
			'15734' => '16989',
			'15791' => '16643',
			'15984' => '17343',
			'15725' => '14238',
			'15636' => '16538',
			'15645' => '16631',
			'15702' => '16523',
			'15757' => '17277',
			'15701' => '17141',
			'15644' => '16611',
			'15637' => '16912',
			'15742' => '16487',
			'15622' => '17056',
			'15778' => '15193',
			'15638' => '16546',
			'15669' => '17135',
			'15726' => '14935',
			'15627' => '16414',
			'15688' => '16710',
			'15836' => '16962',
			'15621' => '17117',
			'15699' => '17139',
			'15662' => '16687',
			'15766' => '16850',
			'15619' => '15574',
			'15748' => '17211',
			'15686' => '16942',
			'15915' => '16652',
			'15658' => '17103',
			'15698' => '16734',
			'15640' => '17190',
			'15800' => '16480',
			'15733' => '16307',
			'15783' => '16319',
			'15673' => '16979',
			'15633' => '17136',
			'15977' => '16382',
			'15687' => '16490',
			'15639' => '16569',
			'15659' => '17040',
			'15635' => '16505',
			'25425' => '25429',
			'25477' => '25487',
			'25476' => '25488',
			'25498' => '25505',
			'25496' => '25499',
			'25526' => '25530',
			'25523' => '25533',
			'25561' => '25533',
			'25574' => '25575',
			'25576' => '25577',
			'25578' => '25579',
			'25580' => '25581',
			'25582' => '25583',
			'25584' => '25585',
			'25587' => '25588',
			'25593' => '25594',
			'25596' => '25597',
			'25598' => '25599',
			'25600' => '25601',
			'25602' => '25603',
			'25642' => '25643',
			'25659' => '25660',
			'25661' => '25663',
			'25665' => '25666',
			'25670' => '25672',
			'25669' => '25671',
			'25674' => '25675',
			'25673' => '25676',
			'25677' => '25679',
			'25678' => '25680',
			'25682' => '25684',
			'25686' => '25688',
			'25685' => '25687',
			'25690' => '25691',
			'25694' => '25695',
			'25697' => '25699',
			'25698' => '25700',
			'25701' => '25704',
			'25702' => '25703',
			'25705' => '25707',
			'25706' => '25708',
			'25710' => '25712',
			'25711' => '25713',
			'25716' => '25717',
			'25718' => '25719',
			'25722' => '25725',
			'25723' => '25724',
			'25727' => '25728',
			'25726' => '25729',
			'25731' => '25732',
			'25730' => '25733',
			'25734' => '25736',
			'25735' => '25737',
			'25739' => '25740',
			'25738' => '25741',
			'25742' => '25745',
			'25743' => '25744',
			'25747' => '25749',
			'25746' => '25748',
			'25751' => '25753',
			'25750' => '25752',
			'25755' => '25756',
			'25754' => '25757',
			'25758' => '25760',
			'25759' => '25761',
			'25762' => '25763',
			'25764' => '25765',
			'25657' => '25766',
			'25768' => '25769',
			'25667' => '25770',
			'25824' => '25825',
			'25655' => '25826',
			'25662' => '25827',
			'25882' => '25883',
			'25912' => '25913',
			'25916' => '25917',
			'25905' => '25919',
			'25689' => '25924',
			'25927' => '25928',
			'25681' => '25930',
			'25931' => '25932',
			'25933' => '25934',
			'25935' => '25936',
			'25937' => '25938',
			'25939' => '25940',
			'25941' => '25942',
			'25943' => '25944',
			'25958' => '25959',
			'25960' => '25961',
			'25962' => '25963',
			'25966' => '25967',
			'25968' => '25969',
			'25973' => '25974',
			'25975' => '25976',
			'25977' => '25978',
			'25955' => '25982',
			'25986' => '25987',
			'25988' => '25989',
			'25693' => '25992',
			'25984' => '25993',
			'25946' => '25994',
			'25995' => '25996',
			'25997' => '25998',
			'25999' => '26000',
			'25964' => '26004',
			'26006' => '26007',
			'26008' => '26009',
			'26012' => '26013',
			'26014' => '26015',
			'26016' => '26017',
			'26018' => '26019',
			'26020' => '26021',
			'25990' => '26022',
			'26023' => '26024',
		];


	}

	private function data_attach_ids_rewind() {
		return array(4,44,46,25654,25656,25657,25658,25659,25662,25662,25662,25662,25662,25665,25667,25667,25667,25670,25670,25670,25670,25670,25670,25671,25672,25672,25674,25674,25675,25676,25677,25681,25682,25684,25684,25685,25686,25686,25686,25687,25688,25688,25691,25693,25696,25697,25698,25698,25698,25700,25700,25700,25700,25700,25701,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25702,25703,25703,25703,25703,25705,25705,25705,25705,25707,25709,25709,25711,25711,25712,25712,25712,25712,25713,25713,25715,25717,25717,25717,25717,25718,25718,25720,25720,25721,25721,25722,25724,25726,25727,25728,25729,25731,25732,25733,25733,25733,25733,25734,25736,25738,25740,25741,25743,25743,25744,25745,25746,25747,25748,25749,25749,25750,25751,25751,25751,25753,25754,25756,25756,25756,25757,25758,25758,25759,25760,25764,25765,25765,25767,25768,25768,25770,25770,25771,25774,25774,25774,25775,25775,25775,25775,25776,25780,25783,25785,25786,25787,25788,25789,25790,25792,25794,25794,25794,25795,25795,25796,25796,25796,25797,25797,25798,25798,25798,25798,25798,25799,25800,25804,25804,25804,25804,25808,25810,25810,25811,25812,25812,25813,25815,25815,25816,25816,25816,25816,25818,25819,25819,25819,25820,25823,25823,25824,25824,25825,25825,25825,25825,25826,25827,25830,25830,25830,25830,25832,25832,25834,25836,25840,25840,25841,25842,25843,25844,25846,25847,25849,25849,25850,25851,25852,25852,25854,25854,25854,25855,25856,25856,25856,25856,25857,25859,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25860,25862,25866,25870,25872,25875,25876,25878,25878,25878,25880,25880,25880,25880,25882,25882,25883,25883,25883,25883,25884,25885,25885,25887,25888,25888,25888,25888,25888,25889,25889,25891,25893,25896,25896,25897,25898,25898,25898,25898,25899,25899,25901,25903,25904,25909,25910,25910,25910,25910,25911,25912,25913,25913,25913,25913,25913,25914,25915,25915,25915,25918,25919,25920,25922,25925,25926,25928,25928,25928,25929,25929,25929,25931,25932,25933,25934,25937,25938,25940,25940,25940,25940,25940,25940,25943,25945,25946,25947,25948,25950,25951,25952,25952,25953,25954,25954,25954,25954,25956,25958,25959,25960,25961,25961,25963,25963,25964,25964,25964,25964,25964,25964,25965,25966,25966,25967,25967,25968,25968,25968,25968,25968,25968,25968,25973,25973,25976,25977,25978,25978,25978,25978,25979,25980,25980,25980,25980,25981,25981,25981,25981,25981,25981,25981,25983,25984,25984,25984,25986,25987,25987,25987,25988,25990,25990,25994,25994,25995,25998,25998,25998,26001,26001,26002,26003,26006,26006,26006,26006,26008,26013,26014,26014,26014,26014,26015,26021,26022,26023,26024,26025,26025,26025,26025,26025,26026,26027,26027,26029,26030,26030,26030,26030,26031,26033,26035,26035,26036,26039,26040,26042,26043,26046,26046,26048,26049,26049,26049,26049,26050,26051,26053,26054,26056,26057,26057,26057,26057,26061,26061,26061,26061,26061,26061,26065,26065,26067,26068,26069,26069,26069,26069,26069,26070,26070,26072,26073,26073,26073,26073,26073,26074,26074,26074,26075,26076,26080,26081,26082,26082,26082,26082,26082,26083,26084,26084,26085,26086,26087,26088,26088,26089,26089,26089,26089,26089,26089,26089,26089,26089,26092,26092,26092,26094,26094,26095,26095);
	}

	private function data_cover_image_rewind_two() {
		return [
			'https://content-prod-ripr.thepublicsradio.org/articles/b54724ac-7ca1-478a-85c8-a5e4f184f2ca/mikepad.jpg' => '26037',
			'https://content-prod-ripr.thepublicsradio.org/articles/60579cc9-950e-48b2-b280-9ac6ce9cfdfb/jwususpendedsign.jpg' => '26044',
			'https://content-prod-ripr.thepublicsradio.org/articles/09fc69a4-36f7-4a2c-96c6-f9aa25d4ba63/robinlubbocknewbedfordmarinecommerceterminal3.jpg' => '26090',
			'https://content-prod-ripr.thepublicsradio.org/articles/e29089c5-3753-4612-a330-0fee25e45c43/tim4.jpg' => '25816',
			'https://content-prod-ripr.thepublicsradio.org/articles/b06cc94a-615d-41fc-81fc-1adf02db4aa4/reusablecupkellysikkemaunsplash.jpg' => '25848',
			'https://content-prod-ripr.thepublicsradio.org/articles/ca2b539c-6958-487f-8ce0-befd89afd4c3/mikepad.jpg' => '25949',
			'https://content-prod-ripr.thepublicsradio.org/articles/54a61b55-67ac-4701-bb71-377851208a51/reedhaaland2.jpg' => '25920',
			'https://content-prod-ripr.thepublicsradio.org/articles/fb3cca0b-485b-4d2e-8348-3f7a06ac95a5/starstoreedited2.jpg' => '26068',
			'https://content-prod-ripr.thepublicsradio.org/articles/58b161b5-5976-4386-9d94-9d6383e5d2fa/marbledave.jpg' => '25758',
			'https://content-prod-ripr.thepublicsradio.org/articles/b495f036-3638-44c5-9e2a-542222cc5b32/buddyteevens4yale19da.jpg' => '25720',
			'https://content-prod-ripr.thepublicsradio.org/articles/1ff0886f-3cc4-499b-afcd-e0649a724e4f/mikepad.jpg' => '25802',
			'https://content-prod-ripr.thepublicsradio.org/articles/7b8ab2d6-9d5d-4046-b6ba-037941a685d6/pulaskistaterecreationarea.jpg' => '25703',
			'https://content-prod-ripr.thepublicsradio.org/articles/66089ff9-63ba-404e-9a7a-d116307b7543/sizedsieu1199neworkersatpchcprotestinprovidence10.12.2023cropped.jpg' => '25893',
			'https://content-prod-ripr.thepublicsradio.org/articles/208b9dd2-49c4-4fbe-9142-971d98a19e9a/jeffreymarcuscoffeedom033020230838.jpg' => '26082',
			'https://content-prod-ripr.thepublicsradio.org/articles/9d585ba8-910b-40fa-bd8c-ffa54c160cea/providencecityscapeempirest.arianaleo.jpg' => '25663',
			'https://content-prod-ripr.thepublicsradio.org/articles/b3a2b991-c460-4765-9d52-1013c5a0ecee/sabinamatos072123.jpg' => '26055',
			'https://content-prod-ripr.thepublicsradio.org/articles/34777c1f-7f86-4902-8c0d-63b5ca9ffa31/mikepad.jpg' => '25962',
			'https://content-prod-ripr.thepublicsradio.org/articles/d5ae1f68-5730-4275-a1af-00b6fefcaf89/fullsizerender.png' => '26043',
			'https://content-prod-ripr.thepublicsradio.org/articles/4c8e15b1-eec3-4c07-aacf-d19261510cb6/walukwashsq071820232068.jpg' => '25864',
			'https://content-prod-ripr.thepublicsradio.org/articles/c424cb3b-9f37-40f4-86eb-f69ffa55a2df/img0127.jpg' => '25726',
			'https://content-prod-ripr.thepublicsradio.org/articles/167b1138-f869-4e5d-b88d-b91d32ecdcb9/lighthouse4.jpg' => '25908',
			'https://content-prod-ripr.thepublicsradio.org/articles/f8e31b4f-b047-4964-bee5-364367517dcc/sizedkarendanninresidentbethanyhome10.04.2023img4491cropped.jpg' => '26070',
			'https://content-prod-ripr.thepublicsradio.org/articles/1a0f5222-a256-430d-bf85-c3a96e325f66/mikepad.jpg' => '25985',
			'https://content-prod-ripr.thepublicsradio.org/articles/5e51c27c-e8a6-44aa-b14b-72b6f467c93f/1jkowxkgzesqhlvw6njba8b22lqxvngnw2648h1470iv2.jpg' => '25815',
			'https://content-prod-ripr.thepublicsradio.org/articles/cc00dd6e-5987-4ea1-a97a-4037e75b83e2/mikepad.jpg' => '25655',
			'https://content-prod-ripr.thepublicsradio.org/articles/be0f89e2-c313-4fc2-8bd5-b165a58a2799/img2185.jpg' => '25712',
			'https://content-prod-ripr.thepublicsradio.org/articles/2ed5e8e1-a8be-4333-a56a-5c77102ac892/fortroad.jpg' => '25884',
			'https://content-prod-ripr.thepublicsradio.org/articles/ad7c055a-3bb2-4ad3-95ac-726a2eabd327/morroneresigns.jpg' => '25993',
			'https://content-prod-ripr.thepublicsradio.org/articles/85a5071c-c1a3-4aa5-b9a5-17bf35bca5b9/dsc5548.jpg' => '25806',
			'https://content-prod-ripr.thepublicsradio.org/articles/0f0cb260-de64-4df3-9d01-ce1a1fafe0a0/mikepad.jpg' => '25710',
			'https://content-prod-ripr.thepublicsradio.org/articles/83f14b20-f83a-4107-b3c8-71e35ce1b21f/deceptionpasscollectionsurge2018cropped.jpg' => '25823',
			'https://content-prod-ripr.thepublicsradio.org/articles/26fb22a8-c0ca-4379-aa43-c70016be52e5/ap23282004569750.jpg' => '25889',
			'https://content-prod-ripr.thepublicsradio.org/articles/984a7dc5-3746-4a95-b7ef-c9bb8d6d24b2/mikepad.jpg' => '26019',
			'https://content-prod-ripr.thepublicsradio.org/articles/0e599d15-7790-413b-aa71-856794136ea7/img19891.jpg' => '26021',
			'https://content-prod-ripr.thepublicsradio.org/articles/bbb56312-a5f5-4853-9638-15af8ff3659a/img6819.jpg' => '25849',
			'https://content-prod-ripr.thepublicsradio.org/articles/57a2a2a2-cd25-4b73-aafc-45854fee760b/napatreepoint.jpg' => '26009',
			'https://content-prod-ripr.thepublicsradio.org/articles/4c004190-5238-432a-b242-dba53e9880a0/mikepad.jpg' => '25975',
			'https://content-prod-ripr.thepublicsradio.org/articles/603845e0-db7a-4535-843e-fe8d60c8e337/newportsuper082520232335.jpg' => '25824',
			'https://content-prod-ripr.thepublicsradio.org/articles/1a1d7f32-e329-4f31-aea2-ff58db4d486b/oscarperez040323.jpg' => '26047',
			'https://content-prod-ripr.thepublicsradio.org/articles/3e861ae9-05a7-4993-ae7b-81ea27466d07/mikepad.jpg' => '25858',
			'https://content-prod-ripr.thepublicsradio.org/articles/f072994d-241a-41e1-a76a-211dd2f9bab1/img2975.jpg' => '25828',
			'https://content-prod-ripr.thepublicsradio.org/articles/d81b8257-b1ec-467d-8f1a-49b861682916/starstoreresized.jpg' => '25793',
			'https://content-prod-ripr.thepublicsradio.org/articles/1c81c9ff-74f8-4e12-82f8-913a5feb4460/0919possiblymicahgiszackunsplash.jpg' => '25988',
			'https://content-prod-ripr.thepublicsradio.org/articles/5222813e-2c44-4aad-adf3-43e42ad39490/img0028.jpg' => '25900',
			'https://content-prod-ripr.thepublicsradio.org/articles/d6e02a0a-6931-4fe6-bfb5-33c95b1d448d/mikepad.jpg' => '25660',
			'https://content-prod-ripr.thepublicsradio.org/articles/c08de577-7d34-4897-ba03-2b5783429a76/mikepad.jpg' => '25805',
			'https://content-prod-ripr.thepublicsradio.org/articles/f0b601dc-ac93-4118-951f-f3e94616c667/shoreline1.jpg' => '25706',
			'https://content-prod-ripr.thepublicsradio.org/articles/392e9d31-0066-4d87-a373-fff0ce1544ff/lighthouseapplicationimage.jpg' => '26059',
			'https://content-prod-ripr.thepublicsradio.org/articles/1e6f2c5b-543e-4c12-bdb2-613ad0197b27/carolpona.jpg' => '25666',
			'https://content-prod-ripr.thepublicsradio.org/articles/357928d7-9201-471b-8378-6e759aed2994/lighthouseroadentrance.jpg' => '25869',
			'https://content-prod-ripr.thepublicsradio.org/articles/c5577bed-ccc8-4fab-858e-e7e6ec1a9808/102023salveaidamercy3crop.jpg' => '25966',
			'https://content-prod-ripr.thepublicsradio.org/articles/2233a503-3374-4458-a9a0-c9692617b3d6/041723risdsigns1ebertz.jpg' => '25716',
			'https://content-prod-ripr.thepublicsradio.org/articles/a867ea69-00d9-4721-9ac0-89bc48703b98/votingunitedstateswikimedia.jpg' => '26018',
			'https://content-prod-ripr.thepublicsradio.org/articles/3202ea40-8fcc-4ad2-b855-9c5b32afbd48/mikepad.jpg' => '25669',
			'https://content-prod-ripr.thepublicsradio.org/articles/193b727d-59e9-4068-970b-a1707c45fb15/092023underage3rodrigo2cooper.jpg' => '25888',
			'https://content-prod-ripr.thepublicsradio.org/articles/05a42cd4-5331-4fff-9805-984b6a4380bd/weayonnohnelsondavies.jpg' => '25997',
			'https://content-prod-ripr.thepublicsradio.org/articles/b210f43c-f8ff-43c3-a180-32892e6839e4/gammhangmenpress4.jpg' => '25765',
			'https://content-prod-ripr.thepublicsradio.org/articles/0e7b338e-ff31-4c77-9dab-398c8b03ec8d/mikepad.jpg' => '25941',
			'https://content-prod-ripr.thepublicsradio.org/articles/9b0b7f7e-f798-4736-9040-ba0589fd28b2/allensave1.jpg' => '25923',
			'https://content-prod-ripr.thepublicsradio.org/articles/3c7aca73-fd4e-4b76-81d9-4450f55598be/img3229.jpg' => '25865',
			'https://content-prod-ripr.thepublicsradio.org/articles/d3b3dc5b-2c88-442e-84b5-02d06a69c50e/rep.aaronregunbergatclimatemobilizationapr.292017avorybrookins.jpg' => '25760',
			'https://content-prod-ripr.thepublicsradio.org/articles/b24c3200-7164-4f3c-a061-d4d9a1bb64aa/kevinrose060123.jpg' => '26060',
			'https://content-prod-ripr.thepublicsradio.org/articles/be64d338-db0b-44b8-891c-e10ffd03b1a6/vietnamwarveteransmemorialperspective.jpg' => '25820',
			'https://content-prod-ripr.thepublicsradio.org/articles/4dffff53-3ab5-4557-96ea-392519cabdd7/dsc03811.jpg' => '25969',
			'https://content-prod-ripr.thepublicsradio.org/articles/e50e6785-02d9-402c-954c-aa837ab0d805/shavingunsplash.jpg' => '25769',
			'https://content-prod-ripr.thepublicsradio.org/articles/152f585f-044e-4142-b739-e464cc08f5da/harborresized.jpg' => '25764',
			'https://content-prod-ripr.thepublicsradio.org/articles/bb179c31-94e4-4d86-b837-facebfb3c51d/img01091.jpg' => '25733',
			'https://content-prod-ripr.thepublicsradio.org/articles/1c20ef8a-1202-4c83-a50b-3fdd25bb4511/frostydrew3.jpg' => '25906',
			'https://content-prod-ripr.thepublicsradio.org/articles/a2d08c6e-9a6e-46f2-b221-f5374d23ef46/morrone1.jpg' => '25971',
			'https://content-prod-ripr.thepublicsradio.org/articles/7a738c40-dfc2-4fe8-b27b-fc37c5b4ab51/072523buffchaceprovidencecitycouncil1ebertz.jpg' => '25814',
			'https://content-prod-ripr.thepublicsradio.org/articles/9a2f0f08-86ef-4d90-9494-04bd8e2ac8f1/judysamtwophotos.jpg' => '25831',
			'https://content-prod-ripr.thepublicsradio.org/articles/1e15bb87-c7b4-4920-82fb-011b7e5af0a7/joeshekarchi061023.jpg' => '25737',
			'https://content-prod-ripr.thepublicsradio.org/articles/45524a4b-ecfd-4077-bdeb-44a5d9ae9553/lighthouse5.jpg' => '25747',
			'https://content-prod-ripr.thepublicsradio.org/articles/f909b07d-1276-4259-b5bf-109adbc0019d/signresized.jpg' => '25902',
			'https://content-prod-ripr.thepublicsradio.org/articles/6c2d180d-3392-4b83-82ef-81e0e6391137/genderqueer.jpg' => '25725',
			'https://content-prod-ripr.thepublicsradio.org/articles/00ce8bf9-12f8-481a-a039-e685efa34cf6/f3qogssasaaljpz.jpg' => '26067',
			'https://content-prod-ripr.thepublicsradio.org/articles/6c1d09e0-cb47-4532-9a93-7d7c92b49861/ussupremecourtwiki.jpg' => '25787',
			'https://content-prod-ripr.thepublicsradio.org/articles/adb990a8-7f5e-444a-be9e-fbaed08883df/img3672img3672.jpg' => '25751',
			'https://content-prod-ripr.thepublicsradio.org/articles/e833d0c5-4f74-44f0-bdd0-f5176993666e/img2122.jpg' => '26074',
			'https://content-prod-ripr.thepublicsradio.org/articles/109f34c6-4289-4b92-936a-3fd6be3ff858/joeshekarchiandfriends060223.jpg' => '25752',
			'https://content-prod-ripr.thepublicsradio.org/articles/fb5a36e8-a9b9-45c6-afbe-711955b9e8ef/purcell1.jpg' => '26028',
			'https://content-prod-ripr.thepublicsradio.org/articles/6fbebb2b-3db9-463a-b02f-bba6b9b59a54/mikepad.jpg' => '26024',
			'https://content-prod-ripr.thepublicsradio.org/articles/fd06ac09-79b6-41a0-ad73-fd021ad79895/dsc1471.jpg' => '25989',
			'https://content-prod-ripr.thepublicsradio.org/articles/04501a7c-9769-4e63-9006-7610a030b994/img0751.jpg' => '26089',
			'https://content-prod-ripr.thepublicsradio.org/articles/945d7a27-41b8-4b1a-8447-f8900cc9de6e/img6633.jpg' => '26035',
			'https://content-prod-ripr.thepublicsradio.org/articles/f1bf8ba4-a50b-4833-8f2c-a1e4b5a4fca8/actionfrontsavedgedarius090823.png' => '25721',
			'https://content-prod-ripr.thepublicsradio.org/articles/7028f588-5aef-4e95-b6ec-43f36c24f9be/img0011.jpg' => '25886',
			'https://content-prod-ripr.thepublicsradio.org/articles/e8b04e5f-8006-4aca-b2e1-38696f4285f3/mikepad.jpg' => '25879',
			'https://content-prod-ripr.thepublicsradio.org/articles/a6b9ab8a-be1a-4840-b227-52783efa579c/0026.jpg' => '25670',
			'https://content-prod-ripr.thepublicsradio.org/articles/61d0f210-376f-40eb-8445-b88d544a1460/sizeddr.pablorodriguezscreenshot1002202311.41.411cropped.jpg' => '25784',
			'https://content-prod-ripr.thepublicsradio.org/articles/57e46c46-e094-4724-8e00-0401856a9239/img1119.jpg' => '25859',
			'https://content-prod-ripr.thepublicsradio.org/articles/fec1c4a3-0688-436f-b829-38ba042551cf/img8045.jpg' => '25996',
			'https://content-prod-ripr.thepublicsradio.org/articles/e0c24dfb-60ff-43ec-a367-0ab8f2a959c0/rhodeislandfoundationsallyeisele.jpg' => '25982',
			'https://content-prod-ripr.thepublicsradio.org/articles/37fcf2a7-bd5a-4c3f-8fda-f86c4d2ea4ce/karenalzate061323.jpg' => '25766',
			'https://content-prod-ripr.thepublicsradio.org/articles/4cfb9797-d29b-43c7-91f8-71efdc201685/mikepad.jpg' => '25935',
			'https://content-prod-ripr.thepublicsradio.org/articles/d466ec84-76ae-4c56-abfa-f72a2ca3a5fa/img0042.jpg' => '25899',
			'https://content-prod-ripr.thepublicsradio.org/articles/0128cea9-5ee0-46a1-ae4b-4aec1ea0ff51/microplasticsamples0001.jpg' => '26091',
			'https://content-prod-ripr.thepublicsradio.org/articles/0ef21334-7a19-4208-9613-a2bbda86db9d/img1805.jpg' => '26078',
			'https://content-prod-ripr.thepublicsradio.org/articles/5f47243d-5c81-42a1-ac43-4e33782eaa4d/napatreepointsign.jpg' => '26079',
			'https://content-prod-ripr.thepublicsradio.org/articles/b6a09e78-e61e-4629-ba7f-2dbe23350f26/ithffoundersflowers3.jpg' => '25912',
			'https://content-prod-ripr.thepublicsradio.org/articles/0c19cde7-cf87-4ac2-8e71-e598f439323e/m39a6867.jpg' => '25674',
			'https://content-prod-ripr.thepublicsradio.org/articles/9396de0b-2b58-4cfe-860b-ac96de6510b6/policestationresized.jpg' => '26006',
			'https://content-prod-ripr.thepublicsradio.org/articles/c9678824-6f4d-4cef-960a-4c8878310a89/10122023adamgreenmanoffice.jpg' => '25827',
			'https://content-prod-ripr.thepublicsradio.org/articles/acd3d4a4-2931-4705-bb8e-833faba0bf21/70465180007ripro061720neprincipalscopy.jpg' => '25801',
			'https://content-prod-ripr.thepublicsradio.org/articles/993c31b7-2207-4bad-9e65-50730b853a51/sizedjenniferduboissignraisedmarchestowoonsocketcityhallrally4.21.22cropped.jpg' => '25986',
			'https://content-prod-ripr.thepublicsradio.org/articles/ec333c56-ae60-4222-a651-38dd26519feb/gabeamoatthepublicsradio.jpg' => '44',
			'https://content-prod-ripr.thepublicsradio.org/articles/95e78445-5907-4821-9b2a-5930e0c12a2a/ashstreetjail2.jpg' => '25832',
			'https://content-prod-ripr.thepublicsradio.org/articles/e11430f7-b158-4cc2-aba3-477163ef0f7f/pessoaresized.jpg' => '25708',
			'https://content-prod-ripr.thepublicsradio.org/articles/0c9cf699-f76e-40b6-b082-827786633af8/thomasquinniiiresized.jpg' => '25873',
			'https://content-prod-ripr.thepublicsradio.org/articles/f4dfcb5f-817f-41ba-add3-d75ac71909ee/lighthouse1.jpg' => '26046',
			'https://content-prod-ripr.thepublicsradio.org/articles/ed27c340-5377-4aa7-a2ca-0c612811ced4/mikepad.jpg' => '25874',
			'https://content-prod-ripr.thepublicsradio.org/articles/9dbd01a6-3470-45fb-b603-e1eb94054ce4/amtrakacela.jpg' => '25875',
			'https://content-prod-ripr.thepublicsradio.org/articles/e652595b-7757-4ade-867d-6b45ba6e5024/apphoto1ap23282723326959.jpg' => '25894',
			'https://content-prod-ripr.thepublicsradio.org/articles/5f1314af-a1b4-4035-877d-d72546eadec9/img2111.jpg' => '26069',
			'https://content-prod-ripr.thepublicsradio.org/articles/0819c7b9-64c5-412b-86e6-5987dd689f1a/img1424.jpg' => '25856',
			'https://content-prod-ripr.thepublicsradio.org/articles/8955f9c4-f365-4774-8eee-2a15aba20af3/img3077.jpg' => '25944',
			'https://content-prod-ripr.thepublicsradio.org/articles/c2c48501-0c61-456c-8b4b-982899d25481/folkfest.jpg' => '25916',
			'https://content-prod-ripr.thepublicsradio.org/articles/a54058df-c1f3-46a5-9f00-6888c68ea3f6/publicsvoicewebphoto.jpg' => '25895',
			'https://content-prod-ripr.thepublicsradio.org/articles/e7f75b2f-52a7-4d07-a913-d8739e6c0f0c/img0078.jpg' => '25981',
			'https://content-prod-ripr.thepublicsradio.org/articles/2f1ebd16-b5c9-41cd-b2d8-28aa17994d09/091823underage1boat1healey.jpg' => '26039',
			'https://content-prod-ripr.thepublicsradio.org/articles/cb6237fb-ba3c-463a-8f2c-bbb98d691551/taunt.jpg' => '25972',
			'https://content-prod-ripr.thepublicsradio.org/articles/08795ee5-d705-46ad-9db3-e3d8b585bc9e/keeleyaccess2.jpg' => '25933',
			'https://content-prod-ripr.thepublicsradio.org/articles/554e3ae2-a72b-4ab1-a4ac-c7ac40d0e0cd/peterneronhajune2023.jpg' => '25854',
			'https://content-prod-ripr.thepublicsradio.org/articles/decf1957-8da5-4497-9839-b347b84fb9c6/providencepubliclibrarywashingtonstreetprovidencerhodeisland.jpg' => '25877',
			'https://content-prod-ripr.thepublicsradio.org/articles/21424bfe-575c-4059-8add-63f2ff2d30f6/269wickenden.png' => '25958',
			'https://content-prod-ripr.thepublicsradio.org/articles/bede4e13-87ce-42d3-b692-3ed13859798c/dsc1680.jpg' => '26093',
			'https://content-prod-ripr.thepublicsradio.org/articles/50f4c9fc-842f-456c-aa7a-42f844c66d23/shoreline.jpg' => '25967',
			'https://content-prod-ripr.thepublicsradio.org/articles/950928b7-142b-43a8-8e17-53e9550eeb0e/vineyarddeal1.jpg' => '25990',
			'https://content-prod-ripr.thepublicsradio.org/articles/31bc8a2b-6a05-4eb7-ab35-829546ca2d96/taunt.jpg' => '25929',
			'https://content-prod-ripr.thepublicsradio.org/articles/90f414cd-220e-48a5-9b06-458b8ff64069/raidscreenshot.png' => '25773',
			'https://content-prod-ripr.thepublicsradio.org/articles/87d285c1-9c7c-40b1-9760-d17aff5d8330/blockislandwindfarmionna22wikimediacommons.jpg' => '25948',
			'https://content-prod-ripr.thepublicsradio.org/articles/635e76a3-d63a-4fce-a386-ac7c5d24f977/ilaphoto1.jpg' => '25921',
			'https://content-prod-ripr.thepublicsradio.org/articles/48dcd8b3-d9b6-4a69-9905-7426c4daeb65/manatee.jpg' => '25887',
			'https://content-prod-ripr.thepublicsradio.org/articles/fa3aa341-1f61-4d2d-89cb-aaf140f5c141/sizedkylemcneillonbuscropped.jpg' => '25772',
			'https://content-prod-ripr.thepublicsradio.org/articles/40d73a3a-126e-4128-bdbe-086ba9ced3be/codachuntingtonaveprovidence08.25.23pgcropped1.jpg' => '25917',
			'https://content-prod-ripr.thepublicsradio.org/articles/736ea77a-d832-4af6-bf90-db7f2c51414d/ap2.jpg' => '25918',
			'https://content-prod-ripr.thepublicsradio.org/articles/3b9fa7bd-ee93-49c1-a64a-508f078869df/sizedcloseupsterileneedlesandothersupplies12332662.jpg' => '25930',
			'https://content-prod-ripr.thepublicsradio.org/articles/0978ae16-1143-455c-9d78-58dd0219d96a/17cd1forum08222023.jpg' => '25812',
			'https://content-prod-ripr.thepublicsradio.org/articles/aaecec1a-ea51-47cc-8421-32eca3ca128a/blockislandwindfarmwikimediacommons.jpg' => '26016',
			'https://content-prod-ripr.thepublicsradio.org/articles/eee0ff20-4723-4d0b-92de-c9dbaed2553e/tourocsicourt2474.jpg' => '25680',
			'https://content-prod-ripr.thepublicsradio.org/articles/ac02535f-0322-4128-b6cb-b61cdac58fb6/chrisbove06102023.jpg' => '25717',
			'https://content-prod-ripr.thepublicsradio.org/articles/b97abae9-2e3f-49be-a308-176f1ec94954/newsizedwilliamdalpeownerpatriotfirearmsschoolrehobothma05.02.2023cropped.jpg' => '25676',
			'https://content-prod-ripr.thepublicsradio.org/articles/b36dc44a-cd00-4112-ac06-12d9a251f5ab/cfhornwall2048x1536.jpg' => '26001',
			'https://content-prod-ripr.thepublicsradio.org/articles/e41f7359-29e9-4282-93d7-c52d4d968ed9/pinkboots209162023.jpg' => '26049',
			'https://content-prod-ripr.thepublicsradio.org/articles/237a3222-8065-4998-9086-5fd86755ee72/img0169.jpg' => '25684',
			'https://content-prod-ripr.thepublicsradio.org/articles/7c43c5c4-a00f-4166-92e7-d8bf5828fd62/mikepad.jpg' => '25821',
			'https://content-prod-ripr.thepublicsradio.org/articles/e3ab3b45-92a8-4e5a-948a-3e4106903638/eacaga.jpg' => '25761',
			'https://content-prod-ripr.thepublicsradio.org/articles/e6c5360d-9cf3-4db5-a725-feee782ccef0/dsc4711w.jpg' => '25994',
			'https://content-prod-ripr.thepublicsradio.org/articles/31a50759-c9c8-4c64-9c47-6c491afeebbe/dirkensen.jpg' => '25999',
			'https://content-prod-ripr.thepublicsradio.org/articles/69950ab9-5fd5-447b-8445-bfcb6b45f712/ap23289582908674.jpg' => '25836',
			'https://content-prod-ripr.thepublicsradio.org/articles/9a1de264-14ba-4788-bfed-260b0a136472/092023underage3rodrigo2cooper.jpg' => '26025',
			'https://content-prod-ripr.thepublicsradio.org/articles/6e90ce07-f95f-4320-8331-55a5f7096d7c/cd1picwpri.jpg' => '25664',
			'https://content-prod-ripr.thepublicsradio.org/articles/3831ad93-9513-46d6-8505-483a649602f1/0imi7tii.jpg' => '25685',
			'https://content-prod-ripr.thepublicsradio.org/articles/fdb948f8-ab3c-48b8-9f2f-7c51ae3a07d1/051823szostaksteinberg1.jpg' => '25744',
			'https://content-prod-ripr.thepublicsradio.org/articles/a8cbfc60-d833-441f-b587-1cba50d44579/conley091123.jpg' => '25991',
			'https://content-prod-ripr.thepublicsradio.org/articles/b9286f78-68c8-4a75-89dd-6622654148d8/img0187.jpg' => '26077',
			'https://content-prod-ripr.thepublicsradio.org/articles/4c9c13a3-fe1f-46c5-9201-4010ad099bc1/111023weeklycatchmainimage.jpg' => '26063',
			'https://content-prod-ripr.thepublicsradio.org/articles/0dd9fe65-1b5c-4074-984c-d871c39b98e7/housing.jpg' => '26020',
			'https://content-prod-ripr.thepublicsradio.org/articles/75435c2c-e717-43f4-8b5a-79fedf40b1de/img17681.jpg' => '25880',
			'https://content-prod-ripr.thepublicsradio.org/articles/114a65a9-2946-4d4e-9096-1d5fada9ac06/satdisholdandnew.jpg' => '25804',
			'https://content-prod-ripr.thepublicsradio.org/articles/97222ff1-6689-442b-a512-e3a1617fa11a/bristolcountyjail.jpg' => '25866',
			'https://content-prod-ripr.thepublicsradio.org/articles/b098ffb3-f1b5-455c-b3e1-f3c03759cb94/library3.jpg' => '25819',
			'https://content-prod-ripr.thepublicsradio.org/articles/2b972f72-b0ee-4868-91f3-f5256c7027af/neronhaprt120123.jpg' => '25778',
			'https://content-prod-ripr.thepublicsradio.org/articles/a6dec687-6d25-498d-8eb6-ed6ca0bce12d/img01391.jpg' => '25987',
			'https://content-prod-ripr.thepublicsradio.org/articles/a0eec2d5-d507-48c0-9a8c-1d2bdd6efbd1/abortionbothsides2019.jpg' => '26032',
			'https://content-prod-ripr.thepublicsradio.org/articles/cf596f62-5d99-463d-9edc-00573c00d504/gerryleonard091123.jpg' => '25822',
			'https://content-prod-ripr.thepublicsradio.org/articles/07ac1341-6d53-4956-9eae-cf11d391c9d2/mikepad.jpg' => '25807',
			'https://content-prod-ripr.thepublicsradio.org/articles/24ae7bf7-dec9-4619-ba97-c498e9309010/braytonpointbragaresized.jpg' => '25883',
			'https://content-prod-ripr.thepublicsradio.org/articles/5cad3fd7-6033-42a7-a375-985939ac0a67/mikepad.jpg' => '25803',
			'https://content-prod-ripr.thepublicsradio.org/articles/21918bbe-173a-422c-a362-241d7d1d99d0/082923possiblyoilspills.jpg' => '25896',
			'https://content-prod-ripr.thepublicsradio.org/articles/84f70417-f0bc-4afc-9b04-f2d5cb40f649/091823underage1nathanaelwindow1hilton.jpg' => '25702',
			'https://content-prod-ripr.thepublicsradio.org/articles/82980c1d-47f3-4ca9-8630-c41ce22d211d/johnjmoran.jpg' => '25863',
			'https://content-prod-ripr.thepublicsradio.org/articles/19fb0ee3-833e-4a9d-92da-e9588680d321/avelchuklanovou1eqo29umsunsplash.jpg' => '26085',
			'https://content-prod-ripr.thepublicsradio.org/articles/9c3ce96a-181d-4748-8114-c241c19974f7/img18511.jpg' => '25798',
			'https://content-prod-ripr.thepublicsradio.org/articles/a8882d86-8319-45ca-b1cd-a2512890a031/shorelinephoto1.jpg' => '25781',
			'https://content-prod-ripr.thepublicsradio.org/articles/7c366003-b28c-4a13-b529-7f1055e9153b/img0309.jpg' => '25662',
			'https://content-prod-ripr.thepublicsradio.org/articles/dcf26d6c-0fce-46ba-9740-c4cc0bea3289/mikepad.jpg' => '25927',
			'https://content-prod-ripr.thepublicsradio.org/articles/433187ce-f158-48bb-abe3-284be96c1d20/blockislandwind.jpg' => '25890',
			'https://content-prod-ripr.thepublicsradio.org/articles/f7562e67-09ef-4348-88d9-1b9093338fbd/fortroad.jpg' => '25955',
			'https://content-prod-ripr.thepublicsradio.org/articles/40ab4eb1-5b73-4176-90ab-c04d809fd5f9/sandtrailnotice.jpg' => '25905',
			'https://content-prod-ripr.thepublicsradio.org/articles/dd0ed5e3-1b0d-42b6-8d7f-0f2930018f85/pjpexhibitionppl2.jpg' => '25844',
			'https://content-prod-ripr.thepublicsradio.org/articles/79fe29c5-6559-48e7-8fb9-90e901309b33/electiondayresized.jpg' => '25762',
			'https://content-prod-ripr.thepublicsradio.org/articles/b367d282-f43e-4a1c-b6d8-5ad799319daf/img4168.jpg' => '26095',
			'https://content-prod-ripr.thepublicsradio.org/articles/24e25529-65df-4bf0-af92-f8a0e5d67965/shorelinebillsenate.jpg' => '25970',
			'https://content-prod-ripr.thepublicsradio.org/articles/e8b27e77-99f9-459c-a2bf-fc1370323e1e/004qpaerial5523.jpg' => '25838',
			'https://content-prod-ripr.thepublicsradio.org/articles/e2094d69-a8a1-477b-94c2-89ec1aac2906/sandtrailimage.jpg' => '26011',
			'https://content-prod-ripr.thepublicsradio.org/articles/fca660f0-e49e-4c85-a2e6-5cb575e19043/screenshot14.png' => '26062',
			'https://content-prod-ripr.thepublicsradio.org/articles/d45418a0-987b-4109-9b12-3f1b339d7bb5/braytonpointbragaresized.jpg' => '25774',
			'https://content-prod-ripr.thepublicsradio.org/articles/7c7a906e-0508-4b51-8e2f-081cbf79ee65/sizeddr.ashishjha2frombrownu10cropped.jpg' => '25719',
			'https://content-prod-ripr.thepublicsradio.org/articles/93babc09-e4e5-4375-a11b-11b0f897bc3b/charliefixsailhorizontals1404230426amr11hrt02302.jpg' => '25907',
			'https://content-prod-ripr.thepublicsradio.org/articles/057fb5a9-033f-4cec-9943-129501f8680e/rogerwilliamsmcbygretchen.jpg' => '25657',
			'https://content-prod-ripr.thepublicsradio.org/articles/cfe4f4e5-0f98-4bba-b551-6a781878759d/chrisdiani4.jpg' => '26030',
			'https://content-prod-ripr.thepublicsradio.org/articles/aa5eb208-03ef-4a29-9b22-6e542c4b993a/charliefixsailhorizontals1404230426amr11hrt0230.jpg' => '25964',
			'https://content-prod-ripr.thepublicsradio.org/articles/ea5a7d5f-5298-4ac2-aac6-d8bc32a3a6f3/081823tornado1eddy46295688.jpg' => '25809',
			'https://content-prod-ripr.thepublicsradio.org/articles/3ed26b01-a0dc-4ece-a73d-b59e271160a4/beachhousemisquamicutwiki.jpg' => '25871',
			'https://content-prod-ripr.thepublicsradio.org/articles/4c74ada7-107c-4380-bcb6-618692014919/newbedfordhurricanebarrierbenberke.jpg' => '25942',
			'https://content-prod-ripr.thepublicsradio.org/articles/32d3d8f3-a86c-4354-ae2b-98b00900c3dd/7799441810203ec5704bo.jpg' => '25810',
			'https://content-prod-ripr.thepublicsradio.org/articles/b18ff4e6-6f92-4de5-af90-1f7ca79a1cd0/mikepad.jpg' => '26017',
			'https://content-prod-ripr.thepublicsradio.org/articles/d0343528-5998-48a7-9c0e-ab98012beaff/sizedinsidedignitybuswithlightscropped.jpg' => '25696',
			'https://content-prod-ripr.thepublicsradio.org/articles/0b1f7ad2-07da-4c8e-9897-454c59dc2a66/pessoasentencing.jpg' => '25678',
			'https://content-prod-ripr.thepublicsradio.org/articles/dab9bd68-d7fb-437d-847a-57f826240a1f/ninasparlingimg2540.jpg' => '25763',
			'https://content-prod-ripr.thepublicsradio.org/articles/cff43086-e26e-46dc-9750-1d77e5de24db/ap2.jpg' => '25984',
			'https://content-prod-ripr.thepublicsradio.org/articles/31393004-12fd-4acb-9785-d5b83411ac92/img0709.jpg' => '25892',
			'https://content-prod-ripr.thepublicsradio.org/articles/811c4ad8-4faa-4d78-a847-b1d595d0a241/fyayjbwyaaelzx.jpg' => '26061',
			'https://content-prod-ripr.thepublicsradio.org/articles/2e852c06-332e-4c8c-8b06-97d1f772cf41/georgeredmanlinearparklookingeast2015.jpg' => '25742',
			'https://content-prod-ripr.thepublicsradio.org/articles/da3e0215-5113-4550-a11b-0dcc39bf2709/img11811.jpg' => '25817',
			'https://content-prod-ripr.thepublicsradio.org/articles/b5637a45-1995-419d-b92a-c5eaea599b27/charlesroberts53120231919.jpg' => '25945',
			'https://content-prod-ripr.thepublicsradio.org/articles/73a2847f-aa33-4938-8b6e-aa7c444d774a/mikepad.jpg' => '25694',
			'https://content-prod-ripr.thepublicsradio.org/articles/096eb729-bc93-41b9-83a6-2bcabc76d4c9/081523possiblyowninganev2.jpg' => '26065',
			'https://content-prod-ripr.thepublicsradio.org/articles/90413df3-a32f-462e-a68c-771948399025/11thhourteam2183080423.jpg' => '25978',
			'https://content-prod-ripr.thepublicsradio.org/articles/23adb7f3-0ba5-416f-89e5-7ee2404f9013/sabinamatosjanuary2023.jpg' => '26071',
			'https://content-prod-ripr.thepublicsradio.org/articles/880012ab-cdcc-4d4f-b067-9f59fa34b8b9/091223possiblydominikvanopdenboschunsplash.jpg' => '25730',
			'https://content-prod-ripr.thepublicsradio.org/articles/cb7188ae-07e4-4b0b-af70-21a0bad753c6/img2285.jpg' => '4',
			'https://content-prod-ripr.thepublicsradio.org/articles/616da12b-4374-44b8-b76c-ddec94aa92d6/southcoastcourtesycrmc.jpg' => '26088',
			'https://content-prod-ripr.thepublicsradio.org/articles/2a1760f3-b7d7-4da4-bce9-2b19c319eeb6/rifoundation.jpg' => '25940',
			'https://content-prod-ripr.thepublicsradio.org/articles/0030c0df-1297-4d3a-a992-f6e128cea326/mikepad.jpg' => '26000',
			'https://content-prod-ripr.thepublicsradio.org/articles/7f6ed6e8-a8ad-4b4e-ad90-4eda3e77fa7b/sizeddbinsideblue07.19.23arditicropped.jpg' => '25878',
			'https://content-prod-ripr.thepublicsradio.org/articles/b8e8bb77-917c-486f-b25d-321d39bc0f9e/starstoreedited.jpg' => '25857',
			'https://content-prod-ripr.thepublicsradio.org/articles/82ea48c2-8f56-4177-b241-f9c68b083c4e/policestationresized.jpg' => '25692',
			'https://content-prod-ripr.thepublicsradio.org/articles/fd4c33f8-e7ac-4e1d-80b5-04f3fe3c6775/mackblackiewithwilliamgroverandhiswifeveronicahigbie02.13.20232.jpg' => '26094',
			'https://content-prod-ripr.thepublicsradio.org/articles/bf384d94-35c8-4b70-807f-1bae9799ef61/mikepad.jpg' => '25861',
			'https://content-prod-ripr.thepublicsradio.org/articles/cc44182d-8920-412b-a0a8-209b841c5a53/sabinamatosjanuary2023.jpg' => '25973',
			'https://content-prod-ripr.thepublicsradio.org/articles/7860e5d8-85eb-4ea1-95fd-94a2f990752a/091823underage1nathanaelwindow1hilton.jpg' => '25860',
			'https://content-prod-ripr.thepublicsradio.org/articles/5db031dc-81c6-4efd-99eb-ffff32b7e839/statehousefrombackhorizontalarianaleo.jpg' => '25939',
			'https://content-prod-ripr.thepublicsradio.org/articles/9da4c841-a194-426e-967c-45be557eb65e/juliatalkstograceandrikkiincourtroomduringheldtrialjune2023photobyrobinloznak.jpg' => '26083',
			'https://content-prod-ripr.thepublicsradio.org/articles/a608a7b5-5446-41bb-af33-35b0e96e375d/lombardo.jpg' => '25924',
			'https://content-prod-ripr.thepublicsradio.org/articles/9b1b400f-15bf-427b-acc3-ab0bf932e8d2/magaziner.jpg' => '26058',
			'https://content-prod-ripr.thepublicsradio.org/articles/6e69686a-35fd-4392-820b-e654a685a319/041423bostonmarathonfinish1billmorrowcc.jpg' => '25723',
			'https://content-prod-ripr.thepublicsradio.org/articles/c3551541-eb52-4504-a391-0ef8bf6d6c75/mikepad.jpg' => '25689',
			'https://content-prod-ripr.thepublicsradio.org/articles/c46b8750-e3cc-4fe4-80ca-d5903784f27c/peterneronhajune2023.jpg' => '25731',
			'https://content-prod-ripr.thepublicsradio.org/articles/1ac4f7d6-95cd-40ba-9fcb-06dfaee61d87/theweeklycatch3000x3000.png' => '25867',
			'https://content-prod-ripr.thepublicsradio.org/articles/3e5879e8-f68d-482c-83ed-ef93a140d5ce/img2425.jpg' => '25957',
			'https://content-prod-ripr.thepublicsradio.org/articles/7addef80-a699-4777-a717-52392e735800/woonsocketpolicedepartment02.27.2023.jpg' => '26026',
			'https://content-prod-ripr.thepublicsradio.org/articles/3eb9be6a-6a40-4c38-a18d-73bee8ce0f5d/tristangregoroutsidemorley.jpg' => '26034',
			'https://content-prod-ripr.thepublicsradio.org/articles/3f3f0f7e-7502-4933-a0bb-5762c31b864c/toddcravenslwacyk8scmaunsplash.jpg' => '26080',
			'https://content-prod-ripr.thepublicsradio.org/articles/4fab1bc2-a3c8-4202-b31b-6621294bae0c/07172022ihofoben2400.jpg' => '25690',
			'https://content-prod-ripr.thepublicsradio.org/articles/5ebd69a0-5f72-4e81-8285-42d7412db48d/mikepad.jpg' => '25714',
			'https://content-prod-ripr.thepublicsradio.org/articles/27039af0-391f-433d-94bf-aded979d55a9/mikepad.jpg' => '25735',
			'https://content-prod-ripr.thepublicsradio.org/articles/b67828b9-f47b-4640-a082-13b1f4ffb8b3/fallriver058.jpg' => '26007',
			'https://content-prod-ripr.thepublicsradio.org/articles/5385797a-a507-45af-8acb-042887007242/aaronginsburg09012023.jpg' => '25840',
			'https://content-prod-ripr.thepublicsradio.org/articles/244091b8-e437-4219-9561-dd493629ecf3/viralmiddletown.jpg' => '25829',
			'https://content-prod-ripr.thepublicsradio.org/articles/4dddb580-dffa-40b6-8183-f042cb2e25cf/cryingcropped1.jpg' => '25705',
			'https://content-prod-ripr.thepublicsradio.org/articles/d2abf606-8a45-4087-a006-eb57ea2051fe/marta.jpg' => '25686',
			'https://content-prod-ripr.thepublicsradio.org/articles/f8a943cc-30df-4a03-98d0-cb4f25f407db/exterior.jpg' => '26023',
			'https://content-prod-ripr.thepublicsradio.org/articles/80e4212a-44c2-4853-857e-ae9187db4c62/conleyphoto.jpg' => '25679',
		];
	}













	/*********************************** OLD PARSE FUNCTIONS ********************************************/

	private function parse_media( $item ) {
			
		// <video - none
		// <source - none
		// <embed - none

		$this->parse_media_tag( 'iframe' );
		$this->parse_media_tag( 'img' );
		$this->parse_link_tag( 'a' );
		$this->parse_file_attachments( 'file-attachment' );
		
		// {\"image\":\"
		$this->parse_block_image( 'image' );

		// {\"file-attachment\":{\"path\":\"https://content-prod-ripr.thepublicsradio.org/articles/87d4d01c-f54a-49bc-98b7-e4b7df41d83a/mosaiccta2.mp3\",
		$this->parse_block_file_attachment( 'file-attachment' );

		// \"link\":\"http://media.ride.ri.gov/BOE/092220Meeting/BOE_Meeting_09222020.mp4\"}
		// TODO: $this->parse_block_link( 'link' );

		/*
			// \"embedCode\":\"
			// \"insert\":\"https
		*/

	}

	private function parse_block_link( $tag ) {

		// get all tags from line
		if( preg_match_all('/{\\\\\"link.*?}/', $this->json_line, $matches ) ) {

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// get src match
				if( ! preg_match( '#{\\\\"link\\\\":\\\\"([^\\\\]+)(\.[A-Za-z0-9]{3,4})#', $tag_html, $src_match ) ) {
					$this->mylog( 'error-block-link-match', array(
						$tag => $tag_html
					));
					continue;
				}

				print_r($src_match);


				// // make sure correct count
				if ( 3 != count( $src_match )) {
					$this->mylog( 'error-block-link-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// // add domain name to report
				$substr_domain = substr($src_match[1], 0, 45);
				if( ! isset( $this->report['media'][$tag][$substr_domain] ) ) $this->report['media'][$tag][$substr_domain] = 1;
				else $this->report['media'][$tag][$substr_domain]++;
				
				// add extension to report
				if( ! isset( $this->report['media'][$tag . '-extensions'][$src_match[2]] ) ) $this->report['media'][$tag . '-extensions'][$src_match[2]] = 1;
				else $this->report['media'][$tag . '-extensions'][$src_match[2]]++;
			
			}
		}
	}

	private function parse_block_file_attachment( $tag ) {

		// get all tags from line
		if( preg_match_all('/{\\\\\"file-attachment.*?}/', $this->json_line, $matches ) ) {

			$this->mylog( 'block-file-attachments', array( $this->json_line , $matches ));

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// get src match
				if( ! preg_match( '#{\\\\"file-attachment\\\\":{\\\\"path\\\\":\\\\"([^\\\\]+)(\.[A-Za-z0-9]{3,4})#', $tag_html, $src_match ) ) {
					$this->mylog( 'error-block-file-attachment-match', array(
						$tag => $tag_html
					));
					continue;
				}

				// // make sure correct count
				if ( 3 != count( $src_match )) {
					$this->mylog( 'error-block-file-attachment-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// // add domain name to report
				$substr_domain = substr($src_match[1], 0, 45);
				if( ! isset( $this->report['media'][$tag][$substr_domain] ) ) $this->report['media'][$tag][$substr_domain] = 1;
				else $this->report['media'][$tag][$substr_domain]++;
				
				// add extension to report
				if( ! isset( $this->report['media'][$tag . '-extensions'][$src_match[2]] ) ) $this->report['media'][$tag . '-extensions'][$src_match[2]] = 1;
				else $this->report['media'][$tag . '-extensions'][$src_match[2]]++;
			
			}
		}
	}


	private function parse_block_image( $tag ) {

		// get all tags from line
		if( preg_match_all('/{\\\\\"image.*?}/', $this->json_line, $matches ) ) {

			// print_r($matches);

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// get src match
				if( ! preg_match( '#{\\\\"image\\\\":\\\\"([^\\\\]+)(\.[A-Za-z0-9]{3,4})#', $tag_html, $src_match ) ) {
					$this->mylog( 'error-block-image-match', array(
						$tag => $tag_html
					));
					continue;
				}

				// // make sure correct count
				if ( 3 != count( $src_match )) {
					$this->mylog( 'error-block-image-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// // add domain name to report
				$substr_domain = substr($src_match[1], 0, 45);
				if( ! isset( $this->report['media'][$tag][$substr_domain] ) ) $this->report['media'][$tag][$substr_domain] = 1;
				else $this->report['media'][$tag][$substr_domain]++;
				
				// add extension to report
				if( ! isset( $this->report['media'][$tag . '-extensions'][$src_match[2]] ) ) $this->report['media'][$tag . '-extensions'][$src_match[2]] = 1;
				else $this->report['media'][$tag . '-extensions'][$src_match[2]]++;
			
			}
		}
	}

	private function parse_file_attachments( $tag ) {

		// get all tags from line
		if( preg_match_all('/<' . $tag . '.*?>/', $this->json_line, $matches ) ) {

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// get src match
				if( ! preg_match( '#path=[\\\\]*["\']{1}[\s]*(http(s?):)?//([^/]+)/#', $tag_html, $src_match ) ) {
					$this->mylog( 'error-file-attachment-match', array(
						$tag => $tag_html
					));
					continue;
				}
				
				// make sure correct count
				if ( 4 != count( $src_match )) {
					$this->mylog( 'error-file-attachment-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// add domain name to report
				if( ! isset( $this->report['media'][$tag][$src_match[3]] ) ) $this->report['media'][$tag][$src_match[3]] = 1;
				else $this->report['media'][$tag][$src_match[3]]++;
				
				// must have file extension
				if( ! preg_match( '#path=[\\\\]*["\']{1}[\s]*(http(s?):)?//[^\'"]+(\.[A-Za-z0-9]{3,4})#', $tag_html, $ext_match ) ) {
					$this->mylog( 'file-attachments-ext-errors', $tag_html );
				}
				
				// add extension to report
				if( ! isset( $this->report['media'][$tag . '-extensions'][$ext_match[3]] ) ) $this->report['media'][$tag . '-extensions'][$ext_match[3]] = 1;
				else $this->report['media'][$tag . '-extensions'][$ext_match[3]]++;
			
			}
		}
	}

	private function parse_media_tag( $tag ) {

		$domains_for_media = [
			'content-prod-ripr.thepublicsradio.org', // full count: 578344
			'content-prod-ripr.ripr.org', // full count: 633
		];

		// get all tags from line
		if( preg_match_all('/<' . $tag . '.*?>/', $this->json_line, $matches ) ) {

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// skip base64
				if( preg_match( '#src=[\\\\]*["\']{1}data:image/[A-Za-z]+;base64#', $tag_html) ) continue;
				
				// get src match
				if( ! preg_match( '#src=[\\\\]*["\']{1}[\s]*(http(s?):)?//([^/]+)/#', $tag_html, $src_match ) ) {
					$this->mylog( 'error-src-match', array(
						$tag => $tag_html
					));
					continue;
				}
				
				// make sure correct count
				if ( 4 != count( $src_match )) {
					$this->mylog( 'error-src-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// look at image extensions
				if ( 'img' == $tag ) {

					// if not a domain we care about, continue
					if( ! in_array( $src_match[3], $domains_for_media ) ) continue;

					
					// must have file extension
					if( ! preg_match( '#src=[\\\\]*["\']{1}[\s]*(http(s?):)?//[^\'"]+(\.[A-Za-z0-9]{3,4})#', $tag_html, $ext_match ) ) {
						$this->mylog( 'imgs-ext-errors', $tag_html );
					}
					
					// add extension to report
					if( ! isset( $this->report['media'][$tag . '-extensions'][$ext_match[3]] ) ) $this->report['media'][$tag . '-extensions'][$ext_match[3]] = 1;
					else $this->report['media'][$tag . '-extensions'][$ext_match[3]]++;

				}

				// add domain name to report
				if( ! isset( $this->report['media'][$tag][$src_match[3]] ) ) $this->report['media'][$tag][$src_match[3]] = 1;
				else $this->report['media'][$tag][$src_match[3]]++;
			
			}

		}

	}

	private function parse_link_tag( $tag ) {

		$domains_for_media = array(
			'thepublicsradio.org', // count ALL json lines => 2597
			'explore.thepublicsradio.org', // count ALL json lines => 281
			'content-prod-ripr.thepublicsradio.org', // count ALL json lines => 15
			'ripr.org', // count ALL json lines => 2669
			'www.ripr.org', // count ALL json lines => 249
			'ripr.secureallegiance.com', // count ALL json lines => 34
			'content-prod-ripr.thepublicsradio.org', // count ALL json lines => 15
			'ripr-clock.herokuapp.com', // count ALL json lines => 5
			'ripr.careasy.org', // count ALL json lines => 3
			'ripr-ice.streamguys1.com', // count ALL json lines => 3
			'riprapp.org', // count ALL json lines => 2
			'www.riprsoundvision.com', // count ALL json lines => 2
			'www.riprapp.org', // count ALL json lines => 2
			'archive.ripr.org', // count ALL json lines => 1
			'smart.ripr.org', // count ALL json lines => 1			
		);


		// get all tags from line
		if( preg_match_all('/<' . $tag . '.*?>/', $this->json_line, $matches ) ) {

			// print_r($matches);

			// setup report
			if( ! isset ( $this->report['media'][$tag] ) ) $this->report['media'][$tag] = [];
			if( ! isset ( $this->report['media'][$tag . '-extensions'] ) ) $this->report['media'][$tag . '-extensions'] = [];

			// loop through each match
			foreach( $matches[0] as $tag_html ) {
				
				// skip mailto
				if( preg_match( '#href=[\\\\]*["\']{1}mailto:#', $tag_html) ) continue;

				// get href match
				if( ! preg_match( '#href=[\\\\]*["\']{1}[\s]*(http(s?):)?//([^\\\\/]+)#', $tag_html, $href_match ) ) {
					$this->mylog( 'error-href-match', array(
						$tag => $tag_html
					));
					continue;
				}
				
				// make sure correct count
				if ( 4 != count( $href_match )) {
					$this->mylog( 'error-href-match-count', array(
						$tag => $tag_html
					));
					continue;
				}

				// if not a domain we care about, continue
				if( ! in_array( $href_match[3], $domains_for_media ) ) continue;

				// add domain name to report
				if( ! isset( $this->report['media'][$tag][$href_match[3]] ) ) $this->report['media'][$tag][$href_match[3]] = 1;
				else $this->report['media'][$tag][$href_match[3]]++;

				// look for file types
				if( preg_match( '#href=[\\\\]*["\']{1}[\s]*(http(s?):)?//([^\\\\/]+)[\\\\/]{1,2}[^\'"]+(\.[A-Za-z0-9]{3,4})#', $tag_html, $ext_match ) ) {

					$this->mylog( 'href-exts', $tag_html );
					$this->mylog( 'href-exts', $ext_match );

					// add extension to report
					if( ! isset( $this->report['media'][$tag . '-extensions'][$ext_match[4]] ) ) $this->report['media'][$tag . '-extensions'][$ext_match[4]] = 1;
					else $this->report['media'][$tag . '-extensions'][$ext_match[4]]++;

				}			
			}
		}

	}

}

