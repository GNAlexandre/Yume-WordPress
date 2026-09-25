<?php
/**
 * Exécution du plan de migration, sur place (les pièces jointes gardent leur ID) :
 *
 *  1. préparation : sauvegarde des options, des statuts des anciennes pages, des catégories
 *     des articles et des catégories ; empreinte des contenus touchés ; correspondance des
 *     médias (par ID puis par suffixe de _wp_attached_file) ;
 *  2. œuvres, tomes (yume_oeuvre_id), chapitres (yume_tome_id ; le reste est dérivé par le
 *     cœur), avec slugs du plan, images par ID de pièce jointe, taxonomies type et statut ;
 *  3. catégories (Yume News → Sorties, catégorie par défaut → Actualités) et articles
 *     (catégorie cible, terme yume_oeuvre_liee de l'œuvre — né avec l'œuvre, jamais créé ici) ;
 *  4. pages Yume (§11, actualités, mentions légales, accueil) et option yume_pages ;
 *  5. anciennes pages remplacées passées en brouillon (jamais supprimées) ;
 *  6. réglages de lecture, bannière, redirections 301, suppression de « Non classé ».
 *
 * Idempotent : chaque contenu créé porte la méta _yume_migration_cle (« oeuvre:grimgar… »),
 * _yume_source_id (ID de la page ou de l'article d'origine) et le journal garde les
 * correspondances ; une nouvelle exécution met à jour au lieu de dupliquer.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

// Messages d'exception internes, échappés là où ils sont affichés (esc_html dans
// l'administration, WP_Error en JSON pour l'API REST, WP-CLI en console).
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Étapes de l'exécution.
 */
final class Migration_Executor extends Migration_Moteur {

	/** Méta : clé du plan d'un contenu créé par la migration. */
	public const META_CLE = Site_Source::META_CLE;

	/** Méta : ID de la page ou de l'article d'origine. */
	public const META_SOURCE = '_yume_source_id';

	/** Méta : identifiant de l'exécution qui a créé le contenu. */
	public const META_RUN = '_yume_migration_run';

	/** Métadonnées de cache calculées par le cœur, jamais écrites par la migration. */
	private const CACHES = array( 'yume_nb_chapitres', 'yume_note_moyenne', 'yume_nb_notes', 'yume_nb_favoris', 'yume_derniere_sortie' );

	/**
	 * Contenus créés par la migration : clé => ID (lu une fois par lot).
	 *
	 * @var array<string,int>|null
	 */
	private ?array $marques = null;

	/**
	 * Étapes.
	 *
	 * @return array<string,string>
	 */
	public static function etapes(): array {
		return array(
			'preparer'        => __( 'Préparation (sauvegarde, médias)', 'yume-core' ),
			'oeuvres'         => __( 'Œuvres', 'yume-core' ),
			'tomes'           => __( 'Tomes', 'yume-core' ),
			'chapitres'       => __( 'Chapitres', 'yume-core' ),
			'categories'      => __( 'Catégories', 'yume-core' ),
			'articles'        => __( 'Articles', 'yume-core' ),
			'pages'           => __( 'Pages Yume', 'yume-core' ),
			'anciennes_pages' => __( 'Anciennes pages (brouillons)', 'yume-core' ),
			'reglages'        => __( 'Réglages de lecture', 'yume-core' ),
			'redirections'    => __( 'Redirections 301', 'yume-core' ),
			'nettoyage'       => __( 'Nettoyage des catégories', 'yume-core' ),
			'terminer'        => __( 'Fin', 'yume-core' ),
		);
	}

	/**
	 * Nombre d'éléments d'une étape.
	 *
	 * @param string $etape Étape.
	 */
	public function total( string $etape ): int {
		switch ( $etape ) {
			case 'oeuvres':
			case 'tomes':
			case 'chapitres':
			case 'articles':
				return count( (array) ( $this->plan[ $etape ] ?? array() ) );
			case 'pages':
				return count( (array) ( $this->plan['pages']['creer'] ?? array() ) );
			case 'anciennes_pages':
				return count( (array) ( $this->plan['pages']['remplacer'] ?? array() ) );
			default:
				return 1;
		}
	}

	/**
	 * Problèmes qui empêchent d'exécuter un plan : erreurs du plan, œuvres dont le slug est déjà
	 * pris par une œuvre qui n'a pas été créée par la migration.
	 *
	 * @param array $plan Plan.
	 * @return string[]
	 */
	public static function problemes( array $plan ): array {
		$problemes = array();
		foreach ( (array) ( $plan['avertissements'] ?? array() ) as $a ) {
			if ( 'erreur' === ( $a['niveau'] ?? '' ) ) {
				/* translators: %s: message d'erreur du plan. */
				$problemes[] = sprintf( __( 'Erreur du plan : %s', 'yume-core' ), $a['message'] );
			}
		}
		foreach ( (array) ( $plan['oeuvres'] ?? array() ) as $oeuvre ) {
			$ids = get_posts(
				array(
					'post_type'        => 'yume_oeuvre',
					'name'             => $oeuvre['cle'],
					'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'fields'           => 'ids',
					'posts_per_page'   => 5,
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( (string) get_post_meta( (int) $id, self::META_CLE, true ) !== 'oeuvre:' . $oeuvre['cle'] ) {
					/* translators: 1: slug, 2: ID. */
					$problemes[] = sprintf( __( 'L’œuvre « %1$s » existe déjà (ID %2$d) sans avoir été créée par la migration.', 'yume-core' ), $oeuvre['cle'], $id );
				}
			}
		}
		/**
		 * Filtre les problèmes qui empêchent d'exécuter un plan (liste vide : exécution permise).
		 *
		 * @param string[] $problemes Problèmes.
		 * @param array    $plan      Plan.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'yume_migration_problemes', $problemes, $plan ) ) );
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
			case 'preparer':
				$this->preparer();
				break;
			case 'oeuvres':
				$this->oeuvre( $this->plan['oeuvres'][ $index ] );
				break;
			case 'tomes':
				$this->tome( $this->plan['tomes'][ $index ] );
				break;
			case 'chapitres':
				$this->chapitre( $this->plan['chapitres'][ $index ] );
				break;
			case 'categories':
				$this->categories();
				break;
			case 'articles':
				$this->article( $this->plan['articles'][ $index ] );
				break;
			case 'pages':
				$this->page( $this->plan['pages']['creer'][ $index ] );
				break;
			case 'anciennes_pages':
				$this->ancienne_page( $this->plan['pages']['remplacer'][ $index ] );
				break;
			case 'reglages':
				$this->reglages();
				break;
			case 'redirections':
				$this->redirections();
				break;
			case 'nettoyage':
				$this->nettoyage();
				break;
			case 'terminer':
				break;
		}
	}

	/**
	 * Fin de l'exécution.
	 */
	protected function finir(): void {
		$this->etat['statut']       = 'migre';
		$this->etat['operation']    = '';
		$this->etat['fin']          = current_time( 'mysql', true );
		$this->etat['migre_le']     = $this->etat['fin'];
		$this->etat['migre_par']    = (int) ( $this->etat['par'] ?? 0 );
		$this->etat['historique'][] = array(
			'date'   => $this->etat['fin'],
			'action' => 'migre',
			'par'    => get_current_user_id(),
		);
		$this->message( __( 'Migration terminée.', 'yume-core' ), 'succes' );
		/**
		 * La migration vient de se terminer.
		 *
		 * @param array $journal Journal (correspondances, modifications).
		 * @param array $plan    Plan exécuté.
		 */
		do_action( 'yume_migration_terminee', $this->journal, $this->plan );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Outils
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Contenus déjà créés par la migration (clé du plan => ID), hors corbeille.
	 *
	 * @return array<string,int>
	 */
	private function marques(): array {
		global $wpdb;
		if ( null === $this->marques ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$lignes        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_value AS cle, m.post_id AS id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) ORDER BY m.post_id ASC",
					self::META_CLE
				)
			);
			$this->marques = array();
			foreach ( (array) $lignes as $ligne ) {
				if ( ! isset( $this->marques[ $ligne->cle ] ) ) {
					$this->marques[ (string) $ligne->cle ] = (int) $ligne->id;
				}
			}
		}
		return $this->marques;
	}

	/**
	 * Contenu existant créé par la migration pour une clé (journal, puis méta).
	 *
	 * @param string $type Type d'élément (oeuvre, tome, chapitre, page).
	 * @param string $cle  Clé du plan.
	 * @param string $post_type Type de contenu attendu.
	 */
	private function existant( string $type, string $cle, string $post_type ): int {
		$ids = array(
			(int) ( $this->journal['correspondances'][ $type ][ $cle ] ?? 0 ),
			(int) ( $this->marques()[ $type . ':' . $cle ] ?? 0 ),
		);
		foreach ( $ids as $id ) {
			$post = $id ? get_post( $id ) : null;
			if ( $post instanceof \WP_Post && $post_type === $post->post_type && 'trash' !== $post->post_status
				&& (string) get_post_meta( $id, self::META_CLE, true ) === $type . ':' . $cle ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Mémorise la correspondance d'un élément créé ou mis à jour.
	 *
	 * @param string   $type      oeuvre, tome, chapitre ou page.
	 * @param string   $cle       Clé du plan.
	 * @param int      $id        Contenu cible.
	 * @param int|null $source_id Page ou article d'origine.
	 */
	private function correspondance( string $type, string $cle, int $id, ?int $source_id ): void {
		$this->journal['correspondances'][ $type ][ $cle ] = $id;
		if ( null !== $this->marques ) {
			$this->marques[ $type . ':' . $cle ] = $id;
		}
		if ( $source_id ) {
			$lien  = $type . ':' . $cle;
			$liste = (array) ( $this->journal['sources'][ $source_id ] ?? array() );
			if ( ! in_array( $lien, $liste, true ) ) {
				$liste[] = $lien;
			}
			$this->journal['sources'][ $source_id ] = $liste;
		}
	}

	/**
	 * Auteur d'un contenu créé : auteur de la page ou de l'article d'origine, sinon
	 * l'utilisateur courant, sinon le premier administrateur.
	 *
	 * @param int|null $source_id Contenu d'origine.
	 */
	private function auteur( ?int $source_id ): int {
		$source = $source_id ? get_post( $source_id ) : null;
		if ( $source instanceof \WP_Post && (int) $source->post_author && get_userdata( (int) $source->post_author ) ) {
			return (int) $source->post_author;
		}
		return get_current_user_id() ? get_current_user_id() : self::premier_admin();
	}

	/**
	 * Média local d'un ID du plan.
	 *
	 * @param int $id ID du plan.
	 */
	private function media( int $id ): int {
		return Media_Mapper::local( (array) ( $this->journal['medias'] ?? array() ), $id );
	}

	/**
	 * Métadonnées du plan prêtes à écrire (sans valeurs nulles ni caches).
	 *
	 * @param array $meta Métadonnées du plan.
	 * @return array<string,mixed>
	 */
	private function meta_propres( array $meta ): array {
		$propres = array();
		foreach ( $meta as $cle => $valeur ) {
			if ( null === $valeur || in_array( $cle, self::CACHES, true ) ) {
				continue;
			}
			$propres[ $cle ] = $valeur;
		}
		return $propres;
	}

	/**
	 * Crée ou met à jour un contenu.
	 *
	 * @param array $postarr Données (non échappées).
	 * @param int   $id      Contenu existant (0 : création).
	 * @return int ID.
	 * @throws \RuntimeException Échec de l'enregistrement.
	 */
	private function enregistrer( array $postarr, int $id ): int {
		if ( '' === (string) ( $postarr['post_date'] ?? '' ) ) {
			unset( $postarr['post_date'] );
		}
		if ( $id ) {
			$postarr['ID'] = $id;
			$resultat      = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$resultat = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $resultat ) || ! $resultat ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: titre, 2: message d'erreur. */
					__( 'Enregistrement de « %1$s » impossible : %2$s', 'yume-core' ),
					(string) ( $postarr['post_title'] ?? '' ),
					is_wp_error( $resultat ) ? $resultat->get_error_message() : __( 'erreur inconnue', 'yume-core' )
				)
			);
		}
		return (int) $resultat;
	}

	/**
	 * Termes d'une taxonomie hiérarchique Yume par slug (créés avec leur libellé s'ils manquent).
	 *
	 * @param int      $post_id  Contenu.
	 * @param string   $taxonomy Taxonomie.
	 * @param string[] $slugs    Slugs.
	 * @param array    $libelles Slug => libellé.
	 */
	private function termes( int $post_id, string $taxonomy, array $slugs, array $libelles ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		$ids = array();
		foreach ( array_filter( array_map( 'strval', $slugs ) ) as $slug ) {
			$terme = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $terme instanceof \WP_Term ) {
				$res = wp_insert_term( $libelles[ $slug ] ?? $slug, $taxonomy, array( 'slug' => $slug ) );
				if ( is_wp_error( $res ) ) {
					continue;
				}
				$ids[] = (int) $res['term_id'];
				continue;
			}
			$ids[] = (int) $terme->term_id;
		}
		wp_set_object_terms( $post_id, $ids, $taxonomy, false );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Étapes
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Préparation : sauvegardes, empreinte, correspondance des médias.
	 */
	private function preparer(): void {
		if ( ! empty( $this->journal['sauvegarde'] ) ) {
			// Reprise après interruption : la sauvegarde d'origine est déjà prise.
			return;
		}
		$sauvegarde = array(
			'options'    => array(),
			'pages'      => array(),
			'articles'   => array(),
			'categories' => array(),
		);
		foreach ( Migration_Empreinte::OPTIONS as $option ) {
			$valeur                           = get_option( $option, '__yume_absente__' );
			$sauvegarde['options'][ $option ] = array(
				'existe' => '__yume_absente__' !== $valeur,
				'valeur' => '__yume_absente__' === $valeur ? null : $valeur,
			);
		}
		foreach ( (array) ( $this->plan['pages']['remplacer'] ?? array() ) as $page ) {
			$post = get_post( (int) $page['id'] );
			if ( $post instanceof \WP_Post ) {
				$sauvegarde['pages'][ (int) $post->ID ] = array(
					'post_status'   => $post->post_status,
					'post_modified' => $post->post_modified,
				);
			}
		}
		foreach ( (array) ( $this->plan['articles'] ?? array() ) as $article ) {
			$id = (int) $article['source_id'];
			if ( ! get_post( $id ) ) {
				continue;
			}
			$cats                          = wp_get_object_terms( $id, 'category', array( 'fields' => 'ids' ) );
			$lies                          = taxonomy_exists( 'yume_oeuvre_liee' ) ? wp_get_object_terms( $id, 'yume_oeuvre_liee', array( 'fields' => 'ids' ) ) : array();
			$sauvegarde['articles'][ $id ] = array(
				'categories'  => is_array( $cats ) ? array_map( 'intval', $cats ) : array(),
				'oeuvre_liee' => is_array( $lies ) ? array_map( 'intval', $lies ) : array(),
			);
		}
		$ids_categories = array();
		foreach ( (array) ( $this->plan['categories'] ?? array() ) as $cat ) {
			if ( ! empty( $cat['id'] ) ) {
				$ids_categories[] = (int) $cat['id'];
			}
		}
		foreach ( array( Legacy_Post_Parser::CATEGORIE_SORTIES, Legacy_Post_Parser::CATEGORIE_ACTUALITES ) as $slug ) {
			$terme = get_term_by( 'slug', $slug, 'category' );
			if ( $terme instanceof \WP_Term ) {
				$ids_categories[] = (int) $terme->term_id;
			}
		}
		foreach ( array_unique( $ids_categories ) as $id ) {
			$terme = get_term( $id, 'category' );
			if ( $terme instanceof \WP_Term ) {
				$sauvegarde['categories'][ $id ] = array(
					'term_id'          => (int) $terme->term_id,
					'term_taxonomy_id' => (int) $terme->term_taxonomy_id,
					'name'             => $terme->name,
					'slug'             => $terme->slug,
					'term_group'       => (int) $terme->term_group,
					'description'      => $terme->description,
					'parent'           => (int) $terme->parent,
				);
			}
		}
		$this->journal['empreinte'] = Migration_Empreinte::calculer( $this->plan );

		// Médias : références du plan, illustrations des tomes, bannière du site.
		$avert      = array();
		$references = (array) ( $this->plan['medias']['references'] ?? array() );
		$connus     = array_map( static fn( $r ) => (int) $r['id'], $references );
		$autres     = array( (int) ( $this->plan['reglages']['banniere_id'] ?? 0 ) );
		foreach ( (array) ( $this->plan['tomes'] ?? array() ) as $tome ) {
			$autres = array_merge( $autres, array_map( 'intval', (array) ( $tome['meta']['yume_illustrations'] ?? array() ) ) );
		}
		foreach ( array_unique( array_filter( $autres ) ) as $id ) {
			if ( ! in_array( $id, $connus, true ) ) {
				$references[] = array(
					'id'  => $id,
					'url' => '',
				);
			}
		}
		$this->journal['medias']     = Media_Mapper::correspondances( $references, $avert );
		$this->journal['sauvegarde'] = $sauvegarde;
		foreach ( $avert as $message ) {
			$this->message( $message, 'avertissement' );
		}
		$trouves = count( array_filter( $this->journal['medias'] ) );
		/* translators: 1: médias retrouvés, 2: médias référencés. */
		$this->message( sprintf( __( 'Sauvegarde prise ; %1$d média(s) sur %2$d retrouvé(s).', 'yume-core' ), $trouves, count( $this->journal['medias'] ) ) );
	}

	/**
	 * Œuvre.
	 *
	 * @param array $o Œuvre du plan.
	 */
	private function oeuvre( array $o ): void {
		$cle       = (string) $o['cle'];
		$source_id = (int) ( $o['source']['id'] ?? 0 );
		$id        = $this->existant( 'oeuvre', $cle, 'yume_oeuvre' );
		$meta      = $this->meta_propres( (array) $o['meta'] );
		if ( isset( $meta['yume_banniere_id'] ) ) {
			$meta['yume_banniere_id'] = $this->media( (int) $meta['yume_banniere_id'] );
		}
		$meta[ self::META_CLE ]    = 'oeuvre:' . $cle;
		$meta[ self::META_SOURCE ] = $source_id;
		$meta[ self::META_RUN ]    = (string) $this->etat['run'];
		$vignette                  = $this->media( (int) ( $o['thumbnail_id'] ?? 0 ) );
		if ( $vignette ) {
			$meta['_thumbnail_id'] = $vignette;
		}
		$post   = $o['post'];
		$id2    = $this->enregistrer(
			array(
				'post_type'      => 'yume_oeuvre',
				'post_title'     => $post['post_title'],
				'post_name'      => $post['post_name'],
				'post_status'    => $post['post_status'],
				'post_date'      => $post['post_date'] ?? '',
				'post_excerpt'   => $post['post_excerpt'] ?? '',
				'post_content'   => $post['post_content'] ?? '',
				'comment_status' => $post['comment_status'] ?? 'open',
				'ping_status'    => 'closed',
				'post_author'    => $this->auteur( $source_id ),
				'meta_input'     => $meta,
			),
			$id
		);
		$choix  = (array) ( $this->options['choix'] ?? array() );
		$statut = isset( $choix[ $cle ] ) && '' !== $choix[ $cle ] ? array( $choix[ $cle ] ) : (array) ( $o['termes']['yume_statut'] ?? array() );
		$this->termes( $id2, 'yume_type', (array) ( $o['termes']['yume_type'] ?? array() ), function_exists( 'yume_types' ) ? yume_types() : array() );
		$this->termes( $id2, 'yume_statut', $statut, function_exists( 'yume_statuts' ) ? yume_statuts() : array() );
		if ( taxonomy_exists( 'yume_genre' ) && ! empty( $o['termes']['yume_genre'] ) ) {
			wp_set_object_terms( $id2, array_map( 'strval', (array) $o['termes']['yume_genre'] ), 'yume_genre', false );
		}
		$this->correspondance( 'oeuvre', $cle, $id2, $source_id );
		$this->compter( $id ? 'oeuvres_mises_a_jour' : 'oeuvres_creees' );
	}

	/**
	 * Tome.
	 *
	 * @param array $t Tome du plan.
	 * @throws \RuntimeException Œuvre absente.
	 */
	private function tome( array $t ): void {
		$cle       = (string) $t['cle'];
		$oeuvre_id = (int) ( $this->journal['correspondances']['oeuvre'][ $t['oeuvre'] ] ?? 0 );
		if ( ! $oeuvre_id ) {
			/* translators: %s: clé de l'œuvre. */
			throw new \RuntimeException( sprintf( __( 'œuvre « %s » non créée.', 'yume-core' ), $t['oeuvre'] ) );
		}
		$source_id = (int) ( $t['source']['id'] ?? 0 );
		$id        = $this->existant( 'tome', $cle, 'yume_tome' );
		$meta      = $this->meta_propres( (array) $t['meta'] );
		if ( isset( $meta['yume_illustrations'] ) ) {
			$meta['yume_illustrations'] = array_values( array_filter( array_map( fn( $i ) => $this->media( (int) $i ), (array) $meta['yume_illustrations'] ) ) );
		}
		$meta['yume_oeuvre_id']    = $oeuvre_id;
		$meta[ self::META_CLE ]    = 'tome:' . $cle;
		$meta[ self::META_SOURCE ] = $source_id;
		$meta[ self::META_RUN ]    = (string) $this->etat['run'];
		$couverture                = $this->media( (int) ( $t['thumbnail_id'] ?? 0 ) );
		if ( $couverture ) {
			$meta['_thumbnail_id'] = $couverture;
		}
		$post = $t['post'];
		$id2  = $this->enregistrer(
			array(
				'post_type'      => 'yume_tome',
				'post_title'     => $post['post_title'],
				'post_name'      => $post['post_name'],
				'post_status'    => $post['post_status'],
				'post_date'      => $post['post_date'] ?? '',
				'post_excerpt'   => $post['post_excerpt'] ?? '',
				'post_content'   => $post['post_content'] ?? '',
				'menu_order'     => (int) ( $post['menu_order'] ?? 0 ),
				'comment_status' => $post['comment_status'] ?? 'open',
				'ping_status'    => 'closed',
				'post_author'    => $this->auteur( $source_id ),
				'meta_input'     => $meta,
			),
			$id
		);
		$this->correspondance( 'tome', $cle, $id2, $source_id );
		$this->compter( $id ? 'tomes_mis_a_jour' : 'tomes_crees' );
	}

	/**
	 * Chapitre.
	 *
	 * @param array $c Chapitre du plan.
	 * @throws \RuntimeException Tome absent.
	 */
	private function chapitre( array $c ): void {
		$cle     = (string) $c['cle'];
		$tome_id = (int) ( $this->journal['correspondances']['tome'][ $c['tome'] ] ?? 0 );
		if ( ! $tome_id ) {
			/* translators: %s: clé du tome. */
			throw new \RuntimeException( sprintf( __( 'tome « %s » non créé.', 'yume-core' ), $c['tome'] ) );
		}
		$source_id = 'page' === ( $c['source']['type'] ?? '' ) ? (int) ( $c['source']['id'] ?? 0 ) : null;
		$id        = $this->existant( 'chapitre', $cle, 'yume_chapitre' );
		$meta      = $this->meta_propres( (array) $c['meta'] );
		if ( empty( $meta['yume_source']['format'] ) ) {
			unset( $meta['yume_source'] );
		}
		if ( 0 === (int) ( $meta['yume_nb_mots'] ?? 0 ) ) {
			// Chapitre planifié (brouillon vide) : le cœur calcule mots et temps de lecture.
			unset( $meta['yume_nb_mots'], $meta['yume_temps_lecture'] );
		}
		$meta['yume_tome_id']      = $tome_id;
		$meta[ self::META_CLE ]    = 'chapitre:' . $cle;
		$meta[ self::META_SOURCE ] = (int) ( $c['source']['id'] ?? 0 );
		$meta[ self::META_RUN ]    = (string) $this->etat['run'];
		$post                      = $c['post'];
		$contenu                   = Media_Mapper::remapper_contenu( (string) ( $post['post_content'] ?? '' ), (array) ( $this->journal['medias'] ?? array() ) );
		$id2                       = $this->enregistrer(
			array(
				'post_type'      => 'yume_chapitre',
				'post_title'     => $post['post_title'],
				'post_name'      => $post['post_name'],
				'post_status'    => $post['post_status'],
				'post_date'      => $post['post_date'] ?? '',
				'post_content'   => $contenu,
				'menu_order'     => (int) ( $post['menu_order'] ?? 0 ),
				'comment_status' => $post['comment_status'] ?? 'open',
				'ping_status'    => 'closed',
				'post_author'    => $this->auteur( $source_id ),
				'meta_input'     => $meta,
			),
			$id
		);
		$this->correspondance( 'chapitre', $cle, $id2, $source_id );
		$this->compter( $id ? 'chapitres_mis_a_jour' : 'chapitres_crees' );
	}

	/**
	 * Catégories : renommage de Yume News en Sorties, description d'Actualités, catégorie par
	 * défaut → Actualités (« Non classé » est supprimée à l'étape de nettoyage).
	 *
	 * @throws \RuntimeException Renommage ou création impossible.
	 */
	private function categories(): void {
		$cibles = (array) ( $this->journal['categories_cibles'] ?? array() );
		foreach ( (array) ( $this->plan['categories'] ?? array() ) as $cat ) {
			$action = (string) ( $cat['action'] ?? '' );
			if ( 'supprimer' === $action ) {
				continue;
			}
			$slug  = (string) $cat['slug'];
			$terme = ! empty( $cat['id'] ) ? get_term( (int) $cat['id'], 'category' ) : null;
			$autre = get_term_by( 'slug', $slug, 'category' );
			if ( 'renommer' === $action && $terme instanceof \WP_Term ) {
				if ( $autre instanceof \WP_Term && (int) $autre->term_id !== (int) $terme->term_id ) {
					// Une catégorie de ce slug existe déjà : elle sert de cible, l'ancienne reste.
					$cibles[ $slug ] = (int) $autre->term_id;
					continue;
				}
				if ( $terme->slug !== $slug || $terme->name !== $cat['nom'] ) {
					$res = wp_update_term(
						(int) $terme->term_id,
						'category',
						array(
							'name'        => $cat['nom'],
							'slug'        => $slug,
							'description' => '' !== (string) $terme->description ? $terme->description : (string) ( $cat['description'] ?? '' ),
						)
					);
					if ( is_wp_error( $res ) ) {
						throw new \RuntimeException( $res->get_error_message() );
					}
					$this->noter_categorie_modifiee( (int) $terme->term_id );
					/* translators: 1: ancien nom, 2: nouveau nom. */
					$this->message( sprintf( __( 'Catégorie « %1$s » renommée « %2$s ».', 'yume-core' ), $cat['nom_actuel'] ?? '', $cat['nom'] ) );
				}
				$cibles[ $slug ] = (int) $terme->term_id;
				continue;
			}
			if ( 'conserver' === $action && $terme instanceof \WP_Term ) {
				if ( '' === (string) $terme->description && '' !== (string) ( $cat['description'] ?? '' ) ) {
					wp_update_term( (int) $terme->term_id, 'category', array( 'description' => $cat['description'] ) );
					$this->noter_categorie_modifiee( (int) $terme->term_id );
				}
				$cibles[ $slug ] = (int) $terme->term_id;
				continue;
			}
			$cibles[ $slug ] = $this->categorie_par_slug( $slug, (string) ( $cat['nom'] ?? ucfirst( $slug ) ), (string) ( $cat['description'] ?? '' ) );
		}
		$noms = array(
			Legacy_Post_Parser::CATEGORIE_SORTIES    => __( 'Sorties', 'yume-core' ),
			Legacy_Post_Parser::CATEGORIE_ACTUALITES => __( 'Actualités', 'yume-core' ),
		);
		foreach ( $noms as $slug => $nom ) {
			if ( empty( $cibles[ $slug ] ) ) {
				$cibles[ $slug ] = $this->categorie_par_slug( $slug, $nom, '' );
			}
		}
		$this->journal['categories_cibles'] = $cibles;
		update_option( 'default_category', (int) $cibles[ Legacy_Post_Parser::CATEGORIE_ACTUALITES ] );
		$this->noter_option( 'default_category' );
	}

	/**
	 * Catégorie d'un slug, créée si elle manque.
	 *
	 * @param string $slug        Slug.
	 * @param string $nom         Nom.
	 * @param string $description Description.
	 * @throws \RuntimeException Création impossible.
	 */
	private function categorie_par_slug( string $slug, string $nom, string $description ): int {
		$terme = get_term_by( 'slug', $slug, 'category' );
		if ( $terme instanceof \WP_Term ) {
			return (int) $terme->term_id;
		}
		$res = wp_insert_term(
			$nom,
			'category',
			array(
				'slug'        => $slug,
				'description' => $description,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( $res->get_error_message() );
		}
		$this->journal['modifications']['categories_creees'][] = (int) $res['term_id'];
		/* translators: %s: nom de la catégorie. */
		$this->message( sprintf( __( 'Catégorie « %s » créée.', 'yume-core' ), $nom ) );
		return (int) $res['term_id'];
	}

	/**
	 * Note une catégorie modifiée (restaurée à l'annulation).
	 *
	 * @param int $id Terme.
	 */
	private function noter_categorie_modifiee( int $id ): void {
		if ( ! in_array( $id, $this->journal['modifications']['categories_modifiees'], true ) ) {
			$this->journal['modifications']['categories_modifiees'][] = $id;
		}
	}

	/**
	 * Note une option modifiée.
	 *
	 * @param string $option Nom.
	 */
	private function noter_option( string $option ): void {
		if ( ! in_array( $option, $this->journal['modifications']['options'], true ) ) {
			$this->journal['modifications']['options'][] = $option;
		}
	}

	/**
	 * Article : catégorie cible et œuvre liée.
	 *
	 * @param array $a Article du plan.
	 */
	private function article( array $a ): void {
		if ( 'reclasser' !== ( $a['action'] ?? '' ) ) {
			return;
		}
		$id   = (int) $a['source_id'];
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			/* translators: %d: ID de l'article. */
			$this->message( sprintf( __( 'Article %d introuvable : ignoré.', 'yume-core' ), $id ), 'avertissement' );
			return;
		}
		$categorie = (int) ( $this->journal['categories_cibles'][ $a['categorie_cible'] ] ?? 0 );
		if ( $categorie ) {
			wp_set_object_terms( $id, array( $categorie ), 'category', false );
		}
		if ( ! empty( $a['oeuvre'] ) && taxonomy_exists( 'yume_oeuvre_liee' ) ) {
			$oeuvre_id = (int) ( $this->journal['correspondances']['oeuvre'][ $a['oeuvre'] ] ?? 0 );
			$terme     = $oeuvre_id ? (int) get_post_meta( $oeuvre_id, '_yume_terme_lie', true ) : 0;
			if ( $terme && term_exists( $terme, 'yume_oeuvre_liee' ) ) {
				wp_set_object_terms( $id, array( $terme ), 'yume_oeuvre_liee', false );
			}
		}
		if ( ! in_array( $id, $this->journal['modifications']['articles'], true ) ) {
			$this->journal['modifications']['articles'][] = $id;
		}
		$this->compter( 'articles_reclasses' );
	}

	/**
	 * Page Yume à créer (ou page existante de même adresse adoptée).
	 *
	 * @param array $p Page du plan (pages.creer[]).
	 * @throws \RuntimeException Parent absent.
	 */
	private function page( array $p ): void {
		$cle    = (string) $p['cle'];
		$parent = ! empty( $p['parent'] ) ? (int) ( $this->journal['correspondances']['page'][ $p['parent'] ] ?? 0 ) : 0;
		if ( ! empty( $p['parent'] ) && ! $parent ) {
			/* translators: %s: clé de la page parente. */
			throw new \RuntimeException( sprintf( __( 'page parente « %s » non créée.', 'yume-core' ), $p['parent'] ) );
		}
		$id = $this->existant( 'page', $cle, 'page' );
		if ( ! $id ) {
			$id = $this->adopter_page( $p, $parent );
		}
		if ( ! $id ) {
			$id = $this->enregistrer(
				array(
					'post_type'      => 'page',
					'post_title'     => $p['post_title'],
					'post_name'      => $p['post_name'],
					'post_status'    => $p['post_status'] ?? 'publish',
					'post_content'   => (string) ( $p['post_content'] ?? '' ),
					'post_parent'    => $parent,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'post_author'    => $this->auteur( null ),
					'meta_input'     => array(
						self::META_CLE => 'page:' . $cle,
						self::META_RUN => (string) $this->etat['run'],
					),
				),
				0
			);
			$this->compter( 'pages_creees' );
		}
		$this->correspondance( 'page', $cle, $id, null );
		$pages         = get_option( 'yume_pages', array() );
		$pages         = is_array( $pages ) ? $pages : array();
		$pages[ $cle ] = $id;
		update_option( 'yume_pages', $pages );
		$this->noter_option( 'yume_pages' );
	}

	/**
	 * Adopte une page existante à l'adresse voulue (créée avant la migration, hors pages de
	 * l'ancien site traitées par le plan) : publiée, contenu posé s'il est vide. Son état est
	 * sauvegardé pour l'annulation.
	 *
	 * @param array $p         Page du plan.
	 * @param int   $parent_id Page parente (0 : aucune).
	 * @return int ID adopté (0 si aucune).
	 */
	private function adopter_page( array $p, int $parent_id ): int {
		global $wpdb;
		$chemin = $p['post_name'];
		if ( $parent_id ) {
			$chemin = get_page_uri( $parent_id ) . '/' . $chemin;
		}
		$page = get_page_by_path( $chemin, OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post || 'trash' === $page->post_status ) {
			return 0;
		}
		$anciennes = array();
		foreach ( array( 'conserver', 'remplacer', 'ignorer' ) as $liste ) {
			foreach ( (array) ( $this->plan['pages'][ $liste ] ?? array() ) as $ancienne ) {
				$anciennes[] = (int) $ancienne['id'];
			}
		}
		if ( in_array( (int) $page->ID, $anciennes, true ) || '' !== (string) get_post_meta( $page->ID, self::META_CLE, true ) ) {
			return 0;
		}
		$this->journal['modifications']['pages_adoptees'][ (int) $page->ID ] = array(
			'post_status'   => $page->post_status,
			'post_content'  => $page->post_content,
			'post_modified' => $page->post_modified,
		);
		if ( '' === trim( (string) $page->post_content ) && '' !== (string) ( $p['post_content'] ?? '' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $p['post_content'] ), array( 'ID' => $page->ID ) );
			clean_post_cache( $page->ID );
		}
		if ( 'publish' !== $page->post_status ) {
			self::changer_statut( (int) $page->ID, 'publish' );
		}
		/* translators: 1: titre, 2: ID. */
		$this->message( sprintf( __( 'Page existante « %1$s » (ID %2$d) reprise comme page Yume.', 'yume-core' ), $page->post_title, $page->ID ) );
		$this->compter( 'pages_adoptees' );
		return (int) $page->ID;
	}

	/**
	 * Ancienne page remplacée : passée en brouillon (jamais supprimée).
	 *
	 * @param array $p Page du plan (pages.remplacer[]).
	 */
	private function ancienne_page( array $p ): void {
		$id   = (int) $p['id'];
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'publish', 'private', 'future' ), true ) ) {
			self::changer_statut( $id, 'draft' );
			$this->journal['modifications']['pages_depubliees'][ $id ] = $post->post_status;
			$this->compter( 'pages_depubliees' );
		}
	}

	/**
	 * Réglages : page d'accueil, page des articles (12 par page), bannière du site, inscription
	 * des lecteurs (« Tout le monde peut s'inscrire », rôle par défaut Lecteur). Les valeurs
	 * d'origine sont sauvegardées à la préparation et restaurées par l'annulation.
	 */
	private function reglages(): void {
		// L'inscription en façade (page connexion, module lecteurs) dépend de ces deux réglages.
		if ( '1' !== (string) get_option( 'users_can_register' ) ) {
			update_option( 'users_can_register', 1 );
			$this->noter_option( 'users_can_register' );
		}
		if ( 'subscriber' !== get_option( 'default_role' ) ) {
			update_option( 'default_role', 'subscriber' );
			$this->noter_option( 'default_role' );
		}
		// Actualités, catégories et recherche en grilles de 2 ou 3 colonnes : 12 par page.
		if ( 12 !== (int) get_option( 'posts_per_page' ) ) {
			update_option( 'posts_per_page', 12 );
			$this->noter_option( 'posts_per_page' );
		}

		$pages = (array) ( $this->journal['correspondances']['page'] ?? array() );
		foreach ( (array) ( $this->plan['pages']['creer'] ?? array() ) as $p ) {
			$reglage = (string) ( $p['reglage'] ?? '' );
			if ( in_array( $reglage, array( 'page_on_front', 'page_for_posts' ), true ) && ! empty( $pages[ $p['cle'] ] ) ) {
				update_option( $reglage, (int) $pages[ $p['cle'] ] );
				$this->noter_option( $reglage );
			}
		}
		if ( ! empty( $pages['accueil'] ) ) {
			update_option( 'show_on_front', 'page' );
			$this->noter_option( 'show_on_front' );
		}
		$banniere = $this->media( (int) ( $this->plan['reglages']['banniere_id'] ?? 0 ) );
		if ( $banniere ) {
			$reglages = get_option( 'yume_reglages', array() );
			$reglages = is_array( $reglages ) ? $reglages : array();
			if ( empty( $reglages['banniere_id'] ) ) {
				$reglages['banniere_id'] = $banniere;
				update_option( 'yume_reglages', $reglages );
				$this->noter_option( 'yume_reglages' );
			}
		}
	}

	/**
	 * Chemin relatif à l'accueil d'une URL du site (« /oeuvres/grimgar/ »).
	 *
	 * @param string $url URL absolue.
	 */
	private static function chemin( string $url ): string {
		$base = untrailingslashit( (string) home_url() );
		if ( '' !== $base && str_starts_with( $url, $base ) ) {
			$url = substr( $url, strlen( $base ) );
		}
		return '' === $url ? '/' : $url;
	}

	/**
	 * Nouvelle URL publique d'un élément (permalien du contenu créé s'il est publié, sinon de
	 * son tome ou de son œuvre), ou null.
	 *
	 * @param string $type oeuvre, tome ou chapitre.
	 * @param string $cle  Clé du plan.
	 */
	private function url_element( string $type, string $cle ): ?string {
		$ordre = array( 'chapitre', 'tome', 'oeuvre' );
		$pos   = array_search( $type, $ordre, true );
		if ( false === $pos ) {
			return null;
		}
		foreach ( array_slice( $ordre, (int) $pos ) as $niveau ) {
			$cle_niveau = $cle;
			if ( 'tome' === $niveau && 'chapitre' === $type ) {
				$cle_niveau = substr( $cle, 0, (int) strrpos( $cle, '/' ) );
			} elseif ( 'oeuvre' === $niveau && 'oeuvre' !== $type ) {
				$cle_niveau = strtok( $cle, '/' );
			}
			$id = (int) ( $this->journal['correspondances'][ $niveau ][ $cle_niveau ] ?? 0 );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$lien = get_permalink( $id );
				return $lien ? self::chemin( (string) $lien ) : null;
			}
		}
		return null;
	}

	/**
	 * Redirections 301 : table du plan, cibles recalculées sur les contenus réellement créés.
	 */
	private function redirections(): void {
		$entrees = array();
		$pages   = (array) ( $this->journal['correspondances']['page'] ?? array() );
		$cibles  = (array) ( $this->journal['categories_cibles'] ?? array() );
		foreach ( (array) ( $this->plan['redirections'] ?? array() ) as $r ) {
			$cible = (string) $r['cible'];
			$type  = (string) ( $r['type'] ?? '' );
			if ( in_array( $type, array( 'oeuvre', 'tome', 'chapitre' ), true ) && ! empty( $r['cle'] ) ) {
				$cible = $this->url_element( $type, (string) $r['cle'] ) ?? $cible;
			} elseif ( 'hub' === $type && ! empty( $pages['bibliotheque'] ) && str_starts_with( $cible, '/bibliotheque/' ) ) {
				$lien  = get_permalink( (int) $pages['bibliotheque'] );
				$cible = $lien ? self::chemin( (string) $lien ) . substr( $cible, strlen( '/bibliotheque/' ) ) : $cible;
			} elseif ( 'categorie' === $type && preg_match( '#^/category/([^/]+)/$#', $cible, $m ) && ! empty( $cibles[ $m[1] ] ) ) {
				$lien  = get_term_link( (int) $cibles[ $m[1] ], 'category' );
				$cible = is_string( $lien ) ? self::chemin( $lien ) : $cible;
			}
			$entrees[ (string) $r['source'] ] = $cible;
		}
		$ecrites                                        = Redirections::ajouter( $entrees );
		$this->journal['modifications']['redirections'] = array_values( array_unique( array_merge( (array) $this->journal['modifications']['redirections'], $ecrites ) ) );
		$this->noter_option( 'yume_redirections' );
		/* translators: %d: nombre de redirections. */
		$this->message( sprintf( __( '%d redirection(s) 301 enregistrée(s).', 'yume-core' ), count( $ecrites ) ) );
		$this->compter( 'redirections', count( $ecrites ) );
	}

	/**
	 * Nettoyage : suppression des catégories vidées (« Non classé »), une fois les articles
	 * reclassés et la catégorie par défaut changée.
	 */
	private function nettoyage(): void {
		foreach ( (array) ( $this->plan['categories'] ?? array() ) as $cat ) {
			if ( 'supprimer' !== ( $cat['action'] ?? '' ) || empty( $cat['id'] ) ) {
				continue;
			}
			$id    = (int) $cat['id'];
			$terme = get_term( $id, 'category' );
			if ( ! $terme instanceof \WP_Term || (int) get_option( 'default_category' ) === $id ) {
				continue;
			}
			$res = wp_delete_term( $id, 'category' );
			if ( true === $res ) {
				$this->journal['modifications']['categories_supprimees'][] = $id;
				/* translators: %s: nom de la catégorie. */
				$this->message( sprintf( __( 'Catégorie « %s » supprimée (articles reclassés).', 'yume-core' ), $terme->name ) );
			}
		}
	}
}
