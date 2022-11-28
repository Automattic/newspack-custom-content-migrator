<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use stdClass;
use \WP_CLI;
use WP_Error;
use WP_Term;

/**
 * This custom migrator was necessary because El Libero had various custom categories/post types, which had a complex
 * hierarchy. These needed to be re-categorized with a parent-child category relationship.
 */
class ElLiberoCustomCategoriesMigrator implements InterfaceCommand {

	/**
	 * Mapping of parent-child categorization.
	 *
	 * @var array|array[] $category_mapping
	 */
	protected $category_mapping = [
		'Actualidad'               => [
			'slug'     => 'actualidad',
			'new_name' => null,
			'name'     => 'Actualidad',
			'tag'      => [ 'categorias-video' ],
			'children' => [
				[
					'slug'     => 'actualidad-informativa',
					'new_name' => null,
					'name'     => 'Actualidad informativa',
					'tag'      => [ 'categorias-actualidad' ],
				],
				[
					'slug'     => 'actualidad-deportiva',
					'new_name' => null,
					'name'     => 'Actualidad deportiva',
					'tag'      => [ 'categorias-actualidad' ],
				],
			],
		],
		'Opinión'                  => [
			'slug'     => 'opinion',
			'new_name' => null,
			'name'     => 'Opinión',
			'tag'      => [],
			'children' => [
				[
					'slug'     => 'columnas-de-opinion',
					'new_name' => 'Columnas',
					'name'     => 'Columnas de opinión',
					'tag'      => [ 'categorias-opinion', 'categorias-banner' ],
				],
				[
					'slug'     => 'libre-expresion',
					'new_name' => 'Cartas',
					'name'     => 'Cartas al director',
					'tag'      => [],
				],
				[
					'slug'     => 'opinion-constituyente',
					'new_name' => null,
					'name'     => 'Opinión Constituyente',
					'tag'      => [ 'categorias-actualidad' ],
				],
				[
					'slug'     => 'ensayos-asuntos-publicos',
					'new_name' => 'Ensayos',
					'name'     => 'Ensayos de asuntos públicos',
					'tag'      => [ 'categorias-opinion' ],
				],
				[
					'slug'     => 'el-libertino',
					'new_name' => 'Libertino',
					'name'     => 'El Libertino',
					'tag'      => [ 'categorias-opinion' ],
				],
				[
					'slug'     => 'columnista',
					'new_name' => null,
					'name'     => 'Columnistas',
					'tag'      => [],
				],
			],
		],
		'Lo mejor de la prensa'    => [
			'slug'     => 'lo-mejor-de-la-prensa',
			'new_name' => null,
			'name'     => 'Lo mejor de la prensa',
			'tag'      => [],
			'children' => [
				[
					'slug'     => 'seleccion-nacional',
					'new_name' => 'LMP - Nacional',
					'name'     => 'Selección Nacional',
					'tag'      => [ 'categorias-seleccion' ],
				],
				[
					'slug'     => 'seleccion-vespertina',
					'new_name' => 'LMP - Vespertina',
					'name'     => 'Selección Vespertina',
					'tag'      => [ 'categorias-seleccion' ],
				],
				[
					'slug'     => 'seleccion-financiera',
					'new_name' => 'LMP - Financiera',
					'name'     => 'Selección Financiera',
					'tag'      => [ 'categorias-seleccion' ],
				],
				[
					'slug'     => 'seleccion-internacional',
					'new_name' => 'LMP - Internacional',
					'name'     => 'Selección Internacional',
					'tag'      => [ 'categorias-seleccion' ],
				],
			],
		],
		'Tiempo libre'             => [
			'slug'     => 'tiempo-libre',
			'new_name' => null,
			'name'     => 'Tiempo libre',
			'tag'      => [ 'categorias-banner' ],
			'children' => [
				[
					'slug'     => 'gps-libero',
					'new_name' => null,
					'name'     => 'GPS Líbero',
					'tag'      => [ 'categorias-video' ],
				],
				[
					'slug'     => 'hay-algo-alla-afuera',
					'new_name' => null,
					'name'     => 'Hay algo allá afuera',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'la-repisa',
					'new_name' => null,
					'name'     => 'La repisa',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'cine-en-su-casa',
					'new_name' => null,
					'name'     => 'Cine en casa',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'como-en-casa',
					'new_name' => null,
					'name'     => 'Como en casa',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'de-arte-y-otras-hierbas',
					'new_name' => null,
					'name'     => 'De arte y otras hierbas',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'de-coleccion',
					'new_name' => null,
					'name'     => 'De colección',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'la-ruta-saludable',
					'new_name' => null,
					'name'     => 'La ruta saludable',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'huerta-y-mesa',
					'new_name' => null,
					'name'     => 'Huerta y mesa',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'columnas-de-opinion',
					'new_name' => null,
					'name'     => 'Columnas de opinión',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'cocinaconcuento',
					'new_name' => null,
					'name'     => 'Cocina con cuento',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'criticadecine',
					'new_name' => null,
					'name'     => 'Crítica de cine',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'criticadelibros',
					'new_name' => null,
					'name'     => 'Crítica de libros',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'criticademedios',
					'new_name' => null,
					'name'     => 'Crítica de medios',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'criticasdeseries',
					'new_name' => null,
					'name'     => 'Crítica de series',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'gastronomia',
					'new_name' => null,
					'name'     => 'Gastronomía',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'la-cocina-politica',
					'new_name' => null,
					'name'     => 'La cocina política',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'criticadeopera',
					'new_name' => null,
					'name'     => 'Opera y musica docta',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'panoramas',
					'new_name' => null,
					'name'     => 'Panoramas',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'spotify',
					'new_name' => null,
					'name'     => 'Spotify',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
				[
					'slug'     => 'tragos',
					'new_name' => null,
					'name'     => 'Tragos',
					'tag'      => [ 'categorias-tiempo-libre' ],
				],
			],
		],
		'Podcast'                  => [
			'slug'     => 'podcast',
			'new_name' => null,
			'name'     => 'Podcast',
			'tag'      => [],
			'children' => [
				[
					'slug'     => 'lomejordelaprensa',
					'new_name' => 'Podcast lo mejor de la prensa',
					'name'     => 'Lo mejor de la prensa: Actualidad',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'contrafactual-una-mirada-alternativa-a-la-economia',
					'new_name' => 'Contrafactual',
					'name'     => 'Contrafactual (...)',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'el-barril-de-diogenes-el-podcast-de-filosofia',
					'new_name' => 'Barril de diógenes',
					'name'     => 'El Barril de diógenes (...)',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'mirada-libero-en-agricultura',
					'new_name' => 'Mirada Libero',
					'name'     => 'Mirada Líbero en agricultura',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'en-el-patio',
					'new_name' => null,
					'name'     => 'En el patio',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'pich-21',
					'new_name' => null,
					'name'     => 'PICH 21',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'reportajes',
					'new_name' => null,
					'name'     => 'Reportajes',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'especial-coronavirus',
					'new_name' => null,
					'name'     => 'Especial coronavirus',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'debate-constitucional',
					'new_name' => null,
					'name'     => 'Debate constitucional',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'a-fierro-limpio',
					'new_name' => 'A fierro limpio',
					'name'     => 'A fierro limpio (...)',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'gps-libero-la-guia-de-tiempo-libre',
					'new_name' => 'GPS Libero: La guia',
					'name'     => 'GPS Líbero: La guia de tiempo (...)',
					'tag'      => [ 'categorias-audio' ],
				],
				[
					'slug'     => 'archivo',
					'new_name' => null,
					'name'     => 'Archivo',
					'tag'      => [ 'categorias-audio' ],
				],
			],
		],
		'Video'                    => [
			'slug'     => 'video',
			'new_name' => null,
			'name'     => 'Video',
			'tag'      => [],
			'children' => [
				[
					'slug'     => 'archivo',
					'new_name' => null,
					'name'     => 'Archivo',
					'tag'      => [ 'categorias-video' ],
				],
			],
		],
		'Alertas'                  => [
			'slug'     => 'alerta-libero',
			'new_name' => null,
			'name'     => 'Alerta Líbero',
			'tag'      => [ 'categorias-portada' ],
			'children' => [],
		],
		'Líbero constituyente'     => [
			'slug'     => 'libero-constituyente',
			'new_name' => null,
			'name'     => 'Líbero constituyente',
			'tag'      => [ 'categorias-actualidad' ],
			'children' => [],
		],
		'Radio'                    => [
			'slug'     => 'radio',
			'new_name' => null,
			'name'     => 'Radio',
			'tag'      => [],
			'children' => [
				[
					'slug'     => 'miradalibero',
					'new_name' => 'Mirada Líbero',
					'name'     => 'Mirada Líbero',
					'tag'      => [],
				],
			],
		],
		'Podcast red líbero'       => [
			'slug'     => 'podcast-usuarioredlibero', // TODO check that this slug exists.
			'new_name' => null,
			'name'     => 'Podcast red líbero',
			'tag'      => [],
			'children' => [],
		],
		'lo mejor de lo nuestro'   => [
			'slug'     => 'lo-mejor-de-lo-nuestro', // TODO check that this slug exists.
			'new_name' => null,
			'name'     => 'lo mejor de lo nuestro',
			'tag'      => [],
			'children' => [],
		],
		'Informes de inteligencia' => [
			'slug'     => 'informes-de-inteligencia', // TODO check that this slug exists.
			'new_name' => null,
			'name'     => 'Informes de inteligencia',
			'tag'      => [],
			'children' => [],
		],
	];

	/**
	 * This is required to make sure that we can obtain the correct records needed after terms are merged.
	 *
	 * @var int $maximum_term_id Default to 0.
	 */
	private $maximum_term_id = 0;

	/**
	 * ElLiberoCustomCategoriesMigrator Singleton.
	 *
	 * @var ElLiberoCustomCategoriesMigrator|null $instance
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get singleton instance.
	 *
	 * @return ElLiberoCustomCategoriesMigrator
	 */
	public static function get_instance(): ElLiberoCustomCategoriesMigrator {
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
			'newspack-content-migrator el-libero-migrate-categories',
			[ $this, 'driver' ],
			[
				'shortdesc' => 'Will handle category migration for El Libero.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator merge-terms',
			[ $this, 'merge_term_driver' ],
			[
				'shortdesc' => 'Will merge any two terms into one record.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'main-term-id',
						'description' => 'The Term which the other specified terms should be merged into.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'other-term-ids',
						'description' => 'Other terms that should be merged into the main term.',
						'optional'    => false,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'include-taxonomies',
						'description' => 'Limit to these taxonomies for a given term.',
						'optional'    => true,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'exclude-taxonomies',
						'description' => 'Do not include these taxonomies if they appear for a given term.',
						'optional'    => true,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'new-taxonomy',
						'description' => 'The new taxonomy to use for the merged terms and taxonomies.',
						'default'     => 'category',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'parent-term-id',
						'description' => 'Parent Term ID if the finalized term/taxonomy should be a child.',
						'default'     => 0,
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}


	/**
	 * Function to merge wp_term_relationships records.
	 *
	 * @param int $main_term_taxonomy_id Main term_taxonomy_id to merge relationship records into.
	 * @param int $term_taxonomy_id term_taxonomy_id which relationships should be merged from.
	 */
	public function merge_relationships( int $main_term_taxonomy_id, int $term_taxonomy_id ) {
		WP_CLI::line( 'Merging relationships...' );
		$this->output( "Term Taxonomy ID: $term_taxonomy_id => Main Term Taxonomy ID: ($main_term_taxonomy_id)", '%B' );

		global $wpdb;

		$duplicate_sql = "SELECT object_id, COUNT(object_id) as counter FROM $wpdb->term_relationships WHERE term_taxonomy_id IN ($term_taxonomy_id, $main_term_taxonomy_id) GROUP BY object_id HAVING counter > 1";

		$this->output_sql( $duplicate_sql );
		$dupes = $wpdb->get_results( $duplicate_sql );

		$dupes_count = count( $dupes );

		$this->output( "There are $dupes_count duplicates in $wpdb->term_relationships", '%B' );

		if ( ! empty( $dupes ) ) {
			$object_ids = array_map( function( $dupe ) { return $dupe->object_id; }, $dupes );

			$delete_sql = "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id = $term_taxonomy_id AND object_id IN (" . implode( ',', $object_ids ) . ')';
			$this->output_sql( $delete_sql );
			$deleted = $wpdb->query( $delete_sql );

			$this->output( "$deleted rows were deleted", '%B' );
		}

		$update_sql = "UPDATE $wpdb->term_relationships SET term_taxonomy_id = $main_term_taxonomy_id WHERE term_taxonomy_id = $term_taxonomy_id";
		$this->output_sql( $update_sql );
		$updated = $wpdb->query( $update_sql );

		if ( false === $updated ) {
			$this->output( 'Unable to merge.', '%B' );
		} else {
			$this->output( "Merged $updated rows.", '%B' );
		}
	}

	/**
	 * Handles the merging of wp_term_taxonomy records.
	 *
	 * @param int    $main_term_id Main term_id to use for merged taxonomy.
	 * @param array  $records Array of wp_term_taxonomy records to merge.
	 * @param string $taxonomy Taxonomy to use. Defaults to 'category'.
	 * @param int    $parent_term_id Parent term_id. Defaults to 0.
	 *
	 * @return stdClass
	 */
	protected function merge_taxonomies( int $main_term_id, array $records, string $taxonomy = 'category', int $parent_term_id = 0 ) {
		global $wpdb;

		$taxonomies_record_count = count( $records );

		/*
		 * Will attempt to find the first record in $records matching $main_term_id.
		 * If none is found, then will fall back to simply taking the first record
		 * in $records.
		 */
		$first_taxonomy_record = null;
		foreach ( $records as $key => $record ) {
			if ( $main_term_id == $record->term_id ) {
				$first_taxonomy_record = $record;
				unset( $records[ $key ] );
			}
		}

		if ( is_null( $first_taxonomy_record ) ) {
			$first_taxonomy_record = array_shift( $records );
		}

		$this->output( "Main term_taxonomy_id: $first_taxonomy_record->term_taxonomy_id", '%C' );

		if ( $parent_term_id != $first_taxonomy_record->parent ) {
			$this->output( "Updating parent: $first_taxonomy_record->parent to $parent_term_id for term_taxonomy_id: $first_taxonomy_record->term_taxonomy_id" );
			$wpdb->update(
				$wpdb->term_taxonomy,
				[
					'parent' => $parent_term_id,
				],
				[
					'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
				]
			);
		}

		if ( $taxonomies_record_count > 1 ) {
			$this->output( "Merging $taxonomies_record_count records into one main taxonomy record.", '%C' );
		}

		foreach ( $records as $taxonomy_record ) {
			$this->merge_relationships(
				$first_taxonomy_record->term_taxonomy_id,
				$taxonomy_record->term_taxonomy_id
			);

			$this->output( "Checking to see if term_taxonomy_id: $taxonomy_record->term_taxonomy_id term_id: $taxonomy_record->term_id is a parent.", '%C' );
			$parent_check_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE parent = $taxonomy_record->term_id";
			$this->output_sql( $parent_check_sql );
			$term_is_a_parent_check = $wpdb->get_results( $parent_check_sql );

			if ( ! empty( $term_is_a_parent_check ) ) {
				$this->output( 'Term is a parent, updating to new parent.', '%C' );

				$update_parent_sql = "UPDATE $wpdb->term_taxonomy SET parent = $first_taxonomy_record->term_id WHERE parent = $taxonomy_record->term_id";
				$this->output_sql( $update_parent_sql );
				$updated = $wpdb->query( $update_parent_sql );

				if ( false === $updated ) {
					$this->output( 'Unable to update to new parent', '%C' );
				} else {
					$this->output( "Updated $updated rows to new parent", '%C' );
				}
			}

			WP_CLI::line( "Adding count: $taxonomy_record->count to main count: $first_taxonomy_record->count" );
			$update_count_sql = "UPDATE $wpdb->term_taxonomy SET count = count + $taxonomy_record->count WHERE term_taxonomy_id = $first_taxonomy_record->term_taxonomy_id";
			$this->output_sql( $update_count_sql );
			$update_count = $wpdb->query( $update_count_sql );

			if ( false !== $update_count ) {
				$this->output( 'Count updated.', '%C' );
			}

			WP_CLI::line( "Say goodbye to term_taxonomy_id: $taxonomy_record->term_taxonomy_id term_id: $taxonomy_record->term_id" );
			$deleted = $wpdb->delete(
				$wpdb->term_taxonomy,
				[
					'term_taxonomy_id' => $taxonomy_record->term_taxonomy_id,
				]
			);

			if ( false !== $deleted ) {
				$this->output( "Deleted term_taxonomy_id: $taxonomy_record->term_taxonomy_id", '%C' );
			} else {
				$this->output( "Unable to delete term_taxonomy_id: $taxonomy_record->term_taxonomy_id", '%C' );
			}
		}

		$final_count_check_sql = "SELECT COUNT(object_id) as counter FROM $wpdb->term_relationships WHERE term_taxonomy_id = $first_taxonomy_record->term_taxonomy_id";
		$this->output_sql( $final_count_check_sql );
		$final_count_check = $wpdb->get_results( $final_count_check_sql );

		if ( ! empty( $final_count_check ) ) {
			$final_count_check = $final_count_check[0];

			if ( $final_count_check->counter != $first_taxonomy_record->count ) {
				$this->output( "Final count must be updated. Current: $first_taxonomy_record->count Actual: $final_count_check->counter" );

				$wpdb->update(
					$wpdb->term_taxonomy,
					[
						'count' => $final_count_check->counter,
					],
					[
						'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
					]
				);
			}
		}

		$wpdb->update(
			$wpdb->term_taxonomy,
			[
				'taxonomy' => $taxonomy,
			],
			[
				'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
			]
		);

		return $first_taxonomy_record;
	}

	/**
	 * Finds $term_ids to merge into $main_term_id.
	 *
	 * @param int      $main_term_id Main term_id to merge terms into.
	 * @param int[]    $term_ids Other term_id's that should be merged into main term_id.
	 * @param string[] $include_taxonomies Capture any terms with these specific taxonomies.
	 * @param string[] $exclude_taxonomies Exclude any terms with these specific taxonomies.
	 * @param string   $taxonomy Taxonomy to use. Default to 'category'.
	 * @param int      $parent_term_id Parent term_id. Default to 0 (i.e. none).
	 *
	 * @return stdClass|WP_Error
	 */
	public function merge_terms( int $main_term_id, array $term_ids = [], array $include_taxonomies = [], array $exclude_taxonomies = [], string $taxonomy = 'category', int $parent_term_id = 0 ) {
		WP_CLI::line( 'Merging Taxonomies...' );
		$term_ids = array_unique( $term_ids, SORT_NUMERIC );

		if ( in_array( $main_term_id, $term_ids ) ) {
			foreach ( $term_ids as $key => $term_id ) {
				if ( $main_term_id === $term_id ) {
					unset( $term_ids[ $key ] );
					break;
				}
			}
		}

		if ( ! in_array( $taxonomy, $include_taxonomies ) ) {
			$include_taxonomies[] = $taxonomy;
		}

		if ( ! in_array( 'post_tag', $exclude_taxonomies ) ) {
			$exclude_taxonomies[] = 'post_tag';
		}

		global $wpdb;

		$main_taxonomy_sql = "SELECT * FROM $wpdb->term_taxonomy ";

		$constraints     = [];
		$taxonomy_wheres = [];
		if ( ! empty( $include_taxonomies ) ) {
			$taxonomy_wheres[] = "taxonomy IN ('" . implode( "','", $include_taxonomies ) . "')";
		}

		if ( ! empty( $exclude_taxonomies ) ) {
			$constraints[] = "taxonomy NOT IN ('" . implode( "','", $exclude_taxonomies ) . "')";
		}

		$parent_term_id_constraint = "parent = $parent_term_id";
		if ( ! empty( $taxonomy_wheres ) ) {
			$constraints[] = '(' . implode( ' AND ', $taxonomy_wheres ) . " OR $parent_term_id_constraint" . ')';
		} else {
			$constraints[] = $parent_term_id_constraint;
		}

		$temporary_term_id_merge = array_merge( [ $main_term_id ], $term_ids );
		if ( ! empty( $temporary_term_id_merge ) ) {
			$constraints[] = 'term_id IN (' . implode( ',', $temporary_term_id_merge ) . ')';
		} else {
			WP_CLI::confirm( 'The script is about to run on a ton of records, because no term_ids were provided.' );
		}

		if ( ! empty( $constraints ) ) {
			$main_taxonomy_sql = "$main_taxonomy_sql WHERE " . implode( ' AND ', $constraints );
		}

		$this->output( "Main term_id: $main_term_id", '%C' );

		$this->output_sql( $main_taxonomy_sql );
		$main_taxonomy_records = $wpdb->get_results( $main_taxonomy_sql );

		// If one or more $taxonomy records, need to merge all records into one.
		if ( ! empty( $main_taxonomy_records ) ) {
			return $this->merge_taxonomies( $main_term_id, $main_taxonomy_records, 'category', $parent_term_id );
		} else {
			// There is no main taxonomy record. Need to create one.
			WP_CLI::line( "No proper $taxonomy taxonomy record exists for term, creating a new one..." );

			// Before creating a new taxonomy row, need to check the unique key constraint. If it exists, need to create a new term.
			$unique_taxonomy_constraint_sql  = "SELECT * FROM $wpdb->term_taxonomy ";
			$unique_taxonomy_constraint_sql .= "INNER JOIN $wpdb->terms ON $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id ";
			$unique_taxonomy_constraint_sql .= "WHERE $wpdb->term_taxonomy.term_id = $main_term_id ";
			$unique_taxonomy_constraint_sql .= "AND $wpdb->term_taxonomy.taxonomy = '$taxonomy'";
			$this->output_sql( $unique_taxonomy_constraint_sql );
			$result = $wpdb->get_results( $unique_taxonomy_constraint_sql );

			// Means a row already exists in $wpdb->term_taxonomy for $main_term_id-$taxonomy key.
			if ( ! empty( $result ) ) {
				// Must get a new term ID.
				$this->output( "$main_term_id-$taxonomy key already exists in $wpdb->term_taxonomy. Need to create a new one...", '%C' );
				$wpdb->insert(
					$wpdb->terms,
					[
						'name' => $result[0]->name,
						'slug' => $result[0]->slug,
					]
				);

				$new_term_sql = "SELECT * FROM $wpdb->terms WHERE name = '{$result[0]->name}' AND slug = '{$result[0]->slug}' AND term_id != $main_term_id";
				$this->output_sql( $new_term_sql );
				$new_term = $wpdb->get_results( $new_term_sql );

				if ( ! empty( $new_term ) ) {
					$this->output( "New term created successfully, term_id: {$new_term[0]->term_id}" );
					$main_term_id = $new_term[0]->term_id;
				}
			}

			$inserted = $wpdb->insert(
				$wpdb->term_taxonomy,
				[
					'term_id'  => $main_term_id,
					'taxonomy' => $taxonomy,
					'parent'   => $parent_term_id,
				]
			);

			if ( false !== $inserted ) {
				$get_newly_created_taxonomy_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE term_id = $main_term_id AND taxonomy = '$taxonomy' AND parent = $parent_term_id";
				$this->output_sql( $get_newly_created_taxonomy_sql );
				$result = $wpdb->get_results( $get_newly_created_taxonomy_sql );

				$other_taxonomies = [];
				if ( ! empty( $term_ids ) ) {
					$other_taxonomies_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE term_id IN (" . implode( ',', $term_ids ) . ')';
					$this->output_sql( $other_taxonomies_sql );
					$other_taxonomies = $wpdb->get_results( $other_taxonomies_sql );
				}

				$this->output( "New term_taxonomy_id: {$result[0]->term_taxonomy_id}", '%C' );

				$this->merge_taxonomies(
					$main_term_id,
					array_merge( $result, $other_taxonomies ),
					$taxonomy
				);

				return $result[0];
			} else {
				WP_CLI::error( "Unable to create new taxonomy row for term_id: $main_term_id" );
			}
		}
	}

	/**
	 * Handles obtaining the terms required for merging.
	 *
	 * @param string   $slug Unique link for the term to use.
	 * @param string   $current_name Current name for the term.
	 * @param string   $new_name New name for the term to use.
	 * @param string[] $include_taxonomies Taxonomies to capture as part of merging process.
	 * @param string[] $exclude_taxonomies Taxonomies to exclude as part of merging process.
	 * @param string   $taxonomy Name for taxonomy. Default to 'category'.
	 * @param int      $parent_term_id Term ID for parent Term. Default to 0 for none.
	 *
	 * @return stdClass
	 */
	public function handle_term_and_taxonomy( string $slug, string $current_name, string $new_name = '', array $include_taxonomies = [], array $exclude_taxonomies = [], string $taxonomy = 'category', int $parent_term_id = 0 ) {
		WP_CLI::line( "Handling slug: $slug, name: $current_name" );

		if ( ! in_array( 'post_tag', $exclude_taxonomies ) ) {
			$exclude_taxonomies[] = 'post_tag';
		}

		global $wpdb;

		$terms_by_slug_sql  = "SELECT * FROM $wpdb->terms t LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id ";
		$terms_by_slug_sql .= "WHERE t.slug = '$slug' AND (tt.taxonomy NOT IN ('" . implode( ',', $exclude_taxonomies ) . "') OR tt.taxonomy IS NULL)";
		$this->output_sql( $terms_by_slug_sql );
		$terms = $wpdb->get_results( $terms_by_slug_sql );

		if ( ! empty( $terms ) ) {
			$terms_count = count( $terms );

			$first_term_record = array_shift( $terms );

			$this->output( "Main term_id: $first_term_record->term_id, $terms_count terms total." );

			$this->merge_terms(
				$first_term_record->term_id,
				array_map( function( $term ) { return $term->term_id; }, $terms ),
				$include_taxonomies,
				$exclude_taxonomies,
				$taxonomy,
				$parent_term_id ?? 0
			);

			// A new term may have been created here. So we will want to grab the latest term_id for a given slug.
			$latest_term_sql = "SELECT * FROM wp_terms WHERE slug = '$slug' AND term_id > $this->maximum_term_id ORDER BY term_id DESC LIMIT 1;";
			$this->output_sql( $latest_term_sql );
			$latest_term_result = $wpdb->get_results( $latest_term_sql );

			if ( ! empty( $latest_term_result ) ) {
				$this->output( "Changing term from term_id: $first_term_record->term_id to term_id: {$latest_term_result[0]->term_id}" );
				$first_term_record = $latest_term_result[0];
			}

			if ( $new_name != $first_term_record->name ) {
				$this->output( "Updating name from $first_term_record->name to $new_name for term_id: $first_term_record->term_id" );
				$updated = $wpdb->update(
					$wpdb->terms,
					[
						'name' => $new_name,
					],
					[
						'term_id' => $first_term_record->term_id,
					]
				);

				if ( false !== $updated ) {
					$this->output( 'Name was updated.' );
				}
			}

			$this->output( 'Removing other terms...' );

			foreach ( $terms as $term ) {
				$deleted = $wpdb->delete(
					$wpdb->terms,
					[
						'term_id' => $term->term_id,
					],
				);

				if ( false !== $deleted ) {
					$this->output( "Deleted $term->term_id" );
				}
			}

			return $first_term_record;
		} else {
			WP_CLI::line( 'Term does not exist, need to create a new one...' );

			$inserted = $wpdb->insert(
				$wpdb->terms,
				[
					'name' => $new_name,
					'slug' => $slug,
				],
			);

			if ( false !== $inserted ) {
				$term_sql = "SELECT * FROM $wpdb->terms WHERE name = '$new_name' AND slug = '$slug'";
				$this->output_sql( $term_sql );
				$term_result = $wpdb->get_results( $term_sql )[0];

				$wpdb->insert(
					$wpdb->term_taxonomy,
					[
						'term_id'  => $term_result->term_id,
						'taxonomy' => $taxonomy,
						'parent'   => $parent_term_id,
					]
				);

				$taxonomy_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE term_id = {$term_result->term_id} AND taxonomy = '$taxonomy' AND parent = $parent_term_id";
				$this->output_sql( $taxonomy_sql );
				$taxonomy_result = $wpdb->get_results( $taxonomy_sql )[0];

				$this->output( "Created new term-taxonomy - term_id: $term_result->term_id, term_taxonomy_id: $taxonomy_result->term_taxonomy_id" );

				return $term_result;
			}
		}
	}

	/**
	 * Driver function for program execution.
	 *
	 * @returns void
	 */
	public function driver() {
		$this->output( 'Beginning...', '%G' );

		$this->setup();

		foreach ( $this->category_mapping as $parent => $attributes ) {
			$this->output( "Handling Parent: $parent", '%G' );
			$parent_term = $this->handle_term_and_taxonomy(
				$attributes['slug'],
				$attributes['name'],
				$attributes['new_name'] ?? $attributes['name'],
				$attributes['tag'],
				$attributes['exclude'] ?? [],
			);

			foreach ( $attributes['children'] as $child ) {
				$this->output( "Handling Child: {$child['slug']}" );
				$child = $this->handle_term_and_taxonomy(
					$child['slug'],
					$child['name'],
					$child['new_name'] ?? $child['name'],
					$child['tag'],
					$attributes['exclude'] ?? [],
					'category',
					$parent_term->term_id
				);
			}
		}
	}

	/**
	 * Handler for WP CLI execution.
	 *
	 * @param array $args Positional CLI arguments.
	 * @param array $assoc_args Associative CLI arguments.
	 * */
	public function merge_term_driver( array $args, array $assoc_args ) {
		$this->setup();

		$main_term_id       = intval( $assoc_args['main-term-id'] );
		$other_term_ids     = intval( $assoc_args['other-term-ids'] );
		$include_taxonomies = $assoc_args['include-taxonomies'] ?? [];
		$exclude_taxonomies = $assoc_args['exclude-taxonomies'] ?? [];
		$new_taxonomy       = $assoc_args['new-taxonomy'];
		$parent_term_id     = intval( $assoc_args['parent-term-id'] );

		if ( ! is_array( $other_term_ids ) ) {
			$other_term_ids = [ $other_term_ids ];
		}

		$this->merge_terms(
			$main_term_id,
			$other_term_ids,
			$include_taxonomies,
			$exclude_taxonomies,
			$new_taxonomy,
			$parent_term_id
		);
	}

	/**
	 * Using as a sort of constructor, to initialize some class properties.
	 *
	 * @returns void
	 */
	private function setup() {
		global $wpdb;

		$max_term_id_sql = "SELECT * FROM $wpdb->terms ORDER BY term_id DESC LIMIT 1;";
		$this->output_sql( $max_term_id_sql );
		$max_term = $wpdb->get_results( $max_term_id_sql );

		if ( ! empty( $max_term ) ) {
			$this->output( "Maximum term_id: {$max_term[0]->term_id}", '%G' );
			$this->maximum_term_id = $max_term[0]->term_id;
		}
	}

	/**
	 * Convenience function to handle setting a specific color for SQL statements.
	 *
	 * @param string $message MySQL Statement.
	 *
	 * @returns void
	 */
	private function output_sql( string $message ) {
		$this->output( $message, '%w' );
	}

	/**
	 * Output messsage to console with color.
	 *
	 * @param string $message String to output on console.
	 * @param string $color The color to use for console output.
	 *
	 * @returns void
	 */
	private function output( string $message, $color = '%Y' ) {
		echo WP_CLI::colorize( "$color$message%n\n" );
	}
}
