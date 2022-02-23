<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

/**
 * Interface DataTypeInterface.
 */
interface DataTypeInterface
{
    public const TYPE_INT = 'int';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_MIXED = 'mixed';
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_BOOL_TRUE = 'true';
    public const TYPE_BOOL_FALSE = 'false';
    public const TYPE_NULL = 'null';
    public const TYPE_VOID = 'void';
    public const TYPE_ARRAY = 'array';
    public const TYPE_ARRAY_MIXED = 'mixed[]';
    public const TYPE_CLOSURE = '\Closure';
    public const TYPE_CALLABLE = 'callable';
    public const TYPE_THIS = '$this';
    public const TYPE_SELF = 'self';
    public const TYPE_STATIC = 'static';
    public const TYPE_OBJECT = 'object';
    public const TYPE_RESOURCE = 'resource';
    public const TYPE_ = '';
}
