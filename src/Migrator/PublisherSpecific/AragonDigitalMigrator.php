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
				'synopsis'  => array(),
			),
		);

		WP_CLI::add_command(
			'newspack-content-migrator migrate-aragon-digital-movie-library-to-posts',
			array( $this, 'handle_movie_library_to_posts_conversion' ),
			array(
				'shortdesc' => 'Convert ',
				'synopsis'  => array(),
			),
		);

		WP_CLI::add_command(
			'newspack-content-migrator aragon-set-movies-year-as-tag',
			array( $this, 'handle_setting_movies_year_as_tags' ),
			array(
				'shortdesc' => 'Set movies years as tags.',
				'synopsis'  => array(),
			),
		);

		WP_CLI::add_command(
			'newspack-content-migrator aragon-set-movies-tags',
			array( $this, 'handle_movie_tags' ),
			array(
				'shortdesc' => 'Set movies years as tags.',
				'synopsis'  => array(),
			),
		);

		WP_CLI::add_command(
			'newspack-content-migrator aragon-fix-movies-yt-block',
			array( $this, 'handle_fix_movies_YT_block' ),
			array(
				'shortdesc' => 'Set movies years as tags.',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * Callable for `newspack-content-migrator convert-aragon-digital-posts`.
	 * Custom data conversion handler.
	 */
	public function handle_post_conversion() {
		global $wpdb;

		$posts = get_posts(
			array(
				'posts_per_page' => -1,
				'meta_key'       => 'tipi_builder_data',
				'post_type'      => 'page',
				'post__not_in'   => array( 310137, 310397, 310593 ), // Pages already fixed by the publisher.
			)
		);

		$total_posts = count( $posts );

		/** @var WP_Post $post */
		foreach ( $posts as $i => $post ) {
			// $this->log( self::GENERAL_LOG, "Converting post: {$post->post_title} (#{$post->ID})" );
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
					$result = $wpdb->update( $wpdb->prefix . 'posts', array( 'post_content' => $updated_content ), array( 'ID' => $post->ID ) );
					if ( ! $result ) {
						WP_CLI::line( 'Error updating post ' . $post->ID );
					} else {
						update_post_meta( $post->ID, '_wp_page_template', 'single-wide.php' );
						WP_CLI::line( 'Updated post ' . $post->ID );
					}
				} else {
					WP_CLI::line( 'Skipping empty post ' . $post->ID );
				}
			}

			WP_CLI::success( $post->ID );
		}

		WP_CLI::success( 'Done' );
	}

	/**
	 * Callable for `newspack-content-migrator migrate-aragon-digital-movie-library-to-posts`.
	 * Convert WP Movie Library custom posts to Blocks Posts.
	 */
	public function handle_movie_library_to_posts_conversion() {
		// You must convert all needed terms to catgories/tags before running this.
		// wp newspack-content-migrator terms-with-taxonomy-to-categories --taxonomy=genre --create-parent-category=true

		$movies_category = wp_create_category( 'Movie' );

		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'post_type'   => 'movie',
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$movie_index = $index + 1;
			$poster      = $this->get_movie_imported_attachments( $post->ID, 'poster' );
			$poster_full = $this->get_movie_imported_attachments( $post->ID, 'poster', 'full' );
			$images      = $this->get_movie_imported_attachments( $post->ID );

			$poster_id     = $poster ? $poster['id'] : get_post_thumbnail_id( $post->ID );
			$poster_url    = $poster ? $poster['url'] : get_the_post_thumbnail_url( $post->ID );
			$poster_width  = $poster ? $poster['width'] : 200;
			$poster_height = $poster ? $poster['height'] : 300;

			$poster_full_url = $poster_full ? $poster_full['url'] : get_the_post_thumbnail_url( $post->ID );

			$duration     = get_post_meta( $post->ID, '_wpmoly_movie_runtime', true );
			$release_date = date_i18n( 'd F Y', strtotime( get_post_meta( $post->ID, '_wpmoly_movie_release_date', true ) ) );

			$genres    = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_genres', true ) );
			$directors = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_director', true ) );
			$actors    = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_cast', true ) );
			$overview  = get_post_meta( $post->ID, '_wpmoly_movie_overview', true );

			$genre_categories = array_map(
				function( $genre ) {
					$category_id = get_cat_ID( trim( $genre ) );
					return 0 !== $category_id ? get_category( get_cat_ID( trim( $genre ) ) ) : get_category_by_slug( sanitize_title( $genre ) );
				},
				array_filter( $genres ) // Filter empty category.
			);

			$categories_links_html = join(
				', ',
				array_map(
					function( $category ) {
						return '<a href="' . get_category_link( $category ) . "\">{$category->name}</a>";
					},
					$genre_categories
				)
			) . ' - ';

			$categories_links_html = ( ' - ' === $categories_links_html ) ? '' : $categories_links_html;

			$directors_tags = array_map(
				function( $director ) {
					$tag = wp_create_tag( trim( $director ) );
					return get_term( $tag['term_id'], 'post_tag' ); },
				array_filter( $directors )
			);

			$directors_links_html = join(
				', ',
				array_map(
					function( $tag ) {
						return '<a href="' . get_tag_link( $tag ) . "\">{$tag->name}</a>";
					},
					$directors_tags
				)
			);

			$actors_tags = array_map(
				function( $actor ) {
					$tag = wp_create_tag( trim( $actor ) );
					return get_term( $tag['term_id'], 'post_tag' ); },
				array_filter( $actors )
			);

			$actors_links_html = join(
				', ',
				array_map(
					function( $tag ) {
						return '<a href="' . get_tag_link( $tag ) . "\">{$tag->name}</a>";
					},
					$actors_tags
				)
			);

			$images_blocks = array_map(
				function( $image ) {
					return array(
						'blockName'    => 'core/image',
						'attrs'        => array(
							'id'              => $image['id'],
							'sizeSlug'        => 'large',
							'linkDestination' => 'none',
						),
						'innerBlocks'  => array(),
						'innerHTML'    => '<figure class="wp-block-image size-large"><img src="' . $image['url'] . '" alt="" class="wp-image-' . $image['id'] . '"/></figure>',
						'innerContent' => array( '<figure class="wp-block-image size-large"><img src="' . $image['url'] . '" alt="" class="wp-image-' . $image['id'] . '"/></figure>' ),
					);
				},
				$images
			);

			$movie_blocks = array(
				array(
					'blockName'    => 'core/columns',
					'attrs'        => array(),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'core/column',
							'attrs'        => array(),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'core/image',
									'attrs'        => array(
										'align'           => 'left',
										'id'              => $poster_id ? $poster_id : '',
										'width'           => $poster_width,
										'height'          => $poster_height,
										'sizeSlug'        => 'full',
										'linkDestination' => 'none',
									),
									'innerBlocks'  => array(),
									'innerHTML'    => '<div class="wp-block-image"><figure class="alignleft size-full is-resized"><img src="' . $poster_url . '" alt="" class="wp-image-' . $poster_id . '" width="' . $poster_width . '" height="' . $poster_height . '"/></figure></div>',
									'innerContent' => array( '<div class="wp-block-image"><figure class="alignleft size-full is-resized"><img src="' . $poster_url . '" alt="" class="wp-image-' . $poster_id . '" width="' . $poster_width . '" height="' . $poster_height . '"/></figure></div>' ),
								),
								array(
									'blockName'    => 'core/paragraph',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<p>R ' . $duration . ' min - ' . $categories_links_html . $release_date . '</p>',
									'innerContent' => array( '<p>R ' . $duration . ' min - ' . $categories_links_html . $release_date . '</p>' ),
								),
								array(
									'blockName'    => 'core/paragraph',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<p>' . $overview . '</p>',
									'innerContent' => array( '<p>' . $overview . '</p>' ),
								),
							),
							'innerHTML'    => '<div class="wp-block-column"></div>',
							'innerContent' => array(
								'<div class="wp-block-column">',
								null,
								'',
								null,
								'',
								null,
								'</div>',
							),
						),
					),
					'innerHTML'    => '<div class="wp-block-columns"></div>',
					'innerContent' => array(
						'<div class="wp-block-columns">',
						null,
						'</div>',
					),
				),
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p><strong>Director: </strong>' . $directors_links_html . '</p>',
					'innerContent' => array( '<p><strong>Director: </strong>' . $directors_links_html . '</p>' ),
				),
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p><strong>Intérpretes: </strong>' . $actors_links_html . '</p>',
					'innerContent' => array( '<p><strong>Intérpretes: </strong>' . $actors_links_html . '</p>' ),
				),
				array(
					'blockName'    => 'core/heading',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<h2 id="fotos">Fotos</h2>',
					'innerContent' => array( '<h2 id="fotos">Fotos</h2>' ),
				),
			);

			if ( ! empty( $images_blocks ) ) {
				$movie_blocks[] = array(
					'blockName'    => 'core/gallery',
					'attrs'        => array(
						'linkTo' => 'none',
					),
					'innerBlocks'  => $images_blocks,
					'innerHTML'    => '<figure class="wp-block-gallery has-nested-images columns-default is-cropped"></figure>',
					'innerContent' => array(
						'<figure class="wp-block-gallery has-nested-images columns-default is-cropped">',
						null,
						'',
						null,
						'</figure>',
					),
				);
			}

			$movie_blocks[] = array(
				'blockName'    => null,
				'attrs'        =>
				array(),
				'innerBlocks'  =>
				array(),
				'innerHTML'    => '<p>' . nl2br( $post->post_content ) . '</p>',
				'innerContent' =>
				array( '<p>' . nl2br( $post->post_content ) . '</p>' ),
			);

			$movie_blocks[] = array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'id'              => $poster_id,
					'sizeSlug'        => 'full',
					'linkDestination' => 'none',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '<figure class="wp-block-image size-full"><img src="' . $poster_full_url . '" alt="" class="wp-image-' . $poster_id . '"/></figure>',
				'innerContent' => array( '<figure class="wp-block-image size-full"><img src="' . $poster_full_url . '" alt="" class="wp-image-' . $poster_id . '"/></figure>' ),
			);

			$post_content = join(
				'',
				array_map(
					function( $block ) {
						return serialize_block( $block );
					},
					$movie_blocks
				)
			);

			$result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $post_content,
					'post_type'    => 'post',
					'post_status'  => 'publish',
				)
			);

			if ( ! $result ) {
				WP_CLI::warning( sprintf( '(%d/%d) Failed to update: %d', $movie_index, $total_posts, $post->ID ) );
			} else {
				wp_set_post_categories( $post->ID, array( $movies_category ), true );
				update_post_meta( $post->ID, 'newspack_migrated_movie', true );
				update_post_meta( $post->ID, 'newspack_featured_image_position', 'hidden' );

				WP_CLI::success( sprintf( '(%d/%d) Post updated: %d', $movie_index, $total_posts, $post->ID ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator aragon-set-movies-year-as-tag`.
	 * Will hide movies's featured images.
	 */
	public function handle_setting_movies_year_as_tags() {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'post_type'   => 'post',
				'meta_key'    => 'newspack_migrated_movie',
			)
		);

		$total_posts = count( $posts );
		foreach ( $posts as $movie_index => $post ) {
			$year = date_i18n( 'Y', strtotime( get_post_meta( $post->ID, '_wpmoly_movie_release_date', true ) ) );
			wp_set_post_tags( $post->ID, $year, true );
			WP_CLI::success( sprintf( '(%d/%d) Year %s set as tag for the post: %d', $movie_index, $total_posts, $year, $post->ID ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator aragon-set-movies-tags`.
	 * Will hide movies's featured images.
	 */
	public function handle_movie_tags() {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'post_type'   => 'post',
				'meta_key'    => 'newspack_migrated_movie',
			)
		);

		$total_posts = count( $posts );
		foreach ( $posts as $movie_index => $post ) {
			$genres           = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_genres', true ) );
			$directors        = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_director', true ) );
			$actors           = explode( ',', get_post_meta( $post->ID, '_wpmoly_movie_cast', true ) );
			$genre_categories = array_map(
				function( $genre ) {
					$category_id = get_cat_ID( trim( $genre ) );
					return 0 !== $category_id ? get_category( get_cat_ID( trim( $genre ) ) ) : get_category_by_slug( sanitize_title( $genre ) );
				},
				array_filter( $genres ) // Filter empty category.
			);
			$directors_tags   = array_map(
				function( $director ) {
					$tag = wp_create_tag( trim( $director ) );
					return get_term( $tag['term_id'], 'post_tag' ); },
				array_filter( $directors )
			);
			$actors_tags      = array_map(
				function( $actor ) {
					$tag = wp_create_tag( trim( $actor ) );
					return get_term( $tag['term_id'], 'post_tag' ); },
				array_filter( $actors )
			);

			$categories_ids = array_map(
				function( $category ) {
					return $category->term_id;
				},
				$genre_categories
			);

			$tags = array_merge(
				array_map(
					function( $director ) {
						return $director->name;
					},
					$directors_tags
				),
				array_map(
					function( $actor ) {
						return $actor->name;
					},
					$actors_tags
				)
			);

			wp_set_post_categories( $post->ID, $categories_ids, true );
			wp_set_post_tags( $post->ID, $tags, true );

			WP_CLI::success( sprintf( '(%d/%d) Added %d categories, and %d tags for the post %d', $movie_index, $total_posts, count( $categories_ids ), count( $tags ), $post->ID ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator aragon-fix-movies-yt-block`.
	 * Will hide movies's featured images.
	 */
	public function handle_fix_movies_YT_block() {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'post_type'   => 'post',
				'meta_key'    => 'newspack_migrated_movie',
			)
		);

		$total_posts = count( $posts );
		foreach ( $posts as $movie_index => $post ) {
			$updated = false;
			$blocks  = array_map(
				function( $block ) use ( &$updated ) {
					if ( is_null( $block['blockName'] ) && ! $updated ) {
						$cleared_content = strip_tags( $block['innerHTML'], '< /br>' );
						$cleared_content = trim( str_replace( '&nbsp;', '', $cleared_content ) );
						preg_match( '/^((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)(?P<video_id>[\w\-]+)(\S+)?((<br[^>]+>)\s*<br[^>]+>\s*&nbsp;)?$/', $cleared_content, $yt_matches );

						if ( empty( $yt_matches ) ) {
							preg_match( '/^<p><iframe src="((?:https?:)?\/\/)?((?:www|m)\.)?((?:youtube\.com|youtu.be))(\/(?:[\w\-]+\?v=|embed\/|v\/)?)(?P<video_id>[\w\-]+)(\S+)?"[^>]*><\/iframe><\/p>$/', $block['innerHTML'], $yt_matches );
						}

						if ( array_key_exists( 'video_id', $yt_matches ) ) {
							$video_url = 'https://www.youtube.com/watch?v=' . $yt_matches['video_id'];
							$updated   = true;
							return array(
								'blockName'    => 'core/embed',
								'attrs'        =>
								array(
									'url'              => $video_url,
									'type'             => 'video',
									'providerNameSlug' => 'youtube',
									'responsive'       => true,
									'className'        => '',
								),
								'innerBlocks'  =>
								array(),
								'innerHTML'    => '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . $video_url . '</div></figure>',
								'innerContent' =>
								array(
									'<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . $video_url . '</div></figure>',
								),
							);
						}
					}

					return $block;
				},
				parse_blocks( $post->post_content )
			);

			if ( $updated ) {
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => serialize_blocks( $blocks ),
					)
				);
				WP_CLI::success( sprintf( '(%d/%d) Post %d content updated.', $movie_index, $total_posts, $post->ID ) );
			} else {
				WP_CLI::success( sprintf( '(%d/%d) Post %d content not updated.', $movie_index, $total_posts, $post->ID ) );
			}
		}
	}

	/**
	 * Get Movie Poster or Images. Modified version from the one in the plugin
	 *
	 * @param int    $movie_post_id
	 * @param string $type
	 *
	 * @return mixed
	 */
	private static function get_movie_imported_attachments( $movie_post_id, $type = 'image', $size = 'medium' ) {
		if ( 'movie' !== get_post_type( $movie_post_id ) ) {
			return false;
		}

		if ( 'poster' !== $type ) {
			$type = 'image';
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_key'       => "_wpmoly_{$type}_related_tmdb_id",
			'posts_per_page' => -1,
			'post_parent'    => $movie_post_id,
		);

		$attachments = new \WP_Query( $args );
		$images      = array();

		if ( $attachments->posts ) {
			foreach ( $attachments->posts as $attachment ) {
				$media    = wp_get_attachment_image_src( $attachment->ID, $size );
				$images[] = array(
					'id'     => $attachment->ID,
					'url'    => $media[0],
					'width'  => $media[1],
					'height' => $media[2],
				);
			}
		}

		return 'poster' === $type ? current( $images ) : $images;
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

		// $block_html_content = '<!-- wp:newspack-ads/ad-unit /-->';
		// print_r( json_encode( current( parse_blocks( $block_html_content ) ) ) );
		// die();

		if ( 110 === $p ) {
			$blocks = $this->generate_block_columns( $index, $section );
		} elseif ( 101 === $p ) {
			$this->log( self::GENERAL_LOG, "Widget block in section $index" );
		} elseif ( $p > 80 || in_array( $p, array( 2, 21, 61, 62, 71, 74, 79 ) ) || ( $p > 40 && $p < 45 ) ) {
			$blocks = $this->generate_block_homepage_posts( $index, $section, in_array( $p, array( 21, 79 ) ) );
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
			// foreach ( $blocks as $block ) {
			// print_r( serialize_block( $block ) );
			// }
			// die();
		} elseif ( 31 === $p ) {
			$this->log( self::GENERAL_LOG, "Instagram block ignored in section $index" );
		} elseif ( 30 === $p ) {
			$blocks = $this->generate_block_video( $index, $section );
		} elseif ( in_array( $p, array( 1, 22 ) ) ) {
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

		$inner_blocks = array();
		foreach ( $nested_blocks as $block_index => $inner_column_blocks ) {
			$column_size    = $this->get_column_size( $block_index, $columns_count, $layout );
			$inner_blocks[] = $this->generate_block_column( "$column_size%", $inner_column_blocks );
		}

		// Inner content.
		$inner_content = array_fill( 1, count( $inner_blocks ), null );
		array_unshift( $inner_content, '<div class="wp-block-columns">' );
		array_push( $inner_content, '</div>' );

		return array(
			array(
				'blockName'    => 'core/columns',
				'attrs'        => array(),
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => '<div class="wp-block-columns"></div>',
				'innerContent' => $inner_content,
			),
		);
	}

	/**
	 * Generate image block.
	 *
	 * @param string $width Column width.
	 * @param array  $inner_blocks Inner blocks options.
	 * @return array | boolean
	 */
	private function generate_block_column( $width, $inner_blocks ) {
		$processed_inner_blocks = array();
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

		return array(
			'blockName'    => 'core/column',
			'attrs'        => array(
				'width' => $width,
			),
			'innerBlocks'  => $processed_inner_blocks,
			'innerHTML'    => "<div class='wp-block-column' style='flex-basis:$width'></div>",
			'innerContent' => $inner_content,
		);
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

			return array(
				array(
					'blockName'    => 'newspack-ads/ad-unit',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
			);
		} elseif ( preg_match( '/^\[poll.*\]/', $custom_content ) ) {
			// Crowdsignal case.
			$this->log( self::GENERAL_LOG, 'TODO: Crowdsignal: ' . $custom_content );

			return array(
				array(
					'blockName'    => 'core/embed',
					'attrs'        => array(
						'providerNameSlug' => 'crowdsignal',
						'responsive'       => true,
					),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
			);
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

				return array(
					array(
						'blockName'    => 'core/embed',
						'attrs'        => array(
							'url'              => $youtube_video,
							'type'             => 'video',
							'providerNameSlug' => 'youtube',
							'responsive'       => true,
							'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
						),
						'innerBlocks'  => array(),
						'innerHTML'    => '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $youtube_video . '</div></figure>',
						'innerContent' => array( '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $youtube_video . '</div></figure>' ),
					),
				);
			}

			$this->log( self::GENERAL_LOG, 'elfsight_youtube_gallery shortcode without ID: ' . $custom_content );
			die();
		} elseif ( preg_match( '/^\[visualizer.*\]/', $custom_content ) ) {
			$this->log( self::GENERAL_LOG, "Visualizer block ignored in section $index" );
		} elseif ( preg_match( '/^\[\w+(\s.*?)?\](?:([^\[]+)?\[\/\w+\])?/', $custom_content ) ) {
			$this->log( self::GENERAL_LOG, 'Block custom code shortcode not handled: ' . $custom_content );
			die();
		}

		return array(
			array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $custom_content,
				'innerContent' => array( $custom_content ),
			),
		);
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

		return array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'sizeSlug' => 'large',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '<figure class="wp-block-image size-large"><img src="https://test.local/wp-content/uploads/2020/02/cabecera-educacion-el-reto-de-innovar-v5.jpg" alt=""/></figure>',
				'innerContent' => array( '<figure class="wp-block-image size-large"><img src="https://test.local/wp-content/uploads/2020/02/cabecera-educacion-el-reto-de-innovar-v5.jpg" alt=""/></figure>' ),
			),
		);
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

		return array(
			array(
				'blockName'    => 'core/video',
				'attrs'        => array( 'src' => $section['video_url'] ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<figure class="wp-block-video"><video controls src="' . $section['video_url'] . '"></video></figure>',
				'innerContent' => array( '<figure class="wp-block-video"><video controls src="' . $section['video_url'] . '"></video></figure>' ),
			),
		);
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

		$blocks = array();

		if ( array_key_exists( 'title', $section ) ) {
			$blocks[] = current( $this->generate_block_title( $index, $section ) );
		}

		$posts_per_page = intval( array_key_exists( 'qry', $section ) ? $section['qry']['posts_per_page'] : ( array_key_exists( 'posts_per_page', $section ) ? $section['posts_per_page'] : 2 ) );
		$category       = intval( array_key_exists( 'qry', $section ) ? $section['qry']['cat'] : ( array_key_exists( 'category', $section ) ? $section['category'] : null ) );
		$columns        = intval( array_key_exists( 'columns', $section ) ? $section['columns'] : 2 );

		$attrs = array(
			'className'   => 'is-style-default',
			'showExcerpt' => false,
			'showDate'    => false,
			'showAuthor'  => false,
			'columns'     => $columns,
			'postsToShow' => $posts_per_page,
			'moreButton'  => ( array_key_exists( 'load_more', $section ) && 1 === intval( $section['load_more'] ) ),
		);

		if ( $category ) {
			$attrs['categories'] = array( $category );
		}

		if ( $media_position ) {
			$attrs['mediaPosition'] = $media_position;
		}

		if ( $is_grid ) {
			$attrs['postLayout'] = 'grid';
		}

		$blocks[] = array(
			'blockName'    => 'newspack-blocks/homepage-articles',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

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
		$blocks = array();

		if ( array_key_exists( 'title', $section ) ) {
			$blocks[] = current( $this->generate_block_title( $index, $section ) );
		}

		$posts_per_page = intval( array_key_exists( 'qry', $section ) ? $section['qry']['posts_per_page'] : ( array_key_exists( 'posts_per_page', $section ) ? $section['posts_per_page'] : 2 ) );
		$category       = intval( array_key_exists( 'qry', $section ) ? $section['qry']['cat'] : ( array_key_exists( 'category', $section ) ? $section['category'] : null ) );
		$columns        = intval( array_key_exists( 'columns', $section ) ? $section['columns'] : 2 );

		$attrs = array(
			'postsToShow'   => $posts_per_page,
			'slidesPerView' => $columns,
			'showDate'      => false,
			'showAuthor'    => false,
		);

		if ( $category ) {
			$attrs['categories'] = array( $category );
		}

		$blocks[] = array(
			'blockName'    => 'newspack-blocks/carousel',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

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

		return array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $stripped_paragraph,
				'innerContent' => array( $stripped_paragraph ),
			),
		);
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

		return array(
			array(
				'blockName'    => 'core/spacer',
				'attrs'        => array( 'height' => $space ),
				'innerBlocks'  => array(),
				'innerHTML'    => "<div style='height:${space}px' aria-hidden='true' class='wp-block-spacer'></div>",
				'innerContent' => array( "<div style='height:${space}px' aria-hidden='true' class='wp-block-spacer'></div>" ),
			),
		);
	}

	/**
	 * Generate title block.
	 *
	 * @param integer $index Section index.
	 * @param array   $section Block options.
	 * @return array
	 */
	private function generate_block_title( $index, $section ) {
		if ( ! array_key_exists( 'title', $section ) ) {
			if ( array_key_exists( 'custom_content', $section ) ) {
				return $this->generate_block_paragraph( $index, $section );
			} else {
				$this->log( self::GENERAL_LOG, "Block title without `title` in section $index" );
				return false;
			}
		}

		$title = strip_tags( $section['title'] );

		return array(
			array(
				'blockName'    => 'core/heading',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => "<h2>$title</h2>",
				'innerContent' => array( "<h2>$title</h2>" ),
			),
		);
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

		$block_attrs = array(
			'paddingSyncUnits' => true,
			'height'           => $height,
			'contentAlign'     => 'left',
			'className'        => 'coblocks-hero-' . $block_id,
			'coblocks'         => array(
				'id' => $block_id,
			),
		);

		if ( array_key_exists( 'img_bg', $section ) ) {
			$block_attrs['backgroundImg'] = $section['img_bg'];
		}

		return array(
			array(
				'blockName'    => 'coblocks/hero',
				'attrs'        => $block_attrs,
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/heading',
						'attrs'        => array(
							'placeholder' => 'Add hero heading…',
						),
						'innerBlocks'  => array(),
						'innerHTML'    => "<h2>$title</h2>",
						'innerContent' => array( "<h2>$title</h2>" ),
					),
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array(
							'placeholder' => 'Add hero content, which is typically an introductory area of a page accompanied by call to action or two.',
						),
						'innerBlocks'  => array(),
						'innerHTML'    => '<p></p>',
						'innerContent' => array( '<p></p>' ),
					),
					array(
						'blockName'    => 'core/buttons',
						'attrs'        => array( 'align' => 'left' ),
						'innerBlocks'  => array(
							array(
								'blockName'    => 'core/button',
								'attrs'        => array( 'placeholder' => 'Primary button…' ),
								'innerBlocks'  => array(),
								'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $button_link . '" target="_blank" rel="noreferrer noopener">' . $button_text . '</a></div>',
								'innerContent' => array( '<div class="wp-block-button"><a class="wp-block-button__link" href="' . $button_link . '" target="_blank" rel="noreferrer noopener">' . $button_text . '</a></div>' ),
							),
							array(
								'blockName'    => 'core/button',
								'attrs'        => array(
									'placeholder' => 'Secondary button…',
									'className'   => 'is-style-outline',
								),
								'innerBlocks'  => array(),
								'innerHTML'    => '',
								'innerContent' => array(),
							),
						),
						'innerHTML'    => '<div class="wp-block-buttons"></div>',
						'innerContent' => array( '<div class="wp-block-buttons">', null, '', null, '</div>' ),
					),
				),
				'innerHTML'    => '<div class="wp-block-coblocks-hero alignfull coblocks-hero-' . $block_id . '"><div class="wp-block-coblocks-hero__inner bg-cover has-background-image bg-no-repeat bg-center-center hero-center-left-align has-padding has-huge-padding has-left-content" style="background-image:url(' . $section['img_bg'] . ');min-height:' . $height . 'px"><div class="wp-block-coblocks-hero__content-wrapper"><div class="wp-block-coblocks-hero__content" style="max-width:560px"></div></div></div></div>',
				'innerContent' => array( '<div class="wp-block-coblocks-hero alignfull coblocks-hero-' . $block_id . '"><div class="wp-block-coblocks-hero__inner bg-cover has-background-image bg-no-repeat bg-center-center hero-center-left-align has-padding has-huge-padding has-left-content" style="background-image:url(' . $section['img_bg'] . ');min-height:' . $height . 'px"><div class="wp-block-coblocks-hero__content-wrapper"><div class="wp-block-coblocks-hero__content" style="max-width:560px">', null, '', null, '', null, '</div></div></div></div>' ),
			),
		);
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

		$button_attr = array();

		if ( array_key_exists( 'button_color', $section ) ) {
			$button_attr['style'] = array( 'color' => array( 'background' => $section['button_color'] ) );
		}

		if ( array_key_exists( 'width', $section ) ) {
			$button_attr['width'] = ( intval( $section['width'] ) * 100 ) / 950; // 950 is 100%.
		}

		$button_link = false;

		preg_match( '/<a\shref="(?P<link>[^"]+)"/', $section['button_text'], $matches );
		if ( array_key_exists( 'link', $matches ) ) {
			$button_link = $matches['link'];
		}

		$button_style = array_key_exists( 'button_color', $section ) ? 'background-color:#f69021' : '';

		return array(
			array(
				'blockName'    => 'core/buttons',
				'attrs'        => array( 'align' => 'full' ),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/button',
						'attrs'        => $button_attr,
						'innerBlocks'  => array(),
						'innerHTML'    => '<div class="wp-block-button has-custom-width wp-block-button__width-' . $button_attr['width'] . '"><a class="wp-block-button__link has-background" href="' . $button_link . '" style="' . $button_style . '">' . $button_text . '</a></div>',
						'innerContent' => array( '<div class="wp-block-button has-custom-width wp-block-button__width-' . $button_attr['width'] . '"><a class="wp-block-button__link has-background" href="' . $button_link . '" style="' . $button_style . '">' . $button_text . '</a></div>' ),
					),
				),
				'innerHTML'    => '<div class="wp-block-buttons alignfull"></div>',
				'innerContent' => array(
					'<div class="wp-block-buttons alignfull">',
					null,
					'</div>',
				),
			),
		);
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
				return ! in_array( $meta, array( 'margin_type', 't_margin_type', 'mobile', 'desktop', 'padding_bottom', 'padding_right', 'padding_left', 'padding_type', 'm_padding_top', 'm_padding_bottom', 'm_padding_right', 'm_padding_left', 'm_padding_type', 'border_color', 'border_outer', 'skin_outer', 'm_margin_type', 't_padding_top', 't_padding_bottom', 't_padding_right', 't_padding_left', 't_padding_type', 'meta_background_padding', 'meta_background_img_opacity', 'meta_background_color', 'skin', 'skin_img_opacity', 'skin_color', 'skin_parallax', 'divider_top_onoff', 'divider_bottom_onoff', 'animation_onoff', 'animation_stagger', 'boxed_content' ) );
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
