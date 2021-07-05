<?php

namespace OCA\Libresign\Controller;

use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileUserMapper;
use OCA\Libresign\Service\MailService;
use OCA\Libresign\Service\SignFileService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * @deprecated 2.4.0
 */
class SignFileDeprecatedController extends SignFileController {
	/** @var IL10N */
	protected $l10n;
	/** @var IUserSession */
	private $userSession;
	/** @var FileUserMapper */
	private $fileUserMapper;
	/** @var FileMapper */
	private $fileMapper;
	/** @var SignFileService */
	protected $signFile;
	/** @var MailService */
	private $mail;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IRequest $request,
		IL10N $l10n,
		FileUserMapper $fileUserMapper,
		FileMapper $fileMapper,
		IUserSession $userSession,
		SignFileService $signFile,
		MailService $mail,
		LoggerInterface $logger
	) {
		$this->l10n = $l10n;
		$this->fileUserMapper = $fileUserMapper;
		$this->fileMapper = $fileMapper;
		$this->userSession = $userSession;
		$this->signFile = $signFile;
		$this->mail = $mail;
		$this->logger = $logger;
		parent::__construct(
			$request,
			$this->l10n,
			$this->fileUserMapper,
			$this->fileMapper,
			$this->userSession,
			$this->signFile,
			$this->logger
		);
	}

	/**
	 * @inheritDoc
	 */
	public function requestSign(array $file, array $users, string $name, ?string $callback = null) {
		return parent::requestSign($file, $users, $name, $callback);
	}

	/**
	 * @inheritDoc
	 */
	public function updateSign(array $users, ?string $uuid = null, ?array $file = []) {
		return parent::updateSign($users, $uuid, $file);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $uuid
	 * @param array $users
	 * @return JSONResponse
	 */
	public function removeSign(array $users, ?string $uuid = null, ?array $file = []) {
		$user = $this->userSession->getUser();
		$data = [
			'uuid' => $uuid,
			'users' => $users,
			'userManager' => $user
		];
		try {
			$this->signFile->validateUserManager($data);
			$this->signFile->validateExistingFile($data);
			$deletedUsers = $this->signFile->deleteSignRequest($data);
			foreach ($deletedUsers as $user) {
				$this->mail->notifyUnsignedUser($user);
			}
		} catch (\Throwable $th) {
			$message = $th->getMessage();
			return new JSONResponse(
				[
					'message' => $message,
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Success')
			],
			Http::STATUS_OK
		);
	}
}
