<?php
/**
 * Espace équipe, vue « Œuvres » (?vue=oeuvres, capacité edit_yume_oeuvres : Éditeur Yume,
 * Gérant, administrateur) : toutes les œuvres du catalogue, brouillons compris ; formulaires
 * « Nouvelle œuvre » et « Modifier l'œuvre » (?vue=oeuvres&modifier=ID) ; genres.
 *
 * Le même formulaire crée ou modifie une œuvre : titre, titres alternatifs, type, statut de la
 * traduction, genres (existants ou nouveaux), auteur, illustrateur, éditeur VO, synopsis
 * (paragraphes, gras et italique conservés), couverture, et dans « Fiche détaillée » : statut
 * et nombre de tomes de la VO, source de la traduction, jours de sortie, équipe affichée,
 * liens externes. Une œuvre se crée en brouillon, ou publiée si le compte peut publier ;
 * publier une œuvre n'annonce rien (seuls les tomes sont annoncés). Le synopsis d'une œuvre
 * existante n'est réécrit que s'il a été modifié dans le formulaire (sa mise en forme
 * d'origine est sinon conservée telle quelle).
 *
 * Pour chaque œuvre : couverture, titre, statut, type, VO, ses tomes et leur état (regroupés :
 * « T1 à T6 ✓ Publiés »), son état modifiable (liste déroulante et « Changer », suggestion
 * « passer à Terminée » : oeuvres-etat.php) et les actions « Voir » (publiée), « Publier »
 * (brouillon), « Modifier », « Ajouter un tome au planning » (vue « Nouveau tome », œuvre
 * présélectionnée) et « Ajouter des chapitres ». Filtres GET statut, etat et recherche ; légende
 * des états. Le champ « État de l'œuvre » du formulaire « Modifier » passe par
 * changer_etat_oeuvre() (mêmes confirmations et effets).
 *
 * Genres (capacités edit_terms / delete_terms de yume_genre) : ajout et suppression dans la vue.
 *
 * Formulaires sans JavaScript (admin-post.php : yume_oeuvre_creer, yume_oeuvre_modifier,
 * yume_oeuvre_publier, yume_genre_equipe), résultat affiché au retour (retour_formulaire()).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

use Yume\Core\Publication\Fichiers;
use Yume\Core\Publication\Medias;

defined( 'ABSPATH' ) || exit;

/** Capacité de la vue et du formulaire. */
const CAPACITE_OEUVRES = 'edit_yume_oeuvres';

/** Longueur maximale du synopsis saisi (caractères). */
const SYNOPSIS_MAX = 5000;

/** Lignes vides proposées pour les liens externes. */
const LIENS_VIDES = 3;

/**
 * Filtre « Statut » de la vue : clé => libellé.
 *
 * @return array<string,string>
 */
function statuts_vue_oeuvres(): array {
	return array(
		''          => __( 'Toutes', 'yume-core' ),
		'publie'    => __( 'Publiées', 'yume-core' ),
		'brouillon' => __( 'Brouillons', 'yume-core' ),
	);
}

/**
 * Filtre « État » de la vue : '' (tous) puis les états de yume_etats_oeuvre().
 *
 * @return array<string,string>
 */
function etats_vue_oeuvres(): array {
	return array( '' => __( 'Tous', 'yume-core' ) ) + yume_etats_oeuvre();
}

/**
 * Œuvres de la vue, triées par titre, avec leurs tomes (une seule requête pour tous) et leur
 * état.
 *
 * @param array{statut?:string,recherche?:string,etat?:string} $filtres Filtres.
 * @return array<int,array{id:int,titre:string,statut:string,type:string,avancement:string,etat:string,tomes:int,tomes_etats:array,suggestion:bool}>
 */
function oeuvres_equipe( array $filtres = array() ): array {
	$statut    = (string) ( $filtres['statut'] ?? '' );
	$args      = array(
		'post_type'        => 'yume_oeuvre',
		'post_status'      => 'publie' === $statut ? array( 'publish', 'private' ) : ( 'brouillon' === $statut ? array( 'draft', 'pending', 'future' ) : array( 'publish', 'private', 'draft', 'pending', 'future' ) ),
		'posts_per_page'   => -1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
	);
	$recherche = trim( (string) ( $filtres['recherche'] ?? '' ) );
	if ( '' !== $recherche ) {
		$args['s']              = $recherche;
		$args['search_columns'] = array( 'post_title' );
	}
	$etat = (string) ( $filtres['etat'] ?? '' );
	if ( '' !== $etat ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'taxonomy' => 'yume_statut',
				'field'    => 'slug',
				'terms'    => array( $etat ),
			),
		);
	}
	$oeuvres = get_posts( $args );
	$tomes   = tomes_des_oeuvres( wp_list_pluck( $oeuvres, 'ID' ) );
	$liste   = array();
	foreach ( $oeuvres as $oeuvre ) {
		$id      = (int) $oeuvre->ID;
		$etats   = etats_tomes( $tomes[ $id ] ?? array() );
		$liste[] = array(
			'id'          => $id,
			'titre'       => titre_brut( $id ),
			'statut'      => (string) $oeuvre->post_status,
			'type'        => nom_terme_oeuvre( $id, 'yume_type' ),
			'avancement'  => nom_terme_oeuvre( $id, 'yume_statut' ),
			'etat'        => etat_oeuvre( $id ),
			'tomes'       => count( $etats ),
			'tomes_etats' => $etats,
			'suggestion'  => suggerer_terminee( $id, $etats ),
		);
	}
	usort(
		$liste,
		static function ( array $a, array $b ): int {
			return strnatcasecmp( remove_accents( $a['titre'] ), remove_accents( $b['titre'] ) );
		}
	);
	return $liste;
}

/**
 * Nom du premier terme d'une taxonomie de l'œuvre, ou chaîne vide.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $taxonomie Taxonomie.
 */
function nom_terme_oeuvre( int $oeuvre_id, string $taxonomie ): string {
	$termes = get_the_terms( $oeuvre_id, $taxonomie );
	return is_array( $termes ) && $termes ? (string) $termes[0]->name : '';
}

/**
 * Termes d'une taxonomie de l'œuvre (slug => nom), ordre du contrat.
 *
 * @param string $taxonomie Taxonomie.
 * @return array<string,string>
 */
function termes_taxonomie_oeuvre( string $taxonomie ): array {
	return \Yume\Core\Core\termes_taxonomie( $taxonomie );
}

/*
 * -----------------------------------------------------------------------------
 * Synopsis
 * -----------------------------------------------------------------------------
 */

/**
 * Balises conservées dans le synopsis saisi (mise en forme en ligne seulement).
 *
 * @return array<string,array>
 */
function balises_synopsis(): array {
	return array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
	);
}

/**
 * Synopsis saisi, nettoyé : sauts de ligne normalisés, gras et italique seulement, longueur
 * bornée.
 *
 * @param string $texte Texte saisi.
 */
function nettoyer_synopsis( string $texte ): string {
	$texte = str_replace( array( "\r\n", "\r" ), "\n", $texte );
	// Entités décodées : le texte se relit tel quel dans le formulaire (« & », pas « &amp; ») ;
	// synopsis_en_blocs() les réencode.
	$texte = html_entity_decode( wp_kses( $texte, balises_synopsis() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$texte = preg_replace( "/[ \t]+\n/", "\n", $texte );
	return mb_substr( trim( (string) $texte ), 0, SYNOPSIS_MAX );
}

/**
 * Synopsis en blocs paragraphe (un paragraphe par bloc de texte séparé d'une ligne vide).
 *
 * @param string $synopsis Texte nettoyé (nettoyer_synopsis()).
 */
function synopsis_en_blocs( string $synopsis ): string {
	$blocs = array();
	foreach ( preg_split( '/\n\s*\n/u', trim( $synopsis ) ) as $paragraphe ) {
		$paragraphe = trim( (string) $paragraphe );
		if ( '' !== $paragraphe ) {
			$blocs[] = "<!-- wp:paragraph -->\n<p>" . nl2br( wp_kses( $paragraphe, balises_synopsis() ), false ) . "</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $blocs );
}

/**
 * Synopsis d'une œuvre sous forme de texte à modifier : un paragraphe par bloc séparé d'une
 * ligne vide, gras et italique conservés, le reste de la mise en forme simplifié.
 *
 * @param int $oeuvre_id Œuvre.
 */
function synopsis_texte( int $oeuvre_id ): string {
	$contenu = (string) get_post_field( 'post_content', $oeuvre_id );
	if ( '' === trim( $contenu ) ) {
		$contenu = (string) get_post_field( 'post_excerpt', $oeuvre_id );
	}
	$contenu = (string) preg_replace( '/<!--.*?-->/s', '', $contenu );
	$parties = preg_match_all( '#<(?:p|h[1-6]|li|blockquote)[^>]*>(.*?)</(?:p|h[1-6]|li|blockquote)>#is', $contenu, $m ) ? $m[1] : preg_split( '/\R\s*\R/u', $contenu );
	$textes  = array();
	foreach ( $parties as $partie ) {
		$partie = (string) preg_replace( '#<br\s*/?>\s*#i', "\n", (string) $partie );
		$partie = trim( wp_kses( $partie, balises_synopsis() ) );
		if ( '' !== $partie ) {
			$textes[] = $partie;
		}
	}
	return nettoyer_synopsis( implode( "\n\n", $textes ) );
}

/**
 * Empreinte du synopsis affiché (savoir s'il a été modifié).
 *
 * @param string $texte Texte nettoyé.
 */
function empreinte_synopsis( string $texte ): string {
	return md5( nettoyer_synopsis( $texte ) );
}

/*
 * -----------------------------------------------------------------------------
 * Saisie et enregistrement
 * -----------------------------------------------------------------------------
 */

/**
 * Saisie du formulaire d'œuvre, nettoyée (sans le fichier de couverture).
 *
 * @param array $post Données POST (brutes, avec slashes).
 * @return array<string,mixed>
 */
function saisie_oeuvre( array $post ): array {
	$texte  = static function ( string $cle, int $max = 200 ) use ( $post ): string {
		$v = champ_post( $post, $cle );
		return is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, $max ) : '';
	};
	$genres = champ_post( $post, 'genres' );
	$genres = is_array( $genres ) ? array_values( array_unique( array_filter( array_map( 'sanitize_title', array_filter( $genres, 'is_scalar' ) ) ) ) ) : array();
	$autres = array();
	foreach ( explode( ',', $texte( 'nouveaux_genres', 300 ) ) as $nom ) {
		$nom = trim( $nom );
		if ( '' !== $nom && ! in_array( $nom, $autres, true ) ) {
			$autres[] = mb_substr( $nom, 0, 60 );
		}
	}
	$synopsis = champ_post( $post, 'synopsis' );
	$alt      = champ_post( $post, 'titres_alt' );
	$liens    = champ_post( $post, 'liens' );
	$nb_tomes = \Yume\Core\Core\san_entier( champ_post( $post, 'nb_tomes_vo' ) );
	$publier  = champ_post( $post, 'publier' );
	return array(
		'titre'             => $texte( 'titre' ),
		'titres_alt'        => \Yume\Core\Core\san_liste_textes( is_scalar( $alt ) ? (string) $alt : '' ),
		'type'              => sanitize_title( $texte( 'type', 60 ) ),
		'avancement'        => sanitize_title( $texte( 'avancement', 60 ) ),
		'genres'            => array_slice( $genres, 0, 40 ),
		'nouveaux_genres'   => array_slice( $autres, 0, 10 ),
		'auteur'            => $texte( 'auteur' ),
		'illustrateur'      => $texte( 'illustrateur' ),
		'editeur_vo'        => $texte( 'editeur_vo' ),
		'synopsis'          => is_scalar( $synopsis ) ? nettoyer_synopsis( (string) $synopsis ) : '',
		'synopsis_origine'  => preg_replace( '/[^a-f0-9]/', '', $texte( 'synopsis_origine', 32 ) ),
		'statut_vo'         => \Yume\Core\Core\san_enum( champ_post( $post, 'statut_vo' ), array( 'en_cours', 'termine' ) ),
		'nb_tomes_vo'       => $nb_tomes > 0 ? min( $nb_tomes, 999 ) : 0,
		'source_traduction' => $texte( 'source_traduction' ),
		'jours_sortie'      => \Yume\Core\Core\san_jours( (array) ( champ_post( $post, 'jours_sortie' ) ?? array() ) ),
		'equipe'            => \Yume\Core\Core\san_trio_textes( champ_post( $post, 'equipe' ) ),
		'liens'             => \Yume\Core\Core\san_liens( is_array( $liens ) ? array_values( $liens ) : array() ),
		'publier'           => '1' === ( is_scalar( $publier ) ? (string) $publier : '' ),
		'cadrage'           => cadrage_saisi( $post ),
	);
}

/**
 * Cadrage de la couverture saisi (curseurs horizontal et vertical, 0 à 100), ou null quand le
 * formulaire n'en contient pas.
 *
 * @param array $post Données POST.
 * @return array{x:int,y:int}|null
 */
function cadrage_saisi( array $post ): ?array {
	$x = champ_post( $post, 'cadrage_x' );
	$y = champ_post( $post, 'cadrage_y' );
	if ( ! is_numeric( $x ) || ! is_numeric( $y ) ) {
		return null;
	}
	return array(
		'x' => max( 0, min( 100, (int) round( (float) $x ) ) ),
		'y' => max( 0, min( 100, (int) round( (float) $y ) ) ),
	);
}

/**
 * Valeurs d'une œuvre existante, au format de saisie_oeuvre() (formulaire « Modifier »).
 *
 * @param int $id Œuvre.
 * @return array<string,mixed>
 */
function valeurs_oeuvre( int $id ): array {
	$meta     = static function ( string $cle ) use ( $id ) {
		return get_post_meta( $id, $cle, true );
	};
	$slugs    = static function ( string $taxonomie ) use ( $id ): array {
		$termes = get_the_terms( $id, $taxonomie );
		return is_array( $termes ) ? array_values( wp_list_pluck( $termes, 'slug' ) ) : array();
	};
	$type     = $slugs( 'yume_type' );
	$statut   = $slugs( 'yume_statut' );
	$equipe   = $meta( 'yume_equipe' );
	$synopsis = synopsis_texte( $id );
	return array(
		'titre'             => titre_brut( $id ),
		'titres_alt'        => array_values( array_filter( (array) $meta( 'yume_titres_alt' ), 'is_string' ) ),
		'type'              => $type ? $type[0] : '',
		'avancement'        => $statut ? $statut[0] : '',
		'genres'            => $slugs( 'yume_genre' ),
		'nouveaux_genres'   => array(),
		'auteur'            => (string) $meta( 'yume_auteur' ),
		'illustrateur'      => (string) $meta( 'yume_illustrateur' ),
		'editeur_vo'        => (string) $meta( 'yume_editeur_vo' ),
		'synopsis'          => $synopsis,
		'synopsis_origine'  => empreinte_synopsis( $synopsis ),
		'statut_vo'         => (string) $meta( 'yume_statut_vo' ),
		'nb_tomes_vo'       => (int) $meta( 'yume_nb_tomes_vo' ),
		'source_traduction' => (string) $meta( 'yume_source_traduction' ),
		'jours_sortie'      => \Yume\Core\Core\san_jours( (array) $meta( 'yume_jours_sortie' ) ),
		'equipe'            => \Yume\Core\Core\san_trio_textes( is_array( $equipe ) ? $equipe : array() ),
		'liens'             => \Yume\Core\Core\san_liens( (array) $meta( 'yume_liens' ) ),
		'publier'           => false,
		'cadrage'           => yume_cadrage_couverture( yume_get_cover_id( $id ) ),
	);
}

/**
 * Contrôles communs à la création et à la modification.
 *
 * @param array $saisie    Saisie nettoyée.
 * @param int   $oeuvre_id Œuvre modifiée (0 : création).
 * @return \WP_Error|null
 */
function valider_oeuvre( array $saisie, int $oeuvre_id ): ?\WP_Error {
	if ( '' === $saisie['titre'] ) {
		return new \WP_Error( 'yume_oeuvre_titre', __( 'Indiquez le titre de l’œuvre.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$existante = oeuvre_du_titre( $saisie['titre'], $oeuvre_id );
	if ( $existante ) {
		return new \WP_Error(
			'yume_oeuvre_existe',
			/* translators: %s : titre */
			sprintf( __( 'Une œuvre « %s » existe déjà : ouvrez-la depuis la liste des œuvres.', 'yume-core' ), $saisie['titre'] ),
			array(
				'status'    => 409,
				'oeuvre_id' => $existante,
			)
		);
	}
	if ( '' !== $saisie['type'] && ! isset( termes_taxonomie_oeuvre( 'yume_type' )[ $saisie['type'] ] ) ) {
		return new \WP_Error( 'yume_oeuvre_type', __( 'Type d’œuvre inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( '' !== $saisie['avancement'] && ! isset( termes_taxonomie_oeuvre( 'yume_statut' )[ $saisie['avancement'] ] ) ) {
		return new \WP_Error( 'yume_oeuvre_statut', __( 'État de l’œuvre inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	return null;
}

/**
 * Couverture téléversée, contrôlée, ou null (aucune).
 *
 * @param array|null $couverture Entrée de $_FILES.
 * @return array|\WP_Error|null
 */
function couverture_saisie( ?array $couverture ) {
	return $couverture && Fichiers::fourni( $couverture ) ? Fichiers::couverture( $couverture ) : null;
}

/**
 * Écrit les champs de la fiche (méta, type, statut, genres, couverture) d'une œuvre.
 *
 * @param int        $id      Œuvre.
 * @param array      $saisie  Saisie nettoyée.
 * @param array|null $fichier Couverture contrôlée (couverture_saisie()).
 * @param int        $user_id Utilisateur.
 * @return string[] Avertissements.
 */
function enregistrer_champs_oeuvre( int $id, array $saisie, ?array $fichier, int $user_id ): array {
	$equipe = array_filter( $saisie['equipe'] ) ? $saisie['equipe'] : '';
	foreach (
		array(
			'yume_titres_alt'        => $saisie['titres_alt'],
			'yume_auteur'            => $saisie['auteur'],
			'yume_illustrateur'      => $saisie['illustrateur'],
			'yume_editeur_vo'        => $saisie['editeur_vo'],
			'yume_statut_vo'         => $saisie['statut_vo'],
			'yume_nb_tomes_vo'       => $saisie['nb_tomes_vo'] ? $saisie['nb_tomes_vo'] : '',
			'yume_source_traduction' => $saisie['source_traduction'],
			'yume_jours_sortie'      => $saisie['jours_sortie'],
			'yume_equipe'            => $equipe,
			'yume_liens'             => $saisie['liens'],
		) as $cle => $valeur
	) {
		if ( array() === $valeur || '' === $valeur ) {
			delete_post_meta( $id, $cle );
		} else {
			update_post_meta( $id, $cle, $valeur );
		}
	}
	wp_set_object_terms( $id, '' !== $saisie['type'] ? array( $saisie['type'] ) : array(), 'yume_type' );
	wp_set_object_terms( $id, '' !== $saisie['avancement'] ? array( $saisie['avancement'] ) : array(), 'yume_statut' );

	$avert  = array();
	$genres = array_values( array_intersect( $saisie['genres'], array_keys( termes_taxonomie_oeuvre( 'yume_genre' ) ) ) );
	if ( $saisie['nouveaux_genres'] ) {
		$tax = get_taxonomy( 'yume_genre' );
		if ( $tax && user_can( $user_id, $tax->cap->edit_terms ) ) {
			foreach ( $saisie['nouveaux_genres'] as $nom ) {
				$terme = creer_genre( $nom );
				if ( $terme ) {
					$genres[] = $terme;
				}
			}
		} else {
			$avert[] = __( 'Nouveaux genres ignorés : votre rôle ne peut pas en créer (choisissez parmi les genres existants).', 'yume-core' );
		}
	}
	wp_set_object_terms( $id, array_values( array_unique( $genres ) ), 'yume_genre' );

	if ( $fichier ) {
		/* translators: %s : titre de l'œuvre */
		$cid = Medias::couverture( $fichier, $id, sprintf( __( 'Couverture — %s', 'yume-core' ), $saisie['titre'] ) );
		if ( is_wp_error( $cid ) ) {
			$avert[] = $cid->get_error_message();
		} else {
			set_post_thumbnail( $id, (int) $cid );
		}
	}
	// Cadrage de la couverture affichée (celle de l'œuvre, ou à défaut celle d'un tome).
	if ( null !== $saisie['cadrage'] ) {
		yume_enregistrer_cadrage( yume_get_cover_id( $id ), $saisie['cadrage'] );
	}
	return $avert;
}

/**
 * Crée un genre (ou retrouve celui qui porte déjà ce nom, casse ignorée) ; slug, ou chaîne vide.
 *
 * @param string $nom Nom.
 */
function creer_genre( string $nom ): string {
	$nom = trim( sanitize_text_field( $nom ) );
	if ( '' === $nom ) {
		return '';
	}
	foreach ( termes_taxonomie_oeuvre( 'yume_genre' ) as $slug => $existant ) {
		if ( mb_strtolower( $existant ) === mb_strtolower( $nom ) ) {
			return (string) $slug;
		}
	}
	$terme = wp_insert_term( mb_substr( $nom, 0, 60 ), 'yume_genre' );
	if ( is_wp_error( $terme ) ) {
		$id = (int) $terme->get_error_data( 'term_exists' );
		return $id ? (string) get_term_field( 'slug', $id, 'yume_genre' ) : '';
	}
	return (string) get_term_field( 'slug', (int) $terme['term_id'], 'yume_genre' );
}

/**
 * Crée une œuvre depuis l'espace équipe.
 *
 * @param array      $saisie     Saisie nettoyée (saisie_oeuvre()).
 * @param array|null $couverture Entrée de $_FILES de la couverture (facultative).
 * @param int        $user_id    Auteur.
 * @return array{id:int,avertissements:string[]}|\WP_Error
 */
function creer_oeuvre( array $saisie, ?array $couverture, int $user_id ) {
	$saisie      = array_merge( saisie_oeuvre( array() ), $saisie );
	$type_oeuvre = get_post_type_object( 'yume_oeuvre' );
	if ( ! $type_oeuvre || ! user_can( $user_id, $type_oeuvre->cap->create_posts ) ) {
		return new \WP_Error( 'yume_oeuvre_interdit', __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent créer une œuvre.', 'yume-core' ), array( 'status' => 403 ) );
	}
	$erreur = valider_oeuvre( $saisie, 0 );
	if ( $erreur ) {
		return $erreur;
	}
	$fichier = couverture_saisie( $couverture );
	if ( is_wp_error( $fichier ) ) {
		return $fichier;
	}
	$publier = $saisie['publier'] && user_can( $user_id, $type_oeuvre->cap->publish_posts );

	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'yume_oeuvre',
				'post_title'   => $saisie['titre'],
				'post_content' => synopsis_en_blocs( $saisie['synopsis'] ),
				'post_status'  => $publier ? 'publish' : 'draft',
				'post_author'  => $user_id,
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$id    = (int) $id;
	$avert = enregistrer_champs_oeuvre( $id, $saisie, $fichier, $user_id );

	/**
	 * Une œuvre vient d'être créée depuis l'espace équipe.
	 *
	 * @param int   $id      Œuvre.
	 * @param array $saisie  Saisie nettoyée.
	 * @param int   $user_id Auteur.
	 */
	do_action( 'yume_oeuvre_creee_equipe', $id, $saisie, $user_id );

	return array(
		'id'             => $id,
		'avertissements' => $avert,
	);
}

/**
 * Modifie une œuvre depuis l'espace équipe (statut de publication inchangé). L'état de l'œuvre
 * passe par changer_etat_oeuvre() : un état qui demande une confirmation (entrée dans
 * « Licenciée » ou sortie) n'est pas appliqué, la clé confirmer_etat du résultat le signale
 * (le formulaire mène alors à l'écran de confirmation). Un état vide ne change rien.
 *
 * @param int        $id         Œuvre.
 * @param array      $saisie     Saisie nettoyée (saisie_oeuvre()).
 * @param array|null $couverture Entrée de $_FILES d'une nouvelle couverture (facultative).
 * @param int        $user_id    Utilisateur.
 * @return array{id:int,avertissements:string[],confirmer_etat:string,etat:array|null}|\WP_Error
 */
function modifier_oeuvre( int $id, array $saisie, ?array $couverture, int $user_id ) {
	$saisie = array_merge( saisie_oeuvre( array() ), $saisie );
	if ( 'yume_oeuvre' !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
		return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	if ( ! user_can( $user_id, 'edit_post', $id ) ) {
		return new \WP_Error( 'yume_oeuvre_interdit', __( 'Votre rôle ne permet pas de modifier cette œuvre.', 'yume-core' ), array( 'status' => 403 ) );
	}
	$erreur = valider_oeuvre( $saisie, $id );
	if ( $erreur ) {
		return $erreur;
	}
	$fichier = couverture_saisie( $couverture );
	if ( is_wp_error( $fichier ) ) {
		return $fichier;
	}
	$maj = array(
		'ID'         => $id,
		'post_title' => $saisie['titre'],
	);
	// Synopsis réécrit seulement s'il a été modifié : sa mise en forme d'origine est conservée.
	if ( empreinte_synopsis( $saisie['synopsis'] ) !== $saisie['synopsis_origine'] ) {
		$maj['post_content'] = synopsis_en_blocs( $saisie['synopsis'] );
	}
	$resultat = wp_update_post( wp_slash( $maj ), true );
	if ( is_wp_error( $resultat ) ) {
		return $resultat;
	}
	// L'état n'est jamais écrit directement : changer_etat_oeuvre() ci-dessous.
	$avert   = enregistrer_champs_oeuvre( $id, array_merge( $saisie, array( 'avancement' => etat_oeuvre( $id ) ) ), $fichier, $user_id );
	$etat    = null;
	$attente = '';
	if ( '' !== $saisie['avancement'] ) {
		$etat = changer_etat_oeuvre( $id, $saisie['avancement'], $user_id );
		if ( is_wp_error( $etat ) ) {
			if ( 'yume_oeuvre_etat_confirmation' === $etat->get_error_code() ) {
				$attente = $saisie['avancement'];
			} else {
				$avert[] = $etat->get_error_message();
			}
			$etat = null;
		}
	}

	/**
	 * Une œuvre vient d'être modifiée depuis l'espace équipe.
	 *
	 * @param int   $id      Œuvre.
	 * @param array $saisie  Saisie nettoyée.
	 * @param int   $user_id Utilisateur.
	 */
	do_action( 'yume_oeuvre_modifiee_equipe', $id, $saisie, $user_id );

	return array(
		'id'             => $id,
		'avertissements' => $avert,
		'confirmer_etat' => $attente,
		'etat'           => $etat,
	);
}

/**
 * Œuvre existante (tous statuts vivants) portant ce titre, casse ignorée, ou 0. Compare les
 * titres décodés : WordPress enregistre « & » en « &amp; » selon le compte.
 *
 * @param string $titre Titre.
 * @param int    $sauf  Œuvre à ignorer (celle qu'on modifie).
 */
function oeuvre_du_titre( string $titre, int $sauf = 0 ): int {
	$cherche = mb_strtolower( trim( $titre ) );
	$ids     = get_posts(
		array(
			'post_type'        => 'yume_oeuvre',
			'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	foreach ( $ids as $id ) {
		if ( (int) $id !== $sauf && mb_strtolower( titre_brut( (int) $id ) ) === $cherche ) {
			return (int) $id;
		}
	}
	return 0;
}

/*
 * -----------------------------------------------------------------------------
 * Formulaires (admin-post)
 * -----------------------------------------------------------------------------
 */

/**
 * Traite le formulaire d'œuvre : création, ou modification si oeuvre_id est fourni.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param array $files   Fichiers ($_FILES).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,oeuvre_id:int,modifier?:int,saisie?:array}
 */
function traiter_formulaire_oeuvre( array $post, array $files, int $user_id ): array {
	$id     = isset( $post['oeuvre_id'] ) && is_scalar( $post['oeuvre_id'] ) ? absint( $post['oeuvre_id'] ) : 0;
	$nonce  = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$erreur = array(
		'cible'     => 'yn-oeuvre-form',
		'type'      => 'erreur',
		'oeuvre_id' => $id,
		'modifier'  => $id,
	);
	if ( ! wp_verify_nonce( $nonce, $id ? 'yume_oeuvre_modifier_' . $id : 'yume_oeuvre_creer' ) ) {
		return $erreur + array( 'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	$saisie     = saisie_oeuvre( $post );
	$couverture = isset( $files['couverture'] ) && is_array( $files['couverture'] ) ? $files['couverture'] : null;
	$resultat   = $id ? modifier_oeuvre( $id, $saisie, $couverture, $user_id ) : creer_oeuvre( $saisie, $couverture, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $erreur + array(
			'message' => $resultat->get_error_message(),
			'saisie'  => $saisie,
		);
	}
	$id = $resultat['id'];
	if ( ! empty( $resultat['confirmer_etat'] ) ) {
		// Entrée dans « Licenciée » ou sortie : fiche enregistrée, état à confirmer à part.
		return array(
			'cible'     => 'yn-oeuvre-etat',
			'type'      => 'ok',
			/* translators: %s : titre */
			'message'   => trim( sprintf( __( '« %s » est enregistrée. Son nouvel état touche les lecteurs : confirmez-le ci-dessous.', 'yume-core' ), titre_brut( $id ) ) . ' ' . implode( ' ', $resultat['avertissements'] ) ),
			'oeuvre_id' => $id,
			'changer'   => $id,
			'vers'      => (string) $resultat['confirmer_etat'],
		);
	}
	if ( isset( $post['oeuvre_id'] ) && absint( $post['oeuvre_id'] ) ) {
		/* translators: %s : titre */
		$message = sprintf( __( '« %s » est enregistrée.', 'yume-core' ), titre_brut( $id ) );
		if ( ! empty( $resultat['etat']['changement'] ) ) {
			$message .= ' ' . message_etat_oeuvre( $id, $resultat['etat'] );
		}
	} elseif ( 'publish' === get_post_status( $id ) ) {
		/* translators: %s : titre */
		$message = sprintf( __( '« %s » est créée et publiée. Ajoutez maintenant ses tomes.', 'yume-core' ), titre_brut( $id ) );
	} else {
		/* translators: %s : titre */
		$message = sprintf( __( '« %s » est créée en brouillon (invisible du public). Ajoutez ses tomes, puis publiez-la.', 'yume-core' ), titre_brut( $id ) );
	}
	if ( $resultat['avertissements'] ) {
		$message .= ' ' . implode( ' ', $resultat['avertissements'] );
	}
	return array(
		'cible'     => 'yn-oeuvre-' . $id,
		'type'      => 'ok',
		'message'   => $message,
		'oeuvre_id' => $id,
	);
}

/**
 * Traite le bouton « Publier » d'une œuvre en brouillon.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,oeuvre_id:int}
 */
function traiter_publication_oeuvre( array $post, int $user_id ): array {
	$id    = isset( $post['oeuvre_id'] ) && is_scalar( $post['oeuvre_id'] ) ? absint( $post['oeuvre_id'] ) : 0;
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$base  = array(
		'cible'     => $id ? 'yn-oeuvre-' . $id : 'yn-oeuvres-retour',
		'oeuvre_id' => $id,
	);
	if ( ! $id || ! wp_verify_nonce( $nonce, 'yume_oeuvre_publier_' . $id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	if ( 'yume_oeuvre' !== get_post_type( $id ) || ! in_array( get_post_status( $id ), array( 'draft', 'pending' ), true ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Cette œuvre n’est pas un brouillon.', 'yume-core' ),
		);
	}
	if ( ! user_can( $user_id, 'publish_post', $id ) || ! user_can( $user_id, 'edit_post', $id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre rôle ne permet pas de publier cette œuvre.', 'yume-core' ),
		);
	}
	$maj = wp_update_post(
		array(
			'ID'          => $id,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $maj ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => $maj->get_error_message(),
		);
	}
	return $base + array(
		'type'    => 'ok',
		/* translators: %s : titre */
		'message' => sprintf( __( '« %s » est publiée : sa fiche est visible dans la bibliothèque.', 'yume-core' ), titre_brut( $id ) ),
	);
}

/**
 * Traite l'ajout (op=ajouter, nom) ou la suppression (op=supprimer, genre) d'un genre.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string}
 */
function traiter_genre( array $post, int $user_id ): array {
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$op    = is_scalar( $post['op'] ?? null ) ? sanitize_key( (string) $post['op'] ) : '';
	$base  = array( 'cible' => 'yn-genres' );
	$tax   = get_taxonomy( 'yume_genre' );
	if ( ! wp_verify_nonce( $nonce, 'yume_genre_equipe' ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	if ( 'ajouter' === $op ) {
		if ( ! $tax || ! user_can( $user_id, $tax->cap->edit_terms ) ) {
			return $base + array(
				'type'    => 'erreur',
				'message' => __( 'Votre rôle ne permet pas de créer des genres.', 'yume-core' ),
			);
		}
		$noms  = array_filter( array_map( 'trim', explode( ',', (string) champ_post( $post, 'nom' ) ) ) );
		$crees = array();
		foreach ( array_slice( $noms, 0, 10 ) as $nom ) {
			$terme = get_term_by( 'slug', creer_genre( $nom ), 'yume_genre' );
			if ( $terme instanceof \WP_Term ) {
				$crees[] = $terme->name;
			}
		}
		return $base + ( $crees
			? array(
				'type'    => 'ok',
				/* translators: %s : genres */
				'message' => sprintf( __( 'Genres disponibles : %s.', 'yume-core' ), implode( ', ', $crees ) ),
			)
			: array(
				'type'    => 'erreur',
				'message' => __( 'Indiquez le nom du genre.', 'yume-core' ),
			) );
	}
	if ( 'supprimer' === $op ) {
		$terme = get_term( absint( champ_post( $post, 'genre' ) ), 'yume_genre' );
		if ( ! $tax || ! user_can( $user_id, $tax->cap->delete_terms ) ) {
			return $base + array(
				'type'    => 'erreur',
				'message' => __( 'Votre rôle ne permet pas de supprimer des genres.', 'yume-core' ),
			);
		}
		if ( ! $terme instanceof \WP_Term ) {
			return $base + array(
				'type'    => 'erreur',
				'message' => __( 'Genre introuvable.', 'yume-core' ),
			);
		}
		wp_delete_term( $terme->term_id, 'yume_genre' );
		return $base + array(
			'type'    => 'ok',
			/* translators: %s : genre */
			'message' => sprintf( __( 'Genre « %s » supprimé (retiré des œuvres qui l’avaient).', 'yume-core' ), $terme->name ),
		);
	}
	return $base + array(
		'type'    => 'erreur',
		'message' => __( 'Action inconnue.', 'yume-core' ),
	);
}

/**
 * Redirige vers la vue « Œuvres » après un formulaire (formulaire de modification rouvert en
 * cas d'erreur).
 *
 * @param array $retour Retour.
 */
function rediriger_vue_oeuvres( array $retour ): void {
	$args = ! empty( $retour['modifier'] ) ? array( 'modifier' => (int) $retour['modifier'] ) : array();
	if ( ! empty( $retour['changer'] ) ) {
		// Écran de confirmation d'un changement d'état (oeuvres-etat.php).
		$args = array(
			'changer' => (int) $retour['changer'],
			'vers'    => (string) ( $retour['vers'] ?? '' ),
		);
	}
	wp_safe_redirect( url_vue_equipe( 'oeuvres', $args ) . '#' . $retour['cible'] );
	exit;
}

/**
 * Formulaire d'œuvre (admin-post.php, actions yume_oeuvre_creer et yume_oeuvre_modifier).
 */
function admin_post_formulaire_oeuvre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_oeuvre().
	$retour = traiter_formulaire_oeuvre( $_POST, $_FILES, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_oeuvres( $retour );
}
add_action( 'admin_post_yume_oeuvre_creer', __NAMESPACE__ . '\\admin_post_formulaire_oeuvre' );
add_action( 'admin_post_yume_oeuvre_modifier', __NAMESPACE__ . '\\admin_post_formulaire_oeuvre' );

/**
 * Bouton « Publier » d'une œuvre (admin-post.php, action yume_oeuvre_publier).
 */
function admin_post_publier_oeuvre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_publication_oeuvre().
	$retour = traiter_publication_oeuvre( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_oeuvres( $retour );
}
add_action( 'admin_post_yume_oeuvre_publier', __NAMESPACE__ . '\\admin_post_publier_oeuvre' );

/**
 * Ajout ou suppression d'un genre (admin-post.php, action yume_genre_equipe).
 */
function admin_post_genre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_genre().
	$retour = traiter_genre( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_oeuvres( $retour );
}
add_action( 'admin_post_yume_genre_equipe', __NAMESPACE__ . '\\admin_post_genre' );

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire de création (0) ou de modification d'une œuvre.
 *
 * @param array|null $retour    Retour du dernier envoi (message, saisie à reprendre).
 * @param int        $oeuvre_id Œuvre modifiée (0 : nouvelle œuvre).
 */
function formulaire_oeuvre( ?array $retour, int $oeuvre_id = 0 ): string {
	$depuis_retour = is_array( $retour['saisie'] ?? null ) && (int) ( $retour['oeuvre_id'] ?? 0 ) === $oeuvre_id;
	$s             = $depuis_retour ? $retour['saisie'] : ( $oeuvre_id ? valeurs_oeuvre( $oeuvre_id ) : array() );
	$s             = array_merge( saisie_oeuvre( array() ), $s );
	$val           = static function ( string $cle ) use ( $s ): string {
		return is_scalar( $s[ $cle ] ?? null ) ? (string) $s[ $cle ] : '';
	};
	$max           = Fichiers::taille_max_couverture();
	$html          = '<form class="yn-card yn-team__ajout" id="yn-oeuvre-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-labelledby="yn-oeuvre-form-titre">';
	if ( $oeuvre_id ) {
		$html .= '<input type="hidden" name="action" value="yume_oeuvre_modifier"><input type="hidden" name="oeuvre_id" value="' . $oeuvre_id . '">';
		$html .= wp_nonce_field( 'yume_oeuvre_modifier_' . $oeuvre_id, '_yume_nonce', true, false );
		$html .= '<input type="hidden" name="synopsis_origine" value="' . esc_attr( $val( 'synopsis_origine' ) ) . '">';
	} else {
		$html .= '<input type="hidden" name="action" value="yume_oeuvre_creer">' . wp_nonce_field( 'yume_oeuvre_creer', '_yume_nonce', true, false );
	}
	$pour_moi = $retour && 'yn-oeuvre-form' === ( $retour['cible'] ?? '' ) && (int) ( $retour['oeuvre_id'] ?? 0 ) === $oeuvre_id;
	$html    .= zone_retour( $pour_moi ? $retour : null );
	$html    .= '<div class="yn-team__grille">';
	$html    .= champ_saisie(
		'yn-oeuvre-titre',
		'titre',
		__( 'Titre', 'yume-core' ),
		$val( 'titre' ),
		'text',
		array(
			'required'     => true,
			'maxlength'    => 200,
			'autocomplete' => 'off',
		)
	);
	$html    .= champ_select( 'yn-oeuvre-type', 'type', __( 'Type', 'yume-core' ), array( '' => __( '— Choisir —', 'yume-core' ) ) + termes_taxonomie_oeuvre( 'yume_type' ), $val( 'type' ) );
	$etats    = yume_etats_oeuvre();
	if ( ! $oeuvre_id || ! isset( $etats[ $val( 'avancement' ) ] ) ) {
		$etats = array( '' => $oeuvre_id ? __( '— Non renseigné —', 'yume-core' ) : __( '— Choisir —', 'yume-core' ) ) + $etats;
	}
	$champ = champ_select(
		'yn-oeuvre-avancement',
		'avancement',
		__( 'État de l’œuvre', 'yume-core' ),
		$etats,
		$oeuvre_id || '' !== $val( 'avancement' ) ? $val( 'avancement' ) : 'en-cours',
		$oeuvre_id ? array( 'aria-describedby' => 'yn-oeuvre-avancement-aide' ) : array()
	);
	if ( $oeuvre_id ) {
		// Entrée dans « Licenciée » ou sortie : écran de confirmation après l'enregistrement.
		$champ = str_replace( '</select></p>', '</select><span class="yn-muted" id="yn-oeuvre-avancement-aide">' . esc_html__( '« Licenciée » retire la lecture en ligne et les liens PDF/EPUB : une confirmation est demandée.', 'yume-core' ) . '</span></p>', $champ );
	}
	$html .= $champ;
	$html .= champ_saisie( 'yn-oeuvre-auteur', 'auteur', __( 'Auteur', 'yume-core' ), $val( 'auteur' ), 'text', array( 'maxlength' => 200 ) );
	$html .= champ_saisie( 'yn-oeuvre-illustrateur', 'illustrateur', __( 'Illustrateur', 'yume-core' ), $val( 'illustrateur' ), 'text', array( 'maxlength' => 200 ) );
	$html .= champ_saisie( 'yn-oeuvre-editeur', 'editeur_vo', __( 'Éditeur VO', 'yume-core' ), $val( 'editeur_vo' ), 'text', array( 'maxlength' => 200 ) );
	$html .= '</div>';

	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-alt">' . esc_html__( 'Titres alternatifs (un par ligne : titre original, titre anglais…)', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-oeuvre-alt" class="yn-team__court" name="titres_alt" rows="2">' . esc_textarea( implode( "\n", array_map( 'strval', (array) $s['titres_alt'] ) ) ) . '</textarea></p>';

	$genres = termes_taxonomie_oeuvre( 'yume_genre' );
	$coches = (array) $s['genres'];
	$html  .= '<fieldset class="yn-team__genres"><legend class="yn-label">' . esc_html__( 'Genres', 'yume-core' ) . '</legend>';
	if ( $genres ) {
		$html .= '<div class="yn-team__cases">';
		foreach ( $genres as $slug => $nom ) {
			$id    = 'yn-oeuvre-genre-' . sanitize_html_class( (string) $slug );
			$html .= '<label class="yn-team__case" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="genres[]" value="' . esc_attr( (string) $slug ) . '"' . checked( in_array( (string) $slug, $coches, true ), true, false ) . '> ' . esc_html( $nom ) . '</label>';
		}
		$html .= '</div>';
	}
	$tax = get_taxonomy( 'yume_genre' );
	if ( $tax && current_user_can( $tax->cap->edit_terms ) ) {
		$html .= champ_saisie(
			'yn-oeuvre-nouveaux-genres',
			'nouveaux_genres',
			__( 'Ajouter des genres absents de la liste (séparés par des virgules)', 'yume-core' ),
			implode( ', ', (array) $s['nouveaux_genres'] ),
			'text',
			array(
				'maxlength'   => 300,
				'placeholder' => __( 'ex. Cultivation, Gastronomie', 'yume-core' ),
			)
		);
	}
	$html .= '</fieldset>';

	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-synopsis">' . esc_html__( 'Synopsis', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-oeuvre-synopsis" name="synopsis" rows="7" maxlength="' . SYNOPSIS_MAX . '" aria-describedby="yn-oeuvre-synopsis-aide">' . esc_textarea( $val( 'synopsis' ) ) . '</textarea>';
	$html .= '<span class="yn-muted" id="yn-oeuvre-synopsis-aide">' . esc_html__( 'Une ligne vide sépare deux paragraphes. Gras : <strong>texte</strong> ; italique : <em>texte</em>.', 'yume-core' ) . '</span></p>';

	$couverture = $oeuvre_id ? yume_get_cover_id( $oeuvre_id ) : 0;
	$html      .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-couverture">' . esc_html( $couverture ? __( 'Remplacer la couverture (facultatif)', 'yume-core' ) : __( 'Couverture (facultative)', 'yume-core' ) ) . '</label>';
	$html      .= '<input type="file" id="yn-oeuvre-couverture" name="couverture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="yn-oeuvre-couverture-aide" data-yn-cadrage-fichier>';
	/* translators: %s : taille maximale */
	$html .= '<span class="yn-muted" id="yn-oeuvre-couverture-aide">' . esc_html( sprintf( __( 'JPG, PNG ou WebP, %s maximum. Portrait (2:3) de préférence.', 'yume-core' ), Fichiers::taille_lisible( $max ) ) ) . '</span></p>';
	$html .= champ_cadrage( $couverture, is_array( $s['cadrage'] ) ? $s['cadrage'] : null );

	$html .= formulaire_oeuvre_details( $s );

	$html .= '<p class="yn-team__action">';
	if ( $oeuvre_id ) {
		$html .= '<button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button>';
		$html .= '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'oeuvres' ) . '#yn-oeuvre-' . $oeuvre_id ) . '">' . esc_html__( 'Annuler', 'yume-core' ) . '</a>';
	} else {
		$publier = current_user_can( 'publish_yume_oeuvres' );
		$html   .= '<button type="submit" class="yn-btn' . ( $publier ? '' : ' yn-btn--primary' ) . '" name="publier" value="0">' . esc_html__( 'Créer en brouillon', 'yume-core' ) . '</button>';
		if ( $publier ) {
			$html .= '<button type="submit" class="yn-btn yn-btn--primary" name="publier" value="1">' . esc_html__( 'Créer et publier', 'yume-core' ) . '</button>';
		}
	}
	$html .= '</p>';
	if ( $oeuvre_id ) {
		$editeur = (string) get_edit_post_link( $oeuvre_id );
		$html   .= '<p class="yn-muted">' . esc_html__( 'Mise en forme avancée du synopsis (listes, liens, images) :', 'yume-core' ) . ' <a href="' . esc_url( $editeur ) . '">' . esc_html__( 'éditeur WordPress', 'yume-core' ) . '</a>.</p>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Publier une œuvre n’envoie aucune annonce (seuls les tomes sont annoncés).', 'yume-core' ) . '</p>';
	}
	return $html . '</form>';
}

/**
 * Réglage du cadrage de la couverture : aperçu au format des cartes (2:3) et curseurs
 * horizontal / vertical ; avec JavaScript, un clic dans l'aperçu place le point et une image
 * choisie dans le champ fichier est prévisualisée avant l'envoi.
 *
 * @param int        $couverture Couverture actuelle (0 : aucune).
 * @param array|null $cadrage    Cadrage actuel ou saisi.
 */
function champ_cadrage( int $couverture, ?array $cadrage ): string {
	$x     = $cadrage ? (int) $cadrage['x'] : 50;
	$y     = $cadrage ? (int) $cadrage['y'] : 50;
	$url   = $couverture ? (string) wp_get_attachment_image_url( $couverture, 'medium_large' ) : '';
	$html  = '<fieldset class="yn-team__genres yn-cadrage" data-yn-cadrage' . ( '' === $url ? ' data-yn-cadrage-vide' : '' ) . ' style="--yn-cadrage-x:' . $x . '%;--yn-cadrage-y:' . $y . '%">';
	$html .= '<legend class="yn-label">' . esc_html__( 'Cadrage de la couverture', 'yume-core' ) . '</legend>';
	$html .= '<div class="yn-cadrage__corps">';
	$html .= '<div class="yn-cadrage__cadre" data-yn-cadrage-apercu aria-hidden="true">';
	$html .= '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="" data-yn-cadrage-image>' : '<img src="" alt="" data-yn-cadrage-image hidden>';
	$html .= '<span class="yn-cadrage__point"></span></div>';
	$html .= '<div class="yn-cadrage__reglages">';
	$html .= '<p class="yn-muted" id="yn-cadrage-aide">' . esc_html__( 'Les cartes de la bibliothèque montrent la couverture au format portrait. Choisissez la partie de l’image à garder visible : cliquez dans l’aperçu ou réglez les curseurs. Centré par défaut.', 'yume-core' ) . '</p>';
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-cadrage-x">' . esc_html__( 'Horizontal (gauche → droite)', 'yume-core' ) . '</label>';
	$html .= '<input type="range" id="yn-cadrage-x" name="cadrage_x" min="0" max="100" step="1" value="' . $x . '" aria-describedby="yn-cadrage-aide" data-yn-cadrage-axe="x"></p>';
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-cadrage-y">' . esc_html__( 'Vertical (haut → bas)', 'yume-core' ) . '</label>';
	$html .= '<input type="range" id="yn-cadrage-y" name="cadrage_y" min="0" max="100" step="1" value="' . $y . '" aria-describedby="yn-cadrage-aide" data-yn-cadrage-axe="y"></p>';
	$html .= '<p><button type="button" class="yn-btn yn-btn--sm" data-yn-cadrage-centrer hidden>' . esc_html__( 'Recentrer', 'yume-core' ) . '</button></p>';
	$html .= '</div></div>';
	if ( '' === $url ) {
		$html .= '<p class="yn-muted yn-cadrage__sans-image">' . esc_html__( 'Choisissez une couverture pour voir l’aperçu.', 'yume-core' ) . '</p>';
	}
	return $html . '</fieldset>';
}

/**
 * Partie « Fiche détaillée » du formulaire d'œuvre (repliée si vide).
 *
 * @param array $s Valeurs (format de saisie_oeuvre()).
 */
function formulaire_oeuvre_details( array $s ): string {
	$remplie = '' !== $s['statut_vo'] || $s['nb_tomes_vo'] || '' !== $s['source_traduction'] || $s['jours_sortie'] || array_filter( (array) $s['equipe'] ) || $s['liens'];
	$html    = '<details class="yn-team__details"' . ( $remplie ? ' open' : '' ) . '><summary>' . esc_html__( 'Fiche détaillée : VO, jours de sortie, équipe, liens', 'yume-core' ) . '</summary>';
	$html   .= '<div class="yn-team__grille">';
	$html   .= champ_select(
		'yn-oeuvre-statut-vo',
		'statut_vo',
		__( 'Statut de la VO', 'yume-core' ),
		array(
			''         => __( '— Non renseigné —', 'yume-core' ),
			'en_cours' => __( 'En cours', 'yume-core' ),
			'termine'  => __( 'Terminée', 'yume-core' ),
		),
		(string) $s['statut_vo']
	);
	$html   .= champ_saisie(
		'yn-oeuvre-nb-tomes',
		'nb_tomes_vo',
		__( 'Tomes parus en VO', 'yume-core' ),
		$s['nb_tomes_vo'] ? (string) $s['nb_tomes_vo'] : '',
		'number',
		array(
			'min' => 0,
			'max' => 999,
		)
	);
	$html   .= champ_saisie(
		'yn-oeuvre-source',
		'source_traduction',
		__( 'Source de la traduction', 'yume-core' ),
		(string) $s['source_traduction'],
		'text',
		array(
			'maxlength'   => 200,
			'placeholder' => __( 'ex. Édition anglaise officielle (J-Novel Club)', 'yume-core' ),
		)
	);
	$html   .= '</div>';

	$html .= '<fieldset class="yn-team__genres"><legend class="yn-label">' . esc_html__( 'Jours de sortie', 'yume-core' ) . '</legend><div class="yn-team__cases">';
	foreach ( yume_jours_semaine() as $jour => $libelle ) {
		$html .= '<label class="yn-team__case" for="yn-oeuvre-jour-' . esc_attr( $jour ) . '"><input type="checkbox" id="yn-oeuvre-jour-' . esc_attr( $jour ) . '" name="jours_sortie[]" value="' . esc_attr( $jour ) . '"' . checked( in_array( $jour, (array) $s['jours_sortie'], true ), true, false ) . '> ' . esc_html( $libelle ) . '</label>';
	}
	$html .= '</div></fieldset>';

	$html .= '<fieldset class="yn-team__genres"><legend class="yn-label">' . esc_html__( 'Équipe affichée sur la fiche', 'yume-core' ) . '</legend><div class="yn-team__grille">';
	foreach (
		array(
			'traduction' => __( 'Traduction', 'yume-core' ),
			'relecture'  => __( 'Relecture', 'yume-core' ),
			'edition'    => __( 'Édition', 'yume-core' ),
		) as $etape => $libelle
	) {
		$html .= champ_saisie( 'yn-oeuvre-equipe-' . $etape, 'equipe[' . $etape . ']', $libelle, (string) ( $s['equipe'][ $etape ] ?? '' ), 'text', array( 'maxlength' => 200 ) );
	}
	$html .= '</div></fieldset>';

	$html  .= '<fieldset class="yn-team__genres"><legend class="yn-label">' . esc_html__( 'Liens externes (Novel-Index, MangaDex, éditeur, Discord…)', 'yume-core' ) . '</legend><div class="yn-team__liens">';
	$lignes = array_merge(
		(array) $s['liens'],
		array_fill(
			0,
			LIENS_VIDES,
			array(
				'label' => '',
				'url'   => '',
			)
		)
	);
	foreach ( array_values( $lignes ) as $i => $lien ) {
		/* translators: %d : numéro du lien */
		$html .= champ_saisie( 'yn-oeuvre-lien-' . $i . '-label', 'liens[' . $i . '][label]', sprintf( __( 'Libellé du lien %d', 'yume-core' ), $i + 1 ), (string) $lien['label'], 'text', array( 'maxlength' => 100 ) );
		/* translators: %d : numéro du lien */
		$html .= champ_saisie( 'yn-oeuvre-lien-' . $i . '-url', 'liens[' . $i . '][url]', sprintf( __( 'Adresse du lien %d', 'yume-core' ), $i + 1 ), (string) $lien['url'], 'url', array( 'placeholder' => 'https://' ) );
	}
	$html .= '</div><span class="yn-muted">' . esc_html__( 'Une ligne sans adresse est ignorée ; enregistrez pour obtenir de nouvelles lignes vides.', 'yume-core' ) . '</span></fieldset>';
	return $html . '</details>';
}

/**
 * Section « Genres » : genres existants (avec leur nombre d'œuvres), ajout et suppression.
 *
 * @param array|null $retour Retour du dernier envoi.
 */
function section_genres( ?array $retour ): string {
	$tax = get_taxonomy( 'yume_genre' );
	if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
		return '';
	}
	$termes = get_terms(
		array(
			'taxonomy'   => 'yume_genre',
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);
	$termes = is_array( $termes ) ? $termes : array();
	$ouvert = $retour && 'yn-genres' === ( $retour['cible'] ?? '' );
	$html   = '<section class="yn-team__section" id="yn-genres" aria-labelledby="yn-genres-titre"><details class="yn-card yn-team__details yn-team__genres-carte"' . ( $ouvert ? ' open' : '' ) . '>';
	/* translators: %d : nombre de genres */
	$html .= '<summary><h2 id="yn-genres-titre">' . esc_html( sprintf( _n( 'Genres (%d)', 'Genres (%d)', count( $termes ), 'yume-core' ), count( $termes ) ) ) . '</h2></summary>';
	$html .= zone_retour( $ouvert ? $retour : null );
	$html .= '<form class="yn-team__genre-ajout" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yume_genre_equipe"><input type="hidden" name="op" value="ajouter">' . wp_nonce_field( 'yume_genre_equipe', '_yume_nonce', true, false );
	$html .= champ_saisie(
		'yn-genre-nom',
		'nom',
		__( 'Nouveaux genres (séparés par des virgules)', 'yume-core' ),
		'',
		'text',
		array(
			'maxlength' => 300,
			'required'  => true,
		)
	);
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Ajouter', 'yume-core' ) . '</button></p></form>';
	if ( $termes ) {
		$supprimer = current_user_can( $tax->cap->delete_terms );
		$html     .= '<ul class="yn-team__genres-liste">';
		foreach ( $termes as $terme ) {
			/* translators: %d : nombre d'œuvres */
			$html .= '<li><span>' . esc_html( $terme->name ) . ' <span class="yn-muted">' . esc_html( sprintf( _n( '%d œuvre', '%d œuvres', (int) $terme->count, 'yume-core' ), (int) $terme->count ) ) . '</span></span>';
			if ( $supprimer ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="yume_genre_equipe"><input type="hidden" name="op" value="supprimer"><input type="hidden" name="genre" value="' . (int) $terme->term_id . '">' . wp_nonce_field( 'yume_genre_equipe', '_yume_nonce', true, false );
				$html .= '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Supprimer', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $terme->name ) . '</span></button></form>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
	}
	return $html . '</details></section>';
}

/**
 * Ligne de la VO d'une œuvre (« VO : 7 tomes, terminée »), ou chaîne vide.
 *
 * @param int $id Œuvre.
 */
function ligne_vo_oeuvre( int $id ): string {
	$nb      = (int) get_post_meta( $id, 'yume_nb_tomes_vo', true );
	$statut  = (string) get_post_meta( $id, 'yume_statut_vo', true );
	$parties = array();
	if ( $nb > 0 ) {
		/* translators: %d : nombre de tomes parus en VO */
		$parties[] = sprintf( _n( '%d tome', '%d tomes', $nb, 'yume-core' ), $nb );
	}
	if ( 'termine' === $statut ) {
		$parties[] = __( 'terminée', 'yume-core' );
	} elseif ( 'en_cours' === $statut ) {
		$parties[] = __( 'en cours', 'yume-core' );
	}
	/* translators: %s : tomes et statut de la VO (« 7 tomes, terminée ») */
	return $parties ? sprintf( __( 'VO : %s', 'yume-core' ), implode( ', ', $parties ) ) : '';
}

/**
 * Ligne d'une œuvre : couverture, titre, puces, tomes et leur état, état de l'œuvre, actions.
 *
 * @param array<string,mixed> $oeuvre Œuvre (voir oeuvres_equipe()).
 * @param array|null          $retour Retour du dernier envoi.
 */
function ligne_oeuvre_equipe( array $oeuvre, ?array $retour ): string {
	$id         = (int) $oeuvre['id'];
	$titre      = (string) $oeuvre['titre'];
	$couverture = yume_get_cover_id( $id );
	$contexte   = '<span class="yn-visually-hidden"> — ' . esc_html( $titre ) . '</span>';
	$publiee    = in_array( $oeuvre['statut'], array( 'publish', 'private' ), true );
	$html       = '<li class="yn-lecture__tome yn-oeuvres__ligne" id="yn-oeuvre-' . $id . '">';
	$html      .= '<span class="yn-lecture__couverture" aria-hidden="true">';
	$html      .= $couverture ? yume_image_couverture( $couverture, 'thumbnail', array( 'alt' => '' ) ) : '<span class="yn-lecture__sans-couverture">' . esc_html( mb_strtoupper( mb_substr( $titre, 0, 1 ) ) ) . '</span>';
	$html      .= '</span><div class="yn-lecture__infos"><p class="yn-lecture__libelle">' . esc_html( $titre ) . '</p><p class="yn-lecture__puces">';
	$html      .= $publiee
		? '<span class="yn-chip yn-chip--ok">' . esc_html__( 'Publiée', 'yume-core' ) . '</span>'
		: '<span class="yn-chip">' . esc_html__( 'Brouillon', 'yume-core' ) . '</span>';
	if ( '' !== $oeuvre['type'] ) {
		$html .= '<span class="yn-chip">' . esc_html( (string) $oeuvre['type'] ) . '</span>';
	}
	$vo    = ligne_vo_oeuvre( $id );
	$html .= '' !== $vo ? '<span class="yn-muted yn-tomes__date">' . esc_html( $vo ) . '</span>' : '';
	$html .= '</p>';
	// Tomes et leur état (Planifié, En cours de publication, Publié), regroupés.
	$html .= puces_tomes_oeuvre( (array) ( $oeuvre['tomes_etats'] ?? array() ) );
	$cible = 'yn-oeuvre-' . $id;
	$html .= zone_retour( $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null );
	$html .= '</div>' . bloc_etat_ligne( $oeuvre );
	// Div (et non p) : le bouton « Publier » est un formulaire.
	$html .= '<div class="yn-lecture__actions">';
	if ( $publiee ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir', 'yume-core' ) . $contexte . '</a>';
	} elseif ( current_user_can( 'publish_post', $id ) && current_user_can( 'edit_post', $id ) && in_array( $oeuvre['statut'], array( 'draft', 'pending' ), true ) ) {
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="yn-team__form-bouton"><input type="hidden" name="action" value="yume_oeuvre_publier"><input type="hidden" name="oeuvre_id" value="' . $id . '">';
		$html .= wp_nonce_field( 'yume_oeuvre_publier_' . $id, '_yume_nonce', true, false );
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-btn--primary">' . esc_html__( 'Publier', 'yume-core' ) . $contexte . '</button></form>';
	}
	if ( current_user_can( 'edit_post', $id ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_vue_equipe( 'oeuvres', array( 'modifier' => $id ) ) . '#yn-oeuvre-form' ) . '">' . esc_html__( 'Modifier', 'yume-core' ) . $contexte . '</a>';
	}
	if ( current_user_can( 'yume_maj_planning_tous' ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_nouveau_tome( $id, 'oeuvres' ) ) . '">' . esc_html__( 'Ajouter un tome au planning', 'yume-core' ) . $contexte . '</a>';
	}
	if ( current_user_can( 'yume_publier' ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( add_query_arg( 'oeuvre', $id, yume_url_page( 'publier' ) ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . $contexte . '</a>';
	}
	return $html . '</div></li>';
}

/**
 * Vue « Œuvres » (?vue=oeuvres ; ?modifier=ID : formulaire de modification).
 */
function rendu_vue_oeuvres(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--oeuvres' );
	$html .= navigation_equipe( 'oeuvres' );
	$html .= '<div class="yn-team__principal">';

	if ( ! current_user_can( CAPACITE_OEUVRES ) ) {
		$html .= tete_vue( __( 'Œuvres', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent créer et modifier les œuvres.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}

	$retour  = retour_formulaire( get_current_user_id() );
	$changer = get_entier( 'changer' );
	$vers    = get_cle( 'vers' );
	if ( $changer && isset( yume_etats_oeuvre()[ $vers ] ) && 'yume_oeuvre' === get_post_type( $changer ) && 'trash' !== get_post_status( $changer ) && current_user_can( 'edit_post', $changer ) ) {
		// Écran de confirmation d'un changement d'état (oeuvres-etat.php).
		$html .= rendu_confirmation_etat( $changer, $vers, $retour );
		return $html . '</div></div>';
	}
	$modifier = get_entier( 'modifier' );
	if ( $modifier && 'yume_oeuvre' === get_post_type( $modifier ) && 'trash' !== get_post_status( $modifier ) && current_user_can( 'edit_post', $modifier ) ) {
		/* translators: %s : titre de l'œuvre */
		$titre = sprintf( __( 'Modifier « %s »', 'yume-core' ), titre_brut( $modifier ) );
		$liens = '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'oeuvres' ) . '#yn-oeuvre-' . $modifier ) . '">' . esc_html__( 'Retour aux œuvres', 'yume-core' ) . '</a>';
		if ( in_array( get_post_status( $modifier ), array( 'publish', 'private' ), true ) ) {
			$liens .= '<a class="yn-btn" href="' . esc_url( (string) get_permalink( $modifier ) ) . '">' . esc_html__( 'Voir la fiche', 'yume-core' ) . '</a>';
		}
		$html .= tete_vue( $titre, $liens );
		$html .= '<section class="yn-team__section" aria-labelledby="yn-oeuvre-form-titre"><h2 id="yn-oeuvre-form-titre" class="yn-visually-hidden">' . esc_html( $titre ) . '</h2>';
		$html .= formulaire_oeuvre( $retour, $modifier ) . '</section>';
		return $html . '</div></div>';
	}

	$statut    = get_cle( 'statut' );
	$statut    = isset( statuts_vue_oeuvres()[ $statut ] ) ? $statut : '';
	$recherche = get_recherche_tomes();
	$etat      = get_cle( 'etat' );
	$etat      = isset( etats_vue_oeuvres()[ $etat ] ) ? $etat : '';
	$filtres   = array_filter(
		array(
			'statut'    => $statut,
			'etat'      => $etat,
			'recherche' => $recherche,
		)
	);
	$oeuvres   = oeuvres_equipe( $filtres );

	$html .= tete_vue( __( 'Œuvres', 'yume-core' ), '<a class="yn-btn yn-btn--primary" href="#yn-nouvelle-oeuvre-section">' . esc_html__( 'Nouvelle œuvre', 'yume-core' ) . '</a>' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Toutes les œuvres du catalogue, brouillons compris : « Modifier » ouvre la fiche complète. Une œuvre en brouillon reste invisible du public, mais on peut déjà lui ajouter des tomes au planning. L’état de chaque œuvre se change sur sa ligne ; l’état de ses tomes est rappelé à côté.', 'yume-core' ) . '</p>';
	$html .= '<div id="yn-oeuvres-retour">' . zone_retour( $retour && 'yn-oeuvres-retour' === ( $retour['cible'] ?? '' ) ? $retour : null ) . '</div>';

	// Filtres (GET, sans JavaScript).
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filtrer les œuvres', 'yume-core' ) . '">' . champs_caches_vue( 'oeuvres' );
	$html .= champ_select( 'yn-o-statut', 'statut', __( 'Statut', 'yume-core' ), statuts_vue_oeuvres(), $statut );
	$html .= champ_select( 'yn-o-etat', 'etat', __( 'État', 'yume-core' ), etats_vue_oeuvres(), $etat );
	$html .= champ_saisie( 'yn-o-recherche', 'recherche', __( 'Titre contient', 'yume-core' ), $recherche, 'search', array( 'maxlength' => 100 ) );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$html .= '<section class="yn-team__section" id="yn-oeuvres-liste" aria-labelledby="yn-oeuvres-liste-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-oeuvres-liste-titre">' . esc_html(
		$oeuvres
			/* translators: %d : nombre d'œuvres */
			? sprintf( _n( '%d œuvre', '%d œuvres', count( $oeuvres ), 'yume-core' ), count( $oeuvres ) )
			: __( 'Aucune œuvre', 'yume-core' )
	) . '</h2>';
	if ( $filtres ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'oeuvres' ) ) . '">' . esc_html__( 'Effacer les filtres', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	if ( $oeuvres ) {
		$html .= '<ul class="yn-card yn-lecture__tomes">';
		foreach ( $oeuvres as $oeuvre ) {
			$html .= ligne_oeuvre_equipe( $oeuvre, $retour );
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html(
			$filtres
				? __( 'Aucune œuvre ne correspond à ces filtres.', 'yume-core' )
				: __( 'Aucune œuvre pour le moment : créez la première ci-dessous.', 'yume-core' )
		) . '</p>';
	}
	$html .= '</section>';
	$html .= legende_etats_oeuvre();

	$html .= '<section class="yn-team__section" id="yn-nouvelle-oeuvre-section" aria-labelledby="yn-oeuvre-form-titre"><h2 id="yn-oeuvre-form-titre">' . esc_html__( 'Nouvelle œuvre', 'yume-core' ) . '</h2>';
	$html .= formulaire_oeuvre( $retour ) . '</section>';
	$html .= section_genres( $retour );

	return $html . '</div></div>';
}
