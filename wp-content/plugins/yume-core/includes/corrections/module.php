<?php
/**
 * Module « corrections » (lot L1 du chantier « lecteurs », 2.5.0) : signalement des coquilles par
 * les lecteurs et file des corrections de l'espace équipe.
 *
 * - Lecteur (lecteur.php, assets/coquilles.js) : sur un chapitre texte publié, la sélection d'un
 *   passage fait apparaître un bouton flottant « Signaler une coquille » ; un lien après la
 *   navigation de fin de chapitre ouvre la même fenêtre au clavier. Envoi par
 *   POST /yume/v1/chapitres/{id}/coquilles (rest.php). Sans JavaScript, rien n'est affiché.
 * - Équipe (equipe.php) : vue ?vue=corrections (menu « Catalogue »), signalements groupés par
 *   chapitre, Corrigé / Rejeté / Rouvrir réservés à edit_post sur le chapitre.
 * - Notifications (notifications.php) : Discord canal équipe (étouffé) et cloche du lecteur à
 *   « corrigé ».
 * - RGPD (rgpd.php) : export, anonymisation à la suppression du compte, purge des signalements
 *   traités (install.php : table, tâche quotidienne).
 *
 * Contrat : docs/06-contrat-technique.md §8 quater. Les fonctions des modules social, reader,
 * planning et mangadex ne sont appelées que dans des callbacks, gardées par function_exists ; au
 * chargement, les fichiers du module ne font qu'accrocher des hooks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Corrections;

defined( 'ABSPATH' ) || exit;

// Socle : constantes, table, règles d'un signalement, réglages.
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/rest.php';
// Lecteur : script, fenêtre de signalement, lien de fin de chapitre.
require_once __DIR__ . '/lecteur.php';
// Espace équipe : vue ?vue=corrections et actions.
require_once __DIR__ . '/equipe.php';
// Discord équipe et notification du lecteur.
require_once __DIR__ . '/notifications.php';
// Export, anonymisation, effaceur.
require_once __DIR__ . '/rgpd.php';
