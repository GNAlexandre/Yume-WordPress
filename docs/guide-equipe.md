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

- Cliquer sur **Connexion** en haut à droite du site (page `/connexion/`), saisir son identifiant ou
  son e-mail et son mot de passe. « Mot de passe oublié ? » envoie un lien de réinitialisation par
  e-mail.
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
- les liens **Publier un tome** et **Lecture à compléter** (éditeurs), **Planning complet**, **Journal**, et pour les gérants
  **Membres et rôles** et **Réglages** ;
- **Se déconnecter**, sous votre nom dans le menu de l'espace équipe.

Sous chaque tâche (et chaque tome de *Tous les tomes*), des raccourcis mènent directement au bon
écran : **Publier ce tome** (éditeurs : le formulaire de publication s'ouvre déjà rempli pour ce
tome, inutile de ressaisir œuvre, nature et numéro), **Modifier dans l'administration**, **Voir la
fiche** (tome publié), **Historique** (le journal de ce tome) et **Gérer dans le planning complet**.

### Planning complet (`/equipe/?vue=planning`)

L'entrée **Planning complet** du menu ouvre la gestion de **tout** le planning, sans passer par
l'administration WordPress (le planning public reste accessible par le bouton **Voir le planning
public**) :

- **tous les tomes** : en préparation, programmés, en attente, publiés (même anciens) ;
- **filtres** : œuvre, état (à l'heure, en retard, bloqué, publié), statut (brouillon, programmé,
  publié…) et responsable ; **Afficher tout le planning** retire les filtres ;
- chaque ligne se déplie : étape, avancement des trois étapes, responsables, date cible, blocage et
  note, puis **Enregistrer**. Si l'enregistrement est refusé (par exemple « Terminez d'abord l'étape
  Traduction (100 %) avant de passer à la Relecture »), le message s'affiche en rouge **dans la
  ligne** ; seuls les gérants et l'administrateur peuvent forcer une étape (le journal note alors
  « étape forcée ») ;
- les mêmes raccourcis que ci-dessus, plus **Retirer du planning** (éditeurs, gérants) : pour un
  tome ajouté par erreur, **brouillon sans chapitre publié** seulement. Il part à la corbeille (un
  administrateur peut le récupérer). Un tome publié, programmé ou avec des chapitres en ligne ne
  se retire pas ici : le message explique quoi faire dans l'administration.

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

## 4. Mettre à jour son planning

Le planning public (`/planning/`) montre aux lecteurs où en est chaque tome. Il se met à jour
**uniquement** à partir de ce que l'équipe saisit : pensez-y à chaque avancée.

Dans **Mes tâches**, pour chaque tome :

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

### Rappels automatiques

Chaque jour vers **9 h (heure de Paris)**, le site vérifie le planning :

- date cible dépassée → e-mail au responsable + message sur le Discord de l'équipe ;
- aucune mise à jour depuis **14 jours** → rappel « mets ton planning à jour » ;
- chaque **lundi** → récapitulatif aux gérants (état global, retards, sorties de la semaine).

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
  - *Mises à jour* : section réservée aux administrateurs (invisible pour les gérants).
- Le lien **Contact** du menu ouvre le **Discord** de Yume (lien d'invitation réglé dans *Site et
  réseaux*).
- Si une page du site (planning, espace équipe, compte…) a été supprimée ou dépubliée, un avis
  l'indique en haut de l'administration avec un bouton **Recréer les pages manquantes**.
- Les tâches automatiques (rappels, e-mails) peuvent être contrôlées avec l'extension WP Crontrol.

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
| J'ai ajouté un tome au planning par erreur | *Planning complet* → dépliez sa ligne → **Retirer du planning** (brouillon sans chapitre publié). |
| Un lien PDF ou EPUB est mort | *Yume → Tomes → Modifier* le tome et remplacez le lien. |
| Les chapitres sont mal découpés | Vérifiez les styles *Titre 1* / *Titre 2* dans Word et redéposez le DOCX avant de publier. |
| Un lecteur ne veut plus d'e-mails | Chaque e-mail d'alerte a un lien de désabonnement (une œuvre, les réponses aux commentaires ou tout) : il confirme sans se connecter. Il peut aussi tout régler dans *Mon compte*. |
| Un lecteur ne reçoit pas les alertes | Il doit avoir l'œuvre en favori avec une alerte active (page *Mon compte*) ; les e-mails aux lecteurs doivent être activés dans *Yume → Réglages*. |
| J'ai oublié mon mot de passe | Lien « Mot de passe oublié ? » sur la page de connexion. |
