<?php

declare(strict_types=1);

namespace MicroModule\UnitTestGenerator\Service;

use MicroModule\UnitTestGenerator\Generator\Exception\NotDirectoryException;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Class TestProject.
 *
 * @SuppressWarnings(PHPMD)
 */
class TestProject
{
    /**
     * Source path.
     *
     * @var string
     */
    protected $sourcePath;

    /**
     * Folders, that should be exclude from analyze.
     *
     * @var mixed[]
     */
    private $excludeFolders;

    /**
     * TestProject constructor.
     *
     * @param string  $sourcePath
     * @param mixed[] $excludeFolders
     */
    public function __construct(string $sourcePath, array $excludeFolders = [])
    {
        $this->sourcePath = $sourcePath;
        $this->excludeFolders = $excludeFolders;
    }

    /**
     * Generate tests.
     *
     * @throws Exception
     */
    public function generate(): void
    {
        if (!file_exists($this->sourcePath)) {
            throw new Exception("Source file '{$this->sourcePath}' doesn't exists!");
        }
        $modules = $this->scanProjectSourceFolder($this->sourcePath, true);

        foreach ($modules as $folder) {
            foreach ($folder as $file) {
                $testClass = new TestClass();
                $testClass->generate($file);
            }
        }
    }

    /**
     * Scan all subfolders in the source project directory and generate test for all classes.
     *
     * @param string $projectSourcePath
     * @param bool   $deepLevel
     *
     * @return mixed[]
     *
     * @throws Exception
     */
    protected function scanProjectSourceFolder(string $projectSourcePath, bool $deepLevel = false): array
    {
        $folders = scandir($projectSourcePath);

        if (false === $folders) {
            throw new NotDirectoryException('Project source path is not a directory.');
        }
        $srcFolders = [];

        foreach ($folders as $folder) {
            if ('.' === $folder || '..' === $folder || in_array($folder, $this->excludeFolders, true)) {
                continue;
            }
            $sourcePath = $projectSourcePath . DIRECTORY_SEPARATOR . $folder;

            if (!is_dir($sourcePath)) {
                continue;
            }

            if ($deepLevel) {
                $subFolders = $this->scanProjectSourceFolder($sourcePath);
                $srcFolders[$folder] = array_merge(...array_values($subFolders));
            } else {
                $srcFolders[$folder] = $this->scanSources($sourcePath);
            }
        }

        return $srcFolders;
    }

    /**
     * Scan all subfolders in the source project directory and generate test for all classes.
     *
     * @param string $sourcePath
     *
     * @return mixed[]
     *
     * @throws Exception
     */
    protected function scanSources(string $sourcePath): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS));
        $files = [];

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ('php' !== $item->getExtension()) {
                continue;
            }

            $files[] = $item->getRealPath();
        }

        return $files;
    }
}
