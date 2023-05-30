<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for Ithaca Voice.
 */
class DallasExaminerMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator dallas-examiner-extract-by-lines',
			[ $this, 'cmd_migrate_acf_bylines' ],
			[
				'shortdesc' => 'Extract by lines from the begninning of the post content and save them as Guest authors.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Extract by lines from the begninning of the post content and save them as Guest authors
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args CLI assoc arguments.
	 * @return void
	 */
	public function cmd_migrate_acf_bylines( $args, $assoc_args ) {
		global $wpdb;

		global $coauthors_plus;
		if ( ! is_object( $coauthors_plus ) ) {
			echo "Co-Authors Plus plugin not found\n";
			exit;
		}

        $dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];
        $initial_post = ! empty( $assoc_args['initial-post'] ) ? $assoc_args['initial-post'] : 0;

        if ( ! $dry_run ) {
            WP_CLI::line( 'This command will modify the database.');
            WP_CLI::line( 'Consider running it with --dry-run first to see what it will do.');
            WP_CLI::confirm( "Are you sure you want to continue?", $assoc_args );
        }

        if ( ! $initial_post ) {
            WP_CLI::line( 'Are you sure you want to run this to all posts? You can use --initial-post to start from a specific post.');
            WP_CLI::confirm( "Are you sure you want to continue?", $assoc_args );
        }

		// A manually created list of names of the existing authors in the site found in different formats.
		// Key is how the name appears on the post content, values are the actual existing user display names
		$corrected_names = [
			'BENJAMIN F. CHAVIS JR.' => 'Benjamin Chavis',
			'BENJAMIN F. CHAVIS' => 'Benjamin Chavis',
			'DR. BENJAMIN F. CHAVIS JR.' => 'Benjamin Chavis',
			'BEN JEALOUS' => 'BenJealous',
			'E. FAYE WILLIAM' => 'E. FAYE WILLIAMS',
			'GLYNDA CARR' => 'GLYNDA C. CARR',
			'PRESIDENT JOE BIDEN' => 'JOE BIDEN',
			'JOHN E. WARREN' => 'John Warren',
			'DR. JOHN E. WARREN' => 'John Warren',
			'MADISON MICHELLE WILLIAMS' => 'MADISON WILLIAMS',
			'REGINALD BACHUS' => 'Reginald Baccus',
			'DR. SELENA SEABROOKS' => 'Selena Seabrookis',
			'DR SELENA SEABROOKS' => 'Selena Seabrookis',
			'SELENA SEABROOKS' => 'Selena Seabrookis',
			'STEPHANIE MYERS' => 'Stephanie Meyers',
		];

		$posts = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT ID, SUBSTRING_INDEX(post_content, '\n', 5) as post_content FROM $wpdb->posts WHERE post_type = 'post' and SUBSTRING_INDEX(post_content, '\n', 5) like binary '%By%' AND ID >= %d",
                $initial_post
            )
         );

		echo 'Found ', count($posts), " posts\n";

		$users = [];
		$posts_authors = [];
		$posts_not_found = [];

		foreach( $posts as $post ) {
			$content = strip_tags($post->post_content);
			$lines = explode( "\n", $content );
			$found = false;
			$posts_authors[$post->ID] = [];
			foreach( $lines as $line ) {
                $line = htmlentities($line);
                $line = str_replace( '&nbsp;', ' ', $line);
				if ( preg_match( '/By (.+)$/', $line, $matches ) ) {
					$user = $matches[1];
					$user = strtoupper( $user ); // names are already uppercase. but we have AND and "and"
					$coauthors = explode( ' AND ', $user );
					foreach( $coauthors as $author ) {
						$all_authors = explode( ',', $author );
						foreach( $all_authors as $name ) {
							if ( preg_match('/^[A-Z].+/', $name ) && strlen($name) < 34 ) {
								$name = trim($name);
								$name = preg_replace('/[^A-ZÁÉÈÍÓÚÜÑ\. -]/', '', $name); // checked and wont break any names. SOme are already broken with invalid chars.
								$posts_authors[$post->ID][] = $name;
								$users[] = $name;
								$found = true;
							}
						}
					}
				}
			}
			if ( ! $found ) $posts_not_found[] = $post->ID;
		}

		echo 'Found ', count($users), ' author names', "\n";
		echo 'Unable to parse author from ', count($posts_not_found), "posts\n";
		print_r($posts_not_found);
		$unique_users = array_unique($users);
		echo 'Found ', count($unique_users), ' unique author names', "\n";
		echo "Creating users\n";

		$found_users=0;
		$not_found_users=0;
		$user_logins = [];
		foreach( $unique_users as $name ) {
			if ( isset( $corrected_names[$name] ) ) {
				echo $name, ' -> ', $corrected_names[$name], "\n";
				$name = $corrected_names[$name];
			}

			$existing_user_login = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM $wpdb->users WHERE display_name = %s", $name ) );
			if ( $existing_user_login ) {
				$found_users++;
				$user_logins[$name] = $existing_user_login;
			} else {
				$not_found_users++;
				$user_login = sanitize_title($name);
				
				// THIS LINE CREATES THE GUEST AUTHOR
                if ( ! $dry_run ) {
				    $coauthors_plus->guest_authors->create( [ 'display_name' => $name, 'user_login' => $user_login ] );
                }
				
				$user_logins[$name] = $user_login;
			}
		}

		echo 'Found ', $found_users, " existing users\n";
		echo 'Created ', $not_found_users, " Guest Authors\n";
		echo "Adding authors to posts \n";

		foreach ( $posts_authors as $post_id => $authors ) {
			$authors = array_map( function( $name ) use ( $user_logins, $corrected_names ) {
				if ( isset( $corrected_names[$name] ) ) {
					$name = $corrected_names[$name];
				}
				return $user_logins[$name];
			}, $authors );
			echo "Adding authors to post $post_id\n";
			print_r($authors);
			echo "\n";
			
			// THIS LINE ASSIGN AUTHORS TO THE POST
			if ( ! $dry_run ) {
                $coauthors_plus->add_coauthors( $post_id, $authors );
            }
		}
	}

	
}
