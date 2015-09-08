<?php

namespace noFlash\SupercacheBundle\Cache;


use noFlash\SupercacheBundle\Exceptions\FilesystemException;
use noFlash\SupercacheBundle\Exceptions\SecurityViolationException;
use noFlash\SupercacheBundle\Filesystem\Finder;

/**
 * Performs cache-related operations on cached entries.
 */
class CacheManager
{

    const UNCACHEABLE_ENVIRONMENT     = -6;
    const UNCACHEABLE_PRIVATE         = -5;
    const UNCACHEABLE_NO_STORE_POLICY = -4;
    const UNCACHEABLE_QUERY           = -3;
    const UNCACHEABLE_CODE            = -2;
    const UNCACHEABLE_METHOD          = -1;

    /**
     * @var array Human readable values for UNCACHEABLE_* codes
     */
    private static $readableUncachableExplanation = array(
        self::UNCACHEABLE_ENVIRONMENT => 'env',
        self::UNCACHEABLE_PRIVATE => 'private',
        self::UNCACHEABLE_NO_STORE_POLICY => 'no-store-policy',
        self::UNCACHEABLE_QUERY => 'query-string',
        self::UNCACHEABLE_CODE => 'code',
        self::UNCACHEABLE_METHOD => 'method'
    );

    /**
     * @var Finder
     */
    private $finder;

    /**
     * @param Finder $finder
     */
    public function __construct(Finder $finder)
    {
        $this->finder = $finder;
    }

    /**
     * Translates UNCACHEABLE_* reason codes into more human readable form.
     *
     * @param integer $code Any code available by UNCACHEABLE_* constants
     *
     * @return string
     * @throws \InvalidArgumentException Unknown code
     */
    public static function getUncachableReasonFromCode($code)
    {
        if (!isset(self::$readableUncachableExplanation[$code])) {
            throw new \InvalidArgumentException('Unknown code of ' . $code . ' specified');
        }

        return self::$readableUncachableExplanation[$code];
    }

    /**
     * Checks if cache for given path exists.
     *
     * @param string $path Cache path, eg. /sandbox
     *
     * @return bool
     */
    public function isExists($path)
    {
        $filePath = $path . DIRECTORY_SEPARATOR . 'index.html';

        return $this->finder->isReadable($filePath);
    }

    /**
     * Provides list of all cached elements.
     *
     * @param null|string $parent Branch to start from. Eg. you can specify /sandbox and you'll get /sandbox,
     *     /sandbox/info but not /test or /test/sandbox.
     *
     * @return array[]
     */
    public function getEntriesList($parent = null)
    {
        $filesList = $this->finder->getFilesList();
        $basePath = $this->finder->getRealCacheDir();
        $basePathLength = mb_strlen($basePath);

        $entries = array();

        foreach ($filesList as $e) {
            $entry = mb_substr($e->getPath(), $basePathLength);

            if ($entry === '') {
                $entry = '/';

            } elseif (DIRECTORY_SEPARATOR !== '/') { //Why elseif and not if below? Simple - it's waste of time to call str_replace when $entry was empty before
                $entry = str_replace($entry, DIRECTORY_SEPARATOR, '/'); //Did I mention I hate Windos?
            }

            if ($parent && strpos($entry, $parent) !== 0) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Deletes single cache entry
     *
     * @param string $path Cache path, eg. /sandbox
     *
     * @return bool
     * @throws \InvalidArgumentException Invalid cache patch specified. Patch which doesn't not exist but it's valid
     *     will not cause this exception.
     */
    public function deleteEntry($path)
    {
        if (DIRECTORY_SEPARATOR !== '/') { //Path will always have / (bcs it's http path), no matter on which OS
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path); //...but filesystem differ
        }

        $filePath = $path . DIRECTORY_SEPARATOR . 'index.html'; //File path

        if (!$this->finder->isReadable($filePath)) {
            return false;
        }

        if (!$this->finder->deleteFile($filePath)) {
            return false;
        }

        $this->finder->deleteDirectory($path); //This one can fail - if you try to delete entry /sandbox and url /sandbox/test is present /sandbox directory cannot be deleted

        return true;
    }

    /**
     * Deletes cache entry and all it's children.
     * So if you request recursive removal of /sandbox paths /sandbox, /sandbox/info and /sandbox/test are going to be
     * removed.
     *
     * @param string $path Cache path, eg. /sandbox
     *
     * @return bool
     * @throws \RuntimeException For details please {@see Finder::deleteDirectoryRecursive()}
     * @throws FilesystemException
     */
    public function deleteEntryRecursive($path)
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        if (!$this->finder->isReadable($path)) {
            return false;
        }

        return $this->finder->deleteDirectoryRecursive($path);
    }

    /**
     * Removes all cache entries.
     * Alias for deleteEntryRecursive('/').
     *
     * @return bool
     * @see deleteEntryRecursive()
     */
    public function clear()
    {
        return $this->deleteEntryRecursive('/');
    }

    public function saveElement(CacheElement $element)
    {

    }

    /**
     * Saves entry content. If entry exists it will be updated, otherwise created.
     *
     * @param string $path Cache path, eg. /sandbox
     * @param string $content Content to save
     *
     * @return bool
     * @throws FilesystemException
     * @throws SecurityViolationException Specified cache path was found to be dangerous (eg. /../../sandbox)
     */
    public function saveEntry($path, $content)
    {
        if (preg_match('/(\\|\/)\.\.(\\|\/)/', $path)) { //Don't look at me that way, please...
            throw new SecurityViolationException("Requested path $path is invalid");
        }

        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        $path .= DIRECTORY_SEPARATOR . 'index.html';

        return $this->finder->writeFile($path, $content);
    }
}