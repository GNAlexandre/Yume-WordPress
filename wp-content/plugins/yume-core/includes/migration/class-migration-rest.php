<?php
/**
 * Routes REST de la migration (espace yume/v1, capacité manage_options) :
 *
 *   GET  /yume/v1/migration            état (statut, étape, progression, messages)
 *   POST /yume/v1/migration/executer   démarre (confirmation « MIGRER ») ou reprend un lot
 *   POST /yume/v1/migration/annuler    démarre (confirmation « ANNULER ») ou reprend un lot ;
 *                                      conserver (booléen) : annuler malgré l'utilisation du site
 *                                      depuis la migration (œuvres utilisées conservées) ;
 *                                      reconstruire (booléen) : annuler malgré un journal perdu
 *                                      (erreur 412 sans ces confirmations)
 *
 * Paramètre commun des POST : ignorer (booléen) pour sauter l'élément en erreur.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * API REST de la migration.
 */
final class Migration_Rest {

	/** Espace de noms. */
	public const ESPACE = 'yume/v1';

	/**
	 * Enregistre les routes.
	 */
	public static function routes(): void {
		$permission = array( self::class, 'permission' );
		register_rest_route(
			self::ESPACE,
			'/migration',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'etat' ),
				'permission_callback' => $permission,
			)
		);
		$args = array(
			'confirmation' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'ignorer'      => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
		register_rest_route(
			self::ESPACE,
			'/migration/executer',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'executer' ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
		register_rest_route(
			self::ESPACE,
			'/migration/annuler',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'annuler' ),
				'permission_callback' => $permission,
				'args'                => array_merge(
					$args,
					array(
						'conserver'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'reconstruire' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
	}

	/**
	 * Seuls les administrateurs migrent le site.
	 */
	public static function permission(): bool {
		return current_user_can( Migration_Admin::CAPACITE );
	}

	/**
	 * GET /migration.
	 */
	public static function etat(): \WP_REST_Response {
		return rest_ensure_response( Migration_Runner::resume( Migration_State::etat() ) );
	}

	/**
	 * Erreur REST depuis une exception.
	 *
	 * @param \RuntimeException $e Exception.
	 */
	private static function erreur( \RuntimeException $e ): \WP_Error {
		$statut = in_array( (int) $e->getCode(), array( 409, 412 ), true ) ? (int) $e->getCode() : 400;
		return new \WP_Error( 'yume_migration', $e->getMessage(), array( 'status' => $statut ) );
	}

	/**
	 * POST /migration/executer.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function executer( \WP_REST_Request $request ) {
		$etat = Migration_State::etat();
		try {
			if ( in_array( $etat['statut'], array( 'non_migre', 'annule' ), true ) ) {
				if ( Migration_Admin::CONFIRMATION !== (string) $request->get_param( 'confirmation' ) ) {
					return new \WP_Error( 'yume_migration_confirmation', __( 'Tapez MIGRER (en majuscules) pour confirmer l’exécution.', 'yume-core' ), array( 'status' => 400 ) );
				}
				Migration_Runner::demarrer_execution();
			} elseif ( 'en_cours' !== $etat['statut'] ) {
				return new \WP_Error( 'yume_migration_etat', __( 'Aucune migration à exécuter : elle est déjà faite ou une annulation est en cours.', 'yume-core' ), array( 'status' => 409 ) );
			}
			$etat = Migration_Runner::lot( array( 'ignorer' => (bool) $request->get_param( 'ignorer' ) ) );
		} catch ( \RuntimeException $e ) {
			return self::erreur( $e );
		}
		return rest_ensure_response( Migration_Runner::resume( $etat ) );
	}

	/**
	 * POST /migration/annuler.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function annuler( \WP_REST_Request $request ) {
		$etat = Migration_State::etat();
		try {
			if ( in_array( $etat['statut'], array( 'migre', 'en_cours' ), true ) ) {
				if ( 'ANNULER' !== (string) $request->get_param( 'confirmation' ) ) {
					return new \WP_Error( 'yume_migration_confirmation', __( 'Confirmez l’annulation de la migration.', 'yume-core' ), array( 'status' => 400 ) );
				}
				Migration_Runner::demarrer_annulation(
					array(
						'conserver'    => (bool) $request->get_param( 'conserver' ),
						'reconstruire' => (bool) $request->get_param( 'reconstruire' ),
					)
				);
			} elseif ( 'annulation' !== $etat['statut'] ) {
				return new \WP_Error( 'yume_migration_etat', __( 'Aucune migration à annuler.', 'yume-core' ), array( 'status' => 409 ) );
			}
			$etat = Migration_Runner::lot( array( 'ignorer' => (bool) $request->get_param( 'ignorer' ) ) );
		} catch ( \RuntimeException $e ) {
			return self::erreur( $e );
		}
		return rest_ensure_response( Migration_Runner::resume( $etat ) );
	}
}
