<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for Charleston City Paper.
 */
class CharlestonCityPaperMigrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
			'newspack-content-migrator charlestoncitypaper-uploadcare-checkfilesinfolders',
			[ $this, 'cmd_uploadcare_checkfilesinfolders' ],
			[
				'shortdesc' => 'A helper command which checks Upload Care contents.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'uploadcare-path',
						'description' => 'Full path to location of all Upload Care folders and files.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-uploadcare-fix',
			[ $this, 'cmd_uploadcare_fix' ],
			[
				'shortdesc' => 'Fixes uploadcare images to local path.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'uploadcare-path',
						'description' => 'Full path to location of all Upload Care folders and files.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-uploads-subfolders-fix',
			[ $this, 'cmd_uploads_subfolders_fix' ],
			[
				'shortdesc' => 'Fixes upload subfolders by moving files out of these one level below.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-create-merge-authors-from-post-author-meta',
			[ $this, 'cmd_create_merge_authors_from_post_author_meta' ],
			[
				'shortdesc' => 'Loop over all the posts checking their `author` meta, link the author if it exists as a WP user, or create it as a Guest Author.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-migrate-authors-from-publishpress-to-cap',
			[ $this, 'cmd_migrate_authors_from_publishpress_to_cap' ],
			[
				'shortdesc' => 'Loop over all the posts checking their `author` meta, link the author if it exists as a WP user, or create it as a Guest Author.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-resize-images-and-remove-shortcodes',
			[ $this, 'cmd_resize_images_and_remove_shortcodes' ],
			[
				'shortdesc' => 'Resize images and remove shortcodes.',
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploads-subfolders-fix`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_uploads_subfolders_fix( $args, $assoc_args ) {
		$time_start = microtime( true );

		$uploads_dir = wp_get_upload_dir()['basedir'] ?? null;
		if ( is_null( $uploads_dir ) ) {
			WP_CLI::error( 'Could not get upload dir.' );
		}

		$uploads_subdirs = glob( $uploads_dir . '/*', GLOB_ONLYDIR );
		foreach ( $uploads_subdirs as $key_uploads_subdirs => $uploads_subdir ) {
			// Work only with valid `yyyy` subdirs.
			$yyyy_name = pathinfo( $uploads_subdir )['basename'] ?? null;
			if ( is_null( $yyyy_name ) ) {
				continue;
			}
			if ( ! is_numeric( $yyyy_name ) ) {
				continue;
			}
			$yyyy_numeric = (int) $yyyy_name;
			if ( $yyyy_numeric < 2000 || $yyyy_numeric > 2022 ) {
				continue;
			}

			WP_CLI::line( sprintf( '(%d/%d) yyyy %s', $key_uploads_subdirs + 1, count( $uploads_subdirs ), $yyyy_name ) );

			// Work through `mm` folders.
			$mms = glob( $uploads_dir . '/' . $yyyy_name . '/*', GLOB_ONLYDIR );
			foreach ( $mms as $key_mms => $mm_dir ) {
				$mm_name = pathinfo( $mm_dir )['basename'] ?? null;
				if ( is_null( $mm_name ) ) {
					continue;
				}
				if ( ! is_numeric( $mm_name ) ) {
					continue;
				}
				$mm_numeric = (int) $mm_name;
				if ( $mm_numeric < 0 || $mm_numeric > 12 ) {
					continue;
				}

				WP_CLI::line( sprintf( '  [%d/%d] mm %s', $key_mms + 1, count( $mms ), $mm_name ) );

				// Work through the subfolders.
				$mm_full_path = $uploads_dir . '/' . $yyyy_name . '/' . $mm_name;
				$mm_subdirs   = glob( $mm_full_path . '/*', GLOB_ONLYDIR );
				$progress     = \WP_CLI\Utils\make_progress_bar( 'Moving...', count( $mm_subdirs ) );
				foreach ( $mm_subdirs as $mm_subdir ) {
					$progress->tick();

					$subdir_files = array_diff( scandir( $mm_subdir ), [ '.', '..' ] );
					if ( 0 == count( $subdir_files ) ) {
						continue;
					}

					$msg = '{SUBDIRPATH}' . $mm_subdir;
					$this->log( 'ccp_subfoldersMove', $msg );
					foreach ( $subdir_files as $subdir_file ) {
						$this->log( 'ccp_subfoldersMove', $subdir_file );
						$old_file = $mm_subdir . '/' . $subdir_file;
						$new_file = $mm_full_path . '/' . $subdir_file;
						$renamed  = rename( $old_file, $new_file );
						if ( false === $renamed ) {
							$this->log( 'ccp_subfoldersMoveError', $old_file . ' ' . $new_file );
						}
					}
				}
				$progress->finish();
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploadcare-fix`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_uploadcare_fix( $args, $assoc_args ) {
		$uploadcare_path = $assoc_args['uploadcare-path'] ?? null;
		if ( ! file_exists( $uploadcare_path ) ) {
			WP_CLI::error( sprintf( 'Location %s not found.', $uploadcare_path ) );
		}

		$time_start = microtime( true );

		// Clear option value upload_url_path.
		$res_cleared_upload_url_path = update_option( 'upload_url_path', '' );

		$images   = get_posts(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'numberposts'    => -1,
			]
		);
		$progress = \WP_CLI\Utils\make_progress_bar( 'Images', count( $images ) );

		foreach ( $images as $image ) {
			$progress->tick();

			// Skip non-uploadcare images.
			if ( false === strpos( $image->guid, '//ucarecdn.com/' ) ) {
				WP_CLI::line( sprintf( 'Skipping %d ', $image->ID ) );
				continue;
			}

			$relative_path = str_replace( 'https://ucarecdn.com/', '', $image->guid );
			if ( empty( $relative_path ) ) {
				$this->log( 'ccp_ucImagePathWrong', $image->ID . ' ' . $image->guid );
				WP_CLI::warning( sprintf( 'UC image wrong path %d %s', $image->ID, $image->guid ) );
				continue;
			}
			$url_old = wp_get_attachment_url( $image->ID );
			$path    = $uploadcare_path . '/' . $relative_path;

			$res_updated = update_attached_file( $image->ID, $path );

			$url_new = wp_get_attachment_url( $image->ID );
			$this->log( 'ccp_ucImageUpdated', sprintf( '%d %s %s', $image->ID, $url_old, $url_new ) );
			WP_CLI::line( sprintf( 'Updated %d ', $image->ID ) );

			if ( false === $res_updated ) {
				$this->log( 'ccp_ucImageUpdateError', $image->ID );
				WP_CLI::warning( sprintf( 'Update error %d ', $image->ID ) );
			}
		}
		$progress->finish();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploadcare-checkfilesinfolders`.
	 */
	public function cmd_uploadcare_checkfilesinfolders( $args, $assoc_args ) {
		$uploadcare_path = $assoc_args['uploadcare-path'] ?? null;
		if ( ! file_exists( $uploadcare_path ) ) {
			WP_CLI::error( sprintf( 'Location %s not found.', $uploadcare_path ) );
		}

		$time_start = microtime( true );

		$uploadcare_subdirs = glob( $uploadcare_path . '/*', GLOB_ONLYDIR );

		$one_file_per_folder          = true;
		$subfolders_not_having_1_file = [];

		foreach ( $uploadcare_subdirs as $uploadcare_subdir ) {
			$files = array_diff( scandir( $uploadcare_subdir ), [ '.', '..' ] );
			if ( count( $files ) > 1 || count( $files ) < 1 ) {
				$one_file_per_folder            = false;
				$subfolders_not_having_1_file[] = $uploadcare_subdir;
			}
		}

		if ( false === $one_file_per_folder ) {
			WP_CLI::error( 'Some folders do not have exactly one file in them.' );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-create-merge-authors-from-post-author-meta`
	 */
	public function cmd_create_merge_authors_from_post_author_meta( $args, $assoc_args ) {
		$dry_run           = $assoc_args['dry_run'] ?? false;
		$post_id           = $assoc_args['post_id'] ?? false;
		$posts_from_author = $assoc_args['posts_from_author'] ?? false;
		$time_start        = microtime( true );

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		WP_CLI::line( sprintf( 'dry run mode: %s', $dry_run ? 'on' : 'off' ) );

		$posts_count      = 0;
		$posts_total      = array_sum( (array) wp_count_posts( 'post' ) );
		$posts_query_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'paged'          => 1,
			'fields'         => 'ids,post_author',
		];

		if ( $post_id ) {
			$posts_query_args['p'] = $post_id;
		}

		$posts = get_posts( $posts_query_args );

		foreach ( $posts as $post ) {
			$posts_count++;
			// handle only posts from the author: citypaper.
			if ( $posts_from_author && $posts_from_author !== $post->post_author ) {
				WP_CLI::line( sprintf( 'Skiping post %d with author %s', $post->ID, $post->post_author ) );
				continue;
			}

			WP_CLI::line( sprintf( 'Checking post: %d/%d', $posts_count, $posts_total ) );

			$author_meta = get_post_meta( $post->ID, 'author' );
			if ( ! empty( $author_meta ) && '' !== current( $author_meta ) ) {

				$author_name = array_shift( $author_meta );
				if ( is_array( $author_name ) ) {
					// sometimes posts are linked to many authors, we reset the authors list variable.
					$author_meta = $author_name;
					$author_name = array_shift( $author_meta );
				}

				// Handling main author.
				// check if we do have a WP User with that name.
				$user = $this->get_wp_user_by_name( $author_name );

				// If we do have an existing WP User, we link the post to them.
				if ( $user ) {
					WP_CLI::line( sprintf( '%s(%d) will be assigned as main author to the post #%d', $user->display_name, $user->ID, $post->ID ) );

					if ( ! $dry_run ) {
						wp_update_post(
							[
								'ID'          => $post->ID,
								'post_author' => $user->ID,
							]
						);
					}
				} else {
					// if not, we create a GA and link them to the post.
					if ( ! $dry_run ) {
						$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $author_name ] );
						$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $co_author_id ], $post->ID );
					}
					WP_CLI::line( sprintf( 'Co-Author %s(%d) will be assigned to the post #%d', $author_name, $co_author_id, $post->ID ) );
				}

				// Handle co-authors in case the post have more than one author.
				foreach ( $author_meta as $co_author ) {
					if ( ! $dry_run ) {
						$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $co_author ] );
						$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $co_author_id ], $post->ID );

						// Link the co-author created with the WP User with the same name if it exists.
						$co_author_wp_user = $this->get_wp_user_by_name( $co_author );
						if ( $co_author_wp_user ) {
							$this->coauthorsplus_logic->link_guest_author_to_wp_user( $co_author_id, $co_author_wp_user );
						}
					}

					WP_CLI::line( sprintf( 'Co-Author %s(%d) will be assigned to the post #%d', $co_author, $co_author_id, $post->ID ) );
				}
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-migrate-authors-from-publishpress-to-cap`
	 */
	public function cmd_migrate_authors_from_publishpress_to_cap( $args, $assoc_args ) {
		$dry_run    = $assoc_args['dry_run'] ?? false;
		$post_id    = $assoc_args['post_id'] ?? false;
		$time_start = microtime( true );

		if ( ! function_exists( 'get_multiple_authors' ) ) {
			WP_CLI::warning( 'PublishPress Authors plugin not found. Install and activate it before using this command.' );
			exit;
		}

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		WP_CLI::line( sprintf( 'dry run mode: %s', $dry_run ? 'on' : 'off' ) );

		$posts_query_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'paged'          => 1,
			'fields'         => 'ids,post_author',
		];

		if ( $post_id ) {
			$posts_query_args['p'] = $post_id;
		}

		$posts = get_posts( $posts_query_args );

		foreach ( $posts as $post ) {
			$authors = get_multiple_authors( $post->ID, false, false, true );
			if ( ! empty( $authors ) ) {
				foreach ( $authors as $author ) {
					if ( ! $dry_run ) {
						$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $author->display_name ] );
						$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $co_author_id ], $post->ID );
						WP_CLI::line( sprintf( 'Co-Author %s created and linked to post #%d.', $author->display_name, $post->ID ) );
					} else {
						WP_CLI::line( sprintf( 'Co-Author %s will be created and linked to post #%d.', $author->display_name, $post->ID ) );
					}
				}
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable which processes all posts and resizes images and removes shortcodes.
	 */
	public function cmd_resize_images_and_remove_shortcodes() {
		$last_processed_post_id_file     = "/tmp/last_processed_post_id_image_resize.txt";
		$successfully_processed_post_ids = "/tmp/successfully_processed_post_ids_image_resize.log";

		if ( ! file_exists( $last_processed_post_id_file ) ) {
			file_put_contents( $last_processed_post_id_file, '' );
		}

		if ( ! file_exists( $successfully_processed_post_ids ) ) {
			file_put_contents( $successfully_processed_post_ids, '' );
		}

		$last_processed_post_id = file_get_contents( $last_processed_post_id_file );

		if ( empty( $last_processed_post_id ) ) {
			$last_processed_post_id = PHP_INT_MAX;
		}

		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id
				FROM {$wpdb->postmeta} pm 
				WHERE pm.meta_key = '_wp_attachment_metadata' 
				  AND pm.meta_value LIKE '%width%' 
				  AND pm.post_id < %d 
				ORDER BY pm.post_id DESC",
				$last_processed_post_id
			)
		);

		foreach ( $posts as $post ) {
			WP_CLI::log( "Post_ID: $post->post_id" );
			$command = "wp media regenerate $post->post_id";
			WP_CLI::log( "Executing: $command" );
			$output = shell_exec( $command );
			WP_CLI::log( "$output" );

			file_put_contents( $last_processed_post_id_file, $post->post_id );
			$this->log( $successfully_processed_post_ids, "$command | $output" );
		}

		$last_processed_post_id_file     = "/tmp/last_processed_post_id_shortcodes.txt";
		$successfully_processed_post_ids = "/tmp/successfully_processed_post_ids_shortcodes.log";

		if ( ! file_exists( $last_processed_post_id_file ) ) {
			file_put_contents( $last_processed_post_id_file, '' );
		}

		if ( ! file_exists( $successfully_processed_post_ids ) ) {
			file_put_contents( $successfully_processed_post_ids, '' );
		}

		$last_processed_post_id = file_get_contents( $last_processed_post_id_file );

		if ( empty( $last_processed_post_id ) ) {
			$last_processed_post_id = PHP_INT_MAX;
		}
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID as post_id, post_content
				FROM {$wpdb->posts}
				WHERE post_content LIKE '%%[image-%%' 
				  AND post_type = 'post'
				  AND ID < %d
				  ORDER BY ID DESC",
				$last_processed_post_id
			)
		);

		foreach ( $posts as $post ) {
			$this->log( $successfully_processed_post_ids, "Post_ID: $post->post_id" );
			$content = $post->post_content;
			$content = preg_replace( '/\[image-\d{2}\]/', '', $content, -1, $replacements );

			if ( ! is_null( $content ) ) {
				$result = $wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $content ],
					[ 'ID' => $post->post_id ]
				);

				if ( $result >= 1 ) {
					$this->log( $successfully_processed_post_ids, "eliminated shortcodes: $replacements" );
				}
			}

			file_put_contents( $last_processed_post_id_file, $post->post_id );
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		WP_CLI::log( $message );
		file_put_contents( $file, $message . "\n", FILE_APPEND );
	}

	/**
	 * Retrieve WP user by name.
	 *
	 * @param string $user_name Name to look for.
	 * @return (WP_User|false) WP_User object on success, false on failure.
	 */
	private function get_wp_user_by_name( $user_name ) {
		$user_query = new \WP_User_Query(
			[
				'search'        => $user_name,
				'search_fields' => array( 'user_login', 'user_nicename', 'display_name' ),
			]
		);

		// If we do have an existing WP User, we link the post to them.
		if ( ! empty( $user_query->results ) ) {
			return current( $user_query->results );
		}
		return false;
	}
}
