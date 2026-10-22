<?php

declare(strict_types=1);

use KornSW\RemoteArticles\Embedding\UrlResolver;
use KornSW\RemoteArticles\Galleries\GalleryPlaceholderInserter;
use KornSW\RemoteArticles\Galleries\ShareOneDriveModule;
use KornSW\RemoteArticles\Galleries\ShareOneDriveResponseParser;

// Share-one-Drive adapter (doc/security.md, section 8): parameters, response parsing with host allow list, placeholders.

$moduleMarkup = "<p>Text</p><div id='kra-1-wpcp-635fd131b0e39396cd03672e81c5c1c4' class='wpcp-container'>"
  . "<div class='wpcp-module ShareoneDrive wpcp-gallery jsdisabled' data-token='635fd131b0e39396cd03672e81c5c1c4' "
  . "data-account-id='b6d95d20cef28f44' data-drive-id='b6d95d20cef28f44' data-id='' data-path='bnVsbA==' data-sort='name:asc' "
  . "data-list='gallery' data-targetheight='300'><div class='nav-header'>UI</div></div></div><p>Weiter</p>";

// --- module parameters ---
$validAttributes = ['data-token' => '635fd131b0e39396cd03672e81c5c1c4', 'data-account-id' => 'b6d95d20cef28f44', 'data-drive-id' => 'B6D95D20CEF28F44', 'data-path' => 'bnVsbA==', 'data-sort' => 'name:asc', 'data-targetheight' => '300'];
TestRun::check(ShareOneDriveModule::fromAttributes($validAttributes) !== null, 'valid gallery module is accepted');
$invalidModules = [
  'token with URL' => ['data-token' => 'https://evil.example/'],
  'account with query' => ['data-account-id' => 'a&action=x'],
  'path with markup' => ['data-path' => '<script>'],
  'folder with slash' => ['data-id' => '../x'],
];
foreach ($invalidModules as $description => $override) {
  TestRun::check(ShareOneDriveModule::fromAttributes(array_merge($validAttributes, $override)) === null, 'gallery module rejects ' . $description);
}
$roundTrip = ShareOneDriveModule::fromArray(ShareOneDriveModule::fromAttributes($validAttributes)->toArray());
TestRun::check($roundTrip !== null && $roundTrip->buildRequestFields('abc')['action'] === 'shareonedrive-get-gallery', 'module survives the signed payload round trip');

// --- placeholders ---
$inserter = new GalleryPlaceholderInserter();
$noNextGen = function (int $index): string {
  return 'unused';
};
$placeholderResult = $inserter->insert($moduleMarkup, function (ShareOneDriveModule $module): string {
  return 'https://eigene.example/wp-json/kornsw-remotearticles/v1/gallery?d=x&s=y';
}, $noNextGen);
TestRun::check($placeholderResult['count'] === 1, 'gallery module is detected', $placeholderResult['html']);
TestRun::check(
  str_contains($placeholderResult['html'], 'data-kornsw-ra-gallery="https://eigene.example/wp-json/kornsw-remotearticles/v1/gallery?d=x&amp;s=y"')
    && !str_contains($placeholderResult['html'], 'wpcp-container') && str_contains($placeholderResult['html'], '<p>Weiter</p>'),
  'the whole container is replaced by the placeholder, surrounding content stays',
  $placeholderResult['html']
);
$noGallery = $inserter->insert('<p>ShareoneDrive als Wort</p>', function (): string {
  return 'x';
}, $noNextGen);
TestRun::check($noGallery['count'] === 0 && $noGallery['html'] === '<p>ShareoneDrive als Wort</p>', 'content without gallery module stays unchanged');
TestRun::check(str_contains($placeholderResult['html'], 'Galerie wird geladen') && str_contains($placeholderResult['html'], 'aria-busy="true"'), 'placeholder shows a loading indicator');

// --- NextGEN placeholders and page galleries ---
$nextGenResult = $inserter->insert(
  "<p>Text</p>\nngg_shortcode_0_placeholder\n<h2>Teil</h2><p>ngg_shortcode_1_placeholder</p><p>Ende</p>",
  function (): string {
    return 'unused';
  },
  function (int $index): string {
    return 'https://eigene.example/gallery?n=' . $index;
  }
);
TestRun::check($nextGenResult['count'] === 2, 'NextGEN placeholders are detected', $nextGenResult['html']);
TestRun::check(
  str_contains($nextGenResult['html'], 'gallery?n=0') && str_contains($nextGenResult['html'], 'gallery?n=1')
    && !str_contains($nextGenResult['html'], 'ngg_shortcode') && !str_contains($nextGenResult['html'], '<p></p>'),
  'NextGEN placeholders are numbered in document order and replaced completely (also when wrapped in <p>)',
  $nextGenResult['html']
);
TestRun::check(
  !str_contains(sanitizeForTest('<div data-kornsw-ra-nextgen="0">x</div>'), 'data-kornsw-ra-nextgen'),
  'the internal NextGEN marker cannot be forged by the source (removed by the sanitizer)'
);

$nextGenParser = new \KornSW\RemoteArticles\Galleries\NextGenGalleryParser(new UrlResolver());
$nextGenPage = '<html><body><aside><div data-nextgen-gallery-id="99"><a href="https://quelle.example/wp-content/gallery/w/a.jpg"><img src="https://quelle.example/wp-content/gallery/w/thumbs/a.jpg" width="100" height="100"></a></div></aside>'
  . '<article><div class="entry-content"><p>x</p>'
  . '<div class="ngg-galleryoverview" data-nextgen-gallery-id="19"><div class="ngg-gallery-thumbnail"><a href="/wp-content/gallery/aida/1.jpg" data-title="Bild 1"><img src="/wp-content/gallery/aida/thumbs/thumbs_1.jpg" width="240" height="160" alt="Alt 1"></a></div>'
  . '<div class="ngg-gallery-thumbnail"><a href="https://evil.example/2.jpg"><img src="/wp-content/gallery/aida/thumbs/thumbs_2.jpg" width="240" height="160"></a></div>'
  . '<div class="ngg-gallery-thumbnail"><a href="javascript:alert(1)"><img src="/x.jpg"></a></div></div>'
  . '<div class="ngg-galleryoverview" data-nextgen-gallery-id="20"><a href="/wp-content/gallery/aida/3.jpg"><img src="/wp-content/gallery/aida/thumbs/thumbs_3.jpg" width="160" height="240"></a></div>'
  . '</div></article></body></html>';
$nextGenGalleries = $nextGenParser->parse($nextGenPage, 'https://quelle.example/2022/aida/', 'quelle.example');
TestRun::check(count($nextGenGalleries) === 2, 'NextGEN galleries are taken from the post content only (not from widgets)', print_r($nextGenGalleries, true));
TestRun::check(
  count($nextGenGalleries[0]) === 1 && $nextGenGalleries[0][0]->mediaUrl === 'https://quelle.example/wp-content/gallery/aida/1.jpg'
    && $nextGenGalleries[0][0]->title === 'Bild 1' && $nextGenGalleries[0][0]->height === 160,
  'NextGEN items: relative URLs resolved, foreign hosts and javascript: dropped, title and size taken over'
);
TestRun::check(count($nextGenGalleries[1]) === 1 && $nextGenGalleries[1][0]->width === 160, 'second NextGEN gallery in order');

// --- response parsing ---
$parser = new ShareOneDriveResponseParser(new UrlResolver());
$thumb = 'https://northeurope1-mediap.svc.ms/transform/thumbnail?provider=spo&amp;docid=x&amp;width=225&amp;height=300';
$large = 'https://northeurope1-mediap.svc.ms/transform/thumbnail?provider=spo&amp;docid=x&amp;width=1500&amp;height=1500';
$stream = 'https://quelle.example/wp-admin/admin-ajax.php?action=shareonedrive-stream&amp;id=B6D%2198179&amp;listtoken=abc';
$responseHtml = "<div class='images'>"
  . "<div class='image-container entry'><a href='$large' title='Bild &lt;1&gt;' data-type='image'><img src='https://quelle.example/transparant.png' data-src='$thumb' data-src-retina='$thumb' width='225' height='300'></a></div>"
  . "<div class='image-container entry'><a href='$large' title='Video' data-type='video' data-options=\"thumbnail: '$thumb', html5video:{h264:'$stream', poster: '$thumb'}\"><img data-src='$thumb' width='533' height='300'></a></div>"
  . "<div class='image-container entry'><a href='https://evil.example/x.jpg' data-type='image'><img data-src='$thumb' width='1' height='1'></a></div>"
  . "<div class='image-container entry'><a href='$large' data-type='image'><img data-src='https://evil.example/t.jpg' width='1' height='1'></a></div>"
  . "<div class='image-container entry'><a href='$large' data-type='video' data-options=\"html5video:{h264:'https://evil.example/wp-admin/admin-ajax.php?action=shareonedrive-stream'}\"><img data-src='$thumb'></a></div>"
  . "<div class='image-container entry'><a href='javascript:alert(1)' data-type='image'><img data-src='$thumb'></a></div>"
  . "</div>";
$items = $parser->parse((string)json_encode(['html' => $responseHtml]), 'quelle.example');
TestRun::check(count($items) === 2, 'only the two valid entries survive (foreign hosts, foreign stream host and javascript: are dropped)', print_r($items, true));
TestRun::check($items[0]->type === 'image' && $items[0]->title === 'Bild <1>' && str_starts_with($items[0]->mediaUrl, 'https://northeurope1-mediap.svc.ms/') && $items[0]->width === 225, 'image entry: data only, title as plain text');
TestRun::check($items[1]->type === 'video' && str_starts_with($items[1]->mediaUrl, 'https://quelle.example/wp-admin/admin-ajax.php?action=shareonedrive-stream'), 'video entry: stream of the source');
TestRun::check($parser->isAllowedMediaUrl('https://my.microsoftpersonalcontent.com/x') && !$parser->isAllowedMediaUrl('https://svc.ms.evil.example/x') && !$parser->isAllowedMediaUrl('http://a.svc.ms/x'), 'media host allow list (suffix match, https only)');

$rejected = '';
try {
  $parser->parse('-1', 'quelle.example');
} catch (\InvalidArgumentException $exception) {
  $rejected = $exception->getMessage();
}
TestRun::check($rejected !== '', 'an invalid nonce response ("-1") is reported as error (triggers nonce renewal)');
