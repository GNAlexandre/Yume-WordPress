# Guide de l'équipe — Yume Novel v2

Ce guide s'adresse aux traducteurs, relecteurs, graphistes, éditeurs et gérants de Yume Novel. Il
explique comment se connecter, tenir le planning à jour, publier un tome et s'occuper des lecteurs,
**sans passer par le code ni par les réglages techniques de WordPress**.

> Les écrans de la v2 sont en cours de construction : les libellés peuvent encore bouger un peu.
> Ce guide suit le fonctionnement arrêté par l'équipe (maquettes validées et contrat technique).

## 1. Qui peut faire quoi

| Rôle | Pour qui | Peut |
| --- | --- | --- |
| **Lecteur** | Tout compte créé par un lecteur | Lire, commenter, gérer ses favoris, notes, alertes et réglages de lecture |
| **Traducteur**, **Relecteur**, **Graphiste** | Membres de l'équipe | Accéder à l'espace équipe, mettre à jour le planning **des tomes dont ils sont responsables**, envoyer des images |
| **Éditeur Yume** | Personnes qui publient | Tout ce qui précède, plus : publier un tome, mettre à jour le planning de **tous** les tomes, modifier œuvres, tomes et chapitres, écrire des actualités, **modérer les commentaires** |
| **Gérant** | Responsables de Yume | Tout ce qui précède, plus : gérer les membres de l'équipe, régler le site (*Yume → Réglages*), modifier les articles des autres |
| Administrateur | 1 ou 2 comptes techniques | Tout (installation, extensions) |

Un rôle se demande à un gérant. Chacun n'a que les droits dont il a besoin : c'est normal de ne pas
voir tous les menus.

## 2. Se connecter

- **Lecteurs et équipe** (traducteurs, relecteurs, graphistes, éditeurs, gérants) : cliquer sur
  **Connexion** en haut à droite du site (page `/connexion/`), saisir son pseudo ou son e-mail et
  son mot de passe ; cocher « Se souvenir de moi » sur un appareil personnel. On arrive sur la page
  d'où l'on venait (ou sur **Mon compte**, ou l'espace équipe pour un traducteur, un relecteur ou
  un graphiste). En cas d'erreur, le message « Identifiant ou mot de passe incorrect. » s'affiche
  au-dessus du formulaire, l'identifiant reste rempli : on corrige et on recommence, sans passer
  par une page WordPress. Après 5 erreurs en 15 minutes (même compte ou même connexion), le
  formulaire est bloqué 15 minutes (« Trop de tentatives, réessayez dans N minutes. ») : attendre,
  ou utiliser « Mot de passe oublié ? », qui envoie un lien de réinitialisation par e-mail.
- **Administrateurs** : ils se connectent par la **page de connexion WordPress** (connexion
  WordPress.com et validation en deux étapes), via le petit lien **Connexion administrateur** sous
  le formulaire de `/connexion/`. Le formulaire de `/connexion/` refuse un compte administrateur,
  même avec le bon mot de passe, et affiche ce lien. La page WordPress sert aussi aux personnes
  invitées par l'administrateur à créer leur compte.
- Une fois connecté, le menu du compte affiche **Mon compte** et, pour les membres de l'équipe,
  **Espace équipe** (page `/equipe/`). **Se déconnecter** est à côté, dans le menu du site.
- Traducteurs, relecteurs et graphistes travaillent uniquement dans l'espace équipe : s'ils ouvrent
  l'administration WordPress (`/wp-admin/`), ils y sont renvoyés automatiquement (seule la page
  **Profil** reste accessible).
- Sécurité : un mot de passe propre à Yume (gestionnaire de mots de passe recommandé) ; activez la
  **validation en deux étapes** proposée par WordPress.com / Jetpack si vous publiez. Ne partagez
  jamais un compte : chaque action est enregistrée au nom de son auteur dans le journal.

## 3. L'espace équipe (`/equipe/`)

Le tableau de bord rassemble, sans passer par l'administration WordPress :

- **Mes tâches** : les tomes, arcs ou chapitres dont vous êtes responsable (traduction, relecture ou
  édition), avec leur étape, leur avancement et leur date cible ;
- **Mes retards** : ce qui a dépassé sa date cible ;
- la **prochaine sortie** de l'équipe et les **rappels** envoyés ce mois-ci ;
- le **journal de l'équipe** : les dernières mises à jour (qui, quoi, quand) ;
- le menu, en quatre rubriques repliables (celle de la page affichée est ouverte) : **Catalogue** (Œuvres, Tous les tomes, Publier un tome, Lecture à compléter, Glossaires), **Planning** (Planning complet, Journal), **Équipe** (Membres et rôles, Commentaires) et **Site** (Indicateurs, Santé du site, Réglages) — chacun n'y voit que ce que son rôle permet ; pour les gérants
  **Membres et rôles** et **Réglages** ;
- **Se déconnecter**, sous votre nom dans le menu de l'espace équipe.

Sous chaque tâche (et chaque tome de *Tomes en préparation*, la liste du planning à venir), des raccourcis mènent directement au bon
écran : **Publier ce tome** (éditeurs : le formulaire de publication s'ouvre déjà rempli pour ce
tome, inutile de ressaisir œuvre, nature et numéro), **Modifier dans l'administration**, **Voir la
fiche** (tome publié), **Historique** (le journal de ce tome) et **Gérer dans le planning complet**.

### Œuvres, nouvelle œuvre et genres (`/equipe/?vue=oeuvres`, éditeurs et gérants)

Toutes les œuvres du catalogue, **brouillons compris**, filtrables par statut ou par titre. Pour
chaque œuvre : **Voir** (publiée) ou **Publier** (brouillon), **Modifier**, **Ajouter un tome au
planning** (le formulaire du tableau de bord s'ouvre avec l'œuvre déjà choisie) et **Publier un
tome**.

**Nouvelle œuvre** (bouton en haut de la vue, ou lien « L'œuvre n'existe pas encore ? » sous
« Ajouter un tome au planning ») et **Modifier** utilisent le même formulaire : titre, type, statut
de la traduction, auteur, illustrateur, éditeur VO, titres alternatifs (un par ligne), genres
(cases à cocher, ou « Ajouter des genres absents de la liste »), synopsis (une ligne vide sépare
deux paragraphes ; `<strong>gras</strong>` et `<em>italique</em>` sont conservés) et couverture
(JPG, PNG ou WebP). La partie repliable **Fiche détaillée** contient le statut et le nombre de tomes
de la VO, la source de la traduction, les jours de sortie, l'équipe affichée et les liens externes.

- **Créer en brouillon** : l'œuvre reste invisible du public ; on peut déjà lui ajouter des tomes au
  planning, puis la publier d'un clic depuis la liste.
- **Créer et publier** : la fiche est tout de suite visible dans la bibliothèque. Publier une œuvre
  n'envoie aucune annonce (seuls les tomes sont annoncés).
- **Modifier** ne change pas le statut (publiée ou brouillon). Le synopsis n'est réécrit que si
  vous le modifiez : sinon sa mise en forme d'origine (listes, liens…) reste telle quelle. Pour une
  mise en forme avancée, un lien mène à l'éditeur WordPress.
- Un titre déjà pris par une autre œuvre (même en brouillon) est refusé, pour éviter les doublons.
- **Cadrage de la couverture** : les cartes de la bibliothèque montrent la couverture au format
  portrait (2:3). Si le personnage ou le titre est coupé, cliquez dans l'aperçu sur la partie à
  garder visible, ou réglez les curseurs horizontal et vertical, puis enregistrez. « Recentrer »
  revient au cadrage centré par défaut. Le réglage s'applique partout où cette couverture
  s'affiche (bibliothèque, fiches, compte, reprise de lecture).

**Genres** (en bas de la vue) : une trentaine de genres courants sont proposés d'office (Action,
Aventure, Isekai, Romance, Slice of life…). Ajoutez-en (plusieurs à la fois, séparés par des
virgules) ou supprimez-en ; un genre supprimé est retiré des œuvres qui l'avaient et n'est pas
recréé aux mises à jour.

### Planning complet (`/equipe/?vue=planning`)

L'entrée **Planning complet** du menu ouvre la gestion de **tout** le planning, sans passer par
l'administration WordPress (le planning public reste accessible par le bouton **Voir le planning
public**) :

- **tous les tomes** : en préparation, programmés, en attente, publiés (même anciens) ;
- **filtres** : œuvre, état (à l'heure, en retard, bloqué, publié), statut (brouillon, programmé,
  publié…) et responsable ; **Afficher tout le planning** retire les filtres ;
- **tri** : *En retard d'abord* met en tête les tomes en retard (le plus ancien retard d'abord),
  puis les tomes bloqués ;
- **Exporter en CSV** télécharge le planning affiché (avec vos filtres et votre tri, toutes les
  pages) : un fichier qui s'ouvre dans Excel ou LibreOffice (colonnes œuvre, tome, étape, statut,
  responsables, date cible, date programmée, dernière mise à jour, retard) ;
- chaque ligne se déplie : étape, avancement des trois étapes, responsables, date cible, blocage et
  note, puis **Enregistrer**. Si l'enregistrement est refusé (par exemple « Terminez d'abord l'étape
  Traduction (100 %) avant de passer à la Relecture »), le message s'affiche en rouge **dans la
  ligne** ; seuls les gérants et l'administrateur peuvent forcer une étape (le journal note alors
  « étape forcée ») ;
- les mêmes raccourcis que ci-dessus, plus **Retirer du planning** (éditeurs, gérants) : pour un
  tome ajouté par erreur, **brouillon sans chapitre publié** seulement. Il part à la corbeille (un
  administrateur peut le récupérer). Un tome publié, programmé ou avec des chapitres en ligne ne
  se retire pas ici : le message explique quoi faire dans l'administration ;
- **Mettre en pause** (éditeurs, gérants) : pour un tome volontairement arrêté (attente de la
  VO, équipe indisponible, tome migré jamais repris). Tant qu'il est en pause, il n'est **jamais
  en retard**, ne reçoit **aucun rappel** et sort des retards du récapitulatif ; un badge **En
  pause** le signale dans l'espace équipe (le public ne voit rien). **Reprendre** le remet dans les
  retards et les rappels (la reprise compte comme une mise à jour). Les deux actions sont notées au
  journal de l'équipe (« a mis en pause », « a repris »).

Traducteurs, relecteurs et graphistes y voient tout le planning, mais ne modifient que leurs propres
étapes des tomes dont ils sont responsables.

Depuis le planning public (`/planning/`), un membre connecté a un bouton **Modifier dans l'espace
équipe** en haut de page et un lien du même nom sous chaque tome, qui ouvre directement sa ligne.

### Journal (`/equipe/?vue=journal`)

**Journal** (menu) ou **Tout le journal** (sous le journal du tableau de bord) affiche toutes les
mises à jour, page par page, avec des filtres par œuvre et par tome ; cochez **Inclure les rappels
automatiques** pour voir aussi les rappels et signalements. Cliquer sur le nom d'un tome filtre le
journal sur ce tome.

### Réglages (`/equipe/?vue=reglages`, gérants et administrateurs)

L'entrée **Réglages** du menu affiche tous les réglages du site dans l'espace équipe, sans passer
par l'administration : *Site et réseaux*, *Planning et rappels*, *Annonces et notifications*,
*Partenaires* (et *Mises à jour* pour l'administrateur seulement ; voir la section 8 pour le
détail). Modifiez ce qu'il faut puis cliquez sur **Enregistrer les réglages** (un seul bouton en
bas de page) : un message en haut de la vue confirme l'enregistrement ou liste ce qui a été
refusé (par exemple un partenaire sans lien valide, ou un lien qui n'est pas un webhook Discord ;
l'ancienne valeur est alors conservée).

- **Images** (bannière du site, logos des partenaires) : saisissez l'**ID** de l'image ou son
  **adresse** (copiée depuis la médiathèque) ; un aperçu s'affiche une fois enregistrée. Le lien
  **Choisir dans la médiathèque** ouvre l'administration dans un nouvel onglet (pour la bannière :
  la page *Yume → Réglages* et son sélecteur d'images).
- **Ouvrir dans l'administration** (en haut à droite) ouvre la page *Yume → Réglages*, qui reste
  disponible et enregistre exactement les mêmes réglages.

### Mon profil public (facultatif)

Chaque membre de l'équipe peut, **s'il le souhaite**, avoir une page publique qui présente son
travail, à l'adresse `/contributeurs/{votre-pseudo}/`. Elle est **désactivée par défaut**.

- **Activer** : *Mon compte* → rubrique **Profil public** → cochez **Afficher mon profil public**,
  puis **Enregistrer le profil public**. Vous pouvez ajouter une courte présentation (300 caractères),
  votre pseudo Discord, votre compte X (`@pseudo`), votre site et le mois de votre arrivée dans
  l'équipe : tout est facultatif.
- **Ce qui est visible** : votre pseudo, votre rôle (Traducteur, Relecteur…), ces informations
  facultatives et la liste des **tomes publiés** dont vous êtes responsable d'une étape dans le
  planning (traduction, relecture, édition). **Jamais** votre identifiant de connexion, votre
  adresse e-mail ni les tomes en préparation. Votre profil apparaît aussi dans la liste
  `/contributeurs/` et sous la page « L'équipe ».
- **Retirer** : décochez la case et enregistrez : la page disparaît **immédiatement** (son adresse
  ne mène plus nulle part). Quitter l'équipe la retire aussi. Sur WordPress.com, l'ancienne page est
  aussitôt retirée du cache des pages quand l'hébergeur le permet (Batcache) ; sinon, un visiteur
  anonyme peut encore la voir **quelques minutes** (le temps que la copie en cache expire, 5 minutes
  en général).
- Si votre pseudo est identique à votre identifiant de connexion, le profil ne peut pas être activé
  (pour ne pas rendre cet identifiant public) : changez d'abord de pseudo dans **Profil et sécurité**.
  Changer de pseudo change aussi l'adresse du profil.

## 4. Mettre à jour son planning

Le planning public (`/planning/`) montre aux lecteurs où en est chaque tome. Il se met à jour
**uniquement** à partir de ce que l'équipe saisit : pensez-y à chaque avancée.

Dans **Mes tâches** (lien du menu de l'espace équipe, `/equipe/?vue=taches` : toutes vos tâches,
**en retard d'abord**, avec le nombre de retards en pastille ; la même liste figure aussi sur le
tableau de bord), pour chaque tome :

1. **Avancement** : faites glisser le curseur (0 à 100 %) de votre étape.
2. **Étape** : *À faire → Traduction → Relecture → Édition → Publié*. Passez à l'étape suivante
   quand la vôtre est terminée ; la personne suivante la voit alors dans ses tâches. Le passage
   n'est accepté que si les étapes précédentes sont à **100 %** (seuls les gérants et
   l'administrateur peuvent le forcer). **Publié** est posé automatiquement à la sortie du tome.
3. **Date cible** : la date de sortie visée (indicative ; la relecture décide).
4. **Bloqué** : cochez-le et indiquez la raison (« relecteur manquant », « en attente des
   illustrations »…) quand le tome ne peut plus avancer.
5. **Note pour l'équipe** (facultatif) : un mot pour les autres membres. Elle n'est **jamais**
   affichée aux lecteurs.
6. **Enregistrer**.

Vous ne pouvez modifier que les tomes dont vous êtes responsable, et seulement l'avancement de
**votre** étape ; les éditeurs et gérants peuvent tous les modifier (et désigner les responsables).
Un tome programmé s'affiche « Programmé le … » et n'est jamais en retard.

États affichés sur le planning public :

| État | Signification |
| --- | --- |
| ● **À l'heure** | La date cible tient |
| ▲ **En retard** | Date cible dépassée, **ou** aucune mise à jour depuis 14 jours |
| ■ **Bloqué** | Le tome est marqué bloqué (la raison est affichée) |
| **Publié** | Le tome est sorti |

Ce qui est public : l'étape, les pourcentages, le pseudo des responsables, la date cible, l'état et
l'historique des changements. Ce qui ne l'est pas : la note pour l'équipe.

Le planning public a aussi un onglet **Calendrier** (vue par mois des sorties prévues, programmées
et parues) et un bouton **S'abonner au calendrier (ICS)** : les lecteurs (et vous) peuvent ajouter
les dates de sortie à Google Agenda, Apple Calendrier ou Outlook, qui se mettent à jour seuls. Une
date cible y apparaît comme « (prévision) » : seule une sortie programmée y est confirmée. Chaque
œuvre a enfin son flux RSS (`/oeuvres/{œuvre}/feed/`) avec ses nouveaux tomes, chapitres et
actualités.

### Rappels automatiques

Chaque jour vers **9 h (heure de Paris)**, le site vérifie le planning :

- date cible dépassée → e-mail au responsable (son bouton ouvre la tâche dans **Mes tâches**) +
  message sur le Discord de l'équipe ;
- aucune mise à jour depuis **14 jours** → rappel « mets ton planning à jour » ;
- chaque **lundi** → récapitulatif aux gérants (état global, retards, sorties de la semaine).

Les rappels d'un même tome s'espacent : un premier rappel, une relance trois jours plus tard, puis
**au plus un par semaine**. Au-delà de **8 semaines de retard**, plus aucun rappel : le tome reste
seulement dans le récapitulatif des gérants (« Plus de rappel automatique ») ; mettez-le à jour,
en pause ou retirez-le du planning. Un tome **en pause** ne reçoit jamais de rappel.

Pour ne plus recevoir de rappel : mettez le tome à jour (même un petit pourcentage compte). Les
délais, l'heure et le jour du récapitulatif sont réglés par les gérants.

## 5. Publier un tome (`/equipe/publier/`)

Réservé aux **Éditeurs Yume** et aux **Gérants**. Un seul formulaire crée le tome, ses chapitres de
lecture en ligne, l'annonce, la mise à jour du planning et les notifications.

### 5.1 Préparer le DOCX

Le fichier Word du tome est la **source de la lecture en ligne**. Il est découpé automatiquement ;
il suffit d'utiliser les bons styles :

| Dans Word | Sur le site |
| --- | --- |
| Style **Titre 1** « Chapitre 1 », « Chapitre 2 »… | Un nouveau chapitre (une page de lecture) |
| Style **Titre 2** juste après le Titre 1 | Sous-titre du chapitre |
| Style **Titre 2** seul (« Postface », « Prologue »…) | Chapitre spécial sans numéro |
| Liste à puce « — » ou paragraphe qui commence par « — » | Dialogue (tiret cadratin, retrait) |
| Style **Pensée** | Pensée (italique, en retrait) |
| Paragraphe centré | Paragraphe centré |
| `***`, `* * *` ou `◇` seul sur une ligne | Séparateur de scène |
| Image JPG, PNG ou WebP dans le texte | Illustration pleine largeur |
| Images placées avant le premier Titre 1 | Galerie d'illustrations du tome (pas un chapitre) |
| Notes de bas de page | Appels de note et liste de notes en fin de chapitre |

Les ornements Word au format EMF/WMF, les en-têtes, pieds de page et sauts de page sont ignorés (et
signalés dans le rapport). Un EPUB est accepté en dépannage, mais le DOCX reste la référence.

### 5.2 Remplir le formulaire

1. **Œuvre** (liste), puis **Tome du planning** : choisissez le tome déjà prévu au planning (sa
   nature, son numéro et son titre sont repris, sans créer de doublon) ou « — Nouveau tome — ».
   Sinon, **Nature** (*Tome*, *Arc*, *Chapitre*, *EX / bonus*), **Numéro**, et un **Titre**
   facultatif.
2. **Liens PDF et EPUB** : collez les liens de téléchargement (ClicTune ou autre). **Les fichiers PDF
   et EPUB ne sont jamais envoyés sur le site** : ce ne sont que des liens.
3. **Couverture** : glissez l'image (JPG, PNG ou WebP ; 1400 × 2000 px conseillé).
4. **Déposez le DOCX** du tome. Le site l'analyse et affiche aussitôt :
   - les **chapitres détectés** avec leur nombre de mots ;
   - les **avertissements** (par exemple un titre mal formé corrigé automatiquement, des images
     ignorées) ;
   - un bouton pour **prévisualiser** un chapitre.
   Si le découpage est faux, corrigez les styles dans Word et déposez à nouveau le fichier.
5. **Crédits** : traduction, relecture, édition / couverture (affichés sur le tome et les chapitres).

### 5.3 Publier

- **Publier maintenant** : tout est mis en ligne immédiatement ;
- **Programmer** : choisissez la date et l'heure de sortie ;
- **Enregistrer en brouillon** : rien n'est visible des lecteurs, vous pourrez reprendre plus tard.

Un tome **sans chapitre ni lien PDF / EPUB** n'est publié qu'après confirmation (« Publier quand
même ce tome sans chapitre ni lien de téléchargement »).

À la publication, le site :

- crée le **tome** (couverture, liens PDF / EPUB, crédits) et ses **pages de lecture** (sommaire,
  navigation chapitre précédent / suivant) ;
- rédige l'**article d'annonce** « Le tome N de … est disponible ! » (modifiable ensuite comme un
  article normal) ;
- passe le planning du tome à **Publié, 100 %** (un arc publié chapitre par chapitre garde son étape
  jusqu'à la sortie de son dernier chapitre) ;
- prévient : message sur le salon Discord des sorties, e-mail aux lecteurs qui suivent l'œuvre,
  newsletter si elle est activée.

Si le tome a **déjà des chapitres**, le formulaire le rappelle sous la zone de dépôt : un nouveau
fichier les **remplace en place**, par numéro (mêmes adresses, commentaires conservés) ; les
chapitres absents du nouveau fichier restent en ligne, sauf si vous cochez « Mettre en brouillon les
chapitres absents du nouveau fichier ».

Le DOCX n'est **pas conservé** sur le serveur : seuls les chapitres et les illustrations restent.
La publication est réversible : dépublier un tome le retire du site, remet son annonce en brouillon
et ramène son planning à l'étape **Édition** ; le remettre en ligne rétablit « Publié » sans
nouvelle annonce aux lecteurs.

### 5.4 Ajouter la lecture en ligne aux tomes déjà parus

Après la migration, beaucoup de tomes sont en ligne avec leurs seuls liens PDF / EPUB. Leur ajouter
la lecture en ligne ne doit pas être présenté aux lecteurs comme une nouveauté : c'est un **ajout
au catalogue**, sans annonce.

1. Espace équipe → **Lecture à compléter** (`/equipe/?vue=lecture`, éditeurs et gérants) : la liste
   des tomes parus qui n'ont aucun chapitre en ligne, par œuvre, avec la progression « X tomes sur Y
   ont la lecture en ligne », un filtre par œuvre et, pour chaque tome, sa couverture et ses liens
   PDF / EPUB présents.
2. **Ajouter le DOCX** ouvre le formulaire de publication déjà rempli pour ce tome. La case
   **« Ajout au catalogue : ne pas annoncer (pas d'article, pas de Discord, pas d'e-mail) »** est
   **cochée d'office** (elle l'est pour tout tome déjà publié ; elle est décochée pour un nouveau
   tome, un brouillon ou un tome programmé). Le récapitulatif « Ce qui sera créé » indique alors
   « Aucune annonce » au lieu de l'article et des notifications.
3. Déposez le DOCX, vérifiez les chapitres détectés, puis **Publier maintenant**.

Le site met les chapitres en ligne (lecture, sommaire, navigation) **sans** article dans
« Sorties », sans message Discord, sans e-mail aux lecteurs et sans compter le tome dans le
récapitulatif hebdomadaire. Les chapitres prennent la date de sortie du tome : ils n'apparaissent
pas comme nouveautés. Le journal de l'équipe note « lecture en ligne ajoutée (sans annonce) »
(visible de l'équipe seulement), et le tome **quitte la liste** « Lecture à compléter ».

Si plus tard de nouveaux chapitres sont ajoutés à ce tome **sans** cocher la case, ils sont annoncés
normalement comme de nouveaux chapitres (jamais comme la sortie du tome entier). Pour annoncer
quand même un tome déjà paru, décochez la case avant de publier.

En ligne de commande : `docx2chapters.php publish … --publier maintenant --sans-annonce`
(`--avec-annonce` pour forcer l'annonce ; sans l'une ni l'autre, le site choisit comme le
formulaire). Voir `tools/docx2chapters/README.md`.

### 5.4 bis Remplacer la lecture en ligne d'un tome déjà publié

Pour refaire la lecture en ligne d'un tome paru (nouvelle traduction, corrections en masse, DOCX
refait) :

1. Espace équipe → **Tous les tomes** (`/equipe/?vue=tomes`, éditeurs et gérants) : tous les tomes,
   **publiés compris** (la section « Tomes en préparation » du tableau de bord ne montre que le
   planning à venir). Filtrez par œuvre, par statut (publiés, programmés, brouillons) ou tapez une
   partie du titre.
2. Sur la ligne du tome : **Remplacer la lecture en ligne** (le tome a déjà des chapitres en ligne)
   ou **Lecture en ligne : ajouter le DOCX/EPUB** (il n'en a aucun). **Voir** ouvre la fiche publique,
   **Modifier** l'administration.
3. Le formulaire de publication s'ouvre déjà rempli. En haut, l'encadré **« Remplacer la lecture en
   ligne (N chapitres actuels) »** rappelle ce qui va se passer. **Rien ne change pour les lecteurs
   tant que vous n'avez pas cliqué sur « Remplacer la lecture en ligne maintenant ».**
4. Déposez le nouveau DOCX ou EPUB, puis cliquez sur **Vérifier (sans rien changer en ligne)** (dans
   l'encadré). Le fichier est découpé en une **version en attente**, visible de l'équipe seulement :
   le message indique le bilan (« 13 chapitres prêts (3 modifiés, 10 nouveaux) ; 1 chapitre en ligne
   absent du fichier, laissé en ligne ») et l'encadré liste chaque chapitre (**Nouveau**, **Modifié**
   ou **Inchangé**) avec son lien **Aperçu** (la nouvelle version, jamais montrée aux lecteurs ni aux
   moteurs de recherche) et **Version en ligne** pour comparer. **Enregistrer en brouillon** et
   **Prévisualiser le chapitre 1** font de même : ils ne touchent jamais aux chapitres en ligne.
5. Si tout est bon, laissez cochée la case **« Ajout au catalogue »** et cliquez sur **Remplacer la
   lecture en ligne maintenant** (ou **Publier maintenant**, qui fait la même chose). Sinon, corrigez
   le fichier et vérifiez-le à nouveau (la nouvelle vérification remplace la précédente), ou cliquez
   sur **Annuler le remplacement** : la version en attente et ses images sont supprimées, rien n'a
   changé en ligne.

Ce qui se passe au remplacement :

- chaque chapitre est **remplacé en place, par numéro** : même adresse, mêmes commentaires, même
  date, aucun doublon ; les chapitres nouveaux du fichier sont ajoutés (datés de la sortie du tome) ;
- un chapitre **absent du nouveau fichier** est signalé dès la vérification ; il reste en ligne, sauf
  si vous avez coché **« Mettre en brouillon les chapitres absents »** (il est alors mis en brouillon
  au moment du remplacement) ;
- **aucune annonce** : ni article, ni Discord, ni e-mail, ni notification aux lecteurs, et la **date
  de sortie du tome ne change pas**. Le message final l'indique : « *Titre du tome* : lecture en
  ligne remplacée (N chapitres en ligne ; M nouveaux), sans annonce : ni article, ni Discord, ni
  e-mail. La date de sortie du tome ne change pas. »

À savoir :

- **un seul remplacement peut attendre par tome** : si un autre membre en a déjà préparé un, votre
  vérification est refusée avec son nom et sa date ; appliquez-le ou annulez-le d'abord (les boutons
  sont dans l'encadré) ;
- une version en attente **ni appliquée ni annulée est supprimée au bout de 7 jours**, avec ses
  images ;
- les champs du tome lui-même (titre, liens PDF / EPUB, crédits, couverture téléversée) sont
  enregistrés dès l'envoi, comme avant ; seule la lecture en ligne attend le remplacement ;
- un tome **en brouillon ou programmé** n'a pas de version en attente : ses chapitres sont mis à jour
  directement (ils ne sont pas encore visibles des lecteurs).

### 5.5 Actualités d'une œuvre (onglet « Actualités » de la fiche)

Un article d'actualité **lié à une œuvre** apparaît sur la fiche de cette œuvre :

- pour lier un article, cochez l'œuvre dans le panneau **Œuvres liées** de l'éditeur d'article (les
  annonces de sortie créées à la publication d'un tome sont liées d'office) ;
- dès que l'œuvre a **au moins un article publié** lié, sa fiche affiche des onglets
  **Présentation · Actualités** sous l'en-tête et un encart **Dernières actualités** (les 3 plus
  récentes) sous la liste des tomes ;
- l'onglet mène à la page `/oeuvres/{œuvre}/actualites/` : tous les articles liés, du plus récent au
  plus ancien, 10 par page ;
- les brouillons, articles programmés et articles protégés par mot de passe n'y figurent pas ; sans
  article publié lié, ni onglet ni encart, et l'adresse `/actualites/` de l'œuvre n'existe pas.

### 5.6 Glossaire d'une œuvre (Éditeurs Yume et Gérants)

Le glossaire public d'une œuvre (`/oeuvres/{œuvre}/glossaire/`, onglet **Glossaire** de la fiche)
vient du fichier `glossaire.yaml` de Yume-Trad :

- **Téléverser** : *Espace équipe* → **Glossaires** → choisissez l'œuvre et le fichier, cliquez
  **Vérifier** (rien n'est publié : vous voyez le nombre d'entrées et les entrées ignorées), puis
  **Publier le glossaire**. Le nouveau fichier remplace entièrement l'ancien.
- **Depuis Yume-Trad** : l'application peut envoyer le glossaire elle-même, quand vous le lui
  demandez, avec un *mot de passe d'application* créé dans votre profil (voir `docs/glossaire.md`).
- **Historique** : les 5 dernières versions restent disponibles : **Télécharger ce YAML** ou
  **Restaurer cette version**.
- Ce que voient les lecteurs : le nom français, le terme original, le genre et le pluriel, le rôle
  et la description. Jamais les variantes, formes interdites, provenance, confiance ni preuve ; les
  entrées sans traduction française restent cachées (sauf `traduire: false` : « Nom conservé »).
  Connecté, l'équipe peut **Afficher les notes de traduction** sur la page.
- Une entrée peut être masquée derrière « Révéler (spoiler) » avec `spoiler: true` (et `tome: N`)
  dans le fichier.

## 6. Corriger un chapitre

Pour une coquille signalée par un lecteur (Éditeurs Yume et Gérants) :

1. Ouvrir l'administration : lien **Tableau de bord** du compte, ou `yumenovel.fr/wp-admin/`.
2. Menu **Yume → Chapitres**, rechercher le chapitre (titre ou œuvre), cliquer sur **Modifier**.
3. Corriger le texte directement dans l'éditeur, puis **Mettre à jour**.
   - Un dialogue est un paragraphe qui commence par « — » ; une pensée ou un paragraphe centré
     garde son style : modifiez le texte à l'intérieur du paragraphe plutôt que de le recréer.
   - Le **sous-titre** et les **crédits** se trouvent dans les réglages du chapitre (colonne de
     droite), pas dans le texte.
4. Répondre au lecteur sous son commentaire (« Corrigé, merci ! »).

Les liens PDF / EPUB, la couverture et les crédits d'un tome se corrigent de la même façon dans
**Yume → Tomes**. Les fiches des œuvres (synopsis, auteur, statut…) dans **Yume → Œuvres**.

Traducteurs, relecteurs et graphistes : signalez la correction à un éditeur (Discord de l'équipe).

## 7. Modérer les commentaires

Les lecteurs commentent sous chaque tome et chaque chapitre ; il faut **un compte** pour commenter
(un visiteur voit « Connectez-vous ou créez un compte pour commenter »). Éditeurs Yume et Gérants
modèrent dans **Commentaires** (administration) :

- **Approuver** les commentaires en attente (selon les réglages de discussion, le premier
  commentaire d'un nouveau lecteur attend une validation) ;
- **Répondre** : votre réponse apparaît sous le commentaire ;
- **Indésirable** pour le spam (Akismet en filtre déjà la plupart), **Corbeille** pour ce qui
  enfreint les règles (insultes, spoilers non signalés, liens pirates).

Un commentaire qui signale une coquille : corrigez (§6), répondez, puis approuvez-le ou supprimez-le.

**Sans passer par l'administration** : dans l'espace équipe, l'entrée **Commentaires (N)** du menu
(`/equipe/?vue=commentaires`, éditeurs, gérants et administrateurs) apparaît dès qu'il y a quelque
chose à modérer. Elle liste :

- les commentaires **signalés** par les lecteurs (les plus signalés d'abord, avec le pseudo et le
  motif de chaque signalement) ;
- les commentaires **en attente** de modération.

Pour chacun : **Approuver** (le publie et classe ses signalements), **Ignorer les signalements**
(le commentaire reste en ligne), **Indésirable** ou **Corbeille**. Les boutons n'apparaissent que si
votre compte peut modifier le contenu commenté (un éditeur modère les commentaires des tomes et
chapitres ; ceux d'un article d'un autre compte passent par un gérant).

Les lecteurs connectés ont un lien **Signaler** sous chaque commentaire (motif facultatif, un
signalement par compte). Au **3ᵉ signalement**, le commentaire est masqué et repasse en attente
jusqu'à votre décision. Vos réponses portent un badge **Équipe** à côté de votre pseudo.

## 8. Pour les gérants

- **Membres et rôles** (page `/equipe/membres/` de l'espace équipe) : la liste des membres et de
  leur rôle. Pour chacun : **Changer le rôle** (Traducteur, Relecteur, Graphiste, Éditeur Yume) ou
  **Retirer de l'équipe** (le compte repasse Lecteur ; réattribuez d'abord ses tâches). Un membre
  encore responsable de tomes en cours est signalé sur sa ligne (nombre et liste des tomes) avec un
  lien **Voir ses tomes dans le planning** (planning complet filtré sur lui) : le retrait ou le
  changement de rôle reste possible, mais il ne le décharge pas de ces tomes. **Ajouter un
  membre** : la personne crée d'abord son compte de lecteur sur le site, puis vous saisissez son
  identifiant ou son e-mail et choisissez son rôle. Les comptes administrateurs et gérants, et le
  vôtre, ne sont modifiables que par un administrateur ; l'administrateur a pour cela un lien
  **Modifier dans l'administration** sur la ligne de ces comptes. Un gérant change **seulement les
  rôles** : il ne crée pas de compte et ne modifie ni le mot de passe, ni l'e-mail, ni le profil d'un
  autre membre ou lecteur (*Comptes* dans l'administration ne sert qu'à consulter la liste et à
  changer un rôle). Un membre qui a perdu son mot de passe utilise « Mot de passe oublié ? » sur la
  page de connexion ; pour un changement d'e-mail ou un compte bloqué, c'est l'administrateur qui
  s'en charge.
- **Réglages** (menu de l'espace équipe, ou *Yume → Réglages* dans l'administration) :
  - *Site et réseaux* : bannière de l'accueil, liens Ko-fi, Discord et X ;
  - *Planning et rappels* : jours de sortie habituels, délai avant rappel (14 jours par défaut),
    heure des rappels, jour du récapitulatif ;
  - *Annonces et notifications* : webhooks Discord (sorties, équipe), e-mails aux lecteurs, modèle
    du texte d'annonce ;
  - *Partenaires* : la section « Nos partenaires » de l'accueil (voir ci-dessous) ;
  - *Recrutement* : la page « Rejoindre l'équipe » (voir ci-dessous) ;
  - *Mises à jour* : section réservée aux administrateurs (invisible pour les gérants).
- Le lien **Contact** du menu ouvre le **Discord** de Yume (lien d'invitation réglé dans *Site et
  réseaux*).
- Si une page du site (planning, espace équipe, compte…) a été supprimée ou dépubliée, un avis
  l'indique en haut de l'administration avec un bouton **Recréer les pages manquantes**.
- Les tâches automatiques (rappels, e-mails) se contrôlent dans **Santé du site** (menu de l'espace
  équipe, voir ci-dessous).

### Indicateurs et santé du site

- **Indicateurs** (menu de l'espace équipe, `/equipe/?vue=kpi`, gérants et administrateurs) : tomes
  sortis par mois sur un an (graphique et tableau), temps moyen passé dans chaque étape, tâches
  ouvertes et en retard de chaque membre, retards en cours, lecteurs actifs, favoris et lecteurs par
  œuvre, e-mails envoyés. Choisissez la période (30 jours, 90 jours ou 12 mois) puis **Afficher**.
  Les chiffres sont recalculés toutes les 5 minutes ; aucun lecteur n'y est nommé.
- **Santé du site** (menu de l'espace équipe, juste avant *Réglages*, `/equipe/?vue=sante`, gérants
  et administrateurs ; *Yume → Santé* dans l'administration y mène aussi) : dernière et prochaine
  exécution des tâches automatiques (rappels, récapitulatifs, envoi des e-mails), e-mails en
  attente ou abandonnés et derniers échecs d'envoi, webhooks Discord avec un bouton **Envoyer un
  test** (un message de test doit arriver sur le canal ; le résultat s'affiche en haut de la page),
  version installée du site et dernière version connue. Les administrateurs y voient aussi les
  **prérequis de mise en production** (l'avis de l'administration renvoie vers cette page). Les
  mêmes contrôles apparaissent dans *Outils → Santé du site*. Une tâche « en retard » signifie en
  général que le site a eu peu de visites : le cron de WordPress ne tourne qu'au passage des
  visiteurs.

### Partenaires de l'accueil

*Yume → Réglages → Partenaires* règle la section « Nos partenaires » de la page d'accueil :

- **8 partenaires au plus**, affichés dans l'ordre du tableau ;
- pour chacun : **nom**, **lien** (obligatoire, `https://…`), courte **description** et **logo** :
  l'ID d'une image de la médiathèque ou l'adresse d'une image ; sans logo, les initiales du nom
  sont affichées ;
- vider le nom et le lien d'une ligne **supprime** ce partenaire ;
- sur le site, chaque partenaire s'ouvre dans un **nouvel onglet**.

Après la migration, les quatre partenaires de l'ancien site sont déjà en place et leurs logos sont
retrouvés automatiquement dans la médiathèque.

### Recrutement (page « Rejoindre l'équipe »)

La page `/rejoindre-l-equipe/` (lien **Rejoindre l'équipe** du menu « Yume Novel », et depuis les
mentions « Poste à pourvoir » de la page « L'équipe ») se règle dans *Réglages → Recrutement* :

- **Introduction** : le texte d'accueil (une ligne vide sépare deux paragraphes) ;
- **Postes** : un poste par ligne, sous la forme `Intitulé | Description courte | ouvert` ; remplacez
  `ouvert` par `fermé` pour masquer un poste sans l'effacer. Sans poste ouvert, la page affiche
  « Aucun poste ouvert pour le moment » ;
- **Test de traduction** : lien facultatif vers le fichier du test (Google Drive, Dropbox…) ;
- **Comment postuler** : la consigne affichée au-dessus du bouton **Postuler sur le Discord**
  (lien : l'invitation Discord de *Site et réseaux*).

Le site ne recueille aucune candidature : tout passe par le Discord (ticket dans le salon
« tickets »), où l'équipe répond directement.

### Recherche dans le texte des chapitres

La recherche du site (`/?s=`) classe ses résultats en **Œuvres** (titre, titres alternatifs,
auteur, illustrateur, éditeur VO), **Tomes** et **Actualités**, avec des filtres (contenu, statut de
l'œuvre, genre, tri). Elle ignore les accents et les majuscules : « lanterne » trouve « Lanterné ».

Un quatrième groupe, **Dans les chapitres**, cherche aussi dans le texte des chapitres et affiche un
extrait autour du mot trouvé, avec un lien qui ouvre le chapitre au bon paragraphe. Il est
**désactivé par défaut** : cochez *Yume → Réglages → Site et réseaux → Recherche dans les chapitres*
pour l'activer.

Coût : chaque recherche d'au moins 3 caractères lit alors le texte de 60 chapitres au plus (les plus
récents qui contiennent les mots), ce qui charge davantage le serveur qu'une recherche dans les
titres. Seuls les chapitres **publiés** d'un tome et d'une œuvre publiés sont lus ; ceux d'une œuvre
**licenciée** ne le sont jamais. Si la recherche paraît lente, décochez la case : le reste de la
recherche n'est pas touché.

### Listes de lecture, cloche et notifications des lecteurs

Ce que les lecteurs connectés trouvent sur le site (utile pour leur répondre sur Discord) :

- **Listes de lecture** : sur la fiche d'une œuvre, le bouton **Ajouter à une liste** (à côté de
  *Favori* et *Alerte*) propose trois listes toutes prêtes, **À lire**, **En cours** et **Terminé**
  (une œuvre n'est que dans l'une des trois), et les listes personnelles du lecteur (20 au plus,
  500 œuvres chacune), qu'il peut créer directement depuis ce menu. Par défaut, une œuvre passe
  seule dans *En cours* quand il commence à la lire, puis dans *Terminé* quand il a lu le dernier
  chapitre publié ; il peut couper ce classement automatique dans *Mon compte → Mes listes*, où il
  renomme, décrit, rend publique ou supprime ses listes.
- **Liste publique** : une liste rendue publique a une adresse à partager
  (`/listes/k3v9q2m7x1c8b4n6-mes-pepites/`) qui montre son nom, le pseudo de son auteur (jamais son
  identifiant de connexion) et les couvertures des œuvres. Le code de l'adresse est tiré au hasard :
  on ne peut pas deviner les listes des autres en changeant un numéro. Elle n'est pas proposée aux
  moteurs de recherche. Une liste privée n'est visible que de son auteur ; si elle redevient
  publique, elle reçoit une **nouvelle adresse** (l'ancien lien partagé ne fonctionne plus). Une liste
  passée en privé ou supprimée quitte le cache des pages de WordPress.com quand l'hébergeur le permet ;
  sinon, un visiteur anonyme peut encore la voir quelques minutes.
- **Cloche** : à côté de *Mon compte*, une cloche affiche le nombre de notifications non lues : sortie
  d'un tome ou d'un chapitre d'une œuvre en favori (sauf alerte « Jamais ») et réponse à l'un de ses
  commentaires. Un clic ouvre la liste, avec **Tout marquer comme lu** ; tout l'historique (90 jours)
  est dans *Mon compte → Notifications*. Ces notifications ne dépendent pas des e-mails : un lecteur
  désabonné des e-mails les reçoit quand même dans la cloche.
- **Notifications navigateur** : dans *Mon compte → Notifications*, **Activer sur cet appareil**
  affiche une notification du navigateur à chaque nouvelle entrée de la cloche, même site fermé. À
  activer sur chaque appareil (ordinateur, téléphone) ; sur iPhone et iPad, il faut d'abord ajouter le
  site à l'écran d'accueil. Si le navigateur a bloqué les notifications, il faut les réautoriser dans
  ses réglages. Désactivable pour tout le site : *Yume → Réglages → Notifications →
  Notifications navigateur*.

## 9. Mises à jour du site

Le site se met à jour tout seul quand l'équipe technique publie une nouvelle version : rien à faire
de votre côté. En cas de souci après une mise à jour (page cassée, bouton qui ne répond plus),
prévenez l'équipe technique sur Discord avec l'adresse de la page et une capture d'écran.

## 10. Questions fréquentes

| Question | Réponse |
| --- | --- |
| Je ne vois pas mon tome dans *Mes tâches* | Vous n'en êtes pas responsable : demandez à un éditeur ou un gérant de vous l'attribuer. |
| Le planning dit « En retard » alors que j'avance | Aucune mise à jour depuis 14 jours : enregistrez votre avancement, même sans changer d'étape. |
| Le tome publié n'apparaît pas | Il est en brouillon ou programmé : vérifiez son statut dans *Planning complet* (filtre *Statut*) ou dans *Yume → Tomes*. |
| Un tome est arrêté mais reste « en retard » et déclenche des rappels | *Planning complet* → dépliez sa ligne → **Mettre en pause** ; **Reprendre** le jour où il repart. |
| J'ai ajouté un tome au planning par erreur | *Planning complet* → dépliez sa ligne → **Retirer du planning** (brouillon sans chapitre publié). |
| Un lien PDF ou EPUB est mort | *Yume → Tomes → Modifier* le tome et remplacez le lien. |
| Les chapitres sont mal découpés | Vérifiez les styles *Titre 1* / *Titre 2* dans Word et redéposez le DOCX avant de publier. |
| Un lecteur ne veut plus d'e-mails | Chaque e-mail d'alerte a un lien de désabonnement (une œuvre, les réponses aux commentaires ou tout) : il confirme sans se connecter. Il peut aussi tout régler dans *Mon compte*. |
| Un lecteur ne reçoit pas les alertes | Il doit avoir l'œuvre en favori avec une alerte active (page *Mon compte*) ; les e-mails aux lecteurs doivent être activés dans *Yume → Réglages*. |
| J'ai oublié mon mot de passe | Lien « Mot de passe oublié ? » sur la page de connexion. |
| Un lecteur veut lire sans connexion (train, avion) | Qu'il ouvre les chapitres voulus une fois en ligne (le chapitre suivant est gardé aussi) : ils restent lisibles hors ligne sur cet appareil, 30 au plus. Il peut aussi installer le site comme une application (« Installer » / « Ajouter à l'écran d'accueil » du navigateur). Hors ligne, les autres pages affichent « Vous êtes hors ligne » avec la liste des chapitres disponibles. Désactivable : *Yume → Réglages* → **Lecture hors ligne**. |
| Un lecteur a du mal à lire (dyslexie, vue basse, animations gênantes) | Dans un chapitre, bouton ⚙ **Paramètres** → **Accessibilité** : police adaptée à la dyslexie (OpenDyslexic), contraste renforcé, réduction des animations. Les raccourcis clavier (← → pour changer de chapitre) y sont listés. |
| Un lecteur demande combien il a lu | *Mon compte* → **Mes statistiques** : tomes terminés, chapitres lus, temps de lecture estimé, séries en cours et badge « À jour » par série (d'après sa position de lecture, en étant connecté). |
| Un lecteur ne retrouve pas une œuvre dans « En cours » ou « Terminé » | Le classement automatique suit sa position de lecture en étant connecté ; il a pu le couper dans *Mon compte → Mes listes*. Il peut toujours ranger l'œuvre à la main avec **Ajouter à une liste** sur la fiche. |
| Un lecteur ne reçoit pas les notifications du navigateur | Elles s'activent appareil par appareil dans *Mon compte → Notifications* ; le navigateur doit les autoriser pour le site. Elles arrivent quelques minutes après la sortie (envoi planifié). Sa cloche, elle, se met à jour dans tous les cas. |
