<?php

namespace WpLogin;

class Database {
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /** @var \mysqli */
    private $connection = null;

    /**
     * @param \mysqli $connection
     */
    public function __construct( $connection ) {
        $this->connection = $connection;
    }

    /**
     * @return self
     */
    public static function connect_from_config() {
        if ( ! extension_loaded( 'mysqli' ) ) {
            throw new \RuntimeException( 'The mysqli PHP extension is required to read WordPress users without loading plugins.' );
        }

        foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
            if ( ! defined( $constant ) ) {
                throw new \RuntimeException( sprintf( 'Missing %s in wp-config.php.', $constant ) );
            }
        }

        $connection = mysqli_init();

        if ( false === $connection ) {
            throw new \RuntimeException( 'Could not initialize the MySQL connection.' );
        }

        @mysqli_options( $connection, MYSQLI_OPT_CONNECT_TIMEOUT, self::CONNECT_TIMEOUT_SECONDS );

        $host   = self::parse_host( (string) DB_HOST );
        $flags  = defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0;
        $port   = null === $host['port'] ? 0 : $host['port'];
        $socket = null === $host['socket'] ? null : $host['socket'];

        $connected = @mysqli_real_connect(
            $connection,
            $host['host'],
            (string) DB_USER,
            (string) DB_PASSWORD,
            (string) DB_NAME,
            $port,
            $socket,
            $flags
        );

        if ( ! $connected ) {
            $message = mysqli_connect_error();
            mysqli_close( $connection );

            throw new \RuntimeException(
                '' !== $message
                    ? 'Could not connect to the WordPress database: ' . $message
                    : 'Could not connect to the WordPress database.'
            );
        }

        if ( defined( 'DB_CHARSET' ) && '' !== (string) DB_CHARSET ) {
            $charset = (string) DB_CHARSET;

            if ( preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ) {
                @mysqli_set_charset( $connection, $charset );
            }
        }

        return new self( $connection );
    }

    public function __destruct() {
        if ( $this->connection instanceof \mysqli ) {
            @mysqli_close( $this->connection );
        }
    }

    /**
     * @param string            $sql
     * @param string            $types
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetch_all( $sql, $types = '', $params = array() ) {
        $statement = $this->prepare_and_execute( $sql, $types, $params );
        $metadata  = $statement->result_metadata();

        if ( false === $metadata ) {
            $statement->close();
            return array();
        }

        $fields = $metadata->fetch_fields();
        $row    = array();
        $bind   = array();

        foreach ( $fields as $field ) {
            $row[ $field->name ] = null;
            $bind[]               = &$row[ $field->name ];
        }

        if ( ! empty( $bind ) ) {
            call_user_func_array( array( $statement, 'bind_result' ), $bind );
        }

        $rows = array();

        while ( $statement->fetch() ) {
            $copy = array();

            foreach ( $row as $key => $value ) {
                $copy[ $key ] = $value;
            }

            $rows[] = $copy;
        }

        $metadata->free();
        $statement->close();

        return $rows;
    }

    /**
     * @param string            $sql
     * @param string            $types
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch_one( $sql, $types = '', $params = array() ) {
        $rows = $this->fetch_all( $sql, $types, $params );

        return isset( $rows[0] ) ? $rows[0] : null;
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function identifier( $identifier ) {
        if ( '' === $identifier || ! preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
            throw new \RuntimeException( sprintf( 'Unsafe database identifier: %s', $identifier ) );
        }

        return '`' . $identifier . '`';
    }

    /**
     * Parse the DB_HOST formats commonly accepted by WordPress.
     *
     * @param string $value
     * @return array{host:string,port:int|null,socket:string|null}
     */
    public static function parse_host( $value ) {
        $value = trim( $value );

        if ( '' === $value ) {
            return array(
                'host'   => 'localhost',
                'port'   => null,
                'socket' => null,
            );
        }

        if ( '[' === substr( $value, 0, 1 ) ) {
            $end = strpos( $value, ']' );

            if ( false !== $end ) {
                $host      = substr( $value, 1, $end - 1 );
                $remainder = substr( $value, $end + 1 );

                if ( '' === $remainder ) {
                    return array( 'host' => $host, 'port' => null, 'socket' => null );
                }

                if ( 0 === strpos( $remainder, ':' ) ) {
                    $extra = substr( $remainder, 1 );

                    if ( ctype_digit( $extra ) ) {
                        return array( 'host' => $host, 'port' => (int) $extra, 'socket' => null );
                    }

                    if ( '' !== $extra ) {
                        return array( 'host' => $host, 'port' => null, 'socket' => $extra );
                    }
                }
            }
        }

        if ( 1 === substr_count( $value, ':' ) ) {
            list( $host, $extra ) = explode( ':', $value, 2 );

            if ( ctype_digit( $extra ) ) {
                return array( 'host' => $host, 'port' => (int) $extra, 'socket' => null );
            }

            if ( '' !== $extra && ( '/' === $extra[0] || '\\' === $extra[0] ) ) {
                return array( 'host' => $host, 'port' => null, 'socket' => $extra );
            }
        }

        if ( preg_match( '/^([^:]+):(\d+):(.+)$/', $value, $matches ) ) {
            return array(
                'host'   => $matches[1],
                'port'   => (int) $matches[2],
                'socket' => $matches[3],
            );
        }

        return array(
            'host'   => $value,
            'port'   => null,
            'socket' => null,
        );
    }

    /**
     * @param string            $sql
     * @param string            $types
     * @param array<int, mixed> $params
     * @return \mysqli_stmt
     */
    private function prepare_and_execute( $sql, $types, $params ) {
        $statement = $this->connection->prepare( $sql );

        if ( false === $statement ) {
            throw new \RuntimeException( 'Database query preparation failed: ' . $this->connection->error );
        }

        if ( '' !== $types ) {
            if ( strlen( $types ) !== count( $params ) ) {
                $statement->close();
                throw new \RuntimeException( 'Database query parameter mismatch.' );
            }

            $bind = array( $types );

            foreach ( $params as $index => $value ) {
                $params[ $index ] = $value;
                $bind[]            = &$params[ $index ];
            }

            if ( ! call_user_func_array( array( $statement, 'bind_param' ), $bind ) ) {
                $message = $statement->error;
                $statement->close();
                throw new \RuntimeException( 'Database query binding failed: ' . $message );
            }
        }

        if ( ! $statement->execute() ) {
            $message = $statement->error;
            $statement->close();
            throw new \RuntimeException( 'Database query failed: ' . $message );
        }

        return $statement;
    }
}
