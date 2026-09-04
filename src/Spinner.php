<?php

namespace WpLogin;

final class Spinner {
    private const INTERVAL_MICROSECONDS = 80000;
    private const MAX_RUNTIME_SECONDS = 300;

    /** @var resource|null */
    private $process;

    /** @var string|null */
    private $stop_file;

    /** @var bool */
    private $animated = false;

    /** @var bool */
    private $started = false;

    /**
     * @param string $message
     * @return void
     */
    public function start( $message ) {
        if ( $this->started ) {
            return;
        }

        $this->started = true;

        if ( $this->start_animated( $message ) ) {
            $this->animated = true;
            return;
        }

        fwrite( STDOUT, '⠋ ' . $message );
        fflush( STDOUT );
    }

    /**
     * @return void
     */
    public function stop() {
        if ( ! $this->started ) {
            return;
        }

        $this->stop_process();

        if ( $this->animated || $this->stdout_is_tty() ) {
            fwrite( STDOUT, "\r\033[2K" );
            fflush( STDOUT );
        } else {
            fwrite( STDOUT, PHP_EOL );
        }

        $this->reset();
    }

    /**
     * @param string $message
     * @return bool
     */
    private function start_animated( $message ) {
        if ( ! $this->stdout_is_tty() || ! function_exists( 'proc_open' ) || ! defined( 'PHP_BINARY' ) ) {
            return false;
        }

        if ( '' === PHP_BINARY || ! is_file( PHP_BINARY ) ) {
            return false;
        }

        try {
            $stop_file = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'wp-login-spinner-' . bin2hex( random_bytes( 8 ) ) . '.stop';
        } catch ( \Exception $exception ) {
            return false;
        }

        if ( file_exists( $stop_file ) ) {
            @unlink( $stop_file );
        }

        $script = <<<'PHP'
$frames = array( '⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏' );
$message = isset( $argv[1] ) ? $argv[1] : 'Working...';
$stop_file = isset( $argv[2] ) ? $argv[2] : '';
$index = 0;
$started_at = microtime( true );

while ( '' !== $stop_file && ! file_exists( $stop_file ) && microtime( true ) - $started_at < %d ) {
    fwrite( STDOUT, "\r\033[2K" . $frames[ $index ] . ' ' . $message );
    fflush( STDOUT );
    $index = ( $index + 1 ) %% count( $frames );
    usleep( %d );
}
PHP;
        $script = sprintf( $script, self::MAX_RUNTIME_SECONDS, self::INTERVAL_MICROSECONDS );

        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => STDOUT,
            2 => STDERR,
        );

        $process = @proc_open(
            array( PHP_BINARY, '-r', $script, $message, $stop_file ),
            $descriptors,
            $pipes,
            null,
            null,
            array( 'bypass_shell' => true )
        );

        if ( ! is_resource( $process ) || ! isset( $pipes[0] ) || ! is_resource( $pipes[0] ) ) {
            if ( is_resource( $process ) ) {
                @proc_terminate( $process );
                @proc_close( $process );
            }

            @unlink( $stop_file );
            return false;
        }

        fclose( $pipes[0] );

        $this->process   = $process;
        $this->stop_file = $stop_file;

        return true;
    }

    /**
     * @return void
     */
    private function stop_process() {
        if ( null !== $this->stop_file ) {
            $stopped = false !== @file_put_contents( $this->stop_file, '1' );

            if ( ! $stopped && is_resource( $this->process ) ) {
                @proc_terminate( $this->process );
            }
        }

        if ( is_resource( $this->process ) ) {
            @proc_close( $this->process );
        }

        if ( null !== $this->stop_file ) {
            @unlink( $this->stop_file );
        }

        $this->process   = null;
        $this->stop_file = null;
    }

    /**
     * @return bool
     */
    private function stdout_is_tty() {
        return function_exists( 'stream_isatty' ) && defined( 'STDOUT' ) && @stream_isatty( STDOUT );
    }

    /**
     * @return void
     */
    private function reset() {
        $this->animated = false;
        $this->started  = false;
    }
}
