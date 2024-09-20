<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\General\CreativeCircleMigrator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use WP_CLI;

class CityViewMigrator implements InterfaceCommand {

	private function __construct() {
		// Do nothing!
	}

	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @inheritDoc
	 * @throws \Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator cityview-scrape',
			[ $this, 'cmd_scrape' ],
			[
				'synopsis' => [
					...CreativeCircleMigrator::$scrape_args,
				],
			]
		);
	}

	/**
	 * Command callback.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_scrape( array $pos_args, array $assoc_args ): void {
		add_filter( 'np_cc_author_details', [ $this, 'filter_np_cc_author_details' ], 10, 2 );
		add_filter( 'np_cc_default_author_id', fn( $id ) => NP_CC_DEFAULT_AUTHOR_ID, 10, 2 );

		CreativeCircleMigrator::get_instance()->cmd_scrape( $pos_args, $assoc_args );
	}

	/**
	 * Filter callback.
	 *
	 * Clean up author display name and fabricate an email if missing.
	 */
	public function filter_np_cc_author_details( $author_details ) {
		static $email_domain, $replacements = null;
		if ( null === $replacements ) {
			$domain       = str_replace( 'www.', '', wp_parse_url( NEWSPACK_SCRAPER_MIGRATOR_SITE_URL, PHP_URL_HOST ) );
			$email_domain = NP_CC_AUTHOR_EMAIL_SUBDOMAIN . '.' . $domain;

			$replacements = [
				'/^(By)/i' => '',
			];
		}

		$massaged_name                  = preg_replace( array_keys( $replacements ), array_values( $replacements ), $author_details['display_name'] );
		$author_details['display_name'] = trim( ucwords( strtolower( $massaged_name ) ) );

		if ( empty( $author_details['display_name'] ) ) {
			$author_details['email'] = '';
		} elseif ( empty( $author_details['email'] ) ) {
			$before_at               = sanitize_user( sanitize_title_with_dashes( $author_details['display_name'] ) );
			$author_details['email'] = sanitize_email( "{$before_at}@{$email_domain}" );
		}

		return $author_details;
	}
}
