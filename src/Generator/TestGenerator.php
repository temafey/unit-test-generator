<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Exception\InvalidClassnameException;
use MicroModule\UnitTestGenerator\Generator\Exception\InvalidMockTypeException;
use MicroModule\UnitTestGenerator\Generator\Exception\MockNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\Helper\ReturnTypeNotFoundException;
use MicroModule\UnitTestGenerator\Generator\Preprocessor\PreprocessorInterface;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

/**
 * Generator for test class skeletons from classes.
 *
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 *
 * @SuppressWarnings(PHPMD)
 */
class TestGenerator extends AbstractGenerator
{
    use CodeHelper;

    /**
     * @var mixed[]
     */
    protected $methodNameCounter = [];

    /**
     * Method setter patterns.
     *
     * @var string[]
     */
    protected $setters = ['set', 'append', 'push', 'send', 'write'];

    /**
     * Method getter patterns.
     *
     * @var string[]
     */
    protected $getters = ['get', 'find', 'fetch', 'pop', 'read'];

    /**
     * Method checker patterns.
     *
     * @var string[]
     */
    protected $checkers = ['is', 'exist', 'has'];

    /**
     * Mock code generator.
     *
     * @var MockGenerator
     */
    private $mockGenerator;

    /**
     * Data providers code generator.
     *
     * @var DataProviderGenerator
     */
    protected $dataProviderGenerator;

    /**
     * Is throw exception if return type was not found.
     *
     * @var bool
     */
    private $returnTypeNotFoundThrowable = false;

    /**
     * Test method preprocessor closure.
     *
     * @var PreprocessorInterface
     */
    private $testPreprocessor;

    /**
     * Base test namespace.
     *
     * @var string
     */
    private $baseTestNamespace;

    /**
     * Constructor.
     *
     * @param string $inClassName
     * @param string $inSourceFile
     * @param string $outClassName
     * @param string $outSourceFile
     * @param string $baseNamespace
     * @param string $baseTestNamespace
     * @param string $mockType
     * @param string $dataProviderTestPath
     * @param string $dataProviderNamespace
     * @param string $mockTestPath
     * @param string $mockNamespace
     * @param string $projectNamespace
     *
     * @throws FileNotExistsException
     * @throws InvalidMockTypeException
     * @throws ReflectionException
     */
    public function __construct(
        string $inClassName,
        string $inSourceFile = '',
        string $outClassName = '',
        string $outSourceFile = '',
        string $baseNamespace = '',
        string $baseTestNamespace = '',
        string $mockType = MockGenerator::MOCK_TYPE_PHPUNIT,
        string $dataProviderTestPath = '',
        string $dataProviderNamespace = '',
        string $mockTestPath = '',
        string $mockNamespace = '',
        string $projectNamespace = ''
    ) {
        if (class_exists($inClassName)) {
            $reflector = new ReflectionClass($inClassName);
            $inSourceFile = $reflector->getFileName();

            if (false === $inSourceFile) {
                $inSourceFile = '<internal>';
            }
            unset($reflector);
        } else {
            $possibleFilenames = [
                $inClassName . '.php',
                str_replace(
                    ['_', '\\'],
                    DIRECTORY_SEPARATOR,
                    $inClassName
                ) . '.php',
            ];

            if (empty($inSourceFile)) {
                foreach ($possibleFilenames as $possibleFilename) {
                    if (is_file($possibleFilename)) {
                        $inSourceFile = $possibleFilename;
                    }
                }
            }

            if (empty($inSourceFile)) {
                throw new RuntimeException(
                    sprintf(
                        'Neither \'%s\' nor \'%s\' could be opened.',
                        $possibleFilenames[0],
                        $possibleFilenames[1]
                    )
                );
            }

            if (!is_file($inSourceFile)) {
                throw new RuntimeException(
                    sprintf(
                        '\'%s\' could not be opened.',
                        $inSourceFile
                    )
                );
            }
            $inSourceFile = realpath($inSourceFile);

            if (!class_exists($inClassName)) {
                throw new RuntimeException(
                    sprintf(
                        'Could not find class \'%s\' in \'%s\'.',
                        $inClassName,
                        $inSourceFile
                    )
                );
            }
        }

        if (false === $inSourceFile) {
            throw new FileNotExistsException(sprintf('Filename can not be empty.'));
        }

        if (empty($outClassName)) {
            $outClassName = $inClassName . 'Test';
        }

        if (empty($outSourceFile)) {
            $dirname = dirname($inSourceFile);
            $outClassNameTree = explode('\\', $outClassName);
            $outSourceFile = $dirname . DIRECTORY_SEPARATOR . end($outClassNameTree) . '.php';
        }

        $this->baseNamespace = $baseNamespace;
        $this->baseTestNamespace = $baseTestNamespace;
        $this->inClassName = $this->parseFullyQualifiedClassName(
            $inClassName
        );
        $this->outClassName = $this->parseFullyQualifiedClassName(
            $outClassName
        );
        $this->inSourceFile = str_replace(
            $this->inClassName['fullyQualifiedClassName'],
            $this->inClassName['className'],
            $inSourceFile
        );
        $this->outSourceFile = str_replace(
            $this->outClassName['fullyQualifiedClassName'],
            $this->outClassName['className'],
            $outSourceFile
        );
        $this->initDataProviderGenerator($dataProviderTestPath, $dataProviderNamespace, $baseNamespace, $projectNamespace);
        $this->initMockGenerator($mockTestPath, $mockNamespace, $baseNamespace, $projectNamespace, $mockType);
    }

    /**
     * Init DataProviderGenerator.
     *
     * @param string $dataProviderTestPath
     * @param string $dataProviderNamespace
     * @param string $baseNamespace
     * @param string $projectNamespace
     */
    private function initDataProviderGenerator(
        string $dataProviderTestPath,
        string $dataProviderNamespace,
        string $baseNamespace,
        string $projectNamespace
    ): void {
        $this->dataProviderGenerator = new DataProviderGenerator(
            $dataProviderTestPath,
            $dataProviderNamespace,
            $baseNamespace,
            $projectNamespace
        );
    }

    /**
     * Init MockGenerator.
     *
     * @param string $mockTestPath
     * @param string $mockNamespace
     * @param string $baseNamespace
     * @param string $projectNamespace
     * @param string $mockType
     *
     * @throws InvalidMockTypeException
     */
    private function initMockGenerator(
        string $mockTestPath,
        string $mockNamespace,
        string $baseNamespace,
        string $projectNamespace,
        string $mockType
    ): void {
        $this->mockGenerator = new MockGenerator($mockTestPath, $mockNamespace, $projectNamespace, $mockType);
    }

    /**
     * Generate test class code.
     *
     * @return string|null
     *
     * @throws Exception
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function generate(): ?string
    {
        $reflectionClass = new ReflectionClass(
            $this->inClassName['fullyQualifiedClassName']
        );

        if ($reflectionClass->isAbstract()) {
            sprintf(
                'Class \'%s\' is abstract, test reflectionClass will not be generate',
                $reflectionClass->getName()
            );

            return null;
        }
        $traits = $reflectionClass->getTraitNames();

        if (null !== $traits) {
            foreach ($traits as $trait) {
                if (!trait_exists($trait)) {
                    throw new Exception(sprintf('Trait \'%s\' does not exists!', $trait));
                }
            }
        }
        $methods = '';
        $constructArguments = '';
        $constructArgumentsInitialize = '';
        $reflectionClassMethods = $reflectionClass->getMethods();

        foreach ($reflectionClassMethods as $reflectionMethod) {
            if (!$reflectionMethod->isConstructor()) {
                continue;
            }
            [$constructArguments, $constructArgumentsInitialize, ] = $this->processMethodDocComment($reflectionClass, $reflectionMethod);
        }

        foreach ($reflectionClassMethods as $reflectionMethod) {
            if ($reflectionMethod->isDestructor() || $reflectionMethod->isConstructor()) {
                continue;
            }
            $methodName = $reflectionMethod->getName();
            $methodDeclaringClassName = $reflectionMethod->getDeclaringClass()->getName();

            if (
                !$reflectionMethod->isAbstract()
                && $reflectionMethod->isPublic()
                && !in_array(strtolower($methodName), self::EXCLUDE_DECLARING_METHODS, true)
                && !in_array(explode('\\', trim($methodDeclaringClassName, '\\'))[0], self::EXCLUDE_DECLARING_CLASSES_FOR_METHODS, true)
            ) {
                $testMethodCode = $this->renderMethod($reflectionClass, $reflectionMethod, $constructArguments, $constructArgumentsInitialize);

                if (!$testMethodCode) {
                    continue;
                }
                $methods .= $testMethodCode;
            }
        }
        $useStatement = [];
        $useStatement[] = "\r\nuse " . ltrim($this->baseTestNamespace, '\\') . '\\' . 'UnitTestCase;';
        $useStatement[] = "\r\nuse " . ltrim($this->inClassName['fullyQualifiedClassName'], '\\') . ';';
        $mockTraits = $this->mockGenerator->generate();

        if (null !== $mockTraits) {
            $mockTraits = explode(',', $mockTraits);

            foreach ($mockTraits as &$mockTrait) {
                $reflectionMock = new ReflectionClass($mockTrait);
                $useStatement[] = "\r\nuse " . $reflectionMock->getName() . ';';
                $mockTrait = $reflectionMock->getShortName();
            }
        }
        $useTraits = $mockTraits ? "\r\n\tuse " . implode(', ', $mockTraits) . ';' : '';
        $this->dataProviderGenerator->generate();
        $classTemplate = new Template(
            sprintf(
                '%s%stemplate%sTestClass.tpl',
                __DIR__,
                DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR
            )
        );
        sort($useStatement);
        $classTemplate->setVar(
            [
                'namespace' => trim($this->outClassName['namespace'], '\\'),
                'testBaseFullClassName' => trim($this->outClassName['testBaseFullClassName'], '\\'),
                'className' => $this->inClassName['className'],
                'fullClassName' => $this->inClassName['fullyQualifiedClassName'],
                'useStatement' => implode('', $useStatement),
                'useTraits' => $useTraits,
                'constructArguments' => $constructArguments,
                'constructArgumentsInitialize' => $constructArgumentsInitialize,
                'testClassName' => $this->outClassName['className'],
                'methods' => $methods,
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
            ]
        );

        return $classTemplate->render();
    }

    /**
     * Render test method.
     *
     * @param ReflectionClass $class
     * @param ReflectionMethod $method
     * @param string $constructArguments
     * @param string $constructArgumentsInitialize
     *
     * @return string
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     * @throws InvalidClassnameException
     * @throws MockNotExistsException
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    protected function renderMethod(
        ReflectionClass $class,
        ReflectionMethod $method,
        string $constructArguments,
        string $constructArgumentsInitialize
    ): ?string {
        $argumentsInitialize = '';
        $additional = '';
        $returnType = $this->getMethodReturnType($method);
        $methodName = $method->getName();

        switch ($returnType) {
            case DataTypeInterface::TYPE_INT:
            case DataTypeInterface::TYPE_INTEGER:
                $assertion = 'Equals';
                $template = self::METHOD_TEMPLATE_TYPE_DEFAULT;
                $expected = '$mockArgs[\'' . $methodName . '\']';

                break;

            case DataTypeInterface::TYPE_STRING:
                $assertion = 'Equals';
                $template = self::METHOD_TEMPLATE_TYPE_DEFAULT;
                $expected = '$mockArgs[\'' . $methodName . '\']';

                break;

            case DataTypeInterface::TYPE_BOOL:
            case DataTypeInterface::TYPE_BOOLEAN:
                $assertion = 'True';
                $template = self::METHOD_TEMPLATE_TYPE_BOOL;
                $expected = DataTypeInterface::TYPE_BOOL_TRUE;

                break;

            case DataTypeInterface::TYPE_VOID:
                $assertion = false;
                $template = self::METHOD_TEMPLATE_TYPE_VOID;
                $expected = null;

                break;

            case DataTypeInterface::TYPE_ARRAY:
            case DataTypeInterface::TYPE_ARRAY_MIXED:
                $assertion = 'Equals';
                $template = self::METHOD_TEMPLATE_TYPE_DEFAULT;
                $expected = '$mockArgs[\'' . $methodName . '\']';

                break;

            default:
                $typeIsArray = false;

                if (false !== strpos($returnType, '[]')) {
                    $returnType = $this->getClassNameFromComplexAnnotationName($returnType, $method->getDeclaringClass());
                    $typeIsArray = true;
                }

                if (!$typeIsArray) {
                    $assertion = 'InstanceOf';
                    $template = self::METHOD_TEMPLATE_TYPE_DEFAULT;
                    $expected = '\\' . $returnType . '::class';
                } else {
                    $filename = $class->getFileName();
                    $namespaces = $this->getNamespacesFromSource($filename);
                    $mockName = $this->mockGenerator->addMock($returnType, $class, $method, $namespaces);
                    $mockStructure = $this->mockGenerator->getMock($mockName);
                    $assertion = 'Equals';
                    $template = self::METHOD_TEMPLATE_TYPE_DEFAULT;
                    $mockMethodName = $mockStructure['mockMethodName'];
                    $mockShortName = $mockStructure['mockShortName'];
                    $argumentsInitialize .= '$' . $methodName . 's = [];';
                    $argumentsInitialize .= "\n\r\n\t\t" . 'foreach ($mockArgs[\'' . $methodName . '\'] as $i => $' . $mockShortName . ') {';
                    $argumentsInitialize .= "\r\n\t\t\t$" . $methodName . 's[] = $this->' . $mockMethodName .
                        '($' . $mockShortName . ', $mockTimes[\'' . $methodName . '\'][$i]);' . "\r\n\t\t}\r\n\t\t";
                    $expected = '$' . $methodName . 's';
                }
        }

        if ($method->isStatic()) {
            $template .= 'Static';
        }
        $methodTemplate = new Template(
            sprintf(
                '%s%stemplate%s%s.tpl',
                __DIR__,
                DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $template
            )
        );
        $methodName = $this->generateTestMethodName($method, $returnType);

        if (!isset($this->methodNameCounter[$methodName])) {
            $this->methodNameCounter[$methodName] = 0;
        }
        ++$this->methodNameCounter[$methodName];

        if ($this->methodNameCounter[$methodName] > 1) {
            $methodName .= $this->methodNameCounter[$methodName];
        }

        if ($this->testPreprocessor) {
            if (!$this->testPreprocessor->isShouldBeTested($class, $method)) {
                return null;
            }

            $this->testPreprocessor->process($class, $method, $methodName, $additional);
        }
        [
            $arguments,
            $argumentsInitializeFromMethod,
            $methodComment,
            $dataProviderClassName,
            $dataProviderMethodName
        ] = $this->processMethodDocComment($class, $method);
        $methodTemplate->setVar(
            [
                'arguments' => $arguments,
                'argumentsInitialize' => $argumentsInitialize . $argumentsInitializeFromMethod,
                'assertion' => $assertion,
                'expected' => $expected,
                'additional' => $additional,
                'origMethodName' => $method->getName(),
                'className' => $this->inClassName['className'],
                'fullClassName' => $this->inClassName['fullyQualifiedClassName'],
                'methodName' => $methodName,
                'methodComment' => $methodComment,
                'constructArguments' => $constructArguments,
                'constructArgumentsInitialize' => $constructArgumentsInitialize,
                'dataProviderClassName' => $dataProviderClassName,
                'dataProviderMethodName' => $dataProviderMethodName,
            ]
        );

        return $methodTemplate->render();
    }

    /**
     * Build test method name.
     *
     * @param ReflectionMethod $method
     * @param string           $returnType
     *
     * @return string
     */
    protected function generateTestMethodName(ReflectionMethod $method, string $returnType): string
    {
        if ($returnType === DataTypeInterface::TYPE_ARRAY_MIXED) {
            $returnType = DataTypeInterface::TYPE_ARRAY;
        }
        $origMethodName = $method->getName();
        $methodName = lcfirst($origMethodName);
        $pos = strrpos($returnType, '\\');
        $returnTypeSuffix = $pos ? substr($returnType, ++$pos) : $returnType;

        if (
            false !== strpos('create', $origMethodName) ||
            false !== strpos('make', $origMethodName)
        ) {
            return $methodName . 'ShouldInitializeAndReturn' . $returnTypeSuffix;
        }

        if (false !== strpos('Event', $returnType)) {
            return $methodName . 'ShouldFire' . $returnTypeSuffix;
        }

        if (
            false !== strpos('handle', $origMethodName)
        ) {
            $args = [];

            foreach ($method->getParameters() as $param) {
                $args[] = ucfirst($param->getName());
            }
            $methodName .= 'ShouldProcess' . implode('', $args);

            if ($returnType === self::METHOD_TEMPLATE_TYPE_VOID) {
                return $methodName;
            }

            if (class_exists($returnType) || interface_exists($returnType)) {
                return $methodName . 'AndReturn' . $returnTypeSuffix;
            }

            return $methodName . 'AndReturn' . ucfirst($returnType);
        }

        if (class_exists($returnType) || interface_exists($returnType)) {
            return $methodName . 'ShouldReturn' . $returnTypeSuffix;
        }

        return $methodName . 'ShouldReturn' . ucfirst($returnType);
    }

    /**
     * Process method doc comment and parse method description, method arguments and code for inititalize all arguments for testing.
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     *
     * @return string[]
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     * @throws InvalidClassnameException
     * @throws MockNotExistsException
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     *
     * @psalm-suppress PossiblyNullReference
     */
    protected function processMethodDocComment(ReflectionClass $reflectionClass, ReflectionMethod $reflectionMethod): array
    {
        $excludeConstructor = false;
        $class = $reflectionMethod->getDeclaringClass();
        $methodDeclaringClass = $class->getName();

        if (
            $reflectionMethod->isConstructor() &&
            in_array(explode('\\', $methodDeclaringClass)[0], self::EXCLUDE_DECLARING_CLASSES, true)
        ) {
            $excludeConstructor = true;
        }
        $filename = $reflectionClass->getFileName();

        if (false === $filename) {
            throw new FileNotExistsException(sprintf('File \'%s\' does not exists.', $reflectionClass->getName()));
        }
        $namespaces = $this->getNamespacesFromSource($filename);
        $methodDocComment = $reflectionMethod->getDocComment();
        $methodComment = 'Execute \'' . $reflectionMethod->getName() . '\' method';

        if (false !== $methodDocComment) {
            preg_match_all('/\* (.*)$/Um', $methodDocComment, $annotationComment);

            if (isset($annotationComment[1][0])) {
                $methodComment = trim($annotationComment[1][0], ". \t\n\r\0\x0B");
            }
        }
        $annotationParams = $this->getParamsFromAnnotation($reflectionMethod, false, true);
        $parameters = $reflectionMethod->getParameters();
        $argumentsInitialize = [];
        $arguments = [];
        $dataProviderMethodName = $this->dataProviderGenerator->addDataProviderMethod($reflectionClass, $reflectionMethod);

        if (null === $annotationParams) {
            $annotationParams = [];
        }

        foreach ($parameters as $i => $param) {
            $this->dataProviderGenerator->addDataProviderArgument($reflectionClass, $param, $dataProviderMethodName);
            $setEndColon = true;
            $argumentName = '$' . $param->getName();
            $argumentInitialize = '$' . $param->getName() . ' = ';

            if (null !== $param->getType()) {
                $returnType = $param->getType();

                if (
                    class_exists('ReflectionUnionType') &&
                    $returnType instanceof \ReflectionUnionType
                ) {
                    // use only first return type
                    // @TODO return all return types
                    $returnTypes = $returnType->getTypes();
                    $returnType = array_shift($returnTypes);
                }
                if ($returnType instanceof \ReflectionNamedType) {
                    $returnType = $returnType->getName();
                }
                $annotationParams[$i] = $returnType;
            } elseif (isset($annotationParams[$i])) {
                $annotationParam = $this->findAndReturnClassNameFromUseStatement($annotationParams[$i], $reflectionClass);

                if (null !== $annotationParam) {
                    $annotationParams[$i] = $annotationParam;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();

                if (
                    (null === $value || false === $value || true === $value) &&
                    (class_exists($annotationParams[$i]) || interface_exists($annotationParams[$i]))
                ) {
                    $className = $annotationParams[$i];
                    $mockName = $this->mockGenerator->addMock($className, $reflectionClass, $reflectionMethod, $namespaces);
                    $mockStructure = $this->mockGenerator->getMock($mockName);

                    if (null === $mockStructure) {
                        throw new MockNotExistsException(sprintf('Mock \'%s\' does not exist.', $mockName));
                    }
                    $pos = strrpos($className, '\\');
                    $dataProviderMockArgName = $pos ? substr($className, ++$pos) : $className;
                    $mockMethodName = $mockStructure['mockMethodName'];
                    $mockShortName = $mockStructure['mockShortName'];
                    $argumentName = '$' . $mockShortName;
                    $argumentInitialize = '$' . $mockShortName . ' = $this->' . $mockMethodName . '($mockArgs[\'' . $dataProviderMockArgName . '\'], $mockTimes[\'' . $dataProviderMockArgName . '\']);';
                    $setEndColon = false;
                } elseif (null === $value) {
                    $argumentInitialize .= 'null';
                } elseif (false === $value) {
                    $argumentInitialize .= 'false';
                } elseif (true === $value) {
                    $argumentInitialize .= 'true';
                } elseif (is_numeric($value) || is_float($value)) {
                    $argumentInitialize .= $value;
                } elseif (is_array($value)) {
                    $tmpValue = [];

                    foreach ($value as $key => $val) {
                        $key = is_numeric($key) ? $key : '\'' . $key . '\'';
                        $val = is_numeric($val) || is_float($val) ? $val : '\'' . $val . '\'';
                        $tmpValue[] = $key . ' => ' . $val;
                    }
                    $argumentInitialize .= '[' . implode(', ', $tmpValue) . ']';
                } else {
                    $argumentInitialize .= '\'' . $value . '\'';
                }
            } else {
                if (!isset($annotationParams[$i]) &&
                    in_array(explode('\\', $methodDeclaringClass)[0], self::EXCLUDE_DECLARING_CLASSES_FOR_METHODS, true)
                ) {
                    $annotationParams[$i] = null;
                }

                if (!array_key_exists($i, $annotationParams)) {
                    if ($reflectionClass->isInterface()) {
                        throw new Exception(sprintf(
                            'In interface \'%s\' in method \'%s\' for argument  \'%s\' annotation not exists.',
                            $reflectionClass->getName(),
                            $reflectionMethod->getName(),
                            $param->getName()
                        ));
                    }
                    $interfaces = $reflectionClass->getInterfaces();

                    foreach ($interfaces as $interface) {
                        try {
                            $parentMethod = $interface->getMethod($reflectionMethod->getName());

                            return $this->processMethodDocComment($interface, $parentMethod);
                            break;
                        } catch (ReflectionException $e) {
                            continue;
                        } catch (RuntimeException $e) {
                            break;
                        }
                    }
                    $parentClass = $reflectionMethod->getDeclaringClass()->getParentClass();

                    if ($parentClass) {
                        $parentMethod = $parentClass->getMethod($reflectionMethod->getName());

                        return $this->processMethodDocComment($parentClass, $parentMethod);
                    }

                    throw new RuntimeException(
                        sprintf(
                            'In class \'%s\' in method \'%s\' for argument  \'%s\' annotation not exists.',
                            $reflectionClass->getName(),
                            $reflectionMethod->getName(),
                            $param->getName()
                        )
                    );
                }

                if (null !== $annotationParams[$i]) {
                    $annotationParams[$i] = trim($annotationParams[$i]);

                    if (false !== strpos($annotationParams[$i], '|')) {
                        $annotationParams[$i] = explode('|', $annotationParams[$i])[0];
                    }
                }

                switch ($annotationParams[$i]) {
                    case DataTypeInterface::TYPE_INT:
                    case DataTypeInterface::TYPE_INTEGER:
                    case DataTypeInterface::TYPE_FLOAT:
                    case DataTypeInterface::TYPE_STRING:
                    case DataTypeInterface::TYPE_BOOL:
                    case DataTypeInterface::TYPE_BOOLEAN:
                    case DataTypeInterface::TYPE_MIXED:
                    case DataTypeInterface::TYPE_ARRAY:
                    case DataTypeInterface::TYPE_ARRAY_MIXED:
                        $argumentInitialize .= '$mockArgs[\'' . $param->getName() . '\']';
                        break;

                    case DataTypeInterface::TYPE_CLOSURE:
                    case DataTypeInterface::TYPE_CALLABLE:
                        $argumentInitialize .= 'function () { return true; }';

                        break;

                    case DataTypeInterface::TYPE_OBJECT:
                        $argumentInitialize .= 'new class {}';

                        break;

                    default:
                        if (
                            false === $excludeConstructor &&
                            empty($annotationParams[$i])
                        ) {
                            throw new RuntimeException(
                                sprintf(
                                    'Could not find param type for param \'%s\' in method \'%s\' in \'%s\'.',
                                    $param->getName(),
                                    $reflectionMethod->getName(),
                                    $reflectionClass->getName()
                                )
                            );
                        }

                        if (!$excludeConstructor && $param->getType()) {
                            $className = $param->getType() ?: $this->getReturnFromReflectionMethodAnnotation($reflectionMethod);

                            if (
                                class_exists('ReflectionUnionType') &&
                                $className instanceof \ReflectionUnionType
                            ) {
                                // use only first return type
                                // @TODO return all return types
                                $className = $className->getTypes();
                                $className = array_shift($className);
                            }
                            if ($className instanceof \ReflectionNamedType) {
                                $className = $className->getName();
                            }

                            if (null === $className) {
                                throw new InvalidClassnameException(sprintf('Class name could not be null.'));
                            }
                            $classNameTmp = ($className instanceof ReflectionClass) ? $className->getName() : $className;
                            $pos = strrpos($classNameTmp, '\\');
                            $dataProviderMockArgName = $pos ? substr($classNameTmp, ++$pos) : $classNameTmp;
                            $mockName = $this->mockGenerator->addMock($className, $reflectionClass, $reflectionMethod, $namespaces);
                            $mockStructure = $this->mockGenerator->getMock($mockName);

                            if (null === $mockStructure) {
                                throw new MockNotExistsException(sprintf('Mock \'%s\' does not exist.', $mockName));
                            }
                            $mockMethodName = $mockStructure['mockMethodName'];
                            $mockShortName = $mockStructure['mockShortName'];
                            $argumentName = '$' . $mockShortName;
                            $argumentInitialize = '$' . $mockShortName . ' = $this->' . $mockMethodName . '($mockArgs[\'' . $dataProviderMockArgName . '\'], $mockTimes[\'' . $dataProviderMockArgName . '\']);';
                            $setEndColon = false;
                        } else {
                            $argumentInitialize .= '$mockArgs[\'' . $param->getName() . '\']';
                        }
                }
            }

            if ($setEndColon) {
                $argumentInitialize .= ';';
            }
            $arguments[] = $argumentName;
            $argumentsInitialize[] = $argumentInitialize;
        }
        $arguments = implode(', ', $arguments);
        $argumentsInitialize = implode("\r\n\t\t", $argumentsInitialize);
        $dataProviderName = $this->dataProviderGenerator->getDataProviderName($reflectionClass->getName());
        $dataProviderConfig = $this->dataProviderGenerator->getDataProvider($dataProviderName);
        $dataProviderClassName = $dataProviderConfig['dataProviderFullClassName'];

        return [$arguments, $argumentsInitialize, $methodComment, $dataProviderClassName, $dataProviderMethodName];
    }

    /**
     * Set preprocessor test generator.
     *
     * @return PreprocessorInterface
     */
    public function getTestPreprocessor(): PreprocessorInterface
    {
        return $this->testPreprocessor;
    }

    /**
     * Get preprocessor test generator.
     *
     * @param PreprocessorInterface $testPreprocessor
     */
    public function setTestPreprocessor(PreprocessorInterface $testPreprocessor): void
    {
        $this->testPreprocessor = $testPreprocessor;
    }
}
