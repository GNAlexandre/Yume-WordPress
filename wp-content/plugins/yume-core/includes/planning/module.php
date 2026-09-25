<?php
/**
 * Module « planning » : planning public des traductions, espace équipe, rappels automatiques,
 * file d'e-mails et annonces Discord (contrat §7, §8, §10, §12, §13 ; docs/02 F1 et F2).
 *
 * Au chargement, les fichiers ne font qu'accrocher des hooks (docs/06-contrat-technique.md §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/planning.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/evenements.php';
require_once __DIR__ . '/rappels.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/blocs.php';
require_once __DIR__ . '/equipe.php';
