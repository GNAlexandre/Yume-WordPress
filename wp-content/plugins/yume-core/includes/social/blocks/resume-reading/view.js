/**
 * Reprendre la lecture (bloc yume/resume-reading) pour les visiteurs : remplit le gabarit
 * masqué avec la lecture la plus récente de localStorage['yn.progression'] (écrite par le
 * lecteur), puis l'affiche. Rien de mémorisé : le bloc reste masqué ([hidden]).
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	const blocs = document.querySelectorAll( '.yn-resume[data-yn-resume][hidden]' );
	if ( ! blocs.length ) {
		return;
	}
	let toutes = null;
	try {
		toutes = JSON.parse( window.localStorage.getItem( 'yn.progression' ) || 'null' );
	} catch ( e ) {
		return;
	}
	if ( ! toutes || typeof toutes !== 'object' || Array.isArray( toutes ) ) {
		return;
	}

	let recente = null;
	Object.keys( toutes ).forEach( function ( cle ) {
		const entree = toutes[ cle ];
		if ( ! entree || typeof entree !== 'object' || ! entree.url || ! entree.titre ) {
			return;
		}
		if ( ! recente || String( entree.updated_at || '' ) > String( recente.updated_at || '' ) ) {
			recente = entree;
		}
	} );
	if ( ! recente ) {
		return;
	}

	// Adresse locale uniquement (le stockage a pu être modifié à la main).
	let url;
	try {
		url = new URL( String( recente.url ), window.location.href );
	} catch ( e ) {
		return;
	}
	if ( url.origin !== window.location.origin ) {
		return;
	}
	const paragraphe = Math.max( 0, parseInt( recente.paragraphe, 10 ) || 0 );
	const pourcentage = Math.max( 0, Math.min( 100, parseInt( recente.pourcentage, 10 ) || 0 ) );
	url.hash = paragraphe > 0 ? 'yn-p-' + ( paragraphe + 1 ) : '';

	const morceaux = String( recente.titre ).split( ' · ' );
	const oeuvre = morceaux[ 0 ];
	const suite = morceaux.slice( 1 ).map( function ( texte, i, liste ) {
		return i === liste.length - 1 ? texte.charAt( 0 ).toLowerCase() + texte.slice( 1 ) : texte;
	} );
	const detail = suite.concat( [ pourcentage + ' %' ] ).join( ' · ' );

	function remplir( bloc, selecteur, texte ) {
		const cible = bloc.querySelector( selecteur );
		if ( cible ) {
			cible.textContent = texte;
		}
	}

	blocs.forEach( function ( bloc ) {
		remplir( bloc, '[data-yn-resume-titre]', oeuvre + ' · ' + detail );
		remplir( bloc, '[data-yn-resume-oeuvre]', oeuvre );
		remplir( bloc, '[data-yn-resume-detail]', detail );
		remplir( bloc, '[data-yn-resume-position]', ' : ' + recente.titre );
		remplir( bloc, '[data-yn-resume-initiales]', oeuvre.slice( 0, 2 ) );
		const barre = bloc.querySelector( '[data-yn-resume-barre]' );
		if ( barre ) {
			barre.style.setProperty( '--v', pourcentage + '%' );
		}
		const lien = bloc.querySelector( '[data-yn-resume-lien]' );
		if ( lien ) {
			lien.href = url.href;
		}
		bloc.hidden = false;
	} );
}() );
