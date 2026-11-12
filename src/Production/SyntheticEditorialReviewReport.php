<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

/**
 * F1-63: non-authoritative editorial suggestions for a PRIVATE synthetic
 * website preview. This is NOT SEO ranking, client acceptance or publish gate.
 */
final readonly class SyntheticEditorialReviewReport
{
    /** @param array<string,bool> $checks
     *  @param list<string> $suggestions
     */
    private function __construct(
        public array $checks,
        public array $suggestions,
    ) {}

    public static function inspect(SiteBrief $brief,ReleaseArtifact $artifact):self
    {
        $rendered=(new StaticSiteRenderer())->render($brief);
        $sourceExact=hash_equals($rendered->digest,$artifact->digest) &&
            hash_equals($rendered->html,$artifact->html);
        $safe=InertPreviewAudit::isSafe($artifact->html) &&
            SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks;

        // UTF-8 was already checked by SiteBrief. These ranges are OPTIONAL
        // editorial heuristics, not search-engine rules or ranking promises.
        $title=$brief->headline.' | '.$brief->brand;
        $titleLength=mb_strlen($title,'UTF-8');
        $descriptionLength=mb_strlen($brief->description,'UTF-8');
        $ctaLength=mb_strlen($brief->callToAction,'UTF-8');
        $unique=[];
        foreach($brief->benefits as $benefit) {
            $normalized=preg_replace('/\s+/u',' ',trim($benefit));
            $unique[]=mb_strtolower($normalized??trim($benefit),'UTF-8');
        }
        $checks=[
            'exact_inert_html'=>$sourceExact && $safe,
            'descriptive_title'=>$titleLength>=20 && $titleLength<=70,
            'description_readability'=>$descriptionLength>=60 && $descriptionLength<=160,
            'cta_clarity'=>$ctaLength>=4 && $ctaLength<=45,
            'benefit_coverage'=>count($brief->benefits)>=2 && count($brief->benefits)<=6,
            'benefits_distinct'=>count(array_unique($unique))===count($unique),
        ];
        $labels=[
            'exact_inert_html'=>'Revisar integridade da prévia: o HTML precisa coincidir exatamente com o briefing.',
            'descriptive_title'=>'Revisar o título: uma frase clara e objetiva ajuda na compreensão da página.',
            'description_readability'=>'Revisar a descrição: explicar a proposta com detalhes úteis e linguagem direta.',
            'cta_clarity'=>'Revisar a chamada para ação: escolher uma orientação curta e específica.',
            'benefit_coverage'=>'Revisar os benefícios: apresentar de dois a seis pontos realmente distintos.',
            'benefits_distinct'=>'Revisar os benefícios repetidos: remover ideias duplicadas antes da aprovação.',
        ];
        $suggestions=[];
        foreach($checks as $key=>$passed) {
            if(!$passed)$suggestions[]=$labels[$key];
        }
        return new self($checks,$suggestions);
    }
}
