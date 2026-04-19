<?php

namespace App\Services\Airline\Videcom;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VidecomClient
{
    public const MODE_API = 'api';

    public const MODE_SOAP = 'soap';

    public const MODE_SESSION = 'session';

    protected string $baseUrl;

    protected array $config;

    protected string $mode;

    /**
     * VidecomClient constructor.
     *
     * @param  array  $config  Configuration containing credentials, base_url, and mode
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseUrl = rtrim($config['base_url'], '/');
        $mode = strtolower((string) ($config['mode'] ?? self::MODE_SESSION));
        $this->mode = $mode === self::MODE_API ? self::MODE_SOAP : $mode;
    }

    /**
     * Execute a VRS command.
     *
     * @param  string  $command  The VRS command to run
     * @return string The raw response from Videcom
     *
     * @throws Exception
     */
    public function runCommand(string $command): string
    {
        return match ($this->mode) {
            self::MODE_SOAP => $this->runSoapCommand($command),
            self::MODE_SESSION => $this->runSessionCommand($command),
            default => throw new Exception("Unsupported Videcom client mode: {$this->mode}"),
        };
    }

    /**
     * Run command using Expert Logon session.
     */
    protected function runSessionCommand(string $command): string
    {
        $username = $this->config['username'] ?? '';
        $cacheKey = 'videcom_session_'.md5($this->baseUrl.$username);
        $session = Cache::get($cacheKey);

        if (! $session) {
            $session = $this->login();
            Cache::put($cacheKey, $session, now()->addMinutes(20));
        }

        $response = $this->sendCommandWithSession($command, $session);

        // Check if session expired (NotSinedInException)
        if (str_contains($response, 'VARS.SystemLibrary.NotSinedInException')) {
            $session = $this->login();
            Cache::put($cacheKey, $session, now()->addMinutes(20));
            $response = $this->sendCommandWithSession($command, $session);
        }

        return $response;
    }

    /**
     * Perform login to get a new session ID.
     */
    protected function login(): string
    {
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';

        if (empty($username) || empty($password)) {
            throw new Exception('Videcom credentials (username/password) are missing for Expert Logon.');
        }

        $loginUrl = "{$this->baseUrl}/VARS/Agent/WebServices/LoginWs.asmx/DoLogin?VarsSessionID=undefined";

        $response = Http::withBody(json_encode([
            'loginRq' => [
                'UserName' => $username,
                'Password' => $password,
            ],
        ]), 'application/json')->post($loginUrl);

        if ($response->failed()) {
            Log::error('Videcom Login HTTP Failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new Exception('Videcom login failed with status '.$response->status());
        }

        $data = $response->json('d');
        if (empty($data['NextURL'])) {
            Log::error('Videcom Login Response Missing NextURL', ['data' => $data]);
            throw new Exception('Videcom login failed: Invalid credentials or account locked.');
        }

        $nextUrl = $data['NextURL'];
        $parts = parse_url($nextUrl);
        parse_str($parts['query'] ?? '', $query);

        $sessionId = $query['VarsSessionID'] ?? null;

        if (! $sessionId) {
            $exploded = explode('VarsSessionID=', $nextUrl);
            $sessionId = end($exploded);
        }

        return "VarsSessionID={$sessionId}";
    }

    /**
     * Send command using an existing session.
     */
    protected function sendCommandWithSession(string $command, string $sessionQuery): string
    {
        $url = "{$this->baseUrl}/VARS/Agent/res/EmulatorWS.asmx/SendCommand?{$sessionQuery}";

        try {
            $response = Http::post($url, [
                'VRSCommand' => $command,
            ]);

            if ($response->failed()) {
                throw new Exception('Videcom command failed: '.$response->body());
            }

            return $response->json('d')['Data'] ?? '';
        } catch (Exception $e) {
            Log::error('Videcom Session Command Error: '.$e->getMessage(), [
                'base_url' => $this->baseUrl,
                'command' => $command,
            ]);
            throw $e;
        }
    }

    /**
     * Run command using SOAP API.
     */
    protected function runSoapCommand(string $command): string
    {
        $soapUrl = "{$this->baseUrl}/vars/public/webservices/VRSXMLWebservice3.asmx";
        $token = $this->config['token'] ?? '';

        if (empty($token)) {
            throw new Exception('Videcom token is missing for SOAP mode.');
        }

        $xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <RunVRSCommand xmlns="http://videcom.com/">
      <msg>
        <Token>'.htmlspecialchars($token).'</Token>
        <Command>'.htmlspecialchars($command).'</Command>
      </msg>
    </RunVRSCommand>
  </soap:Body>
</soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => 'http://videcom.com/RunVRSCommand',
        ])->send('POST', $soapUrl, [
            'body' => $xml,
        ]);

        if ($response->failed()) {
            throw new Exception('Videcom SOAP request failed: '.$response->body());
        }

        return $this->parseSoapResponse($response->body());
    }

    /**
     * Extract RunVRSCommandResult from SOAP response.
     */
    protected function parseSoapResponse(string $soapResponse): string
    {
        $xml = simplexml_load_string($soapResponse);
        if (! $xml) {
            return $soapResponse;
        }

        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('ns', 'http://videcom.com/');

        $results = $xml->xpath('//ns:RunVRSCommandResult');

        return ! empty($results) ? (string) $results[0] : $soapResponse;
    }
}
