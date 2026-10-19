<?php
/**
 * Corrections, socle : constantes, table, réglages, numérotation des paragraphes et cycle de vie
 * d'un signalement de coquille (signaler, lister, compter, traiter, anonymiser, purger).
 *
 * Numérotation : un « paragraphe » est un bloc de premier niveau du contenu enregistré du
 * chapitre (les blocs vides sans nom sont ignorés), comme le lecteur (enfants directs de
 * .wp-block-post-content, data-yn-paragraphe) et la recherche (Library\paragraphe_occurrence()) ;
 * index 0 pour le premier, ancre #yn-p-N avec N = index + 1. Contenu sans blocs : paragraphes
 * séparés par </p> ou une ligne vide.
 *
 * Signatures figées : docs/06-contrat-technique.md §8 quater.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

/** Statuts d'un signalement. */
const STATUTS = array( 'nouveau', 'corrige', 'rejete' );

/** Option : version du schéma de la table des signalements. */
const OPTION_SCHEMA = 'yume_corrections_schema';

/** Version du schéma de la table des signalements. */
const VERSION_SCHEMA = '1';

/** Tâche quotidienne : purge des signalements traités, effacement des empreintes d'IP. */
const HOOK_PURGE = 'yume_corrections_purge';

/** Longueur minimale d'un extrait (caractères, espaces normalisées). */
const EXTRAIT_MIN = 3;

/** Longueur maximale d'un extrait. */
const EXTRAIT_MAX = 300;

/** Longueur maximale du contexte (texte du paragraphe gardé avec le signalement). */
const CONTEXTE_MAX = 600;

/** Longueur maximale du commentaire. */
const COMMENTAIRE_MAX = 500;

/** Signalements par heure et par compte (filtre yume_coquilles_debit). */
const DEBIT_COMPTE = 20;

/** Signalements par heure et par adresse IP en mode anonyme. */
const DEBIT_ANONYME = 5;

/** Signalements « nouveau » au plus par chapitre. */
const SATURATION_CHAPITRE = 200;

/** Conservation des signalements traités, en jours (filtre yume_coquilles_retention). */
const RETENTION_JOURS = 180;

/** Conservation de l'empreinte d'IP d'un signalement anonyme, en jours. */
const RETENTION_IP_JOURS = 30;

/** Signalements par page de la vue de l'espace équipe. */
const PAR_PAGE = 50;

/** Clé de la vue de l'espace équipe. */
const VUE_CLE = 'corrections';

/*
 * -----------------------------------------------------------------------------
 * Table et réglages
 * -----------------------------------------------------------------------------
 */

/**
 * Nom complet de la table des signalements.
 */
function table_coquilles(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_coquilles';
}

/**
 * La table des signalements est-elle installée ?
 */
function table_prete(): bool {
	return VERSION_SCHEMA === get_option( OPTION_SCHEMA );
}

/**
 * Valeur booléenne d'un réglage du module (yume_setting(), défaut si le module core manque).
 *
 * @param string $cle    Clé.
 * @param bool   $defaut Défaut.
 */
function reglage( string $cle, bool $defaut ): bool {
	return function_exists( 'yume_setting' ) ? (bool) yume_setting( $cle, $defaut ) : $defaut;
}

/**
 * Le signalement des coquilles est-il ouvert ? (réglage coquilles_actives, filtre final
 * yume_coquilles_actives).
 */
function coquilles_actives(): bool {
	/**
	 * Filtre l'ouverture du signalement des coquilles.
	 *
	 * @param bool $actives Réglage coquilles_actives (vrai par défaut).
	 */
	return (bool) apply_filters( 'yume_coquilles_actives', reglage( 'coquilles_actives', true ) );
}

/**
 * Les visiteurs sans compte peuvent-ils signaler ? (réglage coquilles_anonymes, faux par défaut ;
 * exige le jeton horodaté du module social).
 */
function anonymes_autorises(): bool {
	return reglage( 'coquilles_anonymes', false ) && function_exists( 'Yume\\Core\\Social\\verifier_jeton' );
}

/**
 * Section « Lecteurs » de Yume → Réglages (filtre yume_reglages_sections).
 *
 * @param array<string,string> $sections Sections.
 * @return array<string,string>
 */
function section_reglages( $sections ): array {
	$sections             = is_array( $sections ) ? $sections : array();
	$sections['lecteurs'] = __( 'Lecteurs', 'yume-core' );
	return $sections;
}
add_filter( 'yume_reglages_sections', __NAMESPACE__ . '\\section_reglages' );

/**
 * Champs de la section « Lecteurs » (filtre yume_reglages_champs).
 *
 * @param array $champs Champs.
 * @return array
 */
function champs_reglages( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'coquilles_actives',
		'label'       => __( 'Signalement des coquilles', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'lecteurs',
		'default'     => true,
		'description' => __( 'Dans un chapitre, un lecteur sélectionne un passage puis « Signaler une coquille » ; les signalements arrivent dans l’espace équipe (Catalogue → Corrections).', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'coquilles_anonymes',
		'label'       => __( 'Signalements sans compte', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'lecteurs',
		'default'     => false,
		'description' => __( 'Permet aux visiteurs non connectés de signaler une coquille (5 signalements par heure et par connexion, protection anti-robots). Sinon, le bouton invite à se connecter.', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'coquilles_discord',
		'label'       => __( 'Coquilles sur Discord', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'lecteurs',
		'default'     => true,
		'description' => __( 'Annonce chaque signalement dans le salon de l’équipe (webhook « équipe »), un message par chapitre toutes les 10 minutes au plus.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages' );

/*
 * -----------------------------------------------------------------------------
 * Chapitres et paragraphes
 * -----------------------------------------------------------------------------
 */

/**
 * Un contenu est-il publié, sans mot de passe ?
 *
 * @param int    $id   Contenu.
 * @param string $type Type attendu.
 */
function publie( int $id, string $type ): bool {
	$post = $id > 0 ? get_post( $id ) : null;
	return $post instanceof \WP_Post && $type === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password;
}

/**
 * Un chapitre peut-il recevoir des signalements ? Chapitre texte publié, sans mot de passe,
 * d'un tome et d'une œuvre publiés ; jamais un chapitre lu sur MangaDex.
 *
 * @param int $chapitre_id Chapitre.
 */
function chapitre_signalable( int $chapitre_id ): bool {
	if ( ! publie( $chapitre_id, 'yume_chapitre' ) || ! function_exists( 'yume_get_tome_id' ) || ! function_exists( 'yume_get_oeuvre_id' ) ) {
		return false;
	}
	if ( ! publie( yume_get_tome_id( $chapitre_id ), 'yume_tome' ) || ! publie( yume_get_oeuvre_id( $chapitre_id ), 'yume_oeuvre' ) ) {
		return false;
	}
	return ! function_exists( 'yume_mangadex_chapitre' ) || null === yume_mangadex_chapitre( $chapitre_id );
}

/**
 * Texte normalisé : balises retirées, entités décodées, apostrophes et espaces insécables
 * unifiées, espaces réduites.
 *
 * @param string $texte Texte ou HTML.
 */
function normaliser_texte( string $texte ): string {
	$texte = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $texte );
	$texte = (string) preg_replace( '#</(p|div|h[1-6]|li|blockquote|figcaption|td|th)>|<br\s*/?>#i', '$0 ', $texte );
	$texte = html_entity_decode( wp_strip_all_tags( $texte ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$texte = strtr(
		$texte,
		array(
			"\u{2019}" => "'",
			"\u{2018}" => "'",
			"\u{02BC}" => "'",
			"\u{00A0}" => ' ',
			"\u{202F}" => ' ',
			"\u{2009}" => ' ',
		)
	);
	return trim( (string) preg_replace( '/\s+/u', ' ', $texte ) );
}

/**
 * Empreinte d'un paragraphe : md5 du texte normalisé (insensible aux espaces, aux balises et à la
 * forme des apostrophes).
 *
 * @param string $texte Texte ou HTML du paragraphe.
 */
function empreinte_paragraphe( string $texte ): string {
	return md5( normaliser_texte( $texte ) );
}

/**
 * Textes des paragraphes d'un chapitre (même numérotation que le lecteur et la recherche).
 *
 * @param int $chapitre_id Chapitre.
 * @return string[] Texte normalisé de chaque paragraphe, dans l'ordre (index 0 pour le premier).
 */
function paragraphes_chapitre( int $chapitre_id ): array {
	$post = $chapitre_id > 0 ? get_post( $chapitre_id ) : null;
	if ( ! $post instanceof \WP_Post || 'yume_chapitre' !== $post->post_type ) {
		return array();
	}
	$contenu  = (string) $post->post_content;
	$morceaux = array();
	if ( has_blocks( $contenu ) ) {
		foreach ( parse_blocks( $contenu ) as $bloc ) {
			if ( null === $bloc['blockName'] && '' === trim( (string) $bloc['innerHTML'] ) ) {
				continue;
			}
			$morceaux[] = null === $bloc['blockName'] ? (string) $bloc['innerHTML'] : serialize_block( $bloc );
		}
	} else {
		$morceaux = preg_split( '#</p>|\n\s*\n#i', $contenu, -1, PREG_SPLIT_NO_EMPTY );
		$morceaux = array_values( array_filter( (array) $morceaux, static fn( $m ): bool => '' !== trim( wp_strip_all_tags( (string) $m ) ) ) );
	}
	return array_map( static fn( $m ): string => normaliser_texte( strip_shortcodes( (string) $m ) ), $morceaux );
}

/**
 * Texte du i-ème paragraphe d'un chapitre (index 0 pour le premier, ancre #yn-p-{index + 1}),
 * '' si l'index n'existe pas.
 *
 * @param int $chapitre_id Chapitre.
 * @param int $index       Index du paragraphe.
 */
function texte_paragraphe( int $chapitre_id, int $index ): string {
	$paragraphes = paragraphes_chapitre( $chapitre_id );
	return $index >= 0 && isset( $paragraphes[ $index ] ) ? $paragraphes[ $index ] : '';
}

/**
 * Contexte gardé avec un signalement : le texte du paragraphe, ou une fenêtre de CONTEXTE_MAX
 * caractères centrée sur l'extrait quand le paragraphe est plus long.
 *
 * @param string $texte   Texte normalisé du paragraphe.
 * @param string $extrait Extrait signalé.
 */
function contexte_autour( string $texte, string $extrait ): string {
	if ( mb_strlen( $texte ) <= CONTEXTE_MAX ) {
		return $texte;
	}
	$position = '' !== $extrait ? mb_stripos( $texte, $extrait ) : false;
	$debut    = false === $position ? 0 : max( 0, (int) $position - intdiv( CONTEXTE_MAX - mb_strlen( $extrait ), 2 ) );
	$debut    = min( $debut, mb_strlen( $texte ) - CONTEXTE_MAX );
	return mb_substr( $texte, $debut, CONTEXTE_MAX );
}

/**
 * Commentaire assaini : texte seul, adresses web retirées (sauf filtre yume_coquilles_liens),
 * COMMENTAIRE_MAX caractères au plus.
 *
 * @param string $commentaire Commentaire brut.
 */
function assainir_commentaire( string $commentaire ): string {
	$commentaire = sanitize_textarea_field( $commentaire );
	/**
	 * Les adresses web sont-elles gardées dans le commentaire d'un signalement ?
	 *
	 * @param bool $liens Défaut : faux (retirées, comme les liens des robots).
	 */
	if ( ! apply_filters( 'yume_coquilles_liens', false ) ) {
		$commentaire = (string) preg_replace( '#(?:\b[a-z][a-z0-9+.\-]*://|\bwww\.)\S+#iu', '', $commentaire );
	}
	$commentaire = (string) preg_replace( '/[ \t]+/u', ' ', $commentaire );
	$commentaire = trim( (string) preg_replace( '/ *\n */u', "\n", $commentaire ) );
	return mb_substr( $commentaire, 0, COMMENTAIRE_MAX );
}

/**
 * Empreinte (HMAC) de l'adresse IP de la requête, pour le mode anonyme.
 */
function empreinte_ip(): string {
	$ip = function_exists( 'Yume\\Core\\Social\\adresse_ip' ) ? \Yume\Core\Social\adresse_ip() : '';
	if ( '' === $ip ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}
	return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
}

/*
 * -----------------------------------------------------------------------------
 * Signalement
 * -----------------------------------------------------------------------------
 */

/**
 * Erreur d'un signalement.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 */
function erreur( string $code, string $message, int $statut ): \WP_Error {
	return new \WP_Error( $code, $message, array( 'status' => $statut ) );
}

/**
 * Débit dépassé ? (Social\limite_atteinte() ; sans module social, pas de limite).
 *
 * @param string $action Action.
 * @param int    $max    Maximum par heure.
 * @param string $cle    Clé ('' : adresse IP).
 */
function debit_atteint( string $action, int $max, string $cle ): bool {
	return function_exists( 'Yume\\Core\\Social\\limite_atteinte' ) && \Yume\Core\Social\limite_atteinte( $action, $max, HOUR_IN_SECONDS, $cle );
}

/**
 * Enregistre un signalement de coquille.
 *
 * Contrôles, dans l'ordre : signalement ouvert (403 yume_coquilles_inactives), chapitre signalable
 * (404 yume_chapitre_introuvable), compte connecté ou mode anonyme avec jeton horodaté et pot de
 * miel (401 rest_forbidden, 400 yume_coquille_jeton), extrait de 3 à 300 caractères et paragraphe
 * existant (400 yume_coquille_extrait), débit (429 yume_coquilles_debit : 20 par heure et par
 * compte, filtre yume_coquilles_debit ; 5 par heure et par IP en anonyme), un seul signalement
 * « nouveau » par compte (ou IP), chapitre et paragraphe (409 yume_coquille_doublon), 200
 * « nouveau » au plus par chapitre (429 yume_coquilles_chapitre_sature).
 *
 * @param int   $chapitre_id Chapitre.
 * @param int   $user_id     Compte (0 : visiteur, mode anonyme).
 * @param array $donnees     paragraphe (index ≥ 0), extrait, contexte (repli si le paragraphe n'a
 *                           pas de texte), commentaire ; anonyme : jeton, yn_site_web (pot de
 *                           miel), ip_hash (calculée si absente).
 * @return array{id:int,message:string}|\WP_Error
 */
function signaler_coquille( int $chapitre_id, int $user_id, array $donnees ) {
	global $wpdb;
	if ( ! coquilles_actives() || ! table_prete() ) {
		return erreur( 'yume_coquilles_inactives', __( 'Le signalement des coquilles est désactivé.', 'yume-core' ), 403 );
	}
	if ( ! chapitre_signalable( $chapitre_id ) ) {
		return erreur( 'yume_chapitre_introuvable', __( 'Chapitre introuvable.', 'yume-core' ), 404 );
	}
	$ip_hash = '';
	if ( $user_id <= 0 ) {
		$user_id = 0;
		if ( ! anonymes_autorises() ) {
			return erreur( 'rest_forbidden', __( 'Connectez-vous pour signaler une coquille.', 'yume-core' ), 401 );
		}
		$jeton = is_scalar( $donnees['jeton'] ?? null ) ? (string) $donnees['jeton'] : '';
		$miel  = is_scalar( $donnees['yn_site_web'] ?? null ) ? trim( (string) $donnees['yn_site_web'] ) : '';
		if ( '' !== $miel || 'ok' !== \Yume\Core\Social\verifier_jeton( $jeton ) ) {
			return erreur( 'yume_coquille_jeton', __( 'La page est restée ouverte trop longtemps : rechargez-la puis réessayez.', 'yume-core' ), 400 );
		}
		$ip_hash = is_string( $donnees['ip_hash'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/', $donnees['ip_hash'] ) ? $donnees['ip_hash'] : empreinte_ip();
	}

	$extrait = normaliser_texte( sanitize_text_field( is_scalar( $donnees['extrait'] ?? null ) ? (string) $donnees['extrait'] : '' ) );
	$taille  = mb_strlen( $extrait );
	if ( $taille < EXTRAIT_MIN || $taille > EXTRAIT_MAX ) {
		/* translators: 1: minimum, 2: maximum */
		return erreur( 'yume_coquille_extrait', sprintf( __( 'Le passage doit compter de %1$d à %2$d caractères.', 'yume-core' ), EXTRAIT_MIN, EXTRAIT_MAX ), 400 );
	}
	$paragraphe  = is_numeric( $donnees['paragraphe'] ?? null ) ? (int) $donnees['paragraphe'] : -1;
	$paragraphes = paragraphes_chapitre( $chapitre_id );
	if ( $paragraphe < 0 || ! isset( $paragraphes[ $paragraphe ] ) ) {
		return erreur( 'yume_coquille_extrait', __( 'Ce paragraphe n’existe pas dans le chapitre : rechargez la page puis réessayez.', 'yume-core' ), 400 );
	}
	$texte    = $paragraphes[ $paragraphe ];
	$contexte = '' !== $texte ? contexte_autour( $texte, $extrait ) : mb_substr( normaliser_texte( sanitize_textarea_field( is_scalar( $donnees['contexte'] ?? null ) ? (string) $donnees['contexte'] : '' ) ), 0, CONTEXTE_MAX );

	if ( $user_id > 0 ) {
		/**
		 * Signalements de coquilles autorisés par compte et par heure.
		 *
		 * @param int $max Défaut 20.
		 */
		$debit = max( 1, (int) apply_filters( 'yume_coquilles_debit', DEBIT_COMPTE ) );
		$trop  = debit_atteint( 'coquille', $debit, 'u' . $user_id );
	} else {
		$trop = debit_atteint( 'coquille_anonyme', DEBIT_ANONYME, '' );
	}
	if ( $trop ) {
		return erreur( 'yume_coquilles_debit', __( 'Vous avez envoyé beaucoup de signalements : réessayez dans une heure.', 'yume-core' ), 429 );
	}

	$table = table_coquilles();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table du module, valeurs préparées.
	$doublon = $user_id > 0
		? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND chapitre_id = %d AND paragraphe = %d AND statut = %s", $user_id, $chapitre_id, $paragraphe, 'nouveau' ) )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = 0 AND ip_hash = %s AND chapitre_id = %d AND paragraphe = %d AND statut = %s", $ip_hash, $chapitre_id, $paragraphe, 'nouveau' ) );
	if ( $doublon > 0 ) {
		return erreur( 'yume_coquille_doublon', __( 'Vous avez déjà signalé ce passage.', 'yume-core' ), 409 );
	}
	$ouverts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE chapitre_id = %d AND statut = %s", $chapitre_id, 'nouveau' ) );
	// phpcs:enable
	if ( $ouverts >= SATURATION_CHAPITRE ) {
		return erreur( 'yume_coquilles_chapitre_sature', __( 'Ce chapitre a déjà beaucoup de signalements en attente : l’équipe les traite, réessayez plus tard.', 'yume-core' ), 429 );
	}

	$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		array(
			'chapitre_id' => $chapitre_id,
			'tome_id'     => yume_get_tome_id( $chapitre_id ),
			'oeuvre_id'   => yume_get_oeuvre_id( $chapitre_id ),
			'user_id'     => $user_id,
			'paragraphe'  => $paragraphe,
			'extrait'     => $extrait,
			'contexte'    => $contexte,
			'empreinte'   => md5( $texte ),
			'commentaire' => assainir_commentaire( is_scalar( $donnees['commentaire'] ?? null ) ? (string) $donnees['commentaire'] : '' ),
			'statut'      => 'nouveau',
			'ip_hash'     => $ip_hash,
			'cree_le'     => gmdate( 'Y-m-d H:i:s' ),
			'traite_par'  => 0,
		),
		array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
	);
	if ( ! $ok ) {
		return erreur( 'yume_coquille_echec', __( 'Le signalement n’a pas pu être enregistré. Réessayez.', 'yume-core' ), 500 );
	}
	$id = (int) $wpdb->insert_id;
	/**
	 * Une coquille vient d'être signalée.
	 *
	 * @param int $id          Signalement.
	 * @param int $chapitre_id Chapitre.
	 * @param int $user_id     Compte (0 : visiteur).
	 */
	do_action( 'yume_coquille_signalee', $id, $chapitre_id, $user_id );
	return array(
		'id'      => $id,
		'message' => __( 'Merci ! L’équipe a reçu votre signalement.', 'yume-core' ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Lecture
 * -----------------------------------------------------------------------------
 */

/**
 * Ligne de la table normalisée (entiers typés).
 *
 * @param array $ligne Ligne brute.
 * @return array{id:int,chapitre_id:int,tome_id:int,oeuvre_id:int,user_id:int,paragraphe:int,extrait:string,contexte:string,empreinte:string,commentaire:string,statut:string,ip_hash:string,cree_le:string,traite_le:string,traite_par:int}
 */
function normaliser_ligne( array $ligne ): array {
	return array(
		'id'          => (int) ( $ligne['id'] ?? 0 ),
		'chapitre_id' => (int) ( $ligne['chapitre_id'] ?? 0 ),
		'tome_id'     => (int) ( $ligne['tome_id'] ?? 0 ),
		'oeuvre_id'   => (int) ( $ligne['oeuvre_id'] ?? 0 ),
		'user_id'     => (int) ( $ligne['user_id'] ?? 0 ),
		'paragraphe'  => (int) ( $ligne['paragraphe'] ?? 0 ),
		'extrait'     => (string) ( $ligne['extrait'] ?? '' ),
		'contexte'    => (string) ( $ligne['contexte'] ?? '' ),
		'empreinte'   => (string) ( $ligne['empreinte'] ?? '' ),
		'commentaire' => (string) ( $ligne['commentaire'] ?? '' ),
		'statut'      => (string) ( $ligne['statut'] ?? 'nouveau' ),
		'ip_hash'     => (string) ( $ligne['ip_hash'] ?? '' ),
		'cree_le'     => (string) ( $ligne['cree_le'] ?? '' ),
		'traite_le'   => (string) ( $ligne['traite_le'] ?? '' ),
		'traite_par'  => (int) ( $ligne['traite_par'] ?? 0 ),
	);
}

/**
 * Un signalement, ou null.
 *
 * @param int $id Signalement.
 */
function coquille( int $id ): ?array {
	global $wpdb;
	if ( $id <= 0 || ! table_prete() ) {
		return null;
	}
	$table = table_coquilles();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	return is_array( $ligne ) ? normaliser_ligne( $ligne ) : null;
}

/**
 * Clause WHERE (préparée) des filtres de lister_coquilles().
 *
 * @param array $args statut, oeuvre, tome, chapitre, user_id.
 */
function clause_filtres( array $args ): string {
	global $wpdb;
	$where  = array( '1=1' );
	$statut = (string) ( $args['statut'] ?? 'nouveau' );
	if ( in_array( $statut, STATUTS, true ) ) {
		$where[] = $wpdb->prepare( 'statut = %s', $statut );
	}
	foreach ( array(
		'oeuvre'   => 'oeuvre_id',
		'tome'     => 'tome_id',
		'chapitre' => 'chapitre_id',
	) as $cle => $colonne ) {
		if ( ! empty( $args[ $cle ] ) ) {
			$where[] = $wpdb->prepare( "{$colonne} = %d", (int) $args[ $cle ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de colonne fixe.
		}
	}
	if ( isset( $args['user_id'] ) && null !== $args['user_id'] && '' !== $args['user_id'] ) {
		$where[] = $wpdb->prepare( 'user_id = %d', (int) $args['user_id'] );
	}
	return implode( ' AND ', $where );
}

/**
 * Signalements filtrés, du plus récent au plus ancien.
 *
 * @param array $args statut ('nouveau' par défaut | 'corrige' | 'rejete' | 'tous'), oeuvre, tome,
 *                    chapitre, user_id (null : tous), page (1…), limite (50 par défaut, 0 : tous).
 * @return array{lignes:array<int,array>,total:int}
 */
function lister_coquilles( array $args ): array {
	global $wpdb;
	if ( ! table_prete() ) {
		return array(
			'lignes' => array(),
			'total'  => 0,
		);
	}
	$table  = table_coquilles();
	$where  = clause_filtres( $args );
	$limite = isset( $args['limite'] ) ? max( 0, (int) $args['limite'] ) : PAR_PAGE;
	$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- clause construite avec prepare().
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY cree_le DESC, id DESC";
	if ( $limite > 0 ) {
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limite, ( $page - 1 ) * $limite );
	}
	$lignes = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- clause et limite construites avec prepare().
	// phpcs:enable
	return array(
		'lignes' => array_map( __NAMESPACE__ . '\\normaliser_ligne', array_filter( $lignes, 'is_array' ) ),
		'total'  => $total,
	);
}

/**
 * Nombre de signalements « nouveau » (d'une œuvre, ou de tout le site).
 *
 * @param int $oeuvre_id Œuvre (0 : toutes).
 */
function compter_nouvelles( int $oeuvre_id = 0 ): int {
	global $wpdb;
	if ( ! table_prete() ) {
		return 0;
	}
	$table = table_coquilles();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return $oeuvre_id > 0
		? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE oeuvre_id = %d AND statut = %s", $oeuvre_id, 'nouveau' ) )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE statut = %s", 'nouveau' ) );
	// phpcs:enable
}

/**
 * Nombre de signalements par statut (filtres œuvre, tome).
 *
 * @param array $args oeuvre, tome.
 * @return array<string,int> statut => nombre (les trois statuts).
 */
function compter_par_statut( array $args = array() ): array {
	global $wpdb;
	$comptes = array_fill_keys( STATUTS, 0 );
	if ( ! table_prete() ) {
		return $comptes;
	}
	$table = table_coquilles();
	$where = clause_filtres( array_merge( $args, array( 'statut' => 'tous' ) ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- clause construite avec prepare().
	foreach ( (array) $wpdb->get_results( "SELECT statut, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY statut", ARRAY_A ) as $ligne ) {
		if ( isset( $comptes[ $ligne['statut'] ] ) ) {
			$comptes[ $ligne['statut'] ] = (int) $ligne['n'];
		}
	}
	return $comptes;
}

/*
 * -----------------------------------------------------------------------------
 * Traitement
 * -----------------------------------------------------------------------------
 */

/**
 * Change le statut d'un signalement (Corrigé, Rejeté, ou « nouveau » pour Rouvrir) : réservé à
 * qui peut modifier le chapitre (edit_post).
 *
 * @param int    $id      Signalement.
 * @param string $statut  nouveau, corrige ou rejete.
 * @param int    $user_id Compte qui traite.
 * @return true|\WP_Error 400 yume_coquille_statut, 404 yume_coquille_introuvable, 403 yume_coquille_interdite.
 */
function changer_statut( int $id, string $statut, int $user_id ) {
	global $wpdb;
	if ( ! in_array( $statut, STATUTS, true ) ) {
		return erreur( 'yume_coquille_statut', __( 'Statut inconnu.', 'yume-core' ), 400 );
	}
	$ligne = coquille( $id );
	if ( ! $ligne ) {
		return erreur( 'yume_coquille_introuvable', __( 'Signalement introuvable.', 'yume-core' ), 404 );
	}
	if ( $user_id <= 0 || ! user_can( $user_id, 'edit_post', $ligne['chapitre_id'] ) ) {
		return erreur( 'yume_coquille_interdite', __( 'Seul un compte qui peut modifier ce chapitre (éditeur, gérant) peut traiter ce signalement.', 'yume-core' ), 403 );
	}
	if ( $ligne['statut'] === $statut ) {
		return true;
	}
	$nouveau = 'nouveau' === $statut;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->update(
		table_coquilles(),
		array(
			'statut'     => $statut,
			'traite_le'  => $nouveau ? null : gmdate( 'Y-m-d H:i:s' ),
			'traite_par' => $nouveau ? 0 : $user_id,
		),
		array( 'id' => $id ),
		array( '%s', '%s', '%d' ),
		array( '%d' )
	);
	if ( false === $ok ) {
		return erreur( 'yume_coquille_echec', __( 'Le signalement n’a pas pu être mis à jour. Réessayez.', 'yume-core' ), 500 );
	}
	/**
	 * Un signalement de coquille vient d'être traité (ou rouvert : statut « nouveau »).
	 *
	 * @param int    $id      Signalement.
	 * @param string $statut  Nouveau statut.
	 * @param int    $user_id Compte qui traite.
	 */
	do_action( 'yume_coquille_traitee', $id, $statut, $user_id );
	return true;
}

/**
 * Traite d'un coup tous les signalements « nouveau » d'un chapitre (« Tout marquer corrigé »).
 *
 * @param int    $chapitre_id Chapitre.
 * @param string $statut      corrige ou rejete.
 * @param int    $user_id     Compte qui traite (edit_post sur le chapitre).
 * @return int Signalements traités.
 */
function traiter_chapitre( int $chapitre_id, string $statut, int $user_id ): int {
	if ( ! in_array( $statut, array( 'corrige', 'rejete' ), true ) || $user_id <= 0 || ! user_can( $user_id, 'edit_post', $chapitre_id ) ) {
		return 0;
	}
	$n = 0;
	foreach ( lister_coquilles(
		array(
			'statut'   => 'nouveau',
			'chapitre' => $chapitre_id,
			'limite'   => 0,
		)
	)['lignes'] as $ligne ) {
		if ( true === changer_statut( $ligne['id'], $statut, $user_id ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Détache les signalements d'un compte (suppression du compte, RGPD) : user_id mis à 0, le
 * signalement reste utile à l'équipe.
 *
 * @param int $user_id Compte.
 * @return int Signalements anonymisés.
 */
function anonymiser_coquilles( int $user_id ): int {
	global $wpdb;
	if ( $user_id <= 0 || ! table_prete() ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n = $wpdb->update( table_coquilles(), array( 'user_id' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( table_coquilles(), array( 'traite_par' => 0 ), array( 'traite_par' => $user_id ), array( '%d' ), array( '%d' ) );
	return (int) $n;
}

/**
 * Purge : signalements traités (corrigés ou rejetés) depuis plus de $jours jours supprimés,
 * empreintes d'IP de plus de RETENTION_IP_JOURS jours effacées.
 *
 * @param int $jours Conservation des signalements traités, en jours.
 * @return int Signalements supprimés.
 */
function purger_coquilles( int $jours = RETENTION_JOURS ): int {
	global $wpdb;
	if ( ! table_prete() ) {
		return 0;
	}
	$table = table_coquilles();
	$jours = max( 1, $jours );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$n = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE statut IN (%s, %s) AND traite_le IS NOT NULL AND traite_le < %s",
			'corrige',
			'rejete',
			gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS )
		)
	);
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ip_hash = '' WHERE ip_hash <> '' AND cree_le < %s", gmdate( 'Y-m-d H:i:s', time() - RETENTION_IP_JOURS * DAY_IN_SECONDS ) ) );
	// phpcs:enable
	return $n;
}

/*
 * -----------------------------------------------------------------------------
 * Libellés
 * -----------------------------------------------------------------------------
 */

/**
 * Libellé d'un chapitre : « Chapitre 2 — La Crête Brumeuse » (titre omis s'il répète le libellé).
 *
 * @param int $chapitre_id Chapitre.
 */
function libelle_chapitre( int $chapitre_id ): string {
	$titre   = wp_strip_all_tags( get_the_title( $chapitre_id ) );
	$libelle = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	if ( '' === $libelle ) {
		return $titre;
	}
	return '' === $titre || false !== mb_stripos( $titre, $libelle ) ? ( '' !== $titre ? $titre : $libelle ) : $libelle . ' — ' . $titre;
}

/**
 * Fil d'un signalement : œuvre, tome, chapitre (éléments non vides).
 *
 * @param array $ligne Signalement normalisé.
 * @return string[]
 */
function fil( array $ligne ): array {
	$fil = array();
	if ( $ligne['oeuvre_id'] && get_post( $ligne['oeuvre_id'] ) ) {
		$fil[] = wp_strip_all_tags( get_the_title( $ligne['oeuvre_id'] ) );
	}
	if ( $ligne['tome_id'] && function_exists( 'yume_libelle_tome' ) ) {
		$tome = yume_libelle_tome( $ligne['tome_id'] );
		if ( '' !== $tome ) {
			$fil[] = $tome;
		}
	}
	$fil[] = get_post( $ligne['chapitre_id'] ) ? libelle_chapitre( $ligne['chapitre_id'] ) : __( 'Chapitre supprimé', 'yume-core' );
	return $fil;
}

/**
 * Adresse publique du paragraphe signalé (permalien#yn-p-N), '' si le chapitre n'est plus en ligne.
 *
 * @param array $ligne Signalement normalisé.
 */
function url_paragraphe( array $ligne ): string {
	if ( ! publie( $ligne['chapitre_id'], 'yume_chapitre' ) ) {
		return '';
	}
	$url = (string) get_permalink( $ligne['chapitre_id'] );
	return '' !== $url ? $url . '#yn-p-' . ( $ligne['paragraphe'] + 1 ) : '';
}

/**
 * Pseudo de l'auteur d'un signalement.
 *
 * @param array $ligne Signalement normalisé.
 */
function auteur( array $ligne ): string {
	if ( $ligne['user_id'] > 0 ) {
		$user = get_userdata( $ligne['user_id'] );
		if ( $user ) {
			return (string) $user->display_name;
		}
	}
	return '' !== $ligne['ip_hash'] ? __( 'Visiteur', 'yume-core' ) : __( 'Compte supprimé', 'yume-core' );
}

/**
 * Le paragraphe signalé a-t-il changé depuis le signalement (empreinte différente) ?
 *
 * @param array $ligne Signalement normalisé.
 */
function paragraphe_modifie( array $ligne ): bool {
	if ( '' === $ligne['empreinte'] || ! get_post( $ligne['chapitre_id'] ) ) {
		return false;
	}
	$paragraphes = paragraphes_chapitre( $ligne['chapitre_id'] );
	if ( ! isset( $paragraphes[ $ligne['paragraphe'] ] ) ) {
		return true;
	}
	return md5( $paragraphes[ $ligne['paragraphe'] ] ) !== $ligne['empreinte'];
}
