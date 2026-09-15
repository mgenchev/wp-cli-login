<?php

namespace WpLogin;

class Clipboard {
    /**
     * @param string $text
     * @return bool
     */
    public function copy( $text ) {
        $commands = $this->build_commands();

        foreach ( $commands as $command ) {
            if ( $this->run_command( $command, $text ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function build_commands() {
        $os_family = $this->get_os_family();

        if ( 'Darwin' === $os_family ) {
            return $this->build_macos_commands();
        }

        if ( 'Windows' === $os_family ) {
            return $this->build_windows_commands();
        }

        if ( 'Linux' === $os_family ) {
            if ( $this->is_wsl() ) {
                return $this->build_wsl_commands();
            }

            return $this->build_linux_commands();
        }

        return array();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function build_macos_commands() {
        $pbcopy = '/usr/bin/pbcopy';

        if ( is_file( $pbcopy ) && is_executable( $pbcopy ) ) {
            return array(
                array( $pbcopy ),
            );
        }

        $pbcopy = $this->find_executable( 'pbcopy' );

        if ( null !== $pbcopy ) {
            return array(
                array( $pbcopy ),
            );
        }

        return array();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function build_windows_commands() {
        $commands    = array();
        $system_root = $this->get_environment_value( 'SystemRoot' );

        if ( $system_root ) {
            $clip = rtrim( $system_root, '/\\' ) . '\\System32\\clip.exe';

            if ( is_file( $clip ) ) {
                $commands[] = array( $clip );
            }
        }

        $clip = $this->find_executable( 'clip.exe' );

        if ( null !== $clip && ! $this->command_exists( $commands, $clip ) ) {
            $commands[] = array( $clip );
        }

        return $commands;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function build_wsl_commands() {
        $commands = array();
        $clip     = $this->find_executable( 'clip.exe' );

        if ( null !== $clip ) {
            $commands[] = array( $clip );
        }

        $mounted_clip = '/mnt/c/Windows/System32/clip.exe';

        if ( is_file( $mounted_clip ) && ! $this->command_exists( $commands, $mounted_clip ) ) {
            $commands[] = array( $mounted_clip );
        }

        return $commands;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function build_linux_commands() {
        $commands = array();

        if ( false !== $this->get_environment_value( 'WAYLAND_DISPLAY' ) ) {
            $wl_copy = $this->find_executable( 'wl-copy' );

            if ( null !== $wl_copy ) {
                $commands[] = array( $wl_copy );
            }
        }

        if ( false !== $this->get_environment_value( 'DISPLAY' ) ) {
            $xclip = $this->find_executable( 'xclip' );

            if ( null !== $xclip ) {
                $commands[] = array( $xclip, '-selection', 'clipboard' );
            }

            $xsel = $this->find_executable( 'xsel' );

            if ( null !== $xsel ) {
                $commands[] = array( $xsel, '--clipboard', '--input' );
            }
        }

        return $commands;
    }

    /**
     * @param array<int, string> $command
     * @param string             $text
     * @return bool
     */
    protected function run_command( $command, $text ) {
        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );

        $process = @proc_open( $command, $descriptors, $pipes, null, null, array( 'bypass_shell' => true ) );

        if ( ! is_resource( $process ) ) {
            return false;
        }

        $written = fwrite( $pipes[0], $text );
        fclose( $pipes[0] );

        stream_get_contents( $pipes[1] );
        stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        return strlen( $text ) === $written && 0 === proc_close( $process );
    }

    /**
     * @return string
     */
    protected function get_os_family() {
        return PHP_OS_FAMILY;
    }

    /**
     * @param string $name
     * @return string|false
     */
    protected function get_environment_value( $name ) {
        return getenv( $name );
    }

    /**
     * @return bool
     */
    protected function is_wsl() {
        if ( false !== $this->get_environment_value( 'WSL_DISTRO_NAME' ) ) {
            return true;
        }

        $release = @file_get_contents( '/proc/sys/kernel/osrelease' );

        return false !== $release && false !== stripos( $release, 'microsoft' );
    }

    /**
     * @param array<int, array<int, string>> $commands
     * @param string                        $executable
     * @return bool
     */
    private function command_exists( $commands, $executable ) {
        foreach ( $commands as $command ) {
            if ( isset( $command[0] ) && $command[0] === $executable ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @return string|null
     */
    private function find_executable( $name ) {
        $path = $this->get_environment_value( 'PATH' );

        if ( ! $path ) {
            return null;
        }

        foreach ( explode( PATH_SEPARATOR, $path ) as $directory ) {
            if ( '' === $directory ) {
                continue;
            }

            $candidate = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $name;

            if ( is_file( $candidate ) && is_executable( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }
}
