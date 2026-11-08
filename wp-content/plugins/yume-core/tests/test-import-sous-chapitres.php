<?php
/**
 * Sous-chapitres à l'import (lot B, 2.8.0) : DOCX SYNTHÉTIQUE à la structure du tome 2 de
 * Miss Medic's Diary at War (chapitres au style Titre 1, dont certains sans texte propre,
 * parties datées au style Titre 2), fabriqué avec ZipArchive (tools/fixtures/build-fixtures.php) :
 * - détection automatique inchangée (un Titre 2 reste un intertitre ou un sous-titre) ;
 * - découpages rapides « titres » et « sous_chapitres » (bouton « Couper aux Titre 2 ») : le
 *   Titre 2 qui suit directement un Titre 1 a lui aussi un repère ;
 * - découpage manuel avec la nature « sous-chapitre » : numéro du chapitre parent, rang de la
 *   partie, titre du parent, parent sans texte retiré (ses illustrations passent à la première
 *   partie), sous-chapitre sans parent traité comme un chapitre (avertissement) ;
 * - marqueur « [sous-chapitre] Titre » dans le document.
 *
 * Lancement : tools/localenv/test.sh import-sous-chapitres
 *
 * @package Yume\Core
 */

use Yume\Core\Import\Chapter_Builder;
use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Result;
use Yume\Core\Import\Texte;

defined( 'ABSPATH' ) || exit;

require_once YUME_CORE_DIR . 'includes/import/autoload.php';

if ( ! function_exists( 'yume_timp_docx' ) ) {
	// Fonctions d'aide du module import (fixtures) sans ses tests.
	$GLOBALS['yume_tests_import_aides_seules'] = true;
	require __DIR__ . '/test-import.php';
	unset( $GLOBALS['yume_tests_import_aides_seules'] );
}

if ( ! function_exists( 'yume_tisc_docx' ) ) {
	/**
	 * DOCX synthétique à la structure de Miss Medic (texte neutre) : prologue contenant un Titre 2,
	 * chapitre avec texte, chapitre sans texte (une illustration) et ses deux parties, chapitre
	 * avec texte, chapitre sans texte suivi directement de son unique partie (cas réel : le
	 * Titre 2 y est lu comme sous-titre par la détection automatique), chapitre avec un texte
	 * d'introduction et ses deux parties, postface.
	 *
	 * @return string Chemin du fichier (à supprimer par l'appelant).
	 */
	function yume_tisc_docx(): string {
		yume_timp_outils_fixtures();
		$t1    = static fn( string $t ): string => yume_fx_t( $t, array( 'style' => 'Titre1' ) );
		$t2    = static fn( string $t ): string => yume_fx_t( $t, array( 'style' => 'Titre2' ) );
		$p     = static fn( string $t ): string => yume_fx_t( $t );
		$corps = $t1( 'Prologue' ) . $p( 'Texte du prologue, première ligne.' )
			. $t2( 'Souvenir lointain' ) . $p( 'Texte du souvenir.' )
			. $t1( 'Année 1, été 1' ) . $p( 'Premier paragraphe de l’été.' ) . $p( 'Second paragraphe de l’été.' )
			. $t1( 'Bataille de la colline' ) . yume_fx_p( yume_fx_dessin( yume_fx_rid( 'carte.png' ), 'Carte de la colline' ) )
			. $t2( 'Premier jour, soir' ) . $p( 'Le soir tombe sur la colline.' )
			. $t2( 'Premier jour, nuit' ) . $p( 'La nuit est longue.' ) . $p( 'Personne ne dort.' )
			. $t1( 'Année 1, été 2' ) . $p( 'Un été plus calme.' )
			. $t1( 'Défense du pont' ) . $t2( 'Quatrième jour' ) . $p( 'Le pont tient bon.' )
			. $t1( 'Évacuation du village' ) . $p( 'Introduction de l’évacuation.' )
			. $t2( 'Cinquième jour' ) . $p( 'Les charrettes partent.' )
			. $t2( 'Sixième jour' ) . $p( 'Le village est vide.' )
			. $t1( 'Postface' ) . $p( 'Merci de votre lecture.' );
		return yume_timp_docx( $corps, array( 'carte.png' => yume_fx_image( 'png', 600, 800 ) ) );
	}

	/**
	 * Découpage « Couper aux Titre 2 (sous-chapitres) », comme le formulaire : titres de niveau 1
	 * → leur nature (chapitre, prologue, postface…), Titre 2 → sous-chapitre, titres vides (ceux
	 * du fichier sont repris).
	 *
	 * @param Result     $r       Analyse du fichier.
	 * @param float|null $premier Premier numéro de chapitre (posé, comme le formulaire, sur le
	 *                            premier chapitre ou sous-chapitre).
	 * @return array<string,mixed>
	 */
	function yume_tisc_plan_titre2( Result $r, ?float $premier = null ): array {
		$debuts = array();
		$pose   = false;
		foreach ( $r->candidats as $c ) {
			$t1 = in_array( $c['ancre'], $r->decoupages['titres'], true );
			$t2 = in_array( $c['ancre'], $r->decoupages['sous_chapitres'], true );
			if ( ! $t1 && ! $t2 ) {
				continue;
			}
			$debut = array(
				'ancre'  => $c['ancre'],
				'nature' => $t2 ? 'sous-chapitre' : ( 'sous-chapitre' === $c['nature'] ? 'chapitre' : $c['nature'] ),
				'titre'  => '',
			);
			if ( null !== $premier && ! $pose && in_array( $debut['nature'], array( 'chapitre', 'sous-chapitre' ), true ) ) {
				$debut['numero'] = $premier;
				$pose            = true;
			}
			$debuts[] = $debut;
		}
		return array( 'debuts' => $debuts );
	}

	/**
	 * Résumé des chapitres : [nature, numéro, rang de partie, titre, sous-titre, parent].
	 *
	 * @param Result $r Résultat.
	 * @return array<int,array<int,mixed>>
	 */
	function yume_tisc_resume( Result $r ): array {
		return array_map(
			static fn( $c ) => array( $c['nature'], $c['numero'], $c['sous_numero'], $c['titre'], $c['sous_titre'], $c['chapitre_parent'] ),
			$r->chapters
		);
	}
}

// Inclusion par test-publication-sous-chapitres.php pour ses seules fonctions d'aide.
if ( ! empty( $GLOBALS['yume_tests_sous_chapitres_aides_seules'] ) ) {
	return;
}

yume_test(
	'sous-chapitres : détection automatique inchangée, Titre 2 relevés pour « Couper aux Titre 2 »',
	function () {
		$fichier = yume_tisc_docx();
		try {
			$r = Docx_Converter::convert_file( $fichier );
			yume_assert_same( array(), array_values( array_filter( array_column( $r->chapters, 'nature' ), static fn( $n ) => 'sous-chapitre' === $n ) ), 'jamais de sous-chapitre détecté' );
			yume_assert_same( array( 'prologue', 'chapitre', 'chapitre', 'chapitre', 'chapitre', 'chapitre', 'postface' ), array_column( $r->chapters, 'nature' ) );
			// Titre 2 juste après un Titre 1 : sous-titre (comme avant), ailleurs : intertitre.
			yume_assert_same( '', $r->chapters[0]['sous_titre'] );
			yume_assert_contains( 'Souvenir lointain</h2>', $r->chapters[0]['blocks'] );
			yume_assert_same( 'Bataille de la colline', $r->chapters[2]['sous_titre'] );
			yume_assert_contains( 'Premier jour, soir</h2>', $r->chapters[2]['blocks'] );
			yume_assert_contains( 'Premier jour, nuit</h2>', $r->chapters[2]['blocks'] );
			yume_assert_same( 'Quatrième jour', $r->chapters[4]['sous_titre'] );
			yume_assert_same( array( null ), array_values( array_unique( array_column( $r->chapters, 'sous_numero' ) ) ) );
			// Découpages rapides : sept titres de niveau 1, six Titre 2 (sous-titre compris).
			$extrait = static function ( array $ancres ) use ( $r ): array {
				$par_ancre = array_column( $r->candidats, 'extrait', 'ancre' );
				return array_map( static fn( $a ) => $par_ancre[ $a ], $ancres );
			};
			yume_assert_same( array( 'Prologue', 'Année 1, été 1', 'Bataille de la colline', 'Année 1, été 2', 'Défense du pont', 'Évacuation du village', 'Postface' ), $extrait( $r->decoupages['titres'] ) );
			yume_assert_same( array( 'Souvenir lointain', 'Premier jour, soir', 'Premier jour, nuit', 'Quatrième jour', 'Cinquième jour', 'Sixième jour' ), $extrait( $r->decoupages['sous_chapitres'] ) );
			// Un fichier sans Titre 2 : découpages vides (le bouton est masqué).
			$sans = Docx_Converter::convert_file( yume_timp_fixture( 'regles.docx' ) );
			yume_assert_true( count( $sans->decoupages['sous_chapitres'] ) > 0, 'regles.docx a des Titre 2' );
			$plat = yume_timp_docx( yume_fx_t( 'Chapitre 1', array( 'style' => 'Titre1' ) ) . yume_fx_t( 'Texte.' ) );
			try {
				$vide = Docx_Converter::convert_file( $plat );
				yume_assert_same( array(), $vide->decoupages['titres'] );
				yume_assert_same( array(), $vide->decoupages['sous_chapitres'] );
			} finally {
				wp_delete_file( $plat );
			}
		} finally {
			wp_delete_file( $fichier );
		}
	}
);

yume_test(
	'sous-chapitres : « Couper aux Titre 2 » — parent sans texte absorbé, parent avec texte gardé, sous-chapitre sans parent',
	function () {
		$fichier = yume_tisc_docx();
		try {
			$auto = Docx_Converter::convert_file( $fichier );
			$plan = yume_tisc_plan_titre2( $auto );
			$r    = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same(
				array(
					array( 'prologue', 0.0, null, 'Prologue', '', '' ),
					array( 'chapitre', 1.0, null, 'Chapitre 1', 'Souvenir lointain', '' ),
					array( 'chapitre', 2.0, null, 'Chapitre 2', 'Année 1, été 1', '' ),
					array( 'sous-chapitre', 3.0, 1, 'Chapitre 3, partie 1', 'Premier jour, soir', 'Bataille de la colline' ),
					array( 'sous-chapitre', 3.0, 2, 'Chapitre 3, partie 2', 'Premier jour, nuit', 'Bataille de la colline' ),
					array( 'chapitre', 4.0, null, 'Chapitre 4', 'Année 1, été 2', '' ),
					array( 'sous-chapitre', 5.0, 1, 'Chapitre 5, partie 1', 'Quatrième jour', 'Défense du pont' ),
					array( 'chapitre', 6.0, null, 'Chapitre 6', 'Évacuation du village', '' ),
					array( 'sous-chapitre', 6.0, 1, 'Chapitre 6, partie 1', 'Cinquième jour', 'Évacuation du village' ),
					array( 'sous-chapitre', 6.0, 2, 'Chapitre 6, partie 2', 'Sixième jour', 'Évacuation du village' ),
					array( 'postface', null, null, 'Postface', '', '' ),
				),
				yume_tisc_resume( $r )
			);
			yume_assert_same( array( 'Sous-chapitre « Souvenir lointain » sans chapitre parent : traité comme un chapitre.' ), $r->warnings, 'seul avertissement : aucun « chapitre absent », aucun chapitre vide' );
			// Illustration du parent sans texte : en tête de sa première partie.
			yume_assert_same( 1, count( $r->chapters[3]['images'] ) );
			yume_assert_same( 1, $r->chapters[3]['stats']['images'] );
			yume_assert_true( str_starts_with( $r->chapters[3]['blocks'], '<!-- wp:image' ), 'image en tête de la partie 1' );
			yume_assert_contains( 'Le soir tombe sur la colline.', $r->chapters[3]['blocks'] );
			yume_assert_not_contains( 'La nuit est longue.', $r->chapters[3]['blocks'] );
			yume_assert_same( array(), $r->chapters[4]['images'] );
			// Parent avec texte : chapitre ordinaire, sans le texte de ses parties.
			yume_assert_contains( 'Le pont tient bon.', $r->chapters[6]['blocks'] );
			yume_assert_contains( 'Introduction de l’évacuation.', $r->chapters[7]['blocks'] );
			yume_assert_not_contains( 'Les charrettes partent.', $r->chapters[7]['blocks'] );
			yume_assert_same( 11, $r->stats['chapitres'], 'parents sans texte non comptés' );
			// Le début du parent absorbé reste coché (vérification du découpage).
			$candidats = array_column( $r->candidats, null, 'extrait' );
			foreach ( array( 'Bataille de la colline', 'Défense du pont' ) as $titre ) {
				yume_assert_true( $candidats[ $titre ]['auto'], $titre );
				yume_assert_same( 'chapitre', $candidats[ $titre ]['nature'] );
				yume_assert_same( $titre, $candidats[ $titre ]['titre'] );
			}
			foreach ( array( 'Premier jour, soir', 'Quatrième jour' ) as $titre ) {
				yume_assert_true( $candidats[ $titre ]['auto'], $titre );
				yume_assert_same( 'sous-chapitre', $candidats[ $titre ]['nature'] );
			}
			// Rapport (analyse, REST) : rang de partie et titre du parent.
			$rapport = $r->rapport();
			yume_assert_same( 1, $rapport['chapitres'][3]['sous_numero'] );
			yume_assert_same( 'Bataille de la colline', $rapport['chapitres'][3]['chapitre_parent'] );
			yume_assert_same( 'Chapitre 3, partie 1 — Premier jour, soir', Result::libelle( $r->chapters[3] ) );

			// Premier numéro de chapitre saisi : la numérotation des parties suit.
			$plan = yume_tisc_plan_titre2( $auto );
			array_splice( $plan['debuts'], 1, 1 ); // Sans le Titre 2 du prologue (intertitre).
			$plan['debuts'][1]['numero'] = 10;
			$r                           = Docx_Converter::convert_file( $fichier, array( 'plan' => $plan ) );
			yume_assert_same( array( 'Prologue', 'Chapitre 10', 'Chapitre 11, partie 1', 'Chapitre 11, partie 2', 'Chapitre 12', 'Chapitre 13, partie 1', 'Chapitre 14', 'Chapitre 14, partie 1', 'Chapitre 14, partie 2', 'Postface' ), array_column( $r->chapters, 'titre' ) );
			yume_assert_same( array(), $r->warnings );
			yume_assert_contains( 'Souvenir lointain</h2>', $r->chapters[0]['blocks'], 'Titre 2 non coupé : intertitre du prologue' );
		} finally {
			wp_delete_file( $fichier );
		}
	}
);

yume_test(
	'sous-chapitres : numérotation d’un découpage (numeroter) et libellés',
	function () {
		$n = Chapter_Builder::numeroter(
			array(
				array(
					'nature' => 'sous-chapitre',
					'numero' => null,
				),
				array(
					'nature' => 'chapitre',
					'numero' => 4.0,
				),
				array(
					'nature' => 'sous-chapitre',
					'numero' => 9.0,
				),
				array(
					'nature' => 'sous-chapitre',
					'numero' => null,
				),
				array(
					'nature' => 'interlude',
					'numero' => null,
				),
				array(
					'nature' => 'sous-chapitre',
					'numero' => null,
				),
				array(
					'nature' => 'chapitre',
					'numero' => null,
				),
			)
		);
		yume_assert_same(
			array(
				array( 'chapitre', 1.0, null, true, 'Chapitre 1' ),
				array( 'chapitre', 4.0, null, false, 'Chapitre 4' ),
				array( 'sous-chapitre', 4.0, 1, false, 'Chapitre 4, partie 1' ),
				array( 'sous-chapitre', 4.0, 2, false, 'Chapitre 4, partie 2' ),
				array( 'interlude', null, null, false, 'Interlude' ),
				array( 'chapitre', 5.0, null, true, 'Chapitre 5' ),
				array( 'chapitre', 6.0, null, false, 'Chapitre 6' ),
			),
			array_map( static fn( $c ) => array( $c['nature'], $c['numero'], $c['sous_numero'], $c['orphelin'], $c['titre'] ), $n ),
			'numéro saisi d’une partie ignoré ; partie sans parent : chapitre (orphelin)'
		);
		yume_assert_same( 'Chapitre 2, partie 1', Texte::libelle_partie( 2.0, 1 ) );
		yume_assert_same( 'Chapitre 12,5, partie 3', Texte::libelle_partie( 12.5, 3 ) );
		yume_assert_same( 'Sous-chapitre', Texte::LIBELLES['sous-chapitre'] );
	}
);

yume_test(
	'sous-chapitres : marqueur « [sous-chapitre] Titre » dans le document (sans découpage)',
	function () {
		foreach ( array( '[sous-chapitre] Matin', '[Sous-Chapitre] : Matin', '[sous chapitre] Matin', '[souschapitre] Matin' ) as $texte ) {
			yume_assert_same(
				array(
					'nature' => 'sous-chapitre',
					'titre'  => 'Matin',
				),
				Texte::marqueur( $texte ),
				$texte
			);
		}
		yume_assert_same( null, Texte::marqueur( '[sous-titre] Matin' ) );
		yume_assert_same( 'chapitre', Texte::marqueur( '[chapitre] Titre' )['nature'] );

		$p       = static fn( string $t ): string => yume_fx_t( $t );
		$fichier = yume_timp_docx(
			$p( '[sous-chapitre] Orphelin' ) . $p( 'Texte orphelin.' )
			. yume_fx_t( 'Chapitre 3 : La fin', array( 'style' => 'Titre1' ) ) . $p( 'Avant les parties.' )
			. $p( '[sous-chapitre] Matin' ) . $p( 'Texte du matin.' )
			. $p( '[sous-chapitre] Soir' ) . $p( 'Texte du soir.' )
			. yume_fx_t( 'Chapitre 4', array( 'style' => 'Titre1' ) ) . $p( 'Suite.' )
		);
		try {
			$r = Docx_Converter::convert_file( $fichier );
			yume_assert_same(
				array(
					array( 'chapitre', 1.0, null, 'Chapitre 1', 'Orphelin', '' ),
					array( 'chapitre', 3.0, null, 'Chapitre 3', 'La fin', '' ),
					array( 'sous-chapitre', 3.0, 1, 'Chapitre 3, partie 1', 'Matin', 'La fin' ),
					array( 'sous-chapitre', 3.0, 2, 'Chapitre 3, partie 2', 'Soir', 'La fin' ),
					array( 'chapitre', 4.0, null, 'Chapitre 4', '', '' ),
				),
				yume_tisc_resume( $r )
			);
			yume_assert_contains( 'Sous-chapitre « Orphelin » sans chapitre parent : traité comme un chapitre.', implode( "\n", $r->warnings ) );
			yume_assert_not_contains( '[sous-chapitre]', implode( '', array_column( $r->chapters, 'blocks' ) ), 'marqueurs jamais publiés' );
		} finally {
			wp_delete_file( $fichier );
		}
	}
);
