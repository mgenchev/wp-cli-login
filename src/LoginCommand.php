<?php

namespace WpLogin;

use WP_CLI;

final class LoginCommand {
    /**
     * Log in to WordPress with a one-time browser link.
     *
     * ## OPTIONS
     *
     * [--user=<user>]
     * : User ID, login, or email. Skips the interactive user picker.
     *
     * [--no-open]
     * : Do not open the browser automatically. Print the one-time URL instead.
     *
     * ## EXAMPLES
     *
     *     wp login
     *     wp login --user=admin
     *     wp login --user=42 --no-open
     *
     * @when before_wp_load
     *
     * @param array<int, string>        $args
     * @param array<string, mixed>      $assoc_args
     */
    public function __invoke( $args, $assoc_args ) {
        unset( $args );

        $this->load_wordpress();

        if ( ! defined( 'ABSPATH' ) || ! is_dir( ABSPATH ) ) {
            WP_CLI::error( 'Could not determine the WordPress root directory.' );
        }

        $user = $this->resolve_user( $assoc_args );

        WP_CLI::log( sprintf( '✓ User selected: %s (#%d)', $user->user_login, $user->ID ) );

        try {
            $login_link = new LoginLink();
            $result     = $login_link->create( ABSPATH, site_url(), (int) $user->ID );
        } catch ( \RuntimeException $exception ) {
            WP_CLI::error( $exception->getMessage() );
        }

        $should_open = ! isset( $assoc_args['no-open'] );

        if ( $should_open ) {
            $browser = new Browser();

            if ( $browser->open( $result['url'] ) ) {
                WP_CLI::log( '✓ Browser launch requested' );
                WP_CLI::log( 'Login URL: ' . $result['url'] );
                WP_CLI::log( 'Login link is valid for 1 minute and can be used once.' );
                return;
            }
        }

        WP_CLI::log( 'Open this URL:' );
        WP_CLI::log( $result['url'] );
        WP_CLI::log( 'Login link is valid for 1 minute and can be used once.' );
    }

    /**
     * @param array<string, mixed> $assoc_args
     * @return \WP_User
     */
    private function resolve_user( $assoc_args ) {
        if ( isset( $assoc_args['user'] ) && '' !== (string) $assoc_args['user'] ) {
            $user = $this->find_user( (string) $assoc_args['user'] );

            if ( ! $user ) {
                WP_CLI::error( sprintf( 'No WordPress user matched "%s".', (string) $assoc_args['user'] ) );
            }

            return $user;
        }

        $selector = new UserSelector();
        return $selector->select();
    }

    /**
     * @param string $value
     * @return \WP_User|false
     */
    private function find_user( $value ) {
        if ( ctype_digit( $value ) ) {
            $user = get_user_by( 'id', (int) $value );

            if ( $user ) {
                return $user;
            }
        }

        $user = get_user_by( 'login', $value );

        if ( $user ) {
            return $user;
        }

        if ( false !== strpos( $value, '@' ) ) {
            $user = get_user_by( 'email', $value );

            if ( $user ) {
                return $user;
            }
        }

        return false;
    }

    /**
     * Load WordPress only after the command has had a chance to show progress.
     *
     * @return void
     */
    private function load_wordpress() {
        if ( function_exists( 'get_user_by' ) ) {
            return;
        }

        $spinner = new Spinner();
        $spinner->start( 'Preparing WordPress...' );

        try {
            WP_CLI::get_runner()->load_wordpress();
        } catch ( \Throwable $throwable ) {
            $spinner->stop();
            throw $throwable;
        }

        $spinner->stop();
    }
}
