<?php
/**
 * Écouteurs des événements métier (§8) :
 *
 * - yume_tome_publie : étape « publié », avancements à 100 %, date de mise à jour, journal
 *   public et annonce Discord (canal des sorties : embed avec couverture, liens Lire / PDF /
 *   EPUB) ;
 * - yume_chapitre_publie : journal public, activité du tome et annonce Discord courte ;
 * - un arc ou un tome publié chapitre par chapitre garde son étape tant que des chapitres
 *   restent à sortir, puis passe « publié » avec le dernier (SCAN-10) ;
 * - dépublication d'un tome (publish → autre statut) : étape « édition », avancements gardés,
 *   journal « depublie », date de dernière sortie de l'œuvre recalculée ; le retour en ligne
 *   rétablit « publié » par le même chemin que yume_tome_publie (SCAN-04) ;
 * - tome programmé (statut future) : la date cible suit la date programmée (SCAN-05) ;
 * - tome publié chapitre par chapitre (yume_parution = en_cours) : sa sortie ne le passe jamais
 *   « publié » ; l'étape est gardée, l'avancement suit les chapitres en ligne (sur
 *   yume_chapitres_prevus) et l'annonce Discord dit « Tome 2 : Prologue disponible ! » ;
 *   yume_tome_complet (case « Le tome est complet ») le passe « publié » à 100 % et annonce sur
 *   Discord « Le tome 2 de SukaMoka est complet : PDF et EPUB disponibles ».
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
 * Libellé des chapitres en ligne d'un tome pour une annonce (« Prologue », « chapitres 1 à
 * 3 ») : celui du module publication s'il est chargé, sinon « N chapitres ».
 *
 * @param int $tome_id Tome.
 */
function libelle_chapitres_en_ligne( int $tome_id ): string {
	$ids = array_map( 'intval', wp_list_pluck( yume_get_chapitres( $tome_id ), 'ID' ) );
	if ( ! $ids ) {
		return '';
	}
	if ( is_callable( array( '\\Yume\\Core\\Publication\\Annonce', 'libelle_sortie' ) ) ) {
		return (string) \Yume\Core\Publication\Annonce::libelle_sortie( $ids );
	}
	/* translators: %d : nombre de chapitres */
	return sprintf( _n( '%d chapitre', '%d chapitres', count( $ids ), 'yume-core' ), count( $ids ) );
}

/**
 * Formats téléchargeables d'un tome pour une annonce (« PDF et EPUB disponibles »), vide sans lien.
 *
 * @param int $tome_id Tome.
 */
function formats_disponibles( int $tome_id ): string {
	$liens = yume_liens_telechargement( $tome_id );
	if ( '' !== $liens['pdf'] && '' !== $liens['epub'] ) {
		return __( 'PDF et EPUB disponibles', 'yume-core' );
	}
	if ( '' !== $liens['pdf'] ) {
		return __( 'PDF disponible', 'yume-core' );
	}
	return '' !== $liens['epub'] ? __( 'EPUB disponible', 'yume-core' ) : '';
}

/**
 * Annonce Discord d'un tome publié.
 *
 * Variantes : tome en cours de parution (yume_parution_tome() = en_cours, première sortie
 * chapitre par chapitre) : « Nouvelle sortie : SukaMoka — Tome 2 : Prologue disponible ! » ;
 * $variante = complet (yume_tome_complet) : « SukaMoka — Le tome 2 est complet : PDF et EPUB
 * disponibles ! ». Sinon le texte habituel d'un tome complet.
 *
 * @param int    $tome_id  Tome.
 * @param string $variante Vide (selon la parution du tome) ou « complet ».
 * @return array{texte:string,embeds:array}
 */
function annonce_tome( int $tome_id, string $variante = '' ): array {
	if ( '' === $variante && 'en_cours' === yume_parution_tome( $tome_id ) ) {
		$variante = 'en_cours';
	}
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

	$chapitres = 'en_cours' === $variante ? libelle_chapitres_en_ligne( $tome_id ) : '';
	if ( '' !== $chapitres ) {
		$texte = sprintf(
			/* translators: 1: œuvre, 2: libellé du tome, 3: chapitres (Prologue, chapitres 1 à 3) */
			_n( 'Nouvelle sortie : **%1$s** — %2$s : %3$s disponible !', 'Nouvelle sortie : **%1$s** — %2$s : %3$s disponibles !', max( 1, $chap['publies'] ), 'yume-core' ),
			echapper_discord( $oeuvre ),
			echapper_discord( $libelle ),
			echapper_discord( $chapitres )
		);
	} elseif ( 'complet' === $variante ) {
		$formats = formats_disponibles( $tome_id );
		$texte   = sprintf(
			/* translators: 1: œuvre, 2: libellé du tome */
			__( 'Tome complet : **%1$s** — %2$s est complet !', 'yume-core' ),
			echapper_discord( $oeuvre ),
			echapper_discord( $libelle )
		);
		if ( '' !== $formats ) {
			$texte = sprintf(
				/* translators: 1: œuvre, 2: libellé du tome, 3: formats (PDF et EPUB disponibles) */
				__( 'Tome complet : **%1$s** — %2$s est complet : %3$s !', 'yume-core' ),
				echapper_discord( $oeuvre ),
				echapper_discord( $libelle ),
				$formats
			);
		}
	} else {
		$texte = sprintf(
			/* translators: 1: œuvre, 2: libellé du tome */
			__( 'Nouvelle sortie : **%1$s** — %2$s est disponible !', 'yume-core' ),
			echapper_discord( $oeuvre ),
			echapper_discord( $libelle )
		);
	}
	$annonce = array(
		'texte'  => $texte,
		'embeds' => array( $embed ),
	);
	/**
	 * Filtre l'annonce Discord d'un tome publié (texte vide : pas d'annonce).
	 *
	 * @param array  $annonce  ['texte' => string, 'embeds' => array].
	 * @param int    $tome_id  Tome.
	 * @param string $variante Vide (tome complet), en_cours (première sortie chapitre par
	 *                         chapitre) ou complet (fin de parution).
	 */
	$annonce = (array) apply_filters( 'yume_planning_annonce_tome', $annonce, $tome_id, $variante );
	return array(
		'texte'  => (string) ( $annonce['texte'] ?? '' ),
		'embeds' => (array) ( $annonce['embeds'] ?? array() ),
	);
}

/**
 * Sortie d'un tome dans le planning (décision SCAN-10) : étape « publié », avancements à 100 %
 * et tome débloqué, seulement si aucun de ses chapitres ne reste à sortir (brouillon, en
 * attente, programmé). Sinon (arc ou tome publié chapitre par chapitre), l'étape est gardée,
 * la sortie est journalisée (« publie », partiel) et le tome reste dans les listes de
 * l'équipe : il passera « publié » à la sortie de son dernier chapitre (verifier_fin_sortie()).
 *
 * Tome publié chapitre par chapitre (méta yume_parution = en_cours) : toujours une sortie
 * partielle, même sans chapitre en attente (la suite n'est pas encore déposée) ; l'avancement
 * suit les chapitres en ligne (avancement_chapitres()). Il passe « publié » quand l'équipe le
 * marque complet (yume_tome_complet).
 *
 * @param int   $tome_id Tome (publié).
 * @param int   $user_id Auteur.
 * @param array $details Données ajoutées à la ligne de journal « publie » (ex. retour => true).
 * @return bool Vrai si le tome est passé à l'étape « publié ».
 */
function appliquer_sortie( int $tome_id, int $user_id, array $details = array() ): bool {
	$chap     = compte_chapitres( $tome_id );
	$attente  = chapitres_en_attente( $tome_id );
	$en_cours = 'en_cours' === (string) get_post_meta( $tome_id, 'yume_parution', true );
	if ( $attente > 0 || $en_cours ) {
		update_post_meta( $tome_id, META_SORTIE_PARTIELLE, gmt() );
		mettre_a_jour(
			$tome_id,
			$en_cours ? avancement_chapitres( $tome_id ) : array(),
			$user_id,
			array(
				'forcer'         => true,
				'toujours_dater' => true,
				'evenements'     => array(
					'publie' => array(
						'',
						array_merge(
							array(
								'chapitres' => $chap['publies'],
								'total'     => $chap['total'],
								'partiel'   => true,
							),
							$details
						),
					),
				),
			)
		);
		return false;
	}
	delete_post_meta( $tome_id, META_SORTIE_PARTIELLE );
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
				'publie' => array( '', array_merge( array( 'chapitres' => $chap['publies'] ), $details ) ),
			),
		)
	);
	return true;
}

/**
 * Tome en ligne dont des chapitres restaient à sortir : passe à l'étape « publié » quand il
 * n'en reste plus (dernier chapitre publié, ou chapitres restants retirés).
 *
 * @param int $tome_id Tome.
 * @param int $user_id Auteur.
 * @return bool Vrai si le tome vient de passer « publié ».
 */
function verifier_fin_sortie( int $tome_id, int $user_id = 0 ): bool {
	if ( ! $tome_id || ! get_post_meta( $tome_id, META_SORTIE_PARTIELLE, true ) ) {
		return false;
	}
	if ( 'publish' !== get_post_status( $tome_id ) ) {
		delete_post_meta( $tome_id, META_SORTIE_PARTIELLE );
		return false;
	}
	// Tome publié chapitre par chapitre : seul « Le tome est complet » termine sa sortie.
	if ( chapitres_en_attente( $tome_id ) > 0 || 'en_cours' === (string) get_post_meta( $tome_id, 'yume_parution', true ) ) {
		return false;
	}
	if ( 'publie' === donnees_tome( $tome_id )['etape'] ) {
		delete_post_meta( $tome_id, META_SORTIE_PARTIELLE );
		return false;
	}
	return appliquer_sortie( $tome_id, $user_id, array( 'complet' => true ) );
}

/**
 * Avancement d'un tome publié chapitre par chapitre d'après ses chapitres en ligne : si le
 * nombre de chapitres prévus (yume_chapitres_prevus) est connu, chaque étape de travail vaut au
 * moins la part des chapitres en ligne (un chapitre en ligne est traduit, relu et édité ;
 * une étape plus avancée garde sa valeur). Sans chapitres prévus : rien (avancement inchangé).
 *
 * @param int $tome_id Tome.
 * @return array Saisie pour mettre_a_jour() (['avancement' => …]) ou tableau vide.
 */
function avancement_chapitres( int $tome_id ): array {
	$prevus = (int) get_post_meta( $tome_id, 'yume_chapitres_prevus', true );
	if ( $prevus <= 0 ) {
		return array();
	}
	$pct        = (int) min( 100, round( compte_chapitres( $tome_id )['publies'] * 100 / $prevus ) );
	$avant      = donnees_tome( $tome_id )['avancement'];
	$avancement = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		if ( $pct > (int) ( $avant[ $etape ] ?? 0 ) ) {
			$avancement[ $etape ] = $pct;
		}
	}
	return $avancement ? array( 'avancement' => $avancement ) : array();
}

/**
 * Met à jour l'avancement d'un tome en cours de parution d'après ses chapitres en ligne
 * (avancement_chapitres()) ; appelé à la sortie d'un chapitre, et par le module publication
 * après une sortie sans annonce.
 *
 * @param int $tome_id Tome.
 * @param int $user_id Auteur (0 = système).
 * @return bool Vrai si l'avancement a changé.
 */
function avancer_selon_chapitres( int $tome_id, int $user_id = 0 ): bool {
	if ( 'en_cours' !== (string) get_post_meta( $tome_id, 'yume_parution', true ) || 'publish' !== get_post_status( $tome_id ) ) {
		return false;
	}
	$saisie = avancement_chapitres( $tome_id );
	if ( ! $saisie ) {
		return false;
	}
	$resultat = mettre_a_jour( $tome_id, $saisie, $user_id, array( 'forcer' => true ) );
	return ! is_wp_error( $resultat ) && ! empty( $resultat['changements'] );
}

/**
 * Tome publié chapitre par chapitre marqué complet (action yume_tome_complet, module
 * publication) : sortie terminée (étape « publié », 100 %, journal « publie » complet, ou sortie
 * partielle s'il reste des chapitres programmés), puis annonce Discord « … est complet » si elle
 * est demandée.
 *
 * @param int  $tome_id  Tome.
 * @param bool $annoncer Annoncer la fin de parution sur Discord.
 */
function sur_tome_complet( $tome_id, $annoncer = true ): void {
	$tome_id = (int) $tome_id;
	if ( 'yume_tome' !== get_post_type( $tome_id ) || 'publish' !== get_post_status( $tome_id ) ) {
		return;
	}
	appliquer_sortie( $tome_id, auteur_publication( $tome_id ), array( 'complet' => true ) );
	if ( $annoncer ) {
		$annonce = annonce_tome( $tome_id, 'complet' );
		if ( '' !== $annonce['texte'] ) {
			yume_discord( 'sorties', $annonce['texte'], $annonce['embeds'] );
		}
	}
}
add_action( 'yume_tome_complet', __NAMESPACE__ . '\\sur_tome_complet', 10, 2 );

/**
 * Tome publié (action yume_tome_publie) : planning « publié » à 100 % (ou sortie partielle,
 * voir appliquer_sortie()), journal public et annonce Discord.
 *
 * @param int $tome_id Tome.
 */
function sur_tome_publie( $tome_id ): void {
	$tome_id = (int) $tome_id;
	if ( 'yume_tome' !== get_post_type( $tome_id ) ) {
		return;
	}
	delete_post_meta( $tome_id, META_DEPUBLIE );
	appliquer_sortie( $tome_id, auteur_publication( $tome_id ) );

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
	// Tome en cours de parution : l'avancement suit les chapitres en ligne.
	$en_cours = 'en_cours' === (string) get_post_meta( $tome_id, 'yume_parution', true );
	mettre_a_jour(
		$tome_id,
		$en_cours ? avancement_chapitres( $tome_id ) : array(),
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

/**
 * Dépublication d'un tome (transition_post_status, publish → brouillon, en attente, privé ou
 * programmé) : étape « édition » si elle était « publié » (avancements conservés), journal
 * « depublie », date de dernière sortie de l'œuvre recalculée. Le tome est marqué (méta
 * META_DEPUBLIE) : son retour en ligne rétablit « publié » (voir apres_enregistrement_tome()).
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function sur_transition_tome( $nouveau, $ancien, $post ): void {
	if ( ! $post instanceof \WP_Post || 'yume_tome' !== $post->post_type ) {
		return;
	}
	if ( 'publish' !== $ancien || in_array( $nouveau, array( 'publish', 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return;
	}
	depublier( (int) $post->ID, (string) $nouveau, get_current_user_id() );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\sur_transition_tome', 20, 3 );

/**
 * Applique la dépublication d'un tome au planning (voir sur_transition_tome()).
 *
 * @param int    $tome_id Tome.
 * @param string $statut  Nouveau statut WordPress.
 * @param int    $user_id Auteur (0 = système).
 */
function depublier( int $tome_id, string $statut, int $user_id ): void {
	delete_post_meta( $tome_id, META_SORTIE_PARTIELLE );
	update_post_meta( $tome_id, META_DEPUBLIE, gmt() );
	$saisie = 'publie' === donnees_tome( $tome_id )['etape'] ? array( 'etape' => 'edition' ) : array();
	mettre_a_jour(
		$tome_id,
		$saisie,
		$user_id,
		array(
			'forcer'         => true,
			'toujours_dater' => true,
			'evenements'     => array(
				'depublie' => array( 'publish', $statut ),
			),
		)
	);
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	if ( $oeuvre_id && function_exists( '\\Yume\\Core\\Core\\recalculer_derniere_sortie' ) ) {
		\Yume\Core\Core\recalculer_derniere_sortie( $oeuvre_id );
	}
}

/**
 * Aligne la date cible d'un tome programmé (statut future) sur son jour de sortie programmé
 * (décision SCAN-05), changement journalisé. Appelé automatiquement à chaque enregistrement
 * d'un tome programmé (apres_enregistrement_tome()) ; le module publication peut aussi
 * l'appeler juste après avoir programmé une sortie. Sans effet pour un tome non programmé.
 *
 * @param int $tome_id Tome.
 * @param int $user_id Auteur (0 = système).
 * @return bool Vrai si la date cible a changé.
 */
function aligner_date_programmee( int $tome_id, int $user_id = 0 ): bool {
	$date = date_programmee( $tome_id );
	if ( '' === $date || donnees_tome( $tome_id )['date_cible'] === $date ) {
		return false;
	}
	$resultat = mettre_a_jour( $tome_id, array( 'date_cible' => $date ), $user_id, array( 'forcer' => true ) );
	return ! is_wp_error( $resultat ) && isset( $resultat['changements']['date_cible'] );
}

/**
 * Après l'enregistrement d'un tome (métadonnées écrites, événements du cœur émis) :
 *
 * - tome programmé : date cible alignée sur la date programmée ;
 * - tome dépublié revenu en ligne : si yume_tome_publie n'a pas été émis (il ne l'est qu'une
 *   fois par tome), la sortie est rétablie par appliquer_sortie() (étape « publié », ou sortie
 *   partielle), sans nouvelle annonce ;
 * - tome en ligne dont des chapitres restaient à sortir : fin de sortie vérifiée.
 *
 * @param int      $post_id Contenu.
 * @param \WP_Post $post    Contenu.
 */
function apres_enregistrement_tome( $post_id, $post = null ): void {
	$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
	if ( ! $post || 'yume_tome' !== $post->post_type ) {
		return;
	}
	$tome_id = (int) $post->ID;
	if ( 'future' === $post->post_status ) {
		aligner_date_programmee( $tome_id, get_current_user_id() );
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( get_post_meta( $tome_id, META_DEPUBLIE, true ) ) {
		delete_post_meta( $tome_id, META_DEPUBLIE );
		if ( 'publie' !== donnees_tome( $tome_id )['etape'] ) {
			appliquer_sortie( $tome_id, auteur_publication( $tome_id ), array( 'retour' => true ) );
		}
		return;
	}
	verifier_fin_sortie( $tome_id, auteur_publication( $tome_id ) );
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_tome', 30, 2 );

/**
 * Après l'enregistrement d'un chapitre (publication, programmation, corbeille…) : son tome,
 * en ligne avec des chapitres encore à sortir, passe-t-il « publié » ?
 *
 * @param int      $post_id Contenu.
 * @param \WP_Post $post    Contenu.
 */
function apres_enregistrement_chapitre( $post_id, $post = null ): void {
	$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
	if ( ! $post || 'yume_chapitre' !== $post->post_type ) {
		return;
	}
	$tome_id = yume_get_tome_id( (int) $post->ID );
	if ( $tome_id ) {
		verifier_fin_sortie( $tome_id, auteur_publication( (int) $post->ID ) );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_chapitre', 30, 2 );
