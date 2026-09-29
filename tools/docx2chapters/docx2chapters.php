<?php
/**
 * Outil docx2chapters : découpe un DOCX (ou un EPUB) en chapitres, hors de WordPress, ou le
 * publie sur le site via l'API REST (mot de passe d'application).
 *
 * Utilisation :
 *   php tools/docx2chapters/docx2chapters.php convert tome.docx --out dossier
 *   php tools/docx2chapters/docx2chapters.php analyse tome.docx
 *   YUME_APP_PASSWORD=… php tools/docx2chapters/docx2chapters.php publish tome.docx \
 *       --site https://yumenovel.fr --user pseudo --oeuvre 12 --numero 10 \
 *       [--nature tome] [--titre "…"] [--pdf URL] [--epub URL] [--couverture image.jpg] \
 *       [--traduction X] [--relecture Y] [--edition Z] [--retirer-absents] \
 *       [--publier maintenant|AAAA-MM-JJTHH:MM] [--sans-annonce|--avec-annonce]
 *
 * Voir tools/docx2chapters/README.md. Utilise le convertisseur du plugin
 * (wp-content/plugins/yume-core/includes/import/), qui n'a besoin d'aucune fonction WordPress.
 *
 * @package Yume\Core
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.EscapeOutput, WordPress.WP.GlobalVariablesOverride

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Epub_Converter;
use Yume\Core\Import\Import_Exception;
use Yume\Core\Import\Result;
use Yume\Core\Import\Texte;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/yume-core/includes/import/autoload.php';

/**
 * Affiche un message sur la sortie d'erreur et quitte.
 *
 * @param string $message Message.
 * @param int    $code    Code de sortie.
 */
function yume_d2c_erreur( string $message, int $code = 1 ): void {
	fwrite( STDERR, 'Erreur : ' . $message . "\n" );
	exit( $code );
}

/**
 * Aide.
 */
function yume_d2c_aide(): string {
	return <<<'AIDE'
docx2chapters — découpe un DOCX (ou un EPUB) du tome en chapitres Yume.

Commandes :
  convert FICHIER --out DOSSIER      écrit resultat.json, les images et un aperçu HTML par chapitre
  analyse FICHIER                    affiche le rapport (chapitres, mots, avertissements), n'écrit rien
  publish FICHIER --site URL --user IDENTIFIANT --oeuvre ID --numero N
                                     envoie le fichier au site (tome et chapitres en brouillon)
  aide                               cette aide

Options de publish :
  --nature tome|arc|ex|bonus|chapitres   (défaut : tome)
  --titre "Titre du tome"
  --pdf URL --epub URL                    liens externes de téléchargement (jamais hébergés sur le site)
  --couverture image.jpg                  couverture (JPG, PNG ou WebP)
  --traduction X --relecture Y --edition Z
  --retirer-absents                       met en brouillon les chapitres absents du fichier
  --publier maintenant|AAAA-MM-JJTHH:MM   publie tout de suite, ou programme la sortie
  --sans-annonce                          ajout au catalogue : ni article d'annonce, ni Discord,
                                          ni e-mail (défaut du site pour un tome déjà publié)
  --avec-annonce                          annonce la sortie même si le tome est déjà publié
                                          (défaut du site pour un nouveau tome)

Options de convert et analyse :
  --sans-typographie                      n'ajoute pas d'espaces insécables devant ? ! : ;
  --json                                  (analyse) rapport au format JSON

Mot de passe d'application (Profil → Mots de passe d'application), lu dans l'ordre :
  1. la variable d'environnement YUME_APP_PASSWORD (méthode conseillée) ;
  2. le fichier ~/.config/yume/credentials (ou $YUME_CREDENTIALS), une ligne
     « YUME_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx », droits 600 obligatoires
     (chmod 600 ~/.config/yume/credentials) ;
  3. --app-password MOT_DE_PASSE : déconseillé, le secret reste dans l'historique du
     shell et est visible des autres utilisateurs de la machine (ps) ; un avertissement
     est affiché.

AIDE;
}

/**
 * Lit les arguments « --option valeur » et « --drapeau ».
 *
 * @param string[] $argv Arguments.
 * @return array{0:string[],1:array<string,string|bool>}
 */
function yume_d2c_arguments( array $argv ): array {
	$positionnels = array();
	$options      = array();
	$drapeaux     = array( 'retirer-absents', 'sans-annonce', 'avec-annonce', 'sans-typographie', 'json', 'aide', 'help' );
	for ( $i = 0, $n = count( $argv ); $i < $n; $i++ ) {
		$arg = $argv[ $i ];
		if ( str_starts_with( $arg, '--' ) ) {
			$nom = substr( $arg, 2 );
			if ( str_contains( $nom, '=' ) ) {
				list( $nom, $valeur ) = explode( '=', $nom, 2 );
				$options[ $nom ]      = $valeur;
			} elseif ( in_array( $nom, $drapeaux, true ) ) {
				$options[ $nom ] = true;
			} elseif ( $i + 1 < $n ) {
				$options[ $nom ] = $argv[ ++$i ];
			} else {
				yume_d2c_erreur( 'valeur manquante pour --' . $nom, 2 );
			}
			continue;
		}
		$positionnels[] = $arg;
	}
	return array( $positionnels, $options );
}

/**
 * Convertit un fichier selon son extension.
 *
 * @param string              $fichier Fichier.
 * @param array<string,mixed> $options Options du convertisseur.
 */
function yume_d2c_convertir( string $fichier, array $options ): Result {
	$ext = strtolower( pathinfo( $fichier, PATHINFO_EXTENSION ) );
	try {
		if ( 'epub' === $ext ) {
			return Epub_Converter::convert_file( $fichier, $options );
		}
		if ( 'docx' === $ext ) {
			return Docx_Converter::convert_file( $fichier, $options );
		}
	} catch ( Import_Exception $e ) {
		yume_d2c_erreur( $e->getMessage() );
	}
	yume_d2c_erreur( 'format non pris en charge : déposez un fichier .docx (ou .epub).', 2 );
	exit( 2 );
}

/**
 * Complète un texte par des espaces jusqu'à une largeur en caractères (et non en octets).
 *
 * @param string $texte   Texte.
 * @param int    $largeur Largeur.
 */
function yume_d2c_largeur( string $texte, int $largeur ): string {
	$texte = mb_strimwidth( $texte, 0, $largeur, '…', 'UTF-8' );
	return $texte . str_repeat( ' ', max( 0, $largeur - mb_strlen( $texte, 'UTF-8' ) ) );
}

/**
 * Rapport lisible d'une conversion.
 *
 * @param Result $r Résultat.
 */
function yume_d2c_rapport( Result $r ): string {
	$lignes   = array();
	$lignes[] = sprintf( '%s (%s) : %d chapitres, %s mots, %d dialogues, %d pensées', $r->stats['fichier'], strtoupper( (string) $r->stats['format'] ), count( $r->chapters ), number_format( (int) $r->stats['mots'], 0, ',', ' ' ), $r->stats['dialogues'], $r->stats['pensees'] );
	$lignes[] = sprintf( 'Images : %d gardées (%d avant le premier chapitre), %d ignorées', count( $r->images ), count( $r->front_images ), (int) ( $r->stats['images_ignorees'] ?? 0 ) );
	$lignes[] = '';
	foreach ( $r->chapters as $i => $c ) {
		$lignes[] = sprintf( '  %2d. %s %7s mots', $i + 1, yume_d2c_largeur( Result::libelle( $c ), 40 ), number_format( (int) $c['nb_mots'], 0, ',', ' ' ) );
	}
	if ( $r->warnings ) {
		$lignes[] = '';
		$lignes[] = 'Avertissements :';
		foreach ( $r->warnings as $w ) {
			$lignes[] = '  ▲ ' . $w;
		}
	}
	return implode( "\n", $lignes ) . "\n";
}

/**
 * Feuille de style des aperçus (reprend la typographie du lecteur Yume, thème Papier).
 */
function yume_d2c_css(): string {
	return 'body{margin:0;background:#fdf8fa;color:#2a1240;font:18px/1.6 Literata,Georgia,serif}'
		. 'main{max-width:68ch;margin:0 auto;padding:32px 20px;text-align:justify;hyphens:auto}'
		. 'h1{font-family:Outfit,system-ui,sans-serif;text-align:center;font-size:2.2em;margin:0}'
		. 'h2.yn-subtitle{text-align:center;font-size:1.25em;border-bottom:1px solid #e8d9e2;padding-bottom:.4em;margin:.4em 0 1.6em}'
		. 'p{margin:0 0 .8em}.yn-dialogue{padding-left:1.4em;text-indent:-1.4em}.yn-thought{font-style:italic;padding-left:2.2em}'
		. '.yn-thought em{font-style:normal}.yn-center,.has-text-align-center{text-align:center}'
		. 'hr.yn-scene-break{border:0;text-align:center;margin:2em 0}hr.yn-scene-break::after{content:"✿";color:#b23a71}'
		. 'figure{margin:2em 0}figure img{width:100%;height:auto;border-radius:8px}'
		. '.yn-note a{color:#b23a71;font-weight:700;text-decoration:none}.yn-notes{border-top:1px solid #e8d9e2;padding-top:1em;font-size:.85em;color:#5e4a73}'
		. 'nav{font-family:system-ui,sans-serif;font-size:15px;display:flex;justify-content:space-between;margin-top:2em}'
		. 'a{color:#b23a71}.avert{font-family:system-ui,sans-serif;font-size:14px;color:#8f5a1b}';
}

/**
 * Commande convert.
 *
 * @param string              $fichier Fichier source.
 * @param array<string,mixed> $options Options.
 */
function yume_d2c_convert( string $fichier, array $options ): void {
	if ( empty( $options['out'] ) || ! is_string( $options['out'] ) ) {
		yume_d2c_erreur( 'indiquez le dossier de sortie avec --out DOSSIER.', 2 );
	}
	$dossier = rtrim( (string) $options['out'], '/' );
	foreach ( array( $dossier, $dossier . '/images', $dossier . '/chapitres' ) as $d ) {
		if ( ! is_dir( $d ) && ! mkdir( $d, 0775, true ) ) {
			yume_d2c_erreur( 'impossible de créer le dossier ' . $d );
		}
	}
	$r = yume_d2c_convertir( $fichier, array( 'typographie' => empty( $options['sans-typographie'] ) ) );

	// Images extraites (sans les charger en mémoire).
	$fichiers_images = array();
	foreach ( $r->images as $cle => $image ) {
		$ext = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		)[ $image['mime'] ] ?? 'img';
		$nom = $cle . '.' . $ext;
		if ( $r->copier_image( $cle, $dossier . '/images/' . $nom ) ) {
			$fichiers_images[ $cle ] = $nom;
		}
	}

	file_put_contents( $dossier . '/resultat.json', json_encode( $r->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );

	$pages = array();
	foreach ( $r->chapters as $i => $c ) {
		$slug    = sprintf( '%02d-%s.html', $i + 1, Texte::cle( (string) $c['titre'] ) );
		$pages[] = $slug;
	}
	foreach ( $r->chapters as $i => $c ) {
		$html  = Blocks::remplacer_images(
			(string) $c['blocks'],
			static function ( string $cle, string $alt ) use ( $fichiers_images ): string {
				return isset( $fichiers_images[ $cle ] ) ? Blocks::image( 0, '../images/' . $fichiers_images[ $cle ], $alt ) : '';
			}
		);
		$html  = trim( (string) preg_replace( '/<!-- \/?wp:[^>]*-->\n?/', '', $html ) );
		$prec  = $i > 0 ? '<a href="' . $pages[ $i - 1 ] . '">← Chapitre précédent</a>' : '<span></span>';
		$suiv  = $i + 1 < count( $pages ) ? '<a href="' . $pages[ $i + 1 ] . '">Chapitre suivant →</a>' : '<span></span>';
		$titre = Blocks::texte( (string) $c['titre'] );
		$page  = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $titre . '</title><style>' . yume_d2c_css() . '</style></head><body><main>'
			. '<h1>' . $titre . '</h1>' . ( '' !== (string) $c['sous_titre'] ? '<h2 class="yn-subtitle">' . Blocks::texte( (string) $c['sous_titre'] ) . '</h2>' : '' )
			. $html . '<nav>' . $prec . '<a href="../index.html">Sommaire</a>' . $suiv . '</nav></main></body></html>';
		file_put_contents( $dossier . '/chapitres/' . $pages[ $i ], $page );
	}

	$liste = '';
	foreach ( $r->chapters as $i => $c ) {
		$liste .= '<li><a href="chapitres/' . $pages[ $i ] . '">' . Blocks::texte( Result::libelle( $c ) ) . '</a> — ' . number_format( (int) $c['nb_mots'], 0, ',', ' ' ) . ' mots</li>';
	}
	$galerie = '';
	foreach ( $r->front_images as $cle ) {
		if ( isset( $fichiers_images[ $cle ] ) ) {
			$galerie .= '<figure><img src="images/' . $fichiers_images[ $cle ] . '" alt=""></figure>';
		}
	}
	$avert = '';
	foreach ( $r->warnings as $w ) {
		$avert .= '<li class="avert">' . Blocks::texte( $w ) . '</li>';
	}
	file_put_contents(
		$dossier . '/index.html',
		'<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . Blocks::texte( (string) $r->stats['fichier'] ) . '</title><style>' . yume_d2c_css() . '</style></head><body><main>'
		. '<h1>' . Blocks::texte( (string) $r->stats['fichier'] ) . '</h1><h2 class="yn-subtitle">' . count( $r->chapters ) . ' chapitres · ' . number_format( (int) $r->stats['mots'], 0, ',', ' ' ) . ' mots</h2>'
		. '<ol>' . $liste . '</ol>' . ( '' !== $avert ? '<h2>Avertissements</h2><ul>' . $avert . '</ul>' : '' ) . ( '' !== $galerie ? '<h2>Galerie du tome</h2>' . $galerie : '' ) . '</main></body></html>'
	);

	echo yume_d2c_rapport( $r );
	echo "\nÉcrit dans " . $dossier . ' : resultat.json, index.html, chapitres/ (' . count( $pages ) . '), images/ (' . count( $fichiers_images ) . ")\n";
}

/**
 * Requête multipart vers l'API REST du site.
 *
 * @param string              $url     URL.
 * @param array<string,mixed> $champs  Champs (CURLFile pour les fichiers).
 * @param string              $user    Identifiant.
 * @param string              $pass    Mot de passe d'application.
 * @return array{0:int,1:array<string,mixed>|null,2:string}
 */
function yume_d2c_post( string $url, array $champs, string $user, string $pass ): array {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $champs,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD        => $user . ':' . $pass,
			CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			CURLOPT_TIMEOUT        => 900,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_USERAGENT      => 'docx2chapters (Yume Novel)',
		)
	);
	$corps  = curl_exec( $ch );
	$statut = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$err    = curl_error( $ch );
	curl_close( $ch );
	if ( false === $corps ) {
		yume_d2c_erreur( 'connexion au site impossible : ' . $err );
	}
	$json = json_decode( (string) $corps, true );
	return array( $statut, is_array( $json ) ? $json : null, (string) $corps );
}

/**
 * Racine de l'API REST (/wp-json/ ou ?rest_route=).
 *
 * @param string $site  Adresse du site.
 * @param string $route Route (ex. yume/v1/publications).
 * @param bool   $jolie Permaliens « jolis ».
 */
function yume_d2c_url( string $site, string $route, bool $jolie ): string {
	$site = rtrim( $site, '/' );
	return $jolie ? $site . '/wp-json/' . $route : $site . '/?rest_route=/' . $route;
}

/**
 * Chemin du fichier d'identifiants : $YUME_CREDENTIALS, sinon
 * $XDG_CONFIG_HOME/yume/credentials, sinon ~/.config/yume/credentials ('' si introuvable).
 */
function yume_d2c_fichier_identifiants(): string {
	$chemin = (string) getenv( 'YUME_CREDENTIALS' );
	if ( '' !== $chemin ) {
		return $chemin;
	}
	$config = (string) getenv( 'XDG_CONFIG_HOME' );
	if ( '' === $config ) {
		$maison = (string) ( getenv( 'HOME' ) ? getenv( 'HOME' ) : getenv( 'USERPROFILE' ) );
		$config = '' !== $maison ? $maison . '/.config' : '';
	}
	return '' !== $config ? $config . '/yume/credentials' : '';
}

/**
 * Lit le mot de passe d'application dans le fichier d'identifiants : ligne
 * « YUME_APP_PASSWORD=… » (ou première ligne non vide hors commentaire « # »). Le fichier
 * doit n'être lisible que par son propriétaire (600) : sinon l'outil refuse de s'en servir.
 *
 * @param string $chemin Fichier.
 * @return string Mot de passe, '' si le fichier n'existe pas ou n'en contient pas.
 */
function yume_d2c_lire_identifiants( string $chemin ): string {
	if ( '' === $chemin || ! is_file( $chemin ) ) {
		return '';
	}
	clearstatcache( true, $chemin );
	$droits = fileperms( $chemin );
	if ( DIRECTORY_SEPARATOR === '/' && false !== $droits && ( $droits & 0077 ) ) {
		yume_d2c_erreur( sprintf( 'le fichier d’identifiants %s est lisible par d’autres utilisateurs (droits %o) : lancez « chmod 600 %s ».', $chemin, $droits & 0777, $chemin ), 2 );
	}
	$lignes = file( $chemin, FILE_IGNORE_NEW_LINES );
	foreach ( false === $lignes ? array() : $lignes as $ligne ) {
		$ligne = trim( $ligne );
		if ( '' === $ligne || str_starts_with( $ligne, '#' ) ) {
			continue;
		}
		if ( preg_match( '/^(?:export\s+)?YUME_APP_PASSWORD\s*=\s*(.*)$/', $ligne, $m ) ) {
			return trim( $m[1], " \t\"'" );
		}
		if ( ! str_contains( $ligne, '=' ) ) {
			return $ligne;
		}
	}
	return '';
}

/**
 * Mot de passe d'application : YUME_APP_PASSWORD, puis fichier d'identifiants, puis
 * --app-password (déconseillé : historique du shell, ps), avec un avertissement.
 *
 * @param array<string,mixed> $o Options.
 */
function yume_d2c_mot_de_passe( array $o ): string {
	if ( isset( $o['app-password'] ) && '' !== (string) $o['app-password'] ) {
		fwrite( STDERR, "Attention : --app-password laisse le mot de passe dans l'historique du shell et le rend visible dans la liste des processus (ps). Préférez la variable YUME_APP_PASSWORD ou le fichier ~/.config/yume/credentials (droits 600), puis révoquez ce mot de passe d'application s'il a fuité.\n" );
		return (string) $o['app-password'];
	}
	$env = (string) getenv( 'YUME_APP_PASSWORD' );
	if ( '' !== $env ) {
		return $env;
	}
	return yume_d2c_lire_identifiants( yume_d2c_fichier_identifiants() );
}

/**
 * Commande publish.
 *
 * @param string              $fichier Fichier source.
 * @param array<string,mixed> $o       Options.
 */
function yume_d2c_publish( string $fichier, array $o ): void {
	if ( ! function_exists( 'curl_init' ) ) {
		yume_d2c_erreur( 'l’extension PHP « curl » est nécessaire pour publier.' );
	}
	foreach ( array( 'site', 'user', 'oeuvre' ) as $requis ) {
		if ( empty( $o[ $requis ] ) ) {
			yume_d2c_erreur( 'option --' . $requis . ' manquante (voir « aide »).', 2 );
		}
	}
	$pass = yume_d2c_mot_de_passe( $o );
	if ( '' === $pass ) {
		yume_d2c_erreur( 'mot de passe d’application manquant : variable YUME_APP_PASSWORD ou fichier ~/.config/yume/credentials (droits 600) ; voir « aide ».', 2 );
	}
	if ( ! empty( $o['sans-annonce'] ) && ! empty( $o['avec-annonce'] ) ) {
		yume_d2c_erreur( '--sans-annonce et --avec-annonce sont incompatibles.', 2 );
	}
	// Absent : le site choisit (sans annonce pour un tome déjà publié, avec sinon).
	$annonce = array();
	if ( ! empty( $o['sans-annonce'] ) ) {
		$annonce['sans_annonce'] = '1';
	} elseif ( ! empty( $o['avec-annonce'] ) ) {
		$annonce['sans_annonce'] = '0';
	}
	$nature = (string) ( $o['nature'] ?? 'tome' );
	if ( ! isset( $o['numero'] ) && ! in_array( $nature, array( 'ex', 'bonus' ), true ) ) {
		yume_d2c_erreur( 'option --numero manquante.', 2 );
	}
	$ext  = strtolower( pathinfo( $fichier, PATHINFO_EXTENSION ) );
	$mime = 'epub' === $ext ? 'application/epub+zip' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	// Contrôle local avant l'envoi : un fichier illisible n'est pas envoyé.
	$local = yume_d2c_convertir( $fichier, array() );
	echo 'Analyse locale : ' . count( $local->chapters ) . ' chapitres, ' . count( $local->images ) . " images.\n";

	$champs = array(
		'oeuvre_id' => (string) (int) $o['oeuvre'],
		'nature'    => $nature,
		'source'    => new CURLFile( $fichier, $mime, basename( $fichier ) ),
	);
	foreach ( array(
		'numero'     => 'numero',
		'titre'      => 'titre',
		'pdf'        => 'lien_pdf',
		'epub'       => 'lien_epub',
		'traduction' => 'credits[traduction]',
		'relecture'  => 'credits[relecture]',
		'edition'    => 'credits[edition]',
	) as $option => $champ ) {
		if ( isset( $o[ $option ] ) && is_string( $o[ $option ] ) ) {
			$champs[ $champ ] = $o[ $option ];
		}
	}
	if ( ! empty( $o['retirer-absents'] ) ) {
		$champs['retirer_absents'] = '1';
	}
	$champs += $annonce;
	if ( ! empty( $o['couverture'] ) ) {
		$couv = (string) $o['couverture'];
		if ( ! is_readable( $couv ) ) {
			yume_d2c_erreur( 'couverture illisible : ' . $couv );
		}
		$infos = getimagesize( $couv );
		if ( ! is_array( $infos ) || ! in_array( $infos['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			yume_d2c_erreur( 'couverture refusée : image JPG, PNG ou WebP attendue.' );
		}
		$champs['couverture'] = new CURLFile( $couv, $infos['mime'], basename( $couv ) );
	}

	echo 'Envoi à ' . $o['site'] . "…\n";
	$jolie                        = true;
	list( $statut, $json, $brut ) = yume_d2c_post( yume_d2c_url( (string) $o['site'], 'yume/v1/publications', true ), $champs, (string) $o['user'], $pass );
	if ( 404 === $statut && ( null === $json || 'rest_no_route' !== ( $json['code'] ?? '' ) ) ) {
		$jolie                        = false;
		list( $statut, $json, $brut ) = yume_d2c_post( yume_d2c_url( (string) $o['site'], 'yume/v1/publications', false ), $champs, (string) $o['user'], $pass );
	}
	if ( $statut < 200 || $statut >= 300 || null === $json ) {
		yume_d2c_erreur( ( $json['message'] ?? trim( strip_tags( substr( $brut, 0, 300 ) ) ) ) . ' (HTTP ' . $statut . ')' );
	}
	$tome = $json['tome'];
	echo "\n" . $tome['titre'] . ' — ' . $tome['etat'] . ( ! empty( $tome['reutilise'] ) ? ' (tome existant mis à jour)' : ' (créé)' ) . "\n";
	echo '  Aperçu : ' . $tome['apercu'] . "\n  Modifier : " . $tome['edition'] . "\n";
	foreach ( $json['chapitres'] as $c ) {
		echo '  · ' . yume_d2c_largeur( (string) $c['titre'], 40 ) . ' ' . yume_d2c_largeur( (string) $c['etat'], 10 ) . ' ' . $c['apercu'] . "\n";
	}
	if ( ! empty( $json['article'] ) ) {
		echo '  Annonce : ' . $json['article']['titre'] . ' (' . $json['article']['etat'] . ') ' . $json['article']['edition'] . "\n";
	}
	if ( ! empty( $json['sans_annonce'] ) ) {
		echo "  Ajout au catalogue : aucune annonce (ni article, ni Discord, ni e-mail).\n";
	}
	foreach ( (array) ( $json['avertissements'] ?? array() ) as $w ) {
		echo '  ▲ ' . $w . "\n";
	}

	if ( ! empty( $o['publier'] ) && is_string( $o['publier'] ) ) {
		list( $statut, $sortie ) = yume_d2c_post( yume_d2c_url( (string) $o['site'], 'yume/v1/publications/' . (int) $tome['id'] . '/publier', $jolie ), array( 'quand' => $o['publier'] ) + ( $annonce ? $annonce : array( 'sans_annonce' => empty( $json['sans_annonce'] ) ? '0' : '1' ) ), (string) $o['user'], $pass );
		if ( $statut < 200 || $statut >= 300 || null === $sortie ) {
			yume_d2c_erreur( 'brouillon enregistré, mais la publication a échoué : ' . ( $sortie['message'] ?? 'HTTP ' . $statut ) );
		}
		echo "\n" . ( 'publish' === $sortie['statut'] ? 'Publié : ' . $sortie['tome']['lien'] : 'Sortie programmée le ' . $sortie['date'] ) . ( ! empty( $sortie['sans_annonce'] ) ? ' (sans annonce)' : '' ) . "\n";
	}
}

list( $yume_d2c_pos, $yume_d2c_opt ) = yume_d2c_arguments( array_slice( $argv, 1 ) );
$yume_d2c_commande                   = $yume_d2c_pos[0] ?? 'aide';
if ( in_array( $yume_d2c_commande, array( 'aide', 'help', '-h' ), true ) || ! empty( $yume_d2c_opt['aide'] ) || ! empty( $yume_d2c_opt['help'] ) ) {
	echo yume_d2c_aide();
	exit( 0 );
}
$yume_d2c_fichier = $yume_d2c_pos[1] ?? '';
if ( '' === $yume_d2c_fichier || ! is_readable( $yume_d2c_fichier ) ) {
	yume_d2c_erreur( 'fichier introuvable : « ' . $yume_d2c_fichier . ' » (voir « aide »).', 2 );
}
switch ( $yume_d2c_commande ) {
	case 'convert':
		yume_d2c_convert( $yume_d2c_fichier, $yume_d2c_opt );
		break;
	case 'analyse':
		$yume_d2c_r = yume_d2c_convertir( $yume_d2c_fichier, array( 'typographie' => empty( $yume_d2c_opt['sans-typographie'] ) ) );
		echo ! empty( $yume_d2c_opt['json'] ) ? json_encode( $yume_d2c_r->rapport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" : yume_d2c_rapport( $yume_d2c_r );
		break;
	case 'publish':
		yume_d2c_publish( $yume_d2c_fichier, $yume_d2c_opt );
		break;
	default:
		yume_d2c_erreur( 'commande inconnue « ' . $yume_d2c_commande . ' » (convert, analyse, publish, aide).', 2 );
}
