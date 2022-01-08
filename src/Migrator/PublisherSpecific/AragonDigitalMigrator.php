<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use WP_CLI;
use WP_Post;

class AragonDigitalMigrator implements InterfaceMigrator {
	const GENERAL_LOG = 'tipi_content_converter.log';

	/**
	 * AragonDigitalMigrator Singleton.
	 *
	 * @var AragonDigitalMigrator $instance
	 */
	private static $instance;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get Instance.
	 *
	 * @return AragonDigitalMigrator
	 */
	public static function get_instance() {
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
			'newspack-content-migrator convert-aragon-digital-posts',
			array( $this, 'handle_post_conversion' ),
			array(
				'shortdesc' => 'Will handle Tipi Builder posts conversion to blocks',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'skip-converted',
						'description' => 'Skip posts already converted.',
						'optional'    => true,
						'default'     => false,
					],
				],
			),
		);
	}

	/**
	 * Custom data conversion handler.
	 */
	public function handle_post_conversion( $args, $assoc_args ) {
		global $wpdb;
		$skip_validated = array_key_exists( 'skip-converted', $assoc_args ) && $assoc_args['skip-converted'];

		$posts = get_posts(
			[
				'posts_per_page' => -1,
				'meta_key'       => 'tipi_builder_data',
				'post_type'      => 'page',
			]
		);

		// filter validated posts if necessary.
		if ( $skip_validated ) {
			$posts = array_values(
				array_filter(
					$posts,
					function( $post ) {
						return 1 != get_post_meta( $post->ID, 'tipi-content-converted', true );
					}
				)
			);
		}

		$total_posts = count( $posts );

		/** @var WP_Post $post */
		foreach ( $posts as $i => $post ) {
			WP_CLI::line( "Converting post: {$post->post_title} (#{$post->ID}) " . sprintf( '%d/%d', $i + 1, $total_posts ) );

			$data = get_post_meta( $post->ID, 'tipi_builder_data', true );

			if ( $data ) {
				$sections = json_decode( $data, true );

				$updated_content = '';
				foreach ( $sections as $index => $section ) {
					$cleaned_section  = $this->clean_section( $section );
					$processed_blocks = $this->process_tipi_section( $index, $cleaned_section );


					if ( $processed_blocks ) {
						foreach ( $processed_blocks as $processed_block ) {
							$updated_content .= serialize_block( $processed_block );
						}
					} else {
						WP_CLI::warning( "Section $index skipped" );
					}
				}

				// update post content.
				if ( ! empty( $updated_content ) ) {
					$result = $wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $updated_content ], [ 'ID' => $post->ID ] );
					if ( ! $result ) {
						WP_CLI::line( 'Error updating post ' . $post->ID );
					} else {
						update_post_meta( $post->ID, '_wp_page_template', 'single-wide.php' );
						WP_CLI::line( 'Updated post ' . $post->ID );
					}

					$is_validated = get_post_meta( $post->ID, 'tipi-content-converted', true );
					if ( ! $is_validated ) {
						add_post_meta( $post->ID, 'tipi-content-converted', true );
					}
				} else {
					WP_CLI::line( 'Skipping empty post ' . $post->ID );
				}
			}

			WP_CLI::success( $post->ID );
		}

		wp_cache_flush();
		WP_CLI::success( 'Done' );
	}

	/**
	 * Process Tipi block to Gutenberg block.
	 *
	 * @param int   $index Section index.
	 * @param array $section Block options.
	 * @return array | boolean
	 */
	private function process_tipi_section( $index, $section ) {
		$p      = intval( $section['preview'] );
		$blocks = false;

		// For dev purposes.
		// $block_html_content = '<!-- wp:newspack-ads/ad-unit /-->';
		// print_r( json_encode( current( parse_blocks( $block_html_content ) ) ) );
		// die();

		if ( 110 === $p ) {
			$blocks = $this->generate_block_columns( $index, $section );
		} elseif ( 101 === $p ) {
			$this->log( self::GENERAL_LOG, "Widget block in section $index" );
		} elseif ( $p > 80 || in_array( $p, [ 2, 21, 61, 62, 71, 74, 79 ] ) || ( $p > 40 && $p < 45 ) ) {
			$blocks = $this->generate_block_homepage_posts( $index, $section, in_array( $p, [ 21, 79 ] ) );
		} elseif ( 59 === $p ) {
			$blocks = $this->generate_block_paragraph( $index, $section );
		} elseif ( $p > 50 && $p < 56 ) {
			$blocks = $this->generate_block_post_carousel( $index, $section );
		} elseif ( 49 === $p ) {
			$blocks = $this->generate_block_hero( $index, $section );
		} elseif ( 48 === $p ) {
			$blocks = $this->generate_block_title( $index, $section );
		} elseif ( 39 === $p ) {
			$blocks = $this->generate_block_spacer( $index, $section );
		} elseif ( 36 === $p ) {
			$blocks = $this->generate_block_button( $index, $section );
		} elseif ( 35 === $p ) {
			$blocks = $this->generate_block_image( $index, $section );
		} elseif ( 34 === $p ) {
			$blocks = $this->generate_block_custom_code( $index, $section );
			// For dev purposes.
			// foreach ( $blocks as $block ) {
			// print_r( serialize_block( $block ) );
			// }
			// die();
		} elseif ( 31 === $p ) {
			$this->log( self::GENERAL_LOG, "Instagram block ignored in section $index" );
		} elseif ( 30 === $p ) {
			$blocks = $this->generate_block_video( $index, $section );
		} elseif ( in_array( $p, [ 1, 22 ] ) ) {
			$blocks = $this->generate_block_homepage_posts( $index, $section, false, 'left' );
		} else {
			WP_CLI::warning( "Section $p not parsed" );
			$blocks = false;
		}

		return $blocks;
	}

	/**
	 * Generate image block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_columns( $index, $section ) {
		$columns_count = empty( $section['columns'] ) ? 2 : intval( $section['columns'] );
		$layout        = $this->get_section_layout( $section );

		// Inner Blocks.
		$nested_blocks = array_filter(
			$section['nested'],
			function( $nested_section ) {
				return count( $nested_section ) > 0;
			}
		);

		$inner_blocks = [];
		foreach ( $nested_blocks as $block_index => $inner_column_blocks ) {
			$column_size    = $this->get_column_size( $block_index, $columns_count, $layout );
			$inner_blocks[] = $this->generate_block_column( "$column_size%", $inner_column_blocks );
		}

		// Inner content.
		$inner_content = array_fill( 1, count( $inner_blocks ), null );
		array_unshift( $inner_content, '<div class="wp-block-columns">' );
		array_push( $inner_content, '</div>' );

		return [
			[
				'blockName'    => 'core/columns',
				'attrs'        => [],
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => '<div class="wp-block-columns"></div>',
				'innerContent' => $inner_content,
			],
		];
	}

	/**
	 * Generate image block.
	 *
	 * @param string $width Column width.
	 * @param array  $inner_blocks Inner blocks options.
	 * @return array | boolean
	 */
	private function generate_block_column( $width, $inner_blocks ) {
		$processed_inner_blocks = [];
		foreach ( $inner_blocks as $k => $inner_block ) {
			$processed_blocks = $this->process_tipi_section( $k, $inner_block );

			if ( $processed_blocks ) {
				foreach ( $processed_blocks as $processed_block ) {
					$processed_inner_blocks[] = $processed_block;
				}
			}
		}

		// Inner content.
		$inner_content = array_fill( 1, count( $processed_inner_blocks ), null );
		array_unshift( $inner_content, "<div class='wp-block-column' style='flex-basis:$width'>" );
		array_push( $inner_content, '</div>' );

		return [
			'blockName'    => 'core/column',
			'attrs'        => [
				'width' => $width,
			],
			'innerBlocks'  => $processed_inner_blocks,
			'innerHTML'    => "<div class='wp-block-column' style='flex-basis:$width'></div>",
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Generate image block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_custom_code( $index, $section ) {
		if ( ! array_key_exists( 'custom_content', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block custom code without `custom_content` in section $index" );
			return false;
		}

		$custom_content = trim( $section['custom_content'] );

		// Ad case.
		if ( preg_match( '/^\[bsa_pro_ad_space.*\]/', $custom_content ) ) {
			$this->log( self::GENERAL_LOG, 'TODO: Ad Unit: ' . $custom_content );

			return [
				[
					'blockName'    => 'newspack-ads/ad-unit',
					'attrs'        => [],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
			];
		} elseif ( preg_match( '/^\[poll.*\]/', $custom_content ) ) {
			// Crowdsignal case.
			$this->log( self::GENERAL_LOG, 'TODO: Crowdsignal: ' . $custom_content );

			return [
				[
					'blockName'    => 'core/embed',
					'attrs'        => [
						'providerNameSlug' => 'crowdsignal',
						'responsive'       => true,
					],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
			];
		} elseif ( preg_match( '/^\[simple_masonry\ssm_category_name="(?P<category_id>[^"]+)"\]/', $custom_content, $matches ) ) {
			// Posts Masonery.
			if ( array_key_exists( 'category_id', $matches ) ) {
				$category = get_category_by_slug( $matches['category_id'] );

				if ( $category ) {
					$section['category'] = $category->term_id;
					return $this->generate_block_homepage_posts( $index, $section );
				}

				return false;
			}
		} elseif ( preg_match( '/^\[elfsight_youtube_gallery.*\]/', $custom_content ) ) {
			// YouTube case.
			$this->log( self::GENERAL_LOG, 'TODO: Youtube: ' . $custom_content );
			// shortcode_parse_atts didn't work for this shortcode (e.g. [elfsight_youtube_gallery id="1"])
			preg_match( '/id="(?P<id>\d+)"/', $custom_content, $matches );
			if ( array_key_exists( 'id', $matches ) ) {
				$youtube_video = $this->get_youtube_video_from_elfsight( $matches['id'] );

				return [
					[
						'blockName'    => 'core/embed',
						'attrs'        => [
							'url'              => $youtube_video,
							'type'             => 'video',
							'providerNameSlug' => 'youtube',
							'responsive'       => true,
							'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
						],
						'innerBlocks'  => [],
						'innerHTML'    => '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $youtube_video . '</div></figure>',
						'innerContent' => [ '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $youtube_video . '</div></figure>' ],
					],
				];
			}

			$this->log( self::GENERAL_LOG, 'elfsight_youtube_gallery shortcode without ID: ' . $custom_content );
		} elseif ( preg_match( '/^\[visualizer.*\]/', $custom_content ) ) {
			$this->log( self::GENERAL_LOG, "Visualizer block ignored in section $index" );
		} elseif ( preg_match( '/^\[\w+(\s.*?)?\](?:([^\[]+)?\[\/\w+\])?/', $custom_content ) ) {
			$this->log( self::GENERAL_LOG, 'Block custom code shortcode not handled: ' . $custom_content );
		}

		return [
			[
				'blockName'    => 'core/html',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $custom_content,
				'innerContent' => [ $custom_content ],
			],
		];
	}

	/**
	 * Generate image block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_image( $index, $section ) {
		if ( ! array_key_exists( 'img_bg', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block image without `img_bg` in section $index" );
			return false;
		}

		return [
			[
				'blockName'    => 'core/image',
				'attrs'        => [
					'sizeSlug' => 'large',
				],
				'innerBlocks'  => [],
				'innerHTML'    => '<figure class="wp-block-image size-large"><img src="https://test.local/wp-content/uploads/2020/02/cabecera-educacion-el-reto-de-innovar-v5.jpg" alt=""/></figure>',
				'innerContent' => [ '<figure class="wp-block-image size-large"><img src="https://test.local/wp-content/uploads/2020/02/cabecera-educacion-el-reto-de-innovar-v5.jpg" alt=""/></figure>' ],
			],
		];
	}

	/**
	 * Generate video block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_video( $index, $section ) {
		if ( ! array_key_exists( 'video_url', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block video without `video_url` in section $index" );
			return false;
		}

		return [
			[
				'blockName'    => 'core/video',
				'attrs'        => [ 'src' => $section['video_url'] ],
				'innerBlocks'  => [],
				'innerHTML'    => '<figure class="wp-block-video"><video controls src="' . $section['video_url'] . '"></video></figure>',
				'innerContent' => [ '<figure class="wp-block-video"><video controls src="' . $section['video_url'] . '"></video></figure>' ],
			],
		];
	}

	/**
	 * Generate Homepage posts block.
	 *
	 * @param integer       $index Section index.
	 * @param array         $section Block options.
	 * @param boolean       $is_grid If post layout is grid.
	 * @param string | null $media_position Media position in post.
	 * @return array | boolean
	 */
	private function generate_block_homepage_posts( $index, $section, $is_grid = false, $media_position = null ) {
		// Carousel block.
		if ( array_key_exists( 'load_more', $section ) && 2 === intval( $section['load_more'] ) ) {
			return $this->generate_block_post_carousel( $index, $section );
		}

		$blocks = [];

		if ( array_key_exists( 'title', $section ) ) {
			$blocks[] = current( $this->generate_block_title( $index, $section ) );
		}

		$posts_per_page = intval( array_key_exists( 'qry', $section ) ? $section['qry']['posts_per_page'] : ( array_key_exists( 'posts_per_page', $section ) ? $section['posts_per_page'] : 2 ) );
		$category       = intval( array_key_exists( 'qry', $section ) ? $section['qry']['cat'] : ( array_key_exists( 'category', $section ) ? $section['category'] : null ) );
		$columns        = intval( array_key_exists( 'columns', $section ) ? $section['columns'] : 2 );

		$attrs = [
			'className'   => 'is-style-default',
			'showExcerpt' => false,
			'showDate'    => false,
			'showAuthor'  => false,
			'columns'     => $columns,
			'postsToShow' => $posts_per_page,
			'moreButton'  => ( array_key_exists( 'load_more', $section ) && 1 === intval( $section['load_more'] ) ),
		];

		if ( $category ) {
			$attrs['categories'] = [ $category ];
		}

		if ( $media_position ) {
			$attrs['mediaPosition'] = $media_position;
		}

		if ( $is_grid ) {
			$attrs['postLayout'] = 'grid';
		}

		$blocks[] = [
			'blockName'    => 'newspack-blocks/homepage-articles',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		return $blocks;
	}

	/**
	 * Generate Post carousel block.
	 *
	 * @param integer       $index Section index.
	 * @param array         $section Block options.
	 * @param string | null $media_position Media position in post.
	 * @return array | boolean
	 */
	private function generate_block_post_carousel( $index, $section ) {
		$blocks = [];

		if ( array_key_exists( 'title', $section ) ) {
			$blocks[] = current( $this->generate_block_title( $index, $section ) );
		}

		$posts_per_page = intval( array_key_exists( 'qry', $section ) ? $section['qry']['posts_per_page'] : ( array_key_exists( 'posts_per_page', $section ) ? $section['posts_per_page'] : 2 ) );
		$category       = intval( array_key_exists( 'qry', $section ) ? $section['qry']['cat'] : ( array_key_exists( 'category', $section ) ? $section['category'] : null ) );
		$columns        = intval( array_key_exists( 'columns', $section ) ? $section['columns'] : 2 );

		$attrs = [
			'postsToShow'   => $posts_per_page,
			'slidesPerView' => $columns,
			'showDate'      => false,
			'showAuthor'    => false,
		];

		if ( $category ) {
			$attrs['categories'] = [ $category ];
		}

		$blocks[] = [
			'blockName'    => 'newspack-blocks/carousel',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		return $blocks;
	}

	/**
	 * Generate paragraph block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array | boolean
	 */
	private function generate_block_paragraph( $index, $section ) {
		if ( ! array_key_exists( 'custom_content', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block paragraph without `custom_content` in section $index" );
			return false;
		}

		$stripped_paragraph = preg_replace( '/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i', '<$1$2>', $section['custom_content'] );

		return [
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $stripped_paragraph,
				'innerContent' => [ $stripped_paragraph ],
			],
		];
	}

	/**
	 * Generate spacer block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return void
	 */
	private function generate_block_spacer( $index, $section ) {
		$space = array_key_exists( 'padding_top', $section ) ? intval( $section['padding_top'] ) : 10;

		return [
			[
				'blockName'    => 'core/spacer',
				'attrs'        => [ 'height' => $space ],
				'innerBlocks'  => [],
				'innerHTML'    => "<div style='height:${space}px' aria-hidden='true' class='wp-block-spacer'></div>",
				'innerContent' => [ "<div style='height:${space}px' aria-hidden='true' class='wp-block-spacer'></div>" ],
			],
		];
	}

	/**
	 * Generate title block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array
	 */
	private function generate_block_title( $index, $section ) {
		$blocks = [];

		// Title.
		if ( array_key_exists( 'title', $section ) ) {
			$title = strip_tags( $section['title'] );

			$blocks[] = [
				'blockName'    => 'core/heading',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => "<h2>$title</h2>",
				'innerContent' => [ "<h2>$title</h2>" ],
			];
		}

		// Custom paragraph.
		if ( array_key_exists( 'custom_content', $section ) ) {
			$blocks[] = current( $this->generate_block_paragraph( $index, $section ) );
		}

		return $blocks;
	}

	/**
	 * Generate hero block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array
	 */
	private function generate_block_hero( $index, $section ) {
		if ( ! array_key_exists( 'custom_content', $section ) && ! array_key_exists( 'cta_content', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block hero without `custom_content` in section $index" );
			return false;
		}

		$title = strip_tags( $section['custom_content'] ?? $section['cta_content'] );

		$button_text = array_key_exists( 'button_text', $section ) ? strip_tags( $section['button_text'] ) : '';
		$button_link = false;

		if ( array_key_exists( 'button_text', $section ) ) {
			preg_match( '/<a\shref="(?P<link>[^"]+)"/', $section['button_text'], $matches );
			if ( array_key_exists( 'link', $matches ) ) {
				$button_link = $matches['link'];
			}
		}

		$height = 300;

		$block_id = wp_generate_password();

		$block_attrs = [
			'paddingSyncUnits' => true,
			'height'           => $height,
			'contentAlign'     => 'left',
			'className'        => 'coblocks-hero-' . $block_id,
			'coblocks'         => [
				'id' => $block_id,
			],
		];

		if ( array_key_exists( 'img_bg', $section ) ) {
			$block_attrs['backgroundImg'] = $section['img_bg'];
		}

		return [
			[
				'blockName'    => 'coblocks/hero',
				'attrs'        => $block_attrs,
				'innerBlocks'  => [
					[
						'blockName'    => 'core/heading',
						'attrs'        => [
							'placeholder' => 'Add hero heading…',
						],
						'innerBlocks'  => [],
						'innerHTML'    => "<h2>$title</h2>",
						'innerContent' => [ "<h2>$title</h2>" ],
					],
					[
						'blockName'    => 'core/paragraph',
						'attrs'        => [
							'placeholder' => 'Add hero content, which is typically an introductory area of a page accompanied by call to action or two.',
						],
						'innerBlocks'  => [],
						'innerHTML'    => '<p></p>',
						'innerContent' => [ '<p></p>' ],
					],
					[
						'blockName'    => 'core/buttons',
						'attrs'        => [ 'align' => 'left' ],
						'innerBlocks'  => [
							[
								'blockName'    => 'core/button',
								'attrs'        => [ 'placeholder' => 'Primary button…' ],
								'innerBlocks'  => [],
								'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $button_link . '" target="_blank" rel="noreferrer noopener">' . $button_text . '</a></div>',
								'innerContent' => [ '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $button_link . '" target="_blank" rel="noreferrer noopener">' . $button_text . '</a></div>' ],
							],
							[
								'blockName'    => 'core/button',
								'attrs'        => [
									'placeholder' => 'Secondary button…',
									'className'   => 'is-style-outline',
								],
								'innerBlocks'  => [],
								'innerHTML'    => '',
								'innerContent' => [],
							],
						],
						'innerHTML'    => '<div class="wp-block-buttons"></div>',
						'innerContent' => [ '<div class="wp-block-buttons">', null, '', null, '</div>' ],
					],
				],
				'innerHTML'    => '<div class="wp-block-coblocks-hero alignfull coblocks-hero-' . $block_id . '"><div class="wp-block-coblocks-hero__inner bg-cover has-background-image bg-no-repeat bg-center-center hero-center-left-align has-padding has-huge-padding has-left-content" style="background-image:url(' . $section['img_bg'] . ');min-height:' . $height . 'px"><div class="wp-block-coblocks-hero__content-wrapper"><div class="wp-block-coblocks-hero__content" style="max-width:560px"></div></div></div></div>',
				'innerContent' => [ '<div class="wp-block-coblocks-hero alignfull coblocks-hero-' . $block_id . '"><div class="wp-block-coblocks-hero__inner bg-cover has-background-image bg-no-repeat bg-center-center hero-center-left-align has-padding has-huge-padding has-left-content" style="background-image:url(' . $section['img_bg'] . ');min-height:' . $height . 'px"><div class="wp-block-coblocks-hero__content-wrapper"><div class="wp-block-coblocks-hero__content" style="max-width:560px">', null, '', null, '', null, '</div></div></div></div>' ],
			],
		];
	}

	/**
	 * Generate button block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array
	 */
	private function generate_block_button( $index, $section ) {
		if ( ! array_key_exists( 'button_text', $section ) ) {
			$this->log( self::GENERAL_LOG, "Block button without `button_text` in section $index" );
			return false;
		}

		$button_text = strip_tags( $section['button_text'] );

		$button_attr = [];

		// if ( array_key_exists( 'button_color', $section ) ) {
		// $button_attr['style'] = [ 'color' => [ 'background' => $section['button_color'] ] ];
		// }

		if ( array_key_exists( 'width', $section ) ) {
			$button_attr['width'] = ( intval( $section['width'] ) * 100 ) / 950; // 950 is 100%.
		}

		$button_link = false;

		preg_match( '/<a\shref="(?P<link>[^"]+)"/', $section['button_text'], $matches );
		if ( array_key_exists( 'link', $matches ) ) {
			$button_link = $matches['link'];
		}

		// $button_style = array_key_exists( 'button_color', $section ) ? 'background-color:' . $section['button_color'] : '';

		return [
			[
				'blockName'    => 'core/buttons',
				'attrs'        => [ 'align' => 'full' ],
				'innerBlocks'  => [
					[
						'blockName'    => 'core/button',
						'attrs'        => $button_attr,
						'innerBlocks'  => [],
						'innerHTML'    => '<div class="wp-block-button has-custom-width wp-block-button__width-' . $button_attr['width'] . '"><a class="wp-block-button__link" href="' . $button_link . '">' . $button_text . '</a></div>',
						'innerContent' => [ '<div class="wp-block-button has-custom-width wp-block-button__width-' . $button_attr['width'] . '"><a class="wp-block-button__link" href="' . $button_link . '">' . $button_text . '</a></div>' ],
					],
				],
				'innerHTML'    => '<div class="wp-block-buttons alignfull"></div>',
				'innerContent' => [
					'<div class="wp-block-buttons alignfull">',
					null,
					'</div>',
				],
			],
		];
	}

	/**
	 * Remove unnecessary block options
	 *
	 * @param array $section Block options to be filtered.
	 * @return array
	 */
	private function clean_section( $section ) {
		return array_filter(
			$section,
			function ( $meta ) {
				return ! in_array( $meta, [ 'margin_type', 't_margin_type', 'mobile', 'desktop', 'padding_bottom', 'padding_right', 'padding_left', 'padding_type', 'm_padding_top', 'm_padding_bottom', 'm_padding_right', 'm_padding_left', 'm_padding_type', 'border_color', 'border_outer', 'skin_outer', 'm_margin_type', 't_padding_top', 't_padding_bottom', 't_padding_right', 't_padding_left', 't_padding_type', 'meta_background_padding', 'meta_background_img_opacity', 'meta_background_color', 'skin', 'skin_img_opacity', 'skin_color', 'skin_parallax', 'divider_top_onoff', 'divider_bottom_onoff', 'animation_onoff', 'animation_stagger', 'boxed_content' ] );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Generate column size based on the layout.
	 *
	 * Based on zeen/inc/classes/class-zeen-block-columns.php:69
	 *
	 * @param int $block_index Column index in its list.
	 * @param int $columns_count Total columns.
	 * @param int $layout Layout model.
	 * @return int
	 */
	private function get_column_size( $block_index, $columns_count, $layout ) {
		if ( 2 === $columns_count ) {
			$size = 50;
			if ( 1 === $layout ) {
				$size = 0 === $block_index ? 66.66 : 33.33;
			} elseif ( 2 === $layout ) {
				$size = 0 === $block_index ? 33.33 : 66.66;
			} elseif ( 3 === $layout ) {
				$size = 0 === $block_index ? 20 : 80;
			} elseif ( 4 === $layout ) {
				$size = 0 === $block_index ? 80 : 20;
			}
		} elseif ( 3 === $columns_count ) {
			$size = 33.33;
			if ( 1 === $layout ) {
				if ( 0 === $block_index ) {
					$size = 20;
				} elseif ( 1 === $block_index ) {
					$size = 46.66;
				}
			} elseif ( 2 === $layout ) {
				if ( 2 === $block_index ) {
					$size = 20;
				} elseif ( 1 === $block_index ) {
					$size = 46.66;
				}
			} elseif ( 3 === $layout ) {
				if ( 2 === $block_index ) {
					$size = 66.66;
				}
			} elseif ( 4 === $layout ) {
				$size = 25;
				if ( 2 === $block_index ) {
					$size = 50;
				}
			} elseif ( 5 === $layout ) {
				$size = 25;
				if ( 0 === $block_index ) {
					$size = 50;
				}
			} elseif ( 6 === $layout ) {
				$size = 25;
				if ( 1 === $block_index ) {
					$size = 50;
				}
			}
		} elseif ( 4 === $columns_count ) {
			$size = 25;
		} elseif ( 1 === $columns_count ) {
			$size = 100;
		}

		return $size;
	}

	/**
	 * Get section layout based on section preview.
	 *
	 * Based on zeen/inc/core/tipi-block.php:36
	 *
	 * @param array $section Section to get its layout.
	 * @return int
	 */
	private function get_section_layout( $section ) {

		if ( 300 === $section['preview'] ) {
			return empty( $section['layout'] ) ? 1 : $section['layout'];
		} else {
			return empty( $section['layout'] ) ? 0 : $section['layout'];
		}
	}

	private function get_youtube_video_from_elfsight( $widget_id ) {
		global $wpdb;
		$results = $wpdb->get_row( $wpdb->prepare( "SELECT options FROM {$wpdb->prefix}elfsight_youtube_gallery_widgets WHERE id = %d", $widget_id ) );
		if ( ! empty( $results ) ) {
			$options = json_decode( $results->options, true );

			// From videos list.
			if ( ! empty( $options['sourceGroups'] ) ) {
				return $options['sourceGroups'][0]['sources'][0];
			}

			// TODO: From channel use YouTube API to get first video? ($options['channel'])?
		}
		return '';
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		WP_CLI::line( $message );

		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
