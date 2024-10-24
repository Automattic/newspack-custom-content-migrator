<?php
/**
 * S3-Uploads General Migrator.
 *
 * @package newspack-custom-content-migrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\PHP;
use WP_CLI;
use WP_Error;

/**
 * S3UploadsMigrator.
 */
class S3UploadsMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

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
	 * Posts logic
	 *
	 * @var Posts $posts Posts logic.
	 */
	private $posts;

	/**
	 * Constructor.
	 */
	private function __construct() {

		$this->posts = new Posts();

		// Function \readline() has gone missing from Atomic, so here's it is back.
		if ( ! function_exists( 'readline' ) ) {

			/**
			 * Taken from class-wp-cli.php function confirm() and simplified.
			 *
			 * @param string $question   Question to display before the prompt.
			 * @param array  $assoc_args Skips prompt if 'yes' is provided.
			 *
			 * @return string CLI user input.
			 */
			function readline( $question, $assoc_args = [] ) {
				fwrite( STDOUT, $question );
				$answer = strtolower( trim( fgets( STDIN ) ) );

				return $answer;
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-upload-directories',
			self::get_command_closure( 'cmd_upload_directories' ),
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
		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-remove-subfolders-from-uploadsyyyymm',
			self::get_command_closure( 'cmd_remove_subfolders_from_uploadsyyyymm' ),
			[
				'shortdesc' => "Some S3 integration plugin use a custom subfolder in e.g. '~/wp-content/uploads/YYYY/MM/SUBFOLDER/image.jpg'. This command removes all such subfolders, and moves all the images one level below to '~/wp-content/uploads/YYYY/MM/image.jpg'.",
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-compare-uploads-contents-local-with-s3',
			self::get_command_closure( 'cmd_compare_uploads_contents_local_with_s3' ),
			[
				'shortdesc' => 'This command contains instructions how to compare files from local path with files on S3, and lists a diff -- files that are present on local but missing on S3. ' .
								'1. Save list of all files from local folder to a --local-log file, run this either on entire uploads/ or year by year. ' . 
								'e.g for entire uploads/: ' .
								'  $ find uploads -type f > uploads_local.txt ; ' .
								'or e.g just for year 2009: ' .
								'  $ find 2009 -type f > 2009_local.txt ; ' .
								'' .
								'2. Save list of all files from S3 to --s3-log file, run this for the folder, ' .
								'e.g. for entire uploads/: ' .
								"  $ aws s3 ls --profile berkeleyside s3://newspack-berkeleyside-cityside/wp-content/uploads/ --recursive | awk {'print $4'} > uploads_s3.txt ; " .
								'or e.g justfor year 2009: ' .
								"  $ aws s3 ls --profile berkeleyside s3://newspack-berkeleyside-cityside/wp-content/uploads/2009/ --recursive | awk {'print $4'} > 2009_s3.txt ; " .
								'' .
								'3. Open the list of files on S3 from step 2. and check if there is a difference in local VS S3 paths. If S3 paths have a prefix segment that is missing from the paths of local files, provide it as an argument e.g. --path-to-this-folder-on-s3=wp-content/uploads/ ' .
								'' .
								'Last step is to compare the two files from steps 1. and 2. There is different ways to get the diff. Use one of the following ones.' .
								'4a. On OSX flavored bash, the resulting two files can easily be diff-ed using the command, however first make sure that the paths/prefixes inside both files are the same: ' .
								'  $ comm -23 <(sort uploads_local.txt) <(sort uploads_s3.txt) > diff_exist_on_local_but_not_on_s3.txt' .
								'' .
								'4b. On Linux bash, a diff can be located like this, also previously making sure that paths/prefixes inside both files match:' . 
								"  $ diff --changed-group-format='%>' --unchanged-group-format='' uploads_local.txt uploads_s3.txt | grep -v '^$' > diff_exist_on_local_but_not_on_s3.txt " .
								'' .
								'4c. The two files can also be diffed by running this command like this: ' .
								'  wp newspack-content-migrator s3uploads-compare-uploads-contents-local-with-s3 \ ' .
								'    --local-log=2009_local.txt \ ' .
								'    --s3-log=2009_s3.txt \ ' .
								'    --path-to-this-folder-on-s3=wp-content/uploads/ ' .
								'' .
								'which will list/log all the files that are present on local but missing on S3.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'local-log',
						'description' => 'Output file of command which lists local files, e.g. for folder 2009: `find 2009 -type f > 2009_local.txt`',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 's3-log',
						'description' => "Output file of command which lists files on S3 using AWS SKD CLI, e.g. for folder 2009: `aws s3 ls --profile berkeleyside s3://newspack-berkeleyside-cityside/wp-content/uploads/2009/ --recursive | awk {'print $4'} > 2009_s3.txt`",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'path-to-this-folder-on-s3',
						'description' => 'An extra prefixed path segment for files on S3 as opposed to files on local.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-download-all-image-sizes-from-atomic',
			self::get_command_closure( 'cmd_download_all_image_sizes_from_atomic' ),
			[
				'shortdesc' => 'Downloads missing intermediate image sizes from given Atomic server which are not present on local disk.' .
					'Additional info:' .
					"-- try and check sizes registered on Atomic by running `$ wp eval 'global \$_wp_additional_image_sizes; var_dump(\$_wp_additional_image_sizes);'`" .
					'-- discover which sizes may have been used in post_content with `s3uploads-discover-image-sizes-used-in-post-content` command.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'remote-host',
						'description' => "Download missing intermediate images (sizes not present on this local) from this remote (i.e. Atomic) hostname, e.g. 'publisher.com'",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids',
						'description' => 'Run only for these attachment ids, CSV. Otherwise, will run for all attachments.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids-range-from',
						'description' => 'Run only for these attachment ids, also required attachment-ids-range-to.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids-range-to',
						'description' => 'Run only for these attachment ids, also required attachment-ids-range-from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'also-download-sizes-with-scaled-filename',
						'description' => "For scaled images, when downloading sizes also download sizes with scaled filename. Explanation on an example: let's say a large image img.jpg was uploaded, and WP created img-scaled.jpg alongside the original file. When sizes are generated, they should be based off the original file name, e.g. img-100x100.jpg. But selecting this flag will also download that same size as img-scaled-100x100.jpg as well. This is how in some cases some historic WP sites utilize sizes.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'download-extra-sizes',
						'description' => 'Downloaded extra sizes, CSV. E.g. --download-extra-sizes=1200x900,150x55 NOTE: these sizes will be downloaded verbatim with locked aspect ratio, while regular scaled images could get relative width and height depending on image dimensions.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'only-download-sizes',
						'description' => 'Download only these specific sizes, CSV. E.g. --only-download-sizes=1200x900,150x55 NOTE: This will override and ignore all other sizes and will download only the sizes listed here. These sizes will not be dynamically scaled and relative to the original image dimensions, but only used verbatim as they are with locked aspect ratio which may or may not correspond to specific attachment IDs ratios.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'download-all-registered-sizes-with-locked-ratio',
						'description' => 'Development option. Will add all to list of sizes to be downloaded all the sizes registered in `\get_intermediate_image_sizes()` and `global $_wp_additional_image_sizes`. NOTE: these sizes will be downloaded verbatim with locked aspect ratio, while regular scaled images could get relative width and height depending on image dimensions.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator s3uploads-discover-image-sizes-used-in-post-content',
			self::get_command_closure( 'cmd_discover_image_sizes_used_in_post_content' ),
			[
				'shortdesc' => 'Loops through all published Posts and Pages and discovers which image sizes might have been used in Posts and Pages.',
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator s3uploads-compare-uploads-contents-local-with-s3`, see command sortdesc above.
	 *
	 * @param array $positional_args Positional args.
	 * @param array $assoc_args      Associative args.
	 *
	 * @return void
	 */
	public function cmd_compare_uploads_contents_local_with_s3( $positional_args, $assoc_args ) {
		$local_log_path       = $assoc_args['local-log'] ?? null;
		$s3_log_path          = $assoc_args['s3-log'] ?? null;
		$path_to_folder_on_s3 = $assoc_args['path-to-this-folder-on-s3'] ?? '';

		// Clear output results log file.
		$notfound_log = 's3uploads-compare-files-local-w-s3.log';
		if ( file_exists( $notfound_log ) ) {
			unlink( $notfound_log );
		}

		// Get lists of files from logs.
		$local_log_content = file_get_contents( $local_log_path );
		$local_lines       = explode( "\n", $local_log_content );
		if ( false === $local_log_content || empty( $local_lines ) ) {
			WP_CLI::error( $local_log_path . ' not found or file empty.' );
		}
		$s3_log_content = file_get_contents( $s3_log_path );
		$s3_lines       = explode( "\n", $s3_log_content );
		if ( false === $s3_log_content || empty( $s3_lines ) ) {
			WP_CLI::error( $s3_log_path . ' not found or file empty.' );
		}

		// Filter out file names from S3 list (which contains more file info).
		foreach ( $s3_lines as $s3_line ) {
			$pos_start = strpos( $s3_line, $path_to_folder_on_s3 );
			$s3_keys[ substr( $s3_line, $pos_start + strlen( $path_to_folder_on_s3 ) ) ] = 1;
		}

		// Compare files.
		foreach ( $local_lines as $key_local_line => $local_line ) {

			WP_CLI::log( sprintf( '(%d)/(%d)', $key_local_line + 1, count( $local_lines ) ) );

			if ( isset( $s3_keys[ $local_line ] ) ) {
				unset( $s3_keys[ $local_line ] );
			} else {
				WP_CLI::log( sprintf( 'NOTFOUND %s', $local_line ) );
				file_put_contents( $notfound_log, $local_line . "\n", FILE_APPEND );
			}
		}

		// Display results.
		if ( file_exists( $notfound_log ) ) {
			WP_CLI::warning( sprintf( 'Some files are missing on S3, list saved to %s', $notfound_log ) );
		} else {
			WP_CLI::success( 'No missing files on S3 found, lists are the same.' );
		}
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
		$files = $this->get_files_from_directory( $directory_path );
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
				++$i_current_batch;
				WP_CLI::log( sprintf( 'Uploading %s -- batch #%d, files from %s to %s...', $directory_path, $i_current_batch, str_replace( $directory_path . '/', '', $batch[0] ), str_replace( $directory_path . '/', '', $batch[ count( $batch ) - 1 ] ) ) );
				$this->upload_a_batch_of_files( $batch, $cli_s3_uploads_destination );

				// Reset the loop.
				$batch = [];
			}

			$batch[] = $file_path;
		}

		// Upload remaining files.
		if ( ! empty( $batch ) ) {
			++$i_current_batch;
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
		$files = $this->get_files_from_directory( $dir_from );

		$this->log( self::LOG, sprintf( "%s\n%s", $cli_command, implode( "\n", $files ) ), false );
	}

	/**
	 * Gets a list of sorted files from a directory.
	 * Excludes files from self::IGNORE_UPLOADING_FILES.
	 *
	 * @param string $directory Directory path.
	 *
	 * @return array|false Array with file names or false.
	 */
	public function get_files_from_directory( $directory ) {
		$files        = scandir( $directory );
		$ignore_files = array_merge( [ '.', '..' ], self::IGNORE_UPLOADING_FILES );
		foreach ( $ignore_files as $file_ignore ) {
			unset( $files[ array_search( $file_ignore, $files, true ) ] );
		}
		sort( $files );

		return $files;
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
	 * Callable for `newspack-content-migrator s3uploads-remove-subfolders-from-uploadsyyyymm`.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_remove_subfolders_from_uploadsyyyymm( $args, $assoc_args ) {
		$time_start = microtime( true );

		$log_filename        = 's3_subfoldersRemove.log';
		$log_errors_filename = 's3_subfoldersRemove_errors.log';

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
					$this->log( $log_filename, $msg );
					foreach ( $subdir_files as $subdir_file ) {
						$this->log( $log_filename, $subdir_file );
						$old_file = $mm_subdir . '/' . $subdir_file;
						$new_file = $mm_full_path . '/' . $subdir_file;
						$renamed  = rename( $old_file, $new_file );
						if ( false === $renamed ) {
							$this->log( $log_errors_filename, $old_file . ' ' . $new_file );
						}
					}
				}
				$progress->finish();
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator s3uploads-download-all-image-sizes-from-atomic`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_download_all_image_sizes_from_atomic( $pos_args, $assoc_args ) {
		
		global $wpdb;

		/**
		 * Arguments.
		 */
		$remote_host         = $assoc_args['remote-host'];
		$attachment_ids      = isset( $assoc_args['attachment-ids'] ) && ! empty( $assoc_args['attachment-ids'] ) ? explode( ',', $assoc_args['attachment-ids'] ) : null;
		$attachment_ids_from = $assoc_args['attachment-ids-range-from'] ?? null;
		$attachment_ids_to   = $assoc_args['attachment-ids-range-to'] ?? null;
		if ( ( ! is_null( $attachment_ids_from ) && is_null( $attachment_ids_to ) ) || ( is_null( $attachment_ids_from ) && ! is_null( $attachment_ids_to ) ) ) {
			WP_CLI::error( 'Must provide both --attachment-ids-range-from and --attachment-ids-range-to at the same time.' );
		}
		if ( ! is_null( $attachment_ids_from ) && ! is_null( $attachment_ids ) ) {
			WP_CLI::error( 'Either provide specific attachments with --attachment-ids or define ID range with --attachment-ids-range-from and --attachment-ids-range-to.' );
		}
		$also_download_sizes_with_scaled_filename = $assoc_args['also-download-sizes-with-scaled-filename'] ?? false;
		$only_download_sizes_csv                  = $assoc_args['only-download-sizes'] ?? null;
		$extra_sizes_csv                          = $assoc_args['download-extra-sizes'] ?? null;
		// Using null instead of false for flag $download_all_registered_sizes makes it more convenient.
		$download_all_registered_sizes = $assoc_args['download-all-registered-sizes-with-locked-ratio'] ?? null;
		if ( ! is_null( $only_download_sizes_csv ) && ( ! is_null( $extra_sizes_csv ) || ! is_null( $download_all_registered_sizes ) ) ) {
			WP_CLI::error( 'Cannot use both --only-download-sizes and --download-extra-sizes or --download-all-registered-sizes-with-locked-ratio at the same time.' );
		}

		// Timestamp the log.
		$log = 's3uploads-download-all-image-sizes-from-atomic.log';
		$this->log( $log, sprintf( 'Starting %s.', gmdate( 'Y-m-d h:i:s a', time() ) ) );

		// Get local hostname.
		$parsed_site_url = parse_url( site_url() );
		$local_host      = $parsed_site_url['host'];


		/**
		 * Get custom sizes for download.
		 */
		$custom_sizes = [];
		if ( $only_download_sizes_csv ) {
			// Download just some specific sizes.
			$custom_sizes = $this->explode_csv_sizes( $only_download_sizes_csv );
		} else {
			// Use extra provided sizes.
			if ( $extra_sizes_csv ) {
				$extra_sizes  = $this->explode_csv_sizes( $extra_sizes_csv );
				$custom_sizes = $this->merge_sizes( $custom_sizes, $extra_sizes );
			}

			// Use all the registered sizes with locked aspect ratio (a development option).
			if ( $download_all_registered_sizes ) {
				$registered_image_sizes = $this->get_registered_image_sizes();
				$custom_sizes           = $this->merge_sizes( $custom_sizes, $registered_image_sizes );
			}
		}

		// Confirm custom sizes before continuing.
		if ( ! empty( $custom_sizes ) ) {
			if ( $only_download_sizes_csv ) {
				$this->log( $log, sprintf( 'These are the only %d sizes which will be downloaded, and even the attachment-specific meta sizes will be skipped:', count( $custom_sizes ) ) );
			} else {
				$this->log( $log, sprintf( 'These %d custom sizes will be downloaded in addition to regular attachment meta sizes:', count( $custom_sizes ) ) );
			}
			foreach ( $custom_sizes as $size ) {
				$this->log( $log, sprintf( '- %sx%s', $size['width'], $size['height'] ) );
			}
			$this->log( $log, '⚠️  WARNING -- aspect ratio for these custom sizes will be locked and not relative to actual image attachment sizes! Such subsizes may or may not correspond to sizes generated by WP.' );

			// Confirm.
			$input = readline( 'Continue downloading sizes? (y/n) ' );
			if ( 'y' != $input ) {
				exit;
			}
		}


		/**
		 * Loop over attachments and download all missing sizes files from remote.
		 */
		
		// Get attachment IDs.
		if ( ! is_null( $attachment_ids_from ) && ! is_null( $attachment_ids_to ) ) {
			// phpcs:ignore -- Prepared statement.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND ID BETWEEN %d AND %d ORDER BY ID ASC", $attachment_ids_from, $attachment_ids_to ) );
		} elseif ( is_null( $attachment_ids ) ) {
			$attachment_ids = $this->posts->get_all_posts_ids( 'attachment' );
		}

		foreach ( $attachment_ids as $key_atatchment_id => $attachment_id ) {
			$this->log( $log, sprintf( "\n" . 'Attachment (%d/%d) ID %d', $key_atatchment_id + 1, count( $attachment_ids ), $attachment_id ) );

			// Skip if attachment is not an image.
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				$this->log( $log, 'Not an image, skipping' );
				continue;
			}

			// Get attachment metadata.
			$attachment_metadata = wp_get_attachment_metadata( $attachment_id );
			
			// If a large image was uploaded, WP will create a '-scaled' image to be used in its place. Original filename may be stored in 'original_image' key, if it exists.
			$filename_original = $attachment_metadata['original_image'] ?? null;

			/**
			 * Get attachment's
			 *      $local_path -- full path to local file
			 *      $url_local -- URL to file using this host
			 *      $filename -- just the filename
			 */
			$local_path = get_attached_file( $attachment_id );
			$filename   = basename( $local_path );
			$url_local  = wp_get_attachment_url( $attachment_id );
			// Some debugging level validation.
			if ( false === $local_path ) {
				$this->log( $log, sprintf( 'ERROR Attachment ID %d has no local path.', $attachment_id ) );
				exit;
			}
			if ( false === $url_local ) {
				$this->log( $log, sprintf( 'ERROR Attachment ID %d has no local URL.', $attachment_id ) );
				exit;
			}
			// Log.
			$this->log( $log, sprintf( '> get_attached_file %s', $local_path ) );
			
			
			/**
			 * Check if this image was '-scaled'.
			 * There might be a hash appended even after '-scaled', so it's not always safe to just search for '-scaled' suffix to determine this.
			 * 
			 * WP automatically creates a scaled image if it's larger than the threshold:
			 *  https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-admin/includes/image.php#L288
			 */
			$is_image_scaled = ! is_null( $filename_original )
				&& ( $filename_original != $filename )
				&& ( false !== strpos( $filename, '-scaled' ) );
			

			/**
			 * If it's a '-scaled' image, also get
			 *      $local_path_original
			 *      $url_local_original
			 *      $filename_original
			 * 
			 * If this is not a '-scaled' image, these will be null.
			 */
			$local_path_original = null;
			$url_local_original  = null;
			if ( $is_image_scaled ) {
				$local_path_original = str_replace( '/' . $filename, '/' . $filename_original, $local_path );
				$url_local_original  = str_replace( '/' . $filename, '/' . $filename_original, $url_local );
			}
			// Some debugging level validation -- if image is scaled, these should not be null.
			if ( $is_image_scaled && ( is_null( $local_path_original ) || is_null( $url_local_original ) ) ) {
				$this->log( $log, sprintf( 'ERROR Attachment ID %d is scaled but $local_path_original:%s or $url_local_original:%s are not defined.', $attachment_id, $local_path_original ?? 'null', $url_local_original ?? 'null' ) );
				exit;
			}
			// Log.
			$this->log( $log, sprintf( '> $is_attachment_scaled %s', $is_image_scaled ? 'true' : 'false' ) );
			$this->log( $log, sprintf( '> $local_path_original %s', $local_path_original ?? 'null' ) );
			

			/**
			 * Download attachment file to $local_path from $url_local.
			 */
			if ( file_exists( $local_path ) ) {
				$this->log( $log, sprintf( '+ attached file exists %s, skipping', $local_path ) );
			} else {
				$url_remote = str_replace( '//' . $local_host . '/', '//' . $remote_host . '/', $url_local );
				$this->log( $log, sprintf( '- attached file not found %s, downloading %s', $local_path, $url_remote ) );
				
				$downloaded = $this->download_url_to_file( $url_remote, $local_path );
				if ( is_wp_error( $downloaded ) || ! $downloaded ) {
					$err_msg = is_wp_error( $downloaded ) ? $downloaded->get_error_message() : 'n/a';
					$this->log( $log, sprintf( 'ERROR att. ID %d downloading attached file %s from %s', $attachment_id, $url_remote, $err_msg ) );
				}
			}


			/**
			 * If the attachment file is already '-scaled', also download the non-scaled original $local_path_original
			 * from $url_local_original.
			 */
			if ( $is_image_scaled && $local_path_original && $url_local_original ) {
				if ( file_exists( $local_path_original ) ) {
					$this->log( $log, sprintf( '+ original non-scaled file exists %s, skipping', $local_path_original ) );
				} else {
					$url_remote_original = str_replace( '//' . $local_host . '/', '//' . $remote_host . '/', $url_local_original );
					$this->log( $log, sprintf( '- original non-scaled file not found %s, downloading %s', $local_path_original, $url_remote_original ) );
					
					$downloaded = $this->download_url_to_file( $url_remote_original, $local_path_original );
					if ( is_wp_error( $downloaded ) || ! $downloaded ) {
						$err_msg = is_wp_error( $downloaded ) ? $downloaded->get_error_message() : 'n/a';
						$this->log( $log, sprintf( 'ERROR att. ID %d downloading original non-scaled file from %s err.msg: %s', $attachment_id, $url_remote, $err_msg ) );
					}
				}
			}
			
			
			/**
			 * Download all the subsizes.
			 */
			
			// If just downloading specific sizes was selected, then select just those...
			$sizes = [];
			if ( $only_download_sizes_csv ) {
				$sizes = $custom_sizes;
			} elseif ( isset( $attachment_metadata['sizes'] ) && ! empty( $attachment_metadata['sizes'] ) ) {
				// ... otherwise get the attachment metadata sizes -- NOTE, these are the most important sizes -- with other possible custom sizes.
				$sizes = $this->merge_sizes( $custom_sizes, $attachment_metadata['sizes'] );
			}

			// Loop through sizes and download them.
			$i_size = 0;
			foreach ( $sizes as $size_name => $size ) {
				$height    = $size['height'];
				$width     = $size['width'];
				$size_name = $width . 'x' . $height;
				$this->log( $log, sprintf( '... size %d/%d %s', $i_size + 1, count( $sizes ), $size_name ) );
				
				// Get size filename/path and URL of this size for download.
				$size_paths = [];
				if ( $is_image_scaled ) {
					// Download size with original filename, e.g. img-100x200.jpg, not with attached filename which will be '-scaled', e.g. img-scaled-100x200.jpg.
					$local_path_size = $this->append_suffix_to_file( $local_path_original, '-' . $size_name );
					$url_size_local  = $this->append_suffix_to_file( $url_local_original, '-' . $size_name );
					$url_size_remote = str_replace( '//' . $local_host . '/', '//' . $remote_host . '/', $url_size_local );
					$size_paths[]    = [
						'original_local_path' => $local_path_original,
						'local_path' => $local_path_size,
						'url_remote' => $url_size_remote,
					];

					// Only if additionally requested, also download size with scaled filename, e.g. img-scaled-100x200.jpg.
					if ( $also_download_sizes_with_scaled_filename ) {
						$local_path_size = $this->append_suffix_to_file( $local_path, '-' . $size_name );
						$url_size_local  = $this->append_suffix_to_file( $url_local, '-' . $size_name );
						$url_size_remote = str_replace( '//' . $local_host . '/', '//' . $remote_host . '/', $url_size_local );
						$size_paths[]    = [
							'original_local_path' => $local_path,
							'local_path' => $local_path_size,
							'url_remote' => $url_size_remote,
						];
					}
				} else {
					// Image is not scaled, so use regular attached file.
					$local_path_size = $this->append_suffix_to_file( $local_path, '-' . $size_name );
					$url_size_local  = $this->append_suffix_to_file( $url_local, '-' . $size_name );
					$url_size_remote = str_replace( '//' . $local_host . '/', '//' . $remote_host . '/', $url_size_local );
					$size_paths[]    = [
						'original_local_path' => $local_path,
						'local_path' => $local_path_size,
						'url_remote' => $url_size_remote,
					];
				}

				// Download the size.
				foreach ( $size_paths as $size_path ) {
					$local_path_size = $size_path['local_path'];
					$url_size_remote = $size_path['url_remote'];
					if ( file_exists( $local_path_size ) ) {
						$this->log( $log, sprintf( '+ %s file exists %s, skipping', $size_name, $local_path_size ) );
					} else {
						// Try to create it first, if you can't, then fetch it.
						$maybe_resized_image = $this->resize_image( $size_path['original_local_path'], $width, $height );

						if ( is_wp_error( $maybe_resized_image ) ) {
							$downloaded = $this->download_url_to_file( $url_size_remote, $local_path_size );
							if ( is_wp_error( $downloaded ) || ! $downloaded ) {
								$err_msg = is_wp_error( $downloaded ) ? $downloaded->get_error_message() : 'n/a';
								$this->log( $log, sprintf( 'ERROR att. ID %d downloading size %s from %s err.msg: %s', $attachment_id, $size_name, $url_size_remote, $err_msg ) );
							} else {
								$this->log( $log, sprintf( '+ downloaded %s', $url_size_remote ) );
							}
						} else {
							$this->log( $log, sprintf( '+ resized %s', $local_path_size ) );
						}
					}
				}

				++$i_size;
			}       
		}

		WP_CLI::line( sprintf( 'All done! 🙌 See log %s for full details.', $log ) );
	}

	/**
	 * This function uses the GD library to resize images locally.
	 *
	 * @param string $image_path Full path to the image.
	 * @param int    $width New width.
	 * @param int    $height New height.
	 *
	 * @return string|WP_Error
	 */
	public function resize_image( string $image_path, int $width, int $height ) {
		if ( ! function_exists( 'gd_info' ) ) {
			return new WP_Error( 'gd_not_installed', 'GD library is not installed.' );
		}

		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'image_not_found', 'Image not found.' );
		}

		// Get image info.
		$image_info = getimagesize( $image_path );
		if ( false === $image_info ) {
			return new WP_Error( 'image_info_error', 'Could not get image info.' );
		}
		$mime_type             = $image_info['mime'];
		$original_aspect_ratio = $image_info[0] / $image_info[1]; // width / height.
		$resized_aspect_ratio  = $width / $height;
		$resize_height         = $original_aspect_ratio > $resized_aspect_ratio ? -1 : $height;

		// Create image resource.
		$image = null;
		switch ( $mime_type ) {
			case 'image/jpeg':
				$image = imagecreatefromjpeg( $image_path );
				break;
			case 'image/png':
				$image = imagecreatefrompng( $image_path );
				break;
			case 'image/gif':
				$image = imagecreatefromgif( $image_path );
				break;
			default:
				return new WP_Error( 'unsupported_mime_type', 'Unsupported image mime type.' );
		}

		if ( ! $image ) {
			return new WP_Error( 'image_create_error', 'Could not create image resource.' );
		}

		// Resize image.
		$resized_image = imagescale( $image, $width, $resize_height );
		if ( false === $resized_image ) {
			return new WP_Error( 'image_resize_error', 'Could not resize image.' );
		}

		// Save resized image.
		$resized_image_path = $this->append_suffix_to_file( $image_path, "-{$width}x$height" );
		switch ( $mime_type ) {
			case 'image/jpeg':
				imagejpeg( $resized_image, $resized_image_path );
				break;
			case 'image/png':
				imagepng( $resized_image, $resized_image_path );
				break;
			case 'image/gif':
				imagegif( $resized_image, $resized_image_path );
				break;
		}

		// Free memory.
		imagedestroy( $image );
		imagedestroy( $resized_image );

		return $resized_image_path;
	}
	/**
	 * Callable for `newspack-content-migrator s3uploads-discover-image-sizes-used-in-post-content`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_discover_image_sizes_used_in_post_content( $pos_args, $assoc_args ) {
		global $wpdb;

		// Timestamp the log.
		$log = 's3uploads-discover-image-sizes-used-in-post-content.log';
		$this->log( $log, sprintf( 'Starting %s.', gmdate( 'Y-m-d h:i:s a', time() ) ) );

		// Get local hostname.
		$parsed_site_url = parse_url( site_url() );
		$local_host      = $parsed_site_url['host'];

		// WP supported image extensions.
		$image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'ico', 'webp', 'svg', 'heic', 'heif' ];

		// Loop through all published Posts and Pages and find all potential Subsizes' sizes in <img> src.
		$image_sizes_data = [];
		$post_ids         = $this->posts->get_all_posts_ids( [ 'post', 'page' ], [ 'publish' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d/%d) Post ID %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			
			// Get post_content.
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			if ( empty( $post_content ) ) {
				continue;
			}
			
			// Find <img>s.
			$dom = new \DOMDocument();
			// phpcs:ignore -- Allow ignoring bad HTML syntax.
			@$dom->loadHTML( $post_content );
			$img_tags = $dom->getElementsByTagName( 'img' );
			foreach ( $img_tags as $img ) {
				
				// Get src.
				$src = $img->getAttribute( 'src' );
				if ( empty( $src ) ) {
					continue;
				}

				// Only continue if the src hostname is $local_host or if the URL is relative.
				$parsed_src      = parse_url( $src );
				$is_relative_url = ! isset( $parsed_src['host'] );
				$is_local_host   = isset( $parsed_src['host'] ) && ( $local_host == $parsed_src['host'] );
				if ( ! $is_local_host && ! $is_relative_url ) {
					continue;
				}

				// Only continue if src filename is an image.
				$filename       = basename( $src );
				$file_extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
				if ( ! in_array( $file_extension, $image_extensions ) ) {
					continue;
				}

				// Only continue if src filename ends in "-\d+x\d+" size suffix.
				$filename_without_extension = pathinfo( $filename, PATHINFO_FILENAME );
				if ( 1 !== preg_match( '/-(\d+x\d+)$/', $filename_without_extension, $matches ) ) {
					continue;
				}
				$size_name = $matches[1];

				// Store image sizes and post IDs.
				$image_sizes_data[ $size_name ][] = [
					'post_id' => $post_id,
					'src'     => $src,
				];
			}       
		}

		// Log.
		$this->log( $log, sprintf( 'Found %d potential image sizes referenced in post_content: %s', count( $image_sizes_data ), implode( ',', array_keys( $image_sizes_data ) ) ), false );
		foreach ( $image_sizes_data as $size_name => $data ) {
			foreach ( $data as $datum ) {
				$this->log( $log, sprintf( 'size:%s post_id:%d src:%s', $size_name, $datum['post_id'], $datum['src'] ), false );
			}
		}

		// Done.
		WP_CLI::log( sprintf( 'All done! 🙌  Found %d potential image sizes. See %s log for URLs and post IDs.', count( $image_sizes_data ), $log ) );
	}

	/**
	 * Appends a suffix to a file path or URL.
	 *
	 * @param string $path_or_url File path or URL.
	 * @param string $suffix    Suffix to append.
	 * @return string New path or URL with appended suffix to the file name.
	 */
	public function append_suffix_to_file( string $path_or_url, string $suffix ): string {

		$filename                   = basename( $path_or_url );
		$directory                  = dirname( $path_or_url );
		$extension                  = pathinfo( $filename, PATHINFO_EXTENSION );
		$filename_without_extension = pathinfo( $filename, PATHINFO_FILENAME );
	
		$new_filename    = $filename_without_extension . $suffix . '.' . $extension;
		$new_path_or_url = $directory . '/' . $new_filename;
	
		return $new_path_or_url;
	}

	/**
	 * Downloads a file from a URL and saves it to a specified path.
	 *
	 * @param string $url URL of the file to download.
	 * @param string $path Path to save the file to.
	 * @return boolean|WP_Error True on success, WP_Error on failure.
	 */
	public function download_url_to_file( string $url, string $path ): bool|WP_Error {
		
		// Download.
		$tmp_path = download_url( $url );
		if ( is_wp_error( $tmp_path ) ) {
			return $tmp_path;
		}
		if ( filesize( $tmp_path ) < 1 ) {
			return new WP_Error( sprintf( 'File %s was empty', $path ) );
		}

		// Rename file from $tmpfname to $path.
		// phpcs:ignore -- Renaming is intended.
		$renamed = rename( $tmp_path, $path );
		if ( ! $renamed ) {
			$error = new WP_Error( sprintf( 'Error renaming downloaded file %s from %s to %s', $url, $tmp_path, $path ) );
			
			// Clean up and delete the tmp file.
			if ( file_exists( $tmp_path ) ) {
				// phpcs:ignore -- Will not run on VIP infrastructure.
				unlink( $tmp_path );
			}
			
			return $error;
		}

		return true;
	}

	/**
	 * Checks if a size exists in an array of sizes.
	 *
	 * @param array   $sizes Array of sizes.
	 * @param integer $width Width.
	 * @param integer $height Height.
	 * @return boolean True if the size exists, false if not.
	 */
	public function does_size_exist( array $sizes, int $width, int $height ): bool {
		foreach ( $sizes as $size ) {
			if ( $size['width'] == $width && $size['height'] == $height ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Merges two arrays of sizes, keeping only unique sizes.
	 *
	 * @param array $sizes1 Array of sizes.
	 * @param array $sizes2 Array of sizes.
	 * @return array Merged array of sizes.
	 */
	public function merge_sizes( array $sizes1, array $sizes2 ): array {
		
		/**
		 * It's expected that $sizes1 might already contain duplicates,
		 * e.g. sizes newspack-article-block-landscape-large newspack-archive-image-large are both 1200x900
		 * so by not starting with `$sizes = $sizes1;` and by looping through each element of $sizes1 and $sizes2
		 * duplicates are removed.
		 */
		$sizes = [];

		foreach ( $sizes1 as $size1 ) {
			if ( ! $this->does_size_exist( $sizes, $size1['width'], $size1['height'] ) ) {
				$sizes[] = $size1;
			}
		}
		foreach ( $sizes2 as $size2 ) {
			if ( ! $this->does_size_exist( $sizes, $size2['width'], $size2['height'] ) ) {
				$sizes[] = $size2;
			}
		}

		return $sizes;
	}

	/**
	 * Explodes a CSV of sizes strings and returns an array.
	 *
	 * @param string $sizes_csv CSV of sizes strings.
	 * @return array {
	 *    @type string width
	 *    @type string height
	 *    @type bool   crop
	 * }
	 */
	public function explode_csv_sizes( string $sizes_csv ): array {

		$sizes = [];
		foreach ( explode( ',', $sizes_csv ) as $size_string ) {
			$size_exploded = explode( 'x', strtolower( $size_string ) );
			if ( count( $size_exploded ) != 2 ) {
				WP_CLI::error( 'Invalid size string ' . $size_string );
			}

			$width  = $size_exploded[0];
			$height = $size_exploded[1];
			if ( $this->does_size_exist( $sizes, $width, $height ) ) {
				WP_CLI::warning( 'explode_csv_sizes: additional size is already registered and will be fixed ' . $size_string );
				continue;
			}

			$sizes[ $size_string ] = [
				'width'  => $width,
				'height' => $height,
				'crop'   => false,
			];
		}

		return $sizes;
	}

	/**
	 * Gets all registered image sizes. These registered sizes are static, i.e. with locked aspect ratio while subsizes might be dynamic.
	 *
	 * @return array {
	 *   @type string width
	 *   @type string height
	 *   @type bool   crop
	 * }
	 */
	public function get_registered_image_sizes(): array {
		
		global $_wp_additional_image_sizes;
		$default_sizes = [ 'thumbnail', 'medium', 'medium_large', 'large' ];
		// Get names of all registered sizes.
		// phpcs:ignore -- Not intended to run on VIP infrastructure.
		$sizes_names = get_intermediate_image_sizes();
	
		$sizes = [];
		foreach ( $sizes_names as $size_name ) {
			// Get default sizes from options.
			if ( in_array( $size_name, $default_sizes ) ) {
				$width               = get_option( $size_name . '_size_w' );
				$height              = get_option( $size_name . '_size_h' );
				$crop                = (bool) get_option( $size_name . '_crop' );
				$sizes[ $size_name ] = [
					'width'  => $width,
					'height' => $height,
					'crop'   => $crop,
				];
			} elseif ( isset( $_wp_additional_image_sizes[ $size_name ] ) ) {
				// Get additional sizes from the global.
				$width               = $_wp_additional_image_sizes[ $size_name ]['width'];
				$height              = $_wp_additional_image_sizes[ $size_name ]['height'];
				$crop                = $_wp_additional_image_sizes[ $size_name ]['crop'];
				$sizes[ $size_name ] = [
					'width'  => $width,
					'height' => $height,
					'crop'   => $crop,
				];
			}
		}
	
		return $sizes;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $message;
		}
		// phpcs:ignore -- Logging is intended.
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
