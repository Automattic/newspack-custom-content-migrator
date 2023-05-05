<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

/* Internal dependencies */
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
/* External dependencies */
use stdClass;
use WP_CLI;
use WP_Query;
use WP_User;

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
	 * Co-Authors Plys logic.
	 *
	 * @var null|CoAuthorsPlus
	 */
	private $coauthorsplus;

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
	 * Headers from the CSV file we're currently importing, if any.
	 *
	 * @var array
	 */
	private $csv_headers;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();

		$this->attachments = new Attachments();

		$this->coauthorsplus = new CoAuthorPlus();

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

		\WP_CLI::add_command(
			'newspack-content-migrator newsroom-nz-author-names',
			[ $this, 'cmd_author_names' ],
			[
				'shortdesc' => 'Makes sure authors have full names.',
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

		\WP_CLI::add_command(
			'newspack-content-migrator newsroom-nz-import-users',
			[ $this, 'cmd_import_users' ],
			[
				'shortdesc' => 'Makes sure authors have full names.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'A CSV containing user data.',
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
			'newspack-content-migrator newsroom-nz-find-missing-articles',
			[ $this, 'cmd_find_missing_articles' ],
			[
				'shortdesc' => 'Find missing articles.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'dir',
						'optional'    => false,
						'description' => 'A directory containing XML files of articles.',
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
		$this->log( 'Importing Newsroom NZ articles...', 'info' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'info' );
		}

		// Make sure there is a path to XML provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an XML file.', 'error' );
		}

		// Format the XML into a nice array of objects and iterate.
		$articles = $this->xml_to_json( $args[0] );

		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( 'Importing %d articles', count( $articles ) ),
			count( $articles )
		);

		foreach ( $articles as $article ) {
			$progress->tick();

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

			// Import the post.
			$post_id = $this->import_post( $article );

			// Add the original data to the post as meta.
			if ( ! $this->dryrun ) {
				add_post_meta( $post_id, self::META_PREFIX . 'import_data', $article );
				add_post_meta( $post_id, self::META_PREFIX . 'guid', $article['guid'] );
			}

		}

		$progress->finish();

	}

	/**
	 * Callable for `newspack-content-migrator newsroom-nz-author-names`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_author_names( $args, $assoc_args ) {
		global $wpdb;
		$this->log( 'Adding Newsroom NZ author names...' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.' );
		}

		// Get all the user IDs and emails for users without a first name.
		$users = $wpdb->get_results(
			"SELECT ID, user_email FROM {$wpdb->users} INNER JOIN {$wpdb->usermeta} ON {$wpdb->users}.ID = {$wpdb->usermeta}.user_id WHERE {$wpdb->usermeta}.meta_key = 'first_name' AND {$wpdb->usermeta}.meta_value IS NULL OR {$wpdb->usermeta}.meta_value = ''",
		);

		foreach ( $users as $user ) {
			// Find this user's data.
			$import_data = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					AND meta_value
					LIKE %s",
					self::META_PREFIX . 'import_data',
					'%' . $user->user_email . '%'
				)
			);
			if ( ! $import_data ) {
				$this->log( sprintf( 'No import data found with email %s', $user->user_email ), 'warning' );
				continue;
			}

			// We might need to serialize the data here.
			$import_data = maybe_unserialize( $import_data );

			// Update the user's first and last name.
			$update = ( $this->dryrun ) ? true : wp_update_user(
				[
					'ID'           => $user->ID,
					'first_name'   => $import_data['author_firstname'],
					'last_name'    => $import_data['author_lastname'],
					'display_name' => $import_data['author_firstname'] . ' ' . $import_data['author_lastname'],
				]
			);
			if ( is_wp_error( $update ) ) {
				$this->log( sprintf( 'Failed to update user %s', $user->user_email ), 'warning' );
				continue;
			}

			$this->log( sprintf( 'Added names for user with email %s and ID %d', $user->user_email, $user->ID ), 'success' );

		}

	}

	/**
	 * Callable for `newspack-content-migrator newsroom-nz-import-users`
	 */
	public function cmd_import_users( $args, $assoc_args ) {
		$this->log( 'Adding Newsroom NZ author names...', 'info' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'info' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d users', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv ) ) !== false ) {
			$progress->tick();

			// Unpack the fields into the format expected by WordPress.
			$user = [
				'first_name'    => $row[0],
				'last_name'     => $row[1],
				'user_login'    => $row[2],
				'user_nicename' => $row[0] . ' ' . $row[1],
				'user_pass'     => wp_generate_password( 20 ),
				'display_name'  => $row[0] . ' ' . $row[1],
				'user_email'    => $row[3],
				'role'          => $this->fix_role( $row[4] ),
				'description'   => $row[9],
				'meta_input'    => [
					self::META_PREFIX . 'import_data' => $row,
				],
			];

			// If the user exists, attempt to update it.
			if ( $this->user_exists( $user['user_email'] ) ) {
				$this->maybe_update_user( $user );
				continue;
			}

			// No user, so let's check if we're creating a user account or a guest author.
			if ( empty( $user['role'] ) ) {
				// Create guest author
				$guest_author = ( $this->dryrun ) ? true : $this->coauthorsplus->create_guest_author( $user );
				
				// Now add the meta data to the guest author.
				if ( ! $this->dryrun ) {
					add_post_meta( $guest_author, self::META_PREFIX . 'import_data', $row );
				}
			} else {
				// Create WP User.
				$user_id = ( $this->dryrun ) ? true : wp_insert_user( $user );
				if ( is_wp_error( $user_id ) ) {
					$this->log( sprintf( 'Failed to create user %s', $user['user_email'] ) );
					continue;
				}
			}
		}

		$progress->finish();

	}

	/**
	 * Callable for `newspack-content-migrator newsroom-nz-find-missing-articles`
	 * 
	 * @param array $args       The arguments passed to the command.
	 * @param array $assoc_args The associative arguments passed to the command.
	 * 
	 * @return void
	 */
	public function cmd_find_missing_articles( $args, $assoc_args ) {
		$this->log( 'Finding missing articles...', 'info' );

		// Make sure there is a directory path provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to a directory of XML files.', 'error' );
		}

		$dir = trailingslashit( $args[0] );

		// Make sure the directory exists.
		if ( ! is_dir( $dir ) ) {
			$this->log( 'The directory provided does not exist.', 'error' );
		}

		// Get the list of XML files.
		$files = glob( $dir . 'articles_*.xml' );
		if ( empty( $files ) ) {
			$this->log( 'No XML files found in the directory provided.', 'error' );
		}

		// Create a new XML file to store the missing articles.
		$missing_articles = 'missing_articles.xml';
		file_put_contents( $missing_articles, '<?xml version="1.0" encoding="UTF-8"?><articles>' );

		// Loop through each file.
		foreach ( $files as $file ) {

			$this->log( sprintf( 'Processing file %s', $file ), 'info' );

			// Format the XML into a nice array of objects and iterate.
			$articles = $this->xml_to_json( $file );

			// Start the progress bar.
			$progress = \WP_CLI\Utils\make_progress_bar(
				sprintf( 'Processing %d articles', count( $articles ) ),
				count( $articles )
			);

			// Loop through each article.
			foreach ( $articles as $article ) {

				$progress->tick();

				// Check if the article exists.
				if ( $this->post_exists( $article['guid'] ) ) {
					continue;
				}

				// Log the missing article.
				file_put_contents( $missing_articles, $this->article_to_xml( $article ), FILE_APPEND );

			}

			$progress->finish();
			file_put_contents( $missing_articles, '</articles>', FILE_APPEND );

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
			$this->log( sprintf( 'Failed to create post for guid %s', $import_fields['guid'] ), );
		} else {
			$this->log( sprintf( 'Successfully imported "%s" as ID %d', $import_fields['title'], $post ) );
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
			$this->log( 'Failed to parse XML.', 'error' );
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
	 * Convert an article object to XML.
	 *
	 * @param array $article Article object.
	 *
	 * @return string XML string.
	 */
	private function article_to_xml( $article ) {
		
		$xml = new \SimpleXMLElement( '<article></article>' );
		$this->array_to_xml( $article, $xml );
		return $xml->asXML();

	}

	private function array_to_xml( $data, &$xml ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$child = $xml->addChild( $key );
				$this->array_to_xml( $value, $child );
			} else {
				$value = ( empty( $value ) ) ? '' : $value;
				$xml->addChild( $key, htmlspecialchars( $value ) );
			}
		}
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
			
			// Set the user details.
			$user_email   = $value;
			$first_name   = ( ! empty( $fields['author_firstname' ] ) ) ? $fields['author_firstname'] : '';
			$last_name    = ( ! empty( $fields['author_lastname' ] ) ) ? $fields['author_lastname'] : '';
			$display_name = $first_name . ' ' . $last_name;
			$user_login   = $this->create_username( $fields['author_firstname'], $fields['author_lastname'], $user_email );
			$user_pass    = wp_generate_password( 20 );

			// Create the user.
			$user_id = ( $this->dryrun ) ? 1 : wp_insert_user(
				[
					'user_login'   => $user_login,
					'user_pass'    => $user_pass,
					'role'		   => 'author',
					'user_email'   => $user_email,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'display_name' => $display_name,
				]
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
				trim( $value['url'] ),     // Image URL.
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
	 * Get the user based on an email address.
	 * 
	 * @param string $email Email address to search on.
	 * 
	 * @return int|bool The user ID if found, false if not.
	 */
	private function user_exists( $email ) {
		$user = get_user_by( 'email', $email );
		return ( $user ) ? $user->ID : false;
	}

	/**
	 * Update a user's details if we need to.
	 * 
	 * @param array $user_data The user data to update.
	 * 
	 * @return WP_User|false The updated user object, false if failed.
	 */
	private function maybe_update_user( $user_data ) {
		
		// Check if the user exists.
		$user_id = $this->user_exists( $user_data['user_email'] );
		if ( ! $user_id ) {
			return false;
		}

		// Get the existing user object.
		$user = get_user_by( 'id', $user_id );

		// Check if we need to update the user.
		$diff = strcmp( json_encode( $user_data ), json_encode( $user->to_array() ) );
		if ( 0 !== $diff ) {
			$updated_user = wp_parse_args( $user_data, $user->to_array() );
			$user_id      = ( $this->dryrun ) ? true : wp_update_user( $updated_user );
			if ( is_wp_error( $user_id ) ) {
				$this->log( sprintf( 'Failed to update user %s', $user->user_login ) );
				$user_id = false;
			}
		}

		return $user_id;
	}

	/**
	 * Fix the role values from the import spreadsheet.
	 * 
	 * @param string $role The role to fix.
	 * 
	 * @return string The fixed role.
	 */
	private function fix_role( $role ) {
		switch ( $role ) {
			case 'Authors':
			case 'Author':
				$role = 'author';
				break;
			case 'Contributors':
				$role = 'contributor';
				break;
			case 'Publishers':
				$role = 'editor';
				break;
			case 'Administrator':
				$role = 'administrator';
				break;
			default:
				$role = 'subscriber';
				break;
		}
		return $role;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string         $message Log message.
	 * @param string|boolean $level Whether to output the message to the CLI. Default to `line` CLI level.
	 */
	private function log( $message, $level = false ) {
		$this->logger->log( 'newsroomnz', $message, $level );
	}

}