<?php
/**
 * Module « publication » : du DOCX (ou de l'EPUB) du tome à sa sortie sur le site
 * (contrat §8, §10 yume/publish-form, §12).
 *
 * - Service       : crée ou met à jour le tome (réutilisé s'il existe déjà, par exemple le
 *                   brouillon du planning), ses chapitres en place, ses illustrations,
 *                   l'article d'annonce ; publie ou programme la sortie ;
 * - Fichiers      : contrôle des fichiers téléversés (type réel, extension, taille) ;
 * - Medias        : versement des images dans la médiathèque (≤ 1600 px, WebP) ;
 * - Annonce       : article d'annonce en brouillon (catégorie « Sorties ») ;
 * - Rest          : POST /yume/v1/publications/analyse, /publications, /publications/{id}/publier ;
 * - Formulaire    : bloc yume/publish-form, envoi sans JavaScript (admin-post.php), menu d'administration.
 *
 * Au chargement, le module n'appelle aucun autre module : il accroche ses hooks.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-fichiers.php';
require_once __DIR__ . '/class-medias.php';
require_once __DIR__ . '/class-annonce.php';
require_once __DIR__ . '/class-service.php';
require_once __DIR__ . '/class-rest.php';
require_once __DIR__ . '/class-formulaire.php';

// Le convertisseur (module import) est chargé à la demande s'il ne l'a pas été.
if ( ! class_exists( '\Yume\Core\Import\Docx_Converter' ) && is_readable( dirname( __DIR__ ) . '/import/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/import/autoload.php';
}

add_action( 'rest_api_init', array( Rest::class, 'enregistrer_routes' ) );
add_action( 'init', array( Formulaire::class, 'enregistrer_bloc' ) );
add_action( 'admin_menu', array( Formulaire::class, 'menu' ), 20 );
add_action( 'admin_post_yume_publication', array( Formulaire::class, 'traiter' ) );
add_action( 'admin_post_nopriv_yume_publication', array( Formulaire::class, 'traiter_anonyme' ) );
add_action( Service::HOOK_GROUPE, array( Service::class, 'sortie_groupee_programmee' ), 10, 2 );
