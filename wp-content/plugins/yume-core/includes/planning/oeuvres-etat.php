<?php
/**
 * État d'une œuvre (lot E) : terme de la taxonomie yume_statut (« Statut de la traduction »),
 * libellés de l'espace équipe yume_etats_oeuvre() (En cours de publication, Terminée, En pause,
 * Abandonnée, Licenciée), modifiable sur chaque ligne de la vue « Œuvres » et dans le
 * formulaire « Modifier l'œuvre ».
 *
 * Tout changement passe par changer_etat_oeuvre() :
 *
 * - « Licenciée » (après confirmation) : chapitres en ligne ou programmés des tomes de l'œuvre
 *   remis en brouillon (méta _yume_retire_licence = date GMT, et _yume_retire pour que la
 *   publication ne les ressorte pas), e-mails d'alerte encore en attente pour ces chapitres
 *   annulés, liens yume_lien_pdf / yume_lien_epub des tomes mis de côté dans la méta privée
 *   _yume_liens_licence ; la fiche de l'œuvre et ses tomes restent en ligne ;
 * - quitter « Licenciée » (après confirmation) : restauration, proposée par défaut, de ce qui
 *   avait été retiré (chapitres republiés sans annonce ni notification, ou reprogrammés si leur
 *   date est encore à venir ; liens remis s'ils n'ont pas été remplacés entre-temps) ;
 * - en pause, terminée, abandonnée, licenciée : plus de retard, de rappel ni d'alerte de
 *   planning pour ses tomes (oeuvre_sans_rappels(), filtre yume_planning_etat de planning.php,
 *   rappels.php) ; retour « En cours de publication » : tout reprend.
 *
 * Chaque changement : ligne « etat_oeuvre » au journal de l'équipe (jamais publique), action
 * yume_oeuvre_etat_change( $oeuvre_id, $ancien, $nouveau, $user_id ), caches de la
 * bibliothèque, du calendrier ICS, des indicateurs et pages du planning (Batcache) vidés.
 *
 * Formulaires sans JavaScript (admin-post.php, action yume_oeuvre_etat, nonce
 * yume_oeuvre_etat_{id}, droit edit_post sur l'œuvre) ; écran de confirmation
 * ?vue=oeuvres&changer=ID&vers=ETAT.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Méta d'un chapitre retiré de la lecture en ligne à la licence de l'œuvre (date GMT). */
const META_RETIRE_LICENCE = '_yume_retire_licence';

/** Méta privée d'un tome : liens PDF / EPUB mis de côté à la licence ({pdf, epub, le}). */
const META_LIENS_LICENCE = '_yume_liens_licence';

/** Méta d'un chapitre retiré (partagée avec la publication : jamais ressorti par elle). */
const META_CHAPITRE_RETIRE_LICENCE = '_yume_retire';

/** État qui retire la lecture en ligne et les liens. */
const ETAT_LICENCIEE = 'licenciee';

/** Liens d'un tome retirés à la licence (méta publique => clé dans META_LIENS_LICENCE). */
const LIENS_LICENCE = array(
	'yume_lien_pdf'  => 'pdf',
	'yume_lien_epub' => 'epub',
);

/*
 * -----------------------------------------------------------------------------
 * Tomes et chapitres concernés
 * -----------------------------------------------------------------------------
 */

/**
 * Tomes vivants (tous statuts actifs) d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 * @return int[]
 */
function tomes_etat_oeuvre( int $oeuvre_id ): array {
	return array_map( 'intval', wp_list_pluck( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ), 'ID' ) );
}

/**
 * Chapitres des tomes donnés, selon leurs statuts, éventuellement limités à ceux qui portent
 * une méta.
 *
 * @param int[]    $tomes   Tomes.
 * @param string[] $statuts Statuts.
 * @param string   $meta    Méta exigée ('' : aucune).
 * @return int[]
 */
function chapitres_des_tomes( array $tomes, array $statuts, string $meta = '' ): array {
	if ( ! $tomes ) {
		return array();
	}
	$requete = array(
		array(
			'key'     => 'yume_tome_id',
			'value'   => array_map( 'intval', $tomes ),
			'compare' => 'IN',
		),
	);
	if ( '' !== $meta ) {
		$requete[] = array(
			'key'     => $meta,
			'compare' => 'EXISTS',
		);
	}
	$ids = get_posts(
		array(
			'post_type'        => 'yume_chapitre',
			'post_status'      => $statuts,
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => $requete, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return array_map( 'intval', $ids );
}

/**
 * Liens PDF / EPUB d'un tome encore en ligne (clé courte => adresse).
 *
 * @param int $tome_id Tome.
 * @return array<string,string>
 */
function liens_publics_tome( int $tome_id ): array {
	$liens = array();
	foreach ( LIENS_LICENCE as $meta => $cle ) {
		$url = trim( (string) get_post_meta( $tome_id, $meta, true ) );
		if ( '' !== $url ) {
			$liens[ $cle ] = $url;
		}
	}
	return $liens;
}

/**
 * Liens d'un tome mis de côté à la licence (clé courte => adresse).
 *
 * @param int $tome_id Tome.
 * @return array<string,string>
 */
function liens_mis_de_cote( int $tome_id ): array {
	$valeur = get_post_meta( $tome_id, META_LIENS_LICENCE, true );
	$liens  = array();
	foreach ( LIENS_LICENCE as $cle ) {
		$url = is_array( $valeur ) && is_string( $valeur[ $cle ] ?? null ) ? trim( $valeur[ $cle ] ) : '';
		if ( '' !== $url ) {
			$liens[ $cle ] = $url;
		}
	}
	return $liens;
}

/**
 * Ce que le passage à « Licenciée » retirerait (rien n'est modifié).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{tomes:int,chapitres:int,publies:int,programmes:int,liens:int,ids:int[],tomes_liens:int[]}
 */
function bilan_retrait_licence( int $oeuvre_id ): array {
	$tomes      = tomes_etat_oeuvre( $oeuvre_id );
	$publies    = chapitres_des_tomes( $tomes, array( 'publish' ) );
	$programmes = chapitres_des_tomes( $tomes, array( 'future' ) );
	$touches    = array();
	foreach ( array_merge( $publies, $programmes ) as $chapitre ) {
		$touches[ (int) get_post_meta( $chapitre, 'yume_tome_id', true ) ] = true;
	}
	$avec_liens = array_values( array_filter( $tomes, static fn( int $tome ): bool => (bool) liens_publics_tome( $tome ) ) );
	foreach ( $avec_liens as $tome ) {
		$touches[ $tome ] = true;
	}
	return array(
		'tomes'       => count( $touches ),
		'chapitres'   => count( $publies ) + count( $programmes ),
		'publies'     => count( $publies ),
		'programmes'  => count( $programmes ),
		'liens'       => count( $avec_liens ),
		'ids'         => array_merge( $publies, $programmes ),
		'tomes_liens' => $avec_liens,
	);
}

/**
 * Ce que la sortie de « Licenciée » remettrait en ligne (rien n'est modifié).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{tomes:int,chapitres:int,liens:int,ids:int[],tomes_liens:int[]}
 */
function bilan_restauration_licence( int $oeuvre_id ): array {
	$tomes      = tomes_etat_oeuvre( $oeuvre_id );
	$chapitres  = chapitres_des_tomes( $tomes, \Yume\Core\Core\statuts_actifs(), META_RETIRE_LICENCE );
	$a_remettre = array_values( array_filter( $chapitres, static fn( int $id ): bool => 'draft' === get_post_status( $id ) ) );
	$touches    = array();
	foreach ( $a_remettre as $chapitre ) {
		$touches[ (int) get_post_meta( $chapitre, 'yume_tome_id', true ) ] = true;
	}
	$avec_liens = array_values( array_filter( $tomes, static fn( int $tome ): bool => (bool) liens_mis_de_cote( $tome ) ) );
	foreach ( $avec_liens as $tome ) {
		$touches[ $tome ] = true;
	}
	return array(
		'tomes'       => count( $touches ),
		'chapitres'   => count( $a_remettre ),
		'liens'       => count( $avec_liens ),
		'ids'         => $chapitres,
		'tomes_liens' => $avec_liens,
	);
}

/*
 * -----------------------------------------------------------------------------
 * Retrait et restauration (licence)
 * -----------------------------------------------------------------------------
 */

/**
 * Retire la lecture en ligne et les liens d'une œuvre licenciée : chapitres en ligne ou
 * programmés en brouillon (marqués), e-mails d'alerte en attente annulés, liens PDF / EPUB mis
 * de côté. La fiche et les tomes ne changent pas de statut.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{tomes:int,chapitres:int,publies:int,programmes:int,liens:int}
 */
function retirer_lecture_licence( int $oeuvre_id ): array {
	$bilan = bilan_retrait_licence( $oeuvre_id );
	$quand = gmt();
	$fait  = 0;
	foreach ( $bilan['ids'] as $chapitre ) {
		update_post_meta( $chapitre, META_RETIRE_LICENCE, $quand );
		update_post_meta( $chapitre, META_CHAPITRE_RETIRE_LICENCE, 1 );
		$ok = wp_update_post(
			array(
				'ID'          => $chapitre,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $ok ) ) {
			delete_post_meta( $chapitre, META_RETIRE_LICENCE );
			delete_post_meta( $chapitre, META_CHAPITRE_RETIRE_LICENCE );
			continue;
		}
		++$fait;
	}
	// Alertes des lecteurs encore en file pour ces chapitres (programmés compris).
	if ( $bilan['ids'] && function_exists( '\\Yume\\Core\\Social\\retirer_alertes_en_attente' ) ) {
		\Yume\Core\Social\retirer_alertes_en_attente( $bilan['ids'] );
	}
	foreach ( $bilan['tomes_liens'] as $tome ) {
		$liens = array_merge( liens_mis_de_cote( $tome ), liens_publics_tome( $tome ) );
		update_post_meta( $tome, META_LIENS_LICENCE, array_merge( $liens, array( 'le' => $quand ) ) );
		foreach ( array_keys( LIENS_LICENCE ) as $meta ) {
			delete_post_meta( $tome, $meta );
		}
	}
	return array(
		'tomes'      => $bilan['tomes'],
		'chapitres'  => $fait,
		'publies'    => $bilan['publies'],
		'programmes' => $bilan['programmes'],
		'liens'      => $bilan['liens'],
	);
}

/**
 * Remet en ligne ce qui avait été retiré à la licence : chapitres marqués republiés (ou
 * reprogrammés si leur date est encore à venir) sans annonce ni notification (filtre
 * yume_core_notifier coupé le temps de l'opération), liens remis s'ils n'ont pas été remplacés
 * entre-temps. Marques effacées.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{tomes:int,chapitres:int,publies:int,programmes:int,liens:int}
 */
function restaurer_lecture_licence( int $oeuvre_id ): array {
	$bilan      = bilan_restauration_licence( $oeuvre_id );
	$publies    = 0;
	$programmes = 0;
	$couper     = static fn(): bool => false;
	add_filter( 'yume_core_notifier', $couper, 999 );
	try {
		foreach ( $bilan['ids'] as $chapitre ) {
			$statut = (string) get_post_status( $chapitre );
			delete_post_meta( $chapitre, META_RETIRE_LICENCE );
			if ( 'draft' !== $statut ) {
				// Déjà remis en ligne (ou programmé) autrement : seule la marque disparaît.
				continue;
			}
			delete_post_meta( $chapitre, META_CHAPITRE_RETIRE_LICENCE );
			// Date d'origine gardée : WordPress programme le chapitre si elle est encore à venir.
			$ok = wp_update_post(
				array(
					'ID'          => $chapitre,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $ok ) ) {
				continue;
			}
			if ( 'future' === get_post_status( $chapitre ) ) {
				++$programmes;
			} else {
				++$publies;
			}
		}
	} finally {
		remove_filter( 'yume_core_notifier', $couper, 999 );
	}
	foreach ( $bilan['tomes_liens'] as $tome ) {
		foreach ( liens_mis_de_cote( $tome ) as $cle => $url ) {
			$meta = (string) array_search( $cle, LIENS_LICENCE, true );
			if ( '' !== $meta && '' === trim( (string) get_post_meta( $tome, $meta, true ) ) ) {
				update_post_meta( $tome, $meta, $url );
			}
		}
		delete_post_meta( $tome, META_LIENS_LICENCE );
	}
	return array(
		'tomes'      => $bilan['tomes'],
		'chapitres'  => $publies + $programmes,
		'publies'    => $publies,
		'programmes' => $programmes,
		'liens'      => $bilan['liens'],
	);
}

/*
 * -----------------------------------------------------------------------------
 * Changement d'état
 * -----------------------------------------------------------------------------
 */

/**
 * Le passage de $ancien à $nouveau touche-t-il les lecteurs (entrée ou sortie de « Licenciée »)
 * et demande-t-il donc une confirmation ?
 *
 * @param string $ancien  État actuel.
 * @param string $nouveau État demandé.
 */
function etat_a_confirmer( string $ancien, string $nouveau ): bool {
	return $ancien !== $nouveau && ( ETAT_LICENCIEE === $nouveau || ETAT_LICENCIEE === $ancien );
}

/**
 * Aperçu d'un changement d'état, pour l'écran de confirmation (rien n'est modifié).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $etat      État demandé.
 * @return array{ancien:string,nouveau:string,confirmation:bool,retrait:array|null,restauration:array|null}
 */
function apercu_etat_oeuvre( int $oeuvre_id, string $etat ): array {
	$ancien = etat_oeuvre( $oeuvre_id );
	return array(
		'ancien'       => $ancien,
		'nouveau'      => $etat,
		'confirmation' => etat_a_confirmer( $ancien, $etat ),
		'retrait'      => ETAT_LICENCIEE === $etat && ETAT_LICENCIEE !== $ancien ? bilan_retrait_licence( $oeuvre_id ) : null,
		'restauration' => ETAT_LICENCIEE === $ancien && ETAT_LICENCIEE !== $etat ? bilan_restauration_licence( $oeuvre_id ) : null,
	);
}

/**
 * Change l'état d'une œuvre (fonction unique : ligne de la vue « Œuvres », formulaire
 * « Modifier l'œuvre », confirmation).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $etat      État demandé (slug yume_statut, clé de yume_etats_oeuvre()).
 * @param int    $user_id   Auteur (edit_post sur l'œuvre).
 * @param array  $options   'confirmer' (bool, défaut faux : exigé pour entrer dans
 *                          « Licenciée » ou en sortir), 'restaurer' (bool, défaut vrai : à la
 *                          sortie de « Licenciée », remettre en ligne ce qui avait été retiré).
 * @return array{changement:bool,ancien:string,nouveau:string,retrait:array|null,restauration:array|null}|\WP_Error
 */
function changer_etat_oeuvre( int $oeuvre_id, string $etat, int $user_id, array $options = array() ) {
	$options = array_merge(
		array(
			'confirmer' => false,
			'restaurer' => true,
		),
		$options
	);
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) || in_array( get_post_status( $oeuvre_id ), array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return erreur( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), 404 );
	}
	if ( ! user_can( $user_id, 'edit_post', $oeuvre_id ) ) {
		return erreur( 'yume_oeuvre_interdit', __( 'Votre rôle ne permet pas de modifier cette œuvre.', 'yume-core' ), 403 );
	}
	$etat = sanitize_title( $etat );
	if ( ! isset( yume_etats_oeuvre()[ $etat ] ) ) {
		return erreur( 'yume_oeuvre_etat', __( 'État de l’œuvre inconnu.', 'yume-core' ), 400 );
	}
	$ancien   = etat_oeuvre( $oeuvre_id );
	$resultat = array(
		'changement'   => false,
		'ancien'       => $ancien,
		'nouveau'      => $etat,
		'retrait'      => null,
		'restauration' => null,
	);
	if ( $ancien === $etat ) {
		return $resultat;
	}
	if ( etat_a_confirmer( $ancien, $etat ) && ! $options['confirmer'] ) {
		return erreur(
			'yume_oeuvre_etat_confirmation',
			__( 'Ce changement touche les lecteurs : confirmez-le.', 'yume-core' ),
			409,
			array( 'apercu' => apercu_etat_oeuvre( $oeuvre_id, $etat ) )
		);
	}

	if ( ETAT_LICENCIEE === $etat ) {
		$resultat['retrait'] = retirer_lecture_licence( $oeuvre_id );
	} elseif ( ETAT_LICENCIEE === $ancien && $options['restaurer'] ) {
		$resultat['restauration'] = restaurer_lecture_licence( $oeuvre_id );
	}
	$termes = wp_set_object_terms( $oeuvre_id, array( $etat ), 'yume_statut' );
	if ( is_wp_error( $termes ) ) {
		return $termes;
	}
	$resultat['changement'] = true;

	$details = array(
		'oeuvre' => $oeuvre_id,
		'titre'  => titre_brut( $oeuvre_id ),
		'etat'   => $etat,
	);
	if ( $resultat['retrait'] ) {
		$details['retrait'] = array_intersect_key( $resultat['retrait'], array_flip( array( 'chapitres', 'liens' ) ) );
	}
	if ( $resultat['restauration'] ) {
		$details['restauration'] = array_intersect_key( $resultat['restauration'], array_flip( array( 'chapitres', 'liens' ) ) );
	}
	journaliser( 0, $user_id, 'etat_oeuvre', $ancien, $details, false );

	/**
	 * L'état d'une œuvre vient de changer (après le retrait ou la restauration de la lecture
	 * en ligne pour « Licenciée »).
	 *
	 * @param int    $oeuvre_id Œuvre.
	 * @param string $ancien    Ancien état (slug yume_statut, '' : aucun).
	 * @param string $nouveau   Nouvel état.
	 * @param int    $user_id   Auteur.
	 */
	do_action( 'yume_oeuvre_etat_change', $oeuvre_id, $ancien, $etat, $user_id );
	return $resultat;
}

/**
 * Caches à vider quand l'état d'une œuvre change : bibliothèque (version de
 * yume_bibliotheque_cache), calendrier ICS, pages du planning, de l'œuvre, de ses tomes et de la
 * bibliothèque dans Batcache (les indicateurs : kpi.php).
 *
 * @param int|mixed $oeuvre_id Œuvre.
 */
function vider_caches_etat_oeuvre( $oeuvre_id ): void {
	$oeuvre_id = (int) $oeuvre_id;
	if ( function_exists( '\\Yume\\Core\\Library\\invalider' ) ) {
		\Yume\Core\Library\invalider();
	}
	invalider_ics();
	if ( ! function_exists( 'batcache_clear_url' ) ) {
		return;
	}
	$adresses = adresses_pages_planning();
	if ( 'publish' === get_post_status( $oeuvre_id ) ) {
		$adresses[] = (string) get_permalink( $oeuvre_id );
	}
	foreach ( yume_get_tomes( $oeuvre_id ) as $tome ) {
		$adresses[] = (string) get_permalink( $tome );
	}
	$adresses[] = yume_url_page( 'bibliotheque' );
	foreach ( array_unique( array_filter( $adresses ) ) as $adresse ) {
		batcache_clear_url( $adresse );
	}
}
add_action( 'yume_oeuvre_etat_change', __NAMESPACE__ . '\\vider_caches_etat_oeuvre' );

/**
 * Texte d'une ligne « etat_oeuvre » du journal de l'équipe.
 *
 * @param array $infos Détails journalisés (titre, etat, retrait, restauration).
 */
function texte_etat_oeuvre_journal( array $infos ): string {
	$etat    = (string) ( $infos['etat'] ?? '' );
	$libelle = yume_etats_oeuvre()[ $etat ] ?? $etat;
	/* translators: 1: titre de l'œuvre, 2: état */
	$texte = sprintf( __( 'état de « %1$s » : %2$s', 'yume-core' ), (string) ( $infos['titre'] ?? '' ), mb_strtolower( $libelle ) );
	foreach (
		array(
			/* translators: 1: chapitres, 2: tomes */
			'retrait'      => __( 'lecture en ligne retirée (%1$d chapitre(s), liens de %2$d tome(s))', 'yume-core' ),
			/* translators: 1: chapitres, 2: tomes */
			'restauration' => __( 'lecture en ligne rétablie sans annonce (%1$d chapitre(s), liens de %2$d tome(s))', 'yume-core' ),
		) as $cle => $modele
	) {
		if ( is_array( $infos[ $cle ] ?? null ) ) {
			$texte .= ', ' . sprintf( $modele, (int) ( $infos[ $cle ]['chapitres'] ?? 0 ), (int) ( $infos[ $cle ]['liens'] ?? 0 ) );
		}
	}
	return $texte;
}

/**
 * Message affiché après un changement d'état réussi.
 *
 * @param int   $oeuvre_id Œuvre.
 * @param array $resultat  Résultat de changer_etat_oeuvre().
 */
function message_etat_oeuvre( int $oeuvre_id, array $resultat ): string {
	$titre   = titre_brut( $oeuvre_id );
	$libelle = yume_etats_oeuvre()[ $resultat['nouveau'] ] ?? $resultat['nouveau'];
	if ( ! $resultat['changement'] ) {
		/* translators: 1: titre, 2: état */
		return sprintf( __( '« %1$s » est déjà « %2$s ».', 'yume-core' ), $titre, $libelle );
	}
	/* translators: 1: titre, 2: état */
	$message = sprintf( __( '« %1$s » est maintenant « %2$s ».', 'yume-core' ), $titre, $libelle );
	if ( is_array( $resultat['retrait'] ) ) {
		$message .= ' ' . sprintf(
			/* translators: 1: chapitres, 2: tomes */
			__( 'Lecture en ligne retirée : %1$d chapitre(s) en brouillon, liens PDF/EPUB de %2$d tome(s) mis de côté. La fiche et les tomes restent en ligne.', 'yume-core' ),
			(int) $resultat['retrait']['chapitres'],
			(int) $resultat['retrait']['liens']
		);
	} elseif ( is_array( $resultat['restauration'] ) ) {
		$message .= ' ' . sprintf(
			/* translators: 1: chapitres, 2: tomes */
			__( 'Remis en ligne sans annonce ni notification : %1$d chapitre(s), liens de %2$d tome(s).', 'yume-core' ),
			(int) $resultat['restauration']['chapitres'],
			(int) $resultat['restauration']['liens']
		);
	} elseif ( ETAT_LICENCIEE === $resultat['ancien'] ) {
		$message .= ' ' . __( 'La lecture en ligne et les liens retirés à la licence restent de côté.', 'yume-core' );
	}
	$message .= ' ' . ( in_array( $resultat['nouveau'], ETATS_OEUVRE_SANS_RAPPELS, true )
		? __( 'Plus de rappel ni d’alerte de planning pour ses tomes.', 'yume-core' )
		: __( 'Les rappels de planning de ses tomes reprennent.', 'yume-core' ) );
	return $message;
}

/*
 * -----------------------------------------------------------------------------
 * Liste « Œuvres » : tomes et leur état
 * -----------------------------------------------------------------------------
 */

/**
 * Valeur numérique d'un numéro de tome, ou null.
 *
 * @param mixed $numero Méta yume_numero.
 */
function numero_tome_tri( $numero ): ?float {
	return is_numeric( $numero ) ? (float) $numero : null;
}

/**
 * Tomes vivants de plusieurs œuvres en une seule requête (méta en cache), triés comme
 * yume_get_tomes() (numéro, ordre, date, ID) : œuvre => WP_Post[].
 *
 * @param int[] $oeuvre_ids Œuvres.
 * @return array<int,\WP_Post[]>
 */
function tomes_des_oeuvres( array $oeuvre_ids ): array {
	$oeuvre_ids = array_values( array_filter( array_map( 'intval', $oeuvre_ids ) ) );
	if ( ! $oeuvre_ids ) {
		return array();
	}
	$tomes   = get_posts(
		array(
			'post_type'        => 'yume_tome',
			'post_status'      => \Yume\Core\Core\statuts_actifs(),
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => 'yume_oeuvre_id',
					'value'   => $oeuvre_ids,
					'compare' => 'IN',
				),
			),
		)
	);
	$groupes = array();
	foreach ( $tomes as $tome ) {
		$groupes[ (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true ) ][] = $tome;
	}
	foreach ( $groupes as &$liste ) {
		usort(
			$liste,
			static function ( \WP_Post $a, \WP_Post $b ): int {
				$na = numero_tome_tri( get_post_meta( $a->ID, 'yume_numero', true ) );
				$nb = numero_tome_tri( get_post_meta( $b->ID, 'yume_numero', true ) );
				if ( $na !== $nb ) {
					if ( null === $na || null === $nb ) {
						return null === $na ? 1 : -1;
					}
					return $na <=> $nb;
				}
				return array( $a->menu_order, (string) $a->post_date, $a->ID ) <=> array( $b->menu_order, (string) $b->post_date, $b->ID );
			}
		);
	}
	unset( $liste );
	return $groupes;
}

/**
 * État de chaque tome (yume_parution_tome() : a_paraitre, en_cours, complet) et libellé court
 * (« T1 », « A3 », « EX1 »).
 *
 * @param \WP_Post[] $tomes Tomes triés.
 * @return array<int,array{id:int,court:string,nature:string,etat:string}>
 */
function etats_tomes( array $tomes ): array {
	$etats = array();
	foreach ( $tomes as $tome ) {
		$nature  = (string) get_post_meta( $tome->ID, 'yume_nature', true );
		$etats[] = array(
			'id'     => (int) $tome->ID,
			'court'  => str_replace( '.', '', yume_libelle_tome( (int) $tome->ID, true ) ),
			'nature' => '' !== $nature ? $nature : 'tome',
			'etat'   => yume_parution_tome( (int) $tome->ID ),
		);
	}
	return $etats;
}

/**
 * Tomes regroupés quand ils se suivent, sont de même nature et dans le même état (« T1 à T6 »).
 *
 * @param array $etats Résultat de etats_tomes().
 * @return array<int,array{debut:string,fin:string,nb:int,etat:string}>
 */
function groupes_tomes( array $etats ): array {
	$groupes = array();
	$dernier = null;
	foreach ( $etats as $tome ) {
		$cle = $tome['nature'] . '|' . $tome['etat'];
		if ( null !== $dernier && $dernier === $cle ) {
			$i                    = count( $groupes ) - 1;
			$groupes[ $i ]['fin'] = $tome['court'];
			$groupes[ $i ]['nb'] += 1;
			continue;
		}
		$groupes[] = array(
			'debut' => $tome['court'],
			'fin'   => $tome['court'],
			'nb'    => 1,
			'etat'  => $tome['etat'],
		);
		$dernier   = $cle;
	}
	return $groupes;
}

/**
 * Libellé de l'état d'un groupe de tomes (« Publié », « Publiés », « Planifiés »…).
 *
 * @param string $etat État (yume_etats_tome()).
 * @param int    $nb   Nombre de tomes.
 */
function libelle_etat_tomes( string $etat, int $nb ): string {
	switch ( $etat ) {
		case 'complet':
			return _n( 'Publié', 'Publiés', $nb, 'yume-core' );
		case 'a_paraitre':
			return _n( 'Planifié', 'Planifiés', $nb, 'yume-core' );
	}
	return yume_etats_tome()[ $etat ] ?? $etat;
}

/**
 * Faut-il proposer « passer à Terminée » ? Œuvre pas encore terminée dont la VO est terminée
 * (yume_statut_vo = termine) avec yume_nb_tomes_vo > 0, et au moins autant de tomes (nature
 * tome) « Publié ». Jamais appliqué automatiquement.
 *
 * @param int   $oeuvre_id Œuvre.
 * @param array $etats     Tomes de l'œuvre (etats_tomes()).
 */
function suggerer_terminee( int $oeuvre_id, array $etats ): bool {
	$vo = (int) get_post_meta( $oeuvre_id, 'yume_nb_tomes_vo', true );
	if ( $vo <= 0 || 'termine' !== get_post_meta( $oeuvre_id, 'yume_statut_vo', true ) || in_array( 'terminee', etats_termes_oeuvre( $oeuvre_id ), true ) ) {
		return false;
	}
	$publies = count( array_filter( $etats, static fn( array $t ): bool => 'tome' === $t['nature'] && 'complet' === $t['etat'] ) );
	return $publies >= $vo;
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Pictogramme et variante de pastille d'un état d'œuvre.
 *
 * @param string $etat État.
 * @return array{0:string,1:string}
 */
function apparence_etat_oeuvre( string $etat ): array {
	$apparences = array(
		'en-cours'   => array( '●', 'yn-chip--ok' ),
		'terminee'   => array( '✓', 'yn-chip--info' ),
		'en-pause'   => array( '❚❚', 'yn-chip--warn' ),
		'abandonnee' => array( '✕', 'yn-chip--err' ),
		'licenciee'  => array( '§', '' ),
	);
	return $apparences[ $etat ] ?? array( '•', '' );
}

/**
 * Pastille d'un état d'œuvre.
 *
 * @param string $etat État.
 */
function pastille_etat_oeuvre( string $etat ): string {
	$libelle = yume_etats_oeuvre()[ $etat ] ?? '';
	if ( '' === $libelle ) {
		return '';
	}
	list( $icone, $variante ) = apparence_etat_oeuvre( $etat );
	return '<span class="yn-chip ' . esc_attr( $variante ) . '"><span aria-hidden="true">' . esc_html( $icone ) . '</span> ' . esc_html( $libelle ) . '</span>';
}

/**
 * Pastilles des tomes d'une œuvre et de leur état (« T1 à T6 ✓ Publiés »).
 *
 * @param array $etats Tomes (etats_tomes()).
 */
function puces_tomes_oeuvre( array $etats ): string {
	if ( ! $etats ) {
		return '<p class="yn-oeuvre__tomes yn-muted">' . esc_html__( 'Aucun tome', 'yume-core' ) . '</p>';
	}
	$apparences = array(
		'complet'    => array( '✓', 'yn-chip--info' ),
		'en_cours'   => array( '●', 'yn-chip--ok' ),
		'a_paraitre' => array( '○', '' ),
	);
	$html       = '<ul class="yn-oeuvre__tomes" aria-label="' . esc_attr__( 'Tomes et leur état', 'yume-core' ) . '">';
	foreach ( groupes_tomes( $etats ) as $groupe ) {
		list( $icone, $variante ) = $apparences[ $groupe['etat'] ] ?? array( '•', '' );
		$nom                      = $groupe['nb'] > 1
			/* translators: 1: premier tome (« T1 »), 2: dernier tome (« T6 ») */
			? sprintf( __( '%1$s à %2$s', 'yume-core' ), $groupe['debut'], $groupe['fin'] )
			: $groupe['debut'];
		$html .= '<li class="yn-chip ' . esc_attr( $variante ) . '">' . esc_html( $nom ) . ' <span aria-hidden="true">' . esc_html( $icone ) . '</span> ' . esc_html( libelle_etat_tomes( $groupe['etat'], $groupe['nb'] ) ) . '</li>';
	}
	return $html . '</ul>';
}

/**
 * Adresse de l'écran de confirmation d'un changement d'état.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $etat      État demandé.
 */
function url_confirmer_etat( int $oeuvre_id, string $etat ): string {
	return url_vue_equipe(
		'oeuvres',
		array(
			'changer' => $oeuvre_id,
			'vers'    => $etat,
		)
	) . '#yn-oeuvre-etat';
}

/**
 * Champs cachés communs des formulaires d'état (action, œuvre, nonce).
 *
 * @param int $oeuvre_id Œuvre.
 */
function champs_caches_etat( int $oeuvre_id ): string {
	return '<input type="hidden" name="action" value="yume_oeuvre_etat"><input type="hidden" name="oeuvre_id" value="' . $oeuvre_id . '">'
		. wp_nonce_field( 'yume_oeuvre_etat_' . $oeuvre_id, '_yume_nonce', true, false );
}

/**
 * Bloc « État de l'œuvre » d'une ligne : liste déroulante et bouton « Changer » (edit_post),
 * sinon la pastille ; suggestion « passer à Terminée ».
 *
 * @param array $oeuvre Œuvre (oeuvres_equipe()).
 */
function bloc_etat_ligne( array $oeuvre ): string {
	$id    = (int) $oeuvre['id'];
	$etat  = (string) $oeuvre['etat'];
	$titre = (string) $oeuvre['titre'];
	$html  = '<div class="yn-oeuvre-etat">';
	if ( current_user_can( 'edit_post', $id ) ) {
		$options = yume_etats_oeuvre();
		if ( ! isset( $options[ $etat ] ) ) {
			$options = array( '' => __( '— Non renseigné —', 'yume-core' ) ) + $options;
		}
		$champ = 'yn-oeuvre-etat-' . $id;
		$html .= '<form class="yn-oeuvre-etat__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_caches_etat( $id );
		$html .= '<label class="yn-label" for="' . esc_attr( $champ ) . '">' . esc_html__( 'État de l’œuvre', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html( $titre ) . '</span></label>';
		$html .= '<span class="yn-oeuvre-etat__champs"><select id="' . esc_attr( $champ ) . '" name="etat">';
		foreach ( $options as $valeur => $libelle ) {
			$html .= '<option value="' . esc_attr( (string) $valeur ) . '"' . selected( (string) $valeur, $etat, false ) . '>' . esc_html( $libelle ) . '</option>';
		}
		$html .= '</select><button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Changer', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $titre ) . '</span></button></span></form>';
		if ( ! empty( $oeuvre['suggestion'] ) ) {
			$html .= '<p class="yn-oeuvre-etat__suggestion">' . esc_html__( 'Tous les tomes de la VO sont publiés :', 'yume-core' ) . ' <a href="' . esc_url( url_confirmer_etat( $id, 'terminee' ) ) . '">' . esc_html__( 'passer à « Terminée »', 'yume-core' ) . '<span class="yn-visually-hidden"> — ' . esc_html( $titre ) . '</span></a></p>';
		}
	} else {
		$html .= pastille_etat_oeuvre( $etat );
	}
	return $html . '</div>';
}

/**
 * Légende courte des cinq états d'une œuvre.
 */
function legende_etats_oeuvre(): string {
	$textes = array(
		'en-cours'   => __( 'L’équipe traduit et publie. Rappels de planning actifs.', 'yume-core' ),
		'terminee'   => __( 'Tous les tomes prévus sont publiés. Plus de rappel de planning.', 'yume-core' ),
		'en-pause'   => __( 'Reprise prévue : les tomes restent au planning, sans retard, rappel ni alerte.', 'yume-core' ),
		'abandonnee' => __( 'Plus de traduction. Les tomes publiés restent lisibles.', 'yume-core' ),
		'licenciee'  => __( 'Publiée officiellement en France : lecture en ligne et liens PDF/EPUB retirés (réversible), fiche et tomes en ligne.', 'yume-core' ),
	);
	$html   = '<section class="yn-team__section" id="yn-oeuvres-legende" aria-labelledby="yn-oeuvres-legende-titre"><h2 id="yn-oeuvres-legende-titre" class="yn-visually-hidden">' . esc_html__( 'États d’une œuvre', 'yume-core' ) . '</h2>';
	$html  .= '<ul class="yn-card yn-oeuvres-legende">';
	foreach ( yume_etats_oeuvre() as $etat => $libelle ) {
		if ( isset( $textes[ $etat ] ) ) {
			$html .= '<li>' . pastille_etat_oeuvre( $etat ) . '<p>' . esc_html( $textes[ $etat ] ) . '</p></li>';
		}
	}
	return $html . '</ul></section>';
}

/**
 * Effets d'un changement d'état, en clair (écran de confirmation).
 *
 * @param array $apercu Aperçu (apercu_etat_oeuvre()).
 * @return string[]
 */
function effets_etat_oeuvre( array $apercu ): array {
	$effets  = array();
	$retrait = $apercu['retrait'];
	if ( is_array( $retrait ) ) {
		/* translators: %d : nombre de tomes */
		$tomes    = sprintf( _n( '%d tome concerné', '%d tomes concernés', (int) $retrait['tomes'], 'yume-core' ), (int) $retrait['tomes'] );
		$effets[] = $retrait['chapitres']
			? sprintf(
				/* translators: 1: chapitres, 2: tomes concernés (« 2 tomes concernés ») */
				_n( '%1$d chapitre en ligne ou programmé (%2$s) repasse en brouillon : retiré de la lecture en ligne, adresse et commentaires conservés.', '%1$d chapitres en ligne ou programmés (%2$s) repassent en brouillon : retirés de la lecture en ligne, adresses et commentaires conservés.', (int) $retrait['chapitres'], 'yume-core' ),
				(int) $retrait['chapitres'],
				$tomes
			)
			: __( 'Aucun chapitre en ligne ni programmé : rien à retirer de la lecture en ligne.', 'yume-core' );
		$effets[] = $retrait['liens']
			/* translators: %d : tomes */
			? sprintf( _n( 'Les liens PDF et EPUB de %d tome sont mis de côté (retirés du site, gardés pour un retour).', 'Les liens PDF et EPUB de %d tomes sont mis de côté (retirés du site, gardés pour un retour).', (int) $retrait['liens'], 'yume-core' ), (int) $retrait['liens'] )
			: __( 'Aucun lien PDF ou EPUB à retirer.', 'yume-core' );
		$effets[] = __( 'Les e-mails d’alerte encore en attente pour ces chapitres sont annulés.', 'yume-core' );
		$effets[] = __( 'La fiche de l’œuvre et ses tomes restent en ligne, avec la mention « Licenciée ».', 'yume-core' );
		$effets[] = __( 'Réversible : en quittant « Licenciée », tout peut être remis en ligne, sans annonce.', 'yume-core' );
	}
	$effets[] = in_array( $apercu['nouveau'], ETATS_OEUVRE_SANS_RAPPELS, true )
		? __( 'Plus de retard, de rappel ni d’alerte de planning pour ses tomes (tableau de bord, Mes tâches, indicateurs, récapitulatif).', 'yume-core' )
		: __( 'Les tomes de l’œuvre retrouvent leurs retards, rappels et alertes de planning.', 'yume-core' );
	return $effets;
}

/**
 * Écran de confirmation d'un changement d'état (?vue=oeuvres&changer=ID&vers=ETAT).
 *
 * @param int        $oeuvre_id Œuvre.
 * @param string     $etat      État demandé.
 * @param array|null $retour    Retour du dernier envoi.
 */
function rendu_confirmation_etat( int $oeuvre_id, string $etat, ?array $retour ): string {
	$titre        = titre_brut( $oeuvre_id );
	$etats        = yume_etats_oeuvre();
	$apercu       = apercu_etat_oeuvre( $oeuvre_id, $etat );
	$retour_liste = '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'oeuvres' ) . '#yn-oeuvre-' . $oeuvre_id ) . '">' . esc_html__( 'Retour aux œuvres', 'yume-core' ) . '</a>';
	/* translators: %s : titre de l'œuvre */
	$tete  = sprintf( __( 'État de « %s »', 'yume-core' ), $titre );
	$html  = tete_vue( $tete, $retour_liste );
	$html .= '<section class="yn-team__section" id="yn-oeuvre-etat" aria-labelledby="yn-oeuvre-etat-titre"><div class="yn-card yn-oeuvre-confirmation">';
	$html .= zone_retour( $retour && 'yn-oeuvre-etat' === ( $retour['cible'] ?? '' ) ? $retour : null );
	if ( $apercu['ancien'] === $etat ) {
		/* translators: 1: titre, 2: état */
		$html .= '<h3 id="yn-oeuvre-etat-titre">' . esc_html( sprintf( __( '« %1$s » est déjà « %2$s ».', 'yume-core' ), $titre, $etats[ $etat ] ) ) . '</h3>';
		return $html . '<p>' . $retour_liste . '</p></div></section>';
	}
	/* translators: 1: titre, 2: nouvel état */
	$html .= '<h3 id="yn-oeuvre-etat-titre">' . esc_html( sprintf( __( 'Passer « %1$s » à « %2$s » ?', 'yume-core' ), $titre, $etats[ $etat ] ) ) . '</h3>';
	$html .= '<p class="yn-oeuvre-confirmation__etats">' . ( '' !== $apercu['ancien'] ? pastille_etat_oeuvre( $apercu['ancien'] ) : '<span class="yn-chip">' . esc_html__( 'Non renseigné', 'yume-core' ) . '</span>' ) . ' <span aria-hidden="true">→</span><span class="yn-visually-hidden">' . esc_html__( 'devient', 'yume-core' ) . '</span> ' . pastille_etat_oeuvre( $etat ) . '</p>';
	$html .= '<p class="yn-label">' . esc_html__( 'Ce qui va changer', 'yume-core' ) . '</p><ul class="yn-oeuvre-confirmation__effets">';
	foreach ( effets_etat_oeuvre( $apercu ) as $effet ) {
		$html .= '<li>' . esc_html( $effet ) . '</li>';
	}
	$html .= '</ul>';

	$html        .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_caches_etat( $oeuvre_id );
	$html        .= '<input type="hidden" name="etat" value="' . esc_attr( $etat ) . '"><input type="hidden" name="confirmer" value="1">';
	$restauration = $apercu['restauration'];
	if ( is_array( $restauration ) ) {
		$html .= '<input type="hidden" name="restaurer_present" value="1">';
		if ( $restauration['chapitres'] || $restauration['liens'] ) {
			$html .= '<p class="yn-oeuvre-confirmation__restaurer"><label class="yn-team__case" for="yn-oeuvre-restaurer"><input type="checkbox" id="yn-oeuvre-restaurer" name="restaurer" value="1" checked aria-describedby="yn-oeuvre-restaurer-aide"> ' . esc_html__( 'Remettre en ligne la lecture et les liens retirés à la licence', 'yume-core' ) . '</label>';
			$html .= '<span class="yn-muted" id="yn-oeuvre-restaurer-aide">' . esc_html(
				sprintf(
					/* translators: 1: chapitres, 2: tomes */
					__( '%1$d chapitre(s) republié(s) (ou reprogrammé(s) si leur date est à venir) et liens PDF/EPUB de %2$d tome(s), sans annonce ni notification. Décochez pour les laisser de côté.', 'yume-core' ),
					(int) $restauration['chapitres'],
					(int) $restauration['liens']
				)
			) . '</span></p>';
		} else {
			$html .= '<p class="yn-muted">' . esc_html__( 'Rien n’avait été retiré à la licence : aucune lecture ni aucun lien à remettre en ligne.', 'yume-core' ) . '</p>';
		}
	}
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html(
		/* translators: %s : état */
		sprintf( __( 'Confirmer : passer à « %s »', 'yume-core' ), $etats[ $etat ] )
	) . '</button><a class="yn-btn" href="' . esc_url( url_vue_equipe( 'oeuvres' ) . '#yn-oeuvre-' . $oeuvre_id ) . '">' . esc_html__( 'Annuler', 'yume-core' ) . '</a></p>';
	return $html . '</form></div></section>';
}

/*
 * -----------------------------------------------------------------------------
 * Formulaire (admin-post)
 * -----------------------------------------------------------------------------
 */

/**
 * Traite un formulaire de changement d'état (ligne de la liste ou écran de confirmation). Un
 * changement qui touche les lecteurs sans confirmation renvoie vers l'écran de confirmation
 * (clés changer et vers du retour), sans rien modifier.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,oeuvre_id:int,changer?:int,vers?:string}
 */
function traiter_etat_oeuvre( array $post, int $user_id ): array {
	$id    = isset( $post['oeuvre_id'] ) && is_scalar( $post['oeuvre_id'] ) ? absint( $post['oeuvre_id'] ) : 0;
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$base  = array(
		'cible'     => $id ? 'yn-oeuvre-' . $id : 'yn-oeuvres-retour',
		'oeuvre_id' => $id,
	);
	if ( ! $id || ! wp_verify_nonce( $nonce, 'yume_oeuvre_etat_' . $id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	$valeur    = champ_post( $post, 'etat' );
	$etat      = is_scalar( $valeur ) ? sanitize_title( (string) $valeur ) : '';
	$coche     = champ_post( $post, 'restaurer' );
	$confirmer = champ_post( $post, 'confirmer' );
	$options   = array(
		'confirmer' => '1' === ( is_scalar( $confirmer ) ? (string) $confirmer : '' ),
		'restaurer' => null === champ_post( $post, 'restaurer_present' ) || '1' === ( is_scalar( $coche ) ? (string) $coche : '' ),
	);
	$resultat  = changer_etat_oeuvre( $id, $etat, $user_id, $options );
	if ( is_wp_error( $resultat ) ) {
		if ( 'yume_oeuvre_etat_confirmation' === $resultat->get_error_code() ) {
			return array(
				'cible'     => 'yn-oeuvre-etat',
				'type'      => 'ok',
				'message'   => '',
				'oeuvre_id' => $id,
				'changer'   => $id,
				'vers'      => $etat,
			);
		}
		return $base + array(
			'type'    => 'erreur',
			'message' => $resultat->get_error_message(),
		);
	}
	return $base + array(
		'type'    => 'ok',
		'message' => message_etat_oeuvre( $id, $resultat ),
	);
}

/**
 * Changement d'état d'une œuvre (admin-post.php, action yume_oeuvre_etat).
 */
function admin_post_etat_oeuvre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_etat_oeuvre().
	$retour = traiter_etat_oeuvre( $_POST, get_current_user_id() );
	if ( '' !== $retour['message'] ) {
		retour_formulaire( get_current_user_id(), $retour );
	}
	rediriger_vue_oeuvres( $retour );
}
add_action( 'admin_post_yume_oeuvre_etat', __NAMESPACE__ . '\\admin_post_etat_oeuvre' );
add_action( 'admin_post_nopriv_yume_oeuvre_etat', __NAMESPACE__ . '\\admin_post_anonyme' );
