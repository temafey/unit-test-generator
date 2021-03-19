<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator\Helper;

use MicroModule\UnitTestGenerator\Generator\DataTypeInterface;
use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Exception\FileNotExistsException;
use PhpParser\{Node\Stmt\ClassMethod, ParserFactory, Node, NodeFinder};
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;


/**
 * Trait CodeHelper.
 *
 * @SuppressWarnings(PHPMD)
 */
trait CodeHelper
{
    /**
     * Parsed source namespaces.
     *
     * @var mixed[]
     */
    protected $namespaces = [];

    /**
     * Find and return method return type from annotation.
     *
     * @param ReflectionMethod $refMethod
     * @param bool $strictMode
     * @param bool $returnOrigin
     *
     * @return string|null
     *
     * @throws ReturnTypeNotFoundException
     */
    protected function getReturnFromAnnotation(
        ReflectionMethod $refMethod,
        bool $strictMode = true,
        bool $returnOrigin = false
    ): ?string {
        $return = [];
        $docComment = $refMethod->getDocComment();

        if ($docComment) {
            preg_match_all('/@return (.*)$/Um', $docComment, $return);
        }

        if (!isset($return[1][0])) {
            if (!$strictMode) {
                return null;
            }
            $reflection = $refMethod->getDeclaringClass();

            throw new ReturnTypeNotFoundException(
                sprintf(
                    'In class \'%s\' could not find annotation comment for method \'%s\' in source \'%s\'.',
                    $reflection->getName(),
                    $refMethod->getName(),
                    $reflection->getFileName()
                )
            );
        }
        $return = trim($return[1][0], ' \\');

        if (false !== strpos($return, '|')) {
            $return = explode('|', $return);
            $return = (count($return) > 1 && $return[0] === DataTypeInterface::TYPE_NULL) ? $return[1] : $return[0];
        }
        $return = trim($return);

        if (false !== strpos($return, ' ')) {
            $return = explode(' ', $return)[0];
        }

        if (false === $returnOrigin && false !== strpos($return, '[]')) {
            return DataTypeInterface::TYPE_ARRAY;
        }

        return $return;
    }

    /**
     * Find and return method param types from annotation.
     *
     * @param ReflectionMethod $refMethod
     * @param bool $strictMode
     * @param bool $returnOrigin
     *
     * @return string[]|null
     *
     * @throws ReturnTypeNotFoundException
     */
    protected function getParamsFromAnnotation(
        ReflectionMethod $refMethod,
        bool $strictMode = true,
        bool $returnOrigin = false
    ): ?array {
        $annotationParams = [];
        $docComment = $refMethod->getDocComment();

        if (false === $docComment) {
            if (!$strictMode) {
                return null;
            }
            $reflection = $refMethod->getDeclaringClass();

            throw new ReturnTypeNotFoundException(
                sprintf(
                    'In class \'%s\' could not find annotation comment for method \'%s\' in source \'%s\'.',
                    $reflection->getName(),
                    $refMethod->getName(),
                    $reflection->getFileName()
                )
            );
        }

        if (false !== strpos($docComment, '@inheritdoc')) {
            try {
                $docComment = $refMethod->getPrototype()->getDocComment();
            } catch (ReflectionException $e) {
                if (!$strictMode) {
                    return null;
                }
                $reflection = $refMethod->getDeclaringClass();

                throw new ReturnTypeNotFoundException(
                    sprintf(
                        'In class \'%s\' find \'@inheritdoc\' annotation comment, but prototype does not exists, for method \'%s\' in source \'%s\'.',
                        $reflection->getName(),
                        $refMethod->getName(),
                        $reflection->getFileName()
                    )
                );
            }
        }

        if ($docComment) {
            preg_match_all('/@param (.*?) (.*)$/Um', $docComment, $annotationParams);
        }

        if (!isset($annotationParams[1][0])) {
            if (!$strictMode) {
                return null;
            }
            $reflection = $refMethod->getDeclaringClass();

            throw new ReturnTypeNotFoundException(
                sprintf(
                    'In class \'%s\' could not find annotation comment for method \'%s\' in source \'%s\'.',
                    $reflection->getName(),
                    $refMethod->getName(),
                    $reflection->getFileName()
                )
            );
        }
        $annotationParams = $annotationParams[1];

        foreach ($annotationParams as &$annotationParam) {
            $annotationParam = trim($annotationParam, ' \\');

            if (false !== strpos($annotationParam, '|')) {
                $annotationParam = explode('|', $annotationParam);
                $annotationParam = (count($annotationParam) > 1 && $annotationParam[0] === DataTypeInterface::TYPE_NULL) ? $annotationParam[1] : $annotationParam[0];
            }
            $annotationParam = trim($annotationParam);

            if (false !== strpos($annotationParam, ' ')) {
                $annotationParam = explode(' ', $annotationParam)[0];
            }

            if (false === $returnOrigin && false !== strpos($annotationParam, '[]')) {
                $annotationParam = DataTypeInterface::TYPE_ARRAY;
            }
        }

        return $annotationParams;
    }

    /**
     * Parse and find all parent classes and traits.
     *
     * @param ReflectionClass $reflection
     *
     * @return mixed[]
     */
    public function getParentClassesAndTraits(ReflectionClass $reflection): array
    {
        $traitsNames = [];
        $parentClasses = [];
        $recursiveClasses = static function (ReflectionClass $class) use (&$recursiveClasses, &$traitsNames, &$parentClasses): void {
            if (false !== $class->getParentClass()) {
                $parentClass = $class->getParentClass()->getName();

                if (in_array($parentClass, $parentClasses, true)) {
                    return;
                }
                $parentClasses[] = $parentClass;
                $recursiveClasses($class->getParentClass());
            } else {
                $reflectionTraits = $class->getTraitNames();

                if ($reflectionTraits) {
                    $traitsNames = array_merge($traitsNames, $reflectionTraits);
                }
            }
        };
        $recursiveClasses($reflection);

        return [$parentClasses, $traitsNames];
    }

    /**
     * Return all namespaces from parent classes and traits.
     *
     * @param ReflectionClass $reflection
     *
     * @return string[]
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     * @throws ReflectionException
     */
    public function getNamespacesFromParentClassesAndTraits(ReflectionClass $reflection): array
    {
        $namespaces = [];
        [$parentClasses, $extendedTraits] = $this->getParentClassesAndTraits($reflection);
        unset($parentClasses);

        if (!$extendedTraits) {
            return $namespaces;
        }

        foreach ($extendedTraits as $trait) {
            $reflection = new ReflectionClass($trait);
            $filename = $reflection->getFileName();

            if (false === $filename) {
                throw new FileNotExistsException(sprintf('Trait \'%s\' does not exists.', $trait));
            }
            $namespaces[] = $this->getNamespacesFromSource($filename);
        }

        return array_merge(...$namespaces);
    }

    /**
     * Return all namespaces source.
     *
     * @param string $sourceName
     *
     * @return string[]
     *
     * @throws CodeExtractException
     */
    public function getNamespacesFromSource(string $sourceName): array
    {
        if (isset($this->namespaces[$sourceName])) {
            return $this->namespaces[$sourceName];
        }
        $namespaces = [];
        $sourceCode = file_get_contents($sourceName);

        if (false === $sourceCode) {
            throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $sourceName));
        }
        $tokens = token_get_all($sourceCode);
        $iMax = count($tokens);

        for ($i = 0; $i < $iMax; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && T_USE === $token[0]) {
                $y = 0;
                ++$i;
                $namespace = [];
                $alias = false;
                $namespaceFinish = false;

                foreach (array_slice($tokens, $i) as $y => $useToken) {
                    if (
                        is_array($useToken) &&
                        (
                            T_WHITESPACE === $useToken[0] ||
                            T_NS_SEPARATOR === $useToken[0]
                        )
                    ) {
                        continue;
                    }

                    if (is_array($useToken) && T_NAME_QUALIFIED === $useToken[0]) {
                        $useToken[1] = trim($useToken[1]);

                        if (!$namespaceFinish) {
                            $namespace[] = $useToken[1];
                        } else {
                            $alias = $useToken[1];
                        }
                    } elseif (is_string($useToken)) {
                        if (',' === $useToken) {
                            if (false === $alias) {
                                $alias = end($namespace);
                            }
                            $namespace = implode('\\', $namespace);
                            $namespaces[$alias] = $namespace;
                            $namespace = [];
                            $alias = false;
                            $namespaceFinish = false;
                        } elseif (';' === $useToken) {
                            break;
                        }
                    } elseif (is_array($useToken) && T_AS === $useToken[0]) {
                        $namespaceFinish = true;
                    }
                }

                if (false === $alias) {
                    $alias = end($namespace);
                }
                $namespace = implode('\\', $namespace);
                $namespaces[$alias] = $namespace;

                $i += $y;
            }
            if (is_array($token) && T_CLASS === $token[0]) {
                break;
            }
        }
        $this->namespaces[$sourceName] = $namespaces;

        return $this->namespaces[$sourceName];
    }

    /**
     * Parse the use statements from read source by
     * tokenizing and reading the tokens. Returns
     * an array of use statements and aliases.
     *
     * @param ReflectionClass $reflection
     *
     * @return mixed[]
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     */
    private function parseUseStatements(ReflectionClass $reflection): array
    {
        if (!defined('T_NAME_QUALIFIED')) {
            define('T_NAME_QUALIFIED', 311);
        }
        $filename = $reflection->getFileName();

        if (false === $filename) {
            throw new FileNotExistsException(sprintf('Trait \'%s\' does not exists.', $reflection->getName()));
        }
        $sourceCode = file_get_contents($filename);

        if (false === $sourceCode) {
            throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $filename));
        }
        $tokens = token_get_all($sourceCode);
        $builtNamespace = '';
        $buildingNamespace = false;
        $matchedNamespace = false;
        $useStatements = [];
        $record = false;
        $currentUse = [
            'class' => '',
            'as' => '',
        ];

        foreach ($tokens as $token) {
            if (T_NAMESPACE === $token[0]) {
                $buildingNamespace = true;

                if ($matchedNamespace) {
                    break;
                }
            }

            if ($buildingNamespace) {
                if (';' === $token) {
                    $buildingNamespace = false;

                    continue;
                }
                switch ($token[0]) {
                    case T_STRING:
                    case T_NAME_QUALIFIED:
                    case T_NS_SEPARATOR:
                        $builtNamespace .= $token[1];

                        break;
                }

                continue;
            }

            if (';' === $token || !is_array($token)) {
                if ($record) {
                    $useStatements[] = $currentUse;
                    $record = false;
                    $currentUse = [
                        'class' => '',
                        'as' => '',
                    ];
                }

                continue;
            }

            if (T_CLASS === $token[0]) {
                break;
            }

            if (0 === strcasecmp($builtNamespace, $reflection->getNamespaceName())) {
                $matchedNamespace = true;
            }

            if ($matchedNamespace) {
                if (T_USE === $token[0]) {
                    $record = 'class';
                }

                if (T_AS === $token[0]) {
                    $record = 'as';
                }

                if ($record) {
                    switch ($token[0]) {
                        case T_STRING:
                        case T_NAME_QUALIFIED:
                        case T_NS_SEPARATOR:
                            $currentUse[$record] .= $token[1];

                            break;
                    }
                }
            }

            if ($token[2] >= $reflection->getStartLine()) {
                break;
            }
        }
        // Make sure the as key has the name of the class even
        // if there is no alias in the use statement.
        foreach ($useStatements as &$useStatement) {
            if (empty($useStatement['as'])) {
                $useStatement['as'] = basename($useStatement['class']);
            }
        }

        return $useStatements;
    }

    /**
     * Return all methods from source.
     *
     * @param string $sourceName
     *
     * @return string[]
     *
     * @throws CodeExtractException
     */
    public function parseMethodsFromSource(string $sourceName): array
    {
        $methods = [];
        $sourceCode = file_get_contents($sourceName);

        if (false === $sourceCode) {
            throw new CodeExtractException(sprintf('Code can not be extract from source \'%s\'.', $sourceName));
        }
        $tokens = token_get_all($sourceCode);
        $iMax = count($tokens);

        for ($i = 0; $i < $iMax; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && T_FUNCTION === $token[0]) {
                $y = 0;
                ++$i;
                $method = '';

                foreach (array_slice($tokens, $i) as $y => $methodToken) {
                    if (
                        is_array($methodToken) &&
                        (
                            T_WHITESPACE === $methodToken[0] ||
                            T_NS_SEPARATOR === $methodToken[0]
                        )
                    ) {
                        continue;
                    }

                    if (is_array($methodToken) && T_STRING === $methodToken[0]) {
                        $methodToken[1] = trim($methodToken[1]);
                        $method = $methodToken[1];
                    } elseif (is_string($methodToken)) {
                        if ('(' === $methodToken) {
                            break;
                        }
                    }
                }
                $methods[] = $method;
                $i += $y;
            }
        }

        return $methods;
    }

    /**
     * Find and return source method return type.
     *
     * @param ReflectionMethod $reflectionMethod
     *
     * @return string
     *
     * @throws ReturnTypeNotFoundException
     */
    protected function getMethodReturnType(ReflectionMethod $reflectionMethod): string
    {
        $returnType = null;
        $className = $reflectionMethod->getDeclaringClass()->getName();

        if (in_array($reflectionMethod->getName(), self::METHOD_NAMES_RETURN_SELF, true)) {
            $returnType = $className;
        } else {
            $returnType = $reflectionMethod->getReturnType();

            if (null !== $returnType) {
                $returnType = $returnType->getName();
            }
        }

        if (!$returnType || $returnType === 'array') {
            $returnType = $this->getReturnFromAnnotation($reflectionMethod, false, true);

            if (null === $returnType) {
                $returnType = DataTypeInterface::TYPE_MIXED;
            }
        }

        if (in_array($returnType, self::METHODS_RETURN_SELF, true)) {
            $returnType = $className;
        }

        return $returnType;
    }

    /**
     * Process ReflectionClass from method annotation name and return class name.
     *
     * @param string $annotationName
     * @param ReflectionClass $reflectionClass
     *
     * @return string
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     */
    protected function getClassNameFromComplexAnnotationName(string $annotationName, ReflectionClass $reflectionClass): string
    {
        $annotationName = str_replace('[]', '', $annotationName);
        $useStatements = $this->parseUseStatements($reflectionClass);
        $fullClassName = current(array_filter($useStatements, static function (array $element) use ($annotationName) {
            if (!(class_exists($element['class']) || interface_exists($element['class']))) {
                return false;
            }
            $reflection = new ReflectionClass($element['class']);

            return ($element['as'] === $annotationName || $reflection->getShortName() === $annotationName) ?? true;
        }));

        if (is_array($fullClassName) && isset($fullClassName['class'])) {
            $annotationName = $fullClassName['class'];
        } else {
            $annotationName = $reflectionClass->getNamespaceName() . '\\' . $annotationName;
        }

        return $annotationName;
    }

    /**
     * Validate and try to find full class name from class use statement.
     *
     * @param string $className
     * @param ReflectionClass $reflectionClass
     *
     * @return string|null
     *
     * @throws CodeExtractException
     * @throws FileNotExistsException
     */
    protected function findAndReturnClassNameFromUseStatement(string $className, ReflectionClass $reflectionClass): ?string
    {
        if(
            in_array($className, [
                DataTypeInterface::TYPE_INT,
                DataTypeInterface::TYPE_INTEGER,
                DataTypeInterface::TYPE_FLOAT,
                DataTypeInterface::TYPE_MIXED,
                DataTypeInterface::TYPE_STRING,
                DataTypeInterface::TYPE_BOOL,
                DataTypeInterface::TYPE_BOOLEAN,
                DataTypeInterface::TYPE_BOOL_TRUE,
                DataTypeInterface::TYPE_BOOL_FALSE,
                DataTypeInterface::TYPE_NULL,
                DataTypeInterface::TYPE_VOID,
                DataTypeInterface::TYPE_ARRAY_MIXED,
                DataTypeInterface::TYPE_CLOSURE,
                DataTypeInterface::TYPE_SELF,
            ], true) ||
            !$reflectionClass->getFileName()
        ) {
            return null;
        }
        $className = $this->getClassNameFromComplexAnnotationName($className, $reflectionClass);

        if (!class_exists($className) && !interface_exists($className)) {
            return null;
        }

        return $className;
    }

    /**
     * @param ReflectionMethod $method
     *
     * @return Node|null
     *
     * @throws FileNotExistsException
     */
    protected function findAndReturnMethodAstStmtByName(ReflectionMethod $method)
    {
        $phpCodeGenerator = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $reflectionClass = $method->getDeclaringClass();
        $filename = $reflectionClass->getFileName();

        if (false === $filename) {
            throw new FileNotExistsException(sprintf('Trait \'%s\' does not exists.', $filename));
        }
        $sourceCode = file_get_contents($reflectionClass->getFileName());
        $stmts = $phpCodeGenerator->parse($sourceCode);
        $methodName = $method->getName();
        $nodeFinder = new NodeFinder;
        $stmtMethod = $nodeFinder->findFirst($stmts, function(Node $node) use ($methodName) {
            return $node instanceof ClassMethod
                && $node->name->toString() === $methodName;
        });

        return $stmtMethod;
    }

    /**
     * @param ClassMethod $methodStmt
     * @param string $methodName
     *
     * @return array
     */
    protected function findCallMethodsByNameFromAstStmt(ClassMethod $methodStmt, string $methodName): array
    {
        $nodeFinder = new NodeFinder;

        return $nodeFinder->find($methodStmt, function(Node $node) use ($methodName) {
            return $node instanceof Node\Expr\MethodCall
                && $node->name->toString() === $methodName;
        });
    }

    /**
     * @param array $stmtMethods
     * @param string $pattern
     *
     * @return array
     */
    protected function findCallMethodsByPatternFromAstStmts(array $stmtMethods, string $pattern): array
    {
        $nodeFinder = new NodeFinder;
        $stmtCallMethods = [];

        foreach ($stmtMethods as $stmtMethod) {
            $stmtCallMethods[] = $nodeFinder->findFirst($stmtMethod, function(Node $node) use ($pattern) {
                return $node instanceof Node\Expr\MethodCall
                    && preg_match($pattern, $node->name->toString());
            });
        }

        return $stmtCallMethods;
    }
}
