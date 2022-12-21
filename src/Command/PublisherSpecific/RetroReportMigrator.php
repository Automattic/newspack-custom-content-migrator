<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for Retro Report.
 */
class RetroReportMigrator implements InterfaceCommand {

	public const META_PREFIX = 'newspack_rr_';

	public const BASE_URL = 'https://data.retroreport-org.pages.dev';

	public const CF_TOKEN_OPTION = 'newspack_rr_cf_token';

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
	 * CAP login.
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
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments = new Attachments();

		$this->co_authors_plus = new CoAuthorPlus();

		$this->core_fields = array(
			'post_author',
			'post_date_gmt',
			'post_content',
			'post_title',
			'post_excerpt',
			'post_status',
			'post_name',
			'post_modified_gmt',
		);

		$this->core_fields = array_flip( $this->core_fields );

		$this->set_fields_mappings();
		$this->set_post_types();

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
	 * Callable for `newspack-content-migrator retro-report-import-posts`
	 * 
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_retro_report_import_posts( $args, $assoc_args ) {
		$category  = $assoc_args['category'];
		$json_file = $assoc_args['json-file'];
		$ga_id     = isset( $assoc_args['guest-auhor'] ) ? $assoc_args['guest-auhor'] : null;

		$post_type = $this->get_post_type( $category );

		if ( ! file_exists( $json_file ) ) {
			WP_CLI::error( 'The provided JSON file doesn\'t exist.' );
		}

		// Check if the category already exists.
		$category_id = get_cat_ID( $category );

		// If not, create it.
		if ( 0 === $category_id ) {
			WP_CLI::log( sprintf( 'Category %s not found. Creating it....', $category ) );
			$category_id = wp_create_category( $category );
		}

		WP_CLI::log( sprintf( 'Using category %s ID %d.', $category, $category_id ) );

		$posts = json_decode( file_get_contents( $json_file ) );
		
		if ( null === $posts ) {
			WP_CLI::error( 'Could not decode the JSON data. Exiting...' );
		}

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

		foreach ( $post_fields as $field ) {
			$value           = property_exists( $object, $field->name ) ? $object->{ $field->name } : '';
			$formatted_value = $this->format_post_field( $field, $value );
			if ( $field->is_meta ) {
				$post_meta[ $field->target ] = $formatted_value;
			} else {
				$post_args[ $field->target ] = $formatted_value;
			}
		}

		$post_id = $this->add_post( $post_args, $post_meta );

		return $post_id;
	}

	/**
	 * Add a post to the database along with its meta data.
	 * 
	 * @param array $post_args Array containing the post arguments (post_title, post_content etc.).
	 * @param array $post_meta Associative array of post meta (key => value).
	 * 
	 * @return int|WP_Error The post ID on success, WP_Error otherwise.
	 */
	public function add_post( $post_args, $post_meta ) {
		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( $post_meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
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
			}
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
		if ( '_thumbnail_id' == $name ) {
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
			array( 'date', 'string', 'date' ),
		);
	}

	/**
	 * Set the post types for each endpoint.
	 * 
	 * @return void
	 */
	public function set_post_types() {
		$this->post_types['Standards'] = 'wp_block';
		$this->post_types['Links']     = 'post';
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
}
