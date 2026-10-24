<?php
/**
 * Comptes inscrits, vue liste de l'espace équipe (?vue=comptes, menu « Équipe », juste après
 * « Membres et rôles ») — lot B.
 *
 * Rendu serveur complet, sans JavaScript : formulaire GET de filtres, liens de filtres rapides,
 * tableau défilant sur mobile, pagination par « pg » (Planning\pagination_vue()). Paramètres :
 * ceux de args_comptes() (q, role, source, discord, activite, inscrits, sans_lecture, sans_mdp,
 * tri, ordre, pg), plus « compte » = ID : fiche du compte, rendue par rendu_fiche() (lot C) si
 * elle existe, sinon message « bientôt disponible ».
 *
 * Données : lister_comptes(), indicateurs() (lot A), url_export() (lot D), journal_recent() et
 * rendu_fiche() (lot C), toutes appelées par vue_donnees() : une fonction absente donne null et la
 * vue affiche un message à la place. Le filtre yume_comptes_vue_donnees court-circuite ces appels
 * (tests, démonstrations).
 *
 * Capacité CAPACITE (yume_voir_lecteurs : gérants et administrateurs). Aucune colonne e-mail.
 * Pseudos décodés (le cœur stocke « & » en « &amp; ») puis échappés. Journal des consultations
 * (<details>) visible des administrateurs (manage_options). Feuille assets/comptes.css (VUE_STYLE,
 * dépend de yume-kpi pour le tableau défilant) : variables du thème uniquement.
 *
 * Contrat : docs/06-contrat-technique.md §8 septies.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

use function Yume\Core\Planning\champ_select;
use function Yume\Core\Planning\champs_caches_vue;
use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\get_entier;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\pagination_vue;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/** Entrées du journal affichées dans « Dernières consultations ». */
const VUE_JOURNAL = 20;

/*
 * -----------------------------------------------------------------------------
 * Déclaration, style
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare la vue dans l'espace équipe (filtre yume_vues_equipe, priorité 9 : avant la vue
 * « Commentaires » du module social, ajoutée à la priorité 10, pour que « Comptes inscrits »
 * suive directement « Membres et rôles » dans le menu « Équipe »).
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue( $vues ): array {
	$vues            = is_array( $vues ) ? $vues : array();
	$vues[ VUE_CLE ] = array(
		'libelle'  => __( 'Comptes inscrits', 'yume-core' ),
		'capacite' => CAPACITE,
		'groupe'   => 'equipe',
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_comptes',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue', 9 );

/**
 * Feuille de style de la vue (enfilée seulement quand la vue est rendue ; s'appuie sur celle des
 * Indicateurs pour le tableau défilant).
 */
function enregistrer_style(): void {
	wp_register_style( VUE_STYLE, YUME_CORE_URL . 'includes/comptes/assets/comptes.css', array( 'yume-kpi' ), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style' );

/*
 * -----------------------------------------------------------------------------
 * État de la vue (paramètres GET)
 * -----------------------------------------------------------------------------
 */

/**
 * État de la vue, lu dans $_GET et normalisé (valeur inconnue → défaut). La fiche reçoit
 * vue_etat_fiche( $etat ).
 *
 * @return array{args:array,compte:int,user_id:int,url_liste:string}
 *   args      paramètres de la liste (args_comptes()) ;
 *   compte    compte demandé (?compte=ID), 0 pour la liste ;
 *   user_id   compte qui consulte ;
 *   url_liste adresse de la liste avec les mêmes filtres (lien « Retour à la liste »).
 */
function vue_etat(): array {
	$brut = array();
	foreach ( array_merge( array_keys( args_comptes( array() ) ), array( 'pg' ) ) as $cle ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtres d'affichage en lecture seule.
		if ( isset( $_GET[ $cle ] ) && is_scalar( $_GET[ $cle ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini par args_comptes().
			$brut[ $cle ] = wp_unslash( (string) $_GET[ $cle ] );
		}
	}
	$args = args_comptes( $brut );
	return array(
		'args'      => $args,
		'compte'    => get_entier( 'compte' ),
		'user_id'   => get_current_user_id(),
		'url_liste' => url_comptes( $args ),
	);
}

/**
 * Paramètres d'adresse de la liste qui diffèrent des défauts (mêmes règles que url_comptes() :
 * cases cochées « 1 », ordre omis s'il est celui du tri), sans la page.
 *
 * @param array $args Paramètres (args_comptes()).
 * @return array<string,string>
 */
function vue_parametres( array $args ): array {
	$args    = args_comptes( $args );
	$defauts = args_comptes( array() );
	$sortie  = array();
	foreach ( $args as $cle => $valeur ) {
		if ( in_array( $cle, array( 'page', 'limite' ), true ) ) {
			continue;
		}
		$omis = 'ordre' === $cle ? ordre_par_defaut( $args['tri'] ) === $valeur : $defauts[ $cle ] === $valeur;
		if ( ! $omis ) {
			$sortie[ $cle ] = is_bool( $valeur ) ? '1' : (string) $valeur;
		}
	}
	if ( COMPTES_PAR_PAGE !== $args['limite'] ) {
		$sortie['limite'] = (string) $args['limite'];
	}
	return $sortie;
}

/**
 * Des filtres réduisent-ils la liste (recherche, rôle, source…) ? Le tri seul ne compte pas.
 *
 * @param array $args Paramètres (args_comptes()).
 */
function vue_filtree( array $args ): bool {
	return (bool) array_diff_key( vue_parametres( $args ), array_flip( array( 'tri', 'ordre', 'limite' ) ) );
}

/*
 * -----------------------------------------------------------------------------
 * Composants
 * -----------------------------------------------------------------------------
 */

/**
 * Pseudo prêt à afficher : entités du cœur décodées (« &amp; » → « & »), puis échappé. Vide :
 * « Compte n° ID ».
 *
 * @param mixed $pseudo Pseudo (display_name).
 * @param int   $id     Compte.
 */
function vue_pseudo( $pseudo, int $id = 0 ): string {
	$pseudo = trim( wp_specialchars_decode( is_scalar( $pseudo ) ? (string) $pseudo : '', ENT_QUOTES ) );
	/* translators: %d : numéro du compte */
	return esc_html( '' !== $pseudo ? $pseudo : sprintf( __( 'Compte n° %d', 'yume-core' ), $id ) );
}

/**
 * Nombre lisible (« 1 240 »), « — » si inconnu.
 *
 * @param mixed $n Nombre.
 */
function vue_nombre( $n ): string {
	return is_numeric( $n ) ? number_format_i18n( (int) $n ) : '—';
}

/**
 * Horodatage d'une date ISO 8601 (UTC si aucun fuseau n'est indiqué), 0 si invalide.
 *
 * @param mixed $iso Date.
 */
function vue_horodatage( $iso ): int {
	if ( ! is_string( $iso ) || '' === trim( $iso ) ) {
		return 0;
	}
	$ts = strtotime( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $iso ) ? $iso : $iso . ' UTC' );
	return false !== $ts && $ts > 0 ? $ts : 0;
}

/**
 * Date courte en français (« 12 oct. 2026 ») dans un élément <time>, « — » si absente.
 *
 * @param mixed $iso Date ISO 8601.
 */
function vue_date( $iso ): string {
	$ts = vue_horodatage( $iso );
	if ( ! $ts ) {
		return '<span class="yn-muted">—</span>';
	}
	return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( format_fr( $ts, 'j M Y' ) ) . '</time>';
}

/**
 * Horodatage (midi UTC) d'un jour 'Y-m-d' : même jour à Paris, pour format_fr().
 *
 * @param string $jour Jour.
 */
function vue_ts_jour( string $jour ): int {
	return jour_valide( $jour ) ? (int) strtotime( $jour . ' 12:00:00 UTC' ) : 0;
}

/**
 * Jour en toutes lettres (« 28 oct. 2026 »), '' si invalide.
 *
 * @param string $jour Jour 'Y-m-d'.
 */
function vue_jour( string $jour ): string {
	$ts = vue_ts_jour( $jour );
	return $ts ? format_fr( $ts, 'j M Y' ) : '';
}

/**
 * Dernière activité relative au jour du site (« aujourd’hui », « hier », « il y a 9 j », puis la
 * date), dans un élément <time> ; « inconnue » si jamais relevée.
 *
 * @param mixed $jour Jour 'Y-m-d' ou ''.
 */
function vue_activite( $jour ): string {
	$jour = is_string( $jour ) ? $jour : '';
	if ( ! jour_valide( $jour ) ) {
		return '<span class="yn-muted">' . esc_html__( 'inconnue', 'yume-core' ) . '</span>';
	}
	$ecart = (int) round( ( vue_ts_jour( jour_site() ) - vue_ts_jour( $jour ) ) / DAY_IN_SECONDS );
	if ( $ecart <= 0 ) {
		$texte = __( 'aujourd’hui', 'yume-core' );
	} elseif ( 1 === $ecart ) {
		$texte = __( 'hier', 'yume-core' );
	} elseif ( $ecart <= 60 ) {
		/* translators: %d : jours */
		$texte = sprintf( __( 'il y a %d j', 'yume-core' ), $ecart );
	} else {
		$texte = vue_jour( $jour );
	}
	return '<time datetime="' . esc_attr( $jour ) . '">' . esc_html( $texte ) . '</time>';
}

/**
 * Carte d'un indicateur : surtitre, valeur et détail (HTML déjà échappé).
 *
 * @param string $label  Surtitre.
 * @param mixed  $valeur Valeur (nombre ou null).
 * @param string $detail HTML du détail (lien, texte) ; vide sinon.
 */
function vue_carte( string $label, $valeur, string $detail = '' ): string {
	$html  = '<div class="yn-card yn-team__chiffre yn-comptes__chiffre"><p class="yn-label">' . esc_html( $label ) . '</p>';
	$html .= '<p class="yn-team__valeur">' . esc_html( vue_nombre( $valeur ) ) . '</p>';
	return $html . ( '' !== $detail ? '<p class="yn-comptes__detail">' . $detail . '</p>' : '' ) . '</div>';
}

/**
 * Début du relevé de la dernière activité ('Y-m-d') : celui des indicateurs, sinon l'option.
 *
 * @param array|null $indicateurs Indicateurs (indicateurs()) ou null.
 */
function vue_activite_depuis( ?array $indicateurs ): string {
	$depuis = is_array( $indicateurs ) ? (string) ( $indicateurs['activite_depuis'] ?? '' ) : '';
	if ( ! jour_valide( $depuis ) ) {
		$depuis = (string) get_option( OPTION_DEPUIS, '' );
	}
	return jour_valide( $depuis ) ? $depuis : '';
}

/**
 * Adresse de la vue Statistiques, onglet « Lecteurs » ('' si le module est absent).
 */
function vue_url_statistiques(): string {
	return function_exists( '\Yume\Core\Statistiques\url_statistiques' )
		? (string) \Yume\Core\Statistiques\url_statistiques( array( 'onglet' => 'lecteurs' ) )
		: '';
}

/*
 * -----------------------------------------------------------------------------
 * Sections
 * -----------------------------------------------------------------------------
 */

/**
 * En-tête : titre, boutons (Statistiques des lecteurs, Membres et rôles) et présentation.
 *
 * @param string $depuis Début du relevé de l'activité ('Y-m-d' ou '').
 */
function vue_entete( string $depuis ): string {
	$boutons      = '';
	$statistiques = vue_url_statistiques();
	if ( '' !== $statistiques ) {
		$boutons .= '<a class="yn-btn" href="' . esc_url( $statistiques ) . '">' . esc_html__( 'Statistiques des lecteurs', 'yume-core' ) . '</a>';
	}
	$membres = function_exists( '\Yume\Core\Planning\url_membres' ) ? (string) \Yume\Core\Planning\url_membres() : '';
	if ( '' !== $membres ) {
		$boutons .= '<a class="yn-btn" href="' . esc_url( $membres ) . '">' . esc_html__( 'Membres et rôles', 'yume-core' ) . '</a>';
	}
	$html  = tete_vue( __( 'Comptes inscrits', 'yume-core' ), $boutons );
	$texte = __( 'Tous les comptes du site et leur activité. L’adresse e-mail n’apparaît que sur la fiche d’un compte.', 'yume-core' );
	if ( '' !== $depuis ) {
		/* translators: %s : date (« 28 oct. 2026 ») */
		$texte .= ' ' . sprintf( __( 'Dernière activité relevée au jour près depuis le %s (« inconnue » avant).', 'yume-core' ), vue_jour( $depuis ) );
	}
	return $html . '<p class="yn-muted yn-comptes__presentation">' . esc_html( $texte ) . '</p>';
}

/**
 * Indicateurs : comptes, lecteurs, équipe, nouveaux et actifs sur 30 jours, liés à Discord.
 *
 * @param array|null $ind    Indicateurs (indicateurs()) ou null.
 * @param string     $depuis Début du relevé de l'activité.
 */
function vue_indicateurs( ?array $ind, string $depuis ): string {
	$html = '<section class="yn-comptes__chiffres" aria-label="' . esc_attr__( 'Indicateurs des comptes', 'yume-core' ) . '">';
	if ( null === $ind ) {
		return $html . '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'Indicateurs indisponibles pour le moment.', 'yume-core' ) . '</p></section>';
	}
	$statistiques = vue_url_statistiques();
	$html        .= vue_carte( __( 'Comptes', 'yume-core' ), $ind['comptes'] ?? null );
	$html        .= vue_carte( __( 'Lecteurs', 'yume-core' ), $ind['lecteurs'] ?? null );
	$html        .= vue_carte( __( 'Équipe', 'yume-core' ), $ind['equipe'] ?? null );
	$html        .= vue_carte(
		__( 'Nouveaux (30 j)', 'yume-core' ),
		$ind['nouveaux_30j'] ?? null,
		'' !== $statistiques ? '<a href="' . esc_url( $statistiques ) . '">' . esc_html__( 'Évolution', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html__( 'des inscriptions', 'yume-core' ) . '</span> <span aria-hidden="true">→</span></a>' : ''
	);
	$html        .= vue_carte(
		__( 'Actifs (30 j)', 'yume-core' ),
		$ind['actifs_30j'] ?? null,
		/* translators: %s : date (« 28 oct. 2026 ») */
		'' !== $depuis ? '<span class="yn-muted">' . esc_html( sprintf( __( 'relevé depuis le %s', 'yume-core' ), vue_jour( $depuis ) ) ) . '</span>' : ''
	);
	$sans_mdp = (int) ( $ind['sans_mdp'] ?? 0 );
	$html    .= vue_carte(
		__( 'Liés à Discord', 'yume-core' ),
		$ind['discord'] ?? null,
		/* translators: %s : nombre de comptes */
		'<span class="yn-muted">' . esc_html( sprintf( __( 'dont %s sans mot de passe', 'yume-core' ), vue_nombre( $sans_mdp ) ) ) . '</span>'
	);
	return $html . '</section>';
}

/**
 * Choix des listes déroulantes des filtres : clé de args_comptes() => valeur => libellé.
 *
 * @return array<string,array<string,string>>
 */
function vue_choix(): array {
	return array(
		'role'     => libelles_roles(),
		'source'   => array( 'toutes' => __( 'Toutes', 'yume-core' ) ) + libelles_sources(),
		'discord'  => array(
			'tous' => __( 'Tous', 'yume-core' ),
			'lie'  => __( 'Lié', 'yume-core' ),
			'non'  => __( 'Non lié', 'yume-core' ),
		),
		'activite' => array(
			'toutes'   => __( 'Toutes', 'yume-core' ),
			'7'        => __( 'Actifs 7 j', 'yume-core' ),
			'30'       => __( 'Actifs 30 j', 'yume-core' ),
			'90'       => __( 'Actifs 90 j', 'yume-core' ),
			'inactifs' => __( 'Inactifs 90 j', 'yume-core' ),
			'inconnue' => __( 'Inconnue', 'yume-core' ),
		),
		'inscrits' => array(
			'tout' => __( 'Depuis toujours', 'yume-core' ),
			'7'    => __( '7 jours', 'yume-core' ),
			'30'   => __( '30 jours', 'yume-core' ),
			'90'   => __( '90 jours', 'yume-core' ),
			'365'  => __( '1 an', 'yume-core' ),
		),
		'tri'      => array(
			'inscription'  => __( 'Inscription', 'yume-core' ),
			'pseudo'       => __( 'Pseudo', 'yume-core' ),
			'activite'     => __( 'Dernière activité', 'yume-core' ),
			'lecture'      => __( 'Dernière lecture', 'yume-core' ),
			'favoris'      => __( 'Favoris', 'yume-core' ),
			'commentaires' => __( 'Commentaires', 'yume-core' ),
		),
		'ordre'    => array(
			''     => __( 'Par défaut', 'yume-core' ),
			'desc' => __( 'Décroissant', 'yume-core' ),
			'asc'  => __( 'Croissant', 'yume-core' ),
		),
	);
}

/**
 * Filtres rapides (liens) : clé => array{libelle, args}.
 *
 * @return array<string,array{libelle:string,args:array}>
 */
function vue_filtres_rapides(): array {
	return array(
		'tous'    => array(
			'libelle' => __( 'Tous', 'yume-core' ),
			'args'    => array(),
		),
		'discord' => array(
			'libelle' => __( 'Discord', 'yume-core' ),
			'args'    => array( 'discord' => 'lie' ),
		),
		'equipe'  => array(
			'libelle' => __( 'Équipe', 'yume-core' ),
			'args'    => array( 'role' => 'equipe' ),
		),
		'actifs'  => array(
			'libelle' => __( 'Actifs 7 j', 'yume-core' ),
			'args'    => array( 'activite' => '7' ),
		),
	);
}

/**
 * Formulaire GET des filtres (recherche, listes, cases, tri), « Filtrer », « Réinitialiser » et
 * filtres rapides. Les filtres conservent le tri ; les filtres rapides repartent des défauts.
 *
 * @param array $args Paramètres (args_comptes()).
 */
function vue_filtres( array $args ): string {
	$choix = vue_choix();
	$html  = '<form class="yn-card yn-comptes__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filtrer les comptes', 'yume-core' ) . '">' . champs_caches_vue( VUE_CLE );
	if ( COMPTES_PAR_PAGE !== $args['limite'] ) {
		$html .= '<input type="hidden" name="limite" value="' . (int) $args['limite'] . '">';
	}
	$html .= '<p class="yn-team__champ yn-comptes__recherche"><label class="yn-label" for="yn-comptes-q">' . esc_html__( 'Rechercher (pseudo, nom Discord ou e-mail exact)', 'yume-core' ) . '</label>';
	$html .= '<input type="search" id="yn-comptes-q" name="q" value="' . esc_attr( $args['q'] ) . '" maxlength="' . (int) RECHERCHE_MAX . '" placeholder="' . esc_attr__( 'Ex. : Sakura, sakura@exemple.fr', 'yume-core' ) . '" aria-describedby="yn-comptes-q-aide">';
	/* translators: %d : nombre de caractères */
	$html .= '<span class="yn-muted yn-comptes__aide" id="yn-comptes-q-aide">' . esc_html( sprintf( __( '%d caractères minimum. L’adresse e-mail doit être complète.', 'yume-core' ), RECHERCHE_MIN ) ) . '</span></p>';

	$html  .= '<div class="yn-comptes__champs">';
	$libels = array(
		'role'     => __( 'Rôle', 'yume-core' ),
		'source'   => __( 'Source', 'yume-core' ),
		'discord'  => __( 'Discord', 'yume-core' ),
		'activite' => __( 'Activité', 'yume-core' ),
		'inscrits' => __( 'Inscrits', 'yume-core' ),
		'tri'      => __( 'Trier par', 'yume-core' ),
		'ordre'    => __( 'Ordre', 'yume-core' ),
	);
	foreach ( $libels as $cle => $libelle ) {
		$actuelle = 'ordre' === $cle && ordre_par_defaut( $args['tri'] ) === $args['ordre'] ? '' : (string) $args[ $cle ];
		$html    .= champ_select( 'yn-comptes-' . $cle, $cle, $libelle, $choix[ $cle ], $actuelle );
	}
	$html .= '</div><div class="yn-comptes__ligne">';
	$html .= '<fieldset class="yn-comptes__cases"><legend class="yn-visually-hidden">' . esc_html__( 'Autres filtres', 'yume-core' ) . '</legend>';
	foreach (
		array(
			'sans_lecture' => __( 'Sans aucune lecture', 'yume-core' ),
			'sans_mdp'     => __( 'Sans mot de passe', 'yume-core' ),
		) as $cle => $libelle
	) {
		$id    = 'yn-comptes-' . str_replace( '_', '-', $cle );
		$html .= '<label class="yn-team__case" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $cle ) . '" value="1"' . checked( $args[ $cle ], true, false ) . '> ' . esc_html( $libelle ) . '</label>';
	}
	$html .= '</fieldset><p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button>';
	$html .= '<a class="yn-comptes__reinitialiser" href="' . esc_url( url_comptes() ) . '">' . esc_html__( 'Réinitialiser', 'yume-core' ) . '</a></p></div>';

	// Filtres rapides : liens, celui qui correspond aux filtres courants porte aria-current.
	$courants = array_diff_key( vue_parametres( $args ), array_flip( array( 'tri', 'ordre', 'limite' ) ) );
	$html    .= '<div class="yn-comptes__rapides"><span class="yn-label" id="yn-comptes-rapides">' . esc_html__( 'Filtres rapides', 'yume-core' ) . '</span><ul aria-labelledby="yn-comptes-rapides">';
	foreach ( vue_filtres_rapides() as $cle => $rapide ) {
		$actif = vue_parametres( $rapide['args'] ) === $courants;
		$html .= '<li><a class="yn-comptes__rapide" href="' . esc_url( url_comptes( $rapide['args'] ) ) . '"' . ( $actif ? ' aria-current="true"' : '' ) . ' data-yn-rapide="' . esc_attr( $cle ) . '">' . esc_html( $rapide['libelle'] ) . '</a></li>';
	}
	return $html . '</ul></div></form>';
}

/**
 * Cellule « Pseudo » : lien vers la fiche (filtres de la liste conservés) et pastilles Équipe,
 * Discord, Sans mot de passe.
 *
 * @param array $c    Compte (lister_comptes()).
 * @param array $args Paramètres de la liste.
 */
function vue_cellule_pseudo( array $c, array $args ): string {
	$id    = (int) ( $c['id'] ?? 0 );
	$url   = url_comptes( array_merge( vue_parametres( $args ), array( 'page' => $args['page'] ), array( 'compte' => $id ) ) );
	$html  = '<a class="yn-comptes__pseudo" href="' . esc_url( $url ) . '">' . vue_pseudo( $c['pseudo'] ?? '', $id ) . '</a>';
	$html .= ! empty( $c['equipe'] ) ? ' <span class="yn-chip yn-comptes__badge yn-comptes__badge--equipe">' . esc_html__( 'Équipe', 'yume-core' ) . '</span>' : '';
	$html .= is_string( $c['discord'] ?? null ) && '' !== $c['discord'] ? ' <span class="yn-chip yn-comptes__badge yn-comptes__badge--discord">' . esc_html__( 'Discord', 'yume-core' ) . '</span>' : '';
	$html .= ! empty( $c['sans_mdp'] ) ? ' <span class="yn-chip yn-chip--warn yn-comptes__badge"><span aria-hidden="true">' . esc_html__( 'Sans mdp', 'yume-core' ) . '</span><span class="yn-visually-hidden">' . esc_html__( 'Sans mot de passe', 'yume-core' ) . '</span></span>' : '';
	return $html;
}

/**
 * Tableau des comptes (défilant sur petit écran) : Pseudo · Rôle · Inscrit le · Source · Dernière
 * activité · Dernière lecture · Chap. lus (est.) · Favoris (dont alertés) · Comm. Les en-têtes
 * abrégés de la maquette sont lus en entier par les lecteurs d'écran. Jamais d'adresse e-mail.
 *
 * @param array $comptes Comptes (lister_comptes()['comptes']).
 * @param array $args    Paramètres de la liste.
 */
function vue_tableau( array $comptes, array $args ): string {
	$sources  = libelles_sources();
	$colonnes = array( // Libellé affiché, tri, colonne de nombres, libellé complet (abrégé sinon '').
		array( __( 'Pseudo', 'yume-core' ), 'pseudo', false, '' ),
		array( __( 'Rôle', 'yume-core' ), '', false, '' ),
		array( __( 'Inscrit le', 'yume-core' ), 'inscription', false, '' ),
		array( __( 'Source', 'yume-core' ), '', false, '' ),
		array( __( 'Dernière activité', 'yume-core' ), 'activite', false, '' ),
		array( __( 'Dernière lecture', 'yume-core' ), 'lecture', false, '' ),
		array( __( 'Chap. lus (est.)', 'yume-core' ), '', true, __( 'Chapitres lus (estimation)', 'yume-core' ) ),
		array( __( 'Favoris', 'yume-core' ), 'favoris', true, '' ),
		array( __( 'Comm.', 'yume-core' ), 'commentaires', true, __( 'Commentaires', 'yume-core' ) ),
	);
	$html     = '<div class="yn-kpi__defile yn-comptes__defile" role="region" tabindex="0" aria-labelledby="yn-comptes-legende">';
	$html    .= '<table class="yn-kpi__table yn-comptes__table"><caption id="yn-comptes-legende" class="yn-visually-hidden">' . esc_html__( 'Comptes inscrits', 'yume-core' ) . '</caption><thead><tr>';
	foreach ( $colonnes as $col ) {
		$tri   = '' !== $col[1] && $col[1] === $args['tri'] ? ' aria-sort="' . ( 'asc' === $args['ordre'] ? 'ascending' : 'descending' ) . '"' : '';
		$texte = '' !== $col[3] ? '<span aria-hidden="true">' . esc_html( $col[0] ) . '</span><span class="yn-visually-hidden">' . esc_html( $col[3] ) . '</span>' : esc_html( $col[0] );
		$html .= '<th scope="col"' . ( $col[2] ? ' class="yn-kpi__nombre"' : '' ) . $tri . '>' . $texte . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $comptes as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$source   = (string) ( $c['source'] ?? 'inconnue' );
		$source   = $sources[ $source ] ?? $sources['inconnue'];
		$deduite  = ! empty( $c['source_deduite'] ) ? ' <span class="yn-muted">' . esc_html__( '(déduite)', 'yume-core' ) . '</span>' : '';
		$favoris  = (int) ( $c['favoris'] ?? 0 );
		$suiveurs = (int) ( $c['suiveurs'] ?? 0 );
		$html    .= '<tr><th scope="row">' . vue_cellule_pseudo( $c, $args ) . '</th>';
		$html    .= '<td>' . esc_html( (string) ( $c['role'] ?? '' ) ) . '</td>';
		$html    .= '<td>' . vue_date( $c['inscrit_le'] ?? '' ) . '</td>';
		$html    .= '<td>' . esc_html( $source ) . $deduite . '</td>';
		$html    .= '<td>' . vue_activite( $c['activite'] ?? '' ) . '</td>';
		$html    .= '<td>' . vue_date( $c['derniere_lecture'] ?? '' ) . '</td>';
		$html    .= '<td class="yn-kpi__nombre">' . esc_html( vue_nombre( $c['chapitres_lus'] ?? 0 ) ) . '</td>';
		/* translators: %s : nombre de favoris avec alertes */
		$html .= '<td class="yn-kpi__nombre">' . esc_html( vue_nombre( $favoris ) ) . ( $favoris > 0 ? ' <span class="yn-muted" aria-hidden="true">(' . esc_html( vue_nombre( $suiveurs ) ) . ')</span><span class="yn-visually-hidden">' . esc_html( sprintf( __( ', dont %s avec alertes', 'yume-core' ), vue_nombre( $suiveurs ) ) ) . '</span>' : '' ) . '</td>';
		$html .= '<td class="yn-kpi__nombre">' . esc_html( vue_nombre( $c['commentaires'] ?? 0 ) ) . '</td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * Liste : résumé (nombre, page), export, tableau ou message vide, pagination, note.
 *
 * @param array $args Paramètres (args_comptes()).
 */
function vue_liste( array $args ): string {
	$resultat = vue_donnees( 'lister_comptes', array( $args ) );
	if ( ! is_array( $resultat ) ) {
		return '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'La liste des comptes n’est pas disponible pour le moment.', 'yume-core' ) . '</p>';
	}
	$comptes = array_values( array_filter( (array) ( $resultat['comptes'] ?? array() ), 'is_array' ) );
	$total   = max( 0, (int) ( $resultat['total'] ?? count( $comptes ) ) );
	$pages   = max( 1, (int) ( $resultat['pages'] ?? 1 ) );
	$page    = min( $pages, max( 1, (int) ( $resultat['page'] ?? $args['page'] ) ) );

	/* translators: %s : nombre de comptes */
	$resume = sprintf( _n( '%s compte', '%s comptes', $total, 'yume-core' ), vue_nombre( $total ) );
	if ( vue_filtree( $args ) ) {
		/* translators: %s : « 12 comptes » */
		$resume = sprintf( _n( '%s correspond aux filtres', '%s correspondent aux filtres', $total, 'yume-core' ), $resume );
	}
	if ( $total > 0 ) {
		/* translators: 1: page, 2: nombre de pages */
		$resume .= ' · ' . sprintf( __( 'page %1$d sur %2$d', 'yume-core' ), $page, $pages );
	}
	$html   = '<div class="yn-comptes__barre"><p class="yn-muted yn-comptes__resume">' . esc_html( $resume ) . '</p>';
	$export = $total > 0 ? vue_donnees( 'url_export', array( $args ) ) : null;
	if ( is_string( $export ) && '' !== $export ) {
		$html .= '<p class="yn-comptes__exports"><a class="yn-btn" href="' . esc_url( $export ) . '" download>' . esc_html__( 'Exporter la liste (CSV)', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html__( '(comptes filtrés, toutes les pages)', 'yume-core' ) . '</span></a>';
		// Export avec les adresses e-mail : administrateurs seulement (journalisé à part par le lot D).
		$avec_email = current_user_can( 'manage_options' ) ? vue_donnees( 'url_export', array( array_merge( $args, array( 'email' => true ) ) ) ) : null;
		if ( is_string( $avec_email ) && '' !== $avec_email ) {
			$html .= '<a class="yn-btn" href="' . esc_url( $avec_email ) . '" download>' . esc_html__( 'Exporter avec e-mail', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html__( '(réservé aux administrateurs, journalisé)', 'yume-core' ) . '</span></a>';
		}
		$html .= '</p>';
	}
	$html .= '</div>';

	if ( $comptes ) {
		$html .= '<section class="yn-card yn-comptes__tableau" aria-label="' . esc_attr__( 'Liste des comptes', 'yume-core' ) . '">' . vue_tableau( $comptes, $args ) . '</section>';
	} else {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html(
			vue_filtree( $args ) ? __( 'Aucun compte ne correspond à ces filtres.', 'yume-core' ) : __( 'Aucun compte pour le moment.', 'yume-core' )
		) . '</p>';
	}
	$html .= pagination_vue( VUE_CLE, vue_parametres( $args ), $page, $page < $pages, $pages );
	return $html . '<p class="yn-muted yn-comptes__note">' . esc_html__( 'Données personnelles : usage interne de l’équipe. Chaque fiche consultée et chaque export sont journalisés. « Chapitres lus » est une estimation d’après la position de lecture ; entre parenthèses, les favoris dont le lecteur reçoit les alertes.', 'yume-core' ) . '</p>';
}

/**
 * Libellé d'une entrée du journal (« Fiche consultée », « Export CSV (12 comptes) »…).
 *
 * @param array $entree Entrée (journal_recent()).
 */
function vue_action_journal( array $entree ): string {
	$nb = (int) ( $entree['nb'] ?? 0 );
	switch ( (string) ( $entree['action'] ?? '' ) ) {
		case 'fiche':
			return __( 'Fiche consultée', 'yume-core' );
		case 'export':
			/* translators: %s : nombre de comptes */
			return sprintf( _n( 'Export CSV (%s compte)', 'Export CSV (%s comptes)', $nb, 'yume-core' ), vue_nombre( $nb ) );
		case 'export_email':
			/* translators: %s : nombre de comptes */
			return sprintf( _n( 'Export CSV avec e-mails (%s compte)', 'Export CSV avec e-mails (%s comptes)', $nb, 'yume-core' ), vue_nombre( $nb ) );
	}
	return (string) ( $entree['action'] ?? '' );
}

/**
 * Nom d'un compte du journal (pseudo, ou « Compte supprimé » s'il n'existe plus).
 *
 * @param int $user_id Compte.
 */
function vue_nom_journal( int $user_id ): string {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	return $user ? vue_pseudo( $user->display_name, $user_id ) : esc_html__( 'Compte supprimé', 'yume-core' );
}

/**
 * « Dernières consultations » (<details>, administrateurs seulement) : quand, qui, quoi, compte.
 */
function vue_journal(): string {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}
	$journal = vue_donnees( 'journal_recent', array( VUE_JOURNAL ) );
	$journal = is_array( $journal ) ? array_values( array_filter( $journal, 'is_array' ) ) : array();
	$html    = '<details class="yn-card yn-comptes__journal"><summary>' . esc_html__( 'Dernières consultations', 'yume-core' ) . ' <span class="yn-muted">' . esc_html__( '(visible des administrateurs)', 'yume-core' ) . '</span></summary>';
	if ( ! $journal ) {
		return $html . '<p class="yn-muted">' . esc_html__( 'Aucune consultation journalisée pour le moment.', 'yume-core' ) . '</p></details>';
	}
	$html .= '<div class="yn-kpi__defile" role="region" tabindex="0" aria-labelledby="yn-comptes-journal-legende"><table class="yn-kpi__table yn-comptes__table"><caption id="yn-comptes-journal-legende" class="yn-visually-hidden">' . esc_html__( 'Dernières fiches consultées et derniers exports', 'yume-core' ) . '</caption><thead><tr>';
	foreach ( array( __( 'Quand', 'yume-core' ), __( 'Qui', 'yume-core' ), __( 'Action', 'yume-core' ), __( 'Compte', 'yume-core' ) ) as $entete ) {
		$html .= '<th scope="col">' . esc_html( $entete ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $journal as $e ) {
		$quand  = (int) ( $e['quand'] ?? 0 );
		$compte = (int) ( $e['compte'] ?? 0 );
		$html  .= '<tr><td>' . ( $quand > 0 ? '<time datetime="' . esc_attr( gmdate( 'c', $quand ) ) . '">' . esc_html( format_fr( $quand, 'j M Y, H\hi' ) ) . '</time>' : '—' ) . '</td>';
		$html  .= '<td>' . vue_nom_journal( (int) ( $e['acteur'] ?? 0 ) ) . '</td>';
		$html  .= '<td>' . esc_html( vue_action_journal( $e ) ) . '</td>';
		$html  .= '<td>' . ( $compte > 0 ? ( get_userdata( $compte ) ? '<a href="' . esc_url( url_comptes( array( 'compte' => $compte ) ) ) . '">' . vue_nom_journal( $compte ) . '</a>' : vue_nom_journal( $compte ) ) : '—' ) . '</td></tr>';
	}
	return $html . '</tbody></table></div></details>';
}

/**
 * Argument de rendu_fiche() (lot C) : paramètres de la liste (args_comptes(), pour le lien de
 * retour) plus « compte » => ID. Aucune autre clé (url_comptes() transmettrait les inconnues).
 *
 * @param array $etat État (vue_etat()).
 * @return array
 */
function vue_etat_fiche( array $etat ): array {
	return $etat['args'] + array( 'compte' => (int) $etat['compte'] );
}

/**
 * Fiche d'un compte (?compte=ID) : contenu de la zone principale rendu par rendu_fiche() (lot C,
 * sa propre tête et son lien de retour) si elle existe, sinon message. La racine et la navigation
 * restent celles de la vue ; ni en-tête, ni indicateurs, ni filtres de la liste.
 *
 * @param array $etat État (vue_etat()).
 */
function vue_fiche( array $etat ): string {
	$fiche = vue_donnees( 'rendu_fiche', array( vue_etat_fiche( $etat ) ) );
	if ( is_string( $fiche ) && '' !== $fiche ) {
		return $fiche;
	}
	$html  = tete_vue( __( 'Comptes inscrits', 'yume-core' ), '' );
	$html .= '<p><a href="' . esc_url( $etat['url_liste'] ) . '">' . esc_html__( '← Retour à la liste des comptes', 'yume-core' ) . '</a></p>';
	return $html . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'La fiche d’un compte sera bientôt disponible.', 'yume-core' ) . '</p></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Vue « Comptes inscrits » (?vue=comptes) : liste, ou fiche si ?compte=ID.
 */
function rendu_vue_comptes(): string {
	if ( ! function_exists( '\Yume\Core\Planning\ouvrir_racine' ) ) {
		return '';
	}
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--kpi yn-team--comptes' );
	$html .= navigation_equipe( VUE_CLE );
	$html .= '<div class="yn-team__principal">';

	if ( ! peut_voir_comptes() ) {
		$html .= tete_vue( __( 'Comptes inscrits', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><h2>' . esc_html__( 'Réservé aux gérants', 'yume-core' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'La liste des comptes inscrits est réservée aux gérants et aux administrateurs (données personnelles).', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}
	wp_enqueue_style( VUE_STYLE );

	$etat = vue_etat();
	if ( $etat['compte'] > 0 ) {
		return $html . vue_fiche( $etat ) . '</div></div>';
	}

	$indicateurs = vue_donnees( 'indicateurs' );
	$indicateurs = is_array( $indicateurs ) ? $indicateurs : null;
	$depuis      = vue_activite_depuis( $indicateurs );
	$html       .= vue_entete( $depuis );
	$html       .= vue_indicateurs( $indicateurs, $depuis );
	$html       .= vue_filtres( $etat['args'] );
	$html       .= vue_liste( $etat['args'] );
	$html       .= vue_journal();
	return $html . '</div></div>';
}
