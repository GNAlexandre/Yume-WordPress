<?php
/**
 * Rendu du bloc yume/reader-tools : barre collante du lecteur (contexte, sommaire, marque-page,
 * thème, paramètres), barre de progression, bandeau de reprise et panneau Paramètres
 * (<dialog> modal : panneau latéral sur bureau, feuille en bas sur mobile).
 *
 * Sans JavaScript, seuls le contexte et le lien Sommaire s'affichent : le chapitre reste
 * entièrement lisible. Les couleurs viennent du thème (§15).
 *
 * @package Yume\Core
 *
 * @var array    $attributes Attributs du bloc.
 * @var WP_Block $block      Instance du bloc.
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

$yume_chapitre = chapitre_courant( $block ?? null );
// Page « Illustrations » d'un tome : même barre, sans chapitre (ni suivi, ni marque-page).
$yume_illus = $yume_chapitre ? 0 : tome_illustrations_courant();
if ( ! $yume_chapitre && ! $yume_illus ) {
	if ( apercu_editeur() ) {
		printf(
			'<div %1$s><p class="yn-muted yn-reader-tools__apercu">%2$s</p></div>',
			get_block_wrapper_attributes( array( 'class' => 'yn-reader-tools yn-reader-tools--apercu' ) ), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Barre de lecture : progression, sommaire, marque-page, thème et paramètres (visible sur les chapitres).', 'yume-core' )
		);
	}
	return;
}

$yume_config   = $yume_illus ? configuration_illustrations( $yume_illus ) : configuration( $yume_chapitre );
$yume_oeuvre   = (int) $yume_config['oeuvre'];
$yume_tome     = (int) $yume_config['tome'];
$yume_url_tome = $yume_tome && 'publish' === get_post_status( $yume_tome ) ? (string) get_permalink( $yume_tome ) : '';
$yume_url_oeu  = $yume_oeuvre && 'publish' === get_post_status( $yume_oeuvre ) ? (string) get_permalink( $yume_oeuvre ) : '';
$yume_retour   = '' !== $yume_url_tome ? $yume_url_tome : $yume_url_oeu;
$yume_nom_oeu  = $yume_oeuvre ? wp_strip_all_tags( get_the_title( $yume_oeuvre ) ) : '';
$yume_lib_tome = $yume_tome && function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $yume_tome ) : '';
$yume_lib_ct   = $yume_tome && function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $yume_tome, true ) : '';
if ( $yume_illus ) {
	$yume_lib_chap = __( 'Illustrations', 'yume-core' );
	$yume_minutes  = 0;
} else {
	$yume_lib_chap = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $yume_chapitre ) : wp_strip_all_tags( get_the_title( $yume_chapitre ) );
	$yume_minutes  = (int) get_post_meta( $yume_chapitre, 'yume_temps_lecture', true );
}

// Position du chapitre parmi les chapitres publiés du tome.
$yume_rang  = 0;
$yume_total = 0;
if ( $yume_chapitre && $yume_tome && function_exists( 'yume_get_chapitres' ) ) {
	$yume_ids   = array_map( 'intval', wp_list_pluck( yume_get_chapitres( $yume_tome ), 'ID' ) );
	$yume_total = count( $yume_ids );
	$yume_pos   = array_search( $yume_chapitre, $yume_ids, true );
	$yume_rang  = false === $yume_pos ? 0 : $yume_pos + 1;
}

// Détails : « Chapitre 1 · 1 sur 20 · ~18 min » (rang et durée masqués sur mobile).
$yume_details = '<span>' . esc_html( $yume_lib_chap ) . '</span>';
if ( $yume_rang && $yume_total ) {
	/* translators: 1 : rang du chapitre, 2 : nombre de chapitres du tome. */
	$yume_details .= '<span class="yn-reader-tools__rang"> · ' . esc_html( sprintf( __( '%1$d sur %2$d', 'yume-core' ), $yume_rang, $yume_total ) ) . '</span>';
}
if ( $yume_minutes > 0 ) {
	/* translators: %d : durée de lecture en minutes. */
	$yume_details .= '<span class="yn-reader-tools__duree"> · ' . esc_html( sprintf( __( '~%d min', 'yume-core' ), $yume_minutes ) ) . '</span>';
}
if ( $yume_illus && function_exists( 'yume_illustrations_tome' ) ) {
	$yume_nb_illus = count( yume_illustrations_tome( $yume_illus ) );
	/* translators: %d : nombre d'illustrations du tome. */
	$yume_details .= '<span class="yn-reader-tools__rang"> · ' . esc_html( sprintf( _n( '%d planche', '%d planches', $yume_nb_illus, 'yume-core' ), $yume_nb_illus ) ) . '</span>';
}

// Illustration de l'œuvre en arrière-plan (réglage « Opacité du fond »).
$yume_banniere = $yume_oeuvre ? (int) get_post_meta( $yume_oeuvre, 'yume_banniere_id', true ) : 0;
$yume_fond     = $yume_banniere ? wp_get_attachment_image_url( $yume_banniere, 'full' ) : '';

$yume_reglages = null !== $yume_config['reglages'] ? $yume_config['reglages'] : defauts_reglages();
$yume_bornes   = bornes_reglages();
$yume_polices  = polices();
$yume_themes   = libelles_themes();

$yume_icone     = static function ( string $chemin, string $classe = '' ): string {
	return '<svg class="yn-reader-tools__svg' . ( '' !== $classe ? ' ' . esc_attr( $classe ) : '' ) . '" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $chemin . '</svg>';
};
$yume_engrenage = '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"></path>';

// Compte ou connexion dans la barre (maquette Lecteur : une seule barre ; l'en-tête du
// gabarit est alors masqué par la feuille de style du bloc).
if ( is_user_logged_in() ) {
	$yume_membre     = wp_get_current_user();
	$yume_url_compte = function_exists( '\Yume\Core\Social\url_compte' ) ? \Yume\Core\Social\url_compte() : admin_url( 'profile.php' );
	$yume_nom_membre = (string) $yume_membre->display_name;
	$yume_initiale   = '' !== $yume_nom_membre ? mb_strtoupper( mb_substr( $yume_nom_membre, 0, 1 ) ) : '?';
	/* translators: %s : pseudo du membre. */
	$yume_lien_compte = '<a class="yn-reader-tools__compte yn-reader-tools__compte--membre" href="' . esc_url( $yume_url_compte ) . '" aria-label="' . esc_attr( sprintf( __( 'Mon compte (%s)', 'yume-core' ), $yume_nom_membre ) ) . '" title="' . esc_attr__( 'Mon compte', 'yume-core' ) . '"><span aria-hidden="true">' . esc_html( $yume_initiale ) . '</span></a>';
} else {
	$yume_ici         = $yume_illus ? (string) $yume_config['url'] : (string) get_permalink( $yume_chapitre );
	$yume_url_cnx     = function_exists( '\Yume\Core\Social\url_connexion' ) ? \Yume\Core\Social\url_connexion( $yume_ici ) : wp_login_url( $yume_ici );
	$yume_lien_compte = '<a class="yn-btn yn-btn--primary yn-btn--sm yn-reader-tools__compte" href="' . esc_url( $yume_url_cnx ) . '">' . esc_html__( 'Connexion', 'yume-core' ) . '</a>';
}

$yume_attributs = get_block_wrapper_attributes(
	array(
		'class'           => 'yn-reader-tools',
		'data-yn-lecteur' => wp_json_encode( $yume_config ),
	)
);
?>
<div <?php echo $yume_attributs; // phpcs:ignore WordPress.Security.EscapeOutput -- attributs échappés par WordPress. ?>>
	<?php if ( $yume_fond ) : ?>
		<div class="yn-reader-tools__fond" aria-hidden="true" style="<?php echo esc_attr( '--yn-image-fond:url("' . esc_url_raw( $yume_fond ) . '")' ); ?>"></div>
	<?php endif; ?>
	<div class="yn-reader-tools__collant">
		<div class="yn-reader-tools__barre">
			<div class="yn-reader-tools__contexte">
				<?php if ( '' !== $yume_retour ) : ?>
					<a class="yn-reader-tools__retour" href="<?php echo esc_url( $yume_retour ); ?>">
						<?php echo $yume_icone( '<path d="m15 18-6-6 6-6"></path>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="yn-reader-tools__oeuvre"><?php echo esc_html( $yume_nom_oeu ); ?></span>
						<?php if ( '' !== $yume_lib_tome ) : ?>
							<span class="yn-reader-tools__tome"><span aria-hidden="true"> · </span><span class="yn-reader-tools__long"><?php echo esc_html( $yume_lib_tome ); ?></span><span class="yn-reader-tools__court" aria-hidden="true"><?php echo esc_html( $yume_lib_ct ); ?></span></span>
						<?php endif; ?>
						<span class="yn-visually-hidden"><?php esc_html_e( '(retour au tome)', 'yume-core' ); ?></span>
					</a>
				<?php endif; ?>
				<p class="yn-reader-tools__position">
					<?php echo $yume_details; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ci-dessus. ?><span class="yn-reader-tools__js" data-yn-pourcentage-texte></span>
				</p>
			</div>
			<div class="yn-reader-tools__actions">
				<?php if ( '' !== $yume_url_tome ) : ?>
					<a class="yn-btn yn-btn--sm yn-reader-tools__sommaire" href="<?php echo esc_url( $yume_url_tome ); ?>">
						<?php echo $yume_icone( '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="yn-reader-tools__texte"><?php esc_html_e( 'Sommaire', 'yume-core' ); ?></span>
						<span class="yn-visually-hidden"><?php echo esc_html( $yume_lib_tome ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( ! $yume_illus ) : ?>
					<button type="button" class="yn-reader-tools__icone yn-reader-tools__js" data-yn-action="marque-page" aria-label="<?php esc_attr_e( 'Marque-page : enregistrer ma position', 'yume-core' ); ?>" title="<?php esc_attr_e( 'Marque-page : enregistrer ma position', 'yume-core' ); ?>">
						<?php echo $yume_icone( '<path d="M6 3h12v18l-6-4-6 4z"></path>', 'yn-reader-tools__marque' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</button>
				<?php endif; ?>
				<button type="button" class="yn-reader-tools__icone yn-reader-tools__js" data-yn-action="theme" aria-label="<?php esc_attr_e( 'Changer le thème de lecture (Nuit, Papier, Sépia)', 'yume-core' ); ?>" title="<?php esc_attr_e( 'Changer le thème de lecture', 'yume-core' ); ?>">
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput -- icônes constantes.
					echo $yume_icone( '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"></path>', 'yn-reader-tools__theme yn-reader-tools__theme--nuit' );
					echo $yume_icone( '<circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>', 'yn-reader-tools__theme yn-reader-tools__theme--papier' );
					echo $yume_icone( '<path d="M4 19.5V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2.5Z"></path><path d="M8 7h7M8 11h5"></path>', 'yn-reader-tools__theme yn-reader-tools__theme--sepia' );
					// phpcs:enable
					?>
				</button>
				<button type="button" class="yn-reader-tools__icone yn-reader-tools__icone--principal yn-reader-tools__js" data-yn-action="parametres" aria-haspopup="dialog" aria-expanded="false" aria-controls="yn-parametres-lecture" aria-keyshortcuts="S" aria-label="<?php esc_attr_e( 'Paramètres de lecture', 'yume-core' ); ?>" title="<?php esc_attr_e( 'Paramètres de lecture (touche S)', 'yume-core' ); ?>">
					<?php echo $yume_icone( $yume_engrenage ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</button>
				<?php echo $yume_lien_compte; // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ci-dessus. ?>
			</div>
		</div>
		<div class="yn-reader-tools__progression yn-bar yn-reader-tools__js" role="progressbar" aria-label="<?php echo esc_attr( $yume_illus ? __( 'Progression dans les illustrations', 'yume-core' ) : __( 'Progression dans le chapitre', 'yume-core' ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="<?php echo esc_attr( $yume_illus ? __( '0 % des illustrations', 'yume-core' ) : __( '0 % du chapitre', 'yume-core' ) ); ?>" data-yn-portee="<?php echo esc_attr( $yume_illus ? __( 'des illustrations', 'yume-core' ) : __( 'du chapitre', 'yume-core' ) ); ?>" data-yn-progression><span style="--v:0%"></span></div>
		<div class="yn-reader-tools__reprise" data-yn-reprise hidden>
			<p class="yn-reader-tools__reprise-texte" data-yn-reprise-texte></p>
			<button type="button" class="yn-btn yn-btn--primary yn-btn--sm" data-yn-action="reprendre"></button>
			<button type="button" class="yn-reader-tools__icone yn-reader-tools__icone--discret" data-yn-action="ignorer-reprise" aria-label="<?php esc_attr_e( 'Masquer : lire depuis le début', 'yume-core' ); ?>">
				<?php echo $yume_icone( '<path d="M18 6 6 18M6 6l12 12"></path>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</button>
		</div>
	</div>
	<p class="yn-visually-hidden" role="status" aria-live="polite" aria-atomic="true" data-yn-annonce></p>
	<p class="yn-reader-tools__toast" aria-hidden="true" data-yn-toast hidden></p>

	<dialog class="yn-reader-panel" id="yn-parametres-lecture" aria-labelledby="yn-parametres-titre" aria-describedby="yn-parametres-note" aria-modal="true">
		<form class="yn-reader-panel__formulaire" method="dialog" data-yn-formulaire>
			<div class="yn-reader-panel__entete">
				<div>
					<h2 class="yn-reader-panel__titre" id="yn-parametres-titre"><?php esc_html_e( 'Paramètres', 'yume-core' ); ?></h2>
					<p class="yn-label"><?php esc_html_e( 'Lecture personnalisée', 'yume-core' ); ?></p>
				</div>
				<button type="button" class="yn-reader-tools__icone yn-reader-tools__icone--discret" data-yn-action="fermer" aria-label="<?php esc_attr_e( 'Fermer sans enregistrer', 'yume-core' ); ?>">
					<?php echo $yume_icone( '<path d="M18 6 6 18M6 6l12 12"></path>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</button>
			</div>

			<div class="yn-reader-panel__groupe">
				<div class="yn-reader-panel__ligne">
					<label for="yn-reglage-size"><?php esc_html_e( 'Taille', 'yume-core' ); ?></label>
					<output class="yn-muted" for="yn-reglage-size" data-yn-sortie="size"></output>
				</div>
				<div class="yn-reader-panel__curseur">
					<button type="button" class="yn-reader-panel__pas" data-yn-pas="size" data-yn-sens="-1" aria-label="<?php esc_attr_e( 'Réduire la taille du texte', 'yume-core' ); ?>">−</button>
					<input type="range" id="yn-reglage-size" name="size" min="<?php echo esc_attr( (string) $yume_bornes['size'][0] ); ?>" max="<?php echo esc_attr( (string) $yume_bornes['size'][1] ); ?>" step="1" value="<?php echo esc_attr( (string) $yume_reglages['size'] ); ?>">
					<button type="button" class="yn-reader-panel__pas" data-yn-pas="size" data-yn-sens="1" aria-label="<?php esc_attr_e( 'Agrandir la taille du texte', 'yume-core' ); ?>">+</button>
				</div>
			</div>

			<div class="yn-reader-panel__groupe">
				<div class="yn-reader-panel__ligne">
					<label for="yn-reglage-lh"><?php esc_html_e( 'Interligne', 'yume-core' ); ?></label>
					<output class="yn-muted" for="yn-reglage-lh" data-yn-sortie="lh"></output>
				</div>
				<input type="range" id="yn-reglage-lh" name="lh" min="<?php echo esc_attr( (string) $yume_bornes['lh'][0] ); ?>" max="<?php echo esc_attr( (string) $yume_bornes['lh'][1] ); ?>" step="0.05" value="<?php echo esc_attr( (string) $yume_reglages['lh'] ); ?>" aria-describedby="yn-reglage-lh-bornes">
				<p class="yn-reader-panel__bornes yn-label" id="yn-reglage-lh-bornes"><span><?php esc_html_e( 'Compact', 'yume-core' ); ?></span><span><?php esc_html_e( 'Large', 'yume-core' ); ?></span></p>
			</div>

			<div class="yn-reader-panel__groupe">
				<div class="yn-reader-panel__ligne">
					<label for="yn-reglage-bgalpha"><?php esc_html_e( 'Opacité du fond', 'yume-core' ); ?></label>
					<output class="yn-muted" for="yn-reglage-bgalpha" data-yn-sortie="bgAlpha"></output>
				</div>
				<input type="range" id="yn-reglage-bgalpha" name="bgAlpha" min="<?php echo esc_attr( (string) ( $yume_bornes['bgAlpha'][0] * 100 ) ); ?>" max="<?php echo esc_attr( (string) ( $yume_bornes['bgAlpha'][1] * 100 ) ); ?>" step="1" value="<?php echo esc_attr( (string) round( $yume_reglages['bgAlpha'] * 100 ) ); ?>" aria-describedby="yn-reglage-bgalpha-bornes">
				<p class="yn-reader-panel__bornes yn-label" id="yn-reglage-bgalpha-bornes"><span><?php esc_html_e( 'Transparent', 'yume-core' ); ?></span><span><?php esc_html_e( 'Opaque', 'yume-core' ); ?></span></p>
			</div>

			<fieldset class="yn-reader-panel__groupe">
				<legend><?php esc_html_e( 'Thème', 'yume-core' ); ?></legend>
				<div class="yn-reader-panel__choix yn-reader-panel__choix--themes">
					<?php foreach ( $yume_themes as $yume_slug => $yume_libelle ) : ?>
						<label class="yn-reader-panel__option yn-reader-panel__option--<?php echo esc_attr( $yume_slug ); ?>">
							<input type="radio" name="theme" value="<?php echo esc_attr( $yume_slug ); ?>" <?php checked( $yume_reglages['theme'], $yume_slug ); ?>>
							<span><?php echo esc_html( $yume_libelle ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<fieldset class="yn-reader-panel__groupe">
				<legend><?php esc_html_e( 'Police d’écriture', 'yume-core' ); ?></legend>
				<div class="yn-reader-panel__choix yn-reader-panel__choix--polices">
					<?php foreach ( $yume_polices as $yume_slug => $yume_police ) : ?>
						<label class="yn-reader-panel__option" style="<?php echo esc_attr( 'font-family:' . $yume_police['pile'] ); ?>">
							<input type="radio" name="font" value="<?php echo esc_attr( $yume_slug ); ?>" <?php checked( $yume_reglages['font'], $yume_slug ); ?>>
							<span><?php echo esc_html( $yume_police['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="yn-reader-panel__groupe">
				<div class="yn-reader-panel__ligne">
					<label for="yn-reglage-width"><?php esc_html_e( 'Largeur de colonne', 'yume-core' ); ?></label>
					<output class="yn-muted" for="yn-reglage-width" data-yn-sortie="width"></output>
				</div>
				<input type="range" id="yn-reglage-width" name="width" min="<?php echo esc_attr( (string) $yume_bornes['width'][0] ); ?>" max="<?php echo esc_attr( (string) $yume_bornes['width'][1] ); ?>" step="1" value="<?php echo esc_attr( (string) $yume_reglages['width'] ); ?>" aria-describedby="yn-reglage-width-bornes">
				<p class="yn-reader-panel__bornes yn-label" id="yn-reglage-width-bornes"><span><?php esc_html_e( 'Étroite', 'yume-core' ); ?></span><span><?php esc_html_e( 'Large', 'yume-core' ); ?></span></p>
			</div>

			<div class="yn-reader-panel__pied">
				<button type="submit" class="yn-btn yn-btn--primary" value="valider" data-yn-action="valider"><?php esc_html_e( 'Valider', 'yume-core' ); ?></button>
				<button type="button" class="yn-reader-panel__reinitialiser" data-yn-action="reinitialiser">
					<span aria-hidden="true">↻</span> <?php esc_html_e( 'Réinitialiser par défaut', 'yume-core' ); ?>
				</button>
			</div>
			<p class="yn-reader-panel__note yn-muted" id="yn-parametres-note">
				<?php
				if ( is_user_logged_in() ) {
					esc_html_e( 'Aperçu en direct. « Valider » enregistre sur votre compte, Échap annule.', 'yume-core' );
				} else {
					esc_html_e( 'Aperçu en direct. « Valider » enregistre sur cet appareil (connectez-vous pour les retrouver partout), Échap annule.', 'yume-core' );
				}
				?>
				<span class="yn-reader-panel__raccourcis">
					<?php
					printf(
						/* translators: 1 : touches flèches, 2 : touche S. */
						esc_html__( 'Raccourcis : %1$s chapitres · %2$s paramètres.', 'yume-core' ),
						'<kbd>←</kbd> <kbd>→</kbd>',
						'<kbd>S</kbd>'
					);
					?>
				</span>
			</p>
		</form>
	</dialog>
</div>
