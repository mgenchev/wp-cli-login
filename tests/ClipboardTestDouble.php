<?php

namespace WpLoginTests;

use WpLogin\Clipboard;

final class ClipboardTestDouble extends Clipboard {
    /** @var string */
    private $os_family;

    /** @var array<string, string|false> */
    private $environment;

    /** @var array<int, bool> */
    private $results;

    /** @var array<int, array<int, string>> */
    public $commands = array();

    /** @var array<int, string> */
    public $inputs = array();

    /**
     * @param string                      $os_family
     * @param array<string, string|false> $environment
     * @param array<int, bool>            $results
     */
    public function __construct( $os_family, $environment = array(), $results = array( true ) ) {
        $this->os_family   = $os_family;
        $this->environment = $environment;
        $this->results     = $results;
    }

    /**
     * @param array<int, string> $command
     * @param string             $text
     * @return bool
     */
    protected function run_command( $command, $text ) {
        $this->commands[] = $command;
        $this->inputs[]   = $text;

        if ( empty( $this->results ) ) {
            return false;
        }

        return (bool) array_shift( $this->results );
    }

    /**
     * @return string
     */
    protected function get_os_family() {
        return $this->os_family;
    }

    /**
     * @param string $name
     * @return string|false
     */
    protected function get_environment_value( $name ) {
        if ( array_key_exists( $name, $this->environment ) ) {
            return $this->environment[ $name ];
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function is_wsl() {
        return false !== $this->get_environment_value( 'WSL_DISTRO_NAME' );
    }
}
