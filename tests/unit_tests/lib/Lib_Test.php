<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Lib_Test extends TestCase {

    private function getLibDir()
    {
        return DJEBEL_APP_TEST_DATA_DIR . '/lib';
    }

    public function testLoadLibLoadsPresentLib()
    {
        $res_obj = Dj_App_Lib::loadLib('djebel-test-lib', [ 'dir' => $this->getLibDir(), ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
    }

    public function testLoadLibSoftSkipsAbsentLib()
    {
        // Valid id, but no such lib on disk — the "load it if it's there" case: no throw.
        $res_obj = Dj_App_Lib::loadLib('djebel-absent-lib', [ 'dir' => $this->getLibDir(), ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertFalse(class_exists('Djebel_Absent_Lib'));
    }

    public function testLoadLibAcceptsArrayOfIds()
    {
        $res_obj = Dj_App_Lib::loadLib([ 'djebel-test-lib', 'djebel-absent-lib', ], [ 'dir' => $this->getLibDir(), ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
    }

    public function testLoadLibThrowsOnTraversalId()
    {
        $this->expectException(Dj_App_Exception::class);

        Dj_App_Lib::loadLib('../../etc/passwd', [ 'dir' => $this->getLibDir(), ]);
    }

    public function testLoadLibThrowsOnNonPhpEntry()
    {
        $this->expectException(Dj_App_Exception::class);

        Dj_App_Lib::loadLib('djebel-test-lib', [ 'dir' => $this->getLibDir(), 'entry' => 'lib.txt', ]);
    }

    public function testLoadLibIsIdempotent()
    {
        // require_once — loading twice must not fatal and stays successful.
        Dj_App_Lib::loadLib('djebel-test-lib', [ 'dir' => $this->getLibDir(), ]);
        $res_obj = Dj_App_Lib::loadLib('djebel-test-lib', [ 'dir' => $this->getLibDir(), ]);

        $this->assertTrue($res_obj->isSuccess());
    }

    public function testLoadLibResolvesTheDefaultLibDirWhenNoneGiven()
    {
        // Default use — NO 'dir' passed. loadLib figures the dir out itself (getCorePrivateDir
        // app/lib, the smart default), so real callers never hand it a dir. The probe lib isn't
        // installed in that resolved dir here, so it soft-skips — the point is the default path
        // resolves with no caller-supplied dir and still succeeds.
        $res_obj = Dj_App_Lib::loadLib('djebel-default-probe-lib');

        $this->assertTrue($res_obj->isSuccess());
    }

}
