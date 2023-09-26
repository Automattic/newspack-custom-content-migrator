<?php

namespace NewspackCustomContentMigrator\Utils;

use \Mustache_Engine;
use \Mustache_Loader_FilesystemLoader;

/**
 * Class MigrationTemplater.
 *
 * Use Mustache templating engine to render HTML templates.
 *
 * See https://github.com/bobthecow/mustache.php
 */
class MigrationTemplater {

	private static function get_filesystem_loader( string $template_folder ): Mustache_Loader_FilesystemLoader {
		return new Mustache_Loader_FilesystemLoader(
			$template_folder,
			[
				'extension' => '.mustache'
			]
		);
	}

	/**
	 * Render a publisher-specific Mustache template.
	 *
	 * Loads templates from a publisher-specific folder, e.g:
	 * src/Command/PublisherSpecific/mustache-templates/HighCountryNews/template-name.mustache
	 *
	 * @param string $publisher Name of publisher – use the same name as the folder with the templates.
	 * @param string $template_file Name of template in publisher folder - do not include .mustache extension.
	 * @param array $data Key (variable name in template) => value pairs to pass to the template.
	 *
	 * @return string Rendered HTML.
	 */
	public static function format_publisher_html( string $publisher, string $template_file, array $data ): string {
		$mustache = new Mustache_Engine( [
			'loader' => self::get_filesystem_loader(
				dirname( __FILE__ ) . "/../Command/PublisherSpecific/mustache-templates/{$publisher}/"
			),
		] );

		return $mustache->render( $template_file, $data );
	}

	/**
	 * Render a general Mustache template.
	 *
	 * Loads templates from a publisher-specific folder, e.g:
	 * src/Command/General/mustache-templates/template-name.mustache
	 *
	 * @param string $template_file Name of template in publisher folder - do not include .mustache extension.
	 * @param array $data Key (variable name in template) => value pairs to pass to the template.
	 *
	 * @return string Rendered HTML.
	 */
	public static function format_general_html( string $template_file, array $data ): string {
		$mustache = new Mustache_Engine( [
			'loader' => self::get_filesystem_loader(
				dirname( __FILE__ ) . "/../Command/General/mustache-templates/"
			),
		] );

		return $mustache->render( $template_file, $data );
	}

}