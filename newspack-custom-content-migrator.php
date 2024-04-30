<?php
/**
 * Plugin Name: Newspack Custom Content Migrator
 * Description: A set of tools in CLI environment to assist during a Newspack site content migration.
 * Plugin URI:  https://newspack.blog/
 * Author:      Automattic
 * Author URI:  https://newspack.blog/
 * Version:     1.5.3
 *
 * @package  Newspack_Custom_Content_Migrator
 */

namespace NewspackCustomContentMigrator;

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require __DIR__ . '/vendor/autoload.php';
require_once ABSPATH . 'wp-settings.php';

PluginSetup::configure_error_reporting();
PluginSetup::register_ticker();
PluginSetup::register_migrators(
	array(
		// General.
		Command\General\PostsMigrator::class,
		Command\General\MetaToContentMigrator::class,
		Command\General\MenusMigrator::class,
		Command\General\CssMigrator::class,
		Command\General\ContentConverterPluginMigrator::class,
		Command\General\SettingsMigrator::class,
		Command\General\WooCommMigrator::class,
		Command\General\ReaderRevenueMigrator::class,
		Command\General\CampaignsMigrator::class,
		Command\General\ListingsMigrator::class,
		Command\General\InlineFeaturedImageMigrator::class,
		Command\General\SubtitleMigrator::class,
		Command\General\CoAuthorPlusMigrator::class,
		Command\General\CPTMigrator::class,
		Command\General\AdsMigrator::class,
		Command\General\NewslettersMigrator::class,
		Command\General\TaxonomyMigrator::class,
		Command\General\ReusableBlocksMigrator::class,
		Command\General\SportsPressMigrator::class,
		Command\General\FeaturedImagesMigrator::class,
		Command\General\ContentDiffMigrator::class,
		Command\General\WooCommOrdersAndSubscriptionsMigrator::class,
		Command\General\NextgenGalleryMigrator::class,
		Command\General\TablePressMigrator::class,
		Command\General\NinjaTablesMigrator::class,
		Command\General\PhotoAlbumProGalleryMigrator::class,
		Command\General\S3UploadsMigrator::class,
		Command\General\AttachmentsMigrator::class,
		Command\General\PDFEmbedderMigrator::class,
		Command\General\ContentFixerMigrator::class,
		Command\General\XMLMigrator::class,
		Command\General\PrelaunchSiteQAMigrator::class,
		Command\General\VillageMediaCMSMigrator::class,
		Command\General\MetroMigrator::class,
		Command\General\ProfilePress::class,
		Command\General\Ras::class,
		Command\General\TownNewsMigrator::class,
		Command\General\UsersMigrator::class,
		Command\General\EmbarcaderoMigrator::class,
		Command\General\ChorusCmsMigrator::class,
		Command\General\LedeMigrator::class,
		Command\General\DownloadMissingImages::class,
		Command\General\MigrationHelper::class,
		Command\General\MolonguiAutorship::class,
		Command\General\MediumMigrator::class,
		Command\General\CreativeCircleMigrator::class,
		Command\General\BlockTransformerCommand::class,
		Command\General\PostDateMigrator::class,
		Command\General\SimplyGuestAuthorNameMigrator::class,
		Command\General\TagDivThemesPluginsMigrator::class,

		// Publisher specific, remove when launched.
		Command\PublisherSpecific\SoccerAmericaMigrator::class,
		Command\PublisherSpecific\LaSillaVaciaMigrator::class,
		Command\PublisherSpecific\NewsroomNZMigrator::class,
		Command\PublisherSpecific\LatinFinanceMigrator::class,
		Command\PublisherSpecific\PCIJMigrator::class,
		Command\PublisherSpecific\InsightCrimeMigrator::class,
		Command\PublisherSpecific\DallasExaminerMigrator::class,
		Command\PublisherSpecific\TheCityMigrator::class,
		Command\PublisherSpecific\LinkNYCMigrator::class,
		Command\PublisherSpecific\WindyCityMigrator::class,
		Command\PublisherSpecific\TheFriscMigrator::class,
		Command\PublisherSpecific\CityViewMigrator::class,
		Command\PublisherSpecific\BigBendSentinelMigrator::class,
		Command\PublisherSpecific\LAFocusMigrator::class,
		Command\PublisherSpecific\TheFifthEstateMigrator::class,
	)
);
