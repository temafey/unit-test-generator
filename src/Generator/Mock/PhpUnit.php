<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator\Mock;

use MicroModule\UnitTestGenerator\Generator\AbstractGenerator;
use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Exception\MockNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\Helper\ReturnTypeNotFoundException;
use MicroModule\UnitTestGenerator\Generator\MockGenerator;
use ReflectionClass;
use ReflectionException;

/**
 * Class PhpUnit.
 *
 * @SuppressWarnings(PHPMD)
 */
class PhpUnit implements MockInterface
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
     * @param MockGenerator   $mockGenerator
     * @param string          $className
     * @param ReflectionClass $reflection
     * @param int             $level
     * @param string          $mockName
     * @param ReflectionClass $parentClass
     * @param mixed[]         $parentNamespaces
     *
     * @return mixed[]
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
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
        if (
            $level > 2 ||
            in_array(explode('\\', trim($className, '\\'))[0], AbstractGenerator::EXCLUDE_DECLARING_CLASSES, true)
        ) {
            $mock = '$this->getMockBuilder(\'' . $className . '\')
           ->disableOriginalConstructor()
           ->getMock();';

            return [$mock, '', ''];
        }

        [$extendedClasses, $extendedTraits] = $this->getParentClassesAndTraits($reflection);
        $methods = $reflection->getMethods();
        $refMethods = [];
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
            $mockArgs[] = '\'' . $refMethodName . '\' => \'\'';
            $mockTimes[] = '\'' . $refMethodName . '\' => 0';

            if (
                in_array(explode('\\', trim($refMethodDeclaringClassName, '\\'))[0], AbstractGenerator::EXCLUDE_DECLARING_CLASSES_FOR_METHODS) ||
                in_array(strtolower($refMethodName), AbstractGenerator::EXCLUDE_DECLARING_METHODS)
            ) {
                continue;
            }
            $returnType = null;

            if (in_array($refMethodName, self::METHODS_RETURN_SELF, true)) {
                $returnType = $className;
            } else {
                $returnType = $refMethod->getReturnType();

                if (null !== $returnType) {
                    $returnType = $returnType->getName();
                }
            }

            if (!$returnType) {
                try {
                    $returnType = $this->getReturnFromAnnotation($refMethod);
                } catch (ReturnTypeNotFoundException $e) {
                    if ($this->returnTypeNotFoundThrowable) {
                        throw $e;
                    }
                    $returnType = DataTypeInterface::TYPE_MIXED;
                }
            }

            switch ($returnType) {
                case DataTypeInterface::TYPE_INT:
                case DataTypeInterface::TYPE_INTEGER:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(1));';

                    break;

                case DataTypeInterface::TYPE_FLOAT:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(1.5));';

                    break;

                case DataTypeInterface::TYPE_MIXED:
                case DataTypeInterface::TYPE_STRING:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(\'testMock' . $refMethodName . '\'));';

                    break;

                case DataTypeInterface::TYPE_BOOL:
                case DataTypeInterface::TYPE_BOOLEAN:
                case DataTypeInterface::TYPE_BOOL_TRUE:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(true));';

                    break;

                case DataTypeInterface::TYPE_BOOL_FALSE:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(false));';

                    break;

                case DataTypeInterface::TYPE_NULL:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(null));';

                    break;

                case DataTypeInterface::TYPE_VOID:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')';

                    break;

                case DataTypeInterface::TYPE_ARRAY:
                case DataTypeInterface::TYPE_ARRAY_MIXED:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue([\'test1\', \'test2\', \'test3\']));';

                    break;

                case DataTypeInterface::TYPE_CLOSURE:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue(function () { return true; }));';

                    break;

                case DataTypeInterface::TYPE_SELF:
                case DataTypeInterface::TYPE_THIS:
                case DataTypeInterface::TYPE_STATIC:
                case $className:
                    $mockMethod = '$' . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnSelf());';

                    break;

                default:
                    if (
                        in_array($returnType, $extendedClasses, true) ||
                        in_array($returnType, $extendedTraits, true)
                    ) {
                        $mockMethod = '$' . $mockName .
                            '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnSelf());';

                        break;
                    }
                    $filename = $reflection->getFileName();

                    if (false === $filename) {
                        throw new FileNotExistsException(sprintf('File \'%s\' does not exists.', $reflection->getName()));
                    }
                    $refNamespaces = $this->getNamespacesFromSource($filename);
                    $namespacesFromParentClassesAndTraits = $this->getNamespacesFromParentClassesAndTraits($reflection);
                    $namespaces = array_merge($parentNamespaces, $refNamespaces, $namespacesFromParentClassesAndTraits);
                    $refMockName = $mockGenerator->addMock($returnType, $reflection, $refMethod, $namespaces, $level, $parentClass);
                    $mockStructure = $mockGenerator->getMock($refMockName);

                    if (null === $mockStructure) {
                        throw new MockNotExistsException(sprintf('Mock \'%s\' does not exist.', $refMockName));
                    }
                    $mockMethodName = $mockStructure['mockMethodName'];
                    $mockShortName = $mockStructure['mockShortName'];
                    $mockMethod = "\r\n\t\t$" . $mockShortName . ' = $this->' . $mockMethodName . "();\r\n\t\t$" . $mockName .
                        '->expects($this->any())->method(\'' . $refMethodName . '\')->will($this->returnValue($' . $mockShortName . '));';

                    break;
            }
            $mockMethods[] = $mockMethod;
            $refMethods[] = '\'' . $refMethodName . '\'';
        }
        $mock = '$this->getMockBuilder(\'' . $className . '\')
           ->setMethods([' . implode(', ', $refMethods) . '])
           ->disableOriginalConstructor()
           ->getMock();';

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
        return self::METHOD_TEMPLATE_PHPUNIT;
    }

    /**
     * Return mock method return interface.
     *
     * @return string
     */
    public function getReturnMockInterface(): string
    {
        return self::METHOD_RETURN_MOCK_INTERFACE_PHPUNIT;
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
