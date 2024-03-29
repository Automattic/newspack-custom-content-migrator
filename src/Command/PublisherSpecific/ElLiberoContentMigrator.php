<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use WP_CLI;
use WP_Error;
use WP_oEmbed;

/**
 * Custom Libero migration script.
 */
class ElLiberoContentMigrator implements InterfaceCommand {
	/**
	 * ElLiberoContentMigrator Singleton.
	 *
	 * @var ElLiberoContentMigrator $instance
	 */
	private static $instance;

	/**
	 * Template string for embedded SoundCloud content.
	 *
	 * @var string $soundcloud_embed
	 */
	protected $soundcloud_embed = '<!-- wp:embed {"url":"{url}","type":"rich","providerNameSlug":"soundcloud","responsive":true} -->
	<figure class="wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud"><div class="wp-block-embed__wrapper">{url}</div></figure>
	<!-- /wp:embed -->';

	/**
	 * Template string for embedded audio content.
	 *
	 * @var string $audio_embed
	 */
	protected $audio_embed = '<!-- wp:audio {"id":{child_id}} -->
	<figure class="wp-block-audio"><audio controls src="{s3_upload_url}"></audio></figure>
	<!-- /wp:audio -->';

	/**
	 * Template string for embedded HTML content.
	 *
	 * @var string $html_embed
	 */
	protected $html_embed = '<!-- wp:html -->{html_content}<!-- /wp:html -->';

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
			'newspack-content-migrator el-libero-migrate-content',
			[ $this, 'handler' ],
			[
				'shortdesc' => 'Will handle migrating custom content from wp_postmeta to wp_post.post_content',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Handler function.
	 *
	 * @throws Exception
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
			$soundcloud_embed = ( new WP_oEmbed() )->get_html( $link->meta_value );

			if ( ! empty( $link->post_content ) && ! str_contains( $link->post_content, 'wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud' ) ) {
				$this->output( "Appending SoundCloud link to post_id: $link->post_id because post_content is not empty." );
				$wpdb->update(
					$wpdb->posts,
					[
						'post_content' => "<p>$link->post_content</p><p><br></p>$soundcloud_embed",
					],
					[
						'ID' => $link->post_id,
					]
				);
				continue;
			}

			// Probably a way to call DB once for this info, but going to be a bit quick and dirty.
			$content_sql = "SELECT meta_value FROM $wpdb->postmeta
				WHERE meta_key = 'bajada_noticia'
				AND post_id = $link->post_id";
			$this->output_sql( $content_sql );

			$content_result = $wpdb->get_results( $content_sql );

			$content_result = array_shift( $content_result );
			$post_content   = "<p>$content_result->meta_value</p><p><br></p>$soundcloud_embed";
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
	 * If the post doesn't already have a cached iframe of the SoundCloud link,
	 * it will attempt to add one.
	 *
	 * @param string $url SoundCloud link.
	 * @param int    $post_id Post ID.
	 */
	protected function add_soundcloud_oembed( string $url, int $post_id ) {
		global $wpdb;

		$has_oembed_sql = "SELECT meta_key, meta_value FROM $wpdb->postmeta 
				WHERE post_id = $post_id 
				  AND meta_key LIKE '_oembed_%'";
		$this->output_sql( $has_oembed_sql );
		$has_oembed = $wpdb->get_results( $has_oembed_sql );
		$has_oembed = ! empty( $has_oembed );

		if ( ! $has_oembed ) {

			$key_suffix    = md5( $url . serialize( wp_embed_defaults( $url ) ) );
			$cachekey      = "_oembed_$key_suffix";
			$cachekey_time = "_oembed_time_$key_suffix";

			update_post_meta( $post_id, $cachekey,  );
			update_post_meta( $post_id, $cachekey_time, time() );
			$this->output( "Created embed: $cachekey " );
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

			if ( ! ( $html_content instanceof WP_Error ) && 200 === $html_content['response']['code'] ) {
				$parent_post_sql = "SELECT post_parent FROM $wpdb->posts WHERE ID = $html->post_id";
				$this->output_sql( $parent_post_sql );
				$parent_post = $wpdb->get_results( $parent_post_sql );
				$parent_post = array_shift( $parent_post );

				$html_content = strtr(
					$this->html_embed,
					[
						'{html_content}' => $html_content['body'],
					]
				);

				$wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $html_content,
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
