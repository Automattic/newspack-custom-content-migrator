<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for Retro Report.
 */
class RetroReportMigrator implements InterfaceCommand {

	public const META_PREFIX = 'newspack_rr_';

	public const BASE_URL = 'https://data.retroreport-org.pages.dev';

	public const CF_TOKEN_OPTION = 'newspack_rr_cf_token';

	public const ARRAY_ITEM_REGEX = '/(.*)\[(.*)\]/';

	/**
	 * Instance of RetroReportMigrator
	 *
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * Attachments logic.
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Simple Local Avatars.
	 *
	 * @var null|Simple_Local_Avatars
	 */
	private $simple_local_avatars;

	/**
	 * CAP logic.
	 *
	 * @var null|CoAuthorPlus
	 */
	private $co_authors_plus;

	/**
	 * List of core wp_posts fields
	 *
	 * @var array
	 */
	private $core_fields;

	/**
	 * Fields mappings from JSON fields to WP fields
	 *
	 * @var array
	 */
	private $fields_mappings;

	/**
	 * Post types for imported
	 *
	 * @var array
	 */
	private $post_types;

	/**
	 * Formatting functions for specific types of content
	 *
	 * @var array
	 */
	private $content_formatters;


	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments = new Attachments();

		$this->co_authors_plus = new CoAuthorPlus();

		$this->simple_local_avatars = new SimpleLocalAvatars();

		$this->core_fields = array(
			'post_author',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'post_name',
			'post_modified_gmt',
			'tags_input',
		);

		$this->core_fields = array_flip( $this->core_fields );

		$this->set_fields_mappings();
		$this->set_post_types();
		$this->set_content_formatters();

		add_filter( 'http_request_args', array( $this, 'add_cf_token_to_requests' ), 10, 2 );
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
			'newspack-content-migrator retro-report-import-posts',
			array( $this, 'cmd_retro_report_import_posts' ),
			array(
				'shortdesc' => 'Import posts from a JSON file',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'category',
						'optional'    => false,
						'description' => 'Category name (about, board, articles, etc.).',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'json-file',
						'optional'    => false,
						'description' => 'Path to the JSON file.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-type',
						'optional'    => false,
						'description' => 'The post type to be imported as (default is `post`).',
						'default'     => 'post',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'guest-author',
						'optional'    => true,
						'description' => 'ID of the guest author to assign to post.',
						'default'     => 'post',
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator retro-report-import-staff',
			array( $this, 'cmd_retro_report_import_staff' ),
			array(
				'shortdesc' => 'Import staff from a JSON file',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'json-file',
						'optional'    => false,
						'description' => 'Path to the JSON file.',
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator retro-report-add-cf-token',
			array( $this, 'cmd_retro_report_add_cf_token' ),
			array(
				'shortdesc' => 'Add the CloudFlare authorization token (CF_Authorization cookie) for later use (to download images for now).',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'token',
						'optional'    => false,
						'description' => 'The CF authorization token.',
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator retro-report-add-cf-token`
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_retro_report_add_cf_token( $args, $assoc_args ) {
		$token = $assoc_args['token'];

		update_option( self::CF_TOKEN_OPTION, $token );

		WP_CLI::success( sprintf( 'The token was added to the %s option.', self::CF_TOKEN_OPTION ) );
	}

	/**
	 * Callable for `newspack-content-migrator retro-report-import-staff`
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_retro_report_import_staff( $args, $assoc_args ) {
		if ( ! $this->simple_local_avatars->is_sla_plugin_active() ) {
			WP_CLI::error( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

		$users = $this->validate_json_file( $assoc_args );

		foreach ( $users as $user ) {
			$unique_id  = $user->unique_id;
			$first_name = trim( $this->get_object_property( $user, 'first_name' ) );
			$last_name  = trim( $this->get_object_property( $user, 'last_name' ) );
			$images     = $this->get_object_property( $user, 'images' );
			$twitter    = $this->get_object_property( $user, 'twitter' );
			$position   = $this->get_object_property( $user, 'position' );
			$role       = $this->get_object_property( $user, 'role' );

			$path_parts = explode( '/', trim( $user->path, '/' ) );
			$nicename   = end( $path_parts );

			if ( ( ! $first_name || ! $last_name ) && property_exists( $user, 'title' ) ) {
				list( $first_name, $last_name ) = explode( ' ', $user->title );
			}

			$username  = sprintf( '%s.%s', strtolower( $first_name ), strtolower( $last_name ) );
			$email     = sprintf( '%s@retroreport.com', $username );
			$full_name = sprintf( '%s %s', $first_name, $last_name );

			if ( $this->user_exists( $user ) ) {
				WP_CLI::log( sprintf( 'User %s already exists. Skipping...', $full_name ) );
				continue;
			}

			WP_CLI::log( sprintf( 'Importing user %s...', $full_name ) );

			if ( 'none' == $position || null == $position ) {
				$position = '';
			}

			// There is one case where the role is set to an empty array. In that case we just consider it's empty.
			// The array may have actual roles that we need to add, so revisit this condition when we have more examples.
			if ( is_array( $role ) ) {
				$role = '';
			}

			$wp_role = 'author';

			if ( 'Masthead' == $role ) {
				$wp_role = 'administrator';
			}

			if ( 'Business Staff' == $role ) {
				$wp_role = 'editor';
			}

			$user_meta = array(
				'newspack_job_title'               => $position,
				'newspack_role'                    => $role,
				'twitter'                          => $twitter,
				$this->get_meta_key( 'images' )    => $images,
				$this->get_meta_key( 'unique_id' ) => $unique_id,
			);

			$user_args = array(
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'nickname'      => $username,
				'user_login'    => $username,
				'user_email'    => $email,
				'user_nicename' => $nicename,
				'user_pass'     => wp_generate_password(),
				'display_name'  => property_exists( $user, 'title' ) ? $user->title : $full_name,
				'description'   => property_exists( $user, 'content' ) ? $user->content : '',
				'meta_input'    => $user_meta,
				'role'          => $wp_role,
			);

			$user_id = wp_insert_user( $user_args );

			if ( is_wp_error( $user_id ) ) {
				WP_CLI::warning( sprintf( 'There was a problem importing user %s', $full_name ) );
				WP_CLI::warning( $user_id->get_error_message() );
				continue;
			}

			// Import the user's avatar.
			$image = $this->get_object_property( $user, 'image' );

			if ( $image ) {
				$image_url     = self::BASE_URL . $image;
				$attachment_id = $this->attachments->import_external_file( $image_url );

				WP_CLI::log( sprintf( 'Importing the avatar for user %s...', $full_name ) );

				if ( is_wp_error( $attachment_id ) ) {
					WP_CLI::warning( sprintf( 'There was a problem importing the image %s.', $image_url ) );
					WP_CLI::warning( $attachment_id->get_error_message() );
				} else {
					$this->simple_local_avatars->import_avatar( $user_id, $attachment_id );
				}
			}
		}

	}

	/**
	 * Callable for `newspack-content-migrator retro-report-import-posts`
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_retro_report_import_posts( $args, $assoc_args ) {
		$category  = $assoc_args['category'];
		$ga_id     = isset( $assoc_args['guest-auhor'] ) ? $assoc_args['guest-auhor'] : null;
		$posts     = $this->validate_json_file( $assoc_args );
		$post_type = $this->get_post_type( $category );

		// Check if the category already exists.
		$category_id = get_cat_ID( $category );

		// If not, create it.
		if ( 0 === $category_id ) {
			WP_CLI::log( sprintf( 'Category %s not found. Creating it....', $category ) );
			$category_id = wp_create_category( $category );
		}

		WP_CLI::log( sprintf( 'Using category %s ID %d.', $category, $category_id ) );

		$fields = $this->load_mappings( $category );

		WP_CLI::log( 'The fields that are going to be imported are:' );

		WP_CLI\Utils\format_items( 'table', $fields, 'name,type,target' );

		foreach ( $posts as $post ) {
			WP_CLI::log( sprintf( 'Importing post "%s"...', $post->title ) );

			if ( $this->post_exists( $post, $post_type ) ) {
				WP_CLI::log( sprintf( 'Post "%s" is already imported. Skipping...', $post->title ) );
				continue;
			}

			$post_id = $this->import_post( $post, $post_type, $fields, $category );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( sprintf( 'Could not add post "%s".', $post->title ) );
				WP_CLI::warning( $post_id->get_error_message() );
				continue;
			}

			wp_set_post_categories( $post_id, array( $category_id ) );

			if ( $ga_id ) {
				$this->co_authors_plus->assign_guest_authors_to_post(
					array( $ga_id ),
					$post_id,
					true,
				);
			}
		}
	}

	/**
	 * Import an object as a WP post.
	 *
	 * @param object $object A standard object with post fields.
	 * @param string $post_type The post type.
	 * @param array  $post_fields An array containing the mapping of each field.
	 * @param string $category The name of the post's category.
	 *
	 * @return int|WP_Error The post ID on success, WP_Error otherwise.
	 */
	public function import_post( $object, $post_type, $post_fields, $category ) {
		$post_args = array(
			'post_type' => $post_type,
		);

		$post_meta = array(
			self::META_PREFIX . 'type'      => $category,
			self::META_PREFIX . 'unique_id' => $object->unique_id,
		);

		$post_authors = [];

		foreach ( $post_fields as $field ) {

			if ( 'post_content' == $field->target && $this->get_content_formatter( $category ) ) {
				$formatter_function = $this->get_content_formatter( $category );
				$post_args['post_content'] = call_user_func( $formatter_function, $object );
				continue;
			}

			if ( $this->is_array_item( $field ) ) {
				$value = $this->get_array_item_value( $field, $object );
			} else {
				$value = property_exists( $object, $field->name ) ? $object->{ $field->name } : '';
			}

			$formatted_value = $this->format_post_field( $field, $value );
			if ( $field->is_meta ) {
				$post_meta[ $field->target ] = $formatted_value;
			} else if ( 'authors' === $field->type ) {
				$post_authors = $formatted_value;
			} else {
				$post_args[ $field->target ] = $formatted_value;
			}
		}

		$post_id = $this->add_post( $post_args, $post_meta, $post_authors );

		return $post_id;
	}

	/**
	 * Add a post to the database along with its meta data.
	 *
	 * @param array $post_args    Array containing the post arguments (post_title, post_content etc.).
	 * @param array $post_meta    Associative array of post meta (key => value).
	 * @param array $post_authors Array of CAP Guest Author IDs.
	 *
	 * @return int|WP_Error The post ID on success, WP_Error otherwise.
	 */
	public function add_post( $post_args, $post_meta, $post_authors ) {
		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $post_meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Add Guest Authors.
		if ( ! empty( $post_authors ) ) {
			$this->co_authors_plus->assign_guest_authors_to_post( $post_authors, $post_id );
		}

		return $post_id;
	}

	/**
	 * Convert a field's value to the necessary format.
	 * For example, convert an image URL to attachment ID by importing it,
	 * convert "draft": false to post_status=publish etc.
	 *
	 * @param object $field The field object.
	 * @param mixed  $value The field's value (could be anything).
	 *
	 * @return mixed The formatted value.
	 */
	public function format_post_field( $field, $value ) {

		// @TODO use a mechanism like in $this->is_array_item()?
		if ( false !== strpos( $field->name, '->' ) && ! empty( $value ) ) {
			$prop = substr( $field->name, strpos( $field->name, '->' ) + 2 );
			$value = $value->$prop;
		}

		if ( 'string' == $field->type || 'array' == $field->type || 'boolean' == $field->type ) {
			switch ( $field->target ) {
				case 'post_status':
					if ( true === $value ) {
						return 'draft';
					}

					if ( false === $value ) {
						return 'publish';
					}

					return $value;
				case 'post_name':
					$path_parts = explode( '/', trim( $value, '/' ) );
					$slug       = end( $path_parts );
					return $slug;

				case "post_author":
					if ( ! empty( $value ) ) {
						// Just grab the first user for core post_author.
						$author = $this->get_user_from_staff_id( $value[0] );
						return ( empty( $author ) ) ? false : $author;
					}

			}
		}

		// Convert dates to WordPress' precious format.
		if ( 'date' === $field->type ) {
			$value = date( 'Y-m-d H:i:s', strtotime( $value ) );
		}

		// Process multiple authors into CAP guest authors.
		if ( 'authors' === $field->type ) {
			$authors = $this->convert_authors_to_guest_authors( $value );
			return $authors;
		}

		if ( 'thumbnail' == $field->type && $value ) {

			WP_CLI::log( sprintf( 'Importing thumbnail from %s', $value ) );

			$image_url     = self::BASE_URL . $value;
			$attachment_id = $this->attachments->import_external_file( $image_url );

			if ( is_wp_error( $attachment_id ) ) {
				WP_CLI::warning( sprintf( 'There was a problem importing the image %s.', $image_url ) );
				WP_CLI::warning( $attachment_id->get_error_message() );
				return $value;
			}

			return $attachment_id;
		}

		return maybe_serialize( $value );
	}

	/**
	 * Check if a post has already been imported.
	 *
	 * @param object $post The post object (from JSON, not WP_Post).
	 * @param string $post_type The post type (wp_posts.post_type).
	 *
	 * @return boolean True if the post has already been imported, false otherwise.
	 */
	public function post_exists( $post, $post_type = 'post' ) {
		$unique_id = $post->unique_id;

		$query = new WP_Query(
			array(
				'meta_key'    => self::META_PREFIX . 'unique_id',
				'meta_value'  => $unique_id,
				'post_type'   => $post_type,
				'post_status' => 'any',
			),
		);

		return $query->post_count > 0;
	}

	/**
	 * Check if a user has already been imported.
	 *
	 * @param object $user The user object (from JSON, not wp_users).
	 *
	 * @return boolean True if the user has already been imported, false otherwise.
	 */
	public function user_exists( $user ) {
		$unique_id = $user->unique_id;

		$users = $this->get_user_from_staff_id( $unique_id );

		return count( $users ) > 0;
	}

	/**
	 * Grab the user account based on the original staff ID.
	 *
	 * @param int $staff_id Staff ID from the JSON import.
	 *
	 * @return array List of WP_User objects that match the Staff ID.
	 */
	private function get_user_from_staff_id( $staff_id ) {
		return get_users(
			array(
				'meta_key'   => self::META_PREFIX . 'unique_id',
				'meta_value' => $staff_id,
			),
		);
	}

	/**
	 * Get CAP guest authors from the original staff ID.
	 *
	 * @param int $staff_id Staff ID from the JSON import.
	 *
	 * @return object|bool Guest author object, or false if not found.
	 */
	private function get_guest_author_from_staff_id( $staff_id ) {

		// Get any existing user.
		$user = $this->get_user_from_staff_id( $staff_id );
		if ( empty( $user ) ) {
			return false;
		}

		// Now find (or create) a corresponding Guest Author.
		return $this->co_authors_plus->get_or_create_guest_author_from_user( $user[0] );

	}

	/**
	 * Converts a list of staff IDs into WP user IDs.
	 *
	 * @param array $authors A list of staff IDs from the original import.
	 *
	 * @return array A list of guest author IDs.
	 */
	private function convert_authors_to_guest_authors( $authors ) {

		$ga_ids = [];

		foreach ( $authors as $author ) {
			$ga = $this->get_guest_author_from_staff_id( $author );
			if ( ! $ga ) {
				continue;
			}

			$ga_ids[] = $ga->ID;
		}

		return $ga_ids;
	}

	/**
	 * Load the fields mappings from a CSV file
	 *
	 * @param string $category The name of the category (endpoint title).
	 *
	 * @return array Array of fields.
	 */
	public function load_mappings( $category ) {
		if ( ! isset( $this->fields_mappings[ $category ] ) ) {
			WP_CLI::error( 'The given category does not exist.' );
		}

		$fields = array();

		foreach ( $this->fields_mappings[ $category ] as $field ) {
			list( $name, $type, $target ) = $field;

			$is_meta = $this->is_meta_field( $target );

			$fields[] = (object) array(
				'name'    => $name,
				'type'    => $type,
				'is_meta' => $is_meta,
				'target'  => $is_meta ? $this->get_meta_key( $target ) : $target,
			);
		}

		return $fields;
	}

	/**
	 * Get the post type of a category (endpoint).
	 *
	 * @param string $category The category.
	 *
	 * @return string The post type.
	 */
	public function get_post_type( $category ) {
		if ( ! isset( $this->post_types[ $category ] ) ) {
			WP_CLI::error( 'The given category does not exist.' );
		}

		return $this->post_types[ $category ];
	}

	/**
	 * Generate the meta key for a field.
	 *
	 * @param string $name The field name.
	 *
	 * @return string The meta key.
	 */
	public function get_meta_key( $name ) {
		if ( '_thumbnail_id' == $name || strpos( $name, 'newspack_' ) !== false ) {
			return $name;
		}

		return self::META_PREFIX . $name;
	}

	/**
	 * Check if a field should be saved in meta or in wp_posts table.
	 *
	 * @param string $field The field name.
	 *
	 * @return boolean True if meta, false otherwise.
	 */
	public function is_meta_field( $field ) {
		return ! isset( $this->core_fields[ $field ] );
	}

	/**
	 * Add the CloudFlare authorization token to HTTP requests.
	 *
	 * @param array  $args HTTP args.
	 * @param string $url The requested URL.
	 *
	 * @return array The modified args.
	 */
	public function add_cf_token_to_requests( $args, $url ) {
		if ( strpos( $url, 'pages.dev' ) === false ) {
			return $args;
		}

		$cf_token = get_option( self::CF_TOKEN_OPTION );

		if ( ! $cf_token ) {
			WP_CLI::error( 'HTTP requests to CloudFlare will not work because the CF token does not exist. Aborting.' );
		}

		$args['cookies']['CF_Authorization'] = $cf_token;

		return $args;
	}

	/**
	 * Set the fields mappings for each endpoint.
	 *
	 * @return void
	 */
	public function set_fields_mappings() {
		$this->fields_mappings['Education profiles'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'content', 'string', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'lastmod', 'date', 'post_modified_gmt' ),
			array( 'lastmod', 'date', 'post_date_gmt' ),
			array( 'path', 'string', 'post_name' ),
			array( 'path', 'string', 'path' ),
		);

		$this->fields_mappings['Education resources'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'content', 'string', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'lastmod', 'date', 'post_modified_gmt' ),
			array( 'lastmod', 'date', 'post_date_gmt' ),
			array( 'path', 'string', 'post_name' ),
			array( 'path', 'string', 'path' ),
			array( 'description', 'string', 'newspack_article_summary' ),
			array( 'subtitle', 'string', 'newspack_post_subtitle' ),
			array( 'images[0]', 'thumbnail', '_thumbnail_id' ),
		);

		$this->fields_mappings['Standards'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'content', 'string', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'lastmod', 'date', 'post_modified_gmt' ),
			array( 'lastmod', 'date', 'post_date_gmt' ),
			array( 'path', 'string', 'post_name' ),
			array( 'path', 'string', 'path' ),
		);

		$this->fields_mappings['Links'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'content', 'string', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'lastmod', 'date', 'post_modified_gmt' ),
			array( 'publishdate', 'date', 'post_date_gmt' ),
			array( 'description', 'string', 'post_excerpt' ),
			array( 'path', 'string', 'post_name' ),
			array( 'description', 'string', 'description' ),
			array( 'path', 'string', 'path' ),
			array( 'date', 'string', 'date' ),
			array( 'images', 'array', 'images' ),
		);

		$this->fields_mappings['Video'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'video_source[0]', 'array', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'lastmod', 'date', 'post_modified_gmt' ),
			array( 'publishdate', 'date', 'post_date_gmt' ),
			array( 'video_source[0]->description', 'string', 'newspack_post_subtitle' ),
			array( 'video_source[0]->description', 'string', 'newspack_article_summary' ),
			array( 'video_source[0]->image', 'thumbnail', '_thumbnail_id' ),
			array( 'video_source[0]->video_id', 'string', 'video_id' ),
		);

		$this->fields_mappings['Articles'] = array(
			array( 'title', 'string', 'post_title' ),
			array( 'content', 'string', 'post_content' ),
			array( 'blocks', 'array', 'post_content' ),
			array( 'draft', 'boolean', 'post_status' ),
			array( 'authors', 'array', 'post_author' ),
			array( 'authors', 'authors', 'guest_authors' ),
			array( 'description', 'string', 'newspack_article_summary' ),
			array( 'publishdate', 'string', 'post_date_gmt' ),
			array( 'lastmod', 'string', 'post_modified_gmt' ),
			array( 'image->file', 'string', '_thumbnail_id' ),
		);

	}

	public function set_content_formatters() {
		$this->content_formatters['Education profiles'] = array( $this, 'format_education_profiles_content' );
		$this->content_formatters['Education resources'] = array( $this, 'format_education_resources_content' );
		$this->content_formatters['Video'] = array( $this, 'format_video_content' );
		$this->content_formatters['Articles'] = array( $this, 'format_article_content' );
	}

	public function get_content_formatter( $category ) {
		return isset( $this->content_formatters[ $category ] ) ? $this->content_formatters[ $category ] : null;
	}

	/**
	 * Get the property value, or return empty string if it doesn't exist.
	 *
	 * @param object $object The object.
	 * @param string $property The property to access.
	 *
	 * @return mixed The property value.
	 */
	public function get_object_property( $object, $property ) {
		return property_exists( $object, $property ) ? $object->{ $property } : '';
	}

	/**
	 * Set the post types for each endpoint.
	 *
	 * @return void
	 */
	public function set_post_types() {
		$this->post_types['Standards']           = 'wp_block';
		$this->post_types['Links']               = 'post';
		$this->post_types['Education profiles']  = 'newspack_lst_generic';
		$this->post_types['Education resources'] = 'post';
		$this->post_types['Video']               = 'post';
		$this->post_types['Articles']            = 'post';
	}

	public function format_education_profiles_content( $post ) {
		$content_code = <<<HTML
<!-- wp:social-links -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"https://twitter.com/%s","service":"twitter"} /--></ul>
<!-- /wp:social-links -->

<!-- wp:paragraph -->
<p><strong>%s<br></strong>%s</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;

		return sprintf(
			$content_code,
			$post->twitter_handle,
			$post->school,
			$post->location,
			$post->description,
		);
	}

	public function format_education_resources_content( $post ) {
		// WIP
		$content_code = <<<HTML

HTML;
		$video_block = <<<HTML

HTML;
		$single_block_content = <<<HTML

HTML;
	}

	/**
	 * Format post content for articles.
	 *
	 * Merges imported data with a post template specifically for article content.
	 *
	 * @param array $post Array of post data as retrieved from the JSON.
	 *
	 * @return string Constructed post template.
	 */
	public function format_article_content( $post ) {
		return implode(
			'',
			[
				$this->convert_copy_to_blocks( $post->content ),
				$this->convert_blocks_to_blocks( $post->blocks ),
			]
		);
	}

	/**
	 * Format post content for video posts.
	 *
	 * Merges imported data with a post template specifically for Video content.
	 *
	 * @param array $post Array of post data as retrieved from the JSON.
	 *
	 * @return string Constructed post template.
	 */
	public function format_video_content( $post ) {

		// List of production credits.
		$credits         = '';
		$credit_template = '<!-- wp:paragraph --><p>%1$s: %2$s</p><!-- /wp:paragraph -->';
		foreach ( $post->production_credits as $credit ) {
			foreach ( $credit->staff as $staff ) {
				$users = $this->get_user_from_staff_id( $staff );
				if ( ! empty( $users ) ) {
					$credits .= sprintf( $credit_template, $credit->title, $users[0]->display_name );
				}
			}
		}

		// Release date, formatted according to the template.
		$released = sprintf(
			'<!-- wp:paragraph --><p>Released: %s </p><!-- /wp:paragraph -->',
			date( 'M d, Y', strtotime( $post->video_source[0]->release_date ) )
		);

		// Transcript button.
		$transcript = sprintf(
			'<!-- wp:buttons -->
			<div class="wp-block-buttons"><!-- wp:button -->
			<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="%s">Transcript</a></div>
			<!-- /wp:button --></div>
			<!-- /wp:buttons -->',
			$post->video_source[0]->transcript_file
		);

		// Accordion of "archive" videos.
		$archive_videos      = array_slice( $post->video_source, 1 );
		$archive_video_block = '';
		$accordion_template  = '<!-- wp:genesis-blocks/gb-accordion -->
<div class="wp-block-genesis-blocks-gb-accordion gb-block-accordion"><details><summary class="gb-accordion-title">%1$s</summary><div class="gb-accordion-text">%2$s
%3$s</div></details></div>
<!-- /wp:genesis-blocks/gb-accordion -->';
		if ( ! empty( $archive_videos ) ) {
			$archive_video_block .= '<!-- wp:heading --><h2>Archived Versions</h2><!-- /wp:heading -->';
			foreach ( $archive_videos as $video ) {
				$archive_video_block .= sprintf(
					$accordion_template,
					$video->title,
					$this->format_video_block( $video->video_id ),
					$this->convert_copy_to_blocks( $video->copy )
				);
			}
		}

		// Combine all the parts in the right order.
		return implode( '', [
			$this->format_video_group_block( $post->video_source[0]->video_id ),
			"<!-- wp:heading --><h2>$post->title</h2><!-- /wp:heading -->",
			$credits,
			$released,
			$this->convert_copy_to_blocks( $post->video_source[0]->copy ),
			$transcript,
			$archive_video_block
		] );

	}

	/**
	 * Convert paragraph HTML to blocks.
	 *
	 * Intended to be used for any "copy" values from the JSON imports.
	 *
	 * @param string $copy The raw HTML from the JSON
	 *
	 * @return string Refactored content with paragraph blocks.
	 */
	private function convert_copy_to_blocks( $copy ) {
		$copy = str_replace( '\n', '', $copy );
		$copy = str_replace( '<p>', '<!-- wp:paragraph --><p>', $copy );
		$copy = str_replace( '</p>', '</p><!-- /wp:paragraph -->', $copy );
		return $copy;
	}

	/**
	 * Convert blocks from the JSON into WP blocks.
	 *
	 * @param array $blocks List of blocks from the import JSON.
	 *
	 * @return string WP Block HTML
	 */
	private function convert_blocks_to_blocks( $blocks ) {
		$new_blocks = [];
		foreach ( $blocks as $block ) {
			switch ( $block->layout ) {
				case "video":
					$new_blocks[] = $this->format_video_group_block( $block->video_id );
					break;
				case "text":
					$new_blocks[] = $this->convert_copy_to_blocks( $block->copy );
					break;
				case "image":
					$new_blocks[] = $this->format_image_block( $block );
					break;
			}
		}
		return implode( '', $new_blocks );
	}

	private function format_image_block( $block ) {

		$filename = explode( '/', $block->image );
		$filename = end( $filename );
		$attachment_id = $this->attachments->get_attachment_by_filename( $filename );
		if ( is_null( $attachment_id ) ) {
			$image_url     = self::BASE_URL . $block->image;
			$attachment_id = $this->attachments->import_external_file(
				$image_url,     // Image URL.
				$block->copy, // Title.
				$block->copy, // Caption.
				$block->copy, // Description.
				$block->alt   // Alt.
			);
		}

		// Do we definitely have an imported image?
		if ( is_wp_error( $attachment_id ) ) {
			return '';
		}

		// Grab the attachment details and produce the image block.
		$template = '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="%2$s" alt="%3$s" class="wp-image-%1$d"/><figcaption class="wp-element-caption">%3$s</figcaption></figure>
<!-- /wp:image -->';

		return sprintf(
			$template,
			$attachment_id,
			wp_get_attachment_url( $attachment_id ),
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			wp_get_attachment_caption( $attachment_id ),
		);

	}

	/**
	 * Generates a video group block, based on a given video ID.
	 *
	 * Used for creating the "featured image"-style spot at the top of video posts.
	 *
	 * @param string $video_id The ID of the video from the JSON import.
	 *
	 * @return string Gutenberg block HTML.
	 */
	private function format_video_group_block( $video_id ) {

		// Video group block at the top of the page.
		$video_block = $this->format_video_block( $video_id );
		$video_group = <<<HTML
		<!-- wp:group {"align":"full","backgroundColor":"dark-gray","textColor":"white","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-white-color has-dark-gray-background-color has-text-color has-background">$video_block</div>
<!-- /wp:group -->
HTML;

		return $video_group;

	}

	/**
	 * Generate an individual video block.
	 *
	 * @param string $video_id The ID of the video from the JSON import.
	 *
	 * @return string Gutenberg block HTML.
	 */
	private function format_video_block( $video_id ) {

		$video_template = '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=%1$s","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=%1$s
</div></figure>
<!-- /wp:embed -->';
		$video_block = sprintf( $video_template, $video_id );
		return $video_block;

	}

	/**
	 * Get posts by the video ID they had in the import JSON.
	 *
	 * @param string $video_id Unique ID
	 *
	 * @return WP_Post|false The post object or false if there is none.
	 */
	private function get_post_from_video_id( $video_id ) {
		$posts = get_posts(
			[
				'post_type'      => 'any',
				'posts_per_page' => 1,
				'meta_query'     => [
					[
						'key'   => 'newspack_rr_video_id',
						'value' => $video_id,
					],
				],
			]
		);
		if ( $posts && ! empty( $posts ) ) {
			return $posts[0];
		}

		return false;
	}

	public function is_array_item( $field ) {
		return preg_match( self::ARRAY_ITEM_REGEX, $field->name );
	}

	public function get_array_item_value( $field, $object ) {
		preg_match_all( self::ARRAY_ITEM_REGEX, $field->name, $matches );

		$property_name  = $matches[1][0];
		$index          = $matches[2][0];
		$property_value = $this->get_object_property( $object, $property_name );

		return $property_value[ $index ];
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}

	/**
	 * Validate the JSON file and return it's decoded contents.
	 *
	 * @param array $assoc_args The array of associate arguments passed to the CLI command.
	 * @return mixed The decoded JSON, if possible.
	 */
	private function validate_json_file( $assoc_args ) {

		if ( ! array_key_exists( 'json-file', $assoc_args ) ) {
			WP_CLI::error( 'No JSON file provided. Please feed me JSON.' );
		}

		$json_file = $assoc_args['json-file'];
		if ( ! file_exists( $json_file ) ) {
			WP_CLI::error( 'The provided JSON file doesn\'t exist.' );
		}

		$data = json_decode( file_get_contents( $json_file ) );
		if ( null === $data ) {
			WP_CLI::error( 'Could not decode the JSON data. Exiting...' );
		}

		return $data;
	}
}
