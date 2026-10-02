# 01 · Audit de l'existant — yumenovel.fr

Analyse réalisée en lecture seule le 24 septembre 2026 via le connecteur WordPress.com
(site `238001312`, domaine principal `yumenovel.fr`, alias `yumenovel.wordpress.com`).

## 1. Plateforme et abonnement

| Élément | Valeur constatée | Conséquence |
| --- | --- | --- |
| Type d'hébergement | **WordPress.com « Simple »** (mutualisé, sans accès fichiers) | Aucun plugin, aucun code PHP, aucun accès SFTP/SSH. Toute la logique métier demandée (planning, formulaire, lecteur, comptes) est impossible sur cette plateforme. |
| Quota de stockage | 6 Go (344 Mo utilisés) | Le quota de 6 Go correspond au plan **Personal**. Les plans Premium (13 Go) et Business (50 Go) ont d'autres quotas. |
| Gestion des plugins | Refusée : `paid_simple_site_plugins_unavailable` | WordPress.com propose un transfert vers l'hébergement « Atomic » (managé, avec plugins). Le transfert est automatique et sans interruption. |
| Thème | Twenty Twenty-Four (thème bloc / FSE), styles globaux non personnalisés | Bon socle technique, mais identité visuelle absente : couleurs et polices par défaut du thème. |
| Langue / fuseau | `fr`, Europe/Paris | OK. |
| Inscriptions | **Ouvertes** (`users_can_register = true`, rôle par défaut `subscriber`) | À conserver pour les comptes lecteurs, mais à sécuriser (anti-spam, vérification e-mail). |
| Utilisateurs | 4 comptes, **tous administrateurs** (Mael7523m_, Angeloids, calumini, Rai tei) | Aucun rôle intermédiaire : chaque membre peut tout casser. Les rôles « traducteur / relecteur / éditeur » sont à créer. |
| Audience (90 j) | 3 657 vues, 1 329 visiteurs, ~1 200 vues/mois, 5 abonnés e-mail, 17 commentaires | Site de niche actif, en croissance depuis le nom de domaine (juin 2026). |
| Santé | `healthy`, 1 plugin actif (Jetpack intégré) | — |

## 2. Inventaire du contenu

| Type | Quantité | Détail |
| --- | --- | --- |
| Pages publiées | **96** (+ 2 brouillons) | Toutes à plat (`parent = 0`, `menu_order = 0`) : aucune hiérarchie. |
| Articles | **180** | Catégories : *Yume News* (119), *Actualités* (63), *Non classé* (4). **Aucun tag.** |
| Médias | **438** | Uniquement des images (JPEG 67 %, PNG, WebP). **Aucun PDF/EPUB hébergé** : les fichiers sont sur ClicTune (et anciennement Mega). |
| Types de contenu personnalisés | Aucun | Tout est « page » ou « article ». |
| Menus | 1 navigation principale + 3 navigations de pied de page | Liens pointant encore vers `yumenovel.wordpress.com` au lieu de `yumenovel.fr`. |

### 2.1 Cartographie des 96 pages

| Famille | Pages | Remarques |
| --- | --- | --- |
| Hubs de navigation | Yume LN, Yume Manga, Yume News, Actualités, Nos réseaux | Construites à la main en colonnes d'images (vignettes 590×487) classées « Terminée / En cours / Licenciée-Abandonnée ». Chaque nouvelle œuvre = édition manuelle du hub. |
| Fiches œuvres (~15) | Grimgar (LN), Secrets of the Silent Witch (WN), SukaSuka, SukaMoka, Raven of the Inner Palace, Survival in Another World…, Witches Can't Be Collared, Miss Medic's Diary at War, Chitose…, Mikadono Sanshimai, Otonari no Tenshi-sama, Roshidere (LN + Manga), Gimai Seikatsu, Agents of the Four Seasons, Xba | Modèle répétitif : bloc « Noms / Scénario / Illustrations / Nombre de volumes / Éditeur / Traduction », synopsis, puis un bloc *media-text* par tome avec deux boutons **PDF** / **EPUB** (liens ClicTune). Tout est saisi à la main. |
| Pages « ARC » (8) | ARC 1 → ARC 8 – Silent Witch | Liste des chapitres en liens texte, saisie manuelle, avec mention « PDF » en bas. |
| Pages chapitres (63) | Silent Witch T.1 (7) · T.2 (10) · T.3 (8) · T.4 (6) · T.5 (10) · T.6 (14) · T.7 (8) | Seule œuvre lisible en ligne. Chaque page = titre du chapitre, crédits « Traduction – X / Relecture – Y », paragraphes, séparateurs `<hr>`, illustration, puis navigation précédent/suivant par **images cliquables**. Aucune sauvegarde de progression, aucun réglage de lecture. |
| Institutionnel | L'équipe, La Yume Novel, Yume FAQ, Contactez-nous | Contenu correct, mise en forme hétérogène (blocs colorés `#efe7fb` sur la page équipe uniquement). |

### 2.2 Processus de publication actuel (par sortie)

Une sortie de tome demande aujourd'hui **3 à 4 éditions manuelles** :

1. Éditer la fiche œuvre : ajouter un bloc *media-text* + couverture + 2 boutons ClicTune.
2. (WN) Créer la page chapitre, coller le texte, ajouter l'illustration et les images « chapitre précédent/suivant ».
3. (WN) Éditer la page ARC pour ajouter le lien du chapitre.
4. Rédiger l'article « Tome N de X disponible ! » avec les mêmes boutons.

Chaque étape est une source d'erreur constatée : slug incohérent (`secrets-of-the-silent-witch-t-3-chapitre-1-2` = T.4 chapitre 1), fiche WN sous le slug `…-ln`, articles en « Non classé », liens internes vers `.wordpress.com`.

## 3. Mise en page et thème

- Page d'accueil = modèle *Blog Home* modifié : bloc « Nos Sorties » (1 article en vedette + 2), « Nos Partenaires » (4 logos), bouton Ko-fi rose `#f500af`, pied de page.
- En-tête : logo + titre + navigation + recherche (placeholder « SukaSuka… ») + **bannière image pleine largeur** sur chaque page.
- Modèles : `single` et `page` du thème, plus `single-with-sidebar` / `page-with-sidebar` (sidebar « Venez discuter » Discord).
- Textes anglais résiduels dans les modèles : « Comments », « by », « in », « Previous / Next », « Page Not Found », format de date `M j, Y`.
- Pied de page : « Yume Novel ©2025 » (à mettre à jour), Discord + Twitter (`twitter.com/YumeNovel` alors que la FAQ indique `x.com/Roshidere_FR`).
- Aucune personnalisation des styles globaux : palette beige/rouille de Twenty Twenty-Four, polices Inter / Cardo.
- Aucun mode sombre, alors que la lecture de light novels se fait majoritairement le soir sur mobile (cf. capture des « Paramètres de lecture » souhaités : sombre, taille, interligne, opacité, police).

## 4. Analyse du document Word de référence (Grimgar T.7)

Fichier `JG__YumeGrimgar_of_Fantasy_and_Ash_T.7.docx` (auteur du document : Alexandre TOURNEL, 839 révisions, A4, marges 2,5 cm, 3 526 paragraphes, 19 chapitres + postface, 16 images).

| Style Word | Occurrences | Rendu | Équivalent HTML cible |
| --- | --- | --- | --- |
| `Normal` | ~1 700 | Lucida Sans Unicode 12 pt, **justifié**, interligne 1,16, espace après 8 pt | `<p>` |
| `Titre1` (« Chapitre N ») | 20 | Lucida Sans Unicode **gras 30 pt**, centré, `keepLines` | Frontière de chapitre → `<h1>` |
| `Titre2` (sous-titre du chapitre, « PostFace ») | 20 | Gras 16 pt, centré, **filet inférieur** | `<h2 class="chapitre-sous-titre">` |
| `Paragraphedeliste` (liste à puce « — ») | 1 453 | Dialogue précédé d'un **tiret cadratin**, retrait 1349 twips / suspendu 357 | `<p class="dialogue">— …</p>` |
| `Pensée` (basé sur liste, sans puce) | 307 | *Italique*, retrait gauche 1349 | `<p class="pensee">` (italique, retrait) |
| Sauts de page | 2 | Avant certains chapitres | Ignorés (un chapitre = une page web) |
| Images JPG/PNG | 10 | Couverture, illustrations couleur (pages liminaires), illustrations N&B pleine page dans les chapitres | `<figure class="illustration">` ; les images liminaires vont dans la galerie du tome |
| Images EMF | 6 (~11 Mo chacune) | Ornements vectoriels Word | **Non convertibles** en l'état → ignorées ou remplacées par le cadre CSS du lecteur |
| Pieds de page | 30 sections, texte vide | Pagination Word | Ignorés |

Conclusion : la mise en page est **entièrement pilotée par 4 styles de paragraphe**, ce qui rend la conversion automatique DOCX → chapitres HTML fiable (voir `04-import-docx-epub-lecteur.md`).

## 5. Synthèse des points bloquants

1. **Plateforme** : l'hébergement « Simple » interdit tout développement. Changement de plan obligatoire (voir plan, §2). *Note (octobre 2026) : le plan actuel a suffi ; la première extension installée a déclenché le passage à l'hébergement Atomic, sans changement de plan.*
2. **Modèle de contenu** : tout est page/article, rien n'est structuré (œuvre, tome, chapitre, équipe, planning). Impossible d'automatiser sans types de contenu dédiés.
3. **Processus** : chaque sortie = plusieurs éditions manuelles → erreurs, incohérences, lenteur.
4. **Lecture** : aucune expérience de lecture (progression, réglages, mode sombre, navigation).
5. **Droits** : 4 administrateurs, pas de rôles d'équipe.
6. **Identité** : thème par défaut, mélange FR/EN, aucune direction visuelle.
