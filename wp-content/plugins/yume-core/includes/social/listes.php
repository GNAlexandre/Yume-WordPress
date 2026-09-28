<?php
/**
 * Listes de lecture des membres (PAGE-07).
 *
 * - Trois listes système par membre, créées à la première consultation : « À lire »,
 *   « En cours », « Terminé » (une œuvre n'est que dans l'une d'elles à la fois), plus des
 *   listes personnelles (LISTES_MAX au plus, LISTE_OEUVRES_MAX œuvres par liste), publiques ou
 *   privées. Tables {prefix}yume_listes et {prefix}yume_listes_oeuvres (install.php).
 * - Remplissage automatique (désactivable par le membre, méta yume_listes_auto = '0') : une
 *   œuvre passe dans « En cours » quand le lecteur l'ouvre (yume_progression_enregistree), puis
 *   dans « Terminé » quand il a lu le dernier chapitre publié.
 * - Fiche d'œuvre : menu « Ajouter à une liste » inséré dans le bloc yume/oeuvre-actions
 *   (render.php du bloc), cases pour chaque liste et création rapide ; sans JavaScript, un
 *   formulaire admin-post (yume_listes_oeuvre).
 * - Page compte : rubrique « Mes listes » (section_listes(), formulaires admin-post).
 * - Liste publique partageable : /listes/{id}-{slug}/ (règle de réécriture propre, vidée par
 *   verifier_regles_listes() avec l'option yume_listes_regles), rendue dans le thème par le
 *   bloc yume/liste-publique, noindex par défaut (filtre yume_listes_indexables). Une liste
 *   privée, ou d'un compte disparu, répond 404 à tout autre que son propriétaire.
 * - RGPD : donnees_listes() (export) et effacer_listes() (effacement, suppression du compte).
 *
 * Routes REST : rest.php (GET/POST /moi/listes, PATCH/DELETE /moi/listes/{id},
 * PUT/DELETE /moi/listes/{id}/oeuvres/{oeuvre}).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

/** Nombre maximal de listes personnelles par membre (hors listes système). */
const LISTES_MAX = 20;

/** Nombre maximal d'œuvres par liste. */
const LISTE_OEUVRES_MAX = 500;

/** Longueur maximale du nom d'une liste. */
const LISTE_NOM_MAX = 60;

/** Longueur maximale de la description d'une liste. */
const LISTE_DESCRIPTION_MAX = 280;

/** Méta utilisateur : remplissage automatique des listes « En cours » et « Terminé » ('0' : non). */
const META_LISTES_AUTO = 'yume_listes_auto';

/** Variable de requête publique de la page d'une liste. */
const QV_LISTE = 'yume_liste';

/** Option : signature de la règle de réécriture /listes/ déjà enregistrée. */
const OPTION_REGLES_LISTES = 'yume_listes_regles';

/** Paramètre d'URL des messages après un formulaire de listes (sans JavaScript). */
const PARAM_MESSAGE_LISTES = 'yn-lmsg';

/**
 * Nom complet de la table des listes.
 */
function table_listes(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_listes';
}

/**
 * Nom complet de la table des œuvres des listes.
 */
function table_listes_oeuvres(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_listes_oeuvres';
}

/**
 * Listes système : clé => nom et slug (ordre d'affichage).
 *
 * @return array<string,array{nom:string,slug:string}>
 */
function listes_systeme(): array {
	return array(
		'a_lire'   => array(
			'nom'  => __( 'À lire', 'yume-core' ),
			'slug' => 'a-lire',
		),
		'en_cours' => array(
			'nom'  => __( 'En cours', 'yume-core' ),
			'slug' => 'en-cours',
		),
		'termine'  => array(
			'nom'  => __( 'Terminé', 'yume-core' ),
			'slug' => 'termine',
		),
	);
}

/**
 * Ligne de liste normalisée.
 *
 * @param array $ligne Ligne brute.
 * @return array{id:int,user_id:int,nom:string,slug:string,description:string,publique:bool,systeme:string,cree_le:string,maj_le:string}
 */
function normaliser_liste( array $ligne ): array {
	$systeme = (string) ( $ligne['systeme'] ?? '' );
	$connues = listes_systeme();
	return array(
		'id'          => (int) ( $ligne['id'] ?? 0 ),
		'user_id'     => (int) ( $ligne['user_id'] ?? 0 ),
		// Nom des listes système : toujours le libellé traduit courant.
		'nom'         => isset( $connues[ $systeme ] ) ? $connues[ $systeme ]['nom'] : (string) ( $ligne['nom'] ?? '' ),
		'slug'        => (string) ( $ligne['slug'] ?? '' ),
		'description' => (string) ( $ligne['description'] ?? '' ),
		'publique'    => (bool) (int) ( $ligne['publique'] ?? 0 ),
		'systeme'     => isset( $connues[ $systeme ] ) ? $systeme : '',
		'cree_le'     => (string) ( $ligne['cree_le'] ?? '' ),
		'maj_le'      => (string) ( $ligne['maj_le'] ?? '' ),
	);
}

/**
 * Erreur « liste introuvable » (404) : aussi pour la liste d'un autre membre.
 */
function erreur_liste(): \WP_Error {
	return new \WP_Error( 'yume_liste_introuvable', __( 'Liste introuvable.', 'yume-core' ), array( 'status' => 404 ) );
}

/**
 * Slug d'un nom de liste (jamais vide).
 *
 * @param string $nom Nom.
 */
function slug_liste( string $nom ): string {
	$slug = sanitize_title( $nom );
	$slug = '' !== $slug ? substr( $slug, 0, 100 ) : 'liste';
	return trim( $slug, '-' ) !== '' ? trim( $slug, '-' ) : 'liste';
}

/**
 * Nom de liste désinfecté et tronqué (chaîne vide si invalide).
 *
 * @param string $nom Nom saisi.
 */
function nettoyer_nom_liste( string $nom ): string {
	$nom = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $nom ) ) );
	return mb_substr( $nom, 0, LISTE_NOM_MAX );
}

/**
 * Description désinfectée et tronquée.
 *
 * @param string $description Description saisie.
 */
function nettoyer_description_liste( string $description ): string {
	return mb_substr( trim( sanitize_textarea_field( $description ) ), 0, LISTE_DESCRIPTION_MAX );
}

/**
 * Prépare les tables si besoin (première utilisation sans passage par l'installation).
 */
function preparer_tables_lecteur(): bool {
	if ( ! tables_lecteur_pretes() ) {
		installer_tables_lecteur();
	}
	return tables_lecteur_pretes();
}

/*
 * -----------------------------------------------------------------------------
 * Lecture
 * -----------------------------------------------------------------------------
 */

/**
 * Une liste par son identifiant, ou null.
 *
 * @param int $liste_id Liste.
 */
function liste( int $liste_id ): ?array {
	global $wpdb;
	if ( $liste_id <= 0 || ! tables_lecteur_pretes() ) {
		return null;
	}
	$table = table_listes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $liste_id ), ARRAY_A );
	return is_array( $ligne ) ? normaliser_liste( $ligne ) : null;
}

/**
 * Une liste du membre, ou null (liste inconnue ou d'un autre membre).
 *
 * @param int $liste_id Liste.
 * @param int $user_id  Membre.
 */
function liste_du_membre( int $liste_id, int $user_id ): ?array {
	$liste = liste( $liste_id );
	return $liste && $user_id > 0 && $liste['user_id'] === $user_id ? $liste : null;
}

/**
 * Crée les listes système manquantes du membre.
 *
 * @param int $user_id Membre.
 */
function assurer_listes_systeme( int $user_id ): void {
	global $wpdb;
	if ( $user_id <= 0 || ! preparer_tables_lecteur() ) {
		return;
	}
	$table = table_listes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$presentes  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT systeme FROM {$table} WHERE user_id = %d AND systeme <> ''", $user_id ) );
	$maintenant = maintenant_gmt();
	foreach ( listes_systeme() as $cle => $systeme ) {
		if ( in_array( $cle, $presentes, true ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'nom'         => $systeme['nom'],
				'slug'        => $systeme['slug'],
				'description' => '',
				'publique'    => 0,
				'systeme'     => $cle,
				'cree_le'     => $maintenant,
				'maj_le'      => $maintenant,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}
}

/**
 * Listes du membre : les trois listes système d'abord, puis les listes personnelles par date
 * de création. Chaque liste porte son nombre d'œuvres publiées (nb).
 *
 * @param int  $user_id Membre.
 * @param bool $creer   Créer les listes système manquantes.
 * @return array<int,array>
 */
function listes_utilisateur( int $user_id, bool $creer = true ): array {
	global $wpdb;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return array();
	}
	if ( $creer ) {
		assurer_listes_systeme( $user_id );
	}
	if ( ! tables_lecteur_pretes() ) {
		return array();
	}
	$listes  = table_listes();
	$contenu = table_listes_oeuvres();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$listes} WHERE user_id = %d ORDER BY id ASC", $user_id ), ARRAY_A );
	$nb     = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT lo.liste_id, COUNT(*) AS nb FROM {$contenu} lo
			INNER JOIN {$listes} l ON l.id = lo.liste_id
			INNER JOIN {$wpdb->posts} p ON p.ID = lo.oeuvre_id AND p.post_type = 'yume_oeuvre' AND p.post_status = 'publish'
			WHERE l.user_id = %d GROUP BY lo.liste_id",
			$user_id
		),
		ARRAY_A
	);
	// phpcs:enable
	$compte = array();
	foreach ( $nb as $ligne ) {
		$compte[ (int) $ligne['liste_id'] ] = (int) $ligne['nb'];
	}
	$ordre   = array_flip( array_keys( listes_systeme() ) );
	$systeme = array();
	$perso   = array();
	$vus     = array();
	foreach ( $lignes as $ligne ) {
		$liste       = normaliser_liste( $ligne );
		$liste['nb'] = $compte[ $liste['id'] ] ?? 0;
		if ( '' !== $liste['systeme'] ) {
			// Doublon éventuel (deux créations simultanées) : seule la première compte.
			if ( isset( $vus[ $liste['systeme'] ] ) ) {
				continue;
			}
			$vus[ $liste['systeme'] ]               = true;
			$systeme[ $ordre[ $liste['systeme'] ] ] = $liste;
		} else {
			$perso[] = $liste;
		}
	}
	ksort( $systeme );
	return array_merge( array_values( $systeme ), $perso );
}

/**
 * Liste système du membre (créée au besoin), ou null.
 *
 * @param int    $user_id Membre.
 * @param string $cle     a_lire, en_cours ou termine.
 */
function liste_systeme( int $user_id, string $cle ): ?array {
	foreach ( listes_utilisateur( $user_id ) as $liste ) {
		if ( $cle === $liste['systeme'] ) {
			return $liste;
		}
	}
	return null;
}

/**
 * Nombre de listes personnelles du membre.
 *
 * @param int $user_id Membre.
 */
function nb_listes_personnelles( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	$table = table_listes();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND systeme = ''", $user_id ) );
}

/**
 * Œuvres d'une liste, dans l'ordre d'ajout.
 *
 * @param int  $liste_id  Liste.
 * @param bool $publiees  Seulement les œuvres publiées.
 * @return int[]
 */
function oeuvres_liste( int $liste_id, bool $publiees = true ): array {
	global $wpdb;
	if ( $liste_id <= 0 || ! tables_lecteur_pretes() ) {
		return array();
	}
	$table = table_listes_oeuvres();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( $publiees ) {
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT lo.oeuvre_id FROM {$table} lo INNER JOIN {$wpdb->posts} p ON p.ID = lo.oeuvre_id AND p.post_type = 'yume_oeuvre' AND p.post_status = 'publish'
				WHERE lo.liste_id = %d ORDER BY lo.ordre ASC, lo.ajoute_le ASC",
				$liste_id
			)
		);
	} else {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT oeuvre_id FROM {$table} WHERE liste_id = %d ORDER BY ordre ASC, ajoute_le ASC", $liste_id ) );
	}
	// phpcs:enable
	return array_map( 'intval', (array) $ids );
}

/**
 * Listes du membre qui contiennent l'œuvre : liste_id => date d'ajout (GMT).
 *
 * @param int $user_id   Membre.
 * @param int $oeuvre_id Œuvre.
 * @return array<int,string>
 */
function listes_contenant( int $user_id, int $oeuvre_id ): array {
	global $wpdb;
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! tables_lecteur_pretes() ) {
		return array();
	}
	$listes  = table_listes();
	$contenu = table_listes_oeuvres();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT lo.liste_id, lo.ajoute_le FROM {$contenu} lo INNER JOIN {$listes} l ON l.id = lo.liste_id WHERE l.user_id = %d AND lo.oeuvre_id = %d", $user_id, $oeuvre_id ), ARRAY_A );
	$resultat = array();
	foreach ( $lignes as $ligne ) {
		$resultat[ (int) $ligne['liste_id'] ] = (string) $ligne['ajoute_le'];
	}
	return $resultat;
}

/**
 * Adresse publique d'une liste : /listes/{id}-{slug}/ (ou ?yume_liste={id} sans permaliens).
 *
 * @param array $liste Liste.
 */
function url_liste( array $liste ): string {
	global $wp_rewrite;
	if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ) {
		return home_url( user_trailingslashit( 'listes/' . (int) $liste['id'] . '-' . ( '' !== $liste['slug'] ? $liste['slug'] : 'liste' ) ) );
	}
	return add_query_arg( QV_LISTE, (int) $liste['id'], home_url( '/' ) );
}

/**
 * Représentation d'une liste pour l'API et l'export.
 *
 * @param array $liste     Liste (avec nb).
 * @param int   $oeuvre_id Œuvre dont on indique la présence (0 : non demandé).
 * @return array<string,mixed>
 */
function liste_pour_api( array $liste, int $oeuvre_id = 0 ): array {
	$oeuvres = oeuvres_liste( $liste['id'] );
	$donnees = array(
		'id'          => $liste['id'],
		'nom'         => $liste['nom'],
		'slug'        => $liste['slug'],
		'description' => $liste['description'],
		'publique'    => $liste['publique'],
		'systeme'     => '' !== $liste['systeme'] ? $liste['systeme'] : null,
		'nb'          => count( $oeuvres ),
		'oeuvres'     => $oeuvres,
		'url'         => url_liste( $liste ),
		'cree_le'     => iso( $liste['cree_le'] ),
		'maj_le'      => iso( $liste['maj_le'] ),
	);
	if ( $oeuvre_id > 0 ) {
		$donnees['contient'] = in_array( $oeuvre_id, $oeuvres, true );
	}
	return $donnees;
}

/*
 * -----------------------------------------------------------------------------
 * Écriture
 * -----------------------------------------------------------------------------
 */

/**
 * Crée une liste personnelle.
 *
 * @param int    $user_id     Membre.
 * @param string $nom         Nom (1 à LISTE_NOM_MAX caractères).
 * @param string $description Description courte.
 * @param bool   $publique    Liste publique (partageable).
 * @return array|\WP_Error Liste créée.
 */
function creer_liste( int $user_id, string $nom, string $description = '', bool $publique = false ) {
	global $wpdb;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$nom = nettoyer_nom_liste( $nom );
	if ( '' === $nom ) {
		return new \WP_Error( 'yume_liste_nom', __( 'Donnez un nom à la liste.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! preparer_tables_lecteur() ) {
		return new \WP_Error( 'yume_liste_echec', __( 'La liste n’a pas pu être créée.', 'yume-core' ), array( 'status' => 500 ) );
	}
	assurer_listes_systeme( $user_id );
	if ( nb_listes_personnelles( $user_id ) >= LISTES_MAX ) {
		return new \WP_Error(
			'yume_listes_limite',
			/* translators: %d : nombre maximal de listes. */
			sprintf( __( 'Vous avez atteint la limite de %d listes personnelles.', 'yume-core' ), LISTES_MAX ),
			array( 'status' => 409 )
		);
	}
	$maintenant = maintenant_gmt();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		table_listes(),
		array(
			'user_id'     => $user_id,
			'nom'         => $nom,
			'slug'        => slug_liste( $nom ),
			'description' => nettoyer_description_liste( $description ),
			'publique'    => $publique ? 1 : 0,
			'systeme'     => '',
			'cree_le'     => $maintenant,
			'maj_le'      => $maintenant,
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);
	if ( ! $ok ) {
		return new \WP_Error( 'yume_liste_echec', __( 'La liste n’a pas pu être créée.', 'yume-core' ), array( 'status' => 500 ) );
	}
	return liste( (int) $wpdb->insert_id );
}

/**
 * Modifie une liste : nom (listes personnelles seulement), description, visibilité.
 *
 * @param array $liste  Liste.
 * @param array $champs nom, description, publique (clés absentes : inchangées).
 * @return array|\WP_Error Liste modifiée.
 */
function modifier_liste( array $liste, array $champs ) {
	global $wpdb;
	$maj = array();
	if ( array_key_exists( 'nom', $champs ) && null !== $champs['nom'] ) {
		$nom = nettoyer_nom_liste( (string) $champs['nom'] );
		if ( '' !== $liste['systeme'] ) {
			if ( $nom !== $liste['nom'] ) {
				return new \WP_Error( 'yume_liste_systeme', __( 'Les listes « À lire », « En cours » et « Terminé » ne peuvent pas être renommées.', 'yume-core' ), array( 'status' => 400 ) );
			}
		} elseif ( '' === $nom ) {
			return new \WP_Error( 'yume_liste_nom', __( 'Donnez un nom à la liste.', 'yume-core' ), array( 'status' => 400 ) );
		} else {
			$maj['nom']  = $nom;
			$maj['slug'] = slug_liste( $nom );
		}
	}
	if ( array_key_exists( 'description', $champs ) && null !== $champs['description'] ) {
		$maj['description'] = nettoyer_description_liste( (string) $champs['description'] );
	}
	if ( array_key_exists( 'publique', $champs ) && null !== $champs['publique'] ) {
		$maj['publique'] = rest_sanitize_boolean( $champs['publique'] ) ? 1 : 0;
	}
	if ( $maj ) {
		$maj['maj_le'] = maintenant_gmt();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( table_listes(), $maj, array( 'id' => $liste['id'] ) );
	}
	return liste( $liste['id'] );
}

/**
 * Supprime une liste personnelle et son contenu.
 *
 * @param array $liste Liste.
 * @return true|\WP_Error
 */
function supprimer_liste( array $liste ) {
	global $wpdb;
	if ( '' !== $liste['systeme'] ) {
		return new \WP_Error( 'yume_liste_systeme', __( 'Les listes « À lire », « En cours » et « Terminé » ne peuvent pas être supprimées.', 'yume-core' ), array( 'status' => 400 ) );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_listes_oeuvres(), array( 'liste_id' => $liste['id'] ), array( '%d' ) );
	$wpdb->delete( table_listes(), array( 'id' => $liste['id'] ), array( '%d' ) );
	// phpcs:enable
	return true;
}

/**
 * Ajoute une œuvre publiée à une liste (sans effet si elle y est). Listes système : l'œuvre
 * quitte les deux autres listes système du membre.
 *
 * @param array $liste     Liste.
 * @param int   $oeuvre_id Œuvre.
 * @return bool|\WP_Error Vrai si l'œuvre vient d'être ajoutée, faux si elle y était déjà.
 */
function ajouter_a_liste( array $liste, int $oeuvre_id ) {
	global $wpdb;
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		return erreur_oeuvre();
	}
	$table = table_listes_oeuvres();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$deja = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE liste_id = %d AND oeuvre_id = %d", $liste['id'], $oeuvre_id ) );
	if ( $deja ) {
		return false;
	}
	$nb = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE liste_id = %d", $liste['id'] ) );
	if ( $nb >= LISTE_OEUVRES_MAX ) {
		return new \WP_Error(
			'yume_liste_pleine',
			/* translators: %d : nombre maximal d'œuvres. */
			sprintf( __( 'Cette liste est pleine (%d œuvres au plus).', 'yume-core' ), LISTE_OEUVRES_MAX ),
			array( 'status' => 409 )
		);
	}
	$ordre = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ordre), 0) FROM {$table} WHERE liste_id = %d", $liste['id'] ) );
	// phpcs:enable
	if ( '' !== $liste['systeme'] ) {
		foreach ( listes_utilisateur( $liste['user_id'], false ) as $autre ) {
			if ( '' !== $autre['systeme'] && $autre['id'] !== $liste['id'] ) {
				retirer_de_liste( $autre, $oeuvre_id );
			}
		}
	}
	$maintenant = maintenant_gmt();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		$table,
		array(
			'liste_id'  => $liste['id'],
			'oeuvre_id' => $oeuvre_id,
			'ajoute_le' => $maintenant,
			'ordre'     => $ordre + 1,
		),
		array( '%d', '%d', '%s', '%d' )
	);
	if ( ! $ok ) {
		return new \WP_Error( 'yume_liste_echec', __( 'L’œuvre n’a pas pu être ajoutée à la liste.', 'yume-core' ), array( 'status' => 500 ) );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( table_listes(), array( 'maj_le' => $maintenant ), array( 'id' => $liste['id'] ) );
	/**
	 * Une œuvre vient d'être ajoutée à une liste de lecture.
	 *
	 * @param int   $oeuvre_id Œuvre.
	 * @param array $liste     Liste.
	 */
	do_action( 'yume_liste_oeuvre_ajoutee', $oeuvre_id, $liste );
	return true;
}

/**
 * Retire une œuvre d'une liste.
 *
 * @param array $liste     Liste.
 * @param int   $oeuvre_id Œuvre.
 * @return bool Vrai si une ligne a été supprimée.
 */
function retirer_de_liste( array $liste, int $oeuvre_id ): bool {
	global $wpdb;
	if ( $oeuvre_id <= 0 || ! tables_lecteur_pretes() ) {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n = (int) $wpdb->delete(
		table_listes_oeuvres(),
		array(
			'liste_id'  => $liste['id'],
			'oeuvre_id' => $oeuvre_id,
		),
		array( '%d', '%d' )
	);
	if ( $n ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( table_listes(), array( 'maj_le' => maintenant_gmt() ), array( 'id' => $liste['id'] ) );
	}
	return $n > 0;
}

/**
 * Supprime toutes les listes d'un membre (RGPD, suppression du compte).
 *
 * @param int $user_id Membre.
 * @return int Nombre de listes supprimées.
 */
function effacer_listes( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! tables_lecteur_pretes() ) {
		return 0;
	}
	$listes  = table_listes();
	$contenu = table_listes_oeuvres();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$listes} WHERE user_id = %d", $user_id ) ) );
	foreach ( $ids as $id ) {
		$wpdb->delete( $contenu, array( 'liste_id' => $id ), array( '%d' ) );
	}
	$wpdb->delete( $listes, array( 'user_id' => $user_id ), array( '%d' ) );
	// phpcs:enable
	if ( metadata_exists( 'user', $user_id, META_LISTES_AUTO ) ) {
		delete_user_meta( $user_id, META_LISTES_AUTO );
	}
	return count( $ids );
}

/**
 * Listes d'un membre pour l'export RGPD (toutes les œuvres, même non publiées).
 *
 * @param int $user_id Membre.
 * @return array<int,array<string,mixed>>
 */
function donnees_listes( int $user_id ): array {
	$export = array();
	foreach ( listes_utilisateur( $user_id, false ) as $liste ) {
		$oeuvres = array();
		foreach ( oeuvres_liste( $liste['id'], false ) as $oeuvre_id ) {
			$ref       = reference_oeuvre( $oeuvre_id );
			$oeuvres[] = array(
				'oeuvre_id' => $oeuvre_id,
				'oeuvre'    => $ref['titre'],
				'url'       => $ref['url'],
			);
		}
		$export[] = array(
			'id'          => $liste['id'],
			'nom'         => $liste['nom'],
			'description' => $liste['description'],
			'publique'    => $liste['publique'],
			'systeme'     => $liste['systeme'],
			'url'         => $liste['publique'] ? url_liste( $liste ) : '',
			'cree_le'     => iso( $liste['cree_le'] ),
			'maj_le'      => iso( $liste['maj_le'] ),
			'oeuvres'     => $oeuvres,
		);
	}
	return $export;
}

/**
 * Utilisateur supprimé (administration ou façade) : ses listes aussi.
 *
 * @param int $user_id Utilisateur.
 */
function listes_utilisateur_supprime( $user_id ): void {
	effacer_listes( (int) $user_id );
}
add_action( 'deleted_user', __NAMESPACE__ . '\\listes_utilisateur_supprime' );

/**
 * Œuvre supprimée définitivement : elle quitte toutes les listes.
 *
 * @param int           $post_id ID.
 * @param \WP_Post|null $post    Contenu.
 */
function listes_contenu_supprime( $post_id, $post = null ): void {
	global $wpdb;
	$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
	if ( 'yume_oeuvre' !== $type || ! tables_lecteur_pretes() ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_listes_oeuvres(), array( 'oeuvre_id' => (int) $post_id ), array( '%d' ) );
}
add_action( 'deleted_post', __NAMESPACE__ . '\\listes_contenu_supprime', 10, 2 );

/*
 * -----------------------------------------------------------------------------
 * Remplissage automatique (« En cours », « Terminé »)
 * -----------------------------------------------------------------------------
 */

/**
 * Le remplissage automatique est-il actif pour ce membre (par défaut : oui) ?
 *
 * @param int $user_id Membre.
 */
function listes_auto( int $user_id ): bool {
	return '0' !== (string) get_user_meta( $user_id, META_LISTES_AUTO, true );
}

/**
 * Dernier chapitre publié d'une œuvre, dans l'ordre de lecture (0 si aucun).
 *
 * @param int $oeuvre_id Œuvre.
 */
function dernier_chapitre( int $oeuvre_id ): int {
	$dernier = 0;
	if ( function_exists( '\Yume\Core\Reader\plan_de_lecture' ) ) {
		$plan = \Yume\Core\Reader\plan_de_lecture( array( $oeuvre_id ) );
		foreach ( (array) ( $plan[ $oeuvre_id ] ?? array() ) as $tome ) {
			if ( $tome['chapitres'] ) {
				$fin     = end( $tome['chapitres'] );
				$dernier = (int) $fin['id'];
			}
		}
	}
	return $dernier;
}

/**
 * Position enregistrée (yume_progression_enregistree) : l'œuvre passe dans « En cours », ou
 * dans « Terminé » si le dernier chapitre publié est lu (seuil du module lecteur, 90 %). Une
 * œuvre déjà « Terminé » n'en sort que pour un chapitre publié après son classement (nouvelle
 * sortie), pas pour une relecture.
 *
 * @param int   $user_id Membre.
 * @param array $ligne   Position (oeuvre_id, chapitre_id, pourcentage).
 */
function listes_sur_progression( $user_id, $ligne ): void {
	$user_id     = (int) $user_id;
	$ligne       = (array) $ligne;
	$oeuvre_id   = (int) ( $ligne['oeuvre_id'] ?? 0 );
	$chapitre_id = (int) ( $ligne['chapitre_id'] ?? 0 );
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! tables_lecteur_pretes() || ! listes_auto( $user_id ) ) {
		return;
	}
	/**
	 * Active ou désactive le remplissage automatique des listes « En cours » et « Terminé ».
	 *
	 * @param bool $actif     Actif (préférence du membre).
	 * @param int  $user_id   Membre.
	 * @param int  $oeuvre_id Œuvre.
	 */
	if ( ! apply_filters( 'yume_listes_auto', true, $user_id, $oeuvre_id ) ) {
		return;
	}
	// Liste système qui contient déjà l'œuvre.
	$actuelle = '';
	$depuis   = '';
	$contient = listes_contenant( $user_id, $oeuvre_id );
	$systemes = array();
	foreach ( listes_utilisateur( $user_id ) as $liste ) {
		if ( '' === $liste['systeme'] ) {
			continue;
		}
		$systemes[ $liste['systeme'] ] = $liste;
		if ( isset( $contient[ $liste['id'] ] ) ) {
			$actuelle = $liste['systeme'];
			$depuis   = $contient[ $liste['id'] ];
		}
	}
	$seuil = defined( '\Yume\Core\Reader\SEUIL_CHAPITRE_LU' ) ? (int) \Yume\Core\Reader\SEUIL_CHAPITRE_LU : 90;
	$fini  = (int) ( $ligne['pourcentage'] ?? 0 ) >= $seuil && $chapitre_id > 0 && dernier_chapitre( $oeuvre_id ) === $chapitre_id;
	if ( $fini ) {
		if ( 'termine' !== $actuelle && isset( $systemes['termine'] ) ) {
			ajouter_a_liste( $systemes['termine'], $oeuvre_id );
		}
		return;
	}
	if ( 'en_cours' === $actuelle || ! isset( $systemes['en_cours'] ) ) {
		return;
	}
	if ( 'termine' === $actuelle ) {
		$sortie = (string) get_post_field( 'post_date_gmt', $chapitre_id );
		if ( '' === $sortie || $sortie <= $depuis ) {
			return; // Relecture d'une œuvre terminée.
		}
	}
	ajouter_a_liste( $systemes['en_cours'], $oeuvre_id );
}
add_action( 'yume_progression_enregistree', __NAMESPACE__ . '\\listes_sur_progression', 20, 2 );

/*
 * -----------------------------------------------------------------------------
 * Messages des formulaires (sans JavaScript)
 * -----------------------------------------------------------------------------
 */

/**
 * Messages des formulaires de listes : code => [type, texte].
 *
 * @return array<string,array{0:string,1:string}>
 */
function messages_listes(): array {
	return array(
		'liste-creee'       => array( 'succes', __( 'Liste créée.', 'yume-core' ) ),
		'liste-modifiee'    => array( 'succes', __( 'Liste enregistrée.', 'yume-core' ) ),
		'liste-supprimee'   => array( 'succes', __( 'Liste supprimée.', 'yume-core' ) ),
		'liste-retiree'     => array( 'succes', __( 'Œuvre retirée de la liste.', 'yume-core' ) ),
		'listes-ok'         => array( 'succes', __( 'Vos listes sont à jour.', 'yume-core' ) ),
		'listes-auto-ok'    => array( 'succes', __( 'Préférence enregistrée.', 'yume-core' ) ),
		'liste-nom'         => array( 'erreur', __( 'Donnez un nom à la liste.', 'yume-core' ) ),
		/* translators: %d : nombre maximal de listes. */
		'listes-limite'     => array( 'erreur', sprintf( __( 'Vous avez atteint la limite de %d listes personnelles.', 'yume-core' ), LISTES_MAX ) ),
		/* translators: %d : nombre maximal d'œuvres. */
		'liste-pleine'      => array( 'erreur', sprintf( __( 'Cette liste est pleine (%d œuvres au plus).', 'yume-core' ), LISTE_OEUVRES_MAX ) ),
		'liste-systeme'     => array( 'erreur', __( 'Les listes « À lire », « En cours » et « Terminé » ne peuvent être ni renommées ni supprimées.', 'yume-core' ) ),
		'liste-introuvable' => array( 'erreur', __( 'Liste introuvable.', 'yume-core' ) ),
		'listes-erreur'     => array( 'erreur', __( 'Une erreur est survenue. Réessayez.', 'yume-core' ) ),
	);
}

/**
 * Messages de listes demandés par l'URL courante.
 *
 * @return array<int,array{0:string,1:string}>
 */
function messages_listes_courants(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage d'un code connu.
	$code   = isset( $_GET[ PARAM_MESSAGE_LISTES ] ) ? sanitize_key( wp_unslash( $_GET[ PARAM_MESSAGE_LISTES ] ) ) : '';
	$connus = messages_listes();
	return isset( $connus[ $code ] ) ? array( $connus[ $code ] ) : array();
}

/**
 * Code de message d'une erreur de liste.
 *
 * @param \WP_Error $erreur Erreur.
 */
function code_erreur_liste( \WP_Error $erreur ): string {
	$codes = array(
		'yume_liste_nom'         => 'liste-nom',
		'yume_listes_limite'     => 'listes-limite',
		'yume_liste_pleine'      => 'liste-pleine',
		'yume_liste_systeme'     => 'liste-systeme',
		'yume_liste_introuvable' => 'liste-introuvable',
	);
	return $codes[ $erreur->get_error_code() ] ?? 'listes-erreur';
}

/**
 * Redirige après un formulaire de listes, avec un message.
 *
 * @param string $retour Page de retour.
 * @param string $code   Code de message (messages_listes()).
 * @param string $ancre  Ancre.
 */
function rediriger_listes( string $retour, string $code, string $ancre ): void {
	$retour = remove_query_arg( PARAM_MESSAGE_LISTES, $retour );
	rediriger( add_query_arg( PARAM_MESSAGE_LISTES, $code, $retour ), array(), $ancre );
}

/*
 * -----------------------------------------------------------------------------
 * Fiche d'œuvre : menu « Ajouter à une liste » (bloc yume/oeuvre-actions)
 * -----------------------------------------------------------------------------
 */

/**
 * Menu « Ajouter à une liste » d'une œuvre pour le membre connecté ('' sinon).
 *
 * @param int $oeuvre_id Œuvre.
 */
function html_menu_listes( int $oeuvre_id ): string {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 || ! oeuvre_publiee( $oeuvre_id ) || ! preparer_tables_lecteur() ) {
		return '';
	}
	$listes   = listes_utilisateur( $user_id );
	$contient = listes_contenant( $user_id, $oeuvre_id );
	$nb       = count( array_intersect_key( $contient, array_flip( wp_list_pluck( $listes, 'id' ) ) ) );
	$icone    = icone( '<path d="M8 6h13"></path><path d="M8 12h13"></path><path d="M8 18h13"></path><path d="M3 6h.01"></path><path d="M3 12h.01"></path><path d="M3 18h.01"></path>' );
	$resume   = $nb > 0
		/* translators: %d : nombre de listes contenant l'œuvre. */
		? sprintf( _n( 'Dans %d liste', 'Dans %d listes', $nb, 'yume-core' ), $nb )
		: __( 'Ajouter à une liste', 'yume-core' );
	$titre = wp_strip_all_tags( get_the_title( $oeuvre_id ) );

	$html = '<details class="yn-oeuvre-actions__menu yn-oeuvre-actions__listes" data-yn-menu="listes"><summary class="yn-btn">' . $icone
		. '<span data-yn-listes-resume>' . esc_html( $resume ) . '</span>'
		/* translators: %s : titre de l'œuvre. */
		. '<span class="yn-visually-hidden">' . esc_html( sprintf( __( ' — listes de lecture pour %s', 'yume-core' ), $titre ) ) . '</span></summary>'
		. '<div class="yn-oeuvre-actions__panneau yn-card">'
		. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-form="listes">'
		. champs_action( 'yume_listes_oeuvre', $oeuvre_id )
		. '<fieldset class="yn-oeuvre-actions__listes-choix"><legend>' . esc_html__( 'Mes listes', 'yume-core' ) . '</legend><ul class="yn-oeuvre-actions__listes-liste" data-yn-listes>';
	foreach ( $listes as $liste ) {
		$html .= html_case_liste( $liste, isset( $contient[ $liste['id'] ] ) );
	}
	$html .= '</ul></fieldset>'
		. '<p class="yn-oeuvre-actions__nouvelle"><label for="yn-nouvelle-liste">' . esc_html__( 'Nouvelle liste', 'yume-core' ) . '</label>'
		. '<span class="yn-oeuvre-actions__nouvelle-ligne"><input type="text" id="yn-nouvelle-liste" name="yn_nouvelle" maxlength="' . esc_attr( (string) LISTE_NOM_MAX ) . '" autocomplete="off" data-yn-nouvelle-liste>'
		. ' <button type="submit" name="yn_creer" value="1" class="yn-btn yn-btn--sm" data-yn-creer-liste>' . esc_html__( 'Créer', 'yume-core' ) . '</button></span></p>'
		. '<div class="yn-oeuvre-actions__boutons"><button type="submit" class="yn-btn yn-btn--primary yn-btn--sm yn-sans-js">' . esc_html__( 'Enregistrer mes listes', 'yume-core' ) . '</button>'
		. '<a class="yn-oeuvre-actions__gerer" href="' . esc_url( url_compte() . '#yn-listes' ) . '">' . esc_html__( 'Gérer mes listes', 'yume-core' ) . '</a></div>'
		. '</form></div></details>';
	return $html;
}

/**
 * Case d'une liste dans le menu de la fiche.
 *
 * @param array $liste    Liste.
 * @param bool  $contient L'œuvre est dans la liste.
 */
function html_case_liste( array $liste, bool $contient ): string {
	$id = 'yn-liste-' . $liste['id'];
	return '<li><label class="yn-oeuvre-actions__liste" for="' . esc_attr( $id ) . '">'
		. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="yn_listes[]" value="' . esc_attr( (string) $liste['id'] ) . '" data-yn-liste="' . esc_attr( (string) $liste['id'] ) . '"' . checked( $contient, true, false ) . '>'
		. ' <span>' . esc_html( $liste['nom'] ) . '</span>'
		. ( $liste['publique'] ? ' <span class="yn-muted">' . esc_html__( '(publique)', 'yume-core' ) . '</span>' : '' )
		. '</label></li>';
}

/**
 * Insère le menu des listes (et les messages de listes) dans le HTML du bloc
 * yume/oeuvre-actions, à côté de Favori et Alerte (appelé par le render.php du bloc).
 *
 * @param string         $html Rendu du bloc.
 * @param \WP_Block|null $bloc Instance.
 */
function inserer_menu_listes( string $html, $bloc = null ): string {
	if ( '' === $html || ! is_user_logged_in() || apercu_editeur() ) {
		return $html;
	}
	$menu = html_menu_listes( oeuvre_contexte( $bloc ) );
	if ( '' === $menu ) {
		return $html;
	}
	$messages = html_messages( messages_listes_courants() );
	if ( '' !== $messages ) {
		$pos = strpos( $html, '<div class="yn-oeuvre-actions__ligne">' );
		if ( false !== $pos ) {
			$html = substr_replace( $html, $messages, $pos, 0 );
		}
	}
	// Fin de la ligne des boutons : juste avant la zone d'annonce (dernier élément du bloc).
	$marqueur = '</div><p class="yn-visually-hidden" role="status"';
	$pos      = strrpos( $html, $marqueur );
	if ( false === $pos ) {
		$pos = strrpos( $html, '</div>' );
	}
	return false === $pos ? $html . $menu : substr_replace( $html, $menu, $pos, 0 );
}

/**
 * Formulaire de la fiche sans JavaScript : enregistre les cases cochées et crée au besoin une
 * nouvelle liste contenant l'œuvre.
 */
function action_listes_oeuvre(): void {
	list( $oeuvre_id, $retour, $ancre ) = contexte_action();
	exiger_nonce( 'yume_social_' . $oeuvre_id, $retour, $ancre );
	$user_id = get_current_user_id();
	if ( ! oeuvre_publiee( $oeuvre_id ) ) {
		rediriger_listes( $retour, 'listes-erreur', $ancre );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié ci-dessus.
	$cochees  = isset( $_POST['yn_listes'] ) && is_array( $_POST['yn_listes'] ) ? array_map( 'absint', wp_unslash( $_POST['yn_listes'] ) ) : array();
	$contient = listes_contenant( $user_id, $oeuvre_id );
	$code     = 'listes-ok';
	foreach ( listes_utilisateur( $user_id ) as $liste ) {
		$voulue = in_array( $liste['id'], $cochees, true );
		if ( $voulue && ! isset( $contient[ $liste['id'] ] ) ) {
			$resultat = ajouter_a_liste( $liste, $oeuvre_id );
			if ( is_wp_error( $resultat ) ) {
				$code = code_erreur_liste( $resultat );
			}
		} elseif ( ! $voulue && isset( $contient[ $liste['id'] ] ) ) {
			retirer_de_liste( $liste, $oeuvre_id );
		}
	}
	$nom = champ_post( 'yn_nouvelle' );
	if ( '' !== $nom ) {
		$liste = creer_liste( $user_id, $nom );
		if ( is_wp_error( $liste ) ) {
			$code = code_erreur_liste( $liste );
		} else {
			ajouter_a_liste( $liste, $oeuvre_id );
			$code = 'liste-creee';
		}
	} elseif ( '' !== champ_post( 'yn_creer' ) ) {
		$code = 'liste-nom';
	}
	rediriger_listes( $retour, $code, $ancre );
}
add_action( 'admin_post_yume_listes_oeuvre', __NAMESPACE__ . '\\action_listes_oeuvre' );
add_action( 'admin_post_nopriv_yume_listes_oeuvre', __NAMESPACE__ . '\\action_visiteur' );

/*
 * -----------------------------------------------------------------------------
 * Page compte : rubrique « Mes listes »
 * -----------------------------------------------------------------------------
 */

/**
 * Champs communs d'un formulaire de la rubrique « Mes listes ».
 *
 * @param string $action Action admin-post.
 * @param int    $liste  Liste visée (0 : aucune).
 */
function champs_listes( string $action, int $liste = 0 ): string {
	return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
		. ( $liste > 0 ? '<input type="hidden" name="yn_liste" value="' . esc_attr( (string) $liste ) . '">' : '' )
		. '<input type="hidden" name="yn_retour" value="' . esc_url( url_courante() ) . '">'
		. wp_nonce_field( 'yume_listes', '_yn_nonce', false, false );
}

/**
 * Rubrique « Mes listes » de la page compte.
 *
 * @param int $user_id Membre.
 */
function section_listes( int $user_id ): string {
	$html = debut_section( 'yn-listes', __( 'Mes listes', 'yume-core' ), __( 'À lire, en cours, terminé et vos listes à partager', 'yume-core' ) );
	if ( ! preparer_tables_lecteur() ) {
		return $html . '<p class="yn-account__vide">' . esc_html__( 'Les listes ne sont pas disponibles pour le moment.', 'yume-core' ) . '</p></section>';
	}
	$html  .= html_messages( messages_listes_courants() );
	$action = esc_url( admin_url( 'admin-post.php' ) );
	$listes = listes_utilisateur( $user_id );

	// Remplissage automatique.
	$html .= '<form class="yn-card yn-account__bloc yn-listes__auto" method="post" action="' . $action . '">'
		. champs_listes( 'yume_listes_auto' )
		. '<ul class="yn-account__interrupteurs"><li class="yn-account__interrupteur"><label for="yn-listes-auto"><span class="yn-account__interrupteur-texte">' . esc_html__( 'Classer automatiquement mes lectures', 'yume-core' )
		. '<span class="yn-muted" id="yn-listes-auto-aide">' . esc_html__( 'Une œuvre passe dans « En cours » quand vous commencez à la lire, puis dans « Terminé » quand vous avez lu son dernier chapitre publié.', 'yume-core' ) . '</span></span>'
		. '<input type="checkbox" role="switch" class="yn-interrupteur" id="yn-listes-auto" name="yn_auto" value="1" aria-describedby="yn-listes-auto-aide"' . checked( listes_auto( $user_id ), true, false ) . '></label></li></ul>'
		. '<div class="yn-account__boutons"><button type="submit" class="yn-btn">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button></div></form>';

	$html .= '<div class="yn-listes">';
	foreach ( $listes as $liste ) {
		$html .= carte_liste_compte( $liste, $action );
	}
	$html .= '</div>';

	// Nouvelle liste.
	$nb_perso = nb_listes_personnelles( $user_id );
	$html    .= '<form class="yn-card yn-account__bloc yn-account__formulaire yn-listes__creer" method="post" action="' . $action . '">'
		. champs_listes( 'yume_liste_creer' )
		. '<h3 class="yn-account__sous-titre">' . esc_html__( 'Nouvelle liste', 'yume-core' ) . '</h3>';
	if ( $nb_perso >= LISTES_MAX ) {
		$html .= '<p class="yn-muted">' . esc_html( messages_listes()['listes-limite'][1] ) . '</p></form>';
	} else {
		$html .= '<div class="yn-account__champs">'
			. '<p class="yn-account__champ"><label for="yn-liste-nom">' . esc_html__( 'Nom', 'yume-core' ) . '</label>'
			. '<input type="text" id="yn-liste-nom" name="yn_nom" required maxlength="' . esc_attr( (string) LISTE_NOM_MAX ) . '"></p>'
			. '<p class="yn-account__champ"><label for="yn-liste-description">' . esc_html__( 'Description (facultative)', 'yume-core' ) . '</label>'
			. '<textarea id="yn-liste-description" name="yn_description" rows="2" maxlength="' . esc_attr( (string) LISTE_DESCRIPTION_MAX ) . '"></textarea></p>'
			. '</div>'
			. '<p class="yn-account__champ yn-listes__case"><label><input type="checkbox" name="yn_publique" value="1"> ' . esc_html__( 'Liste publique : toute personne ayant le lien peut la consulter', 'yume-core' ) . '</label></p>'
			/* translators: 1 : listes personnelles existantes, 2 : maximum. */
			. '<p class="yn-muted yn-account__note">' . esc_html( sprintf( _n( '%1$d liste personnelle sur %2$d.', '%1$d listes personnelles sur %2$d.', $nb_perso, 'yume-core' ), $nb_perso, LISTES_MAX ) ) . '</p>'
			. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Créer la liste', 'yume-core' ) . '</button></div></form>';
	}
	return $html . '</section>';
}

/**
 * Carte d'une liste dans la rubrique « Mes listes » : couvertures, lien public, œuvres
 * (retirer), modification (nom, description, visibilité) et suppression.
 *
 * @param array  $liste  Liste.
 * @param string $action Adresse admin-post (échappée).
 */
function carte_liste_compte( array $liste, string $action ): string {
	$oeuvres = oeuvres_liste( $liste['id'] );
	$id      = 'yn-liste-carte-' . $liste['id'];
	$html    = '<article class="yn-card yn-account__bloc yn-listes__carte" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id . '-titre' ) . '">'
		. '<div class="yn-listes__entete"><h3 class="yn-account__sous-titre" id="' . esc_attr( $id . '-titre' ) . '">' . esc_html( $liste['nom'] ) . '</h3>'
		/* translators: %d : nombre d'œuvres. */
		. '<span class="yn-muted">' . esc_html( sprintf( _n( '%d œuvre', '%d œuvres', count( $oeuvres ), 'yume-core' ), count( $oeuvres ) ) ) . '</span> '
		. ( $liste['publique'] ? pastille( 'ok', '◉', __( 'Publique', 'yume-core' ) ) : pastille( 'info', '◌', __( 'Privée', 'yume-core' ) ) )
		. '</div>';
	if ( '' !== $liste['description'] ) {
		$html .= '<p class="yn-listes__description">' . esc_html( $liste['description'] ) . '</p>';
	}
	if ( $liste['publique'] ) {
		$html .= '<p class="yn-listes__lien"><span class="yn-muted">' . esc_html__( 'Lien à partager :', 'yume-core' ) . '</span> <a href="' . esc_url( url_liste( $liste ) ) . '">' . esc_html( url_liste( $liste ) ) . '</a></p>';
	}
	if ( $oeuvres ) {
		$html .= '<ul class="yn-listes__oeuvres">';
		foreach ( $oeuvres as $oeuvre_id ) {
			$titre = wp_strip_all_tags( get_the_title( $oeuvre_id ) );
			$html .= '<li class="yn-listes__oeuvre">' . mini_couverture( $oeuvre_id, initiales( $titre ), 'yn-account__couverture--petite' )
				. '<a href="' . esc_url( (string) get_permalink( $oeuvre_id ) ) . '">' . esc_html( $titre ) . '</a>'
				. '<form method="post" action="' . $action . '">' . champs_listes( 'yume_liste_retirer', $liste['id'] )
				. '<input type="hidden" name="yn_oeuvre" value="' . esc_attr( (string) $oeuvre_id ) . '">'
				. '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html__( 'Retirer', 'yume-core' )
				/* translators: 1 : œuvre, 2 : liste. */
				. '<span class="yn-visually-hidden"> ' . esc_html( sprintf( __( '%1$s de la liste %2$s', 'yume-core' ), $titre, $liste['nom'] ) ) . '</span></button></form></li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-account__vide">' . esc_html__( 'Aucune œuvre pour l’instant : utilisez le bouton « Ajouter à une liste » d’une fiche d’œuvre.', 'yume-core' ) . '</p>';
	}

	// Modifier.
	$html .= '<details class="yn-listes__modifier"><summary>' . esc_html__( 'Modifier', 'yume-core' )
		/* translators: %s : nom de la liste. */
		. '<span class="yn-visually-hidden"> ' . esc_html( sprintf( __( 'la liste %s', 'yume-core' ), $liste['nom'] ) ) . '</span></summary>'
		. '<form class="yn-account__formulaire" method="post" action="' . $action . '">' . champs_listes( 'yume_liste_modifier', $liste['id'] )
		. '<div class="yn-account__champs">';
	if ( '' === $liste['systeme'] ) {
		$html .= '<p class="yn-account__champ"><label for="' . esc_attr( $id . '-nom' ) . '">' . esc_html__( 'Nom', 'yume-core' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id . '-nom' ) . '" name="yn_nom" required maxlength="' . esc_attr( (string) LISTE_NOM_MAX ) . '" value="' . esc_attr( $liste['nom'] ) . '"></p>';
	}
	$html .= '<p class="yn-account__champ"><label for="' . esc_attr( $id . '-description' ) . '">' . esc_html__( 'Description (facultative)', 'yume-core' ) . '</label>'
		. '<textarea id="' . esc_attr( $id . '-description' ) . '" name="yn_description" rows="2" maxlength="' . esc_attr( (string) LISTE_DESCRIPTION_MAX ) . '">' . esc_textarea( $liste['description'] ) . '</textarea></p>'
		. '</div>'
		. '<p class="yn-account__champ yn-listes__case"><label><input type="checkbox" name="yn_publique" value="1"' . checked( $liste['publique'], true, false ) . '> ' . esc_html__( 'Liste publique : toute personne ayant le lien peut la consulter', 'yume-core' ) . '</label></p>'
		. '<div class="yn-account__boutons"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button></div></form>';
	if ( '' === $liste['systeme'] ) {
		$html .= '<form class="yn-listes__supprimer" method="post" action="' . $action . '">' . champs_listes( 'yume_liste_supprimer', $liste['id'] )
			. '<button type="submit" class="yn-btn yn-account__bouton-danger">' . esc_html__( 'Supprimer cette liste', 'yume-core' ) . '</button></form>';
	}
	$html .= '</details>';
	return $html . '</article>';
}

/**
 * Contexte d'un formulaire de la rubrique « Mes listes » : nonce vérifié, membre connecté.
 *
 * @return array{0:int,1:string,2:?array} Membre, page de retour, liste visée (ou null).
 */
function contexte_listes(): array {
	exiger_connexion();
	$retour = page_retour( url_compte() );
	exiger_nonce( 'yume_listes', $retour, 'yn-listes' );
	$user_id = get_current_user_id();
	$liste   = liste_du_membre( absint( champ_post( 'yn_liste' ) ), $user_id );
	return array( $user_id, $retour, $liste );
}

/**
 * Création d'une liste depuis la page compte.
 */
function action_liste_creer(): void {
	list( $user_id, $retour ) = contexte_listes();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par contexte_listes().
	$description = isset( $_POST['yn_description'] ) && is_string( $_POST['yn_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['yn_description'] ) ) : '';
	$publique    = '' !== champ_post( 'yn_publique' );
	$liste       = creer_liste( $user_id, champ_post( 'yn_nom' ), $description, $publique );
	rediriger_listes( $retour, is_wp_error( $liste ) ? code_erreur_liste( $liste ) : 'liste-creee', 'yn-listes' );
}
add_action( 'admin_post_yume_liste_creer', __NAMESPACE__ . '\\action_liste_creer' );

/**
 * Modification d'une liste (nom, description, visibilité).
 */
function action_liste_modifier(): void {
	list( , $retour, $liste ) = contexte_listes();
	if ( ! $liste ) {
		rediriger_listes( $retour, 'liste-introuvable', 'yn-listes' );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par contexte_listes().
	$description = isset( $_POST['yn_description'] ) && is_string( $_POST['yn_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['yn_description'] ) ) : '';
	$champs      = array(
		'description' => $description,
		'publique'    => '' !== champ_post( 'yn_publique' ),
	);
	if ( '' === $liste['systeme'] ) {
		$champs['nom'] = champ_post( 'yn_nom' );
	}
	$resultat = modifier_liste( $liste, $champs );
	rediriger_listes( $retour, is_wp_error( $resultat ) ? code_erreur_liste( $resultat ) : 'liste-modifiee', 'yn-listes' );
}
add_action( 'admin_post_yume_liste_modifier', __NAMESPACE__ . '\\action_liste_modifier' );

/**
 * Suppression d'une liste personnelle.
 */
function action_liste_supprimer(): void {
	list( , $retour, $liste ) = contexte_listes();
	if ( ! $liste ) {
		rediriger_listes( $retour, 'liste-introuvable', 'yn-listes' );
	}
	$resultat = supprimer_liste( $liste );
	rediriger_listes( $retour, is_wp_error( $resultat ) ? code_erreur_liste( $resultat ) : 'liste-supprimee', 'yn-listes' );
}
add_action( 'admin_post_yume_liste_supprimer', __NAMESPACE__ . '\\action_liste_supprimer' );

/**
 * Retrait d'une œuvre d'une liste depuis la page compte.
 */
function action_liste_retirer(): void {
	list( , $retour, $liste ) = contexte_listes();
	if ( ! $liste ) {
		rediriger_listes( $retour, 'liste-introuvable', 'yn-listes' );
	}
	retirer_de_liste( $liste, absint( champ_post( 'yn_oeuvre' ) ) );
	rediriger_listes( $retour, 'liste-retiree', 'yn-listes' );
}
add_action( 'admin_post_yume_liste_retirer', __NAMESPACE__ . '\\action_liste_retirer' );

/**
 * Préférence de remplissage automatique.
 */
function action_listes_auto(): void {
	list( $user_id, $retour ) = contexte_listes();
	if ( '' !== champ_post( 'yn_auto' ) ) {
		delete_user_meta( $user_id, META_LISTES_AUTO );
	} else {
		update_user_meta( $user_id, META_LISTES_AUTO, '0' );
	}
	rediriger_listes( $retour, 'listes-auto-ok', 'yn-listes' );
}
add_action( 'admin_post_yume_listes_auto', __NAMESPACE__ . '\\action_listes_auto' );

/*
 * -----------------------------------------------------------------------------
 * Page publique d'une liste : /listes/{id}-{slug}/
 * -----------------------------------------------------------------------------
 */

/**
 * Règle de réécriture des listes : motif => requête.
 *
 * @return array<string,string>
 */
function regles_listes(): array {
	return array( '^listes/([0-9]+)(?:-([^/]*))?/?$' => 'index.php?' . QV_LISTE . '=$matches[1]' );
}

/**
 * Déclare la règle de réécriture et la variable de requête.
 */
function enregistrer_regles_listes(): void {
	foreach ( regles_listes() as $motif => $requete ) {
		add_rewrite_rule( $motif, $requete, 'top' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_regles_listes' );

/**
 * Variable de requête publique yume_liste.
 *
 * @param string[] $variables Variables.
 * @return string[]
 */
function variable_liste( $variables ): array {
	$variables   = (array) $variables;
	$variables[] = QV_LISTE;
	return $variables;
}
add_filter( 'query_vars', __NAMESPACE__ . '\\variable_liste' );

/**
 * Vide les règles de réécriture si la règle des listes n'est pas encore enregistrée (même
 * principe que Core\verifier_regles(), avec sa propre option yume_listes_regles).
 */
function verifier_regles_listes(): void {
	global $wp_rewrite;
	if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
		return;
	}
	$signature = md5( (string) wp_json_encode( regles_listes() ) . '|listes|' . YUME_CORE_VERSION );
	if ( get_option( OPTION_REGLES_LISTES ) === $signature ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( OPTION_REGLES_LISTES, $signature, true );
}
add_action( 'init', __NAMESPACE__ . '\\verifier_regles_listes', 101 );

/**
 * Identifiant de liste demandé par la requête principale (0 sinon).
 */
function liste_demandee_id(): int {
	global $wp_query;
	return $wp_query instanceof \WP_Query ? absint( $wp_query->get( QV_LISTE ) ) : 0;
}

/**
 * Liste affichée par la page courante si elle est visible par la personne qui la consulte
 * (publique, ou la sienne), sinon null.
 */
function liste_affichee(): ?array {
	$id = liste_demandee_id();
	if ( ! $id ) {
		return null;
	}
	$liste = liste( $id );
	if ( ! $liste || ! get_userdata( $liste['user_id'] ) ) {
		return null;
	}
	if ( ! $liste['publique'] && get_current_user_id() !== $liste['user_id'] ) {
		return null;
	}
	return $liste;
}

/**
 * La requête d'une liste n'est ni l'accueil ni une archive d'articles.
 *
 * @param \WP_Query $requete Requête.
 */
function requete_liste( $requete ): void {
	if ( $requete instanceof \WP_Query && $requete->is_main_query() && absint( $requete->get( QV_LISTE ) ) ) {
		$requete->is_home = false;
	}
}
add_action( 'parse_query', __NAMESPACE__ . '\\requete_liste' );

/**
 * Aucun article à charger pour la page d'une liste.
 *
 * @param array|null $posts   Résultat court-circuité.
 * @param \WP_Query  $requete Requête.
 * @return array|null
 */
function articles_liste( $posts, $requete ) {
	if ( $requete instanceof \WP_Query && $requete->is_main_query() && absint( $requete->get( QV_LISTE ) ) ) {
		$requete->found_posts = 0;
		return array();
	}
	return $posts;
}
add_filter( 'posts_pre_query', __NAMESPACE__ . '\\articles_liste', 10, 2 );

/**
 * Page d'une liste : la décision 404 revient à liste_template_redirect().
 *
 * @param bool $court Court-circuit.
 */
function liste_sans_404_auto( $court ) {
	return liste_demandee_id() ? true : $court;
}
add_filter( 'pre_handle_404', __NAMESPACE__ . '\\liste_sans_404_auto' );

/**
 * Pas de redirection canonique de WordPress sur la page d'une liste.
 *
 * @param string|false $url URL de redirection.
 * @return string|false
 */
function liste_sans_canonique( $url ) {
	return liste_demandee_id() ? false : $url;
}
add_filter( 'redirect_canonical', __NAMESPACE__ . '\\liste_sans_canonique' );

/**
 * Liste invisible (privée, inconnue) : 404. Adresse non canonique (ancien nom) : 301.
 */
function liste_template_redirect(): void {
	global $wp_query;
	if ( ! liste_demandee_id() ) {
		return;
	}
	$liste = liste_affichee();
	if ( ! $liste ) {
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}
	if ( ! $liste['publique'] ) {
		nocache_headers();
	}
	global $wp_rewrite;
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé seulement.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
	if ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() && '' !== $uri ) {
		$canonique = (string) wp_parse_url( url_liste( $liste ), PHP_URL_PATH );
		if ( untrailingslashit( $uri ) !== untrailingslashit( $canonique ) ) {
			wp_safe_redirect( url_liste( $liste ), 301 );
			exit;
		}
	}
}
add_action( 'template_redirect', __NAMESPACE__ . '\\liste_template_redirect', 5 );

/**
 * Gabarit de la page d'une liste : modèle « yume-liste » du thème s'il existe, sinon en-tête,
 * bloc yume/liste-publique et pied du thème (filtre yume_liste_gabarit).
 */
function gabarit_liste(): string {
	$contenu = '';
	if ( function_exists( 'get_block_template' ) ) {
		$modele = get_block_template( get_stylesheet() . '//yume-liste' );
		if ( $modele && ! empty( $modele->content ) ) {
			$contenu = (string) $modele->content;
		}
	}
	if ( '' === $contenu ) {
		$contenu = '<!-- wp:template-part {"slug":"header","tagName":"header","className":"yn-site-header"} /-->' . "\n"
			. '<!-- wp:group {"tagName":"main","className":"yn-main yn-page-large","layout":{"type":"constrained","contentSize":"1344px"}} -->' . "\n"
			. '<main class="wp-block-group yn-main yn-page-large"><!-- wp:yume/liste-publique /--></main>' . "\n"
			. '<!-- /wp:group -->' . "\n"
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer","className":"yn-site-footer"} /-->';
	}
	/**
	 * Filtre le gabarit (balisage de blocs) de la page publique d'une liste de lecture.
	 *
	 * @param string $contenu Balisage de blocs.
	 */
	return (string) apply_filters( 'yume_liste_gabarit', $contenu );
}

/**
 * Rendu de la page d'une liste dans le thème de blocs (template-canvas de WordPress).
 *
 * @param string $template Gabarit choisi.
 */
function liste_template_include( $template ) {
	if ( ! liste_demandee_id() || is_404() || ! liste_affichee() || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
		return $template;
	}
	global $_wp_current_template_content, $_wp_current_template_id;
	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- gabarit de blocs de la page, comme locate_block_template().
	$_wp_current_template_content = gabarit_liste();
	$_wp_current_template_id      = get_stylesheet() . '//yume-liste';
	// phpcs:enable
	return ABSPATH . WPINC . '/template-canvas.php';
}
add_filter( 'template_include', __NAMESPACE__ . '\\liste_template_include', 50 );

/**
 * Titre du document de la page d'une liste.
 *
 * @param array $parties Parties du titre.
 * @return array
 */
function titre_document_liste( $parties ): array {
	$parties = (array) $parties;
	$liste   = liste_demandee_id() && ! is_404() ? liste_affichee() : null;
	if ( $liste ) {
		$auteur = get_userdata( $liste['user_id'] );
		/* translators: 1 : nom de la liste, 2 : nom affiché du membre. */
		$parties['title'] = sprintf( __( '%1$s, liste de %2$s', 'yume-core' ), $liste['nom'], $auteur ? $auteur->display_name : '' );
		unset( $parties['tagline'] );
	}
	return $parties;
}
add_filter( 'document_title_parts', __NAMESPACE__ . '\\titre_document_liste' );

/**
 * Pages de listes : noindex par défaut (filtre yume_listes_indexables).
 *
 * @param array $robots Directives.
 * @return array
 */
function robots_liste( $robots ): array {
	$robots = (array) $robots;
	if ( liste_demandee_id() ) {
		/**
		 * Les pages publiques des listes de lecture peuvent-elles être indexées ?
		 *
		 * @param bool $indexables Faux par défaut.
		 */
		if ( is_404() || ! apply_filters( 'yume_listes_indexables', false ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['max-image-preview'] );
		}
	}
	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots_liste' );

/**
 * Enregistre le bloc yume/liste-publique (page d'une liste).
 */
function enregistrer_bloc_liste(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/liste-publique' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc_liste' );

/**
 * Rendu du bloc yume/liste-publique : titre, auteur (nom affiché, jamais l'identifiant de
 * connexion), description, couvertures des œuvres publiées.
 */
function rendu_liste_publique(): string {
	if ( apercu_editeur() ) {
		return rendu_apercu( 'yn-liste-publique', __( 'Liste de lecture publique : titre, auteur et couvertures des œuvres (page /listes/…).', 'yume-core' ) );
	}
	$liste = liste_affichee();
	if ( ! $liste ) {
		return '';
	}
	$auteur  = get_userdata( $liste['user_id'] );
	$nom     = $auteur ? (string) $auteur->display_name : '';
	$oeuvres = oeuvres_liste( $liste['id'] );
	$maj     = wp_date( 'j F Y', (int) strtotime( $liste['maj_le'] . ' UTC' ) );

	$html = '<div ' . attributs_racine( 'yn-liste-publique' ) . '>'
		. '<header class="yn-liste-publique__entete">'
		. '<p class="yn-label">' . esc_html__( 'Liste de lecture', 'yume-core' ) . '</p>'
		. '<h1 class="yn-liste-publique__titre">' . esc_html( $liste['nom'] ) . '</h1>'
		. '<p class="yn-muted yn-liste-publique__meta">'
		/* translators: %s : nom affiché du membre. */
		. ( '' !== $nom ? sprintf( esc_html__( 'par %s', 'yume-core' ), '<strong>' . esc_html( $nom ) . '</strong>' ) . ' · ' : '' )
		/* translators: %d : nombre d'œuvres. */
		. esc_html( sprintf( _n( '%d œuvre', '%d œuvres', count( $oeuvres ), 'yume-core' ), count( $oeuvres ) ) )
		/* translators: %s : date de mise à jour. */
		. ' · ' . esc_html( sprintf( __( 'mise à jour le %s', 'yume-core' ), $maj ) ) . '</p>';
	if ( '' !== $liste['description'] ) {
		$html .= '<p class="yn-liste-publique__description">' . esc_html( $liste['description'] ) . '</p>';
	}
	if ( get_current_user_id() === $liste['user_id'] ) {
		$html .= '<p class="yn-liste-publique__proprietaire">'
			. ( $liste['publique']
				? pastille( 'ok', '◉', __( 'Publique : partagez l’adresse de cette page', 'yume-core' ) )
				: pastille( 'info', '◌', __( 'Privée : vous seul voyez cette page', 'yume-core' ) ) )
			. ' <a href="' . esc_url( url_compte() . '#yn-listes' ) . '">' . esc_html__( 'Gérer mes listes', 'yume-core' ) . '</a></p>';
	}
	$html .= '</header>';

	if ( ! $oeuvres ) {
		return $html . '<p class="yn-liste-publique__vide">' . esc_html__( 'Cette liste est vide pour l’instant.', 'yume-core' ) . '</p></div>';
	}
	$html .= '<ul class="yn-liste-publique__grille">';
	foreach ( $oeuvres as $rang => $oeuvre_id ) {
		$titre    = wp_strip_all_tags( get_the_title( $oeuvre_id ) );
		$image_id = (int) get_post_thumbnail_id( $oeuvre_id );
		if ( function_exists( '\Yume\Core\Library\couverture' ) ) {
			$cover = \Yume\Core\Library\couverture(
				$image_id,
				array(
					'alt'        => '',
					'texte'      => $titre,
					'chargement' => $rang < 6 ? 'eager' : 'lazy',
					'sizes'      => '(min-width: 1200px) 200px, (min-width: 600px) 25vw, 45vw',
				)
			);
		} else {
			$cover = mini_couverture( $oeuvre_id, $titre );
		}
		$html .= '<li class="yn-liste-publique__item"><a class="yn-liste-publique__lien" href="' . esc_url( (string) get_permalink( $oeuvre_id ) ) . '">'
			. $cover . '<span class="yn-liste-publique__oeuvre">' . esc_html( $titre ) . '</span></a></li>';
	}
	return $html . '</ul></div>';
}
