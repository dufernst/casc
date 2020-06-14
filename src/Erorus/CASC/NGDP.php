<?php

namespace Erorus\CASC;

use Erorus\CASC\DataSource\CASC;
use Erorus\CASC\DataSource\TACT;
use Erorus\CASC\NameLookup\Install;
use Erorus\CASC\NameLookup\Root;
use Erorus\CASC\VersionConfig\HTTP as HTTPVersionConfig;
use Erorus\CASC\VersionConfig\Ribbit;
use Erorus\DB2\Reader;

class NGDP {
    
    /** @var Cache */
    private $cache;

    /** @var Encoding */
    private $encoding;

    /** @var NameLookup[] */
    private $nameSources = [];

    /** @var DataSource[] */
    private $dataSources = [];

    private $ready = false;
    
    public function __construct($cachePath, $wowPath, $program = 'wow', $region = 'us', $locale = 'enUS') {
        if (PHP_INT_MAX < 8589934590) {
            throw new \Exception("Requires 64-bit PHP");
        }
        if (!in_array('blte', stream_get_wrappers())) {
            stream_wrapper_register('blte', BLTE::class);
        }

        HTTP::$writeProgressToStream = STDOUT;

        $this->cache = new Cache($cachePath);

        $versionConfig = new HTTPVersionConfig($this->cache, $program, $region);
        $hosts = $versionConfig->getHosts();

        $ribbit = new Ribbit($this->cache, $program, $region);
        if (count($ribbit->getHosts()) >= count($hosts)) {
            $versionConfig = $ribbit;
            $hosts = $ribbit->getHosts();
        }

        if (!count($hosts)) {
            throw new \Exception(sprintf("No hosts returned from NGDP for program '%s' region '%s'\n", $program, $region));
        }

        echo sprintf("%s %s version %s\n", $versionConfig->getRegion(), $versionConfig->getProgram(), $versionConfig->getVersion());

        $buildConfig = new Config($this->cache, $hosts, $versionConfig->getCDNPath(), $versionConfig->getBuildConfig());
        if (!isset($buildConfig->encoding[1])) {
            throw new \Exception("Could not find encoding value in build config\n");
        }
        if (!isset($buildConfig->root[0])) {
            throw new \Exception("Could not find root value in build config\n");
        }
        if (!isset($buildConfig->install[0])) {
            throw new \Exception("Could not find install value in build config\n");
        }

        echo "Loading encoding..";
        $this->encoding = new Encoding($this->cache, $hosts, $versionConfig->getCDNPath(), $buildConfig->encoding[1]);
        echo "\n";

        echo "Loading install..";
        $installContentMap = $this->encoding->getContentMap(hex2bin($buildConfig->install[0]));
        if (!$installContentMap) {
            throw new \Exception("Could not find install header in Encoding\n");
        }
        $this->nameSources['Install'] = new Install($this->cache, $hosts, $versionConfig->getCDNPath(), bin2hex($installContentMap->getEncodedHashes()[0]));
        echo "\n";

        echo "Loading root..";
        $rootContentMap = $this->encoding->getContentMap(hex2bin($buildConfig->root[0]));
        if (!$rootContentMap) {
            throw new \Exception("Could not find root header in Encoding\n");
        }
        $this->nameSources['Root'] = new Root($this->cache, $hosts, $versionConfig->getCDNPath(), bin2hex($rootContentMap->getEncodedHashes()[0]), $locale);
        echo "\n";

        $cdnConfig = new Config($this->cache, $hosts, $versionConfig->getCDNPath(), $versionConfig->getCDNConfig());

        if ($wowPath) {
            echo "Loading local indexes..";
            $this->dataSources['Local'] = new CASC($wowPath);
            echo "\n";
        }

        echo "Loading remote indexes..";
        $this->dataSources['Remote'] = new TACT($this->cache, $hosts, $versionConfig->getCDNPath(), $cdnConfig->archives, $wowPath ? $wowPath : null);
        echo "\n";

        $this->ready = true;

        BLTE::loadEncryptionKeys(); // init static keys
        try {
            $this->fetchTactKey();
        } catch (\Exception $e) {
            echo " Failed: ", $e->getMessage(), "\n";
        }
    }

    public function getContentHash($file, $locale = null) {
        $contentHash = false;
        foreach ($this->nameSources as $nameSourceName => $nameSource) {
            if ($contentHash = $nameSource->GetContentHash($file, $locale)) {
                break;
            }
        }
        return $contentHash;
    }

    /**
     * Extracts a file.
     *
     * @param string $sourceId Ideally a numeric file ID, also supports some filenames (e.g. from Install)
     * @param string $destPath The filesystem path where to save the file. If it exists and matches our content hash,
     *                         we can skip downloading/extracting it.
     * @param string|null $locale The locale to use. Null to use the default locale.
     *
     * @return null|string Null on any failure. A string naming the data source on success. When
     *                     DataSource::ignoreErrors is true, this will still return null even if errors were ignored and
     *                     the file was updated.
     */
    public function fetchFile(string $sourceId, string $destPath, ?string $locale = null): ?string {
        if (!$this->ready) {
            return null;
        }

        $sourceId = strtr($sourceId, ['/' => '\\']);
        $contentHash = $this->getContentHash($sourceId, $locale);
        if ($contentHash === false) {
            return null;
        }
        if (file_exists($destPath) && md5_file($destPath, true) === $contentHash) {
            return 'Already Exists';
        }

        return $this->fetchContentHash($contentHash, $destPath);
    }

    private function fetchTactKey() {
        echo "Loading encryption keys..";

        $files = [
            'tactKey' => 1302850,
            'tactKeyLookup' => 1302851,
        ];

        /** @var Reader[] $db2s */
        $db2s = [];

        foreach ($files as $id => $path) {
            $contentHash = $this->nameSources['Root']->GetContentHash($path, null);
            if (!$contentHash) {
                echo " Failed to find $id file\n";
                return false;
            }

            $cachePath = 'keys/' . bin2hex($contentHash);
            $fullCachePath = $this->cache->getFullPath($cachePath);
            if (!$this->cache->fileExists($cachePath)) {
                $success = $this->fetchContentHash($contentHash, $fullCachePath);
                if (!$success) {
                    $this->cache->delete($cachePath);

                    echo " Failed to fetch $id file\n";
                    return false;
                }
            }

            try {
                $db2s[$id] = new Reader($fullCachePath);
            } catch (\Exception $e) {
                $this->cache->delete($cachePath);

                echo " Failed to open $id file: " . $e->getMessage() . "\n";
                return false;
            }
        }

        $keys = [];
        foreach ($db2s['tactKeyLookup']->generateRecords() as $id => $lookupRec) {
            $keyRec = $db2s['tactKey']->getRecord($id);
            if ($keyRec) {
                $keys[static::byteArrayToString($lookupRec[0])] = static::byteArrayToString($keyRec[0]);
            }
        }

        BLTE::loadEncryptionKeys($keys);

        echo sprintf(" OK (+%d)\n", count($keys));
    }

    private static function byteArrayToString($bytes) {
        $str = '';
        for ($x = 0; $x < count($bytes); $x++) {
            $str .= chr($bytes[$x]);
        }
        return $str;
    }

    /**
     * Saves the given content hash to the given filesystem location.
     *
     * @param string $contentHash The content hash, in binary bytes.
     * @param string $destPath Where to save the file.
     *
     * @return string|null Returns the name of the data source which provided it when successful, null on failure.
     */
    private function fetchContentHash(string $contentHash, string $destPath): ?string {
        $contentMap = $this->encoding->getContentMap($contentHash);
        if (!$contentMap) {
            return null;
        }

        foreach ($this->dataSources as $dataSourceName => $dataSource) {
            foreach ($contentMap->getEncodedHashes() as $hash) {
                try {
                    if ($location = $dataSource->findHashInIndexes($hash)) {
                        if ($dataSource->extractFile($location, $destPath, $contentHash)) {
                            return $dataSourceName;
                        }
                    }
                } catch (\Exception $e) {
                    echo sprintf(" - %s ", trim($e->getMessage()));
                }
            }
        }

        return null;
    }
}
