<?php
/**
 * Images de substitution de la préproduction : couvertures, bannières d'œuvre et bannière du
 * site dessinées avec GD aux couleurs de la direction « Crépuscule sakura » (design/README.md).
 *
 * Les vrais fichiers de la médiathèque de yumenovel.fr ne sont pas téléchargeables depuis
 * l'environnement de développement : le seed (tools/migrate/seed-local.php) crée des pièces
 * jointes aux mêmes ID avec une image minimale (numéro et nom de fichier). Pour une revue par
 * l'équipe, ces images sont redessinées ici, sans changer d'ID ni de chemin : titre de l'œuvre,
 * libellé du tome, dégradé nuit → sakura, pétales et soleil couchant.
 *
 * Outil local (non livré avec l'extension). Utilisé par tools/preprod/preprod.php.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dessin des images de substitution.
 */
final class Yume_Preprod_Images {

	/** Polices TrueType candidates (la première trouvée est utilisée). */
	private const POLICES = array(
		'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
		'/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
		'/Library/Fonts/Arial Bold.ttf',
		'C:\\Windows\\Fonts\\arialbd.ttf',
	);

	/** Police à chasse fixe (surtitres). */
	private const POLICES_MONO = array(
		'/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
		'/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf',
		'/usr/share/fonts/truetype/freefont/FreeMono.ttf',
	);

	/**
	 * Première police disponible.
	 *
	 * @param string[] $candidates Chemins.
	 */
	private static function police( array $candidates ): string {
		foreach ( $candidates as $chemin ) {
			if ( is_readable( $chemin ) ) {
				return $chemin;
			}
		}
		return '';
	}

	/**
	 * GD est-il utilisable ?
	 */
	public static function disponible(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' );
	}

	/**
	 * Teinte stable (décalage du dégradé) tirée d'un texte.
	 *
	 * @param string $graine Texte.
	 */
	private static function teinte( string $graine ): int {
		return (int) ( hexdec( substr( md5( $graine ), 0, 4 ) ) % 70 ) - 35;
	}

	/**
	 * Dégradé vertical à trois arrêts.
	 *
	 * @param \GdImage $image  Image.
	 * @param int[][]  $arrets Couleurs RVB [haut, milieu, bas].
	 * @param int      $decal  Décalage du rouge et du bleu (variété entre œuvres).
	 */
	private static function degrade( $image, array $arrets, int $decal ): void {
		$l = imagesx( $image );
		$h = imagesy( $image );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $h > 1 ? $y / ( $h - 1 ) : 0;
			if ( $t < 0.55 ) {
				$a = $arrets[0];
				$b = $arrets[1];
				$u = $t / 0.55;
			} else {
				$a = $arrets[1];
				$b = $arrets[2];
				$u = ( $t - 0.55 ) / 0.45;
			}
			$c = array();
			for ( $i = 0; $i < 3; $i++ ) {
				$v       = $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $u;
				$v      += 0 === $i ? $decal : ( 2 === $i ? -$decal / 2 : 0 );
				$c[ $i ] = max( 0, min( 255, (int) round( $v ) ) );
			}
			imageline( $image, 0, $y, $l - 1, $y, (int) imagecolorallocate( $image, $c[0], $c[1], $c[2] ) );
		}
	}

	/**
	 * Soleil couchant et pétales translucides.
	 *
	 * @param \GdImage $image  Image.
	 * @param string   $graine Graine du tirage (stable d'une construction à l'autre).
	 * @param float    $soleil_x Position horizontale du soleil (0–1).
	 * @param float    $soleil_y Position verticale du soleil (0–1).
	 * @param float    $rayon    Rayon du soleil (fraction de la hauteur).
	 * @param int      $petales  Nombre de pétales.
	 */
	private static function decor( $image, string $graine, float $soleil_x, float $soleil_y, float $rayon, int $petales ): void {
		$l = imagesx( $image );
		$h = imagesy( $image );
		$petales = min( $petales, 90 );
		imagealphablending( $image, true );
		$r = (int) round( $h * $rayon );
		for ( $i = 6; $i >= 1; $i-- ) {
			$halo = (int) imagecolorallocatealpha( $image, 247, 197, 159, 100 + 4 * $i );
			imagefilledellipse( $image, (int) ( $l * $soleil_x ), (int) ( $h * $soleil_y ), 2 * $r + 18 * $i, 2 * $r + 18 * $i, $halo );
		}
		imagefilledellipse( $image, (int) ( $l * $soleil_x ), (int) ( $h * $soleil_y ), 2 * $r, 2 * $r, (int) imagecolorallocatealpha( $image, 247, 197, 159, 38 ) );
		mt_srand( (int) hexdec( substr( md5( $graine ), 0, 7 ) ) );
		$base = max( 6, (int) round( min( $l, $h ) / 40 ) );
		for ( $i = 0; $i < $petales; $i++ ) {
			$x     = mt_rand( 0, $l );
			$y     = mt_rand( 0, $h );
			$taille = (int) ( $base * 0.6 ) + mt_rand( 0, $base );
			$petale = (int) imagecolorallocatealpha( $image, 248, 196, 218, mt_rand( 85, 112 ) );
			imagefilledellipse( $image, $x, $y, (int) ( $taille * 1.6 ), $taille, $petale );
		}
		mt_srand();
	}

	/**
	 * Collines sombres en bas de l'image (horizon).
	 *
	 * @param \GdImage $image   Image.
	 * @param float    $hauteur Hauteur de l'horizon (fraction de la hauteur).
	 */
	private static function horizon( $image, float $hauteur ): void {
		$l      = imagesx( $image );
		$h      = imagesy( $image );
		$points = array( 0, $h );
		$pas    = max( 8, (int) ( $l / 24 ) );
		for ( $x = 0; $x <= $l; $x += $pas ) {
			$points[] = $x;
			$points[] = (int) ( $h * ( 1 - $hauteur ) + sin( $x / max( 1, $l ) * 6.2 ) * $h * $hauteur * 0.35 );
		}
		$points[] = $l;
		$points[] = $h;
		imagefilledpolygon( $image, $points, (int) imagecolorallocatealpha( $image, 27, 18, 49, 18 ) );
	}

	/**
	 * Écrit un texte coupé en lignes dans une largeur donnée ; renvoie la hauteur écrite.
	 *
	 * @param \GdImage $image   Image.
	 * @param string   $texte   Texte.
	 * @param int      $x       Marge gauche.
	 * @param int      $y_bas   Ligne de base de la dernière ligne (le bloc est aligné en bas),
	 *                          ou négatif : -y de la première ligne (aligné en haut).
	 * @param int      $largeur Largeur disponible.
	 * @param float    $corps   Corps (px).
	 * @param int      $couleur Couleur GD.
	 * @param string   $police  Police TrueType ('' : police GD intégrée).
	 * @param int      $max     Nombre maximal de lignes.
	 */
	private static function texte( $image, string $texte, int $x, int $y_bas, int $largeur, float $corps, int $couleur, string $police, int $max = 4 ): int {
		$texte = trim( preg_replace( '/\s+/u', ' ', $texte ) );
		if ( '' === $texte ) {
			return 0;
		}
		if ( '' === $police || ! function_exists( 'imagettftext' ) ) {
			$ascii = (string) preg_replace( '/[^\x20-\x7e]/', '?', (string) remove_accents( $texte ) );
			$y     = $y_bas < 0 ? -$y_bas : $y_bas - imagefontheight( 5 );
			imagestring( $image, 5, $x, $y, $ascii, $couleur );
			return imagefontheight( 5 );
		}
		$mots   = explode( ' ', $texte );
		$lignes = array();
		$ligne  = '';
		foreach ( $mots as $mot ) {
			$essai = '' === $ligne ? $mot : $ligne . ' ' . $mot;
			$boite = imagettfbbox( $corps, 0, $police, $essai );
			if ( '' !== $ligne && is_array( $boite ) && ( $boite[2] - $boite[0] ) > $largeur ) {
				$lignes[] = $ligne;
				$ligne    = $mot;
			} else {
				$ligne = $essai;
			}
		}
		$lignes[] = $ligne;
		if ( count( $lignes ) > $max ) {
			$lignes              = array_slice( $lignes, 0, $max );
			$lignes[ $max - 1 ] .= '…';
		}
		$interligne = (int) round( $corps * 1.22 );
		$total      = $interligne * count( $lignes );
		$y          = $y_bas < 0 ? -$y_bas + (int) $corps : $y_bas - $total + $interligne;
		foreach ( $lignes as $l ) {
			imagettftext( $image, $corps, 0, $x, $y, $couleur, $police, $l );
			$y += $interligne;
		}
		return $total;
	}

	/**
	 * Enregistre l'image au format du fichier (JPEG, PNG, WebP, GIF) et met à jour les
	 * métadonnées de la pièce jointe (dimensions, poids).
	 *
	 * @param \GdImage $image         Image.
	 * @param int      $attachment_id Pièce jointe.
	 * @return bool Vrai si écrit.
	 */
	private static function enregistrer( $image, int $attachment_id ): bool {
		$chemin = (string) get_attached_file( $attachment_id );
		if ( '' === $chemin ) {
			return false;
		}
		wp_mkdir_p( dirname( $chemin ) );
		switch ( (string) get_post_mime_type( $attachment_id ) ) {
			case 'image/png':
				$ok = imagepng( $image, $chemin, 6 );
				break;
			case 'image/webp':
				$ok = function_exists( 'imagewebp' ) && imagewebp( $image, $chemin, 82 );
				break;
			case 'image/gif':
				$ok = imagegif( $image, $chemin );
				break;
			default:
				$ok = imagejpeg( $image, $chemin, 86 );
		}
		if ( ! $ok ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		wp_update_attachment_metadata(
			$attachment_id,
			array_merge(
				$meta,
				array(
					'width'    => imagesx( $image ),
					'height'   => imagesy( $image ),
					'filesize' => (int) filesize( $chemin ),
					'sizes'    => array(),
				)
			)
		);
		return true;
	}

	/**
	 * Dimensions cibles d'une pièce jointe : proportions d'origine, grand côté $cote.
	 *
	 * @param int $attachment_id Pièce jointe.
	 * @param int $cote          Grand côté visé.
	 * @param int $l_defaut      Largeur par défaut.
	 * @param int $h_defaut      Hauteur par défaut.
	 * @return int[] [largeur, hauteur]
	 */
	private static function dimensions( int $attachment_id, int $cote, int $l_defaut, int $h_defaut ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$l    = is_array( $meta ) && ! empty( $meta['width'] ) ? (int) $meta['width'] : $l_defaut;
		$h    = is_array( $meta ) && ! empty( $meta['height'] ) ? (int) $meta['height'] : $h_defaut;
		$f    = $cote / max( 1, max( $l, $h ) );
		return array( max( 16, (int) round( $l * $f ) ), max( 16, (int) round( $h * $f ) ) );
	}

	/**
	 * Zone sûre d'une image recadrée par le thème (object-fit: cover) : bande centrale aux
	 * proportions $ratio (largeur / hauteur), ou l'image entière si elle est plus étroite.
	 *
	 * @param int   $l     Largeur.
	 * @param int   $h     Hauteur.
	 * @param float $ratio Proportions de l'affichage.
	 * @return int[] [x de départ, largeur]
	 */
	private static function zone_sure( int $l, int $h, float $ratio ): array {
		$largeur = (int) min( $l, round( $h * $ratio ) );
		return array( (int) round( ( $l - $largeur ) / 2 ), $largeur );
	}

	/**
	 * Couverture : dégradé, pétales, titre de l'œuvre en bas, libellé du tome en surtitre. Le
	 * texte reste dans la zone visible d'une couverture 2/3 (images de l'ancien site souvent
	 * au format paysage, recadrées par le thème).
	 *
	 * @param int    $attachment_id Pièce jointe à redessiner.
	 * @param string $oeuvre        Titre de l'œuvre.
	 * @param string $libelle       Libellé (« Tome 7 », « Arc 7 »), ou vide.
	 */
	public static function couverture( int $attachment_id, string $oeuvre, string $libelle ): bool {
		if ( ! self::disponible() ) {
			return false;
		}
		list( $l, $h ) = self::dimensions( $attachment_id, 900, 600, 900 );
		list( $x0, $lz ) = self::zone_sure( $l, $h, 2 / 3 );
		$image         = imagecreatetruecolor( $l, $h );
		self::degrade( $image, array( array( 91, 58, 122 ), array( 45, 31, 79 ), array( 194, 67, 126 ) ), self::teinte( $oeuvre ) );
		self::decor( $image, $oeuvre . $libelle, ( $x0 + $lz * 0.72 ) / $l, 0.58, 0.12, (int) ( $lz * $h / 16000 ) );
		self::horizon( $image, 0.14 );
		$marge = (int) round( $lz * 0.09 );
		$blanc = (int) imagecolorallocate( $image, 255, 248, 251 );
		$peche = (int) imagecolorallocate( $image, 247, 197, 159 );
		$gras  = self::police( self::POLICES );
		$mono  = self::police( self::POLICES_MONO );
		if ( '' !== $libelle ) {
			self::texte( $image, mb_strtoupper( $libelle ), $x0 + $marge, -$marge, $lz - 2 * $marge, max( 12, $lz / 20 ), $peche, $mono, 1 );
		}
		self::texte( $image, $oeuvre, $x0 + $marge, $h - (int) round( $marge * 1.4 ), $lz - 2 * $marge, max( 14, $lz / 13 ), $blanc, $gras, 4 );
		$ok = self::enregistrer( $image, $attachment_id );
		imagedestroy( $image );
		return $ok;
	}

	/**
	 * Bannière : format large, soleil couchant, pétales. Sans titre, c'est une illustration de
	 * fond (bannière d'œuvre, derrière la fiche et le lecteur) ; avec titre (bannière du site),
	 * le texte est centré dans la zone visible sur mobile (recadrage au format 3/1).
	 *
	 * @param int    $attachment_id Pièce jointe à redessiner.
	 * @param string $surtitre      Surtitre (mono, pêche), ou vide.
	 * @param string $titre         Titre, ou vide pour une illustration seule.
	 * @param int    $largeur       Largeur visée (proportions d'origine conservées).
	 * @param string $graine        Graine du décor (titre de l'œuvre).
	 */
	public static function banniere( int $attachment_id, string $surtitre, string $titre, int $largeur = 2400, string $graine = '' ): bool {
		if ( ! self::disponible() ) {
			return false;
		}
		list( $l, $h ) = self::dimensions( $attachment_id, $largeur, 2400, 600 );
		if ( $h > $l ) {
			list( $l, $h ) = array( $largeur, (int) round( $largeur / 4 ) );
		}
		$graine = '' !== $graine ? $graine : $titre;
		$image  = imagecreatetruecolor( $l, $h );
		self::degrade( $image, array( array( 36, 23, 64 ), array( 84, 52, 120 ), array( 194, 67, 126 ) ), self::teinte( $graine ) );
		self::decor( $image, $graine, '' === $titre ? 0.7 : 0.82, 0.9, 0.45, (int) ( $l * $h / 12000 ) );
		self::horizon( $image, 0.2 );
		if ( '' !== $titre ) {
			list( $x0, $lz ) = self::zone_sure( $l, $h, 3.0 );
			$blanc           = (int) imagecolorallocate( $image, 255, 248, 251 );
			$peche           = (int) imagecolorallocate( $image, 247, 197, 159 );
			$gras            = self::police( self::POLICES );
			$corps           = max( 18, min( $h / 6.5, $lz / 14 ) );
			$y               = (int) round( $h * 0.34 );
			if ( '' !== $surtitre ) {
				$mono = self::police( self::POLICES_MONO );
				self::texte_centre( $image, mb_strtoupper( $surtitre ), $x0, $lz, $y, max( 11, $corps / 3 ), $peche, $mono );
				$y += (int) round( $corps * 0.9 );
			}
			self::texte_centre( $image, $titre, $x0, $lz, $y + (int) $corps, $corps, $blanc, $gras );
		}
		$ok = self::enregistrer( $image, $attachment_id );
		imagedestroy( $image );
		return $ok;
	}

	/**
	 * Une ligne de texte centrée dans une bande horizontale.
	 *
	 * @param \GdImage $image   Image.
	 * @param string   $texte   Texte.
	 * @param int      $x0      Début de la bande.
	 * @param int      $largeur Largeur de la bande.
	 * @param int      $y       Ligne de base.
	 * @param float    $corps   Corps (px).
	 * @param int      $couleur Couleur GD.
	 * @param string   $police  Police TrueType ('' : police GD intégrée).
	 */
	private static function texte_centre( $image, string $texte, int $x0, int $largeur, int $y, float $corps, int $couleur, string $police ): void {
		if ( '' === $police || ! function_exists( 'imagettftext' ) ) {
			$ascii = (string) preg_replace( '/[^\x20-\x7e]/', '?', (string) remove_accents( $texte ) );
			imagestring( $image, 5, $x0 + (int) max( 0, ( $largeur - strlen( $ascii ) * imagefontwidth( 5 ) ) / 2 ), $y - imagefontheight( 5 ), $ascii, $couleur );
			return;
		}
		$boite = imagettfbbox( $corps, 0, $police, $texte );
		$large = is_array( $boite ) ? $boite[2] - $boite[0] : 0;
		imagettftext( $image, $corps, 0, $x0 + (int) max( 0, ( $largeur - $large ) / 2 ), $y, $couleur, $police, $texte );
	}

	/**
	 * Crée une pièce jointe image vide (fichier dessiné ensuite par banniere()).
	 *
	 * @param string $nom   Nom de fichier (dans le dossier des médias, sous preprod/).
	 * @param string $titre Titre de la pièce jointe.
	 * @param int    $l     Largeur annoncée.
	 * @param int    $h     Hauteur annoncée.
	 * @return int ID (0 en cas d'échec).
	 */
	public static function nouvelle_piece( string $nom, string $titre, int $l, int $h ): int {
		$uploads = wp_get_upload_dir();
		$relatif = 'preprod/' . sanitize_file_name( $nom );
		$id      = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $titre,
				'post_status'    => 'inherit',
				'guid'           => trailingslashit( $uploads['baseurl'] ) . $relatif,
			),
			trailingslashit( $uploads['basedir'] ) . $relatif,
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( (int) $id, '_wp_attached_file', $relatif );
		wp_update_attachment_metadata(
			(int) $id,
			array(
				'width'  => $l,
				'height' => $h,
				'file'   => $relatif,
				'sizes'  => array(),
			)
		);
		return (int) $id;
	}
}
