<?php

namespace NewspackCustomContentMigratorTest\DataProviders;

class DataProviderGutenbergBlocks {

	/**
	 * Returns an <img> element.
	 *
	 * @param int $id Img att.
	 *
	 * @return string HTML.
	 */
	public function get_img_element( $id ) {
		$html_id_placeholder = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="%s" class="wp-image-%d"/>
HTML;

		return sprintf( $html_id_placeholder, $id, $id );
	}

	/**
	 * @param int    $id  Image attachment ID.
	 * @param string $src Optional.
	 *
	 * @return string
	 */
	public function get_gutenberg_image_block( $id, $src = null ) {
		if ( is_null( $src ) ) {
			$src = "https://newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22244107023566-2-1200x800.jpg";
		}

		$block_placeholder = <<<HTML
<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="%s" alt="" class="wp-image-%d"/><figcaption>Caption text</figcaption></figure>
<!-- /wp:image -->
HTML;

		return sprintf( $block_placeholder, $id, $src, $id );
	}

	/**
	 * @param int    $id  Audio file attachment ID.
	 * @param string $src src attribute.
	 *
	 * @return string
	 */
	public function get_gutenberg_audio_block( $id, $src ) {
		$block_placeholder_sprintf = <<<HTML
<!-- wp:audio {"id":%d} -->
<figure class="wp-block-audio"><audio controls src="%s"></audio></figure>
<!-- /wp:audio -->
HTML;

		return sprintf( $block_placeholder_sprintf, $id, $src );
	}

	/**
	 * @param int    $id  Video file attachment ID.
	 * @param string $src src attribute.
	 *
	 * @return string
	 */
	public function get_gutenberg_video_block( $id, $src ) {
		$block_placeholder_sprintf = <<<HTML
<!-- wp:video {"id":%d} -->
<figure class="wp-block-video"><video controls src="%s"></video></figure>
<!-- /wp:video -->
HTML;

		return sprintf( $block_placeholder_sprintf, $id, $src );
	}

	/**
	 * @param int    $id  File attachment ID.
	 * @param string $src src attribute.
	 *
	 * @return string
	 */
	public function get_gutenberg_file_block( $id, $src ) {
		$block_placeholder_sprintf = <<<HTML
<!-- wp:file {"id":%d,"href":"%s"} -->
<div class="wp-block-file"><a id="wp-block-file--media-1b32a8dc-27e7-4af8-b4e3-f14348bb6889" href="%s">link text</a><a href="%s" class="wp-block-file__button" download aria-describedby="wp-block-file--media-1b32a8dc-27e7-4af8-b4e3-f14348bb6889">Download</a></div>
<!-- /wp:file -->
HTML;

		return sprintf( $block_placeholder_sprintf, $id, $src, $src, $src );
	}

	/**
	 * @param int    $id  Cover image attachment ID.
	 * @param string $src Cover image src.
	 *
	 * @return string
	 */
	public function get_gutenberg_cover_block( $id, $src ) {
		$block_placeholder_sprintf = <<<HTML
<!-- wp:cover {"url":"%s","id":%d,"dimRatio":50,"isDark":false} -->
<div class="wp-block-cover is-light"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background wp-image-%d" alt="" src="%s" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:paragraph {"align":"center","placeholder":"Write title…","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:cover -->
HTML;

		return sprintf( $block_placeholder_sprintf, $src, $id, $id, $src );
	}

	/**
	 * @param int    $mediaId   Block mediaId attribute.
	 * @param string $mediaLink Block mediaLink attribute.
	 * @param string $img_src   Image HTML element src attribute
	 * @param string $text      Text.
	 *
	 * @return string
	 */
	public function get_gutenberg_mediatext_block( $mediaId, $mediaLink, $img_src, $text ) {
		$block_placeholder_sprintf = <<<HTML
<!-- wp:media-text {"mediaId":%d,"mediaLink":"%s","mediaType":"image"} -->
<div class="wp-block-media-text alignwide is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="%s" alt="" class="wp-image-%d size-full"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph {"placeholder":"Content…"} -->
<p>%s</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:media-text -->
HTML;

		return sprintf( $block_placeholder_sprintf, $mediaId, $mediaLink, $img_src, $mediaId, $text );
	}

	/**
	 * @param array $img_ids
	 * @param array $img_data_links
	 * @param array $img_data_urls
	 * @param array $img_srcs
	 *
	 * @return string
	 */
	public function get_gutenberg_jetpacktiledgallery_block( $img_ids, $img_data_links, $img_data_urls, $img_srcs ) {
		if ( count( $img_ids ) !== count( $img_srcs ) ) {
			throw new \RuntimeException( '$img_ids and $img_srcs counts are different.' );
		}

		$block_outer_placeholder_sprintf = <<<HTML
<!-- wp:jetpack/tiled-gallery {"columnWidths":[["40.03600","59.96400"]],"ids":[%s]} -->
<div class="wp-block-jetpack-tiled-gallery aligncenter is-style-rectangular"><div class="tiled-gallery__gallery"><div class="tiled-gallery__row">%s</div></div></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		$blocks_images = '';
		foreach ( $img_ids as $key => $img_id ) {
			$img_data_link = $img_data_links[ $key ];
			$img_data_url = $img_data_urls[ $key ];
			$img_src = $img_srcs[ $key ];
			// sprintf() doesn't work here, reports unknown format specifiers, for Block's usage of "%".
			$blocks_images .= <<<HTML
<div class="tiled-gallery__col" style="flex-basis:40.03600%"><figure class="tiled-gallery__item"><img    bbbbbbb=""   alt="" data-height="600" data-id="$img_id" data-link="$img_data_link" data-url="$img_data_url" data-width="600" src="$img_src" data-amp-layout="responsive"/></figure></div>
HTML;
		}

		$block = sprintf( $block_outer_placeholder_sprintf, implode( ',', $img_ids ), $blocks_images );

		return $block;
	}

	/**
	 * Temporary content storage funcion, will be refactored into proper fixtures by the time the PR is submitted.
	 *
	 * @return void
	 */
	public function get_all_coloradosun_blocks_examples() {
		$blocks_examples = <<<HTML
<!-- wp:jetpack/slideshow {"ids":[285120,285111],"sizeSlug":"large"} -->
<div class="wp-block-jetpack-slideshow aligncenter" data-effect="slide"><div class="wp-block-jetpack-slideshow_container swiper-container"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper"><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-285120" data-id="285120" src="https://newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/Larry.jpg"/></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-285111" data-id="285111" src="https://newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22224706871232-2-1200x800.jpg"/><figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">Mary Peltola is shown leaving a voting booth while early voting on Friday, Aug. 12, 2022, in Anchorage, Alaska. Peltola, a Democrat, faces Republicans Nick Begich and Sarah Palin Tuesday in a special election to fill the remainder of the U.S. House term left vacant by Don Young's death in March. Peltola is also a candidate in Tuesday's primary for a full two-year term for the House seat. (AP Photo/Mark Thiessen)</figcaption></figure></li></ul><a class="wp-block-jetpack-slideshow_button-prev swiper-button-prev swiper-button-white" role="button"></a><a class="wp-block-jetpack-slideshow_button-next swiper-button-next swiper-button-white" role="button"></a><a aria-label="Pause Slideshow" class="wp-block-jetpack-slideshow_button-pause" role="button"></a><div class="wp-block-jetpack-slideshow_pagination swiper-pagination swiper-pagination-white"></div></div></div>
<!-- /wp:jetpack/slideshow -->

<!-- wp:jetpack/image-compare {"imageBefore":{"id":285109,"url":"https://i2.wp.com/newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22244107146324-scaled.jpg?ssl=1","alt":"","width":2560,"height":1707},"imageAfter":{"id":285108,"url":"https://i1.wp.com/newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22244107146324-1-scaled.jpg?ssl=1","alt":"","width":2560,"height":1707}} -->
<figure class="wp-block-jetpack-image-compare"><div class="juxtapose" data-mode="horizontal"><img id="285109" src="https://i2.wp.com/newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22244107146324-scaled.jpg?ssl=1" alt="" width="2560" height="1707" class="image-compare__image-before"/><img id="285108" src="https://i1.wp.com/newspack-coloradosun.s3.amazonaws.com/wp-content/uploads/2022/09/AP22244107146324-1-scaled.jpg?ssl=1" alt="" width="2560" height="1707" class="image-compare__image-after"/></div></figure>
<!-- /wp:jetpack/image-compare -->
HTML;

	}

	/**
	 * Gets a Gutenberg Gallery block with three images.
	 *
	 * @param int $id1 Att ID 1.
	 * @param int $id2 Att ID 2.
	 * @param int $id3 Att ID 3.
	 *
	 * @return string HTML.
	 */
	public function get_gutenberg_gallery_block_w_3_images( $id1, $id2, $id3 ) {
		$html_id_placeholder = <<<HTML
<!-- wp:gallery {"linkTo":"none"} -->
<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" class="wp-image-%d"/><figcaption>Garrett Williams is a senior in environmental science at Haskell Indian Nations University. (Photo provided via Wikimedia Commons)</figcaption></figure>
<!-- /wp:image -->

<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://philomath.test/wp-content/uploads/2022/02/022822-mask-mandate-artwork.png" alt="Woman wearing mask" class="wp-image-%d"/><figcaption>Masks will no longer be required in stores and other public indoor places on March 12. (Photo by Getty Images)</figcaption></figure>
<!-- /wp:image -->

<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://philomath.test/wp-content/uploads/2022/02/022822-scio-covered-bridge.jpeg" alt="Scio covered bridge" class="wp-image-%d"/><figcaption>Scio, known for its covered bridges, could be home to one of the state’s largest broiler chicken production operations. (Photo by Ian Sane/Flickr)</figcaption></figure>
<!-- /wp:image --></figure>
<!-- /wp:gallery -->
HTML;

		return sprintf( $html_id_placeholder, $id1, $id1, $id2, $id2, $id3, $id3 );
	}

	/**
	 * @param array $img_ids  Gallery image IDs.
	 * @param array $img_srcs Gallery image src URLs.
	 *
	 * @return string
	 */
	public function get_gutenberg_gallery_block( $img_ids, $img_srcs ) {
		if ( count( $img_ids ) !== count( $img_srcs ) ) {
			throw new \RuntimeException( 'Number of IDs given in first method argument must be equal to number of srcs in secong method argument.' );
		}

		$gallery_block_outer_placeholder_sprintf = <<<HTML
<!-- wp:gallery {"linkTo":"none"} -->
<figure class="wp-block-gallery has-nested-images columns-default is-cropped">%s</figure>
<!-- /wp:gallery -->
HTML;
		$image_block_placeholder_sprintf = <<<HTML
<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="%s" alt="" class="wp-image-%d"/></figure>
<!-- /wp:image -->
HTML;
		$img_blocks = [];
		foreach ( $img_ids as $key => $img_id ) {
			$img_src = $img_srcs[ $key ];
			$img_blocks[] = sprintf( $image_block_placeholder_sprintf, $img_id, $img_src, $img_id );
		}

		$gallery_block = sprintf( $gallery_block_outer_placeholder_sprintf, implode( "\n\n", $img_blocks ) );

		return $gallery_block;
	}

	/**
	 * Gets a Jetpack Slideshow block with with three images.
	 *
	 * @param int $id1 Att ID 1.
	 * @param int $id2 Att ID 2.
	 * @param int $id3 Att ID 3.
	 *
	 * @return string HTML.
	 */
	public function get_jetpack_slideshow_block( $id1, $id2, $id3 ) {
		$html_placeholders = <<<HTML
<!-- wp:jetpack/slideshow {"ids":[%d,%d,%d],"sizeSlug":"large"} -->
<div class="wp-block-jetpack-slideshow aligncenter" data-effect="slide"><div class="wp-block-jetpack-slideshow_container swiper-container"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper"><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="PHS girls basketball team" class="wp-block-jetpack-slideshow_image wp-image-%d" data-id="%d" src="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_team_0013-1200x843.jpg"/><figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">PHS girls basketball team (Photo by Logan Hannigan-Downs/Philomath News)</figcaption></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="Cassidy Lewis" class="wp-block-jetpack-slideshow_image wp-image-%d" data-id="%d" src="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_lewis_0028-1200x778.jpg"/><figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">Cassidy Lewis (Photo by Logan Hannigan-Downs/Philomath News)</figcaption></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="Reagan Larson" class="wp-block-jetpack-slideshow_image wp-image-%d" data-id="%d" src="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_larson_0040-1200x800.jpg"/><figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">Reagan Larson (Photo by Logan Hannigan-Downs/Philomath News)</figcaption></figure></li><li class="wp-block-jetpack-slideshow_slide swiper-slide"></li></ul><a class="wp-block-jetpack-slideshow_button-prev swiper-button-prev swiper-button-white" role="button"></a><a class="wp-block-jetpack-slideshow_button-next swiper-button-next swiper-button-white" role="button"></a><a aria-label="Pause Slideshow" class="wp-block-jetpack-slideshow_button-pause" role="button"></a><div class="wp-block-jetpack-slideshow_pagination swiper-pagination swiper-pagination-white"></div></div></div>
<!-- /wp:jetpack/slideshow -->
HTML;

		return sprintf( $html_placeholders, $id1, $id2, $id3, $id1, $id1, $id2, $id2, $id3, $id3 );
	}

	/**
	 * Gets a Jetpack Tiled Gallery block with with three images.
	 *
	 * @param int $id1 Att ID 1.
	 * @param int $id2 Att ID 2.
	 * @param int $id3 Att ID 3.
	 *
	 * @return string HTML.
	 */
	public function get_jetpack_tiled_gallery_block( $id1, $id2, $id3 ) {
		$html_placeholders =  <<<HTML
<!-- wp:jetpack/tiled-gallery {"columnWidths":[["71.51704","28.48296"],["37.62035","62.37965"],["33.33333","33.33333","33.33333"],["32.02508","35.94242","32.03250"],["62.48203","37.51797"],["69.17398","30.82602"],["69.16156","30.83844"],["34.68121","32.84397","32.47482"],["69.53806","30.46194"],["45.84734","54.15266"]],"ids":[%d,%d,%d]} -->
<div class="wp-block-jetpack-tiled-gallery aligncenter is-style-rectangular"><div class="tiled-gallery__gallery"><div class="tiled-gallery__row"><div class="tiled-gallery__col" style="flex-basis:71.51704"><figure class="tiled-gallery__item"><img alt="PHS girls basketball team" data-height="1707" data-id="%d" data-link="https://philomathnews-oldlive.newspackstaging.com/021522_gbb_bench_0044/" data-url="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_bench_0044-1200x800.jpg" data-width="2560" src="https://i0.wp.com/philomathnews.com/wp-content/uploads/2022/02/021522_gbb_bench_0044-1200x800.jpg?ssl=1" data-amp-layout="responsive"/></figure></div><div class="tiled-gallery__col" style="flex-basis:28.48296"><figure class="tiled-gallery__item"><img alt="Ingrid Hellesto" data-height="1707" data-id="%d" data-link="https://philomathnews-oldlive.newspackstaging.com/021522_gbb_hellesto_0019/" data-url="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_hellesto_0019-1200x800.jpg" data-width="2560" src="https://i1.wp.com/philomathnews.com/wp-content/uploads/2022/02/021522_gbb_hellesto_0019-1200x800.jpg?ssl=1" data-amp-layout="responsive"/></figure><figure class="tiled-gallery__item"><img alt="PHS girls basketball team" data-height="1798" data-id="%d" data-link="https://philomathnews-oldlive.newspackstaging.com/021522_gbb_team_0013/" data-url="https://philomathnews-oldlive.newspackstaging.com/wp-content/uploads/2022/02/021522_gbb_team_0013-1200x843.jpg" data-width="2560" src="https://i0.wp.com/philomathnews.com/wp-content/uploads/2022/02/021522_gbb_team_0013-1200x843.jpg?ssl=1" data-amp-layout="responsive"/></figure></div></div><div class="tiled-gallery__row"><div class="tiled-gallery__col" style="flex-basis:37.62035"></div></div></div></div>
<!-- /wp:jetpack/tiled-gallery -->
HTML;

		return sprintf( $html_placeholders, $id1, $id2, $id3, $id1, $id2, $id3 );
	}

}
