<?php
/**
 * API PHP publique du module lecteurs (§7 du contrat). Signatures figées : toute évolution
 * passe d'abord par docs/06-contrat-technique.md.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Positions de lecture d'un membre (table progression du module lecture).
 *
 * Ligne : ['oeuvre_id', 'chapitre_id', 'tome_id', 'paragraphe', 'pourcentage', 'updated_at']
 * (entiers ; updated_at en « Y-m-d H:i:s » GMT). Avec $oeuvre_id = 0 : toutes les œuvres, de la
 * plus récente à la plus ancienne ; sinon au plus une ligne.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre (0 = toutes).
 * @return array<int,array{oeuvre_id:int,chapitre_id:int,tome_id:int,paragraphe:int,pourcentage:int,updated_at:string}>
 */
function yume_get_progression( int $user_id, int $oeuvre_id = 0 ): array {
	if ( ! function_exists( '\Yume\Core\Reader\lignes_progression' ) ) {
		return array();
	}
	return \Yume\Core\Reader\lignes_progression( $user_id, $oeuvre_id );
}

/**
 * L'œuvre est-elle dans les favoris du membre ?
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function yume_is_favori( int $user_id, int $oeuvre_id ): bool {
	return \Yume\Core\Social\est_favori( $user_id, $oeuvre_id );
}

/**
 * Abonnés d'une œuvre (membres l'ayant en favori) pour une fréquence d'alerte.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $frequence 'immediat' (défaut), 'hebdo' ou 'jamais'.
 * @return int[] Identifiants d'utilisateurs.
 */
function yume_get_abonnes( int $oeuvre_id, string $frequence = 'immediat' ): array {
	return \Yume\Core\Social\abonnes( $oeuvre_id, $frequence );
}

/**
 * Note (1 à 5) donnée par un membre à une œuvre ; 0 s'il n'a pas noté.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function yume_get_note( int $user_id, int $oeuvre_id ): int {
	return \Yume\Core\Social\note( $user_id, $oeuvre_id );
}
