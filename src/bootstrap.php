<?php

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command( 'login', array( new \WpLogin\LoginCommand(), '__invoke' ) );
