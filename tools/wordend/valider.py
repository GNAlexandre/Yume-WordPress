#!/usr/bin/env python3
"""Valide un univers WordEnd (manifeste, personnages, ennemis, niveaux, planches) sans WordPress.

Mêmes règles que le test PHP `wp-content/plugins/yume-core/tests/test-wordend.php`
(`docs/wordend-formats.md` §5) : manifeste en version 2, fichiers référencés lisibles, slugs égaux
aux noms de fichiers, planches (dimensions du PNG, cadres dans l'image, ancres dans leur cadre,
images `coup`/`onde` existantes), `poses` et animations des compétences existantes, types de
compétences, comportements et objectifs connus, attaques déclarées et animées, `decor`/`musique`/
`suivant` résolus, plateformes dans `[0, largeur]`, références d'ennemis des niveaux résolues.

Usage (Python 3.9+, pip install pillow) :

    python3 tools/wordend/valider.py wp-content/plugins/yume-core/includes/wordend/assets/univers/sukasuka [--strict]

Un personnage (autre que le premier) ou un niveau (autre que le niveau par défaut) listé mais absent
est un **avertissement** (le moteur le tolère : fichiers des lots en cours) ; `--strict` en fait une
erreur. Code de sortie : 0 si aucune erreur, 1 sinon.
"""

import argparse
import json
import os
import sys

from PIL import Image

COMPETENCES = ('melee', 'onde', 'projectile', 'ruee', 'parade')
COMPORTEMENTS = ('marcheur', 'coureur', 'volant', 'tireur', 'bouclier', 'boss')
OBJECTIFS = ('arcade', 'vagues', 'survie', 'boss')
COTES = ('gauche', 'droite', 'alterne', 'aleatoire')
PLATEFORMES = ('traversable', 'solide')
ANIMATIONS_PERSONNAGE = ('repos', 'marche', 'course', 'attaque', 'charge', 'degats', 'mort')
ANIMATIONS_ENNEMI = ('repos', 'marche', 'course', 'degats', 'mort')


def nombre(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


class Validateur:
    def __init__(self, dossier, strict):
        self.dossier = os.path.abspath(dossier)
        self.strict = strict
        self.erreurs = []
        self.avertissements = []
        self.planches = {}

    # --- Messages ---

    def erreur(self, ou, message):
        self.erreurs.append('%s : %s' % (ou, message))

    def avertir(self, ou, message):
        (self.erreurs if self.strict else self.avertissements).append('%s : %s' % (ou, message))

    def verifier(self, condition, ou, message):
        if not condition:
            self.erreur(ou, message)
        return bool(condition)

    # --- Fichiers ---

    def chemin(self, relatif):
        return os.path.join(self.dossier, relatif)

    def lire_json(self, relatif, tolere=False):
        """JSON d'un fichier de l'univers, ou None (absent : erreur, ou avertissement si toléré)."""
        chemin = self.chemin(relatif)
        if not os.path.isfile(chemin):
            if tolere:
                self.avertir(relatif, 'fichier listé mais absent (toléré par le moteur, attendu d’un lot)')
            else:
                self.erreur(relatif, 'fichier absent')
            return None
        try:
            with open(chemin, encoding='utf-8') as f:
                donnees = json.load(f)
        except (OSError, ValueError) as e:
            self.erreur(relatif, 'JSON illisible (%s)' % e)
            return None
        if not isinstance(donnees, dict):
            self.erreur(relatif, 'objet JSON attendu')
            return None
        return donnees

    def fichier_present(self, relatif, ou):
        return self.verifier(isinstance(relatif, str) and os.path.isfile(self.chemin(relatif)), ou,
                             'fichier « %s » absent' % relatif)

    # --- Planches ---

    def planche(self, dossier_relatif, nom, ou):
        """Vérifie `<nom>.png` + `<nom>.planche.json` ; renvoie la méta (ou None)."""
        if not isinstance(nom, str) or not nom:
            self.erreur(ou, '« planche » manquant')
            return None
        base = os.path.join(dossier_relatif, nom)
        if base in self.planches:
            return self.planches[base]
        self.planches[base] = None
        meta = self.lire_json(base + '.planche.json')
        png = base + '.png'
        if not self.fichier_present(png, ou) or meta is None:
            return None
        lieu = base + '.planche.json'
        try:
            with Image.open(self.chemin(png)) as image:
                largeur, hauteur = image.size
        except OSError as e:
            self.erreur(png, 'image illisible (%s)' % e)
            return None
        self.verifier(meta.get('version') == 1, lieu, 'version 1 attendue')
        self.verifier(nombre(meta.get('echelle')) and meta['echelle'] > 0, lieu, '« echelle » positive attendue')
        self.verifier(meta.get('planche') == [largeur, hauteur], lieu,
                      'dimensions %s ≠ PNG %d × %d' % (meta.get('planche'), largeur, hauteur))
        animations = meta.get('animations')
        if not self.verifier(isinstance(animations, dict) and animations, lieu, '« animations » manquant'):
            return None
        for anim, d in animations.items():
            ici = '%s « %s »' % (lieu, anim)
            images = d.get('images') if isinstance(d, dict) else None
            if not self.verifier(isinstance(images, list) and images, ici, 'aucune image'):
                continue
            self.verifier(nombre(d.get('ips')) and d['ips'] > 0, ici, '« ips » positif attendu')
            self.verifier(isinstance(d.get('boucle'), bool), ici, '« boucle » booléen attendu')
            for k, c in enumerate(images):
                if not self.verifier(isinstance(c, list) and len(c) == 6 and all(nombre(v) for v in c), ici,
                                     'image %d : [x, y, largeur, hauteur, ancre x, ancre y] attendu' % k):
                    continue
                x, y, l, h, ax, ay = c
                self.verifier(x >= 0 and y >= 0 and l > 0 and h > 0 and x + l <= largeur and y + h <= hauteur,
                              ici, 'image %d : cadre hors de la planche' % k)
                self.verifier(0 <= ax <= l and 0 <= ay <= h, ici, 'image %d : ancre hors de son cadre' % k)
            for indice in d.get('coup', []):
                self.verifier(isinstance(indice, int) and 0 <= indice < len(images), ici,
                              'image de coup %s inexistante' % indice)
            if 'onde' in d:
                self.verifier(isinstance(d['onde'], int) and 0 <= d['onde'] < len(images), ici,
                              'image d’onde %s inexistante' % d['onde'])
        self.planches[base] = meta
        return meta

    # --- Entités ---

    def entete(self, donnees, relatif):
        attendu = os.path.splitext(os.path.basename(relatif))[0]
        self.verifier(donnees.get('version') == 2, relatif, 'version 2 attendue')
        self.verifier(donnees.get('slug') == attendu, relatif,
                      'slug « %s » ≠ nom du fichier « %s »' % (donnees.get('slug'), attendu))
        return attendu

    def boite(self, b, ou, champs=('l', 'h')):
        return self.verifier(isinstance(b, dict) and all(nombre(b.get(k)) for k in champs), ou,
                             'boîte {%s} numérique attendue' % ', '.join(champs))

    def personnage(self, relatif, donnees, niveaux):
        self.entete(donnees, relatif)
        dossier = os.path.dirname(relatif)
        meta = self.planche(dossier, donnees.get('planche'), relatif)
        anims = (meta or {}).get('animations', {})
        if meta:
            for a in ANIMATIONS_PERSONNAGE:
                if a not in anims:
                    self.avertir(relatif, 'animation attendue « %s » absente de la planche' % a)
        for champ in ('nom', 'description'):
            self.verifier(isinstance(donnees.get(champ), str) and donnees[champ], relatif, '« %s » manquant' % champ)
        self.boite(donnees.get('boite'), relatif + ' « boite »')
        for champ in ('pv', 'vitesseMarche', 'vitesseCourse'):
            self.verifier(nombre(donnees.get(champ)) and donnees[champ] > 0, relatif, '« %s » positif attendu' % champ)
        saut = donnees.get('saut')
        if saut is not None:
            self.verifier(isinstance(saut, dict) and nombre(saut.get('impulsion'))
                          and isinstance(saut.get('sautsMax', 1), int), relatif, '« saut » {impulsion, sautsMax} attendu')
        for pose, cible in (donnees.get('poses') or {}).items():
            ok = isinstance(cible, list) and len(cible) == 2 and cible[0] in anims \
                and isinstance(cible[1], int) and -len(anims[cible[0]]['images']) <= cible[1] < len(anims[cible[0]]['images'])
            if meta:
                self.verifier(ok, relatif, 'pose « %s » : %s inexistante dans la planche' % (pose, cible))
        competences = donnees.get('competences')
        if self.verifier(isinstance(competences, dict) and 'principale' in competences, relatif,
                         '« competences.principale » manquant'):
            for emplacement, c in competences.items():
                ici = '%s compétence « %s »' % (relatif, emplacement)
                if not self.verifier(isinstance(c, dict), ici, 'objet attendu'):
                    continue
                self.verifier(c.get('type') in COMPETENCES, ici,
                              'type « %s » inconnu (%s)' % (c.get('type'), ', '.join(COMPETENCES)))
                if c.get('animation') and meta:
                    self.verifier(c['animation'] in anims, ici, 'animation « %s » absente de la planche' % c['animation'])
                if c.get('type') == 'melee':
                    self.boite(c.get('boite'), ici + ' « boite »', ('x', 'y', 'l', 'h'))
        debloque = donnees.get('debloque', True)
        if isinstance(debloque, dict):
            self.verifier(debloque.get('niveau') in niveaux, relatif,
                          'déblocage : niveau « %s » inconnu' % debloque.get('niveau'))
        else:
            self.verifier(debloque is True, relatif, '« debloque » : true ou {"niveau": …}')

    def ennemi(self, relatif, donnees):
        self.entete(donnees, relatif)
        dossier = os.path.dirname(relatif)
        meta = self.planche(dossier, donnees.get('planche'), relatif)
        anims = (meta or {}).get('animations', {})
        if meta:
            for a in ANIMATIONS_ENNEMI:
                if a not in anims:
                    self.avertir(relatif, 'animation attendue « %s » absente de la planche' % a)
        self.boite(donnees.get('boite'), relatif + ' « boite »')
        attaques = donnees.get('attaques') or {}
        for nom, a in attaques.items():
            self.verifier(isinstance(a, dict) and nombre(a.get('portee')) and nombre(a.get('hauteur')), relatif,
                          'attaque « %s » : portee et hauteur attendues' % nom)
        couleurs = donnees.get('couleursMort')
        if couleurs is not None:
            self.verifier(isinstance(couleurs, list) and len(couleurs) == 3, relatif, '« couleursMort » : 3 couleurs')
        types = donnees.get('types')
        if not self.verifier(isinstance(types, dict) and types, relatif, '« types » manquant'):
            return {}
        for nom, t in types.items():
            ici = '%s type « %s »' % (relatif, nom)
            if not self.verifier(isinstance(t, dict), ici, 'objet attendu'):
                continue
            self.verifier(t.get('comportement') in COMPORTEMENTS, ici,
                          'comportement « %s » inconnu (%s)' % (t.get('comportement'), ', '.join(COMPORTEMENTS)))
            for champ in ('taille', 'pv', 'vitesse', 'points'):
                self.verifier(nombre(t.get(champ)) and t[champ] > 0, ici, '« %s » positif attendu' % champ)
            anims_type = anims
            if t.get('planche'):
                meta_type = self.planche(dossier, t['planche'], ici)
                anims_type = (meta_type or {}).get('animations', {})
            liste = t.get('attaques')
            if self.verifier(isinstance(liste, list) and liste, ici, '« attaques » : liste non vide'):
                for a in liste:
                    self.verifier(a in attaques, ici, 'attaque « %s » non déclarée dans « attaques »' % a)
                    if meta:
                        self.verifier(a in anims_type, ici, 'attaque « %s » sans animation dans la planche' % a)
        return types

    def niveau(self, relatif, donnees, manifeste, slugs_niveaux, ennemis):
        self.entete(donnees, relatif)
        self.verifier(isinstance(donnees.get('titre'), str) and donnees['titre'], relatif, '« titre » manquant')
        for champ, cle in (('decor', 'decors'), ('musique', 'musiques')):
            if champ in donnees:
                self.verifier(donnees[champ] in (manifeste.get(cle) or {}), relatif,
                              '%s « %s » absent du manifeste' % (champ, donnees[champ]))
        largeur = donnees.get('largeur', 480)
        sol = donnees.get('sol', 238)
        self.verifier(nombre(largeur) and largeur >= 480, relatif, '« largeur » ≥ 480 attendue')
        self.verifier(nombre(sol) and 0 < sol <= 270, relatif, '« sol » dans ]0, 270] attendu')
        plateformes = donnees.get('plateformes', [])
        if self.verifier(isinstance(plateformes, list), relatif, '« plateformes » : liste attendue') and nombre(largeur):
            for k, p in enumerate(plateformes):
                ici = '%s plateforme %d' % (relatif, k)
                if not self.verifier(isinstance(p, dict) and all(nombre(p.get(c)) for c in ('x', 'y', 'l')), ici,
                                     '{x, y, l} numériques attendus'):
                    continue
                self.verifier(p['x'] >= 0 and p['l'] > 0 and p['x'] + p['l'] <= largeur, ici,
                              'hors de [0, %s]' % largeur)
                self.verifier(0 <= p['y'] <= (sol if nombre(sol) else 270), ici, 'au-dessous du sol')
                self.verifier(p.get('type', 'traversable') in PLATEFORMES, ici, 'type « %s » inconnu' % p.get('type'))
        apparition = donnees.get('apparition')
        if apparition is not None and nombre(largeur):
            self.verifier(isinstance(apparition, dict) and nombre(apparition.get('x'))
                          and 0 <= apparition['x'] <= largeur, relatif, '« apparition.x » dans [0, largeur] attendu')
        if 'suivant' in donnees:
            self.verifier(donnees['suivant'] in slugs_niveaux, relatif,
                          'niveau suivant « %s » absent du manifeste' % donnees['suivant'])
        objectif = donnees.get('objectif')
        if not self.verifier(isinstance(objectif, dict), relatif, '« objectif » manquant'):
            return
        genre = objectif.get('type')
        if not self.verifier(genre in OBJECTIFS, relatif, 'objectif « %s » inconnu (%s)' % (genre, ', '.join(OBJECTIFS))):
            return

        def reference(ref, ici, type_requis=True):
            if not isinstance(ref, dict):
                self.erreur(ici, 'objet {ennemi, type} attendu')
                return
            if not self.verifier(ref.get('ennemi') in ennemis, ici, 'ennemi « %s » inconnu' % ref.get('ennemi')):
                return
            if type_requis or 'type' in ref:
                self.verifier(ref.get('type') in ennemis[ref['ennemi']], ici,
                              'type « %s » inconnu pour « %s »' % (ref.get('type'), ref['ennemi']))

        if genre == 'arcade':
            if 'ennemi' in objectif:
                self.verifier(objectif['ennemi'] in ennemis, relatif, 'ennemi « %s » inconnu' % objectif['ennemi'])
        elif genre == 'vagues':
            vagues = objectif.get('vagues')
            if self.verifier(isinstance(vagues, list) and vagues, relatif, '« objectif.vagues » : liste non vide'):
                for k, v in enumerate(vagues):
                    ici = '%s vague %d' % (relatif, k + 1)
                    groupes = v.get('ennemis') if isinstance(v, dict) else None
                    if not self.verifier(isinstance(groupes, list) and groupes, ici, '« ennemis » : liste non vide'):
                        continue
                    for g in groupes:
                        reference(g, ici)
                        if isinstance(g, dict):
                            self.verifier(isinstance(g.get('n', 1), int) and g.get('n', 1) > 0, ici, '« n » entier positif')
                            self.verifier(g.get('cote', 'alterne') in COTES, ici, 'côté « %s » inconnu' % g.get('cote'))
        elif genre == 'survie':
            self.verifier(nombre(objectif.get('duree')) and objectif['duree'] > 0, relatif, '« objectif.duree » positive')
            generateur = objectif.get('generateur')
            if self.verifier(isinstance(generateur, dict), relatif, '« objectif.generateur » manquant'):
                groupes = generateur.get('ennemis')
                if self.verifier(isinstance(groupes, list) and groupes, relatif, '« generateur.ennemis » : liste non vide'):
                    for g in groupes:
                        reference(g, relatif + ' générateur')
        elif genre == 'boss':
            reference(objectif, relatif + ' objectif boss')
        etoiles = donnees.get('etoiles')
        if etoiles is not None:
            self.verifier(isinstance(etoiles, dict), relatif, '« etoiles » : objet attendu')

    # --- Univers ---

    def valider(self):
        if not os.path.isdir(self.dossier):
            self.erreur(self.dossier, 'dossier introuvable')
            return
        manifeste = self.lire_json('manifeste.json')
        if manifeste is None:
            return
        lieu = 'manifeste.json'
        self.verifier(manifeste.get('version') == 2, lieu, 'version 2 attendue')
        self.verifier(manifeste.get('slug') == os.path.basename(self.dossier), lieu,
                      'slug « %s » ≠ dossier « %s »' % (manifeste.get('slug'), os.path.basename(self.dossier)))
        for champ in ('titre', 'oeuvre'):
            self.verifier(isinstance(manifeste.get(champ), str) and manifeste[champ], lieu, '« %s » manquant' % champ)
        listes = {}
        for cle in ('personnages', 'ennemis', 'niveaux'):
            liste = manifeste.get(cle)
            ok = isinstance(liste, list) and liste and all(isinstance(c, str) and c.endswith('.json') for c in liste)
            self.verifier(ok, lieu, '« %s » : liste non vide de chemins .json attendue' % cle)
            listes[cle] = liste if ok else []
            if ok and len(set(liste)) != len(liste):
                self.erreur(lieu, '« %s » : chemin en double' % cle)
        for nom, d in (manifeste.get('decors') or {}).items():
            ici = '%s décor « %s »' % (lieu, nom)
            if self.verifier(isinstance(d, dict), ici, 'objet attendu'):
                self.fichier_present(d.get('image'), ici)
                if 'parallaxe' in d:
                    self.verifier(nombre(d['parallaxe']) and 0 <= d['parallaxe'] <= 1, ici, 'parallaxe dans [0, 1]')
        for nom, m in (manifeste.get('musiques') or {}).items():
            ici = '%s musique « %s »' % (lieu, nom)
            if self.verifier(isinstance(m, dict), ici, 'objet attendu'):
                self.fichier_present(m.get('fichier'), ici)

        def slug(chemin):
            return os.path.splitext(os.path.basename(chemin))[0]

        slugs_niveaux = [slug(c) for c in listes['niveaux']]
        defaut = 'arcade' if 'arcade' in slugs_niveaux else (slugs_niveaux[0] if slugs_niveaux else None)

        ennemis = {}
        for relatif in listes['ennemis']:
            donnees = self.lire_json(relatif)
            if donnees is not None:
                ennemis[slug(relatif)] = self.ennemi(relatif, donnees)
        # Invocations du boss (ennemi et type résolus).
        for nom_ennemi, types in ennemis.items():
            for nom_type, t in types.items():
                for phase in (t.get('phases') or []) if isinstance(t, dict) else []:
                    inv = phase.get('invocations') if isinstance(phase, dict) else None
                    if inv:
                        ici = '%s type « %s » invocations' % (nom_ennemi, nom_type)
                        if self.verifier(inv.get('ennemi') in ennemis, ici, 'ennemi « %s » inconnu' % inv.get('ennemi')):
                            self.verifier(inv.get('type') in ennemis[inv['ennemi']], ici, 'type « %s » inconnu' % inv.get('type'))

        for k, relatif in enumerate(listes['personnages']):
            donnees = self.lire_json(relatif, tolere=k > 0)
            if donnees is not None:
                self.personnage(relatif, donnees, slugs_niveaux)

        for relatif in listes['niveaux']:
            donnees = self.lire_json(relatif, tolere=slug(relatif) != defaut)
            if donnees is not None:
                self.niveau(relatif, donnees, manifeste, slugs_niveaux, ennemis)


def main():
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('dossier', help='dossier de l’univers (contient manifeste.json)')
    p.add_argument('--strict', action='store_true', help='fichiers listés absents = erreurs (intégration)')
    args = p.parse_args()
    v = Validateur(args.dossier, args.strict)
    v.valider()
    for message in v.avertissements:
        print('avertissement : ' + message)
    for message in v.erreurs:
        print('erreur : ' + message, file=sys.stderr)
    nom = os.path.basename(os.path.abspath(args.dossier))
    if v.erreurs:
        print('Univers « %s » : %d erreur(s), %d avertissement(s).' % (nom, len(v.erreurs), len(v.avertissements)),
              file=sys.stderr)
        sys.exit(1)
    print('Univers « %s » valide (%d avertissement(s)).' % (nom, len(v.avertissements)))


if __name__ == '__main__':
    main()
