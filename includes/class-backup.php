<?php
/**
 * Sauvegarde de la base de données avant les mises à jour.
 *
 * Filet de sécurité minimal : un dump SQL gzippé est écrit dans les uploads
 * avant d'appliquer les mises à jour (cœur + extensions). Deux voies :
 * `mysqldump` quand le binaire et `exec` sont disponibles, sinon un dump PHP
 * pur en flux (hébergements mutualisés). Un échec de sauvegarde n'interrompt
 * JAMAIS l'entretien — le statut remonte au backend via les données serveur.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Backup {

	/** Nombre de sauvegardes conservées sur disque. */
	const KEEP = 3;

	/** Option holding the random per-site directory token (autoload off). */
	const DIR_OPTION = 'pardesign_entretien_backup_dir';

	/** Prefix of the backup directory inside uploads; the random token follows. */
	const DIR_PREFIX = 'pardesign-entretien-';

	/** Fixed directory name used by versions <= 0.8.0, migrated on first run and removed. */
	const LEGACY_DIRNAME = 'pardesign-entretien-backups';

	/** Taille du tampon d'écriture gzip (~1 Mo). */
	const FLUSH_BYTES = 1048576;

	/**
	 * Point d'entrée : dump gzippé dans les uploads + purge. Ne lève jamais.
	 *
	 * @return array{ok:bool,method:?string,file:?string,size_bytes:int,duration_s:float,error:?string,taken_at:string}
	 */
	public static function run() {
		$started = microtime( true );
		$result  = array(
			'ok'         => false,
			'method'     => null,
			'file'       => null,
			'size_bytes' => 0,
			'duration_s' => 0.0,
			'error'      => null,
			'taken_at'   => gmdate( 'c' ),
		);

		try {
			$dir = self::ensure_dir();
			if ( is_wp_error( $dir ) ) {
				$result['error'] = $dir->get_error_message();
				return self::finalize( $result, $started );
			}

			$space = self::check_disk_space( $dir );
			if ( is_wp_error( $space ) ) {
				$result['error'] = $space->get_error_message();
				return self::finalize( $result, $started );
			}

			$file = self::target_path( $dir );

			// Voie 1 : mysqldump (fiable et rapide) quand l'environnement le permet.
			if ( self::exec_available() && self::find_mysqldump() ) {
				$ok = self::dump_via_mysqldump( $file );
				if ( true === $ok ) {
					$result['method'] = 'mysqldump';
				} else {
					// On retente en PHP pur avant de déclarer l'échec.
					self::delete_partial( $file );
					$ok = self::dump_via_php( $file );
					if ( true === $ok ) {
						$result['method'] = 'php';
					} else {
						self::delete_partial( $file );
						$result['error'] = $ok->get_error_message();
						return self::finalize( $result, $started );
					}
				}
			} else {
				$ok = self::dump_via_php( $file );
				if ( true === $ok ) {
					$result['method'] = 'php';
				} else {
					self::delete_partial( $file );
					$result['error'] = $ok->get_error_message();
					return self::finalize( $result, $started );
				}
			}

			// Sans zlib, le dump est écrit en .sql non compressé.
			if ( ! file_exists( $file ) ) {
				$alt = preg_replace( '/\.gz$/', '', $file );
				if ( file_exists( $alt ) ) {
					$file = $alt;
				}
			}
			$size = file_exists( $file ) ? (int) filesize( $file ) : 0;
			if ( $size <= 0 ) {
				self::delete_partial( $file );
				$result['error'] = __( 'Fichier de sauvegarde vide.', 'pardesign-entretien' );
				return self::finalize( $result, $started );
			}

			@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result['ok']         = true;
			$result['file']       = $file;
			$result['size_bytes'] = $size;
			self::purge( $dir );
		} catch ( \Throwable $e ) {
			$result['error'] = $e->getMessage();
		}

		return self::finalize( $result, $started );
	}

	/**
	 * Dernière sauvegarde présente sur disque (pour `wp pardesign entretien status`).
	 *
	 * @return array{file:string,size_bytes:int,taken_at:string}|null
	 */
	public static function last() {
		$dir = self::dir_path();
		if ( ! $dir || ! is_dir( $dir ) ) {
			return null;
		}
		$files = array_filter( array_merge( (array) glob( $dir . '/*.sql.gz' ), (array) glob( $dir . '/*.sql' ) ) );
		if ( empty( $files ) ) {
			return null;
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		$latest = $files[0];
		return array(
			'file'       => basename( $latest ),
			'size_bytes' => (int) filesize( $latest ),
			'taken_at'   => gmdate( 'c', (int) filemtime( $latest ) ),
		);
	}

	/** Complète durée + retour. */
	private static function finalize( array $result, $started ) {
		$result['duration_s'] = round( microtime( true ) - $started, 2 );
		return $result;
	}

	/**
	 * Path of the backup directory: {uploads}/pardesign-entretien-{random token}. The token is
	 * generated once per site and stored in an option, so the directory name is unguessable
	 * and never reported anywhere (the backend only ever receives file basenames).
	 *
	 * @param bool $create Generate the token when missing.
	 * @return string|null Absolute path without trailing slash, null when not yet created.
	 */
	public static function dir_path( bool $create = false ) {
		$token = (string) get_option( self::DIR_OPTION, '' );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			if ( ! $create ) {
				return null;
			}
			$token = wp_generate_password( 32, false, false );
			add_option( self::DIR_OPTION, $token, '', 'no' );
			$token = (string) get_option( self::DIR_OPTION, $token ); // another request may have won the race
		}
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR_PREFIX . $token;
	}

	/**
	 * Dossier de destination protégé : nom aléatoire, mode 0700, fichiers de garde pour
	 * Apache (.htaccess), IIS (web.config) et l'index. Sous nginx, seul le nom imprévisible
	 * protège ; le chmod n'arrête pas le serveur web lui-même (même utilisateur), seulement
	 * les autres comptes d'un hébergement mutualisé.
	 *
	 * @return string|WP_Error
	 */
	private static function ensure_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'backup_dir', $uploads['error'] );
		}
		$dir = self::dir_path( true );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'backup_dir', __( 'Impossible de créer le dossier de sauvegardes.', 'pardesign-entretien' ) );
		}
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'backup_dir', __( 'Dossier de sauvegardes non inscriptible.', 'pardesign-entretien' ) );
		}
		self::write_guards( $dir );
		self::migrate_legacy_dir( $dir );
		return $dir;
	}

	/** Guard files blocking direct HTTP access where the web server honours them. */
	private static function write_guards( string $dir ): void {
		$guards = array(
			'index.php'  => "<?php // Silence.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions>\n\t\t\t\t\t<add fileExtension=\".sql\" allowed=\"false\" />\n\t\t\t\t\t<add fileExtension=\".gz\" allowed=\"false\" />\n\t\t\t\t\t<add fileExtension=\".cnf\" allowed=\"false\" />\n\t\t\t\t</fileExtensions>\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
		);
		foreach ( $guards as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/** Move dumps left by <= 0.8.0 in the fixed-name directory into the private one, then remove it. */
	private static function migrate_legacy_dir( string $dir ): void {
		$uploads = wp_upload_dir( null, false );
		$legacy  = trailingslashit( $uploads['basedir'] ) . self::LEGACY_DIRNAME;
		if ( ! is_dir( $legacy ) || realpath( $legacy ) === realpath( $dir ) ) {
			return;
		}
		foreach ( array_merge( (array) glob( $legacy . '/*.sql.gz' ), (array) glob( $legacy . '/*.sql' ) ) as $old ) {
			if ( $old && @rename( $old, $dir . '/' . basename( $old ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@chmod( $dir . '/' . basename( $old ), 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		self::remove_dir( $legacy );
	}

	/** Create an empty file in mode 0600 so it is never world-readable, even while being written. */
	private static function create_private_file( string $path ): bool {
		$fh = @fopen( $path, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		fclose( $fh );
		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	/** Delete every regular file of a directory (dumps, guards, temp files) and the directory itself. */
	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $entry ) {
			if ( $entry && is_file( $entry ) ) {
				@unlink( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Uninstall: delete every dump, the private directory (and the legacy one) and the token.
	 *
	 * @return int Number of dump files deleted.
	 */
	public static function delete_all(): int {
		$count   = 0;
		$uploads = wp_upload_dir( null, false );
		$dirs    = array( trailingslashit( $uploads['basedir'] ) . self::LEGACY_DIRNAME );
		$private = self::dir_path();
		if ( $private ) {
			$dirs[] = $private;
		}
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$count += count( array_filter( array_merge( (array) glob( $dir . '/*.sql.gz' ), (array) glob( $dir . '/*.sql' ) ) ) );
			self::remove_dir( $dir );
		}
		delete_option( self::DIR_OPTION );
		return $count;
	}

	/** Chemin cible avec suffixe aléatoire (nom imprévisible). */
	private static function target_path( $dir ) {
		return $dir . '/' . DB_NAME . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false, false ) . '.sql.gz';
	}

	/**
	 * Refuse de remplir le disque : la taille brute des tables doit tenir (le
	 * gzip compresse ~5-10×, on exige la moitié de l'estimation brute).
	 *
	 * @return true|WP_Error
	 */
	private static function check_disk_space( $dir ) {
		global $wpdb;
		if ( ! function_exists( 'disk_free_space' ) ) {
			return true;
		}
		$estimated = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);
		$free = @disk_free_space( $dir );
		if ( false === $free || $estimated <= 0 ) {
			return true;
		}
		if ( $free < $estimated * 0.5 ) {
			return new WP_Error(
				'backup_disk',
				sprintf(
					/* translators: 1: espace libre, 2: taille estimée. */
					__( 'Espace disque insuffisant pour la sauvegarde (%1$s libres, base estimée à %2$s).', 'pardesign-entretien' ),
					size_format( (int) $free ),
					size_format( (int) $estimated )
				)
			);
		}
		return true;
	}

	/** `exec` réellement utilisable ? */
	private static function exec_available() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	/** Chemin du binaire mysqldump, null si introuvable. */
	private static function find_mysqldump() {
		$out  = array();
		$code = 1;
		@exec( 'command -v mysqldump 2>/dev/null', $out, $code );
		if ( 0 === $code && ! empty( $out[0] ) ) {
			return trim( $out[0] );
		}
		$candidates = array(
			'/usr/bin/mysqldump',
			'/usr/local/bin/mysqldump',
			'/usr/local/mysql/bin/mysqldump',
			'/opt/homebrew/bin/mysqldump',
		);
		foreach ( $candidates as $bin ) {
			if ( @is_executable( $bin ) ) {
				return $bin;
			}
		}
		return null;
	}

	/** DB_HOST → host / port / socket (formats WordPress usuels). */
	private static function parse_db_host() {
		$host   = DB_HOST;
		$port   = null;
		$socket = null;
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $extra ) = explode( ':', DB_HOST, 2 );
			if ( is_numeric( $extra ) ) {
				$port = (int) $extra;
			} else {
				$socket = $extra;
			}
		}
		return array(
			'host'   => $host,
			'port'   => $port,
			'socket' => $socket,
		);
	}

	/**
	 * Dump via mysqldump : `--result-file` (code retour fiable, pas de pipe),
	 * puis compression gzip en flux PHP. Mot de passe via defaults-extra-file
	 * 0600, jamais sur la ligne de commande.
	 *
	 * @return true|WP_Error
	 */
	private static function dump_via_mysqldump( $file ) {
		$bin = self::find_mysqldump();
		if ( ! $bin ) {
			return new WP_Error( 'backup_mysqldump', __( 'Binaire mysqldump introuvable.', 'pardesign-entretien' ) );
		}

		$sql_tmp = preg_replace( '/\.gz$/', '', $file );

		// Credentials file: outside the web root (system temp dir), created by tempnam() in mode
		// 0600, removed in finally AND by a shutdown function so a killed process leaves nothing.
		$defaults = tempnam( get_temp_dir(), 'pdcnf' );
		if ( false === $defaults ) {
			return new WP_Error( 'backup_mysqldump', __( 'Impossible de créer le fichier de connexion temporaire.', 'pardesign-entretien' ) );
		}
		@chmod( $defaults, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		register_shutdown_function(
			static function () use ( $defaults ) {
				if ( file_exists( $defaults ) ) {
					@unlink( $defaults ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		);

		$conn  = self::parse_db_host();
		$lines = array( '[client]', 'user=' . self::cnf_quote( DB_USER ), 'password=' . self::cnf_quote( DB_PASSWORD ) );
		if ( $conn['socket'] ) {
			$lines[] = 'socket=' . $conn['socket'];
		} else {
			$lines[] = 'host=' . $conn['host'];
			if ( $conn['port'] ) {
				$lines[] = 'port=' . $conn['port'];
			}
		}

		if ( false === @file_put_contents( $defaults, implode( "\n", $lines ) . "\n" ) ) {
			return new WP_Error( 'backup_mysqldump', __( 'Impossible d’écrire le fichier de connexion temporaire.', 'pardesign-entretien' ) );
		}

		try {
			// mysqldump truncates an existing --result-file, so creating it first keeps mode 0600.
			self::create_private_file( $sql_tmp );
			$cmd = escapeshellarg( $bin )
				. ' --defaults-extra-file=' . escapeshellarg( $defaults )
				. ' --single-transaction --quick --no-tablespaces --skip-lock-tables'
				. ' --result-file=' . escapeshellarg( $sql_tmp )
				. ' ' . escapeshellarg( DB_NAME )
				. ' 2>&1';
			$out  = array();
			$code = 1;
			@exec( $cmd, $out, $code );

			if ( 0 !== $code || ! file_exists( $sql_tmp ) || filesize( $sql_tmp ) <= 0 ) {
				@unlink( $sql_tmp );
				return new WP_Error(
					'backup_mysqldump',
					sprintf(
						/* translators: 1: code retour, 2: sortie. */
						__( 'mysqldump a échoué (code %1$d) : %2$s', 'pardesign-entretien' ),
						$code,
						substr( implode( ' ', $out ), 0, 300 )
					)
				);
			}

			$gz = self::gzip_file( $sql_tmp, $file );
			@unlink( $sql_tmp );
			return $gz;
		} finally {
			@unlink( $defaults );
		}
	}

	/** Quote a value for a MySQL option file (double quotes; backslash and quote escaped). */
	private static function cnf_quote( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/** Compresse un fichier en flux (blocs de 512 Ko). @return true|WP_Error */
	private static function gzip_file( $src, $dest ) {
		if ( ! function_exists( 'gzopen' ) ) {
			// zlib absent (rarissime) : garder le .sql non compressé.
			return rename( $src . '', preg_replace( '/\.gz$/', '', $dest ) )
				? true
				: new WP_Error( 'backup_gzip', __( 'Compression indisponible et déplacement impossible.', 'pardesign-entretien' ) );
		}
		$in = @fopen( $src, 'rb' );
		if ( ! $in ) {
			return new WP_Error( 'backup_gzip', __( 'Lecture du dump temporaire impossible.', 'pardesign-entretien' ) );
		}
		self::create_private_file( $dest );
		$out = @gzopen( $dest, 'wb6' );
		if ( ! $out ) {
			fclose( $in );
			return new WP_Error( 'backup_gzip', __( 'Écriture du fichier compressé impossible.', 'pardesign-entretien' ) );
		}
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 524288 );
			if ( false === $chunk ) {
				break;
			}
			gzwrite( $out, $chunk );
		}
		fclose( $in );
		gzclose( $out );
		return true;
	}

	/**
	 * Dump PHP pur, mémoire O(1) : lecture non bufferisée (MYSQLI_USE_RESULT)
	 * ligne par ligne, écriture gzip streamée. Aucune autre requête ne doit
	 * partir pendant la lecture d'une table (connexion bloquée).
	 *
	 * @return true|WP_Error
	 */
	private static function dump_via_php( $file ) {
		global $wpdb;

		$use_gz = function_exists( 'gzopen' );
		if ( ! $use_gz ) {
			$file = preg_replace( '/\.gz$/', '', $file );
		}
		self::create_private_file( $file );
		$out = $use_gz ? @gzopen( $file, 'wb6' ) : @fopen( $file, 'wb' );
		if ( ! $out ) {
			return new WP_Error( 'backup_php', __( 'Impossible d’ouvrir le fichier de sauvegarde en écriture.', 'pardesign-entretien' ) );
		}
		$write = static function ( $s ) use ( $out, $use_gz ) {
			$use_gz ? gzwrite( $out, $s ) : fwrite( $out, $s );
		};
		$close = static function () use ( $out, $use_gz ) {
			$use_gz ? gzclose( $out ) : fclose( $out );
		};

		try {
			$write( "-- Sauvegarde PAR Design Entretien\n-- Base : " . DB_NAME . "\n-- Date : " . gmdate( 'c' ) . "\n\n" );
			$write( "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );

			$tables = $wpdb->get_col( 'SHOW TABLES' );
			if ( empty( $tables ) ) {
				$close();
				return new WP_Error( 'backup_php', __( 'Aucune table trouvée.', 'pardesign-entretien' ) );
			}

			$mysqli = ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) ? $wpdb->dbh : null;

			foreach ( $tables as $table ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_N );
				if ( ! $create || empty( $create[1] ) ) {
					continue;
				}
				$write( 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create[1] . ";\n\n" );

				if ( $mysqli ) {
					$ok = self::dump_rows_unbuffered( $mysqli, $table, $write );
				} else {
					$ok = self::dump_rows_chunked( $wpdb, $table, $write );
				}
				if ( is_wp_error( $ok ) ) {
					$close();
					return $ok;
				}
				$write( "\n" );
			}

			$write( "SET FOREIGN_KEY_CHECKS=1;\n" );
			$close();
			return true;
		} catch ( \Throwable $e ) {
			$close();
			return new WP_Error( 'backup_php', $e->getMessage() );
		}
	}

	/** Lignes d'une table en lecture non bufferisée. @return true|WP_Error */
	private static function dump_rows_unbuffered( \mysqli $mysqli, $table, $write ) {
		$res = $mysqli->query( 'SELECT * FROM `' . str_replace( '`', '', $table ) . '`', MYSQLI_USE_RESULT );
		if ( false === $res ) {
			return new WP_Error(
				'backup_php',
				sprintf(
					/* translators: 1: table, 2: erreur MySQL. */
					__( 'Lecture de la table %1$s impossible : %2$s', 'pardesign-entretien' ),
					$table,
					$mysqli->error
				)
			);
		}
		$buffer = '';
		$count  = 0;
		while ( $row = $res->fetch_row() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$values = array();
			foreach ( $row as $v ) {
				$values[] = null === $v ? 'NULL' : "'" . $mysqli->real_escape_string( (string) $v ) . "'";
			}
			$buffer .= ( 0 === $count % 500 ? ( $count ? ";\n" : '' ) . 'INSERT INTO `' . $table . '` VALUES ' : ',' )
				. '(' . implode( ',', $values ) . ')';
			$count++;
			if ( strlen( $buffer ) >= self::FLUSH_BYTES ) {
				$write( $buffer );
				$buffer = '';
			}
		}
		$res->free();
		if ( $count > 0 ) {
			$buffer .= ";\n";
		}
		if ( '' !== $buffer ) {
			$write( $buffer );
		}
		return true;
	}

	/** Repli : lignes par tranches LIMIT (dbh non mysqli). @return true|WP_Error */
	private static function dump_rows_chunked( $wpdb, $table, $write ) {
		$offset = 0;
		$chunk  = 1000;
		$quoted = '`' . str_replace( '`', '', $table ) . '`';
		while ( true ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$quoted} LIMIT {$offset}, {$chunk}", ARRAY_N );
			if ( empty( $rows ) ) {
				break;
			}
			$parts = array();
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $v ) {
					$values[] = null === $v ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
				}
				$parts[] = '(' . implode( ',', $values ) . ')';
			}
			$write( 'INSERT INTO ' . $quoted . ' VALUES ' . implode( ',', $parts ) . ";\n" );
			$offset += $chunk;
			if ( count( $rows ) < $chunk ) {
				break;
			}
		}
		return true;
	}

	/** Supprime un fichier partiel (échec) et son éventuel .sql temporaire. */
	private static function delete_partial( $file ) {
		@unlink( $file );
		@unlink( preg_replace( '/\.gz$/', '', $file ) );
	}

	/** Garde les KEEP sauvegardes les plus récentes. */
	private static function purge( $dir ) {
		$files = array_merge( (array) glob( $dir . '/*.sql.gz' ), (array) glob( $dir . '/*.sql' ) );
		$files = array_filter( $files );
		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);
		foreach ( array_slice( $files, self::KEEP ) as $old ) {
			@unlink( $old );
		}
	}
}
