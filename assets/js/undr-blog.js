/* =========================================================================
   UNDR Core — shared blog JS.

   Progressive enhancement for blog post pages:
     - copy-to-clipboard share button: any element with [data-undr-copy]
       (optional data-undr-copy="<url>"; defaults to the current page URL).
       Briefly swaps its label to a "copied" state (data-undr-copied="Copied!").
     - native share button: [data-undr-share] uses the Web Share API when
       available, otherwise falls back to copying the link.

   First-party (published into each site's /assets/), so it runs under CSP
   'self'. No dependencies; safe to defer.
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

  document.addEventListener('click', function (e) {
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
