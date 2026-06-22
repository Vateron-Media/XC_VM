<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers QueryHelper
 */
final class QueryHelperTest extends TestCase {

	public function testPrepareColumnSanitizesToSafeIdentifier() {
		$this->assertSame('foobar23', QueryHelper::prepareColumn('Foo Bar!23'));
		$this->assertSame('user_id', QueryHelper::prepareColumn('user_id'));
		$this->assertSame('droptable', QueryHelper::prepareColumn('drop;table'));
	}

	public function testPrepareArrayBuildsColumnsPlaceholdersAndData() {
		$result = QueryHelper::prepareArray(array('name' => 'x', 'age' => 5));

		$this->assertSame('`name`,`age`', $result['columns']);
		$this->assertSame('?,?', $result['placeholder']);
		$this->assertSame(array('x', 5), $result['data']);
		$this->assertSame('`name` = ?,`age` = ?', $result['update']);
	}

	public function testPrepareArrayJsonEncodesArrayValues() {
		$result = QueryHelper::prepareArray(array('tags' => array(1, 2, 3)));
		$this->assertSame('[1,2,3]', $result['data'][0]);
	}

	public function testPrepareArrayNormalizesNullValues() {
		$result = QueryHelper::prepareArray(array('a' => null, 'b' => 'null'));
		$this->assertNull($result['data'][0]);
		$this->assertNull($result['data'][1]);
	}

	public function testPrepareArraySanitizesColumnNames() {
		$result = QueryHelper::prepareArray(array('na me!' => 'v'));
		$this->assertSame('`name`', $result['columns']);
	}
}
