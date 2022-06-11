<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator;
use \WP_CLI;

class S3UploadsMigrator implements InterfaceMigrator {

	/**
	 * Safe limit of number of files in a folder to upload at once for S3-Uploads not to crash.
	 */
	const UPLOAD_FILES_LIMIT = 2;
	// const UPLOAD_FILES_LIMIT = 700;

	const IGNORE_UPLOADING_FILES = [
		'.DS_Store',
	];

	private $cli_upload_directory_s3_uploads_prefix;

	private $confirmed_first_upload = false;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-upload-directories',
			[ $this, 'cmd_upload_directories' ],
		[
			'shortdesc' => "Uses S3-Uploads' `upload-directory` CLI command by automatically splitting the contents of a folder in smaller, safer batches which won't cause S3-Uploads to break in error. You can provide one or more directories as positional arguments; for example, either provide individual full paths to uploads-years (recommended), or a single full path of the uploads. It uploads all the recursive contents in the folder, including subfolders if any.",
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 's3-uploads-prefix',
					'description' => "Optional. Destination root prefix '{s3-uploads-prefix}'. Used in CLI command like this `$ wp s3-uploads upload-directory /srv/htdocs/wp-content/uploads/2022/01/ {s3-uploads-prefix}uploads/2022/01`. Leave empty if the config constant S3_UPLOADS_BUCKET contains the '/wp-content' suffix (for example `define( 'S3_UPLOADS_BUCKET\', 'name-of-bucket/wp-content' );`) and if you are providing the years folders. Or if '/wp-content' suffix isn't defined in S3_UPLOADS_BUCKET, use this prefix to match the destination in your bucket.",
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'positional',
					'name'        => 'directory-to-upload',
					'description' => "Repeating positional argument at the end of the command. Provide full paths to all the (years) directories to be uploaded. For example, '$ wp newspack-content-migrator s3uploads-upload-directories /srv/htdocs/wp-content/uploads/2019 /srv/htdocs/wp-content/uploads/2020 /srv/htdocs/wp-content/uploads/2021 /srv/htdocs/wp-content/uploads/2022",
					'optional'    => false,
					'repeating'   => true,
				],
			],
		] );
	}

	/**
	 * @param array $positional_args
	 * @param array $assoc_args
	 */
	public function cmd_upload_directories( $positional_args, $assoc_args ) {

		// Check if S3-Uploads is installed and active.
		if ( ! $this->is_s3_uploads_plugin_active() ) {
			WP_CLI::error( 'S3-Uploads plugin needs to be installed and active to run this command.' );
		}

		// Fetch arguments.
		$this->cli_upload_directory_s3_uploads_prefix = isset( $assoc_args['s3-uploads-prefix'] ) ? $assoc_args['s3-uploads-prefix'] : null;
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			WP_CLI::error( 'WP_CONTENT_DIR is not defined.' );
		}
		$this->path_wp_content = WP_CONTENT_DIR;
		$directories = [];
		foreach ( $positional_args as $positional_arg ) {
			if ( ! file_exists( $positional_arg ) || ! is_dir( $positional_arg ) ) {
				WP_CLI::error( sprintf( "Incorrect uploads directory path %s.", $positional_arg ) );
			}
			$directories[] = rtrim( $positional_arg, '/' );
		}

		foreach ( $directories as $directory ) {
			$this->upload_contents_in_directory( $directory );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Checks whether S3-Uploads Plus is installed and active.
	 *
	 * @return bool Is active.
	 */
	public function is_s3_uploads_plugin_active() {
		$active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( strtolower( $plugin ), '/s3-uploads.php' ) ) {
				$active = true;
			}
		}

		return $active;
	}

	/**
	 * @param $directory_path
	 *
	 * @return void
	 */
	public function upload_contents_in_directory( $directory_path ) {

		// Get list of files in $directory_path.
		$files = scandir( $directory_path );
		$ignore_files = array_merge( [ '.', '..', ], self::IGNORE_UPLOADING_FILES );
		foreach ( $ignore_files as $file_ignore ) {
			unset( $files[ array_search( $file_ignore, $files, true) ] ) ;
		}
		sort( $files );

		// Return if empty.
		if ( count( $files ) < 1 ) {
			return;
		}

		// Get a safe number of files to upload in a batch.
		$i_current_batch = 0;
		$batch = [];
		foreach( $files as $file ) {
			$file_path = $directory_path . '/' . $file;

			// If it's a directory, run this method recursively.
			if ( is_dir( $file_path ) ) {
				$this->upload_contents_in_directory( $file_path );
			}

			// Add file to batch, or upload the batch.
			if ( 0 !== count( $batch ) && 0 === ( count( $batch ) % self::UPLOAD_FILES_LIMIT ) ) {

				// Upload batch.
				$i_current_batch++;
				WP_CLI::log( sprintf( "  - uploading batch #%d in %s -- from %s to %s...", $i_current_batch, $directory_path, str_replace( $directory_path . '/', '', $batch[0] ), str_replace( $directory_path  . '/', '', $batch[ count( $batch ) - 1 ] ) ) );
				$this->upload_a_batch_of_files( $batch );

				// Reset the loop.
				$batch = [];
			} else {
				$batch[] = $file_path;
			}
		}

		// Upload remaining files.
		if ( ! empty( $batch ) ) {
			$i_current_batch++;
			WP_CLI::log( sprintf( "  - uploading batch #%d in %s -- from %s to %s...", $i_current_batch, $directory_path, str_replace( $directory_path . '/', '', $batch[0] ), str_replace( $directory_path  . '/', '', $batch[ count( $batch ) - 1 ] ) ) );
			$this->upload_a_batch_of_files( $batch );
		}
	}

	/**
	 * Uploads a batch of files in the same directory.
	 *
	 * @param $batch_of_files
	 *
	 * @return void
	 */
	public function upload_a_batch_of_files( $batch_of_files ) {
		if ( empty( $batch_of_files ) ) {
			return;
		}

		// Get directory info.
		$first_file = $batch_of_files[0];
		$file_pathinfo = pathinfo( $first_file );
		$dir_name = $file_pathinfo[ 'dirname' ];

		// Prepare the tmp directory with the '__S3UPLOAD' suffix.
		$dir_name_tmp = $dir_name . '__S3UPLOAD';
		if ( file_exists( $dir_name_tmp ) ) {
			unlink( $dir_name_tmp );
		}
		mkdir( $dir_name_tmp, 0777, true );

		// Temporarily cp files from batch to this tmp directory.
		foreach ( $batch_of_files as $key_file => $file ) {
			$file_pathinfo = pathinfo( $file );
			$file_destination = $dir_name_tmp . '/' . $file_pathinfo[ 'basename' ];
			copy( $file, $file_destination );
		}

		// Upload the tmp directory.
		$this->upload_folder_to_s3( $dir_name_tmp );

		// rm the tmp files.
		unlink( $dir_name_tmp );
	}

	public function upload_folder_to_s3( $dir ) {
		$cli_command = sprintf(
			"wp s3-uploads upload-directory %s/ %suploads/2022/01_test",
			$dir,
			$this->cli_upload_directory_s3_uploads_prefix
		);

		// Prompt the user before running the first command, just to double check the format of everything.
		if ( false === $this->confirmed_first_upload ) {
			WP_CLI::log( "\nPlease confirm that the first upload-directory command that's about to be executed uses the correct S3 destination:" );
			WP_CLI::log( '  $ ' . $cli_command );
			WP_CLI::confirm( 'Do you want to proceed?' );
			$this->confirmed_first_upload = true;
		}

		// Upload away...
		$options = [
			'return'     => true,
			'launch'     => false,
			'exit_error' => true,
		];
		WP_CLI::runcommand( $cli_command, $options );
	}
}
