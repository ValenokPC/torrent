<?php

require_once('../vendor/autoload.php');

use Helix\Torrent\Torrent;

$torrent = Torrent::createFromFile('files/logo.png');
$torrent->save($torrent->data['name'].'.torrent');
