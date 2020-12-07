<?php

declare(strict_types=1);


namespace MicroModule\UnitTestGenerator\Generator\Preprocessor;

use ReflectionClass;
use ReflectionMethod;

/**
 * Interface PreprocessorInterface.
 */
interface PreprocessorInterface
{
    /**
     * Validate is should method be tested.
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     *
     * @return bool
     */
    public function isShouldBeTested(ReflectionClass $reflectionClass, ReflectionMethod $reflectionMethod): bool;

    /**
     * Exec preprocessor logic.
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     * @param string $testMethodName
     * @param string|null $testMethodBody
     */
    public function process(ReflectionClass $reflectionClass, ReflectionMethod $reflectionMethod, string &$testMethodName, ?string &$testMethodBody): void;
}
