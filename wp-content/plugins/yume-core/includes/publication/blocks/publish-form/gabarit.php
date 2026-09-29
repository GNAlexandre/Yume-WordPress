<?php
/**
 * Gabarit du formulaire de publication (inclus par Formulaire::rendu()).
 *
 * Variables : $v (valeurs initiales), $retour (message de retour ou null).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

$yume_oeuvres   = self::oeuvres();
$yume_natures   = yume_natures_tome();
$yume_tome      = $v['tome'] instanceof \WP_Post ? $v['tome'] : null;
$yume_meta      = (array) ( $v['meta'] ?? array() );
$yume_chaps     = $yume_tome ? yume_get_chapitres( (int) $yume_tome->ID, array( 'status' => 'any' ) ) : array();
$yume_equipe    = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
$yume_planning  = function_exists( 'yume_url_page' ) ? yume_url_page( 'planning' ) : home_url( '/' );
$yume_max       = Fichiers::taille_lisible( Fichiers::taille_max_source() );
$yume_couv      = $v['couverture_id'] ? wp_get_attachment_image_url( (int) $v['couverture_id'], 'medium' ) : '';
$yume_date_lib  = self::libelle_date( (string) $v['date_sortie'] );
$yume_import    = is_array( $retour['rapport']['import'] ?? null ) ? $retour['rapport']['import'] : null;
$yume_avert     = $retour ? (array) ( $retour['rapport']['avertissements'] ?? array() ) : array();
$yume_planifies = self::tomes_planning( $yume_tome ? (int) $yume_tome->ID : 0 );
// Tome déjà paru qui a une lecture en ligne : le formulaire sert à la remplacer, en deux temps
// (version en attente vérifiée, puis remplacement).
$yume_en_ligne = $yume_tome && 'publish' === $yume_tome->post_status ? count( yume_get_chapitres( (int) $yume_tome->ID ) ) : 0;
$yume_attente  = $yume_tome ? Remplacement::etat( (int) $yume_tome->ID ) : null;
$yume_jours    = (int) round( Remplacement::duree() / DAY_IN_SECONDS );

// Libellé d'état (puce en haut à droite).
if ( $yume_tome ) {
	$yume_maj  = ! empty( $yume_meta['maj'] ) ? self::date_fr( (int) strtotime( $yume_meta['maj'] . ' UTC' ) ) : '';
	$yume_etat = 'publish' === $yume_tome->post_status
		? ( $yume_attente ? __( 'Version en attente : rien n’a changé en ligne', 'yume-core' ) : __( 'Tome publié : les modifications sont en ligne', 'yume-core' ) )
		: ( 'future' === $yume_tome->post_status
			/* translators: %s : date */
			? sprintf( __( 'Sortie programmée le %s', 'yume-core' ), self::date_fr( (int) strtotime( $yume_tome->post_date_gmt . ' UTC' ) ) )
			/* translators: %s : date */
			: ( '' !== $yume_maj ? sprintf( __( 'Brouillon enregistré le %s', 'yume-core' ), $yume_maj ) : __( 'Brouillon du planning', 'yume-core' ) ) );
} else {
	$yume_etat = __( 'Nouveau tome', 'yume-core' );
}
?>
<div class="yn-publish__cadre">
	<?php
	// Navigation de l'espace équipe : celle du tableau de bord (module planning), sinon une
	// copie des mêmes entrées, même ordre et mêmes cibles (WCAG 3.2.3).
	$yume_nav_equipe = self::navigation();
	if ( null !== $yume_nav_equipe ) :
		echo $yume_nav_equipe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML échappé par navigation_equipe().
	else :
		$yume_nav   = array(
			array( __( 'Tableau de bord', 'yume-core' ), $yume_equipe, false ),
			array( __( 'Mes tâches', 'yume-core' ), add_query_arg( 'vue', 'taches', $yume_equipe ), false ),
			array( __( 'Publier un tome', 'yume-core' ), '' !== self::url_page() ? self::url_page() : (string) get_permalink(), true ),
		);
		$yume_nav[] = array( __( 'Tous les tomes', 'yume-core' ), add_query_arg( 'vue', 'tomes', $yume_equipe ), false );
		$yume_nav[] = array( __( 'Planning complet', 'yume-core' ), $yume_planning, false );
		$yume_nav[] = array( __( 'Journal', 'yume-core' ), $yume_equipe . '#yn-team-journal', false );
		// Page « Membres et rôles » de l'espace équipe, sinon la liste des utilisateurs de l'administration.
		$yume_membres = function_exists( '\Yume\Core\Planning\url_membres' ) ? \Yume\Core\Planning\url_membres() : ( current_user_can( 'list_users' ) ? admin_url( 'users.php' ) : '' );
		if ( '' !== $yume_membres ) {
			$yume_nav[] = array( __( 'Membres et rôles', 'yume-core' ), $yume_membres, false );
		}
		if ( current_user_can( 'yume_reglages' ) ) {
			$yume_nav[] = array( __( 'Réglages (rappels, Discord)', 'yume-core' ), admin_url( 'admin.php?page=yume-reglages' ), false );
		}
		?>
		<nav class="yn-publish__nav" aria-label="<?php esc_attr_e( 'Espace équipe', 'yume-core' ); ?>">
			<a class="yn-publish__marque" href="<?php echo esc_url( $yume_equipe ); ?>"><span class="yn-publish__pastille" aria-hidden="true"></span><?php esc_html_e( 'Yume · Équipe', 'yume-core' ); ?></a>
			<ul class="yn-publish__menu">
				<?php foreach ( $yume_nav as $yume_entree ) : ?>
					<li><a href="<?php echo esc_url( $yume_entree[1] ); ?>"<?php echo $yume_entree[2] ? ' aria-current="page" class="is-actif"' : ''; ?>><?php echo esc_html( $yume_entree[0] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>
	<?php endif; ?>

	<div class="yn-publish__page">
		<div class="yn-publish__entete">
			<div>
				<span class="yn-label"><?php esc_html_e( 'Publication · étape 1 sur 2', 'yume-core' ); ?></span>
				<h2 class="yn-publish__titre"><?php esc_html_e( 'Publier un tome ou un arc', 'yume-core' ); ?></h2>
				<p class="yn-muted yn-publish__intro"><?php esc_html_e( 'Quelques champs, un dépôt de fichier : le tome, ses chapitres, l’annonce, le planning et les notifications sont créés pour vous.', 'yume-core' ); ?></p>
			</div>
			<span class="yn-chip yn-chip--info" data-yn-etat><?php echo esc_html( $yume_etat ); ?></span>
		</div>

		<div class="yn-publish__messages" data-yn-messages role="status" aria-live="polite">
			<?php if ( $retour ) : ?>
				<div class="yn-card yn-publish__message yn-publish__message--<?php echo 'erreur' === $retour['type'] ? 'erreur' : 'succes'; ?>" <?php echo 'erreur' === $retour['type'] ? 'role="alert"' : ''; ?>>
					<p><strong><?php echo esc_html( (string) $retour['message'] ); ?></strong></p>
					<?php if ( $yume_tome && 'succes' === $retour['type'] ) : ?>
						<p class="yn-publish__liens">
							<a href="<?php echo esc_url( 'publish' === $yume_tome->post_status ? get_permalink( $yume_tome ) : get_preview_post_link( $yume_tome ) ); ?>"><?php echo 'publish' === $yume_tome->post_status ? esc_html__( 'Voir le tome', 'yume-core' ) : esc_html__( 'Prévisualiser le tome', 'yume-core' ); ?></a>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $yume_tome->ID ) ); ?>"><?php esc_html_e( 'Modifier dans l’administration', 'yume-core' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $yume_en_ligne > 0 || $yume_attente ) : ?>
			<section class="yn-card yn-publish__mode-remplacement" aria-labelledby="yn-publish-remplacer-titre" data-yn-mode-remplacement>
				<h3 id="yn-publish-remplacer-titre">
					<?php
					/* translators: %d : nombre de chapitres en ligne */
					echo esc_html( sprintf( _n( 'Remplacer la lecture en ligne (%d chapitre actuel)', 'Remplacer la lecture en ligne (%d chapitres actuels)', $yume_en_ligne, 'yume-core' ), $yume_en_ligne ) );
					?>
				</h3>
				<p><strong><?php esc_html_e( 'Ce tome est déjà paru avec sa lecture en ligne. Rien ne change pour les lecteurs tant que vous n’avez pas cliqué sur « Remplacer la lecture en ligne maintenant ».', 'yume-core' ); ?></strong></p>
				<ol class="yn-publish__etapes-remplacement">
					<li><?php esc_html_e( 'Déposez le nouveau DOCX ou EPUB ci-dessous, puis cliquez sur « Vérifier (sans rien changer en ligne) » : le fichier est découpé en une version en attente, visible de l’équipe seulement ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'contrôlez le rapport et l’aperçu de chaque chapitre ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'cliquez sur « Remplacer la lecture en ligne maintenant » (ou sur « Annuler le remplacement »).', 'yume-core' ); ?></li>
				</ol>
				<ul>
					<li><?php esc_html_e( 'chaque chapitre est remplacé en place, par numéro : mêmes adresses, commentaires conservés, aucun doublon ; les chapitres nouveaux du fichier sont ajoutés ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'un chapitre absent du nouveau fichier est signalé dans le rapport ; il reste en ligne, sauf si vous cochez « Mettre en brouillon les chapitres absents » ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'avec la case « Ajout au catalogue » cochée (par défaut) : aucune nouvelle annonce (ni article, ni Discord, ni e-mail, ni notification aux lecteurs) et la date de sortie du tome ne change pas ;', 'yume-core' ); ?></li>
					<li>
						<?php
						/* translators: %d : nombre de jours */
						echo esc_html( sprintf( _n( 'un seul remplacement peut attendre par tome ; s’il n’est ni appliqué ni annulé, il est supprimé au bout de %d jour.', 'un seul remplacement peut attendre par tome ; s’il n’est ni appliqué ni annulé, il est supprimé au bout de %d jours.', $yume_jours, 'yume-core' ), $yume_jours ) );
						?>
					</li>
				</ul>
				<p class="yn-publish__remplacement-actions">
					<button type="submit" form="yn-publish-formulaire" name="etape" value="verifier" class="yn-btn" data-yn-etape="verifier"><?php esc_html_e( 'Vérifier (sans rien changer en ligne)', 'yume-core' ); ?></button>
				</p>
				<div class="yn-publish__attente" data-yn-attente <?php echo $yume_attente ? '' : 'hidden'; ?>>
					<h4><?php esc_html_e( 'Version en attente : rien n’a changé en ligne', 'yume-core' ); ?></h4>
					<p data-yn-attente-texte><?php echo esc_html( $yume_attente ? (string) $yume_attente['texte'] : '' ); ?></p>
					<ul class="yn-publish__attente-liste" data-yn-attente-liste>
						<?php foreach ( $yume_attente ? (array) $yume_attente['chapitres'] : array() as $yume_v ) : ?>
							<li>
								<span class="yn-chip yn-chip--<?php echo 'inchange' === $yume_v['action'] ? 'info' : ( 'cree' === $yume_v['action'] ? 'ok' : 'warn' ); ?>"><?php echo esc_html( (string) $yume_v['etat'] ); ?></span>
								<span class="yn-publish__attente-titre"><?php echo esc_html( (string) $yume_v['titre'] ); ?></span>
								<a href="<?php echo esc_url( (string) $yume_v['apercu'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Aperçu', 'yume-core' ); ?><span class="yn-visually-hidden"> — <?php echo esc_html( (string) $yume_v['titre'] ); ?></span></a>
								<?php if ( '' !== (string) $yume_v['lien'] ) : ?>
									<a href="<?php echo esc_url( (string) $yume_v['lien'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Version en ligne', 'yume-core' ); ?><span class="yn-visually-hidden"> — <?php echo esc_html( (string) $yume_v['titre'] ); ?></span></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="yn-publish__remplacement-actions">
						<button type="submit" form="yn-publish-formulaire" name="etape" value="remplacer" class="yn-btn yn-btn--primary" data-yn-etape="remplacer"><?php esc_html_e( 'Remplacer la lecture en ligne maintenant', 'yume-core' ); ?></button>
						<button type="submit" form="yn-publish-formulaire" name="etape" value="annuler_remplacement" class="yn-btn" data-yn-etape="annuler_remplacement" formnovalidate><?php esc_html_e( 'Annuler le remplacement', 'yume-core' ); ?></button>
					</p>
				</div>
			</section>
		<?php endif; ?>

		<form id="yn-publish-formulaire" class="yn-publish__formulaire" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-yn-formulaire>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="tome_id" value="<?php echo esc_attr( (string) $v['tome_id'] ); ?>" data-yn-tome>
			<?php wp_nonce_field( self::ACTION, '_yume_nonce' ); ?>
			<?php // Bouton par défaut (touche Entrée dans un champ) : enregistrer en brouillon. ?>
			<button type="submit" name="etape" value="brouillon" class="yn-visually-hidden" tabindex="-1" aria-hidden="true" data-yn-etape="brouillon"><?php esc_html_e( 'Enregistrer en brouillon', 'yume-core' ); ?></button>

			<div class="yn-publish__principal">
				<fieldset class="yn-card yn-publish__champs">
					<legend class="yn-visually-hidden"><?php esc_html_e( 'Informations du tome', 'yume-core' ); ?></legend>
					<p class="yn-publish__champ yn-publish__champ--3">
						<label for="yn-publish-oeuvre" class="yn-label"><?php esc_html_e( 'Œuvre', 'yume-core' ); ?></label>
						<select id="yn-publish-oeuvre" name="oeuvre_id" required data-yn-oeuvre>
							<option value=""><?php esc_html_e( '— Choisir une œuvre —', 'yume-core' ); ?></option>
							<?php foreach ( $yume_oeuvres as $yume_id => $yume_libelle ) : ?>
								<option value="<?php echo esc_attr( (string) $yume_id ); ?>" <?php selected( (int) $v['oeuvre_id'], $yume_id ); ?>><?php echo esc_html( $yume_libelle ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="yn-publish__champ yn-publish__champ--3" data-yn-planning-champ>
						<label for="yn-publish-planning" class="yn-label"><?php esc_html_e( 'Tome du planning', 'yume-core' ); ?></label>
						<select id="yn-publish-planning" name="tome_planning" aria-describedby="yn-publish-planning-aide" data-yn-planning>
							<option value=""><?php esc_html_e( '— Nouveau tome —', 'yume-core' ); ?></option>
							<?php
							$yume_groupe = null;
							foreach ( $yume_planifies as $yume_p ) :
								if ( $yume_groupe !== $yume_p['oeuvre_id'] ) :
									if ( null !== $yume_groupe ) {
										echo '</optgroup>';
									}
									$yume_groupe = $yume_p['oeuvre_id'];
									echo '<optgroup label="' . esc_attr( $yume_oeuvres[ $yume_groupe ] ?? get_the_title( $yume_groupe ) ) . '">';
								endif;
								?>
								<option value="<?php echo esc_attr( (string) $yume_p['id'] ); ?>" data-oeuvre="<?php echo esc_attr( (string) $yume_p['oeuvre_id'] ); ?>" data-nature="<?php echo esc_attr( $yume_p['nature'] ); ?>" data-numero="<?php echo esc_attr( $yume_p['numero'] ); ?>" data-titre="<?php echo esc_attr( $yume_p['titre'] ); ?>" data-date="<?php echo esc_attr( $yume_p['date_sortie'] ); ?>" data-publie="<?php echo $yume_p['publie'] ? '1' : '0'; ?>" <?php selected( (int) $v['tome_id'], $yume_p['id'] ); ?>><?php echo esc_html( $yume_p['libelle'] ); ?></option>
								<?php
							endforeach;
							if ( null !== $yume_groupe ) {
								echo '</optgroup>';
							}
							?>
						</select>
						<span id="yn-publish-planning-aide" class="yn-muted yn-publish__aide"><?php esc_html_e( 'Un tome déjà prévu au planning : sa nature, son numéro et son titre sont repris, sans créer de doublon.', 'yume-core' ); ?></span>
					</p>
					<p class="yn-publish__champ">
						<label for="yn-publish-nature" class="yn-label"><?php esc_html_e( 'Nature', 'yume-core' ); ?></label>
						<select id="yn-publish-nature" name="nature" data-yn-nature>
							<?php foreach ( $yume_natures as $yume_cle => $yume_libelle ) : ?>
								<option value="<?php echo esc_attr( $yume_cle ); ?>" <?php selected( (string) $v['nature'], $yume_cle ); ?>><?php echo esc_html( $yume_libelle ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="yn-publish__champ">
						<label for="yn-publish-numero" class="yn-label"><?php esc_html_e( 'Numéro', 'yume-core' ); ?></label>
						<input id="yn-publish-numero" name="numero" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" value="<?php echo esc_attr( (string) $v['numero'] ); ?>" data-yn-numero aria-describedby="yn-publish-numero-aide">
						<span id="yn-publish-numero-aide" class="yn-visually-hidden"><?php esc_html_e( 'Un nombre : 10, ou 26,5 pour un tome intermédiaire.', 'yume-core' ); ?></span>
					</p>
					<p class="yn-publish__champ yn-publish__champ--4">
						<label for="yn-publish-titre" class="yn-label"><?php esc_html_e( 'Titre (facultatif)', 'yume-core' ); ?></label>
						<input id="yn-publish-titre" name="titre" type="text" value="<?php echo esc_attr( (string) $v['titre'] ); ?>" placeholder="<?php esc_attr_e( 'Ex. : Un bon jour pour attendre un meilleur jour', 'yume-core' ); ?>">
					</p>
					<p class="yn-publish__champ yn-publish__champ--2">
						<label for="yn-publish-date" class="yn-label"><?php esc_html_e( 'Sortie', 'yume-core' ); ?></label>
						<input id="yn-publish-date" name="date_sortie" type="datetime-local" value="<?php echo esc_attr( (string) $v['date_sortie'] ); ?>" data-yn-date>
					</p>
					<p class="yn-publish__champ yn-publish__champ--2">
						<label for="yn-publish-pdf" class="yn-label"><?php esc_html_e( 'Lien de téléchargement · PDF', 'yume-core' ); ?></label>
						<input id="yn-publish-pdf" name="lien_pdf" type="url" value="<?php echo esc_attr( (string) $v['lien_pdf'] ); ?>" placeholder="https://www.clictune.com/…" data-yn-lien="pdf" aria-describedby="yn-publish-liens-aide">
					</p>
					<p class="yn-publish__champ yn-publish__champ--2">
						<label for="yn-publish-epub" class="yn-label"><?php esc_html_e( 'Lien de téléchargement · EPUB', 'yume-core' ); ?></label>
						<input id="yn-publish-epub" name="lien_epub" type="url" value="<?php echo esc_attr( (string) $v['lien_epub'] ); ?>" placeholder="https://www.clictune.com/…" data-yn-lien="epub" aria-describedby="yn-publish-liens-aide">
					</p>
					<p id="yn-publish-liens-aide" class="yn-muted yn-publish__aide yn-publish__champ--6"><?php esc_html_e( 'Liens externes (ClicTune, Mega…) : les fichiers PDF et EPUB ne sont jamais hébergés sur le site.', 'yume-core' ); ?></p>
				</fieldset>

				<div class="yn-publish__depots">
					<div class="yn-publish__depot yn-publish__depot--couverture" data-yn-depot="couverture">
						<div class="yn-publish__vignette" data-yn-vignette <?php echo $yume_couv ? '' : 'hidden'; ?>>
							<?php if ( $yume_couv ) : ?>
								<img src="<?php echo esc_url( $yume_couv ); ?>" alt="<?php esc_attr_e( 'Couverture actuelle du tome', 'yume-core' ); ?>">
							<?php endif; ?>
						</div>
						<?php echo self::icone( 'image', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?>
						<strong class="yn-publish__depot-titre"><?php esc_html_e( 'Couverture', 'yume-core' ); ?></strong>
						<span class="yn-muted yn-publish__depot-aide" id="yn-publish-couverture-aide"><?php esc_html_e( 'Glissez l’image ici', 'yume-core' ); ?><br><?php esc_html_e( 'JPG / PNG / WebP · 1400 × 2000 px conseillé', 'yume-core' ); ?></span>
						<span class="yn-publish__nom" data-yn-nom-couverture></span>
						<input id="yn-publish-couverture" class="yn-publish__fichier" type="file" name="couverture" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" aria-describedby="yn-publish-couverture-aide" data-yn-fichier="couverture">
						<label for="yn-publish-couverture" class="yn-btn yn-btn--sm"><?php esc_html_e( 'Choisir un fichier', 'yume-core' ); ?></label>
						<input type="hidden" name="couverture_id" value="<?php echo esc_attr( (string) $v['couverture_id'] ); ?>">
					</div>

					<div class="yn-publish__depot yn-publish__depot--source" data-yn-depot="source">
						<?php echo self::icone( 'depot', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?>
						<strong class="yn-publish__depot-titre yn-publish__depot-titre--grand"><?php esc_html_e( 'Déposez le DOCX ou l’EPUB du tome', 'yume-core' ); ?></strong>
						<span class="yn-muted yn-publish__depot-texte" id="yn-publish-source-aide"><?php esc_html_e( 'Le fichier est découpé en chapitres pour la lecture en ligne (titres, dialogues « — », pensées en italique et illustrations conservés). Le PDF, lui, n’est qu’un lien.', 'yume-core' ); ?></span>
						<div class="yn-card yn-publish__fiche" data-yn-fiche <?php echo ( $yume_import || ! empty( $yume_meta['fichier'] ) ) ? '' : 'hidden'; ?>>
							<?php
							$yume_fichier = $yume_import ? (array) $yume_import['fichier'] : (array) ( $yume_meta['fichier'] ?? array() );
							$yume_resume  = $yume_import ? (string) $yume_import['resume'] : (string) ( $yume_meta['resume'] ?? '' );
							?>
							<span class="yn-chip yn-chip--ok" data-yn-fiche-format>✓ <?php echo esc_html( strtoupper( (string) ( $yume_fichier['format'] ?? 'docx' ) ) ); ?></span>
							<div class="yn-publish__fiche-texte">
								<strong data-yn-fiche-nom><?php echo esc_html( (string) ( $yume_fichier['nom'] ?? '' ) ); ?></strong>
								<div class="yn-muted" data-yn-fiche-details>
									<?php
									if ( ! empty( $yume_fichier['octets'] ) ) {
										/* translators: 1: taille, 2: résumé de l'analyse */
										echo esc_html( sprintf( __( '%1$s · importé : %2$s', 'yume-core' ), Fichiers::taille_lisible( (int) $yume_fichier['octets'] ), $yume_resume ) );
									}
									?>
								</div>
							</div>
							<button type="button" class="yn-publish__retirer" data-yn-retirer hidden><?php esc_html_e( 'Retirer', 'yume-core' ); ?></button>
						</div>
						<input id="yn-publish-source" class="yn-publish__fichier" type="file" name="source" accept=".docx,.epub,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/epub+zip" aria-describedby="yn-publish-source-aide yn-publish-source-max" data-yn-fichier="source">
						<label for="yn-publish-source" class="yn-btn yn-btn--sm"><?php echo $yume_tome && $yume_chaps ? esc_html__( 'Remplacer par un nouveau fichier', 'yume-core' ) : esc_html__( 'ou choisir un fichier', 'yume-core' ); ?></label>
						<span class="yn-muted yn-publish__max" id="yn-publish-source-max">
							<?php
							/* translators: %s : taille maximale */
							echo esc_html( sprintf( __( 'Taille maximale : %s', 'yume-core' ), $yume_max ) );
							?>
						</span>
						<span class="yn-bar yn-publish__progression" data-yn-progression hidden><span style="--v:0%"></span></span>
						<?php if ( $yume_tome && $yume_chaps ) : ?>
							<p class="yn-publish__remplacement" id="yn-publish-remplacement" data-yn-remplacement>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d : nombre de chapitres du tome */
										_n(
											'Ce tome a déjà %d chapitre. Un nouveau fichier le remplace en place, par numéro : mêmes adresses, commentaires conservés. Un chapitre absent du nouveau fichier reste en ligne, sauf si vous cochez « Mettre en brouillon les chapitres absents ».',
											'Ce tome a déjà %d chapitres. Un nouveau fichier les remplace en place, par numéro : mêmes adresses, commentaires conservés. Les chapitres absents du nouveau fichier restent en ligne, sauf si vous cochez « Mettre en brouillon les chapitres absents ».',
											count( $yume_chaps ),
											'yume-core'
										),
										count( $yume_chaps )
									)
								);
								if ( $yume_en_ligne > 0 ) {
									echo ' <strong>' . esc_html__( 'Tome déjà paru : le nouveau fichier est d’abord préparé à part ; rien ne change en ligne avant « Remplacer la lecture en ligne maintenant ».', 'yume-core' ) . '</strong>';
								}
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="yn-card yn-publish__chapitres" data-yn-chapitres>
					<span class="yn-label"><?php esc_html_e( 'Chapitres détectés (aperçu)', 'yume-core' ); ?></span>
					<div class="yn-publish__liste" data-yn-liste>
						<?php
						// Chapitres : ceux du dernier import (retour sans JavaScript) ou ceux du tome.
						$yume_lignes = array();
						if ( $yume_import && ! empty( $yume_import['chapitres'] ) ) {
							foreach ( (array) $yume_import['chapitres'] as $yume_c ) {
								$yume_lignes[] = array(
									'nature'     => (string) $yume_c['nature'],
									'numero'     => 'chapitre' === $yume_c['nature'] && null !== $yume_c['numero'] ? \Yume\Core\Import\Texte::numero_fr( (float) $yume_c['numero'] ) : (string) $yume_c['titre'],
									'sous_titre' => (string) $yume_c['sous_titre'],
									'mots'       => (int) $yume_c['nb_mots'],
								);
							}
						} else {
							foreach ( $yume_chaps as $yume_c ) {
								$yume_nature   = (string) get_post_meta( $yume_c->ID, 'yume_nature', true );
								$yume_numero   = get_post_meta( $yume_c->ID, 'yume_numero', true );
								$yume_lignes[] = array(
									'nature'     => '' === $yume_nature ? 'chapitre' : $yume_nature,
									'numero'     => ( '' === $yume_nature || 'chapitre' === $yume_nature ) && is_numeric( $yume_numero ) ? \Yume\Core\Import\Texte::numero_fr( (float) $yume_numero ) : yume_libelle_chapitre( (int) $yume_c->ID ),
									'sous_titre' => (string) get_post_meta( $yume_c->ID, 'yume_sous_titre', true ),
									'mots'       => (int) get_post_meta( $yume_c->ID, 'yume_nb_mots', true ),
								);
							}
						}
						?>
						<?php if ( $yume_lignes ) : ?>
							<?php foreach ( array_slice( $yume_lignes, 0, 7 ) as $yume_l ) : ?>
								<span><strong><?php echo esc_html( $yume_l['numero'] ); ?></strong><?php echo '' !== $yume_l['sous_titre'] ? ' · ' . esc_html( $yume_l['sous_titre'] ) : ''; ?> <span class="yn-muted"><?php echo esc_html( self::nombre( (int) $yume_l['mots'] ) . ' ' . __( 'mots', 'yume-core' ) ); ?></span></span>
							<?php endforeach; ?>
							<?php
							if ( count( $yume_lignes ) > 7 ) {
								$yume_reste    = array_slice( $yume_lignes, 7 );
								$yume_speciaux = array_column( array_filter( $yume_reste, static fn( $l ) => 'chapitre' !== $l['nature'] ), 'numero' );
								$yume_autres   = count( $yume_reste ) - count( $yume_speciaux );
								$yume_texte    = $yume_autres > 0
									/* translators: %d : nombre de chapitres restants */
									? sprintf( _n( '… %d autre', '… %d autres', $yume_autres, 'yume-core' ), $yume_autres )
									: '…';
								if ( $yume_speciaux ) {
									$yume_texte .= ( $yume_autres > 0 ? ' · ' : ' ' ) . implode( ', ', $yume_speciaux );
								}
								echo '<span class="yn-muted">' . esc_html( $yume_texte ) . '</span>';
							}
							?>
						<?php else : ?>
							<span class="yn-muted yn-publish__vide"><?php esc_html_e( 'Déposez le fichier du tome : ses chapitres apparaîtront ici avant tout enregistrement.', 'yume-core' ); ?></span>
						<?php endif; ?>
					</div>
					<ul class="yn-publish__avertissements" data-yn-avertissements <?php echo $yume_avert ? '' : 'hidden'; ?>>
						<?php foreach ( $yume_avert as $yume_a ) : ?>
							<li><span class="yn-chip yn-chip--warn"><?php echo self::icone( 'alerte', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?><?php echo esc_html( (string) $yume_a ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<div class="yn-publish__apercu">
						<button type="submit" name="etape" value="apercu" class="yn-btn" data-yn-etape="apercu" formtarget="_blank"><?php esc_html_e( 'Prévisualiser le chapitre 1', 'yume-core' ); ?></button>
					</div>
				</div>
			</div>

			<aside class="yn-publish__cote" aria-label="<?php esc_attr_e( 'Récapitulatif et actions', 'yume-core' ); ?>">
				<div class="yn-card yn-publish__recap">
					<span class="yn-label"><?php esc_html_e( 'Ce qui sera créé', 'yume-core' ); ?></span>
					<ul class="yn-publish__recap-liste" data-yn-recap>
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span data-yn-recap-tome><strong><?php echo esc_html( $yume_tome ? yume_libelle_tome( (int) $yume_tome->ID ) : __( 'Le tome', 'yume-core' ) ); ?></strong> <?php esc_html_e( 'avec sa couverture et ses liens de téléchargement', 'yume-core' ); ?></span></li>
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span data-yn-recap-chapitres>
							<?php
							if ( $yume_chaps ) {
								/* translators: %d : nombre de pages de lecture */
								echo wp_kses( sprintf( _n( '<strong>%d page de lecture</strong>, navigation et sommaire', '<strong>%d pages de lecture</strong>, navigation et sommaire', count( $yume_chaps ), 'yume-core' ), count( $yume_chaps ) ), array( 'strong' => array() ) );
							} else {
								echo wp_kses( __( '<strong>Une page de lecture par chapitre</strong>, navigation et sommaire', 'yume-core' ), array( 'strong' => array() ) );
							}
							?>
						</span></li>
						<li data-yn-recap-annonce <?php echo $v['sans_annonce'] ? 'hidden' : ''; ?>><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Article d’annonce</strong> dans « Sorties » (modifiable)', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Planning</strong> : étape « Publié », 100 %', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li data-yn-recap-notifications <?php echo $v['sans_annonce'] ? 'hidden' : ''; ?>><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Notifications</strong> : Discord (#sorties) et e-mail aux lecteurs qui suivent l’œuvre', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li data-yn-recap-catalogue <?php echo $v['sans_annonce'] ? '' : 'hidden'; ?>><span class="yn-chip yn-chip--info" aria-hidden="true">–</span><span><?php echo wp_kses( __( '<strong>Aucune annonce</strong> : ajout au catalogue, ni article, ni Discord, ni e-mail', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
					</ul>
				</div>

				<fieldset class="yn-card yn-publish__credits">
					<legend class="yn-label"><?php esc_html_e( 'Crédits du tome', 'yume-core' ); ?></legend>
					<p class="yn-publish__champ">
						<label for="yn-publish-trad" class="yn-publish__petit"><?php esc_html_e( 'Traduction', 'yume-core' ); ?></label>
						<input id="yn-publish-trad" type="text" name="credits[traduction]" value="<?php echo esc_attr( (string) $v['credits']['traduction'] ); ?>" autocomplete="off">
					</p>
					<p class="yn-publish__champ">
						<label for="yn-publish-relecture" class="yn-publish__petit"><?php esc_html_e( 'Relecture', 'yume-core' ); ?></label>
						<input id="yn-publish-relecture" type="text" name="credits[relecture]" value="<?php echo esc_attr( (string) $v['credits']['relecture'] ); ?>" autocomplete="off">
					</p>
					<p class="yn-publish__champ">
						<label for="yn-publish-edition" class="yn-publish__petit"><?php esc_html_e( 'Édition / couverture', 'yume-core' ); ?></label>
						<input id="yn-publish-edition" type="text" name="credits[edition]" value="<?php echo esc_attr( (string) $v['credits']['edition'] ); ?>" autocomplete="off">
					</p>
				</fieldset>

				<div class="yn-publish__option yn-publish__option--catalogue">
					<input type="hidden" name="sans_annonce" value="0">
					<input id="yn-publish-sans-annonce" type="checkbox" name="sans_annonce" value="1" aria-describedby="yn-publish-sans-annonce-aide" data-yn-sans-annonce <?php checked( $v['sans_annonce'] ); ?>>
					<span class="yn-publish__option-texte">
						<label for="yn-publish-sans-annonce"><?php esc_html_e( 'Ajout au catalogue : ne pas annoncer (pas d’article, pas de Discord, pas d’e-mail)', 'yume-core' ); ?></label>
						<span id="yn-publish-sans-annonce-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Pour un tome déjà paru (PDF/EPUB seuls) : sa lecture en ligne est ajoutée sans être présentée comme une nouveauté. Cochée d’office quand le tome est déjà publié.', 'yume-core' ); ?></span>
					</span>
				</div>

				<?php if ( $yume_chaps ) : ?>
					<p class="yn-publish__option">
						<input id="yn-publish-retirer" type="checkbox" name="retirer_absents" value="1">
						<label for="yn-publish-retirer"><?php esc_html_e( 'Mettre en brouillon les chapitres absents du nouveau fichier', 'yume-core' ); ?></label>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $retour['confirmer'] ) ) : ?>
					<p class="yn-publish__option yn-publish__option--confirmer">
						<input id="yn-publish-confirmer-vide" type="checkbox" name="confirmer_vide" value="1" data-yn-confirmer-vide>
						<label for="yn-publish-confirmer-vide"><?php esc_html_e( 'Publier quand même ce tome sans chapitre ni lien de téléchargement', 'yume-core' ); ?></label>
					</p>
				<?php endif; ?>

				<div class="yn-publish__actions">
					<button type="submit" name="etape" value="publier" class="yn-btn yn-btn--primary yn-publish__publier" data-yn-etape="publier"><?php esc_html_e( 'Publier maintenant', 'yume-core' ); ?></button>
					<div class="yn-publish__actions-ligne">
						<button type="submit" name="etape" value="programmer" class="yn-btn" data-yn-etape="programmer"><?php esc_html_e( 'Programmer', 'yume-core' ); ?><span data-yn-date-libelle><?php echo '' !== $yume_date_lib ? '· ' . esc_html( $yume_date_lib ) : ''; ?></span></button>
						<button type="submit" name="etape" value="brouillon" class="yn-btn" data-yn-etape="brouillon"><?php esc_html_e( 'Enregistrer en brouillon', 'yume-core' ); ?></button>
					</div>
					<span class="yn-muted yn-publish__note"><?php esc_html_e( 'La publication est réversible : un tome dépublié disparaît du site et des notifications en attente.', 'yume-core' ); ?></span>
				</div>

				<div class="yn-card yn-publish__resultat" data-yn-resultat hidden></div>
			</aside>
		</form>
	</div>
</div>
