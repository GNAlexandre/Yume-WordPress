# Planche de Chtholly (easter egg WordEnd)

Outil ponctuel : découpe la planche générée par Gemini en planche de jeu. La CI ne l'exécute pas ;
le PNG et le JSON produits sont versionnés dans
`wp-content/plugins/yume-core/includes/wordend/assets/`.

```sh
pip install pillow numpy scipy
python3 tools/wordend/decouper-planche.py
```

Options : `--source` (défaut `tools/wordend/source/chtholly-planche-gemini.jpg`), `--sortie`
(défaut : dossier `assets/` du module).

Si une nouvelle planche a une autre disposition, adapter `BANDES` (lignes de la source, en px) et
`RYTHMES` (images par seconde, images de coup) en tête du script, puis vérifier le résultat dans le
jeu. Format du JSON et fonctionnement : `docs/wordend.md`.
