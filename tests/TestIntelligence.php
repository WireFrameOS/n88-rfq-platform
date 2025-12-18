<?php
/**
 * Test Intelligence Engine (Unit Normalization, CBM, Timeline Derivation)
 */
class TestIntelligence extends PHPUnit\Framework\TestCase {

    public function test_normalize_mm_to_cm() {
        $result = N88_Intelligence::normalize_to_cm( 100, 'mm' );
        $this->assertEquals( 10.0, $result, '100mm should equal 10cm', 0.01 );
    }

    public function test_normalize_cm_to_cm() {
        $result = N88_Intelligence::normalize_to_cm( 50, 'cm' );
        $this->assertEquals( 50.0, $result, '50cm should equal 50cm', 0.01 );
    }

    public function test_normalize_m_to_cm() {
        $result = N88_Intelligence::normalize_to_cm( 2, 'm' );
        $this->assertEquals( 200.0, $result, '2m should equal 200cm', 0.01 );
    }

    public function test_normalize_in_to_cm() {
        $result = N88_Intelligence::normalize_to_cm( 10, 'in' );
        $this->assertEquals( 25.4, $result, '10in should equal 25.4cm', 0.01 );
    }

    public function test_normalize_invalid_unit() {
        $result = N88_Intelligence::normalize_to_cm( 100, 'invalid' );
        $this->assertNull( $result, 'Invalid unit should return null' );
    }

    public function test_normalize_negative_value() {
        $result = N88_Intelligence::normalize_to_cm( -10, 'cm' );
        $this->assertNull( $result, 'Negative value should return null' );
    }

    public function test_calculate_cbm_valid() {
        $result = N88_Intelligence::calculate_cbm( 100, 50, 25 );
        $expected = ( 100 * 50 * 25 ) / 1000000.0;
        $this->assertEquals( $expected, $result, 'CBM calculation should be correct', 0.000001 );
    }

    public function test_calculate_cbm_missing_dimension() {
        $result = N88_Intelligence::calculate_cbm( 100, 50, null );
        $this->assertNull( $result, 'CBM should be null when any dimension is missing' );
    }

    public function test_calculate_cbm_zero_dimension() {
        $result = N88_Intelligence::calculate_cbm( 100, 50, 0 );
        $this->assertNull( $result, 'CBM should be null for zero dimensions' );
    }

    public function test_calculate_cbm_negative_dimension() {
        $result = N88_Intelligence::calculate_cbm( 100, -50, 25 );
        $this->assertNull( $result, 'CBM should be null for negative dimensions' );
    }

    public function test_derive_timeline_type_furniture() {
        $result = N88_Intelligence::derive_timeline_type( 'furniture' );
        $this->assertEquals( '6_step', $result, 'Furniture should derive 6_step timeline' );
    }

    public function test_derive_timeline_type_global_sourcing() {
        $result = N88_Intelligence::derive_timeline_type( 'global_sourcing' );
        $this->assertEquals( '4_step', $result, 'Global sourcing should derive 4_step timeline' );
    }

    public function test_derive_timeline_type_invalid() {
        $result = N88_Intelligence::derive_timeline_type( 'invalid' );
        $this->assertNull( $result, 'Invalid sourcing type should return null' );
    }

    public function test_derive_timeline_type_null() {
        $result = N88_Intelligence::derive_timeline_type( null );
        $this->assertNull( $result, 'Null sourcing type should return null' );
    }

    public function test_is_valid_unit() {
        $this->assertTrue( N88_Intelligence::is_valid_unit( 'mm' ), 'mm should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_unit( 'cm' ), 'cm should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_unit( 'm' ), 'm should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_unit( 'in' ), 'in should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_unit( null ), 'null should be valid (allowed)' );
        $this->assertTrue( N88_Intelligence::is_valid_unit( '' ), 'empty string should be valid (allowed)' );
        $this->assertFalse( N88_Intelligence::is_valid_unit( 'invalid' ), 'invalid should not be valid' );
    }

    public function test_is_valid_sourcing_type() {
        $this->assertTrue( N88_Intelligence::is_valid_sourcing_type( 'furniture' ), 'furniture should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_sourcing_type( 'global_sourcing' ), 'global_sourcing should be valid' );
        $this->assertTrue( N88_Intelligence::is_valid_sourcing_type( null ), 'null should be valid (allowed)' );
        $this->assertTrue( N88_Intelligence::is_valid_sourcing_type( '' ), 'empty string should be valid (allowed)' );
        $this->assertFalse( N88_Intelligence::is_valid_sourcing_type( 'invalid' ), 'invalid should not be valid' );
    }

    public function test_get_supported_units() {
        $units = N88_Intelligence::get_supported_units();
        $this->assertIsArray( $units, 'Should return array' );
        $this->assertContains( 'mm', $units, 'Should include mm' );
        $this->assertContains( 'cm', $units, 'Should include cm' );
        $this->assertContains( 'm', $units, 'Should include m' );
        $this->assertContains( 'in', $units, 'Should include in' );
    }

    public function test_get_allowed_sourcing_types() {
        $types = N88_Intelligence::get_allowed_sourcing_types();
        $this->assertIsArray( $types, 'Should return array' );
        $this->assertContains( 'furniture', $types, 'Should include furniture' );
        $this->assertContains( 'global_sourcing', $types, 'Should include global_sourcing' );
    }
}

