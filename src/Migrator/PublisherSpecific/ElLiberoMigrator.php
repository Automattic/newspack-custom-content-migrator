<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use stdClass;
use WP_CLI;
use WP_Error;
use WP_User;

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
		WP_CLI::add_command(
			'newspack-content-migrator migrate-el-libero-authors',
			array( $this, 'handle_authors_migration' ),
			array(
				'shortdesc' => 'Will handle `autor` data migration to create Authors.',
				'synopsis'  => array(),
			)
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

	public function handle_authors_migration() {
		global $wpdb;

		$list_of_authors_sql = "SELECT sub.meta_value, GROUP_CONCAT(DISTINCT sub.post_id) as post_ids FROM (
                  SELECT *
                  FROM $wpdb->postmeta pm
                  WHERE pm.meta_key = 'autor'
              ) as sub GROUP BY sub.meta_value ORDER BY sub.meta_value";

		$list_of_authors = $wpdb->get_results( $list_of_authors_sql );

		$unprocesssable = [];

		foreach ( $list_of_authors as $record ) {
			if ( ! empty( $record->meta_value ) ) {
				if ( intval( $record->meta_value ) ) {
					try {
						$user_id = $this->handle_author_via_post( $record->meta_value );

						if ( ! empty( $record->post_ids ) ) {
							$update_post_author_sql = "UPDATE $wpdb->posts SET post_author = $user_id WHERE ID IN ($record->post_ids)";
							$wpdb->query( $update_post_author_sql );
						}
					} catch ( Exception $e ) {
						$unprocesssable[] = $record;
						continue;
					}
				} else {
					$user = username_exists( $this->nicename( $record->meta_value ) );

					if ( $user ) {
						$update_post_author_sql = "UPDATE $wpdb->posts SET post_author = $user WHERE ID IN ($record->post_ids)";
					} else {
						$user_id = wp_insert_user(
							[
								'display_name'  => $record->meta_value,
								'user_nicename' => $this->nicename( $record->meta_value ),
								'user_login'    => $this->nicename( $record->meta_value ),
								'user_pass'     => wp_generate_password(),
							]
						);

						if ( $user_id instanceof WP_Error ) {
							$unprocesssable[] = $record;
							continue;
						}

						$update_post_author_sql = "UPDATE $wpdb->posts SET post_author = $user_id WHERE ID IN ($record->post_ids)";
					}
					$wpdb->query( $update_post_author_sql );
				}
			}
		}

		if ( ! empty( $unprocesssable ) ) {
			file_put_contents( __DIR__ . '/unprocessable_author_posts.json', json_encode( $unprocesssable ) );
		}
	}

	/**
	 * Function to handle the creation of an author via a post ID.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int|void|WP_Error
	 * @throws Exception
	 */
	private function handle_author_via_post( int $post_id ) {
		global $wpdb;

		$post_meta_sql = "SELECT REPLACE(meta_key, '-', '') as meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = $post_id AND meta_key IN ('nombre', 'apellido', 'e-mail')";
		$post_meta     = $wpdb->get_results( $post_meta_sql );

		if ( empty( $post_meta ) ) {
			throw new Exception( "Post doesn't exist." );
		}

		$user_object = $this->transpose_to_object( $post_meta );

		$user_name_sql = "SELECT post_name FROM $wpdb->posts WHERE ID = $post_id";
		$user_name     = $wpdb->get_results( $user_name_sql );
		$user_name     = array_shift( $user_name );
		$user_name     = $user_name->post_name;

		$user = get_user_by( 'email', $user_object->email );

		if ( $user instanceof WP_User ) {
			return $user->ID;
		}

		if ( $user = username_exists( $user_name ) ) {
			return $user;
		}

		$user_created = wp_insert_user(
			[
				'user_login'    => $user_name,
				'user_email'    => $user_object->email,
				'first_name'    => $user_object->nombre,
				'last_name'     => $user_object->apellido,
				'display_name'  => "$user_object->nombre $user_object->apellido",
				'user_nicename' => $this->nicename( "$user_object->nombre $user_object->apellido" ),
				'user_pass'     => wp_generate_password(),
			]
		);

		if ( ! ( $user_created instanceof WP_Error ) ) {
			return $user_created;
		}
	}

	/**
	 * Take array and convert to standard class/object.
	 *
	 * @param array $result Array.
	 *
	 * @return stdClass
	 */
	private function transpose_to_object( array $result ): stdClass {
		$obj           = new stdClass();
		$obj->email    = '';
		$obj->nombre   = '';
		$obj->apellido = '';

		foreach ( $result as $property ) {
			$prop       = $property->meta_key;
			$obj->$prop = $property->meta_value;
		}

		return $obj;
	}

	/**
	 * Takes a string and tries to create username.
	 *
	 * @param string $username Username.
	 *
	 * @return string
	 */
	private function nicename( string $username ): string {
		return substr( str_replace( ' ', '-', strtolower( $username ) ), 0, 50 );
	}
}
