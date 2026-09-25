<?php

namespace WpLogin;

final class Terminal {
    /**
     * @return bool
     */
    public static function is_interactive() {
        if ( ! defined( 'STDOUT' ) ) {
            return false;
        }

        if ( function_exists( 'stream_isatty' ) ) {
            return @stream_isatty( STDOUT );
        }

        return function_exists( 'posix_isatty' ) ? @posix_isatty( STDOUT ) : false;
    }

    /**
     * @param bool|null $interactive
     * @return void
     */
    public static function clear_screen( $interactive = null ) {
        if ( null === $interactive ) {
            $interactive = self::is_interactive();
        }

        if ( $interactive ) {
            fwrite( STDOUT, "\033[2J\033[H" );
        }
    }
}
