=== Yume ===
Contributors: yumenovel
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Thème bloc de Yume Novel : direction « Crépuscule sakura », lecture en ligne, bibliothèque et planning.

== Description ==

Thème bloc (édition complète du site) de yumenovel.fr, fan-traductions françaises de light novels.

* Thème Nuit par défaut, thèmes Papier et Sépia (html[data-yn-theme]) appliqués avant le premier
  rendu à partir de localStorage['yn.theme'] ; bouton de bascule dans l'en-tête.
* Typographie de lecture pilotée par les variables --yn-size, --yn-lh, --yn-font, --yn-width et
  --yn-bg-alpha (réglages du lecteur de l'extension Yume Core).
* Classes utilitaires partagées avec l'extension : .yn-btn, .yn-chip, .yn-card, .yn-cover, .yn-label,
  .yn-muted, .yn-grid-covers, .yn-visually-hidden, .yn-bar.
* Modèles : accueil, actualités, article, page, page large, archive, recherche, 404, bibliothèque,
  fiche d'œuvre, tome, chapitre (lecteur).
* Les blocs yume/* (bannière, dernières sorties, planning, lecteur…) sont fournis par l'extension
  Yume Core ; sans elle, le thème reste utilisable (liens de repli pour la bibliothèque et la connexion).

Référence technique : docs/06-contrat-technique.md (§14 et §15) du dépôt GNAlexandre/Yume-WordPress.

== Polices ==

Polices auto-hébergées (sous-ensembles latin et latin étendu, WOFF2), distribuées sous licence
SIL Open Font License 1.1 (texte complet dans assets/fonts/<famille>/OFL.txt) :

* Outfit — Copyright 2021 The Outfit Project Authors
* Nunito Sans — Copyright 2016 The Nunito Sans Project Authors
* IBM Plex Mono — Copyright 2017 IBM Corp.
* Literata — Copyright 2017 The Literata Project Authors

Fichiers issus des paquets @fontsource (version 5.3.0).

== Changelog ==

= 2.0.1 =
* Première version du thème bloc Yume v2.
