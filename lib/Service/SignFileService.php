<?php

namespace OCA\Libresign\Service;

use OC\AppFramework\Utility\TimeFactory;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\DataObjects\VisibleElementAssoc;
use OCA\Libresign\Db\AccountFileMapper;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileElementMapper;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileUser as FileUserEntity;
use OCA\Libresign\Db\FileUserMapper;
use OCA\Libresign\Db\UserElementMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\Pkcs7Handler;
use OCA\Libresign\Handler\Pkcs12Handler;
use OCA\Libresign\Handler\TCPDILibresign;
use OCA\Libresign\Helper\ValidateHelper;
use OCP\Accounts\IAccountManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IServerContainer;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;
use TCPDF_PARSER;

class SignFileService {
	/** @var IL10N */
	private $l10n;
	/** @var FileMapper */
	private $fileMapper;
	/** @var FileUserMapper */
	private $fileUserMapper;
	/** @var AccountFileMapper */
	private $accountFileMapper;
	/** @var Pkcs7Handler */
	private $pkcs7Handler;
	/** @var Pkcs12Handler */
	private $pkcs12Handler;
	/** @var FolderService */
	private $folderService;
	/** @var IClientService */
	private $client;
	/** @var IUserManager */
	private $userManager;
	/** @var MailService */
	private $mail;
	/** @var LoggerInterface */
	private $logger;
	/** @var IConfig */
	private $config;
	/** @var ValidateHelper */
	private $validateHelper;
	/** @var IHasher */
	private $hasher;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var IAppManager */
	private $appManager;
	/** @var IAccountManager */
	private $accountManager;
	/** @var IServerContainer */
	private $serverContainer;
	/** @var IRootFolder */
	private $root;
	/** @var FileElementMapper */
	private $fileElementMapper;
	/** @var UserElementMapper */
	private $userElementMapper;
	/** @var FileService */
	private $fileService;
	/** @var TimeFactory; */
	private $timeFactory;
	/** @var ITempManager */
	private $tempManager;
	/** @var FileUserEntity */
	private $fileUser;
	/** @var string */
	private $password;
	/** @var FileEntity */
	private $libreSignFile;
	/** @var VisibleElementAssoc[] */
	private $elements = [];
	/** @var bool */
	private $signWithoutPassword = false;

	public function __construct(
		IL10N $l10n,
		FileMapper $fileMapper,
		FileUserMapper $fileUserMapper,
		AccountFileMapper $accountFileMapper,
		Pkcs7Handler $pkcs7Handler,
		Pkcs12Handler $pkcs12Handler,
		FolderService $folderService,
		IClientService $client,
		IUserManager $userManager,
		MailService $mail,
		LoggerInterface $logger,
		IConfig $config,
		ValidateHelper $validateHelper,
		IHasher $hasher,
		ISecureRandom $secureRandom,
		IAppManager $appManager,
		IAccountManager $accountManager,
		IServerContainer $serverContainer,
		IRootFolder $root,
		FileElementMapper $fileElementMapper,
		UserElementMapper $userElementMapper,
		FileElementService $fileElementService,
		TimeFactory $timeFactory,
		ITempManager $tempManager
	) {
		$this->l10n = $l10n;
		$this->fileMapper = $fileMapper;
		$this->fileUserMapper = $fileUserMapper;
		$this->accountFileMapper = $accountFileMapper;
		$this->pkcs7Handler = $pkcs7Handler;
		$this->pkcs12Handler = $pkcs12Handler;
		$this->folderService = $folderService;
		$this->client = $client;
		$this->userManager = $userManager;
		$this->mail = $mail;
		$this->logger = $logger;
		$this->config = $config;
		$this->validateHelper = $validateHelper;
		$this->hasher = $hasher;
		$this->secureRandom = $secureRandom;
		$this->appManager = $appManager;
		$this->accountManager = $accountManager;
		$this->serverContainer = $serverContainer;
		$this->root = $root;
		$this->fileElementMapper = $fileElementMapper;
		$this->userElementMapper = $userElementMapper;
		$this->fileElementService = $fileElementService;
		$this->timeFactory = $timeFactory;
		$this->tempManager = $tempManager;
	}

	/**
	 * @param array{callback: string, name: string, userManager: OCP\IUserManager} $data
	 */
	public function save(array $data): array {
		$file = $this->saveFile($data);
		$this->saveVisibleElements($data, $file);
		$return['uuid'] = $file->getUuid();
		$return['nodeId'] = $file->getNodeId();
		$return['users'] = $this->associateToUsers($data, $file->getId());
		return $return;
	}

	private function saveVisibleElements(array $data, FileEntity $file): array {
		if (empty($data['visibleElements'])) {
			return [];
		}
		$elements = $data['visibleElements'];
		foreach ($elements as $key => $element) {
			$element['fileId'] = $file->getId();
			$elements[$key] = $this->fileElementService->saveVisibleElement($element);
		}
		return $elements;
	}

	/**
	 * Save file data
	 *
	 *
	 * @param array{userManager: IUserManager, name: string, callback: string} $data
	 */
	public function saveFile(array $data): FileEntity {
		if (!empty($data['uuid'])) {
			return $this->fileMapper->getByUuid($data['uuid']);
		}
		if (!empty($data['file']['fileId'])) {
			try {
				$file = $this->fileMapper->getByFileId($data['file']['fileId']);
				if ($data['status'] && $data['status'] > $file->getStatus()) {
					$file->setStatus($data['status']);
					return $this->fileMapper->update($file);
				}
				return $file;
			} catch (\Throwable $th) {
			}
		}

		$node = $this->getNodeFromData($data);

		$file = new FileEntity();
		$file->setNodeId($node->getId());
		$file->setUserId($data['userManager']->getUID());
		$file->setUuid(UUIDUtil::getUUID());
		$file->setCreatedAt(time());
		$file->setName($data['name']);
		$file->setMetadata(json_encode($this->getFileMetadata($node)));
		if (!empty($data['callback'])) {
			$file->setCallback($data['callback']);
		}
		if (isset($data['status'])) {
			$file->setStatus($data['status']);
		} else {
			$file->setStatus(FileEntity::STATUS_ABLE_TO_SIGN);
		}
		$this->fileMapper->insert($file);
		return $file;
	}

	public function getFileMetadata(\OCP\Files\Node $node): array {
		$pdf = new TCPDILibresign();
		$pdf->setSourceData($node->getContent());
		return $pdf->getPagesMetadata();
	}

	public function saveFileUser(FileUserEntity $fileUser, bool $notifyAsNewUser = false): void {
		if ($fileUser->getId()) {
			$this->fileUserMapper->update($fileUser);
		} else {
			$this->fileUserMapper->insert($fileUser);
			$notifyAsNewUser = true;
		}
		if ($notifyAsNewUser) {
			$this->mail->notifyUnsignedUser($fileUser);
		} else {
			$this->mail->notifySignDataUpdated($fileUser);
		}
	}

	/**
	 * @return FileUserEntity[]
	 *
	 * @psalm-return list<FileUserEntity>
	 */
	private function associateToUsers(array $data, int $fileId): array {
		$return = [];
		if (!empty($data['users'])) {
			$notifyAsNewUser = false;
			if (isset($data['status']) && $data['status'] === FileEntity::STATUS_ABLE_TO_SIGN) {
				$notifyAsNewUser = true;
			}
			foreach ($data['users'] as $user) {
				$user['email'] = $this->getUserEmail($user);
				$fileUser = $this->getFileUser($user['email'], $fileId);
				$this->setDataToUser($fileUser, $user, $fileId);
				$this->saveFileUser($fileUser, $notifyAsNewUser);
				$return[] = $fileUser;
			}
		}
		return $return;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 */
	private function getFileUser(string $email, int $fileId): FileUserEntity {
		try {
			$fileUser = $this->fileUserMapper->getByEmailAndFileId($email, $fileId);
		} catch (DoesNotExistException $e) {
			$fileUser = new FileUserEntity();
		}
		return $fileUser;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedMethodCall
	 */
	private function getNodeFromData(array $data): \OCP\Files\Node {
		if (!$this->folderService->getUserId()) {
			$this->folderService->setUserId($data['userManager']->getUID());
		}
		if (isset($data['file']['fileId'])) {
			$userFolder = $this->folderService->getFolder($data['file']['fileId']);
			return $userFolder->getById($data['file']['fileId'])[0];
		}
		$userFolder = $this->folderService->getFolder();
		$folderName = $this->folderService->getFolderName($data, $data['userManager']);
		if ($userFolder->nodeExists($folderName)) {
			throw new \Exception($this->l10n->t('File already exists'));
		}
		$folderToFile = $userFolder->newFolder($folderName);
		return $folderToFile->newFile($data['name'] . '.pdf', $this->getFileRaw($data));
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedMethodCall
	 *
	 * @return false|resource|string
	 */
	private function getFileRaw(array $data) {
		if (!empty($data['file']['url'])) {
			if (!filter_var($data['file']['url'], FILTER_VALIDATE_URL)) {
				throw new \Exception($this->l10n->t('Invalid URL file'));
			}
			$response = $this->client->newClient()->get($data['file']['url']);
			$contentType = $response->getHeader('Content-Type');
			if ($contentType !== 'application/pdf') {
				throw new \Exception($this->l10n->t('The URL should be a PDF.'));
			}
			$content = $response->getBody();
			if (!$content) {
				throw new \Exception($this->l10n->t('Empty file'));
			}
		} else {
			$content = base64_decode($data['file']['base64']);
		}
		$this->validatePdfStringWithFpdi($content);
		return $content;
	}

	/**
	 * Validates a PDF. Triggers error if invalid.
	 *
	 * @param string $string
	 *
	 * @throws Type\PdfTypeException
	 */
	private function validatePdfStringWithFpdi($string): void {
		try {
			new TCPDF_PARSER($string);
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage());
			throw new \Exception($this->l10n->t('Invalid PDF'));
		}
	}

	/**
	 * @psalm-suppress MixedMethodCall
	 */
	private function setDataToUser(FileUserEntity $fileUser, array $user, int $fileId): void {
		$fileUser->setFileId($fileId);
		if (!$fileUser->getUuid()) {
			$fileUser->setUuid(UUIDUtil::getUUID());
		}
		$fileUser->setEmail($user['email']);
		if (!empty($user['description']) && $fileUser->getDescription() !== $user['description']) {
			$fileUser->setDescription($user['description']);
		}
		if (empty($user['uid'])) {
			$userToSign = $this->userManager->getByEmail($user['email']);
			if ($userToSign) {
				$fileUser->setUserId($userToSign[0]->getUID());
				if (empty($user['displayName'])) {
					$user['displayName'] = $userToSign[0]->getDisplayName();
				}
			}
		} else {
			$fileUser->setUserId($user['uid']);
		}
		if (!empty($user['displayName'])) {
			$fileUser->setDisplayName($user['displayName']);
		}
		if (!$fileUser->getId()) {
			$fileUser->setCreatedAt(time());
		}
	}

	public function validateNewRequestToFile(array $data): void {
		$this->validateUserManager($data);
		$this->validateNewFile($data);
		$this->validateUsers($data);
		$this->validateHelper->validateFileStatus($data);
	}

	public function validateUserManager(array $user): void {
		if (!isset($user['userManager'])) {
			throw new \Exception($this->l10n->t('You are not allowed to request signing'), Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$this->validateHelper->canRequestSign($user['userManager']);
	}

	public function validateNewFile(array $data): void {
		if (empty($data['name'])) {
			throw new \Exception($this->l10n->t('Name is mandatory'));
		}
		$this->validateHelper->validateNewFile($data);
	}

	public function validateExistingFile(array $data): void {
		if (isset($data['uuid'])) {
			$this->validateHelper->validateFileUuid($data);
			$file = $this->fileMapper->getByUuid($data['uuid']);
			$this->validateHelper->iRequestedSignThisFile($data['userManager'], $file->getNodeId());
		} elseif (isset($data['file'])) {
			if (!isset($data['file']['fileId'])) {
				throw new \Exception($this->l10n->t('Invalid fileID'));
			}
			$this->validateHelper->validateLibreSignNodeId($data['file']['fileId']);
			$this->validateHelper->iRequestedSignThisFile($data['userManager'], $data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}
	}

	public function validateUsers(array $data): void {
		if (empty($data['users'])) {
			throw new \Exception($this->l10n->t('Empty users list'));
		}
		if (!is_array($data['users'])) {
			throw new \Exception($this->l10n->t('User list needs to be an array'));
		}
		$emails = [];
		foreach ($data['users'] as $index => $user) {
			$this->validateHelper->haveValidMail($user);
			$emails[$index] = strtolower($this->getUserEmail($user));
		}
		$uniques = array_unique($emails);
		if (count($emails) > count($uniques)) {
			throw new \Exception($this->l10n->t('Remove duplicated users, email address need to be unique'));
		}
	}

	private function getUserEmail(array $user): ?string {
		if (!empty($user['email'])) {
			return strtolower($user['email']);
		}
		if (!empty($user['uid'])) {
			$user = $this->userManager->get($user['uid']);
			return $user->getEMailAddress();
		}
	}

	/**
	 * Can delete sing request
	 *
	 * @param array $data
	 */
	public function canDeleteSignRequest(array $data): void {
		if (!empty($data['uuid'])) {
			$signatures = $this->fileUserMapper->getByFileUuid($data['uuid']);
		} elseif (!empty($data['file']['fileId'])) {
			$signatures = $this->fileUserMapper->getByNodeId($data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}
		$signed = array_filter($signatures, fn ($s) => $s->getSigned());
		if ($signed) {
			throw new \Exception($this->l10n->t('Document already signed'));
		}
		array_walk($data['users'], function ($user) use ($signatures) {
			$exists = array_filter($signatures, fn ($s) => $s->getEmail() === $user['email']);
			if (!$exists) {
				throw new \Exception($this->l10n->t('No signature was requested to %s', $user['email']));
			}
		});
	}

	/**
	 * @deprecated 2.4.0
	 * @param array $data
	 * @return array
	 */
	public function deleteSignRequestDeprecated(array $data): array {
		$this->validateHelper->validateFileUuid($data);
		$this->validateUsers($data);
		$this->canDeleteSignRequest($data);

		if (!empty($data['uuid'])) {
			$signatures = $this->fileUserMapper->getByFileUuid($data['uuid']);
			$fileData = $this->fileMapper->getByUuid($data['uuid']);
		} elseif (!empty($data['file']['fileId'])) {
			$signatures = $this->fileUserMapper->getByNodeId($data['file']['fileId']);
			$fileData = $this->fileMapper->getByFileId($data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}

		$deletedUsers = [];
		foreach ($data['users'] as $signer) {
			try {
				$fileUser = $this->fileUserMapper->getByEmailAndFileId(
					$signer['email'],
					$fileData->getId()
				);
				$deletedUsers[] = $fileUser;
				$this->fileUserMapper->delete($fileUser);
			} catch (\Throwable $th) {
				// already deleted
			}
		}
		if ((empty($data['users']) && !count($signatures)) || count($signatures) === count($data['users'])) {
			$this->fileMapper->delete($fileData);
		}
		return $deletedUsers;
	}

	/**
	 * @param array $data
	 * @return void
	 */
	public function deleteSignRequest(array $data): void {
		if (!empty($data['uuid'])) {
			$signatures = $this->fileUserMapper->getByFileUuid($data['uuid']);
			$fileData = $this->fileMapper->getByUuid($data['uuid']);
		} elseif (!empty($data['file']['fileId'])) {
			$signatures = $this->fileUserMapper->getByNodeId($data['file']['fileId']);
			$fileData = $this->fileMapper->getByFileId($data['file']['fileId']);
		} else {
			throw new \Exception($this->l10n->t('Inform or UUID or a File object'));
		}
		foreach ($signatures as $fileUser) {
			$this->fileUserMapper->delete($fileUser);
		}
		$this->fileMapper->delete($fileData);
		$this->fileElementService->deleteVisibleElements($fileData->getId());
	}

	public function unassociateToUser(int $fileId, int $fileUserId): void {
		$fileUser = $this->fileUserMapper->getByFileIdAndFileUserId($fileId, $fileUserId);
		$this->fileUserMapper->delete($fileUser);
		try {
			$visibleElements = $this->fileElementMapper->getByFileIdAndFileUserId($fileId, $fileUserId);
			foreach ($visibleElements as $visibleElement) {
				$this->fileElementMapper->delete($visibleElement);
			}
		} catch (\Throwable $th) {
		}
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress MixedMethodCall
	 */
	public function notifyCallback(string $uri, string $uuid, File $file): IResponse {
		$options = [
			'multipart' => [
				[
					'name' => 'uuid',
					'contents' => $uuid
				],
				[
					'name' => 'file',
					'contents' => $file->fopen('r'),
					'filename' => $file->getName()
				]
			]
		];
		return $this->client->newClient()->post($uri, $options);
	}

	public function setLibreSignFile(FileEntity $libreSignFile): self {
		$this->libreSignFile = $libreSignFile;
		return $this;
	}

	public function setFileUser(FileUserEntity $fileUser): self {
		$this->fileUser = $fileUser;
		return $this;
	}

	public function setSignWithoutPassword(bool $signWithoutPassword): self {
		$this->signWithoutPassword = $signWithoutPassword;
		return $this;
	}

	public function setPassword(?string $password = null): self {
		$this->password = $password;
		return $this;
	}

	public function setVisibleElements(array $list): self {
		$fileElements = $this->fileElementMapper->getByFileIdAndUserId($this->fileUser->getFileId(), $this->fileUser->getUserId());
		foreach ($fileElements as $fileElement) {
			$element = array_filter($list, function (array $element) use ($fileElement): bool {
				return $element['documentElementId'] === $fileElement->getId();
			});
			if ($element) {
				$c = current($element);
				$userElement = $this->userElementMapper->findOne(['id' => $c['profileElementId']]);
			} else {
				$userElement = $this->userElementMapper->findOne([
					'user_id' => $this->fileUser->getUserId(),
					'type' => $fileElement->getType(),
				]);
			}
			try {
				$node = $this->root->getById($userElement->getFileId())[0];
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('You need to define a visible signature or initials to sign this document.'));
			}
			$tempFile = $this->tempManager->getTemporaryFile('.png');
			file_put_contents($tempFile, $node->getContent());
			$visibleElements = new VisibleElementAssoc(
				$fileElement,
				$userElement,
				$tempFile
			);
			$this->elements[] = $visibleElements;
		}
		return $this;
	}

	public function sign(): \OCP\Files\Node {
		$fileToSign = $this->getFileToSing($this->libreSignFile);
		$pfxFile = $this->getPfxFile();
		switch ($fileToSign->getExtension()) {
			case 'pdf':
				$signedFile = $this->pkcs12Handler
					->setInputFile($fileToSign)
					->setCertificate($pfxFile)
					->setVisibleElements($this->elements)
					->setPassword($this->password)
					->sign();
				break;
			default:
				$signedFile = $this->pkcs7Handler
					->setInputFile($fileToSign)
					->setCertificate($pfxFile)
					->setPassword($this->password)
					->sign();
		}

		$this->fileUser->setSigned(time());
		if ($this->fileUser->getId()) {
			$this->fileUserMapper->update($this->fileUser);
		} else {
			$this->fileUserMapper->insert($this->fileUser);
		}
		$this->libreSignFile->setSignedNodeId($signedFile->getId());
		$this->fileMapper->update($this->libreSignFile);

		return $signedFile;
	}

	private function getPfxFile(): \OCP\Files\Node {
		if ($this->signWithoutPassword) {
			$tempPassword = sha1(time());
			$this->setPassword($tempPassword);
			return $this->pkcs12Handler->generateCertificate(
				$this->fileUser->getEmail(),
				$tempPassword,
				$this->fileUser->getUserId(),
				true
			);
		}
		return $this->pkcs12Handler->getPfx($this->fileUser->getUserId());
	}

	/**
	 * Get file to sign
	 *
	 * @throws LibresignException
	 * @param FileEntity $libresignFile
	 * @return \OCP\Files\Node
	 */
	public function getFileToSing(FileEntity $libresignFile): \OCP\Files\Node {
		$userFolder = $this->root->getUserFolder($libresignFile->getUserId());
		$originalFile = $userFolder->getById($libresignFile->getNodeId());
		if (count($originalFile) < 1) {
			throw new LibresignException($this->l10n->t('File not found'));
		}
		$originalFile = $originalFile[0];
		if ($originalFile->getExtension() === 'pdf') {
			return $this->getPdfToSign($libresignFile, $originalFile);
		}
		return $userFolder->get($originalFile);
	}

	public function getLibresignFile(?int $fileId, ?string $fileUserUuid): FileEntity {
		try {
			if ($fileId) {
				$libresignFile = $this->fileMapper->getByFileId($fileId);
			} elseif ($fileUserUuid) {
				$fileUser = $this->fileUserMapper->getByUuid($fileUserUuid);
				$libresignFile = $this->fileMapper->getById($fileUser->getFileId());
			} else {
				throw new \Exception('Invalid arguments');
			}
		} catch (DoesNotExistException $th) {
			throw new LibresignException($this->l10n->t('File not found'), 1);
		}
		return $libresignFile;
	}

	public function requestCode(FileUserEntity $fileUser, IUser $user): int {
		$token = $this->secureRandom->generate(6, ISecureRandom::CHAR_DIGITS);
		$this->sendCode($user, $fileUser, $token);
		$fileUser->setCode($this->hasher->hash($token));
		$this->fileUserMapper->update($fileUser);
		return $token;
	}

	private function sendCode(IUser $user, FileUserEntity $fileUser, string $code) {
		$signMethod = $this->config->getAppValue(Application::APP_ID, 'sign_method', 'password');
		switch ($signMethod) {
			case 'sms':
			case 'telegram':
			case 'signal':
				$this->sendCodeByGateway($user, $code, $signMethod);
				break;
			case 'email':
				$this->sendCodeByEmail($fileUser, $code);
				break;
			case 'password':
				throw new LibresignException($this->l10n->t('Sending authorization code not enabled.'));
		}
	}

	private function sendCodeByGateway(IUser $user, string $code, string $gatewayName) {
		$gateway = $this->getGateway($user, $gatewayName);
		
		$userAccount = $this->accountManager->getAccount($user);
		$identifier = $userAccount->getProperty(IAccountManager::PROPERTY_PHONE)->getValue();
		$gateway->send($user, $identifier, $this->l10n->t('%s is your LibreSign verification code.', $code));
	}

	/**
	 * @throws OCSForbiddenException
	 * @return \OCA\TwoFactorGateway\Service\Gateway\IGateway
	 */
	private function getGateway(IUser $user, string $gatewayName) {
		if (!$this->appManager->isEnabledForUser('twofactor_gateway', $user)) {
			throw new OCSForbiddenException($this->l10n->t('Authorize signing using %s token is disabled because Nextcloud Two-Factor Gateway is not enabled.', $gatewayName));
		}
		$factory = $this->serverContainer->get('\OCA\TwoFactorGateway\Service\Gateway\Factory');
		$gateway = $factory->getGateway($gatewayName);
		if (!$gateway->getConfig()->isComplete()) {
			throw new OCSForbiddenException($this->l10n->t('Gateway %s not configured on Two-Factor Gateway.', $gatewayName));
		}
		return $gateway;
	}

	private function sendCodeByEmail(FileUserEntity $fileUser, string $code) {
		$this->mail->sendCodeToSign($fileUser, $code);
	}

	public function getFileUserToSign(FileEntity $libresignFile, IUser $user): FileUserEntity {
		$this->validateHelper->fileCanBeSigned($libresignFile);
		try {
			$fileUser = $this->fileUserMapper->getByFileIdAndUserId($libresignFile->getNodeId(), $user->getUID());
			if ($fileUser->getSigned()) {
				throw new LibresignException($this->l10n->t('File already signed by you'), 1);
			}
		} catch (DoesNotExistException $th) {
			try {
				$accountFile = $this->accountFileMapper->getByFileId($libresignFile->getId());
			} catch (\Throwable $th) {
				throw new LibresignException($this->l10n->t('Invalid data to sign file'), 1);
			}
			$this->validateHelper->userCanApproveValidationDocuments($user);
			$fileUser = new FileUserEntity();
			$fileUser->setFileId($libresignFile->getId());
			$fileUser->setEmail($user->getEMailAddress());
			$fileUser->setDisplayName($user->getDisplayName());
			$fileUser->setUserId($user->getUID());
			$fileUser->setUuid(UUIDUtil::getUUID());
			$fileUser->setCreatedAt(time());
		}
		return $fileUser;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress MixedMethodCall
	 *
	 * @return File
	 */
	private function getPdfToSign(FileEntity $fileData, File $originalFile): File {
		if ($fileData->getSignedNodeId()) {
			/** @var \OCP\Files\File */
			$fileToSign = $this->root->getById($fileData->getSignedNodeId())[0];
		} else {
			$signedFilePath = preg_replace(
				'/' . $originalFile->getExtension() . '$/',
				$this->l10n->t('signed') . '.' . $originalFile->getExtension(),
				$originalFile->getPath()
			);

			/** @var \OCP\Files\File */
			$buffer = $this->pkcs12Handler->writeFooter($originalFile, $fileData->getUuid());
			if (!$buffer) {
				$buffer = $originalFile->getContent($originalFile);
			}
			/** @var \OCP\Files\File */
			$fileToSign = $this->root->newFile($signedFilePath);
			$fileToSign->putContent($buffer);
		}
		return $fileToSign;
	}
}
