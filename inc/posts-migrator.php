<?php

function cmd_export_posts( $args, $assoc_args ) {

	$post_ids_csv = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;
	$export_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
	$file_output_posts = isset( $assoc_args[ 'file-export-posts' ] ) ? $assoc_args[ 'file-export-posts' ] : null;

	if ( is_null( $post_ids_csv ) ) {
		WP_CLI::error( 'Invalid post IDs.' );
	}
	if ( is_null( $export_dir ) ) {
		WP_CLI::error( 'Invalid output dir.' );
	}
	if ( is_null( $file_output_posts ) ) {
		WP_CLI::error( 'Invalid posts output file.' );
	}

	WP_CLI::line( sprintf( 'Exporting post IDs %s...', $post_ids_csv ) );
	export_posts( $post_ids_csv, $export_dir, $file_output_posts );

	wp_cache_flush();
}

function export_posts( $post_ids_csv, $export_dir, $file_output_posts ) {
	WP_CLI::runcommand( "export --post__in=$post_ids_csv --dir=$export_dir --filename_format=$file_output_posts" );
}

function cmd_import_posts( $args, $assoc_args ) {

	$dir = isset( $assoc_args[ 'dir' ] ) ? $assoc_args[ 'dir' ] : null;
	$file_mapping_csv = isset( $assoc_args[ 'mapping-csv-file' ] ) ? $assoc_args[ 'mapping-csv-file' ] : null;
	$file_posts = isset( $assoc_args[ 'file-posts' ] ) ? $assoc_args[ 'file-posts' ] : null;
	$hostname_export = isset( $assoc_args[ 'hostname-export' ] ) ? $assoc_args[ 'hostname-export' ] : null;
	$hostname_import = isset( $assoc_args[ 'hostname-import' ] ) ? $assoc_args[ 'hostname-import' ] : null;

	if ( is_null( $dir ) || ! file_exists( $dir ) ) {
		WP_CLI::error( 'Invalid dir.' );
	}
	if ( is_null( $file_mapping_csv ) || ! file_exists( $file_mapping_csv ) ) {
		WP_CLI::error( "Invalid mapping.csv file, which is used by the WP import command's authors option (see https://developer.wordpress.org/cli/commands/import/)." );
	}
	if ( is_null( $file_posts ) || ! file_exists( $dir . '/' . $file_posts ) ) {
		WP_CLI::error( 'Invalid posts file.' );
	}
	if ( is_null( $hostname_export ) ) {
		WP_CLI::error( 'Invalid hostname of the export site.' );
	}
	if ( is_null( $hostname_import ) ) {
		WP_CLI::error( 'Invalid hostname of the the current site where import is being performed.' );
	}

	WP_CLI::line( 'Checking whether WP Importer is set up...' );
	install_importer();

	WP_CLI::line( 'Importing posts...' );
	import_posts( $dir, $file_posts, $file_mapping_csv );

	wp_cache_flush();
}

function import_posts( $dir, $file_posts, $file_mapping_csv ) {
	$options = [
		'return'     => true,
		// 'parse'      => 'json',
	];
	$output = WP_CLI::runcommand( "import $dir/$file_posts --authors=$file_mapping_csv", $options );

	return $output;
}

WP_CLI::add_command( 'newspack-live-migrate export-posts', 'cmd_export_posts', [
	'shortdesc' => 'Exports elements of the staging site.',
	'synopsis'  => [
		[
			'type'        => 'assoc',
			'name'        => 'post-ids',
			'description' => 'CSV post/page IDs to migrate.',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'output-dir',
			'description' => 'Output directory full path (no ending slash).',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'file-export-posts',
			'description' => 'Posts export XML filename.',
			'optional'    => false,
			'repeating'   => false,
		],
	],
] );

WP_CLI::add_command( 'newspack-live-migrate import-posts', 'cmd_import_posts', [
	'shortdesc' => 'Imports custom posts from the export XML file.',
	'synopsis'  => [
		[
			'type'        => 'assoc',
			'name'        => 'dir',
			'description' => 'Directory with exported resources, full path (no ending slash).',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'mapping-csv-file',
			'description' => "Full path to the authors mapping.csv file, used by the WP import command's authors option -- see https://developer.wordpress.org/cli/commands/import/ .",
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'hostname-export',
			'description' => "Hostname of the site where the export was performed.",
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'hostname-import',
			'description' => "Hostname of the site where the import is being performed (this).",
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'file-posts',
			'description' => 'Exported Posts XML file.',
			'optional'    => false,
			'repeating'   => false,
		],
	],
] );
