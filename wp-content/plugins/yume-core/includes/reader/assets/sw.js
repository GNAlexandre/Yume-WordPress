/**
 * Service worker Yume Novel : lecture hors ligne (AMEL-07).
 *
 * Servi par le plugin à l'adresse /?yume_sw=1 (portée « / », en-tête Service-Worker-Allowed),
 * précédé de la configuration self.YUME_SW_CONFIG (version, chemins, limites) écrite par PHP
 * (includes/reader/pwa.php). Changer YUME_CORE_VERSION change ce fichier : le navigateur
 * installe la nouvelle version et les caches des versions précédentes sont supprimés.
 *
 * Stratégie :
 * - Pages de lecture (/lire/…, navigation GET sans paramètre) : réseau d'abord, copie en cache
 *   en cas de succès, cache en repli hors ligne, sinon page « Hors ligne ». Seul le HTML
 *   public est mis en cache : la réponse doit porter <meta name="yume-hors-ligne"
 *   content="lecture">, que le serveur n'écrit que pour un visiteur non connecté. Pour un
 *   membre connecté, une copie anonyme est demandée à part (credentials: "omit") : aucune
 *   donnée personnelle (nonce, pseudo, progression) n'entre jamais dans le cache.
 * - Chapitre suivant (<link rel="next">) : préchargé à la demande de la page (message).
 * - Ressources statiques du thème, du plugin et de wp-includes (CSS, JS, polices, images) :
 *   cache d'abord puis mise à jour en arrière-plan.
 * - Jamais interceptés : wp-admin, wp-login.php, REST (/wp-json/, ?rest_route=), admin-ajax,
 *   requêtes autres que GET. Jamais de cache non plus pour l'espace équipe (/equipe/), le
 *   compte (/compte/), la connexion, les aperçus et recherches (URL avec paramètres) : hors
 *   ligne, ces pages affichent la page « Hors ligne ».
 * - Limite : CONFIG.maxChapitres pages de lecture (30 par défaut), la moins récemment
 *   consultée est retirée en premier (LRU) ; CONFIG.maxStatiques ressources.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
/* global self, caches, fetch, Response, URL, Request */
( function () {
	'use strict';

	const CONFIG = self.YUME_SW_CONFIG || {};
	const VERSION = String( CONFIG.version || '0' );
	const PREFIXE = 'yume-';
	const CACHE_LECTURE = PREFIXE + 'lecture-' + VERSION;
	const CACHE_STATIQUE = PREFIXE + 'statique-' + VERSION;
	const CACHE_SECOURS = PREFIXE + 'secours-' + VERSION;
	const MAX_CHAPITRES = Math.max( 1, parseInt( CONFIG.maxChapitres, 10 ) || 30 );
	const MAX_STATIQUES = Math.max( 10, parseInt( CONFIG.maxStatiques, 10 ) || 120 );
	const LECTURE = String( CONFIG.lecture || '/lire/' );
	const HORS_LIGNE = String( CONFIG.horsLigne || '/?yume_hors_ligne=1' );
	const MARQUEUR = /<meta\s+name=["']yume-hors-ligne["']\s+content=["']lecture["']/i;

	const BASE = String( CONFIG.base || '/' );
	function prefixer( chemin ) {
		return BASE + chemin;
	}
	// Administration, connexion et REST : jamais interceptés (ni cache, ni page de repli).
	const SYSTEME = [ 'wp-admin/', 'wp-login.php', 'wp-json/', 'wp-cron.php', 'xmlrpc.php' ].map( prefixer ).concat(
		Array.isArray( CONFIG.systeme ) ? CONFIG.systeme : []
	);
	// Pages personnelles (équipe, compte, connexion) : jamais mises en cache ; hors ligne, page
	// de repli « Hors ligne » (CONFIG.exclus : leurs chemins réels, pages yume_pages).
	const EXCLUS = SYSTEME.concat( [ 'equipe/', 'compte/', 'connexion/' ].map( prefixer ) ).concat(
		Array.isArray( CONFIG.exclus ) ? CONFIG.exclus : []
	);
	// Préfixes des ressources statiques admises dans le cache.
	const STATIQUES = Array.isArray( CONFIG.statiques ) ? CONFIG.statiques : [ '/wp-content/themes/', '/wp-content/plugins/yume-core/', '/wp-includes/' ];
	const DESTINATIONS = [ 'style', 'script', 'font', 'image' ];

	/* ------------------------------------------------------------------ */
	/* Filtres                                                             */
	/* ------------------------------------------------------------------ */

	function commencePar( url, liste ) {
		return liste.some( function ( chemin ) {
			return chemin && url.pathname.indexOf( chemin ) === 0;
		} );
	}

	/** Requête que le service worker ne touche jamais (autre origine, admin, connexion, REST). */
	function systeme( url ) {
		return url.origin !== self.location.origin || url.searchParams.has( 'rest_route' ) || url.pathname.indexOf( 'admin-ajax.php' ) > -1 || commencePar( url, SYSTEME );
	}

	/** Adresse jamais mise en cache (système, ou page personnelle). */
	function exclu( url ) {
		return systeme( url ) || commencePar( url, EXCLUS );
	}

	function pageDeLecture( url ) {
		return url.pathname.indexOf( LECTURE ) === 0 && url.search === '' && ! exclu( url );
	}

	function statique( url ) {
		return ! exclu( url ) && STATIQUES.some( function ( chemin ) {
			return url.pathname.indexOf( chemin ) === 0;
		} ) && /\.(css|js|woff2?|ttf|otf|svg|png|jpe?g|gif|webp|avif|ico)$/i.test( url.pathname );
	}

	/** Clé de cache d'une page de lecture : l'URL sans ancre ni paramètre. */
	function cle( url ) {
		return url.origin + url.pathname;
	}

	/* ------------------------------------------------------------------ */
	/* Cache des pages de lecture (LRU)                                    */
	/* ------------------------------------------------------------------ */

	function reponseMemorisable( reponse ) {
		return !! reponse && reponse.ok && reponse.status === 200 && ! reponse.redirected && ( reponse.type === 'basic' || reponse.type === 'default' );
	}

	function limiter( nom, max ) {
		return caches.open( nom ).then( function ( cache ) {
			return cache.keys().then( function ( cles ) {
				const surplus = cles.slice( 0, Math.max( 0, cles.length - max ) );
				return Promise.all(
					surplus.map( function ( requete ) {
						return cache.delete( requete );
					} )
				);
			} );
		} );
	}

	/**
	 * Mémorise une page de lecture si elle est publique (marqueur présent). La clé est retirée
	 * puis réécrite : elle passe en dernière position (la plus récente) de l'ordre des clés.
	 */
	function memoriserPage( url, reponse ) {
		if ( ! reponseMemorisable( reponse ) ) {
			return Promise.resolve( false );
		}
		return reponse.clone().text().then( function ( texte ) {
			if ( ! MARQUEUR.test( texte ) ) {
				return false;
			}
			const copie = new Response( texte, {
				status: 200,
				statusText: 'OK',
				headers: { 'Content-Type': reponse.headers.get( 'Content-Type' ) || 'text/html; charset=UTF-8' },
			} );
			return caches.open( CACHE_LECTURE ).then( function ( cache ) {
				return cache.delete( cle( url ) ).then( function () {
					return cache.put( cle( url ), copie );
				} );
			} ).then( function () {
				return limiter( CACHE_LECTURE, MAX_CHAPITRES );
			} ).then( function () {
				return true;
			} );
		} );
	}

	/** Copie anonyme d'une page de lecture (sans cookie : jamais de données personnelles). */
	function telechargerAnonyme( url ) {
		return fetch( new Request( cle( url ), { credentials: 'omit', redirect: 'follow', headers: { Accept: 'text/html' } } ) ).then( function ( reponse ) {
			return memoriserPage( url, reponse );
		} ).catch( function () {
			return false;
		} );
	}

	function depuisCache( url ) {
		return caches.open( CACHE_LECTURE ).then( function ( cache ) {
			return cache.match( cle( url ) ).then( function ( reponse ) {
				if ( ! reponse ) {
					return null;
				}
				// Consultée : elle redevient la plus récente (LRU).
				const copie = reponse.clone();
				cache.delete( cle( url ) ).then( function () {
					return cache.put( cle( url ), copie );
				} );
				return reponse;
			} );
		} );
	}

	function pageHorsLigne() {
		return caches.open( CACHE_SECOURS ).then( function ( cache ) {
			return cache.match( HORS_LIGNE );
		} ).then( function ( reponse ) {
			return reponse || new Response( '<!doctype html><meta charset="utf-8"><title>Hors ligne</title><p>Vous êtes hors ligne.</p>', {
				status: 503,
				headers: { 'Content-Type': 'text/html; charset=UTF-8' },
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Stratégies                                                          */
	/* ------------------------------------------------------------------ */

	/** Page de lecture : réseau d'abord, cache en repli, puis page « Hors ligne ». */
	function lecture( evenement, url ) {
		return fetch( evenement.request ).then(
			function ( reponse ) {
				const tache = memoriserPage( url, reponse ).then( function ( ok ) {
					// Page personnalisée (membre connecté) : copie anonyme à part.
					return ok || ! reponseMemorisable( reponse ) ? ok : telechargerAnonyme( url );
				} ).catch( function () {} );
				evenement.waitUntil( tache );
				return reponse;
			},
			function () {
				return depuisCache( url ).then( function ( reponse ) {
					return reponse || pageHorsLigne();
				} );
			}
		);
	}

	/**
	 * Autre navigation (dont compte et équipe) : réseau seul, page « Hors ligne » en cas d'échec ;
	 * jamais de cache.
	 */
	function navigation( evenement ) {
		return fetch( evenement.request ).catch( pageHorsLigne );
	}

	/** Ressource statique : cache d'abord, mise à jour en arrière-plan. */
	function ressource( evenement ) {
		return caches.open( CACHE_STATIQUE ).then( function ( cache ) {
			return cache.match( evenement.request ).then( function ( enCache ) {
				const reseau = fetch( evenement.request ).then( function ( reponse ) {
					if ( reponseMemorisable( reponse ) ) {
						return cache.put( evenement.request, reponse.clone() ).then( function () {
							limiter( CACHE_STATIQUE, MAX_STATIQUES );
							return reponse;
						} );
					}
					return reponse;
				} );
				if ( enCache ) {
					evenement.waitUntil( reseau.catch( function () {} ) );
					return enCache;
				}
				return reseau;
			} );
		} );
	}

	function memoriserRessources( urls ) {
		const liste = ( Array.isArray( urls ) ? urls : [] ).slice( 0, 60 ).map( function ( brute ) {
			try {
				return new URL( brute, self.location.href );
			} catch ( e ) {
				return null;
			}
		} ).filter( function ( url ) {
			return url && statique( url );
		} );
		if ( ! liste.length ) {
			return Promise.resolve();
		}
		return caches.open( CACHE_STATIQUE ).then( function ( cache ) {
			return Promise.all(
				liste.map( function ( url ) {
					return cache.match( url.href ).then( function ( deja ) {
						if ( deja ) {
							return null;
						}
						return fetch( url.href, { credentials: 'omit' } ).then( function ( reponse ) {
							return reponseMemorisable( reponse ) ? cache.put( url.href, reponse ) : null;
						} ).catch( function () {} );
					} );
				} )
			).then( function () {
				return limiter( CACHE_STATIQUE, MAX_STATIQUES );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Cycle de vie                                                        */
	/* ------------------------------------------------------------------ */

	self.addEventListener( 'install', function ( evenement ) {
		evenement.waitUntil(
			caches.open( CACHE_SECOURS ).then( function ( cache ) {
				return fetch( HORS_LIGNE, { credentials: 'omit' } ).then( function ( reponse ) {
					return reponse.ok ? cache.put( HORS_LIGNE, reponse ) : null;
				} ).catch( function () {} );
			} ).then( function () {
				return self.skipWaiting();
			} )
		);
	} );

	self.addEventListener( 'activate', function ( evenement ) {
		evenement.waitUntil(
			caches.keys().then( function ( noms ) {
				return Promise.all(
					noms.filter( function ( nom ) {
						return nom.indexOf( PREFIXE ) === 0 && [ CACHE_LECTURE, CACHE_STATIQUE, CACHE_SECOURS ].indexOf( nom ) < 0;
					} ).map( function ( nom ) {
						return caches.delete( nom );
					} )
				);
			} ).then( function () {
				return self.clients.claim();
			} )
		);
	} );

	self.addEventListener( 'fetch', function ( evenement ) {
		const requete = evenement.request;
		if ( requete.method !== 'GET' ) {
			return;
		}
		let url;
		try {
			url = new URL( requete.url );
		} catch ( e ) {
			return;
		}
		if ( systeme( url ) ) {
			return;
		}
		if ( requete.mode === 'navigate' ) {
			if ( pageDeLecture( url ) ) {
				evenement.respondWith( lecture( evenement, url ) );
			} else {
				evenement.respondWith( navigation( evenement ) );
			}
			return;
		}
		if ( DESTINATIONS.indexOf( requete.destination ) > -1 && statique( url ) ) {
			evenement.respondWith( ressource( evenement ) );
		}
	} );

	/**
	 * Messages des pages :
	 * - { type: 'yume-memoriser', pages: [url…], ressources: [url…] } : page de lecture ouverte
	 *   (copie anonyme de la page et du chapitre suivant, ressources déjà chargées) ;
	 * - { type: 'yume-vider' } : vide les caches Yume (désactivation, déconnexion).
	 */
	self.addEventListener( 'message', function ( evenement ) {
		const donnees = evenement.data || {};
		if ( donnees.type === 'yume-vider' ) {
			evenement.waitUntil(
				caches.keys().then( function ( noms ) {
					return Promise.all(
						noms.filter( function ( nom ) {
							return nom.indexOf( PREFIXE ) === 0 && nom !== CACHE_SECOURS;
						} ).map( function ( nom ) {
							return caches.delete( nom );
						} )
					);
				} )
			);
			return;
		}
		if ( donnees.type !== 'yume-memoriser' ) {
			return;
		}
		const pages = ( Array.isArray( donnees.pages ) ? donnees.pages : [] ).slice( 0, 3 ).map( function ( brute ) {
			try {
				return new URL( brute, self.location.href );
			} catch ( e ) {
				return null;
			}
		} ).filter( function ( url ) {
			return url && url.origin === self.location.origin && url.pathname.indexOf( LECTURE ) === 0 && ! exclu( url );
		} );
		evenement.waitUntil(
			Promise.all(
				pages.map( function ( url ) {
					return caches.open( CACHE_LECTURE ).then( function ( cache ) {
						return cache.match( cle( url ) );
					} ).then( function ( deja ) {
						// Déjà en cache : la prochaine visite en ligne la rafraîchira (réseau d'abord).
						return deja ? true : telechargerAnonyme( url );
					} );
				} )
			).then( function () {
				return memoriserRessources( donnees.ressources );
			} ).then( function () {
				if ( evenement.source && evenement.source.postMessage ) {
					evenement.source.postMessage( { type: 'yume-memorise' } );
				}
			} ).catch( function () {} )
		);
	} );
}() );
