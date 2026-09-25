<?php
/**
 * Page d'administration Yume → Migrer (capacité manage_options) : état de la migration,
 * simulation (rapport lisible, statuts à valider, redirections, téléchargements CSV / JSON /
 * Markdown), exécution par lots avec confirmation tapée « MIGRER » et barre de progression,
 * reprise après interruption, annulation confirmée.
 *
 * Le JavaScript (assets/migrer.js) enchaîne les lots par l'API REST ; sans JavaScript, les
 * formulaires passent par admin-post.php et avancent d'un lot par envoi.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Écran et actions d'administration de la migration.
 */
final class Migration_Admin {

	/** Slug de la page. */
	public const PAGE = 'yume-migrer';

	/** Capacité requise. */
	public const CAPACITE = 'manage_options';

	/** Mot de confirmation de l'exécution. */
	public const CONFIRMATION = 'MIGRER';

	/** Budget d'un lot sans JavaScript (secondes). */
	public const BUDGET_FORMULAIRE = 20.0;

	/**
	 * Sous-menu Yume → Migrer.
	 */
	public static function menu(): void {
		$hook = add_submenu_page(
			'yume',
			__( 'Migrer l’ancien site', 'yume-core' ),
			__( 'Migrer', 'yume-core' ),
			self::CAPACITE,
			self::PAGE,
			array( self::class, 'afficher' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'charger' ) );
		}
	}

	/**
	 * Chargement de la page : feuilles de style et script.
	 */
	public static function charger(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'ressources' ) );
	}

	/**
	 * Ressources de la page.
	 */
	public static function ressources(): void {
		$base    = plugin_dir_url( __FILE__ ) . 'assets/';
		$dossier = __DIR__ . '/assets/';
		$version = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '1';
		$date    = static fn( string $f ): string => $version . ( is_file( $dossier . $f ) ? '-' . filemtime( $dossier . $f ) : '' );
		wp_enqueue_style( 'yume-migrer', $base . 'migrer.css', array(), $date( 'migrer.css' ) );
		wp_enqueue_script( 'yume-migrer', $base . 'migrer.js', array( 'wp-a11y' ), $date( 'migrer.js' ), true );
	}

	/**
	 * URL de la page (avec paramètres éventuels).
	 *
	 * @param array $args Paramètres.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Date GMT → date locale lisible.
	 *
	 * @param string $gmt Date GMT « Y-m-d H:i:s ».
	 */
	private static function date( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}
		return wp_date( __( 'j F Y à H:i', 'yume-core' ), (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Nom d'un utilisateur.
	 *
	 * @param int $id Utilisateur.
	 */
	private static function nom( int $id ): string {
		$u = $id ? get_userdata( $id ) : false;
		return $u ? $u->display_name : __( 'WP-CLI ou système', 'yume-core' );
	}

	/**
	 * Redirige vers la page avec un message.
	 *
	 * @param string $message Code du message.
	 * @param string $texte   Texte libre (erreur).
	 */
	private static function retour( string $message, string $texte = '' ): void {
		$args = array( 'yume_message' => $message );
		if ( '' !== $texte ) {
			set_transient( 'yume_migration_notice_' . get_current_user_id(), $texte, 120 );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Vérifie le droit de migrer (le jeton est vérifié par chaque action).
	 */
	private static function verifier_droits(): void {
		if ( ! current_user_can( self::CAPACITE ) ) {
			wp_die( esc_html__( 'Vous n’avez pas le droit de migrer le site.', 'yume-core' ), '', array( 'response' => 403 ) );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Actions (admin-post.php)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Simuler : calcule et enregistre le plan.
	 */
	public static function action_simuler(): void {
		self::verifier_droits();
		check_admin_referer( 'yume_migration_simuler' );
		try {
			Migration_Runner::simuler();
		} catch ( \RuntimeException $e ) {
			self::retour( 'erreur', $e->getMessage() );
		}
		self::retour( 'simule' );
	}

	/**
	 * Enregistre les statuts d'œuvres choisis par l'équipe.
	 */
	public static function action_choix(): void {
		self::verifier_droits();
		check_admin_referer( 'yume_migration_choix' );
		$statuts = function_exists( 'yume_statuts' ) ? array_keys( yume_statuts() ) : array( 'en-cours', 'terminee', 'en-pause', 'licenciee', 'abandonnee' );
		$plan    = Migration_State::plan();
		$choix   = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- assaini élément par élément ci-dessous.
		$saisie = isset( $_POST['statut'] ) && is_array( $_POST['statut'] ) ? wp_unslash( $_POST['statut'] ) : array();
		foreach ( (array) ( $plan['oeuvres'] ?? array() ) as $oeuvre ) {
			$valeur = isset( $saisie[ $oeuvre['cle'] ] ) ? sanitize_key( (string) $saisie[ $oeuvre['cle'] ] ) : '';
			if ( in_array( $valeur, $statuts, true ) && ( $oeuvre['termes']['yume_statut'][0] ?? '' ) !== $valeur ) {
				$choix[ $oeuvre['cle'] ] = $valeur;
			}
		}
		Migration_State::enregistrer_choix( $choix );
		self::retour( 'choix' );
	}

	/**
	 * Exécuter sans JavaScript : démarre (confirmation « MIGRER ») ou reprend, un lot par envoi.
	 */
	public static function action_executer(): void {
		self::verifier_droits();
		check_admin_referer( 'yume_migration_executer' );
		$etat = Migration_State::etat();
		try {
			if ( in_array( $etat['statut'], array( 'non_migre', 'annule' ), true ) ) {
				$confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
				if ( self::CONFIRMATION !== $confirmation ) {
					self::retour( 'confirmation' );
				}
				Migration_Runner::demarrer_execution();
			}
			Migration_Runner::lot(
				array(
					'budget'  => self::BUDGET_FORMULAIRE,
					'ignorer' => ! empty( $_POST['ignorer'] ),
				)
			);
		} catch ( \RuntimeException $e ) {
			self::retour( 'erreur', $e->getMessage() );
		}
		self::retour( 'lot' );
	}

	/**
	 * Annuler sans JavaScript : démarre (case cochée) ou reprend, un lot par envoi.
	 */
	public static function action_annuler(): void {
		self::verifier_droits();
		check_admin_referer( 'yume_migration_annuler' );
		$etat = Migration_State::etat();
		try {
			if ( 'annulation' !== $etat['statut'] ) {
				if ( empty( $_POST['confirmation'] ) ) {
					self::retour( 'confirmation_annulation' );
				}
				Migration_Runner::demarrer_annulation();
			}
			Migration_Runner::lot(
				array(
					'budget'  => self::BUDGET_FORMULAIRE,
					'ignorer' => ! empty( $_POST['ignorer'] ),
				)
			);
		} catch ( \RuntimeException $e ) {
			self::retour( 'erreur', $e->getMessage() );
		}
		self::retour( 'lot' );
	}

	/**
	 * Téléchargements : plan.json, rapport.md, redirections du plan ou redirections actives (CSV).
	 */
	public static function action_telecharger(): void {
		self::verifier_droits();
		check_admin_referer( 'yume_migration_telecharger' );
		$fichier = isset( $_GET['fichier'] ) ? sanitize_key( wp_unslash( $_GET['fichier'] ) ) : '';
		$plan    = Migration_State::plan();
		$date    = gmdate( 'Y-m-d' );
		switch ( $fichier ) {
			case 'plan':
				$nom     = 'plan-migration-' . $date . '.json';
				$type    = 'application/json';
				$contenu = null !== $plan ? (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
				break;
			case 'rapport':
				$nom     = 'rapport-migration-' . $date . '.md';
				$type    = 'text/markdown';
				$contenu = null !== $plan ? Plan_Report::markdown( $plan ) : '';
				break;
			case 'redirections':
				$nom     = 'redirections-plan-' . $date . '.csv';
				$type    = 'text/csv';
				$contenu = null !== $plan ? Plan_Report::csv_redirections( $plan ) : '';
				break;
			case 'redirections_actives':
				$nom     = 'redirections-' . $date . '.csv';
				$type    = 'text/csv';
				$contenu = Redirections::csv( Redirections::table() );
				break;
			default:
				wp_die( esc_html__( 'Fichier inconnu.', 'yume-core' ), '', array( 'response' => 404 ) );
		}
		if ( '' === $contenu ) {
			wp_die( esc_html__( 'Aucun plan : lancez d’abord une simulation.', 'yume-core' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $nom . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $contenu; // phpcs:ignore WordPress.Security.EscapeOutput -- fichier téléchargé (JSON, Markdown ou CSV), pas du HTML.
		exit;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Affichage
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Message après une action.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage.
		$code  = isset( $_GET['yume_message'] ) ? sanitize_key( wp_unslash( $_GET['yume_message'] ) ) : '';
		$texte = (string) get_transient( 'yume_migration_notice_' . get_current_user_id() );
		delete_transient( 'yume_migration_notice_' . get_current_user_id() );
		$messages = array(
			'simule'                  => array( 'success', __( 'Simulation terminée : relisez le rapport ci-dessous. Rien n’a été modifié sur le site.', 'yume-core' ) ),
			'choix'                   => array( 'success', __( 'Statuts enregistrés : ils seront appliqués à l’exécution.', 'yume-core' ) ),
			'confirmation'            => array( 'error', __( 'Tapez MIGRER (en majuscules) pour confirmer l’exécution.', 'yume-core' ) ),
			'confirmation_annulation' => array( 'error', __( 'Cochez la case de confirmation pour annuler la migration.', 'yume-core' ) ),
			'lot'                     => array( 'info', __( 'Lot traité. Si l’opération n’est pas terminée, cliquez sur « Reprendre ».', 'yume-core' ) ),
			'erreur'                  => array( 'error', '' !== $texte ? $texte : __( 'L’opération a échoué.', 'yume-core' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		list( $type, $message ) = $messages[ $code ];
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), nl2br( esc_html( $message ) ) );
	}

	/**
	 * Page Yume → Migrer.
	 */
	public static function afficher(): void {
		if ( ! current_user_can( self::CAPACITE ) ) {
			wp_die( esc_html__( 'Vous n’avez pas le droit de migrer le site.', 'yume-core' ), '', array( 'response' => 403 ) );
		}
		$etat   = Migration_State::etat();
		$meta   = Migration_State::meta_plan();
		$plan   = Migration_State::plan();
		$resume = Migration_Runner::resume( $etat );
		?>
		<div class="wrap yume-migrer" data-yume-migrer
			data-api="<?php echo esc_url( rest_url( 'yume/v1/migration/' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-page="<?php echo esc_url( self::url() ); ?>"
			data-statut="<?php echo esc_attr( $etat['statut'] ); ?>"
			data-operation="<?php echo esc_attr( $etat['operation'] ); ?>">
			<h1><?php esc_html_e( 'Migrer l’ancien site', 'yume-core' ); ?></h1>
			<p class="yume-migrer__intro">
				<?php esc_html_e( 'La migration transforme les pages de l’ancien site (fiches, arcs, chapitres) en œuvres, tomes et chapitres Yume, reclasse les articles, crée les pages Yume et installe les redirections 301. Elle se fait sur place : les images gardent leur identifiant, les anciennes pages passent en brouillon (rien n’est supprimé) et tout peut être annulé.', 'yume-core' ); ?>
			</p>
			<?php self::notice(); ?>
			<?php self::carte_etat( $etat ); ?>
			<?php self::carte_simulation( $etat, $meta, $plan ); ?>
			<?php self::carte_execution( $etat, $meta, $plan, $resume ); ?>
			<?php self::carte_annulation( $etat, $resume ); ?>
			<?php self::carte_redirections( $etat ); ?>
		</div>
		<?php
	}

	/**
	 * Pastille de statut.
	 *
	 * @param string $statut Statut.
	 */
	private static function pastille( string $statut ): string {
		$classes = array(
			'non_migre'  => 'neutre',
			'en_cours'   => 'attention',
			'migre'      => 'succes',
			'annulation' => 'attention',
			'annule'     => 'neutre',
		);
		return sprintf(
			'<span class="yume-migrer__pastille yume-migrer__pastille--%1$s">%2$s</span>',
			esc_attr( $classes[ $statut ] ?? 'neutre' ),
			esc_html( Migration_State::STATUTS[ $statut ] ?? $statut )
		);
	}

	/**
	 * Carte « État ».
	 *
	 * @param array $etat État.
	 */
	private static function carte_etat( array $etat ): void {
		?>
		<section class="yume-migrer__carte" aria-labelledby="yume-migrer-etat">
			<h2 id="yume-migrer-etat"><?php esc_html_e( 'État', 'yume-core' ); ?> <span data-yume-pastille><?php echo self::pastille( $etat['statut'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- échappé dans pastille(). ?></span></h2>
			<?php if ( 'migre' === $etat['statut'] ) : ?>
				<p>
					<?php
					/* translators: 1: date, 2: nom. */
					echo esc_html( sprintf( __( 'Migré le %1$s par %2$s.', 'yume-core' ), self::date( (string) $etat['migre_le'] ), self::nom( (int) $etat['migre_par'] ) ) );
					?>
				</p>
			<?php elseif ( 'annule' === $etat['statut'] ) : ?>
				<p>
					<?php
					/* translators: 1: date, 2: nom. */
					echo esc_html( sprintf( __( 'Migration annulée le %1$s par %2$s.', 'yume-core' ), self::date( (string) $etat['annule_le'] ), self::nom( (int) $etat['annule_par'] ) ) );
					?>
				</p>
				<?php self::controle( (array) ( $etat['controle'] ?? array() ) ); ?>
			<?php elseif ( 'non_migre' === $etat['statut'] ) : ?>
				<p><?php esc_html_e( 'Le site n’a pas encore été migré. Commencez par une simulation.', 'yume-core' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					/* translators: 1: date, 2: nom. */
					echo esc_html( sprintf( __( 'Opération commencée le %1$s par %2$s. Elle reprend où elle s’est arrêtée.', 'yume-core' ), self::date( (string) $etat['debut'] ), self::nom( (int) $etat['par'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php self::comptes_execution( (array) $etat['comptes'] ); ?>
		</section>
		<?php
	}

	/**
	 * Résultat du contrôle après annulation.
	 *
	 * @param array $controle Contrôle (date, differences, verifie).
	 */
	private static function controle( array $controle ): void {
		if ( empty( $controle['verifie'] ) ) {
			return;
		}
		if ( empty( $controle['differences'] ) ) {
			echo '<p class="yume-migrer__ok">' . esc_html__( 'Contrôle : les pages, articles, catégories et options touchés sont revenus à leur état d’origine.', 'yume-core' ) . '</p>';
			return;
		}
		echo '<p class="yume-migrer__alerte">' . esc_html__( 'Contrôle : ces éléments diffèrent de leur état d’origine :', 'yume-core' ) . ' ' . esc_html( implode( ', ', array_slice( (array) $controle['differences'], 0, 30 ) ) ) . '</p>';
	}

	/**
	 * Comptes de la dernière exécution ou annulation.
	 *
	 * @param array $comptes Comptes.
	 */
	private static function comptes_execution( array $comptes ): void {
		if ( ! $comptes ) {
			return;
		}
		$libelles = array(
			'oeuvres_creees'       => __( 'œuvres créées', 'yume-core' ),
			'oeuvres_mises_a_jour' => __( 'œuvres mises à jour', 'yume-core' ),
			'tomes_crees'          => __( 'tomes créés', 'yume-core' ),
			'tomes_mis_a_jour'     => __( 'tomes mis à jour', 'yume-core' ),
			'chapitres_crees'      => __( 'chapitres créés', 'yume-core' ),
			'chapitres_mis_a_jour' => __( 'chapitres mis à jour', 'yume-core' ),
			'articles_reclasses'   => __( 'articles reclassés', 'yume-core' ),
			'pages_creees'         => __( 'pages créées', 'yume-core' ),
			'pages_adoptees'       => __( 'pages existantes reprises', 'yume-core' ),
			'pages_depubliees'     => __( 'anciennes pages en brouillon', 'yume-core' ),
			'redirections'         => __( 'redirections', 'yume-core' ),
			'pages_restaurees'     => __( 'anciennes pages restaurées', 'yume-core' ),
			'supprimes'            => __( 'contenus supprimés', 'yume-core' ),
			'ignores'              => __( 'éléments ignorés', 'yume-core' ),
		);
		echo '<ul class="yume-migrer__comptes">';
		foreach ( $comptes as $cle => $n ) {
			printf( '<li><strong>%1$s</strong> %2$s</li>', esc_html( number_format_i18n( (int) $n ) ), esc_html( $libelles[ $cle ] ?? $cle ) );
		}
		echo '</ul>';
	}

	/**
	 * Bouton de téléchargement.
	 *
	 * @param string $fichier Fichier (plan, rapport, redirections, redirections_actives).
	 * @param string $libelle Libellé.
	 */
	private static function lien_telechargement( string $fichier, string $libelle ): string {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'yume_migration_telecharger',
					'fichier' => $fichier,
				),
				admin_url( 'admin-post.php' )
			),
			'yume_migration_telecharger'
		);
		return sprintf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $libelle ) );
	}

	/**
	 * Carte « Simuler » et rapport.
	 *
	 * @param array      $etat État.
	 * @param array|null $meta Métadonnées du plan enregistré.
	 * @param array|null $plan Plan enregistré.
	 */
	private static function carte_simulation( array $etat, ?array $meta, ?array $plan ): void {
		$possible = in_array( $etat['statut'], array( 'non_migre', 'annule' ), true );
		?>
		<section class="yume-migrer__carte" aria-labelledby="yume-migrer-simuler">
			<h2 id="yume-migrer-simuler"><?php esc_html_e( '1. Simuler', 'yume-core' ); ?></h2>
			<p><?php esc_html_e( 'La simulation lit l’ancien contenu du site et calcule le plan de migration sans rien modifier. Relisez le rapport, validez les statuts signalés, puis exécutez.', 'yume-core' ); ?></p>
			<?php if ( $meta ) : ?>
				<p class="yume-migrer__meta">
					<?php
					$origines = array(
						'simulation' => __( 'Simulation', 'yume-core' ),
						'execution'  => __( 'Plan exécuté', 'yume-core' ),
					);
					/* translators: 1: origine, 2: date, 3: nom. */
					echo esc_html( sprintf( __( '%1$s du %2$s par %3$s.', 'yume-core' ), $origines[ $meta['origine'] ] ?? $meta['origine'], self::date( (string) $meta['date'] ), self::nom( (int) $meta['par'] ) ) );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yume-migrer__actions">
				<input type="hidden" name="action" value="yume_migration_simuler">
				<?php wp_nonce_field( 'yume_migration_simuler' ); ?>
				<button type="submit" class="button button-primary" <?php disabled( ! $possible ); ?>><?php $meta ? esc_html_e( 'Simuler à nouveau', 'yume-core' ) : esc_html_e( 'Simuler', 'yume-core' ); ?></button>
				<?php if ( $plan ) : ?>
					<?php echo self::lien_telechargement( 'rapport', __( 'Rapport (Markdown)', 'yume-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::lien_telechargement( 'plan', __( 'Plan (JSON)', 'yume-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo self::lien_telechargement( 'redirections', __( 'Redirections (CSV)', 'yume-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</form>
			<?php if ( ! $possible ) : ?>
				<p class="description"><?php esc_html_e( 'La simulation n’est possible qu’avant la migration ou après son annulation.', 'yume-core' ); ?></p>
			<?php endif; ?>
			<?php
			if ( $plan ) {
				self::rapport( $plan, $possible );
			}
			?>
		</section>
		<?php
	}

	/**
	 * Rapport lisible d'un plan.
	 *
	 * @param array $plan       Plan.
	 * @param bool  $modifiable Vrai si les statuts peuvent encore être choisis.
	 */
	private static function rapport( array $plan, bool $modifiable ): void {
		$c      = $plan['comptes'];
		$n      = static fn( $v ) => number_format_i18n( (int) $v );
		$lignes = array(
			array( __( 'Œuvres', 'yume-core' ), $c['oeuvres']['total'], self::detail( $c['oeuvres']['par_type'] ) . ' · ' . self::detail( $c['oeuvres']['par_statut'] ) ),
			array( __( 'Tomes', 'yume-core' ), $c['tomes']['total'], self::detail( $c['tomes']['par_nature'] ) . ' · ' . self::detail( $c['tomes']['par_statut'] ) ),
			/* translators: 1: migrés, 2: planifiés, 3: mots. */
			array( __( 'Chapitres', 'yume-core' ), $c['chapitres']['total'], sprintf( __( '%1$s migrés, %2$s planifiés (brouillons), %3$s mots', 'yume-core' ), $n( $c['chapitres']['migres'] ), $n( $c['chapitres']['planifies'] ), $n( $c['chapitres']['mots'] ) ) ),
			/* translators: 1: sorties, 2: actualités, 3: ignorés. */
			array( __( 'Articles', 'yume-core' ), $c['articles']['total'], sprintf( __( '%1$s sorties, %2$s actualités, %3$s brouillons d’essai ignorés', 'yume-core' ), $n( $c['articles']['par_classement']['sortie'] ?? 0 ), $n( $c['articles']['par_classement']['actualite'] ?? 0 ), $n( $c['articles']['a_ignorer'] ) ) ),
			/* translators: 1: conservées, 2: remplacées, 3: ignorées, 4: créées. */
			array( __( 'Pages', 'yume-core' ), $c['pages']['total'], sprintf( __( '%1$s conservées, %2$s remplacées (brouillon), %3$s ignorées, %4$s pages Yume créées', 'yume-core' ), $n( $c['pages_plan']['conserver'] ?? 0 ), $n( $c['pages_plan']['remplacer'] ?? 0 ), $n( $c['pages_plan']['ignorer'] ?? 0 ), $n( $c['pages_plan']['creer'] ?? 0 ) ) ),
			/* translators: 1: PDF, 2: EPUB. */
			array( __( 'Liens de téléchargement', 'yume-core' ), (int) $c['liens']['pdf'] + (int) $c['liens']['epub'], sprintf( __( '%1$s PDF, %2$s EPUB', 'yume-core' ), $n( $c['liens']['pdf'] ), $n( $c['liens']['epub'] ) ) . ' · ' . self::detail( $c['liens']['hebergeurs'] ) ),
			array( __( 'Redirections 301', 'yume-core' ), $c['redirections'], '' ),
			/* translators: 1: référencés, 2: manquants. */
			array( __( 'Médias', 'yume-core' ), $c['medias']['exportes'], sprintf( __( '%1$s utilisés par le plan, %2$s manquants', 'yume-core' ), $n( $c['medias']['references'] ), $n( $c['medias']['manquants'] ) ) ),
		);
		?>
		<div class="yume-migrer__rapport">
			<h3><?php esc_html_e( 'Comptes', 'yume-core' ); ?></h3>
			<table class="widefat striped yume-migrer__table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Élément', 'yume-core' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Total', 'yume-core' ); ?></th><th scope="col"><?php esc_html_e( 'Détail', 'yume-core' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $lignes as $ligne ) : ?>
					<tr><th scope="row"><?php echo esc_html( $ligne[0] ); ?></th><td class="num"><?php echo esc_html( $n( $ligne[1] ) ); ?></td><td><?php echo esc_html( $ligne[2] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			self::avertissements( (array) $plan['avertissements'] );
			self::statuts( $plan, $modifiable );
			self::oeuvres( $plan );
			self::pages( $plan );
			self::redirections_plan( $plan );
			?>
		</div>
		<?php
	}

	/**
	 * « clé : valeur · … » d'un tableau de comptes.
	 *
	 * @param array $comptes Comptes.
	 */
	private static function detail( array $comptes ): string {
		$morceaux = array();
		foreach ( $comptes as $cle => $valeur ) {
			$morceaux[] = $cle . ' : ' . number_format_i18n( (int) $valeur );
		}
		return implode( ', ', $morceaux );
	}

	/**
	 * Avertissements du plan, par niveau.
	 *
	 * @param array $avertissements Avertissements.
	 */
	private static function avertissements( array $avertissements ): void {
		$niveaux = array(
			'erreur'    => __( 'Erreurs (à corriger avant d’exécuter)', 'yume-core' ),
			'attention' => __( 'Points d’attention (à valider par l’équipe)', 'yume-core' ),
			'info'      => __( 'Informations', 'yume-core' ),
		);
		echo '<h3>' . esc_html__( 'Avertissements', 'yume-core' ) . '</h3>';
		foreach ( $niveaux as $niveau => $titre ) {
			$liste = array_values( array_filter( $avertissements, static fn( $a ) => ( $a['niveau'] ?? '' ) === $niveau ) );
			if ( ! $liste ) {
				if ( 'erreur' === $niveau ) {
					echo '<p class="yume-migrer__ok">' . esc_html__( 'Aucune erreur.', 'yume-core' ) . '</p>';
				}
				continue;
			}
			printf(
				'<details class="yume-migrer__details yume-migrer__details--%1$s"%2$s><summary>%3$s (%4$s)</summary><ul>',
				esc_attr( $niveau ),
				'info' === $niveau ? '' : ' open',
				esc_html( $titre ),
				esc_html( number_format_i18n( count( $liste ) ) )
			);
			foreach ( $liste as $a ) {
				printf(
					'<li><span class="yume-migrer__categorie">%1$s</span>%2$s %3$s</li>',
					esc_html( (string) $a['categorie'] ),
					null !== $a['source_id'] ? ' <span class="yume-migrer__source">#' . esc_html( (string) $a['source_id'] ) . '</span>' : '',
					esc_html( (string) $a['message'] )
				);
			}
			echo '</ul></details>';
		}
	}

	/**
	 * Œuvres dont le statut est ambigu ou contradictoire (hub contre fiche), à valider.
	 *
	 * @param array $plan Plan.
	 * @return array<int,array<string,mixed>>
	 */
	public static function statuts_a_valider( array $plan ): array {
		$liste = array();
		foreach ( (array) ( $plan['oeuvres'] ?? array() ) as $o ) {
			$raisons = array_values(
				array_filter(
					(array) ( $o['avertissements'] ?? array() ),
					static fn( $m ) => str_contains( (string) $m, 'ambigu' ) || str_contains( (string) $m, 'indique' )
				)
			);
			if ( $raisons ) {
				$liste[] = array(
					'cle'     => $o['cle'],
					'titre'   => $o['post']['post_title'],
					'statut'  => $o['termes']['yume_statut'][0] ?? '',
					'hub'     => $o['infos']['statut_hub'] ?? '',
					'fiche'   => $o['infos']['statut_fiche'] ?? '',
					'raisons' => $raisons,
				);
			}
		}
		return $liste;
	}

	/**
	 * Tableau des statuts à valider (choix enregistrés appliqués à l'exécution).
	 *
	 * @param array $plan       Plan.
	 * @param bool  $modifiable Choix possibles.
	 */
	private static function statuts( array $plan, bool $modifiable ): void {
		$liste = self::statuts_a_valider( $plan );
		if ( ! $liste ) {
			return;
		}
		$statuts = function_exists( 'yume_statuts' ) ? yume_statuts() : array(
			'en-cours'   => 'En cours',
			'terminee'   => 'Terminée',
			'en-pause'   => 'En pause',
			'licenciee'  => 'Licenciée',
			'abandonnee' => 'Abandonnée',
		);
		$choix   = Migration_State::choix();
		?>
		<h3 id="yume-migrer-statuts"><?php esc_html_e( 'Statuts à valider', 'yume-core' ); ?></h3>
		<p><?php esc_html_e( 'Le hub et la fiche de ces œuvres donnent un statut ambigu ou contradictoire. Le statut proposé vient du hub ; choisissez le bon avant d’exécuter (modifiable aussi plus tard dans la fiche de l’œuvre).', 'yume-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yume_migration_choix">
			<?php wp_nonce_field( 'yume_migration_choix' ); ?>
			<table class="widefat striped yume-migrer__table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Œuvre', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hub', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Fiche', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Statut à la migration', 'yume-core' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $liste as $ligne ) : ?>
					<?php
					$id_champ = 'yume-statut-' . sanitize_html_class( $ligne['cle'] );
					$courant  = $choix[ $ligne['cle'] ] ?? $ligne['statut'];
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id_champ ); ?>"><?php echo esc_html( $ligne['titre'] ); ?></label>
							<p class="description"><?php echo esc_html( implode( ' ', $ligne['raisons'] ) ); ?></p></th>
						<td><?php echo esc_html( $ligne['hub'] ); ?></td>
						<td><?php echo esc_html( '' !== $ligne['fiche'] ? $ligne['fiche'] : '—' ); ?></td>
						<td>
							<select id="<?php echo esc_attr( $id_champ ); ?>" name="statut[<?php echo esc_attr( $ligne['cle'] ); ?>]" <?php disabled( ! $modifiable ); ?>>
								<?php foreach ( $statuts as $slug => $libelle ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $courant, $slug ); ?>><?php echo esc_html( $libelle . ( $slug === $ligne['statut'] ? ' ' . __( '(proposé)', 'yume-core' ) : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $modifiable ) : ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Enregistrer les statuts', 'yume-core' ); ?></button></p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Tableau des œuvres du plan.
	 *
	 * @param array $plan Plan.
	 */
	private static function oeuvres( array $plan ): void {
		$types   = function_exists( 'yume_types' ) ? yume_types() : array();
		$statuts = function_exists( 'yume_statuts' ) ? yume_statuts() : array();
		$choix   = Migration_State::choix();
		?>
		<details class="yume-migrer__details" open>
			<summary><?php echo esc_html( sprintf( /* translators: %s: nombre. */ __( 'Œuvres (%s)', 'yume-core' ), number_format_i18n( count( (array) $plan['oeuvres'] ) ) ) ); ?></summary>
			<div class="yume-migrer__defilement" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Table des œuvres du plan', 'yume-core' ); ?>">
			<table class="widefat striped yume-migrer__table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Œuvre', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Statut', 'yume-core' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Tomes', 'yume-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Nouvelle adresse', 'yume-core' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( (array) $plan['oeuvres'] as $o ) : ?>
					<?php
					$type   = $o['termes']['yume_type'][0] ?? '';
					$statut = $choix[ $o['cle'] ] ?? ( $o['termes']['yume_statut'][0] ?? '' );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $o['post']['post_title'] ); ?></th>
						<td><?php echo esc_html( $types[ $type ] ?? $type ); ?></td>
						<td><?php echo esc_html( $statuts[ $statut ] ?? $statut ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( count( (array) $o['tomes'] ) ) ); ?></td>
						<td><code><?php echo esc_html( $o['url'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</details>
		<?php
	}

	/**
	 * Pages créées, conservées et remplacées.
	 *
	 * @param array $plan Plan.
	 */
	private static function pages( array $plan ): void {
		?>
		<details class="yume-migrer__details">
			<summary><?php esc_html_e( 'Pages', 'yume-core' ); ?></summary>
			<h4><?php esc_html_e( 'Pages Yume créées', 'yume-core' ); ?></h4>
			<ul class="yume-migrer__liste">
				<?php foreach ( (array) $plan['pages']['creer'] as $p ) : ?>
					<li><strong><?php echo esc_html( $p['post_title'] ); ?></strong> <code><?php echo esc_html( $p['url'] ); ?></code> — <?php echo esc_html( str_replace( '`', '', Plan_Report::resume_contenu_page( $p ) ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<h4><?php esc_html_e( 'Pages conservées telles quelles', 'yume-core' ); ?></h4>
			<ul class="yume-migrer__liste">
				<?php foreach ( (array) $plan['pages']['conserver'] as $p ) : ?>
					<li><?php echo esc_html( $p['titre'] ); ?> <code><?php echo esc_html( $p['url'] ); ?></code><?php echo $p['remarques'] ? ' — ' . esc_html( implode( ' ', $p['remarques'] ) ) : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<h4><?php echo esc_html( sprintf( /* translators: %s: nombre. */ __( 'Pages remplacées, passées en brouillon (%s)', 'yume-core' ), number_format_i18n( count( (array) $plan['pages']['remplacer'] ) ) ) ); ?></h4>
			<p class="description"><?php esc_html_e( 'Fiches, arcs, chapitres, hubs et pages de catégorie de l’ancien site : leur adresse est redirigée vers la nouvelle.', 'yume-core' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Redirections du plan.
	 *
	 * @param array $plan Plan.
	 */
	private static function redirections_plan( array $plan ): void {
		?>
		<details class="yume-migrer__details">
			<summary><?php echo esc_html( sprintf( /* translators: %s: nombre. */ __( 'Redirections 301 prévues (%s)', 'yume-core' ), number_format_i18n( count( (array) $plan['redirections'] ) ) ) ); ?></summary>
			<div class="yume-migrer__defilement" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Table des redirections prévues', 'yume-core' ); ?>">
			<table class="widefat striped yume-migrer__table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Ancienne adresse', 'yume-core' ); ?></th><th scope="col"><?php esc_html_e( 'Nouvelle adresse', 'yume-core' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'yume-core' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) $plan['redirections'] as $r ) : ?>
					<tr><td><code><?php echo esc_html( $r['source'] ); ?></code></td><td><code><?php echo esc_html( $r['cible'] ); ?></code></td><td><?php echo esc_html( $r['type'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</details>
		<?php
	}

	/**
	 * Zone de progression (mise à jour par le script).
	 *
	 * @param array  $resume    Résumé de l'état.
	 * @param bool   $visible   Affichée d'emblée.
	 * @param string $operation executer ou annuler.
	 */
	private static function progression( array $resume, bool $visible, string $operation ): void {
		?>
		<div class="yume-migrer__progression" data-yume-progression="<?php echo esc_attr( $operation ); ?>" <?php echo $visible ? '' : 'hidden'; ?>>
			<p class="yume-migrer__etape" data-yume-etape><?php echo esc_html( $resume['etape_libelle'] ); ?></p>
			<progress max="100" value="<?php echo esc_attr( (string) $resume['pourcentage'] ); ?>" data-yume-barre aria-label="<?php esc_attr_e( 'Avancement', 'yume-core' ); ?>"><?php echo esc_html( $resume['pourcentage'] . ' %' ); ?></progress>
			<p class="yume-migrer__compteur" data-yume-compteur>
				<?php
				/* translators: 1: fait, 2: total, 3: pourcentage. */
				echo esc_html( sprintf( __( '%1$s / %2$s éléments (%3$s %%)', 'yume-core' ), number_format_i18n( $resume['fait'] ), number_format_i18n( $resume['total'] ), $resume['pourcentage'] ) );
				?>
			</p>
			<div class="notice notice-error inline" data-yume-erreur <?php echo '' !== $resume['erreur'] ? '' : 'hidden'; ?>><p><?php echo esc_html( $resume['erreur'] ); ?></p></div>
			<ol class="yume-migrer__journal" data-yume-journal aria-live="polite" aria-relevant="additions">
				<?php foreach ( array_slice( (array) $resume['messages'], -8 ) as $m ) : ?>
					<li class="yume-migrer__message--<?php echo esc_attr( (string) $m['type'] ); ?>"><?php echo esc_html( (string) $m['texte'] ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Boutons de reprise d'une opération interrompue.
	 *
	 * @param string $action   Action admin-post.
	 * @param array  $resume   Résumé.
	 * @param string $libelle  Libellé du bouton.
	 */
	private static function reprise( string $action, array $resume, string $libelle ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yume-migrer__actions" data-yume-form="<?php echo esc_attr( 'yume_migration_executer' === $action ? 'executer' : 'annuler' ); ?>" data-yume-reprise>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( $action ); ?>
			<button type="submit" class="button button-primary" name="reprendre" value="1"><?php echo esc_html( $libelle ); ?></button>
			<?php if ( '' !== $resume['erreur'] ) : ?>
				<button type="submit" class="button" name="ignorer" value="1" data-yume-ignorer><?php esc_html_e( 'Ignorer l’élément en erreur et continuer', 'yume-core' ); ?></button>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Carte « Exécuter ».
	 *
	 * @param array      $etat   État.
	 * @param array|null $meta   Métadonnées du plan.
	 * @param array|null $plan   Plan.
	 * @param array      $resume Résumé.
	 */
	private static function carte_execution( array $etat, ?array $meta, ?array $plan, array $resume ): void {
		if ( ! in_array( $etat['statut'], array( 'non_migre', 'annule', 'en_cours' ), true ) ) {
			return;
		}
		?>
		<section class="yume-migrer__carte" aria-labelledby="yume-migrer-executer">
			<h2 id="yume-migrer-executer"><?php esc_html_e( '2. Exécuter', 'yume-core' ); ?></h2>
			<?php if ( 'en_cours' === $etat['statut'] ) : ?>
				<?php self::progression( $resume, true, 'executer' ); ?>
				<?php self::reprise( 'yume_migration_executer', $resume, __( 'Reprendre la migration', 'yume-core' ) ); ?>
			<?php elseif ( null === $plan || 'simulation' !== ( $meta['origine'] ?? '' ) ) : ?>
				<p><?php esc_html_e( 'Lancez d’abord une simulation et relisez son rapport.', 'yume-core' ); ?></p>
			<?php else : ?>
				<?php $problemes = Migration_Executor::problemes( $plan ); ?>
				<?php if ( $problemes ) : ?>
					<div class="notice notice-error inline"><p><?php esc_html_e( 'Exécution impossible tant que ces problèmes ne sont pas réglés :', 'yume-core' ); ?></p>
						<ul class="yume-migrer__liste">
						<?php
						foreach ( $problemes as $p ) :
							?>
							<li><?php echo esc_html( $p ); ?></li><?php endforeach; ?></ul>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'L’exécution se fait par petits lots (quelques secondes chacun) pour respecter les limites de l’hébergeur. Gardez cette page ouverte ; si elle se ferme, revenez-y et cliquez sur « Reprendre ». Aucune notification (e-mail, Discord) n’est envoyée pendant la migration.', 'yume-core' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yume-migrer__confirmer" data-yume-form="executer">
						<input type="hidden" name="action" value="yume_migration_executer">
						<?php wp_nonce_field( 'yume_migration_executer' ); ?>
						<label for="yume-migrer-confirmation"><?php esc_html_e( 'Tapez MIGRER pour confirmer', 'yume-core' ); ?></label>
						<input type="text" id="yume-migrer-confirmation" name="confirmation" autocomplete="off" spellcheck="false" class="regular-text" aria-describedby="yume-migrer-confirmation-aide" data-yume-confirmation>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Exécuter la migration', 'yume-core' ); ?></button>
						<p class="description" id="yume-migrer-confirmation-aide"><?php esc_html_e( 'Tout reste réversible avec « Annuler la migration ».', 'yume-core' ); ?></p>
					</form>
					<?php self::progression( $resume, false, 'executer' ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Carte « Annuler la migration ».
	 *
	 * @param array $etat   État.
	 * @param array $resume Résumé.
	 */
	private static function carte_annulation( array $etat, array $resume ): void {
		if ( ! in_array( $etat['statut'], array( 'migre', 'en_cours', 'annulation' ), true ) ) {
			return;
		}
		?>
		<section class="yume-migrer__carte yume-migrer__carte--danger" aria-labelledby="yume-migrer-annuler">
			<h2 id="yume-migrer-annuler"><?php esc_html_e( '3. Annuler la migration', 'yume-core' ); ?></h2>
			<?php if ( 'annulation' === $etat['statut'] ) : ?>
				<?php self::progression( $resume, true, 'annuler' ); ?>
				<?php self::reprise( 'yume_migration_annuler', $resume, __( 'Reprendre l’annulation', 'yume-core' ) ); ?>
			<?php else : ?>
				<?php $autres = self::contenus_ajoutes_depuis(); ?>
				<p><?php esc_html_e( 'L’annulation supprime les œuvres, tomes, chapitres et pages créés par la migration, remet les anciennes pages en ligne, restaure les catégories des articles, les réglages de lecture et les options, et retire les redirections.', 'yume-core' ); ?></p>
				<?php if ( $autres ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						/* translators: %s: nombre de contenus. */
						echo esc_html( sprintf( __( '%s contenu(s) Yume ont été ajoutés depuis la migration : ils ne seront pas supprimés mais perdront leur œuvre ou leur tome.', 'yume-core' ), number_format_i18n( $autres ) ) );
						?>
					</p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yume-migrer__confirmer" data-yume-form="annuler">
					<input type="hidden" name="action" value="yume_migration_annuler">
					<?php wp_nonce_field( 'yume_migration_annuler' ); ?>
					<p><label><input type="checkbox" name="confirmation" value="1" data-yume-confirmation-annulation> <?php esc_html_e( 'Je confirme vouloir annuler la migration et revenir à l’ancien site.', 'yume-core' ); ?></label></p>
					<button type="submit" class="button yume-migrer__bouton-danger"><?php esc_html_e( 'Annuler la migration', 'yume-core' ); ?></button>
				</form>
				<?php self::progression( $resume, false, 'annuler' ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Contenus Yume (œuvres, tomes, chapitres) qui n'ont pas été créés par la migration.
	 */
	private static function contenus_ajoutes_depuis(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type IN ( 'yume_oeuvre', 'yume_tome', 'yume_chapitre' ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND m.meta_id IS NULL",
				Site_Source::META_CLE
			)
		);
	}

	/**
	 * Carte « Redirections actives ».
	 *
	 * @param array $etat État.
	 */
	private static function carte_redirections( array $etat ): void {
		$table = Redirections::table();
		if ( ! $table && 'migre' !== $etat['statut'] ) {
			return;
		}
		?>
		<section class="yume-migrer__carte" aria-labelledby="yume-migrer-redirections">
			<h2 id="yume-migrer-redirections"><?php esc_html_e( 'Redirections actives', 'yume-core' ); ?></h2>
			<p>
				<?php
				/* translators: %s: nombre. */
				echo esc_html( sprintf( __( '%s ancienne(s) adresse(s) redirigée(s) en 301 par Yume. Le fichier CSV s’importe dans l’extension Redirection si l’équipe préfère la gérer là.', 'yume-core' ), number_format_i18n( count( $table ) ) ) );
				?>
			</p>
			<p><?php echo self::lien_telechargement( 'redirections_actives', __( 'Télécharger les redirections (CSV Redirection)', 'yume-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php if ( $table ) : ?>
				<details class="yume-migrer__details">
					<summary><?php esc_html_e( 'Voir la table', 'yume-core' ); ?></summary>
					<div class="yume-migrer__defilement" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Table des redirections actives', 'yume-core' ); ?>">
					<table class="widefat striped yume-migrer__table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Ancienne adresse', 'yume-core' ); ?></th><th scope="col"><?php esc_html_e( 'Nouvelle adresse', 'yume-core' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $table as $source => $cible ) : ?>
							<tr><td><code><?php echo esc_html( (string) $source ); ?></code></td><td><a href="<?php echo esc_url( Redirections::url( (string) $cible ) ); ?>"><?php echo esc_html( (string) $cible ); ?></a></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}
}
