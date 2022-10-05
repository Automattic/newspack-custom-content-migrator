<?php
/**
 * Plugin Name: Newspack Custom Content Migrator
 * Description: A set of tools in CLI environment to assist during a Newspack site content migration.
 * Plugin URI:  https://newspack.blog/
 * Author:      Automattic
 * Author URI:  https://newspack.blog/
 * Version:     1.0.1
 *
 * @package  Newspack_Custom_Content_Migrator
 */

namespace NewspackCustomContentMigrator;

require __DIR__ . '/vendor/autoload.php';

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once ABSPATH . 'wp-settings.php';

PluginSetup::setup_wordpress_importer();
PluginSetup::register_migrators(
	array(
		// General.
		Migrator\General\PostsMigrator::class,
		Migrator\General\MetaToContentMigrator::class,
		Migrator\General\MenusMigrator::class,
		Migrator\General\CssMigrator::class,
		Migrator\General\ContentConverterPluginMigrator::class,
		Migrator\General\SettingsMigrator::class,
		Migrator\General\WooCommMigrator::class,
		Migrator\General\ReaderRevenueMigrator::class,
		Migrator\General\CampaignsMigrator::class,
		Migrator\General\ListingsMigrator::class,
		Migrator\General\InlineFeaturedImageMigrator::class,
		Migrator\General\SubtitleMigrator::class,
		Migrator\General\CoAuthorPlusMigrator::class,
		Migrator\General\CPTMigrator::class,
		Migrator\General\AdsMigrator::class,
		Migrator\General\NewslettersMigrator::class,
		Migrator\General\TaxonomyMigrator::class,
		Migrator\General\ReusableBlocksMigrator::class,
		Migrator\General\SportsPressMigrator::class,
		Migrator\General\FeaturedImagesMigrator::class,
		Migrator\General\ContentDiffMigrator::class,
		Migrator\General\WooCommOrdersAndSubscriptionsMigrator::class,
		Migrator\General\NextgenGalleryMigrator::class,
		Migrator\General\TablePressMigrator::class,
		Migrator\General\NinjaTablesMigrator::class,
		Migrator\General\PhotoAlbumProGalleryMigrator::class,
		Migrator\General\S3UploadsMigrator::class,
		Migrator\General\AttachmentsMigrator::class,
		Migrator\General\PDFEmbedderMigrator::class,
		Migrator\General\ContentFixerMigrator::class,
		Migrator\General\XMLMigrator::class,
		Migrator\General\PrelaunchSiteQAMigrator::class,

		// Publisher specific.
		Migrator\PublisherSpecific\GadisMigrator::class,
		Migrator\PublisherSpecific\ElLiberoMigrator::class,
		Migrator\PublisherSpecific\NoozhawkMigrator::class,
		Migrator\PublisherSpecific\CharlottesvilleTodayMigrator::class,
		Migrator\PublisherSpecific\VoiceOfSanDiegoMigrator::class,
		Migrator\PublisherSpecific\BethesdaMagMigrator::class,
		Migrator\PublisherSpecific\SearchLightNMMigrator::class,
		Migrator\PublisherSpecific\CalMattersMigrator::class,
		Migrator\PublisherSpecific\NewsroomCoNzMigrator::class,
		Migrator\PublisherSpecific\MassterlistMigrator::class,
		Migrator\PublisherSpecific\ColoradoSunMigrator::class,
		Migrator\PublisherSpecific\MustangNewsMigrator::class,
		Migrator\PublisherSpecific\LkldNowMigrator::class,
		Migrator\PublisherSpecific\BGAMigrator::class,
	)
);
