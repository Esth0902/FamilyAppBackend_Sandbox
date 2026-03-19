<?php

namespace Tests\Unit\Domain\Budget\ValueObjects;

use App\Domain\Budget\ValueObjects\BudgetComment;
use PHPUnit\Framework\TestCase;

class BudgetCommentTest extends TestCase
{
    public function test_it_parses_prefixed_stored_comment(): void
    {
        $comment = BudgetComment::fromStored(
            "[budget-meta]request_kind=reimbursement;payout_mode=immediate\nRemboursement cantine"
        );

        $this->assertSame('Remboursement cantine', $comment->displayComment);
        $this->assertSame(BudgetComment::REQUEST_KIND_REIMBURSEMENT, $comment->requestKind);
        $this->assertSame(BudgetComment::PAYOUT_MODE_IMMEDIATE, $comment->payoutMode);
    }

    public function test_it_serializes_to_prefixed_storage_string_when_meta_is_needed(): void
    {
        $comment = new BudgetComment(
            displayComment: 'Remboursement transport',
            requestKind: BudgetComment::REQUEST_KIND_ADVANCE,
            payoutMode: BudgetComment::PAYOUT_MODE_IMMEDIATE,
        );

        $this->assertSame(
            "[budget-meta]request_kind=advance;payout_mode=immediate\nRemboursement transport",
            $comment->toStoredString()
        );
    }

    public function test_it_keeps_plain_comment_without_prefix_for_default_advance(): void
    {
        $comment = new BudgetComment('Besoin urgent', BudgetComment::REQUEST_KIND_ADVANCE, null);

        $this->assertSame('Besoin urgent', $comment->toStoredString());
    }
}
