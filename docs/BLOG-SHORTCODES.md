# Blog shortcodes

Shortcodes are bracket tokens you write in a blog post's **Markdown** (in the UNDR
portal). The backend passes them through untouched; each brand site expands them at
render time via `blog_render_body($post)` from `undr/core`. They also behave sensibly
in the RSS feed (absolute URLs, real markup) and in JSON-LD `articleBody` (plain-text
projections — no button labels, no raw HTML).

## Ground rules

- Put a shortcode **on its own line** (own paragraph) to get its block form — card,
  figure, grid, facade. Written **inline** in a sentence it degrades to a plain text
  link instead (a block inside a paragraph would be invalid HTML).
- A token that can't be resolved (unknown event, empty payload, bad URL) renders
  **nothing** — raw `[tokens]` never leak to readers.
- Payload fields are separated by `|`. A payload must **never contain `](`** (the
  Markdown link syntax would swallow it) and should avoid `*` / `_` pairs surrounded
  by spaces (Markdown emphasis).
- Image/link URLs must be **absolute** (`https://…`) or **site-rooted** (`/media/…`,
  `/assets/…`). There is no per-post body-image upload — host body images on
  undr.zone or in the site's own assets. Note **CAGE's CSP only allows images from
  itself and undr.zone**; use undr.zone URLs for anything cross-brand.

## `[event: …]` — event card

```
[event:cage-2026-07-10]     any brand's event: <brand>-<YYYY-MM-DD>
[event:2026-07-10]          date only → this site's own brand
```

Renders a card with flyer, brand kicker, name, localized date/doors/venue line, short
description and two buttons:

- **Buy Tickets** — the event's primary ticket link. On the event's own site, if the
  brand exposes a rausgegangen widget (`primary_ticket_link()` returns a
  `loader`/`widget` URL) and the page carries the tickets-modal markup, it opens the
  shared **tickets modal**; otherwise it opens the ticket shop in a new tab.
- **More Info** — deep-links `<site>/<lang>/#event=<id>`, which opens the event modal
  on arrival — including on **another brand's site**, keeping the current page's
  language when that site speaks it.

Cross-brand references sync automatically: the site's cron pulls referenced foreign
events into `.cache/undr/linked-events.<lang>.json` (plus `brands.json`). Past events
render an informational card (greyed flyer, "This event has taken place.") without
buttons. Inline form renders "Name — date" as a link.

## `[image: url | alt | caption]` — figure

```
[image: https://undr.zone/media/unleashed/blog/my-post/crowd.jpg | Crowd at 3 AM | Peak time on the main floor]
```

A full-width `<figure>` (lazy-loaded) with optional caption; clicking opens the
lightbox. Alt and caption are optional but alt is strongly recommended
(accessibility + it becomes the articleBody text).

## `[gallery: url :: alt | url :: alt | …]` — image grid + lightbox

```
[gallery: https://undr.zone/media/a.jpg :: Opening | https://undr.zone/media/b.jpg :: Main floor | https://undr.zone/media/c.jpg]
```

A 3-column grid (2 on phones) of square thumbnails; clicking opens the shared
lightbox with prev/next (arrow keys work, Esc closes). Per-image alt goes after `::`.
Block-only — inline galleries render nothing. Long payloads may wrap across lines;
that's fine.

## `[video: youtube-url-or-id | title]` — GDPR click-to-load video

```
[video: https://www.youtube.com/watch?v=dQw4w9WgXcQ | HEAT Aftermovie 2026]
[video: dQw4w9WgXcQ | HEAT Aftermovie 2026]
```

Renders a 16:9 facade (play glyph + title) that makes **zero third-party requests**
until clicked — then it swaps in a `youtube-nocookie.com` iframe. Without JavaScript
(and in feed readers) it's a plain link to YouTube. Accepts watch / youtu.be /
shorts / embed URLs or the bare 11-char ID. Sites with a strict CSP need
`frame-src https://www.youtube-nocookie.com` (HEAT and CAGE ship it).

## `[button: url | label]` — CTA button

```
[button: /buy-tickets/ | Get your ticket]
[button: https://ra.co/events/123456 | RSVP on RA]
```

An accent-styled call-to-action. External URLs open in a new tab; site-relative ones
stay in-tab (and absolutize automatically in the RSS feed).

## `[quote: text | attribution]` — pull-quote

```
[quote: The best night of my life. | A very happy guest]
```

A styled editorial pull-quote with optional attribution. (Plain Markdown `>` quotes
still work for ordinary quoting; this one is the loud version.)

## `[map: address or venue | label]` — map link

```
[map: Dircksenstraße 114, 10178 Berlin | Find AMT]
```

A pin-styled link to Google Maps (link-out only — no embedded map, no third-party
requests, GDPR-clean). Label defaults to "Open map" / "Karte öffnen".

## `[html] … [/html]` — raw HTML escape hatch

```
[html]
<div class="my-embed">
  <iframe src="https://example.com/player" loading="lazy"></iframe>
</div>
[/html]
```

Everything between the markers is emitted **verbatim** on the site (taken from the
post's Markdown source — the sanitizer never touches it). Trusted-author feature: you
can inject anything, so treat it like editing the site itself. Remember the brand
CSPs still apply (inline `<script>` is blocked on HEAT/CAGE; iframes need a
`frame-src` entry). Stripped entirely from JSON-LD articleBody; kept in the RSS feed.

## Label overrides (per brand, optional)

The built-in en/de button labels can be overridden in a brand's `public/lang/*.php`
catalogs: `blog_event_tickets`, `blog_event_more_info`, `blog_event_past`,
`blog_video_play`, `blog_map_open`.

## Site integration (one-time, already done for HEAT/CAGE/UNLEASHED)

- Post template outputs `<?= blog_render_body($post) ?>` (not raw `bodyHtml`).
- Post pages link `undr-blog.css` and load `undr-blog.js` (lightbox + video facade).
- For the tickets modal on event cards: post pages include the `#tickets-modal`
  markup + `undr-modal.js`/`undr-tickets.js`/`window.UNDR_MODAL` (see
  SITE-DEVELOPMENT.md §6) and the brand's `primary_ticket_link()` returns the
  rausgegangen `widget`/`loader` URL.
