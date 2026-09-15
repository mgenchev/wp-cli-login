<?php

namespace WpLoginTests;

use WpLogin\Database;

final class FakeDatabase extends Database {
    /** @var array<int, array<string, mixed>> */
    private $users;

    /** @var array<int, array<string, mixed>> */
    private $meta;

    /** @var array<string, string> */
    private $options;

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $meta
     * @param array<string, string>            $options
     */
    public function __construct( $users = array(), $meta = array(), $options = array() ) {
        $this->users   = $users;
        $this->meta    = $meta;
        $this->options = $options;
    }

    public function identifier( $identifier ) {
        return '`' . $identifier . '`';
    }

    public function fetch_one( $sql, $types = '', $params = array() ) {
        $rows = $this->fetch_all( $sql, $types, $params );
        return isset( $rows[0] ) ? $rows[0] : null;
    }

    public function fetch_all( $sql, $types = '', $params = array() ) {
        unset( $types );

        if ( false !== strpos( $sql, 'FROM `wp_options`' ) ) {
            $name = isset( $params[0] ) ? (string) $params[0] : '';

            if ( isset( $this->options[ $name ] ) ) {
                return array( array( 'option_value' => $this->options[ $name ] ) );
            }

            return array();
        }

        if ( false !== strpos( $sql, 'FROM `wp_usermeta`' ) ) {
            $meta_key = isset( $params[0] ) ? (string) $params[0] : '';
            $ids      = array_map( 'intval', array_slice( $params, 1 ) );
            $rows     = array();

            foreach ( $this->meta as $row ) {
                if ( $meta_key === (string) $row['meta_key'] && in_array( (int) $row['user_id'], $ids, true ) ) {
                    $rows[] = array(
                        'user_id'    => (int) $row['user_id'],
                        'meta_value' => (string) $row['meta_value'],
                    );
                }
            }

            return $rows;
        }

        if ( false !== strpos( $sql, 'FROM `wp_users`' ) ) {
            $users = $this->users;

            if ( false !== strpos( $sql, 'WHERE ID = ?' ) ) {
                $id = (int) $params[0];
                $users = array_values( array_filter( $users, function ( $row ) use ( $id ) {
                    return (int) $row['ID'] === $id;
                } ) );
            } elseif ( false !== strpos( $sql, 'WHERE user_login = ?' ) ) {
                $login = (string) $params[0];
                $users = array_values( array_filter( $users, function ( $row ) use ( $login ) {
                    return (string) $row['user_login'] === $login;
                } ) );
            } elseif ( false !== strpos( $sql, 'WHERE user_email = ?' ) ) {
                $email = (string) $params[0];
                $users = array_values( array_filter( $users, function ( $row ) use ( $email ) {
                    return (string) $row['user_email'] === $email;
                } ) );
            } elseif ( false !== strpos( $sql, 'WHERE ID > ?' ) ) {
                $after = (int) $params[0];
                $users = array_values( array_filter( $users, function ( $row ) use ( $after ) {
                    return (int) $row['ID'] > $after;
                } ) );
            }

            usort( $users, function ( $a, $b ) {
                return (int) $a['ID'] <=> (int) $b['ID'];
            } );

            if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $matches ) ) {
                $users = array_slice( $users, 0, (int) $matches[1] );
            }

            return array_map( function ( $row ) {
                return array(
                    'ID'         => (int) $row['ID'],
                    'user_login' => (string) $row['user_login'],
                    'user_email' => (string) $row['user_email'],
                );
            }, $users );
        }

        return array();
    }
}
