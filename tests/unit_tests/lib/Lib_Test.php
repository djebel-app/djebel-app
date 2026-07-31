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

    public function testLoadLibSplitsSeparatorDelimitedList()
    {
        // A separator-delimited string is split into ids — NOT treated as one id (which, holding a
        // pipe/space, would fail the id validation and throw). Success proves the split happened.
        $lib_dir = $this->getLibDir();
        $res_obj = Dj_App_Lib::loadLib('djebel-test-lib | djebel-absent-lib', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
    }

    public function testLoadLibWildcardLoadsEveryLibInDir()
    {
        // '*' expands to every lib in the dir (unexpanded it would fail id validation and throw).
        $lib_dir = $this->getLibDir();
        $res_obj = Dj_App_Lib::loadLib('*', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
    }

    public function testLoadLibGlobPrefixMatchesLibIds()
    {
        // "djebel-*" is a glob (the "*" would fail id validation if it were NOT expanded), so a
        // successful load proves the prefix match ran and resolved to djebel-test-lib.
        $lib_dir = $this->getLibDir();
        $res_obj = Dj_App_Lib::loadLib('djebel-*', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));

        // A glob that matches nothing is harmless — no ids resolved, no throw.
        $no_match_res = Dj_App_Lib::loadLib('zzz-no-such-lib*', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($no_match_res->isSuccess());
    }

    public function testLoadLibEnableFlagLoadsEveryLib()
    {
        // An enable flag ("1") is shorthand for the "*" glob → loads every lib in the dir.
        $lib_dir = $this->getLibDir();
        $res_obj = Dj_App_Lib::loadLib('1', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
    }

    public function testLoadLibSkipsZzzSkipPrefixedLibs()
    {
        // A "*" bulk load must leave a parked (zzz_skip_-prefixed) lib out — its class stays
        // undefined even though a real lib in the same dir loads.
        $lib_dir = $this->getLibDir();
        $res_obj = Dj_App_Lib::loadLib('*', [ 'dir' => $lib_dir, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertTrue(class_exists('Djebel_Test_Lib'));
        $this->assertFalse(class_exists('Djebel_Zzz_Skip_Disabled_Lib'));
    }

}
