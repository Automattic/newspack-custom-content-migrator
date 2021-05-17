<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
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
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic = new AttachmentsLogic();
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
				'shortdesc' => 'Sets additionally idendified missing Featured images; the origin DB was a bit faulty, so we are updating these manually.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator quepasa-set-featured-images-post-launch`.
	 */
	public function cmd_set_feat_images_post_launch( $args, $assoc_args ) {
		$i = 0;
		$posts_images = $this->get_posts_featured_images();
		foreach ( $posts_images as $post_id => $img_url ) {
			WP_CLI::line( sprintf( '%d/%d Post ID %d', ++$i, count( $posts_images ), $post_id ) );

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
	 * Simple logging.
	 *
	 * @param string $file_path Full file path.
	 * @param string $message   Logging message
	 */
	private function log( $file, $message ) {
		file_put_contents( $file, $message . "\n", FILE_APPEND );
	}

	/**
	 * Get Featured images to set for Posts.
	 *
	 * @return string[] Post ID as key, featured image to download and use for the Post.
	 */
	private function get_posts_featured_images() {
return [
	1391154 => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-507546185.jpg',
	1391156 => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-471344257.jpg',
	1391160 => 'http://newspack.quepasamedia.com/wp-content/uploads/2015/04/ThinkstockPhotos-56515123.jpg',
];


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
