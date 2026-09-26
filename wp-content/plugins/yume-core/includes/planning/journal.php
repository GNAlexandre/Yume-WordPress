<?php
/**
 * Journal du planning (table planning_journal) : écriture, lecture, mise en forme en français
 * et regroupement des lignes d'une même mise à jour.
 *
 * Champs journalisés : etape, avancement, responsables, date_cible, bloque, bloque_raison,
 * note_equipe (jamais publique), et les événements creation, publie (sortie complète, partielle,
 * retour en ligne, dernier chapitre), depublie, chapitre_publie, retire (tome retiré du planning), etape_forcee (équipe seulement), rappel, signalement
 * (gérants, non public) et digest (non public).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Champs qui sont des événements (journalisés même sans « ancienne » valeur différente).
 *
 * @return string[]
 */
function champs_evenements(): array {
	return array( 'creation', 'publie', 'depublie', 'chapitre_publie', 'retire', 'etape_forcee', 'rappel', 'signalement', 'digest' );
}

/**
 * Champs jamais publics.
 *
 * @return string[]
 */
function champs_prives(): array {
	return array( 'note_equipe', 'etape_forcee', 'signalement', 'digest' );
}

/**
 * Valeur enregistrée dans le journal : JSON pour les tableaux et objets, « 1 »/« 0 » pour
 * les booléens.
 *
 * @param mixed $valeur Valeur.
 */
function valeur_journal( $valeur ): string {
	if ( is_bool( $valeur ) ) {
		return $valeur ? '1' : '0';
	}
	if ( is_array( $valeur ) || is_object( $valeur ) ) {
		return (string) wp_json_encode( $valeur );
	}
	if ( null === $valeur ) {
		return '';
	}
	return is_scalar( $valeur ) ? (string) $valeur : '';
}

/**
 * Relit une valeur du journal (JSON décodé si c'en est).
 *
 * @param string|null $valeur Valeur brute.
 * @return mixed
 */
function lire_valeur( $valeur ) {
	$valeur = (string) $valeur;
	if ( '' !== $valeur && ( '{' === $valeur[0] || '[' === $valeur[0] ) ) {
		$json = json_decode( $valeur, true );
		if ( is_array( $json ) ) {
			return $json;
		}
	}
	return $valeur;
}

/**
 * Écrit une ligne du journal.
 *
 * @param int       $tome_id Tome (0 : événement global, ex. récapitulatif).
 * @param int       $user_id Auteur (0 = système).
 * @param string    $champ   Champ.
 * @param mixed     $ancien  Ancienne valeur.
 * @param mixed     $nouveau Nouvelle valeur.
 * @param bool|null $visible Visibilité publique (null : selon le champ).
 * @return int ID de la ligne (0 si rien n'a été écrit).
 */
function journaliser( int $tome_id, int $user_id, string $champ, $ancien, $nouveau, ?bool $visible = null ): int {
	global $wpdb;
	$champ = substr( (string) preg_replace( '/[^a-z0-9_.-]/', '', strtolower( $champ ) ), 0, 40 );
	if ( '' === $champ || $tome_id < 0 ) {
		return 0;
	}
	$a = valeur_journal( $ancien );
	$n = valeur_journal( $nouveau );
	if ( $a === $n && ! in_array( $champ, champs_evenements(), true ) ) {
		return 0;
	}
	$public = null === $visible ? ! in_array( $champ, champs_prives(), true ) : $visible;
	if ( 'note_equipe' === $champ ) {
		$public = false; // Jamais exposée, quel que soit le filtre.
	} else {
		/**
		 * Filtre la visibilité publique d'une ligne du journal.
		 *
		 * @param bool   $public  Visible sur le planning public.
		 * @param string $champ   Champ.
		 * @param int    $tome_id Tome.
		 */
		$public = (bool) apply_filters( 'yume_planning_journal_public', $public, $champ, $tome_id );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		table_journal(),
		array(
			'tome_id'    => $tome_id,
			'user_id'    => max( 0, $user_id ),
			'champ'      => $champ,
			'ancien'     => $a,
			'nouveau'    => $n,
			'public'     => $public ? 1 : 0,
			'created_at' => gmt(),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
	);
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Tomes dont l'historique ne doit pas apparaître publiquement : tomes privés ou à la corbeille,
 * et tomes d'une œuvre non publiée (projet pas encore annoncé).
 *
 * @return int[]
 */
function tomes_non_publics(): array {
	global $wpdb;
	$ids     = array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'yume_tome',
				'post_status'      => array( 'private', 'trash' ),
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		)
	);
	$oeuvres = array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'yume_oeuvre',
				'post_status'      => array( 'draft', 'pending', 'private', 'future', 'trash' ),
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		)
	);
	if ( $oeuvres ) {
		$marques = implode( ', ', array_fill( 0, count( $oeuvres ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		$tomes = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'yume_oeuvre_id' AND meta_value IN ($marques)", array_map( 'strval', $oeuvres ) ) );
		$ids   = array_merge( $ids, array_map( 'intval', (array) $tomes ) );
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Lit le journal, du plus récent au plus ancien.
 *
 * @param array $args 'tome_id' (int|int[]), 'oeuvre_id' (int), 'public' (bool : lignes publiques
 *                    seulement, hors tomes non publics), 'champs' (string[]), 'exclure' (string[]), 'depuis' (GMT),
 *                    'user_id' (int), 'limit' (défaut 20, max 500), 'offset'.
 * @return object[] Lignes (id, tome_id, user_id, champ, ancien, nouveau, public, created_at).
 */
function lire_journal( array $args = array() ): array {
	global $wpdb;
	$args   = wp_parse_args(
		$args,
		array(
			'tome_id'   => 0,
			'oeuvre_id' => 0,
			'public'    => false,
			'champs'    => array(),
			'exclure'   => array(),
			'depuis'    => '',
			'user_id'   => 0,
			'limit'     => 20,
			'offset'    => 0,
		)
	);
	$where  = array( '1=1' );
	$params = array();

	$tomes = array_filter( array_map( 'intval', (array) $args['tome_id'] ) );
	if ( (int) $args['oeuvre_id'] > 0 ) {
		$ids_oeuvre = array_map( 'intval', wp_list_pluck( yume_get_tomes( (int) $args['oeuvre_id'], array( 'status' => 'any' ) ), 'ID' ) );
		$tomes      = $tomes ? array_values( array_intersect( $tomes, $ids_oeuvre ) ) : $ids_oeuvre;
		if ( ! $tomes ) {
			return array();
		}
	}
	if ( $tomes ) {
		$where[] = 'tome_id IN (' . implode( ', ', array_fill( 0, count( $tomes ), '%d' ) ) . ')';
		$params  = array_merge( $params, array_values( $tomes ) );
	}
	if ( $args['public'] ) {
		$where[] = 'public = 1';
		$caches  = tomes_non_publics();
		if ( $caches ) {
			$where[] = 'tome_id NOT IN (' . implode( ', ', array_fill( 0, count( $caches ), '%d' ) ) . ')';
			$params  = array_merge( $params, $caches );
		}
	}
	$champs = array_filter( array_map( 'strval', (array) $args['champs'] ) );
	if ( $champs ) {
		$where[] = 'champ IN (' . implode( ', ', array_fill( 0, count( $champs ), '%s' ) ) . ')';
		$params  = array_merge( $params, array_values( $champs ) );
	}
	$exclure = array_filter( array_map( 'strval', (array) $args['exclure'] ) );
	if ( $exclure ) {
		$where[] = 'champ NOT IN (' . implode( ', ', array_fill( 0, count( $exclure ), '%s' ) ) . ')';
		$params  = array_merge( $params, array_values( $exclure ) );
	}
	if ( '' !== (string) $args['depuis'] ) {
		$where[]  = 'created_at >= %s';
		$params[] = (string) $args['depuis'];
	}
	if ( (int) $args['user_id'] > 0 ) {
		$where[]  = 'user_id = %d';
		$params[] = (int) $args['user_id'];
	}
	$params[] = max( 1, min( 500, (int) $args['limit'] ) );
	$params[] = max( 0, (int) $args['offset'] );
	$table    = table_journal();
	$sql      = "SELECT id, tome_id, user_id, champ, ancien, nouveau, public, created_at FROM {$table} WHERE "
		. implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	return is_array( $lignes ) ? $lignes : array();
}

/**
 * Nombre de lignes du journal correspondant à des champs depuis une date.
 *
 * @param string[] $champs Champs.
 * @param string   $depuis Date GMT.
 */
function compter_journal( array $champs, string $depuis ): int {
	global $wpdb;
	$table   = table_journal();
	$marques = implode( ', ', array_fill( 0, count( $champs ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE champ IN ($marques) AND created_at >= %s", array_merge( $champs, array( $depuis ) ) ) );
}

/**
 * Cible lisible d'une ligne : « Grimgar of Fantasy and Ash T.10 » (tome supprimé : libellé
 * générique).
 *
 * @param int $tome_id Tome.
 */
function cible_journal( int $tome_id ): string {
	if ( ! $tome_id ) {
		return '';
	}
	if ( 'yume_tome' !== get_post_type( $tome_id ) ) {
		return __( 'Tome supprimé', 'yume-core' );
	}
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	$oeuvre    = $oeuvre_id ? titre_brut( $oeuvre_id ) : '';
	return trim( $oeuvre . ' ' . yume_libelle_tome( $tome_id, true ) );
}

/**
 * Texte d'un changement (sans balise), ou chaîne vide s'il n'y a rien à montrer.
 *
 * @param object $ligne  Ligne du journal.
 * @param bool   $equipe Lecture par l'équipe (notes visibles).
 */
function texte_changement( $ligne, bool $equipe ): string {
	$champ   = (string) $ligne->champ;
	$ancien  = lire_valeur( $ligne->ancien );
	$nouveau = lire_valeur( $ligne->nouveau );
	$etapes  = yume_etapes();

	switch ( $champ ) {
		case 'etape':
			$libelle = is_string( $nouveau ) && isset( $etapes[ $nouveau ] ) ? $etapes[ $nouveau ] : (string) valeur_journal( $nouveau );
			/* translators: %s : étape */
			return sprintf( __( 'étape : %s', 'yume-core' ), mb_strtolower( $libelle ) );

		case 'avancement':
			$a       = norm_avancement( is_array( $ancien ) ? $ancien : array() );
			$n       = norm_avancement( is_array( $nouveau ) ? $nouveau : array() );
			$parties = array();
			foreach ( ETAPES_TRAVAIL as $etape ) {
				if ( $a[ $etape ] !== $n[ $etape ] ) {
					$parties[] = sprintf( '%1$s %2$s → %3$s', libelle_etape_min( $etape ), pct( $a[ $etape ] ), pct( $n[ $etape ] ) );
				}
			}
			return implode( ', ', $parties );

		case 'responsables':
			$a       = norm_responsables( is_array( $ancien ) ? $ancien : array() );
			$n       = norm_responsables( is_array( $nouveau ) ? $nouveau : array() );
			$parties = array();
			foreach ( ETAPES_TRAVAIL as $etape ) {
				if ( $a[ $etape ] !== $n[ $etape ] ) {
					$parties[] = $n[ $etape ]
						/* translators: 1: étape, 2: pseudo */
						? sprintf( __( '%1$s confiée à %2$s', 'yume-core' ), libelle_etape_min( $etape ), nom_utilisateur( $n[ $etape ] ) )
						/* translators: %s : étape */
						: sprintf( __( '%s sans responsable', 'yume-core' ), libelle_etape_min( $etape ) );
				}
			}
			return implode( ', ', $parties );

		case 'date_cible':
			$date = is_string( $nouveau ) ? $nouveau : '';
			if ( ! valider_date( $date ) ) {
				return __( 'date cible retirée', 'yume-core' );
			}
			/* translators: %s : date */
			return sprintf( __( 'date cible : %s', 'yume-core' ), format_fr( ts_date( $date ), 'j M' ) );

		case 'bloque':
			return '1' === (string) $ligne->nouveau ? __( 'bloqué', 'yume-core' ) : __( 'débloqué', 'yume-core' );

		case 'bloque_raison':
			$raison = is_string( $nouveau ) ? trim( $nouveau ) : '';
			/* translators: %s : raison du blocage */
			return '' === $raison ? '' : sprintf( __( 'raison : %s', 'yume-core' ), $raison );

		case 'note_equipe':
			if ( ! $equipe ) {
				return '';
			}
			$note = is_string( $nouveau ) ? trim( $nouveau ) : '';
			/* translators: %s : note */
			return '' === $note ? __( 'note effacée', 'yume-core' ) : sprintf( __( 'note : « %s »', 'yume-core' ), wp_html_excerpt( $note, 90, '…' ) );

		case 'creation':
			return __( 'ajouté au planning', 'yume-core' );

		case 'retire':
			return __( 'retiré du planning', 'yume-core' );

		case 'etape_forcee':
			if ( ! $equipe ) {
				return '';
			}
			$infos   = is_array( $nouveau ) ? $nouveau : array();
			$cible   = (string) ( $infos['etape'] ?? '' );
			$av      = norm_avancement( is_array( $infos['avancement'] ?? null ) ? $infos['avancement'] : array() );
			$parties = array();
			foreach ( ETAPES_TRAVAIL as $etape ) {
				if ( $etape === $cible ) {
					break;
				}
				if ( $av[ $etape ] < 100 ) {
					$parties[] = libelle_etape_min( $etape ) . ' ' . pct( $av[ $etape ] );
				}
			}
			$libelle = isset( $etapes[ $cible ] ) ? mb_strtolower( $etapes[ $cible ] ) : $cible;
			return $parties
				/* translators: 1: étape forcée, 2: étapes inachevées (« traduction 70 % ») */
				? sprintf( __( 'étape forcée : %1$s (%2$s)', 'yume-core' ), $libelle, implode( ', ', $parties ) )
				/* translators: %s : étape forcée */
				: sprintf( __( 'étape forcée : %s', 'yume-core' ), $libelle );

		case 'publie':
			$infos   = is_array( $nouveau ) ? $nouveau : array();
			$nb      = (int) ( $infos['chapitres'] ?? 0 );
			$parties = array();
			if ( ! empty( $infos['retour'] ) ) {
				$parties[] = __( 'remis en ligne', 'yume-core' );
			}
			if ( ! empty( $infos['partiel'] ) ) {
				$total     = (int) ( $infos['total'] ?? 0 );
				$parties[] = $total > 0
					/* translators: 1: chapitres publiés, 2: total */
					? sprintf( _n( 'sortie partielle : %1$d chapitre publié sur %2$d', 'sortie partielle : %1$d chapitres publiés sur %2$d', $nb, 'yume-core' ), $nb, $total )
					: __( 'sortie partielle', 'yume-core' );
			} elseif ( ! empty( $infos['complet'] ) ) {
				$parties[] = __( 'dernier chapitre publié : tome complet', 'yume-core' );
			} elseif ( $equipe && $nb ) {
				/* translators: %d : nombre de chapitres */
				$parties[] = sprintf( _n( '%d chapitre', '%d chapitres', $nb, 'yume-core' ), $nb );
			}
			return implode( ', ', $parties );

		case 'depublie':
			$statuts = array(
				'draft'   => __( 'repassé en brouillon', 'yume-core' ),
				'pending' => __( 'repassé en attente de relecture', 'yume-core' ),
				'private' => __( 'passé en privé', 'yume-core' ),
				'future'  => __( 'reprogrammé', 'yume-core' ),
				'trash'   => __( 'mis à la corbeille', 'yume-core' ),
			);
			$statut  = is_string( $nouveau ) ? $nouveau : '';
			/* translators: %s : nouveau statut (« repassé en brouillon ») */
			return isset( $statuts[ $statut ] ) ? sprintf( __( 'dépublié (%s)', 'yume-core' ), $statuts[ $statut ] ) : __( 'dépublié', 'yume-core' );

		case 'chapitre_publie':
			return '';

		case 'rappel':
			$infos = is_array( $nouveau ) ? $nouveau : array();
			$noms  = implode( ', ', array_map( __NAMESPACE__ . '\\nom_utilisateur', array_map( 'intval', (array) ( $infos['destinataires'] ?? array() ) ) ) );
			$jours = (int) ( $infos['jours'] ?? 0 );
			$motif = 'date' === ( $infos['motif'] ?? '' )
				/* translators: %d : jours */
				? sprintf( _n( 'date cible dépassée de %d jour', 'date cible dépassée de %d jours', $jours, 'yume-core' ), $jours )
				/* translators: %d : jours */
				: sprintf( _n( 'sans mise à jour depuis %d jour', 'sans mise à jour depuis %d jours', $jours, 'yume-core' ), $jours );
			/* translators: 1: destinataires, 2: motif */
			return '' !== $noms ? sprintf( __( 'rappel envoyé à %1$s : %2$s', 'yume-core' ), $noms, $motif ) : sprintf( __( 'rappel : %s', 'yume-core' ), $motif );

		case 'signalement':
			$infos    = is_array( $nouveau ) ? $nouveau : array();
			$manquant = array_filter( array_map( __NAMESPACE__ . '\\libelle_role_manquant', (array) ( $infos['manquants'] ?? array() ) ) );
			/* translators: %s : rôles manquants */
			return sprintf( __( 'signalé aux gérants : %s', 'yume-core' ), $manquant ? implode( ', ', $manquant ) : __( 'tome bloqué', 'yume-core' ) );

		case 'digest':
			$infos = is_array( $nouveau ) ? $nouveau : array();
			return sprintf(
				/* translators: 1: sorties, 2: retards, 3: bloqués */
				__( 'récapitulatif hebdomadaire : %1$d sortie(s), %2$d retard(s), %3$d bloqué(s)', 'yume-core' ),
				(int) ( $infos['sorties'] ?? 0 ),
				(int) ( $infos['retards'] ?? 0 ),
				(int) ( $infos['bloques'] ?? 0 )
			);
	}
	/* translators: 1: champ, 2: valeur */
	return sprintf( __( '%1$s : %2$s', 'yume-core' ), str_replace( '_', ' ', $champ ), wp_html_excerpt( valeur_journal( $nouveau ), 60, '…' ) );
}

/**
 * Regroupe les lignes d'une même mise à jour (même tome, même auteur, à quelques secondes
 * d'intervalle) en entrées lisibles.
 *
 * @param object[] $lignes Lignes (du plus récent au plus ancien).
 * @param bool     $equipe Lecture par l'équipe.
 * @param int      $max    Nombre maximal d'entrées.
 * @return array<int,array{ts:int,user_id:int,auteur:string,tome_id:int,cible:string,parties:string[],publie:bool,type:string,ids:int[],donnees:mixed}>
 */
function grouper_journal( array $lignes, bool $equipe, int $max = 0 ): array {
	$entrees  = array();
	$courante = null;
	$seuls    = array( 'chapitre_publie', 'rappel', 'signalement', 'digest' );
	foreach ( $lignes as $ligne ) {
		$ts     = ts_gmt( (string) $ligne->created_at );
		$champ  = (string) $ligne->champ;
		$seul   = in_array( $champ, $seuls, true );
		$accole = null !== $courante && ! $seul && 'maj' === $courante['type']
			&& $courante['tome_id'] === (int) $ligne->tome_id && $courante['user_id'] === (int) $ligne->user_id
			&& abs( $courante['ts'] - $ts ) <= 5;
		if ( ! $accole ) {
			if ( null !== $courante ) {
				$entrees[] = $courante;
				if ( $max > 0 && count( $entrees ) >= $max ) {
					$courante = null;
					break;
				}
			}
			$courante = array(
				'ts'      => $ts,
				'user_id' => (int) $ligne->user_id,
				'auteur'  => nom_utilisateur( (int) $ligne->user_id ),
				'tome_id' => (int) $ligne->tome_id,
				'cible'   => cible_journal( (int) $ligne->tome_id ),
				'parties' => array(),
				'publie'  => false,
				'type'    => $seul ? $champ : 'maj',
				'ids'     => array(),
				'donnees' => $seul ? lire_valeur( $ligne->nouveau ) : null,
			);
			if ( 'chapitre_publie' === $champ ) {
				$infos = lire_valeur( $ligne->nouveau );
				$lib   = is_array( $infos ) ? (string) ( $infos['libelle'] ?? '' ) : '';
				if ( '' !== $lib ) {
					$courante['cible'] = trim( $courante['cible'] . ' · ' . $lib );
				}
				$courante['publie'] = true;
			}
		}
		$courante['ids'][] = (int) $ligne->id;
		if ( 'publie' === $champ ) {
			// Une sortie partielle (chapitres restant à paraître) n'est pas encore « publié ».
			$infos              = lire_valeur( $ligne->nouveau );
			$courante['publie'] = $courante['publie'] || ! ( is_array( $infos ) && ! empty( $infos['partiel'] ) );
		}
		$texte = texte_changement( $ligne, $equipe );
		if ( '' !== $texte ) {
			$courante['parties'][] = $texte;
		}
	}
	if ( null !== $courante ) {
		$entrees[] = $courante;
	}
	// Une publication résume la mise à jour : l'étape et les 100 % sont implicites.
	foreach ( $entrees as &$entree ) {
		if ( $entree['publie'] && 'maj' === $entree['type'] ) {
			$entree['parties'] = array_values(
				array_filter(
					$entree['parties'],
					static function ( string $p ): bool {
						return ! str_starts_with( $p, __( 'étape :', 'yume-core' ) ) && ! str_contains( $p, '→' ) && __( 'débloqué', 'yume-core' ) !== $p;
					}
				)
			);
		}
	}
	unset( $entree );
	return array_values(
		array_filter(
			$entrees,
			static function ( array $e ): bool {
				return $e['publie'] || $e['parties'];
			}
		)
	);
}

/**
 * Texte brut d'une entrée regroupée (flux RSS, e-mails).
 *
 * @param array $entree Entrée.
 */
function texte_entree( array $entree ): string {
	$parties = $entree['parties'];
	if ( $entree['publie'] ) {
		array_unshift( $parties, __( 'Publié', 'yume-core' ) );
	}
	return implode( ' · ', array_filter( array_merge( array( $entree['auteur'], $entree['cible'] ), array( implode( ', ', $parties ) ) ) ) );
}
