<?php
/**
 * Module « lecteurs » : comptes lecteurs (favoris, notes, alertes, page compte, inscription et
 * connexion en façade, RGPD), API §7 (yume_get_progression, yume_is_favori, yume_get_abonnes,
 * yume_get_note), routes REST /moi/* (§12), tables favoris et notes (§13), e-mails d'alerte et
 * blocs yume/oeuvre-actions, yume/resume-reading, yume/account et yume/auth-links (§10).
 *
 * Au chargement, les fichiers ne font qu'accrocher des hooks (docs/06-contrat-technique.md §0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Social;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/rgpd.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/comptes.php';
require_once __DIR__ . '/formulaires.php';
require_once __DIR__ . '/alertes.php';
require_once __DIR__ . '/desabonnement.php';
require_once __DIR__ . '/blocs.php';
require_once __DIR__ . '/compte.php';
require_once __DIR__ . '/lignes-tomes.php';
