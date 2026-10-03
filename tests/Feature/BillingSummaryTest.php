<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Reports\BillingSummary;
use Tests\TestCase;

final class BillingSummaryTest extends TestCase
{
    public function test_a_summary_heading_inside_fenced_code_is_not_the_section(): void
    {
        $body = "# March\n\n```md\n## Billing summary\nOpening balance: 0h\n```\n\nThe end.\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::MISSING, $result['status']);
        $this->assertSame($body, $result['body']);
    }

    public function test_the_real_section_is_found_after_a_fenced_one_and_the_fence_is_kept(): void
    {
        $body = "```md\n## Billing summary\n```\n\n## Billing summary\n\nOpening balance: 0h\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::REWRITTEN, $result['status']);
        $this->assertStringStartsWith("```md\n## Billing summary\n```\n\n## Billing summary\n\nOpening balance for the month: +4h", $result['body']);
    }

    public function test_two_summary_sections_are_left_alone(): void
    {
        $body = "## Billing summary\n\nOpening balance: 0h\n\n## Billing summary\n\nClosing balance: +9h\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::AMBIGUOUS, $result['status']);
        $this->assertSame($body, $result['body']);
    }

    public function test_subheadings_of_the_section_are_replaced_with_it(): void
    {
        $body = "## Billing summary\n\nOpening balance: 0h\n\n### Balance\n\nClosing balance: +9h\n\n## Contact\n\nmail\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::REWRITTEN, $result['status']);
        $this->assertStringNotContainsString('+9h', $result['body']);
        $this->assertStringNotContainsString('### Balance', $result['body']);
        $this->assertStringContainsString('Opening balance for next month: +13h', $result['body']);
        $this->assertStringEndsWith("\n\n## Contact\n\nmail\n", $result['body']);
    }

    public function test_a_setext_heading_after_the_section_ends_it_and_survives(): void
    {
        $body = "## Billing summary\n\nOpening balance: 0h\n\nContact\n-------\n\nmail@example.test\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::REWRITTEN, $result['status']);
        $this->assertStringEndsWith("Opening balance for next month: +13h\n\nContact\n-------\n\nmail@example.test\n", $result['body']);
    }

    public function test_a_setext_summary_heading_is_found(): void
    {
        $body = "# March\n\nBilling summary\n---------------\n\nOpening balance: 0h\n";

        $result = BillingSummary::replaceIn($body, 4, 12, 3, null);

        $this->assertSame(BillingSummary::REWRITTEN, $result['status']);
        $this->assertSame("# March\n\n## Billing summary\n\nOpening balance for the month: +4h\n\nPool for the month: +12h\n\nUsed in the month: \u{2212}3h\n\nOpening balance for next month: +13h\n", $result['body']);
    }

    public function test_a_summary_heading_in_an_indented_code_block_is_not_the_section(): void
    {
        $body = "Intro\n\n    ## Billing summary\n    Opening balance: 0h\n";

        $this->assertSame(BillingSummary::MISSING, BillingSummary::replaceIn($body, 4, 12, 3, null)['status']);
    }
}
