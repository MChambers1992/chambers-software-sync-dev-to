<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Dev.to REST API.
 *
 * Endpoints used:
 *   POST /api/articles          – create
 *   PUT  /api/articles/{id}     – update
 *   GET  /api/articles/{id}     – fetch (used for validation)
 */
class Cross_Post_DevTo_API {

    const BASE_URL = 'https://dev.to/api/';

    /** Dev.to API key used to authenticate every request. @var string */
    private $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = $api_key;
    }

    // -------------------------------------------------------------------------
    // Public methods
    // -------------------------------------------------------------------------

    /**
     * Create a new article on Dev.to.
     *
     * @param array $payload  Dev.to article payload (pre-built by Publisher).
     * @return array|WP_Error  Response body array on success, WP_Error on failure.
     */
    public function create_article( array $payload ) {
        return $this->request( 'POST', 'articles', [ 'article' => $payload ] );
    }

    /**
     * Update an existing article on Dev.to.
     *
     * @param int   $devto_id  Dev.to article ID stored in post meta.
     * @param array $payload   Dev.to article payload.
     * @return array|WP_Error
     */
    public function update_article( int $devto_id, array $payload ) {
        return $this->request( 'PUT', "articles/{$devto_id}", [ 'article' => $payload ] );
    }

    /**
     * Fetch a single article – useful for confirming the article exists before updating.
     *
     * @param int $devto_id
     * @return array|WP_Error
     */
    public function get_article( int $devto_id ) {
        return $this->request( 'GET', "articles/{$devto_id}" );
    }

    /**
     * Validate that the stored API key belongs to a real account.
     *
     * @return array|WP_Error  User object or WP_Error.
     */
    public function validate_key() {
        return $this->request( 'GET', 'users/me' );
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helper
    // -------------------------------------------------------------------------

    /**
     * Send an authenticated request to the Dev.to API.
     *
     * @param string     $method   HTTP verb.
     * @param string     $endpoint Path relative to BASE_URL.
     * @param array|null $body     JSON-serialisable body (omit for GET).
     * @return array|WP_Error
     */
    private function request( string $method, string $endpoint, ?array $body = null ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Dev.to API key is not configured.', 'cross-post-devto' ) );
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'api-key'      => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ( ! is_null( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $url      = self::BASE_URL . ltrim( $endpoint, '/' );
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        // Dev.to returns 2xx for success.
        if ( $code < 200 || $code >= 300 ) {
            if ( $code === 429 ) {
                return $this->rate_limit_error( $response, $raw );
            }
            $message = isset( $data['error'] ) ? $data['error'] : sprintf( 'HTTP %d', $code );
            return new WP_Error( 'devto_api_error', $message, [ 'status' => $code, 'body' => $raw ] );
        }

        return $data;
    }

    /**
     * Build a distinct WP_Error for HTTP 429 responses.
     *
     * Dev.to's rate limit resets are communicated via a `Retry-After` header
     * (seconds). Surfaced separately from the generic error code so callers
     * (and the sync log) can tell "rate limited, try later" apart from a
     * genuine request problem — no automatic retry is attempted here, since
     * this runs synchronously inside a WordPress publish/save request and
     * blocking that request to wait out a rate limit would be worse than
     * just logging it and letting the next edit/manual sync try again.
     *
     * @param array|WP_Error $response  The raw wp_remote_request() response.
     * @param string         $raw       Raw response body.
     */
    private function rate_limit_error( $response, string $raw ): WP_Error {
        $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

        $message = $retry_after
            ? sprintf(
                /* translators: %s = number of seconds until the Dev.to rate limit resets */
                __( 'Dev.to API rate limit hit. Retry after %s seconds.', 'cross-post-devto' ),
                $retry_after
            )
            : __( 'Dev.to API rate limit hit. Try again shortly.', 'cross-post-devto' );

        return new WP_Error( 'devto_rate_limited', $message, [
            'status'      => 429,
            'retry_after' => $retry_after ?: null,
            'body'        => $raw,
        ] );
    }
}
