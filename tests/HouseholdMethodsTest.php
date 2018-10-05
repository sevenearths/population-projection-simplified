<?php

use App\PopulationProjectionSimplified\Household;

use Illuminate\Support\Facades\DB;

class HouseholdMethodsTest extends TestCase
{
    private $start_year = 2013;
    private $end_year = 2023;

    public function setUp()
    {
        parent::setUp();

        config(['database.connections.mysql.database' => $this->database]);

        $this->household = new Household($this->database, $this->local_authority, $this->start_year, $this->end_year);
    }

    public function testCheckDataHasCorrectNumberOfEntries()
    {
        $data = ['a', 'b', 'c'];
        try {
            $this->invokeMethod($this->household, 'checkDataHasCorrectNumberOfEntries', [$data, count($data)]);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->assertTrue(false, $e->getMessage());
        }

        $data = ['a', 'b', 'c', 'd', 'e', 'f'];
        try {
            $this->invokeMethod($this->household, 'checkDataHasCorrectNumberOfEntries', [$data, count($data)]);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->assertTrue(false, $e->getMessage());
        }

        $data = ['a', 'b', 'c', 'd', 'e', 'f'];
        try {
            $this->invokeMethod($this->household, 'checkDataHasCorrectNumberOfEntries', [$data, 2]);
            $this->assertTrue(false);
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        try {
            $this->invokeMethod($this->household, 'checkDataHasCorrectNumberOfEntries', [$data, 20]);
            $this->assertTrue(false);
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testCheckDatabaseHasDataForStartAndEndYear()
    {
        $this->household->start_year = 1991;
        $this->household->end_year = 2039;

        try {
            $this->invokeMethod($this->household, 'checkDatabaseHasDataForStartAndEndYear', []);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->assertTrue(false, $e->getMessage());
        }

        $this->household->start_year = 1990;
        $this->household->end_year = 2039;

        try {
            $this->invokeMethod($this->household, 'checkDatabaseHasDataForStartAndEndYear', []);
            $this->assertTrue(false);
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }

        $this->household->start_year = 1991;
        $this->household->end_year = 2040;

        try {
            $this->invokeMethod($this->household, 'checkDatabaseHasDataForStartAndEndYear', []);
            $this->assertTrue(false);
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testMakeSexAllInCombination()
    {
        $test_data = [
            ['combination' => '0_4, M, S', 'result' => '0_4, All, S'],
            ['combination' => '40_44, F, C', 'result' => '40_44, All, C'],
            ['combination' => '55_59, F, P', 'result' => '55_59, All, P'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'makeSexAllInCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetMaleCombination()
    {
        $test_data = [
            ['combination' => '0_4, M, S', 'result' => '0_4, M, S'],
            ['combination' => '40_44, F, C', 'result' => '40_44, M, C'],
            ['combination' => '55_59, F, P', 'result' => '55_59, M, P'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'getMaleCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetFemaleCombination()
    {
        $test_data = [
            ['combination' => '0_4, F, S', 'result' => '0_4, F, S'],
            ['combination' => '40_44, M, C', 'result' => '40_44, F, C'],
            ['combination' => '55_59, M, P', 'result' => '55_59, F, P'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'getFemaleCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetSingleCombination()
    {
        $test_data = [
            ['combination' => '0_4, F, S', 'result' => '0_4, F, S'],
            ['combination' => '40_44, M, C', 'result' => '40_44, M, S'],
            ['combination' => '55_59, M, P', 'result' => '55_59, M, S'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'getSingleCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetCoupleCombination()
    {
        $test_data = [
            ['combination' => '0_4, F, S', 'result' => '0_4, F, C'],
            ['combination' => '40_44, M, C', 'result' => '40_44, M, C'],
            ['combination' => '55_59, All, P', 'result' => '55_59, All, C'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'getCoupleCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetPreviouslyMarriedCombination()
    {
        $test_data = [
            ['combination' => '0_4, F, S', 'result' => '0_4, F, P'],
            ['combination' => '40_44, M, C', 'result' => '40_44, M, P'],
            ['combination' => '55_59, All, P', 'result' => '55_59, All, P'],
        ];
        foreach ($test_data as $data) {
            $result = $this->invokeMethod($this->household, 'getPreviouslyMarriedCombination', [$data['combination']]);
            $this->assertEquals($result, $data['result']);
        }
    }

    public function testGetCombinationRelationshipType()
    {
        //
    }

}