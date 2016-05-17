<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
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
namespace OCA\DAV\Tests\Unit\Connector\Sabre;

use OCA\DAV\Connector\Sabre\FakeLockerPlugin;
use Sabre\HTTP\Response;
use Test\TestCase;

/**
 * Class FakeLockerPluginTest
 *
 * @package OCA\DAV\Tests\Unit\Connector\Sabre
 */
class FakeLockerPluginTest extends TestCase {
	/** @var FakeLockerPlugin */
	private $fakeLockerPlugin;

	public function setUp() {
		parent::setUp();
		$this->fakeLockerPlugin = new FakeLockerPlugin();
	}

	public function testInitialize() {
		/** @var \Sabre\DAV\Server $server */
		$server = $this->getMock('\Sabre\DAV\Server');
		$server
			->expects($this->at(0))
			->method('on')
			->with('method:LOCK', [$this->fakeLockerPlugin, 'fakeLockProvider'], 1);
		$server
			->expects($this->at(1))
			->method('on')
			->with('method:UNLOCK', [$this->fakeLockerPlugin, 'fakeUnlockProvider'], 1);
		$server
			->expects($this->at(2))
			->method('on')
			->with('propFind', [$this->fakeLockerPlugin, 'propFind']);
		$server
			->expects($this->at(3))
			->method('on')
			->with('validateTokens', [$this->fakeLockerPlugin, 'validateTokens']);

		$this->fakeLockerPlugin->initialize($server);
	}

	public function testGetHTTPMethods() {
		$expected = [
			'LOCK',
			'UNLOCK',
		];
		$this->assertSame($expected, $this->fakeLockerPlugin->getHTTPMethods('Test'));
	}

	public function testGetFeatures() {
		$expected = [
			2,
		];
		$this->assertSame($expected, $this->fakeLockerPlugin->getFeatures());
	}

	public function testPropFind() {
		$propFind = $this->getMockBuilder('\Sabre\DAV\PropFind')
			->disableOriginalConstructor()
			->getMock();
		$node = $this->getMock('\Sabre\DAV\INode');

		$propFind->expects($this->at(0))
			->method('handle')
			->with('{DAV:}supportedlock');
		$propFind->expects($this->at(1))
			->method('handle')
			->with('{DAV:}lockdiscovery');

		$this->fakeLockerPlugin->propFind($propFind, $node);
	}

	public function tokenDataProvider() {
		return [
			[
				[
					[
						'tokens' => [
							[
								'token' => 'aToken',
								'validToken' => false,
							],
							[],
							[
								'token' => 'opaquelocktoken:asdf',
								'validToken' => false,
							]
						],
					]
				],
				[
					[
						'tokens' => [
							[
								'token' => 'aToken',
								'validToken' => false,
							],
							[],
							[
								'token' => 'opaquelocktoken:asdf',
								'validToken' => true,
							]
						],
					]
				],
			]
		];
	}

	/**
	 * @dataProvider tokenDataProvider
	 * @param array $input
	 * @param array $expected
	 */
	public function testValidateTokens(array $input, array $expected) {
		$request = $this->getMock('\Sabre\HTTP\RequestInterface');
		$this->fakeLockerPlugin->validateTokens($request, $input);
		$this->assertSame($expected, $input);
	}

	public function testFakeLockProvider() {
		$request = $this->getMock('\Sabre\HTTP\RequestInterface');
		$response = new Response();
		$server = $this->getMock('\Sabre\DAV\Server');
		$this->fakeLockerPlugin->initialize($server);

		$request->expects($this->exactly(2))
			->method('getPath')
			->will($this->returnValue('MyPath'));

		$this->assertSame(false, $this->fakeLockerPlugin->fakeLockProvider($request, $response));

		$expectedXml = '<?xml version="1.0" encoding="utf-8"?><d:prop xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><d:lockdiscovery><d:activelock><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:lockroot><d:href>MyPath</d:href></d:lockroot><d:depth>infinity</d:depth><d:timeout>Second-1800</d:timeout><d:locktoken><d:href>opaquelocktoken:fe4f7f2437b151fbcb4e9f5c8118c6b1</d:href></d:locktoken><d:owner/></d:activelock></d:lockdiscovery></d:prop>';

		$this->assertXmlStringEqualsXmlString($expectedXml, $response->getBody());
	}

	public function testFakeUnlockProvider() {
		$request = $this->getMock('\Sabre\HTTP\RequestInterface');
		$response = $this->getMock('\Sabre\HTTP\ResponseInterface');

		$response->expects($this->once())
				->method('setStatus')
				->with('204');
		$response->expects($this->once())
				->method('setHeader')
				->with('Content-Length', '0');

		$this->assertSame(false, $this->fakeLockerPlugin->fakeUnlockProvider($request, $response));
	}
}