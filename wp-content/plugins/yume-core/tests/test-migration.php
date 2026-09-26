<?php
/**
 * Tests du module migration.
 *
 * Partie 1 (analyse, classes pures, sans écriture) : utilitaires HTML et blocs, chargeur
 * d'export, analyseurs de hub, de fiche, d'arc, de chapitre et d'article, planificateur et
 * rapport — sur les extraits courts de tools/migrate/fixtures/, puis sur l'export complet
 * s'il est présent (tools/migrate/export/, non versionné : ces tests sont ignorés sinon).
 *
 * Partie 2 (exécution) : la base est peuplée DANS la transaction du test avec l'ancien site
 * (tools/migrate/lib/class-yume-seed-local.php, sans images), puis source base contre export,
 * exécution (œuvres, tomes, chapitres, images, taxonomies, articles, pages, options),
 * idempotence, correspondance des médias, redirections, annulation complète, REST,
 * administration et neutralisation des notifications. Tout est annulé à la fin de chaque test.
 *
 *   tools/localenv/test.sh migration
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Blocks;
use Yume\Core\Migration\Export_Loader;
use Yume\Core\Migration\Html;
use Yume\Core\Migration\Media_Mapper;
use Yume\Core\Migration\Migration_Admin;
use Yume\Core\Migration\Migration_Empreinte;
use Yume\Core\Migration\Migration_Executor;
use Yume\Core\Migration\Migration_Runner;
use Yume\Core\Migration\Migration_State;
use Yume\Core\Migration\Redirections;
use Yume\Core\Migration\Site_Source;
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
 * @throws Yume_Test_Failure Donnée absente ou opération en échec.
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
 * @throws Yume_Test_Failure Donnée absente ou opération en échec.
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
 * @throws Yume_Test_Failure Donnée absente ou opération en échec.
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
			'/grimgar-of-fantasy-and-ash-ln/'              => '/oeuvres/grimgar-of-fantasy-and-ash/',
			'/secrets-of-the-silent-witch-ln/'             => '/oeuvres/secrets-of-the-silent-witch/',
			'/arc-7-tournoi-dechec-silent-witch/'          => '/oeuvres/secrets-of-the-silent-witch/arc-7/',
			'/secrets-of-the-silent-witch-t-3-chapitre-1-2/' => '/lire/secrets-of-the-silent-witch/arc-4/1/',
			'/secrets-of-the-silent-witch-t-7-chapitre-8/' => '/lire/secrets-of-the-silent-witch/arc-7/8/',
			'/yume-ln/'                                    => '/bibliotheque/?type=light-novel',
			'/category/yume-news/'                         => '/category/sorties/',
			'/category/non-classe/'                        => '/category/actualites/',
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
		yume_assert_same( array( 'bibliotheque', 'planning', 'equipe', 'publier', 'membres', 'compte', 'connexion', 'actualites', 'mentions-legales', 'accueil' ), array_keys( $creer ) );
		yume_assert_same( array( 'bibliotheque', 'planning', 'equipe', 'publier', 'membres', 'compte', 'connexion', 'actualites', 'mentions-legales', 'accueil' ), array_column( $plan['pages']['creer'], 'cle' ) );
		yume_assert_contains( '<!-- wp:yume/publish-form /-->', $creer['publier']['post_content'] );
		yume_assert_same( 'equipe', $creer['publier']['parent'] );
		yume_assert_contains( '<!-- wp:yume/team-members /-->', $creer['membres']['post_content'] );
		yume_assert_same( 'equipe', $creer['membres']['parent'] );
		yume_assert_same( '/equipe/membres/', $creer['membres']['url'] );
		yume_assert_contains( '<!-- wp:yume/account /-->', $creer['connexion']['post_content'] );
		yume_assert_same( 'page_for_posts', $creer['actualites']['reglage'] );
		yume_assert_same( 'page_on_front', $creer['accueil']['reglage'] );
		yume_assert_same( '', $creer['accueil']['post_content'] );
		yume_assert_contains( '<!-- wp:heading -->', $creer['mentions-legales']['post_content'] );
		yume_assert_contains( 'Automattic', $creer['mentions-legales']['post_content'] );
		yume_assert_contains( 'href="/contactez-nous/"', $creer['mentions-legales']['post_content'] );
		yume_assert_same( array(), parse_blocks( $creer['mentions-legales']['post_content'] ) === array() ? array( 'vide' ) : array() );
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

/*
 * -----------------------------------------------------------------------------
 * Partie 2 : exécution sur une base peuplée dans la transaction du test
 * -----------------------------------------------------------------------------
 */

if ( ! class_exists( 'Yume_Seed_Local' ) ) {
	require_once dirname( __DIR__, 4 ) . '/tools/migrate/lib/class-yume-seed-local.php';
}

/**
 * Peuple la base (dans la transaction du test) avec l'ancien site des fixtures, sans images.
 *
 * @param string $dossier Dossier d'export (fixtures par défaut).
 * @return array<string,mixed> Rapport du peuplement.
 * @throws Yume_Test_Failure Donnée absente ou opération en échec.
 */
function yume_test_migration_seed( string $dossier = '' ): array {
	global $wp_rewrite;
	if ( Yume_Seed_Local::PERMALIENS !== $wp_rewrite->permalink_structure ) {
		throw new Yume_Test_Failure( 'Permaliens attendus : ' . Yume_Seed_Local::PERMALIENS . ' (tools/localenv/wp.sh les pose à l’installation).' );
	}
	return Yume_Seed_Local::executer(
		'' !== $dossier ? $dossier : yume_test_migration_dossier_fixtures(),
		array(
			'images'   => false,
			'reglages' => false,
		)
	);
}

/**
 * Accepte l'exécution d'un plan des fixtures (qui contient des erreurs volontaires : pages de
 * chapitres absentes des extraits).
 *
 * @param bool $actif Activer ou retirer le filtre.
 */
function yume_test_migration_accepter( bool $actif = true ): void {
	if ( $actif ) {
		add_filter( 'yume_migration_problemes', '__return_empty_array' );
	} else {
		remove_filter( 'yume_migration_problemes', '__return_empty_array' );
	}
}

/**
 * Simule puis exécute la migration jusqu'au bout.
 *
 * @return array<string,mixed> État final.
 */
function yume_test_migration_executer(): array {
	yume_test_migration_accepter();
	try {
		Migration_Runner::simuler();
		Migration_Runner::demarrer_execution();
		return Migration_Runner::terminer();
	} finally {
		yume_test_migration_accepter( false );
	}
}

/**
 * ID du contenu créé pour une clé du plan.
 *
 * @param string $type oeuvre, tome, chapitre ou page.
 * @param string $cle  Clé.
 * @throws Yume_Test_Failure Donnée absente ou opération en échec.
 */
function yume_test_migration_id( string $type, string $cle ): int {
	$id = (int) ( Migration_State::journal()['correspondances'][ $type ][ $cle ] ?? 0 );
	if ( ! $id ) {
		throw new Yume_Test_Failure( "Aucun contenu créé pour $type:$cle." );
	}
	return $id;
}

/**
 * Instantané de la base sur tout ce que la migration peut toucher : contenus (champs et
 * contenu), métadonnées des contenus d'origine, termes et relations, options de lecture.
 *
 * @return array<string,mixed>
 */
function yume_test_migration_instantane(): array {
	global $wpdb;
	$posts = array();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( $wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_title, post_excerpt, post_content, post_date, post_date_gmt, post_modified, post_parent, menu_order, post_author FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) as $ligne ) {
		$ligne['post_content'] = md5( $ligne['post_content'] );
		$posts[ $ligne['ID'] ] = $ligne;
	}
	$meta  = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY post_id, meta_key, meta_value", ARRAY_A );
	$terms = $wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id ORDER BY t.term_id", ARRAY_A );
	$rel   = $wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A );
	// phpcs:enable
	$options = array();
	foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'default_category', 'yume_pages', 'yume_reglages', 'yume_redirections', 'sticky_posts', 'users_can_register', 'default_role', 'posts_per_page' ) as $option ) {
		$valeur             = get_option( $option, '__absente__' );
		$options[ $option ] = is_scalar( $valeur ) ? (string) $valeur : $valeur;
	}
	return compact( 'posts', 'meta', 'terms', 'rel', 'options' );
}

/**
 * Premières différences entre deux instantanés (message lisible).
 *
 * @param array $a Instantané avant.
 * @param array $b Instantané après.
 */
function yume_test_migration_diff( array $a, array $b ): string {
	$diff = array();
	foreach ( $a as $partie => $valeurs ) {
		$avant = array_map( 'wp_json_encode', (array) $valeurs );
		$apres = array_map( 'wp_json_encode', (array) ( $b[ $partie ] ?? array() ) );
		foreach ( array_diff_assoc( $avant, $apres ) as $cle => $v ) {
			$diff[] = "$partie/$cle avant : $v ; après : " . ( $apres[ $cle ] ?? '(absent)' );
		}
		foreach ( array_diff_key( $apres, $avant ) as $cle => $v ) {
			$diff[] = "$partie/$cle en trop : $v";
		}
	}
	return implode( "\n", array_slice( $diff, 0, 12 ) );
}

yume_test(
	'Source base : l’ancien site peuplé depuis les fixtures donne le même plan que l’export (hors champs documentés)',
	function () {
		$seed = yume_test_migration_seed();
		yume_assert_same( 11, $seed['comptes']['pages'] );
		yume_assert_same( 5, $seed['ids_conserves'] - 11, 'ID d’origine des articles' );
		$options = array(
			'genere_le'   => '2026-01-01T00:00:00Z',
			'date_import' => '2026-01-01 00:00:00',
		);
		$export  = Site_Source::export(
			array(
				'domaine'        => 'yumenovel.fr',
				'domaines_alias' => array( 'yumenovel.wordpress.com' ),
				'home'           => 'https://yumenovel.fr',
				'url_medias'     => 'https://yumenovel.wordpress.com/wp-content/uploads/',
			)
		);
		yume_assert_same( array( 11, 5, 3, 48, 1, 1 ), array( count( $export['pages'] ), count( $export['posts'] ), count( $export['categories'] ), count( $export['media'] ), count( $export['navigations'] ), count( $export['template_parts'] ) ) );
		yume_assert_same( 'pub/twentytwentyfour//header', $export['template_parts'][0]['id'] );
		$fichiers = array();
		foreach ( yume_test_migration_fixtures()['media'] as $m ) {
			$fichiers[ $m['id'] ] = '' !== $m['file'] ? $m['file'] : Media_Mapper::suffixe( $m['source_url'] );
		}
		yume_assert_same( $fichiers, array_column( $export['media'], 'file', 'id' ), 'mêmes ID et même _wp_attached_file' );
		$base = ( new Migration_Planner( $export, $options ) )->plan();
		$ref  = ( new Migration_Planner( yume_test_migration_fixtures(), $options ) )->plan();
		foreach ( array( 'exporte_le', 'site_id' ) as $champ ) {
			unset( $base['source'][ $champ ], $ref['source'][ $champ ] );
		}
		yume_assert_same( wp_json_encode( $ref ), wp_json_encode( $base ), 'plan(base) == plan(export)' );

		// Les contenus créés par la migration ne sont jamais relus comme « ancien site ».
		yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_name'  => 'bibliotheque',
				'meta_input' => array( Site_Source::META_CLE => 'page:bibliotheque' ),
			)
		);
		yume_assert_same( 11, count( Site_Source::export()['pages'] ) );
	}
);

yume_test(
	'Exécution : œuvres, tomes, chapitres créés avec les métadonnées du cœur, slugs du plan, images et taxonomies',
	function () {
		yume_test_migration_seed();
		$publies = did_action( 'yume_tome_publie' ) + did_action( 'yume_chapitre_publie' );
		$etat    = yume_test_migration_executer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		$plan = Migration_State::plan();
		yume_assert_same( count( $plan['oeuvres'] ), (int) $etat['comptes']['oeuvres_creees'] );
		yume_assert_same( count( $plan['tomes'] ), (int) $etat['comptes']['tomes_crees'] );
		yume_assert_same( count( $plan['chapitres'] ), (int) $etat['comptes']['chapitres_crees'] );

		$grimgar = yume_test_migration_id( 'oeuvre', 'grimgar-of-fantasy-and-ash' );
		$post    = get_post( $grimgar );
		yume_assert_same( 'yume_oeuvre', $post->post_type );
		yume_assert_same( 'grimgar-of-fantasy-and-ash', $post->post_name );
		yume_assert_same( 'publish', $post->post_status );
		yume_assert_same( 2222, (int) get_post_thumbnail_id( $grimgar ), 'couverture par ID de pièce jointe' );
		yume_assert_same( 2206, (int) get_post_meta( $grimgar, 'yume_banniere_id', true ) );
		yume_assert_same( array( 'light-novel' ), wp_get_object_terms( $grimgar, 'yume_type', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $grimgar, 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( 2209, (int) get_post_meta( $grimgar, Migration_Executor::META_SOURCE, true ) );
		yume_assert_same( 'oeuvre:grimgar-of-fantasy-and-ash', get_post_meta( $grimgar, Migration_Executor::META_CLE, true ) );
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/' ), get_permalink( $grimgar ) );

		$tome9 = yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-9' );
		yume_assert_same( $grimgar, (int) get_post_meta( $tome9, 'yume_oeuvre_id', true ) );
		yume_assert_same( 'tome-9', get_post( $tome9 )->post_name );
		yume_assert_same( 2553, (int) get_post_thumbnail_id( $tome9 ) );
		yume_assert_contains( 'clictune.com', (string) get_post_meta( $tome9, 'yume_lien_pdf', true ) );
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/tome-9/' ), get_permalink( $tome9 ) );
		yume_assert_same( $tome9, url_to_postid( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/tome-9/' ) ) );
		yume_assert_same( 9, count( yume_get_tomes( $grimgar ) ) );

		$sw       = yume_test_migration_id( 'oeuvre', 'secrets-of-the-silent-witch' );
		$arc4     = yume_test_migration_id( 'tome', 'secrets-of-the-silent-witch/arc-4' );
		$chapitre = yume_test_migration_id( 'chapitre', 'secrets-of-the-silent-witch/arc-4/1' );
		yume_assert_same( $arc4, (int) get_post_meta( $chapitre, 'yume_tome_id', true ) );
		yume_assert_same( $sw, (int) get_post_meta( $chapitre, 'yume_oeuvre_id', true ), 'yume_oeuvre_id dérivé par le cœur' );
		yume_assert_same( 1548, (int) get_post_meta( $chapitre, Migration_Executor::META_SOURCE, true ) );
		yume_assert_same( 'chapitre-1', get_post( $chapitre )->post_name );
		yume_assert_same( 'publish', get_post_status( $chapitre ) );
		yume_assert_contains( 'yn-dialogue', get_post( $chapitre )->post_content );
		yume_assert_same( 'migration', get_post_meta( $chapitre, 'yume_source', true )['format'] ?? '' );
		yume_assert_true( (int) get_post_meta( $chapitre, 'yume_nb_mots', true ) > 0 );
		yume_assert_same( home_url( '/lire/secrets-of-the-silent-witch/arc-4/1/' ), get_permalink( $chapitre ) );
		yume_assert_same( $chapitre, url_to_postid( home_url( '/lire/secrets-of-the-silent-witch/arc-4/1/' ) ) );

		$planifie = yume_test_migration_id( 'chapitre', 'secrets-of-the-silent-witch/arc-7/15' );
		yume_assert_same( 'draft', get_post_status( $planifie ) );

		// Aucun événement de publication pendant la migration (contenus anciens, notifier faux).
		yume_assert_same( $publies, did_action( 'yume_tome_publie' ) + did_action( 'yume_chapitre_publie' ) );
		yume_assert_same( 'ignore', get_post_meta( $tome9, '_yume_publie_notifie', true ) );
		// Terme yume_oeuvre_liee né avec l'œuvre (synchronisation du cœur), jamais créé à la main.
		$terme = (int) get_post_meta( $grimgar, '_yume_terme_lie', true );
		yume_assert_true( $terme > 0 );
		yume_assert_same( 'grimgar-of-fantasy-and-ash', get_term( $terme, 'yume_oeuvre_liee' )->slug );
	}
);

yume_test(
	'Exécution : articles reclassés (Sorties / Actualités, œuvre liée), catégories, pages §11, options, anciennes pages',
	function () {
		yume_test_migration_seed();
		$etat = yume_test_migration_executer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );

		$sorties = get_term( 775285387, 'category' );
		yume_assert_same( 'sorties', $sorties->slug );
		yume_assert_same( 'Sorties', $sorties->name );
		yume_assert_same( array( 775285387 ), wp_get_object_terms( 2564, 'category', array( 'fields' => 'ids' ) ) );
		yume_assert_same( array( 'secrets-of-the-silent-witch' ), wp_get_object_terms( 2564, 'yume_oeuvre_liee', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array( 'grimgar-of-fantasy-and-ash' ), wp_get_object_terms( 2555, 'yume_oeuvre_liee', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array( 3402 ), wp_get_object_terms( 1712, 'category', array( 'fields' => 'ids' ) ) );
		yume_assert_same( array(), wp_get_object_terms( 1712, 'yume_oeuvre_liee', array( 'fields' => 'ids' ) ) );
		yume_assert_false( get_term( 6325, 'category' ) instanceof WP_Term, '« Non classé » supprimée' );
		yume_assert_same( 3402, (int) get_option( 'default_category' ) );

		$pages = get_option( 'yume_pages' );
		yume_assert_same( array( 'bibliotheque', 'planning', 'equipe', 'publier', 'membres', 'compte', 'connexion', 'actualites', 'mentions-legales', 'accueil' ), array_keys( $pages ) );
		yume_assert_same( (int) $pages['equipe'], (int) get_post( $pages['publier'] )->post_parent );
		yume_assert_same( home_url( '/equipe/publier/' ), get_permalink( $pages['publier'] ) );
		yume_assert_same( home_url( '/equipe/publier/' ), yume_url_page( 'publier' ) );
		yume_assert_same( (int) $pages['equipe'], (int) get_post( $pages['membres'] )->post_parent );
		yume_assert_same( home_url( '/equipe/membres/' ), yume_url_page( 'membres' ) );
		yume_assert_same( '<!-- wp:yume/library-grid /-->', get_post( $pages['bibliotheque'] )->post_content );
		yume_assert_contains( 'Automattic', get_post( $pages['mentions-legales'] )->post_content );
		yume_assert_same( 'page', get_option( 'show_on_front' ) );
		yume_assert_same( (int) $pages['accueil'], (int) get_option( 'page_on_front' ) );
		yume_assert_same( (int) $pages['actualites'], (int) get_option( 'page_for_posts' ) );
		yume_assert_same( 1341, (int) yume_setting( 'banniere_id' ) );
		yume_assert_same( '1', (string) get_option( 'users_can_register' ), 'inscription des lecteurs ouverte' );
		yume_assert_same( 'subscriber', get_option( 'default_role' ), 'rôle par défaut : Lecteur' );
		yume_assert_same( 12, (int) get_option( 'posts_per_page' ), 'grilles d’actualités : 12 par page' );

		// Anciennes pages remplacées en brouillon, institutionnelles et ignorées intactes.
		foreach ( array( 12, 66, 550, 1548, 2072, 2173, 2209, 2417, 2558 ) as $id ) {
			yume_assert_same( 'draft', get_post_status( $id ), "page $id" );
		}
		yume_assert_same( 'publish', get_post_status( 143 ) );
		yume_assert_same( 'draft', get_post_status( 151 ) );
		yume_assert_same( 'draft', get_post_status( 205 ), 'brouillon d’essai laissé tel quel' );
	}
);

yume_test(
	'Idempotence : relancer l’exécution met à jour sans créer de doublon ; une nouvelle exécution est refusée',
	function () {
		yume_test_migration_seed();
		yume_test_migration_executer();
		$avant   = Migration_State::journal()['correspondances'];
		$compte  = static function (): array {
			$n = array();
			foreach ( array( 'yume_oeuvre', 'yume_tome', 'yume_chapitre', 'page' ) as $type ) {
				$n[ $type ] = array_sum( array_map( 'intval', (array) wp_count_posts( $type ) ) );
			}
			$n['termes'] = (int) wp_count_terms(
				array(
					'taxonomy'   => 'yume_oeuvre_liee',
					'hide_empty' => false,
				)
			);
			return $n;
		};
		$nombres = $compte();
		try {
			Migration_Runner::demarrer_execution();
			throw new Yume_Test_Failure( 'Une deuxième exécution sans --forcer aurait dû être refusée.' );
		} catch ( RuntimeException $e ) {
			yume_assert_contains( 'déjà faite', $e->getMessage() );
		}
		Migration_Runner::demarrer_execution( array( 'forcer' => true ) );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'migre', $etat['statut'] );
		yume_assert_same( $nombres, $compte(), 'aucun doublon' );
		yume_assert_same( $avant, Migration_State::journal()['correspondances'], 'mêmes contenus' );
		yume_assert_same( count( Migration_State::plan()['oeuvres'] ), (int) $etat['comptes']['oeuvres_mises_a_jour'] );
		yume_assert_false( isset( $etat['comptes']['oeuvres_creees'] ) );
		// Contenus retrouvés par leur méta même si le journal est perdu.
		$journal                    = Migration_State::journal();
		$journal['correspondances'] = Migration_State::journal_defaut()['correspondances'];
		Migration_State::enregistrer_journal( $journal );
		Migration_Runner::demarrer_execution( array( 'forcer' => true ) );
		Migration_Runner::terminer();
		yume_assert_same( $nombres, $compte(), 'aucun doublon sans journal' );
	}
);

yume_test(
	'Médias : correspondance par ID, puis par suffixe de _wp_attached_file ; contenu réécrit ; couverture retrouvée',
	function () {
		yume_test_migration_seed();
		$avert   = array();
		$fichier = (string) get_post_meta( 2222, '_wp_attached_file', true );
		yume_assert_true( '' !== $fichier );
		yume_assert_same( 2222, Media_Mapper::trouver( 2222, $fichier, $avert ) );
		yume_assert_same( '2024/10/logo.png', Media_Mapper::suffixe( 'https://yumenovel.wordpress.com/wp-content/uploads/2024/10/logo.png?w=300' ) );

		// La pièce jointe 2222 disparaît et revient sous un autre ID avec le même fichier.
		wp_delete_attachment( 2222, true );
		$nouveau = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Couverture',
				'post_status'    => 'inherit',
			),
			false
		);
		update_post_meta( $nouveau, '_wp_attached_file', $fichier );
		$avert = array();
		yume_assert_same( $nouveau, Media_Mapper::trouver( 2222, $fichier, $avert ) );
		yume_assert_contains( 'retrouvé sous l’ID', implode( ' ', $avert ) );
		yume_assert_same( 0, Media_Mapper::trouver( 999999, '2020/01/absent.png', $avert ) );

		// ID occupé par un autre fichier : le suffixe l'emporte.
		$autre = yume_factory_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			)
		);
		update_post_meta( $autre, '_wp_attached_file', '2024/01/autre.png' );
		yume_assert_same( $nouveau, Media_Mapper::trouver( $autre, $fichier, $avert ) );

		// Réécriture d'un bloc image.
		$contenu = Blocks::image( 2222, 'https://yumenovel.wordpress.com/wp-content/uploads/' . $fichier );
		$remappe = Media_Mapper::remapper_contenu( $contenu, array( 2222 => $nouveau ) );
		yume_assert_contains( '"id":' . $nouveau, $remappe );
		yume_assert_contains( 'wp-image-' . $nouveau . '"', $remappe );
		yume_assert_contains( (string) wp_get_attachment_url( $nouveau ), $remappe );
		yume_assert_same( $contenu, Media_Mapper::remapper_contenu( $contenu, array( 2222 => 2222 ) ) );

		// Exécution d'un plan venu de l'export (URL des médias connues) : la couverture de
		// Grimgar pointe vers la pièce jointe retrouvée par son fichier.
		$etat           = Migration_State::etat_defaut();
		$etat['statut'] = 'en_cours';
		$etat['etape']  = 'preparer';
		$etat['run']    = 'test';
		$moteur         = new Migration_Executor( yume_test_migration_plan(), Migration_State::journal_defaut(), $etat );
		$retablir       = Migration_Runner::neutraliser_notifications();
		try {
			yume_assert_true( $moteur->avancer( 600.0 ), (string) $moteur->etat()['erreur'] );
		} finally {
			$retablir();
		}
		$journal = $moteur->journal();
		yume_assert_same( $nouveau, (int) $journal['medias'][2222] );
		yume_assert_same( 2553, (int) $journal['medias'][2553], 'ID conservé quand il existe' );
		yume_assert_same( $nouveau, (int) get_post_thumbnail_id( (int) $journal['correspondances']['oeuvre']['grimgar-of-fantasy-and-ash'] ) );
	}
);

yume_test(
	'Redirections : table des 301 après exécution, cibles réelles, pagination et flux, requête conservée, CSV Redirection',
	function () {
		yume_test_migration_seed();
		yume_test_migration_executer();
		$plan  = Migration_State::plan();
		$table = Redirections::table();
		yume_assert_same( count( $plan['redirections'] ), count( $table ) );
		$attendu = array(
			'/grimgar-of-fantasy-and-ash-ln/'     => '/oeuvres/grimgar-of-fantasy-and-ash/',
			'/arc-7-tournoi-dechec-silent-witch/' => '/oeuvres/secrets-of-the-silent-witch/arc-7/',
			'/secrets-of-the-silent-witch-t-3-chapitre-1-2/' => '/lire/secrets-of-the-silent-witch/arc-4/1/',
			'/yume-ln/'                           => '/bibliotheque/?type=light-novel',
			'/category/yume-news/'                => '/category/sorties/',
			'/category/non-classe/'               => '/category/actualites/',
		);
		foreach ( $attendu as $source => $cible ) {
			yume_assert_same( home_url( $cible ), Redirections::resoudre( $source ), "redirection de $source" );
		}
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/' ), Redirections::resoudre( '/Grimgar-Of-Fantasy-And-Ash-LN' ) );
		yume_assert_same( home_url( '/category/sorties/page/2/' ), Redirections::resoudre( '/category/yume-news/page/2/' ) );
		yume_assert_same( home_url( '/category/sorties/feed/' ), Redirections::resoudre( '/category/yume-news/feed/' ) );
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/?utm_source=x' ), Redirections::resoudre( '/grimgar-of-fantasy-and-ash-ln/?utm_source=x', 'utm_source=x' ) );
		yume_assert_same( home_url( '/bibliotheque/?type=light-novel' ), Redirections::resoudre( '/yume-ln/', 'a=1' ) );
		yume_assert_same( null, Redirections::resoudre( '/a-propos/' ) );
		yume_assert_same( null, Redirections::resoudre( '/' ) );
		// Chaque cible répond (contenu publié, page ou catégorie existante).
		foreach ( $table as $source => $cible ) {
			$chemin = (string) wp_parse_url( $cible, PHP_URL_PATH );
			if ( str_starts_with( $chemin, '/category/' ) ) {
				yume_assert_true( get_term_by( 'slug', trim( substr( $chemin, 10 ), '/' ), 'category' ) instanceof WP_Term, "catégorie de $source" );
				continue;
			}
			yume_assert_true( url_to_postid( home_url( $chemin ) ) > 0, "cible de $source : $cible" );
		}
		$csv = Redirections::csv( $table );
		yume_assert_contains( "source,target,regex,code\n", $csv );
		yume_assert_contains( "\"/grimgar-of-fantasy-and-ash-ln/\",\"/oeuvres/grimgar-of-fantasy-and-ash/\",0,301\n", $csv );
	}
);

yume_test(
	'Annulation : supprime ce que la migration a créé et restaure pages, articles, catégories, options (base identique)',
	function () {
		yume_test_migration_seed();
		// Réglages d'inscription de l'ancien site : fermés, rôle par défaut différent.
		update_option( 'users_can_register', '0' );
		update_option( 'default_role', 'author' );
		$avant = yume_test_migration_instantane();
		yume_test_migration_executer();
		yume_assert_true( array() !== Migration_State::journal()['empreinte'] );
		yume_assert_same( '1', (string) get_option( 'users_can_register' ) );
		yume_assert_same( 'subscriber', get_option( 'default_role' ) );
		Migration_Runner::demarrer_annulation();
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( array(), $etat['controle']['differences'], 'contrôle par empreinte' );
		yume_assert_true( $etat['controle']['verifie'] );
		$apres = yume_test_migration_instantane();
		// Seules les options d'état de la migration elle-même peuvent différer (elles ne sont pas dans l'instantané).
		yume_assert_same( '', yume_test_migration_diff( $avant, $apres ), 'différences' );
		yume_assert_same( $avant, $apres );
		yume_assert_same( 'Non classé', get_term( 6325, 'category' )->name );
		yume_assert_same( 'yume-news', get_term( 775285387, 'category' )->slug );
		yume_assert_same( '0', (string) get_option( 'users_can_register' ), 'inscription restaurée' );
		yume_assert_same( 'author', get_option( 'default_role' ), 'rôle par défaut restauré' );
		yume_assert_same( null, Redirections::resoudre( '/grimgar-of-fantasy-and-ash-ln/' ) );
		// Une nouvelle simulation puis exécution sont de nouveau possibles.
		yume_assert_same( 'migre', yume_test_migration_executer()['statut'] );
	}
);

yume_test(
	'Neutralisation : pendant un lot, aucun événement de publication, aucun e-mail, aucun appel Discord',
	function () {
		$retablir = Migration_Runner::neutraliser_notifications();
		try {
			yume_assert_false( apply_filters( 'yume_core_notifier', true, get_post( yume_factory_post() ), 'yume_tome_publie' ) );
			yume_assert_false( wp_mail( 'equipe@example.test', 'Sujet', 'Corps' ) );
			$reponse = wp_remote_post( 'https://discord.com/api/webhooks/1/abc', array( 'body' => '{}' ) );
			yume_assert_true( is_wp_error( $reponse ) && 'yume_migration' === $reponse->get_error_code() );
			yume_assert_false( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		} finally {
			$retablir();
		}
		yume_assert_true( apply_filters( 'yume_core_notifier', true, get_post( yume_factory_post() ), 'yume_tome_publie' ) );
	}
);

yume_test(
	'REST : manage_options exigé, confirmation « MIGRER », exécution en plusieurs lots, annulation confirmée',
	function () {
		yume_test_migration_seed();
		Migration_Runner::simuler();
		$admin   = yume_factory_user( 'administrator' );
		$editeur = yume_factory_user( 'yume_editeur' );
		yume_assert_same( 401, yume_rest( 'GET', '/yume/v1/migration' )->get_status() );
		yume_assert_same( 403, yume_rest( 'POST', '/yume/v1/migration/executer', array( 'confirmation' => 'MIGRER' ), $editeur )->get_status() );
		yume_assert_same( 'non_migre', yume_rest( 'GET', '/yume/v1/migration', array(), $admin )->get_data()['statut'] );
		yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/migration/executer', array( 'confirmation' => 'migrer' ), $admin )->get_status() );
		yume_assert_same( 'non_migre', Migration_State::etat()['statut'] );

		$budget = static fn() => 0.0;
		add_filter( 'yume_migration_budget', $budget );
		yume_test_migration_accepter();
		try {
			$reponse = yume_rest( 'POST', '/yume/v1/migration/executer', array( 'confirmation' => 'MIGRER' ), $admin );
			yume_assert_same( 200, $reponse->get_status(), wp_json_encode( $reponse->get_data() ) );
			$lots = 1;
			while ( ! $reponse->get_data()['termine'] && $lots < 2000 ) {
				$reponse = yume_rest( 'POST', '/yume/v1/migration/executer', array(), $admin );
				yume_assert_same( 200, $reponse->get_status(), wp_json_encode( $reponse->get_data() ) );
				yume_assert_same( '', $reponse->get_data()['erreur'] );
				++$lots;
			}
			yume_assert_true( $lots > 10, 'exécution par lots' );
			yume_assert_same( 'migre', $reponse->get_data()['statut'] );
			yume_assert_same( 100, $reponse->get_data()['pourcentage'] );
			yume_assert_same( 409, yume_rest( 'POST', '/yume/v1/migration/executer', array( 'confirmation' => 'MIGRER' ), $admin )->get_status() );

			yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/migration/annuler', array(), $admin )->get_status() );
			$reponse = yume_rest( 'POST', '/yume/v1/migration/annuler', array( 'confirmation' => 'ANNULER' ), $admin );
			while ( ! $reponse->get_data()['termine'] && $lots < 4000 ) {
				$reponse = yume_rest( 'POST', '/yume/v1/migration/annuler', array(), $admin );
				++$lots;
			}
			yume_assert_same( 'annule', $reponse->get_data()['statut'] );
			yume_assert_same( array(), $reponse->get_data()['controle']['differences'] );
		} finally {
			remove_filter( 'yume_migration_budget', $budget );
			yume_test_migration_accepter( false );
		}
	}
);

yume_test(
	'Reprise : une exécution interrompue (verrou, erreur) reprend au même élément ; l’élément en erreur peut être ignoré',
	function () {
		yume_test_migration_seed();
		yume_test_migration_accepter();
		$budget = static fn() => 0.0;
		add_filter( 'yume_migration_budget', $budget );
		try {
			Migration_Runner::simuler();
			Migration_Runner::demarrer_execution();
			Migration_Runner::lot();
			Migration_Runner::lot();
			$etat = Migration_State::etat();
			yume_assert_same( 'en_cours', $etat['statut'] );
			yume_assert_same( 'oeuvres', $etat['etape'] );
			yume_assert_same( 1, $etat['curseur'] );
			yume_assert_same( 2, $etat['progression']['fait'] );

			// Verrou pris par un autre lot : refus explicite (409), rien ne bouge.
			add_option( Migration_State::OPTION_VERROU, time() . '|autre', '', false );
			try {
				Migration_Runner::lot();
				throw new Yume_Test_Failure( 'Le verrou aurait dû bloquer le lot.' );
			} catch ( RuntimeException $e ) {
				yume_assert_same( 409, $e->getCode() );
			}
			delete_option( Migration_State::OPTION_VERROU );
			// Verrou abandonné (requête tuée par l'hébergeur) : repris après VERROU_DUREE secondes.
			add_option( Migration_State::OPTION_VERROU, ( time() - Migration_State::VERROU_DUREE - 5 ) . '|ancien', '', false );
			$etat = Migration_Runner::lot();
			yume_assert_same( '', $etat['erreur'] );
			yume_assert_false( get_option( Migration_State::OPTION_VERROU ), 'verrou rendu après le lot' );
			$jeton = Migration_State::verrouiller();
			yume_assert_true( '' !== $jeton );
			yume_assert_same( '', Migration_State::verrouiller(), 'un seul verrou à la fois' );
			Migration_State::deverrouiller( $jeton );

			// Avance jusqu'au dernier tome de Grimgar (aucun chapitre n'en dépend).
			$garde = 0;
			while ( ! ( 'tomes' === $etat['etape'] && 8 === (int) $etat['curseur'] ) && $garde++ < 100 ) {
				$etat = Migration_Runner::lot();
			}
			yume_assert_same( 'grimgar-of-fantasy-and-ash/tome-9', Migration_State::plan()['tomes'][8]['cle'] );

			// Erreur sur un élément : l'état garde l'erreur et le curseur ; « ignorer » passe à la suite.
			add_filter( 'wp_insert_post_empty_content', '__return_true' );
			$etat = Migration_Runner::lot();
			remove_filter( 'wp_insert_post_empty_content', '__return_true' );
			yume_assert_contains( 'Tomes', $etat['erreur'] );
			yume_assert_same( 8, $etat['curseur'] );
			yume_assert_same( $etat, Migration_State::etat(), 'erreur et curseur enregistrés' );
			$etat = Migration_Runner::lot( array( 'ignorer' => true ) );
			yume_assert_same( '', $etat['erreur'] );
			yume_assert_same( 1, (int) $etat['comptes']['ignores'] );
			remove_filter( 'yume_migration_budget', $budget );
			$etat = Migration_Runner::terminer();
			yume_assert_same( 'migre', $etat['statut'] );
			yume_assert_false( isset( Migration_State::journal()['correspondances']['tome']['grimgar-of-fantasy-and-ash/tome-9'] ) );
			yume_assert_same( 8, count( yume_get_tomes( yume_test_migration_id( 'oeuvre', 'grimgar-of-fantasy-and-ash' ) ) ) );
		} finally {
			remove_filter( 'yume_migration_budget', $budget );
			yume_test_migration_accepter( false );
		}
	}
);

yume_test(
	'Administration : page Yume → Migrer (rapport, statuts à valider, confirmation), choix de statut appliqué',
	function () {
		yume_test_migration_seed();
		$admin = yume_factory_user( 'administrator' );
		wp_set_current_user( $admin );
		ob_start();
		Migration_Admin::afficher();
		$html = (string) ob_get_clean();
		yume_assert_contains( 'Migrer l’ancien site', $html );
		yume_assert_contains( 'Lancez d’abord une simulation', $html );
		yume_assert_contains( 'name="action" value="yume_migration_simuler"', $html );

		Migration_Runner::simuler();
		ob_start();
		Migration_Admin::afficher();
		$html = (string) ob_get_clean();
		yume_assert_contains( 'Comptes', $html );
		yume_assert_contains( 'Grimgar of Fantasy and Ash', $html );
		yume_assert_contains( '/grimgar-of-fantasy-and-ash-ln/', $html );
		yume_assert_contains( 'Erreurs (à corriger avant d’exécuter)', $html );
		yume_assert_contains( 'Exécution impossible', $html, 'les fixtures ont des erreurs volontaires' );
		yume_test_migration_accepter();
		ob_start();
		Migration_Admin::afficher();
		$html = (string) ob_get_clean();
		yume_test_migration_accepter( false );
		yume_assert_contains( 'Tapez MIGRER pour confirmer', $html );
		yume_assert_contains( 'data-yume-form="executer"', $html );
		yume_assert_contains( 'yume_migration_telecharger', $html );

		// Statuts à valider (export complet : Gimai, Roshidere, Otonari, Mikadono, Chiramune, Raven, SukaMoka).
		$complet = yume_test_migration_plan_complet();
		if ( null !== $complet ) {
			$cles = array_column( Migration_Admin::statuts_a_valider( $complet ), 'cle' );
			foreach ( array( 'gimai-seikatsu', 'roshidere', 'otonari-no-tenshi-sama', 'mikadono-sanshimai-wa-angai-choroi', 'chitose-is-in-the-ramune-bottle', 'raven-of-the-inner-palace', 'sukamoka' ) as $cle ) {
				yume_assert_true( in_array( $cle, $cles, true ), "statut à valider : $cle" );
			}
		}

		// Choix de l'équipe appliqué à l'exécution.
		Migration_State::enregistrer_choix( array( 'grimgar-of-fantasy-and-ash' => 'terminee' ) );
		wp_set_current_user( 0 );
		yume_test_migration_executer();
		yume_assert_same( array( 'terminee' ), wp_get_object_terms( yume_test_migration_id( 'oeuvre', 'grimgar-of-fantasy-and-ash' ), 'yume_statut', array( 'fields' => 'slugs' ) ) );

		wp_set_current_user( $admin );
		ob_start();
		Migration_Admin::afficher();
		$html = (string) ob_get_clean();
		yume_assert_contains( 'Migré le', $html );
		yume_assert_contains( 'Annuler la migration', $html );
		yume_assert_contains( 'Redirections actives', $html );
		yume_assert_not_contains( 'Tapez MIGRER pour confirmer', $html );
	}
);

yume_test(
	'Plan : une redirection ne vise jamais un tome ou un chapitre non publié (arc planifié → œuvre)',
	function () {
		$plan = yume_test_migration_plan_complet();
		if ( null === $plan ) {
			return; // Export complet absent.
		}
		$urls = array();
		foreach ( array_merge( $plan['oeuvres'], $plan['tomes'], $plan['chapitres'] ) as $e ) {
			$urls[ $e['url'] ] = $e['post']['post_status'];
		}
		foreach ( $plan['redirections'] as $r ) {
			if ( isset( $urls[ $r['cible'] ] ) ) {
				yume_assert_same( 'publish', $urls[ $r['cible'] ], 'cible de ' . $r['source'] );
			}
		}
		$cibles = array_column( $plan['redirections'], 'cible', 'source' );
		yume_assert_same( '/oeuvres/secrets-of-the-silent-witch/', $cibles['/arc-8-la-vie-nocturne-silent-witch/'] );
		yume_assert_same( 92, count( $plan['redirections'] ) );
		yume_assert_same( 10, count( $plan['pages']['creer'] ) );
	}
);

yume_test(
	'Bout en bout (export complet, dans la transaction) : 15 œuvres, 55 tomes, 81 chapitres, 92 redirections, annulation exacte',
	function () {
		$dossier = dirname( __DIR__, 4 ) . '/tools/migrate/export';
		if ( ! is_readable( $dossier . '/pages.json' ) ) {
			return; // Export complet absent : voir tools/migrate/README.md.
		}
		yume_test_migration_seed( $dossier );
		$avant = yume_test_migration_instantane();
		Migration_Runner::simuler();
		yume_assert_same( array(), Migration_Executor::problemes( Migration_State::plan() ) );
		Migration_Runner::demarrer_execution();
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		$c = $etat['comptes'];
		yume_assert_same( array( 15, 55, 81, 181, 10, 90, 92 ), array( $c['oeuvres_creees'], $c['tomes_crees'], $c['chapitres_crees'], $c['articles_reclasses'], $c['pages_creees'], $c['pages_depubliees'], $c['redirections'] ) );
		yume_assert_same( 15, (int) wp_count_posts( 'yume_oeuvre' )->publish );
		yume_assert_same( array( 53, 2 ), array( (int) wp_count_posts( 'yume_tome' )->publish, (int) wp_count_posts( 'yume_tome' )->draft ) );
		yume_assert_same( array( 63, 18 ), array( (int) wp_count_posts( 'yume_chapitre' )->publish, (int) wp_count_posts( 'yume_chapitre' )->draft ) );
		foreach ( Redirections::table() as $source => $cible ) {
			$chemin = (string) wp_parse_url( $cible, PHP_URL_PATH );
			if ( ! str_starts_with( $chemin, '/category/' ) ) {
				yume_assert_true( url_to_postid( home_url( $chemin ) ) > 0, "cible de $source : $cible" );
			}
		}
		Migration_Runner::demarrer_annulation();
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( array(), $etat['controle']['differences'] );
		yume_assert_same( '', yume_test_migration_diff( $avant, yume_test_migration_instantane() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Non-régression de la revue de la migration (MIG-D-1 à MIG-D-11, UX-6)
 * -----------------------------------------------------------------------------
 */

/**
 * Export des fixtures modifié par une fonction (copie).
 *
 * @param callable $modifier Reçoit l'export par référence.
 * @return array<string,mixed>
 */
function yume_test_migration_export_modifie( callable $modifier ): array {
	$export = yume_test_migration_fixtures();
	$modifier( $export );
	return $export;
}

/**
 * Plan d'un export (dates figées).
 *
 * @param array $export Export.
 * @return array<string,mixed>
 */
function yume_test_migration_plan_de( array $export ): array {
	return ( new Migration_Planner(
		$export,
		array(
			'genere_le'   => '2026-01-01T00:00:00Z',
			'date_import' => '2026-01-01 00:00:00',
		)
	) )->plan();
}

/**
 * Remplace un texte dans le contenu d'une page de l'export.
 *
 * @param array  $export Export (par référence).
 * @param int    $id     Page.
 * @param string $avant  Texte cherché.
 * @param string $apres  Remplacement.
 * @throws Yume_Test_Failure Texte absent.
 */
function yume_test_migration_remplacer_page( array &$export, int $id, string $avant, string $apres ): void {
	foreach ( $export['pages'] as $i => $page ) {
		if ( $id === $page['id'] ) {
			if ( ! str_contains( (string) $page['content'], $avant ) ) {
				throw new Yume_Test_Failure( "« $avant » absent de la page $id." );
			}
			$export['pages'][ $i ]['content'] = str_replace( $avant, $apres, (string) $page['content'] );
			return;
		}
	}
	throw new Yume_Test_Failure( "Page $id absente." );
}

/**
 * Avertissements d'un plan dont le message contient un texte.
 *
 * @param array  $plan  Plan.
 * @param string $texte Texte.
 * @return array<int,array<string,mixed>>
 */
function yume_test_migration_avertissements( array $plan, string $texte ): array {
	return array_values( array_filter( $plan['avertissements'], static fn( $a ) => str_contains( (string) $a['message'], $texte ) ) );
}

yume_test(
	'MIG-D-1 : un lien PDF d’article n’est repris que d’une sortie de tome, jamais pour une œuvre licenciée ni un tome « Acheter »',
	function () {
		// Tome 9 de Grimgar sans lien PDF sur la fiche : l'article de sortie du tome le donne.
		$sans_pdf = static function ( array &$e ): void {
			yume_test_migration_remplacer_page( $e, 2209, 'href="https://www.clictune.com/nwEd"', 'href=""' );
		};
		$plan     = yume_test_migration_plan_de( yume_test_migration_export_modifie( $sans_pdf ) );
		$tome     = yume_test_migration_par_cle( $plan['tomes'], 'grimgar-of-fantasy-and-ash/tome-9' );
		yume_assert_same( 'https://www.clictune.com/nwEd', $tome['meta']['yume_lien_pdf'], 'sortie du tome : lien repris' );
		yume_assert_false( $tome['achat_fiche'] );

		// Même tome, marqué « ==> Acheter <== » sur la fiche (sans lien) : rien n'est repris.
		$plan = yume_test_migration_plan_de(
			yume_test_migration_export_modifie(
				static function ( array &$e ) use ( $sans_pdf ): void {
					$sans_pdf( $e );
					yume_test_migration_remplacer_page( $e, 2209, '<strong>Tome 9</strong>', '<strong>Tome 9 | ==&gt; Acheter &lt;==</strong>' );
				}
			)
		);
		$tome = yume_test_migration_par_cle( $plan['tomes'], 'grimgar-of-fantasy-and-ash/tome-9' );
		yume_assert_true( $tome['achat_fiche'] );
		yume_assert_same( '', $tome['meta']['yume_lien_pdf'], 'tome proposé à l’achat : aucun lien repris' );
		$refus = yume_test_migration_avertissements( $plan, 'non repris' );
		yume_assert_true( (bool) $refus );
		yume_assert_same( 'attention', $refus[0]['niveau'] );
		yume_assert_contains( 'achat', $refus[0]['message'] );

		// Œuvre licenciée, article de chapitre : refus (niveaux attention / info).
		$refus   = new ReflectionMethod( Migration_Planner::class, 'refus_lien_article' );
		$planner = new Migration_Planner( yume_test_migration_fixtures() );
		$refus->setAccessible( true );
		$tome    = array(
			'oeuvre'      => 'x',
			'achat_fiche' => false,
		);
		$oeuvres = array( 'x' => array( 'termes' => array( 'yume_statut' => array( 'licenciee' ) ) ) );
		yume_assert_same( 'attention', $refus->invoke( $planner, $tome, $oeuvres, array( 'type_sortie' => 'tome' ) )['niveau'] );
		$oeuvres['x']['termes']['yume_statut'] = array( 'en-cours' );
		yume_assert_same( 'info', $refus->invoke( $planner, $tome, $oeuvres, array( 'type_sortie' => 'chapitres' ) )['niveau'] );
		yume_assert_same( null, $refus->invoke( $planner, $tome, $oeuvres, array( 'type_sortie' => 'tome_relie' ) ) );

		// Export complet : Roshidere T3 (licenciée, « Acheter ») n'a plus le lien d'un chapitre.
		$complet = yume_test_migration_plan_complet();
		if ( null !== $complet ) {
			$t3 = yume_test_migration_par_cle( $complet['tomes'], 'roshidere/tome-3' );
			yume_assert_same( '', $t3['meta']['yume_lien_pdf'] );
			yume_assert_true( $t3['achat_fiche'] );
			yume_assert_same( array(), yume_test_migration_avertissements( $complet, 'repris de l’article « Nouvelle sortie chapitre (Roshidere' ) );
		}
	}
);

yume_test(
	'MIG-D-11 : les liens des auteurs, illustrateurs et éditeurs VO de la fiche rejoignent les liens de l’œuvre',
	function () {
		$page  = array(
			'id'             => 97,
			'slug'           => 'roshidere-ln',
			'link'           => 'https://yumenovel.fr/roshidere-ln/',
			'title'          => 'Roshidere (LN)',
			'date'           => '2024-10-01T00:00:00',
			'featured_media' => 0,
			'content'        => "<!-- wp:paragraph -->\n<p><strong>Noms</strong> : Roshidere<br><strong>Auteur</strong> : <a href=\"https://anilist.co/staff/211473/Sunsunsun-\">SUNSUNSUN</a><br><strong>Illustrations</strong> : <a href=\"https://anilist.co/staff/119248/Momoco\">Momoco</a><br><strong>Éditeur VO</strong> : <a href=\"https://sneakerbunko.jp/\">Kadokawa Sneaker Bunko</a></p>\n<!-- /wp:paragraph -->",
		);
		$fiche = Legacy_Oeuvre_Parser::parse( $page );
		yume_assert_same( 'SUNSUNSUN', $fiche['auteur'] );
		$liens = array_column( $fiche['liens'], 'label', 'url' );
		yume_assert_same( 'Auteur : SUNSUNSUN (AniList)', $liens['https://anilist.co/staff/211473/Sunsunsun-'] ?? '' );
		yume_assert_same( 'Illustrations : Momoco (AniList)', $liens['https://anilist.co/staff/119248/Momoco'] ?? '' );
		yume_assert_same( 'Éditeur VO : Kadokawa Sneaker Bunko (sneakerbunko.jp)', $liens['https://sneakerbunko.jp/'] ?? '' );

		$complet = yume_test_migration_plan_complet();
		if ( null !== $complet ) {
			$urls = array();
			foreach ( $complet['oeuvres'] as $o ) {
				foreach ( $o['meta']['yume_liens'] as $l ) {
					$urls[] = $l['url'];
				}
			}
			foreach ( array( 'https://anilist.co/staff/211473/Sunsunsun-', 'https://www.nautiljon.com/societes/dengeki+bunko,16298.html', 'https://anilist.co/staff/313402/Tama-Masaki' ) as $url ) {
				yume_assert_true( in_array( $url, $urls, true ), "lien gardé : $url" );
			}
		}
	}
);

yume_test(
	'MIG-D-10 : comptes du rapport et de l’administration en libellés français (Brouillon, Abandonnée, Light novel)',
	function () {
		yume_assert_same( 'Abandonnée', Plan_Report::libelle( 'statut', 'abandonnee' ) );
		yume_assert_same( 'Brouillon', Plan_Report::libelle( 'publication', 'draft' ) );
		yume_assert_same( 'Light novel', Plan_Report::libelle( 'type', 'light-novel' ) );
		yume_assert_same( 'clictune.com', Plan_Report::libelle( '', 'clictune.com' ) );
		$md = Plan_Report::markdown( yume_test_migration_plan() );
		foreach ( array( 'draft : ', 'publish : ', 'en-cours : ', 'light-novel : ' ) as $brut ) {
			yume_assert_not_contains( $brut, $md, "clé brute « $brut »" );
		}
		yume_assert_contains( 'Publié : ', $md );
		yume_assert_contains( 'Light novel : ', $md );
		$complet = yume_test_migration_plan_complet();
		if ( null !== $complet ) {
			yume_assert_contains( '→ « Abandonnée » retenu', Plan_Report::markdown( $complet ) );
			yume_assert_not_contains( 'abandonnee retenu', Plan_Report::markdown( $complet ) );
		}

		yume_test_migration_seed();
		Migration_Runner::simuler();
		wp_set_current_user( yume_factory_user( 'administrator' ) );
		ob_start();
		Migration_Admin::afficher();
		$html = (string) ob_get_clean();
		yume_assert_contains( 'Light novel : ', $html );
		yume_assert_contains( 'Publié : ', $html );
		yume_assert_not_contains( 'publish : ', $html );
		yume_assert_not_contains( 'light-novel : ', $html );
	}
);

yume_test(
	'MIG-D-6 : un média absent sous son ID n’empêche plus l’exécution et est retrouvé par son fichier',
	function () {
		// Plan : couverture du tome 9 absente de la médiathèque → « attention », URL connue.
		$plan = yume_test_migration_plan_de(
			yume_test_migration_export_modifie(
				static function ( array &$e ): void {
					$e['media'] = array_values( array_filter( $e['media'], static fn( $m ) => 2553 !== (int) $m['id'] ) );
				}
			)
		);
		$ref  = array_values( array_filter( $plan['medias']['references'], static fn( $r ) => 2553 === $r['id'] ) )[0];
		yume_assert_false( $ref['existe'] );
		yume_assert_contains( '2026/09/grimgart9.jpg', $ref['url'], 'URL tirée du src de l’image' );
		$avert = yume_test_migration_avertissements( $plan, 'Média 2553' );
		yume_assert_same( 'attention', $avert[0]['niveau'] );
		yume_assert_not_contains( 'exportée', $avert[0]['message'] );
		foreach ( Migration_Executor::problemes( $plan ) as $probleme ) {
			yume_assert_not_contains( 'Média', $probleme );
		}

		// Exécution sur la base : la pièce jointe 2553 a changé d'ID (même fichier).
		yume_test_migration_seed();
		$fichier = (string) get_post_meta( 2553, '_wp_attached_file', true );
		wp_delete_attachment( 2553, true );
		$nouveau = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'grimgart9',
				'post_status'    => 'inherit',
			),
			false
		);
		update_post_meta( $nouveau, '_wp_attached_file', $fichier );
		$etat = yume_test_migration_executer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( $nouveau, (int) get_post_thumbnail_id( yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-9' ) ) );
	}
);

yume_test(
	'MIG-D-7 / UX-6 : pages conservées sans couleurs en ligne ni lien-image muet ; contenu d’origine rendu à l’annulation',
	function () {
		$source = '<!-- wp:group {"style":{"color":{"background":"#efe7fb"},"spacing":{"padding":{"top":"1.5rem"}}},"textColor":"white","layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group has-white-color has-text-color has-background" style="background-color:#efe7fb;padding-top:1.5rem"><!-- wp:heading {"level":3,"fontSize":"large"} -->'
			. '<h3 class="wp-block-heading has-large-font-size">La direction</h3><!-- /wp:heading --></div><!-- /wp:group -->'
			. '<!-- wp:image {"id":48,"linkDestination":"custom"} --><figure class="wp-block-image size-large"><a href="https://discord.gg/tuMB3rmmWB"><img src="https://yumenovel.wordpress.com/wp-content/uploads/2024/10/yumediscord2.png?w=514" alt="" class="wp-image-48" /></a></figure><!-- /wp:image -->';
		$propre = Html::alt_liens_images( Html::sans_couleurs( $source ) );
		yume_assert_not_contains( '#efe7fb', $propre );
		yume_assert_not_contains( 'has-background', $propre );
		yume_assert_not_contains( 'has-text-color', $propre );
		yume_assert_not_contains( 'textColor', $propre );
		yume_assert_contains( 'padding-top:1.5rem', $propre, 'autres styles gardés' );
		yume_assert_contains( '"spacing":{"padding":{"top":"1.5rem"}}', $propre );
		yume_assert_contains( 'has-large-font-size', $propre );
		yume_assert_contains( 'alt="Rejoindre le serveur Discord de Yume Novel"', $propre );
		yume_assert_same( 1, Html::liens_images_sans_alt( $source ) );
		yume_assert_same( 0, Html::liens_images_sans_alt( $propre ) );
		$blocs = parse_blocks( $propre );
		yume_assert_same( 'core/group', $blocs[0]['blockName'] );
		yume_assert_false( isset( $blocs[0]['attrs']['style']['color'] ) );

		// Exécution : la page conservée « La Yume Novel » (143) reçoit ce contenu avant la simulation.
		yume_test_migration_seed();
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $source ), array( 'ID' => 143 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( 143 );
		$avant = yume_test_migration_instantane();
		$plan  = Migration_Runner::calculer_plan();
		$page  = array_values( array_filter( $plan['pages']['conserver'], static fn( $p ) => 143 === $p['id'] ) )[0];
		yume_assert_contains( 'retirées à l’exécution', implode( ' ', $page['remarques'] ) );
		$etat = yume_test_migration_executer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( $propre, get_post( 143 )->post_content );
		yume_assert_same( 'publish', get_post_status( 143 ) );
		Migration_Runner::demarrer_annulation();
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( $source, get_post( 143 )->post_content );
		yume_assert_same( array(), $etat['controle']['differences'] );
		yume_assert_same( '', yume_test_migration_diff( $avant, yume_test_migration_instantane() ) );
	}
);

yume_test(
	'MIG-D-9 : l’adresse courte ?page_id=N d’une page remplacée redirige vers sa nouvelle adresse',
	function () {
		yume_test_migration_seed();
		yume_test_migration_executer();
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/' ), Redirections::resoudre( '/', 'page_id=2209' ) );
		yume_assert_same( home_url( '/lire/secrets-of-the-silent-witch/arc-4/1/' ), Redirections::resoudre( '/', 'page_id=1548' ) );
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/?utm_source=x' ), Redirections::resoudre( '/', 'page_id=2209&utm_source=x' ) );
		yume_assert_same( home_url( '/oeuvres/grimgar-of-fantasy-and-ash/' ), Redirections::resoudre( '/', 'p=2209' ) );
		yume_assert_same( null, Redirections::resoudre( '/', 'page_id=143' ), 'page conservée, publiée' );
		yume_assert_same( null, Redirections::resoudre( '/', 'page_id=2209&preview=true' ) );
		yume_assert_same( null, Redirections::resoudre( '/', 'p=2555' ), 'article publié' );
		yume_assert_same( null, Redirections::resoudre( '/' ) );
		Migration_Runner::demarrer_annulation();
		Migration_Runner::terminer();
		yume_assert_same( null, Redirections::resoudre( '/', 'page_id=2209' ) );
		yume_assert_false( get_option( Redirections::OPTION_IDS ) );
	}
);

yume_test(
	'MIG-D-4 : ignorer un tome ignore ses chapitres d’un coup ; les anciennes pages sans remplaçant restent en ligne',
	function () {
		yume_test_migration_seed();
		$echec = static function ( $vide, $postarr ) {
			return ( 'yume_tome' === ( $postarr['post_type'] ?? '' ) && 'arc-4' === ( $postarr['post_name'] ?? '' ) ) ? true : $vide;
		};
		add_filter( 'wp_insert_post_empty_content', $echec, 10, 2 );
		yume_test_migration_accepter();
		try {
			Migration_Runner::simuler();
			Migration_Runner::demarrer_execution();
			$etat    = Migration_Runner::terminer();
			$erreurs = 0;
			while ( '' !== (string) $etat['erreur'] && $erreurs < 20 ) {
				++$erreurs;
				Migration_Runner::lot( array( 'ignorer' => true ) );
				$etat = Migration_Runner::terminer();
			}
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $echec, 10 );
			yume_test_migration_accepter( false );
		}
		yume_assert_same( 1, $erreurs, 'une seule erreur à ignorer (le tome)' );
		yume_assert_same( 'migre', $etat['statut'] );
		$chapitres = count( array_filter( Migration_State::plan()['chapitres'], static fn( $c ) => 'secrets-of-the-silent-witch/arc-4' === $c['tome'] ) );
		yume_assert_true( $chapitres > 0 );
		yume_assert_same( 1 + $chapitres, (int) $etat['comptes']['ignores'] );
		yume_assert_same( 1 + $chapitres, (int) $etat['ignores'] );
		// Page de l'arc 4 et page de son chapitre 1 : toujours en ligne, sans redirection.
		yume_assert_same( 'publish', get_post_status( 2072 ) );
		yume_assert_same( 'publish', get_post_status( 1548 ) );
		yume_assert_same( null, Redirections::resoudre( '/arc-4-bal-de-lacademie-silent-witch/' ) );
		yume_assert_same( null, Redirections::resoudre( '/secrets-of-the-silent-witch-t-3-chapitre-1-2/' ) );
		yume_assert_same( null, Redirections::resoudre( '/', 'page_id=1548' ) );
		// Les autres anciennes pages sont bien remplacées.
		yume_assert_same( 'draft', get_post_status( 2209 ) );
		$resume = Migration_Runner::resume( $etat );
		yume_assert_contains( 'ignorés', $resume['statut_libelle'] );
		$dernier = end( $etat['messages'] );
		yume_assert_same( 'avertissement', $dernier['type'] );
		yume_assert_contains( 'élément(s) ignoré(s)', $dernier['texte'] );
		// L'annulation remet tout en place.
		Migration_Runner::demarrer_annulation();
		yume_assert_same( 'annule', Migration_Runner::terminer()['statut'] );
		yume_assert_same( 'publish', get_post_status( 2209 ) );
	}
);

yume_test(
	'MIG-D-3 : la relance (--forcer) garde tels quels les contenus modifiés depuis la migration',
	function () {
		yume_test_migration_seed();
		yume_test_migration_executer();
		$planifie = yume_test_migration_id( 'chapitre', 'secrets-of-the-silent-witch/arc-7/15' );
		$tome9    = yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-9' );
		$arc4     = yume_test_migration_id( 'tome', 'secrets-of-the-silent-witch/arc-4' );
		yume_assert_true( '' !== (string) get_post_meta( $planifie, Migration_Executor::META_EMPREINTE, true ), 'empreinte prise en fin d’exécution' );
		yume_assert_false( Migration_Executor::modifie_depuis_migration( $tome9, '' ) );
		wp_update_post(
			array(
				'ID'           => $planifie,
				'post_content' => '<!-- wp:paragraph --><p>Texte du chapitre 15 traduit après la migration.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $tome9, 'yume_lien_pdf', 'https://example.test/nouveau.pdf' );
		update_post_meta( $arc4, 'yume_etape', 'relecture' );
		wp_update_term( 775285387, 'category', array( 'name' => 'Sorties LN' ) );
		// Contenu migré par une version sans empreinte : une métadonnée changée suffit.
		$tome1 = yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-1' );
		delete_post_meta( $tome1, Migration_Executor::META_EMPREINTE );
		update_post_meta( $tome1, 'yume_lien_epub', 'https://example.test/t1.epub' );
		wp_set_object_terms( 2555, array( 3402 ), 'category', false );
		yume_assert_true( Migration_Executor::modifie_depuis_migration( $tome9, '' ) );

		Migration_Runner::demarrer_execution( array( 'forcer' => true ) );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( 4, (int) ( $etat['comptes']['modifies_conserves'] ?? 0 ), (string) wp_json_encode( $etat['comptes'] ) );
		yume_assert_same( 'publish', get_post_status( $planifie ) );
		yume_assert_contains( 'traduit après la migration', get_post( $planifie )->post_content );
		yume_assert_same( 'https://example.test/nouveau.pdf', get_post_meta( $tome9, 'yume_lien_pdf', true ) );
		yume_assert_same( 'relecture', get_post_meta( $arc4, 'yume_etape', true ) );
		yume_assert_same( 'Sorties LN', get_term( 775285387, 'category' )->name, 'catégorie renommée depuis : gardée' );
		yume_assert_same( array( 3402 ), wp_get_object_terms( 2555, 'category', array( 'fields' => 'ids' ) ), 'article reclassé depuis : gardé' );
		$plan = Migration_State::plan();
		yume_assert_same( count( $plan['oeuvres'] ), (int) $etat['comptes']['oeuvres_mises_a_jour'] );
		yume_assert_same( count( $plan['tomes'] ) - 3, (int) $etat['comptes']['tomes_mis_a_jour'] );
		yume_assert_same( 'https://example.test/t1.epub', get_post_meta( $tome1, 'yume_lien_epub', true ) );
		yume_assert_same( count( $plan['chapitres'] ) - 1, (int) $etat['comptes']['chapitres_mis_a_jour'] );
		// Une seconde relance ne réécrit toujours pas ces contenus (empreinte reprise en fin).
		Migration_Runner::demarrer_execution( array( 'forcer' => true ) );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 4, (int) ( $etat['comptes']['modifies_conserves'] ?? 0 ), (string) wp_json_encode( $etat['comptes'] ) );
		yume_assert_same( 'publish', get_post_status( $planifie ) );
	}
);

yume_test(
	'MIG-D-2 : l’annulation d’un site utilisé est refusée sans confirmation, puis conserve les œuvres utilisées',
	function () {
		global $wpdb;
		yume_test_migration_seed();
		yume_test_migration_executer();
		$grimgar = yume_test_migration_id( 'oeuvre', 'grimgar-of-fantasy-and-ash' );
		$tome9   = yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-9' );
		$tome1   = yume_test_migration_id( 'tome', 'grimgar-of-fantasy-and-ash/tome-1' );
		$sw      = yume_test_migration_id( 'oeuvre', 'secrets-of-the-silent-witch' );
		$sw_ch   = yume_test_migration_id( 'chapitre', 'secrets-of-the-silent-witch/arc-4/1' );
		yume_assert_same( array(), Migration_Runner::dependances_annulation()['conserver'], 'site non utilisé' );

		// Utilisation du site : chapitre importé dans le tome 9, commentaire, favori d'un lecteur.
		$ajoute = yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_status' => 'publish',
				'post_title'  => 'Chapitre importé',
				'meta_input'  => array( 'yume_tome_id' => $tome9 ),
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID'  => $grimgar,
				'comment_content'  => 'Merci !',
				'comment_approved' => 1,
			)
		);
		$favoris = $wpdb->prefix . 'yume_favoris';
		$table   = $favoris === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $favoris ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $table ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$favoris,
				array(
					'user_id'    => yume_factory_user(),
					'oeuvre_id'  => $grimgar,
					'frequence'  => 'immediat',
					'created_at' => current_time( 'mysql', true ),
				)
			);
		}
		$dep = Migration_Runner::dependances_annulation();
		yume_assert_true( isset( $dep['contenus'][ $ajoute ] ) );
		yume_assert_same( 1, $dep['commentaires'] );
		if ( $table ) {
			yume_assert_same( 1, $dep['lignes']['yume_favoris']['nombre'] ?? 0 );
		}
		foreach ( array( $grimgar, $tome1, $tome9 ) as $id ) {
			yume_assert_true( in_array( $id, $dep['conserver'], true ), "œuvre utilisée conservée avec ses tomes ($id)" );
		}
		yume_assert_false( in_array( $sw, $dep['conserver'], true ) );

		try {
			Migration_Runner::demarrer_annulation();
			throw new Yume_Test_Failure( 'L’annulation aurait dû être refusée.' );
		} catch ( RuntimeException $e ) {
			yume_assert_same( 412, $e->getCode() );
			yume_assert_contains( 'Chapitre importé', $e->getMessage() );
			yume_assert_contains( 'commentaire', $e->getMessage() );
		}
		yume_assert_same( 'migre', Migration_State::etat()['statut'], 'rien n’a bougé' );
		$rest = yume_rest( 'POST', '/yume/v1/migration/annuler', array( 'confirmation' => 'ANNULER' ), yume_factory_user( 'administrator' ) );
		yume_assert_same( 412, $rest->get_status() );

		Migration_Runner::demarrer_annulation( array( 'conserver' => true ) );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( 'yume_oeuvre', get_post_type( $grimgar ) );
		yume_assert_same( 'yume_tome', get_post_type( $tome9 ) );
		yume_assert_same( $tome9, (int) get_post_meta( $ajoute, 'yume_tome_id', true ) );
		yume_assert_same( $grimgar, (int) get_post_meta( $ajoute, 'yume_oeuvre_id', true ), 'chapitre importé toujours rattaché' );
		yume_assert_same( 1, (int) get_comments_number( $grimgar ) );
		if ( $table ) {
			yume_assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$favoris} WHERE oeuvre_id = %d", $grimgar ) ) ); // phpcs:ignore WordPress.DB
		}
		yume_assert_false( get_post( $sw ) instanceof WP_Post, 'œuvre non utilisée supprimée' );
		yume_assert_false( get_post( $sw_ch ) instanceof WP_Post );
		yume_assert_same( 'publish', get_post_status( 2209 ), 'ancienne fiche remise en ligne' );
		yume_assert_true( in_array( $grimgar, (array) $etat['annulation']['conserves'], true ) );

		// Nouvelle exécution : les contenus conservés sont repris (mêmes ID), pas dupliqués.
		$etat = yume_test_migration_executer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( $grimgar, yume_test_migration_id( 'oeuvre', 'grimgar-of-fantasy-and-ash' ) );
		yume_assert_same( 1, (int) wp_count_posts( 'yume_oeuvre' )->publish - 1, 'deux œuvres, aucun doublon' );
	}
);

yume_test(
	'MIG-D-5 : après un arrêt brutal pendant un lot, l’annulation défait quand même les catégories',
	function () {
		yume_test_migration_seed();
		$avant = yume_test_migration_instantane();
		yume_test_migration_accepter();
		try {
			Migration_Runner::simuler();
			Migration_Runner::demarrer_execution();
		} finally {
			yume_test_migration_accepter( false );
		}
		// Lot « tué » au premier article reclassé : seul ce que le moteur a enregistré lui-même
		// en cours de lot subsiste (pas d'enregistrement de fin de lot).
		$moteur = new Migration_Executor( Migration_State::plan(), Migration_State::journal(), Migration_State::etat(), array( 'choix' => array() ) );
		$sauver = static function ( $m ): void {
			Migration_State::enregistrer_journal( $m->journal() );
			Migration_State::enregistrer_etat( $m->etat() );
		};
		$arret  = static function ( $objet, $termes, $tt, $taxonomie ): void {
			if ( 'category' === $taxonomie && 'post' === get_post_type( $objet ) ) {
				throw new RuntimeException( 'arrêt brutal' );
			}
		};
		add_action( 'set_object_terms', $arret, 10, 4 );
		$retablir = Migration_Runner::neutraliser_notifications();
		try {
			$moteur->avancer( 600.0, $sauver );
		} finally {
			$retablir();
			remove_action( 'set_object_terms', $arret, 10 );
		}
		yume_assert_contains( 'arrêt brutal', (string) $moteur->etat()['erreur'] );
		yume_assert_same( 'sorties', get_term( 775285387, 'category' )->slug, 'renommée en base' );
		yume_assert_true( in_array( 775285387, Migration_State::journal()['modifications']['categories_modifiees'], true ), 'renommage enregistré aussitôt' );

		// Même si ce journal avait manqué la modification, la reprise la retrouve.
		$journal = Migration_State::journal();
		$journal['modifications']['categories_modifiees'] = array();
		Migration_State::enregistrer_journal( $journal );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'migre', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_true( in_array( 775285387, Migration_State::journal()['modifications']['categories_modifiees'], true ) );
		Migration_Runner::demarrer_annulation();
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_same( 'yume-news', get_term( 775285387, 'category' )->slug );
		yume_assert_same( array(), $etat['controle']['differences'] );
		yume_assert_same( '', yume_test_migration_diff( $avant, yume_test_migration_instantane() ) );
	}
);

yume_test(
	'MIG-D-8 : journal perdu — relance refusée, annulation seulement avec reconstruction, contrôle signalé impossible',
	function () {
		yume_test_migration_seed();
		yume_test_migration_executer();
		delete_option( Migration_State::OPTION_JOURNAL );
		try {
			Migration_Runner::demarrer_execution( array( 'forcer' => true ) );
			throw new Yume_Test_Failure( 'La relance aurait dû être refusée.' );
		} catch ( RuntimeException $e ) {
			yume_assert_contains( 'journal de la migration est perdu', $e->getMessage() );
		}
		try {
			Migration_Runner::demarrer_annulation();
			throw new Yume_Test_Failure( 'L’annulation aurait dû demander une confirmation.' );
		} catch ( RuntimeException $e ) {
			yume_assert_same( 412, $e->getCode() );
		}
		Migration_Runner::demarrer_annulation( array( 'reconstruire' => true ) );
		$etat = Migration_Runner::terminer();
		yume_assert_same( 'annule', $etat['statut'], (string) $etat['erreur'] );
		yume_assert_false( $etat['controle']['verifie'] );
		yume_assert_contains( 'Contrôle impossible', implode( "\n", array_column( $etat['messages'], 'texte' ) ) );
		foreach ( array( 12, 66, 550, 1548, 2072, 2173, 2209, 2417, 2558 ) as $id ) {
			yume_assert_same( 'publish', get_post_status( $id ), "ancienne page $id remise en ligne" );
		}
		yume_assert_same( 'yume-news', get_term( 775285387, 'category' )->slug );
		yume_assert_same( 'Non classé', get_term( 6325, 'category' )->name ?? '' );
		yume_assert_same( 6325, (int) get_option( 'default_category' ) );
		yume_assert_same( null, Redirections::resoudre( '/grimgar-of-fantasy-and-ash-ln/' ) );
		yume_assert_same( 0, (int) wp_count_posts( 'yume_oeuvre' )->publish );

		wp_set_current_user( yume_factory_user( 'administrator' ) );
		ob_start();
		Migration_Admin::afficher();
		yume_assert_contains( 'Contrôle impossible', (string) ob_get_clean() );
	}
);
