(() => {
  'use strict';

  // ---------------------------------------------------------------------------
  // UNDR shared tickets modal — loads a vendor purchase widget (e.g. rausgegangen
  // external-loader.js) and shows per-event header context + alternate links.
  // Reads the same window.UNDR_MODAL config object as undr-modal.js:
  //   inertMode ('siblings' | 'selectors'), inertSelectors, inertAriaHidden, onClose.
  // Defaults reproduce HEAT. In 'selectors' mode it also handles the nested case
  // (tickets opened over the info dossier): the dossier is inerted on open and,
  // on close, control is handed back to it while the chrome stays inert.
  //
  // Markup contract: #tickets-modal, #tickets-widget-slot, #tickets-alts
  // (+ .tickets-alts__list), [data-open-tickets], [data-tickets-loader],
  // [data-tickets-alts], [data-tix-name|date|venue]. A brand without a tickets
  // modal (no #tickets-modal) is a no-op.
  // ---------------------------------------------------------------------------
  const cfg = window.UNDR_MODAL || {};
  const INERT_MODE      = cfg.inertMode === 'selectors' ? 'selectors' : 'siblings';
  const INERT_SELECTORS = Array.isArray(cfg.inertSelectors) ? cfg.inertSelectors : [];
  const INERT_ARIA      = !!cfg.inertAriaHidden;
  const onClose         = typeof cfg.onClose === 'function' ? cfg.onClose : null;
  const INERT_ATTR      = 'data-undr-inert';

  const modal    = document.getElementById('tickets-modal');
  const slot     = document.getElementById('tickets-widget-slot');
  const altsRow  = document.getElementById('tickets-alts');
  const altsList = altsRow ? altsRow.querySelector('.tickets-alts__list') : null;
  if (!modal || !slot) return;

  const backdrop  = modal.querySelector('[data-modal-backdrop]');
  const closeBtns = modal.querySelectorAll('[data-modal-close]');
  const infoModal = document.getElementById('info-modal');

  let loadedSrc = null;
  let lastFocus = null;

  function setInertOne(el, on) {
    if (!el) return;
    if (on) { el.setAttribute('inert', ''); if (INERT_ARIA) el.setAttribute('aria-hidden', 'true'); }
    else { el.removeAttribute('inert'); if (INERT_ARIA) el.removeAttribute('aria-hidden'); }
  }

  function setBackgroundInert(on) {
    if (INERT_MODE === 'selectors') {
      INERT_SELECTORS.forEach((sel) => setInertOne(document.querySelector(sel), on));
      if (on) {
        setInertOne(infoModal, true); // dossier behind us (if open) goes inert
      } else {
        // Hand control back to the dossier if it's still open; keep chrome inert in that case.
        const dossierOpen = infoModal && !infoModal.hidden;
        setInertOne(infoModal, false);
        if (dossierOpen) INERT_SELECTORS.forEach((sel) => setInertOne(document.querySelector(sel), true));
      }
      return;
    }
    // 'siblings': inert every body child except the dialog(s); clear only when no
    // modal remains open (keeps the info dialog usable underneath).
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

  // Focus-trap: keep Tab cycling within the dialog (WCAG 2.4.3).
  const FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"]), input, select, textarea';
  function trapTab(e) {
    if (e.key !== 'Tab' || modal.hidden) return;
    const focusables = Array.from(modal.querySelectorAll(FOCUSABLE)).filter(el => el.offsetParent !== null);
    if (!focusables.length) return;
    const first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
    else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
  }

  function loadWidget(src) {
    if (!src || src === loadedSrc) return;
    slot.innerHTML = '';
    const s = document.createElement('script');
    s.src = src;
    s.id = 'purchase-widget-loader';
    s.setAttribute('data-layout', 'fullwidth');
    s.async = true;
    slot.appendChild(s);
    loadedSrc = src;
  }

  function open(src) {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('modal-open');
    setBackgroundInert(true);
    requestAnimationFrame(() => modal.classList.add('is-open'));
    loadWidget(src);
    const focusTarget = modal.querySelector('[data-modal-close]');
    if (focusTarget) focusTarget.focus();
  }

  function close() {
    const dialog = modal.querySelector('.modal__dialog');
    if (onClose && dialog) { try { onClose(dialog); } catch (_) {} }
    modal.classList.remove('is-open');
    document.body.classList.remove('modal-open');
    setBackgroundInert(false);
    setTimeout(() => { modal.hidden = true; }, 180);
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  // Per-trigger context: event name / date / venue + alternative ticket links.
  function applyHeader(trigger) {
    const set = (sel, val) => {
      const el = modal.querySelector(sel);
      if (el && val) el.textContent = val;
    };
    set('[data-tix-name]',  trigger.dataset.eventName);
    set('[data-tix-date]',  trigger.dataset.eventDate);
    set('[data-tix-venue]', trigger.dataset.eventVenue);
  }

  function applyAlts(trigger) {
    if (!altsRow || !altsList) return;
    altsList.innerHTML = '';
    let alts = [];
    try { alts = JSON.parse(trigger.dataset.ticketsAlts || '[]'); } catch (_) {}
    if (!alts.length) { altsRow.hidden = true; return; }
    alts.forEach((a, i) => {
      if (i > 0) {
        const sep = document.createElement('span');
        sep.className = 'tickets-alts__sep';
        sep.setAttribute('aria-hidden', 'true');
        sep.textContent = '·';
        altsList.appendChild(sep);
      }
      const link = document.createElement('a');
      link.href = a.url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.className = 'tickets-alts__link';
      link.textContent = a.label + ' ↗';
      altsList.appendChild(link);
    });
    altsRow.hidden = false;
  }

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-open-tickets]');
    if (!trigger) return;
    e.preventDefault();
    applyHeader(trigger);
    applyAlts(trigger);
    open(trigger.dataset.ticketsLoader || loadedSrc);
  });

  closeBtns.forEach(btn => btn.addEventListener('click', close));
  if (backdrop) backdrop.addEventListener('click', close);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) close();
    trapTab(e);
  });
})();
