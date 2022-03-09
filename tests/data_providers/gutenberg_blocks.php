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
	 * Gets a Gutenberg Gallery block with three images.
	 *
	 * @param int $id1 Att ID 1.
	 * @param int $id2 Att ID 2.
	 * @param int $id3 Att ID 3.
	 *
	 * @return string HTML.
	 */
	public function get_gutenberg_gallery_block( $id1, $id2, $id3 ) {
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
