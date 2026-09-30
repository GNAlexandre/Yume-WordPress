# Planches de sprites (easter egg WordEnd)

Outil ponctuel : découpe les planches générées (Chtholly, Timere) en planches de jeu. La CI ne
l'exécute pas ; les PNG et JSON produits sont versionnés dans
`wp-content/plugins/yume-core/includes/wordend/assets/`.

```sh
pip install pillow numpy scipy
python3 tools/wordend/decouper-planche.py            # les deux planches
python3 tools/wordend/decouper-planche.py timere     # une seule
```

Sources dans `tools/wordend/source/` : `chtholly-planche-gemini.jpg`, `timere-planche-verte.webp`
(fonds en damier dessiné) et `timere-planche-complement.webp` (vraie transparence). Pour le Timere,
seules les lignes Repos, Marche, Attaque Fouet et Attaque Morsure de la planche verte sont gardées ;
Course, Dégâts et Mort viennent de la planche complémentaire. Option `--sortie` : autre dossier.

Si une nouvelle planche a une autre disposition, adapter en tête du script `BANDES`/`RYTHMES`
(Chtholly) ou `TIMERE_VERTE`/`TIMERE_COMPLEMENT`/`TIMERE_RYTHMES` (Timere : bandes en px des
sources). Vérifier ensuite le résultat dans le jeu. Format du JSON et fonctionnement : `docs/wordend.md`.
