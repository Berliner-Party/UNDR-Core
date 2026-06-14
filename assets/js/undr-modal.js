(() => {
  'use strict';

  // ---------------------------------------------------------------------------
  // UNDR shared event-info modal. One implementation, configured per brand via
  // a window.UNDR_MODAL object set BEFORE this script loads. Defaults reproduce
  // HEAT's original behaviour exactly; CAGE and UNLEASHED override the options
  // that genuinely differ.
  //
  //   window.UNDR_MODAL = {
  //     source:        'template' | 'data-src',   // <template id="event-tpl-ID"> | [data-event-src="ID"] .event-detail
  //     inertMode:     'siblings' | 'selectors',  // inert all body children except .modal | inert a fixed selector list
  //     inertSelectors: ['#main', …],             // used when inertMode === 'selectors'
  //     inertAriaHidden: false,                   // also set aria-hidden on inert targets (selectors mode)
  //     scrollLock:    false,                     // position:fixed body scroll-lock + restore on close
  //     ariaExpanded:  false,                     // toggle aria-expanded on the trigger button
  //     historyClose:  'push' | 'back',           // push an empty state | history.back() the open() entry
  //     promo:         true,                      // enable the flyer promo-video player
  //     onClose:       function(dialog) {}        // brand close effect (e.g. HEAT heat-burst)
  //   };
  //
  // Markup contract: #info-modal, #info-body, [data-modal-backdrop],
  // [data-modal-close], [data-open-info="ID"], .event-detail__name.
  // ---------------------------------------------------------------------------
  const cfg = window.UNDR_MODAL || {};
  const SOURCE          = cfg.source === 'data-src' ? 'data-src' : 'template';
  const INERT_MODE      = cfg.inertMode === 'selectors' ? 'selectors' : 'siblings';
  const INERT_SELECTORS = Array.isArray(cfg.inertSelectors) ? cfg.inertSelectors : [];
  const INERT_ARIA      = !!cfg.inertAriaHidden;
  const SCROLL_LOCK     = !!cfg.scrollLock;
  const ARIA_EXPANDED   = !!cfg.ariaExpanded;
  const HISTORY_BACK    = cfg.historyClose === 'back';
  const PROMO           = cfg.promo !== false;
  const onClose         = typeof cfg.onClose === 'function' ? cfg.onClose : null;
  const INERT_ATTR      = 'data-undr-inert';

  const modal = document.getElementById('info-modal');
  const body  = document.getElementById('info-body');
  if (!modal || !body) return;

  const backdrop  = modal.querySelector('[data-modal-backdrop]');
  const closeBtns = modal.querySelectorAll('[data-modal-close]');

  let lastFocus    = null;
  let loadedId     = null;
  let scrollY      = 0;
  let pushedHistory = false;   // did open() add a history entry to pop on close? (history-back mode)
  let isClosing    = false;    // guard the popstate fired by our own history.back()
  let lastTrigger  = null;     // the button that opened the dialog (aria-expanded mode)

  // ---- Background inert -----------------------------------------------------
  function setBackgroundInert(on) {
    if (INERT_MODE === 'selectors') {
      INERT_SELECTORS.forEach((sel) => {
        const el = document.querySelector(sel);
        if (!el) return;
        if (on) { el.setAttribute('inert', ''); if (INERT_ARIA) el.setAttribute('aria-hidden', 'true'); }
        else { el.removeAttribute('inert'); if (INERT_ARIA) el.removeAttribute('aria-hidden'); }
      });
      return;
    }
    // 'siblings': inert every body child except the dialog(s); only clear once no
    // modal is open, so stacking the tickets dialog over this one stays safe.
    if (on) {
      Array.from(document.body.children).forEach((el) => {
        if (el.classList && el.classList.contains('modal')) return;
        if (!el.hasAttribute('inert')) { el.setAttribute('inert', ''); el.setAttribute(INERT_ATTR, '1'); }
      });
    } else if (!document.querySelector('.modal.is-open')) {
      document.querySelectorAll('[' + INERT_ATTR + ']').forEach((el) => {
        el.removeAttribute('inert'); el.removeAttribute(INERT_ATTR);
      });
    }
  }

  // ---- Focus trap (WCAG 2.4.3) ----------------------------------------------
  const FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"]), input, select, textarea';
  function trapTab(e) {
    if (e.key !== 'Tab' || modal.hidden) return;
    const focusables = Array.from(modal.querySelectorAll(FOCUSABLE)).filter(el => el.offsetParent !== null);
    if (!focusables.length) return;
    const first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
    else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
  }

  // ---- Event content source -------------------------------------------------
  function cloneEvent(eventId) {
    if (SOURCE === 'data-src') {
      const src = document.querySelector('[data-event-src="' + eventId + '"] .event-detail');
      return src ? src.cloneNode(true) : null;
    }
    const tpl = document.getElementById('event-tpl-' + eventId);
    return tpl ? tpl.content.cloneNode(true) : null;
  }

  function open(eventId) {
    const node = cloneEvent(eventId);
    if (!node) return;

    if (loadedId !== eventId) {
      body.innerHTML = '';
      body.appendChild(node);
      body.scrollTop = 0;
      loadedId = eventId;
    }

    // Wire the modal's accessible name to the cloned event heading.
    const heading = body.querySelector('.event-detail__name');
    if (heading) {
      if (!heading.id) heading.id = 'info-modal-title-' + eventId;
      modal.setAttribute('aria-labelledby', heading.id);
      modal.removeAttribute('aria-label');
    }

    lastFocus = document.activeElement;
    modal.hidden = false;
    if (SCROLL_LOCK) { scrollY = window.scrollY; document.body.style.top = (-scrollY) + 'px'; }
    document.body.classList.add('modal-open');
    setBackgroundInert(true);
    requestAnimationFrame(() => modal.classList.add('is-open'));
    const focusTarget = modal.querySelector('[data-modal-close]');
    if (focusTarget) focusTarget.focus();

    // Update URL hash so the modal state is shareable / back-button friendly.
    if (location.hash !== '#event=' + eventId) {
      history.pushState({ infoEvent: eventId }, '', '#event=' + eventId);
      pushedHistory = true;
    }
  }

  function close({ updateHistory = true } = {}) {
    if (isClosing) return;                          // re-entrancy guard (our own history.back)
    isClosing = true;
    if (PROMO) body.querySelectorAll('.event-detail__hero-flyer.is-playing').forEach(stopPromo);
    const dialog = modal.querySelector('.modal__dialog');
    if (onClose && dialog) { try { onClose(dialog); } catch (_) {} }
    modal.classList.remove('is-open');
    document.body.classList.remove('modal-open');
    if (SCROLL_LOCK) { document.body.style.top = ''; window.scrollTo(0, scrollY); }
    setBackgroundInert(false);
    setTimeout(() => { modal.hidden = true; isClosing = false; }, 180);
    if (ARIA_EXPANDED && lastTrigger) { lastTrigger.setAttribute('aria-expanded', 'false'); lastTrigger = null; }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus(SCROLL_LOCK ? { preventScroll: true } : undefined);
    }
    if (updateHistory && location.hash.startsWith('#event=')) {
      if (HISTORY_BACK) {
        if (pushedHistory) { pushedHistory = false; history.back(); }
        else history.replaceState({}, '', location.pathname + location.search);
      } else {
        history.pushState({}, '', location.pathname + location.search);
      }
    }
  }

  // Delegate clicks so any "More Info" trigger anywhere on the page opens the modal.
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-open-info]');
    if (!trigger) return;
    e.preventDefault();
    if (ARIA_EXPANDED) { lastTrigger = trigger; trigger.setAttribute('aria-expanded', 'true'); }
    open(trigger.dataset.openInfo);
  });

  // ---- Promo video on the flyer hero (created on demand) --------------------
  function playPromo(figure, src) {
    if (!figure || !src) return;
    if (figure.querySelector('.hero-flyer__video')) return;
    const video = document.createElement('video');
    video.className = 'hero-flyer__video';
    video.src = src;
    video.controls = true;
    video.playsInline = true;
    video.autoplay = true;
    video.preload = 'auto';
    video.addEventListener('ended', () => stopPromo(figure));
    figure.classList.add('is-playing');
    figure.appendChild(video);
    video.focus();
  }
  function stopPromo(figure) {
    const v = figure.querySelector('.hero-flyer__video');
    if (v) { try { v.pause(); } catch (_) {} v.remove(); }
    figure.classList.remove('is-playing');
    const play = figure.querySelector('[data-play-promo]');
    if (play) play.focus();
  }
  if (PROMO) {
    body.addEventListener('click', (e) => {
      const trigger = e.target.closest('[data-play-promo]');
      if (!trigger) return;
      e.preventDefault();
      playPromo(trigger.closest('.event-detail__hero-flyer'), trigger.dataset.playPromo);
    });
  }

  closeBtns.forEach(btn => btn.addEventListener('click', () => close()));
  if (backdrop) backdrop.addEventListener('click', () => close());
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) {
      // If the tickets dialog is stacked on top, let it handle Escape so only the
      // topmost dialog closes (order-independent).
      const tx = document.getElementById('tickets-modal');
      if (!tx || tx.hidden) close();
    }
    trapTab(e);
  });

  // Restore from hash on load so deep-links work (e.g. /#event=heat-2026-05-16).
  function maybeOpenFromHash() {
    const m = /^#event=(.+)$/.exec(location.hash);
    if (m) open(decodeURIComponent(m[1]));
    else if (!modal.hidden) close({ updateHistory: false });
  }
  window.addEventListener('popstate', maybeOpenFromHash);
  if (location.hash.startsWith('#event=')) maybeOpenFromHash();
})();
