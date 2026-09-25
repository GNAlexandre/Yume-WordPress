<?php
/**
 * Formulaire de publication (bloc yume/publish-form, maquette TeamPublier) :
 * - rendu serveur complet, utilisable sans JavaScript (envoi vers admin-post.php, nonce,
 *   messages de retour) ; le script du bloc ajoute le glisser-déposer, l'analyse immédiate
 *   du fichier déposé, la barre de progression et l'envoi via l'API REST ;
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

	/** Étapes (boutons) du formulaire. */
	public const ETAPES = array( 'brouillon', 'apercu', 'publier', 'programmer' );

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
		foreach ( array( 'oeuvre_id', 'tome_id', 'nature', 'numero', 'titre', 'date_sortie', 'lien_pdf', 'lien_epub', 'couverture_id' ) as $cle ) {
			if ( isset( $_POST[ $cle ] ) && is_scalar( $_POST[ $cle ] ) ) {
				$champs[ $cle ] = sanitize_text_field( wp_unslash( (string) $_POST[ $cle ] ) );
			}
		}
		if ( isset( $_POST['credits'] ) && is_array( $_POST['credits'] ) ) {
			$credits = wp_unslash( $_POST['credits'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini ci-dessous.
			foreach ( array( 'traduction', 'relecture', 'edition' ) as $role ) {
				$champs['credits'][ $role ] = sanitize_text_field( is_scalar( $credits[ $role ] ?? '' ) ? (string) ( $credits[ $role ] ?? '' ) : '' );
			}
		}
		$champs['retirer_absents'] = ! empty( $_POST['retirer_absents'] );
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
			if ( 'programmer' === $etape && '' === ( $champs['date_sortie'] ?? '' ) ) {
				self::memoriser(
					array(
						'type'    => 'erreur',
						'message' => __( 'Indiquez la date et l’heure de sortie pour programmer la publication.', 'yume-core' ),
						'champs'  => $champs,
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
						'champs'  => $champs,
					)
				);
				wp_safe_redirect( self::adresse_retour( (int) ( $champs['tome_id'] ?? 0 ) ) );
				exit;
			}
			$tome_id = (int) $rapport['tome']['id'];
			$message = '';
			$type    = 'succes';
			if ( 'publier' === $etape || 'programmer' === $etape ) {
				$sortie = Service::publier( $tome_id, 'publier' === $etape ? 'maintenant' : (string) $champs['date_sortie'] );
				if ( is_wp_error( $sortie ) ) {
					$type    = 'erreur';
					$message = __( 'Le brouillon est enregistré, mais la publication a échoué :', 'yume-core' ) . ' ' . $sortie->get_error_message();
				} elseif ( 'publish' === $sortie['statut'] ) {
					/* translators: %s : titre du tome */
					$message = sprintf( __( '%s est en ligne ! Les chapitres, l’annonce et les notifications sont partis.', 'yume-core' ), $sortie['tome']['titre'] );
				} else {
					/* translators: 1: titre du tome, 2: date */
					$message = sprintf( __( '%1$s sortira le %2$s.', 'yume-core' ), $sortie['tome']['titre'], self::date_fr( ( new \DateTimeImmutable( (string) $sortie['date'], wp_timezone() ) )->getTimestamp(), 'long' ) );
				}
			} elseif ( 'apercu' === $etape && ! empty( $rapport['chapitres'][0]['apercu'] ) ) {
				wp_safe_redirect( (string) $rapport['chapitres'][0]['apercu'] );
				exit;
			} else {
				/* translators: 1: titre du tome, 2: nombre de chapitres */
				$message = sprintf( _n( 'Brouillon enregistré : %1$s, %2$d chapitre.', 'Brouillon enregistré : %1$s, %2$d chapitres.', count( $rapport['chapitres'] ), 'yume-core' ), $rapport['tome']['titre'], count( $rapport['chapitres'] ) );
			}
			self::memoriser(
				array(
					'type'    => $type,
					'message' => $message,
					'rapport' => array(
						'avertissements' => $rapport['avertissements'],
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
	 * Œuvres proposées (ID => libellé « Titre (LN) »).
	 *
	 * @return array<int,string>
	 */
	private static function oeuvres(): array {
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
			'tome'          => null,
		);
		$tome = isset( $_GET['tome'] ) ? get_post( absint( $_GET['tome'] ) ) : null;
		// phpcs:enable
		if ( $tome instanceof \WP_Post && 'yume_tome' === $tome->post_type && 'trash' !== $tome->post_status && current_user_can( 'edit_post', $tome->ID ) ) {
			$meta    = get_post_meta( $tome->ID, Service::META, true );
			$meta    = is_array( $meta ) ? $meta : array();
			$credits = get_post_meta( $tome->ID, 'yume_credits', true );
			$numero  = get_post_meta( $tome->ID, 'yume_numero', true );
			$v       = array_merge(
				$v,
				array(
					'tome_id'       => (int) $tome->ID,
					'oeuvre_id'     => (int) get_post_meta( $tome->ID, 'yume_oeuvre_id', true ),
					'nature'        => (string) get_post_meta( $tome->ID, 'yume_nature', true ),
					'numero'        => is_numeric( $numero ) ? str_replace( '.', ',', rtrim( rtrim( number_format( (float) $numero, 3, '.', '' ), '0' ), '.' ) ) : '',
					'titre'         => (string) ( $meta['titre'] ?? '' ),
					'date_sortie'   => (string) ( $meta['date_sortie'] ?? '' ),
					'lien_pdf'      => (string) get_post_meta( $tome->ID, 'yume_lien_pdf', true ),
					'lien_epub'     => (string) get_post_meta( $tome->ID, 'yume_lien_epub', true ),
					'credits'       => is_array( $credits ) ? array_merge( $v['credits'], $credits ) : $v['credits'],
					'couverture_id' => (int) get_post_thumbnail_id( $tome->ID ),
					'tome'          => $tome,
					'meta'          => $meta,
				)
			);
			if ( 'future' === $tome->post_status ) {
				$v['date_sortie'] = substr( str_replace( ' ', 'T', $tome->post_date ), 0, 16 );
			}
		}
		if ( $retour && ! empty( $retour['champs'] ) && is_array( $retour['champs'] ) ) {
			foreach ( $retour['champs'] as $cle => $valeur ) {
				if ( array_key_exists( $cle, $v ) && 'tome' !== $cle ) {
					$v[ $cle ] = $valeur;
				}
			}
		}
		if ( '' === $v['nature'] || ! isset( yume_natures_tome()[ $v['nature'] ] ) ) {
			$v['nature'] = 'tome';
		}
		return $v;
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
			return '<div ' . $enveloppe . '><div class="yn-card yn-publish__acces"><h2>' . esc_html__( 'Publier un tome', 'yume-core' ) . '</h2><p>' . esc_html__( 'Cet espace est réservé à l’équipe Yume Novel. Connectez-vous pour publier un tome.', 'yume-core' ) . '</p><p><a class="yn-btn yn-btn--primary" href="' . esc_url( $lien ) . '">' . esc_html__( 'Se connecter', 'yume-core' ) . '</a></p></div></div>';
		}
		if ( ! current_user_can( 'yume_publier' ) ) {
			$equipe = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
			return '<div ' . $enveloppe . '><div class="yn-card yn-publish__acces" role="alert"><h2>' . esc_html__( 'Accès réservé aux éditeurs', 'yume-core' ) . '</h2><p>' . esc_html__( 'Votre compte n’a pas le droit de publier un tome : seuls les rôles « Éditeur Yume » et « Gérant » le peuvent. Demandez à un gérant si vous devez publier.', 'yume-core' ) . '</p>'
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
