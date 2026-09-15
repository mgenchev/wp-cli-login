<?php

namespace WpLogin;

use WP_CLI;

final class LoginCommand {
    private const ACTION_OPEN = 'open';
    private const ACTION_COPY = 'copy';
    private const ACTION_PRINT = 'print';

    /**
     * Log in to WordPress with a one-time browser link.
     *
     * ## OPTIONS
     *
     * [--open]
     * : Open the one-time login URL in the default browser without asking.
     *
     * [--copy]
     * : Copy the one-time login URL to the clipboard without asking.
     *
     * [--no-open]
     * : Legacy compatibility option. Print the one-time URL instead of opening or copying it.
     *
     * ## EXAMPLES
     *
     *     wp login
     *     wp login --user=admin --open
     *     wp login --user=42 --copy
     *
     * `--user=<id|login|email>` is the standard WP-CLI global parameter and is
     * used by this command as the requested login account.
     *
     * @when after_wp_config_load
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc_args
     */
    public function __invoke( $args, $assoc_args ) {
        unset( $args );

        global $table_prefix;

        if ( ! defined( 'ABSPATH' ) || ! is_dir( ABSPATH ) ) {
            WP_CLI::error( 'Could not determine the WordPress root directory.' );
        }

        if ( ! isset( $table_prefix ) || ! is_string( $table_prefix ) || '' === $table_prefix ) {
            WP_CLI::error( 'Could not determine the WordPress table prefix.' );
        }

        try {
            $database = Database::connect_from_config();
            $context  = SiteContext::resolve(
                $database,
                ABSPATH,
                $table_prefix,
                $this->get_global_config( 'url' )
            );
            $users     = new UserRepository( $database, $context->base_prefix, $context->site_prefix );
        } catch ( \RuntimeException $exception ) {
            WP_CLI::error( $exception->getMessage() );
        }

        $user = $this->resolve_user( $users );

        WP_CLI::log( sprintf( '✓ User selected: %s (#%d)', $user->user_login, $user->ID ) );

        $action = $this->resolve_action( $assoc_args );

        try {
            $login_link = new LoginLink();
            $result     = $login_link->create( $context->root, $context->site_url, (int) $user->ID );
        } catch ( \RuntimeException $exception ) {
            WP_CLI::error( $exception->getMessage() );
        }

        $this->perform_action( $action, $result['url'] );
        WP_CLI::log( 'Login link is valid for 1 minute and can be used once.' );
    }

    /**
     * @param UserRepository $users
     * @return object
     */
    private function resolve_user( UserRepository $users ) {
        $requested = $this->get_global_config( 'user' );

        if ( null !== $requested && false !== $requested && '' !== (string) $requested ) {
            $user = $users->find( (string) $requested );

            if ( ! $user ) {
                WP_CLI::error( sprintf( 'No WordPress user matched "%s".', (string) $requested ) );
            }

            return $user;
        }

        $selector = new UserSelector( $users );
        return $selector->select();
    }

    /**
     * @param array<string, mixed> $assoc_args
     * @return string
     */
    private function resolve_action( $assoc_args ) {
        $requested_actions = array();

        // WP-CLI normalizes --no-open to ['open' => false].
        // array_key_exists() is required here because isset() ignores false.
        if ( array_key_exists( 'open', $assoc_args ) ) {
            $requested_actions[] = false === $assoc_args['open']
                ? self::ACTION_PRINT
                : self::ACTION_OPEN;
        }

        if ( isset( $assoc_args['copy'] ) ) {
            $requested_actions[] = self::ACTION_COPY;
        }

        // Keep compatibility with direct/manual invocations that may still pass
        // the historical array shape instead of WP-CLI's normalized one.
        if ( isset( $assoc_args['no-open'] ) ) {
            $requested_actions[] = self::ACTION_PRINT;
        }

        if ( count( $requested_actions ) > 1 ) {
            WP_CLI::error( 'Choose only one login action: --open, --copy, or --no-open.' );
        }

        if ( 1 === count( $requested_actions ) ) {
            return $requested_actions[0];
        }

        WP_CLI::log( '' );
        WP_CLI::log( 'Login action:' );
        WP_CLI::log( '  1) Open in browser' );
        WP_CLI::log( '  2) Copy URL to clipboard' );

        $choice = $this->read_input( 'Select [1-2]: ' );

        if ( '1' === $choice ) {
            return self::ACTION_OPEN;
        }

        if ( '2' === $choice ) {
            return self::ACTION_COPY;
        }

        WP_CLI::error( 'Invalid selection. Choose 1 or 2.' );
    }

    /**
     * @param string $action
     * @param string $url
     * @return void
     */
    private function perform_action( $action, $url ) {
        if ( self::ACTION_OPEN === $action ) {
            $browser = new Browser();

            if ( $browser->open( $url ) ) {
                WP_CLI::log( '✓ Browser launch requested' );
                WP_CLI::log( 'Login URL: ' . $url );
                return;
            }

            $this->print_url( $url );
            return;
        }

        if ( self::ACTION_COPY === $action ) {
            $clipboard = new Clipboard();

            if ( $clipboard->copy( $url ) ) {
                WP_CLI::log( '✓ Login URL copied to clipboard' );
                return;
            }

            $this->print_url( $url );
            return;
        }

        $this->print_url( $url );
    }

    /**
     * @param string $url
     * @return void
     */
    private function print_url( $url ) {
        WP_CLI::log( 'Login URL: ' . $url );
    }

    /**
     * @param string $key
     * @return mixed
     */
    private function get_global_config( $key ) {
        if ( method_exists( 'WP_CLI', 'has_config' ) && ! WP_CLI::has_config( $key ) ) {
            return null;
        }

        return WP_CLI::get_config( $key );
    }

    /**
     * @param string $prompt
     * @return string
     */
    private function read_input( $prompt ) {
        fwrite( STDOUT, $prompt );
        $input = fgets( STDIN );

        if ( false === $input ) {
            WP_CLI::error( 'Interactive input is unavailable. Use --open or --copy.' );
        }

        return trim( $input );
    }
}
