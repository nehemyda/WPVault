<?php
/**
 * Read-only access to a .wpvault package during restore. A thin wrapper
 * around ZipArchive kept separate from Backup\Package_Builder because that
 * class's job is building/finalizing a package across chunks; this one's
 * job is only ever reading an already-finished one.
 */

namespace WPVault\Restore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Package_Reader {

	/** @var \ZipArchive */
	private $zip;

	private $path;

	public function __construct( $path ) {
		$this->path = $path;
		$this->zip  = new \ZipArchive();

		if ( true !== $this->zip->open( $path ) ) {
			throw new \RuntimeException( __( 'Could not open the backup package.', 'wpvault' ) );
		}
	}

	public function manifest() {
		$json = $this->zip->getFromName( 'manifest.json' );

		if ( false === $json ) {
			throw new \RuntimeException( __( 'The package has no manifest.json.', 'wpvault' ) );
		}

		$manifest = json_decode( $json, true );

		if ( ! is_array( $manifest ) ) {
			throw new \RuntimeException( __( 'The package manifest could not be read.', 'wpvault' ) );
		}

		return $manifest;
	}

	public function num_entries() {
		return $this->zip->numFiles;
	}

	/**
	 * @return array{name:string,is_dir:bool}|null Entry info at $index, or
	 *                                              null past the end.
	 */
	public function entry_at( $index ) {
		$name = $this->zip->getNameIndex( $index );

		if ( false === $name ) {
			return null;
		}

		return array(
			'name'   => $name,
			'is_dir' => '/' === substr( $name, -1 ),
		);
	}

	/**
	 * A read stream for one entry -- the caller is expected to
	 * stream_copy_to_stream() it rather than read it whole, so a large file
	 * inside the package is never fully buffered in memory during restore
	 * either.
	 *
	 * @return resource
	 */
	public function open_stream( $name ) {
		$stream = $this->zip->getStream( $name );

		if ( false === $stream ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: name of the file inside the package */
					__( 'Could not read "%s" from the package.', 'wpvault' ),
					$name
				)
			);
		}

		return $stream;
	}

	public function has_entry( $name ) {
		return false !== $this->zip->locateName( $name );
	}

	/**
	 * Copies the database component out to a plain file on disk so
	 * Database_Importer can gzseek/gztell it across many chunks -- a
	 * ZipArchive entry stream doesn't support seeking, but a real file does.
	 */
	public function extract_database_to( $destination_path ) {
		if ( ! $this->has_entry( 'database/database.sql.gz' ) ) {
			return false;
		}

		$source = $this->open_stream( 'database/database.sql.gz' );
		$target = fopen( $destination_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		stream_copy_to_stream( $source, $target );

		fclose( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	public function close() {
		$this->zip->close();
	}
}
