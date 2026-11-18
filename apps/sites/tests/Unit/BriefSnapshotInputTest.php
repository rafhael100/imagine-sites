<?php

declare(strict_types=1);

namespace Tests\Unit;

use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Production\BriefSnapshotInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BriefSnapshotInputTest extends TestCase
{
    private static function example(string $name): string
    {
        return file_get_contents(dirname(__DIR__, 4).
            '/contracts/portal-sites/candidates/brief-production-v1/examples/'.$name.'.synthetic.json');
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private static function sourceChain(string $sourceJson): string
    {
        $snapshot = json_decode(self::example('brief-snapshot'), true, 8, JSON_THROW_ON_ERROR);
        $snapshot['payload']['sizeBytes'] = strlen($sourceJson);
        $snapshot['payload']['sha256'] = hash('sha256', $sourceJson);
        return self::encode($snapshot);
    }

    private function denied(string $snapshot, string $source): void
    {
        try {
            BriefSnapshotInput::fromJson($snapshot, $source);
            self::fail('Invalid source/snapshot must not become an input object.');
        } catch (RuntimeException $error) {
            self::assertSame('BRIEF_SNAPSHOT_DENIED', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_original_json_and_independent_revision_metadata_are_retained_without_a_render_plan(): void
    {
        $source = json_decode(self::example('onepage-brief'), true, 8, JSON_THROW_ON_ERROR);
        $source['notes'] = "Primeira linha\r\ncafé 漢😀 <b>texto</b>";
        $sourceJson = "\r\n".self::encode($source)." \t";
        $snapshotJson = " \n".self::sourceChain($sourceJson)."\t";
        $snapshot = json_decode($snapshotJson, true, 8, JSON_THROW_ON_ERROR);
        $input = BriefSnapshotInput::fromJson($snapshotJson, $sourceJson);
        self::assertSame($snapshotJson, $input->snapshotJson);
        self::assertSame($sourceJson, $input->sourceJson);
        foreach (['operationId', 'idempotencyKey', 'tenantRef', 'siteRef', 'orderRef', 'contractRef',
            'contractRevision', 'productCode', 'sourceVersion', 'snapshotRef', 'expectedBriefRevision',
            'briefRevision'] as $field) {
            self::assertSame($snapshot[$field], $input->{$field});
        }
        self::assertSame(hash('sha256', $snapshotJson), $input->manifestSha256);
        self::assertSame(hash('sha256', $sourceJson), $input->payloadSha256);
        self::assertNotSame($input->sourceVersion, $input->briefRevision);
    }

    public function test_standalone_input_enforces_its_two_original_byte_budgets(): void
    {
        $source = self::example('onepage-brief');
        $source = str_pad($source, BriefSnapshotInput::MAX_SOURCE_BYTES);
        $snapshot = str_pad(self::sourceChain($source), BriefSnapshotInput::MAX_MANIFEST_BYTES);
        $input = BriefSnapshotInput::fromJson($snapshot, $source);
        self::assertSame(150000, strlen($input->sourceJson));
        self::assertSame(32768, strlen($input->snapshotJson));
        $this->denied($snapshot.' ', $source);
        $tooLarge = $source.' ';
        $this->denied(self::sourceChain($tooLarge), $tooLarge);
    }

    public function test_terminal_brief_revision_and_aggregate_source_version_are_independent(): void
    {
        $source = self::example('onepage-brief');
        $snapshot = json_decode(self::sourceChain($source), true, 8, JSON_THROW_ON_ERROR);
        $snapshot['expectedBriefRevision'] = 2147483646;
        $snapshot['briefRevision'] = 2147483647;
        $snapshot['sourceVersion'] = 9007199254740991;
        $input = BriefSnapshotInput::fromJson(self::encode($snapshot), $source);
        self::assertSame(2147483647, $input->briefRevision);
        self::assertSame(9007199254740991, $input->sourceVersion);
        $snapshot['sourceVersion']++;
        $this->denied(self::encode($snapshot), $source);
        $snapshot['sourceVersion'] = 1;
        $snapshot['expectedBriefRevision'] = 2147483647;
        $snapshot['briefRevision'] = 2147483648;
        $this->denied(self::encode($snapshot), $source);
    }

    public static function invalidProfile(): iterable
    {
        yield 'required company after ES trim' => ['company_name', "\u{FEFF}\u{2028}"];
        yield 'UTF16 astral bound' => ['company_name', str_repeat('😀', 81)];
        yield 'escaped NUL' => ['services', "Texto\0privado"];
        yield 'ASCII scheme required' => ['current_site', 'httpſ://example.invalid'];
        yield 'truthy material flag' => ['logo_unavailable', 'true'];
        yield 'material not supported' => ['manual_unavailable', false];
    }

    #[DataProvider('invalidProfile')]
    public function test_direct_input_rechecks_the_rich_profile_with_correct_hashes(string $field, mixed $value): void
    {
        $source = json_decode(self::example('onepage-brief'), true, 8, JSON_THROW_ON_ERROR);
        $source[$field] = $value;
        $sourceJson = self::encode($source);
        $this->denied(self::sourceChain($sourceJson), $sourceJson);
    }

    public function test_direct_input_rejects_noncanonical_tokens_and_duplicate_decoded_keys(): void
    {
        $source = self::example('onepage-brief');
        $snapshot = self::sourceChain($source);
        $this->denied(substr($snapshot, 0, 1).'"site\u0052ef":"11111111-1111-4111-8111-111111111111",'.
            substr($snapshot, 1), $source);
        $this->denied(str_replace('"briefRevision":1', '"briefRevision":1e0', $snapshot), $source);
        $badSource = substr($source, 0, 1).'"company\u005fname":"Duplicado",'.substr($source, 1);
        $this->denied(self::sourceChain($badSource), $badSource);
    }

    public function test_snapshot_selection_validates_the_whole_plan_and_only_exposes_the_exact_read_selector(): void
    {
        $planJson = self::example('brief-render-plan');
        $plan = json_decode($planJson, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame([
            'siteRef' => $plan['siteRef'], 'snapshotRef' => $plan['snapshot']['snapshotRef'],
            'briefRevision' => $plan['snapshot']['briefRevision'],
            'manifestSha256' => $plan['snapshot']['manifestSha256'],
        ], BriefRenderProvenance::snapshotSelection($planJson));
        $invalid = [];
        $copy = $plan; $copy['renderInput']['mediaType'] = 'text/html'; $invalid[] = self::encode($copy);
        $copy = $plan; $copy['renderInput']['sizeBytes'] = 32769; $invalid[] = self::encode($copy);
        $copy = $plan; $copy['expectedArtifact']['sha256'] = str_repeat('A', 64); $invalid[] = self::encode($copy);
        $copy = $plan; $copy['releaseRef'] = '../path'; $invalid[] = self::encode($copy);
        $copy = $plan; $copy['authorityVerified'] = true; $invalid[] = self::encode($copy);
        $compact = self::encode($plan);
        $invalid[] = str_replace('"briefRevision":1', '"briefRevision":1.0', $compact);
        $invalid[] = '{"site\u0052ef":"11111111-1111-4111-8111-111111111111",'.substr($compact, 1);
        foreach ($invalid as $json) {
            try {
                BriefRenderProvenance::snapshotSelection($json);
                self::fail('Incomplete or invalid plan must not reach snapshot lookup.');
            } catch (RuntimeException $error) {
                self::assertSame('LOCAL_RENDER_DENIED', $error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
    }
}
