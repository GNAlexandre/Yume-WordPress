<?php
/**
 * Tests du module migration (partie 1 : analyse de l'ancien site, sans écriture) :
 * utilitaires HTML et blocs, chargeur d'export, analyseurs de hub, de fiche, d'arc, de
 * chapitre et d'article, planificateur et rapport — sur les extraits courts de
 * tools/migrate/fixtures/, puis sur l'export complet s'il est présent (tools/migrate/export/,
 * non versionné : ces derniers tests sont ignorés sinon).
 *
 *   tools/localenv/test.sh migration
 *
 * Aucun test n'écrit en base ni n'appelle le réseau : les classes testées sont pures.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Blocks;
use Yume\Core\Migration\Export_Loader;
use Yume\Core\Migration\Html;
use Yume\Core\Migration\Legacy_Arc_Parser;
use Yume\Core\Migration\Legacy_Chapter_Parser;
use Yume\Core\Migration\Legacy_Hub_Parser;
use Yume\Core\Migration\Legacy_Oeuvre_Parser;
use Yume\Core\Migration\Legacy_Post_Parser;
use Yume\Core\Migration\Migration_Planner;
use Yume\Core\Migration\Plan_Report;

if ( ! class_exists( Migration_Planner::class ) ) {
	require_once dirname( __DIR__ ) . '/includes/migration/module.php';
}

/**
 * Dossier des extraits d'export versionnés.
 */
function yume_test_migration_dossier_fixtures(): string {
	return dirname( __DIR__, 4 ) . '/tools/migrate/fixtures';
}

/**
 * Export des fixtures (chargé une fois).
 *
 * @return array<string,mixed>
 */
function yume_test_migration_fixtures(): array {
	static $export = null;
	if ( null === $export ) {
		$export = Export_Loader::depuis_dossier( yume_test_migration_dossier_fixtures() );
	}
	return $export;
}

/**
 * Page des fixtures par ID.
 *
 * @param int $id ID de la page d'origine.
 */
function yume_test_migration_page( int $id ): array {
	foreach ( yume_test_migration_fixtures()['pages'] as $page ) {
		if ( $id === $page['id'] ) {
			return $page;
		}
	}
	throw new Yume_Test_Failure( "Page $id absente des fixtures." );
}

/**
 * Article des fixtures par ID.
 *
 * @param int $id ID de l'article d'origine.
 */
function yume_test_migration_article( int $id ): array {
	foreach ( yume_test_migration_fixtures()['posts'] as $post ) {
		if ( $id === $post['id'] ) {
			return $post;
		}
	}
	throw new Yume_Test_Failure( "Article $id absent des fixtures." );
}

/**
 * Plan des fixtures (dates figées pour un résultat reproductible).
 *
 * @return array<string,mixed>
 */
function yume_test_migration_plan(): array {
	static $plan = null;
	if ( null === $plan ) {
		$plan = ( new Migration_Planner(
			yume_test_migration_fixtures(),
			array(
				'genere_le'   => '2026-01-01T00:00:00Z',
				'date_import' => '2026-01-01 00:00:00',
			)
		) )->plan();
	}
	return $plan;
}

/**
 * Élément d'une liste du plan par clé.
 *
 * @param array  $liste Liste (oeuvres, tomes, chapitres).
 * @param string $cle   Clé cherchée.
 */
function yume_test_migration_par_cle( array $liste, string $cle ): array {
	foreach ( $liste as $element ) {
		if ( ( $element['cle'] ?? null ) === $cle ) {
			return $element;
		}
	}
	throw new Yume_Test_Failure( "Clé « $cle » absente du plan." );
}

/**
 * Plan de l'export complet, ou null s'il n'a pas été régénéré localement.
 *
 * @return array<string,mixed>|null
 */
function yume_test_migration_plan_complet(): ?array {
	static $plan = false;
	if ( false === $plan ) {
		$dossier = dirname( __DIR__, 4 ) . '/tools/migrate/export';
		$plan    = is_readable( $dossier . '/pages.json' ) && is_readable( $dossier . '/posts.json' )
			? ( new Migration_Planner(
				Export_Loader::depuis_dossier( $dossier ),
				array(
					'genere_le'   => '2026-01-01T00:00:00Z',
					'date_import' => '2026-01-01 00:00:00',
				)
			) )->plan()
			: null;
	}
	return $plan;
}

/*
 * -----------------------------------------------------------------------------
 * Utilitaires HTML et blocs
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Html : réplique normalisée (tiret cadratin + espace insécable, mise en forme du tiret retirée)',
	function () {
		$nbsp = "\u{00A0}";
		yume_assert_same( '—' . $nbsp . 'Bonjour.', Html::normaliser_dialogue( Html::nettoyer_inline( '<em><strong>— </strong></em>Bonjour.' ) ) );
		yume_assert_same( '—' . $nbsp . 'Salut !', Html::normaliser_dialogue( '- Salut !' ) );
		yume_assert_same( '—' . $nbsp . 'Oui.', Html::normaliser_dialogue( '–&nbsp;Oui.' ) );
		yume_assert_same( '—' . $nbsp . '...De la fenêtre ?', Html::normaliser_dialogue( Html::nettoyer_inline( '<strong>— .</strong>..De la fenêtre ?' ) ) );
		yume_assert_same( '—' . $nbsp . '<em>Je pense donc je suis.</em>', Html::normaliser_dialogue( Html::nettoyer_inline( '<em>— Je pense donc je suis.</em>' ) ) );
		yume_assert_true( Html::commence_par_tiret( '— Oui' ) );
		yume_assert_true( Html::commence_par_tiret( '- Oui' ) );
		yume_assert_false( Html::commence_par_tiret( 'Tout-à-coup' ) );
	}
);

yume_test(
	'Html : nettoyage du HTML en ligne (balises autorisées, équivalences, liens sûrs, fusion)',
	function () {
		yume_assert_same( '<strong>gras</strong> et <em>italique</em>', Html::nettoyer_inline( '<b>gras</b> et <i>italique</i>' ) );
		yume_assert_same( 'texte', Html::nettoyer_inline( '<span style="color:red">texte</span><script>alert(1)</script>' ) );
		yume_assert_same( '<a href="https://exemple.fr/">lien</a>', Html::nettoyer_inline( '<a href="https://exemple.fr/" onclick="x()">lien</a>' ) );
		yume_assert_same( 'piège', Html::nettoyer_inline( '<a href="javascript:alert(1)">piège</a>' ) );
		yume_assert_same( '<em>Pourquoi es-tu ici</em>', Html::nettoyer_inline( '<em>P</em><em>ourquoi es-tu ici</em>' ) );
		yume_assert_same( 'a<br>b', Html::nettoyer_inline( 'a<br/>b' ) );
		yume_assert_same( '« Oui »', Html::nettoyer_inline( '<em>« </em>Oui<strong> »</strong>' ) );
	}
);

yume_test(
	'Html : pensées (italique intégral), séparateurs de scène, comptage des mots',
	function () {
		yume_assert_true( Html::entierement_italique( '<em>Je dois m’en souvenir.</em>' ) );
		yume_assert_true( Html::entierement_italique( '<em>Je dois</em> <em>m’en souvenir…</em>' ) );
		yume_assert_false( Html::entierement_italique( 'Il pensa : <em>non</em>.' ) );
		yume_assert_same( 'Je dois m’en souvenir.', Html::sans_italique( '<em>Je dois m’en souvenir.</em>' ) );
		yume_assert_true( Html::est_separateur_texte( '***' ) );
		yume_assert_true( Html::est_separateur_texte( '* * *' ) );
		yume_assert_true( Html::est_separateur_texte( '◇◇◇' ) );
		yume_assert_false( Html::est_separateur_texte( 'Fin.' ) );
		yume_assert_same( 4, Html::nombre_mots( 'Aujourd’hui, l’élève dit : « peut-être ».' ) );
	}
);

yume_test(
	'Html : chemins locaux, nombres, normalisation',
	function () {
		$domaines = array( 'yumenovel.fr', 'yumenovel.wordpress.com' );
		yume_assert_same( '/grimgar-of-fantasy-and-ash-ln/', Html::chemin_local( 'https://yumenovel.wordpress.com/grimgar-of-fantasy-and-ash-ln', $domaines ) );
		yume_assert_same( '/arc-7/', Html::chemin_local( 'https://www.yumenovel.fr/arc-7/?x=1#haut', $domaines ) );
		yume_assert_same( '', Html::chemin_local( 'https://www.clictune.com/n7ss', $domaines ) );
		yume_assert_same( 26.5, Html::nombre( '26,5' ) );
		yume_assert_same( 'l ecole d ete', Html::normaliser( 'L’École d\'Été' ) );
		yume_assert_same( 'Tournoi d\'échec', Html::casse_phrase( 'TOURNOI D\'ÉCHEC' ) );
	}
);

yume_test(
	'Blocks : markup conforme au contrat §9 (dialogue, centré, séparateur, illustration)',
	function () {
		$dialogue = Blocks::paragraphe( "—\u{00A0}Oui.", array( 'yn-dialogue' ) );
		$blocs    = parse_blocks( $dialogue );
		yume_assert_same( 'core/paragraph', $blocs[0]['blockName'] );
		yume_assert_same( 'yn-dialogue', $blocs[0]['attrs']['className'] );
		yume_assert_contains( '<p class="yn-dialogue">', $dialogue );

		$centre = parse_blocks( Blocks::paragraphe( 'Fin', array( 'yn-center' ), 'center' ) )[0];
		yume_assert_same( 'center', $centre['attrs']['align'] );
		yume_assert_contains( 'has-text-align-center', $centre['innerHTML'] );
		yume_assert_contains( 'yn-center', $centre['innerHTML'] );

		$sep = parse_blocks( Blocks::separateur() )[0];
		yume_assert_same( 'core/separator', $sep['blockName'] );
		yume_assert_contains( 'yn-scene-break', $sep['innerHTML'] );

		$image = parse_blocks( Blocks::image( 42, 'https://exemple.fr/a.jpg', 'Alya "souriante"' ) )[0];
		yume_assert_same( 'core/image', $image['blockName'] );
		yume_assert_same( 42, $image['attrs']['id'] );
		yume_assert_same( 'large', $image['attrs']['sizeSlug'] );
		yume_assert_contains( 'yn-illustration', $image['innerHTML'] );
		yume_assert_contains( 'wp-image-42', $image['innerHTML'] );
		yume_assert_contains( 'alt="Alya &quot;souriante&quot;"', $image['innerHTML'] );
		yume_assert_same( '<!-- wp:yume/library-grid /-->', Blocks::dynamique( 'yume/library-grid' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Chargeur d'export
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Export_Loader : fixtures chargées et normalisées, erreurs explicites',
	function () {
		$export = yume_test_migration_fixtures();
		yume_assert_same( 'yumenovel.fr', $export['site']['domaine'] );
		yume_assert_same( 11, count( $export['pages'] ) );
		yume_assert_same( 5, count( $export['posts'] ) );
		yume_assert_same( 3, count( $export['categories'] ) );
		yume_assert_true( is_int( $export['pages'][0]['id'] ) );

		$normal = Export_Loader::normaliser(
			array(
				'pages' => array(
					array(
						'id'      => '7',
						'title'   => array( 'rendered' => 'L&rsquo;&Eacute;quipe' ),
						'content' => array( 'raw' => '<p>x</p>' ),
						'date'    => '2025-08-13T12:21:48',
					),
				),
				'posts' => array(),
			)
		);
		yume_assert_same( 7, $normal['pages'][0]['id'] );
		yume_assert_same( 'L’Équipe', $normal['pages'][0]['title'] );
		yume_assert_same( '<p>x</p>', $normal['pages'][0]['content'] );
		yume_assert_same( '2025-08-13 12:21:48', $normal['pages'][0]['date'] );

		$erreur = '';
		try {
			Export_Loader::depuis_dossier( '/chemin/inexistant' );
		} catch ( RuntimeException $e ) {
			$erreur = $e->getMessage();
		}
		yume_assert_contains( 'introuvable', $erreur );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Hub, fiche, arc
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Hub « Yume LN » : sections de statut et entrées (couverture, statuts ambigus)',
	function () {
		$hub = Legacy_Hub_Parser::parse( yume_test_migration_page( 12 ) );
		yume_assert_same( 12, $hub['source_id'] );
		yume_assert_same( 'light-novel', $hub['type'] );
		yume_assert_same( 12, count( $hub['entrees'] ) );
		yume_assert_same( array( 'en-cours' ), $hub['entrees']['grimgar-of-fantasy-and-ash-ln']['statuts'] );
		yume_assert_same( 2206, $hub['entrees']['grimgar-of-fantasy-and-ash-ln']['image_id'] );
		yume_assert_same( array( 'terminee' ), $hub['entrees']['sukasuka-ln']['statuts'] );
		yume_assert_same( array( 'licenciee', 'abandonnee' ), $hub['entrees']['gimai-seikatsu-ln']['statuts'] );
		$statuts = Legacy_Hub_Parser::statuts_depuis_libelle( 'Série Abandonnée/En pause' );
		sort( $statuts );
		yume_assert_same( array( 'abandonnee', 'en-pause' ), $statuts );
		yume_assert_same( array( 'terminee' ), Legacy_Hub_Parser::statuts_depuis_libelle( 'Série Terminée' ) );
	}
);

yume_test(
	'Fiche Grimgar (LN) : métadonnées, statut du hub, 9 tomes avec 18 liens ClicTune',
	function () {
		$hub   = Legacy_Hub_Parser::parse( yume_test_migration_page( 12 ) );
		$fiche = Legacy_Oeuvre_Parser::parse( yume_test_migration_page( 2209 ), $hub['entrees']['grimgar-of-fantasy-and-ash-ln'] );
		yume_assert_same( 'Grimgar of Fantasy and Ash', $fiche['titre'] );
		yume_assert_same( 'grimgar-of-fantasy-and-ash', $fiche['slug'] );
		yume_assert_same( 'light-novel', $fiche['type'] );
		yume_assert_same( 'en-cours', $fiche['statut'] );
		yume_assert_same( 'Jyumonji Ao', $fiche['auteur'] );
		yume_assert_same( 'Shirai Eiri', $fiche['illustrateur'] );
		yume_assert_same( 'OVERLAP', $fiche['editeur_vo'] );
		yume_assert_same( 22, $fiche['nb_tomes_vo'] );
		yume_assert_true( in_array( 'Hai to Gensou no Grimgar', $fiche['titres_alt'], true ) );
		yume_assert_same( 9, count( $fiche['tomes'] ) );
		$clictune = 0;
		foreach ( $fiche['tomes'] as $i => $tome ) {
			yume_assert_same( (float) ( $i + 1 ), $tome['numero'] );
			yume_assert_same( 'tome', $tome['nature'] );
			yume_assert_true( $tome['couverture_id'] > 0, 'couverture du tome ' . ( $i + 1 ) );
			foreach ( array( $tome['lien_pdf'], $tome['lien_epub'] ) as $lien ) {
				$clictune += str_starts_with( $lien, 'https://www.clictune.com/' ) ? 1 : 0;
			}
		}
		yume_assert_same( 18, $clictune );
		yume_assert_same( 'https://www.clictune.com/nwEd', $fiche['tomes'][8]['lien_pdf'] );
		yume_assert_same( 'https://www.clictune.com/nwEh', $fiche['tomes'][8]['lien_epub'] );
		yume_assert_same( array(), $fiche['avertissements'] );
	}
);

yume_test(
	'Fiche Silent Witch (WN) : type web novel, slug sans « -ln », 8 arcs, avancement TR/REC',
	function () {
		$fiche = Legacy_Oeuvre_Parser::parse( yume_test_migration_page( 550 ) );
		yume_assert_same( 'Secrets Of The Silent Witch', $fiche['titre'] );
		yume_assert_same( 'secrets-of-the-silent-witch', $fiche['slug'] );
		yume_assert_same( 'web-novel', $fiche['type'] );
		yume_assert_same( 'Isora Matsuri', $fiche['auteur'] );
		yume_assert_same( 8, count( $fiche['arcs'] ) );
		yume_assert_same( '/arc-7-tournoi-dechec-silent-witch/', $fiche['arcs'][6]['chemin'] );
		yume_assert_same(
			array(
				'traduction' => 11,
				'relecture'  => 8,
			),
			$fiche['avancement_fiche']
		);
		yume_assert_same( array(), $fiche['tomes'] );
	}
);

yume_test(
	'Fiche : utilitaires (en-tête de tome, plage de chapitres, type de lien, titre)',
	function () {
		yume_assert_same(
			array(
				'numero' => 3.0,
				'nature' => 'tome',
			),
			Legacy_Oeuvre_Parser::entete_tome( 'TOME 3 | ==> PDF <==' )
		);
		yume_assert_same( 'ex', Legacy_Oeuvre_Parser::entete_tome( 'EX | Bientôt' )['nature'] );
		yume_assert_same( null, Legacy_Oeuvre_Parser::entete_tome( 'Synopsis' ) );
		yume_assert_same(
			array(
				'de' => 1.0,
				'a'  => 6.5,
			),
			Legacy_Oeuvre_Parser::plage_chapitres( 'C1 à C6.5' )
		);
		yume_assert_same( 'epub', Legacy_Oeuvre_Parser::type_lien( 'https://www.clictune.com/x', 'EPUB' ) );
		yume_assert_same( 'pdf', Legacy_Oeuvre_Parser::type_lien( 'https://mega.nz/x', '==> PDF <==' ) );
		yume_assert_same( 'achat', Legacy_Oeuvre_Parser::type_lien( 'https://www.amazon.fr/x', 'Acheter' ) );
		yume_assert_same( 'lecture', Legacy_Oeuvre_Parser::type_lien( 'https://mangadex.org/title/x', 'Tome 1' ) );
		yume_assert_same( '26,5', Legacy_Oeuvre_Parser::numero_fr( 26.5 ) );
	}
);

yume_test(
	'Arc 7 : titre, équivalence LN, 15 chapitres annoncés dont 8 traduits, bouton PDF vide signalé',
	function () {
		yume_assert_same(
			array(
				'numero' => 7,
				'titre'  => 'Tournoi d\'échec',
				'oeuvre' => 'Silent Witch',
			),
			Legacy_Arc_Parser::analyser_titre( 'ARC 7 | TOURNOI D\'ÉCHEC – Silent Witch' )
		);
		$arc = Legacy_Arc_Parser::parse( yume_test_migration_page( 2173 ) );
		yume_assert_same( 7, $arc['numero'] );
		yume_assert_same( 3, $arc['equivalence_tome_ln'] );
		yume_assert_same( 15, count( $arc['chapitres'] ) );
		yume_assert_same( 8, $arc['nb_traduits'] );
		yume_assert_same( '******', $arc['chapitres'][2]['titre'] );
		yume_assert_same( '/secrets-of-the-silent-witch-t-7-chapitre-3/', $arc['chapitres'][2]['chemin'] );
		yume_assert_false( $arc['chapitres'][8]['traduit'] );
		yume_assert_same( '', $arc['lien_pdf'] );
		yume_assert_contains( 'PDF', implode( ' ', $arc['avertissements'] ) );

		$arc4 = Legacy_Arc_Parser::parse( yume_test_migration_page( 2072 ) );
		yume_assert_same( 'Bal de l\'académie', $arc4['titre'] );
		yume_assert_same( 6, $arc4['nb_traduits'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Chapitres
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Chapitre : titre de page et slug attendu',
	function () {
		$titre = Legacy_Chapter_Parser::analyser_titre( 'Secrets of the Silent Witch T.4 – Chapitre 1' );
		yume_assert_same( 'Secrets of the Silent Witch', $titre['oeuvre'] );
		yume_assert_same( 4, $titre['tome'] );
		yume_assert_same( 1.0, $titre['numero'] );
		yume_assert_same( 'secrets-of-the-silent-witch-t-4-chapitre-1', Legacy_Chapter_Parser::slug_attendu( $titre ) );
		yume_assert_same( null, Legacy_Chapter_Parser::analyser_titre( 'La Yume Novel' ) );
	}
);

yume_test(
	'Chapitre 1548 (slug incohérent) : sous-titre, crédits, répliques normalisées, navigation retirée',
	function () {
		$c = Legacy_Chapter_Parser::parse( yume_test_migration_page( 1548 ) );
		yume_assert_same( 4, $c['tome_numero'] );
		yume_assert_same( 1.0, $c['numero'] );
		yume_assert_same( 'Gyurungyurun (SFX de danse)', $c['sous_titre'] );
		yume_assert_same(
			array(
				'traduction' => 'Pizzflc',
				'relecture'  => 'Mael7523m',
				'edition'    => '',
			),
			$c['credits']
		);
		yume_assert_true( $c['slug_incoherent'] );
		yume_assert_same( 'secrets-of-the-silent-witch-t-4-chapitre-1', $c['slug_attendu'] );
		yume_assert_same( 2, count( $c['navigation'] ) );
		yume_assert_same( 3, $c['stats']['dialogues'] );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Nooooon ! S'il te plaît, ralentis !</p>", $c['contenu'] );
		// Ni sous-titre, ni crédits, ni navigation, ni séparateur final dans le contenu.
		yume_assert_not_contains( 'Gyurungyurun', $c['contenu'] );
		yume_assert_not_contains( 'Pizzflc', $c['contenu'] );
		yume_assert_not_contains( 'wp:image', $c['contenu'] );
		yume_assert_not_contains( 'wp:separator', $c['contenu'] );
		yume_assert_same( 40, strlen( $c['hash'] ) );
		yume_assert_same( 2, $c['temps_lecture'] );
		yume_assert_same( array(), $c['avertissements'] );
		// Le contenu produit est un markup de blocs valide (re-sérialisation à l'identique).
		yume_assert_same( $c['contenu'], serialize_blocks( parse_blocks( $c['contenu'] ) ) );
	}
);

yume_test(
	'Chapitre 2417 : sous-titre masqué (« ****** ») signalé, citation aplatie en réplique, séparateurs fusionnés',
	function () {
		$c = Legacy_Chapter_Parser::parse( yume_test_migration_page( 2417 ) );
		yume_assert_same( '', $c['sous_titre'] );
		yume_assert_contains( 'Sous-titre', implode( ' ', $c['avertissements'] ) );
		yume_assert_same( 'Pizzflc', $c['credits']['traduction'] );
		yume_assert_contains( "<p class=\"yn-dialogue\">—\u{00A0}Quant à votre façon de jouer, Lord Howard...</p>", $c['contenu'] );
		yume_assert_not_contains( 'wp:quote', $c['contenu'] );
		yume_assert_not_contains( 'wp:separator', $c['contenu'] );
		yume_assert_same( 1, $c['stats']['dialogues'] );
	}
);

yume_test(
	'Chapitre 2558 : pensées en italique → yn-thought, image non liée du pied de navigation retirée',
	function () {
		$c = Legacy_Chapter_Parser::parse( yume_test_migration_page( 2558 ) );
		yume_assert_same( 'Peu importe comment je le regarde, c’est un objet de torture', $c['sous_titre'] );
		yume_assert_same( 2, $c['stats']['pensees'] );
		yume_assert_contains( '<p class="yn-thought">Pourquoi es-tu ici... Bernie !?</p>', $c['contenu'] );
		yume_assert_contains( 'yn-scene-break', $c['contenu'] );
		yume_assert_not_contains( 'wp:image', $c['contenu'] );
		yume_assert_same( 0, $c['stats']['illustrations'] );
		yume_assert_same( array(), $c['illustrations'] );
		yume_assert_contains( '#2561', implode( ' ', $c['avertissements'] ) );
	}
);

yume_test(
	'Chapitre synthétique : HTML rendu sans blocs, *** → séparateur, illustration conservée, répliques collées signalées',
	function () {
		$page = array(
			'id'      => 9001,
			'slug'    => 'mon-oeuvre-t-2-chapitre-3-5',
			'status'  => 'publish',
			'link'    => 'https://yumenovel.fr/mon-oeuvre-t-2-chapitre-3-5/',
			'title'   => 'Mon Œuvre T.2 – Chapitre 3.5',
			'date'    => '2025-01-02 03:04:05',
			'content' => '<p style="text-align:center"><strong>Le retour</strong></p>'
				. '<p>Traduction : Alice / Relecture : Bob</p>'
				. '<p>Il était une fois.<br><br>— Qui va là ?<br>— Moi. — Toi ? — Oui.</p>'
				. '<p>* * *</p>'
				. '<p style="text-align:center">Fin de la partie</p>'
				. '<figure class="wp-block-image"><img src="https://yumenovel.wordpress.com/wp-content/uploads/2025/01/illu.jpg?w=1024" alt="Illustration" class="wp-image-77"/></figure>'
				. '<figure class="wp-block-image"><a href="https://yumenovel.fr/mon-oeuvre-t-2-chapitre-4/"><img src="https://yumenovel.wordpress.com/wp-content/uploads/2025/01/suivant.jpg" class="wp-image-78"/></a></figure>',
		);
		$c    = Legacy_Chapter_Parser::parse( $page, array( 'medias' => array( 77 => 'https://yumenovel.wordpress.com/wp-content/uploads/2025/01/illu.jpg' ) ) );
		yume_assert_same( 2, $c['tome_numero'] );
		yume_assert_same( 3.5, $c['numero'] );
		yume_assert_same( 'Le retour', $c['sous_titre'] );
		yume_assert_same( 'Alice', $c['credits']['traduction'] );
		yume_assert_same( 'Bob', $c['credits']['relecture'] );
		yume_assert_false( $c['slug_incoherent'] );
		yume_assert_same( 2, $c['stats']['dialogues'] );
		yume_assert_same( 1, $c['stats']['separateurs'] );
		yume_assert_same( 1, $c['stats']['centres'] );
		yume_assert_same( array( 77 ), $c['illustrations'] );
		yume_assert_same( array( 'https://yumenovel.fr/mon-oeuvre-t-2-chapitre-4/' ), $c['navigation'] );
		yume_assert_contains( '"id":77,"sizeSlug":"large"', $c['contenu'] );
		yume_assert_contains( 'src="https://yumenovel.wordpress.com/wp-content/uploads/2025/01/illu.jpg"', $c['contenu'] );
		yume_assert_contains( '<p class="has-text-align-center yn-center">Fin de la partie</p>', $c['contenu'] );
		yume_assert_contains( 'plusieurs répliques', implode( ' ', $c['avertissements'] ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Articles
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Article : numéros annoncés (tome, arc, chapitres groupés, plages décimales)',
	function () {
		$n = Legacy_Post_Parser::numeros( 'Chapitre 12 à 16.5 de Mikadono Sister disponible !' );
		yume_assert_same( array( 12.0, 13.0, 14.0, 15.0, 16.0, 16.5 ), $n['chapitres'] );
		yume_assert_same( array( 6.0, 6.5 ), Legacy_Post_Parser::numeros( 'Chapitre 6 & 6.5 de Mikadono Sister' )['chapitres'] );
		$n = Legacy_Post_Parser::numeros( 'Chapitre 8 de l’Arc 7 du Web Novel de Silent Witch disponible !' );
		yume_assert_same( 7, $n['arc'] );
		yume_assert_same( array( 8.0 ), $n['chapitres'] );
		yume_assert_same( 9.0, Legacy_Post_Parser::numeros( 'Tome 9 de Grimgar of Fantasy and Ash disponible !' )['tome'] );
		yume_assert_true( Legacy_Post_Parser::numeros( 'Tome EX de Grimgar' )['tome_ex'] );
	}
);

yume_test(
	'Article : classement sortie / actualité, œuvre liée, liens, catégorie cible',
	function () {
		$index      = Legacy_Post_Parser::index_oeuvres(
			array(
				array(
					'cle'        => 'grimgar-of-fantasy-and-ash',
					'titre'      => 'Grimgar of Fantasy and Ash',
					'titres_alt' => array(),
					'type'       => 'light-novel',
					'alias'      => array( 'Grimgar' ),
				),
				array(
					'cle'        => 'secrets-of-the-silent-witch',
					'titre'      => 'Secrets Of The Silent Witch',
					'titres_alt' => array(),
					'type'       => 'web-novel',
					'alias'      => array( 'Silent Witch' ),
				),
			)
		);
		$categories = array(
			3402      => 'actualites',
			6325      => 'non-classe',
			775285387 => 'yume-news',
		);

		$grimgar = Legacy_Post_Parser::parse( yume_test_migration_article( 2555 ), $index, array( 'categories' => $categories ) );
		yume_assert_same( 'sortie', $grimgar['classement'] );
		yume_assert_same( 'sorties', $grimgar['categorie_cible'] );
		yume_assert_same( 'tome', $grimgar['type_sortie'] );
		yume_assert_same( 'grimgar-of-fantasy-and-ash', $grimgar['oeuvre'] );
		yume_assert_same( 9.0, $grimgar['tome'] );
		yume_assert_same( 'https://www.clictune.com/nwEd', $grimgar['liens']['pdf'] );
		yume_assert_same( 'https://www.clictune.com/nwEh', $grimgar['liens']['epub'] );

		$sw = Legacy_Post_Parser::parse( yume_test_migration_article( 2564 ), $index, array( 'categories' => $categories ) );
		yume_assert_same( 'chapitres', $sw['type_sortie'] );
		yume_assert_same( 'secrets-of-the-silent-witch', $sw['oeuvre'] );
		yume_assert_same( array( 'non-classe' ), $sw['categories_slugs'] );
		yume_assert_same( array( '/secrets-of-the-silent-witch-t-7-chapitre-8/' ), $sw['liens']['internes'] );

		$news = Legacy_Post_Parser::parse( yume_test_migration_article( 1712 ), $index, array( 'categories' => $categories ) );
		yume_assert_same( 'actualite', $news['classement'] );
		yume_assert_same( 'actualites', $news['categorie_cible'] );
		yume_assert_same( null, $news['type_sortie'] );

		$equipe = Legacy_Post_Parser::parse(
			array(
				'id'      => 1,
				'title'   => 'Chapitre 3 de Grimgar disponible !',
				'content' => '<p>La traduction est signée par Pizzflc et la correction par Mael7523m. Bonne lecture !</p>',
			),
			$index
		);
		yume_assert_same(
			array(
				'traduction' => 'Pizzflc',
				'relecture'  => 'Mael7523m',
			),
			$equipe['equipe']
		);
	}
);

/*
 * -----------------------------------------------------------------------------
 * Planificateur et rapport (fixtures)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Plan (fixtures) : œuvres, tomes des fiches et des arcs, clés et URL du contrat',
	function () {
		$plan = yume_test_migration_plan();
		yume_assert_same( Migration_Planner::VERSION, $plan['version'] );
		yume_assert_same( array( 'secrets-of-the-silent-witch', 'grimgar-of-fantasy-and-ash' ), array_column( $plan['oeuvres'], 'cle' ) );

		$grimgar = yume_test_migration_par_cle( $plan['oeuvres'], 'grimgar-of-fantasy-and-ash' );
		yume_assert_same( 'yume_oeuvre', $grimgar['post']['post_type'] );
		yume_assert_same( 'grimgar-of-fantasy-and-ash', $grimgar['post']['post_name'] );
		yume_assert_same( array( 'light-novel' ), $grimgar['termes']['yume_type'] );
		yume_assert_same( array( 'en-cours' ), $grimgar['termes']['yume_statut'] );
		yume_assert_same( '/oeuvres/grimgar-of-fantasy-and-ash/', $grimgar['url'] );
		yume_assert_same( 2209, $grimgar['source']['id'] );
		yume_assert_same( 9, count( $grimgar['tomes'] ) );

		$t9 = yume_test_migration_par_cle( $plan['tomes'], 'grimgar-of-fantasy-and-ash/tome-9' );
		yume_assert_same( 'yume_tome', $t9['post']['post_type'] );
		yume_assert_same( 'tome-9', $t9['post']['post_name'] );
		yume_assert_same( 'Grimgar of Fantasy and Ash — Tome 9', $t9['post']['post_title'] );
		yume_assert_same( 90, $t9['post']['menu_order'] );
		yume_assert_same( 'https://www.clictune.com/nwEd', $t9['meta']['yume_lien_pdf'] );
		yume_assert_same( 'https://www.clictune.com/nwEh', $t9['meta']['yume_lien_epub'] );
		yume_assert_same( '/oeuvres/grimgar-of-fantasy-and-ash/tome-9/', $t9['url'] );
		yume_assert_same( 'publish', $t9['post']['post_status'] );
		yume_assert_true( $t9['thumbnail_id'] > 0 );
		// Date du tome : article de sortie du tome 9 (2555).
		yume_assert_same( yume_test_migration_article( 2555 )['date'], $t9['post']['post_date'] );

		$arc7 = yume_test_migration_par_cle( $plan['tomes'], 'secrets-of-the-silent-witch/arc-7' );
		yume_assert_same( 'arc-7', $arc7['post']['post_name'] );
		yume_assert_same( 'Secrets Of The Silent Witch — Arc 7 : Tournoi d\'échec', $arc7['post']['post_title'] );
		yume_assert_same( 7.0, (float) $arc7['meta']['yume_numero'] );
		yume_assert_same( 'arc', $arc7['meta']['yume_nature'] );
		yume_assert_same( 'edition', $arc7['meta']['yume_etape'] );
		yume_assert_same( 15, count( $arc7['chapitres'] ) );
		yume_assert_same( '/oeuvres/secrets-of-the-silent-witch/arc-7/', $arc7['url'] );
	}
);

yume_test(
	'Plan (fixtures) : chapitres migrés et planifiés, source conservée, pages manquantes en erreur',
	function () {
		$plan = yume_test_migration_plan();
		$c    = yume_test_migration_par_cle( $plan['chapitres'], 'secrets-of-the-silent-witch/arc-4/1' );
		yume_assert_same( 'page', $c['source']['type'] );
		yume_assert_same( 1548, $c['source']['id'] );
		yume_assert_same( 'yume_chapitre', $c['post']['post_type'] );
		yume_assert_same( 'chapitre-1', $c['post']['post_name'] );
		yume_assert_same( 'Chapitre 1 — Gyurungyurun (SFX de danse)', $c['post']['post_title'] );
		yume_assert_same( 'publish', $c['post']['post_status'] );
		yume_assert_same( 'Gyurungyurun (SFX de danse)', $c['meta']['yume_sous_titre'] );
		yume_assert_same( 'migration', $c['meta']['yume_source']['format'] );
		yume_assert_same( '2026-01-01 00:00:00', $c['meta']['yume_source']['importe_le'] );
		yume_assert_same( '/lire/secrets-of-the-silent-witch/arc-4/1/', $c['url'] );

		$titre_arc = yume_test_migration_par_cle( $plan['chapitres'], 'secrets-of-the-silent-witch/arc-7/3' );
		yume_assert_same( '******', $titre_arc['meta']['yume_sous_titre'] );

		$annonce = yume_test_migration_par_cle( $plan['chapitres'], 'secrets-of-the-silent-witch/arc-7/15' );
		yume_assert_same( 'annonce', $annonce['source']['type'] );
		yume_assert_same( 2173, $annonce['source']['id'] );
		yume_assert_same( 'draft', $annonce['post']['post_status'] );
		yume_assert_same( '', $annonce['post']['post_content'] );

		yume_assert_same( 3, $plan['comptes']['chapitres']['migres'] );
		$erreurs = array_filter( $plan['avertissements'], static fn( $a ) => 'erreur' === $a['niveau'] && 'chapitre' === $a['categorie'] );
		yume_assert_same( 11, count( $erreurs ), 'chapitres traduits dont la page manque aux fixtures' );
	}
);

yume_test(
	'Plan (fixtures) : redirections 301 de l’ancien site vers les URL du contrat §3',
	function () {
		$plan    = yume_test_migration_plan();
		$cibles  = array_column( $plan['redirections'], 'cible', 'source' );
		$attendu = array(
			'/grimgar-of-fantasy-and-ash-ln/'                 => '/oeuvres/grimgar-of-fantasy-and-ash/',
			'/secrets-of-the-silent-witch-ln/'                => '/oeuvres/secrets-of-the-silent-witch/',
			'/arc-7-tournoi-dechec-silent-witch/'             => '/oeuvres/secrets-of-the-silent-witch/arc-7/',
			'/secrets-of-the-silent-witch-t-3-chapitre-1-2/'  => '/lire/secrets-of-the-silent-witch/arc-4/1/',
			'/secrets-of-the-silent-witch-t-7-chapitre-8/'    => '/lire/secrets-of-the-silent-witch/arc-7/8/',
			'/yume-ln/'                                       => '/bibliotheque/?type=light-novel',
			'/category/yume-news/'                            => '/category/sorties/',
			'/category/non-classe/'                           => '/category/actualites/',
		);
		foreach ( $attendu as $source => $cible ) {
			yume_assert_same( $cible, $cibles[ $source ] ?? null, "redirection de $source" );
		}
		foreach ( $plan['redirections'] as $r ) {
			yume_assert_same( 301, $r['code'] );
			yume_assert_true( str_starts_with( $r['cible'], '/' ) );
		}
		yume_assert_same( count( $plan['redirections'] ), count( array_unique( array_column( $plan['redirections'], 'source' ) ) ), 'sources uniques' );
		yume_assert_contains( "\"/yume-ln/\",\"/bibliotheque/?type=light-novel\",0,301\n", Plan_Report::csv_redirections( $plan ) );
	}
);

yume_test(
	'Plan (fixtures) : pages conservées, remplacées, ignorées et pages §11 à créer',
	function () {
		$plan = yume_test_migration_plan();
		yume_assert_same( array( 143 ), array_column( $plan['pages']['conserver'], 'id' ) );
		yume_assert_same( array( 151 ), array_column( $plan['pages']['ignorer'], 'id' ) );
		$remplacer = array_column( $plan['pages']['remplacer'], 'famille', 'id' );
		yume_assert_same( 'hub', $remplacer[12] );
		yume_assert_same( 'categorie', $remplacer[66] );
		yume_assert_same( 'fiche', $remplacer[2209] );
		yume_assert_same( 'arc', $remplacer[2173] );
		yume_assert_same( 'chapitre', $remplacer[1548] );
		$creer = array_column( $plan['pages']['creer'], null, 'post_name' );
		yume_assert_same( array( 'bibliotheque', 'planning', 'equipe', 'publier', 'compte', 'connexion' ), array_keys( $creer ) );
		yume_assert_contains( '<!-- wp:yume/publish-form /-->', $creer['publier']['post_content'] );
		yume_assert_same( 'equipe', $creer['publier']['parent'] );
		yume_assert_contains( '<!-- wp:yume/account /-->', $creer['connexion']['post_content'] );
	}
);

yume_test(
	'Plan (fixtures) : catégories (Yume News → Sorties), articles reclassés, brouillon d’essai ignoré',
	function () {
		$plan = yume_test_migration_plan();
		$cats = array_column( $plan['categories'], null, 'slug_actuel' );
		yume_assert_same( 'renommer', $cats['yume-news']['action'] );
		yume_assert_same( 'sorties', $cats['yume-news']['slug'] );
		yume_assert_same( 'Sorties', $cats['yume-news']['nom'] );
		yume_assert_same( 'conserver', $cats['actualites']['action'] );
		yume_assert_same( 'supprimer', $cats['non-classe']['action'] );

		$articles = array_column( $plan['articles'], null, 'source_id' );
		yume_assert_same( 'ignorer', $articles[205]['action'] );
		yume_assert_same( 'reclasser', $articles[2564]['action'] );
		yume_assert_same( 'sorties', $articles[2564]['categorie_cible'] );
		yume_assert_same( 'secrets-of-the-silent-witch', $articles[2564]['oeuvre'] );
		yume_assert_same( 'secrets-of-the-silent-witch/arc-7', $articles[2564]['tome'] );
		yume_assert_same( array( 'secrets-of-the-silent-witch/arc-7/8' ), $articles[2564]['chapitres'] );
		yume_assert_same( 'grimgar-of-fantasy-and-ash/tome-9', $articles[2555]['tome'] );
		yume_assert_same( array( 'secrets-of-the-silent-witch/arc-4/1', 'secrets-of-the-silent-witch/arc-4/2' ), $articles[1547]['chapitres'] );
		yume_assert_same( 'actualites', $articles[1712]['categorie_cible'] );
	}
);

yume_test(
	'Plan (fixtures) : sérialisable en JSON, reproductible, réglages, médias et rapport Markdown',
	function () {
		$plan = yume_test_migration_plan();
		$json = wp_json_encode( $plan );
		yume_assert_true( is_string( $json ) && '' !== $json );
		$bis = ( new Migration_Planner(
			yume_test_migration_fixtures(),
			array(
				'genere_le'   => '2026-01-01T00:00:00Z',
				'date_import' => '2026-01-01 00:00:00',
			)
		) )->plan();
		yume_assert_same( $json, wp_json_encode( $bis ) );
		yume_assert_same( 1341, $plan['reglages']['banniere_id'] );
		yume_assert_same( 0, $plan['comptes']['medias']['manquants'] );
		foreach ( array_merge( $plan['oeuvres'], $plan['tomes'], $plan['chapitres'] ) as $element ) {
			yume_assert_true( ! empty( $element['source']['id'] ), 'identifiant source de ' . $element['cle'] );
		}
		$md = Plan_Report::markdown( $plan );
		yume_assert_contains( '# Rapport de migration — yumenovel.fr', $md );
		yume_assert_contains( '## Anomalies et avertissements', $md );
		yume_assert_contains( 'Grimgar of Fantasy and Ash — Tome 9', $md );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Export complet (ignoré si tools/migrate/export/ n'a pas été régénéré)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Export complet : 15 œuvres, 63 chapitres de Silent Witch migrés, Grimgar 9 tomes / 18 liens ClicTune, aucune erreur',
	function () {
		$plan = yume_test_migration_plan_complet();
		if ( null === $plan ) {
			return; // Export complet absent : voir tools/migrate/README.md.
		}
		yume_assert_same( 15, count( $plan['oeuvres'] ) );
		yume_assert_same( 63, $plan['comptes']['chapitres']['migres'] );
		yume_assert_same( 18, $plan['comptes']['chapitres']['planifies'] );
		$migres = array_filter( $plan['chapitres'], static fn( $c ) => 'page' === $c['source']['type'] );
		foreach ( $migres as $c ) {
			yume_assert_same( 'secrets-of-the-silent-witch', $c['oeuvre'] );
			yume_assert_true( '' !== $c['meta']['yume_credits']['traduction'], 'crédits de ' . $c['cle'] );
			yume_assert_true( '' !== $c['meta']['yume_sous_titre'], 'sous-titre de ' . $c['cle'] );
			yume_assert_not_contains( 'wp:image', $c['post']['post_content'], 'navigation retirée de ' . $c['cle'] );
		}
		yume_assert_same( 63, count( array_unique( array_map( static fn( $c ) => $c['source']['id'], $migres ) ) ) );

		$grimgar  = array_filter( $plan['tomes'], static fn( $t ) => 'grimgar-of-fantasy-and-ash' === $t['oeuvre'] );
		$clictune = 0;
		foreach ( $grimgar as $t ) {
			$clictune += (int) str_contains( $t['meta']['yume_lien_pdf'], 'clictune.com' ) + (int) str_contains( $t['meta']['yume_lien_epub'], 'clictune.com' );
		}
		yume_assert_same( 9, count( $grimgar ) );
		yume_assert_same( 18, $clictune );

		$arcs = array_filter( $plan['tomes'], static fn( $t ) => 'secrets-of-the-silent-witch' === $t['oeuvre'] );
		yume_assert_same( 8, count( $arcs ) );

		yume_assert_same( array(), array_values( array_filter( $plan['avertissements'], static fn( $a ) => 'erreur' === $a['niveau'] ) ) );
		yume_assert_same( 0, $plan['comptes']['articles']['sorties_sans_oeuvre'] );
		yume_assert_same( count( $plan['redirections'] ), count( array_unique( array_column( $plan['redirections'], 'source' ) ) ) );
		yume_assert_true( count( $plan['redirections'] ) >= 90 );
		yume_assert_same( 0, $plan['comptes']['medias']['manquants'] );
	}
);
