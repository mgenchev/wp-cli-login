<?php

namespace {
    $root = (string) getenv( 'WP_LOGIN_TEST_ROOT' );

    if ( '' === $root || ! is_dir( $root ) ) {
        fwrite( STDERR, "Invalid test root.\n" );
        exit( 1 );
    }

    define( 'ABSPATH', rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR );
    define( 'DB_HOST', 'localhost' );
    define( 'DB_NAME', 'wordpress' );
    define( 'DB_USER', 'root' );
    define( 'DB_PASSWORD', '' );

    $table_prefix = 'wp_';

    class WP_CLI {
        public static $config = array(
            'user' => 'Lindstrom',
        );

        public static function log( $message ) {
            echo $message . "\n";
        }

        public static function error( $message ) {
            throw new RuntimeException( $message );
        }

        public static function has_config( $key ) {
            return array_key_exists( $key, self::$config );
        }

        public static function get_config( $key = null ) {
            if ( null === $key ) {
                return self::$config;
            }

            return isset( self::$config[ $key ] ) ? self::$config[ $key ] : null;
        }
    }
}

namespace WpLogin {
    class Database {
        public static function connect_from_config() {
            return new self();
        }
    }

    class SiteContext {
        public $root;
        public $base_prefix = 'wp_';
        public $site_prefix = 'wp_';
        public $site_url = 'https://example.test';

        public static function resolve( $database, $root, $prefix, $url = null ) {
            unset( $database, $prefix, $url );
            $context = new self();
            $context->root = rtrim( $root, '/\\' );
            return $context;
        }
    }

    class UserRepository {
        public function __construct( $database, $base_prefix, $site_prefix ) {
            unset( $database, $base_prefix, $site_prefix );
        }

        public function find( $value ) {
            if ( 'Lindstrom' !== $value ) {
                return null;
            }

            return (object) array(
                'ID'         => 4272,
                'user_login' => 'Lindstrom',
                'user_email' => 'lindstrom@example.test',
                'roles'      => array( 'customer' ),
            );
        }
    }

    class UserSelector {
        public function __construct( $users ) {
            unset( $users );
            throw new \RuntimeException( 'Interactive selector must not be constructed when global --user is set.' );
        }
    }

    class LoginLink {
        public function create( $root, $url, $user_id ) {
            unset( $root, $url );
            return array(
                'url'        => 'https://example.test/login?user=' . $user_id,
                'path'       => '/tmp/fake',
                'expires_at' => time() + 60,
            );
        }
    }

    class Browser {
        public function open( $url ) {
            unset( $url );
            return false;
        }
    }

    class Clipboard {
        public function copy( $url ) {
            unset( $url );
            return false;
        }
    }
}

namespace {
    require dirname( __DIR__ ) . '/src/LoginCommand.php';

    $command = new WpLogin\LoginCommand();
    $command( array(), array( 'no-open' => true ) );
}
