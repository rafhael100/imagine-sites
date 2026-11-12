<?php

declare(strict_types=1);

/** F1-290: source and policy verification; no HTTP service or production. */
$root=dirname(__DIR__);
$css=(string)file_get_contents($root.'/apps/sites/resources/views/labs/imagine-shell.blade.php');
$view=(string)file_get_contents($root.'/apps/sites/resources/views/labs/review.blade.php');
$routes=(string)file_get_contents($root.'/apps/sites/routes/web.php');
$renderer=(string)file_get_contents($root.'/src/Production/StaticSiteRenderer.php');
$logo=(string)file_get_contents($root.'/apps/sites/public/assets/logo-imagine.png');
$checks=0;
function assert290(bool $ok,string $label):void {
    global $checks;
    if(!$ok) throw new RuntimeException('F1-290 '.$label);
    ++$checks;
}
$tokens=[
    '--imagine-orange'=>'#f9941d',
    '--imagine-ink'=>'#171b19',
    '--imagine-white'=>'#ffffff',
    '--imagine-surface'=>'#f7f7f8',
    '--imagine-muted'=>'#737373',
    '--imagine-border'=>'#e8e8e8',
    '--imagine-orange-soft'=>'#fff1e0',
    '--imagine-orange-text'=>'#9b4d00',
    '--imagine-focus'=>'#b75c00',
    '--imagine-radius-control'=>'6px',
    '--imagine-radius-card'=>'9px',
    '--imagine-sidebar-width'=>'240px',
    '--imagine-font-ui'=>'Arial, Helvetica, sans-serif',
];
foreach($tokens as $name=>$value) {
    assert290(str_contains($css,$name.': '.$value.';'),
        'local aliases match Node Portal contract '.$name);
}
assert290(str_contains($view,"@include('labs.imagine-shell')") &&
    substr_count($view,"@include('labs.imagine-shell')")===1,
    'administrative view includes one isolated brand stylesheet');
assert290(str_contains($view,'alt="Imagine"') &&
    str_contains($view,'src="/assets/logo-imagine.png"') &&
    !str_contains($view,'src="https://'),
    'logo is local, accessible, and no remote image');
assert290(str_starts_with($logo,"\x89PNG\r\n\x1a\n") &&
    strlen($logo)>1000 &&
    hash('sha1','blob '.strlen($logo)."\0".$logo) ===
    '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77',
    'same exact original Git logo blob from Imagine Portal Node');
assert290(substr_count($routes,"img-src 'self' data:;")===16 &&
    !str_contains($routes,'img-src https:') &&
    !str_contains($routes,'img-src *'),
    'all existing 16 lab-only CSPs permit same-origin logo and no CDN');
assert290(!str_contains($renderer,'--imagine-') &&
    !str_contains($renderer,'/assets/logo-imagine.png'),
    'customer website HTML remains independent of staff branding');
assert290(!str_contains($css,'@import') &&
    !str_contains($css,'url(') &&
    !str_contains($css,'<script') &&
    !str_contains($css,'<form'),
    'no imports, external URLs, scripts or forms');
assert290(str_contains($css,'--sites-shell-bg: var(--imagine-surface)') &&
    str_contains($css,'--sites-shell-accent-text: var(--imagine-orange-text)'),
    'shell aliases derive from shared Imagine tokens');
assert290(str_contains($css,'a:focus-visible') &&
    str_contains($css,'@media(max-width:610px)') &&
    str_contains($css,'prefers-reduced-motion'),
    'keyboard focus, responsive mobile and reduced motion');
function luminance290(string $hex):float {
    $rgb=[];
    for($i=1;$i<=5;$i+=2) {
        $x=hexdec(substr($hex,$i,2))/255.0;
        $rgb[]=$x<=0.04045?$x/12.92:(($x+0.055)/1.055)**2.4;
    }
    return $rgb[0]*.2126+$rgb[1]*.7152+$rgb[2]*.0722;
}
function contrast290(string $a,string $b):float {
    $x=luminance290($a);$y=luminance290($b);
    return (max($x,$y)+.05)/(min($x,$y)+.05);
}
foreach([
    ['#9b4d00','#ffffff'],
    ['#9b4d00','#fff1e0'],
    ['#b75c00','#ffffff'],
    ['#737373','#ffffff'],
    ['#171b19','#ffffff']
] as [$a,$b]) {
    assert290(contrast290($a,$b)>=4.5,
        'WCAG AA small-text/focus colors '.$a.' on '.$b);
}
assert290(contrast290('#f9941d','#ffffff')<4.5 &&
    !preg_match('/\bcolor\s*:\s*var\(--imagine-orange\)/',$css),
    'decorative orange is not used as small text on white');
echo "$checks F1-290 Imagine Node brand contract assertions passed; no customer CSS changed.\n";
