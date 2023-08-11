<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\ContentDiffMigrator as ContentDiffMigratorLogic;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\PHP as PHPUtil;
use WP_CLI;

/**
 * Content Diff Migrator CLI commands class.
 *
 * @package NewspackCustomContentMigrator\Command\General
 */
class ContentDiffMigrator implements InterfaceCommand {

	const LOG_IDS_CSV                     = 'content-diff__new-ids-csv.log';
	const LOG_IDS_MODIFIED                = 'content-diff__modified-ids.log';
	const LOG_IMPORTED_POST_IDS           = 'content-diff__imported-post-ids.log';
	const LOG_UPDATED_PARENT_IDS          = 'content-diff__updated-parent-ids.log';
	const LOG_DELETED_MODIFIED_IDS        = 'content-diff__deleted-modified-ids.log';
	const LOG_UPDATED_FEATURED_IMAGES_IDS = 'content-diff__updated-feat-imgs-ids.log';
	const LOG_UPDATED_BLOCKS_IDS          = 'content-diff__wp-blocks-ids-updates.log';
	const LOG_ERROR                       = 'content-diff__err.log';
	const LOG_RECREATED_CATEGORIES        = 'content-diff__recreated_categories.log';

	const SAVED_META_LIVE_POST_ID = 'newspackcontentdiff_live_id';

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Content Diff logic class.
	 *
	 * @var null|ContentDiffMigratorLogic Logic.
	 */
	private static $logic = null;

	/**
	 * Posts logic class.
	 *
	 * @var Posts Posts logic.
	 */
	private $posts_logic;

	/**
	 * Prefix of tables from the live DB, which are imported next to local WP tables.
	 *
	 * @var null|string Live DB tables prefix.
	 */
	private $live_table_prefix;

	/**
	 * General error log file.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_error;

	/**
	 * Log containing recreated categories term_ids.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_recreated_categories;

	/**
	 * Log containing imported post IDs.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_imported_post_ids;

	/**
	 * Log containing posts ID which had their post_parent IDs updated.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_posts_parent_ids;

	/**
	 * Log containing attachment IDs which were updated to new IDs if used as attachment images.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_featured_imgs_ids;

	/**
	 * Log containing post IDs which had their content updated with new IDs in blocks syntax.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_blocks_ids;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new Posts();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			global $wpdb;

			self::$logic    = new ContentDiffMigratorLogic( $wpdb );
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-search-new-content-on-live',
			[ $this, 'cmd_search_new_content_on_live' ],
			[
				'shortdesc' => 'Searches for new posts existing in the Live site tables and not in the local site tables, and exports the IDs to a file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir',
						'description' => 'Folder to export the IDs to.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,some_cpt. Default value is post,page,attachment.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-migrate-live-content',
			[ $this, 'cmd_migrate_live_content' ],
			[
				'shortdesc' => 'Migrates content from Live site tables to local site tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'import-dir',
						'description' => 'Folder containing the file with list of IDs to migrate.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator content-diff-fix-image-ids-in-post-content',
			[ $this, 'cmd_fix_image_ids_in_post_content' ],
			[
				'shortdesc' => 'Standalone command which fixes attachment IDs in Block content. It does so by loading all the posts, goes through post_content and gets all the WP Blocks which use attachments IDs (see \NewspackCustomContentMigrator\Logic\ContentDiffMigrator::update_blocks_ids), then it takes every single attachment file and checks if its attachment ID has changed, and if it has it updates the IDs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Optional. Post ID range minimum.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Optional. Post ID range maximum.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'local-hostname-aliases-csv',
						'description' => "Optional. CSV of image URL hostnames to be used as local hostname aliases when searching for image attachment files. If, for example, the site uses S3, and some images' src hostnames use newspack-pubname.s3.amazonaws.com in URL hostnames, we should add this AWS hostname to the list here, to treat these URLs as local hostnames when searching for the files' attachment IDs in local DB -- in other words, the search for attachment ID will substitute these aliases for actual local hostname e.g. 'host.com' and search by a local URL instead.",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator display-collations-comparison',
			[ $this, 'cmd_compare_collations_of_live_and_core_wp_tables' ],
			[
				'shortdesc' => 'Display a table comparing collations of Live and Core WP tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'CSV of tables to skip checking for collation.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'different-collations-only',
						'description' => 'This flag determines to only display tables with differing collations.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator correct-collations-for-live-wp-tables',
			[ $this, 'cmd_correct_collations_for_live_wp_tables' ],
			[
				'shortdesc' => 'This command will handle the necessary operations to match collations across Live and Core WP tables',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'mode',
						'description' => 'Determines how large the SQL insert transactions are and the latency between them.',
						'optional'    => true,
						'default'     => 'generous',
						'options'     => [
							'aggressive',
							'generous',
							'cautious',
							'calm',
						],
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'Skip checking a particular set of tables from the collation checks.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'backup-table-prefix',
						'description' => 'Prefix to use when backing up the Live tables.',
						'optional'    => true,
						'default'     => 'bak_',
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator content-diff-update-featured-images-ids',
			[ $this, 'cmd_update_feat_images_ids' ],
			[
				'shortdesc' => 'Updates all featured images thumbnails ID. Takes "old_attachment_ids"=>"new_attachment_ids" from DB, which get saved when Content Diff is performed. But optionally can also take a JSON file with attachment IDs and update only those.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir',
						'description' => 'Path to where log will be written.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids-json-file',
						'description' => 'Optional. Path to a JSON encoded array where keys are old attachment IDs and values are new attachment IDs.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-update-featured-images-ids`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_update_feat_images_ids( $pos_args, $assoc_args ) {
		global $wpdb;

		// Get params.
		$attachment_ids_map = null;
		if ( isset( $assoc_args['attachment-ids-json-file'] ) && file_exists( $assoc_args['attachment-ids-json-file'] ) ) {
			$attachment_ids_map = json_decode( file_get_contents( $assoc_args['attachment-ids-json-file'] ), true );
			if ( empty( $attachment_ids_map ) ) {
				WP_CLI::error( 'No attachment IDs found in the JSON file.' );
			}
		}
		$export_dir = $assoc_args['export-dir'];
		if ( ! file_exists( $export_dir ) ) {
			$made = mkdir( $export_dir, 0777, true );
			if ( false == $made ) {
				WP_CLI::error( "Could not create export directory $export_dir ." );
			}
		}

		// If no attachment IDs map was passed, get it from the DB.
		if ( is_null( $attachment_ids_map ) ) {
			// Get all attachment old and new IDs.
			$attachment_ids_map = $this->get_attachments_from_db();

			if ( ! $attachment_ids_map ) {
				WP_CLI::error( 'No attachment IDs found in the DB.' );
			}
		}

		// Timestamp the log.
		$ts  = gmdate( 'Y-m-d h:i:s a', time() );
		$log = 'update-featured-images-ids.log';
		$this->log( $log, sprintf( 'Starting %s.', $ts ) );

		// Get local Post IDs that were imported using Content Diff (they will have the self::SAVED_META_LIVE_POST_ID postmeta).
		$new_post_ids = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wp.ID
			FROM {$wpdb->posts} wp
			JOIN {$wpdb->postmeta} pm ON wp.ID = pm.post_id
			WHERE p.post_type <> 'attachment'
			AND pm.meta_key = %s ",
				self::SAVED_META_LIVE_POST_ID
			) 
		);

		// Update attachment IDs.
		self::$logic->update_featured_images( $new_post_ids, $attachment_ids_map, $log );

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_search_new_content_on_live( $args, $assoc_args ) {
		$export_dir        = $assoc_args['export-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'page', 'attachment' ];

		global $wpdb;
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			WP_CLI::warning( $e->getMessage() );
			WP_CLI::line( "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=generous --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
		}

		// Search distinct Post types in live DB.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		// phpcs:ignore -- table prefix string value was escaped.
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$live_table_prefix_escaped}posts ;" );
		WP_CLI::log( sprintf( 'These unique Post types exist in live DB:%s', "\n- " . implode( "\n- ", $cpts_live ) ) );

		// Validate selected post types.
		array_walk(
			$post_types,
			function ( &$v, $k ) use ( $cpts_live ) {
				if ( ! in_array( $v, $cpts_live ) ) {
					WP_CLI::error( sprintf( 'Post type %s not found in live DB.', $v ) );
				}
			}
		);

		// Get list of post types except attachments.
		$post_types_non_attachments = $post_types;
		$key                        = array_search( 'attachment', $post_types_non_attachments );
		if ( false !== $key ) {
			unset( $post_types_non_attachments[ $key ] );
			$post_types_non_attachments = array_values( $post_types_non_attachments );
		}

		WP_CLI::log( sprintf( 'Now searching live DB for new Post types %s ...', implode( ', ', $post_types ) ) );
		try {
			WP_CLI::log( sprintf( 'Querying %s types...', implode( ',', $post_types_non_attachments ) ) );
			$results_live_posts  = self::$logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			$results_local_posts = self::$logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );

			WP_CLI::log( sprintf( 'Fetched %s total from live site. Searching new ones...', count( $results_live_posts ) ) );
			$new_live_ids = self::$logic->filter_new_live_ids( $results_live_posts, $results_local_posts );
			WP_CLI::success( sprintf( '%d new IDs found.', count( $new_live_ids ) ) );

			WP_CLI::log( 'Searching for records more recently modified on live...' );
			$modified_live_ids = self::$logic->filter_modified_live_ids( $results_live_posts, $results_local_posts );
			WP_CLI::success( sprintf( '%d modified IDs found.', count( $modified_live_ids ) ) );

			WP_CLI::log( 'Querying attachments...' );
			$results_live_attachments  = self::$logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			$results_local_attachments = self::$logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );

			WP_CLI::log( sprintf( 'Fetched %s total from live site. Searching new ones...', count( $results_live_attachments ) ) );
			$new_live_attachment_ids = self::$logic->filter_new_live_ids( $results_live_attachments, $results_local_attachments );
			$new_live_ids            = array_merge( $new_live_ids, $new_live_attachment_ids );
			WP_CLI::success( sprintf( '%d new IDs found.', count( $new_live_attachment_ids ) ) );

		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Save logs and output results.
		if ( count( $new_live_ids ) > 0 ) {
			$file = $export_dir . '/' . self::LOG_IDS_CSV;
			file_put_contents( $file, implode( ',', $new_live_ids ) );
			WP_CLI::success( sprintf( 'New IDs exported to %s', $file ) );
		}
		if ( count( $modified_live_ids ) > 0 ) {
			$file_modified = $export_dir . '/' . self::LOG_IDS_MODIFIED;
			if ( file_exists( $file_modified ) ) {
				unlink( $file_modified );
			}
			foreach ( $modified_live_ids as $modified_live_id_pair ) {
				$this->log(
					$file_modified,
					json_encode(
						[
							'live_id'  => $modified_live_id_pair['live_id'],
							'local_id' => $modified_live_id_pair['local_id'],
						]
					)
				);
			}
			WP_CLI::success( sprintf( 'Modified IDs exported to %s', $file_modified ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		$import_dir        = $assoc_args['import-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;

		// $log_lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/oilcitynews/app/setup2_fix_thumbs/cdiff_test/cdiff_logs/content-diff__imported-post-ids.log' ) );
		// $entries = [];
		// $att_ids_old = [];
		// $att_ids_new = [];
		// foreach ( $log_lines as $log_line ) {
		// $entry = json_decode( $log_line, true );
		// if ( $entry ) {
		// if ( 'attachment' != $entry['post_type'] ) {
		// continue;
		// }
		// $entries[] = $entry;
		// $att_ids_old[] = $entry['id_old'];
		// $att_ids_new[] = $entry['id_new'];
		// }
		// }
		//
		// Loop through olds.
		// $olds_same_as_news = [];
		// foreach ( $att_ids_old as $id_old ) {
		// if ( in_array( $id_old, $att_ids_new ) ) {
		// $olds_same_as_news[] = $id_old;
		// }
		// }
		//
		// 1278 of those.
		// $ids = [ 237363,237366,237387,237392,237406,237407,237411,237413,237418,237431,237440,237451,237452,237453,237454,237455,237456,237457,237458,237459,237460,237461,237462,237463,237464,237465,237466,237467,237468,237469,237470,237471,237472,237473,237474,237476,237478,237488,237501,237508,237526,237531,237534,237535,237546,237547,237548,237549,237550,237551,237564,237573,237574,237578,237579,237585,237613,237614,237617,237638,237642,237643,237644,237645,237650,237653,237654,237657,237658,237659,237663,237665,237667,237671,237672,237681,237682,237683,237684,237689,237710,237719,237720,237721,237722,237723,237724,237725,237726,237727,237728,237750,237763,237766,237790,237797,237799,237805,237814,237816,237817,237822,237827,237834,237848,237854,237863,237866,237873,237874,237892,237893,237901,237917,237918,237919,237920,237933,237937,237938,237953,237958,237961,237964,237969,238007,238011,238013,238015,238017,238018,238019,238031,238032,238044,238045,238046,238047,238048,238049,238051,238052,238053,238054,238055,238056,238057,238058,238059,238060,238061,238065,238069,238077,238089,238090,238101,238104,238105,238106,238107,238108,238109,238110,238111,238112,238113,238115,238116,238117,238126,238128,238144,238160,238165,238185,238191,238193,238194,238195,238220,238234,238241,238242,238243,238244,238245,238246,238247,238248,238253,238254,238260,238292,238306,238326,238329,238330,238331,238332,238333,238340,238341,238344,238418,238470,238564,238930,239601,239624,239625,239637,239653,239654,239709,239714,239720,239727,239733,239748,239749,239750,239751,239752,239753,239754,239755,239756,239757,239758,239759,239760,239761,239762,239763,239764,239765,239766,239767,239768,239769,239792,239793,239816,239840,239843,239844,239845,239846,239847,239848,239849,239850,239851,239863,239864,239865,239866,239867,239868,239869,239870,239871,239872,239873,239874,239875,239889,239894,239913,239916,239929,239939,239946,239948,239949,239950,239952,239953,239955,239956,239957,239959,239960,239961,239962,239963,239964,239965,239966,239967,239969,239976,239978,239989,239991,239992,239994,240024,240039,240058,240068,240071,240073,240074,240075,240080,240081,240082,240088,240089,240090,240091,240092,240093,240101,240102,240103,240104,240105,240106,240107,240111,240121,240124,240133,240138,240148,240152,240164,240171,240174,240180,240184,240191,240192,240201,240209,240219,240228,240230,240232,240275,240276,240277,240278,240279,240280,240285,240296,240305,240313,240329,240334,240338,240350,240352,240354,240356,240357,240358,240369,240389,240392,240596,240597,240602,240611,240617,240618,240621,240625,240633,240642,240643,240668,240670,240671,240672,240678,240680,240697,240699,240707,240708,240709,240710,240712,240713,240720,240722,240737,240748,240749,240750,240759,240761,240780,240781,240782,240783,240784,240785,240786,240787,240788,240789,240790,240791,240792,240808,240809,240810,242730,242750,242762,242777,242782,242804,242806,242807,242808,242809,242810,242811,242812,242813,242814,242815,242816,242818,242830,242838,242840,242843,242844,242845,242846,242847,242848,242849,242850,242851,242852,242853,242854,242855,242856,242857,242858,242859,242860,242861,242870,242875,242879,242880,242882,242883,242891,242894,242895,242900,242940,242941,242942,242943,242944,242945,242946,242947,242948,242949,242950,242951,242955,242956,242957,242958,242959,242975,242988,243007,243011,243015,243017,243028,243029,243034,243035,243036,243037,243038,243040,243041,243042,243043,243044,243045,243046,243047,243048,243049,243050,243051,243052,243053,243054,243055,243056,243057,243058,243059,243060,243061,243062,243063,243064,243065,243066,243067,243068,243069,243096,243097,243098,243099,243113,243114,243115,243116,243121,243122,243123,243126,243131,243136,243151,243152,243153,243154,243155,243156,243157,243158,243159,243160,243161,243165,243166,243167,243168,243169,243174,243175,243176,243177,243178,243179,243180,243181,243182,243183,243184,243185,243186,243187,243188,243189,243206,243208,243219,243226,243227,243244,243248,243257,243258,243259,243260,243261,243266,243267,243270,243272,243277,243278,243279,243280,243282,243283,243284,243285,243286,243287,243288,243289,243290,243293,243297,243309,243311,243312,243313,243315,243324,243351,243354,243357,243365,243375,243378,243402,243407,243410,243415,243416,243420,243421,243428,243429,243430,243431,243449,243450,243451,243452,243474,243478,243495,243496,243504,243517,243522,243535,243538,243540,243543,243548,243550,243560,243565,243566,243567,243575,243576,243577,243578,243579,243584,243585,243586,243597,243598,243607,243608,243609,243610,243611,243612,243613,243619,243624,243633,243635,243652,243658,243677,243702,243704,243705,243706,243707,243708,243709,243710,243711,243712,243713,243714,243715,243716,243717,243718,243719,243720,243721,243722,243725,243735,243736,243737,243738,243741,243742,243746,243758,243762,243775,243787,243793,243796,243812,243817,243837,243853,243877,243878,243913,243930,243937,243944,243959,243961,243962,243977,243983,243988,243995,244000,244016,244021,244025,244029,244030,244034,244068,244095,244099,244112,244114,244116,244117,244119,244120,244121,244122,244124,244125,244126,244127,244128,244129,244130,244131,244132,244133,244134,244135,244136,244137,244138,244139,244140,244141,244142,244151,244152,244154,244156,244158,244159,244164,244186,244187,244191,244194,244196,244236,244237,244238,244241,244242,244243,244244,244261,244263,244286,244296,244302,244321,244330,244349,244356,244359,244361,244363,244364,244381,244387,244388,244390,244391,244393,244394,244397,244399,244410,244415,244418,244421,244432,244441,244442,244443,244444,244445,244454,244458,244459,244460,244473,244496,244497,244498,244499,244508,244510,244518,244526,244527,244528,244529,244530,244531,244532,244533,244545,244546,244547,244548,244549,244550,244551,244552,244568,244571,244572,244573,244574,244575,244576,244577,244593,244604,244607,244608,244615,244622,244652,244653,244654,244663,244667,244668,244673,244674,244675,244676,244677,244678,244679,244690,244693,244704,244709,244710,244711,244715,244721,244743,244760,244762,244763,244766,244773,244785,244790,244803,244804,244805,244807,244822,244829,244830,244831,244834,244850,244851,244853,244855,244856,244869,244872,244873,244875,244876,244878,244879,244880,244881,244882,244893,244898,244920,244921,244922,244932,244933,244934,244942,244943,244946,244948,244949,244950,244951,244952,244953,244954,244955,244956,244957,244958,244959,244960,244961,244962,244963,244964,244965,244973,244984,245014,245015,245019,245020,245021,245022,245023,245024,245025,245026,245027,245043,245059,245060,245064,245065,245066,245082,245088,245089,245090,245108,245127,245128,245134,245151,245155,245156,245157,245158,245159,245160,245161,245162,245163,245164,245176,245193,245194,245195,245196,245197,245198,245199,245200,245201,245202,245203,245204,245211,245217,245226,245227,245228,245229,245230,245231,245232,245233,245234,245235,245236,245251,245255,245256,245257,245258,245259,245260,245261,245262,245263,245264,245265,245281,245286,245288,245303,245304,245305,245314,245329,245330,245341,245342,245344,245345,245346,245348,245349,245350,245351,245352,245353,245354,245355,245356,245357,245366,245367,245368,245369,245372,245378,245382,245383,245386,245387,245392,245408,245416,245447,245454,245458,245461,245464,245465,245466,245487,245490,245502,245503,245504,245505,245506,245507,245513,245514,245515,245518,245523,245524,245525,245526,245527,245528,245529,245530,245531,245553,245554,245555,245556,245557,245568,245577,245579,245582,245588,245600,245612,245616,245644,245647,245650,245651,245652,245655,245656,245658,245661,245663,245687,245693,245696,245727,245728,245731,245744,245763,245764,245771,245790,245791,245792,245793,245794,245795,245796,245798,245799,245804,245807,245809,245815,245828,245837,245838,245843,245844,245845,245846,245847,245849,245852,245853,245854,245855,245862,245863,245864,245865,245866,245870,245878,245890,245904,245910,245915,245920,245943,245959,245975,245980,246015,246053,246054,246072,246073,246074,246075,246076,246077,246078,246079,246080,246081,246082,246083,246084,246085,246086,246087,246088,246089,246090,246091,246092,246093,246097,246104,246105,246106,246107,246142,246143,246144,246145,246146,246164,246169,246174,246175,246176,246177,246178,246179,246180,246181,246182,246184,246188,246193,246197,246198,246199,246200,246201,246202,246203,246204,246207,246212,246213,246214,246215,246216,246217,246218,246219,246220,246221,246224,246225,246226,246227,246228,246229,246230,246231,246232,246233,246234,246235,246236,246237,246238,246239,246240,246241,246242,246243,246244,246245,246246,246250,246265,246266,246306,246307,246308,246309,246310,246311,246312,246313,246314,246315,246316,246317,246318,246319,246320,246321,246322,246323,246324,246325,246326,246328,246330,246331,246332,246333,246335,246337,246345,246347,246348,246349,246352,246355,246356,246358,246360, ];
		// $ids_csv = implode( ',', $ids );
		//
		// How many of those IDs were used as thumbs on old live DB cdiff_?
		// global $wpdb;
		//
		// $old_post_ids_which_used_old_attachment_ids_that_are_same_as_new_ids = $wpdb->get_results( "SELECT post_id, meta_value FROM {$live_table_prefix}postmeta WHERE meta_key='_thumbnail_id' AND meta_value IN ({$ids_csv})", ARRAY_A );
		// =>>> These need to get new_att_ids.
		// =>>> They also get "thumbnail_id_updated", so they don't get it updated twice in a edge scenario where
		// {"post_type":"attachment","id_old":244822,"id_new":239618}
		// {"post_type":"attachment","id_old":255644,"id_new":244822}
		//
		//
		// We should only be updating _thumbnail_id for posts that came over using Content Diff. Not posts that were double posting.

		/**
		 * Get IDs mapping from DB.
		 *
		 * Get live post_ids where their _thumbnail_id is an  IN ( "old_id" mapping ).
		 *  => update those _thumbnail_ids with same "new_id" mapping
		 */

		//
		// return;


		// Validate all params.
		$file_ids_csv      = $import_dir . '/' . self::LOG_IDS_CSV;
		$file_ids_modified = $import_dir . '/' . self::LOG_IDS_MODIFIED;
		if ( ! file_exists( $file_ids_csv ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file_ids_csv ) );
		}
		$all_live_posts_ids           = explode( ',', trim( file_get_contents( $file_ids_csv ) ) );
		$all_live_modified_posts_data = file_exists( $file_ids_modified ) ? $this->get_data_from_log( $file_ids_modified, [ 'live_id', 'local_id' ] ) : [];
		if ( empty( $all_live_posts_ids ) ) {
			WP_CLI::error( sprintf( 'File %s does not contain valid CSV IDs.', $file_ids_csv ) );
		}

		// Validate DBs.
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			WP_CLI::warning( $e->getMessage() );
			WP_CLI::line( "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=generous --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
		}

		// Set constants.
		$this->live_table_prefix             = $live_table_prefix;
		$this->log_error                     = $import_dir . '/' . self::LOG_ERROR;
		$this->log_recreated_categories      = $import_dir . '/' . self::LOG_RECREATED_CATEGORIES;
		$this->log_imported_post_ids         = $import_dir . '/' . self::LOG_IMPORTED_POST_IDS;
		$this->log_updated_posts_parent_ids  = $import_dir . '/' . self::LOG_UPDATED_PARENT_IDS;
		$this->log_deleted_modified_ids      = $import_dir . '/' . self::LOG_DELETED_MODIFIED_IDS;
		$this->log_updated_featured_imgs_ids = $import_dir . '/' . self::LOG_UPDATED_FEATURED_IMAGES_IDS;
		$this->log_updated_blocks_ids        = $import_dir . '/' . self::LOG_UPDATED_BLOCKS_IDS;

		// Timestamp the logs.
		$ts = gmdate( 'Y-m-d h:i:s a', time() );
		$this->log( $this->log_error, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_recreated_categories, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_imported_post_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_posts_parent_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_deleted_modified_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_featured_imgs_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_blocks_ids, sprintf( 'Starting %s.', $ts ) );

		// Before we create categories, let's make sure categories have valid parents. If they don't they should be fixed first.
		WP_CLI::log( 'Validating categories...' );
		$this->validate_categories();

		WP_CLI::log( 'Recreating categories...' );
		$category_term_id_updates = $this->recreate_categories();

		if ( ! empty( $all_live_modified_posts_data ) ) {
			WP_CLI::log( sprintf( 'Deleting %s modified posts before they are reimported...', count( $all_live_modified_posts_data ) ) );
		}

		/**
		 * Map of modified Post IDs.
		 *
		 * @var array $modified_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$modified_ids_map   = $this->get_ids_from_modified_posts_log( $all_live_modified_posts_data );
		$modified_live_ids  = array_keys( $modified_ids_map );
		$modified_local_ids = array_values( $modified_ids_map );
		/**
		 * Importing modified IDS. Different kind of data could have been updated for a post (content, author, featured image),
		 * so the easies way to refresh them is to:
		 * 1. delete the existing post,
		 * 2. reimport it
		 */
		// Delete outdated local Posts.
		$this->delete_local_posts( $modified_local_ids );
		$this->log( $this->log_deleted_modified_ids, implode( ',', $modified_local_ids ) );
		// Merge modified posts IDs with $all_live_posts_ids for reimport.
		$all_live_posts_ids = array_merge( $all_live_posts_ids, $modified_live_ids );

		WP_CLI::log( sprintf( 'Importing %d objects, hold tight...', count( $all_live_posts_ids ) ) );
		$imported_posts_data = $this->import_posts( $all_live_posts_ids, $category_term_id_updates );

		WP_CLI::log( 'Updating Post parent IDs...' );
		$this->update_post_parent_ids( $all_live_posts_ids, $imported_posts_data );

		WP_CLI::log( 'Updating Featured images IDs...' );
		$this->update_featured_image_ids( $imported_posts_data );

		WP_CLI::log( 'Updating attachment IDs in block content...' );
		$this->update_attachment_ids_in_blocks( $imported_posts_data );

		WP_CLI::success( 'All done migrating content! 🙌 ' );

		// Output info about all available logs.
		$cli_output_logs_report = [];
		if ( file_exists( $this->log_error ) ) {
			$cli_output_logs_report[] = sprintf( '%s - errors', $this->log_error );
		}
		if ( file_exists( $this->log_imported_post_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - all imported IDs', $this->log_imported_post_ids );
		}
		if ( file_exists( $this->log_updated_blocks_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - detailed blocks IDs post content replacements', $this->log_updated_blocks_ids );
		}
		if ( ! empty( $cli_output_logs_report ) ) {
			WP_CLI::log( 'Check the logs for more details:' );
			WP_CLI::log( '- ' . implode( "\n- ", $cli_output_logs_report ) );
		}

		wp_cache_flush();
	}

	/**
	 * Validates local DB and live DB categories. Checks if the categories' parent term_ids are correct and resets those if not.
	 *
	 * @return void
	 */
	public function validate_categories(): void {
		global $wpdb;

		// Check if any of the local categories have nonexistent wp_term_taxonomy.parent, and fix those before continuing.
		$categories = self::$logic->get_categories_with_nonexistent_parents( $wpdb->prefix );
		if ( ! empty( $categories ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $categories as $category ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . json_encode( $category );
				$term_taxonomy_ids[] = $category['term_taxonomy_id'];
			}

			WP_CLI::warning( 'The following local DB categories have invalid parent IDs which must be fixed (and set to 0) first.' );
			WP_CLI::log( $list );

			WP_CLI::confirm( "OK to fix and set all these categories' parents to 0?" );
			self::$logic->reset_categories_parents( $wpdb->prefix, $term_taxonomy_ids );
		}

		// Check the same for Live DB's categories, and fix those before continuing.
		$categories = self::$logic->get_categories_with_nonexistent_parents( $this->live_table_prefix );
		if ( ! empty( $categories ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $categories as $category ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . json_encode( $category );
				$term_taxonomy_ids[] = $category['term_taxonomy_id'];
			}

			WP_CLI::warning( 'The following live DB categories have invalid parent IDs which must be fixed (and set to 0) first.' );
			WP_CLI::log( $list );

			WP_CLI::confirm( "OK to fix and set all these categories' parents to 0?" );
			self::$logic->reset_categories_parents( $this->live_table_prefix, $term_taxonomy_ids );
		}
	}

	/**
	 * Fixes attachment IDs in Block content.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_image_ids_in_post_content( $positional_args, $assoc_args ) {
		// Params.
		global $wpdb;
		$post_id_from                = $assoc_args['post-id-from'] ?? null;
		$post_id_to                  = $assoc_args['post-id-to'] ?? null;
		$local_hostnames_aliases_csv = $assoc_args['local-hostname-aliases-csv'] ?? null;
		$log_file_path               = 'contentdiff_update_blocks_ids.log';
		if ( ( ! is_null( $post_id_from ) && is_null( $post_id_to ) ) || ( is_null( $post_id_from ) && ! is_null( $post_id_to ) ) ) {
			WP_CLI::error( 'Both --post-id-from and --post-id-to must be provided' );
		}

		// Deactivate the S3-Uploads plugin because it changes how \attachment_url_to_postid() behaves.
		WP_CLI::log( '' );
		WP_CLI::confirm( 'In order to correctly update attachment IDs in Block content, S3-Uploads plugin will be deactivated. Continue' );
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( strtolower( $plugin ), 's3-uploads.php' ) ) {
				deactivate_plugins( $plugin );
				WP_CLI::success( sprintf( 'Deactivated %s', $plugin ) );
			}
		}

		// Either use --local-hostname-aliases-csv, or search all content for used image hostnames, then display those hostnames and prompt which to use as local hostname aliases.
		if ( ! is_null( $local_hostnames_aliases_csv ) ) {
			$local_hostname_aliases = explode( ',', $local_hostnames_aliases_csv );
		} else {
			// Scan all content for used images hostnames by using NewspackPostImageDownloader.
			WP_CLI::log( 'Now searching all posts for used image URL hostnames...' );
			$downloader             = new \NewspackPostImageDownloader\Downloader();
			$posts                  = $downloader->get_posts_ids_and_contents();
			$all_hostnames_with_ids = $downloader->get_all_image_hostnames_from_posts( $posts );
			// Remove relative URLs, leave just ones with hostnames.
			unset( $all_hostnames_with_ids['relative URL paths'] );
			$all_hostnames = array_keys( $all_hostnames_with_ids );

			// Display all found hostnames and prompt which local aliases to use.
			WP_CLI::log( sprintf( "Found following image hosts: \n- %s\n", implode( "\n- ", $all_hostnames ) ) );
			WP_CLI::log( "If any of these hostnames should be looked up as local attachments, add them next (e.g. if S3 hostname 'newspack-pubname.s3.amazonaws.com' is used in <img> srcs in post_content, it should be added as a local hostname alias)." );
			$local_hostnames_aliases_csv = PHPUtil::readline( "Enter additional image hostnames to be treated as local, or leave blank for none (CSVs, don't use any extra spaces): " );
			$local_hostname_aliases      = explode( ',', $local_hostnames_aliases_csv );
		}

		// Either use --post-id-to and --post-id-from, or get all post IDs.
		if ( is_null( $post_id_to ) || is_null( $post_id_from ) ) {
			WP_CLI::log( 'Getting a list of all the post IDs...' );
			$post_ids = $this->posts_logic->get_all_posts_ids();
		} else {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select ID
					from $wpdb->posts
					where post_type = 'post'
					and post_status in ( 'publish', 'draft' ) 
					and ID >= %d
					and ID <= %d
					order by ID asc",
					$post_id_from,
					$post_id_to
				)
			);
		}

		// Run the command on a single $post_id at a time to control interruptions more easily.
		$known_attachment_ids_updates = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			self::$logic->update_blocks_ids( [ $post_id ], $known_attachment_ids_updates, $local_hostname_aliases, $log_file_path );
		}

		wp_cache_flush();
		WP_CLI::success( sprintf( 'Done. Check %s.', $log_file_path ) );
	}

	/**
	 * Recreates all categories from Live to local.
	 *
	 * If hierarchical cats are used, their whole structure should be in place when they get assigned to posts.
	 *
	 * @return array Map of category term_id udpdates. Keys are categories' term_ids on Live and values are corresponding
	 *               categories' term_ids on local (staging).
	 */
	public function recreate_categories() {
		$category_term_id_updates = self::$logic->recreate_categories( $this->live_table_prefix );

		// Log category term_id updates.
		$this->log(
			$this->log_recreated_categories,
			json_encode( [ 'category_term_id_updates' => $category_term_id_updates ] )
		);

		return $category_term_id_updates;
	}

	/**
	 * Permanently deletes local posts.
	 *
	 * @param array $ids Post IDs.
	 *
	 * @return void
	 */
	public function delete_local_posts( array $ids ): void {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Creates and imports posts and all related post data. Skips previously imported IDs found in $this->log_imported_post_ids.
	 *
	 * @param array $all_live_posts_ids       Live IDs to be imported to local.
	 * @param array $category_term_id_updates Map of updated category term_ids. Keys are Categories' term_ids on live, and values
	 *                                        are corresponding Categories' term_ids on local (staging).
	 *
	 * @return array $imported_posts_data {
	 *     Array with subarray records for all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function import_posts( $all_live_posts_ids, $category_term_id_updates ) {

		$post_ids_for_import = $all_live_posts_ids;

		// Skip previously imported posts.
		$imported_posts_data = $this->get_data_from_log( $this->log_imported_post_ids, [ 'post_type', 'id_old', 'id_new' ] ) ?? [];
		foreach ( $imported_posts_data as $imported_post_data ) {
			$id_old     = $imported_post_data['id_old'] ?? null;
			$key_id_old = array_search( $id_old, $post_ids_for_import );
			if ( ! is_null( $id_old ) && false !== $key_id_old ) {
				unset( $post_ids_for_import[ $key_id_old ] );
			}
		}
		if ( empty( $post_ids_for_import ) ) {
			WP_CLI::log( 'All posts were already imported, moving on.' );
			return $imported_posts_data;
		}
		if ( $post_ids_for_import !== $all_live_posts_ids ) {
			$post_ids_for_import = array_values( $post_ids_for_import );
			WP_CLI::log( sprintf( '%s of total %d IDs were already imported, continuing from there. Hold tight..', count( $all_live_posts_ids ) - count( $post_ids_for_import ), count( $all_live_posts_ids ) ) );
		}

		// Import Posts.
		$percent_progress = null;
		foreach ( $post_ids_for_import as $key_post_id => $post_id_live ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			self::$logic->get_progress_percentage( count( $post_ids_for_import ), $key_post_id + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get all Post data from DB.
			$post_data = self::$logic->get_post_data( (int) $post_id_live, $this->live_table_prefix );
			$post_type = $post_data[ self::$logic::DATAKEY_POST ]['post_type'];

			// First just insert a new blank `wp_posts` record to get the new ID.
			try {
				$post_id_new           = self::$logic->insert_post( $post_data[ self::$logic::DATAKEY_POST ] );
				$imported_posts_data[] = [
					'post_type' => $post_type,
					'id_old'    => (int) $post_id_live,
					'id_new'    => (int) $post_id_new,
				];
			} catch ( \Exception $e ) {
				$this->log( $this->log_error, sprintf( 'import_posts error while inserting post_type %s id_old=%d : %s', $post_type, $post_id_live, $e->getMessage() ) );
				WP_CLI::warning( sprintf( 'Error inserting %s Live ID %d (details in log file)', $post_type, $post_id_live ) );

				// Error is logged. Continue importing other posts.
				continue;
			}

			// Now import all related Post data.
			$import_errors = self::$logic->import_post_data( $post_id_new, $post_data, $category_term_id_updates );
			if ( ! empty( $import_errors ) ) {
				$msg = sprintf( 'Errors during import post_type=%s, id_old=%d, id_new=%d :', $post_type, $post_id_live, $post_id_new );
				foreach ( $import_errors as $import_error ) {
					$msg .= PHP_EOL . '- ' . $import_error;
				}
				$this->log( $this->log_error, $msg );
				WP_CLI::warning( $msg );
			}

			// Log imported post.
			$this->log(
				$this->log_imported_post_ids,
				json_encode(
					[
						'post_type' => $post_type,
						'id_old'    => (int) $post_id_live,
						'id_new'    => (int) $post_id_new,
					]
				)
			);

			// Save some metas.
			update_post_meta( $post_id_new, self::SAVED_META_LIVE_POST_ID, $post_id_live );
		}

		// Flush the cache for `$wpdb::update`s to sink in.
		wp_cache_flush();

		return $imported_posts_data;
	}

	/**
	 * Updates all Posts' post_parent IDs.
	 *
	 * @param array $all_live_posts_ids Old (Live) IDs to have their post_parent updated.
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_post_parent_ids( $all_live_posts_ids, $imported_posts_data ) {

		$parent_ids_for_update = $all_live_posts_ids;

		// Skip previously updated IDs.
		$previously_updated_parent_ids_data = $this->get_data_from_log( $this->log_updated_posts_parent_ids, [ 'id_old' ] );
		foreach ( $previously_updated_parent_ids_data as $entry ) {
			$id_old     = $entry['id_old'] ?? null;
			$key_id_old = array_search( $id_old, $parent_ids_for_update );
			if ( ! is_null( $id_old ) && false !== $key_id_old ) {
				unset( $parent_ids_for_update[ $key_id_old ] );
			}
		}
		if ( empty( $parent_ids_for_update ) ) {
			WP_CLI::log( 'All posts already had their post_parent updated, moving on.' );
			return;
		}
		if ( $parent_ids_for_update !== $all_live_posts_ids ) {
			$parent_ids_for_update = array_values( $parent_ids_for_update );
			WP_CLI::log( sprintf( '%s post_parent IDs of total %d were already updated, continuing from there..', count( $all_live_posts_ids ) - count( $parent_ids_for_update ), count( $all_live_posts_ids ) ) );
		}

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Try and free some memory.
		$all_live_posts_ids  = null;
		$imported_posts_data = null;
		usleep( 100000 );

		// Update parent IDs.
		global $wpdb;
		$percent_progress = null;
		foreach ( $parent_ids_for_update as $key_id_old => $id_old ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			self::$logic->get_progress_percentage( count( $parent_ids_for_update ), $key_id_old + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get new local Post ID.
			$id_new = $imported_post_ids_map[ $id_old ] ?? null;
			$id_new = is_null( $id_new ) ? $imported_attachment_ids_map[ $id_old ] : $id_new;

			// Get Post's post_parent which uses the live DB ID.
			$parent_id_old = $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM $wpdb->posts WHERE ID = %d;", $id_new ) );

			// No update to do.
			if ( ( '0' == $parent_id_old ) || empty( $parent_id_old ) ) {
				continue;
			}

			// Get new post_parent.
			$parent_id_new = $imported_post_ids_map[ $parent_id_old ] ?? null;
			// Check if it's perhaps an attachment.
			$parent_id_new = is_null( $parent_id_new ) && array_key_exists( $parent_id_old, $imported_attachment_ids_map ) ? $imported_attachment_ids_map[ $parent_id_old ] : $parent_id_new;

			// It's possible that this $post's post_parent already existed in local DB before the Content Diff import was run, so
			// it won't be present in the list of the posts we imported. Let's try and search for the new ID directly in DB.
			// First try searching by postmeta self::SAVED_META_LIVE_POST_ID -- in case a previous content diff imported it.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = self::$logic->get_current_post_id_by_custom_meta( $parent_id_old, self::SAVED_META_LIVE_POST_ID );
			}
			// Next try searching for the new parent_id by joining local and live DB tables.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = self::$logic->get_current_post_id_by_comparing_with_live_db( $parent_id_old, $this->live_table_prefix );
			}

			// Warn if this post_parent object was not found/imported. It might be legit, like the parent object being a
			// post_type different than the supported post type, or an error like the post_parent object missing in Live DB.
			if ( is_null( $parent_id_new ) ) {
				// If all attempts failed (possible that parent didn't exist in live DB, or is of a non-imported post_type), set it to 0.
				$parent_id_new = 0;

				$this->log( $this->log_error, sprintf( 'update_post_parent_ids error, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is 0.', $id_old, $id_new, $parent_id_old ) );
			}

			// Update.
			if ( $parent_id_old != $parent_id_new ) {
				self::$logic->update_post_parent( $id_new, $parent_id_new );
			}

			// Log IDs of the Post.
			$log_entry = [
				'id_old' => $id_old,
				'id_new' => $id_new,
			];
			if ( 0 != $parent_id_old && ! is_null( $parent_id_new ) ) {
				// Log, add IDs of post_parent.
				$log_entry = array_merge(
					$log_entry,
					[
						'parent_id_old' => $parent_id_old,
						'parent_id_new' => $parent_id_new,
					]
				);
			}
			$this->log( $this->log_updated_posts_parent_ids, json_encode( $log_entry ) );
		}
	}

	/**
	 * Updates all Featured Images IDs.
	 *
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_featured_image_ids( $imported_posts_data ) {
		global $wpdb;

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_db();

		/**
		 * Get IDs mapping from DB.
		 *
		 * Get live table's Post IDs where their _thumbnail_id is an IN ( "old_id" attachment mapping ).
		 *  => update those Post IDs' _thumbnail_ids with corresponding "old_id"=>"new_id" mapping
		 */

		// Get new Post IDs from DB.
		$new_post_ids = array_values( $imported_post_ids_map );

		self::$logic->update_featured_images( $new_post_ids, $imported_attachment_ids_map, $this->log_updated_featured_imgs_ids );
	}

	/**
	 * Updates Attachment IDs in Post contents.
	 *
	 * Some Gutenberg Blocks contain `id` or `ids` of Attachments attributes in their headers, and image elements contain those
	 * IDs too.
	 *
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_attachment_ids_in_blocks( $imported_posts_data ) {

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Skip previously updated Posts.
		$updated_post_ids               = $this->get_data_from_log( $this->log_updated_blocks_ids, [ 'id_new' ] ) ?? [];
		$new_post_ids_for_blocks_update = array_values( $imported_post_ids_map );
		foreach ( $updated_post_ids as $entry ) {
			$id_new     = $entry['id_new'] ?? null;
			$key_id_new = array_search( $id_new, $new_post_ids_for_blocks_update );
			if ( ! is_null( $id_new ) && false !== $key_id_new ) {
				unset( $new_post_ids_for_blocks_update[ $key_id_new ] );
			}
		}
		if ( empty( $new_post_ids_for_blocks_update ) ) {
			WP_CLI::log( 'All posts already had their blocks\' att. IDs updated, moving on.' );
			return;
		}
		if ( array_values( $imported_post_ids_map ) !== $new_post_ids_for_blocks_update ) {
			$new_post_ids_for_blocks_update = array_values( $new_post_ids_for_blocks_update );
			WP_CLI::log( sprintf( '%s of total %d posts already had their blocks\' IDs updated, continuing from there..', count( $imported_post_ids_map ) - count( $new_post_ids_for_blocks_update ), count( $imported_post_ids_map ) ) );
		}

		self::$logic->update_blocks_ids( $new_post_ids_for_blocks_update, $imported_attachment_ids_map, [], $this->log_updated_blocks_ids );
	}

	/**
	 * This function will display a table comparing the collations of Live and Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_compare_collations_of_live_and_core_wp_tables( $args, $assoc_args ) {
		$live_table_prefix     = $assoc_args['live-table-prefix'];
		$skip_tables           = [];
		$different_tables_only = $assoc_args['different-collations-only'] ?? false;

		if ( ! empty( $assoc_args['skip-tables'] ) ) {
			$skip_tables = explode( ',', $assoc_args['skip-tables'] );
		}

		$tables = [];

		if ( $different_tables_only ) {
			$tables = self::$logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );
		} else {
			$tables = self::$logic->get_collation_comparison_of_live_and_core_wp_tables( $live_table_prefix, $skip_tables );
		}

		if ( ! empty( $tables ) ) {
			WP_CLI\Utils\format_items( 'table', $tables, array_keys( $tables[0] ) );
		} else {
			WP_CLI::success( 'Live and Core WP DB table collations match!' );
		}
	}

	/**
	 * This function will execute the necessary steps to get Live WP
	 * tables to match the collation of Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_correct_collations_for_live_wp_tables( $args, $assoc_args ) {
		$live_table_prefix = $assoc_args['live-table-prefix'];
		$mode              = $assoc_args['mode'];
		$backup_prefix     = isset( $assoc_args['backup-table-prefix'] ) ? $assoc_args['backup-table-prefix'] : 'collationbak_';
		$skip_tables       = isset( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];

		$tables_with_differing_collations = self::$logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );

		if ( ! empty( $tables_with_differing_collations ) ) {
			WP_CLI\Utils\format_items( 'table', $tables_with_differing_collations, array_keys( $tables_with_differing_collations[0] ) );
		}

		switch ( $mode ) {
			case 'aggressive':
				$records_per_transaction = 15000;
				$sleep_in_seconds        = 1;
				break;
			case 'generous':
				$records_per_transaction = 10000;
				$sleep_in_seconds        = 2;
				break;
			case 'calm':
				$records_per_transaction = 1000;
				$sleep_in_seconds        = 3;
				break;
			default: // Cautious.
				$records_per_transaction = 5000;
				$sleep_in_seconds        = 2;
				break;
		}

		WP_CLI::log( "Now fixing $live_table_prefix tables collations..." );
		foreach ( $tables_with_differing_collations as $result ) {
			WP_CLI::log( 'Addressing ' . $result['table'] . ' table...' );
			self::$logic->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $records_per_transaction, $sleep_in_seconds, $backup_prefix );
		}
	}

	/**
	 * Filters the log data array by where conditions.
	 *
	 * @param array  $imported_posts_log_data Log data array, consists of subarrays with one or more multiple key=>values.
	 * @param string $where_key               Search key.
	 * @param array  $where_values            Search value.
	 * @param string $where_operand           Search operand, can be '==' or '!='.
	 * @param bool   $return_first            If true, return just the first matched entry, otherwise returns all matched entries.
	 *
	 * @throws \RuntimeException In case an unsupported $where_operand was given.
	 *
	 * @return array Found results. Mind that if $return_first is true, it will return a one-dimensional array,
	 *               and if $return_first is false, it will return two-dimensional array with all matched elements as subarrays.
	 */
	private function filter_imported_posts_log( array $imported_posts_log_data, string $where_key, array $where_values, string $where_operand, bool $return_first = true ): array {
		$return                   = [];
		$supported_where_operands = [ '==', '!=' ];

		// Validate $where_operand.
		if ( ! in_array( $where_operand, $supported_where_operands ) ) {
			throw new \RuntimeException( sprintf( 'Where operand %s is not supported.', $where_operand ) );
		}

		foreach ( $imported_posts_log_data as $entry ) {

			// Check $where conditions.
			foreach ( $where_values as $where_value ) {

				$matched = false;
				if ( '==' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value == $entry[ $where_key ];
				} elseif ( '!=' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value != $entry[ $where_key ];
				}

				if ( true === $matched ) {
					$return[] = $entry;

					// Return the very first element matching $where.
					if ( true === $return_first ) {
						return $entry;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Gets IDs from the log for Posts, Pages and other post types which are not Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs.
	 */
	private function get_non_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '!=', false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_post_ids_map;
	}

	/**
	 * Gets IDs from the log for Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs, keys are old/live IDs, values are new/local IDs.
	 */
	private function get_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '==', false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_attachment_ids_map;
	}

	/**
	 * Gets old => new attachment IDs mapping from the postmeta.
	 *
	 * @return array Imported attachment IDs, keys are old/live IDs, values are new/local/Staging IDs.
	 */
	private function get_attachments_from_db(): array {
		global $wpdb;

		$attachment_ids_map = [];

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT wpm.post_id, wpm.meta_value
					FROM {$wpdb->postmeta} wpm
					JOIN {$wpdb->posts} wp ON wp.ID = wpm.post_id 
					WHERE wpm.meta_key = %s
					AND wp.post_type = 'attachment';",
				self::SAVED_META_LIVE_POST_ID,
			),
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$attachment_ids_map[ $result['meta_value'] ] = $result['post_id'];
		}

		return $attachment_ids_map;
	}

	/**
	 * Gets a map of live=>local IDs from the modified IDs log.
	 *
	 * @param array $modified_posts_log_data Modified post IDs log data.
	 *
	 * @return array IDs, keys are live IDs, values are local IDs.
	 */
	private function get_ids_from_modified_posts_log( array $modified_posts_log_data ): array {
		$ids = [];
		foreach ( $modified_posts_log_data as $entry ) {
			$ids[ $entry['live_id'] ] = $entry['local_id'];
		}

		return $ids;
	}

	/**
	 * Gets data from logs which contain JSON encoded arrays per line.
	 *
	 * @param string $log       Path to log.
	 * @param array  $json_keys Keys to fetch from log lines.
	 *
	 * @return array|null Array with subarray elements with $json_keys keys and values pulled from the log, or null if file can't be found.
	 */
	private function get_data_from_log( $log, $json_keys ) {
		$data = [];

		// Read line by line.
		$handle = fopen( $log, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				// Skip if not JSON data on line.
				$line_decoded = json_decode( $line, true );
				if ( ! is_array( $line_decoded ) ) {
					continue;
				}

				// Get data if line contains these JSON keys.
				$data_key          = count( $data );
				$data[ $data_key ] = [];
				foreach ( $json_keys as $json_key ) {
					if ( isset( $line_decoded[ $json_key ] ) ) {
						$data[ $data_key ] = array_merge( $data[ $data_key ], [ $json_key => $line_decoded[ $json_key ] ] );
					}
				}
			}

			fclose( $handle );
		} else {
			return null;
		}

		return $data;
	}

	/**
	 * Validates DB tables.
	 *
	 * @param string $live_table_prefix Live table prefix.
	 * @param array  $skip_tables       Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case that table collations do not match.
	 *
	 * @return void
	 */
	public function validate_db_tables( string $live_table_prefix, array $skip_tables ): void {
		self::$logic->validate_core_wp_db_tables_exist_in_db( $live_table_prefix, $skip_tables );
		if ( ! self::$logic->are_table_collations_matching( $live_table_prefix, $skip_tables ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}
	}

	/**
	 * Logs error message to file.
	 *
	 * @param string $file Full file path.
	 * @param string $msg  Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND );
	}
}
