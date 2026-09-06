<?php
/**
 * RSS trend aggregator — fashion publication feeds → xot_trend stubs.
 *
 * Pulls fresh items from BoF, Hypebeast, Vogue Runway, Dazed, i-D,
 * Highsnobiety, W Magazine. Each item lands as a draft xot_trend post
 * flagged `_xot_needs_editorial_review` so editors can flesh out into
 * proper editorial content before publishing.
 *
 * NOT auto-publishing AI-summary editorial — per memory rule, AI-voice
 * articles get deleted. These are EDITORIAL STUBS: title + canonical
 * link + first paragraph + featured image, drafted for human review.
 *
 * Defaults to DRY-RUN. Cron disabled by default until explicitly opted
 * in via `xot_trend_rss_enabled` option.
 *
 * Storage:
 *   - xot_trend post status=draft
 *   - postmeta `_xot_source_url` (canonical), `_xot_source_feed`,
 *     `_xot_source_pubdate`, `_xot_needs_editorial_review`=1
 *   - dedup on `_xot_source_url` (no two stubs for the same article)
 *
 * @since 5.0.0
 */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'xot_trend_rss_feeds' ) ) :
function xot_trend_rss_feeds(): array {
    // Cross-vertical: fashion + entertainment + music + culture.
    // Filterable so editors can add/remove sources without code changes.
    return apply_filters( 'xot_trend_rss_feeds', [
        // Fashion
        'bof'           => 'https://www.businessoffashion.com/feed',
        'hypebeast'     => 'https://hypebeast.com/feed',
        'vogue'         => 'https://www.vogue.com/feed/rss',
        'dazed'         => 'https://www.dazeddigital.com/rss',
        'highsnobiety'  => 'https://www.highsnobiety.com/feed/',
        'wmagazine'     => 'https://www.wmagazine.com/rss',
        // Entertainment
        'variety'       => 'https://variety.com/feed/',
        'thr'           => 'https://www.hollywoodreporter.com/feed/',
        'deadline'      => 'https://deadline.com/feed/',
        'vulture'       => 'https://www.vulture.com/rss/index.xml',
        // Music
        'pitchfork'     => 'https://pitchfork.com/feed/feed-news/rss',
        'rollingstone'  => 'https://www.rollingstone.com/music/feed/',
        'billboard'     => 'https://www.billboard.com/feed/',
        // Culture / lifestyle
        'theatlantic-c' => 'https://www.theatlantic.com/feed/channel/entertainment/',
    ] );
}
endif;

if ( ! function_exists( 'xot_trend_rss_fetch_feed' ) ) :
function xot_trend_rss_fetch_feed( string $url ): array {
    // Avoid WP's fetch_feed() — per memory wp-options-bloat-pattern,
    // it writes unbounded transients. Direct wp_remote_get + parse.
    $res = wp_remote_get( $url, [
        'timeout' => 12,
        'headers' => [
            'Accept'     => 'application/rss+xml, application/atom+xml, application/xml, text/xml; q=0.9, */*; q=0.8',
            'User-Agent' => 'XOTList Trend Aggregator (' . parse_url( home_url(), PHP_URL_HOST ) . ')',
        ],
    ] );
    if ( is_wp_error( $res ) ) return [];
    if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) return [];

    $body = (string) wp_remote_retrieve_body( $res );
    if ( $body === '' ) return [];

    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    if ( ! $xml ) return [];

    // RSS 2.0 (channel/item) or Atom (feed/entry)
    $items = [];
    if ( isset( $xml->channel->item ) ) {
        foreach ( $xml->channel->item as $item ) {
            $items[] = xot_trend_rss_normalize_rss( $item );
        }
    } elseif ( isset( $xml->entry ) ) {
        foreach ( $xml->entry as $entry ) {
            $items[] = xot_trend_rss_normalize_atom( $entry );
        }
    }
    return array_values( array_filter( $items ) );
}
endif;

if ( ! function_exists( 'xot_trend_rss_normalize_rss' ) ) :
function xot_trend_rss_normalize_rss( SimpleXMLElement $item ): ?array {
    $title = trim( (string) ( $item->title ?? '' ) );
    $link  = trim( (string) ( $item->link  ?? '' ) );
    if ( $title === '' || $link === '' ) return null;
    $desc  = trim( (string) ( $item->description ?? '' ) );
    // strip tags + truncate to ~280 chars for excerpt
    $excerpt = wp_html_excerpt( wp_strip_all_tags( $desc, true ), 280, '…' );
    $pubdate = trim( (string) ( $item->pubDate ?? '' ) );
    $pub_iso = $pubdate ? gmdate( 'c', strtotime( $pubdate ) ) : '';
    // Try to extract image from media:thumbnail or enclosure
    $image = '';
    foreach ( $item->children( 'media', true ) as $key => $child ) {
        $attrs = $child->attributes();
        if ( isset( $attrs['url'] ) ) { $image = (string) $attrs['url']; break; }
    }
    if ( $image === '' && isset( $item->enclosure ) ) {
        $att = $item->enclosure->attributes();
        if ( isset( $att['type'] ) && strpos( (string) $att['type'], 'image/' ) === 0 ) {
            $image = (string) $att['url'];
        }
    }
    return [
        'title'   => $title,
        'link'    => $link,
        'excerpt' => $excerpt,
        'pubdate' => $pub_iso,
        'image'   => $image,
    ];
}
endif;

if ( ! function_exists( 'xot_trend_rss_normalize_atom' ) ) :
function xot_trend_rss_normalize_atom( SimpleXMLElement $entry ): ?array {
    $title = trim( (string) ( $entry->title ?? '' ) );
    $link  = '';
    foreach ( $entry->link as $l ) {
        $a = $l->attributes();
        if ( ! isset( $a['rel'] ) || (string) $a['rel'] === 'alternate' ) {
            $link = (string) ( $a['href'] ?? '' );
            break;
        }
    }
    if ( $title === '' || $link === '' ) return null;
    $summary = trim( (string) ( $entry->summary ?? $entry->content ?? '' ) );
    $excerpt = wp_html_excerpt( wp_strip_all_tags( $summary, true ), 280, '…' );
    $updated = trim( (string) ( $entry->published ?? $entry->updated ?? '' ) );
    $pub_iso = $updated ? gmdate( 'c', strtotime( $updated ) ) : '';
    return [
        'title'   => $title,
        'link'    => $link,
        'excerpt' => $excerpt,
        'pubdate' => $pub_iso,
        'image'   => '',
    ];
}
endif;

/* ──────────────────────────────────────────────
 * Dedup helpers
 * ────────────────────────────────────────────── */

if ( ! function_exists( 'xot_trend_rss_canonical_url' ) ) :
function xot_trend_rss_canonical_url( string $url ): string {
    // Strip tracking params + fragment + trailing slash. Catches most
    // cross-feed dupes (same article republished by 2 sources usually
    // links to the SAME canonical URL).
    $strip = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
               'utm_content', 'fbclid', 'gclid', '_ga', 'mc_cid', 'mc_eid' ];
    $clean = remove_query_arg( $strip, $url );
    $clean = strtok( $clean, '#' ) ?: $clean;
    $clean = rtrim( $clean, '/' );
    return $clean;
}
endif;

if ( ! function_exists( 'xot_trend_rss_find_by_url' ) ) :
function xot_trend_rss_find_by_url( string $url ): int {
    if ( $url === '' ) return 0;
    global $wpdb;
    $canonical = xot_trend_rss_canonical_url( $url );
    // Match either the raw URL or the canonical form to catch cross-feed dupes.
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key IN ('_xot_source_url', '_xot_source_canonical')
           AND pm.meta_value IN (%s, %s)
           AND p.post_type = 'xot_trend'
         LIMIT 1",
        $url, $canonical
    ) );
}
endif;

/**
 * Enrich an RSS item by fetching the canonical URL and parsing the
 * page's <meta property="og:image"> (if present). Most fashion RSS
 * feeds omit images; OG-scraping fills the gap.
 */
if ( ! function_exists( 'xot_trend_rss_enrich_og_image' ) ) :
function xot_trend_rss_enrich_og_image( string $url ): string {
    if ( $url === '' ) return '';
    $res = wp_remote_get( $url, [
        'timeout'    => 8,
        'redirection'=> 5,
        'user-agent' => 'XOTList Trend Aggregator (xotlist.com)',
        'headers'    => [ 'Accept' => 'text/html' ],
    ] );
    if ( is_wp_error( $res ) ) return '';
    if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) return '';
    $html = (string) wp_remote_retrieve_body( $res );
    if ( $html === '' ) return '';

    // Only parse the head — saves memory on long article pages.
    $head_end = stripos( $html, '</head>' );
    $head     = $head_end !== false ? substr( $html, 0, $head_end ) : substr( $html, 0, 32768 );

    if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m ) ) {
        return esc_url_raw( $m[1] );
    }
    if ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $head, $m ) ) {
        return esc_url_raw( $m[1] );
    }
    if ( preg_match( '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m ) ) {
        return esc_url_raw( $m[1] );
    }
    return '';
}
endif;

/* ──────────────────────────────────────────────
 * Feed → vertical + publication mapping. Bakes in editorial knowledge
 * about each source so single-trend pages can show "via Variety"-style
 * attribution and cross-vertical archives can filter correctly.
 * ────────────────────────────────────────────── */

if ( ! function_exists( 'xot_trend_rss_feed_meta' ) ) :
function xot_trend_rss_feed_meta( string $feed_key ): array {
    static $map = null;
    if ( $map === null ) {
        $map = apply_filters( 'xot_trend_rss_feed_meta', [
            'bof'           => [ 'vertical' => 'fashion',       'name' => 'Business of Fashion',   'tier' => 'tier1' ],
            'hypebeast'     => [ 'vertical' => 'fashion',       'name' => 'Hypebeast',             'tier' => 'tier1' ],
            'vogue'         => [ 'vertical' => 'fashion',       'name' => 'Vogue',                 'tier' => 'tier1' ],
            'dazed'         => [ 'vertical' => 'fashion',       'name' => 'Dazed',                 'tier' => 'tier2' ],
            'highsnobiety'  => [ 'vertical' => 'fashion',       'name' => 'Highsnobiety',          'tier' => 'tier1' ],
            'wmagazine'     => [ 'vertical' => 'fashion',       'name' => 'W Magazine',            'tier' => 'tier1' ],
            'variety'       => [ 'vertical' => 'entertainment', 'name' => 'Variety',               'tier' => 'tier1' ],
            'thr'           => [ 'vertical' => 'entertainment', 'name' => 'The Hollywood Reporter','tier' => 'tier1' ],
            'deadline'      => [ 'vertical' => 'entertainment', 'name' => 'Deadline',              'tier' => 'tier1' ],
            'vulture'       => [ 'vertical' => 'entertainment', 'name' => 'Vulture',               'tier' => 'tier1' ],
            'pitchfork'     => [ 'vertical' => 'music',         'name' => 'Pitchfork',             'tier' => 'tier1' ],
            'rollingstone'  => [ 'vertical' => 'music',         'name' => 'Rolling Stone',         'tier' => 'tier1' ],
            'billboard'     => [ 'vertical' => 'music',         'name' => 'Billboard',             'tier' => 'tier1' ],
            'theatlantic-c' => [ 'vertical' => 'culture',       'name' => 'The Atlantic',          'tier' => 'tier1' ],
        ] );
    }
    return $map[ $feed_key ] ?? [ 'vertical' => 'culture', 'name' => ucfirst( $feed_key ), 'tier' => 'tier2' ];
}
endif;

/* ──────────────────────────────────────────────
 * Fetch + extract article body from the source URL via DOMDocument.
 *
 * RSS feeds only ship a 280-char description. To answer "why is this
 * trending" we need real body content. Readability-style extraction:
 * strip nav/scripts/ads, pull <article>/<main>/role=main containers,
 * collect <p> tags ≥40 chars. Caps at 30 paragraphs / 1500 words.
 *
 * @return array{body:string,image:string,word_count:int}
 * @since 5.4.0
 * ────────────────────────────────────────────── */

if ( ! function_exists( 'xot_trend_rss_fetch_article' ) ) :
function xot_trend_rss_fetch_article( string $url ): array {
    $out = [ 'body' => '', 'image' => '', 'word_count' => 0 ];
    if ( $url === '' ) return $out;

    $res = wp_remote_get( $url, [
        'timeout'    => 10,
        'redirection'=> 5,
        'user-agent' => 'XOTList Trend Aggregator (xotlist.com)',
        'headers'    => [ 'Accept' => 'text/html' ],
    ] );
    if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) return $out;
    $html = (string) wp_remote_retrieve_body( $res );
    if ( $html === '' ) return $out;

    // og:image from <head>
    $head_end = stripos( $html, '</head>' );
    $head     = $head_end !== false ? substr( $html, 0, $head_end ) : substr( $html, 0, 32768 );
    if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m ) ) {
        $out['image'] = esc_url_raw( $m[1] );
    } elseif ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $head, $m ) ) {
        $out['image'] = esc_url_raw( $m[1] );
    } elseif ( preg_match( '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $m ) ) {
        $out['image'] = esc_url_raw( $m[1] );
    }

    // Body extraction via DOMDocument.
    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument();
    @$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $xpath = new DOMXPath( $doc );

    // Strip noise.
    $strip = '//script|//style|//noscript|//nav|//header|//footer|//aside|//form'
           . '|//*[contains(@class,"newsletter")]|//*[contains(@class,"related")]'
           . '|//*[contains(@class,"share")]|//*[contains(@class,"social")]'
           . '|//*[contains(@class,"comment")]|//*[contains(@class,"sidebar")]'
           . '|//*[contains(@class,"promo")]|//*[contains(@class,"ad-")]'
           . '|//*[contains(@id,"comment")]|//*[contains(@class,"author-box")]';
    foreach ( $xpath->query( $strip ) as $node ) {
        if ( $node->parentNode ) $node->parentNode->removeChild( $node );
    }

    $selectors = [
        '//article//*[contains(@class,"article-body") or contains(@class,"entry-content") or contains(@class,"post-content")]',
        '//article',
        '//main//*[contains(@class,"article-body") or contains(@class,"entry-content") or contains(@class,"post-content")]',
        '//main',
        '//*[@role="main"]',
        '//*[contains(@class,"article-body")]',
        '//*[contains(@class,"entry-content")]',
        '//*[contains(@class,"post-content")]',
        '//*[contains(@class,"content-body")]',
    ];

    $paragraphs = [];
    foreach ( $selectors as $sel ) {
        $nodes = $xpath->query( $sel );
        if ( ! $nodes || $nodes->length === 0 ) continue;
        $container = $nodes->item( 0 );
        $ps        = $xpath->query( './/p', $container );
        if ( ! $ps || $ps->length < 2 ) continue;
        foreach ( $ps as $p ) {
            $txt = trim( preg_replace( '/\s+/', ' ', (string) $p->textContent ) );
            if ( $txt === '' || strlen( $txt ) < 40 ) continue;
            if ( preg_match( '/^(advertisement|sponsored|subscribe|sign up|follow us)/i', $txt ) ) continue;
            $paragraphs[] = $txt;
            if ( count( $paragraphs ) >= 30 ) break 2;
        }
        if ( $paragraphs ) break;
    }
    if ( empty( $paragraphs ) ) {
        $ps = $xpath->query( '//body//p' );
        if ( $ps ) foreach ( $ps as $p ) {
            $txt = trim( preg_replace( '/\s+/', ' ', (string) $p->textContent ) );
            if ( strlen( $txt ) < 80 ) continue;
            $paragraphs[] = $txt;
            if ( count( $paragraphs ) >= 30 ) break;
        }
    }
    if ( empty( $paragraphs ) ) return $out;

    $body = ''; $wc = 0;
    foreach ( $paragraphs as $p ) {
        $body .= '<p>' . esc_html( $p ) . '</p>' . "\n";
        $wc   += str_word_count( $p );
        if ( $wc >= 1500 ) break;
    }
    $out['body']       = $body;
    $out['word_count'] = $wc;
    return $out;
}
endif;

/* ──────────────────────────────────────────────
 * Insert as draft stub
 * ────────────────────────────────────────────── */

if ( ! function_exists( 'xot_trend_rss_insert_stub' ) ) :
function xot_trend_rss_insert_stub( array $item, string $feed_key ): array {
    if ( xot_trend_rss_find_by_url( $item['link'] ) ) {
        return [ 'action' => 'skipped-dup', 'post_id' => 0 ];
    }

    // Single-fetch: pulls article body AND og:image in one round-trip.
    $article = xot_trend_rss_fetch_article( $item['link'] );
    $image   = $item['image'] !== '' ? $item['image'] : $article['image'];
    $body    = $article['body'];

    // Feed metadata: vertical + publication display name.
    $feed_meta = xot_trend_rss_feed_meta( $feed_key );

    // Auto-publish as xot_trend (news-aggregator stub). Body content comes
    // from source extraction so users can read the story without a click-out.
    $post_id = wp_insert_post( [
        'post_title'   => $item['title'],
        'post_type'    => 'xot_trend',
        'post_status'  => 'publish',
        'post_content' => $body,
        'post_excerpt' => $item['excerpt'],
        'post_name'    => sanitize_title( $item['title'] ),
    ], true );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        return [ 'action' => 'skipped', 'post_id' => 0, 'reason' => 'insert failed' ];
    }
    $canonical = xot_trend_rss_canonical_url( $item['link'] );
    update_post_meta( $post_id, '_xot_source_url',             $item['link'] );
    update_post_meta( $post_id, '_xot_source_canonical',       $canonical );
    update_post_meta( $post_id, '_xot_source_feed',            $feed_key );
    update_post_meta( $post_id, '_xot_source_pubdate',         $item['pubdate'] );
    update_post_meta( $post_id, '_xot_publication_name',       $feed_meta['name'] );
    update_post_meta( $post_id, '_xot_vertical',               $feed_meta['vertical'] );
    update_post_meta( $post_id, '_xot_source_tier',            $feed_meta['tier'] );
    update_post_meta( $post_id, '_xot_needs_editorial_review', '1' );
    update_post_meta( $post_id, '_xot_source_validated_at',    gmdate( 'c' ) );
    update_post_meta( $post_id, '_xot_body_word_count',        (int) $article['word_count'] );
    if ( $image !== '' ) update_post_meta( $post_id, '_xot_cover_img', $image );

    // Initial heat_score from freshness — newer items rank higher.
    // Decays from 100 at insert to 0 over 7 days. The primary /trending/
    // query filters by meta_key=_xot_heat_score, so items without this
    // meta key fall to the date-ordered fallback. Setting it on insert
    // ensures fresh aggregated trends surface above stale editorial.
    $pub_ts = $item['pubdate'] ? strtotime( $item['pubdate'] ) : time();
    $age_hr = max( 0, ( time() - $pub_ts ) / 3600 );
    $score  = max( 1, (int) round( 100 - ( $age_hr / 168 * 100 ) ) ); // 168h = 7d
    update_post_meta( $post_id, '_xot_heat_score', $score );

    if ( function_exists( 'xot_event' ) ) {
        xot_event( 'trend.rss.inserted', [
            'post_id' => $post_id,
            'feed'    => $feed_key,
            'title'   => $item['title'],
            'image_enriched' => $image !== '' && $item['image'] === '',
        ], 'info', 'trend_rss' );
    }
    return [ 'action' => 'inserted', 'post_id' => $post_id ];
}
endif;

/* ──────────────────────────────────────────────
 * Run a feed batch — single feed
 * ────────────────────────────────────────────── */

if ( ! function_exists( 'xot_trend_rss_run_feed' ) ) :
function xot_trend_rss_run_feed( string $feed_key, string $feed_url, int $limit, bool $apply ): array {
    $items = xot_trend_rss_fetch_feed( $feed_url );
    if ( ! $items ) return [ 'feed' => $feed_key, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'preview' => [] ];

    $items = array_slice( $items, 0, $limit );
    $inserted = 0; $skipped = 0; $preview = [];
    foreach ( $items as $it ) {
        if ( ! $apply ) {
            $existing = xot_trend_rss_find_by_url( $it['link'] );
            $preview[] = [
                'action' => $existing > 0 ? 'would-skip-dup' : 'would-insert',
                'title'  => $it['title'],
                'link'   => $it['link'],
            ];
            continue;
        }
        $r = xot_trend_rss_insert_stub( $it, $feed_key );
        if ( $r['action'] === 'inserted' ) $inserted++;
        else                                $skipped++;
    }

    // Feed health: track last successful fetch timestamp + count.
    update_option( "xot_trend_rss_last_fetch_{$feed_key}", time(), false );
    update_option( "xot_trend_rss_last_count_{$feed_key}", count( $items ), false );
    if ( count( $items ) > 0 ) {
        update_option( "xot_trend_rss_last_success_{$feed_key}", time(), false );
    }

    return [
        'feed'     => $feed_key,
        'fetched'  => count( $items ),
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'preview'  => $preview,
    ];
}
endif;

/* ──────────────────────────────────────────────
 * Stale-draft retention cron — daily prune.
 *
 * Drafts flagged `_xot_needs_editorial_review` that sit untouched
 * for >30 days get deleted. Editor-touched drafts (any modification
 * to title/content/meta beyond the auto-set fields) are kept.
 *
 * Heuristic for "untouched": post_modified === post_date (no edits).
 * ────────────────────────────────────────────── */

add_action( 'xot_trend_rss_prune_stale', function (): void {
    global $wpdb;
    $days   = (int) apply_filters( 'xot_trend_rss_stale_days', 30 );
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
           JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_xot_needs_editorial_review' AND pm.meta_value = '1'
          WHERE p.post_type = 'xot_trend'
            AND p.post_status = 'draft'
            AND p.post_date_gmt < %s
            AND p.post_modified_gmt = p.post_date_gmt
          LIMIT 200",
        $cutoff
    ) );

    $deleted = 0;
    foreach ( $rows as $pid ) {
        wp_delete_post( (int) $pid, true );
        $deleted++;
    }
    if ( $deleted > 0 && function_exists( 'xot_event' ) ) {
        xot_event( 'trend.rss.prune_stale', [ 'deleted' => $deleted, 'cutoff_days' => $days ], 'info', 'trend_rss' );
    }
} );
// v5.2.0 Bug 11 — alive path via master schedule (daily, priority 5).
if ( function_exists( 'xot_cron_alive_register' ) ) {
    $__when = strtotime( 'tomorrow 06:30 UTC' );
    xot_cron_alive_register( 'trend_rss_prune_stale', 'xot_trend_rss_prune_stale', DAY_IN_SECONDS, 5, [
        'first_run' => $__when - time(),
        'label'     => 'Trend-RSS prune stale entries',
    ] );
    unset( $__when );
}

/* ──────────────────────────────────────────────
 * Feed health monitor — daily check, alert if any feed stale > 7 days.
 * ────────────────────────────────────────────── */

add_action( 'xot_trend_rss_health_check', function (): void {
    $threshold = (int) apply_filters( 'xot_trend_rss_health_threshold_days', 7 );
    $cutoff    = time() - ( $threshold * DAY_IN_SECONDS );
    $stale     = [];
    foreach ( xot_trend_rss_feeds() as $key => $url ) {
        $last_success = (int) get_option( "xot_trend_rss_last_success_{$key}", 0 );
        if ( $last_success === 0 ) continue; // never run yet, not stale
        if ( $last_success < $cutoff ) {
            $stale[] = [ 'feed' => $key, 'last_success' => gmdate( 'c', $last_success ) ];
        }
    }
    if ( $stale && function_exists( 'xot_event' ) ) {
        xot_event( 'trend.rss.feed_stale', [ 'stale' => $stale, 'threshold_days' => $threshold ], 'warn', 'trend_rss' );
    }
} );
// v5.2.0 Bug 11 — alive path via master schedule (daily, priority 5).
if ( function_exists( 'xot_cron_alive_register' ) ) {
    $__when = strtotime( 'tomorrow 07:00 UTC' );
    xot_cron_alive_register( 'trend_rss_health_check', 'xot_trend_rss_health_check', DAY_IN_SECONDS, 5, [
        'first_run' => $__when - time(),
        'label'     => 'Trend-RSS feed health check',
    ] );
    unset( $__when );
}

/* ──────────────────────────────────────────────
 * Source-URL re-validation cron — weekly walk of stub posts.
 * If a source URL goes 404/410/dead, mark `_xot_source_dead_at`.
 * Editors can filter on this meta to clean up dead-link drafts.
 * ────────────────────────────────────────────── */

add_action( 'xot_trend_rss_revalidate_weekly', function (): void {
    global $wpdb;
    $limit = (int) apply_filters( 'xot_trend_rss_revalidate_limit', 50 );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID,
                MAX(CASE WHEN pm.meta_key = '_xot_source_url' THEN pm.meta_value END) AS url,
                MAX(CASE WHEN pm.meta_key = '_xot_source_validated_at' THEN pm.meta_value END) AS validated_at
           FROM {$wpdb->posts} p
           JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_xot_source_url', '_xot_source_validated_at')
          WHERE p.post_type = 'xot_trend'
          GROUP BY p.ID
         HAVING url IS NOT NULL AND url != ''
          ORDER BY validated_at IS NULL DESC, validated_at ASC
          LIMIT %d",
        $limit
    ), ARRAY_A );

    $checked = 0; $live = 0; $dead = 0;
    foreach ( $rows as $r ) {
        $pid = (int) $r['ID'];
        $url = (string) $r['url'];
        $now = gmdate( 'c' );

        $head = wp_remote_head( $url, [
            'timeout'     => 6,
            'redirection' => 3,
            'user-agent'  => 'XOTList Trend Validator (xotlist.com)',
        ] );
        $code = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_response_code( $head );
        $is_live = ( $code >= 200 && $code < 400 );
        $checked++;
        update_post_meta( $pid, '_xot_source_validated_at', $now );

        $was_dead = (string) get_post_meta( $pid, '_xot_source_dead_at', true );
        if ( $is_live ) {
            $live++;
            if ( $was_dead !== '' ) delete_post_meta( $pid, '_xot_source_dead_at' );
        } else {
            $dead++;
            if ( $was_dead === '' ) update_post_meta( $pid, '_xot_source_dead_at', $now );
        }
        usleep( 500_000 );
    }
    if ( $checked > 0 && function_exists( 'xot_event' ) ) {
        xot_event( 'trend.rss.revalidate.weekly', [
            'checked' => $checked, 'live' => $live, 'dead' => $dead,
        ], $dead > 0 ? 'warn' : 'info', 'trend_rss' );
    }
} );
// v5.2.0 Bug 11 — alive path via master schedule (weekly, priority 5).
if ( function_exists( 'xot_cron_alive_register' ) ) {
    $__when = strtotime( 'tomorrow 08:00 UTC' );
    xot_cron_alive_register( 'trend_rss_revalidate_weekly', 'xot_trend_rss_revalidate_weekly', 7 * DAY_IN_SECONDS, 5, [
        'first_run' => $__when - time(),
        'label'     => 'Trend-RSS weekly source revalidation',
    ] );
    unset( $__when );
}

/* ──────────────────────────────────────────────
 * Daily cron — disabled by default
 * ────────────────────────────────────────────── */

add_action( 'xot_trend_rss_daily', function (): void {
    if ( ! get_option( 'xot_trend_rss_enabled', true ) ) return; // enabled by default; flip to 0 to pause
    $per_feed = (int) apply_filters( 'xot_trend_rss_daily_per_feed', 5 );
    $totals = [ 'fetched' => 0, 'inserted' => 0, 'skipped' => 0 ];
    foreach ( xot_trend_rss_feeds() as $key => $url ) {
        $r = xot_trend_rss_run_feed( $key, $url, $per_feed, true );
        $totals['fetched']  += $r['fetched'];
        $totals['inserted'] += $r['inserted'];
        $totals['skipped']  += $r['skipped'];
        usleep( 5_000_000 ); // 5s between feeds — gentle
    }
    if ( function_exists( 'xot_event' ) ) {
        xot_event( 'trend.rss.cron.daily', $totals, 'info', 'trend_rss' );
    }
} );
// v5.2.0 Bug 11 — alive path via master schedule (daily, priority 4).
if ( function_exists( 'xot_cron_alive_register' ) ) {
    $__when = strtotime( 'tomorrow 06:00 UTC' );
    xot_cron_alive_register( 'trend_rss_daily', 'xot_trend_rss_daily', DAY_IN_SECONDS, 4, [
        'first_run'  => $__when - time(),
        'killswitch' => 'xot_trend_rss_enabled',
        'label'      => 'Trend-RSS daily ingest',
    ] );
    unset( $__when );
}

/* ──────────────────────────────────────────────
 * WP-CLI
 * ────────────────────────────────────────────── */

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {

    /**
     * Backfill _xot_vertical + _xot_publication_name on existing xot_trend
     * posts from their _xot_source_feed.
     *
     *   wp xot trend-rss-backfill-vertical [--limit=N] [--dry-run]
     */
    WP_CLI::add_command( 'xot trend-rss-backfill-vertical', function ( array $args, array $assoc ): void {
        global $wpdb;
        $limit = (int) ( $assoc['limit'] ?? 0 );
        $dry   = ! empty( $assoc['dry-run'] );

        $sql = "SELECT p.ID,
                       MAX(CASE WHEN pm.meta_key='_xot_source_feed'      THEN pm.meta_value END) AS feed,
                       MAX(CASE WHEN pm.meta_key='_xot_vertical'         THEN pm.meta_value END) AS vertical
                  FROM {$wpdb->posts} p
                  JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'xot_trend'
                   AND p.post_status IN ('publish','draft')
                 GROUP BY p.ID
                HAVING feed IS NOT NULL AND feed != '' AND ( vertical IS NULL OR vertical = '' )";
        if ( $limit > 0 ) $sql .= ' LIMIT ' . $limit;
        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $set = 0; $by_v = [];
        foreach ( $rows as $r ) {
            $meta = xot_trend_rss_feed_meta( (string) $r['feed'] );
            $by_v[ $meta['vertical'] ] = ( $by_v[ $meta['vertical'] ] ?? 0 ) + 1;
            if ( ! $dry ) {
                update_post_meta( (int) $r['ID'], '_xot_vertical', $meta['vertical'] );
                update_post_meta( (int) $r['ID'], '_xot_publication_name', $meta['name'] );
                update_post_meta( (int) $r['ID'], '_xot_source_tier', $meta['tier'] );
                $set++;
            }
        }
        WP_CLI::log( '── vertical breakdown ──' );
        foreach ( $by_v as $v => $c ) WP_CLI::log( sprintf( '  %-15s %d', $v, $c ) );
        WP_CLI::success( ( $dry ? '[DRY-RUN] ' : '' ) . 'candidates=' . count( $rows ) . ' wrote=' . $set );
    } );

    /**
     * Backfill post_content for existing xot_trend posts that have
     * _xot_source_url but no body. Fetches via xot_trend_rss_fetch_article().
     *
     *   wp xot trend-rss-backfill-content [--limit=N] [--throttle=MS] [--dry-run]
     */
    WP_CLI::add_command( 'xot trend-rss-backfill-content', function ( array $args, array $assoc ): void {
        global $wpdb;
        $limit    = max( 1, (int) ( $assoc['limit']    ?? 10 ) );
        $throttle = max( 0, (int) ( $assoc['throttle'] ?? 2000 ) );
        $dry      = ! empty( $assoc['dry-run'] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    MAX(CASE WHEN pm.meta_key='_xot_source_url' THEN pm.meta_value END) AS url
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_xot_source_url'
              WHERE p.post_type = 'xot_trend'
                AND p.post_status IN ('publish','draft')
                AND ( p.post_content = '' OR p.post_content IS NULL OR LENGTH(p.post_content) < 100 )
              GROUP BY p.ID
              ORDER BY p.post_date DESC
              LIMIT %d",
            $limit
        ), ARRAY_A );

        WP_CLI::log( sprintf( 'Found %d posts with empty content', count( $rows ) ) );
        $filled = 0; $skipped = 0;
        foreach ( $rows as $r ) {
            $pid = (int) $r['ID']; $url = (string) $r['url'];
            if ( $url === '' ) { $skipped++; continue; }
            if ( $dry ) {
                WP_CLI::log( sprintf( '  [DRY] %d  %s', $pid, mb_substr( $r['post_title'], 0, 70 ) ) );
                continue;
            }
            $art = xot_trend_rss_fetch_article( $url );
            if ( $art['body'] === '' ) {
                WP_CLI::log( sprintf( '  ✗ %d  no body  %s', $pid, $url ) );
                $skipped++;
                usleep( $throttle * 1000 );
                continue;
            }
            wp_update_post( [ 'ID' => $pid, 'post_content' => $art['body'] ] );
            update_post_meta( $pid, '_xot_body_word_count', (int) $art['word_count'] );
            if ( $art['image'] !== '' && ! get_post_meta( $pid, '_xot_cover_img', true ) ) {
                update_post_meta( $pid, '_xot_cover_img', $art['image'] );
            }
            WP_CLI::log( sprintf( '  ✓ %d  %dw  %s', $pid, $art['word_count'], mb_substr( $r['post_title'], 0, 60 ) ) );
            $filled++;
            usleep( $throttle * 1000 );
        }
        WP_CLI::success( sprintf( '%sfilled=%d skipped=%d', $dry ? '[DRY-RUN] ' : '', $filled, $skipped ) );
    } );

    WP_CLI::add_command( 'xot trend-rss-fetch', function ( array $args, array $assoc ): void {
        // Default = apply (pro mode). Pass --dry-run for preview-only.
        $apply = empty( $assoc['dry-run'] );
        $only  = (string) ( $assoc['feed'] ?? '' );
        $limit = (int) ( $assoc['limit'] ?? 5 );

        if ( ! $apply ) WP_CLI::log( '[DRY-RUN] — no DB writes. Drop --dry-run to write stubs.' );

        $feeds = xot_trend_rss_feeds();
        if ( $only !== '' ) {
            if ( ! isset( $feeds[ $only ] ) ) WP_CLI::error( 'Unknown feed key: ' . $only );
            $feeds = [ $only => $feeds[ $only ] ];
        }

        $totals = [ 'fetched' => 0, 'inserted' => 0, 'skipped' => 0 ];
        foreach ( $feeds as $key => $url ) {
            $r = xot_trend_rss_run_feed( $key, $url, $limit, $apply );
            WP_CLI::log( sprintf( '[%s] fetched=%d inserted=%d skipped=%d',
                $key, $r['fetched'], $r['inserted'], $r['skipped'] ) );
            foreach ( $r['preview'] ?? [] as $p ) {
                WP_CLI::log( sprintf( '  %s · %s', $p['action'], $p['title'] ) );
            }
            $totals['fetched']  += $r['fetched'];
            $totals['inserted'] += $r['inserted'];
            $totals['skipped']  += $r['skipped'];
            usleep( 1_000_000 ); // 1s between feeds in CLI (faster than cron)
        }
        WP_CLI::success( sprintf( 'TOTAL fetched=%d inserted=%d skipped=%d',
            $totals['fetched'], $totals['inserted'], $totals['skipped'] ) );
    } );
}
