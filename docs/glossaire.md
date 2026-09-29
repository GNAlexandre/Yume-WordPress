# Glossaire des œuvres (module `glossaire`)

Chaque œuvre peut avoir un **glossaire public** : noms des personnages, lieux, organisations,
créatures, objets, termes de l'univers et anglicismes, avec leur forme originale et leur traduction
française. Il vient de l'application de traduction **Yume-Trad** (fichier `glossaire.yaml` de
l'œuvre) : on le **téléverse** dans l'espace équipe, ou l'application l'**envoie directement** au
site. Chaque envoi **remplace** le glossaire de l'œuvre ; les 5 dernières versions sont gardées.

Il n'y a **aucune liaison permanente** entre le site et l'application : pas de webhook, pas de
synchronisation, pas de tâche qui interroge l'application. C'est toujours l'équipe (ou
l'application, sur demande) qui envoie un fichier, ponctuellement.

- Page publique : `/oeuvres/{œuvre}/glossaire/` (onglet **Glossaire** de la fiche).
- Espace équipe : `/equipe/?vue=glossaire` (entrée **Glossaires**).
- API : `POST /wp-json/yume/v1/oeuvres/{œuvre}/glossaire`.
- Droits : capacité `yume_glossaire` (Éditeur Yume, Gérant, administrateur).

## 1. Format YAML accepté

La racine du fichier liste des **catégories**. Les catégories connues s'affichent dans cet ordre :
`personnages`, `lieux`, `organisations`, `creatures`, `objets`, `termes`, `evenements`, `groupes`,
puis toute **autre clé** (catégorie générique, libellé tiré de la clé : `armes_legendaires` →
« Armes legendaires »), et `anglicismes` en dernier. Les accents des clés sont tolérés
(`créatures` = `creatures`).

Chaque catégorie (sauf `anglicismes`) est une **liste d'entrées** :

```yaml
personnages:
- termes_source:
  - アカリ
  - 灯里
  role: Héroïne
  description: Fille de l'allumeuse du village ; entend la voix des lanternes.
  traduire: null
  cibles:
    fr:
      nom: Akari
      pluriel: ''
      genre: féminin
      variantes: [Akari-chan]
      interdits: []
      force: false
  provenance: humain
  preuve: Planche couleur du tome 1
  confiance: sure
  spoiler: false   # facultatif (propre au site)
  tome: 1          # facultatif (propre au site)
anglicismes:
- vo: See you!
  fr: À la prochaine !
```

| Champ | Valeurs | Public ? | Rôle sur le site |
| --- | --- | --- | --- |
| `termes_source` | liste de chaînes (VO), liste vide permise | oui | affichés sous le nom (`lang="ja"` en japonais, sinon langue des `graphies_refusees` ou `lang="en"`), sauf un terme identique au nom à la casse près (« SABER » / « Saber ») ; le premier sert de nom s'il n'y a pas de traduction |
| `role` | texte | oui | ligne en gras sous le nom |
| `description` | texte (plusieurs lignes permises) | oui | description de l'entrée |
| `traduire` | `null`, `true`, `false` | oui (indirectement) | `false` : nom gardé tel quel → pastille « Nom conservé » |
| `cibles.fr.nom` | texte | oui | nom affiché (titre de la carte) |
| `cibles.fr.pluriel` | texte | oui | « pluriel : … », discret |
| `cibles.fr.genre` | `masculin`, `féminin`, `m`, `f`, `'?'`, `''` | oui | « masculin » / « féminin », discret ; `'?'` ou vide : rien |
| `cibles.fr.variantes` | liste | **non** (équipe) | notes de traduction |
| `cibles.fr.interdits` | liste | **non** (équipe) | notes de traduction (barrés) |
| `cibles.fr.force` | booléen | **non** (équipe) | « imposé » |
| `provenance` | `humain`, `terminologue`, `glossariste`, `import`, `inconnue` | **non** (équipe) | notes de traduction (autre valeur → `inconnue`) |
| `preuve` | texte | **non** (équipe) | notes de traduction |
| `confiance` | `sure`, `probable`, `hypothese` | **non** (équipe) | notes de traduction |
| `graphies_refusees` | mapping langue → liste (`en: [NUMBER 48]`) | **non** (équipe) | « Graphies refusées (EN) » dans les notes ; la langue sert aussi d'attribut `lang` des termes source |
| `spoiler` | booléen (facultatif) | oui | rôle et description dans un bloc « Révéler (spoiler, tome N) » |
| `tome` | entier (facultatif) | oui | premier tome où l'entrée apparaît (« dès le tome N », ou dans le libellé du spoiler) |

`anglicismes` : liste de paires `{vo, fr}` (une forme `vo: fr` en mapping est aussi acceptée),
affichées dans un tableau VO → FR.

Tolérances : champs absents (valeurs vides), champs inconnus (ignorés), nombres (convertis en
texte : `nom: 7`), `cibles.fr` vide ou `null` (entrée **sans traduction**). Tout le texte est
enregistré en **texte brut** (balises HTML retirées) et borné : nom, rôle, termes 200 caractères,
description 2 000, preuve 1 000, 20 termes source et 30 variantes ou interdits au plus.

**Entrées non publiques** : une entrée sans traduction française (`cibles.fr.nom` vide) et sans
`traduire: false` est **masquée au public** ; l'équipe la voit, marquée « À définir », avec les
notes de traduction. Une entrée sans nom français **ni** terme source est ignorée (avertissement).

Sous-ensemble YAML lu (`includes/glossaire/class-lecteur-yaml.php`, sans extension PHP) : YAML
« bloc » habituel, scalaires sur plusieurs lignes, `|` et `>`, guillemets, listes et mappings en
flux (`[a, b]`, `{c: d}`), commentaires. Refusés avec le numéro de ligne : ancres et alias (`&`,
`*`), étiquettes (`!`), clés complexes (`?`), tabulations de retrait, documents multiples.

Refus (rien n'est modifié) : YAML invalide (« YAML invalide. Ligne 12 : … »), racine qui n'est pas
une liste de catégories, aucune entrée exploitable, fichier de plus de **4 Mo**, plus de **5 000**
entrées (anglicismes compris). Un fichier **identique** (même empreinte SHA-256) à la version en
ligne n'est pas réécrit : réponse « inchangé ».

## 2. Page publique

`/oeuvres/{œuvre}/glossaire/`, gabarit du thème `single-yume_oeuvre-glossaire.html` (en-tête de
l'œuvre, onglets, bloc `yume/glossaire`) :

- sommaire des catégories (ancres et nombre d'entrées), une section par catégorie, entrées triées
  par nom ;
- recherche instantanée (nom, terme original, description ; sans tenir compte des accents) et
  filtre par catégorie, avec le nombre de résultats annoncé aux lecteurs d'écran ; sans
  JavaScript, tout le glossaire reste affiché ;
- onglet **Glossaire** de la fiche et page présents seulement si l'œuvre a au moins une entrée
  publique (sinon 404) ;
- titre « Glossaire — {Œuvre} », description, adresse canonique propre ; **noindex** tant que le
  glossaire a moins de 5 entrées publiques.

**Équipe connectée** : bouton **Afficher les notes de traduction** (ou `?notes=1`) : variantes,
formes interdites (barrées), « imposé », provenance, confiance, preuve, et entrées « À définir ».
Les éditeurs et gérants ont aussi un lien **Gérer le glossaire**.

## 3. Espace équipe (`/equipe/?vue=glossaire`)

1. Choisir l'œuvre, le fichier `.yaml` / `.yml` (4 Mo au plus), une note facultative.
2. **Vérifier** : le fichier est analysé sans rien publier : nombre d'entrées par catégorie,
   entrées visibles du public, anglicismes, entrées ignorées et pourquoi. Puis **Publier le
   glossaire** (ou **Annuler**). La vérification est gardée 30 minutes. **Publier le glossaire**
   directement depuis le formulaire publie sans étape de vérification.
3. **Historique** de l'œuvre : date, auteur, source (téléversement, Yume-Trad, restauration),
   nombre d'entrées ; **Télécharger ce YAML** ; **Restaurer cette version** (crée une nouvelle
   version identique à l'ancienne) ; lien vers la page publique.

Chaque publication ajoute au **journal de l'équipe** : « a mis à jour le glossaire de « X » (N
entrées) » (jamais public).

## 4. Connecteur Yume-Trad (envoi direct)

### 4.1 Mettre en place (une fois)

1. Se connecter au site avec un compte **Éditeur Yume** ou **Gérant**.
2. **Profil** (administration WordPress, `/wp-admin/profile.php`) → **Mots de passe
   d'application** → nom « Yume-Trad » → **Ajouter un mot de passe d'application**. Copier le
   mot de passe affiché (24 caractères, espaces comprises ou non) : il ne sera plus montré.
3. Dans Yume-Trad : adresse du site, identifiant du compte, mot de passe d'application, et
   l'**œuvre** du site (son slug, ex. `lanternes-de-brume-haute`, ou son ID). L'adresse complète
   est affichée dans l'espace équipe (encadré « Envoi direct depuis Yume-Trad »).

Le mot de passe d'application ne sert qu'à l'API : il ne permet pas de se connecter au site et se
révoque à tout moment depuis le profil. Les mots de passe d'application exigent **HTTPS**.

### 4.2 Requêtes

`POST /wp-json/yume/v1/oeuvres/{œuvre}/glossaire` — authentification HTTP Basic (identifiant +
mot de passe d'application), ou cookie de session + en-tête `X-WP-Nonce` (nonce `wp_rest`).
Corps accepté :

- YAML brut : `Content-Type: application/yaml` (ou `text/yaml`, `text/plain`) ;
- JSON : `{"yaml": "…", "note": "…"}` (`Content-Type: application/json`) ;
- multipart : champ fichier `glossaire` (et `note` facultatif).

Paramètre `simulation=1` : analyse et bilan, **rien n'est écrit**. Paramètre `note` : texte libre
gardé dans l'historique.

```sh
# Vérifier sans rien publier
curl -u "alice:abcd efgh ijkl mnop qrst uvwx" \
  -H "Content-Type: application/yaml" --data-binary @glossaire.yaml \
  "https://yumenovel.fr/wp-json/yume/v1/oeuvres/lanternes-de-brume-haute/glossaire?simulation=1"

# Publier
curl -u "alice:abcd efgh ijkl mnop qrst uvwx" \
  -H "Content-Type: application/yaml" --data-binary @glossaire.yaml \
  "https://yumenovel.fr/wp-json/yume/v1/oeuvres/lanternes-de-brume-haute/glossaire?note=Tome%203"

# Le site est-il à jour ? (métadonnées de la version en ligne, sans le contenu)
curl -u "alice:abcd efgh ijkl mnop qrst uvwx" \
  "https://yumenovel.fr/wp-json/yume/v1/oeuvres/lanternes-de-brume-haute/glossaire"
```

Réponse du `POST` (200) :

```json
{
  "statut": "importe",
  "oeuvre": { "id": 4, "titre": "Les Lanternes de Brume-Haute", "url_glossaire": "https://…/oeuvres/lanternes-de-brume-haute/glossaire/" },
  "nb_entrees": { "personnages": 4, "lieux": 2, "anglicismes": 2 },
  "total": 14, "anglicismes": 2, "publiques": 12,
  "avertissements": [ "chansons, entrée 2 ignorée : ni nom français ni terme source." ],
  "sha256": "7c7c…",
  "version": { "id": 12, "cree_le": "2026-09-28T18:07:35+00:00", "sha256": "7c7c…", "nb_entrees": 14, "source": "api", "auteur": "Alice", "note": "Tome 3" }
}
```

`statut` : `importe` (nouvelle version en ligne), `inchange` (fichier identique à la version en
ligne, rien réécrit) ou `simulation` (avec `identique: true|false`). `total` = entrées hors
anglicismes ; `version` = version créée (ou version en ligne pour `inchange`/`simulation`, `null`
s'il n'y en a pas).

Réponse du `GET` (200) : `{ "oeuvre": {…}, "version": {id, cree_le, sha256, nb_entrees, source,
auteur, note} | null, "entrees", "publiques", "anglicismes" }`. L'application compare `sha256` à
celle de son fichier (SHA-256 des octets envoyés) pour savoir si le site est à jour.

### 4.3 Exemple Python (`requests`)

```python
import hashlib, sys
import requests

SITE = "https://yumenovel.fr"
OEUVRE = "lanternes-de-brume-haute"          # slug ou ID de l'œuvre sur le site
AUTH = ("alice", "abcd efgh ijkl mnop qrst uvwx")  # mot de passe d'application
URL = f"{SITE}/wp-json/yume/v1/oeuvres/{OEUVRE}/glossaire"

def envoyer_glossaire(chemin, note="", simulation=False):
    donnees = open(chemin, "rb").read()
    en_ligne = requests.get(URL, auth=AUTH, timeout=30).json().get("version")
    if en_ligne and en_ligne["sha256"] == hashlib.sha256(donnees).hexdigest():
        return {"statut": "inchange", "version": en_ligne}
    r = requests.post(URL, auth=AUTH, data=donnees, timeout=60,
                      params={"simulation": int(simulation), "note": note},
                      headers={"Content-Type": "application/yaml; charset=utf-8"})
    if r.status_code != 200:
        err = r.json()
        raise RuntimeError(f"{r.status_code} {err.get('code')} : {err.get('message')}")
    return r.json()

if __name__ == "__main__":
    bilan = envoyer_glossaire(sys.argv[1], note="Envoyé par Yume-Trad")
    print(bilan["statut"], bilan.get("total"), *bilan.get("avertissements", []), sep="\n")
```

### 4.4 Codes d'erreur

| HTTP | `code` | Cause |
| --- | --- | --- |
| 401 | `rest_forbidden` | pas authentifié (mot de passe d'application absent ou faux) |
| 403 | `rest_forbidden` | compte sans la capacité `yume_glossaire` (lecteur, traducteur…) |
| 404 | `yume_oeuvre_introuvable` | œuvre inconnue (ID ou slug) |
| 400 | `yume_glossaire_absent` | corps vide ou de type non reconnu |
| 400 | `yume_glossaire_yaml_invalide` | YAML invalide ; `data.ligne` = ligne fautive |
| 400 | `yume_glossaire_structure` | la racine n'est pas une liste de catégories |
| 400 | `yume_glossaire_vide` | aucune entrée exploitable |
| 413 | `yume_glossaire_trop_gros` | plus de 4 Mo |
| 413 | `yume_glossaire_trop_entrees` | plus de 5 000 entrées |
| 415 | `yume_glossaire_type` | fichier (multipart) qui n'est pas un `.yaml`/`.yml` texte |
| 429 | `yume_glossaire_limite` | plus de 20 envois en une heure pour ce compte (simulations comprises) |
| 500 | `yume_glossaire_ecriture` | erreur de base de données : rien n'a été modifié |

## 5. Référence technique

Voir `docs/06-contrat-technique.md` (§ « Glossaire ») : tables `yume_glossaire` et
`yume_glossaire_versions`, méta `_yume_glossaire`, action `yume_glossaire_importe`, filtres.
