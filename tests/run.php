<?php

require dirname( __DIR__ ) . '/src/LoginLink.php';
require dirname( __DIR__ ) . '/src/Browser.php';
require dirname( __DIR__ ) . '/src/Clipboard.php';
require dirname( __DIR__ ) . '/src/Database.php';
require dirname( __DIR__ ) . '/src/SiteContext.php';
require dirname( __DIR__ ) . '/src/UserRepository.php';
require __DIR__ . '/FakeDatabase.php';
require __DIR__ . '/BrowserTestDouble.php';
require __DIR__ . '/ClipboardTestDouble.php';

use WpLogin\Database;
use WpLogin\LoginLink;
use WpLogin\SiteContext;
use WpLogin\UserRepository;
use WpLoginTests\BrowserTestDouble;
use WpLoginTests\ClipboardTestDouble;
use WpLoginTests\FakeDatabase;

$tests = array();

$tests['command runs after wp-config but before WordPress runtime'] = function () {
    $source = file_get_contents( dirname( __DIR__ ) . '/src/LoginCommand.php' );

    assert_true( false !== strpos( $source, '@when after_wp_config_load' ), 'Expected wp login to run after wp-config.php is loaded.' );
    assert_true( false === strpos( $source, 'load_wordpress()' ), 'The command must not load wp-settings.php.' );
    assert_true( false === strpos( $source, 'Preparing WordPress' ), 'The obsolete WordPress bootstrap spinner must be removed.' );
    assert_true( false !== strpos( $source, "get_global_config( 'user' )" ), 'Expected the WP-CLI global --user value to be consumed explicitly.' );
};

$tests['global --user resolves directly without opening the interactive picker'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process(
            __DIR__ . '/GlobalUserScenario.php',
            array( 'WP_LOGIN_TEST_ROOT' => $root )
        );

        assert_true( 0 === $result['exit_code'], 'Expected the global --user scenario to succeed.' );
        assert_true( false !== strpos( $result['output'], '✓ User selected: Lindstrom (#4272)' ), 'Expected the global WP-CLI user value to select Lindstrom.' );
        assert_true( false === strpos( $result['output'], 'Login as:' ), 'The interactive user picker must not appear when --user is provided.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['database host parser supports host port socket and IPv6'] = function () {
    assert_true(
        array( 'host' => 'localhost', 'port' => 3307, 'socket' => null ) === Database::parse_host( 'localhost:3307' ),
        'Expected host:port parsing.'
    );
    assert_true(
        array( 'host' => 'localhost', 'port' => null, 'socket' => '/tmp/mysql.sock' ) === Database::parse_host( 'localhost:/tmp/mysql.sock' ),
        'Expected Unix socket parsing.'
    );
    assert_true(
        array( 'host' => 'db.example.test', 'port' => 3307, 'socket' => '/tmp/mysql.sock' ) === Database::parse_host( 'db.example.test:3307:/tmp/mysql.sock' ),
        'Expected host:port:socket parsing.'
    );
    assert_true(
        array( 'host' => '::1', 'port' => 3306, 'socket' => null ) === Database::parse_host( '[::1]:3306' ),
        'Expected bracketed IPv6 parsing.'
    );
};

$tests['database identifiers reject unsafe table names'] = function () {
    $reflection = new ReflectionClass( Database::class );
    $database   = $reflection->newInstanceWithoutConstructor();

    assert_throws(
        function () use ( $database ) {
            $database->identifier( 'wp_users;DROP' );
        },
        'Unsafe database identifier'
    );
};

$tests['site context reads and validates the site URL without WordPress APIs'] = function () {
    $root = make_temp_dir();

    try {
        $database = new FakeDatabase( array(), array(), array( 'siteurl' => 'https://example.test/wp' ) );
        $context  = SiteContext::resolve( $database, $root, 'wp_' );

        assert_true( 'https://example.test/wp' === $context->site_url, 'Expected siteurl from the options table.' );
        assert_true( 'wp_' === $context->site_prefix, 'Expected the single-site table prefix.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['site context rejects a non-http site URL'] = function () {
    $root = make_temp_dir();

    try {
        $database = new FakeDatabase( array(), array(), array( 'siteurl' => 'javascript:alert(1)' ) );

        assert_throws(
            function () use ( $database, $root ) {
                SiteContext::resolve( $database, $root, 'wp_' );
            },
            'not a valid HTTP(S) URL'
        );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['user repository finds a login and parses roles safely'] = function () {
    $database = make_user_database();
    $users    = new UserRepository( $database, 'wp_', 'wp_' );
    $user     = $users->find( 'Lindstrom' );

    assert_true( null !== $user, 'Expected the user to be found.' );
    assert_true( 3 === $user->ID, 'Unexpected user ID.' );
    assert_true( array( 'subscriber' ) === $user->roles, 'Expected roles from the site capabilities metadata.' );
};

$tests['user repository does not instantiate serialized objects from capabilities'] = function () {
    $database = new FakeDatabase(
        array(
            array( 'ID' => 9, 'user_login' => 'unsafe', 'user_email' => 'unsafe@example.test' ),
        ),
        array(
            array( 'user_id' => 9, 'meta_key' => 'wp_capabilities', 'meta_value' => 'O:8:"stdClass":0:{}' ),
        )
    );
    $users = new UserRepository( $database, 'wp_', 'wp_' );
    $user  = $users->find( 'unsafe' );

    assert_true( null !== $user, 'Expected the user row to remain readable.' );
    assert_true( array() === $user->roles, 'Serialized objects must not be accepted as role data.' );
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

    $result = run_php_process( __DIR__ . '/UserSelectorScenario.php', array( 'WP_LOGIN_TEST_USERS' => $users ) );

    assert_true( 0 === $result['exit_code'], 'Expected single-user selection scenario to succeed.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:admin#1' ), 'Expected the only user to be selected automatically.' );
    assert_true( false === strpos( $result['output'], 'Login as:' ), 'Single-user sites must not show the login-mode picker.' );
};

$tests['existing-user picker excludes administrators and rejects manual admin selection'] = function () {
    $users = json_encode(
        array(
            array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.test', 'roles' => array( 'administrator' ) ),
            array( 'ID' => 2, 'user_login' => 'editor', 'user_email' => 'editor@example.test', 'roles' => array( 'editor' ) ),
            array( 'ID' => 3, 'user_login' => 'admin-two', 'user_email' => 'admin-two@example.test', 'roles' => array( 'administrator' ) ),
        )
    );

    $result = run_php_process(
        __DIR__ . '/UserSelectorScenario.php',
        array( 'WP_LOGIN_TEST_USERS' => $users ),
        "2\nadmin\n1\n"
    );

    assert_true( 0 === $result['exit_code'], 'Expected existing-user selection scenario to succeed.' );
    assert_true( false === strpos( $result['output'], 'admin-two' ), 'Administrators must not appear in the existing-user list.' );
    assert_true( false !== strpos( $result['output'], 'Warning: No user matched that selection.' ), 'Manual administrator selection must be rejected.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:editor#2' ), 'Expected a non-administrator user to remain selectable.' );
};

$tests['administrator picker still selects an administrator'] = function () {
    $users = json_encode(
        array(
            array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.test', 'roles' => array( 'administrator' ) ),
            array( 'ID' => 2, 'user_login' => 'editor', 'user_email' => 'editor@example.test', 'roles' => array( 'editor' ) ),
        )
    );

    $result = run_php_process(
        __DIR__ . '/UserSelectorScenario.php',
        array( 'WP_LOGIN_TEST_USERS' => $users ),
        "1\n"
    );

    assert_true( 0 === $result['exit_code'], 'Expected administrator selection to succeed.' );
    assert_true( false !== strpos( $result['output'], 'SELECTED:admin#1' ), 'Expected the administrator option to remain functional.' );
};

$tests['interactive action picker offers browser or clipboard before creating the link'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process(
            __DIR__ . '/LoginCommandScenario.php',
            array(
                'WP_LOGIN_TEST_ROOT'   => $root,
                'WP_LOGIN_TEST_ACTION' => 'interactive',
            ),
            "2\n"
        );

        assert_true( 0 === $result['exit_code'], 'Expected interactive action selection to succeed.' );
        assert_true( false !== strpos( $result['output'], 'Login action:' ), 'Expected the login-action picker.' );
        assert_true( false !== strpos( $result['output'], '✓ Login URL copied to clipboard' ), 'Expected the selected copy action.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['no-open uses WP-CLI normalized false flag and skips the action prompt'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process(
            __DIR__ . '/LoginCommandScenario.php',
            array(
                'WP_LOGIN_TEST_ROOT'   => $root,
                'WP_LOGIN_TEST_ACTION' => 'no-open',
            )
        );

        assert_true( 0 === $result['exit_code'], 'Expected --no-open scenario to succeed.' );
        assert_true( false === strpos( $result['output'], 'Login action:' ), '--no-open must skip the interactive action picker.' );
        assert_true( false !== strpos( $result['output'], 'Login URL: https://example.test/' ), '--no-open must print the login URL.' );
        assert_true( false === strpos( $result['output'], 'Browser launch requested' ), '--no-open must not open the browser.' );
        assert_true( false === strpos( $result['output'], 'copied to clipboard' ), '--no-open must not copy the URL.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['open and copy flags are mutually exclusive'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process(
            __DIR__ . '/LoginCommandScenario.php',
            array(
                'WP_LOGIN_TEST_ROOT'   => $root,
                'WP_LOGIN_TEST_ACTION' => 'conflict',
            )
        );

        assert_true( 0 !== $result['exit_code'], 'Expected conflicting action flags to fail.' );
        assert_true( false !== strpos( $result['output'], 'Choose only one login action' ), 'Expected a clear conflicting-action error.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['command output remains compact with one-minute lifetime'] = function () {
    $root = make_temp_dir();

    try {
        $result = run_php_process( __DIR__ . '/LoginCommandScenario.php', array( 'WP_LOGIN_TEST_ROOT' => $root ) );

        assert_true( 0 === $result['exit_code'], 'Expected command output scenario to succeed.' );
        assert_true( false !== strpos( $result['output'], '✓ User selected: editor (#2)' ), 'Expected selected-user status.' );
        assert_true( false !== strpos( $result['output'], 'Login link is valid for 1 minute and can be used once.' ), 'Expected one-minute lifetime message.' );
        assert_true( false === strpos( $result['output'], 'Preparing WordPress' ), 'No WordPress bootstrap spinner should remain.' );
        assert_true( false === strpos( $result['output'], 'Success:' ), 'Command must not use WP-CLI Success prefix.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['creates one-time endpoint without storing the raw token'] = function () {
    $root = make_temp_dir();

    try {
        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );

        parse_str( parse_url( $result['url'], PHP_URL_QUERY ), $query );
        $source    = file_get_contents( $result['path'] );
        $lock_path = $result['path'] . '.lock';

        assert_true( is_file( $result['path'] ), 'Expected bridge file to exist.' );
        assert_true( is_file( $lock_path ), 'Expected a separate bridge lock file to exist.' );
        assert_true( isset( $query['token'] ) && 64 === strlen( $query['token'] ), 'Expected a 64-character token.' );
        assert_true( false !== strpos( $source, 'hash_equals' ), 'Bridge must use constant-time token comparison.' );
        assert_true( false !== strpos( $source, "__FILE__ . '.lock'" ), 'Bridge must lock a separate file instead of the executable PHP endpoint.' );
        assert_true( false === strpos( $source, "fopen( __FILE__, 'r' )" ), 'Bridge must not lock the executable PHP endpoint directly.' );
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
        assert_throws( function () use ( $link, $root ) { $link->create( $root, 'https://example.test', 0 ); }, 'invalid user ID' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['rejects an invalid WordPress root'] = function () {
    $link = new LoginLink();
    assert_throws( function () use ( $link ) { $link->create( '/path/that/does/not/exist', 'https://example.test', 1 ); }, 'Invalid WordPress root directory' );
};

$tests['cleans stale bridge files but preserves unrelated files'] = function () {
    $root = make_temp_dir();

    try {
        $stale      = $root . '/wp-cli-login-stale.php';
        $stale_lock = $stale . '.lock';
        $fresh      = $root . '/wp-cli-login-fresh.php';
        $other      = $root . '/index.php';
        file_put_contents( $stale, '<?php' );
        file_put_contents( $stale_lock, '1' );
        file_put_contents( $fresh, '<?php' );
        file_put_contents( $other, '<?php' );
        touch( $stale, time() - 1200 );
        touch( $stale_lock, time() - 1200 );
        touch( $fresh, time() );
        touch( $other, time() - 1200 );

        $link = new LoginLink();
        $link->create( $root, 'https://example.test', 1 );

        assert_true( ! file_exists( $stale ), 'Expected stale bridge to be removed.' );
        assert_true( ! file_exists( $stale_lock ), 'Expected stale bridge lock to be removed.' );
        assert_true( file_exists( $fresh ), 'Expected fresh bridge to be preserved.' );
        assert_true( file_exists( $other ), 'Expected unrelated file to be preserved.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['uses pbcopy on macOS'] = function () {
    $bin = make_temp_dir();

    try {
        $pbcopy = $bin . DIRECTORY_SEPARATOR . 'pbcopy';
        file_put_contents( $pbcopy, '' );
        chmod( $pbcopy, 0755 );
        $clipboard = new ClipboardTestDouble( 'Darwin', array( 'PATH' => $bin ), array( true ) );
        assert_true( $clipboard->copy( 'https://example.test/login' ), 'Expected macOS clipboard copy to succeed.' );
        assert_true( $pbcopy === $clipboard->commands[0][0], 'Expected pbcopy on macOS.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['uses clip.exe for clipboard copying under WSL'] = function () {
    $bin = make_temp_dir();

    try {
        $clip = $bin . DIRECTORY_SEPARATOR . 'clip.exe';
        file_put_contents( $clip, '' );
        chmod( $clip, 0755 );
        $clipboard = new ClipboardTestDouble( 'Linux', array( 'PATH' => $bin, 'WSL_DISTRO_NAME' => 'Ubuntu' ), array( true ) );
        assert_true( $clipboard->copy( 'https://example.test/login' ), 'Expected WSL clipboard copy to succeed.' );
        assert_true( $clip === $clipboard->commands[0][0], 'Expected clip.exe under WSL.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['uses wl-copy for Wayland clipboard sessions'] = function () {
    $bin = make_temp_dir();

    try {
        $wl_copy = $bin . DIRECTORY_SEPARATOR . 'wl-copy';
        file_put_contents( $wl_copy, '' );
        chmod( $wl_copy, 0755 );
        $clipboard = new ClipboardTestDouble( 'Linux', array( 'PATH' => $bin, 'WAYLAND_DISPLAY' => 'wayland-0' ), array( true ) );
        assert_true( $clipboard->copy( 'https://example.test/login' ), 'Expected Wayland clipboard copy to succeed.' );
        assert_true( $wl_copy === $clipboard->commands[0][0], 'Expected wl-copy for Wayland.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['clipboard failure falls back safely'] = function () {
    $clipboard = new ClipboardTestDouble( 'Linux', array( 'PATH' => '/path/that/does/not/exist' ) );
    assert_true( ! $clipboard->copy( 'https://example.test/login' ), 'Expected unavailable clipboard integration to fail safely.' );
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
        $browser = new BrowserTestDouble( 'Windows', array( 'PATH' => $bin, 'SystemRoot' => false ), array( true ) );
        assert_true( $browser->open( 'https://example.test/login' ), 'Expected Windows browser launch to succeed.' );
        assert_true( $explorer === $browser->commands[0][0], 'Expected explorer.exe as primary Windows launcher.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['falls back to cmd start when explorer fails on Windows'] = function () {
    $bin = make_temp_dir();

    try {
        $explorer = $bin . DIRECTORY_SEPARATOR . 'explorer.exe';
        $cmd      = $bin . DIRECTORY_SEPARATOR . 'cmd.exe';
        file_put_contents( $explorer, '' );
        file_put_contents( $cmd, '' );
        chmod( $explorer, 0755 );
        chmod( $cmd, 0755 );
        $browser = new BrowserTestDouble( 'Windows', array( 'PATH' => $bin, 'SystemRoot' => false ), array( false, true ) );
        assert_true( $browser->open( 'https://example.test/login?token=abc' ), 'Expected Windows fallback launcher to succeed.' );
        assert_true( $cmd === $browser->commands[1][0], 'Expected cmd.exe fallback.' );
    } finally {
        remove_temp_dir( $bin );
    }
};

$tests['does not launch a browser in headless Linux'] = function () {
    $browser = new BrowserTestDouble( 'Linux', array( 'PATH' => '/usr/bin:/bin' ) );
    assert_true( ! $browser->open( 'https://example.test/login' ), 'Expected headless Linux browser opening to be skipped.' );
};

$tests['executes a valid bridge once against a mock WordPress runtime'] = function () {
    $root = make_temp_dir();

    try {
        $marker = $root . '/marker.log';
        $loader = <<<'MOCK'
<?php
function get_user_by( $field, $value ) { return (object) array( 'ID' => (int) $value ); }
function wp_clear_auth_cookie() { file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "clear\n", FILE_APPEND ); }
function wp_set_current_user( $user_id ) { file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "user:" . $user_id . "\n", FILE_APPEND ); }
function wp_set_auth_cookie( $user_id, $remember, $secure ) { file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "cookie:" . $user_id . "\n", FILE_APPEND ); }
function is_ssl() { return false; }
function admin_url() { return 'https://example.test/wp-admin/'; }
function wp_safe_redirect( $url ) { file_put_contents( getenv( 'WP_LOGIN_TEST_MARKER' ), "redirect:" . $url . "\n", FILE_APPEND ); }
MOCK;
        file_put_contents( $root . '/wp-load.php', $loader );
        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );
        parse_str( parse_url( $result['url'], PHP_URL_QUERY ), $query );
        $runner = $root . '/runner.php';
        file_put_contents( $runner, "<?php\n\$_GET['token'] = " . var_export( $query['token'], true ) . ";\nrequire " . var_export( $result['path'], true ) . ";\n" );
        $process = run_php_process( $runner, array( 'WP_LOGIN_TEST_MARKER' => $marker ) );

        assert_true( 0 === $process['exit_code'], 'Expected valid bridge process to exit successfully.' );
        assert_true( ! file_exists( $result['path'] ), 'Expected bridge file to delete itself after login.' );
        $events = file_get_contents( $marker );
        assert_true( false !== strpos( $events, "user:7\n" ), 'Expected the selected user to be set.' );
        assert_true( false !== strpos( $events, "redirect:https://example.test/wp-admin/\n" ), 'Expected redirect to wp-admin.' );
    } finally {
        remove_temp_dir( $root );
    }
};

$tests['invalid bridge token does not load WordPress or consume endpoint'] = function () {
    $root = make_temp_dir();

    try {
        $marker = $root . '/wp-loaded.log';
        file_put_contents( $root . '/wp-load.php', "<?php\nfile_put_contents( " . var_export( $marker, true ) . ", 'loaded' );\n" );
        $link   = new LoginLink();
        $result = $link->create( $root, 'https://example.test', 7 );
        $runner = $root . '/runner-invalid.php';
        file_put_contents( $runner, "<?php\n\$_GET['token'] = 'invalid';\nrequire " . var_export( $result['path'], true ) . ";\n" );
        $process = run_php_process( $runner );

        assert_true( false !== strpos( $process['output'], 'Invalid login link.' ), 'Expected invalid-token response.' );
        assert_true( ! file_exists( $marker ), 'WordPress must not load for an invalid token.' );
        assert_true( file_exists( $result['path'] ), 'Invalid token must not consume the endpoint.' );
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

function make_user_database() {
    return new FakeDatabase(
        array(
            array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.test' ),
            array( 'ID' => 2, 'user_login' => 'editor', 'user_email' => 'editor@example.test' ),
            array( 'ID' => 3, 'user_login' => 'Lindstrom', 'user_email' => 'lindstrom@example.test' ),
        ),
        array(
            array( 'user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'administrator' => true ) ) ),
            array( 'user_id' => 2, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'editor' => true ) ) ),
            array( 'user_id' => 3, 'meta_key' => 'wp_capabilities', 'meta_value' => serialize( array( 'subscriber' => true ) ) ),
        ),
        array( 'siteurl' => 'https://example.test' )
    );
}

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
