<?php

namespace NewspackCustomContentMigrator\Builder;

use Exception;

class GutenbergBlockBuilder {
	const ATTRIBUTES = 'attrs';
	const BLOCK_NAME = 'blockName';
	const INNER_BLOCKS = 'innerBlocks';
	const INNER_HTML = 'innerHTML';
	const INNER_CONTENT = 'innerContent';

	public function figure( int $attachment_id, string $url = '', $caption = '', string $size = 'full' ) {
		global $wpdb;

		$query = sprintf(
			"SELECT guid, post_excerpt FROM $wpdb->posts WHERE post_type = 'attachment' AND ID = %d",
			$attachment_id
		);

		$result = null;

		if ( empty( $url ) ) {

			$result = $wpdb->get_row( $query );

			if ( ! $result ) {
				throw new Exception( sprintf( 'Attachment %d does not exist in the database.', $attachment_id ) );
			}

			$url = $result->guid;
		}

		if ( is_bool( $caption ) ) {
			if ( $caption ) {

				if ( is_null( $result ) ) {
					$result = $wpdb->get_row( $query );
				}

				if ( ! $result ) {
					throw new Exception( sprintf( 'Attachment %d does not exist in the database.', $attachment_id ) );
				}

				$caption = $result->post_excerpt;
			} else {
				$caption = '';
			}
		}

		if ( is_string( $caption ) && ! empty( $caption ) ) {
			$caption = "<figcaption>$caption</figcaption>";
		}

		return '<figure '
		        . 'class="wp-block-image" '
		        . 'size-' . $size . '>'
			       . '<img '
			        . 'src="' . $url . '" '
			        . 'alt="" '
			        . 'class="wp-image-' . $attachment_id . '" />'
			            . $caption
		       . '</figure>';
	}

	/**
	 * @param int $attachment_id
	 * @param bool|string $caption Caption to used in HTML, or boolean. If True, DB call will be made to obtain image caption. If false or blank, no call is made, no caption is used.
	 * @param string $size
	 * @param string $link_destination
	 *
	 * @return string
	 * @throws Exception
	 */
	public static function image_block( int $attachment_id, string $url = '', $caption = '', string $size = 'full', string $link_destination = 'none' ): string {
		$content = ( new self() )->figure( $attachment_id, $url, $caption, $size );
		return serialize_block(
			[
				self::BLOCK_NAME => 'core/image',
				self::ATTRIBUTES => [
					'id' => $attachment_id,
					'sizeSlug' => $size,
					'linkDestination' => $link_destination,
				],
				self::INNER_BLOCKS => [],
				self::INNER_HTML => $content,
				self::INNER_CONTENT => [
					$content,
				],
			]
		);
	}

	/**
	 * @param string $content
	 * @param array $inner_blocks
	 *
	 * @return string
	 */
	public static function paragraph_block( string $content, array $inner_blocks = [] ) {
		return serialize_block(
			[
				self::BLOCK_NAME => 'core/paragraph',
				self::ATTRIBUTES => [],
				self::INNER_BLOCKS => $inner_blocks,
				self::INNER_HTML => "<p>$content</p>",
				self::INNER_CONTENT => [
					"<p>$content</p>",
				],
			]
		);
	}

	/**
	 * @param string $title
	 * @param float $longitude
	 * @param float $latitude
	 * @param int $zoom
	 * @param string $caption
	 * @param string $place_title
	 * @param array $inner_blocks
	 *
	 * @return string
	 */
	public static function jetpack_map_block( string $title, float $longitude, float $latitude, int $zoom, string $caption = '', string $place_title = '', string $inner_html = '', array $inner_blocks = [] ) {

		if ( empty( $place_title ) ) {
			$place_title = $title;
		}

		// TODO find out how to generate ID.
		$id = 'TODO';

		$data  = [
				self::BLOCK_NAME => 'jetpack/map',
				self::ATTRIBUTES => [
					'points' => [
						[
							'placeTitle' => $place_title,
							'title' => $title,
							'caption' => $caption,
							'id' => $id,
							'coordinates' => [
								'longitude' => $longitude,
								'latitude' => $latitude,
							],
						],
					],
					'zoom' => $zoom,
					'mapCenter' => [
						'lng' => $longitude,
						'lat' => $latitude,
					],
				],
				self::INNER_BLOCKS => $inner_blocks,
			];

		if ( ! empty( $inner_html ) ) {
			$data[self::INNER_HTML] = $inner_html;
		}

		return serialize_block( $data );
	}
}