<?php
/**
 * Planificateur de migration : export complet de l'ancien site → plan de migration
 * (tableau sérialisable en JSON, sans aucun accès à la base).
 *
 * Le plan décrit exactement ce que l'exécution doit créer ou modifier : œuvres, tomes (y
 * compris les arcs et les tomes annoncés), chapitres (migrés ou planifiés), articles à
 * reclasser, catégories, pages conservées / remplacées / ignorées, pages du §11 à créer,
 * redirections 301 (ancienne URL → nouvelle URL, formats du contrat §3), médias référencés
 * et avertissements. Chaque élément garde l'identifiant de sa source. Structure détaillée :
 * voir tools/migrate/README.md (« Structure de plan.json »).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Construction du plan de migration.
 */
final class Migration_Planner {

	/** Version du format du plan. */
	public const VERSION = 1;

	/**
	 * Alias supplémentaires utilisés dans les titres d'articles (abréviations de l'équipe),
	 * par slug cible d'œuvre. Les titres et titres alternatifs des fiches sont ajoutés
	 * automatiquement.
	 */
	public const ALIAS = array(
		'chitose-is-in-the-ramune-bottle'            => array( 'Chiramune', 'Chitose' ),
		'mikadono-sanshimai-wa-angai-choroi'         => array( 'Mikadono', 'Mikadono Sister', 'Mikadono Sisters' ),
		'gimai-seikatsu'                             => array( 'Gimai' ),
		'roshidere'                                  => array( 'Roshidere', 'Alya' ),
		'roshidere-manga'                            => array( 'Roshidere', 'Alya' ),
		'otonari-no-tenshi-sama'                     => array( 'Otonari', 'The Angel Next Door', 'Angel Next Door' ),
		'secrets-of-the-silent-witch'                => array( 'Silent Witch' ),
		'sukasuka'                                   => array( 'SukaSuka', 'WorldEnd', 'Chtholly' ),
		'sukamoka'                                   => array( 'SukaMoka' ),
		'raven-of-the-inner-palace'                  => array( 'Raven of the Inner Palace' ),
		'survival-in-another-world-with-my-mistress' => array( 'Survival in Another World with My Mistress' ),
		'witches-cant-be-collared'                   => array( 'Witches Cant Be Collared' ),
		'grimgar-of-fantasy-and-ash'                 => array( 'Grimgar' ),
		'miss-medics-diary-at-war'                   => array( 'Miss Medic', 'Miss Medics Diary at War' ),
		'agents-of-the-four-seasons-dance-of-spring' => array( 'Agents of the four seasons' ),
	);

	/**
	 * Pages créées par la migration : clé de l'option yume_pages => ( slug, clé du parent,
	 * titre, bloc dynamique ou '' pour un contenu propre à la page, réglage de lecture visé ).
	 * Les six premières sont celles du contrat §11 ; « actualites » devient la page des
	 * articles, « accueil » la page d'accueil (le thème fournit front-page.html) et
	 * « mentions-legales » reçoit un texte de base (visé par le pied de page du thème).
	 */
	public const PAGES_A_CREER = array(
		'bibliotheque'     => array( 'bibliotheque', '', 'Bibliothèque', 'yume/library-grid', '' ),
		'planning'         => array( 'planning', '', 'Planning', 'yume/planning', '' ),
		'equipe'           => array( 'equipe', '', 'Espace équipe', 'yume/team-dashboard', '' ),
		'publier'          => array( 'publier', 'equipe', 'Publier un tome', 'yume/publish-form', '' ),
		'compte'           => array( 'compte', '', 'Mon compte', 'yume/account', '' ),
		'connexion'        => array( 'connexion', '', 'Connexion', 'yume/account', '' ),
		'actualites'       => array( 'actualites', '', 'Actualités', '', 'page_for_posts' ),
		'mentions-legales' => array( 'mentions-legales', '', 'Mentions légales', '', '' ),
		'accueil'          => array( 'accueil', '', 'Accueil', '', 'page_on_front' ),
	);

	/**
	 * Export normalisé.
	 *
	 * @var array<string,mixed>
	 */
	private array $export;

	/**
	 * Options (genere_le, avancement_planning).
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Domaines du site (liens internes).
	 *
	 * @var string[]
	 */
	private array $domaines;

	/**
	 * Médias de l'export, par ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $medias = array();

	/**
	 * Avertissements du plan.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $avertissements = array();

	/**
	 * Constructeur.
	 *
	 * @param array $export  Export (format Export_Loader).
	 * @param array $options 'genere_le' (date ISO, défaut : maintenant) ;
	 *                       'date_import' (« Y-m-d H:i:s » de yume_source.importe_le).
	 */
	public function __construct( array $export, array $options = array() ) {
		$this->export   = Export_Loader::normaliser( $export );
		$maintenant     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->options  = array_merge(
			array(
				'genere_le'   => $maintenant,
				'date_import' => gmdate( 'Y-m-d H:i:s' ),
			),
			$options
		);
		$site           = $this->export['site'];
		$this->domaines = array_values(
			array_unique(
				array_map(
					static fn( $d ) => strtolower( (string) preg_replace( '/^www\./i', '', (string) $d ) ),
					array_merge( array( $site['domaine'] ), (array) $site['domaines_alias'] )
				)
			)
		);
		foreach ( $this->export['media'] as $m ) {
			$this->medias[ $m['id'] ] = $m;
		}
	}

	/**
	 * Ajoute un avertissement au plan.
	 *
	 * @param string   $niveau    info, attention ou erreur.
	 * @param string   $categorie Catégorie (statut, slug, liens, contenu…).
	 * @param string   $message   Message en français.
	 * @param int|null $source_id Page ou article concerné.
	 */
	private function avertir( string $niveau, string $categorie, string $message, ?int $source_id = null ): void {
		$this->avertissements[] = array(
			'niveau'    => $niveau,
			'categorie' => $categorie,
			'message'   => $message,
			'source_id' => $source_id,
		);
	}

	/**
	 * Chemin local (« /slug/ ») d'une page ou d'un article de l'export.
	 *
	 * @param array $item Page ou article.
	 */
	private function chemin( array $item ): string {
		$chemin = Html::chemin_local( (string) $item['link'], $this->domaines );
		return '' !== $chemin && '/' !== $chemin ? $chemin : ( '' !== $item['slug'] ? '/' . $item['slug'] . '/' : '' );
	}

	/**
	 * Famille d'une page de l'ancien site.
	 *
	 * @param array $page       Page.
	 * @param array $categories Slugs des catégories existantes.
	 * @return string hub, fiche, arc, chapitre, categorie, institutionnelle ou ignoree.
	 */
	private function famille( array $page, array $categories ): string {
		$contenu = (string) ( $page['content'] ?? '' );
		$texte   = Html::normaliser( Html::texte( $contenu ) );
		if ( 'publish' !== $page['status'] ) {
			return 'ignoree';
		}
		if ( null !== Legacy_Chapter_Parser::analyser_titre( $page['title'] ) ) {
			return 'chapitre';
		}
		if ( null !== Legacy_Arc_Parser::analyser_titre( $page['title'] ) ) {
			return 'arc';
		}
		if ( preg_match( '/^noms?\s/', $texte ) || preg_match( '/<strong>\s*noms?\s*(?:<\/strong>)?\s*:/iu', $contenu ) ) {
			return 'fiche';
		}
		if ( preg_match_all( '/\bseries?\s+(termin|en cours|licenci|abandon)/', $texte ) >= 1 && substr_count( $contenu, 'wp:image' ) >= 2 ) {
			return 'hub';
		}
		$normalise = Html::normaliser( $page['slug'] . ' ' . $page['title'] );
		if ( '' === trim( $texte ) && '' !== Html::normaliser( $page['slug'] ) ) {
			foreach ( $categories as $slug ) {
				$cat = Html::normaliser( $slug );
				if ( '' !== $cat && ( str_contains( $normalise, $cat ) || str_contains( $cat, Html::normaliser( $page['slug'] ) ) ) ) {
					return 'categorie';
				}
			}
		}
		return 'institutionnelle';
	}

	/**
	 * Construit le plan.
	 *
	 * @return array<string,mixed>
	 */
	public function plan(): array {
		$this->avertissements = array();
		$export               = $this->export;
		$categories           = array();
		foreach ( $export['categories'] as $cat ) {
			$categories[ $cat['id'] ] = $cat['slug'];
		}

		// 1. Familles de pages.
		$familles = array();
		$pages    = array();
		foreach ( $export['pages'] as $page ) {
			$pages[ $page['id'] ] = $page;
			if ( null === $page['content'] ) {
				$this->avertir( 'erreur', 'export', sprintf( 'Page %d « %s » : contenu absent de l’export.', $page['id'], $page['title'] ), $page['id'] );
			}
			$familles[ $page['id'] ] = $this->famille( $page, $categories );
		}

		// 2. Hubs.
		$hubs         = array();
		$entrees_hubs = array();
		foreach ( $familles as $id => $famille ) {
			if ( 'hub' === $famille ) {
				$hub          = Legacy_Hub_Parser::parse( $pages[ $id ], $this->domaines );
				$hubs[ $id ]  = $hub;
				$entrees_hubs = array_merge( $entrees_hubs, $hub['entrees'] );
				foreach ( $hub['avertissements'] as $message ) {
					$this->avertir( 'attention', 'hub', $message, $id );
				}
			}
		}

		// 3. Fiches → œuvres.
		$fiches = array();
		foreach ( $familles as $id => $famille ) {
			if ( 'fiche' === $famille ) {
				$fiche = Legacy_Oeuvre_Parser::parse( $pages[ $id ], $entrees_hubs[ $pages[ $id ]['slug'] ] ?? array(), array( 'domaines' => $this->domaines ) );
				if ( ! isset( $entrees_hubs[ $pages[ $id ]['slug'] ] ) ) {
					$this->avertir( 'attention', 'hub', sprintf( 'Fiche « %s » absente des hubs Yume LN / Yume Manga.', $fiche['titre'] ), $id );
				}
				$fiches[ $id ] = $fiche;
			}
		}
		foreach ( $entrees_hubs as $slug => $entree ) {
			$trouve = false;
			foreach ( $fiches as $fiche ) {
				$trouve = $trouve || $fiche['source_slug'] === $slug;
			}
			if ( ! $trouve ) {
				$this->avertir( 'attention', 'hub', sprintf( 'Le hub renvoie vers « /%s/ », qui n’est pas une fiche publiée.', $slug ), $entree['hub_id'] );
			}
		}
		$this->resoudre_collisions( $fiches );
		$this->nettoyer_titres_alt( $fiches );

		// 4. Arcs, chapitres, index des chemins.
		$arcs = array();
		foreach ( $familles as $id => $famille ) {
			if ( 'arc' === $famille ) {
				$arcs[ $id ] = Legacy_Arc_Parser::parse( $pages[ $id ], array( 'domaines' => $this->domaines ) );
			}
		}
		$chemins_chapitres = array();
		foreach ( $familles as $id => $famille ) {
			if ( 'chapitre' === $famille ) {
				$chemins_chapitres[] = $this->chemin( $pages[ $id ] );
			}
		}
		$urls_medias = array();
		foreach ( $this->medias as $mid => $m ) {
			$urls_medias[ $mid ] = $m['source_url'];
		}
		$chapitres_pages = array();
		foreach ( $familles as $id => $famille ) {
			if ( 'chapitre' === $famille ) {
				$chapitres_pages[ $id ] = Legacy_Chapter_Parser::parse(
					$pages[ $id ],
					array(
						'domaines'          => $this->domaines,
						'chemins_chapitres' => $chemins_chapitres,
						'medias'            => $urls_medias,
					)
				);
			}
		}

		// 5. Œuvres, tomes et chapitres.
		$oeuvres       = array();
		$tomes         = array();
		$chapitres     = array();
		$par_chemin    = array();
		$fiche_par_cle = array();
		foreach ( $fiches as $id => $fiche ) {
			$oeuvres[ $fiche['slug'] ]                    = $this->plan_oeuvre( $fiche );
			$fiche_par_cle[ $fiche['slug'] ]              = $fiche;
			$par_chemin[ $this->chemin( $pages[ $id ] ) ] = array(
				'type' => 'oeuvre',
				'cle'  => $fiche['slug'],
			);
			foreach ( $fiche['tomes'] as $tome ) {
				$plan_tome                  = $this->plan_tome_fiche( $fiche, $tome );
				$tomes[ $plan_tome['cle'] ] = $plan_tome;
			}
		}

		// Arcs : rattachement à l'œuvre par le lien de la fiche, sinon par le titre.
		$chapitre_par_chemin = array();
		foreach ( $chapitres_pages as $id => $chapitre ) {
			$chapitre_par_chemin[ $this->chemin( $pages[ $id ] ) ] = $id;
		}
		$chapitres_rattaches = array();
		foreach ( $arcs as $id => $arc ) {
			$chemin_arc = $this->chemin( $pages[ $id ] );
			$oeuvre     = null;
			$arc_fiche  = null;
			foreach ( $fiches as $fiche ) {
				foreach ( $fiche['arcs'] as $a ) {
					if ( $a['chemin'] === $chemin_arc ) {
						$oeuvre    = $fiche['slug'];
						$arc_fiche = $a;
					}
				}
			}
			if ( null === $oeuvre ) {
				$oeuvre = $this->oeuvre_par_titre( $arc['oeuvre_indice'], $oeuvres );
				if ( null !== $oeuvre ) {
					$this->avertir( 'attention', 'arc', sprintf( 'Arc %d : absent de la fiche, rattaché à « %s » d’après son titre.', $arc['numero'], $oeuvres[ $oeuvre ]['post']['post_title'] ), $id );
				}
			}
			if ( null === $oeuvre ) {
				$this->avertir( 'erreur', 'arc', sprintf( 'Arc %d « %s » : œuvre introuvable, arc non migré.', $arc['numero'], $arc['titre'] ), $id );
				continue;
			}
			foreach ( $arc['avertissements'] as $message ) {
				$this->avertir( 'attention', 'arc', $message, $id );
			}
			$plan_tome                 = $this->plan_tome_arc( $oeuvres[ $oeuvre ], $fiche_par_cle[ $oeuvre ], $arc, $arc_fiche );
			$par_chemin[ $chemin_arc ] = array(
				'type' => 'tome',
				'cle'  => $plan_tome['cle'],
			);

			// Chapitres annoncés dans l'ordre de la liste : page existante ou chapitre planifié.
			foreach ( $arc['chapitres'] as $ordre => $annonce ) {
				$page_id = $annonce['traduit'] ? ( $chapitre_par_chemin[ $annonce['chemin'] ] ?? null ) : null;
				if ( $annonce['traduit'] && null === $page_id ) {
					$this->avertir( 'erreur', 'chapitre', sprintf( 'Arc %d, chapitre %s : lien vers « %s », page introuvable.', $arc['numero'], Legacy_Oeuvre_Parser::numero_fr( $annonce['numero'] ), $annonce['chemin'] ), $id );
				}
				if ( null !== $page_id ) {
					$source = $chapitres_pages[ $page_id ];
					if ( $source['tome_numero'] !== $arc['numero'] || $source['numero'] !== $annonce['numero'] ) {
						$this->avertir( 'erreur', 'chapitre', sprintf( 'Arc %d : la ligne « %s » pointe vers « %s » (T.%d – Chapitre %s).', $arc['numero'], Legacy_Oeuvre_Parser::numero_fr( $annonce['numero'] ), $source['source_titre'], $source['tome_numero'], Legacy_Oeuvre_Parser::numero_fr( $source['numero'] ) ), $page_id );
					}
					if ( '' !== $source['sous_titre'] && Html::normaliser( $source['sous_titre'] ) !== Html::normaliser( $annonce['titre'] ) ) {
						$this->avertir( 'info', 'chapitre', sprintf( 'Arc %d, chapitre %s : titre « %s » sur la page, « %s » dans la liste de l’arc (page retenue).', $arc['numero'], Legacy_Oeuvre_Parser::numero_fr( $annonce['numero'] ), $source['sous_titre'], $annonce['titre'] ), $page_id );
					}
					$chapitres_rattaches[ $page_id ] = true;
					$plan_chap                       = $this->plan_chapitre_page( $oeuvre, $plan_tome, $source, $annonce, $ordre + 1 );
				} else {
					$plan_chap = $this->plan_chapitre_annonce( $oeuvre, $plan_tome, $annonce, $ordre + 1, $id );
				}
				$chapitres[ $plan_chap['cle'] ] = $plan_chap;
				$plan_tome['chapitres'][]       = $plan_chap['cle'];
				if ( null !== $page_id ) {
					$par_chemin[ $this->chemin( $pages[ $page_id ] ) ] = array(
						'type' => 'chapitre',
						'cle'  => $plan_chap['cle'],
					);
				}
			}
			$tomes[ $plan_tome['cle'] ] = $plan_tome;
		}

		// Chapitres qui ne figurent dans aucune liste d'arc : rattachés d'après leur titre.
		foreach ( $chapitres_pages as $id => $source ) {
			if ( isset( $chapitres_rattaches[ $id ] ) ) {
				continue;
			}
			$oeuvre = $this->oeuvre_par_titre( $source['oeuvre_indice'], $oeuvres );
			$cle_t  = null === $oeuvre ? null : $oeuvre . '/arc-' . $source['tome_numero'];
			if ( null === $cle_t || ! isset( $tomes[ $cle_t ] ) ) {
				$this->avertir( 'erreur', 'chapitre', sprintf( 'Page « %s » : aucun arc correspondant, chapitre non migré.', $source['source_titre'] ), $id );
				continue;
			}
			$this->avertir( 'attention', 'chapitre', sprintf( 'Page « %s » absente de la liste de l’arc %d : ajoutée en fin d’arc.', $source['source_titre'], $source['tome_numero'] ), $id );
			$annonce                                      = array(
				'numero' => $source['numero'],
				'titre'  => $source['sous_titre'],
			);
			$plan_chap                                    = $this->plan_chapitre_page( $oeuvre, $tomes[ $cle_t ], $source, $annonce, count( $tomes[ $cle_t ]['chapitres'] ) + 1 );
			$chapitres[ $plan_chap['cle'] ]               = $plan_chap;
			$tomes[ $cle_t ]['chapitres'][]               = $plan_chap['cle'];
			$par_chemin[ $this->chemin( $pages[ $id ] ) ] = array(
				'type' => 'chapitre',
				'cle'  => $plan_chap['cle'],
			);
		}

		// 6. Articles : classement, œuvre liée, dates et liens des tomes.
		$index    = Legacy_Post_Parser::index_oeuvres(
			array_map(
				static fn( $o ) => array(
					'cle'        => $o['cle'],
					'titre'      => $o['post']['post_title'],
					'titres_alt' => $o['meta']['yume_titres_alt'],
					'type'       => $o['termes']['yume_type'][0],
					'alias'      => self::ALIAS[ $o['cle'] ] ?? array(),
				),
				array_values( $oeuvres )
			)
		);
		$articles = array();
		foreach ( $export['posts'] as $post ) {
			$analyse = Legacy_Post_Parser::parse(
				$post,
				$index,
				array(
					'domaines'   => $this->domaines,
					'categories' => $categories,
				)
			);
			// Œuvre liée par un lien interne du contenu (fiche, arc, chapitre) si le titre ne suffit pas.
			if ( null === $analyse['oeuvre'] ) {
				foreach ( $analyse['liens']['internes'] as $chemin ) {
					$cible = $par_chemin[ $chemin ] ?? null;
					if ( null !== $cible ) {
						$analyse['oeuvre']        = explode( '/', $cible['cle'] )[0];
						$analyse['oeuvre_source'] = 'lien';
						break;
					}
				}
			}
			$articles[ $post['id'] ] = $analyse;
		}
		$this->completer_tomes_depuis_articles( $articles, $oeuvres, $tomes, $chapitres, $fiche_par_cle );

		// Dates et étapes des tomes, équipe des œuvres.
		foreach ( $tomes as $cle => $tome ) {
			$tomes[ $cle ] = $this->dater_tome( $tome, $articles, $chapitres, $fiche_par_cle[ $tome['oeuvre'] ] ?? array() );
		}
		foreach ( $oeuvres as $cle => $oeuvre ) {
			$oeuvres[ $cle ] = $this->equipe_oeuvre( $oeuvre, $tomes, $chapitres, $articles );
		}
		foreach ( $tomes as $cle => $tome ) {
			$oeuvres[ $tome['oeuvre'] ]['tomes'][] = $cle;
		}
		foreach ( $oeuvres as $cle => $oeuvre ) {
			usort(
				$oeuvres[ $cle ]['tomes'],
				static fn( $a, $b ) => $tomes[ $a ]['post']['menu_order'] <=> $tomes[ $b ]['post']['menu_order']
			);
		}

		// 7. Articles du plan.
		$plan_articles = array();
		foreach ( $articles as $id => $analyse ) {
			$plan_articles[] = $this->plan_article( $analyse, $tomes, $chapitres, $oeuvres );
		}

		// 8. Pages, redirections, catégories, navigation, médias.
		$pages_plan   = $this->plan_pages( $pages, $familles, $par_chemin, $hubs );
		$redirections = $this->plan_redirections( $pages, $familles, $par_chemin, $oeuvres, $tomes, $chapitres, $hubs, $export['categories'] );
		$cats_plan    = $this->plan_categories( $export['categories'], $plan_articles );
		$navigation   = $this->plan_navigation( $redirections, $pages_plan );
		$this->anomalies( $pages, $familles, $chapitres_pages, $export['posts'], $fiches, $categories );

		$oeuvres   = array_values( $oeuvres );
		$tomes     = array_values( $tomes );
		$chapitres = array_values( $chapitres );
		$medias    = $this->plan_medias( $oeuvres, $tomes, $chapitres );

		$plan            = array(
			'version'        => self::VERSION,
			'genere_le'      => (string) $this->options['genere_le'],
			'source'         => array(
				'domaine'        => $export['site']['domaine'],
				'domaines_alias' => $export['site']['domaines_alias'],
				'exporte_le'     => $export['site']['exporte_le'],
				'site_id'        => $export['site']['site_id'] ?? null,
			),
			'oeuvres'        => $oeuvres,
			'tomes'          => $tomes,
			'chapitres'      => $chapitres,
			'articles'       => $plan_articles,
			'categories'     => $cats_plan,
			'pages'          => $pages_plan,
			'redirections'   => $redirections,
			'medias'         => $medias,
			'navigation'     => $navigation,
			'reglages'       => $this->plan_reglages(),
			'avertissements' => $this->avertissements,
		);
		$plan['comptes'] = $this->comptes( $plan, $familles, $export );
		return $plan;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Œuvres
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Deux fiches donnant le même slug (Roshidere LN / Roshidere Manga) : la fiche non-LN
	 * reçoit un suffixe de type dans son slug et son titre.
	 *
	 * @param array $fiches Fiches analysées (par référence).
	 */
	private function resoudre_collisions( array &$fiches ): void {
		$par_slug = array();
		foreach ( $fiches as $id => $fiche ) {
			$par_slug[ $fiche['slug'] ][] = $id;
		}
		$suffixes = array(
			'manga'       => array( 'manga', 'Manga' ),
			'web-novel'   => array( 'wn', 'web novel' ),
			'light-novel' => array( 'ln', 'light novel' ),
		);
		foreach ( $par_slug as $slug => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			usort( $ids, static fn( $a, $b ) => ( ( 'light-novel' === $fiches[ $b ]['type'] ) <=> ( 'light-novel' === $fiches[ $a ]['type'] ) ) ? ( ( 'light-novel' === $fiches[ $b ]['type'] ) <=> ( 'light-novel' === $fiches[ $a ]['type'] ) ) : $a <=> $b );
			foreach ( array_slice( $ids, 1 ) as $id ) {
				$suffixe                = $suffixes[ $fiches[ $id ]['type'] ] ?? array( (string) $id, (string) $id );
				$fiches[ $id ]['slug']  = $slug . '-' . $suffixe[0];
				$fiches[ $id ]['titre'] = $fiches[ $id ]['titre'] . ' (' . $suffixe[1] . ')';
				$this->avertir( 'info', 'slug', sprintf( 'Deux fiches donnent le slug « %s » : la fiche %d devient « %s » (« %s »).', $slug, $id, $fiches[ $id ]['slug'], $fiches[ $id ]['titre'] ), $id );
			}
		}
	}

	/**
	 * Retire d'une fiche un titre alternatif copié depuis une autre œuvre (titre tronqué
	 * contenu dans un titre alternatif d'une autre fiche sans rapport).
	 *
	 * @param array $fiches Fiches analysées (par référence).
	 */
	private function nettoyer_titres_alt( array &$fiches ): void {
		foreach ( $fiches as $id => $fiche ) {
			$propre_a = Html::normaliser( Legacy_Oeuvre_Parser::titre_propre( $fiche['source_titre'] )['titre'] );
			foreach ( $fiche['titres_alt'] as $i => $alt ) {
				$n = Html::normaliser( $alt );
				if ( mb_strlen( $n, 'UTF-8' ) < 6 ) {
					continue;
				}
				foreach ( $fiches as $autre_id => $autre ) {
					if ( $autre_id === $id || Html::normaliser( Legacy_Oeuvre_Parser::titre_propre( $autre['source_titre'] )['titre'] ) === $propre_a ) {
						continue;
					}
					foreach ( $autre['titres_alt'] as $alt_autre ) {
						$m = Html::normaliser( $alt_autre );
						if ( $m !== $n && str_starts_with( $m, $n ) ) {
							unset( $fiches[ $id ]['titres_alt'][ $i ] );
							$this->avertir( 'attention', 'fiche', sprintf( 'Fiche « %s » : titre alternatif « %s » copié de « %s » (fiche %d), retiré.', $fiche['titre'], $alt, $autre['titre'], $autre_id ), $id );
							continue 3;
						}
					}
				}
			}
			$fiches[ $id ]['titres_alt'] = array_values( $fiches[ $id ]['titres_alt'] );
		}
	}

	/**
	 * Œuvre d'après un titre cité (« Silent Witch », « Secrets of the Silent Witch »).
	 *
	 * @param string $titre   Titre.
	 * @param array  $oeuvres Œuvres du plan.
	 */
	private function oeuvre_par_titre( string $titre, array $oeuvres ): ?string {
		if ( '' === trim( $titre ) ) {
			return null;
		}
		$index = Legacy_Post_Parser::index_oeuvres(
			array_map(
				static fn( $o ) => array(
					'cle'        => $o['cle'],
					'titre'      => $o['post']['post_title'],
					'titres_alt' => $o['meta']['yume_titres_alt'],
					'type'       => $o['termes']['yume_type'][0],
					'alias'      => self::ALIAS[ $o['cle'] ] ?? array(),
				),
				array_values( $oeuvres )
			)
		);
		return Legacy_Post_Parser::trouver_oeuvre( $titre, $index )['cle'];
	}

	/**
	 * Plan d'une œuvre.
	 *
	 * @param array $fiche Fiche analysée.
	 * @return array<string,mixed>
	 */
	private function plan_oeuvre( array $fiche ): array {
		foreach ( $fiche['avertissements'] as $message ) {
			$this->avertir( false !== strpos( $message, 'ambigu' ) || false !== strpos( $message, 'indique' ) ? 'attention' : 'info', 'fiche', $message, $fiche['source_id'] );
		}
		if ( 'web-novel' === $fiche['type'] && str_ends_with( $fiche['source_slug'], '-ln' ) ) {
			$this->avertir( 'info', 'slug', sprintf( 'Fiche « %s » : web novel publié sous le slug « %s » (suffixe -ln incohérent).', $fiche['titre'], $fiche['source_slug'] ), $fiche['source_id'] );
		}
		return array(
			'cle'            => $fiche['slug'],
			'source'         => array(
				'type'  => 'fiche',
				'id'    => $fiche['source_id'],
				'slug'  => $fiche['source_slug'],
				'url'   => $fiche['source_url'],
				'titre' => $fiche['source_titre'],
			),
			'post'           => array(
				'post_type'      => 'yume_oeuvre',
				'post_title'     => $fiche['titre'],
				'post_name'      => $fiche['slug'],
				'post_status'    => 'publish',
				'post_date'      => $fiche['date'],
				'post_excerpt'   => '',
				'post_content'   => $fiche['contenu'],
				'comment_status' => 'open',
			),
			'thumbnail_id'   => $fiche['image_id'],
			'termes'         => array(
				'yume_type'   => array( $fiche['type'] ),
				'yume_statut' => array( $fiche['statut'] ),
				'yume_genre'  => array(),
			),
			'meta'           => array(
				'yume_titres_alt'        => $fiche['titres_alt'],
				'yume_auteur'            => $fiche['auteur'],
				'yume_illustrateur'      => $fiche['illustrateur'],
				'yume_editeur_vo'        => $fiche['editeur_vo'],
				'yume_nb_tomes_vo'       => (int) $fiche['nb_tomes_vo'],
				'yume_statut_vo'         => $fiche['statut_vo'],
				'yume_liens'             => array_slice( $fiche['liens'], 0, 30 ),
				'yume_source_traduction' => '',
				'yume_banniere_id'       => $fiche['banniere_id'],
				'yume_equipe'            => array(
					'traduction' => '',
					'relecture'  => '',
					'edition'    => '',
				),
			),
			'tomes'          => array(),
			'url'            => '/oeuvres/' . $fiche['slug'] . '/',
			'infos'          => array(
				'type_indice'      => $fiche['type_indice'],
				'statut_hub'       => $fiche['statut_hub'],
				'statut_fiche'     => $fiche['statut_fiche'],
				'avancement_fiche' => $fiche['avancement_fiche'],
				'infos_vf'         => $fiche['infos_vf'],
				'autres_infos'     => $fiche['autres_infos'],
				'synopsis'         => $fiche['synopsis'],
			),
			'avertissements' => $fiche['avertissements'],
		);
	}

	/**
	 * Numéro pour les URL (« 3 », « 26.5 »).
	 *
	 * @param float $numero Numéro.
	 */
	public static function numero_url( float $numero ): string {
		return floor( $numero ) === $numero ? (string) (int) $numero : rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Slug d'un tome d'après sa nature et son numéro (« tome-9 », « arc-7 », « tome-ex »).
	 *
	 * @param string     $nature Nature.
	 * @param float|null $numero Numéro.
	 */
	public static function slug_tome( string $nature, ?float $numero ): string {
		if ( 'ex' === $nature ) {
			return 'tome-ex';
		}
		$prefixe = 'arc' === $nature ? 'arc' : 'tome';
		return $prefixe . '-' . str_replace( '.', '-', self::numero_url( (float) $numero ) );
	}

	/**
	 * Squelette commun d'un tome du plan.
	 *
	 * @param string     $oeuvre Clé de l'œuvre.
	 * @param string     $titre  Titre de l'œuvre.
	 * @param string     $nature Nature.
	 * @param float|null $numero Numéro.
	 * @param string     $suffixe Complément du titre (« : Rentrée académique »).
	 * @return array<string,mixed>
	 */
	private function tome_base( string $oeuvre, string $titre, string $nature, ?float $numero, string $suffixe = '' ): array {
		$slug    = self::slug_tome( $nature, $numero );
		$libelle = 'ex' === $nature ? 'Tome EX' : ( 'arc' === $nature ? 'Arc ' : 'Tome ' ) . Legacy_Oeuvre_Parser::numero_fr( $numero );
		if ( 'ex' === $nature ) {
			$libelle = 'Tome EX';
		}
		return array(
			'cle'            => $oeuvre . '/' . $slug,
			'oeuvre'         => $oeuvre,
			'source'         => array(),
			'post'           => array(
				'post_type'      => 'yume_tome',
				'post_title'     => $titre . ' — ' . $libelle . $suffixe,
				'post_name'      => $slug,
				'post_status'    => 'publish',
				'post_date'      => '',
				'post_excerpt'   => '',
				'post_content'   => '',
				'menu_order'     => null === $numero ? 1000 : (int) round( $numero * 10 ),
				'comment_status' => 'open',
			),
			'thumbnail_id'   => 0,
			'meta'           => array(
				'yume_numero'        => $numero,
				'yume_nature'        => $nature,
				'yume_lien_pdf'      => '',
				'yume_lien_epub'     => '',
				'yume_equivalence'   => '',
				'yume_illustrations' => array(),
				'yume_credits'       => array(
					'traduction' => '',
					'relecture'  => '',
					'edition'    => '',
				),
				'yume_etape'         => 'publie',
				'yume_avancement'    => array(
					'traduction' => 100,
					'relecture'  => 100,
					'edition'    => 100,
				),
				'yume_nb_chapitres'  => 0,
			),
			'date_source'    => '',
			'liens'          => array(),
			'achat_fiche'    => false,
			'chapitres'      => array(),
			'url'            => '/oeuvres/' . $oeuvre . '/' . $slug . '/',
			'avertissements' => array(),
		);
	}

	/**
	 * Plan d'un tome lu sur une fiche.
	 *
	 * @param array $fiche Fiche.
	 * @param array $tome  Tome analysé.
	 * @return array<string,mixed>
	 */
	private function plan_tome_fiche( array $fiche, array $tome ): array {
		$plan                           = $this->tome_base( $fiche['slug'], $fiche['titre'], $tome['nature'], $tome['numero'] );
		$plan['source']                 = array(
			'type'  => 'fiche',
			'id'    => $fiche['source_id'],
			'bloc'  => $tome['bloc_index'],
			'url'   => $fiche['source_url'],
			'texte' => $tome['libelle_source'],
		);
		$plan['thumbnail_id']           = $tome['couverture_id'];
		$plan['meta']['yume_lien_pdf']  = $tome['lien_pdf'];
		$plan['meta']['yume_lien_epub'] = $tome['lien_epub'];
		$plan['liens']                  = $tome['liens'];
		$plan['achat_fiche']            = ! empty( $tome['achat_mentionne'] );
		$plan['couverture_url']         = $tome['couverture_url'];

		$contenu = array();
		if ( null !== $tome['chapitres_plage'] ) {
			$plage                        = sprintf( 'Chapitres %s à %s', Legacy_Oeuvre_Parser::numero_fr( $tome['chapitres_plage']['de'] ), Legacy_Oeuvre_Parser::numero_fr( $tome['chapitres_plage']['a'] ) );
			$plan['post']['post_excerpt'] = $plage;
			$plan['chapitres_plage']      = $tome['chapitres_plage'];
			$contenu[]                    = Blocks::paragraphe( Html::attr( $plage . '.' ) );
		}
		if ( $tome['sommaire'] ) {
			$elements = array();
			foreach ( $tome['sommaire'] as $element ) {
				$elements[] = Html::attr( self::libelle_sommaire( $element ) );
			}
			$contenu[] = Blocks::titre( 'Sommaire', 2 );
			$contenu[] = Blocks::liste( $elements, 'yn-sommaire' );
		}
		$plan['post']['post_content'] = Blocks::assembler( $contenu );
		$plan['sommaire']             = $tome['sommaire'];

		if ( $tome['a_venir'] ) {
			$plan['post']['post_status']     = 'draft';
			$plan['meta']['yume_etape']      = 'a_faire';
			$plan['meta']['yume_avancement'] = array(
				'traduction' => 0,
				'relecture'  => 0,
				'edition'    => 0,
			);
			$this->avertir( 'info', 'tome', sprintf( '« %s » : tome annoncé (« %s »), créé en brouillon (planifié).', $plan['post']['post_title'], $tome['libelle_source'] ), $fiche['source_id'] );
		}
		return $plan;
	}

	/**
	 * Libellé d'un élément de sommaire (« Chapitre 3 — Titre », « Prologue », « Postface — … »).
	 *
	 * @param array $element Élément (numero, nature, titre).
	 */
	private static function libelle_sommaire( array $element ): string {
		$noms = array(
			'prologue'  => 'Prologue',
			'epilogue'  => 'Épilogue',
			'postface'  => 'Postface',
			'interlude' => 'Interlude',
		);
		if ( 'chapitre' === $element['nature'] && null !== $element['numero'] ) {
			$libelle = 'Chapitre ' . Legacy_Oeuvre_Parser::numero_fr( $element['numero'] );
		} elseif ( isset( $noms[ $element['nature'] ] ) ) {
			$libelle = $noms[ $element['nature'] ];
		} else {
			return $element['titre'];
		}
		return '' !== $element['titre'] ? $libelle . ' — ' . $element['titre'] : $libelle;
	}

	/**
	 * Plan d'un arc (page ARC + vignette de la fiche).
	 *
	 * @param array      $oeuvre    Œuvre du plan.
	 * @param array      $fiche     Fiche de l'œuvre.
	 * @param array      $arc       Arc analysé.
	 * @param array|null $arc_fiche Entrée de la fiche pour cet arc (couverture).
	 * @return array<string,mixed>
	 */
	private function plan_tome_arc( array $oeuvre, array $fiche, array $arc, ?array $arc_fiche ): array {
		$plan                             = $this->tome_base( $oeuvre['cle'], $oeuvre['post']['post_title'], 'arc', (float) $arc['numero'], ' : ' . $arc['titre'] );
		$plan['source']                   = array(
			'type'  => 'arc',
			'id'    => $arc['source_id'],
			'slug'  => $arc['source_slug'],
			'url'   => $arc['source_url'],
			'texte' => $arc['source_titre'],
		);
		$plan['titre_arc']                = $arc['titre'];
		$plan['thumbnail_id']             = $arc_fiche['couverture_id'] ?? $arc['image_id'];
		$plan['couverture_url']           = $arc_fiche['couverture_url'] ?? '';
		$plan['post']['post_content']     = $arc['contenu'];
		$plan['meta']['yume_equivalence'] = $arc['equivalence'];
		$plan['meta']['yume_lien_pdf']    = $arc['lien_pdf'];
		$plan['meta']['yume_lien_epub']   = $arc['lien_epub'];
		$plan['liens']                    = $arc['liens'];
		$plan['nb_annonces']              = count( $arc['chapitres'] );
		$plan['nb_traduits']              = $arc['nb_traduits'];
		if ( null === $arc_fiche ) {
			$this->avertir( 'attention', 'arc', sprintf( 'Arc %d : aucune vignette sur la fiche, image de la page ARC utilisée.', $arc['numero'] ), $arc['source_id'] );
		}

		// Planning : arc complet → publié ; en cours de publication → édition ; rien de publié → relecture ou traduction.
		$avancement = $fiche['avancement_fiche'] ?? null;
		$tr         = (int) ( $avancement['traduction'] ?? 0 );
		$rec        = (int) ( $avancement['relecture'] ?? 0 );
		$total      = max( 1, count( $arc['chapitres'] ) );
		if ( $arc['nb_traduits'] > 0 && $arc['nb_traduits'] >= count( $arc['chapitres'] ) ) {
			$plan['meta']['yume_etape'] = 'publie';
		} elseif ( $arc['nb_traduits'] > 0 ) {
			$plan['meta']['yume_etape']      = 'edition';
			$plan['meta']['yume_avancement'] = array(
				'traduction' => 100,
				'relecture'  => $rec >= $arc['numero'] ? 100 : 0,
				'edition'    => (int) round( $arc['nb_traduits'] / $total * 100 ),
			);
		} else {
			$plan['post']['post_status']     = 'draft';
			$plan['meta']['yume_etape']      = $tr >= $arc['numero'] ? 'relecture' : 'traduction';
			$plan['meta']['yume_avancement'] = array(
				'traduction' => $tr >= $arc['numero'] ? 100 : 0,
				'relecture'  => $rec > $arc['numero'] ? 100 : 0,
				'edition'    => 0,
			);
		}
		if ( 'publie' !== $plan['meta']['yume_etape'] ) {
			$this->avertir(
				'info',
				'planning',
				sprintf(
					'Arc %d : %d chapitre(s) traduit(s) sur %d annoncé(s) → étape « %s »%s.',
					$arc['numero'],
					$arc['nb_traduits'],
					count( $arc['chapitres'] ),
					$plan['meta']['yume_etape'],
					$avancement ? sprintf( ' (fiche : « %s »)', $fiche['statut_fiche'] ) : ''
				),
				$arc['source_id']
			);
		}
		return $plan;
	}

	/**
	 * Plan d'un chapitre issu d'une page.
	 *
	 * @param string $oeuvre  Clé de l'œuvre.
	 * @param array  $tome    Tome du plan.
	 * @param array  $source  Chapitre analysé.
	 * @param array  $annonce Ligne de la liste d'arc (numero, titre).
	 * @param int    $ordre   Ordre dans le tome (menu_order).
	 * @return array<string,mixed>
	 */
	private function plan_chapitre_page( string $oeuvre, array $tome, array $source, array $annonce, int $ordre ): array {
		$numero                             = $source['numero'] ?? $annonce['numero'];
		$sous_titre                         = '' !== $source['sous_titre'] ? $source['sous_titre'] : (string) $annonce['titre'];
		$plan                               = $this->chapitre_base( $oeuvre, $tome, $numero, $source['nature'], $sous_titre, $ordre );
		$plan['source']                     = array(
			'type'  => 'page',
			'id'    => $source['source_id'],
			'slug'  => $source['source_slug'],
			'url'   => $source['source_url'],
			'titre' => $source['source_titre'],
		);
		$plan['post']['post_status']        = 'publish' === $source['status'] ? 'publish' : 'draft';
		$plan['post']['post_date']          = $source['date'];
		$plan['post']['post_content']       = $source['contenu'];
		$plan['meta']['yume_credits']       = $source['credits'];
		$plan['meta']['yume_nb_mots']       = $source['nb_mots'];
		$plan['meta']['yume_temps_lecture'] = $source['temps_lecture'];
		$plan['meta']['yume_source']        = array(
			'format'     => 'migration',
			'hash'       => $source['hash'],
			'importe_le' => (string) $this->options['date_import'],
		);
		$plan['illustrations']              = $source['illustrations'];
		$plan['stats']                      = $source['stats'];
		$plan['navigation_supprimee']       = $source['navigation'];
		$plan['avertissements']             = $source['avertissements'];
		foreach ( $source['avertissements'] as $message ) {
			$niveau = 'attention';
			if ( str_starts_with( $message, 'Sous-titre' ) && '' !== (string) $annonce['titre'] ) {
				$niveau   = 'info';
				$message .= sprintf( ' Titre de la liste de l’arc retenu : « %s ».', $annonce['titre'] );
			} elseif ( str_starts_with( $message, 'Image ' ) || str_contains( $message, 'plusieurs répliques' ) ) {
				$niveau = 'info';
			}
			$this->avertir( $niveau, 'chapitre', sprintf( '« %s » : %s', $source['source_titre'], $message ), $source['source_id'] );
		}
		if ( $source['slug_incoherent'] ) {
			$this->avertir( 'attention', 'slug', sprintf( 'Page « %s » publiée sous le slug « %s » (attendu « %s ») : redirection vers %s.', $source['source_titre'], $source['source_slug'], $source['slug_attendu'], $plan['url'] ), $source['source_id'] );
		}
		return $plan;
	}

	/**
	 * Plan d'un chapitre annoncé mais pas encore traduit (brouillon pour le planning).
	 *
	 * @param string $oeuvre  Clé de l'œuvre.
	 * @param array  $tome    Tome du plan.
	 * @param array  $annonce Ligne de la liste d'arc.
	 * @param int    $ordre   Ordre dans le tome.
	 * @param int    $page_id Page ARC source.
	 * @return array<string,mixed>
	 */
	private function plan_chapitre_annonce( string $oeuvre, array $tome, array $annonce, int $ordre, int $page_id ): array {
		$plan                        = $this->chapitre_base( $oeuvre, $tome, $annonce['numero'], 'chapitre', (string) $annonce['titre'], $ordre );
		$plan['source']              = array(
			'type'  => 'annonce',
			'id'    => $page_id,
			'ligne' => $ordre,
		);
		$plan['post']['post_status'] = 'draft';
		return $plan;
	}

	/**
	 * Squelette commun d'un chapitre du plan.
	 *
	 * @param string     $oeuvre     Clé de l'œuvre.
	 * @param array      $tome       Tome du plan.
	 * @param float|null $numero     Numéro.
	 * @param string     $nature     Nature.
	 * @param string     $sous_titre Sous-titre.
	 * @param int        $ordre      Ordre dans le tome.
	 * @return array<string,mixed>
	 */
	private function chapitre_base( string $oeuvre, array $tome, ?float $numero, string $nature, string $sous_titre, int $ordre ): array {
		$noms    = array(
			'chapitre'  => 'Chapitre',
			'prologue'  => 'Prologue',
			'epilogue'  => 'Épilogue',
			'postface'  => 'Postface',
			'interlude' => 'Interlude',
			'bonus'     => 'Bonus',
		);
		$nature  = isset( $noms[ $nature ] ) ? $nature : 'chapitre';
		$libelle = $noms[ $nature ] . ( null !== $numero && in_array( $nature, array( 'chapitre', 'interlude', 'bonus' ), true ) ? ' ' . Legacy_Oeuvre_Parser::numero_fr( $numero ) : '' );
		if ( 'chapitre' === $nature && null !== $numero ) {
			$segment = self::numero_url( $numero );
			$slug    = 'chapitre-' . str_replace( '.', '-', $segment );
		} else {
			$slug    = sanitize_title( $libelle );
			$segment = $slug;
		}
		return array(
			'cle'                  => $tome['cle'] . '/' . $segment,
			'oeuvre'               => $oeuvre,
			'tome'                 => $tome['cle'],
			'source'               => array(),
			'post'                 => array(
				'post_type'      => 'yume_chapitre',
				'post_title'     => $libelle . ( '' !== $sous_titre ? ' — ' . $sous_titre : '' ),
				'post_name'      => $slug,
				'post_status'    => 'publish',
				'post_date'      => '',
				'post_content'   => '',
				'menu_order'     => $ordre,
				'comment_status' => 'open',
			),
			'meta'                 => array(
				'yume_numero'        => $numero,
				'yume_sous_titre'    => $sous_titre,
				'yume_nature'        => $nature,
				'yume_credits'       => array(
					'traduction' => '',
					'relecture'  => '',
					'edition'    => '',
				),
				'yume_nb_mots'       => 0,
				'yume_temps_lecture' => 0,
				'yume_source'        => array(
					'format'     => '',
					'hash'       => '',
					'importe_le' => '',
				),
			),
			'illustrations'        => array(),
			'stats'                => array(),
			'navigation_supprimee' => array(),
			'url'                  => '/lire/' . $oeuvre . '/' . substr( $tome['cle'], strlen( $oeuvre ) + 1 ) . '/' . $segment . '/',
			'avertissements'       => array(),
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Articles → tomes
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Clé du tome visé par un article de sortie (arc pour une œuvre découpée en arcs,
	 * tome sinon, tome contenant les chapitres pour un manga).
	 *
	 * @param array $article Article analysé.
	 * @param array $oeuvres Œuvres du plan.
	 * @param array $tomes   Tomes du plan.
	 */
	private function tome_de_article( array $article, array $oeuvres, array $tomes ): ?string {
		$oeuvre = $article['oeuvre'];
		if ( null === $oeuvre || ! isset( $oeuvres[ $oeuvre ] ) || 'sortie' !== $article['classement'] ) {
			return null;
		}
		$a_des_arcs = false;
		foreach ( $tomes as $tome ) {
			$a_des_arcs = $a_des_arcs || ( $tome['oeuvre'] === $oeuvre && 'arc' === $tome['meta']['yume_nature'] );
		}
		if ( $article['tome_ex'] ) {
			return $oeuvre . '/tome-ex';
		}
		if ( $a_des_arcs ) {
			$numero = $article['arc'] ?? $article['tome'];
			return null === $numero ? null : $oeuvre . '/' . self::slug_tome( 'arc', (float) $numero );
		}
		if ( null !== $article['tome'] ) {
			return $oeuvre . '/' . self::slug_tome( 'tome', (float) $article['tome'] );
		}
		if ( $article['chapitres'] ) {
			foreach ( $tomes as $cle => $tome ) {
				$plage = $tome['chapitres_plage'] ?? null;
				if ( $tome['oeuvre'] === $oeuvre && null !== $plage && $article['chapitres'][0] >= $plage['de'] && $article['chapitres'][0] <= $plage['a'] ) {
					return $cle;
				}
			}
		}
		return null;
	}

	/**
	 * Complète les tomes avec les articles de sortie : tome absent de la fiche créé depuis
	 * l'article, lien PDF / EPUB manquant sur la fiche récupéré dans l'article.
	 *
	 * @param array $articles  Articles analysés (par référence : clé du tome ajoutée).
	 * @param array $oeuvres   Œuvres.
	 * @param array $tomes     Tomes (par référence).
	 * @param array $chapitres Chapitres.
	 * @param array $fiches    Fiches par clé d'œuvre.
	 */
	private function completer_tomes_depuis_articles( array &$articles, array $oeuvres, array &$tomes, array $chapitres, array $fiches ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature commune des étapes du plan.
		uasort( $articles, static fn( $a, $b ) => 0 !== strcmp( $a['date'], $b['date'] ) ? strcmp( $a['date'], $b['date'] ) : $a['source_id'] <=> $b['source_id'] );
		foreach ( $articles as $id => $article ) {
			$cle                         = $this->tome_de_article( $article, $oeuvres, $tomes );
			$articles[ $id ]['tome_cle'] = $cle;
			if ( null === $cle ) {
				if ( 'sortie' === $article['classement'] && null !== $article['oeuvre'] && in_array( $article['type_sortie'], array( 'tome', 'tome_relie' ), true ) ) {
					$this->avertir( 'attention', 'article', sprintf( 'Article « %s » : tome annoncé introuvable.', $article['titre'] ), $id );
				}
				continue;
			}
			if ( ! isset( $tomes[ $cle ] ) ) {
				if ( 'tome' !== $article['type_sortie'] || str_contains( $cle, '/arc-' ) ) {
					$this->avertir( 'attention', 'article', sprintf( 'Article « %s » : « %s » introuvable.', $article['titre'], $cle ), $id );
					$articles[ $id ]['tome_cle'] = null;
					continue;
				}
				$oeuvre               = $oeuvres[ $article['oeuvre'] ];
				$nature               = $article['tome_ex'] ? 'ex' : 'tome';
				$plan                 = $this->tome_base( $oeuvre['cle'], $oeuvre['post']['post_title'], $nature, $article['tome_ex'] ? null : $article['tome'] );
				$plan['source']       = array(
					'type'  => 'article',
					'id'    => $id,
					'url'   => $article['lien'],
					'texte' => $article['titre'],
				);
				$plan['thumbnail_id'] = (int) ( $this->export_post( $id )['featured_media'] ?? 0 );
				foreach ( $article['liens']['lecture'] as $url ) {
					$plan['liens'][] = array(
						'type'  => 'lecture',
						'url'   => $url,
						'texte' => 'LIRE',
					);
				}
				$tomes[ $cle ] = $plan;
				$this->avertir( 'attention', 'tome', sprintf( '« %s » : absent de la fiche, créé d’après l’article « %s ».', $plan['post']['post_title'], $article['titre'] ), $id );
				if ( ! $article['contenu_disponible'] ) {
					$this->avertir( 'attention', 'export', sprintf( 'Article %d : contenu non exporté, liens du tome inconnus.', $id ), $id );
				}
			}
			foreach ( array( 'pdf', 'epub' ) as $format ) {
				$meta = 'yume_lien_' . $format;
				if ( '' === $tomes[ $cle ]['meta'][ $meta ] && '' !== $article['liens'][ $format ] ) {
					$refus = $this->refus_lien_article( $tomes[ $cle ], $oeuvres, $article );
					if ( null !== $refus ) {
						$this->avertir( $refus['niveau'], 'liens', sprintf( '« %s » : lien %s de l’article « %s » (%s) non repris : %s.', $tomes[ $cle ]['post']['post_title'], strtoupper( $format ), $article['titre'], $article['liens'][ $format ], $refus['raison'] ), $id );
						continue;
					}
					$tomes[ $cle ]['meta'][ $meta ] = $article['liens'][ $format ];
					$this->avertir( 'info', 'liens', sprintf( '« %s » : lien %s absent de la fiche, repris de l’article « %s ».', $tomes[ $cle ]['post']['post_title'], strtoupper( $format ), $article['titre'] ), $id );
				} elseif ( '' !== $article['liens'][ $format ] && $tomes[ $cle ]['meta'][ $meta ] !== $article['liens'][ $format ] && in_array( $article['type_sortie'], array( 'tome', 'tome_relie' ), true ) ) {
					$this->avertir( 'info', 'liens', sprintf( '« %s » : lien %s différent entre la fiche (%s) et l’article « %s » (%s) ; fiche retenue.', $tomes[ $cle ]['post']['post_title'], strtoupper( $format ), $tomes[ $cle ]['meta'][ $meta ], $article['titre'], $article['liens'][ $format ] ), $id );
				}
			}
		}
	}

	/**
	 * Raison de ne pas reprendre sur un tome le lien PDF / EPUB d'un article, ou null s'il peut
	 * l'être : seul un article de sortie du tome (tome, tome relié) donne le fichier du tome
	 * (une sortie de chapitre renvoie au fichier d'un chapitre) ; jamais pour une œuvre
	 * licenciée ni pour un tome que la fiche ne propose qu'à l'achat (fichiers retirés par
	 * l'équipe).
	 *
	 * @param array $tome    Tome du plan.
	 * @param array $oeuvres Œuvres du plan.
	 * @param array $article Article analysé.
	 * @return array{niveau:string,raison:string}|null
	 */
	private function refus_lien_article( array $tome, array $oeuvres, array $article ): ?array {
		$statut = (string) ( $oeuvres[ $tome['oeuvre'] ]['termes']['yume_statut'][0] ?? '' );
		if ( 'licenciee' === $statut ) {
			return array(
				'niveau' => 'attention',
				'raison' => 'œuvre licenciée, fichiers retirés par l’équipe',
			);
		}
		if ( ! empty( $tome['achat_fiche'] ) ) {
			return array(
				'niveau' => 'attention',
				'raison' => 'la fiche ne propose ce tome qu’à l’achat',
			);
		}
		if ( ! in_array( $article['type_sortie'], array( 'tome', 'tome_relie' ), true ) ) {
			return array(
				'niveau' => 'info',
				'raison' => 'l’article n’annonce pas la sortie du tome (lien d’un chapitre)',
			);
		}
		return null;
	}

	/**
	 * Article de l'export par ID.
	 *
	 * @param int $id ID.
	 * @return array<string,mixed>
	 */
	private function export_post( int $id ): array {
		foreach ( $this->export['posts'] as $post ) {
			if ( $post['id'] === $id ) {
				return $post;
			}
		}
		return array();
	}

	/**
	 * Date d'un tome : article d'annonce (le plus ancien), sinon dernier chapitre publié
	 * (arcs), sinon date d'envoi de la couverture, sinon date de la fiche.
	 *
	 * @param array $tome      Tome.
	 * @param array $articles  Articles analysés.
	 * @param array $chapitres Chapitres du plan.
	 * @param array $fiche     Fiche de l'œuvre.
	 * @return array<string,mixed>
	 */
	private function dater_tome( array $tome, array $articles, array $chapitres, array $fiche ): array {
		$date   = '';
		$source = '';
		foreach ( $articles as $id => $article ) {
			if ( ( $article['tome_cle'] ?? null ) === $tome['cle'] && in_array( $article['type_sortie'], array( 'tome', 'tome_relie' ), true ) && ( '' === $date || $article['date'] < $date ) ) {
				$date   = $article['date'];
				$source = 'article:' . $id;
			}
		}
		$publies = array();
		foreach ( $tome['chapitres'] as $cle ) {
			if ( 'publish' === $chapitres[ $cle ]['post']['post_status'] && '' !== $chapitres[ $cle ]['post']['post_date'] ) {
				$publies[] = $chapitres[ $cle ]['post']['post_date'];
			}
		}
		if ( '' === $date && $publies ) {
			$date   = max( $publies );
			$source = 'chapitre';
		}
		if ( '' === $date && $tome['thumbnail_id'] && ! empty( $this->medias[ $tome['thumbnail_id'] ]['date'] ) ) {
			$date   = Export_Loader::date( $this->medias[ $tome['thumbnail_id'] ]['date'] );
			$source = 'couverture:' . $tome['thumbnail_id'];
		}
		if ( '' === $date && ! empty( $fiche['date'] ) ) {
			$date   = $fiche['date'];
			$source = 'fiche:' . $fiche['source_id'];
		}
		$tome['post']['post_date']         = $date;
		$tome['date_source']               = $source;
		$tome['meta']['yume_nb_chapitres'] = count(
			array_filter( $tome['chapitres'], static fn( $c ) => 'publish' === $chapitres[ $c ]['post']['post_status'] )
		);

		// Crédits du tome : crédits les plus fréquents de ses chapitres.
		$credits = array();
		foreach ( $tome['chapitres'] as $cle ) {
			$c = $chapitres[ $cle ]['meta']['yume_credits'];
			if ( '' !== $c['traduction'] || '' !== $c['relecture'] ) {
				$k             = wp_json_encode( $c );
				$credits[ $k ] = ( $credits[ $k ] ?? 0 ) + 1;
			}
		}
		if ( $credits ) {
			arsort( $credits );
			$tome['meta']['yume_credits'] = json_decode( (string) array_key_first( $credits ), true );
		}
		if ( 'publish' === $tome['post']['post_status'] && 'arc' !== $tome['meta']['yume_nature'] && '' === $tome['meta']['yume_lien_pdf'] && '' === $tome['meta']['yume_lien_epub'] ) {
			$types = array_unique( array_map( static fn( $l ) => $l['type'], $tome['liens'] ) );
			$this->avertir( 'info', 'liens', sprintf( '« %s » : ni PDF ni EPUB%s.', $tome['post']['post_title'], $types ? ' (liens : ' . implode( ', ', $types ) . ')' : '' ), (int) ( $tome['source']['id'] ?? 0 ) );
		}
		return $tome;
	}

	/**
	 * Équipe d'une œuvre : crédits de ses chapitres, sinon crédits annoncés dans les articles.
	 *
	 * @param array $oeuvre    Œuvre.
	 * @param array $tomes     Tomes.
	 * @param array $chapitres Chapitres.
	 * @param array $articles  Articles analysés.
	 * @return array<string,mixed>
	 */
	private function equipe_oeuvre( array $oeuvre, array $tomes, array $chapitres, array $articles ): array {
		$credits = array();
		foreach ( $tomes as $tome ) {
			if ( $tome['oeuvre'] !== $oeuvre['cle'] ) {
				continue;
			}
			$c = $tome['meta']['yume_credits'];
			if ( '' !== $c['traduction'] || '' !== $c['relecture'] ) {
				$k             = wp_json_encode( $c );
				$credits[ $k ] = ( $credits[ $k ] ?? 0 ) + max( 1, count( $tome['chapitres'] ) );
			}
		}
		if ( $credits ) {
			arsort( $credits );
			$oeuvre['meta']['yume_equipe'] = json_decode( (string) array_key_first( $credits ), true );
			return $oeuvre;
		}
		$recent = '';
		foreach ( $articles as $article ) {
			if ( $article['oeuvre'] === $oeuvre['cle'] && '' !== $article['equipe']['traduction'] && $article['date'] > $recent ) {
				$recent                        = $article['date'];
				$oeuvre['meta']['yume_equipe'] = array(
					'traduction' => $article['equipe']['traduction'],
					'relecture'  => $article['equipe']['relecture'],
					'edition'    => '',
				);
			}
		}
		return $oeuvre;
	}

	/**
	 * Plan d'un article.
	 *
	 * @param array $article   Article analysé.
	 * @param array $tomes     Tomes.
	 * @param array $chapitres Chapitres.
	 * @param array $oeuvres   Œuvres.
	 * @return array<string,mixed>
	 */
	private function plan_article( array $article, array $tomes, array $chapitres, array $oeuvres ): array {
		$tome     = $article['tome_cle'] ?? null;
		$chap_cle = array();
		if ( null !== $tome && isset( $tomes[ $tome ] ) ) {
			foreach ( $article['chapitres'] as $numero ) {
				$cle = $tome . '/' . self::numero_url( (float) $numero );
				if ( isset( $chapitres[ $cle ] ) ) {
					$chap_cle[] = $cle;
				}
			}
		}
		foreach ( $article['avertissements'] as $message ) {
			$this->avertir( 'attention', 'article', $message, $article['source_id'] );
		}
		if ( count( $article['chapitres'] ) > 1 ) {
			$this->avertir( 'info', 'article', sprintf( 'Article « %s » : sortie groupée de %d chapitres.', $article['titre'], count( $article['chapitres'] ) ), $article['source_id'] );
		}
		// Brouillon d'essai (titre ou contenu quasi vide) : laissé tel quel, hors migration.
		$action = 'reclasser';
		if ( 'publish' !== $article['status'] && ( mb_strlen( $article['titre'], 'UTF-8' ) <= 3 || ( $article['nb_mots'] ?? 0 ) < 5 ) ) {
			$action = 'ignorer';
			$this->avertir( 'attention', 'article', sprintf( 'Brouillon « %s » (%d mot(s)) : brouillon d’essai laissé tel quel, à supprimer à la main si l’équipe le confirme.', $article['titre'], $article['nb_mots'] ?? 0 ), $article['source_id'] );
		}
		return array(
			'source_id'            => $article['source_id'],
			'slug'                 => $article['slug'],
			'titre'                => $article['titre'],
			'date'                 => $article['date'],
			'status'               => $article['status'],
			'action'               => $action,
			'url'                  => $article['lien'],
			'classement'           => $article['classement'],
			'type_sortie'          => $article['type_sortie'],
			'categories_actuelles' => $article['categories_source'],
			'categories_slugs'     => $article['categories_slugs'],
			'categorie_cible'      => $article['categorie_cible'],
			'oeuvre'               => null !== $article['oeuvre'] && isset( $oeuvres[ $article['oeuvre'] ] ) ? $article['oeuvre'] : null,
			'oeuvre_source'        => $article['oeuvre_source'],
			'oeuvre_alias'         => $article['oeuvre_alias'],
			'tome'                 => null !== $tome && isset( $tomes[ $tome ] ) ? $tome : null,
			'chapitres'            => $chap_cle,
			'chapitres_annonces'   => $article['chapitres'],
			'liens'                => $article['liens'],
			'contenu_disponible'   => $article['contenu_disponible'],
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Pages, redirections, catégories, navigation, médias
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Pages conservées, remplacées, ignorées et à créer.
	 *
	 * @param array $pages      Pages par ID.
	 * @param array $familles   Famille par ID.
	 * @param array $par_chemin Correspondance chemin → élément du plan.
	 * @param array $hubs       Hubs analysés.
	 * @return array<string,array>
	 */
	private function plan_pages( array $pages, array $familles, array $par_chemin, array $hubs ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature commune des étapes du plan.
		$plan            = array(
			'conserver' => array(),
			'remplacer' => array(),
			'ignorer'   => array(),
			'creer'     => array(),
		);
		$slugs_conserves = array();
		foreach ( $pages as $id => $page ) {
			$famille = $familles[ $id ];
			$chemin  = $this->chemin( $page );
			if ( 'institutionnelle' === $famille ) {
				$remarques = array();
				$wpcom     = substr_count( (string) $page['content'], 'yumenovel.wordpress.com' );
				if ( $wpcom ) {
					$remarques[] = sprintf( '%d lien(s) vers yumenovel.wordpress.com à réécrire.', $wpcom );
				}
				if ( Html::sans_couleurs( (string) $page['content'] ) !== (string) $page['content'] ) {
					$remarques[] = 'Couleurs de fond et de texte en ligne (#efe7fb…) retirées à l’exécution (illisibles dans les thèmes Nuit / Papier).';
				}
				$sans_alt = Html::liens_images_sans_alt( (string) $page['content'] );
				if ( $sans_alt ) {
					$remarques[] = sprintf( '%d lien(s)-image sans texte alternatif : texte alternatif ajouté à l’exécution.', $sans_alt );
					$this->avertir( 'info', 'accessibilite', sprintf( 'Page « %s » : %d lien(s) dont le seul contenu est une image sans texte alternatif ; texte alternatif ajouté à l’exécution d’après la destination du lien.', $page['title'], $sans_alt ), $id );
				}
				$plan['conserver'][] = array(
					'id'        => $id,
					'slug'      => $page['slug'],
					'titre'     => $page['title'],
					'url'       => $chemin,
					'remarques' => $remarques,
				);
				$slugs_conserves[]   = $page['slug'];
				continue;
			}
			if ( 'ignoree' === $famille ) {
				$vide              = '' === trim( Html::texte( (string) $page['content'] ) );
				$plan['ignorer'][] = array(
					'id'     => $id,
					'statut' => $page['status'],
					'titre'  => $page['title'],
					'raison' => ( 'publish' !== $page['status'] ? 'Brouillon' : 'Page' ) . ( $vide ? ' vide' : '' ) . ' : non migré, à supprimer après validation.',
				);
				continue;
			}
			$cible               = $par_chemin[ $chemin ] ?? null;
			$plan['remplacer'][] = array(
				'id'      => $id,
				'slug'    => $page['slug'],
				'titre'   => $page['title'],
				'famille' => $famille,
				'action'  => 'depublier',
				'url'     => $chemin,
				'cible'   => $cible['cle'] ?? null,
			);
		}
		$slugs_occupes = array();
		foreach ( $pages as $id => $page ) {
			if ( 'institutionnelle' !== $familles[ $id ] && 0 === (int) $page['parent'] && '' !== $page['slug'] ) {
				$slugs_occupes[ $page['slug'] ] = $id;
			}
		}
		foreach ( self::PAGES_A_CREER as $cle => $def ) {
			list( $slug, $parent, $titre, $bloc, $reglage ) = $def;
			if ( in_array( $slug, $slugs_conserves, true ) ) {
				$this->avertir( 'erreur', 'pages', sprintf( 'La page à créer « %s » entre en conflit avec une page conservée.', $slug ) );
			} elseif ( '' === $parent && isset( $slugs_occupes[ $slug ] ) ) {
				$this->avertir( 'attention', 'pages', sprintf( 'La page à créer « %s » porte le slug de la page %d, remplacée ou ignorée : elle recevra un slug suffixé.', $slug, $slugs_occupes[ $slug ] ), $slugs_occupes[ $slug ] );
			}
			$plan['creer'][] = array(
				'cle'          => $cle,
				'post_title'   => $titre,
				'post_name'    => $slug,
				'parent'       => '' !== $parent ? $parent : null,
				'post_status'  => 'publish',
				'post_content' => '' !== $bloc ? Blocks::dynamique( $bloc ) : self::contenu_page( $cle ),
				'reglage'      => '' !== $reglage ? $reglage : null,
				'url'          => '/' . ( '' !== $parent ? $parent . '/' : '' ) . $slug . '/',
			);
		}
		return $plan;
	}

	/**
	 * Contenu d'une page créée sans bloc dynamique : l'accueil et la page des articles restent
	 * vides (le thème fournit leur modèle) ; les mentions légales reçoivent un texte de base,
	 * factuel, que l'équipe complète dans l'éditeur.
	 *
	 * @param string $cle Clé de la page (option yume_pages).
	 */
	public static function contenu_page( string $cle ): string {
		if ( 'mentions-legales' !== $cle ) {
			return '';
		}
		$sections = array(
			'Éditeur du site'                   => array(
				'Yume Novel est un site de traductions de fans animé bénévolement par une équipe de passionnés de light novels, de web novels et de mangas. Il n’est rattaché à aucune maison d’édition.',
				'Pour toute demande, écrivez-nous depuis la page <a href="/contactez-nous/">Contactez-nous</a> ou sur notre serveur Discord.',
			),
			'Hébergement'                       => array(
				'Le site est hébergé par WordPress.com, service exploité par Automattic Inc., 60 29th Street #343, San Francisco, CA 94110, États-Unis.',
			),
			'Œuvres, traductions et droits'     => array(
				'Les œuvres présentées appartiennent à leurs auteurs, illustrateurs et éditeurs. Les traductions publiées ici sont des traductions de fans, proposées gratuitement pour faire découvrir des œuvres inédites en français.',
				'Lorsqu’une œuvre est licenciée en France, sa traduction est arrêtée et les fichiers concernés sont retirés. Un ayant droit peut demander le retrait d’un contenu depuis la page <a href="/contactez-nous/">Contactez-nous</a> : la demande est traitée au plus vite.',
				'Certains liens de téléchargement passent par des services tiers (raccourcisseurs de liens, hébergeurs de fichiers) qui appliquent leurs propres conditions.',
			),
			'Données personnelles'              => array(
				'Le site ne recueille que les données nécessaires à son fonctionnement : nom d’utilisateur et adresse e-mail pour un compte lecteur ou un commentaire, favoris, notes, alertes et progression de lecture. Ces données ne sont ni vendues ni cédées.',
				'Depuis la page <a href="/compte/">Mon compte</a>, vous pouvez exporter vos données ou supprimer votre compte. Pour toute autre demande, contactez-nous.',
			),
			'Cookies et stockage du navigateur' => array(
				'Le site utilise des cookies techniques (connexion à un compte) et le stockage local du navigateur pour mémoriser vos préférences de lecture (thème, taille du texte) et votre progression. Yume Novel ne dépose aucun cookie publicitaire.',
				'L’hébergeur peut établir des statistiques de fréquentation agrégées.',
			),
		);
		$blocs    = array();
		foreach ( $sections as $titre => $paragraphes ) {
			$blocs[] = Blocks::titre( Html::attr( $titre ), 2 );
			foreach ( $paragraphes as $paragraphe ) {
				$blocs[] = Blocks::paragraphe( $paragraphe );
			}
		}
		return Blocks::assembler( $blocs );
	}

	/**
	 * Table des redirections 301 (ancien chemin → nouvelle URL relative).
	 *
	 * @param array $pages      Pages.
	 * @param array $familles   Familles.
	 * @param array $par_chemin Correspondance chemin → élément.
	 * @param array $oeuvres    Œuvres.
	 * @param array $tomes      Tomes.
	 * @param array $chapitres  Chapitres.
	 * @param array $hubs       Hubs.
	 * @param array $categories Catégories de l'export.
	 * @return array<int,array<string,mixed>>
	 */
	private function plan_redirections( array $pages, array $familles, array $par_chemin, array $oeuvres, array $tomes, array $chapitres, array $hubs, array $categories ): array {
		$redirections = array();
		$ajouter      = function ( string $source, string $cible, string $type, ?int $id, ?string $cle ) use ( &$redirections ): void {
			if ( '' === $source || $source === $cible ) {
				return;
			}
			$redirections[ $source ] = array(
				'source'    => $source,
				'cible'     => $cible,
				'code'      => 301,
				'type'      => $type,
				'source_id' => $id,
				'cle'       => $cle,
			);
		};
		foreach ( $pages as $id => $page ) {
			$chemin = $this->chemin( $page );
			$cible  = $par_chemin[ $chemin ] ?? null;
			switch ( $familles[ $id ] ) {
				case 'fiche':
				case 'arc':
				case 'chapitre':
					if ( null === $cible ) {
						$this->avertir( 'erreur', 'redirection', sprintf( 'Page « %s » : aucune cible de redirection.', $page['title'] ), $id );
						break;
					}
					$url = $this->url_publiee( $cible, $oeuvres, $tomes, $chapitres );
					if ( $url !== $this->url_element( $cible, $oeuvres, $tomes, $chapitres ) ) {
						$this->avertir( 'info', 'redirection', sprintf( 'Page « %s » : « %s » n’est pas publié, redirection vers %s.', $page['title'], $this->url_element( $cible, $oeuvres, $tomes, $chapitres ), $url ), $id );
					}
					$ajouter( $chemin, $url, $cible['type'], $id, $cible['cle'] );
					break;
				case 'hub':
					$type = $hubs[ $id ]['type'] ?? 'light-novel';
					$ajouter( $chemin, '/bibliotheque/?type=' . $type, 'hub', $id, null );
					break;
				case 'categorie':
					$slug = $this->categorie_de_page( $page, $categories );
					if ( null !== $slug ) {
						$ajouter( $chemin, '/category/' . $this->slug_categorie_cible( $slug ) . '/', 'categorie', $id, null );
					}
					break;
			}
		}
		foreach ( $categories as $cat ) {
			$cible = $this->slug_categorie_cible( $cat['slug'] );
			if ( $cible !== $cat['slug'] ) {
				$ajouter( '/category/' . $cat['slug'] . '/', '/category/' . $cible . '/', 'categorie', null, null );
			}
		}
		ksort( $redirections );
		return array_values( $redirections );
	}

	/**
	 * URL d'un élément du plan (œuvre, tome ou chapitre).
	 *
	 * @param array $cible     { type, cle }.
	 * @param array $oeuvres   Œuvres.
	 * @param array $tomes     Tomes.
	 * @param array $chapitres Chapitres.
	 */
	private function url_element( array $cible, array $oeuvres, array $tomes, array $chapitres ): string {
		if ( 'oeuvre' === $cible['type'] ) {
			return $oeuvres[ $cible['cle'] ]['url'];
		}
		return 'tome' === $cible['type'] ? $tomes[ $cible['cle'] ]['url'] : $chapitres[ $cible['cle'] ]['url'];
	}

	/**
	 * URL publique la plus proche d'un élément : l'élément s'il est publié, sinon son tome
	 * publié, sinon son œuvre (un tome ou un chapitre planifié reste en brouillon, et une
	 * redirection vers un brouillon aboutirait à une erreur 404).
	 *
	 * @param array $cible     { type, cle }.
	 * @param array $oeuvres   Œuvres.
	 * @param array $tomes     Tomes.
	 * @param array $chapitres Chapitres.
	 */
	private function url_publiee( array $cible, array $oeuvres, array $tomes, array $chapitres ): string {
		if ( 'chapitre' === $cible['type'] ) {
			$chapitre = $chapitres[ $cible['cle'] ];
			if ( 'publish' === $chapitre['post']['post_status'] ) {
				return $chapitre['url'];
			}
			$cible = array(
				'type' => 'tome',
				'cle'  => $chapitre['tome'],
			);
		}
		if ( 'tome' === $cible['type'] ) {
			$tome = $tomes[ $cible['cle'] ];
			if ( 'publish' === $tome['post']['post_status'] ) {
				return $tome['url'];
			}
			$cible = array(
				'type' => 'oeuvre',
				'cle'  => $tome['oeuvre'],
			);
		}
		return $oeuvres[ $cible['cle'] ]['url'];
	}

	/**
	 * Slug de catégorie cible d'une catégorie existante.
	 *
	 * @param string $slug Slug existant.
	 */
	private function slug_categorie_cible( string $slug ): string {
		if ( 'yume-news' === $slug || 'sorties' === $slug ) {
			return Legacy_Post_Parser::CATEGORIE_SORTIES;
		}
		return Legacy_Post_Parser::CATEGORIE_ACTUALITES;
	}

	/**
	 * Catégorie listée par une page vide (« Yume News », « Actualités »).
	 *
	 * @param array $page       Page.
	 * @param array $categories Catégories.
	 */
	private function categorie_de_page( array $page, array $categories ): ?string {
		$n = Html::normaliser( $page['slug'] . ' ' . $page['title'] );
		foreach ( $categories as $cat ) {
			$c = Html::normaliser( $cat['slug'] );
			if ( str_contains( $n, $c ) || str_contains( $c, Html::normaliser( $page['slug'] ) ) ) {
				return $cat['slug'];
			}
		}
		return null;
	}

	/**
	 * Actions sur les catégories : « Yume News » renommée « Sorties », « Actualités »
	 * conservée, « Non classé » supprimée après reclassement.
	 *
	 * @param array $categories Catégories de l'export.
	 * @param array $articles   Articles du plan.
	 * @return array<int,array<string,mixed>>
	 */
	private function plan_categories( array $categories, array $articles ): array {
		$plan     = array();
		$a_sortie = false;
		foreach ( $categories as $cat ) {
			switch ( $cat['slug'] ) {
				case 'yume-news':
					$a_sortie = true;
					$plan[]   = array(
						'action'      => 'renommer',
						'id'          => $cat['id'],
						'slug_actuel' => $cat['slug'],
						'nom_actuel'  => $cat['name'],
						'slug'        => Legacy_Post_Parser::CATEGORIE_SORTIES,
						'nom'         => 'Sorties',
						'description' => 'Sorties de tomes et de chapitres.',
					);
					break;
				case 'actualites':
					$plan[] = array(
						'action'      => 'conserver',
						'id'          => $cat['id'],
						'slug_actuel' => $cat['slug'],
						'nom_actuel'  => $cat['name'],
						'slug'        => Legacy_Post_Parser::CATEGORIE_ACTUALITES,
						'nom'         => 'Actualités',
						'description' => 'Annonces, licences, couvertures et vie de l’équipe.',
					);
					break;
				default:
					$plan[] = array(
						'action'      => 'supprimer',
						'id'          => $cat['id'],
						'slug_actuel' => $cat['slug'],
						'nom_actuel'  => $cat['name'],
						'condition'   => 'Après reclassement des articles ; définir d’abord l’option default_category sur « Actualités ».',
					);
			}
		}
		if ( ! $a_sortie ) {
			$plan[] = array(
				'action'      => 'creer',
				'id'          => null,
				'slug'        => Legacy_Post_Parser::CATEGORIE_SORTIES,
				'nom'         => 'Sorties',
				'description' => 'Sorties de tomes et de chapitres.',
			);
		}
		$non_classes = count( array_filter( $articles, static fn( $a ) => in_array( 'non-classe', $a['categories_slugs'], true ) ) );
		if ( $non_classes ) {
			$this->avertir( 'info', 'article', sprintf( '%d article(s) en « Non classé » reclassé(s).', $non_classes ) );
		}
		return $plan;
	}

	/**
	 * Correspondance des liens des anciens menus avec les nouvelles URL.
	 *
	 * @param array $redirections Redirections.
	 * @param array $pages_plan   Plan des pages.
	 * @return array<string,mixed>
	 */
	private function plan_navigation( array $redirections, array $pages_plan ): array {
		$par_source = array_column( $redirections, 'cible', 'source' );
		$conserves  = array_column( $pages_plan['conserver'], 'url' );
		$liens      = array();
		foreach ( $this->export['navigations'] as $nav ) {
			if ( ! preg_match_all( '/<a\s[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', (string) ( $nav['content'] ?? '' ), $m, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $m as $lien ) {
				$url    = Html::decoder( $lien[1] );
				$chemin = Html::chemin_local( $url, $this->domaines );
				$cible  = '';
				if ( '' !== $chemin ) {
					$cible = $par_source[ $chemin ] ?? ( in_array( $chemin, $conserves, true ) ? $chemin : '' );
				}
				$liens[] = array(
					'menu'    => (string) ( $nav['title'] ?? '' ),
					'libelle' => Html::texte( $lien[2] ),
					'url'     => $url,
					'cible'   => '' !== $cible ? $cible : ( '' === $chemin ? $url : null ),
					'wpcom'   => false !== strpos( $url, 'yumenovel.wordpress.com' ),
				);
			}
		}
		return array(
			'remarque' => 'Le thème yume fournit l’en-tête et le pied de page : les anciennes navigations ne sont pas migrées ; cette table sert à vérifier qu’aucun lien ne se perd.',
			'liens'    => $liens,
		);
	}

	/**
	 * Médias référencés par le plan (couvertures, bannières, illustrations).
	 *
	 * @param array $oeuvres   Œuvres.
	 * @param array $tomes     Tomes.
	 * @param array $chapitres Chapitres.
	 * @return array<string,array>
	 */
	private function plan_medias( array $oeuvres, array $tomes, array $chapitres ): array {
		$usages = array();
		foreach ( $oeuvres as $o ) {
			if ( $o['thumbnail_id'] ) {
				$usages[ $o['thumbnail_id'] ][] = 'oeuvre:' . $o['cle'] . ':image';
			}
			if ( $o['meta']['yume_banniere_id'] ) {
				$usages[ $o['meta']['yume_banniere_id'] ][] = 'oeuvre:' . $o['cle'] . ':banniere';
			}
		}
		foreach ( $tomes as $t ) {
			if ( $t['thumbnail_id'] ) {
				$usages[ $t['thumbnail_id'] ][] = 'tome:' . $t['cle'] . ':couverture';
			}
		}
		foreach ( $chapitres as $c ) {
			foreach ( $c['illustrations'] as $id ) {
				$usages[ $id ][] = 'chapitre:' . $c['cle'] . ':illustration';
			}
		}
		$banniere = $this->plan_reglages()['banniere_id'];
		if ( $banniere ) {
			$usages[ $banniere ][] = 'reglages:banniere_id';
		}
		ksort( $usages );
		$references = array();
		$manquants  = array();
		$connues    = null;
		foreach ( $usages as $id => $liste ) {
			$existe = isset( $this->medias[ $id ] );
			$url    = $existe ? (string) $this->medias[ $id ]['source_url'] : '';
			if ( ! $existe ) {
				// URL connue par le contenu (src de l'image, couverture d'un tome, bannière d'un
				// hub) : l'exécution retrouve le fichier sous un autre ID (suffixe, puis nom).
				$connues = $connues ?? $this->urls_images_connues( $tomes );
				$url     = $connues[ (int) $id ] ?? '';
			}
			$references[] = array(
				'id'     => (int) $id,
				'url'    => $url,
				'existe' => $existe,
				'usages' => $liste,
			);
			if ( ! $existe ) {
				$manquants[] = (int) $id;
				$fichier     = '' !== $url ? Media_Mapper::suffixe( $url ) : '';
				$this->avertir(
					'attention',
					'media',
					sprintf(
						'Média %d référencé (%s) introuvable sous cet ID dans la médiathèque : %s',
						$id,
						implode( ', ', $liste ),
						'' !== $fichier || '' !== $url
							? sprintf( 'il sera recherché par son fichier (« %s ») à l’exécution, sinon l’image restera vide.', '' !== $fichier ? $fichier : $url )
							: 'fichier inconnu, l’image restera vide (à reposer à la main après la migration).'
					)
				);
			}
		}
		return array(
			'references' => $references,
			'manquants'  => $manquants,
		);
	}

	/**
	 * URL des images connues par leur ID dans les contenus de l'ancien site (classe wp-image-N
	 * ou attribut id des blocs image et media-text), les couvertures des tomes et les bannières
	 * des hubs.
	 *
	 * @param array $tomes Tomes du plan.
	 * @return array<int,string> ID => URL.
	 */
	private function urls_images_connues( array $tomes ): array {
		$urls     = array();
		$contenus = array();
		foreach ( array( 'pages', 'posts', 'template_parts' ) as $liste ) {
			foreach ( (array) ( $this->export[ $liste ] ?? array() ) as $item ) {
				$contenus[] = (string) ( $item['content'] ?? '' );
			}
		}
		foreach ( $contenus as $contenu ) {
			if ( ! preg_match_all( '/<img\s[^>]*>/i', $contenu, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $img ) {
				if ( preg_match( '/wp-image-(\d+)/', $img, $id ) && preg_match( '/\ssrc="([^"]+)"/i', $img, $src ) && ! isset( $urls[ (int) $id[1] ] ) ) {
					$urls[ (int) $id[1] ] = Html::decoder( $src[1] );
				}
			}
		}
		foreach ( $tomes as $t ) {
			if ( ! empty( $t['thumbnail_id'] ) && '' !== (string) ( $t['couverture_url'] ?? '' ) ) {
				$urls[ (int) $t['thumbnail_id'] ] = (string) $t['couverture_url'];
			}
		}
		return $urls;
	}

	/**
	 * Réglages suggérés (option yume_reglages) : bannière actuelle du site (partie « header »).
	 *
	 * @return array<string,mixed>
	 */
	private function plan_reglages(): array {
		$banniere = 0;
		foreach ( $this->export['template_parts'] as $partie ) {
			if ( 'header' === ( $partie['slug'] ?? '' ) && preg_match( '/<!-- wp:image \{[^}]*"id":(\d+)/', (string) ( $partie['content'] ?? '' ), $m ) ) {
				$banniere = (int) $m[1];
			}
		}
		return array( 'banniere_id' => $banniere );
	}

	/**
	 * Anomalies globales relevées dans l'export.
	 *
	 * @param array $pages      Pages.
	 * @param array $familles   Familles.
	 * @param array $chapitres  Chapitres analysés (par ID de page).
	 * @param array $posts      Articles.
	 * @param array $fiches     Fiches.
	 * @param array $categories Catégories (ID → slug).
	 */
	private function anomalies( array $pages, array $familles, array $chapitres, array $posts, array $fiches, array $categories ): void {
		// Chapitres créés dans le désordre (ID de page décroissant alors que le numéro croît).
		$par_arc = array();
		foreach ( $chapitres as $id => $c ) {
			$par_arc[ $c['tome_numero'] ][ (string) $c['numero'] ] = $id;
		}
		foreach ( $par_arc as $arc => $liste ) {
			uksort( $liste, static fn( $a, $b ) => (float) $a <=> (float) $b );
			$precedent = 0;
			foreach ( $liste as $numero => $id ) {
				if ( $id < $precedent ) {
					$this->avertir( 'info', 'chapitre', sprintf( 'T.%d – chapitre %s (page %d) créé avant le chapitre précédent (page %d).', $arc, $numero, $id, $precedent ), $id );
				}
				$precedent = max( $precedent, $id );
			}
		}
		// Liens internes vers l'ancien domaine WordPress.com.
		$wpcom_pages = 0;
		$wpcom_liens = 0;
		foreach ( $pages as $page ) {
			$n = substr_count( (string) $page['content'], 'yumenovel.wordpress.com/' ) - substr_count( (string) $page['content'], 'yumenovel.wordpress.com/wp-content/' );
			if ( $n > 0 ) {
				++$wpcom_pages;
				$wpcom_liens += $n;
			}
		}
		if ( $wpcom_liens ) {
			$this->avertir( 'info', 'liens', sprintf( '%d lien(s) interne(s) vers yumenovel.wordpress.com dans %d page(s) (hors images) : couverts par les redirections et la redirection de domaine de WordPress.com.', $wpcom_liens, $wpcom_pages ) );
		}
		// Articles à plusieurs catégories.
		$multi = count( array_filter( $posts, static fn( $p ) => count( $p['categories'] ) > 1 ) );
		if ( $multi ) {
			$this->avertir( 'info', 'article', sprintf( '%d article(s) rangé(s) dans plusieurs catégories : une seule catégorie cible leur est attribuée.', $multi ) );
		}
		$sans_contenu = count( array_filter( $posts, static fn( $p ) => null === $p['content'] ) );
		if ( $sans_contenu ) {
			$this->avertir( 'attention', 'export', sprintf( '%d article(s) sans contenu dans l’export : liens PDF / EPUB des articles non vérifiés pour ceux-ci.', $sans_contenu ) );
		}
		unset( $familles, $fiches, $categories );
	}

	/**
	 * Comptes du plan.
	 *
	 * @param array $plan     Plan.
	 * @param array $familles Familles des pages.
	 * @param array $export   Export.
	 * @return array<string,mixed>
	 */
	private function comptes( array $plan, array $familles, array $export ): array {
		$compter    = static function ( array $liste, callable $cle ): array {
			$c = array();
			foreach ( $liste as $e ) {
				$k       = (string) $cle( $e );
				$c[ $k ] = ( $c[ $k ] ?? 0 ) + 1;
			}
			ksort( $c );
			return $c;
		};
		$hebergeurs = array();
		$pdf        = 0;
		$epub       = 0;
		foreach ( $plan['tomes'] as $t ) {
			foreach ( array( 'yume_lien_pdf', 'yume_lien_epub' ) as $meta ) {
				$url = $t['meta'][ $meta ];
				if ( '' === $url ) {
					continue;
				}
				'yume_lien_pdf' === $meta ? ++$pdf : ++$epub;
				$hote                = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
				$hebergeurs[ $hote ] = ( $hebergeurs[ $hote ] ?? 0 ) + 1;
			}
		}
		arsort( $hebergeurs );
		$par_oeuvre = array();
		foreach ( $plan['tomes'] as $t ) {
			$par_oeuvre[ $t['oeuvre'] ] = ( $par_oeuvre[ $t['oeuvre'] ] ?? 0 ) + 1;
		}
		return array(
			'pages'          => array(
				'total'       => count( $export['pages'] ),
				'par_famille' => $compter( array_values( $familles ), static fn( $f ) => $f ),
				'par_statut'  => $compter( $export['pages'], static fn( $p ) => $p['status'] ),
			),
			'articles'       => array(
				'total'                => count( $plan['articles'] ),
				'par_classement'       => $compter( $plan['articles'], static fn( $a ) => $a['classement'] ),
				'par_statut'           => $compter( $plan['articles'], static fn( $a ) => $a['status'] ),
				'a_ignorer'            => count( array_filter( $plan['articles'], static fn( $a ) => 'ignorer' === $a['action'] ) ),
				'avec_oeuvre'          => count( array_filter( $plan['articles'], static fn( $a ) => null !== $a['oeuvre'] ) ),
				'sorties_sans_oeuvre'  => count( array_filter( $plan['articles'], static fn( $a ) => 'sortie' === $a['classement'] && null === $a['oeuvre'] ) ),
				'non_classe'           => count( array_filter( $plan['articles'], static fn( $a ) => in_array( 'non-classe', $a['categories_slugs'], true ) ) ),
				'par_categorie_source' => $compter( $export['posts'], static fn( $p ) => implode( '+', $p['categories'] ) ),
			),
			'oeuvres'        => array(
				'total'      => count( $plan['oeuvres'] ),
				'par_type'   => $compter( $plan['oeuvres'], static fn( $o ) => $o['termes']['yume_type'][0] ),
				'par_statut' => $compter( $plan['oeuvres'], static fn( $o ) => $o['termes']['yume_statut'][0] ),
			),
			'tomes'          => array(
				'total'      => count( $plan['tomes'] ),
				'par_nature' => $compter( $plan['tomes'], static fn( $t ) => $t['meta']['yume_nature'] ),
				'par_statut' => $compter( $plan['tomes'], static fn( $t ) => $t['post']['post_status'] ),
				'par_oeuvre' => $par_oeuvre,
			),
			'chapitres'      => array(
				'total'                        => count( $plan['chapitres'] ),
				'migres'                       => count( array_filter( $plan['chapitres'], static fn( $c ) => 'page' === $c['source']['type'] ) ),
				'planifies'                    => count( array_filter( $plan['chapitres'], static fn( $c ) => 'annonce' === $c['source']['type'] ) ),
				'par_tome'                     => $compter( $plan['chapitres'], static fn( $c ) => $c['tome'] ),
				'mots'                         => array_sum( array_map( static fn( $c ) => (int) $c['meta']['yume_nb_mots'], $plan['chapitres'] ) ),
				'dialogues'                    => array_sum( array_map( static fn( $c ) => (int) ( $c['stats']['dialogues'] ?? 0 ), $plan['chapitres'] ) ),
				'pensees'                      => array_sum( array_map( static fn( $c ) => (int) ( $c['stats']['pensees'] ?? 0 ), $plan['chapitres'] ) ),
				'illustrations'                => array_sum( array_map( static fn( $c ) => count( $c['illustrations'] ), $plan['chapitres'] ) ),
				'images_navigation_supprimees' => array_sum( array_map( static fn( $c ) => count( $c['navigation_supprimee'] ), $plan['chapitres'] ) ),
			),
			'liens'          => array(
				'pdf'        => $pdf,
				'epub'       => $epub,
				'hebergeurs' => $hebergeurs,
			),
			'redirections'   => count( $plan['redirections'] ),
			'pages_plan'     => array_map( 'count', $plan['pages'] ),
			'medias'         => array(
				'exportes'   => count( $export['media'] ),
				'references' => count( $plan['medias']['references'] ),
				'manquants'  => count( $plan['medias']['manquants'] ),
			),
			'avertissements' => $compter( $plan['avertissements'], static fn( $a ) => $a['niveau'] ),
		);
	}
}
