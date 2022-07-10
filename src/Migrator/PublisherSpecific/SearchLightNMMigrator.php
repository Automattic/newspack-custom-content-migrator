<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Search Light New Mexico.
 */
class SearchLightNMMigrator implements InterfaceMigrator {
	// Logs.
	const SUBTITLE_LOGS   = 'SLNM_subtitles.log';
	const SHORTCODES_LOGS = 'SLNM_shortcodes.log';

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
		$this->posts_logic                       = new PostsLogic();
		$this->dom_crawler                       = new Crawler();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator searchlightnm-migrate-post-subtitle',
			array( $this, 'searchlightnm_migrate_post_subtitle' ),
			array(
				'shortdesc' => 'Migrate post subtitle from content.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlightnm-migrate-shortcodes',
			array( $this, 'searchlightnm_migrate_shortcodes' ),
			array(
				'shortdesc' => 'Migrate a couple of shortcodes to Gutenberg (Rows, columns, and images).',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlightnm-copy-migrated-content',
			array( $this, 'searchlightnm_copy_migrated_content' ),
			array(
				'shortdesc' => 'Copy migrated posts content from an SQL table.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlight-remove-first-image-from-post-body',
			array( $this, 'searchlight_remove_first_image_from_post_body' ),
			array(
				'shortdesc' => 'Remove the first image from the post body, usefull to normalize the posts content in case some contains the featured image in their body and others not.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlight-set-featured-image-position',
			array( $this, 'searchlight_set_featured_image_postition' ),
			array(
				'shortdesc' => '',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator searchlightnm-fix-column-size-to-full-size',
			array( $this, 'searchlightnm_fix_column_size_to_full_size' ),
			array(
				'shortdesc' => 'Clean post content from shortcodes.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-migrate-post-subtitle`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_migrate_post_subtitle( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, 'newspack_post_subtitle', true ) ) {
				continue;
			}

			update_post_meta( $post->ID, 'newspack_post_subtitle', $post->post_excerpt );
		}

		// manually fixing a couple of subtitles.
		update_post_meta( 90316, 'newspack_post_subtitle', 'Landlords tried to evict nearly 200 households with pending rental assistance applications.' );
		update_post_meta( 90250, 'newspack_post_subtitle', 'Scores of students in the Four Corners have vanished from attendance rolls. How did they get lost?' );
		update_post_meta( 90111, 'newspack_post_subtitle', 'Child welfare agency under fire as turmoil continues' );
		update_post_meta( 89863, 'newspack_post_subtitle', 'As rents skyrocket, more and more landlords refuse Section 8 tenants like 51-year-old grandmother Renee Garnett.' );
		update_post_meta( 89812, 'newspack_post_subtitle', 'CYFD, La Familia-Namaste ignored or covered up child abuse, suit claims' );
		update_post_meta( 89795, 'newspack_post_subtitle', 'UNM law professor and advocate Serge Martinez has answers.' );
		update_post_meta( 89718, 'newspack_post_subtitle', 'Rental prices are on the rise, and the need for affordable housing far outstrips what’s available. This motel and others have become a haven for people with nowhere else to go.' );
		update_post_meta( 89627, 'newspack_post_subtitle', 'New Mexico’s new cannabis industry pits growers against communities, both needing a precious commodity: water.' );
		update_post_meta( 89576, 'newspack_post_subtitle', 'As the COVID-19 pandemic began to sweep across New Mexico and the nation, Searchlight New Mexico launched a series of stories called Hitting Home to chronicle the impact on five New Mexico towns: Shiprock, Gallup, Las Vegas, Carlsbad and Anthony. It was an ambitious project that ended up involving nine reporters, two editors, two contract photographers—and one staff photographer. Me.' );
		update_post_meta( 89532, 'newspack_post_subtitle', 'Mariel Nanasi has been PNM’s watchdog for years. Could she kill the state’s biggest energy merger?' );
		update_post_meta( 89469, 'newspack_post_subtitle', 'CYFD secretary replaced by former New Mexico Supreme Court Justice' );
		update_post_meta( 89446, 'newspack_post_subtitle', 'A Searchlight investigation finds that in spite of state and federal bans, evictions continue.' );
		update_post_meta( 89272, 'newspack_post_subtitle', 'Navajo students went to extraordinary lengths to attend virtual classes in internet dead zones. How did they do it?' );
		update_post_meta( 89256, 'newspack_post_subtitle', 'High-level officials accuse the CYFD of retaliation ' );
		update_post_meta( 89045, 'newspack_post_subtitle', 'With the federal eviction moratorium set to expire, this New Mexico town on the edge of Navajo Nation is reaching a breaking point' );
		update_post_meta( 89028, 'newspack_post_subtitle', 'Why the number of American Indian and Alaska Natives who have died during the coronavirus pandemic may never be known' );
		update_post_meta( 88851, 'newspack_post_subtitle', 'In Anthony, N.M., a border community and its farmworkers find solidarity in the pandemic' );
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-migrate-shortcodes`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_migrate_shortcodes( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				// 'post__in'    => array( 90805 ),
			)
		);

		foreach ( $posts as $post ) {
			$migrated            = false;
			$post_content_blocks = array();

			// clean posts with the vc_row shortcode inside paragraph tags.
			$post_content = trim( $post->post_content );
			if ( str_starts_with( $post_content, '<p>[vc_row' ) && str_ends_with( $post_content, '/vc_row]</p>' ) ) {
				$post_content = ltrim( $post_content, '<p>' );
				$post_content = rtrim( $post_content, '</p>' );
			}

			foreach ( parse_blocks( $post_content ) as $content_block ) {
				// remove shortcodes from classic blocks that starts with a shortcode.
				if ( ! $content_block['blockName'] && substr( $content_block['innerHTML'], 0, 1 ) === '[' ) {
					// print_r( $content_block['innerHTML'] );
					// die();
					$post_content_blocks = array_merge( $post_content_blocks, $this->parseShortcodeContentToBlock( $post->ID, $content_block['innerHTML'] ) );
					$migrated            = true;
					continue;
				}

				$post_content_blocks[] = $content_block;
			}

			if ( $migrated ) {
				$post_content_without_shortcodes = serialize_blocks( array_values( array_filter( $post_content_blocks ) ) );
				$update                          = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $post_content_without_shortcodes,
					)
				);
				if ( is_wp_error( $update ) ) {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Failed to update post %d because %s', $post->ID, $update->get_error_message() ) );
				} else {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Post %d cleaned from shortcodes.', $post->ID ) );
				}
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-copy-migrated-content`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_copy_migrated_content( $args, $assoc_args ) {
		global $wpdb;
		$migrated_content = $wpdb->get_results(
			"SELECT ID, post_content
		FROM {$wpdb->prefix}posts_migrated
		WHERE ID in (91094,90838,90489,90471,90384,87778,89869,87905,87777,86691,86871,86788,86257,84998,85826,85806,85002,85000,84996,84964,84958,83803,83557,83114,83103,83098,83096,83093,83033,83031,82980,82831,82829,82827,82825,82823,82821,90805,90794,90788,90779,90763,90755,90742,90719,90625,90537,90441,90371,90316,90286,90266,90250,90230,90188,90138,90129,90111,89738,89863,89853,89812,89795,89718,89627,89576,89532,89504,89476,89469,89446,89358,89343,89332,89272,89256,89223,89193,89180,89045,89028,88851,88834,88808,88785,88739,88657,88626,88558,88541,88520,88105,88027,87999,87963,87947,87917,87839,87806,87789,87753,87717,87700,87644,87661,87614,87565,87536,87499,87474,87411,87277,87263,87204,87187,87156,87144,87123,87105,87045,87015,86989,86875,86924,86717,86698,86652,86622,86596,86550,86449,86415,86335,86296,86154,85923,85846,84990,84762,86498,84777,84807,84847,84813,84822,84827,84835,84831,84837,84845,84852,83487,83342,84855,84858,84861,84863,84901,84916,84923,84934,84939,84942,84947,85047,85396,85074,85092,85095,85378,85108,83695,85135,85270,85283,83642,83644,83646,83648,85569,86084,86068,85892,83668,83676,86267,85897,85905,86212,86348,85303,85298,85289,43373,42447,41628,37420,37417,37291,34030,34024,33750,31371,83710,30471,27427,26100,26004,25268,24369,21886,21895,18836,18780,18772,18790,18755,17118,17946,16651,16590,14776,13993,13974,13966,13408,12497,12151,11935,11390,13446,10821,10285,9515,9512,9505,8456,8474,8479,8185,7753,7749,6290,6049,5815,5667,5594,5307,5137,83703,3511,2670)
		;"
		);

		foreach ( $migrated_content as $migrated_post ) {
			$update = wp_update_post(
				array(
					'ID'           => $migrated_post->ID,
					'post_content' => $migrated_post->post_content,
				)
			);
			if ( is_wp_error( $update ) ) {
				$this->log( self::SHORTCODES_LOGS, sprintf( 'Failed to update post %d because %s', $migrated_post->ID, $update->get_error_message() ) );
			} else {
				WP_CLI::line( sprintf( '%d,%s,%s', $migrated_post->ID, str_replace( get_site_url(), 'https://searchlightnm.org', get_permalink( $migrated_post->ID ) ), get_permalink( $migrated_post->ID ) ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator searchlight-remove-first-image-from-post-body`.
	 */
	public function searchlight_remove_first_image_from_post_body() {
		global $wpdb;
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				// 'post__in'    => array( 2670 ),
			)
		);

		foreach ( $posts as $post ) {
			$remove_first_block = false;
			$blocks             = parse_blocks( $post->post_content );
			$first_block        = current( $blocks );
			$first_inner_block  = current( $first_block['innerBlocks'] );

			if ( ! $first_inner_block ) {
				continue;
			}
			if ( 'core/column' === $first_inner_block['blockName'] ) {
				$possible_image_block = current( $first_inner_block['innerBlocks'] );
				if ( 'core/image' === $possible_image_block['blockName'] ) {
					$remove_first_block = true;
				}
			}

			if ( $remove_first_block ) {
				array_shift( $blocks );
				$content = serialize_blocks( $blocks );

				if ( $content !== $post->post_content ) {
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
	}

	/**
	 * Callable for `newspack-content-migrator searchlight-set-featured-image-position`.
	 */
	public function searchlight_set_featured_image_postition() {
		global $wpdb;
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		foreach ( $posts as $post ) {
			update_post_meta( $post->ID, 'newspack_featured_image_position', 'above' );
			WP_CLI::warning( "Updated {$post->ID}" );
		}
	}

	/**
	 * Callable for `newspack-content-migrator searchlightnm-fix-column-size-to-full-size`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function searchlightnm_fix_column_size_to_full_size( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				'post__in'    => array( 91094, 90838, 90489, 90471, 90384, 87778, 89869, 87905, 87777, 86691, 86871, 86788, 86257, 84998, 85826, 85806, 85002, 85000, 84996, 84964, 84958, 83803, 83557, 83114, 83103, 83098, 83096, 83093, 83033, 83031, 82980, 82831, 82829, 82827, 82825, 82823, 82821, 90805, 90794, 90788, 90779, 90763, 90755, 90742, 90719, 90625, 90537, 90441, 90371, 90316, 90286, 90266, 90250, 90230, 90188, 90138, 90129, 90111, 89738, 89863, 89853, 89812, 89795, 89718, 89627, 89576, 89532, 89504, 89476, 89469, 89446, 89358, 89343, 89332, 89272, 89256, 89223, 89193, 89180, 89045, 89028, 88851, 88834, 88808, 88785, 88739, 88657, 88626, 88558, 88541, 88520, 88105, 88027, 87999, 87963, 87947, 87917, 87839, 87806, 87789, 87753, 87717, 87700, 87644, 87661, 87614, 87565, 87536, 87499, 87474, 87411, 87277, 87263, 87204, 87187, 87156, 87144, 87123, 87105, 87045, 87015, 86989, 86875, 86924, 86717, 86698, 86652, 86622, 86596, 86550, 86449, 86415, 86335, 86296, 86154, 85923, 85846, 84990, 84762, 86498, 84777, 84807, 84847, 84813, 84822, 84827, 84835, 84831, 84837, 84845, 84852, 83487, 83342, 84855, 84858, 84861, 84863, 84901, 84916, 84923, 84934, 84939, 84942, 84947, 85047, 85396, 85074, 85092, 85095, 85378, 85108, 83695, 85135, 85270, 85283, 83642, 83644, 83646, 83648, 85569, 86084, 86068, 85892, 83668, 83676, 86267, 85897, 85905, 86212, 86348, 85303, 85298, 85289, 43373, 42447, 41628, 37420, 37417, 37291, 34030, 34024, 33750, 31371, 83710, 30471, 27427, 26100, 26004, 25268, 24369, 21886, 21895, 18836, 18780, 18772, 18790, 18755, 17118, 17946, 16651, 16590, 14776, 13993, 13974, 13966, 13408, 12497, 12151, 11935, 11390, 13446, 10821, 10285, 9515, 9512, 9505, 8456, 8474, 8479, 8185, 7753, 7749, 6290, 6049, 5815, 5667, 5594, 5307, 5137, 83703, 3511, 2670 ),
			)
		);

		foreach ( $posts as $post ) {
			$post_content_blocks = array();

			foreach ( parse_blocks( $post->post_content ) as $content_block ) {
				// remove shortcodes from classic blocks that starts with a shortcode.
				if ( $content_block['blockName'] === 'core/columns' ) {
					foreach ( $content_block['innerBlocks'] as $column_index => $column ) {
						if ( 'core/column' !== $column['blockName'] ) {
							continue;
						}

						if ( array_key_exists( 'width', $column['attrs'] ) && str_ends_with( $column['attrs']['width'], '%' ) && floatval( current( explode( '%', $column['attrs']['width'] ) ) ) > 75 ) {
							$serialized_column = serialize_block( $content_block['innerBlocks'][ $column_index ] );
							$fixed_column      = str_replace( $column['attrs']['width'], '100%', $serialized_column );

							$content_block['innerBlocks'][ $column_index ] = current( parse_blocks( $fixed_column ) );
						}
					}
				}

				$post_content_blocks[] = $content_block;
			}

			$post_content_with_fixed_columns_width = serialize_blocks( $post_content_blocks );
			if ( $post_content_with_fixed_columns_width !== $post->post_content ) {
				$update = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $post_content_with_fixed_columns_width,
					)
				);
				if ( is_wp_error( $update ) ) {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Failed to update post %d because %s', $post->ID, $update->get_error_message() ) );
				} else {
					$this->log( self::SHORTCODES_LOGS, sprintf( 'Post %d cleaned from shortcodes.', $post->ID ) );
				}
			}
		}
	}

	private function parseShortcodeContentToBlock( $post_id, $content ) {
		$blocks = array();

		// ROWS.
		$row_shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_row', $content );

		if ( ! empty( $row_shortcodes[0] ) ) {
			foreach ( $row_shortcodes[0] as $row_shortcode ) {
				$row_block = $this->generate_block_columns( $post_id, $row_shortcode[0] );
				if ( $row_block ) {
					$blocks[] = $row_block;
				}
			}

			return $blocks;
		}

		$shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags_or_designations(
			array( 'vc_column_text', 'vc_raw_html', 'vc_custom_heading' ),
			array( 'vc_single_image', 'vc_gallery' ),
			$content
		);

		if ( ! is_array( $shortcodes ) ) {
			return array();
		}

		foreach ( $shortcodes as $shortcode ) {
			$shortcode = current( $shortcode );

			// SINGLE IMAGE.
			$single_image_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'vc_single_image', $shortcode );
			if ( ! empty( $single_image_shortcodes[0] ) ) {
				foreach ( $single_image_shortcodes[0] as $single_image_shortcode ) {
					$blocks[] = $this->generate_block_media( $single_image_shortcode );
				}
			}

			// SINGLE GALLERY.
			$single_gallery_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'vc_gallery', $shortcode );
			if ( ! empty( $single_gallery_shortcodes[0] ) ) {
				foreach ( $single_gallery_shortcodes[0] as $single_gallery_shortcode ) {
					$blocks[] = $this->generate_block_gallery( $post_id, $single_gallery_shortcode );
				}
			}

			// TEXT.
			$text_shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_column_text', $shortcode );

			if ( ! empty( $text_shortcodes[0] ) ) {
				foreach ( $text_shortcodes[0] as $text_shortcode ) {
					$blocks[] = $this->generate_block_paragraph( $text_shortcode[0] );
				}
			}

			// HEADING.
			$text_shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_custom_heading', $shortcode );

			if ( ! empty( $text_shortcodes[0] ) ) {
				foreach ( $text_shortcodes[0] as $text_shortcode ) {
					$blocks[] = $this->generate_block_heading( $text_shortcode[0] );
				}
			}

			// RAW HTML.
			$raw_html_shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_raw_html', $shortcode );

			if ( ! empty( $raw_html_shortcodes[0] ) ) {
				foreach ( $raw_html_shortcodes[0] as $raw_html_shortcode ) {
					$blocks[] = $this->generate_block_html( $raw_html_shortcode[0] );
				}
			}
		}

		return $blocks;
	}

	private function generate_block_columns( $post_id, $row_shortcode ) {
		$columns_content = $this->squarebracketselement_manipulator->get_shortcode_contents( $row_shortcode, array( 'vc_row' ) );
		$column          = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_column', $columns_content );
		$column_inner    = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'vc_column_inner', $columns_content );

		$column_shortcodes = ! empty( $column[0] ) ? $column[0] : $column_inner[0];

		$inner_blocks = array();
		if ( ! empty( $column_shortcodes ) ) {
			foreach ( $column_shortcodes as $column_shortcode ) {
				$inner_blocks[] = $this->generate_block_column( $post_id, $column_shortcode[0] );
			}
		}

		// Inner content.
		$inner_blocks = array_values( array_filter( $inner_blocks ) );

		if ( empty( $inner_blocks ) && str_contains( $row_shortcode, '[uncode_block' ) ) {
			return false;
		}

		$inner_content = array_fill( 1, count( $inner_blocks ), null );
		array_unshift( $inner_content, '<div class="wp-block-columns">' );
		array_push( $inner_content, '</div>' );

		return array(
			'blockName'    => 'core/columns',
			'attrs'        => array(),
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '<div class="wp-block-columns"></div>',
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Generate image block.
	 *
	 * @param string $inner_blocks Inner blocks options.
	 * @return array | boolean
	 */
	private function generate_block_column( $post_id, $inner_blocks ) {
		$column_content = $this->squarebracketselement_manipulator->get_shortcode_contents( $inner_blocks, array( 'vc_column' ) );

		preg_match( '/width="(\d+)\/(\d+)"/', $inner_blocks, $width_match );
		$column_width = ( isset( $width_match[1] ) && isset( $width_match[2] ) ) ? ( ( intval( $width_match[1] ) / intval( $width_match[2] ) ) * 100 ) . '%' : '100%';

		$processed_blocks = empty( $column_content ) ? array() : array_filter( $this->parseShortcodeContentToBlock( $post_id, $column_content ) );

		if ( empty( $processed_blocks ) ) {
			return null;
		}

		// Inner content.
		$inner_content = array_fill( 1, count( $processed_blocks ), null );
		array_unshift( $inner_content, '<div class="wp-block-column" style="flex-basis:' . $column_width . '">' );
		array_push( $inner_content, '</div>' );

		return array(
			'blockName'    => 'core/column',
			'attrs'        => array(
				'width' => $column_width,
			),
			'innerBlocks'  => $processed_blocks,
			'innerHTML'    => '<div class="wp-block-column" style="flex-basis:' . $column_width . '">',
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Generate media block.
	 *
	 * @param string $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_media( $section ) {
		preg_match( '/media="(\d+)"/', $section, $media_match );

		$media_id    = $media_match[1];
		$media_url   = wp_get_attachment_url( $media_id );
		$media_alt   = get_post_meta( $media_id, '_wp_attachment_media_alt', true );
		$media_title = get_the_title( $media_id );

		if ( ! $media_url ) {
			$this->log( self::SHORTCODES_LOGS, "Block media not found: $section" );
			return false;
		}

		if ( str_ends_with( $media_url, '.mp3' ) || str_ends_with( $media_url, '.m4a' ) || str_ends_with( $media_url, '.wav' ) ) {
			return current(
				parse_blocks(
					'<!-- wp:audio {"id":' . $media_id . '} -->
					<figure class="wp-block-audio"><audio controls src="' . $media_url . '"></audio></figure>
					<!-- /wp:audio -->'
				)
			);
		}

		return current(
			parse_blocks(
				'<!-- wp:image {"id":' . $media_id . ',"sizeSlug":"large","linkDestination":"none"} -->
				<figure class="wp-block-image size-large"><img src="' . $media_url . '" alt="' . $media_alt . '" class="wp-image-' . $media_id . '"/><figcaption>' . $media_title . '</figcaption></figure>
				<!-- /wp:image -->'
			)
		);
	}

	/**
	 * Generate gallery block.
	 *
	 * @param string $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_gallery( $post_id, $section ) {
		preg_match( '/\[vc_gallery([^\]]*) medias="(.*?)"/', $section, $media_match );
		preg_match( '/\[vc_gallery([^\]]*) type="(.*?)"/', $section, $type_match );

		$media_id     = $media_match[2];
		$gallery_type = isset( $type_match[2] ) ? $type_match[2] : false;
		$image_ids    = false;

		if ( str_contains( $media_id, ',' ) ) {
			$image_ids = explode( ',', $media_id );
		} else {
			$gallery_post = get_post_parent( $media_id );

			if ( ! $media_id || ! $gallery_post || ( $gallery_post && 'uncode_gallery' !== $gallery_post->post_type ) ) {
				$this->log( self::SHORTCODES_LOGS, "Block gallery not found (post ID $post_id): $section" );
				return false;
			}

			$image_ids = explode( ',', get_post_meta( $gallery_post->ID, '_uncode_featured_media', true ) );
		}

		if ( ! $image_ids ) {
			$this->log( self::SHORTCODES_LOGS, "Block gallery without images (post ID $post_id): $section" );
			return false;
		}

		$gallery_block = 'carousel' === $gallery_type
		? $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $image_ids )
		: $this->get_gutenberg_gallery_block_html( 'gutenberg-gallery-with-lightbox', $image_ids );

		return current( parse_blocks( $gallery_block ) );
	}



	/**
	 * Creates a Gutenberg Gallery Block with specified images.
	 *
	 * @param string $gallery_block_type Block type to generate.
	 * @param array  $att_ids Media Library attachment IDs.
	 *
	 * @return null|string Gutenberg Gallery Block HTML.
	 */
	public function get_gutenberg_gallery_block_html( $gallery_block_type, $att_ids ) {
		if ( empty( $att_ids ) ) {
			return null;
		}

		$gallery_block_html_sprintf = <<<HTML
<!-- wp:gallery {"linkTo":"none"%s} -->
<figure class="wp-block-gallery has-nested-images columns-default is-cropped">%s</figure>
<!-- /wp:gallery -->
HTML;
		$image_block_html_sprintf   = <<<HTML
<!-- wp:image {"id":%s,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="%s" alt="" class="wp-image-%s"/>%s</figure>
<!-- /wp:image -->
HTML;

		// Get all belonging Images Blocks.
		$images_blocks_html = '';
		foreach ( $att_ids as $att_id ) {
			$att_src             = wp_get_attachment_url( $att_id );
			$att_caption         = wp_get_attachment_caption( $att_id );
			$figure_caption      = empty( $att_caption ) ? '' : "<figcaption>$att_caption</figcaption>";
			$images_blocks_html .= empty( $images_blocks_html ) ? '' : "\n\n";
			$images_blocks_html .= sprintf( $image_block_html_sprintf, $att_id, $att_src, $att_id, $figure_caption );
		}

		// Add block options.
		$lightbox_images_option = 'gutenberg-gallery-with-lightbox' === $gallery_block_type ? ',"ampLightbox":true' : '';

		// Inject Images Blocks into the Gallery Block.
		$gallery_block_html = sprintf( $gallery_block_html_sprintf, $lightbox_images_option, $images_blocks_html );

		return $gallery_block_html;
	}

	/**
	 * Generate paragraph block.
	 *
	 * @param array $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_paragraph( $section ) {
		$paragraph_content = $this->squarebracketselement_manipulator->get_shortcode_contents( $section, array( 'vc_column_text' ) );

		if ( '[vc_column_text][/vc_column_text]' === $section ) {
			return null;
		}

		if ( ! $paragraph_content ) {
			$this->log( self::SHORTCODES_LOGS, "Block paragraph not parsed: $section" );
			die();
		}

		$stripped_paragraph = nl2br(
			strip_tags(
				$paragraph_content,
				array(
					'strong',
					'h2',
					'b',
					'a',
					'i',
					'em',
					'li',
					'ol',
					'ul',
					'audio',
					'figure',
					'blockquote',
					'h1',
					'h3',
					'dt',
					'dd',
					'dl',
					'h4',
					'u',
					'iframe',
				)
			)
		);
		$stripped_paragraph = str_ireplace( array( '<b>', '</b>' ), array( '<br><b>', '</b><br>' ), $stripped_paragraph );
		$stripped_paragraph = str_ireplace( array( 'text-align: right;', 'color: #ffffff;', 'color: white !important;', 'color: white;' ), '', $stripped_paragraph );

		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $stripped_paragraph,
			'innerContent' => array( $stripped_paragraph ),
		);
	}

	/**
	 * Generate heading block.
	 *
	 * @param array $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_heading( $section ) {
		$heading_content = $this->squarebracketselement_manipulator->get_shortcode_contents( $section, array( 'vc_custom_heading' ) );

		if ( ! $heading_content ) {
			$this->log( self::SHORTCODES_LOGS, "Block heading not parsed: $section" );
			die();
		}

		$stripped_heading = '<h2>' . wp_strip_all_tags( $heading_content ) . '</h2>';

		return array(
			'blockName'    => 'core/heading',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $stripped_heading,
			'innerContent' => array( $stripped_heading ),
		);
	}

	/**
	 * Generate html block.
	 *
	 * @param string $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_html( $section ) {
		$html_content = $this->squarebracketselement_manipulator->get_shortcode_contents( $section, array( 'vc_raw_html' ) );

		if ( ! $html_content ) {
			$this->log( self::SHORTCODES_LOGS, "Block html not parsed: $section" );
			return false;
		}

		$raw_html = urldecode( base64_decode( $html_content ) );

		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $raw_html,
			'innerContent' => array( $raw_html ),
		);
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
