<?php

namespace WpLogin;

use WP_CLI;
use WP_User_Query;

final class UserSelector {
    private const PAGE_SIZE = 20;

    /**
     * @return \WP_User
     */
    public function select() {
        $users = $this->get_initial_users();

        if ( 1 === count( $users ) ) {
            return $users[0];
        }

        WP_CLI::log( '' );
        WP_CLI::log( 'Login as:' );
        WP_CLI::log( '  1) Administrator' );
        WP_CLI::log( '  2) Choose existing user' );

        $choice = $this->read_input( 'Select [1-2]: ' );

        if ( '1' === $choice ) {
            return $this->select_administrator();
        }

        if ( '2' === $choice ) {
            return $this->select_from_pages( null, 'administrator', 'Users' );
        }

        WP_CLI::error( 'Invalid selection. Choose 1 or 2.' );
    }

    /**
     * Fetch at most two users so single-user sites can skip the interactive picker.
     *
     * @return array<int, \WP_User>
     */
    private function get_initial_users() {
        $query = new WP_User_Query(
            array(
                'orderby' => 'ID',
                'order'   => 'ASC',
                'number'  => 2,
            )
        );

        $users = $query->get_results();

        if ( empty( $users ) ) {
            WP_CLI::error( 'No WordPress users were found for this site.' );
        }

        return $users;
    }

    /**
     * @return \WP_User
     */
    private function select_administrator() {
        $query = new WP_User_Query(
            array(
                'role'    => 'administrator',
                'orderby' => 'ID',
                'order'   => 'ASC',
                'number'  => 2,
            )
        );

        $users = $query->get_results();

        if ( empty( $users ) ) {
            WP_CLI::error( 'No administrator users were found for this site.' );
        }

        if ( 1 === count( $users ) ) {
            return $users[0];
        }

        return $this->select_from_pages( 'administrator', null, 'Administrators' );
    }

    /**
     * @param string|null $required_role
     * @param string|null $excluded_role
     * @param string      $title
     * @return \WP_User
     */
    private function select_from_pages( $required_role, $excluded_role, $title ) {
        $page = 1;

        while ( true ) {
            $query_args = array(
                'orderby' => 'ID',
                'order'   => 'ASC',
                'number'  => self::PAGE_SIZE,
                'paged'   => $page,
            );

            if ( null !== $required_role ) {
                $query_args['role'] = $required_role;
            }

            if ( null !== $excluded_role ) {
                $query_args['role__not_in'] = array( $excluded_role );
            }

            $query = new WP_User_Query( $query_args );
            $users = $query->get_results();

            if ( empty( $users ) ) {
                if ( 1 === $page ) {
                    WP_CLI::error( sprintf( 'No %s were found.', strtolower( $title ) ) );
                }

                $page--;
                WP_CLI::warning( 'There are no more users on the next page.' );
                continue;
            }

            WP_CLI::log( '' );
            WP_CLI::log( sprintf( '%s — page %d', $title, $page ) );
            $this->print_users( $users );

            WP_CLI::log( '' );
            WP_CLI::log( 'Enter a list number, exact login/email, n for next page, or p for previous page.' );
            $choice = $this->read_input( 'Select: ' );

            if ( 'n' === strtolower( $choice ) ) {
                $page++;
                continue;
            }

            if ( 'p' === strtolower( $choice ) ) {
                $page = max( 1, $page - 1 );
                continue;
            }

            $selected = $this->resolve_choice( $choice, $users );

            if ( $selected && $this->matches_roles( $selected, $required_role, $excluded_role ) ) {
                return $selected;
            }

            WP_CLI::warning( 'No user matched that selection.' );
        }
    }

    /**
     * @param string                $choice
     * @param array<int, \WP_User> $users
     * @return \WP_User|false
     */
    private function resolve_choice( $choice, $users ) {
        if ( ctype_digit( $choice ) ) {
            $index = (int) $choice - 1;

            if ( isset( $users[ $index ] ) ) {
                return $users[ $index ];
            }

            return false;
        }

        $user = get_user_by( 'login', $choice );

        if ( ! $user && false !== strpos( $choice, '@' ) ) {
            $user = get_user_by( 'email', $choice );
        }

        return $user ? $user : false;
    }

    /**
     * @param \WP_User    $user
     * @param string|null $required_role
     * @param string|null $excluded_role
     * @return bool
     */
    private function matches_roles( $user, $required_role, $excluded_role ) {
        $roles = (array) $user->roles;

        if ( null !== $required_role && ! in_array( $required_role, $roles, true ) ) {
            return false;
        }

        if ( null !== $excluded_role && in_array( $excluded_role, $roles, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, \WP_User> $users
     * @return void
     */
    private function print_users( $users ) {
        foreach ( $users as $index => $user ) {
            $email = $user->user_email ? sprintf( ' <%s>', $user->user_email ) : '';

            WP_CLI::log(
                sprintf(
                    '  %2d) %-24s #%d%s',
                    $index + 1,
                    $user->user_login,
                    $user->ID,
                    $email
                )
            );
        }
    }

    /**
     * @param string $prompt
     * @return string
     */
    private function read_input( $prompt ) {
        fwrite( STDOUT, $prompt );
        $input = fgets( STDIN );

        if ( false === $input ) {
            WP_CLI::error( 'Interactive input is unavailable. Use --user=<id|login|email> instead.' );
        }

        return trim( $input );
    }
}
