<?php

namespace Tests\Unit;

use App\Enums\RequestStatus;
use PHPUnit\Framework\TestCase;

class RequestStatusTest extends TestCase
{
    public function test_normal_path_is_allowed(): void
    {
        $path = ['submitted', 'approved', 'queued', 'generating', 'generated', 'verified', 'released', 'acknowledged', 'archived'];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                RequestStatus::from($path[$i])->canMoveTo(RequestStatus::from($path[$i + 1])),
                "{$path[$i]} -> {$path[$i + 1]} should be allowed",
            );
        }
    }

    public function test_skipping_steps_is_refused(): void
    {
        $this->assertFalse(RequestStatus::Submitted->canMoveTo(RequestStatus::Generated));
        $this->assertFalse(RequestStatus::Generated->canMoveTo(RequestStatus::Released));
        $this->assertFalse(RequestStatus::Archived->canMoveTo(RequestStatus::Queued));
    }

    public function test_review_can_only_requeue_or_cancel(): void
    {
        $this->assertSame([RequestStatus::Queued, RequestStatus::Cancelled], RequestStatus::NeedsReview->allowedNext());
    }

    public function test_has_certificate_flags(): void
    {
        $this->assertTrue(RequestStatus::Generated->hasCertificate());
        $this->assertFalse(RequestStatus::Retrying->hasCertificate());
    }
}
