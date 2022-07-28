<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use Simple_Local_Avatars;
use stdClass;
use \WP_CLI;

class BerkeleysideMigrator implements InterfaceMigrator {

	/**
	 * BerkeleysideMigrator Instance.
	 *
	 * @var BerkeleysideMigrator
	 */
	private static $instance;

	/**
	 * CoAuthorsPlus Helper Class.
	 *
	 * @var CoAuthorPlusMigrator.
	 */
	private $cap_logic;
	private $posts_logic;

	/**
	 * Template mapping, old postmeta value => new postmeta value.
	 *
	 * @var int[] $template_mapping
	 */
	protected array $template_mapping = [
		'page-templates/post_template-single-photo-lead.php' => 'single.php',
		'page-templates/post_template-single-wide.php'  => 'single.php',
		'page-templates/post_template-single-short.php' => 'default',
	];

	/**
	 * Media Credit Mapping for postmeta.
	 *
	 * @var array|string[] $media_credit_mapping
	 */
	protected array $media_credit_mapping = [
		'photo_credit_name'  => 'media_credit',
		'photo_credit_url'   => 'media_credit_url',
		'photo_organization' => 'navis_media_credit_or',
	];

	/**
	 * Custom mapping for custom postmeta types => tags.
	 *
	 * @var array|string[] $postmeta_to_tag_mapping
	 */
	protected array $postmeta_to_tag_mapping = [
		'lead_story_front_page_article' => 'Home: Lead',
		'lead_story_front_page_photo'   => 'Home: Lead Photo',
		'breaking_story'                => 'Home: Breaking',
		'highlight_story'               => 'Home: Highlight',
		'timeline_story'                => 'Home: Timeline',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cap_logic = new CoAuthorPlusLogic();
		$this->posts_logic = new PostsLogic();
	}

	/**
	 * Get Instance.
	 *
	 * @return BerkeleysideMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}


	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-import-postmeta-to-postcontent',
			[ $this, 'cmd_import_postmeta_to_postcontent' ],
			[
				'shortdesc' => 'Takes ACF content in wp_postmeta and transfers it to wp_posts.post_content',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-convert-templates-to-newspack',
			[ $this, 'cmd_convert_templates_to_newspack' ],
			[
				'shortdesc' => 'Looks at a list of previously used templates and updates them to conform to Newspack standard',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-update-acf-media-credit',
			[ $this, 'cmd_update_acf_media_credit' ],
			[
				'shortdesc' => 'Updates a list of postmeta keys to new keys',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-display-updated-date-correctly',
			[ $this, 'cmd_display_updated_date_correctly' ],
			[
				'shortdesc' => 'Looks for Berkeleyside metadata to display updated date correctly on staging site.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-update-related-posts-block',
			[ $this, 'cmd_update_related_posts_block' ],
			[
				'shortdesc' => 'Looks at postmeta for related post information and attempts to recreate it using a post block',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-migrate-user-avatars',
			[ $this, 'cmd_migrate_user_avatars' ],
			[
				'shortdesc' => 'Migrating data from User Profile Picture to Simple Local Avatars',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-replace-postmeta-with-tags',
			[ $this, 'cmd_replace_postmeta_with_tags' ],
			[
				'shortdesc' => 'Takes a list of custom postmeta types (article_type) and converts them to tags. Then associates posts with those tags.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-acf-authors-to-cap',
			[ $this, 'cmd_acf_authors_to_cap' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-acf-authors-to-cap-2',
			[ $this, 'cmd_process_extra_guest_authors' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-add-user-title-to-yoast',
			[ $this, 'cmd_add_user_title_to_yoast' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-remove-dup-content',
			[ $this, 'cmd_remove_duplicate_content' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator berkeleyside-get-specialchars-imgs-and-posts',
			[ $this, 'cmd_get_specialchars_images_and_posts' ],
		);
	}

	public function cmd_get_specialchars_images_and_posts( $args, $assoc_args ) {
		global $wpdb;

		$files = [
			'2013/01/BKGD-How-we-got-here-«-Berkeley-Animal-Welfare-Fund.pdf',
			'2013/11/Gmail-La-Peña-Craft-Fair-Dec-14-Calling-Craftspeople-and-Crafts-Lovers.pdf',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-160x120.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-180x120.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-200x148.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-200x150.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-220x165.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-300x225.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-340x240.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-360x267.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-460x310.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-620x400.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo-720x533.png',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo.png',
			'2015/11/Veteranís-Administration-Building-160x120.jpg',
			'2015/11/Veteranís-Administration-Building-180x120.jpg',
			'2015/11/Veteranís-Administration-Building-200x132.jpg',
			'2015/11/Veteranís-Administration-Building-200x150.jpg',
			'2015/11/Veteranís-Administration-Building-220x165.jpg',
			'2015/11/Veteranís-Administration-Building-300x225.jpg',
			'2015/11/Veteranís-Administration-Building-340x240.jpg',
			'2015/11/Veteranís-Administration-Building-360x238.jpg',
			'2015/11/Veteranís-Administration-Building-460x310.jpg',
			'2015/11/Veteranís-Administration-Building-620x400.jpg',
			'2015/11/Veteranís-Administration-Building-720x476.jpg',
			'2015/11/Veteranís-Administration-Building.jpg',
			'2017/06/Arreguín-Urban-Shield-statement-.rtf',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-1200x900.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-1600x900.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-200x150.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-360x270.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-400x225.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-400x400.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-405x300.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-480x320.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-720x405.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-720x480.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-768x576.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-800x600.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park-900x675.jpg',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-100x150.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-1200x900.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-1600x900.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-240x360.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-400x225.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-400x400.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-405x300.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-480x320.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-720x405.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-720x480.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-768x1151.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-800x600.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli-900x1349.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-1200x630.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-1200x900.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-1600x900.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-200x150.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-360x270.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-400x225.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-400x400.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-405x300.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-480x320.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-720x405.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-720x480.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-768x576.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-800x600.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-900x675.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-e1522184759445.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-1044x783.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-1080x630.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-200x150.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-24x24.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-354x472.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-360x270.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-400x225.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-400x400.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-405x300.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-414x552.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-470x470.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-480x320.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-48x48.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-536x402.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-550x550.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-632x474.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-687x810.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-720x405.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-720x480.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-768x576.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-800x600.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-840x810.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-900x675.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-912x810.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley-96x96.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-1024x630.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-200x133.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-24x24.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-354x472.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-360x239.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-400x225.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-400x400.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-405x300.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-414x552.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-470x470.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-480x320.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-48x48.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-536x402.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-550x550.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-632x474.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-687x680.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-720x405.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-720x480.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-768x510.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-800x600.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-840x680.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-900x598.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-912x680.jpg',
			'2019/04/grégoire_jacquet-5-1024x680-96x96.jpg',
			'2019/04/grégoire_jacquet-5-1024x680.jpg',
			'2019/05/Quince-Café-150x150.jpg',
			'2019/05/Quince-Café-200x133.jpg',
			'2019/05/Quince-Café-24x24.jpg',
			'2019/05/Quince-Café-300x300.jpg',
			'2019/05/Quince-Café-354x472.jpg',
			'2019/05/Quince-Café-360x240.jpg',
			'2019/05/Quince-Café-400x225.jpg',
			'2019/05/Quince-Café-400x400.jpg',
			'2019/05/Quince-Café-405x300.jpg',
			'2019/05/Quince-Café-414x552.jpg',
			'2019/05/Quince-Café-45x45.jpg',
			'2019/05/Quince-Café-470x470.jpg',
			'2019/05/Quince-Café-480x270.jpg',
			'2019/05/Quince-Café-480x320.jpg',
			'2019/05/Quince-Café-48x48.jpg',
			'2019/05/Quince-Café-536x402.jpg',
			'2019/05/Quince-Café-550x550.jpg',
			'2019/05/Quince-Café-632x474.jpg',
			'2019/05/Quince-Café-640x360.jpg',
			'2019/05/Quince-Café-687x600.jpg',
			'2019/05/Quince-Café-720x480.jpg',
			'2019/05/Quince-Café-768x512.jpg',
			'2019/05/Quince-Café-800x450.jpg',
			'2019/05/Quince-Café-800x600.jpg',
			'2019/05/Quince-Café-840x600.jpg',
			'2019/05/Quince-Café-900x600.jpg',
			'2019/05/Quince-Café-96x96.jpg',
			'2019/05/Quince-Café.jpg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-1044x783.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-1104x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-1122x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-1200x630.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-1200x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-150x150.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-200x150.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-24x24.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-300x300.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-354x472.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-360x270.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-400x225.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-400x400.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-405x300.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-414x552.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-45x45.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-470x470.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-480x270.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-480x320.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-48x48.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-536x402.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-550x550.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-632x474.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-640x360.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-687x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-720x480.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-768x576.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-800x450.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-800x600.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-840x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-900x675.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-912x900.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel-96x96.jpeg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel.jpeg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1044x783.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1104x1104.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1122x1208.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1200x630.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1200x900.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1376x1032.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1472x1208.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-150x150.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1600x900.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-1832x1208.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-200x81.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-24x24.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-2800x1208.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-300x300.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-354x472.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-360x145.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-400x400.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-405x300.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-414x552.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-470x470.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-480x270.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-480x320.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-48x48.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-536x402.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-550x550.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-632x474.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-687x916.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-720x480.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-768x309.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-800x450.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-800x600.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-840x1120.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-900x362.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-912x912.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café-96x96.jpg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café.jpg',
			'2019/11/Dorothée-Mitrani-150x150.jpg',
			'2019/11/Dorothée-Mitrani-24x24.jpg',
			'2019/11/Dorothée-Mitrani-48x48.jpg',
			'2019/11/Dorothée-Mitrani-96x96.jpg',
			'2019/11/Dorothée-Mitrani.jpg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-1024x630.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-150x150.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-200x133.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-24x24.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-300x300.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-354x472.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-360x240.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-400x400.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-405x300.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-414x552.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-470x470.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-480x270.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-480x320.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-48x48.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-536x402.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-550x550.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-632x474.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-687x683.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-720x405.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-720x480.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-768x512.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-800x600.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-840x683.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-912x683.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos-96x96.jpeg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-1024x630.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-150x150.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-200x133.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-24x24.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-300x300.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-354x472.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-360x240.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-400x400.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-405x300.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-414x552.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-470x470.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-480x270.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-480x320.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-48x48.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-536x402.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-550x550.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-632x474.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-687x683.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-720x405.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-720x480.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-768x512.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-800x600.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-840x683.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-912x683.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos-96x96.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos.jpeg',
			'2020/12/Fat-Gold-Renée-Vargas-1044x783.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1104x1104.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1122x1496.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-112x150.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1152x1536.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1200x630.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1200x900.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1226x1032.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1226x1374.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1226x1472.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1226x1575.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-1226x900.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-150x150.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-24x24.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-270x360.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-300x300.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-354x472.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-400x400.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-405x300.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-414x552.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-470x470.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-480x270.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-480x320.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-48x48.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-536x402.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-550x550.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-632x474.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-687x916.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-720x405.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-720x480.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-720x960.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-768x1024.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-800x600.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-840x1120.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-912x912.jpg',
			'2020/12/Fat-Gold-Renée-Vargas-96x96.jpg',
			'2020/12/Fat-Gold-Renée-Vargas.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1044x783.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1104x1080.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1122x1080.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1200x630.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1200x900.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1376x1032.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1472x1080.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-150x150.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1536x864.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1600x900.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-1832x1080.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-200x113.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-24x24.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-300x300.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-354x472.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-360x203.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-400x400.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-405x300.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-414x552.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-470x470.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-480x270.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-480x320.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-48x48.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-536x402.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-550x550.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-632x474.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-687x916.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-720x405.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-720x480.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-768x432.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-800x600.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-840x1080.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-912x912.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy-96x96.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy.jpg',
			'2021/09/Jeunée-Simon-Headshot-107x150.jpg',
			'2021/09/Jeunée-Simon-Headshot-150x150.jpg',
			'2021/09/Jeunée-Simon-Headshot-24x24.jpg',
			'2021/09/Jeunée-Simon-Headshot-257x360.jpg',
			'2021/09/Jeunée-Simon-Headshot-300x300.jpg',
			'2021/09/Jeunée-Simon-Headshot-354x472.jpg',
			'2021/09/Jeunée-Simon-Headshot-400x400.jpg',
			'2021/09/Jeunée-Simon-Headshot-405x300.jpg',
			'2021/09/Jeunée-Simon-Headshot-414x552.jpg',
			'2021/09/Jeunée-Simon-Headshot-470x470.jpg',
			'2021/09/Jeunée-Simon-Headshot-480x270.jpg',
			'2021/09/Jeunée-Simon-Headshot-480x320.jpg',
			'2021/09/Jeunée-Simon-Headshot-48x48.jpg',
			'2021/09/Jeunée-Simon-Headshot-536x402.jpg',
			'2021/09/Jeunée-Simon-Headshot-550x550.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x405.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x474.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x480.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x600.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x630.jpg',
			'2021/09/Jeunée-Simon-Headshot-600x783.jpg',
			'2021/09/Jeunée-Simon-Headshot-96x96.jpg',
			'2021/09/Jeunée-Simon-Headshot.jpg',
			'2022/05/Lappé-Cover-Photo-150x150.jpeg',
			'2022/05/Lappé-Cover-Photo-227x270.jpeg',
			'2022/05/Lappé-Cover-Photo-227x300.jpeg',
			'2022/05/Lappé-Cover-Photo-227x320.jpeg',
			'2022/05/Lappé-Cover-Photo-24x24.jpeg',
			'2022/05/Lappé-Cover-Photo-48x48.jpeg',
			'2022/05/Lappé-Cover-Photo-96x96.jpeg',
			'2022/05/Lappé-Cover-Photo-97x150.jpeg',
			'2022/05/Lappé-Cover-Photo.jpeg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-1044x783.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-1104x800.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-1122x800.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-1200x630.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-150x150.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-200x133.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-24x24.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-300x300.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-354x472.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-360x240.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-400x400.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-405x300.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-414x552.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-470x470.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-480x270.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-480x320.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-48x48.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-536x402.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-550x550.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-632x474.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-687x800.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-720x405.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-720x480.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-768x512.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-800x600.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-840x800.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-912x800.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315-96x96.jpg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1044x783.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1104x933.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1122x933.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1200x630.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1200x800.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1200x900.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1376x933.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-1400x900.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-150x150.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-200x133.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-24x24.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-300x300.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-354x472.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-360x240.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-400x400.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-405x300.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-414x552.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-470x470.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-480x270.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-480x320.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-48x48.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-536x402.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-550x550.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-632x474.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-687x916.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-720x405.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-720x480.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-768x512.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-800x600.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-840x933.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-912x912.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114-96x96.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114.jpg',
			'wpforms/303610-ccef9db127fbef8e0abdc73ffc9dae14/ChristinaStapransRésumé-212fb3c0a1a771aa512419e43473f8e6.pdf',
		];

		$files = [
			'2013/01/BKGD-How-we-got-here-«-Berkeley-Animal-Welfare-Fund.pdf',
			'2013/11/Gmail-La-Peña-Craft-Fair-Dec-14-Calling-Craftspeople-and-Crafts-Lovers.pdf',
			'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo.png',
			'2015/11/Veteranís-Administration-Building.jpg',
			'2017/06/Arreguín-Urban-Shield-statement-.rtf',
			'2017/06/Sunday-Morning-Nature-César-Chavez-Park.jpg',
			'2017/08/spok-solo-1-fotógrafo-Edson-Acioli.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-e1522184759445.jpg',
			'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart.jpg',
			'2019/03/Alegio-Chocolaté-Berkeley.jpg',
			'2019/04/grégoire_jacquet-5-1024x680.jpg',
			'2019/05/Quince-Café.jpg',
			'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel.jpeg',
			'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café.jpg',
			'2019/11/Dorothée-Mitrani.jpg',
			'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos.jpeg',
			'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos.jpeg',
			'2020/12/Fat-Gold-Renée-Vargas.jpg',
			'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy.jpg',
			'2021/09/Jeunée-Simon-Headshot.jpg',
			'2022/05/Lappé-Cover-Photo.jpeg',
			'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315.jpg',
			'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114.jpg',
			'wpforms/303610-ccef9db127fbef8e0abdc73ffc9dae14/ChristinaStapransRésumé-212fb3c0a1a771aa512419e43473f8e6.pdf',
		];

		$files_ids = [];
		$files_ids_not_found = [];

		// get att ids
		foreach ( $files as $key_file => $filepath ) {
			// get att id
			// $filepath = '2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy.jpg';

			WP_CLI::log( sprintf( "(%d)/(%d) %s", $key_file + 1, count( $files ), $filepath ) );

			// replace special chars w/ search char
			$filepath_query = $filepath;
			$filepath_query = str_replace( 'é', '%', $filepath_query );
			$filepath_query = str_replace( 'É', '%', $filepath_query );
			$filepath_query = str_replace( 'Á', '%', $filepath_query );
			$filepath_query = str_replace( 'á', '%', $filepath_query );
			$filepath_query = str_replace( 'í', '%', $filepath_query );
			$filepath_query = str_replace( 'Í', '%', $filepath_query );
			$filepath_query = str_replace( 'ñ', '%', $filepath_query );
			$filepath_query = str_replace( 'Ñ', '%', $filepath_query );
			$filepath_query = str_replace( 'ó', '%', $filepath_query );
			$filepath_query = str_replace( 'Ó', '%', $filepath_query );
			$filepath_query = str_replace( 'ú', '%', $filepath_query );
			$filepath_query = str_replace( 'Ú', '%', $filepath_query );
			$filepath_query = str_replace( 'ü', '%', $filepath_query );
			$filepath_query = str_replace( 'Ü', '%', $filepath_query );
			$filepath_query = str_replace( '¿', '%', $filepath_query );
			$filepath_query = str_replace( '¡', '%', $filepath_query );
			$filepath_query = str_replace( '«', '%', $filepath_query );
			$filepath_query = str_replace( '»', '%', $filepath_query );

			$att_id = $wpdb->get_var(
				"select post_id from $wpdb->postmeta
					where meta_key = '_wp_attached_file'
					and meta_value like '$filepath_query'; "
			);
			if ( null == $att_id ) {
				$d = 1;
				$files_ids_not_found[] = $filepath;
				WP_CLI::log( 'not found' );
				continue;
			}

			$files_ids[ $filepath ] = $att_id;
		}

		foreach ( $files_ids_not_found as $file_not_found ) {
			$log .= "fileNotFoundProgrammatically $file_not_found" . "\n";
		}

// $files_ids = array (
// 	'2013/01/BKGD-How-we-got-here-«-Berkeley-Animal-Welfare-Fund.pdf' => '105488',
// 	'2013/11/Gmail-La-Peña-Craft-Fair-Dec-14-Calling-Craftspeople-and-Crafts-Lovers.pdf' => '146613',
// 	'2015/09/Giovanni-García-and-Raymundo-Coronado-of-Mariachi-Mexicanisimo.png' => '203208',
// 	'2015/11/Veteranís-Administration-Building.jpg' => '208299',
// 	'2017/06/Arreguín-Urban-Shield-statement-.rtf' => '262715',
// 	'2017/06/Sunday-Morning-Nature-César-Chavez-Park.jpg' => '261501',
// 	'2017/08/spok-solo-1-fotógrafo-Edson-Acioli.jpg' => '269144',
// 	'2018/03/Fresh-Morning-César-Chávez-Park-Berkeley.-By-Melinda-Stuart-e1522184759445.jpg' => '292471',
// 	'2019/03/Alegio-Chocolaté-Berkeley.jpg' => '327795',
// 	'2019/04/grégoire_jacquet-5-1024x680.jpg' => '331606',
// 	'2019/05/Quince-Café.jpg' => '336955',
// 	'2019/05/Yalis-Café-owners-Leah-and-Ayal-Amzel.jpeg' => '337699',
// 	'2019/07/Dreyers-Grand-Ice-Cream-Parlor-Café.jpg' => '342214',
// 	'2019/11/Dorothée-Mitrani.jpg' => '355237',
// 	'2020/03/Inside-the-closed-Café-Roma-on-College-and-Ashby.-Photo-Pete-Rosos.jpeg' => '364638',
// 	'2020/03/La-Mediterranée-is-open-for-take-out-only-no-seating-March-17-Photo-Pete-Rosos.jpeg' => '364640',
// 	'2020/12/Fat-Gold-Renée-Vargas.jpg' => '398196',
// 	'2021/04/Poly-Styrene_-I-Am-A-Cliché-Search_Still1-Courtesy-of-SFFILM-copy.jpg' => '408764',
// 	'2021/09/Jeunée-Simon-Headshot.jpg' => '430172',
// 	'2022/05/Lappé-Cover-Photo.jpeg' => '453727',
// 	'2022/07/Elena-Estér-Credit-Robbie-Sweeny-A9_03315.jpg' => '461980',
// 	'2022/07/Libby-Oberlin-and-Elena-Estér-and-Linda-Maria-Girón-Credit-Robbie-Sweeny-A9_03114.jpg' => '461981',
// );

		$files_ids;
		$files_ids_not_found;

		$log = '';

		// get posts
// $post_ids = [ 408758 ];
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post = get_post( $post_id );

			$key_file = 0;
			foreach ( $files_ids as $filepath => $att_id ) {

				// WP_CLI::log( sprintf( "   ... (%d)/(%d)", $key_file + 1, count( $files_ids ) ) );

				// search file in post
				$filename_pathinfo     = pathinfo( $filepath );
				$filename_no_extension = $filename_pathinfo['filename'];

				$pos = strpos( $post->post_content, $filename_no_extension );
				if ( false !== $pos ) {
					$log .= "attID $att_id $filepath" . "\n";
					$log .= "  => usedInPostID $post->ID" . "\n";
				}

				// search if it's featured image
				$post_id_used_featured = $wpdb->get_var(
					"select post_id from $wpdb->postmeta
		               where meta_key = '_thumbnail_id' and meta_value = $att_id and post_id = $post_id ; "
				);
				if ( null != $post_id_used_featured ) {
					$log .= "attID $att_id $filepath" . "\n";
					$log .= "  => isFeatImgInPostID $post->ID" . "\n";
				}

				$key_file++;
			}

		}

		file_put_contents( 'special_chars_atts_and_posts.txt', $log );

		$d=1;
	}

	public function cmd_acf_authors_to_cap( $args, $assoc_args ) {
		if ( ! $this->cap_logic->is_coauthors_active() ) {
			WP_CLI::error( 'CAP plugin needs to be active to run this command.' );
		}

		global $wpdb;

		$posts_with_opinion_category = $wpdb->get_results(
			"SELECT 
       				object_id as post_id 
			FROM $wpdb->term_relationships 
			WHERE term_taxonomy_id = (
    			SELECT 
    			       tt.term_taxonomy_id 
    			FROM $wpdb->term_taxonomy tt 
    			    INNER JOIN $wpdb->terms t ON tt.term_id = t.term_id 
    			WHERE t.slug = 'opinion' 
    			  AND tt.taxonomy = 'category'
			)"
		);

		$count_of_posts = count( $posts_with_opinion_category );
		foreach ( $posts_with_opinion_category as $key_post_id => $row ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, $count_of_posts, $row->post_id ) );

			$post                        = get_post( $row->post_id );
			$meta_opinionator_author     = get_post_meta( $row->post_id, 'opinionator_author', true );
			$meta_opinionator_author_bio = get_post_meta( $row->post_id, 'opinionator_author_bio', true );

			// If no GA user to create by name, skip.
			if ( empty( $meta_opinionator_author ) ) {
				$this->log( 'berkeleyside__meta_empty_authorname.log', sprintf( '%d %s %s', $row->post_id, $meta_opinionator_author, $meta_opinionator_author_bio ), false );
				WP_CLI::log( '  x skipped, empty author name' );
				continue;
			}

			// If no bio, WP User Author should be enough as it is.
			if ( empty( $meta_opinionator_author_bio ) ) {
				$this->log( 'berkeleyside__meta_empty_authorbio.log', sprintf( '%d %s %s', $row->post_id, $meta_opinionator_author, $meta_opinionator_author_bio ), false );
				WP_CLI::log( '  x skipped, empty author bio' );
				continue;
			}

			WP_CLI::log( "  Author: $meta_opinionator_author" );

			$exploded = explode( ',', $meta_opinionator_author );

			$leaders = [
				'and ',
				'by ',
			];

			$bio_html = '<div class="opinion-bio">' . $meta_opinionator_author_bio . '</div>';
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->posts SET post_content = CONCAT(post_content, '<br>', %s) WHERE ID = %d",
					$bio_html,
					$post->ID
				)
			);

			$full_names_to_process = [];
			while ( $particle = array_shift( $exploded ) ) {
				$particle = trim( $particle );

				$obliterated = explode( ' and ', $particle );
				if ( count( $obliterated ) > 1 ) {
					$particle = array_shift( $obliterated );
					$exploded = array_merge( $exploded, $obliterated );
				}

				foreach ( $leaders as $leader ) {
					if ( str_starts_with( $particle, $leader ) ) {
						$particle = substr( $particle, strlen( $leader ) );
						$particle = trim( $particle );
					}

					if ( str_ends_with( $particle, '.' ) ) {
						$particle = substr( $particle, -1 );
					}
				}

				$particle = preg_replace( '/\s\s+/', ' ', $particle );

				if ( ! empty( $particle ) ) {
					$full_names_to_process[] = $particle;
				}
			}

			foreach ( $full_names_to_process as $key => $full_name ) {
				WP_CLI::log( "    full_name: $full_name" );
				$exploded_name = explode( ' ', $full_name );
				$last_name     = array_pop( $exploded_name );
				$first_name    = implode( ' ', $exploded_name );

				if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
					$user = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT 
	                            sub.user_id
							FROM (
				                  SELECT 
				                        GROUP_CONCAT(DISTINCT user_id) as user_id, 
				                         COUNT(umeta_id) as counter
				                  FROM $wpdb->usermeta
				                  WHERE (meta_key = 'first_name' AND meta_value = '%s')
				                     OR (meta_key = 'last_name' AND meta_value = '%s')
				                  GROUP BY user_id
				                  HAVING counter = 2
						) as sub WHERE LOCATE( sub.user_id, ',' ) = 0",
							$first_name,
							$last_name
						)
					);

					if ( ! is_null( $user ) ) {
						WP_CLI::log( '      USER EXISTS!' );
						$user_description = $wpdb->get_row(
							"SELECT 
       						umeta_id, 
       						meta_value 
						FROM $wpdb->usermeta 
						WHERE user_id = $user->user_id 
						  AND meta_key = 'description' 
						  AND meta_value = ''"
						);

						/*if ( is_null( $user_description ) ) {
							$wpdb->insert(
								$wpdb->usermeta,
								[
									'user_id'    => $user->user_id,
									'meta_key'   => 'description',
									'meta_value' => $meta_opinionator_author_bio
								]
							);
						} else {
							$wpdb->update(
								$wpdb->usermeta,
								[
									'meta_value' => $meta_opinionator_author_bio,
								],
								[
									'umeta_id' => $user_description->umeta_id,
								]
							);
						}*/
					} else {
						WP_CLI::log( '      CREATING GUEST AUTHOR.' );
						// Get/Create GA.
						$ga_id = $this->cap_logic->create_guest_author(
							[
								'display_name' => $full_name,
								'first_name'   => $first_name,
								'last_name'    => $last_name,
							]
						);

						// Assign GA.
						$append = 0 !== $key;
						$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID, $append );
					}
				}
			}

			/*// Get WP Author user.
			$user = get_user_by( 'id', $post->post_author );

			$guest_author_data = [
				'display_name' => $meta_opinionator_author,
				'description'  => $meta_opinionator_author_bio,
			];


			$user_avatar = get_user_meta( $user->ID, 'simple_local_avatar', true );

			if ( ! empty( $user_avatar ) ) {
				$guest_author_data['avatar'] = $user_avatar['media_id'];
			}

			// Get/Create GA.
			$ga_id = $this->cap_logic->create_guest_author( $guest_author_data );

			// Link WP User and GA.
			$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $user );

			// Assign GA.
			$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID );*/
		}

		WP_CLI::log( 'Done.' );
	}

	public function cmd_process_extra_guest_authors() {
		global $wpdb;

		$posts_with_guest_authors = $wpdb->get_results(
			"SELECT
				       sub.post_id,
				       GROUP_CONCAT(sub.meta_value ORDER BY meta_key SEPARATOR '|' ) as guest_authors
				FROM (
				    SELECT 
				           * 
				    FROM $wpdb->postmeta 
				    WHERE meta_key IN (
                          'guest_contributors_0_guest_contributor_full_name',
                          'guest_contributors_1_guest_contributor_full_name',
                          'guest_contributors_2_guest_contributor_full_name'
				    ) AND meta_value != '') as sub
				LEFT JOIN $wpdb->posts p ON sub.post_id = p.ID
				WHERE p.ID IS NOT NULL
				GROUP BY sub.post_id"
		);

		foreach ( $posts_with_guest_authors as $row ) {
			WP_CLI::log( 'POST ID: ' . $row->post_id );
			$leaders = [
				'and ',
				'by ',
			];

			$exploded = explode( '|', $row->guest_authors );

			$full_names_to_process = [];
			while ( $particle = array_shift( $exploded ) ) {
				$particle = trim( $particle );

				$obliterated = explode( ' and ', $particle );
				if ( count( $obliterated ) > 1 ) {
					$particle = array_shift( $obliterated );
					$exploded = array_merge( $exploded, $obliterated );
				}

				foreach ( $leaders as $leader ) {
					if ( str_starts_with( $particle, $leader ) ) {
						$particle = substr( $particle, strlen( $leader ) );
						$particle = trim( $particle );
					}

					if ( str_ends_with( $particle, '.' ) ) {
						$particle = substr( $particle, -1 );
					}
				}

				$particle = preg_replace( '/\s\s+/', ' ', $particle );

				if ( ! empty( $particle ) ) {
					$full_names_to_process[] = $particle;
				}
			}

			foreach ( $full_names_to_process as $key => $full_name ) {
				WP_CLI::log( "    full_name: $full_name" );
				$exploded_name = explode( ' ', $full_name );
				$last_name     = array_pop( $exploded_name );
				$first_name    = implode( ' ', $exploded_name );

				if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
					$user = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT 
	                            sub.user_id
							FROM (
				                  SELECT 
				                        GROUP_CONCAT(DISTINCT user_id) as user_id, 
				                         COUNT(umeta_id) as counter
				                  FROM $wpdb->usermeta
				                  WHERE (meta_key = 'first_name' AND meta_value = '%s')
				                     OR (meta_key = 'last_name' AND meta_value = '%s')
				                  GROUP BY user_id
				                  HAVING counter = 2
						) as sub WHERE LOCATE( sub.user_id, ',' ) = 0",
							$first_name,
							$last_name
						)
					);

					if ( ! is_null( $user ) ) {
						WP_CLI::log( '      USER EXISTS!' );
					} else {
						WP_CLI::log( '      CREATING GUEST AUTHOR.' );
						// Get/Create GA.
						$ga_id = $this->cap_logic->create_guest_author(
							[
								'display_name' => $full_name,
								'first_name'   => $first_name,
								'last_name'    => $last_name,
							]
						);

						// Assign GA.
						$append = 0 !== $key;
						$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $row->post_id, $append );
					}
				}
			}

			$wpdb->query(
				"UPDATE 
    				$wpdb->postmeta 
				SET meta_key = CONCAT( '0.', meta_key) 
				WHERE post_id = $row->post_id 
				  AND meta_key IN (
				        'guest_contributors_0_guest_contributor_full_name',
				        'guest_contributors_1_guest_contributor_full_name',
				        'guest_contributors_2_guest_contributor_full_name'
				)"
			);
		}
	}

	public function cmd_import_postmeta_to_postcontent( $args, $assoc_args ) {
		global $wpdb;
		$target_slug = 'news-wire';

		$target_category_term_taxonomy_id = $wpdb->get_row(
			"SELECT 
       			tt.term_taxonomy_id 
			FROM $wpdb->term_taxonomy tt 
			    LEFT JOIN $wpdb->terms t ON tt.term_id = t.term_id
			WHERE t.slug = '$target_slug'"
		);
		$target_category_term_taxonomy_id = $target_category_term_taxonomy_id->term_taxonomy_id;

		$posts_associated_with_category_sql = "SELECT 
       		p.ID, 
       		p.post_content, 
       		GROUP_CONCAT(CONCAT(pm.meta_key, ':', pm.meta_value) ORDER BY pm.meta_key SEPARATOR '|') as meta_fields
		FROM $wpdb->posts p LEFT JOIN $wpdb->postmeta pm on p.ID = pm.post_id
		WHERE p.ID IN (
		    SELECT 
		           tr.object_id 
		    FROM $wpdb->term_relationships tr 
		    WHERE tr.term_taxonomy_id = $target_category_term_taxonomy_id
		    )
		AND pm.meta_key LIKE 'wire_stories_%'
		OR pm.meta_key = '_wp_page_template'
		AND p.post_content NOT LIKE '%wire-stories-list%'
		GROUP BY p.ID";

		$posts_associated_with_category = $wpdb->get_results( $posts_associated_with_category_sql );

		$html_template      = '<em>Heads up: We sometimes link to sites that limit access for non-subscribers.</em><ul class="wire-stories-list">{list_items}</ul>';
		$list_item_template = '<li><a href="{url}" target="_blank">{description}</a> {source}</li>';
		foreach ( $posts_associated_with_category as $post ) {
			$links       = [];
			$meta_fields = explode( '|', $post->meta_fields );

			$had_short_template = array_filter( $meta_fields, fn( $field ) => '_wp_page_template:default' == $field );
			if ( ! empty( $post->post_content ) || ! empty( $had_short_template ) ) {
				continue;
			}

			foreach ( $meta_fields as $meta_field ) {
				$exploded_meta_fields = explode( ':', $meta_field );
				$key                  = $exploded_meta_fields[0];
				$value                = $exploded_meta_fields[1] ?? null;
				$array_key            = substr( $key, 0, strlen( 'wire_stories_' ) + 2 );

				$attributes = [];

				if ( str_ends_with( $key, 'story_link' ) ) {
					$attributes['{url}'] = $value;
				} elseif ( str_ends_with( $key, 'story_source' ) ) {
					$attributes['{source}'] = "($value)";
				} elseif ( str_ends_with( $key, 'story_title' ) ) {
					$attributes['{description}'] = $value;
				}

				if ( array_key_exists( $array_key, $links ) ) {
					$links[ $array_key ] = strtr( $links[ $array_key ], $attributes );
				} else {
					$links[ $array_key ] = strtr( $list_item_template, $attributes );
				}
			}

			$list_items = implode( "\n", array_filter( $links, fn( $link ) => ! str_contains( $link, '{description}' ) ) );

			$html = strtr( $html_template, [ '{list_items}' => $list_items ] );

			$post_content = $html;
			if ( ! empty( $post->post_content ) ) {
				$post_content = "$post->post_content<br>$html";
			}

			WP_CLI::line( 'Updating Post ID:' . $post->ID );
			$wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $post_content,
				],
				[
					'ID' => $post->ID,
				]
			);

			$row = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = 'newspack_featured_image_position' AND post_id = $post->ID" ) ;

			if ( is_null( $row ) ) {
				$wpdb->insert(
					$wpdb->postmeta,
					[
						'meta_key' => 'newspack_featured_image_position',
						'meta_value' => 'large',
						'post_id' => $post->ID,
					]
				);
			} else {
				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_value' => 'large',
					],
					[
						'meta_id' => $row->meta_id,
					]
				);
			}
		}
	}

	public function cmd_convert_templates_to_newspack( $args, $assoc_args ) {
		global $wpdb;

		foreach ( $this->template_mapping as $old_template => $new_template ) {
			WP_CLI::line( $old_template );

			$posts_with_old_template = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT 
	                            ID, 
	                            post_title, 
	                            post_date, 
	                            post_status, 
	                            post_type 
							FROM wp_live_posts 
							WHERE ID IN (
	                            SELECT 
	                                   post_id 
	                            FROM wp_live_postmeta 
	                            WHERE meta_key = %s 
	                              AND meta_value = %s
	                        )',
					'_wp_page_template',
					$old_template
				)
			);

			foreach ( $posts_with_old_template as $post ) {
				$corresponding_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT 
	                            ID 
							FROM $wpdb->posts 
							WHERE post_title = %s
							  AND post_date = %s
							  AND post_status = %s
							  AND post_type = %s",
						$post->post_title,
						$post->post_date,
						$post->post_status,
						$post->post_type
					)
				);

				if ( ! is_null( $corresponding_post ) ) {
					WP_CLI::line( "$post->ID => $corresponding_post->ID" );

					$row = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_wp_page_template' AND post_id = $corresponding_post->ID" );

					if ( is_null( $row ) ) {
						$wpdb->insert(
							$wpdb->postmeta,
							[
								'meta_key' => '_wp_page_template',
								'meta_value' => $new_template,
								'post_id' => $corresponding_post->ID,
							]
						);
					} else {
						$wpdb->update(
							$wpdb->postmeta,
							[
								'meta_value' => $new_template,
							],
							[
								'meta_id' => $row->meta_id,
							]
						);
					}

					$row2 = $wpdb->get_row( "SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = 'newspack_featured_image_position' AND post_id = $corresponding_post->ID" );

					if ( is_null( $row2 ) ) {
						$wpdb->insert(
							$wpdb->postmeta,
							[
								'meta_key' => 'newspack_featured_image_position',
								'meta_value' => 'above',
								'post_id' => $corresponding_post->ID,
							]
						);
					} else {
						$wpdb->update(
							$wpdb->postmeta,
							[
								'meta_value' => 'above',
							],
							[
								'meta_id' => $row2->meta_id,
							]
						);
					}
				} else {
					WP_CLI::line( "$posts_with_old_template->ID X" );
				}
			}
		}
	}

	public function cmd_update_acf_media_credit( $args, $assoc_args ) {
		global $wpdb;

		$timestamp = gmdate( 'Ymd_His', time() );
		$file_path = "/tmp/updated_acf_media_credit_$timestamp.txt";

		$results = [];
		foreach ( $this->media_credit_mapping as $old_key => $new_key ) {
			$old_key_count = $wpdb->get_row( "SELECT COUNT(*) AS counter, GROUP_CONCAT( meta_id ) as meta_ids FROM $wpdb->postmeta WHERE meta_key = '$old_key'" );
			file_put_contents( $file_path, "$old_key: $old_key_count->meta_ids\n", FILE_APPEND );
			$old_key_count = $old_key_count->counter;

			$new_key_count = $wpdb->get_row( "SELECT COUNT(*) AS counter, GROUP_CONCAT( meta_id ) as meta_ids FROM $wpdb->postmeta WHERE meta_key = '_$new_key'" );
			file_put_contents( $file_path, "$new_key: $new_key_count->meta_ids\n", FILE_APPEND );
			$new_key_count = $new_key_count->counter;

			$updated_count = $wpdb->query( "UPDATE $wpdb->postmeta SET meta_key = REPLACE( meta_key, '$old_key', '_$new_key') WHERE meta_key = '$old_key'" );
			if ( is_numeric( $updated_count ) ) {
				$formatted_updated_count = number_format( $updated_count );
				file_put_contents( $file_path, "Updated: $formatted_updated_count\n", FILE_APPEND );
			}

			$results[] = [
				'old_key'         => $old_key,
				'old_key_count'   => number_format( $old_key_count ),
				'new_key'         => $new_key,
				'new_key_count'   => number_format( $new_key_count ),
				'updated_count'   => number_format( $updated_count ),
				'new_and_updated' => number_format( $new_key_count + $updated_count ),
			];
		}

		WP_CLI\Utils\format_items(
			'table',
			$results,
			[
				'old_key',
				'old_key_count',
				'new_key',
				'new_key_count',
				'updated_count',
				'new_and_updated',
			]
		);
	}

	public function cmd_display_updated_date_correctly( $args, $assoc_args ) {
		global $wpdb;

		$post_ids_with_updated_time = $wpdb->get_results(
			"SELECT 
       			post_id 
			FROM $wpdb->postmeta 
			WHERE meta_key = 'display_updated_date_and_time' 
			  AND meta_value = 1"
		);
		$post_ids_with_updated_time = array_map( fn( $row ) => $row->post_id, $post_ids_with_updated_time );

		WP_CLI::line( 'Count of posts with updated time: ' . count( $post_ids_with_updated_time ) );

		$post_ids_without_updated_time_sql = "SELECT 
       			p.ID 
			FROM $wpdb->posts p 
			LEFT JOIN (
			    SELECT post_id, MAX(meta_key) AS meta_key, MAX(meta_value) AS meta_value
			    FROM $wpdb->postmeta
			    WHERE meta_key = 'newspack_hide_updated_date'
			    GROUP BY post_id
			) as sub ON p.ID = sub.post_id
			WHERE p.post_type = 'post'
			  AND sub.post_id IS NULL";

		if ( ! empty( $post_ids_with_updated_time ) ) {
			$post_ids_with_updated_time_concatenated = implode( ',', $post_ids_with_updated_time );
			$post_ids_without_updated_time_sql      .= " AND p.ID NOT IN ($post_ids_with_updated_time_concatenated)";
		}
		$post_ids_without_updated_time = $wpdb->get_results( $post_ids_without_updated_time_sql );

		$interval     = 300;
		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Inserting Newspack Hide Updated Date data', count( $post_ids_without_updated_time ) );
		while ( ! empty( $post_ids_without_updated_time ) ) {
			$interval --;

			if ( 0 == $interval ) {
				sleep( 2 );
				$interval = 300;
			}

			$post = array_shift( $post_ids_without_updated_time );

			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => $post->ID,
					'meta_key'   => 'newspack_hide_updated_date',
					'meta_value' => '1',
				]
			);
			$progress_bar->tick();
		}
		$progress_bar->finish();

		// This should only be run when the site is in production.
		/*
		$result = $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = 'display_updated_date_and_time' AND post_id IN ($post_ids_with_updated_time_concatenated)" );

		if ( is_numeric( $result ) ) {
			WP_CLI::line( "Count of deleted posts with old postmeta of updated time: $result" );
		}*/
	}

	public function cmd_update_related_posts_block( $args, $assoc_args ) {
		global $wpdb;

		$deleted_empty_postmeta = $wpdb->query(
			"DELETE FROM $wpdb->postmeta 
				WHERE meta_key = 'berkeleyside_related-post-by-id' 
				  AND meta_value = ''"
		);

		if ( is_numeric( $deleted_empty_postmeta ) ) {
			$formatted_deleted_empty_postmeta = number_format( $deleted_empty_postmeta );
			WP_CLI::line( "Deleted $formatted_deleted_empty_postmeta empty related post meta rows" );
		}

		$post_ids_with_related_posts = $wpdb->get_results(
			"SELECT 
       				post_id, 
       				meta_id,
       				meta_value 
				FROM $wpdb->postmeta 
				WHERE meta_key = 'berkeleyside_related-post-by-id'
					AND meta_value != ''"
		);

		$count_post_ids_with_related_posts = count( $post_ids_with_related_posts );
		$old_related_post_ids              = [];
		$progress_bar                      = WP_CLI\Utils\make_progress_bar( 'Processing related post data', $count_post_ids_with_related_posts );
		foreach ( $post_ids_with_related_posts as &$post ) {
			$separator = ', ';

			if ( str_contains( $post->meta_value, ',' ) ) {
				$separator = ',';
			} elseif ( str_contains( $post->meta_value, '. ' ) ) {
				$separator = '. ';
			}

			$old_post_ids = explode( $separator, $post->meta_value );

			foreach ( $old_post_ids as $key => $old_post_id ) {
				$old_post_id = trim( $old_post_id );
				if ( is_numeric( $old_post_id ) ) {
					$old_post_id = (int) $old_post_id;
					$old_post_ids[ $key ] = $old_post_id;
					$old_related_post_ids[ $old_post_id ] = null;
				} else {
					unset( $old_post_ids[ $key ] );
				}
			}

			$post->meta_value = $old_post_ids;
			$progress_bar->tick();
		}
		$progress_bar->finish();

		$old_to_new_post_ids = [];
		if ( ! empty( $old_related_post_ids ) ) {
			$old_to_new_post_ids = $this->get_old_to_new_post_ids( array_keys( $old_related_post_ids ) );
		}

		$block_template = '<!-- wp:newspack-blocks/homepage-articles 
		{
			"showExcerpt":false,
			"showDate":false,
			"showAuthor":false,
			"showAvatar":false,
			"postLayout":"grid",
			"specificPosts":[{post_ids}],
			"typeScale":2,
			"sectionHeader":"Related stories",
			"specificMode":true
		} /-->';

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Inserting homepage block to posts', $count_post_ids_with_related_posts );
		$updated      = 0;
		foreach ( $post_ids_with_related_posts as $post ) {
			$new_post_ids = [];
			foreach ( $post->meta_value as $old_post_id ) {
				if ( is_numeric( $old_post_id ) && array_key_exists( $old_post_id, $old_to_new_post_ids ) ) {
					$new_post_ids[] = $old_to_new_post_ids[ (int) $old_post_id ]->new_post_id;
				}
			}

			if ( ! empty( $new_post_ids ) ) {
				$new_post_ids_concatenated = implode( ',', $new_post_ids );
				$block                     = strtr(
					$block_template,
					[
						'{post_ids}' => $new_post_ids_concatenated,
					]
				);

				$result = $wpdb->query(
					$wpdb->prepare(
						'UPDATE $wpdb->posts 
							SET post_content = CONCAT(post_content, %1$s, %2$s) 
							WHERE ID = %3$d',
						'<br>',
						$block,
						$post->post_id
					)
				);

				if ( is_numeric( $result ) ) {
					$updated ++;
					$wpdb->delete(
						$wpdb->postmeta,
						[
							'meta_id' => $post->meta_id,
						]
					);
				}
			}

			$progress_bar->tick();
		}
		$progress_bar->finish();

		WP_CLI::line( "Total posts with related posts: $count_post_ids_with_related_posts" );
		WP_CLI::line( "Total posts updated: $updated" );
	}

	public function cmd_migrate_user_avatars( $args, $assoc_args ) {
		global $wpdb;

		$users_and_posts_with_avatars = $wpdb->get_results(
			"SELECT 
       			sub.umeta_id, 
       			sub.user_id, 
       			p.ID as post_id 
			FROM (
    			SELECT * FROM wp_live_usermeta 
    			WHERE meta_key = 'wp_live_metronet_image_id' 
    			  AND meta_value != 0 
    			  AND meta_value NOT LIKE '0.%') as sub
			LEFT JOIN wp_live_posts p ON p.ID = sub.meta_value
			WHERE p.ID IS NOT NULL"
		);

		$old_to_new_post_ids = $this->get_old_to_new_post_ids( array_map( fn( $row ) => $row->post_id, $users_and_posts_with_avatars ) );

		$simple_avatars_class = new Simple_Local_Avatars();

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Creating Simple Local Avatar data', count( $users_and_posts_with_avatars ) );
		foreach ( $users_and_posts_with_avatars as $user_and_post_with_avatar ) {
			if ( $old_to_new_post_ids[ $user_and_post_with_avatar->post_id ] ) {
				$simple_avatars_class->assign_new_user_avatar(
					$old_to_new_post_ids[ $user_and_post_with_avatar->post_id ]->new_post_id,
					$user_and_post_with_avatar->user_id
				);

				// Updating, instead of deleting. Making it so it's not possible to obtain correct Post ID.
				$wpdb->update(
					'wp_live_usermeta',
					[
						'meta_value' => "0.$user_and_post_with_avatar->post_id",
					],
					[
						'umeta_id' => $user_and_post_with_avatar->umeta_id,
					]
				);
			}
			$progress_bar->tick();
		}
		$progress_bar->finish();
	}

	public function cmd_replace_postmeta_with_tags( $args, $assoc_args ) {
		global $wpdb;

		$meta_values = [];

		foreach ( $this->postmeta_to_tag_mapping as $meta_value => $tag ) {
			$meta_values[] = "'$meta_value'";

			$tag = wp_create_tag( $tag );

			$tag = $tag['term_taxonomy_id'];

			$this->postmeta_to_tag_mapping[ $meta_value ] = $tag;
		}

		if ( ! empty( $meta_values ) ) {
			$meta_values = implode( ',', $meta_values );

			$postmeta_with_target_types = $wpdb->get_results(
				"SELECT
				       *
				FROM $wpdb->postmeta
				WHERE meta_key = 'article_type'
				  AND meta_value IN ($meta_values)
				ORDER BY post_id DESC"
			);

			$progress_bar = WP_CLI\Utils\make_progress_bar( 'Associating Posts to Tags', count( $postmeta_with_target_types ) );
			foreach ( $postmeta_with_target_types as $postmeta ) {
				$wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $postmeta->post_id,
						'term_taxonomy_id' => $this->postmeta_to_tag_mapping[ $postmeta->meta_value ],
					]
				);

				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_value' => "0.$postmeta->meta_value",
					],
					[
						'meta_id' => $postmeta->meta_id,
					]
				);

				if ( 'lead_story_front_page_photo' === $postmeta->meta_value ) {
					update_post_meta( $postmeta->post_id, 'newspack_featured_image_position', 'above' );
				}

				$progress_bar->tick();
			}

			$progress_bar->finish();
		}
	}

	public function cmd_remove_duplicate_content(  ) {
		global $wpdb;

		$posts = $wpdb->get_results( "SELECT ID, post_content FROM wp_fp7b3e_posts WHERE post_content LIKE '%\"specificPosts\":%'" );

		foreach ( $posts as $post ) {
			WP_CLI::log( "POST ID: $post->ID" );
			$dom = new \DOMDocument();
			@$dom->loadHTML( $post->post_content );

			$count = 0;
			$body = $dom->lastChild->firstChild;
			foreach ( $body->childNodes as $child ) {
				/* @var \DOMNode $child */
				if ( '#comment' === $child->nodeName && str_contains( $child->nodeValue, 'specificPosts' ) ) {
					$count ++;

					if ( $count > 1 ) {
						WP_CLI::log( 'FOUND DUPE' );
						$body->removeChild( $child );
					}
				}
			}

			if ( $count > 1 ) {
				$content = $dom->saveHTML( $body );

				if ( str_starts_with( $content, '<body>' ) ) {
					$content = substr( $content, 6 );
				}

				if ( str_ends_with( $content, '</body>' ) ) {
					$content = substr( $content, 0, -7 );
				}

				WP_CLI::log( 'UPDATING POST CONTENT' );
				var_dump($content);
				$result = $wpdb->query( "UPDATE $wpdb->posts SET post_content = '$content' WHERE ID = $post->ID" );
				if ( $result >= 1 ) {
					WP_CLI::colorize('%gSuccess!%n');
				} else {
					WP_CLI::colorize('%rFailed%n');
				}
			}
		}
	}

	public function cmd_add_user_title_to_yoast( $args, $assoc_args ) {
		global $wpdb;

		$users_and_titles = $wpdb->get_results(
			"SELECT 
       			user_id, 
       			meta_value 
			FROM $wpdb->usermeta 
			WHERE meta_key = 'berkeleyside_title' 
			  AND meta_value != ''"
		);

		$progress_bar  = WP_CLI\Utils\make_progress_bar( 'Updating User Meta with Job Title', count( $users_and_titles ) );
		$updated_users = [];
		foreach ( $users_and_titles as $user_and_title ) {
			$meta = get_user_meta( $user_and_title->user_id, 'wpseo_user_schema', true );

			$updated_user = [
				'User_ID'       => $user_and_title->user_id,
				'Title'         => $user_and_title->meta_value,
				'Previous_Meta' => $meta,
			];

			if ( is_array( $meta ) ) {
				$meta['jobTitle'] = $user_and_title->meta_value;

				$wpdb->update(
					$wpdb->usermeta,
					[
						'meta_key'   => 'wpseo_user_schema',
						'meta_value' => serialize( $meta ),
					],
					[
						'user_id'  => $user_and_title->user_id,
						'meta_key' => 'wpseo_user_schema',
					]
				);
			} else {
				$meta = [ 'jobTitle' => $user_and_title->meta_value ];

				$wpdb->insert(
					$wpdb->usermeta,
					[
						'user_id'    => $user_and_title->user_id,
						'meta_key'   => 'wpseo_user_schema',
						'meta_value' => serialize( $meta ),
					]
				);
			}

			$updated_user['Updated_Meta'] = $meta;
			$updated_users[] = $updated_user;
			$progress_bar->tick();
		}
		$progress_bar->finish();

		WP_CLI\Utils\format_items( 'table', $updated_users, [ 'User_ID', 'Title', 'Previous_Meta', 'Updated_Meta' ] );
	}

	/**
	 * Converts Post IDs from "live" tables to corresponding Post IDs from staging/production tables.
	 *
	 * @param int[] $old_post_ids Array of old post IDs from "live" table.
	 *
	 * @return array|object|stdClass[]|null
	 */
	private function get_old_to_new_post_ids( array $old_post_ids ) {
		if ( empty( $old_post_ids ) ) {
			return [];
		}

		global $wpdb;

		$old_related_post_ids_concatenated = implode( ',', $old_post_ids );

		return $wpdb->get_results(
			"SELECT
							sub.ID as old_post_id,
							p.ID as new_post_id
						FROM $wpdb->posts p
						LEFT JOIN (
						    SELECT 
						           ID, 
						           post_name, 
						           post_date, 
						           post_type, 
						           post_status
						    FROM wp_live_posts
						    WHERE ID IN ($old_related_post_ids_concatenated)						    
						) as sub 
						    ON sub.post_name = p.post_name 
						           AND sub.post_date = p.post_date 
						           AND sub.post_type = p.post_type 
						           AND sub.post_status = p.post_status
						WHERE sub.ID IS NOT NULL",
			OBJECT_K
		);
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file File name or path.
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
