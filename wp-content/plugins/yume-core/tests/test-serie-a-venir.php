<?php
/**
 * Tests de la « série à venir » (includes/planning/serie-a-venir.php) : métas et API
 * (yume_oeuvre_a_venir(), yume_titre_public_oeuvre()), aucune fuite du vrai titre côté public
 * (page Planning, calendrier, accueil, prochaines sorties, REST, journal, RSS, ICS), brouillon non
 * marqué invisible, révélation automatique à la première publication, formulaires de l'œuvre et
 * pastilles de l'espace équipe.
 *
 * Lancement : tools/localenv/test.sh serie-a-venir
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Planning\ajouter_tome;
use function Yume\Core\Planning\formulaire_nouveau_tome;
use function Yume\Core\Planning\fuseau;
use function Yume\Core\Planning\grouper_journal;
use function Yume\Core\Planning\lire_journal;
use function Yume\Core\Planning\membres_equipe;
use function Yume\Core\Planning\mettre_a_jour;
use function Yume\Core\Planning\prochain_tome;
use function Yume\Core\Planning\rendu_a_la_une;
use function Yume\Core\Planning\rendu_modifier_tome;
use function Yume\Core\Planning\rendu_planning_accueil;
use function Yume\Core\Planning\rendu_vue_oeuvres;
use function Yume\Core\Planning\rendu_vue_planning;
use function Yume\Core\Planning\rendu_vue_tomes;
use function Yume\Core\Planning\table_journal;
use function Yume\Core\Planning\traiter_formulaire_oeuvre;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tsv_)
 * -----------------------------------------------------------------------------
 */

/**
 * Test isolé : œuvres, tomes, chapitres et journal vidés (transaction annulée ensuite par le
 * lanceur), annonces coupées, visiteur anonyme et GET rétablis à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tsv_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			$wpdb->query( 'DELETE FROM ' . table_journal() ); // phpcs:ignore
			wp_cache_flush();
			delete_transient( 'yume_planning_ics' );
			\Yume\Core\Core\installer_roles();
			add_filter( 'yume_core_notifier', '__return_false' );
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps();
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				remove_filter( 'yume_core_notifier', '__return_false' );
				delete_transient( 'yume_planning_ics' );
				delete_transient( 'yume_planning_retour_' . get_current_user_id() );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Date « Y-m-d » (heure de Paris) à N jours d'aujourd'hui.
 *
 * @param int $jours Décalage.
 */
function yume_tsv_jour( int $jours ): string {
	return ( new DateTimeImmutable( 'now', fuseau() ) )->modify( sprintf( '%+d days', $jours ) )->format( 'Y-m-d' );
}

/**
 * Crée une œuvre (type light novel).
 *
 * @param string $titre  Titre.
 * @param string $statut Statut.
 * @param array  $meta   Métadonnées.
 */
function yume_tsv_oeuvre( string $titre, string $statut = 'draft', array $meta = array() ): int {
	$id = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut,
			'meta_input'  => $meta,
		)
	);
	wp_set_object_terms( $id, 'light-novel', 'yume_type' );
	return $id;
}

/**
 * Ajoute un tome au planning par le service (journal « creation »), comme l'espace équipe.
 *
 * @param int    $oeuvre  Œuvre.
 * @param int    $numero  Numéro.
 * @param string $titre   Sous-titre.
 * @param int    $jours   Date cible dans N jours.
 * @param int    $user_id Éditeur.
 * @throws Yume_Test_Failure Ajout refusé.
 */
function yume_tsv_tome( int $oeuvre, int $numero, string $titre, int $jours, int $user_id ): int {
	$id = ajouter_tome(
		array(
			'oeuvre_id'  => $oeuvre,
			'numero'     => $numero,
			'titre'      => $titre,
			'date_cible' => yume_tsv_jour( $jours ),
			'etape'      => 'traduction',
		),
		$user_id
	);
	if ( ! is_int( $id ) ) {
		throw new Yume_Test_Failure( 'ajouter_tome : ' . ( is_wp_error( $id ) ? $id->get_error_message() : '?' ) );
	}
	return $id;
}

/**
 * Couverture réelle (PNG dans les téléversements) mise en avant sur un contenu ; fichier et
 * nom révélateurs (« zeta »).
 *
 * @param int    $contenu Contenu.
 * @param string $alt     Texte alternatif et titre de la pièce jointe.
 * @throws Yume_Test_Failure Pièce jointe refusée.
 */
function yume_tsv_couverture( int $contenu, string $alt ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$dossier = wp_upload_dir();
	wp_mkdir_p( $dossier['path'] );
	$nom    = 'couverture-zeta-' . wp_rand( 1000, 9999 ) . '.png';
	$chemin = trailingslashit( $dossier['path'] ) . $nom;
	$image  = imagecreatetruecolor( 40, 60 );
	imagepng( $image, $chemin );
	imagedestroy( $image );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => $alt,
			'post_status'    => 'inherit',
			'guid'           => trailingslashit( $dossier['url'] ) . $nom,
		),
		$chemin,
		$contenu,
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new Yume_Test_Failure( 'couverture : ' . $id->get_error_message() );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $chemin ) );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	set_post_thumbnail( $contenu, $id );
	return (int) $id;
}

/**
 * Jeu de données : « Titre Secret Zeta » (brouillon, série à venir, couverture révélatrice) et
 * son Tome 1 « Le Secret Omega » au planning dans 2 jours (avancement mis à jour), « Projet
 * Invisible Kappa » (brouillon NON marqué) et son Tome 1 dans 1 jour, « Grimgar » (publiée) et
 * son Tome 9 dans 5 jours.
 *
 * @param string $libelle Nom public (vide : défaut).
 * @return array<string,int>
 * @throws Yume_Test_Failure Mise à jour refusée.
 */
function yume_tsv_jeu( string $libelle = '' ): array {
	$editeur = yume_factory_user( 'yume_editeur' );
	wp_set_current_user( $editeur );
	$meta = array( 'yume_serie_a_venir' => '1' );
	if ( '' !== $libelle ) {
		$meta['yume_libelle_a_venir'] = $libelle;
	}
	$d            = array( 'editeur' => $editeur );
	$d['secret']  = yume_tsv_oeuvre( 'Titre Secret Zeta', 'draft', $meta );
	$d['cover']   = yume_tsv_couverture( $d['secret'], 'Titre Secret Zeta' );
	$d['t1']      = yume_tsv_tome( $d['secret'], 1, 'Le Secret Omega', 2, $editeur );
	$d['kappa']   = yume_tsv_oeuvre( 'Projet Invisible Kappa' );
	$d['k1']      = yume_tsv_tome( $d['kappa'], 1, '', 1, $editeur );
	$d['grimgar'] = yume_tsv_oeuvre( 'Grimgar', 'publish' );
	$d['g9']      = yume_tsv_tome( $d['grimgar'], 9, '', 5, $editeur );
	$maj          = mettre_a_jour( $d['t1'], array( 'avancement' => array( 'traduction' => 40 ) ), $editeur );
	if ( is_wp_error( $maj ) ) {
		throw new Yume_Test_Failure( 'mettre_a_jour : ' . $maj->get_error_message() );
	}
	wp_set_current_user( 0 );
	return $d;
}

/**
 * Supprime la couverture du jeu (fichiers compris).
 *
 * @param array $d Jeu.
 */
function yume_tsv_nettoyer( array $d ): void {
	if ( ! empty( $d['cover'] ) ) {
		wp_delete_attachment( (int) $d['cover'], true );
	}
}

/**
 * Sorties publiques du planning, rendues en visiteur anonyme : nom => texte.
 *
 * @return array<string,string>
 */
function yume_tsv_sorties_publiques(): array {
	wp_set_current_user( 0 );
	$sorties     = array(
		'planning'   => yume_render_block( 'yume/planning' ),
		'accueil'    => yume_render_block( 'yume/planning-accueil' ),
		'upcoming'   => yume_render_block( 'yume/upcoming', array( 'count' => 6 ) ),
		'calendrier' => yume_render_block( 'yume/calendrier' ),
	);
	$_GET['vue'] = 'calendrier'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	try {
		$sorties['planning-calendrier'] = yume_render_block( 'yume/planning' );
	} finally {
		unset( $_GET['vue'] );
	}
	$sorties['rest']    = (string) wp_json_encode( yume_rest( 'GET', '/yume/v1/planning' )->get_data(), JSON_UNESCAPED_UNICODE );
	$sorties['avenir']  = (string) wp_json_encode( yume_rest( 'GET', '/yume/v1/planning', array( 'a_venir' => true ) )->get_data(), JSON_UNESCAPED_UNICODE );
	$sorties['journal'] = (string) wp_json_encode( yume_rest( 'GET', '/yume/v1/planning/journal' )->get_data(), JSON_UNESCAPED_UNICODE );
	$sorties['rss']     = (string) yume_rest( 'GET', '/yume/v1/planning/journal', array( 'format' => 'rss' ) )->get_data();
	$sorties['ics']     = (string) yume_rest( 'GET', '/yume/v1/planning.ics' )->get_data();
	return $sorties;
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_tsv_test(
	'Série à venir : métas déclarées et assainies, yume_oeuvre_a_venir() et yume_titre_public_oeuvre()',
	static function () {
		$cles = get_registered_meta_keys( 'post', 'yume_oeuvre' );
		yume_assert_true( isset( $cles['yume_serie_a_venir'], $cles['yume_libelle_a_venir'] ), 'métas déclarées' );
		yume_assert_same( 'boolean', $cles['yume_serie_a_venir']['type'] );

		$o = yume_tsv_oeuvre( 'Titre Secret Zeta' );
		yume_assert_false( yume_oeuvre_a_venir( $o ), 'non marquée' );
		yume_assert_same( 'Titre Secret Zeta', yume_titre_public_oeuvre( $o ) );
		update_post_meta( $o, 'yume_serie_a_venir', true );
		yume_assert_same( '1', get_post_meta( $o, 'yume_serie_a_venir', true ) );
		yume_assert_true( yume_oeuvre_a_venir( $o ) );
		yume_assert_same( 'Nouvelle série à venir', yume_titre_public_oeuvre( $o ), 'nom par défaut' );
		update_post_meta( $o, 'yume_libelle_a_venir', '<b>Projet</b> mystère ' . str_repeat( 'x', 120 ) );
		$libelle = get_post_meta( $o, 'yume_libelle_a_venir', true );
		yume_assert_same( 80, mb_strlen( $libelle ), '80 caractères au plus' );
		yume_assert_true( str_starts_with( $libelle, 'Projet mystère x' ), 'balises retirées' );
		yume_assert_same( $libelle, yume_titre_public_oeuvre( $o ) );

		foreach ( array( 'pending', 'future' ) as $statut ) {
			$id = yume_tsv_oeuvre( 'Autre ' . $statut, 'future' === $statut ? 'draft' : $statut, array( 'yume_serie_a_venir' => '1' ) );
			if ( 'future' === $statut ) {
				global $wpdb;
				$wpdb->update( $wpdb->posts, array( 'post_status' => 'future' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $id );
			}
			yume_assert_true( yume_oeuvre_a_venir( $id ), $statut );
		}
		foreach ( array( 'publish', 'private' ) as $statut ) {
			$id = yume_tsv_oeuvre( 'Publique ' . $statut, $statut, array( 'yume_serie_a_venir' => '1' ) );
			yume_assert_false( yume_oeuvre_a_venir( $id ), $statut . ' : jamais à venir' );
			yume_assert_contains( 'Publique ' . $statut, yume_titre_public_oeuvre( $id ), $statut . ' : vrai titre' );
		}
		yume_assert_false( yume_oeuvre_a_venir( 0 ) );
		yume_assert_false( yume_oeuvre_a_venir( yume_factory_post( array( 'meta_input' => array( 'yume_serie_a_venir' => '1' ) ) ) ), 'pas une œuvre' );
	}
);

yume_tsv_test(
	'Série à venir : aucune fuite du vrai titre côté public (Planning, calendrier, accueil, prochaines sorties, REST, journal, RSS, ICS)',
	static function () {
		$d = yume_tsv_jeu();
		try {
			$sorties = yume_tsv_sorties_publiques();
			foreach ( $sorties as $nom => $texte ) {
				yume_assert_not_contains( 'Titre Secret Zeta', $texte, $nom );
				yume_assert_false( false !== stripos( $texte, 'zeta' ), $nom . ' : ni titre, ni alt, ni fichier de couverture' );
				yume_assert_not_contains( 'Omega', $texte, $nom . ' : sous-titre du tome' );
				yume_assert_false( false !== stripos( $texte, 'kappa' ), $nom . ' : brouillon non marqué invisible' );
			}
			foreach ( array( 'planning', 'accueil', 'upcoming', 'calendrier', 'planning-calendrier', 'rest', 'avenir', 'journal', 'rss', 'ics' ) as $nom ) {
				yume_assert_contains( 'Nouvelle série à venir', $sorties[ $nom ], $nom );
			}
			foreach ( array( 'planning', 'accueil', 'upcoming', 'rest' ) as $nom ) {
				yume_assert_contains( 'Tome 1', $sorties[ $nom ], $nom );
			}
			yume_assert_contains( 'Nouvelle série à venir T.1', $sorties['ics'] );
			yume_assert_contains( 'Nouvelle série à venir T.1', $sorties['rss'] );
			yume_assert_contains( 'Grimgar', $sorties['planning'], 'les autres œuvres restent affichées' );

			// REST : nom public, sans sous-titre ni adresse.
			$lignes = array_column( yume_rest( 'GET', '/yume/v1/planning' )->get_data(), null, 'tome_id' );
			yume_assert_true( isset( $lignes[ $d['t1'] ] ), 'Tome 1 au planning public' );
			yume_assert_false( isset( $lignes[ $d['k1'] ] ), 'brouillon non marqué absent' );
			yume_assert_same( 'Nouvelle série à venir', $lignes[ $d['t1'] ]['oeuvre'] );
			yume_assert_same( 'Tome 1', $lignes[ $d['t1'] ]['tome'] );
			yume_assert_same( '', $lignes[ $d['t1'] ]['titre'] );
			yume_assert_same( '', $lignes[ $d['t1'] ]['url'] );
			yume_assert_same( '', $lignes[ $d['t1'] ]['url_oeuvre'] );

			// À la une : visuel neutre, ni lien, ni « Suivre l'œuvre », ni image.
			$une = prochain_tome();
			yume_assert_same( $d['t1'], (int) $une['tome_id'] );
			yume_assert_same( 0, (int) $une['couverture_id'] );
			$html = rendu_a_la_une( $une );
			yume_assert_contains( '<span>?</span>', $html );
			yume_assert_not_contains( '<img', $html );
			yume_assert_not_contains( 'href=', $html );
			yume_assert_not_contains( 'Suivre l’œuvre', $html );
			yume_assert_contains( 'Nouvelle série à venir', $html );

			// Journal public : sous le nom public ; l'équipe lit le vrai titre.
			$public = grouper_journal( lire_journal( array( 'public' => true ) ), false );
			yume_assert_contains( 'Nouvelle série à venir T.1', implode( "\n", array_column( $public, 'cible' ) ) );
			$equipe = grouper_journal( lire_journal( array( 'tome_id' => $d['t1'] ) ), true );
			yume_assert_contains( 'Titre Secret Zeta T.1', implode( "\n", array_column( $equipe, 'cible' ) ) );

			// L'équipe voit les vrais titres (vue de gestion).
			$gestion = array_column( yume_get_planning( array( 'gestion' => true ) ), null, 'tome_id' );
			yume_assert_same( 'Titre Secret Zeta', $gestion[ $d['t1'] ]['oeuvre'] );
			yume_assert_same( 'Le Secret Omega', $gestion[ $d['t1'] ]['titre'] );
			yume_assert_true( $gestion[ $d['t1'] ]['titre_cache'] );
			yume_assert_same( 'Projet Invisible Kappa', $gestion[ $d['k1'] ]['oeuvre'] );
			yume_assert_false( $gestion[ $d['k1'] ]['titre_cache'] );
		} finally {
			yume_tsv_nettoyer( $d );
		}
	}
);

yume_tsv_test(
	'Série à venir : nom public choisi partout ; décocher (métas retirées) rend l’œuvre invisible comme tout brouillon',
	static function () {
		$d = yume_tsv_jeu( 'Projet Lune' );
		try {
			$sorties = yume_tsv_sorties_publiques();
			foreach ( array( 'planning', 'accueil', 'upcoming', 'rest', 'journal', 'rss', 'ics' ) as $nom ) {
				yume_assert_contains( 'Projet Lune', $sorties[ $nom ], $nom );
				yume_assert_not_contains( 'Nouvelle série à venir', $sorties[ $nom ], $nom );
				yume_assert_not_contains( 'Titre Secret Zeta', $sorties[ $nom ], $nom );
			}
			delete_post_meta( $d['secret'], 'yume_serie_a_venir' );
			yume_assert_false( yume_oeuvre_a_venir( $d['secret'] ) );
			$sorties = yume_tsv_sorties_publiques();
			foreach ( $sorties as $nom => $texte ) {
				yume_assert_not_contains( 'Projet Lune', $texte, $nom );
				yume_assert_not_contains( 'Titre Secret Zeta', $texte, $nom . ' (cache ICS vidé)' );
			}
		} finally {
			yume_tsv_nettoyer( $d );
		}
	}
);

yume_tsv_test(
	'Série à venir : révélée à la première publication de l’œuvre, d’un tome ou d’un chapitre (journal de l’équipe, jamais public)',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$cas = array();
		foreach ( array( 'oeuvre', 'tome', 'chapitre' ) as $par ) {
			$o           = yume_tsv_oeuvre( 'Série ' . $par, 'draft', array( 'yume_serie_a_venir' => '1' ) );
			$t           = yume_tsv_tome( $o, 1, '', 3, $editeur );
			$cas[ $par ] = array( $o, $t );
		}
		// Le cache ICS est vidé à la révélation.
		set_transient( 'yume_planning_ics', array( 0 => 'EN CACHE' ), HOUR_IN_SECONDS );

		wp_update_post(
			array(
				'ID'          => $cas['oeuvre'][0],
				'post_status' => 'publish',
			)
		);
		yume_assert_false( get_transient( 'yume_planning_ics' ), 'cache ICS vidé' );
		wp_update_post(
			array(
				'ID'          => $cas['tome'][1],
				'post_status' => 'publish',
			)
		);
		$chapitre = yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_title'  => 'Chapitre 1',
				'post_status' => 'draft',
				'meta_input'  => array(
					'yume_tome_id'   => $cas['chapitre'][1],
					'yume_oeuvre_id' => $cas['chapitre'][0],
					'yume_numero'    => 1,
				),
			)
		);
		yume_assert_true( yume_oeuvre_a_venir( $cas['chapitre'][0] ), 'chapitre en brouillon : rien de révélé' );
		wp_update_post(
			array(
				'ID'          => $chapitre,
				'post_status' => 'publish',
			)
		);
		foreach ( $cas as $par => $ids ) {
			yume_assert_false( yume_oeuvre_a_venir( $ids[0] ), $par );
			yume_assert_same( '', (string) get_post_meta( $ids[0], 'yume_serie_a_venir', true ), $par . ' : méta retirée' );
		}
		$lignes = lire_journal( array( 'champs' => array( 'serie_a_venir' ) ) );
		yume_assert_same( 3, count( $lignes ) );
		foreach ( $lignes as $ligne ) {
			yume_assert_same( '0', (string) $ligne->public, 'journal de l’équipe seulement' );
		}
		$textes = implode( "\n", array_merge( ...array_column( grouper_journal( $lignes, true ), 'parties' ) ) );
		yume_assert_contains( 'titre de « Série tome » révélé au public (première publication)', $textes );
		yume_assert_contains( 'titre de « Série chapitre » révélé au public (première publication)', $textes );
		yume_assert_same( array(), grouper_journal( $lignes, false ), 'rien en public' );
		// Un brouillon révélé redevient un brouillon ordinaire : absent du planning public.
		wp_set_current_user( 0 );
		$ids = array_column( yume_get_planning(), 'tome_id' );
		yume_assert_false( in_array( $cas['chapitre'][1], $ids, true ) );
	}
);

yume_tsv_test(
	'Formulaires de l’œuvre : « Annonce au planning » (création, modification, nom personnalisé, décocher), Nouveau tome accepte le brouillon',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Annonce au planning', $html );
		yume_assert_contains( 'name="serie_a_venir" value="1"', $html );
		yume_assert_contains( 'Série à venir : cacher le titre au public', $html );
		yume_assert_contains( 'Nom affiché au public', $html );
		yume_assert_contains( 'placeholder="Nouvelle série à venir"', $html );
		yume_assert_contains( 'maxlength="80"', $html );

		$post   = static function ( array $champs ) {
			return wp_slash(
				array_merge(
					array(
						'action'      => 'yume_oeuvre_creer',
						'_yume_nonce' => wp_create_nonce( 'yume_oeuvre_creer' ),
					),
					$champs
				)
			);
		};
		$retour = traiter_formulaire_oeuvre(
			$post(
				array(
					'titre'         => 'Titre Secret Zeta',
					'serie_a_venir' => '1',
					'publier'       => '0',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$id = (int) $retour['oeuvre_id'];
		yume_assert_same( 'draft', get_post_status( $id ) );
		yume_assert_true( yume_oeuvre_a_venir( $id ) );
		yume_assert_same( '', (string) get_post_meta( $id, 'yume_libelle_a_venir', true ) );
		yume_assert_contains( '« Nouvelle série à venir »', $retour['message'] );

		// Liste et formulaire « Modifier » : vrai titre, pastille, case cochée.
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Titre Secret Zeta', $html );
		yume_assert_contains( 'Titre caché au public', $html );
		$_GET['modifier'] = (string) $id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html             = rendu_vue_oeuvres();
		unset( $_GET['modifier'] );
		yume_assert_contains( 'id="yn-oeuvre-a-venir" name="serie_a_venir" value="1" checked=\'checked\'', $html );

		// « Nouveau tome » : l'œuvre en brouillon est proposée et le Tome 1 se crée.
		yume_assert_contains( 'Titre Secret Zeta (brouillon)', formulaire_nouveau_tome( membres_equipe(), null, $id ) );
		$t1 = yume_tsv_tome( $id, 1, '', 4, $editeur );
		yume_assert_same( $id, yume_get_oeuvre_id( $t1 ) );

		// Pastille dans les vues de l'équipe (planning complet, tous les tomes, modifier le tome).
		yume_assert_contains( 'Titre caché au public', rendu_vue_planning(), 'planning complet' );
		yume_assert_contains( 'Titre caché au public', rendu_vue_tomes(), 'tous les tomes' );
		$fiche = rendu_modifier_tome( $t1 );
		yume_assert_contains( 'Titre caché au public', $fiche, 'modifier le tome' );
		yume_assert_contains( 'Titre Secret Zeta', $fiche );

		// Modification : nom personnalisé.
		$modifier = static function ( array $champs ) use ( $id ) {
			return wp_slash(
				array_merge(
					array(
						'action'      => 'yume_oeuvre_modifier',
						'oeuvre_id'   => (string) $id,
						'_yume_nonce' => wp_create_nonce( 'yume_oeuvre_modifier_' . $id ),
						'titre'       => 'Titre Secret Zeta',
					),
					$champs
				)
			);
		};
		$retour   = traiter_formulaire_oeuvre(
			$modifier(
				array(
					'serie_a_venir'   => '1',
					'libelle_a_venir' => '  Projet <i>mystère</i>  ',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_same( 'Projet mystère', yume_titre_public_oeuvre( $id ) );
		wp_set_current_user( 0 );
		yume_assert_contains( 'Projet mystère', yume_render_block( 'yume/planning' ) );
		wp_set_current_user( $editeur );

		// Décocher : titre révélé, métas retirées, journal de l'équipe.
		$retour = traiter_formulaire_oeuvre( $modifier( array( 'libelle_a_venir' => 'Projet mystère' ) ), array(), $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_false( yume_oeuvre_a_venir( $id ) );
		yume_assert_same( '', (string) get_post_meta( $id, 'yume_libelle_a_venir', true ) );
		$textes = implode( "\n", array_merge( ...array_column( grouper_journal( lire_journal( array( 'champs' => array( 'serie_a_venir' ) ) ), true ), 'parties' ) ) );
		yume_assert_contains( 'titre de « Titre Secret Zeta » caché au public : série annoncée sous le nom « Nouvelle série à venir »', $textes );
		yume_assert_contains( 'nom public de « Titre Secret Zeta » : « Projet mystère »', $textes );
		yume_assert_contains( 'titre de « Titre Secret Zeta » révélé au public', $textes );
		yume_assert_not_contains( 'Titre caché au public', rendu_vue_oeuvres() );

		// « Créer et publier » avec la case cochée : rien à cacher.
		$retour = traiter_formulaire_oeuvre(
			$post(
				array(
					'titre'         => 'Œuvre publiée',
					'serie_a_venir' => '1',
					'publier'       => '1',
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'publish', get_post_status( (int) $retour['oeuvre_id'] ) );
		yume_assert_same( '', (string) get_post_meta( (int) $retour['oeuvre_id'], 'yume_serie_a_venir', true ) );
		// Œuvre publiée : section absente du formulaire « Modifier ».
		$_GET['modifier'] = (string) $retour['oeuvre_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html             = rendu_vue_oeuvres();
		unset( $_GET['modifier'] );
		yume_assert_contains( 'id="yn-oeuvre-form"', $html );
		yume_assert_not_contains( 'Annonce au planning', $html );
	}
);
