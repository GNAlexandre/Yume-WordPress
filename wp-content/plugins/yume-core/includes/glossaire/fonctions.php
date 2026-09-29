<?php
/**
 * Glossaire par œuvre : constantes, tables, catégories, analyse et normalisation du YAML de
 * Yume-Trad, import (remplacement atomique des entrées + nouvelle version), lecture.
 *
 * Format accepté (docs/glossaire.md) : racine = mapping de catégories (personnages, lieux,
 * organisations, creatures, objets, termes, evenements, groupes, anglicismes, ou toute autre clé :
 * catégorie générique). Chaque catégorie est une liste d'entrées (termes_source, role,
 * description, traduire, cibles.fr {nom, pluriel, genre, variantes, interdits, force},
 * provenance, preuve, confiance, et facultativement spoiler, tome) ; anglicismes = liste de
 * {vo, fr}. Champs absents tolérés, champs inconnus ignorés, nombres convertis en texte.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

/** Capacité : importer, restaurer et télécharger les glossaires (éditeur, gérant, administrateur). */
const CAPACITE = 'yume_glossaire';

/** Durée de conservation d'un glossaire vérifié en attente de publication (brouillon). */
const DUREE_BROUILLON = 30 * MINUTE_IN_SECONDS;

/** Versions conservées par œuvre (brouillons non compris). */
const VERSIONS_CONSERVEES = 5;

/**
 * Source des lignes « brouillon » de la table des versions : glossaire vérifié dans l'espace
 * équipe, pas encore publié (equipe.php). Jamais comptées dans l'historique ni dans la
 * rétention, jamais servies (version(), versions(), REST, téléchargement).
 */
const SOURCE_BROUILLON = 'brouillon';

/** Nombre maximal d'entrées (anglicismes compris) d'un glossaire. */
const ENTREES_MAX = 5000;

/** Envois par heure et par compte (API). */
const ENVOIS_PAR_HEURE = 20;

/** Moins d'entrées publiques que ce seuil : page glossaire en noindex. */
const SEUIL_INDEXATION = 5;

/** Méta (privée) de l'œuvre : état du glossaire courant (version, compteurs, date). */
const META_ETAT = '_yume_glossaire';

/** Version du schéma des tables (option OPTION_SCHEMA). */
const VERSION_SCHEMA = '1';

/** Option : version du schéma installée. */
const OPTION_SCHEMA = 'yume_glossaire_schema';

/** Groupe du cache objet. */
const GROUPE_CACHE = 'yume_glossaire';

/** Avertissements détaillés au plus (les suivants sont résumés). */
const AVERTISSEMENTS_MAX = 50;

/**
 * Table des entrées.
 */
function table_entrees(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_glossaire';
}

/**
 * Table des versions.
 */
function table_versions(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_glossaire_versions';
}

/**
 * Catégories connues, dans l'ordre d'affichage : clé => libellé.
 *
 * @return array<string,string>
 */
function categories(): array {
	return array(
		'personnages'   => __( 'Personnages', 'yume-core' ),
		'lieux'         => __( 'Lieux', 'yume-core' ),
		'organisations' => __( 'Organisations', 'yume-core' ),
		'creatures'     => __( 'Créatures', 'yume-core' ),
		'objets'        => __( 'Objets', 'yume-core' ),
		'termes'        => __( 'Termes', 'yume-core' ),
		'evenements'    => __( 'Événements', 'yume-core' ),
		'groupes'       => __( 'Groupes', 'yume-core' ),
		'anglicismes'   => __( 'Anglicismes', 'yume-core' ),
	);
}

/**
 * Libellé d'une catégorie (clé inconnue : tirée de la clé, « armes_legendaires » → « Armes legendaires »).
 *
 * @param string $cle Clé normalisée.
 */
function libelle_categorie( string $cle ): string {
	$connues = categories();
	if ( isset( $connues[ $cle ] ) ) {
		return $connues[ $cle ];
	}
	$texte = trim( str_replace( '_', ' ', $cle ) );
	return '' === $texte ? __( 'Autres', 'yume-core' ) : mb_strtoupper( mb_substr( $texte, 0, 1 ) ) . mb_substr( $texte, 1 );
}

/**
 * Ordre d'affichage des catégories : connues d'abord, puis les autres (ordre du fichier, tri
 * stable), les anglicismes en dernier.
 *
 * @param string $a Clé.
 * @param string $b Clé.
 */
function comparer_categories( string $a, string $b ): int {
	$rang = array_flip( array_keys( categories() ) );
	$ra   = 'anglicismes' === $a ? 1000 : ( $rang[ $a ] ?? 100 );
	$rb   = 'anglicismes' === $b ? 1000 : ( $rang[ $b ] ?? 100 );
	return $ra <=> $rb;
}

/**
 * Clé de catégorie normalisée : minuscules sans accents, [a-z0-9_], 40 caractères au plus.
 *
 * @param mixed $cle Clé du YAML.
 */
function cle_categorie( $cle ): string {
	$cle = strtolower( remove_accents( trim( (string) $cle ) ) );
	$cle = trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', $cle ), '_' );
	return substr( $cle, 0, 40 );
}

/**
 * Provenances reconnues (autre valeur ou champ absent : « inconnue »).
 *
 * @return string[]
 */
function provenances(): array {
	return array( 'humain', 'terminologue', 'glossariste', 'import', 'inconnue' );
}

/**
 * Niveaux de confiance reconnus (autre valeur : chaîne vide).
 *
 * @return string[]
 */
function confiances(): array {
	return array( 'sure', 'probable', 'hypothese' );
}

/*
 * -----------------------------------------------------------------------------
 * Normalisation
 * -----------------------------------------------------------------------------
 */

/**
 * Texte brut borné : scalaire converti (nombres, booléens), balises retirées, caractères de
 * contrôle supprimés, espaces réduites (retours à la ligne gardés si $multiligne).
 *
 * @param mixed $valeur     Valeur du YAML.
 * @param int   $max        Longueur maximale (caractères).
 * @param bool  $multiligne Garder les retours à la ligne.
 */
function texte( $valeur, int $max, bool $multiligne = false ): string {
	if ( is_bool( $valeur ) ) {
		$valeur = $valeur ? 'true' : 'false';
	}
	if ( ! is_scalar( $valeur ) ) {
		return '';
	}
	$texte = wp_check_invalid_utf8( (string) $valeur, true );
	$texte = wp_strip_all_tags( $texte, false );
	$texte = str_replace( array( "\r\n", "\r" ), "\n", $texte );
	$texte = (string) preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $texte );
	if ( $multiligne ) {
		$texte = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $texte );
		$texte = (string) preg_replace( '/ *\n */', "\n", $texte );
		$texte = (string) preg_replace( '/\n{3,}/', "\n\n", $texte );
	} else {
		$texte = (string) preg_replace( '/\s+/u', ' ', $texte );
	}
	$texte = trim( $texte );
	return mb_strlen( $texte ) > $max ? rtrim( mb_substr( $texte, 0, $max - 1 ) ) . '…' : $texte;
}

/**
 * Liste de textes (scalaire seul accepté comme liste d'un élément), sans vide ni doublon.
 *
 * @param mixed $valeur Valeur du YAML.
 * @param int   $max    Longueur maximale de chaque texte.
 * @param int   $nombre Nombre maximal d'éléments.
 * @return string[]
 */
function liste_textes( $valeur, int $max, int $nombre ): array {
	if ( null === $valeur || '' === $valeur ) {
		return array();
	}
	$valeur = is_array( $valeur ) ? $valeur : array( $valeur );
	$liste  = array();
	foreach ( $valeur as $element ) {
		$t = texte( $element, $max );
		if ( '' !== $t && ! in_array( $t, $liste, true ) ) {
			$liste[] = $t;
		}
		if ( count( $liste ) >= $nombre ) {
			break;
		}
	}
	return $liste;
}

/**
 * Booléen du YAML (true, « true », « oui », 1) ; tout le reste est faux.
 *
 * @param mixed $valeur Valeur.
 */
function booleen( $valeur ): bool {
	if ( is_bool( $valeur ) ) {
		return $valeur;
	}
	if ( is_int( $valeur ) ) {
		return 1 === $valeur;
	}
	return is_string( $valeur ) && in_array( strtolower( trim( $valeur ) ), array( 'true', 'oui', 'yes', '1' ), true );
}

/**
 * Genre grammatical normalisé : « masculin », « féminin » ou ''.
 *
 * @param mixed $valeur Valeur (masculin, féminin, feminin, m, f…).
 */
function genre( $valeur ): string {
	$g = strtolower( remove_accents( texte( $valeur, 20 ) ) );
	if ( in_array( $g, array( 'm', 'masc', 'masculin' ), true ) ) {
		return 'masculin';
	}
	if ( in_array( $g, array( 'f', 'fem', 'feminin' ), true ) ) {
		return 'féminin';
	}
	return '';
}

/**
 * Texte normalisé pour la recherche : minuscules, sans accents, espaces réduites.
 *
 * @param string ...$textes Textes.
 */
function normaliser_recherche( string ...$textes ): string {
	$texte = mb_strtolower( remove_accents( implode( ' ', $textes ) ) );
	return trim( (string) preg_replace( '/\s+/u', ' ', $texte ) );
}

/**
 * Le texte contient-il des caractères japonais (kana, kanji) ?
 *
 * @param string $texte Texte.
 */
function est_japonais( string $texte ): bool {
	return (bool) preg_match( '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF66}-\x{FF9F}]/u', $texte );
}

/**
 * Normalise une entrée d'une catégorie.
 *
 * @param mixed $brut Entrée du YAML.
 * @return array|string Entrée normalisée, ou raison du refus.
 */
function normaliser_entree( $brut ) {
	if ( ! is_array( $brut ) || ( $brut && array_is_list( $brut ) ) ) {
		return __( 'un bloc de champs (termes_source, cibles…) est attendu', 'yume-core' );
	}
	$cibles = is_array( $brut['cibles'] ?? null ) ? $brut['cibles'] : array();
	$fr     = $cibles['fr'] ?? null;
	if ( is_scalar( $fr ) ) {
		$fr = array( 'nom' => $fr );
	}
	$fr       = is_array( $fr ) ? $fr : array();
	$traduire = $brut['traduire'] ?? null;
	$traduire = is_bool( $traduire ) ? $traduire : ( is_string( $traduire ) && in_array( strtolower( $traduire ), array( 'true', 'false' ), true ) ? 'true' === strtolower( $traduire ) : null );
	$source   = liste_textes( $brut['termes_source'] ?? null, 200, 20 );
	$nom_fr   = texte( $fr['nom'] ?? '', 200 );
	$nom      = '' !== $nom_fr ? $nom_fr : ( $source[0] ?? '' );
	if ( '' === $nom ) {
		return __( 'ni nom français ni terme source', 'yume-core' );
	}
	$provenance = strtolower( remove_accents( texte( $brut['provenance'] ?? '', 40 ) ) );
	$confiance  = strtolower( remove_accents( texte( $brut['confiance'] ?? '', 40 ) ) );
	$tome       = $brut['tome'] ?? 0;
	$tome       = is_numeric( $tome ) ? max( 0, min( 999, (int) $tome ) ) : 0;
	// Graphies refusées par Yume-Trad, par langue source (« en: [NUMBER 48] ») : notes internes.
	$refusees = array();
	if ( is_array( $brut['graphies_refusees'] ?? null ) ) {
		foreach ( $brut['graphies_refusees'] as $langue => $graphies ) {
			$langue = strtolower( (string) $langue );
			$liste  = liste_textes( $graphies, 200, 30 );
			if ( preg_match( '/^[a-z]{2,3}$/', $langue ) && $liste ) {
				$refusees[ $langue ] = $liste;
			}
			if ( count( $refusees ) >= 5 ) {
				break;
			}
		}
	}
	return array(
		'nom'           => $nom,
		'nom_fr'        => $nom_fr,
		'termes_source' => $source,
		'role'          => texte( $brut['role'] ?? '', 200 ),
		'description'   => texte( $brut['description'] ?? '', 2000, true ),
		'traduire'      => $traduire,
		'pluriel'       => texte( $fr['pluriel'] ?? '', 200 ),
		'genre'         => genre( $fr['genre'] ?? '' ),
		'variantes'     => liste_textes( $fr['variantes'] ?? null, 200, 30 ),
		'interdits'     => liste_textes( $fr['interdits'] ?? null, 200, 30 ),
		'force'         => booleen( $fr['force'] ?? false ),
		'provenance'    => in_array( $provenance, provenances(), true ) ? $provenance : 'inconnue',
		'preuve'        => texte( $brut['preuve'] ?? '', 1000, true ),
		'confiance'     => in_array( $confiance, confiances(), true ) ? $confiance : '',
		'spoiler'       => booleen( $brut['spoiler'] ?? false ),
		'tome'          => $tome,
		'refusees'      => $refusees,
		// Langue des termes source non japonais : celle des graphies refusées, sinon l'anglais.
		'langue_source' => $refusees ? (string) array_key_first( $refusees ) : '',
		// Public : traduction française connue, ou nom volontairement gardé tel quel.
		'public'        => '' !== $nom_fr || false === $traduire,
	);
}

/**
 * Normalise un anglicisme ({vo, fr}).
 *
 * @param mixed $brut Élément du YAML.
 * @return array|string Anglicisme normalisé, ou raison du refus.
 */
function normaliser_anglicisme( $brut ) {
	if ( ! is_array( $brut ) ) {
		return __( 'une paire vo / fr est attendue', 'yume-core' );
	}
	$vo = texte( $brut['vo'] ?? '', 300 );
	$fr = texte( $brut['fr'] ?? '', 300 );
	if ( '' === $vo || '' === $fr ) {
		return __( 'vo et fr sont tous deux nécessaires', 'yume-core' );
	}
	return array(
		'vo'     => $vo,
		'fr'     => $fr,
		'public' => true,
	);
}

/**
 * Analyse un glossaire YAML sans rien écrire.
 *
 * @param string $yaml Document.
 * @return array{lignes:array,compteurs:array<string,int>,avertissements:string[],entrees:int,anglicismes:int,publiques:int,sha256:string}|\WP_Error
 */
function analyser_glossaire( string $yaml ) {
	if ( strlen( $yaml ) > Lecteur_Yaml::TAILLE_MAX ) {
		return new \WP_Error( 'yume_glossaire_trop_gros', __( 'Fichier trop volumineux : 4 Mo au plus.', 'yume-core' ), array( 'status' => 413 ) );
	}
	if ( '' === trim( $yaml ) ) {
		return new \WP_Error( 'yume_glossaire_vide', __( 'Le glossaire est vide.', 'yume-core' ), array( 'status' => 400 ) );
	}
	try {
		$donnees = Lecteur_Yaml::analyser( $yaml );
	} catch ( Erreur_Yaml $e ) {
		return new \WP_Error(
			'yume_glossaire_yaml_invalide',
			/* translators: %s : message du lecteur YAML, avec le numéro de ligne */
			sprintf( __( 'YAML invalide. %s', 'yume-core' ), $e->getMessage() ),
			array(
				'status' => 400,
				'ligne'  => $e->ligne,
			)
		);
	}
	if ( null === $donnees ) {
		return new \WP_Error( 'yume_glossaire_vide', __( 'Le glossaire est vide.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! is_array( $donnees ) || ( $donnees && array_is_list( $donnees ) ) ) {
		return new \WP_Error( 'yume_glossaire_structure', __( 'Structure inattendue : la racine du fichier doit lister des catégories (« personnages: », « lieux: »…).', 'yume-core' ), array( 'status' => 400 ) );
	}

	$lignes         = array();
	$compteurs      = array();
	$avertissements = array();
	$entrees        = 0;
	$anglicismes    = 0;
	$publiques      = 0;
	$avertir        = static function ( string $message ) use ( &$avertissements ): void {
		$avertissements[] = $message;
	};
	foreach ( $donnees as $cle_brute => $elements ) {
		$cle = cle_categorie( $cle_brute );
		if ( '' === $cle ) {
			/* translators: %s : clé du YAML */
			$avertir( sprintf( __( 'Catégorie « %s » ignorée : nom inutilisable.', 'yume-core' ), texte( $cle_brute, 60 ) ) );
			continue;
		}
		if ( null === $elements || array() === $elements || '' === $elements ) {
			continue; // Catégorie vide : rien à signaler.
		}
		$anglais = 'anglicismes' === $cle;
		// Anglicismes écrits en mapping « vo: fr » : tolérés.
		if ( $anglais && is_array( $elements ) && ! array_is_list( $elements ) ) {
			$paires = array();
			foreach ( $elements as $vo => $fr ) {
				$paires[] = array(
					'vo' => $vo,
					'fr' => $fr,
				);
			}
			$elements = $paires;
		}
		if ( ! is_array( $elements ) || ! array_is_list( $elements ) ) {
			/* translators: %s : catégorie */
			$avertir( sprintf( __( 'Catégorie « %s » ignorée : une liste d’entrées est attendue.', 'yume-core' ), $cle ) );
			continue;
		}
		foreach ( $elements as $n => $brut ) {
			$entree = $anglais ? normaliser_anglicisme( $brut ) : normaliser_entree( $brut );
			if ( is_string( $entree ) ) {
				/* translators: 1: catégorie, 2: numéro de l'entrée (1 = première), 3: raison */
				$avertir( sprintf( __( '%1$s, entrée %2$d ignorée : %3$s.', 'yume-core' ), $cle, $n + 1, $entree ) );
				continue;
			}
			$compteurs[ $cle ] = ( $compteurs[ $cle ] ?? 0 ) + 1;
			if ( $anglais ) {
				++$anglicismes;
				$lignes[] = array(
					'categorie'     => $cle,
					'nom'           => $entree['fr'],
					'termes_source' => $entree['vo'],
					'recherche'     => normaliser_recherche( $entree['fr'], $entree['vo'] ),
					'donnees'       => $entree,
				);
				continue;
			}
			++$entrees;
			if ( $entree['public'] ) {
				++$publiques;
			}
			$lignes[] = array(
				'categorie'     => $cle,
				'nom'           => $entree['nom'],
				'termes_source' => implode( "\n", $entree['termes_source'] ),
				'recherche'     => normaliser_recherche( $entree['nom'], implode( ' ', $entree['variantes'] ), implode( ' ', $entree['termes_source'] ), $entree['description'] ),
				'donnees'       => $entree,
			);
		}
	}
	if ( ! $lignes ) {
		return new \WP_Error( 'yume_glossaire_vide', __( 'Aucune entrée exploitable dans ce glossaire.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( count( $lignes ) > ENTREES_MAX ) {
		return new \WP_Error(
			'yume_glossaire_trop_entrees',
			/* translators: 1: entrées du fichier, 2: maximum */
			sprintf( __( 'Trop d’entrées : %1$d pour %2$d au plus.', 'yume-core' ), count( $lignes ), ENTREES_MAX ),
			array( 'status' => 413 )
		);
	}
	if ( count( $avertissements ) > AVERTISSEMENTS_MAX ) {
		$reste          = count( $avertissements ) - AVERTISSEMENTS_MAX;
		$avertissements = array_slice( $avertissements, 0, AVERTISSEMENTS_MAX );
		/* translators: %d : avertissements non détaillés */
		$avertissements[] = sprintf( _n( '… et %d autre avertissement.', '… et %d autres avertissements.', $reste, 'yume-core' ), $reste );
	}
	uksort( $compteurs, __NAMESPACE__ . '\\comparer_categories' );
	return array(
		'lignes'         => $lignes,
		'compteurs'      => $compteurs,
		'avertissements' => $avertissements,
		'entrees'        => $entrees,
		'anglicismes'    => $anglicismes,
		'publiques'      => $publiques,
		'sha256'         => hash( 'sha256', $yaml ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Écriture
 * -----------------------------------------------------------------------------
 */

/**
 * Base SQLite (intégration de développement) ?
 */
function est_sqlite(): bool {
	global $wpdb;
	return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || str_contains( strtolower( get_class( $wpdb ) ), 'sqlite' );
}

/**
 * La connexion est-elle déjà dans une transaction ? MariaDB : variable @@in_transaction (propre
 * à MariaDB). MySQL 8, qui ne la connaît pas (et dont information_schema.innodb_trx exige le
 * privilège PROCESS, rarement accordé chez un hébergeur) : sonde par point de sauvegarde —
 * hors transaction (autocommit), « SAVEPOINT » est validé aussitôt et « RELEASE SAVEPOINT »
 * échoue ; dans une transaction, il réussit. Requêtes sans message d'erreur ; null si rien ne
 * permet de le savoir.
 *
 * @param string|null $serveur Version du serveur (défaut : $wpdb->db_server_info()).
 */
function transaction_en_cours( ?string $serveur = null ): ?bool {
	global $wpdb;
	$serveur  = strtolower( $serveur ?? ( method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '' ) );
	$masquer  = $wpdb->suppress_errors( true );
	$en_cours = null;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	if ( str_contains( $serveur, 'mariadb' ) ) {
		$valeur = $wpdb->get_var( 'SELECT @@in_transaction' );
		if ( '' === $wpdb->last_error && null !== $valeur ) {
			$en_cours = '1' === (string) $valeur;
		}
	}
	if ( null === $en_cours && false !== $wpdb->query( 'SAVEPOINT yume_sonde' ) ) {
		$en_cours = false !== $wpdb->query( 'RELEASE SAVEPOINT yume_sonde' );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	$wpdb->last_error = '';
	$wpdb->suppress_errors( $masquer );
	return $en_cours;
}

/**
 * Ouvre une transaction (MySQL/MariaDB). Déjà dans une transaction (tests, autre module) : point
 * de sauvegarde, pour ne pas valider implicitement la transaction englobante. SQLite : rien.
 *
 * @return string Mode : 'aucun', 'savepoint' ou 'transaction'.
 */
function ouvrir_transaction(): string {
	global $wpdb;
	if ( est_sqlite() ) {
		return 'aucun';
	}
	if ( true === transaction_en_cours() ) {
		$wpdb->query( 'SAVEPOINT yume_glossaire' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 'savepoint';
	}
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return 'transaction';
}

/**
 * Valide ou annule la transaction ouverte par ouvrir_transaction().
 *
 * @param string $mode    Mode renvoyé par ouvrir_transaction().
 * @param bool   $valider Valider (sinon annuler).
 */
function fermer_transaction( string $mode, bool $valider ): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	if ( 'savepoint' === $mode && $valider ) {
		$wpdb->query( 'RELEASE SAVEPOINT yume_glossaire' );
	} elseif ( 'savepoint' === $mode ) {
		$wpdb->query( 'ROLLBACK TO SAVEPOINT yume_glossaire' );
	} elseif ( 'transaction' === $mode && $valider ) {
		$wpdb->query( 'COMMIT' );
	} elseif ( 'transaction' === $mode ) {
		$wpdb->query( 'ROLLBACK' );
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

/**
 * Remplace les entrées d'une œuvre (suppression puis insertion par lots).
 *
 * @param int   $oeuvre_id Œuvre.
 * @param array $lignes    Lignes de analyser_glossaire().
 * @return bool Succès.
 */
function remplacer_entrees( int $oeuvre_id, array $lignes ): bool {
	global $wpdb;
	$table = table_entrees();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( false === $wpdb->delete( $table, array( 'oeuvre_id' => $oeuvre_id ), array( '%d' ) ) ) {
		return false;
	}
	foreach ( array_chunk( $lignes, 50, true ) as $lot ) {
		$valeurs = array();
		$params  = array();
		foreach ( $lot as $ordre => $l ) {
			$valeurs[] = '(%d, %s, %d, %s, %s, %s, %s)';
			array_push( $params, $oeuvre_id, $l['categorie'], (int) $ordre, $l['nom'], $l['termes_source'], $l['recherche'], (string) wp_json_encode( $l['donnees'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		$sql = "INSERT INTO {$table} (oeuvre_id, categorie, ordre, nom, termes_source, recherche, donnees) VALUES " . implode( ', ', $valeurs );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $params ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Ne garde que les VERSIONS_CONSERVEES dernières versions d'une œuvre (les brouillons ne
 * comptent pas ; ceux qui ont expiré sont supprimés).
 *
 * @param int $oeuvre_id Œuvre.
 */
function purger_versions( int $oeuvre_id ): void {
	global $wpdb;
	$table = table_versions();
	purger_brouillons();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE oeuvre_id = %d AND source <> %s ORDER BY id DESC", $oeuvre_id, SOURCE_BROUILLON ) ) );
	$vieux = array_slice( $ids, VERSIONS_CONSERVEES );
	if ( $vieux ) {
		$marques = implode( ', ', array_fill( 0, count( $vieux ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($marques)", $vieux ) );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Brouillons (glossaire vérifié, pas encore publié)
 * -----------------------------------------------------------------------------
 */

/**
 * Enregistre un brouillon dans la table des versions (source SOURCE_BROUILLON) : le YAML
 * (jusqu'à 4 Mo) n'a pas sa place dans un transient (limite de 1 Mo de Memcached).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $user_id   Compte.
 * @param string $yaml      Document YAML vérifié.
 * @param string $sha256    Empreinte (analyser_glossaire()).
 * @param int    $entrees   Nombre d'entrées.
 * @param string $note      Note saisie.
 * @return int Identifiant du brouillon (0 : échec).
 */
function enregistrer_brouillon( int $oeuvre_id, int $user_id, string $yaml, string $sha256, int $entrees, string $note ): int {
	global $wpdb;
	purger_brouillons();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->insert(
		table_versions(),
		array(
			'oeuvre_id'  => $oeuvre_id,
			'user_id'    => max( 0, $user_id ),
			'source'     => SOURCE_BROUILLON,
			'cree_le'    => gmdate( 'Y-m-d H:i:s' ),
			'sha256'     => $sha256,
			'nb_entrees' => max( 0, $entrees ),
			'yaml'       => $yaml,
			'note'       => texte( $note, 255 ),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
	);
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Brouillon d'un compte (ligne complète, YAML compris), s'il existe encore, appartient à ce
 * compte, porte cette empreinte et n'a pas expiré ; sinon null.
 *
 * @param int    $brouillon_id Brouillon.
 * @param int    $user_id      Compte.
 * @param string $sha256       Empreinte attendue.
 */
function ligne_brouillon( int $brouillon_id, int $user_id, string $sha256 ): ?array {
	global $wpdb;
	if ( $brouillon_id <= 0 || $user_id <= 0 || '' === $sha256 ) {
		return null;
	}
	$table = table_versions();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND source = %s AND user_id = %d", $brouillon_id, SOURCE_BROUILLON, $user_id ), ARRAY_A );
	if ( ! is_array( $ligne ) || ! hash_equals( (string) $ligne['sha256'], $sha256 ) ) {
		return null;
	}
	$cree = strtotime( (string) $ligne['cree_le'] . ' UTC' );
	return $cree && $cree > time() - DUREE_BROUILLON ? $ligne : null;
}

/**
 * Supprime un brouillon (publication, annulation, nouvelle vérification).
 *
 * @param int $brouillon_id Brouillon.
 */
function supprimer_brouillon( int $brouillon_id ): void {
	global $wpdb;
	if ( $brouillon_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			table_versions(),
			array(
				'id'     => $brouillon_id,
				'source' => SOURCE_BROUILLON,
			),
			array( '%d', '%s' )
		);
	}
}

/**
 * Supprime les brouillons expirés (plus de DUREE_BROUILLON), de toutes les œuvres.
 */
function purger_brouillons(): void {
	global $wpdb;
	$table = table_versions();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = %s AND cree_le < %s", SOURCE_BROUILLON, gmdate( 'Y-m-d H:i:s', time() - DUREE_BROUILLON ) ) );
}

/**
 * Titre brut d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function titre_oeuvre( int $oeuvre_id ): string {
	return trim( wp_strip_all_tags( html_entity_decode( (string) get_the_title( $oeuvre_id ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Adresse de la page publique du glossaire ('' si l'œuvre n'a pas de permalien).
 *
 * @param int $oeuvre_id Œuvre.
 */
function url_glossaire( int $oeuvre_id ): string {
	return function_exists( '\\Yume\\Core\\Core\\url_onglet_oeuvre' ) ? \Yume\Core\Core\url_onglet_oeuvre( $oeuvre_id, 'glossaire' ) : '';
}

/**
 * Bilan commun (réponse de l'API, retour de la vue équipe).
 *
 * @param string     $statut    importe, inchange ou simulation.
 * @param int        $oeuvre_id Œuvre.
 * @param array      $analyse   Résultat de analyser_glossaire().
 * @param array|null $version   Version (version_publique()).
 */
function bilan( string $statut, int $oeuvre_id, array $analyse, ?array $version ): array {
	return array(
		'statut'         => $statut,
		'oeuvre'         => array(
			'id'            => $oeuvre_id,
			'titre'         => titre_oeuvre( $oeuvre_id ),
			'url_glossaire' => url_glossaire( $oeuvre_id ),
		),
		'nb_entrees'     => $analyse['compteurs'],
		'total'          => (int) $analyse['entrees'],
		'anglicismes'    => (int) $analyse['anglicismes'],
		'publiques'      => (int) $analyse['publiques'],
		'avertissements' => array_values( $analyse['avertissements'] ),
		'sha256'         => $analyse['sha256'],
		'version'        => $version,
	);
}

/**
 * Importe un glossaire pour une œuvre : analyse, puis (sauf simulation ou contenu identique à la
 * version courante) remplacement atomique des entrées, nouvelle version, journal de l'équipe et
 * action yume_glossaire_importe.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $yaml      Document YAML.
 * @param array  $args      'user_id' (int), 'source' (api|televersement|restauration), 'note'
 *                          (string), 'simulation' (bool).
 * @return array|\WP_Error Bilan (bilan()).
 */
function importer( int $oeuvre_id, string $yaml, array $args = array() ) {
	global $wpdb;
	$args = wp_parse_args(
		$args,
		array(
			'user_id'    => get_current_user_id(),
			'source'     => 'api',
			'note'       => '',
			'simulation' => false,
		)
	);
	if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return new \WP_Error( 'yume_oeuvre_introuvable', __( 'Œuvre introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	$analyse = analyser_glossaire( $yaml );
	if ( is_wp_error( $analyse ) ) {
		return $analyse;
	}
	$courante = version_courante( $oeuvre_id );
	if ( $args['simulation'] ) {
		$bilan              = bilan( 'simulation', $oeuvre_id, $analyse, $courante );
		$bilan['identique'] = null !== $courante && hash_equals( $courante['sha256'], $analyse['sha256'] );
		return $bilan;
	}
	if ( null !== $courante && hash_equals( $courante['sha256'], $analyse['sha256'] ) ) {
		return bilan( 'inchange', $oeuvre_id, $analyse, $courante );
	}

	$source = in_array( $args['source'], array( 'api', 'televersement', 'restauration' ), true ) ? $args['source'] : 'api';
	$mode   = ouvrir_transaction();
	$ok     = remplacer_entrees( $oeuvre_id, $analyse['lignes'] );
	if ( $ok ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = false !== $wpdb->insert(
			table_versions(),
			array(
				'oeuvre_id'  => $oeuvre_id,
				'user_id'    => max( 0, (int) $args['user_id'] ),
				'source'     => $source,
				'cree_le'    => gmdate( 'Y-m-d H:i:s' ),
				'sha256'     => $analyse['sha256'],
				'nb_entrees' => (int) $analyse['entrees'],
				'yaml'       => $yaml,
				'note'       => texte( $args['note'], 255 ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}
	$version_id = $ok ? (int) $wpdb->insert_id : 0;
	fermer_transaction( $mode, $ok );
	if ( ! $ok || ! $version_id ) {
		return new \WP_Error( 'yume_glossaire_ecriture', __( 'Le glossaire n’a pas pu être enregistré : aucune modification n’a été appliquée.', 'yume-core' ), array( 'status' => 500 ) );
	}
	purger_versions( $oeuvre_id );
	update_post_meta(
		$oeuvre_id,
		META_ETAT,
		array(
			'version'     => $version_id,
			'entrees'     => (int) $analyse['entrees'],
			'publiques'   => (int) $analyse['publiques'],
			'anglicismes' => (int) $analyse['anglicismes'],
			'maj'         => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	wp_cache_delete( 'entrees_' . $oeuvre_id, GROUPE_CACHE );
	journaliser_import( $oeuvre_id, (int) $args['user_id'], (int) $analyse['entrees'] );

	/**
	 * Un glossaire vient d'être importé (nouvelle version courante).
	 *
	 * @param int $oeuvre_id  Œuvre.
	 * @param int $version_id Version créée.
	 */
	do_action( 'yume_glossaire_importe', $oeuvre_id, $version_id );

	return bilan( 'importe', $oeuvre_id, $analyse, version_publique( version( $version_id ) ) );
}

/**
 * Ligne du journal de l'équipe (privée) : « a mis à jour le glossaire de X (N entrées) ».
 *
 * @param int $oeuvre_id Œuvre.
 * @param int $user_id   Auteur.
 * @param int $entrees   Entrées (hors anglicismes).
 */
function journaliser_import( int $oeuvre_id, int $user_id, int $entrees ): void {
	if ( ! function_exists( '\\Yume\\Core\\Planning\\journaliser' ) ) {
		return;
	}
	\Yume\Core\Planning\journaliser(
		0,
		$user_id,
		'glossaire',
		'',
		array(
			'oeuvre'  => $oeuvre_id,
			'titre'   => titre_oeuvre( $oeuvre_id ),
			'entrees' => $entrees,
		),
		false
	);
}

/**
 * Restaure une version : son YAML est réimporté comme nouvelle version (source « restauration »).
 *
 * @param int $version_id Version.
 * @param int $user_id    Auteur.
 * @return array|\WP_Error Bilan.
 */
function restaurer_version( int $version_id, int $user_id ) {
	$version = version( $version_id );
	if ( ! $version ) {
		return new \WP_Error( 'yume_glossaire_version_introuvable', __( 'Version introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	return importer(
		(int) $version['oeuvre_id'],
		(string) $version['yaml'],
		array(
			'user_id' => $user_id,
			'source'  => 'restauration',
			/* translators: %s : date de la version restaurée */
			'note'    => sprintf( __( 'Restauration de la version du %s', 'yume-core' ), date_lisible( (string) $version['cree_le'] ) ),
		)
	);
}

/**
 * Supprime tout le glossaire d'une œuvre (entrées, versions, état).
 *
 * @param int $oeuvre_id Œuvre.
 */
function supprimer_glossaire( int $oeuvre_id ): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( table_entrees(), array( 'oeuvre_id' => $oeuvre_id ), array( '%d' ) );
	$wpdb->delete( table_versions(), array( 'oeuvre_id' => $oeuvre_id ), array( '%d' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	delete_post_meta( $oeuvre_id, META_ETAT );
	wp_cache_delete( 'entrees_' . $oeuvre_id, GROUPE_CACHE );
}

/*
 * -----------------------------------------------------------------------------
 * Lecture
 * -----------------------------------------------------------------------------
 */

/**
 * État du glossaire courant d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array{version:int,entrees:int,publiques:int,anglicismes:int,maj:string}
 */
function etat_glossaire( int $oeuvre_id ): array {
	$etat = $oeuvre_id > 0 ? get_post_meta( $oeuvre_id, META_ETAT, true ) : array();
	$etat = is_array( $etat ) ? $etat : array();
	return array(
		'version'     => (int) ( $etat['version'] ?? 0 ),
		'entrees'     => (int) ( $etat['entrees'] ?? 0 ),
		'publiques'   => (int) ( $etat['publiques'] ?? 0 ),
		'anglicismes' => (int) ( $etat['anglicismes'] ?? 0 ),
		'maj'         => (string) ( $etat['maj'] ?? '' ),
	);
}

/**
 * La page du glossaire existe-t-elle pour le visiteur courant ? Oui si une entrée publique ou un
 * anglicisme existe ; pour l'équipe, dès qu'il y a une entrée.
 *
 * @param int $oeuvre_id Œuvre.
 */
function glossaire_visible( int $oeuvre_id ): bool {
	$etat = etat_glossaire( $oeuvre_id );
	if ( $etat['publiques'] + $etat['anglicismes'] > 0 ) {
		return true;
	}
	return $etat['entrees'] > 0 && current_user_can( 'yume_voir_equipe' );
}

/**
 * Entrées d'une œuvre, dans l'ordre du fichier.
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array{id:int,categorie:string,ordre:int,nom:string,donnees:array}>
 */
function lire_entrees( int $oeuvre_id ): array {
	global $wpdb;
	$cache = wp_cache_get( 'entrees_' . $oeuvre_id, GROUPE_CACHE );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$table  = table_entrees();
	$brutes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT id, categorie, ordre, nom, donnees FROM {$table} WHERE oeuvre_id = %d ORDER BY ordre ASC, id ASC", $oeuvre_id ),
		ARRAY_A
	);
	$entrees = array();
	foreach ( (array) $brutes as $b ) {
		$donnees   = json_decode( (string) $b['donnees'], true );
		$entrees[] = array(
			'id'        => (int) $b['id'],
			'categorie' => (string) $b['categorie'],
			'ordre'     => (int) $b['ordre'],
			'nom'       => (string) $b['nom'],
			'donnees'   => is_array( $donnees ) ? $donnees : array(),
		);
	}
	wp_cache_set( 'entrees_' . $oeuvre_id, $entrees, GROUPE_CACHE, HOUR_IN_SECONDS );
	return $entrees;
}

/**
 * Une version (YAML compris), ou null. Un brouillon n'est jamais une version.
 *
 * @param int $version_id Version.
 */
function version( int $version_id ): ?array {
	global $wpdb;
	$table = table_versions();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND source <> %s", $version_id, SOURCE_BROUILLON ), ARRAY_A );
	return is_array( $ligne ) ? $ligne : null;
}

/**
 * Versions conservées d'une œuvre, de la plus récente à la plus ancienne (sans le YAML ni les
 * brouillons).
 *
 * @param int $oeuvre_id Œuvre.
 * @return array<int,array<string,mixed>>
 */
function versions( int $oeuvre_id ): array {
	global $wpdb;
	$table = table_versions();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT id, oeuvre_id, user_id, source, cree_le, sha256, nb_entrees, note FROM {$table} WHERE oeuvre_id = %d AND source <> %s ORDER BY id DESC", $oeuvre_id, SOURCE_BROUILLON ), ARRAY_A );
	return is_array( $lignes ) ? $lignes : array();
}

/**
 * Version courante d'une œuvre (métadonnées publiques de version_publique()), ou null.
 *
 * @param int $oeuvre_id Œuvre.
 */
function version_courante( int $oeuvre_id ): ?array {
	$etat = etat_glossaire( $oeuvre_id );
	if ( ! $etat['version'] ) {
		return null;
	}
	$version = version( $etat['version'] );
	return $version && (int) $version['oeuvre_id'] === $oeuvre_id ? version_publique( $version ) : null;
}

/**
 * Métadonnées d'une version exposées par l'API (sans le YAML).
 *
 * @param array|null $version Ligne de la table des versions.
 */
function version_publique( ?array $version ): ?array {
	if ( ! $version ) {
		return null;
	}
	$ts = strtotime( (string) $version['cree_le'] . ' UTC' );
	return array(
		'id'         => (int) $version['id'],
		'cree_le'    => $ts ? gmdate( 'c', $ts ) : '',
		'sha256'     => (string) $version['sha256'],
		'nb_entrees' => (int) $version['nb_entrees'],
		'source'     => (string) $version['source'],
		'auteur'     => nom_auteur( (int) $version['user_id'] ),
		'note'       => (string) $version['note'],
	);
}

/**
 * Nom affiché d'un compte (0 : « Système », import de démonstration ou en ligne de commande).
 *
 * @param int $user_id Compte.
 */
function nom_auteur( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return __( 'Système', 'yume-core' );
	}
	$user = get_userdata( $user_id );
	return $user ? (string) $user->display_name : __( 'Compte supprimé', 'yume-core' );
}

/**
 * Date GMT « Y-m-d H:i:s » en date lisible à l'heure du site (« 28 sept. 2026 à 14:05 »).
 *
 * @param string $gmt Date GMT.
 */
function date_lisible( string $gmt ): string {
	$ts = strtotime( $gmt . ' UTC' );
	return $ts ? wp_date( 'j M Y \à H:i', $ts ) : '';
}

/**
 * Œuvre désignée par un ID ou un slug (hors corbeille), ou 0.
 *
 * @param string $valeur ID ou slug.
 */
function oeuvre_depuis( string $valeur ): int {
	$valeur = trim( $valeur );
	if ( '' === $valeur ) {
		return 0;
	}
	if ( ctype_digit( $valeur ) ) {
		$id = (int) $valeur;
		return 'yume_oeuvre' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ? $id : 0;
	}
	$ids = get_posts(
		array(
			'post_type'        => 'yume_oeuvre',
			'name'             => sanitize_title( $valeur ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Lit un fichier YAML téléversé (entrée de $_FILES) : erreur d'envoi, extension .yaml ou .yml,
 * taille, fichier réellement téléversé, texte (pas d'octet nul, type détecté textuel).
 *
 * @param array $fichier Entrée de $_FILES (name, tmp_name, error, size).
 * @return string|\WP_Error Contenu.
 */
function lire_fichier_televerse( array $fichier ) {
	$erreur = (int) ( $fichier['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_NO_FILE === $erreur ) {
		return new \WP_Error( 'yume_glossaire_absent', __( 'Choisissez un fichier .yaml.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( in_array( $erreur, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		return new \WP_Error( 'yume_glossaire_trop_gros', __( 'Fichier trop volumineux : 4 Mo au plus.', 'yume-core' ), array( 'status' => 413 ) );
	}
	if ( UPLOAD_ERR_OK !== $erreur ) {
		return new \WP_Error( 'yume_glossaire_televersement', __( 'Le fichier n’a pas pu être reçu. Réessayez.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$nom    = (string) ( $fichier['name'] ?? '' );
	$chemin = (string) ( $fichier['tmp_name'] ?? '' );
	if ( ! in_array( strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) ), array( 'yaml', 'yml' ), true ) ) {
		return new \WP_Error( 'yume_glossaire_type', __( 'Type de fichier refusé : un fichier .yaml ou .yml est attendu.', 'yume-core' ), array( 'status' => 415 ) );
	}
	/**
	 * Accepte un fichier local qui n'a pas été téléversé par PHP (tests, import en ligne de commande).
	 *
	 * @param bool   $accepte Accepté.
	 * @param string $chemin  Chemin.
	 */
	$local = (bool) apply_filters( 'yume_glossaire_fichier_local', false, $chemin );
	if ( '' === $chemin || ! is_readable( $chemin ) || ( ! is_uploaded_file( $chemin ) && ! $local ) ) {
		return new \WP_Error( 'yume_glossaire_televersement', __( 'Le fichier n’a pas pu être reçu. Réessayez.', 'yume-core' ), array( 'status' => 400 ) );
	}
	$taille = (int) filesize( $chemin );
	if ( $taille > Lecteur_Yaml::TAILLE_MAX ) {
		return new \WP_Error( 'yume_glossaire_trop_gros', __( 'Fichier trop volumineux : 4 Mo au plus.', 'yume-core' ), array( 'status' => 413 ) );
	}
	$contenu = (string) file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$type    = '';
	if ( function_exists( 'finfo_open' ) && '' !== $contenu ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$type  = $finfo ? (string) finfo_file( $finfo, $chemin ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}
	}
	if ( str_contains( $contenu, "\0" ) || ( '' !== $type && ! str_starts_with( $type, 'text/' ) && ! str_contains( $type, 'yaml' ) ) ) {
		return new \WP_Error( 'yume_glossaire_type', __( 'Type de fichier refusé : ce fichier n’est pas un texte YAML.', 'yume-core' ), array( 'status' => 415 ) );
	}
	return $contenu;
}
