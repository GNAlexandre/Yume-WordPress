<?php
/**
 * Données personnelles (RGPD) : export JSON du compte, effacement des données du module,
 * suppression du compte en façade (jamais pour l'équipe ni un administrateur), et
 * enregistrement de l'exportateur et de l'effaceur dans les outils de confidentialité de
 * WordPress (Outils → Exporter / Effacer les données personnelles).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Titre et adresse d'une œuvre (même non publiée : l'export est exhaustif).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{titre:string,url:string}
 */
function reference_oeuvre( int $oeuvre_id ): array {
	$post = get_post( $oeuvre_id );
	if ( ! $post ) {
		return array(
			'titre' => '',
			'url'   => '',
		);
	}
	return array(
		'titre' => wp_strip_all_tags( get_the_title( $post ) ),
		'url'   => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
	);
}

/**
 * Toutes les données personnelles d'un membre (export du compte, GET /moi/export).
 *
 * @param int $user_id Utilisateur.
 * @return array<string,mixed>
 */
function donnees_personnelles( int $user_id ): array {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array();
	}

	$favoris = array();
	foreach ( favoris_utilisateur( $user_id ) as $ligne ) {
		$ref       = reference_oeuvre( $ligne['oeuvre_id'] );
		$favoris[] = array(
			'oeuvre_id' => $ligne['oeuvre_id'],
			'oeuvre'    => $ref['titre'],
			'url'       => $ref['url'],
			'alerte'    => $ligne['frequence'],
			'ajoute_le' => iso( $ligne['created_at'] ),
		);
	}

	$notes = array();
	foreach ( notes_utilisateur( $user_id ) as $ligne ) {
		$ref     = reference_oeuvre( $ligne['oeuvre_id'] );
		$notes[] = array(
			'oeuvre_id' => $ligne['oeuvre_id'],
			'oeuvre'    => $ref['titre'],
			'note'      => $ligne['note'],
			'le'        => iso( $ligne['updated_at'] ),
		);
	}

	$progression = array();
	foreach ( yume_get_progression( $user_id ) as $ligne ) {
		$ref           = reference_oeuvre( $ligne['oeuvre_id'] );
		$progression[] = array(
			'oeuvre_id'   => $ligne['oeuvre_id'],
			'oeuvre'      => $ref['titre'],
			'tome_id'     => $ligne['tome_id'],
			'chapitre_id' => $ligne['chapitre_id'],
			'chapitre'    => wp_strip_all_tags( get_the_title( $ligne['chapitre_id'] ) ),
			'paragraphe'  => $ligne['paragraphe'],
			'pourcentage' => $ligne['pourcentage'],
			'le'          => iso( $ligne['updated_at'] ),
		);
	}

	$commentaires = array();
	$liste        = get_comments(
		array(
			'user_id' => $user_id,
			'status'  => 'all',
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
			'number'  => 5000,
		)
	);
	foreach ( (array) $liste as $commentaire ) {
		$commentaires[] = array(
			'id'      => (int) $commentaire->comment_ID,
			'sur'     => wp_strip_all_tags( get_the_title( (int) $commentaire->comment_post_ID ) ),
			'url'     => (string) get_comment_link( $commentaire ),
			'date'    => iso( (string) $commentaire->comment_date_gmt ),
			'statut'  => (string) wp_get_comment_status( $commentaire ),
			'contenu' => (string) $commentaire->comment_content,
		);
	}

	$reglages = function_exists( '\Yume\Core\Reader\reglages_enregistres' ) ? \Yume\Core\Reader\reglages_enregistres( $user_id ) : null;
	$attente  = get_user_meta( $user_id, META_EMAIL_ATTENTE, true );

	return array(
		'format'              => 'yume-export-1',
		'genere_le'           => gmdate( 'c' ),
		'site'                => home_url( '/' ),
		'profil'              => array(
			'id'               => (int) $user->ID,
			'identifiant'      => (string) $user->user_login,
			'pseudo'           => (string) $user->display_name,
			'email'            => (string) $user->user_email,
			'email_en_attente' => is_array( $attente ) && ! empty( $attente['email'] ) ? (string) $attente['email'] : '',
			'inscrit_le'       => iso( (string) $user->user_registered ),
			'roles'            => array_values( (array) $user->roles ),
		),
		'reglages_lecture'    => $reglages,
		'preferences_alertes' => preferences_alertes( $user_id ),
		'favoris'             => $favoris,
		'notes'               => $notes,
		'progression'         => $progression,
		'commentaires'        => $commentaires,
	);
}

/**
 * Efface les données du module lecteurs et lecture d'un membre (favoris, notes, positions,
 * réglages, préférences), sans supprimer le compte.
 *
 * @param int $user_id Utilisateur.
 * @return int Nombre d'éléments supprimés.
 */
function effacer_donnees( int $user_id ): int {
	if ( $user_id <= 0 ) {
		return 0;
	}
	$n = effacer_lignes_utilisateur( $user_id );
	if ( function_exists( '\Yume\Core\Reader\supprimer_progression_par' ) ) {
		$n += \Yume\Core\Reader\supprimer_progression_par( 'user_id', $user_id );
	}
	foreach ( array( META_ALERTES, META_EMAIL_ATTENTE, 'yume_reglages' ) as $cle ) {
		if ( metadata_exists( 'user', $user_id, $cle ) ) {
			delete_user_meta( $user_id, $cle );
			++$n;
		}
	}
	return $n;
}

/**
 * Anonymise les commentaires d'un membre (outil d'effacement de WordPress) : le texte reste,
 * l'auteur, l'e-mail, l'adresse IP et le navigateur sont retirés.
 *
 * @param string $email Adresse du membre.
 */
function anonymiser_commentaires( string $email ): void {
	if ( '' === $email || ! function_exists( 'wp_comments_personal_data_eraser' ) ) {
		return;
	}
	for ( $page = 1; $page <= 50; $page++ ) {
		$resultat = wp_comments_personal_data_eraser( $email, $page );
		if ( ! is_array( $resultat ) || ! empty( $resultat['done'] ) ) {
			break;
		}
	}
}

/**
 * Anonymise tous les commentaires rattachés au compte (user_id), quelle que soit l'adresse
 * e-mail enregistrée avec eux : après un changement d'adresse, les anciens commentaires
 * portent encore l'ancienne adresse, que l'effaceur de WordPress (recherche par adresse) ne
 * retrouve pas. Même traitement que wp_comments_personal_data_eraser().
 *
 * @param int $user_id Membre.
 * @return int Nombre de commentaires anonymisés.
 */
function anonymiser_commentaires_membre( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return 0;
	}
	$n = 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE user_id = %d", $user_id ) );
	foreach ( array_map( 'intval', (array) $ids ) as $comment_id ) {
		$commentaire = get_comment( $comment_id );
		if ( ! $commentaire instanceof \WP_Comment ) {
			continue;
		}
		$anonyme = array(
			'comment_agent'        => '',
			'comment_author'       => __( 'Anonymous' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- même libellé que l'effaceur de WordPress.
			'comment_author_email' => '',
			'comment_author_IP'    => wp_privacy_anonymize_data( 'ip', $commentaire->comment_author_IP ),
			'comment_author_url'   => '',
			'user_id'              => 0,
		);
		/** This filter is documented in wp-includes/comment.php */
		if ( true !== apply_filters( 'wp_anonymize_comment', true, $commentaire, $anonyme ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->update( $wpdb->comments, $anonyme, array( 'comment_ID' => $comment_id ) ) ) {
			clean_comment_cache( $comment_id );
			++$n;
		}
	}
	return $n;
}

/**
 * Supprime un compte lecteur et toutes ses données. Refusé pour l'équipe et les administrateurs.
 *
 * @param int $user_id Utilisateur.
 * @return true|\WP_Error
 */
function supprimer_compte( int $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 404 ) );
	}
	if ( ! peut_supprimer_compte( $user_id ) ) {
		return new \WP_Error( 'yume_suppression_interdite', __( 'Les comptes de l’équipe et des administrateurs ne peuvent pas être supprimés depuis cette page.', 'yume-core' ), array( 'status' => 403 ) );
	}
	/**
	 * Un compte lecteur va être supprimé (données encore présentes).
	 *
	 * @param int $user_id Utilisateur.
	 */
	do_action( 'yume_compte_suppression', $user_id );

	anonymiser_commentaires( (string) $user->user_email );
	anonymiser_commentaires_membre( $user_id );
	effacer_donnees( $user_id );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$sites = get_blogs_of_user( $user_id );
		if ( count( $sites ) > 1 ) {
			remove_user_from_blog( $user_id, get_current_blog_id() );
			$ok = true;
		} else {
			$ok = wpmu_delete_user( $user_id );
		}
	} else {
		$ok = wp_delete_user( $user_id );
	}
	if ( ! $ok ) {
		return new \WP_Error( 'yume_suppression_echec', __( 'Le compte n’a pas pu être supprimé.', 'yume-core' ), array( 'status' => 500 ) );
	}
	return true;
}

/*
 * -----------------------------------------------------------------------------
 * Outils de confidentialité de WordPress
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare l'exportateur.
 *
 * @param array $exportateurs Exportateurs.
 * @return array
 */
function declarer_exportateur( $exportateurs ): array {
	$exportateurs                      = (array) $exportateurs;
	$exportateurs['yume-core-lecteur'] = array(
		'exporter_friendly_name' => __( 'Yume Novel — lecture et favoris', 'yume-core' ),
		'callback'               => __NAMESPACE__ . '\\exporter_donnees',
	);
	return $exportateurs;
}
add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\declarer_exportateur' );

/**
 * Exportateur : favoris, notes, positions de lecture, réglages et préférences d'alerte.
 *
 * @param string $email Adresse.
 * @param int    $page  Page (tout tient sur une page).
 * @return array{data:array,done:bool}
 */
function exporter_donnees( $email, $page = 1 ): array {
	unset( $page ); // Tout tient sur une seule page.
	$user = get_user_by( 'email', (string) $email );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}
	$donnees = donnees_personnelles( (int) $user->ID );
	$items   = array();

	foreach ( $donnees['favoris'] as $favori ) {
		$items[] = array(
			'group_id'    => 'yume-favoris',
			'group_label' => __( 'Yume Novel — favoris', 'yume-core' ),
			'item_id'     => 'yume-favori-' . $favori['oeuvre_id'],
			'data'        => array(
				array(
					'name'  => __( 'Œuvre', 'yume-core' ),
					'value' => $favori['oeuvre'],
				),
				array(
					'name'  => __( 'Alerte', 'yume-core' ),
					'value' => libelles_frequences()[ $favori['alerte'] ] ?? $favori['alerte'],
				),
				array(
					'name'  => __( 'Ajouté le', 'yume-core' ),
					'value' => $favori['ajoute_le'],
				),
			),
		);
	}
	foreach ( $donnees['notes'] as $note ) {
		$items[] = array(
			'group_id'    => 'yume-notes',
			'group_label' => __( 'Yume Novel — notes', 'yume-core' ),
			'item_id'     => 'yume-note-' . $note['oeuvre_id'],
			'data'        => array(
				array(
					'name'  => __( 'Œuvre', 'yume-core' ),
					'value' => $note['oeuvre'],
				),
				array(
					'name'  => __( 'Note', 'yume-core' ),
					'value' => (string) $note['note'],
				),
				array(
					'name'  => __( 'Date', 'yume-core' ),
					'value' => $note['le'],
				),
			),
		);
	}
	foreach ( $donnees['progression'] as $position ) {
		$items[] = array(
			'group_id'    => 'yume-progression',
			'group_label' => __( 'Yume Novel — positions de lecture', 'yume-core' ),
			'item_id'     => 'yume-progression-' . $position['oeuvre_id'],
			'data'        => array(
				array(
					'name'  => __( 'Œuvre', 'yume-core' ),
					'value' => $position['oeuvre'],
				),
				array(
					'name'  => __( 'Chapitre', 'yume-core' ),
					'value' => $position['chapitre'],
				),
				array(
					'name'  => __( 'Paragraphe', 'yume-core' ),
					'value' => (string) ( $position['paragraphe'] + 1 ),
				),
				array(
					'name'  => __( 'Pourcentage lu', 'yume-core' ),
					'value' => $position['pourcentage'] . ' %',
				),
				array(
					'name'  => __( 'Date', 'yume-core' ),
					'value' => $position['le'],
				),
			),
		);
	}
	$preferences = array();
	foreach ( $donnees['preferences_alertes'] as $cle => $valeur ) {
		$preferences[] = array(
			'name'  => $cle,
			'value' => $valeur ? __( 'oui', 'yume-core' ) : __( 'non', 'yume-core' ),
		);
	}
	if ( is_array( $donnees['reglages_lecture'] ) ) {
		foreach ( $donnees['reglages_lecture'] as $cle => $valeur ) {
			$preferences[] = array(
				'name'  => $cle,
				'value' => (string) $valeur,
			);
		}
	}
	$items[] = array(
		'group_id'    => 'yume-preferences',
		'group_label' => __( 'Yume Novel — préférences', 'yume-core' ),
		'item_id'     => 'yume-preferences-' . $user->ID,
		'data'        => $preferences,
	);

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Déclare l'effaceur.
 *
 * @param array $effaceurs Effaceurs.
 * @return array
 */
function declarer_effaceur( $effaceurs ): array {
	$effaceurs                      = (array) $effaceurs;
	$effaceurs['yume-core-lecteur'] = array(
		'eraser_friendly_name' => __( 'Yume Novel — lecture et favoris', 'yume-core' ),
		'callback'             => __NAMESPACE__ . '\\effacer_donnees_email',
	);
	return $effaceurs;
}
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\declarer_effaceur' );

/**
 * Effaceur : favoris, notes, positions, réglages et préférences du membre.
 *
 * @param string $email Adresse.
 * @param int    $page  Page.
 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
 */
function effacer_donnees_email( $email, $page = 1 ): array {
	unset( $page ); // Tout est effacé en une seule passe.
	$user = get_user_by( 'email', (string) $email );
	$n    = $user ? effacer_donnees( (int) $user->ID ) : 0;
	return array(
		'items_removed'  => $n > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
