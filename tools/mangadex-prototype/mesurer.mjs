#!/usr/bin/env node
/**
 * Mesure du proxy d'images du prototype MangaDex (Node.js 18 ou plus, sans dépendance).
 *
 *   node tools/mangadex-prototype/mesurer.mjs <site> <chapitre> [--qualite data-saver|data]
 *        [--voie rest|front] [--concurrence 4] [--passes 2] [--json resultat.json]
 *
 * Exemple : node mesurer.mjs https://preprod-yumenovel.wpcomstaging.com 0f1e…-uuid --voie front
 *
 * Pour chaque passe, demande toutes les pages du chapitre (N à la fois) et relève : statut,
 * délai avant les en-têtes, durée totale, taille, en-tête X-Yume-Proto-Genere (date de
 * génération par WordPress, durée du téléchargement chez MangaDex) et en-têtes de cache du
 * bord (x-ac de WordPress.com, x-cache, age, cf-cache-status). Une page dont X-Yume-Proto-Genere
 * est identique d'une passe à l'autre a été servie par un cache, sans passer par PHP.
 */

const args = process.argv.slice( 2 );
const options = { qualite: 'data-saver', voie: 'rest', concurrence: 4, passes: 2, json: '' };
const positionnels = [];
for ( let i = 0; i < args.length; i++ ) {
	if ( args[ i ].startsWith( '--' ) ) {
		options[ args[ i ].slice( 2 ) ] = args[ i + 1 ];
		i++;
	} else {
		positionnels.push( args[ i ] );
	}
}
const [ site, chapitre ] = positionnels;
if ( ! site || ! chapitre ) {
	console.error( 'Usage : node mesurer.mjs <site> <chapitre> [--qualite data-saver|data] [--voie rest|front] [--concurrence 4] [--passes 2] [--json fichier]' );
	process.exit( 2 );
}

const base = site.replace( /\/+$/, '' );
const CACHE = [ 'x-ac', 'x-cache', 'age', 'cf-cache-status', 'x-nananana', 'cache-control' ];

async function mesurer( url ) {
	const debut = performance.now();
	const reponse = await fetch( url, { headers: { 'User-Agent': 'yume-mesure-mangadex/0.1' } } );
	const entetes = performance.now();
	const corps = await reponse.arrayBuffer();
	const fin = performance.now();
	const cache = {};
	for ( const nom of CACHE ) {
		if ( reponse.headers.get( nom ) ) {
			cache[ nom ] = reponse.headers.get( nom );
		}
	}
	return {
		url,
		statut: reponse.status,
		ttfb: Math.round( entetes - debut ),
		total: Math.round( fin - debut ),
		octets: corps.byteLength,
		genere: reponse.headers.get( 'x-yume-proto-genere' ) || '',
		cache,
	};
}

async function parLots( urls, n ) {
	const resultats = new Array( urls.length );
	let suivant = 0;
	async function ouvrier() {
		while ( suivant < urls.length ) {
			const i = suivant++;
			try {
				resultats[ i ] = await mesurer( urls[ i ] );
			} catch ( e ) {
				resultats[ i ] = { url: urls[ i ], statut: 0, erreur: String( e ), ttfb: 0, total: 0, octets: 0, genere: '', cache: {} };
			}
		}
	}
	await Promise.all( Array.from( { length: Math.max( 1, n ) }, ouvrier ) );
	return resultats;
}

function centile( valeurs, p ) {
	const t = [ ...valeurs ].sort( ( a, b ) => a - b );
	return t.length ? t[ Math.min( t.length - 1, Math.floor( p * t.length ) ) ] : 0;
}

const t0 = performance.now();
const api = `${ base }/wp-json/yume-proto/v1/chapitre/${ chapitre }?qualite=${ encodeURIComponent( options.qualite ) }&voie=${ encodeURIComponent( options.voie ) }`;
const reponse = await fetch( api );
const infos = await reponse.json();
if ( ! reponse.ok ) {
	console.error( `Chapitre refusé (HTTP ${ reponse.status }) : ${ infos.message || JSON.stringify( infos ) }` );
	process.exit( 1 );
}
console.log( `Chapitre : ${ infos.manga?.titre || '?' } ch. ${ infos.chapitre || '?' } — ${ infos.pages.length } pages, qualité ${ options.qualite }, voie ${ options.voie } (API : ${ Math.round( performance.now() - t0 ) } ms)` );

const passes = [];
for ( let p = 1; p <= Number( options.passes ); p++ ) {
	const debut = performance.now();
	const resultats = await parLots( infos.pages, Number( options.concurrence ) );
	const duree = Math.round( performance.now() - debut );
	passes.push( { passe: p, duree, resultats } );
	const ok = resultats.filter( ( r ) => r.statut === 200 );
	const octets = ok.reduce( ( s, r ) => s + r.octets, 0 );
	const caches = p > 1 ? resultats.filter( ( r, i ) => r.genere && r.genere === passes[ 0 ].resultats[ i ].genere ).length : 0;
	console.log(
		`Passe ${ p } : ${ ok.length }/${ resultats.length } pages en ${ duree } ms · ${ ( octets / 1048576 ).toFixed( 2 ) } Mo · ` +
		`délai avant en-têtes p50 ${ centile( ok.map( ( r ) => r.ttfb ), 0.5 ) } ms / p95 ${ centile( ok.map( ( r ) => r.ttfb ), 0.95 ) } ms` +
		( p > 1 ? ` · servies par un cache (génération identique) : ${ caches }/${ resultats.length }` : '' )
	);
	const premier = resultats.find( ( r ) => Object.keys( r.cache ).length );
	if ( premier ) {
		console.log( `  en-têtes de cache (page 1) : ${ JSON.stringify( premier.cache ) }` );
	}
	for ( const r of resultats.filter( ( r ) => r.statut !== 200 ) ) {
		console.log( `  échec : HTTP ${ r.statut } ${ r.erreur || '' } ${ r.url }` );
	}
}

if ( options.json ) {
	const { writeFile } = await import( 'node:fs/promises' );
	await writeFile( options.json, JSON.stringify( { site: base, chapitre, options, infos: { pages: infos.pages.length, titre: infos.manga?.titre }, passes }, null, '\t' ) );
	console.log( `Détail : ${ options.json }` );
}
