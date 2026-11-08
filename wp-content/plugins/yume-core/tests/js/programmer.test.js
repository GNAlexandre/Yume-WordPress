/**
 * Tests de la répartition en publications du panneau « Programmer les sorties »
 * (includes/publication/blocks/publish-form/programmer.js), sans dépendance :
 *
 *   node --test wp-content/plugins/yume-core/tests/js/*.test.js
 *
 * Les groupes attendus sont ceux de tests/fixtures/repartition-miss-medic.json, vérifiés aussi
 * côté PHP (Calendrier::repartir(), tests/test-publication-calendrier.php) : les deux
 * implémentations donnent les mêmes groupes.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );

const programmer = require( path.join( __dirname, '../../includes/publication/blocks/publish-form/programmer.js' ) );
const fixture = require( path.join( __dirname, '../fixtures/repartition-miss-medic.json' ) );
const mots = fixture.unites.map( ( u ) => u.mots );

function sommes( tailles ) {
	let i = 0;
	return tailles.map( ( t ) => {
		const s = mots.slice( i, i + t ).reduce( ( a, b ) => a + b, 0 );
		i += t;
		return s;
	} );
}

test( 'Miss Medic, 45 unités : mêmes groupes que Calendrier::repartir() pour N = 10, 5, 1, 45', () => {
	assert.equal( mots.length, 45 );
	for ( const n of Object.keys( fixture.attendu ) ) {
		assert.deepEqual( programmer.repartir( mots, Number( n ) ), fixture.attendu[ n ], 'N = ' + n );
	}
} );

test( 'contiguë, complète, la plus grosse publication minimale', () => {
	const g = programmer.repartir( mots, 10 );
	assert.equal( g.length, 10 );
	assert.equal( g.reduce( ( a, b ) => a + b, 0 ), 45 );
	// L'unité la plus longue (8 453 mots) est insécable : c'est le maximum atteint.
	assert.equal( Math.max( ...sommes( g ) ), 8453 );
} );

test( 'une unité à 0 mot ne forme jamais une publication seule (collée à la suivante, ou à la précédente en fin)', () => {
	for ( const n of [ 2, 7, 10, 20, 37, 45 ] ) {
		let i = 0;
		for ( const t of programmer.repartir( mots, n ) ) {
			assert.ok( mots.slice( i, i + t ).some( ( m ) => m > 0 ), 'N = ' + n + ', publication à 0 mot à ' + i );
			assert.ok( mots[ i + t - 1 ] > 0 || i + t === mots.length, 'N = ' + n + ' : une unité à 0 mot termine une publication' );
			i += t;
		}
	}
	assert.deepEqual( programmer.repartir( [ 100, 0 ], 2 ), [ 2 ] );
	assert.deepEqual( programmer.repartir( [ 0, 0 ], 2 ), [ 2 ] );
	assert.deepEqual( programmer.repartir( [], 3 ), [] );
} );

test( 'N plus grand que le nombre d’unités : une publication par unité (unités à 0 mot collées)', () => {
	assert.deepEqual( programmer.repartir( [ 10, 20, 30 ], 9 ), [ 1, 1, 1 ] );
	assert.equal( programmer.repartir( mots, 400 ).length, 37 );
	assert.deepEqual( programmer.repartir( [ 10, 20, 30 ], 0 ), [ 3 ] );
} );

test( 'dates : rythme hebdomadaire et intervalle, sans fuseau', () => {
	const samedi = { jour: 6, heure: '18:00' };
	// Mercredi 11 novembre 2026, 10 h → samedi 14 novembre, 18 h.
	const apres = programmer.lireDate( '2026-11-11T10:00' );
	assert.equal( programmer.ecrireDate( programmer.prochainRythme( apres, samedi ) ), '2026-11-14T18:00' );
	// Samedi 18 h pile : le suivant.
	assert.equal( programmer.ecrireDate( programmer.prochainRythme( programmer.lireDate( '2026-11-14T18:00' ), samedi ) ), '2026-11-21T18:00' );
	const config = { rythme: null, dates: [], maintenant: '2026-11-08T09:00' };
	assert.deepEqual( programmer.datesParDefaut( config, 3, '2026-11-10T20:00', 3 ), [ '2026-11-10T20:00', '2026-11-13T20:00', '2026-11-16T20:00' ] );
	assert.deepEqual( programmer.datesParDefaut( config, 2, '', 7 ), [ '', '2026-11-15T09:00' ] );
	assert.deepEqual( programmer.datesDepuis( { rythme: samedi, maintenant: '2026-11-08T09:00' }, 3, '2026-11-14T18:00', 7 ), [ '2026-11-14T18:00', '2026-11-21T18:00', '2026-11-28T18:00' ] );
	// Rythme : dates du serveur tant que le départ ne les dépasse pas.
	const serveur = { rythme: samedi, dates: [ '2026-11-14T18:00', '2026-11-21T18:00' ], maintenant: '2026-11-08T09:00' };
	assert.deepEqual( programmer.datesParDefaut( serveur, 2, '', 7 ), serveur.dates );
	assert.deepEqual( programmer.datesParDefaut( serveur, 2, '2026-11-25T08:00', 7 ), [ '2026-11-28T18:00', '2026-12-05T18:00' ] );
} );
