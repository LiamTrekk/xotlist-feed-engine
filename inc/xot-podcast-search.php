<?php
/**
 * Podcast search REST endpoint.
 *
 * GET /wp-json/xot/v1/podcast-search?q=...&limit=12
 *
 * Returns merged result list:
 *   - episodes: full-text title/show/description across xot_podcast CPT
 *   - shows:    iTunes Podcast Search API (live, cached 1h per query)
 *
 * Cached server-side for 5 minutes per query (transient).
 */

declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function () : void {
    register_rest_route(
        'xot/v1',
        '/podcast-search',
        [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'xot_podcast_search_endpoint',
            'args'                => [
                'q'     => [ 'required' => true,  'type' => 'string' ],
                'limit' => [ 'required' => false, 'type' => 'integer' ],
            ],
        ]
    );
} );

if ( ! function_exists( 'xot_podcast_search_endpoint' ) ) :
function xot_podcast_search_endpoint( WP_REST_Request $req ) {
    $q     = trim( (string) $req->get_param( 'q' ) );
    $limit = max( 4, min( 24, (int) ( $req->get_param( 'limit' ) ?: 12 ) ) );
    if ( strlen( $q ) < 2 ) {
        return new WP_REST_Response( [ 'shows' => [], 'episodes' => [] ], 200 );
    }

    if ( function_exists( 'xot_rest_rate_limit' )
         && xot_rest_rate_limit( 'podcast_search', 30, 60 ) === false ) {
        return new WP_REST_Response( [ 'error' => 'rate_limited' ], 429 );
    }

    $cache_key = 'xot_pdsearch_' . md5( strtolower( $q ) . '|' . $limit );
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return new WP_REST_Response( $cached, 200 );
    }

    $shows    = xot_podcast_search_shows( $q, $limit );
    $episodes = xot_podcast_search_episodes( $q, $limit );

    $payload = [
        'shows'    => $shows,
        'episodes' => $episodes,
    ];
    set_transient( $cache_key, $payload, 300 );
    return new WP_REST_Response( $payload, 200 );
}
endif;

/**
 * Show search via iTunes Podcast Search API (free, no key).
 */
if ( ! function_exists( 'xot_podcast_search_shows' ) ) :
function xot_podcast_search_shows( string $q, int $limit ) : array {
    $url = sprintf(
        'https://itunes.apple.com/search?media=podcast&entity=podcast&limit=%d&term=%s',
        max( 4, min( 25, $limit ) ),
        rawurlencode( $q )
    );
    $resp = wp_remote_get( $url, [ 'timeout' => 5, 'user-agent' => 'XOTLIST/1.0' ] );
    if ( is_wp_error( $resp ) ) return [];
    if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) return [];
    $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
    if ( empty( $body['results'] ) || ! is_array( $body['results'] ) ) return [];

    $out = [];
    foreach ( $body['results'] as $r ) {
        $name = (string) ( $r['collectionName'] ?? '' );
        if ( ! $name ) continue;
        $out[] = [
            'name'      => $name,
            'artist'    => (string) ( $r['artistName']        ?? '' ),
            'art'       => (string) ( $r['artworkUrl600']     ?? $r['artworkUrl100'] ?? '' ),
            'genre'     => (string) ( $r['primaryGenreName'] ?? '' ),
            'apple_url' => (string) ( $r['collectionViewUrl'] ?? '' ),
            'url'       => function_exists( 'xot_podcast_show_url' )
                ? xot_podcast_show_url( $name )
                : home_url( '/podcast-show/' . sanitize_title( $name ) . '/' ),
        ];
    }
    return $out;
}
endif;

/**
 * Episode search across xot_podcast CPT.
 */
if ( ! function_exists( 'xot_podcast_search_episodes' ) ) :
function xot_podcast_search_episodes( string $q, int $limit ) : array {
    $posts = get_posts( [
        'post_type'        => 'xot_podcast',
        's'                => $q,
        'posts_per_page'   => $limit,
        'post_status'      => 'publish',
        'suppress_filters' => false,
    ] );
    $out = [];
    foreach ( $posts as $p ) {
        $show = get_post_meta( $p->ID, '_xot_podcast_show', true ) ?: '';
        $art  = function_exists( 'xot_hero_image' )
            ? (string) xot_hero_image( $p->ID )
            : (string) ( get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '' );
        $out[] = [
            'id'      => (int) $p->ID,
            'title'   => get_the_title( $p ),
            'show'    => (string) $show,
            'art'     => $art,
            'url'     => get_permalink( $p ),
            'date'    => mysql2date( 'M j, Y', $p->post_date_gmt ),
        ];
    }
    return $out;
}
endif;
