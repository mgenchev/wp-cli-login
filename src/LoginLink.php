<?php

namespace WpLogin;

final class LoginLink {
    private const FILE_PREFIX = 'wp-cli-login-';
    private const TOKEN_BYTES = 32;
    private const TTL_SECONDS = 60;
    private const CLEANUP_AGE_SECONDS = 600;

    /**
     * @param string $wordpress_root
     * @param string $site_url
     * @param int    $user_id
     * @return array{url:string,path:string,expires_at:int}
     */
    public function create( $wordpress_root, $site_url, $user_id ) {
        $root = $this->normalize_root( $wordpress_root );

        if ( $user_id <= 0 ) {
            throw new \RuntimeException( 'Cannot create a login link for an invalid user ID.' );
        }

        if ( ! is_writable( $root ) ) {
            throw new \RuntimeException( sprintf( 'WordPress root is not writable: %s', $root ) );
        }

        $this->cleanup_stale_files( $root );

        try {
            $token       = bin2hex( random_bytes( self::TOKEN_BYTES ) );
            $file_random = bin2hex( random_bytes( 12 ) );
        } catch ( \Exception $exception ) {
            throw new \RuntimeException( 'Could not generate secure random data for the login link.' );
        }

        $filename   = self::FILE_PREFIX . $file_random . '.php';
        $path       = $root . DIRECTORY_SEPARATOR . $filename;
        $expires_at = time() + self::TTL_SECONDS;
        $token_hash = hash( 'sha256', $token );
        $source     = $this->build_bridge_source( $token_hash, $user_id, $expires_at );

        $handle = @fopen( $path, 'x' );

        if ( false === $handle ) {
            throw new \RuntimeException( sprintf( 'Could not create the temporary login endpoint: %s', $path ) );
        }

        $written = fwrite( $handle, $source );
        fclose( $handle );

        if ( false === $written || $written !== strlen( $source ) ) {
            @unlink( $path );
            throw new \RuntimeException( sprintf( 'Could not write the temporary login endpoint: %s', $path ) );
        }

        @chmod( $path, 0644 );

        $url = rtrim( $site_url, '/' ) . '/' . rawurlencode( $filename ) . '?token=' . rawurlencode( $token );

        return array(
            'url'        => $url,
            'path'       => $path,
            'expires_at' => $expires_at,
        );
    }

    /**
     * @param string $wordpress_root
     * @return string
     */
    private function normalize_root( $wordpress_root ) {
        $root = realpath( $wordpress_root );

        if ( false === $root || ! is_dir( $root ) ) {
            throw new \RuntimeException( sprintf( 'Invalid WordPress root directory: %s', $wordpress_root ) );
        }

        return rtrim( $root, '/\\' );
    }

    /**
     * @param string $root
     * @return void
     */
    private function cleanup_stale_files( $root ) {
        $pattern = $root . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*.php';
        $paths   = glob( $pattern );

        if ( false === $paths ) {
            return;
        }

        $threshold = time() - self::CLEANUP_AGE_SECONDS;

        foreach ( $paths as $path ) {
            if ( ! is_file( $path ) && ! is_link( $path ) ) {
                continue;
            }

            $modified = @filemtime( $path );

            if ( false !== $modified && $modified < $threshold ) {
                @unlink( $path );
            }
        }
    }

    /**
     * @param string $token_hash
     * @param int    $user_id
     * @param int    $expires_at
     * @return string
     */
    private function build_bridge_source( $token_hash, $user_id, $expires_at ) {
        return sprintf(
            <<<'PHP'
<?php

$expected_hash = '%s';
$user_id       = %d;
$expires_at    = %d;
$token         = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';

if ( time() > $expires_at ) {
    @unlink( __FILE__ );
    http_response_code( 410 );
    exit( 'Login link expired.' );
}

if ( '' === $token || ! hash_equals( $expected_hash, hash( 'sha256', $token ) ) ) {
    http_response_code( 403 );
    exit( 'Invalid login link.' );
}

$lock = @fopen( __FILE__, 'r' );

if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
    http_response_code( 409 );
    exit( 'Login link is already being used.' );
}

define( 'WP_USE_THEMES', false );
require __DIR__ . '/wp-load.php';

$user = get_user_by( 'id', $user_id );

if ( ! $user ) {
    @unlink( __FILE__ );
    http_response_code( 404 );
    exit( 'WordPress user no longer exists.' );
}

@unlink( __FILE__ );
wp_clear_auth_cookie();
wp_set_current_user( $user_id );
wp_set_auth_cookie( $user_id, false, is_ssl() );
wp_safe_redirect( admin_url() );
exit;

PHP,
            $token_hash,
            $user_id,
            $expires_at
        );
    }
}
