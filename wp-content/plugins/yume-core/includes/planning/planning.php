<?php
/**
 * Lecture du planning : lignes (yume_get_planning), forme publique des lignes (REST),
 * chiffres clés.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Titre lisible (sans balises ni entités) d'un contenu.
 *
 * @param int $post_id Contenu.
 */
function titre_brut( int $post_id ): string {
	return trim( wp_strip_all_tags( html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Slug du type (yume_type) d'une œuvre, ou chaîne vide.
 *
 * @param int $oeuvre_id Œuvre.
 */
function type_oeuvre( int $oeuvre_id ): string {
	if ( ! $oeuvre_id || ! taxonomy_exists( 'yume_type' ) ) {
		return '';
	}
	$termes = get_the_terms( $oeuvre_id, 'yume_type' );
	return is_array( $termes ) && $termes ? (string) $termes[0]->slug : '';
}

/**
 * La traduction de l'œuvre est-elle arrêtée (série abandonnée ou licenciée) ? Ses tomes
 * encore à paraître n'ont pas leur place dans le planning public.
 *
 * @param int $oeuvre_id Œuvre.
 */
function oeuvre_arretee( int $oeuvre_id ): bool {
	if ( ! $oeuvre_id || ! taxonomy_exists( 'yume_statut' ) ) {
		return false;
	}
	/**
	 * Statuts d'œuvre (slugs yume_statut) dont les tomes à paraître sont masqués du planning
	 * public (ils restent dans l'espace équipe).
	 *
	 * @param string[] $statuts Défaut : abandonnee, licenciee.
	 */
	$arretes = (array) apply_filters( 'yume_planning_statuts_arretes', array( 'abandonnee', 'licenciee' ) );
	$termes  = get_the_terms( $oeuvre_id, 'yume_statut' );
	if ( ! is_array( $termes ) || ! $termes ) {
		return false;
	}
	foreach ( $termes as $terme ) {
		if ( in_array( $terme->slug, $arretes, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Un tome d'une œuvre en pause, terminée, abandonnée ou licenciée n'est jamais « en retard »
 * (yume_planning_etat), comme un tome en pause (etat_hors_pause()) : il sort des retards du
 * tableau de bord, de « Mes tâches », des indicateurs, du planning, des rappels et du
 * récapitulatif. Bloqué et publié restent inchangés.
 *
 * @param string $etat    État calculé.
 * @param int    $tome_id Tome.
 */
function etat_hors_oeuvre_sans_rappels( $etat, $tome_id = 0 ) {
	return 'en_retard' === $etat && oeuvre_sans_rappels( (int) yume_get_oeuvre_id( (int) $tome_id ) ) ? 'a_lheure' : $etat;
}
add_filter( 'yume_planning_etat', __NAMESPACE__ . '\\etat_hors_oeuvre_sans_rappels', 5, 2 );

/**
 * Ligne complète (usage interne et équipe) du planning d'un tome.
 *
 * @param int $tome_id Tome.
 * @return array<string,mixed>
 */
function ligne_tome( int $tome_id ): array {
	$post      = get_post( $tome_id );
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	$d         = donnees_tome( $tome_id );
	$analyse   = analyser_etat( $tome_id );
	/** Ce filtre est documenté dans yume_planning_etat(). */
	$etat = (string) apply_filters( 'yume_planning_etat', $analyse['etat'], $tome_id );
	$etat = array_key_exists( $etat, etats() ) ? $etat : 'a_lheure';

	$responsables = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$uid                    = $d['responsables'][ $etape ];
		$responsables[ $etape ] = array(
			'id'  => $uid,
			'nom' => $uid ? nom_utilisateur( $uid ) : '',
		);
	}

	$statut      = $post ? (string) $post->post_status : '';
	$date_sortie = '';
	if ( 'publie' === $d['etape'] ) {
		$date_sortie = '' !== $d['derniere_maj'] ? $d['derniere_maj'] : ( $post && ts_contenu( $post, 'post_date' ) ? gmt( ts_contenu( $post, 'post_date' ) ) : '' );
	}
	$numero = get_post_meta( $tome_id, 'yume_numero', true );
	// Sortie programmée : la date cible affichée est le jour de sortie programmé (SCAN-05).
	$programmee = 'programme' === $analyse['motif'] ? (string) ( $analyse['date'] ?? '' ) : '';
	// Heure de sortie : celle de la sortie programmée, sinon celle saisie par l'équipe.
	$heure = '' !== $programmee && $post ? substr( (string) $post->post_date, 11, 5 ) : $d['heure_cible'];

	return array(
		'tome_id'         => $tome_id,
		'oeuvre_id'       => $oeuvre_id,
		'oeuvre'          => $oeuvre_id ? titre_brut( $oeuvre_id ) : '',
		'tome'            => yume_libelle_tome( $tome_id ),
		'etape'           => $d['etape'],
		'avancement'      => $d['avancement'],
		'responsables'    => $responsables,
		'date_cible'      => '' !== $programmee ? $programmee : $d['date_cible'],
		'heure_cible'     => valider_heure( $heure ) ? $heure : '',
		'etat'            => $etat,
		'derniere_maj'    => $d['derniere_maj'],
		'url_oeuvre'      => $oeuvre_id && 'publish' === get_post_status( $oeuvre_id ) ? (string) get_permalink( $oeuvre_id ) : '',
		'titre'           => sous_titre_tome( $tome_id ),
		'nature'          => (string) get_post_meta( $tome_id, 'yume_nature', true ),
		'numero'          => is_numeric( $numero ) ? (float) $numero : null,
		'type'            => type_oeuvre( $oeuvre_id ),
		'statut'          => $statut,
		'url'             => 'publish' === $statut ? (string) get_permalink( $tome_id ) : '',
		'bloque'          => $d['bloque'],
		'bloque_raison'   => $d['bloque'] ? $d['bloque_raison'] : '',
		'motif_retard'    => 'en_retard' === $etat ? $analyse['motif'] : '',
		'jours_retard'    => 'en_retard' === $etat ? $analyse['jours'] : 0,
		'chapitres'       => compte_chapitres( $tome_id ),
		'date_sortie'     => $date_sortie,
		'maj_par'         => array(
			'id'  => $d['maj_par'],
			'nom' => $d['maj_par'] ? nom_utilisateur( $d['maj_par'] ) : '',
		),
		'ts_activite'     => ts_derniere_activite( $tome_id ),
		'programme'       => '' !== $programmee,
		'date_programmee' => $programmee,
		'titre_cache'     => $oeuvre_id && yume_oeuvre_a_venir( $oeuvre_id ),
	);
}

/**
 * Libellé de l'état d'une ligne : « Programmé le sam. 3 oct. » pour une sortie programmée,
 * sinon le libellé de l'état (« À l'heure », « En retard »…).
 *
 * @param array $ligne Ligne (etat, programme, date_programmee).
 */
function libelle_etat_ligne( array $ligne ): string {
	if ( ! empty( $ligne['programme'] ) && 'publie' !== ( $ligne['etat'] ?? '' ) ) {
		return libelle_programme( (string) ( $ligne['date_programmee'] ?? '' ) );
	}
	return (string) ( etats()[ $ligne['etat'] ?? '' ] ?? '' );
}

/**
 * IDs des tomes candidats au planning : tous les tomes vivants dont l'étape n'est pas
 * « publié », plus les tomes publiés récemment et ceux encore en cours de publication.
 *
 * @param string   $depuis  Date GMT : tomes publiés mis à jour (ou datés) après cette date.
 * @param string[] $statuts Statuts WordPress.
 * @return int[]
 */
function ids_candidats( string $depuis, array $statuts ): array {
	global $wpdb;
	$marques = implode( ', ', array_fill( 0, count( $statuts ), '%s' ) );
	$sql     = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p"
		. " LEFT JOIN {$wpdb->postmeta} e ON ( e.post_id = p.ID AND e.meta_key = 'yume_etape' )"
		. " LEFT JOIN {$wpdb->postmeta} d ON ( d.post_id = p.ID AND d.meta_key = 'yume_derniere_maj' )"
		. " LEFT JOIN {$wpdb->postmeta} r ON ( r.post_id = p.ID AND r.meta_key = 'yume_parution' )"
		. " WHERE p.post_type = 'yume_tome' AND p.post_status IN ($marques)"
		. " AND ( e.meta_value IS NULL OR e.meta_value <> 'publie' OR d.meta_value >= %s OR p.post_date_gmt >= %s OR r.meta_value = 'en_cours' )"
		. ' ORDER BY p.ID ASC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $statuts, array( $depuis, $depuis ) ) ) );
	return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
}

/**
 * Lignes du planning (voir yume_get_planning()).
 *
 * @param array $args Arguments.
 * @return array<int,array<string,mixed>>
 */
function lignes_planning( array $args = array() ): array {
	$args = wp_parse_args(
		$args,
		array(
			'oeuvre_id'              => 0,
			'type'                   => '',
			'etat'                   => '',
			'a_venir'                => false,
			'limit'                  => 0,
			'inclure_publies_depuis' => 14,
			'publies_du_jour'        => false,
			'responsable'            => 0,
			'public'                 => true,
			'gestion'                => false,
			'statut'                 => '',
		)
	);
	if ( $args['gestion'] ) {
		return lignes_gestion( $args );
	}

	$oeuvre_id   = max( 0, (int) $args['oeuvre_id'] );
	$type        = sanitize_key( (string) $args['type'] );
	$etat_voulu  = sanitize_key( (string) $args['etat'] );
	$a_venir     = (bool) $args['a_venir'];
	$limite      = max( 0, (int) $args['limit'] );
	$jours_pub   = max( 0, (int) $args['inclure_publies_depuis'] );
	$responsable = max( 0, (int) $args['responsable'] );
	$public      = (bool) $args['public'];
	$du_jour     = (bool) $args['publies_du_jour'];

	// Publiés du jour : depuis minuit (fuseau du planning), quel que soit inclure_publies_depuis.
	if ( $du_jour && ! $a_venir ) {
		$seuil_pub = ts_date( date_locale() );
	} else {
		$seuil_pub = $jours_pub > 0 && ! $a_venir ? maintenant() - $jours_pub * DAY_IN_SECONDS : 0;
	}
	$depuis  = $seuil_pub ? gmt( $seuil_pub ) : '9999-12-31 23:59:59';
	$statuts = $public ? array( 'publish', 'future', 'draft', 'pending' ) : array( 'publish', 'future', 'draft', 'pending', 'private' );

	$ids = ids_candidats( $depuis, $statuts );
	if ( ! $ids ) {
		return array();
	}
	amorcer_caches_planning( $ids );
	try {
		$lignes = lignes_depuis_ids( $ids, $oeuvre_id, $type, $etat_voulu, $a_venir, $seuil_pub, $responsable, $public, $du_jour );
	} finally {
		oublier_comptes_chapitres();
	}

	usort( $lignes, __NAMESPACE__ . '\\comparer_lignes' );
	if ( $limite > 0 ) {
		$lignes = array_slice( $lignes, 0, $limite );
	}
	/**
	 * Filtre les lignes du planning.
	 *
	 * @param array $lignes Lignes.
	 * @param array $args   Arguments de yume_get_planning().
	 */
	return (array) apply_filters( 'yume_planning_lignes', $lignes, $args );
}

/**
 * Vue de gestion (yume_get_planning( array( 'gestion' => true ) )) : tous les tomes vivants
 * (brouillon, programmé, en attente, publié, privé) de toutes les œuvres, quels que soient
 * leur étape et leur âge. Filtres : oeuvre_id, statut (statut WordPress), etat, type,
 * responsable, limit.
 *
 * @param array $args Arguments (déjà complétés par lignes_planning()).
 * @return array<int,array<string,mixed>>
 */
function lignes_gestion( array $args ): array {
	global $wpdb;
	$statuts = array( 'draft', 'future', 'pending', 'publish', 'private' );
	$statut  = sanitize_key( (string) $args['statut'] );
	if ( '' !== $statut ) {
		if ( ! in_array( $statut, $statuts, true ) ) {
			return array();
		}
		$statuts = array( $statut );
	}
	$marques = implode( ', ', array_fill( 0, count( $statuts ), '%s' ) );
	$sql     = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'yume_tome' AND post_status IN ($marques) ORDER BY ID ASC";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, $statuts ) );
	$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	$oeuvre_id   = max( 0, (int) $args['oeuvre_id'] );
	$type        = sanitize_key( (string) $args['type'] );
	$etat_voulu  = sanitize_key( (string) $args['etat'] );
	$responsable = max( 0, (int) $args['responsable'] );
	amorcer_caches_planning( $ids );
	$lignes = array();
	try {
		foreach ( $ids as $id ) {
			$o = yume_get_oeuvre_id( $id );
			if ( ( $oeuvre_id && $o !== $oeuvre_id ) || ( '' !== $type && type_oeuvre( $o ) !== $type ) ) {
				continue;
			}
			if ( $responsable && ! in_array( $responsable, norm_responsables( get_post_meta( $id, 'yume_responsables', true ) ), true ) ) {
				continue;
			}
			$ligne = ligne_tome( $id );
			if ( '' !== $etat_voulu && $ligne['etat'] !== $etat_voulu ) {
				continue;
			}
			$lignes[] = $ligne;
		}
	} finally {
		oublier_comptes_chapitres();
	}
	usort( $lignes, __NAMESPACE__ . '\\comparer_lignes' );
	$limite = max( 0, (int) $args['limit'] );
	if ( $limite > 0 ) {
		$lignes = array_slice( $lignes, 0, $limite );
	}
	/** Ce filtre est documenté dans lignes_planning(). */
	return (array) apply_filters( 'yume_planning_lignes', $lignes, $args );
}

/**
 * Amorce en quelques requêtes ce que ligne_tome() lit pour chaque tome : tomes et leurs
 * méta, œuvres et leurs termes (type, statut), responsables et auteurs de mise à jour,
 * nombre de chapitres (constat RC-6 : plus de requête par ligne).
 *
 * @param int[] $ids Tomes.
 */
function amorcer_caches_planning( array $ids ): void {
	_prime_post_caches( $ids, false, true );
	$oeuvres = array();
	$users   = array();
	foreach ( $ids as $id ) {
		$o = (int) get_post_meta( $id, 'yume_oeuvre_id', true );
		if ( $o > 0 ) {
			$oeuvres[ $o ] = $o;
		}
		foreach ( norm_responsables( get_post_meta( $id, 'yume_responsables', true ) ) as $uid ) {
			if ( $uid > 0 ) {
				$users[ $uid ] = $uid;
			}
		}
		$maj = (int) get_post_meta( $id, 'yume_maj_par', true );
		if ( $maj > 0 ) {
			$users[ $maj ] = $maj;
		}
	}
	if ( $oeuvres ) {
		_prime_post_caches( array_values( $oeuvres ), true, false );
	}
	if ( $users ) {
		cache_users( array_values( $users ) );
	}
	amorcer_comptes_chapitres( $ids );
}

/**
 * Lignes retenues parmi les tomes candidats (voir lignes_planning()).
 *
 * @param int[]  $ids         Tomes candidats.
 * @param int    $oeuvre_id   Œuvre (0 : toutes).
 * @param string $type        Type d'œuvre ('' : tous).
 * @param string $etat_voulu  État ('' : tous).
 * @param bool   $a_venir     Seulement les tomes à paraître.
 * @param int    $seuil_pub    Horodatage : publiés récents depuis (0 : aucun).
 * @param int    $responsable  Membre responsable (0 : tous).
 * @param bool   $vue_publique Vue publique.
 * @param bool   $du_jour      Publiés du jour seulement ($seuil_pub = minuit) ; un tome publié
 *                             mais encore en cours de publication (chapitre par chapitre) reste.
 * @return array<int,array<string,mixed>>
 */
function lignes_depuis_ids( array $ids, int $oeuvre_id, string $type, string $etat_voulu, bool $a_venir, int $seuil_pub, int $responsable, bool $vue_publique, bool $du_jour = false ): array {
	$lignes = array();
	foreach ( $ids as $id ) {
		$o = yume_get_oeuvre_id( $id );
		if ( $oeuvre_id && $o !== $oeuvre_id ) {
			continue;
		}
		// Vue publique : œuvres publiées, et séries à venir (titre caché) ; jamais un autre brouillon.
		if ( $vue_publique && ( ! $o || ( 'publish' !== get_post_status( $o ) && ! yume_oeuvre_a_venir( $o ) ) ) ) {
			continue;
		}
		if ( '' !== $type && type_oeuvre( $o ) !== $type ) {
			continue;
		}
		if ( $responsable ) {
			$resp = norm_responsables( get_post_meta( $id, 'yume_responsables', true ) );
			if ( ! in_array( $responsable, $resp, true ) ) {
				continue;
			}
		}
		$ligne = ligne_tome( $id );
		if ( $vue_publique && 'publie' !== $ligne['etat'] && oeuvre_arretee( $o ) ) {
			continue;
		}
		if ( 'publie' === $ligne['etat'] && ! ( $du_jour && ! $a_venir && 'en_cours' === yume_parution_tome( $id ) ) ) {
			if ( $a_venir || ! $seuil_pub || ts_gmt( $ligne['date_sortie'] ) < $seuil_pub ) {
				continue;
			}
		}
		if ( '' !== $etat_voulu && $ligne['etat'] !== $etat_voulu ) {
			continue;
		}
		$lignes[] = $vue_publique ? ligne_titre_public( $ligne ) : $ligne;
	}
	return $lignes;
}

/**
 * Ligne telle que le public peut la lire : pour une série à venir (titre_cache), l'œuvre prend
 * son nom public (yume_titre_public_oeuvre()), le tome garde son libellé (« Tome 1 ») mais
 * perd son sous-titre, et aucune adresse ne mène à l'œuvre ni au tome.
 *
 * @param array $ligne Ligne (ligne_tome()).
 * @return array<string,mixed>
 */
function ligne_titre_public( array $ligne ): array {
	if ( empty( $ligne['titre_cache'] ) ) {
		return $ligne;
	}
	$ligne['oeuvre']     = yume_titre_public_oeuvre( (int) $ligne['oeuvre_id'] );
	$ligne['titre']      = '';
	$ligne['url_oeuvre'] = '';
	$ligne['url']        = '';
	return $ligne;
}

/**
 * Ordre du planning : en cours par date cible (sans date en dernier), puis publiés récents.
 *
 * @param array $a Ligne.
 * @param array $b Ligne.
 */
function comparer_lignes( array $a, array $b ): int {
	$pa = 'publie' === $a['etat'];
	$pb = 'publie' === $b['etat'];
	if ( $pa !== $pb ) {
		return $pa ? 1 : -1;
	}
	if ( $pa ) {
		$cmp = strcmp( (string) $b['date_sortie'], (string) $a['date_sortie'] );
		return 0 !== $cmp ? $cmp : ( $b['tome_id'] <=> $a['tome_id'] );
	}
	$da = (string) $a['date_cible'];
	$db = (string) $b['date_cible'];
	if ( $da !== $db ) {
		if ( '' === $da ) {
			return 1;
		}
		if ( '' === $db ) {
			return -1;
		}
		return strcmp( $da, $db );
	}
	$cmp = strcasecmp( (string) $a['oeuvre'], (string) $b['oeuvre'] );
	if ( 0 !== $cmp ) {
		return $cmp;
	}
	$cmp = ( $a['numero'] ?? 0 ) <=> ( $b['numero'] ?? 0 );
	return 0 !== $cmp ? $cmp : ( $a['tome_id'] <=> $b['tome_id'] );
}

/**
 * Date-heure GMT « Y-m-d H:i:s » → ISO 8601 (UTC), ou chaîne vide.
 *
 * @param string $gmt Date GMT.
 */
function iso( string $gmt ): string {
	$ts = ts_gmt( $gmt );
	return $ts ? gmdate( 'c', $ts ) : '';
}

/**
 * Forme publique d'une ligne (REST GET /planning) : ni ID d'utilisateur, ni note d'équipe,
 * ni champ interne.
 *
 * @param array $ligne Ligne complète.
 * @return array<string,mixed>
 */
function ligne_publique( array $ligne ): array {
	$responsables = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$responsables[ $etape ] = array( 'nom' => (string) ( $ligne['responsables'][ $etape ]['nom'] ?? '' ) );
	}
	$etapes = yume_etapes();
	return array(
		'tome_id'         => (int) $ligne['tome_id'],
		'oeuvre_id'       => (int) $ligne['oeuvre_id'],
		'oeuvre'          => (string) $ligne['oeuvre'],
		'tome'            => (string) $ligne['tome'],
		'titre'           => (string) $ligne['titre'],
		'nature'          => (string) $ligne['nature'],
		'numero'          => $ligne['numero'],
		'type'            => (string) $ligne['type'],
		'etape'           => (string) $ligne['etape'],
		'etape_libelle'   => (string) ( $etapes[ $ligne['etape'] ] ?? '' ),
		'avancement'      => $ligne['avancement'],
		'responsables'    => $responsables,
		'date_cible'      => (string) $ligne['date_cible'],
		'heure_cible'     => (string) ( $ligne['heure_cible'] ?? '' ),
		'etat'            => (string) $ligne['etat'],
		'etat_libelle'    => libelle_etat_ligne( $ligne ),
		'bloque_raison'   => (string) $ligne['bloque_raison'],
		'motif_retard'    => (string) $ligne['motif_retard'],
		'chapitres'       => $ligne['chapitres'],
		'derniere_maj'    => iso( (string) $ligne['derniere_maj'] ),
		'date_sortie'     => iso( (string) $ligne['date_sortie'] ),
		'url_oeuvre'      => (string) $ligne['url_oeuvre'],
		'url'             => (string) $ligne['url'],
		'programme'       => ! empty( $ligne['programme'] ),
		'date_programmee' => (string) ( $ligne['date_programmee'] ?? '' ),
	);
}

/**
 * Forme « équipe » d'une ligne (réponses REST des écritures) : ligne complète + note d'équipe
 * si l'utilisateur courant peut la lire.
 *
 * @param int $tome_id Tome.
 * @return array<string,mixed>
 */
function ligne_equipe( int $tome_id ): array {
	$ligne = ligne_tome( $tome_id );
	unset( $ligne['ts_activite'] );
	$ligne['derniere_maj'] = iso( (string) $ligne['derniere_maj'] );
	$ligne['date_sortie']  = iso( (string) $ligne['date_sortie'] );
	$ligne['etat_libelle'] = libelle_etat_ligne( $ligne );
	if ( current_user_can( 'yume_maj_planning' ) ) {
		$ligne['note_equipe'] = (string) get_post_meta( $tome_id, 'yume_note_equipe', true );
	}
	return $ligne;
}

/**
 * Libellé d'avancement d'un tome pour les annonces (« Arc 7, chapitre 9 » pour un arc dont
 * les chapitres sortent un à un, sinon le libellé du tome).
 *
 * @param array $ligne Ligne.
 */
function libelle_prochaine_sortie( array $ligne ): string {
	$chap = $ligne['chapitres'] ?? array(
		'publies' => 0,
		'total'   => 0,
	);
	if ( 'publish' === ( $ligne['statut'] ?? '' ) && $chap['total'] > $chap['publies'] ) {
		return sprintf(
			/* translators: 1: libellé du tome, 2: numéro du prochain chapitre */
			__( '%1$s, chapitre %2$d', 'yume-core' ),
			$ligne['tome'],
			$chap['publies'] + 1
		);
	}
	return (string) $ligne['tome'];
}

/**
 * Chiffres clés d'un ensemble de lignes (bloc planning, espace équipe).
 *
 * @param array $lignes Lignes.
 * @return array{prochaines:array,en_cours:int,oeuvres:int,membres:int,retards:int,bloques:int,rappel_recent:bool}
 */
function statistiques( array $lignes ): array {
	$aujourdhui = date_locale();
	$prochaines = array();
	$oeuvres    = array();
	$membres    = array();
	$en_cours   = 0;
	$retards    = 0;
	$bloques    = 0;
	$ids_retard = array();
	foreach ( $lignes as $l ) {
		if ( 'publie' === $l['etat'] ) {
			continue;
		}
		++$en_cours;
		$oeuvres[ $l['oeuvre_id'] ] = true;
		foreach ( $l['responsables'] as $r ) {
			if ( ! empty( $r['id'] ) ) {
				$membres[ (int) $r['id'] ] = true;
			}
		}
		if ( 'en_retard' === $l['etat'] ) {
			++$retards;
			$ids_retard[] = (int) $l['tome_id'];
		} elseif ( 'bloque' === $l['etat'] ) {
			++$bloques;
		}
		if ( 'bloque' !== $l['etat'] && '' !== $l['date_cible'] && $l['date_cible'] >= $aujourdhui ) {
			$prochaines[] = $l;
		}
	}
	usort(
		$prochaines,
		static function ( array $a, array $b ): int {
			$cmp = strcmp( $a['date_cible'], $b['date_cible'] );
			if ( 0 === $cmp ) {
				// Même jour : par heure de sortie (sans heure en dernier).
				$cmp = strcmp( ( $a['heure_cible'] ?? '' ) . '~', ( $b['heure_cible'] ?? '' ) . '~' );
			}
			return 0 !== $cmp ? $cmp : ( $a['tome_id'] <=> $b['tome_id'] );
		}
	);
	$rappel_recent = false;
	if ( $ids_retard ) {
		$rappel_recent = (bool) lire_journal(
			array(
				'tome_id' => $ids_retard,
				'champs'  => array( 'rappel' ),
				'depuis'  => gmt( maintenant() - 7 * DAY_IN_SECONDS ),
				'limit'   => 1,
			)
		);
	}
	return array(
		'prochaines'    => array_slice( $prochaines, 0, 2 ),
		'en_cours'      => $en_cours,
		'oeuvres'       => count( $oeuvres ),
		'membres'       => count( $membres ),
		'retards'       => $retards,
		'bloques'       => $bloques,
		'rappel_recent' => $rappel_recent,
	);
}
