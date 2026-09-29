# Planches de sprites (easter egg WordEnd)

Outil ponctuel : découpe les planches générées (Chtholly, Timere) en planches de jeu. La CI ne
l'exécute pas ; les PNG et JSON produits sont versionnés dans
`wp-content/plugins/yume-core/includes/wordend/assets/`.

```sh
pip install pillow numpy scipy
python3 tools/wordend/decouper-planche.py            # les deux planches
python3 tools/wordend/decouper-planche.py timere     # une seule
```

Sources dans `tools/wordend/source/` : `chtholly-planche-gemini.jpg` (fond en damier dessiné) et
`timere-planche.webp` (fond noir, restylé en pixel art vert). Option `--sortie` : autre dossier.

Si une nouvelle planche a une autre disposition, adapter en tête du script `BANDES`/`RYTHMES`
(Chtholly) ou `TIMERE_TITRES`/`TIMERE_BANDES`/`TIMERE_RYTHMES` (Timere : bandes et coupes en px de
la source), et pour le style du Timere `TIMERE_RAMPE`, `TIMERE_CONTOUR`, `TIMERE_YEUX`. Vérifier
ensuite le résultat dans le jeu. Format du JSON et fonctionnement : `docs/wordend.md`.
