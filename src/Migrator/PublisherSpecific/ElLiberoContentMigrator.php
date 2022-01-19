<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use WP_CLI;
use WP_Error;

/**
 * Custom Libero migration script.
 */
class ElLiberoContentMigrator implements InterfaceMigrator {
	/**
	 * ElLiberoContentMigrator Singleton.
	 *
	 * @var ElLiberoContentMigrator $instance
	 */
	private static ElLiberoContentMigrator $instance;

	/**
	 * Template string for embedded SoundCloud content.
	 *
	 * @var string $soundcloud_embed
	 */
	protected string $soundcloud_embed = '<!-- wp:embed {"url":"{url}","type":"rich","providerNameSlug":"soundcloud","responsive":true} -->
	<figure class="wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud"><div class="wp-block-embed__wrapper">{url}</div></figure>
	<!-- /wp:embed -->';

	/**
	 * Template string for embedded audio content.
	 *
	 * @var string $audio_embed
	 */
	protected string $audio_embed = '<!-- wp:audio {"id":{child_id}} -->
	<figure class="wp-block-audio"><audio controls src="{s3_upload_url}"></audio></figure>
	<!-- /wp:audio -->';

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get Instance.
	 *
	 * @return ElLiberoContentMigrator
	 */
	public static function get_instance(): ElLiberoContentMigrator {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator migrate-el-libero-content',
			[ $this, 'handler' ],
			[
				'shortdesc' => 'Will handle migrating custom content from wp_postmeta to wp_post.post_content',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Handler function.
	 */
	public function handler() {
		$this->migrate_podcasts();
		$this->migrate_audio();
		$this->migrate_html();
	}

	/**
	 * Migrate SoundCloud embeds to block format.
	 *
	 * @return void
	 */
	protected function migrate_podcasts() {
		global $wpdb;

		$soundcloud_links_sql = "SELECT pm.post_id, pm.meta_value, p.post_content FROM $wpdb->postmeta AS pm
			LEFT JOIN $wpdb->posts AS p ON pm.post_id = p.ID
			WHERE pm.meta_key = 'podcast_url'
			AND pm.meta_value LIKE '%soundcloud.com%'";
		$this->output_sql( $soundcloud_links_sql );
		$results = $wpdb->get_results( $soundcloud_links_sql );

		if ( empty( $results ) ) {
			WP_CLI::warning( 'No podcast links containing soundcloud.com found.' );
		}

		foreach ( $results as $link ) {
			if ( ! empty( $link->post_content ) ) {
				$this->output( "Skipping post_id: $link->post_id because post_content is not empty." );
				continue;
			}
			// Probably a way to call DB once for this info, but going to be a bit quick and dirty.
			$content_sql = "SELECT meta_value FROM $wpdb->postmeta
				WHERE meta_key = 'bajada_noticia'
				AND post_id = $link->post_id";
			$this->output_sql( $content_sql );

			$content_result = $wpdb->get_results( $content_sql );

			$soundcloud_embed = strtr(
				$this->soundcloud_embed,
				[
					'{url}' => $link->meta_value,
				]
			);

			$content_result = array_shift( $content_result );
			$post_content   = "$content_result->meta_value</br>$soundcloud_embed";
			$wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $post_content,
				],
				[
					'ID' => $link->post_id,
				]
			);
		}
	}

	/**
	 * Migrate MP3's to block format.
	 *
	 * @return void
	 */
	protected function migrate_audio() {
		global $wpdb;

		$find_mp3s_sql = "SELECT post_id, meta_value AS post_with_mp3_path FROM $wpdb->postmeta WHERE meta_key = 'archivo_mp3'";
		$this->output_sql( $find_mp3s_sql );

		$mp3_posts = $wpdb->get_results( $find_mp3s_sql );

		foreach ( $mp3_posts as $mp3_post ) {
			if ( empty( $mp3_post->post_with_mp3_path ) ) {
				$this->output( "Could not migrate $mp3_post->post_with_mp3_path", '%R' );
				continue;
			}

			$mp3_path_sql = "SELECT meta_value AS mp3_path FROM $wpdb->postmeta WHERE post_id = $mp3_post->post_with_mp3_path AND meta_key = '_wp_attached_file'";
			$this->output_sql( $mp3_path_sql );

			$mp3_path_result = $wpdb->get_results( $mp3_path_sql );
			$mp3_path_result = array_shift( $mp3_path_result );
			// Assuming files will be in uploads folder.
			$s3_upload_path = "https://ellibero.s3.amazonaws.com/nuevoellibero/wp-content/uploads/$mp3_path_result->mp3_path";
			$exploded_path  = explode( '/', $s3_upload_path );
			$file_name      = array_pop( $exploded_path );

			$child_post = wp_insert_post(
				[
					'post_title'     => 'Custom Upload ' . $file_name,
					'post_status'    => 'inherit',
					'comment_status' => 'open',
					'ping_status'    => 'closed',
					'post_name'      => $file_name,
					'post_parent'    => $mp3_post->post_id,
					'guid'           => $s3_upload_path,
					'menu_order'     => 0,
					'post_type'      => 'attachment',
					'post_mime_type' => 'audio/mpeg',
					'comment_count'  => 0,
				]
			);

			$embed_content = strtr(
				$this->audio_embed,
				[
					'{child_id}'      => $child_post,
					'{s3_upload_url}' => $s3_upload_path,
				]
			);

			$wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $embed_content,
				],
				[
					'ID' => $mp3_post->post_id,
				]
			);

			$this->output( "Updated post_id: $mp3_post->post_id" );
		}
	}

	/**
	 * Migrate custom HTML content.
	 *
	 * @return void
	 *
	 * @throws Exception
	 */
	protected function migrate_html() {
		global $wpdb;

		$find_attached_html_sql = "SELECT post_id, meta_value AS file_path FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%.html'";
		$this->output_sql( $find_attached_html_sql );
		$attached_html = $wpdb->get_results( $find_attached_html_sql );

		foreach ( $attached_html as $html ) {
			$html_content = wp_remote_get( "https://ellibero.s3.amazonaws.com/nuevoellibero/wp-content/uploads/$html->file_path" );

			if ( ! ( $html_content instanceof WP_Error ) && 200 === $html_content['response']['code']) {
				$parent_post_sql = "SELECT post_parent FROM $wpdb->posts WHERE ID = $html->post_id";
				$this->output_sql( $parent_post_sql );
				$parent_post = $wpdb->get_results( $parent_post_sql );
				$parent_post = array_shift( $parent_post );

				$wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $html_content['body'],
					],
					[
						'ID' => $parent_post->post_parent,
					]
				);

				$this->output( "Updated post_id $parent_post->post_parent" );
			} else {
				$this->output( "Could not migrate $html->post_id", '%R' );
			}

			sleep( random_int( 1, 4 ) );
		}
	}

	/**
	 * Convenience function to handle setting a specific color for SQL statements.
	 *
	 * @param string $message MySQL Statement.
	 *
	 * @returns void
	 */
	private function output_sql( string $message ) {
		$this->output( preg_replace( '/^\s+|\s+$|\s+(?=\s)/', ' ', $message ), '%w' );
	}

	/**
	 * Output messsage to console with color.
	 *
	 * @param string $message String to output on console.
	 * @param string $color The color to use for console output.
	 *
	 * @returns void
	 */
	private function output( string $message, string $color = '%Y' ) {
		echo WP_CLI::colorize( "$color$message%n\n" );
	}
}
