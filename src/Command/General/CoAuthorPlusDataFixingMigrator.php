<?php

namespace NewspackCustomContentMigrator\Command\General;

use cli\Streams;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use NewspackCustomContentMigrator\Logic\CoAuthorPlusDataFixer;
use NewspackCustomContentMigrator\Logic\ConsoleOutput\Posts;
use NewspackCustomContentMigrator\Logic\ConsoleOutput\Taxonomy;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Error;

/**
 * This class will help you fix your CAP woes.
 */
class CoAuthorPlusDataFixingMigrator implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Co-Authors Plus.
	 *
	 * @var CoAuthorsPlusHelper $co_authors_plus_logic CoAuthors Plus logic.
	 */
	private CoAuthorsPlusHelper $co_authors_plus_logic;

	/**
	 * Co-Authors Plus data fixing logic.
	 *
	 * @var CoAuthorPlusDataFixer $co_authors_plus_data_fixer_logic CoAuthors Plus logic.
	 */
	private CoAuthorPlusDataFixer $co_authors_plus_data_fixer_logic;

	/**
	 * Custom logic and console output for posts.
	 *
	 * @var Posts $posts_logic Posts logic.
	 */
	private Posts $posts_logic;

	/**
	 * Taxonomy logic.
	 *
	 * @var Taxonomy $taxonomy_logic Taxonomy logic.
	 */
	private Taxonomy $taxonomy_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->co_authors_plus_data_fixer_logic = new CoAuthorPlusDataFixer();
		$this->co_authors_plus_logic            = new CoAuthorsPlusHelper();
		$this->posts_logic                      = new Posts();
		$this->taxonomy_logic                   = new Taxonomy();
	}

	/**
	 * Creates an instance of the class.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();

		}

		return self::$instance;
	}

	/**
	 * Command registration.
	 *
	 * @see InterfaceCommand::register_commands.
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator fix-co-authors-data-set-standalone-guest-author-data',
			[ $this, 'cmd_set_standalone_guest_author_data' ],
			[
				'shortdesc' => 'Fixes data for a Standalone Guest Author (i.e. one that is NOT linked to a WP_User)',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'guest-author-id',
						'description' => 'Guest Author ID, (wp_posts.ID)',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'term-id',
						'description' => 'Term ID, (wp_terms.term_id) that links to a taxonomy, which may need linking to the GA',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * This function will correct all data associated with a given Guest Author, including their Term data.
	 * This Guest Author MUST NOT be one that is connected to a WP_User. Use this function only when
	 * you are 100% sure that you'd like to connect a Guest Author and provided Term ID. If you
	 * are unsure, first use the `newspack-content-migrator co-authors-validate-guest-author-data` command.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws WP_CLI\ExitException Script halts whenever it detects data that is not valid.
	 */
	public function cmd_set_standalone_guest_author_data( array $args, array $assoc_args ): void {
		$guest_author_id = $assoc_args['guest-author-id'];
		$term_id         = $assoc_args['term-id'];

		if ( ! $this->co_authors_plus_data_fixer_logic->is_guest_author_standalone( $guest_author_id ) ) {
			ConsoleColor::red( 'This is not a Standalone Guest Author' )->output();
			WP_CLI::halt( 1 );
		}

		global $wpdb;

		$term = $this->taxonomy_logic->get_term_record( $term_id );

		if ( null === $term ) {
			ConsoleColor::red( "No $wpdb->terms record found for term_id:" )->white( $term_id )->output();
			WP_CLI::halt( 1 );
		}

		$author_term_taxonomy = $this->co_authors_plus_data_fixer_logic->get_guest_author_taxonomy( $term_id );

		if ( count( $author_term_taxonomy ) > 1 ) {
			// TODO Guest-Author-Validation-Refactor - We could prompt to see which record should be used, and whether the other ones should be eliminated.
			$this->taxonomy_logic->output_term_taxonomy_table(
				array_map( fn( $row ) => $row->term_taxonomy_id, $author_term_taxonomy ),
				ConsoleColor::red( 'More than one author term-taxonomy found for term_id:' )->white( $term_id )->get()
			);
			WP_CLI::halt( 1 );
		}

		if ( empty( $author_term_taxonomy ) ) {
			// Here we know that there is no wp_term_taxonomy row that is linked to the given term_id.
			$result = $this->co_authors_plus_data_fixer_logic->attempt_to_relate_standalone_guest_author_and_term(
				$guest_author_id,
				$term_id
			);

			if ( is_numeric( $result ) ) {
				$term->term_taxonomy_id = $result;
				$author_term_taxonomy   = [ $term ];
			} elseif ( $result instanceof WP_Error ) {
				if ( 'existing_slugs' === $result->get_error_code() ) {
					ConsoleTable::output_data(
						$result->get_error_data()['existing_slugs'],
						[],
						ConsoleColor::yellow( 'This slug is already being used by other terms:' )
									->underlined_bright_white( $result->get_error_data()['slug'] )
									->get()
					);
				} elseif ( 'multiple-author-taxonomies' === $result->get_error_code() ) {
					$this->taxonomy_logic->output_term_taxonomy_table(
						$result->get_error_data()['author_taxonomy_ids'],
						ConsoleColor::title( 'Author Taxonomies' )
									->white( '(' )
									->bright_white( $result->get_error_data()['term_id'] )
									->white( ')' )
									->get()
					);
				} elseif ( 'insert-failed' === $result->get_error_code() ) {
					ConsoleColor::red( 'Unable to insert new wp_term_taxonomy record.' )->output();
					ConsoleTable::output_comparison(
						[],
						$result->get_error_data()['insert_data']
					);
				} else {
					ConsoleColor::red( $result->get_error_message() )->output();
				}
			} else {
				ConsoleColor::red( 'Unable to relate Guest Author to Term.' )->output();
				WP_CLI::halt( 1 );
			}
		}

		$this->set_standalone_guest_author_data( $guest_author_id, $author_term_taxonomy[0] );
	}

	/**
	 * This function will correct all data associated with a given Guest Author, including their Term data. This Guest
	 * Author MUST NOT be one that is connected to a WP_User. Use this function only when you are 100% sure that
	 * you'd like to connect a Guest Author and Term ID. Reference the `@see` tag below if you're not.
	 *
	 * @param int    $guest_author_id WP_Post ID of the Guest Author.
	 * @param object $term Term object.
	 *
	 * @return void
	 * @throws WP_CLI\ExitException Stops execution on invalid input, or if user wants to stop.
	 *
	 * @see CoAuthorPlusDataFixer::is_guest_author_standalone() to give you a confidence check, and if you
	 * want to play it safe, first use the `newspack-content-migrator co-authors-validate-guest-author-data` command.
	 */
	public function set_standalone_guest_author_data( int $guest_author_id, object $term ): void {
		$this->posts_logic->output_table( [ $guest_author_id ], [], 'Guest Author Record' );
		$post_guest_author = get_post( $guest_author_id );
		$this->taxonomy_logic->output_term_and_term_taxonomy_table( [ $term->term_id ], Taxonomy::TERM_ID, 'Author Term' );
		$filtered_author_cap_fields = $this->co_authors_plus_data_fixer_logic->get_filtered_cap_fields(
			$guest_author_id,
			[
				'cap-user_login',
				'cap-user_email',
				'cap-linked_account',
				'cap-display_name',
			]
		);

		ConsoleColor::title_output( 'Post Meta Fields' );
		ConsoleTable::output_comparison( [], $filtered_author_cap_fields );

		// TODO check to see if there are multiple cap-user_logins.

		// Do I have a display name? If not, then I need to ask for one. If I do, confirm that it's ok to use that one.
		$display_name = $this->get_or_prompt_for_field(
			'display_name',
			$filtered_author_cap_fields['cap-display_name'] ?? null
		);
		// If multiple CAP Display Name were found, they might have been deleted at this point.
		// So we'll get the values from the DB again.
		$filtered_author_cap_fields['cap-display_name'] =
			$this->co_authors_plus_data_fixer_logic->get_filtered_cap_fields( $guest_author_id, [ 'cap-display_name' ] )['cap-display_name'];
		$sanitized_display_name                         = sanitize_title( $display_name );

		$prompt_for_user_login = true;
		if ( isset( $filtered_author_cap_fields['cap-user_login'] ) && is_string( $filtered_author_cap_fields['cap-user_login'] ) ) {
			// Skip the prompt for checking if basis for user_login is ok if $sanitized_display_name already matches the cap-user_login.
			$needles               = [
				"cap-$sanitized_display_name",
				$sanitized_display_name,
			];
			$prompt_for_user_login = ! empty( str_replace( $needles, '', $filtered_author_cap_fields['cap-user_login'] ) );
		}

		$slug_login_basis = $sanitized_display_name;
		$user_login       = "cap-$slug_login_basis";

		if ( ! $prompt_for_user_login && is_email( $filtered_author_cap_fields['cap-user_login'] ) ) {
			$slug_login_basis      = substr(
				$filtered_author_cap_fields['cap-user_login'],
				0,
				strpos( $filtered_author_cap_fields['cap-user_login'], '@' )
			);
			$prompt_for_user_login = true;
		}

		if ( $prompt_for_user_login ) {
			$prompt_msg                               = 'Use \'' . ConsoleColor::bright_white( $slug_login_basis )->get() . '\' as basis for cap-user_login and slug? (y/n)';
			$use_sanitized_display_name_as_user_login = Streams::prompt( $prompt_msg, 'y' );

			if ( 'n' === $use_sanitized_display_name_as_user_login ) {
				$user_login = Streams::prompt( 'Ok, please enter a new user_login' );
				$user_login = sanitize_title( strtolower( $user_login ) );

				if ( empty( $user_login ) || is_email( $user_login ) ) {
					WP_CLI::halt( 1 );
				}

				$user_login = "cap-$user_login";
			} else {
				$user_login = "cap-$slug_login_basis";
			}
		}

		// Does $user_login match any existing cap-user_login records or any existing wp_user.user_nicename records?
		$user_login = $this->prompt_for_unique_user_login( $user_login, $guest_author_id );

		ConsoleColor::title_output( 'Proposed vs Current CAP User Login and Display Name' );
		$left       = 'Proposed Values';
		$right      = 'Existing CAP Values';
		$comparison = ConsoleTable::output_value_comparison(
			[
				'cap-user_login',
				'cap-display_name',
			],
			[
				'cap-user_login'   => $user_login,
				'cap-display_name' => $display_name,
			],
			[
				'cap-user_login'   => $filtered_author_cap_fields['cap-user_login'] ?? '',
				'cap-display_name' => $filtered_author_cap_fields['cap-display_name'] ?? '',
			],
			true,
			$left,
			$right
		);

		global $wpdb;

		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// Updating $key from $value[ $right ] to $value[ $left ].
				ConsoleColor::white( 'Updating' )
							->bright_white( "$key" )
							->from( 'from' )
							->bright_cyan( $value[ $right ] )
							->white( 'to' )
							->underlined_bright_green( $value[ $left ] )
							->output();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$update = $wpdb->update(
					$wpdb->postmeta,
					[
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'meta_value' => $value[ $left ],
					],
					[
						'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'post_id'  => $guest_author_id,
					]
				);

				if ( $update ) {
					$filtered_author_cap_fields[ $key ] = $value[ $left ];
				} else {
					ConsoleColor::red( 'Unable to update' )->white( $key )->output();

					if ( 'n' === Streams::prompt( 'Continue with script execution? (y/n)', 'n' ) ) {
						WP_CLI::halt( 1 );
					}
				}
			}
		}

		ConsoleColor::title_output( 'Name and Title vs WP_Post Name and Title' );
		$comparison = ConsoleTable::output_value_comparison(
			[
				'post_name',
				'post_title',
			],
			[
				'post_name'  => $filtered_author_cap_fields['cap-user_login'],
				'post_title' => $display_name,
			],
			[
				'post_name'  => $post_guest_author->post_name,
				'post_title' => $post_guest_author->post_title,
			]
		);

		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// Updating $key from $value[ $right ] to $value[ $left ].
				ConsoleColor::white( 'Updating' )
							->bright_white( "$key" )
							->white( 'from' )
							->bright_cyan( $value['RIGHT'] )
							->white( 'to' )
							->underlined_bright_green( $value['LEFT'] )
							->output();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$update = $wpdb->update(
					$wpdb->posts,
					[
						$key => $value['LEFT'],
					],
					[
						'id' => $guest_author_id,
					]
				);

				if ( $update ) {
					$post_guest_author->$key = $value['LEFT'];
				} else {
					ConsoleColor::red( 'Unable to update' )->white( $key )->output();

					if ( 'n' === Streams::prompt( 'Continue with script execution? (y/n)', 'n' ) ) {
						WP_CLI::halt( 1 );
					}
				}
			}
		}

		ConsoleColor::title_output( 'CAP Display Name and Login vs Author Term' );
		$comparison = ConsoleTable::output_value_comparison(
			[
				'name',
				'slug',
			],
			[
				'name' => $post_guest_author->post_title,
				'slug' => $filtered_author_cap_fields['cap-user_login'],
			],
			[
				'name' => $term->name,
				'slug' => $term->slug,
			]
		);

		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				// Updating $key from $value[ $right ] to $value[ $left ].
				ConsoleColor::white( 'Updating' )
							->bright_white( "$key" )
							->white( 'from' )
							->bright_cyan( $value['RIGHT'] )
							->white( 'to' )
							->underlined_bright_green( $value['LEFT'] )
							->output();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$update = $wpdb->update(
					$wpdb->terms,
					[
						$key => $value['LEFT'],
					],
					[
						'term_id' => $term->term_id,
					]
				);

				if ( $update ) {
					$term->$key = $value['LEFT'];
				} else {
					ConsoleColor::red( 'Unable to update' )->white( $key )->output();

					if ( 'n' === Streams::prompt( 'Continue with script execution? (y/n)', 'n' ) ) {
						WP_CLI::halt( 1 );
					}
				}
			}
		}

		wp_cache_flush();

		$current_description = $term->description;
		$description_result  = $this->co_authors_plus_data_fixer_logic->update_author_term_description(
			$this->co_authors_plus_logic->get_guest_author_by_id( $guest_author_id ),
			$term
		);

		if ( null !== $description_result ) {
			if ( $description_result ) {
				// Updating wp_term_taxonomy.description from $current_description to (updated) $term->description.
				ConsoleColor::white( 'Updated' )
							->bright_white( 'wp_term_taxonomy.description' )
							->white( 'from' )
							->bright_cyan( $current_description )
							->white( 'to' )
							->underlined_bright_green( $term->description )
							->output();
			} else {
				ConsoleColor::red( 'Unable to update wp_term_taxonomy.description.' )->output();
			}
		}

		$relationship_result = $this->taxonomy_logic->insert_relationship_if_not_exists( $guest_author_id, $term->term_taxonomy_id );

		if ( null !== $relationship_result ) {
			if ( $relationship_result ) {
				ConsoleColor::yellow( 'wp_term_relationship record did not exist, so one was created (object_id:' )
							->bright_yellow( $guest_author_id )
							->yellow( ', term_taxonomy_id:' )
							->bright_yellow( $term->term_taxonomy_id )
							->yellow( ')' )
							->output();
			} else {
				ConsoleColor::red( 'Unable to create wp_term_relationship record.' )->output();
			}
		}
	}

	/**
	 * Use this function to ensure you are getting a wholly unique user_login. It can be used for either a WP_User
	 * or a Guest Author. It will prompt the user for a new user_login if the one they entered already exists.
	 *
	 * @param string $user_login The user_login to check.
	 * @param int    $exclude_guest_author_id The ID of the Guest Author to exclude from the check.
	 * @param int    $exclude_user_id The ID of the WP_User to exclude from the check.
	 *
	 * @return string
	 * @throws WP_CLI\ExitException Script halts whenever it detects data that is not valid.
	 */
	public function prompt_for_unique_user_login( string $user_login, int $exclude_guest_author_id = 0, int $exclude_user_id = 0 ): string {
		$capless_user_login = str_replace( 'cap-', '', $user_login );
		$capped_user_login  = 'cap-' . $capless_user_login;

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists_in_users_table = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, user_login, user_nicename FROM $wpdb->users WHERE ID != %d AND (user_login = %s OR user_nicename = %s)",
				$exclude_user_id,
				$capless_user_login,
				$capless_user_login
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists_in_postmeta_table = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id != %d AND meta_key = 'cap-user_login' AND meta_value IN (%s, %s)",
				$exclude_guest_author_id,
				$capless_user_login,
				$capped_user_login
			)
		);

		if ( ! empty( $exists_in_users_table ) || ! empty( $exists_in_postmeta_table ) ) {
			ConsoleTable::output_comparison(
				[
					'Unique in wp_users?',
					'Unique in wp_postmeta?',
				],
				[
					'Unique in wp_users?'    => empty( $exists_in_users_table ) ? '✅' : ConsoleColor::yellow( count( $exists_in_users_table ) )->get(),
					'Unique in wp_postmeta?' => empty( $exists_in_postmeta_table ) ? '✅' : ConsoleColor::yellow( count( $exists_in_postmeta_table ) )->get(),
				]
			);

			$bright_user_login = ! empty( $exists_in_users_table ) ?
				ConsoleColor::bright_white( $capless_user_login )->get() :
				ConsoleColor::bright_white( $capped_user_login )->get();
			$prompt            = Streams::prompt( "User login '$bright_user_login' already exists. Please enter a new user_login, or (h)alt execution" );

			if ( 'h' === $prompt ) {
				WP_CLI::halt( 1 );
			}

			return $this->prompt_for_unique_user_login( $prompt, $exclude_guest_author_id, $exclude_user_id );
		}

		return $user_login;
	}

	/**
	 * This function facilitates the process of checking if a field exists and has a correct value, and if it doesn't
	 * asking the user if they would like to set it.
	 *
	 * @param string            $field_name The name of the field to check.
	 * @param array|string|null $field The value of the field to check.
	 *
	 * @return string
	 * @throws WP_CLI\ExitException Script halts whenever it detects data that is not valid.
	 */
	private function get_or_prompt_for_field( string $field_name, array|string|null $field ): string {
		if ( null === $field ) {
			$prompt = Streams::prompt( "This Guest Author has an empty $field_name. Would you like to set one? (y/h)" );

			if ( 'y' === $prompt ) {
				$field = Streams::prompt( "Please enter a $field_name" );
				$field = ucwords( $field );
			} else {
				WP_CLI::halt( 1 );
			}
		} elseif ( is_array( $field ) ) {
			// Unique values obtained, meta_id as array key is maintained.
			// This makes it convenient to delete the non-selected values.
			$unique_field_values = array_unique( $field );

			if ( count( $unique_field_values ) > 1 ) {

				$unique_field_values[] = 'None of the above';// $prompt will be the meta_id that was selected.
				$prompt                = Streams::menu( $unique_field_values, '', "Please select the $field_name you would like to use (the others will be deleted)" );
				if ( 'None of the above' === $unique_field_values[ $prompt ] ) {
					$prompt = Streams::prompt( "Understood, first please enter a $field_name" );

					if ( empty( $prompt ) ) {
						WP_CLI::halt( 1 );
					}

					$prompt = ucwords( $prompt );

					foreach ( $field as $meta_id => $meta_value ) {
						delete_metadata_by_mid( 'post', $meta_id );
					}

					$field = $prompt;
				} elseif ( empty( $prompt ) ) {
					WP_CLI::halt( 1 );
				} else {
					foreach ( $field as $meta_id => $meta_value ) {
						if ( $meta_id !== $prompt ) {
							delete_metadata_by_mid( 'post', $meta_id );
						}
					}

					$field = $unique_field_values[ $prompt ];
				}
			} else {
				$field = reset( $unique_field_values );
			}
		} else {
			$prompt_value = ConsoleColor::bright_white( $field )->get();
			$prompt       = Streams::prompt( "Use '{$prompt_value}' as $field_name? (y/n)", 'y' );

			if ( 'n' === $prompt ) {
				$field = Streams::prompt( "Ok then, please provide a $field_name" );
				$field = ucwords( $field );
			}
		}

		return $field;
	}
}
