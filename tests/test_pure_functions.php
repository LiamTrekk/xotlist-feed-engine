<?php
/**
 * Tests for the pure logic in this repository.
 *
 * The modules here are extracts from a WordPress theme and do not run
 * standalone. But three functions carry the logic that actually decides whether
 * ingestion behaves correctly, and none of them depend on WordPress state:
 *
 *   _xot_chart_art_fp()            cache-key fingerprinting
 *   _xot_chart_art_norm()          normalisation for fuzzy comparison
 *   xot_trend_rss_canonical_url()  cross-feed deduplication
 *
 * They are loaded in isolation below so the properties the README claims can be
 * checked rather than taken on trust.
 *
 *   php tests/test_pure_functions.php
 */

declare( strict_types=1 );

// ---------------------------------------------------------------------------
// The single WordPress dependency, reimplemented to its documented behaviour:
// remove the named query args from a URL. Everything else under test is plain
// PHP. This shim is declared rather than hidden — it is the only WP surface the
// functions below touch.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'remove_query_arg' ) ) {
    function remove_query_arg( $keys, string $url ): string {
        $keys  = (array) $keys;
        $parts = parse_url( $url );
        if ( empty( $parts['query'] ) ) {
            return $url;
        }
        parse_str( $parts['query'], $q );
        foreach ( $keys as $k ) {
            unset( $q[ $k ] );
        }
        $query = http_build_query( $q );
        $out   = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' )
               . ( $parts['path'] ?? '' );
        if ( $query !== '' ) {
            $out .= '?' . $query;
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $out .= '#' . $parts['fragment'];
        }
        return $out;
    }
}

// Extract only the function bodies under test. Loading the modules wholesale
// would pull in add_action() and friends, which is precisely what we are
// avoiding.
function load_function( string $file, string $name ): void {
    $src = file_get_contents( __DIR__ . '/../inc/' . $file );
    if ( ! preg_match( '/^function ' . preg_quote( $name, '/' ) . '\s*\(.*?^}/ms', $src, $m ) ) {
        fwrite( STDERR, "could not extract {$name} from {$file}\n" );
        exit( 1 );
    }
    eval( $m[0] );
}

load_function( 'xot-chart-artwork.php', '_xot_chart_art_fp' );
load_function( 'xot-chart-artwork.php', '_xot_chart_art_norm' );
load_function( 'xot-trend-rss.php', 'xot_trend_rss_canonical_url' );

// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
    global $passed, $failed;
    if ( $ok ) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ( $detail ? " — {$detail}" : '' ) . "\n";
    }
}

// --- fingerprint: featured artists must not fragment the cache ---------------
// This is the whole point of the function. If "Drake feat. 21 Savage" produced a
// different key from "Drake", every collaboration would miss cache and burn an
// API call.
$base = _xot_chart_art_fp( 'Rich Flex', 'Drake' );
foreach ( [ 'Drake feat. 21 Savage', 'Drake ft. 21 Savage', 'Drake featuring 21 Savage',
            'Drake & 21 Savage', 'Drake + 21 Savage', 'Drake, 21 Savage' ] as $variant ) {
    check(
        "fingerprint collapses '{$variant}'",
        _xot_chart_art_fp( 'Rich Flex', $variant ) === $base
    );
}

// --- fingerprint: title qualifiers must not fragment either ------------------
foreach ( [ 'Rich Flex (feat. 21 Savage)', 'Rich Flex (with 21 Savage)',
            'Rich Flex (prod. Vinylz)' ] as $variant ) {
    check(
        "fingerprint collapses title '{$variant}'",
        _xot_chart_art_fp( $variant, 'Drake' ) === $base
    );
}

// --- fingerprint: case and punctuation are irrelevant ------------------------
check( 'fingerprint is case-insensitive',
    _xot_chart_art_fp( 'RICH FLEX', 'DRAKE' ) === $base );
check( 'fingerprint ignores punctuation',
    _xot_chart_art_fp( 'Rich  Flex!', ' Drake. ' ) === $base );

// --- fingerprint: genuinely different tracks must NOT collide ----------------
// A cache key that collapsed everything would be worse than none.
check( 'different track yields a different key',
    _xot_chart_art_fp( 'God\'s Plan', 'Drake' ) !== $base );
check( 'different artist yields a different key',
    _xot_chart_art_fp( 'Rich Flex', 'Future' ) !== $base );

// --- fingerprint: deterministic ---------------------------------------------
$stable = true;
for ( $i = 0; $i < 100; $i++ ) {
    if ( _xot_chart_art_fp( 'Rich Flex', 'Drake' ) !== $base ) { $stable = false; break; }
}
check( 'fingerprint is deterministic across repeated calls', $stable );

// --- normalisation ----------------------------------------------------------
check( 'norm lowercases and strips punctuation',
    _xot_chart_art_norm( 'Beyoncé — HALO!' ) === 'beyonce  halo'
    || _xot_chart_art_norm( 'Beyoncé — HALO!' ) === 'beyonce halo',
    'got: ' . _xot_chart_art_norm( 'Beyoncé — HALO!' ) );
check( 'norm is idempotent',
    _xot_chart_art_norm( _xot_chart_art_norm( 'Sigur Rós' ) )
    === _xot_chart_art_norm( 'Sigur Rós' ) );

// --- canonical URL: cross-feed deduplication --------------------------------
// Two publications syndicating the same article append different tracking
// params. Without this, the same story is ingested twice.
$canon = xot_trend_rss_canonical_url( 'https://example.com/story' );
foreach ( [
    'https://example.com/story?utm_source=feedburner',
    'https://example.com/story?utm_medium=rss&utm_campaign=x',
    'https://example.com/story?fbclid=abc123',
    'https://example.com/story?gclid=xyz',
    'https://example.com/story?mc_cid=1&mc_eid=2',
    'https://example.com/story/',
    'https://example.com/story#comments',
] as $dirty ) {
    $label = parse_url( $dirty, PHP_URL_QUERY );
    if ( ! $label ) {
        $label = str_contains( $dirty, '#' ) ? 'fragment' : 'trailing slash';
    }
    check( "canonicalises {$label}",
        xot_trend_rss_canonical_url( $dirty ) === $canon,
        'got: ' . xot_trend_rss_canonical_url( $dirty ) );
}

// --- canonical URL: meaningful params must survive ---------------------------
// Over-stripping would collapse genuinely different pages into one.
check( 'preserves non-tracking query params',
    str_contains( xot_trend_rss_canonical_url( 'https://example.com/story?id=42' ), 'id=42' ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
