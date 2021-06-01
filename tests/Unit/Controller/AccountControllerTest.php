<?php

namespace OCA\Libresign\Tests\Unit\Controller;

use donatj\MockWebServer\Response;
use OC\Authentication\Login\Chain;
use OCA\Libresign\Controller\AccountController;
use OCA\Libresign\Db\File as LibresignFile;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileUser;
use OCA\Libresign\Helper\JSActions;
use OCA\Libresign\Service\AccountService;
use OCA\Libresign\Tests\lib\User\Dummy;
use OCA\Libresign\Tests\Unit\ApiTestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * @group DB
 */
final class AccountControllerTest extends ApiTestCase {
	use ProphecyTrait;
	/** @var AccountController */
	private $controller;
	/** @var IL10N */
	private $l10n;
	/** @var AccountService */
	private $account;
	/** @var FileMapper */
	private $fileMapper;
	/** @var IRootFolder */
	private $root;
	/** @var Chain */
	private $loginChain;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IUserSession */
	private $session;

	public function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n
			->method('t')
			->will($this->returnArgument(0));
		$this->account = $this->createMock(AccountService::class);
		$this->fileMapper = $this->createMock(FileMapper::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->loginChain = $this->createMock(Chain::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->session = $this->getMockBuilder(IUserSession::class)
			->disableOriginalConstructor()
			->getMock();
		$this->controller = new AccountController(
			$request,
			$this->l10n,
			$this->account,
			$this->fileMapper,
			$this->root,
			$this->loginChain,
			$this->urlGenerator,
			$this->session
		);
	}

	public function testCreateSuccess() {
		$fileUser = $this->createMock(FileUser::class);
		$fileUser
			->method('__call')
			->withConsecutive(
				[$this->equalTo('getFileId'), $this->anything()],
				[$this->equalTo('getDescription'), $this->anything()]
			)
			->will($this->returnValueMap([
				[$this->returnValue(1)],
				[$this->returnValue('Description')]
			]));
		$this->account
			->method('getFileUserByUuid')
			->will($this->returnValue($fileUser));

		$fileData = $this->createMock(LibresignFile::class);
		$fileData
			->method('__call')
			->withConsecutive(
				[$this->equalTo('getUserId'), $this->anything()],
				[$this->equalTo('getNodeId'), $this->anything()],
				[$this->equalTo('getName'), $this->anything()]
			)
			->will($this->returnValueMap([
				['getUserId', [], 1],
				['getNodeId', [], 1],
				['getName', [], 'Filename']
			]));
		$this->fileMapper
			->method('getById')
			->will($this->returnValue($fileData));

		$userDummyBackend = $this->createMock(Dummy::class);
		$userDummyBackend
			->method('userExists')
			->will($this->returnValue(true));
		\OC::$server->getUserManager()->registerBackend($userDummyBackend);
		\OC::$server->getSession()->set('user_id', 1);

		$node = $this->createMock(File::class);
		$node->method('getContent')
			->will($this->returnvalue('PDF'));
		$this->root
			->method('getById')
			->will($this->returnValue([$node]));

			
		$this->urlGenerator
			->method('linkToRoute')
			->will($this->returnValue('http://test.coop'));

		$actual = $this->controller->createToSign('uuid', 'email', 'password', 'signPassword');
		$expected = new JSONResponse([
			'message' => 'Success',
			'action' => JSActions::ACTION_SIGN,
			'filename' => 'Filename',
			'description' => null,
			'pdf' => [
				'url' => 'http://test.coop'
			]
		], Http::STATUS_OK);
		$this->assertEquals($expected, $actual);
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testAccountSignatureEndpointWithSuccess() {
		$user = $this->createUser('username', 'password');
		$user->setEMailAddress('person@test.coop');
		self::$server->setResponseOfPath('/api/v1/cfssl/newcert', new Response(
			file_get_contents(__DIR__ . '/../../fixtures/cfssl/newcert-with-success.json')
		));

		$this->mockConfig([
			'libresign' => [
				'commonName' => 'CommonName',
				'country' => 'Brazil',
				'organization' => 'Organization',
				'organizationUnit' => 'organizationUnit',
				'cfsslUri' => self::$server->getServerRoot() . '/api/v1/cfssl/'
			]
		]);

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('username:password'),
				'Content-Type' => 'application/json'
			])
			->withRequestBody([
				'signPassword' => 'password'
			])
			->withPath('/account/signature');

		$home = $user->getHome();
		$this->assertFileDoesNotExist($home . '/files/LibreSign/signature.pfx');
		$this->assertRequest();
		$this->assertFileExists($home . '/files/LibreSign/signature.pfx');
	}

	/**
	 * @runInSeparateProcess
	 */
	public function testAccountSignatureEndpointWithFailure() {
		$this->createUser('username', 'password');

		$this->request
			->withMethod('POST')
			->withRequestHeader([
				'Authorization' => 'Basic ' . base64_encode('username:password'),
				'Content-Type' => 'application/json'
			])
			->withRequestBody([
				'signPassword' => ''
			])
			->withPath('/account/signature')
			->assertResponseCode(401);

		$this->assertRequest();
	}
}
