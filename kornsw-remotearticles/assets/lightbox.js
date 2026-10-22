/*
 * KornSW RemoteArticles - lightbox for images and videos of embedded posts.
 *
 * Replaces the scripts of the source (which are removed for security reasons, see doc/security.md). Reacts only to links
 * the server marked with data-kornsw-ra-media inside embedded content; their URLs were validated server-side.
 * Never uses innerHTML.
 */
(function () {
  'use strict';

  var MEDIA_LINK_SELECTOR = '.kornsw-ra-content a[data-kornsw-ra-media]';

  var overlay = null;
  var mediaContainer = null;
  var counter = null;
  var closeButton = null;
  var previousButton = null;
  var nextButton = null;
  var items = [];
  var currentIndex = 0;
  var focusBeforeOpening = null;

  /**
   * Creates a button of the lightbox.
   */
  function createButton(className, label, text, onClick) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'kornsw-ra-lightbox-button ' + className;
    button.setAttribute('aria-label', label);
    button.textContent = text;
    button.addEventListener('click', function (event) {
      event.stopPropagation();
      onClick();
    });
    return button;
  }

  /**
   * Creates the overlay once.
   */
  function ensureOverlay() {
    if (overlay !== null) {
      return;
    }
    overlay = document.createElement('div');
    overlay.className = 'kornsw-ra-lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Bildansicht');
    overlay.hidden = true;

    mediaContainer = document.createElement('div');
    counter = document.createElement('div');
    counter.className = 'kornsw-ra-lightbox-counter';
    closeButton = createButton('kornsw-ra-lightbox-close', 'Schließen', '×', close);
    previousButton = createButton('kornsw-ra-lightbox-previous', 'Vorheriges', '‹', showPrevious);
    nextButton = createButton('kornsw-ra-lightbox-next', 'Nächstes', '›', showNext);

    overlay.appendChild(mediaContainer);
    overlay.appendChild(closeButton);
    overlay.appendChild(previousButton);
    overlay.appendChild(nextButton);
    overlay.appendChild(counter);
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) {
        close();
      }
    });
    document.body.appendChild(overlay);
  }

  /**
   * Shows the item with the given index.
   */
  function show(index) {
    currentIndex = (index + items.length) % items.length;
    var item = items[currentIndex];

    while (mediaContainer.firstChild !== null) {
      mediaContainer.removeChild(mediaContainer.firstChild);
    }

    var media;
    if (item.type === 'video') {
      media = document.createElement('video');
      media.controls = true;
      media.autoplay = true;
      media.playsInline = true;
    } else {
      media = document.createElement('img');
      media.alt = item.alt;
    }
    media.className = 'kornsw-ra-lightbox-media';
    media.src = item.url;
    mediaContainer.appendChild(media);

    var hasSeveralItems = items.length > 1;
    previousButton.hidden = !hasSeveralItems;
    nextButton.hidden = !hasSeveralItems;
    if (hasSeveralItems) {
      counter.textContent = (currentIndex + 1) + ' / ' + items.length;
    } else {
      counter.textContent = '';
    }
  }

  function showPrevious() {
    show(currentIndex - 1);
  }

  function showNext() {
    show(currentIndex + 1);
  }

  /**
   * Opens the lightbox with all media links of the embedded post that contains the clicked link.
   */
  function open(clickedLink) {
    ensureOverlay();
    var content = clickedLink.closest('.kornsw-ra-content');
    var links = Array.prototype.slice.call(content.querySelectorAll('a[data-kornsw-ra-media]'));

    items = links.map(function (link) {
      var image = link.querySelector('img');
      var alt = '';
      if (image !== null) {
        alt = image.getAttribute('alt') || '';
      }
      return {
        url: link.href,
        type: link.getAttribute('data-kornsw-ra-media'),
        alt: alt
      };
    });

    focusBeforeOpening = document.activeElement;
    overlay.hidden = false;
    document.addEventListener('keydown', onKeyDown);
    show(links.indexOf(clickedLink));
    closeButton.focus();
  }

  /**
   * Closes the lightbox and returns the focus to the link that opened it.
   */
  function close() {
    if (overlay === null || overlay.hidden) {
      return;
    }
    overlay.hidden = true;
    while (mediaContainer.firstChild !== null) {
      mediaContainer.removeChild(mediaContainer.firstChild);
    }
    document.removeEventListener('keydown', onKeyDown);
    if (focusBeforeOpening !== null && typeof focusBeforeOpening.focus === 'function') {
      focusBeforeOpening.focus();
    }
  }

  function onKeyDown(event) {
    if (event.key === 'Escape') {
      close();
    } else if (event.key === 'ArrowLeft') {
      showPrevious();
    } else if (event.key === 'ArrowRight') {
      showNext();
    }
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
      return;
    }
    var link = event.target.closest(MEDIA_LINK_SELECTOR);
    if (link === null) {
      return;
    }
    event.preventDefault();
    open(link);
  });
})();
