<?php

namespace Tests\Unit\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Enums\OzonProductStatus;
use PHPUnit\Framework\TestCase;

class OzonEnumsTest extends TestCase
{
    public function test_all_enum_labels_are_non_empty_utf8_strings(): void
    {
        foreach ([...OzonProductStatus::cases(), ...OzonOperationStatus::cases(), ...OzonOperationType::cases()] as $case) {
            $this->assertNotSame('', trim($case->label()));
            $this->assertTrue(mb_check_encoding($case->label(), 'UTF-8'));
        }
    }

    public function test_product_status_business_guards(): void
    {
        foreach ([OzonProductStatus::Draft, OzonProductStatus::Ready, OzonProductStatus::Rejected, OzonProductStatus::Failed] as $status) {
            $this->assertTrue($status->isExportable());
        }
        foreach ([OzonProductStatus::Sending, OzonProductStatus::Processing, OzonProductStatus::Published] as $status) {
            $this->assertFalse($status->isExportable());
        }
        $this->assertTrue(OzonProductStatus::Published->isPublished());
        $this->assertTrue(OzonProductStatus::Published->isCommercialSyncAllowed());
        $this->assertTrue(OzonProductStatus::Published->isTerminal());
        $this->assertFalse(OzonProductStatus::Accepted->isCommercialSyncAllowed());
    }

    public function test_operation_status_active_and_finished_guards(): void
    {
        $this->assertTrue(OzonOperationStatus::Pending->isActive());
        $this->assertTrue(OzonOperationStatus::Running->isActive());
        $this->assertFalse(OzonOperationStatus::Completed->isActive());
        $this->assertTrue(OzonOperationStatus::Completed->isFinished());
        $this->assertTrue(OzonOperationStatus::Failed->isFinished());
    }
}
