<?php

function cmd_export_css( $args, $assoc_args ) {

	$export_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
	$file_output_css = isset( $assoc_args[ 'file-export-css' ] ) ? $assoc_args[ 'file-export-css' ] : null;

	if ( is_null( $export_dir ) ) {
		WP_CLI::error( 'Invalid output dir.' );
	}
	if ( is_null( $file_output_css ) ) {
		WP_CLI::error( 'Invalid CSS output file.' );
	}

	WP_CLI::line( sprintf( 'Exporting custom CSS...' ) );
	export_custom_css( $export_dir, $file_output_css );

	wp_cache_flush();
}

function cmd_import_css( $args, $assoc_args ) {

	$dir = isset( $assoc_args[ 'dir' ] ) ? $assoc_args[ 'dir' ] : null;
	$file_mapping_csv = isset( $assoc_args[ 'mapping-csv-file' ] ) ? $assoc_args[ 'mapping-csv-file' ] : null;
	$file_css = isset( $assoc_args[ 'file-css' ] ) ? $assoc_args[ 'file-css' ] : null;
	$hostname_export = isset( $assoc_args[ 'hostname-export' ] ) ? $assoc_args[ 'hostname-export' ] : null;
	$hostname_import = isset( $assoc_args[ 'hostname-import' ] ) ? $assoc_args[ 'hostname-import' ] : null;

	if ( is_null( $dir ) || ! file_exists( $dir ) ) {
		WP_CLI::error( 'Invalid dir.' );
	}
	if ( is_null( $file_mapping_csv ) || ! file_exists( $file_mapping_csv ) ) {
		WP_CLI::error( "Invalid mapping.csv file, which is used by the WP import command's authors option (see https://developer.wordpress.org/cli/commands/import/)." );
	}
	if ( is_null( $file_css ) || ! file_exists( $dir . '/' . $file_css ) ) {
		WP_CLI::error( 'Invalid CSS file.' );
	}
	if ( is_null( $hostname_export ) ) {
		WP_CLI::error( 'Invalid hostname of the export site.' );
	}
	if ( is_null( $hostname_import ) ) {
		WP_CLI::error( 'Invalid hostname of the the current site where import is being performed.' );
	}

	WP_CLI::line( 'Checking whether WP Importer is set up...' );
	install_importer();

	WP_CLI::line( 'Importing custom CSS...' );
	import_custom_css( $dir, $file_css, $file_mapping_csv, $hostname_export, $hostname_import );

	wp_cache_flush();
}

function export_custom_css( $export_dir, $file_output_css ) {

	$query = new WP_Query( [
		'posts_per_page' => 100,
		'cache_results'  => false,
		'post_type'      => 'custom_css',
		'post_status'    => 'public',
	] );

	$post_ids = [];
	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_ids[] = get_the_ID();
		}
	}

	export_posts( implode( ',', $post_ids ), $export_dir, $file_output_css );

	// Save postmeta

	wp_reset_postdata();
}

function cimport_css( $dir, $file_css, $mapping_csv, $hostname_export, $hostname_import ) {

	// import_posts( $dir, $file_css, $mapping_csv );
	// $items = xml_file_to_items_array( $dir, $file_css );

	$items = array (
		0 =>
			array (
				'tag' => 'twentytwenty',
				'guid' => 'http://temp1.test/?p=18',
				'id' => '18',
			),
		1 =>
			array (
				'tag' => 'twentytwenty',
				'guid' => 'http://temp1.test/?p=20',
				'id' => '20',
			),
	);

	// First distinguish which entries were inserted, and which already existed in the DB and skipped.
	// After that, ONLY FOR THE INSERTED ONES do: ...

	// 1. do wp_content.guid replacements which the WP importer left out:
	//      from this: http://{HOSTNAME_EXPORT}}/?p={ID_EXPORTED}
	//      to this:   http://{HOSTNAME_IMPORT}}/?p={ID_IMPORTED}

	// 2. for every imported CSS post, also create an option.
	//      option_name:     'theme_mods_' . $post_title
	//      option_value:    a:1:{s:18:"custom_css_post_id";i:___ID___;}
	//      autoload:        yes
	// e.g. theme_mods_twentytwenty   a:1:{s:18:"custom_css_post_id";i:18;}   yes

	return; // wp newspack-live-migrate import --dir=/srv/www/temp1/public_html/wp-content/plugins --file-posts=exported_posts.xml --file-css=exported_css.xml --mapping-csv-file=/srv/www/temp1/public_html/wp-content/plugins/mapping.csv --hostname-export=temp1.test --hostname-import=dev-var.test
}

WP_CLI::add_command( 'newspack-live-migrate export-css', 'cmd_export_css', [
	'shortdesc' => 'Exports elements of the staging site.',
	'synopsis'  => [
		[
			'type'        => 'assoc',
			'name'        => 'output-dir',
			'description' => 'Output directory full path (no ending slash).',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'file-export-css',
			'description' => 'CSS export XML filename.',
			'optional'    => false,
			'repeating'   => false,
		],
	],
] );

WP_CLI::add_command( 'newspack-live-migrate import-css', 'cmd_import_css', [
	'shortdesc' => 'Imports custom CSS from the export XML file.',
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
			'name'        => 'file-css',
			'description' => 'Exported custom CSS XML file.',
			'optional'    => false,
			'repeating'   => false,
		],
	],
] );
