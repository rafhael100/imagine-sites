<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/**
 * F1-190: four strictly typed brand hex colors, never CSS or a remote asset.
 *
 * Hero text is fixed pure white, button foreground/background are separately
 * contrast-checked. This is a synthetic design preference, not an authenticated
 * brand asset, a color-profile certification or permission to publish.
 */
final readonly class SyntheticBrandPaletteV1
{
    private const HEX='/\A#[a-fA-F0-9]{6}\z/D';

    private function __construct(
        public string $heroStart,
        public string $heroEnd,
        public string $buttonBackground,
        public string $buttonText,
    ) {}

    public static function fromHex(
        string $heroStart,
        string $heroEnd,
        string $buttonBackground,
        string $buttonText,
    ):self {
        $colors=[$heroStart,$heroEnd,$buttonBackground,$buttonText];
        foreach($colors as $color) {
            if(preg_match(self::HEX,$color)!==1) {
                throw new InvalidArgumentException(
                    'Brand palette supports only exact six-digit RGB hex colors.'
                );
            }
        }
        [$heroStart,$heroEnd,$buttonBackground,$buttonText]=array_map(
            strtolower(...),$colors
        );
        if (self::contrastRatio('#ffffff',$heroStart)<4.5 ||
            self::contrastRatio('#ffffff',$heroEnd)<4.5 ||
            self::contrastRatio($buttonBackground,$buttonText)<4.5) {
            throw new InvalidArgumentException(
                'Brand palette fails WCAG AA normal-text contrast at a checked endpoint.'
            );
        }
        return new self($heroStart,$heroEnd,$buttonBackground,$buttonText);
    }

    /** @return array{heroStart:float,heroEnd:float,button:float} */
    public function contrast():array
    {
        return [
            'heroStart'=>self::contrastRatio('#ffffff',$this->heroStart),
            'heroEnd'=>self::contrastRatio('#ffffff',$this->heroEnd),
            'button'=>self::contrastRatio($this->buttonBackground,$this->buttonText),
        ];
    }

    /**
     * Entire fixed grammar: only validated, canonical #rrggbb tokens
     * can enter these static styles. No arbitrary property/selector/URL.
     */
    public function cssRules():string
    {
        return '    .hero { background: linear-gradient(120deg, '.
            $this->heroStart.', '.$this->heroEnd.'); color: #ffffff; }'."\n".
            '    .hero .eyebrow { color: #ffffff; opacity: 1; }'."\n".
            '    .hero .btn { background: '.$this->buttonBackground.
            '; color: '.$this->buttonText.'; }';
    }

    private static function contrastRatio(string $a,string $b):float
    {
        $first=self::luminance($a);
        $second=self::luminance($b);
        return (max($first,$second)+0.05)/(min($first,$second)+0.05);
    }

    private static function luminance(string $hex):float
    {
        $channels=[];
        foreach([1,3,5] as $offset) {
            $channel=hexdec(substr($hex,$offset,2))/255;
            $channels[]=$channel<=0.04045
                ? $channel/12.92
                : (($channel+0.055)/1.055)**2.4;
        }
        return 0.2126*$channels[0]+0.7152*$channels[1]+0.0722*$channels[2];
    }
}
