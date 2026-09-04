<?php

$root = (string) getenv( 'WP_LOGIN_TEST_ROOT' );

if ( '' === $root || ! is_dir( $root ) ) {
    fwrite( STDERR, "Invalid test root.\n" );
    exit( 1 );
}

define( 'ABSPATH', rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR );

class WP_CLI {
    public static function log( $message ) {
        echo $message . "\n";
    }

    public static function warning( $message ) {
        echo 'Warning: ' . $message . "\n";
    }

    public static function success( $message ) {
        echo 'Success: ' . $message . "\n";
    }

    public static function error( $message ) {
        throw new RuntimeException( $message );
    }

    public static function get_runner() {
        throw new RuntimeException( 'WordPress should already be considered loaded in this scenario.' );
    }
}

function get_user_by( $field, $value ) {
    if ( ( 'id' === $field && 2 === (int) $value ) || ( 'login' === $field && 'editor' === $value ) ) {
        return (object) array(
            'ID'         => 2,
            'user_login' => 'editor',
            'user_email' => 'editor@example.test',
            'roles'      => array( 'editor' ),
        );
    }

    return false;
}

function site_url() {
    return 'https://example.test';
}

require dirname( __DIR__ ) . '/src/Spinner.php';
require dirname( __DIR__ ) . '/src/Browser.php';
require dirname( __DIR__ ) . '/src/LoginLink.php';
require dirname( __DIR__ ) . '/src/UserSelector.php';
require dirname( __DIR__ ) . '/src/LoginCommand.php';

$command = new WpLogin\LoginCommand();
$command( array(), array( 'user' => '2', 'no-open' => true ) );
