<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator\Mock;

use MicroModule\UnitTestGenerator\Generator\MockGenerator;
use ReflectionClass;

/**
 * Interface MockInterface.
 */
interface MockInterface
{
    public const METHODS_RETURN_SELF = [
        'fromNative',
    ];

    public const METHOD_TEMPLATE_MOCKERY = 'MockMethodMockery';
    public const METHOD_TEMPLATE_PHPUNIT = 'MockMethodPhpUnit';
    public const METHOD_RETURN_MOCK_INTERFACE_MOCKERY = 'Mockery\MockInterface';
    public const METHOD_RETURN_MOCK_INTERFACE_PHPUNIT = 'PHPUnit\Framework\MockObject\MockObject';

    public const METHOD_NAME_PREFIX = 'create';

    /**
     * Generate mock code.
     *
     * @param MockGenerator   $mockGenerator
     * @param string          $className
     * @param ReflectionClass $reflection
     * @param int             $level
     * @param string          $mockName
     * @param ReflectionClass $parentClass
     * @param mixed[]         $parentNamespaces
     *
     * @return mixed[]
     */
    public function generateMockCode(
        MockGenerator $mockGenerator,
        string $className,
        ReflectionClass $reflection,
        int $level,
        string $mockName,
        ReflectionClass $parentClass,
        array $parentNamespaces = []
    ): array;

    /**
     * Return mock method template name.
     *
     * @return string
     */
    public function getMockMethodTemplate(): string;

    /**
     * Return mock method return interface.
     *
     * @return string
     */
    public function getReturnMockInterface(): string;
}
