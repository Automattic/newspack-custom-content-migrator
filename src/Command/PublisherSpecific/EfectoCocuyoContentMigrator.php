<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use stdClass;
use WP_CLI;
use WP_User;

/**
 * Custom Efecto Cocuyo migration script.
 */
class EfectoCocuyoContentMigrator implements InterfaceCommand {
	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var SimpleLocalAvatars Simple Local Avatars logic.
	 */
	protected $simple_local_avatar_logic;

	/**
	 * @var Attachments Attachments logic.
	 */
	protected $attachments;

	/**
	 * @var resource FTP connection.
	 */
	protected $ftp;

    /**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->simple_local_avatar_logic = new SimpleLocalAvatars();
		$this->attachments = new Attachments();
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
			'newspack-content-migrator efecto-cocuyo-attempt-to-fix-author-profile-images',
			[ $this, 'cmd_attempt_to_fix_author_profile_images' ],
			[
				'shortdesc' => 'Attempts to fix author and guest author profile images',
				'synopsis' => [
					[
						'type'        => 'assoc',
						'name'        => 'ftp-server',
						'description' => 'The FTP server for this site',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ftp-user',
						'description' => 'The FTP user for this site',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ftp-pass',
						'description' => 'The FTP password for this site',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
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
	 * This function will attempt to fix a missing avatar issue for Authors and Guest Authors.
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_attempt_to_fix_author_profile_images( $args, $assoc_args ) {
		$ftp_server = $assoc_args['ftp-server'];
		$ftp_user = $assoc_args['ftp-user'];
		$ftp_pass = $assoc_args['ftp-pass'];

		$target_meta_keys = [
//			'author_image',
			'ce_user_avatar',
			'ce_user_avatars',
			'wp_user_avatars',
			'pp_uploaded_files',
			'pcg_custom_gravatar',
			'pp_profile_cover_image',
		];

		global $wpdb;

		/**
		 * Structure:
		 * [
		 *  user_id => meta_data
		 * ]
		 */
		$old_users = [];

		foreach ( $target_meta_keys as $meta_key ) {
			$query = $wpdb->prepare( "SELECT * FROM old_usermeta WHERE meta_key = %s AND meta_value <> ''", $meta_key );

			$user_meta_results = $wpdb->get_results( $query );

			foreach ( $user_meta_results as $user_meta_result ) {
				$old_user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM old_users WHERE ID = %d", $user_meta_result->user_id ) );

				if ( ! $old_user ) {
					WP_CLI::log("COULD NOT FIND OLD USER FOR USER ID: {$user_meta_result->user_id}" );
					continue;
				}

				$new_user = get_user_by( 'email', $old_user->user_email );

				if ( ! $new_user ) {
					WP_CLI::log("COULD NOT FIND NEW USER FROM OLD USER EMAIL: {$old_user->user_email}" );
					continue;
				}

				if ( ! isset( $old_users[ $new_user->ID ] ) ) {
					$old_users[ $new_user->ID ] = $user_meta_result;
				} else {
					WP_CLI::warning( 'User ID: ' . $new_user->ID . ' has multiple profile pictures ' );
					var_dump( $old_users[ $new_user->ID ] );
					var_dump( $user_meta_result );
				}

				if ( $this->coauthorsplus_logic->is_user_a_guest_author( $new_user ) ) {
					WP_CLI::log('User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' is a guest author');
					//
					$guest_author = $this->coauthorsplus_logic->get_or_create_guest_author_from_user( $new_user );
					$attachment_id = $this->coauthorsplus_logic->get_guest_authors_avatar_attachment_id( $guest_author->ID );

					if ( is_null( $attachment_id ) ) {
						WP_CLI::log( 'User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' does not have a profile picture' );
						//Need to find pointer to profile picture in local media.
						$success = $this->set_avatar_for_user( $new_user, $guest_author, $user_meta_result, $ftp_server, $ftp_user, $ftp_pass );
						if ( ! $success ) {
							unset( $old_users[ $new_user->ID ] );
						}
					} else {
						WP_CLI::log( 'User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' already has a profile picture' );
						$attachment = get_post( $attachment_id );
						var_dump( $attachment );
					}
				} else {
					WP_CLI::log('User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' is a regular user');

					$has_local_avatar = $this->simple_local_avatar_logic->user_has_local_avatar( $new_user->ID );

					if ( ! $has_local_avatar ) {
						$gravatar_url = get_avatar_url( $new_user->ID, [ 'default' => '404' ] );
						// Check if URL results in 404
						$response = wp_remote_get( $gravatar_url );
						$status_code = wp_remote_retrieve_response_code( $response );

						if ( 404 === $status_code ) {
							WP_CLI::log( 'User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' does not have a profile picture' );
							$success = $this->set_avatar_for_user( $new_user, null, $user_meta_result, $ftp_server, $ftp_user, $ftp_pass );
							if ( ! $success ) {
								unset( $old_users[ $new_user->ID ] );
							}
						} else {
							WP_CLI::log( 'User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' already has a profile picture' );
							echo WP_CLI::colorize("%GGravatar URL: $gravatar_url%n\n");
						}
					} else {
						WP_CLI::log( 'User ID: ' . $new_user->ID . ', User Email: ' . $new_user->user_email . ' already has a profile picture' );
						$attachment_id = $this->simple_local_avatar_logic->get_local_avatar_attachment_id( $new_user->ID );
						$attachment = get_post( $attachment_id );
						var_dump( $attachment );
					}
				}
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
            'Iván E. Reyes | Ronny S. Rodríguez Rosas' => 'Iván E. Reyes y Ronny Rodríguez',
            'Iván E. Reyes | Ronny S. Rodríguez R.' => 'Iván E. Reyes y Ronny Rodríguez',
            'Iván Reyes/ Deisy Martínez' => 'Iván E. Reyes y Deisy Martínez',
            'Albany Andara Meza | Ronny S. Rodríguez Rosas' => 'Albany Andara y Ronny Rodríguez',
            'Coalición C-Informa' => 'C-Informa',
        ];

        return ! empty( $names[ $name ] ) ? $names[ $name ] : $name;

    }

	/**
	 * @param WP_User $user
	 * @param stdClass|null $guest_author
	 * @param stdClass $meta_data
	 *
	 * @return bool
	 */
	private function set_avatar_for_user( $user, $guest_author, $meta_data, $ftp_server, $ftp_user, $ftp_pass ): bool {
		global $wpdb;

		$attachment_id = null;
		if ( is_numeric( $meta_data->meta_value ) ) {
			//Possibly pointing to an attachment ID?
			echo WP_CLI::colorize( "%M Numeric: $meta_data->meta_value%n\n" );

			$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_type = 'attachment' AND ID = %d", intval( $meta_data->meta_value ) ) );

			$attachment_id = $result?->ID;
		} else if ( is_string( $meta_data->meta_value ) && ! empty( $meta_data->meta_value ) ) {
			$filename = $meta_data->meta_value;
			$full_file_path = '';
			$data = @unserialize( $meta_data->meta_value );

			if ( is_array( $data ) ) {
				if ( empty( $data['full'] ) ) {
					echo WP_CLI::colorize( "%R Unable to find file%n\n" );
					return false;
				}
				$filename = basename( $data['full'] );
				$partial_remote_path = parse_url( $data['full'], PHP_URL_PATH );
				$partial_remote_path = str_replace( '/wp-content/uploads/', '', $partial_remote_path );
				$full_file_path = wp_upload_dir()['basedir'] . '/' . $partial_remote_path;
			}

			echo WP_CLI::colorize( "%Y String: $filename%n\n" );

			$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE '%%%s' ORDER BY ID", $meta_data->meta_value ) );

			$attachment_id = $result?->ID;

			if ( is_null( $attachment_id ) ) {
				WP_CLI::log( 'No attachment record found' );
				if ( file_exists( $full_file_path ) ) {
					echo WP_CLI::colorize( "%CFound file locally, creating attachment record for file%n\n" );
					$attachment_id = $this->attachments->import_external_file( $full_file_path, "Programmatic avatar upload for User ID $user->ID" );
				} else {
					$remote_path = '/public_html' . parse_url( $data['full'], PHP_URL_PATH );

					WP_CLI::log( "File not found locally, checking remote server - $remote_path" );

					$this->establish_ftp_connection( $ftp_server, $ftp_user, $ftp_pass );
//					$full_file_path = '/var/www/html/wp-content/uploads/ftp_test.jpg';
					$directory = str_replace( $filename, '', $full_file_path );
					if ( ! is_dir( $directory ) ) {
						WP_CLI::log( "Creating directory $directory" );
						mkdir( $directory );
					}
					WP_CLI::log( "Downloading to $full_file_path" );
					$downloaded = ftp_get( $this->ftp, $full_file_path, $remote_path );
					$this->close_ftp_connection();

					if ( $downloaded && file_exists( $full_file_path ) ) {
						echo WP_CLI::colorize( "%GFound file on remote server, creating attachment record for file%n\n" );
						$attachment_id = $this->attachments->import_external_file( $full_file_path, "Programmatic avatar upload for User ID $user->ID" );
					} else {
						echo WP_CLI::colorize( "%RUnable to download file from remote server.%n\n");
					}
				}
			}
		} else {
			echo WP_CLI::colorize( '%R Unknown type%n' . '\n' );
			var_dump( $meta_data );
			return false;
		}

		if ( ! is_null( $attachment_id ) ) {
			if ( ! is_null( $guest_author ) ) {
				echo WP_CLI::colorize( "%GFound file in DB, Attachment ID: $attachment_id, setting profile for Guest Author%n\n" );
				set_post_thumbnail( $guest_author->ID, $attachment_id );
			} else {
				echo WP_CLI::colorize( "%GFound file in DB, Attachment ID: $attachment_id, setting profile for regular User%n\n" );
				$this->simple_local_avatar_logic->assign_avatar( $user->ID, $attachment_id );
			}
			return true;
		} else {
			echo WP_CLI::colorize( "%R Unable to find file%n\n" );
			return false;
		}
	}

	/**
	 * Establishes an FTP connection
	 *
	 * @param $ftp_server
	 * @param $ftp_user
	 * @param $ftp_pass
	 *
	 * @return void
	 */
	private function establish_ftp_connection( $ftp_server, $ftp_user, $ftp_pass ) {
		$this->ftp = ftp_ssl_connect( $ftp_server );
		$login = ftp_login( $this->ftp, $ftp_user, $ftp_pass );

		if ( ! $this->ftp || ! $login ) {
			echo WP_CLI::colorize( "%R Unable to connect to FTP server%n\n" );
			throw new Exception( 'Unable to connect to FTP server' );
		}

		ftp_pasv( $this->ftp, true );
	}

	/**
	 * Closes active FTP connection
	 *
	 * @return void
	 */
	private function close_ftp_connection() {
		if ( ! is_null( $this->ftp ) ) {
			ftp_close( $this->ftp );
		}
	}
}
