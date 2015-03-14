<?php

namespace Helix\Torrent;

use RecursiveDirectoryIterator as Dir;

/**
* Torrent file as an object.
*/
class Torrent {

    /**
    * @var array Torrent structure
    */
    public $data = [];

    /**
    * Creates an instance representing a torrent containing a directory.
    * @param string $path
    * @param int $pieceLength
    * @return self
    * @uses self::$data
    * @uses self::hashPieces
    **/
    public static function createFromDirectory ( $path , $pieceLength = 262144 ) {
        $torrent = new static;
        $torrent->data = [
            'creation date' => time(),
            'info' => [
                'files' => [],
                'name' => basename($path),
                'piece length' => $pieceLength,
                'pieces' => ''
            ]
        ];
        $dirname = dirname($path);
        $dir = new Dir($path,Dir::FOLLOW_SYMLINKS|Dir::SKIP_DOTS|Dir::UNIX_PATHS|Dir::CURRENT_AS_PATHNAME);
        $iter = new \RecursiveIteratorIterator($dir);
        foreach ($iter as $path) {
            $torrent->data['info']['files'][] = [
                'length' => filesize($path),
                'path' => explode('/',ltrim(substr($path,strlen($dirname)),'/'))
            ];
            $torrent->data['info']['pieces'] .= static::hashPieces($path,$pieceLength);
        }
        return $torrent;
    }

    /**
    * Creates an instance representing a torrent containing a single file.
    * @param string $path
    * @param int $pieceLength Defaults to 256KiB
    * @return self
    * @uses self::$data
    * @uses self::hashPieces
    **/
    public static function createFromFile ( $path , $pieceLength = 262144 ) {
        $torrent = new static;
        $torrent->data = [
            'creation date' => time(),
            'info' => [
                'name' => basename($path),
                'length' => filesize($path),
                'piece length' => $pieceLength,
                'pieces' => static::hashPieces($path,$pieceLength)
            ]
        ];
        return $torrent;
    }

    /**
    * Generates SHA-1 pieces for a file.
    * @param string $path The file to hash
    * @param int $pieceLength Defaults to 256KiB
    * @return string All hashes concatenated together
    */
    public static function hashPieces ( $path , $pieceLength = 262144 ) {
        if (!$handle = fopen($path,'rb')) {
            throw new Error("Unable to open {$path}");
        }
        $pieces = '';
        while (!feof($handle)) {
            if (false === $piece = fread($handle,$pieceLength)) {
                throw new Error("Unable to read {$path}");
            }
            $pieces .= sha1($piece,true);
        }
        fclose($handle);
        return $pieces;
    }

    /**
    * Parses a torrent file into a new instance.
    * @param string $path
    * @return self
    * @uses self::$data
    */
    public static function open ( $path ) {
        if (!$handle = fopen($path,'rb')) {
            throw new Error("Unable to open {$path}");
        }
        flock($handle,LOCK_SH);
        $read = function($length) use ($handle,$path) {
            if (false === $data = fread($handle,$length)) {
                flock($handle,LOCK_UN);
                throw new Error("Unable to read {$path}");
            }
            elseif (strlen($data) !== $length) {
                flock($handle,LOCK_UN);
                throw new Error("Unexpected end of file: {$path}");
            }
            return $data;
        };
        $bdecode = function() use ($read,&$bdecode) {
            $type = $read(1);
            if ($type === 'e') return false; // EOF, list/dict trailing e
            elseif ($type === 'l') { // list
                $list = [];
                while (false !== $value = $bdecode()) $list[] = $value;
                return $list;
            }
            elseif ($type === 'd') { // dict
                $dict = [];
                while (false !== $key = $bdecode()) $dict[$key] = $bdecode();
                return $dict;
            }
            elseif ($type === 'i') { // int
                $int = '';
                while ('e' !== $char = $read(1)) $int .= $char;
                return (int)$int;
            }
            else { // string
                $length = '';
                while (':' !== $char = $read(1)) $length .= $char;
                return $read((int)$length));
            }
        };
        $torrent = new static;
        $torrent->data = $bdecode();
        flock($handle,LOCK_UN);
        fclose($handle);
        return $torrent;
    }

    /**
    * Saves the instance as a torrent file.
    * @param string $path File path to save to.
    * @throws Error
    * @return self
    * @uses self::$data
    */
    public function save ( $path ) {
        if (!$handle = fopen($path,'wb')) throw new Error("Unable to write to {$path}");
        flock($handle,LOCK_EX);
        $write = function($string) use ($handle,$path) {
            for ($total = 0; $total < strlen($string); $total += $count) {
                if (!$count = fwrite($handle,substr($string,$total))) {
                    flock($handle,LOCK_UN);
                    throw new Error("Unable to write to {$path}");
                }
            }
        };
        $bencode = function($var,$key=null) use (&$bencode,$write) {
            if (is_string($key)) $write(strlen($key).':'.$key); // in-dict array_walk
            if (is_array($var)) { // list/dict
                if (is_int(key($var))) $write('l'); // list
                else {
                    ksort($var);
                    $write('d'); // dict
                }
                array_walk($var,$bencode);
                $write('e');
            }
            elseif (is_int($var)) { // int
                $write("i{$var}e");
            }
            else { // string
                $write(strlen($var).':');
                $write($var); // write alone, no concat, potentially large
            }
        };
        $bencode($this->data);
        if (!fflush($handle)) {
            flock($handle,LOCK_UN);
            throw new Error("Unable to commit data to {$path}");
        }
        flock($handle,LOCK_UN);
        fclose($handle);
        return $this;
    }

    public function isPrivate ( ) {
        return isset($this->data['private']) and $this->data['private'] === 1;
    }

}
