<?php
/**
 * Écouteurs des événements métier (§8) :
 *
 * - yume_tome_publie : étape « publié », avancements à 100 %, date de mise à jour, journal
 *   public et annonce Discord (canal des sorties : embed avec couverture, liens Lire / PDF /
 *   EPUB) ;
 * - yume_chapitre_publie : journal public, activité du tome et annonce Discord courte.
 *
 * Chaque mise à jour émet yume_planning_mis_a_jour.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Couleur des embeds Discord (accent sakura #f3a6c8). */
const COULEUR_DISCORD = 0xF3A6C8;

/**
 * Auteur d'une publication : l'utilisateur courant, sinon l'auteur du contenu (sortie
 * programmée publiée par le cron).
 *
 * @param int $post_id Contenu.
 */
function auteur_publication( int $post_id ): int {
	$user = get_current_user_id();
	if ( $user > 0 ) {
		return $user;
	}
	$post = get_post( $post_id );
	return $post ? (int) $post->post_author : 0;
}

/**
 * Lien « Lire » d'un tome : premier chapitre publié, sinon la page du tome.
 *
 * @param int $tome_id Tome.
 */
function url_lecture( int $tome_id ): string {
	$chapitres = yume_get_chapitres( $tome_id );
	return $chapitres ? (string) get_permalink( $chapitres[0] ) : (string) get_permalink( $tome_id );
}

/**
 * Annonce Discord d'un tome publié.
 *
 * @param int $tome_id Tome.
 * @return array{texte:string,embeds:array}
 */
function annonce_tome( int $tome_id ): array {
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	$oeuvre    = $oeuvre_id ? titre_brut( $oeuvre_id ) : '';
	$libelle   = yume_libelle_tome( $tome_id );
	$sous      = sous_titre_tome( $tome_id );
	$liens     = yume_liens_telechargement( $tome_id );

	$boutons = array( sprintf( '**[%1$s](%2$s)**', __( 'Lire en ligne', 'yume-core' ), url_lecture( $tome_id ) ) );
	if ( '' !== $liens['pdf'] ) {
		$boutons[] = sprintf( '[%1$s](%2$s)', __( 'PDF', 'yume-core' ), $liens['pdf'] );
	}
	if ( '' !== $liens['epub'] ) {
		$boutons[] = sprintf( '[%1$s](%2$s)', __( 'EPUB', 'yume-core' ), $liens['epub'] );
	}

	$chap        = compte_chapitres( $tome_id );
	$description = array();
	$extrait     = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $tome_id ) ) );
	if ( '' !== $extrait ) {
		$description[] = echapper_discord( tronquer( html_entity_decode( $extrait, ENT_QUOTES, 'UTF-8' ), 600 ) );
	}
	if ( $chap['publies'] ) {
		/* translators: %d : nombre de chapitres */
		$description[] = sprintf( _n( '%d chapitre à lire en ligne.', '%d chapitres à lire en ligne.', $chap['publies'], 'yume-core' ), $chap['publies'] );
	}
	$description[] = implode( ' · ', $boutons );

	$embed      = array(
		'title'       => trim( $oeuvre . ' — ' . $libelle . ( '' !== $sous ? ' : ' . $sous : '' ), ' —' ),
		'url'         => (string) get_permalink( $tome_id ),
		'description' => implode( "\n\n", $description ),
		'color'       => COULEUR_DISCORD,
		'footer'      => array( 'text' => 'Yume Novel' ),
		'timestamp'   => gmdate( 'c', maintenant() ),
	);
	$couverture = yume_get_cover_id( $tome_id );
	$image      = $couverture ? wp_get_attachment_image_url( $couverture, 'large' ) : '';
	if ( $image ) {
		$embed['image'] = array( 'url' => (string) $image );
	}

	$annonce = array(
		'texte'  => sprintf(
			/* translators: 1: œuvre, 2: libellé du tome */
			__( 'Nouvelle sortie : **%1$s** — %2$s est disponible !', 'yume-core' ),
			echapper_discord( $oeuvre ),
			echapper_discord( $libelle )
		),
		'embeds' => array( $embed ),
	);
	/**
	 * Filtre l'annonce Discord d'un tome publié (texte vide : pas d'annonce).
	 *
	 * @param array $annonce ['texte' => string, 'embeds' => array].
	 * @param int   $tome_id Tome.
	 */
	$annonce = (array) apply_filters( 'yume_planning_annonce_tome', $annonce, $tome_id );
	return array(
		'texte'  => (string) ( $annonce['texte'] ?? '' ),
		'embeds' => (array) ( $annonce['embeds'] ?? array() ),
	);
}

/**
 * Tome publié (action yume_tome_publie) : planning « publié » à 100 %, journal public et
 * annonce Discord.
 *
 * @param int $tome_id Tome.
 */
function sur_tome_publie( $tome_id ): void {
	$tome_id = (int) $tome_id;
	if ( 'yume_tome' !== get_post_type( $tome_id ) ) {
		return;
	}
	$user_id = auteur_publication( $tome_id );
	$chap    = compte_chapitres( $tome_id );
	mettre_a_jour(
		$tome_id,
		array(
			'etape'      => 'publie',
			'avancement' => array(
				'traduction' => 100,
				'relecture'  => 100,
				'edition'    => 100,
			),
			'bloque'     => false,
		),
		$user_id,
		array(
			'forcer'         => true,
			'toujours_dater' => true,
			'evenements'     => array(
				'publie' => array( '', array( 'chapitres' => $chap['publies'] ) ),
			),
		)
	);

	$annonce = annonce_tome( $tome_id );
	if ( '' !== $annonce['texte'] ) {
		yume_discord( 'sorties', $annonce['texte'], $annonce['embeds'] );
	}
}
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\sur_tome_publie', 10 );

/**
 * Chapitre publié (action yume_chapitre_publie) : journal public, activité du tome, annonce
 * Discord courte.
 *
 * @param int $chapitre_id Chapitre.
 */
function sur_chapitre_publie( $chapitre_id ): void {
	$chapitre_id = (int) $chapitre_id;
	if ( 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return;
	}
	$tome_id = yume_get_tome_id( $chapitre_id );
	if ( ! $tome_id ) {
		return;
	}
	$libelle = yume_libelle_chapitre( $chapitre_id );
	$sous    = trim( (string) get_post_meta( $chapitre_id, 'yume_sous_titre', true ) );
	mettre_a_jour(
		$tome_id,
		array(),
		auteur_publication( $chapitre_id ),
		array(
			'forcer'     => true,
			'evenements' => array(
				'chapitre_publie' => array(
					'',
					array(
						'id'      => $chapitre_id,
						'libelle' => $libelle,
					),
				),
			),
		)
	);

	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	$texte     = sprintf(
		/* translators: 1: œuvre, 2: tome, 3: chapitre, 4: adresse */
		__( 'Nouveau chapitre : **%1$s** — %2$s, %3$s : %4$s', 'yume-core' ),
		echapper_discord( $oeuvre_id ? titre_brut( $oeuvre_id ) : '' ),
		echapper_discord( yume_libelle_tome( $tome_id ) ),
		echapper_discord( $libelle . ( '' !== $sous ? ' « ' . html_entity_decode( $sous, ENT_QUOTES, 'UTF-8' ) . ' »' : '' ) ),
		(string) get_permalink( $chapitre_id )
	);
	/**
	 * Filtre l'annonce Discord d'un chapitre publié isolément (vide : pas d'annonce).
	 *
	 * @param string $texte       Message.
	 * @param int    $chapitre_id Chapitre.
	 */
	$texte = (string) apply_filters( 'yume_planning_annonce_chapitre', $texte, $chapitre_id );
	if ( '' !== $texte ) {
		yume_discord( 'sorties', $texte );
	}
}
add_action( 'yume_chapitre_publie', __NAMESPACE__ . '\\sur_chapitre_publie', 10 );
