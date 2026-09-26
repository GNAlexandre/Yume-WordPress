<?php
/**
 * Annulation de la migration : retire les redirections, restaure les catégories (y compris
 * « Non classé », recréée sous son ID d'origine), les catégories et œuvres liées des articles,
 * les options (lecture, yume_pages, yume_reglages, catégorie par défaut) et les statuts des
 * anciennes pages ; supprime les pages, chapitres, tomes et œuvres créés par la migration
 * (méta _yume_migration_cle) ; puis compare l'empreinte des contenus touchés avec celle prise
 * avant l'exécution.
 *
 * Les listes à traiter sont figées au démarrage de l'annulation (état « annulation ») pour
 * que la reprise après interruption traite exactement les mêmes éléments.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

// Messages d'exception internes, échappés là où ils sont affichés (esc_html dans
// l'administration, WP_Error en JSON pour l'API REST, WP-CLI en console).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Étapes de l'annulation.
 */
final class Migration_Rollback extends Migration_Moteur {

	/**
	 * Étapes.
	 *
	 * @return array<string,string>
	 */
	public static function etapes(): array {
		return array(
			'redirections'     => __( 'Retrait des redirections', 'yume-core' ),
			'categories'       => __( 'Catégories', 'yume-core' ),
			'articles'         => __( 'Articles', 'yume-core' ),
			'reglages'         => __( 'Réglages et options', 'yume-core' ),
			'pages_conservees' => __( 'Pages conservées', 'yume-core' ),
			'anciennes_pages'  => __( 'Anciennes pages', 'yume-core' ),
			'pages'            => __( 'Pages Yume', 'yume-core' ),
			'chapitres'        => __( 'Chapitres', 'yume-core' ),
			'tomes'            => __( 'Tomes', 'yume-core' ),
			'oeuvres'          => __( 'Œuvres', 'yume-core' ),
			'terminer'         => __( 'Contrôle final', 'yume-core' ),
		);
	}

	/**
	 * Listes à traiter (calculées au démarrage de l'annulation).
	 *
	 * @param array $journal Journal.
	 * @return array<string,int[]>
	 */
	public static function listes( array $journal ): array {
		global $wpdb;
		$listes = array(
			'articles'         => array_map( 'intval', array_keys( (array) ( $journal['sauvegarde']['articles'] ?? array() ) ) ),
			'anciennes_pages'  => array_map( 'intval', array_keys( (array) ( $journal['sauvegarde']['pages'] ?? array() ) ) ),
			'pages_conservees' => array_map( 'intval', array_keys( (array) ( $journal['modifications']['pages_nettoyees'] ?? array() ) ) ),
			'pages'            => array(),
			'chapitres'        => array(),
			'tomes'            => array(),
			'oeuvres'          => array(),
		);
		$types  = array(
			'page'          => 'pages',
			'yume_chapitre' => 'chapitres',
			'yume_tome'     => 'tomes',
			'yume_oeuvre'   => 'oeuvres',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$lignes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_type AS type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s ORDER BY p.ID ASC",
				Site_Source::META_CLE
			)
		);
		foreach ( (array) $lignes as $ligne ) {
			if ( isset( $types[ $ligne->type ] ) ) {
				$listes[ $types[ $ligne->type ] ][] = (int) $ligne->id;
			}
		}
		foreach ( array_keys( (array) ( $journal['modifications']['pages_adoptees'] ?? array() ) ) as $id ) {
			$listes['pages'][] = (int) $id;
		}
		foreach ( $listes as $cle => $ids ) {
			$listes[ $cle ] = array_values( array_unique( $ids ) );
		}
		return $listes;
	}

	/**
	 * Tables des modules qui gardent des données des lecteurs sur les contenus : table (sans
	 * préfixe) => [ colonnes d'ID de contenu, libellé ].
	 *
	 * @return array<string,array{0:string[],1:string}>
	 */
	private static function tables_lecteurs(): array {
		$tables = array(
			'yume_favoris'     => array( array( 'oeuvre_id' ), __( 'favori(s) de lecteurs', 'yume-core' ) ),
			'yume_notes'       => array( array( 'oeuvre_id' ), __( 'note(s) de lecteurs', 'yume-core' ) ),
			'yume_progression' => array( array( 'oeuvre_id', 'tome_id', 'chapitre_id' ), __( 'progression(s) de lecture', 'yume-core' ) ),
		);
		/**
		 * Tables dont les lignes rattachées à un contenu migré empêchent de le supprimer à
		 * l'annulation (table sans préfixe => [ colonnes, libellé ]).
		 *
		 * @param array $tables Tables.
		 */
		return (array) apply_filters( 'yume_migration_tables_dependantes', $tables );
	}

	/**
	 * Ce qui a été fait sur le site depuis la migration et qu'une annulation détruirait ou
	 * rendrait orphelin : contenus non créés par la migration rattachés à une œuvre ou à un tome
	 * migré (chapitres importés, tomes planifiés), données des lecteurs (favoris, notes,
	 * progression), commentaires, contenus migrés modifiés depuis (texte publié, étape,
	 * liens).
	 *
	 * Pour les conserver, l'unité est l'œuvre : une œuvre concernée est gardée avec tous ses
	 * tomes et chapitres créés par la migration (liste « conserver »).
	 *
	 * @param array $listes Listes de l'annulation (listes()).
	 * @param array $etat   État de la migration (migre_le).
	 * @param array $journal Journal (pages adoptées, jamais supprimées).
	 * @return array{contenus:array,lignes:array,commentaires:int,modifies:int[],conserver:int[]}
	 */
	public static function dependances( array $listes, array $etat, array $journal = array() ): array {
		global $wpdb;
		$adoptees = array_map( 'intval', array_keys( (array) ( $journal['modifications']['pages_adoptees'] ?? array() ) ) );
		$migres   = array();
		foreach ( array( 'pages', 'chapitres', 'tomes', 'oeuvres' ) as $liste ) {
			foreach ( (array) ( $listes[ $liste ] ?? array() ) as $id ) {
				if ( ! in_array( (int) $id, $adoptees, true ) ) {
					$migres[ (int) $id ] = true;
				}
			}
		}
		$resultat = array(
			'contenus'     => array(),
			'lignes'       => array(),
			'commentaires' => 0,
			'modifies'     => array(),
			'conserver'    => array(),
		);
		if ( ! $migres ) {
			return $resultat;
		}
		$touches = array();

		// Contenus non créés par la migration qui pointent vers une œuvre ou un tome migré.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$lignes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_type AS type, p.post_title AS titre, m.meta_value AS cible FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( 'yume_oeuvre_id', 'yume_tome_id' )
				LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s
				WHERE k.meta_id IS NULL AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND p.post_type <> 'revision'",
				Site_Source::META_CLE
			)
		);
		foreach ( (array) $lignes as $ligne ) {
			if ( isset( $migres[ (int) $ligne->cible ] ) ) {
				$resultat['contenus'][ (int) $ligne->id ] = array(
					'type'  => (string) $ligne->type,
					'titre' => (string) $ligne->titre,
					'cible' => (int) $ligne->cible,
				);
				$touches[ (int) $ligne->cible ]           = true;
			}
		}

		// Commentaires.
		$lignes = $wpdb->get_results( "SELECT comment_post_ID AS id, COUNT(*) AS n FROM {$wpdb->comments} WHERE comment_approved NOT IN ( 'trash', 'spam' ) GROUP BY comment_post_ID" );
		foreach ( (array) $lignes as $ligne ) {
			if ( isset( $migres[ (int) $ligne->id ] ) ) {
				$resultat['commentaires']   += (int) $ligne->n;
				$touches[ (int) $ligne->id ] = true;
			}
		}

		// Données des lecteurs.
		foreach ( self::tables_lecteurs() as $table => $def ) {
			list( $colonnes, $libelle ) = $def;
			$nom                        = $wpdb->prefix . $table;
			if ( $nom !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $nom ) ) ) ) {
				continue;
			}
			$colonnes = array_values( array_filter( (array) $colonnes, static fn( $c ) => (bool) preg_match( '/^[a-z_]+$/', (string) $c ) ) );
			if ( ! $colonnes ) {
				continue;
			}
			// Noms de table et de colonnes fixés par le code (motif vérifié ci-dessus).
			$requete = 'SELECT ' . implode( ', ', $colonnes ) . " FROM `{$nom}`";
			$rangs   = $wpdb->get_results( $requete, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$n       = 0;
			foreach ( (array) $rangs as $rang ) {
				$lie = false;
				foreach ( $rang as $valeur ) {
					if ( isset( $migres[ (int) $valeur ] ) ) {
						$touches[ (int) $valeur ] = true;
						$lie                      = true;
					}
				}
				$n += $lie ? 1 : 0;
			}
			if ( $n ) {
				$resultat['lignes'][ $table ] = array(
					'nombre'  => $n,
					'libelle' => (string) $libelle,
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		// Contenus migrés modifiés depuis la migration.
		$migre_le = (string) ( $etat['migre_le'] ?? '' );
		foreach ( array_keys( $migres ) as $id ) {
			if ( Migration_Executor::modifie_depuis_migration( (int) $id, $migre_le ) ) {
				$resultat['modifies'][] = (int) $id;
				$touches[ (int) $id ]   = true;
			}
		}

		// Conservation par œuvre : l'œuvre de chaque contenu touché, avec tout ce qui en dépend.
		$oeuvres = array();
		$garder  = array();
		foreach ( array_keys( $touches ) as $id ) {
			$type = get_post_type( (int) $id );
			if ( 'yume_oeuvre' === $type ) {
				$oeuvres[ (int) $id ] = true;
			} elseif ( 'yume_tome' === $type || 'yume_chapitre' === $type ) {
				$oeuvre = (int) get_post_meta( (int) $id, 'yume_oeuvre_id', true );
				if ( ! $oeuvre && 'yume_chapitre' === $type ) {
					$oeuvre = (int) get_post_meta( (int) get_post_meta( (int) $id, 'yume_tome_id', true ), 'yume_oeuvre_id', true );
				}
				if ( $oeuvre && isset( $migres[ $oeuvre ] ) ) {
					$oeuvres[ $oeuvre ] = true;
				}
				$garder[ (int) $id ] = true;
			} else {
				$garder[ (int) $id ] = true;
			}
		}
		foreach ( array( 'tomes', 'chapitres' ) as $liste ) {
			foreach ( (array) ( $listes[ $liste ] ?? array() ) as $id ) {
				if ( isset( $oeuvres[ (int) get_post_meta( (int) $id, 'yume_oeuvre_id', true ) ] ) ) {
					$garder[ (int) $id ] = true;
				}
			}
		}
		foreach ( array_keys( $oeuvres ) as $id ) {
			$garder[ (int) $id ] = true;
		}
		$garder = array_map( 'intval', array_keys( $garder ) );
		sort( $garder );
		$resultat['conserver'] = $garder;
		return $resultat;
	}

	/**
	 * Résumé lisible des dépendances (une ligne par nature).
	 *
	 * @param array $dependances Résultat de dependances().
	 * @return string[]
	 */
	public static function resume_dependances( array $dependances ): array {
		$lignes = array();
		if ( $dependances['contenus'] ) {
			$exemples = array();
			foreach ( array_slice( $dependances['contenus'], 0, 5, true ) as $id => $c ) {
				$exemples[] = sprintf( '« %s » (%d)', $c['titre'], $id );
			}
			/* translators: 1: nombre, 2: exemples. */
			$lignes[] = sprintf( __( '%1$d contenu(s) ajouté(s) depuis la migration et rattaché(s) à une œuvre ou un tome migré (chapitres importés, tomes planifiés) : %2$s', 'yume-core' ), count( $dependances['contenus'] ), implode( ', ', $exemples ) . ( count( $dependances['contenus'] ) > 5 ? '…' : '' ) );
		}
		foreach ( $dependances['lignes'] as $ligne ) {
			/* translators: 1: nombre, 2: nature (favori(s) de lecteurs, note(s) de lecteurs…). */
			$lignes[] = sprintf( __( '%1$d %2$s', 'yume-core' ), (int) $ligne['nombre'], $ligne['libelle'] );
		}
		if ( $dependances['commentaires'] ) {
			/* translators: %d: nombre. */
			$lignes[] = sprintf( __( '%d commentaire(s) sur des contenus migrés', 'yume-core' ), (int) $dependances['commentaires'] );
		}
		if ( $dependances['modifies'] ) {
			$exemples = array();
			foreach ( array_slice( $dependances['modifies'], 0, 5 ) as $id ) {
				$exemples[] = sprintf( '« %s » (%d)', (string) get_post_field( 'post_title', (int) $id ), (int) $id );
			}
			/* translators: 1: nombre, 2: exemples. */
			$lignes[] = sprintf( __( '%1$d contenu(s) migré(s) modifié(s) depuis la migration (texte, statut, étape, liens) : %2$s', 'yume-core' ), count( $dependances['modifies'] ), implode( ', ', $exemples ) . ( count( $dependances['modifies'] ) > 5 ? '…' : '' ) );
		}
		return $lignes;
	}

	/**
	 * Sauvegarde reconstruite depuis le plan quand le journal a été perdu : anciennes pages
	 * remplacées remises en ligne (elles étaient publiées), noms et slugs d'origine des
	 * catégories, catégories d'origine des articles, catégorie par défaut. Les autres options
	 * (lecture, inscription, options Yume) ne sont pas connues et restent à vérifier à la main.
	 *
	 * @param array $plan    Plan exécuté.
	 * @param array $journal Journal (sans sauvegarde).
	 * @return array<string,mixed> Journal complété.
	 */
	public static function reconstruire_sauvegarde( array $plan, array $journal ): array {
		$sauvegarde = array(
			'options'    => array(),
			'pages'      => array(),
			'articles'   => array(),
			'categories' => array(),
		);
		foreach ( (array) ( $plan['pages']['remplacer'] ?? array() ) as $page ) {
			$post = get_post( (int) $page['id'] );
			if ( $post instanceof \WP_Post ) {
				$sauvegarde['pages'][ (int) $post->ID ] = array(
					'post_status'   => 'publish',
					'post_modified' => $post->post_modified,
				);
			}
		}
		foreach ( (array) ( $plan['articles'] ?? array() ) as $article ) {
			if ( 'reclasser' === ( $article['action'] ?? '' ) && get_post( (int) $article['source_id'] ) ) {
				$sauvegarde['articles'][ (int) $article['source_id'] ] = array(
					'categories'  => array_map( 'intval', (array) ( $article['categories_actuelles'] ?? array() ) ),
					'oeuvre_liee' => array(),
				);
			}
		}
		$modifs = (array) ( $journal['modifications'] ?? array() );
		foreach ( (array) ( $plan['categories'] ?? array() ) as $cat ) {
			if ( 'creer' === ( $cat['action'] ?? '' ) ) {
				$terme = get_term_by( 'slug', (string) $cat['slug'], 'category' );
				if ( $terme instanceof \WP_Term ) {
					$modifs['categories_creees'][] = (int) $terme->term_id;
				}
				continue;
			}
			if ( empty( $cat['id'] ) ) {
				continue;
			}
			$id    = (int) $cat['id'];
			$terme = get_term( $id, 'category' );
			// Description d'origine : celle d'aujourd'hui, sauf si c'est celle posée par la migration.
			$description                     = $terme instanceof \WP_Term && (string) ( $cat['description'] ?? '' ) !== (string) $terme->description ? (string) $terme->description : '';
			$sauvegarde['categories'][ $id ] = array(
				'term_id'          => $id,
				'term_taxonomy_id' => $terme instanceof \WP_Term ? (int) $terme->term_taxonomy_id : 0,
				'name'             => (string) ( $cat['nom_actuel'] ?? '' ),
				'slug'             => (string) ( $cat['slug_actuel'] ?? '' ),
				'term_group'       => 0,
				'description'      => $description,
				'parent'           => $terme instanceof \WP_Term ? (int) $terme->parent : 0,
			);
			if ( $terme instanceof \WP_Term ) {
				$modifs['categories_modifiees'][] = $id;
			} else {
				$modifs['categories_supprimees'][] = $id;
			}
			if ( 'supprimer' === ( $cat['action'] ?? '' ) && 'non-classe' === ( $cat['slug_actuel'] ?? '' ) ) {
				$sauvegarde['options']['default_category'] = array(
					'existe' => true,
					'valeur' => $id,
				);
			}
		}
		foreach ( array( 'categories_creees', 'categories_modifiees', 'categories_supprimees' ) as $cle ) {
			$modifs[ $cle ] = array_values( array_unique( array_map( 'intval', (array) ( $modifs[ $cle ] ?? array() ) ) ) );
		}
		$journal['modifications']           = $modifs;
		$journal['sauvegarde']              = $sauvegarde;
		$journal['sauvegarde_reconstruite'] = true;
		return $journal;
	}

	/**
	 * Liste figée d'une étape.
	 *
	 * @param string $etape Étape.
	 * @return int[]
	 */
	private function liste( string $etape ): array {
		return array_map( 'intval', (array) ( $this->etat['annulation'][ $etape ] ?? array() ) );
	}

	/**
	 * Nombre d'éléments d'une étape.
	 *
	 * @param string $etape Étape.
	 */
	public function total( string $etape ): int {
		if ( in_array( $etape, array( 'articles', 'pages_conservees', 'anciennes_pages', 'pages', 'chapitres', 'tomes', 'oeuvres' ), true ) ) {
			return count( $this->liste( $etape ) );
		}
		return 1;
	}

	/**
	 * Traite un élément.
	 *
	 * @param string $etape Étape.
	 * @param int    $index Index.
	 * @throws \RuntimeException Erreur bloquante.
	 */
	protected function traiter( string $etape, int $index ): void {
		switch ( $etape ) {
			case 'redirections':
				$this->redirections();
				break;
			case 'categories':
				$this->categories();
				break;
			case 'articles':
				$this->article( $this->liste( $etape )[ $index ] );
				break;
			case 'reglages':
				$this->options();
				break;
			case 'pages_conservees':
				$this->page_conservee( $this->liste( $etape )[ $index ] );
				break;
			case 'anciennes_pages':
				$this->ancienne_page( $this->liste( $etape )[ $index ] );
				break;
			case 'pages':
				$this->page( $this->liste( $etape )[ $index ] );
				break;
			case 'chapitres':
			case 'tomes':
			case 'oeuvres':
				$this->supprimer( $this->liste( $etape )[ $index ] );
				break;
			case 'terminer':
				$this->controle();
				break;
		}
	}

	/**
	 * Fin de l'annulation.
	 */
	protected function finir(): void {
		$this->etat['statut']       = 'annule';
		$this->etat['operation']    = '';
		$this->etat['fin']          = current_time( 'mysql', true );
		$this->etat['annule_le']    = $this->etat['fin'];
		$this->etat['annule_par']   = (int) ( $this->etat['par'] ?? 0 );
		$this->etat['historique'][] = array(
			'date'   => $this->etat['fin'],
			'action' => 'annule',
			'par'    => get_current_user_id(),
		);
		$this->message( __( 'Migration annulée.', 'yume-core' ), 'succes' );
		/**
		 * La migration vient d'être annulée.
		 *
		 * @param array $journal Journal de l'exécution annulée.
		 */
		do_action( 'yume_migration_annulee', $this->journal );
	}

	/**
	 * Retire les redirections ajoutées par la migration.
	 */
	private function redirections(): void {
		// Sources notées au journal et sources du plan : même si le journal a manqué
		// l'enregistrement (arrêt brutal), aucune redirection de la migration ne reste.
		$sources = (array) ( $this->journal['modifications']['redirections'] ?? array() );
		foreach ( (array) ( $this->plan['redirections'] ?? array() ) as $r ) {
			$sources[] = (string) $r['source'];
		}
		$origine = $this->journal['sauvegarde']['options'][ Redirections::OPTION ] ?? null;
		$avant   = is_array( $origine ) && ! empty( $origine['existe'] ) && is_array( $origine['valeur'] ) ? $origine['valeur'] : array();
		$retirer = array();
		foreach ( array_unique( array_map( array( Redirections::class, 'normaliser' ), $sources ) ) as $source ) {
			if ( ! isset( $avant[ $source ] ) ) {
				$retirer[] = $source;
			}
		}
		Redirections::retirer( $retirer );
		if ( $avant ) {
			// Redirections qui existaient avant la migration : cible d'origine.
			$table = get_option( Redirections::OPTION, array() );
			$table = is_array( $table ) ? $table : array();
			foreach ( array_unique( array_map( array( Redirections::class, 'normaliser' ), $sources ) ) as $source ) {
				if ( isset( $avant[ $source ] ) ) {
					$table[ $source ] = $avant[ $source ];
				}
			}
			ksort( $table );
			update_option( Redirections::OPTION, $table, true );
		}
		$table = get_option( Redirections::OPTION, array() );
		if ( ( ! is_array( $origine ) || empty( $origine['existe'] ) ) && empty( $table ) ) {
			delete_option( Redirections::OPTION );
		}
		$this->journal['modifications']['redirections'] = array();
	}

	/**
	 * Catégories : « Non classé » recréée sous son ID, noms et slugs d'origine, catégories
	 * créées supprimées, catégorie par défaut restaurée.
	 *
	 * @throws \RuntimeException Recréation ou restauration impossible.
	 */
	private function categories(): void {
		global $wpdb;
		$sauvegarde = (array) ( $this->journal['sauvegarde']['categories'] ?? array() );
		$recreees   = (array) ( $this->journal['categories_recreees'] ?? array() );
		foreach ( (array) ( $this->journal['modifications']['categories_supprimees'] ?? array() ) as $id ) {
			$id    = (int) $id;
			$ligne = $sauvegarde[ $id ] ?? null;
			if ( ! is_array( $ligne ) || get_term( $id, 'category' ) instanceof \WP_Term ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$libre    = 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $id ) );
			$tt_libre = 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", (int) $ligne['term_taxonomy_id'] ) );
			if ( $libre && ! get_term_by( 'slug', $ligne['slug'], 'category' ) ) {
				$wpdb->insert(
					$wpdb->terms,
					array(
						'term_id'    => $id,
						'name'       => $ligne['name'],
						'slug'       => $ligne['slug'],
						'term_group' => (int) $ligne['term_group'],
					)
				);
				$tt = array(
					'term_id'     => $id,
					'taxonomy'    => 'category',
					'description' => $ligne['description'],
					'parent'      => (int) $ligne['parent'],
					'count'       => 0,
				);
				if ( $tt_libre && (int) $ligne['term_taxonomy_id'] > 0 ) {
					$tt['term_taxonomy_id'] = (int) $ligne['term_taxonomy_id'];
				}
				$wpdb->insert( $wpdb->term_taxonomy, $tt );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery
				clean_term_cache( $id, 'category' );
				$recreees[ $id ] = $id;
			} else {
				$res = wp_insert_term(
					$ligne['name'],
					'category',
					array(
						'slug'        => $ligne['slug'],
						'description' => $ligne['description'],
						'parent'      => (int) $ligne['parent'],
					)
				);
				if ( is_wp_error( $res ) ) {
					throw new \RuntimeException( $res->get_error_message() );
				}
				$recreees[ $id ] = (int) $res['term_id'];
				/* translators: 1: nom, 2: ancien ID, 3: nouvel ID. */
				$this->message( sprintf( __( 'Catégorie « %1$s » recréée sous l’ID %3$d (ID %2$d occupé).', 'yume-core' ), $ligne['name'], $id, $res['term_id'] ), 'avertissement' );
			}
			/* translators: %s: nom de la catégorie. */
			$this->message( sprintf( __( 'Catégorie « %s » recréée.', 'yume-core' ), $ligne['name'] ) );
		}
		$this->journal['categories_recreees']                    = $recreees;
		$this->journal['modifications']['categories_supprimees'] = array();

		foreach ( (array) ( $this->journal['modifications']['categories_modifiees'] ?? array() ) as $id ) {
			$ligne = $sauvegarde[ (int) $id ] ?? null;
			if ( ! is_array( $ligne ) || ! get_term( (int) $id, 'category' ) instanceof \WP_Term ) {
				continue;
			}
			$res = wp_update_term(
				(int) $id,
				'category',
				array(
					'name'        => $ligne['name'],
					'slug'        => $ligne['slug'],
					'description' => $ligne['description'],
					'parent'      => (int) $ligne['parent'],
				)
			);
			if ( is_wp_error( $res ) ) {
				throw new \RuntimeException( $res->get_error_message() );
			}
		}
		$this->journal['modifications']['categories_modifiees'] = array();

		// Catégorie par défaut d'origine avant de supprimer les catégories créées.
		$this->restaurer_option( 'default_category' );
		foreach ( (array) ( $this->journal['modifications']['categories_creees'] ?? array() ) as $id ) {
			if ( get_term( (int) $id, 'category' ) instanceof \WP_Term && (int) get_option( 'default_category' ) !== (int) $id ) {
				wp_delete_term( (int) $id, 'category' );
			}
		}
		$this->journal['modifications']['categories_creees'] = array();
	}

	/**
	 * Article : catégories et œuvres liées d'origine.
	 *
	 * @param int $id Article.
	 */
	private function article( int $id ): void {
		$sauvegarde = $this->journal['sauvegarde']['articles'][ $id ] ?? null;
		if ( ! is_array( $sauvegarde ) || ! get_post( $id ) ) {
			return;
		}
		$recreees = (array) ( $this->journal['categories_recreees'] ?? array() );
		$cats     = array();
		foreach ( (array) $sauvegarde['categories'] as $cat ) {
			$cats[] = (int) ( $recreees[ (int) $cat ] ?? $cat );
		}
		wp_set_object_terms( $id, $cats, 'category', false );
		if ( taxonomy_exists( 'yume_oeuvre_liee' ) ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $sauvegarde['oeuvre_liee'] ), 'yume_oeuvre_liee', false );
		}
	}

	/**
	 * Restaure une option sauvegardée (valeur, ou absence).
	 *
	 * @param string $option Nom.
	 */
	private function restaurer_option( string $option ): void {
		$origine = $this->journal['sauvegarde']['options'][ $option ] ?? null;
		if ( ! is_array( $origine ) ) {
			return;
		}
		if ( empty( $origine['existe'] ) ) {
			delete_option( $option );
			return;
		}
		// Valeur d'origine réécrite telle quelle : sans l'assainissement de register_setting(),
		// qui dépend de l'utilisateur courant et des valeurs par défaut du moment.
		$filtre  = 'sanitize_option_' . $option;
		$rappels = $GLOBALS['wp_filter'][ $filtre ] ?? null;
		remove_all_filters( $filtre );
		update_option( $option, $origine['valeur'] );
		if ( null !== $rappels ) {
			$GLOBALS['wp_filter'][ $filtre ] = $rappels; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- filtres remis tels qu'ils étaient.
		}
	}

	/**
	 * Options : lecture, pages Yume, réglages, catégorie par défaut.
	 */
	private function options(): void {
		foreach ( Migration_Empreinte::OPTIONS as $option ) {
			if ( Redirections::OPTION !== $option ) {
				$this->restaurer_option( $option );
			}
		}
		$this->journal['modifications']['options'] = array();
	}

	/**
	 * Page conservée : contenu d'origine (couleurs en ligne, textes alternatifs), sauf si
	 * l'équipe l'a retouchée depuis la migration.
	 *
	 * @param int $id Page.
	 */
	private function page_conservee( int $id ): void {
		global $wpdb;
		$sauvegarde = $this->journal['modifications']['pages_nettoyees'][ $id ] ?? null;
		$post       = get_post( $id );
		if ( ! is_array( $sauvegarde ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( md5( (string) $post->post_content ) !== (string) ( $sauvegarde['ecrit'] ?? '' ) && (string) $post->post_content !== (string) $sauvegarde['post_content'] ) {
			/* translators: 1: titre, 2: ID. */
			$this->message( sprintf( __( 'Page « %1$s » (ID %2$d) retouchée depuis la migration : laissée telle quelle.', 'yume-core' ), $post->post_title, $id ), 'avertissement' );
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $sauvegarde['post_content'] ), array( 'ID' => $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
	}

	/**
	 * Ancienne page : statut (et date de modification) d'origine.
	 *
	 * @param int $id Page.
	 */
	private function ancienne_page( int $id ): void {
		$sauvegarde = $this->journal['sauvegarde']['pages'][ $id ] ?? null;
		$post       = get_post( $id );
		if ( ! is_array( $sauvegarde ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $post->post_status !== $sauvegarde['post_status'] || $post->post_modified !== $sauvegarde['post_modified'] ) {
			self::changer_statut( $id, (string) $sauvegarde['post_status'], (string) $sauvegarde['post_modified'] );
			$this->compter( 'pages_restaurees' );
		}
	}

	/**
	 * Page Yume : supprimée si créée par la migration, restaurée si elle a été adoptée.
	 *
	 * @param int $id Page.
	 */
	private function page( int $id ): void {
		global $wpdb;
		$adoptee = $this->journal['modifications']['pages_adoptees'][ $id ] ?? null;
		if ( is_array( $adoptee ) ) {
			if ( get_post( $id ) ) {
				// Contenu restauré tel quel, sans filtre ni révision.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $adoptee['post_content'] ), array( 'ID' => $id ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $id );
				self::changer_statut( $id, (string) $adoptee['post_status'], (string) $adoptee['post_modified'] );
			}
			return;
		}
		$this->supprimer( $id );
	}

	/**
	 * Supprime définitivement un contenu créé par la migration.
	 *
	 * @param int $id Contenu.
	 * @throws \RuntimeException Suppression impossible.
	 */
	private function supprimer( int $id ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( '' === (string) get_post_meta( $id, Site_Source::META_CLE, true ) ) {
			// Jamais de suppression d'un contenu que la migration n'a pas créé.
			return;
		}
		if ( ! wp_delete_post( $id, true ) ) {
			/* translators: %d: ID. */
			throw new \RuntimeException( sprintf( __( 'suppression du contenu %d impossible.', 'yume-core' ), $id ) );
		}
		$this->compter( 'supprimes' );
	}

	/**
	 * Contrôle final : recomptage des catégories, comparaison de l'empreinte.
	 */
	private function controle(): void {
		$ids = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $ids ) && $ids ) {
			wp_update_term_count_now( array_map( 'intval', $ids ), 'category' );
		}
		$this->journal['correspondances'] = Migration_State::journal_defaut()['correspondances'];
		$avant                            = (array) ( $this->journal['empreinte'] ?? array() );
		if ( ! $avant ) {
			$this->etat['controle'] = array(
				'date'        => current_time( 'mysql', true ),
				'differences' => array(),
				'verifie'     => false,
			);
			$this->message(
				! empty( $this->journal['sauvegarde_reconstruite'] )
					? __( 'Contrôle impossible : la sauvegarde d’origine était perdue. Les statuts des anciennes pages, les catégories et les catégories des articles ont été restaurés d’après le plan ; vérifiez à la main les réglages de lecture et d’inscription, la page d’accueil et les options Yume.', 'yume-core' )
					: __( 'Contrôle impossible : aucune empreinte d’origine n’a été prise (la migration s’est arrêtée avant sa préparation).', 'yume-core' ),
				'avertissement'
			);
			return;
		}
		$diff                   = Migration_Empreinte::differences( $avant, Migration_Empreinte::calculer( $this->plan ) );
		$this->etat['controle'] = array(
			'date'        => current_time( 'mysql', true ),
			'differences' => $diff,
			'verifie'     => true,
		);
		if ( $diff && ! empty( $this->etat['annulation']['conserves'] ) ) {
			/* translators: 1: nombre de contenus conservés, 2: liste des éléments. */
			$this->message( sprintf( __( 'Contrôle : %1$d contenu(s) utilisés depuis la migration ont été conservés, d’où ces différences attendues : %2$s.', 'yume-core' ), count( (array) $this->etat['annulation']['conserves'] ), implode( ', ', array_slice( $diff, 0, 20 ) ) ), 'avertissement' );
		} elseif ( $diff ) {
			/* translators: %s: liste des éléments. */
			$this->message( sprintf( __( 'Contrôle : %s diffèrent de l’état d’origine.', 'yume-core' ), implode( ', ', array_slice( $diff, 0, 20 ) ) ), 'avertissement' );
		} else {
			$this->message( __( 'Contrôle : les contenus touchés sont revenus à leur état d’origine.', 'yume-core' ), 'succes' );
		}
	}
}
