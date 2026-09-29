<?php
/**
 * Remplacement de la lecture en ligne d'un tome déjà paru, en deux temps (contrat §8) :
 *
 * 1. Préparation (Service::preparer() avec un nouveau DOCX/EPUB, boutons « Vérifier (sans rien
 *    changer en ligne) », « Enregistrer en brouillon » ou « Prévisualiser ») : chaque chapitre du
 *    fichier devient une VERSION EN ATTENTE, un chapitre au statut interne « yume_remplacement »
 *    (jamais public, hors de toutes les listes, sommaires et compteurs qui ne retiennent que les
 *    statuts publish, future, draft, pending, private) rattaché au tome, avec la méta
 *    _yume_remplacement_de = ID du chapitre en ligne qu'elle remplacera (0 : chapitre nouveau).
 *    Les chapitres en ligne ne sont PAS modifiés. Les images nouvelles sont versées sans
 *    rattachement ; l'état de la préparation est noté sur le tome (méta _yume_remplacement).
 * 2. Application (Service::publier() : « Remplacer la lecture en ligne maintenant » ou « Publier
 *    maintenant ») : chaque version en attente est recopiée EN PLACE dans son chapitre en ligne
 *    (même ID, adresse, date, commentaires), puis supprimée ; une version sans chapitre en ligne
 *    devient un chapitre en brouillon, publié ensuite comme d'habitude ; images rattachées à leur
 *    chapitre, galerie et couverture posées. Annulation : versions et images nouvelles supprimées.
 *
 * Un seul remplacement en attente par tome (celui d'un autre membre bloque une nouvelle
 * préparation) ; une préparation abandonnée est supprimée au bout de 7 jours (tâche cron unique
 * yume_publication_nettoyer_remplacement, et au prochain envoi ou affichage du formulaire).
 * Aperçu d'une version en attente : équipe seulement (droit de modifier le chapitre, 404 pour un
 * visiteur, comme un brouillon), noindex.
 *
 * Tome non publié, ou publié sans chapitre en ligne : pas de préparation séparée, les chapitres
 * sont créés ou mis à jour directement (comportement de Service::preparer()).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

/**
 * Versions en attente d'un remplacement de lecture en ligne.
 */
final class Remplacement {

	/** Statut interne d'une version en attente (chapitre). */
	public const STATUT = 'yume_remplacement';

	/** Méta d'une version en attente : chapitre en ligne remplacé (0 : chapitre nouveau). */
	public const META_DE = '_yume_remplacement_de';

	/** Méta d'une version en attente : cree | maj | inchange (par rapport au chapitre en ligne). */
	public const META_ACTION = '_yume_remplacement_action';

	/** Méta d'une version en attente : images qu'elle utilise (ID de pièces jointes). */
	public const META_MEDIAS = '_yume_remplacement_medias';

	/**
	 * Méta du tome : préparation en attente {par, cree (horodatage), fichier, resume, medias
	 * (images versées pour elle), galerie (ID ou null), couverture, retirer_absents, absents}.
	 */
	public const META_TOME = '_yume_remplacement';

	/** Tâche cron unique de nettoyage d'une préparation abandonnée (argument : tome). */
	public const HOOK_NETTOYAGE = 'yume_publication_nettoyer_remplacement';

	/**
	 * Enregistre le statut interne (non public, protégé comme un brouillon : aperçu réservé à qui
	 * peut modifier le chapitre ; absent des listes de l'administration et des requêtes « any »).
	 */
	public static function enregistrer_statut(): void {
		register_post_status(
			self::STATUT,
			array(
				'label'                     => _x( 'Version en attente', 'statut de chapitre', 'yume-core' ),
				'public'                    => false,
				'protected'                 => true,
				'internal'                  => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * Durée de vie d'une préparation non appliquée (secondes, 7 jours par défaut).
	 */
	public static function duree(): int {
		/**
		 * Durée de vie d'un remplacement de lecture en ligne préparé mais ni appliqué ni annulé.
		 *
		 * @param int $duree Secondes (7 jours).
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'yume_publication_remplacement_duree', 7 * DAY_IN_SECONDS ) );
	}

	/**
	 * Le tome est-il en mode remplacement : publié, avec au moins un chapitre en ligne ?
	 *
	 * @param \WP_Post|int|null $tome Tome.
	 */
	public static function mode( $tome ): bool {
		$tome = $tome ? get_post( $tome ) : null;
		return $tome instanceof \WP_Post && 'yume_tome' === $tome->post_type && 'publish' === $tome->post_status && count( yume_get_chapitres( (int) $tome->ID ) ) > 0;
	}

	/**
	 * Préparation en attente du tome (méta brute), ou null.
	 *
	 * @param int $tome_id Tome.
	 * @return array<string,mixed>|null
	 */
	public static function lire( int $tome_id ): ?array {
		$etat = $tome_id ? get_post_meta( $tome_id, self::META_TOME, true ) : '';
		return is_array( $etat ) && ! empty( $etat['cree'] ) ? $etat : null;
	}

	/**
	 * La préparation a-t-elle dépassé sa durée de vie ?
	 *
	 * @param array<string,mixed> $etat Préparation.
	 */
	private static function expiree( array $etat ): bool {
		return time() - (int) $etat['cree'] > self::duree();
	}

	/**
	 * Versions en attente du tome, dans l'ordre de lecture.
	 *
	 * @param int $tome_id Tome.
	 * @return \WP_Post[]
	 */
	public static function versions( int $tome_id ): array {
		return get_posts(
			array(
				'post_type'        => 'yume_chapitre',
				'post_status'      => self::STATUT,
				'posts_per_page'   => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- quelques dizaines de chapitres.
				'meta_query'       => array(
					array(
						'key'   => 'yume_tome_id',
						'value' => (string) $tome_id,
					),
				),
			)
		);
	}

	/**
	 * Refus d'une nouvelle préparation quand un autre membre a déjà un remplacement en attente
	 * pour ce tome (une préparation expirée est d'abord supprimée).
	 *
	 * @param int $tome_id Tome.
	 * @return \WP_Error|null
	 */
	public static function verrou( int $tome_id ): ?\WP_Error {
		$etat = self::nettoyer_si_expiree( $tome_id );
		if ( ! $etat || (int) ( $etat['par'] ?? 0 ) === get_current_user_id() ) {
			return null;
		}
		return new \WP_Error(
			'yume_remplacement_en_attente',
			sprintf(
				/* translators: 1: membre, 2: date */
				__( 'Un remplacement de la lecture en ligne de ce tome est déjà en attente (préparé par %1$s le %2$s). Un seul remplacement peut attendre par tome : appliquez-le (« Remplacer la lecture en ligne maintenant ») ou annulez-le avant d’en préparer un autre.', 'yume-core' ),
				self::auteur( $etat ),
				Formulaire::date_fr( (int) $etat['cree'] )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Nom affiché de l'auteur d'une préparation.
	 *
	 * @param array<string,mixed> $etat Préparation.
	 */
	private static function auteur( array $etat ): string {
		$user = get_userdata( (int) ( $etat['par'] ?? 0 ) );
		return $user ? (string) $user->display_name : __( 'un membre de l’équipe', 'yume-core' );
	}

	/**
	 * Début d'une nouvelle préparation : les versions en attente précédentes (du même membre)
	 * sont supprimées, leurs images gardées pour être réutilisées (même empreinte).
	 *
	 * @param int $tome_id Tome.
	 * @return int[] Images versées pour la préparation précédente.
	 */
	public static function debuter( int $tome_id ): array {
		$etat   = self::lire( $tome_id );
		$medias = $etat ? array_map( 'intval', (array) ( $etat['medias'] ?? array() ) ) : array();
		foreach ( self::versions( $tome_id ) as $version ) {
			wp_delete_post( (int) $version->ID, true );
		}
		delete_post_meta( $tome_id, self::META_TOME );
		return $medias;
	}

	/**
	 * Enregistre la préparation : images versées pour elle (celles que plus aucune version
	 * n'utilise sont supprimées), état noté sur le tome, nettoyage programmé.
	 *
	 * @param int                 $tome_id  Tome.
	 * @param array<string,mixed> $etat     fichier, resume, galerie, couverture, retirer_absents, absents.
	 * @param int[]               $medias   Images versées pour cette préparation ou la précédente.
	 * @param int[]               $utilises Images utilisées par cette préparation.
	 */
	public static function terminer( int $tome_id, array $etat, array $medias, array $utilises ): void {
		$utilises = array_flip( array_map( 'intval', $utilises ) );
		$garder   = array();
		foreach ( array_unique( array_map( 'intval', $medias ) ) as $id ) {
			if ( isset( $utilises[ $id ] ) ) {
				$garder[] = $id;
			} else {
				self::supprimer_media( $id );
			}
		}
		$etat = array_merge(
			$etat,
			array(
				'par'    => get_current_user_id(),
				'cree'   => time(),
				'medias' => $garder,
			)
		);
		update_post_meta( $tome_id, self::META_TOME, $etat );
		wp_clear_scheduled_hook( self::HOOK_NETTOYAGE, array( $tome_id ) );
		wp_schedule_single_event( time() + self::duree() + MINUTE_IN_SECONDS, self::HOOK_NETTOYAGE, array( $tome_id ) );
	}

	/**
	 * Supprime une image versée pour une préparation, si rien ne l'a rattachée depuis.
	 *
	 * @param int $id Pièce jointe.
	 */
	private static function supprimer_media( int $id ): void {
		$media = get_post( $id );
		if ( $media && 'attachment' === $media->post_type && 0 === (int) $media->post_parent ) {
			wp_delete_attachment( $id, true );
		}
	}

	/**
	 * Méta recopiées d'une version en attente dans le chapitre en ligne.
	 *
	 * @return string[]
	 */
	private static function cles_recopiees(): array {
		return array( 'yume_nature', 'yume_numero', 'yume_sous_titre', 'yume_nb_mots', 'yume_temps_lecture', 'yume_source', 'yume_credits' );
	}

	/**
	 * Applique la préparation en attente : versions recopiées en place dans les chapitres en
	 * ligne (mêmes ID, adresses, dates, commentaires) puis supprimées ; versions nouvelles
	 * changées en chapitres brouillons (Service::publier() les publie ensuite) ; chapitres absents
	 * mis en brouillon si demandé ; images rattachées ; galerie et couverture posées.
	 *
	 * @param int $tome_id Tome.
	 * @return array{remplaces:int,inchanges:int,nouveaux:int,retires:int}|null Null : rien en attente.
	 */
	public static function appliquer( int $tome_id ): ?array {
		$etat = self::lire( $tome_id );
		if ( ! $etat ) {
			return null;
		}
		$stats    = array(
			'remplaces' => 0,
			'inchanges' => 0,
			'nouveaux'  => 0,
			'retires'   => 0,
		);
		$traites  = array();
		$en_place = array();
		foreach ( self::versions( $tome_id ) as $version ) {
			$vid     = (int) $version->ID;
			$medias  = array_map( 'intval', (array) get_post_meta( $vid, self::META_MEDIAS, true ) );
			$cible   = (int) get_post_meta( $vid, self::META_DE, true );
			$chap    = $cible ? get_post( $cible ) : null;
			$valable = $chap instanceof \WP_Post && 'yume_chapitre' === $chap->post_type
				&& in_array( $chap->post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true )
				&& (int) get_post_meta( $cible, 'yume_tome_id', true ) === $tome_id && ! isset( $en_place[ $cible ] );
			if ( ! $valable ) {
				// Chapitre nouveau (ou chapitre en ligne supprimé depuis) : brouillon du tome.
				wp_update_post(
					array(
						'ID'          => $vid,
						'post_status' => 'draft',
					)
				);
				delete_post_meta( $vid, self::META_DE );
				delete_post_meta( $vid, self::META_ACTION );
				delete_post_meta( $vid, self::META_MEDIAS );
				self::rattacher( $medias, $vid );
				$traites[ $vid ] = true;
				++$stats['nouveaux'];
				continue;
			}
			$en_place[ $cible ] = true;
			$meta               = array();
			foreach ( self::cles_recopiees() as $cle ) {
				if ( metadata_exists( 'post', $vid, $cle ) ) {
					$meta[ $cle ] = get_post_meta( $vid, $cle, true );
				}
			}
			if ( $chap->post_content === $version->post_content && $chap->post_title === $version->post_title && (int) $chap->menu_order === (int) $version->menu_order ) {
				++$stats['inchanges'];
				foreach ( $meta as $cle => $valeur ) {
					update_post_meta( $cible, $cle, wp_slash( $valeur ) );
				}
			} else {
				$maj = wp_update_post(
					wp_slash(
						array(
							'ID'           => $cible,
							'post_title'   => $version->post_title,
							'post_content' => $version->post_content,
							'menu_order'   => (int) $version->menu_order,
							'meta_input'   => $meta,
						)
					),
					true
				);
				if ( is_wp_error( $maj ) ) {
					continue; // Version gardée : elle sera supprimée à l'annulation ou au nettoyage.
				}
				++$stats['remplaces'];
			}
			if ( ! metadata_exists( 'post', $vid, 'yume_numero' ) ) {
				delete_post_meta( $cible, 'yume_numero' );
			}
			delete_post_meta( $cible, Service::META_RETIRE );
			self::rattacher( $medias, $cible );
			wp_delete_post( $vid, true );
			$traites[ $cible ] = true;
		}

		// Chapitres absents du nouveau fichier : mis en brouillon seulement si demandé.
		if ( ! empty( $etat['retirer_absents'] ) ) {
			foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) as $chap ) {
				if ( isset( $traites[ (int) $chap->ID ] ) ) {
					continue;
				}
				if ( in_array( $chap->post_status, array( 'publish', 'future', 'pending', 'private' ), true ) ) {
					wp_update_post(
						array(
							'ID'          => (int) $chap->ID,
							'post_status' => 'draft',
						)
					);
				}
				update_post_meta( (int) $chap->ID, Service::META_RETIRE, 1 );
				++$stats['retires'];
			}
		}

		// Galerie et couverture tirées du fichier.
		if ( isset( $etat['galerie'] ) && is_array( $etat['galerie'] ) && $etat['galerie'] ) {
			$galerie = array_map( 'intval', $etat['galerie'] );
			update_post_meta( $tome_id, 'yume_illustrations', $galerie );
			self::rattacher( $galerie, $tome_id );
		}
		$couverture = (int) ( $etat['couverture'] ?? 0 );
		if ( $couverture && ! has_post_thumbnail( $tome_id ) && wp_attachment_is_image( $couverture ) ) {
			set_post_thumbnail( $tome_id, $couverture );
			self::rattacher( array( $couverture ), $tome_id );
		}
		// Images de la préparation qu'aucun contenu n'a reprises (ex. chapitre en erreur).
		foreach ( array_map( 'intval', (array) ( $etat['medias'] ?? array() ) ) as $id ) {
			if ( ! self::versions_utilisent( $tome_id, $id ) ) {
				self::rattacher( array( $id ), $tome_id );
			}
		}

		// Fichier source de la lecture en ligne désormais en place.
		$meta = get_post_meta( $tome_id, Service::META, true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( ! empty( $etat['fichier'] ) ) {
			$meta['fichier'] = $etat['fichier'];
			$meta['resume']  = (string) ( $etat['resume'] ?? '' );
		}
		update_post_meta( $tome_id, Service::META, $meta );

		if ( ! self::versions( $tome_id ) ) {
			delete_post_meta( $tome_id, self::META_TOME );
			wp_clear_scheduled_hook( self::HOOK_NETTOYAGE, array( $tome_id ) );
		}
		clean_post_cache( $tome_id );
		return $stats;
	}

	/**
	 * Une version encore en attente utilise-t-elle cette image ?
	 *
	 * @param int $tome_id Tome.
	 * @param int $media   Pièce jointe.
	 */
	private static function versions_utilisent( int $tome_id, int $media ): bool {
		foreach ( self::versions( $tome_id ) as $version ) {
			if ( in_array( $media, array_map( 'intval', (array) get_post_meta( $version->ID, self::META_MEDIAS, true ) ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rattache des images (sans rattachement) à un contenu.
	 *
	 * @param int[] $ids     Pièces jointes.
	 * @param int   $post_id Contenu.
	 */
	private static function rattacher( array $ids, int $post_id ): void {
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) && 0 === (int) wp_get_post_parent_id( $id ) ) {
				wp_update_post(
					array(
						'ID'          => $id,
						'post_parent' => $post_id,
					)
				);
			}
		}
	}

	/**
	 * Annule la préparation : versions en attente et images versées pour elle supprimées ;
	 * les chapitres en ligne ne changent pas.
	 *
	 * @param int $tome_id Tome.
	 * @return bool Vrai si une préparation a été annulée.
	 */
	public static function annuler( int $tome_id ): bool {
		$etat     = self::lire( $tome_id );
		$versions = self::versions( $tome_id );
		if ( ! $etat && ! $versions ) {
			return false;
		}
		$medias = $etat ? array_map( 'intval', (array) ( $etat['medias'] ?? array() ) ) : array();
		foreach ( $versions as $version ) {
			wp_delete_post( (int) $version->ID, true );
		}
		foreach ( array_unique( $medias ) as $id ) {
			self::supprimer_media( $id );
		}
		delete_post_meta( $tome_id, self::META_TOME );
		wp_clear_scheduled_hook( self::HOOK_NETTOYAGE, array( $tome_id ) );
		return true;
	}

	/**
	 * Supprime la préparation du tome si elle a expiré ; renvoie celle qui reste.
	 *
	 * @param int $tome_id Tome.
	 * @return array<string,mixed>|null
	 */
	public static function nettoyer_si_expiree( int $tome_id ): ?array {
		$etat = self::lire( $tome_id );
		if ( $etat && self::expiree( $etat ) ) {
			self::annuler( $tome_id );
			return null;
		}
		return $etat;
	}

	/**
	 * Tâche cron : supprime une préparation abandonnée (expirée) ; une préparation plus récente
	 * (reprogrammée) reste.
	 *
	 * @param int $tome_id Tome.
	 */
	public static function nettoyer( $tome_id ): void {
		self::nettoyer_si_expiree( (int) $tome_id );
	}

	/**
	 * État lisible de la préparation en attente (formulaire, réponses REST), ou null.
	 *
	 * @param int $tome_id Tome.
	 * @return array<string,mixed>|null {par, auteur, le, fichier, resume, expire_le, chapitres[{id,
	 *                                  libelle, titre, action, remplace, apercu, lien}], remplaces,
	 *                                  nouveaux, inchanges, absents, retirer_absents, message}.
	 */
	public static function etat( int $tome_id ): ?array {
		$etat = self::nettoyer_si_expiree( $tome_id );
		if ( ! $etat ) {
			return null;
		}
		$lignes   = array();
		$libelles = array(
			'cree'     => __( 'Nouveau', 'yume-core' ),
			'maj'      => __( 'Modifié', 'yume-core' ),
			'inchange' => __( 'Inchangé', 'yume-core' ),
		);
		$nb       = array(
			'cree'     => 0,
			'maj'      => 0,
			'inchange' => 0,
		);
		foreach ( self::versions( $tome_id ) as $version ) {
			$action = (string) get_post_meta( $version->ID, self::META_ACTION, true );
			$action = isset( $nb[ $action ] ) ? $action : 'maj';
			++$nb[ $action ];
			$cible    = (int) get_post_meta( $version->ID, self::META_DE, true );
			$lignes[] = array(
				'id'       => (int) $version->ID,
				'libelle'  => yume_libelle_chapitre( (int) $version->ID ),
				'titre'    => Service::titre_texte( $version ),
				'action'   => $action,
				'etat'     => $libelles[ $action ],
				'remplace' => $cible,
				'apercu'   => (string) get_preview_post_link( $version ),
				'lien'     => $cible && 'publish' === get_post_status( $cible ) ? (string) get_permalink( $cible ) : '',
			);
		}
		$absents           = count( (array) ( $etat['absents'] ?? array() ) );
		$resultat          = array(
			'par'             => (int) ( $etat['par'] ?? 0 ),
			'auteur'          => self::auteur( $etat ),
			'le'              => Formulaire::date_fr( (int) $etat['cree'] ),
			'expire_le'       => Formulaire::date_fr( (int) $etat['cree'] + self::duree(), 'long' ),
			'fichier'         => (string) ( $etat['fichier']['nom'] ?? '' ),
			'resume'          => (string) ( $etat['resume'] ?? '' ),
			'chapitres'       => $lignes,
			'remplaces'       => $nb['maj'],
			'nouveaux'        => $nb['cree'],
			'inchanges'       => $nb['inchange'],
			'absents'         => $absents,
			'retirer_absents' => ! empty( $etat['retirer_absents'] ),
		);
		$resultat['bilan'] = self::bilan( $resultat );
		$resultat['texte'] = sprintf(
			/* translators: 1: membre, 2: date, 3: nom du fichier, 4: bilan, 5: date d'expiration */
			__( 'Préparée par %1$s le %2$s à partir de « %3$s » : %4$s. Sans action, elle sera supprimée le %5$s.', 'yume-core' ),
			$resultat['auteur'],
			$resultat['le'],
			'' !== $resultat['fichier'] ? $resultat['fichier'] : __( 'fichier source', 'yume-core' ),
			$resultat['bilan'],
			$resultat['expire_le']
		);
		$resultat['message'] = sprintf(
			/* translators: %s : bilan (« 13 chapitres prêts : 2 modifiés, 1 nouveau, 10 inchangés ») */
			__( 'Vérification terminée, rien n’a changé en ligne : %s. Contrôlez les aperçus, puis cliquez sur « Remplacer la lecture en ligne maintenant » (ou « Annuler le remplacement »).', 'yume-core' ),
			$resultat['bilan']
		);
		return $resultat;
	}

	/**
	 * Bilan d'une préparation (« 13 chapitres prêts : 2 modifiés, 1 nouveau, 10 inchangés ;
	 * 1 chapitre en ligne absent du fichier, il restera en ligne »).
	 *
	 * @param array<string,mixed> $etat État lisible (etat()).
	 */
	public static function bilan( array $etat ): string {
		$total  = count( $etat['chapitres'] );
		$parts  = array();
		$nombre = array(
			/* translators: %d : nombre de chapitres modifiés */
			'remplaces' => _n( '%d modifié', '%d modifiés', (int) $etat['remplaces'], 'yume-core' ),
			/* translators: %d : nombre de chapitres nouveaux */
			'nouveaux'  => _n( '%d nouveau', '%d nouveaux', (int) $etat['nouveaux'], 'yume-core' ),
			/* translators: %d : nombre de chapitres inchangés */
			'inchanges' => _n( '%d inchangé', '%d inchangés', (int) $etat['inchanges'], 'yume-core' ),
		);
		foreach ( $nombre as $cle => $format ) {
			if ( (int) $etat[ $cle ] > 0 ) {
				$parts[] = sprintf( $format, (int) $etat[ $cle ] );
			}
		}
		/* translators: %d : nombre de chapitres */
		$texte   = sprintf( _n( '%d chapitre prêt', '%d chapitres prêts', $total, 'yume-core' ), $total ) . ( $parts ? ' (' . implode( ', ', $parts ) . ')' : '' );
		$absents = (int) $etat['absents'];
		if ( $absents > 0 ) {
			$texte .= ' ; ' . sprintf(
				/* translators: %d : nombre de chapitres */
				_n( '%d chapitre en ligne absent du fichier', '%d chapitres en ligne absents du fichier', $absents, 'yume-core' ),
				$absents
			) . ( $etat['retirer_absents']
				? __( ', mis en brouillon au remplacement', 'yume-core' )
				: _n( ', laissé en ligne', ', laissés en ligne', $absents, 'yume-core' ) );
		}
		return $texte;
	}

	/**
	 * Aperçu d'une version en attente : jamais indexé.
	 *
	 * @param array<string,bool|string> $robots Directives.
	 * @return array<string,bool|string>
	 */
	public static function robots( $robots ) {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && self::STATUT === $post->post_status ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/**
	 * Aperçu d'une version en attente : aucune mise en cache (équipe seulement).
	 */
	public static function sans_cache(): void {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && self::STATUT === $post->post_status ) {
			nocache_headers();
		}
	}
}
