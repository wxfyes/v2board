<?php

namespace App\Utils;

use RuntimeException;
use Throwable;

class IPv6WrySearcher
{
    protected static $instance;
    protected static $db_path;
    protected $handle;
    protected $osLen;
    protected $ipLen;
    protected $dbAddr;
    protected $size;
    private $dLen;

    final private function __construct()
    {
        $filename = self::setDbPath();
        $this->handle = @fopen($filename, 'rb');
        if (!$this->handle) {
            throw new RuntimeException('Failed open ipv6wry.db file!');
        }

        if ($this->read(0, 4) !== 'IPDB') {
            throw new RuntimeException('The type of ip database file is not "IPDB".');
        }

        $this->osLen = $this->readInt(6, 1);
        $this->ipLen = $this->readInt(7, 1);
        $this->dLen = $this->osLen + $this->ipLen;
        $this->dbAddr = $this->readInt(0x10, 8);
        $this->size = $this->readInt(8, 8);
    }

    public static function setDbPath(string $db_path = null): string
    {
        if (self::$instance !== null && $db_path !== null && $db_path !== self::$db_path) {
            throw new RuntimeException('Database path cannot change after instance.');
        }

        if ($db_path !== null) {
            self::$db_path = $db_path;
        } elseif (self::$db_path === null) {
            $candidatePaths = [
                function_exists('app_path') ? app_path('Utils/ipv6wry.db') : null,
                function_exists('base_path') ? base_path('app/Utils/ipv6wry.db') : null,
                __DIR__ . '/ipv6wry.db',
                dirname(__DIR__) . '/Utils/ipv6wry.db',
            ];

            foreach ($candidatePaths as $p) {
                if ($p && file_exists($p)) {
                    self::$db_path = $p;
                    break;
                }
            }

            if (!self::$db_path) {
                self::$db_path = __DIR__ . '/ipv6wry.db';
            }
        }

        if (!file_exists(self::$db_path) || !is_readable(self::$db_path)) {
            throw new RuntimeException('Failed open ip database file: ' . self::$db_path);
        }

        return self::$db_path;
    }

    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function read(int $offset = 0, int $size = 1): string
    {
        fseek($this->handle, $offset, SEEK_SET);
        return fread($this->handle, $size);
    }

    private function readInt(int $offset = 0, int $size = 8): int
    {
        $s = $this->read($offset, $size);
        if ($size == 3) {
            $s .= "\x00";
            $size = 4;
        }

        $format = [8 => 'P', 4 => 'V', 2 => 'v', 1 => 'C'][$size];
        return unpack($format, $s)[1];
    }

    private function readRawText(int $start): string
    {
        $bs = '';
        while (0 != ($p = $this->readInt($start, 1))) {
            $bs .= chr($p);
            $start += 1;
        }
        return $bs;
    }

    private function parseIpv6(string $ip): int
    {
        $ip_bin = @inet_pton($ip);
        if ($ip_bin === false || strlen($ip_bin) !== 16) {
            return -1;
        }
        return unpack("J", $ip_bin)[1];
    }

    private function checkIndex(int $index): void
    {
        if ($index < 0 || $index >= $this->size) {
            throw new RuntimeException('Index out of range');
        }
    }

    private function getData(int $index): int
    {
        $this->checkIndex($index);
        $addr = $this->dbAddr + $index * $this->dLen;
        return $this->readInt($addr, $this->ipLen);
    }

    private function readLoc(int $start, bool $isTwoPart = false): string
    {
        $jType = $this->readInt($start, 1);
        if ($jType == 1 || $jType == 2) {
            $start += 1;
            $offAddr = $this->readInt($start, $this->osLen);
            if ($offAddr == 0) {
                return 'Unknown Address';
            }

            $loc = $this->readLoc($offAddr, $jType == 1);
            $nAddr = $start + $this->osLen;
        } else {
            $loc = $this->readRawText($start);
            $nAddr = $start + strlen($loc) + 1;
        }

        if ($isTwoPart && $jType != 1) {
            $part2 = $this->readLoc($nAddr);
            if ($loc && $part2) {
                $loc .= ' ' . $part2;
            }
        }
        return $loc;
    }

    private function binarySearch(int $key, int $lo = 0, int $hi = null): int
    {
        $hi = $hi ?? $this->size - 1;

        while ($lo <= $hi) {
            if ($hi - $lo <= 1) {
                if ($this->getData($lo) > $key) {
                    return -1;
                } elseif ($this->getData($hi) <= $key) {
                    return $hi;
                } else {
                    return $lo;
                }
            }
            $mid = (int)floor(($hi + $lo) / 2);
            $data = $this->getData($mid);
            if ($data > $key) {
                $hi = $mid - 1;
            } elseif ($data < $key) {
                $lo = $mid;
            } else {
                return $mid;
            }
        }
        return -1;
    }

    private function getLoc(int $index): string
    {
        $this->checkIndex($index);
        $addr = $this->dbAddr + $index * $this->dLen;
        $lAddr = $this->readInt($addr + $this->ipLen, $this->osLen);
        return $this->readLoc($lAddr, true);
    }

    public static function search(string $ipv6): ?string
    {
        try {
            $instance = self::getInstance();
            $v6_int = $instance->parseIpv6($ipv6);
            if ($v6_int < 0) {
                return null;
            }

            $index = $instance->binarySearch($v6_int);
            if ($index < 0) {
                return null;
            }

            if ($index > $instance->size - 2) {
                $index = $instance->size - 2;
            }

            $area = $instance->getLoc($index);
            return $area ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
