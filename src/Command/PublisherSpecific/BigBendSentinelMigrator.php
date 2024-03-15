<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Gravity_Forms\Gravity_Forms\Settings\Fields\Hidden;
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

	private $pdf_post_category_slug = 'issue-pdfs';

	private $live_table_prefix;
	private $original_prefix;


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
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'During Content Diff, custom Taxonomies are only in Live tables.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bigbendsentinel-convert-people-cpt',
			[ $this, 'cmd_convert_people_cpt' ],
			[
				'shortdesc' => 'Convert People CPT to Co-Authors Plus and add Redirects.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'During Content Diff, custom Taxonomies are only in Live tables.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bigbendsentinel-fix-pdf-for-pages',
			[ $this, 'cmd_fix_pdf_for_pages' ],
			[
				'shortdesc' => 'Fix PDF image for Pages.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator bigbendsentinel-reset-pdf-post-category',
			[ $this, 'cmd_reset_pdf_post_category' ],
			[
				'shortdesc' => 'Resets all PDF Post\'s category to only "Issue PDFs".',
			]
		);

	}
	
	public function cmd_convert_issues_cpt( $pos_args, $assoc_args ) {

		global $wpdb;
		
		if( isset( $assoc_args['live-table-prefix'] ) ) {

			$live_table_prefix_regex = '/^[0-9A-Za-z_]+$/';
			
			if( ! preg_match( $live_table_prefix_regex, $assoc_args['live-table-prefix'] ) ) {
				WP_CLI::error( 'Live table prefix must match: ' . $live_table_prefix_regex );
			}
			
			$this->original_prefix = $wpdb->get_blog_prefix();

			$this->live_table_prefix = $assoc_args['live-table-prefix'];

			WP_CLI::line( 'Using live table prefix: ' . $this->live_table_prefix );

		}

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
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		// set patterns
		
		$pattern_for_page = $wpdb->get_var(
			"select post_content from {$wpdb->posts} where post_type = 'wp_block' and post_status = 'publish' and post_name = 'issue-page-pattern'"
		);

		if( ! is_string( $pattern_for_page ) || 0 == strlen( trim( $pattern_for_page ) ) ) {
			WP_CLI::error( 'Issue Page Pattern (wp_block) is required.' );
		}

		$pattern_for_pdf_post = $wpdb->get_var(
			"select post_content from {$wpdb->posts} where post_type = 'wp_block' and post_status = 'publish' and post_name = 'issue-pdf-post-pattern'"
		);

		if( ! is_string( $pattern_for_pdf_post ) || 0 == strlen( trim( $pattern_for_pdf_post ) ) ) {
			WP_CLI::error( 'Issue PDF Post Pattern (wp_block) is required.' );
		}
					
					
		$report = [];

		$parent_pages = [
			'BIG BEND SENTINEL' => get_page_by_path( 'issues-archive', OBJECT, 'page'),
			'PRESIDIO INTERNATIONAL' => get_page_by_path( 'issues-archive-presidio-international', OBJECT, 'page'),
		];
		
		$wp_user = get_user_by( 'slug', 'bigbendsentinel' );

		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

		$this->logger->log( $log , 'Starting conversion of Issues Taxonomy.' );

		if( $this->live_table_prefix ) $wpdb->set_prefix( $this->live_table_prefix );

		$issues = $wpdb->get_results("
			SELECT t.term_id, t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			join {$wpdb->terms} t on t.term_id = tt.term_id
			where tt.taxonomy = 'issue'
			order by slug
		");

		if( $this->live_table_prefix ) $wpdb->set_prefix( $this->original_prefix );

        $this->logger->log( $log, 'Found issues: ' . count( $issues ) );

		foreach( $issues as $issue ) {

			$this->logger->log( $log, 'Converting issue: ' . $issue->term_id . '; ' . $issue->name . '; ' . $issue->slug );
			$this->report_add( $report, 'Converting issue.' );

			if( $this->live_table_prefix ) $wpdb->set_prefix( $this->live_table_prefix );
	
			// get post ids
			$posts = $wpdb->get_results( $wpdb->prepare( "
				SELECT p.ID
				FROM {$wpdb->term_taxonomy} tt
				join {$wpdb->term_relationships} tr on tr.term_taxonomy_id = tt.term_taxonomy_id
				join {$wpdb->posts} p on p.ID = tr.object_id and p.post_type = 'post' and p.post_status = 'publish'
				where tt.taxonomy = 'issue' and tt.term_id = %d
			", $issue->term_id ) );
						
			if( $this->live_table_prefix ) $wpdb->set_prefix( $this->original_prefix );

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
			$this->logger->log( $log, 'Page title: ' . $page_title );

			// check if Page arleady exists (ie: the code below was already run)
			$existing_page = get_posts(
				array(
					'post_type'              => 'page',
					'title'                  => $page_title,
					'post_status'            => 'publish',
					'numberposts'            => 1,
				)
			);
			
			if ( ! empty( $existing_page ) ) {
				$this->logger->log( $log, 'Skip: Page already exists.', $this->logger::WARNING );
				$this->report_add( $report, 'Skip: Page already exists.' );
				continue;
			} 

			// get or create tag
			$tag_term = term_exists( $issue->name, 'post_tag' );
			if( is_wp_error( $tag_term ) || empty( $tag_term ) ) {
				$tag_term = wp_insert_term( $issue->name, 'post_tag' );
			}

			// add tag to posts
			foreach( $posts as $post ) {

				$post_id_for_add_post_tags = $post->ID;

				if( $this->live_table_prefix ) {

					$post_id_for_add_post_tags = $wpdb->get_var( $wpdb->prepare("
						SELECT post_id FROM {$wpdb->postmeta} where meta_key = 'newspackcontentdiff_live_id' and meta_value = %d
						", $post->ID
					));

				}
		
				wp_add_post_tags( (int) $post_id_for_add_post_tags, $issue->name );
							
			}

			// get term meta for volume
			if( $this->live_table_prefix ) $wpdb->set_prefix( $this->live_table_prefix );

			$issue_volume = get_term_meta( $issue->term_id, 'issue_volume', true );

			if( $this->live_table_prefix ) $wpdb->set_prefix( $this->original_prefix );
	
			// get or create PDF post
			// NOTE: PDF Post and Issue Page share the same Title
			$pdf_post_id = $this->get_or_create_pdf_post( $page_title, $issue, $issue_name_split[0], $pattern_for_pdf_post, $wp_user, $log, $report );

			// set Page content from pattern
			$page_pattern = $pattern_for_page;
	
			$page_pattern = str_replace( 'Vol. XX No. XX', $issue_volume, $page_pattern );

			$page_pattern = str_replace( 
				'"postsToShow":12,"includeSubcategories":false,',
				'"postsToShow":12,"includeSubcategories":false,"tags":["' . $tag_term['term_id']. '"],',
				$page_pattern
			);

			// set pdf Post ID if exists
			if( ! is_wp_error( $pdf_post_id ) && is_numeric( $pdf_post_id ) && $pdf_post_id > 0 ) {
				
				$page_pattern = str_replace( 
					'"postsToShow":1,"includeSubcategories":false,',
					'"postsToShow":1,"includeSubcategories":false,"specificPosts":["' . $pdf_post_id . '"],',
					$page_pattern
				);

			}
			// else Remove PDF block
			else {

				$page_pattern = str_replace( 
					'<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"showDate":false,"showAuthor":false,"postsToShow":1,"includeSubcategories":false,"typeScale":3,"specificMode":true} /-->',
					'',
					$page_pattern
				);

				$this->logger->log( $log, 'FYI: PDF block removed from Page.' );
				$this->report_add( $report, 'FYI: PDF block removed from Page.' );

			}
			
			// create the page
			$new_page_id = wp_insert_post( [
				'post_author' => $wp_user->ID,
				'post_content' => $page_pattern,
				'post_date' => $issue_name_split[0],
				'post_parent' => $parent_pages[$issue_name_split[1]]->ID,
				'post_status' => 'publish',
				'post_title' => $page_title,
				'post_type' => 'page',
			], true, );

			if( is_wp_error( $new_page_id ) || ! ( $new_page_id > 0) ) {
				$this->logger->log( $log, 'Page insert failed.', $this->logger::WARNING );
				$this->report_add( $report, 'Page insert failed..' );
				continue;
			}
	
			update_post_meta( $new_page_id, '_wp_page_template', 'single-feature.php' ); // 'One column'

			// set featured image thumbnail to the same as pdf post featured
			if( ! is_wp_error( $pdf_post_id ) && is_numeric( $pdf_post_id ) && $pdf_post_id > 0 ) {
				
				$pdf_post_thumb_id = get_post_meta( $pdf_post_id, '_thumbnail_id', true );
				
				if( is_numeric( $pdf_post_thumb_id ) && $pdf_post_thumb_id > 0 ) {				
					
					update_post_meta( $new_page_id, '_thumbnail_id', $pdf_post_thumb_id );
					update_post_meta( $new_page_id, 'newspack_featured_image_position', 'hidden' ); // don't show at top of Page

				}

			} // featured image


			// set redirect from old Issues Custom Taxonomy urls
			$this->set_redirect( 
				'/issue/' . $issue->slug, 
				str_replace( get_site_url() , '', get_permalink( $new_page_id ) ), 
				'issues custom taxonomy', 
			);
			
			// set redirect from new tag to Page instead
			$this->set_redirect( 
				str_replace( get_site_url() , '', get_tag_link( $tag_term['term_id'] ) ), 
				str_replace( get_site_url() , '', get_permalink( $new_page_id ) ),
				'issues tag to page', 
			);
			
		}

		$this->logger->log( $log, print_r( $report, true ) );

		WP_CLI::success( "Done." );

	}

	public function cmd_convert_people_cpt( $pos_args, $assoc_args ) {

		$live_table_prefix = null;
		$original_prefix = null;
		$original_post_id = null;

		if( isset( $assoc_args['live-table-prefix'] ) ) {

			$live_table_prefix_regex = '/^[0-9A-Za-z_]+$/';
			
			if( ! preg_match( $live_table_prefix_regex, $assoc_args['live-table-prefix'] ) ) {
				WP_CLI::error( 'Live table prefix must match: ' . $live_table_prefix_regex );
			}
			
			$live_table_prefix = $assoc_args['live-table-prefix'];
			WP_CLI::line( 'Using live table prefix: ' . $live_table_prefix );

		}

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

		if( $live_table_prefix ) {
			$original_prefix = $wpdb->get_blog_prefix();
			$wpdb->set_prefix( $live_table_prefix );
		}

		$people = $wpdb->get_results("
			SELECT tt.term_taxonomy_id, t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			join {$wpdb->terms} t on t.term_id = tt.term_id
			where tt.taxonomy = 'people'
			order by t.name, t.slug
		");

		if( $live_table_prefix ) {
			$wpdb->set_prefix( $original_prefix );
		}

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

			$maybe_live_post_id = get_post_meta( $post_id, 'newspackcontentdiff_live_id', true );

			if( $live_table_prefix ) {
				
				$original_post_id = $post_id;
				$post_id = $maybe_live_post_id;

				$original_prefix = $wpdb->get_blog_prefix();
				$wpdb->set_prefix( $live_table_prefix );

			}
	
			$terms = wp_get_post_terms( $post_id, 'people', array( 'fields' => 'names' ) );

			if( $live_table_prefix ) {
				
				$post_id = $original_post_id;
				$wpdb->set_prefix( $original_prefix );

			}

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

	public function cmd_fix_pdf_for_pages( $pos_args, $assoc_args ) {

		global $wpdb;

		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

        $this->logger->log( $log , 'Starting ...' );

		$parent_1 = get_page_by_path( 'issues-archive', OBJECT, 'page' );
		$parent_2 = get_page_by_path( 'issues-archive-presidio-international', OBJECT, 'page' );

		// select Pages where featured image thumbnail is not set
		$query = new \WP_Query ( [
			'posts_per_page' => -1,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_parent__in' => array( $parent_1->ID, $parent_2->ID ),
			'fields'		=> 'ids',
			'meta_query' 	=> [
				[
					'key' => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				],
			],
		]);

        $this->logger->log( $log , 'Pages found: ' . $query->post_count );

		foreach ( $query->posts as $post_id ) {

			$this->logger->log( $log , 'Page ID:' . $post_id );

			// get block content
			$post = get_post( $post_id );
			
			if( ! preg_match( '/"postsToShow":1,"includeSubcategories":false,"specificPosts":\["(\d+)"\]/', $post->post_content, $matches ) ) {
				$this->logger->log( $log, 'No PDF Post.', $this->logger::WARNING );
				continue;
			}

			if( ! isset( $matches[1] ) || ! is_numeric( $matches[1] ) || ! ( $matches[1] > 0 ) ) {
				$this->logger->log( $log, 'PDF Post id malformed.', $this->logger::WARNING );
				continue;
			}

			$pdf_post_thumb_id = get_post_meta( $matches[1], '_thumbnail_id', true );

			if( ! is_numeric( $pdf_post_thumb_id ) || ! ( $pdf_post_thumb_id > 0 ) ) {
				$this->logger->log( $log, 'PDF Post has no thumbnail.', $this->logger::WARNING );
				continue;

			}
			
			update_post_meta( $post_id, '_thumbnail_id', $pdf_post_thumb_id );
			update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' ); // don't show at top of Page

			$this->logger->log( $log, 'Thumbnail set.', $this->logger::SUCCESS );

		}

		$this->logger->log( $log, 'Done.', $this->logger::SUCCESS );

	}

	public function cmd_reset_pdf_post_category( $pos_args, $assoc_args ) {

		global $wpdb;

		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

        $this->logger->log( $log , 'Starting ...' );

		// get post with PDF Post block pattern
		$posts = $wpdb->get_results("
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'post'
			AND post_content like '%<!-- wp:file {%wp-block-file%>Download</a></div>%<!-- /wp:file -->%'
		");

		$this->logger->log( $log, 'Posts found: ' . count( $posts ) );
		
		$primary_term_ids = term_exists( $this->pdf_post_category_slug, 'category' );

		foreach( $posts as $post ) {
			
			$this->logger->log( $log , 'Post ID:' . $post->ID );
			
			$existing_cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );

			if( ! is_array( $existing_cats ) || 1 != count( $existing_cats ) || ! isset( $existing_cats[0]->slug ) ) {
				$this->logger->log( $log , 'Skip: single post category mismatch.', $this->logger::WARNING );
				continue;
			}

			if( $this->pdf_post_category_slug == $existing_cats[0]->slug ) {
				$this->logger->log( $log , 'Skip: post was already reset.' );
				continue;
			}

			if( 'news' != $existing_cats[0]->slug ) {
				$this->logger->log( $log , 'Skip: post categories do not match only news.', $this->logger::WARNING );
				continue;
			}

			wp_set_post_categories( $post->ID, $primary_term_ids['term_id'] );

		}

		WP_CLI::success( "Done." );

	}


	/**
	 * ISSUES
	 */

	private function get_or_create_pdf_post( $page_title, $issue, $issue_date, $pdf_pattern, $wp_user, $log, &$report ) {

		global $wpdb;

		// if post already exists, return it's id
		// NOTE: Issue Page and PDF Post have same title
		$existing_post = get_posts([
				'title'                  => $page_title,
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'numberposts'            => 1,
		]);
		
		if ( ! empty( $existing_post ) ) {
			$this->logger->log( $log, 'FYI: PDF Post already exists.' );
			$this->report_add( $report, 'FYI: PDF Post already exists.' );
			return $existing_post[0]->ID;
		} 

		// get PDF file attachment

		if( $this->live_table_prefix ) $wpdb->set_prefix( $this->live_table_prefix );

		$pdf_attachment_id = get_term_meta( $issue->term_id, 'issue_pdf', true );

		if( $this->live_table_prefix ) {
			
			$wpdb->set_prefix( $this->original_prefix );

			$pdf_attachment_id = $wpdb->get_var( $wpdb->prepare("
				SELECT post_id FROM {$wpdb->postmeta} where meta_key = 'newspackcontentdiff_live_id' and meta_value = %d
				", $pdf_attachment_id
			));
			
		}

		if ( empty( $pdf_attachment_id ) || false == is_numeric( $pdf_attachment_id ) || ! ( $pdf_attachment_id > 0 ) ) {
			$this->logger->log( $log, 'FYI: PDF attachment ID not gt 0.' );
			$this->report_add( $report, 'FYI: PDF attachment ID not gt 0.' );
			return false;
		} 

		$pdf_attachment = get_posts([
				'post_type'          => 'attachment',
				'include'            => $pdf_attachment_id,
				'numberposts'        => 1,
		]);
		
		if ( empty( $pdf_attachment ) ) {
			$this->logger->log( $log, 'FYI: PDF attachment not exist.' );
			$this->report_add( $report, 'FYI: PDF attachment not exist.' );
			return false;
		} 

		// update pattern for content
		$pdf_pattern = str_replace( 'wp:file {"id":22408', 'wp:file {"id":' . $pdf_attachment_id, $pdf_pattern );
		$pdf_pattern = str_replace( '08-03-23 bbs for web', esc_attr( $pdf_attachment[0]->post_title ), $pdf_pattern );
		$pdf_pattern = preg_replace(
			'#https://[^/]+/wp-content/uploads/2023/08/08-03-23-bbs-for-web.pdf#',
			wp_get_attachment_url( $pdf_attachment_id ),
			$pdf_pattern
		);

		// create the post
		$new_pdf_post_id = wp_insert_post( [
			'post_author' => $wp_user->ID,
			'post_content' => $pdf_pattern,
			'post_date' => $issue_date,
			'post_status' => 'publish',
			'post_title' => $page_title,
			'tags_input' => array( $issue->name ),
		], true, );
		
		if( is_wp_error( $new_pdf_post_id ) || ! ( $new_pdf_post_id > 0) ) {
			$this->logger->log( $log, 'PDF Post insert failed.', $this->logger::WARNING );
			$this->report_add( $report, 'PDF Post insert failed..' );
			return false;
		}

		update_post_meta( $new_pdf_post_id, '_wp_page_template', 'single-feature.php' ); // 'One column'

		// set PDF image only if exists
		if( $this->live_table_prefix ) $wpdb->set_prefix( $this->live_table_prefix );

		$pdf_featured_image_id = get_term_meta( $issue->term_id, 'issue_image', true );

		if( $this->live_table_prefix ) {
			
			$wpdb->set_prefix( $this->original_prefix );

			$pdf_featured_image_id = $wpdb->get_var( $wpdb->prepare("
				SELECT post_id FROM {$wpdb->postmeta} where meta_key = 'newspackcontentdiff_live_id' and meta_value = %d
				", $pdf_featured_image_id
			));
			
		}

		if ( empty( $pdf_featured_image_id ) || false == is_numeric( $pdf_featured_image_id ) || ! ( $pdf_featured_image_id > 0 ) ) {
			$this->logger->log( $log, 'FYI: PDF image ID not gt 0.' );
			$this->report_add( $report, 'FYI: PDF image ID not gt 0.' );
			return $new_pdf_post_id;
		} 

		$pdf_featured_image = get_posts([
				'post_type'          => 'attachment',
				'include'            => $pdf_featured_image_id,
				'numberposts'        => 1,
		]);
		
		if ( empty( $pdf_featured_image ) ) {
			$this->logger->log( $log, 'FYI: PDF had no featured image.' );
			$this->report_add( $report, 'FYI: PDF had no featured image.' );
			return $new_pdf_post_id;
		} 

		set_post_thumbnail( $new_pdf_post_id, $pdf_featured_image_id );
		update_post_meta( $new_pdf_post_id, 'newspack_featured_image_position', 'hidden' ); // don't show at top of Post

		// set category to only 'Issue PDFs'
		$primary_term_ids = term_exists( $this->pdf_post_category_slug, 'category' );
		if( isset( $primary_term_ids['term_id'] ) ) {
			wp_set_post_categories( $new_pdf_post_id, $primary_term_ids['term_id'] );
		}

		return $new_pdf_post_id;
		
	}


	/**
	 * REDIRECTION FUNCTIONS
	 */

	private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			if( $verbose ) WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		if( $verbose ) WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . ')',
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
