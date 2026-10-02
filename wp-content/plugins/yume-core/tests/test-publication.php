<?php
/**
 * Tests du module publication : service (création, réutilisation d'un tome planifié, mise
 * à jour en place, images, annonce, publication immédiate ou programmée, émission unique de
 * yume_tome_publie, suppression du fichier source), REST (permissions, validation,
 * multipart), bloc yume/publish-form et envoi sans JavaScript.
 *
 * @package Yume\Core
 */

use Yume\Core\Publication\Annonce;
use Yume\Core\Publication\Fichiers;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Medias;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_timp_fixture' ) ) {
	// Fonctions d'aide (fixtures, validité des blocs) sans les tests du module import.
	$GLOBALS['yume_tests_import_aides_seules'] = true;
	require __DIR__ . '/test-import.php';
	unset( $GLOBALS['yume_tests_import_aides_seules'] );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! function_exists( 'yume_tpub' ) ) {
	/**
	 * Enveloppe un test de publication : fichiers locaux acceptés comme téléversés, pièces
	 * jointes créées supprimées du disque à la fin, compteur did_action remis à zéro.
	 *
	 * @param callable $corps Corps du test (reçoit l'objet de contexte).
	 */
	function yume_tpub( callable $corps ): callable {
		return static function () use ( $corps ) {
			$ctx           = new stdClass();
			$ctx->medias   = array();
			$ctx->emis     = array();
			$ctx->fichiers = array();
			$suivre        = static function ( $id ) use ( $ctx ) {
				$ctx->medias[] = (int) $id;
			};
			$compter       = static function ( $id ) use ( $ctx ) {
				$ctx->emis[] = (int) $id;
			};
			add_filter( 'yume_publication_fichier_local', '__return_true' );
			add_action( 'add_attachment', $suivre );
			add_action( 'yume_tome_publie', $compter, 1 );
			try {
				$corps( $ctx );
			} finally {
				remove_filter( 'yume_publication_fichier_local', '__return_true' );
				remove_action( 'add_attachment', $suivre );
				remove_action( 'yume_tome_publie', $compter, 1 );
				foreach ( $ctx->medias as $id ) {
					wp_delete_attachment( $id, true );
				}
				foreach ( $ctx->fichiers as $f ) {
					if ( is_file( $f ) ) {
						wp_delete_file( $f );
					}
				}
				unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
				delete_transient( Formulaire::RETOUR . get_current_user_id() );
			}
		};
	}

	/**
	 * Copie une fixture dans un fichier temporaire et renvoie l'entrée $_FILES correspondante.
	 *
	 * @param stdClass $ctx     Contexte.
	 * @param string   $fixture Fixture (ou chemin absolu).
	 * @param string   $nom     Nom annoncé par le navigateur.
	 * @return array<string,mixed>
	 */
	function yume_tpub_fichier( stdClass $ctx, string $fixture, string $nom = '' ): array {
		$source = is_file( $fixture ) ? $fixture : yume_timp_fixture( $fixture );
		$tmp    = wp_tempnam( 'yume-test' );
		copy( $source, $tmp );
		$ctx->fichiers[] = $tmp;
		return array(
			'name'     => '' !== $nom ? $nom : basename( $source ),
			'type'     => 'application/octet-stream',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		);
	}

	/**
	 * Crée une œuvre publiée.
	 *
	 * @param string $titre Titre.
	 */
	function yume_tpub_oeuvre( string $titre = 'Grimgar de test' ): int {
		return yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => $titre,
			)
		);
	}

	/**
	 * Prépare un tome depuis regles.docx en tant qu'éditeur.
	 *
	 * @param stdClass            $ctx     Contexte.
	 * @param int                 $oeuvre  Œuvre.
	 * @param array<string,mixed> $champs  Champs supplémentaires.
	 * @param string              $fixture Fichier source (fixture).
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Préparation en erreur.
	 */
	function yume_tpub_preparer( stdClass $ctx, int $oeuvre, array $champs = array(), string $fixture = 'regles.docx' ): array {
		$rapport = Service::preparer(
			array_merge(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '10',
					'lien_pdf'  => 'https://www.clictune.com/pdf10',
					'lien_epub' => 'https://www.clictune.com/epub10',
					'credits'   => array(
						'traduction' => 'Calumi',
						'relecture'  => 'Angeloids',
						'edition'    => 'JojoGg',
					),
				),
				$champs
			),
			array( 'source' => yume_tpub_fichier( $ctx, $fixture ) )
		);
		if ( is_wp_error( $rapport ) ) {
			throw new Yume_Test_Failure( 'preparer : ' . $rapport->get_error_message() );
		}
		return $rapport;
	}

	/**
	 * Tome publié il y a un mois et déjà annoncé (_yume_publie_notifie daté), avec des
	 * chapitres en brouillon.
	 *
	 * @param int   $oeuvre   Œuvre.
	 * @param int[] $numeros  Numéros des chapitres brouillons.
	 * @return array{0:int,1:int[]} Tome, chapitres.
	 */
	function yume_tpub_tome_annonce( int $oeuvre, array $numeros ): array {
		$ancienne = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		add_filter( 'yume_core_notifier', '__return_false' );
		$tome = yume_factory_post(
			array(
				'post_type'     => 'yume_tome',
				'post_title'    => 'Tome annoncé — Tome 7',
				'post_name'     => 'tome-7',
				'post_status'   => 'publish',
				'post_date'     => get_date_from_gmt( $ancienne ),
				'post_date_gmt' => $ancienne,
				'meta_input'    => array(
					'yume_oeuvre_id' => $oeuvre,
					'yume_numero'    => 7,
					'yume_nature'    => 'tome',
				),
			)
		);
		remove_filter( 'yume_core_notifier', '__return_false' );
		update_post_meta( $tome, '_yume_publie_notifie', $ancienne );
		$chapitres = array();
		foreach ( array_values( $numeros ) as $i => $numero ) {
			$chapitres[] = yume_factory_post(
				array(
					'post_type'    => 'yume_chapitre',
					'post_title'   => 'Chapitre ' . $numero,
					'post_name'    => 'chapitre-' . $numero,
					'post_status'  => 'draft',
					'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
					'menu_order'   => $i + 1,
					'meta_input'   => array(
						'yume_tome_id' => $tome,
						'yume_numero'  => $numero,
						'yume_nature'  => 'chapitre',
					),
				)
			);
		}
		// Le tome n'a pas été publié « dans cette requête » (comme en production, où le cron
		// publie les chapitres programmés bien après la sortie du tome).
		if ( function_exists( 'Yume\Core\Core\etat_set' ) ) {
			\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
		}
		unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		return array( $tome, $chapitres );
	}
}

if ( ! function_exists( 'yume_tpub_tome_migre' ) ) {
	/**
	 * Tome migré : publié il y a un mois avec ses seuls liens PDF/EPUB, jamais annoncé.
	 *
	 * @param int $oeuvre Œuvre.
	 * @param int $numero Numéro.
	 * @return array{0:int,1:string} Tome, date GMT de sortie.
	 */
	function yume_tpub_tome_migre( int $oeuvre, int $numero = 10 ): array {
		$ancienne = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		add_filter( 'yume_core_notifier', '__return_false' );
		try {
			$tome = yume_factory_post(
				array(
					'post_type'     => 'yume_tome',
					'post_title'    => 'Tome migré — Tome ' . $numero,
					'post_name'     => 'tome-' . $numero,
					'post_status'   => 'publish',
					'post_date'     => get_date_from_gmt( $ancienne ),
					'post_date_gmt' => $ancienne,
					'meta_input'    => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => $numero,
						'yume_nature'    => 'tome',
						'yume_lien_pdf'  => 'https://www.clictune.com/pdf' . $numero,
					),
				)
			);
		} finally {
			remove_filter( 'yume_core_notifier', '__return_false' );
		}
		if ( function_exists( 'Yume\Core\Core\etat_set' ) ) {
			\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
		}
		return array( $tome, $ancienne );
	}

	/**
	 * Compte les événements de sortie et les appels Discord pendant $corps.
	 *
	 * @param callable $corps Corps (reçoit le compteur).
	 * @return stdClass tome, chapitre, alertes (IDs reçus), discord (URL appelées).
	 */
	function yume_tpub_compter( callable $corps ): stdClass {
		$n          = new stdClass();
		$n->tome    = array();
		$n->chap    = array();
		$n->alertes = array();
		$n->discord = array();
		$tome       = static function ( $id ) use ( $n ) {
			$n->tome[] = (int) $id;
		};
		$chap       = static function ( $id ) use ( $n ) {
			$n->chap[] = (int) $id;
		};
		$alertes    = static function ( $id ) use ( $n ) {
			$n->alertes[] = (int) $id;
		};
		$http       = static function ( $pre, $args, $url ) use ( $n ) {
			if ( str_contains( (string) $url, 'discord.com' ) ) {
				$n->discord[] = (string) $url;
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 204,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $pre;
		};
		$reglages   = get_option( 'yume_reglages', array() );
		update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'discord_webhook_sorties' => 'https://discord.com/api/webhooks/1/sorties' ) ) );
		add_action( 'yume_tome_publie', $tome, 1 );
		add_action( 'yume_chapitre_publie', $chap, 1 );
		add_action( 'yume_alertes_envoyees', $alertes, 1 );
		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			$corps( $n );
		} finally {
			remove_action( 'yume_tome_publie', $tome, 1 );
			remove_action( 'yume_chapitre_publie', $chap, 1 );
			remove_action( 'yume_alertes_envoyees', $alertes, 1 );
			remove_filter( 'pre_http_request', $http, 10 );
			update_option( 'yume_reglages', $reglages );
		}
		return $n;
	}

	/**
	 * Articles de la catégorie « Sorties » (tous statuts vivants).
	 *
	 * @return int[]
	 */
	function yume_tpub_sorties(): array {
		return get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'category_name'    => 'sorties',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
	}
}

// Inclusion par test-publication-chapitres.php pour ses seules fonctions d'aide.
if ( ! empty( $GLOBALS['yume_tests_publication_aides_seules'] ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Service
 * -----------------------------------------------------------------------------
 */

yume_test(
	'service : création du tome et des chapitres en brouillon avec les métadonnées du contrat',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre   = yume_tpub_oeuvre();
			$preparee = 0;
			$ecouteur = static function ( $tome_id, $rapport ) use ( &$preparee ) {
				$preparee = is_array( $rapport ) ? (int) $tome_id : 0;
			};
			add_action( 'yume_publication_preparee', $ecouteur, 10, 2 );
			$r = yume_tpub_preparer( $ctx, $oeuvre );
			remove_action( 'yume_publication_preparee', $ecouteur, 10 );

			$tome = get_post( $r['tome']['id'] );
			yume_assert_same( $tome->ID, $preparee );
			yume_assert_same( array( 'yume_tome', 'draft', 'Grimgar de test — Tome 10', 'tome-10', 100 ), array( $tome->post_type, $tome->post_status, $tome->post_title, $tome->post_name, (int) $tome->menu_order ) );
			yume_assert_same( $oeuvre, (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true ) );
			yume_assert_same( 10.0, (float) get_post_meta( $tome->ID, 'yume_numero', true ) );
			yume_assert_same( 'tome', get_post_meta( $tome->ID, 'yume_nature', true ) );
			yume_assert_same( 'https://www.clictune.com/pdf10', get_post_meta( $tome->ID, 'yume_lien_pdf', true ) );
			yume_assert_same( 'Calumi', get_post_meta( $tome->ID, 'yume_credits', true )['traduction'] );
			yume_assert_false( $r['tome']['reutilise'] );

			$chapitres = yume_get_chapitres( $tome->ID, array( 'status' => 'any' ) );
			yume_assert_same( 13, count( $chapitres ) );
			yume_assert_same( 13, count( $r['chapitres'] ) );
			$c1 = $chapitres[1];
			yume_assert_same( array( 'Chapitre 1 — La Crête Brumeuse', 'draft', 'chapitre-1', 2 ), array( $c1->post_title, $c1->post_status, $c1->post_name, (int) $c1->menu_order ) );
			yume_assert_same( $tome->ID, (int) get_post_meta( $c1->ID, 'yume_tome_id', true ) );
			yume_assert_same( $oeuvre, (int) get_post_meta( $c1->ID, 'yume_oeuvre_id', true ) );
			yume_assert_same( 1.0, (float) get_post_meta( $c1->ID, 'yume_numero', true ) );
			yume_assert_same( 'La Crête Brumeuse', get_post_meta( $c1->ID, 'yume_sous_titre', true ) );
			yume_assert_same( 'Angeloids', get_post_meta( $c1->ID, 'yume_credits', true )['relecture'] );
			$source = get_post_meta( $c1->ID, 'yume_source', true );
			yume_assert_same( 'docx', $source['format'] );
			yume_assert_same( sha1_file( yume_timp_fixture( 'regles.docx' ) ), $source['hash'] );
			yume_assert_true( '' !== $source['importe_le'] );
			$mots = (int) get_post_meta( $c1->ID, 'yume_nb_mots', true );
			yume_assert_true( $mots > 50 );
			yume_assert_same( (int) ceil( $mots / 230 ), (int) get_post_meta( $c1->ID, 'yume_temps_lecture', true ) );
			$postface = end( $chapitres );
			yume_assert_same( array( 'postface', 'postface', '' ), array( get_post_meta( $postface->ID, 'yume_nature', true ), $postface->post_name, get_post_meta( $postface->ID, 'yume_numero', true ) ) );
			yume_assert_same( 0.0, (float) get_post_meta( $chapitres[0]->ID, 'yume_numero', true ), 'Prologue numéroté 0' );
			yume_assert_contains( 'preview=true', $r['chapitres'][0]['apercu'] );
			yume_assert_contains( 'post.php?post=' . $chapitres[0]->ID, $r['chapitres'][0]['edition'] );
			yume_assert_same( 'cree', $r['chapitres'][0]['action'] );
			foreach ( $chapitres as $chapitre ) {
				yume_timp_blocs_valides( $chapitre->post_content, $chapitre->post_title );
			}
		}
	)
);

yume_test(
	'service : images versées (≤ 1600 px, WebP), rattachées au chapitre, jetons remplacés, galerie du tome',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$r    = yume_tpub_preparer( $ctx, yume_tpub_oeuvre() );
			$tome = (int) $r['tome']['id'];
			$c1   = (int) $r['chapitres'][1]['id'];
			$html = get_post( $c1 )->post_content;
			yume_assert_not_contains( '{{yume-image', $html );
			preg_match_all( '/<!-- wp:image \{"id":(\d+),"sizeSlug":"large","linkDestination":"none","className":"yn-illustration"\} -->/', $html, $m );
			yume_assert_same( 3, count( $m[1] ) );
			$webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
			foreach ( array_map( 'intval', $m[1] ) as $i => $id ) {
				yume_assert_same( $c1, (int) wp_get_post_parent_id( $id ), 'Image rattachée au chapitre' );
				yume_assert_contains( 'class="wp-image-' . $id . '"', $html );
				$meta = wp_get_attachment_metadata( $id );
				yume_assert_true( max( (int) $meta['width'], (int) $meta['height'] ) <= Medias::COTE_MAX, 'Redimensionnée : ' . $meta['width'] );
				// Titre du média : toujours le libellé généré, jamais le texte du document.
				yume_assert_same( 'Grimgar de test, Tome 10, Chapitre 1 — illustration', get_post( $id )->post_title );
				if ( 0 === $i ) {
					yume_assert_same( 1600, (int) $meta['width'] );
					yume_assert_same( $webp ? 'image/webp' : 'image/png', get_post_mime_type( $id ) );
					yume_assert_same( 'Illustration de la brume', get_post_meta( $id, '_wp_attachment_image_alt', true ), 'texte alternatif du document' );
				}
				if ( 1 === $i ) {
					yume_assert_same( 'image/gif', get_post_mime_type( $id ), 'Le GIF reste un GIF' );
					yume_assert_same( 'Grimgar de test, Tome 10, Chapitre 1 — illustration', get_post_meta( $id, '_wp_attachment_image_alt', true ), 'sans texte alternatif dans le document : libellé généré' );
				}
			}
			$galerie = get_post_meta( $tome, 'yume_illustrations', true );
			yume_assert_same( 2, count( $galerie ) );
			foreach ( $galerie as $id ) {
				yume_assert_same( $tome, (int) wp_get_post_parent_id( $id ) );
				yume_assert_same( 'Illustration — Grimgar de test — Tome 10', get_post( $id )->post_title );
			}
			yume_assert_same( 5, count( array_unique( $ctx->medias ) ) );
		}
	)
);

yume_test(
	'service : un tome planifié (brouillon du planning) est réutilisé, son adresse conservée',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre();
			$tome   = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'post_title'  => 'Grimgar de test — Tome 10',
					'post_name'   => 'tome-10',
					'meta_input'  => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 10,
						'yume_nature'    => 'tome',
						'yume_etape'     => 'edition',
					),
				)
			);
			$r      = yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '10,0' ) );
			yume_assert_same( $tome, (int) $r['tome']['id'] );
			yume_assert_true( $r['tome']['reutilise'] );
			yume_assert_same( 'tome-10', get_post( $tome )->post_name );
			yume_assert_same( 'edition', get_post_meta( $tome, 'yume_etape', true ), 'Planning intact' );
			yume_assert_same( 1, count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) ) );
			// Autre nature ou autre numéro : nouveau tome.
			$arc = yume_tpub_preparer( $ctx, $oeuvre, array( 'nature' => 'arc' ) );
			yume_assert_true( $tome !== (int) $arc['tome']['id'] );
			yume_assert_same( 'arc-10', get_post( $arc['tome']['id'] )->post_name );
		}
	)
);

yume_test(
	'service : nouvel import = mise à jour en place (mêmes chapitres, mêmes images, aucun doublon)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre  = yume_tpub_oeuvre();
			$premier = yume_tpub_preparer( $ctx, $oeuvre );
			$medias  = count( $ctx->medias );
			$ids     = array_column( $premier['chapitres'], 'id' );
			$second  = yume_tpub_preparer( $ctx, $oeuvre, array( 'titre' => 'Un bon jour' ) );
			yume_assert_same( $premier['tome']['id'], $second['tome']['id'] );
			yume_assert_same( $ids, array_column( $second['chapitres'], 'id' ), 'Chapitres mis à jour en place (doublons de numéro compris)' );
			yume_assert_same( array_fill( 0, 13, 'inchange' ), array_column( $second['chapitres'], 'action' ) );
			yume_assert_same( 13, count( yume_get_chapitres( (int) $premier['tome']['id'], array( 'status' => 'any' ) ) ) );
			yume_assert_same( $medias, count( $ctx->medias ), 'Images réutilisées' );
			yume_assert_same( 'Grimgar de test — Tome 10 : Un bon jour', get_the_title( $premier['tome']['id'] ) );
			yume_assert_same( array(), $second['disparus'] );
		}
	)
);

yume_test(
	'service : chapitres disparus signalés, mis en brouillon seulement sur option',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre();
			$r      = yume_tpub_preparer( $ctx, $oeuvre );
			$tome   = (int) $r['tome']['id'];
			$extra  = yume_factory_post(
				array(
					'post_type'   => 'yume_chapitre',
					'post_status' => 'publish',
					'post_title'  => 'Chapitre 99',
					'post_date'   => '2020-01-01 10:00:00',
					'meta_input'  => array(
						'yume_tome_id' => $tome,
						'yume_numero'  => 99,
						'yume_nature'  => 'chapitre',
					),
				)
			);
			$sans   = yume_tpub_preparer( $ctx, $oeuvre );
			yume_assert_same( array( $extra ), array_column( $sans['disparus'], 'id' ) );
			yume_assert_false( $sans['disparus'][0]['retire'] );
			yume_assert_same( 'publish', get_post_status( $extra ) );
			yume_assert_contains( 'absent du nouveau fichier', implode( ' ', $sans['avertissements'] ) );
			$avec = yume_tpub_preparer( $ctx, $oeuvre, array( 'retirer_absents' => '1' ) );
			yume_assert_true( $avec['disparus'][0]['retire'] );
			yume_assert_same( 'draft', get_post_status( $extra ) );
			$sortie = Service::publier( $tome, 'maintenant' );
			yume_assert_same( 13, $sortie['chapitres'] );
			yume_assert_same( 'draft', get_post_status( $extra ), 'Un chapitre retiré n’est pas republié' );
		}
	)
);

yume_test(
	'service : article d’annonce en brouillon (Sorties, œuvre liée, modèle, boutons)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre  = yume_tpub_oeuvre();
			$r       = yume_tpub_preparer( $ctx, $oeuvre );
			$article = get_post( $r['article']['id'] );
			yume_assert_same( array( 'post', 'draft' ), array( $article->post_type, $article->post_status ) );
			yume_assert_same( 'Le tome 10 de Grimgar de test est disponible !', $article->post_title );
			$categories = wp_get_post_categories( $article->ID, array( 'fields' => 'slugs' ) );
			yume_assert_same( array( 'sorties' ), $categories );
			if ( taxonomy_exists( 'yume_oeuvre_liee' ) ) {
				$termes = wp_get_object_terms( $article->ID, 'yume_oeuvre_liee', array( 'fields' => 'slugs' ) );
				yume_assert_same( array( get_post_field( 'post_name', $oeuvre ) ), $termes );
				yume_assert_same( $oeuvre, yume_get_oeuvre_id( $article->ID ) );
			}
			yume_assert_contains( 'href="https://www.clictune.com/pdf10">Télécharger le PDF</a>', $article->post_content );
			yume_assert_contains( 'href="https://www.clictune.com/epub10">Télécharger l’EPUB</a>', $article->post_content );
			yume_assert_contains( 'Lire en ligne</a>', $article->post_content );
			yume_assert_contains( 'Traduction : Calumi · Relecture : Angeloids · Édition : JojoGg', $article->post_content );
			yume_assert_same( $article->post_content, serialize_blocks( parse_blocks( $article->post_content ) ) );
			// Une seule annonce par tome, régénérée tant qu'elle n'est pas modifiée.
			$r2 = yume_tpub_preparer( $ctx, $oeuvre );
			yume_assert_same( $article->ID, (int) $r2['article']['id'] );
			// Modèle personnalisé et élision.
			update_option( 'yume_reglages', array( 'modele_annonce' => 'Le {nature} {numero} de {oeuvre} ({titre}) est là' ) );
			$arc = yume_tpub_preparer(
				$ctx,
				$oeuvre,
				array(
					'nature' => 'arc',
					'numero' => '7',
					'titre'  => 'Tournoi',
				)
			);
			yume_assert_same( 'L’arc 7 de Grimgar de test (Tournoi) est là', get_the_title( $arc['article']['id'] ) );
			yume_assert_same( 'L’arc 7 de Grimgar de test (Tournoi) est là', Annonce::titre( (int) $arc['tome']['id'] ) );
		}
	)
);

yume_test(
	'service : publication immédiate, yume_tome_publie émis une seule fois',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$r     = yume_tpub_preparer( $ctx, yume_tpub_oeuvre() );
			$tome  = (int) $r['tome']['id'];
			$avant = did_action( 'yume_publication_en_cours' );
			$dans  = null;
			$voir  = static function () use ( &$dans ) {
				$dans = did_action( 'yume_publication_en_cours' ) > 0;
			};
			add_action( 'yume_tome_publie', $voir, 2 );
			$sortie = Service::publier( $tome, 'maintenant' );
			remove_action( 'yume_tome_publie', $voir, 2 );
			yume_assert_same( $avant + 1, did_action( 'yume_publication_en_cours' ) );
			yume_assert_true( $dans, 'Émis pendant la publication orchestrée' );
			yume_assert_same( array( $tome ), $ctx->emis );
			yume_assert_same( 'publish', $sortie['statut'] );
			yume_assert_same( 13, $sortie['chapitres'] );
			yume_assert_same( 'publish', get_post_status( $tome ) );
			foreach ( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) as $chapitre ) {
				yume_assert_same( 'publish', $chapitre->post_status );
			}
			yume_assert_same( 'publish', get_post_status( $r['article']['id'] ) );
			yume_assert_true( metadata_exists( 'post', $tome, '_yume_publie_notifie' ) );
			yume_assert_contains( '/oeuvres/', get_permalink( $tome ) );
			yume_assert_contains( 'href="' . esc_url( get_permalink( $tome ) ) . '">Lire en ligne', get_post( $r['article']['id'] )->post_content, 'Lien de l’annonce mis à jour' );
			// Nouvelle publication du même tome : aucune nouvelle émission.
			Service::publier( $tome, 'maintenant' );
			yume_assert_same( array( $tome ), $ctx->emis );
			if ( function_exists( 'Yume\Core\Core\publication_en_cours' ) ) {
				yume_assert_true( \Yume\Core\Core\publication_en_cours() );
			}
		}
	)
);

yume_test(
	'service : tome déjà en ligne (migré, PDF/EPUB seuls) : ses chapitres sortent en une seule sortie',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre   = yume_tpub_oeuvre( 'Tome migré' );
			$ancienne = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
			// Tome migré : publié il y a un mois, sans chapitre, jamais annoncé (notifications coupées).
			add_filter( 'yume_core_notifier', '__return_false' );
			$tome = yume_factory_post(
				array(
					'post_type'     => 'yume_tome',
					'post_title'    => 'Tome migré — Tome 10',
					'post_name'     => 'tome-10',
					'post_status'   => 'publish',
					'post_date'     => get_date_from_gmt( $ancienne ),
					'post_date_gmt' => $ancienne,
					'meta_input'    => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 10,
						'yume_nature'    => 'tome',
					),
				)
			);
			remove_filter( 'yume_core_notifier', '__return_false' );
			yume_assert_same( 'ignore', get_post_meta( $tome, '_yume_publie_notifie', true ) );

			$chapitres = array();
			$suivre    = static function ( $id ) use ( &$chapitres ) {
				$chapitres[] = (int) $id;
			};
			add_action( 'yume_chapitre_publie', $suivre, 1 );
			try {
				$r = yume_tpub_preparer( $ctx, $oeuvre );
				yume_assert_same( $tome, (int) $r['tome']['id'], 'tome migré réutilisé' );
				yume_assert_same( 'publish', get_post_status( $tome ), 'le tome reste en ligne pendant la préparation' );
				$sortie = Service::publier( $tome, 'maintenant' );
				yume_assert_same( 13, $sortie['chapitres'] );
				yume_assert_same( array( $tome ), $ctx->emis, 'une seule sortie : yume_tome_publie' );
				yume_assert_same( array(), $chapitres, 'aucun événement par chapitre' );
				yume_assert_same( $ancienne, get_post_field( 'post_date_gmt', $tome ), 'date du tome conservée' );
				yume_assert_true( 'ignore' !== get_post_meta( $tome, '_yume_publie_notifie', true ) );

				// Tome déjà annoncé qui reçoit d'autres chapitres en bloc : un seul yume_chapitre_publie.
				foreach ( yume_get_chapitres( $tome ) as $i => $chapitre ) {
					if ( $i >= 11 ) {
						wp_update_post(
							array(
								'ID'          => $chapitre->ID,
								'post_status' => 'draft',
							)
						);
					}
				}
				unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
				$ctx->emis = array();
				$chapitres = array();
				$sortie    = Service::publier( $tome, 'maintenant' );
				yume_assert_same( 2, $sortie['chapitres'] );
				yume_assert_same( array(), $ctx->emis );
				yume_assert_same( 1, count( $chapitres ), 'un seul événement pour les chapitres ajoutés' );
			} finally {
				remove_action( 'yume_chapitre_publie', $suivre, 1 );
			}
		}
	)
);

yume_test(
	'service : titres de chapitre importés enregistrés comme du texte (aucune balise, même avec unfiltered_html)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'administrator' ) );
			yume_timp_outils_fixtures();
			$corps           = yume_fx_t( 'Chapitre 1 : <img src=x onerror=alert(document.domain)>', array( 'style' => 'Titre1' ) );
			$corps          .= yume_fx_t( 'Premier paragraphe du chapitre un.' );
			$corps          .= yume_fx_t( 'Chapitre 2 : <svg onload=alert(2)>Le <b>vrai</b> titre', array( 'style' => 'Titre1' ) );
			$corps          .= yume_fx_t( 'Texte du chapitre deux, avec <script>alert(3)</script> en toutes lettres.' );
			$corps          .= yume_fx_t( 'Chapitre 3 : Quand a < b', array( 'style' => 'Titre1' ) );
			$corps          .= yume_fx_t( 'Texte du chapitre trois.' );
			$docx            = yume_timp_docx( $corps );
			$ctx->fichiers[] = $docx;
			$r               = yume_tpub_preparer( $ctx, yume_tpub_oeuvre(), array(), $docx );
			$titres          = array();
			foreach ( yume_get_chapitres( (int) $r['tome']['id'], array( 'status' => 'any' ) ) as $chapitre ) {
				$titres[] = array( $chapitre->post_title, get_post_meta( $chapitre->ID, 'yume_sous_titre', true ) );
				yume_assert_not_contains( '<', str_replace( 'a < b', '', $chapitre->post_title ), 'aucune balise dans le titre' );
			}
			yume_assert_same(
				array(
					array( 'Chapitre 1', '' ),
					array( 'Chapitre 2 — Le vrai titre', 'Le vrai titre' ),
					array( 'Chapitre 3 — Quand a < b', sanitize_text_field( 'Quand a < b' ) ),
				),
				$titres
			);
			yume_assert_same( 'Le vrai titre', $r['chapitres'][1]['sous_titre'] );
			// Le texte des paragraphes reste échappé.
			yume_assert_contains( '&lt;script&gt;', get_post( $r['chapitres'][1]['id'] )->post_content );
			Service::publier( (int) $r['tome']['id'], 'maintenant' );
			yume_assert_not_contains( 'onerror', get_the_title( $r['chapitres'][0]['id'] ) );
			yume_assert_not_contains( '<svg', get_the_title( $r['chapitres'][1]['id'] ) );
		}
	)
);

yume_test(
	'service : un tome créé sans adresse (planning, administration) reçoit « tome-10 » à la préparation et à la publication',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre   = yume_tpub_oeuvre( 'Grimgar of Fantasy and Ash' );
			$planifie = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'post_title'  => 'Grimgar of Fantasy and Ash — Tome 10',
					'meta_input'  => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 10,
						'yume_nature'    => 'tome',
					),
				)
			);
			yume_assert_same( '', get_post( $planifie )->post_name, 'brouillon du planning sans adresse' );
			$r = yume_tpub_preparer( $ctx, $oeuvre );
			yume_assert_same( $planifie, (int) $r['tome']['id'] );
			yume_assert_same( 'tome-10', get_post( $planifie )->post_name );
			Service::publier( $planifie, 'maintenant' );
			yume_assert_same( 'tome-10', get_post( $planifie )->post_name );
			yume_assert_contains( '/tome-10/', (string) get_permalink( $planifie ) );

			// Tome programmé depuis l'administration (slug tiré du titre par WordPress), puis
			// publié par le module sans nouveau fichier.
			$admin = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'future',
					'post_title'  => 'Grimgar of Fantasy and Ash — Arc 11',
					'post_date'   => wp_date( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
					'meta_input'  => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 11,
						'yume_nature'    => 'arc',
					),
				)
			);
			yume_assert_same( 'grimgar-of-fantasy-and-ash-arc-11', get_post( $admin )->post_name );
			Service::publier( $admin, 'maintenant', array( 'confirmer_vide' => true ) );
			yume_assert_same( 'arc-11', get_post( $admin )->post_name );

			// Un tome déjà en ligne garde toujours son adresse.
			$en_ligne = yume_factory_post(
				array(
					'post_type'  => 'yume_tome',
					'post_title' => 'Grimgar of Fantasy and Ash — Tome 12',
					'post_name'  => 'grimgar-tome-12-ancien',
					'meta_input' => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 12,
						'yume_nature'    => 'tome',
					),
				)
			);
			yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '12' ) );
			Service::publier( $en_ligne, 'maintenant' );
			yume_assert_same( 'grimgar-tome-12-ancien', get_post( $en_ligne )->post_name );
		}
	)
);

yume_test(
	'service : titres sans entités HTML (annonce enregistrée, réponses REST)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( "Miss Medic's Diary at War" );
			$r      = yume_tpub_preparer( $ctx, $oeuvre, array( 'titre' => "Tournoi d'échec" ) );
			$titre  = Annonce::titre( (int) $r['tome']['id'] );
			yume_assert_not_contains( '&#', $titre );
			yume_assert_contains( 'Miss Medic', $titre );
			yume_assert_not_contains( '&#', get_post( $r['article']['id'] )->post_title, 'titre de l’article enregistré sans entité' );
			yume_assert_not_contains( '&#', $r['tome']['titre'] );
			yume_assert_not_contains( '&#', $r['oeuvre']['titre'] );
			yume_assert_contains( 'Tournoi d', $r['tome']['titre'] );
			$sortie = Service::publier( (int) $r['tome']['id'], 'maintenant' );
			yume_assert_not_contains( '&#', $sortie['tome']['titre'] );
			yume_assert_not_contains( '&#', $sortie['article']['titre'] );
		}
	)
);

yume_test(
	'service : plusieurs chapitres d’un tome déjà annoncé = une seule annonce qui les cite tous',
	yume_tpub(
		function () {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Grimgar annoncé' ), array( 21, 22, 23 ) );
			$vus                = array();
			$ecouteur           = static function ( $id, $groupe = array() ) use ( &$vus ) {
				// Verrou du module social (posé à la priorité 20) : simulé ici.
				add_post_meta( (int) $id, '_yume_alerte_envoyee', '2026-09-25 10:00:00', true );
				$vus[] = array( (int) $id, $groupe, yume_libelle_chapitre( (int) $id ), get_post_meta( (int) $id, 'yume_sous_titre', true ) );
			};
			update_post_meta( $ids[0], 'yume_sous_titre', 'Sous-titre du premier' );
			add_action( 'yume_chapitre_publie', $ecouteur, 1, 2 );
			try {
				$sortie = Service::publier( $tome, 'maintenant' );
			} finally {
				remove_action( 'yume_chapitre_publie', $ecouteur, 1 );
			}
			yume_assert_same( 3, $sortie['chapitres'] );
			yume_assert_same( array( array( $ids[0], $ids, 'Chapitres 21 à 23', '' ) ), $vus );
			yume_assert_same( 'Chapitre 21', yume_libelle_chapitre( $ids[0] ), 'libellé normal hors de l’annonce' );
			yume_assert_same( 'Sous-titre du premier', get_post_meta( $ids[0], 'yume_sous_titre', true ) );
			foreach ( $ids as $id ) {
				yume_assert_same( '2026-09-25 10:00:00', get_post_meta( $id, '_yume_alerte_envoyee', true ), 'chapitre noté pour le récapitulatif' );
			}
			yume_assert_same( 'Chapitres 4 et 5', Service::libelle_groupe( array( yume_tpub_tome_annonce( yume_tpub_oeuvre(), array( 4, 5 ) )[1] )[0] ) );
		}
	)
);

yume_test(
	'service : sortie programmée de plusieurs chapitres d’un tome en ligne = une seule annonce au passage de la date',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			list( $tome, $ids ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Grimgar programmé' ), array( 24, 25, 26 ) );
			$vus                = array();
			$ecouteur           = static function ( $id, $groupe = array() ) use ( &$vus ) {
				$vus[] = array( (int) $id, $groupe, yume_libelle_chapitre( (int) $id ) );
			};
			add_action( 'yume_chapitre_publie', $ecouteur, 1, 2 );
			try {
				// Programmée, puis reprogrammée : une seule tâche, la dernière.
				$q1 = wp_date( 'Y-m-d\TH:i', time() + 2 * DAY_IN_SECONDS );
				$t1 = Service::date_sortie( $q1 )->getTimestamp();
				Service::publier( $tome, $q1 );
				yume_assert_true( false !== wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, $t1 ) ) );
				$q2     = wp_date( 'Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS );
				$t2     = Service::date_sortie( $q2 )->getTimestamp();
				$sortie = Service::publier( $tome, $q2 );
				yume_assert_same( 3, $sortie['chapitres'] );
				yume_assert_false( wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, $t1 ) ), 'ancienne tâche supprimée' );
				yume_assert_true( false !== wp_next_scheduled( Service::HOOK_GROUPE, array( $tome, $t2 ) ) );
				foreach ( $ids as $id ) {
					yume_assert_same( 'future', get_post_status( $id ) );
					yume_assert_same( 'ignore', get_post_meta( $id, '_yume_publie_notifie', true ) );
				}
				yume_assert_same( array(), $ctx->emis );
				yume_assert_same( array(), $vus );

				// Le cron de WordPress publie chaque chapitre (publish_future_post) : rien n'est émis.
				foreach ( $ids as $id ) {
					wp_publish_post( $id );
				}
				yume_assert_same( array(), $vus, 'aucune annonce par chapitre' );
				// Puis la tâche de la sortie groupée : une seule annonce.
				do_action( Service::HOOK_GROUPE, $tome, $t2 );
				yume_assert_same( array( array( $ids[0], $ids, 'Chapitres 24 à 26' ) ), $vus );
				yume_assert_same( array(), $ctx->emis );
				yume_assert_false( metadata_exists( 'post', $tome, Service::META_GROUPE ) );
				do_action( Service::HOOK_GROUPE, $tome, $t2 );
				yume_assert_same( 1, count( $vus ), 'jamais deux fois' );

				// Chapitre retiré de la sortie avant la date : il sera annoncé normalement plus tard.
				list( $tome2, $ids2 ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Autre' ), array( 1, 2 ) );
				$vus                  = array();
				$q3                   = wp_date( 'Y-m-d\TH:i', time() + 2 * DAY_IN_SECONDS );
				$t3                   = Service::date_sortie( $q3 )->getTimestamp();
				Service::publier( $tome2, $q3 );
				wp_update_post(
					array(
						'ID'          => $ids2[1],
						'post_status' => 'draft',
					)
				);
				wp_publish_post( $ids2[0] );
				do_action( Service::HOOK_GROUPE, $tome2, $t3 );
				yume_assert_same( array( array( $ids2[0], array( $ids2[0] ), 'Chapitre 1' ) ), $vus );
				yume_assert_false( metadata_exists( 'post', $ids2[1], '_yume_publie_notifie' ), 'marque retirée du chapitre non publié' );

				// Sortie programmée remplacée par une sortie immédiate : une seule annonce, tâche supprimée.
				list( $tome3, $ids3 ) = yume_tpub_tome_annonce( yume_tpub_oeuvre( 'Troisième' ), array( 8, 9 ) );
				$vus                  = array();
				$q4                   = wp_date( 'Y-m-d\TH:i', time() + 2 * DAY_IN_SECONDS );
				$t4                   = Service::date_sortie( $q4 )->getTimestamp();
				Service::publier( $tome3, $q4 );
				Service::publier( $tome3, 'maintenant' );
				yume_assert_false( wp_next_scheduled( Service::HOOK_GROUPE, array( $tome3, $t4 ) ) );
				yume_assert_same( array( array( $ids3[0], $ids3, 'Chapitres 8 et 9' ) ), $vus );
			} finally {
				remove_action( 'yume_chapitre_publie', $ecouteur, 1 );
			}
		}
	)
);

yume_test(
	'bloc : navigation identique à celle du tableau de bord (ordre, entrées, cibles)',
	yume_tpub(
		function () {
			wp_set_current_user( yume_factory_user( 'administrator' ) );
			$html = yume_render_block( 'yume/publish-form' );
			// Navigation du tableau de bord (module planning) : mêmes entrées, même ordre, mêmes cibles.
			preg_match( '#<nav class="yn-team__nav".*?</nav>#s', $html, $m );
			yume_assert_true( ! empty( $m[0] ), 'navigation présente' );
			$entrees  = static function ( string $nav ): array {
				preg_match_all( '#<li><a href="([^"]*)"[^>]*>([^<]*)#', $nav, $liens, PREG_SET_ORDER );
				return array_map( static fn( $l ) => array( $l[1], trim( html_entity_decode( $l[2], ENT_QUOTES, 'UTF-8' ) ) ), $liens );
			};
			$publier  = $entrees( $m[0] );
			$tableau  = $entrees( \Yume\Core\Planning\navigation_equipe( 'membres' ) );
			$libelles = array_column( $publier, 1 );
			yume_assert_same( array_column( $tableau, 1 ), $libelles, 'mêmes entrées que le tableau de bord' );
			yume_assert_same( array_column( $tableau, 0 ), array_column( $publier, 0 ), 'mêmes cibles' );
			yume_assert_contains( 'Ajouter des chapitres', implode( '|', $libelles ) );
			yume_assert_not_contains( 'edit.php?post_type=yume_tome', $m[0] );
			yume_assert_same( 1, substr_count( $m[0], 'aria-current="page"' ) );
			yume_assert_true( (bool) preg_match( '#aria-current="page"[^>]*>Ajouter des chapitres#', $m[0] ), 'page courante : Ajouter des chapitres' );
		}
	)
);

yume_test(
	'service : publication programmée (future), aucune émission, date passée refusée',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$r     = yume_tpub_preparer( $ctx, yume_tpub_oeuvre() );
			$tome  = (int) $r['tome']['id'];
			$passe = Service::publier( $tome, '2020-01-01T10:00' );
			yume_assert_true( is_wp_error( $passe ) );
			yume_assert_same( 'yume_date_passee', $passe->get_error_code() );
			yume_assert_same( 'yume_date_invalide', Service::publier( $tome, 'demain' )->get_error_code() );
			$quand  = wp_date( 'Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS );
			$sortie = Service::publier( $tome, $quand );
			yume_assert_same( 'future', $sortie['statut'] );
			yume_assert_same( array(), $ctx->emis, 'Aucune émission pour une sortie programmée' );
			yume_assert_same( str_replace( 'T', ' ', $quand ) . ':00', get_post( $tome )->post_date );
			foreach ( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) as $chapitre ) {
				yume_assert_same( 'future', $chapitre->post_status );
				yume_assert_same( get_post( $tome )->post_date_gmt, $chapitre->post_date_gmt );
			}
			yume_assert_same( 'future', get_post_status( $r['article']['id'] ) );
			yume_assert_true( false !== wp_next_scheduled( 'publish_future_post', array( $tome ) ), 'Sortie planifiée par WordPress' );
			yume_assert_false( metadata_exists( 'post', $tome, '_yume_publie_notifie' ) );
		}
	)
);

yume_test(
	'service : le fichier source est supprimé après traitement, même en cas d’erreur',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$ok = yume_tpub_fichier( $ctx, 'regles.docx' );
			Service::preparer(
				array(
					'oeuvre_id' => yume_tpub_oeuvre(),
					'numero'    => '1',
				),
				array( 'source' => $ok )
			);
			yume_assert_false( file_exists( $ok['tmp_name'] ), 'Succès' );
			$erreur = yume_tpub_fichier( $ctx, 'regles.docx' );
			$res    = Service::preparer(
				array(
					'oeuvre_id' => 999999,
					'numero'    => '1',
				),
				array( 'source' => $erreur )
			);
			yume_assert_true( is_wp_error( $res ) );
			yume_assert_false( file_exists( $erreur['tmp_name'] ), 'Œuvre invalide' );
			$invalide = yume_tpub_fichier( $ctx, 'invalide.docx' );
			$res      = Service::preparer(
				array(
					'oeuvre_id' => yume_tpub_oeuvre(),
					'numero'    => '1',
				),
				array( 'source' => $invalide )
			);
			yume_assert_same( 'yume_import_docx_invalide', $res->get_error_code() );
			yume_assert_false( file_exists( $invalide['tmp_name'] ), 'DOCX invalide' );
			$analyse = yume_tpub_fichier( $ctx, 'regles.docx' );
			Service::analyser( $analyse );
			yume_assert_false( file_exists( $analyse['tmp_name'] ), 'Analyse' );
			// Un fichier qui n'a pas été téléversé n'est jamais supprimé.
			remove_filter( 'yume_publication_fichier_local', '__return_true' );
			$local = yume_tpub_fichier( $ctx, 'regles.docx' );
			yume_assert_true( is_wp_error( Service::analyser( $local ) ) );
			yume_assert_true( file_exists( $local['tmp_name'] ) );
			add_filter( 'yume_publication_fichier_local', '__return_true' );
		}
	)
);

yume_test(
	'service : validation des champs, des liens externes et des fichiers',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre();
			yume_assert_same( 'yume_numero_manquant', Service::champs( array( 'oeuvre_id' => $oeuvre ) )->get_error_code() );
			yume_assert_same( 'yume_numero_invalide', Service::champs( array( 'numero' => 'dix' ) )->get_error_code() );
			yume_assert_same(
				'yume_nature_invalide',
				Service::champs(
					array(
						'nature' => 'saga',
						'numero' => 1,
					)
				)->get_error_code()
			);
			yume_assert_same( 26.5, Service::champs( array( 'numero' => '26,5' ) )['numero'] );
			yume_assert_same( null, Service::champs( array( 'nature' => 'ex' ) )['numero'] );
			yume_assert_same(
				'yume_lien_invalide',
				Service::champs(
					array(
						'numero'   => 1,
						'lien_pdf' => 'javascript:alert(1)',
					)
				)->get_error_code()
			);
			yume_assert_same(
				'yume_lien_invalide',
				Service::champs(
					array(
						'numero'    => 1,
						'lien_epub' => 'pas une adresse',
					)
				)->get_error_code()
			);
			yume_assert_same(
				'yume_lien_heberge',
				Service::champs(
					array(
						'numero'   => 1,
						'lien_pdf' => home_url( '/wp-content/uploads/2026/09/tome.pdf' ),
					)
				)->get_error_code()
			);
			yume_assert_same(
				'https://mega.nz/file/abc#cle',
				Service::champs(
					array(
						'numero'   => 1,
						'lien_pdf' => ' https://mega.nz/file/abc#cle ',
					)
				)['lien_pdf']
			);
			yume_assert_same( 'yume_fichier_format', Fichiers::source( yume_tpub_fichier( $ctx, 'regles.docx', 'tome.pdf' ) )->get_error_code() );
			yume_assert_same( 'yume_fichier_type', Fichiers::source( yume_tpub_fichier( $ctx, 'faux.docx' ) )->get_error_code() );
			yume_assert_same( 'yume_fichier_chiffre', Fichiers::source( yume_tpub_fichier( $ctx, 'chiffre.docx' ) )->get_error_code() );
			yume_assert_same( 'yume_fichier_televersement', Fichiers::source( array( 'error' => UPLOAD_ERR_INI_SIZE ) )->get_error_code() );
			$taille = static fn() => 10;
			add_filter( 'yume_publication_taille_max', $taille );
			yume_assert_same( 'yume_fichier_trop_grand', Fichiers::source( yume_tpub_fichier( $ctx, 'regles.docx' ) )->get_error_code() );
			remove_filter( 'yume_publication_taille_max', $taille );
			$docx = Fichiers::source( yume_tpub_fichier( $ctx, 'regles.docx', '../Mon Tome (final).docx' ) );
			yume_assert_same( array( 'Mon-Tome-final.docx', 'docx' ), array( $docx['nom'], $docx['format'] ) );
			$epub = Fichiers::source( yume_tpub_fichier( $ctx, 'livre-epub3.epub' ) );
			yume_assert_same( 'epub', $epub['format'] );
			yume_assert_same( 'yume_couverture_format', Fichiers::couverture( yume_tpub_fichier( $ctx, 'regles.docx', 'couverture.jpg' ) )->get_error_code() );
		}
	)
);

yume_test(
	'service : couverture téléversée, couverture de l’EPUB par défaut',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre          = yume_tpub_oeuvre();
			$image           = wp_tempnam( 'yume-couv' );
			$ctx->fichiers[] = $image;
			$gd              = imagecreatetruecolor( 140, 200 );
			imagepng( $gd, $image );
			imagedestroy( $gd );
			$r = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'numero'    => '3',
				),
				array(
					'couverture' => array(
						'name'     => 'couverture.png',
						'tmp_name' => $image,
						'error'    => 0,
						'size'     => filesize( $image ),
					),
				)
			);
			yume_assert_false( is_wp_error( $r ) );
			$couv = (int) get_post_thumbnail_id( $r['tome']['id'] );
			yume_assert_true( $couv > 0 );
			yume_assert_same( (int) $r['tome']['id'], (int) wp_get_post_parent_id( $couv ) );
			yume_assert_same( $couv, (int) get_post_thumbnail_id( $r['article']['id'] ), 'Image de l’annonce = couverture' );
			yume_assert_contains( 'Aucun fichier DOCX ou EPUB', implode( ' ', $r['avertissements'] ) );
			$e = yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '4' ), 'livre-epub3.epub' );
			yume_assert_same( 5, count( $e['chapitres'] ) );
			yume_assert_true( (int) get_post_thumbnail_id( $e['tome']['id'] ) > 0, 'Couverture de l’EPUB' );
			yume_assert_same( 'epub', get_post_meta( $e['chapitres'][0]['id'], 'yume_source', true )['format'] );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'rest : permissions (anonyme, lecteur, traducteur, éditeur)',
	yume_tpub(
		function ( $ctx ) {
			$oeuvre   = yume_tpub_oeuvre();
			$fichiers = array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) );
			yume_assert_same( 401, yume_rest( 'POST', '/yume/v1/publications/analyse', array(), 0, $fichiers )->get_status() );
			yume_assert_same( 403, yume_rest( 'POST', '/yume/v1/publications/analyse', array(), yume_factory_user( 'subscriber' ), $fichiers )->get_status() );
			yume_assert_same( 403, yume_rest( 'POST', '/yume/v1/publications', array( 'oeuvre_id' => $oeuvre ), yume_factory_user( 'yume_traducteur' ), $fichiers )->get_status() );
			$tome = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'meta_input'  => array( 'yume_oeuvre_id' => $oeuvre ),
				)
			);
			yume_assert_same( 403, yume_rest( 'POST', '/yume/v1/publications/' . $tome . '/publier', array(), yume_factory_user( 'yume_traducteur' ) )->get_status() );
			$editeur = yume_factory_user( 'yume_editeur' );
			yume_assert_same( 404, yume_rest( 'POST', '/yume/v1/publications/' . $oeuvre . '/publier', array(), $editeur )->get_status() );
			yume_assert_same( 200, yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) ) )->get_status() );
		}
	)
);

yume_test(
	'rest : analyse multipart (rapport, rien n’est créé)',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			$oeuvre  = yume_tpub_oeuvre();
			$fichier = yume_tpub_fichier( $ctx, 'regles.docx', 'JG__Tome 10.docx' );
			$avant   = wp_count_posts( 'yume_chapitre' );
			$reponse = yume_rest(
				'POST',
				'/yume/v1/publications/analyse',
				array(
					'oeuvre_id' => $oeuvre,
					'numero'    => '10',
				),
				$editeur,
				array( 'source' => $fichier )
			);
			$data    = $reponse->get_data();
			yume_assert_same( 200, $reponse->get_status() );
			yume_assert_same( 13, count( $data['chapitres'] ) );
			yume_assert_same( 'Chapitre 1 — La Crête Brumeuse', $data['chapitres'][1]['libelle'] );
			yume_assert_same(
				array(
					'nom'    => 'JG__Tome-10.docx',
					'format' => 'docx',
				),
				array_intersect_key( $data['fichier'], array_flip( array( 'nom', 'format' ) ) )
			);
			yume_assert_same( '9 chapitres + prologue, interlude, épilogue, postface · 5 illustrations · 2 ornements EMF ignorés', $data['resume'] );
			yume_assert_true( count( $data['avertissements'] ) > 5 );
			yume_assert_same( array( 'couverture', 'couleur' ), $data['front_images'] );
			yume_assert_false( isset( $data['stats']['hash'] ) );
			yume_assert_same( null, $data['tome_existant'] );
			yume_assert_equals( $avant, wp_count_posts( 'yume_chapitre' ), 'Aucun chapitre créé' );
			yume_assert_false( file_exists( $fichier['tmp_name'] ) );
			$sans = yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur );
			yume_assert_same( array( 400, 'yume_source_manquante' ), array( $sans->get_status(), $sans->get_data()['code'] ) );
			$pdf = yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx', 'tome.pdf' ) ) );
			yume_assert_same( 415, $pdf->get_status() );
			yume_assert_contains( 'Format refusé', $pdf->get_data()['message'] );
			$casse = yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'invalide.docx' ) ) );
			yume_assert_same( 422, $casse->get_status() );
		}
	)
);

yume_test(
	'rest : création (201), réutilisation (200), publication programmée puis immédiate',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			$oeuvre  = yume_tpub_oeuvre();
			$champs  = array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '8',
				'lien_pdf'  => 'https://www.clictune.com/x',
				'credits'   => array( 'traduction' => 'Calumi' ),
			);
			$cree    = yume_rest( 'POST', '/yume/v1/publications', $champs, $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) ) );
			yume_assert_same( 201, $cree->get_status() );
			$data = $cree->get_data();
			yume_assert_same( 13, count( $data['chapitres'] ) );
			yume_assert_contains( 'preview=true', $data['tome']['apercu'] );
			yume_assert_true( is_array( $data['import'] ) && ! isset( $data['import']['chapitres'][0]['stats'] ) );
			$maj = yume_rest( 'POST', '/yume/v1/publications', $champs, $editeur );
			yume_assert_same( 200, $maj->get_status(), 'Sans fichier : métadonnées mises à jour' );
			yume_assert_same( $data['tome']['id'], $maj->get_data()['tome']['id'] );
			$lien = yume_rest( 'POST', '/yume/v1/publications', array_merge( $champs, array( 'lien_epub' => 'ftp://exemple.test/x' ) ), $editeur );
			yume_assert_same( 400, $lien->get_status() );
			$id        = (int) $data['tome']['id'];
			$futur     = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * DAY_IN_SECONDS );
			$programme = yume_rest( 'POST', '/yume/v1/publications/' . $id . '/publier', array( 'quand' => $futur ), $editeur );
			yume_assert_same( 200, $programme->get_status() );
			yume_assert_same( 'future', $programme->get_data()['statut'] );
			yume_assert_same( array(), $ctx->emis );
			$maintenant = yume_rest( 'POST', '/yume/v1/publications/' . $id . '/publier', array( 'quand' => 'maintenant' ), $editeur );
			yume_assert_same( 'publish', $maintenant->get_data()['statut'] );
			yume_assert_same( array( $id ), $ctx->emis );
			yume_assert_same( 400, yume_rest( 'POST', '/yume/v1/publications/' . $id . '/publier', array( 'quand' => '2001-01-01' ), $editeur )->get_status() );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Bloc et envoi sans JavaScript
 * -----------------------------------------------------------------------------
 */

yume_test(
	'bloc : rendu selon les droits (visiteur, lecteur, éditeur)',
	yume_tpub(
		function () {
			wp_set_current_user( 0 );
			$visiteur = yume_render_block( 'yume/publish-form' );
			yume_assert_true( (bool) preg_match( '/<div class="[^"]*\byn-publish\b[^"]*" id="yume-publication"/', $visiteur ), 'Classe racine .yn-publish' );
			yume_assert_contains( 'wp-block-yume-publish-form', $visiteur );
			yume_assert_contains( 'Se connecter', $visiteur );
			yume_assert_not_contains( '<form', $visiteur );
			wp_set_current_user( yume_factory_user( 'subscriber' ) );
			$lecteur = yume_render_block( 'yume/publish-form' );
			yume_assert_contains( 'Votre compte n’a pas le droit de publier', $lecteur );
			yume_assert_not_contains( '<form', $lecteur );
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'Silent Witch de test' );
			$html   = yume_render_block( 'yume/publish-form' );
			foreach ( array(
				'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"',
				'enctype="multipart/form-data"',
				'name="action" value="yume_publication"',
				'name="_yume_nonce"',
				'name="oeuvre_id"',
				'name="nature"',
				'name="numero"',
				'name="date_sortie"',
				'name="titre"',
				'name="lien_pdf"',
				'name="lien_epub"',
				'name="couverture"',
				'name="source"',
				'name="credits[traduction]"',
				'value="publier"',
				'value="brouillon"',
				'value="apercu"',
				'data-rest="' . esc_url( rest_url( 'yume/v1/' ) ) . '"',
				'data-nonce="',
				'Silent Witch de test',
				'Ajouter des chapitres à un tome',
				'<label for="yn-publish-source"',
				// Ajout de chapitres : mode, sortie, annonce, tome complet.
				'name="mode" value="chapitres"',
				'name="sortie" value="maintenant"',
				'name="sortie" value="rythme"',
				'name="sortie" value="date"',
				'name="intervalle"',
				'name="annoncer"',
				'name="complet"',
				'name="liens_dernier"',
				'Publier les liens avec le dernier chapitre',
				'Le tome est complet : tout publier maintenant',
				'name="chapitres_prevus"',
				'data-yn-comparaison',
				'data-yn-recap',
			) as $attendu ) {
				yume_assert_contains( $attendu, $html );
			}
		}
	)
);

yume_test(
	'bloc : préremplissage depuis un tome existant',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$r            = yume_tpub_preparer( $ctx, yume_tpub_oeuvre() );
			$_GET['tome'] = (string) $r['tome']['id'];
			try {
				$html = yume_render_block( 'yume/publish-form' );
			} finally {
				unset( $_GET['tome'] );
			}
			yume_assert_contains( 'name="tome_id" value="' . $r['tome']['id'] . '"', $html );
			yume_assert_contains( 'value="https://www.clictune.com/pdf10"', $html );
			yume_assert_contains( 'value="Calumi"', $html );
			yume_assert_contains( 'value="10"', $html );
			yume_assert_contains( '<strong>1</strong> · La Crête Brumeuse', $html );
			yume_assert_contains( '… 3 autres · Interlude, Épilogue, Postface', $html );
			yume_assert_contains( 'regles.docx', $html );
			yume_assert_contains( 'Brouillon enregistré le', $html );
		}
	)
);

yume_test(
	'envoi sans JavaScript (admin-post) : brouillon puis publication, source supprimée',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre   = yume_tpub_oeuvre();
			$fichier  = yume_tpub_fichier( $ctx, 'regles.docx' );
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = array(
				'action'      => 'yume_publication',
				'_yume_nonce' => wp_create_nonce( 'yume_publication' ),
				'etape'       => 'brouillon',
				'oeuvre_id'   => (string) $oeuvre,
				'nature'      => 'tome',
				'numero'      => '2',
				'lien_pdf'    => 'https://www.clictune.com/p2',
				'credits'     => array( 'traduction' => 'Calumi' ),
			);
			$_FILES = array( 'source' => $fichier );
			$url    = '';
			try {
				Formulaire::traiter();
			} catch ( RuntimeException $e ) {
				$url = $e->getMessage();
			} finally {
				remove_filter( 'wp_redirect', $redirige, 1 );
			}
			yume_assert_contains( 'redirection:', $url );
			yume_assert_contains( 'yume_retour=1', $url );
			yume_assert_false( file_exists( $fichier['tmp_name'] ), 'DOCX supprimé' );
			$retour = get_transient( Formulaire::RETOUR . $editeur );
			yume_assert_same( 'succes', $retour['type'] );
			yume_assert_contains( 'Brouillon enregistré', $retour['message'] );
			$tome = Service::trouver_tome( $oeuvre, 'tome', 2.0 );
			yume_assert_true( $tome instanceof WP_Post );
			yume_assert_same( 'draft', $tome->post_status );
			// Rendu suivant : message affiché une seule fois.
			$html = yume_render_block( 'yume/publish-form' );
			yume_assert_contains( 'Brouillon enregistré', $html );
			yume_assert_false( get_transient( Formulaire::RETOUR . $editeur ) );

			// Publication sans nouveau fichier.
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST['etape']   = 'publier';
			$_POST['tome_id'] = (string) $tome->ID;
			$_FILES           = array();
			try {
				Formulaire::traiter();
			} catch ( RuntimeException $e ) {
				$url = $e->getMessage();
			} finally {
				remove_filter( 'wp_redirect', $redirige, 1 );
				$_POST = array();
			}
			yume_assert_same( 'publish', get_post_status( $tome->ID ) );
			yume_assert_same( array( $tome->ID ), $ctx->emis );
			yume_assert_contains( 'est en ligne', get_transient( Formulaire::RETOUR . $editeur )['message'] );
		}
	)
);

yume_test(
	'envoi sans JavaScript : nonce invalide refusé, rien n’est créé',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre   = yume_tpub_oeuvre();
			$fichier  = yume_tpub_fichier( $ctx, 'regles.docx' );
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = array(
				'_yume_nonce' => 'mauvais',
				'etape'       => 'brouillon',
				'oeuvre_id'   => (string) $oeuvre,
				'numero'      => '5',
			);
			$_FILES = array( 'source' => $fichier );
			try {
				Formulaire::traiter();
			} catch ( RuntimeException $e ) {
				yume_assert_contains( 'redirection:', $e->getMessage() );
			} finally {
				remove_filter( 'wp_redirect', $redirige, 1 );
				$_POST  = array();
				$_FILES = array();
			}
			yume_assert_same( 'erreur', get_transient( Formulaire::RETOUR . $editeur )['type'] );
			yume_assert_same( null, Service::trouver_tome( $oeuvre, 'tome', 5.0 ) );
			yume_assert_false( file_exists( $fichier['tmp_name'] ) );
		}
	)
);

yume_test(
	'administration : sous-menu Yume → Publier un tome (capacité yume_publier)',
	function () {
		global $submenu, $_registered_pages;
		$sauve_menu  = $submenu;
		$sauve_pages = $_registered_pages;
		try {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			Formulaire::menu();
			$slugs = array_column( (array) ( $submenu['yume'] ?? array() ), 2 );
			yume_assert_true( in_array( 'yume-publier', $slugs, true ) );
			$entree = array_values( array_filter( (array) $submenu['yume'], static fn( $e ) => 'yume-publier' === $e[2] ) )[0];
			yume_assert_same( array( 'Publier un tome', 'yume_publier' ), array( $entree[0], $entree[1] ) );
		} finally {
			$submenu           = $sauve_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$_registered_pages = $sauve_pages; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}
);

yume_test(
	'docx de référence (privé) : préparation complète (20 chapitres, 10 images)',
	yume_tpub(
		function ( $ctx ) {
			$chemin = yume_timp_docx_prive();
			if ( '' === $chemin ) {
				echo "    (tools/fixtures/private/grimgar-t7.docx absent : test ignoré)\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				return;
			}
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			// Hors requête HTTP, la limite de téléversement de PHP (CLI) ne s'applique pas.
			$limite = static fn() => 256 * MB_IN_BYTES;
			add_filter( 'upload_size_limit', $limite );
			$r      = Service::preparer(
				array(
					'oeuvre_id' => yume_tpub_oeuvre( 'Grimgar of Fantasy and Ash' ),
					'numero'    => '7',
				),
				array( 'source' => yume_tpub_fichier( $ctx, $chemin, 'grimgar-t7.docx' ) )
			);
			remove_filter( 'upload_size_limit', $limite );
			yume_assert_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
			yume_assert_same( 20, count( $r['chapitres'] ) );
			yume_assert_same( 'Postface', $r['chapitres'][19]['titre'] );
			// 16 illustrations, dont 6 images EMF converties (2 dans la galerie, 4 dans les chapitres).
			yume_assert_same( 8, count( get_post_meta( $r['tome']['id'], 'yume_illustrations', true ) ) );
			yume_assert_same( 16, count( array_unique( $ctx->medias ) ) );
			$contenu = implode( '', array_map( static fn( $c ) => get_post( $c['id'] )->post_content, $r['chapitres'] ) );
			yume_assert_same( 8, substr_count( $contenu, '<!-- wp:image {"id":' ) );
			yume_assert_not_contains( '{{yume-image', $contenu );
			yume_assert_same( '19 chapitres + postface · 16 illustrations', $r['import']['resume'] );
		}
	)
);

yume_test(
	'MET-5 : un tome brouillon publié depuis l’administration reçoit « tome-12 » ; un tome déjà en ligne garde son adresse',
	function () {
		add_filter( 'yume_core_notifier', '__return_false', 99 );
		try {
			$oeuvre = yume_factory_post(
				array(
					'post_type'  => 'yume_oeuvre',
					'post_title' => 'Œuvre slug admin',
				)
			);
			$tome   = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'post_title'  => 'Œuvre slug admin — Tome 12',
					'meta_input'  => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_nature'    => 'tome',
						'yume_numero'    => 12,
					),
				)
			);
			wp_update_post(
				array(
					'ID'          => $tome,
					'post_status' => 'publish',
				)
			);
			yume_assert_same( 'tome-12', get_post( $tome )->post_name );

			$garde = yume_factory_post(
				array(
					'post_type'  => 'yume_tome',
					'post_title' => 'Œuvre slug admin — Tome 13',
					'post_name'  => 'oeuvre-slug-admin-tome-13',
					'meta_input' => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_nature'    => 'tome',
						'yume_numero'    => 13,
					),
				)
			);
			wp_update_post(
				array(
					'ID'           => $garde,
					'post_excerpt' => 'maj',
				)
			);
			yume_assert_same( 'oeuvre-slug-admin-tome-13', get_post( $garde )->post_name );
		} finally {
			remove_filter( 'yume_core_notifier', '__return_false', 99 );
		}
	}
);

yume_test(
	'SCAN-16 : tome sans chapitre ni lien PDF/EPUB refusé sans confirmation explicite (service, REST, formulaire)',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre = yume_tpub_oeuvre( 'Tome vide' );
			$r      = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '3',
				)
			);
			$tome   = (int) $r['tome']['id'];
			yume_assert_true( Service::tome_vide( $tome ) );
			$refus = Service::publier( $tome, 'maintenant' );
			yume_assert_true( is_wp_error( $refus ) );
			yume_assert_same( 'yume_tome_vide', $refus->get_error_code() );
			yume_assert_same( 409, $refus->get_error_data()['status'] );
			yume_assert_same( 'draft', get_post_status( $tome ), 'rien n’est publié' );
			yume_assert_same( array(), $ctx->emis, 'aucune annonce ni notification' );
			yume_assert_same( 'draft', get_post_status( Annonce::existant( $tome ) ), 'annonce restée en brouillon' );

			// REST : 409, puis confirmation explicite.
			$rest = yume_rest( 'POST', '/yume/v1/publications/' . $tome . '/publier', array(), $editeur );
			yume_assert_same( 409, $rest->get_status() );
			yume_assert_same( 'yume_tome_vide', $rest->get_data()['code'] );
			$quand = wp_date( 'Y-m-d\TH:i', time() + 2 * DAY_IN_SECONDS );
			$rest  = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome . '/publier',
				array(
					'quand'          => $quand,
					'confirmer_vide' => true,
				),
				$editeur
			);
			yume_assert_same( 200, $rest->get_status() );
			yume_assert_same( 'future', get_post_status( $tome ) );

			// Un lien PDF suffit.
			$lien = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '4',
					'lien_pdf'  => 'https://www.clictune.com/pdf4',
				)
			);
			wp_set_current_user( $editeur );
			yume_assert_false( Service::tome_vide( (int) $lien['tome']['id'] ) );
			yume_assert_same( 'publish', Service::publier( (int) $lien['tome']['id'], 'maintenant' )['statut'] );

			// Formulaire sans JavaScript : refus, case de confirmation proposée, puis publication.
			$vide     = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '5',
				)
			);
			$vide_id  = (int) $vide['tome']['id'];
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			$envoyer  = static function ( array $post ) use ( $redirige ) {
				add_filter( 'wp_redirect', $redirige, 1 );
				$_POST  = $post;
				$_FILES = array();
				try {
					Formulaire::traiter();
				} catch ( RuntimeException $e ) {
					unset( $e );
				} finally {
					remove_filter( 'wp_redirect', $redirige, 1 );
					$_POST = array();
				}
			};
			$post     = array(
				'action'      => 'yume_publication',
				'_yume_nonce' => wp_create_nonce( 'yume_publication' ),
				'etape'       => 'publier',
				'oeuvre_id'   => (string) $oeuvre,
				'tome_id'     => (string) $vide_id,
				'nature'      => 'tome',
				'numero'      => '5',
			);
			$envoyer( $post );
			$retour = get_transient( Formulaire::RETOUR . $editeur );
			yume_assert_same( 'erreur', $retour['type'] );
			yume_assert_true( $retour['confirmer'] );
			yume_assert_same( 'draft', get_post_status( $vide_id ) );
			$html = yume_render_block( 'yume/publish-form' );
			yume_assert_contains( 'name="confirmer_vide"', $html );
			yume_assert_contains( 'aucun chapitre ni lien', $html );
			$envoyer( array_merge( $post, array( 'confirmer_vide' => '1' ) ) );
			yume_assert_same( 'publish', get_post_status( $vide_id ) );
			yume_assert_not_contains( 'name="confirmer_vide"', yume_render_block( 'yume/publish-form' ) );
		}
	)
);

yume_test(
	'SCAN-04 : tome dépublié → annonce en brouillon ; republié → la même annonce revient (pas de doublon)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$r       = yume_tpub_preparer( $ctx, yume_tpub_oeuvre( 'Annonce qui suit' ) );
			$tome    = (int) $r['tome']['id'];
			$sortie  = Service::publier( $tome, 'maintenant' );
			$article = (int) $sortie['article']['id'];
			yume_assert_same( 'publish', get_post_status( $article ) );
			$date = get_post( $article )->post_date_gmt;

			// Dépublication depuis l'administration.
			wp_update_post(
				array(
					'ID'          => $tome,
					'post_status' => 'draft',
				)
			);
			yume_assert_same( 'draft', get_post_status( $article ), 'annonce retirée avec le tome' );
			yume_assert_true( is_array( get_post_meta( $article, Annonce::META_RETIREE, true ) ) );

			// Retour en ligne par le formulaire de publication : même article, date d'origine.
			Service::publier( $tome, 'maintenant' );
			yume_assert_same( 'publish', get_post_status( $article ) );
			yume_assert_same( $date, get_post( $article )->post_date_gmt, 'date d’origine conservée' );
			yume_assert_same( '', get_post_meta( $article, Annonce::META_RETIREE, true ) );
			yume_assert_same( $article, Annonce::existant( $tome ) );
			$annonces = get_posts(
				array(
					'post_type'   => 'post',
					'post_status' => 'any',
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_key'    => Annonce::META_TOME,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'  => (string) $tome,
				)
			);
			yume_assert_same( array( $article ), array_map( 'intval', $annonces ), 'une seule annonce' );

			// Corbeille puis republication depuis l'administration.
			wp_trash_post( $tome );
			yume_assert_same( 'draft', get_post_status( $article ) );
			wp_untrash_post( $tome );
			wp_update_post(
				array(
					'ID'          => $tome,
					'post_status' => 'publish',
				)
			);
			yume_assert_same( 'publish', get_post_status( $article ), 'annonce republiée depuis l’administration' );

			// Une annonce jamais parue (tome programmé puis déprogrammé) redevient brouillon et suit la nouvelle date.
			$r2      = yume_tpub_preparer( $ctx, yume_tpub_oeuvre( 'Annonce programmée' ) );
			$tome2   = (int) $r2['tome']['id'];
			$quand   = wp_date( 'Y-m-d\TH:i', time() + 3 * DAY_IN_SECONDS );
			$sortie2 = Service::publier( $tome2, $quand );
			$art2    = (int) $sortie2['article']['id'];
			yume_assert_same( 'future', get_post_status( $art2 ) );
			wp_update_post(
				array(
					'ID'          => $tome2,
					'post_status' => 'draft',
				)
			);
			yume_assert_same( 'draft', get_post_status( $art2 ), 'annonce programmée retirée' );
			Service::publier( $tome2, 'maintenant' );
			yume_assert_same( 'publish', get_post_status( $art2 ) );
			yume_assert_true( strtotime( get_post( $art2 )->post_date_gmt . ' UTC' ) <= time(), 'date de la sortie effective' );
		}
	)
);

yume_test(
	'SCAN-05 : programmer une sortie cale la date cible du planning sur la date programmée (journalisé)',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$r    = yume_tpub_preparer( $ctx, yume_tpub_oeuvre( 'Date cible' ) );
			$tome = (int) $r['tome']['id'];
			update_post_meta( $tome, 'yume_date_cible', '2026-01-15' );
			$quand = wp_date( 'Y-m-d\TH:i', time() + 10 * DAY_IN_SECONDS );
			Service::publier( $tome, $quand );
			yume_assert_same( 'future', get_post_status( $tome ) );
			if ( ! function_exists( '\Yume\Core\Planning\mettre_a_jour' ) ) {
				return;
			}
			yume_assert_same( substr( $quand, 0, 10 ), get_post_meta( $tome, 'yume_date_cible', true ) );
			if ( function_exists( '\Yume\Core\Planning\table_journal' ) ) {
				global $wpdb;
				$table = \Yume\Core\Planning\table_journal();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tome_id = %d AND champ = 'date_cible' ORDER BY id DESC LIMIT 1", $tome ), ARRAY_A );
				yume_assert_true( is_array( $ligne ), 'changement journalisé' );
				yume_assert_contains( substr( $quand, 0, 10 ), (string) $ligne['nouveau'] );
				yume_assert_same( $editeur, (int) $ligne['user_id'] );
			}
		}
	)
);

yume_test(
	'SCAN-07 : liste « Tome » (tous les tomes, avec leur parution), préremplissage et cible sans doublon',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre    = yume_tpub_oeuvre( 'Planning vers publication' );
			$autre     = yume_tpub_oeuvre( 'Autre œuvre' );
			$planifie  = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'draft',
					'post_title'  => 'Planning vers publication — Arc 26,5',
					'meta_input'  => array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => 26.5,
						'yume_nature'    => 'arc',
					),
				)
			);
			$programme = yume_factory_post(
				array(
					'post_type'   => 'yume_tome',
					'post_status' => 'future',
					'post_date'   => wp_date( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
					'post_title'  => 'Autre œuvre — Tome 2',
					'meta_input'  => array(
						'yume_oeuvre_id' => $autre,
						'yume_numero'    => 2,
						'yume_nature'    => 'tome',
					),
				)
			);
			$publie    = yume_factory_post(
				array(
					'post_type'  => 'yume_tome',
					'post_title' => 'Autre œuvre — Tome 1',
					'meta_input' => array(
						'yume_oeuvre_id' => $autre,
						'yume_numero'    => 1,
						'yume_nature'    => 'tome',
					),
				)
			);
			$html      = yume_render_block( 'yume/publish-form' );
			yume_assert_contains( 'name="tome_planning"', $html );
			yume_assert_contains( '<label for="yn-publish-planning" class="yn-label">Tome</label>', $html );
			yume_assert_true( (bool) preg_match( '#<option value="' . $planifie . '" data-oeuvre="' . $oeuvre . '" data-nature="arc" data-numero="26,5"#', $html ), 'brouillon proposé avec ses données' );
			yume_assert_true( (bool) preg_match( '#<option value="' . $programme . '"[^>]*>[^<]*programmé le#', $html ), 'programmé proposé' );
			yume_assert_true( (bool) preg_match( '#<option value="' . $publie . '"[^>]*>Tome 1 · publié</option>#', $html ), 'tome sorti proposé, avec sa parution' );
			yume_assert_contains( '<optgroup label="Planning vers publication', $html );

			// ?tome=ID : toujours prérempli, et sélectionné dans la liste.
			$_GET['tome'] = (string) $planifie;
			try {
				$html = yume_render_block( 'yume/publish-form' );
			} finally {
				unset( $_GET['tome'] );
			}
			yume_assert_true( (bool) preg_match( '#<option value="' . $planifie . '"[^>]*selected#', $html ), 'tome ouvert sélectionné' );
			yume_assert_contains( 'name="tome_id" value="' . $planifie . '"', $html );
			yume_assert_contains( 'value="26,5"', $html );

			// Envoi sans JavaScript : le tome choisi est la cible, nature et numéro repris.
			$avant    = count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) );
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = array(
				'action'        => 'yume_publication',
				'_yume_nonce'   => wp_create_nonce( 'yume_publication' ),
				'etape'         => 'brouillon',
				'oeuvre_id'     => (string) $oeuvre,
				'tome_planning' => (string) $planifie,
				'numero'        => '',
				'lien_pdf'      => 'https://www.clictune.com/arc26',
			);
			$_FILES = array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) );
			try {
				Formulaire::traiter();
			} catch ( RuntimeException $e ) {
				yume_assert_contains( 'tome=' . $planifie, $e->getMessage() );
			} finally {
				remove_filter( 'wp_redirect', $redirige, 1 );
				$_POST  = array();
				$_FILES = array();
			}
			yume_assert_same( 'succes', get_transient( Formulaire::RETOUR . $editeur )['type'] );
			yume_assert_same( $avant, count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) ), 'aucun doublon' );
			yume_assert_same( 'https://www.clictune.com/arc26', get_post_meta( $planifie, 'yume_lien_pdf', true ) );
			yume_assert_true( count( yume_get_chapitres( $planifie, array( 'status' => 'any' ) ) ) > 0, 'chapitres rattachés au tome du planning' );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Ajout au catalogue (sans annonce)
 * -----------------------------------------------------------------------------
 */

yume_test(
	'catalogue : tome migré (PDF seul) mis en lecture en ligne sans annonce — aucun événement, article, Discord ni alerte',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre                  = yume_tpub_oeuvre( 'Catalogue muet' );
			list( $tome, $ancienne ) = yume_tpub_tome_migre( $oeuvre );
			yume_assert_true( Service::sans_annonce_par_defaut( $tome ), 'tome publié : sans annonce par défaut' );
			$sorties_avant = yume_tpub_sorties();
			$n             = yume_tpub_compter(
				function () use ( $ctx, $oeuvre, $tome ) {
					$r = yume_tpub_preparer( $ctx, $oeuvre, array( 'lien_epub' => '' ) );
					yume_assert_same( $tome, (int) $r['tome']['id'], 'tome migré réutilisé' );
					yume_assert_true( $r['sans_annonce'], 'préparation : défaut sans annonce pour un tome publié' );
					yume_assert_same( null, $r['article'], 'aucun article préparé' );
					$sortie = Service::publier( $tome, 'maintenant', array( 'sans_annonce' => true ) );
					yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
					yume_assert_same( 13, $sortie['chapitres'] );
					yume_assert_true( $sortie['sans_annonce'] );
					yume_assert_same( null, $sortie['article'] );
				}
			);
			yume_assert_same( array(), $n->tome, 'aucun yume_tome_publie' );
			yume_assert_same( array(), $n->chap, 'aucun yume_chapitre_publie' );
			yume_assert_same( array(), $n->alertes, 'aucune alerte e-mail' );
			yume_assert_same( array(), $n->discord, 'aucun message Discord' );
			yume_assert_same( 0, Annonce::existant( $tome ), 'aucun article d’annonce' );
			yume_assert_same( $sorties_avant, yume_tpub_sorties(), 'rien de nouveau dans « Sorties »' );

			$chapitres = yume_get_chapitres( $tome );
			yume_assert_same( 13, count( $chapitres ), 'chapitres en ligne' );
			foreach ( $chapitres as $chapitre ) {
				yume_assert_same( $ancienne, $chapitre->post_date_gmt, 'chapitre daté de la sortie du tome' );
				yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $chapitre->ID, '_yume_publie_notifie', true ) );
				yume_assert_same( '', (string) get_post_meta( $chapitre->ID, '_yume_alerte_envoyee', true ), 'hors récapitulatif hebdomadaire' );
			}
			yume_assert_same( 'publish', get_post_status( $tome ) );
			yume_assert_same( $ancienne, get_post_field( 'post_date_gmt', $tome ), 'date du tome conservée' );
			yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $tome, '_yume_publie_notifie', true ), 'tome marqué « catalogue »' );
			yume_assert_same( 13, (int) get_post_meta( $tome, 'yume_nb_chapitres', true ), 'nombre de chapitres recalculé' );
			yume_assert_true( (bool) apply_filters( 'yume_core_notifier', true, get_post( $tome ), 'yume_tome_publie' ), 'filtre yume_core_notifier rétabli' );
			yume_assert_same( 'https://www.clictune.com/pdf10', get_post_meta( $tome, 'yume_lien_pdf', true ) );
			$meta = get_post_meta( $tome, Service::META, true );
			yume_assert_true( ! empty( $meta['sans_annonce'] ) );

			// Journal de l'équipe : ligne non publique.
			if ( function_exists( 'Yume\Core\Planning\lire_journal' ) ) {
				$lignes = \Yume\Core\Planning\lire_journal(
					array(
						'tome_id' => $tome,
						'champs'  => array( 'lecture_ajoutee' ),
					)
				);
				yume_assert_same( 1, count( $lignes ), 'journal : lecture en ligne ajoutée' );
				yume_assert_same( 0, (int) $lignes[0]->public, 'ligne réservée à l’équipe' );
				yume_assert_contains( 'lecture en ligne ajoutée (sans annonce) : 13 chapitres', \Yume\Core\Planning\texte_changement( $lignes[0], true ) );
				yume_assert_same(
					array(),
					\Yume\Core\Planning\lire_journal(
						array(
							'tome_id' => $tome,
							'public'  => true,
							'champs'  => array( 'lecture_ajoutee', 'publie' ),
						)
					)
				);
			}

			// Plus tard, de vrais nouveaux chapitres : annoncés comme chapitres, jamais le tome ancien.
			$nouveaux = array();
			foreach ( array( 14, 15 ) as $i => $numero ) {
				$nouveaux[] = yume_factory_post(
					array(
						'post_type'    => 'yume_chapitre',
						'post_title'   => 'Chapitre ' . $numero,
						'post_status'  => 'draft',
						'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
						'menu_order'   => 20 + $i,
						'meta_input'   => array(
							'yume_tome_id' => $tome,
							'yume_numero'  => $numero,
							'yume_nature'  => 'chapitre',
						),
					)
				);
			}
			$n = yume_tpub_compter(
				function () use ( $tome ) {
					Service::publier( $tome, 'maintenant' );
				}
			);
			yume_assert_same( array(), $n->tome, 'le tome n’est pas « sorti » une seconde fois' );
			yume_assert_same( 1, count( $n->chap ), 'une sortie groupée des nouveaux chapitres' );
		}
	)
);

yume_test(
	'catalogue : un nouveau tome publié ensuite est toujours annoncé (article, yume_tome_publie, Discord)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre        = yume_tpub_oeuvre( 'Catalogue puis nouveauté' );
			list( $migre ) = yume_tpub_tome_migre( $oeuvre, 3 );
			$n             = yume_tpub_compter(
				function () use ( $ctx, $oeuvre, $migre ) {
					yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '3' ) );
					Service::publier( $migre, 'maintenant', array( 'sans_annonce' => true ) );
				}
			);
			yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->discord ) );

			$n = yume_tpub_compter(
				function ( $n ) use ( $ctx, $oeuvre ) {
					$r = yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '4' ) );
					yume_assert_false( $r['sans_annonce'], 'nouveau tome : avec annonce par défaut' );
					yume_assert_true( is_array( $r['article'] ), 'article d’annonce préparé' );
					$sortie = Service::publier( (int) $r['tome']['id'], 'maintenant' );
					yume_assert_true( is_array( $sortie['article'] ) && 'publish' === $sortie['article']['statut'], 'annonce publiée' );
					yume_assert_same( array( (int) $r['tome']['id'] ), $n->tome, 'yume_tome_publie émis' );
				}
			);
			yume_assert_same( 1, count( $n->discord ), 'message Discord de la sortie' );
			yume_assert_same( array(), $n->chap );
		}
	)
);

yume_test(
	'catalogue : case « Ajout au catalogue » cochée pour un tome publié, décochée pour un brouillon ou un nouveau tome ; récapitulatif',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre        = yume_tpub_oeuvre( 'Catalogue formulaire' );
			list( $migre ) = yume_tpub_tome_migre( $oeuvre, 2 );
			$brouillon     = yume_tpub_preparer( $ctx, $oeuvre, array( 'numero' => '5' ) );
			$rendu         = static function ( int $tome ): string {
				$_GET['tome'] = (string) $tome;
				try {
					return yume_render_block( 'yume/publish-form' );
				} finally {
					unset( $_GET['tome'] );
				}
			};
			$case          = '#<input id="yn-publish-sans-annonce" type="checkbox" name="sans_annonce" value="1"[^>]*>#';

			$html = $rendu( $migre );
			yume_assert_true( (bool) preg_match( $case, $html, $m ), 'case présente' );
			yume_assert_contains( 'checked', $m[0], 'tome publié : cochée' );
			yume_assert_contains( '<input type="hidden" name="sans_annonce" value="0">', $html, 'décochée = « 0 » envoyé' );
			yume_assert_contains( 'Ajout au catalogue : ne pas annoncer (pas d’article, pas de Discord, pas d’e-mail)', $html );
			yume_assert_contains( '<li data-yn-recap-annonce hidden>', $html, 'pas d’« Article d’annonce »' );
			yume_assert_contains( '<li data-yn-recap-notifications hidden>', $html, 'pas de « Notifications »' );
			yume_assert_contains( '<li data-yn-recap-catalogue >', $html, 'ligne « Aucune annonce » visible' );
			yume_assert_not_contains( 'data-yn-remplacement', $html, 'aucun chapitre : pas de note de remplacement' );

			$html = $rendu( (int) $brouillon['tome']['id'] );
			preg_match( $case, $html, $m );
			yume_assert_not_contains( 'checked', $m[0], 'brouillon : décochée' );
			yume_assert_contains( 'disabled', $m[0], 'brouillon : case « Ajout au catalogue » désactivée (masquée)' );
			yume_assert_true( (bool) preg_match( '#<input id="yn-publish-annoncer" type="checkbox" name="annoncer" value="1"[^>]*checked#', $html ), 'brouillon : « Annoncer les nouveaux chapitres » cochée' );
			yume_assert_contains( '<li data-yn-recap-annonce >', $html );
			yume_assert_contains( '<li data-yn-recap-catalogue hidden>', $html );
			yume_assert_contains( 'Ce tome a déjà 13 chapitres : le fichier leur est comparé', $html, 'note : comparaison avec le tome' );

			$html = yume_render_block( 'yume/publish-form' );
			preg_match( $case, $html, $m );
			yume_assert_not_contains( 'checked', $m[0], 'nouveau tome : décochée' );
		}
	)
);

yume_test(
	'catalogue : REST sans_annonce (absent : selon le statut du tome ; explicite : respecté)',
	yume_tpub(
		function ( $ctx ) {
			$editeur                 = yume_factory_user( 'yume_editeur' );
			$oeuvre                  = yume_tpub_oeuvre( 'Catalogue REST' );
			list( $tome, $ancienne ) = yume_tpub_tome_migre( $oeuvre, 6 );
			$champs                  = array(
				'oeuvre_id' => $oeuvre,
				'nature'    => 'tome',
				'numero'    => '6',
			);
			$n                       = yume_tpub_compter(
				function () use ( $ctx, $editeur, $champs, $tome ) {
					$cree = yume_rest( 'POST', '/yume/v1/publications', $champs, $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) ) );
					yume_assert_same( 200, $cree->get_status() );
					yume_assert_true( $cree->get_data()['sans_annonce'], 'absent + tome publié : sans annonce' );
					yume_assert_same( null, $cree->get_data()['article'] );
					$sortie = yume_rest( 'POST', '/yume/v1/publications/' . $tome . '/publier', array( 'quand' => 'maintenant' ), $editeur );
					yume_assert_same( 200, $sortie->get_status() );
					yume_assert_true( $sortie->get_data()['sans_annonce'] );
					yume_assert_same( 13, $sortie->get_data()['chapitres'] );
				}
			);
			yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->alertes, $n->discord ) );
			yume_assert_same( 0, Annonce::existant( $tome ) );
			yume_assert_same( $ancienne, yume_get_chapitres( $tome )[0]->post_date_gmt );

			// Nouveau tome : avec annonce par défaut ; sans_annonce explicite respecté.
			$n = yume_tpub_compter(
				function () use ( $ctx, $editeur, $oeuvre ) {
					$cree = yume_rest(
						'POST',
						'/yume/v1/publications',
						array(
							'oeuvre_id'    => $oeuvre,
							'numero'       => '7',
							'sans_annonce' => 'true',
						),
						$editeur,
						array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) )
					);
					yume_assert_same( 201, $cree->get_status() );
					yume_assert_true( $cree->get_data()['sans_annonce'] );
					$id     = (int) $cree->get_data()['tome']['id'];
					$sortie = yume_rest(
						'POST',
						'/yume/v1/publications/' . $id . '/publier',
						array(
							'quand'        => 'maintenant',
							'sans_annonce' => '1',
						),
						$editeur
					);
					yume_assert_same( 'publish', $sortie->get_data()['statut'] );
					yume_assert_true( $sortie->get_data()['sans_annonce'] );
					yume_assert_same( 0, Annonce::existant( $id ) );
					yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $id, '_yume_publie_notifie', true ) );
				}
			);
			yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->discord ), 'nouveau tome ajouté sans annonce : muet' );

			$n = yume_tpub_compter(
				function ( $n ) use ( $ctx, $editeur, $oeuvre ) {
					$cree = yume_rest(
						'POST',
						'/yume/v1/publications',
						array(
							'oeuvre_id' => $oeuvre,
							'numero'    => '8',
						),
						$editeur,
						array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) )
					);
					yume_assert_same( 201, $cree->get_status(), wp_json_encode( $cree->get_data() ) );
					yume_assert_false( $cree->get_data()['sans_annonce'], 'absent + nouveau tome : avec annonce' );
					$id = (int) $cree->get_data()['tome']['id'];
					yume_assert_false( yume_rest( 'POST', '/yume/v1/publications/' . $id . '/publier', array( 'quand' => 'maintenant' ), $editeur )->get_data()['sans_annonce'] );
					yume_assert_same( array( $id ), $n->tome );
				}
			);
			yume_assert_same( 1, count( $n->discord ) );
		}
	)
);

yume_test(
	'catalogue : formulaire sans JavaScript, case cochée → publication sans annonce et message adapté',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre       = yume_tpub_oeuvre( 'Catalogue sans JS' );
			list( $tome ) = yume_tpub_tome_migre( $oeuvre, 9 );
			$redirige     = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			$n            = yume_tpub_compter(
				function () use ( $ctx, $oeuvre, $tome, $redirige ) {
					add_filter( 'wp_redirect', $redirige, 1 );
					$_POST  = array(
						'action'        => 'yume_publication',
						'_yume_nonce'   => wp_create_nonce( 'yume_publication' ),
						'etape'         => 'publier',
						'oeuvre_id'     => (string) $oeuvre,
						'tome_planning' => (string) $tome,
						'numero'        => '9',
						'sans_annonce'  => '1',
					);
					$_FILES = array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) );
					try {
						Formulaire::traiter();
					} catch ( RuntimeException $e ) {
						yume_assert_contains( 'tome=' . $tome, $e->getMessage() );
					} finally {
						remove_filter( 'wp_redirect', $redirige, 1 );
						$_POST  = array();
						$_FILES = array();
					}
				}
			);
			$retour       = get_transient( Formulaire::RETOUR . $editeur );
			yume_assert_same( 'succes', $retour['type'] );
			yume_assert_contains( 'lecture en ligne ajoutée (13 chapitres), sans annonce', $retour['message'] );
			yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->discord ) );
			yume_assert_same( 13, count( yume_get_chapitres( $tome ) ) );
			yume_assert_same( 0, Annonce::existant( $tome ) );
		}
	)
);

yume_test(
	'Titre d’annonce : élision et contraction de l’article (« des Lanternes », « du Voyageur », « L’arc »)',
	function () {
		yume_assert_same( 'Le tome 2 des Lanternes de Brume-Haute est disponible !', Annonce::elision( 'Le tome 2 de Les Lanternes de Brume-Haute est disponible !' ) );
		yume_assert_same( 'L’arc 3 du Voyageur est disponible !', Annonce::elision( 'Le arc 3 de Le Voyageur est disponible !' ) );
		yume_assert_same( 'Le tome 1 de Grimgar est disponible !', Annonce::elision( 'Le tome 1 de Grimgar est disponible !' ) );
		yume_assert_same( 'Le tome 1 de L’Attaque des Titans', Annonce::elision( 'Le tome 1 de L’Attaque des Titans' ) );
		yume_assert_same( 'Le tome 5 du monde de Lesley', Annonce::elision( 'Le tome 5 du monde de Lesley' ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Découpage manuel en chapitres (le fichier n'est jamais conservé : le découpage est
 * renvoyé avec lui)
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( 'yume_tpub_plan' ) ) {
	/**
	 * Découpage manuel à partir des débuts possibles d'une analyse : extrait => nature.
	 *
	 * @param array<int,array<string,mixed>> $candidats Débuts possibles (rapport d'analyse).
	 * @param array<string,string>           $debuts    Début de l'extrait => nature.
	 * @param bool                           $garder    Conserver le texte d'ouverture.
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Début absent.
	 */
	function yume_tpub_plan( array $candidats, array $debuts, bool $garder = false ): array {
		$plan = array(
			'debuts'       => array(),
			'garder_avant' => $garder,
		);
		foreach ( $debuts as $extrait => $nature ) {
			$trouve = null;
			foreach ( $candidats as $c ) {
				if ( str_starts_with( (string) $c['extrait'], (string) $extrait ) ) {
					$trouve = $c;
					break;
				}
			}
			if ( null === $trouve ) {
				throw new Yume_Test_Failure( 'Début possible absent : ' . $extrait );
			}
			$plan['debuts'][] = array(
				'ancre'  => $trouve['ancre'],
				'nature' => $nature,
				'titre'  => (string) $trouve['titre'],
			);
		}
		return $plan;
	}
}

yume_test(
	'découpage : l’analyse renvoie les débuts possibles et accepte un découpage ; préparation avec découpage (service, REST, sans JavaScript)',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			$oeuvre  = yume_tpub_oeuvre();
			$analyse = yume_rest( 'POST', '/yume/v1/publications/analyse', array(), $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) ) );
			yume_assert_same( 200, $analyse->get_status() );
			$data = $analyse->get_data();
			yume_assert_true( count( $data['candidats'] ) > 20, 'débuts possibles' );
			yume_assert_same( array( 'images', 'ouvertures', 'sauts' ), array_keys( $data['decoupages'] ) );
			yume_assert_same( 13, count( array_filter( $data['candidats'], static fn( $c ) => $c['auto'] ) ), 'un début automatique par chapitre' );
			$plan = yume_tpub_plan(
				$data['candidats'],
				array(
					'Prologue'   => 'prologue',
					'Chapitre 1' => 'chapitre',
				)
			);

			// Analyse avec le découpage (JSON), même fichier renvoyé.
			$essai = yume_rest( 'POST', '/yume/v1/publications/analyse', array( 'plan' => wp_json_encode( $plan ) ), $editeur, array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) ) );
			yume_assert_same( 200, $essai->get_status() );
			yume_assert_same( array( 'Prologue', 'Chapitre 1 — La Crête Brumeuse' ), array_column( $essai->get_data()['chapitres'], 'libelle' ) );
			yume_assert_true( $essai->get_data()['stats']['decoupage_manuel'] );

			// Découpage invalide : 400, fichier supprimé quand même.
			$fichier = yume_tpub_fichier( $ctx, 'regles.docx' );
			$refus   = yume_rest( 'POST', '/yume/v1/publications/analyse', array( 'plan' => '{"debuts":[{"ancre":"<script>","nature":"chapitre"}]}' ), $editeur, array( 'source' => $fichier ) );
			yume_assert_same( 400, $refus->get_status() );
			yume_assert_same( 'rest_invalid_param', $refus->get_data()['code'] );
			$fichier = yume_tpub_fichier( $ctx, 'regles.docx' );
			$refus   = Service::analyser( $fichier, array( 'plan' => array( 'debuts' => array() ) ) );
			yume_assert_same( 'yume_plan_invalide', $refus->get_error_code() );
			yume_assert_false( file_exists( $fichier['tmp_name'] ), 'source supprimée malgré l’erreur' );

			// Service : préparation avec découpage (tableau).
			wp_set_current_user( $editeur );
			$r = yume_tpub_preparer( $ctx, $oeuvre, array( 'plan' => $plan ) );
			yume_assert_same( 2, count( $r['chapitres'] ) );
			yume_assert_same( array( 'prologue', 'chapitre' ), array_column( $r['chapitres'], 'nature' ) );
			yume_assert_false( isset( $r['import']['candidats'] ), 'débuts possibles absents du rapport de préparation' );
			$chapitres = yume_get_chapitres( (int) $r['tome']['id'], array( 'status' => 'any' ) );
			yume_assert_same( 2, count( $chapitres ) );
			yume_assert_contains( 'Texte de l’épilogue.', $chapitres[1]->post_content, 'tout le texte dans le chapitre 1' );
			yume_assert_contains( '<h2 class="wp-block-heading">Chapitre 5</h2>', $chapitres[1]->post_content, 'titre détecté devenu intertitre' );
			yume_assert_same( 'La Crête Brumeuse', get_post_meta( $chapitres[1]->ID, 'yume_sous_titre', true ) );
			// Aucun découpage enregistré sur le tome.
			yume_assert_false( isset( get_post_meta( (int) $r['tome']['id'], Service::META, true )['plan'] ) );
			// Sans fichier : découpage ignoré, signalé.
			$sans = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '10',
					'plan'      => $plan,
				)
			);
			yume_assert_contains( 'Découpage manuel ignoré', implode( "\n", $sans['avertissements'] ) );

			// REST : création avec le découpage en JSON.
			$cree = yume_rest(
				'POST',
				'/yume/v1/publications',
				array(
					'oeuvre_id' => $oeuvre,
					'nature'    => 'tome',
					'numero'    => '11',
					'plan'      => wp_json_encode( $plan ),
				),
				$editeur,
				array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) )
			);
			yume_assert_same( 201, $cree->get_status() );
			yume_assert_same( 2, count( $cree->get_data()['chapitres'] ) );

			// Sans JavaScript : champ caché « plan » envoyé avec le fichier.
			wp_set_current_user( $editeur );
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = wp_slash(
				array(
					'action'      => 'yume_publication',
					'_yume_nonce' => wp_create_nonce( 'yume_publication' ),
					'etape'       => 'brouillon',
					'oeuvre_id'   => (string) $oeuvre,
					'nature'      => 'tome',
					'numero'      => '12',
					'plan'        => wp_json_encode( array_merge( $plan, array( 'garder_avant' => true ) ) ),
				)
			);
			$_FILES = array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) );
			try {
				Formulaire::traiter();
			} catch ( RuntimeException $e ) {
				yume_assert_contains( 'redirection:', $e->getMessage() );
			} finally {
				remove_filter( 'wp_redirect', $redirige, 1 );
				$_POST  = array();
				$_FILES = array();
			}
			$retour = get_transient( Formulaire::RETOUR . $editeur );
			yume_assert_same( 'succes', $retour['type'], (string) $retour['message'] );
			yume_assert_contains( '2 chapitres', $retour['message'] );
			$tome     = Service::trouver_tome( $oeuvre, 'tome', 12.0 );
			$prologue = yume_get_chapitres( $tome->ID, array( 'status' => 'any' ) )[0];
			yume_assert_contains( 'équipe Yume</p>', $prologue->post_content, 'texte d’ouverture conservé' );
		}
	)
);

yume_test(
	'découpage : remplacement d’une lecture en ligne (version en attente) avec découpage, appliqué à la publication',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre = yume_tpub_oeuvre();
			$r      = yume_tpub_preparer( $ctx, $oeuvre );
			$tome   = (int) $r['tome']['id'];
			Service::publier( $tome, 'maintenant', array( 'sans_annonce' => true ) );
			yume_assert_true( \Yume\Core\Publication\Remplacement::mode( $tome ), 'tome en ligne' );
			$en_ligne = array();
			foreach ( yume_get_chapitres( $tome ) as $c ) {
				$en_ligne[ $c->ID ] = $c->post_content;
			}
			yume_assert_same( 13, count( $en_ligne ) );

			$analyse = Service::analyser( yume_tpub_fichier( $ctx, 'regles.docx' ) );
			$plan    = yume_tpub_plan(
				$analyse['candidats'],
				array(
					'Prologue'   => 'prologue',
					'Chapitre 1' => 'chapitre',
					'Chapitre2'  => 'chapitre',
				)
			);
			// « Vérifier » : version en attente découpée selon le plan, rien ne change en ligne.
			wp_set_current_user( $editeur );
			$v = yume_tpub_preparer( $ctx, $oeuvre, array( 'plan' => $plan ) );
			yume_assert_true( is_array( $v['remplacement'] ), 'remplacement en attente' );
			yume_assert_same( 3, count( $v['remplacement']['chapitres'] ) );
			yume_assert_same( 10, $v['remplacement']['absents'] );
			foreach ( yume_get_chapitres( $tome ) as $c ) {
				yume_assert_same( $en_ligne[ $c->ID ], get_post( $c->ID )->post_content, 'chapitre en ligne inchangé' );
			}
			// Application à la publication : le chapitre 2 (jusqu'à la fin du fichier) remplacé en
			// place (même adresse), le chapitre 1 inchangé.
			$sortie = Service::publier( $tome, 'maintenant', array( 'sans_annonce' => true ) );
			yume_assert_false( is_wp_error( $sortie ), is_wp_error( $sortie ) ? $sortie->get_error_message() : '' );
			$par_adresse = array();
			foreach ( yume_get_chapitres( $tome ) as $c ) {
				$par_adresse[ $c->post_name ] = get_post( $c->ID );
			}
			$chapitre1 = $par_adresse['chapitre-1'];
			$chapitre2 = $par_adresse['chapitre-2'];
			yume_assert_same( $en_ligne[ $chapitre1->ID ], $chapitre1->post_content, 'chapitre 1 inchangé' );
			yume_assert_true( isset( $en_ligne[ $chapitre2->ID ] ), 'même chapitre 2' );
			yume_assert_contains( 'Texte de l’épilogue.', $chapitre2->post_content, 'chapitre 2 remplacé' );
			yume_assert_true( $en_ligne[ $chapitre2->ID ] !== $chapitre2->post_content );
		}
	)
);
