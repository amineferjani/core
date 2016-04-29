<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\DAV\Tests\Unit\CalDAV;

use OCA\DAV\CalDAV\BirthdayService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCP\IL10N;
use Sabre\DAV\PropPatch;
use Test\TestCase;

class CalendarTest extends TestCase {

	/** @var IL10N */
	private $l10n;

	public function setUp() {
		parent::setUp();
		$this->l10n = $this->getMockBuilder('\OCP\IL10N')
			->disableOriginalConstructor()->getMock();
		$this->l10n
			->expects($this->any())
			->method('t')
			->will($this->returnCallback(function ($text, $parameters = array()) {
				return vsprintf($text, $parameters);
			}));
	}

	public function testDelete() {
		/** @var \PHPUnit_Framework_MockObject_MockObject | CalDavBackend $backend */
		$backend = $this->getMockBuilder('OCA\DAV\CalDAV\CalDavBackend')->disableOriginalConstructor()->getMock();
		$backend->expects($this->once())->method('updateShares');
		$backend->expects($this->any())->method('getShares')->willReturn([
			['href' => 'principal:user2']
		]);
		$calendarInfo = [
			'{http://owncloud.org/ns}owner-principal' => 'user1',
			'principaluri' => 'user2',
			'id' => 666,
			'uri' => 'cal',
		];
		$c = new Calendar($backend, $calendarInfo, $this->l10n);
		$c->delete();
	}

	/**
	 * @expectedException \Sabre\DAV\Exception\Forbidden
	 */
	public function testDeleteFromGroup() {
		/** @var \PHPUnit_Framework_MockObject_MockObject | CalDavBackend $backend */
		$backend = $this->getMockBuilder('OCA\DAV\CalDAV\CalDavBackend')->disableOriginalConstructor()->getMock();
		$backend->expects($this->never())->method('updateShares');
		$backend->expects($this->any())->method('getShares')->willReturn([
			['href' => 'principal:group2']
		]);
		$calendarInfo = [
			'{http://owncloud.org/ns}owner-principal' => 'user1',
			'principaluri' => 'user2',
			'id' => 666,
			'uri' => 'cal',
		];
		$c = new Calendar($backend, $calendarInfo, $this->l10n);
		$c->delete();
	}

	public function dataPropPatch() {
		return [
			[[], true],
			[[
				'{http://owncloud.org/ns}calendar-enabled' => true,
			], false],
			[[
				'{DAV:}displayname' => true,
			], true],
			[[
				'{DAV:}displayname' => true,
				'{http://owncloud.org/ns}calendar-enabled' => true,
			], true],
		];
	}

	/**
	 * @dataProvider dataPropPatch
	 */
	public function testPropPatch($mutations, $throws) {
		/** @var \PHPUnit_Framework_MockObject_MockObject | CalDavBackend $backend */
		$backend = $this->getMockBuilder('OCA\DAV\CalDAV\CalDavBackend')->disableOriginalConstructor()->getMock();
		$calendarInfo = [
			'{http://owncloud.org/ns}owner-principal' => 'user1',
			'principaluri' => 'user2',
			'id' => 666,
			'uri' => 'default'
		];
		$c = new Calendar($backend, $calendarInfo, $this->l10n);

		if ($throws) {
			$this->setExpectedException('\Sabre\DAV\Exception\Forbidden');
		}
		$c->propPatch(new PropPatch($mutations));
		if (!$throws) {
			$this->assertTrue(true);
		}
	}

	/**
	 * @dataProvider providesReadOnlyInfo
	 */
	public function testAcl($expectsWrite, $readOnlyValue, $hasOwnerSet, $uri = 'default') {
		/** @var \PHPUnit_Framework_MockObject_MockObject | CalDavBackend $backend */
		$backend = $this->getMockBuilder('OCA\DAV\CalDAV\CalDavBackend')->disableOriginalConstructor()->getMock();
		$backend->expects($this->any())->method('applyShareAcl')->willReturnArgument(1);
		$calendarInfo = [
			'principaluri' => 'user2',
			'id' => 666,
			'uri' => $uri
		];
		if (!is_null($readOnlyValue)) {
			$calendarInfo['{http://owncloud.org/ns}read-only'] = $readOnlyValue;
		}
		if ($hasOwnerSet) {
			$calendarInfo['{http://owncloud.org/ns}owner-principal'] = 'user1';
		}
		$c = new Calendar($backend, $calendarInfo, $this->l10n);
		$acl = $c->getACL();
		$childAcl = $c->getChildACL();

		$expectedAcl = [[
			'privilege' => '{DAV:}read',
			'principal' => $hasOwnerSet ? 'user1' : 'user2',
			'protected' => true
		], [
			'privilege' => '{DAV:}write',
			'principal' => $hasOwnerSet ? 'user1' : 'user2',
			'protected' => true
		]];
		if ($uri === BirthdayService::BIRTHDAY_CALENDAR_URI) {
			$expectedAcl = [[
				'privilege' => '{DAV:}read',
				'principal' => $hasOwnerSet ? 'user1' : 'user2',
				'protected' => true
			]];
		}
		if ($hasOwnerSet) {
			$expectedAcl[] = [
				'privilege' => '{DAV:}read',
				'principal' => 'user2',
				'protected' => true
			];
			if ($expectsWrite) {
				$expectedAcl[] = [
					'privilege' => '{DAV:}write',
					'principal' => 'user2',
					'protected' => true
				];
			}
		}
		$this->assertEquals($expectedAcl, $acl);
		$this->assertEquals($expectedAcl, $childAcl);
	}

	public function providesReadOnlyInfo() {
		return [
			'read-only property not set' => [true, null, true],
			'read-only property is false' => [true, false, true],
			'read-only property is true' => [false, true, true],
			'read-only property not set and no owner' => [true, null, false],
			'read-only property is false and no owner' => [true, false, false],
			'read-only property is true and no owner' => [false, true, false],
			'birthday calendar' => [false, false, false, BirthdayService::BIRTHDAY_CALENDAR_URI]
		];
	}
}