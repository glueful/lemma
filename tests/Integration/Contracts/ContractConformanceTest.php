<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Context\LemmaContext;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;

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
}
