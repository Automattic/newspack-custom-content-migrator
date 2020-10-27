<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class DocumentCloudEmbedMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command( 'newspack-content-migrator convert-documentcloud-embeds', array( $this, 'cmd_convert_documentcloud_embeds' ), [
			'shortdesc' => 'Converts DocumentCloud embeds from pure HTML & JS to the shortcode.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'post-ids',
					'description' => 'Post IDs to convert.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for convert-documentcloud-embeds command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_convert_documentcloud_embeds( $args, $assoc_args ) {

		$post_ids = $assoc_args[ 'post-ids' ];

		if ( empty( $post_ids ) ) {
			$post_ids = get_posts( [
				's'              => 'documentcloud',
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );
		} else {
			$post_ids = \explode( ',', $post_ids );
		}

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );

		$started = time();

		foreach ( $post_ids as $id ) {

			$content = get_post_field( 'post_content', $id );

			$regex        = '#\s*(<!-- wp:html -->.*DV-viewer.*documentcloud.*<!-- \/wp:html -->)#isU';
			$regex_search = preg_match( $regex, $content, $matches );
			if ( 1 !== $regex_search ) {
				// Try a different embed style.
				$regex        = '#\s*(<div id="DV-viewer.*documentcloud.*DV\.load.*<\/script>)#isU';
				$regex_search = preg_match( $regex, $content, $matches );
			}
// WP_CLI::line(var_export($matches,true));
			// No matches in this post, and no more types of embed to look for.
			if ( 1 !== $regex_search ) {
				continue;
			}

			// Matches.
			$file_url_regex  = '#https:\/\/assets\.documentcloud\.org\/documents\/([0-9]+)\/(.*)\.[a-z]{0,4}#isU';
			$file_url_search = preg_match( $file_url_regex, $matches[0], $file_url_matches );
			if ( 1 !== $file_url_search ) {
				// Try matching the HTML ID attribute.
				$file_url_regex  = '#id="DV-Viewer-([0-9]+)-(.*)"#isU';
				$file_url_search = preg_match( $file_url_regex, $matches[0], $file_url_matches );
			}
// WP_CLI::line(var_export($file_url_matches,true));
			// No more embed formats to look for, so bail.
			if ( 1 !== $file_url_search ) {
				// Didn't find the document URL for some reason.
				continue;
			}

			$document_id       = $file_url_matches[1];
			$document_name     = $file_url_matches[2];
			$url_for_shortcode = \sprintf(
				'https://www.documentcloud.org/documents/%s-%s.html',
				$document_id,
				$document_name
			);
			$shortcode         = \sprintf(
				'<!-- wp:shortcode -->[documentcloud url="%s"]<!-- /wp:shortcode -->',
				$url_for_shortcode
			);

			$replaced = str_replace( $matches[0], $shortcode, $content );
			if ( $content != $replaced ) {
				$updated = [
					'ID'           => $id,
					'post_content' => $replaced
				];
				$result = wp_update_post( $updated );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf(
						'Failed to update post #%d because %s',
						$id,
						$result->get_error_messages()
					) );
				} else {
					WP_CLI::success( sprintf( 'Updated #%d', $id ) );
				}

			}

		}

		WP_CLI::line( sprintf(
			'Finished processing %d records in %d seconds',
			count( $post_ids ),
			time() - $started
		) );

	}

}
