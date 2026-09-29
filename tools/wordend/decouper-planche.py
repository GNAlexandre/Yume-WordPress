#!/usr/bin/env python3
"""Découpe la planche de Chtholly (générée par Gemini) en planche de jeu WordEnd.

La planche source est un JPG dont le « fond transparent » est un damier dessiné (cases
d'environ 12 px, blanc et gris ~194) avec un titre texte par ligne. Le script :

1. détoure le damier : pixels gris neutres ou blancs reliés au fond, plus les poches de
   damier enfermées (qui contiennent les deux couleurs : une lueur blanche seule est gardée) ;
2. retire les petites taches (bruit JPEG, titres) et découpe chaque image par ligne ;
3. calcule l'ancre de chaque image : milieu du buste (pixels sombres de l'uniforme, entre
   40 et 80 % de la hauteur), bas des bottes ; l'épée ne décale donc pas le personnage ;
4. met toutes les images à la même échelle (Chtholly debout = HAUTEUR px), les range en
   planche compacte (une ligne par animation) et écrit le PNG (256 couleurs) et le JSON.

Usage (Python 3.9+, pip install pillow numpy scipy) :

    python3 tools/wordend/decouper-planche.py \
        [--source tools/wordend/source/chtholly-planche-gemini.jpg] \
        [--sortie wp-content/plugins/yume-core/includes/wordend/assets]

Outil ponctuel : la CI ne l'exécute pas, le PNG et le JSON générés sont versionnés.
"""

import argparse
import json
import os

import numpy as np
from PIL import Image
from scipy import ndimage as nd

RACINE = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Hauteur de Chtholly debout dans la planche de sortie (px). Le jeu dessine en 2× : 144 px
# dans la planche = 72 px logiques sur l'écran de 480 × 270.
HAUTEUR = 144

# Bandes de la planche source : (haut, bas, gauche, droite) en px, et images à garder.
BANDES = {
    'repos': ((80, 320, 0, 1411), [0, 1]),  # Les deux vues de dos sont écartées.
    'marche': ((390, 630, 0, 1411), None),
    'course': ((700, 940, 0, 1411), None),
    'attaque': ((1040, 1285, 0, 1411), None),
    'charge': ((1380, 1630, 0, 1411), None),
    'degats': ((1715, 1970, 0, 420), None),
    'mort': ((1730, 1990, 440, 900), None),
}

# Rythme (images par seconde), boucle, et repères de jeu par animation.
RYTHMES = {
    'repos': {'ips': 2, 'boucle': True},
    'marche': {'ips': 10, 'boucle': True},
    'course': {'ips': 14, 'boucle': True},
    'attaque': {'ips': 14, 'boucle': False, 'coup': [1, 2, 3]},
    'charge': {'ips': 10, 'boucle': False, 'onde': 3},
    'degats': {'ips': 1, 'boucle': False},
    'mort': {'ips': 1, 'boucle': False},
}


def detourer(a):
    """Masque du premier plan (True = personnage)."""
    mx = a.max(2)
    sat = mx - a.min(2)
    gris = (sat <= 14) & (mx >= 176) & (mx <= 214)
    blanc = (sat <= 14) & (mx >= 232)
    lab, n = nd.label(gris | blanc)
    idx = range(1, n + 1)
    tot = nd.sum(np.ones_like(lab), lab, idx)
    ng = nd.sum(gris, lab, idx)
    nb = nd.sum(blanc, lab, idx)
    fond = np.zeros(n + 1, bool)
    fond[int(np.argmax(tot)) + 1] = True
    for i in idx:
        t = tot[i - 1]
        if t > 40 and ng[i - 1] > 0.15 * t and nb[i - 1] > 0.15 * t:
            fond[i] = True
    fg = nd.binary_opening(~fond[lab], iterations=1)
    lab, n = nd.label(fg)
    tailles = nd.sum(fg, lab, range(1, n + 1))
    return np.isin(lab, 1 + np.where(tailles >= 120)[0])


def images_bande(a, fg, nom, bande):
    """Images RGBA d'une bande, de gauche à droite, avec leur ancre (x, y) en px source."""
    y0, y1, x0, x1 = bande
    m = fg[y0:y1, x0:x1]
    sat = (a.max(2) - a.min(2))[y0:y1, x0:x1]
    lab, n = nd.label(m)
    sl = nd.find_objects(lab)
    tailles = nd.sum(m, lab, range(1, n + 1))
    msat = nd.mean(sat, lab, range(1, n + 1))
    boites = []
    for i in sorted((i for i in range(n) if tailles[i] >= 2500), key=lambda i: sl[i][1].start):
        s = sl[i]
        b = [s[1].start, s[0].start, s[1].stop, s[0].stop]
        if boites and b[0] < boites[-1][2] - 10:
            p = boites[-1]
            boites[-1] = [min(p[0], b[0]), min(p[1], b[1]), max(p[2], b[2]), max(p[3], b[3])]
        else:
            boites.append(b)
    sorties = []
    for b in boites:
        marge = 40 if nom == 'mort' else 12
        zone = [b[0] - marge, b[1] - (90 if nom == 'mort' else marge), b[2] + marge, b[3] + 4]
        garde = np.zeros_like(m)
        for i in range(n):
            s = sl[i]
            cx = (s[1].start + s[1].stop) / 2
            cy = (s[0].start + s[0].stop) / 2
            dedans = zone[0] <= cx <= zone[2] and zone[1] <= cy <= zone[3]
            # Grandes composantes, ou petites mais colorées (pétales, papillon, étincelles).
            if dedans and (tailles[i] >= 2500 or (msat[i] > 25 and tailles[i] >= 60)):
                garde |= lab == i + 1
        ys, xs = np.nonzero(garde)
        gx0, gx1, gy0, gy1 = xs.min(), xs.max() + 1, ys.min(), ys.max() + 1
        rgb = a[y0 + gy0:y0 + gy1, x0 + gx0:x0 + gx1]
        alpha = garde[gy0:gy1, gx0:gx1]
        h = gy1 - gy0
        sombre = alpha & (rgb.max(2) < 75)
        _, xs2 = np.nonzero(sombre[int(h * 0.4):int(h * 0.8)])
        ax = alpha.shape[1] / 2 if nom == 'mort' or not len(xs2) else float(np.median(xs2))
        ay = float(np.nonzero(sombre)[0].max() + 1)
        sorties.append((np.dstack([rgb, alpha * 255]).astype(np.uint8), ax, ay))
    return sorties


def redimensionner(rgba, echelle):
    """Redimensionnement Lanczos en alpha prémultiplié (pas de liseré sombre)."""
    h, w = rgba.shape[:2]
    taille = (max(1, round(w * echelle)), max(1, round(h * echelle)))
    pm = rgba.astype(float)
    pm[..., :3] *= pm[..., 3:] / 255
    r = np.asarray(Image.fromarray(pm.astype(np.uint8), 'RGBA').resize(taille, Image.LANCZOS)).astype(float)
    alpha = r[..., 3:]
    rgb = np.where(alpha > 0, np.clip(r[..., :3] * 255 / np.maximum(alpha, 1), 0, 255), 0)
    return np.dstack([rgb, alpha]).astype(np.uint8)


def main():
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('--source', default=os.path.join(RACINE, 'tools/wordend/source/chtholly-planche-gemini.jpg'))
    p.add_argument('--sortie', default=os.path.join(RACINE, 'wp-content/plugins/yume-core/includes/wordend/assets'))
    args = p.parse_args()

    a = np.asarray(Image.open(args.source).convert('RGB')).astype(int)
    fg = detourer(a)
    brutes = {}
    for nom, (bande, garder) in BANDES.items():
        imgs = images_bande(a, fg, nom, bande)
        brutes[nom] = [imgs[i] for i in garder] if garder else imgs
    echelle = HAUTEUR / float(np.median([ay for _, _, ay in brutes['repos']] + [ay for _, _, ay in brutes['marche']]))

    placees, animations, y, largeur = [], {}, 0, 0
    for nom, imgs in brutes.items():
        x, hmax, cadres = 0, 0, []
        for rgba, ax, ay in imgs:
            r = redimensionner(rgba, echelle)
            h, w = r.shape[:2]
            placees.append((x, y, r))
            cadres.append([x, y, w, h, round(ax * echelle), round(ay * echelle)])
            x += w + 2
            hmax = max(hmax, h)
        animations[nom] = dict(RYTHMES[nom], images=cadres)
        largeur = max(largeur, x)
        y += hmax + 2

    planche = Image.new('RGBA', (largeur, y))
    for x, yy, r in placees:
        planche.paste(Image.fromarray(r, 'RGBA'), (x, yy))
    planche = planche.quantize(colors=256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    planche.save(os.path.join(args.sortie, 'chtholly.png'), optimize=True)
    meta = {
        'version': 1,
        'echelle': 2,
        'planche': [largeur, y],
        'format': 'images : [x, y, largeur, hauteur, ancre x, ancre y] en px de la planche',
        'animations': animations,
    }
    with open(os.path.join(args.sortie, 'chtholly.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, ensure_ascii=False, separators=(',', ':'))
        f.write('\n')
    print('Planche %d × %d, échelle %.3f' % (largeur, y, echelle))


if __name__ == '__main__':
    main()
