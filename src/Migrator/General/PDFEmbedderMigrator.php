<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for PDF Embedder plugin.
 */
class PDFEmbedderMigrator implements InterfaceMigrator {
	const PDF_EMBEDDER_LOG = 'PDF_EMBEDDER.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic = null;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
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
			'newspack-content-migrator migrate-pdf-embedder-block-to-core-file-block',
			array( $this, 'migrate_pdf_embedder_block_to_core_file_block' ),
			array(
				'shortdesc' => 'Migrate PDF Embedder Block to Core File Block.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator migrate-pdf-embedder-block-to-core-file-block`.
	 */
	public function migrate_pdf_embedder_block_to_core_file_block() {
		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) {
				global $wpdb;

				if ( str_contains( $post->post_content, 'wp:pdfemb/pdf-embedder-viewer' ) ) {
					preg_match_all( '/<!--\s+wp:pdfemb\/pdf\-embedder\-viewer(?:\s+(\{.*?\}))?\s+(?:\/)?-->(?:.*?)<!--\s+\/wp:pdfemb\/pdf\-embedder\-viewer\s+-->/s', $post->post_content, $pdf_embedder_match );

					if ( ! empty( $pdf_embedder_match[0] ) ) {
						$content = $post->post_content;

						foreach ( $pdf_embedder_match[0] as $pdf_embedder_block ) {
							$pdf_block_parsed = current( parse_blocks( $pdf_embedder_block ) );

							if ( ! array_key_exists( 'pdfID', $pdf_block_parsed['attrs'] ) || ! array_key_exists( 'url', $pdf_block_parsed['attrs'] ) ) {
								WP_CLI::warning( sprintf( "Can't parse the PDF from the post %d: ", $post->ID, $pdf_embedder_block ) );
								return;
							}

							$pdf_media = get_post( $pdf_block_parsed['attrs']['pdfID'] );
							$media_uid = uniqid( 'wp-block-file--media-' );

							if ( ! $pdf_media ) {
								WP_CLI::warning( sprintf( "Can't find the PDF %d for the post %d", $pdf_block_parsed['attrs']['pdfID'], $post->ID ) );
								return;
							}

							$innerHTML = '<div class="wp-block-file"><object class="wp-block-file__embed" data="' . $pdf_block_parsed['attrs']['url'] . '" type="application/pdf" style="width:100%;height:600px" aria-label="Embed of ' . $pdf_media->post_title . '."></object><a id="' . $media_uid . '" href="' . $pdf_block_parsed['attrs']['url'] . '">' . $pdf_media->post_title . '</a><a href="' . $pdf_block_parsed['attrs']['url'] . '" class="wp-block-file__button" download aria-describedby="' . $media_uid . '">Download</a></div>';

							$file_block = serialize_block(
								array(
									'blockName'    => 'core/file',
									'attrs'        => array(
										'id'             => $pdf_media->ID,
										'href'           => $pdf_block_parsed['attrs']['url'],
										'displayPreview' => true,
									),
									'innerBlocks'  => array(),
									'innerHTML'    => $innerHTML,
									'innerContent' => array( $innerHTML ),
								)
							);

							$content = str_replace( $pdf_embedder_block, $file_block, $content );
						}

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->update(
							$wpdb->prefix . 'posts',
							array( 'post_content' => $content ),
							array( 'ID' => $post->ID )
						);

						WP_CLI::line( sprintf( 'Updated post: %d', $post->ID ) );
					}
				}
			}
		);
	}
}
