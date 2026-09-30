#!/usr/bin/env python3
"""Découpe des planches générées (Gemini…) en planches de jeu WordEnd, d'après une description JSON.

Chaque planche de jeu est décrite par un fichier `tools/wordend/source/<nom>.planche.json` (format
détaillé dans `tools/wordend/README.md`) : sources (image, fond, bandes par animation), ancre,
hauteur de référence, rythmes, ordre, alignements, bords nets, variantes recolorées. Aucune
constante propre à une planche dans ce script.

Étapes, pour chaque source :

1. détourage : fond « damier » (damier dessiné : gris et blancs neutres reliés au fond, poches de
   damier enfermées, cases collées au personnage, petites taches) ou « transparent » (vraie
   transparence, titres noirs retirés si `titres`) ;
2. découpe de chaque bande (zone en px de la source) en images, de gauche à droite : par
   composantes (`decoupe: "composantes"`, images fusionnées quand elles se chevauchent, petits
   éléments colorés rattachés) ou par colonnes vides (`decoupe: "colonnes"`) ;
3. ancre de chaque image (`ancre` : buste, pattes ou centre ; `sol` : bas des pixels sombres ou
   bas de l'image), alignement facultatif sur la tête ;
4. mise à l'échelle commune (hauteur de référence), bords nets facultatifs ;

puis rangement en planche compacte (une ligne par animation, dans `ordre`), PNG 256 couleurs et
JSON au format v1 (`docs/wordend-formats.md` §4.5).

Usage (Python 3.9+, pip install pillow numpy scipy) :

    python3 tools/wordend/decouper-planche.py tools/wordend/source/chtholly.planche.json \\
        [autre.planche.json …] --sortie <dossier> [--variante nephren] [--variante nom=teinte:40]

Écrit `<sortie>/<nom>.png` et `<sortie>/<nom>.planche.json` (+ une paire par variante).
Outil ponctuel : la CI ne l'exécute pas, les PNG et JSON produits sont versionnés dans
`wp-content/plugins/yume-core/includes/wordend/assets/univers/<univers>/`.
"""

import argparse
import json
import os
import sys

import numpy as np
from PIL import Image
from scipy import ndimage as nd

FORMAT = 'images : [x, y, largeur, hauteur, ancre x, ancre y] en px de la planche'

# Valeurs par défaut du détourage d'un fond en damier dessiné.
DAMIER_DEFAUT = {
    'saturation': 14,     # écart max entre canaux d'un pixel « neutre »
    'gris': [176, 214],   # cases grises : max des canaux dans cet intervalle
    'blanc': 232,         # cases blanches : max des canaux ≥ blanc
    'beige': None,        # {"min", "saturation"} : traits parasites clairs, rattachés au fond
    'poche': 40,          # taille min (px) d'une poche de damier enfermée (gris ET blanc ≥ 15 %)
    'clair': None,        # {"saturation", "min"} : zones claires neutres collées au fond = fond
    'tailleMin': 120,     # taches plus petites retirées
}

ANCRES = ('buste', 'pattes', 'centre')
SOLS = ('sombre', 'bas')
SOL_PAR_ANCRE = {'buste': 'sombre', 'pattes': 'bas', 'centre': 'bas'}


class ErreurDescription(Exception):
    """Description de planche invalide."""


# --- Détourage ---------------------------------------------------------------------------------


def masque_damier(a, reglages):
    """Masque du premier plan (True = sujet) d'une source RGB (int) à damier dessiné."""
    r = dict(DAMIER_DEFAUT, **reglages)
    mx = a.max(2)
    sat = mx - a.min(2)
    gris = (sat <= r['saturation']) & (mx >= r['gris'][0]) & (mx <= r['gris'][1])
    blanc = (sat <= r['saturation']) & (mx >= r['blanc'])
    candidats = gris | blanc
    if r['beige']:
        candidats = candidats | ((mx >= r['beige']['min']) & (sat <= r['beige']['saturation']) & ~blanc)
    lab, n = nd.label(candidats)
    idx = range(1, n + 1)
    tot = nd.sum(np.ones_like(lab), lab, idx)
    ng = nd.sum(gris, lab, idx)
    nb = nd.sum(blanc, lab, idx)
    fond = np.zeros(n + 1, bool)
    fond[int(np.argmax(tot)) + 1] = True
    for i in idx:
        t = tot[i - 1]
        # Poche de damier enfermée : elle contient les deux couleurs (une lueur blanche seule,
        # des dents blanches, sont gardées).
        if t > r['poche'] and ng[i - 1] > 0.15 * t and nb[i - 1] > 0.15 * t:
            fond[i] = True
    fond = fond[lab]
    if r['clair']:
        # Cases de damier collées au sujet (bruit JPEG, un peu hors des seuils) : zone claire et
        # neutre qui touche le fond = fond. Les vrais blancs (reflets, éclats bleutés) ne touchent
        # pas le fond ou ne sont pas neutres.
        clair = ~fond & (sat <= r['clair']['saturation']) & (mx >= r['clair']['min'])
        lab, n = nd.label(clair)
        bord = nd.binary_dilation(fond, iterations=1)
        touche = nd.maximum(bord, lab, range(1, n + 1))
        for i in range(1, n + 1):
            if touche[i - 1]:
                fond |= lab == i
    fg = nd.binary_opening(~fond, iterations=1)
    lab, n = nd.label(fg)
    tailles = nd.sum(fg, lab, range(1, n + 1))
    return np.isin(lab, 1 + np.where(tailles >= r['tailleMin'])[0])


def masque_transparent(rgba, seuil, titres):
    """Masque du premier plan d'une source à vraie transparence (alpha > seuil), sans les titres
    (composantes à plus de 60 % de noir neutre) si `titres`."""
    visible = rgba[..., 3] > seuil
    if titres:
        rgb = rgba[..., :3].astype(int)
        noir = (rgb.max(2) < 80) & (rgb.max(2) - rgb.min(2) < 25)
        lab, n = nd.label(visible)
        idx = range(1, n + 1)
        part_noire = nd.mean(noir, lab, idx)
        for i in idx:
            if part_noire[i - 1] > 0.6:
                visible[lab == i] = False
    return visible


# --- Ancres ------------------------------------------------------------------------------------


def calculer_ancre(rgb, masque, ancre, sol, sombre):
    """Ancre (x, y) en px de l'image : x selon `ancre`, y selon `sol`.

    - buste : médiane des pixels sombres (< `sombre`) entre 40 et 80 % de la hauteur (l'épée ou
      la queue ne décalent pas le sujet) ; milieu de l'image s'il n'y en a pas ;
    - pattes : médiane des pixels du quart inférieur ;
    - centre : milieu de l'image ;
    - sol « sombre » : bas des pixels sombres (bottes) ; « bas » : bas de l'image.
    """
    h, w = masque.shape
    fonce = masque & (rgb.max(2) < sombre)
    if ancre == 'buste':
        _, xs = np.nonzero(fonce[int(h * 0.4):int(h * 0.8)])
        ax = float(np.median(xs)) if len(xs) else w / 2
    elif ancre == 'pattes':
        _, xs = np.nonzero(masque[int(h * 0.75):])
        ax = float(np.median(xs)) if len(xs) else w / 2
    else:
        ax = w / 2
    if sol == 'sombre':
        ys = np.nonzero(fonce)[0]
        ay = float(ys.max() + 1) if len(ys) else float(h)
    else:
        ay = float(h)
    return ax, ay


# --- Découpe des bandes ------------------------------------------------------------------------


def zone_bande(bande, largeur):
    """(haut, bas, gauche, droite) d'une bande : `zone` = [haut, bas] ou [haut, bas, gauche, droite]."""
    z = bande['zone']
    return (z[0], z[1], 0, largeur) if len(z) == 2 else tuple(z)


def decouper_composantes(rgba, fg, bande, reglages):
    """Images d'une bande par composantes : les grandes (≥ `tailleImage`) donnent les images,
    fusionnées si elles se chevauchent ; les éléments proches sont rattachés s'ils sont grands ou
    petits mais colorés (pétales, papillon, étincelles). Renvoie [(rgba, masque)]."""
    y0, y1, x0, x1 = zone_bande(bande, fg.shape[1])
    m = fg[y0:y1, x0:x1]
    rgb = rgba[..., :3].astype(int)
    sat = (rgb.max(2) - rgb.min(2))[y0:y1, x0:x1]
    lab, n = nd.label(m)
    sl = nd.find_objects(lab)
    tailles = nd.sum(m, lab, range(1, n + 1))
    msat = nd.mean(sat, lab, range(1, n + 1))
    grande = reglages['tailleImage']
    boites = []
    for i in sorted((i for i in range(n) if tailles[i] >= grande), key=lambda i: sl[i][1].start):
        s = sl[i]
        b = [s[1].start, s[0].start, s[1].stop, s[0].stop]
        if boites and b[0] < boites[-1][2] - 10:
            p = boites[-1]
            boites[-1] = [min(p[0], b[0]), min(p[1], b[1]), max(p[2], b[2]), max(p[3], b[3])]
        else:
            boites.append(b)
    mg, mh, md, mb = bande.get('marges', reglages['marges'])
    sorties = []
    for b in boites:
        zone = [b[0] - mg, b[1] - mh, b[2] + md, b[3] + mb]
        garde = np.zeros_like(m)
        for i in range(n):
            s = sl[i]
            cx = (s[1].start + s[1].stop) / 2
            cy = (s[0].start + s[0].stop) / 2
            dedans = zone[0] <= cx <= zone[2] and zone[1] <= cy <= zone[3]
            colore = msat[i] > reglages['satellites']['saturation'] and tailles[i] >= reglages['satellites']['taille']
            if dedans and (tailles[i] >= grande or colore):
                garde |= lab == i + 1
        ys, xs = np.nonzero(garde)
        gx0, gx1, gy0, gy1 = xs.min(), xs.max() + 1, ys.min(), ys.max() + 1
        bloc = rgba[y0 + gy0:y0 + gy1, x0 + gx0:x0 + gx1].copy()
        masque = garde[gy0:gy1, gx0:gx1]
        bloc[..., 3] = masque * 255
        sorties.append((bloc, masque))
    return sorties


def colonnes_vides(m, ecart):
    """Segments (début, fin) de plus de `ecart` px séparés par des colonnes vides."""
    col = m.sum(0)
    segments, debut = [], None
    for x, v in enumerate(col):
        if v > 0 and debut is None:
            debut = x
        elif v == 0 and debut is not None:
            if x - debut > ecart:
                segments.append((debut, x))
            debut = None
    if debut is not None:
        segments.append((debut, len(col)))
    return segments


def decouper_colonnes(rgba, fg, bande, reglages):
    """Images d'une bande séparées par des colonnes vides (pixel art bien espacé)."""
    y0, y1, x0, x1 = zone_bande(bande, fg.shape[1])
    m = fg[y0:y1, x0:x1]
    sorties = []
    for c0, c1 in colonnes_vides(m, reglages['ecartColonnes']):
        zone = m[:, c0:c1]
        if zone.sum() < reglages['tailleImage']:
            continue
        ys, xs = np.nonzero(zone)
        gx0, gx1, gy0, gy1 = xs.min(), xs.max() + 1, ys.min(), ys.max() + 1
        bloc = rgba[y0 + gy0:y0 + gy1, x0 + c0 + gx0:x0 + c0 + gx1].copy()
        masque = zone[gy0:gy1, gx0:gx1]
        bloc[..., 3] = np.where(masque, bloc[..., 3], 0)
        sorties.append((bloc, masque))
    return sorties


DECOUPES = {
    'composantes': (decouper_composantes, {'tailleImage': 2500, 'marges': [12, 12, 12, 4],
                                           'satellites': {'saturation': 25, 'taille': 60}}),
    'colonnes': (decouper_colonnes, {'tailleImage': 600, 'ecartColonnes': 8}),
}


def aligner_tete(images):
    """Aligne le bout de la tête (colonne la plus à droite de la moitié haute) à distance
    constante de l'ancre, égale à la médiane des images : utile quand les pattes bougent trop
    pour servir d'ancre (course)."""
    tetes = [float(np.nonzero(rgba[: rgba.shape[0] // 2, :, 3] > 0)[1].max()) for rgba, _, _ in images]
    ecart = float(np.median([tete - ax for tete, (_, ax, _) in zip(tetes, images)]))
    return [(rgba, tete - ecart, ay) for tete, (rgba, _, ay) in zip(tetes, images)]


# --- Mise à l'échelle et écriture --------------------------------------------------------------


def redimensionner(rgba, echelle):
    """Redimensionnement Lanczos en alpha prémultiplié (pas de liseré sombre)."""
    if echelle == 1:
        return rgba
    h, w = rgba.shape[:2]
    taille = (max(1, round(w * echelle)), max(1, round(h * echelle)))
    pm = rgba.astype(float)
    pm[..., :3] *= pm[..., 3:] / 255
    r = np.asarray(Image.fromarray(pm.astype(np.uint8), 'RGBA').resize(taille, Image.LANCZOS)).astype(float)
    alpha = r[..., 3:]
    rgb = np.where(alpha > 0, np.clip(r[..., :3] * 255 / np.maximum(alpha, 1), 0, 255), 0)
    return np.dstack([rgb, alpha]).astype(np.uint8)


def ranger(images, ordre, rythmes):
    """Range les images (déjà à l'échelle) en planche compacte : (tableau RGBA, animations)."""
    placees, animations, y, largeur = [], {}, 0, 0
    for nom in ordre:
        x, hmax, cadres = 0, 0, []
        for r, ax, ay in images[nom]:
            h, w = r.shape[:2]
            placees.append((x, y, r))
            cadres.append([x, y, w, h, int(round(ax)), int(round(ay))])
            x += w + 2
            hmax = max(hmax, h)
        animations[nom] = dict(rythmes[nom], images=cadres)
        largeur = max(largeur, x)
        y += hmax + 2
    planche = Image.new('RGBA', (largeur, y))
    for x, yy, r in placees:
        planche.paste(Image.fromarray(r, 'RGBA'), (x, yy))
    return planche, animations


def ecrire(planche, meta, nom, sortie):
    """Écrit `<nom>.png` (256 couleurs) et `<nom>.planche.json` dans `sortie`."""
    image = planche.quantize(colors=256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    image.save(os.path.join(sortie, nom + '.png'), optimize=True)
    with open(os.path.join(sortie, nom + '.planche.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, ensure_ascii=False, separators=(',', ':'))
        f.write('\n')
    print('%s : planche %d × %d' % (nom, planche.width, planche.height))


# --- Variantes recolorées ----------------------------------------------------------------------


def recolorer(planche, variante):
    """Planche recolorée : rotation de teinte (`teinte` en degrés), facteurs `saturation` et
    `luminosite`, limitée aux pixels dont la teinte d'origine est dans `plage` ([min, max] en
    degrés, bornes incluses, intervalle circulaire si min > max) et assez saturés (`saturationMin`,
    0…255)."""
    rgba = np.asarray(planche)
    hsv = np.asarray(Image.fromarray(np.ascontiguousarray(rgba[..., :3]), 'RGB').convert('HSV')).astype(float)
    teinte_deg = hsv[..., 0] * 360 / 256
    choisis = rgba[..., 3] > 0
    if 'plage' in variante:
        pmin, pmax = variante['plage']
        dedans = (teinte_deg >= pmin) & (teinte_deg <= pmax) if pmin <= pmax else \
            (teinte_deg >= pmin) | (teinte_deg <= pmax)
        choisis &= dedans
    choisis &= hsv[..., 1] >= variante.get('saturationMin', 0)
    nouveau = hsv.copy()
    nouveau[..., 0] = (hsv[..., 0] + variante.get('teinte', 0) * 256 / 360) % 256
    nouveau[..., 1] = np.clip(hsv[..., 1] * variante.get('saturation', 1), 0, 255)
    nouveau[..., 2] = np.clip(hsv[..., 2] * variante.get('luminosite', 1), 0, 255)
    hsv = np.where(choisis[..., None], nouveau, hsv)
    rgb = np.asarray(Image.fromarray(np.round(hsv).astype(np.uint8), 'HSV').convert('RGB'))
    return Image.fromarray(np.dstack([rgb, rgba[..., 3]]), 'RGBA')


def lire_variante_cli(texte, description):
    """`--variante nom` (déclarée dans la description) ou `--variante nom=teinte:40,saturation:0.8`."""
    if '=' not in texte:
        variantes = description.get('variantes', {})
        if texte not in variantes:
            raise ErreurDescription('variante inconnue : %s (déclarées : %s)'
                                    % (texte, ', '.join(variantes) or 'aucune'))
        return texte, variantes[texte]
    nom, reglages = texte.split('=', 1)
    variante = {}
    for morceau in filter(None, reglages.split(',')):
        cle, _, valeur = morceau.partition(':')
        if cle == 'plage':
            variante[cle] = [float(v) for v in valeur.split('/')]
        else:
            variante[cle] = float(valeur)
    return nom, variante


# --- Description -------------------------------------------------------------------------------


def verifier_description(d, chemin):
    """Contrôles de forme de la description (messages en français)."""
    def exiger(condition, message):
        if not condition:
            raise ErreurDescription('%s : %s' % (chemin, message))

    exiger(isinstance(d.get('sources'), list) and d['sources'], '« sources » doit être une liste non vide')
    exiger(isinstance(d.get('rythmes'), dict), '« rythmes » manquant')
    noms = []
    for i, s in enumerate(d['sources']):
        exiger(s.get('fond', 'damier') in ('damier', 'transparent'), 'source %d : fond « damier » ou « transparent »' % i)
        exiger(s.get('decoupe', 'composantes') in DECOUPES, 'source %d : découpe inconnue' % i)
        exiger(s.get('ancre', d.get('ancre', 'buste')) in ANCRES, 'source %d : ancre inconnue' % i)
        exiger(isinstance(s.get('bandes'), dict) and s['bandes'], 'source %d : « bandes » manquant' % i)
        for nom, b in s['bandes'].items():
            exiger(len(b.get('zone', [])) in (2, 4), 'bande « %s » : zone [haut, bas] ou [haut, bas, gauche, droite]' % nom)
            exiger(b.get('ancre', 'centre') in ANCRES, 'bande « %s » : ancre inconnue' % nom)
            exiger(b.get('sol', 'bas') in SOLS, 'bande « %s » : sol « sombre » ou « bas »' % nom)
            exiger(nom not in noms, 'animation « %s » décrite deux fois' % nom)
            noms.append(nom)
    for nom in d.get('ordre', noms):
        exiger(nom in noms, 'animation « %s » de « ordre » sans bande' % nom)
        exiger(nom in d['rythmes'], 'animation « %s » sans rythme' % nom)
    for nom, mode in d.get('alignement', {}).items():
        exiger(nom in noms and mode == 'tete', 'alignement « %s » : animation connue et mode « tete »' % nom)
    exiger(isinstance(d.get('reference'), list) and d['reference'], '« reference » : animations donnant la hauteur')


def charger_description(chemin):
    with open(chemin, encoding='utf-8') as f:
        d = json.load(f)
    verifier_description(d, chemin)
    return d


def lire_source(s, dossier, ancre_defaut):
    """Images brutes d'une source : {animation: [(rgba, ax, ay)]} en px de la source."""
    fichier = os.path.join(dossier, s['fichier'])
    image = Image.open(fichier)
    if s.get('fond', 'damier') == 'damier':
        a = np.asarray(image.convert('RGB')).astype(int)
        fg = masque_damier(a, s.get('damier', {}))
        rgba = np.dstack([a, fg * 255]).astype(np.uint8)
    else:
        rgba = np.asarray(image.convert('RGBA'))
        fg = masque_transparent(rgba, s.get('seuilAlpha', 128), s.get('titres', False))
    methode = s.get('decoupe', 'composantes')
    fonction, defauts = DECOUPES[methode]
    reglages = dict(defauts, **s.get('reglages', {}))
    ancre_source = s.get('ancre', ancre_defaut)
    sombre = s.get('sombre', 75)
    images = {}
    for nom, bande in s['bandes'].items():
        ancre = bande.get('ancre', ancre_source)
        sol = bande.get('sol', s.get('sol', SOL_PAR_ANCRE[ancre_source]))
        brutes = []
        for bloc, masque in fonction(rgba, fg, bande, reglages):
            ax, ay = calculer_ancre(bloc[..., :3].astype(int), masque, ancre, sol, sombre)
            brutes.append((bloc, ax, ay))
        if 'garder' in bande:
            try:
                brutes = [brutes[i] for i in bande['garder']]
            except IndexError:
                raise ErreurDescription('bande « %s » : « garder » hors des %d images trouvées' % (nom, len(brutes)))
        if not brutes:
            raise ErreurDescription('bande « %s » : aucune image trouvée' % nom)
        images[nom] = brutes
    return images


def construire(d, dossier):
    """Planche (Image RGBA) et méta JSON d'une description."""
    sources = [lire_source(s, dossier, d.get('ancre', 'buste')) for s in d['sources']]
    alignement = d.get('alignement', {})
    for images in sources:
        for nom in images:
            if alignement.get(nom) == 'tete':
                images[nom] = aligner_tete(images[nom])

    # Hauteur de référence : médiane des ancres (y) des animations de « reference » ; mise à
    # l'échelle de chaque source pour que cette hauteur vaille « hauteur » (ou celle de la
    # première source qui a ces animations, gardée telle quelle si « hauteur » est absent).
    reference = d['reference']

    def hauteur_reference(images):
        ays = [ay for nom in reference if nom in images for _, _, ay in images[nom]]
        return float(np.median(ays)) if ays else None

    cible = d.get('hauteur')
    echelles = [None] * len(sources)
    if cible is None:
        for i, images in enumerate(sources):
            h = hauteur_reference(images)
            if h is not None:
                cible, echelles[i] = h, 1
                break
        if cible is None:
            raise ErreurDescription('aucune source ne contient les animations de « reference »')
    for i, (s, images) in enumerate(zip(d['sources'], sources)):
        if echelles[i] is not None:
            continue
        if 'referenceHauteur' in s:
            nom, indice = s['referenceHauteur']
            if nom not in images or not -len(images[nom]) <= indice < len(images[nom]):
                raise ErreurDescription('source %d : « referenceHauteur » %s introuvable' % (i, [nom, indice]))
            echelles[i] = cible / images[nom][indice][2]
        else:
            h = hauteur_reference(images)
            if h is None:
                raise ErreurDescription('source %d : ni animation de « reference » ni « referenceHauteur »' % i)
            echelles[i] = cible / h

    nets = set(d.get('bordsNets', []))
    finales = {}
    for images, echelle in zip(sources, echelles):
        for nom, imgs in images.items():
            finales[nom] = []
            for rgba, ax, ay in imgs:
                r = redimensionner(rgba, echelle)
                if nom in nets:
                    r[..., 3] = np.where(r[..., 3] > 128, 255, 0)
                finales[nom].append((r, ax * echelle, ay * echelle))

    ordre = d.get('ordre') or [nom for s in d['sources'] for nom in s['bandes']]
    planche, animations = ranger(finales, ordre, d['rythmes'])
    meta = {
        'version': 1,
        'echelle': d.get('echelle', 2),
        'planche': [planche.width, planche.height],
        'format': FORMAT,
        'animations': animations,
    }
    return planche, meta, echelles


def main():
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('descriptions', nargs='+', help='fichiers tools/wordend/source/<nom>.planche.json')
    p.add_argument('--sortie', required=True, help='dossier où écrire <nom>.png et <nom>.planche.json')
    p.add_argument('--variante', action='append', default=[],
                   help='variante recolorée : nom déclaré dans « variantes », ou nom=teinte:40[,saturation:0.8,'
                        'luminosite:1.1,plage:180/260,saturationMin:40] (répétable)')
    p.add_argument('--toutes-variantes', action='store_true', help='produit aussi toutes les variantes déclarées')
    args = p.parse_args()
    os.makedirs(args.sortie, exist_ok=True)
    try:
        for chemin in args.descriptions:
            d = charger_description(chemin)
            nom = d.get('sortie') or os.path.basename(chemin).split('.')[0]
            demandees = [lire_variante_cli(v, d) for v in args.variante]
            if args.toutes_variantes:
                demandees += list(d.get('variantes', {}).items())
            planche, meta, echelles = construire(d, os.path.dirname(os.path.abspath(chemin)))
            ecrire(planche, meta, nom, args.sortie)
            print('  échelles des sources : ' + ', '.join('%.3f' % e for e in echelles))
            for nom_variante, variante in demandees:
                ecrire(recolorer(planche, variante), meta, nom_variante, args.sortie)
    except (ErreurDescription, OSError, ValueError, KeyError) as e:
        print('Erreur : %s' % e, file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
