<?php
/**
 * Redirections 301 des anciennes URL (table en option : ancien chemin relatif → nouvelle URL
 * relative), servies sur template_redirect avant la redirection canonique et le modèle 404,
 * et export CSV au format d'import de l'extension Redirection (source,target,regex,code).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Table et gestionnaire des redirections.
 */
final class Redirections {

	/** Option : chemin source normalisé => cible relative (chargée automatiquement). */
	public const OPTION = 'yume_redirections';

	/**
	 * Table des redirections, éventuellement complétée par le filtre yume_redirections.
	 *
	 * @return array<string,string>
	 */
	public static function table(): array {
		$table = get_option( self::OPTION, array() );
		$table = is_array( $table ) ? $table : array();
		/**
		 * Filtre la table des redirections 301 (chemin source normalisé => cible relative ou absolue).
		 *
		 * @param array<string,string> $table Table.
		 */
		$table = apply_filters( 'yume_redirections', $table );
		return is_array( $table ) ? $table : array();
	}

	/**
	 * Chemin du site (sans domaine) de home_url(), sans barre finale (« » ou « /blog »).
	 */
	private static function base(): string {
		return untrailingslashit( (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * Normalise un chemin (ou une URL) : chemin seul, relatif à l'accueil du site, décodé, en
	 * minuscules, barres doublées réduites, barre initiale et finale.
	 *
	 * @param string $chemin Chemin ou URL.
	 */
	public static function normaliser( string $chemin ): string {
		$chemin = (string) wp_parse_url( $chemin, PHP_URL_PATH );
		$chemin = rawurldecode( $chemin );
		$base   = self::base();
		if ( '' !== $base && ( $chemin === $base || str_starts_with( $chemin, $base . '/' ) ) ) {
			$chemin = substr( $chemin, strlen( $base ) );
		}
		$chemin = '/' . trim( $chemin, '/' );
		$chemin = function_exists( 'mb_strtolower' ) ? mb_strtolower( $chemin, 'UTF-8' ) : strtolower( $chemin );
		$chemin = (string) preg_replace( '#/+#', '/', $chemin );
		return '/' === $chemin ? '/' : $chemin . '/';
	}

	/**
	 * Ajoute des redirections (les sources déjà présentes sont remplacées).
	 *
	 * @param array<string,string> $entrees Chemin source => cible relative.
	 * @return string[] Sources normalisées écrites.
	 */
	public static function ajouter( array $entrees ): array {
		$table  = get_option( self::OPTION, array() );
		$table  = is_array( $table ) ? $table : array();
		$ecrits = array();
		foreach ( $entrees as $source => $cible ) {
			$source = self::normaliser( (string) $source );
			$cible  = trim( (string) $cible );
			if ( '/' === $source || '' === $cible || self::normaliser( $cible ) === $source ) {
				continue;
			}
			$table[ $source ] = $cible;
			$ecrits[]         = $source;
		}
		ksort( $table );
		update_option( self::OPTION, $table, true );
		return $ecrits;
	}

	/**
	 * Retire des redirections.
	 *
	 * @param string[] $sources Chemins source.
	 */
	public static function retirer( array $sources ): void {
		$table = get_option( self::OPTION, array() );
		if ( ! is_array( $table ) ) {
			return;
		}
		foreach ( $sources as $source ) {
			unset( $table[ self::normaliser( (string) $source ) ] );
		}
		update_option( self::OPTION, $table, true );
	}

	/**
	 * URL absolue d'une cible relative (« /oeuvres/x/ ») ou déjà absolue.
	 *
	 * @param string $cible Cible.
	 */
	public static function url( string $cible ): string {
		if ( preg_match( '#^https?://#i', $cible ) ) {
			return $cible;
		}
		return home_url( '/' . ltrim( $cible, '/' ) );
	}

	/**
	 * Cible d'une URL demandée, ou null si elle n'est pas redirigée. Une URL de pagination ou
	 * de flux d'une source (« …/page/2/ », « …/feed/ ») suit la même redirection.
	 *
	 * @param string $uri     URI demandée (REQUEST_URI).
	 * @param string $requete Chaîne de requête à conserver si la cible n'en a pas.
	 */
	public static function resoudre( string $uri, string $requete = '' ): ?string {
		$table = self::table();
		if ( ! $table ) {
			return null;
		}
		$chemin  = self::normaliser( $uri );
		$suffixe = '';
		$cible   = $table[ $chemin ] ?? null;
		if ( null === $cible && preg_match( '#^(/.+?/)((?:feed|amp)/|page/\d+/)$#', $chemin, $m ) && isset( $table[ $m[1] ] ) ) {
			$cible   = $table[ $m[1] ];
			$suffixe = $m[2];
		}
		if ( null === $cible || '' === (string) $cible ) {
			return null;
		}
		$cible = (string) $cible;
		if ( '' !== $suffixe && ! str_contains( $cible, '?' ) ) {
			$cible = trailingslashit( $cible ) . $suffixe;
		}
		$url = self::url( $cible );
		if ( '' !== $requete && ! str_contains( $url, '?' ) ) {
			$url .= '?' . $requete;
		}
		return $url;
	}

	/**
	 * Redirige une ancienne URL en 301 (template_redirect, priorité 1 : avant la redirection
	 * canonique du cœur et le modèle 404).
	 */
	public static function gerer(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$methode = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $methode, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$requete = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cible   = self::resoudre( $uri, $requete );
		if ( null === $cible ) {
			return;
		}
		if ( wp_safe_redirect( $cible, 301, 'Yume Novel' ) ) {
			exit;
		}
	}

	/**
	 * Table au format CSV de l'extension Redirection (import : Outils → Redirection →
	 * Importer/Exporter, format CSV).
	 *
	 * @param array<string,string> $table Chemin source => cible.
	 */
	public static function csv( array $table ): string {
		$csv = "source,target,regex,code\n";
		foreach ( $table as $source => $cible ) {
			$csv .= sprintf( "\"%s\",\"%s\",0,301\n", str_replace( '"', '""', (string) $source ), str_replace( '"', '""', (string) $cible ) );
		}
		return $csv;
	}
}
