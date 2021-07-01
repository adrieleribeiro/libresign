<?php

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\File as FileEntity;
use OCA\Libresign\Db\FileMapper;
use OCA\Libresign\Db\FileUser as FileUserEntity;
use OCA\Libresign\Db\FileUserMapper;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Handler\Pkcs7Handler;
use OCA\Libresign\Handler\Pkcs12Handler;
use OCA\Libresign\Helper\ValidateHelper;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\UUIDUtil;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;

class SignFileService {
	/** @var FileEntity */
	private $file;
	/** @var IConfig */
	private $config;
	/** @var IL10N */
	private $l10n;
	/** @var FileMapper */
	private $fileMapper;
	/** @var FileUserMapper */
	private $fileUserMapper;
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
	/** @var ValidateHelper */
	private $validateHelper;
	/** @var IRootFolder */
	private $root;

	public function __construct(
		IConfig $config,
		IL10N $l10n,
		FileMapper $fileMapper,
		FileUserMapper $fileUserMapper,
		Pkcs7Handler $pkcs7Handler,
		Pkcs12Handler $pkcs12Handler,
		FolderService $folderService,
		IClientService $client,
		IUserManager $userManager,
		MailService $mail,
		LoggerInterface $logger,
		ValidateHelper $validateHelper,
		IRootFolder $root
	) {
		$this->config = $config;
		$this->l10n = $l10n;
		$this->fileMapper = $fileMapper;
		$this->fileUserMapper = $fileUserMapper;
		$this->pkcs7Handler = $pkcs7Handler;
		$this->pkcs12Handler = $pkcs12Handler;
		$this->folderService = $folderService;
		$this->client = $client;
		$this->userManager = $userManager;
		$this->mail = $mail;
		$this->logger = $logger;
		$this->validateHelper = $validateHelper;
		$this->root = $root;
	}

	public function save(array $data) {
		if (!empty($data['uuid'])) {
			$file = $this->getFileByUuid($data['uuid']);
		} else {
			$file = $this->saveFile($data);
		}
		$return['uuid'] = $file->getUuid();
		$return['nodeId'] = $file->getNodeId();
		$return['users'] = $this->associateToUsers($data, $file->getId());
		return $return;
	}

	/**
	 * Save file data
	 *
	 * @param array $data
	 * @return FileEntity
	 */
	public function saveFile(array $data): FileEntity {
		$node = $this->getNodeFromData($data);

		$file = new FileEntity();
		$file->setNodeId($node->getId());
		$file->setUserId($data['userManager']->getUID());
		$file->setUuid(UUIDUtil::getUUID());
		$file->setCreatedAt(time());
		$file->setName($data['name']);
		if (!empty($data['callback'])) {
			$file->setCallback($data['callback']);
		}
		$file->setEnabled(1);
		$this->fileMapper->insert($file);
		return $file;
	}

	public function saveFileUser(FileUserEntity $fileUser) {
		if ($fileUser->getId()) {
			$this->fileUserMapper->update($fileUser);
			$this->mail->notifySignDataUpdated($fileUser);
		} else {
			$this->fileUserMapper->insert($fileUser);
			$this->mail->notifyUnsignedUser($fileUser);
		}
	}

	private function associateToUsers(array $data, int $fileId): array {
		$return = [];
		if (!empty($data['users'])) {
			foreach ($data['users'] as $user) {
				$user['email'] = strtolower($user['email']);
				$fileUser = $this->getFileUser($user['email'], $fileId);
				$this->setDataToUser($fileUser, $user, $fileId);
				$this->saveFileUser($fileUser);
				$return[] = $fileUser;
			}
		}
		return $return;
	}

	/**
	 * Get LibreSign file entity by UUID
	 *
	 * @param string $uuid
	 * @return FileEntity
	 */
	private function getFileByUuid(string $uuid): FileEntity {
		if (!$this->file || $this->file->getUuid() !== $uuid) {
			$this->file = $this->fileMapper->getByUuid($uuid);
		}
		return $this->file;
	}

	private function getFileUser(string $email, int $fileId): FileUserEntity {
		try {
			$fileUser = $this->fileUserMapper->getByEmailAndFileId($email, $fileId);
		} catch (\Throwable $th) {
			$fileUser = new FileUserEntity();
		}
		return $fileUser;
	}

	private function getNodeFromData(array $data): \OCP\Files\Node {
		if (!$this->folderService->getUserId()) {
			$this->folderService->setUserId($data['userManager']->getUID());
		}
		if (isset($data['file']['fileId'])) {
			$userFolder = $this->folderService->getFolder($data['file']['fileId']);
			return $userFolder->getById($data['file']['fileId'])[0];
		}
		$userFolder = $this->folderService->getFolder();
		$folderName = $this->getFolderName($data);
		if ($userFolder->nodeExists($folderName)) {
			throw new \Exception($this->l10n->t('File already exists'));
		}
		$folderToFile = $userFolder->newFolder($folderName);
		return $folderToFile->newFile($data['name'] . '.pdf', $this->getFileRaw($data));
	}

	private function getFileRaw($data) {
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
	 * @throws CrossReferenceException
	 * @throws PdfParserException
	 */
	private function validatePdfStringWithFpdi($string) {
		$pdf = new Fpdi();
		try {
			$stream = fopen('php://memory','r+');
			fwrite($stream, $string);
			rewind($stream);
			$pdf->setSourceFile($stream);
		} catch (\Throwable $th) {
			$this->logger->error($th->getMessage());
			throw new \Exception($this->l10n->t('Invalid PDF'));
		}
	}

	private function getFolderName(array $data) {
		if (!isset($data['settings']['folderPatterns'])) {
			$data['settings']['separator'] = '_';
			$data['settings']['folderPatterns'][] = [
				'name' => 'date',
				'setting' => 'Y-m-d\TH:i:s'
			];
			$data['settings']['folderPatterns'][] = [
				'name' => 'name'
			];
			$data['settings']['folderPatterns'][] = [
				'name' => 'userId'
			];
		}
		foreach ($data['settings']['folderPatterns'] as $pattern) {
			switch ($pattern['name']) {
				case 'date':
					$folderName[] = (new \DateTime('NOW'))->format($pattern['setting']);
					break;
				case 'name':
					if (!empty($data['name'])) {
						$folderName[] = $data['name'];
					}
					break;
				case 'userId':
					$folderName[] = $data['userManager']->getUID();
					break;
			}
		}
		return implode($data['settings']['separator'], $folderName);
	}

	private function setDataToUser(FileUserEntity $fileUser, array $user, $fileId) {
		$fileUser->setFileId($fileId);
		if (!$fileUser->getUuid()) {
			$fileUser->setUuid(UUIDUtil::getUUID());
		}
		$fileUser->setEmail($user['email']);
		if (!empty($user['description']) && $fileUser->getDescription() !== $user['description']) {
			$fileUser->setDescription($user['description']);
		}
		if (empty($user['user_id'])) {
			$userToSign = $this->userManager->getByEmail($user['email']);
			if ($userToSign) {
				$fileUser->setUserId($userToSign[0]->getUID());
				if (empty($user['displayName'])) {
					$user['displayName'] = $userToSign[0]->getDisplayName();
				}
			}
		}
		if (!empty($user['displayName'])) {
			$fileUser->setDisplayName($user['displayName']);
		}
		if (!$fileUser->getId()) {
			$fileUser->setCreatedAt(time());
		}
	}

	public function validate(array $data) {
		$this->validateUserManager($data);
		$this->validateFile($data);
		$this->validateUsers($data);
	}

	public function validateUserManager($user) {
		if (!isset($user['userManager'])) {
			throw new \Exception($this->l10n->t('You are not allowed to request signing'), Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$this->validateHelper->canRequestSign($user['userManager']);
	}

	public function validateFile(array $data) {
		if (empty($data['name'])) {
			throw new \Exception($this->l10n->t('Name is mandatory'));
		}
		$this->validateHelper->validateFile($data);
	}

	public function validateFileUuid(array $data) {
		try {
			$this->getFileByUuid($data['uuid']);
		} catch (\Throwable $th) {
			throw new \Exception($this->l10n->t('Invalid UUID file'));
		}
	}

	public function validateUsers(array $data) {
		if (empty($data['users'])) {
			throw new \Exception($this->l10n->t('Empty users list'));
		}
		if (!is_array($data['users'])) {
			throw new \Exception($this->l10n->t('User list needs to be an array'));
		}
		$emails = [];
		foreach ($data['users'] as $index => $user) {
			$this->validateHelper->haveValidMail($user);
			$emails[$index] = strtolower($user['email']);
		}
		$uniques = array_unique($emails);
		if (count($emails) > count($uniques)) {
			throw new \Exception($this->l10n->t('Remove duplicated users, email address need to be unique'));
		}
	}

	/**
	 * Can delete sing request
	 *
	 * @param array $data
	 */
	public function canDeleteSignRequest(array $data) {
		$signatures = $this->fileUserMapper->getByFileUuid($data['uuid']);
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

	public function deleteSignRequest(array $data): array {
		$signatures = $this->fileUserMapper->getByFileUuid($data['uuid']);
		$fileData = $this->getFileByUuid($data['uuid']);
		$deletedUsers = [];
		foreach ($data['users'] as $key => $signer) {
			try {
				$fileUser = $this->fileUserMapper->getByEmailAndFileId(
					$signer['email'],
					$fileData->getId()
				);
				$this->fileUserMapper->delete($fileUser);
				$deletedUsers[] = $fileUser;
			} catch (\Throwable $th) {
				// already deleted
			}
		}
		if ((empty($data['users']) && !count($signatures)) || count($signatures) === count($data['users'])) {
			$file = $this->getFileByUuid($data['uuid']);
			$this->fileMapper->delete($file);
		}
		return $deletedUsers;
	}

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

	public function sign(FileEntity $libreSignFile, FileUserEntity $fileUser, string $password): \OCP\Files\File {
		$fileToSign = $this->getFileToSing($libreSignFile);
		$pfxFile = $this->pkcs12Handler->getPfx($fileUser->getUserId());
		switch ($fileToSign->getExtension()) {
			case 'pdf':
				$signedFile = $this->pkcs12Handler->sign($fileToSign, $pfxFile, $password);
				break;
			default:
				$signedFile = $this->pkcs7Handler->sign($fileToSign, $pfxFile, $password);
		}

		$fileUser->setSigned(time());
		$this->fileUserMapper->update($fileUser);

		return $signedFile;
	}

	public function writeFooter(File $file, string $uuid) {
		$validation_site = $this->config->getAppValue(Application::APP_ID, 'validation_site');
		if (!$validation_site) {
			return;
		}
		$validation_site = rtrim($validation_site, '/').'/'.$uuid;
		$pdf = new Fpdi();
		$pageCount = $pdf->setSourceFile($file->fopen('r'));

		for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
			$templateId = $pdf->importPage($pageNo);

			$pdf->AddPage();
			$pdf->useTemplate($templateId, ['adjustPageSize' => true]);

			$pdf->SetFont('Helvetica');
			$pdf->SetFontSize(8);
			$pdf->SetAutoPageBreak(false);
			$pdf->SetXY(5, -10);

			$pdf->Write(8, iconv('UTF-8', 'windows-1252', $this->l10n->t(
				'Digital signed by LibreSign. Validate in %s',
				$validation_site
			)));
		}

		return $pdf->Output('S');
	}

	/**
	 * Get file to sign
	 *
	 * @throws LibresignException
	 * @param FileEntity $fileData
	 * @return \OCP\Files\File
	 */
	public function getFileToSing(FileEntity $fileData): \OCP\Files\File {
		$userFolder = $this->root->getUserFolder($fileData->getUserId());
		$originalFile = $userFolder->getById($fileData->getNodeId());
		if (count($originalFile) < 1) {
			throw new LibresignException($this->l10n->t('File not found'));
		}
		$originalFile = $originalFile[0];
		if ($originalFile->getExtension() === 'pdf') {
			return $this->getPdfToSign($fileData, $originalFile);
		}
		return $userFolder->get($originalFile);
	}

	private function getPdfToSign(FileEntity $fileData, File $originalFile): \OCP\Files\File {
		$signedFilePath = preg_replace(
			'/' . $originalFile->getExtension() . '$/',
			$this->l10n->t('signed') . '.' . $originalFile->getExtension(),
			$originalFile->getPath()
		);

		if ($this->root->nodeExists($signedFilePath)) {
			/** @var \OCP\Files\File */
			$fileToSign = $this->root->get($signedFilePath);
		} else {
			/** @var \OCP\Files\File */
			$buffer = $this->writeFooter($originalFile, $fileData->getUuid());
			if (!$buffer) {
				$buffer = $originalFile->getContent($originalFile);
			}
			$fileToSign = $this->root->newFile($signedFilePath);
			$fileToSign->putContent($buffer);
		}
		return $fileToSign;
	}
}
