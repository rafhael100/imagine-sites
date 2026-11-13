<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/**
 * F1-204: Imagine-owned, literal-only options for offline visual designs.
 * A client, AI, or URL must never provide CSS, colors or arbitrary layout.
 *
 * This is not a service identity, approval, license or deployment gate.
 */
final class SyntheticVisualPresetCatalog
{
    /** @return array<string,list<string>> */
    public static function sectionOrders(): array
    {
        return [
            'services_process_faq'=>['highlights','process','faq'],
            'services_faq_process'=>['highlights','faq','process'],
            'process_services_faq'=>['process','highlights','faq'],
            'process_faq_services'=>['process','faq','highlights'],
            'faq_services_process'=>['faq','highlights','process'],
            'faq_process_services'=>['faq','process','highlights'],
        ];
    }

    /** @return array<string,?SyntheticBrandPaletteV1> */
    public static function palettes(): array
    {
        return [
            'default'=>null,
            'ocean'=>SyntheticBrandPaletteV1::fromHex(
                '#064e5b','#075a6b','#ffffff','#075a6b'
            ),
            'plum'=>SyntheticBrandPaletteV1::fromHex(
                '#50204d','#673060','#ffe9fa','#50204d'
            ),
            'forest'=>SyntheticBrandPaletteV1::fromHex(
                '#134a36','#1d5a43','#f4fff5','#134a36'
            ),
        ];
    }

    /**
     * @return array{product:string,preset:string,order:list<string>,
     *     nav:bool,palette:?SyntheticBrandPaletteV1}
     */
    public static function choose(
        string $product,
        string $preset,
        string $orderCode,
        string $navCode,
        string $paletteCode,
    ): array {
        $orders=self::sectionOrders();
        $palettes=self::palettes();
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !array_key_exists($orderCode,$orders) ||
            !in_array($navCode,['off','on'],true) ||
            !array_key_exists($paletteCode,$palettes) ||
            ($product==='landing_page' && $navCode==='on')) {
            throw new InvalidArgumentException('Unknown fixed synthetic visual option.');
        }
        return [
            'product'=>$product,
            'preset'=>$preset,
            'order'=>$orders[$orderCode],
            'nav'=>$navCode==='on',
            'palette'=>$palettes[$paletteCode],
        ];
    }

    public static function isOriginal(
        string $preset,
        string $orderCode,
        string $navCode,
        string $paletteCode,
    ): bool {
        return $preset==='classic' &&
            $orderCode==='services_process_faq' &&
            $navCode==='off' &&
            $paletteCode==='default';
    }
}
