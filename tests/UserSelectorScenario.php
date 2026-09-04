<?php

$users_data = json_decode( (string) getenv( 'WP_LOGIN_TEST_USERS' ), true );

if ( ! is_array( $users_data ) ) {
    fwrite( STDERR, "Invalid test users.\n" );
    exit( 1 );
}

$test_users = array();

foreach ( $users_data as $user_data ) {
    $user = new stdClass();
    $user->ID         = (int) $user_data['ID'];
    $user->user_login = (string) $user_data['user_login'];
    $user->user_email = (string) $user_data['user_email'];
    $user->roles      = (array) $user_data['roles'];
    $test_users[]     = $user;
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

class WP_User_Query {
    private $args;

    public function __construct( $args ) {
        $this->args = $args;
    }

    public function get_results() {
        global $test_users;

        $users = $test_users;

        if ( isset( $this->args['role'] ) ) {
            $role = (string) $this->args['role'];
            $users = array_values(
                array_filter(
                    $users,
                    function ( $user ) use ( $role ) {
                        return in_array( $role, (array) $user->roles, true );
                    }
                )
            );
        }

        if ( isset( $this->args['role__not_in'] ) ) {
            $excluded_roles = (array) $this->args['role__not_in'];
            $users = array_values(
                array_filter(
                    $users,
                    function ( $user ) use ( $excluded_roles ) {
                        return empty( array_intersect( $excluded_roles, (array) $user->roles ) );
                    }
                )
            );
        }

        $number = isset( $this->args['number'] ) ? (int) $this->args['number'] : count( $users );
        $page   = isset( $this->args['paged'] ) ? max( 1, (int) $this->args['paged'] ) : 1;
        $offset = ( $page - 1 ) * $number;

        return array_slice( $users, $offset, $number );
    }
}

function get_user_by( $field, $value ) {
    global $test_users;

    foreach ( $test_users as $user ) {
        if ( 'id' === $field && (int) $value === (int) $user->ID ) {
            return $user;
        }

        if ( 'login' === $field && (string) $value === $user->user_login ) {
            return $user;
        }

        if ( 'email' === $field && (string) $value === $user->user_email ) {
            return $user;
        }
    }

    return false;
}

require dirname( __DIR__ ) . '/src/UserSelector.php';

$selector = new WpLogin\UserSelector();
$user     = $selector->select();

echo 'SELECTED:' . $user->user_login . '#' . $user->ID . "\n";
