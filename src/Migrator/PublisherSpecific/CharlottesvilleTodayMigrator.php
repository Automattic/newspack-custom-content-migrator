<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;

/**
 * Custom migration scripts for CharlottesvilleToday.
 */
class CharlottesvilleTodayMigrator implements InterfaceMigrator {

	const LOG_MAPS_NOT_DONE_IDS = 'ct_MAPS_NOT_DONE.log';
	const LOG_SKIPPED = 'ct_skipped.log';
	const LOG_EXCERPT = 'ct_excerpt.log';
	const LOG_FEATIMG = 'ct_featimg.log';
	const LOG_RELATEDARTICLES = 'ct_relatedarticles.log';
	const LOG_AUDIOFILE = 'ct_audiofile.log';
	const LOG_PDF = 'ct_pdf.log';
	const LOG_PDF_NOTFOUND = 'ct_pdf_notfound.log';
	const LOG_WYSIWYG = 'ct_wysiwyg.log';
	const LOG_BOXES = 'ct_boxes.log';
	const LOG_LINK = 'ct_link.log';
	const LOG_IMAGE = 'ct_image.log';
	const LOG_IMAGE_NOT_FOUND = 'ct_image_notfound.log';
	const LOG_IMAGES = 'ct_images.log';
	const LOG_INFOGRAMEMBED = 'ct_infogramembed.log';
	const LOG_QUOTE = 'ct_quote.log';
	const LOG_VIDEO = 'ct_video.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

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
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator charlottesvilletoday-acf-migrate',
			[ $this, 'cmd_acf_migrate' ],
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_acf_migrate( $args, $assoc_args ) {
		global $wpdb;

		/**
		 * Here's analyzing Charlotteville's DB and searching for unique 'stripes_\d_*' component names.
		 *
		 * The 'stripes_\d_*' we're migrating here are:
		 *      post featured image
		 *      post excerpt
		 *      featured_image || featured_image_override
		 *      stripes_%_audio_file (just 'audio_file' abbrev. for the next fields
		 *      audio_title
		 *      file (BUT NOT %audio_file)
		 *      text - numbered list of boxes
		 *      credit (belongs to link or audio)
		 *      image
		 *      images
		 *      infogram_embed_id
		 *      link
		 *      pdf
		 *      person
		 *      person_title
		 *      title (BUT NOT person_title NOR audio_title)
		 *      quote
		 *      type -- "link" or "file"
		 *      video
		 *      wysiwyg
		 *      subtitle
		 *
		 * These three have just one ID, so we'll do them manually
		 *      heading -- 70846
		 *      mini_article_component -- 70846
		 *      preheading -- 70846
		 *
		 * We're skipping these 'stripes_\d_*' metas:
		 *      related_articles
		 *      related_articles_label
		 *      addtl_content
		 *      align_right
		 *      bg_image
		 *      blocks
		 *      blocks_0_meta_content
		 *      blocks_0_meta_content_0_heading
		 *      blocks_0_meta_content_1_data
		 *      blocks_0_meta_content_2_label
		 *      blocks_0_meta_content_3_data
		 *      blocks_1_meta_content
		 *      blocks_1_meta_content_0_heading
		 *      blocks_1_meta_content_1_data
		 *      blocks_1_meta_content_2_cta
		 *      blocks_1_meta_content_2_include_arrow
		 *      blocks_1_meta_content_2_text
		 *      blocks_2_meta_content
		 *      blocks_2_meta_content_0_heading
		 *      blocks_2_meta_content_1_data
		 *      blocks_2_meta_content_2_images
		 *      boxes
		 *      boxes_0_text
		 *      boxes_1_text
		 *      boxes_2_text
		 *      boxes_3_text
		 *      boxes_4_text
		 *      boxes_5_text
		 *      boxes_6_text
		 *      btn_nav_items
		 *      btn_nav_items_0_btn_nav_link
		 *      btn_nav_items_0_hed
		 *      btn_nav_items_0_snippet
		 *      btn_nav_items_1_btn_nav_link
		 *      btn_nav_items_1_hed
		 *      btn_nav_items_1_snippet
		 *      btn_nav_items_2_btn_nav_link
		 *      btn_nav_items_2_hed
		 *      btn_nav_items_2_snippet
		 *      btn_nav_items_3_btn_nav_link
		 *      content
		 *      content_0_label
		 *      cta
		 *      display_mode
		 *      donate-box
		 *      donate-box_background_image
		 *      donate-box_button_link
		 *      donate-box_community_partners
		 *      donate-box_heading
		 *      donate-box_snippet
		 *      donate-box_sponsor_label
		 *      donate-box_sponsor_link
		 *      donate-box_sponsors
		 *      first
		 *      hed
		 *      hide_image_caption
		 *      icon
		 *      include
		 *      label
		 *      latest_10
		 *      make_h2
		 *      map_0_options
		 *      map_0_options_label
		 *      map_0_options_marker
		 *      map_1_options
		 *      map_1_options_label
		 *      map_1_options_marker
		 *      map_options
		 *      map_options_label
		 *      map_options_marker
		 *      map_options_zoom
		 *      max_width
		 *      mentions
		 *      meta_content
		 *      meta_content_0_label
		 *      meta_content_1_text
		 *      meta_content_2_cta
		 *      meta_content_2_include_arrow
		 *      meta_content_2_text
		 *      meta_content_3_label
		 *      meta_content_4_text
		 *      meta_content_5_cta
		 *      meta_content_5_include_arrow
		 *      meta_content_5_text
		 *      meta_content_6_label
		 *      meta_content_7_data
		 *      meta_content_8_text
		 *      person_type
		 *      second
		 *      vc_fields
		 *      vc_fields_video_caption
		 *      vc_fields_video_url
		 */

		// Example IDs which contain and test all the migrated ACF fields.
		$dev_examples__post_ids = [
			// wysiwyg, audio_file
			69143,
			// file, related_articles, images
			73049,
			// boxes
			74915,
			// link
			74317,
			// image
			49595,
			// images
			68990,
			// infogram_embed_id
			70309,
			// pdf
			74287,
			// quote person person_title
			49595,
			// video
			76074
		];

		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			\WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post = get_post( $post_id );

			// Import ACF excerpt.
			$acf_excerpt_meta_value = $wpdb->get_var( $wpdb->prepare( "select meta_value from $wpdb->postmeta where post_id = %d and meta_key like 'excerpt' ; ", $post_id ) );
			if ( $acf_excerpt_meta_value && ! empty( $acf_excerpt_meta_value ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $acf_excerpt_meta_value ], [ 'ID' => $post_id ] );
				$this->log( self::LOG_EXCERPT, $post_id );
			}

			// Import ACF featured image or override.
			$acf_feat_img_meta_value = $wpdb->get_var( $wpdb->prepare( "select meta_value from $wpdb->postmeta where post_id = %d and meta_key = 'featured_image' ; ", $post_id ) );
			$acf_feat_img_override_meta_value = $wpdb->get_var( $wpdb->prepare( "select meta_value from $wpdb->postmeta where post_id = %d and meta_key = 'featured_image_override' ; ", $post_id ) );
			$featured_image_id = $acf_feat_img_meta_value ?? $acf_feat_img_override_meta_value;
			if ( ! is_null( $featured_image_id ) ) {
				$is_set = set_post_thumbnail( $post_id, $featured_image_id );
				$this->log( self::LOG_FEATIMG, $post_id );
			}

			$this->set_acf_categories( $post );

			// Will update Post's Featured Image and Excerpt even if there's no post_content.
			if ( ! empty( $post->post_content ) ) {
				\WP_CLI::line( "Skipping." );
				$this->log( self::LOG_SKIPPED, $post_id );
				continue;
			}

			// Import ACF "stripes_%" meta, which contains various types of content.
			$stripes_meta_rows = $wpdb->get_results( $wpdb->prepare( "select * from $wpdb->postmeta where post_id = %d and meta_key like 'stripes_%' ; ", $post_id ), ARRAY_A );

			// Put these in an array which groups all meta fields by their index "stripes_(\d+).+"
			$stripes_metas = [];
			foreach ( $stripes_meta_rows as $stripes_meta_row ) {
				preg_match( '/stripes_(\d+)_(.+)/', $stripes_meta_row[ 'meta_key' ], $matches );
				if ( empty( $matches ) ) {
					continue;
				}

				$stripe_index = $matches[1];
				$stripe_name = $matches[2];

				$stripes_metas[ $stripe_index ][ $stripe_name ] = $stripes_meta_row[ 'meta_value' ];
			}

			// Sort by meta_key.
			ksort( $stripes_metas );

			// Convert $stripes_metas to actual HTML content.
			$post_content = '';
			foreach ( $stripes_metas as $key_stripes_meta => $stripes_meta ) {
				$pre_linebreak = ! empty( $post_content ) ? "\n\n" : '';

				if ( isset( $stripes_meta['map'] ) || isset( $stripes_meta['zoom'] ) ) {
					// Just log these to be done manually.
					$this->log( self::LOG_MAPS_NOT_DONE_IDS, $post_id );
				} else if ( isset( $stripes_meta['related_articles'] ) ) {
					$label = $stripes_meta['related_articles_label'] ?? null;
					$related_post_ids = unserialize( $stripes_meta['related_articles'] ) ?? [];

					if ( ! empty( $related_post_ids ) ) {
						// Defaults for $label.
						if ( empty( $label ) ) {
							$label = 'Related articles';
						}
						if ( ! empty( $label ) && ! str_ends_with( $label , ':' ) ) {
							$label .= ':';
						}

						$post_content .= $pre_linebreak . $this->render_related_articles( $label, $related_post_ids );

						$this->log( self::LOG_RELATEDARTICLES, $post_id );
					}
				} else if ( isset( $stripes_meta['audio_file'] ) ) {
					$audio_attachment_id = $stripes_meta['audio_file'];
					$audio_title = $stripes_meta['audio_title'] ?? '';
					$audio_credit = $stripes_meta['credit'] ?? '';

					$caption = $audio_title
					           . ( ! empty( $audio_title ) && ! empty( $audio_credit ) ? '. ' : '' )
							   . 'Credit: ' . $audio_credit;
					$post_content .= $pre_linebreak . $this->render_audio_block( $audio_attachment_id, $caption );

					$this->log( self::LOG_AUDIOFILE, $post_id );
				} else if ( isset( $stripes_meta['file'] ) ) {
					// All 'file's in Charlotteville's ACF are PDFs.
					$file_attachment_id = $stripes_meta['file'];
					$credit = $stripes_meta['credit'] ?? '';
					$title = $stripes_meta['title'] ?? '';

					$caption = $title
					           . ( ! empty( $title ) && ! empty( $credit ) ? '. ' : '' )
					           . 'Credit: ' . $credit;
					$post_content .= $pre_linebreak . $this->render_pdf_block( $file_attachment_id, $caption );

					$this->log( self::LOG_PDF, $post_id );
				} else if ( isset( $stripes_meta['pdf'] ) ) {
					// PDFs.
					$file_attachment_id = $stripes_meta['pdf'];
					$attachment = get_post( $file_attachment_id );
					if ( ! $attachment ) {
						$this->log( self::LOG_PDF_NOTFOUND, $post_id );
						continue;
					}
					$caption = wp_get_attachment_caption( $file_attachment_id );
					// 'Description' attachment field is used as Credit on Charlottesville Today.
					$credit = $attachment->post_content;

					// Let's append "Credit" to Caption.
					if ( ! empty( $credit ) ) {
						$caption .= ( ! empty( $caption ) ? '. ' : '' ) . 'Credit: ' . $credit;
					}

					$post_content .= $pre_linebreak . $this->render_pdf_block( $file_attachment_id, $caption );

					$this->log( self::LOG_PDF, $post_id );
				} else if ( isset( $stripes_meta['subtitle'] ) ) {
					$subtitle = $stripes_meta['subtitle'];

					$post_content .= $pre_linebreak . $this->render_h3( $subtitle );
				} else if ( isset( $stripes_meta['wysiwyg'] ) ) {
					// WYSIWYG text content.
					$post_content .= $pre_linebreak . $stripes_meta['wysiwyg'];

					$this->log( self::LOG_WYSIWYG, $post_id );
				} else if ( isset( $stripes_meta['boxes'] ) ) {
					// Numerated list.
					unset( $stripes_meta['boxes'] );
					$list_lines = [];
					foreach ( $stripes_meta as $key_box => $box ) {
						if ( str_ends_with( $key_box, '_text' ) ) {
							$list_lines[] = $box;
						}
					}

					if ( ! empty( $list_lines ) ) {
						$post_content .= $pre_linebreak . $this->render_numerated_list( $list_lines );

						$this->log( self::LOG_BOXES, $post_id );
					}
				} else if ( isset( $stripes_meta['type'] ) && 'link' == $stripes_meta['type'] ) {
					// A link consisting of title, caption and possibly an image.
					$credit = $stripes_meta['credit'] ?? null;
					if ( ! is_null( $credit ) ) {
						$credit = 'Credit: ' . $credit;
					}
					$title = $stripes_meta['title'] ?? null;
					$image_attachment_id = 'custom' == $stripes_meta['icon'] && isset( $stripes_meta['image'] ) ? $stripes_meta['image'] : null;
					$unserialized_data = isset( $stripes_meta['link'] ) ? unserialize( $stripes_meta['link'] ) : null;
					$url = null;
					if ( is_array( $unserialized_data ) && ! empty( $unserialized_data ) ) {
						$title = $unserialized_data['title'];
						$url = $unserialized_data['url'];
					}

					if ( ! is_null( $url ) ) {
						$post_content .= $pre_linebreak . $this->render_link_w_possible_image( $url, $title, $credit, $image_attachment_id );

						$this->log( self::LOG_LINK, $post_id );
					}
				} else if ( isset( $stripes_meta['image'] ) ) {
					// Image.
					$image_attachment_id = $stripes_meta['image'];
					$image = get_post( $image_attachment_id );
					if ( ! $image ) {
						$this->log( self::LOG_IMAGE_NOT_FOUND, $post_id );
						continue;
					}

					$caption = wp_get_attachment_caption( $image_attachment_id );
					$description = $wpdb->get_var( $wpdb->prepare( "select post_content from $wpdb->posts where ID = %d ;", $image_attachment_id ) );
					// 'Description' attachment field is used as Credit on Charlottesville Today.
					$credit = $description;

					// Let's append "Credit" to image Caption.
					if ( ! empty( $credit ) ) {
						$caption .= ( ! empty( $caption ) ? '. ' : '' ) . 'Credit: ' . $credit;
					}

					$post_content .= $pre_linebreak . $this->render_image( $image_attachment_id, $caption );

					$this->log( self::LOG_IMAGE, $post_id );
				} else if ( isset( $stripes_meta['images'] ) ) {
					$image_ids = unserialize( $stripes_meta['images'] );

					$post_content .= $pre_linebreak . $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $image_ids );

					$this->log( self::LOG_IMAGES, $post_id );
				} else if ( isset( $stripes_meta['infogram_embed_id'] ) ) {
					$infogram_embed_id = $stripes_meta['infogram_embed_id'];
					$src = "https://e.infogram.com/$infogram_embed_id";

					$post_content .= $pre_linebreak . $this->render_iframe( $src );

					$this->log( self::LOG_INFOGRAMEMBED, $post_id );
				} else if ( isset( $stripes_meta['quote'] ) ) {
					$quote = $stripes_meta['quote'];
					$person = $stripes_meta['person'] ?? null;
					$person_title = $stripes_meta['person_title'] ?? null;
					if ( ! is_null( $person_title ) ) {
						$person_title = trim( $person_title, ' ' );
					}

					$post_content .= $pre_linebreak . $this->render_person_quote( $quote, $person, $person_title );

					$this->log( self::LOG_QUOTE, $post_id );
				} else if ( isset( $stripes_meta['video'] ) ) {
					$video_src = $stripes_meta['video'];

					if ( str_contains( $video_src, 'youtu.be/' ) || str_contains( $video_src, 'youtube.com/' ) ) {
						$post_content .= $pre_linebreak . $this->render_youtube_block_video( $video_src );

						$this->log( self::LOG_VIDEO, $post_id );
					} else if ( str_contains( $video_src, 'vimeo.com/' ) ) {
						$post_content .= $pre_linebreak . $this->render_vimeo_block_video( $video_src );

						$this->log( self::LOG_VIDEO, $post_id );
					}
				}
			}

			if ( ! empty( $post_content ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content ], [ 'ID' => $post_id ] );
			}
		}

		echo "Done. Follow ups:\n";
		echo "1. search-replace '/articles/' with '/'\n";
		echo "2. do these 3 fields manually:\n"
			 . " - heading -- 70846\n"
		     . " - mini_article_component -- 70846\n"
		     . " - preheading -- 70846";
		echo "3. give the publisher a list of IDs for maps and zoom to do those manually ". self::LOG_MAPS_NOT_DONE_IDS . "\n";
		echo "4. these IDs might have a featured video: 70846,73345,76719\n";

		// For $wpdb->update to sink in.
		wp_cache_flush();
	}

	public function set_acf_categories( $post ){
		global $wpdb;

		$acf_topics_meta = $wpdb->get_var( $wpdb->prepare( "select meta_value from $wpdb->postmeta where post_id = %d and meta_key = 'article_topics' ; ", $post->ID ) );
		if ( ! $acf_topics_meta || empty( $acf_topics_meta ) ) {
			return;
		}

		$term_ids = unserialize( $acf_topics_meta );
		if ( empty( $term_ids ) ) {
			return;
		}

		// Transform ACF Topics to Cats and assign them to Posts.
		foreach ( $term_ids as $term_id ) {
			// Can't use get_term -- WP Error "Invalid taxonomy.".
			$term_name = $wpdb->get_var( $wpdb->prepare( "select name from wp_terms where term_id = %d ; ", $term_id ) );
			$category_id = wp_create_category( $term_name );
			$cat_set = wp_set_post_categories( $post->ID, [ $category_id ], true );
		}

		// Remove Uncategorized.
		wp_remove_object_terms( $post->ID, 'uncategorized', 'category' );
	}

	public function render_audio_block( $attachment_id, $caption ) {
		$url = get_attachment_link( $attachment_id );
		return <<<BLOCK
<!-- wp:audio {"id":$attachment_id} -->
<figure class="wp-block-audio"><audio controls src="$url"></audio><figcaption>$caption</figcaption></figure>
<!-- /wp:audio -->
BLOCK;
	}

	public function render_pdf_block( $attachment_id, $caption ) {
		$url = get_attachment_link( $attachment_id );
		$post = get_post( $attachment_id );
		$title = $post->post_title;

		return <<<BLOCK
<!-- wp:file {"id":$attachment_id,"href":"$url","displayPreview":true} -->
<div class="wp-block-file"><object class="wp-block-file__embed" data="$url" type="application/pdf" style="width:100%;height:600px" aria-label="Embed of $title."></object><a id="wp-block-file--media-09a92531-4615-429f-bb96-839afc72b2d3" href="$url">$caption</a><a href="$url" class="wp-block-file__button" download aria-describedby="wp-block-file--media-09a92531-4615-429f-bb96-839afc72b2d3">Download</a></div>
<!-- /wp:file -->
BLOCK;
	}

	public function render_numerated_list( $lines ) {
		$li_separated_html = implode( '</li><li>', $lines );
		return <<<BLOCK
<!-- wp:list {"ordered":true} -->
<ol><li>$li_separated_html</li></ol>
<!-- /wp:list -->
BLOCK;
	}

	public function render_link_w_possible_image( $url, $title, $credit, $image_attachment_id ) {
		$block = '';

		if ( $image_attachment_id ) {
			$image_url = get_attachment_link( $image_attachment_id );
			$block .= <<<BLOCK
<!-- wp:image {"sizeSlug":"large","linkDestination":"custom"} -->
<figure class="wp-block-image size-large"><a href="$url"><img src="$image_url" alt=""/></a></figure>
<!-- /wp:image -->
BLOCK;
		}

		$block .= ( ! empty( $block ) ? "\n\n" : '') . <<<BLOCK
<!-- wp:paragraph -->
<p><a href="$url">$title</a></p>
<!-- /wp:paragraph -->
BLOCK;

		if ( $credit ) {
		$block .= ( ! empty( $block ) ? "\n\n" : '') . <<<BLOCK
<!-- wp:paragraph -->
<p>$credit</p>
<!-- /wp:paragraph -->
BLOCK;
		}

		return $block;
	}

	public function render_image( $attachment_id, $caption ) {
		$url = get_attachment_link( $attachment_id );
		return <<<BLOCK
<!-- wp:image {"id":$attachment_id,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="$url" class="wp-image-$attachment_id"/><figcaption>$caption</figcaption></figure>
<!-- /wp:image -->
BLOCK;
	}

	public function render_iframe( $src ) {
		return <<<BLOCK
<!-- wp:newspack-blocks/iframe {"src":"$src"} /-->
BLOCK;
	}

	public function render_person_quote( $quote, $person, $person_title ) {
		$cite = $person
		        . ( ! empty( $person ) && ! empty( $person_title ) ? ', ' : '' )
		        . $person_title;
		return <<<BLOCK
<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>$quote</p><cite>$cite</cite></blockquote>
<!-- /wp:quote -->
BLOCK;
	}

	public function render_youtube_block_video( $src ) {
		return <<<BLOCK
<!-- wp:embed {"url":"$src","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
$src
</div></figure>
<!-- /wp:embed -->
BLOCK;
	}

	public function render_vimeo_block_video( $src ) {
		return <<<BLOCK
<!-- wp:embed {"url":"$src","type":"video","providerNameSlug":"vimeo","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
$src
</div></figure>
<!-- /wp:embed -->
BLOCK;
	}

	public function render_h3( $text ) {
		return <<<BLOCK
<!-- wp:heading {"level":3} -->
<h3>$text</h3>
<!-- /wp:heading -->
BLOCK;
	}

	public function render_related_articles( $label, $related_post_ids ) {
		$a_elements = [];
		foreach ( $related_post_ids as $related_post_id ) {
			$related_post = get_post( $related_post_id );
			$href = get_permalink( $related_post_id );
			$title = $related_post->post_title;
			$a_elements[] = sprintf ( '<a href="%s">%s</a>', $href, $title );
		}

		$li_separated_a_elements = implode( '</li><li>', $a_elements );

		return <<<BLOCK
<!-- wp:paragraph -->
<p>$label</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul><li>$li_separated_a_elements</li></ul>
<!-- /wp:list -->
BLOCK;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
