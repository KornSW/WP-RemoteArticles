<?php

declare(strict_types=1);

use KornSW\RemoteArticles\Embedding\LinkMode;

// Functional tests of the embedding pipeline: legitimate content must survive.

// --- KornSW AutoGallery markup (shortened, structure as rendered by the plugin) ---
$galleryHtml = '<div id="tkag-abc" class="tkag-gallery" style="--tkag-gap:10px;--tkag-mobile-row-height:150px;display:flex!important">'
  . '<style>#tkag-abc *{box-sizing:border-box!important;}@media(max-width:700px){#tkag-abc .tkag-row{height:var(--tkag-mobile-row-height)!important;}}'
  . '.tkag-lightbox{position:fixed!important;inset:0!important;}</style>'
  . '<div class="tkag-row"><a href="https://quelle.example/wp-content/uploads/2026/foo/bild(1).jpg" class="tkag-tile" data-type="image" '
  . 'style="display:block!important;flex:1.5 1 0!important;position:relative!important">'
  . '<img src="https://quelle.example/wp-content/uploads/2026/foo/bild(1)-300x200.jpg" loading="lazy" decoding="async" width="300" height="200" alt="" style="object-fit:cover!important;transform:scale(1.1)!important"></a>'
  . '<a href="https://quelle.example/wp-content/uploads/2026/foo/film.mp4" class="tkag-tile" data-type="video"><video muted playsinline preload="metadata"><source src="https://quelle.example/wp-content/uploads/2026/foo/film.mp4"></video></a></div>'
  . '<script>(function(){ window.TkAutoGalleryLightboxInitialized = true; })();</script></div>';
$galleryOutput = sanitizeForTest($galleryHtml);

TestRun::check(count(findSecurityProblems($galleryOutput)) === 0, 'gallery output is safe', implode("\n", findSecurityProblems($galleryOutput)));
TestRun::check(str_contains($galleryOutput, 'id="kra-1-tkag-abc"'), 'gallery id is prefixed', $galleryOutput);
TestRun::check(str_contains($galleryOutput, '#kornsw-ra-1 #kra-1-tkag-abc *{box-sizing:border-box!important}'), 'gallery style sheet is scoped and ids are renamed', $galleryOutput);
TestRun::check(str_contains($galleryOutput, '@media (max-width:700px){#kornsw-ra-1 #kra-1-tkag-abc .tkag-row{height:var(--tkag-mobile-row-height)!important}}'), 'media queries survive', $galleryOutput);
TestRun::check(str_contains($galleryOutput, '--tkag-gap:10px'), 'custom properties in style attributes survive', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'position:relative!important'), 'position relative survives', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'transform:scale(1.1)!important'), 'transform survives', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'href="https://quelle.example/wp-content/uploads/2026/foo/bild(1).jpg"'), 'tile link to the image stays (parentheses in URL)', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'data-kornsw-ra-media="image"'), 'image tile is marked for the lightbox', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'data-kornsw-ra-media="video"'), 'video tile is marked for the lightbox', $galleryOutput);
TestRun::check(str_contains($galleryOutput, 'data-type="image"'), 'data attributes of the source survive', $galleryOutput);
TestRun::check(!str_contains($galleryOutput, '<script'), 'gallery script is removed', $galleryOutput);
TestRun::check(!str_contains($galleryOutput, 'target="_blank"'), 'media links are not subject to the link mode', $galleryOutput);

// --- Link modes ---
$pageLink = '<p><a href="https://quelle.example/andere-seite/">Andere Seite</a> und <a href="https://www.quelle.example/x/">www</a></p>';
$newWindowOutput = sanitizeForTest($pageLink, LinkMode::NewWindow);
TestRun::check(substr_count($newWindowOutput, 'target="_blank" rel="noopener noreferrer"') === 2, 'link mode "new window" opens source pages in a new window (also www. variant)', $newWindowOutput);
$removeOutput = sanitizeForTest($pageLink, LinkMode::Remove);
TestRun::check($removeOutput === '<p>Andere Seite und www</p>', 'link mode "remove" keeps the link text only', $removeOutput);
$keepOutput = sanitizeForTest($pageLink, LinkMode::Keep);
TestRun::check(str_contains($keepOutput, '<a href="https://quelle.example/andere-seite/">') && !str_contains($keepOutput, 'target'), 'link mode "keep" leaves links unchanged', $keepOutput);

$externalOutput = sanitizeForTest('<a href="https://andere.example/">extern</a>', LinkMode::Remove);
TestRun::check($externalOutput === '<a href="https://andere.example/">extern</a>', 'links to third-party sites are not affected by the link mode', $externalOutput);
$uploadOutput = sanitizeForTest('<a href="https://quelle.example/wp-content/uploads/dok.pdf">PDF</a>', LinkMode::Remove);
TestRun::check(str_contains($uploadOutput, '<a href="https://quelle.example/wp-content/uploads/dok.pdf">'), 'links to uploads are not removed', $uploadOutput);

// --- URL resolution ---
$relativeOutput = sanitizeForTest('<img src="/wp-content/uploads/x.jpg" alt="x"><a href="../andere/">rel</a><img src="http://quelle.example/y.jpg"><a href="#teil">Sprung</a><h2 id="teil">Teil</h2>', LinkMode::Keep);
TestRun::check(str_contains($relativeOutput, 'src="https://quelle.example/wp-content/uploads/x.jpg"'), 'root-relative URLs are resolved against the source', $relativeOutput);
TestRun::check(str_contains($relativeOutput, 'href="https://quelle.example/2026/10/andere/"'), 'document-relative URLs with ".." are resolved', $relativeOutput);
TestRun::check(str_contains($relativeOutput, 'src="https://quelle.example/y.jpg"'), 'http is upgraded to https', $relativeOutput);
TestRun::check(str_contains($relativeOutput, 'href="#kra-1-teil"') && str_contains($relativeOutput, 'id="kra-1-teil"'), 'fragment links follow the prefixed ids', $relativeOutput);

// --- Other content ---
$youtubeOutput = sanitizeForTest('<iframe src="https://www.youtube.com/embed/abc" width="560" height="315" onload="x" title="Video"></iframe>');
TestRun::check(str_contains($youtubeOutput, 'src="https://www.youtube.com/embed/abc"') && str_contains($youtubeOutput, 'sandbox='), 'YouTube iframes are kept with sandbox', $youtubeOutput);
$unwrapOutput = sanitizeForTest('<center><b>fett</b></center><custom-element>text</custom-element>');
TestRun::check($unwrapOutput === '<b>fett</b>text', 'unknown elements are unwrapped, their content stays', $unwrapOutput);
$srcsetOutput = sanitizeForTest('<img src="a.jpg" srcset="a-300.jpg 300w, a-600.jpg 600w" sizes="(max-width: 600px) 100vw, 600px" alt="">');
TestRun::check(str_contains($srcsetOutput, 'srcset="https://quelle.example/2026/10/beitrag/a-300.jpg 300w, https://quelle.example/2026/10/beitrag/a-600.jpg 600w"'), 'srcset candidates are resolved', $srcsetOutput);
$ownHostCssOutput = sanitizeForTest('<div style="background-image:url(\'https://eigene.example/bg.png\')">x</div>');
TestRun::check(str_contains($ownHostCssOutput, 'url(&quot;https://eigene.example/bg.png&quot;)') || str_contains($ownHostCssOutput, 'url("https://eigene.example/bg.png")'), 'CSS urls to the own site are allowed', $ownHostCssOutput);
$blockOutput = sanitizeForTest('<figure class="wp-block-image size-large"><img src="https://quelle.example/wp-content/uploads/b.jpg" alt="Bild"/><figcaption class="wp-element-caption">Text</figcaption></figure>');
TestRun::check(str_contains($blockOutput, '<figure class="wp-block-image size-large"><img src="https://quelle.example/wp-content/uploads/b.jpg" alt="Bild"><figcaption class="wp-element-caption">Text</figcaption></figure>'), 'block editor markup survives unchanged', $blockOutput);
