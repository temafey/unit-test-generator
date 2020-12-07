<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Exception\InvalidMockTypeException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\Mock\Mockery;
use MicroModule\UnitTestGenerator\Generator\Mock\MockInterface;
use MicroModule\UnitTestGenerator\Generator\Mock\PhpUnit;
use Exception;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Class MockGenerator.
 * Generator for test class skeletons from classes.
 *
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 *
 * @SuppressWarnings(PHPMD)
 */
class MockGenerator extends AbstractGenerator
{
    use CodeHelper;

    public const MOCK_TYPE_PHPUNIT = 'PhpUnit';
    public const MOCK_TYPE_MOCKERY = 'Mockery';
    public const MOCK_NAME_PREFIX = 'Mock';
    private const MOCK_TRAIT_SUFFIX = 'MockHelper';
    private const MOCK_VENDOR_FOLDER = 'Vendor';
    private const MOCK_NATIVE_NAMESPACE = 'Native';

    /**
     * Mocks generated code.
     *
     * @var mixed[]
     */
    protected $mocks = [];

    /**
     * Mock code generator.
     *
     * @var MockInterface
     */
    private $mockGenerator;

    /**
     * Path to save mock helper.
     *
     * @var string
     */
    private $mockTestPath;

    /**
     * Mock namespace.
     *
     * @var string
     */
    private $mockNamespace;

    /**
     * Project namespace.
     *
     * @var string
     */
    private $projectNamespace;

    /**
     * TestGenerator constructor.
     *
     * @param string $mockTestPath
     * @param string $mockNamespace
     * @param string $projectNamespace
     * @param string $mockType
     *
     * @throws InvalidMockTypeException
     */
    public function __construct(
        string $mockTestPath,
        string $mockNamespace,
        string $projectNamespace,
        string $mockType = self::MOCK_TYPE_PHPUNIT
    ) {
        $this->mockTestPath = $mockTestPath;
        $this->mockNamespace = $mockNamespace;
        $this->projectNamespace = $projectNamespace;
        $this->mockGenerator = $this->makeMockGeneratorByType($mockType);
    }

    /**
     * Build and return mock file path.
     *
     * @param string $className
     *
     * @return string[]
     */
    private function getMockPathAndNamespace(string $className): array
    {
        $mockFilePath = $this->mockTestPath . DIRECTORY_SEPARATOR;
        $mockFullClassName = $this->mockNamespace . '\\';

        if (false === strpos($className, $this->projectNamespace)) {
            $mockFilePath .= self::MOCK_VENDOR_FOLDER . DIRECTORY_SEPARATOR;
            $mockFullClassName .= self::MOCK_VENDOR_FOLDER . '\\';
            $tmpNamespace = $className;
        } else {
            $tmpNamespace = substr($className, strlen($this->projectNamespace) + 1);
        }

        if (false === strpos($tmpNamespace, '\\')) {
            $tmpNamespace = self::MOCK_NATIVE_NAMESPACE . '\\' . $tmpNamespace;
        }
        $tmpNamespace = implode('\\', array_slice(explode('\\', $tmpNamespace), 0, 2));
        $tmpClassName = implode('\\', array_slice(explode('\\', $tmpNamespace), 1, 1));
        $mockFilePath .= str_replace('\\', DIRECTORY_SEPARATOR, $tmpNamespace) . self::MOCK_TRAIT_SUFFIX . '.php';
        $mockClassName = $tmpClassName . self::MOCK_TRAIT_SUFFIX;
        $mockFullClassName .= $tmpNamespace . self::MOCK_TRAIT_SUFFIX;
        $pos = strrpos($mockFullClassName, '\\');

        if (false === $pos) {
            $pos = strlen($mockFullClassName);
        }
        $mockNamespace = substr($mockFullClassName, 0, $pos);

        return [$mockFilePath, $mockNamespace, $mockClassName, $mockFullClassName];
    }

    /**
     * Mock code generator factory.
     *
     * @param string $type
     *
     * @return MockInterface
     *
     * @throws InvalidMockTypeException
     */
    private function makeMockGeneratorByType(string $type): MockInterface
    {
        switch ($type) {
            case self::MOCK_TYPE_PHPUNIT:
                return new PhpUnit();

            case self::MOCK_TYPE_MOCKERY:
                return new Mockery();

            default:
                throw new InvalidMockTypeException('Invalid mock type.');
        }
    }

    /**
     * Generate and save test mocks.
     *
     * @return string|null
     *
     * @throws Exception
     */
    public function generate(): ?string
    {
        $mocks = [];
        $mockFiles = [];
        $mockTraits = [];
        $mockFileInitialized = [];

        if (!$this->mocks) {
            return null;
        }

        foreach ($this->mocks as $mockName => $mock) {
            [$mockFilepath, $mockNamespace, $mockClassName, $mockFullClassName] = $this->getMockPathAndNamespace($mock['className']);

            if (!isset($mockFileInitialized[$mockFilepath]) && file_exists($mockFilepath)) {
                $methods = $this->parseMethodsFromSource($mockFilepath);

                foreach ($methods as $method) {
                    $mockFiles[$mockFilepath][] = $method;
                }
                $mockFileInitialized[$mockFilepath] = true;
            }

            if (!isset($mockTraits[$mockFullClassName])) {
                $mockTraits[$mockFullClassName] = '\\' . $mockFullClassName;
            }

            if (!isset($mockFiles[$mockFilepath])) {
                $mockFiles[$mockFilepath] = [];
            }
            $mockMethodName = $mock['mockMethodName'];

            if (in_array($mockMethodName, $mockFiles[$mockFilepath], true)) {
                continue;
            }
            $mockTemplate = new Template(
                sprintf(
                    '%s%stemplate%s%s.tpl',
                    __DIR__,
                    DIRECTORY_SEPARATOR,
                    DIRECTORY_SEPARATOR,
                    $this->mockGenerator->getMockMethodTemplate()
                )
            );
            $mockTemplate->setVar([
                'mockClassName' => $mock['className'],
                'mockName' => $mockName,
                'mockMethodName' => $mockMethodName,
                'mockInterface' => $this->mockGenerator->getReturnMockInterface(),
                'mock' => $mock['mock'],
                'mockArgs' => $mock['mockArgs'],
                'mockTimes' => $mock['mockTimes'],
            ]);

            if (!isset($mocks[$mockFilepath])) {
                $mocks[$mockFilepath] = [
                    'className' => $mockClassName,
                    'fullClassName' => $mockFullClassName,
                    'namespace' => $mockNamespace,
                    'methods' => [],
                ];
            }
            $mocks[$mockFilepath]['methods'][] = $mockTemplate->render();
        }

        foreach ($mocks as $mockFilepath => $mock) {
            $mockCode = implode('', $mock['methods']);

            if (!file_exists($mockFilepath)) {
                $mockCode = $this->generateNewMockTrait($mock['namespace'], $mock['className'], $mockCode);
            }
            $this->saveFile($mockFilepath, $mockCode);
        }

        return implode(',', $mockTraits);
    }

    /**
     * Generate new mock trait.
     *
     * @param string $namespace
     * @param string $className
     * @param string $methods
     *
     * @return string
     *
     * @throws Exception
     */
    private function generateNewMockTrait(string $namespace, string $className, string $methods): string
    {
        $classTemplate = new Template(
            sprintf(
                '%s%stemplate%sTrait.tpl',
                __DIR__,
                DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR
            )
        );
        $classTemplate->setVar([
            'namespace' => trim($namespace, '\\'),
            'className' => $className,
            'methods' => $methods,
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
        ]);

        return $classTemplate->render();
    }

    /**
     * Save code to file, if file exist append code, if no, check if folder exist and create if needed.
     *
     * @param string $filepath
     * @param string $code
     *
     * @throws CodeExtractException
     */
    private function saveFile(string $filepath, string $code): void
    {
        $pathInfo = pathinfo($filepath);

        if (!file_exists($pathInfo['dirname'])) {
            if (!mkdir($concurrentDirectory = $pathInfo['dirname'], 0755, true) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        if (file_exists($filepath)) {
            $existCode = file_get_contents($filepath);

            if (false === $existCode) {
                throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $filepath));
            }
            $pos = strrpos($existCode, "\n}");

            if (false === $pos) {
                $pos = strlen($existCode);
            }
            $existCode = substr($existCode, 0, $pos);
            $code = $existCode . $code . "\n}";
        }

        file_put_contents($filepath, $code);
    }

    /**
     * Generate mock object.
     *
     * @param string|ReflectionClass $className
     * @param ReflectionClass        $parentClass
     * @param ReflectionMethod       $parentMethod
     * @param mixed[]                $parentNamespaces
     * @param int                    $level
     * @param ReflectionClass|null   $parentAddMockClass
     *
     * @return string
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     */
    public function addMock(
        $className,
        ReflectionClass $parentClass,
        ReflectionMethod $parentMethod,
        array $parentNamespaces,
        int $level = 0,
        ?ReflectionClass $parentAddMockClass = null
    ): string {
        ++$level;
        $reflection = $className;

        if (!$reflection instanceof ReflectionClass) {
            $reflection = trim($reflection);
            $reflection = $this->makeReflectionClass($reflection, $parentNamespaces, $parentMethod, $parentClass);
        }
        $className = $reflection->getName();
        $mockName = ucfirst(str_replace('\\', '', $className . self::MOCK_NAME_PREFIX));

        if (isset($this->mocks[$mockName])) {
            return $mockName;
        }
        $mockShortName = lcfirst(str_replace('\\', '', str_replace($this->projectNamespace, '', $className) . self::MOCK_NAME_PREFIX));
        $mockMethodName = MockInterface::METHOD_NAME_PREFIX . ucfirst($mockShortName);

        if (null === $parentAddMockClass || $parentAddMockClass->getName() !== $reflection->getName()) {
            [$mock, $mockArgs, $mockTimes] = $this->mockGenerator->generateMockCode($this, $className, $reflection, $level, $mockName, $parentClass, $parentNamespaces);
            $this->mocks[$mockName] = ['className' => $className, 'mockShortName' => $mockShortName, 'mockMethodName' => $mockMethodName, 'mock' => $mock, 'mockArgs' => $mockArgs, 'mockTimes' => $mockTimes];
        } else {
            $this->mocks[$mockName] = ['className' => $className, 'mockShortName' => $mockShortName, 'mockMethodName' => $mockMethodName, 'mock' => '$mock', 'mockArgs' => '[]', 'mockTimes' => '[]'];
        }

        return $mockName;
    }

    /**
     * Return, if exists, mock structure.
     *
     * @param string $mockName
     *
     * @return mixed[]|null
     */
    public function getMock(string $mockName): ?array
    {
        return $this->mocks[$mockName] ?: null;
    }

    /**
     * Make and return ReflectionClass.
     *
     * @param string           $className
     * @param string[]         $parentNamespaces
     * @param ReflectionMethod $parentMethod
     * @param ReflectionClass  $parentClass
     *
     * @return ReflectionClass
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    private function makeReflectionClass(
        string $className,
        array $parentNamespaces,
        ReflectionMethod $parentMethod,
        ReflectionClass $parentClass
    ): ReflectionClass {
        try {
            $reflection = new ReflectionClass($className);
        } catch (Throwable $e) {
            $alias = $className;
            $fullClassName = $className;

            if (strpos('\\', $className)) {
                $alias = explode('\\', $className)[0];
            }

            if (isset($parentNamespaces[$alias])) {
                $fullClassName = $parentNamespaces[$alias];
            }

            try {
                $reflection = new ReflectionClass($fullClassName);
            } catch (Throwable $e) {
                $fullClassName = $this->getClassNameFromComplexAnnotationName($fullClassName, $parentMethod->getDeclaringClass());

                try {
                    $reflection = new ReflectionClass($fullClassName);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        sprintf(
                            'Class \'%s\' does not exists, creating mock in parent class \'%s\' for method \'%s\'.',
                            $fullClassName,
                            $parentClass->getName(),
                            $parentMethod->getName()
                        )
                    );
                }
            }
        }

        return $reflection;
    }
}
