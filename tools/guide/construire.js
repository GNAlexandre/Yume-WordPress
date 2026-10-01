#!/usr/bin/env node
/**
 * Construit le guide de l'équipe en Word à partir de sa source.
 *
 *   node tools/guide/construire.js              construit docs/guide-equipe/Guide-equipe-Yume-Novel.docx
 *   node tools/guide/construire.js --verifier   échoue si le DOCX n'est pas à jour (CI)
 *   node tools/guide/construire.js --sans-pages construit sans LibreOffice (sommaire sans numéros de page)
 *
 * Source : docs/guide-equipe/procedures.md (syntaxe décrite en tête du fichier) et les images
 * qu'il cite (docs/guide-equipe/images, produites par tools/guide/captures.js).
 *
 * Version du guide : version du plugin (en-tête de wp-content/plugins/yume-core/yume-core.php) et
 * date de construction, en pied de page et dans les propriétés du document.
 *
 * Empreinte : SHA-256 de procedures.md, des images citées et de ce script, écrite dans la propriété
 * personnalisée « EmpreinteSources » du DOCX. --verifier la recalcule et la compare : il suffit de
 * Node et des dépendances de tools/guide (ni Chromium ni LibreOffice). La version du plugin n'entre
 * pas dans l'empreinte (elle change à chaque commit) : reconstruire le guide quand son texte ou ses
 * images changent.
 *
 * Numéros de page du sommaire : si LibreOffice (soffice) et pdftotext sont installés, une première
 * construction est convertie en PDF pour relever la page de chaque titre ; sinon le sommaire n'a
 * que les titres et Word propose de le mettre à jour à l'ouverture.
 */
'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const crypto = require( 'crypto' );
const { execFileSync } = require( 'child_process' );

const RACINE = path.resolve( __dirname, '..', '..' );
const DOSSIER = path.join( RACINE, 'docs', 'guide-equipe' );
const SOURCE = path.join( DOSSIER, 'procedures.md' );
const SORTIE = path.join( DOSSIER, 'Guide-equipe-Yume-Novel.docx' );
const PLUGIN = path.join( RACINE, 'wp-content', 'plugins', 'yume-core', 'yume-core.php' );
const PROPRIETE = 'EmpreinteSources';

// ---------------------------------------------------------------------------------------------
// Sources et empreinte
// ---------------------------------------------------------------------------------------------

/** Texte d'un fichier, fins de ligne normalisées (même empreinte sous Windows). */
function lireTexte( fichier ) {
	return fs.readFileSync( fichier, 'utf8' ).replace( /\r\n?/g, '\n' );
}

/** Images citées par le texte (chemins relatifs à docs/guide-equipe), sans doublon, triées. */
function imagesCitees( texte ) {
	const vues = new Set();
	for ( const m of texte.matchAll( /^!\[[^\]]*\]\(([^)]+)\)/gm ) ) {
		vues.add( m[ 1 ].trim() );
	}
	return [ ...vues ].sort();
}

/** Empreinte SHA-256 des sources du guide. */
function empreinte( texte ) {
	const h = crypto.createHash( 'sha256' );
	const ajouter = ( nom, contenu ) => {
		h.update( nom + '\0' + crypto.createHash( 'sha256' ).update( contenu ).digest( 'hex' ) + '\n' );
	};
	ajouter( 'procedures.md', texte );
	ajouter( 'construire.js', lireTexte( __filename ) );
	for ( const image of imagesCitees( texte ) ) {
		const fichier = path.join( DOSSIER, image );
		if ( ! fs.existsSync( fichier ) ) {
			throw new Error( `Image introuvable : docs/guide-equipe/${ image } (lancez node tools/guide/captures.js).` );
		}
		ajouter( image, fs.readFileSync( fichier ) );
	}
	return h.digest( 'hex' );
}

function versionPlugin() {
	const m = fs.readFileSync( PLUGIN, 'utf8' ).match( /^\s*\*\s*Version:\s*(\S+)/m );
	if ( ! m ) {
		throw new Error( 'Version introuvable dans yume-core.php.' );
	}
	return m[ 1 ];
}

function dateDuJour() {
	const parties = Object.fromEntries( new Intl.DateTimeFormat( 'fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Paris' } )
		.formatToParts( new Date() ).map( ( p ) => [ p.type, p.value ] ) );
	return `${ parties.day === '1' ? '1er' : parties.day } ${ parties.month } ${ parties.year }`;
}

// ---------------------------------------------------------------------------------------------
// Lecture du markdown (sous-ensemble décrit en tête de procedures.md)
// ---------------------------------------------------------------------------------------------

const ENCADRES = [ 'À savoir', 'Attention', 'Astuce', 'Nouveau' ];

function analyser( texte ) {
	const lignes = texte.replace( /<!--[\s\S]*?-->/g, '' ).split( '\n' );
	const blocs = [];
	let i = 0;
	let paragraphe = [];
	const vider = () => {
		if ( paragraphe.length ) {
			blocs.push( { type: 'p', texte: paragraphe.join( ' ' ) } );
			paragraphe = [];
		}
	};
	while ( i < lignes.length ) {
		const l = lignes[ i ];
		let m;
		if ( ! l.trim() ) {
			vider();
			i++;
		} else if ( ( m = l.match( /^(#{1,3}) (.+)$/ ) ) ) {
			vider();
			blocs.push( { type: 'titre', niveau: m[ 1 ].length, texte: m[ 2 ].trim() } );
			i++;
		} else if ( ( m = l.match( /^!\[([^\]]*)\]\(([^)]+)\)(?:\{largeur=(\d+)%\})?\s*$/ ) ) ) {
			vider();
			blocs.push( { type: 'image', legende: m[ 1 ], fichier: m[ 2 ].trim(), largeur: m[ 3 ] ? Number( m[ 3 ] ) : 100 } );
			i++;
		} else if ( l.startsWith( '>' ) ) {
			vider();
			const contenu = [];
			while ( i < lignes.length && lignes[ i ].startsWith( '>' ) ) {
				contenu.push( lignes[ i ].replace( /^>\s?/, '' ) );
				i++;
			}
			blocs.push( encadre( contenu ) );
		} else if ( l.startsWith( '|' ) ) {
			vider();
			const rangees = [];
			while ( i < lignes.length && lignes[ i ].startsWith( '|' ) ) {
				const cellules = lignes[ i ].trim().replace( /^\||\|$/g, '' ).split( '|' ).map( ( c ) => c.trim() );
				if ( ! cellules.every( ( c ) => /^:?-{3,}:?$/.test( c ) ) ) {
					rangees.push( cellules );
				}
				i++;
			}
			blocs.push( { type: 'tableau', rangees } );
		} else if ( /^\d+\. /.test( l ) || /^- /.test( l ) ) {
			vider();
			const numerote = /^\d+\. /.test( l );
			const items = [];
			while ( i < lignes.length ) {
				const c = lignes[ i ];
				if ( numerote ? /^\d+\. /.test( c ) : /^- /.test( c ) ) {
					items.push( { texte: c.replace( /^(\d+\.|-) /, '' ), sous: [] } );
				} else if ( /^\s{2,}- /.test( c ) && items.length ) {
					items[ items.length - 1 ].sous.push( c.replace( /^\s+- /, '' ) );
				} else if ( /^\s{2,}\S/.test( c ) && items.length ) {
					const dernier = items[ items.length - 1 ];
					if ( dernier.sous.length ) {
						dernier.sous[ dernier.sous.length - 1 ] += ' ' + c.trim();
					} else {
						dernier.texte += ' ' + c.trim();
					}
				} else {
					break;
				}
				i++;
			}
			blocs.push( { type: numerote ? 'etapes' : 'puces', items } );
		} else {
			paragraphe.push( l.trim() );
			i++;
		}
	}
	vider();
	return blocs;
}

/** Encadré « > **À savoir** : … » : genre, paragraphes et puces. */
function encadre( contenu ) {
	const premier = contenu.join( '\n' ).match( /^\*\*([^*]+)\*\*\s*:?\s*/ );
	const genre = premier && ENCADRES.includes( premier[ 1 ] ) ? premier[ 1 ] : 'À savoir';
	const texte = premier && ENCADRES.includes( premier[ 1 ] ) ? contenu.join( '\n' ).slice( premier[ 0 ].length ) : contenu.join( '\n' );
	const parties = [];
	let courant = [];
	for ( const l of texte.split( '\n' ) ) {
		if ( ! l.trim() ) {
			if ( courant.length ) {
				parties.push( { type: 'p', texte: courant.join( ' ' ) } );
			}
			courant = [];
		} else if ( l.startsWith( '- ' ) ) {
			if ( courant.length ) {
				parties.push( { type: 'p', texte: courant.join( ' ' ) } );
			}
			courant = [];
			parties.push( { type: 'puce', texte: l.slice( 2 ) } );
		} else {
			courant.push( l.trim() );
		}
	}
	if ( courant.length ) {
		parties.push( { type: 'p', texte: courant.join( ' ' ) } );
	}
	return { type: 'encadre', genre, parties };
}

// ---------------------------------------------------------------------------------------------
// Construction du DOCX
// ---------------------------------------------------------------------------------------------

const COULEURS = {
	texte: '222222',
	discret: '6B6478',
	violet: '4B2A7B',
	violetClair: '7A4FB0',
	rose: 'D6457F',
	bordure: 'CFC6DE',
	entete: '3A2A63',
	zebre: 'F6F3FA',
};
const STYLES_ENCADRES = {
	'À savoir': { bordure: '7A4FB0', fond: 'F1ECFA', titre: '4B2A7B' },
	Attention: { bordure: 'D9822B', fond: 'FDF1E4', titre: '9A4D0C' },
	Astuce: { bordure: '2E9E6A', fond: 'E9F7EF', titre: '1E6B47' },
	Nouveau: { bordure: 'D6457F', fond: 'FCE8F1', titre: '9C1F52' },
};
const LARGEUR_TEXTE = 9026; // A4 (11906) moins deux marges de 1440 (2,54 cm), en vingtièmes de point.
const LARGEUR_PX = 600; // Largeur utile en pixels à 96 ppp.
const HAUTEUR_MAX_PX = 700;

/** Espaces insécables de la typographie française (avant : ; ! ? », après «). */
function typographie( t ) {
	return t
		.replace( / ([:»])/g, ' $1' )
		.replace( / ([;!?])/g, ' $1' )
		.replace( /« /g, '« ' );
}

/** TextRun d'un texte avec **gras**, *italique* et `code`. */
function runs( docx, texte, base = {} ) {
	const { TextRun } = docx;
	const sortie = [];
	const motif = /(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|`[^`]+`)/g;
	let dernier = 0;
	for ( const m of texte.matchAll( motif ) ) {
		if ( m.index > dernier ) {
			sortie.push( new TextRun( { ...base, text: typographie( texte.slice( dernier, m.index ) ) } ) );
		}
		const t = m[ 0 ];
		if ( t.startsWith( '**' ) ) {
			sortie.push( new TextRun( { ...base, text: typographie( t.slice( 2, -2 ) ), bold: true } ) );
		} else if ( t.startsWith( '`' ) ) {
			sortie.push( new TextRun( { ...base, text: t.slice( 1, -1 ), font: 'Consolas', size: 19, shading: { type: docx.ShadingType.CLEAR, color: 'auto', fill: 'EFEAF5' } } ) );
		} else {
			sortie.push( new TextRun( { ...base, text: typographie( t.slice( 1, -1 ) ), italics: true } ) );
		}
		dernier = m.index + t.length;
	}
	if ( dernier < texte.length ) {
		sortie.push( new TextRun( { ...base, text: typographie( texte.slice( dernier ) ) } ) );
	}
	return sortie;
}

function dimensionsPng( tampon ) {
	if ( tampon.toString( 'ascii', 1, 4 ) !== 'PNG' ) {
		throw new Error( 'Seules les images PNG sont prises en charge.' );
	}
	return { l: tampon.readUInt32BE( 16 ), h: tampon.readUInt32BE( 20 ) };
}

/** Titres numérotés (1, 1.1) : [{niveau, numero, texte, ancre}] dans l'ordre du document. */
function numeroterTitres( blocs ) {
	const compteurs = [ 0, 0 ];
	let n = 0;
	for ( const b of blocs ) {
		if ( b.type !== 'titre' ) {
			continue;
		}
		if ( b.niveau === 1 ) {
			compteurs[ 0 ]++;
			compteurs[ 1 ] = 0;
			b.numero = String( compteurs[ 0 ] );
		} else if ( b.niveau === 2 ) {
			compteurs[ 1 ]++;
			b.numero = compteurs[ 0 ] + '.' + compteurs[ 1 ];
		}
		b.ancre = 'titre-' + ++n;
	}
}

function construireDocument( docx, blocs, meta, pages ) {
	const {
		Document, Paragraph, TextRun, ImageRun, Table, TableRow, TableCell, WidthType, BorderStyle,
		ShadingType, AlignmentType, HeadingLevel, LevelFormat, Header, Footer, PageNumber,
		TableOfContents, Bookmark, PageBreak, Tab, TabStopType, LineRuleType, VerticalAlign,
	} = docx;

	let figures = 0;
	let instanceEtapes = 0;
	const corps = [];
	const premierTitre = blocs.findIndex( ( b ) => b.type === 'titre' && b.niveau === 1 );
	const preambule = premierTitre > 0 ? blocs.slice( 0, premierTitre ) : [];
	const contenu = premierTitre >= 0 ? blocs.slice( premierTitre ) : blocs;
	const niveaux = { 1: HeadingLevel.HEADING_1, 2: HeadingLevel.HEADING_2, 3: HeadingLevel.HEADING_3 };
	const aucuneBordure = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };

	const paragrapheTexte = ( texte, options = {} ) => new Paragraph( { ...options, children: runs( docx, texte, options.run || {} ) } );

	const tableau = ( rangees ) => {
		const colonnes = Math.max( ...rangees.map( ( r ) => r.length ) );
		const poids = [];
		for ( let c = 0; c < colonnes; c++ ) {
			const longueurs = rangees.map( ( r ) => ( r[ c ] || '' ).replace( /\*\*/g, '' ).length );
			poids.push( Math.max( 10, Math.min( 70, longueurs.reduce( ( a, b ) => a + b, 0 ) / longueurs.length ) ) );
		}
		// Au moins la largeur du mot le plus long de la colonne (environ 125 vingtièmes de point par
		// caractère en 9,5 points gras), pour ne jamais couper un mot.
		const minimums = [];
		for ( let c = 0; c < colonnes; c++ ) {
			const mots = rangees.flatMap( ( r ) => ( r[ c ] || '' ).replace( /\*\*|`/g, '' ).split( /\s+/ ) );
			minimums.push( Math.max( 1300, 260 + 125 * Math.max( ...mots.map( ( m ) => m.length ) ) ) );
		}
		const total = poids.reduce( ( a, b ) => a + b, 0 );
		const largeurs = poids.map( ( p, c ) => Math.max( minimums[ c ], Math.round( ( LARGEUR_TEXTE * p ) / total ) ) );
		// Ramener la somme à la largeur du texte en prenant sur les colonnes au-dessus de leur minimum.
		let exces = largeurs.reduce( ( a, b ) => a + b, 0 ) - LARGEUR_TEXTE;
		for ( let c = 0; exces > 0 && c < colonnes; c++ ) {
			const marge = largeurs[ c ] - minimums[ c ];
			const pris = Math.min( marge, Math.ceil( ( exces * marge ) / Math.max( 1, largeurs.reduce( ( a, l, k ) => a + l - minimums[ k ], 0 ) ) ) + 1 );
			largeurs[ c ] -= pris;
			exces -= pris;
		}
		largeurs[ largeurs.length - 1 ] += LARGEUR_TEXTE - largeurs.reduce( ( a, b ) => a + b, 0 );
		const bord = { style: BorderStyle.SINGLE, size: 4, color: COULEURS.bordure };
		return new Table( {
			width: { size: LARGEUR_TEXTE, type: WidthType.DXA },
			columnWidths: largeurs,
			rows: rangees.map( ( r, i ) => new TableRow( {
				tableHeader: i === 0,
				cantSplit: true,
				children: largeurs.map( ( l, c ) => new TableCell( {
					width: { size: l, type: WidthType.DXA },
					margins: { top: 70, bottom: 70, left: 110, right: 110 },
					borders: { top: bord, bottom: bord, left: bord, right: bord },
					shading: i === 0 ? { type: ShadingType.CLEAR, color: 'auto', fill: COULEURS.entete } : ( i % 2 === 0 ? { type: ShadingType.CLEAR, color: 'auto', fill: COULEURS.zebre } : undefined ),
					verticalAlign: VerticalAlign.CENTER,
					children: [ paragrapheTexte( r[ c ] || '', { spacing: { before: 0, after: 0 }, run: i === 0 ? { bold: true, color: 'FFFFFF', size: 19 } : { size: 19 } } ) ],
				} ) ),
			} ) ),
		} );
	};

	const blocEncadre = ( b ) => {
		const s = STYLES_ENCADRES[ b.genre ];
		const enfants = b.parties.map( ( p, i ) => {
			const titre = i === 0 ? [ new TextRun( { text: b.genre, bold: true, color: s.titre } ), new TextRun( { text: ' : ' } ) ] : [];
			if ( p.type === 'puce' ) {
				return new Paragraph( { numbering: { reference: 'puces', level: 0 }, spacing: { before: 40, after: 40 }, children: [ ...titre, ...runs( docx, p.texte ) ] } );
			}
			return new Paragraph( { spacing: { before: i ? 80 : 0, after: 0 }, children: [ ...titre, ...runs( docx, p.texte ) ] } );
		} );
		return new Table( {
			width: { size: LARGEUR_TEXTE, type: WidthType.DXA },
			columnWidths: [ LARGEUR_TEXTE ],
			rows: [ new TableRow( {
				cantSplit: true,
				children: [ new TableCell( {
					width: { size: LARGEUR_TEXTE, type: WidthType.DXA },
					margins: { top: 120, bottom: 120, left: 200, right: 160 },
					shading: { type: ShadingType.CLEAR, color: 'auto', fill: s.fond },
					borders: { top: aucuneBordure, bottom: aucuneBordure, right: aucuneBordure, left: { style: BorderStyle.SINGLE, size: 24, color: s.bordure } },
					children: enfants,
				} ) ],
			} ) ],
		} );
	};

	const image = ( b ) => {
		const fichier = path.join( DOSSIER, b.fichier );
		const donnees = fs.readFileSync( fichier );
		const { l, h } = dimensionsPng( donnees );
		let largeur = Math.min( LARGEUR_PX * ( b.largeur / 100 ), l );
		let hauteur = ( h * largeur ) / l;
		if ( hauteur > HAUTEUR_MAX_PX ) {
			largeur = ( largeur * HAUTEUR_MAX_PX ) / hauteur;
			hauteur = HAUTEUR_MAX_PX;
		}
		figures++;
		return [
			new Paragraph( {
				alignment: AlignmentType.CENTER,
				keepNext: true,
				spacing: { before: 160, after: 60 },
				children: [ new ImageRun( {
					type: 'png',
					data: donnees,
					transformation: { width: Math.round( largeur ), height: Math.round( hauteur ) },
					altText: { name: path.basename( b.fichier ), title: b.legende, description: b.legende },
				} ) ],
			} ),
			new Paragraph( { style: 'Legende', children: [ new TextRun( { text: `Figure ${ figures } — `, bold: true } ), ...runs( docx, b.legende ) ] } ),
		];
	};

	// Espace sous un tableau ou un encadré, sauf avant un titre (qui a le sien) : évite une page
	// blanche quand ce paragraphe vide déborde juste avant un chapitre.
	const espace = ( suivant ) => ( suivant && suivant.type !== 'titre' ? [ new Paragraph( { spacing: { before: 0, after: 60 }, children: [] } ) ] : [] );

	const ajouter = ( b, cible, suivant ) => {
		switch ( b.type ) {
			case 'titre': {
				const texte = ( b.numero ? b.numero + ' ' : '' ) + typographie( b.texte );
				cible.push( new Paragraph( { heading: niveaux[ b.niveau ], children: [ new Bookmark( { id: b.ancre, children: [ new TextRun( texte ) ] } ) ] } ) );
				break;
			}
			case 'p':
				cible.push( paragrapheTexte( b.texte ) );
				break;
			case 'image':
				cible.push( ...image( b ) );
				break;
			case 'encadre':
				cible.push( blocEncadre( b ), ...espace( suivant ) );
				break;
			case 'tableau':
				cible.push( tableau( b.rangees ), ...espace( suivant ) );
				break;
			case 'etapes':
				instanceEtapes++;
				b.items.forEach( ( it ) => {
					cible.push( new Paragraph( { numbering: { reference: 'etapes', level: 0, instance: instanceEtapes }, spacing: { before: 40, after: 60 }, children: runs( docx, it.texte ) } ) );
					it.sous.forEach( ( s ) => cible.push( new Paragraph( { numbering: { reference: 'puces', level: 1 }, spacing: { before: 0, after: 40 }, children: runs( docx, s ) } ) ) );
				} );
				break;
			case 'puces':
				b.items.forEach( ( it ) => {
					cible.push( new Paragraph( { numbering: { reference: 'puces', level: 0 }, spacing: { before: 0, after: 60 }, children: runs( docx, it.texte ) } ) );
					it.sous.forEach( ( s ) => cible.push( new Paragraph( { numbering: { reference: 'puces', level: 1 }, spacing: { before: 0, after: 40 }, children: runs( docx, s ) } ) ) );
				} );
				break;
		}
	};

	// Page de titre.
	const titre = [
		new Paragraph( { spacing: { before: 2400, after: 0 }, children: [ new TextRun( { text: 'YUME NOVEL', bold: true, color: COULEURS.rose, size: 26, characterSpacing: 60 } ) ] } ),
		new Paragraph( { spacing: { before: 120, after: 120 }, children: [ new TextRun( { text: 'Guide de l’équipe', bold: true, color: COULEURS.violet, size: 64 } ) ] } ),
		new Paragraph( {
			spacing: { before: 0, after: 480 },
			border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: COULEURS.rose, space: 12 } },
			children: [ new TextRun( { text: typographie( 'Procédures de l’espace équipe : planning, œuvres, tomes, chapitres et publication' ), color: COULEURS.discret, size: 28 } ) ],
		} ),
		new Paragraph( { spacing: { before: 0, after: 480 }, children: [ new TextRun( { text: `Version ${ meta.version } du ${ meta.date }`, bold: true, color: COULEURS.violetClair, size: 22 } ) ] } ),
	];
	preambule.forEach( ( b, i ) => ajouter( b, titre, preambule[ i + 1 ] ) );

	// Sommaire.
	const entrees = contenu.filter( ( b ) => b.type === 'titre' && b.niveau <= 2 ).map( ( b ) => ( {
		title: ( b.numero ? b.numero + ' ' : '' ) + typographie( b.texte ),
		level: b.niveau,
		page: pages ? pages[ b.ancre ] : undefined,
		href: b.ancre,
	} ) );
	const sommaire = [
		new Paragraph( { children: [ new PageBreak() ] } ),
		new Paragraph( { style: 'TitreSommaire', children: [ new TextRun( 'Sommaire' ) ] } ),
		new TableOfContents( 'Sommaire', {
			hyperlink: true,
			headingStyleRange: '1-2',
			cachedEntries: entrees,
			beginDirty: ! pages,
		} ),
	];

	contenu.forEach( ( b, i ) => ajouter( b, corps, contenu[ i + 1 ] ) );

	const piedTexte = `Version ${ meta.version } du ${ meta.date }`;
	const document = new Document( {
		creator: 'Yume Novel',
		title: 'Guide de l’équipe Yume Novel',
		subject: 'Procédures de l’espace équipe',
		description: piedTexte,
		keywords: 'Yume Novel, guide, équipe, procédures',
		customProperties: [
			{ name: PROPRIETE, value: meta.empreinte },
			{ name: 'VersionGuide', value: meta.version },
			{ name: 'DateGuide', value: meta.date },
		],
		features: { updateFields: ! pages },
		styles: {
			default: {
				document: { run: { font: 'Calibri', size: 22, color: COULEURS.texte }, paragraph: { spacing: { after: 120, line: 276, lineRule: LineRuleType.AUTO } } },
			},
			paragraphStyles: [
				{ id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
					run: { size: 40, bold: true, color: COULEURS.violet },
					paragraph: { pageBreakBefore: true, keepNext: true, spacing: { before: 0, after: 280 }, outlineLevel: 0,
						border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: COULEURS.rose, space: 6 } } } },
				{ id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
					run: { size: 28, bold: true, color: COULEURS.violetClair },
					paragraph: { keepNext: true, keepLines: true, spacing: { before: 360, after: 120 }, outlineLevel: 1 } },
				{ id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true,
					run: { size: 24, bold: true, color: COULEURS.texte },
					paragraph: { keepNext: true, spacing: { before: 240, after: 80 }, outlineLevel: 2 } },
				{ id: 'Legende', name: 'Légende', basedOn: 'Normal', next: 'Normal',
					run: { size: 18, italics: true, color: COULEURS.discret },
					paragraph: { alignment: AlignmentType.CENTER, spacing: { before: 0, after: 240 } } },
				{ id: 'TitreSommaire', name: 'Titre du sommaire', basedOn: 'Normal', next: 'Normal',
					run: { size: 40, bold: true, color: COULEURS.violet },
					paragraph: { spacing: { before: 0, after: 280 } } },
				{ id: 'PiedDePage', name: 'Pied de page Yume', basedOn: 'Normal', next: 'Normal',
					run: { size: 17, color: COULEURS.discret },
					paragraph: { spacing: { before: 0, after: 0 } } },
				{ id: 'TOC1', name: 'toc 1', basedOn: 'Normal', next: 'Normal',
					run: { bold: true, color: COULEURS.violet },
					paragraph: { spacing: { before: 160, after: 40 } } },
				{ id: 'TOC2', name: 'toc 2', basedOn: 'Normal', next: 'Normal',
					paragraph: { indent: { left: 360 }, spacing: { before: 0, after: 30 } } },
			],
		},
		numbering: {
			config: [
				{ reference: 'etapes', levels: [ { level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
					style: { run: { bold: true, color: COULEURS.violetClair }, paragraph: { indent: { left: 440, hanging: 360 } } } } ] },
				{ reference: 'puces', levels: [
					{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { run: { color: COULEURS.rose }, paragraph: { indent: { left: 440, hanging: 300 } } } },
					{ level: 1, format: LevelFormat.BULLET, text: '–', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 880, hanging: 300 } } } },
				] },
			],
		},
		sections: [ {
			properties: {
				titlePage: true,
				page: { size: { width: 11906, height: 16838 }, margin: { top: 1440, bottom: 1300, left: 1440, right: 1440, header: 600, footer: 600 } },
			},
			headers: {
				first: new Header( { children: [ new Paragraph( { children: [] } ) ] } ),
				default: new Header( { children: [ new Paragraph( {
					alignment: AlignmentType.RIGHT,
					border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: COULEURS.bordure, space: 4 } },
					children: [ new TextRun( { text: 'Yume Novel · Guide de l’équipe', size: 17, color: COULEURS.discret } ) ],
				} ) ] } ),
			},
			footers: {
				first: new Footer( { children: [ new Paragraph( { children: [] } ) ] } ),
				default: new Footer( { children: [ new Paragraph( {
					style: 'PiedDePage',
					border: { top: { style: BorderStyle.SINGLE, size: 4, color: COULEURS.bordure, space: 4 } },
					tabStops: [ { type: TabStopType.RIGHT, position: LARGEUR_TEXTE } ],
					children: [
						new TextRun( { text: piedTexte, size: 17, color: COULEURS.discret } ),
						new TextRun( { size: 17, color: COULEURS.discret, children: [ new Tab(), 'Page ', PageNumber.CURRENT, ' sur ', PageNumber.TOTAL_PAGES ] } ),
					],
				} ) ] } ),
			},
			children: [ ...titre, ...sommaire, ...corps ],
		} ],
	} );
	return { document, figures };
}

// ---------------------------------------------------------------------------------------------
// Numéros de page du sommaire (LibreOffice)
// ---------------------------------------------------------------------------------------------

function commandeDisponible( nom ) {
	try {
		execFileSync( 'sh', [ '-c', `command -v ${ nom }` ], { stdio: 'ignore' } );
		return true;
	} catch ( e ) {
		return false;
	}
}

/** Page de chaque titre (ancre => numéro), d'après le rendu PDF de LibreOffice ; null si impossible. */
function relevePages( docxTampon, blocs ) {
	const soffice = [ 'soffice', 'libreoffice' ].find( commandeDisponible );
	if ( ! soffice || ! commandeDisponible( 'pdftotext' ) ) {
		console.warn( 'LibreOffice ou pdftotext absent : sommaire sans numéros de page (Word les calcule à l\u2019ouverture).' );
		return null;
	}
	// Sans Calibri ni Carlito (même chasse), LibreOffice mettrait en page avec une autre police :
	// les numéros relevés seraient faux dans Word.
	let police = '';
	try {
		police = execFileSync( 'fc-match', [ '-f', '%{family}', 'Calibri' ] ).toString();
	} catch ( e ) {
		police = '';
	}
	if ( ! /Calibri|Carlito/i.test( police ) ) {
		console.warn( 'Police Calibri ou Carlito absente (paquet fonts-crosextra-carlito) : sommaire sans numéros de page.' );
		return null;
	}
	const travail = fs.mkdtempSync( path.join( os.tmpdir(), 'guide-yume-' ) );
	try {
		const entree = path.join( travail, 'guide.docx' );
		fs.writeFileSync( entree, docxTampon );
		execFileSync( soffice, [ '-env:UserInstallation=file://' + path.join( travail, 'profil' ), '--headless', '--convert-to', 'pdf', '--outdir', travail, entree ], { stdio: 'ignore', timeout: 180000 } );
		const texte = execFileSync( 'pdftotext', [ path.join( travail, 'guide.pdf' ), '-' ], { maxBuffer: 64 * 1024 * 1024 } ).toString( 'utf8' );
		const norm = ( t ) => t.replace( /[\s   ]+/g, ' ' ).trim();
		const pagesPdf = texte.split( '\f' ).map( ( p ) => p.split( '\n' ).map( norm ).filter( Boolean ) );
		const resultat = {};
		let depuis = 0;
		for ( const b of blocs.filter( ( x ) => x.type === 'titre' && x.niveau <= 2 ) ) {
			const cherche = norm( ( b.numero ? b.numero + ' ' : '' ) + typographie( b.texte ) ).slice( 0, 28 );
			let trouve = -1;
			for ( let p = depuis; p < pagesPdf.length && trouve < 0; p++ ) {
				// Les lignes du sommaire ont des points de conduite ou se terminent par « 999 ».
				if ( pagesPdf[ p ].some( ( l ) => l.startsWith( cherche ) && ! /\.\.\.|…|\s999$/.test( l ) ) ) {
					trouve = p;
				}
			}
			if ( trouve < 0 ) {
				console.warn( `Page introuvable pour « ${ b.texte } » : sommaire sans numéros de page.` );
				return null;
			}
			resultat[ b.ancre ] = trouve + 1;
			depuis = trouve;
		}
		return resultat;
	} catch ( e ) {
		console.warn( 'Conversion LibreOffice impossible (' + e.message + ') : sommaire sans numéros de page.' );
		return null;
	} finally {
		fs.rmSync( travail, { recursive: true, force: true } );
	}
}

// ---------------------------------------------------------------------------------------------
// Commandes
// ---------------------------------------------------------------------------------------------

async function lireEmpreinteDocx( fichier ) {
	const JSZip = require( 'jszip' );
	const zip = await JSZip.loadAsync( fs.readFileSync( fichier ) );
	const custom = zip.file( 'docProps/custom.xml' );
	if ( ! custom ) {
		return null;
	}
	const xml = await custom.async( 'string' );
	const m = xml.match( new RegExp( `name="${ PROPRIETE }"[^>]*>\\s*<vt:lpwstr>([0-9a-f]{64})</vt:lpwstr>` ) );
	return m ? m[ 1 ] : null;
}

async function verifier() {
	const attendue = empreinte( lireTexte( SOURCE ) );
	if ( ! fs.existsSync( SORTIE ) ) {
		console.error( 'docs/guide-equipe/Guide-equipe-Yume-Novel.docx manque : lancez node tools/guide/construire.js.' );
		return 1;
	}
	const trouvee = await lireEmpreinteDocx( SORTIE );
	if ( trouvee !== attendue ) {
		console.error( 'Le guide Word n’est pas à jour : procedures.md, ses images ou tools/guide/construire.js ont changé depuis sa construction.' );
		console.error( 'Lancez node tools/guide/construire.js (voir tools/guide/README.md), puis commitez le DOCX.' );
		console.error( `Empreinte attendue ${ attendue }, trouvée ${ trouvee || '(aucune)' }.` );
		return 1;
	}
	console.log( `Guide Word à jour (empreinte ${ attendue.slice( 0, 12 ) }…).` );
	return 0;
}

async function construire( avecPages ) {
	const docx = require( 'docx' );
	const texte = lireTexte( SOURCE );
	const blocs = analyser( texte );
	numeroterTitres( blocs );
	const meta = { version: versionPlugin(), date: dateDuJour(), empreinte: empreinte( texte ) };

	let pages = null;
	if ( avecPages ) {
		// Première passe : numéros factices de même largeur, pour relever la page de chaque titre.
		const factices = Object.fromEntries( blocs.filter( ( b ) => b.ancre ).map( ( b ) => [ b.ancre, 999 ] ) );
		const brouillon = construireDocument( docx, blocs, meta, factices );
		pages = relevePages( await docx.Packer.toBuffer( brouillon.document ), blocs );
	}
	const { document, figures } = construireDocument( docx, blocs, meta, pages );
	fs.writeFileSync( SORTIE, await docx.Packer.toBuffer( document ) );
	const titres = blocs.filter( ( b ) => b.type === 'titre' );
	console.log( `docs/guide-equipe/Guide-equipe-Yume-Novel.docx : version ${ meta.version } du ${ meta.date }, `
		+ `${ titres.filter( ( b ) => b.niveau === 1 ).length } chapitres, ${ titres.filter( ( b ) => b.niveau === 2 ).length } procédures, ${ figures } figures, `
		+ ( pages ? `sommaire paginé (dernier titre page ${ Math.max( ...Object.values( pages ) ) })` : 'sommaire sans numéros de page' ) + '.' );
	return 0;
}

const args = process.argv.slice( 2 );
( args.includes( '--verifier' ) ? verifier() : construire( ! args.includes( '--sans-pages' ) ) )
	.then( ( code ) => process.exit( code ) )
	.catch( ( e ) => {
		console.error( e.message || e );
		process.exit( 1 );
	} );
