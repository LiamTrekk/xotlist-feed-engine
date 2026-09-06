<?php
/**
 * XOTLIST Chart Artwork Enrichment Engine
 * Version: 1.0.0
 *
 * Backfills missing `img` fields on chart tracks using Deezer's free
 * track search API. Designed to run in the CRON path (inside
 * xot_live_fetch_chart / xot_live_fetch_afro_charts) so artwork is
 * already resolved before any visitor hits page-chart.php.
 *
 * Pipeline per track with empty img:
 *   1. Deezer track search → album.cover_big (actual song artwork)
 *   2. Artist resolver fallback → Deezer artist photo / iTunes / celebrity DB
 *   3. Cache per artist+title fingerprint — 7 day TTL on hit, 6 h on miss
 *
 * Public API:
 *   xot_chart_enrich_artwork( &$tracks, int $max_api = 8 ): void
 *     Mutates $tracks in-place, filling empty 'img' fields.
 *     $max_api caps external HTTP calls per invocation (cron safety).
 *
 * Load AFTER: xot-live-engine.php, xot-artist-resolver.php, xot-deezer-enrich.php
 *   require_once get_stylesheet_directory() . '/inc/xot-chart-artwork.php';
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ──────────────────────────────────────────────────────────
define( 'XOT_CHART_ART_HIT_TTL',  7 * DAY_IN_SECONDS );   // 7 d — CDN URLs are stable
define( 'XOT_CHART_ART_MISS_TTL', 6 * HOUR_IN_SECONDS );  // 6 h — retry on next cron cycle
define( 'XOT_CHART_ART_PREFIX',   'xot_cart_' );           // transient prefix

// ============================================================
// 1. PUBLIC — Enrich an array of chart tracks in-place
// ============================================================

/**
 * Fill missing `img` on chart tracks.
 *
 * @param array &$tracks Array of chart track arrays (mutated in-place).
 * @param int    $max_api Max external API calls this invocation (default 8).
 */
if ( ! function_exists( 'xot_chart_enrich_artwork' ) ) :
function xot_chart_enrich_artwork( array &$tracks, int $max_api = 8 ): void {
    if ( empty( $tracks ) ) return;

    $api_calls = 0;

    foreach ( $tracks as &$t ) {
        // Already has artwork — skip
        if ( ! empty( $t['img'] ) ) continue;

        $title  = trim( $t['title']  ?? '' );
        $artist = trim( $t['artist'] ?? '' );
        if ( ! $title || ! $artist ) continue;

        $fp        = _xot_chart_art_fp( $title, $artist );
        $cache_key = XOT_CHART_ART_PREFIX . $fp;

        // ── Check transient cache first ────────────────────────────
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) ) {
            // '' = cached miss, non-empty = cached URL
            if ( $cached !== '' ) $t['img'] = $cached;
            continue;
        }

        // ── Budget check — stop API calls but keep checking cache ──
        if ( $api_calls >= $max_api ) continue;

        // ── Layer 1: Deezer track search → album artwork ──────────
        $art_url = _xot_chart_art_deezer_track( $title, $artist );
        $api_calls++;

        // ── Layer 2: Artist resolver fallback → Deezer artist / iTunes / celeb DB ──
        if ( ! $art_url && $api_calls < $max_api ) {
            $art_url = _xot_chart_art_artist_fallback( $artist );
            $api_calls++;
        }

        // ── Store result ──────────────────────────────────────────
        if ( $art_url ) {
            $t['img'] = $art_url;
            set_transient( $cache_key, $art_url, XOT_CHART_ART_HIT_TTL );
        } else {
            set_transient( $cache_key, '', XOT_CHART_ART_MISS_TTL );
        }
    }
    unset( $t );
}
endif;


// ============================================================
// 2. INTERNAL — Deezer track search (album artwork)
// ============================================================

/**
 * Search Deezer for a specific track and return the album cover.
 * Returns '' on failure or no match.
 */
if ( ! function_exists( '_xot_chart_art_deezer_track' ) ) :
function _xot_chart_art_deezer_track( string $title, string $artist ): string {
    // Clean featured artist suffixes for better match accuracy
    $clean_artist = preg_replace(
        '/\s*(?:feat\.?|ft\.?|featuring|&|\+|,|x\s).+$/i', '', $artist
    );
    $clean_title = preg_replace(
        '/\s*\((?:feat\.?|ft\.?|with|prod\.?).*\)$/i', '', $title
    );

    $query = trim( $clean_artist . ' ' . $clean_title );
    $url   = 'https://api.deezer.com/search/track?' . http_build_query([
        'q'     => $query,
        'limit' => 3,
    ]);

    $resp = wp_remote_get( $url, [
        'timeout'    => 6,
        'user-agent' => 'XOTLIST/3.0 (https://xotlist.com; chart-artwork)',
    ] );

    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
        return '';
    }

    $body = json_decode( wp_remote_retrieve_body( $resp ), true );
    $hits = $body['data'] ?? [];

    if ( empty( $hits ) ) return '';

    // ── Fuzzy match: pick the best result ─────────────────────────
    // Deezer search is usually accurate but can return wrong matches
    // for very common words. Verify title+artist loosely match.
    $norm_title  = _xot_chart_art_norm( $clean_title );
    $norm_artist = _xot_chart_art_norm( $clean_artist );

    foreach ( $hits as $hit ) {
        $hit_title  = _xot_chart_art_norm( $hit['title'] ?? '' );
        $hit_artist = _xot_chart_art_norm( $hit['artist']['name'] ?? '' );

        // Title must overlap (either direction — handles "Title (Remix)" vs "Title")
        $title_ok = str_contains( $hit_title, $norm_title )
                 || str_contains( $norm_title, $hit_title )
                 || similar_text( $norm_title, $hit_title ) / max( strlen( $norm_title ), 1 ) > 0.6;

        // Artist must overlap
        $artist_ok = str_contains( $hit_artist, $norm_artist )
                  || str_contains( $norm_artist, $hit_artist )
                  || similar_text( $norm_artist, $hit_artist ) / max( strlen( $norm_artist ), 1 ) > 0.5;

        if ( $title_ok && $artist_ok ) {
            // Prefer cover_big (500×500), fall back through sizes
            return $hit['album']['cover_big']
                ?? $hit['album']['cover_medium']
                ?? $hit['album']['cover']
                ?? '';
        }
    }

    // No confident match — take first result's artwork anyway
    // (Deezer search with artist+title is rarely completely wrong)
    return $hits[0]['album']['cover_big']
        ?? $hits[0]['album']['cover_medium']
        ?? '';
}
endif;


// ============================================================
// 3. INTERNAL — Artist photo fallback
// ============================================================

/**
 * Fall back to artist resolver which cascades:
 *   Celebrity DB → Deezer artist API → iTunes artist → empty
 *
 * Returns artist portrait URL or ''.
 * Not as good as album art but far better than 🎵.
 */
if ( ! function_exists( '_xot_chart_art_artist_fallback' ) ) :
function _xot_chart_art_artist_fallback( string $artist ): string {
    if ( ! function_exists( 'xot_artist_data' ) ) return '';

    $a = xot_artist_data( $artist );
    return $a['photo'] ?? '';
}
endif;


// ============================================================
// 4. INTERNAL — Helpers
// ============================================================

/**
 * Fingerprint: deterministic cache key from title+artist.
 * Strips featured artists, punctuation, normalises case.
 */
if ( ! function_exists( '_xot_chart_art_fp' ) ) :
function _xot_chart_art_fp( string $title, string $artist ): string {
    $a = preg_replace( '/\s*(?:feat\.?|ft\.?|featuring|&|\+|,).+$/i', '', $artist );
    $t = preg_replace( '/\s*\((?:feat\.?|ft\.?|with|prod\.?).*\)$/i', '', $title );
    $raw = mb_strtolower( trim( $a ) . '|' . trim( $t ) );
    return md5( preg_replace( '/[^\p{L}\p{N}|]/u', '', $raw ) );
}
endif;

/**
 * Normalise a string for fuzzy comparison.
 * Strips accents, punctuation, lowercases.
 */
if ( ! function_exists( '_xot_chart_art_norm' ) ) :
function _xot_chart_art_norm( string $s ): string {
    $s = mb_strtolower( trim( $s ) );
    if ( function_exists( 'transliterator_transliterate' ) ) {
        $s = transliterator_transliterate( 'Any-Latin; Latin-ASCII', $s );
    } elseif ( function_exists( 'iconv' ) ) {
        $t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
        if ( $t ) $s = $t;
    }
    return preg_replace( '/[^a-z0-9\s]/', '', $s );
}
endif;
