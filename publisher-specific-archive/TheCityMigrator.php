<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use NewspackCustomContentMigrator\Command\General\ChorusCmsMigrator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

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
	 * @var CoAuthorsPlusHelper $cap_logic CoAuthorsPlusHelper instance.
	 */
	private $cap_logic;

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
		$this->posts              = new PostsLogic();
		$this->wpblockmanipulator = new WpBlockManipulator();
		$this->gutenberg_blocks   = new GutenbergBlockGenerator();
		$this->cap_logic          = new CoAuthorsPlusHelper();
		$this->logger             = new Logger();
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
		WP_CLI::add_command(
			'newspack-content-migrator thecity-postlaunch-update-subtitles',
			[ $this, 'cmd_postlaunch_update_subtitles' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator thecity-postlaunch-reset-guest-author-bylines',
			[ $this, 'cmd_reset_guest_author_bylines' ],
			[
				'shortdesc' => 'TheCity has a customized version of CAP which supports displaying custom bylines. This command will migrate existing bylines to the new format.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_postlaunch_update_subtitles( array $pos_args, array $assoc_args ): void {

		global $wpdb;
		$file_urls_subtitles = '/tmp/postlaunch_update_subtitles/urls_subtitlejsons.php';
		$urls_subtitles = include $file_urls_subtitles;
		$urls_not_found = [];
		$urls_post_ids = [];
		foreach ( $urls_subtitles as $url_original => $subtitle_json ) {

			/**
			 * Get current URL.
			 */
			// $url_local = str_replace( '//www.thecity.nyc', '//thecity.local', $url_original );
			// $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s and meta_value=%s", 'newspack_chorus_entry_url', $url_local ) );
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s and meta_value=%s", 'newspack_chorus_entry_url', $url_original ) );
			if ( ! $post_id ) {
				$urls_not_found[] = $url_original;
				WP_CLI::warning( 'Not found URL in posts ' . $url_original );
				continue;
			}

			/**
			 * Get new subtitle.
			 */
			$subtitle = $subtitle_json;
			// Remove {"html"=>" from beginning.
			if ( 0 !== strpos( $subtitle, '{"html"=>"' ) ) {
				$d=1;
			}
			$subtitle = preg_replace( '/^\{"html"=>"/' , '', $subtitle );
			// Remove '"}' from end.
			$chars_end = '"}';
			if ( $chars_end !== substr( $subtitle, -strlen( $chars_end ) ) ) {
				$d=1;
			}
			$subtitle = preg_replace( '/"}$/' , '', $subtitle );
			// Replace " " char.
			$subtitle = str_replace( " ", " ", $subtitle );
			if ( empty( $subtitle ) ) {
				$d=1;
			}

			// Save URL to post ID.
			$urls_post_ids[ $url_original ] = $post_id;

			// Update post excerpt.
			$post_excerpt_old = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d and meta_key = %s", $post_id, 'newspack_post_subtitle' ) );
			WP_CLI::success( $post_id );
			$updated = $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $subtitle ], [ 'post_id' => $post_id, 'meta_key' => 'newspack_post_subtitle' ] );

			// Log.
			$post_log = [
				'url' => $url_original,
				'post_id' => $post_id,
				'post_excerpt_old' => $post_excerpt_old,
			];
			$this->logger->log( 'postlaunch_update_subtitles.log', json_encode( $post_log ), null );
		}

		WP_CLI::warning( 'URLs not found: ' . count( $urls_not_found ) );
		WP_CLI::warning( implode( "\n", $urls_not_found ) );

		$scv = '';
		foreach ( $urls_post_ids as $url => $id ) {
			$scv .= ! empty( $scv ) ? "\n" : '';
			$scv .= sprintf( "%s|%s|%s\n", $url, $id, $post_excerpt_old );
		}
		$this->logger->log( 'data.csv', $scv, null );

		$d=1;
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
		$i_updated_credits = 0;
		$i_updated_captions = 0;
		$i_updated_titles = 0;
		$assets_to_attachment_ids_jsons = glob( $path_to_assets_to_attachment_ids_jsons . '/*.json' );
		foreach ( $assets_to_attachment_ids_jsons as $json ) {
			$data = json_decode( file_get_contents( $json ), true );
			foreach ( $data as $asset_json => $post_ids ) {
				$asset_data = json_decode( file_get_contents( $path . '/asset/' . $asset_json ), true );
				$asset_url = $asset_data['url'];
				if ( in_array( $asset_url, $issues_skip ) ) {
					continue;
				}
				foreach ( $post_ids as $post_id ) {
					// Credit.
					if (
						isset( $asset_data['credit'] ) && ! is_null( $asset_data['credit'] )
						&& isset( $asset_data['credit']['html'] ) && ! is_null( $asset_data['credit']['html'] )
					) {
						$credit = $asset_data['credit']['html'];
						$current_credit = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_media_credit'", $post_id ) );
						if ( $current_credit && ( $credit != $current_credit ) ) {
							$dbg=1;
						}

						update_post_meta( $post_id, '_media_credit', $credit );
						WP_CLI::success( sprintf( 'Updated credit %d %s', $post_id, $credit ) );
						$i_updated_credits++;
						$d=1;
					}

					// Caption.
					if (
						isset( $asset_data['sourceCaption'] ) && ! is_null( $asset_data['sourceCaption'] && ! empty( $asset_data['sourceCaption'] ) )
					) {
						$caption  = $asset_data['sourceCaption'];
						$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $caption ], [ 'ID' => $post_id ] );
						WP_CLI::success( sprintf( 'Updated caption %d %s', $post_id, $caption ) );
						$i_updated_captions++;
					} else {
						$d=1;
					}

					// Title.
					if (
						isset( $asset_data['title'] ) && ! is_null( $asset_data['title'] && ! empty( $asset_data['title'] ) )
					) {
						$title = $asset_data['title'];
						$wpdb->update( $wpdb->posts, [ 'post_title' => $title ], [ 'ID' => $post_id ] );
						WP_CLI::success( sprintf( 'Updated title %d %s', $post_id, $title ) );
						$i_updated_titles++;
					} else {
						$d=1;
					}

				}
			}
		}
		WP_CLI::success( sprintf( 'Updated %d credits.', $i_updated_credits ) );
		WP_CLI::success( sprintf( 'Updated %d captions.', $i_updated_captions ) );
		WP_CLI::success( sprintf( 'Updated %d titles.', $i_updated_titles ) );
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

	/**
	 * This function will handle updating the bylines for all posts which do not already have the custom RT byline set.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_reset_guest_author_bylines( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_without_updated_byline = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_content 
				FROM $wpdb->posts 
				WHERE ID NOT IN (
					SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value <> ''
				) AND post_type = %s AND post_status = %s AND post_content LIKE %s ORDER BY ID ASC",
				'_the_city_features_post_meta',
				'post',
				'publish',
				'%' . $wpdb->esc_like( 'p-additional-reporters' ) . '%'
			)
		);

		foreach ( $posts_without_updated_byline as $post ) {
			echo "\n\n\n";
			ConsoleColor::white( 'Processing Post ID:' )
						->bright_white( $post->ID )
						->white( 'Post Name:' )
						->bright_white( $post->post_name )
						->white( 'Link:' )
						->bright_white( get_site_url() . '/?p=' . $post->ID )
						->output();
			$guest_authors    = $this->cap_logic->get_guest_authors_for_post( $post->ID );
			$guest_author_ids = array_map(
				function ( $guest_author ) {
					return $guest_author->ID;
				},
				$guest_authors
			);

			$additional_contributor_meta  = get_post_meta( $post->ID, 'newspack_chorus_additional_contributor_ga_id' );
			$additional_contributor_meta  = array_filter(
				$additional_contributor_meta,
				function ( $meta_data ) {
					return is_numeric( $meta_data ); // There are WP_Error classes which were saved as additional contributors.
				}
			);
			$additional_contributor_meta  = array_unique( $additional_contributor_meta );
			$additional_contributor_meta  = array_diff( $additional_contributor_meta, $guest_author_ids );
			$additional_guest_author_objs = [];
			foreach ( $additional_contributor_meta as $key => $guest_author_id ) {
				$additional_contributor = $this->cap_logic->get_guest_author_by_id( $guest_author_id );

				if ( $additional_contributor ) {
					$additional_guest_author_objs[] = $additional_contributor;
				} else {
					unset( $additional_contributor_meta[ $key ] );
				}
			}

			$all_guest_authors = array_merge( $guest_author_ids, $additional_contributor_meta );
			$all_guest_authors = array_unique( $all_guest_authors );

			$this->cap_logic->assign_guest_authors_to_post( $all_guest_authors, $post->ID );

			$byline = '';

			$last_guest_author = array_pop( $guest_authors );

			if ( $last_guest_author ) { // Ensure we have at least 1 author for the main part of the byline.
				$byline = '<p>By ';

				if ( empty( $guest_authors ) ) {
					$byline .= $this->get_guest_author_byline_html( $last_guest_author );
				} else {
					foreach ( $guest_authors as $guest_author ) {
						$byline .= $this->get_guest_author_byline_html( $guest_author ) . ', ';
					}

					$byline .= 'and ' . $this->get_guest_author_byline_html( $last_guest_author );
				}

				$byline .= '</p>';
			}

			$last_additional_guest_author = array_pop( $additional_guest_author_objs );

			if ( $last_additional_guest_author ) { // Same as above, but for the additional reporting section.
				if ( empty( $additional_guest_author_objs ) ) {
					$byline .= '<p>Additional reporting by ' . $this->get_guest_author_byline_html( $last_additional_guest_author ) . '</p>';
				} else {
					$byline .= '<p>Additional reporting by ';

					foreach ( $additional_guest_author_objs as $additional_guest_author ) {
						$byline .= $this->get_guest_author_byline_html( $additional_guest_author ) . ', ';
					}

					$byline .= 'and ' . $this->get_guest_author_byline_html( $last_additional_guest_author ) . '</p>';
				}
			}

			ConsoleColor::white( '----Byline----' )->output();
			ConsoleColor::bright_white( $byline )->output();
			ConsoleColor::white( '--------------' )->output();

			$post_content = $post->post_content;
			if ( str_contains( $post_content, '<!-- wp:paragraph {"className":"p-additional-reporters"} -->' ) ) {
				// @see https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Command/General/ChorusCmsMigrator.php#L437
				$double_new_line_pos = strpos( $post_content, "\n\n", strpos( $post_content, '<!-- wp:paragraph {"className":"p-additional-reporters"} -->' ) );

				if ( false === $double_new_line_pos ) {
					ConsoleColor::yellow( 'Could not find double new line' );
					continue;
				}

				$post_content = substr( $post_content, $double_new_line_pos + 2 );
			}

			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $post_content,
				]
			);

			update_post_meta( $post->ID, '_the_city_features_post_meta', $byline );
		}
	}

	/**
	 * Convenience method for getting the valid RT Camp HTML for a guest author byline.
	 *
	 * @param object $guest_author Guest author object.
	 *
	 * @return string
	 */
	private function get_guest_author_byline_html( object $guest_author ) {
		$href = site_url() . '/author/' . $guest_author->user_nicename;

		if ( ! empty( $guest_author->website ) ) {
			$href = $guest_author->website;
		}

		return "<a data-author-id='{$guest_author->user_nicename}' href='{$href}'>{$guest_author->display_name}</a>";
	}
}
