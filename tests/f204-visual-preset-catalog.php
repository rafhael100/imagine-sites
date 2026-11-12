<?php

declare(strict_types=1);

/** F1-204: UI/CLI share one owned finite catalog, never arbitrary CSS. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Production\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unexpected fixture class');
    require __DIR__.'/../src/Production/'.$name.'.php';
});

use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticBrandPaletteV1 as Palette;

$assertions=0;
function check204(bool $okay,string $why):void {
    global $assertions;
    if (!$okay)throw new RuntimeException('F1-204 '.$why);
    ++$assertions;
}
function denied204(callable $operation):bool {
    try {$operation();}catch(InvalidArgumentException){return true;}
    return false;
}
$orders=Catalog::sectionOrders();
$palettes=Catalog::palettes();
check204(count($orders)===6 && count($palettes)===4,
    'exactly six orders and four static palettes');
$all=[];
$permutations=[];
foreach($orders as $order) {
    check204(array_is_list($order) && count($order)===3,
        'section order is a list without privilege fields');
    $sorted=$order;
    sort($sorted,SORT_STRING);
    check204($sorted===['faq','highlights','process'],
        'only fixed FAQ/services/process sections');
    $permutations[implode(',', $order)]=true;
}
check204(count($permutations)===6,
    'all six combinations are distinct permutations');

foreach($palettes as $name=>$palette) {
    if($name==='default'){
        check204($palette===null,'default palette uses legacy style byte-for-byte');
        continue;
    }
    check204($palette instanceof Palette,
        'named palette is a validated type, never arbitrary CSS');
    $contrast=$palette->contrast();
    check204($contrast['heroStart']>=4.5 &&
        $contrast['heroEnd']>=4.5 &&
        $contrast['button']>=4.5,
        'preset colors retain tested minimum 4.5:1 contrast');
    check204(preg_match(
        '/\A    \.hero \{ background: linear-gradient\(120deg, #[0-9a-f]{6}, #[0-9a-f]{6}\); color: #ffffff; \}\n'.
        '    \.hero \.eyebrow \{ color: #ffffff; opacity: 1; \}\n'.
        '    \.hero \.btn \{ background: #[0-9a-f]{6}; color: #[0-9a-f]{6}; \}\z/D',
        $palette->cssRules())===1,
        'only canonical fixed CSS selectors/values are emitted');
}
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach($orders as $orderCode=>$order) {
            foreach($product==='onepage'?['off','on']:['off'] as $nav) {
                foreach($palettes as $colorCode=>$color) {
                    $selected=Catalog::choose(
                        $product,$preset,$orderCode,$nav,$colorCode
                    );
                    check204($selected['product']===$product &&
                        $selected['preset']===$preset &&
                        $selected['order']===$order &&
                        $selected['nav']===($nav==='on') &&
                        ($color===null ?
                            $selected['palette']===null :
                            $selected['palette']?->cssRules()===$color->cssRules()),
                        'selection returns exact independently controlled values');
                    $key=implode('|',[$product,$preset,$orderCode,$nav,$colorCode]);
                    $all[$key]=true;
                    $original=Catalog::isOriginal($preset,$orderCode,$nav,$colorCode);
                    check204($original===($preset==='classic' &&
                        $orderCode==='services_process_faq' &&
                        $nav==='off' && $colorCode==='default'),
                        'baseline identity is exactly defined');
                }
            }
        }
    }
}
check204(count($all)===216,'only 216 product-appropriate combinations exist');
foreach([
    ['blog','classic','services_process_faq','off','default'],
    ['onepage','custom','services_process_faq','off','default'],
    ['onepage','classic','faq_unknown','off','default'],
    ['onepage','classic','services_process_faq','maybe','default'],
    ['onepage','classic','services_process_faq','off','https://evil.test'],
    ['onepage','classic','services_process_faq','off','#123456'],
    ['landing_page','classic','services_process_faq','on','default'],
    ['ONEPAGE','classic','services_process_faq','off','default'],
    ['onepage','classic','services_process_faq','off','DEFAULT'],
] as $invalid) {
    check204(denied204(static fn()=>Catalog::choose(...$invalid)),
        'invalid user-looking selection never enters generated HTML');
}
echo "$assertions F1-204 common visual palette/layout preset assertions passed.\n";
