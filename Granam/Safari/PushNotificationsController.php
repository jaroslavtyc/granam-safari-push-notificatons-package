<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\Safari;

use Granam\Strict\Object\StrictObject;

/**
 * This is mostly just a syntax sugar of original https://github.com/connorlacombe/Safari-Push-Notifications/
 */
abstract class PushNotificationsController extends StrictObject
{
    /**
     * @var PushPackage
     */
    private $pushPackage;

    public function __construct(PushPackage $pushPackage)
    {
        $this->pushPackage = $pushPackage;
    }

    /**
     * The URL format should be {webServiceURL}/{version}/pushPackages/{websitePushID}
     *
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW24
     *
     * @return bool
     * @throws \Granam\Safari\Exceptions\CanNotCreateTemporaryPackageDir
     * @throws \Granam\Safari\Exceptions\CanNotEncodeWebsiteToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveWebsiteJsonToPackage
     * @throws \Granam\Safari\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCloseZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Safari\Exceptions\CanNotCopyIcon
     * @throws \Granam\Safari\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Safari\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveManifestJsonFile
     * @throws \Granam\Safari\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Safari\Exceptions\CanNotReadCertificateData
     * @throws \Granam\Safari\Exceptions\CanNotGetResourceFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotSignManifest
     * @throws \Granam\Safari\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Safari\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Safari\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Safari\Exceptions\CanNotSaveDerSignatureToFile
     * @throws \Granam\Safari\Exceptions\CanNotReadZipPackage
     */
    public function pushPackages(): bool
    {
        $this->sendAlreadyExpiredHeaders();
        $path = $_SERVER['PATH_INFO'] ?? '';
        if (!\preg_match('~^.*/v(?<version>\d+)/pushPackages/(?<websitePushId>[^/]+)~', $path, $matches)) {
            \header('HTTP/1.0 400 Bad Request');

            return false;
        }
        if (!$this->isWebsitePushIdMatching($matches['websitePushId'])) {
            \header('HTTP/1.1 403 Forbidden Invalid Parameter websitePushId');

            return false;
        }
        $contents = \file_get_contents('php://input');
        if (!$contents) {
            \header('HTTP/1.0 400 Bad Request Missing Parameters');

            return false;
        }
        $contents = \json_decode($contents, true /* to get associative array */);
        $userId = (string)($contents['userId'] ?? '');
        if ($userId === '') {
            \header('HTTP/1.0 400 Bad Request Missing Parameter userId');

            return false;
        }
        $zipPackage = $this->pushPackage->createPushPackage($userId);
        \header('Content-type: application/zip');
        if (!readfile($zipPackage)) {
            throw new Exceptions\CanNotReadZipPackage("ZIPed package {$zipPackage} can not be read");
        }

        return true;
    }

    private function sendAlreadyExpiredHeaders()
    {
        \header('Cache-Control: no-cache, must-revalidate');
        \header('Expires: Thu, 01 Jan 1970 00:00:01 +0000');
    }

    private function isWebsitePushIdMatching(string $websitePushId): bool
    {
        return $websitePushId === $this->pushPackage->getWebsitePushId();
    }

    /**
     * This covers both adding a new device as well as deleting old one, depending on request method (POST or DELETE).
     * The URL format should be {webServiceURL}/{version}/devices/{deviceToken}/registrations/{websitePushID}
     *
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW24
     * @return bool
     */
    public function devicesRegistrations(): bool
    {
        /** this is the authenticationToken key we packaged in the website.json pushPackage, @see \Granam\Safari\PushPackage::getWebsiteJsonContent */
        $userId = $this->pushPackage->parseUserId($_SERVER['HTTP_AUTHORIZATION'] ?? ''); // need to be parsed as it could be encoded due to Apple length requirement
        if ($userId === '') {
            \header('HTTP/1.1 401 Unauthorized');

            return false;
        }
        $path = $_SERVER['PATH_INFO'] ?? '';
        if ($path === ''
            || !\preg_match('~^.*/v(?<version>\d+)/devices/(?<deviceToken>[^/]+)/registrations/(?<websitePushId>[^/]+)~', $path, $matches)
        ) {
            \header('HTTP/1.0 400 Bad Request');

            return false;
        }
        if (!$this->isWebsitePushIdMatching($matches['websitePushId'])) {
            \header('HTTP/1.1 403 Forbidden Invalid parameter websitePushId');

            return false;
        }
        $deviceToken = $matches['deviceToken'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->addDevice($userId, $deviceToken);
        }
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            return $this->deleteDevice($userId, $deviceToken);
        }
        \header('HTTP/1.0 400 Bad Request');

        return false;
    }

    abstract protected function addDevice(string $userId, string $deviceToken): bool;

    abstract protected function deleteDevice(string $userId, string $deviceToken): bool;

    /**
     * To receive reported errors from Apple.
     * The URL format should be {webServiceURL}/{version}/log and errors should be reported by POST method
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW27
     * @return bool
     */
    public function log(): bool
    {
        $this->sendAlreadyExpiredHeaders();
        $contents = \file_get_contents('php://input');
        if (!$contents) {
            \header('HTTP/1.0 400 Bad Request Missing Content');

            return false;
        }
        $decoded = \json_decode($contents, true /* to get associative array */);
        if (!\array_key_exists('log', $decoded)) {
            \header('HTTP/1.0 400 Bad Request Missing log');

            return false;
        }

        return $this->processErrorLog((array)$decoded['log']);
    }

    abstract protected function processErrorLog(array $log): bool;

    /**
     * @link https://developer.apple.com/library/content/documentation/NetworkingInternet/Conceptual/NotificationProgrammingGuideForWebsites/PushNotifications/PushNotifications.html#//apple_ref/doc/uid/TP40013225-CH3-SW12
     * @throws \Granam\Safari\Exceptions\CanNotEncodePushPayloadToJson
     */
    public function pushNotification(): bool
    {
        $this->sendAlreadyExpiredHeaders();
        $title = \trim($_POST['title'] ?? $_GET['title'] ?? '');
        if ($title === '') {
            \header('HTTP/1.0 400 Bad Request Missing Title');
            echo 'Missing "title"';

            return false;
        }
        $body = \trim($_POST['body'] ?? $_GET['body'] ?? '');
        if ($body === '') {
            \header('HTTP/1.0 400 Bad Request Missing Body');
            echo 'Missing "body"';

            return false;
        }
        $userId = \trim($_POST['user-id'] ?? $_GET['user-id'] ?? '');
        if ($userId === '') {
            \header('HTTP/1.0 400 Bad Request Missing user-id');
            echo 'Missing "user-id"';

            return false;
        }
        $urlArguments = $_POST['arguments'] ?? $_GET['arguments'] ?? [];
        if (\is_string($urlArguments)) {
            $urlArguments = explode(',', $urlArguments);
        }
        if (\count($urlArguments) !== $this->pushPackage->getCountOfExpectedArguments()) {
            \header('HTTP/1.0 400 Bad Request Invalid Number Of Arguments');
            echo 'Invalid number of arguments, expected ' . $this->pushPackage->getCountOfExpectedArguments()
                . ' of them, got ' . var_export($urlArguments, true);

            return false;
        }
        $buttonText = \trim($_POST['button-text'] ?? $_GET['button-text'] ?? ''); // if empty then MacOS will use default one (View)
        $deviceToken = $this->getDeviceToken($userId);
        if (!$deviceToken) {
            \header('HTTP/1.0 404 Not Found Device by Given User Authentication Token');
            echo 'No device has been found by give user authentication token';

            return false;
        }
        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body,
                    'action' => $buttonText
                ]
            ],
            'url-args' => $urlArguments
        ];
        $jsonPayload = \json_encode($payload);
        if (!$jsonPayload) {
            throw new Exceptions\CanNotEncodePushPayloadToJson(
                'Can not encode to JSON a payload ' . \var_export($payload, true)
            );
        }
        if (\strlen($jsonPayload) > 256) {
            if ($userId === '') {
                \header('HTTP/1.0 400 Bad Request Payload To Sent Is Too Long');
                echo 'Final push notification payload to send is longer than allowed 256 bytes with length of '
                    . \strlen($jsonPayload) . ' bytes';

                return false;
            }
        }

        return $this->sendPushNotification($jsonPayload, \str_replace(' ', '', $deviceToken));
    }

    /**
     * @param string $userId
     * @return string Empty string of no matching device token has been found
     */
    abstract protected function getDeviceToken(string $userId): string;

    /**
     * @param string $jsonPayload
     * @param string $deviceToken
     * @return bool True on success, False on failure (for asynchronous requests just return True).
     */
    abstract protected function sendPushNotification(string $jsonPayload, string $deviceToken): bool;

    /**
     * @param string|null $path
     * @return bool
     */
    public function isAppleAction(string $path = null): bool
    {
        $path = $path ?? $_SERVER['PATH_INFO'] ?? '';
        if ($path === '' || !\preg_match('~^/v\d+/(?<action>[^/]+)/([^/]+/(?<subAction>))?~', $path, $matches)) {
            return false;
        }
        switch ($matches['action']) {
            case 'pushPackages' :
                return true;
            case 'devices' :
                return ($matches['subAction'] ?? '') === 'registrations';
            case 'log' :
                return true;
            default :
                return false;
        }
    }

    /**
     * @param string|null $path
     * @return bool
     * @throws \Granam\Safari\Exceptions\UnknownActionToDo
     * @throws \Granam\Safari\Exceptions\CanNotCreateTemporaryPackageDir
     * @throws \Granam\Safari\Exceptions\CanNotEncodeWebsiteToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveWebsiteJsonToPackage
     * @throws \Granam\Safari\Exceptions\CanNotCreateZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotAddFileToZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCloseZipArchive
     * @throws \Granam\Safari\Exceptions\CanNotCreateDirForIconSet
     * @throws \Granam\Safari\Exceptions\CanNotCopyIcon
     * @throws \Granam\Safari\Exceptions\CanNotCalculateSha1FromFile
     * @throws \Granam\Safari\Exceptions\CanNotEncodeManifestDataToJson
     * @throws \Granam\Safari\Exceptions\CanNotSaveManifestJsonFile
     * @throws \Granam\Safari\Exceptions\CanNotGetCertificateContent
     * @throws \Granam\Safari\Exceptions\CanNotReadCertificateData
     * @throws \Granam\Safari\Exceptions\CanNotGetResourceFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotGetPrivateKeyFromOpenedCertificate
     * @throws \Granam\Safari\Exceptions\CanNotSignManifest
     * @throws \Granam\Safari\Exceptions\CanNotReadPemSignatureFromFile
     * @throws \Granam\Safari\Exceptions\UnexpectedContentOfPemSignature
     * @throws \Granam\Safari\Exceptions\CanNotCreateDerSignatureByDecodingToBase64
     * @throws \Granam\Safari\Exceptions\CanNotSaveDerSignatureToFile
     * @throws \Granam\Safari\Exceptions\CanNotReadZipPackage
     */
    public function processAppleAction(string $path = null): bool
    {
        $path = $path ?? $_SERVER['PATH_INFO'] ?? '';
        if ($path === '' || \preg_match('~^/v\d+/(?<action>[^/]+)/~', $path, $matches)) {
            throw new Exceptions\UnknownActionToDo("Do not know what to do by {$path}");
        }
        switch ($matches['action']) {
            case 'pushPackages' :
                return $this->pushPackages();
            case 'devices' :
                if (!\preg_match('~^/v\d +/devices / [^/]+/registrations / ~', $path, $matches)) {
                    throw new Exceptions\UnknownActionToDo("Do not know what to do by {$path}");
                }

                return $this->devicesRegistrations();
            case 'log' :
                return $this->log();
            default :
                throw new Exceptions\UnknownActionToDo("Do not know what to do by {$path}");
        }
    }
}