<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Content\Delivery\ReferenceTargetResolver as OldReferenceTargetResolver;
use App\Content\Search\ContentReindexerInterface as OldContentReindexer;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Context\LemmaContext;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Lemma\Contracts\Search\ContentReindexer;

final class ContractConformanceTest extends LemmaTestCase
{
    /**
     * Contracts that core binds to a concrete implementation (Tasks 6–8). These MUST
     * resolve from the container.
     *
     * @return list<array{0:class-string}>
     */
    public static function boundContractProvider(): array
    {
        return [
            [ContentWriter::class],
            [ContentDeliveryReader::class],
            [LemmaContext::class],
        ];
    }

    /** @dataProvider boundContractProvider */
    public function testBoundContractResolvesToAConcreteImplementation(string $contract): void
    {
        $impl = $this->container()->get($contract);
        self::assertInstanceOf($contract, $impl);
        self::assertFalse((new \ReflectionClass($impl))->isAbstract());
    }

    /**
     * Promoted seams that are intentionally OPTIONAL/unbound in core (a pack binds them
     * later): ContentReindexer (search) and ReferenceTargetResolver. We don't require
     * them to resolve — only that the old engine interface now extends the new contract,
     * so existing implementors satisfy the contract for free.
     */
    public function testPromotedInterfacesExtendTheirContracts(): void
    {
        self::assertTrue(is_subclass_of(OldContentReindexer::class, ContentReindexer::class));
        self::assertTrue(is_subclass_of(OldReferenceTargetResolver::class, ReferenceTargetResolver::class));
    }
}
