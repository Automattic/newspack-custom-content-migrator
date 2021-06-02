<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Que Pasa.
 */
class QuePasaMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic = new AttachmentsLogic();
		$this->crawler           = new Crawler();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator quepasa-set-featured-images-post-launch',
			[ $this, 'cmd_set_feat_images_post_launch' ],
			[
				'shortdesc' => 'Sets additionally identified missing Featured images; the origin DB was a bit faulty, which is why these are updated individually.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator quepasa-fix-missing-images',
			[ $this, 'cmd_fix_missing_images' ],
			[
				'shortdesc' => 'Goes through a list of predefined images pulled out by Ben from Que Pasa, and fixes their use.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator quepasa-set-featured-images-post-launch`.
	 */
	public function cmd_set_feat_images_post_launch( $args, $assoc_args ) {
		global $wpdb;

		$i = 0;
		$posts_images = $this->get_poststitles_featuredimages();

		foreach ( $posts_images as $post_title => $img_url ) {
			WP_CLI::line( sprintf( "%d/%d Title '%s' Img %s", ++$i, count( $posts_images ), $post_title, $img_url ) );

			$results_post_ids = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->prefix}posts wp
				WHERE wp.post_type = 'post'
				AND wp.post_title = %s ;",
				$post_title
			) );
			if ( count( $results_post_ids ) > 1 ) {
				$msg = sprintf( "Multiple Posts with title '%s' found, skipping.", $post_title );
				WP_CLI::warning( $msg );
				$this->log( 'featured_images__multipletitles.log', $msg );
				continue;
			}

			$post_id = $results_post_ids[0]->ID;

			$attachment_id = $this->attachments_logic->import_external_file( $img_url );
			if ( is_wp_error( $attachment_id ) ) {
				$msg = sprintf( 'Error downloading URL %s : %s', $img_url, $attachment_id->get_error_message() );
				WP_CLI::warning( $msg );
				$this->log( 'featured_images__err.log', $msg );
				continue;
			}

			$updated = set_post_thumbnail( $post_id, $attachment_id );
			if ( false === $updated ) {
				$msg = sprintf( 'Error setting att.id %d to Post ID %d', $attachment_id, $post_id );
				$this->log( 'featured_images__err.log', $msg );
				WP_CLI::warning( $msg );
				continue;
			}

			$msg = sprintf( 'PostID %d AttID %d', $post_id, $attachment_id );
			WP_CLI::success( $msg );
			$this->log( 'featured_images__updated.log', $msg );
		}

		WP_CLI::line( 'Done.' );
	}

	/**
	 * Callable for the `newspack-content-migrator quepasa-fix-missing-images`.
	 */
	public function cmd_fix_missing_images( $args, $assoc_args ) {
		global $wpdb;

		$time_start = microtime( true );
		$file_with_images = '/srv/www/0_data_no_backup/0_quepasa/4_bens_missing_images/ben_newspack_article_body_images_urls.txt';
		$images = explode( "\n", file_get_contents( $file_with_images ) );

		// Loading all post_contents to memory makes the searches much faster. But, requires too much memory (!)...
		// $post_contents = $this->get_post_contents();

		// Go through all the images and search for their uses in existing Posts.
		foreach ( $images as $key_image => $image ) {
			WP_CLI::line( sprintf( '(%d/%d) %s', $key_image + 1, count( $images), $image ) );

			/**
			 * @param string $image_url e.g. 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/0011410793.jpg'.
			 */
			$image_url = trim( $image );
			/**
			 * @param string $image_no_host e.g. 'wp-content/uploads/2015/04/0011410793.jpg'.
			 */
			$image_no_host = str_replace( 'https://newspack.quepasamedia.com/', '', $image_url );
			/**
			 * @param string $image_no_host_ending_slash e.g. '/wp-content/uploads/2015/04/0011410793.jpg'.
			 */
			$image_no_host_with_beginning_slash = '/' . $image_no_host;
			/**
			 * @param string $image_s3 e.g. 'https://qpwebsite.s3.amazonaws.com/uploads/2021/05/157537120_3883791018310121_5726006756605356791_n.jpeg'.
			 */
			$image_s3 = str_replace( 'wp-content/uploads/', 'https://qpwebsite.s3.amazonaws.com/uploads/', $image_no_host );

			// $ids = $this->get_ids_with_content( $post_contents, $image_no_host );
			WP_CLI::line( ' ... querying DB...' );
			$ids = $this->get_post_ids_with_image_query_DB( $image_no_host );
			if ( empty( $ids ) ) {
				WP_CLI::warning( 'image not found in any Post.' );
				$this->log( 'missingimgs_imgNotFoundInPosts.log', $image_url );
				continue;
			}

			foreach ( $ids as $key_ids => $id ) {
				WP_CLI::line( sprintf( '   - img found in ID %d', $id ) );

				$post = get_post( $id );

				// Double check we're covering all the `src` occurrences -- all forms of URLs, absolute and relative.
				if ( false === $this->check_if_only_relative_src_found( $post, $image_no_host ) ) {
					WP_CLI::warning( 'Found URLs different than relative with starting `/`!' );
					$this->log( 'missingimgs_imgNotRelative.log', sprintf( '%d %s', $post->ID, $image_no_host ) );
				}

				if ( $this->does_image_exist_on_s3( $image_s3 ) ) {
					WP_CLI::success( sprintf( '   + exists on S3' ) );

					// If image exists in the S3 bucket, update the relative URLs to the fully qualified S3 URLs.
					$post_content_updated = $post->post_content;
					$post_content_updated = str_replace( $image_no_host_with_beginning_slash, $image_s3, $post_content_updated );

					if ( $post_content_updated != $post->post_content ) {
						$wpdb->update( $wpdb->prefix . 'posts', array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
						WP_CLI::success( sprintf( sprintf( '   + Replaced %s with %s', $image_no_host_with_beginning_slash, $image_s3) ) );
						$this->log( 'missingimgs_replacedWithS3Url.log', sprintf( '%d %s %s', $post->ID, $image_no_host_with_beginning_slash, $image_s3) );
					} else {
						$this->log( 'missingimgs_noReplacementsMade_s3.log', sprintf( '%d %s', $post->ID, $image_no_host ) );
					}
				} else {
					WP_CLI::success( sprintf( '   + does not exist on S3, now downloading' ) );

					// Or else download the image from the original `newspack.quepasamedia.com` host, and update the `src`s.
					$attachment_id = $this->attachments_logic->import_external_file( $image_url );
					if ( is_wp_error( $attachment_id ) ) {
						WP_CLI::warning( sprintf( 'Error downloading URL %s : %s', $image_url, $attachment_id->get_error_message() ) );
						$this->log( 'missingimgs_downloadFailed.log', $image_url );
						continue;
					}
					$image_url_new = wp_get_attachment_url( $attachment_id );

					$post_content_updated = $post->post_content;
					$post_content_updated = str_replace( $image_no_host_with_beginning_slash, $image_url_new, $post_content_updated );
					if ( $post_content_updated != $post->post_content ) {
						$wpdb->update( $wpdb->prefix . 'posts', array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
						WP_CLI::success( sprintf( sprintf( '   + Replaced %s with %s', $image_no_host_with_beginning_slash, $image_s3) ) );
						$this->log( 'missingimgs_replacedWithNewlyDownloaded.log', sprintf( '%d %s %s', $post->ID, $image_no_host_with_beginning_slash, $image_s3) );
					} else {
						$this->log( 'missingimgs_noReplacementsMade_download.log', sprintf( '%d %s', $post->ID, $image_no_host ) );
					}
				}
			}
		}

		// Required for the $wpdb->update() to sink in.
		wp_cache_flush();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Just an extra check for the image URL. I'm assuming all the srcs are relative URLs beginning with the '/',
	 * but we're going to check all the `src` for whether this image appeared in a different formats.
	 *
	 * @param WP_Post $post            Post.
	 * @param string  $img_src_no_host Image URL without host and without the beginning '/'.
	 *
	 * @return bool True if img src is relative beginning with '/', false if not.
	 */
	private function check_if_only_relative_src_found( $post, $img_src_no_host ) {
		$this->crawler->clear();
		$this->crawler->add( $post->post_content );
		$srcs = $this->crawler->filterXpath( '//img' )->extract( [ 'src' ] );

		foreach ( $srcs as $src ) {
			$pos_img_no_host = strpos( $src, $img_src_no_host );
			$pos_img_no_host_beginning_with_slash = strpos( $src, '/' . $img_src_no_host );

			// If src URL is matched, but does not begin with '/'.
			if ( false !== $pos_img_no_host && 0 !== $pos_img_no_host_beginning_with_slash ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks if the image is available at the S3 URL.
	 *
	 * @param string $url S3 image URL.
	 *
	 * @return bool
	 */
	private function does_image_exist_on_s3( $url ) {
		return false !== strpos( $this->get_response_code( $url ), '200 OK' );
	}

	/**
	 * Makes a request and returns the response code with the status.
	 *
	 * @param string $url URL.
	 *
	 * @return false|string|null The numeric response code + status part of the get_geaders().
	 */
	private function get_response_code( $url ) {
		// $headers = get_headers( $url, 1 );
		$headers = get_headers( $url );
		if ( is_array( $headers ) ) {
			$status = substr( $headers[0], 9 );
		}

		return $status ?? null;
	}

	/**
	 * Fetches all posts' post_contents.
	 *
	 * @return array Keys are Post IDs, values are post_content.
	 */
	private function get_post_contents() {
		global $wpdb;
		$posts_contents = [];

		$results = $wpdb->get_results( "SELECT ID, post_content FROM `{$wpdb->posts}` WHERE post_type='post';" );
		foreach ( $results as $result ) {
			$posts_contents[ $result->ID ] = $result->post_content;
		}

		return $posts_contents;
	}

	/**
	 * Searches the $posts_contents and returns the IDs which contain the $needle.
	 *
	 * @param array  $posts_contents Results of the self::get_post_contents().
	 * @param string $needle         String to search for in post_content.
	 *
	 * @return array IDs which contain the needle.
	 */
	private function get_ids_with_content( $posts_contents, $needle ) {
		$ids = [];
		foreach ( $posts_contents as $id => $post_content ) {
			$found = false !== strpos( $post_content, $needle );
			if ( $found ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Gets IDs of Posts which contain the search string.
	 *
	 * @param string $subject Search subject.
	 *
	 * @return array IDs.
	 */
	private function get_post_ids_with_image_query_DB( $subject ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT ID FROM `{$wpdb->posts}` WHERE post_type='post' AND post_content LIKE %s;",
			'%'. $wpdb->esc_like( $subject ) . '%'
		);

		$results = $wpdb->get_results( $sql, ARRAY_N );
		if ( empty( $results ) ) {
			return [];
		}

		$ids = [];
		foreach ( $results as $result ) {
			$ids[] = $result[0];
		}

		return $ids;
	}

	/**
	 * Simple logging.
	 *
	 * @param string $file_path Full file path.
	 * @param string $message   Logging message
	 */
	private function log( $file, $message ) {
		file_put_contents( $file, $message . "\n", FILE_APPEND );
	}

	/**
	 * Post titles with their Featured images.
	 * Programmatically created from data in $this->get_wpconversionsite_posts_featuredimages().
	 *
	 * @return string[] Post title as key, Featured image to download and use for the Post.
	 */
	private function get_poststitles_featuredimages() {
		return array (
			'La comunicación en la pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-507546185.jpg',
			'La importancia de los halagos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-471344257.jpg',
			'El problema de la timidez' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-56515123.jpg',
			'Vivir es decidir' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-174394329.jpg',
			'Los chicos y las redes sociales: lo que hay que saber' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/Los_chicos_y_las_redes_sociales_lo_que_hay_que_saber.jpg',
			'Un desafío: aceptar las cosas como son' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/aceptar_las_cosas_como_son.jpg',
			'¿Nuestras creencias influyen en el envejecimiento?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/envejecer.jpg',
			'Alerta: Abuso sexual infantil' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/481013297.jpg',
			'Aprender a discutir' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/aprenda_a_discutir.jpg',
			'Pequeños pasos, grandes resultados' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/487206341.jpg',
			'¡BIENVENIDO 2015!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/469193513.jpg',
			'Mundo real y mundo virtual' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/mundo_real_y_mundo_virtual.jpg',
			'¿Leer beneficia la salud mental?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/06/thinkstockphotos-87176861.jpg',
			'Reforma migratoria: Los números, las voces y el miedo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			'El hogar de los sin hogar' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			'El proyecto de ley HB 786: El debate' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			'El hijo del medio' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			'¿Se puede aprender a ser feliz?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/05/thinkstockphotos-466290409.jpg',
			'¿Es normal tener olvidos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/05/thinkstockphotos-471798792.jpg',
			'Corrupción y escándalo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/06/10847325w.jpg',
			'¿Todos somos ansiosos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/10/ansiedad.jpg',
			'El infierno de las drogas' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/10/drug-addict.jpg',
			'VIOLENCIA DE GÉNERO' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/06/violenciadegenero.jpg',
			'Ser homosexual' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/06/serhomosexual.jpg',
			'Cuidarse/Cuidarnos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/06/cuidarsecuidarnos.jpg',
			'¿Qué es el Ghosting?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/07/queeselchosting.jpg',
			'Maltrato al Adulto Mayor' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/06/thinkstockphotos-122399312.jpg',
			'El abuso emocional' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/11/emotionalabuse.jpg',
			'En tiempos del amor líquido' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/11/amorliquido.jpg',
			'Sexo y tecnología' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/11/sexoytecnologia.jpg',
			'En el país de la  esperanza' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/11/esperanza.jpg',
			'Día Internacional de la Eliminación de la Violencia contra la Mujer' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/11/11289019w.jpg',
			'Locura y muerte en Charleston' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/06/635703447687306911-scaled.jpg',
			'Decir que no' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/12/decirqueno.jpg',
			'¿Qué es la depresión?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_queesladepre.jpg',
			'¿Cómo descubrir a un mentiroso?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/07/thinkstockphotos-466945514.jpg',
			'Comunicación y nuevas tecnologías' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/12/friends-on-the-phone.jpg',
			'Las apps del amor' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/07/losappsdelamor00.jpg',
			'Es estrés de fin de año' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/12/esestres.jpg',
			'La violencia psicológica en la pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-482888978.jpg',
			'Reflexiones para fin de año' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/12/reflexionesfindeano.jpg',
			'Un nuevo síntoma: el cansancio' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-470761047.jpg',
			'Mamá, papá: ¡No me griten!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/07/nogriten00.jpg',
			'¡Bienvenido 2016!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/01/depositphotos_70602239_m-2015.jpg',
			'¿Para qué sirven las emociones?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-185194947.jpg',
			'El hábito de mentir' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-478241581.jpg',
			'El poder de los libros' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/07/elpoderdeloslibros.jpg',
			'Cultivar el buen humor' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/08/cultivarelbuenhumor.jpg',
			'Bienvenido 2016' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/01/bienvenido2016.jpg',
			'Los compradores compulsivos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/09/loscompradores.jpg',
			'Buenas noticias: ¡leer es bueno!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/09/leer-es-bueno.jpg',
			'Adicciones: un problema social' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/09/rat-park.jpg',
			'El alcoholismo:  Un problema de salud' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/10/elalcoholismo.jpg',
			'Deportaciones, miedo e incertidumbre' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/01/deportacionesmiedosinsertid.jpg',
			'Los niños y la educación' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/10/losninosylaeducacion.jpg',
			'¡A cuidarse de los manipuladores!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-468434092-scaled.jpg',
			'Mantener la mente en forma' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/10/mantener00.jpg',
			'Hombres violentos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/10/hombresviolentos.jpg',
			'Hablemos del miedo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/01/hablemosdelmiedo.jpg',
			'Los problemas del poder' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/10/losproblemas.jpg',
			'Celos que enferman: El Síndrome de Otelo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/11/celos.jpg',
			'Cómo manejar la ansiedad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/01/comomanejarlaansiedad.jpg',
			'¿Es fácil olvidar a un ex?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/09/thinkstockphotos-467983993-scaled.jpg',
			'Palabras de amor' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/02/palabrasdeamor.jpg',
			'Sexo virtual y Ciber-infidelidad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/09/concept-of-cybersex-or-in-011.jpg',
			'Escuela para padres' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/11/escuelaparapadres.jpg',
			'Migración y xenofobia' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/11/migracion.jpg',
			'¿Mentir nos cambia?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/11/mentirnoscambia.jpg',
			'¿Qué es ser impulsivo?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/10/af5593c0-cb6c-4e4f-9adb-523d8e79613b.jpeg',
			'¡Estamos conectados!' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/02/conectados.jpg',
			'¿Problemas para dormir?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/11/problemaspara.jpg',
			'Fortalecer los vínculos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/02/fortalecerlosvinculos.jpg',
			'¿Nos deprime el invierno?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/02/nosdeprimeelinvierno.jpg',
			'El cansancio y la falta de tiempo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/12/cansancio.jpg',
			'El estrés navideño' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/12/elestresnavideno.jpg',
			'Violencia en el hogar' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/10/violenciadomestica.jpg',
			'La vida en Facebook' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/03/facebook1.jpg',
			'Cuando el amor está en crisis' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/12/cuandoelamor.jpg',
			'Abuso sexual infantil' => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/10/child-abuse.jpg',
			'Empiece un año sin estrés  El estrés laboral: un mal de nuestro tiempo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/01/empieceun.jpg',
			'¿El mal humor crónico puede  ser una enfermedad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/01/almalhumor.jpg',
			'¿Cómo vivir en la era del multitasking?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/01/comovivirenlaeradel.jpg',
			'¿Cómo elaborar las pérdidas?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/01/comoelaborarlas.jpg',
			'¿Qué es la felicidad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/02/b2_queeslafelicidad.jpg',
			'El alcohol y los accidentes de tránsito' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/03/elalcoholylosaccidentesdetr.jpg',
			'¿Cómo mejorar su estado de ánimo?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_comomejorarsuestadodeani.jpg',
			'Día Mundial del Sueño' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/03/diamundialdelsueno.jpg',
			'La soledad y sus vaivenes' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/04/lasoledadysusvalvenes_0.jpg',
			'Cambios generacionales' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_cambiosgeneracionales-scaled.jpg',
			'El cuerpo habla' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_elcuerpohabla.jpg',
			'¿Tener  amigos mejora la salud?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_teneramigosmejoralasalud.jpg',
			'6 comportamientos de los niños que los padres no deben pasar por alto' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_6comportamientosdelosnin.jpg',
			'Hablemos de fobias' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/07/hablemosdefobias.jpg',
			'Atención: llegó la Generación Z' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/07/atencionllegla.jpg',
			'¿Qué son los trastornos de ansiedad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/07/quesonlos-1.jpg',
			'El ejercicio físico y el cerebro' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/07/elejerciciofisico.png',
			'¿Cómo lidiar con los celos entre hermanos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/08/comolidiarcon.jpg',
			'Las apariencias no engañan' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/08/lasaparienciasno.jpg',
			'Las pesadillas en la infancia' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/08/pesadillasenla.jpg',
			'Una cuestión de actitud' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/08/unacuestionde.jpg',
			'Vida en pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/09/vidaenpareja.jpg',
			'¿Cómo ponerse a salvo de lo negativo?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/09/negativity.jpg',
			'El amor y las redes sociales' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/09/elamorylas.jpg',
			'La bulimia y la anorexia no son lo mismo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/10/labulimiay.jpg',
			'¿Qué hacer si siente que su pareja ya no lo ama como antes?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/10/quehacersi.jpg',
			'La soledad: un problema de salud' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/10/lasoledadun.jpg',
			'¿Qué es la depresión otoñal?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/10/queesla.jpg',
			'Amores on-line' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/10/amoreeninternet.jpg',
			'¿Cómo ayudar a alguien que sufre?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/11/comayudara.jpg',
			'La comezón del séptimo año' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/11/lacomezondel.jpg',
			'Reír es saludable' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/11/reiressaludable.jpg',
			'¿Problemas con el alcohol?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/11/problemas-de-alcohol.jpg',
			'Ser padre: un trabajo a tiempo completo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/12/padreehijo.jpg',
			'Cuando el estrés nos supera' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/12/cuandoelestres.jpg',
			'La Navidad de los niños' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/12/lanavidaddelos.jpg',
			'Palabras de fin de año' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/12/palabrasdefin.jpg',
			'Los inicios' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/01/losinicios.jpg',
			'El agotamiento emocional: un signo de estos tiempos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/01/elagotamientoemocional.jpg',
			'El valor del tiempo y la impuntualidad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/01/elvalordel.jpg',
			'El amor y sus secretos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/01/elamorysus.jpg',
			'¿Cómo lidiar con los niños que no hacen caso?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/01/comolidiarcon.jpg',
			'Hábitos tóxicos en la pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/02/habitostoxicosen.jpg',
			'Los nueve enemigos de la felicidad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/02/losnueveenemigos.jpg',
			'¿Cómo lidiar con las peleas en la pareja?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/02/comolidearcon.jpg',
			'¿Cómo compartir tiempo de calidad con los hijos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/03/comocompartirtiempo.jpg',
			'¿De qué nos arrepentimos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/03/enfermo.jpg',
			'Atención: su hijo puede estar sufriendo bullying' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/03/atencionsuhijo.jpg',
			'¿Qué es el abuso emocional?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/04/queeselabuso.jpg',
			'¿Qué es la depresión posparto?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/04/queesladepresion.jpg',
			'Stalkear en redes sociales: una obsesión que crece' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/04/stalkerenredes.jpg',
			'La ira y sus efectos en la vida' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/04/lairaysus.jpg',
			'¿Qué es el bloqueo mental?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/05/bloqueomental.jpg',
			'¿Y si mejoramos nuestra autoestima?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/06/ysimejoramos.jpg',
			'¿Es usted una persona controladora?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/05/esustedunapersona.jpg',
			'¿Qué son las emociones?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/05/quesonlas.jpg',
			'El gran problema del alcohol' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/06/elgranproblema.jpg',
			'¿Cómo reconocer una personalidad pesimista?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/06/comoreconoceruna.jpg',
			'¿Qué son los micromachismos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/06/quesonlos.jpg',
			'¿Qué son las habilidades sociales?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/07/habilidadessociales.jpg',
			'Trolls, haters, stalkers… ¿Quién es quién en las redes sociales?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/07/trollshatersstalkers.jpg',
			'¿Cómo detectar un noviazgo violento en la adolescencia?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/07/amparo_violencia.jpg',
			'Millennials: la generación cansada' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/07/millennials.jpg',
			'¿Qué es el delirium tremens?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/08/deliriumtremens.jpg',
			'¿Qué es la parálisis del sueño?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/08/paralisis_sueno.jpg',
			'¿Qué es el Breadcrumbing?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/08/breadcrumbing.jpg',
			'¿Cómo nos comunicamos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/08/comunica.jpg',
			'Seis señales de peligro en una pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/09/pareja.jpg',
			'El duelo en los niños' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/09/ninoduelo.jpg',
			'¿Qué son los ataques de pánico?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/09/respirando.jpg',
			'¿Qué es el Síndrome de Diógenes?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/09/digital-diogenes.jpg',
			'¿Qué es la personalidad narcisista?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/10/narcisista.jpg',
			'El mosting: prometer amor eterno y desaparecer' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/10/amparo-mosting.jpg',
			'Cuando los pensamientos te abruman' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/10/thinking.jpg',
			'Cuídese de la depresión otoñal' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/10/depression.jpg',
			'¿Qué es el autismo?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/10/autista.jpg',
			'Utilice el pensamiento lateral' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/11/lateral_thinking.jpg',
			'Lo que hay que saber sobre el lenguaje corporal' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/11/lenguajecorporal.jpg',
			'Conozca el Síndrome de Münchausen' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/11/munchausen.jpg',
			'El Síndrome del Emperador: los niños tiranos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/11/emperor-kid.jpg',
			'La obsesión por comer “sano”' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/12/woman_nutrition.jpg',
			'Ansiedad nocturna: cuando la mente no nos deja dormir' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/12/insomnia.jpg',
			'Cómo lidiar con los conflictos navideños' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/12/arguing.jpg',
			'Bienvenido 2020' => 'http://newspack.quepasamedia.com/wp-content/uploads/2019/12/2020.jpg',
			'¿Cómo alcanzar los propósitos 2020?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/01/ny_resoultions.jpg',
			'Mitomanía: cuando mentir es enfermizo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/01/liar.jpg',
			'Ecoansiedad: el impacto del cambio climático en la salud mental' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/01/global_warming.jpg',
			'¿Conoce a alguna persona vanidosa?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/01/vanity.jpg',
			'Cuando las emociones son inaccesibles' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/01/emotions.jpg',
			'¿Cómo lidiar con adultos inmaduros?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/02/immature-_adult.jpg',
			'Celebrando San Valentín' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/02/family_love.jpg',
			'El síndrome del caballero blanco: personas que necesitan salvar a los demás' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/02/white_knight.jpg',
			'La importancia de los buenos modales' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/02/good_manners.jpg',
			'Cómo detectar a una persona egoísta' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/03/selfish.jpg',
			'El síndrome de Houdini' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/03/runaway.jpg',
			'El difícil arte de vivir en pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/03/loving_couple.jpg',
			'Coronavirus: responsabilidad social y cambio de hábitos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/03/social_distance.jpg',
			'¿Cómo cuidar la salud mental en época de pandemia?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/04/depressed_in_window.jpg',
			'¿Cómo cuidar la salud mental en época de pandemia? (parte 2)' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/04/coro_mental_health.jpg',
			'Cuide la estabilidad emocional durante la crisis del Coronavirus' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/04/emotional.jpg',
			'Tiempo de pausa' => 'http://newspack.quepasamedia.com/wp-content/uploads/2020/04/pause_0.jpg',
			'Día Mundial de la Salud' => 'http://newspack.quepasamedia.com/wp-content/uploads/2021/04/dia_mundial_de_la_salud_222.jpg',
			'¿BUSCAR LA FELICIDAD O CREARLA?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_buscarlafelicidadocrearl.jpg',
			'¿Qué es la inteligencia?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_queeslainteligencia.jpg',
			'¿Cómo combatir la tendencia a dejar las cosas para mañana?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/03/b4_comocombatirlatendenciaa.jpg',
			'¿Es bueno vivir apurado?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_esbuenovivirapurado-scaled.jpg',
			'¿LO HAGO O NO LO HAGO? EL DILEMA DEL INDECISO' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_lohagoonolohago-scaled.jpg',
			'PENSAR POR UNO MISMO' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_pensarporunomismo.jpg',
			'Tendencias que hacen que una pareja sea feliz' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_tendenciasquehacenqueuna.png',
			'Cuidarse de las amistades tóxicas' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/04/cuidarsedelasamistades.jpg',
			'Un mundo de emociones' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/04/depositphotos_23964499_l-2015.jpg',
			'¿Está aumentando el nivel de estrés en la población?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_estaaumentandoelniveldee.png',
			'¿Cómo aprovechar las oportunidades?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_comoaprovecharlasoportun.jpg',
			'El sexo, el amor y la pareja' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/05/elsexoelamorylapareja.jpg',
			'Pedir perdón' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/05/pedirperdon.jpg',
			'El estrés: ¿un enemigo de la sexualidad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/05/elestresunenemigodelasexual.jpg',
			'¿Cómo ayudar a una persona que está deprimida?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2016/05/comoayudaraunapersona.jpg',
			'Cómo mejorar la atención  en la era de la distracción' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_comomejorarlaatencionenl.png',
			'El alcoholismo en la familia' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_elalcoholismoenlafamilia.jpg',
			'¿Qué es el ataque de pánico?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_queeselataquedepanico-scaled.jpg',
			'8 señales para reconocer el estrés a tiempo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_8senalesparareconocerele.png',
			'¿Por qué es malo dormir poco?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_porqueesmalo.jpg',
			'El secreto de la felicidad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/11/elsecreto.jpg',
			'Cuando el juego no es juego' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_cuandoeljuegonoesjusto.jpg',
			'El sentido de la vida' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_elsentidodelavida.jpg',
			'Un cuento de Navidad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_uncuentodenavidad.jpg',
			'¿Cómo gestionar la ira?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_comogestionarlaira.png',
			'¿Qué tal si lo empezamos con todo?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_quetalsiloempezamos.jpg',
			'El amor en el 2018' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_elamorenel2018.jpg',
			'El invierno nos deprime' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_elinviernonosdeprime.jpg',
			'La teoría del apego' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_lateoriadelapego.jpg',
			'¿Somos adictos al Facebook?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/06/b6_somosadictosalfacebook.png',
			'¿La tecnología nos hace más felices?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/02/b6_latecnologianoshacemasfe.jpg',
			'¿Existe la amistad entre el hombre y la mujer?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/02/existelaamistad.jpg',
			'Sentimientos que enferman' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/02/sentiminetosque.jpg',
			'¿Vivimos ansiosos?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/02/vivimosansiosos.jpg',
			'Trastornos alimenticios del siglo XXI' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/06/b6_trastornosalimentosdelsi.png',
			'¿Qué es la Inteligencia Emocional?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/06/inteligencia-emocional-goleman1.jpg',
			'Día Internacional de la Mujer' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/03/diainternacional.jpg',
			'El peso de la infancia en la baja autoestima' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_elpasodelainfanciaenlaba.jpg',
			'¿Qué dice la medicina de la soledad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/03/quedicela.jpg',
			'Gaslighting: una forma de abuso emocional' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/07/gaslighting.jpg',
			'La felicidad, el tiempo y el espacio' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/03/lafelicidadel.jpg',
			'Las 5 etapas del duelo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_las5etapasdelduelo.jpg',
			'¿Existen las personas tóxicas?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/03/personastoxicas.jpg',
			'¿Qué es el lenguaje corporal?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_queesellenguajecorporal.jpg',
			'Prevenir el alcoholismo' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/04/prevenirelalcohiolismo.jpg',
			'El amor cuenta' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/04/elamorcuenta.jpg',
			'A todos nos puede pasar' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_atodosnospuedepasar.jpg',
			'Cuando las preocupaciones complican la vida' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/04/cuandolaspreocupa.jpg',
			'Leer para entrenar la mente' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/08/o-top-fantasy-books-facebook.jpg',
			'Las malas compañías' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_lasmalascompanias.jpg',
			'¿Cómo manejar la inseguridad?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/04/comomenajarla.jpg',
			'El fantasma de la soledad' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_elfantasmadelasoledad.jpg',
			'La tragedia de los celos' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/05/b6_latragediadeloscelos.jpg',
			'4 trucos para mejorar su memoria' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/05/4trucospara.jpg',
			'Hora de ir a dormir' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/08/horadedormir.png',
			'Los beneficios de la risa' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/05/losbeneficiosde.jpg',
			'Hasta El Hueso' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_hastaelhueso.jpg',
			'Los adolescentes en tiempos del Smartphone' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/09/los_adolescentes.1.jpg',
			'Todo es cuestión de actitud' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_todoescuestiondeactitud.jpg',
			'Memoria, redes sociales y efecto Google' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/05/memoriaredessociales-scaled.jpg',
			'Dar para estar mejor' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_darparaestarmejor.jpg',
			'¿Qué es el estrés postraumático?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_queeselestrespostraumati.jpg',
			'12 señales de baja autoestima' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/05/12senalesde-scaled.jpg',
			'“Micro-cheating”, ¿la nueva forma de engañar?' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/06/microcheatingla.jpg',
			'Aprender a cuidarse' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/06/aprenderacuidarse.jpg',
			'Uso y abuso de las redes sociales' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/10/uso_abuso-scaled.jpg',
			'Un poco de filosofía' => 'http://newspack.quepasamedia.com/wp-content/uploads/2018/06/unpocode.jpg',
			'Los celos en los tiempos del smartphone' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_loscelosenlostiemposdel.jpg',
			'Aprender a comunicarse' => 'http://newspack.quepasamedia.com/wp-content/uploads/2017/10/comunicacion.jpg',
		);
	}

	/**
	 * WP conversion site's Post IDs with their Featured images.
	 *
	 * @return string[] Post ID as key, featured image as value.
	 */
	private function get_wpconversionsite_posts_featuredimages() {
		return [
			1391154 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-507546185.jpg',
			1391156 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-471344257.jpg',
			1391160 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-56515123.jpg',
			1391162 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-174394329.jpg',
			1391164 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/Los_chicos_y_las_redes_sociales_lo_que_hay_que_saber.jpg',
			1391166 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/aceptar_las_cosas_como_son.jpg',
			1391168 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/envejecer.jpg',
			1391170 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/481013297.jpg',
			1391172 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/aprenda_a_discutir.jpg',
			1391174 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/487206341.jpg',
			1391176 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/469193513.jpg',
			1391178 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/mundo_real_y_mundo_virtual.jpg',
			1391193 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/06/thinkstockphotos-87176861.jpg',
			1391243 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			1391250 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			1391251 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			1391291 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/04/ClaudiaAleman.jpg',
			1391316 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/05/thinkstockphotos-466290409.jpg',
			1391318 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/05/thinkstockphotos-471798792.jpg',
			1391320 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/06/10847325w.jpg',
			1391321 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/10/ansiedad.jpg',
			1391323 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/10/drug-addict.jpg',
			1391326 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/06/violenciadegenero.jpg',
			1391328 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/06/serhomosexual.jpg',
			1391330 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/06/cuidarsecuidarnos.jpg',
			1391332 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/06/10847325w.jpg',
			1391333 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/07/queeselchosting.jpg',
			1391336 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/06/thinkstockphotos-122399312.jpg',
			1391337 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/11/emotionalabuse.jpg',
			1391340 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/11/amorliquido.jpg',
			1391342 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/11/sexoytecnologia.jpg',
			1391344 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/11/esperanza.jpg',
			1391346 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/11/11289019w.jpg',
			1391348 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/06/635703447687306911-scaled.jpg',
			1391350 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/12/decirqueno.jpg',
			1391351 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/07/thinkstockphotos-492569143.jpg',
			1391354 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/07/thinkstockphotos-466945514.jpg',
			1391356 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/12/friends-on-the-phone.jpg',
			1391357 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/07/losappsdelamor00.jpg',
			1391359 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/12/esestres.jpg',
			1391362 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-482888978.jpg',
			1391363 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/12/reflexionesfindeano.jpg',
			1391366 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-470761047.jpg',
			1391367 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/07/nogriten00.jpg',
			1391369 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/01/depositphotos_70602239_m-2015.jpg',
			1391370 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-185194947.jpg',
			1391374 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-478241581.jpg',
			1391375 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/07/elpoderdeloslibros.jpg',
			1391378 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/08/cultivarelbuenhumor.jpg',
			1391380 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/01/bienvenido2016.jpg',
			1391381 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/09/loscompradores.jpg',
			1391384 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/09/leer-es-bueno.jpg',
			1391386 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/09/rat-park.jpg',
			1391388 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/10/elalcoholismo.jpg',
			1391390 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/01/deportacionesmiedosinsertid.jpg',
			1391391 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/10/losninosylaeducacion.jpg',
			1391392 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/08/thinkstockphotos-468434092-scaled.jpg',
			1391396 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/10/mantener00.jpg',
			1391398 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/10/hombresviolentos.jpg',
			1391400 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/01/hablemosdelmiedo.jpg',
			1391402 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/10/losproblemas.jpg',
			1391404 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/11/celos.jpg',
			1391405 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/01/comomanejarlaansiedad.jpg',
			1391408 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/09/thinkstockphotos-467983993-scaled.jpg',
			1391409 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/02/palabrasdeamor.jpg',
			1391412 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/09/concept-of-cybersex-or-in-011.jpg',
			1391413 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/11/escuelaparapadres.jpg',
			1391416 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/11/migracion.jpg',
			1391418 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/11/mentirnoscambia.jpg',
			1391419 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/10/af5593c0-cb6c-4e4f-9adb-523d8e79613b.jpeg',
			1391422 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/02/conectados.jpg',
			1391423 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/11/problemaspara.jpg',
			1391425 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/02/fortalecerlosvinculos.jpg',
			1391428 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/02/nosdeprimeelinvierno.jpg',
			1391429 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/12/cansancio.jpg',
			1391432 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/12/elestresnavideno.jpg',
			1391434 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/10/violenciadomestica.jpg',
			1391435 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/03/facebook1.jpg',
			1391436 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/12/cuandoelamor.jpg',
			1391442 => 'https://newspack.quepasamedia.com/wp-content/uploads/2015/10/child-abuse.jpg',
			1391443 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/01/empieceun.jpg',
			1391445 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/01/almalhumor.jpg',
			1391447 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/01/comovivirenlaeradel.jpg',
			1391449 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/01/comoelaborarlas.jpg',
			1391453 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/02/b2_queeslafelicidad.jpg',
			1391457 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/03/elalcoholylosaccidentesdetr.jpg',
			1391459 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_comomejorarsuestadodeani.jpg',
			1391462 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/03/diamundialdelsueno.jpg',
			1391464 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/04/lasoledadysusvalvenes_0.jpg',
			1391466 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_cambiosgeneracionales-scaled.jpg',
			1391468 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_elcuerpohabla.jpg',
			1391469 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/02/b6_teneramigosmejoralasalud.jpg',
			1391471 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_6comportamientosdelosnin.jpg',
			1391475 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/07/hablemosdefobias.jpg',
			1391477 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/07/atencionllegla.jpg',
			1391479 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/07/quesonlos-1.jpg',
			1391481 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/07/elejerciciofisico.png',
			1391483 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/08/comolidiarcon.jpg',
			1391485 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/08/lasaparienciasno.jpg',
			1391487 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/08/pesadillasenla.jpg',
			1391489 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/08/unacuestionde.jpg',
			1391491 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/09/vidaenpareja.jpg',
			1391493 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/09/negativity.jpg',
			1391495 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/09/elamorylas.jpg',
			1391497 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/10/labulimiay.jpg',
			1391499 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/10/quehacersi.jpg',
			1391501 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/10/lasoledadun.jpg',
			1391503 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/10/queesla.jpg',
			1391505 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/10/amoreeninternet.jpg',
			1391507 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/11/comayudara.jpg',
			1391509 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/11/lacomezondel.jpg',
			1391511 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/11/reiressaludable.jpg',
			1391513 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/11/problemas-de-alcohol.jpg',
			1391515 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/12/padreehijo.jpg',
			1391517 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/12/cuandoelestres.jpg',
			1391519 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/12/lanavidaddelos.jpg',
			1391521 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/12/palabrasdefin.jpg',
			1391523 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/01/losinicios.jpg',
			1391525 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/01/elagotamientoemocional.jpg',
			1391527 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/01/elvalordel.jpg',
			1391529 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/01/elamorysus.jpg',
			1391531 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/01/comolidiarcon.jpg',
			1391533 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/02/habitostoxicosen.jpg',
			1391535 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/02/losnueveenemigos.jpg',
			1391537 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/02/comolidearcon.jpg',
			1391539 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/03/comocompartirtiempo.jpg',
			1391541 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/03/enfermo.jpg',
			1391543 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/03/atencionsuhijo.jpg',
			1391545 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/04/queeselabuso.jpg',
			1391547 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/04/queesladepresion.jpg',
			1391549 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/04/stalkerenredes.jpg',
			1391551 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/04/lairaysus.jpg',
			1391553 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/05/bloqueomental.jpg',
			1391555 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/05/ysimejoramos.jpg',
			1391557 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/05/esustedunapersona.jpg',
			1391559 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/05/quesonlas.jpg',
			1391561 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/06/ysimejoramos.jpg',
			1391563 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/06/elgranproblema.jpg',
			1391565 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/06/comoreconoceruna.jpg',
			1391567 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/06/quesonlos.jpg',
			1391569 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/07/habilidadessociales.jpg',
			1391571 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/07/trollshatersstalkers.jpg',
			1391573 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/07/amparo_violencia.jpg',
			1391575 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/07/millennials.jpg',
			1391577 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/08/deliriumtremens.jpg',
			1391579 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/08/paralisis_sueno.jpg',
			1391581 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/08/breadcrumbing.jpg',
			1391583 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/08/comunica.jpg',
			1391585 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/09/pareja.jpg',
			1391587 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/09/ninoduelo.jpg',
			1391589 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/09/respirando.jpg',
			1391591 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/09/digital-diogenes.jpg',
			1391593 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/10/narcisista.jpg',
			1391595 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/10/amparo-mosting.jpg',
			1391597 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/10/thinking.jpg',
			1391599 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/10/depression.jpg',
			1391601 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/10/autista.jpg',
			1391604 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/11/lateral_thinking.jpg',
			1391606 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/11/lenguajecorporal.jpg',
			1391607 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/11/munchausen.jpg',
			1391609 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/11/emperor-kid.jpg',
			1391611 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/12/woman_nutrition.jpg',
			1391613 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/12/insomnia.jpg',
			1391615 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/12/arguing.jpg',
			1391617 => 'https://newspack.quepasamedia.com/wp-content/uploads/2019/12/2020.jpg',
			1391619 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/01/ny_resoultions.jpg',
			1391621 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/01/liar.jpg',
			1391623 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/01/global_warming.jpg',
			1391625 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/01/vanity.jpg',
			1391627 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/01/emotions.jpg',
			1391629 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/02/immature-_adult.jpg',
			1391631 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/02/family_love.jpg',
			1391633 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/02/white_knight.jpg',
			1391635 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/02/good_manners.jpg',
			1391637 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/03/selfish.jpg',
			1391639 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/03/runaway.jpg',
			1391641 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/03/loving_couple.jpg',
			1391643 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/03/social_distance.jpg',
			1391645 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/04/depressed_in_window.jpg',
			1391647 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/04/coro_mental_health.jpg',
			1391649 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/04/emotional.jpg',
			1391651 => 'https://newspack.quepasamedia.com/wp-content/uploads/2020/04/pause_0.jpg',
			1391653 => 'https://newspack.quepasamedia.com/wp-content/uploads/2021/04/dia_mundial_de_la_salud_222.jpg',
			1391669 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_buscarlafelicidadocrearl.jpg',
			1391673 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/03/b6_queeslainteligencia.jpg',
			1391675 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/03/b4_comocombatirlatendenciaa.jpg',
			1391679 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_esbuenovivirapurado-scaled.jpg',
			1391685 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_lohagoonolohago-scaled.jpg',
			1391687 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/04/b6_pensarporunomismo.jpg',
			1391690 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_tendenciasquehacenqueuna.png',
			1391694 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/04/cuidarsedelasamistades.jpg',
			1391696 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/04/depositphotos_23964499_l-2015.jpg',
			1391698 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_estaaumentandoelniveldee.png',
			1391699 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/05/comoaprovecharlasoportunida.jpg',
			1391702 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/05/elsexoelamorylapareja.jpg',
			1391704 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/05/pedirperdon.jpg',
			1391706 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/05/elestresunenemigodelasexual.jpg',
			1391708 => 'https://newspack.quepasamedia.com/wp-content/uploads/2016/05/comoayudaraunapersona.jpg',
			1391709 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_comomejorarlaatencionenl.png',
			1391711 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_elalcoholismoenlafamilia.jpg',
			1391713 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_queeselataquedepanico-scaled.jpg',
			1391715 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_8senalesparareconocerele.png',
			1391717 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_porqueesmalo.jpg',
			1391719 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/11/b6_queesladepre.jpg',
			1391721 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/11/elsecreto.jpg',
			1391723 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_cuandoeljuegonoesjusto.jpg',
			1391725 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_elsentidodelavida.jpg',
			1391727 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/12/b6_uncuentodenavidad.jpg',
			1391728 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/05/b6_comogestionarlaira.png',
			1391731 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_quetalsiloempezamos.jpg',
			1391733 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_elamorenel2018.jpg',
			1391735 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_elinviernonosdeprime.jpg',
			1391737 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_comoaprovecharlasoportun.jpg',
			1391739 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/01/b6_lateoriadelapego.jpg',
			1391741 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/06/b6_somosadictosalfacebook.png',
			1391743 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/02/b6_latecnologianoshacemasfe.jpg',
			1391745 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/02/existelaamistad.jpg',
			1391747 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/02/sentiminetosque.jpg',
			1391749 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/02/vivimosansiosos.jpg',
			1391750 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/06/b6_trastornosalimentosdelsi.png',
			1391753 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/06/inteligencia-emocional-goleman1.jpg',
			1391754 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/03/diainternacional.jpg',
			1391757 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_elpasodelainfanciaenlaba.jpg',
			1391758 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/03/quedicela.jpg',
			1391761 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/07/gaslighting.jpg',
			1391762 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/03/lafelicidadel.jpg',
			1391765 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_las5etapasdelduelo.jpg',
			1391767 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/03/personastoxicas.jpg',
			1391769 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/07/b6_queesellenguajecorporal.jpg',
			1391770 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/04/prevenirelalcohiolismo.jpg',
			1391773 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/04/elamorcuenta.jpg',
			1391775 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_atodosnospuedepasar.jpg',
			1391777 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/04/cuandolaspreocupa.jpg',
			1391778 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/08/o-top-fantasy-books-facebook.jpg',
			1391781 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_lasmalascompanias.jpg',
			1391783 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/04/comomenajarla.jpg',
			1391785 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/08/b6_elfantasmadelasoledad.jpg',
			1391787 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/05/b6_latragediadeloscelos.jpg',
			1391789 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/05/4trucospara.jpg',
			1391790 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/08/horadedormir.png',
			1391793 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/05/losbeneficiosde.jpg',
			1391795 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_hastaelhueso.jpg',
			1391797 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/09/los_adolescentes.1.jpg',
			1391799 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_todoescuestiondeactitud.jpg',
			1391801 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/05/memoriaredessociales-scaled.jpg',
			1391803 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/09/b6_darparaestarmejor.jpg',
			1391805 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_queeselestrespostraumati.jpg',
			1391807 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/05/12senalesde-scaled.jpg',
			1391809 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/06/microcheatingla.jpg',
			1391811 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/06/aprenderacuidarse.jpg',
			1391813 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/10/uso_abuso-scaled.jpg',
			1391815 => 'https://newspack.quepasamedia.com/wp-content/uploads/2018/06/unpocode.jpg',
			1391816 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/10/b6_loscelosenlostiemposdel.jpg',
			1391818 => 'https://newspack.quepasamedia.com/wp-content/uploads/2017/10/comunicacion.jpg',
		];
	}
}
