<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Big Bend Sentinel.
 */
class BigBendSentinelMigrator implements InterfaceCommand {

	private static $instance = null;

	private $coauthorsplus_logic;
	private $logger;
	private $redirection_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->logger              = new Logger();
		$this->redirection_logic   = new RedirectionLogic();
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
			'newspack-content-migrator bigbendsentinel-convert-issues-cpt',
			[ $this, 'cmd_convert_issues_cpt' ],
			[
				'shortdesc' => 'Convert Issues Custom Taxonomy to Pages and Tags and maybe some Redirects.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bigbendsentinel-convert-people-cpt',
			[ $this, 'cmd_convert_people_cpt' ],
			[
				'shortdesc' => 'Convert People CPT to Co-Authors Plus and add Redirects.',
			]
		);

	}
	public function cmd_convert_issues_cpt( $pos_args, $assoc_args ) {

		// register the taxonomy since the old site had this in their theme
        register_taxonomy('issue', ['post'], [
            'labels' => [
                'name'                          => __('Issue'),
                'singular_name'                 => __('Issue'),
                'menu_name'                     => __('Issues'),
                'all_items'                     => __('All Issues'),
                'edit_item'                     => __('Edit Issue'),
                'view_item'                     => __('View Issue'),
                'update_item'                   => __('Update Issue'),
                'add_new_item'                  => __('Add New Issue'),
                'new_item_name'                 => __('New Issue Name'),
                'parent_item'                   => __('Parent Issue'),
                'parent_item_colon'             => __('Parent Issue:'),
                'search_items'                  => __('Search Issues'),
                'popular_items'                 => __('Popular Issues'),
                'seperate_items_with_commas'    => __('Seperate Issues with commas'),
                'add_or_remove_items'           => __('Add or remove Issues'),
                'choose_from_most_used'         => __('Choose from the most used Issues'),
                'not_found'                     => __('No Issues found.'),
            ],
            'public'                => true,
            'publicly_queryable'    => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'show_in_nav_menus'     => true,
            'show_tagcloud'         => false,
            'show_in_quick_edit'    => true,
            'show_admin_column'     => true,
            'description'           => __('Issue.'),
            'hierarchical'          => true,
            'query_var'             => true,
            'rewrite'               => ['slug' => 'issue', 'with_front' => false],
        ]);

		// make sure Redirects plugin is active
		// if( ! class_exists ( '\Red_Item' ) ) {
		// 	WP_CLI::error( 'Redirection plugin must be active.' );
		// }

		global $wpdb;
		
		$report = [];

		$parent_pages = [
			'BIG BEND SENTINEL' => get_page_by_path( 'issues-archive', OBJECT, 'page'),
			'PRESIDIO INTERNATIONAL' => get_page_by_path( 'issues-archive-presidio-international', OBJECT, 'page'),
		];
		
		$wp_user = get_user_by( 'slug', 'adminnewspack' );

		$pattern = $wpdb->get_var( "select post_content from {$wpdb->posts} where post_type = 'wp_block' and post_status = 'publish' and post_name = 'issue-page-pattern'");

		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

		$this->logger->log( $log , 'Starting conversion of Issues Taxonomy.' );

		$issues = $wpdb->get_results("
			SELECT t.term_id, t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			join {$wpdb->terms} t on t.term_id = tt.term_id
			where tt.taxonomy = 'issue'
			order by slug
		");

        $this->logger->log( $log, 'Found issues: ' . count( $issues ) );

		foreach( $issues as $issue ) {

			$this->logger->log( $log, 'Converting issue: ' . $issue->term_id . '; ' . $issue->name . '; ' . $issue->slug );
			$this->report_add( $report, 'Converting issue.' );

			// get post ids
			$posts = $wpdb->get_results( $wpdb->prepare( "
				SELECT p.ID
				FROM {$wpdb->term_taxonomy} tt
				join {$wpdb->term_relationships} tr on tr.term_taxonomy_id = tt.term_taxonomy_id
				join {$wpdb->posts} p on p.ID = tr.object_id and p.post_type = 'post' and p.post_status = 'publish'
				where tt.taxonomy = 'issue' and tt.term_id = %d
			", $issue->term_id ) );
						
			$this->logger->log( $log, 'Posts in issue: ' . count( $posts ) );

			// skip if no posts
			if( 0 == count( $posts ) ) {
				$this->logger->log( $log, 'Skip: no posts.', $this->logger::WARNING );
				$this->report_add( $report, 'Skip: no posts.' );
				continue;
			}
			
			// split Issue name into date and publication name
			$issue_name_split = array_map( 'strtoupper', array_map( 'trim', preg_split( '/\s/', $issue->name, 2 ) ) );

			// date
			if( empty( $issue_name_split[0] ) || false == preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_name_split[0] ) ) {
				$this->logger->log( $log, 'Skip: split date error.', $this->logger::WARNING );
				$this->report_add( $report, 'Skip: split date error.' );
				continue;
			}

			// publication name
			if( empty( $issue_name_split[1] ) || false == preg_match( '/^(BIG BEND SENTINEL|PRESIDIO INTERNATIONAL)$/', $issue_name_split[1] ) ) {
				$this->logger->log( $log, 'Skip: split publication error.', $this->logger::WARNING );
				$this->report_add( $report, 'Skip: split publication error.' );
				continue;
			}

			// new page title
			$page_title = $issue_name_split[1] . ' - ' . date( "M. d, Y", strtotime( $issue_name_split[0] ) );

			// check if exists
			$existing_page = get_posts(
				array(
					'post_type'              => 'page',
					'title'                  => $page_title,
					'post_status'            => 'publish',
					'numberposts'            => 1,
				)
			);
			
			if ( ! empty( $existing_page ) ) {
				$this->logger->log( $log, 'Skip: page exists.', $this->logger::WARNING );
				$this->report_add( $report, 'Skip: page exists.' );
				continue;
			} 

			// get term meta
			$meta = [
				'issue_date'   => get_term_meta( $issue->term_id, 'issue_date', true ),
				'issue_image'  => get_term_meta( $issue->term_id, 'issue_image', true ),
				'issue_paper'  => get_term_meta( $issue->term_id, 'issue_paper', true ),
				'issue_pdf'    => get_term_meta( $issue->term_id, 'issue_pdf', true ),
				'issue_volume' => get_term_meta( $issue->term_id, 'issue_volume', true ),
			];
			
			// update page content from pattern
			$pattern = str_replace(
				'<p class="has-large-font-size"><strong>Vol. XX No. XX</strong></p>',
				'<p class="has-large-font-size"><strong>' . $meta['issue_volume'] . '</strong></p>',
				$pattern
			);

// "postsToShow":12,"includeSubcategories":false,"typeScale":3}
// "postsToShow":12,"includeSubcategories":false,"tags":["721"],"typeScale":3}


// "postsToShow":1,"includeSubcategories":false,"typeScale":3,"specificMode":true} /-->
// "postsToShow":1,"includeSubcategories":false,"specificPosts":["24410"],"typeScale":3,"specificMode":true} /-->






			// create the page
			$new_page_id = wp_insert_post( [
				'post_author' => $wp_user->ID,
				'post_content' => $pattern,
				'post_date' => $issue_name_split[0],
				'post_parent' => $parent_pages[$issue_name_split[1]]->ID,
				'post_status' => 'publish',
				'post_title' => $page_title,
				'post_type' => 'page',
			], true, );

			// The value 0 or WP_Error on failure.
			var_dump( $new_page_id );


			update_post_meta( $new_page_id, '_wp_page_template', 'single-feature.php' ); // 'One column'


			// get the parent page
			
			exit();



			
			
			print_r($meta);
			continue;


			

			// set the Page title
			// $page_title = 

			// set the new tag
			// https://bigbendsentinel.com/issue/2024-02-15-big-bend-sentinel/


			
			// test that we didn't already do the conversion

			// create a tag
			// add new tag to posts

			// create Page
			// create PDF post

			// add redirect from tag to Page


			
		}

		$this->logger->log( $log, print_r( $report, true ) );

		WP_CLI::success( "Done." );

	}

	public function cmd_convert_people_cpt( $pos_args, $assoc_args ) {

		// register the taxonomy since the old site had this in their theme
        register_taxonomy('people', ['post', 'attachment'], [
            'labels' => [
                'name'                          => __('Post Author'),
                'singular_name'                 => __('Post Author'),
                'menu_name'                     => __('Post Authors'),
                'all_items'                     => __('All Post Authors'),
                'edit_item'                     => __('Edit Post Author'),
                'view_item'                     => __('View Post Author'),
                'update_item'                   => __('Update Post Author'),
                'add_new_item'                  => __('Add New Post Author'),
                'new_item_name'                 => __('New Post Author Name'),
                'parent_item'                   => __('Parent Post Author'),
                'parent_item_colon'             => __('Parent Post Author:'),
                'search_items'                  => __('Search Post Authors'),
                'popular_items'                 => __('Popular Post Authors'),
                'seperate_items_with_commas'    => __('Seperate Post Authors with commas'),
                'add_or_remove_items'           => __('Add or remove Post Authors'),
                'choose_from_most_used'         => __('Choose from the most used Post Authors'),
                'not_found'                     => __('No Post Authors found.'),
            ],
            'public'                => true,
            'publicly_queryable'    => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'show_in_nav_menus'     => true,
            'show_tagcloud'         => false,
            'show_in_quick_edit'    => true,
            'show_admin_column'     => true,
            'description'           => __('Post Author.'),
            'hierarchical'          => true,
            'query_var'             => true,
            'rewrite'               => ['slug' => 'people', 'with_front' => false],
        ]);

		// needs coauthors plus plugin
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		global $wpdb;

		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

        $this->logger->log( $log , 'Starting conversion of People CPT to GAs.' );

		$cache_gas_for_posts = [];

		$people = $wpdb->get_results("
			SELECT tt.term_taxonomy_id, t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			join {$wpdb->terms} t on t.term_id = tt.term_id
			where tt.taxonomy = 'people'
			order by t.name, t.slug
		");

        $this->logger->log( $log, 'Found people: ' . count( $people ) );

		foreach( $people as $person ) {

			$this->logger->log( $log, 'Creating GA for person: ' . $person->name . '; ' . $person->slug );

			// warn if an existing wp user is already using preferred url: /author/user_nicename
			if( get_user_by( 'slug', $person->slug ) ) {
				$this->logger->log( $log, 'WP User slug already exists for person slug.', $this->logger::WARNING );
			}

			// get or create GA
			// note: created GA may have a different (random/unique) user_login (aka slug) then original person->slug
			// note: distinct GAs are based on display name. The same display name but different slugs, will be merged into first created GA's display name
			// examples: jack-copeland-by-jack-copeland, morris-pearl-2, state-senator-cesar-blanco-2
			$ga_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $person->name, 'user_login' => $person->slug ] );

			// save into a cache for later post matching
			$cache_gas_for_posts[$person->name] = $ga_id;

			// get the ga object
			$ga_obj = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );

			// warn if an existing wp user is already using preferred url: /author/user_nicename
			if( get_user_by( 'slug', $ga_obj->user_login ) ) {
				$this->logger->log( $log, 'WP User slug already exists for new ga slug.', $this->logger::WARNING );
			}

			// create a Redirect
			$this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );			
			
			// migrate any usermeta (ACF data) into GA object -- do this by hand - see 1:1/asana notes
			
		}

        $this->logger->log( $log , 'Assigning People CPT posts to GAs.' );

		// select posts where CAP hasn't been set
		$query = new \WP_Query ( [
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'fields'		=> 'ids',
			'tax_query' 	=> [
				// coauthors not set already
				[
					'taxonomy' => 'author',
					'field' => 'slug',
					'operator' => 'NOT EXISTS',
				],
			],
		]);

		$post_ids_count = $query->post_count;

        $this->logger->log( $log , 'Posts found: ' . $post_ids_count );

		foreach ( $query->posts as $key_post_id => $post_id ) {
			
			$this->logger->log( $log , 'Post '. $post_id . ' / ' . ( $key_post_id + 1 ) . ' of ' . $post_ids_count );

			// get display names - this "should" get bylines in correct author order (if multiple)
			// turn off old site plugin: Custom Taxonomy Order
			$terms = wp_get_post_terms( $post_id, 'people', array( 'fields' => 'names' ) );

			// if no terms, assign default GA
			if ( count( $terms ) == 0 ) {
				$terms[0] = 'Big Bend Sentinel';				
			}

			// map display names to GA ids
			$gas_for_post = [];
			foreach( $terms as $term_display_name ) {
				$gas_for_post[] = $cache_gas_for_posts[$term_display_name];
			}
			
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $gas_for_post, $post_id );

		}

		// need to also check if post_type = attachment...

		WP_CLI::success( "Done." );

	}

	/**
	 * REDIRECTION FUNCTIONS
	 */

	private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			if( $verbose ) WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		if( $verbose ) WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . '): ' . $url_from,
			$url_from,
			$url_to
		);

		return;

	}

	/**
	 * REPORTING
	 */
	private function report_add( &$report, $key ) {
		if( empty( $report[$key] ) ) $report[$key] = 0;
		$report[$key]++;
	}
	

}
