<?php

/**
 * Copyright (C) 2014 Nicolai Ehemann <en@enlightened.de>
 *
 * This file is licensed under the GNU GPL version 3 or later.
 * See COPYING for details.
 */
namespace ZipStreamer;

require "src/ZipStreamer.php";
require "test/ZipComponents.php";

class File {
  const FILE = 1;
  const DIR = 2;
  public $filename;
  public $date;
  public $type;
  public $data;

  public function __construct($filename, $type, $date, $data = "") {
    $this->filename = $filename;
    $this->type = $type;
    $this->date = $date;
    $this->data = $data;
  }

  public function getSize() {
    return strlen($this->data);
  }
}

class TestZipStreamer extends \PHPUnit_Framework_TestCase {
  const ATTR_MADE_BY_VERSION = 0x032d; // made by version (upper byte: UNIX, lower byte v4.5)
  const EXT_FILE_ATTR_DIR = 0x41ed0010;
  const EXT_FILE_ATTR_FILE = 0x81a40000;
  protected $outstream;

  protected function setUp() {
    parent::setUp();
    $this->outstream = fopen('php://memory', 'rw');
    zipRecord::setUnitTest($this);
  }

  protected function tearDown() {
    fclose($this->outstream);
    parent::tearDown();
  }

  protected function getOutput() {
    rewind($this->outstream);
    return stream_get_contents($this->outstream);
  }

  protected static function getVersionToExtract($zip64, $isDir) {
    if ($zip64) {
      $version = 0x2d; // 4.5 - File uses ZIP64 format extensions
    } else if ($isDir) {
      $version = 0x14; // 2.0 - File is a folder (directory)
    } else {
      $version = 0x0a; // 1.0 - Default value
    }
    return $version;
  }

  protected function assertOutputEqualsFile($filename) {
    return $this->assertEquals(file_get_contents($filename), $this->getOutput());
  }

  protected function assertContainsOneMatch($pattern, $input) {
    $results = preg_grep($pattern, $input);
    return $this->assertEquals(1, sizeof($results));
  }

  protected function assertOutputZipfileOK($files, $options) {
    if (0 < sizeof($files)) { // php5.3 does not combine empty arrays
      $files = array_combine(array_map(function ($element) {
        return $element->filename;
      }, $files), $files);
    }
    $output = $this->getOutput();

    $eocdrec = EndOfCentralDirectoryRecord::constructFromString($output);
    $this->assertEquals(strlen($output) - 1, $eocdrec->end, "EOCDR last item in file");

    if ($options['zip64']) {
      $eocdrec->assertValues(array(
          "numberDisk" => 0xffff,
          "numberDiskStartCD" => 0xffff,
          "numberEntriesDisk" => 0xffff,
          "numberEntriesCD" => 0xffff,
          "size" => 0xffffffff,
          "offsetStart" => 0xffffffff,
          "lengthComment" => 0,
          "comment" => ''
      ));

      $z64eocdloc = Zip64EndOfCentralDirectoryLocator::constructFromString($output, strlen($output) - ($eocdrec->begin + 1));

      $this->assertEquals($z64eocdloc->end + 1, $eocdrec->begin, "Z64EOCDL directly before EOCDR");
      $z64eocdloc->assertValues(array(
          "numberDiskStartZ64EOCDL" => 0,
          "numberDisks" => 1
      ));

      $z64eocdrec = Zip64EndOfCentralDirectoryRecord::constructFromString($output, strlen($output) - ($z64eocdloc->begin + 1));

      $this->assertEquals(Count64::construct($z64eocdrec->begin), $z64eocdloc->offsetStart, "Z64EOCDR begin");
      $this->assertEquals($z64eocdrec->end + 1, $z64eocdloc->begin, "Z64EOCDR directly before Z64EOCDL");
      $z64eocdrec->assertValues(array(
          "size" => Count64::construct(44),
          "madeByVersion" => pack16le(self::ATTR_MADE_BY_VERSION),
          "versionToExtract" => pack16le($this->getVersionToExtract($options['zip64'], False)),
          "numberDisk" => 0,
          "numberDiskStartCDR" => 0,
          "numberEntriesDisk" => Count64::construct(sizeof($files)),
          "numberEntriesCD" => Count64::construct(sizeof($files))
      ));
      $sizeCD = $z64eocdrec->sizeCD->getLoBytes();
      $offsetCD = $z64eocdrec->offsetStart->getLoBytes();
      $beginFollowingRecord = $z64eocdrec->begin;
    } else {
      $eocdrec->assertValues(array(
          "numberDisk" => 0,
          "numberDiskStartCD" => 0,
          "numberEntriesDisk" => sizeof($files),
          "numberEntriesCD" => sizeof($files),
          "lengthComment" => 0,
          "comment" => ''
      ));
      $sizeCD = $eocdrec->size;
      $offsetCD = $eocdrec->offsetStart;
      $beginFollowingRecord = $eocdrec->begin;
    }

    $cdheaders = array();
    $pos = $offsetCD;
    $cdhead = null;

    while ($pos < $beginFollowingRecord) {
      $cdhead = CentralDirectoryHeader::constructFromString($output, $pos);
      $filename = $cdhead->filename;
      $pos = $cdhead->end + 1;
      $cdheaders[$filename] = $cdhead;

      $this->assertArrayHasKey($filename, $files, "CDH entry has valid name");
      $cdhead->assertValues(array(
          "madeByVersion" => pack16le(self::ATTR_MADE_BY_VERSION),
          "versionToExtract" => pack16le($this->getVersionToExtract($options['zip64'], File::DIR == $files[$filename]->type)),
          "gpFlags" => (File::FILE == $files[$filename]->type ? pack16le(GPFLAGS::ADD) : pack16le(GPFLAGS::NONE)),
          "gzMethod" => pack16le(0x0000),
          "dosTime" => pack32le(ZipStreamer::getDosTime($files[$filename]->date)),
          "lengthFilename" => strlen($filename),
          "lengthComment" => 0,
          "fileAttrInternal" => pack16le(0x0000),
          "fileAttrExternal" => (File::FILE == $files[$filename]->type ? pack32le(self::EXT_FILE_ATTR_FILE) : pack32le(self::EXT_FILE_ATTR_DIR))
      ));
      if ($options['zip64']) {
        $cdhead->assertValues(array(
            "sizeCompressed" => 0xffffffff,
            "size" => 0xffffffff,
            "lengthExtraField" => 32,
            "diskNumberStart" => 0xffff,
            "offsetStart" => 0xffffffff
        ));
        $cdhead->z64Ext->assertValues(array(
            "sizeField" => 28,
            "size" => Count64::construct($files[$filename]->getSize()),
            // TODO: check compression
            "sizeCompressed" => Count64::construct($files[$filename]->getSize()),
            "diskNumberStart" => 0
        ));
      } else {
        $cdhead->assertValues(array(
            "size" => $files[$filename]->getSize(),
            // TODO: check compression
            "sizeCompressed" => $files[$filename]->getSize(),
            "lengthExtraField" => 0,
            "diskNumberStart" => 0
        ));
      }
    }
    if (0 < sizeof($files)) {
      $this->assertEquals($cdhead->end + 1, $beginFollowingRecord, "CDH directlty before following record");
      $this->assertEquals(sizeof($files), sizeof($cdheaders), "CDH has correct number of entries");
      $this->assertEquals($sizeCD, $beginFollowingRecord - $offsetCD, "CDH has correct size");
    } else {
      $this->assertNull($cdhead);
    }

    $first = True;
    foreach ($cdheaders as $filename => $cdhead) {
      if ($options['zip64']) {
        $sizeCompressed = $cdhead->z64Ext->sizeCompressed->getLoBytes();
        $offsetStart = $cdhead->z64Ext->offsetStart->getLoBytes();
      } else {
        $sizeCompressed = $cdhead->sizeCompressed;
        $offsetStart = $cdhead->offsetStart;
      }
      if ($first) {
        $this->assertEquals(0, $offsetStart, "first file directly at beginning of zipfile");
      } else {
        $this->assertEquals($endLastFile + 1, $offsetStart, "file immediately after last file");
      }
      $file = FileEntry::constructFromString($output, $offsetStart, $sizeCompressed);
      $this->assertEquals($files[$filename]->data, $file->data);
      $this->assertEquals(crc32($files[$filename]->data), $cdhead->dataCRC32);
      if (GPFLAGS::ADD & $file->lfh->gpFlags) {
        $this->assertNotNull($file->dd, "data descriptor present (flag ADD set)");
      }
      if ($options['zip64']) {
        $file->lfh->assertValues(array(
            "sizeCompressed" => 0xffffffff,
            "size" => 0xffffffff,
        ));
        $file->lfh->z64Ext->assertValues(array(
            "sizeField" => 28,
            "size" => Count64::construct(0),
            "sizeCompressed" => Count64::construct(0),
            "diskNumberStart" => 0
        ));
      } else {
        $file->lfh->assertValues(array(
            "sizeCompressed" => 0,
            "size" => 0,
        ));
      }
      $file->lfh->assertValues(array(
          "versionToExtract" => pack16le($this->getVersionToExtract($options['zip64'], File::DIR == $files[$filename]->type)),
          "gpFlags" => (File::FILE == $files[$filename]->type ? GPFLAGS::ADD : GPFLAGS::NONE),
          "gzMethod" => 0x0000,
          "dosTime" => pack32le(ZipStreamer::getDosTime($files[$filename]->date)),
          "dataCRC32" => 0x0000,
          "lengthFilename" => strlen($filename),
          "filename" => $filename
      ));

      $endLastFile = $file->end;
      $first = False;
    }
    if (0 < sizeof($files)) {
      $this->assertEquals($offsetCD, $endLastFile + 1, "last file directly before CDH");
    } else {
      $this->assertEquals(0, $beginFollowingRecord, "empty zip file, CD records at beginning of file");
    }
  }

  public function providerSendHeadersOK() {
    // array(filename, mimetype), expectedMimetype, expextedFilename, $description
    return array(
      array(array(), 'application/zip', 'archive.zip', 'default headers'),
      array(array('file.zip', 'application/octet-stream'), 'application/octet-stream', 'file.zip', 'specific headers')
    );
  }

  /**
   * @dataProvider providerSendHeadersOK
   * @preserveGlobalState disabled
   * @runInSeparateProcess
   */
  public function testSendHeadersOK($arguments, $expectedMimetype, $expectedFilename, $description) {
    $zip = new ZipStreamer(array(
        'outstream' => $this->outstream
    ));
    call_user_func_array(array($zip, "sendHeaders"), $arguments);
    $headers = xdebug_get_headers();
    $this->assertContains('Pragma: public', $headers);
    $this->assertContains('Expires: 0', $headers);
    $this->assertContains('Accept-Ranges: bytes', $headers);
    $this->assertContains('Connection: Keep-Alive', $headers);
    $this->assertContains('Content-Transfer-Encoding: binary', $headers);
    $this->assertContains('Content-Type: ' . $expectedMimetype, $headers);
    $this->assertContains('Content-Disposition: attachment; filename="' . $expectedFilename . '";', $headers);
    $this->assertContainsOneMatch('/^Last-Modified: /', $headers);
  }

  public function providerZipfileOK() {
    // options, file(s), description
    return array(
        array(
            array(
                'zip64' => True
            ),
            array(),
            "empty (zip64)"
        ),
        array(
            array(
                'zip64' => True
            ),
            array(
                new File('test/', File::DIR, 1)
            ),
            "one empty dir (zip64)"
        ),
        array(
            array(
                'zip64' => True
            ),
            array(
                new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.')
            ),
            "one file (zip64)"
        ),
        array(
            array(
                'zip64' => True
            ),
            array(
                new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.'),
                new File('test/', File::DIR, 1),
                new File('test/test12.txt', File::FILE, 1, 'Duis malesuada lorem lorem, id sodales sapien sagittis ac. Donec in porttitor tellus, eu aliquam elit. Curabitur eu aliquam eros. Nulla accumsan augue quam, et consectetur quam eleifend eget. Donec cursus dolor lacus, eget pellentesque risus tincidunt at. Pellentesque rhoncus purus eget semper porta. Duis in magna tincidunt, fermentum orci non, consectetur nibh. Aliquam tortor eros, dignissim a posuere ac, rhoncus a justo. Sed sagittis velit ac massa pulvinar, ac pharetra ipsum fermentum. Etiam commodo lorem a scelerisque facilisis.')
            ),
            "simple structure (zip64)"
        ),
        array(
            array(
                'zip64' => False
            ),
            array(),
            "empty"
        ),
        array(
            array(
                'zip64' => False
            ),
            array(
                new File('test/', File::DIR, 1)
            ),
            "one empty dir"
        ),
        array(
            array(
                'zip64' => False
            ),
            array(
                new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.')
            ),
            "one file"
        ),
        array(
            array(
                'zip64' => False
            ),
            array(
                new File('test1.txt', File::FILE, 1, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed elit diam, posuere vel aliquet et, malesuada quis purus. Aliquam mattis aliquet massa, a semper sem porta in. Aliquam consectetur ligula a nulla vestibulum dictum. Interdum et malesuada fames ac ante ipsum primis in faucibus. Nullam luctus faucibus urna, accumsan cursus neque laoreet eu. Suspendisse potenti. Nulla ut feugiat neque. Maecenas molestie felis non purus tempor, in blandit ligula tincidunt. Ut in tortor sit amet nisi rutrum vestibulum vel quis tortor. Sed bibendum mauris sit amet gravida tristique. Ut hendrerit sapien vel tellus dapibus, eu pharetra nulla adipiscing. Donec in quam faucibus, cursus lacus sed, elementum ligula. Morbi volutpat vel lacus malesuada condimentum. Fusce consectetur nisl euismod justo volutpat sodales.'),
                new File('test/', File::DIR, 1),
                new File('test/test12.txt', File::FILE, 1, 'Duis malesuada lorem lorem, id sodales sapien sagittis ac. Donec in porttitor tellus, eu aliquam elit. Curabitur eu aliquam eros. Nulla accumsan augue quam, et consectetur quam eleifend eget. Donec cursus dolor lacus, eget pellentesque risus tincidunt at. Pellentesque rhoncus purus eget semper porta. Duis in magna tincidunt, fermentum orci non, consectetur nibh. Aliquam tortor eros, dignissim a posuere ac, rhoncus a justo. Sed sagittis velit ac massa pulvinar, ac pharetra ipsum fermentum. Etiam commodo lorem a scelerisque facilisis.')
            ),
            "simple structure"
        )
            );
  }

  /**
   * @dataProvider providerZipfileOK
   * preserveGlobalState disabled
   * runInSeparateProcess
   */
  public function testZipfile($options, $files, $description) {
    $options = array_merge($options, array('outstream' => $this->outstream));
    $zip = new ZipStreamer($options);
    foreach ($files as $file) {
      if (File::DIR == $file->type) {
        $zip->addEmptyDir($file->filename, $file->date);
      } else {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $file->data);
        rewind($stream);
        $zip->addFileFromStream($stream, $file->filename, $file->date);
        fclose($stream);
      }
    }
    $zip->finalize();

    $this->assertOutputZipfileOK($files, $options);
  }
}

?>
