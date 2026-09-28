<?php
/**
 * Tests de la recherche avancée (AMEL-04) : texte plié (accents, casse), groupes et compteurs,
 * titres alternatifs et métadonnées d'œuvre, filtres GET (contenu, statut, genre, tri),
 * pagination par groupe, « Aucun résultat » et œuvres proches, recherche dans les chapitres
 * (désactivée par défaut, puis active : non publiés et licenciés exclus, extrait, ancre),
 * échappement du surlignage, suggestions (REST publique, longueur minimale, 8 au plus, cache,
 * débit, contenus publiés seulement) et combobox ARIA du formulaire de l'en-tête.
 *
 * À lancer sur les deux moteurs (mêmes résultats attendus) :
 *   tools/localenv/test.sh recherche
 *   YUME_DB_ENGINE=mysql tools/localenv/test.sh recherche
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Library\chercher_chapitres;
use function Yume\Core\Library\cle_cache;
use function Yume\Core\Library\extrait_autour;
use function Yume\Core\Library\groupes_recherche;
use function Yume\Core\Library\motif_like;
use function Yume\Core\Library\mots_recherche;
use function Yume\Core\Library\normaliser_filtres_recherche;
use function Yume\Core\Library\oeuvres_proches;
use function Yume\Core\Library\plier;
use function Yume\Core\Library\renouveler_version;
use function Yume\Core\Library\rendu_recherche;
use function Yume\Core\Library\resultats_recherche;
use function Yume\Core\Library\suggestions;
use function Yume\Core\Library\surligner;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tr_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé : contenus Yume vidés dans la transaction, cache de la bibliothèque
 * renouvelé, paramètres GET et requête principale restaurés, réglages d'origine.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tr_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb, $wp_query, $wp_the_query;
			$get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requete = $wp_query;
			$reelle  = $wp_the_query;
			$types   = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
			// phpcs:enable
			wp_cache_flush();
			renouveler_version();
			add_filter( 'yume_core_notifier', '__return_false' );
			$_GET         = array();
			$wp_query     = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			try {
				$corps();
			} finally {
				remove_filter( 'yume_core_notifier', '__return_false' );
				$_GET         = $get;
				$wp_query     = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$wp_the_query = $reelle; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				renouveler_version();
			}
		}
	);
}

/**
 * Crée une œuvre.
 *
 * @param string $titre  Titre.
 * @param array  $metas  Métadonnées.
 * @param array  $termes Taxonomie => slug(s) (défaut : light novel en cours).
 * @param string $statut Statut de publication.
 */
function yume_tr_oeuvre( string $titre, array $metas = array(), array $termes = array(), string $statut = 'publish' ): int {
	$id     = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut,
			'meta_input'  => $metas,
		)
	);
	$termes = array_merge(
		array(
			'yume_type'   => 'light-novel',
			'yume_statut' => 'en-cours',
		),
		$termes
	);
	foreach ( $termes as $taxonomie => $slugs ) {
		wp_set_object_terms( $id, $slugs, $taxonomie );
	}
	return $id;
}

/**
 * Crée un tome.
 *
 * @param int    $oeuvre_id Œuvre.
 * @param int    $numero    Numéro.
 * @param string $statut    Statut.
 * @param string $titre     Titre (défaut : « {œuvre} — Tome N »).
 */
function yume_tr_tome( int $oeuvre_id, int $numero, string $statut = 'publish', string $titre = '' ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_tome',
			'post_title'  => '' !== $titre ? $titre : get_the_title( $oeuvre_id ) . ' — Tome ' . $numero,
			'post_name'   => 'tome-' . $numero,
			'post_status' => $statut,
			'meta_input'  => array(
				'yume_oeuvre_id' => $oeuvre_id,
				'yume_numero'    => $numero,
				'yume_nature'    => 'tome',
			),
		)
	);
}

/**
 * Crée un chapitre (contenu en blocs paragraphe).
 *
 * @param int      $tome_id     Tome.
 * @param int      $numero      Numéro.
 * @param string[] $paragraphes Paragraphes.
 * @param string   $statut      Statut.
 */
function yume_tr_chapitre( int $tome_id, int $numero, array $paragraphes, string $statut = 'publish' ): int {
	$contenu = '';
	foreach ( $paragraphes as $p ) {
		$contenu .= "<!-- wp:paragraph -->\n<p>" . esc_html( $p ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return yume_factory_post(
		array(
			'post_type'    => 'yume_chapitre',
			'post_title'   => 'Chapitre ' . $numero,
			'post_name'    => 'chapitre-' . $numero,
			'post_status'  => $statut,
			'post_content' => $contenu,
			'menu_order'   => $numero,
			'meta_input'   => array(
				'yume_tome_id'   => $tome_id,
				'yume_oeuvre_id' => (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ),
				'yume_numero'    => $numero,
				'yume_nature'    => 'chapitre',
			),
		)
	);
}

/**
 * Crée une actualité (article).
 *
 * @param string $titre   Titre.
 * @param string $contenu Contenu.
 * @param string $statut  Statut.
 * @param int    $jours   Âge en jours.
 */
function yume_tr_actualite( string $titre, string $contenu = '', string $statut = 'publish', int $jours = 1 ): int {
	return yume_factory_post(
		array(
			'post_type'    => 'post',
			'post_title'   => $titre,
			'post_content' => $contenu,
			'post_status'  => $statut,
			'post_date'    => wp_date( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS ),
		)
	);
}

/**
 * Résultats groupés d'une recherche (filtres GET simulés).
 *
 * @param string $terme Terme.
 * @param array  $get   Paramètres de filtre.
 */
function yume_tr_chercher( string $terme, array $get = array() ): array {
	return resultats_recherche( $terme, normaliser_filtres_recherche( $get ) );
}

/**
 * IDs trouvés dans un groupe, dans l'ordre.
 *
 * @param array  $resultats Résultats groupés.
 * @param string $groupe    Groupe.
 * @return int[]
 */
function yume_tr_ids( array $resultats, string $groupe ): array {
	return array_map( static fn( array $r ): int => (int) $r['id'], $resultats['groupes'][ $groupe ]['resultats'] ?? array() );
}

/**
 * Rendu du bloc pour une recherche (paramètres GET simulés).
 *
 * @param string $terme     Terme.
 * @param array  $get       Paramètres de filtre.
 * @param array  $attributs Attributs du bloc.
 */
function yume_tr_rendu( string $terme, array $get = array(), array $attributs = array() ): string {
	$_GET = array_merge( array( 's' => wp_slash( $terme ) ), wp_slash( $get ) );
	return rendu_recherche( $attributs );
}

/**
 * Active la recherche dans les chapitres (réglage enregistré).
 *
 * @param bool $actif Valeur.
 */
function yume_tr_reglage_chapitres( bool $actif ): void {
	$reglages                        = get_option( 'yume_reglages', array() );
	$reglages                        = is_array( $reglages ) ? $reglages : array();
	$reglages['recherche_chapitres'] = $actif;
	update_option( 'yume_reglages', $reglages );
}

/*
 * -----------------------------------------------------------------------------
 * Texte plié
 * -----------------------------------------------------------------------------
 */

yume_tr_test(
	'texte plié : minuscules, sans accents ni ligatures, apostrophe typographique ; mots dédoublonnés',
	static function () {
		yume_assert_same( 'lanterne', plier( 'LANTERNÉ' ) );
		yume_assert_same( "l'oeuvre", plier( 'L’Œuvre' ) );
		yume_assert_same( array( 'lanterne', 'brume' ), mots_recherche( '  « Lanterné »  brume, LANTERNE ' ) );
		yume_assert_same( array(), mots_recherche( '   ' ) );
		yume_assert_same( 'Lanterné <mark class="yn-search__marque">&lt;b&gt;</mark> &amp; co', surligner( 'Lanterné <b> & co', array( '<b>' ) ) );
		yume_assert_same( '<mark class="yn-search__marque">Lanterné</mark> bleue', surligner( 'Lanterné bleue', array( 'lanterne' ) ), 'surlignage dans le texte d’origine (accent conservé)' );
		yume_assert_same( 'Mon <mark class="yn-search__marque">Œuvre</mark>', surligner( 'Mon Œuvre', array( 'oeuvre' ) ), 'ligature' );
		yume_assert_contains( '%', motif_like( 'oeuvre' ), 'ligature : préfiltre SQL large' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Œuvres, tomes, actualités : groupes et compteurs
 * -----------------------------------------------------------------------------
 */

yume_tr_test(
	'accents et casse ignorés dans les deux sens (« lanterne » ↔ « Lanterné »), même préfiltre SQL sur SQLite et MariaDB',
	static function () {
		$a = yume_tr_oeuvre( 'La Lanterné du soir' );
		$b = yume_tr_oeuvre( 'LANTERNE BLEUE' );
		$c = yume_tr_oeuvre( 'Autre chose' );
		$n = yume_tr_actualite( 'Annonce de la lanterné', 'Texte' );
		$m = yume_tr_actualite( 'Sans rapport', 'Il était une fois une LANTERNE cachée.' );
		foreach ( array( 'lanterne', 'Lanterné', 'LANTERNÉ' ) as $terme ) {
			$r = yume_tr_chercher( $terme );
			$o = yume_tr_ids( $r, 'oeuvres' );
			sort( $o );
			yume_assert_same( array( $a, $b ), $o, 'œuvres pour « ' . $terme . ' »' );
			yume_assert_false( in_array( $c, $o, true ) );
			$actus = yume_tr_ids( $r, 'actualites' );
			sort( $actus );
			yume_assert_same( array( $n, $m ), $actus, 'actualités (titre et texte) pour « ' . $terme . ' »' );
		}
	}
);

yume_tr_test(
	'groupes « Œuvres », « Tomes », « Actualités » et compteurs ; contenus non publiés jamais trouvés',
	static function () {
		$o        = yume_tr_oeuvre( 'Grimbrume' );
		$t1       = yume_tr_tome( $o, 1 );
		$t2       = yume_tr_tome( $o, 2 );
		$t_draft  = yume_tr_tome( $o, 3, 'draft' );
		$cachee   = yume_tr_oeuvre( 'Grimbrume secrète', array(), array(), 'draft' );
		$t_cachee = yume_tr_tome( $cachee, 1 );
		$actu     = yume_tr_actualite( 'Grimbrume revient', 'Bonne nouvelle.' );
		$brouill  = yume_tr_actualite( 'Grimbrume brouillon', '', 'draft' );

		$r = yume_tr_chercher( 'grimbrume' );
		yume_assert_same( array( 'oeuvres', 'tomes', 'actualites' ), array_keys( $r['groupes'] ), 'groupes par défaut (sans chapitres)' );
		yume_assert_same( array( $o ), yume_tr_ids( $r, 'oeuvres' ) );
		$tomes = yume_tr_ids( $r, 'tomes' );
		sort( $tomes );
		yume_assert_same( array( $t1, $t2 ), $tomes, 'tomes publiés d’œuvres publiées seulement' );
		yume_assert_same( array( $actu ), yume_tr_ids( $r, 'actualites' ) );
		yume_assert_same( 4, $r['total'] );
		foreach ( array( $t_draft, $cachee, $t_cachee, $brouill ) as $id ) {
			yume_assert_false( in_array( $id, array_merge( ...array_values( array_map( static fn( $g ) => array_column( $g['resultats'], 'id' ), $r['groupes'] ) ) ), true ), 'non publié exclu : ' . $id );
		}

		$html = yume_tr_rendu( 'grimbrume' );
		yume_assert_contains( 'class="yn-search', $html );
		yume_assert_contains( 'id="yn-search-oeuvres"', $html );
		yume_assert_contains( 'id="yn-search-tomes"', $html );
		yume_assert_contains( 'id="yn-search-actualites"', $html );
		yume_assert_not_contains( 'yn-search-chapitres', $html );
		yume_assert_contains( 'Tomes <span class="yn-search__compteur">2<span class="yn-visually-hidden"> résultats</span>', $html, 'compteur du groupe' );
		yume_assert_contains( '4 résultats pour « grimbrume »', $html );
		yume_assert_contains( '>Tout <span class="yn-search__nombre">4', $html, 'compteur « Tout » des filtres' );
		yume_assert_contains( '<mark class="yn-search__marque">Grimbrume</mark>', $html );
		yume_assert_not_contains( 'secrète', $html );
	}
);

yume_tr_test(
	'titres alternatifs, auteur, illustrateur et éditeur VO trouvent l’œuvre (champ affiché et surligné)',
	static function () {
		$o = yume_tr_oeuvre(
			'Les Lanternes de Brume-Haute',
			array(
				'yume_titres_alt'   => array( 'Brume-Haute no Chōchin', 'The Lanterns of High Mist' ),
				'yume_auteur'       => 'Aoi Tsukimura',
				'yume_illustrateur' => 'Hikaru Mizuki',
				'yume_editeur_vo'   => 'Kōdansha Ranobe',
			)
		);
		yume_tr_oeuvre( 'Autre' );
		yume_assert_same( array( $o ), yume_tr_ids( yume_tr_chercher( 'high mist' ), 'oeuvres' ), 'titre alternatif' );
		yume_assert_same( array( $o ), yume_tr_ids( yume_tr_chercher( 'chochin' ), 'oeuvres' ), 'titre alternatif avec macron' );
		yume_assert_same( array( $o ), yume_tr_ids( yume_tr_chercher( 'tsukimura' ), 'oeuvres' ), 'auteur' );
		yume_assert_same( array( $o ), yume_tr_ids( yume_tr_chercher( 'mizuki' ), 'oeuvres' ), 'illustrateur' );
		yume_assert_same( array( $o ), yume_tr_ids( yume_tr_chercher( 'kodansha' ), 'oeuvres' ), 'éditeur VO' );
		$html = yume_tr_rendu( 'high mist' );
		yume_assert_contains( 'Titre alternatif : The Lanterns of <mark class="yn-search__marque">High</mark> <mark class="yn-search__marque">Mist</mark>', $html );
		yume_assert_contains( 'Auteur : Aoi <mark class="yn-search__marque">Tsukimura</mark>', yume_tr_rendu( 'tsukimura' ) );

		// Titre alternatif modifié : le cache est renouvelé.
		update_post_meta( $o, 'yume_titres_alt', array( 'Mist Lanterns' ) );
		yume_assert_same( array(), yume_tr_ids( yume_tr_chercher( 'high mist' ), 'oeuvres' ), 'cache renouvelé au changement de titre alternatif' );
	}
);

yume_tr_test(
	'filtres GET combinables : contenu, statut de l’œuvre, genre, tri (pertinence, récent, A → Z)',
	static function () {
		$zeta  = yume_tr_oeuvre(
			'Zeta Brasier',
			array(),
			array(
				'yume_statut' => 'terminee',
				'yume_genre'  => 'fantasy',
			)
		);
		$alpha = yume_tr_oeuvre(
			'Alpha Brasier',
			array(),
			array(
				'yume_statut' => 'en-cours',
				'yume_genre'  => 'romance',
			)
		);
		$exact = yume_tr_oeuvre(
			'Brasier',
			array(),
			array(
				'yume_statut' => 'en-cours',
				'yume_genre'  => 'fantasy',
			)
		);
		$tz    = yume_tr_tome( $zeta, 1 );
		$ta    = yume_tr_tome( $alpha, 1 );
		yume_tr_actualite( 'Brasier : nouvelle', '' );

		$r = yume_tr_chercher( 'brasier' );
		yume_assert_same( $exact, yume_tr_ids( $r, 'oeuvres' )[0], 'pertinence : titre identique en premier' );
		yume_assert_same( array( $alpha, $exact, $zeta ), yume_tr_ids( yume_tr_chercher( 'brasier', array( 'tri' => 'az' ) ), 'oeuvres' ), 'A → Z' );

		$r = yume_tr_chercher( 'brasier', array( 'statut' => 'terminee' ) );
		yume_assert_same( array( $zeta ), yume_tr_ids( $r, 'oeuvres' ), 'statut' );
		yume_assert_same( array( $tz ), yume_tr_ids( $r, 'tomes' ), 'statut appliqué aux tomes par leur œuvre' );
		yume_assert_same( 0, $r['groupes']['actualites']['total'], 'statut : actualités non liées exclues' );

		$r = yume_tr_chercher(
			'brasier',
			array(
				'genre'  => 'fantasy',
				'statut' => 'en-cours,en-pause',
			)
		);
		yume_assert_same( array( $exact ), yume_tr_ids( $r, 'oeuvres' ), 'genre et statut combinés' );

		$f = normaliser_filtres_recherche(
			array(
				'contenu' => 'chapitres',
				'tri'     => 'n-importe',
				'statut'  => 'inconnu',
			)
		);
		yume_assert_same( '', $f['contenu'], 'groupe « chapitres » inconnu tant que le réglage est inactif' );
		yume_assert_same( 'pertinence', $f['tri'] );
		yume_assert_same( array(), $f['statuts'] );

		$html = yume_tr_rendu(
			'brasier',
			array(
				'contenu' => 'tomes',
				'genre'   => 'fantasy',
			)
		);
		yume_assert_contains( 'id="yn-search-tomes"', $html );
		yume_assert_not_contains( 'id="yn-search-oeuvres"', $html, 'un seul groupe affiché' );
		yume_assert_contains( 'aria-label="Filtres de la recherche"', $html );
		yume_assert_contains(
			esc_url(
				add_query_arg(
					array(
						's'       => 'brasier',
						'contenu' => 'tomes',
						'genre'   => 'fantasy',
						'tri'     => 'az',
					),
					home_url( '/' )
				)
			),
			$html,
			'lien de tri qui garde les autres filtres'
		);
		yume_assert_contains( 'aria-current="page">Tomes', $html, 'filtre actif marqué' );
		yume_assert_not_contains( (string) $ta, wp_json_encode( yume_tr_ids( yume_tr_chercher( 'brasier', array( 'genre' => 'fantasy' ) ), 'tomes' ) ) );
	}
);

yume_tr_test(
	'pagination propre à chaque groupe (pg_{groupe}), les autres groupes gardent leur page',
	static function () {
		$ids = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$ids[] = yume_tr_oeuvre( 'Pagination ' . $i );
		}
		$html = yume_tr_rendu( 'pagination', array(), array( 'apercu' => 5 ) );
		yume_assert_contains( 'aria-label="Pages des résultats : Œuvres"', $html );
		yume_assert_contains(
			esc_url(
				add_query_arg(
					array(
						's'          => 'pagination',
						'pg_oeuvres' => 2,
					),
					home_url( '/' )
				) . '#yn-search-oeuvres'
			),
			$html
		);
		yume_assert_same( 5, substr_count( $html, 'yn-search__item--oeuvre' ) );
		$html = yume_tr_rendu( 'pagination', array( 'pg_oeuvres' => '2' ), array( 'apercu' => 5 ) );
		yume_assert_same( 2, substr_count( $html, 'yn-search__item--oeuvre' ), 'page 2' );
	}
);

yume_tr_test(
	'aucun résultat : message, œuvres au titre proche, lien vers la Bibliothèque',
	static function () {
		$o = yume_tr_oeuvre( 'La Lanterné du soir' );
		yume_tr_oeuvre( 'Rien à voir' );
		yume_assert_same( array( $o ), oeuvres_proches( mots_recherche( 'lantrene' ) ) );
		$html = yume_tr_rendu( 'lantrene' );
		yume_assert_contains( 'Aucun résultat', $html );
		yume_assert_contains( 'Vouliez-vous dire', $html );
		yume_assert_contains( esc_url( (string) get_permalink( $o ) ), $html );
		yume_assert_contains( 'Parcourir la bibliothèque', $html );
		yume_assert_not_contains( 'Rien à voir', $html );
		yume_assert_contains( 'Saisissez', yume_tr_rendu( '' ), 'terme vide' );
	}
);

yume_tr_test(
	'échappement strict : un terme « <script> » est cherché littéralement, surligné et échappé',
	static function () {
		yume_tr_actualite( 'Balise &lt;script&gt; interdite', '<p>Le mot &lt;script&gt; ne passe jamais.</p>' );
		$html = yume_tr_rendu( '<script>' );
		yume_assert_contains( '<mark class="yn-search__marque">&lt;script&gt;</mark>', $html );
		yume_assert_contains( '1 résultat pour « &lt;script&gt; »', $html );
		yume_assert_not_contains( '<script', $html );
		$html = yume_tr_rendu( '"><img src=x onerror=alert(1)><script>alert(1)</script>' );
		yume_assert_not_contains( '<script', $html );
		yume_assert_not_contains( '<img src=x', $html );
		yume_assert_contains( 'Aucun résultat', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Chapitres
 * -----------------------------------------------------------------------------
 */

yume_tr_test(
	'chapitres : absents par défaut (réglage désactivé), présents avec le réglage ; non publiés et licenciés exclus ; extrait et ancre',
	static function () {
		$o       = yume_tr_oeuvre( 'Œuvre ouverte' );
		$t       = yume_tr_tome( $o, 1 );
		$long    = str_repeat( 'Du texte sans intérêt pour remplir la page. ', 12 );
		$c       = yume_tr_chapitre( $t, 1, array( 'Premier paragraphe.', 'Deuxième paragraphe.', $long . 'Le dragon Émeraudïn apparut soudain dans le ciel. ' . $long ) );
		$c_draft = yume_tr_chapitre( $t, 2, array( 'Émeraudïn en brouillon.' ), 'draft' );
		$t_draft = yume_tr_tome( $o, 2, 'draft' );
		$c_tome  = yume_tr_chapitre( $t_draft, 1, array( 'Émeraudïn dans un tome non sorti.' ) );
		$lic     = yume_tr_oeuvre( 'Œuvre licenciée', array(), array( 'yume_statut' => 'licenciee' ) );
		$tl      = yume_tr_tome( $lic, 1 );
		$c_lic   = yume_tr_chapitre( $tl, 1, array( 'Émeraudïn chez l’éditeur français.' ) );

		yume_assert_false( (bool) yume_setting( 'recherche_chapitres', false ), 'réglage désactivé par défaut' );
		yume_assert_false( isset( groupes_recherche()['chapitres'] ) );
		yume_assert_same( array(), chercher_chapitres( mots_recherche( 'emeraudin' ), normaliser_filtres_recherche( array() ) )['resultats'] );
		yume_assert_not_contains( 'Dans les chapitres', yume_tr_rendu( 'emeraudin' ) );

		yume_tr_reglage_chapitres( true );
		renouveler_version();
		$r = yume_tr_chercher( 'emeraudin' );
		yume_assert_same( array( $c ), yume_tr_ids( $r, 'chapitres' ), 'publiés, d’un tome et d’une œuvre publiés, œuvre non licenciée' );
		foreach ( array( $c_draft, $c_tome, $c_lic ) as $exclu ) {
			yume_assert_false( in_array( $exclu, yume_tr_ids( $r, 'chapitres' ), true ) );
		}
		yume_assert_same( array(), yume_tr_ids( yume_tr_chercher( 'em' ), 'chapitres' ), 'terme de moins de 3 caractères' );

		$html = yume_tr_rendu( 'Émeraudin' );
		yume_assert_contains( 'id="yn-search-chapitres"', $html );
		yume_assert_contains( 'Dans les chapitres', $html );
		yume_assert_contains( esc_url( get_permalink( $c ) . '#yn-p-3' ), $html, 'lien vers le paragraphe de la première occurrence' );
		yume_assert_contains( '<mark class="yn-search__marque">Émeraudïn</mark>', $html );
		yume_assert_true( (bool) preg_match( '#<p class="yn-search__extrait">(.*?)</p>#s', $html, $m ) );
		$texte = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' );
		yume_assert_true( mb_strlen( $texte ) <= 202 && mb_strlen( $texte ) >= 150, 'extrait d’environ 200 caractères : ' . mb_strlen( $texte ) );
		yume_assert_true( str_starts_with( $texte, '…' ) && str_ends_with( $texte, '…' ), 'extrait coupé des deux côtés' );

		yume_assert_same( '', normaliser_filtres_recherche( array( 'contenu' => 'inconnu' ) )['contenu'] );
		yume_assert_same( 'chapitres', normaliser_filtres_recherche( array( 'contenu' => 'chapitres' ) )['contenu'] );
	}
);

yume_tr_test(
	'chapitres : coût limité (LIMIT, filtre de type et de statut dans la requête SQL)',
	static function () {
		yume_tr_reglage_chapitres( true );
		$o = yume_tr_oeuvre( 'Série longue' );
		$t = yume_tr_tome( $o, 1 );
		for ( $i = 1; $i <= 4; $i++ ) {
			yume_tr_chapitre( $t, $i, array( 'Le mot vermillon revient.' ) );
		}
		renouveler_version();
		$requetes = array();
		$espion   = static function ( $sql ) use ( &$requetes ) {
			if ( str_contains( (string) $sql, 'yume_chapitre' ) && str_contains( (string) $sql, 'LIKE' ) ) {
				$requetes[] = (string) $sql;
			}
			return $sql;
		};
		add_filter( 'query', $espion );
		add_filter( 'yume_recherche_chapitres_max', static fn() => 3 );
		$r = chercher_chapitres( mots_recherche( 'vermillon' ), normaliser_filtres_recherche( array() ) );
		remove_filter( 'query', $espion );
		remove_all_filters( 'yume_recherche_chapitres_max' );
		yume_assert_same( 3, count( $r['resultats'] ) );
		yume_assert_true( $r['tronque'], 'limite atteinte signalée' );
		yume_assert_same( 1, count( $requetes ), 'une requête' );
		yume_assert_contains( 'LIMIT 3', $requetes[0] );
		yume_assert_contains( "post_status = 'publish'", $requetes[0] );
		yume_assert_contains( 'yume_tome_id', $requetes[0] );
	}
);

yume_test(
	'réglage « Recherche dans les chapitres » ajouté à Yume → Réglages (défaut : désactivé)',
	static function () {
		$champs = array_column( (array) apply_filters( 'yume_reglages_champs', array() ), null, 'key' );
		yume_assert_true( isset( $champs['recherche_chapitres'] ) );
		yume_assert_same( 'checkbox', $champs['recherche_chapitres']['type'] );
		yume_assert_false( $champs['recherche_chapitres']['default'] );
	}
);

yume_test(
	'extrait : 200 caractères autour de la première occurrence, début de texte sans points de suspension',
	static function () {
		$texte = 'Début. ' . str_repeat( 'abc def ghi ', 40 ) . 'CIBLE finale ' . str_repeat( 'jkl mno ', 40 );
		$html  = extrait_autour( $texte, array( 'cible' ) );
		yume_assert_contains( '<mark class="yn-search__marque">CIBLE</mark>', $html );
		yume_assert_true( mb_strlen( html_entity_decode( wp_strip_all_tags( $html ) ) ) <= 202 );
		yume_assert_same( 'Court <mark class="yn-search__marque">texte</mark>', extrait_autour( 'Court texte', array( 'texte' ) ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Suggestions
 * -----------------------------------------------------------------------------
 */

yume_tr_test(
	'suggestions : 2 caractères au moins, 8 au plus, œuvres (titres alternatifs compris) puis tomes, publiés seulement, mises en cache',
	static function () {
		$oeuvres = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$oeuvres[] = yume_tr_oeuvre( 'Nébuleuse ' . $i );
		}
		$alt    = yume_tr_oeuvre( 'Tout autre titre', array( 'yume_titres_alt' => array( 'Kagerou Nebula' ) ) );
		$cachee = yume_tr_oeuvre( 'Kagerou cachée', array(), array(), 'draft' );
		yume_tr_tome( $cachee, 1 );
		$tome = yume_tr_tome( $alt, 1, 'publish', 'Tout autre titre — Kagerou Tome 1' );

		yume_assert_same( array(), suggestions( 'n' ), 'moins de 2 caractères' );
		$s = suggestions( 'nebu' );
		yume_assert_same( 8, count( $s ), '8 suggestions au plus' );
		yume_assert_same( 'oeuvre', $s[0]['type'] );
		yume_assert_true( str_starts_with( $s[0]['url'], 'http' ) );
		yume_assert_true( array_key_exists( 'image', $s[0] ) );

		$s = suggestions( 'kagerou' );
		yume_assert_same( array( $alt, $tome ), array_column( $s, 'id' ), 'titre alternatif puis tome ; brouillons exclus' );
		yume_assert_same( 'Kagerou Nebula', $s[0]['detail'], 'titre alternatif en détail' );

		// Cache : la réponse est gardée (transient) et renouvelée avec le contenu.
		yume_assert_true( is_array( get_transient( cle_cache( 'suggestions', array( mots_recherche( 'kagerou' ) ) ) ) ) );
		set_transient( cle_cache( 'suggestions', array( mots_recherche( 'kagerou' ) ) ), array( array( 'marqueur' => 1 ) ), 60 );
		yume_assert_same( array( array( 'marqueur' => 1 ) ), suggestions( 'Kagerou' ), 'lue depuis le cache' );
		yume_tr_oeuvre( 'Kagerou nouvelle' );
		yume_assert_true( count( suggestions( 'kagerou' ) ) >= 3, 'cache renouvelé à la publication' );
	}
);

yume_tr_test(
	'REST GET /yume/v1/suggestions : publique, longueur minimale, limitation de débit (429)',
	static function () {
		yume_tr_oeuvre( 'Astrolabe' );
		$r = yume_rest( 'GET', '/yume/v1/suggestions', array( 'q' => 'astro' ) );
		yume_assert_same( 200, $r->get_status() );
		$d = $r->get_data();
		yume_assert_same( 1, $d['total'] );
		yume_assert_same( 'Astrolabe', $d['suggestions'][0]['titre'] );
		yume_assert_contains( 's=astro', $d['recherche'] );
		$r = yume_rest( 'GET', '/yume/v1/suggestions', array( 'q' => 'a' ) );
		yume_assert_same( 200, $r->get_status() );
		yume_assert_same( 0, $r->get_data()['total'], 'un caractère : aucune suggestion' );
		yume_assert_same( 400, yume_rest( 'GET', '/yume/v1/suggestions' )->get_status(), 'q obligatoire' );

		$limite                 = static fn() => 2;
		add_filter( 'yume_suggestions_limite', $limite );
		$ip                     = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$statuts                = array();
		try {
			for ( $i = 0; $i < 3; $i++ ) {
				$statuts[] = yume_rest( 'GET', '/yume/v1/suggestions', array( 'q' => 'astro' ) )->get_status();
			}
		} finally {
			remove_filter( 'yume_suggestions_limite', $limite );
			if ( null === $ip ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $ip;
			}
		}
		yume_assert_same( array( 200, 200 ), array_slice( $statuts, 0, 2 ) );
		yume_assert_same( 429, $statuts[2], 'au-delà de la limite : 429' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Thème : gabarit et combobox de l'en-tête
 * -----------------------------------------------------------------------------
 */

yume_test(
	'thème : search.html utilise yume/recherche ; champ de l’en-tête en combobox ARIA 1.2',
	static function () {
		$gabarit = (string) file_get_contents( get_theme_file_path( 'templates/search.html' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		yume_assert_contains( '<!-- wp:yume/recherche /-->', $gabarit );
		yume_assert_not_contains( 'wp:query ', $gabarit, 'l’ancienne boucle est retirée' );

		$html = render_block(
			array(
				'blockName'    => 'core/search',
				'attrs'        => array(
					'label'          => 'Rechercher sur le site',
					'showLabel'      => false,
					'buttonText'     => 'Rechercher',
					'buttonPosition' => 'button-inside',
					'className'      => 'yn-nav__recherche',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		yume_assert_contains( 'role="combobox"', $html );
		yume_assert_contains( 'aria-expanded="false"', $html );
		yume_assert_contains( 'aria-autocomplete="list"', $html );
		yume_assert_true( (bool) preg_match( '/aria-controls="(yn-suggest-[^"]+-liste)"/', $html, $m ), 'aria-controls' );
		yume_assert_contains( 'id="' . $m[1] . '" role="listbox"', $html );
		yume_assert_contains( 'aria-live="polite"', $html );
		yume_assert_contains( 'data-yn-suggestions="' . esc_url( rest_url( 'yume/v1/suggestions' ) ) . '"', $html );
		yume_assert_contains( 'aria-label="Rechercher sur le site"', $html, 'nom du repère conservé' );
		yume_assert_true( wp_script_is( 'yume-suggestions', 'enqueued' ), 'script des suggestions chargé' );

		// Autre formulaire (page de résultats) : recherche classique, sans combobox.
		$autre = render_block(
			array(
				'blockName'    => 'core/search',
				'attrs'        => array(
					'label'     => 'Nouvelle recherche',
					'className' => 'yn-recherche',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		yume_assert_not_contains( 'combobox', $autre );
	}
);
