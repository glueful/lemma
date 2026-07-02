<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class WorkflowCapabilityTest extends LemmaTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.workflow'),
            'lemma.workflow must be registered and enabled by default',
        );
    }

    public function testSelfReviewConfigDefaultsFalse(): void
    {
        self::assertFalse((bool) config($this->appContext(), 'lemma_workflow.allow_self_review', null));
    }
}
