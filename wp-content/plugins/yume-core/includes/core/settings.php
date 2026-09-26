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
 * Capacité exigée pour modifier la source et le mode des mises à jour (github_repo, maj_auto) :
 * celle qui permet déjà d'installer du code (Extensions → Mises à jour). Le rôle Gérant, qui a
 * yume_reglages mais pas update_plugins, ne voit ni ne modifie ces deux champs.
 */
const CAPACITE_MISES_A_JOUR = 'update_plugins';

/**
 * Le dépôt des mises à jour est-il figé par la constante YUME_GITHUB_REPO (wp-config.php) ?
 * Elle prime alors sur le réglage github_repo, qui n'est plus modifiable.
 */
function depot_fige(): bool {
	return defined( 'YUME_GITHUB_REPO' ) && is_string( constant( 'YUME_GITHUB_REPO' ) ) && '' !== trim( (string) constant( 'YUME_GITHUB_REPO' ) );
}

/**
 * L'utilisateur courant peut-il modifier ce champ ?
 *
 * Un champ peut exiger une capacité propre (clé 'capability') en plus de yume_reglages, ou être
 * verrouillé (clé 'verrouille' : message affiché). Sans utilisateur connecté (WP-CLI, tâche
 * planifiée, code d'installation), seul le verrou s'applique.
 *
 * @param array<string,mixed> $champ Champ.
 */
function champ_modifiable( array $champ ): bool {
	if ( ! empty( $champ['verrouille'] ) ) {
		return false;
	}
	$capacite = isset( $champ['capability'] ) && is_string( $champ['capability'] ) ? $champ['capability'] : '';
	if ( '' === $capacite || ! get_current_user_id() ) {
		return true;
	}
	return current_user_can( $capacite );
}

/**
 * L'utilisateur courant peut-il voir ce champ dans la page de réglages ?
 *
 * @param array<string,mixed> $champ Champ.
 */
function champ_visible( array $champ ): bool {
	$capacite = isset( $champ['capability'] ) && is_string( $champ['capability'] ) ? $champ['capability'] : '';
	return '' === $capacite || current_user_can( $capacite );
}

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
		'partenaires'             => partenaires_par_defaut(),
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
		'partenaires'   => __( 'Partenaires', 'yume-core' ),
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
 * default, min, max, step, placeholder, sanitize (callable), capability (capacité exigée en plus
 * de yume_reglages pour voir et modifier le champ), verrouille (message : champ non modifiable).
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
			'key'         => 'partenaires',
			'label'       => __( 'Partenaires de l’accueil', 'yume-core' ),
			'type'        => 'partenaires',
			'section'     => 'partenaires',
			'description' => sprintf(
				/* translators: %d : nombre maximal de partenaires */
				__( 'Section « Nos partenaires » de l’accueil (bloc Partenaires), dans cet ordre : %d partenaires au plus. Logo : ID d’une image de la médiathèque ou adresse d’une image ; sans logo, les initiales du nom sont affichées. Vider le nom et le lien d’une ligne la supprime.', 'yume-core' ),
				MAX_PARTENAIRES
			),
		),
		array(
			'key'         => 'github_repo',
			'label'       => __( 'Dépôt GitHub', 'yume-core' ),
			'type'        => 'text',
			'section'     => 'mises_a_jour',
			'placeholder' => 'propriétaire/dépôt',
			'description' => __( 'Dépôt dont les releases fournissent les mises à jour du plugin et du thème.', 'yume-core' ),
			// Désigner la source du code installé revient à pouvoir installer du code.
			'capability'  => CAPACITE_MISES_A_JOUR,
			'verrouille'  => depot_fige() ? __( 'Dépôt figé par la constante YUME_GITHUB_REPO (wp-config.php).', 'yume-core' ) : '',
		),
		array(
			'key'         => 'maj_auto',
			'label'       => __( 'Mises à jour automatiques', 'yume-core' ),
			'type'        => 'checkbox',
			'section'     => 'mises_a_jour',
			'description' => __( 'Installer automatiquement les nouvelles versions publiées sur GitHub.', 'yume-core' ),
			'capability'  => CAPACITE_MISES_A_JOUR,
		),
	);
	/**
	 * Filtre les champs de la page Yume → Réglages (les modules y ajoutent les leurs).
	 *
	 * @param array<int,array<string,mixed>> $champs Champs.
	 */
	$champs = (array) apply_filters( 'yume_reglages_champs', $champs );

	$types  = array( 'text', 'url', 'number', 'checkbox', 'select', 'media', 'textarea', 'checkboxes', 'partenaires' );
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
		case 'partenaires':
			return assainir_partenaires( $valeur );
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
		if ( ! champ_modifiable( $champ ) ) {
			// Capacité insuffisante ou champ verrouillé : la valeur actuelle est conservée.
			if ( array_key_exists( $cle, $actuels ) ) {
				$sortie[ $cle ] = $actuels[ $cle ];
			}
			continue;
		}
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
	$champs   = array_values( array_filter( champs_reglages(), __NAMESPACE__ . '\\champ_visible' ) );
	$remplies = array();
	foreach ( $champs as $champ ) {
		if ( ! isset( $sections[ $champ['section'] ] ) ) {
			$sections[ $champ['section'] ] = ucfirst( str_replace( array( '_', '-' ), ' ', (string) $champ['section'] ) );
		}
		$remplies[ $champ['section'] ] = true;
	}
	foreach ( $sections as $id => $titre ) {
		// Une section dont aucun champ n'est accessible à l'utilisateur n'est pas affichée.
		if ( isset( $remplies[ $id ] ) ) {
			add_settings_section( 'yume_' . $id, $titre, '__return_false', PAGE_REGLAGES );
		}
	}
	foreach ( $champs as $champ ) {
		$args = array( 'champ' => $champ );
		if ( ! in_array( $champ['type'], array( 'checkbox', 'checkboxes', 'media', 'partenaires' ), true ) ) {
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
	if ( ! champ_modifiable( $champ ) ) {
		// Champ verrouillé : valeur affichée en lecture seule (jamais envoyée par le formulaire).
		$verrou = (string) ( $champ['verrouille'] ?? '' );
		printf(
			'<input type="text" id="%1$s" value="%2$s" class="regular-text" disabled%3$s />',
			esc_attr( $id ),
			esc_attr( is_scalar( $valeur ) ? (string) $valeur : '' ),
			$aide // phpcs:ignore WordPress.Security.EscapeOutput
		);
		$desc = trim( $desc . ' ' . $verrou );
		if ( '' !== $desc ) {
			printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $id . '-aide' ), esc_html( $desc ) );
		}
		return;
	}

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
		case 'partenaires':
			champ_partenaires( $id, $nom, $valeur, (string) $champ['label'], '' !== $desc ? $id . '-aide' : '' );
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

/*
 * -----------------------------------------------------------------------------
 * Partenaires (section « Nos partenaires » de l'accueil, bloc yume/partenaires)
 * -----------------------------------------------------------------------------
 */

/** Nombre maximal de partenaires. */
const MAX_PARTENAIRES = 8;

/**
 * Partenaires de l'ancien site, affichés tant que le réglage n'a jamais été enregistré.
 *
 * Chaque ligne : nom, url, description, logo (ID de pièce jointe ou adresse d'image) et, pour
 * ces lignes par défaut seulement, fichier : nom du fichier attendu. Le logo n'est retenu que si
 * la pièce jointe porte bien ce fichier ; sinon il est cherché par nom de fichier (autre site,
 * démonstration), et à défaut les initiales sont affichées (voir le module bibliothèque).
 *
 * @return array<int,array<string,mixed>>
 */
function partenaires_par_defaut(): array {
	return array(
		array(
			'nom'         => 'MassNovel',
			'url'         => 'https://massnovel.fr/',
			'description' => 'Regroupe toutes les sorties de manhwa fantraduits en français',
			'logo'        => 1317,
			'fichier'     => 'logomassnovel-2.png',
		),
		array(
			'nom'         => 'Novel Index',
			'url'         => 'https://www.novel-index.com/',
			'description' => 'Regroupe toutes les sorties de LNs fantraduits en français',
			'logo'        => 1312,
			'fichier'     => 'logo-1.png',
		),
		array(
			'nom'         => 'Novel de l’Aube',
			'url'         => 'https://noveldelaube.com/',
			'description' => 'Team de fantraduction de LN japonais',
			'logo'        => 2486,
			'fichier'     => 'logo_ln-france4-1.webp',
		),
		array(
			'nom'         => 'J-Garden',
			'url'         => 'https://j-garden.fr/',
			'description' => 'Team de fantraduction de LN/manga japonais',
			'logo'        => 1313,
			'fichier'     => 'cropped-jg-logo-original.png',
		),
	);
}

/**
 * Assainit la liste des partenaires : nom et description (texte court), lien http(s)
 * obligatoire, logo (ID d'une pièce jointe existante ou adresse http(s) d'image), fichier
 * attendu du logo (lignes par défaut). Les lignes vides sont retirées ; une ligne sans lien
 * valide est ignorée (avec un avertissement dans la page de réglages).
 *
 * @param mixed $valeur Liste saisie (formulaire : lignes indexées).
 * @return array<int,array<string,mixed>>
 */
function assainir_partenaires( $valeur ): array {
	$sortie = array();
	foreach ( is_array( $valeur ) ? $valeur : array() as $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$nom         = mb_substr( san_texte( $ligne['nom'] ?? '' ), 0, 80 );
		$url_brute   = is_scalar( $ligne['url'] ?? null ) ? trim( (string) $ligne['url'] ) : '';
		$url         = san_url( $url_brute );
		$description = mb_substr( san_texte( $ligne['description'] ?? '' ), 0, 160 );
		if ( '' === $nom && '' === $url_brute ) {
			continue;
		}
		if ( '' === $nom || '' === $url || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					OPTION_REGLAGES,
					'yume_partenaire_' . count( $sortie ),
					sprintf(
						/* translators: %s : nom du partenaire */
						__( 'Partenaire « %s » ignoré : un nom et un lien http(s) sont nécessaires.', 'yume-core' ),
						'' !== $nom ? $nom : $url_brute
					)
				);
			}
			continue;
		}
		$partenaire = array(
			'nom'         => $nom,
			'url'         => $url,
			'description' => $description,
			'logo'        => assainir_logo_partenaire( $ligne['logo'] ?? '' ),
		);
		$fichier    = is_scalar( $ligne['fichier'] ?? null ) ? sanitize_file_name( (string) $ligne['fichier'] ) : '';
		if ( '' !== $fichier && is_int( $partenaire['logo'] ) ) {
			$partenaire['fichier'] = $fichier;
		}
		$sortie[] = $partenaire;
		if ( count( $sortie ) >= MAX_PARTENAIRES ) {
			break;
		}
	}
	return $sortie;
}

/**
 * Logo d'un partenaire : ID d'une pièce jointe existante (entier), adresse http(s) ou ''.
 *
 * @param mixed $logo Valeur saisie.
 * @return int|string
 */
function assainir_logo_partenaire( $logo ) {
	if ( ! is_scalar( $logo ) ) {
		return '';
	}
	$logo = trim( (string) $logo );
	if ( '' === $logo ) {
		return '';
	}
	if ( ctype_digit( $logo ) ) {
		$id = (int) $logo;
		return $id > 0 && 'attachment' === get_post_type( $id ) ? $id : '';
	}
	return san_url( $logo );
}

/**
 * Tableau des partenaires de la page de réglages (lignes existantes puis lignes vides).
 *
 * @param string $id      Préfixe des ID des champs.
 * @param string $nom     Attribut name du réglage.
 * @param mixed  $valeur  Liste enregistrée (ou par défaut).
 * @param string $libelle Libellé du réglage.
 * @param string $aide    ID de la description (aria-describedby), ou ''.
 */
function champ_partenaires( string $id, string $nom, $valeur, string $libelle, string $aide ): void {
	$lignes = is_array( $valeur ) ? array_values( array_filter( $valeur, 'is_array' ) ) : array();
	/**
	 * Filtre les partenaires affichés dans le formulaire : le module bibliothèque y remplace le
	 * logo des lignes par défaut par la pièce jointe réellement trouvée sur ce site.
	 *
	 * @param array<int,array<string,mixed>> $lignes Partenaires.
	 */
	$lignes   = (array) apply_filters( 'yume_reglages_partenaires_formulaire', $lignes );
	$lignes   = array_slice( $lignes, 0, MAX_PARTENAIRES );
	$colonnes = array(
		'nom'         => __( 'Nom', 'yume-core' ),
		'url'         => __( 'Lien', 'yume-core' ),
		'description' => __( 'Description (une ligne)', 'yume-core' ),
		'logo'        => __( 'Logo (ID ou adresse)', 'yume-core' ),
	);
	printf(
		'<fieldset class="yume-partenaires"%2$s><legend class="screen-reader-text">%1$s</legend>',
		esc_html( $libelle ),
		'' !== $aide ? ' aria-describedby="' . esc_attr( $aide ) . '"' : ''
	);
	echo '<table class="widefat striped yume-partenaires__table"><thead><tr><th scope="col" class="yume-partenaires__num">#</th>';
	foreach ( $colonnes as $titre ) {
		echo '<th scope="col">' . esc_html( $titre ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	for ( $i = 0; $i < MAX_PARTENAIRES; $i++ ) {
		$ligne = $lignes[ $i ] ?? array();
		echo '<tr><th scope="row" class="yume-partenaires__num">' . esc_html( (string) ( $i + 1 ) ) . '</th>';
		foreach ( $colonnes as $cle => $titre ) {
			$champ_id = $id . '-' . $i . '-' . $cle;
			$brute    = $ligne[ $cle ] ?? '';
			$brute    = is_scalar( $brute ) ? (string) $brute : '';
			echo '<td>';
			printf(
				'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%4$s" value="%5$s" class="%6$s"%7$s />',
				esc_attr( $champ_id ),
				/* translators: 1: colonne, 2: numéro de ligne */
				esc_html( sprintf( __( '%1$s du partenaire %2$d', 'yume-core' ), $titre, $i + 1 ) ),
				'url' === $cle ? 'url' : 'text',
				esc_attr( $nom . '[' . $i . '][' . $cle . ']' ),
				esc_attr( $brute ),
				'logo' === $cle ? 'regular-text code' : 'regular-text',
				' placeholder="' . esc_attr( 'url' === $cle ? 'https://…' : $titre ) . '"'
			);
			if ( 'logo' === $cle && ctype_digit( $brute ) && (int) $brute > 0 ) {
				$vignette = wp_get_attachment_image( (int) $brute, 'thumbnail', false, array( 'class' => 'yume-partenaires__vignette' ) );
				if ( $vignette ) {
					echo ' ' . $vignette; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML produit par WordPress.
				}
			}
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></fieldset>';
	echo '<style>.yume-partenaires__table{table-layout:fixed}.form-table .yume-partenaires__table th,.form-table .yume-partenaires__table td{width:auto;padding:8px;vertical-align:top}.form-table .yume-partenaires__table .yume-partenaires__num{width:2em}.yume-partenaires__table input{width:100%;max-width:none}.yume-partenaires__vignette{display:block;max-width:96px;max-height:40px;width:auto;height:auto;margin-top:4px;object-fit:contain}@media (max-width:782px){.yume-partenaires__table{table-layout:auto}.yume-partenaires__table thead{display:none}.form-table .yume-partenaires__table tr,.form-table .yume-partenaires__table td,.form-table .yume-partenaires__table th{display:block;width:auto}}</style>';
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
