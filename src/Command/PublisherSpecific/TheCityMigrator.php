<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\General\ChorusCmsMigrator;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use \WP_CLI;

/**
 * Custom migration scripts for The City.
 */
class TheCityMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * PostsLogic instance.
	 *
	 * @var PostsLogic PostsLogic instance.
	 */
	private $posts;

	/**
	 * WpBlockManipulator instance.
	 *
	 * @var WpBlockManipulator WpBlockManipulator instance.
	 */
	private $wpblockmanipulator;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $gutenberg_blocks;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new PostsLogic();
		$this->wpblockmanipulator = new WpBlockManipulator();
		$this->gutenberg_blocks = new GutenbergBlockGenerator();
		$this->logger = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator thecity-transform-blocks-wpciviliframe-to-newspackiframe',
			[ $this, 'cmd_wpciviliframe_to_newspackiframe' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator thecity-attachments-all-check-can-distribute',
			[ $this, 'cmd_attachments_all_check_can_distribute' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator thecity-match-assets-to-attachments',
			[ $this, 'cmd_match_assets_to_attachments' ],
			[
				'shortdesc' => 'Searches all asset JSONs and finds equivalent already imported attachment post_ids. Accuracy of this should be high but it is expected that it is not perfect, HTML galleries for QA and verification of results are produced.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'asset/' folder is located with asset JSONs.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator thecity-update-assets-credits',
			[ $this, 'cmd_update_assets_credits' ],
			[
				'shortdesc' => 'Updates .',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'asset/' folder is located with asset JSONs.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'path-to-assets-to-attachment-ids-jsons',
						'description' => "Path to JSONs with URLs to post_ids, produced by command cmd_match_assets_to_attachments.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	public function cmd_update_assets_credits( array $pos_args, array $assoc_args ): void {
		$path = rtrim( $assoc_args['path-to-export'], '/' );
		$path_to_assets_to_attachment_ids_jsons = rtrim( $assoc_args['path-to-assets-to-attachment-ids-jsons'], '/' );

		global $wpdb;

		// Manually QAed HTMLs and filtered these images as not matched to post_ids correctly, so these will be updated manually.
		$issues_skip = [
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/19978615/race.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/19995435/0b166583f31b40ed701757ed643be0fa9557_020819_stringer_mta_presser.w700.a700x467.2x.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/20001672/0b166583f31b40ed701757ed643be0fa9557_020819_stringer_mta_presser.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/20001993/0b166583f31b40ed701757ed643be0fa9557_020819_stringer_mta_presser.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/20002130/0b166583f31b40ed701757ed643be0fa9557_020819_stringer_mta_presser.w700.a700x467.2x.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/20079816/0_4.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/20991771/test_and_trace.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/21763288/20200817_ccrb_obstruction_4x.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/21763359/20200817_ccrb_obstruction_4x.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/21766017/Chalkbeat_Computer.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22253262/unnamed.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22273621/020121_blizzard_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22408346/flyers.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22521672/Image_from_iOS_4_.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22542206/052621_republican_debate.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22807549/Hochul_Benjamin_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/22983198/Image_from_iOS__3_.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119158/BIRDS_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119159/BIRDS_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119281/ADAMS_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119282/ADAMS_3.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119283/ADAMS_4.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119285/ADAMS_6.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23119316/ADAMS_7.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23268692/022422_lgbt_rally_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23278300/ESL.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23398220/shyvonne_noboa.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23663441/06.30.2022_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23663442/06.30.2022_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23672147/07.05.2022_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23934506/08.09.2022_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23934514/08.09.2022_9.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23947015/image1.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/23970839/franyerson_migrant_child.JPG',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24220046/102522_billion_oyster_williamsburg_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24247224/chorus_image.jpeg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24292283/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24292286/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24292289/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24292300/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24302414/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24358379/E_BIKE_Flier_121222__1_.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24383346/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24385345/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24433235/image.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24434529/Screen_Shot.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24449767/E_BIKE_Flier_in_Spanish.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24449778/E_BIKE_Flier_in_Spanish.PDF',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712281/1.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712285/2.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712287/3.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712291/4.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712292/5.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712295/6.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712296/7.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24712298/8.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713628/8.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713629/1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713630/4.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713631/5.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713632/2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713633/7.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24713634/3.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24746175/social_image.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24785558/image__2_.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24785560/image__1_.png',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847803/230717_Yang_FAQ___Street_Drugs_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847804/230717_Yang_FAQ___Street_Drugs_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847805/230717_Yang_FAQ___Street_Drugs_3.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847835/230717_Yang_FAQ___Street_Drugs_3.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847839/230717_Yang_FAQ___Street_Drugs_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847840/230717_Yang_FAQ___Street_Drugs_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847855/230717_Yang_FAQ___Street_Drugs_3.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847860/230717_Yang_FAQ___Street_Drugs_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847861/230717_Yang_FAQ___Street_Drugs_1.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847864/230717_Yang_FAQ___Street_Drugs_2.jpg',
			'https://cdn.vox-cdn.com/uploads/chorus_asset/file/24847865/230717_Yang_FAQ___Street_Drugs_1.jpg',
		];
		$i_updated = 0;
		$assets_to_attachment_ids_jsons = glob( $path_to_assets_to_attachment_ids_jsons . '/*.json' );
		foreach ( $assets_to_attachment_ids_jsons as $json ) {
			$data = json_decode( file_get_contents( $json ), true );
			foreach ( $data as $asset_json => $post_ids ) {
				$asset_data = json_decode( file_get_contents( $asset_json ), true );
				$asset_url = $asset_data['url'];
				if ( in_array( $asset_url, $issues_skip ) ) {
					continue;
				}
				foreach ( $post_ids as $post_id ) {
					// For debugging purposes. Throw continue for obvious reasons first, then we debug other possible issues next in code.
					if ( is_null( $asset_data['credit'] ) ) {
						continue;
					}
					$credit = $asset_data['credit']['html'] ?? null;
					if ( ! $credit ) {
						continue;
					}

					$current_credit = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_media_credit'", $post_id ) );
					if ( $current_credit && ( $credit != $current_credit ) ) {
						$d=1;
					}

					// update_post_meta( $post_id, '_media_credit', $credit );
					if ( $credit ) {
						WP_CLI::success( sprintf( 'Updated %d %s', $post_id, $credit ) );
						$i_updated++;
						$d=1;
					}
				}
			}
		}
		WP_CLI::success( sprintf( 'Updated %d attachments.', $i_updated ) );
	}

	public function cmd_match_assets_to_attachments( array $pos_args, array $assoc_args ): void {
		global $wpdb;

		$output_path = '/Users/ivanuravic/www/thecity/app/public/wp-content/plugins/newspack-custom-content-migrator/0_htmls';
		if ( ! file_exists( $output_path ) ) {
			mkdir( $output_path );
		}

		$path       = rtrim( $assoc_args['path-to-export'], '/' );
		$asset_path = $path . '/asset';
		if ( ! file_exists( $asset_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}

		$not_found_assets_urls = [];
		$images = [];
		$processed_asset_jsons = [];
		$limit = 1000;

		$assets_jsons = glob( $asset_path . '/*.json' );
		$i_k = 0;
		foreach ( $assets_jsons as $key_asset_json => $asset_json ) {

			WP_CLI::line( sprintf( "Fetching %d/%d %s ...", $key_asset_json + 1, count( $assets_jsons), basename( $asset_json ) ) );

			// Get asset data from JSON.
			$data_asset = json_decode( file_get_contents( $asset_json ), true );
			$url        = $data_asset['url'];
			$title      = $data_asset['title'];

			$image_filename = basename($url);

			$filename = basename($url);
			// Match the filename without the ending e.g. ".w700.a700x467.2x.jpg" part.
			if (preg_match('/^(.*?)\.w\d+\.a\d+x\d+\.\d+x\.jpg$/', $filename, $matches)) {
				// If matched, use the first match as the filename base.
				$filename_base = $matches[1];
			} else {
				// Else use full file name wo/ extension
				$path_info = pathinfo( $filename );
				$filename_no_extension = $path_info['filename'];
				$filename_base = $filename_no_extension;
			}

			$post_ids = $this->get_assets_post_ids( $title, $filename_base );

			if ( ! $post_ids ) {
				$not_found_assets_urls[ $asset_json ] = $url;
				continue;
			}

			$images[$url] = $post_ids;
			$processed_asset_jsons[$asset_json] = $post_ids;

			if ( ($key_asset_json + 1) % $limit == 0 ) {
				$i_k++;

				// Flush HTML
				$output = $this->output_gallery( $images );
				$output = str_replace( '//thecity.local/', '//thecity-newspack.newspackstaging.com/', $output );
				file_put_contents( $output_path . "/{$i_k}.html", $output );

				// Save and flush array asset.JSON => [post_ids]
				file_put_contents( $output_path . "/{$i_k}.json", json_encode( $processed_asset_jsons ) );
				$images = [];
				$processed_asset_jsons = [];
			}

		}

		// Flush remaining images.
		$i_k++;

		// Flush HTML
		$output = $this->output_gallery( $images );
		$output = str_replace( '//thecity.local/', '//thecity-newspack.newspackstaging.com/', $output );
		file_put_contents( "/Users/ivanuravic/www/thecity/app/public/wp-content/plugins/newspack-custom-content-migrator/0_htmls/{$i_k}.html", $output );

		// Save and flush array asset.JSON => [post_ids]
		file_put_contents( $output_path . "/{$i_k}.json", json_encode( $processed_asset_jsons ) );

		// Log not found assets
		file_put_contents( 'not_found_assets_urls.json', json_encode( $not_found_assets_urls ) );

		WP_CLI::warning( "Check ==> 'not_found_assets_urls.json'" );
	}

	public function get_assets_post_ids($title,$image_filename) {
		global $wpdb;
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select pm.post_id
				from {$wpdb->postmeta} pm
				join {$wpdb->posts} p
			    	on p.ID = pm.post_id and p.post_type = 'attachment'
			    	and ( p.post_excerpt = %s or p.post_title = %s )
				where pm.meta_key = %s and pm.meta_value like %s ;",
			$title,
			$title,
			ChorusCmsMigrator::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL,
			// $url
			'%' . $image_filename . '%'
		)
		//, ARRAY_A
		);
		return $post_ids;
	}

	public function output_gallery( $urls ) {

// <div class="parent">
		$div = <<<DIV
    <div class="child"><img src="%s" width="200" ><div class="desc">%s</div></div>
DIV;
// </div> <!-- end parent -->
// <br>

		$divs = '';
		foreach ( $urls as $orig_url => $post_ids ) {
			$divs .= '<div class="parent">';
			$divs .= sprintf( $div, $orig_url, $orig_url );
			foreach ( $post_ids as $post_id ) {
				$att_url = get_attachment_link( $post_id );
				$divs .= sprintf( $div, $att_url, $post_id );
			}
			$divs .= '</div> <!-- end parent --> <br>';
		}

		$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        .parent {
            display: flex;
        }
        .child {
            width: 200px;
            height: 200px;
            background-color: lightblue;
            margin: 10px;
        }
    </style>
</head>
<body>
{$divs}
</body>
</html>
HTML;

		return $html;
	}

	public function cmd_attachments_all_check_can_distribute( array $pos_args, array $assoc_args ): void {
		$att_ids = $this->posts->get_all_posts_ids( 'attachment' );
		foreach ( $att_ids as $key_att_id => $att_id ) {
			WP_CLI::line( sprintf( "%d/%d %d", $key_att_id + 1, count( $att_ids ), $att_id ) );
			update_post_meta( $att_id, '_navis_media_can_distribute', 1 );
		}
	}

	public function cmd_wpciviliframe_to_newspackiframe( array $pos_args, array $assoc_args ): void {
		global $wpdb;

		$logs_path_before_after = '0_thecity_iframereplacement_before_afters';
		// Check if folder exists.
		if ( ! file_exists( $logs_path_before_after ) ) {
			mkdir( $logs_path_before_after );
		}

		// Get all posts.
		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'pending', 'private' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );

			// Skip posts which do not have wp:civil/iframe.
			$civil_iframe_matches = $this->wpblockmanipulator->match_wp_block_selfclosing( 'wp:civil/iframe', $post_content );
			if ( ! $civil_iframe_matches || ! isset( $civil_iframe_matches[0] ) || empty( $civil_iframe_matches[0] ) ) {
				continue;
			}

			// Replace blocks.
			$post_content_updated = $post_content;
			foreach ( $civil_iframe_matches[0] as $civil_iframe_match ) {
				$civil_iframe_block_html = $civil_iframe_match[0];
				$src = $this->wpblockmanipulator->get_attribute( $civil_iframe_block_html, 'src' );

				$newspack_iframe_block = $this->gutenberg_blocks->get_iframe( $src );
				$newspack_iframe_block_html = serialize_blocks( [ $newspack_iframe_block ] );

				$post_content_updated = str_replace( $civil_iframe_block_html, $newspack_iframe_block_html, $post_content_updated );
			}

			// Save.
			if ( $post_content != $post_content_updated ) {
				// Save before/after for easy QA.
				file_put_contents( $logs_path_before_after . '/' . $post_id . '.before.html', $post_content );
				file_put_contents( $logs_path_before_after . '/' . $post_id . '.after.html', $post_content_updated );

				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );

				WP_CLI::success( "Updated" );
			}
		}

		wp_cache_flush();
	}
}
