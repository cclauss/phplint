<?php

/*
 * This file is part of the overtrue/phplint.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\PHPLint;

use InvalidArgumentException;
use Overtrue\PHPLint\Process\Lint;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Class Linter.
 */
class Linter
{
    /**
     * @var callable
     */
    private $processCallback;

    /**
     * @var array
     */
    private $files = [];

    /**
     * @var array
     */
    private $cache = [];

    /**
     * @var string|array
     */
    private $path;

    /**
     * @var array
     */
    private $excludes;

    /**
     * @var array
     */
    private $extensions;

    /**
     * @var int
     */
    private $processLimit = 5;

    /**
     * Constructor.
     *
     * @param string|array $path
     * @param array        $excludes
     * @param array        $extensions
     */
    public function __construct($path, array $excludes = [], array $extensions = ['php'])
    {
        $this->path = $path;
        $this->excludes = $excludes;
        $this->extensions = $extensions;
    }

    /**
     * Check the files.
     *
     * @param array $files
     * @param bool  $cache
     *
     * @return array
     */
    public function lint($files = [], $cache = true)
    {
        if (empty($files)) {
            $files = $this->getFiles();
        }

        $processCallback = is_callable($this->processCallback) ? $this->processCallback : function () {
        };

        $errors = [];
        $running = [];
        $newCache = [];
        $phpbin = PHP_SAPI == 'cli' ? PHP_BINARY : PHP_BINDIR.'/php';

        while (!empty($files) || !empty($running)) {
            for ($i = count($running); !empty($files) && $i < $this->processLimit; ++$i) {
                $file = array_shift($files);
                $filename = $file->getRealpath();

                if (!isset($this->cache[$filename]) || $this->cache[$filename] !== md5_file($filename)) {
                    $running[$filename] = [
                        'process' => new Lint($phpbin.' -d error_reporting=E_ALL -d display_errors=On -l '.escapeshellarg($filename)),
                        'file' => $file,
                    ];
                    $running[$filename]['process']->start();
                } else {
                    $newCache[$filename] = $this->cache[$filename];
                }
            }

            foreach ($running as $filename => $item) {
                if ($item['process']->isRunning()) {
                    continue;
                }

                unset($running[$filename]);
                if ($item['process']->hasSyntaxError()) {
                    $processCallback('error', $item['file']);
                    $errors[$filename] = array_merge(['file' => $filename], $item['process']->getSyntaxError());
                } else {
                    $newCache[$filename] = md5_file($filename);
                    $processCallback('ok', $item['file']);
                }
            }

            $cache && Cache::put($newCache);
        }

        return $errors;
    }

    /**
     * Cache setter.
     *
     * @param array $cache
     */
    public function setCache($cache = [])
    {
        if (is_array($cache)) {
            $this->cache = $cache;
        } else {
            $this->cache = [];
        }
    }

    /**
     * Fetch files.
     *
     * @return array
     */
    public function getFiles()
    {
        if (empty($this->files)) {
            foreach ((array) $this->path as $path) {
                if (is_dir($path)) {
                    $this->files = array_merge($this->files, $this->getFilesFromDir($path));
                } elseif (is_file($path)) {
                    $file = new SplFileInfo($path);
                    $this->files[$file->getRealPath()] = $file;
                }
            }
        }

        return $this->files;
    }

    /**
     * Get files from directory.
     *
     * @param string $dir
     *
     * @return array
     */
    protected function getFilesFromDir($dir)
    {
        $finder = new Finder();
        $finder->files()->ignoreUnreadableDirs()->in(realpath($dir));

        foreach ($this->excludes as $exclude) {
            $finder->notPath($exclude);
        }

        foreach ($this->extensions as $extension) {
            $finder->name('*.'.$extension);
        }

        return iterator_to_array($finder);
    }

    /**
     * Set Files.
     *
     * @param array $files
     *
     * @return \Overtrue\PHPLint\Linter
     */
    public function setFiles(array $files)
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                $file = new SplFileInfo($file);
            }

            if (!($file instanceof SplFileInfo)) {
                throw new InvalidArgumentException("File $file not exists.");
            }

            $file = new SplFileInfo($file);
            $this->files[$file->getRealPath()] = $file;
        }

        return $this;
    }

    /**
     * Set process callback.
     *
     * @param callable $processCallback
     *
     * @return Linter
     */
    public function setProcessCallback($processCallback)
    {
        $this->processCallback = $processCallback;

        return $this;
    }

    /**
     * Set process limit.
     *
     * @param int $processLimit
     *
     * @return \Overtrue\PHPLint\Linter
     */
    public function setProcessLimit($processLimit)
    {
        $this->processLimit = $processLimit;

        return $this;
    }
}
