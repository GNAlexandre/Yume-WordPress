<?php
/**
 * Fonctions internes du module bibliothèque : contexte des blocs, visibilité, formats
 * français (dates, nombres, durées), adresses et libellés.
 *
 * Espace de noms réservé au module et à ses tests ; les autres modules passent par
 * les blocs ou par les filtres documentés.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/** Âge maximal (en jours) d'une sortie marquée « Nouveau ». */
const JOURS_NOUVEAU = 7;

/** Types de contenu affichés par le module. */
const TYPE_OEUVRE   = 'yume_oeuvre';
const TYPE_TOME     = 'yume_tome';
const TYPE_CHAPITRE = 'yume_chapitre';

/** Taxonomies des œuvres. */
const TAX_TYPE   = 'yume_type';
const TAX_STATUT = 'yume_statut';
const TAX_GENRE  = 'yume_genre';

/*
 * -----------------------------------------------------------------------------
 * Racine des blocs et aperçu de l'éditeur
 * -----------------------------------------------------------------------------
 */

/**
 * Attributs HTML de la racine d'un bloc (classes et styles de l'éditeur compris).
 *
 * Pendant le rendu d'un bloc, passe par get_block_wrapper_attributes() (alignement, classe
 * personnalisée, ancre) ; sinon (appel direct), construit les attributs à la main.
 *
 * @param string               $classe Classe racine du contrat (§10), toujours présente.
 * @param array<string,string> $extra  Attributs supplémentaires (class, style, aria-*…).
 */
function attributs_racine( string $classe, array $extra = array() ): string {
	$extra['class'] = trim( $classe . ' ' . ( $extra['class'] ?? '' ) );
	if ( class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
		return get_block_wrapper_attributes( $extra );
	}
	$html = '';
	foreach ( $extra as $nom => $valeur ) {
		$html .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return ltrim( $html );
}

/**
 * Rendu en cours dans l'éditeur de blocs (aperçu serveur de ServerSideRender) ?
 */
function apercu_editeur(): bool {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return false;
	}
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	return '' === $route || str_contains( $route, '/block-renderer/' );
}

/**
 * Rendu d'un bloc sans contexte : message discret dans l'éditeur, rien en façade.
 *
 * @param string $classe Classe racine.
 * @param string $texte  Message pour l'éditeur.
 */
function rendu_sans_contexte( string $classe, string $texte ): string {
	if ( ! apercu_editeur() ) {
		return '';
	}
	return '<div ' . attributs_racine( $classe . ' yn-apercu-editeur' ) . '><p class="yn-muted">' . esc_html( $texte ) . '</p></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Contexte : œuvre, tome, chapitre courants
 * -----------------------------------------------------------------------------
 */

/**
 * Contenu du contexte : postId du bloc (boucle de requête, modèle), sinon objet de la requête.
 *
 * @param \WP_Block|null $bloc      Instance du bloc.
 * @param string[]       $acceptes  Types de contenu acceptés dans le contexte du bloc.
 */
function id_contexte( $bloc, array $acceptes ): int {
	$candidats = array();
	if ( $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ) {
		$candidats[] = (int) $bloc->context['postId'];
	}
	$candidats[] = (int) get_queried_object_id();
	foreach ( $candidats as $id ) {
		if ( $id > 0 && in_array( get_post_type( $id ), $acceptes, true ) ) {
			return $id;
		}
	}
	return 0;
}

/**
 * Le contenu est-il consultable par l'utilisateur courant ? Publié : tous ; privé : droit
 * de lecture ; brouillon, programmé ou en attente : droit de modification (aperçu équipe).
 *
 * @param \WP_Post|int|null $post Contenu.
 */
function est_visible( $post ): bool {
	$post = get_post( $post );
	if ( ! $post instanceof \WP_Post ) {
		return false;
	}
	// Visibilité héritée : un tome ou un chapitre dont un parent est masqué l'est aussi.
	if ( function_exists( '\\Yume\\Core\\Core\\hierarchie_visible' ) && ! \Yume\Core\Core\hierarchie_visible( $post ) ) {
		return false;
	}
	switch ( $post->post_status ) {
		case 'publish':
			return true;
		case 'private':
			return current_user_can( 'read_post', $post->ID );
		case 'draft':
		case 'future':
		case 'pending':
			return current_user_can( 'edit_post', $post->ID );
	}
	return false;
}

/**
 * Œuvre du contexte (une œuvre, ou l'œuvre d'un tome ou d'un chapitre), visible, ou 0.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function oeuvre_contexte( $bloc = null ): int {
	$id = id_contexte( $bloc, array( TYPE_OEUVRE, TYPE_TOME, TYPE_CHAPITRE ) );
	if ( $id && TYPE_OEUVRE !== get_post_type( $id ) ) {
		$id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $id ) : 0;
	}
	return $id && est_visible( $id ) ? $id : 0;
}

/**
 * Tome du contexte (un tome, ou le tome d'un chapitre), visible, ou 0.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function tome_contexte( $bloc = null ): int {
	$id = id_contexte( $bloc, array( TYPE_TOME, TYPE_CHAPITRE ) );
	if ( $id && TYPE_CHAPITRE === get_post_type( $id ) ) {
		$id = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $id ) : 0;
	}
	return $id && est_visible( $id ) ? $id : 0;
}

/**
 * Chapitre du contexte, visible, ou 0.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function chapitre_contexte( $bloc = null ): int {
	$id = id_contexte( $bloc, array( TYPE_CHAPITRE ) );
	return $id && est_visible( $id ) ? $id : 0;
}

/*
 * -----------------------------------------------------------------------------
 * Formats français
 * -----------------------------------------------------------------------------
 */

/**
 * Mois abrégés à la française (index 1 à 12), identiques à ceux de WordPress en fr_FR,
 * pour un affichage 100 % français quelle que soit la langue installée.
 *
 * @return array<int,string>
 */
function mois_abreges(): array {
	return array(
		1  => __( 'janv.', 'yume-core' ),
		2  => __( 'févr.', 'yume-core' ),
		3  => __( 'mars', 'yume-core' ),
		4  => __( 'avr.', 'yume-core' ),
		5  => __( 'mai', 'yume-core' ),
		6  => __( 'juin', 'yume-core' ),
		7  => __( 'juil.', 'yume-core' ),
		8  => __( 'août', 'yume-core' ),
		9  => __( 'sept.', 'yume-core' ),
		10 => __( 'oct.', 'yume-core' ),
		11 => __( 'nov.', 'yume-core' ),
		12 => __( 'déc.', 'yume-core' ),
	);
}

/**
 * Date courte « 20 sept. » (fuseau du site). Chaîne vide pour un horodatage nul.
 *
 * @param int  $ts    Horodatage Unix.
 * @param bool $annee Ajouter l'année (« 20 sept. 2026 »).
 */
function date_courte( int $ts, bool $annee = false ): string {
	if ( $ts <= 0 ) {
		return '';
	}
	$mois  = mois_abreges();
	$texte = wp_date( 'j', $ts ) . ' ' . ( $mois[ (int) wp_date( 'n', $ts ) ] ?? '' );
	if ( $annee ) {
		$texte .= ' ' . wp_date( 'Y', $ts );
	}
	return $texte;
}

/**
 * Balise <time> d'une date courte, avec l'attribut datetime ISO 8601.
 *
 * @param int  $ts    Horodatage Unix.
 * @param bool $annee Afficher l'année.
 */
function balise_date( int $ts, bool $annee = false ): string {
	if ( $ts <= 0 ) {
		return '';
	}
	return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( date_courte( $ts, $annee ) ) . '</time>';
}

/**
 * Nombre entier à la française (« 84 000 », espace insécable).
 *
 * @param int $nombre Nombre.
 */
function nombre_fr( int $nombre ): string {
	return number_format( $nombre, 0, ',', "\u{00A0}" );
}

/**
 * Durée de lecture approximative : « ~18 min », « ~5 h 40 », « ~2 h » (espaces insécables).
 *
 * @param int $minutes Minutes.
 */
function duree_lecture( int $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}
	if ( $minutes < 60 ) {
		/* translators: %d : minutes. */
		$texte = sprintf( __( '~%d min', 'yume-core' ), $minutes );
	} else {
		$heures = intdiv( $minutes, 60 );
		$reste  = $minutes % 60;
		if ( 0 === $reste ) {
			/* translators: %d : heures. */
			$texte = sprintf( __( '~%d h', 'yume-core' ), $heures );
		} else {
			/* translators: 1 : heures, 2 : minutes sur deux chiffres. */
			$texte = sprintf( __( '~%1$d h %2$02d', 'yume-core' ), $heures, $reste );
		}
	}
	// Une durée ne se coupe pas en fin de ligne.
	return str_replace( ' ', "\u{00A0}", $texte );
}

/**
 * Durée ISO 8601 (« PT18M ») pour schema.org.
 *
 * @param int $minutes Minutes.
 */
function duree_iso( int $minutes ): string {
	return $minutes > 0 ? 'PT' . $minutes . 'M' : '';
}

/**
 * Horodatage GMT de publication d'un contenu (0 si inconnu).
 *
 * @param \WP_Post|int $post Contenu.
 */
function horodatage( $post ): int {
	$ts = get_post_time( 'U', true, $post );
	return is_numeric( $ts ) ? (int) $ts : 0;
}

/**
 * La sortie datée de $ts est-elle « nouvelle » (moins de 7 jours) ?
 *
 * @param int $ts Horodatage de la sortie.
 */
function est_nouveau( int $ts ): bool {
	$maintenant = time();
	return $ts > 0 && $ts <= $maintenant + HOUR_IN_SECONDS && ( $maintenant - $ts ) < JOURS_NOUVEAU * DAY_IN_SECONDS;
}

/*
 * -----------------------------------------------------------------------------
 * Textes et libellés
 * -----------------------------------------------------------------------------
 */

/**
 * Titre d'un contenu en texte brut (entités décodées, sans balise), à échapper en sortie.
 *
 * @param int $post_id ID.
 */
function titre( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}
	$titre = wp_strip_all_tags( (string) get_the_title( $post_id ) );
	return trim( html_entity_decode( $titre, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Métadonnée texte d'un contenu, nettoyée.
 *
 * @param int    $post_id ID.
 * @param string $cle     Clé.
 */
function meta_texte( int $post_id, string $cle ): string {
	$valeur = get_post_meta( $post_id, $cle, true );
	return is_scalar( $valeur ) ? trim( wp_strip_all_tags( (string) $valeur ) ) : '';
}

/**
 * Libellé d'un tome (API core), vide si l'API est absente.
 *
 * @param int  $tome_id ID du tome.
 * @param bool $court   Forme courte (« T.9 »).
 */
function libelle_tome( int $tome_id, bool $court = false ): string {
	return function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $tome_id, $court ) : titre( $tome_id );
}

/**
 * Libellé d'un chapitre (API core).
 *
 * @param int $chapitre_id ID du chapitre.
 */
function libelle_chapitre( int $chapitre_id ): string {
	return function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : titre( $chapitre_id );
}

/**
 * Sous-titre d'un tome tiré de son titre : « Œuvre — Arc 7 : Tournoi d'échec » donne
 * « Tournoi d'échec ». Vide si le titre ne contient que le libellé.
 *
 * @param int $tome_id ID du tome.
 */
function sous_titre_tome( int $tome_id ): string {
	$complet   = titre( $tome_id );
	$titre     = $complet;
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	$oeuvre    = $oeuvre_id ? titre( $oeuvre_id ) : '';
	$separes   = " \u{00A0}—–-:·|,";
	if ( '' !== $oeuvre && 0 === mb_stripos( $titre, $oeuvre ) ) {
		$titre = trim( ltrim( mb_substr( $titre, mb_strlen( $oeuvre ) ), $separes ) );
	}
	$libelle = libelle_tome( $tome_id );
	if ( '' !== $libelle && 0 === mb_stripos( $titre, $libelle ) ) {
		$reste = mb_substr( $titre, mb_strlen( $libelle ) );
		// Le libellé doit être suivi d'un séparateur (« Tome 1 » ne doit pas couper « Tome 12 »).
		if ( '' === $reste || preg_match( '/^[\s\x{00A0}]*[—–\-:·|,]/u', $reste ) ) {
			return trim( ltrim( $reste, $separes ) );
		}
		return '';
	}
	// Titre libre après le nom de l'œuvre (« Œuvre — Le Pays des ombres ») : c'est le sous-titre.
	return ( $titre !== $complet && '' !== $titre ) ? $titre : '';
}

/**
 * Types d'œuvre (slug => libellé) avec repli sur les termes du contrat.
 *
 * @return array<string,string>
 */
function types(): array {
	return function_exists( 'yume_types' ) ? yume_types() : array();
}

/**
 * Statuts de traduction (slug => libellé).
 *
 * @return array<string,string>
 */
function statuts(): array {
	return function_exists( 'yume_statuts' ) ? yume_statuts() : array();
}

/**
 * Libellé pluriel d'un type d'œuvre pour les menus (« Light novels », « Manga »).
 *
 * @param string $slug Slug du type.
 * @param string $nom  Nom du terme (repli).
 */
function libelle_type_pluriel( string $slug, string $nom ): string {
	$pluriels = array(
		'light-novel' => __( 'Light novels', 'yume-core' ),
		'web-novel'   => __( 'Web novels', 'yume-core' ),
		'manga'       => __( 'Manga', 'yume-core' ),
	);
	/**
	 * Filtre le libellé pluriel d'un type d'œuvre (menu et filtres de la bibliothèque).
	 *
	 * @param string $libelle Libellé.
	 * @param string $slug    Slug du type.
	 */
	return (string) apply_filters( 'yume_bibliotheque_libelle_type', $pluriels[ $slug ] ?? $nom, $slug );
}

/**
 * Apparence d'un statut de traduction : variante de pastille et icône (l'état ne se lit
 * jamais à la seule couleur : icône + libellé).
 *
 * @param string $slug Slug du statut.
 * @return array{variante:string,icone:string}
 */
function apparence_statut( string $slug ): array {
	$apparences = array(
		'en-cours'   => array( 'ok', '●' ),
		'terminee'   => array( 'info', '✓' ),
		'en-pause'   => array( 'warn', '‖' ),
		'licenciee'  => array( 'info', '©' ),
		'abandonnee' => array( 'err', '✕' ),
	);
	$apparence  = $apparences[ $slug ] ?? array( 'info', '•' );
	return array(
		'variante' => $apparence[0],
		'icone'    => $apparence[1],
	);
}

/**
 * Groupes de statuts proposés dans le menu et les filtres de la bibliothèque (maquette
 * d'accueil validée : « Séries en cours » — en cours et en pause —, « Séries terminées »,
 * « Licenciées / abandonnées »).
 *
 * @return array<string,array{libelle:string,statuts:string[]}> Clé = valeur du paramètre GET statut.
 */
function groupes_statuts(): array {
	$groupes = array(
		'en-cours,en-pause'    => array(
			'libelle' => __( 'Séries en cours', 'yume-core' ),
			'statuts' => array( 'en-cours', 'en-pause' ),
		),
		'terminee'             => array(
			'libelle' => __( 'Séries terminées', 'yume-core' ),
			'statuts' => array( 'terminee' ),
		),
		'licenciee,abandonnee' => array(
			'libelle' => __( 'Licenciées / abandonnées', 'yume-core' ),
			'statuts' => array( 'licenciee', 'abandonnee' ),
		),
	);
	/**
	 * Filtre les groupes de statuts du menu et des filtres de la bibliothèque.
	 *
	 * @param array $groupes Clé (valeur du paramètre statut, slugs séparés par des virgules)
	 *                       => array( 'libelle' => string, 'statuts' => string[] ).
	 */
	$groupes = (array) apply_filters( 'yume_bibliotheque_groupes_statuts', $groupes );
	$propres = array();
	foreach ( $groupes as $groupe ) {
		$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) ( $groupe['statuts'] ?? array() ) ) ) ) );
		if ( $slugs && ! empty( $groupe['libelle'] ) ) {
			$propres[ implode( ',', $slugs ) ] = array(
				'libelle' => (string) $groupe['libelle'],
				'statuts' => $slugs,
			);
		}
	}
	return $propres;
}

/*
 * -----------------------------------------------------------------------------
 * Adresses
 * -----------------------------------------------------------------------------
 */

/**
 * Adresse de la page Bibliothèque, avec des paramètres de filtre éventuels.
 *
 * @param array<string,string> $args Paramètres (type, statut, genre, tri).
 */
function url_bibliotheque( array $args = array() ): string {
	$url  = function_exists( 'yume_url_page' ) ? yume_url_page( 'bibliotheque' ) : home_url( '/bibliotheque/' );
	$args = array_filter( $args, 'strlen' );
	return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
}

/**
 * Adresse de la page courante sans paramètre (base des liens de filtre de la grille).
 */
function url_courante(): string {
	if ( is_singular() ) {
		$lien = get_permalink( get_queried_object_id() );
		if ( is_string( $lien ) && '' !== $lien ) {
			return $lien;
		}
	}
	if ( is_post_type_archive() ) {
		$type = get_query_var( 'post_type' );
		$lien = get_post_type_archive_link( is_array( $type ) ? (string) reset( $type ) : (string) $type );
		if ( is_string( $lien ) && '' !== $lien ) {
			return $lien;
		}
	}
	global $wp;
	$requete = ( $wp instanceof \WP && is_string( $wp->request ) ) ? trim( $wp->request, '/' ) : '';
	return '' === $requete ? home_url( '/' ) : home_url( user_trailingslashit( $requete ) );
}

/**
 * Adresse web affichable (http ou https, avec un hôte) ? Pas de résolution DNS : il s'agit
 * d'afficher un lien, non de faire une requête.
 *
 * @param string $url Adresse.
 */
function est_url_http( string $url ): bool {
	$url = trim( $url );
	if ( '' === $url ) {
		return false;
	}
	$schema = wp_parse_url( $url, PHP_URL_SCHEME );
	$hote   = wp_parse_url( $url, PHP_URL_HOST );
	return in_array( strtolower( (string) $schema ), array( 'http', 'https' ), true ) && is_string( $hote ) && '' !== $hote;
}

/**
 * Le lien mène-t-il hors du site ?
 *
 * @param string $url Adresse.
 */
function est_externe( string $url ): bool {
	$hote = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $hote ) || '' === $hote ) {
		return false;
	}
	$site = wp_parse_url( home_url(), PHP_URL_HOST );
	return strtolower( $hote ) !== strtolower( (string) $site );
}

/**
 * Adresse publique d'un contenu publié (vide sinon).
 *
 * @param int $post_id ID.
 */
function lien_public( int $post_id ): string {
	if ( $post_id <= 0 || ! est_visible( $post_id ) ) {
		return '';
	}
	$lien = get_permalink( $post_id );
	return is_string( $lien ) ? $lien : '';
}
