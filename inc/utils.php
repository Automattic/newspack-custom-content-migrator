<?php

function install_importer() {
	if ( ! is_plugin_active( 'wordpress-importer' ) ) {
		WP_CLI::runcommand( "plugin install wordpress-importer --activate" );
	}
}

function xml_file_to_items_array( $dir, $file_css ) {

	// Parse XML file into array.
	$xmlparser = xml_parser_create();
	$xmldata = file_get_contents( $dir . '/' . $file_css );
	xml_parse_into_struct( $xmlparser, $xmldata, $values);
	xml_parser_free( $xmlparser );

	// Extract relevant post data.
	$items = [];
	$i = -1;
	foreach ( $values as $key => $element ) {
		if ( isset( $element[ 'level' ] ) && 4 === $element[ 'level' ] ) {
			if ( isset( $element[ 'tag' ] ) && 'TITLE' === $element[ 'tag' ] ) {
				$i++;
				$items[ $i ][ 'tag' ] = $element[ 'value' ];
			} else if ( isset( $element[ 'tag' ] ) && 'GUID' === $element[ 'tag' ] ) {
				$items[ $i ][ 'guid' ] = $element[ 'value' ];
			} else if ( isset( $element[ 'tag' ] ) && 'WP:POST_ID' === $element[ 'tag' ] ) {
				$items[ $i ][ 'id' ] = $element[ 'value' ];
			}
		}
	}

	return $items;
}
