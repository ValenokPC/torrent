<?php

require_once('../vendor/autoload.php');

use Helix\Torrent\Torrent;

$torrent = Torrent::createFromFile('files/logo.png');
$torrent->save($torrent->getFilename());
$torrent = Torrent::open($torrent->getFilename());
var_dump($torrent->data);

$torrent = Torrent::createFromDir('files');
$torrent->save($torrent->getFilename());
$torrent = Torrent::open($torrent->getFilename());
var_dump($torrent->data);
