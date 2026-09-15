<?php

namespace WpLogin;

final class UserRepository {
    private const SCAN_BATCH_SIZE = 100;

    /** @var Database */
    private $database;

    /** @var string */
    private $users_table;

    /** @var string */
    private $usermeta_table;

    /** @var string */
    private $capabilities_key;

    /**
     * @param Database $database
     * @param string   $base_prefix
     * @param string   $site_prefix
     */
    public function __construct( Database $database, $base_prefix, $site_prefix ) {
        $this->database         = $database;
        $this->users_table      = $database->identifier( $base_prefix . 'users' );
        $this->usermeta_table   = $database->identifier( $base_prefix . 'usermeta' );
        $this->capabilities_key = $site_prefix . 'capabilities';
    }

    /**
     * @param int $limit
     * @return array<int, object>
     */
    public function first_users( $limit ) {
        $limit = max( 1, min( 100, (int) $limit ) );
        $rows  = $this->database->fetch_all(
            "SELECT ID, user_login, user_email FROM {$this->users_table} ORDER BY ID ASC LIMIT {$limit}"
        );

        return $this->hydrate_users( $rows );
    }

    /**
     * @param string $value
     * @return object|null
     */
    public function find( $value ) {
        $row = null;

        if ( ctype_digit( $value ) ) {
            $row = $this->database->fetch_one(
                "SELECT ID, user_login, user_email FROM {$this->users_table} WHERE ID = ? LIMIT 1",
                'i',
                array( (int) $value )
            );
        }

        if ( null === $row ) {
            $row = $this->database->fetch_one(
                "SELECT ID, user_login, user_email FROM {$this->users_table} WHERE user_login = ? LIMIT 1",
                's',
                array( $value )
            );
        }

        if ( null === $row && false !== strpos( $value, '@' ) ) {
            $row = $this->database->fetch_one(
                "SELECT ID, user_login, user_email FROM {$this->users_table} WHERE user_email = ? LIMIT 1",
                's',
                array( $value )
            );
        }

        if ( null === $row ) {
            return null;
        }

        $users = $this->hydrate_users( array( $row ) );
        return isset( $users[0] ) ? $users[0] : null;
    }

    /**
     * @param string|null $required_role
     * @param string|null $excluded_role
     * @param int         $after_id
     * @param int         $limit
     * @return array{users:array<int,object>,next_cursor:int,has_more:bool}
     */
    public function role_page( $required_role, $excluded_role, $after_id, $limit ) {
        $limit       = max( 1, min( 100, (int) $limit ) );
        $cursor      = max( 0, (int) $after_id );
        $matches     = array();
        $exhausted   = false;
        $next_cursor = $cursor;

        while ( count( $matches ) < $limit + 1 && ! $exhausted ) {
            $rows = $this->database->fetch_all(
                "SELECT ID, user_login, user_email FROM {$this->users_table} WHERE ID > ? ORDER BY ID ASC LIMIT " . self::SCAN_BATCH_SIZE,
                'i',
                array( $cursor )
            );

            if ( empty( $rows ) ) {
                $exhausted = true;
                break;
            }

            $users = $this->hydrate_users( $rows );

            foreach ( $users as $user ) {
                $cursor      = (int) $user->ID;
                $next_cursor = $cursor;

                if ( $this->matches_roles( $user, $required_role, $excluded_role ) ) {
                    $matches[] = $user;

                    if ( count( $matches ) >= $limit + 1 ) {
                        break;
                    }
                }
            }

            if ( count( $rows ) < self::SCAN_BATCH_SIZE ) {
                $exhausted = true;
            }
        }

        $has_more = count( $matches ) > $limit;

        if ( $has_more ) {
            array_pop( $matches );
            $last        = end( $matches );
            $next_cursor = false === $last ? $after_id : (int) $last->ID;
        }

        return array(
            'users'       => array_values( $matches ),
            'next_cursor' => $next_cursor,
            'has_more'    => $has_more || ! $exhausted,
        );
    }

    /**
     * @param object      $user
     * @param string|null $required_role
     * @param string|null $excluded_role
     * @return bool
     */
    public function matches_roles( $user, $required_role, $excluded_role ) {
        $roles = isset( $user->roles ) ? (array) $user->roles : array();

        if ( null !== $required_role && ! in_array( $required_role, $roles, true ) ) {
            return false;
        }

        if ( null !== $excluded_role && in_array( $excluded_role, $roles, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, object>
     */
    private function hydrate_users( $rows ) {
        if ( empty( $rows ) ) {
            return array();
        }

        $users = array();
        $ids   = array();

        foreach ( $rows as $row ) {
            $id = (int) $row['ID'];

            $user             = new \stdClass();
            $user->ID         = $id;
            $user->user_login = (string) $row['user_login'];
            $user->user_email = (string) $row['user_email'];
            $user->roles      = array();

            $users[ $id ] = $user;
            $ids[]        = $id;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '?' ) );
        $types        = str_repeat( 'i', count( $ids ) );
        $meta_rows    = $this->database->fetch_all(
            "SELECT user_id, meta_value FROM {$this->usermeta_table} WHERE meta_key = ? AND user_id IN ({$placeholders})",
            's' . $types,
            array_merge( array( $this->capabilities_key ), $ids )
        );

        foreach ( $meta_rows as $meta_row ) {
            $id = (int) $meta_row['user_id'];

            if ( ! isset( $users[ $id ] ) ) {
                continue;
            }

            $users[ $id ]->roles = $this->parse_roles( (string) $meta_row['meta_value'] );
        }

        return array_values( $users );
    }

    /**
     * @param string $serialized
     * @return array<int, string>
     */
    private function parse_roles( $serialized ) {
        if ( '' === $serialized ) {
            return array();
        }

        $capabilities = @unserialize( $serialized, array( 'allowed_classes' => false ) );

        if ( ! is_array( $capabilities ) ) {
            return array();
        }

        $roles = array();

        foreach ( $capabilities as $role => $enabled ) {
            if ( true === $enabled && is_string( $role ) ) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
