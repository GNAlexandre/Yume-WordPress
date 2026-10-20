<?php
/**
 * Réglages de la connexion Discord (Yume → Réglages, section « Connexion Discord ») et test
 * Santé du site yume_discord (affiché seulement quand la connexion est activée ou partiellement
 * configurée).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Discord;

defined( 'ABSPATH' ) || exit;

/** Identifiant de la section de réglages. */
const SECTION_REGLAGES = 'discord_connexion';

/**
 * Section « Connexion Discord ».
 *
 * @param array $sections Sections (id => titre).
 * @return array
 */
function sections_reglages( $sections ) {
	$sections                     = is_array( $sections ) ? $sections : array();
	$sections[ SECTION_REGLAGES ] = __( 'Connexion Discord', 'yume-core' );
	return $sections;
}
add_filter( 'yume_reglages_sections', __NAMESPACE__ . '\\sections_reglages' );

/**
 * Message d'un champ verrouillé par une constante de wp-config.php ('' sinon).
 *
 * @param string $constante Nom de la constante.
 */
function verrou( string $constante ): string {
	/* translators: %s : nom de la constante. */
	return '' !== constante( $constante ) ? sprintf( __( 'Défini par la constante %s (wp-config.php).', 'yume-core' ), $constante ) : '';
}

/**
 * Champs de la section.
 *
 * @param array $champs Champs.
 * @return array
 */
function champs_reglages( $champs ) {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'discord_connexion',
		'label'       => __( 'Connexion avec Discord', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => SECTION_REGLAGES,
		'default'     => false,
		'description' => __( 'Proposer « Se connecter avec Discord » sur la page de connexion et la rubrique « Connexion Discord » dans Mon compte (Client ID et Client Secret requis).', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'discord_inscription',
		'label'       => __( 'Création de compte avec Discord', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => SECTION_REGLAGES,
		'default'     => true,
		'description' => __( 'Créer un compte lecteur au premier passage d’un compte Discord inconnu (adresse e-mail vérifiée par Discord, et seulement si les inscriptions sont ouvertes : Réglages → Général).', 'yume-core' ),
	);
	$champs[] = array(
		'key'         => 'discord_client_id',
		'label'       => __( 'Client ID Discord', 'yume-core' ),
		'type'        => 'text',
		'section'     => SECTION_REGLAGES,
		'default'     => '',
		'placeholder' => '123456789012345678',
		'verrouille'  => verrou( 'YUME_DISCORD_CLIENT_ID' ),
		'sanitize'    => static function ( $valeur ): string {
			return is_scalar( $valeur ) ? (string) preg_replace( '/\D+/', '', (string) $valeur ) : '';
		},
		'description' => sprintf(
			/* translators: %s : adresse de retour (Redirect URI). */
			__( 'Application du portail Discord (discord.com/developers/applications), onglet OAuth2. Adresse de redirection (Redirects) à déclarer exactement : %s', 'yume-core' ),
			url_retour()
		),
	);
	$champs[] = array(
		'key'         => 'discord_client_secret',
		'label'       => __( 'Client Secret Discord', 'yume-core' ),
		'type'        => 'secret',
		'section'     => SECTION_REGLAGES,
		'default'     => '',
		'verrouille'  => verrou( 'YUME_DISCORD_CLIENT_SECRET' ),
		'description' => __( 'Clé secrète de l’onglet OAuth2 du portail Discord (« Réinitialiser la clé secrète »). Collez-la ici puis enregistrez : elle n’est jamais réaffichée ; laissez le champ vide pour garder la valeur enregistrée. Une constante YUME_DISCORD_CLIENT_SECRET dans wp-config.php, si ce fichier est modifiable, remplace ce réglage.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages' );

/**
 * Résultat d'un test Santé du site.
 *
 * @param string $statut      good, recommended ou critical.
 * @param string $libelle     Libellé.
 * @param string $description Description (texte).
 */
function resultat_sante( string $statut, string $libelle, string $description ): array {
	return array(
		'label'       => $libelle,
		'status'      => $statut,
		'badge'       => array(
			'label' => __( 'Yume', 'yume-core' ),
			'color' => 'good' === $statut ? 'blue' : 'orange',
		),
		'description' => '<p>' . esc_html( $description ) . '</p>',
		'actions'     => '',
		'test'        => 'yume_discord',
	);
}

/**
 * Test yume_discord : connexion activée mais incomplète. Le secret enregistré dans les réglages est
 * le cas normal sur WordPress.com (wp-config.php n'y est pas modifiable) : il n'est pas signalé.
 */
function test_sante(): array {
	$active = reglage_booleen( 'discord_connexion', false );
	if ( $active && ( '' === client_id() || '' === client_secret() ) ) {
		return resultat_sante(
			'recommended',
			__( 'Connexion Discord activée mais incomplète', 'yume-core' ),
			__( 'La connexion avec Discord est activée sans Client ID ou sans Client Secret : aucun bouton n’est affiché. Renseignez-les dans Yume → Réglages (Connexion Discord) ou dans la vue Réglages de l’espace équipe.', 'yume-core' )
		);
	}
	return $active
		? resultat_sante( 'good', __( 'Connexion Discord configurée', 'yume-core' ), __( 'Les lecteurs peuvent se connecter avec Discord.', 'yume-core' ) )
		: resultat_sante( 'good', __( 'Connexion Discord désactivée', 'yume-core' ), __( 'La connexion avec Discord est configurée mais désactivée (Yume → Réglages).', 'yume-core' ) );
}

/**
 * Déclare le test yume_discord, seulement si la connexion est activée ou partiellement
 * configurée (rien de nouveau sur un site qui ne l'utilise pas).
 *
 * @param array $tests Tests.
 * @return array
 */
function tests_sante( $tests ) {
	$tests = is_array( $tests ) ? $tests : array();
	if ( reglage_booleen( 'discord_connexion', false ) || '' !== client_id() || '' !== client_secret() ) {
		$tests['direct']['yume_discord'] = array(
			'label' => __( 'Connexion Discord', 'yume-core' ),
			'test'  => __NAMESPACE__ . '\\test_sante',
		);
	}
	return $tests;
}
add_filter( 'site_status_tests', __NAMESPACE__ . '\\tests_sante', 20 );
