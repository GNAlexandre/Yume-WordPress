<?php
/**
 * Tests de l'ajout de chapitres à un tome (mode « chapitres » du module publication,
 * formulaire « Ajouter des chapitres à un tome ») : tome choisi jamais renommé, comparaison du
 * fichier avec le tome, ajout sans remplacement en deux temps, chapitres en ligne gardés ou mis
 * à jour, sortie maintenant / au rythme / à une date, parution en cours puis complète, planning,
 * annonces, ajout au catalogue d'un tome migré, formulaire, REST.
 *
 * @package Yume\Core
 */

use Yume\Core\Publication\Annonce;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Remplacement;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yume_tpub' ) ) {
	// Fonctions d'aide du module publication (fixtures, enveloppe de test) sans ses tests.
	$GLOBALS['yume_tests_publication_aides_seules'] = true;
	require __DIR__ . '/test-publication.php';
	unset( $GLOBALS['yume_tests_publication_aides_seules'] );
}

if ( ! function_exists( 'yume_tpc_docx' ) ) {
	/**
	 * Chapitres de référence du tome 2 de SukaMoka : titre (style Titre 1) => paragraphes.
	 *
	 * @param string $cle prologue, ch1, ch1_modifie, ch2, ch3.
	 * @return array<string,string[]>
	 */
	function yume_tpc_chapitre( string $cle ): array {
		$chapitres = array(
			'prologue'    => array( 'Prologue' => array( 'Il était une fois une ville sans ciel.', 'La pluie y tombait vers le haut.' ) ),
			'ch1'         => array( 'Chapitre 1' => array( 'Le port s’éveillait à peine.', 'Moka regardait les navires rentrer.' ) ),
			'ch1_modifie' => array( 'Chapitre 1' => array( 'Le port s’éveillait lentement.', 'Moka regardait les navires rentrer au port.' ) ),
			'ch2'         => array( 'Chapitre 2' => array( 'Les cloches sonnèrent trois fois.' ) ),
			'ch3'         => array( 'Chapitre 3' => array( 'La ville sans ciel se tut.' ) ),
		);
		return $chapitres[ $cle ];
	}

	/**
	 * DOCX temporaire fait des chapitres demandés, et son entrée $_FILES.
	 *
	 * @param stdClass $ctx  Contexte (fichiers supprimés à la fin).
	 * @param string[] $cles Chapitres (yume_tpc_chapitre()).
	 * @return array<string,mixed>
	 */
	function yume_tpc_docx( stdClass $ctx, array $cles ): array {
		yume_timp_outils_fixtures();
		$corps = '';
		foreach ( $cles as $cle ) {
			foreach ( yume_tpc_chapitre( $cle ) as $titre => $paragraphes ) {
				$corps .= yume_fx_t( $titre, array( 'style' => 'Titre1' ) );
				foreach ( $paragraphes as $paragraphe ) {
					$corps .= yume_fx_t( $paragraphe );
				}
			}
		}
		$docx            = yume_timp_docx( $corps );
		$ctx->fichiers[] = $docx;
		return yume_tpub_fichier( $ctx, $docx, 'SukaMoka_T2.docx' );
	}

	/**
	 * Tome 2 de l'œuvre (brouillon du planning par défaut).
	 *
	 * @param int                 $oeuvre Œuvre.
	 * @param array<string,mixed> $meta   Méta supplémentaires.
	 * @param int                 $numero Numéro.
	 */
	function yume_tpc_tome( int $oeuvre, array $meta = array(), int $numero = 2 ): int {
		return yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_status' => 'draft',
				'post_title'  => get_the_title( $oeuvre ) . ' — Tome ' . $numero,
				'meta_input'  => array_merge(
					array(
						'yume_oeuvre_id' => $oeuvre,
						'yume_numero'    => $numero,
						'yume_nature'    => 'tome',
					),
					$meta
				),
			)
		);
	}

	/**
	 * Nouvelle requête simulée : la publication précédente est terminée.
	 */
	function yume_tpc_requete(): void {
		unset( $GLOBALS['wp_actions']['yume_publication_en_cours'] );
		if ( function_exists( 'Yume\Core\Core\etat_set' ) ) {
			\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
			\Yume\Core\Core\etat_set( 'publies_yume_chapitre', array() );
		}
	}

	/**
	 * Prépare un ajout de chapitres au tome choisi (mode chapitres).
	 *
	 * @param stdClass            $ctx    Contexte.
	 * @param int                 $oeuvre Œuvre.
	 * @param int                 $tome   Tome.
	 * @param string[]            $cles   Chapitres du fichier.
	 * @param array<string,mixed> $champs Champs supplémentaires.
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Préparation en erreur.
	 */
	function yume_tpc_preparer( stdClass $ctx, int $oeuvre, int $tome, array $cles, array $champs = array() ): array {
		$rapport = Service::preparer(
			array_merge(
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => Service::MODE_CHAPITRES,
				),
				$champs
			),
			array( 'source' => yume_tpc_docx( $ctx, $cles ) )
		);
		if ( is_wp_error( $rapport ) ) {
			throw new Yume_Test_Failure( 'preparer : ' . $rapport->get_error_message() );
		}
		return $rapport;
	}

	/**
	 * Sort les chapitres préparés (mode chapitres).
	 *
	 * @param int                 $tome    Tome.
	 * @param array<string,mixed> $options Options de Service::publier().
	 * @param string              $quand   maintenant ou date.
	 * @return array<string,mixed>
	 * @throws Yume_Test_Failure Sortie en erreur.
	 */
	function yume_tpc_publier( int $tome, array $options = array(), string $quand = 'maintenant' ): array {
		$sortie = Service::publier(
			$tome,
			$quand,
			array_merge(
				array(
					'mode'         => Service::MODE_CHAPITRES,
					'sortie'       => 'maintenant',
					'sans_annonce' => false,
				),
				$options
			)
		);
		if ( is_wp_error( $sortie ) ) {
			throw new Yume_Test_Failure( 'publier : ' . $sortie->get_error_message() );
		}
		return $sortie;
	}

	/**
	 * Compte les événements (tome publié, chapitre publié, tome complet) et capture les textes
	 * Discord pendant $corps.
	 *
	 * @param callable $corps Corps.
	 * @return stdClass tome, chap ([id, groupe]), complet ([id, annoncer]), discord_tome, discord_chap.
	 */
	function yume_tpc_compter( callable $corps ): stdClass {
		$n               = new stdClass();
		$n->tome         = array();
		$n->chap         = array();
		$n->complet      = array();
		$n->discord_tome = array();
		$n->discord_chap = array();
		$tome            = static function ( $id ) use ( $n ) {
			$n->tome[] = (int) $id;
		};
		$chap            = static function ( $id, $groupe = array() ) use ( $n ) {
			$n->chap[] = array( (int) $id, array_map( 'intval', (array) $groupe ) );
		};
		$complet         = static function ( $id, $annoncer = true ) use ( $n ) {
			$n->complet[] = array( (int) $id, (bool) $annoncer );
		};
		$discord_tome    = static function ( $annonce ) use ( $n ) {
			$n->discord_tome[] = (string) ( $annonce['texte'] ?? '' );
			return $annonce;
		};
		$discord_chap    = static function ( $texte ) use ( $n ) {
			$n->discord_chap[] = (string) $texte;
			return $texte;
		};
		add_action( 'yume_tome_publie', $tome, 1 );
		add_action( 'yume_chapitre_publie', $chap, 1, 2 );
		add_action( 'yume_tome_complet', $complet, 1, 2 );
		add_filter( 'yume_planning_annonce_tome', $discord_tome, 99 );
		add_filter( 'yume_planning_annonce_chapitre', $discord_chap, 99 );
		try {
			$corps();
		} finally {
			remove_action( 'yume_tome_publie', $tome, 1 );
			remove_action( 'yume_chapitre_publie', $chap, 1 );
			remove_action( 'yume_tome_complet', $complet, 1 );
			remove_filter( 'yume_planning_annonce_tome', $discord_tome, 99 );
			remove_filter( 'yume_planning_annonce_chapitre', $discord_chap, 99 );
		}
		return $n;
	}

	/**
	 * Photographie d'un chapitre (titre, contenu, statut, dates, adresse, ordre).
	 *
	 * @param int $id Chapitre.
	 * @return array<int,mixed>
	 */
	function yume_tpc_photo( int $id ): array {
		clean_post_cache( $id );
		$c = get_post( $id );
		return array( $c->post_title, $c->post_content, $c->post_status, $c->post_modified_gmt, $c->post_date_gmt, $c->post_name, (int) $c->menu_order );
	}

	/**
	 * Chapitre du tome par libellé (« Prologue », « Chapitre 2 »), tous statuts.
	 *
	 * @param int    $tome    Tome.
	 * @param string $libelle Libellé.
	 */
	function yume_tpc_id( int $tome, string $libelle ): int {
		foreach ( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) as $c ) {
			if ( yume_libelle_chapitre( (int) $c->ID ) === $libelle ) {
				return (int) $c->ID;
			}
		}
		return 0;
	}

	/**
	 * Tome 2 de SukaMoka en cours de parution : chapitres demandés publiés (première sortie).
	 *
	 * @param stdClass            $ctx    Contexte.
	 * @param int                 $oeuvre Œuvre.
	 * @param string[]            $cles   Premiers chapitres.
	 * @param array<string,mixed> $meta   Méta du tome.
	 */
	function yume_tpc_tome_en_cours( stdClass $ctx, int $oeuvre, array $cles, array $meta = array() ): int {
		$tome = yume_tpc_tome( $oeuvre, $meta );
		yume_tpc_preparer( $ctx, $oeuvre, $tome, $cles );
		yume_tpc_publier( $tome );
		yume_tpc_requete();
		return $tome;
	}
}

/*
 * -----------------------------------------------------------------------------
 * Le tome d'abord : jamais renommé
 * -----------------------------------------------------------------------------
 */

yume_test(
	'chapitres : un tome choisi garde sa nature et son numéro (nature « chapitres » reçue), sans doublon ; autre œuvre ou tome inconnu refusés',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome( $oeuvre );
			$titre  = get_post_field( 'post_title', $tome );
			// Mode chapitres (formulaire) et comportement historique (API sans mode) : même règle.
			foreach ( array( Service::MODE_CHAPITRES, '' ) as $mode ) {
				$r = Service::preparer(
					array(
						'oeuvre_id' => $oeuvre,
						'tome_id'   => $tome,
						'nature'    => 'chapitres',
						'numero'    => '7',
						'mode'      => $mode,
					),
					array( 'source' => yume_tpc_docx( $ctx, array( 'prologue' ) ) )
				);
				yume_assert_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
				yume_assert_same( $tome, (int) $r['tome']['id'], 'tome choisi (' . $mode . ')' );
				yume_assert_same( 'tome', get_post_meta( $tome, 'yume_nature', true ), 'nature gardée (' . $mode . ')' );
				yume_assert_same( 2.0, (float) get_post_meta( $tome, 'yume_numero', true ), 'numéro gardé (' . $mode . ')' );
				yume_assert_same( 'Tome 2', yume_libelle_tome( $tome ) );
				yume_assert_same( $titre, get_post_field( 'post_title', $tome ), 'titre gardé' );
				yume_assert_same( 1, count( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) ), 'aucun doublon' );
			}
			yume_assert_same( 'tome-2', get_post_field( 'post_name', $tome ), 'adresse du contrat d’après le tome' );

			// Tome choisi : numéro facultatif ; tome d'une autre œuvre ou inconnu refusé.
			$r = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => Service::MODE_CHAPITRES,
				)
			);
			yume_assert_false( is_wp_error( $r ), 'sans numéro' );
			$autre = yume_tpub_oeuvre( 'Autre œuvre' );
			$r     = Service::preparer(
				array(
					'oeuvre_id' => $autre,
					'tome_id'   => $tome,
					'mode'      => Service::MODE_CHAPITRES,
				)
			);
			yume_assert_same( 'yume_tome_autre_oeuvre', is_wp_error( $r ) ? $r->get_error_code() : '' );
			$r = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => 999999,
					'mode'      => Service::MODE_CHAPITRES,
				)
			);
			yume_assert_same( 'yume_tome_invalide', is_wp_error( $r ) ? $r->get_error_code() : '' );
			$r = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'mode'      => 'n_importe',
				)
			);
			yume_assert_same( 'yume_mode_invalide', is_wp_error( $r ) ? $r->get_error_code() : '' );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Première sortie d'un tome en cours, puis ajouts
 * -----------------------------------------------------------------------------
 */

yume_test(
	'chapitres : prologue seul dans un tome vide → tome publié, parution en cours, annonce « Prologue disponible », planning non 100 %',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome( $oeuvre, array( 'yume_chapitres_prevus' => 12 ) );
			$r      = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue' ) );
			yume_assert_same( null, $r['remplacement'] );
			yume_assert_same( 1, $r['comparaison']['nouveaux'] );
			yume_assert_same( 'nouveau', $r['comparaison']['lignes'][0]['etat'] );
			yume_assert_same( 'prologue:0#1', $r['comparaison']['lignes'][0]['cle'] );
			yume_assert_same( 'SukaMoka, Tome 2 : Prologue disponible !', get_post_field( 'post_title', Annonce::existant( $tome ) ), 'brouillon d’annonce : variante chapitres' );

			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					$sortie = yume_tpc_publier( $tome );
				}
			);
			clean_post_cache( $tome );
			yume_assert_same( 'publish', get_post_status( $tome ) );
			yume_assert_same( 'en_cours', get_post_meta( $tome, 'yume_parution', true ) );
			yume_assert_same( 'en_cours', yume_parution_tome( $tome ) );
			yume_assert_same( 'en_cours', $sortie['parution'] );
			yume_assert_same( 1, $sortie['chapitres'] );
			yume_assert_same( array( $tome ), $n->tome, 'yume_tome_publie une seule fois' );
			yume_assert_same( array(), $n->chap );
			yume_assert_same( array(), $n->complet );
			$article = Annonce::existant( $tome );
			yume_assert_same( 'SukaMoka, Tome 2 : Prologue disponible !', get_post_field( 'post_title', $article ) );
			yume_assert_same( 'publish', get_post_status( $article ) );
			yume_assert_contains( 'Prologue est disponible en lecture en ligne', get_post_field( 'post_content', $article ) );
			yume_assert_not_contains( 'Au programme', get_post_field( 'post_content', $article ) );
			yume_assert_same( 1, count( $n->discord_tome ) );
			yume_assert_contains( 'Tome 2 : Prologue disponible !', $n->discord_tome[0] );
			yume_assert_not_contains( 'est disponible', $n->discord_tome[0] );

			// Planning : sortie partielle, l'avancement suit les chapitres (1 sur 12).
			$planning = \Yume\Core\Planning\donnees_tome( $tome );
			yume_assert_true( 'publie' !== $planning['etape'], 'étape gardée' );
			yume_assert_same( 8, (int) $planning['avancement']['edition'], '1 sur 12 = 8 %' );
			yume_assert_true( (bool) get_post_meta( $tome, '_yume_planning_sortie_partielle', true ), 'sortie partielle notée' );
			yume_assert_false( \Yume\Core\Planning\verifier_fin_sortie( $tome ), 'aucun chapitre en attente : toujours en cours' );
			yume_assert_contains( 'Tome 2 : 1 chapitre en ligne', Formulaire::message_chapitres( $sortie ) );
		}
	)
);

yume_test(
	'chapitres : ajout d’un chapitre à un tome en cours, sans remplacement en deux temps ; les chapitres en ligne ne sont pas touchés',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre   = yume_tpub_oeuvre( 'SukaMoka' );
			$tome     = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue' ), array( 'yume_chapitres_prevus' => 12 ) );
			$prologue = yume_tpc_id( $tome, 'Prologue' );
			$avant    = yume_tpc_photo( $prologue );
			yume_assert_true( Remplacement::mode( $tome ), 'tome paru avec sa lecture en ligne' );
			yume_assert_false( Service::sans_annonce_par_defaut( $tome ), 'tome en cours : annoncé par défaut' );

			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1' ) );
			yume_assert_same( null, $r['remplacement'], 'pas de version en attente' );
			yume_assert_same( array(), Remplacement::versions( $tome ) );
			yume_assert_same( array( 'identique', 'nouveau' ), array_column( $r['comparaison']['lignes'], 'etat' ) );
			yume_assert_same( array( 'inchange', 'cree' ), array_column( $r['chapitres'], 'action' ) );
			yume_assert_same( array(), $r['disparus'] );
			yume_assert_same( $avant, yume_tpc_photo( $prologue ), 'prologue intact après la préparation' );
			$ch1 = yume_tpc_id( $tome, 'Chapitre 1' );
			yume_assert_same( 'draft', get_post_status( $ch1 ) );
			yume_assert_true( (int) get_post_field( 'menu_order', $ch1 ) > (int) get_post_field( 'menu_order', $prologue ), 'placé après le prologue' );

			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					$sortie = yume_tpc_publier( $tome );
				}
			);
			yume_assert_same( 'publish', get_post_status( $ch1 ) );
			yume_assert_same( array(), $n->tome, 'pas de yume_tome_publie' );
			yume_assert_same( array( array( $ch1, array( $ch1 ) ) ), $n->chap, 'une annonce de chapitre' );
			yume_assert_same( 1, count( $n->discord_chap ) );
			yume_assert_same( $avant, yume_tpc_photo( $prologue ), 'prologue intact après la sortie' );
			yume_assert_same( 'en_cours', yume_parution_tome( $tome ) );
			yume_assert_same( null, $sortie['remplacement_applique'] );
			yume_assert_same( 17, (int) \Yume\Core\Planning\donnees_tome( $tome )['avancement']['edition'], '2 sur 12' );
			yume_assert_same( array( $prologue, $ch1 ), array_map( 'intval', wp_list_pluck( yume_get_chapitres( $tome ), 'ID' ) ), 'ordre de lecture' );
		}
	)
);

yume_test(
	'chapitres : DOCX prologue + chapitre 1 (déjà en ligne, identiques) + chapitre 2 (nouveau) → seul le chapitre 2 est créé',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue', 'ch1' ) );
			$ids    = array_map( 'intval', wp_list_pluck( yume_get_chapitres( $tome ), 'ID' ) );
			yume_assert_same( 2, count( $ids ) );
			$photos = array_map( 'yume_tpc_photo', $ids );
			// Empreinte enregistrée à la création, puis recalculée si le chapitre est retouché.
			yume_assert_true( is_array( get_post_meta( $ids[1], Service::META_EMPREINTE, true ) ), 'empreinte notée' );

			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1', 'ch2' ) );
			yume_assert_same( array( 'identique', 'identique', 'nouveau' ), array_column( $r['comparaison']['lignes'], 'etat' ) );
			yume_assert_same( 1, $r['comparaison']['nouveaux'] );
			yume_assert_same( 3, count( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) ), 'un seul chapitre créé' );
			yume_assert_same( $photos, array_map( 'yume_tpc_photo', $ids ), 'chapitres en ligne intacts' );
			$n   = yume_tpc_compter(
				static function () use ( $tome ) {
					yume_tpc_publier( $tome );
				}
			);
			$ch2 = yume_tpc_id( $tome, 'Chapitre 2' );
			yume_assert_same( array( array( $ch2, array( $ch2 ) ) ), $n->chap );
			yume_assert_same( array_merge( $ids, array( $ch2 ) ), array_map( 'intval', wp_list_pluck( yume_get_chapitres( $tome ), 'ID' ) ) );
			yume_assert_same( $photos, array_map( 'yume_tpc_photo', $ids ) );

			// Un chapitre ancien sans empreinte (ou retouché) : empreinte calculée à la volée.
			delete_post_meta( $ids[1], Service::META_EMPREINTE );
			yume_tpc_requete();
			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'ch1', 'ch2' ) );
			yume_assert_same( array( 'identique', 'identique' ), array_column( $r['comparaison']['lignes'], 'etat' ) );
			wp_update_post(
				array(
					'ID'           => $ids[1],
					'post_content' => '<!-- wp:paragraph --><p>Corrigé dans l’éditeur.</p><!-- /wp:paragraph -->',
				)
			);
			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'ch1' ) );
			yume_assert_same( 'modifie', $r['comparaison']['lignes'][0]['etat'], 'retouche dans l’éditeur détectée' );
			yume_assert_same( 'garder', $r['comparaison']['lignes'][0]['choix'] );
		}
	)
);

yume_test(
	'chapitres : chapitre en ligne modifié — « Garder la version en ligne » (défaut) puis « Mettre à jour (sans annonce) », appliqué en place à la sortie',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue', 'ch1' ) );
			$ch1    = yume_tpc_id( $tome, 'Chapitre 1' );
			$avant  = yume_tpc_photo( $ch1 );

			// 1. Garder (défaut) : rien n'est touché, le chapitre 2 sort.
			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1_modifie', 'ch2' ) );
			yume_assert_same( array( 'identique', 'modifie', 'nouveau' ), array_column( $r['comparaison']['lignes'], 'etat' ) );
			yume_assert_same( 'garder', $r['comparaison']['lignes'][1]['choix'] );
			yume_assert_same( 'chapitre:1#1', $r['comparaison']['lignes'][1]['cle'] );
			yume_assert_same( null, $r['remplacement'] );
			yume_tpc_publier( $tome );
			yume_assert_same( $avant, yume_tpc_photo( $ch1 ), 'version en ligne gardée' );
			yume_assert_same( 'publish', get_post_status( yume_tpc_id( $tome, 'Chapitre 2' ) ) );

			// 2. Mettre à jour : version en attente, rien en ligne avant la sortie.
			yume_tpc_requete();
			$r = yume_tpc_preparer(
				$ctx,
				$oeuvre,
				$tome,
				array( 'prologue', 'ch1_modifie', 'ch2' ),
				array( 'choix' => array( 'chapitre:1#1' => 'maj' ) )
			);
			yume_assert_same( 'maj', $r['comparaison']['lignes'][1]['action'] );
			yume_assert_same( 1, $r['comparaison']['a_mettre_a_jour'] );
			yume_assert_same( Service::MODE_CHAPITRES, Remplacement::lire( $tome )['mode'] ?? '' );
			yume_assert_same( 1, count( Remplacement::versions( $tome ) ), 'une seule version en attente' );
			yume_assert_same( $avant, yume_tpc_photo( $ch1 ), 'rien n’a changé en ligne avant la sortie' );
			yume_assert_contains( '1 chapitre en ligne à mettre à jour à la sortie (sans annonce)', Formulaire::message_brouillon_chapitres( $r ) );
			$_GET = array( 'tome' => (string) $tome ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$html = yume_render_block( 'yume/publish-form' );
			$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			yume_assert_contains( '<div class="yn-publish__attente" data-yn-attente hidden', $html, 'pas d’encadré de remplacement pour une simple mise à jour' );

			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					$sortie = yume_tpc_publier( $tome );
				}
			);
			$apres  = yume_tpc_photo( $ch1 );
			yume_assert_contains( 'lentement', $apres[1], 'texte mis à jour' );
			yume_assert_same( $avant[4], $apres[4], 'date gardée' );
			yume_assert_same( $avant[5], $apres[5], 'adresse gardée' );
			yume_assert_same( $avant[6], $apres[6], 'ordre gardé' );
			yume_assert_same( 'publish', $apres[2] );
			yume_assert_same( array(), $n->chap, 'une mise à jour n’est jamais annoncée' );
			yume_assert_same( array(), $n->tome );
			yume_assert_same( 1, $sortie['remplacement_applique']['remplaces'] );
			yume_assert_same( array(), Remplacement::versions( $tome ) );
			yume_assert_same( null, Remplacement::lire( $tome ) );
			yume_assert_contains( '1 chapitre en ligne mis à jour en place, sans annonce.', Formulaire::message_chapitres( $sortie ) );
			yume_assert_same( 3, count( yume_get_chapitres( $tome ) ), 'aucun doublon' );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Sortie au rythme, à une date
 * -----------------------------------------------------------------------------
 */

yume_test(
	'chapitres : sortie un par un au rythme du tome (après le dernier chapitre programmé), ou tous les N jours sans rythme',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours(
				$ctx,
				$oeuvre,
				array( 'prologue' ),
				array(
					'yume_rythme' => array(
						'jour'  => 'samedi',
						'heure' => '18:00',
					),
				)
			);
			$d1     = yume_prochaine_sortie_rythme( $tome );
			$d2     = $d1->modify( '+7 days' );
			yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1', 'ch2' ) );
			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					$sortie = yume_tpc_publier( $tome, array( 'sortie' => 'rythme' ) );
				}
			);
			$ch1    = yume_tpc_id( $tome, 'Chapitre 1' );
			$ch2    = yume_tpc_id( $tome, 'Chapitre 2' );
			yume_assert_same( 'future', get_post_status( $ch1 ) );
			yume_assert_same( 'future', get_post_status( $ch2 ) );
			yume_assert_same( $d1->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', $ch1 ), 'samedi suivant, 18 h' );
			yume_assert_same( $d2->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', $ch2 ), 'samedi d’après' );
			yume_assert_same( '6', $d1->format( 'N' ) );
			yume_assert_same( '18:00', $d1->format( 'H:i' ) );
			yume_assert_same( array(), array_merge( $n->tome, $n->chap ), 'annoncés un par un à leur sortie (core)' );
			yume_assert_false( metadata_exists( 'post', $ch1, '_yume_publie_notifie' ), 'chapitre non marqué : core l’annoncera' );
			yume_assert_same( 2, count( $sortie['calendrier'] ) );
			yume_assert_same( 'rythme', $sortie['sortie'] );
			yume_assert_contains( 'chaque chapitre sera annoncé à sa sortie', Formulaire::message_chapitres( $sortie ) );

			// Un nouvel ajout se place après le dernier chapitre déjà programmé ; ceux-ci gardent leur date.
			yume_tpc_requete();
			$r = yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'prologue', 'ch1', 'ch2', 'ch3' ) );
			yume_assert_same( array( 'identique', 'programme', 'programme', 'nouveau' ), array_column( $r['comparaison']['lignes'], 'etat' ) );
			yume_assert_same( 1, count( Service::infos_tome( $tome, 1 )['dates_rythme'] ) );
			yume_tpc_publier( $tome, array( 'sortie' => 'rythme' ) );
			$ch3 = yume_tpc_id( $tome, 'Chapitre 3' );
			yume_assert_same( $d2->modify( '+7 days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', $ch3 ) );
			yume_assert_same( $d1->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', $ch1 ), 'date du chapitre 1 gardée' );

			// Sans rythme : départ choisi, puis tous les 3 jours.
			yume_tpc_requete();
			$autre  = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue' ), array( 'yume_numero' => 3 ) );
			$depart = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->setTime( 20, 0 );
			yume_tpc_preparer( $ctx, $oeuvre, $autre, array( 'ch1', 'ch2' ) );
			yume_tpc_publier(
				$autre,
				array(
					'sortie'     => 'rythme',
					'intervalle' => 3,
				),
				$depart->format( 'Y-m-d\TH:i' )
			);
			yume_assert_same( $depart->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', yume_tpc_id( $autre, 'Chapitre 1' ) ) );
			yume_assert_same( $depart->modify( '+3 days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), get_post_field( 'post_date_gmt', yume_tpc_id( $autre, 'Chapitre 2' ) ) );

			// À une date : tous ensemble, date obligatoire.
			yume_tpc_requete();
			yume_tpc_preparer( $ctx, $oeuvre, $autre, array( 'ch3' ) );
			$erreur = Service::publier(
				$autre,
				'maintenant',
				array(
					'mode'   => Service::MODE_CHAPITRES,
					'sortie' => 'date',
				)
			);
			yume_assert_same( 'yume_date_manquante', is_wp_error( $erreur ) ? $erreur->get_error_code() : '' );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Tome complet
 * -----------------------------------------------------------------------------
 */

yume_test(
	'chapitres : « Le tome est complet » → parution complet, liens PDF/EPUB, planning 100 %, annonce de fin (article et Discord)',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue', 'ch1' ), array( 'yume_chapitres_prevus' => 3 ) );
			yume_assert_same( 67, (int) \Yume\Core\Planning\donnees_tome( $tome )['avancement']['traduction'], '2 sur 3' );
			$r = yume_tpc_preparer(
				$ctx,
				$oeuvre,
				$tome,
				array( 'prologue', 'ch1', 'ch2' ),
				array(
					'complet'   => '1',
					'lien_pdf'  => 'https://www.clictune.com/sukamoka2pdf',
					'lien_epub' => 'https://www.clictune.com/sukamoka2epub',
				)
			);
			yume_assert_same( '', (string) get_post_meta( $tome, 'yume_lien_pdf', true ), 'liens posés seulement à la sortie' );
			yume_assert_same( null, $r['article'], 'tome déjà en ligne : pas de nouvel article à la préparation' );

			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					// Complet et liens repris de la préparation.
					$sortie = yume_tpc_publier( $tome );
				}
			);
			$ch2    = yume_tpc_id( $tome, 'Chapitre 2' );
			yume_assert_same( 'fait', $sortie['complet'] );
			yume_assert_same( 'complet', get_post_meta( $tome, 'yume_parution', true ) );
			yume_assert_same( 'complet', yume_parution_tome( $tome ) );
			yume_assert_same( 'https://www.clictune.com/sukamoka2pdf', get_post_meta( $tome, 'yume_lien_pdf', true ) );
			yume_assert_same( 'https://www.clictune.com/sukamoka2epub', get_post_meta( $tome, 'yume_lien_epub', true ) );
			$planning = \Yume\Core\Planning\donnees_tome( $tome );
			yume_assert_same( 'publie', $planning['etape'] );
			yume_assert_same( 100, (int) $planning['avancement']['edition'] );
			yume_assert_same( array( array( $ch2, array( $ch2 ) ) ), $n->chap, 'le dernier chapitre est annoncé' );
			yume_assert_same( array( array( $tome, true ) ), $n->complet, 'yume_tome_complet une fois' );
			yume_assert_same( array(), $n->tome );
			$fin = Annonce::existant( $tome, Annonce::META_COMPLET );
			yume_assert_true( $fin > 0 && Annonce::existant( $tome ) !== $fin, 'second article' );
			yume_assert_same( 'Le tome 2 de SukaMoka est complet : PDF et EPUB disponibles', get_post_field( 'post_title', $fin ) );
			yume_assert_same( 'publish', get_post_status( $fin ) );
			yume_assert_contains( 'https://www.clictune.com/sukamoka2pdf', get_post_field( 'post_content', $fin ) );
			yume_assert_same( 1, count( $n->discord_tome ) );
			yume_assert_contains( 'est complet : PDF et EPUB disponibles', $n->discord_tome[0] );
			yume_assert_contains( 'Le tome est complet', Formulaire::message_chapitres( $sortie ) );
			yume_assert_false( (bool) ( get_post_meta( $tome, Service::META, true )['complet'] ?? false ), 'case consommée' );

			// Marquer complet une seconde fois : rien de plus (ni article, ni événement).
			$n = yume_tpc_compter(
				static function () use ( $tome ) {
					Service::marquer_complet( $tome, array(), true );
				}
			);
			yume_assert_same( array(), $n->complet );
		}
	)
);

yume_test(
	'chapitres : « Le tome est complet » avec une sortie au rythme → passage complet programmé à la sortie du dernier chapitre',
	yume_tpub(
		function ( $ctx ) {
			global $wpdb;
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours(
				$ctx,
				$oeuvre,
				array( 'prologue' ),
				array(
					'yume_rythme' => array(
						'jour'  => 'samedi',
						'heure' => '18:00',
					),
				)
			);
			yume_tpc_preparer( $ctx, $oeuvre, $tome, array( 'ch1', 'ch2' ) );
			$sortie = yume_tpc_publier(
				$tome,
				array(
					'sortie'  => 'rythme',
					'complet' => true,
					'liens'   => array( 'lien_pdf' => 'https://www.clictune.com/t2' ),
				)
			);
			$ch2    = yume_tpc_id( $tome, 'Chapitre 2' );
			$ts     = (int) strtotime( get_post_field( 'post_date_gmt', $ch2 ) . ' UTC' );
			yume_assert_same( 'programme', $sortie['complet'] );
			yume_assert_same( $ts, (int) get_post_meta( $tome, Service::META_COMPLET, true )['ts'] );
			yume_assert_same( $ts, (int) wp_next_scheduled( Service::HOOK_COMPLET, array( $tome ) ) );
			yume_assert_same( 'en_cours', yume_parution_tome( $tome ), 'pas encore complet' );
			yume_assert_same( '', (string) get_post_meta( $tome, 'yume_lien_pdf', true ) );
			yume_assert_contains( 'Le tome passera complet le', Formulaire::message_chapitres( $sortie ) );

			// Les dates passent : la tâche publie les chapitres échus puis marque le tome complet.
			$passe = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
			foreach ( yume_get_chapitres( $tome, array( 'status' => 'future' ) ) as $c ) {
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_date_gmt' => $passe,
						'post_date'     => get_date_from_gmt( $passe ),
					),
					array( 'ID' => $c->ID )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $c->ID );
			}
			yume_tpc_requete();
			$n = yume_tpc_compter(
				static function () use ( $tome ) {
					Service::complet_programme( $tome );
				}
			);
			yume_assert_same( 'publish', get_post_status( $ch2 ) );
			yume_assert_same( 'complet', yume_parution_tome( $tome ) );
			yume_assert_same( 'https://www.clictune.com/t2', get_post_meta( $tome, 'yume_lien_pdf', true ) );
			yume_assert_same( array( array( $tome, true ) ), $n->complet );
			yume_assert_same( '', (string) get_post_meta( $tome, Service::META_COMPLET, true ) );
			yume_assert_false( wp_next_scheduled( Service::HOOK_COMPLET, array( $tome ) ) );
			yume_assert_same( 'Le tome 2 de SukaMoka est complet : PDF disponible', get_post_field( 'post_title', Annonce::existant( $tome, Annonce::META_COMPLET ) ) );
		}
	)
);

yume_test(
	'chapitres : tome complet publié d’un coup (DOCX entier, case cochée) → comportement habituel d’une sortie de tome',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome( $oeuvre );
			$r      = Service::preparer(
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => Service::MODE_CHAPITRES,
					'complet'   => '1',
					'lien_pdf'  => 'https://www.clictune.com/t2pdf',
				),
				array( 'source' => yume_tpub_fichier( $ctx, 'regles.docx' ) )
			);
			yume_assert_false( is_wp_error( $r ) );
			yume_assert_same( 13, $r['comparaison']['nouveaux'] );
			yume_assert_same( 'Le tome 2 de SukaMoka est disponible !', get_post_field( 'post_title', Annonce::existant( $tome ) ), 'modèle habituel' );
			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $tome, &$sortie ) {
					$sortie = yume_tpc_publier( $tome );
				}
			);
			yume_assert_same( 13, count( yume_get_chapitres( $tome ) ) );
			yume_assert_same( 'publish', get_post_status( $tome ) );
			yume_assert_same( 'complet', get_post_meta( $tome, 'yume_parution', true ) );
			yume_assert_same( 'https://www.clictune.com/t2pdf', get_post_meta( $tome, 'yume_lien_pdf', true ) );
			yume_assert_same( array( $tome ), $n->tome, 'yume_tome_publie une fois' );
			yume_assert_same( array(), $n->chap );
			yume_assert_same( array(), $n->complet, 'pas d’annonce « complet » en plus' );
			yume_assert_same( 0, Annonce::existant( $tome, Annonce::META_COMPLET ) );
			yume_assert_same( 'Le tome 2 de SukaMoka est disponible !', get_post_field( 'post_title', Annonce::existant( $tome ) ) );
			yume_assert_contains( 'Au programme', get_post_field( 'post_content', Annonce::existant( $tome ) ) );
			yume_assert_contains( 'Nouvelle sortie : **SukaMoka** — Tome 2 est disponible !', $n->discord_tome[0] ?? '' );
			$planning = \Yume\Core\Planning\donnees_tome( $tome );
			yume_assert_same( 'publie', $planning['etape'] );
			yume_assert_same( 100, (int) $planning['avancement']['edition'] );
			yume_assert_same( 'fait', $sortie['complet'] );
		}
	)
);

yume_test(
	'chapitres : ajout de la lecture en ligne d’un tome migré (complet) → ajout au catalogue sans annonce, comme avant',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre                     = yume_tpub_oeuvre( 'SukaMoka' );
			list( $migre, $sortie_gmt ) = yume_tpub_tome_migre( $oeuvre, 2 );
			yume_assert_same( 'complet', yume_parution_tome( $migre ) );
			yume_assert_true( Service::sans_annonce_par_defaut( $migre ) );
			$r = yume_tpc_preparer( $ctx, $oeuvre, $migre, array( 'prologue', 'ch1' ) );
			yume_assert_true( $r['sans_annonce'], 'ajout au catalogue par défaut' );
			yume_assert_same( null, $r['article'] );
			$sortie = null;
			$n      = yume_tpc_compter(
				function () use ( $migre, &$sortie ) {
					$sortie = yume_tpc_publier( $migre, array( 'sans_annonce' => true ) );
				}
			);
			yume_assert_same( array(), array_merge( $n->tome, $n->chap, $n->complet, $n->discord_tome, $n->discord_chap ), 'rien n’est annoncé' );
			yume_assert_same( 0, Annonce::existant( $migre ), 'aucun article' );
			foreach ( yume_get_chapitres( $migre ) as $c ) {
				yume_assert_same( $sortie_gmt, $c->post_date_gmt, 'daté de la sortie du tome' );
				yume_assert_same( Service::NOTIFIE_CATALOGUE, get_post_meta( $c->ID, '_yume_publie_notifie', true ) );
			}
			yume_assert_same( 2, $sortie['chapitres'] );
			yume_assert_same( 'complet', yume_parution_tome( $migre ), 'toujours complet' );
			yume_assert_contains( 'sans annonce (ni article, ni Discord, ni e-mail)', Formulaire::message_chapitres( $sortie ) );
		}
	)
);

/*
 * -----------------------------------------------------------------------------
 * Formulaire, envoi sans JavaScript, REST
 * -----------------------------------------------------------------------------
 */

yume_test(
	'chapitres : formulaire — tous les tomes de l’œuvre avec leur parution, ?tome présélectionné, « + Nouveau tome », pas de champ nature pour un tome choisi',
	yume_tpub(
		function ( $ctx ) {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			$oeuvre        = yume_tpub_oeuvre( 'SukaMoka' );
			list( $migre ) = yume_tpub_tome_migre( $oeuvre, 1 );
			$en_cours      = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue' ), array( 'yume_chapitres_prevus' => 12 ) );
			$a_paraitre    = yume_tpc_tome( $oeuvre, array(), 3 );
			$rendu         = static function ( array $get ): string {
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				try {
					return yume_render_block( 'yume/publish-form' );
				} finally {
					$_GET = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			};
			$html          = $rendu( array( 'tome' => (string) $en_cours ) );
			yume_assert_contains( 'Ajouter des chapitres à un tome', $html );
			yume_assert_true( (bool) preg_match( '#<option value="' . $en_cours . '"[^>]*data-parution="en_cours"[^>]*selected[^>]*>Tome 2 · en cours de publication · 1 chapitre en ligne</option>#', $html ), 'tome en cours sélectionné' );
			yume_assert_true( (bool) preg_match( '#<option value="' . $migre . '"[^>]*data-catalogue="1"[^>]*>Tome 1 · publié</option>#', $html ), 'tome migré complet' );
			yume_assert_true( (bool) preg_match( '#<option value="' . $a_paraitre . '"[^>]*>Tome 3 · planifié</option>#', $html ), 'tome à paraître' );
			yume_assert_contains( 'data-yn-creation hidden', $html, 'création ici masquée : un tome est choisi' );
			yume_assert_contains( 'href="' . esc_url( Formulaire::url_nouveau_tome( $oeuvre ) ) . '" data-yn-nouveau-tome', $html );
			yume_assert_contains( 'data-yn-fiche-parution>En cours de publication</span>', $html );
			yume_assert_contains( 'Prologue en ligne', $html );
			yume_assert_contains( '1 sur 12', $html );
			yume_assert_true( (bool) preg_match( '#<input id="yn-publish-annoncer" type="checkbox" name="annoncer" value="1"[^>]*checked#', $html ), 'tome en cours : « Annoncer » cochée' );
			yume_assert_contains( 'data-yn-bloc-catalogue hidden', $html );
			yume_assert_not_contains( 'Remplacer la lecture en ligne (', substr( $html, 0, (int) strpos( $html, '</form>' ) ), 'le remplacement complet reste sous le formulaire' );

			// Tome migré : « Ajout au catalogue » cochée d'office.
			$html = $rendu( array( 'tome' => (string) $migre ) );
			yume_assert_true( (bool) preg_match( '#<input id="yn-publish-sans-annonce" type="checkbox" name="sans_annonce" value="1"[^>]*checked#', $html ) );
			yume_assert_contains( 'data-yn-bloc-annoncer hidden', $html );

			// Aucun tome choisi : création ici possible ; l'adresse « + Nouveau tome » se règle par filtre.
			$filtre = static function ( $url, $oeuvre_id ) {
				return 'https://exemple.test/nouveau-tome?oeuvre=' . (int) $oeuvre_id;
			};
			add_filter( 'yume_url_nouveau_tome', $filtre, 10, 2 );
			try {
				$html = $rendu( array( 'oeuvre' => (string) $oeuvre ) );
			} finally {
				remove_filter( 'yume_url_nouveau_tome', $filtre, 10 );
			}
			yume_assert_contains( 'href="https://exemple.test/nouveau-tome?oeuvre=' . $oeuvre . '"', $html );
			yume_assert_not_contains( 'data-yn-creation hidden', $html );
			yume_assert_contains( 'name="nature"', $html );
		}
	)
);

yume_test(
	'chapitres : envoi sans JavaScript — tome choisi jamais renommé, nouveau chapitre publié et annoncé, message',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre   = yume_tpub_oeuvre( 'SukaMoka' );
			$tome     = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue' ) );
			$redirige = static function ( $url ) {
				throw new RuntimeException( 'redirection:' . $url );
			};
			add_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = array(
				'action'        => 'yume_publication',
				'_yume_nonce'   => wp_create_nonce( 'yume_publication' ),
				'etape'         => 'publier',
				'mode'          => Service::MODE_CHAPITRES,
				'oeuvre_id'     => (string) $oeuvre,
				'tome_planning' => (string) $tome,
				'tome_id'       => (string) $tome,
				'nature'        => 'chapitres',
				'numero'        => '9',
				'sortie'        => 'maintenant',
				'annoncer'      => '1',
				'complet'       => '0',
				'intervalle'    => '7',
			);
			$_FILES = array( 'source' => yume_tpc_docx( $ctx, array( 'prologue', 'ch1' ) ) );
			$n      = yume_tpc_compter(
				static function () {
					try {
						Formulaire::traiter();
					} catch ( RuntimeException $e ) {
						yume_assert_contains( 'redirection:', $e->getMessage() );
					}
				}
			);
			remove_filter( 'wp_redirect', $redirige, 1 );
			$_POST  = array();
			$_FILES = array();
			$retour = get_transient( Formulaire::RETOUR . $editeur );
			yume_assert_same( 'succes', $retour['type'] ?? '', $retour['message'] ?? '' );
			yume_assert_contains( 'Tome 2 : 1 chapitre en ligne ; annonce envoyée aux lecteurs qui suivent l’œuvre.', $retour['message'] );
			yume_assert_same( 'tome', get_post_meta( $tome, 'yume_nature', true ) );
			yume_assert_same( 2.0, (float) get_post_meta( $tome, 'yume_numero', true ) );
			$ch1 = yume_tpc_id( $tome, 'Chapitre 1' );
			yume_assert_same( 'publish', get_post_status( $ch1 ) );
			yume_assert_same( array( array( $ch1, array( $ch1 ) ) ), $n->chap );
		}
	)
);

yume_test(
	'chapitres : REST — analyse comparée au tome, création et sortie avec les nouveaux paramètres, validation',
	yume_tpub(
		function ( $ctx ) {
			$editeur = yume_factory_user( 'yume_editeur' );
			wp_set_current_user( $editeur );
			$oeuvre = yume_tpub_oeuvre( 'SukaMoka' );
			$tome   = yume_tpc_tome_en_cours( $ctx, $oeuvre, array( 'prologue', 'ch1' ), array( 'yume_chapitres_prevus' => 5 ) );

			$res = yume_rest( 'POST', '/yume/v1/publications/analyse', array( 'tome_id' => $tome ), $editeur, array( 'source' => yume_tpc_docx( $ctx, array( 'prologue', 'ch1_modifie', 'ch2' ) ) ) );
			yume_assert_same( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
			$data = $res->get_data();
			yume_assert_same( array( 'identique', 'modifie', 'nouveau' ), array_column( $data['comparaison']['lignes'], 'etat' ) );
			yume_assert_same( 'en_cours', $data['tome']['parution'] );
			yume_assert_same( 2, $data['tome']['en_ligne'] );
			yume_assert_same( 5, $data['tome']['prevus'] );
			yume_assert_same( 'Prologue à Chapitre 1', $data['tome']['en_ligne_libelle'] );
			yume_assert_same( 2, count( yume_get_chapitres( $tome, array( 'status' => 'any' ) ) ), 'rien n’est créé par l’analyse' );

			// Validation.
			$res = yume_rest(
				'POST',
				'/yume/v1/publications',
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => 'chapitres',
					'choix'     => array( 'pas une clé' => 'maj' ),
				),
				$editeur
			);
			yume_assert_same( 400, $res->get_status() );
			$res = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome . '/publier',
				array(
					'mode'   => 'chapitres',
					'sortie' => 'hebdomadaire',
				),
				$editeur
			);
			yume_assert_same( 400, $res->get_status(), 'sortie inconnue' );

			// Création (mise à jour choisie) puis sortie.
			$res = yume_rest(
				'POST',
				'/yume/v1/publications',
				array(
					'oeuvre_id' => $oeuvre,
					'tome_id'   => $tome,
					'mode'      => 'chapitres',
					'choix'     => array( 'chapitre:1#1' => 'maj' ),
				),
				$editeur,
				array( 'source' => yume_tpc_docx( $ctx, array( 'prologue', 'ch1_modifie', 'ch2' ) ) )
			);
			yume_assert_same( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
			yume_assert_contains( '1 nouveau chapitre en brouillon', $res->get_data()['message'] );
			$res = yume_rest(
				'POST',
				'/yume/v1/publications/' . $tome . '/publier',
				array(
					'quand'  => 'maintenant',
					'mode'   => 'chapitres',
					'sortie' => 'maintenant',
				),
				$editeur
			);
			yume_assert_same( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
			$data = $res->get_data();
			yume_assert_same( 1, $data['chapitres'] );
			yume_assert_same( 1, $data['remplacement_applique']['remplaces'] );
			yume_assert_false( $data['sans_annonce'], 'tome en cours : annoncé par défaut' );
			yume_assert_contains( 'Tome 2 : 1 chapitre en ligne', $data['message'] );
			yume_assert_contains( 'lentement', get_post_field( 'post_content', yume_tpc_id( $tome, 'Chapitre 1' ) ) );
			yume_assert_same( 3, count( yume_get_chapitres( $tome ) ) );
		}
	)
);
