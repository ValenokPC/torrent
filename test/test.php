<?php

require_once('../vendor/autoload.php');

use Helix\Torrent\Torrent;

$torrents = [
    Torrent::createFromFile('files/logo.png'),
    Torrent::createFromDir('files')
];

$announce = [
    "a",
    [["a","b"],["c"]]
];

$switch = 0;
foreach ($torrents as $torrent) {
    $switch = ++$switch % 2;
    $torrent->data['nodes'] = [['foo',1],['bar',2],['baz',3]];
    $torrent->removeNode('foo')
            ->removeNode('bar',2)
            ->removeNode('baz',4) // baz stays
            ->setPrivate($switch)
            ->setAnnounce($announce[$switch])
            ->save();
    $torrent = Torrent::open($torrent->getFilename());
    echo "Torrent: ".$torrent->getName()."\n";
    var_dump($torrent->data);
    echo "isPrivate() ";
    var_dump($torrent->isPrivate());
    echo "getAnnounce() ";
    var_dump($torrent->getAnnounce());
    echo "\n";
}
