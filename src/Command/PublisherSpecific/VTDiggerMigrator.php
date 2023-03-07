<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Taxonomy as TaxonomyLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for VTDigger.
 */
class VTDiggerMigrator implements InterfaceCommand {

	// VTD CPTs.
	const OBITUARY_CPT = 'obituary';
	const LETTERS_TO_EDITOR_CPT = 'letters_to_editor';
	const LIVEBLOG_CPT = 'liveblog';
	const NEWSBRIEF_CPT = 'news-brief';

	// GAs for CPTs.
	const OBITUARIES_GA_NAME = 'VTD Obituaries';
	const LETTERS_TO_EDITOR_GA_NAME = 'Letters to Editor';

	// VTD Taxonomies.
	const COUNTIES_TAXONOMY = 'counties';
	const SERIES_TAXONOMY = 'series';

	// WP Category names.
	const NEWS_BRIEFS_CAT_NAME = 'News Briefs';
	const LIVEBLOGS_CAT_NAME = 'Liveblogs';
	const LETTERSTOTHEEDITOR_CAT_NAME = 'Letters to the Editor';
	const OBITUARIES_CAT_NAME = 'Obituaries';
	const SERIES_CAT_NAME = 'Series';

	// This postmeta will tell us which CPT this post was originally, e.g. 'liveblog'.
	const META_VTD_CPT = 'newspack_vtd_cpt';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var TaxonomyLogic
	 */
	private $taxonomy_logic;

	/**
	 * @var PostLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $cap_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
		$this->taxonomy_logic = new TaxonomyLogic();
		$this->posts_logic = new PostLogic();
		$this->cap_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator vtdigger-migrate-newsbriefs',
			[ $this, 'cmd_newsbriefs' ],
			[
				'shortdesc' => 'Migrates the News Briefs CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-liveblogs',
			[ $this, 'cmd_liveblogs' ],
			[
				'shortdesc' => 'Migrates the Liveblog CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-letterstotheeditor',
			[ $this, 'cmd_letterstotheeditor' ],
			[
				'shortdesc' => 'Migrates the Letters to the Editor CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-obituaries',
			[ $this, 'cmd_obituaries' ],
			[
				'shortdesc' => 'Migrates the Obituaries CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-counties',
			[ $this, 'cmd_counties' ],
			[
				'shortdesc' => 'Migrates Counties taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-series',
			[ $this, 'cmd_series' ],
			[
				'shortdesc' => 'Migrates Series taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'Migrates ACF Authors to GAs.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-newsbrief`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_newsbriefs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_newsbriefs.log';

		// Get News Briefs category ID.
		$newsbriefs_cat_id = get_cat_ID( self::NEWS_BRIEFS_CAT_NAME );
		if ( ! $newsbriefs_cat_id ) {
			$newsbriefs_cat_id = wp_insert_category( [ 'cat_name' => self::NEWS_BRIEFS_CAT_NAME ] );
		}

		$newsbriefs_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s ;", self::NEWSBRIEF_CPT ) );

		// Convert to 'post' type.
		foreach ( $newsbriefs_ids as $key_newsbrief_id => $newsbrief_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_newsbrief_id + 1, count( $newsbriefs_ids ), $newsbrief_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $newsbrief_id ) );

			// Set meta 'newspack_vtd_cpt' = 'news-brief';
			update_post_meta( $newsbrief_id, self::META_VTD_CPT, self::NEWSBRIEF_CPT );
		}

		$this->logger->log( $log, implode( ',', $newsbriefs_ids ), false );
		wp_cache_flush();

		// Assign category 'News Briefs'.
		WP_CLI::log( sprintf( "Assigning News Briefs cat ID %d ...", $newsbriefs_cat_id ) );
		foreach ( $newsbriefs_ids as $key_newsbrief_id => $newsbrief_id ) {
			wp_set_post_categories( $newsbrief_id, [ $newsbriefs_cat_id ], true );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-liveblogs`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_liveblogs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_liveblog.log';

		// Get Liveblogs category ID.
		$liveblogs_cat_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( ! $liveblogs_cat_id ) {
			$liveblogs_cat_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}

		$liveblogs_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s;", self::LIVEBLOG_CPT ) );

		// Convert to 'post' type.
		foreach ( $liveblogs_ids as $key_liveblog_id => $liveblog_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_liveblog_id + 1, count( $liveblogs_ids ), $liveblog_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $liveblog_id ) );

			// Set meta 'newspack_vtd_cpt' = 'liveblog';
			update_post_meta( $liveblog_id, self::META_VTD_CPT, self::LIVEBLOG_CPT );
		}

		$this->logger->log( $log, implode( ',', $liveblogs_ids ), false );
		wp_cache_flush();

		// Assign category 'Liveblogs'.
		WP_CLI::log( sprintf( "Assigning %s cat ID %d ...", self::LIVEBLOGS_CAT_NAME, $liveblogs_cat_id ) );
		foreach ( $liveblogs_ids as $key_liveblog_id => $liveblog_id ) {
			wp_set_post_categories( $liveblog_id, [ $liveblogs_cat_id ], true );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-letterstotheeditor`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_letterstotheeditor( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_letterstotheeditor.log';

		// Get Letters to the Editor category ID.
		$letters_cat_id = get_cat_ID( self::LETTERSTOTHEEDITOR_CAT_NAME );
		if ( ! $letters_cat_id ) {
			$letters_cat_id = wp_insert_category( [ 'cat_name' => self::LETTERSTOTHEEDITOR_CAT_NAME ] );
		}

		$letters_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::LETTERS_TO_EDITOR_CPT ) );

		// Convert to 'post' type.
		foreach ( $letters_ids as $key_letter_id => $letter_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_letter_id + 1, count( $letters_ids ), $letter_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $letter_id ) );

			// Set meta 'newspack_vtd_cpt' = 'letters_to_editor';
			update_post_meta( $letter_id, self::META_VTD_CPT, self::LETTERS_TO_EDITOR_CPT );
		}

		$this->logger->log( $log, implode( ',', $letters_ids ), false );
		wp_cache_flush();

		// Assign category 'Letters to the Editor'.
		WP_CLI::log( sprintf( "Assigning %s cat ID %d ...", self::LETTERSTOTHEEDITOR_CAT_NAME, $letters_cat_id ) );
		foreach ( $letters_ids as $letter_id ) {
			wp_set_post_categories( $letter_id, [ $letters_cat_id ], true );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-obituaries`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_obituaries( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_obituaries.log';
		$log_error = 'vtd_obituaries_error.log';

		// Get Obituaries category ID.
		$obituaries_cat_id = get_cat_ID( self::OBITUARIES_CAT_NAME );
		if ( ! $obituaries_cat_id ) {
			$obituaries_cat_id = wp_insert_category( [ 'cat_name' => self::OBITUARIES_CAT_NAME ] );
		}

		$obituaries_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type='%s';", self::OBITUARY_CPT ) );
		$obituaries_ids_dev = [
			// _thumbnail_id IDs w/ & wo/
			// 409943,394799,
			// name_of_deceased IDs w/ & wo/
			// 402320,402256,
			// date_of_birth IDs w/ & wo/
			// 402256,401553,
			// city_of_birth IDs w/ & wo/
			// 402256,401553,
			// state_of_birth IDs w/ & wo/
			// 402497, 402320,
			// date_of_death IDs w/ & wo/
			// 384051,384020,
			// city_of_death IDs w/ & wo/
			// 402256,401553,
			// state_of_death IDs w/ & wo/
			// 402497,402320,
			// details_of_services IDs w/ & wo/
			// 402320,402256,
			// obitbiography IDs w/ & wo/
			// 394221,394199,
			// obitfamily_information IDs w/ & wo/
			// 394221,394199,
		];

		// Convert to 'post' type.
		foreach ( $obituaries_ids as $key_obituary_id => $obituary_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_obituary_id + 1, count( $obituaries_ids ), $obituary_id ) );

			// Get all ACF.
			/*
			 * @var $_thumbnail_id E.g. has _thumbnail_id ID 409943, no _thumbnail_id ID 394799.
			 */
			$thumbnail_id = get_post_meta( $obituary_id, '_thumbnail_id', true ) != '' ? get_post_meta( $obituary_id, '_thumbnail_id', true ) : null;
			/*
			 * @var $name_of_deceased E.g. has name_of_deceased ID 402320, no name_of_deceased ID 402256.
			 */
			$name_of_deceased = get_post_meta( $obituary_id, 'name_of_deceased', true ) != '' ? get_post_meta( $obituary_id, 'name_of_deceased', true ) : null;
			/*
			 * @var string|null $date_of_birth E.g. has date_of_birth ID 402256, no date_of_birth ID 401553
			 */
			$date_of_birth = get_post_meta( $obituary_id, 'date_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'date_of_birth', true ) : null;
			/*
			 * @var string|null $city_of_birth E.g. has city_of_birth ID 402256, no city_of_birth ID 401553.
			 */
			$city_of_birth = get_post_meta( $obituary_id, 'city_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'city_of_birth', true ) : null;
			/*
			 * @var string|null $state_of_birth E.g. has state_of_birth ID 402497, no state_of_birth ID 402320.
			 */
			$state_of_birth = get_post_meta( $obituary_id, 'state_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'state_of_birth', true ) : null;
			/*
			 * @var string|null $date_of_death E.g. has date_of_death ID 384051, no date_of_death ID 384020.
			 */
			$date_of_death = get_post_meta( $obituary_id, 'date_of_death', true ) != '' ? get_post_meta( $obituary_id, 'date_of_death', true ) : null;
			/*
			 * @var string|null $city_of_death E.g. has city_of_death ID 402256, no city_of_death ID 401553.
			 */
			$city_of_death = get_post_meta( $obituary_id, 'city_of_death', true ) != '' ? get_post_meta( $obituary_id, 'city_of_death', true ) : null;
			/*
			 * @var string|null $state_of_death E.g. has state_of_death ID 402497, no state_of_death ID 402320.
			 */
			$state_of_death = get_post_meta( $obituary_id, 'state_of_death', true ) != '' ? get_post_meta( $obituary_id, 'state_of_death', true ) : null;
			/*
			 * @var string|null $details_of_services E.g. has details_of_services ID 402320, no details_of_services ID 402256.
			 */
			$details_of_services = get_post_meta( $obituary_id, 'details_of_services', true ) != '' ? get_post_meta( $obituary_id, 'details_of_services', true ) : null;
			/*
			 * @var string|null $obitbiography E.g. has obitbiography ID 394221, no obitbiography ID 394199.
			 */
			$obitbiography = get_post_meta( $obituary_id, 'obitbiography', true ) != '' ? get_post_meta( $obituary_id, 'obitbiography', true ) : null;
			/*
			 * @var string|null $obitfamily_information E.g. has obitfamily_information ID 394221, no obitfamily_information ID 394199.
			 */
			$obitfamily_information = get_post_meta( $obituary_id, 'obitfamily_information', true ) != '' ? get_post_meta( $obituary_id, 'obitfamily_information', true ) : null;

			// Possible characters for replacing for other types of content.
			$not_used_dev = [
				' ' => '',
			];

			$details_of_services = trim( apply_filters( 'the_content', trim( $details_of_services ) ) );
			$details_of_services = str_replace( "\r\n", "\n", $details_of_services );
			$details_of_services = str_replace( "\n", "", $details_of_services );
			$obitbiography = trim( apply_filters( 'the_content', trim( $obitbiography ) ) );
			$obitbiography = str_replace( "\r\n", "\n", $obitbiography );
			$obitbiography = str_replace( "\n", "", $obitbiography );
			$obitfamily_information = trim( apply_filters( 'the_content', trim( $obitfamily_information ) ) );
			$obitfamily_information = str_replace( "\r\n", "\n", $obitfamily_information );
			$obitfamily_information = str_replace( "\n", "", $obitfamily_information );

			$acf_args = [
				'_thumbnail_id' => $thumbnail_id,
				'name_of_deceased' => $name_of_deceased,
				'date_of_birth' => $date_of_birth,
				'city_of_birth' => $city_of_birth,
				'state_of_birth' => $state_of_birth,
				'date_of_death' => $date_of_death,
				'city_of_death' => $city_of_death,
				'state_of_death' => $state_of_death,
				'details_of_services' => $details_of_services,
				'obitbiography' => $obitbiography,
				'obitfamily_information' => $obitfamily_information,
			];
			$acf_additional_args = [
				'submitter_firstname' => get_post_meta( $obituary_id, 'submitter_firstname' ),
				'submitter_lastname' => get_post_meta( $obituary_id, 'submitter_lastname' ),
				'submitter_email' => get_post_meta( $obituary_id, 'submitter_email' ),
				'display_submitter_info' => get_post_meta( $obituary_id, 'display_submitter_info' ),
				'submitter_phone' => get_post_meta( $obituary_id, 'submitter_phone' ),
			];

			// New values.
			$post_content = $this->get_obituary_content( $acf_args );

			// Update to type post, set title and content.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post', post_content='%s' where ID=%d;", $post_content, $obituary_id ) );

			// Set meta 'newspack_vtd_cpt' = self::OBITUARY_CPT;
			update_post_meta( $obituary_id, self::META_VTD_CPT, self::OBITUARY_CPT );
		}

		$this->logger->log( $log, implode( ',', $obituaries_ids ), false );
		wp_cache_flush();

		// Assign category for Obituaries.
		WP_CLI::log( sprintf( "Assigning %s cat ID %d ...", self::OBITUARIES_CAT_NAME, $obituaries_cat_id ) );
		foreach ( $obituaries_ids as $obituary_id ) {
			wp_set_post_categories( $obituary_id, [ $obituaries_cat_id ], true );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * @param array $replacements {
	 *     Keys are search strings, values are replacements. Expected and mandatory keys:
	 *
	 *     @type int|null    $thumbnail_id           Thumbnail ID.
	 *     @type string|null $name_of_deceased       Value for "{{name_of_deceased}}".
	 *     @type string|null $date_of_birth          Value for "{{date_of_birth}}".
	 *     @type string|null $city_of_birth          Value for "{{city_of_birth}}".
	 *     @type string|null $state_of_birth         Value for "{{state_of_birth}}".
	 *     @type string|null $date_of_death          Value for "{{date_of_death}}".
	 *     @type string|null $city_of_death          Value for "{{city_of_death}}".
	 *     @type string|null $state_of_death         Value for "{{state_of_death}}".
	 *     @type string|null $details_of_services    Value for "{{details_of_services}}".
	 *     @type string|null $obitbiography          Value for "{{obitbiography}}".
	 *     @type string|null $obitfamily_information Value for "{{obitfamily_information}}".
	 *
	 * @return void
	 */
	public function get_obituary_content( $replacements ) {
		$log_error = 'vtd_obituaries_template_error.log';

		$post_content = '';

		// Image.
		if ( ! is_null( $replacements['_thumbnail_id'] ) ) {
			$img_template = <<<HTML
<!-- wp:image {"align":"right","id":%d,"width":353,"sizeSlug":"large","linkDestination":"none","className":"is-resized"} -->
<figure class="wp-block-image alignright size-large is-resized"><img src="%s" alt="" class="wp-image-%d" width="353"/></figure>
<!-- /wp:image -->
HTML;
			$src = wp_get_attachment_url( $replacements['_thumbnail_id'] );
			if ( false == $src || empty( $src ) || ! $src ) {
				$this->logger->log( $log_error, sprintf( "not found src for _thumbnail_id %d", $replacements['_thumbnail_id'] ) );
			}

			$wp_image = sprintf( $img_template, $replacements['_thumbnail_id'], $src, $replacements['_thumbnail_id'] );
			$post_content .= $wp_image;
		}

		// name_of_deceased.
		if ( ! is_null( $replacements['name_of_deceased'] ) ) {
			$spaces = <<<HTML


HTML;
			if ( ! empty( $post_content ) ) {
				$post_content .= $spaces;
			}

			$wp_paragraph_template = <<<HTML
<!-- wp:paragraph -->
<p>{{name_of_deceased}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{name_of_deceased}}', $replacements['name_of_deceased'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// date_of_birth, city_of_birth, state_of_birth
		if ( ! is_null( $replacements['date_of_birth'] ) || ! is_null( $replacements['city_of_birth'] ) || ! is_null( $replacements['state_of_birth'] ) ) {

			// The first paragraph goes with or without date of birth, if any of the birth info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Born </strong>{{date_of_birth}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_birth}}', ! is_null( $replacements['date_of_birth'] ) ? $replacements['date_of_birth'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_birth or state_of_birth is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_birth'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_birth'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_birth'] ) ? $replacements['state_of_birth'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// date_of_death, city_of_death, state_of_death
		if ( ! is_null( $replacements['date_of_death'] ) || ! is_null( $replacements['city_of_death'] ) || ! is_null( $replacements['state_of_death'] ) ) {

			// The first paragraph goes with or without date of death, if any of the death info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Died </strong>{{date_of_death}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_death}}', ! is_null( $replacements['date_of_death'] ) ? $replacements['date_of_death'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_death or state_of_death is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_death'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_death'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_death'] ) ? $replacements['state_of_death'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// details_of_services
		if ( ! empty( $replacements['details_of_services'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Details of services</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{details_of_services}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{details_of_services}}', $replacements['details_of_services'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// wp:separator
		$wp_paragraph_template = <<<HTML


<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
HTML;
		$post_content .= $wp_paragraph_template;

		// obitbiography
		if ( ! empty( $replacements['obitbiography'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
{{obitbiography}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitbiography}}', $replacements['obitbiography'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// obitfamily_information
		if ( ! empty( $replacements['obitfamily_information'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Family information</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{obitfamily_information}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitfamily_information}}', $replacements['obitfamily_information'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		return $post_content;
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-counties`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_counties( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_counties.log';

		WP_CLI::log( "Getting or creating category tree..." );
		/**
		 * Fetch or create the destination category tree:
		 *	Regional
		 *		Champlain Valley
		 *			Chittenden County
		 *				Burlington
		 *			Grand Isle County
		 *			Franklin County
		 *			Addison County
		 *		Northeast Kingdom
		 *			Orleans County
		 *			Essex County
		 *			Caledonia County
		 *		Central Vermont
		 *			Washington County
		 *			Lamoille County
		 *			Orange County
		 *		Southern Vermont
		 *			Windsor County
		 *			Rutland County
		 *			Bennington County
		 *			Windham County
		 **/
		// phpcs:disable -- leave this indentation for a more convenient overview
		$regional_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Regional', 0 );
			$champlain_valley_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Champlain Valley', $regional_id );
				$chittenden_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Chittenden County', $champlain_valley_id );
					$burlington_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Burlington', $chittenden_county_id );
				$grand_isle_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Grand Isle County', $champlain_valley_id );
				$franklin_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Franklin County', $champlain_valley_id );
				$addison_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Addison County', $champlain_valley_id );
			$northeast_kingdom_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Northeast Kingdom', $regional_id );
				$orleans_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orleans County', $northeast_kingdom_id );
				$essex_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Essex County', $northeast_kingdom_id );
				$caledonia_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Caledonia County', $northeast_kingdom_id );
			$central_vermont_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Central Vermont', $regional_id );
				$washington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Washington County', $central_vermont_id );
				$lamoille_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Lamoille County', $central_vermont_id );
				$orange_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orange County', $central_vermont_id );
			$southern_vermontt_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Southern Vermont', $regional_id );
				$windsor_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windsor County', $southern_vermontt_id );
				$rutland_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Rutland County', $southern_vermontt_id );
				$bennington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Bennington County', $southern_vermontt_id );
				$windham_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windham County', $southern_vermontt_id );
		// phpcs:enable

		$county_id_to_cat_id = [
			'Addison' => $addison_county_id,
			'Bennington' => $bennington_county_id,
			'Caledonia' => $caledonia_county_id,
			'Chittenden' => $chittenden_county_id,
			'Essex' => $essex_county_id,
			'Franklin' => $franklin_county_id,
			'Grand Isle' => $grand_isle_county_id,
			'Lamoille' => $lamoille_county_id,
			'Orange' => $orange_county_id,
			'Orleans' => $orleans_county_id,
			'Rutland' => $rutland_county_id,
			'Washington' => $washington_county_id,
			'Windham' => $windham_county_id,
			'Windsor' => $windsor_county_id,
		];

		// Get all term_ids, term_taxonomy_ids and term names with 'counties' taxonomy.
		$counties_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::COUNTIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'counties' terms.
		foreach ( $counties_terms as $key_county_term => $county_term ) {
			$term_id = $county_term['term_id'];
			$term_taxonomy_id = $county_term['term_taxonomy_id'];
			$term_name = $county_term['name'];

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s", $key_county_term + 1, count( $counties_terms ), $term_id, $term_taxonomy_id, $term_name ), true );

			// Get all objects for this 'county' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			// Get the destination category.
			$destination_cat_id = $county_id_to_cat_id[$term_name] ?? null;
			// We should have all 'counties' on record. Double check.
			if ( is_null( $destination_cat_id ) ) {
				throw new \RuntimeException( sprintf( "County term_id=%d term_taxonomy_id=%d name=%s is not mapped by the migrator script.", $term_id, $term_taxonomy_id, $term_name ) );
			}

			// Assign the destination category to all objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d to category_id=%d", $object_id, $destination_cat_id ), true );
				wp_set_post_categories( $object_id, [ $destination_cat_id ], true );
			}

			// Remove the custom taxonomy from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-series`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_series( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_series.log';

		// Get Series category ID.
		$series_parent_cat_id = get_cat_ID( self::SERIES_CAT_NAME );
		if ( ! $series_parent_cat_id ) {
			$series_parent_cat_id = wp_insert_category( [ 'cat_name' => self::SERIES_CAT_NAME ] );
		}

		// Get all term_ids, term_taxonomy_ids and term names with 'series' taxonomy.
		$seriess_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::SERIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'series' terms.
		foreach ( $seriess_terms as $key_series_term => $series_term ) {
			$term_id = $series_term['term_id'];
			$term_taxonomy_id = $series_term['term_taxonomy_id'];
			$term_name = $series_term['name'];

			// Get all objects for this 'series' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s count=%d", $key_series_term + 1, count( $seriess_terms ), $term_id, $term_taxonomy_id, $term_name, count( $object_ids ) ), true );
			if ( 0 == count( $object_ids ) ) {
				WP_CLI::log( "0 posts, skipping." );
				continue;
			}

			// Get the destination category.
			$destination_cat_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $term_name, $series_parent_cat_id );

			// Assign the destination category to all objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d to category_id=%d", $object_id, $destination_cat_id ), true );
				wp_set_post_categories( $object_id, [ $destination_cat_id ], true );
			}

			// Remove this term from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-authors`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_authors( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$logs = [
			'post_ids_no_author_names'            => 'vtd_authors__post_ids_no_author_names.log',
			'created_gas_from_acf'                => 'vtd_authors__created_gas_from_acf.log',
			'created_gas_from_wpusers'            => 'vtd_authors__created_gas_from_wpusers.log',
			'post_ids_obituaries'                 => 'vtd_authors__post_ids_obituaries.log',
			'post_ids_letters_to_editor'          => 'vtd_authors__post_ids_letters_to_editor.log',
			'post_ids_no_acfauthor_or_wpuser'     => 'vtd_authors__post_ids_no_acfauthor_or_wpuser.log',
			'assigned_gas_post_ids'               => 'vtd_authors__assigned_gas_post_ids.log',
			'already_assigned_gas_post_ids'       => 'vtd_authors__already_assigned_gas_post_ids.log',
			'post_has_no_authors_at_all'          => 'vtd_authors__post_has_no_authors_at_all.log',
			// DEV helper, things not yet done, just log and skip these:
			'post_ids_was_newsbrief_not_assigned' => 'vtd_authors__post_id_was_newsbrief_not_assigned.log',
			'post_ids_was_liveblog_not_assigned'  => 'vtd_authors__post_id_was_liveblog_not_assigned.log',
		];

		// Local caching var.
		$cached_authors_meta = [];

		// Get/create GA for CPTs.
		$obituaries_ga_id = $this->cap_logic->get_guest_author_by_display_name( self::OBITUARIES_GA_NAME );
		if ( ! $obituaries_ga_id ) {
			$obituaries_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::OBITUARIES_GA_NAME ] );
		}
		$letters_to_editor_ga_id = $this->cap_logic->get_guest_author_by_display_name( self::LETTERS_TO_EDITOR_GA_NAME );
		if ( ! $letters_to_editor_ga_id ) {
			$letters_to_editor_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::LETTERS_TO_EDITOR_GA_NAME ] );
		}


		WP_CLI::log( "Fetching Post IDs..." );
		$post_ids = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		// $post_ids = [410385,410332,410281,410249,]; // DEV test.

		// Loop through all posts and create&assign GAs.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Skip some specific dev IDs.
			if ( in_array( $post_id, [ 410385 ] ) ) {
				WP_CLI::log( "skipping DEV ID" );
				continue;
			}

			// Get if this post used to be a CPT.
			$was_cpt = get_post_meta( $post_id, self::META_VTD_CPT, true );

			// Assign specific GAs to ex CPTs.
			if ( $was_cpt && ( self::OBITUARY_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $obituaries_ga_id ], $post_id);
				$this->logger->log( $logs[ 'post_ids_obituaries' ], sprintf( "OBITUARY post_id=%d", $post_id ), true );
				continue;
			} elseif ( $was_cpt && ( self::LETTERS_TO_EDITOR_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $letters_to_editor_ga_id ], $post_id );
				$this->logger->log( $logs['post_ids_letters_to_editor'], sprintf( "LETTERS_TO_EDITOR post_id=%d", $post_id ), true );
				continue;
			}
			//
			// WIP -- for now just log and skip those CPTs we don't know how to handle yet. Answers will come soon and this part will be refined.
			//
			elseif ( $was_cpt && ( self::NEWSBRIEF_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_newsbrief_not_assigned'], sprintf( "NEWSBRIEF post_id=%d", $post_id ), true );
				WP_CLI::log( 'skipping' );
				continue;
			} elseif ( $was_cpt && ( self::LIVEBLOG_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_liveblog_not_assigned'], sprintf( "LIVEBLOG post_id=%d", $post_id ), true );
				WP_CLI::log( 'skipping' );
				continue;
			}

			/**
			 * Get author names for this post.
			 * Author names are terms with 'author' taxonomy. And the actual data for these authors can either be located in ACF 'vtd_team' Post objects,
			 * or can be regular WP Users.
			 */
			$author_names = $wpdb->get_col(
				$wpdb->prepare(
					"select name
					from {$wpdb->terms} where term_id in (
						select term_id
						from {$wpdb->term_taxonomy} vwtt
						where taxonomy = 'author' and term_taxonomy_id in (
							select term_taxonomy_id
							from {$wpdb->term_relationships} vwtr
							where object_id = %d
						)
					);",
					$post_id
				)
			);
			if ( empty( $author_names ) ) {
				$this->logger->log( $logs['post_ids_no_author_names'], sprintf( "NO_AUTHOR_NAMES___SKIPPING post_id=%d", $post_id ), true );
				// Continue to next $post_id.
				continue;
			}

			/**
			 * Get or create GAs.
			 */
			$ga_ids = [];
			foreach ( $author_names as $author_name ) {
				WP_CLI::log( "author_name=" . $author_name );

				// Get existing GA.
				$ga = $this->cap_logic->get_guest_author_by_display_name( $author_name );
				$ga_id = $ga->ID ?? null;

				// Create GA if it doesn't exist.
				if ( is_null( $ga_id ) ) {

					/**
					 * 1/2 First try and create GA from ACF author Post object with this name.
					 */
					$acf_author_meta = isset( $cached_authors_meta[ $author_name ] )
						? $cached_authors_meta[ $author_name ]
						: $this->get_acf_author_meta( $author_name );
					if ( ! is_null( $acf_author_meta ) ) {
						// Cache entry.
						if ( ! isset( $cached_authors_meta[ $author_name ] ) ) {
							$cached_authors_meta[ $author_name ] = $acf_author_meta;
						}
						// Create GA.
						$ga_id = $this->create_ga_from_acf_author( $author_name, $acf_author_meta );
						$this->logger->log( $logs['created_gas_from_acf'], sprintf( "CREATED_FROM_ACF post_id=%d name='%s' ga_id=%d", $post_id, $author_name, $ga_id ), true );
					}

					/**
					 * 2/2 Next try and create GA from WP User with this display_name.
					 */
					if ( is_null( $ga_id ) ) {
						$ga_id = $this->create_ga_from_wp_user( $author_name );
						// Success.
						if ( ! is_null( $ga_id ) ) {
							$this->logger->log( $logs['created_gas_from_wpusers'], sprintf( "CREATED_FROM_WPUSER ga_id=%d name='%s' post_id=%d linked_wp_user_id=%d", $ga_id, $author_name, $post_id, $wp_user_row['ID'] ), true );
						}
					}
				}

				// Add new or existing.
				$ga_ids[] = $ga_id;
			} // Done foreach $author_names.


			// This is where author creation/assignment failed completely, GA was not created or fetched from known sources.
			if ( empty( $ga_ids ) ) {
				$this->logger->log( $logs['post_ids_no_acfauthor_or_wpuser'], sprintf( "NO_ACF_OR_WPUSER___SKIPPED post_id=%d author_name='%s'", $post_id, $author_name ), true );
				// Continue to next $post_id.
				continue;
			}

			// Assign GAs to post and log all post_ids.
			$existing_post_ga_ids = $this->cap_logic->get_posts_existing_ga_ids( $post_id );
			$new_post_ga_ids = array_unique( array_merge( $existing_post_ga_ids, $ga_ids ) );
			if ( $existing_post_ga_ids != $new_post_ga_ids ) {
				$this->cap_logic->assign_guest_authors_to_post( $new_post_ga_ids, $post_id );
				$this->logger->log( $logs['assigned_gas_post_ids'], sprintf( "NEWLY_ASSIGNED_GAS post_id=%d ga_ids=%s", $post_id, implode( ',', $new_post_ga_ids ) ), true );
			} elseif ( ! empty( $existing_post_ga_ids ) ) {
				$this->logger->log( $logs['already_assigned_gas_post_ids'], sprintf( "ALREADY_ASSIGNED post_id=%d ga_ids=%s", $post_id, implode( ',', $new_post_ga_ids ) ), true );
			} else {
				$this->logger->log( $logs['post_has_no_authors_at_all'], sprintf( "NO_AUTHORS_AT_ALL post_id=%d", $post_id ), true );
			}

		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done, see %s ", implode( ', ', array_keys( $logs ) ) ) );
	}

	/**
	 * Creates GA from WP User with display_name same as $author_name.
	 *
	 * @param string $author_name Display name.
	 *
	 * @return int|null GA ID or null.
	 */
	public function create_ga_from_wp_user( string $author_name ) {
		global $wpdb;

		$wp_user_row = $wpdb->get_row(
			$wpdb->prepare(
				"select * from {$wpdb->users} where display_name = %s; ",
				$author_name
			),
			ARRAY_A
		);

		if ( is_null( $wp_user_row ) ) {
			return null;
		}

		$social_sources = '';
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'aim', true ) )        ? 'AIM: ' .                  get_user_meta( $wp_user_row['ID'], 'aim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'yim', true ) )        ? 'Yahoo IM: ' .             get_user_meta( $wp_user_row['ID'], 'yim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'jabber', true ) )     ? 'Jabber / Google Talk: ' . get_user_meta( $wp_user_row['ID'], 'jabber', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'facebook', true ) )   ? 'Facebook: ' .             get_user_meta( $wp_user_row['ID'], 'facebook', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'instagram', true ) )  ? 'Instagram: ' .            get_user_meta( $wp_user_row['ID'], 'instagram', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'linkedin', true ) )   ? 'LinkedIn: ' .             get_user_meta( $wp_user_row['ID'], 'linkedin', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'myspace', true ) )    ? 'MySpace: ' .              get_user_meta( $wp_user_row['ID'], 'myspace', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'pinterest', true ) )  ? 'Pinterest: ' .            get_user_meta( $wp_user_row['ID'], 'pinterest', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) ) ? 'SoundCloud: ' .           get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'twitter', true ) )    ? 'Twitter: @' .             get_user_meta( $wp_user_row['ID'], 'twitter', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'youtube', true ) )    ? 'YouTube: ' .              get_user_meta( $wp_user_row['ID'], 'youtube', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) )  ? 'Wikipedia: ' .            get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) . '. ' : null;

		$bio = ! empty( get_user_meta( $wp_user_row['ID'], 'description', true ) ) ? get_user_meta( $wp_user_row['ID'], 'description', true ) : null;

		$description = $social_sources
		               . ( ( ! empty( $social_sources ) && ! empty( $bio ) ) ? ' ' : '' )
		               . $bio;

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $wp_user_row['user_email'] ?? null,
			'website'      => $wp_user_row['user_url'] ?? null,
			'description'  => ! empty( $description ) ? $description : null,
			// Their WP Users have an external plugin which extends avatar abilities, but these are not used.
			// 'avatar'       => null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		// Link to WP User.
		$wp_user = get_user_by( 'ID', $wp_user_row['ID'] );
		$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $wp_user );

		return $ga_id;
	}

	/**
	 * @param string $author_name
	 * @param array  $acf_author_meta
	 *
	 * @return int GA ID.
	 */
	public function create_ga_from_acf_author( string $author_name, array $acf_author_meta ) {
		// Compose $media_link for bio.
		$media_link = '';
		if ( $acf_author_meta['vtd_social_media_handle'] && $acf_author_meta['vtd_social_media_link'] ) {
			// $media_link is a <a> element if both handle and link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_handle'] );
		} elseif ( $acf_author_meta['vtd_social_media_link'] ) {
			// $media_link is a <a> element if just link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_link'] );
		} elseif ( $acf_author_meta['vtd_social_media_handle'] ) {
			// $media_link text.
			$media_link = $acf_author_meta['vtd_social_media_handle'];
		}

		// Compose GA description.
		// Start with the title.
		$description = $acf_author_meta['vtd_title'] ? $acf_author_meta['vtd_title'] . '. ' : '';
		// Add media link.
		$description .= $media_link ? $media_link . ' ' : '';
		// Add bio.
		$description .= $acf_author_meta['vtd_bio'] ? $acf_author_meta['vtd_bio'] : '';

		// Leaving out:
		// 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' (e.g. vtd_department e.g. a:1:{i:0;s:8:"newsroom";})

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $acf_author_meta['vtd_email'] ?? null,
			'website'      => $acf_author_meta['vtd_social_media_link'] ?? null,
			'description'  => $description,
			'avatar'       => $acf_author_meta['_thumbnail_id'] ?? null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		return $ga_id;
	}

	/**
	 * Gets ACF vtd_team meta from author name.
	 *
	 * @param string $author_name
	 *
	 * @return array|null
	 */
	public function get_acf_author_meta( string $author_name ) : array|null {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"select ID
				from {$wpdb->posts}
				where post_title = %s and post_type = 'vtd_team'; ",
				$author_name
			)
		);
		if ( ! $post_id ) {
			return null;
		}

		$post_meta = $wpdb->get_row(
			$wpdb->prepare(
				"select meta_key, meta_value
				from {$wpdb->postmeta}
				where post_id = %d 
				and meta_key in ( '_thumbnail_id', 'vtd_email', 'vtd_title', 'vtd_bio', 'vtd_social_media_handle', 'vtd_social_media_link', 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' ) ; ",
				$post_id
			),
			ARRAY_A
		);

		return $post_meta;
	}
}