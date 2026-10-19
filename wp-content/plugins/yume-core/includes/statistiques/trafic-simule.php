<?php
/**
 * Statistiques, trafic simulé : données de démonstration HORS production, quand Jetpack n'est pas
 * connecté (source_trafic() = « simule » : environnement local, Playground, préproduction).
 *
 * La simulation répond au filtre yume_statistiques_requete_stats avec des charges utiles au format
 * brut de l'API WordPress.com Stats (mêmes formes que celles décrites dans trafic.php) : les
 * normaliseurs de trafic.php sont donc exercés comme en production, et toutes les données qui en
 * sortent portent source = « simule » (l'interface les signale comme une démonstration).
 *
 * Déterministe : chaque valeur dépend seulement de sa graine (date, contenu, nombre de jours),
 * jamais du hasard ni de l'heure ; les contenus sont les œuvres, tomes, chapitres et pages publiés
 * du site local (un contenu n'a pas de vues avant sa date de publication). Aucune requête réseau,
 * aucune donnée personnelle, jamais de ville (pays seulement).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Statistiques;

defined( 'ABSPATH' ) || exit;

/** Types de contenu simulés => poids relatif des vues. */
const SIMULE_POIDS = array(
	'yume_oeuvre'   => 3.0,
	'yume_tome'     => 1.6,
	'yume_chapitre' => 1.0,
	'page'          => 0.3,
);

/** Contenus simulés au plus. */
const SIMULE_CONTENUS_MAX = 300;

/** Jours d'historique de post/{id}. */
const SIMULE_JOURS_POST = 400;

/** Référents simulés : nom => [ adresse, part des vues, sous-domaines (résultats du groupe) ]. */
const SIMULE_REFERENTS = array(
	'Google'        => array( 'https://www.google.com/', 0.42, array( 'google.com', 'google.fr' ) ),
	'Discord'       => array( 'https://discord.com/', 0.18, array() ),
	'MangaDex'      => array( 'https://mangadex.org/', 0.09, array() ),
	'Bing'          => array( 'https://www.bing.com/', 0.05, array() ),
	'X (Twitter)'   => array( 'https://x.com/', 0.04, array() ),
	'Reddit'        => array( 'https://www.reddit.com/', 0.03, array() ),
	'Novel Updates' => array( 'https://www.novelupdates.com/', 0.03, array() ),
	'DuckDuckGo'    => array( 'https://duckduckgo.com/', 0.02, array() ),
);

/** Pays simulés : code => [ nom (anglais, comme l'API), part des vues ]. ZZ : pays inconnu. */
const SIMULE_PAYS = array(
	'FR' => array( 'France', 0.62 ),
	'BE' => array( 'Belgium', 0.08 ),
	'CA' => array( 'Canada', 0.07 ),
	'CH' => array( 'Switzerland', 0.05 ),
	'MA' => array( 'Morocco', 0.04 ),
	'US' => array( 'United States', 0.03 ),
	'DZ' => array( 'Algeria', 0.03 ),
	'TN' => array( 'Tunisia', 0.02 ),
	'SN' => array( 'Senegal', 0.01 ),
	'CI' => array( 'Ivory Coast', 0.01 ),
	'LU' => array( 'Luxembourg', 0.01 ),
	'ZZ' => array( 'Unknown', 0.01 ),
);

/**
 * Réponse simulée au filtre yume_statistiques_requete_stats (source « simule » seulement, et
 * jamais en production).
 *
 * @param null|array|\WP_Error $pre       Réponse déjà fournie (laissée telle quelle).
 * @param string               $ressource Ressource de l'API.
 * @param array                $args      Arguments de l'API.
 * @return null|array|\WP_Error
 */
function simule_requete_stats( $pre, $ressource, $args ) {
	if ( null !== $pre || 'production' === wp_get_environment_type() || 'simule' !== source_trafic() ) {
		return $pre;
	}
	return simule_reponse( (string) $ressource, is_array( $args ) ? $args : array() );
}
add_filter( 'yume_statistiques_requete_stats', __NAMESPACE__ . '\\simule_requete_stats', 10, 3 );

/**
 * Charge utile simulée d'une ressource.
 *
 * @param string $ressource Ressource de l'API.
 * @param array  $args      Arguments de l'API.
 * @return array|\WP_Error
 */
function simule_reponse( string $ressource, array $args ) {
	if ( preg_match( '#^post/(\d+)$#', $ressource, $m ) ) {
		return simule_post( (int) $m[1], $args );
	}
	switch ( $ressource ) {
		case 'visits':
			return simule_visites( $args );
		case 'top-posts':
			return simule_top_posts( $args );
		case 'referrers':
			return simule_referents( $args );
		case 'search-terms':
			return simule_recherches( $args );
		case 'country-views':
			return simule_pays( $args );
	}
	return new \WP_Error( 'yume_trafic_ressource_inconnue', __( 'Ressource de statistiques inconnue.', 'yume-core' ) );
}

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Nombre pseudo-aléatoire déterministe dans [0, 1] tiré d'une graine.
 *
 * @param string $graine Graine.
 */
function simule_alea( string $graine ): float {
	return hexdec( substr( md5( 'yume-stats|' . $graine ), 0, 8 ) ) / 0xFFFFFFFF;
}

/**
 * Jour de fin des arguments (« date »), sinon aujourd'hui (heure du site).
 *
 * @param array $args Arguments de l'API.
 */
function simule_fin( array $args ): string {
	$date = isset( $args['date'] ) && is_string( $args['date'] ) ? $args['date'] : '';
	return trafic_jour_valide( $date ) ? $date : (string) wp_date( 'Y-m-d', maintenant() );
}

/**
 * Entier borné d'un argument.
 *
 * @param array  $args   Arguments.
 * @param string $cle    Clé.
 * @param int    $defaut Valeur par défaut.
 * @param int    $max    Plafond.
 */
function simule_arg( array $args, string $cle, int $defaut, int $max ): int {
	$valeur = isset( $args[ $cle ] ) && is_numeric( $args[ $cle ] ) ? (int) $args[ $cle ] : $defaut;
	return min( $max, max( 1, $valeur ) );
}

/**
 * Vues et visiteurs simulés du site pour un jour : rythme de la semaine (plus de lecture le week-end),
 * lente vague saisonnière et bruit déterministe.
 *
 * @param string $jour Jour « Y-m-d ».
 * @return array{vues:int,visiteurs:int}
 */
function simule_jour( string $jour ): array {
	$ts        = (int) strtotime( $jour . ' 12:00:00 UTC' );
	$semaine   = array( 1.25, 0.9, 0.85, 0.9, 0.95, 1.05, 1.3 ); // Dimanche → samedi.
	$rythme    = $semaine[ (int) gmdate( 'w', $ts ) ];
	$vague     = 1 + 0.18 * sin( floor( $ts / DAY_IN_SECONDS ) / 58 );
	$vues      = (int) round( 420 * $rythme * $vague * ( 0.75 + 0.5 * simule_alea( 'jour|' . $jour ) ) );
	$visiteurs = (int) round( $vues * ( 0.28 + 0.1 * simule_alea( 'visiteurs|' . $jour ) ) );
	return array(
		'vues'      => $vues,
		'visiteurs' => $visiteurs,
	);
}

/**
 * Vues simulées du site sur $num jours se terminant le jour $fin.
 *
 * @param string $fin Dernier jour.
 * @param int    $num Nombre de jours.
 */
function simule_vues_periode( string $fin, int $num ): int {
	$debut = gmdate( 'Y-m-d', (int) strtotime( $fin . ' 12:00:00 UTC' ) - ( $num - 1 ) * DAY_IN_SECONDS );
	$vues  = 0;
	foreach ( trafic_jours( $debut, $fin ) as $jour ) {
		$vues += simule_jour( $jour )['vues'];
	}
	return $vues;
}

/**
 * Contenus publiés du site local (œuvres, tomes, chapitres, pages) : ID => [ type, titre, date ].
 * Une requête, mémorisée tant qu'aucun contenu n'est modifié.
 *
 * @return array<int,array{type:string,titre:string,date:string}>
 */
function simule_contenus(): array {
	static $memo = array();
	$version     = (string) wp_cache_get_last_changed( 'posts' );
	if ( isset( $memo[ $version ] ) ) {
		return $memo[ $version ];
	}
	$posts    = get_posts(
		array(
			'post_type'              => array_keys( SIMULE_POIDS ),
			'post_status'            => 'publish',
			'numberposts'            => SIMULE_CONTENUS_MAX,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$contenus = array();
	foreach ( $posts as $post ) {
		$contenus[ (int) $post->ID ] = array(
			'type'  => (string) $post->post_type,
			'titre' => trafic_texte( $post->post_title ),
			'date'  => substr( (string) $post->post_date, 0, 10 ),
		);
	}
	$memo = array( $version => $contenus );
	return $contenus;
}

/**
 * Vues simulées d'un contenu sur $num jours se terminant le jour $fin (0 avant sa publication).
 *
 * @param int    $post_id Contenu (0 : accueil).
 * @param array  $contenu Contenu (simule_contenus()).
 * @param string $fin     Dernier jour.
 * @param int    $num     Nombre de jours.
 */
function simule_vues_contenu( int $post_id, array $contenu, string $fin, int $num ): int {
	if ( $post_id > 0 && $contenu['date'] > $fin ) {
		return 0;
	}
	$poids   = $post_id > 0 ? ( SIMULE_POIDS[ $contenu['type'] ] ?? 0.5 ) : 9.0;
	$facteur = 0.3 + 1.7 * simule_alea( 'contenu|' . $post_id );
	$bruit   = 0.8 + 0.4 * simule_alea( 'contenu|' . $post_id . '|' . $fin . '|' . $num );
	return (int) round( $num * 9 * $poids * $facteur * $bruit );
}

/*
 * -----------------------------------------------------------------------------
 * Ressources simulées
 * -----------------------------------------------------------------------------
 */

/**
 * Visites : { date, unit, fields, data } par jour (ou par mois : somme des jours du mois jusqu'à
 * la date de fin).
 *
 * @param array $args unit (day|month), quantity, date.
 */
function simule_visites( array $args ): array {
	$fin      = simule_fin( $args );
	$mensuel  = isset( $args['unit'] ) && 'month' === $args['unit'];
	$quantite = simule_arg( $args, 'quantity', 30, $mensuel ? 36 : 800 );
	$lignes   = array();
	if ( $mensuel ) {
		foreach ( trafic_mois( $fin, $quantite ) as $mois ) {
			$dernier = gmdate( 'Y-m-t', (int) strtotime( $mois . ' 12:00:00 UTC' ) );
			$somme   = array( 0, 0 );
			foreach ( trafic_jours( $mois, min( $dernier, $fin ) ) as $jour ) {
				$valeurs   = simule_jour( $jour );
				$somme[0] += $valeurs['vues'];
				$somme[1] += $valeurs['visiteurs'];
			}
			$lignes[] = array( $mois, $somme[0], $somme[1] );
		}
	} else {
		$debut = gmdate( 'Y-m-d', (int) strtotime( $fin . ' 12:00:00 UTC' ) - ( $quantite - 1 ) * DAY_IN_SECONDS );
		foreach ( trafic_jours( $debut, $fin ) as $jour ) {
			$valeurs  = simule_jour( $jour );
			$lignes[] = array( $jour, $valeurs['vues'], $valeurs['visiteurs'] );
		}
	}
	return array(
		'date'   => $fin,
		'unit'   => $mensuel ? 'month' : 'day',
		'fields' => array( 'period', 'views', 'visitors' ),
		'data'   => $lignes,
	);
}

/**
 * Liste au format « summary » (summarize) ou « days[date] ».
 *
 * @param array  $args    Arguments de l'API.
 * @param string $fin     Dernier jour.
 * @param array  $contenu Contenu de la liste (clés de l'API).
 */
function simule_liste( array $args, string $fin, array $contenu ): array {
	if ( ! empty( $args['summarize'] ) ) {
		return array(
			'date'    => $fin,
			'period'  => 'day',
			'summary' => $contenu,
		);
	}
	return array(
		'date'   => $fin,
		'period' => 'day',
		'days'   => array( $fin => $contenu ),
	);
}

/**
 * Contenus les plus vus : accueil + contenus publiés du site, triés, coupés à « max ».
 *
 * @param array $args period, date, num, max, summarize.
 */
function simule_top_posts( array $args ): array {
	$fin      = simule_fin( $args );
	$num      = simule_arg( $args, 'num', 1, 800 );
	$max      = simule_arg( $args, 'max', 10, 1000 );
	$lignes   = array();
	$contenus = array(
		0 => array(
			'type'  => 'homepage',
			'titre' => 'Home page / Archives',
			'date'  => '',
		),
	) + simule_contenus();
	foreach ( $contenus as $post_id => $contenu ) {
		$vues = simule_vues_contenu( (int) $post_id, $contenu, $fin, $num );
		if ( $vues <= 0 ) {
			continue;
		}
		$lignes[] = array(
			'id'     => (int) $post_id,
			'href'   => $post_id > 0 ? (string) get_permalink( (int) $post_id ) : home_url( '/' ),
			'date'   => $contenu['date'],
			'title'  => $contenu['titre'],
			'type'   => $post_id > 0 ? ( 'page' === $contenu['type'] ? 'page' : 'post' ) : 'homepage',
			'public' => true,
			'views'  => $vues,
		);
	}
	usort( $lignes, static fn( array $a, array $b ): int => array( $b['views'], $a['id'] ) <=> array( $a['views'], $b['id'] ) );
	return simule_liste(
		$args,
		$fin,
		array(
			'postviews'   => array_slice( $lignes, 0, $max ),
			'total_views' => (int) array_sum( array_column( $lignes, 'views' ) ),
		)
	);
}

/**
 * Référents : un groupe par site (Google en deux résultats, les autres en un).
 *
 * @param array $args period, date, num, max, summarize.
 */
function simule_referents( array $args ): array {
	$fin     = simule_fin( $args );
	$num     = simule_arg( $args, 'num', 1, 800 );
	$max     = simule_arg( $args, 'max', 10, 1000 );
	$base    = simule_vues_periode( $fin, $num ) * 0.55;
	$groupes = array();
	foreach ( SIMULE_REFERENTS as $nom => $referent ) {
		$total    = (int) round( $base * $referent[1] * ( 0.85 + 0.3 * simule_alea( 'referent|' . $nom . '|' . $fin . '|' . $num ) ) );
		$domaines = $referent[2] ? $referent[2] : array( (string) wp_parse_url( $referent[0], PHP_URL_HOST ) );
		$reste    = $total;
		$results  = array();
		foreach ( $domaines as $i => $domaine ) {
			$vues      = ( count( $domaines ) - 1 === $i ) ? $reste : (int) round( $total * 0.7 );
			$reste    -= $vues;
			$results[] = array(
				'name'  => $domaine,
				'url'   => 'https://' . $domaine . '/',
				'views' => $vues,
			);
		}
		$groupes[] = array(
			'group'   => $nom,
			'name'    => $nom,
			'url'     => $referent[0],
			'icon'    => '',
			'total'   => $total,
			'results' => $results,
		);
	}
	usort( $groupes, static fn( array $a, array $b ): int => $b['total'] <=> $a['total'] );
	return simule_liste(
		$args,
		$fin,
		array(
			'groups'      => array_slice( $groupes, 0, $max ),
			'other_views' => 0,
			'total_views' => (int) array_sum( array_column( $groupes, 'total' ) ),
		)
	);
}

/**
 * Termes de recherche : nom du site et titres des œuvres publiées ; une part chiffrée par le
 * moteur (encrypted_search_terms).
 *
 * @param array $args period, date, num, max, summarize.
 */
function simule_recherches( array $args ): array {
	$fin    = simule_fin( $args );
	$num    = simule_arg( $args, 'num', 1, 800 );
	$max    = simule_arg( $args, 'max', 10, 1000 );
	$base   = simule_vues_periode( $fin, $num ) * 0.06;
	$termes = array( 'yume novel', 'yumenovel', 'light novel français' );
	foreach ( simule_contenus() as $contenu ) {
		if ( 'yume_oeuvre' === $contenu['type'] && $contenu['date'] <= $fin && '' !== $contenu['titre'] ) {
			$titre    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $contenu['titre'] ) : strtolower( $contenu['titre'] );
			$termes[] = $titre . ' light novel';
			$termes[] = $titre . ' vf';
		}
	}
	$lignes = array();
	foreach ( array_unique( $termes ) as $i => $terme ) {
		$vues = (int) round( $base / ( 1 + $i ) * ( 0.7 + 0.6 * simule_alea( 'terme|' . $terme . '|' . $fin . '|' . $num ) ) );
		if ( $vues > 0 ) {
			$lignes[] = array(
				'term'  => $terme,
				'views' => $vues,
			);
		}
	}
	usort( $lignes, static fn( array $a, array $b ): int => $b['views'] <=> $a['views'] );
	return simule_liste(
		$args,
		$fin,
		array(
			'search_terms'           => array_slice( $lignes, 0, $max ),
			'encrypted_search_terms' => (int) round( $base * 1.5 ),
		)
	);
}

/**
 * Vues par pays (codes ISO, jamais de ville) et « country-info ».
 *
 * @param array $args period, date, num, max, summarize.
 */
function simule_pays( array $args ): array {
	$fin   = simule_fin( $args );
	$num   = simule_arg( $args, 'num', 1, 800 );
	$max   = simule_arg( $args, 'max', 10, 1000 );
	$base  = simule_vues_periode( $fin, $num );
	$vues  = array();
	$infos = array();
	foreach ( SIMULE_PAYS as $code => $pays ) {
		$n = (int) round( $base * $pays[1] * ( 0.85 + 0.3 * simule_alea( 'pays|' . $code . '|' . $fin . '|' . $num ) ) );
		if ( $n > 0 ) {
			$vues[] = array(
				'country_code' => $code,
				'views'        => $n,
			);
		}
		$infos[ $code ] = array(
			'flag_icon'    => '',
			'flat_flag'    => '',
			'country_full' => $pays[0],
			'map_region'   => '',
		);
	}
	usort( $vues, static fn( array $a, array $b ): int => $b['views'] <=> $a['views'] );
	$reponse                 = simule_liste( $args, $fin, array( 'views' => array_slice( $vues, 0, $max ) ) );
	$reponse['country-info'] = $infos;
	return $reponse;
}

/**
 * Vues d'un contenu par jour (SIMULE_JOURS_POST jours jusqu'à aujourd'hui, depuis sa publication).
 *
 * @param int   $post_id Contenu.
 * @param array $args    Arguments (aucun n'est utilisé, comme l'API).
 */
function simule_post( int $post_id, array $args ): array {
	unset( $args );
	$contenus = simule_contenus();
	$contenu  = $contenus[ $post_id ] ?? array(
		'type'  => (string) get_post_type( $post_id ),
		'titre' => trafic_texte( get_post_field( 'post_title', $post_id ) ),
		'date'  => substr( (string) get_post_field( 'post_date', $post_id ), 0, 10 ),
	);
	$fin      = (string) wp_date( 'Y-m-d', maintenant() );
	$debut    = gmdate( 'Y-m-d', (int) strtotime( $fin . ' 12:00:00 UTC' ) - ( SIMULE_JOURS_POST - 1 ) * DAY_IN_SECONDS );
	$poids    = SIMULE_POIDS[ $contenu['type'] ] ?? 0.5;
	$facteur  = 0.3 + 1.7 * simule_alea( 'contenu|' . $post_id );
	$lignes   = array();
	$total    = 0;
	foreach ( trafic_jours( $debut, $fin ) as $jour ) {
		$vues     = ( '' !== $contenu['date'] && $contenu['date'] > $jour ) ? 0 : (int) round( 9 * $poids * $facteur * ( 0.5 + simule_alea( 'post|' . $post_id . '|' . $jour ) ) );
		$lignes[] = array( $jour, $vues );
		$total   += $vues;
	}
	return array(
		'date'   => $fin,
		'views'  => $total,
		'fields' => array( 'period', 'views' ),
		'data'   => $lignes,
	);
}
