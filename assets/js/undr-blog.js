/* =========================================================================
   UNDR Core — shared blog JS.

   Progressive enhancement for blog post pages:
     - copy-to-clipboard share button: any element with [data-undr-copy]
       (optional data-undr-copy="<url>"; defaults to the current page URL).
       Briefly swaps its label to a "copied" state (data-undr-copied="Copied!").
     - native share button: [data-undr-share] uses the Web Share API when
       available, otherwise falls back to copying the link.
     - shortcode lightbox: [data-undr-lightbox="<group>"] anchors (the [image]
       and [gallery] shortcodes) open a native <dialog> viewer — prev/next
       within the group, arrow keys, Esc via the dialog itself.
     - shortcode video facade: [data-undr-video="<youtube-id>"] anchors (the
       [video] shortcode) swap themselves for a youtube-nocookie iframe on
       click — zero third-party requests until the visitor opts in.

   First-party (published into each site's /assets/), so it runs under CSP
   'self'. No dependencies; safe to defer. Everything degrades to plain links
   without JS.
   ========================================================================= */
(function () {
  'use strict';

  function flash(el, msg) {
    if (el.dataset.undrBusy) return;
    el.dataset.undrBusy = '1';
    var original = el.getAttribute('data-undr-label') || el.textContent;
    el.setAttribute('data-undr-label', original);
    el.textContent = msg;
    setTimeout(function () {
      el.textContent = el.getAttribute('data-undr-label') || original;
      delete el.dataset.undrBusy;
    }, 1600);
  }

  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        resolve();
      } catch (e) { reject(e); }
    });
  }

  // ---- Lightbox for [image] / [gallery] shortcodes --------------------------
  var lightbox = null;   // the singleton <dialog>, created on first use
  var lbItems  = [];     // anchors of the open group
  var lbIndex  = 0;
  var lbOpener = null;   // anchor to restore focus to on close

  function buildLightbox() {
    if (lightbox) return lightbox;
    lightbox = document.createElement('dialog');
    lightbox.className = 'undr-lightbox';
    lightbox.innerHTML =
      '<button type="button" class="undr-lightbox__close" data-lb-close aria-label="Close">×</button>' +
      '<button type="button" class="undr-lightbox__nav undr-lightbox__nav--prev" data-lb-prev aria-label="Previous">‹</button>' +
      '<figure class="undr-lightbox__stage"><img alt=""><figcaption class="undr-lightbox__caption"></figcaption></figure>' +
      '<button type="button" class="undr-lightbox__nav undr-lightbox__nav--next" data-lb-next aria-label="Next">›</button>';
    document.body.appendChild(lightbox);

    lightbox.addEventListener('click', function (e) {
      if (e.target.closest('[data-lb-close]')) { lightbox.close(); return; }
      if (e.target.closest('[data-lb-prev]')) { lbShow(lbIndex - 1); return; }
      if (e.target.closest('[data-lb-next]')) { lbShow(lbIndex + 1); return; }
      if (e.target === lightbox) lightbox.close(); // backdrop click
    });
    lightbox.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft')  { e.preventDefault(); lbShow(lbIndex - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); lbShow(lbIndex + 1); }
    });
    lightbox.addEventListener('close', function () {
      lightbox.querySelector('img').src = '';
      if (lbOpener && typeof lbOpener.focus === 'function') lbOpener.focus();
    });
    return lightbox;
  }

  function lbShow(i) {
    if (!lbItems.length) return;
    lbIndex = (i + lbItems.length) % lbItems.length; // wrap around
    var a   = lbItems[lbIndex];
    var img = lightbox.querySelector('img');
    var cap = lightbox.querySelector('.undr-lightbox__caption');
    var src = a.getAttribute('href');
    var alt = (a.querySelector('img') && a.querySelector('img').alt) || '';
    img.src = src;
    img.alt = alt;
    cap.textContent = alt;
    cap.hidden = alt === '';
    var multi = lbItems.length > 1;
    lightbox.querySelector('[data-lb-prev]').hidden = !multi;
    lightbox.querySelector('[data-lb-next]').hidden = !multi;
  }

  // ---- Click-to-load video facade for the [video] shortcode -----------------
  function playVideo(facade) {
    var id  = facade.getAttribute('data-undr-video');
    var box = facade.closest('.undr-video') || facade.parentNode;
    var iframe = document.createElement('iframe');
    iframe.className = 'undr-video__iframe';
    iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1';
    iframe.title = facade.getAttribute('data-undr-video-title') || 'Video';
    iframe.setAttribute('allow', 'autoplay; fullscreen; encrypted-media; picture-in-picture');
    iframe.setAttribute('referrerpolicy', 'no-referrer');
    box.classList.add('is-playing');
    box.textContent = '';
    box.appendChild(iframe);
    iframe.focus();
  }

  document.addEventListener('click', function (e) {
    var lbLink = e.target.closest('[data-undr-lightbox]');
    if (lbLink) {
      // No <dialog> support → let the link navigate to the image itself.
      if (typeof HTMLDialogElement !== 'function') return;
      e.preventDefault();
      var group = lbLink.getAttribute('data-undr-lightbox');
      lbItems  = Array.prototype.slice.call(
        document.querySelectorAll('[data-undr-lightbox="' + group.replace(/"/g, '\\"') + '"]')
      );
      lbOpener = lbLink;
      buildLightbox();
      lbShow(lbItems.indexOf(lbLink));
      lightbox.showModal();
      return;
    }

    var facade = e.target.closest('[data-undr-video]');
    if (facade) {
      e.preventDefault();
      playVideo(facade);
      return;
    }

    var copyBtn = e.target.closest('[data-undr-copy]');
    if (copyBtn) {
      e.preventDefault();
      var url = copyBtn.getAttribute('data-undr-copy') || window.location.href;
      copy(url).then(function () {
        flash(copyBtn, copyBtn.getAttribute('data-undr-copied') || 'Copied!');
      }).catch(function () {
        flash(copyBtn, 'Copy failed');
      });
      return;
    }

    var shareBtn = e.target.closest('[data-undr-share]');
    if (shareBtn) {
      var url2 = shareBtn.getAttribute('data-undr-share') || window.location.href;
      var title = document.title || '';
      if (navigator.share) {
        e.preventDefault();
        navigator.share({ title: title, url: url2 }).catch(function () {});
      } else {
        e.preventDefault();
        copy(url2).then(function () {
          flash(shareBtn, shareBtn.getAttribute('data-undr-copied') || 'Link copied!');
        }).catch(function () {});
      }
    }
  });
})();
