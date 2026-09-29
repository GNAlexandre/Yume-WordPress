<?php
/**
 * Espace équipe, vue « Œuvres » (?vue=oeuvres, capacité edit_yume_oeuvres : Éditeur Yume,
 * Gérant, administrateur) : toutes les œuvres du catalogue, brouillons compris, et le formulaire
 * « Nouvelle œuvre ».
 *
 * Le formulaire ne demande que l'essentiel (titre, titres alternatifs, type, statut de la
 * traduction, genres, auteur, illustrateur, éditeur VO, synopsis, couverture) et crée l'œuvre en
 * brouillon, ou publiée si le compte peut publier. Le reste de la fiche (liens, bannière, jours
 * de sortie, équipe affichée…) se complète dans l'éditeur complet (« Compléter la fiche »).
 * Publier une œuvre n'annonce rien (seuls les tomes sont annoncés).
 *
 * Pour chaque œuvre : couverture, titre, statut, type, statut de la traduction, nombre de tomes
 * et les actions « Voir » (publiée), « Publier » (brouillon), « Compléter la fiche »,
 * « Ajouter un tome au planning » et « Publier un tome ».
 *
 * Formulaires sans JavaScript (admin-post.php, actions yume_oeuvre_creer et yume_oeuvre_publier),
 * résultat affiché au retour (retour_formulaire()).
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
 * Œuvres de la vue, triées par titre.
 *
 * @param array{statut?:string,recherche?:string} $filtres Filtres.
 * @return array<int,array{id:int,titre:string,statut:string,type:string,avancement:string,tomes:int}>
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
	$liste = array();
	foreach ( get_posts( $args ) as $oeuvre ) {
		$id      = (int) $oeuvre->ID;
		$liste[] = array(
			'id'         => $id,
			'titre'      => titre_brut( $id ),
			'statut'     => (string) $oeuvre->post_status,
			'type'       => nom_terme_oeuvre( $id, 'yume_type' ),
			'avancement' => nom_terme_oeuvre( $id, 'yume_statut' ),
			'tomes'      => count( yume_get_tomes( $id, array( 'status' => 'any' ) ) ),
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

/*
 * -----------------------------------------------------------------------------
 * Création
 * -----------------------------------------------------------------------------
 */

/**
 * Saisie du formulaire « Nouvelle œuvre », nettoyée (sans le fichier de couverture).
 *
 * @param array $post Données POST (brutes, avec slashes).
 * @return array{titre:string,titres_alt:string[],type:string,avancement:string,genres:string[],nouveaux_genres:string[],auteur:string,illustrateur:string,editeur_vo:string,synopsis:string,publier:bool}
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
	$synopsis = is_scalar( $synopsis ) ? mb_substr( trim( sanitize_textarea_field( (string) $synopsis ) ), 0, SYNOPSIS_MAX ) : '';
	$alt      = champ_post( $post, 'titres_alt' );
	return array(
		'titre'           => $texte( 'titre' ),
		'titres_alt'      => \Yume\Core\Core\san_liste_textes( is_scalar( $alt ) ? (string) $alt : '' ),
		'type'            => sanitize_title( $texte( 'type', 60 ) ),
		'avancement'      => sanitize_title( $texte( 'avancement', 60 ) ),
		'genres'          => array_slice( $genres, 0, 30 ),
		'nouveaux_genres' => array_slice( $autres, 0, 10 ),
		'auteur'          => $texte( 'auteur' ),
		'illustrateur'    => $texte( 'illustrateur' ),
		'editeur_vo'      => $texte( 'editeur_vo' ),
		'synopsis'        => $synopsis,
		'publier'         => '1' === ( is_scalar( champ_post( $post, 'publier' ) ) ? (string) champ_post( $post, 'publier' ) : '' ),
	);
}

/**
 * Synopsis en blocs paragraphe (un paragraphe par bloc de texte séparé d'une ligne vide).
 *
 * @param string $synopsis Texte brut.
 */
function synopsis_en_blocs( string $synopsis ): string {
	$blocs = array();
	foreach ( preg_split( '/\R\s*\R/u', trim( $synopsis ) ) as $paragraphe ) {
		$paragraphe = trim( (string) $paragraphe );
		if ( '' !== $paragraphe ) {
			$blocs[] = "<!-- wp:paragraph -->\n<p>" . nl2br( esc_html( $paragraphe ), false ) . "</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $blocs );
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
	$type_oeuvre = get_post_type_object( 'yume_oeuvre' );
	if ( ! $type_oeuvre || ! user_can( $user_id, $type_oeuvre->cap->create_posts ) ) {
		return new \WP_Error( 'yume_oeuvre_interdit', __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent créer une œuvre.', 'yume-core' ), array( 'status' => 403 ) );
	}
	if ( '' === $saisie['titre'] ) {
		return new \WP_Error( 'yume_oeuvre_titre', __( 'Indiquez le titre de l’œuvre.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$existante = oeuvre_du_titre( $saisie['titre'] );
	if ( $existante ) {
		return new \WP_Error(
			'yume_oeuvre_existe',
			/* translators: %s : titre */
			sprintf( __( 'Une œuvre « %s » existe déjà : ouvrez-la depuis la liste ci-dessous.', 'yume-core' ), $saisie['titre'] ),
			array(
				'status'    => 409,
				'oeuvre_id' => $existante,
			)
		);
	}
	$types = termes_taxonomie_oeuvre( 'yume_type' );
	if ( '' !== $saisie['type'] && ! isset( $types[ $saisie['type'] ] ) ) {
		return new \WP_Error( 'yume_oeuvre_type', __( 'Type d’œuvre inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$avancements = termes_taxonomie_oeuvre( 'yume_statut' );
	if ( '' !== $saisie['avancement'] && ! isset( $avancements[ $saisie['avancement'] ] ) ) {
		return new \WP_Error( 'yume_oeuvre_statut', __( 'Statut de la traduction inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$fichier = null;
	if ( $couverture && Fichiers::fourni( $couverture ) ) {
		$fichier = Fichiers::couverture( $couverture );
		if ( is_wp_error( $fichier ) ) {
			return $fichier;
		}
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
	$id = (int) $id;
	foreach (
		array(
			'yume_titres_alt'   => $saisie['titres_alt'],
			'yume_auteur'       => $saisie['auteur'],
			'yume_illustrateur' => $saisie['illustrateur'],
			'yume_editeur_vo'   => $saisie['editeur_vo'],
		) as $cle => $valeur
	) {
		if ( array() !== $valeur && '' !== $valeur ) {
			update_post_meta( $id, $cle, $valeur );
		}
	}
	if ( '' !== $saisie['type'] ) {
		wp_set_object_terms( $id, $saisie['type'], 'yume_type' );
	}
	if ( '' !== $saisie['avancement'] ) {
		wp_set_object_terms( $id, $saisie['avancement'], 'yume_statut' );
	}

	$avert  = array();
	$genres = array_values( array_intersect( $saisie['genres'], array_keys( termes_taxonomie_oeuvre( 'yume_genre' ) ) ) );
	$tax    = get_taxonomy( 'yume_genre' );
	if ( $saisie['nouveaux_genres'] ) {
		if ( $tax && user_can( $user_id, $tax->cap->edit_terms ) ) {
			foreach ( $saisie['nouveaux_genres'] as $nom ) {
				$terme = term_exists( $nom, 'yume_genre' );
				if ( ! $terme ) {
					$terme = wp_insert_term( $nom, 'yume_genre' );
				}
				if ( is_array( $terme ) ) {
					$genres[] = (string) get_term_field( 'slug', (int) $terme['term_id'], 'yume_genre' );
				}
			}
		} else {
			$avert[] = __( 'Nouveaux genres ignorés : votre rôle ne peut pas en créer (choisissez parmi les genres existants).', 'yume-core' );
		}
	}
	if ( $genres ) {
		wp_set_object_terms( $id, array_values( array_unique( $genres ) ), 'yume_genre' );
	}

	if ( $fichier ) {
		/* translators: %s : titre de l'œuvre */
		$cid = Medias::couverture( $fichier, $id, sprintf( __( 'Couverture — %s', 'yume-core' ), $saisie['titre'] ) );
		if ( is_wp_error( $cid ) ) {
			$avert[] = $cid->get_error_message();
		} else {
			set_post_thumbnail( $id, (int) $cid );
		}
	}

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
 * Œuvre existante (tous statuts vivants) portant ce titre, casse ignorée, ou 0. Compare les
 * titres décodés : WordPress enregistre « & » en « &amp; » selon le compte.
 *
 * @param string $titre Titre.
 */
function oeuvre_du_titre( string $titre ): int {
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
		if ( mb_strtolower( titre_brut( (int) $id ) ) === $cherche ) {
			return (int) $id;
		}
	}
	return 0;
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

/**
 * Traite le formulaire « Nouvelle œuvre ».
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param array $files   Fichiers ($_FILES).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,oeuvre_id:int,saisie?:array}
 */
function traiter_formulaire_oeuvre( array $post, array $files, int $user_id ): array {
	$nonce = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'yume_oeuvre_creer' ) ) {
		return array(
			'cible'     => 'yn-nouvelle-oeuvre-form',
			'type'      => 'erreur',
			'message'   => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
			'oeuvre_id' => 0,
		);
	}
	$saisie   = saisie_oeuvre( $post );
	$resultat = creer_oeuvre( $saisie, isset( $files['couverture'] ) && is_array( $files['couverture'] ) ? $files['couverture'] : null, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return array(
			'cible'     => 'yn-nouvelle-oeuvre-form',
			'type'      => 'erreur',
			'message'   => $resultat->get_error_message(),
			'oeuvre_id' => 0,
			'saisie'    => $saisie,
		);
	}
	$id      = $resultat['id'];
	$publiee = 'publish' === get_post_status( $id );
	$message = sprintf(
		$publiee
			/* translators: %s : titre */
			? __( '« %s » est créée et publiée. Complétez la fiche (liens, bannière, jours de sortie) puis ajoutez ses tomes.', 'yume-core' )
			/* translators: %s : titre */
			: __( '« %s » est créée en brouillon (invisible du public). Complétez la fiche, ajoutez ses tomes, puis publiez-la.', 'yume-core' ),
		titre_brut( $id )
	);
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
 * Redirige vers la vue « Œuvres » après un formulaire.
 *
 * @param array $retour Retour.
 */
function rediriger_vue_oeuvres( array $retour ): void {
	wp_safe_redirect( url_vue_equipe( 'oeuvres' ) . '#' . $retour['cible'] );
	exit;
}

/**
 * Formulaire « Nouvelle œuvre » (admin-post.php, action yume_oeuvre_creer).
 */
function admin_post_creer_oeuvre(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_oeuvre().
	$retour = traiter_formulaire_oeuvre( $_POST, $_FILES, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_vue_oeuvres( $retour );
}
add_action( 'admin_post_yume_oeuvre_creer', __NAMESPACE__ . '\\admin_post_creer_oeuvre' );

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

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire « Nouvelle œuvre ».
 *
 * @param array|null $retour Retour du dernier envoi (message, saisie à reprendre).
 */
function formulaire_oeuvre( ?array $retour ): string {
	$s     = is_array( $retour['saisie'] ?? null ) ? $retour['saisie'] : array();
	$val   = static function ( string $cle ) use ( $s ): string {
		return is_scalar( $s[ $cle ] ?? null ) ? (string) $s[ $cle ] : '';
	};
	$max   = Fichiers::taille_max_couverture();
	$html  = '<form class="yn-card yn-team__ajout" id="yn-nouvelle-oeuvre-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-labelledby="yn-nouvelle-oeuvre">';
	$html .= '<input type="hidden" name="action" value="yume_oeuvre_creer">' . wp_nonce_field( 'yume_oeuvre_creer', '_yume_nonce', true, false );
	$html .= '<div class="yn-team__grille">';
	$html .= champ_saisie(
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
	$html .= champ_select( 'yn-oeuvre-type', 'type', __( 'Type', 'yume-core' ), array( '' => __( '— Choisir —', 'yume-core' ) ) + termes_taxonomie_oeuvre( 'yume_type' ), $val( 'type' ) );
	$html .= champ_select( 'yn-oeuvre-avancement', 'avancement', __( 'Statut de la traduction', 'yume-core' ), array( '' => __( '— Choisir —', 'yume-core' ) ) + termes_taxonomie_oeuvre( 'yume_statut' ), '' !== $val( 'avancement' ) ? $val( 'avancement' ) : 'en-cours' );
	$html .= champ_saisie( 'yn-oeuvre-auteur', 'auteur', __( 'Auteur', 'yume-core' ), $val( 'auteur' ), 'text', array( 'maxlength' => 200 ) );
	$html .= champ_saisie( 'yn-oeuvre-illustrateur', 'illustrateur', __( 'Illustrateur', 'yume-core' ), $val( 'illustrateur' ), 'text', array( 'maxlength' => 200 ) );
	$html .= champ_saisie( 'yn-oeuvre-editeur', 'editeur_vo', __( 'Éditeur VO', 'yume-core' ), $val( 'editeur_vo' ), 'text', array( 'maxlength' => 200 ) );
	$html .= '</div>';

	$alt   = is_array( $s['titres_alt'] ?? null ) ? implode( "\n", array_map( 'strval', $s['titres_alt'] ) ) : '';
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-alt">' . esc_html__( 'Titres alternatifs (un par ligne : titre original, titre anglais…)', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-oeuvre-alt" class="yn-team__court" name="titres_alt" rows="2">' . esc_textarea( $alt ) . '</textarea></p>';

	$genres = termes_taxonomie_oeuvre( 'yume_genre' );
	$coches = is_array( $s['genres'] ?? null ) ? $s['genres'] : array();
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
		$autres = is_array( $s['nouveaux_genres'] ?? null ) ? implode( ', ', $s['nouveaux_genres'] ) : '';
		$html  .= champ_saisie( 'yn-oeuvre-nouveaux-genres', 'nouveaux_genres', $genres ? __( 'Autres genres (séparés par des virgules)', 'yume-core' ) : __( 'Genres (séparés par des virgules)', 'yume-core' ), $autres, 'text', array( 'maxlength' => 300 ) );
	}
	$html .= '</fieldset>';

	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-synopsis">' . esc_html__( 'Synopsis', 'yume-core' ) . '</label>';
	$html .= '<textarea id="yn-oeuvre-synopsis" name="synopsis" rows="6" maxlength="' . SYNOPSIS_MAX . '" aria-describedby="yn-oeuvre-synopsis-aide">' . esc_textarea( $val( 'synopsis' ) ) . '</textarea>';
	$html .= '<span class="yn-muted" id="yn-oeuvre-synopsis-aide">' . esc_html__( 'Une ligne vide sépare deux paragraphes.', 'yume-core' ) . '</span></p>';

	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-oeuvre-couverture">' . esc_html__( 'Couverture (facultative)', 'yume-core' ) . '</label>';
	$html .= '<input type="file" id="yn-oeuvre-couverture" name="couverture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="yn-oeuvre-couverture-aide">';
	/* translators: %s : taille maximale */
	$html .= '<span class="yn-muted" id="yn-oeuvre-couverture-aide">' . esc_html( sprintf( __( 'JPG, PNG ou WebP, %s maximum. Portrait (2:3) de préférence.', 'yume-core' ), Fichiers::taille_lisible( $max ) ) ) . '</span></p>';

	$html .= '<p class="yn-team__action">';
	$html .= '<button type="submit" class="yn-btn' . ( current_user_can( 'publish_yume_oeuvres' ) ? '' : ' yn-btn--primary' ) . '" name="publier" value="0">' . esc_html__( 'Créer en brouillon', 'yume-core' ) . '</button>';
	if ( current_user_can( 'publish_yume_oeuvres' ) ) {
		$html .= ' <button type="submit" class="yn-btn yn-btn--primary" name="publier" value="1">' . esc_html__( 'Créer et publier', 'yume-core' ) . '</button>';
	}
	$html .= '</p><p class="yn-muted">' . esc_html__( 'Liens, bannière, jours de sortie et équipe affichée : « Compléter la fiche » une fois l’œuvre créée. Publier une œuvre n’envoie aucune annonce (seuls les tomes sont annoncés).', 'yume-core' ) . '</p>';
	$html .= zone_retour( $retour && 'yn-nouvelle-oeuvre-form' === ( $retour['cible'] ?? '' ) ? $retour : null );
	return $html . '</form>';
}

/**
 * Ligne d'une œuvre : couverture, titre, puces, actions.
 *
 * @param array<string,mixed> $oeuvre Œuvre (voir oeuvres_equipe()).
 * @param array|null          $retour Retour du dernier envoi.
 */
function ligne_oeuvre_equipe( array $oeuvre, ?array $retour ): string {
	$id         = (int) $oeuvre['id'];
	$titre      = (string) $oeuvre['titre'];
	$couverture = (int) get_post_thumbnail_id( $id );
	$contexte   = '<span class="yn-visually-hidden"> — ' . esc_html( $titre ) . '</span>';
	$publiee    = in_array( $oeuvre['statut'], array( 'publish', 'private' ), true );
	$html       = '<li class="yn-lecture__tome" id="yn-oeuvre-' . $id . '">';
	$html      .= '<span class="yn-lecture__couverture" aria-hidden="true">';
	$html      .= $couverture ? wp_get_attachment_image( $couverture, 'thumbnail', false, array( 'alt' => '' ) ) : '<span class="yn-lecture__sans-couverture">' . esc_html( mb_strtoupper( mb_substr( $titre, 0, 1 ) ) ) . '</span>';
	$html      .= '</span><div class="yn-lecture__infos"><p class="yn-lecture__libelle">' . esc_html( $titre ) . '</p><p class="yn-lecture__puces">';
	$html      .= $publiee
		? '<span class="yn-chip yn-chip--ok">' . esc_html__( 'Publiée', 'yume-core' ) . '</span>'
		: '<span class="yn-chip">' . esc_html__( 'Brouillon', 'yume-core' ) . '</span>';
	foreach ( array( $oeuvre['type'], $oeuvre['avancement'] ) as $puce ) {
		if ( '' !== $puce ) {
			$html .= '<span class="yn-chip">' . esc_html( (string) $puce ) . '</span>';
		}
	}
	$html .= '<span class="yn-muted yn-tomes__date">' . esc_html(
		$oeuvre['tomes']
			/* translators: %d : nombre de tomes */
			? sprintf( _n( '%d tome', '%d tomes', (int) $oeuvre['tomes'], 'yume-core' ), (int) $oeuvre['tomes'] )
			: __( 'Aucun tome', 'yume-core' )
	) . '</span></p>';
	$cible = 'yn-oeuvre-' . $id;
	$html .= zone_retour( $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null );
	// Div (et non p) : le bouton « Publier » est un formulaire.
	$html .= '</div><div class="yn-lecture__actions">';
	if ( $publiee ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'Voir', 'yume-core' ) . $contexte . '</a>';
	} elseif ( current_user_can( 'publish_post', $id ) && current_user_can( 'edit_post', $id ) && in_array( $oeuvre['statut'], array( 'draft', 'pending' ), true ) ) {
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="yn-team__form-bouton"><input type="hidden" name="action" value="yume_oeuvre_publier"><input type="hidden" name="oeuvre_id" value="' . $id . '">';
		$html .= wp_nonce_field( 'yume_oeuvre_publier_' . $id, '_yume_nonce', true, false );
		$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-btn--primary">' . esc_html__( 'Publier', 'yume-core' ) . $contexte . '</button></form>';
	}
	$modifier = current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id ) : '';
	if ( '' !== $modifier ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( $modifier ) . '">' . esc_html__( 'Compléter la fiche', 'yume-core' ) . $contexte . '</a>';
	}
	$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_vue_equipe( '', array( 'oeuvre_ajout' => $id ) ) . '#yn-ajouter-tome-section' ) . '">' . esc_html__( 'Ajouter un tome au planning', 'yume-core' ) . $contexte . '</a>';
	if ( current_user_can( 'yume_publier' ) ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( add_query_arg( 'oeuvre', $id, yume_url_page( 'publier' ) ) ) . '">' . esc_html__( 'Publier un tome', 'yume-core' ) . $contexte . '</a>';
	}
	return $html . '</div></li>';
}

/**
 * Vue « Œuvres » (?vue=oeuvres).
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

	$retour    = retour_formulaire( get_current_user_id() );
	$statut    = get_cle( 'statut' );
	$statut    = isset( statuts_vue_oeuvres()[ $statut ] ) ? $statut : '';
	$recherche = get_recherche_tomes();
	$filtres   = array_filter(
		array(
			'statut'    => $statut,
			'recherche' => $recherche,
		)
	);
	$oeuvres   = oeuvres_equipe( $filtres );

	$html .= tete_vue( __( 'Œuvres', 'yume-core' ), '<a class="yn-btn yn-btn--primary" href="#yn-nouvelle-oeuvre-section">' . esc_html__( 'Nouvelle œuvre', 'yume-core' ) . '</a>' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Toutes les œuvres du catalogue, brouillons compris. Une nouvelle œuvre est créée ici avec l’essentiel ; « Compléter la fiche » ouvre l’éditeur complet. Une œuvre en brouillon reste invisible du public, mais on peut déjà lui ajouter des tomes au planning.', 'yume-core' ) . '</p>';
	$html .= '<div id="yn-oeuvres-retour">' . zone_retour( $retour && 'yn-oeuvres-retour' === ( $retour['cible'] ?? '' ) ? $retour : null ) . '</div>';

	// Filtres (GET, sans JavaScript).
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" role="search" aria-label="' . esc_attr__( 'Filtrer les œuvres', 'yume-core' ) . '">' . champs_caches_vue( 'oeuvres' );
	$html .= champ_select( 'yn-o-statut', 'statut', __( 'Statut', 'yume-core' ), statuts_vue_oeuvres(), $statut );
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

	$html .= '<section class="yn-team__section" id="yn-nouvelle-oeuvre-section" aria-labelledby="yn-nouvelle-oeuvre"><h2 id="yn-nouvelle-oeuvre">' . esc_html__( 'Nouvelle œuvre', 'yume-core' ) . '</h2>';
	$html .= formulaire_oeuvre( $retour ) . '</section>';

	return $html . '</div></div>';
}
