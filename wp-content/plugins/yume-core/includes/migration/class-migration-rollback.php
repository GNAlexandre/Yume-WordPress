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
			'redirections'    => __( 'Retrait des redirections', 'yume-core' ),
			'categories'      => __( 'Catégories', 'yume-core' ),
			'articles'        => __( 'Articles', 'yume-core' ),
			'reglages'        => __( 'Réglages et options', 'yume-core' ),
			'anciennes_pages' => __( 'Anciennes pages', 'yume-core' ),
			'pages'           => __( 'Pages Yume', 'yume-core' ),
			'chapitres'       => __( 'Chapitres', 'yume-core' ),
			'tomes'           => __( 'Tomes', 'yume-core' ),
			'oeuvres'         => __( 'Œuvres', 'yume-core' ),
			'terminer'        => __( 'Contrôle final', 'yume-core' ),
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
			'articles'        => array_map( 'intval', array_keys( (array) ( $journal['sauvegarde']['articles'] ?? array() ) ) ),
			'anciennes_pages' => array_map( 'intval', array_keys( (array) ( $journal['sauvegarde']['pages'] ?? array() ) ) ),
			'pages'           => array(),
			'chapitres'       => array(),
			'tomes'           => array(),
			'oeuvres'         => array(),
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
		if ( in_array( $etape, array( 'articles', 'anciennes_pages', 'pages', 'chapitres', 'tomes', 'oeuvres' ), true ) ) {
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
		Redirections::retirer( (array) ( $this->journal['modifications']['redirections'] ?? array() ) );
		$origine = $this->journal['sauvegarde']['options'][ Redirections::OPTION ] ?? null;
		$table   = get_option( Redirections::OPTION, array() );
		if ( is_array( $origine ) && empty( $origine['existe'] ) && empty( $table ) ) {
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
				if ( $tt_libre ) {
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
		} else {
			update_option( $option, $origine['valeur'] );
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
			return;
		}
		$diff                   = Migration_Empreinte::differences( $avant, Migration_Empreinte::calculer( $this->plan ) );
		$this->etat['controle'] = array(
			'date'        => current_time( 'mysql', true ),
			'differences' => $diff,
			'verifie'     => true,
		);
		if ( $diff ) {
			/* translators: %s: liste des éléments. */
			$this->message( sprintf( __( 'Contrôle : %s diffèrent de l’état d’origine.', 'yume-core' ), implode( ', ', array_slice( $diff, 0, 20 ) ) ), 'avertissement' );
		} else {
			$this->message( __( 'Contrôle : les contenus touchés sont revenus à leur état d’origine.', 'yume-core' ), 'succes' );
		}
	}
}
