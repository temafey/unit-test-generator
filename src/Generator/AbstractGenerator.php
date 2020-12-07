<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

/**
 * Generator for skeletons.
 *
 * @license http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 */
abstract class AbstractGenerator
{
    public const EXCLUDE_DECLARING_CLASSES = ['Exception'];
    public const EXCLUDE_DECLARING_CLASSES_FOR_METHODS = ['Exception'];
    public const EXCLUDE_DECLARING_METHODS = ['__call', '__get', ' __set', '__isset', '__unset', '__sleep', '__wakeup', '__toString'];
    public const TEST_NAME_PREFIX = 'Mock';

    public const METHOD_NAMES_RETURN_SELF = [
        'fromNative',
    ];

    public const METHODS_RETURN_SELF = [
        DataTypeInterface::TYPE_SELF,
        DataTypeInterface::TYPE_THIS,
        DataTypeInterface::TYPE_STATIC
    ];

    protected const METHOD_TEMPLATE_TYPE_DEFAULT = 'TestMethod';
    protected const METHOD_TEMPLATE_TYPE_BOOL = 'TestMethodBool';
    protected const METHOD_TEMPLATE_TYPE_VOID = 'TestMethodVoid';

    /**
     * Source class name.
     *
     * @var string[]
     */
    protected $inClassName;

    /**
     * Source file path.
     *
     * @var string
     */
    protected $inSourceFile;

    /**
     * Test class name.
     *
     * @var string[]
     */
    protected $outClassName;

    /**
     * Test class path.
     *
     * @var string
     */
    protected $outSourceFile;

    /**
     * Base test namespace.
     *
     * @var string
     */
    protected $baseNamespace;

    /**
     * Return source class name.
     *
     * @return string
     */
    public function getOutClassName(): string
    {
        return $this->outClassName['fullyQualifiedClassName'];
    }

    /**
     * Return source class path.
     *
     * @return string
     */
    public function getOutSourceFile(): string
    {
        return $this->outSourceFile;
    }

    /**
     * Generates the code and writes it to a source file.
     *
     * @param string $file
     *
     * @return bool
     */
    public function write(string $file = ''): bool
    {
        if ('' === $file) {
            $file = $this->outSourceFile;
        }

        if (file_exists($file)) {
            echo "UnitTest '" . $file . "' already exists." . PHP_EOL;

            return false;
        }
        $testCode = $this->generate();

        if (null === $testCode) {
            echo "UnitTest was not created for file '" . $file . "'." . PHP_EOL;

            return false;
        }

        if (file_put_contents($file, $testCode)) {
            echo "UnitTest '" . $file . "' created." . PHP_EOL;

            return true;
        }
        echo "UnitTest was not created for file '" . $file . "'." . PHP_EOL;

        return false;
    }

    /**
     * Parse class name and return namespace, full and sort class name, test base class name.
     *
     * @param string $className
     *
     * @return string[]
     */
    protected function parseFullyQualifiedClassName(string $className): array
    {
        if (0 !== strpos($className, '\\')) {
            $className = '\\' . $className . self::TEST_NAME_PREFIX;
        }
        $result = [
            'namespace' => '',
            'testBaseFullClassName' => '',
            'className' => $className,
            'fullyQualifiedClassName' => $className,
        ];

        if (false !== strpos($className, '\\')) {
            $tmp = explode('\\', $className);
            $result['className'] = $tmp[count($tmp) - 1];
            $result['namespace'] = $this->arrayToName($tmp);

            $testBaseFullClassName = $this->baseNamespace ? $this->baseNamespace . '\\' . $tmp[2] . '\Base' : $tmp[1] . '\Base';
            $result['testBaseFullClassName'] = $testBaseFullClassName;
        }

        return $result;
    }

    /**
     * Build class name from exploded parts.
     *
     * @param string[] $parts
     *
     * @return string
     */
    protected function arrayToName(array $parts): string
    {
        $result = '';

        if (count($parts) > 1) {
            array_pop($parts);
            $result = implode('\\', $parts);
        }

        return $result;
    }

    /**
     * Generate test code.
     *
     * @return string|null
     */
    abstract public function generate(): ?string;
}
