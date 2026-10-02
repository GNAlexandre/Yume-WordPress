# Guide de l'équipe en Word

Produit `docs/guide-equipe/Guide-equipe-Yume-Novel.docx`, le guide à distribuer à l'équipe.

1. Modifier le texte dans `docs/guide-equipe/procedures.md` (source unique ; syntaxe en tête du fichier). Les noms de boutons s'écrivent comme dans le code.
2. Une maquette ou un écran change : mettre à jour `docs/guide-equipe/maquettes/` (et `captures.json` : recadrages, libellés alignés sur le code), puis `node tools/guide/captures.js` (Chromium : celui de Playwright, sinon `CHROMIUM=/chemin/chrome` ou le plus récent de `PLAYWRIGHT_BROWSERS_PATH`, par défaut `/opt/pw-browsers` ; jamais `playwright install`).
3. Construire : `npm ci --prefix tools/guide` (une fois), puis `node tools/guide/construire.js`. Version du guide = version du plugin + date. Avec LibreOffice, `pdftotext` et la police Carlito (`fonts-crosextra-carlito`), le sommaire est paginé ; sinon Word le met à jour à l'ouverture.
4. Vérifier : `node tools/guide/construire.js --verifier` (fait par la CI, job « Guide de l'équipe ») échoue si le DOCX ne correspond plus à `procedures.md`, à ses images ou à `construire.js` (empreinte SHA-256 dans les propriétés du document).
5. Commiter ensemble `procedures.md`, les images et le DOCX.
