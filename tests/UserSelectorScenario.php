<?php

$users_data = json_decode( (string) getenv( 'WP_LOGIN_TEST_USERS' ), true );

if ( ! is_array( $users_data ) ) {
    fwrite( STDERR, "Invalid test users.\n" );
    exit( 1 );
}

class WP_CLI {
    public static function log( $message ) {
        echo $message . "\n";
    }

    public static function warning( $message ) {
        echo 'Warning: ' . $message . "\n";
    }

    public static function error( $message ) {
        throw new RuntimeException( $message );
    }
}

require dirname( __DIR__ ) . '/src/Database.php';
require dirname( __DIR__ ) . '/src/UserRepository.php';
require __DIR__ . '/FakeDatabase.php';
require dirname( __DIR__ ) . '/src/UserSelector.php';

$rows = array();
$meta = array();

foreach ( $users_data as $user_data ) {
    $rows[] = array(
        'ID'         => (int) $user_data['ID'],
        'user_login' => (string) $user_data['user_login'],
        'user_email' => (string) $user_data['user_email'],
    );

    $caps = array();

    foreach ( (array) $user_data['roles'] as $role ) {
        $caps[ $role ] = true;
    }

    $meta[] = array(
        'user_id'    => (int) $user_data['ID'],
        'meta_key'   => 'wp_capabilities',
        'meta_value' => serialize( $caps ),
    );
}

$database   = new WpLoginTests\FakeDatabase( $rows, $meta );
$repository = new WpLogin\UserRepository( $database, 'wp_', 'wp_' );
$selector   = new WpLogin\UserSelector( $repository );
$user       = $selector->select();

echo 'SELECTED:' . $user->user_login . '#' . $user->ID . "\n";
