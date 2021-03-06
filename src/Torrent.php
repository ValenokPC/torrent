<?php

namespace Helix\Torrent;

use RecursiveDirectoryIterator as Dir;

/**
* Represents a torrent file as an object.
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
    * @uses self::hashPieces()
    **/
    public static function createFromDir ( $path , $pieceLength = 262144 ) {
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
        if ($dirname === '.') $dirname = '';
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
    * @uses self::hashPieces()
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
    * @throws Error File error
    * @return string All hashes concatenated together
    */
    public static function hashPieces ( $path , $pieceLength = 262144 ) {
        if (!$handle = fopen($path,'r') or !flock($handle,LOCK_SH)) {
            throw new Error("Unable to open {$path}");
        }
        $pieces = '';
        while (!feof($handle)) {
            $piece = fread($handle,$pieceLength);
            if ($piece === false) throw new Error("Unable to read {$path}");
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
        $offset = 0;
        if (!$handle = fopen($path,'r') or !flock($handle,LOCK_SH)) {
            throw new Error("Unable to open {$path}");
        }
        $read = function($length) use ($handle,$path,&$offset) {
            if (false === $data = fread($handle,$length)) throw new Error("Unable to read {$path}");
            elseif (strlen($data) !== $length) throw new Error("Unexpected end of file: {$path}");
            $offset += $length;
            return $data;
        };
        $bdecode = function() use ($read,&$bdecode,&$offset,$path) {
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
            elseif (is_numeric($type)) { // string
                $length = $type;
                while (':' !== $char = $read(1)) $length .= $char;
                return $read((int)$length);
            }
            else {
                throw new Error("Malformed torrent file {$path} at offset {$offset}");
            }
        };
        $torrent = new static;
        $torrent->data = $bdecode();
        fclose($handle);
        return $torrent;
    }

    /**
    * @return null|array[] 2D announce list, even if a single URL is set, or `null` if no announce is present.
    * @uses self::$data
    */
    public function getAnnounce ( ) {
        if (isset($this->data['announce-list'])) return $this->data['announce-list'];
        elseif (isset($this->data['announce'])) return [[$this->data['announce']]];
    }

    /**
    * @return string Suggested name + `.torrent`
    * @uses self::getName()
    */
    public function getFilename ( ) {
        return $this->getName().".torrent";
    }

    /**
    * @return string Suggested destination name. Sets the name to `unnamed` if not set.
    * @uses self::$data
    * @uses self::setName()
    */
    public function getName ( ) {
        if (!isset($this->data['info']['name'])) $this->setName("unnamed");
        return $this->data['info']['name'];
    }

    /**
    * @return bool
    * @uses self::$data
    */
    public function isPrivate ( ) {
        return isset($this->data['private']) and $this->data['private'] === 1;
    }

    /**
    * Saves the instance as a torrent file.
    * @param string $path File path to save to. Defaults to current directory with {@link getFilename()}.
    * @throws Error File error
    * @return self
    * @uses self::$data
    */
    public function save ( $path = null ) {
        if (!isset($path)) $path = $this->getFilename();
        if (!$handle = fopen($path,'c') or !flock($handle,LOCK_EX) or !ftruncate($handle,0)) {
            throw new Error("Unable to open {$path}");
        }
        $write = function($string) use ($handle,$path) {
            for ($total = 0; $total < strlen($string); $total += $count) {
                if (!$count = fwrite($handle,substr($string,$total))) {
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
        if (!fflush($handle)) throw new Error("Unable to commit data to {$path}");
        fclose($handle);
        return $this;
    }


    /**
    * Sets announce URL/s
    * @param null|string|array[] $announce Single announce URL, 2D array of URLs, or `null` to disable.
    * @return self
    * @uses self::$data
    */
    public function setAnnounce ( $announce ) {
        if (is_string($announce)) {
            $this->data['announce'] = $announce;
        }
        elseif (is_array($announce)) $this->data['announce-list'] = $announce;
        elseif (!isset($announce)) unset($this->data['announce'],$this->data['announce-list']);
        return $this;
    }

    /**
    * @param string $name Suggested destination name.
    * @return self
    * @uses self::$data
    */
    public function setName ( $name ) {
        $this->data['info']['name'] = $name;
        return $this;
    }

    /**
    * Removes DHT nodes.
    * @param string $host
    * @param int $port Optional
    * @return self
    * @uses self::$data
    */
    public function removeNode ( $host , $port = null ) {
        if (!isset($this->data['nodes'])) return;
        foreach (array_keys($this->data['nodes']) as $key) {
            if ($this->data['nodes'][$key][0] === $host) {
                if (!isset($port) or $this->data['nodes'][$key][1] === $port) {
                    unset($this->data['nodes'][$key]);
                }
            }
        }
        return $this;
    }

    /**
    * @param bool $private
    * @return self
    * @uses self::$data
    */
    public function setPrivate ( $private ) {
        if ($private) $this->data['private'] = 1;
        else unset($this->data['private']);
        return $this;
    }

}
