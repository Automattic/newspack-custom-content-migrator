<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackPostImageDownloader\Downloader;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;

class NextgenGalleryMigrator implements InterfaceMigrator {

	// Mtas saved to imported images:
	// - original NGG picture ID;
	const META_NGG_PICTUREID = '_newspack_ngg_pictureid';
	// - which gallery ID this image belonged to;
	const META_NGG_PICTURE_GALLERYID = '_newspack_ngg_picture_galleryid';
	// - sort order for this image in belonging NGG Gallery;
	const META_NGG_PICTURE_SORTORDER = '_newspack_ngg_picture_sortorder';
	// Supported Gallery blocks.
	const GUTENBERG_GALLERY_TYPES = array( 'gutenberg-gallery', 'gutenberg-gallery-with-lightbox' );
	const JETPACK_GALLERY_TYPES   = array( 'jetpack-gallery' );

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Downloader.
	 */
	private $downloader;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var WpBlockManipulator.
	 */
	private $wpblock_manipulator;

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * @var array SELECT * FROM `wp_ngg_gallery` table in ARRAY_A format.
	 */
	private $galleries_rows;

	/**
	 * NextgenGalleryMigrator constructor.
	 */
	private function __construct() {
		$this->downloader                        = new Downloader();
		$this->posts_logic                       = new PostsLogic();
		$this->wpblock_manipulator               = new WpBlockManipulator();
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
	}

	/**
	 * Sets up Co-Authors Plus plugin dependencies.
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
			'newspack-content-migrator nextgen-gallery-to-gutenberg-gallery-blocks',
			array( $this, 'cmd_nextgen_galleries_to_gutenberg_gallery_blocks' ),
			array(
				'shortdesc' => 'Import NextGen images to Media Library, and converts NextGen Galleries throughout all Posts and Pages to Gutenberg Gallery blocks. Before running this command, make sure NextGen DB tables are present locally, and that the gallery folder is found at the configured location and contains all the NextGen images and has correct file permissions.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'gallery-block-type',
						'description' => 'Gallery block type to be used as a replacement for the Next Gen Gallery shortcode. Accepted values are gutenberg-gallery, jetpack-gallery. Defaults to jetpack-gallery.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'jetpack-gallery',
						'options'     => array_merge( self::GUTENBERG_GALLERY_TYPES, self::JETPACK_GALLERY_TYPES ),
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator nextgen-gallery-to-gutenberg-gallery-blocks`.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_nextgen_galleries_to_gutenberg_gallery_blocks( $args, $assoc_args ) {
		global $wpdb;

		// Make sure NGG DB tables are available.
		$this->validate_db_tables_exist( array( $wpdb->prefix . 'ngg_album', $wpdb->prefix . 'ngg_gallery', $wpdb->prefix . 'ngg_pictures' ) );

		$ngg_options          = get_option( 'ngg_options' );
		$this->galleries_rows = $wpdb->get_results( " select * from {$wpdb->prefix}ngg_gallery ; ", ARRAY_A );

		echo sprintf( "CHECKING NGG IMAGE FILES...\n" );
		$images_rows       = $wpdb->get_results( " select * from {$wpdb->prefix}ngg_pictures ; ", ARRAY_A );
		$missing_pid_files = array();
		foreach ( $images_rows as $key_image_row => $image_row ) {
			$gallery_row = $this->get_gallery_row_by_gid( $image_row['galleryid'] );
			// If the NGG shortcode is escaped on the post content, the gallery images are set inside folders and the gallery path doesn't have a DS in the end.
			$image_file_full_path = preg_replace( '/\/wp-content$/', '', WP_CONTENT_DIR ) . '/' . trim( $gallery_row['path'], '/' ) . '/' . $image_row['filename'];

			if ( ! file_exists( $image_file_full_path ) ) {
				$missing_pid_files[] = $image_row['pid'];
			}
		}
		if ( ! empty( $missing_pid_files ) ) {
			\WP_CLI::confirm( sprintf( 'Not found %d image files from total %d. Do you still want to continue importing available images, and converting NGG galleries to Gutenberg Galleries with just these available images?', count( $missing_pid_files ), count( $images_rows ) ) );
		}

		echo sprintf( "IMPORTING NGG IMAGES TO MEDIA LIBRARY...\n" );
		foreach ( $images_rows as $key_image_row => $image_row ) {
			echo sprintf( "%d/%d importing image `pid` %d %s\n", $key_image_row + 1, count( $images_rows ), $image_row['pid'], $image_row['filename'] );

			$gallery_row = $this->get_gallery_row_by_gid( $image_row['galleryid'] );
			if ( is_null( $gallery_row ) ) {
				echo sprintf( "ERROR not found gallery `gid` for image `pid` %d %s\n", $image_row['pid'] );
				continue;
			}
			$att_id = $this->import_ngg_image_to_media_library( $image_row, $gallery_row );

			if ( is_wp_error( $att_id ) ) {
				echo sprintf( "%s\n", $att_id->get_error_message() );
				continue;
			}

			echo sprintf( "imported att_id %d\n", $att_id );
		}

		echo sprintf( "\nCONVERTING NEXTGEN GALLERIES TO %s GALLERY BLOCKS...\n", 'gutenberg-gallery' === $assoc_args['gallery-block-type'] ? 'GUTENBERG' : 'JETPACK' );
		$posts_ids = $this->posts_logic->get_all_posts_ids( $post_type = array( 'post', 'page' ) );
		foreach ( $posts_ids as $key_post_id => $post_id ) {
			echo sprintf( "%d/%d post ID %s\n", $key_post_id + 1, count( $posts_ids ), $post_id );
			$post    = get_post( $post_id );
			$updated = $this->convert_ngg_galleries_in_post_to_block_gallery( $post, $assoc_args['gallery-block-type'] );
			if ( is_wp_error( $updated ) ) {
				echo sprintf( "%s\n", $updated->get_error_message() );
				continue;
			} elseif ( true === $updated ) {
				echo sprintf( "converted to Gutenberg Gallery\n" );
			}
		}

		echo sprintf( "\nDONE. Next:\n- clean up and delete all images from the NGG gallery path %s\n", $ngg_options['gallerypath'] );
		wp_cache_flush();
	}

	/**
	 * Imports a single NGG image to the Media Library.
	 *
	 * @param array $image_row   Row from `ngg_pictures` table.
	 * @param array $gallery_row Row from `ngg_gallery` table
	 *
	 * @return int|\WP_Error Attachment ID or \WP_Error.
	 */
	public function import_ngg_image_to_media_library( $image_row, $gallery_row ) {
		global $wpdb;

		// Check if img was already imported.
		$att_post_id = $wpdb->get_var( $wpdb->prepare( "select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s ;", self::META_NGG_PICTUREID, $image_row['pid'] ) );
		if ( ! is_null( $att_post_id ) ) {
			return new \WP_Error( 200, sprintf( "skipping, ngg_image pid %d already imported as att ID %s\n", $image_row['pid'], $att_post_id ) );
		}

		// Img info.
		$filename    = $image_row['filename'];
		$description = $image_row['description'];
		$alt         = $image_row['alttext'];

		// Gallery and path info.
		$atomic_compatible_public_folder_path = preg_replace( '/\/wp-content$/', '', WP_CONTENT_DIR );
		$image_path                           = $atomic_compatible_public_folder_path . '/' . trim( $gallery_row['path'] ) . '/';
		$image_file_full_path                 = $image_path . $filename;

		// Check if file exists.
		if ( ! file_exists( $image_file_full_path ) ) {
			return new \WP_Error( 100, sprintf( "ERROR ngg_image pid %d not found in %s\n", $image_row['pid'], $image_file_full_path ) );
		}

		// Import to Media Library.
		$att_id = $this->downloader->import_external_file(
			$image_file_full_path,
			$filename,
			$description,
			null,
			$alt
		);
		if ( is_wp_error( $att_id ) ) {
			return new \WP_Error( 101, sprintf( "ERROR importing image %s : %s\n", $image_file_full_path, $att_id->get_error_message() ) );
		}

		// Save meta referencing these Attachments as NGG pictures.
		add_post_meta( $att_id, self::META_NGG_PICTUREID, $image_row['pid'] );
		add_post_meta( $att_id, self::META_NGG_PICTURE_GALLERYID, $image_row['galleryid'] );
		add_post_meta( $att_id, self::META_NGG_PICTURE_SORTORDER, $image_row['sortorder'] );

		return $att_id;
	}

	/**
	 * Updates all known NGG gallery syntax in this post shortcodes, blocks, etc) to Gutenberg Gallery blocks.
	 * Expects all the images were already imported using $this->import_ngg_images_to_media_library().
	 *
	 * @param array  $ngg_options NGG Options value.
	 * @param string $gallery_block_type Block type to use as the new gallery (gutenberg-block, jetpack-block).
	 *
	 * @return bool|\WP_Error
	 */
	public function convert_ngg_galleries_in_post_to_block_gallery( $post, $gallery_block_type ) {
		global $wpdb;

		// Convert NGG Gallery shortcode to pure Gutenberg or Jetpack Gallery blocks.
		$post_content_updated = $this->convert_ngg_gallery_shortcode_to_gallery_blocks( $post->post_content, $gallery_block_type );
		// Convert NGG Gallery blocks to pure Gutenberg Gallery blocks.
		$post_content_updated = $this->convert_ngg_gallery_blocks_to_gutenberg_gallery_blocks( $post_content_updated, $gallery_block_type );

		// Persist updates.
		if ( $post->post_content !== $post_content_updated ) {
			$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
			if ( $updated != 1 || false === $updated ) {
				return new \WP_Error( 103, sprintf( 'ERR could not update post ID %d', $post->ID ) );
			}

			return ( $updated > 0 ) && ( false !== $updated );
		}

		return false;
	}

	/**
	 * Checks if DB tables exist locally.
	 *
	 * @param array $tables Tables to check.
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
	private function validate_db_tables_exist( $tables ) {
		global $wpdb;

		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'select * from information_schema.tables where table_schema = %s AND table_name = %s limit 1;', DB_NAME, $table ), ARRAY_A );
			if ( is_null( $row ) || empty( $row ) ) {
				throw new \Exception( sprintf( 'NGG table %s not found in DB.', $table ) );
			}
		}

		return true;
	}

	/**
	 * Transforms NextGenGallery blocks to Gutenberg Gallery Block, e.g.:
	 *
	 *      <!-- wp:imagely/nextgen-gallery -->
	 *      [ngg src="galleries" ids="3" display="pro_mosaic" captions_display_title="1" is_ecommerce_enabled="1"]
	 *      <!-- /wp:imagely/nextgen-gallery -->
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $post_content HTML.
	 *
	 * @return string|\WP_Error Refreshed post_content with Gutenberg Gallery blocks instead of NGG, or \WP_Error on exceptions.
	 */
	public function convert_ngg_gallery_blocks_to_gutenberg_gallery_blocks( $post_content, $gallery_block_type ) {
		$post_content_updated = $post_content;

		// Match the NGG Gallery block.
		$matches_blocks = $this->wpblock_manipulator->match_wp_block( 'wp:imagely/nextgen-gallery', $post_content );
		if ( is_null( $matches_blocks ) ) {
			return $post_content_updated;
		}

		foreach ( $matches_blocks[0] as $match_block ) {
			$block_html = $match_block[0];

			// Match the shortcode inside the NGG Gallery block.
			$matches_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'ngg', $block_html );
			if ( empty( $matches_shortcodes[0] ) ) {
				return $post_content_updated;
			}

			$att_ids = array();
			foreach ( $matches_shortcodes as $match_shortcode ) {
				$shortcode_html = $match_shortcode[0];
				$shortcode_src  = $this->squarebracketselement_manipulator->get_attribute_value( 'src', $shortcode_html );
				if ( 'galleries' != $shortcode_src ) {
					continue;
				}

				$gallery_ids = $this->squarebracketselement_manipulator->get_attribute_value( 'ids', $shortcode_html );
				$gallery_ids = explode( ',', $gallery_ids );
				foreach ( $gallery_ids as $gallery_id ) {
					$att_ids = array_merge( $att_ids, $this->get_attachment_ids_in_ngg_gallery( $gallery_id ) );
				}
			}

			if ( empty( $att_ids ) ) {
				return $post_content_updated;
			}

			// Replace NGG gallery block with Gutenberg or Jetpack Gallery block.
			$gutenberg_gallery_block_html = $this->posts_logic->generate_gutenberg_block_content_from_ngg_images( $gallery_block_type, $att_ids );
			if ( $gutenberg_gallery_block_html ) {
				$post_content_updated = str_replace( $block_html, $gutenberg_gallery_block_html, $post_content_updated );
			}
		}

		return $post_content_updated;
	}

	/**
	 * Transforms NextGenGallery shortcode to Gutenberg or Jetpack Gallery Block, e.g.:
	 *
	 *      [nggallery id=3392]
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $post_content HTML.
	 * @param string $gallery_block_type Block type to use as the new gallery (gutenberg-block, jetpack-block).
	 *
	 * @return string|\WP_Error Refreshed post_content with Gutenberg Gallery blocks instead of NGG, or \WP_Error on exceptions.
	 */
	public function convert_ngg_gallery_shortcode_to_gallery_blocks( $post_content, $gallery_block_type ) {
		$post_content_updated = $post_content;

		// Match the shortcode inside the NGG Gallery block.
		$matches_shortcodes = $this->get_ngg_shortcode_from_content( $post_content );
		if ( empty( $matches_shortcodes[0] ) ) {
			return $post_content_updated;
		}

		foreach ( $matches_shortcodes as $match_shortcode ) {
			$shortcode_html = $match_shortcode[0];

			$gallery_id = $this->get_gallery_id_from_shortcode( $shortcode_html );

			if ( $gallery_id ) {
				$att_ids = $this->get_attachment_ids_in_ngg_gallery( $gallery_id );

				if ( empty( $att_ids ) ) {
					return $post_content_updated;
				}

				// Replace NGG gallery block with Gutenberg or Jetpack Gallery block.
				$gutenberg_gallery_block_html = $this->generate_gutenberg_block_content_from_ngg_images( $gallery_block_type, $att_ids );
				if ( $gutenberg_gallery_block_html ) {
					$post_content_updated = str_replace( $shortcode_html, $gutenberg_gallery_block_html, $post_content_updated );
				}
			}
		}
		return $post_content_updated;
	}

	/**
	 * Generate Gutenberg block content from NextGen Gallery images ids.
	 *
	 * @param string $gallery_block_type Block type to generate.
	 * @param int[]  $images_ids Images IDs used to generate the gallery block.
	 * @return string|false returns the generated block content, or false if the block type is not supported.
	 */
	public function generate_gutenberg_block_content_from_ngg_images( $gallery_block_type, $images_ids ) {
		if ( in_array( $gallery_block_type, self::GUTENBERG_GALLERY_TYPES, true ) ) {
			return $this->get_gutenberg_gallery_block_html( $gallery_block_type, $images_ids );
		} elseif ( in_array( $gallery_block_type, self::JETPACK_GALLERY_TYPES, true ) ) {
			return $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $images_ids );
		}

		WP_CLI::warning( sprintf( "The block type '%s' is not supported.", $gallery_block_type ) );
		return false;
	}

	/**
	 * Gets images from the Media library which belong to the NGG gallery.
	 * Expects all the images were already imported using $this->import_ngg_images_to_media_library().
	 *
	 * @param int $gallery_id NGG Gallery ID.
	 *
	 * @return array Attachment IDs from Media Library which belong to the NGG $gallery_id.
	 */
	public function get_attachment_ids_in_ngg_gallery( $gallery_id ) {
		global $wpdb;

		// Get images from Media Library belonging to this NGG gallery; order needs casting to number, because otherwise sorts as strings.
		$att_ids   = array();
		$atts_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select wpm.post_id, wpm_order.meta_value as sorting_order
				from {$wpdb->prefix}postmeta wpm
				left join {$wpdb->prefix}postmeta wpm_order
				on wpm_order.post_id = wpm.post_id and wpm_order.meta_key = %s
				where wpm.meta_key = %s
				and wpm.meta_value = %s
				order by cast(sorting_order as unsigned) ; ",
				self::META_NGG_PICTURE_SORTORDER,
				self::META_NGG_PICTURE_GALLERYID,
				$gallery_id
			),
			ARRAY_A
		);
		foreach ( $atts_rows as $att_row ) {
			$att_ids[] = $att_row['post_id'];
		}

		return $att_ids;
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
	 * Returns a `wp_ngg_gallery` row with Gallery ID.
	 *
	 * @param int $gid Gallery ID.
	 *
	 * @return array|null
	 */
	private function get_gallery_row_by_gid( $gid ) {
		foreach ( $this->galleries_rows as $key_gallery_row => $gallery_row ) {
			if ( $gid == $gallery_row['gid'] ) {
				return $this->galleries_rows[ $key_gallery_row ];
			}
		}

		return null;
	}

	/**
	 * Return NGG shorcode from the post content.
	 *
	 * @param string $post_content Post content from where we want to extract the NGG shortcode.
	 * @return string[][]
	 */
	private function get_ngg_shortcode_from_content( $post_content ) {
		$possible_shortcode_tags = array( 'nggallery', 'ngg' );
		foreach ( $possible_shortcode_tags as $shortcode_tag ) {
			$matches_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( $shortcode_tag, $post_content );
			if ( ! empty( $matches_shortcodes[0] ) ) {
				return $matches_shortcodes;
			}
		}

		return array();
	}

	/**
	 * Get the gallery ID from the NGG shortcode, returns false if not found.
	 *
	 * @param string $shortcode_html NGG shorcode content.
	 * @return int|false
	 */
	private function get_gallery_id_from_shortcode( $shortcode_html ) {
		// e.g.: [nggallery id=1].
		preg_match( '/nggallery.*id=(?<gallery_id>\d+)/', $shortcode_html, $gallery_id_match );
		if ( array_key_exists( 'gallery_id', $gallery_id_match ) ) {
			return $gallery_id_match['gallery_id'];
		}

		// e.g.: [ngg src="galleries" ids="1" display="basic_slideshow" autoplay="0" arrows="1"].
		$parsed_shortcode = shortcode_parse_atts( $shortcode_html );
		if ( array_key_exists( 'ids', $parsed_shortcode ) ) {
			return $parsed_shortcode['ids'];
		}

		return false;
	}
}
