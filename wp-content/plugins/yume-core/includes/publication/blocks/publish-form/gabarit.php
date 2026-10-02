<?php
/**
 * Gabarit du formulaire « Ajouter des chapitres à un tome » (inclus par Formulaire::rendu()).
 *
 * Trois étapes : 1 · le tome (œuvre puis tome existant ; créé ici seulement si aucun n'est
 * choisi), 2 · le fichier (comparé au tome par le script après l'analyse), 3 · la sortie des
 * nouveaux chapitres (maintenant, un par un au rythme, à une date ; annonce ; tome complet).
 * À droite, le récapitulatif « Ce qui va se passer » (data-yn-recap, mis à jour par le script).
 * L'encadré « Remplacer la lecture en ligne » d'un tome paru reste le remplacement en deux temps.
 *
 * Variables : $v (valeurs initiales), $retour (message de retour ou null).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

$yume_oeuvres  = self::oeuvres();
$yume_natures  = yume_natures_tome();
$yume_tome     = $v['tome'] instanceof \WP_Post ? $v['tome'] : null;
$yume_meta     = (array) ( $v['meta'] ?? array() );
$yume_chaps    = $yume_tome ? yume_get_chapitres( (int) $yume_tome->ID, array( 'status' => 'any' ) ) : array();
$yume_equipe   = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
$yume_planning = function_exists( 'yume_url_page' ) ? yume_url_page( 'planning' ) : home_url( '/' );
$yume_max      = Fichiers::taille_lisible( Fichiers::taille_max_source() );
$yume_couv     = $v['couverture_id'] ? wp_get_attachment_image_url( (int) $v['couverture_id'], 'medium' ) : '';
$yume_import   = is_array( $retour['rapport']['import'] ?? null ) ? $retour['rapport']['import'] : null;
$yume_avert    = $retour ? (array) ( $retour['rapport']['avertissements'] ?? array() ) : array();
$yume_tomes    = self::tomes_formulaire( $yume_tome ? (int) $yume_tome->ID : 0 );
// Tome déjà paru qui a une lecture en ligne : elle peut aussi être remplacée en entier, en deux
// temps (version en attente vérifiée, puis remplacement).
$yume_en_ligne = $yume_tome && 'publish' === $yume_tome->post_status ? count( yume_get_chapitres( (int) $yume_tome->ID ) ) : 0;
$yume_attente  = $yume_tome ? Remplacement::etat( (int) $yume_tome->ID ) : null;
// Ajout de chapitres : les mises à jour choisies attendent la sortie (« Publier »), pas d'encadré.
if ( $yume_attente && Service::MODE_CHAPITRES === ( $yume_attente['mode'] ?? '' ) ) {
	$yume_attente = null;
}
$yume_jours  = (int) round( Remplacement::duree() / DAY_IN_SECONDS );
$yume_infos  = $yume_tome ? Service::infos_tome( (int) $yume_tome->ID ) : null;
$yume_rythme = $yume_infos ? (string) $yume_infos['rythme'] : '';
// Annonce : « Annoncer les nouveaux chapitres » (tome à paraître ou en cours), ou « Ajout au
// catalogue » cochée d'office (tome paru et complet : lecture en ligne d'un tome migré).
$yume_catalogue = $yume_tome && Service::sans_annonce_par_defaut( $yume_tome );
$yume_muet      = (bool) $v['sans_annonce'];

// Libellé d'état (puce en haut à droite).
if ( $yume_tome ) {
	$yume_maj  = ! empty( $yume_meta['maj'] ) ? self::date_fr( (int) strtotime( $yume_meta['maj'] . ' UTC' ) ) : '';
	$yume_etat = 'publish' === $yume_tome->post_status
		? ( $yume_attente ? __( 'Version en attente : rien n’a changé en ligne', 'yume-core' ) : sprintf( /* translators: %s : état du tome (en cours de publication, publié) */ __( 'Tome en ligne · %s : ajout de chapitres', 'yume-core' ), $yume_infos ? mb_strtolower( (string) $yume_infos['parution_libelle'] ) : '' ) )
		: ( 'future' === $yume_tome->post_status
			/* translators: %s : date */
			? sprintf( __( 'Sortie programmée le %s', 'yume-core' ), self::date_fr( (int) strtotime( $yume_tome->post_date_gmt . ' UTC' ) ) )
			/* translators: %s : date */
			: ( '' !== $yume_maj ? sprintf( __( 'Brouillon enregistré le %s', 'yume-core' ), $yume_maj ) : __( 'Brouillon du planning', 'yume-core' ) ) );
} else {
	$yume_etat = __( 'Choisissez le tome', 'yume-core' );
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
				<span class="yn-label"><?php esc_html_e( 'Catalogue · ajouter des chapitres', 'yume-core' ); ?></span>
				<h2 class="yn-publish__titre"><?php esc_html_e( 'Ajouter des chapitres à un tome', 'yume-core' ); ?></h2>
				<p class="yn-muted yn-publish__intro"><?php esc_html_e( 'Un seul formulaire pour tous les cas : un chapitre, plusieurs, ou le tome entier. Le fichier est comparé au tome : les chapitres nouveaux sont ajoutés, ceux déjà en ligne restent tels quels sauf si vous choisissez de les mettre à jour. Rien n’est jamais retiré.', 'yume-core' ); ?></p>
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

		<form id="yn-publish-formulaire" class="yn-publish__formulaire" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-yn-formulaire>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="tome_id" value="<?php echo esc_attr( (string) $v['tome_id'] ); ?>" data-yn-tome>
			<?php // Ajout de chapitres (Service::MODE_CHAPITRES) ; « Vérifier » et « Remplacer » passent au remplacement en deux temps. ?>
			<input type="hidden" name="mode" value="<?php echo esc_attr( Service::MODE_CHAPITRES ); ?>" data-yn-mode>
			<?php wp_nonce_field( self::ACTION, '_yume_nonce' ); ?>
			<?php // Bouton par défaut (touche Entrée dans un champ) : enregistrer en brouillon. ?>
			<button type="submit" name="etape" value="brouillon" class="yn-visually-hidden" tabindex="-1" aria-hidden="true" data-yn-etape="brouillon"><?php esc_html_e( 'Enregistrer en brouillon', 'yume-core' ); ?></button>

			<div class="yn-publish__principal">
				<fieldset class="yn-card yn-publish__champs yn-publish__etape">
					<legend class="yn-publish__etape-titre"><?php esc_html_e( '1 · Le tome', 'yume-core' ); ?></legend>
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
						<label for="yn-publish-planning" class="yn-label"><?php esc_html_e( 'Tome', 'yume-core' ); ?></label>
						<select id="yn-publish-planning" name="tome_planning" aria-describedby="yn-publish-planning-aide" data-yn-planning>
							<option value=""><?php esc_html_e( '— Choisir un tome —', 'yume-core' ); ?></option>
							<?php
							$yume_groupe = null;
							foreach ( $yume_tomes as $yume_p ) :
								if ( $yume_groupe !== $yume_p['oeuvre_id'] ) :
									if ( null !== $yume_groupe ) {
										echo '</optgroup>';
									}
									$yume_groupe = $yume_p['oeuvre_id'];
									echo '<optgroup label="' . esc_attr( $yume_oeuvres[ $yume_groupe ] ?? get_the_title( $yume_groupe ) ) . '">';
								endif;
								?>
								<option value="<?php echo esc_attr( (string) $yume_p['id'] ); ?>" data-oeuvre="<?php echo esc_attr( (string) $yume_p['oeuvre_id'] ); ?>" data-nature="<?php echo esc_attr( $yume_p['nature'] ); ?>" data-numero="<?php echo esc_attr( $yume_p['numero'] ); ?>" data-titre="<?php echo esc_attr( $yume_p['titre'] ); ?>" data-date="<?php echo esc_attr( $yume_p['date_sortie'] ); ?>" data-publie="<?php echo $yume_p['publie'] ? '1' : '0'; ?>" data-parution="<?php echo esc_attr( $yume_p['parution'] ); ?>" data-parution-libelle="<?php echo esc_attr( $yume_p['parution_libelle'] ); ?>" data-en-ligne="<?php echo esc_attr( (string) $yume_p['en_ligne'] ); ?>" data-prevus="<?php echo esc_attr( (string) $yume_p['prevus'] ); ?>" data-rythme="<?php echo esc_attr( $yume_p['rythme'] ); ?>" data-catalogue="<?php echo $yume_p['sans_annonce'] ? '1' : '0'; ?>" data-lien="<?php echo esc_url( $yume_p['lien'] ); ?>" <?php selected( (int) $v['tome_id'], $yume_p['id'] ); ?>><?php echo esc_html( $yume_p['libelle'] ); ?></option>
								<?php
							endforeach;
							if ( null !== $yume_groupe ) {
								echo '</optgroup>';
							}
							?>
						</select>
						<span id="yn-publish-planning-aide" class="yn-muted yn-publish__aide">
							<?php esc_html_e( 'Tous les tomes de l’œuvre, avec leur parution. La nature et le numéro appartiennent au tome, choisis à sa création : ce formulaire ne les modifie jamais.', 'yume-core' ); ?>
							<a href="<?php echo esc_url( self::url_nouveau_tome( (int) $v['oeuvre_id'] ) ); ?>" data-yn-nouveau-tome><?php esc_html_e( '+ Nouveau tome', 'yume-core' ); ?></a>
						</span>
					</p>
					<div class="yn-publish__fiche-tome yn-publish__champ--6" data-yn-fiche-tome <?php echo $yume_infos ? '' : 'hidden'; ?>>
						<span class="yn-chip yn-chip--<?php echo $yume_infos && 'complet' === $yume_infos['parution'] ? 'ok' : 'info'; ?>" data-yn-fiche-parution><?php echo esc_html( $yume_infos ? (string) $yume_infos['parution_libelle'] : '' ); ?></span>
						<span data-yn-fiche-en-ligne>
							<?php
							if ( $yume_infos && $yume_infos['en_ligne'] > 0 ) {
								/* translators: %s : chapitres en ligne (« Prologue à Chapitre 2 ») */
								echo esc_html( sprintf( __( '%s en ligne', 'yume-core' ), (string) $yume_infos['en_ligne_libelle'] ) );
							} elseif ( $yume_infos ) {
								esc_html_e( 'Aucun chapitre en ligne', 'yume-core' );
							}
							?>
						</span>
						<span class="yn-muted" data-yn-fiche-prevus>
							<?php
							if ( $yume_infos && $yume_infos['prevus'] > 0 ) {
								/* translators: 1: chapitres en ligne, 2: chapitres prévus */
								echo esc_html( sprintf( __( '%1$d sur %2$d', 'yume-core' ), (int) $yume_infos['en_ligne'], (int) $yume_infos['prevus'] ) );
							}
							?>
						</span>
						<span class="yn-muted" data-yn-fiche-rythme><?php echo esc_html( '' !== $yume_rythme ? ucfirst( $yume_rythme ) : '' ); ?></span>
						<span class="yn-publish__prevus">
							<label for="yn-publish-prevus" class="yn-label"><?php esc_html_e( 'Chapitres prévus', 'yume-core' ); ?></label>
							<input id="yn-publish-prevus" name="chapitres_prevus" type="number" min="0" max="999" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $v['chapitres_prevus'] ); ?>" aria-describedby="yn-publish-prevus-aide" data-yn-prevus>
							<span id="yn-publish-prevus-aide" class="yn-muted yn-publish__aide" data-yn-prevus-aide><?php esc_html_e( 'Relevé tout seul si le fichier apporte plus de chapitres que prévu. Modifiable ici.', 'yume-core' ); ?></span>
						</span>
						<a href="<?php echo esc_url( $yume_infos ? (string) $yume_infos['lien'] : '' ); ?>" data-yn-fiche-lien <?php echo $yume_infos && '' !== $yume_infos['lien'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Voir le tome', 'yume-core' ); ?></a>
					</div>
					<details class="yn-publish__creation yn-publish__champ--6" data-yn-creation <?php echo $yume_tome ? 'hidden' : ''; ?>>
						<summary><?php esc_html_e( 'Le tome n’existe pas encore ? Le créer ici (nature, numéro, titre)', 'yume-core' ); ?></summary>
						<p class="yn-muted yn-publish__aide"><?php esc_html_e( 'Utilisé seulement si aucun tome n’est choisi ci-dessus. Mieux : créez le tome avec « + Nouveau tome » (rythme de sortie, chapitres prévus), puis revenez ici.', 'yume-core' ); ?></p>
						<div class="yn-publish__creation-champs">
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
						</div>
					</details>
				</fieldset>

				<h3 class="yn-publish__etape-titre yn-publish__etape-titre--seul"><?php esc_html_e( '2 · Le fichier', 'yume-core' ); ?></h3>
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
						<strong class="yn-publish__depot-titre yn-publish__depot-titre--grand"><?php esc_html_e( 'Déposez le DOCX ou l’EPUB', 'yume-core' ); ?></strong>
						<span class="yn-muted yn-publish__depot-texte" id="yn-publish-source-aide"><?php esc_html_e( 'Le chapitre seul, ou le tome avec ses chapitres déjà parus : les deux marchent. Le fichier est découpé en chapitres pour la lecture en ligne (titres, dialogues « — », pensées en italique et illustrations conservés).', 'yume-core' ); ?></span>
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
						<label for="yn-publish-source" class="yn-btn yn-btn--sm"><?php esc_html_e( 'ou choisir un fichier', 'yume-core' ); ?></label>
						<span class="yn-muted yn-publish__max" id="yn-publish-source-max">
							<?php
							/* translators: %s : taille maximale */
							echo esc_html( sprintf( __( 'Taille maximale : %s', 'yume-core' ), $yume_max ) );
							?>
						</span>
						<span class="yn-bar yn-publish__progression" data-yn-progression hidden><span style="--v:0%"></span></span>
						<?php if ( $yume_tome && $yume_chaps ) : ?>
							<p class="yn-publish__note-tome" data-yn-note-tome>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d : nombre de chapitres du tome */
										_n(
											'Ce tome a déjà %d chapitre : le fichier lui est comparé. Les chapitres nouveaux sont ajoutés ; celui déjà en ligne reste tel quel, sauf si vous choisissez de le mettre à jour après l’analyse.',
											'Ce tome a déjà %d chapitres : le fichier leur est comparé. Les chapitres nouveaux sont ajoutés ; ceux déjà en ligne restent tels quels, sauf si vous choisissez de les mettre à jour après l’analyse.',
											count( $yume_chaps ),
											'yume-core'
										),
										count( $yume_chaps )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="yn-card yn-publish__chapitres" data-yn-chapitres>
					<span class="yn-label"><?php esc_html_e( 'Chapitres du fichier', 'yume-core' ); ?></span>
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
							<span class="yn-muted yn-publish__vide"><?php esc_html_e( 'Déposez le fichier : ses chapitres et leur état par rapport au tome apparaîtront ici avant tout enregistrement.', 'yume-core' ); ?></span>
						<?php endif; ?>
					</div>
					<?php
					// Comparaison avec le tome (script du bloc, après l'analyse) : un chapitre par ligne,
					// son état (« Nouveau », « En ligne, identique », « En ligne, modifié »,
					// « Programmé », « Brouillon ») et, pour un chapitre en ligne modifié, le choix
					// « Garder la version en ligne » / « Mettre à jour (sans annonce) » (champ choix[clé]).
					?>
					<div class="yn-publish__comparaison" data-yn-comparaison role="region" tabindex="0" aria-labelledby="yn-publish-comparaison-titre" hidden>
						<p class="yn-publish__comparaison-bilan" id="yn-publish-comparaison-titre" data-yn-comparaison-bilan></p>
						<table>
							<caption class="yn-visually-hidden"><?php esc_html_e( 'Chapitres du fichier comparés au tome', 'yume-core' ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Chapitre', 'yume-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Dans le fichier', 'yume-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Sur le site', 'yume-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Action', 'yume-core' ); ?></th>
								</tr>
							</thead>
							<tbody data-yn-comparaison-lignes></tbody>
						</table>
					</div>
					<ul class="yn-publish__avertissements" data-yn-avertissements <?php echo $yume_avert ? '' : 'hidden'; ?>>
						<?php foreach ( $yume_avert as $yume_a ) : ?>
							<li><span class="yn-chip yn-chip--warn"><?php echo self::icone( 'alerte', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique. ?><?php echo esc_html( (string) $yume_a ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p class="yn-muted yn-publish__aide yn-publish__marqueurs" id="yn-publish-marqueurs">
						<?php esc_html_e( 'Découpage à corriger ? Après l’analyse du fichier, « Délimiter les chapitres moi-même » permet de choisir chaque début de chapitre. Autre possibilité, sans rien installer : dans le document, ajoutez à chaque début de chapitre un paragraphe seul « [chapitre] Titre » (ou « [prologue] », « [interlude] Titre », « [bonus] Titre », « [épilogue] », « [postface] ») : il force ce début de chapitre et n’est pas publié.', 'yume-core' ); ?>
					</p>
				</div>

				<?php
				// Découpage manuel (script du bloc) : rempli après l'analyse du fichier choisi. Seul le
				// champ caché « plan » est envoyé, avec le même fichier ; les autres commandes n'ont pas
				// de nom. Sans JavaScript, le cadre reste masqué (marqueurs dans le document).
				?>
				<fieldset class="yn-card yn-publish__decoupage" data-yn-decoupage data-natures="<?php echo esc_attr( (string) wp_json_encode( \Yume\Core\Import\Texte::LIBELLES ) ); ?>" aria-describedby="yn-publish-decoupage-aide" hidden>
					<legend class="yn-label"><?php esc_html_e( 'Délimiter les chapitres moi-même', 'yume-core' ); ?></legend>
					<p id="yn-publish-decoupage-aide" class="yn-muted yn-publish__aide">
						<?php esc_html_e( 'Cochez chaque début de chapitre parmi les endroits repérés dans le fichier (titres, illustrations, sauts de page, lignes courtes, centrées ou en gras), puis choisissez sa nature et son titre. Le découpage est envoyé avec le fichier à l’enregistrement. Le fichier n’est pas conservé sur le serveur : pour redécouper plus tard, choisissez à nouveau le fichier.', 'yume-core' ); ?>
					</p>
					<p class="yn-publish__option">
						<input id="yn-publish-plan-actif" type="checkbox" data-yn-plan-actif>
						<label for="yn-publish-plan-actif"><?php esc_html_e( 'Utiliser ce découpage (sinon : détection automatique des chapitres)', 'yume-core' ); ?></label>
					</p>
					<div class="yn-publish__decoupage-outils">
						<button type="button" class="yn-btn yn-btn--sm" data-yn-plan-action="ouvertures"><?php esc_html_e( 'Couper aux ouvertures illustrées (2 illustrations ou plus à la suite)', 'yume-core' ); ?></button>
						<button type="button" class="yn-btn yn-btn--sm" data-yn-plan-action="images"><?php esc_html_e( 'Couper à chaque illustration', 'yume-core' ); ?></button>
						<button type="button" class="yn-btn yn-btn--sm" data-yn-plan-action="sauts"><?php esc_html_e( 'Couper à chaque saut de page', 'yume-core' ); ?></button>
						<button type="button" class="yn-btn yn-btn--sm" data-yn-plan-action="auto"><?php esc_html_e( 'Revenir à la détection automatique', 'yume-core' ); ?></button>
						<button type="button" class="yn-btn yn-btn--sm" data-yn-plan-verifier><?php esc_html_e( 'Vérifier ce découpage (rien n’est enregistré)', 'yume-core' ); ?></button>
					</div>
					<div class="yn-publish__decoupage-reglages">
						<p class="yn-publish__option">
							<input id="yn-publish-plan-avant" type="checkbox" data-yn-plan-avant aria-describedby="yn-publish-plan-avant-aide">
							<span class="yn-publish__option-texte">
								<label for="yn-publish-plan-avant"><?php esc_html_e( 'Conserver le texte d’ouverture', 'yume-core' ); ?></label>
								<span id="yn-publish-plan-avant-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Le texte placé avant le premier début coché (lignes surlignées) rejoint le premier chapitre ; sinon il n’est pas publié (ses illustrations vont dans la galerie du tome).', 'yume-core' ); ?></span>
							</span>
						</p>
						<p class="yn-publish__champ yn-publish__decoupage-premier">
							<label for="yn-publish-plan-premier" class="yn-publish__petit"><?php esc_html_e( 'Premier numéro de chapitre', 'yume-core' ); ?></label>
							<input id="yn-publish-plan-premier" type="number" min="0" step="any" value="1" inputmode="decimal" data-yn-plan-premier>
						</p>
						<p class="yn-publish__champ yn-publish__decoupage-premier">
							<label for="yn-publish-plan-filtre" class="yn-publish__petit"><?php esc_html_e( 'Afficher', 'yume-core' ); ?></label>
							<select id="yn-publish-plan-filtre" data-yn-plan-filtre>
								<option value="principaux"><?php esc_html_e( 'Titres, illustrations, sauts de page et débuts cochés', 'yume-core' ); ?></option>
								<option value="tous"><?php esc_html_e( 'Tous les débuts possibles', 'yume-core' ); ?></option>
								<option value="coches"><?php esc_html_e( 'Débuts cochés seulement', 'yume-core' ); ?></option>
							</select>
						</p>
					</div>
					<p class="yn-publish__decoupage-compte" data-yn-plan-compte role="status" aria-live="polite"></p>
					<div class="yn-publish__decoupage-table" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Débuts de chapitre possibles', 'yume-core' ); ?>">
						<table role="table">
							<caption class="yn-visually-hidden"><?php esc_html_e( 'Débuts de chapitre possibles, dans l’ordre du fichier', 'yume-core' ); ?></caption>
							<thead role="rowgroup">
								<tr role="row">
									<th scope="col" role="columnheader"><?php esc_html_e( 'Début de chapitre', 'yume-core' ); ?></th>
									<th scope="col" role="columnheader"><?php esc_html_e( 'Nature', 'yume-core' ); ?></th>
									<th scope="col" role="columnheader"><?php esc_html_e( 'Titre', 'yume-core' ); ?></th>
									<th scope="col" role="columnheader"><?php esc_html_e( 'Dans le fichier', 'yume-core' ); ?></th>
								</tr>
							</thead>
							<tbody role="rowgroup" data-yn-plan-lignes></tbody>
						</table>
					</div>
					<input type="hidden" name="plan" value="" data-yn-plan>
				</fieldset>

				<fieldset class="yn-card yn-publish__sortie yn-publish__etape" data-yn-sortie>
					<legend class="yn-publish__etape-titre"><?php esc_html_e( '3 · La sortie des nouveaux chapitres', 'yume-core' ); ?></legend>
					<div class="yn-publish__sorties" role="radiogroup" aria-label="<?php esc_attr_e( 'Sortie des nouveaux chapitres', 'yume-core' ); ?>">
						<p class="yn-publish__choix-sortie">
							<input id="yn-publish-sortie-maintenant" type="radio" name="sortie" value="maintenant" data-yn-sortie-choix <?php checked( 'maintenant', $v['sortie'] ); ?>>
							<label for="yn-publish-sortie-maintenant"><strong><?php esc_html_e( 'Maintenant', 'yume-core' ); ?></strong> <span class="yn-muted"><?php esc_html_e( 'Les nouveaux chapitres ensemble, une seule annonce.', 'yume-core' ); ?></span></label>
						</p>
						<p class="yn-publish__choix-sortie">
							<input id="yn-publish-sortie-rythme" type="radio" name="sortie" value="rythme" data-yn-sortie-choix <?php checked( 'rythme', $v['sortie'] ); ?>>
							<label for="yn-publish-sortie-rythme"><strong><?php esc_html_e( 'Un par un, au rythme', 'yume-core' ); ?></strong>
								<span class="yn-muted" data-yn-rythme-texte>
									<?php
									echo esc_html(
										'' !== $yume_rythme
											/* translators: %s : rythme (chaque samedi à 18 h) */
											? sprintf( __( '%s, après le dernier chapitre déjà programmé. Une annonce par chapitre.', 'yume-core' ), ucfirst( $yume_rythme ) )
											: __( 'Ce tome n’a pas de rythme : à partir de la date choisie, puis tous les N jours. Une annonce par chapitre.', 'yume-core' )
									);
									?>
								</span>
							</label>
						</p>
						<p class="yn-publish__choix-sortie">
							<input id="yn-publish-sortie-date" type="radio" name="sortie" value="date" data-yn-sortie-choix <?php checked( 'date', $v['sortie'] ); ?>>
							<label for="yn-publish-sortie-date"><strong><?php esc_html_e( 'À une date', 'yume-core' ); ?></strong> <span class="yn-muted"><?php esc_html_e( 'Les nouveaux chapitres ensemble, le jour choisi.', 'yume-core' ); ?></span></label>
						</p>
					</div>
					<div class="yn-publish__sortie-reglages">
						<p class="yn-publish__champ" data-yn-sortie-date>
							<label for="yn-publish-date" class="yn-label"><?php esc_html_e( 'Date (à une date, ou départ un par un)', 'yume-core' ); ?></label>
							<input id="yn-publish-date" name="date_sortie" type="datetime-local" value="<?php echo esc_attr( (string) $v['date_sortie'] ); ?>" data-yn-date>
						</p>
						<p class="yn-publish__champ" data-yn-sortie-intervalle>
							<label for="yn-publish-intervalle" class="yn-label"><?php esc_html_e( 'Un chapitre tous les (jours)', 'yume-core' ); ?></label>
							<input id="yn-publish-intervalle" name="intervalle" type="number" min="1" max="60" step="1" value="<?php echo esc_attr( (string) $v['intervalle'] ); ?>" data-yn-intervalle>
						</p>
					</div>

					<div class="yn-publish__option yn-publish__option--annoncer" data-yn-bloc-annoncer <?php echo $yume_catalogue ? 'hidden' : ''; ?>>
						<input type="hidden" name="annoncer" value="0"<?php disabled( $yume_catalogue ); ?>>
						<input id="yn-publish-annoncer" type="checkbox" name="annoncer" value="1" aria-describedby="yn-publish-annoncer-aide" data-yn-annoncer <?php checked( ! $yume_muet ); ?><?php disabled( $yume_catalogue ); ?>>
						<span class="yn-publish__option-texte">
							<label for="yn-publish-annoncer"><?php esc_html_e( 'Annoncer les nouveaux chapitres', 'yume-core' ); ?></label>
							<span id="yn-publish-annoncer-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Discord #sorties et e-mail aux lecteurs qui suivent l’œuvre (et l’article d’annonce à la première sortie du tome). Décochez pour un ajout silencieux. Un chapitre mis à jour n’est jamais annoncé.', 'yume-core' ); ?></span>
						</span>
					</div>
					<div class="yn-publish__option yn-publish__option--catalogue" data-yn-bloc-catalogue <?php echo $yume_catalogue ? '' : 'hidden'; ?>>
						<input type="hidden" name="sans_annonce" value="0"<?php disabled( ! $yume_catalogue ); ?>>
						<input id="yn-publish-sans-annonce" type="checkbox" name="sans_annonce" value="1" aria-describedby="yn-publish-sans-annonce-aide" data-yn-sans-annonce <?php checked( $yume_catalogue && $yume_muet ); ?><?php disabled( ! $yume_catalogue ); ?>>
						<span class="yn-publish__option-texte">
							<label for="yn-publish-sans-annonce"><?php esc_html_e( 'Ajout au catalogue : ne pas annoncer (pas d’article, pas de Discord, pas d’e-mail)', 'yume-core' ); ?></label>
							<span id="yn-publish-sans-annonce-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Pour un tome déjà paru et complet (PDF/EPUB seuls) : sa lecture en ligne est ajoutée sans être présentée comme une nouveauté. Cochée d’office pour un tome complet déjà publié.', 'yume-core' ); ?></span>
						</span>
					</div>

					<div class="yn-publish__complet" data-yn-bloc-complet>
						<div class="yn-publish__liens-complet">
							<p class="yn-publish__champ">
								<label for="yn-publish-pdf" class="yn-label"><?php esc_html_e( 'Lien de téléchargement · PDF', 'yume-core' ); ?></label>
								<input id="yn-publish-pdf" name="lien_pdf" type="url" value="<?php echo esc_attr( (string) $v['lien_pdf'] ); ?>" placeholder="https://www.clictune.com/…" data-yn-lien="pdf" aria-describedby="yn-publish-liens-aide">
							</p>
							<p class="yn-publish__champ">
								<label for="yn-publish-epub" class="yn-label"><?php esc_html_e( 'Lien de téléchargement · EPUB', 'yume-core' ); ?></label>
								<input id="yn-publish-epub" name="lien_epub" type="url" value="<?php echo esc_attr( (string) $v['lien_epub'] ); ?>" placeholder="https://www.clictune.com/…" data-yn-lien="epub" aria-describedby="yn-publish-liens-aide">
							</p>
							<p id="yn-publish-liens-aide" class="yn-muted yn-publish__aide"><?php esc_html_e( 'Liens externes (ClicTune, Mega…) : les fichiers PDF et EPUB ne sont jamais hébergés sur le site. Ils s’affichent quand le tome passe « Publié » : choisissez quand ci-dessous.', 'yume-core' ); ?></p>
						</div>
						<p class="yn-publish__option" data-yn-bloc-liens-dernier>
							<input type="hidden" name="liens_dernier" value="0">
							<input id="yn-publish-liens-dernier" type="checkbox" name="liens_dernier" value="1" aria-describedby="yn-publish-liens-dernier-aide" data-yn-liens-dernier <?php checked( $v['liens_dernier'] ); ?>>
							<span class="yn-publish__option-texte">
								<label for="yn-publish-liens-dernier"><?php esc_html_e( 'Publier les liens avec le dernier chapitre', 'yume-core' ); ?></label>
								<span id="yn-publish-liens-dernier-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Les chapitres sortent comme prévu ci-dessus. À la sortie du dernier chapitre programmé, le tome passe « Publié » : liens PDF et EPUB affichés, planning à 100 %, annonce « Le tome est complet ».', 'yume-core' ); ?></span>
							</span>
						</p>
						<p class="yn-publish__option">
							<input type="hidden" name="complet" value="0">
							<input id="yn-publish-complet" type="checkbox" name="complet" value="1" aria-describedby="yn-publish-complet-aide" data-yn-complet <?php checked( $v['complet'] ); ?>>
							<span class="yn-publish__option-texte">
								<label for="yn-publish-complet"><?php esc_html_e( 'Le tome est complet : tout publier maintenant', 'yume-core' ); ?></label>
								<span id="yn-publish-complet-aide" class="yn-muted yn-publish__option-aide"><?php esc_html_e( 'Tous les chapitres du tome sortent tout de suite, y compris ceux déjà programmés ; le tome passe « Publié », les liens s’affichent, le planning passe à 100 %. Remplace le choix de sortie ci-dessus.', 'yume-core' ); ?></span>
							</span>
						</p>
					</div>
				</fieldset>
			</div>

			<aside class="yn-publish__cote" aria-label="<?php esc_attr_e( 'Récapitulatif et actions', 'yume-core' ); ?>">
				<div class="yn-card yn-publish__recap">
					<span class="yn-label"><?php esc_html_e( 'Ce qui va se passer', 'yume-core' ); ?></span>
					<ul class="yn-publish__recap-liste" data-yn-recap aria-live="polite">
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span data-yn-recap-tome><strong><?php echo esc_html( $yume_tome ? yume_libelle_tome( (int) $yume_tome->ID ) : __( 'Le tome choisi', 'yume-core' ) ); ?></strong> <?php esc_html_e( 'reçoit les nouveaux chapitres du fichier', 'yume-core' ); ?></span></li>
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span data-yn-recap-chapitres>
							<?php
							if ( $yume_chaps ) {
								/* translators: %d : nombre de chapitres du tome */
								echo wp_kses( sprintf( _n( '<strong>%d chapitre déjà dans le tome</strong> : inchangé sauf choix « Mettre à jour »', '<strong>%d chapitres déjà dans le tome</strong> : inchangés sauf choix « Mettre à jour »', count( $yume_chaps ), 'yume-core' ), count( $yume_chaps ) ), array( 'strong' => array() ) );
							} else {
								echo wp_kses( __( '<strong>Une page de lecture par chapitre</strong>, navigation et sommaire', 'yume-core' ), array( 'strong' => array() ) );
							}
							?>
						</span></li>
						<li data-yn-recap-annonce <?php echo $yume_muet ? 'hidden' : ''; ?>><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Annonce</strong> : article dans « Sorties » à la première sortie du tome, puis à chaque nouveau chapitre', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Planning</strong> : en cours (avancement selon les chapitres), « Publié », 100 % quand le tome est complet', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li data-yn-recap-notifications <?php echo $yume_muet ? 'hidden' : ''; ?>><span class="yn-chip yn-chip--ok" aria-hidden="true">✓</span><span><?php echo wp_kses( __( '<strong>Notifications</strong> : Discord (#sorties) et e-mail aux lecteurs qui suivent l’œuvre', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
						<li data-yn-recap-catalogue <?php echo $yume_muet ? '' : 'hidden'; ?>><span class="yn-chip yn-chip--info" aria-hidden="true">–</span><span><?php echo wp_kses( __( '<strong>Aucune annonce</strong> : ajout au catalogue, ni article, ni Discord, ni e-mail', 'yume-core' ), array( 'strong' => array() ) ); ?></span></li>
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

				<?php if ( ! empty( $retour['confirmer'] ) ) : ?>
					<p class="yn-publish__option yn-publish__option--confirmer">
						<input id="yn-publish-confirmer-vide" type="checkbox" name="confirmer_vide" value="1" data-yn-confirmer-vide>
						<label for="yn-publish-confirmer-vide"><?php esc_html_e( 'Publier quand même ce tome sans chapitre ni lien de téléchargement', 'yume-core' ); ?></label>
					</p>
				<?php endif; ?>

				<div class="yn-publish__actions">
					<button type="submit" name="etape" value="publier" class="yn-btn yn-btn--primary yn-publish__publier" data-yn-etape="publier"><span data-yn-publier-libelle><?php esc_html_e( 'Publier les nouveaux chapitres', 'yume-core' ); ?></span></button>
					<div class="yn-publish__actions-ligne">
						<button type="submit" name="etape" value="apercu" class="yn-btn" data-yn-etape="apercu" formtarget="_blank"><?php esc_html_e( 'Prévisualiser', 'yume-core' ); ?></button>
						<button type="submit" name="etape" value="brouillon" class="yn-btn" data-yn-etape="brouillon"><?php esc_html_e( 'Enregistrer en brouillon', 'yume-core' ); ?></button>
					</div>
					<span class="yn-muted yn-publish__note"><?php esc_html_e( 'Réversible : un chapitre déprogrammé ou dépublié disparaît du sommaire, le tome reste en ligne ; un tome dépublié disparaît du site et des notifications en attente.', 'yume-core' ); ?></span>
				</div>

				<div class="yn-card yn-publish__resultat" data-yn-resultat hidden></div>
			</aside>
		</form>

		<?php if ( $yume_en_ligne > 0 || $yume_attente ) : ?>
			<section class="yn-card yn-publish__mode-remplacement" aria-labelledby="yn-publish-remplacer-titre" data-yn-mode-remplacement>
				<h3 id="yn-publish-remplacer-titre">
					<?php
					/* translators: %d : nombre de chapitres en ligne */
					echo esc_html( sprintf( _n( 'Remplacer la lecture en ligne (%d chapitre actuel)', 'Remplacer la lecture en ligne (%d chapitres actuels)', $yume_en_ligne, 'yume-core' ), $yume_en_ligne ) );
					?>
				</h3>
				<p><strong><?php esc_html_e( 'Pour refaire toute la lecture en ligne de ce tome (nouvelle traduction, corrections en masse) plutôt que d’y ajouter des chapitres. Rien ne change pour les lecteurs tant que vous n’avez pas cliqué sur « Remplacer la lecture en ligne maintenant ».', 'yume-core' ); ?></strong></p>
				<ol class="yn-publish__etapes-remplacement">
					<li><?php esc_html_e( 'Déposez le nouveau DOCX ou EPUB ci-dessus, puis cliquez sur « Vérifier (sans rien changer en ligne) » : le fichier est découpé en une version en attente, visible de l’équipe seulement ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'contrôlez le rapport et l’aperçu de chaque chapitre ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'cliquez sur « Remplacer la lecture en ligne maintenant » (ou sur « Annuler le remplacement »).', 'yume-core' ); ?></li>
				</ol>
				<ul>
					<li><?php esc_html_e( 'chaque chapitre est remplacé en place, par numéro : mêmes adresses, commentaires conservés, aucun doublon ; les chapitres nouveaux du fichier sont ajoutés ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'un chapitre absent du nouveau fichier est signalé dans le rapport ; il reste en ligne, sauf si vous cochez « Mettre en brouillon les chapitres absents » ;', 'yume-core' ); ?></li>
					<li><?php esc_html_e( 'avec la case « Ajout au catalogue » cochée : aucune nouvelle annonce (ni article, ni Discord, ni e-mail, ni notification aux lecteurs) et la date de sortie du tome ne change pas ;', 'yume-core' ); ?></li>
					<li>
						<?php
						/* translators: %d : nombre de jours */
						echo esc_html( sprintf( _n( 'un seul remplacement peut attendre par tome ; s’il n’est ni appliqué ni annulé, il est supprimé au bout de %d jour.', 'un seul remplacement peut attendre par tome ; s’il n’est ni appliqué ni annulé, il est supprimé au bout de %d jours.', $yume_jours, 'yume-core' ), $yume_jours ) );
						?>
					</li>
				</ul>
				<p class="yn-publish__option">
					<input id="yn-publish-retirer" type="checkbox" name="retirer_absents" value="1" form="yn-publish-formulaire">
					<label for="yn-publish-retirer"><?php esc_html_e( 'Mettre en brouillon les chapitres absents du nouveau fichier (remplacement seulement)', 'yume-core' ); ?></label>
				</p>
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
	</div>
</div>
