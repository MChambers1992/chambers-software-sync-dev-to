<?php
/**
 * Builds the plugin release zip locally — the same file set deploy.yml's
 * "build" job produces (same .distignore exclusions, same chambers-software-sync-dev-to/
 * folder nesting), without needing a tag push or rsync/zip CLI tools that
 * may not exist on every machine. Useful for checking exactly what a
 * release would contain, or building one ad hoc without cutting a release.
 *
 * Usage: php bin/build-zip.php
 * Output: build/chambers-software-sync-dev-to-{version}.zip
 */

$root = dirname( __DIR__ );
chdir( $root );

// ── Read plugin version from the header ─────────────────────────────────
$main_file = 'chambers-software-sync-dev-to.php';
$header    = file_get_contents( $main_file );
if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $header, $m ) ) {
    fwrite( STDERR, "Could not find a Version: header in {$main_file}\n" );
    exit( 1 );
}
$version = $m[1];

// ── Load .distignore patterns ────────────────────────────────────────────
$patterns = [];
foreach ( file( '.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
    $line = trim( $line );
    if ( $line === '' || strpos( $line, '#' ) === 0 ) {
        continue;
    }
    $patterns[] = ltrim( $line, '/' );
}

/**
 * Whether a relative path (file or directory) is excluded by .distignore —
 * either an exact match, or nested inside an excluded directory.
 */
function is_excluded( string $relative_path, array $patterns ): bool {
    foreach ( $patterns as $pattern ) {
        if ( strpos( $pattern, '*' ) !== false ) {
            if ( fnmatch( $pattern, $relative_path ) ) {
                return true;
            }
            continue;
        }
        if ( $relative_path === $pattern || strpos( $relative_path, $pattern . '/' ) === 0 ) {
            return true;
        }
    }
    return false;
}

// ── Collect files from git's tracked file list, not the raw filesystem —
//    CI builds from a clean `actions/checkout`, which only ever contains
//    committed files. Walking the working directory directly would let
//    stray local artifacts (build caches, IDE files, anything gitignored
//    but still sitting on disk) leak into a "local" zip that CI could
//    never actually produce, silently diverging from the real release. ──
exec( 'git ls-files', $tracked_files, $exit_code );
if ( $exit_code !== 0 || empty( $tracked_files ) ) {
    fwrite( STDERR, "`git ls-files` failed — is this a git repository?\n" );
    exit( 1 );
}

$files = [];
foreach ( $tracked_files as $relative ) {
    $relative = str_replace( '\\', '/', $relative );
    if ( ! is_excluded( $relative, $patterns ) && is_file( $relative ) ) {
        $files[] = $relative;
    }
}
sort( $files );

if ( empty( $files ) ) {
    fwrite( STDERR, "No files matched — check .distignore isn't excluding everything.\n" );
    exit( 1 );
}

// ── Build the zip ─────────────────────────────────────────────────────────
$slug      = 'chambers-software-sync-dev-to';
$build_dir = 'build';
if ( ! is_dir( $build_dir ) ) {
    mkdir( $build_dir );
}
$zip_path = "{$build_dir}/{$slug}-{$version}.zip";
if ( file_exists( $zip_path ) ) {
    unlink( $zip_path );
}

$zip = new ZipArchive();
if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) {
    fwrite( STDERR, "Could not create {$zip_path}\n" );
    exit( 1 );
}

foreach ( $files as $file ) {
    $zip->addFile( $file, "{$slug}/{$file}" );
}
$zip->close();

$size_kb = round( filesize( $zip_path ) / 1024, 1 );
echo "Built {$zip_path} (" . count( $files ) . " files, {$size_kb} KB)\n";
foreach ( $files as $file ) {
    echo "  {$file}\n";
}
