#!/usr/bin/env python3
"""Découpe les planches générées (Chtholly, Timere) en planches de jeu WordEnd.

Chtholly (source JPG de Gemini) : le « fond transparent » est un damier dessiné (cases
d'environ 12 px, blanc et gris ~194) avec un titre texte par ligne. Le script :

1. détoure le damier : pixels gris neutres ou blancs reliés au fond, plus les poches de
   damier enfermées (qui contiennent les deux couleurs : une lueur blanche seule est gardée) ;
2. retire les petites taches (bruit JPEG, titres) et découpe chaque image par ligne ;
3. calcule l'ancre de chaque image : milieu du buste (pixels sombres de l'uniforme, entre
   40 et 80 % de la hauteur), bas des bottes ; l'épée ne décale donc pas le personnage ;
4. met toutes les images à la même échelle (Chtholly debout = HAUTEUR px), les range en
   planche compacte (une ligne par animation) et écrit le PNG (256 couleurs) et le JSON.

Timere (source WebP) : fond noir uni, titres blancs. Les titres sont retirés, le fond noir
relié au bord devient transparent et la frange est « démélangée » du noir (alpha tiré de la
luminosité, couleur divisée par l'alpha). Les images qui se chevauchent (pattes, coups de
fouet) sont séparées aux colonnes les moins remplies. Ancre : milieu des pattes, au sol.

Usage (Python 3.9+, pip install pillow numpy scipy) :

    python3 tools/wordend/decouper-planche.py [chtholly|timere ...] \
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

SOURCES = os.path.join(RACINE, 'tools/wordend/source')

# Hauteur de Chtholly debout dans la planche de sortie (px). Le jeu dessine en 2× : 144 px
# dans la planche = 72 px logiques sur l'écran de 480 × 270.
HAUTEUR = 144

# Hauteur d'un Timere au repos dans la planche (px) : 112 px = 56 px logiques à la taille 1
# (le jeu agrandit ou réduit selon le type de Timere).
HAUTEUR_TIMERE = 112

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


def ecrire_planche(brutes, echelle, rythmes, nom_fichier, sortie):
    """Range les images (déjà découpées) en planche compacte et écrit le PNG et le JSON."""
    placees, animations, y, largeur = [], {}, 0, 0
    for nom, imgs in brutes.items():
        x, hmax, cadres = 0, 0, []
        for rgba, ax, ay in imgs:
            r = redimensionner(rgba, echelle)
            h, w = r.shape[:2]
            placees.append((x, y, r))
            cadres.append([x, y, w, h, int(round(ax * echelle)), int(round(ay * echelle))])
            x += w + 2
            hmax = max(hmax, h)
        animations[nom] = dict(rythmes[nom], images=cadres)
        largeur = max(largeur, x)
        y += hmax + 2

    planche = Image.new('RGBA', (largeur, y))
    for x, yy, r in placees:
        planche.paste(Image.fromarray(r, 'RGBA'), (x, yy))
    planche = planche.quantize(colors=256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    planche.save(os.path.join(sortie, nom_fichier + '.png'), optimize=True)
    meta = {
        'version': 1,
        'echelle': 2,
        'planche': [largeur, y],
        'format': 'images : [x, y, largeur, hauteur, ancre x, ancre y] en px de la planche',
        'animations': animations,
    }
    with open(os.path.join(sortie, nom_fichier + '.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, ensure_ascii=False, separators=(',', ':'))
        f.write('\n')
    print('%s : planche %d × %d, échelle %.3f' % (nom_fichier, largeur, y, echelle))


def chtholly(sortie):
    """Planche de Chtholly (JPG de Gemini à damier dessiné)."""
    a = np.asarray(Image.open(os.path.join(SOURCES, 'chtholly-planche-gemini.jpg')).convert('RGB')).astype(int)
    fg = detourer(a)
    brutes = {}
    for nom, (bande, garder) in BANDES.items():
        imgs = images_bande(a, fg, nom, bande)
        brutes[nom] = [imgs[i] for i in garder] if garder else imgs
    echelle = HAUTEUR / float(np.median([ay for _, _, ay in brutes['repos']] + [ay for _, _, ay in brutes['marche']]))
    ecrire_planche(brutes, echelle, RYTHMES, 'chtholly', sortie)


# Timere : titres (haut, bas) à effacer, bandes (haut, bas) et coupes des images qui se
# chevauchent (colonnes, en px source ; None = colonnes vides).
TIMERE_TITRES = [(0, 40), (200, 234), (372, 407), (520, 555), (698, 732), (864, 898), (1016, 1046)]
TIMERE_BANDES = {
    'repos': ((10, 200), None),
    'marche': ((232, 372), None),
    'course': ((400, 520), [23, 260, 504, 680, 901, 1049, 1275]),
    'fouet': ((545, 692), [24, 196, 385, 542, 742, 930, 1116, 1282]),
    'morsure': ((728, 864), None),
    'degats': ((893, 1015), None),
    'mort': ((1040, 1172), None),
}
TIMERE_RYTHMES = {
    'repos': {'ips': 7, 'boucle': True},
    'marche': {'ips': 9, 'boucle': True},
    'course': {'ips': 13, 'boucle': True},
    'fouet': {'ips': 12, 'boucle': False, 'coup': [3, 4]},
    'morsure': {'ips': 12, 'boucle': False, 'coup': [2, 3, 4]},
    'degats': {'ips': 14, 'boucle': False},
    'mort': {'ips': 9, 'boucle': False},
}


def colonnes_vides(m):
    """Coupes aux colonnes vides d'une bande : [début, fin, début, fin…] fusionnées en bornes."""
    col = m.sum(0)
    segments, debut = [], None
    for x, v in enumerate(col):
        if v > 0 and debut is None:
            debut = x
        elif v == 0 and debut is not None:
            if x - debut > 8:
                segments.append((debut, x))
            debut = None
    if debut is not None:
        segments.append((debut, len(col)))
    return segments


# Style « pixel art vert » (harmonisé avec la planche verte de Gemini) : rampe de verts du plus
# sombre au plus clair, contour et yeux.
TIMERE_CONTOUR = (26, 36, 20)
TIMERE_RAMPE = [(33, 48, 24), (54, 79, 35), (80, 108, 47), (110, 138, 63), (150, 172, 92)]
TIMERE_YEUX = (236, 226, 110)
TIMERE_PIXEL = 2  # Taille d'un « pixel » du dessin dans la planche (2 = 1 px logique).


def styliser_timere(brutes, hauteur):
    """Ramène chaque image à la taille logique, la redessine en pixel art vert (rampe de
    luminosité, contour sombre, yeux jaunes), puis l'agrandit au plus proche (TIMERE_PIXEL)."""
    echelle = hauteur / TIMERE_PIXEL / float(np.median([ay for _, _, ay in brutes['repos']]))
    petites = {}
    lums = []
    for nom, imgs in brutes.items():
        petites[nom] = []
        for rgba, ax, ay in imgs:
            r = redimensionner(rgba, echelle)
            # Yeux repérés en pleine résolution (orangés et lumineux), puis réduits.
            src = rgba[..., :3].astype(int)
            yeux = (rgba[..., 3] > 128) & (src.max(2) - src.min(2) > 70) & (src[..., 0] > 140) & (src[..., 1] > 90)
            masque = Image.fromarray((yeux * 255).astype(np.uint8)).resize((r.shape[1], r.shape[0]), Image.BOX)
            r = np.dstack([r, np.asarray(masque)])
            petites[nom].append((r, ax * echelle, ay * echelle))
            a = r[..., 3] > 115
            lums.append(r[..., :3].astype(float).mean(2)[a])
    seuils = np.percentile(np.concatenate(lums), [30, 56, 80, 94])
    sorties = {}
    for nom, imgs in petites.items():
        sorties[nom] = []
        for r, ax, ay in imgs:
            rgb = r[..., :3].astype(int)
            plein = r[..., 3] > 115
            lum = rgb.mean(2)
            niveau = np.digitize(lum, seuils)
            couleurs = np.array(TIMERE_RAMPE)[niveau]
            yeux = plein & (r[..., 4] > 25)
            couleurs[yeux] = TIMERE_YEUX
            interieur = nd.binary_erosion(plein, iterations=1, border_value=0)
            contour = plein & ~interieur
            couleurs[contour & ~yeux] = TIMERE_CONTOUR
            out = np.dstack([couleurs, plein * 255]).astype(np.uint8)
            out = out.repeat(TIMERE_PIXEL, 0).repeat(TIMERE_PIXEL, 1)
            sorties[nom].append((out, ax * TIMERE_PIXEL, ay * TIMERE_PIXEL))
    return sorties


def timere(sortie):
    """Planche du Timere (WebP à fond noir uni)."""
    a = np.asarray(Image.open(os.path.join(SOURCES, 'timere-planche.webp')).convert('RGB')).astype(int)
    mx = a.max(2)
    fg = mx > 12
    # Titres : composantes entièrement dans une bande de titre, à gauche.
    lab, n = nd.label(fg)
    for i, s in enumerate(nd.find_objects(lab)):
        for y0, y1 in TIMERE_TITRES:
            if s[0].start >= y0 and s[0].stop <= y1 and s[1].stop <= 400:
                fg[lab == i + 1] = False
    # Fond : noir relié au bord, ou poche noire assez grande (entre les pattes).
    noir = ~fg
    lab, n = nd.label(noir)
    tailles = nd.sum(noir, lab, range(1, n + 1))
    bord = set(np.unique(np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]]))) - {0}
    fond = np.zeros(n + 1, bool)
    for i in range(1, n + 1):
        fond[i] = i in bord or tailles[i - 1] >= 40
    fond = fond[lab] | ~(fg | noir)
    # Alpha : 1 à l'intérieur ; frange (2 px du fond) tirée de la luminosité.
    alpha = np.where(fond, 0.0, 1.0)
    frange = (~fond) & nd.binary_dilation(fond, iterations=2)
    alpha[frange] = np.clip(mx[frange] / 70.0, 0, 1)
    rgb = np.clip(a / np.maximum(alpha, 1e-3)[..., None], 0, 255)
    rgba = np.dstack([np.where(alpha[..., None] > 0, rgb, 0), alpha * 255]).astype(np.uint8)
    visible = alpha > 0.05

    brutes = {}
    for nom, ((y0, y1), coupes) in TIMERE_BANDES.items():
        m = visible[y0:y1]
        bornes = list(zip(coupes[:-1], coupes[1:])) if coupes else colonnes_vides(m)
        imgs = []
        for x0, x1 in bornes:
            zone = m[:, x0:x1]
            if zone.sum() < 800:
                continue
            ys, xs = np.nonzero(zone)
            gx0, gx1, gy0, gy1 = xs.min(), xs.max() + 1, ys.min(), ys.max() + 1
            bloc = rgba[y0 + gy0:y0 + gy1, x0 + gx0:x0 + gx1].copy()
            bloc[..., 3] = np.where(zone[gy0:gy1, gx0:gx1], bloc[..., 3], 0)
            h = gy1 - gy0
            _, xs2 = np.nonzero(zone[gy0 + int(h * 0.75):gy1, gx0:gx1])
            ax = float(np.median(xs2)) if len(xs2) else (gx1 - gx0) / 2
            imgs.append((bloc, ax, float(h)))
        brutes[nom] = imgs
    ecrire_planche(styliser_timere(brutes, HAUTEUR_TIMERE), 1, TIMERE_RYTHMES, 'timere', sortie)


def main():
    p = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    p.add_argument('planches', nargs='*', help='chtholly, timere (défaut : les deux)')
    p.add_argument('--sortie', default=os.path.join(RACINE, 'wp-content/plugins/yume-core/includes/wordend/assets'))
    args = p.parse_args()
    for nom in args.planches or ['chtholly', 'timere']:
        if nom not in ('chtholly', 'timere'):
            p.error('planche inconnue : ' + nom)
        {'chtholly': chtholly, 'timere': timere}[nom](args.sortie)


if __name__ == '__main__':
    main()
