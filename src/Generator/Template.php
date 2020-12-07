<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use Exception;
use RuntimeException;

/**
 * A simple template engine.
 *
 * @category   Text
 *
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 *
 * @SuppressWarnings(PHPMD)
 */
class Template
{
    /**
     * Template name.
     *
     * @var string
     */
    protected $template = '';

    /**
     * Template open tag delimiter.
     *
     * @var string
     */
    protected $openDelimiter = '{';

    /**
     * Template close tag delimiter.
     *
     * @var string
     */
    protected $closeDelimiter = '}';

    /**
     * Template values.
     *
     * @var mixed[]
     */
    protected $values = [];

    /**
     * Template constructor.
     *
     * @param string $file
     * @param string $openDelimiter
     * @param string $closeDelimiter
     *
     * @throws Exception
     */
    public function __construct(string $file = '', string $openDelimiter = '{', string $closeDelimiter = '}')
    {
        $this->setFile($file);
        $this->openDelimiter = $openDelimiter;
        $this->closeDelimiter = $closeDelimiter;
    }

    /**
     * Sets the template file.
     *
     * @param string $file
     *
     * @throws Exception
     */
    public function setFile(string $file): void
    {
        $distFile = $file . '.dist';

        if (file_exists($file)) {
            $template = file_get_contents($file);

            if (false === $template) {
                throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $file));
            }
        } elseif (file_exists($distFile)) {
            $template = file_get_contents($distFile);

            if (false === $template) {
                throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $distFile));
            }
        } else {
            throw new RuntimeException(
                'Template file could not be loaded.'
            );
        }
        $this->template = $template;
    }

    /**
     * Sets one or more template variables.
     *
     * @param mixed[] $values
     * @param bool    $merge
     */
    public function setVar(array $values, bool $merge = true): void
    {
        if (!$merge || empty($this->values)) {
            $this->values = $values;
        } else {
            $this->values = array_merge($this->values, $values);
        }
    }

    /**
     * Renders the template and returns the result.
     *
     * @return string
     */
    public function render(): string
    {
        $keys = [];

        foreach (array_keys($this->values) as $key) {
            $keys[] = $this->openDelimiter . $key . $this->closeDelimiter;
        }

        return str_replace($keys, $this->values, $this->template);
    }

    /**
     * Renders the template and writes the result to a file.
     *
     * @param string $target
     */
    public function renderTo(string $target): void
    {
        $fp = @fopen($target, 'wt');

        if ($fp) {
            fwrite($fp, $this->render());
            fclose($fp);
        } else {
            $error = error_get_last();

            if (null === $error) {
                $error = ['message' => sprintf('Error with writing into \'%s\' file.', $target)];
            }

            $pos = strpos($error['message'], ':');

            if (false !== $pos) {
                $error['message'] = substr($error['message'], $pos + 2);
            }

            throw new RuntimeException(sprintf('Could not write to %s: %s', $target, $error['message']));
        }
    }
}
