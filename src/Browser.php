<?php

namespace WpLogin;

class Browser {
    /**
     * @param string $url
     * @return bool
     */
    public function open( $url ) {
        $commands = $this->build_commands( $url );

        foreach ( $commands as $command ) {
            if ( $this->run_command( $command ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $url
     * @return array<int, array<int, string>>
     */
    private function build_commands( $url ) {
        $os_family = $this->get_os_family();

        if ( 'Darwin' === $os_family ) {
            $open = '/usr/bin/open';

            if ( is_executable( $open ) ) {
                return array(
                    array( $open, $url ),
                );
            }

            return array();
        }

        if ( 'Windows' === $os_family ) {
            return $this->build_windows_commands( $url );
        }

        if ( 'Linux' === $os_family ) {
            if ( $this->is_wsl() ) {
                return $this->build_wsl_commands( $url );
            }

            if ( ! $this->has_graphical_session() ) {
                return array();
            }

            return $this->build_linux_commands( $url );
        }

        return array();
    }

    /**
     * @param string $url
     * @return array<int, array<int, string>>
     */
    private function build_windows_commands( $url ) {
        $commands    = array();
        $system_root = $this->get_environment_value( 'SystemRoot' );

        if ( $system_root ) {
            $base     = rtrim( $system_root, '/\\' );
            $explorer = $base . '\\explorer.exe';
            $cmd      = $base . '\\System32\\cmd.exe';

            if ( is_file( $explorer ) ) {
                $commands[] = array( $explorer, $url );
            }

            if ( is_file( $cmd ) ) {
                $commands[] = array( $cmd, '/D', '/C', $this->build_cmd_start_argument( $url ) );
            }
        }

        $explorer = $this->find_executable( 'explorer.exe' );

        if ( null !== $explorer && ! $this->command_exists( $commands, $explorer ) ) {
            $commands[] = array( $explorer, $url );
        }

        $cmd = $this->find_executable( 'cmd.exe' );

        if ( null !== $cmd && ! $this->command_exists( $commands, $cmd ) ) {
            $commands[] = array( $cmd, '/D', '/C', $this->build_cmd_start_argument( $url ) );
        }

        return $commands;
    }

    /**
     * @param string $url
     * @return array<int, array<int, string>>
     */
    private function build_wsl_commands( $url ) {
        $commands = array();
        $wslview  = $this->find_executable( 'wslview' );

        if ( null !== $wslview ) {
            $commands[] = array( $wslview, $url );
        }

        $explorer = $this->find_executable( 'explorer.exe' );

        if ( null !== $explorer ) {
            $commands[] = array( $explorer, $url );
        }

        $mounted_explorer = '/mnt/c/Windows/explorer.exe';

        if ( is_file( $mounted_explorer ) && is_executable( $mounted_explorer ) && ! $this->command_exists( $commands, $mounted_explorer ) ) {
            $commands[] = array( $mounted_explorer, $url );
        }

        $cmd = $this->find_executable( 'cmd.exe' );

        if ( null !== $cmd ) {
            $commands[] = array( $cmd, '/D', '/C', $this->build_cmd_start_argument( $url ) );
        }

        return $commands;
    }

    /**
     * @param string $url
     * @return array<int, array<int, string>>
     */
    private function build_linux_commands( $url ) {
        $commands = array();
        $xdg_open = $this->find_executable( 'xdg-open' );

        if ( null !== $xdg_open ) {
            $commands[] = array( $xdg_open, $url );
        }

        $gio = $this->find_executable( 'gio' );

        if ( null !== $gio ) {
            $commands[] = array( $gio, 'open', $url );
        }

        return $commands;
    }

    /**
     * @param array<int, string> $command
     * @return bool
     */
    protected function run_command( $command ) {
        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );

        $process = @proc_open( $command, $descriptors, $pipes, null, null, array( 'bypass_shell' => true ) );

        if ( ! is_resource( $process ) ) {
            return false;
        }

        fclose( $pipes[0] );
        stream_get_contents( $pipes[1] );
        stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        return 0 === proc_close( $process );
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
     * @return bool
     */
    private function has_graphical_session() {
        return false !== $this->get_environment_value( 'DISPLAY' )
            || false !== $this->get_environment_value( 'WAYLAND_DISPLAY' )
            || false !== $this->get_environment_value( 'DBUS_SESSION_BUS_ADDRESS' );
    }

    /**
     * @param string $url
     * @return string
     */
    private function build_cmd_start_argument( $url ) {
        $escaped_url = str_replace( '"', '""', $url );

        return 'start "" "' . $escaped_url . '"';
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
