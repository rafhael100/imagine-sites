<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-291 — export only in CI in-process testing. Nothing is served or published.
 * The logo becomes a data URI ONLY in ephemeral screenshot fixtures so Chrome
 * never needs an HTTP route, CDN, or live DNS.
 */
final class SyntheticImagineShellBrowserExportTest extends TestCase
{
    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    public function test_export_four_private_shell_snapshots_only_in_explicit_ci():void
    {
        if(getenv('SITES_F291_BROWSER_EXPORT')!=='1') {
            self::assertTrue(true, 'No CI opt-in; never create screenshot fixture');
            return;
        }
        if(getenv('GITHUB_ACTIONS')!=='true' ||
            !app()->environment('testing')) {
            throw new \RuntimeException('F1-291 export only in isolated CI testing');
        }

        $base=base_path('storage/app/private/f291-imagine-shell');
        if(file_exists($base) || is_link($base) ||
            !mkdir($base,0700,true)) {
            throw new \RuntimeException('F1-291 needs a fresh private directory');
        }
        $logoPath=public_path('assets/logo-imagine.png');
        if(is_link($logoPath) || !is_file($logoPath)) {
            throw new \RuntimeException('F1-291 original logo missing');
        }
        $logo=(string)file_get_contents($logoPath);
        if(hash('sha1','blob '.strlen($logo)."\0".$logo)!==
            '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77') {
            throw new \RuntimeException('F1-291 logo differs from original');
        }

        $byDevice=[
            'desktop'=>'desktop',
            'tablet'=>'desktop',
            'mobile'=>'mobile',
            'narrow'=>'mobile',
        ];
        $files=[];
        foreach($byDevice as $label=>$device) {
            $res=$this->get('/_lab/sites/v1/review?product=onepage'.
                '&device='.$device.'&preset=classic')->assertOk();
            $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
            $html=(string)$res->getContent();
            if(substr_count($html,'src="/assets/logo-imagine.png"')!==1 ||
                !str_contains($html,'sandbox=""') ||
                !str_contains($html,'noindex, nofollow') ||
                str_contains($html,'<form') || str_contains($html,'<script')) {
                throw new \RuntimeException('F1-291 unsafe review fixture');
            }

            // Inline ONLY the exact permitted local logo for file-free CDP.
            $html=str_replace(
                'src="/assets/logo-imagine.png"',
                'src="data:image/png;base64,'.base64_encode($logo).'"',
                $html
            );
            $name=$label.'.html';
            $target=$base.'/'.$name;
            if(file_put_contents($target,$html,LOCK_EX)!==strlen($html)) {
                throw new \RuntimeException('F1-291 could not write screenshot input');
            }
            $files[$name]=[
                'sha256'=>hash('sha256',$html),
                'bytes'=>strlen($html),
                'device'=>$device,
            ];
        }
        $manifest=[
            'schema'=>'sites.f291.admin-shell-screenshots.v1',
            'syntheticOnly'=>true,
            'publishable'=>false,
            'officialClientAcceptance'=>false,
            'hasClientData'=>false,
            'originalLogoBlob'=>'678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77',
            'files'=>$files,
        ];
        $json=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        file_put_contents($base.'/manifest.json',$json."\n",LOCK_EX);
        self::assertCount(4,$files);
    }
}
