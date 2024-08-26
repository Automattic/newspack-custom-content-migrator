<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use \WP_CLI;

/**
 * Custom migration scripts for Ithaca Voice.
 */
class InsightCrimeMigrator implements InterfaceCommand {

    /**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorsPlusHelper $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
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
			'newspack-content-migrator insight-crime-migrate-acf-by-lines',
			[ $this, 'cmd_migrate_acf_bylines' ],
			[
				'shortdesc' => 'Migrate Bylines added via ACF into users and guest authors',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Migrate Bylines added via ACF into users and guest authors
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args CLI assoc arguments.
	 * @return void
	 */
	public function cmd_migrate_acf_bylines( $args, $assoc_args ) {
		global $wpdb, $coauthors_plus;

        $dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];

        if ( ! $dry_run ) {
            WP_CLI::line( 'This command will modify the database.');
            WP_CLI::line( 'Consider running it with --dry-run first to see what it will do.');
            WP_CLI::confirm( "Are you sure you want to continue?", $assoc_args );
        }

        if ( ! $this->coauthorsplus_logic->is_coauthors_active() ) {
            WP_CLI::error( 'Co-Authors Plus plugin is not active.' );
        }

		$query = "select post_id, meta_value from $wpdb->postmeta where meta_key = '_created_by_alias' and meta_value <> '' and meta_value NOT LIKE '--%' and post_id IN ( SELECT ID FROM $wpdb->posts where post_type = 'post' and post_status = 'publish' )";

		$metas = $wpdb->get_results($query);

		$author_names = [];

		$replacements = [
			'*' => '',
            ', and ' => '===',
			', ' => '===',
			'--' => '',
			' Y ' => '===',
			' AND ' => '===',
			' And ' => '===',
			' and ' => '===',
			' y ' => '===',
		];

		foreach( $metas as $meta ) {
            if ( get_post_meta( $meta->post_id, '_created_by_alias_migrated', true ) ) {
                continue;
            }

            WP_CLI::line( 'POST ID: ' . $meta->post_id );
            WP_CLI::line( 'ACF field value: ' . $meta->meta_value );
			$names = $meta->meta_value;
			foreach( $replacements as $search => $replace ) {
				$names = str_replace( $search, $replace, $names );
			}

			$names = explode( '===', $names );
			$names = array_map( function($n) { return trim($n); }, $names );

			$author_names = array_merge( $author_names, $names );

            $coauthors = [];

			foreach( $names as $name ) {
                WP_CLI::line( '- Processing name: ' . $name );
				$user_nicename = $wpdb->get_var( $wpdb->prepare( "SELECT user_nicename FROM $wpdb->users WHERE display_name = LOWER(%s) LIMIT 1", strtolower(trim($name)) ) );
                if ( $user_nicename ) {
                    WP_CLI::line( '-- Found existing user: ' . $user_nicename );
                    $coauthors[] = $user_nicename;
                } else {
                    $nicename = sanitize_title( $name );
                    if ( $dry_run ) {
                        WP_CLI::line( '-- Will create/look for Guest author: ' . $nicename );
                        $coauthors[] = $nicename;
                        continue;
                    }
                    $guest_author_id = $this->coauthorsplus_logic->create_guest_author( [
                        'display_name' => $name,
                        'user_login' => $nicename,
                    ] );
                    if ( is_wp_error( $guest_author_id ) ) {
                        WP_CLI::line( '-- Error creating Guest author: ' . $nicename . ' - ' . $guest_author_id->get_error_message() );
                        continue;
                    }
                    $guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
                    if ( is_object($guest_author) && ! empty( $guest_author->user_nicename ) ) {
                        WP_CLI::line( '-- Found/Created Guest author: ' . $guest_author->user_nicename . ' (ID: ' . $guest_author->ID . ')' );
                        $coauthors[] = $guest_author->user_nicename;
                    }
                }
			}

            if ( ! $dry_run ) {
                $coauthors_plus->add_coauthors( $meta->post_id, $coauthors );
                update_post_meta( $meta->post_id, '_created_by_alias_migrated', 1 );
            }

		}

	}
}
