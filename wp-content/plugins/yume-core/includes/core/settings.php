<?php
/**
 * Réglages Yume (§6 du contrat) : option unique yume_reglages, page Yume → Réglages
 * (Settings API, capacité yume_reglages), champs extensibles par le filtre yume_reglages_champs.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/** Slug de la page de réglages. */
const PAGE_REGLAGES = 'yume-reglages';

/**
 * Valeurs par défaut du contrat (§6), complétées par les champs déclarés avec 'default'.
 *
 * @return array<string,mixed>
 */
function defauts_reglages(): array {
	$defauts = array(
		'discord_webhook_sorties' => '',
		'discord_webhook_equipe'  => '',
		'rappel_jours_sans_maj'   => 14,
		'rappel_heure'            => 9,
		'digest_jour'             => 1,
		'emails_lecteurs'         => true,
		'banniere_id'             => 0,
		'kofi_url'                => 'https://ko-fi.com/ynovel',
		'discord_invite'          => 'https://discord.gg/tuMB3rmmWB',
		'twitter_url'             => 'https://x.com/Roshidere_FR',
		'jours_sortie'            => array( 'mercredi', 'samedi', 'dimanche' ),
		'modele_annonce'          => 'Le {nature} {numero} de {oeuvre} est disponible !',
		'github_repo'             => 'GNAlexandre/Yume-WordPress',
		'maj_auto'                => true,
	);
	if ( did_action( 'init' ) ) {
		foreach ( champs_reglages() as $champ ) {
			if ( ! array_key_exists( $champ['key'], $defauts ) && array_key_exists( 'default', $champ ) ) {
				$defauts[ $champ['key'] ] = $champ['default'];
			}
		}
	}
	return $defauts;
}

/**
 * Sections de la page (id => titre), extensibles par le filtre yume_reglages_sections.
 *
 * @return array<string,string>
 */
function sections_reglages(): array {
	$sections = array(
		'site'          => __( 'Site et réseaux', 'yume-core' ),
		'planning'      => __( 'Planning et rappels', 'yume-core' ),
		'notifications' => __( 'Annonces et notifications', 'yume-core' ),
		'mises_a_jour'  => __( 'Mises à jour', 'yume-core' ),
	);
	/**
	 * Filtre les sections de la page Yume → Réglages.
	 *
	 * @param array<string,string> $sections id => titre.
	 */
	return (array) apply_filters( 'yume_reglages_sections', $sections );
}

/**
 * Champs de la page de réglages (contrat §6 + filtre yume_reglages_champs).
 *
 * Chaque champ : key, label, type (text|url|number|checkbox|select|media|textarea|checkboxes),
 * section, options (select, checkboxes : valeur => libellé), description, et facultativement
 * default, min, max, step, placeholder, sanitize (callable).
 *
 * @return array<int,array<string,mixed>>
 */
function champs_reglages(): array {
	$heures = array();
	for ( $h = 0; $h < 24; $h++ ) {
		/* translators: %d : heure */
		$heures[ (string) $h ] = sprintf( __( '%d h', 'yume-core' ), $h );
	}
	$jours_digest = array(
		'1' => __( 'Lundi', 'yume-core' ),
		'2' => __( 'Mardi', 'yume-core' ),
		'3' => __( 'Mercredi', 'yume-core' ),
		'4' => __( 'Jeudi', 'yume-core' ),
		'5' => __( 'Vendredi', 'yume-core' ),
		'6' => __( 'Samedi', 'yume-core' ),
		'0' => __( 'Dimanche', 'yume-core' ),
	);
	$champs       = array(
		array(
			'key'         => 'banniere_id',
			'label'       => __( 'Bannière du site', 'yume-core' ),
			'type'        => 'media',
			'section'     => 'site',
			'description' => __( 'Image affichée en haut de l’accueil (bloc Bannière).', 'yume-core' ),
		),
		array(
			'key'         => 'kofi_url',
			'label'       => __( 'Page Ko-fi', 'yume-core' ),
			'type'        => 'url',
			'section'     => 'site',
			'description' => __( 'Lien du bouton « Soutenir ».', 'yume-core' ),
		),
		array(
			'key'         => 'discord_invite',
			'label'       => __( 'Invitation Discord', 'yume-core' ),
			'type'        => 'url',
			'section'     => 'site',
			'description' => '',
		),
		array(
			'key'         => 'twitter_url',
			'label'       => __( 'Compte X (Twitter)', 'yume-core' ),
			'type'        => 'url',
			'section'     => 'site',
			'description' => '',
		),
		array(
			'key'         => 'jours_sortie',
			'label'       => __( 'Jours de sortie habituels', 'yume-core' ),
			'type'        => 'checkboxes',
			'section'     => 'planning',
			'options'     => yume_jours_semaine(),
			'description' => __( 'Affichés sur le planning public et utilisés pour proposer les dates de sortie.', 'yume-core' ),
		),
		array(
			'key'         => 'rappel_jours_sans_maj',
			'label'       => __( 'Rappel après (jours sans mise à jour)', 'yume-core' ),
			'type'        => 'number',
			'section'     => 'planning',
			'min'         => 1,
			'max'         => 90,
			'description' => __( 'Un responsable reçoit un rappel quand son tome n’a pas été mis à jour depuis ce nombre de jours.', 'yume-core' ),
		),
		array(
			'key'         => 'rappel_heure',
			'label'       => __( 'Heure des rappels', 'yume-core' ),
			'type'        => 'select',
			'section'     => 'planning',
			'options'     => $heures,
			'description' => __( 'Heure de Paris.', 'yume-core' ),
		),
		array(
			'key'         => 'digest_jour',
			'label'       => __( 'Jour du récapitulatif hebdomadaire', 'yume-core' ),
			'type'        => 'select',
			'section'     => 'planning',
			'options'     => $jours_digest,
			'description' => __( 'Récapitulatif envoyé aux gérants.', 'yume-core' ),
		),
		array(
			'key'         => 'discord_webhook_sorties',
			'label'       => __( 'Webhook Discord des sorties', 'yume-core' ),
			'type'        => 'url',
			'section'     => 'notifications',
			'placeholder' => 'https://discord.com/api/webhooks/…',
			'description' => __( 'Annonce publique de chaque sortie. Laisser vide pour désactiver.', 'yume-core' ),
		),
		array(
			'key'         => 'discord_webhook_equipe',
			'label'       => __( 'Webhook Discord de l’équipe', 'yume-core' ),
			'type'        => 'url',
			'section'     => 'notifications',
			'placeholder' => 'https://discord.com/api/webhooks/…',
			'description' => __( 'Rappels et retards du planning. Laisser vide pour désactiver.', 'yume-core' ),
		),
		array(
			'key'         => 'emails_lecteurs',
			'label'       => __( 'E-mails aux lecteurs', 'yume-core' ),
			'type'        => 'checkbox',
			'section'     => 'notifications',
			'description' => __( 'Prévenir par e-mail les lecteurs qui suivent une œuvre à chaque sortie.', 'yume-core' ),
		),
		array(
			'key'         => 'modele_annonce',
			'label'       => __( 'Modèle de l’annonce de sortie', 'yume-core' ),
			'type'        => 'textarea',
			'section'     => 'notifications',
			'description' => __( 'Variables : {nature}, {numero}, {oeuvre}.', 'yume-core' ),
		),
		array(
			'key'         => 'github_repo',
			'label'       => __( 'Dépôt GitHub', 'yume-core' ),
			'type'        => 'text',
			'section'     => 'mises_a_jour',
			'placeholder' => 'propriétaire/dépôt',
			'description' => __( 'Dépôt dont les releases fournissent les mises à jour du plugin et du thème.', 'yume-core' ),
		),
		array(
			'key'         => 'maj_auto',
			'label'       => __( 'Mises à jour automatiques', 'yume-core' ),
			'type'        => 'checkbox',
			'section'     => 'mises_a_jour',
			'description' => __( 'Installer automatiquement les nouvelles versions publiées sur GitHub.', 'yume-core' ),
		),
	);
	/**
	 * Filtre les champs de la page Yume → Réglages (les modules y ajoutent les leurs).
	 *
	 * @param array<int,array<string,mixed>> $champs Champs.
	 */
	$champs = (array) apply_filters( 'yume_reglages_champs', $champs );

	$types  = array( 'text', 'url', 'number', 'checkbox', 'select', 'media', 'textarea', 'checkboxes' );
	$propre = array();
	$vues   = array();
	foreach ( $champs as $champ ) {
		if ( ! is_array( $champ ) || empty( $champ['key'] ) || ! is_string( $champ['key'] ) ) {
			continue;
		}
		$cle = sanitize_key( $champ['key'] );
		if ( '' === $cle || isset( $vues[ $cle ] ) ) {
			continue;
		}
		$vues[ $cle ] = true;
		$propre[]     = array_merge(
			array(
				'label'       => $cle,
				'type'        => 'text',
				'section'     => 'site',
				'options'     => array(),
				'description' => '',
			),
			$champ,
			array(
				'key'  => $cle,
				'type' => in_array( $champ['type'] ?? 'text', $types, true ) ? $champ['type'] : 'text',
			)
		);
	}
	return $propre;
}

/**
 * Assainit une valeur selon son champ.
 *
 * @param array<string,mixed> $champ  Champ.
 * @param mixed               $valeur Valeur saisie.
 * @param mixed               $defaut Valeur par défaut / précédente.
 * @return mixed
 */
function assainir_champ( array $champ, $valeur, $defaut ) {
	if ( ! empty( $champ['sanitize'] ) && is_callable( $champ['sanitize'] ) ) {
		return call_user_func( $champ['sanitize'], $valeur, $champ );
	}
	switch ( $champ['type'] ) {
		case 'url':
			return san_url( $valeur );
		case 'number':
			if ( ! is_scalar( $valeur ) || ! is_numeric( $valeur ) ) {
				return $defaut;
			}
			$nombre = ( isset( $champ['step'] ) && str_contains( (string) $champ['step'], '.' ) ) ? (float) $valeur : (int) $valeur;
			if ( isset( $champ['min'] ) ) {
				$nombre = max( $champ['min'], $nombre );
			}
			if ( isset( $champ['max'] ) ) {
				$nombre = min( $champ['max'], $nombre );
			}
			return $nombre;
		case 'checkbox':
			return san_booleen( $valeur );
		case 'select':
			$options = array_map( 'strval', array_keys( (array) $champ['options'] ) );
			$valeur  = is_scalar( $valeur ) ? (string) $valeur : '';
			if ( ! in_array( $valeur, $options, true ) ) {
				return $defaut;
			}
			return is_int( $defaut ) ? (int) $valeur : $valeur;
		case 'checkboxes':
			$options = array_map( 'strval', array_keys( (array) $champ['options'] ) );
			return array_values( array_intersect( $options, array_map( 'strval', array_filter( (array) $valeur, 'is_scalar' ) ) ) );
		case 'media':
			$id = san_entier( $valeur );
			return $id && 'attachment' === get_post_type( $id ) ? $id : 0;
		case 'textarea':
			return san_texte_long( $valeur );
		default:
			return san_texte( $valeur );
	}
}

/**
 * Callback d'assainissement de l'option yume_reglages.
 *
 * Depuis le formulaire (marqueur _formulaire), une case décochée vaut faux ; lors d'une mise à
 * jour programmée partielle, les clés absentes gardent leur valeur actuelle.
 *
 * @param mixed $entree Valeur soumise.
 * @return array<string,mixed>
 */
function assainir_reglages( $entree ): array {
	$entree     = is_array( $entree ) ? $entree : array();
	$formulaire = ! empty( $entree['_formulaire'] );
	unset( $entree['_formulaire'] );

	$defauts = defauts_reglages();
	$actuels = get_option( OPTION_REGLAGES, array() );
	$actuels = is_array( $actuels ) ? array_merge( $defauts, $actuels ) : $defauts;
	$sortie  = $actuels;
	$connus  = array();

	foreach ( champs_reglages() as $champ ) {
		$cle            = $champ['key'];
		$connus[ $cle ] = true;
		$precedent      = $actuels[ $cle ] ?? ( $champ['default'] ?? null );
		if ( array_key_exists( $cle, $entree ) ) {
			$sortie[ $cle ] = assainir_champ( $champ, $entree[ $cle ], $precedent );
		} elseif ( $formulaire && in_array( $champ['type'], array( 'checkbox', 'checkboxes' ), true ) ) {
			$sortie[ $cle ] = 'checkbox' === $champ['type'] ? false : array();
		}
	}

	// Webhooks Discord : seules des URL de webhook Discord sont acceptées.
	foreach ( array( 'discord_webhook_sorties', 'discord_webhook_equipe' ) as $cle ) {
		if ( ! empty( $sortie[ $cle ] ) && ! url_webhook_discord_valide( (string) $sortie[ $cle ] ) ) {
			add_settings_error(
				OPTION_REGLAGES,
				'yume_' . $cle,
				__( 'Ce lien n’est pas un webhook Discord (https://discord.com/api/webhooks/…) : la valeur précédente est conservée.', 'yume-core' )
			);
			$sortie[ $cle ] = (string) ( $actuels[ $cle ] ?? '' );
		}
	}

	// Dépôt GitHub : « propriétaire/dépôt ».
	if ( isset( $sortie['github_repo'] ) && ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $sortie['github_repo'] ) ) {
		add_settings_error( OPTION_REGLAGES, 'yume_github_repo', __( 'Le dépôt GitHub doit être de la forme « propriétaire/dépôt » : la valeur précédente est conservée.', 'yume-core' ) );
		$sortie['github_repo'] = (string) ( $actuels['github_repo'] ?? $defauts['github_repo'] );
	}

	// Clés inconnues transmises par programme (modules sans champ) : valeurs scalaires assainies.
	foreach ( $entree as $cle => $valeur ) {
		$cle = sanitize_key( (string) $cle );
		if ( '' !== $cle && ! isset( $connus[ $cle ] ) && ! $formulaire ) {
			$sortie[ $cle ] = is_scalar( $valeur ) ? ( is_string( $valeur ) ? sanitize_text_field( $valeur ) : $valeur ) : map_deep( $valeur, 'sanitize_text_field' );
		}
	}
	return $sortie;
}

/**
 * URL de webhook Discord valide.
 *
 * @param string $url URL.
 */
function url_webhook_discord_valide( string $url ): bool {
	return (bool) preg_match( '#^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\d+/[A-Za-z0-9_\-]+/?$#', $url );
}

/**
 * Déclare le réglage (admin_init et REST).
 */
function enregistrer_reglage(): void {
	register_setting(
		'yume_reglages',
		OPTION_REGLAGES,
		array(
			'type'              => 'object',
			'description'       => __( 'Réglages Yume Novel.', 'yume-core' ),
			'sanitize_callback' => __NAMESPACE__ . '\\assainir_reglages',
			'default'           => defauts_reglages(),
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\\enregistrer_reglage' );
add_action( 'rest_api_init', __NAMESPACE__ . '\\enregistrer_reglage' );

/**
 * Sections et champs de la page (Settings API).
 */
function declarer_champs(): void {
	$sections = sections_reglages();
	$champs   = champs_reglages();
	foreach ( $champs as $champ ) {
		if ( ! isset( $sections[ $champ['section'] ] ) ) {
			$sections[ $champ['section'] ] = ucfirst( str_replace( array( '_', '-' ), ' ', (string) $champ['section'] ) );
		}
	}
	foreach ( $sections as $id => $titre ) {
		add_settings_section( 'yume_' . $id, $titre, '__return_false', PAGE_REGLAGES );
	}
	foreach ( $champs as $champ ) {
		$args = array( 'champ' => $champ );
		if ( ! in_array( $champ['type'], array( 'checkbox', 'checkboxes', 'media' ), true ) ) {
			$args['label_for'] = 'yume-reglage-' . $champ['key'];
		}
		add_settings_field( 'yume_' . $champ['key'], esc_html( (string) $champ['label'] ), __NAMESPACE__ . '\\afficher_champ', PAGE_REGLAGES, 'yume_' . $champ['section'], $args );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\declarer_champs' );

/**
 * Affiche un champ de réglage.
 *
 * @param array{champ:array<string,mixed>} $args Arguments.
 */
function afficher_champ( array $args ): void {
	$champ  = $args['champ'];
	$cle    = (string) $champ['key'];
	$id     = 'yume-reglage-' . $cle;
	$nom    = OPTION_REGLAGES . '[' . $cle . ']';
	$valeur = yume_setting( $cle );
	$desc   = (string) ( $champ['description'] ?? '' );
	$aide   = '' !== $desc ? ' aria-describedby="' . esc_attr( $id . '-aide' ) . '"' : '';

	switch ( $champ['type'] ) {
		case 'checkbox':
			// La description sert de libellé à la case (le titre de la ligne reste le nom du réglage).
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $nom ),
				checked( (bool) $valeur, true, false ),
				esc_html( '' !== $desc ? $desc : (string) $champ['label'] )
			);
			return;
		case 'checkboxes':
			echo '<fieldset><legend class="screen-reader-text">' . esc_html( (string) $champ['label'] ) . '</legend>';
			foreach ( (array) $champ['options'] as $option => $libelle ) {
				printf(
					'<label class="yume-reglages__case"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label> ',
					esc_attr( $nom ),
					esc_attr( (string) $option ),
					checked( in_array( (string) $option, array_map( 'strval', (array) $valeur ), true ), true, false ),
					esc_html( (string) $libelle )
				);
			}
			echo '</fieldset>';
			break;
		case 'select':
			printf( '<select id="%1$s" name="%2$s"%3$s>', esc_attr( $id ), esc_attr( $nom ), $aide ); // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( (array) $champ['options'] as $option => $libelle ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( (string) $valeur, (string) $option, false ), esc_html( (string) $libelle ) );
			}
			echo '</select>';
			break;
		case 'textarea':
			printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text"%3$s>%4$s</textarea>', esc_attr( $id ), esc_attr( $nom ), $aide, esc_textarea( (string) $valeur ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'media':
			champ_media( $id, $nom, (int) $valeur, (string) $champ['label'], false );
			break;
		case 'number':
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s%5$s%6$s%7$s />',
				esc_attr( $id ),
				esc_attr( $nom ),
				esc_attr( (string) $valeur ),
				isset( $champ['min'] ) ? ' min="' . esc_attr( (string) $champ['min'] ) . '"' : '',
				isset( $champ['max'] ) ? ' max="' . esc_attr( (string) $champ['max'] ) . '"' : '',
				isset( $champ['step'] ) ? ' step="' . esc_attr( (string) $champ['step'] ) . '"' : '',
				$aide // phpcs:ignore WordPress.Security.EscapeOutput
			);
			break;
		default:
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" autocomplete="off"%5$s%6$s />',
				'url' === $champ['type'] ? 'url' : 'text',
				esc_attr( $id ),
				esc_attr( $nom ),
				esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ),
				! empty( $champ['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $champ['placeholder'] ) . '"' : '',
				$aide // phpcs:ignore WordPress.Security.EscapeOutput
			);
	}
	if ( '' !== $desc ) {
		printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $id . '-aide' ), esc_html( $desc ) );
	}
}

/**
 * Sélecteur de média accessible : champ numérique (fonctionne sans JavaScript) amélioré par
 * la médiathèque WordPress (assets/admin.js).
 *
 * @param string $id       ID du champ.
 * @param string $nom      Attribut name.
 * @param mixed  $valeur   ID ou liste d'IDs.
 * @param string $libelle  Libellé (titre de la fenêtre de sélection).
 * @param bool   $multiple Sélection multiple (IDs séparés par des virgules).
 */
function champ_media( string $id, string $nom, $valeur, string $libelle, bool $multiple ): void {
	$ids = $multiple ? san_ids( $valeur ) : array_filter( array( san_entier( $valeur ) ) );
	echo '<div class="yume-media" data-yume-media' . ( $multiple ? ' data-multiple="1"' : '' ) . ' data-titre="' . esc_attr( $libelle ) . '">';
	echo '<div class="yume-media__apercu" aria-live="polite">';
	foreach ( $ids as $media_id ) {
		$img = wp_get_attachment_image( $media_id, 'thumbnail', false, array( 'class' => 'yume-media__vignette' ) );
		if ( $img ) {
			echo $img; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</div>';
	printf(
		'<label class="yume-media__saisie" for="%1$s">%2$s <input type="text" inputmode="numeric" id="%1$s" name="%3$s" value="%4$s" class="%5$s" /></label>',
		esc_attr( $id ),
		esc_html( $multiple ? __( 'IDs des images (séparés par des virgules) :', 'yume-core' ) : __( 'ID de l’image :', 'yume-core' ) ),
		esc_attr( $nom ),
		esc_attr( implode( ',', $ids ) ),
		$multiple ? 'regular-text' : 'small-text'
	);
	echo ' <span class="yume-media__actions hide-if-no-js">';
	printf( '<button type="button" class="button yume-media__choisir">%s</button> ', esc_html( $multiple ? __( 'Choisir les images', 'yume-core' ) : __( 'Choisir une image', 'yume-core' ) ) );
	printf( '<button type="button" class="button-link yume-media__retirer"%2$s>%1$s</button>', esc_html__( 'Retirer', 'yume-core' ), $ids ? '' : ' hidden' );
	echo '</span></div>';
}

/**
 * Seule la capacité yume_reglages est exigée pour enregistrer les réglages (options.php).
 *
 * @return string
 */
function capacite_reglages(): string {
	return 'yume_reglages';
}
add_filter( 'option_page_capability_yume_reglages', __NAMESPACE__ . '\\capacite_reglages' );

/**
 * Page Yume → Réglages.
 */
function afficher_page_reglages(): void {
	if ( ! current_user_can( 'yume_reglages' ) ) {
		wp_die( esc_html__( 'Vous n’avez pas l’autorisation de modifier les réglages Yume.', 'yume-core' ), 403 );
	}
	echo '<div class="wrap yume-reglages">';
	echo '<h1>' . esc_html__( 'Réglages Yume', 'yume-core' ) . '</h1>';
	settings_errors();
	echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
	settings_fields( 'yume_reglages' );
	echo '<input type="hidden" name="' . esc_attr( OPTION_REGLAGES ) . '[_formulaire]" value="1" />';
	do_settings_sections( PAGE_REGLAGES );
	submit_button( __( 'Enregistrer les réglages', 'yume-core' ) );
	echo '</form></div>';
}
