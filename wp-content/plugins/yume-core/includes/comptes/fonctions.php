<?php
/**
 * Socle du module « comptes » : constantes partagées par tous les fichiers du module, droits,
 * adresse de la vue, accès aux fonctions de données (vue_donnees()), normalisation des
 * paramètres de la liste (args_comptes()), libellés, journal des consultations et horloge.
 *
 * Contrat figé (docs/06-contrat-technique.md §8 septies) : les lots A–D codent contre ces noms.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

/** Capacité de la vue (gérants et administrateurs) ; aucune capacité nouvelle. */
const CAPACITE = 'yume_voir_lecteurs';

/** Clé de la vue dans l'espace équipe (?vue=comptes). */
const VUE_CLE = 'comptes';

/** Poignée de la feuille de style de la vue (lot B). */
const VUE_STYLE = 'yume-comptes';

/** Méta utilisateur : jour de dernière activité, 'Y-m-d' à l'heure du site (jamais d'heure ni d'IP). */
const META_ACTIVITE = 'yume_derniere_activite';

/** Méta utilisateur : source d'inscription (inscription|discord|equipe). */
const META_SOURCE = 'yume_source_inscription';

/** Option (non autoloadée) : journal des consultations et exports, JOURNAL_MAX entrées. */
const OPTION_JOURNAL = 'yume_comptes_journal';

/** Option : amorçage de la méta d'activité fait (horodatage de l'amorçage). */
const OPTION_AMORCAGE = 'yume_comptes_activite_amorcee';

/** Option : début du relevé de l'activité ('Y-m-d', heure du site). */
const OPTION_DEPUIS = 'yume_comptes_activite_depuis';

/** Entrées gardées dans le journal. */
const JOURNAL_MAX = 200;

/** Comptes par page de la liste. */
const COMPTES_PAR_PAGE = 50;

/** Plafond de la taille de page. */
const COMPTES_PAGE_MAX = 100;

/** Longueur minimale de la recherche (caractères). */
const RECHERCHE_MIN = 2;

/** Longueur maximale de la recherche (caractères ; au-delà, coupée). */
const RECHERCHE_MAX = 60;

/** Sources d'inscription persistées (Social\SOURCES_COMPTE). */
const SOURCES = array( 'inscription', 'discord', 'equipe' );

/** Filtres de dernière activité (jours, inactifs = rien depuis 90 jours, inconnue = jamais relevée). */
const ACTIVITES = array( '7', '30', '90', 'inactifs', 'inconnue' );

/** Tris de la liste. */
const TRIS = array( 'inscription', 'pseudo', 'activite', 'lecture', 'favoris', 'commentaires' );

/*
 * -----------------------------------------------------------------------------
 * Droits
 * -----------------------------------------------------------------------------
 */

/**
 * Le compte peut-il voir la page « Comptes inscrits » (capacité yume_voir_lecteurs) ?
 *
 * @param int $user_id Compte (0 : compte courant).
 */
function peut_voir_comptes( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	return $user_id > 0 && user_can( $user_id, CAPACITE );
}

/**
 * Le compte peut-il voir l'adresse e-mail d'un compte (capacité list_users : gérants et
 * administrateurs, comme dans l'administration) ?
 *
 * @param int $user_id Compte (0 : compte courant).
 */
function peut_voir_email( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	return $user_id > 0 && user_can( $user_id, 'list_users' );
}

/*
 * -----------------------------------------------------------------------------
 * Adresse, données
 * -----------------------------------------------------------------------------
 */

/**
 * Ordre par défaut d'un tri : croissant pour le pseudo, décroissant sinon.
 *
 * @param string $tri Tri (TRIS).
 */
function ordre_par_defaut( string $tri ): string {
	return 'pseudo' === $tri ? 'asc' : 'desc';
}

/**
 * Adresse de la vue « Comptes inscrits » (Planning\url_vue_equipe( 'comptes', … )). Les
 * paramètres de la liste (clés de args_comptes()) sont normalisés et omis quand ils valent leur
 * défaut ; « page » devient « pg » (pagination de l'espace équipe), les cases cochées « 1 ».
 * Les autres clés (compte, …) sont transmises telles quelles.
 *
 * @param array $args Paramètres (q, role, source, discord, activite, inscrits, sans_lecture,
 *                    sans_mdp, tri, ordre, page ou pg, limite, compte…).
 */
function url_comptes( array $args = array() ): string {
	if ( isset( $args['pg'] ) && ! isset( $args['page'] ) ) {
		$args['page'] = $args['pg'];
	}
	unset( $args['pg'] );
	$defauts = args_comptes( array() );
	$normal  = args_comptes( $args );
	$sortie  = array();
	foreach ( $args as $cle => $valeur ) {
		if ( ! array_key_exists( $cle, $defauts ) ) {
			$sortie[ $cle ] = is_bool( $valeur ) ? ( $valeur ? '1' : '' ) : $valeur;
			continue;
		}
		$valeur = $normal[ $cle ];
		if ( 'ordre' === $cle ) {
			$omis = ordre_par_defaut( $normal['tri'] ) === $valeur;
		} else {
			$omis = $defauts[ $cle ] === $valeur;
		}
		if ( $omis ) {
			continue;
		}
		if ( 'page' === $cle ) {
			$sortie['pg'] = $valeur;
		} else {
			$sortie[ $cle ] = is_bool( $valeur ) ? '1' : $valeur;
		}
	}
	if ( function_exists( '\Yume\Core\Planning\url_vue_equipe' ) ) {
		return \Yume\Core\Planning\url_vue_equipe( VUE_CLE, $sortie );
	}
	return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', array_merge( array( 'vue' => VUE_CLE ), $sortie ) ) ), home_url( '/' ) );
}

/**
 * Résultat d'une fonction de données du module (lister_comptes(), indicateurs(), fiche_compte()…),
 * ou null si elle n'existe pas (fichier pas encore livré). Le filtre yume_comptes_vue_donnees
 * peut fournir le résultat à la place (tests, démonstrations) ; false y signifie « fonction
 * absente ».
 *
 * @param string $fonction Nom court de la fonction (espace de noms du module).
 * @param array  $args     Arguments positionnels.
 * @return mixed Résultat ; null si indisponible.
 */
function vue_donnees( string $fonction, array $args = array() ) {
	/**
	 * Court-circuite une fonction de données de la vue « Comptes inscrits ».
	 *
	 * @param mixed  $pre      null : appeler la fonction ; false : fonction absente ; sinon le résultat.
	 * @param string $fonction Nom court (lister_comptes, indicateurs, fiche_compte, journal_recent…).
	 * @param array  $args     Arguments.
	 */
	$pre = apply_filters( 'yume_comptes_vue_donnees', null, $fonction, $args );
	if ( false === $pre ) {
		return null;
	}
	if ( null !== $pre ) {
		return $pre;
	}
	if ( 1 !== preg_match( '/^[a-z_][a-z0-9_]*$/', $fonction ) || ! function_exists( __NAMESPACE__ . '\\' . $fonction ) ) {
		return null;
	}
	return call_user_func_array( __NAMESPACE__ . '\\' . $fonction, array_values( $args ) );
}

/**
 * Valeur scalaire d'un paramètre, en chaîne ('' si absente ou non scalaire).
 *
 * @param array  $args Paramètres.
 * @param string $cle  Clé.
 */
function parametre( array $args, string $cle ): string {
	$valeur = $args[ $cle ] ?? '';
	if ( is_bool( $valeur ) ) {
		return $valeur ? '1' : '';
	}
	return is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
}

/**
 * Paramètres de la liste normalisés (valeur inconnue → défaut). Les données sont attendues
 * déjà désechappées (wp_unslash( $_GET )).
 *
 * Clés :
 *   q            recherche, RECHERCHE_MIN à RECHERCHE_MAX caractères (plus court : '' ; plus long : coupé)
 *   role         tous (défaut) | lecteurs | equipe | slug d'un rôle existant
 *   source       toutes (défaut) | inscription | discord | equipe | inconnue
 *   discord      tous (défaut) | lie | non
 *   activite     toutes (défaut) | 7 | 30 | 90 | inactifs | inconnue
 *   inscrits     tout (défaut) | 7 | 30 | 90 | 365
 *   sans_lecture bool (case cochée : 1, on, true, oui)
 *   sans_mdp     bool
 *   tri          TRIS, inscription par défaut
 *   ordre        asc | desc ; défaut : asc pour pseudo, desc sinon (ordre_par_defaut())
 *   page         entier ≥ 1 (clé « pg » acceptée)
 *   limite       1 à COMPTES_PAGE_MAX, COMPTES_PAR_PAGE par défaut
 *
 * @param array $args Paramètres bruts.
 * @return array{q:string,role:string,source:string,discord:string,activite:string,inscrits:string,sans_lecture:bool,sans_mdp:bool,tri:string,ordre:string,page:int,limite:int}
 */
function args_comptes( array $args ): array {
	$cle   = static function ( string $nom ) use ( $args ): string {
		return sanitize_key( parametre( $args, $nom ) );
	};
	$parmi = static function ( string $valeur, array $permises, string $defaut ): string {
		return in_array( $valeur, $permises, true ) ? $valeur : $defaut;
	};
	$coche = static function ( string $nom ) use ( $args ): bool {
		return in_array( strtolower( parametre( $args, $nom ) ), array( '1', 'on', 'true', 'oui', 'yes' ), true );
	};

	$q = sanitize_text_field( parametre( $args, 'q' ) );
	if ( mb_strlen( $q ) < RECHERCHE_MIN ) {
		$q = '';
	} elseif ( mb_strlen( $q ) > RECHERCHE_MAX ) {
		$q = trim( mb_substr( $q, 0, RECHERCHE_MAX ) );
	}

	$role = $cle( 'role' );
	if ( ! in_array( $role, array( 'tous', 'lecteurs', 'equipe' ), true ) && ( '' === $role || ! wp_roles()->is_role( $role ) ) ) {
		$role = 'tous';
	}

	$tri   = $parmi( $cle( 'tri' ), TRIS, 'inscription' );
	$ordre = $parmi( $cle( 'ordre' ), array( 'asc', 'desc' ), ordre_par_defaut( $tri ) );

	$page = parametre( $args, 'page' );
	$page = '' !== $page ? $page : parametre( $args, 'pg' );
	$page = preg_match( '/^\d{1,9}$/', $page ) ? max( 1, (int) $page ) : 1;

	$limite = parametre( $args, 'limite' );
	$limite = preg_match( '/^\d{1,9}$/', $limite ) && (int) $limite > 0 ? min( COMPTES_PAGE_MAX, (int) $limite ) : COMPTES_PAR_PAGE;

	return array(
		'q'            => $q,
		'role'         => $role,
		'source'       => $parmi( $cle( 'source' ), array_merge( array( 'toutes' ), SOURCES, array( 'inconnue' ) ), 'toutes' ),
		'discord'      => $parmi( $cle( 'discord' ), array( 'tous', 'lie', 'non' ), 'tous' ),
		'activite'     => $parmi( $cle( 'activite' ), array_merge( array( 'toutes' ), ACTIVITES ), 'toutes' ),
		'inscrits'     => $parmi( $cle( 'inscrits' ), array( 'tout', '7', '30', '90', '365' ), 'tout' ),
		'sans_lecture' => $coche( 'sans_lecture' ),
		'sans_mdp'     => $coche( 'sans_mdp' ),
		'tri'          => $tri,
		'ordre'        => $ordre,
		'page'         => $page,
		'limite'       => $limite,
	);
}

/*
 * -----------------------------------------------------------------------------
 * Libellés
 * -----------------------------------------------------------------------------
 */

/**
 * Libellés des sources d'inscription (inconnue : ancien compte dont la source n'a pu être déduite).
 *
 * @return array<string,string>
 */
function libelles_sources(): array {
	return array(
		'inscription' => __( 'Formulaire du site', 'yume-core' ),
		'discord'     => __( 'Discord', 'yume-core' ),
		'equipe'      => __( 'Équipe', 'yume-core' ),
		'inconnue'    => __( 'Inconnue', 'yume-core' ),
	);
}

/**
 * Choix du filtre « Rôle » : tous, lecteurs, équipe, puis chaque rôle existant (slug => nom
 * traduit, dans l'ordre des rôles du site).
 *
 * @return array<string,string>
 */
function libelles_roles(): array {
	$libelles = array(
		'tous'     => __( 'Tous les rôles', 'yume-core' ),
		'lecteurs' => __( 'Lecteurs', 'yume-core' ),
		'equipe'   => __( 'Équipe (tous les rôles)', 'yume-core' ),
	);
	foreach ( wp_roles()->roles as $slug => $role ) {
		if ( ! isset( $libelles[ $slug ] ) ) {
			$libelles[ $slug ] = translate_user_role( (string) ( $role['name'] ?? $slug ) );
		}
	}
	return $libelles;
}

/*
 * -----------------------------------------------------------------------------
 * Journal des consultations
 * -----------------------------------------------------------------------------
 */

/**
 * Contexte d'une entrée du journal : clés sanitize_key, valeurs scalaires seulement (texte
 * assaini et coupé à 200 caractères), 20 clés au plus.
 *
 * @param array $contexte Contexte brut.
 * @return array<string,int|float|bool|string>
 */
function contexte_journal( array $contexte ): array {
	$propre = array();
	foreach ( $contexte as $cle => $valeur ) {
		$cle = sanitize_key( (string) $cle );
		if ( '' === $cle || ! is_scalar( $valeur ) ) {
			continue;
		}
		$propre[ $cle ] = is_string( $valeur ) ? mb_substr( sanitize_text_field( $valeur ), 0, 200 ) : $valeur;
		if ( count( $propre ) >= 20 ) {
			break;
		}
	}
	return $propre;
}

/**
 * Ajoute une entrée en tête du journal des consultations (option non autoloadée, JOURNAL_MAX
 * entrées, les plus anciennes sont retirées) : {quand (horodatage), acteur, compte, action, nb,
 * contexte}. Actions du module : fiche, export, export_email.
 *
 * @param string $action    Action (sanitize_key ; vide : rien n'est écrit).
 * @param int    $acteur    Compte qui consulte.
 * @param int    $compte_id Compte consulté (0 : export de la liste).
 * @param int    $nb        Nombre de comptes concernés (export).
 * @param array  $contexte  Précisions (filtres de l'export…), valeurs scalaires.
 */
function journaliser( string $action, int $acteur, int $compte_id, int $nb = 0, array $contexte = array() ): void {
	$action = sanitize_key( $action );
	if ( '' === $action ) {
		return;
	}
	$journal = get_option( OPTION_JOURNAL, array() );
	$journal = is_array( $journal ) ? array_values( $journal ) : array();
	array_unshift(
		$journal,
		array(
			'quand'    => maintenant(),
			'acteur'   => max( 0, $acteur ),
			'compte'   => max( 0, $compte_id ),
			'action'   => $action,
			'nb'       => max( 0, $nb ),
			'contexte' => contexte_journal( $contexte ),
		)
	);
	update_option( OPTION_JOURNAL, array_slice( $journal, 0, JOURNAL_MAX ), false );
}

/**
 * Dernières entrées du journal, de la plus récente à la plus ancienne.
 *
 * @param int $max Nombre d'entrées (1 à JOURNAL_MAX).
 * @return array<int,array{quand:int,acteur:int,compte:int,action:string,nb:int,contexte:array}>
 */
function journal_recent( int $max = 20 ): array {
	$journal = get_option( OPTION_JOURNAL, array() );
	$lignes  = array();
	foreach ( is_array( $journal ) ? $journal : array() as $entree ) {
		if ( ! is_array( $entree ) || empty( $entree['action'] ) ) {
			continue;
		}
		$lignes[] = array(
			'quand'    => (int) ( $entree['quand'] ?? 0 ),
			'acteur'   => (int) ( $entree['acteur'] ?? 0 ),
			'compte'   => (int) ( $entree['compte'] ?? 0 ),
			'action'   => (string) $entree['action'],
			'nb'       => (int) ( $entree['nb'] ?? 0 ),
			'contexte' => is_array( $entree['contexte'] ?? null ) ? $entree['contexte'] : array(),
		);
		if ( count( $lignes ) >= max( 1, min( JOURNAL_MAX, $max ) ) ) {
			break;
		}
	}
	return $lignes;
}

/*
 * -----------------------------------------------------------------------------
 * Horloge
 * -----------------------------------------------------------------------------
 */

/**
 * Horodatage courant. Le filtre permet aux tests de simuler une date donnée.
 */
function maintenant(): int {
	/**
	 * Filtre l'horodatage « maintenant » du module comptes (tests, simulations).
	 *
	 * @param int $ts Horodatage Unix.
	 */
	return (int) apply_filters( 'yume_comptes_maintenant', time() );
}
