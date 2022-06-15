<?php
/**
 * S3-Uploads General Migrator.
 *
 * @package newspack-custom-content-migrator
 */

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * S3UploadsMigrator.
 */
class S3UploadsMigrator implements InterfaceMigrator {

	/**
	 * Detailed logs file name.
	 */
	const LOG = 's3-uploads-migrator.log';

	/**
	 * Safe number of files to upload in a folder at once for S3-Uploads not to crash.
	 */
	const UPLOAD_FILES_LIMIT = 700;

	/**
	 * Batches of files for upload will be temporarily copied to a temp folder with this suffix, e.g. wp-content/uploads/YYYY/MM{SUFFIX}.
	 */
	const TMP_BATCH_FOLDER_SUFFIX = '__TMPS3UPLOADBATCH';

	/**
	 * Files to ignore while uploading.
	 */
	const IGNORE_UPLOADING_FILES = [
		'.DS_Store',
	];

	/**
	 * Internal flag whether first upload was confirmed via prompt.
	 *
	 * @var bool $confirmed_first_upload Flag, used to prompt the User to confirm the accuracy of the CLI command before running it for the first time.
	 */
	private $confirmed_first_upload = false;

	/**
	 * Internal flag whether prompt before first upload should be skipped.
	 *
	 * @var bool $confirmed_first_upload Flag, used to skip the prompt before uploading the first batch.
	 */
	private $skip_prompt_before_first_upload = false;

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceMigrator $instance Instance.
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
			self::$instance = new $class();
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
				'shortdesc' => "Runs S3-Uploads' `upload-directory` CLI command by splitting the contents of a folder into smaller, safer batches which won't cause S3-Uploads to break in error. It uploads all the recursive contents in the folder, including subfolders if any.",
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'directory-to-upload',
						'description' => 'Full path to a directory to be uploaded. Recommended to upload one year folder at a time. For example, --directory-to-upload=/srv/htdocs/wp-content/uploads/2019',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 's3-uploads-destination',
						'description' => "S3 destination root. Used in the CLI command like this, `$ wp s3-uploads upload-directory --directory-to-upload=/srv/htdocs/wp-content/uploads/2019 --s3-uploads-destination=uploads/2019`. This value depends on the S3_UPLOADS_BUCKET constant and whether it contains the '/wp-content' suffix as root destination or not. E.g. if we're uploading the folder --directory-to-upload=/srv/htdocs/wp-content/uploads/2019, and the constant already contains the '/wp-content' `define( 'S3_UPLOADS_BUCKET\', 'name-of-bucket/wp-content' );`, then this param whould be --s3-uploads-destination=uploads/2019.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'skip-prompt-before-first-upload',
						'description' => 'Use to skip prompting before uploading the first batch.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator s3uploads-upload-directories`.
	 *
	 * @param array $positional_args Positional args.
	 * @param array $assoc_args      Associative args.
	 */
	public function cmd_upload_directories( $positional_args, $assoc_args ) {

		// Check if S3-Uploads is installed and active.
		if ( ! $this->is_s3_uploads_plugin_active() ) {
			WP_CLI::error( 'S3-Uploads plugin needs to be installed and active to run this command.' );
		}

		// Arguments and variables.
		$this->skip_prompt_before_first_upload = $assoc_args['skip-prompt-before-first-upload'] ?? false;
		$cli_s3_uploads_destination            = $assoc_args['s3-uploads-destination'] ?? null;
		$directory_to_upload                   = $assoc_args['directory-to-upload'] ?? null;
		if ( ! file_exists( $directory_to_upload ) || ! is_dir( $directory_to_upload ) ) {
			WP_CLI::error( sprintf( 'Incorrect uploads directory path %s.', $directory_to_upload ) );
		}

		// Upload directory.
		$this->upload_directory( $directory_to_upload, $cli_s3_uploads_destination );

		WP_CLI::success( sprintf( 'Done. See log %s for full details.', self::LOG ) );
	}

	/**

	 * Recursive function. Splits files in directory into smaller batches and runs upload of batches.
	 *
	 * @param string $directory_path Full directory path to upload.
	 * @param string $cli_s3_uploads_destination S3 destination param in S3-Uploads's uplad-directory CLI command.
	 *
	 * @return void
	 */
	public function upload_directory( $directory_path, $cli_s3_uploads_destination ) {

		// Get list of files in $directory_path.
		$files        = scandir( $directory_path );
		$ignore_files = array_merge( [ '.', '..' ], self::IGNORE_UPLOADING_FILES );
		foreach ( $ignore_files as $file_ignore ) {
			unset( $files[ array_search( $file_ignore, $files, true ) ] );
		}
		sort( $files );

		// Return if empty.
		if ( count( $files ) < 1 ) {
			return;
		}

		// Prepare batches of files with a safe number of files to upload.
		$i_current_batch = 0;
		$batch           = [];
		foreach ( $files as $file ) {
			$file_path = $directory_path . '/' . $file;

			// If it's a directory, run this method recursively.
			if ( is_dir( $file_path ) ) {
				$cli_s3_uploads_destination_subdirectory = $cli_s3_uploads_destination . '/' . $file;
				$this->upload_directory( $file_path, $cli_s3_uploads_destination_subdirectory );
				continue;
			}

			// Add file to batch, or upload the batch.
			if ( 0 !== count( $batch ) && 0 === ( count( $batch ) % self::UPLOAD_FILES_LIMIT ) ) {
				// Upload batch.
				$i_current_batch++;
				WP_CLI::log( sprintf( 'Uploading %s -- batch #%d, files from %s to %s...', $directory_path, $i_current_batch, str_replace( $directory_path . '/', '', $batch[0] ), str_replace( $directory_path . '/', '', $batch[ count( $batch ) - 1 ] ) ) );
				$this->upload_a_batch_of_files( $batch, $cli_s3_uploads_destination );

				// Reset the loop.
				$batch = [];
			}

			$batch[] = $file_path;
		}

		// Upload remaining files.
		if ( ! empty( $batch ) ) {
			$i_current_batch++;
			WP_CLI::log( sprintf( 'Uploading %s -- batch #%d, files from %s to %s...', $directory_path, $i_current_batch, str_replace( $directory_path . '/', '', $batch[0] ), str_replace( $directory_path . '/', '', $batch[ count( $batch ) - 1 ] ) ) );
			$this->upload_a_batch_of_files( $batch, $cli_s3_uploads_destination );
		}
	}

	/**
	 * Prepares files in a batch for upload by copying them into a tmp folder.
	 *
	 * @param array  $batch_of_files             Full path to files to be uploaded.
	 * @param string $cli_s3_uploads_destination S3 destination param in S3-Uploads's uplad-directory CLI command.
	 *
	 * @return void
	 */
	public function upload_a_batch_of_files( $batch_of_files, $cli_s3_uploads_destination ) {
		if ( empty( $batch_of_files ) ) {
			return;
		}

		// Get these files' path.
		$first_file    = $batch_of_files[0];
		$file_pathinfo = pathinfo( $first_file );
		$file_dir_name = $file_pathinfo['dirname'];

		// Prepare the tmp directory for the batch.
		$dir_name_tmp = $file_dir_name . self::TMP_BATCH_FOLDER_SUFFIX;
		if ( file_exists( $dir_name_tmp ) ) {
			$this->delete_directory( $dir_name_tmp );
		}
		// phpcs:ignore
		mkdir( $dir_name_tmp, 0777, true );

		// Temporarily cp files from batch to this tmp directory.
		foreach ( $batch_of_files as $key_file => $file ) {
			$file_pathinfo    = pathinfo( $file );
			$file_destination = $dir_name_tmp . '/' . $file_pathinfo['basename'];
			copy( $file, $file_destination );
		}

		// Upload the tmp batch directory.
		$this->run_cli_upload_directory( $dir_name_tmp, $cli_s3_uploads_destination );

		// Clean up and delete the tmp batch directory.
		$this->delete_directory( $dir_name_tmp );
	}

	/**
	 * Runs the S3-Uploads' upload-directory CLI command on a folder.
	 * Before running the first command, it outputs a prompt as a chance to confirm command accuracy.
	 *
	 * @param string $dir_from                   Folder to upload.
	 * @param string $cli_s3_uploads_destination S3 destination param in S3-Uploads's uplad-directory CLI command.
	 *
	 * @return void
	 */
	public function run_cli_upload_directory( $dir_from, $cli_s3_uploads_destination ) {
		// CLI upload-directory command.
		$cli_command = sprintf( 's3-uploads upload-directory %s/ %s', $dir_from, $cli_s3_uploads_destination );

		// Prompt the user before running the command for the first time, as an opportunity to double-check the correct format.
		if ( false == $this->skip_prompt_before_first_upload && false === $this->confirmed_first_upload ) {
			WP_CLI::log( "\nAbout to upload the first batch of files with this command:" );
			WP_CLI::log( '  $ ' . $cli_command );
			$input = readline( 'OK to run this? [y/n] ' );
			if ( 'y' != $input ) {
				// Clean up the temporary directory with a batch of files.
				$this->delete_directory( $dir_from );
				exit;
			}
		}

		// Run the CLI command.
		$options = [
			'return'     => true,
			'launch'     => false,
			'exit_error' => true,
		];
		WP_CLI::runcommand( $cli_command, $options );

		// Log the full command and list of the files uploaded.
		$this->log_command_and_files( $dir_from, $cli_command );

		// Also prompt after completing the first command, to give one a chance to examine the results before continuing. No more prompts after this one.
		if ( false == $this->skip_prompt_before_first_upload && false === $this->confirmed_first_upload ) {
			WP_CLI::log( sprintf( 'First batch of max %d files was uploaded. Feel free to check the log %s and contents of the bucket before continuing. All following commands will run without prompts.', self::UPLOAD_FILES_LIMIT, self::LOG ) );
			$input = readline( 'OK to run this? [y/n] ' );
			if ( 'y' != $input ) {
				// Clean up the temporary directory with a batch of files.
				$this->delete_directory( $dir_from );
				exit;
			}

			$this->confirmed_first_upload = true;
		}

	}

	/**
	 * Saves a full log of the CLI command and all files affected.
	 *
	 * @param string $dir_from    Temp folder which was uploaded.
	 * @param string $cli_command CLI command.
	 *
	 * @return void
	 */
	public function log_command_and_files( $dir_from, $cli_command ) {
		// Get files in batch.
		$files        = scandir( $dir_from );
		$ignore_files = array_merge( [ '.', '..' ], self::IGNORE_UPLOADING_FILES );
		foreach ( $ignore_files as $file_ignore ) {
			unset( $files[ array_search( $file_ignore, $files, true ) ] );
		}
		sort( $files );

		$this->log( self::LOG, sprintf( "%s\n%s", $cli_command, implode( "\n", $files ) ), false );
	}

	/**
	 * Deletes directory and all its contents.
	 *
	 * @param string $dir Directory to be deleted.
	 *
	 * @return void
	 */
	public function delete_directory( $dir ) {
		$files = glob( $dir . '/*' );
		foreach ( $files as $file ) {
			if ( is_dir( $file ) ) {
				$this->delete_directory( $file );
			} else {
				// phpcs:ignore
				unlink( $file );
			}
		}
		// phpcs:ignore
		rmdir( $dir );
	}

	/**
	 * Checks whether S3-Uploads Plus is installed and active.
	 *
	 * @return bool Is S3-Uploads active.
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
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli  Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
