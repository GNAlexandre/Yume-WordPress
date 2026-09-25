<?php
/**
 * État persistant de la migration (options WordPress, jamais chargées automatiquement) :
 *
 * - yume_migration_etat    : statut (non_migre, en_cours, migre, annulation, annule),
 *                            opération et étape courantes, curseur, progression, dates,
 *                            auteur, comptes, erreurs, messages et historique ;
 * - yume_migration_journal : correspondances source → cible, médias, sauvegardes prises au
 *                            début de l'exécution (options, pages, articles, catégories),
 *                            empreinte des contenus touchés, redirections ajoutées ;
 * - yume_migration_plan    : dernier plan calculé (simulation ou exécution), compressé ;
 * - yume_migration_choix   : statuts d'œuvre choisis par l'équipe à la simulation ;
 * - yume_migration_verrou  : verrou d'exécution (une seule exécution par lots à la fois).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Accès à l'état, au journal et au plan de la migration.
 */
final class Migration_State {

	public const OPTION_ETAT    = 'yume_migration_etat';
	public const OPTION_JOURNAL = 'yume_migration_journal';
	public const OPTION_PLAN    = 'yume_migration_plan';
	public const OPTION_CHOIX   = 'yume_migration_choix';
	public const OPTION_VERROU  = 'yume_migration_verrou';

	/** Statuts possibles et libellés. */
	public const STATUTS = array(
		'non_migre'  => 'Non migré',
		'en_cours'   => 'Migration en cours',
		'migre'      => 'Migré',
		'annulation' => 'Annulation en cours',
		'annule'     => 'Migration annulée',
	);

	/** Durée (s) au-delà de laquelle un verrou est considéré comme abandonné. */
	public const VERROU_DUREE = 180;

	/** Nombre maximal de messages et d'entrées d'historique conservés. */
	public const MAX_MESSAGES = 300;

	/**
	 * État par défaut.
	 *
	 * @return array<string,mixed>
	 */
	public static function etat_defaut(): array {
		return array(
			'statut'      => 'non_migre',
			'operation'   => '',
			'run'         => '',
			'etape'       => '',
			'curseur'     => 0,
			'etapes'      => array(),
			'progression' => array(
				'fait'  => 0,
				'total' => 0,
			),
			'debut'       => '',
			'fin'         => '',
			'par'         => 0,
			'migre_le'    => '',
			'migre_par'   => 0,
			'annule_le'   => '',
			'annule_par'  => 0,
			'erreur'      => '',
			'comptes'     => array(),
			'messages'    => array(),
			'historique'  => array(),
			'controle'    => array(),
			'plan'        => array(),
		);
	}

	/**
	 * État courant (complété des valeurs par défaut).
	 *
	 * @return array<string,mixed>
	 */
	public static function etat(): array {
		$etat = get_option( self::OPTION_ETAT, array() );
		$etat = is_array( $etat ) ? $etat : array();
		$etat = array_merge( self::etat_defaut(), $etat );
		if ( ! isset( self::STATUTS[ $etat['statut'] ] ) ) {
			$etat['statut'] = 'non_migre';
		}
		return $etat;
	}

	/**
	 * Enregistre l'état.
	 *
	 * @param array $etat État.
	 */
	public static function enregistrer_etat( array $etat ): void {
		$etat['messages']   = array_slice( (array) $etat['messages'], -self::MAX_MESSAGES );
		$etat['historique'] = array_slice( (array) $etat['historique'], -self::MAX_MESSAGES );
		update_option( self::OPTION_ETAT, $etat, false );
	}

	/**
	 * Journal par défaut.
	 *
	 * @return array<string,mixed>
	 */
	public static function journal_defaut(): array {
		return array(
			'run'               => '',
			'correspondances'   => array(
				'oeuvre'   => array(),
				'tome'     => array(),
				'chapitre' => array(),
				'page'     => array(),
			),
			'sources'           => array(),
			'medias'            => array(),
			'categories_cibles' => array(),
			'sauvegarde'        => array(),
			'modifications'     => array(
				'pages_depubliees'      => array(),
				'pages_adoptees'        => array(),
				'articles'              => array(),
				'categories_modifiees'  => array(),
				'categories_creees'     => array(),
				'categories_supprimees' => array(),
				'options'               => array(),
				'redirections'          => array(),
			),
			'empreinte'         => array(),
		);
	}

	/**
	 * Journal courant.
	 *
	 * @return array<string,mixed>
	 */
	public static function journal(): array {
		$journal = get_option( self::OPTION_JOURNAL, array() );
		$journal = is_array( $journal ) ? $journal : array();
		$defaut  = self::journal_defaut();
		$journal = array_merge( $defaut, $journal );
		foreach ( array( 'correspondances', 'modifications' ) as $cle ) {
			$journal[ $cle ] = array_merge( $defaut[ $cle ], is_array( $journal[ $cle ] ) ? $journal[ $cle ] : array() );
		}
		return $journal;
	}

	/**
	 * Enregistre le journal.
	 *
	 * @param array $journal Journal.
	 */
	public static function enregistrer_journal( array $journal ): void {
		update_option( self::OPTION_JOURNAL, $journal, false );
	}

	/**
	 * Signature d'un plan : empreinte de son contenu, hors dates de génération.
	 *
	 * @param array $plan Plan.
	 */
	public static function signature( array $plan ): string {
		unset( $plan['genere_le'], $plan['source']['exporte_le'] );
		foreach ( (array) ( $plan['chapitres'] ?? array() ) as $i => $chapitre ) {
			if ( isset( $chapitre['meta']['yume_source']['importe_le'] ) ) {
				$plan['chapitres'][ $i ]['meta']['yume_source']['importe_le'] = '';
			}
		}
		return sha1( (string) wp_json_encode( $plan ) );
	}

	/**
	 * Enregistre un plan (compressé si possible) avec son origine.
	 *
	 * @param array  $plan    Plan.
	 * @param string $origine 'simulation' ou 'execution'.
	 * @return array<string,mixed> Métadonnées enregistrées (sans le plan).
	 */
	public static function enregistrer_plan( array $plan, string $origine ): array {
		$json = (string) wp_json_encode( $plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$data = function_exists( 'gzcompress' ) ? 'gz:' . base64_encode( (string) gzcompress( $json, 6 ) ) : 'json:' . $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$meta = array(
			'origine'   => $origine,
			'date'      => current_time( 'mysql', true ),
			'par'       => get_current_user_id(),
			'signature' => self::signature( $plan ),
			'comptes'   => $plan['comptes'] ?? array(),
		);
		update_option(
			self::OPTION_PLAN,
			array_merge( $meta, array( 'donnees' => $data ) ),
			false
		);
		return $meta;
	}

	/**
	 * Métadonnées du plan enregistré (origine, date, auteur, signature, comptes), ou null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function meta_plan(): ?array {
		$option = get_option( self::OPTION_PLAN );
		if ( ! is_array( $option ) || empty( $option['donnees'] ) ) {
			return null;
		}
		unset( $option['donnees'] );
		return $option;
	}

	/**
	 * Plan enregistré, ou null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function plan(): ?array {
		static $cache = array();
		$option       = get_option( self::OPTION_PLAN );
		if ( ! is_array( $option ) || empty( $option['donnees'] ) || ! is_string( $option['donnees'] ) ) {
			return null;
		}
		$cle = md5( $option['donnees'] );
		if ( isset( $cache[ $cle ] ) ) {
			return $cache[ $cle ];
		}
		$donnees = $option['donnees'];
		$json    = '';
		if ( str_starts_with( $donnees, 'gz:' ) && function_exists( 'gzuncompress' ) ) {
			$json = (string) gzuncompress( (string) base64_decode( substr( $donnees, 3 ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		} elseif ( str_starts_with( $donnees, 'json:' ) ) {
			$json = substr( $donnees, 5 );
		}
		$plan = json_decode( $json, true );
		if ( ! is_array( $plan ) || empty( $plan['version'] ) ) {
			return null;
		}
		$cache = array( $cle => $plan );
		return $plan;
	}

	/**
	 * Statuts d'œuvre choisis par l'équipe (clé d'œuvre => slug yume_statut).
	 *
	 * @return array<string,string>
	 */
	public static function choix(): array {
		$choix = get_option( self::OPTION_CHOIX, array() );
		return is_array( $choix ) ? array_map( 'strval', $choix ) : array();
	}

	/**
	 * Enregistre les statuts choisis.
	 *
	 * @param array<string,string> $choix Clé d'œuvre => statut.
	 */
	public static function enregistrer_choix( array $choix ): void {
		update_option( self::OPTION_CHOIX, $choix, false );
	}

	/**
	 * Prend le verrou d'exécution (insertion atomique : deux requêtes simultanées ne peuvent
	 * pas l'obtenir toutes les deux, contrairement à add_option() et son ON DUPLICATE KEY).
	 *
	 * @return string Jeton du verrou, ou chaîne vide s'il est déjà pris.
	 */
	public static function verrouiller(): string {
		global $wpdb;
		$jeton  = wp_generate_password( 12, false );
		$valeur = time() . '|' . $jeton;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$insere = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION_VERROU, $valeur ) );
		self::oublier_verrou();
		if ( 1 === (int) $insere ) {
			return $jeton;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$actuel = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_VERROU ) );
		$depuis = (int) strtok( $actuel, '|' );
		if ( $depuis && time() - $depuis > self::VERROU_DUREE ) {
			// Verrou abandonné (délai d'exécution dépassé, requête interrompue) : repris seulement
			// s'il n'a pas changé entre-temps.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$repris = $wpdb->update(
				$wpdb->options,
				array( 'option_value' => $valeur ),
				array(
					'option_name'  => self::OPTION_VERROU,
					'option_value' => $actuel,
				)
			);
			self::oublier_verrou();
			if ( 1 === (int) $repris ) {
				return $jeton;
			}
		}
		return '';
	}

	/**
	 * Vide le cache de l'option du verrou (lue et écrite hors de l'API des options).
	 */
	private static function oublier_verrou(): void {
		wp_cache_delete( self::OPTION_VERROU, 'options' );
		$absentes = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $absentes ) && isset( $absentes[ self::OPTION_VERROU ] ) ) {
			unset( $absentes[ self::OPTION_VERROU ] );
			wp_cache_set( 'notoptions', $absentes, 'options' );
		}
	}

	/**
	 * Rend le verrou.
	 *
	 * @param string $jeton Jeton obtenu par verrouiller().
	 */
	public static function deverrouiller( string $jeton ): void {
		global $wpdb;
		if ( '' === $jeton ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::OPTION_VERROU, '%|' . $wpdb->esc_like( $jeton ) ) );
		self::oublier_verrou();
	}

	/**
	 * Ajoute un message à l'état (journal lisible de l'exécution).
	 *
	 * @param array  $etat  État (par référence).
	 * @param string $texte Message.
	 * @param string $type  info, succes, avertissement ou erreur.
	 */
	public static function message( array &$etat, string $texte, string $type = 'info' ): void {
		$etat['messages'][] = array(
			'date'  => current_time( 'mysql', true ),
			'type'  => $type,
			'texte' => $texte,
		);
	}
}
