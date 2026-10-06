<?php
/**
 * Tests de l'heure de sortie d'un tome (méta yume_heure_cible, « Heure de sortie » à côté de la
 * date cible) : « Nouveau tome » et « Modifier le tome » gardent l'heure saisie quel que soit le
 * rythme (rythme « Libre » compris), couverture ajoutée ou non ; le rythme des chapitres reprend
 * l'heure de sortie ; anciennes entrées rythme_heure et rythme[heure] acceptées ; heure invalide
 * refusée ; REST planning ; journal ; affichage « 20 h » sur la page Planning, le bloc
 * yume/upcoming, l'accueil (« Aujourd’hui à 20 h ») et l'espace équipe ; calendrier ICS
 * horodaté ; tome sans heure inchangé.
 *
 * Lancement : tools/localenv/test.sh heure-sortie
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\calendrier_ics;
use function Yume\Core\Planning\date_cible_lisible;
use function Yume\Core\Planning\fuseau;
use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\invalider_ics;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\mettre_a_jour;
use function Yume\Core\Planning\prochain_tome;
use function Yume\Core\Planning\rendu_a_la_une;
use function Yume\Core\Planning\rendu_planning_accueil;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\texte_changement;
use function Yume\Core\Planning\traiter_formulaire_ajout;
use function Yume\Core\Planning\traiter_formulaire_maj;
use function Yume\Core\Planning\traiter_formulaire_tome;
use function Yume\Core\Planning\ts_date;
use function Yume\Core\Planning\valeurs_tome;
use function Yume\Core\Planning\vitrine_date_longue;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_ths_)
 * -----------------------------------------------------------------------------
 */

/** Espace insécable des heures en français (« 20 h »). */
const YUME_THS_NBSP = "\u{00A0}";

/**
 * Déclare un test isolé : contenus Yume et journal vidés dans la transaction, fuseau du site
 * Europe/Paris, cache ICS vidé, fichiers et médias créés supprimés à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps (reçoit le contexte : medias, fichiers).
 */
function yume_ths_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
			$wpdb->query( 'DELETE FROM ' . table_journal() );
			// phpcs:enable
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$fuseau = get_option( 'timezone_string' );
			$ecart  = get_option( 'gmt_offset' );
			update_option( 'timezone_string', 'Europe/Paris' );
			invalider_ics();
			$ctx           = new stdClass();
			$ctx->medias   = array();
			$ctx->fichiers = array();
			$suivre        = static function ( $id ) use ( $ctx ) {
				$ctx->medias[] = (int) $id;
			};
			add_filter( 'yume_core_notifier', '__return_false' );
			add_filter( 'yume_publication_fichier_local', '__return_true' );
			add_action( 'add_attachment', $suivre );
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps( $ctx );
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				remove_filter( 'yume_core_notifier', '__return_false' );
				remove_filter( 'yume_publication_fichier_local', '__return_true' );
				remove_action( 'add_attachment', $suivre );
				foreach ( $ctx->medias as $id ) {
					wp_delete_attachment( $id, true );
				}
				foreach ( $ctx->fichiers as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
				update_option( 'timezone_string', $fuseau );
				update_option( 'gmt_offset', $ecart );
				invalider_ics();
				delete_transient( 'yume_planning_retour_' . get_current_user_id() );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Date « Y-m-d » (heure de Paris) à N jours d'aujourd'hui.
 *
 * @param int $jours Décalage.
 */
function yume_ths_jour( int $jours ): string {
	return ( new DateTimeImmutable( 'now', fuseau() ) )->modify( sprintf( '%+d days', $jours ) )->format( 'Y-m-d' );
}

/**
 * Crée une œuvre publiée (light novel).
 *
 * @param string $titre Titre.
 */
function yume_ths_oeuvre( string $titre ): int {
	$id = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => $titre,
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Crée un tome en brouillon (planning « traduction »).
 *
 * @param int   $oeuvre Œuvre.
 * @param int   $numero Numéro.
 * @param array $meta   Métadonnées.
 */
function yume_ths_tome( int $oeuvre, int $numero, array $meta = array() ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_tome',
			'post_title'  => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
			'post_status' => 'draft',
			'meta_input'  => array_merge(
				array(
					'yume_oeuvre_id'    => $oeuvre,
					'yume_numero'       => $numero,
					'yume_nature'       => 'tome',
					'yume_etape'        => 'traduction',
					'yume_derniere_maj' => gmdate( 'Y-m-d H:i:s' ),
				),
				$meta
			),
		)
	);
}

/**
 * Champs du formulaire « Nouveau tome » (admin-post yume_planning_ajout).
 *
 * @param int   $oeuvre Œuvre.
 * @param array $plus   Champs à ajouter ou remplacer.
 * @return array<string,mixed>
 */
function yume_ths_ajout( int $oeuvre, array $plus = array() ): array {
	return array_merge(
		array(
			'action'           => 'yume_planning_ajout',
			'_yume_nonce'      => wp_create_nonce( 'yume_planning_ajout' ),
			'oeuvre_id'        => (string) $oeuvre,
			'nature'           => 'tome',
			'numero'           => '2',
			'titre'            => '',
			'date_cible'       => '2027-01-15',
			'heure_cible'      => '20:30',
			'etape'            => 'traduction',
			'chapitres_prevus' => '',
			'rythme_jour'      => '',
			'suite'            => 'fiche',
		),
		$plus
	);
}

/**
 * Champs du formulaire « Modifier le tome » tels que la fiche les envoie (valeurs actuelles,
 * nonce compris), complétés ou remplacés par $plus.
 *
 * @param int   $tome Tome.
 * @param array $plus Champs à ajouter ou remplacer.
 * @return array<string,mixed>
 */
function yume_ths_post_tome( int $tome, array $plus = array() ): array {
	$v      = valeurs_tome( $tome );
	$rythme = is_array( $v['rythme'] ) ? $v['rythme'] : array();
	return array_merge(
		array(
			'tome_id'          => (string) $tome,
			'_yume_nonce'      => wp_create_nonce( 'yume_tome_modifier_' . $tome ),
			'oeuvre_id'        => $v['oeuvre_id'],
			'nature'           => $v['nature'],
			'numero'           => $v['numero'],
			'titre'            => $v['titre'],
			'date_cible'       => $v['date_cible'],
			'heure_cible'      => $v['heure_cible'],
			'chapitres_prevus' => $v['chapitres_prevus'],
			'rythme_jour'      => (string) ( $rythme['jour'] ?? '' ),
			'lien_pdf'         => $v['lien_pdf'],
			'lien_epub'        => $v['lien_epub'],
			'etat'             => $v['etat'],
			'annoncer'         => '0',
		),
		$plus
	);
}

/**
 * Image PNG temporaire, entrée $_FILES d'une couverture.
 *
 * @param stdClass $ctx Contexte (fichier supprimé à la fin).
 * @return array<string,mixed>
 */
function yume_ths_image( stdClass $ctx ): array {
	$chemin = wp_tempnam( 'yume-couverture.png' );
	$image  = imagecreatetruecolor( 200, 300 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 40, 90, 160 ) );
	imagepng( $image, $chemin );
	imagedestroy( $image );
	$ctx->fichiers[] = $chemin;
	return array(
		'name'     => 'couverture.png',
		'type'     => 'image/png',
		'tmp_name' => $chemin,
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) filesize( $chemin ),
	);
}

/**
 * Rend l'espace équipe avec des paramètres GET, en tant qu'utilisateur.
 *
 * @param int   $user_id Utilisateur.
 * @param array $get     Paramètres GET.
 */
function yume_ths_rendu( int $user_id, array $get = array() ): string {
	$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return yume_render_block( 'yume/team-dashboard' );
}

/**
 * Bloc VEVENT d'un tome dans le calendrier ICS (lignes dépliées), ou ''.
 *
 * @param string $ics     Calendrier.
 * @param int    $tome_id Tome.
 */
function yume_ths_evenement( string $ics, int $tome_id ): string {
	$ics = str_replace( "\r\n ", '', $ics );
	foreach ( explode( 'BEGIN:VEVENT', $ics ) as $bloc ) {
		if ( str_contains( $bloc, 'UID:tome-' . $tome_id . '@' ) ) {
			return $bloc;
		}
	}
	return '';
}

/**
 * Heure en français attendue (« 20 h », « 20 h 30 »).
 *
 * @param int $h Heures.
 * @param int $m Minutes.
 */
function yume_ths_h( int $h, int $m = 0 ): string {
	return $h . YUME_THS_NBSP . 'h' . ( $m ? YUME_THS_NBSP . sprintf( '%02d', $m ) : '' );
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_ths_test(
	'heure de sortie : « Nouveau tome » avec date, heure et rythme « Libre » garde l’heure (plus de 18:00 imposé) ; la fiche « Modifier le tome » la reprend',
	static function () {
		$oeuvre  = yume_ths_oeuvre( 'Grimgar' );
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );

		$retour = traiter_formulaire_ajout( yume_ths_ajout( $oeuvre ), $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$tome = (int) $retour['tome_id'];
		yume_assert_same( '2027-01-15', get_post_meta( $tome, 'yume_date_cible', true ) );
		yume_assert_same( '20:30', get_post_meta( $tome, 'yume_heure_cible', true ), 'heure gardée avec un rythme « Libre »' );
		yume_assert_false( metadata_exists( 'post', $tome, 'yume_rythme' ), 'sortie libre : pas de rythme' );

		$html = yume_ths_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $tome,
			)
		);
		yume_assert_contains( 'type="time" id="yn-tome-heure" name="heure_cible" value="20:30"', $html, 'fiche : heure enregistrée, pas 18:00' );
		yume_assert_not_contains( 'name="rythme_heure"', $html, 'plus de champ d’heure propre au rythme' );
		yume_assert_contains( 'date cible 15 janv. 2027 à ' . yume_ths_h( 20, 30 ), $html, 'fiche : date et heure' );

		// Nouveau tome sans heure : rien d'enregistré (18:00 n'est qu'une proposition du formulaire).
		$retour = traiter_formulaire_ajout(
			yume_ths_ajout(
				$oeuvre,
				array(
					'numero'      => '3',
					'heure_cible' => '',
				)
			),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_false( metadata_exists( 'post', (int) $retour['tome_id'], 'yume_heure_cible' ) );
	}
);

yume_ths_test(
	'heure de sortie : « Modifier le tome » avec une couverture et une nouvelle heure garde l’heure ; heure gardée aussi sans rythme ni couverture',
	static function ( $ctx ) {
		$oeuvre  = yume_ths_oeuvre( 'Raven' );
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$tome = (int) traiter_formulaire_ajout(
			yume_ths_ajout(
				$oeuvre,
				array(
					'heure_cible' => '18:00',
					'date_cible'  => '2027-02-06',
				)
			),
			$editeur
		)['tome_id'];

		$r = traiter_formulaire_tome( yume_ths_post_tome( $tome, array( 'heure_cible' => '21:15' ) ), array( 'couverture' => yume_ths_image( $ctx ) ), $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_true( (int) get_post_thumbnail_id( $tome ) > 0, 'couverture ajoutée' );
		yume_assert_same( '21:15', get_post_meta( $tome, 'yume_heure_cible', true ), 'heure gardée avec la couverture' );
		yume_assert_same( '2027-02-06', get_post_meta( $tome, 'yume_date_cible', true ) );
		yume_assert_false( metadata_exists( 'post', $tome, 'yume_rythme' ) );
		$valeurs = valeurs_tome( $tome );
		yume_assert_same( '21:15', $valeurs['heure_cible'], 'fiche reprise avec la nouvelle heure' );

		// Enregistrer de nouveau la fiche telle quelle : l'heure ne revient jamais à 18:00.
		$r = traiter_formulaire_tome( yume_ths_post_tome( $tome ), array(), $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( '21:15', get_post_meta( $tome, 'yume_heure_cible', true ) );

		// Journal : « heure de sortie : 21 h 15 » (public, comme la date cible).
		$lignes = lire_journal(
			array(
				'tome_id' => $tome,
				'champs'  => array( 'heure_cible' ),
				'public'  => true,
			)
		);
		yume_assert_same( 1, count( $lignes ), 'un changement d’heure journalisé' );
		yume_assert_same( 'heure de sortie : ' . yume_ths_h( 21, 15 ), texte_changement( $lignes[0], false ) );

		// Heure vidée : « non précisée ».
		$r = traiter_formulaire_tome( yume_ths_post_tome( $tome, array( 'heure_cible' => '' ) ), array(), $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( '', (string) get_post_meta( $tome, 'yume_heure_cible', true ) );
	}
);

yume_ths_test(
	'heure de sortie : le rythme des chapitres reprend l’heure de sortie (jour + heure → {jour, heure}, 18:00 sans heure) et la suit quand elle change',
	static function () {
		$oeuvre  = yume_ths_oeuvre( 'Silent Witch' );
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );

		$tome = (int) traiter_formulaire_ajout( yume_ths_ajout( $oeuvre, array( 'rythme_jour' => 'samedi' ) ), $editeur )['tome_id'];
		yume_assert_same( '20:30', get_post_meta( $tome, 'yume_heure_cible', true ) );
		yume_assert_same(
			array(
				'jour'  => 'samedi',
				'heure' => '20:30',
			),
			get_post_meta( $tome, 'yume_rythme', true ),
			'rythme à l’heure de sortie'
		);
		$prochaine = yume_prochaine_sortie_rythme( $tome );
		yume_assert_same( '6 20:30', $prochaine ? $prochaine->format( 'N H:i' ) : '', 'publication au rythme : samedi à 20:30' );

		// Sans heure : 18:00 pour le rythme, aucune heure de sortie enregistrée.
		$sans = (int) traiter_formulaire_ajout(
			yume_ths_ajout(
				$oeuvre,
				array(
					'numero'      => '5',
					'heure_cible' => '',
					'rythme_jour' => 'mercredi',
				)
			),
			$editeur
		)['tome_id'];
		yume_assert_same(
			array(
				'jour'  => 'mercredi',
				'heure' => '18:00',
			),
			get_post_meta( $sans, 'yume_rythme', true )
		);
		yume_assert_same( '', (string) get_post_meta( $sans, 'yume_heure_cible', true ) );

		// « Modifier le tome » : nouveau jour et nouvelle heure.
		$r = traiter_formulaire_tome(
			yume_ths_post_tome(
				$tome,
				array(
					'rythme_jour' => 'vendredi',
					'heure_cible' => '19:00',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same(
			array(
				'jour'  => 'vendredi',
				'heure' => '19:00',
			),
			get_post_meta( $tome, 'yume_rythme', true )
		);

		// Planning complet (formulaire sans JavaScript) : l'heure change, le rythme suit.
		$retour = traiter_formulaire_maj(
			array(
				'tome_id'     => (string) $tome,
				'_yume_nonce' => wp_create_nonce( 'yume_planning_maj_' . $tome ),
				'ancre'       => 'yn-tome-' . $tome,
				'date_cible'  => '2027-01-15',
				'heure_cible' => '21:45',
			),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_same( '21:45', get_post_meta( $tome, 'yume_heure_cible', true ) );
		yume_assert_same( '21:45', get_post_meta( $tome, 'yume_rythme', true )['heure'], 'rythme à la nouvelle heure' );
		yume_assert_contains( 'heure de sortie : ' . yume_ths_h( 21, 45 ), $retour['message'] );

		// REST PATCH /tomes/{id}/planning : heure_cible.
		$rep = yume_rest( 'PATCH', '/yume/v1/tomes/' . $tome . '/planning', array( 'heure_cible' => '07:05' ), $editeur );
		yume_assert_same( 200, $rep->get_status() );
		yume_assert_same( '07:05', get_post_meta( $tome, 'yume_heure_cible', true ) );
		yume_assert_same( '07:05', $rep->get_data()['tome']['heure_cible'] );
		yume_assert_same( '07:05', get_post_meta( $tome, 'yume_rythme', true )['heure'] );
	}
);

yume_ths_test(
	'heure de sortie : anciennes entrées acceptées (rythme_heure des formulaires, rythme[heure] en REST) ; tome antérieur sans heure de sortie : l’heure de son rythme est proposée',
	static function () {
		$oeuvre  = yume_ths_oeuvre( 'Witches' );
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );

		// Formulaire ancien (sans heure_cible), rythme « Libre » : l'heure saisie est gardée.
		$ancien = yume_ths_ajout(
			$oeuvre,
			array(
				'rythme_jour'  => '',
				'rythme_heure' => '19:45',
			)
		);
		unset( $ancien['heure_cible'] );
		$retour = traiter_formulaire_ajout( $ancien, $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_same( '19:45', get_post_meta( (int) $retour['tome_id'], 'yume_heure_cible', true ) );
		yume_assert_false( metadata_exists( 'post', (int) $retour['tome_id'], 'yume_rythme' ) );

		// REST POST /planning/tomes avec rythme[heure] et sans heure_cible.
		$rep = yume_rest(
			'POST',
			'/yume/v1/planning/tomes',
			array(
				'oeuvre_id'  => $oeuvre,
				'numero'     => 4,
				'date_cible' => '2027-03-05',
				'rythme'     => array(
					'jour'  => 'vendredi',
					'heure' => '21:00',
				),
			),
			$editeur
		);
		yume_assert_same( 201, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		$rest = (int) $rep->get_data()['tome_id'];
		yume_assert_same( '21:00', get_post_meta( $rest, 'yume_heure_cible', true ) );
		yume_assert_same(
			array(
				'jour'  => 'vendredi',
				'heure' => '21:00',
			),
			get_post_meta( $rest, 'yume_rythme', true )
		);

		// REST POST avec heure_cible : elle prime sur rythme[heure].
		$rep = yume_rest(
			'POST',
			'/yume/v1/planning/tomes',
			array(
				'oeuvre_id'   => $oeuvre,
				'numero'      => 6,
				'heure_cible' => '12:00',
				'rythme'      => array(
					'jour'  => 'lundi',
					'heure' => '21:00',
				),
			),
			$editeur
		);
		yume_assert_same( 201, $rep->get_status(), wp_json_encode( $rep->get_data() ) );
		yume_assert_same( '12:00', get_post_meta( (int) $rep->get_data()['tome_id'], 'yume_rythme', true )['heure'] );
		yume_assert_same( '12:00', $rep->get_data()['tome']['heure_cible'] );

		// Tome antérieur : rythme à 20:00, sans heure de sortie.
		$vieux = yume_ths_tome(
			$oeuvre,
			7,
			array(
				'yume_date_cible' => '2027-04-03',
				'yume_rythme'     => array(
					'jour'  => 'samedi',
					'heure' => '20:00',
				),
			)
		);
		yume_assert_same( '20:00', valeurs_tome( $vieux )['heure_cible'], 'heure du rythme proposée' );
		$html = yume_ths_rendu(
			$editeur,
			array(
				'vue'      => 'tomes',
				'modifier' => (string) $vieux,
			)
		);
		yume_assert_contains( 'id="yn-tome-heure" name="heure_cible" value="20:00"', $html );
		// Fiche ancienne (rythme_heure, sans heure_cible) : l'heure du rythme devient l'heure de sortie.
		$post = yume_ths_post_tome(
			$vieux,
			array(
				'rythme_jour'  => 'samedi',
				'rythme_heure' => '22:00',
			)
		);
		unset( $post['heure_cible'] );
		$r = traiter_formulaire_tome( $post, array(), $editeur );
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( '22:00', get_post_meta( $vieux, 'yume_heure_cible', true ) );
		yume_assert_same( '22:00', get_post_meta( $vieux, 'yume_rythme', true )['heure'] );
	}
);

yume_ths_test(
	'heure de sortie : une heure invalide est refusée (« Heure de sortie : format attendu HH:MM ») sans rien écrire — formulaires et REST',
	static function () {
		$oeuvre  = yume_ths_oeuvre( 'Lanternes' );
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$attendu = 'Heure de sortie : format attendu HH:MM.';

		$retour = traiter_formulaire_ajout( yume_ths_ajout( $oeuvre, array( 'heure_cible' => '25:00' ) ), $editeur );
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_same( $attendu, $retour['message'] );
		yume_assert_same( array(), yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ), 'aucun tome créé' );
		yume_assert_same( '25:00', $retour['saisie']['heure_cible'], 'saisie reprise dans le formulaire' );

		$rep = yume_rest(
			'POST',
			'/yume/v1/planning/tomes',
			array(
				'oeuvre_id'   => $oeuvre,
				'numero'      => 2,
				'heure_cible' => 'midi',
			),
			$editeur
		);
		yume_assert_same( 400, $rep->get_status() );
		yume_assert_same( 'yume_heure_invalide', $rep->get_data()['code'] );

		$tome = yume_ths_tome( $oeuvre, 3, array( 'yume_heure_cible' => '20:00' ) );
		$rep  = yume_rest( 'PATCH', '/yume/v1/tomes/' . $tome . '/planning', array( 'heure_cible' => '20h' ), $editeur );
		yume_assert_same( 400, $rep->get_status() );
		yume_assert_same( $attendu, $rep->get_data()['message'] );
		wp_set_current_user( $editeur );
		$r = traiter_formulaire_tome( yume_ths_post_tome( $tome, array( 'heure_cible' => '8:75' ) ), array(), $editeur );
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( $attendu, $r['message'] );
		yume_assert_same( '20:00', get_post_meta( $tome, 'yume_heure_cible', true ), 'heure inchangée' );
		yume_assert_true( is_wp_error( mettre_a_jour( $tome, array( 'heure_cible' => array( '20:00' ) ), $editeur ) ) );
	}
);

yume_ths_test(
	'heure de sortie : affichée « 20 h » sur la page Planning (tableau, « En bref »), yume/upcoming et l’espace équipe ; tome sans heure inchangé',
	static function () {
		$oeuvre   = yume_ths_oeuvre( 'Grimgar' );
		$dans3    = yume_ths_jour( 3 );
		$dans5    = yume_ths_jour( 5 );
		$avec     = yume_ths_tome(
			$oeuvre,
			1,
			array(
				'yume_date_cible'  => $dans3,
				'yume_heure_cible' => '20:00',
			)
		);
		$demie    = yume_ths_tome(
			$oeuvre,
			2,
			array(
				'yume_date_cible'  => $dans5,
				'yume_heure_cible' => '20:30',
			)
		);
		$sans     = yume_ths_tome( $oeuvre, 3, array( 'yume_date_cible' => yume_ths_jour( 8 ) ) );
		$jour3    = date_cible_lisible( $dans3, true );
		$jour5    = date_cible_lisible( $dans5, true );
		$jour8    = date_cible_lisible( yume_ths_jour( 8 ), true );
		$planning = yume_render_block( 'yume/planning' );
		yume_assert_contains( 'data-label="Sortie prévue">' . esc_html( $jour3 . ' à ' . yume_ths_h( 20 ) ) . '</td>', $planning, 'tableau : date et heure' );
		yume_assert_contains( 'data-label="Sortie prévue">' . esc_html( $jour5 . ' à ' . yume_ths_h( 20, 30 ) ) . '</td>', $planning, 'tableau : 20 h 30' );
		yume_assert_contains( 'data-label="Sortie prévue">' . esc_html( $jour8 ) . '</td>', $planning, 'tome sans heure : date seule' );
		yume_assert_contains( '<p class="yn-label">Prochaine sortie</p><p class="yn-planning__valeur">' . esc_html( $jour3 . ' à ' . yume_ths_h( 20 ) ) . '</p>', $planning, '« En bref » : prochaine sortie et son heure' );
		yume_assert_contains( '<p class="yn-label">Puis</p><p class="yn-planning__valeur">' . esc_html( $jour5 . ' à ' . yume_ths_h( 20, 30 ) ) . '</p>', $planning );

		$upcoming = yume_render_block( 'yume/upcoming', array( 'count' => 3 ) );
		yume_assert_contains( '<span class="yn-label yn-upcoming__date">' . esc_html( $jour3 . ' à ' . yume_ths_h( 20 ) ) . '</span>', $upcoming );
		yume_assert_contains( '<span class="yn-label yn-upcoming__date">' . esc_html( $jour8 ) . '</span>', $upcoming, 'tome sans heure inchangé' );

		// Ligne publique (REST GET /planning).
		$lignes = yume_rest( 'GET', '/yume/v1/planning' )->get_data();
		$heures = array_column( $lignes, 'heure_cible', 'tome_id' );
		yume_assert_same( '20:00', $heures[ $avec ] ?? null );
		yume_assert_same( '20:30', $heures[ $demie ] ?? null );
		yume_assert_same( '', $heures[ $sans ] ?? null );

		// Espace équipe : planning complet (champ et résumé) et « Tous les tomes ».
		$gerant  = yume_factory_user( 'yume_gerant' );
		$complet = yume_ths_rendu( $gerant, array( 'vue' => 'planning' ) );
		yume_assert_contains( 'type="time" id="g' . $avec . '-heure" name="heure_cible" value="20:00"', $complet, 'planning complet : champ Heure de sortie' );
		yume_assert_contains( 'type="time" id="g' . $sans . '-heure" name="heure_cible" value=""', $complet );
		yume_assert_contains( esc_html( $jour3 . ' à ' . yume_ths_h( 20 ) ), $complet, 'planning complet : résumé avec l’heure' );
		$tomes = yume_ths_rendu( $gerant, array( 'vue' => 'tomes' ) );
		yume_assert_contains( 'date cible ' . format_fr( ts_date( $dans3 ), 'j M Y' ) . ' à ' . yume_ths_h( 20 ), $tomes );
		yume_assert_contains( 'date cible ' . format_fr( ts_date( yume_ths_jour( 8 ) ), 'j M Y' ) . '<', $tomes, 'tome sans heure : date seule' );
	}
);

yume_ths_test(
	'heure de sortie : accueil et bandeau « À la une » — date longue et heure, compte à rebours « Aujourd’hui à 20 h » puis « J-2 » ; sans heure : « Aujourd’hui »',
	static function () {
		$oeuvre  = yume_ths_oeuvre( 'Silent Witch' );
		$tome    = yume_ths_tome(
			$oeuvre,
			7,
			array(
				'yume_date_cible'  => yume_ths_jour( 0 ),
				'yume_heure_cible' => '20:00',
			)
		);
		$accueil = rendu_planning_accueil();
		yume_assert_contains( 'aria-hidden="true">Aujourd’hui à ' . yume_ths_h( 20 ) . '</span>', $accueil, 'compte à rebours avec l’heure' );
		yume_assert_contains( 'Sortie aujourd’hui à ' . yume_ths_h( 20 ), $accueil );
		yume_assert_contains( esc_html( vitrine_date_longue( ts_date( yume_ths_jour( 0 ) ) ) . ' à ' . yume_ths_h( 20 ) ) . '</time>', $accueil, 'date longue et heure' );

		update_post_meta( $tome, 'yume_date_cible', yume_ths_jour( 2 ) );
		update_post_meta( $tome, 'yume_heure_cible', '20:30' );
		$une = rendu_a_la_une( prochain_tome() ?? array(), 'accueil' );
		yume_assert_contains( 'aria-hidden="true">J-2</span>', $une );
		yume_assert_contains( esc_html( vitrine_date_longue( ts_date( yume_ths_jour( 2 ) ) ) . ' à ' . yume_ths_h( 20, 30 ) ), $une );
		$ts = ( new DateTimeImmutable( yume_ths_jour( 2 ) . ' 20:30', wp_timezone() ) )->getTimestamp();
		yume_assert_contains( '<time datetime="' . gmdate( 'Y-m-d\TH:i\Z', $ts ) . '">', $une, 'datetime horodaté' );

		// Sans heure : comme avant.
		update_post_meta( $tome, 'yume_date_cible', yume_ths_jour( 0 ) );
		delete_post_meta( $tome, 'yume_heure_cible' );
		$une = rendu_a_la_une( prochain_tome() ?? array(), 'accueil' );
		yume_assert_contains( 'aria-hidden="true">Aujourd’hui</span>', $une );
		yume_assert_contains( '<time datetime="' . yume_ths_jour( 0 ) . '">' . esc_html( vitrine_date_longue( ts_date( yume_ths_jour( 0 ) ) ) ) . '</time>', $une );
	}
);

yume_ths_test(
	'heure de sortie : calendrier ICS — prévision horodatée (UTC, une heure) quand l’heure est connue, journée entière sinon ; un tome programmé garde sa propre date et heure',
	static function () {
		$oeuvre = yume_ths_oeuvre( 'Raven' );
		$jour   = yume_ths_jour( 4 );
		$avec   = yume_ths_tome(
			$oeuvre,
			1,
			array(
				'yume_date_cible'  => $jour,
				'yume_heure_cible' => '20:00',
			)
		);
		$sans   = yume_ths_tome( $oeuvre, 2, array( 'yume_date_cible' => $jour ) );
		$ics    = calendrier_ics();

		$e  = yume_ths_evenement( $ics, $avec );
		$ts = ( new DateTimeImmutable( $jour . ' 20:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp();
		yume_assert_contains( "\r\nDTSTART:" . gmdate( 'Ymd\THis\Z', $ts ) . "\r\n", $e, 'DTSTART horodaté' );
		yume_assert_contains( "\r\nDTEND:" . gmdate( 'Ymd\THis\Z', $ts + HOUR_IN_SECONDS ) . "\r\n", $e, 'durée : une heure' );
		yume_assert_contains( 'STATUS:TENTATIVE', $e );
		yume_assert_not_contains( 'VALUE=DATE', $e );

		$e = yume_ths_evenement( $ics, $sans );
		yume_assert_contains( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $jour ), $e, 'sans heure : journée entière' );

		// Tome programmé à 10:00 : sa date et son heure priment sur l'heure de sortie saisie.
		$programme = yume_factory_post(
			array(
				'post_type'     => 'yume_tome',
				'post_title'    => 'Raven — Tome 3',
				'post_status'   => 'future',
				'post_date'     => yume_ths_jour( 6 ) . ' 10:00:00',
				'post_date_gmt' => get_gmt_from_date( yume_ths_jour( 6 ) . ' 10:00:00' ),
				'meta_input'    => array(
					'yume_oeuvre_id'   => $oeuvre,
					'yume_numero'      => 3,
					'yume_nature'      => 'tome',
					'yume_etape'       => 'edition',
					'yume_heure_cible' => '20:00',
				),
			)
		);
		$ligne     = yume_get_planning( array( 'a_venir' => true ) );
		$ligne     = array_values( wp_list_filter( $ligne, array( 'tome_id' => $programme ) ) )[0] ?? array();
		yume_assert_same( '10:00', $ligne['heure_cible'] ?? null, 'heure de la sortie programmée' );
		invalider_ics();
		$e = yume_ths_evenement( calendrier_ics(), $programme );
		yume_assert_contains( 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( get_gmt_from_date( yume_ths_jour( 6 ) . ' 10:00:00' ) . ' UTC' ) ), $e );
	}
);
