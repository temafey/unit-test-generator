<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

use Exception;
use RuntimeException;

/**
 * Generator for base test class skeletons from classes.
 *
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 */
class BaseTestGenerator extends AbstractGenerator
{
    /**
     * Constructor.
     *
     * @param string $outClassName
     * @param string $outSourceFile
     * @param string $baseNamespace
     *
     * @throws RuntimeException
     */
    public function __construct(string $outClassName = '', string $outSourceFile = '', string $baseNamespace = '')
    {
        $this->baseNamespace = $baseNamespace;
        $this->outClassName = $this->parseFullyQualifiedClassName($outClassName);
        $this->outSourceFile = str_replace(
            $this->outClassName['fullyQualifiedClassName'],
            $this->outClassName['className'],
            $outSourceFile
        );
    }

    /**
     * Generate base test class.
     *
     * @return string|null
     *
     * @throws Exception
     */
    public function generate(): ?string
    {
        $classTemplate = new Template(
            sprintf(
                '%s%stemplate%sTestBaseClass.tpl',
                __DIR__,
                DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR
            )
        );

        $classTemplate->setVar(
            [
                'namespace' => trim($this->outClassName['namespace'], '\\'),
                'testClassName' => $this->outClassName['className'],
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
            ]
        );

        return $classTemplate->render();
    }
}
