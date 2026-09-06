# XOTLIST Feed Engine

Four content-ingestion modules from [XOTLIST](https://xotlist.com), an entertainment and
culture platform covering music, film, streaming, sports and fashion. They run on WordPress
cron and keep the site populated automatically from public media APIs and publication feeds.

Extracted from a production system for reference. See [Running this](#running-this) —
these files depend on the parent theme and are published to be read, not dropped into a site.

---

## The modules

### `xot-trend-rss.php` — fashion trend aggregator

Pulls fresh items from Business of Fashion, Hypebeast, Vogue Runway, Dazed, i-D,
Highsnobiety and W Magazine into `xot_trend` draft posts.

**The important design decision:** it does not auto-publish. Every item lands as a *draft*
stub — title, canonical link, first paragraph, featured image — flagged
`_xot_needs_editorial_review` so a human writes the actual editorial before anything goes
live. Aggregators that auto-publish machine-written summaries produce filler and bury the
original publisher; this one is built so a person always stands between the feed and the
reader.

Two further safety defaults:

- **Dry-run by default.** It reports what it would create until you say otherwise.
- **Cron off by default**, gated behind the `xot_trend_rss_enabled` option — the schedule
  has to be opted into deliberately.

### `xot-streaming-sync.php` — TMDB streaming ingest

Builds the "Streaming Now" catalogue from The Movie Database:

- `/movie/popular`, `/tv/popular`, `/tv/top_rated`
- `/discover/*` filtered by watch provider — Netflix, Prime, Disney+, HBO Max, Apple TV+,
  Paramount+, Hulu, Peacock
- A dedicated African catalogue drawn from Nigerian, Ghanaian and South African titles
  released in the last 365 days

Each title becomes an `xot_stream_show` post carrying release status, managing-engine flag
and a resolved service label (`NETFLIX`, `SHOWMAX`, `DSTV`, …) via TMDB's
`/watch/providers` endpoint, cached for 7 days.

### `xot-podcast-search.php` — search endpoint

A REST route at `GET /wp-json/xot/v1/podcast-search?q=…&limit=12` returning a merged list:
local episodes from full-text search across the `xot_podcast` type, plus live show results
from the iTunes Podcast Search API. Two cache layers — 1 hour per iTunes query, 5 minutes
per merged response.

### `xot-chart-artwork.php` — chart artwork enrichment

Backfills missing artwork on chart tracks before a visitor ever loads the page, running
inside the chart-fetch cron path rather than on request.

Resolution runs as a fallback chain, taking the first hit:

1. Deezer track search → album cover art
2. Artist resolver → Deezer artist photo, then iTunes, then internal celebrity records

Results cache on an artist + title fingerprint with **asymmetric TTLs — 7 days on a hit,
6 hours on a miss** — so successful lookups stay cheap while failures get retried sooner in
case the source catalogue catches up.

---

## Notes on the approach

**No API keys.** Every source here is a keyless public endpoint (Deezer, iTunes, TMDB's
public read routes, plain RSS). Nothing in these files reads a credential, which is why they
can be published as-is.

**Cache aggressively, fail soft.** Every outbound call is cached in a transient and every
fallback chain terminates in a usable default. A dead upstream degrades the page; it never
breaks it.

**Work happens on cron, not on request.** Ingest, artwork resolution and provider lookups
all run ahead of time so a page load is a database read.

## Running this

These are extracts from a live theme. They call into helpers that are not in this
repository — an artist resolver, chart fetchers, shared cache utilities — so the code will
not execute standalone. It is published so the approach can be read and reviewed, which is
what a five-minute look at a portfolio actually needs.

## Licence

© William Ekuadzi. Published for review and demonstration. All rights reserved — not
licensed for reuse or redistribution.
