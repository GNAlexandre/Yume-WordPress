<?php
/**
 * Tests du module mise-en-page (2.9.0, lot E de docs/10-mise-en-page-chapitre.md) :
 *
 * - découpage en unités (récit, dialogue, pensée, centré, séparateur, intertitre, blocs « autre »)
 *   et reconstruction à l'identique (aller-retour sans modification = contenu identique) ;
 * - changement de style (tiret de dialogue posé / retiré), typographie, nettoyage du HTML
 *   (script, style en ligne, balises interdites, commentaires refusés) ;
 * - blocs « autre » intouchables, unités invalides (400), scinder / fusionner / séparateur ;
 * - enregistrement : 409 de conflit, révision créée, statut, date et métas inchangés, mots
 *   recalculés, aucune annonce (événements, courriels, requêtes HTTP), empreinte de comparaison,
 *   journal privé ;
 * - droits (lecteur, traducteur, éditeur, gérant, filtre), verrou souple, vue et points d'entrée.
 *
 * Les rappels REST sont appelés directement (une requête REST déclencherait rest_api_init pour
 * les fichiers de test suivants, voir docs/guide-developpeur.md §3).
 *
 * Lancement : tools/localenv/test.sh mise-en-page
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Texte;
use Yume\Core\MiseEnPage\Unites;
use Yume\Core\Publication\Service;

use function Yume\Core\MiseEnPage\enregistrer;
use function Yume\Core\MiseEnPage\etat;
use function Yume\Core\MiseEnPage\peut;
use function Yume\Core\MiseEnPage\permission;
use function Yume\Core\MiseEnPage\presence;
use function Yume\Core\MiseEnPage\rendu_vue;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tmep_)
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( 'yume_tmep_contenu' ) ) {

	/**
	 * Contenu de référence : récit, dialogue, pensée, centré, séparateur, intertitre, image,
	 * paragraphe avec appel de note, notes.
	 */
	function yume_tmep_contenu(): string {
		return Blocks::assembler(
			array(
				Blocks::paragraphe( 'Le vent du col charriait une odeur de pierre mouillée.' ),
				Blocks::paragraphe( Texte::TIRET_DIALOGUE . 'Encore trois heures, souffla Doran.', array( 'yn-dialogue' ) ),
				Blocks::paragraphe( 'Je ne <em>dois</em> pas céder.', array( 'yn-thought' ) ),
				Blocks::paragraphe( '10 septembre, soir', array( 'yn-center' ), true ),
				Blocks::separateur(),
				Blocks::titre( 'Le col', 3 ),
				Blocks::image( 0, 'https://example.test/carte.png', 'Carte du col' ),
				Blocks::paragraphe( 'Elle sourit' . Blocks::appel_note( 1 ) . ' et repartit.' ),
				Blocks::notes( array( 1 => 'Note du traducteur.' ) ),
			)
		);
	}

	/**
	 * Unités publiques d'un contenu, telles que la vue les renvoie.
	 *
	 * @param string $contenu Contenu.
	 * @return array<int,array<string,mixed>>
	 */
	function yume_tmep_unites( string $contenu ): array {
		return Unites::publiques( Unites::decouper( $contenu ) );
	}

	/**
	 * Œuvre, tome et chapitre publiés (contenu de référence par défaut).
	 *
	 * @param string $contenu Contenu du chapitre.
	 * @param string $statut  Statut du chapitre.
	 * @return array{oeuvre:int,tome:int,chapitre:int}
	 */
	function yume_tmep_contexte( string $contenu = '', string $statut = 'publish' ): array {
		$oeuvre   = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Journal de garde',
			)
		);
		$tome     = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'post_title' => 'Journal de garde — Tome 2',
				'post_date'  => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				'meta_input' => array(
					'yume_oeuvre_id' => $oeuvre,
					'yume_numero'    => 2,
					'yume_nature'    => 'tome',
				),
			)
		);
		$chapitre = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_status'  => $statut,
				'post_title'   => 'Chapitre 2, partie 1 — 10 septembre, soir',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
				'post_content' => '' !== $contenu ? $contenu : yume_tmep_contenu(),
				'meta_input'   => array(
					'yume_tome_id'     => $tome,
					'yume_oeuvre_id'   => $oeuvre,
					'yume_numero'      => 2,
					'yume_sous_numero' => 1,
					'yume_nature'      => 'sous-chapitre',
					'yume_sous_titre'  => '10 septembre, soir',
				),
			)
		);
		return array(
			'oeuvre'   => $oeuvre,
			'tome'     => $tome,
			'chapitre' => $chapitre,
		);
	}

	/**
	 * Envoi des unités modifiées par un rappel (comme la vue : index, type, style, html, niveau).
	 *
	 * @param string   $contenu Contenu d'origine.
	 * @param callable $modif   function( array $unites ): array.
	 * @return array<int,array<string,mixed>>
	 */
	function yume_tmep_envoi( string $contenu, callable $modif ): array {
		$unites = array_map(
			static function ( array $u ): array {
				unset( $u['apercu'], $u['rendu'] );
				return $u;
			},
			yume_tmep_unites( $contenu )
		);
		return array_values( $modif( $unites ) );
	}

	/**
	 * Paragraphe sérialisé de rang $n (0…) d'un contenu (blocs de premier niveau non vides).
	 *
	 * @param string $contenu Contenu.
	 * @param int    $n       Rang.
	 */
	function yume_tmep_bloc( string $contenu, int $n ): string {
		$blocs = array_values(
			array_filter(
				parse_blocks( $contenu ),
				static fn( array $b ): bool => null !== $b['blockName']
			)
		);
		return isset( $blocs[ $n ] ) ? serialize_block( $blocs[ $n ] ) : '';
	}

	/**
	 * Compte les signes d'une annonce pendant $corps : événements de sortie, courriels, requêtes
	 * HTTP (toutes refusées).
	 *
	 * @param callable $corps Corps.
	 * @return array{evenements:int,mails:int,http:int}
	 */
	function yume_tmep_sans_annonce( callable $corps ): array {
		$compte = array(
			'evenements' => 0,
			'mails'      => 0,
			'http'       => 0,
		);
		$evt    = static function () use ( &$compte ): void {
			++$compte['evenements'];
		};
		$mail   = static function () use ( &$compte ) {
			++$compte['mails'];
			return false;
		};
		$http   = static function () use ( &$compte ) {
			++$compte['http'];
			return new WP_Error( 'yume_test', 'Aucune requête réseau dans les tests.' );
		};
		$hooks  = array( 'yume_chapitre_publie', 'yume_tome_publie', 'yume_tome_complet', 'yume_publication_preparee' );
		foreach ( $hooks as $hook ) {
			add_action( $hook, $evt, 1 );
		}
		add_filter( 'pre_wp_mail', $mail, 1 );
		add_filter( 'pre_http_request', $http, 1 );
		try {
			$corps();
		} finally {
			foreach ( $hooks as $hook ) {
				remove_action( $hook, $evt, 1 );
			}
			remove_filter( 'pre_wp_mail', $mail, 1 );
			remove_filter( 'pre_http_request', $http, 1 );
		}
		return $compte;
	}

	/**
	 * Déclare un test : compte courant et $_GET remis en place, transients de présence effacés.
	 *
	 * @param string   $nom   Nom.
	 * @param callable $corps Corps.
	 */
	function yume_tmep_test( string $nom, callable $corps ): void {
		yume_test(
			$nom,
			static function () use ( $corps ) {
				\Yume\Core\Core\installer_roles();
				$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				try {
					$corps();
				} finally {
					$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					wp_set_current_user( 0 );
					wp_dequeue_script( 'yume-mise-en-page' );
					wp_dequeue_style( 'yume-mise-en-page' );
				}
			}
		);
	}
}

/*
 * -----------------------------------------------------------------------------
 * Unités : découpage et reconstruction
 * -----------------------------------------------------------------------------
 */

yume_tmep_test(
	'Découpage : types, styles, tiret de dialogue retiré du texte, blocs « autre »',
	static function () {
		$u = yume_tmep_unites( yume_tmep_contenu() );
		yume_assert_same( 9, count( $u ), 'neuf unités' );
		yume_assert_same( array( 'paragraphe', 'paragraphe', 'paragraphe', 'paragraphe', 'separateur', 'intertitre', 'autre', 'paragraphe', 'autre' ), array_column( $u, 'type' ) );
		yume_assert_same( 'recit', $u[0]['style'] );
		yume_assert_same( 'dialogue', $u[1]['style'] );
		yume_assert_same( 'Encore trois heures, souffla Doran.', $u[1]['html'], 'tiret retiré du texte d’un dialogue' );
		yume_assert_same( 'pensee', $u[2]['style'] );
		yume_assert_same( 'Je ne <em>dois</em> pas céder.', $u[2]['html'] );
		yume_assert_same( 'centre', $u[3]['style'] );
		yume_assert_same( 3, $u[5]['niveau'] );
		yume_assert_same( 'Le col', $u[5]['html'] );
		yume_assert_same( 'Image : Carte du col', $u[6]['apercu'] );
		yume_assert_contains( 'carte.png', $u[6]['rendu'] );
		yume_assert_contains( '<sup class="yn-note"><a href="#yn-note-1" id="yn-ref-1">1</a></sup>', $u[7]['html'], 'appel de note gardé' );
		yume_assert_same( 'Notes (1)', $u[8]['apercu'] );
		yume_assert_same( range( 0, 8 ), array_column( $u, 'index' ) );
		yume_assert_false( isset( $u[0]['bloc'] ), 'données internes absentes de la vue' );
	}
);

yume_tmep_test(
	'Aller-retour sans modification : contenu identique, aucun paragraphe modifié',
	static function () {
		$contenu = yume_tmep_contenu();
		$r       = Unites::appliquer( $contenu, yume_tmep_envoi( $contenu, static fn( array $u ): array => $u ) );
		yume_assert_false( is_wp_error( $r ) );
		yume_assert_same( $contenu, $r['contenu'], 'contenu reconstruit à l’identique' );
		yume_assert_same( 0, $r['modifies'] );

		// Contenu de l'éditeur (paragraphes à attributs inconnus) : blocs « autre », recopiés.
		$editeur = "<!-- wp:paragraph {\"fontSize\":\"grand\"} -->\n<p class=\"has-grand-font-size\">Gros texte</p>\n<!-- /wp:paragraph -->\n\n" . Blocks::paragraphe( 'Suite.' );
		$u       = yume_tmep_unites( $editeur );
		yume_assert_same( 'autre', $u[0]['type'], 'paragraphe mis en forme dans l’éditeur : intouchable' );
		$r = Unites::appliquer( $editeur, yume_tmep_envoi( $editeur, static fn( array $u ): array => $u ) );
		yume_assert_same( $editeur, $r['contenu'] );
	}
);

yume_tmep_test(
	'Changement de style : tiret posé à l’entrée en dialogue, retiré à la sortie, classes et centrage',
	static function () {
		$contenu = yume_tmep_contenu();
		$envoi   = yume_tmep_envoi(
			$contenu,
			static function ( array $u ): array {
				$u[0]['style'] = 'dialogue'; // Récit → Dialogue.
				$u[1]['style'] = 'recit';    // Dialogue → Récit.
				$u[2]['style'] = 'centre';   // Pensée → Centré.
				$u[3]['style'] = 'pensee';   // Centré → Pensée.
				return $u;
			}
		);
		$r       = Unites::appliquer( $contenu, $envoi );
		yume_assert_same( 4, $r['modifies'] );
		yume_assert_same( Blocks::paragraphe( Texte::TIRET_DIALOGUE . 'Le vent du col charriait une odeur de pierre mouillée.', array( 'yn-dialogue' ) ), yume_tmep_bloc( $r['contenu'], 0 ) );
		yume_assert_same( Blocks::paragraphe( 'Encore trois heures, souffla Doran.' ), yume_tmep_bloc( $r['contenu'], 1 ), 'tiret retiré' );
		yume_assert_same( Blocks::paragraphe( 'Je ne <em>dois</em> pas céder.', array( 'yn-center' ), true ), yume_tmep_bloc( $r['contenu'], 2 ) );
		yume_assert_same( Blocks::paragraphe( '10 septembre, soir', array( 'yn-thought' ) ), yume_tmep_bloc( $r['contenu'], 3 ) );
		// Les blocs non touchés sont recopiés à l'identique.
		yume_assert_same( yume_tmep_bloc( $contenu, 6 ), yume_tmep_bloc( $r['contenu'], 6 ) );

		// Tiret tapé dans un récit, passé en dialogue : un seul tiret normalisé.
		$tape = Blocks::paragraphe( '- Viens, dit-elle.' );
		$r    = Unites::appliquer(
			$tape,
			yume_tmep_envoi(
				$tape,
				static function ( array $u ): array {
					$u[0]['style'] = 'dialogue';
					return $u;
				}
			)
		);
		yume_assert_same( Blocks::paragraphe( Texte::TIRET_DIALOGUE . 'Viens, dit-elle.', array( 'yn-dialogue' ) ), $r['contenu'] );

		// Classe supplémentaire gardée.
		$extra = Blocks::paragraphe( 'Lettre.', array( 'yn-lettre' ) );
		$r     = Unites::appliquer(
			$extra,
			yume_tmep_envoi(
				$extra,
				static function ( array $u ): array {
					$u[0]['style'] = 'pensee';
					return $u;
				}
			)
		);
		yume_assert_same( Blocks::paragraphe( 'Lettre.', array( 'yn-thought', 'yn-lettre' ) ), $r['contenu'] );

		// Style inconnu : 400.
		$r = Unites::appliquer(
			$extra,
			yume_tmep_envoi(
				$extra,
				static function ( array $u ): array {
					$u[0]['style'] = 'gras';
					return $u;
				}
			)
		);
		yume_assert_true( is_wp_error( $r ) && 400 === $r->get_error_data()['status'] );
	}
);

yume_tmep_test(
	'Typographie d’un paragraphe modifié : insécables, guillemets, apostrophe',
	static function () {
		$contenu = Blocks::paragraphe( 'Avant.' );
		$r       = Unites::appliquer(
			$contenu,
			yume_tmep_envoi(
				$contenu,
				static function ( array $u ): array {
					$u[0]['html'] = "Il dit : « C'est <em>toi</em> ? » Oui !";
					return $u;
				}
			)
		);
		$attendu = 'Il dit' . Texte::INSECABLE . ': «' . Texte::INSECABLE . 'C’est <em>toi</em>' . Texte::INSECABLE . '?' . Texte::INSECABLE . '» Oui' . Texte::INSECABLE . '!';
		yume_assert_same( Blocks::paragraphe( $attendu ), $r['contenu'] );
		yume_assert_same( 1, $r['modifies'] );
	}
);

yume_tmep_test(
	'Nettoyage du HTML : script, style en ligne, balises et liens interdits ; commentaires refusés',
	static function () {
		yume_assert_same( 'Bonjour toi', Unites::nettoyer( 'Bonjour <script>alert(1)</script> toi' ), 'script retiré avec son contenu' );
		yume_assert_same( '<strong>Gras</strong> et <em>penché</em>', Unites::nettoyer( '<b style="color:red">Gras</b> et <i class="x">penché</i>' ) );
		yume_assert_same( 'Texte <em>rouge</em>', Unites::nettoyer( '<span style="color:red" onclick="x()">Texte</span> <em style="font-size:40px">rouge</em>' ) );
		yume_assert_same( 'clic', Unites::nettoyer( '<a href="javascript:alert(1)">clic</a>' ) );
		yume_assert_same( '<a href="https://example.test/">lien</a>', Unites::nettoyer( '<a href="https://example.test/" onclick="x" target="_blank">lien</a>' ) );
		yume_assert_same( '<sup class="yn-note"><a href="#yn-note-2" id="yn-ref-2">2</a></sup><sup>er</sup>', Unites::nettoyer( '<sup class="yn-note"><a href="#yn-note-2" id="yn-ref-2">2</a></sup><sup class="autre">er</sup>' ) );
		yume_assert_same( 'Titre image', Unites::nettoyer( '<h2>Titre</h2> <img src="x" onerror="alert(1)">image' ) );
		yume_assert_not_contains( '<style', Unites::nettoyer( '<style>p{}</style>Texte' ) );
		yume_assert_same( 'ligne<br />suite', Unites::nettoyer( "ligne <br>\nsuite" ) );

		$contenu = Blocks::paragraphe( 'Avant.' );
		$r       = Unites::appliquer(
			$contenu,
			yume_tmep_envoi(
				$contenu,
				static function ( array $u ): array {
					$u[0]['html'] = 'Texte <!-- /wp:paragraph --><!-- wp:html --><script>alert(1)</script>';
					return $u;
				}
			)
		);
		yume_assert_true( is_wp_error( $r ) && 'yume_mise_en_page_unite' === $r->get_error_code(), 'commentaire HTML (délimiteur de bloc) refusé' );

		$r = Unites::appliquer(
			$contenu,
			yume_tmep_envoi(
				$contenu,
				static function ( array $u ): array {
					$u[0]['html'] = '<p onclick="x">Texte</p><div style="color:red">sale</div><iframe src="https://example.test"></iframe>';
					return $u;
				}
			)
		);
		yume_assert_same( Blocks::paragraphe( 'Textesale' ), $r['contenu'], 'balises de bloc retirées, texte gardé' );
	}
);

yume_tmep_test(
	'Blocs « autre » intouchables et unités invalides : 400',
	static function () {
		$contenu = yume_tmep_contenu();
		$cas     = array(
			'type changé pour une image'  => static function ( array $u ): array {
				$u[6]['type']  = 'paragraphe';
				$u[6]['style'] = 'recit';
				$u[6]['html']  = 'Plus une image';
				return $u;
			},
			'image supprimée'             => static function ( array $u ): array {
				unset( $u[6] );
				return $u;
			},
			'images et notes inversées'   => static function ( array $u ): array {
				$image = $u[6];
				$u[6]  = $u[8];
				$u[8]  = $image;
				return $u;
			},
			'index inconnu'               => static function ( array $u ): array {
				$u[0]['index'] = 99;
				return $u;
			},
			'index répété'                => static function ( array $u ): array {
				$u[1]['index'] = 0;
				return $u;
			},
			'nouvelle unité « autre »'    => static function ( array $u ): array {
				$u[] = array(
					'index' => null,
					'type'  => 'autre',
				);
				return $u;
			},
			'paragraphe devenu « autre »' => static function ( array $u ): array {
				$u[0]['type'] = 'autre';
				return $u;
			},
			'HTML non textuel'            => static function ( array $u ): array {
				$u[0]['html'] = array( 'x' );
				return $u;
			},
		);
		foreach ( $cas as $nom => $modif ) {
			$r = Unites::appliquer( $contenu, yume_tmep_envoi( $contenu, $modif ) );
			yume_assert_true( is_wp_error( $r ) && 400 === ( $r->get_error_data()['status'] ?? 0 ), $nom );
		}
		yume_assert_true( is_wp_error( Unites::appliquer( $contenu, 'pas une liste' ) ) );
		yume_assert_true( is_wp_error( Unites::appliquer( $contenu, array( 'a' => array() ) ) ) );
	}
);

yume_tmep_test(
	'Scinder, fusionner, insérer un séparateur, intertitre : contenu et décompte',
	static function () {
		$contenu = Blocks::assembler(
			array(
				Blocks::paragraphe( 'Premier. Second.' ),
				Blocks::paragraphe( 'Troisième' ),
				Blocks::paragraphe( 'quatrième.' ),
				Blocks::paragraphe( 'Titre caché' ),
			)
		);
		$r       = Unites::appliquer(
			$contenu,
			yume_tmep_envoi(
				$contenu,
				static function ( array $u ): array {
					$u[0]['html'] = 'Premier.';
					$nouveau      = array(
						'index' => null,
						'type'  => 'paragraphe',
						'style' => 'recit',
						'html'  => 'Second.',
					);
					$u[1]['html'] = 'Troisième quatrième.';
					$titre        = array(
						'index'  => 3,
						'type'   => 'intertitre',
						'niveau' => 3,
						'html'   => 'Titre caché',
					);
					$sep          = array(
						'index' => null,
						'type'  => 'separateur',
					);
					return array( $u[0], $nouveau, $sep, $u[1], $titre );
				}
			)
		);
		yume_assert_same(
			Blocks::assembler(
				array(
					Blocks::paragraphe( 'Premier.' ),
					Blocks::paragraphe( 'Second.' ),
					Blocks::separateur(),
					Blocks::paragraphe( 'Troisième quatrième.' ),
					Blocks::titre( 'Titre caché', 3 ),
				)
			),
			$r['contenu']
		);
		// Scindé (2 : origine modifiée + nouvelle), séparateur (1), fusion (modifié 1 + supprimé 1), intertitre (1).
		yume_assert_same( 6, $r['modifies'] );
	}
);

yume_tmep_test(
	'Paragraphes ajoutés, supprimés, saut de ligne et paragraphes vides retirés',
	static function () {
		$contenu = Blocks::assembler(
			array(
				Blocks::paragraphe( Texte::TIRET_DIALOGUE . 'Tu viens ?', array( 'yn-dialogue' ) ),
				Blocks::paragraphe( 'À supprimer.' ),
				Blocks::titre( 'Titre', 3 ),
				Blocks::paragraphe( '' ),
			)
		);
		$r       = Unites::appliquer(
			$contenu,
			yume_tmep_envoi(
				$contenu,
				static function ( array $u ): array {
					// Entrée en fin de dialogue : nouveau dialogue tapé ; nouveau paragraphe resté vide ;
					// paragraphe supprimé ; intertitre vidé ; paragraphe vide d'origine gardé tel quel.
					$tape          = array(
						'index' => null,
						'type'  => 'paragraphe',
						'style' => 'dialogue',
						'html'  => 'Oui, j’arrive.<br>Attends-moi.',
					);
					$vide          = array(
						'index' => null,
						'type'  => 'paragraphe',
						'style' => 'recit',
						'html'  => ' <em> </em> ',
					);
					$titre         = $u[2];
					$titre['html'] = '';
					return array( $u[0], $tape, $vide, $titre, $u[3] );
				}
			)
		);
		yume_assert_same(
			Blocks::assembler(
				array(
					yume_tmep_bloc( $contenu, 0 ),
					Blocks::paragraphe( Texte::TIRET_DIALOGUE . 'Oui, j’arrive.<br />Attends-moi.', array( 'yn-dialogue' ) ),
					Blocks::paragraphe( '' ),
				)
			),
			$r['contenu'],
			'dialogue ajouté (tiret posé, saut de ligne), vides retirés, paragraphe vide d’origine gardé'
		);
		// Nouveau dialogue (1) + supprimé (1) + intertitre vidé puis retiré (1) ; le vide nouveau ne compte pas.
		yume_assert_same( 3, $r['modifies'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Enregistrement
 * -----------------------------------------------------------------------------
 */

yume_tmep_test(
	'Enregistrement : révision, statut / date / métas inchangés, mots recalculés, journal privé, sans annonce',
	static function () {
		$ctx     = yume_tmep_contexte();
		$id      = $ctx['chapitre'];
		$editeur = yume_factory_user( 'yume_editeur' );
		wp_set_current_user( $editeur );
		$avant = get_post( $id );
		$metas = array(
			'yume_tome_id'     => get_post_meta( $id, 'yume_tome_id', true ),
			'yume_numero'      => get_post_meta( $id, 'yume_numero', true ),
			'yume_sous_numero' => get_post_meta( $id, 'yume_sous_numero', true ),
			'yume_nature'      => get_post_meta( $id, 'yume_nature', true ),
			'_yume_publie'     => get_post_meta( $id, '_yume_publie_notifie', true ),
		);
		$mots  = (int) get_post_meta( $id, 'yume_nb_mots', true );
		yume_assert_true( $mots > 0, 'mots calculés à la création' );
		$envoi  = yume_tmep_envoi(
			$avant->post_content,
			static function ( array $u ): array {
				$u[0]['style'] = 'dialogue';
				$u[7]['html']  = 'Elle sourit<sup class="yn-note"><a href="#yn-note-1" id="yn-ref-1">1</a></sup> longuement, puis elle repartit vers le col.';
				return $u;
			}
		);
		$r      = null;
		$signes = yume_tmep_sans_annonce(
			static function () use ( &$r, $id, $avant, $envoi, $editeur ) {
				$r = enregistrer( $id, $avant->post_modified_gmt, md5( $avant->post_content ), $envoi, $editeur );
			}
		);
		yume_assert_false( is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
		yume_assert_true( $r['enregistre'] );
		yume_assert_same( 2, $r['modifies'] );
		yume_assert_same( 'Mise en page enregistrée : 2 paragraphes modifiés.', $r['message'] );
		yume_assert_same(
			array(
				'evenements' => 0,
				'mails'      => 0,
				'http'       => 0,
			),
			$signes,
			'aucune annonce, aucun courriel, aucune requête'
		);

		clean_post_cache( $id );
		$apres = get_post( $id );
		yume_assert_same( $avant->post_status, $apres->post_status, 'statut inchangé' );
		yume_assert_same( $avant->post_date_gmt, $apres->post_date_gmt, 'date inchangée' );
		yume_assert_same( $avant->post_title, $apres->post_title );
		foreach ( $metas as $cle => $valeur ) {
			yume_assert_same( $valeur, '_yume_publie' === $cle ? get_post_meta( $id, '_yume_publie_notifie', true ) : get_post_meta( $id, $cle, true ), 'méta ' . $cle . ' inchangée' );
		}
		yume_assert_contains( '<!-- wp:paragraph {"className":"yn-dialogue"} -->', $apres->post_content, 'blocs gardés malgré kses (éditeur)' );
		yume_assert_contains( 'class="yn-dialogue">' . Texte::TIRET_DIALOGUE . 'Le vent', $apres->post_content );
		yume_assert_contains( 'carte.png', $apres->post_content, 'image recopiée' );
		yume_assert_true( (int) get_post_meta( $id, 'yume_nb_mots', true ) > $mots, 'nombre de mots recalculé' );
		yume_assert_same( $editeur, (int) get_post_meta( $id, '_edit_last', true ) );

		$revisions = wp_get_post_revisions( $id );
		yume_assert_same( 2, count( $revisions ), 'version d’origine et nouvelle version dans l’historique' );
		// La version d'origine (premier paragraphe en récit) est dans l'historique ; kses peut avoir
		// normalisé le balisage (« <hr … /> »), d'où une comparaison sur le texte.
		$origine = array_filter( $revisions, static fn( WP_Post $p ): bool => str_contains( $p->post_content, '<p>Le vent du col charriait' ) && str_contains( $p->post_content, 'carte.png' ) );
		yume_assert_same( 1, count( $origine ), 'version d’origine gardée' );

		// Empreinte : le prochain dépôt du même Word signale « En ligne, modifié ».
		yume_assert_true( Service::empreinte_chapitre( $apres ) !== Service::empreinte_texte( $avant->post_title, $avant->post_content ) );

		// Journal du planning : ligne privée.
		global $wpdb;
		$ligne = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . \Yume\Core\Planning\table_journal() . ' WHERE tome_id = %d AND champ = %s', $ctx['tome'], 'mise_en_page' ) ); // phpcs:ignore WordPress.DB
		yume_assert_true( null !== $ligne, 'ligne de journal écrite' );
		yume_assert_same( '0', (string) $ligne->public, 'ligne jamais publique' );
		yume_assert_same( 'Chapitre 2, partie 1 — 10 septembre, soir, mise en page corrigée : 2 paragraphes modifiés', \Yume\Core\Planning\texte_changement( $ligne, true ) );
		yume_assert_same( '', \Yume\Core\Planning\texte_changement( $ligne, false ), 'rien au public' );

		// Second enregistrement sans changement : rien n'est écrit.
		$n = count( wp_get_post_revisions( $id ) );
		$r = enregistrer( $id, $apres->post_modified_gmt, '', yume_tmep_envoi( $apres->post_content, static fn( array $u ): array => $u ), $editeur );
		yume_assert_false( $r['enregistre'] );
		yume_assert_same( $n, count( wp_get_post_revisions( $id ) ), 'aucune révision sans changement' );
	}
);

yume_tmep_test(
	'Chapitre brouillon et programmé : statut et date gardés, aucun événement',
	static function () {
		foreach ( array( 'draft', 'future' ) as $statut ) {
			$ctx = yume_tmep_contexte( '', $statut );
			$id  = $ctx['chapitre'];
			if ( 'future' === $statut ) {
				wp_update_post(
					array(
						'ID'            => $id,
						'post_status'   => 'future',
						'post_date'     => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
						'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
					)
				);
			}
			$avant  = get_post( $id );
			$envoi  = yume_tmep_envoi(
				$avant->post_content,
				static function ( array $u ): array {
					$u[2]['style'] = 'recit';
					return $u;
				}
			);
			$signes = yume_tmep_sans_annonce(
				static function () use ( $id, $avant, $envoi ) {
					enregistrer( $id, $avant->post_modified_gmt, '', $envoi, 1 );
				}
			);
			clean_post_cache( $id );
			$apres = get_post( $id );
			yume_assert_same( $statut, $apres->post_status, $statut . ' gardé' );
			yume_assert_same( $avant->post_date_gmt, $apres->post_date_gmt, $statut . ' : date gardée' );
			yume_assert_same( 0, $signes['evenements'] + $signes['mails'] + $signes['http'], $statut . ' : aucune annonce' );
			yume_assert_not_contains( 'yn-thought', $apres->post_content );
		}
	}
);

yume_tmep_test(
	'Conflit : 409 si le chapitre a changé depuis la base, avec l’état courant',
	static function () {
		$ctx    = yume_tmep_contexte();
		$id     = $ctx['chapitre'];
		$gerant = yume_factory_user( 'yume_gerant' );
		$autre  = yume_factory_user( 'yume_editeur' );
		wp_update_user(
			array(
				'ID'           => $autre,
				'display_name' => 'Alix',
			)
		);
		update_post_meta( $id, '_edit_last', $autre );
		$avant = get_post( $id );
		$envoi = yume_tmep_envoi( $avant->post_content, static fn( array $u ): array => $u );

		$r = enregistrer( $id, '2001-01-01 00:00:00', '', $envoi, $gerant );
		yume_assert_true( is_wp_error( $r ) && 'yume_mise_en_page_conflit' === $r->get_error_code() );
		$donnees = $r->get_error_data();
		yume_assert_same( 409, $donnees['status'] );
		yume_assert_same( 'Alix', $donnees['par'] );
		yume_assert_contains( 'Quelqu’un a enregistré ce chapitre entre-temps (Alix, ', $r->get_error_message() );
		yume_assert_contains( 'rechargez pour voir sa version', $r->get_error_message() );
		yume_assert_same( $avant->post_modified_gmt, $donnees['etat']['chapitre']['modifie'], 'état courant renvoyé' );
		yume_assert_same( 9, count( $donnees['etat']['unites'] ) );

		// Même seconde, contenu différent : l'empreinte détecte le conflit.
		$r = enregistrer( $id, $avant->post_modified_gmt, md5( 'autre contenu' ), $envoi, $gerant );
		yume_assert_true( is_wp_error( $r ) && 409 === $r->get_error_data()['status'] );

		// Unité invalide : 400 et rien n'est écrit.
		$r = enregistrer( $id, $avant->post_modified_gmt, '', array( array( 'index' => 50, 'type' => 'paragraphe' ) ), $gerant ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		yume_assert_true( is_wp_error( $r ) && 400 === $r->get_error_data()['status'] );
		clean_post_cache( $id );
		yume_assert_same( $avant->post_content, get_post( $id )->post_content );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Droits, présence, vue
 * -----------------------------------------------------------------------------
 */

yume_tmep_test(
	'Droits : lecteur et traducteur refusés, éditeur et gérant autorisés, filtre yume_mise_en_page_peut',
	static function () {
		$ctx = yume_tmep_contexte();
		$id  = $ctx['chapitre'];
		$u   = array();
		foreach ( array( 'subscriber', 'yume_traducteur', 'yume_editeur', 'yume_gerant', 'administrator' ) as $role ) {
			$u[ $role ] = yume_factory_user( $role );
		}
		yume_assert_false( peut( $id, 0 ), 'anonyme' );
		yume_assert_false( peut( $id, $u['subscriber'] ), 'lecteur' );
		yume_assert_false( peut( $id, $u['yume_traducteur'] ), 'traducteur' );
		yume_assert_true( peut( $id, $u['yume_editeur'] ), 'éditeur' );
		yume_assert_true( peut( $id, $u['yume_gerant'] ), 'gérant' );
		yume_assert_true( peut( $id, $u['administrator'] ), 'administrateur' );
		yume_assert_true( yume_mise_en_page_peut( $id, $u['yume_editeur'] ) );

		$ouvrir = static fn( bool $peut, int $chapitre_id, int $user_id ): bool => $peut || $user_id === $u['yume_traducteur'];
		add_filter( 'yume_mise_en_page_peut', $ouvrir, 10, 3 );
		try {
			yume_assert_true( peut( $id, $u['yume_traducteur'] ), 'filtre : traducteur autorisé' );
			yume_assert_false( peut( $id, $u['subscriber'] ) );
		} finally {
			remove_filter( 'yume_mise_en_page_peut', $ouvrir, 10 );
		}

		$requete = new WP_REST_Request( 'GET', '/yume/v1/chapitres/' . $id . '/mise-en-page' );
		$requete->set_url_params( array( 'id' => $id ) );
		wp_set_current_user( 0 );
		yume_assert_same( 401, permission( $requete )->get_error_data()['status'] );
		wp_set_current_user( $u['yume_traducteur'] );
		yume_assert_same( 403, permission( $requete )->get_error_data()['status'] );
		wp_set_current_user( $u['yume_editeur'] );
		yume_assert_true( true === permission( $requete ) );

		yume_assert_same( '', yume_mise_en_page_url( $ctx['tome'] ), 'pas d’adresse pour un tome' );
		yume_assert_contains( 'vue=chapitre', yume_mise_en_page_url( $id ) );
		yume_assert_contains( 'id=' . $id, yume_mise_en_page_url( $id ) );
	}
);

yume_tmep_test(
	'Verrou souple : un autre membre est signalé pendant 10 minutes, sans écraser sa présence',
	static function () {
		$ctx = yume_tmep_contexte();
		$id  = $ctx['chapitre'];
		$a   = yume_factory_user( 'yume_editeur' );
		$b   = yume_factory_user( 'yume_gerant' );
		wp_update_user(
			array(
				'ID'           => $a,
				'display_name' => 'Camille',
			)
		);
		delete_transient( 'yume_mep_' . $id );
		try {
			yume_assert_same( null, presence( $id, $a ), 'premier membre : personne d’autre' );
			$p = presence( $id, $b );
			yume_assert_true( is_array( $p ) && 'Camille' === $p['nom'] );
			yume_assert_contains( 'Camille a ouvert ce chapitre il y a 1 minute', $p['message'] );
			yume_assert_same( $a, (int) get_transient( 'yume_mep_' . $id )['user'], 'présence de Camille gardée' );
			yume_assert_same( null, presence( $id, $a ), 'Camille ne se voit pas elle-même' );
			// Présence expirée : le second membre prend la place.
			set_transient(
				'yume_mep_' . $id,
				array(
					'user'   => $a,
					'depuis' => time() - 3600,
					'vu'     => time() - 900,
				),
				600
			);
			yume_assert_same( null, presence( $id, $b ) );
			yume_assert_same( $b, (int) get_transient( 'yume_mep_' . $id )['user'] );
		} finally {
			delete_transient( 'yume_mep_' . $id );
		}
	}
);

yume_tmep_test(
	'État : chapitre, liens (voisins du tome, révisions, édition avancée), droits',
	static function () {
		$ctx     = yume_tmep_contexte();
		$id      = $ctx['chapitre'];
		$suivant = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_status'  => 'draft',
				'post_title'   => 'Chapitre 2, partie 2',
				'post_content' => Blocks::paragraphe( 'Suite.' ),
				'menu_order'   => 5,
				'meta_input'   => array(
					'yume_tome_id'     => $ctx['tome'],
					'yume_numero'      => 2,
					'yume_sous_numero' => 2,
					'yume_nature'      => 'sous-chapitre',
				),
			)
		);
		$gerant  = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$e = etat( get_post( $id ), $gerant );
		yume_assert_same( 'Chapitre 2, partie 1 — 10 septembre, soir', $e['chapitre']['libelle'] );
		yume_assert_same( 'Journal de garde', $e['chapitre']['oeuvre']['titre'] );
		yume_assert_same( $ctx['tome'], $e['chapitre']['tome']['id'] );
		yume_assert_same( 'publish', $e['chapitre']['statut'] );
		yume_assert_true( $e['peut_enregistrer'] );
		yume_assert_same( null, $e['liens']['precedent'] );
		yume_assert_same( $suivant, $e['liens']['suivant']['id'] );
		yume_assert_same( yume_mise_en_page_url( $suivant ), $e['liens']['suivant']['url'] );
		yume_assert_contains( 'post.php?post=' . $id, $e['liens']['avancee'] );
		yume_assert_same( '', $e['liens']['revisions'], 'aucune révision encore' );
		yume_assert_same( 9, count( $e['unites'] ) );
	}
);

yume_tmep_test(
	'Vue : déclarée hors menu, titre et fil d’Ariane, accès refusé au traducteur, liens d’entrée',
	static function () {
		$ctx    = yume_tmep_contexte();
		$id     = $ctx['chapitre'];
		$gerant = yume_factory_user( 'yume_gerant' );
		wp_set_current_user( $gerant );
		$vues = \Yume\Core\Planning\vues_equipe_ajoutees();
		yume_assert_true( isset( $vues['chapitre'] ) && false === $vues['chapitre']['menu'], 'vue déclarée, hors menu' );
		$entrees = array_merge( ...array_values( \Yume\Core\Planning\entrees_navigation_equipe() ) );
		yume_assert_false( in_array( 'chapitre', array_column( $entrees, 'cle' ), true ), 'absente de la navigation' );

		$_GET['vue'] = 'chapitre';
		$_GET['id']  = (string) $id;
		$html        = rendu_vue();
		yume_assert_contains( 'Mettre en page · Chapitre 2, partie 1 — 10 septembre, soir', $html );
		yume_assert_contains( 'Journal de garde', $html, 'fil d’Ariane : œuvre' );
		yume_assert_contains( 'data-yn-mep', $html );
		yume_assert_contains( 'data-yn-mep-style="dialogue"', $html );
		yume_assert_contains( 'aria-pressed', $html );
		yume_assert_contains( 'Voir le chapitre', $html );
		yume_assert_contains( 'Édition avancée (administration WordPress)', $html );
		yume_assert_contains( 'class="yn-reader yn-mep__lecture"', $html );
		yume_assert_contains( 'yn-dialogue', $html, 'chapitre rendu en lecture seule sans JavaScript' );
		yume_assert_true( wp_script_is( 'yume-mise-en-page', 'enqueued' ) );

		$_GET['id'] = '999999';
		yume_assert_contains( 'Ce chapitre n’existe pas', rendu_vue() );

		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		$_GET['id'] = (string) $id;
		yume_assert_contains( 'Votre rôle ne permet pas de mettre en page ce chapitre', rendu_vue() );

		// Fiche du tome : bouton « Mettre en page » sur la ligne du chapitre.
		wp_set_current_user( $gerant );
		$ligne = \Yume\Core\Planning\ligne_chapitre_tome( get_post( $id ), true, '', false, null );
		yume_assert_contains( 'Mettre en page', $ligne );
		yume_assert_contains( esc_url( yume_mise_en_page_url( $id ) ), $ligne );
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_not_contains( 'Mettre en page', \Yume\Core\Planning\ligne_chapitre_tome( get_post( $id ), true, '', false, null ) );
	}
);
