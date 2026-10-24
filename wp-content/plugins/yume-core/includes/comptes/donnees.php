<?php
/**
 * Données de la page « Comptes inscrits » (lot A) : liste paginée des comptes avec filtres et
 * tris (lister_comptes(), compter_comptes()), indicateurs (indicateurs(), en cache 5 minutes),
 * fiche d'un compte (fiche_compte()) et lecture estimée par lot (lectures_par_compte()).
 *
 * Requêtes groupées, jamais une boucle par compte : une page de la liste coûte au plus six
 * requêtes (total, page, positions de lecture, statut des œuvres, plan de lecture en deux
 * requêtes). SQL compatible MariaDB et SQLite : CASE WHEN et COALESCE seulement, dates
 * calculées en PHP, motifs LIKE échappés ($wpdb->esc_like), INNER JOIN sur la table des
 * utilisateurs (un compte supprimé n'apparaît jamais). Recherche : pseudo et nom Discord (LIKE
 * échappé), adresse e-mail et identifiant de connexion EXACTS seulement (jamais de LIKE sur
 * l'e-mail).
 *
 * Minimisation (docs/06-contrat-technique.md §8 septies) : ni identifiant de connexion, ni
 * adresse e-mail, ni adresse IP, ni réglages de lecture, ni clés push, ni nom ou contenu d'une
 * liste privée dans les tableaux renvoyés.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

defined( 'ABSPATH' ) || exit;

/** Transient des indicateurs de la page. */
const INDICATEURS_TRANSIENT = 'yume_comptes_indicateurs';

/** Durée du cache des indicateurs (secondes). */
const INDICATEURS_DUREE = 300;

/** Méta utilisateur : identifiant Discord lié (Discord\META_ID). */
const DONNEES_META_DISCORD = 'yume_discord_id';

/** Méta utilisateur : pseudo Discord lié (Discord\META_PSEUDO). */
const DONNEES_META_DISCORD_PSEUDO = 'yume_discord_pseudo';

/** Méta utilisateur : date GMT de la liaison Discord (Discord\META_LIE_LE). */
const DONNEES_META_DISCORD_LIE_LE = 'yume_discord_lie_le';

/** Méta utilisateur : compte créé sans mot de passe (Social\META_COMPTE_SANS_MDP). */
const DONNEES_META_SANS_MDP = '_yume_compte_sans_mdp';

/** Motif LIKE d'un identifiant généré (« yn- » et 8 caractères, Social\identifiant_genere()). */
const DONNEES_MOTIF_IDENTIFIANT = 'yn-________';

/** Commentaires récents de la fiche. */
const DONNEES_COMMENTAIRES_FICHE = 10;

/** Signalements de coquilles récents de la fiche. */
const DONNEES_COQUILLES_FICHE = 5;

/** Fenêtre des notifications comptées sur la fiche (jours). */
const DONNEES_NOTIFICATIONS_JOURS = 90;

/*
 * Indicateurs : cache vidé quand un compte est créé, supprimé ou change de rôle.
 */
add_action( 'user_register', __NAMESPACE__ . '\\vider_indicateurs', 10, 0 );
add_action( 'deleted_user', __NAMESPACE__ . '\\vider_indicateurs', 10, 0 );
add_action( 'set_user_role', __NAMESPACE__ . '\\vider_indicateurs', 10, 0 );

/*
 * -----------------------------------------------------------------------------
 * Aides
 * -----------------------------------------------------------------------------
 */

/**
 * Vide le cache des indicateurs.
 */
function vider_indicateurs(): void {
	delete_transient( INDICATEURS_TRANSIENT );
}

/**
 * Date GMT « Y-m-d H:i:s » en ISO 8601 (UTC) ; '' si vide ou invalide.
 *
 * @param mixed $gmt Date GMT.
 */
function donnees_iso( $gmt ): string {
	if ( ! is_string( $gmt ) || '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? '' : gmdate( 'c', $ts );
}

/**
 * Date GMT « Y-m-d H:i:s » à $jours jours de maintenant() (dans le passé).
 *
 * @param int $jours Jours.
 */
function donnees_gmt_il_y_a( int $jours ): string {
	return gmdate( 'Y-m-d H:i:s', maintenant() - $jours * DAY_IN_SECONDS );
}

/**
 * Premier jour ('Y-m-d', heure du site) de la fenêtre « actif depuis $jours jours » : une
 * activité ce jour-là ou après compte.
 *
 * @param int $jours Jours.
 */
function donnees_seuil_jour( int $jours ): string {
	return jour_site( maintenant() - $jours * DAY_IN_SECONDS );
}

/**
 * Rôles du site qui donnent accès à l'espace équipe (capacité yume_voir_equipe).
 *
 * @return string[]
 */
function donnees_roles_equipe(): array {
	$roles = array();
	foreach ( wp_roles()->roles as $slug => $role ) {
		if ( ! empty( $role['capabilities']['yume_voir_equipe'] ) ) {
			$roles[] = (string) $slug;
		}
	}
	return $roles;
}

/**
 * Rôles d'un compte d'après la valeur brute de sa méta {prefix}capabilities (comme
 * WP_User::$roles : rôles existants accordés, dans l'ordre de la méta).
 *
 * @param mixed $brut Valeur sérialisée.
 * @return string[]
 */
function donnees_roles( $brut ): array {
	$caps  = maybe_unserialize( is_string( $brut ) ? $brut : '' );
	$roles = array();
	foreach ( is_array( $caps ) ? $caps : array() as $slug => $accorde ) {
		if ( $accorde && is_string( $slug ) && wp_roles()->is_role( $slug ) ) {
			$roles[] = $slug;
		}
	}
	return $roles;
}

/**
 * Libellé du rôle principal (comme Planning\nom_role()) : « Administrateur », sinon nom traduit
 * du premier rôle ; '' sans rôle.
 *
 * @param string[] $roles Rôles.
 */
function donnees_libelle_role( array $roles ): string {
	$definis = wp_roles()->roles;
	foreach ( $roles as $role ) {
		if ( 'administrator' === $role ) {
			return __( 'Administrateur', 'yume-core' );
		}
		if ( isset( $definis[ $role ]['name'] ) ) {
			return translate_user_role( $definis[ $role ]['name'] );
		}
	}
	return '';
}

/**
 * Titre brut d'un contenu (sans balises ni entités).
 *
 * @param int $post_id Contenu.
 */
function donnees_titre( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}
	return trim( wp_strip_all_tags( html_entity_decode( (string) get_post_field( 'post_title', $post_id ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Adresse publique d'un contenu publié ('' sinon).
 *
 * @param int $post_id Contenu.
 */
function donnees_url( int $post_id ): string {
	return $post_id > 0 && 'publish' === get_post_status( $post_id ) ? (string) get_permalink( $post_id ) : '';
}

/**
 * Met en cache les contenus (une requête pour ceux qui n'y sont pas encore).
 *
 * @param int[] $ids Contenus.
 */
function donnees_amorcer_contenus( array $ids ): void {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
	if ( $ids ) {
		_prime_post_caches( $ids, false, false );
	}
}

/**
 * La table des favoris est-elle lisible (module social chargé et installé) ?
 */
function donnees_favoris_lisibles(): bool {
	return function_exists( '\Yume\Core\Social\table_favoris' ) && function_exists( '\Yume\Core\Social\tables_pretes' ) && \Yume\Core\Social\tables_pretes();
}

/**
 * La table des positions de lecture est-elle lisible (module reader chargé et installé) ?
 */
function donnees_progression_lisible(): bool {
	return function_exists( '\Yume\Core\Reader\table_progression' ) && function_exists( '\Yume\Core\Reader\table_prete' ) && \Yume\Core\Reader\table_prete();
}

/**
 * Les tables listes, notifications et Web Push sont-elles lisibles ?
 */
function donnees_tables_lecteur_lisibles(): bool {
	return function_exists( '\Yume\Core\Social\tables_lecteur_pretes' ) && \Yume\Core\Social\tables_lecteur_pretes();
}

/**
 * Lecture estimée d'une série, comme Reader\statistiques_lecture() : chapitres publiés qui
 * précèdent la position dans l'ordre de lecture, plus le chapitre courant s'il est lu à
 * Reader\SEUIL_CHAPITRE_LU % au moins. Null si la position est sur un chapitre absent du plan
 * (dépublié, déplacé) : rien ne peut en être déduit.
 *
 * @param array $ligne Position (chapitre_id, pourcentage).
 * @param array $tomes Tomes ordonnés de l'œuvre (Reader\plan_de_lecture()).
 * @return array{chapitres_lus:int,chapitres:int,tomes_termines:int,tomes:int,minutes:int,a_jour:bool,reste:int}|null
 */
function donnees_lecture_serie( array $ligne, array $tomes ): ?array {
	$seuil    = defined( 'Yume\\Core\\Reader\\SEUIL_CHAPITRE_LU' ) ? (int) constant( 'Yume\\Core\\Reader\\SEUIL_CHAPITRE_LU' ) : 90;
	$serie    = array(
		'chapitres_lus'  => 0,
		'chapitres'      => 0,
		'tomes_termines' => 0,
		'tomes'          => 0,
		'minutes'        => 0,
	);
	$trouve   = false;
	$avant    = true;
	$courant  = (int) ( $ligne['chapitre_id'] ?? 0 );
	$lu_assez = (int) ( $ligne['pourcentage'] ?? 0 ) >= $seuil;
	foreach ( $tomes as $tome ) {
		if ( empty( $tome['chapitres'] ) ) {
			continue;
		}
		++$serie['tomes'];
		$tome_lu = true;
		foreach ( $tome['chapitres'] as $chapitre ) {
			++$serie['chapitres'];
			$lu = $avant;
			if ( (int) $chapitre['id'] === $courant ) {
				$trouve = true;
				$lu     = $lu_assez;
				$avant  = false;
			}
			if ( $lu ) {
				++$serie['chapitres_lus'];
				$serie['minutes'] += (int) $chapitre['minutes'];
			} else {
				$tome_lu = false;
			}
		}
		if ( $tome_lu ) {
			++$serie['tomes_termines'];
		}
	}
	if ( ! $trouve ) {
		return null;
	}
	$serie['a_jour'] = $serie['chapitres'] > 0 && $serie['chapitres_lus'] >= $serie['chapitres'];
	$serie['reste']  = max( 0, $serie['chapitres'] - $serie['chapitres_lus'] );
	return $serie;
}

/*
 * -----------------------------------------------------------------------------
 * Liste des comptes
 * -----------------------------------------------------------------------------
 */

/**
 * Conditions SQL réutilisées (alias de la requête de liste : u, cap, src, dsc, mdp).
 *
 * @return array{lie:string,sans_mdp:string,source_absente:string,deduit_discord:string}
 */
function donnees_conditions(): array {
	$lie      = "(dsc.meta_value IS NOT NULL AND dsc.meta_value <> '')";
	$sans_mdp = "(mdp.meta_value IS NOT NULL AND mdp.meta_value <> '' AND mdp.meta_value <> '0')";
	$absente  = "(src.meta_value IS NULL OR src.meta_value NOT IN ('" . implode( "', '", SOURCES ) . "'))";
	return array(
		'lie'            => $lie,
		'sans_mdp'       => $sans_mdp,
		'source_absente' => $absente,
		'deduit_discord' => "({$lie} AND {$sans_mdp})",
	);
}

/**
 * Clause « un des rôles » sur cap.meta_value, et ses valeurs. Aucun rôle : jamais vraie.
 *
 * @param string[] $roles Rôles.
 * @return array{0:string,1:array<int,string>}
 */
function donnees_clause_roles( array $roles ): array {
	global $wpdb;
	if ( ! $roles ) {
		return array( '1 = 0', array() );
	}
	$clauses = array();
	$valeurs = array();
	foreach ( $roles as $role ) {
		$clauses[] = 'cap.meta_value LIKE %s';
		$valeurs[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
	}
	return array( '(' . implode( ' OR ', $clauses ) . ')', $valeurs );
}

/**
 * Jointures et clause WHERE de la liste (préparées par l'appelant) : métas du compte, sous-requêtes
 * agrégées (positions de lecture, favoris, commentaires) seulement si nécessaires.
 *
 * @param array $args     Paramètres normalisés (args_comptes()).
 * @param bool  $complete Avec les agrégats de la liste (sinon : seulement ce que filtre le WHERE).
 * @return array{joins:string,where:string,valeurs:array,progression:bool,favoris:bool}
 */
function donnees_requete( array $args, bool $complete ): array {
	global $wpdb;
	$cond        = donnees_conditions();
	$progression = donnees_progression_lisible() && ( $complete || $args['sans_lecture'] );
	$favoris     = $complete && donnees_favoris_lisibles();

	$joins   = array( "INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s" );
	$valeurs = array( $wpdb->get_blog_prefix() . 'capabilities' );
	foreach ( array(
		'act' => META_ACTIVITE,
		'src' => META_SOURCE,
		'dsc' => DONNEES_META_DISCORD,
		'dps' => DONNEES_META_DISCORD_PSEUDO,
		'mdp' => DONNEES_META_SANS_MDP,
	) as $alias => $cle ) {
		$joins[]   = "LEFT JOIN {$wpdb->usermeta} {$alias} ON {$alias}.user_id = u.ID AND {$alias}.meta_key = %s";
		$valeurs[] = $cle;
	}
	if ( $progression ) {
		$table   = \Yume\Core\Reader\table_progression();
		$joins[] = "LEFT JOIN (SELECT user_id, MAX(updated_at) AS derniere FROM {$table} GROUP BY user_id) prg ON prg.user_id = u.ID";
	}
	if ( $favoris ) {
		$table   = \Yume\Core\Social\table_favoris();
		$joins[] = "LEFT JOIN (SELECT user_id, COUNT(*) AS nb, SUM(CASE WHEN frequence <> 'jamais' THEN 1 ELSE 0 END) AS suiveurs FROM {$table} GROUP BY user_id) fav ON fav.user_id = u.ID";
	}
	if ( $complete ) {
		$joins[] = "LEFT JOIN (SELECT user_id, COUNT(*) AS nb FROM {$wpdb->comments} WHERE user_id > 0 AND comment_approved IN ('0', '1') AND comment_type IN ('comment', '') GROUP BY user_id) com ON com.user_id = u.ID";
	}

	$where = array();
	// Recherche : pseudo et nom Discord (LIKE échappé), e-mail et identifiant exacts.
	if ( '' !== $args['q'] ) {
		$q      = $args['q'];
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		$ou     = array( 'u.display_name LIKE %s', 'dps.meta_value LIKE %s' );
		$ou_val = array( $like, $like );
		// Le cœur enregistre « & » en « &amp; » dans display_name.
		$encode = esc_html( $q );
		if ( $encode !== $q ) {
			$ou[]     = 'u.display_name LIKE %s';
			$ou_val[] = '%' . $wpdb->esc_like( $encode ) . '%';
		}
		if ( is_email( $q ) ) {
			$ou[]     = 'LOWER(u.user_email) = %s';
			$ou_val[] = mb_strtolower( $q );
		}
		$ou[]     = 'LOWER(u.user_login) = %s';
		$ou_val[] = mb_strtolower( $q );
		$where[]  = '(' . implode( ' OR ', $ou ) . ')';
		$valeurs  = array_merge( $valeurs, $ou_val );
	}

	// Rôle.
	if ( 'lecteurs' === $args['role'] ) {
		list( $clause, $vals ) = donnees_clause_roles( array( 'subscriber' ) );
	} elseif ( 'equipe' === $args['role'] ) {
		list( $clause, $vals ) = donnees_clause_roles( donnees_roles_equipe() );
	} elseif ( 'tous' !== $args['role'] ) {
		list( $clause, $vals ) = donnees_clause_roles( array( $args['role'] ) );
	} else {
		list( $clause, $vals ) = array( '', array() );
	}
	if ( '' !== $clause ) {
		$where[] = $clause;
		$valeurs = array_merge( $valeurs, $vals );
	}

	// Source d'inscription : méta, sinon déduite comme source_compte().
	switch ( $args['source'] ) {
		case 'inscription':
			$where[]   = "(src.meta_value = 'inscription' OR ({$cond['source_absente']} AND NOT {$cond['deduit_discord']} AND u.user_login LIKE %s))";
			$valeurs[] = DONNEES_MOTIF_IDENTIFIANT;
			break;
		case 'discord':
			$where[] = "(src.meta_value = 'discord' OR ({$cond['source_absente']} AND {$cond['deduit_discord']}))";
			break;
		case 'equipe':
			$where[] = "src.meta_value = 'equipe'";
			break;
		case 'inconnue':
			$where[]   = "({$cond['source_absente']} AND NOT {$cond['deduit_discord']} AND u.user_login NOT LIKE %s)";
			$valeurs[] = DONNEES_MOTIF_IDENTIFIANT;
			break;
	}

	// Discord.
	if ( 'lie' === $args['discord'] ) {
		$where[] = $cond['lie'];
	} elseif ( 'non' === $args['discord'] ) {
		$where[] = "NOT {$cond['lie']}";
	}

	// Dernière activité (jour du site).
	$connue = "(act.meta_value IS NOT NULL AND act.meta_value <> '')";
	if ( in_array( $args['activite'], array( '7', '30', '90' ), true ) ) {
		$where[]   = "{$connue} AND act.meta_value >= %s";
		$valeurs[] = donnees_seuil_jour( (int) $args['activite'] );
	} elseif ( 'inactifs' === $args['activite'] ) {
		$where[]   = "{$connue} AND act.meta_value < %s";
		$valeurs[] = donnees_seuil_jour( 90 );
	} elseif ( 'inconnue' === $args['activite'] ) {
		$where[] = "NOT {$connue}";
	}

	// Inscription récente.
	if ( 'tout' !== $args['inscrits'] ) {
		$where[]   = 'u.user_registered >= %s';
		$valeurs[] = donnees_gmt_il_y_a( (int) $args['inscrits'] );
	}

	// Sans aucune position de lecture (table absente : aucun compte n'a de position).
	if ( $args['sans_lecture'] && $progression ) {
		$where[] = 'prg.user_id IS NULL';
	}
	if ( $args['sans_mdp'] ) {
		$where[] = $cond['sans_mdp'];
	}

	return array(
		'joins'       => implode( ' ', $joins ),
		'where'       => $where ? 'WHERE ' . implode( ' AND ', $where ) : '',
		'valeurs'     => $valeurs,
		'progression' => $progression,
		'favoris'     => $favoris,
	);
}

/**
 * Nombre de comptes correspondant aux filtres (une requête).
 *
 * @param array $args Paramètres (normalisés par args_comptes()).
 */
function compter_comptes( array $args ): int {
	global $wpdb;
	$args    = args_comptes( $args );
	$requete = donnees_requete( $args, false );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- jointures et clauses construites ici, valeurs préparées.
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} u {$requete['joins']} {$requete['where']}", $requete['valeurs'] )
	);
	// phpcs:enable
}

/**
 * ORDER BY de la liste. Activité et dernière lecture inconnues toujours en dernier.
 *
 * @param array $args        Paramètres normalisés.
 * @param bool  $progression Positions de lecture jointes (prg).
 * @param bool  $favoris     Favoris joints (fav).
 */
function donnees_ordre( array $args, bool $progression, bool $favoris ): string {
	$sens = 'asc' === $args['ordre'] ? 'ASC' : 'DESC';
	switch ( $args['tri'] ) {
		case 'pseudo':
			return "LOWER(u.display_name) {$sens}, u.ID {$sens}";
		case 'activite':
			return "CASE WHEN act.meta_value IS NULL OR act.meta_value = '' THEN 1 ELSE 0 END ASC, act.meta_value {$sens}, u.ID DESC";
		case 'lecture':
			return $progression
				? "CASE WHEN prg.derniere IS NULL THEN 1 ELSE 0 END ASC, prg.derniere {$sens}, u.ID DESC"
				: "u.user_registered {$sens}, u.ID {$sens}";
		case 'favoris':
			return $favoris ? "COALESCE(fav.nb, 0) {$sens}, u.ID DESC" : "u.ID {$sens}";
		case 'commentaires':
			return "COALESCE(com.nb, 0) {$sens}, u.ID DESC";
		default:
			return "u.user_registered {$sens}, u.ID {$sens}";
	}
}

/**
 * Comptes inscrits, filtrés, triés et paginés. Au plus six requêtes pour une page : total, page
 * (métas et agrégats joints), puis lectures_par_compte() (positions, statut des œuvres, plan de
 * lecture).
 *
 * @param array $args Paramètres (args_comptes()).
 * @return array{total:int,page:int,pages:int,limite:int,args:array,comptes:array<int,array<string,mixed>>}
 */
function lister_comptes( array $args ): array {
	global $wpdb;
	$args     = args_comptes( $args );
	$total    = compter_comptes( $args );
	$limite   = (int) $args['limite'];
	$pages    = max( 1, (int) ceil( $total / $limite ) );
	$page     = min( (int) $args['page'], $pages );
	$resultat = array(
		'total'   => $total,
		'page'    => $page,
		'pages'   => $pages,
		'limite'  => $limite,
		'args'    => $args,
		'comptes' => array(),
	);
	if ( 0 === $total ) {
		return $resultat;
	}

	$requete  = donnees_requete( $args, true );
	$derniere = $requete['progression'] ? 'prg.derniere' : 'NULL';
	$favoris  = $requete['favoris'] ? 'COALESCE(fav.nb, 0)' : '0';
	$suiveurs = $requete['favoris'] ? 'COALESCE(fav.suiveurs, 0)' : '0';
	$ordre    = donnees_ordre( $args, $requete['progression'], $requete['favoris'] );
	$valeurs  = array_merge( array( DONNEES_MOTIF_IDENTIFIANT ), $requete['valeurs'], array( $limite, ( $page - 1 ) * $limite ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- jointures, clauses et tri construits ici, valeurs préparées.
	$lignes = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT u.ID AS id, u.display_name AS pseudo, u.user_registered AS inscrit,
				CASE WHEN u.user_login LIKE %s THEN 1 ELSE 0 END AS genere,
				cap.meta_value AS caps, act.meta_value AS activite, src.meta_value AS source,
				dsc.meta_value AS discord_id, dps.meta_value AS discord_pseudo, mdp.meta_value AS sans_mdp,
				{$derniere} AS derniere, {$favoris} AS favoris, {$suiveurs} AS suiveurs, COALESCE(com.nb, 0) AS commentaires
			FROM {$wpdb->users} u {$requete['joins']} {$requete['where']}
			ORDER BY {$ordre} LIMIT %d OFFSET %d",
			$valeurs
		),
		ARRAY_A
	);
	// phpcs:enable

	$lectures = lectures_par_compte( array_map( static fn( array $l ): int => (int) $l['id'], $lignes ) );
	$equipe   = donnees_roles_equipe();
	foreach ( $lignes as $ligne ) {
		$id       = (int) $ligne['id'];
		$roles    = donnees_roles( $ligne['caps'] );
		$lie      = is_string( $ligne['discord_id'] ) && '' !== $ligne['discord_id'];
		$sans_mdp = is_string( $ligne['sans_mdp'] ) && '' !== $ligne['sans_mdp'] && '0' !== $ligne['sans_mdp'];
		$meta_src = is_string( $ligne['source'] ) && in_array( $ligne['source'], SOURCES, true ) ? $ligne['source'] : '';
		if ( '' !== $meta_src ) {
			$source  = $meta_src;
			$deduite = false;
		} elseif ( $lie && $sans_mdp ) {
			$source  = 'discord';
			$deduite = true;
		} elseif ( 1 === (int) $ligne['genere'] ) {
			$source  = 'inscription';
			$deduite = true;
		} else {
			$source  = 'inconnue';
			$deduite = false;
		}
		$pseudo_discord = $lie ? trim( (string) $ligne['discord_pseudo'] ) : '';
		if ( $lie && '' === $pseudo_discord ) {
			$pseudo_discord = __( '(sans pseudo)', 'yume-core' );
		}
		$lecture               = $lectures[ $id ] ?? array(
			'chapitres_lus' => 0,
			'series'        => 0,
		);
		$resultat['comptes'][] = array(
			'id'               => $id,
			'pseudo'           => wp_specialchars_decode( (string) $ligne['pseudo'], ENT_QUOTES ),
			'roles'            => $roles,
			'role'             => donnees_libelle_role( $roles ),
			'equipe'           => (bool) array_intersect( $roles, $equipe ),
			'inscrit_le'       => donnees_iso( $ligne['inscrit'] ),
			'source'           => $source,
			'source_deduite'   => $deduite,
			'discord'          => $pseudo_discord,
			'sans_mdp'         => $sans_mdp,
			'activite'         => jour_valide( $ligne['activite'] ) ? (string) $ligne['activite'] : '',
			'derniere_lecture' => donnees_iso( $ligne['derniere'] ),
			'chapitres_lus'    => (int) $lecture['chapitres_lus'],
			'series'           => (int) $lecture['series'],
			'favoris'          => (int) $ligne['favoris'],
			'suiveurs'         => (int) $ligne['suiveurs'],
			'commentaires'     => (int) $ligne['commentaires'],
		);
	}
	return $resultat;
}

/**
 * Lecture estimée de plusieurs comptes, identique à Reader\statistiques_lecture() compte par
 * compte : positions de tous les comptes (une requête), statut des œuvres (une requête au plus),
 * plan de lecture de toutes les œuvres (Reader\plan_de_lecture(), deux requêtes, une fois).
 *
 * @param int[] $user_ids Comptes.
 * @return array<int,array{chapitres_lus:int,minutes:int,series:int,derniere:string}> derniere : date GMT de la position la plus récente ('' sans position).
 */
function lectures_par_compte( array $user_ids ): array {
	global $wpdb;
	$ids      = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), static fn( int $id ): bool => $id > 0 ) ) );
	$resultat = array_fill_keys(
		$ids,
		array(
			'chapitres_lus' => 0,
			'minutes'       => 0,
			'series'        => 0,
			'derniere'      => '',
		)
	);
	if ( ! $ids || ! donnees_progression_lisible() ) {
		return $resultat;
	}
	$table   = \Yume\Core\Reader\table_progression();
	$marques = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- marqueurs %d de la liste IN.
	$lignes = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, oeuvre_id, chapitre_id, pourcentage, updated_at FROM {$table} WHERE user_id IN ({$marques})", $ids ), ARRAY_A );
	if ( ! $lignes ) {
		return $resultat;
	}
	$oeuvres = array_values( array_unique( array_filter( array_map( static fn( array $l ): int => (int) $l['oeuvre_id'], $lignes ) ) ) );
	donnees_amorcer_contenus( $oeuvres );
	$plan = $oeuvres && function_exists( '\Yume\Core\Reader\plan_de_lecture' ) ? \Yume\Core\Reader\plan_de_lecture( $oeuvres ) : array();
	foreach ( $lignes as $ligne ) {
		$uid = (int) $ligne['user_id'];
		$maj = (string) $ligne['updated_at'];
		if ( strcmp( $maj, $resultat[ $uid ]['derniere'] ) > 0 ) {
			$resultat[ $uid ]['derniere'] = $maj;
		}
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		if ( $oeuvre_id <= 0 || 'publish' !== get_post_status( $oeuvre_id ) || empty( $plan[ $oeuvre_id ] ) ) {
			continue;
		}
		$serie = donnees_lecture_serie( $ligne, $plan[ $oeuvre_id ] );
		if ( null === $serie ) {
			continue;
		}
		$resultat[ $uid ]['chapitres_lus'] += $serie['chapitres_lus'];
		$resultat[ $uid ]['minutes']       += $serie['minutes'];
		++$resultat[ $uid ]['series'];
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Indicateurs
 * -----------------------------------------------------------------------------
 */

/**
 * Indicateurs de la page (une requête, en cache 5 minutes) : comptes, lecteurs (rôle
 * subscriber), équipe (rôles donnant yume_voir_equipe), inscrits depuis 30 jours, actifs depuis
 * 30 jours (méta de dernière activité), liés à Discord, sans mot de passe ; activite_depuis =
 * début du relevé de l'activité ('Y-m-d', '' si pas encore amorcé).
 *
 * @return array{comptes:int,lecteurs:int,equipe:int,nouveaux_30j:int,actifs_30j:int,discord:int,sans_mdp:int,activite_depuis:string}
 */
function indicateurs(): array {
	global $wpdb;
	$cache = get_transient( INDICATEURS_TRANSIENT );
	if ( is_array( $cache ) && isset( $cache['comptes'] ) ) {
		return $cache;
	}
	$cond                       = donnees_conditions();
	list( $lecteurs, $val_lec ) = donnees_clause_roles( array( 'subscriber' ) );
	list( $equipe, $val_eq )    = donnees_clause_roles( donnees_roles_equipe() );
	$valeurs                    = array_merge(
		$val_lec,
		$val_eq,
		array( donnees_gmt_il_y_a( 30 ), donnees_seuil_jour( 30 ) ),
		array( $wpdb->get_blog_prefix() . 'capabilities', META_ACTIVITE, DONNEES_META_DISCORD, DONNEES_META_SANS_MDP )
	);
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- clauses construites ici, valeurs préparées.
	$ligne = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS comptes,
				SUM(CASE WHEN {$lecteurs} THEN 1 ELSE 0 END) AS lecteurs,
				SUM(CASE WHEN {$equipe} THEN 1 ELSE 0 END) AS equipe,
				SUM(CASE WHEN u.user_registered >= %s THEN 1 ELSE 0 END) AS nouveaux,
				SUM(CASE WHEN act.meta_value IS NOT NULL AND act.meta_value <> '' AND act.meta_value >= %s THEN 1 ELSE 0 END) AS actifs,
				SUM(CASE WHEN {$cond['lie']} THEN 1 ELSE 0 END) AS discord,
				SUM(CASE WHEN {$cond['sans_mdp']} THEN 1 ELSE 0 END) AS sans_mdp
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID AND cap.meta_key = %s
			LEFT JOIN {$wpdb->usermeta} act ON act.user_id = u.ID AND act.meta_key = %s
			LEFT JOIN {$wpdb->usermeta} dsc ON dsc.user_id = u.ID AND dsc.meta_key = %s
			LEFT JOIN {$wpdb->usermeta} mdp ON mdp.user_id = u.ID AND mdp.meta_key = %s",
			$valeurs
		),
		ARRAY_A
	);
	// phpcs:enable
	$depuis   = get_option( OPTION_DEPUIS, '' );
	$resultat = array(
		'comptes'         => (int) ( $ligne['comptes'] ?? 0 ),
		'lecteurs'        => (int) ( $ligne['lecteurs'] ?? 0 ),
		'equipe'          => (int) ( $ligne['equipe'] ?? 0 ),
		'nouveaux_30j'    => (int) ( $ligne['nouveaux'] ?? 0 ),
		'actifs_30j'      => (int) ( $ligne['actifs'] ?? 0 ),
		'discord'         => (int) ( $ligne['discord'] ?? 0 ),
		'sans_mdp'        => (int) ( $ligne['sans_mdp'] ?? 0 ),
		'activite_depuis' => jour_valide( $depuis ) ? $depuis : '',
	);
	set_transient( INDICATEURS_TRANSIENT, $resultat, INDICATEURS_DUREE );
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Fiche d'un compte
 * -----------------------------------------------------------------------------
 */

/**
 * Identité de la fiche (sans e-mail ni identifiant de connexion).
 *
 * @param \WP_User $user Compte.
 * @return array<string,mixed>
 */
function donnees_identite( \WP_User $user ): array {
	$id    = (int) $user->ID;
	$roles = array_values( array_map( 'strval', (array) $user->roles ) );
	$role  = function_exists( '\Yume\Core\Planning\nom_role' ) ? \Yume\Core\Planning\nom_role( $user ) : donnees_libelle_role( $roles );

	$discord_id = function_exists( '\Yume\Core\Discord\discord_lie' )
		? \Yume\Core\Discord\discord_lie( $id )
		: (string) get_user_meta( $id, DONNEES_META_DISCORD, true );
	$discord    = null;
	if ( '' !== $discord_id ) {
		$discord = array(
			'pseudo' => trim( (string) get_user_meta( $id, DONNEES_META_DISCORD_PSEUDO, true ) ),
			'lie_le' => donnees_iso( (string) get_user_meta( $id, DONNEES_META_DISCORD_LIE_LE, true ) ),
		);
	}
	$sans_mdp = function_exists( '\Yume\Core\Social\compte_sans_mot_de_passe' )
		? \Yume\Core\Social\compte_sans_mot_de_passe( $id )
		: (bool) get_user_meta( $id, DONNEES_META_SANS_MDP, true );
	if ( function_exists( '\Yume\Core\Social\pseudo_est_identifiant' ) ) {
		$pseudo_identifiant = \Yume\Core\Social\pseudo_est_identifiant( $user );
	} else {
		$login              = (string) $user->user_login;
		$pseudo_identifiant = '' !== $login && mb_strtolower( trim( (string) $user->display_name ) ) === mb_strtolower( $login );
	}
	$source = source_compte( $id );
	return array(
		'id'                     => $id,
		'pseudo'                 => wp_specialchars_decode( (string) $user->display_name, ENT_QUOTES ),
		'roles'                  => $roles,
		'role'                   => $role,
		'equipe'                 => (bool) array_intersect( $roles, donnees_roles_equipe() ),
		'inscrit_le'             => donnees_iso( (string) $user->user_registered ),
		'source'                 => $source['source'],
		'source_deduite'         => $source['deduite'],
		'discord'                => $discord,
		'sans_mdp'               => $sans_mdp,
		'pseudo_est_identifiant' => $pseudo_identifiant,
		'activite'               => derniere_activite( $id ),
		'email_disponible'       => (bool) is_email( (string) $user->user_email ),
	);
}

/**
 * Lecture de la fiche : Reader\statistiques_lecture() + dernière position et détail des séries
 * (une entrée par position, avec la lecture estimée quand elle peut l'être).
 *
 * @param int $user_id Compte.
 * @return array<string,mixed>
 */
function donnees_lecture_fiche( int $user_id ): array {
	$stats                  = function_exists( '\Yume\Core\Reader\statistiques_lecture' )
		? \Yume\Core\Reader\statistiques_lecture( $user_id )
		: array(
			'tomes_termines'  => 0,
			'chapitres_lus'   => 0,
			'minutes'         => 0,
			'series_en_cours' => 0,
			'series_a_jour'   => 0,
			'series'          => array(),
		);
	$stats['derniere']      = null;
	$stats['series_detail'] = array();
	if ( ! donnees_progression_lisible() || ! function_exists( '\Yume\Core\Reader\lignes_progression' ) ) {
		return $stats;
	}
	$lignes = \Yume\Core\Reader\lignes_progression( $user_id );
	if ( ! $lignes ) {
		return $stats;
	}
	$par_oeuvre = array();
	foreach ( (array) $stats['series'] as $serie ) {
		$par_oeuvre[ (int) $serie['oeuvre_id'] ] = $serie;
	}
	donnees_amorcer_contenus( array_merge( wp_list_pluck( $lignes, 'oeuvre_id' ), wp_list_pluck( $lignes, 'chapitre_id' ) ) );
	foreach ( $lignes as $ligne ) {
		$oeuvre_id = (int) $ligne['oeuvre_id'];
		$chapitre  = (int) $ligne['chapitre_id'];
		$serie     = $par_oeuvre[ $oeuvre_id ] ?? null;
		$detail    = array(
			'oeuvre_id'      => $oeuvre_id,
			'titre'          => donnees_titre( $oeuvre_id ),
			'url'            => donnees_url( $oeuvre_id ),
			'chapitre_id'    => $chapitre,
			'chapitre'       => donnees_titre( $chapitre ),
			'chapitre_url'   => donnees_url( $chapitre ),
			'pourcentage'    => (int) $ligne['pourcentage'],
			'le'             => donnees_iso( (string) $ligne['updated_at'] ),
			'estimee'        => null !== $serie,
			'chapitres_lus'  => (int) ( $serie['chapitres_lus'] ?? 0 ),
			'chapitres'      => (int) ( $serie['chapitres'] ?? 0 ),
			'tomes_termines' => (int) ( $serie['tomes_termines'] ?? 0 ),
			'tomes'          => (int) ( $serie['tomes'] ?? 0 ),
			'minutes'        => (int) ( $serie['minutes'] ?? 0 ),
			'a_jour'         => (bool) ( $serie['a_jour'] ?? false ),
			'reste'          => (int) ( $serie['reste'] ?? 0 ),
		);
		if ( null === $stats['derniere'] ) {
			$stats['derniere'] = array(
				'oeuvre_id'   => $oeuvre_id,
				'titre'       => $detail['titre'],
				'chapitre_id' => $chapitre,
				'chapitre'    => $detail['chapitre'],
				'url'         => $detail['chapitre_url'],
				'le'          => $detail['le'],
			);
		}
		$stats['series_detail'][] = $detail;
	}
	return $stats;
}

/**
 * Listes de lecture de la fiche : listes système (nombre d'œuvres publiées), listes
 * personnelles, listes publiques (nom, adresse, nombre) ; listes privées en agrégat seulement
 * (jamais leur nom ni leur contenu). Aucune liste n'est créée (listes_utilisateur( $id, false )).
 *
 * @param int $user_id Compte.
 * @return array{personnelles:int,privees:int,oeuvres_privees:int,publiques:array,systeme:array{a_lire:int,en_cours:int,termine:int}}
 */
function donnees_listes_fiche( int $user_id ): array {
	$listes = array(
		'personnelles'    => 0,
		'privees'         => 0,
		'oeuvres_privees' => 0,
		'publiques'       => array(),
		'systeme'         => array(
			'a_lire'   => 0,
			'en_cours' => 0,
			'termine'  => 0,
		),
	);
	if ( ! function_exists( '\Yume\Core\Social\listes_utilisateur' ) ) {
		return $listes;
	}
	foreach ( \Yume\Core\Social\listes_utilisateur( $user_id, false ) as $liste ) {
		$nb = (int) ( $liste['nb'] ?? 0 );
		if ( '' !== $liste['systeme'] && isset( $listes['systeme'][ $liste['systeme'] ] ) ) {
			$listes['systeme'][ $liste['systeme'] ] = $nb;
		} else {
			++$listes['personnelles'];
			if ( ! $liste['publique'] ) {
				++$listes['privees'];
				$listes['oeuvres_privees'] += $nb;
			}
		}
		if ( $liste['publique'] ) {
			$listes['publiques'][] = array(
				'nom' => (string) $liste['nom'],
				'url' => function_exists( '\Yume\Core\Social\url_liste' ) ? \Yume\Core\Social\url_liste( $liste ) : '',
				'nb'  => $nb,
			);
		}
	}
	return $listes;
}

/**
 * Commentaires de la fiche : totaux par statut (hors corbeille) et derniers commentaires.
 *
 * @param int $user_id Compte.
 * @return array{total:int,approuves:int,attente:int,spam:int,derniers:array}
 */
function donnees_commentaires_fiche( int $user_id ): array {
	global $wpdb;
	$resultat = array(
		'total'     => 0,
		'approuves' => 0,
		'attente'   => 0,
		'spam'      => 0,
		'derniers'  => array(),
	);
	$statuts  = array(
		'1'    => 'approuve',
		'0'    => 'attente',
		'spam' => 'spam',
	);
	$cles     = array(
		'approuve' => 'approuves',
		'attente'  => 'attente',
		'spam'     => 'spam',
	);
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$totaux = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_approved AS statut, COUNT(*) AS nb FROM {$wpdb->comments}
			WHERE user_id = %d AND comment_approved IN ('0', '1', 'spam') AND comment_type IN ('comment', '') GROUP BY comment_approved",
			$user_id
		),
		ARRAY_A
	);
	foreach ( $totaux as $ligne ) {
		$statut = $statuts[ (string) $ligne['statut'] ] ?? '';
		if ( '' !== $statut ) {
			$resultat[ $cles[ $statut ] ] = (int) $ligne['nb'];
			$resultat['total']           += (int) $ligne['nb'];
		}
	}
	if ( 0 === $resultat['total'] ) {
		return $resultat;
	}
	$derniers = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_ID AS id, comment_post_ID AS post_id, comment_date_gmt AS date_gmt, comment_approved AS statut, comment_content AS contenu
			FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved IN ('0', '1', 'spam') AND comment_type IN ('comment', '')
			ORDER BY comment_date_gmt DESC, comment_ID DESC LIMIT %d",
			$user_id,
			DONNEES_COMMENTAIRES_FICHE
		),
		ARRAY_A
	);
	// phpcs:enable
	donnees_amorcer_contenus( wp_list_pluck( $derniers, 'post_id' ) );
	foreach ( $derniers as $ligne ) {
		$statut                 = $statuts[ (string) $ligne['statut'] ] ?? 'attente';
		$post_id                = (int) $ligne['post_id'];
		$lien                   = 'approuve' === $statut ? donnees_url( $post_id ) : '';
		$resultat['derniers'][] = array(
			'id'      => (int) $ligne['id'],
			'sur'     => donnees_titre( $post_id ),
			'url'     => '' !== $lien ? $lien . '#comment-' . (int) $ligne['id'] : '',
			'date'    => donnees_iso( (string) $ligne['date_gmt'] ),
			'statut'  => $statut,
			'extrait' => wp_trim_words( wp_strip_all_tags( (string) $ligne['contenu'] ), 20, '…' ),
		);
	}
	return $resultat;
}

/**
 * Signalements de coquilles de la fiche : totaux par statut et derniers signalements (sans
 * empreinte d'adresse IP ni contexte).
 *
 * @param int $user_id Compte.
 * @return array{total:int,par_statut:array{nouveau:int,corrige:int,rejete:int},dernieres:array}
 */
function donnees_coquilles_fiche( int $user_id ): array {
	$resultat = array(
		'total'      => 0,
		'par_statut' => array(
			'nouveau' => 0,
			'corrige' => 0,
			'rejete'  => 0,
		),
		'dernieres'  => array(),
	);
	if ( ! function_exists( '\Yume\Core\Corrections\compter_par_statut' ) || ! function_exists( '\Yume\Core\Corrections\lister_coquilles' ) ) {
		return $resultat;
	}
	foreach ( \Yume\Core\Corrections\compter_par_statut( array( 'user_id' => $user_id ) ) as $statut => $nb ) {
		if ( isset( $resultat['par_statut'][ $statut ] ) ) {
			$resultat['par_statut'][ $statut ] = (int) $nb;
			$resultat['total']                += (int) $nb;
		}
	}
	if ( 0 === $resultat['total'] ) {
		return $resultat;
	}
	$lignes = \Yume\Core\Corrections\lister_coquilles(
		array(
			'user_id' => $user_id,
			'statut'  => 'tous',
			'limite'  => DONNEES_COQUILLES_FICHE,
		)
	)['lignes'];
	donnees_amorcer_contenus( wp_list_pluck( $lignes, 'chapitre_id' ) );
	foreach ( $lignes as $ligne ) {
		$resultat['dernieres'][] = array(
			'id'          => (int) $ligne['id'],
			'oeuvre_id'   => (int) $ligne['oeuvre_id'],
			'chapitre_id' => (int) $ligne['chapitre_id'],
			'chapitre'    => donnees_titre( (int) $ligne['chapitre_id'] ),
			'url'         => donnees_url( (int) $ligne['chapitre_id'] ),
			'extrait'     => mb_substr( (string) $ligne['extrait'], 0, 120 ),
			'statut'      => (string) $ligne['statut'],
			'le'          => donnees_iso( (string) $ligne['cree_le'] ),
		);
	}
	return $resultat;
}

/**
 * Notifications de la fiche : non lues, total sur DONNEES_NOTIFICATIONS_JOURS jours, nombre
 * d'appareils abonnés aux notifications push (jamais l'adresse ni les clés d'abonnement).
 *
 * @param int $user_id Compte.
 * @return array{non_lues:int,total:int,push:int}
 */
function donnees_notifications_fiche( int $user_id ): array {
	global $wpdb;
	$resultat = array(
		'non_lues' => 0,
		'total'    => 0,
		'push'     => 0,
	);
	if ( ! donnees_tables_lecteur_lisibles() ) {
		return $resultat;
	}
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( function_exists( '\Yume\Core\Social\table_notifications_lecteur' ) ) {
		$table                = \Yume\Core\Social\table_notifications_lecteur();
		$ligne                = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(CASE WHEN lu_le IS NULL THEN 1 ELSE 0 END) AS non_lues, SUM(CASE WHEN cree_le >= %s THEN 1 ELSE 0 END) AS total
				FROM {$table} WHERE user_id = %d",
				donnees_gmt_il_y_a( DONNEES_NOTIFICATIONS_JOURS ),
				$user_id
			),
			ARRAY_A
		);
		$resultat['non_lues'] = (int) ( $ligne['non_lues'] ?? 0 );
		$resultat['total']    = (int) ( $ligne['total'] ?? 0 );
	}
	if ( function_exists( '\Yume\Core\Social\table_push' ) ) {
		$table            = \Yume\Core\Social\table_push();
		$resultat['push'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
	}
	// phpcs:enable
	return $resultat;
}

/**
 * Fiche d'un compte (null s'il n'existe pas) : identité, lecture, favoris et alertes, listes,
 * notes, commentaires, coquilles, notifications. Jamais l'adresse e-mail (la vue la lit
 * elle-même si peut_voir_email()), ni l'identifiant de connexion, ni les réglages de lecture,
 * ni les listes privées nommées, ni les clés push.
 *
 * @param int $user_id Compte.
 * @return array<string,mixed>|null
 */
function fiche_compte( int $user_id ): ?array {
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( ! $user instanceof \WP_User ) {
		return null;
	}
	$id = (int) $user->ID;

	$favoris = function_exists( '\Yume\Core\Social\favoris_utilisateur' ) ? \Yume\Core\Social\favoris_utilisateur( $id ) : array();
	$notes   = function_exists( '\Yume\Core\Social\notes_utilisateur' ) ? \Yume\Core\Social\notes_utilisateur( $id ) : array();
	donnees_amorcer_contenus( array_merge( wp_list_pluck( $favoris, 'oeuvre_id' ), wp_list_pluck( $notes, 'oeuvre_id' ) ) );

	$lignes_favoris = array();
	foreach ( $favoris as $favori ) {
		$lignes_favoris[] = array(
			'oeuvre_id' => (int) $favori['oeuvre_id'],
			'titre'     => donnees_titre( (int) $favori['oeuvre_id'] ),
			'url'       => donnees_url( (int) $favori['oeuvre_id'] ),
			'frequence' => (string) $favori['frequence'],
			'depuis'    => donnees_iso( (string) $favori['created_at'] ),
		);
	}

	$lignes_notes = array();
	$somme        = 0;
	foreach ( $notes as $note ) {
		$somme         += (int) $note['note'];
		$lignes_notes[] = array(
			'oeuvre_id' => (int) $note['oeuvre_id'],
			'titre'     => donnees_titre( (int) $note['oeuvre_id'] ),
			'note'      => (int) $note['note'],
			'le'        => donnees_iso( (string) $note['updated_at'] ),
		);
	}

	$alertes = function_exists( '\Yume\Core\Social\preferences_alertes' )
		? \Yume\Core\Social\preferences_alertes( $id )
		: array(
			'sorties'      => true,
			'hebdo'        => false,
			'commentaires' => true,
		);

	return array(
		'identite'      => donnees_identite( $user ),
		'lecture'       => donnees_lecture_fiche( $id ),
		'favoris'       => $lignes_favoris,
		'alertes'       => array(
			'sorties'      => (bool) ( $alertes['sorties'] ?? true ),
			'hebdo'        => (bool) ( $alertes['hebdo'] ?? false ),
			'commentaires' => (bool) ( $alertes['commentaires'] ?? true ),
		),
		'listes'        => donnees_listes_fiche( $id ),
		'notes'         => array(
			'nb'      => count( $lignes_notes ),
			'moyenne' => $lignes_notes ? round( $somme / count( $lignes_notes ), 1 ) : 0.0,
			'lignes'  => $lignes_notes,
		),
		'commentaires'  => donnees_commentaires_fiche( $id ),
		'coquilles'     => donnees_coquilles_fiche( $id ),
		'notifications' => donnees_notifications_fiche( $id ),
	);
}
