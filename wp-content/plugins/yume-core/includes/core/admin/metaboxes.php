<?php
/**
 * Méta-boîtes d'administration (formulaires classiques, sans étape de build) : fiche de
 * l'œuvre, tome, planning du tome, chapitre, et listes de navigation (tomes d'une œuvre,
 * chapitres d'un tome). Affichées sous l'éditeur de blocs comme dans l'éditeur classique.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Déclaration
 * -----------------------------------------------------------------------------
 */

/**
 * Méta-boîtes de l'œuvre.
 */
function metaboxes_oeuvre(): void {
	add_meta_box( 'yume-fiche-oeuvre', __( 'Fiche de l’œuvre', 'yume-core' ), __NAMESPACE__ . '\\boite_oeuvre', CPT_OEUVRE, 'normal', 'high' );
	add_meta_box( 'yume-tomes-oeuvre', __( 'Tomes de l’œuvre', 'yume-core' ), __NAMESPACE__ . '\\boite_tomes_oeuvre', CPT_OEUVRE, 'side', 'default' );
}
add_action( 'add_meta_boxes_' . CPT_OEUVRE, __NAMESPACE__ . '\\metaboxes_oeuvre' );

/**
 * Méta-boîtes du tome.
 */
function metaboxes_tome(): void {
	add_meta_box( 'yume-tome', __( 'Tome', 'yume-core' ), __NAMESPACE__ . '\\boite_tome', CPT_TOME, 'normal', 'high' );
	add_meta_box( 'yume-planning', __( 'Planning du tome', 'yume-core' ), __NAMESPACE__ . '\\boite_planning', CPT_TOME, 'normal', 'default' );
	add_meta_box( 'yume-chapitres-tome', __( 'Chapitres du tome', 'yume-core' ), __NAMESPACE__ . '\\boite_chapitres_tome', CPT_TOME, 'side', 'default' );
}
add_action( 'add_meta_boxes_' . CPT_TOME, __NAMESPACE__ . '\\metaboxes_tome' );

/**
 * Méta-boîte du chapitre.
 */
function metaboxes_chapitre(): void {
	add_meta_box( 'yume-chapitre', __( 'Chapitre', 'yume-core' ), __NAMESPACE__ . '\\boite_chapitre', CPT_CHAPITRE, 'normal', 'high' );
}
add_action( 'add_meta_boxes_' . CPT_CHAPITRE, __NAMESPACE__ . '\\metaboxes_chapitre' );

/*
 * -----------------------------------------------------------------------------
 * Petits composants de formulaire
 * -----------------------------------------------------------------------------
 */

/**
 * Ligne « libellé + champ texte ».
 *
 * @param string $id          ID du champ.
 * @param string $nom         Attribut name.
 * @param string $libelle     Libellé.
 * @param mixed  $valeur      Valeur.
 * @param string $type        Type d'input.
 * @param array  $attributs   Attributs supplémentaires (clé => valeur).
 * @param string $description Aide sous le champ.
 */
function champ( string $id, string $nom, string $libelle, $valeur, string $type = 'text', array $attributs = array(), string $description = '' ): void {
	$extra = '';
	foreach ( $attributs as $cle => $val ) {
		$extra .= ' ' . esc_attr( (string) $cle ) . ( true === $val ? '' : '="' . esc_attr( (string) $val ) . '"' );
	}
	if ( '' !== $description ) {
		$extra .= ' aria-describedby="' . esc_attr( $id . '-aide' ) . '"';
	}
	echo '<p class="yume-champ">';
	printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $libelle ) );
	printf(
		'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="widefat"%5$s />',
		esc_attr( $type ),
		esc_attr( $id ),
		esc_attr( $nom ),
		esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ),
		$extra // phpcs:ignore WordPress.Security.EscapeOutput
	);
	if ( '' !== $description ) {
		printf( '<span class="description" id="%1$s">%2$s</span>', esc_attr( $id . '-aide' ), esc_html( $description ) );
	}
	echo '</p>';
}

/**
 * Liste déroulante.
 *
 * @param string               $id       ID.
 * @param string               $nom      Attribut name.
 * @param string               $libelle  Libellé.
 * @param array<string,string> $options  Valeur => libellé.
 * @param mixed                $valeur   Valeur sélectionnée.
 * @param bool                 $desactive Champ en lecture seule.
 * @param bool                 $requis   Champ obligatoire.
 */
function liste( string $id, string $nom, string $libelle, array $options, $valeur, bool $desactive = false, bool $requis = false ): void {
	echo '<p class="yume-champ">';
	printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $libelle ) );
	printf( '<select id="%1$s" name="%2$s" class="widefat"%3$s%4$s>', esc_attr( $id ), esc_attr( $nom ), $desactive ? ' disabled' : '', $requis ? ' required' : '' );
	foreach ( $options as $option => $texte ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $valeur, (string) $option, false ), esc_html( (string) $texte ) );
	}
	echo '</select></p>';
}

/**
 * Trois champs texte {traduction, relecture, edition} dans un groupe.
 *
 * @param string               $prefixe Préfixe d'ID.
 * @param string               $nom     Attribut name de base.
 * @param string               $legende Légende du groupe.
 * @param array<string,string> $valeurs Valeurs.
 */
function trio_textes( string $prefixe, string $nom, string $legende, array $valeurs ): void {
	$libelles = array(
		'traduction' => __( 'Traduction', 'yume-core' ),
		'relecture'  => __( 'Relecture', 'yume-core' ),
		'edition'    => __( 'Édition', 'yume-core' ),
	);
	echo '<fieldset class="yume-groupe"><legend>' . esc_html( $legende ) . '</legend><div class="yume-colonnes">';
	foreach ( $libelles as $cle => $libelle ) {
		champ( $prefixe . '-' . $cle, $nom . '[' . $cle . ']', $libelle, $valeurs[ $cle ] ?? '' );
	}
	echo '</div></fieldset>';
}

/**
 * Valeur brute envoyée par le formulaire Yume (tableau $_POST['yume']), déséchappée.
 *
 * @param string $cle Clé.
 * @return mixed
 */
function saisie( string $cle ) {
	// Nonce vérifié par formulaire_valide() ; chaque valeur est assainie par l'appelant (san_*).
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$yume = isset( $_POST['yume'] ) && is_array( $_POST['yume'] ) ? wp_unslash( $_POST['yume'] ) : array();
	// phpcs:enable
	return $yume[ $cle ] ?? null;
}

/**
 * Le formulaire de la méta-boîte a-t-il été envoyé légitimement pour ce contenu ?
 *
 * @param int    $post_id  ID.
 * @param string $contexte oeuvre, tome ou chapitre.
 */
function formulaire_valide( int $post_id, string $contexte ): bool {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return false;
	}
	$champ = 'yume_nonce_' . $contexte;
	if ( ! isset( $_POST[ $champ ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $champ ] ) ), 'yume_enregistrer_' . $contexte . '_' . $post_id ) ) {
		return false;
	}
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Écrit une métadonnée, ou la supprime si la valeur est vide.
 *
 * @param int    $post_id ID.
 * @param string $cle     Clé.
 * @param mixed  $valeur  Valeur (déjà assainie).
 */
function ecrire_meta( int $post_id, string $cle, $valeur ): void {
	$vide = null === $valeur || '' === $valeur || array() === $valeur;
	if ( $vide ) {
		delete_post_meta( $post_id, $cle );
	} else {
		update_post_meta( $post_id, $cle, $valeur );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Œuvre
 * -----------------------------------------------------------------------------
 */

/**
 * Méta-boîte « Fiche de l'œuvre ».
 *
 * @param \WP_Post $post Œuvre.
 */
function boite_oeuvre( \WP_Post $post ): void {
	$id = (int) $post->ID;
	wp_nonce_field( 'yume_enregistrer_oeuvre_' . $id, 'yume_nonce_oeuvre' );

	$titres = san_liste_textes( get_post_meta( $id, 'yume_titres_alt', true ) );
	$jours  = san_jours( get_post_meta( $id, 'yume_jours_sortie', true ) );
	$liens  = san_liens( get_post_meta( $id, 'yume_liens', true ) );
	$equipe = san_trio_textes( get_post_meta( $id, 'yume_equipe', true ) );

	echo '<div class="yume-boite">';

	echo '<p class="yume-champ"><label for="yume-titres-alt">' . esc_html__( 'Titres alternatifs', 'yume-core' ) . '</label>';
	printf(
		'<textarea id="yume-titres-alt" name="yume[titres_alt]" rows="3" class="widefat" aria-describedby="yume-titres-alt-aide">%s</textarea>',
		esc_textarea( implode( "\n", $titres ) )
	);
	echo '<span class="description" id="yume-titres-alt-aide">' . esc_html__( 'Un titre par ligne : japonais, romaji, anglais, français…', 'yume-core' ) . '</span></p>';

	echo '<div class="yume-colonnes">';
	champ( 'yume-auteur', 'yume[auteur]', __( 'Auteur (scénario)', 'yume-core' ), get_post_meta( $id, 'yume_auteur', true ) );
	champ( 'yume-illustrateur', 'yume[illustrateur]', __( 'Illustrateur', 'yume-core' ), get_post_meta( $id, 'yume_illustrateur', true ) );
	champ( 'yume-editeur-vo', 'yume[editeur_vo]', __( 'Éditeur VO', 'yume-core' ), get_post_meta( $id, 'yume_editeur_vo', true ) );
	echo '</div><div class="yume-colonnes">';
	$nb_vo = (int) get_post_meta( $id, 'yume_nb_tomes_vo', true );
	champ(
		'yume-nb-tomes-vo',
		'yume[nb_tomes_vo]',
		__( 'Tomes parus en VO', 'yume-core' ),
		$nb_vo ? $nb_vo : '',
		'number',
		array(
			'min'  => 0,
			'step' => 1,
		)
	);
	liste(
		'yume-statut-vo',
		'yume[statut_vo]',
		__( 'Statut de la VO', 'yume-core' ),
		array(
			''         => __( '— Non renseigné —', 'yume-core' ),
			'en_cours' => __( 'En cours', 'yume-core' ),
			'termine'  => __( 'Terminée', 'yume-core' ),
		),
		get_post_meta( $id, 'yume_statut_vo', true )
	);
	champ( 'yume-source-traduction', 'yume[source_traduction]', __( 'Source de la traduction', 'yume-core' ), get_post_meta( $id, 'yume_source_traduction', true ), 'text', array( 'placeholder' => __( 'Édition anglaise officielle (J-Novel Club)', 'yume-core' ) ) );
	echo '</div>';

	echo '<fieldset class="yume-groupe"><legend>' . esc_html__( 'Jours de sortie', 'yume-core' ) . '</legend>';
	foreach ( yume_jours_semaine() as $jour => $libelle ) {
		printf(
			'<label class="yume-case"><input type="checkbox" name="yume[jours_sortie][]" value="%1$s"%2$s /> %3$s</label>',
			esc_attr( $jour ),
			checked( in_array( $jour, $jours, true ), true, false ),
			esc_html( $libelle )
		);
	}
	echo '</fieldset>';

	trio_textes( 'yume-equipe', 'yume[equipe]', __( 'Équipe de traduction (affichée sur la fiche)', 'yume-core' ), $equipe );

	// Liens externes : lignes existantes + deux lignes vides (et plus avec JavaScript).
	echo '<fieldset class="yume-groupe yume-liens" data-yume-lignes><legend>' . esc_html__( 'Liens externes', 'yume-core' ) . '</legend>';
	echo '<table class="yume-liens__table"><thead><tr><th scope="col">' . esc_html__( 'Libellé', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Adresse (URL)', 'yume-core' ) . '</th></tr></thead><tbody data-yume-lignes-corps>';
	$lignes = array_merge(
		$liens,
		array_fill(
			0,
			2,
			array(
				'label' => '',
				'url'   => '',
			)
		)
	);
	foreach ( $lignes as $i => $lien ) {
		ligne_lien( (string) $i, $lien['label'], $lien['url'] );
	}
	echo '</tbody></table>';
	echo '<template data-yume-lignes-modele>';
	ligne_lien( '__i__', '', '' );
	echo '</template>';
	echo '<p class="hide-if-no-js"><button type="button" class="button" data-yume-lignes-ajouter>' . esc_html__( 'Ajouter un lien', 'yume-core' ) . '</button></p>';
	echo '<p class="description">' . esc_html__( 'Novel-Index, MangaDex, éditeur, fil Discord… Une ligne sans adresse est ignorée.', 'yume-core' ) . '</p>';
	echo '</fieldset>';

	echo '<fieldset class="yume-groupe"><legend>' . esc_html__( 'Bannière de l’œuvre', 'yume-core' ) . '</legend>';
	champ_media( 'yume-banniere', 'yume[banniere_id]', (int) get_post_meta( $id, 'yume_banniere_id', true ), __( 'Bannière de l’œuvre', 'yume-core' ), false );
	echo '</fieldset>';

	// Caches en lecture seule.
	$note   = (float) get_post_meta( $id, 'yume_note_moyenne', true );
	$notes  = (int) get_post_meta( $id, 'yume_nb_notes', true );
	$fav    = (int) get_post_meta( $id, 'yume_nb_favoris', true );
	$sortie = (string) get_post_meta( $id, 'yume_derniere_sortie', true );
	echo '<p class="yume-infos">';
	printf(
		/* translators: 1: note moyenne, 2: nombre de notes, 3: favoris */
		esc_html__( 'Note moyenne : %1$s (%2$d notes) · Favoris : %3$d', 'yume-core' ),
		esc_html( number_format_i18n( $note, 1 ) ),
		(int) $notes,
		(int) $fav
	);
	if ( '' !== $sortie ) {
		echo ' · ' . esc_html(
			sprintf(
				/* translators: %s : date */
				__( 'Dernière sortie : %s', 'yume-core' ),
				get_date_from_gmt( $sortie, 'j F Y à H:i' )
			)
		);
	}
	echo '</p></div>';
}

/**
 * Ligne du tableau des liens.
 *
 * @param string $i     Index.
 * @param string $label Libellé.
 * @param string $url   URL.
 */
function ligne_lien( string $i, string $label, string $url ): void {
	printf(
		'<tr><td><label class="screen-reader-text" for="yume-lien-%1$s-label">%2$s</label><input type="text" id="yume-lien-%1$s-label" name="yume[liens][%1$s][label]" value="%3$s" class="widefat" /></td>'
		. '<td><label class="screen-reader-text" for="yume-lien-%1$s-url">%4$s</label><input type="url" id="yume-lien-%1$s-url" name="yume[liens][%1$s][url]" value="%5$s" class="widefat" placeholder="https://" /></td></tr>',
		esc_attr( $i ),
		esc_html__( 'Libellé du lien', 'yume-core' ),
		esc_attr( $label ),
		esc_html__( 'Adresse du lien', 'yume-core' ),
		esc_attr( $url )
	);
}

/**
 * Enregistre la fiche de l'œuvre.
 *
 * @param int $post_id ID.
 */
function enregistrer_oeuvre( $post_id ): void {
	$post_id = (int) $post_id;
	if ( ! formulaire_valide( $post_id, 'oeuvre' ) ) {
		return;
	}
	ecrire_meta( $post_id, 'yume_titres_alt', san_liste_textes( (string) saisie( 'titres_alt' ) ) );
	ecrire_meta( $post_id, 'yume_auteur', san_texte( saisie( 'auteur' ) ) );
	ecrire_meta( $post_id, 'yume_illustrateur', san_texte( saisie( 'illustrateur' ) ) );
	ecrire_meta( $post_id, 'yume_editeur_vo', san_texte( saisie( 'editeur_vo' ) ) );
	$nb_tomes = san_entier( saisie( 'nb_tomes_vo' ) );
	ecrire_meta( $post_id, 'yume_nb_tomes_vo', $nb_tomes ? $nb_tomes : '' );
	ecrire_meta( $post_id, 'yume_statut_vo', san_enum( saisie( 'statut_vo' ), array( 'en_cours', 'termine' ) ) );
	ecrire_meta( $post_id, 'yume_source_traduction', san_texte( saisie( 'source_traduction' ) ) );
	ecrire_meta( $post_id, 'yume_jours_sortie', san_jours( (array) saisie( 'jours_sortie' ) ) );
	$equipe = san_trio_textes( saisie( 'equipe' ) );
	ecrire_meta( $post_id, 'yume_equipe', array_filter( $equipe ) ? $equipe : '' );
	ecrire_meta( $post_id, 'yume_liens', san_liens( array_values( (array) saisie( 'liens' ) ) ) );
	$banniere = san_entier( saisie( 'banniere_id' ) );
	ecrire_meta( $post_id, 'yume_banniere_id', $banniere && 'attachment' === get_post_type( $banniere ) ? $banniere : '' );
}
add_action( 'save_post_' . CPT_OEUVRE, __NAMESPACE__ . '\\enregistrer_oeuvre' );

/**
 * Méta-boîte latérale « Tomes de l'œuvre ».
 *
 * @param \WP_Post $post Œuvre.
 */
function boite_tomes_oeuvre( \WP_Post $post ): void {
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p>' . esc_html__( 'Enregistrez l’œuvre pour lui ajouter des tomes.', 'yume-core' ) . '</p>';
		return;
	}
	$tomes = yume_get_tomes( (int) $post->ID, array( 'status' => 'any' ) );
	if ( ! $tomes ) {
		echo '<p>' . esc_html__( 'Aucun tome pour l’instant.', 'yume-core' ) . '</p>';
	} else {
		echo '<ul class="yume-liste">';
		foreach ( $tomes as $tome ) {
			liste_contenu( $tome, yume_libelle_tome( (int) $tome->ID ) );
		}
		echo '</ul>';
	}
	if ( current_user_can( get_post_type_object( CPT_TOME )->cap->create_posts ) ) {
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=' . CPT_TOME . '&yume_oeuvre=' . (int) $post->ID ) ),
			esc_html__( 'Ajouter un tome', 'yume-core' )
		);
	}
}

/**
 * Élément de liste « libellé (statut) » avec lien de modification.
 *
 * @param \WP_Post $post    Contenu.
 * @param string   $libelle Libellé.
 */
function liste_contenu( \WP_Post $post, string $libelle ): void {
	$statuts = array(
		'publish' => '',
		'future'  => __( 'programmé', 'yume-core' ),
		'draft'   => __( 'brouillon', 'yume-core' ),
		'pending' => __( 'en attente', 'yume-core' ),
		'private' => __( 'privé', 'yume-core' ),
	);
	$etat    = $statuts[ $post->post_status ] ?? $post->post_status;
	$lien    = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : '';
	echo '<li>';
	echo $lien ? '<a href="' . esc_url( $lien ) . '">' . esc_html( $libelle ) . '</a>' : esc_html( $libelle );
	if ( '' !== $etat ) {
		echo ' <span class="yume-etat">(' . esc_html( $etat ) . ')</span>';
	}
	echo '</li>';
}

/*
 * -----------------------------------------------------------------------------
 * Tome
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre à présélectionner pour un nouveau tome (?yume_oeuvre=ID).
 */
function oeuvre_demandee(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple présélection d'un champ.
	$id = isset( $_GET['yume_oeuvre'] ) ? absint( $_GET['yume_oeuvre'] ) : 0;
	return $id && CPT_OEUVRE === get_post_type( $id ) ? $id : 0;
}

/**
 * Méta-boîte « Tome ».
 *
 * @param \WP_Post $post Tome.
 */
function boite_tome( \WP_Post $post ): void {
	$id = (int) $post->ID;
	wp_nonce_field( 'yume_enregistrer_tome_' . $id, 'yume_nonce_tome' );

	$oeuvre_id = (int) get_post_meta( $id, 'yume_oeuvre_id', true );
	if ( ! $oeuvre_id && 'auto-draft' === $post->post_status ) {
		$oeuvre_id = oeuvre_demandee();
	}
	$numero = numero_ou_null( get_post_meta( $id, 'yume_numero', true ) );

	echo '<div class="yume-boite">';
	$oeuvres = array( '' => __( '— Choisir l’œuvre —', 'yume-core' ) );
	foreach ( liste_oeuvres() as $oid => $titre ) {
		$oeuvres[ (string) $oid ] = $titre;
	}
	echo '<div class="yume-colonnes">';
	liste( 'yume-oeuvre-id', 'yume[oeuvre_id]', __( 'Œuvre (obligatoire)', 'yume-core' ), $oeuvres, $oeuvre_id ? (string) $oeuvre_id : '', false, true );
	liste( 'yume-nature-tome', 'yume[nature]', __( 'Nature', 'yume-core' ), yume_natures_tome(), (string) get_post_meta( $id, 'yume_nature', true ) );
	champ(
		'yume-numero-tome',
		'yume[numero]',
		__( 'Numéro', 'yume-core' ),
		null === $numero ? '' : numero_url( $numero ),
		'number',
		array(
			'step' => 'any',
			'min'  => 0,
		),
		__( 'Ex. 9 ou 26.5. Le slug de l’URL vient du titre ou du champ « slug ».', 'yume-core' )
	);
	echo '</div>';
	if ( ! $oeuvre_id && 'auto-draft' !== $post->post_status ) {
		echo '<p class="yume-alerte" role="alert">' . esc_html__( 'Ce tome n’est rattaché à aucune œuvre : il n’a pas d’adresse publique.', 'yume-core' ) . '</p>';
	}

	echo '<div class="yume-colonnes">';
	champ( 'yume-lien-pdf', 'yume[lien_pdf]', __( 'Lien de téléchargement PDF', 'yume-core' ), get_post_meta( $id, 'yume_lien_pdf', true ), 'url', array( 'placeholder' => 'https://' ), __( 'Lien externe (les fichiers ne sont jamais hébergés sur le site).', 'yume-core' ) );
	champ( 'yume-lien-epub', 'yume[lien_epub]', __( 'Lien de téléchargement EPUB', 'yume-core' ), get_post_meta( $id, 'yume_lien_epub', true ), 'url', array( 'placeholder' => 'https://' ), __( 'Lien externe (les fichiers ne sont jamais hébergés sur le site).', 'yume-core' ) );
	echo '</div>';
	champ( 'yume-equivalence', 'yume[equivalence]', __( 'Équivalence', 'yume-core' ), get_post_meta( $id, 'yume_equivalence', true ), 'text', array( 'placeholder' => __( 'Cet arc équivaut au tome 3 du light novel', 'yume-core' ) ) );
	trio_textes( 'yume-credits-tome', 'yume[credits]', __( 'Crédits', 'yume-core' ), san_trio_textes( get_post_meta( $id, 'yume_credits', true ) ) );

	echo '<fieldset class="yume-groupe"><legend>' . esc_html__( 'Illustrations (galerie du tome)', 'yume-core' ) . '</legend>';
	champ_media( 'yume-illustrations', 'yume[illustrations]', get_post_meta( $id, 'yume_illustrations', true ), __( 'Illustrations du tome', 'yume-core' ), true );
	echo '</fieldset>';

	printf(
		'<p class="yume-infos">%s</p>',
		esc_html(
			sprintf(
				/* translators: %d : nombre de chapitres */
				_n( '%d chapitre publié.', '%d chapitres publiés.', (int) get_post_meta( $id, 'yume_nb_chapitres', true ), 'yume-core' ),
				(int) get_post_meta( $id, 'yume_nb_chapitres', true )
			)
		)
	);
	echo '</div>';
}

/**
 * Méta-boîte « Planning du tome ».
 *
 * @param \WP_Post $post Tome.
 */
function boite_planning( \WP_Post $post ): void {
	$id      = (int) $post->ID;
	$modif   = 'auto-draft' === $post->post_status ? current_user_can( 'yume_maj_planning_tous' ) : yume_user_can_edit_planning( $id );
	$lecture = ! $modif;

	$etape        = (string) get_post_meta( $id, 'yume_etape', true );
	$avancement   = san_avancement( get_post_meta( $id, 'yume_avancement', true ) );
	$responsables = san_responsables( get_post_meta( $id, 'yume_responsables', true ) );
	$bloque       = (bool) get_post_meta( $id, 'yume_bloque', true );
	$membres      = array( '0' => __( '— Personne —', 'yume-core' ) );
	foreach ( membres_equipe() as $uid => $nom ) {
		$membres[ (string) $uid ] = $nom;
	}
	foreach ( $responsables as $uid ) {
		if ( $uid && ! isset( $membres[ (string) $uid ] ) ) {
			$user                     = get_userdata( $uid );
			$membres[ (string) $uid ] = $user ? $user->display_name : sprintf( /* translators: %d : ID */ __( 'Utilisateur #%d', 'yume-core' ), $uid );
		}
	}

	echo '<div class="yume-boite">';
	if ( $lecture ) {
		echo '<p class="description">' . esc_html__( 'Lecture seule : seuls les responsables de ce tome et les éditeurs peuvent modifier son planning.', 'yume-core' ) . '</p>';
	}
	liste( 'yume-etape', 'yume[etape]', __( 'Étape', 'yume-core' ), yume_etapes(), $etape, $lecture );

	$libelles = array(
		'traduction' => __( 'Traduction', 'yume-core' ),
		'relecture'  => __( 'Relecture', 'yume-core' ),
		'edition'    => __( 'Édition', 'yume-core' ),
	);
	echo '<table class="yume-planning"><thead><tr><th scope="col">' . esc_html__( 'Étape', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Avancement (%)', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Responsable', 'yume-core' ) . '</th></tr></thead><tbody>';
	foreach ( $libelles as $cle => $libelle ) {
		echo '<tr><th scope="row">' . esc_html( $libelle ) . '</th><td>';
		printf(
			'<label class="screen-reader-text" for="yume-avancement-%1$s">%2$s</label><input type="number" id="yume-avancement-%1$s" name="yume[avancement][%1$s]" value="%3$d" min="0" max="100" step="1" class="small-text"%4$s /> %%',
			esc_attr( $cle ),
			esc_html(
				sprintf(
					/* translators: %s : étape */
					__( 'Avancement : %s', 'yume-core' ),
					$libelle
				)
			),
			(int) $avancement[ $cle ],
			$lecture ? ' disabled' : ''
		);
		echo '</td><td>';
		printf( '<label class="screen-reader-text" for="yume-responsable-%1$s">%2$s</label>', esc_attr( $cle ), esc_html( sprintf( /* translators: %s : étape */ __( 'Responsable : %s', 'yume-core' ), $libelle ) ) );
		printf( '<select id="yume-responsable-%1$s" name="yume[responsables][%1$s]"%2$s>', esc_attr( $cle ), $lecture ? ' disabled' : '' );
		foreach ( $membres as $uid => $nom ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $uid ), selected( (string) $responsables[ $cle ], (string) $uid, false ), esc_html( $nom ) );
		}
		echo '</select></td></tr>';
	}
	echo '</tbody></table>';

	echo '<div class="yume-colonnes">';
	champ( 'yume-date-cible', 'yume[date_cible]', __( 'Date de sortie visée', 'yume-core' ), get_post_meta( $id, 'yume_date_cible', true ), 'date', $lecture ? array( 'disabled' => true ) : array() );
	champ( 'yume-heure-cible', 'yume[heure_cible]', __( 'Heure de sortie', 'yume-core' ), get_post_meta( $id, 'yume_heure_cible', true ), 'time', $lecture ? array( 'disabled' => true ) : array( 'step' => 60 ) );
	echo '<p class="yume-champ yume-champ--case"><label for="yume-bloque"><input type="checkbox" id="yume-bloque" name="yume[bloque]" value="1"' . checked( $bloque, true, false ) . ( $lecture ? ' disabled' : '' ) . ' /> ' . esc_html__( 'Tome bloqué', 'yume-core' ) . '</label></p>';
	champ( 'yume-bloque-raison', 'yume[bloque_raison]', __( 'Raison du blocage', 'yume-core' ), get_post_meta( $id, 'yume_bloque_raison', true ), 'text', $lecture ? array( 'disabled' => true ) : array() );
	echo '</div>';

	if ( current_user_can( 'yume_maj_planning' ) ) {
		echo '<p class="yume-champ"><label for="yume-note-equipe">' . esc_html__( 'Note de l’équipe (jamais publique)', 'yume-core' ) . '</label>';
		printf(
			'<textarea id="yume-note-equipe" name="yume[note_equipe]" rows="3" class="widefat"%2$s>%1$s</textarea></p>',
			esc_textarea( (string) get_post_meta( $id, 'yume_note_equipe', true ) ),
			$lecture ? ' disabled' : ''
		);
	}

	// Valeurs lues à l'ouverture de l'éditeur : à l'enregistrement, seuls les champs que
	// l'utilisateur a réellement modifiés sont écrits (enregistrer_planning()).
	if ( ! $lecture ) {
		printf(
			'<input type="hidden" name="yume[planning_origine]" value="%s" />',
			esc_attr( (string) wp_json_encode( valeurs_planning( $id ) ) )
		);
	}

	$maj = (string) get_post_meta( $id, 'yume_derniere_maj', true );
	if ( '' !== $maj ) {
		$auteur = get_userdata( (int) get_post_meta( $id, 'yume_maj_par', true ) );
		echo '<p class="yume-infos">' . esc_html(
			sprintf(
				/* translators: 1: date, 2: nom */
				__( 'Dernière mise à jour du planning : %1$s par %2$s.', 'yume-core' ),
				get_date_from_gmt( $maj, 'j F Y à H:i' ),
				$auteur ? $auteur->display_name : __( 'inconnu', 'yume-core' )
			)
		) . '</p>';
	}
	echo '</div>';
}

/**
 * Enregistre le tome et son planning.
 *
 * @param int $post_id ID.
 */
function enregistrer_tome( $post_id ): void {
	$post_id = (int) $post_id;
	if ( ! formulaire_valide( $post_id, 'tome' ) ) {
		return;
	}
	$oeuvre_id = san_entier( saisie( 'oeuvre_id' ) );
	if ( $oeuvre_id && CPT_OEUVRE === get_post_type( $oeuvre_id ) ) {
		update_post_meta( $post_id, 'yume_oeuvre_id', $oeuvre_id );
	}
	ecrire_meta( $post_id, 'yume_nature', san_enum( saisie( 'nature' ), array_keys( yume_natures_tome() ), 'tome' ) );
	ecrire_meta( $post_id, 'yume_numero', san_nombre( saisie( 'numero' ) ) );
	ecrire_meta( $post_id, 'yume_lien_pdf', san_url( saisie( 'lien_pdf' ) ) );
	ecrire_meta( $post_id, 'yume_lien_epub', san_url( saisie( 'lien_epub' ) ) );
	ecrire_meta( $post_id, 'yume_equivalence', san_texte( saisie( 'equivalence' ) ) );
	$credits = san_trio_textes( saisie( 'credits' ) );
	ecrire_meta( $post_id, 'yume_credits', array_filter( $credits ) ? $credits : '' );
	$illustrations = array_values(
		array_filter(
			san_ids( saisie( 'illustrations' ) ),
			static function ( int $id ): bool {
				return 'attachment' === get_post_type( $id );
			}
		)
	);
	ecrire_meta( $post_id, 'yume_illustrations', $illustrations );

	if ( null !== saisie( 'etape' ) && yume_user_can_edit_planning( $post_id ) ) {
		enregistrer_planning( $post_id );
	}
}
add_action( 'save_post_' . CPT_TOME, __NAMESPACE__ . '\\enregistrer_tome' );

/**
 * Normalise une valeur de planning (stockée ou affichée) pour la comparer à une saisie.
 *
 * @param string $cle    Clé de métadonnée (yume_etape, yume_avancement…).
 * @param mixed  $valeur Valeur brute.
 * @return mixed
 */
function normaliser_planning( string $cle, $valeur ) {
	switch ( $cle ) {
		case 'yume_avancement':
			return san_avancement( $valeur );
		case 'yume_responsables':
			return san_responsables( $valeur );
		case 'yume_bloque':
			return (bool) $valeur;
		case 'yume_etape':
			// Comme la liste déroulante : une étape absente s'affiche (et s'envoie) « à faire ».
			return san_enum( $valeur, array_keys( yume_etapes() ), 'a_faire' );
		case 'yume_date_cible':
			return san_date( $valeur );
		case 'yume_heure_cible':
			return san_heure( $valeur );
		case 'yume_note_equipe':
			return san_texte_long( $valeur );
		default:
			return san_texte( $valeur );
	}
}

/**
 * Valeurs de planning stockées d'un tome, normalisées (champ caché de la méta-boîte).
 *
 * @param int $post_id Tome.
 * @return array<string,mixed>
 */
function valeurs_planning( int $post_id ): array {
	$valeurs = array();
	foreach ( array( 'yume_etape', 'yume_avancement', 'yume_responsables', 'yume_date_cible', 'yume_heure_cible', 'yume_bloque', 'yume_bloque_raison', 'yume_note_equipe' ) as $cle ) {
		$valeurs[ $cle ] = normaliser_planning( $cle, get_post_meta( $post_id, $cle, true ) );
	}
	return $valeurs;
}

/**
 * Enregistre les champs de planning qui ont changé, puis date et auteur de la mise à jour
 * et journal du module planning (s'il est chargé).
 *
 * @param int $post_id ID du tome.
 */
function enregistrer_planning( int $post_id ): void {
	$user_id = get_current_user_id();
	$nouveau = array(
		'yume_etape'         => san_enum( saisie( 'etape' ), array_keys( yume_etapes() ), 'a_faire' ),
		'yume_avancement'    => san_avancement( saisie( 'avancement' ) ),
		'yume_responsables'  => san_responsables( saisie( 'responsables' ) ),
		'yume_date_cible'    => san_date( saisie( 'date_cible' ) ),
		'yume_bloque'        => san_booleen( saisie( 'bloque' ) ),
		'yume_bloque_raison' => san_texte( saisie( 'bloque_raison' ) ),
	);
	// Heure de sortie : seulement si le champ est envoyé (formulaire antérieur : heure gardée).
	if ( null !== saisie( 'heure_cible' ) ) {
		$nouveau['yume_heure_cible'] = san_heure( saisie( 'heure_cible' ) );
	}
	if ( current_user_can( 'yume_maj_planning' ) && null !== saisie( 'note_equipe' ) ) {
		$nouveau['yume_note_equipe'] = san_texte_long( saisie( 'note_equipe' ) );
	}
	// Valeurs affichées à l'ouverture de l'éditeur. Un champ que l'utilisateur n'a pas touché
	// n'est jamais réécrit : la publication (étape « publié », 100 %) ou une mise à jour faite
	// entre-temps depuis l'espace équipe ou l'API ne sont pas écrasées par des valeurs périmées.
	$origine = saisie( 'planning_origine' );
	$origine = is_string( $origine ) ? json_decode( $origine, true ) : null;
	$origine = is_array( $origine ) ? $origine : null;

	$changements = array();
	$refus       = array();
	$forcee      = null;
	foreach ( $nouveau as $cle => $valeur ) {
		if ( null !== $origine && array_key_exists( $cle, $origine ) && normaliser_planning( $cle, $origine[ $cle ] ) === $valeur ) {
			continue; // Champ inchangé dans le formulaire.
		}
		$ancien = normaliser_planning( $cle, get_post_meta( $post_id, $cle, true ) );
		if ( $ancien === $valeur ) {
			continue;
		}
		// Désigner les responsables est réservé à yume_maj_planning_tous, et seulement parmi l'équipe.
		if ( 'yume_responsables' === $cle ) {
			if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
				$refus[] = __( 'Responsables non modifiés : seuls les éditeurs et les gérants désignent les responsables d’un tome.', 'yume-core' );
				continue;
			}
			foreach ( $valeur as $role => $uid ) {
				if ( $uid && ! user_can( (int) $uid, 'yume_voir_equipe' ) ) {
					$valeur[ $role ] = 0;
					$refus[]         = sprintf(
						/* translators: %s : nom du compte */
						__( '%s ne fait pas partie de l’équipe : il n’a pas été désigné responsable.', 'yume-core' ),
						get_the_author_meta( 'display_name', (int) $uid )
					);
				}
			}
			if ( $ancien === $valeur ) {
				continue;
			}
		}
		// Mêmes règles d'étape que l'espace équipe (« publié » suit la publication, étapes précédentes à 100 %).
		if ( 'yume_etape' === $cle ) {
			$controle = controler_etape_admin( $post_id, (string) $ancien, (string) $valeur, $user_id, $nouveau['yume_avancement'] );
			if ( is_wp_error( $controle ) ) {
				/* translators: %s : raison du refus */
				$refus[] = sprintf( __( 'Étape non modifiée : %s', 'yume-core' ), $controle->get_error_message() );
				continue;
			}
			if ( function_exists( '\\Yume\\Core\\Planning\\est_etape_forcee' )
				&& \Yume\Core\Planning\est_etape_forcee( $post_id, (string) $ancien, (string) $valeur, $user_id, $nouveau['yume_avancement'] ) ) {
				$forcee = array(
					(string) $ancien,
					array(
						'etape'      => (string) $valeur,
						'avancement' => $nouveau['yume_avancement'],
					),
				);
			}
		}
		if ( 'yume_bloque' === $cle ) {
			update_post_meta( $post_id, $cle, $valeur );
		} else {
			ecrire_meta( $post_id, $cle, $valeur );
		}
		$changements[ substr( $cle, 5 ) ] = array(
			'ancien'  => $ancien,
			'nouveau' => $valeur,
		);
	}
	if ( $refus ) {
		noter_refus_planning( $user_id, $post_id, $refus );
	}
	if ( ! $changements ) {
		return;
	}
	// L'heure du rythme des chapitres suit l'heure de sortie (module planning).
	if ( isset( $changements['heure_cible'] ) && function_exists( '\\Yume\\Core\\Planning\\caler_heure_rythme' ) ) {
		\Yume\Core\Planning\caler_heure_rythme( $post_id, (string) $changements['heure_cible']['nouveau'] );
	}
	update_post_meta( $post_id, 'yume_derniere_maj', current_time( 'mysql', true ) );
	update_post_meta( $post_id, 'yume_maj_par', $user_id );
	if ( function_exists( 'yume_journal_planning' ) ) {
		foreach ( $changements as $champ => $valeurs ) {
			yume_journal_planning( $post_id, $user_id, $champ, $valeurs['ancien'], $valeurs['nouveau'] );
		}
		// Étape forcée par un administrateur ou un gérant (journal de l'équipe seulement).
		if ( $forcee && isset( $changements['etape'] ) && function_exists( '\\Yume\\Core\\Planning\\journaliser' ) ) {
			\Yume\Core\Planning\journaliser( $post_id, $user_id, 'etape_forcee', $forcee[0], $forcee[1], false );
		}
	}
}

/**
 * Contrôle d'un changement d'étape saisi dans la méta-boîte : règles du module planning
 * (controler_etape()). Un administrateur ou un gérant (peut_forcer_etape(), module planning)
 * peut forcer une étape sans que les précédentes soient à 100 % ; les règles de l'étape
 * « publié » restent appliquées.
 *
 * @param int               $post_id    Tome.
 * @param string            $ancien     Étape actuelle.
 * @param string            $nouveau    Étape demandée.
 * @param int               $user_id    Auteur.
 * @param array<string,int> $avancement Avancement saisi.
 * @return true|\WP_Error
 */
function controler_etape_admin( int $post_id, string $ancien, string $nouveau, int $user_id, array $avancement ) {
	if ( ! function_exists( '\\Yume\\Core\\Planning\\controler_etape' ) ) {
		return true;
	}
	$controle = \Yume\Core\Planning\controler_etape( $post_id, $ancien, $nouveau, $user_id, $avancement );
	if ( is_wp_error( $controle ) && 'yume_etape_prematuree' === $controle->get_error_code()
		&& function_exists( '\\Yume\\Core\\Planning\\peut_forcer_etape' ) && \Yume\Core\Planning\peut_forcer_etape( $user_id ) ) {
		// Étape forcée : seul le contrôle d'avancement est levé.
		$controle = \Yume\Core\Planning\controler_etape( $post_id, $ancien, $nouveau, $user_id, array_fill_keys( array( 'traduction', 'relecture', 'edition' ), 100 ) );
	}
	return $controle;
}

/**
 * Clé du transitoire des refus de la méta-boîte « Planning du tome » (par utilisateur).
 *
 * @param int $user_id Utilisateur.
 */
function cle_refus_planning( int $user_id ): string {
	return 'yume_refus_planning_' . $user_id;
}

/**
 * Mémorise les champs de planning refusés à l'enregistrement, affichés au rechargement
 * (admin_notices) ou dans l'éditeur de blocs (avis après l'enregistrement des méta-boîtes).
 *
 * @param int      $user_id Utilisateur.
 * @param int      $post_id Tome.
 * @param string[] $refus   Messages.
 */
function noter_refus_planning( int $user_id, int $post_id, array $refus ): void {
	$refus = array_values( array_unique( array_map( 'strval', $refus ) ) );
	set_transient(
		cle_refus_planning( $user_id ),
		array(
			'tome'     => $post_id,
			'messages' => $refus,
		),
		10 * MINUTE_IN_SECONDS
	);
}

/**
 * Lit (et efface) les refus mémorisés pour un tome.
 *
 * @param int $user_id Utilisateur.
 * @param int $post_id Tome (0 : n'importe lequel).
 * @return string[]
 */
function lire_refus_planning( int $user_id, int $post_id = 0 ): array {
	$refus = get_transient( cle_refus_planning( $user_id ) );
	if ( ! is_array( $refus ) || ( $post_id && (int) ( $refus['tome'] ?? 0 ) !== $post_id ) ) {
		return array();
	}
	delete_transient( cle_refus_planning( $user_id ) );
	return array_map( 'strval', (array) ( $refus['messages'] ?? array() ) );
}

/**
 * Avis d'administration (éditeur classique, après la redirection) : champs refusés.
 */
function avis_refus_planning(): void {
	$ecran = get_current_screen();
	if ( ! $ecran instanceof \WP_Screen || 'post' !== $ecran->base || CPT_TOME !== $ecran->post_type || $ecran->is_block_editor() ) {
		return;
	}
	global $post;
	$messages = $post instanceof \WP_Post ? lire_refus_planning( get_current_user_id(), (int) $post->ID ) : array();
	foreach ( $messages as $message ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\avis_refus_planning' );

/**
 * Éditeur de blocs : les méta-boîtes sont enregistrées en arrière-plan, sans rechargement ;
 * un petit script demande les refus (admin-ajax) une fois cet enregistrement terminé et les
 * affiche comme avis de l'éditeur. Les refus d'un enregistrement précédent s'affichent aussi
 * à l'ouverture (demandés de même : la page rechargée en arrière-plan après l'enregistrement
 * des méta-boîtes ne doit pas les consommer).
 */
function script_refus_planning(): void {
	$ecran = get_current_screen();
	if ( ! $ecran instanceof \WP_Screen || CPT_TOME !== $ecran->post_type ) {
		return;
	}
	global $post;
	wp_register_script( 'yume-refus-planning', false, array( 'wp-data', 'wp-notices', 'wp-edit-post' ), YUME_CORE_VERSION, true );
	wp_enqueue_script( 'yume-refus-planning' );
	$config = array(
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'yume_refus_planning' ),
		'tome'  => $post instanceof \WP_Post ? (int) $post->ID : 0,
	);
	wp_add_inline_script(
		'yume-refus-planning',
		'( function ( config ) {
	var data = window.wp && window.wp.data;
	if ( ! data ) { return; }
	function afficher( messages ) {
		( messages || [] ).forEach( function ( message, i ) {
			data.dispatch( "core/notices" ).createNotice( "error", message, { id: "yume-refus-planning-" + i, isDismissible: true } );
		} );
	}
	function demander() {
		var corps = new URLSearchParams( { action: "yume_refus_planning", tome: String( config.tome ), _ajax_nonce: config.nonce } );
		window.fetch( config.ajax, { method: "POST", credentials: "same-origin", body: corps } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) { if ( r && r.success ) { afficher( r.data ); } } )
			.catch( function () {} );
	}
	if ( config.tome ) { demander(); }
	var enCours = false;
	data.subscribe( function () {
		var editeur = data.select( "core/edit-post" );
		if ( ! editeur || ! editeur.isSavingMetaBoxes ) { return; }
		var maintenant = editeur.isSavingMetaBoxes();
		if ( enCours && ! maintenant ) { demander(); }
		enCours = maintenant;
	} );
}( ' . wp_json_encode( $config ) . ' ) );'
	);
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\script_refus_planning' );

/**
 * Requête admin-ajax : refus mémorisés pour un tome (lus une seule fois).
 */
function ajax_refus_planning(): void {
	check_ajax_referer( 'yume_refus_planning' );
	$tome = isset( $_POST['tome'] ) ? absint( $_POST['tome'] ) : 0;
	if ( ! $tome || ! current_user_can( 'edit_post', $tome ) ) {
		wp_send_json_error( array(), 403 );
	}
	wp_send_json_success( lire_refus_planning( get_current_user_id(), $tome ) );
}
add_action( 'wp_ajax_yume_refus_planning', __NAMESPACE__ . '\\ajax_refus_planning' );

/**
 * Méta-boîte latérale « Chapitres du tome ».
 *
 * @param \WP_Post $post Tome.
 */
function boite_chapitres_tome( \WP_Post $post ): void {
	if ( 'auto-draft' === $post->post_status ) {
		echo '<p>' . esc_html__( 'Enregistrez le tome pour lui ajouter des chapitres.', 'yume-core' ) . '</p>';
		return;
	}
	$chapitres = yume_get_chapitres( (int) $post->ID, array( 'status' => 'any' ) );
	if ( ! $chapitres ) {
		echo '<p>' . esc_html__( 'Aucun chapitre pour l’instant.', 'yume-core' ) . '</p>';
	} else {
		echo '<ol class="yume-liste">';
		foreach ( $chapitres as $chapitre ) {
			liste_contenu( $chapitre, yume_libelle_chapitre( (int) $chapitre->ID ) );
		}
		echo '</ol>';
	}
	if ( current_user_can( get_post_type_object( CPT_CHAPITRE )->cap->create_posts ) ) {
		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=' . CPT_CHAPITRE . '&yume_tome=' . (int) $post->ID ) ),
			esc_html__( 'Ajouter un chapitre', 'yume-core' )
		);
	}
}

/*
 * -----------------------------------------------------------------------------
 * Chapitre
 * -----------------------------------------------------------------------------
 */

/**
 * Tomes groupés par œuvre pour une liste déroulante.
 *
 * @return array<string,array<int,string>> Titre de l'œuvre => [ID du tome => libellé].
 */
function tomes_par_oeuvre(): array {
	$ids = get_posts(
		array(
			'post_type'        => CPT_TOME,
			'post_status'      => statuts_actifs(),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		)
	);
	if ( $ids ) {
		update_meta_cache( 'post', $ids );
	}
	$groupes = array();
	foreach ( liste_oeuvres() as $oeuvre_id => $titre ) {
		foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $tome ) {
			// Libellé complet : la liste fermée n'affiche pas le groupe (l'œuvre).
			$libelle = $titre . ' — ' . yume_libelle_tome( (int) $tome->ID );
			if ( 'publish' !== $tome->post_status ) {
				$libelle .= ' — ' . __( 'non sorti', 'yume-core' );
			}
			$groupes[ $titre ][ (int) $tome->ID ] = $libelle;
		}
	}
	$orphelins = array();
	foreach ( $ids as $id ) {
		if ( ! yume_get_oeuvre_id( (int) $id ) ) {
			$orphelins[ (int) $id ] = get_the_title( (int) $id );
		}
	}
	if ( $orphelins ) {
		$groupes[ __( 'Sans œuvre', 'yume-core' ) ] = $orphelins;
	}
	return $groupes;
}

/**
 * Tome à présélectionner pour un nouveau chapitre (?yume_tome=ID).
 */
function tome_demande(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple présélection d'un champ.
	$id = isset( $_GET['yume_tome'] ) ? absint( $_GET['yume_tome'] ) : 0;
	return $id && CPT_TOME === get_post_type( $id ) ? $id : 0;
}

/**
 * Méta-boîte « Chapitre ».
 *
 * @param \WP_Post $post Chapitre.
 */
function boite_chapitre( \WP_Post $post ): void {
	$id = (int) $post->ID;
	wp_nonce_field( 'yume_enregistrer_chapitre_' . $id, 'yume_nonce_chapitre' );

	$tome_id = (int) get_post_meta( $id, 'yume_tome_id', true );
	if ( ! $tome_id && 'auto-draft' === $post->post_status ) {
		$tome_id = tome_demande();
	}
	$numero = numero_ou_null( get_post_meta( $id, 'yume_numero', true ) );

	echo '<div class="yume-boite">';
	echo '<p class="yume-champ"><label for="yume-tome-id">' . esc_html__( 'Tome (obligatoire)', 'yume-core' ) . '</label>';
	echo '<select id="yume-tome-id" name="yume[tome_id]" class="widefat" required>';
	echo '<option value="">' . esc_html__( '— Choisir le tome —', 'yume-core' ) . '</option>';
	foreach ( tomes_par_oeuvre() as $oeuvre => $tomes ) {
		echo '<optgroup label="' . esc_attr( $oeuvre ) . '">';
		foreach ( $tomes as $tid => $libelle ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $tid, selected( $tome_id, (int) $tid, false ), esc_html( $libelle ) );
		}
		echo '</optgroup>';
	}
	echo '</select></p>';

	echo '<div class="yume-colonnes">';
	liste( 'yume-nature-chapitre', 'yume[nature]', __( 'Nature', 'yume-core' ), yume_natures_chapitre(), (string) get_post_meta( $id, 'yume_nature', true ) );
	champ(
		'yume-numero-chapitre',
		'yume[numero]',
		__( 'Numéro', 'yume-core' ),
		null === $numero ? '' : numero_url( $numero ),
		'number',
		array(
			'step' => 'any',
			'min'  => 0,
		),
		__( 'Chapitre numéroté : adresse …/{numéro}/. Prologue : 0. Postface, épilogue… : adresse …/{slug}/.', 'yume-core' )
	);
	echo '</div>';
	champ( 'yume-sous-titre', 'yume[sous_titre]', __( 'Sous-titre', 'yume-core' ), get_post_meta( $id, 'yume_sous_titre', true ), 'text', array( 'placeholder' => __( 'La Crête Brumeuse', 'yume-core' ) ) );
	trio_textes( 'yume-credits-chapitre', 'yume[credits]', __( 'Crédits', 'yume-core' ), san_trio_textes( get_post_meta( $id, 'yume_credits', true ) ) );

	// Alerte : deux chapitres du même tome à la même adresse.
	if ( $tome_id && 'auto-draft' !== $post->post_status ) {
		$segment = segment_chapitre( $post );
		foreach ( ids_par_meta( CPT_CHAPITRE, 'yume_tome_id', $tome_id, statuts_actifs() ) as $autre ) {
			$autre_post = get_post( $autre );
			if ( $autre !== $id && $autre_post && '' !== $segment && segment_chapitre( $autre_post ) === $segment ) {
				echo '<p class="yume-alerte" role="alert">' . esc_html(
					sprintf(
						/* translators: %s : titre de l'autre chapitre */
						__( 'Attention : « %s » occupe déjà cette adresse dans ce tome. Changez le numéro ou la nature.', 'yume-core' ),
						get_the_title( $autre_post )
					)
				) . '</p>';
				break;
			}
		}
	}

	$mots      = (int) get_post_meta( $id, 'yume_nb_mots', true );
	$temps     = (int) get_post_meta( $id, 'yume_temps_lecture', true );
	$source    = san_source( get_post_meta( $id, 'yume_source', true ) );
	$infos     = array(
		sprintf(
			/* translators: %s : nombre de mots */
			_n( '%s mot', '%s mots', $mots, 'yume-core' ),
			number_format_i18n( $mots )
		),
		sprintf(
			/* translators: %d : minutes */
			__( 'environ %d min de lecture', 'yume-core' ),
			$temps
		),
	);
	$oeuvre_id = (int) get_post_meta( $id, 'yume_oeuvre_id', true );
	if ( $oeuvre_id ) {
		/* translators: %s : titre de l'œuvre */
		$infos[] = sprintf( __( 'Œuvre : %s', 'yume-core' ), get_the_title( $oeuvre_id ) );
	}
	if ( '' !== $source['format'] ) {
		$infos[] = sprintf(
			/* translators: 1: format, 2: date */
			__( 'Importé depuis un fichier %1$s le %2$s', 'yume-core' ),
			strtoupper( $source['format'] ),
			'' !== $source['importe_le'] ? mysql2date( 'j F Y', $source['importe_le'] ) : '—'
		);
	}
	echo '<p class="yume-infos">' . esc_html( implode( ' · ', $infos ) ) . '</p>';
	echo '</div>';
}

/**
 * Enregistre le chapitre.
 *
 * @param int $post_id ID.
 */
function enregistrer_chapitre( $post_id ): void {
	$post_id = (int) $post_id;
	if ( ! formulaire_valide( $post_id, 'chapitre' ) ) {
		return;
	}
	$tome_id = san_entier( saisie( 'tome_id' ) );
	if ( $tome_id && CPT_TOME === get_post_type( $tome_id ) ) {
		update_post_meta( $post_id, 'yume_tome_id', $tome_id );
	}
	ecrire_meta( $post_id, 'yume_nature', san_enum( saisie( 'nature' ), array_keys( yume_natures_chapitre() ), 'chapitre' ) );
	ecrire_meta( $post_id, 'yume_numero', san_nombre( saisie( 'numero' ) ) );
	ecrire_meta( $post_id, 'yume_sous_titre', san_texte( saisie( 'sous_titre' ) ) );
	$credits = san_trio_textes( saisie( 'credits' ) );
	ecrire_meta( $post_id, 'yume_credits', array_filter( $credits ) ? $credits : '' );
}
add_action( 'save_post_' . CPT_CHAPITRE, __NAMESPACE__ . '\\enregistrer_chapitre' );

/*
 * -----------------------------------------------------------------------------
 * Titres automatiques
 * -----------------------------------------------------------------------------
 */

/**
 * Titre calculé d'un tome ou d'un chapitre à partir des données envoyées (formulaire Yume,
 * meta_input) ou déjà enregistrées : « Grimgar of Fantasy and Ash — Tome 9 », « Chapitre 3 »,
 * « Postface ».
 *
 * @param string              $post_type Type.
 * @param array<string,mixed> $postarr   Données reçues par wp_insert_post().
 */
function titre_calcule( string $post_type, array $postarr ): string {
	$id     = (int) ( $postarr['ID'] ?? 0 );
	$form   = isset( $postarr['yume'] ) && is_array( $postarr['yume'] ) ? wp_unslash( $postarr['yume'] ) : array();
	$metas  = isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ? $postarr['meta_input'] : array();
	$valeur = static function ( string $cle ) use ( $form, $metas, $id ) {
		if ( isset( $form[ $cle ] ) && '' !== $form[ $cle ] ) {
			return $form[ $cle ];
		}
		if ( isset( $metas[ 'yume_' . $cle ] ) ) {
			return $metas[ 'yume_' . $cle ];
		}
		return $id ? get_post_meta( $id, 'yume_' . $cle, true ) : '';
	};
	$numero = numero_ou_null( $valeur( 'numero' ) );
	if ( CPT_TOME === $post_type ) {
		$oeuvre = san_entier( $valeur( 'oeuvre_id' ) );
		if ( ! $oeuvre || CPT_OEUVRE !== get_post_type( $oeuvre ) ) {
			return '';
		}
		$nature  = san_enum( $valeur( 'nature' ), array_keys( yume_natures_tome() ), 'tome' );
		$natures = yume_natures_tome();
		$libelle = $natures[ $nature ] . ( null === $numero ? '' : ' ' . numero_fr( $numero ) );
		return sanitize_text_field( get_post_field( 'post_title', $oeuvre ) . ' — ' . $libelle );
	}
	if ( CPT_CHAPITRE === $post_type ) {
		$nature = san_enum( $valeur( 'nature' ), array_keys( yume_natures_chapitre() ), 'chapitre' );
		if ( 'chapitre' === $nature && null === $numero ) {
			return '';
		}
		$natures = yume_natures_chapitre();
		$titre   = $natures[ $nature ];
		if ( null !== $numero && ( 'chapitre' === $nature || ( in_array( $nature, array( 'interlude', 'bonus' ), true ) && $numero > 0 ) ) ) {
			$titre .= ' ' . numero_fr( $numero );
		}
		return $titre;
	}
	return '';
}

/**
 * Un tome ou un chapitre sans titre ni texte peut être enregistré si son titre se déduit
 * de ses champs (œuvre et numéro, nature).
 *
 * @param bool                $vide    Contenu jugé vide par WordPress.
 * @param array<string,mixed> $postarr Données reçues.
 */
function autoriser_sans_titre( $vide, $postarr ) {
	if ( $vide && is_array( $postarr ) && in_array( $postarr['post_type'] ?? '', array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return '' === titre_calcule( (string) $postarr['post_type'], $postarr );
	}
	return $vide;
}
add_filter( 'wp_insert_post_empty_content', __NAMESPACE__ . '\\autoriser_sans_titre', 10, 2 );

/**
 * Donne un titre lisible seul à un tome ou un chapitre enregistré sans titre.
 *
 * @param array<string,mixed> $data    Données à insérer (échappées).
 * @param array<string,mixed> $postarr Données reçues.
 * @return array<string,mixed>
 */
function titre_automatique( $data, $postarr ) {
	if ( ! is_array( $data ) || ! is_array( $postarr ) || ! in_array( $data['post_type'] ?? '', array( CPT_TOME, CPT_CHAPITRE ), true ) ) {
		return $data;
	}
	if ( '' !== trim( (string) ( $data['post_title'] ?? '' ) ) || in_array( $data['post_status'] ?? '', array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
		return $data;
	}
	$titre = titre_calcule( (string) $data['post_type'], $postarr );
	if ( '' !== $titre ) {
		// Le slug d'un contenu publié sans slug est ensuite tiré de ce titre par wp_insert_post().
		$data['post_title'] = wp_slash( $titre );
	}
	return $data;
}
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\\titre_automatique', 10, 2 );
