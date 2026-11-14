<?php

declare(strict_types=1);

namespace App\Labs;

use DomainException;
use Illuminate\Database\Connection;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\StructuredCopyCandidate;
use RuntimeException;

/**
 * F1-381: operator-only, CI-gated visual review artifacts from previously
 * admitted fictional projects. NEVER a public page or publishing pipeline.
 *
 * This lab deliberately uses fixed fake copy, NOT client briefs/AI calls:
 * having a valid order is not permission to publish real client content.
 */
final readonly class LaravelAdmittedPreviewLab
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        if (PHP_SAPI!=='cli' ||
            !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F381_REVIEW_LAB')!=='1' ||
            getenv('DB_HOST')!=='sites-db' ||
            getenv('DB_USERNAME')!=='sites_f360_b') {
            throw new DomainException('F1-381: disposable operator-only lab required.');
        }

        $identity=$this->db->selectOne(
            'SELECT current_database() AS db,session_user AS login,
                    current_setting(\'server_version_num\')::integer AS version'
        );
        if (!is_object($identity) ||
            $identity->db!=='sites_f360_lab' ||
            $identity->login!=='sites_f360_b' ||
            (int)$identity->version<180000 ||
            (int)$identity->version>=190000) {
            throw new DomainException('F1-381: SQL login and DB provenance rejected.');
        }

        // No SELECT privilege is granted on the admissions table itself.
        $rows=$this->db->select(
            'SELECT * FROM sites_f360.list_own_review_candidates()'
        );
        if (count($rows)!==2) {
            throw new DomainException('F1-381: exactly two admitted sample Site projects required.');
        }
        $items=[];
        foreach ($rows as $row) {
            if (!is_object($row) ||
                !in_array($row->product_code ?? null,['onepage','landing_page'],true) ||
                !is_string($row->site_ref ?? null) ||
                !is_string($row->command_digest ?? null) ||
                !preg_match('/\A[a-f0-9]{64}\z/D',$row->command_digest)) {
                throw new DomainException('F1-381: invalid source project row.');
            }
            $product=$row->product_code;
            if (isset($items[$product])) {
                throw new DomainException('F1-381: duplicate product in review bundle.');
            }
            $candidate = $product==='onepage' ? [
                'schemaVersion'=>'sites.ai-copy.v1',
                'headline'=>'Uma presença digital que representa o seu negócio',
                'description'=>'Demonstração fictícia de site institucional com navegação clara e apresentação de benefícios.',
                'callToAction'=>'Conheça os nossos serviços',
                'benefits'=>[
                    'Apresentação objetiva dos serviços',
                    'Experiência agradável no celular',
                    'Identidade e conteúdo organizados',
                ],
            ] : [
                'schemaVersion'=>'sites.ai-copy.v1',
                'headline'=>'Sua próxima campanha começa com uma boa página',
                'description'=>'Demonstração fictícia de landing page com mensagem direta e foco em uma única ação.',
                'callToAction'=>'Saiba como funciona',
                'benefits'=>[
                    'Mensagem principal em destaque',
                    'Benefícios apresentados com clareza',
                    'Experiência responsiva e acessível',
                ],
            ];
            $brief=StructuredCopyCandidate::assemble(
                $product,'Marca Demonstração Imagine',$candidate
            );
            $artifact=(new StaticSiteRenderer())->render($brief);
            if (!hash_equals(hash('sha256',$artifact->html),$artifact->digest) ||
                !str_contains($artifact->html,'<meta name="robots" content="noindex, nofollow">') ||
                str_contains($artifact->html,'<script') ||
                str_contains($artifact->html,'<form') ||
                str_contains($artifact->html,'http://') ||
                str_contains($artifact->html,'https://')) {
                throw new RuntimeException('F1-381: preview violated inert HTML constraints.');
            }
            $items[$product]=[
                'filename'=>$product==='onepage'?'preview-onepage.html':'preview-landing-page.html',
                'product'=>$product,
                'sha256'=>$artifact->digest,
                'sizeBytes'=>$artifact->sizeBytes,
                'htmlBase64'=>base64_encode($artifact->html),
                'publishable'=>false,
                'clientApproved'=>false,
            ];
        }
        if (!isset($items['onepage'],$items['landing_page'])) {
            throw new DomainException('F1-381: both product types required.');
        }
        return [
            'schemaVersion'=>'sites.ci.review-bundle.v1',
            'source'=>'restricted_pg18_admitted_fictitious_project_read_model',
            'fictionalCopyOnly'=>true,
            'clientIdentitiesIncluded'=>false,
            'officialClientAcceptance'=>false,
            'publicHostAvailable'=>false,
            'publishable'=>false,
            'releaseGate'=>'NO_GO',
            'items'=>array_values($items),
        ];
    }
}
