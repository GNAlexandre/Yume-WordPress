<?php
/**
 * API publique du module mise-en-page (contrat §7).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adresse de la vue « Mettre en page » d'un chapitre (/equipe/?vue=chapitre&id=ID), ou '' si le
 * contenu n'est pas un chapitre.
 *
 * @param int $chapitre_id Chapitre.
 */
function yume_mise_en_page_url( int $chapitre_id ): string {
	if ( $chapitre_id <= 0 || 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return '';
	}
	return \Yume\Core\MiseEnPage\url_vue( $chapitre_id );
}

/**
 * Le compte peut-il mettre en page ce chapitre (yume_publier et edit_post, filtre
 * yume_mise_en_page_peut) ?
 *
 * @param int $chapitre_id Chapitre.
 * @param int $user_id     Compte (0 : compte courant).
 */
function yume_mise_en_page_peut( int $chapitre_id, int $user_id = 0 ): bool {
	return \Yume\Core\MiseEnPage\peut( $chapitre_id, $user_id );
}
