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
            'user' => 'editor',
        );

        public static function log( $message ) {
            echo $message . "\n";
        }

        public static function warning( $message ) {
            echo 'Warning: ' . $message . "\n";
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
            if ( 'editor' !== $value ) {
                return null;
            }

            return (object) array(
                'ID'         => 2,
                'user_login' => 'editor',
                'user_email' => 'editor@example.test',
                'roles'      => array( 'editor' ),
            );
        }
    }

    class UserSelector {
        public function __construct( $users ) {
            unset( $users );
        }

        public function select() {
            throw new \RuntimeException( 'Interactive user picker should not run in this scenario.' );
        }
    }

    class Browser {
        public function open( $url ) {
            unset( $url );
            return true;
        }
    }

    class Clipboard {
        public function copy( $url ) {
            unset( $url );
            return true;
        }
    }
}

namespace {
    require dirname( __DIR__ ) . '/src/LoginLink.php';
    require dirname( __DIR__ ) . '/src/Terminal.php';
    require dirname( __DIR__ ) . '/src/LoginCommand.php';

    $assoc_args = array();
    $action     = (string) getenv( 'WP_LOGIN_TEST_ACTION' );

    if ( 'open' === $action ) {
        $assoc_args['open'] = true;
    } elseif ( 'copy' === $action ) {
        $assoc_args['copy'] = true;
    } elseif ( 'interactive' === $action ) {
        // Intentionally prompt.
    } elseif ( 'conflict' === $action ) {
        $assoc_args['open'] = true;
        $assoc_args['copy'] = true;
    } else {
        $assoc_args['open'] = false;
    }

    $command = new WpLogin\LoginCommand();
    $command( array(), $assoc_args );
}
