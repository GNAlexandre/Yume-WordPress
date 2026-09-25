<?php
/**
 * Événements métier (§8 du contrat).
 *
 * - yume_tome_publie( int $tome_id ) : une seule fois par tome (méta _yume_publie_notifie),
 *   quand un tome passe au statut publish ;
 * - yume_chapitre_publie( int $chapitre_id ) : chapitre publié isolément, c'est-à-dire
 *   seulement si son tome était déjà publié avant (pas lors de la sortie groupée d'un tome).
 *
 * Le passage à publish est noté sur transition_post_status, mais l'action n'est émise
 * qu'une fois les métadonnées écrites (wp_after_insert_post), car l'API REST et la sauvegarde
 * des méta-boîtes de l'éditeur de blocs enregistrent l'œuvre ou le tome après le statut.
 * Rien n'est émis pendant une publication orchestrée par le module publication
 * (did_action( 'yume_publication_en_cours' ), qui émet alors yume_tome_publie lui-même),
 * pendant un import (WP_IMPORTING) ni pour un contenu dont la date est ancienne (migration).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Méta : l'événement de publication a été traité (date GMT ou « ignore »). */
const META_NOTIFIE = '_yume_publie_notifie';

/** Méta : publication constatée, événement en attente de données complètes. */
const META_EN_ATTENTE = '_yume_notification_en_attente';

/**
 * Une publication orchestrée par le module publication est-elle en cours ?
 */
function publication_en_cours(): bool {
	return did_action( 'yume_publication_en_cours' ) > 0 || doing_action( 'yume_publication_en_cours' )
		|| ( defined( 'YUME_PUBLICATION_EN_COURS' ) && YUME_PUBLICATION_EN_COURS );
}

/**
 * Faut-il notifier la publication de ce contenu ?
 *
 * @param \WP_Post $post       Tome ou chapitre.
 * @param string   $evenement  'yume_tome_publie' ou 'yume_chapitre_publie'.
 */
function doit_notifier( \WP_Post $post, string $evenement ): bool {
	$notifier = true;
	if ( publication_en_cours() || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
		$notifier = false;
	}
	// Contenu daté du passé (migration, antidatage) : ce n'est pas une sortie.
	$gmt = (string) $post->post_date_gmt;
	if ( $notifier && '' !== $gmt && ! str_starts_with( $gmt, '0000-00-00' ) ) {
		/**
		 * Ancienneté maximale (secondes) d'un contenu dont la publication est notifiée.
		 *
		 * @param int $secondes Défaut : 2 jours.
		 */
		$fraicheur = (int) apply_filters( 'yume_core_fraicheur_notification', 2 * DAY_IN_SECONDS );
		if ( horodatage_gmt( $post ) < time() - $fraicheur ) {
			$notifier = false;
		}
	}
	/**
	 * Filtre la notification d'une publication (faux : aucun événement émis).
	 *
	 * @param bool     $notifier  Notifier.
	 * @param \WP_Post $post      Contenu.
	 * @param string   $evenement Nom de l'action.
	 */
	return (bool) apply_filters( 'yume_core_notifier', $notifier, $post, $evenement );
}

/**
 * Note le passage d'un tome ou d'un chapitre au statut publish.
 *
 * @param string   $nouveau Nouveau statut.
 * @param string   $ancien  Ancien statut.
 * @param \WP_Post $post    Contenu.
 */
function transition_statut( $nouveau, $ancien, $post ): void {
	if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return;
	}
	if ( 'publish' === $nouveau && 'publish' !== $ancien ) {
		etat_ajouter( 'publies_' . $post->post_type, (int) $post->ID );
		if ( metadata_exists( 'post', $post->ID, META_NOTIFIE ) ) {
			return;
		}
		if ( CPT_TOME === $post->post_type && publication_en_cours() ) {
			// Le module publication émet yume_tome_publie lui-même, une fois tout publié.
			return;
		}
		$evenement = CPT_TOME === $post->post_type ? 'yume_tome_publie' : 'yume_chapitre_publie';
		if ( ! doit_notifier( $post, $evenement ) ) {
			update_post_meta( $post->ID, META_NOTIFIE, 'ignore' );
			return;
		}
		update_post_meta( $post->ID, META_EN_ATTENTE, 1 );
	} elseif ( 'publish' === $ancien && 'publish' !== $nouveau ) {
		delete_post_meta( $post->ID, META_EN_ATTENTE );
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\transition_statut', 10, 3 );

/**
 * Le tome d'un chapitre était-il publié avant ce chapitre (et pas dans la même sortie) ?
 *
 * @param int      $tome_id  Tome.
 * @param \WP_Post $chapitre Chapitre.
 */
function tome_publie_avant( int $tome_id, \WP_Post $chapitre ): bool {
	$tome = get_post( $tome_id );
	if ( ! $tome || CPT_TOME !== $tome->post_type || 'publish' !== $tome->post_status ) {
		return false;
	}
	// Tome publié pendant cette même requête : sortie groupée.
	if ( etat_contient( 'publies_' . CPT_TOME, $tome_id ) ) {
		return false;
	}
	/**
	 * Écart minimal (secondes) entre la sortie du tome et celle d'un chapitre pour que le
	 * chapitre soit une sortie isolée (et non la sortie groupée d'un tome programmé).
	 *
	 * @param int $secondes Défaut : 15 minutes.
	 */
	$ecart = (int) apply_filters( 'yume_delai_publication_groupee', 15 * MINUTE_IN_SECONDS );
	return horodatage_gmt( $tome ) + $ecart <= horodatage_gmt( $chapitre );
}

/**
 * Marque l'événement de publication comme traité.
 *
 * @param int    $post_id ID.
 * @param string $valeur  Date GMT ou « ignore ».
 */
function marquer_notifie( int $post_id, string $valeur ): void {
	delete_post_meta( $post_id, META_EN_ATTENTE );
	if ( ! metadata_exists( 'post', $post_id, META_NOTIFIE ) ) {
		update_post_meta( $post_id, META_NOTIFIE, $valeur );
	}
}

/**
 * Émet l'événement en attente d'un tome ou d'un chapitre si ses données sont complètes.
 *
 * @param int $post_id ID.
 */
function traiter_notification( int $post_id ): void {
	if ( ! get_post_meta( $post_id, META_EN_ATTENTE, true ) ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		delete_post_meta( $post_id, META_EN_ATTENTE );
		return;
	}
	if ( metadata_exists( 'post', $post_id, META_NOTIFIE ) ) {
		delete_post_meta( $post_id, META_EN_ATTENTE );
		return;
	}
	if ( CPT_TOME === $post->post_type ) {
		if ( ! (int) get_post_meta( $post_id, 'yume_oeuvre_id', true ) ) {
			return; // L'œuvre n'est pas encore enregistrée : on attend.
		}
		marquer_notifie( $post_id, current_time( 'mysql', true ) );
		/**
		 * Un tome vient de sortir.
		 *
		 * @param int $tome_id ID du tome.
		 */
		do_action( 'yume_tome_publie', $post_id );
		return;
	}
	if ( CPT_CHAPITRE === $post->post_type ) {
		$tome_id = (int) get_post_meta( $post_id, 'yume_tome_id', true );
		if ( ! $tome_id ) {
			return; // Le tome n'est pas encore enregistré : on attend.
		}
		if ( ! tome_publie_avant( $tome_id, $post ) ) {
			marquer_notifie( $post_id, 'ignore' );
			return;
		}
		marquer_notifie( $post_id, current_time( 'mysql', true ) );
		/**
		 * Un chapitre vient de sortir isolément (son tome était déjà publié).
		 *
		 * @param int $chapitre_id ID du chapitre.
		 */
		do_action( 'yume_chapitre_publie', $post_id );
	}
}

/**
 * Sur wp_after_insert_post : émission des événements en attente.
 *
 * @param int $post_id ID.
 */
function apres_enregistrement_evenements( $post_id ): void {
	traiter_notification( (int) $post_id );
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement_evenements', 20 );

/**
 * Rattachement enregistré après coup (update_post_meta hors wp_insert_post) : l'événement en
 * attente peut partir.
 *
 * @param int    $meta_id   ID de la métadonnée.
 * @param int    $object_id ID du contenu.
 * @param string $meta_key  Clé.
 */
function rattachement_enregistre( $meta_id, $object_id, $meta_key ): void {
	if ( in_array( $meta_key, array( 'yume_oeuvre_id', 'yume_tome_id' ), true ) && ! doing_action( 'wp_after_insert_post' ) ) {
		traiter_notification( (int) $object_id );
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\rattachement_enregistre', 30, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\rattachement_enregistre', 30, 3 );

/**
 * Sur yume_tome_publie (priorité 0) : quel que soit l'émetteur (core ou publication), le tome est
 * marqué comme notifié pour ne jamais l'être deux fois.
 *
 * @param int $tome_id ID du tome.
 */
function marquer_tome_publie( $tome_id ): void {
	if ( CPT_TOME === get_post_type( (int) $tome_id ) ) {
		delete_post_meta( (int) $tome_id, META_EN_ATTENTE );
		update_post_meta( (int) $tome_id, META_NOTIFIE, current_time( 'mysql', true ) );
	}
}
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\marquer_tome_publie', 0 );

/**
 * Sur yume_tome_publie (core) : cache yume_derniere_sortie de l'œuvre et nombre de chapitres.
 *
 * @param int $tome_id ID du tome.
 */
function cache_apres_tome_publie( $tome_id ): void {
	$tome_id = (int) $tome_id;
	if ( CPT_TOME !== get_post_type( $tome_id ) ) {
		return;
	}
	recalculer_nb_chapitres( $tome_id );
	recalculer_derniere_sortie( (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ) );
}
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\cache_apres_tome_publie', 5 );
