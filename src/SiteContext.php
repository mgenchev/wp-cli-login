<?php

namespace WpLogin;

final class SiteContext {
    /** @var string */
    public $root;

    /** @var string */
    public $base_prefix;

    /** @var string */
    public $site_prefix;

    /** @var string */
    public $site_url;

    /**
     * @param string $root
     * @param string $base_prefix
     * @param string $site_prefix
     * @param string $site_url
     */
    private function __construct( $root, $base_prefix, $site_prefix, $site_url ) {
        $this->root        = $root;
        $this->base_prefix = $base_prefix;
        $this->site_prefix = $site_prefix;
        $this->site_url    = $site_url;
    }

    /**
     * @param Database    $database
     * @param string      $root
     * @param string      $base_prefix
     * @param string|null $requested_url
     * @return self
     */
    public static function resolve( Database $database, $root, $base_prefix, $requested_url = null ) {
        $root = realpath( $root );

        if ( false === $root || ! is_dir( $root ) ) {
            throw new \RuntimeException( 'Could not determine the WordPress root directory.' );
        }

        self::validate_prefix( $base_prefix );

        $site_prefix = $base_prefix;

        if ( defined( 'MULTISITE' ) && MULTISITE ) {
            $blog_id = self::resolve_blog_id( $database, $base_prefix, $requested_url );
            $main_id = defined( 'BLOG_ID_CURRENT_SITE' ) ? (int) BLOG_ID_CURRENT_SITE : 1;

            if ( $blog_id !== $main_id ) {
                $site_prefix = $base_prefix . $blog_id . '_';
            }
        }

        self::validate_prefix( $site_prefix );

        if ( defined( 'WP_SITEURL' ) && '' !== (string) WP_SITEURL ) {
            $site_url = rtrim( (string) WP_SITEURL, '/' );
        } else {
            $options_table = $database->identifier( $site_prefix . 'options' );
            $row           = $database->fetch_one(
                "SELECT option_value FROM {$options_table} WHERE option_name = ? LIMIT 1",
                's',
                array( 'siteurl' )
            );

            if ( null === $row || empty( $row['option_value'] ) ) {
                throw new \RuntimeException( 'Could not determine the WordPress site URL from the database.' );
            }

            $site_url = rtrim( (string) $row['option_value'], '/' );
        }
        $parts    = parse_url( $site_url );

        if (
            false === $parts
            || empty( $parts['scheme'] )
            || empty( $parts['host'] )
            || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
        ) {
            throw new \RuntimeException( 'The WordPress site URL stored in the database is not a valid HTTP(S) URL.' );
        }

        return new self( rtrim( $root, '/\\' ), $base_prefix, $site_prefix, $site_url );
    }

    /**
     * @param Database    $database
     * @param string      $base_prefix
     * @param string|null $requested_url
     * @return int
     */
    private static function resolve_blog_id( Database $database, $base_prefix, $requested_url ) {
        $main_id = defined( 'BLOG_ID_CURRENT_SITE' ) ? max( 1, (int) BLOG_ID_CURRENT_SITE ) : 1;

        if ( null === $requested_url || '' === $requested_url ) {
            return $main_id;
        }

        $parts = parse_url( $requested_url );

        if ( false === $parts || empty( $parts['host'] ) ) {
            throw new \RuntimeException( 'The --url value is not a valid URL.' );
        }

        $host = strtolower( (string) $parts['host'] );
        $path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';

        if ( '/' !== substr( $path, -1 ) ) {
            $path .= '/';
        }

        $blogs_table = $database->identifier( $base_prefix . 'blogs' );
        $rows        = $database->fetch_all(
            "SELECT blog_id, domain, path FROM {$blogs_table} WHERE LOWER(domain) = ? ORDER BY CHAR_LENGTH(path) DESC LIMIT 100",
            's',
            array( $host )
        );

        foreach ( $rows as $row ) {
            $blog_path = isset( $row['path'] ) && '' !== $row['path'] ? (string) $row['path'] : '/';

            if ( 0 === strpos( $path, $blog_path ) ) {
                return max( 1, (int) $row['blog_id'] );
            }
        }

        throw new \RuntimeException( sprintf( 'No multisite site matched --url=%s.', $requested_url ) );
    }

    /**
     * @param string $prefix
     * @return void
     */
    private static function validate_prefix( $prefix ) {
        if ( '' === $prefix || ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
            throw new \RuntimeException( 'The WordPress table prefix contains unsupported characters.' );
        }
    }
}
