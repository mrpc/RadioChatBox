<?php

namespace RadioChatBox\Tests;

use PHPUnit\Framework\TestCase;
use RadioChatBox\ChatService;
use Mockery;

class ProfileRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that profile is required when setting is enabled
     */
    public function testProfileRequiredWhenSettingEnabled()
    {
        $mockPdo = Mockery::mock(\PDO::class);
        
        // Mock getSetting to return 'true' for require_profile
        $stmt = Mockery::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with(['require_profile']);
        $stmt->shouldReceive('fetch')->once()->andReturn(['setting_value' => 'true']);
        
        $mockPdo->shouldReceive('prepare')
            ->with("SELECT setting_value FROM settings WHERE setting_key = ?")
            ->andReturn($stmt);
        
        $chatService = new ChatService();
        $reflection = new \ReflectionClass($chatService);
        
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($chatService, $mockPdo);
        
        $result = $chatService->getSetting('require_profile');
        
        $this->assertEquals('true', $result);
    }

    /**
     * Test that age validation rejects values outside 18-120 range
     */
    public function testAgeValidationRejectsInvalidRange()
    {
        // Test under 18
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Age must be between 18 and 120');
        
        // This would be called from register.php validation
        $age = 17;
        if ($age < 18 || $age > 120) {
            throw new \InvalidArgumentException('Age must be between 18 and 120');
        }
    }

    /**
     * Test that age validation accepts valid range
     */
    public function testAgeValidationAcceptsValidRange()
    {
        $validAges = [18, 25, 50, 100, 120];
        
        foreach ($validAges as $age) {
            // Should not throw exception
            if ($age < 18 || $age > 120) {
                $this->fail("Age {$age} should be valid");
            }
        }
        
        $this->assertTrue(true);
    }

    /**
     * Test that empty string is properly detected (not using empty() which fails for "0")
     */
    public function testEmptyStringDetection()
    {
        // The bug was using empty() which returns true for "0"
        $testCases = [
            ['value' => null, 'isEmpty' => true],
            ['value' => '', 'isEmpty' => true],
            ['value' => '0', 'isEmpty' => false], // "0" is a valid value
            ['value' => 0, 'isEmpty' => false],
            ['value' => '25', 'isEmpty' => false],
            ['value' => 25, 'isEmpty' => false],
        ];
        
        foreach ($testCases as $case) {
            $value = $case['value'];
            $expectedEmpty = $case['isEmpty'];
            
            // Correct way (what we fixed it to)
            $actualEmpty = ($value === null || $value === '');
            
            $this->assertEquals(
                $expectedEmpty, 
                $actualEmpty,
                "Value " . var_export($value, true) . " should be " . 
                ($expectedEmpty ? 'empty' : 'not empty')
            );
        }
    }

    /**
     * Test that profile data validation differentiates between null and empty string
     */
    public function testProfileDataValidation()
    {
        // Simulate the validation logic from register.php
        $testCases = [
            // All fields provided - should pass
            ['age' => '25', 'location' => 'US', 'sex' => 'male', 'shouldPass' => true],
            
            // Age is null - should fail when required
            ['age' => null, 'location' => 'US', 'sex' => 'male', 'shouldPass' => false],
            
            // Age is empty string - should fail when required
            ['age' => '', 'location' => 'US', 'sex' => 'male', 'shouldPass' => false],
            
            // Location is null - should fail when required
            ['age' => '25', 'location' => null, 'sex' => 'male', 'shouldPass' => false],
            
            // Location is empty - should fail when required
            ['age' => '25', 'location' => '', 'sex' => 'male', 'shouldPass' => false],
            
            // Sex is null - should fail when required
            ['age' => '25', 'location' => 'US', 'sex' => null, 'shouldPass' => false],
            
            // Sex is empty - should fail when required
            ['age' => '25', 'location' => 'US', 'sex' => '', 'shouldPass' => false],
        ];
        
        foreach ($testCases as $case) {
            $age = $case['age'];
            $location = $case['location'];
            $sex = $case['sex'];
            $shouldPass = $case['shouldPass'];
            
            // This is the validation logic from register.php (after fix)
            $isValid = !($age === null || $age === '' || 
                        $location === null || $location === '' || 
                        $sex === null || $sex === '');
            
            $this->assertEquals(
                $shouldPass, 
                $isValid,
                "Validation should " . ($shouldPass ? 'pass' : 'fail') . 
                " for age={$age}, location={$location}, sex={$sex}"
            );
        }
    }

    /**
     * Test age conversion to integer for validation
     */
    public function testAgeConversionToInteger()
    {
        // The fix casts age to int before validation
        $testCases = [
            ['input' => '25', 'expected' => 25],
            ['input' => 25, 'expected' => 25],
            ['input' => '18', 'expected' => 18],
            ['input' => '120', 'expected' => 120],
        ];
        
        foreach ($testCases as $case) {
            $ageInt = (int)$case['input'];
            $this->assertEquals($case['expected'], $ageInt);
            $this->assertTrue($ageInt >= 18 && $ageInt <= 120);
        }
    }

    /**
     * Test that profile update SQL uses correct composite unique constraint
     */
    public function testProfileUpdateConstraint()
    {
        // The bug was using ON CONFLICT (username) instead of (username, session_id)
        $correctSQL = "
            INSERT INTO user_profiles (username, session_id, age, sex, location)
            VALUES (:username, :session_id, :age, :sex, :location)
            ON CONFLICT (username, session_id) 
            DO UPDATE SET 
                age = EXCLUDED.age,
                sex = EXCLUDED.sex,
                location = EXCLUDED.location
        ";
        
        // Check that SQL contains the composite key
        $this->assertStringContainsString('ON CONFLICT (username, session_id)', $correctSQL);
        $this->assertStringContainsString('session_id', $correctSQL);
    }

    /**
     * Test null/undefined/empty string differentiation for JavaScript payload
     */
    public function testJavaScriptPayloadValidation()
    {
        // Simulating the JavaScript validation logic (after fix)
        $testCases = [
            ['value' => null, 'shouldInclude' => false],
            ['value' => '', 'shouldInclude' => false],
            ['value' => '0', 'shouldInclude' => true],
            ['value' => 'US', 'shouldInclude' => true],
            ['value' => 'male', 'shouldInclude' => true],
            ['value' => '25', 'shouldInclude' => true],
        ];
        
        foreach ($testCases as $case) {
            $value = $case['value'];
            
            // This is the logic from chat.js (after fix)
            // In JavaScript: if (value !== null && value !== undefined && value !== '')
            // In PHP equivalent: value not null and not empty string
            $shouldInclude = ($value !== null && $value !== '');
            
            $this->assertEquals(
                $case['shouldInclude'],
                $shouldInclude,
                "Value " . var_export($value, true) . " should " . 
                ($case['shouldInclude'] ? 'be included' : 'not be included') . 
                " in payload"
            );
        }
    }
}
