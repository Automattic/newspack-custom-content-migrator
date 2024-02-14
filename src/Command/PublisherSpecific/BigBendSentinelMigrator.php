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
			'newspack-content-migrator bigbendsentinel-convert-people-cpt',
			[ $this, 'cmd_convert_people_cpt' ],
			[
				'shortdesc' => 'Convert People CPT to Co-Authors Plus and add Redirects.',
			]
		);

	}

	public function cmd_convert_people_cpt( $pos_args, $assoc_args ) {

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
			// ex: jack-copeland-by-jack-copeland, morris-pearl-2, state-senator-cesar-blanco-2
			$ga_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $person->name, 'user_login' => $person->slug ] );

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
				// has people
				[
					'taxonomy' => 'people',
					'field' => 'slug',
					'operator' => 'EXISTS',
				],
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
			
			// $this->logger->log( $log , 'Post '. $post_id . ' / ' . ( $key_post_id + 1 ) . ' of ' . $post_ids_count );

			// will this return them in priority order? what if theme or plugin is removed?
			$terms = wp_get_post_terms( $post_id, 'people', array( 'fields' => 'names' ) );

			if ( count( $terms ) == 0 ) {
				// $this->logger->log( $log , 'Assign Big Bend Sentingl GA' );
				// exit();
			}

			if ( count( $terms ) > 1 ) {
				echo "my counter " . ++$mycounter;
				$this->logger->log( $log , 'Post '. $post_id . ' / ' . ( $key_post_id + 1 ) . ' of ' . $post_ids_count );
				print_r( $terms );
				$this->logger->log( $log , 'Multiple' );
				continue;

			}

			if ( count( $terms ) != 1 ) {
				// $this->logger->log( $log , 'ERROR' );
				// exit();

			}

			// $this->logger->log( $log , 'single' );

		}

		/*

		$this->logger->log( $log, 'Assigning ' . count( $posts ) . ' posts.' );

		foreach( $posts as $post ) {
			// append to existing.
			// since CAP is a new plugin, there wouldn't be any exisiting GAs
			// need to append incase multiple People per post
			// ex: /2024/01/31/la-presentacion-de-candidatos-ya-esta-abierta-para-las-elecciones-del-consejo-municipal-y-de-la-junta-escolar-local/
			$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $ga_id ), $post->ID, true );
		}
		*/

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

}
