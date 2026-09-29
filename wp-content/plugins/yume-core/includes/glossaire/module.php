<?php
/**
 * Module « glossaire » (audit PAGE-05) : glossaire par œuvre importé depuis Yume-Trad
 * (fichier YAML), page publique /oeuvres/{oeuvre}/glossaire/ (bloc yume/glossaire), vue
 * « Glossaires » de l'espace équipe (téléversement, vérification, historique, restauration) et
 * envoi ponctuel par l'application (POST /yume/v1/oeuvres/{oeuvre}/glossaire).
 *
 * Contrat : docs/06-contrat-technique.md ; format et connecteur : docs/glossaire.md.
 * Au chargement, les fichiers ne font qu'accrocher des hooks (§0).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-lecteur-yaml.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/install.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/public.php';
require_once __DIR__ . '/equipe.php';
