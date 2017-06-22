<?php

namespace Keboola\GenericExtractor;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Subscriber\Cache\CacheStorage;
use Keboola\GenericExtractor\Config\Configuration;
use Keboola\Juicer\Config\Config;
use Keboola\Juicer\Parser\Json;
use Keboola\Temp\Temp;
use Keboola\Juicer\Exception\UserException;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Executor
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Executor constructor.
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Configuration $config
     * @return CacheStorage|null
     */
    private function initCacheStorage(Configuration $config)
    {
        $cacheConfig = $config->getCache();

        if (!$cacheConfig) {
            return null;
        }

        return new CacheStorage(
            new FilesystemCache($config->getDataDir() . '/cache'),
            null,
            !empty($cacheConfig['ttl']) ? (int) $cacheConfig['ttl'] : Configuration::CACHE_TTL
        );
    }

    /**
     * @param bool $debug
     */
    private function setLogLevel($debug)
    {
        /** @var AbstractHandler $handler */
        foreach ($this->logger->getHandlers() as $handler) {
            if ($handler instanceof AbstractHandler) {
                if ($debug) {
                    $handler->setLevel($this->logger::DEBUG);
                } else {
                    $handler->setLevel($this->logger::INFO);
                }
            }
        }
    }

    public function run()
    {
        $temp = new Temp();

        $arguments = getopt("d::", ["data::"]);
        if (!isset($arguments["data"])) {
            throw new UserException('Data folder not set.');
        }

        $configuration = new Configuration($arguments['data'], $temp, $this->logger);

        $configs = $configuration->getMultipleConfigs();

        $metadata = $configuration->getMetadata() ?: [];
        $metadata['time']['previousStart'] =
            empty($metadata['time']['previousStart']) ? 0 : $metadata['time']['previousStart'];
        $metadata['time']['currentStart'] = time();

        $authorization = $configuration->getAuthorization();
        $cacheStorage = $this->initCacheStorage($configuration);

        $results = [];
        /** @var Config[] $configs */
        foreach ($configs as $config) {
            $this->setLogLevel($config->getAttribute('debug'));
            $api = $configuration->getApi($config, $authorization);

            if (!empty($config->getAttribute('outputBucket'))) {
                $outputBucket = $config->getAttribute('outputBucket');
            } elseif (!empty($config->getConfigName())) {
                $outputBucket = 'ex-api-' . $api->getName() . "-" . $config->getConfigName();
            } else {
                $outputBucket = "__kbc_default";
            }

            $extractor = new GenericExtractor($temp, $this->logger);

            if ($cacheStorage) {
                $extractor->enableCache($cacheStorage);
            }

            if (!empty($results[$outputBucket])) {
                $extractor->setParser($results[$outputBucket]['parser']);
            }
            $extractor->setApi($api);
            $extractor->setMetadata($metadata);

            $extractor->run($config);

            $metadata = $extractor->getMetadata();

            $results[$outputBucket]['parser'] = $extractor->getParser();
            $results[$outputBucket]['incremental'] = $config->getAttribute('incrementalOutput');
        }

        foreach ($results as $bucket => $result) {
            $this->logger->debug("Processing results for {$bucket}.");
            /** @var Json $parser */
            $parser = $result['parser'];
            $configuration->storeResults(
                $parser->getResults(),
                $bucket == "__kbc_default" ? null : $bucket,
                true,
                $result['incremental']
            );

            // move files and flatten file structure
            $folderFinder = new Finder();
            $fs = new Filesystem();
            $folders = $folderFinder->directories()->in($arguments['data'] . "/out/tables")->depth(0);
            foreach ($folders as $folder) {
                /** @var SplFileInfo $folder */
                $filesFinder = new Finder();
                $files = $filesFinder->files()->in($folder->getPathname())->depth(0);
                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    $destination =
                        $arguments['data'] . "/out/tables/" . basename($folder->getPathname()) .
                        "." . basename($file->getPathname());
                    // maybe move will be better?
                    $fs->rename($file->getPathname(), $destination);
                }
            }
            $fs->remove($folders);
        }

        $metadata['time']['previousStart'] = $metadata['time']['currentStart'];
        unset($metadata['time']['currentStart']);
        $configuration->saveConfigMetadata($metadata);
    }
}
