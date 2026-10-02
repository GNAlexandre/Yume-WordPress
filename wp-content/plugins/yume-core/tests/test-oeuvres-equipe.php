<?php
/**
 * Tests de la vue « Œuvres » de l'espace équipe (?vue=oeuvres, includes/planning/
 * oeuvres-equipe.php) : droits (edit_yume_oeuvres), création d'une œuvre (brouillon ou publiée,
 * champs, genres, couverture), refus (titre vide ou déjà pris, type inconnu, nonce), bouton
 * « Publier », liste (brouillons compris, filtres, actions), entrée de navigation et
 * présélection de l'œuvre dans « Nouveau tome » (« Ajouter un tome au planning »).
 *
 * Lancement : tools/localenv/test.sh oeuvres-equipe
 *
 * @package Yume\Core
 */

use function Yume\Core\Planning\creer_oeuvre;
use function Yume\Core\Planning\formulaire_nouveau_tome;
use function Yume\Core\Planning\url_nouveau_tome;
use function Yume\Core\Planning\modifier_oeuvre;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\nettoyer_synopsis;
use function Yume\Core\Planning\oeuvres_equipe;
use function Yume\Core\Planning\rendu_vue_oeuvres;
use function Yume\Core\Planning\valeurs_oeuvre;
use function Yume\Core\Planning\saisie_oeuvre;
use function Yume\Core\Planning\synopsis_en_blocs;
use function Yume\Core\Planning\synopsis_texte;
use function Yume\Core\Planning\traiter_genre;
use function Yume\Core\Planning\traiter_formulaire_oeuvre;
use function Yume\Core\Planning\traiter_publication_oeuvre;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_toe_)
 * -----------------------------------------------------------------------------
 */

/**
 * Test isolé : œuvres existantes vidées (transaction annulée ensuite par le lanceur), fichiers
 * locaux acceptés comme téléversés, pièces jointes créées supprimées du disque à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps (reçoit le contexte : medias, fichiers).
 */
function yume_toe_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$types = "'yume_oeuvre', 'yume_tome', 'yume_chapitre'";
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) )" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( $types )" ); // phpcs:ignore
			wp_cache_flush();
			\Yume\Core\Core\installer_roles();
			$ctx           = new stdClass();
			$ctx->medias   = array();
			$ctx->fichiers = array();
			$suivre        = static function ( $id ) use ( $ctx ) {
				$ctx->medias[] = (int) $id;
			};
			add_filter( 'yume_publication_fichier_local', '__return_true' );
			add_action( 'add_attachment', $suivre );
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps( $ctx );
			} finally {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				remove_filter( 'yume_publication_fichier_local', '__return_true' );
				remove_action( 'add_attachment', $suivre );
				foreach ( $ctx->medias as $id ) {
					wp_delete_attachment( $id, true );
				}
				foreach ( $ctx->fichiers as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
				delete_transient( 'yume_planning_retour_' . get_current_user_id() );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Données POST du formulaire « Nouvelle œuvre » (nonce du compte courant).
 *
 * @param array $champs Champs.
 */
function yume_toe_post( array $champs ): array {
	return wp_slash(
		array_merge(
			array(
				'action'      => 'yume_oeuvre_creer',
				'_yume_nonce' => wp_create_nonce( 'yume_oeuvre_creer' ),
			),
			$champs
		)
	);
}

/**
 * Image PNG temporaire, présentée comme une entrée de $_FILES.
 *
 * @param stdClass $ctx Contexte (fichier supprimé à la fin).
 */
function yume_toe_image( stdClass $ctx ): array {
	$chemin = wp_tempnam( 'yume-couverture.png' );
	$image  = imagecreatetruecolor( 200, 300 );
	imagefill( $image, 0, 0, imagecolorallocate( $image, 120, 60, 160 ) );
	imagepng( $image, $chemin );
	imagedestroy( $image );
	$ctx->fichiers[] = $chemin;
	return array(
		'name'     => 'couverture.png',
		'type'     => 'image/png',
		'tmp_name' => $chemin,
		'error'    => UPLOAD_ERR_OK,
		'size'     => (int) filesize( $chemin ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_toe_test(
	'Œuvres : vue et création réservées à edit_yume_oeuvres (Éditeur Yume, Gérant), entrée de navigation',
	static function () {
		$traducteur = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $traducteur );
		yume_assert_not_contains( 'vue=oeuvres', navigation_equipe( 'tableau' ) );
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'Seuls les rôles « Éditeur Yume » et « Gérant »', $html );
		yume_assert_not_contains( 'id="yn-oeuvre-form"', $html );
		$refus = creer_oeuvre( saisie_oeuvre( wp_slash( array( 'titre' => 'Interdite' ) ) ), null, $traducteur );
		yume_assert_true( is_wp_error( $refus ) );
		yume_assert_same( 'yume_oeuvre_interdit', $refus->get_error_code() );
		$retour = traiter_formulaire_oeuvre( yume_toe_post( array( 'titre' => 'Interdite' ) ), array(), $traducteur );
		yume_assert_same( 'erreur', $retour['type'] );
		yume_assert_same( array(), oeuvres_equipe() );

		foreach ( array( 'yume_editeur', 'yume_gerant', 'administrator' ) as $role ) {
			wp_set_current_user( yume_factory_user( $role ) );
			yume_assert_contains( 'vue=oeuvres', navigation_equipe( 'tableau' ), $role );
			yume_assert_contains( 'id="yn-oeuvre-form"', rendu_vue_oeuvres(), $role );
		}
	}
);

yume_toe_test(
	'Nouvelle œuvre : brouillon par défaut, titre, synopsis en paragraphes, fiche, type, statut, genres (existants et nouveaux)',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		wp_insert_term( 'Fantasy', 'yume_genre' );
		$retour = traiter_formulaire_oeuvre(
			yume_toe_post(
				array(
					'titre'           => 'Grimgar of Fantasy & Ash',
					'titres_alt'      => "Hai to Gensō no Grimgar\n灰と幻想のグリムガル\n\nHai to Gensō no Grimgar",
					'type'            => 'light-novel',
					'avancement'      => 'en-cours',
					'genres'          => array( 'fantasy', 'inconnu' ),
					'nouveaux_genres' => 'Aventure, Drame , Aventure',
					'auteur'          => 'Ao Jyumonji',
					'illustrateur'    => 'Eiri Shirai',
					'editeur_vo'      => 'Overlap',
					'synopsis'        => "Ils se réveillent dans le noir.\nSans souvenirs.\n\nIls doivent <b>survivre</b>.",
				)
			),
			array(),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$id = $retour['oeuvre_id'];
		yume_assert_same( 'yume_oeuvre', get_post_type( $id ) );
		yume_assert_same( 'draft', get_post_status( $id ) );
		yume_assert_contains( 'brouillon', $retour['message'] );
		yume_assert_same( 'yn-oeuvre-' . $id, $retour['cible'] );
		yume_assert_same( 'Grimgar of Fantasy &amp; Ash', get_post_field( 'post_title', $id ) );
		yume_assert_same( $editeur, (int) get_post_field( 'post_author', $id ) );
		$contenu = (string) get_post_field( 'post_content', $id );
		yume_assert_same( 2, substr_count( $contenu, '<!-- wp:paragraph -->' ) );
		yume_assert_contains( 'Ils se réveillent dans le noir.<br>', $contenu );
		yume_assert_contains( '<p>Ils doivent <b>survivre</b>.</p>', $contenu, 'gras conservé' );
		yume_assert_same( array( 'Hai to Gensō no Grimgar', '灰と幻想のグリムガル' ), get_post_meta( $id, 'yume_titres_alt', true ) );
		yume_assert_same( 'Ao Jyumonji', get_post_meta( $id, 'yume_auteur', true ) );
		yume_assert_same( 'Eiri Shirai', get_post_meta( $id, 'yume_illustrateur', true ) );
		yume_assert_same( 'Overlap', get_post_meta( $id, 'yume_editeur_vo', true ) );
		yume_assert_same( array( 'light-novel' ), wp_get_object_terms( $id, 'yume_type', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array( 'en-cours' ), wp_get_object_terms( $id, 'yume_statut', array( 'fields' => 'slugs' ) ) );
		$genres = wp_get_object_terms( $id, 'yume_genre', array( 'fields' => 'slugs' ) );
		sort( $genres );
		yume_assert_same( array( 'aventure', 'drame', 'fantasy' ), $genres );
		yume_assert_false( (bool) term_exists( 'inconnu', 'yume_genre' ), 'genre coché inconnu ignoré' );
		yume_assert_false( (bool) get_post_meta( $id, 'yume_banniere_id', true ), 'pas de bannière' );

		// Même titre : refusé (pas de doublon), saisie conservée pour le formulaire.
		$doublon = traiter_formulaire_oeuvre( yume_toe_post( array( 'titre' => 'Grimgar of Fantasy & Ash' ) ), array(), $editeur );
		yume_assert_same( 'erreur', $doublon['type'] );
		yume_assert_contains( 'existe déjà', $doublon['message'] );
		yume_assert_same( 'Grimgar of Fantasy & Ash', $doublon['saisie']['titre'] );
		yume_assert_same( 1, count( oeuvres_equipe() ) );
	}
);

yume_toe_test(
	'Nouvelle œuvre : « Créer et publier », couverture versée (miniature), sans annonce ; rôle sans manage_categories : nouveaux genres ignorés',
	static function ( $ctx ) {
		$gerant = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$annonces = 0;
		$compter  = static function () use ( &$annonces ) {
			++$annonces;
		};
		add_action( 'yume_tome_publie', $compter );
		try {
			$retour = traiter_formulaire_oeuvre(
				yume_toe_post(
					array(
						'titre'   => 'Lanternes',
						'type'    => 'manga',
						'publier' => '1',
					)
				),
				array( 'couverture' => yume_toe_image( $ctx ) ),
				$gerant
			);
		} finally {
			remove_action( 'yume_tome_publie', $compter );
		}
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$id = $retour['oeuvre_id'];
		yume_assert_same( 'publish', get_post_status( $id ) );
		yume_assert_contains( 'publiée', $retour['message'] );
		$couverture = (int) get_post_thumbnail_id( $id );
		yume_assert_true( $couverture > 0 && wp_attachment_is_image( $couverture ), 'couverture' );
		yume_assert_same( $id, (int) get_post_field( 'post_parent', $couverture ) );
		yume_assert_same( 0, $annonces );

		// Couverture non image : refusée avant toute création.
		$faux = wp_tempnam( 'faux.png' );
		file_put_contents( $faux, 'pas une image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$ctx->fichiers[] = $faux;
		$refus           = traiter_formulaire_oeuvre(
			yume_toe_post( array( 'titre' => 'Sans image' ) ),
			array(
				'couverture' => array(
					'name'     => 'faux.png',
					'type'     => 'image/png',
					'tmp_name' => $faux,
					'error'    => UPLOAD_ERR_OK,
					'size'     => 13,
				),
			),
			$gerant
		);
		yume_assert_same( 'erreur', $refus['type'] );
		yume_assert_contains( 'Couverture refusée', $refus['message'] );
		yume_assert_same( 1, count( oeuvres_equipe() ) );

		// Éditeur sans manage_categories : œuvre créée, nouveaux genres ignorés et signalés.
		$role = get_role( 'yume_editeur' );
		$role->remove_cap( 'manage_categories' );
		try {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$retour = traiter_formulaire_oeuvre(
				yume_toe_post(
					array(
						'titre'           => 'Sans nouveaux genres',
						'nouveaux_genres' => 'Cultivation',
						'publier'         => '1',
					)
				),
				array(),
				$editeur
			);
		} finally {
			$role->add_cap( 'manage_categories' );
		}
		yume_assert_same( 'ok', $retour['type'] );
		yume_assert_contains( 'Nouveaux genres ignorés', $retour['message'] );
		yume_assert_false( (bool) term_exists( 'Cultivation', 'yume_genre' ) );
	}
);

yume_toe_test(
	'Nouvelle œuvre : titre vide, type ou statut inconnu, nonce invalide refusés',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		yume_assert_contains( 'Indiquez le titre', traiter_formulaire_oeuvre( yume_toe_post( array( 'titre' => '   ' ) ), array(), $editeur )['message'] );
		$type = traiter_formulaire_oeuvre(
			yume_toe_post(
				array(
					'titre' => 'X',
					'type'  => 'roman-photo',
				)
			),
			array(),
			$editeur
		);
		yume_assert_contains( 'Type d’œuvre inconnu', $type['message'] );
		$statut = traiter_formulaire_oeuvre(
			yume_toe_post(
				array(
					'titre'      => 'X',
					'avancement' => 'oubliee',
				)
			),
			array(),
			$editeur
		);
		yume_assert_contains( 'État de l’œuvre inconnu', $statut['message'] );
		$nonce = traiter_formulaire_oeuvre(
			wp_slash(
				array(
					'titre'       => 'X',
					'_yume_nonce' => 'faux',
				)
			),
			array(),
			$editeur
		);
		yume_assert_contains( 'session a expiré', $nonce['message'] );
		yume_assert_same( array(), oeuvres_equipe() );
	}
);

yume_toe_test(
	'Œuvres : bouton « Publier » d\'un brouillon (nonce, droits, brouillon seulement)',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$id   = creer_oeuvre( saisie_oeuvre( wp_slash( array( 'titre' => 'À publier' ) ) ), null, $editeur )['id'];
		$post = static function ( int $id, string $nonce ): array {
			return array(
				'oeuvre_id'   => (string) $id,
				'_yume_nonce' => $nonce,
			);
		};
		yume_assert_contains( 'session a expiré', traiter_publication_oeuvre( $post( $id, 'faux' ), $editeur )['message'] );
		yume_assert_same( 'draft', get_post_status( $id ) );

		$traducteur = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $traducteur );
		$refus = traiter_publication_oeuvre( $post( $id, wp_create_nonce( 'yume_oeuvre_publier_' . $id ) ), $traducteur );
		yume_assert_contains( 'ne permet pas', $refus['message'] );
		yume_assert_same( 'draft', get_post_status( $id ) );

		wp_set_current_user( $editeur );
		$ok = traiter_publication_oeuvre( $post( $id, wp_create_nonce( 'yume_oeuvre_publier_' . $id ) ), $editeur );
		yume_assert_same( 'ok', $ok['type'], $ok['message'] );
		yume_assert_same( 'publish', get_post_status( $id ) );
		$encore = traiter_publication_oeuvre( $post( $id, wp_create_nonce( 'yume_oeuvre_publier_' . $id ) ), $editeur );
		yume_assert_contains( 'n’est pas un brouillon', $encore['message'] );
	}
);

yume_toe_test(
	'Œuvres : liste (brouillons compris, tri, filtres), actions, retour affiché ; « Ajouter un tome au planning » présélectionne l\'œuvre',
	static function () {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$brouillon = creer_oeuvre( saisie_oeuvre( wp_slash( array( 'titre' => 'Zeta brouillon' ) ) ), null, $editeur )['id'];
		$publiee   = creer_oeuvre(
			saisie_oeuvre(
				wp_slash(
					array(
						'titre'   => 'Alpha publiée',
						'type'    => 'web-novel',
						'publier' => '1',
					)
				)
			),
			null,
			$editeur
		)['id'];
		yume_assert_same( array( 'Alpha publiée', 'Zeta brouillon' ), wp_list_pluck( oeuvres_equipe(), 'titre' ) );
		yume_assert_same( array( 'Zeta brouillon' ), wp_list_pluck( oeuvres_equipe( array( 'statut' => 'brouillon' ) ), 'titre' ) );
		yume_assert_same( array( 'Alpha publiée' ), wp_list_pluck( oeuvres_equipe( array( 'statut' => 'publie' ) ), 'titre' ) );
		yume_assert_same( array( 'Zeta brouillon' ), wp_list_pluck( oeuvres_equipe( array( 'recherche' => 'zeta' ) ), 'titre' ) );

		set_transient(
			'yume_planning_retour_' . $editeur,
			array(
				'cible'     => 'yn-oeuvre-' . $brouillon,
				'type'      => 'ok',
				'message'   => 'Message de création',
				'oeuvre_id' => $brouillon,
			),
			60
		);
		$html = rendu_vue_oeuvres();
		yume_assert_contains( '2 œuvres', $html );
		yume_assert_contains( 'Message de création', $html );
		yume_assert_contains( 'Web novel', $html );
		yume_assert_contains( 'name="action" value="yume_oeuvre_publier"', $html );
		yume_assert_contains( 'value="yume_oeuvre_publier"><input type="hidden" name="oeuvre_id" value="' . $brouillon . '"', $html );
		yume_assert_not_contains( 'value="yume_oeuvre_publier"><input type="hidden" name="oeuvre_id" value="' . $publiee . '"', $html, 'pas de « Publier » pour une œuvre publiée' );
		yume_assert_contains( esc_url( (string) get_permalink( $publiee ) ), $html );
		yume_assert_contains( '>Modifier<', $html );
		yume_assert_contains( esc_url( url_vue_equipe( 'oeuvres', array( 'modifier' => $brouillon ) ) ), $html );
		yume_assert_contains( esc_url( url_nouveau_tome( $brouillon, 'oeuvres' ) ), $html, '« Ajouter un tome au planning » : vue « Nouveau tome »' );
		yume_assert_contains( esc_url( add_query_arg( 'oeuvre', $brouillon, yume_url_page( 'publier' ) ) ), $html );
		yume_assert_contains( 'enctype="multipart/form-data"', $html );
		yume_assert_contains( 'value="yume_oeuvre_creer"', $html );
		yume_assert_contains( 'Créer et publier', $html );
		yume_assert_false( (bool) get_transient( 'yume_planning_retour_' . $editeur ), 'retour lu une fois' );

		yume_assert_contains( '<option value="' . $brouillon . '" selected=\'selected\'>', formulaire_nouveau_tome( array(), null, $brouillon, 'oeuvres' ) );
	}
);

yume_test(
	'Synopsis : paragraphes séparés par une ligne vide, sauts de ligne, gras et italique conservés, le reste retiré ; texte relu depuis la fiche',
	static function () {
		yume_assert_same( '', synopsis_en_blocs( nettoyer_synopsis( "  \n\n " ) ) );
		yume_assert_same( "<!-- wp:paragraph -->\n<p>Un<br>\ndeux</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p><i>trois</i> alert(1)</p>\n<!-- /wp:paragraph -->", synopsis_en_blocs( nettoyer_synopsis( "Un\r\ndeux\n\n\n<i>trois</i> <script>alert(1)</script>" ) ) );
		$id = yume_factory_post(
			array(
				'post_type'    => 'yume_oeuvre',
				'post_title'   => 'Relue',
				'post_content' => "<!-- wp:paragraph -->\n<p><strong>Monica</strong> vit seule &amp; cachée.<br>Loin.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list --><ul><li>Un point</li></ul><!-- /wp:list -->",
			)
		);
		yume_assert_same( "<strong>Monica</strong> vit seule & cachée.\nLoin.\n\nUn point", synopsis_texte( $id ) );
	}
);

yume_toe_test(
	'Modifier une œuvre : formulaire prérempli (?modifier=ID), fiche détaillée, synopsis d\'origine conservé s\'il n\'est pas modifié, couverture remplacée',
	static function ( $ctx ) {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$id = yume_factory_post(
			array(
				'post_type'    => 'yume_oeuvre',
				'post_title'   => 'Silent Witch',
				'post_content' => "<!-- wp:paragraph -->\n<p><strong>Monica</strong> a des secrets.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list --><ul><li>Liste d'origine</li></ul><!-- /wp:list -->",
				'meta_input'   => array(
					'yume_auteur'       => 'Isora Matsuri',
					'yume_liens'        => array(
						array(
							'label' => 'Novel-Index',
							'url'   => 'https://novel-index.com/silent-witch',
						),
					),
					'yume_jours_sortie' => array( 'lundi' ),
				),
			)
		);
		wp_set_object_terms( $id, 'web-novel', 'yume_type' );

		$_GET['modifier'] = (string) $id;
		$html             = rendu_vue_oeuvres();
		yume_assert_contains( 'Modifier « Silent Witch »', $html );
		yume_assert_contains( 'value="yume_oeuvre_modifier"', $html );
		yume_assert_contains( 'name="oeuvre_id" value="' . $id . '"', $html );
		yume_assert_contains( 'value="Isora Matsuri"', $html );
		yume_assert_contains( 'value="https://novel-index.com/silent-witch"', $html );
		yume_assert_contains( '<option value="web-novel" selected=\'selected\'>', $html );
		yume_assert_contains( 'value="lundi" checked=\'checked\'', $html );
		yume_assert_contains( '&lt;strong&gt;Monica&lt;/strong&gt; a des secrets.', $html, 'synopsis prérempli avec son gras' );
		yume_assert_contains( '<details class="yn-team__details" open>', $html, 'fiche détaillée ouverte quand elle est remplie' );
		yume_assert_not_contains( 'id="yn-oeuvres-liste"', $html, 'formulaire seul' );

		// Enregistrement sans toucher au synopsis : contenu d'origine intact (liste comprise).
		$post   = valeurs_oeuvre( $id );
		$envoi  = array(
			'action'            => 'yume_oeuvre_modifier',
			'oeuvre_id'         => (string) $id,
			'_yume_nonce'       => wp_create_nonce( 'yume_oeuvre_modifier_' . $id ),
			'titre'             => 'Secrets of the Silent Witch',
			'type'              => 'light-novel',
			'avancement'        => 'terminee',
			'auteur'            => 'Matsuri Isora',
			'synopsis'          => $post['synopsis'],
			'synopsis_origine'  => $post['synopsis_origine'],
			'statut_vo'         => 'termine',
			'nb_tomes_vo'       => '16',
			'source_traduction' => 'Kadokawa',
			'jours_sortie'      => array( 'mardi', 'jeudi' ),
			'equipe'            => array( 'traduction' => 'Pizzflc' ),
			'liens'             => array(
				array(
					'label' => 'Novel-Index',
					'url'   => 'https://novel-index.com/silent-witch',
				),
				array(
					'label' => 'Sans adresse',
					'url'   => '',
				),
			),
		);
		$retour = traiter_formulaire_oeuvre( wp_slash( $envoi ), array( 'couverture' => yume_toe_image( $ctx ) ), $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_contains( 'est enregistrée', $retour['message'] );
		yume_assert_same( 'Secrets of the Silent Witch', get_post_field( 'post_title', $id ) );
		yume_assert_contains( "<ul><li>Liste d'origine</li></ul>", (string) get_post_field( 'post_content', $id ), 'synopsis non modifié : contenu intact' );
		yume_assert_same( 'Matsuri Isora', get_post_meta( $id, 'yume_auteur', true ) );
		yume_assert_same( 'termine', get_post_meta( $id, 'yume_statut_vo', true ) );
		yume_assert_same( 16, (int) get_post_meta( $id, 'yume_nb_tomes_vo', true ) );
		yume_assert_same( array( 'mardi', 'jeudi' ), get_post_meta( $id, 'yume_jours_sortie', true ) );
		yume_assert_same( 'Pizzflc', get_post_meta( $id, 'yume_equipe', true )['traduction'] );
		yume_assert_same( 1, count( get_post_meta( $id, 'yume_liens', true ) ), 'ligne sans adresse ignorée' );
		yume_assert_same( array( 'light-novel' ), wp_get_object_terms( $id, 'yume_type', array( 'fields' => 'slugs' ) ) );
		yume_assert_same( array( 'terminee' ), wp_get_object_terms( $id, 'yume_statut', array( 'fields' => 'slugs' ) ) );
		yume_assert_true( (int) get_post_thumbnail_id( $id ) > 0, 'couverture' );
		yume_assert_same( 'publish', get_post_status( $id ), 'statut de publication inchangé' );

		// Synopsis modifié : réécrit en paragraphes ; champs vidés : méta supprimées.
		$envoi['synopsis']     = "Nouveau <em>synopsis</em>.\n\nDeuxième paragraphe.";
		$envoi['jours_sortie'] = array();
		$envoi['equipe']       = array();
		$envoi['liens']        = array();
		$envoi['type']         = '';
		$retour                = traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur );
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_same( "<!-- wp:paragraph -->\n<p>Nouveau <em>synopsis</em>.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Deuxième paragraphe.</p>\n<!-- /wp:paragraph -->", (string) get_post_field( 'post_content', $id ) );
		yume_assert_false( metadata_exists( 'post', $id, 'yume_jours_sortie' ), 'yume_jours_sortie supprimée' );
		yume_assert_false( metadata_exists( 'post', $id, 'yume_equipe' ), 'yume_equipe supprimée' );
		yume_assert_false( metadata_exists( 'post', $id, 'yume_liens' ), 'yume_liens supprimée' );
		yume_assert_same( array(), wp_get_object_terms( $id, 'yume_type', array( 'fields' => 'slugs' ) ) );

		// Titre d'une autre œuvre refusé ; son propre titre accepté ; saisie reprise dans le formulaire.
		yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Grimgar',
			)
		);
		$envoi['titre'] = 'grimgar';
		$refus          = traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur );
		yume_assert_same( 'erreur', $refus['type'] );
		yume_assert_same( $id, $refus['modifier'] );
		yume_assert_contains( 'existe déjà', $refus['message'] );
		set_transient( 'yume_planning_retour_' . $editeur, $refus, 60 );
		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'value="grimgar"', $html, 'saisie reprise' );
		yume_assert_contains( 'existe déjà', $html );

		// Nonce, droits.
		$envoi['titre']       = 'Secrets of the Silent Witch';
		$envoi['_yume_nonce'] = wp_create_nonce( 'yume_oeuvre_creer' );
		yume_assert_contains( 'session a expiré', traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur )['message'] );
		$traducteur = yume_factory_user( 'yume_traducteur' );
		yume_assert_true( is_wp_error( modifier_oeuvre( $id, saisie_oeuvre( wp_slash( $envoi ) ), null, $traducteur ) ) );
		wp_set_current_user( $traducteur );
		$_GET['modifier'] = (string) $id;
		yume_assert_not_contains( 'value="yume_oeuvre_modifier"', rendu_vue_oeuvres() );
	}
);

yume_toe_test(
	'Genres : genres proposés d\'office (une seule fois), ajout (sans doublon, casse ignorée) et suppression depuis la vue',
	static function () {
		yume_assert_true( (bool) get_option( 'yume_genres_proposes' ) );
		foreach ( array( 'Action', 'Isekai', 'Romance', 'Slice of life', 'Shōnen' ) as $nom ) {
			yume_assert_true( (bool) term_exists( $nom, 'yume_genre' ), $nom );
		}
		$isekai = get_term_by( 'name', 'Isekai', 'yume_genre' );
		wp_delete_term( $isekai->term_id, 'yume_genre' );
		\Yume\Core\Core\installer_genres();
		yume_assert_false( (bool) term_exists( 'Isekai', 'yume_genre' ), 'un genre supprimé ne revient pas' );

		$gerant = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$post  = static function ( array $champs ): array {
			return wp_slash( array_merge( array( '_yume_nonce' => wp_create_nonce( 'yume_genre_equipe' ) ), $champs ) );
		};
		$ajout = traiter_genre(
			$post(
				array(
					'op'  => 'ajouter',
					'nom' => 'Cultivation, romance',
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $ajout['type'], $ajout['message'] );
		yume_assert_contains( 'Cultivation, Romance', $ajout['message'], 'genre existant retrouvé, casse ignorée' );
		yume_assert_same(
			1,
			count(
				get_terms(
					array(
						'taxonomy'   => 'yume_genre',
						'hide_empty' => false,
						'name'       => 'Romance',
					)
				)
			)
		);

		$html = rendu_vue_oeuvres();
		yume_assert_contains( 'id="yn-genres"', $html );
		yume_assert_contains( 'value="yume_genre_equipe"', $html );
		yume_assert_contains( 'Cultivation <span class="yn-muted">0 œuvre', $html );
		yume_assert_contains( 'id="yn-oeuvre-genre-cultivation"', $html, 'proposé dans le formulaire' );

		$terme = get_term_by( 'name', 'Cultivation', 'yume_genre' );
		$suppr = traiter_genre(
			$post(
				array(
					'op'    => 'supprimer',
					'genre' => (string) $terme->term_id,
				)
			),
			$gerant
		);
		yume_assert_same( 'ok', $suppr['type'], $suppr['message'] );
		yume_assert_false( (bool) term_exists( 'Cultivation', 'yume_genre' ) );

		$traducteur = yume_factory_user( 'yume_traducteur' );
		wp_set_current_user( $traducteur );
		$refus = traiter_genre(
			$post(
				array(
					'op'  => 'ajouter',
					'nom' => 'Interdit',
				)
			),
			$traducteur
		);
		yume_assert_same( 'erreur', $refus['type'] );
		yume_assert_false( (bool) term_exists( 'Interdit', 'yume_genre' ) );
		yume_assert_contains( 'session a expiré', traiter_genre( array( 'op' => 'ajouter' ), $gerant )['message'] );
	}
);

yume_toe_test(
	'Cadrage de la couverture : enregistré sur l\'image, cartes non rognées positionnées sur le point, centre = retour à la couverture rognée',
	static function ( $ctx ) {
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$retour = traiter_formulaire_oeuvre(
			yume_toe_post(
				array(
					'titre'     => 'Cadrée',
					'publier'   => '1',
					'cadrage_x' => '20',
					'cadrage_y' => '35',
				)
			),
			array( 'couverture' => yume_toe_image( $ctx ) ),
			$editeur
		);
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		$id    = $retour['oeuvre_id'];
		$image = (int) get_post_thumbnail_id( $id );
		yume_assert_same(
			array(
				'x' => 20,
				'y' => 35,
			),
			yume_cadrage_couverture( $image )
		);
		$html = \Yume\Core\Library\couverture( $image, array( 'alt' => 'Couverture' ) );
		yume_assert_contains( 'object-position:20% 35%', $html );
		yume_assert_not_contains( '-480x720', $html, 'taille non rognée' );

		// Formulaire « Modifier » : curseurs aux valeurs enregistrées, aperçu de la couverture.
		$_GET['modifier'] = (string) $id;
		$vue              = rendu_vue_oeuvres();
		yume_assert_contains( 'name="cadrage_x" min="0" max="100" step="1" value="20"', $vue );
		yume_assert_contains( 'name="cadrage_y" min="0" max="100" step="1" value="35"', $vue );
		yume_assert_contains( 'data-yn-cadrage-image', $vue );

		// Recentré : méta supprimée, retour à la taille rognée au centre.
		$valeurs = valeurs_oeuvre( $id );
		$envoi   = array(
			'oeuvre_id'        => (string) $id,
			'_yume_nonce'      => wp_create_nonce( 'yume_oeuvre_modifier_' . $id ),
			'titre'            => 'Cadrée',
			'synopsis'         => $valeurs['synopsis'],
			'synopsis_origine' => $valeurs['synopsis_origine'],
			'cadrage_x'        => '50',
			'cadrage_y'        => '50',
		);
		yume_assert_same( 'ok', traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur )['type'] );
		yume_assert_same( null, yume_cadrage_couverture( $image ) );
		yume_assert_not_contains( 'object-position', \Yume\Core\Library\couverture( $image ) );

		// Valeurs hors bornes ramenées entre 0 et 100 ; formulaire sans curseurs : cadrage inchangé.
		$envoi['cadrage_x'] = '140';
		$envoi['cadrage_y'] = '-5';
		traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur );
		yume_assert_same(
			array(
				'x' => 100,
				'y' => 0,
			),
			yume_cadrage_couverture( $image )
		);
		unset( $envoi['cadrage_x'], $envoi['cadrage_y'] );
		traiter_formulaire_oeuvre( wp_slash( $envoi ), array(), $editeur );
		yume_assert_same( 100, yume_cadrage_couverture( $image )['x'] );
	}
);
