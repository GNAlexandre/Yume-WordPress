<?php
/**
 * Tiret des dialogues : au rendu d'un paragraphe « yn-dialogue », le tiret cadratin de tête est
 * placé dans une boîte de largeur fixe suspendue à gauche (.yn-tiret). La première lettre de la
 * réplique s'aligne ainsi exactement sur la première lettre des pensées, quelle que soit la
 * police choisie par le lecteur (la largeur du tiret varie d'une police à l'autre). S'applique
 * aussi aux chapitres déjà publiés, sans réimport.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Isole le tiret de tête d'un paragraphe de dialogue.
 *
 * @param string $html Rendu du bloc paragraphe.
 * @return string
 */
function isoler_tiret_dialogue( string $html ): string {
	if ( ! str_contains( $html, 'yn-dialogue' ) || str_contains( $html, 'yn-tiret' ) ) {
		return $html;
	}
	// <p … class="… yn-dialogue …">, éventuelles balises ouvrantes en ligne, tiret, espaces.
	$motif = '/^(\s*<p\b[^>]*\bclass="[^"]*\byn-dialogue\b[^"]*"[^>]*>)((?:\s*<(?:em|i|strong|b|span)\b[^>]*>)*)\s*(?:—|&mdash;|&#8212;|&#x2014;)(?:\x{00A0}|\x{202F}|&nbsp;|&#160;|&#xA0;|\s)*/u';
	$rendu = preg_replace_callback(
		$motif,
		static function ( array $m ): string {
			$ouverture = (string) preg_replace( '/\bclass="/', 'class="yn-dialogue--tiret ', $m[1], 1 );
			return $ouverture . '<span class="yn-tiret">—</span>' . $m[2];
		},
		$html,
		1
	);
	return is_string( $rendu ) ? $rendu : $html;
}

/**
 * Filtre render_block des paragraphes.
 *
 * @param string $html  Rendu.
 * @param array  $block Bloc.
 * @return string
 */
function filtre_paragraphe_dialogue( $html, $block ) {
	if ( ! is_string( $html ) || is_admin() || ! is_array( $block ) ) {
		return $html;
	}
	return isoler_tiret_dialogue( $html );
}
add_filter( 'render_block_core/paragraph', __NAMESPACE__ . '\\filtre_paragraphe_dialogue', 10, 2 );
