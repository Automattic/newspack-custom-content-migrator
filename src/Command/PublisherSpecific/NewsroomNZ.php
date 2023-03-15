<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

/* Internal dependencies */
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Attachments;

/* External dependencies */
use stdClass;
use WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for Retro Report.
 */
class NewsroomNZMigrator implements InterfaceCommand {

	public const META_PREFIX = 'newspack_nnz_';

	/**
	 * Instance of RetroReportMigrator
	 *
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Attachments logic.
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Dry run mode - set to true to prevent changes.
	 *
	 * @var bool
	 */
	private $dryrun;

	/**
	 * Mapping of core post fields to XML fields.
	 *
	 * @var array
	 */
	private $core_fields_mapping;

	/**
	 * Mapping of post meta fields to XML fields.
	 *
	 * @var array
	 */
	private $meta_fields_mapping;

	/**
	 * List of callable functions for each imported field.
	 *
	 * @var array
	 */
	private $content_formatters;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();

		$this->attachments = new Attachments();

		// Define where each XML field should import to.
		$this->core_fields_mapping = [
			'title'        => 'post_title',
			'content'      => 'post_content',
			'slug'         => 'post_name',
			'excerpt'      => 'post_excerpt',
			'status'       => 'post_status',
			'published_at' => 'post_date_gmt',
			'author_email' => 'post_author',
			'media'        => '_thumbnail_id',
			'distribution' => 'post_category',
		];

		// Define blaaa
		$this->meta_fields_mapping = [
			'opengraph_title'       => '_yoast_wpseo_title',
			'opengraph_description' => '_yoast_wpseo_metadesc',
			'label'                 => '_yoast_wpseo_primary_category',
		];

		// Set up the content formatters to process each field.
		$this->set_content_formatters();
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

		\WP_CLI::add_command(
			'newspack-content-migrator newsroom-nz-import-articles',
			[ $this, 'cmd_import_articles' ],
			[
				'shortdesc' => 'Import articles from a Newsroom NZ article XML export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'xml',
						'optional'    => false,
						'description' => 'The XML export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator newsroom-nz-fix-authors',
			[ $this, 'cmd_fix_authors' ],
			[
				'shortdesc' => 'Fixes authors on posts that have imported without one.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

	}

	/**
	 * Callable for `newspack-content-migrator newsroom-nz-fix-authors`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_fix_authors( $args, $assoc_args ) {
		$this->log( 'Importing Newsroom NZ articles...' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.' );
		}

		global $wpdb;
		$posts = $wpdb->get_results( "SELECT ID FROM {$wpdb->prefix}posts WHERE post_author = '0' AND post_type = 'post'" );
		if ( ! $posts || empty( $posts ) ) {
			$this->log( 'No posts found to process', 'error' );
		}

		// Convert the array of objects into a simple array of integer IDs.
		$posts = array_map( function( $post ) {
			return intval( $post->ID );
		}, $posts );

		// Go forth and fix!
		foreach ( $posts as $post_id ) {
			$import_data = get_post_meta( $post_id, 'newspack_nnz_import_data', true );

			// Check we have the data we need.
			if (
				empty( $import_data ) ||
				! is_array( $import_data ) ||
				! array_key_exists( 'author_firstname', $import_data ) ||
				! array_key_exists( 'author_lastname', $import_data ) ||
				! array_key_exists( 'author_email', $import_data )
			) {
				$this->log(
					sprintf(
						'Required meta data not available for post %d',
						$post_id
					),
					'warning'
				);
				continue;
			}

			// Attempt to get the user by email.
			$user = get_user_by( 'email', $import_data['author_email'] );
			if ( ! $user ) {
				$user = get_user_by(
					'login',
					$this->create_username( $import_data['author_firstname'], $import_data['author_lastname'], $import_data['author_email'] )
				);
			}

			// No user found at all, something is very wrong.
			if ( ! $user ) {
				$this->log(
					sprintf(
						'Failed to find a user for %s %s <%s> to add to post %d',
						$import_data['author_firstname'],
						$import_data['author_lastname'],
						$import_data['author_email'],
						$post_id
					),
					'warning'
				);
				continue;
			}

			// Assign the found user to the post.
			$update = ( $this->dryrun ) ? true : wp_update_post(
				[
					'ID'          => $post_id,
					'post_author' => $user->ID,
				]
			);
			if ( is_wp_error( $update ) ) {
				$this->log( sprintf( 'Failed to update post %d with author %d', $post_id, $user->id ), 'warning' );
			} else {
				$this->log( sprintf( 'Added user %d to post %d', $user->ID, $post_id ), 'success' );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator newsroom-nz-import-articles`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_articles( $args, $assoc_args ) {
		$this->log( 'Importing Newsroom NZ articles...' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.' );
		}

		// Make sure there is a path to XML provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an XML file.', 'error' );
		}

		// Format the XML into a nice array of objects and iterate.
		$articles = $this->xml_to_json( $args[0] );
		foreach ( $articles as $article ) {

			// Don't attempt to re-import anything.
			$post_exists = $this->post_exists( $article['guid'] );
			if ( $post_exists ) {
				$this->log(
					sprintf(
						'Post with guid %s already exists - imported with ID %d',
						$article['guid'],
						$post_exists
					)
				);
				continue;
			}

			// Get a list of the fields we explicitly need to import.

			// Import the post.
			$post_id = $this->import_post( $article );

			// Add the original data to the post as meta.
			if ( ! $this->dryrun ) {
				add_post_meta( $post_id, self::META_PREFIX . 'import_data', $article );
				add_post_meta( $post_id, self::META_PREFIX . 'guid', $article['guid'] );
			}

		}

	}

	/**
	 * Import the post!
	 */
	private function import_post( $fields ) {

		// Set up the post args array with defaults.
		$post_args = [
			'post_type'  => 'post',
			'meta_input' => [],
		];

		// Reduce to only the fields we need to process.
		$import_fields = $this->filter_import_fields( $fields );

		// Process each field in turn, adding to the post args array.
		foreach ( $import_fields as $name => $value ) {

			// Don't try to process empty values.
			if ( empty( $value ) ) {
				continue;
			}

			// Get the formatter for this field.
			$formatter_function = $this->get_content_formatter( $name );

			if ( $this->is_meta_field( $name ) ) {

				// Set the meta key using our special prefix, or a pre-defined key.
				$meta_key = $this->meta_fields_mapping[ $name ];

				// Add the formatted value to the meta input array.
				$post_args['meta_input'][ $meta_key ] = call_user_func( $formatter_function, $value, $fields );

			} else {

				// Get the post field that we need to assign this field to.
				$post_field = $this->core_fields_mapping[ $name ];

				// Add the formatted value to the post args array.
				$post_args[ $post_field ] = call_user_func( $formatter_function, $value, $fields );

			}
		}

		$post = ( $this->dryrun ) ? true : wp_insert_post( $post_args, true );
		if ( is_wp_error( $post ) ) {
			$this->log( sprintf( 'Failed to create post for guid %s', $import_fields['guid'] ), 'warning' );
		} else {
			$this->log( sprintf( 'Successfully imported "%s" as ID %d', $import_fields['title'], $post ), 'success' );
		}

		return $post;

	}

	/**
	 * Load XML from a file and convert to JSON.
	 *
	 * @param string $path Path to the XML file.
	 *
	 * @return array List of article object.
	 */
	private function xml_to_json( $path ) {

		// Check the XML file exists.
		if ( ! file_exists( $path ) ) {
			$this->log( sprintf( 'Failed to find log file at %s', $xml ), 'error' );
		}

		// Load the XML so we can parse it.
		$xml = simplexml_load_file( $path, null, LIBXML_NOCDATA );
		if ( ! $xml ) {
			$this->log( 'Failed to parse XML.' );
		}

		// We need to reconfigure the XML to move the `distribution` element into articles.
		$children    = $xml->children();
		$articles    = [];
		$cur_article = new stdClass();
		for ( $i = 0; $i < count( $children ); $i++ ) {

			// We have two types of elements to handle - `<article>` and `<distribution>`.
			switch ( $children[ $i ]->getName() ) {

				// We can simply add the article data to our array.
				case 'article':
					// Encoding and decoding JSON converts SimpleXML objects to standard objects.
					$cur_article = json_decode( json_encode( $children[ $i ] ), true );
					break;

				// The distribution data needs to be embedded into the previous article,
				// then we can add the article to our list.
				case 'distribution':
					$cur_article['distribution'] = json_decode( json_encode( $children[ $i ] ), true );
					$articles[] = $cur_article;
					$cur_article = '';
					break;

			}

		}

		return $articles;

	}

	/**
	 * Determine which formatter to use for each field.
	 */
	private function set_content_formatters() {
		$this->content_formatters = [
			'guid'                  => [ $this, 'return_value' ],
			'title'                 => 'esc_html',
			'content'               => [ $this, 'return_value' ],
			'slug'                  => 'sanitize_title',
			'excerpt'               => 'esc_html',
			'status'                => [ $this, 'format_status' ],
			'published_at'          => [ $this, 'format_date' ],
			'label'                 => [ $this, 'get_or_create_category' ],
			'author_email'          => [ $this, 'format_author_email' ],
			'media'                 => [ $this, 'format_media' ],
			'distribution'          => [ $this, 'format_distribution' ],
			'opengraph_title'       => [ $this, 'return_value' ],
			'opengraph_description' => [ $this, 'return_value' ],
		];
	}

	/**
	 * Get the callable function that will be used to format a field value.
	 *
	 * @param string $name Name of the field we need to format.
	 *
	 * @return callable The callable formatter function.
	 */
	private function get_content_formatter( $name ) {
		return isset( $this->content_formatters[ $name ] ) ? $this->content_formatters[ $name ] : null;;
	}

	/**
	 * Return the value.
	 *
	 * @param mixed $value The value to return.
	 *
	 * @return mixed The unmodified value.
	 */
	private function return_value( $value ) {
		return $value;
	}

	/**
	 * Sanitizes a string into a slug, which can be used in URLs or HTML attributes.
	 *
	 * @param mixed $value The value to return.
	 *
	 * @return mixed The sanitized value.
	 */
	private function sanitize_title( $value ) {
		return sanitize_title( $value );
	}

	/**
	 * Format a date field.
	 *
	 * @param string $value The timestamp from the XML.
	 *
	 * @return string The formatted date for wp_insert_post().
	 */
	private function format_date( $value ) {
		return gmdate( 'Y-m-d H:i:s', intval( $value ) );
	}

	/**
	 * Format the status field for import.
	 *
	 * @param string $value The value of the status field.
	 *
	 * @return string The value to use in wp_insert_post().
	 */
	private function format_status( $value ) {
		// Replace "published" with "publish" and "inactive" with "draft".
		return str_replace(
			[ 'published', 'inactive' ],
			[ 'publish', 'draft' ],
			$value
		);
	}

	/**
	 * Format the author_email field for import.
	 *
	 * @param string $value  The value of the author_email field.
	 * @param array  $fields All the fields for the current article.
	 *
	 * @return int|null The user ID to use in wp_insert_post(). Null on failure.
	 */
	private function format_author_email( $value, $fields ) {
		$user = get_user_by( 'email', $value );
		if ( is_a( $user, 'WP_User' ) ) {
			$user_id = $user->ID;
		} else {

			// Create a username.
			$username = $this->create_username( $fields['author_firstname'], $fields['author_lastname'], $value );

			// Create the user.
			$user_id = ( $this->dryrun ) ? 1 : wp_create_user(
				$username,
				wp_generate_password( 20 ),
				$value
			);
			if ( is_wp_error( $user_id ) ) {
				$this->log( sprintf( 'Failed to create user for email %s', $value ), 'warning' );
				return null;
			}

		}

		return $user_id;
	}

	/**
	 * Format the media field for import.
	 *
	 * @param array $value The value of the media field.
	 *
	 * @return int|null The attachment ID to use in wp_insert_post(). Null on failure.
	 */
	private function format_media( $value ) {

		// Get the filename to check if we've already imported this image.
		$filename      = explode( '/', $value['url'] );
		$filename      = end( $filename );
		$attachment_id = $this->attachments->get_attachment_by_filename( $filename );
		$caption       = ( isset( $value['caption'] ) && ! empty( $value['caption'] ) ) ? $value['caption'] : '';

		// If it doesn't already exist, import it.
		if ( is_null( $attachment_id ) ) {
			$attachment_id = ( $this->dryrun ) ? null : $this->attachments->import_external_file(
				$value['url'],     // Image URL.
				$caption, // Title.
				$caption, // Caption.
				$caption, // Description.
				''   // Alt.
			);
		}

		// Do we definitely have an imported image?
		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		return $attachment_id;
	}

	/**
	 * Format the distribution field for import.
	 *
	 * @param array $value The value of the distribution field.
	 *
	 * @return array|null The category IDs to use in wp_insert_post(). Null on failure.
	 */
	private function format_distribution( $value ) {
		if ( ! isset( $value['section'] ) || empty( $value['section'] ) ) {
			return null;
		}

		// Get the category ID, or create it, for each category name.
		return ( is_array( $value['section'] ) ) ? array_map(
			[ $this, 'get_or_create_category' ],
			$value['section']
		) : [ $this->get_or_create_category( $value['section'] ) ];
	}

	/**
	 * Create a username from author details.
	 *
	 * @param string $first_name The author's first name.
	 * @param string $last_name  The author's last name.
	 * @param string $email      The author's email address.
	 */
	private function create_username( $first_name, $last_name, $email ) {
		$username = [];
		if ( isset( $first_name ) && ! empty( $first_name ) ) {
			$username[] = strtolower( sanitize_user( $first_name ) );
		}

		if ( isset( $last_name ) && ! empty( $last_name ) ) {
			$username[] = strtolower( sanitize_user( $last_name ) );
		}

		// Combine first and last names to create a
		if ( ! empty( $username ) ) {
			$username = sanitize_user( implode( '.', $username ) );
		} else {
			// Fallback to generating a username from the email address.
			$username = sanitize_user( strstr( $email, '@', true ) );
		}

		return $username;
	}

	/**
	 * Check if a post has been imported already by checking the guid.
	 *
	 * @param string $guid GUID as provided by the XML export.
	 *
	 * @return bool True if it exists, false otherwise.
	 */
	private function post_exists( $guid ) {

		$query_args = [
			'post_type'  => 'post',
			'meta_query' => [
				[
					'key'   => self::META_PREFIX . 'guid',
					'value' => $guid,
				],
			],
		];
		$posts = get_posts( $query_args );
		return ( $posts && ! empty( $posts ) );
	}

	/**
	 * Check if a field is to be imported as meta.
	 *
	 * @param string $name The name of the field.
	 *
	 * @return bool True if it's a meta field, false if not.
	 */
	private function is_meta_field( $name ) {
		return array_key_exists( $name, $this->meta_fields_mapping );
	}

	/**
	 * Takes the import data and filters to those we need to specially import.
	 *
	 * @param array $fields The full import data.
	 *
	 * @return array Modified array containing only the data we need to process.
	 */
	private function filter_import_fields( $fields ) {

		// Make an array of field names that need processing.
		$field_names_to_import = array_merge(
			array_keys( $this->core_fields_mapping ),
			array_keys( $this->meta_fields_mapping )
		);

		// Create and return the filtered array.
		return array_filter(
			(array) $fields,
			function ( $field_name ) use ( $field_names_to_import ) {
				return in_array( $field_name, $field_names_to_import );
			},
			ARRAY_FILTER_USE_KEY
		);

	}

	/**
	 * Get a category ID from it's name, creating if it doesn't exist.
	 *
	 * @param string $name   Full textual name of the category.
	 *
	 * @return int|false ID of the found category, false if not found/failed to create.
	 */
	private function get_or_create_category( $name ) {

		// Check if the category already exists.
		$category_id = get_cat_ID( $name );

		// If not, create it.
		if ( 0 === $category_id ) {
			$this->log( sprintf( 'Category %s not found. Creating it....', $name ) );

			// Create the category, under it's parent if required.
			$category_id = ( $this->dryrun ) ? false : wp_create_category( $name );
			if ( is_wp_error( $category_id ) ) {
				$this->log( sprintf( 'Failed to create %s category', $name ) );
				$category_id = false;
			}

		}

		return $category_id;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string         $message Log message.
	 * @param string|boolean $level Whether to output the message to the CLI. Default to `line` CLI level.
	 */
	private function log( $message, $level = 'line' ) {
		$this->logger->log( 'newsroomnz', $message, $level );
	}

}