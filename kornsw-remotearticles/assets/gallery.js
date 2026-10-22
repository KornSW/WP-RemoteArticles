/*
 * KornSW RemoteArticles - loads galleries of embedded posts (e.g. Share-one-Drive) asynchronously.
 *
 * Placeholders carry a signed URL of the plugin's own REST endpoint. The endpoint returns data only (validated URLs,
 * titles, sizes); this script builds the gallery with DOM methods (never innerHTML). The items are links marked with
 * data-kornsw-ra-media, so the plugin's lightbox opens them.
 */
(function () {
  'use strict';

  var PLACEHOLDER_SELECTOR = '[data-kornsw-ra-gallery]';

  /**
   * Accepts only absolute https URLs (the server validated them already; this is a second line of defense).
   */
  function isHttpsUrl(value) {
    return typeof value === 'string' && value.indexOf('https://') === 0;
  }

  /**
   * Creates one gallery item (a link with a preview image).
   */
  function createItem(item, targetHeight) {
    var width = Math.max(1, parseInt(item.width, 10) || 1);
    var height = Math.max(1, parseInt(item.height, 10) || 1);
    var ratio = width / height;

    var link = document.createElement('a');
    link.className = 'kornsw-ra-gallery-item';
    if (item.type === 'video') {
      link.className += ' kornsw-ra-gallery-video';
      link.setAttribute('data-kornsw-ra-media', 'video');
    } else {
      link.setAttribute('data-kornsw-ra-media', 'image');
    }
    link.href = item.url;
    link.rel = 'noreferrer';
    link.referrerPolicy = 'no-referrer';
    link.title = item.title || '';
    // Justified rows: items grow with their aspect ratio, the base width matches the target row height.
    link.style.flexGrow = String(ratio);
    link.style.flexBasis = Math.round(ratio * targetHeight) + 'px';

    var image = document.createElement('img');
    image.src = item.thumbnail;
    if (isHttpsUrl(item.thumbnailRetina)) {
      image.srcset = item.thumbnail + ' 1x, ' + item.thumbnailRetina + ' 2x';
    }
    image.alt = item.title || '';
    image.width = width;
    image.height = height;
    image.loading = 'lazy';
    image.decoding = 'async';
    image.referrerPolicy = 'no-referrer';
    link.appendChild(image);
    return link;
  }

  /**
   * Loads and renders one placeholder.
   */
  function loadGallery(placeholder) {
    var url = placeholder.getAttribute('data-kornsw-ra-gallery');
    placeholder.removeAttribute('data-kornsw-ra-gallery');

    fetch(url, { credentials: 'omit' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        var targetHeight = parseInt(data.height, 10) || 300;
        var items = Array.isArray(data.items) ? data.items : [];
        items.forEach(function (item) {
          if (isHttpsUrl(item.url) && isHttpsUrl(item.thumbnail)) {
            placeholder.appendChild(createItem(item, targetHeight));
          }
        });
        placeholder.classList.remove('kornsw-ra-gallery-loading');
        if (!placeholder.firstChild) {
          placeholder.remove();
        }
      })
      .catch(function () {
        placeholder.remove();
      });
  }

  function loadAll() {
    Array.prototype.forEach.call(document.querySelectorAll(PLACEHOLDER_SELECTOR), loadGallery);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadAll);
  } else {
    loadAll();
  }
})();
