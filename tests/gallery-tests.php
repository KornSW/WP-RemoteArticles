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
$placeholderResult = $inserter->insert($moduleMarkup, function (ShareOneDriveModule $module): string {
  return 'https://eigene.example/wp-json/kornsw-remotearticles/v1/gallery?d=x&s=y';
});
TestRun::check($placeholderResult['count'] === 1, 'gallery module is detected', $placeholderResult['html']);
TestRun::check(
  str_contains($placeholderResult['html'], 'data-kornsw-ra-gallery="https://eigene.example/wp-json/kornsw-remotearticles/v1/gallery?d=x&amp;s=y"')
    && !str_contains($placeholderResult['html'], 'wpcp-container') && str_contains($placeholderResult['html'], '<p>Weiter</p>'),
  'the whole container is replaced by the placeholder, surrounding content stays',
  $placeholderResult['html']
);
$noGallery = $inserter->insert('<p>ShareoneDrive als Wort</p>', function (): string {
  return 'x';
});
TestRun::check($noGallery['count'] === 0 && $noGallery['html'] === '<p>ShareoneDrive als Wort</p>', 'content without gallery module stays unchanged');

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
