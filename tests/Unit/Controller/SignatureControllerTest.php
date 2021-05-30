<?php

namespace OCA\Libresign\Tests\Unit\Controller;

use OCA\Libresign\Tests\Unit\ApiTestCase;
use OCA\Libresign\Tests\Unit\UserTrait;

final class SignatureControllerTest extends ApiTestCase {
	use UserTrait;
	public function setUp(): void {
		parent::setUp();
		$this->userSetUp();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testHasRootCertReturnSuccess() {
		$this->createUser('username', 'password');
		$request = new \OCA\Libresign\Tests\Unit\ApiRequester();
		$request
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('username:password')
			])
			->withPath('/signature/has-root-cert');

		$this->assertRequest($request);
	}
}
