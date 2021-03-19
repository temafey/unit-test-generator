<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Generator;

use MicroModule\UnitTestGenerator\Generator\Exception\CodeExtractException;
use MicroModule\UnitTestGenerator\Generator\Helper\CodeHelper;
use MicroModule\UnitTestGenerator\Generator\Helper\ReturnTypeNotFoundException;
use Exception;
use Faker\Factory;
use Faker\Generator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

/**
 * Class DataProviderGenerator.
 * Generator for test class skeletons from classes.
 *
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 *
 * @SuppressWarnings(PHPMD)
 */
class DataProviderGenerator extends AbstractGenerator
{
    use CodeHelper;

    private const DATA_PROVIDER_SUFFIX = 'DataProvider';

    private const DATA_PROVIDER_DEFAULT_ARGS = 'defaultArgs';

    /**
     * Method names, that should return self value.
     *
     * @var string[]
     */
    private static $selfMethods = [
        'toNative',
        'generateAsString',
        'toString',
    ];

    /**
     * Method names, that should be excluded.
     *
     * @var string[]
     */
    private static $excludeMethods = [
        'fromNative',
        'fromArray',
        'toReal',
        'toNatural',
        'toInteger',
        '__toString'
    ];

    private const FAKE_BASE_PROVIDERS = [
        DataTypeInterface::TYPE_BOOL           => 'boolean',
        DataTypeInterface::TYPE_INT            => 'randomDigitNotNull',
        DataTypeInterface::TYPE_FLOAT          => 'randomFloat',
        DataTypeInterface::TYPE_STRING         => 'word',
        DataTypeInterface::TYPE_MIXED          => 'word',
        DataTypeInterface::TYPE_ARRAY          => 'words',
        DataTypeInterface::TYPE_ARRAY_MIXED    => 'words',
    ];

    private const FAKE_PROVIDERS = [
        'Lorem' => [
            'text'
        ],
        'Person' => [
            'titleMale',                    // 'Mr.'
            'titleFemale',                  // 'Ms.'
            'name' => ['user', 'member'],   // 'Dr. Zane Stroman'
            'firstName',                    // 'Maynard'
            'firstNameMale',                // 'Maynard'
            'firstNameFemale',              // 'Rachel'
            'lastName',                     // 'Zulauf'
        ],
        'Address' => [
            'address',
            'cityPrefix',                   // 'Lake'
            'secondaryAddress',             // 'Suite 961'
            'state',                        // 'NewMexico'
            'stateAbbr',                    // 'OH'
            'citySuffix',                   // 'borough'
            'streetSuffix',                 // 'Keys'
            'buildingNumber',               // '484'
            'city',                         // 'West Judge'
            'streetName',                   // 'Keegan Trail'
            'streetAddress',                // '439 Karley Loaf Suite 897'
            'postcode',                     // '17916'
            'address',                      // '8888 Cummings Vista Apt. 101, Susanbury, NY 95473'
            'country',                      // 'Falkland Islands (Malvinas)'
            'latitude',                     // 77.147489
            'longitude',                    // 86.211205
        ],
        'Phone' => [
            'phoneNumber' => ['phone'],     // '201-886-0269 x3767'
            'tollFreePhoneNumber',          // '(888) 937-7238'
            'e164PhoneNumber',              // '+27113456789'
        ],
        'Company' => [
            'catchPhrase',                  // 'Monitored regional contingency'
            'company',                      // 'Bogan-Treutel'
            'companySuffix',                // 'and Sons'
            'jobTitle',                     // 'Cashier'
        ],
        'Text' => [
            'realText' => ['content', 'text', 'article', 'description']
        ],
        'DateTime' => [
            'unixTime',                 // 58781813
            'dateTime',                 // DateTime('2008-04-25 08:37:17', 'UTC')
            'dateTimeAD',               // DateTime('1800-04-29 20:38:49', 'Europe/Paris')
            'iso8601',                  // '1978-12-09T10:10:29+0000'
            'date',                     // '1979-06-09'
            'time',                     // '20:49:42'
            'dateTimeBetween',          // DateTime('2003-03-15 02:00:49', 'Africa/Lagos')
            'dateTimeInInterval',       // DateTime('2003-03-15 02:00:49', 'Antartica/Vostok')
            'dateTimeThisCentury',      // DateTime('1915-05-30 19:28:21', 'UTC')
            'dateTimeThisDecade',       // DateTime('2007-05-29 22:30:48', 'Europe/Paris')
            'dateTimeThisYear',         // DateTime('2011-02-27 20:52:14', 'Africa/Lagos')
            'dateTimeThisMonth',        // DateTime('2011-10-23 13:46:23', 'Antarctica/Vostok')
            'dayOfMonth',               // '04'
            'dayOfWeek',                // 'Friday'
            'month',                    // '06'
            'monthName',                // 'January'
            'year',                     // '1993'
            'century',                  // 'VI'
            'timezone',                 // 'Europe/Paris'
        ],
        'Internet' => [
            'email',                   // 'tkshlerin@collins.com'
            'safeEmail',               // 'king.alford@example.org'
            'freeEmail',               // 'bradley72@gmail.com'
            'companyEmail',            // 'russel.durward@mcdermott.org'
            'freeEmailDomain',         // 'yahoo.com'
            'safeEmailDomain',         // 'example.org'
            'userName',                // 'wade55'
            'password',                // 'k&|X+a45*2['
            'domainName',              // 'wolffdeckow.net'
            'domainWord',              // 'feeney'
            'tld',                     // 'biz'
            'url',                     // 'http://www.skilesdonnelly.biz/aut-accusantium-ut-architecto-sit-et.html'
            'slug',                    // 'aut-repellat-commodi-vel-itaque-nihil-id-saepe-nostrum'
            'ipv4',                    // '109.133.32.252'
            'localIpv4',               // '10.242.58.8'
            'ipv6',                    // '8e65:933d:22ee:a232:f1c1:2741:1f10:117c'
            'macAddress',              // '43:85:B7:08:10:CA'
        ],
        'UserAgent' => [
            'userAgent',              // 'Mozilla/5.0 (Windows CE) AppleWebKit/5350 (KHTML, like Gecko) Chrome/13.0.888.0 Safari/5350'
            'chrome',                 // 'Mozilla/5.0 (Macintosh; PPC Mac OS X 10_6_5) AppleWebKit/5312 (KHTML, like Gecko) Chrome/14.0.894.0 Safari/5312'
            'firefox',                // 'Mozilla/5.0 (X11; Linuxi686; rv:7.0) Gecko/20101231 Firefox/3.6'
            'safari',                 // 'Mozilla/5.0 (Macintosh; U; PPC Mac OS X 10_7_1 rv:3.0; en-US) AppleWebKit/534.11.3 (KHTML, like Gecko) Version/4.0 Safari/534.11.3'
            'opera',                  // 'Opera/8.25 (Windows NT 5.1; en-US) Presto/2.9.188 Version/10.00'
            'internetExplorer',       // 'Mozilla/5.0 (compatible; MSIE 7.0; Windows 98; Win 9x 4.90; Trident/3.0)'
        ],
        'Payment' => [
            'creditCardType',                       // 'MasterCard'
            'creditCardNumber' => ['creditCard', ], // '4485480221084675'
            'creditCardExpirationDate',             // 04/13
            'creditCardExpirationDateString',       // '04/13'
            'creditCardDetails',                    // array('MasterCard', '4485480221084675', 'Aleksander Nowak', '04/13')
            'iban',                                 // 'IT31A8497112740YZ575DJ28BP4'
            'swiftBicNumber',                       // 'RZTIAT22263'
        ],
        'Color' => [
            'color'
        ],
//        'File' => [
//            'file'
//        ],
        'Image' => [
            'imageUrl',
            'image' => ['img', 'image', 'jpg'],
        ],
        'Uuid' => [
            'uuid'
        ],
        'Barcode' => [
            'barcode'
        ],
        'Miscellaneous' => [
            'boolean', // false
            'md5',           // 'de99a620c50f2990e87144735cd357e7'
            'sha1',          // 'f08e7f04ca1a413807ebc47551a40a20a0b4de5c'
            'sha256',        // '0061e4c60dac5c1d82db0135a42e00c89ae3a333e7c26485321f24348c7e98a5'
            'locale',        // en_UK
            'countryCode',   // UK
            'languageCode',  // en
            'currencyCode',  // EUR
            'emoji',         // 😁
        ],
        'Html' => [
            'randomHtml' => ['html']
        ],
    ];

    /**
     * Data providers code.
     *
     * @var mixed[]
     */
    protected $dataProviders = [];

    /**
     * Path to save dataProvider helper.
     *
     * @var string
     */
    private $dataProviderTestPath;

    /**
     * DataProvider namespace.
     *
     * @var string
     */
    private $dataProviderNamespace;

    /**
     * Project namespace.
     *
     * @var string
     */
    private $projectNamespace;

    /**
     * Fake data generator.
     *
     * @var Generator
     */
    private $faker;

    /**
     * Is throw exception if return type was not found.
     *
     * @var bool
     */
    private $returnTypeNotFoundThrowable = false;

    /**
     * Count of test data sets.
     *
     * @var int
     */
    private $countDataSets = 1;

    /**
     * Deep level to build data for mocked object.
     *
     * @var int
     */
    private $mockDeepLevel = 4;

    /**
     * TestGenerator constructor.
     *
     * @param string $dataProviderTestPath
     * @param string $dataProviderNamespace
     * @param string $baseNamespace
     * @param string $projectNamespace
     */
    public function __construct(
        string $dataProviderTestPath,
        string $dataProviderNamespace,
        string $baseNamespace,
        string $projectNamespace
    ) {
        $this->dataProviderTestPath = $dataProviderTestPath;
        $this->dataProviderNamespace = $dataProviderNamespace;
        $this->baseNamespace = $baseNamespace;
        $this->projectNamespace = $projectNamespace;
        $this->faker = Factory::create();
    }

    /**
     * Build and return dataProvider file path.
     *
     * @param string $className
     *
     * @return string[]
     */
    private function getDataProviderPathAndNamespace(string $className): array
    {
        $pos = strpos($className, $this->projectNamespace);

        if (false === $pos) {
            $dataProviderClassName = ucfirst(str_replace('\\', '', $className)) . self::DATA_PROVIDER_SUFFIX;
            $dataProviderFilePath = $this->dataProviderTestPath . DIRECTORY_SEPARATOR . 'Common' . DIRECTORY_SEPARATOR . $dataProviderClassName . '.php';
            $dataProviderFullClassName = '\\' .$this->dataProviderNamespace . '\\'  . 'Common' . '\\'  . $dataProviderClassName;
            $dataProviderName = ucfirst(str_replace('\\', '', $dataProviderClassName));
            $pos = strrpos($dataProviderFullClassName, '\\');

            if (false === $pos) {
                $pos = strlen($dataProviderFullClassName);
            }
            $dataProviderNamespace = substr($dataProviderFullClassName, 0, $pos);

            return [$dataProviderFilePath, $dataProviderNamespace, $dataProviderClassName, $dataProviderFullClassName, $dataProviderName];
        }
        $tmpNamespace = substr($className, strlen($this->projectNamespace) + 1);
        $tmpNamespaces = explode('\\', $tmpNamespace);
        $tmpNamespace = implode('\\', array_slice($tmpNamespaces, 0, count($tmpNamespaces) - 1));
        $tmpClassName = implode('\\', array_slice($tmpNamespaces, count($tmpNamespaces) - 1, 1));
        $dataProviderFilePath = $this->dataProviderTestPath . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $tmpNamespace) . DIRECTORY_SEPARATOR . $tmpClassName . self::DATA_PROVIDER_SUFFIX . '.php';
        $dataProviderClassName = $tmpClassName . self::DATA_PROVIDER_SUFFIX;
        $dataProviderName = ucfirst(str_replace('\\', '', $dataProviderClassName));
        $dataProviderFullClassName = '\\' .$this->dataProviderNamespace . '\\' . $tmpNamespace . '\\' . $dataProviderClassName;
        $pos = strrpos($dataProviderFullClassName, '\\');

        if (false === $pos) {
            $pos = strlen($dataProviderFullClassName);
        }
        $dataProviderNamespace = substr($dataProviderFullClassName, 0, $pos);

        return [$dataProviderFilePath, $dataProviderNamespace, $dataProviderClassName, $dataProviderFullClassName, $dataProviderName];
    }

    /**
     * Generate and return DataProvider name
     *
     * @param string $className
     *
     * @return string
     */
    public function getDataProviderName(string $className): string
    {
        $pos = strpos($className, $this->projectNamespace);

        if (false === $pos) {
            $dataProviderClassName = ucfirst(str_replace('\\', '', $className)) . self::DATA_PROVIDER_SUFFIX;

            return ucfirst(str_replace('\\', '', $dataProviderClassName));
        }
        $tmpNamespace = substr($className, strlen($this->projectNamespace) + 1);
        $tmpNamespaces = explode('\\', $tmpNamespace);
        $tmpClassName = implode('\\', array_slice($tmpNamespaces, count($tmpNamespaces) - 1, 1));
        $dataProviderClassName = $tmpClassName . self::DATA_PROVIDER_SUFFIX;

        return ucfirst(str_replace('\\', '', $dataProviderClassName));
    }


    /**
     * Generate and return DataProvider method name
     *
     * @param ReflectionMethod $method
     * @param string $dataProviderClassName
     *
     * @return string
     */
    public function getDataProviderMethodName(ReflectionMethod $method, string $dataProviderClassName): string
    {
        if ($method->isConstructor()) {
            return self::DATA_PROVIDER_DEFAULT_ARGS;
        }

        return 'getDataFor' . ucfirst($method->getName()) . 'Method';
    }

    /**
     * Generate and save test dataProviders.
     *
     * @return string|null
     *
     * @throws Exception
     */
    public function generate(): ?string
    {
        $dataProviderFiles = [];
        $dataProviders = [];
        $dataProviderFileInitialized = [];

        foreach ($this->dataProviders as $dataProviderName => $dataProvider) {
            $testClassName = $dataProvider['testClassName'];
            $dataProviderFilepath = $dataProvider['dataProviderFilepath'];
            $dataProviderNamespace = $dataProvider['dataProviderNamespace'];
            $dataProviderClassName = $dataProvider['dataProviderClassName'];
            $dataProviderFullClassName = $dataProvider['dataProviderFullClassName'];
            $dataProviderName = $dataProvider['dataProviderName'];

            if (!isset($dataProviders[$dataProviderFilepath])) {
                $dataProviders[$dataProviderFilepath] = [
                    'testClassName' => $testClassName,
                    'className' => $dataProviderClassName,
                    'fullClassName' => $dataProviderFullClassName,
                    'namespace' => $dataProviderNamespace,
                    'methods' => [],
                ];
            }
            $defaultArgs = null;

            if (!empty($dataProvider['dataProviderMethods'][self::DATA_PROVIDER_DEFAULT_ARGS])) {
                $defaultArgs = $dataProvider['dataProviderMethods'][self::DATA_PROVIDER_DEFAULT_ARGS];
                unset($dataProvider['dataProviderMethods'][self::DATA_PROVIDER_DEFAULT_ARGS]);
            }

            foreach ($dataProvider['dataProviderMethods'] as $dataProviderMethodName => $dataProviderArgs) {
                if (null !== $defaultArgs) {
                    if ($dataProviderArgs) {
                        for ($i = 0; $i < $this->countDataSets; ++$i) {
                            $dataProviderArgs[$i][0] = array_merge($defaultArgs[$i][0], $dataProviderArgs[$i][0]);
                            $dataProviderArgs[$i][1] = array_merge($defaultArgs[$i][1], $dataProviderArgs[$i][1]);
                        }
                    } else {
                        $dataProviderArgs = $defaultArgs;
                    }
                }

                if (!isset($dataProviderFileInitialized[$dataProviderFilepath]) && file_exists($dataProviderFilepath)) {
                    $methods = $this->parseMethodsFromSource($dataProviderFilepath);

                    foreach ($methods as $method) {
                        $dataProviderFiles[$dataProviderFilepath][] = $method;
                    }
                    $dataProviderFileInitialized[$dataProviderFilepath] = true;
                }

                if (!isset($dataProviderFiles[$dataProviderFilepath])) {
                    $dataProviderFiles[$dataProviderFilepath] = [];
                }

                if (in_array($dataProviderMethodName, $dataProviderFiles[$dataProviderFilepath], true)) {
                    continue;
                }
                $dataProviderTemplate = new Template(
                    sprintf(
                        '%s%stemplate%sDataProviderMethod.tpl',
                        __DIR__,
                        DIRECTORY_SEPARATOR,
                        DIRECTORY_SEPARATOR
                    )
                );
                $dataProviderTemplate->setVar([
                    'testClassName' => $testClassName,
                    'dataProviderName' => $dataProviderName,
                    'dataProviderClassName' => $dataProviderClassName,
                    'dataProviderMethodName' => $dataProviderMethodName,
                    'dataProviderArgs' => str_replace("\n", "\n\t\t\t", $this->varExport($dataProviderArgs))
                ]);
                $dataProviders[$dataProviderFilepath]['methods'][] = $dataProviderTemplate->render();
            }
        }

        foreach ($dataProviders as $dataProviderFilepath => $dataProvider) {
            $dataProviderCode = implode('', $dataProvider['methods']);

            if (!file_exists($dataProviderFilepath)) {
                $dataProviderCode = $this->generateNewDataProvider($dataProvider['namespace'], $dataProvider['className'], $dataProviderCode);
            }
            $this->saveFile($dataProviderFilepath, $dataProviderCode);
        }

        return null;
    }

    /**
     * Generate new dataProvider.
     *
     * @param string $namespace
     * @param string $className
     * @param string $methods
     *
     * @return string
     *
     * @throws Exception
     */
    private function generateNewDataProvider(string $namespace, string $className, string $methods): string
    {
        $classTemplate = new Template(
            sprintf(
                '%s%stemplate%sDataProvider.tpl',
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
     * Add new DataProvider
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     *
     * @return string
     *
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    public function addDataProviderMethod(ReflectionClass $reflectionClass, ReflectionMethod $reflectionMethod): string
    {
        $className = $reflectionClass->getName();
        [
            $dataProviderFilepath,
            $dataProviderNamespace,
            $dataProviderClassName,
            $dataProviderFullClassName,
            $dataProviderName
        ] = $this->getDataProviderPathAndNamespace($className);
        $dataProviderMethodName = $this->getDataProviderMethodName($reflectionMethod, $dataProviderClassName);

        if (!isset($this->dataProviders[$dataProviderName])) {
            $this->dataProviders[$dataProviderName] = [
                'testClassName' => $className,
                'dataProviderFilepath' => $dataProviderFilepath,
                'dataProviderNamespace' => $dataProviderNamespace,
                'dataProviderClassName' => $dataProviderClassName,
                'dataProviderFullClassName' => $dataProviderFullClassName,
                'dataProviderName' => $dataProviderName,
                'dataProviderMethods' => [],
            ];
        }

        if (!isset($this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName])) {
            $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName] = [];

            if (!$reflectionMethod->isConstructor()) {
                for ($i = 0; $i < $this->countDataSets; ++$i) {
                    $fakeData = $this->getFakeDataForMethodReturnType($reflectionMethod);

                    if (null === $fakeData) {
                        break;
                    }
                    [$methodName, $fakeValue, $fakeTimes] = $fakeData;
                    $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i][0][$methodName] = $fakeValue;
                    $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i][1][$methodName] = $fakeTimes;
                }
            }
        }

        return $dataProviderMethodName;
    }

    /**
     * Generate and return fake data by method return type.
     *
     * @param ReflectionMethod $method
     *
     * @return mixed[]|null
     *
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    private function getFakeDataForMethodReturnType(ReflectionMethod $method): ?array
    {
        $typeIsArray = false;
        $type = $this->getReturnFromAnnotation($method, false, true);

        if (null === $type) {
            $type = $this->getMethodReturnType($method);

            if ($type === DataTypeInterface::TYPE_VOID) {
                return null;
            }

            if (null === $type) {
                $type = DataTypeInterface::TYPE_MIXED;
            }
        }
        $originType = $type;

        if (false !== strpos($type, '[]')) {
            $type = $this->getClassNameFromComplexAnnotationName($type, $method->getDeclaringClass());
            $originType = str_replace('[]', '', $originType);
            $typeIsArray = true;
        }
        $methodName = $method->getName();

        if (in_array($type, self::METHODS_RETURN_SELF, true)) {
            $type = $method->getDeclaringClass()->getName();
        }

        if (class_exists($type) || interface_exists($type)) {
            [, $fakeValue, $fakeTimes] = $this->getMockedDataProvider($type, true, 1);
            $fakeValue['className'] = $type;
            $fakeTimes['className'] = $type;

            if ($typeIsArray) {
                $fakeValue = [$fakeValue];
                $fakeTimes = [$fakeTimes];
            }

            return [$methodName, $fakeValue, $fakeTimes];
        }
        $fakeValue = $this->getFakeValueByName($methodName);

        if (null === $fakeValue) {
            $fakeValue = $this->getFakeValueByType($originType);
        }

        if ($typeIsArray) {
            $fakeValue = [$fakeValue];
        }

        return [$methodName, $fakeValue, 0];
    }

    /**
     * Add to data provider new argument.
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionParameter $parameter
     * @param string $dataProviderMethodName
     *
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    public function addDataProviderArgument(
        ReflectionClass $reflectionClass,
        ReflectionParameter $parameter,
        string $dataProviderMethodName
    ): void {
        $dataProviderName = $this->getDataProviderName($reflectionClass->getName());

        for ($i=0; $i < $this->countDataSets; ++$i) {
            [$paramName, $fakeValue, $fakeTimes] = $this->getFakeData($parameter);

            if (isset($this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i][0][$paramName])) {
                return;
            }

            if (!isset($this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i])) {
                $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i] = [
                  [],
                  []
                ];
            }
            $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i][0][$paramName] = $fakeValue;

            if (null !== $fakeTimes) {
                $this->dataProviders[$dataProviderName]['dataProviderMethods'][$dataProviderMethodName][$i][1][$paramName] = $fakeTimes;
            }
        }
    }

    /**
     * Return, if exists, dataProvider structure.
     *
     * @param string $dataProviderName
     *
     * @return mixed[]|null
     */
    public function getDataProvider(string $dataProviderName): ?array
    {
        return $this->dataProviders[$dataProviderName] ?: null;
    }

    /**
     * Build and return fake data.
     *
     * @param ReflectionParameter $parameter
     *
     * @return mixed[]
     *
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    private function getFakeData(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if (null !== $type) {
            $type = $type->getName();
        }

        if (null === $type) {
            try {
                $type = $this->getReturnFromAnnotation($parameter->getDeclaringFunction());
            } catch (ReturnTypeNotFoundException $e) {
                if ($this->returnTypeNotFoundThrowable) {
                    throw $e;
                }
                $type = DataTypeInterface::TYPE_MIXED;
            }
        }

        if (class_exists($type) || interface_exists($type)) {
            return $this->getMockedDataProvider($type, true, 1);
        }
        $paramName = $parameter->getName();
        $fakeValue = $this->getFakeValueByName($paramName);

        if (null === $fakeValue) {
            $fakeValue = $this->getFakeValueByType($type);
        }

        return [$paramName, $fakeValue, null];
    }

    /**
     * Analyze and generate fake value for param.
     *
     * @param string $paramName
     *
     * @return mixed|null
     */
    private function getFakeValueByName(string $paramName)
    {
        foreach (self::FAKE_PROVIDERS as $type => $fakeProvider) {
            foreach ($fakeProvider as $subFormatter => $formatter) {
                if (is_array($formatter)) {
                    $formatters = $formatter;
                    $formatter = $subFormatter;
                    array_unshift($formatters, $subFormatter);
                } else {
                    $formatters = [$formatter];
                }

                foreach ($formatters as $pattern) {
                    if (false !== stripos($paramName, $pattern)) {
                        $fakeValue = $this->faker->{$formatter};

                        if ($fakeValue instanceof \DateTime) {
                            $fakeValue = $fakeValue->format(DATE_ATOM);
                        }

                        return $fakeValue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Analyze and generate fake value for param.
     *
     * @param string $paramType
     *
     * @return mixed|null
     */
    private function getFakeValueByType(string $paramType)
    {
        if (isset(self::FAKE_BASE_PROVIDERS[$paramType])) {
            return $this->faker->{self::FAKE_BASE_PROVIDERS[$paramType]};
        }

        return null;
    }

    /**
     * Build data provider structure for mock.
     *
     * @param string $className
     * @param bool $mockDeeper
     * @param int $level
     *
     * @return mixed[]
     *
     * @throws ReflectionException
     * @throws ReturnTypeNotFoundException
     */
    private function getMockedDataProvider(string $className, bool $mockDeeper = false, int $level = 0): array
    {
        $class = new ReflectionClass($className);
        $mockArguments = [];
        $mockTimes = ['times' => 0];
        $arguments = [];
        $argumentTimes = [];

        foreach ($class->getMethods() as $method) {
            $methodName = $method->getName();

            if (
                $method->isConstructor() ||
                $method->isDestructor() ||
                $method->isProtected() ||
                $method->isPrivate() ||
                $method->isStatic() ||
                in_array($className, self::$excludeMethods, true) ||
                in_array($methodName, self::$excludeMethods, true) ||
                (isset(self::$excludeMethods[$className]) && in_array($methodName, self::$excludeMethods[$className], true)) ||
                (
                    isset(self::$excludeMethods[$className]['all_except']) &&
                    !in_array($methodName, self::$excludeMethods[$className]['all_except'], true)
                )
            ) {
                continue;
            }
            $fakeParam = $methodName;

            if (isset($arguments[$fakeParam])) {
                $mockArguments[$methodName] = $arguments[$fakeParam];
                $mockTimes[$methodName] = $argumentTimes[$fakeParam];
                continue;
            }

            if (in_array($fakeParam, self::$selfMethods, true)) {
                $fakeParam = $className;

                if (isset($arguments[$fakeParam])) {
                    $mockArguments[$methodName] = $arguments[$fakeParam];
                    $mockTimes[$methodName] = $argumentTimes[$fakeParam];
                    continue;
                }
            }
            $fakeValue = null;
            $fakeTimes = 0;
            $typeIsArray = false;

            if (!$mockDeeper || $level > $this->mockDeepLevel) {
                continue;
            }
            $returnType = $this->getMethodReturnType($method);

            if (
                $returnType !== DataTypeInterface::TYPE_ARRAY_MIXED &&
                false !== strpos($returnType, '[]')
            ) {
                $returnType = str_replace('[]', '', $returnType);
                $typeIsArray = true;
            }

            if (class_exists($returnType) || interface_exists($returnType)) {
                if (isset($arguments[$returnType])) {
                    $mockArguments[$methodName] = $arguments[$returnType];
                    $mockTimes[$methodName] = $argumentTimes[$returnType];
                    continue;
                }

                if ($returnType === $className) {
                    continue;
                }
                [, $fakeValue, $fakeTimes] = $this->getMockedDataProvider($returnType, true, $level + 1);
            } else {
                $returnType = $this->findAndReturnClassNameFromUseStatement($returnType, $method->getDeclaringClass());

                if (null !== $returnType) {
                    if (isset($arguments[$returnType])) {
                        $mockArguments[$methodName] = $arguments[$returnType];
                        $mockTimes[$methodName] = $argumentTimes[$returnType];
                        continue;
                    }
                    [, $fakeValue, $fakeTimes] = $this->getMockedDataProvider($returnType, true, $level + 1);
                }
            }

            if (null === $fakeValue) {
                $fakeValue = $this->getFakeValueByName($fakeParam);
            }

            if (null === $fakeValue) {
                $fakeParam = $this->getMethodReturnType($method);

                if (isset($arguments[$fakeParam])) {
                    $mockArguments[$methodName] = $arguments[$fakeParam];
                    $mockTimes[$methodName] = $argumentTimes[$fakeParam];
                    continue;
                }

                if (class_exists($fakeParam) || interface_exists($fakeParam)) {
                    $fakeValue = $this->getFakeValueByName($fakeParam);
                }

                if (null === $fakeValue) {
                    $fakeValue = $this->getFakeValueByType($fakeParam);
                }
            }
            $arguments[$fakeParam] = $fakeValue;
            $argumentTimes[$fakeParam] = $fakeTimes;

            if ($typeIsArray) {
                $fakeValue = [$fakeValue];

                if (isset($fakeTimes['times'])) {
                    $times = $fakeTimes['times'];
                    unset($fakeTimes['times']);
                    $fakeTimes = ['times' => $times, 'mockTimes' => [$fakeTimes]];
                }
            }
            $mockArguments[$methodName] = $fakeValue;
            $mockTimes[$methodName] = $fakeTimes;
        }
        $pos = strrpos($className, '\\');
        $paramName = $pos ? substr($className, ++$pos) : $className;

        return [$paramName, $mockArguments, $mockTimes];
    }

    /**
     * PHP var_export() with short array syntax (square brackets) indented 2 spaces.
     *
     * @param mixed $expression
     *
     * @return string
     */
    private function varExport($expression): string
    {
        $export = var_export($expression, TRUE);
        $patterns = [
            "/array \(/" => '[',
            "/^([ ]*)\)(,?)$/m" => '$1]$2',
            "/=>[ ]?\n[ ]+\[/" => '=> [',
            "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
        ];
        $export = preg_replace(array_keys($patterns), array_values($patterns), $export);

        return $export;
    }

    /**
     * Set method names, that should return self value.
     *
     * @param string[] $selfMethods
     */
    public static function setSelfMethods(array $selfMethods): void
    {
        self::$selfMethods = $selfMethods;
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
