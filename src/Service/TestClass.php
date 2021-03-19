<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Service;

use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\MockGenerator;
use MicroModule\UnitTestGenerator\Generator\Preprocessor\PreprocessorInterface;
use MicroModule\UnitTestGenerator\Generator\TestGenerator;
use Exception;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Class TestClass.
 *
 * @SuppressWarnings(PHPMD)
 */
class TestClass
{
    use CodeHelper;

    protected const DEFAULT_SOURCE_FOLDER_NAME = 'src';
    protected const DEFAULT_TEST_FOLDER = 'tests';
    protected const DEFAULT_TEST_NAMESPACE = 'Tests';
    protected const DEFAULT_TEST_UNIT_FOLDER = 'Unit';
    protected const DEFAULT_TEST_PREFIX = 'Test';
    protected const DEFAULT_DATA_PROVIDER_NAMESPACE = 'DataProvider';
    protected const DEFAULT_MOCK_NAMESPACE = 'Mock';

    public const PSR_NAMESPACE_TYPE_1 = 'psr-1';
    public const PSR_NAMESPACE_TYPE_4 = 'psr-4';

    /**
     * Psr namespace type.
     *
     * @var string
     */
    private $psrNamespaceType;

    /**
     * Source code folder name.
     *
     * @var string
     */
    protected $sourceFolderName;

    /**
     * Source path.
     *
     * @var string
     */
    protected $sourcePath;

    /**
     * Test base path.
     *
     * @var string
     */
    private $sourceTestFolder;

    /**
     * Unit tests base path.
     *
     * @var string
     */
    private $unitTestFolder;

    /**
     * Local path for generated tests.
     *
     * @var string[]
     */
    protected $localTestsPath = [];

    /**
     * Test method preprocessor closure.
     *
     * @var PreprocessorInterface[]
     */
    private $testPreprocessors = [];

    /**
     * TestClass constructor.
     *
     * @param string $psrNamespaceType
     * @param string $sourceFolderName
     * @param string $sourceTestFolder
     * @param string $unitTestFolder
     */
    public function __construct(
        string $psrNamespaceType = self::PSR_NAMESPACE_TYPE_4,
        string $sourceFolderName = self::DEFAULT_SOURCE_FOLDER_NAME,
        string $sourceTestFolder = self::DEFAULT_TEST_FOLDER,
        string $unitTestFolder = self::DEFAULT_TEST_UNIT_FOLDER
    ) {
        $this->psrNamespaceType = $psrNamespaceType;
        $this->sourceFolderName = $sourceFolderName;
        $this->sourceTestFolder = $sourceTestFolder;
        $this->unitTestFolder = $unitTestFolder;
    }

    /**
     * Generate test.
     *
     * @param string $className
     *
     * @throws Exception
     */
    public function generate(string $className): void
    {
        if (file_exists($className)) {
            $this->sourcePath = $className;
        } elseif (class_exists($className)) {
            $filename = (new ReflectionClass($className))->getFileName();

            if (false === $filename) {
                throw new FileNotExistsException(sprintf('File \'%s\' does not exists.', $className));
            }
            $this->sourcePath = $filename;
        } else {
            throw new RuntimeException(sprintf("Source '%s' doesn't exists!", $className));
        }

        $item = new SplFileInfo($this->sourcePath);
        $this->generateTest($item);
    }

    /**
     * Generate skeleton for source class.
     *
     * @param SplFileInfo $item
     *
     * @throws Exception
     */
    protected function generateTest(SplFileInfo $item): void
    {
        $sourcePath = $item->getPath();
        $sourceFileName = $item->getFilename();
        $sourceClassName = $this->getClassName($sourcePath . DIRECTORY_SEPARATOR . $sourceFileName);

        if (!$sourceClassName) {
            return;
        }
        $testPathCredentials = $this->getTestPathFromSource($sourcePath, $sourceClassName['namespace']);

        if (
            !file_exists($testPathCredentials['testPath']) &&
            !@mkdir($testPathCredentials['testPath'], 0755, true) &&
            !is_dir($testPathCredentials['testPath'])
        ) {
            throw new Exception(sprintf('Directory \'%s\' was not created!', $testPathCredentials['testPath']));
        }
        $generator = new TestGenerator(
            '\\' . $sourceClassName['namespace'] . '\\' . $sourceClassName['class'],
            $sourcePath . DIRECTORY_SEPARATOR . $sourceFileName,
            '\\' . $testPathCredentials['testNamespace'] . '\\' . $sourceClassName['class'] . self::DEFAULT_TEST_PREFIX,
            $testPathCredentials['testPath'] . DIRECTORY_SEPARATOR . $item->getBasename('.' . $item->getExtension()) . self::DEFAULT_TEST_PREFIX . '.' . $item->getExtension(),
            $testPathCredentials['baseNamespace'],
            $testPathCredentials['baseTestNamespace'],
            MockGenerator::MOCK_TYPE_MOCKERY,
            $testPathCredentials['dataProviderTestPath'],
            $testPathCredentials['dataProviderNamespace'],
            $testPathCredentials['mockTestPath'],
            $testPathCredentials['mockNamespace'],
            $testPathCredentials['projectNamespace']
        );

        $testPreprocessor = $this->getTestPreprocessor($sourceClassName['namespace'] . '\\' . $sourceClassName['class']);

        if ($testPreprocessor) {
            $generator->setTestPreprocessor($this->getTestPreprocessor($sourceClassName['namespace'] . '\\' . $sourceClassName['class']));
        }
        $generator->write();
        $this->localTestsPath[] = $testPathCredentials['localTestPath'] . DIRECTORY_SEPARATOR . $sourceFileName;
    }

    /**
     * Analyze source path and return namespace and origin class name.
     *
     * @param string $sourceFilePath
     *
     * @return mixed[]
     *
     * @throws Exception
     */
    protected function getClassName(string $sourceFilePath): array
    {
        $namespace = 0;
        $sourceCode = file_get_contents($sourceFilePath);

        if (false === $sourceCode) {
            throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $sourceFilePath));
        }
        $tokens = token_get_all($sourceCode);
        $count = count($tokens);
        $dlm = false;
        $class = [];

        for ($i = 2; $i < $count; ++$i) {
            if ((isset($tokens[$i - 2][1]) && ('phpnamespace' === $tokens[$i - 2][1] || 'namespace' === $tokens[$i - 2][1])) ||
                ($dlm && T_NS_SEPARATOR === $tokens[$i - 1][0] && T_STRING === $tokens[$i][0])
            ) {
                if (!$dlm) {
                    $namespace = 0;
                }

                if (isset($tokens[$i][1])) {
                    $namespace = $namespace ? $namespace . '\\' . $tokens[$i][1] : $tokens[$i][1];
                    $dlm = true;
                }
            } elseif ($dlm && (T_NS_SEPARATOR !== $tokens[$i][0]) && (T_STRING !== $tokens[$i][0])) {
                $dlm = false;
            }

            if ((T_CLASS === $tokens[$i - 2][0] || (isset($tokens[$i - 2][1]) && 'phpclass' === $tokens[$i - 2][1]))
                && T_WHITESPACE === $tokens[$i - 1][0] && T_STRING === $tokens[$i][0]) {
                $class['namespace'] = $namespace;
                $class['class'] = $tokens[$i][1];
            }
        }

        return $class;
    }

    /**
     * Generate test folder for new tests.
     *
     * @param string $sourcePath
     * @param string $namespace
     *
     * @return string[]
     */
    private function getTestPathFromSource(string $sourcePath, string $namespace): array
    {
        $namespace = trim(str_replace('\\', DIRECTORY_SEPARATOR, $namespace), DIRECTORY_SEPARATOR);
        $sourcePath = trim($sourcePath, DIRECTORY_SEPARATOR);
        $testFolderPosition = 1;

        switch ($this->psrNamespaceType) {
            case self::PSR_NAMESPACE_TYPE_1:
                $sourcePath = str_replace($namespace, '', $sourcePath);

                break;

            case self::PSR_NAMESPACE_TYPE_4:
                $treePath = array_reverse(explode(DIRECTORY_SEPARATOR, $sourcePath));
                $treeNamespace = array_reverse(explode(DIRECTORY_SEPARATOR, $namespace));
                $sourcePath = [];
                $testFolderPosition = 0;

                foreach ($treePath as $i => $part) {
                    if ($part === $treeNamespace[$i]) {
                        ++$testFolderPosition;

                        continue;
                    }
                    array_unshift($sourcePath, $part);
                }
                $sourcePath = implode(DIRECTORY_SEPARATOR, $sourcePath);

                break;
        }

        $treePath = explode(DIRECTORY_SEPARATOR, $sourcePath);
        $localTestPath = '';
        $step = 1;
        $testPath = implode(DIRECTORY_SEPARATOR, array_slice($treePath, 0, count($treePath) - $step));
        $testPath .= DIRECTORY_SEPARATOR . $this->sourceTestFolder . DIRECTORY_SEPARATOR . $this->unitTestFolder;
        $dataProviderTestPath = $testPath . DIRECTORY_SEPARATOR . self::DEFAULT_DATA_PROVIDER_NAMESPACE;
        $mockTestPath = $testPath . DIRECTORY_SEPARATOR . self::DEFAULT_MOCK_NAMESPACE;
        $namespace = explode(DIRECTORY_SEPARATOR, $namespace);
        $projectNamespace = $namespace;
        $baseNamespace = array_splice($projectNamespace, -$testFolderPosition);
        $basePath = implode(DIRECTORY_SEPARATOR, $baseNamespace);
        $baseNamespace = implode('\\', $baseNamespace);
        $projectNamespace = implode('\\', $projectNamespace);
        $dataProviderNamespace = $projectNamespace . '\\' . self::DEFAULT_TEST_NAMESPACE . '\\' . $this->unitTestFolder . '\\' . self::DEFAULT_DATA_PROVIDER_NAMESPACE;
        $mockNamespace = $projectNamespace . '\\' . self::DEFAULT_TEST_NAMESPACE . '\\' . $this->unitTestFolder . '\\' . self::DEFAULT_MOCK_NAMESPACE;
        $baseTestNamespace = $projectNamespace . '\\' . self::DEFAULT_TEST_NAMESPACE . '\\' . $this->unitTestFolder;
        array_splice($namespace, -$testFolderPosition, 0, [self::DEFAULT_TEST_NAMESPACE, $this->unitTestFolder]);
        $testNamespace = implode('\\', $namespace);
        $localTestPath .= self::DEFAULT_TEST_NAMESPACE . DIRECTORY_SEPARATOR . $this->unitTestFolder;
        $testPath .= DIRECTORY_SEPARATOR . $basePath;
        $localTestPath .= DIRECTORY_SEPARATOR . $basePath;

        return [
            'testPath' => DIRECTORY_SEPARATOR . $testPath,
            'localTestPath' => $localTestPath,
            'baseTestNamespace' => $baseTestNamespace,
            'testNamespace' => $testNamespace,
            'baseNamespace' => $baseNamespace,
            'dataProviderTestPath' => DIRECTORY_SEPARATOR . $dataProviderTestPath,
            'dataProviderNamespace' => $dataProviderNamespace,
            'mockTestPath' => DIRECTORY_SEPARATOR . $mockTestPath,
            'mockNamespace' => $mockNamespace,
            'projectNamespace' => $projectNamespace,
        ];
    }

    /**
     * Set preprocessor test generator.
     *
     * @param string $className
     *
     * @return PreprocessorInterface
     */
    public function getTestPreprocessor(string $className): ?PreprocessorInterface
    {
        return $this->testPreprocessors[$className] ?? null;
    }

    /**
     * Get preprocessor test generator.
     *
     * @param string $className
     * @param PreprocessorInterface $testPreprocessor
     */
    public function setTestPreprocessor(string $className, PreprocessorInterface $testPreprocessor): void
    {
        $this->testPreprocessors[$className] = $testPreprocessor;
    }
}
