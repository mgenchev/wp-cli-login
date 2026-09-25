<?php

namespace WpLogin;

use WP_CLI;

final class UserSelector {
    private const PAGE_SIZE = 20;

    /** @var UserRepository */
    private $users;

    /**
     * @param UserRepository $users
     */
    public function __construct( UserRepository $users ) {
        $this->users = $users;
    }

    /**
     * @return object
     */
    public function select() {
        $users = $this->users->first_users( 2 );

        if ( empty( $users ) ) {
            WP_CLI::error( 'No WordPress users were found for this site.' );
        }

        if ( 1 === count( $users ) ) {
            return $users[0];
        }

        Terminal::clear_screen();
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
     * @return object
     */
    private function select_administrator() {
        $probe = $this->users->role_page( 'administrator', null, 0, 2 );

        if ( empty( $probe['users'] ) ) {
            WP_CLI::error( 'No administrator users were found for this site.' );
        }

        if ( 1 === count( $probe['users'] ) && ! $probe['has_more'] ) {
            return $probe['users'][0];
        }

        return $this->select_from_pages( 'administrator', null, 'Administrators' );
    }

    /**
     * @param string|null $required_role
     * @param string|null $excluded_role
     * @param string      $title
     * @return object
     */
    private function select_from_pages( $required_role, $excluded_role, $title ) {
        $pages      = array();
        $page_index = 0;
        $cursor     = 0;
        $notice     = null;

        while ( true ) {
            if ( ! isset( $pages[ $page_index ] ) ) {
                $page = $this->users->role_page( $required_role, $excluded_role, $cursor, self::PAGE_SIZE );

                if ( empty( $page['users'] ) ) {
                    if ( 0 === $page_index ) {
                        WP_CLI::error( sprintf( 'No %s were found.', strtolower( $title ) ) );
                    }

                    $page_index--;
                    $notice = 'There are no more users on the next page.';
                    continue;
                }

                $pages[ $page_index ] = $page;
            }

            $page = $pages[ $page_index ];

            Terminal::clear_screen();
            WP_CLI::log( '' );
            WP_CLI::log( sprintf( '%s — page %d', $title, $page_index + 1 ) );
            $this->print_users( $page['users'] );

            WP_CLI::log( '' );
            WP_CLI::log( 'Enter a list number, exact login/email, n for next page, or p for previous page.' );

            if ( null !== $notice ) {
                WP_CLI::warning( $notice );
                $notice = null;
            }

            $choice = $this->read_input( 'Select: ' );

            if ( 'n' === strtolower( $choice ) ) {
                if ( ! $page['has_more'] ) {
                    $notice = 'There are no more users on the next page.';
                    continue;
                }

                $cursor = (int) $page['next_cursor'];
                $page_index++;
                continue;
            }

            if ( 'p' === strtolower( $choice ) ) {
                if ( 0 === $page_index ) {
                    $notice = 'You are already on the first page.';
                    continue;
                }

                $page_index--;
                continue;
            }

            $selected = $this->resolve_choice( $choice, $page['users'] );

            if ( $selected && $this->users->matches_roles( $selected, $required_role, $excluded_role ) ) {
                return $selected;
            }

            $notice = 'No user matched that selection.';
        }
    }

    /**
     * @param string             $choice
     * @param array<int, object> $users
     * @return object|null
     */
    private function resolve_choice( $choice, $users ) {
        if ( ctype_digit( $choice ) ) {
            $index = (int) $choice - 1;

            if ( isset( $users[ $index ] ) ) {
                return $users[ $index ];
            }

            return null;
        }

        return $this->users->find( $choice );
    }

    /**
     * @param array<int, object> $users
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
