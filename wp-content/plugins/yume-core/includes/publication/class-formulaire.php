<?php
/**
 * Formulaire « Ajouter des chapitres à un tome » (bloc yume/publish-form, maquettes TeamPublier
 * et « Ajouter des chapitres ») :
 * - le tome d'abord : œuvre puis tome existant (liste de tous ses tomes avec leur parution,
 *   ?tome=ID présélectionne), lien « + Nouveau tome » (filtre yume_url_nouveau_tome) ; sans tome
 *   choisi, le tome peut encore être créé ici (nature, numéro, titre) ;
 * - le fichier, comparé au tome (chapitres nouveaux, en ligne identiques ou modifiés —
 *   « Garder la version en ligne » ou « Mettre à jour (sans annonce) » —, programmés,
 *   brouillons), rien n'est jamais retiré ; la sortie des nouveaux chapitres (maintenant, un
 *   par un au rythme, à une date), l'annonce, « Le tome est complet » avec les liens PDF/EPUB ;
 *   champ caché mode = chapitres (Service::MODE_CHAPITRES) ; « Vérifier » et « Remplacer la
 *   lecture en ligne » (encadré d'un tome paru) restent le remplacement en deux temps ;
 * - rendu serveur complet, utilisable sans JavaScript (envoi vers admin-post.php, nonce,
 *   messages de retour) ; le script du bloc ajoute le glisser-déposer, l'analyse immédiate
 *   du fichier déposé, le découpage manuel en chapitres (envoyé avec le fichier, champ caché
 *   « plan »), la barre de progression et l'envoi via l'API REST ;
 * - accès : visiteur → lien de connexion ; compte sans yume_publier → message clair ;
 * - menu d'administration Yume → « Publier un tome » menant à la page /equipe/publier/.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

/**
 * Formulaire, bloc et menu.
 */
final class Formulaire {

	/** Action admin-post et nonce du formulaire. */
	public const ACTION = 'yume_publication';

	/** Préfixe du transitoire de message de retour (par utilisateur). */
	public const RETOUR = 'yume_publication_retour_';

	/**
	 * Étapes (boutons) du formulaire. Remplacement de la lecture en ligne d'un tome paru :
	 * verifier (préparation en attente, rien ne change en ligne, comme brouillon et apercu),
	 * remplacer (application, comme publier), annuler_remplacement.
	 */
	public const ETAPES = array( 'brouillon', 'apercu', 'publier', 'programmer', 'verifier', 'remplacer', 'annuler_remplacement' );

	/**
	 * Vue de l'espace équipe « Nouveau tome » (lien « + Nouveau tome ») ; adresse réglable par le
	 * filtre yume_url_nouveau_tome.
	 */
	public const VUE_NOUVEAU_TOME = 'nouveau-tome';

	/**
	 * Adresse de création d'un tome (lien « + Nouveau tome ») : vue VUE_NOUVEAU_TOME de l'espace
	 * équipe (url_vue_equipe() du module planning), sinon le planning.
	 *
	 * @param int $oeuvre_id Œuvre présélectionnée (0 : aucune).
	 */
	public static function url_nouveau_tome( int $oeuvre_id = 0 ): string {
		if ( function_exists( '\Yume\Core\Planning\url_nouveau_tome' ) ) {
			$url = \Yume\Core\Planning\url_nouveau_tome( $oeuvre_id, 'publier' );
		} elseif ( function_exists( '\Yume\Core\Planning\url_vue_equipe' ) ) {
			$url = \Yume\Core\Planning\url_vue_equipe( self::VUE_NOUVEAU_TOME, $oeuvre_id ? array( 'oeuvre' => $oeuvre_id ) : array() );
		} else {
			$url = function_exists( 'yume_url_page' ) ? (string) yume_url_page( 'planning' ) : home_url( '/' );
		}
		/**
		 * Filtre l'adresse du lien « + Nouveau tome » du formulaire « Ajouter des chapitres ».
		 *
		 * @param string $url       Adresse.
		 * @param int    $oeuvre_id Œuvre choisie (0 : aucune).
		 */
		return (string) apply_filters( 'yume_url_nouveau_tome', $url, $oeuvre_id );
	}

	/**
	 * Enregistre le bloc yume/publish-form.
	 */
	public static function enregistrer_bloc(): void {
		if ( function_exists( 'yume_register_dynamic_block' ) ) {
			yume_register_dynamic_block( __DIR__ . '/blocks/publish-form' );
		}
	}

	/**
	 * Adresse de la page de publication si elle existe (option yume_pages ou equipe/publier).
	 */
	public static function url_page(): string {
		$pages = get_option( 'yume_pages', array() );
		$id    = is_array( $pages ) ? (int) ( $pages['publier'] ?? 0 ) : 0;
		$page  = $id ? get_post( $id ) : get_page_by_path( 'equipe/publier' );
		if ( $page instanceof \WP_Post && 'page' === $page->post_type && in_array( $page->post_status, array( 'publish', 'private' ), true ) ) {
			return (string) get_permalink( $page );
		}
		return '';
	}

	/**
	 * Sous-menu Yume → « Publier un tome » (capacité yume_publier).
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'yume',
			__( 'Publier un tome', 'yume-core' ),
			__( 'Publier un tome', 'yume-core' ),
			'yume_publier',
			'yume-publier',
			array( self::class, 'page_admin' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'rediriger_admin' ) );
		}
	}

	/**
	 * Le sous-menu mène directement à la page /equipe/publier/.
	 */
	public static function rediriger_admin(): void {
		$url = self::url_page();
		if ( '' !== $url && current_user_can( 'yume_publier' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Page de repli quand la page publique n'existe pas encore.
	 */
	public static function page_admin(): void {
		if ( ! current_user_can( 'yume_publier' ) ) {
			wp_die( esc_html__( 'Votre compte n’a pas le droit de publier un tome.', 'yume-core' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Publier un tome', 'yume-core' ) . '</h1>';
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'La page « Publier » de l’espace équipe n’existe pas encore. Créez une page enfant de « Équipe » avec l’adresse /equipe/publier/ et ajoutez-y le bloc « Formulaire de publication » (catégorie Yume Novel), ou lancez la migration qui crée les pages de l’espace équipe.', 'yume-core' ) . '</p>';
		if ( current_user_can( 'publish_pages' ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '">' . esc_html__( 'Créer une page', 'yume-core' ) . '</a></p>';
		}
		echo '</div></div>';
	}

	/**
	 * Visiteur non connecté qui envoie le formulaire : page de connexion.
	 */
	public static function traiter_anonyme(): void {
		wp_safe_redirect( wp_login_url( '' !== self::url_page() ? self::url_page() : home_url( '/' ) ) );
		exit;
	}

	/**
	 * Enregistre le message de retour affiché au prochain rendu du formulaire.
	 *
	 * @param array<string,mixed> $retour type (succes|erreur), message, liens, champs, rapport.
	 */
	private static function memoriser( array $retour ): void {
		set_transient( self::RETOUR . get_current_user_id(), $retour, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Adresse de retour vers le formulaire.
	 *
	 * @param int $tome_id Tome préparé (0 : aucun).
	 */
	private static function adresse_retour( int $tome_id ): string {
		$defaut = '' !== self::url_page() ? self::url_page() : home_url( '/' );
		$page   = wp_validate_redirect( (string) wp_get_referer(), $defaut );
		$page   = remove_query_arg( array( 'tome', 'yume_retour', 'oeuvre', 'nature', 'numero' ), $page );
		$args   = array( 'yume_retour' => 1 );
		if ( $tome_id ) {
			$args['tome'] = $tome_id;
		}
		return add_query_arg( $args, $page ) . '#yume-publication';
	}

	/**
	 * Champs saisis dans le formulaire classique.
	 *
	 * @return array<string,mixed>
	 */
	private static function champs_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter().
		$champs = array();
		foreach ( array( 'oeuvre_id', 'tome_id', 'nature', 'numero', 'titre', 'date_sortie', 'lien_pdf', 'lien_epub', 'couverture_id', 'mode', 'sortie', 'intervalle', 'complet', 'annoncer' ) as $cle ) {
			if ( isset( $_POST[ $cle ] ) && is_scalar( $_POST[ $cle ] ) ) {
				$champs[ $cle ] = sanitize_text_field( wp_unslash( (string) $_POST[ $cle ] ) );
			}
		}
		// Chapitres en ligne modifiés : « Garder la version en ligne » ou « Mettre à jour » (Service::choix()).
		if ( isset( $_POST['choix'] ) && is_array( $_POST['choix'] ) ) {
			$champs['choix'] = array();
			foreach ( wp_unslash( $_POST['choix'] ) as $cle => $action ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clés et valeurs contrôlées par Service::choix().
				if ( is_string( $cle ) && is_scalar( $action ) ) {
					$champs['choix'][ $cle ] = sanitize_key( (string) $action );
				}
			}
		}
		if ( isset( $_POST['credits'] ) && is_array( $_POST['credits'] ) ) {
			$credits = wp_unslash( $_POST['credits'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini ci-dessous.
			foreach ( array( 'traduction', 'relecture', 'edition' ) as $role ) {
				$champs['credits'][ $role ] = sanitize_text_field( is_scalar( $credits[ $role ] ?? '' ) ? (string) ( $credits[ $role ] ?? '' ) : '' );
			}
		}
		$champs['retirer_absents'] = ! empty( $_POST['retirer_absents'] );
		// Découpage manuel (JSON du champ caché « plan ») : contrôlé strictement par Service::plan().
		if ( isset( $_POST['plan'] ) && is_string( $_POST['plan'] ) && '' !== $_POST['plan'] ) {
			$champs['plan'] = wp_unslash( $_POST['plan'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON validé par Service::plan().
		}
		// Case « Ajout au catalogue » : champ caché « 0 » suivi de la case « 1 » (la dernière
		// valeur l'emporte) ; absente, la valeur par défaut dépend du tome.
		if ( isset( $_POST['sans_annonce'] ) && is_scalar( $_POST['sans_annonce'] ) ) {
			$champs['sans_annonce'] = rest_sanitize_boolean( sanitize_text_field( wp_unslash( (string) $_POST['sans_annonce'] ) ) );
		}
		// « Tome du planning » choisi : il est la cible ; ses œuvre, nature et numéro complètent
		// les champs laissés vides (sans JavaScript, rien n'a été prérempli).
		$planning = isset( $_POST['tome_planning'] ) ? absint( $_POST['tome_planning'] ) : 0;
		$tome     = $planning ? get_post( $planning ) : null;
		if ( $tome instanceof \WP_Post && 'yume_tome' === $tome->post_type && 'trash' !== $tome->post_status && current_user_can( 'edit_post', $tome->ID ) ) {
			$champs['tome_id'] = (string) $tome->ID;
			$prerempli         = self::valeurs_tome( $tome );
			foreach ( array( 'oeuvre_id', 'nature', 'numero', 'titre' ) as $cle ) {
				if ( '' === (string) ( $champs[ $cle ] ?? '' ) ) {
					$champs[ $cle ] = (string) $prerempli[ $cle ];
				}
			}
		}
		// phpcs:enable
		return $champs;
	}

	/**
	 * Traitement du formulaire sans JavaScript (admin-post.php?action=yume_publication).
	 */
	public static function traiter(): void {
		$etape = isset( $_POST['etape'] ) ? sanitize_key( wp_unslash( $_POST['etape'] ) ) : 'brouillon'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$etape = in_array( $etape, self::ETAPES, true ) ? $etape : 'brouillon';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- fichiers contrôlés par Fichiers::source().
		$fichiers = array(
			'source'     => isset( $_FILES['source'] ) && is_array( $_FILES['source'] ) ? $_FILES['source'] : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
			'couverture' => isset( $_FILES['couverture'] ) && is_array( $_FILES['couverture'] ) ? $_FILES['couverture'] : null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		);
		try {
			$nonce = isset( $_POST['_yume_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_yume_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
				self::memoriser(
					array(
						'type'    => 'erreur',
						'message' => __( 'La session a expiré : rechargez la page et recommencez.', 'yume-core' ),
					)
				);
				wp_safe_redirect( self::adresse_retour( 0 ) );
				exit;
			}
			if ( ! current_user_can( 'yume_publier' ) ) {
				wp_die( esc_html__( 'Votre compte n’a pas le droit de publier un tome.', 'yume-core' ), esc_html__( 'Accès refusé', 'yume-core' ), array( 'response' => 403 ) );
			}
			$champs = self::champs_post();
			// « Vérifier » et « Remplacer la lecture en ligne » : remplacement en deux temps explicite.
			if ( in_array( $etape, array( 'verifier', 'remplacer' ), true ) ) {
				$champs['mode'] = Service::MODE_REMPLACEMENT;
			}
			$chapitres_mode = Service::MODE_CHAPITRES === ( $champs['mode'] ?? '' );
			if ( $chapitres_mode && 'programmer' === $etape ) {
				$champs['sortie'] = 'date';
			}
			$sortie_choisie = in_array( $champs['sortie'] ?? '', Service::SORTIES, true ) ? (string) $champs['sortie'] : 'maintenant';
			// Champs gardés pour réafficher le formulaire (le découpage suit le fichier, jamais gardé).
			$saisis = array_diff_key( $champs, array( 'plan' => true ) );
			if ( 'annuler_remplacement' === $etape ) {
				$tome_id = absint( $champs['tome_id'] ?? 0 );
				$annule  = $tome_id && 'yume_tome' === get_post_type( $tome_id ) && current_user_can( 'edit_post', $tome_id ) && Remplacement::annuler( $tome_id );
				self::memoriser(
					array(
						'type'    => $annule ? 'succes' : 'erreur',
						'message' => $annule ? self::message_annulation() : __( 'Aucun remplacement de la lecture en ligne n’est en attente pour ce tome.', 'yume-core' ),
					)
				);
				wp_safe_redirect( self::adresse_retour( $tome_id ) );
				exit;
			}
			if ( ( 'programmer' === $etape || ( $chapitres_mode && 'publier' === $etape && 'date' === $sortie_choisie ) ) && '' === ( $champs['date_sortie'] ?? '' ) ) {
				self::memoriser(
					array(
						'type'    => 'erreur',
						'message' => __( 'Indiquez la date et l’heure de sortie pour programmer la publication.', 'yume-core' ),
						'champs'  => $saisis,
					)
				);
				wp_safe_redirect( self::adresse_retour( (int) ( $champs['tome_id'] ?? 0 ) ) );
				exit;
			}
			$rapport = Service::preparer( $champs, array_filter( $fichiers ) );
			if ( is_wp_error( $rapport ) ) {
				self::memoriser(
					array(
						'type'    => 'erreur',
						'message' => $rapport->get_error_message(),
						'champs'  => $saisis,
					)
				);
				wp_safe_redirect( self::adresse_retour( (int) ( $champs['tome_id'] ?? 0 ) ) );
				exit;
			}
			$tome_id   = (int) $rapport['tome']['id'];
			$message   = '';
			$type      = 'succes';
			$confirmer = false;
			$sortie    = null;
			$attente   = is_array( $rapport['remplacement'] ?? null ) ? $rapport['remplacement'] : null;
			if ( 'remplacer' === $etape && ! $attente ) {
				$type    = 'erreur';
				$message = __( 'Aucun remplacement n’est en attente : déposez le nouveau DOCX ou EPUB, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'yume-core' );
			} elseif ( 'verifier' === $etape && ! $attente ) {
				$type    = 'erreur';
				$message = __( 'Déposez le nouveau DOCX ou EPUB du tome, puis cliquez sur « Vérifier (sans rien changer en ligne) ».', 'yume-core' );
			} elseif ( $chapitres_mode && in_array( $etape, array( 'publier', 'programmer' ), true ) ) {
				// Ajout de chapitres : sortie des nouveaux chapitres (maintenant, au rythme, à une date).
				$avec_date = 'date' === $sortie_choisie || ( 'rythme' === $sortie_choisie && '' !== ( $champs['date_sortie'] ?? '' ) && '' === Service::rythme_texte( $tome_id ) );
				$sortie    = Service::publier(
					$tome_id,
					$avec_date ? (string) $champs['date_sortie'] : 'maintenant',
					array(
						'confirmer_vide' => ! empty( $_POST['confirmer_vide'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié plus haut.
						'sans_annonce'   => ! empty( $rapport['sans_annonce'] ),
						'mode'           => Service::MODE_CHAPITRES,
						'sortie'         => $sortie_choisie,
						'intervalle'     => isset( $champs['intervalle'] ) && is_numeric( $champs['intervalle'] ) ? (int) $champs['intervalle'] : Service::INTERVALLE_DEFAUT,
					)
				);
				if ( is_wp_error( $sortie ) ) {
					$type      = 'erreur';
					$message   = __( 'Le brouillon est enregistré, mais la publication a échoué :', 'yume-core' ) . ' ' . $sortie->get_error_message();
					$confirmer = 'yume_tome_vide' === $sortie->get_error_code();
				} else {
					$message = self::message_chapitres( $sortie );
				}
			} elseif ( in_array( $etape, array( 'publier', 'programmer', 'remplacer' ), true ) ) {
				$sortie = Service::publier(
					$tome_id,
					'programmer' === $etape ? (string) $champs['date_sortie'] : 'maintenant',
					array(
						'confirmer_vide' => ! empty( $_POST['confirmer_vide'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié plus haut.
						'sans_annonce'   => ! empty( $rapport['sans_annonce'] ),
					)
				);
				if ( is_wp_error( $sortie ) ) {
					$type      = 'erreur';
					$message   = __( 'Le brouillon est enregistré, mais la publication a échoué :', 'yume-core' ) . ' ' . $sortie->get_error_message();
					$confirmer = 'yume_tome_vide' === $sortie->get_error_code();
				} elseif ( ! empty( $sortie['sans_annonce'] ) ) {
					$message = self::message_catalogue( $sortie );
				} elseif ( 'publish' === $sortie['statut'] ) {
					$message = (int) ( $sortie['chapitres'] ?? 0 ) > 0
						/* translators: %s : titre du tome */
						? sprintf( __( '%s est en ligne ! Les chapitres, l’annonce et les notifications sont partis.', 'yume-core' ), $sortie['tome']['titre'] )
						/* translators: %s : titre du tome */
						: sprintf( __( '%s est en ligne ! L’annonce et les notifications sont parties ; la lecture en ligne reste à ajouter (Lecture à compléter).', 'yume-core' ), $sortie['tome']['titre'] );
				} else {
					/* translators: 1: titre du tome, 2: date */
					$message = sprintf( __( '%1$s sortira le %2$s.', 'yume-core' ), $sortie['tome']['titre'], self::date_fr( ( new \DateTimeImmutable( (string) $sortie['date'], wp_timezone() ) )->getTimestamp(), 'long' ) );
				}
			} elseif ( 'apercu' === $etape && $chapitres_mode && '' !== self::apercu_chapitres( $rapport ) ) {
				// Ajout de chapitres : aperçu du premier chapitre nouveau (ou mis à jour).
				wp_safe_redirect( self::apercu_chapitres( $rapport ) );
				exit;
			} elseif ( 'apercu' === $etape && ( ! empty( $attente['chapitres'][0]['apercu'] ) || ! empty( $rapport['chapitres'][0]['apercu'] ) ) ) {
				// Remplacement en attente : aperçu de la nouvelle version, jamais du chapitre en ligne.
				wp_safe_redirect( (string) ( $attente['chapitres'][0]['apercu'] ?? $rapport['chapitres'][0]['apercu'] ) );
				exit;
			} elseif ( $attente && ( 'verifier' === $etape || null !== $rapport['import'] ) && ! $chapitres_mode ) {
				$message = (string) $attente['message'];
			} elseif ( $chapitres_mode && is_array( $rapport['comparaison'] ?? null ) ) {
				$message = self::message_brouillon_chapitres( $rapport );
			} else {
				/* translators: 1: titre du tome, 2: nombre de chapitres */
				$message = sprintf( _n( 'Brouillon enregistré : %1$s, %2$d chapitre.', 'Brouillon enregistré : %1$s, %2$d chapitres.', count( $rapport['chapitres'] ), 'yume-core' ), $rapport['tome']['titre'], count( $rapport['chapitres'] ) );
			}
			$avertissements = (array) $rapport['avertissements'];
			if ( is_array( $sortie ) && ! empty( $sortie['remplacement_applique'] ) ) {
				// Remplacement appliqué : les chapitres absents ont été mis en brouillon.
				$avertissements = str_replace( __( 'Il(s) sera (seront) mis en brouillon au remplacement de la lecture en ligne.', 'yume-core' ), __( 'Il(s) a (ont) été mis en brouillon.', 'yume-core' ), $avertissements );
			}
			self::memoriser(
				array(
					'type'      => $type,
					'message'   => $message,
					'confirmer' => $confirmer,
					'rapport'   => array(
						'avertissements' => $avertissements,
						'import'         => $rapport['import'] ? array(
							'resume'    => $rapport['import']['resume'],
							'chapitres' => $rapport['import']['chapitres'],
							'fichier'   => $rapport['import']['fichier'],
						) : null,
					),
				)
			);
			wp_safe_redirect( self::adresse_retour( $tome_id ) );
			exit;
		} finally {
			// Le DOCX n'est jamais conservé, même en cas d'erreur.
			Fichiers::supprimer( $fichiers['source'] );
		}
	}

	/**
	 * Message de l'annulation d'un remplacement de lecture en ligne.
	 */
	public static function message_annulation(): string {
		return __( 'Remplacement annulé : la version en attente et ses images sont supprimées, la lecture en ligne n’a pas changé.', 'yume-core' );
	}

	/**
	 * Message de succès d'un ajout au catalogue (sans annonce).
	 *
	 * @param array<string,mixed> $sortie Résultat de Service::publier().
	 */
	public static function message_catalogue( array $sortie ): string {
		$nb = (int) $sortie['chapitres'];
		if ( ! empty( $sortie['remplacement'] ) ) {
			$en_ligne = (int) ( $sortie['en_ligne'] ?? 0 );
			/* translators: %d : chapitres en ligne */
			$detail = sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', $en_ligne, 'yume-core' ), $en_ligne );
			if ( $nb > 0 ) {
				/* translators: %d : nouveaux chapitres (publiés ou programmés) */
				$detail .= ' ; ' . sprintf( _n( '%d nouveau', '%d nouveaux', $nb, 'yume-core' ), $nb );
			}
			return sprintf(
				/* translators: 1: titre du tome, 2: « 13 chapitres en ligne ; 2 nouveaux » */
				__( '%1$s : lecture en ligne remplacée (%2$s), sans annonce : ni article, ni Discord, ni e-mail. La date de sortie du tome ne change pas.', 'yume-core' ),
				$sortie['tome']['titre'],
				$detail
			);
		}
		if ( 'publish' !== $sortie['statut'] ) {
			return sprintf(
				/* translators: 1: titre du tome, 2: date */
				__( '%1$s : lecture en ligne programmée le %2$s, sans annonce (ni article, ni Discord, ni e-mail).', 'yume-core' ),
				$sortie['tome']['titre'],
				self::date_fr( ( new \DateTimeImmutable( (string) $sortie['date'], wp_timezone() ) )->getTimestamp(), 'long' )
			);
		}
		return sprintf(
			/* translators: 1: titre du tome, 2: nombre de chapitres */
			_n( '%1$s : lecture en ligne ajoutée (%2$d chapitre), sans annonce : ni article, ni Discord, ni e-mail.', '%1$s : lecture en ligne ajoutée (%2$d chapitres), sans annonce : ni article, ni Discord, ni e-mail.', $nb, 'yume-core' ),
			$sortie['tome']['titre'],
			$nb
		);
	}

	/**
	 * Œuvres proposées (ID => libellé « Titre (LN) »).
	 *
	 * @return array<int,string>
	 */
	private static function oeuvres(): array {
		return self::liste_oeuvres();
	}

	/**
	 * Aperçu du premier chapitre nouveau (ou mis à jour) d'une préparation en mode chapitres.
	 *
	 * @param array<string,mixed> $rapport Rapport de Service::preparer().
	 */
	private static function apercu_chapitres( array $rapport ): string {
		foreach ( (array) ( $rapport['chapitres'] ?? array() ) as $chapitre ) {
			if ( in_array( $chapitre['action'] ?? '', array( 'cree', 'maj' ), true ) && ! empty( $chapitre['apercu'] ) ) {
				return (string) $chapitre['apercu'];
			}
		}
		return '';
	}

	/**
	 * Message d'un brouillon enregistré en mode chapitres (« Brouillon enregistré : … — 2 nouveaux
	 * chapitres en brouillon, 3 chapitres en ligne inchangés. Rien n'a changé en ligne. »).
	 *
	 * @param array<string,mixed> $rapport Rapport de Service::preparer().
	 */
	public static function message_brouillon_chapitres( array $rapport ): string {
		$c       = (array) $rapport['comparaison'];
		$parties = array();
		$nb      = (int) $c['nouveaux'] + (int) $c['brouillons'];
		/* translators: %d : nombre de chapitres */
		$parties[] = sprintf( _n( '%d nouveau chapitre en brouillon', '%d nouveaux chapitres en brouillon', $nb, 'yume-core' ), $nb );
		$gardes    = (int) $c['identiques'] + (int) $c['modifies'] - (int) $c['a_mettre_a_jour'];
		if ( $gardes > 0 ) {
			/* translators: %d : nombre de chapitres */
			$parties[] = sprintf( _n( '%d chapitre en ligne inchangé', '%d chapitres en ligne inchangés', $gardes, 'yume-core' ), $gardes );
		}
		if ( (int) $c['a_mettre_a_jour'] > 0 ) {
			/* translators: %d : nombre de chapitres */
			$parties[] = sprintf( _n( '%d chapitre en ligne à mettre à jour à la sortie (sans annonce)', '%d chapitres en ligne à mettre à jour à la sortie (sans annonce)', (int) $c['a_mettre_a_jour'], 'yume-core' ), (int) $c['a_mettre_a_jour'] );
		}
		if ( (int) $c['programmes'] > 0 ) {
			/* translators: %d : nombre de chapitres */
			$parties[] = sprintf( _n( '%d chapitre programmé mis à jour (date gardée)', '%d chapitres programmés mis à jour (dates gardées)', (int) $c['programmes'], 'yume-core' ), (int) $c['programmes'] );
		}
		return sprintf(
			/* translators: 1: titre du tome, 2: bilan */
			__( 'Brouillon enregistré : %1$s — %2$s. Rien n’a changé en ligne.', 'yume-core' ),
			$rapport['tome']['titre'],
			implode( ', ', $parties )
		);
	}

	/**
	 * Message de succès d'une sortie de chapitres (mode chapitres) : chapitres en ligne et
	 * programmés, annonce, mises à jour, tome complet.
	 *
	 * @param array<string,mixed> $sortie Résultat de Service::publier().
	 */
	public static function message_chapitres( array $sortie ): string {
		$en_ligne   = array();
		$programmes = array();
		foreach ( (array) ( $sortie['calendrier'] ?? array() ) as $ligne ) {
			if ( 'publish' === $ligne['statut'] ) {
				$en_ligne[] = $ligne;
			} elseif ( 'future' === $ligne['statut'] ) {
				$programmes[] = $ligne;
			}
		}
		$phrases = array();
		if ( ! $en_ligne && ! $programmes ) {
			$phrases[] = sprintf(
				/* translators: %s : titre du tome */
				__( '%s : aucun nouveau chapitre à sortir.', 'yume-core' ),
				$sortie['tome']['titre']
			);
		} else {
			$parties = array();
			if ( $en_ligne ) {
				/* translators: %d : nombre de chapitres */
				$parties[] = sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', count( $en_ligne ), 'yume-core' ), count( $en_ligne ) );
			}
			if ( 1 === count( $programmes ) ) {
				/* translators: 1: chapitre, 2: date */
				$parties[] = sprintf( __( '%1$s programmé le %2$s', 'yume-core' ), $programmes[0]['libelle'], $programmes[0]['date_libelle'] );
			} elseif ( $programmes ) {
				$parties[] = sprintf(
					/* translators: 1: nombre de chapitres, 2: première date, 3: dernière date */
					__( '%1$d chapitres programmés, du %2$s au %3$s', 'yume-core' ),
					count( $programmes ),
					$programmes[0]['date_libelle'],
					$programmes[ count( $programmes ) - 1 ]['date_libelle']
				);
			}
			if ( ! empty( $sortie['sans_annonce'] ) ) {
				$annonce = __( 'sans annonce (ni article, ni Discord, ni e-mail)', 'yume-core' );
			} elseif ( ! $en_ligne ) {
				$annonce = 'rythme' === ( $sortie['sortie'] ?? '' ) ? __( 'chaque chapitre sera annoncé à sa sortie', 'yume-core' ) : __( 'annonce à la sortie', 'yume-core' );
			} else {
				$annonce = __( 'annonce envoyée aux lecteurs qui suivent l’œuvre', 'yume-core' );
			}
			$phrases[] = sprintf(
				/* translators: 1: titre du tome, 2: chapitres en ligne et programmés, 3: annonce */
				__( '%1$s : %2$s ; %3$s.', 'yume-core' ),
				$sortie['tome']['titre'],
				implode( ', ', $parties ),
				$annonce
			);
		}
		$maj = is_array( $sortie['remplacement_applique'] ?? null ) ? (int) $sortie['remplacement_applique']['remplaces'] : 0;
		if ( $maj > 0 ) {
			/* translators: %d : nombre de chapitres */
			$phrases[] = sprintf( _n( '%d chapitre en ligne mis à jour en place, sans annonce.', '%d chapitres en ligne mis à jour en place, sans annonce.', $maj, 'yume-core' ), $maj );
		}
		if ( 'fait' === ( $sortie['complet'] ?? '' ) ) {
			$phrases[] = __( 'Le tome est complet : liens de téléchargement en ligne, planning à 100 %.', 'yume-core' );
		} elseif ( 'programme' === ( $sortie['complet'] ?? '' ) && '' !== (string) $sortie['complet_le'] ) {
			$phrases[] = sprintf(
				/* translators: %s : date */
				__( 'Le tome passera complet le %s, avec la sortie de son dernier chapitre.', 'yume-core' ),
				self::date_fr( ( new \DateTimeImmutable( (string) $sortie['complet_le'] ) )->getTimestamp(), 'long' )
			);
		}
		return implode( ' ', $phrases );
	}

	/**
	 * Œuvres proposées (ID => libellé « Titre (LN) »).
	 *
	 * @return array<int,string>
	 */
	private static function liste_oeuvres(): array {
		$posts  = get_posts(
			array(
				'post_type'        => 'yume_oeuvre',
				'post_status'      => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- liste des œuvres (quelques dizaines).
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$courts = array(
			'light-novel' => 'LN',
			'web-novel'   => 'WN',
			'manga'       => 'Manga',
		);
		$liste  = array();
		foreach ( $posts as $oeuvre ) {
			$termes                     = get_the_terms( $oeuvre->ID, 'yume_type' );
			$type                       = is_array( $termes ) && $termes ? ( $courts[ $termes[0]->slug ] ?? $termes[0]->name ) : '';
			$titre                      = '' !== $oeuvre->post_title ? $oeuvre->post_title : __( '(sans titre)', 'yume-core' );
			$liste[ (int) $oeuvre->ID ] = $titre . ( '' !== $type ? ' (' . $type . ')' : '' );
		}
		return $liste;
	}

	/**
	 * Valeurs initiales du formulaire (tome existant, paramètres d'URL, saisie à restaurer).
	 *
	 * @param array<string,mixed>|null $retour Message de retour.
	 * @return array<string,mixed>
	 */
	private static function valeurs( ?array $retour ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule pour préremplir.
		$v    = array(
			'tome_id'       => 0,
			'oeuvre_id'     => isset( $_GET['oeuvre'] ) ? absint( $_GET['oeuvre'] ) : 0,
			'nature'        => isset( $_GET['nature'] ) ? sanitize_key( wp_unslash( $_GET['nature'] ) ) : 'tome',
			'numero'        => isset( $_GET['numero'] ) ? sanitize_text_field( wp_unslash( $_GET['numero'] ) ) : '',
			'titre'         => '',
			'date_sortie'   => '',
			'lien_pdf'      => '',
			'lien_epub'     => '',
			'credits'       => array(
				'traduction' => '',
				'relecture'  => '',
				'edition'    => '',
			),
			'couverture_id' => 0,
			'sans_annonce'  => false,
			'tome'          => null,
			'sortie'        => 'maintenant',
			'intervalle'    => Service::INTERVALLE_DEFAUT,
			'complet'       => false,
		);
		$tome = isset( $_GET['tome'] ) ? get_post( absint( $_GET['tome'] ) ) : null;
		// phpcs:enable
		if ( $tome instanceof \WP_Post && 'yume_tome' === $tome->post_type && 'trash' !== $tome->post_status && current_user_can( 'edit_post', $tome->ID ) ) {
			$meta    = get_post_meta( $tome->ID, Service::META, true );
			$meta    = is_array( $meta ) ? $meta : array();
			$credits = get_post_meta( $tome->ID, 'yume_credits', true );
			$v       = array_merge(
				$v,
				self::valeurs_tome( $tome ),
				array(
					'tome_id'       => (int) $tome->ID,
					'lien_pdf'      => (string) get_post_meta( $tome->ID, 'yume_lien_pdf', true ),
					'lien_epub'     => (string) get_post_meta( $tome->ID, 'yume_lien_epub', true ),
					'credits'       => is_array( $credits ) ? array_merge( $v['credits'], $credits ) : $v['credits'],
					'couverture_id' => (int) get_post_thumbnail_id( $tome->ID ),
					'sans_annonce'  => Service::sans_annonce_par_defaut( $tome ),
					'tome'          => $tome,
					'meta'          => $meta,
					// « Le tome est complet » demandé à la dernière préparation (brouillon).
					'complet'       => ! empty( $meta['complet'] ),
				)
			);
			foreach ( (array) ( $meta['liens'] ?? array() ) as $cle => $lien ) {
				if ( in_array( $cle, array( 'lien_pdf', 'lien_epub' ), true ) && '' !== (string) $lien ) {
					$v[ $cle ] = (string) $lien;
				}
			}
		}
		if ( $retour && ! empty( $retour['champs'] ) && is_array( $retour['champs'] ) ) {
			foreach ( $retour['champs'] as $cle => $valeur ) {
				if ( array_key_exists( $cle, $v ) && 'tome' !== $cle ) {
					$v[ $cle ] = $valeur;
				}
			}
		}
		$v['sans_annonce'] = (bool) $v['sans_annonce'];
		$v['complet']      = (bool) $v['complet'];
		if ( '' === $v['nature'] || ! isset( yume_natures_tome()[ $v['nature'] ] ) ) {
			$v['nature'] = 'tome';
		}
		if ( ! in_array( $v['sortie'], Service::SORTIES, true ) ) {
			$v['sortie'] = 'maintenant';
		}
		$v['intervalle'] = max( 1, min( 60, (int) $v['intervalle'] ) );
		return $v;
	}

	/**
	 * Champs préremplis depuis un tome existant : œuvre, nature, numéro (« 26,5 »), titre
	 * saisi à la dernière préparation, date de sortie (programmée, sinon saisie).
	 *
	 * @param \WP_Post $tome Tome.
	 * @return array{oeuvre_id:int,nature:string,numero:string,titre:string,date_sortie:string}
	 */
	public static function valeurs_tome( \WP_Post $tome ): array {
		$meta   = get_post_meta( $tome->ID, Service::META, true );
		$meta   = is_array( $meta ) ? $meta : array();
		$numero = get_post_meta( $tome->ID, 'yume_numero', true );
		$nature = (string) get_post_meta( $tome->ID, 'yume_nature', true );
		return array(
			'oeuvre_id'   => (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true ),
			'nature'      => '' === $nature ? 'tome' : $nature,
			'numero'      => is_numeric( $numero ) ? str_replace( '.', ',', rtrim( rtrim( number_format( (float) $numero, 3, '.', '' ), '0' ), '.' ) ) : '',
			'titre'       => (string) ( $meta['titre'] ?? '' ),
			'date_sortie' => 'future' === $tome->post_status ? substr( str_replace( ' ', 'T', $tome->post_date ), 0, 16 ) : (string) ( $meta['date_sortie'] ?? '' ),
		);
	}

	/**
	 * Tomes proposés dans la liste « Tome » du formulaire « Ajouter des chapitres » : TOUS les
	 * tomes (brouillons, programmés, publiés) que le compte peut modifier, par œuvre puis par
	 * numéro, avec leur parution (« Tome 2 · en cours · 3 chapitres en ligne »).
	 *
	 * @param int $inclure Tome à proposer même s'il manque à la liste (tome ouvert par ?tome=ID).
	 * @return array<int,array<string,mixed>> Liste de {id, oeuvre_id, libelle, nature, numero,
	 *                                        titre, date_sortie, programme, publie, parution,
	 *                                        parution_libelle, en_ligne, prevus, rythme,
	 *                                        sans_annonce, lien}.
	 */
	public static function tomes_formulaire( int $inclure = 0 ): array {
		$posts  = get_posts(
			array(
				'post_type'              => 'yume_tome',
				'post_status'            => array( 'draft', 'pending', 'future', 'publish', 'private' ),
				'posts_per_page'         => 3000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- tous les tomes du catalogue (quelques centaines).
				'orderby'                => 'menu_order',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		$ids    = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
		$ouvert = $inclure ? get_post( $inclure ) : null;
		if ( $ouvert instanceof \WP_Post && 'yume_tome' === $ouvert->post_type && ! in_array( (int) $ouvert->ID, $ids, true ) && ! in_array( $ouvert->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$posts[] = $ouvert;
		}
		$parutions = yume_parutions();
		$liste     = array();
		foreach ( $posts as $tome ) {
			if ( ! current_user_can( 'edit_post', $tome->ID ) ) {
				continue;
			}
			$valeurs = self::valeurs_tome( $tome );
			if ( ! $valeurs['oeuvre_id'] ) {
				continue;
			}
			$parution = yume_parution_tome( (int) $tome->ID );
			// Chapitres en ligne : cache yume_nb_chapitres (core), sinon le compte.
			$en_ligne = metadata_exists( 'post', $tome->ID, 'yume_nb_chapitres' ) ? (int) get_post_meta( $tome->ID, 'yume_nb_chapitres', true ) : count( yume_get_chapitres( (int) $tome->ID ) );
			$morceaux = array( yume_libelle_tome( (int) $tome->ID ), mb_strtolower( (string) ( $parutions[ $parution ] ?? $parution ) ) );
			if ( 'future' === $tome->post_status ) {
				/* translators: %s : date */
				$morceaux[] = sprintf( __( 'programmé le %s', 'yume-core' ), self::date_fr( (int) strtotime( $tome->post_date_gmt . ' UTC' ) ) );
			}
			if ( $en_ligne > 0 ) {
				/* translators: %d : nombre de chapitres en ligne */
				$morceaux[] = sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', $en_ligne, 'yume-core' ), $en_ligne );
			}
			$liste[] = array_merge(
				$valeurs,
				array(
					'id'               => (int) $tome->ID,
					'libelle'          => implode( ' · ', $morceaux ),
					'programme'        => 'future' === $tome->post_status,
					'publie'           => 'publish' === $tome->post_status,
					'parution'         => $parution,
					'parution_libelle' => (string) ( $parutions[ $parution ] ?? $parution ),
					'en_ligne'         => $en_ligne,
					'prevus'           => (int) get_post_meta( $tome->ID, 'yume_chapitres_prevus', true ),
					'rythme'           => Service::rythme_texte( (int) $tome->ID ),
					'sans_annonce'     => Service::sans_annonce_par_defaut( $tome ),
					'lien'             => 'publish' === $tome->post_status ? (string) get_permalink( $tome ) : '',
					'tri'              => (float) get_post_meta( $tome->ID, 'yume_numero', true ),
				)
			);
		}
		usort(
			$liste,
			static function ( array $a, array $b ): int {
				return array( $a['oeuvre_id'], 'ex' === $a['nature'], $a['tri'] ) <=> array( $b['oeuvre_id'], 'ex' === $b['nature'], $b['tri'] );
			}
		);
		return $liste;
	}

	/**
	 * Navigation de l'espace équipe : celle du tableau de bord (module planning), page
	 * « Publier un tome » marquée comme courante ; null si le module planning est absent.
	 */
	private static function navigation(): ?string {
		if ( ! function_exists( '\Yume\Core\Planning\navigation_equipe' ) ) {
			return null;
		}
		$retards = 0;
		if ( function_exists( '\Yume\Core\Planning\taches' ) && function_exists( 'yume_get_planning' ) ) {
			$lignes = yume_get_planning(
				array(
					'a_venir' => true,
					'public'  => false,
				)
			);
			foreach ( \Yume\Core\Planning\taches( $lignes, get_current_user_id() ) as $tache ) {
				if ( 'en_retard' === ( $tache['ligne']['etat'] ?? '' ) && empty( $tache['attente'] ) ) {
					++$retards;
				}
			}
		}
		$html = (string) \Yume\Core\Planning\navigation_equipe( 'publier', $retards );
		if ( ! str_contains( $html, 'aria-current="page"' ) ) {
			$url = esc_url( '' !== self::url_page() ? self::url_page() : (string) yume_url_page( 'publier' ) );
			$pos = strpos( $html, 'href="' . $url . '"' );
			if ( false !== $pos ) {
				$pos += strlen( 'href="' . $url . '"' );
				$html = substr( $html, 0, $pos ) . ' aria-current="page"' . substr( $html, $pos );
			}
		}
		return $html;
	}

	/**
	 * Libellé d'une date de sortie pour le bouton « Programmer » (« dim. 27 sept. 13:00 »).
	 *
	 * @param string $date Date saisie (AAAA-MM-JJTHH:MM).
	 */
	public static function libelle_date( string $date ): string {
		if ( '' === $date ) {
			return '';
		}
		try {
			$d = new \DateTimeImmutable( $date, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}
		return self::date_fr( $d->getTimestamp(), 'court' );
	}

	/**
	 * Date en français quelle que soit la langue du site (l'interface est en français).
	 *
	 * @param int    $horodatage Horodatage Unix.
	 * @param string $style      court (« dim. 27 sept. 13:00 »), jour (« 27 sept. à 13:00 »)
	 *                           ou long (« dimanche 27 septembre 2026 à 13:00 »).
	 */
	public static function date_fr( int $horodatage, string $style = 'jour' ): string {
		$jours_longs  = array( 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi' );
		$jours_courts = array( 'dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.' );
		$mois_longs   = array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
		$mois_courts  = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
		$d            = ( new \DateTimeImmutable( '@' . $horodatage ) )->setTimezone( wp_timezone() );
		$jour         = (int) $d->format( 'w' );
		$mois         = (int) $d->format( 'n' ) - 1;
		$quantieme    = '1' === $d->format( 'j' ) ? '1er' : $d->format( 'j' );
		$heure        = $d->format( 'H:i' );
		switch ( $style ) {
			case 'court':
				return $jours_courts[ $jour ] . ' ' . $quantieme . ' ' . $mois_courts[ $mois ] . ' ' . $heure;
			case 'long':
				return $jours_longs[ $jour ] . ' ' . $quantieme . ' ' . $mois_longs[ $mois ] . ' ' . $d->format( 'Y' ) . ' à ' . $heure;
			default:
				return $quantieme . ' ' . $mois_courts[ $mois ] . ' à ' . $heure;
		}
	}

	/**
	 * Nombre entier au format français (« 4 733 », espace fine insécable).
	 *
	 * @param int $nombre Nombre.
	 */
	public static function nombre( int $nombre ): string {
		return number_format( $nombre, 0, ',', "\u{202F}" );
	}

	/**
	 * Icône SVG décorative.
	 *
	 * @param string $nom    image | depot | coche | alerte.
	 * @param int    $taille Taille en px.
	 */
	private static function icone( string $nom, int $taille = 24 ): string {
		$traces = array(
			'image'  => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-5-5L5 21"></path>',
			'depot'  => '<path d="M12 3v12"></path><path d="m7 8 5-5 5 5"></path><path d="M5 21h14"></path>',
			'coche'  => '<path d="M20 6 9 17l-5-5"></path>',
			'alerte' => '<path d="M12 9v4"></path><path d="M12 17h.01"></path><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"></path>',
		);
		return '<svg class="yn-publish__icone" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $traces[ $nom ] ?? '' ) . '</svg>';
	}

	/**
	 * Rendu du bloc yume/publish-form.
	 *
	 * @param array<string,mixed> $attributs Attributs du bloc.
	 */
	public static function rendu( array $attributs = array() ): string {
		unset( $attributs );
		$enveloppe = get_block_wrapper_attributes(
			array(
				'class' => 'yn-publish',
				'id'    => 'yume-publication',
			)
		);
		if ( ! is_user_logged_in() ) {
			$connexion = function_exists( 'yume_url_page' ) ? yume_url_page( 'connexion' ) : wp_login_url();
			$retour    = '' !== self::url_page() ? self::url_page() : home_url( '/' );
			$lien      = str_contains( $connexion, 'wp-login.php' ) ? wp_login_url( $retour ) : add_query_arg( 'redirect_to', rawurlencode( $retour ), $connexion );
			return '<div ' . $enveloppe . '><div class="yn-card yn-publish__acces"><h2>' . esc_html__( 'Ajouter des chapitres à un tome', 'yume-core' ) . '</h2><p>' . esc_html__( 'Cet espace est réservé à l’équipe Yume Novel. Connectez-vous pour ajouter des chapitres à un tome.', 'yume-core' ) . '</p><p><a class="yn-btn yn-btn--primary" href="' . esc_url( $lien ) . '">' . esc_html__( 'Se connecter', 'yume-core' ) . '</a></p></div></div>';
		}
		if ( ! current_user_can( 'yume_publier' ) ) {
			$equipe = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
			return '<div ' . $enveloppe . '><div class="yn-card yn-publish__acces" role="alert"><h2>' . esc_html__( 'Accès réservé aux éditeurs', 'yume-core' ) . '</h2><p>' . esc_html__( 'Votre compte n’a pas le droit de publier des chapitres : seuls les rôles « Éditeur Yume » et « Gérant » le peuvent. Demandez à un gérant si vous devez publier.', 'yume-core' ) . '</p>'
				. ( current_user_can( 'yume_voir_equipe' ) ? '<p><a class="yn-btn" href="' . esc_url( $equipe ) . '">' . esc_html__( 'Retour à l’espace équipe', 'yume-core' ) . '</a></p>' : '' ) . '</div></div>';
		}

		$retour = get_transient( self::RETOUR . get_current_user_id() );
		$retour = is_array( $retour ) ? $retour : null;
		if ( $retour ) {
			delete_transient( self::RETOUR . get_current_user_id() );
		}
		$v = self::valeurs( $retour );

		ob_start();
		include __DIR__ . '/blocks/publish-form/gabarit.php';
		$html = (string) ob_get_clean();
		return '<div ' . $enveloppe . ' data-yn-publish data-rest="' . esc_url( rest_url( Rest::ESPACE . '/' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-taille-max="' . esc_attr( (string) Fichiers::taille_max_source() ) . '">' . $html . '</div>';
	}
}
