<?php

require dirname( __DIR__ ) . '/src/LoginLink.php';
require dirname( __DIR__ ) . '/src/Browser.php';
require dirname( __DIR__ ) . '/src/Spinner.php';
require __DIR__ . '/BrowserTestDouble.php';

use WpLogin\LoginLink;
use WpLoginTests\BrowserTestDouble;

$tests = array();


$tests['command starts before WordPress load and clears bootstrap progress silently'] = function () {
    $source = file_get_contents( dirname( __DIR__ ) . '/src/LoginCommand.php' );

    assert_true( false !== strpos( $source, '@when before_wp_load' ), 'Expected wp login to run before WordPress loads.' );
    assert_true( false !== strpos( $source, "spinner->start( 'Preparing WordPress...' )" ), 'Expected a progress indicator before WordPress bootstrap.' );
    assert_true( false !== strpos( $source, "WP_CLI::get_runner()->load_wordpress()" ), 'Expected WordPress to be loaded explicitly by the command.' );
    assert_true( false !== strpos( $source, 'spinner->stop()' ), 'Expected bootstrap progress to be cleared without a completion status.' );
    assert_true( false === strpos( $source, 'WordPress ready' ), 'Completed WordPress-ready status must not remain in console output.' );

    $start = strpos( $source, "spinner->start( 'Preparing WordPress...' )" );
    $load = strpos( $source, "WP_CLI::get_runner()->load_wordpress()" );
    $stop = strrpos( $source, 'spinner->stop()' );

    assert_true( $start < $load && $load < $stop, 'Expected progress to start before and stop after WordPress bootstrap.' );
};

$tests['spinner fallback does not leave a completed status line'] = function () {
    $root = make_temp_dir();

    try {
        $runner = $root . '/spinner.php';
        file_put_contents(
            $runner,
            "<?php\nrequire " . var_export( dirname( __DIR__ ) . '/src/Spinner.php', true ) . ";\n\$spinner = new \\WpLogin\\Spinner();\n\$spinner->start( 'Preparing WordPress...' );\nusleep( 10000 );\n\$spinner->stop();\n"
        );

        $result = run_php_process( $runner );

        assert_true( 0 === $result['exit_code'], 'Expected spinner fallback process to exit successfully.' );
        assert_true( false !== strpos( $result['output'], 'Preparing WordPress...' ), 'Expected immediate preparing status.' );
        assert_true( false === strpos( $result['output'], 'WordPress ready' ), 'Spinner must not emit a completed WordPress-ready line.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['creates one-time endpoint without storing the raw token'] = function () {
    $root = make_temp_dir();

    try {
        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );

        assert_true( is_file( $result['path'] ), 'Expected bridge file to exist.' );
        assert_true( 0 === strpos( basename( $result['path'] ), 'wp-cli-login-' ), 'Unexpected bridge filename.' );
        assert_true( false !== strpos( $result['url'], 'https://example.test/wp-cli-login-' ), 'Unexpected login URL.' );

        parse_str( parse_url( $result['url'], PHP_URL_QUERY ), $query );
        assert_true( isset( $query['token'] ) && strlen( $query['token'] ) === 64, 'Expected a 64-character token.' );

        $source = file_get_contents( $result['path'] );
        assert_true( false !== strpos( $source, 'hash_equals' ), 'Bridge must use constant-time token comparison.' );
        assert_true( false !== strpos( $source, 'wp_set_auth_cookie' ), 'Bridge must set a WordPress auth cookie.' );
        assert_true( false !== strpos( $source, 'wp_safe_redirect( admin_url() )' ), 'Bridge must redirect to wp-admin.' );
        assert_true( false === strpos( $source, $query['token'] ), 'Raw token must not be stored in the bridge file.' );
        assert_true( $result['expires_at'] >= time() + 58 && $result['expires_at'] <= time() + 60, 'Expected a one-minute login-link lifetime.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['rejects invalid user IDs'] = function () {
    $root = make_temp_dir();

    try {
        $link = new LoginLink();

        assert_throws(
            function () use ( $link, $root ) {
                $link->create( $root, 'https://example.test', 0 );
            },
            'invalid user ID'
        );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['rejects an invalid WordPress root'] = function () {
    $link = new LoginLink();

    assert_throws(
        function () use ( $link ) {
            $link->create( '/path/that/does/not/exist', 'https://example.test', 1 );
        },
        'Invalid WordPress root directory'
    );
};

$tests['cleans stale bridge files but preserves unrelated files'] = function () {
    $root = make_temp_dir();

    try {
        $stale = $root . '/wp-cli-login-stale.php';
        $fresh = $root . '/wp-cli-login-fresh.php';
        $other = $root . '/index.php';

        file_put_contents( $stale, '<?php' );
        file_put_contents( $fresh, '<?php' );
        file_put_contents( $other, '<?php' );

        touch( $stale, time() - 1200 );
        touch( $fresh, time() );
        touch( $other, time() - 1200 );

        $link = new LoginLink();
        $link->create( $root, 'https://example.test', 1 );

        assert_true( ! file_exists( $stale ), 'Expected stale bridge to be removed.' );
        assert_true( file_exists( $fresh ), 'Expected fresh bridge to be preserved.' );
        assert_true( file_exists( $other ), 'Expected unrelated file to be preserved.' );
    } finally {
        remove_temp_dir( $root );
    }
};



$tests['single-user sites skip the interactive user picker'] = function () {
    $users = json_encode(
        array(
            array(
                'ID'         => 1,
                'user_login' => 'admin',
                'user_email' => 'nobody@nobody.com',
                'roles'      => array( 'administrator' ),
            ),
        )
    );

    $result = run_php_process(
        __DIR__ . '/UserSelectorScenario.php',
        array( 'WP_LOGIN_TEST_USERS' => $users )
    );

    assert_true( 0 === $result['exit_code'], 'Expected single-user selection scenario to succeed.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:admin#1' ), 'Expected the only user to be selected automatically.' );
    assert_true( false === strpos( $result['output'], 'Login as:' ), 'Single-user sites must not show the login-mode picker.' );
    assert_true( false === strpos( $result['output'], 'Choose existing user' ), 'Single-user sites must not show the existing-user option.' );
};

$tests['multi-user sites keep the interactive user picker'] = function () {
    $users = json_encode(
        array(
            array(
                'ID'         => 1,
                'user_login' => 'admin',
                'user_email' => 'admin@example.test',
                'roles'      => array( 'administrator' ),
            ),
            array(
                'ID'         => 2,
                'user_login' => 'editor',
                'user_email' => 'editor@example.test',
                'roles'      => array( 'editor' ),
            ),
        )
    );

    $result = run_php_process(
        __DIR__ . '/UserSelectorScenario.php',
        array( 'WP_LOGIN_TEST_USERS' => $users ),
        "2\n1\n"
    );

    assert_true( 0 === $result['exit_code'], 'Expected multi-user selection scenario to succeed.' );
    assert_true( false !== strpos( $result['output'], 'Login as:' ), 'Multi-user sites must keep the login-mode picker.' );
    assert_true( false !== strpos( $result['output'], 'Choose existing user' ), 'Multi-user sites must keep the existing-user option.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:editor#2' ), 'Expected the selected existing user to be returned.' );
};

$tests['existing-user picker excludes administrators and rejects manual admin selection'] = function () {
    $users = json_encode(
        array(
            array(
                'ID'         => 1,
                'user_login' => 'admin',
                'user_email' => 'admin@example.test',
                'roles'      => array( 'administrator' ),
            ),
            array(
                'ID'         => 2,
                'user_login' => 'editor',
                'user_email' => 'editor@example.test',
                'roles'      => array( 'editor' ),
            ),
            array(
                'ID'         => 3,
                'user_login' => 'admin-two',
                'user_email' => 'admin-two@example.test',
                'roles'      => array( 'administrator' ),
            ),
        )
    );

    $result = run_php_process(
        __DIR__ . '/UserSelectorScenario.php',
        array( 'WP_LOGIN_TEST_USERS' => $users ),
        "2\nadmin\n1\n"
    );

    assert_true( 0 === $result['exit_code'], 'Expected existing-user selection scenario to succeed.' );
    assert_true( false === strpos( $result['output'], 'admin-two' ), 'Administrator accounts must not appear in the existing-user list.' );
    assert_true( false !== strpos( $result['output'], 'Warning: No user matched that selection.' ), 'Manual administrator login must be rejected in the existing-user flow.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:editor#2' ), 'Expected a non-administrator user to remain selectable.' );
};

$tests['command output stays compact and uses a one-minute lifetime message'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process(
            __DIR__ . '/LoginCommandScenario.php',
            array( 'WP_LOGIN_TEST_ROOT' => $root )
        );

        assert_true( 0 === $result['exit_code'], 'Expected command output scenario to succeed.' );
        assert_true( false !== strpos( $result['output'], '✓ User selected: editor (#2)' ), 'Expected selected-user status.' );
        assert_true( false !== strpos( $result['output'], 'Login link is valid for 1 minute and can be used once.' ), 'Expected one-minute lifetime message.' );
        assert_true( false === strpos( $result['output'], 'Success:' ), 'Command must not use WP-CLI Success prefix.' );
        assert_true( false === strpos( $result['output'], 'One-time login link created' ), 'Redundant link-created status must not be emitted.' );
        assert_true( false === strpos( $result['output'], 'Could not open the default browser automatically' ), 'Browser failure warning must not be emitted.' );
        assert_true( false === strpos( $result['output'], 'WordPress ready' ), 'WordPress-ready completion status must not be emitted.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['uses explorer.exe instead of rundll32 on Windows'] = function () {
    $bin = make_temp_dir();

    try {
        $explorer = $bin . DIRECTORY_SEPARATOR . 'explorer.exe';
        $cmd      = $bin . DIRECTORY_SEPARATOR . 'cmd.exe';

        file_put_contents( $explorer, '' );
        file_put_contents( $cmd, '' );
        chmod( $explorer, 0755 );
        chmod( $cmd, 0755 );

        $browser = new BrowserTestDouble(
            'Windows',
            array(
                'PATH'       => $bin,
                'SystemRoot' => false,
            ),
            array( true )
        );

        assert_true( $browser->open( 'https://example.test/login' ), 'Expected Windows browser launch to succeed.' );
        assert_true( 1 === count( $browser->commands ), 'Expected a single successful launcher attempt.' );
        assert_true( $explorer === $browser->commands[0][0], 'Expected explorer.exe to be the primary Windows launcher.' );
        assert_true( false === stripos( implode( ' ', $browser->commands[0] ), 'rundll32' ), 'Windows launcher must not use rundll32.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['falls back to cmd start when explorer launch fails on Windows'] = function () {
    $bin = make_temp_dir();

    try {
        $explorer = $bin . DIRECTORY_SEPARATOR . 'explorer.exe';
        $cmd      = $bin . DIRECTORY_SEPARATOR . 'cmd.exe';

        file_put_contents( $explorer, '' );
        file_put_contents( $cmd, '' );
        chmod( $explorer, 0755 );
        chmod( $cmd, 0755 );

        $browser = new BrowserTestDouble(
            'Windows',
            array(
                'PATH'       => $bin,
                'SystemRoot' => false,
            ),
            array( false, true )
        );

        assert_true( $browser->open( 'https://example.test/login?token=abc' ), 'Expected Windows fallback launcher to succeed.' );
        assert_true( 2 === count( $browser->commands ), 'Expected explorer.exe followed by cmd.exe fallback.' );
        assert_true( $explorer === $browser->commands[0][0], 'Expected explorer.exe first.' );
        assert_true( $cmd === $browser->commands[1][0], 'Expected cmd.exe fallback.' );
        assert_true( 'start "" "https://example.test/login?token=abc"' === $browser->commands[1][3], 'Expected a quoted cmd start URL.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['uses Windows browser integration when running under WSL'] = function () {
    $bin = make_temp_dir();

    try {
        $wslview = $bin . DIRECTORY_SEPARATOR . 'wslview';

        file_put_contents( $wslview, '' );
        chmod( $wslview, 0755 );

        $browser = new BrowserTestDouble(
            'Linux',
            array(
                'PATH'            => $bin,
                'WSL_DISTRO_NAME' => 'Ubuntu',
            ),
            array( true )
        );

        assert_true( $browser->open( 'https://example.test/login' ), 'Expected WSL browser launch to succeed.' );
        assert_true( $wslview === $browser->commands[0][0], 'Expected wslview to be preferred under WSL.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['does not report a browser launch when no graphical Linux session exists'] = function () {
    $browser = new BrowserTestDouble(
        'Linux',
        array(
            'PATH' => '/usr/bin:/bin',
        )
    );

    assert_true( ! $browser->open( 'https://example.test/login' ), 'Expected headless Linux browser opening to be skipped.' );
    assert_true( empty( $browser->commands ), 'Expected no launcher attempt without a graphical session.' );
};

$tests['executes a valid bridge once against a mock WordPress runtime'] = function () {
    $root = make_temp_dir();

    try {
        $marker = $root . '/marker.log';
        $loader = <<<'PHP'
<?php
function get_user_by( $field, $value ) {
    return (object) array( 'ID' => (int) $value );
}
function wp_clear_auth_cookie() {
    file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "clear\n", FILE_APPEND );
}
function wp_set_current_user( $user_id ) {
    file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "user:" . $user_id . "\n", FILE_APPEND );
}
function wp_set_auth_cookie( $user_id, $remember, $secure ) {
    file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "cookie:" . $user_id . ":" . ( $remember ? '1' : '0' ) . "\n", FILE_APPEND );
}
function is_ssl() {
    return false;
}
function admin_url() {
    return 'https://example.test/wp-admin/';
}
function wp_safe_redirect( $url ) {
    file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "redirect:" . $url . "\n", FILE_APPEND );
}
PHP;
        file_put_contents( $root . '/wp-load.php', $loader );

        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );
        parse_str( parse_url( $result['url'], PHP_URL_QUERY ), $query );

        $runner = $root . '/runner.php';
        file_put_contents(
            $runner,
            "<?php\n\$_GET['token'] = " . var_export( $query['token'], true ) . ";\nrequire " . var_export( $result['path'], true ) . ";\n"
        );

        $process_result = run_php_process( $runner, array( 'WP_LOGIN_TEST_MARKER' => $marker ) );
        assert_true( 0 === $process_result['exit_code'], 'Expected valid bridge process to exit successfully.' );
        assert_true( ! file_exists( $result['path'] ), 'Expected bridge file to delete itself after successful login.' );

        $events = file_get_contents( $marker );
        assert_true( false !== strpos( $events, "clear\n" ), 'Expected auth cookies to be cleared.' );
        assert_true( false !== strpos( $events, "user:7\n" ), 'Expected selected user to be set.' );
        assert_true( false !== strpos( $events, "cookie:7:0\n" ), 'Expected a non-persistent auth cookie.' );
        assert_true( false !== strpos( $events, "redirect:https://example.test/wp-admin/\n" ), 'Expected redirect to wp-admin.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['invalid bridge token does not load WordPress or consume the endpoint'] = function () {
    $root = make_temp_dir();

    try {
        $marker = $root . '/wp-loaded.log';
        file_put_contents(
            $root . '/wp-load.php',
            "<?php\nfile_put_contents( " . var_export( $marker, true ) . ", 'loaded' );\n"
        );

        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );
        $runner = $root . '/runner-invalid.php';

        file_put_contents(
            $runner,
            "<?php\n\$_GET['token'] = 'invalid';\nrequire " . var_export( $result['path'], true ) . ";\n"
        );

        $process_result = run_php_process( $runner );
        assert_true( 0 === $process_result['exit_code'], 'Bridge exits normally after rejecting an invalid token.' );
        assert_true( false !== strpos( $process_result['output'], 'Invalid login link.' ), 'Expected invalid-token response.' );
        assert_true( ! file_exists( $marker ), 'WordPress must not load for an invalid token.' );
        assert_true( file_exists( $result['path'] ), 'Invalid token must not consume the valid endpoint.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$passed = 0;
$failed = 0;

foreach ( $tests as $name => $test ) {
    try {
        $test();
        echo "PASS: {$name}\n";
        $passed++;
    } catch ( Throwable $throwable ) {
        echo "FAIL: {$name}\n";
        echo '      ' . $throwable->getMessage() . "\n";
        $failed++;
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );


function run_php_process( $script, $environment = array(), $input = '' ) {
    $command = array( PHP_BINARY, $script );
    $descriptors = array(
        0 => array( 'pipe', 'r' ),
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );
    $env = array_merge( $_ENV, $environment );
    $process = proc_open( $command, $descriptors, $pipes, null, $env, array( 'bypass_shell' => true ) );

    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Could not start PHP subprocess.' );
    }

    if ( '' !== $input ) {
        fwrite( $pipes[0], $input );
    }

    fclose( $pipes[0] );
    $stdout = stream_get_contents( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );

    return array(
        'exit_code' => proc_close( $process ),
        'output'    => $stdout . $stderr,
    );
}

function make_temp_dir() {
    $path = sys_get_temp_dir() . '/wp-login-test-' . bin2hex( random_bytes( 6 ) );

    if ( ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
        throw new RuntimeException( 'Could not create temporary test directory.' );
    }

    return $path;
}

function remove_temp_dir( $path ) {
    if ( ! is_dir( $path ) ) {
        return;
    }

    $items = scandir( $path );

    if ( false !== $items ) {
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }

            $item_path = $path . DIRECTORY_SEPARATOR . $item;

            if ( is_dir( $item_path ) && ! is_link( $item_path ) ) {
                remove_temp_dir( $item_path );
                continue;
            }

            @unlink( $item_path );
        }
    }

    @rmdir( $path );
}

function assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function assert_throws( $callback, $message_fragment ) {
    try {
        $callback();
    } catch ( RuntimeException $exception ) {
        if ( false === strpos( $exception->getMessage(), $message_fragment ) ) {
            throw new RuntimeException( 'Unexpected exception message: ' . $exception->getMessage() );
        }

        return;
    }

    throw new RuntimeException( 'Expected RuntimeException was not thrown.' );
}
