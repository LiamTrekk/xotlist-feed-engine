<?php
/**
 * Streaming Now — TMDB ingest engine (sister of Coming Soon).
 *
 * Pulls currently-streaming films + TV from TMDB:
 *   - /movie/popular, /tv/popular, /tv/top_rated
 *   - /discover/movie + /discover/tv with watch_providers filter
 *     (Netflix US, Prime, Disney+, HBO Max, Apple TV+, Paramount+, Hulu, Peacock)
 *   - African catalog from NG/GH/ZA origin (last 365 days)
 *
 * Inserts xot_stream_show CPT posts with:
 *   - _xot_release_status: 'airing' (current) or 'released' (older catalog)
 *   - _xot_streaming_managed: '1' (distinguishes from coming-soon-managed)
 *   - _xot_service: NETFLIX | HBO | DISNEY+ | PRIME | APPLE | HULU |
 *                   PEACOCK | PARAMOUNT+ | SHOWMAX | DSTV | OTHER
 *     (resolved via TMDB /watch/providers endpoint, cached 7 days)
 *   - Standard meta from xot_cs_upsert_one (poster, backdrop, genre, rating, etc.)
 *
 * Quality bar matches Coming Soon: poster + overview required.
 *
 * Cache-thrash mitigation: bulk-insert as DRAFT, then single SQL UPDATE to
 * publish — skips wp_update_post hooks (no LSCache flood, no IndexNow ping
 * cascade). Same anti-phantom-storm pattern from 2026-05-10 incident.
 *
 * @since 2026-05-10
 */

declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

const XOT_STR_META_MANAGED = '_xot_streaming_managed';

/* ─────────────────────────────────────────────────────────────────
   1. PROVIDER MAP — TMDB provider_id → our _xot_service taxonomy
   ─────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'xot_str_provider_map' ) ) :
function xot_str_provider_map(): array {
    return [
        8   => 'NETFLIX',
        9   => 'PRIME',           // Amazon Prime Video
        119 => 'PRIME',           // Amazon (alt id in some regions)
        337 => 'DISNEY+',
        384 => 'HBO',             // HBO Max (now Max)
        1899=> 'HBO',              // Max
        350 => 'APPLE',
        531 => 'PARAMOUNT+',
        15  => 'HULU',
        386 => 'PEACOCK',
        582 => 'PARAMOUNT+',      // Paramount+ Apple Channel
        619 => 'SHOWMAX',         // Showmax (ZA)
        1859=> 'DSTV',             // DStv
    ];
}
endif;

/* ─────────────────────────────────────────────────────────────────
   2. PROVIDER LOOKUP — cached 7d
   ─────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'xot_str_resolve_service' ) ) :
function xot_str_resolve_service( string $tmdb_type, int $tmdb_id, array $origin_countries = [] ): string {
    $key = 'xot_str_svc_' . $tmdb_type . '_' . $tmdb_id;
    $cached = get_transient( $key );
    if ( is_string( $cached ) ) return $cached;

    $api_key = function_exists( 'xot_live_tmdb_key' ) ? xot_live_tmdb_key() : '';
    if ( ! $api_key || ! function_exists( 'xot_live_get_json' ) ) return '';

    // African-origin fallback: Nigerian/Ghanaian/SA productions often have
    // sparse TMDB watch/providers data. If the title was sourced from our
    // movie_streaming_africa or tv_streaming_africa endpoints (origin in
    // NG/GH/ZA), default service = SHOWMAX (the most common pan-African
    // streamer). Better than dropping into OTHER and being filtered out.
    $is_african_origin = ! empty( $origin_countries )
        && count( array_intersect( $origin_countries, [ 'NG', 'GH', 'ZA' ] ) ) > 0;

    $url  = "https://api.themoviedb.org/3/{$tmdb_type}/{$tmdb_id}/watch/providers?api_key={$api_key}";
    $data = xot_live_get_json( $url );

    // No data at all → African fallback or empty
    if ( ! is_array( $data ) || empty( $data['results'] ) ) {
        $resolved = $is_african_origin ? 'SHOWMAX' : '';
        set_transient( $key, $resolved, $is_african_origin ? 7 * DAY_IN_SECONDS : 3 * DAY_IN_SECONDS );
        return $resolved;
    }

    // Region priority: US > GB > ZA > NG > GH > first-available
    $region_priority = [ 'US', 'GB', 'ZA', 'NG', 'GH' ];
    $picked_region   = null;
    foreach ( $region_priority as $r ) {
        if ( ! empty( $data['results'][ $r ]['flatrate'] ) ) { $picked_region = $r; break; }
    }
    if ( ! $picked_region ) {
        foreach ( $data['results'] as $r => $payload ) {
            if ( ! empty( $payload['flatrate'] ) ) { $picked_region = $r; break; }
        }
    }
    if ( ! $picked_region ) {
        $resolved = $is_african_origin ? 'SHOWMAX' : 'OTHER';
        set_transient( $key, $resolved, 7 * DAY_IN_SECONDS );
        return $resolved;
    }

    $providers = $data['results'][ $picked_region ]['flatrate'];
    $map       = xot_str_provider_map();

    foreach ( $providers as $p ) {
        $pid = (int) ( $p['provider_id'] ?? 0 );
        if ( isset( $map[ $pid ] ) ) {
            set_transient( $key, $map[ $pid ], 7 * DAY_IN_SECONDS );
            return $map[ $pid ];
        }
    }

    // Mapped provider not found — but for African-origin items, prefer
    // SHOWMAX over generic OTHER bucket since most Nollywood content
    // legitimately streams there even when TMDB doesn't track it.
    $resolved = $is_african_origin ? 'SHOWMAX' : 'OTHER';
    set_transient( $key, $resolved, 7 * DAY_IN_SECONDS );
    return $resolved;
}
endif;

/* ─────────────────────────────────────────────────────────────────
   3. FETCH STREAMING-NOW FROM TMDB (mirrors xot_cs_fetch_all)
   ─────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'xot_str_fetch_all' ) ) :
function xot_str_fetch_all(): array {
    $api_key = function_exists( 'xot_live_tmdb_key' ) ? xot_live_tmdb_key() : '';
    if ( ! $api_key ) return [];

    $today    = date( 'Y-m-d' );
    $past_90  = date( 'Y-m-d', strtotime( '-90 days' ) );
    $past_365 = date( 'Y-m-d', strtotime( '-365 days' ) );
    $base     = 'https://api.themoviedb.org/3';
    $params   = "api_key={$api_key}&language=en-US";
    $africa   = 'NG|GH|ZA';
    $providers_us = '8|9|337|384|350|531|15|386'; // Netflix Prime Disney+ HBO Apple Paramount+ Hulu Peacock

    $endpoints = [
        // /movie/popular — TMDB curated popular films
        'movie_popular' => [
            'url'  => "{$base}/movie/popular?{$params}",
            'type' => 'movie',
            'pages'=> 3,
        ],
        // /tv/popular — TMDB curated popular TV
        'tv_popular' => [
            'url'  => "{$base}/tv/popular?{$params}",
            'type' => 'tv',
            'pages'=> 3,
        ],
        // /tv/airing_today — TV airing in the next 24h (truly current)
        'tv_airing_today' => [
            'url'  => "{$base}/tv/airing_today?{$params}",
            'type' => 'tv',
            'pages'=> 2,
        ],
        // /discover/movie — released last 90d on major US streamers
        'movie_provider_discover' => [
            'url'  => "{$base}/discover/movie?{$params}"
                   . "&with_watch_providers={$providers_us}&watch_region=US"
                   . "&primary_release_date.gte={$past_90}&primary_release_date.lte={$today}"
                   . "&sort_by=popularity.desc",
            'type' => 'movie',
            'pages'=> 3,
        ],
        // /discover/tv — returning series on major US streamers
        'tv_provider_discover' => [
            'url'  => "{$base}/discover/tv?{$params}"
                   . "&with_watch_providers={$providers_us}&watch_region=US"
                   . "&with_status=0" // returning_series
                   . "&sort_by=popularity.desc",
            'type' => 'tv',
            'pages'=> 3,
        ],
        // African catalog — origin NG/GH/ZA, released last 365d
        'movie_streaming_africa' => [
            'url'  => "{$base}/discover/movie?{$params}"
                   . "&with_origin_country={$africa}"
                   . "&primary_release_date.gte={$past_365}&primary_release_date.lte={$today}"
                   . "&sort_by=popularity.desc",
            'type' => 'movie',
            'pages'=> 3,
        ],
        'tv_streaming_africa' => [
            'url'  => "{$base}/discover/tv?{$params}"
                   . "&with_origin_country={$africa}"
                   . "&first_air_date.gte={$past_365}&first_air_date.lte={$today}"
                   . "&sort_by=popularity.desc",
            'type' => 'tv',
            'pages'=> 3,
        ],
    ];

    $all = [];

    foreach ( $endpoints as $source => $cfg ) {
        $rows = function_exists( 'xot_cs_fetch_tmdb_endpoint' )
            ? xot_cs_fetch_tmdb_endpoint( $cfg['url'], $cfg['pages'] )
            : [];

        foreach ( $rows as $r ) {
            $is_movie = $cfg['type'] === 'movie';
            $title    = (string) ( $is_movie ? ( $r['title'] ?? '' ) : ( $r['name'] ?? '' ) );
            $rel_date = (string) ( $is_movie ? ( $r['release_date'] ?? '' ) : ( $r['first_air_date'] ?? '' ) );
            if ( $title === '' ) continue;
            if ( $rel_date === '' ) $rel_date = date( 'Y-m-d', strtotime( '-30 days' ) );

            // Quality filter: poster + overview required (matches Coming Soon)
            $poster_path = (string) ( $r['poster_path'] ?? '' );
            $overview    = (string) ( $r['overview'] ?? '' );
            if ( $poster_path === '' || trim( $overview ) === '' ) continue;

            $tmdb_id = (int) $r['id'];
            $key = $cfg['type'] . ':' . $tmdb_id;
            if ( isset( $all[ $key ] ) ) continue; // first source wins

            $genre_ids = is_array( $r['genre_ids'] ?? null ) ? $r['genre_ids'] : [];
            $genre_map = function_exists( 'xot_cs_genre_map' ) ? xot_cs_genre_map( $cfg['type'] ) : [];
            $genres    = array_filter( array_map( fn( $gid ) => $genre_map[ (int) $gid ] ?? '', $genre_ids ) );

            $all[ $key ] = [
                'tmdb_id'      => $tmdb_id,
                'tmdb_type'    => $cfg['type'],
                'title'        => $title,
                'release_date' => $rel_date,
                'overview'     => $overview,
                'poster_path'  => $poster_path,
                'backdrop_path'=> (string) ( $r['backdrop_path'] ?? '' ),
                'popularity'   => (float) ( $r['popularity'] ?? 0 ),
                'rating'       => (float) ( $r['vote_average'] ?? 0 ),
                'genres'       => array_values( $genres ),
                'source'       => $source,
            ];
        }
        usleep( 500000 ); // 0.5s between endpoints
    }

    return array_values( $all );
}
endif;

/* ─────────────────────────────────────────────────────────────────
   4. UPSERT WITH STREAMING STATUS + SERVICE
   ─────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'xot_str_upsert_one' ) ) :
function xot_str_upsert_one( array $item ): array {
    if ( ! function_exists( 'xot_cs_find_existing' ) || ! function_exists( 'xot_cs_upsert_one' ) ) {
        return [ 'action' => 'error', 'error' => 'coming_soon_sync_unavailable' ];
    }

    $tmdb_id   = (int) $item['tmdb_id'];
    $tmdb_type = (string) $item['tmdb_type'];
    $existing  = xot_cs_find_existing( $tmdb_id, $tmdb_type );

    // Origin-country hint for the African fallback in the resolver.
    // movie_streaming_africa / tv_streaming_africa sources by definition
    // have NG|GH|ZA origin from the with_origin_country filter.
    $origin_hint = ( strpos( (string) ( $item['source'] ?? '' ), 'africa' ) !== false )
        ? [ 'NG', 'GH', 'ZA' ]
        : [];

    // If a coming-soon-managed post already exists with this TMDB ID,
    // it's the SAME entity — don't create a duplicate. The lifecycle
    // flip will update its status from 'upcoming' to 'airing' as the
    // release date passes; we just stamp service + streaming flag.
    if ( $existing ) {
        update_post_meta( $existing, XOT_STR_META_MANAGED, '1' );
        $service = xot_str_resolve_service( $tmdb_type, $tmdb_id, $origin_hint );
        if ( $service && ! get_post_meta( $existing, '_xot_service', true ) ) {
            update_post_meta( $existing, '_xot_service', $service );
        }
        return [ 'action' => 'merged_existing', 'id' => $existing, 'service' => $service ];
    }

    // Insert via the existing Coming Soon upsert (creates as DRAFT).
    // Then flip status from 'upcoming' to 'airing' (this is currently-streaming
    // content, not a future release).
    $r = xot_cs_upsert_one( $item );
    if ( $r['action'] !== 'inserted' ) return $r;

    $pid = (int) $r['id'];
    update_post_meta( $pid, XOT_STR_META_MANAGED, '1' );

    // Status: airing if release in last 90d, else released
    $rel_ts  = strtotime( (string) $item['release_date'] );
    $is_airing = $rel_ts && $rel_ts > strtotime( '-90 days' );
    update_post_meta( $pid, '_xot_release_status', $is_airing ? 'airing' : 'released' );

    // Service detection (extra TMDB call — cached 7d). Origin hint enables
    // SHOWMAX fallback for African-sourced items where TMDB has no provider data.
    $service = xot_str_resolve_service( $tmdb_type, $tmdb_id, $origin_hint );
    update_post_meta( $pid, '_xot_service', $service ?: 'OTHER' );

    return [ 'action' => 'inserted', 'id' => $pid, 'service' => $service ];
}
endif;

/* ─────────────────────────────────────────────────────────────────
   5. ORCHESTRATOR — single sync pass
   ─────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'xot_str_run_sync' ) ) :
function xot_str_run_sync( int $limit = 0, bool $dry_run = false ): array {
    $start = microtime( true );
    $items = xot_str_fetch_all();
    if ( $limit > 0 ) $items = array_slice( $items, 0, $limit );

    $totals = [
        'fetched'         => count( $items ),
        'inserted'        => 0,
        'merged_existing' => 0,
        'updated'         => 0,
        'skipped_curated' => 0,
        'errors'          => 0,
        'dry_run'         => $dry_run,
        'preview'         => [],
    ];

    foreach ( $items as $item ) {
        if ( $dry_run ) {
            $existing = function_exists( 'xot_cs_find_existing' )
                ? xot_cs_find_existing( (int) $item['tmdb_id'], (string) $item['tmdb_type'] )
                : 0;
            $totals['preview'][] = [
                'action'       => $existing ? 'would_merge' : 'would_insert',
                'tmdb_type'    => $item['tmdb_type'],
                'title'        => $item['title'],
                'release_date' => $item['release_date'],
                'genres'       => implode( ',', $item['genres'] ),
                'source'       => $item['source'],
            ];
            continue;
        }
        $r = xot_str_upsert_one( $item );
        $action = $r['action'] ?? 'error';
        if ( isset( $totals[ $action ] ) ) $totals[ $action ]++;
        elseif ( $action === 'error' ) $totals['errors']++;
    }

    // Bulk-publish the just-inserted drafts via direct SQL to skip publish
    // hooks (avoid LSCache purge cascade × 4 URLs per post + IndexNow flood).
    // The 2026-05-10 phantom-post storm taught this lesson — never rely on
    // wp_update_post for hundreds of state changes.
    if ( ! $dry_run && $totals['inserted'] > 0 ) {
        global $wpdb;
        $rows = $wpdb->query(
            "UPDATE {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 AND pm.meta_key = '" . XOT_STR_META_MANAGED . "'
                 AND pm.meta_value = '1'
                SET p.post_status = 'publish'
              WHERE p.post_type = 'xot_stream_show'
                AND p.post_status = 'draft'
                AND p.post_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $totals['bulk_published'] = (int) $rows;
    }

    $totals['elapsed_s'] = round( microtime( true ) - $start, 2 );

    if ( ! $dry_run && function_exists( 'xot_event' ) ) {
        xot_event( 'streaming.sync.complete', $totals, 'info', 'streaming' );
    }
    return $totals;
}
endif;

/* ─────────────────────────────────────────────────────────────────
   6. CRON REGISTRATION — daily 04:30 UTC (after Coming Soon at 04:00)
   ─────────────────────────────────────────────────────────────── */
add_action( 'xot_streaming_sync_daily', 'xot_str_run_sync' );

if ( function_exists( 'xot_cron_alive_register' ) ) {
    xot_cron_alive_register( 'streaming_sync_daily', 'xot_streaming_sync_daily', DAY_IN_SECONDS, 5, [
        'first_run' => max( 0, strtotime( 'tomorrow 04:30 UTC' ) - time() ),
        'label'     => 'Streaming — daily sync',
    ] );
} else {
    add_action( 'init', function () : void {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) return;
        if ( ! wp_next_scheduled( 'xot_streaming_sync_daily' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 04:30 UTC' ), 'daily', 'xot_streaming_sync_daily' );
        }
    } );
}

/* ─────────────────────────────────────────────────────────────────
   7. WP-CLI
   ─────────────────────────────────────────────────────────────── */
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
    WP_CLI::add_command( 'xot streaming sync', function ( array $args, array $assoc ) : void {
        $limit   = isset( $assoc['limit'] )   ? (int) $assoc['limit'] : 0;
        $dry_run = isset( $assoc['dry-run'] );
        if ( $dry_run ) WP_CLI::log( '[DRY-RUN] no DB writes — drop --dry-run to apply.' );
        if ( $limit )   WP_CLI::log( "[LIMIT={$limit}] capping to first {$limit} items." );

        $totals = xot_str_run_sync( $limit, $dry_run );

        if ( $dry_run ) {
            $by_source = [];
            foreach ( $totals['preview'] as $p ) {
                $by_source[ $p['source'] ] = ( $by_source[ $p['source'] ] ?? 0 ) + 1;
            }
            foreach ( $by_source as $src => $n ) {
                WP_CLI::log( sprintf( '  %-26s %d', $src, $n ) );
            }
            $would_insert = count( array_filter( $totals['preview'], fn( $p ) => $p['action'] === 'would_insert' ) );
            $would_merge  = count( $totals['preview'] ) - $would_insert;
            WP_CLI::success( sprintf( 'DRY-RUN · fetched=%d would_insert=%d would_merge=%d · %ss',
                $totals['fetched'], $would_insert, $would_merge, $totals['elapsed_s'] ) );
            return;
        }

        WP_CLI::success( sprintf(
            'Streaming sync · fetched=%d inserted=%d merged_existing=%d errors=%d bulk_published=%d · %ss',
            $totals['fetched'], $totals['inserted'], $totals['merged_existing'], $totals['errors'],
            $totals['bulk_published'] ?? 0, $totals['elapsed_s']
        ) );
    } );

    WP_CLI::add_command( 'xot streaming stats', function ( array $args, array $assoc ) : void {
        global $wpdb;
        $base = [
            'post_type'      => 'xot_stream_show',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        // Streaming-managed by status
        $by_status = [];
        foreach ( [ 'airing', 'released', 'upcoming' ] as $st ) {
            $q = new WP_Query( array_merge( $base, [
                'meta_query' => [
                    [ 'key' => XOT_STR_META_MANAGED, 'value' => '1', 'compare' => '=' ],
                    [ 'key' => '_xot_release_status', 'value' => $st, 'compare' => '=' ],
                ],
            ] ) );
            $by_status[ $st ] = count( $q->posts );
        }
        WP_CLI::log( 'Streaming-managed inventory:' );
        foreach ( $by_status as $st => $n ) {
            WP_CLI::log( sprintf( '  %-10s %d', $st, $n ) );
        }

        // By service
        WP_CLI::log( '' );
        $services = $wpdb->get_results(
            "SELECT pm.meta_value AS service, COUNT(DISTINCT p.ID) AS n
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_xot_service'
               JOIN {$wpdb->postmeta} pm2 ON pm2.post_id=p.ID AND pm2.meta_key='" . XOT_STR_META_MANAGED . "' AND pm2.meta_value='1'
              WHERE p.post_type='xot_stream_show' AND p.post_status='publish'
           GROUP BY pm.meta_value ORDER BY n DESC"
        );
        WP_CLI::log( 'By service:' );
        foreach ( $services as $svc ) {
            WP_CLI::log( sprintf( '  %-12s %d', $svc->service ?: 'OTHER', $svc->n ) );
        }
    } );
}
