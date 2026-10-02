<?php
/**
 * Espace équipe, vue « Indicateurs » (?vue=kpi, audit PAGE-08) : sorties par mois (12 derniers
 * mois, graphique en barres CSS et tableau), délai moyen par étape (tiré du journal), charge par
 * membre, retards en cours, lecteurs actifs, favoris et lecteurs par œuvre, e-mails envoyés.
 * Filtre de période : 30 jours, 90 jours ou 12 mois.
 *
 * Capacité yume_reglages (gérants et administrateurs) : yume_maj_planning_tous est aussi donnée
 * aux éditeurs Yume, alors que ces chiffres (charge de chaque membre, audience) relèvent de la
 * gestion du site, comme les réglages.
 *
 * Requêtes agrégées seulement (aucune par œuvre ni par membre) ; résultat mis en cache 5 minutes
 * (transient yume_kpi_{jours}, effacé à chaque mise à jour du planning). Aucune donnée
 * personnelle de lecteur : des comptes, jamais d'identifiant.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Capacité de la vue « Indicateurs ». */
const CAPACITE_KPI = 'yume_reglages';

/** Préfixe du transient des indicateurs (suivi du nombre de jours de la période). */
const TRANSIENT_KPI = 'yume_kpi_';

/** Durée du cache des indicateurs (secondes). */
const DUREE_CACHE_KPI = 5 * MINUTE_IN_SECONDS;

/** Nombre de retards listés (les suivants : lien vers le planning complet filtré). */
const RETARDS_KPI_MAX = 10;

/** Étapes dont le délai est mesuré, dans l'ordre du flux. */
const ETAPES_KPI = array( 'a_faire', 'traduction', 'relecture', 'edition' );

/**
 * Périodes proposées : nombre de jours => libellé.
 *
 * @return array<int,string>
 */
function periodes_kpi(): array {
	return array(
		30  => __( '30 derniers jours', 'yume-core' ),
		90  => __( '90 derniers jours', 'yume-core' ),
		365 => __( '12 derniers mois', 'yume-core' ),
	);
}

/**
 * Période demandée (paramètre GET « periode », 30 par défaut).
 */
function periode_kpi(): int {
	$jours = get_entier( 'periode' );
	return array_key_exists( $jours, periodes_kpi() ) ? $jours : 30;
}

/*
 * -----------------------------------------------------------------------------
 * Calculs (requêtes agrégées)
 * -----------------------------------------------------------------------------
 */

/**
 * Sorties par mois sur les 12 derniers mois (mois courant compris) : tomes et chapitres publiés,
 * par mois de leur date de publication (heure du site). Une requête.
 *
 * @return array<int,array{mois:string,libelle:string,tomes:int,chapitres:int}> Du plus ancien au plus récent.
 */
function sorties_par_mois(): array {
	global $wpdb;
	$fuseau  = wp_timezone();
	$courant = ( new \DateTimeImmutable( '@' . maintenant() ) )->setTimezone( $fuseau )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
	$debut   = $courant->modify( '-11 months' );
	$fin     = $courant->modify( '+1 month' );
	$mois    = array();
	for ( $d = $debut; $d < $fin; $d = $d->modify( '+1 month' ) ) {
		$mois[ $d->format( 'Y-m' ) ] = array(
			'mois'      => $d->format( 'Y-m' ),
			'libelle'   => noms_mois( true )[ (int) $d->format( 'n' ) ] . ' ' . $d->format( 'Y' ),
			'tomes'     => 0,
			'chapitres' => 0,
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_type AS type, SUBSTR(post_date, 1, 7) AS mois, COUNT(*) AS nb FROM {$wpdb->posts}"
			. " WHERE post_type IN (%s, %s) AND post_status = 'publish' AND post_date >= %s AND post_date < %s"
			. ' GROUP BY post_type, SUBSTR(post_date, 1, 7)',
			'yume_tome',
			'yume_chapitre',
			$debut->format( 'Y-m-d H:i:s' ),
			$fin->format( 'Y-m-d H:i:s' )
		)
	);
	foreach ( $lignes as $ligne ) {
		$cle = (string) $ligne->mois;
		if ( isset( $mois[ $cle ] ) ) {
			$mois[ $cle ][ 'yume_tome' === $ligne->type ? 'tomes' : 'chapitres' ] += (int) $ligne->nb;
		}
	}
	return array_values( $mois );
}

/**
 * Délai moyen passé dans chaque étape, d'après le journal : une étape commence à la création du
 * tome ou au changement d'étape précédent, et finit au changement d'étape suivant. Seules les
 * étapes terminées pendant la période comptent. Une requête (lignes « creation » et « etape » des
 * tomes dont l'étape a changé pendant la période).
 *
 * @param string $depuis Début de la période (GMT).
 * @return array<string,array{jours:float,nb:int}> Étape => délai moyen (jours) et nombre de passages.
 */
function delais_par_etape( string $depuis ): array {
	global $wpdb;
	$table  = table_journal();
	$delais = array_fill_keys(
		ETAPES_KPI,
		array(
			'jours' => 0.0,
			'nb'    => 0,
		)
	);
	$sql    = "SELECT tome_id, champ, ancien, created_at FROM {$table} WHERE champ IN ('creation', 'etape')"
		. " AND tome_id IN (SELECT DISTINCT tome_id FROM {$table} WHERE champ = 'etape' AND created_at >= %s AND tome_id > 0)"
		. ' ORDER BY tome_id ASC, created_at ASC, id ASC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( $sql, $depuis ) );
	$sommes = array_fill_keys( ETAPES_KPI, 0 );
	$tome   = 0;
	$debut  = 0;
	foreach ( $lignes as $ligne ) {
		if ( (int) $ligne->tome_id !== $tome ) {
			$tome  = (int) $ligne->tome_id;
			$debut = 0;
		}
		$ts = ts_gmt( (string) $ligne->created_at );
		if ( 'etape' === $ligne->champ ) {
			$etape = (string) $ligne->ancien;
			if ( $debut && isset( $sommes[ $etape ] ) && (string) $ligne->created_at >= $depuis && $ts >= $debut ) {
				$sommes[ $etape ] += $ts - $debut;
				++$delais[ $etape ]['nb'];
			}
		}
		$debut = $ts;
	}
	foreach ( ETAPES_KPI as $etape ) {
		if ( $delais[ $etape ]['nb'] > 0 ) {
			$delais[ $etape ]['jours'] = round( $sommes[ $etape ] / $delais[ $etape ]['nb'] / DAY_IN_SECONDS, 1 );
		}
	}
	return $delais;
}

/**
 * Charge de chaque membre : tâches ouvertes (tomes en cours dont il est responsable de l'étape en
 * cours ou d'une étape à venir) et tâches en retard (étape en cours d'un tome en retard).
 *
 * @param array $lignes Lignes du planning (en cours).
 * @return array<int,array{nom:string,ouvertes:int,retards:int}> Membre => charge, les plus chargés d'abord.
 */
function charge_membres( array $lignes ): array {
	$charge = array();
	foreach ( membres_equipe() as $uid => $nom ) {
		$charge[ $uid ] = array(
			'nom'      => $nom,
			'ouvertes' => 0,
			'retards'  => 0,
		);
	}
	foreach ( $lignes as $l ) {
		if ( 'publie' === $l['etat'] ) {
			continue;
		}
		$courante = etape_de_travail( (string) $l['etape'] );
		$rang     = rang_etape( $courante );
		$vus      = array();
		foreach ( ETAPES_TRAVAIL as $e ) {
			$uid = (int) ( $l['responsables'][ $e ]['id'] ?? 0 );
			if ( ! $uid || ! isset( $charge[ $uid ] ) || rang_etape( $e ) < $rang ) {
				continue;
			}
			if ( ! isset( $vus[ $uid ] ) ) {
				++$charge[ $uid ]['ouvertes'];
				$vus[ $uid ] = true;
			}
			if ( $e === $courante && 'en_retard' === $l['etat'] ) {
				++$charge[ $uid ]['retards'];
			}
		}
	}
	uasort(
		$charge,
		static function ( array $a, array $b ): int {
			$cmp = array( $b['retards'], $b['ouvertes'] ) <=> array( $a['retards'], $a['ouvertes'] );
			return 0 !== $cmp ? $cmp : strnatcasecmp( $a['nom'], $b['nom'] );
		}
	);
	return $charge;
}

/**
 * Retards en cours, les plus anciens d'abord.
 *
 * @param array $lignes Lignes du planning.
 * @return array<int,array{tome_id:int,nom:string,etape:string,motif:string,jours:int}>
 */
function retards_kpi( array $lignes ): array {
	$retards = array();
	foreach ( $lignes as $l ) {
		if ( 'en_retard' !== $l['etat'] ) {
			continue;
		}
		$retards[] = array(
			'tome_id' => (int) $l['tome_id'],
			'nom'     => trim( $l['oeuvre'] . ' ' . $l['tome'] ),
			'etape'   => etape_de_travail( (string) $l['etape'] ),
			'motif'   => (string) $l['motif_retard'],
			'jours'   => (int) $l['jours_retard'],
		);
	}
	usort(
		$retards,
		static function ( array $a, array $b ): int {
			return array( $b['jours'], $a['tome_id'] ) <=> array( $a['jours'], $b['tome_id'] );
		}
	);
	return $retards;
}

/**
 * Lecteurs actifs (comptes dont la progression de lecture a bougé pendant la période), en tout
 * et par œuvre, et favoris par œuvre (en tout et ajoutés pendant la période). Trois requêtes.
 *
 * @param string $depuis Début de la période (GMT).
 * @return array{lecteurs:int|null,oeuvres:array<int,array{titre:string,favoris:int,nouveaux:int,lecteurs:int}>}
 *         lecteurs : null si le module lecteur n'est pas installé.
 */
function audience_kpi( string $depuis ): array {
	global $wpdb;
	$resultat = array(
		'lecteurs' => null,
		'oeuvres'  => array(),
	);
	$ajouter  = static function ( int $oeuvre_id ) use ( &$resultat ): void {
		if ( ! isset( $resultat['oeuvres'][ $oeuvre_id ] ) ) {
			$resultat['oeuvres'][ $oeuvre_id ] = array(
				'titre'    => '',
				'favoris'  => 0,
				'nouveaux' => 0,
				'lecteurs' => 0,
			);
		}
	};
	if ( function_exists( '\\Yume\\Core\\Reader\\table_progression' ) && \Yume\Core\Reader\table_prete() ) {
		$table = \Yume\Core\Reader\table_progression();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$resultat['lecteurs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE updated_at >= %s", $depuis ) );
		// La clé primaire (user_id, oeuvre_id) rend COUNT(*) égal au nombre de lecteurs distincts.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, COUNT(*) AS nb FROM {$table} WHERE updated_at >= %s GROUP BY oeuvre_id", $depuis ) ) as $ligne ) {
			$ajouter( (int) $ligne->oeuvre_id );
			$resultat['oeuvres'][ (int) $ligne->oeuvre_id ]['lecteurs'] = (int) $ligne->nb;
		}
	}
	if ( function_exists( '\\Yume\\Core\\Social\\table_favoris' ) && \Yume\Core\Social\tables_pretes() ) {
		$table = \Yume\Core\Social\table_favoris();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, COUNT(*) AS nb, SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS nouveaux FROM {$table} GROUP BY oeuvre_id", $depuis ) ) as $ligne ) {
			$ajouter( (int) $ligne->oeuvre_id );
			$resultat['oeuvres'][ (int) $ligne->oeuvre_id ]['favoris']  = (int) $ligne->nb;
			$resultat['oeuvres'][ (int) $ligne->oeuvre_id ]['nouveaux'] = (int) $ligne->nouveaux;
		}
	}
	// Titres : œuvres existantes seulement, chargées en une fois.
	$ids = array_keys( $resultat['oeuvres'] );
	if ( $ids ) {
		_prime_post_caches( $ids, false, false );
	}
	foreach ( $ids as $id ) {
		if ( 'yume_oeuvre' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			unset( $resultat['oeuvres'][ $id ] );
			continue;
		}
		$resultat['oeuvres'][ $id ]['titre'] = titre_brut( $id );
	}
	uasort(
		$resultat['oeuvres'],
		static function ( array $a, array $b ): int {
			$cmp = array( $b['favoris'], $b['lecteurs'] ) <=> array( $a['favoris'], $a['lecteurs'] );
			return 0 !== $cmp ? $cmp : strnatcasecmp( $a['titre'], $b['titre'] );
		}
	);
	return $resultat;
}

/**
 * E-mails de la file : envoyés et abandonnés pendant la période (la file ne garde que 30 jours),
 * en attente maintenant. Une requête.
 *
 * @param string $depuis Début de la période (GMT).
 * @return array{envoyes:int,echecs:int,attente:int}
 */
function emails_kpi( string $depuis ): array {
	global $wpdb;
	$table = table_notifications();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM(CASE WHEN statut = 'envoye' AND envoye_le >= %s THEN 1 ELSE 0 END) AS envoyes,"
			. " SUM(CASE WHEN statut = 'echec' AND created_at >= %s THEN 1 ELSE 0 END) AS echecs,"
			. " SUM(CASE WHEN statut IN ('attente', 'envoi') THEN 1 ELSE 0 END) AS attente FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$depuis,
			$depuis
		)
	);
	return array(
		'envoyes' => (int) ( $ligne->envoyes ?? 0 ),
		'echecs'  => (int) ( $ligne->echecs ?? 0 ),
		'attente' => (int) ( $ligne->attente ?? 0 ),
	);
}

/**
 * Tous les indicateurs d'une période, mis en cache 5 minutes.
 *
 * @param int  $jours  Période (30, 90 ou 365 jours).
 * @param bool $forcer Recalculer sans lire le cache.
 * @return array{jours:int,calcule:int,sorties:array,sorties_periode:int,delais:array,charge:array,retards:array,audience:array,emails:array}
 */
function donnees_kpi( int $jours, bool $forcer = false ): array {
	$jours = array_key_exists( $jours, periodes_kpi() ) ? $jours : 30;
	$cle   = TRANSIENT_KPI . $jours;
	if ( ! $forcer ) {
		$cache = get_transient( $cle );
		if ( is_array( $cache ) && isset( $cache['jours'] ) ) {
			return $cache;
		}
	}
	$depuis = gmt( maintenant() - $jours * DAY_IN_SECONDS );
	$lignes = yume_get_planning(
		array(
			'a_venir' => true,
			'public'  => false,
		)
	);
	global $wpdb;
	// Tomes sortis pendant la période (date de publication, heure du site).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$sorties_periode = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date_gmt >= %s", 'yume_tome', $depuis ) );

	$donnees = array(
		'jours'           => $jours,
		'calcule'         => maintenant(),
		'sorties'         => sorties_par_mois(),
		'sorties_periode' => $sorties_periode,
		'delais'          => delais_par_etape( $depuis ),
		'charge'          => charge_membres( $lignes ),
		'retards'         => retards_kpi( $lignes ),
		'audience'        => audience_kpi( $depuis ),
		'emails'          => emails_kpi( $depuis ),
	);
	set_transient( $cle, $donnees, DUREE_CACHE_KPI );
	return $donnees;
}

/**
 * Efface le cache des indicateurs (planning mis à jour, tome mis en pause ou repris, état
 * d'une œuvre changé).
 */
function oublier_kpi(): void {
	foreach ( array_keys( periodes_kpi() ) as $jours ) {
		delete_transient( TRANSIENT_KPI . $jours );
	}
}
add_action( 'yume_planning_mis_a_jour', __NAMESPACE__ . '\\oublier_kpi' );
// Pause et reprise d'un tome : les retards en cours changent.
add_action( 'yume_planning_pause', __NAMESPACE__ . '\\oublier_kpi' );
// Changement d'état d'une œuvre (en pause, terminée…) : ses tomes sortent des retards ou y reviennent.
add_action( 'yume_oeuvre_etat_change', __NAMESPACE__ . '\\oublier_kpi' );

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare la vue dans l'espace équipe (filtre yume_vues_equipe).
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue_kpi( $vues ): array {
	$vues        = is_array( $vues ) ? $vues : array();
	$vues['kpi'] = array(
		'libelle'  => __( 'Indicateurs', 'yume-core' ),
		'capacite' => CAPACITE_KPI,
		'groupe'   => 'site',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_kpi',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue_kpi' );

/**
 * Feuille de style de la vue (chargée seulement quand la vue est rendue).
 */
function enregistrer_style_kpi(): void {
	wp_register_style( 'yume-kpi', YUME_CORE_URL . 'includes/planning/assets/kpi.css', array(), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_kpi' );

/**
 * Nombre de jours lisible (« 3,5 j »).
 *
 * @param float $jours Jours.
 */
function jours_lisibles( float $jours ): string {
	/* translators: %s : nombre de jours (décimal) */
	return sprintf( __( '%s j', 'yume-core' ), number_format_i18n( $jours, $jours < 10 && floor( $jours ) !== $jours ? 1 : 0 ) );
}

/**
 * Tableau enveloppé dans une zone défilante accessible (petits écrans).
 *
 * @param string   $id       Identifiant de la légende.
 * @param string   $legende  Légende (caption).
 * @param string[] $entetes  En-têtes de colonnes (la première : en-tête de ligne).
 * @param array    $lignes   Lignes : cellules déjà échappées (la première devient th scope=row).
 * @param bool     $masquee  Légende visible des lecteurs d'écran seulement.
 */
function tableau_kpi( string $id, string $legende, array $entetes, array $lignes, bool $masquee = false ): string {
	$html  = '<div class="yn-kpi__defile" role="region" tabindex="0" aria-labelledby="' . esc_attr( $id ) . '">';
	$html .= '<table class="yn-kpi__table"><caption id="' . esc_attr( $id ) . '"' . ( $masquee ? ' class="yn-visually-hidden"' : '' ) . '>' . esc_html( $legende ) . '</caption><thead><tr>';
	foreach ( $entetes as $i => $entete ) {
		$html .= '<th scope="col"' . ( $i ? ' class="yn-kpi__nombre"' : '' ) . '>' . esc_html( $entete ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $lignes as $cellules ) {
		$html .= '<tr>';
		foreach ( array_values( $cellules ) as $i => $cellule ) {
			$html .= $i ? '<td class="yn-kpi__nombre">' . $cellule . '</td>' : '<th scope="row">' . $cellule . '</th>';
		}
		$html .= '</tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * Graphique en barres des sorties par mois : image accessible (role="img", résumé en
 * aria-label), valeurs écrites au-dessus des barres ; le tableau qui suit donne le détail.
 *
 * @param array $sorties Sorties par mois (sorties_par_mois()).
 */
function graphique_sorties( array $sorties ): string {
	$max   = max( 1, (int) max( array_merge( array( 0 ), array_column( $sorties, 'tomes' ) ) ) );
	$total = (int) array_sum( array_column( $sorties, 'tomes' ) );
	$pic   = '';
	foreach ( $sorties as $m ) {
		if ( (int) $m['tomes'] === $max && $total > 0 ) {
			$pic = $m['libelle'];
		}
	}
	$resume = $total > 0
		/* translators: 1: nombre de tomes, 2: mois du maximum, 3: maximum */
		? sprintf( _n( 'Sorties des 12 derniers mois : %1$d tome en tout, au plus %3$d en %2$s.', 'Sorties des 12 derniers mois : %1$d tomes en tout, au plus %3$d en %2$s.', $total, 'yume-core' ), $total, $pic, $max )
		: __( 'Sorties des 12 derniers mois : aucun tome sorti.', 'yume-core' );
	$html = '<div class="yn-kpi__graphique" role="img" aria-label="' . esc_attr( $resume ) . '"><ol class="yn-kpi__barres">';
	foreach ( $sorties as $m ) {
		$h     = (int) round( 100 * (int) $m['tomes'] / $max );
		$html .= '<li class="yn-kpi__barre"><span class="yn-kpi__valeur">' . esc_html( number_format_i18n( (int) $m['tomes'] ) ) . '</span>';
		$html .= '<span class="yn-kpi__piste"><span class="yn-kpi__remplie' . ( (int) $m['tomes'] > 0 ? '' : ' yn-kpi__remplie--vide' ) . '" style="--h:' . $h . '%"></span></span>';
		$mois  = explode( ' ', (string) $m['libelle'], 2 );
		$html .= '<span class="yn-kpi__mois">' . esc_html( $mois[0] ) . ( isset( $mois[1] ) ? '<span class="yn-kpi__annee"> ' . esc_html( $mois[1] ) . '</span>' : '' ) . '</span></li>';
	}
	return $html . '</ol></div>';
}

/**
 * Vue « Indicateurs » (?vue=kpi).
 */
function rendu_vue_kpi(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--kpi' );
	$html .= navigation_equipe( 'kpi' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( CAPACITE_KPI ) ) {
		$html .= tete_vue( __( 'Indicateurs', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les gérants et les administrateurs peuvent consulter les indicateurs.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}
	wp_enqueue_style( 'yume-kpi' );

	$jours    = periode_kpi();
	$periodes = periodes_kpi();
	$d        = donnees_kpi( $jours );
	$periode  = mb_strtolower( $periodes[ $jours ] );
	$etapes   = yume_etapes();
	$emails   = $d['emails'];
	$lecteurs = $d['audience']['lecteurs'];

	$html .= tete_vue( __( 'Indicateurs', 'yume-core' ), '' );
	$html .= '<p class="yn-muted">' . esc_html(
		sprintf(
			/* translators: %s : heure du calcul */
			__( 'Chiffres agrégés, calculés à %s et mis à jour toutes les 5 minutes. Aucun lecteur n’y est identifié.', 'yume-core' ),
			format_fr( (int) $d['calcule'], 'H\hi' )
		)
	) . '</p>';

	// Filtre de période.
	$choix = array();
	foreach ( $periodes as $n => $libelle ) {
		$choix[ (string) $n ] = $libelle;
	}
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Choisir la période', 'yume-core' ) . '">' . champs_caches_vue( 'kpi' );
	$html .= champ_select( 'yn-kpi-periode', 'periode', __( 'Période', 'yume-core' ), $choix, (string) $jours );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Afficher', 'yume-core' ) . '</button></p></form>';

	// Chiffres clés.
	$html .= '<div class="yn-team__chiffres">';
	/* translators: %s : période */
	$html .= chiffre_equipe( __( 'Tomes sortis', 'yume-core' ), number_format_i18n( (int) $d['sorties_periode'] ), sprintf( __( 'sur les %s', 'yume-core' ), $periode ) );
	$html .= chiffre_equipe( __( 'Retards en cours', 'yume-core' ), number_format_i18n( count( $d['retards'] ) ), __( 'tomes en retard aujourd’hui', 'yume-core' ), count( $d['retards'] ) > 0 );
	if ( null !== $lecteurs ) {
		/* translators: %s : période */
		$html .= chiffre_equipe( __( 'Lecteurs actifs', 'yume-core' ), number_format_i18n( (int) $lecteurs ), sprintf( __( 'comptes ayant lu en ligne sur les %s', 'yume-core' ), $periode ) );
	}
	$html .= chiffre_equipe(
		__( 'E-mails envoyés', 'yume-core' ),
		number_format_i18n( $emails['envoyes'] ),
		$emails['attente'] || $emails['echecs']
			/* translators: 1: en attente, 2: abandonnés */
			? sprintf( __( '%1$d en attente · %2$d abandonnés', 'yume-core' ), $emails['attente'], $emails['echecs'] )
			: __( 'aucun en attente ni abandonné', 'yume-core' ),
		$emails['echecs'] > 0
	);
	$html .= '</div>';

	// Sorties par mois.
	$html  .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-sorties"><h2 id="yn-kpi-sorties">' . esc_html__( 'Sorties par mois', 'yume-core' ) . '</h2>';
	$html  .= '<p class="yn-muted">' . esc_html__( 'Tomes publiés sur les 12 derniers mois, par mois de publication (toujours sur 12 mois, quelle que soit la période).', 'yume-core' ) . '</p>';
	$html  .= graphique_sorties( $d['sorties'] );
	$lignes = array();
	foreach ( array_reverse( $d['sorties'] ) as $m ) {
		$lignes[] = array( esc_html( (string) $m['libelle'] ), esc_html( number_format_i18n( (int) $m['tomes'] ) ), esc_html( number_format_i18n( (int) $m['chapitres'] ) ) );
	}
	$html .= '<details class="yn-kpi__details"><summary>' . esc_html__( 'Voir le tableau des sorties', 'yume-core' ) . '</summary>';
	$html .= tableau_kpi( 'yn-kpi-t-sorties', __( 'Sorties par mois (du plus récent au plus ancien)', 'yume-core' ), array( __( 'Mois', 'yume-core' ), __( 'Tomes', 'yume-core' ), __( 'Chapitres', 'yume-core' ) ), $lignes ) . '</details></section>';

	$html .= '<div class="yn-kpi__grille">';

	// Délais par étape.
	$max_delai = max( 1.0, (float) max( array_column( $d['delais'], 'jours' ) ) );
	$lignes    = array();
	foreach ( $d['delais'] as $etape => $delai ) {
		$pct      = (int) round( 100 * $delai['jours'] / $max_delai );
		$lignes[] = array(
			esc_html( $etapes[ $etape ] ?? $etape ),
			$delai['nb'] ? '<span class="yn-kpi__jauge"><span class="yn-bar" aria-hidden="true"><span style="--v:' . $pct . '%"></span></span>' . esc_html( jours_lisibles( (float) $delai['jours'] ) ) . '</span>' : '<span class="yn-muted">—</span>',
			esc_html( number_format_i18n( (int) $delai['nb'] ) ),
		);
	}
	$html .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-delais"><h2 id="yn-kpi-delais">' . esc_html__( 'Délai moyen par étape', 'yume-core' ) . '</h2>';
	/* translators: %s : période */
	$html .= '<p class="yn-muted">' . esc_html( sprintf( __( 'Temps passé dans chaque étape, pour les étapes terminées sur les %s (d’après le journal).', 'yume-core' ), $periode ) ) . '</p>';
	$html .= tableau_kpi( 'yn-kpi-t-delais', __( 'Délai moyen par étape', 'yume-core' ), array( __( 'Étape', 'yume-core' ), __( 'Délai moyen', 'yume-core' ), __( 'Tomes', 'yume-core' ) ), $lignes, true ) . '</section>';

	// Charge par membre.
	$lignes = array();
	foreach ( $d['charge'] as $membre ) {
		$lignes[] = array(
			esc_html( (string) $membre['nom'] ),
			esc_html( number_format_i18n( (int) $membre['ouvertes'] ) ),
			$membre['retards'] ? '<span class="yn-chip yn-chip--warn">' . esc_html( number_format_i18n( (int) $membre['retards'] ) ) . '</span>' : '0',
		);
	}
	$html .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-charge"><h2 id="yn-kpi-charge">' . esc_html__( 'Charge par membre', 'yume-core' ) . '</h2>';
	$html .= $lignes
		? tableau_kpi( 'yn-kpi-t-charge', __( 'Tâches ouvertes et en retard de chaque membre', 'yume-core' ), array( __( 'Membre', 'yume-core' ), __( 'Tâches ouvertes', 'yume-core' ), __( 'En retard', 'yume-core' ) ), $lignes, true )
		: '<p class="yn-muted">' . esc_html__( 'Aucun membre dans l’équipe.', 'yume-core' ) . '</p>';
	$html .= '</section></div>';

	// Retards en cours.
	$html .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-retards"><h2 id="yn-kpi-retards">' . esc_html__( 'Retards en cours', 'yume-core' ) . '</h2>';
	if ( ! $d['retards'] ) {
		$html .= '<p class="yn-muted">' . esc_html__( 'Aucun tome en retard.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<ul class="yn-kpi__retards">';
		foreach ( array_slice( $d['retards'], 0, RETARDS_KPI_MAX ) as $r ) {
			$detail = 'date' === $r['motif']
				/* translators: %d : jours */
				? sprintf( _n( 'date cible dépassée de %d jour', 'date cible dépassée de %d jours', $r['jours'], 'yume-core' ), $r['jours'] )
				/* translators: %d : jours */
				: sprintf( _n( 'sans mise à jour depuis %d jour', 'sans mise à jour depuis %d jours', $r['jours'], 'yume-core' ), $r['jours'] );
			$html .= '<li><a href="' . esc_url( url_vue_equipe( 'planning', array( 'tome' => $r['tome_id'] ) ) ) . '">' . esc_html( $r['nom'] ) . '</a>';
			$html .= ' <span class="yn-muted">· ' . esc_html( ( '' !== $r['etape'] ? libelle_etape_min( $r['etape'] ) . ' · ' : '' ) . $detail ) . '</span></li>';
		}
		$html  .= '</ul>';
		$autres = count( $d['retards'] ) - RETARDS_KPI_MAX;
		if ( $autres > 0 ) {
			/* translators: %d : nombre de tomes */
			$html .= '<p><a href="' . esc_url( url_vue_equipe( 'planning', array( 'etat' => 'en_retard' ) ) ) . '">' . esc_html( sprintf( _n( 'Voir %d autre tome en retard dans le planning complet', 'Voir les %d autres tomes en retard dans le planning complet', $autres, 'yume-core' ), $autres ) ) . '</a></p>';
		}
	}
	$html .= '</section>';

	// Favoris et lecteurs par œuvre.
	$lignes = array();
	foreach ( $d['audience']['oeuvres'] as $o ) {
		$lignes[] = array(
			esc_html( (string) $o['titre'] ),
			esc_html( number_format_i18n( (int) $o['favoris'] ) ),
			esc_html( ( $o['nouveaux'] ? '+' : '' ) . number_format_i18n( (int) $o['nouveaux'] ) ),
			esc_html( number_format_i18n( (int) $o['lecteurs'] ) ),
		);
	}
	$html .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-oeuvres"><h2 id="yn-kpi-oeuvres">' . esc_html__( 'Favoris et lecteurs par œuvre', 'yume-core' ) . '</h2>';
	$html .= $lignes
		/* translators: %s : période */
		? tableau_kpi( 'yn-kpi-t-oeuvres', sprintf( __( 'Favoris (en tout et nouveaux) et lecteurs actifs par œuvre sur les %s', 'yume-core' ), $periode ), array( __( 'Œuvre', 'yume-core' ), __( 'Favoris', 'yume-core' ), __( 'Nouveaux', 'yume-core' ), __( 'Lecteurs actifs', 'yume-core' ) ), $lignes, true )
		: '<p class="yn-muted">' . esc_html__( 'Aucun favori ni lecture en ligne pour le moment.', 'yume-core' ) . '</p>';
	$html .= '</section>';

	// E-mails.
	$html .= '<section class="yn-card yn-kpi__carte" aria-labelledby="yn-kpi-emails"><h2 id="yn-kpi-emails">' . esc_html__( 'E-mails', 'yume-core' ) . '</h2><ul class="yn-kpi__liste">';
	/* translators: 1: nombre, 2: période */
	$html .= '<li>' . esc_html( sprintf( _n( '%1$d e-mail envoyé sur les %2$s', '%1$d e-mails envoyés sur les %2$s', $emails['envoyes'], 'yume-core' ), $emails['envoyes'], $periode ) ) . '</li>';
	/* translators: %d : nombre */
	$html .= '<li>' . esc_html( sprintf( _n( '%d e-mail abandonné après 3 tentatives', '%d e-mails abandonnés après 3 tentatives', $emails['echecs'], 'yume-core' ), $emails['echecs'] ) ) . '</li>';
	/* translators: %d : nombre */
	$html .= '<li>' . esc_html( sprintf( _n( '%d e-mail en attente d’envoi', '%d e-mails en attente d’envoi', $emails['attente'], 'yume-core' ), $emails['attente'] ) ) . '</li></ul>';
	$html .= $jours > 30 ? '<p class="yn-muted">' . esc_html__( 'La file d’e-mails ne garde que les 30 derniers jours : au-delà, les envois ne sont plus comptés.', 'yume-core' ) . '</p>' : '';
	$html .= '</section>';

	return $html . '</div></div>';
}
