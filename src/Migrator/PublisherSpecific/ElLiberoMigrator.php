<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use WP_CLI;

class ElLiberoMigrator implements InterfaceMigrator {

	/**
	 * ElLiberoMigrator Singleton.
	 *
	 * @var ElLiberoMigrator $instance
	 */
	private static $instance;

	/**
	 * Custom post_type map to Category Name.
	 *
	 * @var string[] $post_type_to_category_name_map
	 */
	private $post_type_to_category_name_map = array(
		'actualidad'         => 'Actualidad',
		'alerta'             => 'Alerta Líbero',
		'audio'              => 'Podcast',
		'aviso-legal'        => 'Avisos Legales',
		'banner'             => 'Banners',
		'bconstitucional'    => 'Biblioteca constitucional',
		'carta'              => 'Cartas',
		'club-lectura'       => 'Club de lectura',
		'columnista'         => 'Columnistas',
		'expedicion'         => 'Expediciones',
		'gps-libero'         => 'GPS Líbero',
		'informes-redlibero' => 'Informes de inteligencia (Red Libero)',
		'lomejor-redlibero'  => 'Lo mejor de lo nuestro (Red Libero)',
		'mirada-libero'      => 'Mirada Líbero',
		'opinion'            => 'Opinión',
		'podcast-redlibero'  => 'Podcast (Red Libero)',
		'red-libero'         => 'Red Líbero',
		'seleccion'          => 'Lo mejor de la prensa',
		'tiempo-libre'       => 'Tiempo Libre',
		'video'              => 'Líbero TV',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get Instance.
	 *
	 * @return ElLiberoMigrator
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
			'newspack-content-migrator migrate-el-libero-posts',
			array( $this, 'handle_post_migration' ),
			array(
				'shortdesc' => 'Will handle post_type migration to categories',
				'synopsis'  => array(),
			),
		);
	}

	/**
	 * Custom data migration handler.
	 */
	public function handle_post_migration() {
		global $wpdb;

		foreach ( $this->post_type_to_category_name_map as $post_type => $category_name ) {
			WP_CLI::line( "Processing Category: $post_type" );

			// Converting Post Types to Categories.
			$category = get_category_by_slug( $post_type );

			// If Category doesn't exist, create it.
			if ( ! $category ) {
				WP_CLI::line( "Creating new Category: $post_type" );

				$category_id = wp_insert_category(
					array(
						'cat_name'          => $category_name, // Category Name.
						'category_nicename' => $post_type, // Category Slug.
					)
				);

				if ( ! $category_id ) {
					WP_CLI::error( "FAILED TO CREATE CATEGORY: {$post_type}" );
					exit();
				}

				$category = get_term( $category_id );
			}

			// Get all posts with post_type.
			$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = '{$post_type}'" );

			WP_CLI::line( 'Linking ' . count( $post_ids ) . ' posts to new category.' );

			foreach ( $post_ids as $post_id ) {
				wp_set_post_categories( $post_id, $category->term_id, true );
			}

			WP_CLI::line( "Updating '{$post_type}' to 'post'" );

			// Update post_type to new value.
			$wpdb->update( $wpdb->posts, array( 'post_type' => 'post' ), array( 'post_type' => $post_type ) );
		}

		WP_CLI::success( 'Done' );
	}
}
