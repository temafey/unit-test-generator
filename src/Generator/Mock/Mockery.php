<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator\Mock;

use MicroModule\UnitTestGenerator\Generator\AbstractGenerator;
use MicroModule\UnitTestGenerator\Generator\DataTypeInterface;
use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Exception\MockFinalClassException;
use MicroModule\UnitTestGenerator\Generator\Exception\MockNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\Helper\ReturnTypeNotFoundException;
use MicroModule\UnitTestGenerator\Generator\MockGenerator;
use DG\BypassFinals;
use ReflectionClass;
use ReflectionException;

/**
 * Class Mockery.
 *
 * @SuppressWarnings(PHPMD)
 */
class Mockery implements MockInterface
{
    use CodeHelper;

    /**
     * Is throw exception if return type was not found.
     *
     * @var bool
     */
    private $returnTypeNotFoundThrowable = false;

    /**
     * Method names, that should be excluded.
     *
     * @var string[]
     */
    private static $excludeMethods = [];

    /**
     * Generate mock code.
     *
     * @param MockGenerator $mockGenerator
     * @param string $className
     * @param ReflectionClass $reflection
     * @param int $level
     * @param string $mockName
     * @param ReflectionClass $parentClass
     * @param mixed[] $parentNamespaces
     *
     * @return mixed[]
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     * @throws MockFinalClassException
     * @throws MockNotExistsException
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    public function generateMockCode(
        MockGenerator $mockGenerator,
        string $className,
        ReflectionClass $reflection,
        int $level,
        string $mockName,
        ReflectionClass $parentClass,
        array $parentNamespaces = []
    ): array {
        if ($reflection->isFinal() && !class_exists(BypassFinals::class)) {
            throw new MockFinalClassException('Final class \'' . $reflection->getName() . '\' cannot be mocked, use interface instead.');
        }

        [$extendedClasses, $extendedTraits] = $this->getParentClassesAndTraits($reflection);
        $methods = $reflection->getMethods();
        $mockMethods = [];
        $mockArgs = [];
        $mockTimes = [];

        foreach ($methods as $refMethod) {
            $refMethodName = $refMethod->getName();

            if (
                $refMethod->isConstructor() ||
                $refMethod->isDestructor() ||
                $refMethod->isProtected() ||
                $refMethod->isPrivate() ||
                $refMethod->isStatic() ||
                in_array($className, self::$excludeMethods, true) ||
                in_array($refMethodName, self::$excludeMethods, true) ||
                (isset(self::$excludeMethods[$className]) && in_array($refMethodName, self::$excludeMethods[$className], true)) ||
                (
                    isset(self::$excludeMethods[$className]['all_except']) &&
                    !in_array($refMethodName, self::$excludeMethods[$className]['all_except'], true)
                )
            ) {
                continue;
            }
            $refMethodDeclaringClassName = $refMethod->getDeclaringClass()->getName();

            if (
                in_array(strtolower($refMethodName), AbstractGenerator::EXCLUDE_DECLARING_METHODS, true) ||
                in_array(explode('\\', trim($refMethodDeclaringClassName, '\\'))[0], AbstractGenerator::EXCLUDE_DECLARING_CLASSES_FOR_METHODS, true)
            ) {
                continue;
            }
            $returnType = null;
            $mockArgs[] = '\'' . $refMethodName . '\' => \'\'';
            $mockTimes[] = '\'' . $refMethodName . '\' => 0';

            if (in_array($refMethodName, self::METHODS_RETURN_SELF, true)) {
                $returnType = $className;
            } else {
                $returnType = $refMethod->getReturnType();

                if (
                    class_exists('ReflectionUnionType') &&
                    $className instanceof \ReflectionUnionType
                ) {
                    // use only first return type
                    // @TODO return all return types
                    $returnTypes = $returnType->getTypes();
                    $returnType = array_shift($returnTypes);
                }
                if ($returnType instanceof \ReflectionNamedType) {
                    $returnType = $returnType->getName();
                }
            }

            if (!$returnType) {
                try {
                    $returnType = $this->getReturnFromReflectionMethodAnnotation($refMethod);
                } catch (ReturnTypeNotFoundException $e) {
                    if ($this->returnTypeNotFoundThrowable) {
                        throw $e;
                    }
                    $returnType = DataTypeInterface::TYPE_MIXED;
                }
            }
            if (strpos($returnType, '<')) {
                //@TODO build mock after analyze <> structure.
                $returnType = preg_replace("/\<[^)]+\>/",'', $returnType);
            }

            $mockMethod = 'if (array_key_exists(\'' . $refMethodName . '\', $mockTimes)) {
            $mockMethod = $mock
                ->shouldReceive(\'' . $refMethodName . '\');
                
            if (null === $mockTimes[\'' . $refMethodName . '\']) {
                $mockMethod->zeroOrMoreTimes();
            } elseif (is_array($mockTimes[\'' . $refMethodName . '\'])) {
                $mockMethod->times($mockTimes[\'' . $refMethodName . '\'][\'times\']);
            } else {
                $mockMethod->times($mockTimes[\'' . $refMethodName . '\']);
            }' . "\r\n\t\t\t";

            switch ($returnType) {
                case DataTypeInterface::TYPE_INT:
                case DataTypeInterface::TYPE_INTEGER:
                case DataTypeInterface::TYPE_FLOAT:
                case DataTypeInterface::TYPE_MIXED:
                case DataTypeInterface::TYPE_STRING:
                case DataTypeInterface::TYPE_BOOL:
                case DataTypeInterface::TYPE_BOOLEAN:
                case DataTypeInterface::TYPE_BOOL_TRUE:
                case DataTypeInterface::TYPE_BOOL_FALSE:
                    $mockMethod .= '$mockMethod->andReturn($mockArgs[\'' . $refMethodName . '\']);';

                    break;

                case DataTypeInterface::TYPE_NULL:
                    $mockMethod .= '$mockMethod->andReturnNull();';

                    break;

                case DataTypeInterface::TYPE_VOID:
                    $mockMethod .= '';

                    break;

                case DataTypeInterface::TYPE_ARRAY:
                    $returnType = $this->getReturnFromReflectionMethodAnnotation($refMethod, false, true);

                    if (null === $returnType || $returnType === 'mixed[]') {
                        $returnType = DataTypeInterface::TYPE_ARRAY;
                    } else {
                        $returnType = $this->getClassNameFromComplexAnnotationName($returnType, $refMethod->getDeclaringClass());
                    }

                    if (class_exists($returnType) || interface_exists($returnType)) {
                        $refNamespaces = [];

                        if (!$reflection->isInternal()) {
                            $filename = $reflection->getFileName();

                            if (false === $filename) {
                                throw new FileNotExistsException(sprintf('File \'%s\' does not exist.', $reflection->getName()));
                            }
                            $refNamespaces = $this->getNamespacesFromSource($filename);
                        }
                        $namespacesFromParentClassesAndTraits = $this->getNamespacesFromParentClassesAndTraits($reflection);
                        $namespaces = array_merge($parentNamespaces, $refNamespaces, $namespacesFromParentClassesAndTraits);
                        $refMockName = $mockGenerator->addMock($returnType, $reflection, $refMethod, $namespaces, $level, $parentClass);
                        $mockStructure = $mockGenerator->getMock($refMockName);

                        if (null === $mockStructure) {
                            throw new MockNotExistsException(sprintf('Mock \'%s\' does not exist.', $refMockName));
                        }
                        $mockMethodName = $mockStructure['mockMethodName'];
                        $mockShortName = $mockStructure['mockShortName'];
                        $mockMethod .= '$' . $mockShortName . 's = [];';
                        $mockMethod .= "\n\r\n\t\t\t" . 'foreach ($mockArgs[\'' . $refMethodName . '\'] as $i => $' . $mockShortName . ') {';
                        $mockMethod .= "\r\n\t\t\t\t$" . $mockShortName . 's[] = $this->' . $mockMethodName .
                            '($' . $mockShortName . ', $mockTimes[\'' . $refMethodName . '\'][\'mockTimes\'][$i]);' . "\r\n\t\t\t}\r\n\t\t\t" .
                            '$mockMethod->andReturn($' . $mockShortName . 's);';

                        break;
                    }

                case DataTypeInterface::TYPE_ARRAY_MIXED:
                    $mockMethod .= '$mockMethod->andReturn($mockArgs[\'' . $refMethodName . '\']);';

                    break;

                case DataTypeInterface::TYPE_CLOSURE:
                case DataTypeInterface::TYPE_CALLABLE:
                    $mockMethod .= '$mockMethod->andReturn(function () { return true; });';

                    break;

                case DataTypeInterface::TYPE_SELF:
                case DataTypeInterface::TYPE_STATIC:
                case DataTypeInterface::TYPE_THIS:
                case $className:
                    $mockMethod .= '$mockMethod->andReturnSelf();';

                    break;

                case DataTypeInterface::TYPE_OBJECT:
                    $mockMethod .= '$mockMethod->andReturn(new class {});';

                    break;

                default:
                    if (
                        in_array($returnType, $extendedClasses, true) ||
                        in_array($returnType, $extendedTraits, true)
                    ) {
                        $mockMethod .= '$mockMethod->andReturnSelf();';

                        break;
                    }
                    $refNamespaces = [];

                    if (!$reflection->isInternal()) {
                        $filename = $reflection->getFileName();

                        if (false === $filename) {
                            throw new FileNotExistsException(sprintf('File \'%s\' does not exist.', $reflection->getName()));
                        }
                        $refNamespaces = $this->getNamespacesFromSource($filename);
                    }
                    $namespacesFromParentClassesAndTraits = $this->getNamespacesFromParentClassesAndTraits($reflection);
                    $namespaces = array_merge($parentNamespaces, $refNamespaces, $namespacesFromParentClassesAndTraits);
                    $refMockName = $mockGenerator->addMock($returnType, $reflection, $refMethod, $namespaces, $level, $parentClass);
                    $mockStructure = $mockGenerator->getMock($refMockName);

                    if (null === $mockStructure) {
                        throw new MockNotExistsException(sprintf('Mock \'%s\' does not exist.', $refMockName));
                    }
                    $mockMethodName = $mockStructure['mockMethodName'];
                    $mockShortName = $mockStructure['mockShortName'];
                    $mockMethod .= "\r\n\t\t\t$" . $mockShortName . ' = $this->' . $mockMethodName .
                        '($mockArgs[\'' . $refMethodName . '\'], $mockTimes[\'' . $refMethodName . '\']);' . "\r\n\t\t\t" .
                        '$mockMethod->andReturn($' . $mockShortName . ');';

                    break;
            }
            $mockMethod .= "\r\n\t\t}\r\n";
            $mockMethods[] = $mockMethod;
        }
        $mock = '$mock = \Mockery::namedMock(\'Mock\\' . $className . '\', \\' . $className . '::class);';
        $mock .= "\r\n\r\n\t\t" . implode("\r\n\t\t", $mockMethods) . "\r\n";
        $mockArgs = '[' . implode(', ', $mockArgs) . ']';
        $mockTimes = '[' . implode(', ', $mockTimes) . ']';

        return [$mock, $mockArgs, $mockTimes];
    }

    /**
     * Return mock method template name.
     *
     * @return string
     */
    public function getMockMethodTemplate(): string
    {
        return self::METHOD_TEMPLATE_MOCKERY;
    }

    /**
     * Return mock method return interface.
     *
     * @return string
     */
    public function getReturnMockInterface(): string
    {
        return self::METHOD_RETURN_MOCK_INTERFACE_MOCKERY;
    }

    /**
     * Set method names, that should be excluded.
     *
     * @param string[] $excludeMethods
     */
    public static function setExcludeMethods(array $excludeMethods): void
    {
        self::$excludeMethods = $excludeMethods;
    }
}
