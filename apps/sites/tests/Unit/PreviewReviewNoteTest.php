<?php

declare(strict_types=1);

namespace Tests\Unit;

use Imagine\Sites\Production\PreviewReviewInput;
use Imagine\Sites\Production\PrivatePreviewWorkspace;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PreviewReviewNoteTest extends TestCase
{
    public function test_substantive_notes_keep_their_exact_text_for_both_decisions(): void
    {
        $note = "\u{00A0} Ajuste a chamada principal.\r\n\r\nMantenha a ordem dos benefícios: ação, confiança.\t ";
        foreach (['changes_requested', 'approved_internal'] as $decision) {
            self::assertSame($note, PreviewReviewInput::note($decision, $note));
        }
    }

    public function test_html_like_feedback_remains_plain_text_for_the_view_to_escape(): void
    {
        $note = '</textarea><script data-note="literal">exemplo</script><strong>não formatar</strong>';
        self::assertSame($note, PreviewReviewInput::note('changes_requested', $note));
        self::assertSame($note, PreviewReviewInput::note('approved_internal', $note));
    }

    public function test_exact_unicode_character_limit_counts_codepoints_instead_of_bytes(): void
    {
        foreach ([str_repeat('á', 2000), str_repeat('😀', 2000), str_repeat("e\u{0301}", 1000)] as $note) {
            self::assertSame(2000, mb_strlen($note, 'UTF-8'));
            self::assertSame($note, PreviewReviewInput::note('changes_requested', $note));
        }
        self::assertSame(8000, strlen(str_repeat('😀', 2000)));
        self::assertSame(PreviewReviewInput::MAX_NOTE_CHARACTERS, 2000);
        self::assertSame(PreviewReviewInput::MAX_NOTE_BYTES, 8000);
    }

    public function test_unicode_white_space_only_is_optional_for_approval_and_required_for_changes(): void
    {
        $points = array_merge(range(0x0009, 0x000D), [0x0020, 0x0085, 0x00A0, 0x1680],
            range(0x2000, 0x200A), [0x2028, 0x2029, 0x202F, 0x205F, 0x3000]);
        $whitespace = array_map(static fn (int $point): string => mb_chr($point, 'UTF-8'), $points);
        foreach ([null, '', ...$whitespace, implode('', $whitespace), str_repeat(' ', 2000)] as $note) {
            self::assertNull(PreviewReviewInput::note('approved_internal', $note));
            $this->assertRejected('changes_requested', $note);
        }
    }

    public function test_length_is_checked_before_optional_blank_normalization(): void
    {
        foreach (['changes_requested', 'approved_internal'] as $decision) {
            foreach ([str_repeat(' ', 2001), str_repeat('á', 2001), str_repeat('😀', 2001)] as $note) {
                $this->assertRejected($decision, $note);
            }
        }
    }

    public function test_invalid_utf8_nul_and_non_string_notes_are_not_coerced(): void
    {
        foreach (['changes_requested', 'approved_internal'] as $decision) {
            foreach (["invalid\xFF", "bad\xC0\xAF", "\0", "Texto\0oculto", 123, false, [], ['note' => 'Texto'], new \stdClass()] as $note) {
                $this->assertRejected($decision, $note);
            }
        }
    }

    public function test_unknown_decision_is_not_promoted_by_a_valid_note(): void
    {
        foreach (['approved', 'published', 'client_accepted', ''] as $decision) {
            $this->assertRejected($decision, 'Nota editorial de exemplo.');
        }
    }

    public function test_workspace_rejects_invalid_feedback_before_using_a_connection(): void
    {
        $db = new class extends PDO {
            public int $calls = 0;
            public function __construct() {}
            public function inTransaction(): bool
            {
                ++$this->calls;
                throw new \RuntimeException('No SQL authority exists in this input test');
            }
        };
        $workspace = new PrivatePreviewWorkspace($db);
        foreach ([null, "\u{00A0}\u{3000}", str_repeat('😀', 2001), "invalid\xFF"] as $note) {
            try {
                $workspace->review('11111111-1111-4111-8111-111111111111',
                    'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, str_repeat('a', 64), 'changes_requested', $note);
                self::fail('Invalid feedback reached the workspace');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid private preview review note', $error->getMessage());
            }
        }
        self::assertSame(0, $db->calls);
    }

    private function assertRejected(string $decision, mixed $note): void
    {
        try {
            PreviewReviewInput::note($decision, $note);
            self::fail('Invalid review note accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Invalid private preview review note', $error->getMessage());
        }
    }
}
