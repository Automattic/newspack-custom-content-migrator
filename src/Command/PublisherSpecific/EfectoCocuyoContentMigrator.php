<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom Efecto Cocuyo migration script.
 */
class EfectoCocuyoContentMigrator implements InterfaceCommand {
	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthors Plus logic.
	 *
	 * @var CoAuthorPlus CoAuthors Plus logic instance.
	 */
	private $coauthorsplus_logic;

	/**
	 * Logger.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Posts.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

    /**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->logger = new Logger();
		$this->posts = new Posts();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator efecto-cocuyo-list-avatars',
			[ $this, 'cmd_list_avatars' ],
			[
				'shortdesc' => 'Generates a list of potential avatars to be manually handled',
				'synopsis'  => [],
			]
		);
        WP_CLI::add_command(
			'newspack-content-migrator efecto-cocuyo-extract-guest-authors',
			[ $this, 'cmd_migrate_gest_authors' ],
			[
				'shortdesc' => 'Converts authors present in the ACF autor_post field into guest authors',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator efecto-cocuyo-photographers',
			[ $this, 'cmd_photographers' ],
			[
				'shortdesc' => "Gets postmeta 'autor_fotos_o_imagenes_del_post' and sets it as post's featured image's 'Credit' field. Fixes https://app.asana.com/0/1200360665923416/1205089960935202/f",
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator efecto-cocuyo-split-merged-wpuser-authors-into-separate-gas',
			[ $this, 'cmd_split_merged_wpuser_authors_into_separate_gas' ],
			[
				'shortdesc' => "Some co-authors got merged into single WP_Users. This converts those accounts to separate GAs. Fixes https://app.asana.com/0/1200360665923416/1205089960935202/f",
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator efecto-cocuyo-split-merged-wpuser-authors-into-separate-gas`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
    public function cmd_split_merged_wpuser_authors_into_separate_gas( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$wp_user_ids_to_gas = [
			// "Dayimar Ayala e Irving Briceno"
			87 => [
				'Dayimar Ayala',
				'Irving Briceno',
			],
			// "Jeanfreddy y Shari"
			271 => [
				'Jeanfreddy Gutiérrez',
				'Shari Avendaño',
			],
			// "Marelia Armas y John Souto"
			186 => [
				'Marelia Armas',
				'John Souto',
			],
			// "Mariel Lozada y Andrea Garcia"
			92 => [
				'Mariel Lozada',
				'Andrea Garcia',
			],
		];

		$adminnewspack_wp_user_id = 407;

		foreach ( $wp_user_ids_to_gas as $wp_user_id => $ga_names ) {

			WP_CLI::line( "Updating posts belonging to WP_User ID $wp_user_id ..." );

			// Get GAs for this WP_User.
			$ga_ids = [];
			foreach ( $ga_names as $ga_name ) {
				$ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $ga_name );
				if ( $ga ) {
					$ga_ids[] = $ga->ID;
				} else {
					$ga_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $ga_name ] );
					$ga_ids[] = $ga_id;
				}
			}

			// Validate.
			if ( count( $ga_ids ) != count( $ga_names ) ) {
				$this->logger->log( 'efecto-cocuyo-split-merged-wpuser-authors-into-separate-gas_err.log', "Not all GAs were found or created for WP_User ID $wp_user_id", $this->logger::WARNING );
			}

			// Update all 'post' types.
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d AND post_type = 'post'", $wp_user_id ) );
			foreach ( $post_ids as $post_id ) {

				// Remove the WP_User ID from the post_author field.
				$wpdb->update(
					$wpdb->posts,
					[ 'post_author' => $adminnewspack_wp_user_id ],
					[ 'ID' => $post_id ],
				);

				// Add the GAs to the post.
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
				$this->logger->log( 'efecto-cocuyo-split-merged-wpuser-authors-into-separate-gas_updated.log', sprintf( "postID %d GA_IDs %s", $post_id, implode( ',', $ga_ids ) ), null );
			}

			WP_CLI::success( sprintf( "Updated %d posts", count( $post_ids ) ) );
		}
    }

	/**
	 * Callable for `newspack-content-migrator efecto-cocuyo-bylines-and-photographers`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
    public function cmd_photographers( array $pos_args, array $assoc_args ) {
		global $wpdb;

		// "Autor Fotos o Imágenes del Post" should be credit to the featured image.
	    // e.g., live ID 534633 , staging ID 534633, staging featured image ID 534634.

	    $post_ids = $this->posts->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$attachment_id = get_post_meta( $post_id, '_thumbnail_id', true );
			$photographer = get_post_meta( $post_id, 'autor_fotos_o_imagenes_del_post', true );

			if ( ! $attachment_id && $photographer ) {
				$this->logger->log( "ec__warning_has_photographer_but_no_attachment_id.log", $post_id, $this->logger::WARNING );
				continue;
			}
			if ( ! $attachment_id ) {
				continue;
			}
			if ( ! $photographer ) {
				continue;
			}
			$photographer = trim( $photographer );
			if ( empty( $photographer ) ) {
				continue;
			}

			// Get attachment photo credit.
			$credit = get_post_meta( $attachment_id, '_media_credit', true );

			// If credit exists and is different than photographer, log old and new values, overwrite with (new) photographer.
			if ( $credit && ( $credit !== $photographer ) ) {
				$this->logger->log( "ec__warning_credit_exists_was_overwritten.log", sprintf( "postID %d attachmentID%d existingCredit:'%s' newCredit:'%s'", $post_id, $attachment_id, $credit, $photographer ), $this->logger::WARNING );
			}

			// Set credit to attachment.
			update_post_meta( $attachment_id, '_media_credit', $photographer );
			$this->logger->log( "ec__updated.log", "Updated postID {$post_id} credit for attachmentID {$attachment_id} to '{$photographer}'.", $post_id, $this->logger::SUCCESS );
		}
    }

    /**
	 * Outputs a list of potential avatars to be manually handled.
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args CLI assoc arguments.
	 * @return void
	 */
    public function cmd_list_avatars( $args, $assoc_args ) {
        global $wpdb;
        $avatars = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key = 'foto_o_imagen_silueta_del_autor' AND meta_value != ''");
        foreach ( $avatars as $avatar ) {
            WP_CLI::line( 'POST ' . $avatar->post_id . ' - ' . get_permalink( $avatar->post_id ) );
            WP_CLI::line( '-- Author type: ' . get_post_meta( $avatar->post_id, 'tipo_de_autor', true ) );
            WP_CLI::line( '-- Author: ' . get_post_meta( $avatar->post_id, 'autor_post', true ) );
            WP_CLI::line( '-- Avatar: ' . wp_get_attachment_url( $avatar->meta_value ) );
            WP_CLI::line( '' );
        }
    }

    /**
	 * Migrates authors present in the autor_post ACF field into guest authors.
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args CLI assoc arguments.
	 * @return void
	 */
    public function cmd_migrate_gest_authors( $args, $assoc_args ) {
        global $wpdb, $coauthors_plus;
        $dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];

        if ( ! $dry_run ) {
            WP_CLI::line( 'This command will modify the database.');
            WP_CLI::line( 'Consider running it with --dry-run first to see what it will do.');
            WP_CLI::confirm( "Are you sure you want to continue?", $assoc_args );
        }

        if ( ! $this->coauthorsplus_logic->is_coauthors_active() ) {
            WP_CLI::error( 'Co-Authors Plus plugin is not active.' );
        }

        $query = "select post_id, meta_value from $wpdb->postmeta where meta_key = 'autor_post' and meta_value <> '' and post_id IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'tipo_de_autor' and meta_value <> 'auto') and post_id IN (SELECT ID FROM $wpdb->posts where post_status = 'publish' and post_type='post');";

        $metas = $wpdb->get_results($query);

		foreach( $metas as $meta ) {
            if ( get_post_meta( $meta->post_id, '_acf_author_migrated', true ) ) {
                continue;
            }

            WP_CLI::line( 'POST ID: ' . $meta->post_id );
            WP_CLI::line( 'ACF field value: ' . $meta->meta_value );
			$names = $this->get_normalized_name( $meta->meta_value );
            $names = str_replace( ', ', '===', $names );
            $names = str_replace( ' y ', '===', $names );
			$names = explode( '===', $names );
			$names = array_map( function($n) { return trim($n); }, $names );

            $coauthors = [];

			foreach( $names as $name ) {
                WP_CLI::line( '- Processing name: ' . $name );

                $nicename = sanitize_title( $name );
                if ( $dry_run ) {
                    WP_CLI::line( '-- Will create/look for Guest author: ' . $nicename );
                    $coauthors[] = $nicename;
                    continue;
                }
                $guest_author_id = $this->coauthorsplus_logic->create_guest_author( [
                    'display_name' => $name,
                    'user_login' => $nicename,
                ] );
                if ( is_wp_error( $guest_author_id ) ) {
                    WP_CLI::line( '-- Error creating Guest author: ' . $nicename . ' - ' . $guest_author_id->get_error_message() );
                    continue;
                }

                $guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );

                if ( is_object($guest_author) && ! empty( $guest_author->user_nicename ) ) {
                    WP_CLI::line( '-- Found/Created Guest author: ' . $guest_author->user_nicename . ' (ID: ' . $guest_author->ID . ')' );
                    $coauthors[] = $guest_author->user_nicename;
                }
			}

            if ( ! $dry_run ) {
                $coauthors_plus->add_coauthors( $meta->post_id, $coauthors );
                update_post_meta( $meta->post_id, '_acf_author_migrated', 1 );
            }

		}

    }

    /**
     * Gets a normalized version of the name after some manual clean up.
     *
     * @param string $name The name present in the autor_post meta field.
     * @return void
     */
    public function get_normalized_name( $name ) {

        /**
         * This is a list of all names under the autor_post meta field.
         *
         * Here I normalized how names are separated and also some additional clean up.
         *
         * This is a big part of the migration work as a lot of manual cleaning was done - handling duplicates, misspellings, etc.
         *
         * Also, this is no 100% accurate, I might have missed some cases.
         *
         * The key of the array is the name as it appears in the database. If the value is empty, no modification was needed.
         */
        $names = [
            'Aragón' => '',
            'Íbis Moreno' => '',
            'Johan Aragón' => '',
            'Ana Griffin y Reynaldo Mozo Zambrano' => '',
            'Andrés Cañizalez' => '',
            'Julett Pineda y Mariana Souquett' => '',
            'Julett Pineda' => '',
            'Julett Pineda y Edgar López' => '',
            'Efecto Cocuyo' => '',
            '' => '',
            'Deutsche Welle ' => '',
            'Shari Avendaño y Eugenio Martínez' => '',
            'Efecto Cocuyo | @efectococuyo ' => 'Efecto Cocuyo',
            'Unidad de Verificación de Datos Efecto Cocuyo' => '',
            'Fedosy Santaellla' => '',
            'Daniel Acosta Ramos' => '',
            ' Jaiden Martínez ' => 'Jaiden Martínez',
            ' Daniel Lahoud' => 'Daniel Lahoud',
            'Shari Avendaño' => '',
            'José Ochoa' => '',
            'Deisy Martínez' => '',
            'Efecto Cocuyo @efectococuyo' => 'Efecto Cocuyo',
            'Deisy Martínez │@deicamar' => 'Deisy Martínez',
            'Andrés Schmucke / @andy_schmucke' => '',
            'Antonella Freites Franco ' => '',
            'Deisy Martínez @deicamar' => 'Deisy Martínez',
            'Shari Avendaño | @ShariAvendano' => 'Shari Avendaño',
            'Francisco Rincón' => '',
            'Mirian Nuñez' => '',
            'Danisbel Gómez Morillo' => '',
            'Thairy Baute' => '',
            'Shari Avendaño, Jeanfreddy Gutiérrez y Eugenio Martínez' => '',
            'Jeanfreddy Gutiérrez' => '',
            'Efe | Deutsche Welle' => 'Efe y Deutsche Welle',
            'Andrea Herrera' => '',
            'Efe ' => '',
            'María Victoria Fermín @vickyfermin │Jeanfreddy Gutiérrez @jeanfreddy' => 'María Victoria Fermín @vickyfermin y Jeanfreddy Gutiérrez',
            'Judith Valderrama | @juditvalderrama' => '',
            'Cristina González  | Edgar López' => 'Cristina González y Edgar López',
            ' Héctor Gabriel Briceño Motesinos' => 'Héctor Gabriel Briceño Motesinos',
            'DW | Efe' => 'Deutsche Welle y Efe',
            'Lenny Durán ' => '',
            'Deutsche Welle | Efe' => 'Deutsche Welle y Efe',
            'Maolis Castro' => '',
            'Ayatola Núñez' => 'Ayatola Núñez | @miliderayatola',
            'Colombia Check' => '',
            'Nayrobis Rodríguez | @nabybi' => '',
            'Feliciano Reyna Ganteaume' => '',
            'Samantha Aretuo' => '',
            'Ibis León | @IbisL' => 'Ibis León',
            'María Victoria Fermín Kancev | @vickyfermin' => 'María Victoria Fermín @vickyfermin',
            'Nayrobis Rodríguez' => '',
            'Francisco R. Rodríguez' => '',
            'LatamChequea' => '',
            'Cristina González' => '',
            'Unidad de Investigación' => '',
            'Edgar López / Ibis León' => 'Edgar López y Ibis León',
            'Deisy Martínez │@deicamar | Ronny Rodríguez | @ronnyrodriguez' => 'Deisy Martínez y Ronny Rodríguez',
            'Zulma López' => '',
            'Cristina González | Edgar López' => 'Cristina González y Edgar López',
            'Janina Pérez Arias' => '',
            'Ronny Rodríguez Rosas | Reynaldo Mozo Zambrano' => 'Ronny Rodríguez y Reynaldo Mozo Zambrano',
            'Héctor Villa León (@heccctorv) y Pierina Sora (@pierast)' => 'Héctor Villa León (@heccctorv) y Pierina Sora (@pierast)',
            'Jefferson Díaz' => 'Jefferson Díaz | @jefferson_diaz',
            'Oswaldo José Avendaño' => '',
            'Cristina González | @twdecristina' => 'Cristina González',
            'Valentina Lares Martiz | @valentinalares' => '',
            'Ramón Escovar Alvarado ' => '',
            'Augusto Taglioni' => '',
            'Karen de la Torre (@karelampia), Héctor Villa León (@heccctorv) y Gerardo Cárdenas ' => '',
            'Ayatola Núñez | @miliderayatola' => '',
            'Edgar López y María Victoria Fermín' => 'Edgar López y María Victoria Fermín @vickyfermin',
            'Oswaldo Avendaño | @os0790' => '',
            'Jefferson Díaz | @jefferson_diaz' => '',
            'Jeanfreddy Gutiérrez y Eugenio Martínez' => '',
            'Paola Albornoz Fernández | @paoalbornozf' => '',
            'Edgar López | Mariana Souquett' => 'Edgar López y Mariana Souquett',
            'Diana Salinas' => '',
            'José María León Cabrera ' => 'José María León Cabrera',
            'Renzo Gómez Vega ' => 'Renzo Gómez Vega',
            'Paulette Desormeaux ' => 'Paulette Desormeaux',
            'Emilia Delfino ' => 'Emilia Delfino',
            ' Fabiola Chambi ' => 'Fabiola Chambi',
            'Cristina García Casado' => '',
            'Carmen García Bermejo ' => '',
            ' Cecibel Romero ' => 'Cecibel Romero',
            'Iván E. Reyes ' => 'Iván E. Reyes',
            'Zulma López | @ZULOGO' => 'Zulma López',
            'Mairet Chourio | @mairetchourio' => 'Mairet Chourio',
            'Mariela Ramírez – Movimiento Ciudadano Dale Letra' => '',
            'Ronny Rodríguez Rosas | María Victoria Fermín' => 'Ronny Rodríguez y María Victoria Fermín @vickyfermin',
            'AFP ' => '',
            'Por Cristina González , Ma. Victoria Fermín y Edgar López' => 'Cristina González, María Victoria Fermín @vickyfermin y Edgar López',
            'Gloria Ziegler - @Gloriaziegler' => '',
            'Mairet Chourio' => '',
            'Cecibel Romero' => '',
            'Equipo de Investigación' => '',
            'Andrea Tosta' => '',
            'Odell López Escote' => '',
            'Johanny Pernia' => '',
            'Soraya Borelly' => '',
            'Ingrid Ramírez, Iván Serrano y Diana Salinas' => '',
            'Erick González' => '',
            'David González , Ingrid Ramírez y Diana Salinas' => 'David González, Ingrid Ramírez y Diana Salinas',
            'Alessandro Di Stasio' => '',
            'Jeanfreddy Gutiérrez | @jeanfreddy Ronny S. Rodríguez R. | @ronnyrodriguez' => 'Jeanfreddy Gutiérrez y Ronny Rodríguez',
            'Magda Gibelli' => '',
            'Manuel Tomillo C. ' => 'Manuel Tomillo',
            'Shari Avendaño, Edgar López y María Victoria Fermín' => 'Shari Avendaño, Edgar López y María Victoria Fermín @vickyfermin',
            'Raisa Urribarri | @uraisa' => '',
            'Programa Lupa' => '',
            'Mariana Souquett y Shari Avendaño' => '',
            'Gustavo Bencomo y Mariana Souquett' => '',
            '@EfectoCocuyo' => 'Efecto Cocuyo',
            'Shari Avendaño y Mariana Souquett' => '',
            'Deutsche Welle (Deutsche Welle)' => 'Deutsche Welle',
            'Catalina Lobo-Guerrero' => '',
            'Salud con lupa' => '',
            'Prensa presidencial ' => 'Prensa presidencial',
            'Manuel Tomillo / Jeanfreddy Gutiérrez' => 'Manuel Tomillo y Jeanfreddy Gutiérrez',
            'Oscar Doval  @oscardoval_' => 'Oscar Doval | @OscarDoval_',
            'Oscar Doval | @OscarDoval_' => 'Oscar Doval | @OscarDoval_',
            'Ibis León y Mariana Souquett' => '',
            'Kalinda La Mar @MadameKala' => 'Kalinda La Mar',
            'Mariana Souquett y Mairet Chourio ' => '',
            'Texto y fotos Mairet Chourio' => 'Mairet Chourio',
            'Equipo de Investigación (*)' => '',
            'Bea Arias | @beaariasd' => '',
            'Mary Carmen Fleming ' => '',
            'David Villafranca/EFE' => '',
            'Javier Romualdo/EFE' => '',
            'Fabiana Ortega | @Fabianaortegatv' => '',
            'Alejandro Herrera' => '',
            'Ayatola Núñez / Lima @miliderayatola' => 'Ayatola Núñez | @miliderayatola',
            'Venezuela Verifica' => '',
            'Sebastián Meresman | Efe' => '',
            'Carlos Meneses Sánchez' => '',
            'Claudia Aguilar Ramírez' => '',
            'Bea Arias' => '',
            'Luz Escobar | 14 y medio' => 'Luz Escobar | 14 \y medio',
            'Pedro Eduardo Leal' => '',
            'Juan Pablo Romero Sosa' => 'Juan Pablo Romero | @juanpr97',
            'Rosmina Suárez Piña' => '',
            'Yohennys Briceño Rodríguez/Historias que laten' => 'Yohennys Briceño',
            ' Fabiana Ortega F.' => 'Fabiana Ortega F.',
            'Oscar Doval' => 'Oscar Doval | @OscarDoval_',
            'Alianza #GüiriaDuele' => '',
            'Betania Franquis/@cronicauno' => '',
            'Nirma Hernández Ramos | @Nirma_Hernandez' => '',
            'Juan Pablo Romero | @juanpr97' => '',
            'Héctor Escandell/@radiofeyalegria' => '',
            'Morelia Morillo |  @moreliamorillo' => '',
            'Ana Julia Niño Gamboa | @anajulia07' => '',
            'Mariana Souquett y María Victoria Fermín' => 'Mariana Souquett y María Victoria Fermín @vickyfermin',
            'Erick Mayora | @esmayora' => '',
            'BBC' => '',
            'Mariana Souquett, Shari Avendaño y Rey Mozo' => 'Mariana Souquett, Shari Avendaño y Reynaldo Mozo Zambrano',
            'Jeanfreddy Gutiérrez | @jeanfreddy' => 'Jeanfreddy Gutiérrez',
            'Abel Saraiba' => '',
            'Verónica De Sousa A.' => '',
            ' Martín Ramos y Marcia Franco' => 'Martín Ramos y Marcia Franco',
            'Yohennys Briceño Rodríguez | Historias que Laten' => 'Yohennys Briceño',
            'Oscar Doval  |  @oscardoval_' => 'Oscar Doval | @OscarDoval_',
            'Verónida De Sousa A.' => 'Verónica De Souza A.',
            'VoA Noticias' => '',
            'Enrique March' => '',
            'Verónica De Souza A.' => '',
            'Yadira Pérez | Open Democracy' => '',
            'Voz de América | @VozdeAmerica' => '',
            'Distintas Latitudes' => '',
            'Zue Dawzen' => '',
            'Frankie Ruggiero' => '',
            'VOA' => '',
            'Yamel Rincón' => '',
            'Kalinda La Mar' => '',
            'Albany Andara Meza / Mabel Sarmiento' => 'Albany Andara y Mabel Sarmiento',
            'María Laura Jiménez' => '',
            'Lucy Montiel ' => '',
            'EFE @EFEnoticias' => '',
            'Andrea Herrera y Cándido Pérez' => '',
            'María Victoria Fermín y Ronny Rodríguez' => 'María Victoria Fermín @vickyfermin y Ronny Rodríguez',
            'Liseth González' => '',
            'Rosmina Suárez Piña y Reynaldo Mozo Zambrano' => '',
            'Alberto Pradilla' => '',
            'José Capacho y Jhoandry Suárez' => '',
            'Margaret López/ Albany Andara ' => 'Margaret López y Albany Andara',
            'Mariana Sofía García y Yohennys Briceño Rodríguez' => 'Mariana Sofía García y Yohennys Briceño',
            'Agostina Bordigoni y Malkya Tudela' => '',
            'Carlos Silva' => '',
            'Jesús Mesa y Agustina Bordigoni' => '',
            'Luz Mely Reyes | Ronny S. Rodríguez R' => 'Luz Mely Reyes y Ronny Rodríguez',
            'Shari Avendaño e Ibis León' => 'Shari Avendaño y Ibis León',
            'Jade Delgado/Historias que laten' => 'Jade Delgado',
            ' Yohennys Briceño/Historias que laten' => 'Yohennys Briceño',
            'Jade Delgado' => '',
            'Wolman Linares' => '',
            'Emiliana Duarte Otero' => '',
            'Agustina Bordigoni' => '',
            'Madelen Simo y Eira Gonzalez' => '',
            'Yohennys Briceño ' => 'Yohennys Briceño',
            'Jhoandry Suárez' => '',
            ' César Baeza' => 'César Baeza',
            'María José Vargas' => '',
            'Karla Sánchez Arismendi' => '',
            'Luis Fernando Cantoral' => '',
            'Yohennys Briceño Rodríguez' => 'Yohennys Briceño',
            'EFE / @EFEnoticias' => 'EFE @EFEnoticias',
            'Renato Sérgio de Lima' => '',
            'Roberto Valencia' => '',
            'Efecto Cocuyo, OCCRP, Armando.info' => '',
            'OCCRP y Süddeutsche Zeitung' => '',
            'Joseph Stiglitz/Premio Nobel de Economía 2001' => '',
            'Infobae' => '',
            'Infobae/ La Nación' => '',
            'OCCRP, Süddeutsche Zeitung' => '',
            'Leo Felipe Campos' => '',
            'António Guterres' => '',
            'Latam Chequea' => '',
            'María Corina Muskus' => '',
            'Efe | @EFEnoticias' => 'EFE @EFEnoticias',
            'Carlos Carrasco' => '',
            'OCCRP' => '',
            'Ibis León' => '',
            'Amanda Ribeiro, Ethel Rudnitzki, Luiz Fernando Menezes, Marco Faustino y Priscila Pacheco - Aos Fatos (Brasil)' => '',
            'Equipo de CLIP, Agencia Ocote, Animal Político, Bolivia Verifica, Colombia Check y Univisión' => '',
            'José Luis Peñarredonda, Jeanfreddy Gutiérrez y Sharon Mejía (Colombia Check) y Pablo Medina Uribe ' => 'José Luis Peñarredonda, Jeanfreddy Gutiérrez y Sharon Mejía (Colombia Check) y Pablo Medina Uribe',
            'Guillermo Azábal/EFE' => '',
            'Martín Slipczuk - Chequeado (Argentina)' => '',
            'La Liga Contra el Silencio' => '',
            'Josep Borrell Fontelles' => 'Josep Borrel',
            'Anais López' => '',
            'Danisbel Gómez' => 'Danisbel Gómez Morillo',
            'Luisa Kislinger' => '',
            'Andreina Peñaloza' => '',
            'OjoPúblico / Catalina Lobo-Guerrero/ Red Investigativa Transfronteriza ' => 'OjoPúblico, Catalina Lobo-Guerrero y Red Investigativa Transfronteriza',
            'Stefania Vitale' => '',
            'Edgar López y Alessandro Di Stasio' => '',
            'Mariangela García' => '',
            'VOA | @VozdeAmerica' => '',
            'Josep Borrel' => '',
            'Claudia Padrón Cueto' => '',
            'Deisy Martínez- Reynaldo Mozo ' => 'Deisy Martínez y Reynaldo Mozo Zambrano',
            'Luz Mely Reyes e Iván E Reyes' => 'Luz Mely Reyes y Iván E Reyes',
            'Marianna Alexandra Romero Mosqueda' => '',
            'Mariana Duque' => '',
            'Karlo M. Bermúdez' => '',
            'Francisco Javier Cortés Mejía' => '',
            'Marcos Mancero' => '',
            'Contenido patrocinado' => '',
            'Dulce Yumar' => '',
            'Mario Lubetkin' => '',
            'Amira Muci | Carmen Elisa Pecorelli' => 'Amira Muci y Carmen Elisa Pecorelli',
            'Soraya Borelly Patiño' => 'Soraya Borelly',
            'Con información  de Soraya Borelli y Kurucuteando' => 'Soraya Borelly y Kurucuteando',
            'Sergio Sánchez' => '',
            'Erick Mayora' => 'Erick Mayora | @esmayora',
            'Génesis Méndez Alzolar' => '',
            'Alberto Padilla' => '',
            'Jeanfreddy Gutiérrez Torres y Paula Andrea Jiménez' => 'Jeanfreddy Gutiérrez y Paula Andrea Jiménez',
            'Rafael Quiroz Serrano' => '',
            'Mariví Marín Vázquez de ProBox |  Adrián González de Cazadores de Fake News | Héctor Rodríguez de Medianálisis para C-Informa' => 'Mariví Marín Vázquez de ProBox, Adrián González de Cazadores de Fake News, Héctor Rodríguez de Medianálisis para C-Informa',
            'C-Informa' => '',
            'Efecto Cocuyo |  @efectococuyo' => 'Efecto Cocuyo',
            'Josep Borrell' => '',
            'Albany Andara, Reynaldo Mozo y Ronny Rodríguez' => 'Albany Andara, Reynaldo Mozo Zambrano y Ronny Rodríguez',
            'Ana Virgina Garroni' => '',
        ];

        return ! empty( $names[ $name ] ) ? $names[ $name ] : $name;

    }
}
