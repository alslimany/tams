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
     * Execute a transactional command (booking, issuing) in a fresh, isolated session.
     *
     * Always creates a new login — never reuses the cached session — so that any
     * dirty PNR state from previous availability / pricing commands cannot bleed
     * into the booking. The fresh session is intentionally never cached; it will
     * expire naturally on Videcom's side after the request completes.
     *
     * SOAP mode is already stateless, so it falls through to the normal path.
     *
     * @throws Exception
     */
    public function runTransactionalCommand(string $command): string
    {
        if (! isset($this->mode) || $this->mode !== self::MODE_SESSION) {
            return $this->runCommand($command);
        }

        $session = $this->login(); // fresh session — NOT from cache, NOT stored to cache

        return $this->sendCommandWithSession($command, $session);
    }

    /**
     * Run command using Expert Logon session.
     *
     * Cached sessions can expire on Videcom's side while Laravel still caches them.
     * NotSinedInException (and HTTP 500 variants) trigger a forced re-login + retry.
     */
    protected function runSessionCommand(string $command): string
    {
        $response = $this->sendCommandWithSession($command, $this->resolveSession(forceRefresh: false));

        if (! $this->isNotSignedInResponse($response)) {
            return $response;
        }

        Log::warning('Videcom session expired; forcing re-login.', [
            'base_url' => $this->baseUrl,
            'username' => $this->config['username'] ?? null,
            'airline_code' => $this->config['airline_code'] ?? null,
            'command' => $command,
        ]);

        $response = $this->sendCommandWithSession($command, $this->resolveSession(forceRefresh: true));

        if ($this->isNotSignedInResponse($response)) {
            throw new Exception('Videcom session expired and re-login did not restore access.');
        }

        return $response;
    }

    /**
     * Get a cached Expert Logon session, or login and store a new one.
     */
    protected function resolveSession(bool $forceRefresh = false): string
    {
        $cacheKey = $this->sessionCacheKey();

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $session = $forceRefresh ? null : Cache::get($cacheKey);

        if (is_string($session) && $session !== '') {
            return $session;
        }

        $session = $this->login();
        Cache::put($cacheKey, $session, now()->addMinutes(20));

        return $session;
    }

    protected function sessionCacheKey(): string
    {
        $username = (string) ($this->config['username'] ?? '');
        $passwordFingerprint = hash('sha256', (string) ($this->config['password'] ?? ''));
        $tenantId = (string) (tenant()?->getTenantKey() ?? tenant()?->id ?? 'central');

        return 'videcom_session_'.md5(implode('|', [
            $tenantId,
            $this->baseUrl,
            $username,
            $passwordFingerprint,
        ]));
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

        $sessionId = trim((string) $sessionId);

        if ($sessionId === '') {
            throw new Exception('Videcom login failed: VarsSessionID missing from NextURL.');
        }

        return 'VarsSessionID='.$sessionId;
    }

    /**
     * Send command using an existing session.
     */
    protected function sendCommandWithSession(string $command, string $sessionQuery): string
    {
        $url = "{$this->baseUrl}/VARS/Agent/res/EmulatorWS.asmx/SendCommand?{$sessionQuery}";

        try {
            $this->logOutgoingCommand($command, [
                'transport' => self::MODE_SESSION,
                'endpoint' => $url,
                'session_present' => str_contains($sessionQuery, 'VarsSessionID='),
            ]);

            $response = Http::post($url, [
                'VRSCommand' => $command,
            ]);

            $payload = $this->extractSessionCommandPayload($response->body(), $response->json());

            // Expired sessions often come back as HTTP 5xx with NotSinedInException in the body.
            // Surface that as a normal payload so runSessionCommand can re-login and retry.
            if ($this->isNotSignedInResponse($payload)) {
                return $payload;
            }

            if ($response->failed()) {
                throw new Exception('Videcom command failed: '.$response->body());
            }

            return $payload;
        } catch (Exception $e) {
            if ($this->isNotSignedInResponse($e->getMessage())) {
                return $e->getMessage();
            }

            Log::error('Videcom Session Command Error: '.$e->getMessage(), [
                'base_url' => $this->baseUrl,
                'command' => $command,
            ]);
            throw $e;
        }
    }

    /**
     * Normalize EmulatorWS JSON / ASP.NET error envelopes into a single string payload.
     *
     * @param  array<string, mixed>|null  $json
     */
    protected function extractSessionCommandPayload(string $rawBody, ?array $json): string
    {
        if (is_array($json)) {
            $data = $json['d'] ?? null;

            if (is_array($data)) {
                foreach (['Data', 'Message', 'ErrorMessage', 'msg'] as $key) {
                    if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                        return (string) $data[$key];
                    }
                }
            }

            foreach (['Message', 'ExceptionMessage', 'errorMessage', 'msg'] as $key) {
                if (isset($json[$key]) && is_scalar($json[$key]) && (string) $json[$key] !== '') {
                    return (string) $json[$key];
                }
            }
        }

        return $rawBody;
    }

    protected function isNotSignedInResponse(string $payload): bool
    {
        $normalized = strtolower($payload);

        return str_contains($payload, 'VARS.SystemLibrary.NotSinedInException')
            || str_contains($payload, 'VARS.SystemLibrary.NotSignedInException')
            || str_contains($normalized, 'notsinedinexception')
            || str_contains($normalized, 'notsignedinexception')
            || (str_contains($normalized, 'not signed in') && str_contains($normalized, 'exception'));
    }

    /**
     * Run command using SOAP API.
     */
    protected function runSoapCommand(string $command): string
    {
        $soapUrl = "{$this->baseUrl}/VRSXMLService/VRSXMLWebservice3.asmx?WSDL";
        $token = $this->config['token'] ?? '';

        if (empty($token)) {
            throw new Exception('Videcom token is missing for SOAP mode.');
        }

        $this->logOutgoingCommand($command, [
            'transport' => self::MODE_SOAP,
            'endpoint' => $soapUrl,
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="http://videcom.com/">
  <s:Body>
    <ns:msg>
      <ns:Token>'.htmlspecialchars($token, ENT_XML1).'</ns:Token>
      <ns:Command>'.htmlspecialchars($command, ENT_XML1).'</ns:Command>
    </ns:msg>
  </s:Body>
</s:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'application/soap+xml',
            'SOAPAction' => 'http://videcom.com/RunVRSCommand',
        ])->withBody($xml, 'text/xml')->post($soapUrl);

        if ($response->failed()) {
            throw new Exception('Videcom SOAP request failed: '.$response->body());
        }

        return $this->parseSoapResponse($response->body());
    }

    /**
     * Log outbound commands so they can be traced in storage/logs/laravel.log.
     */
    protected function logOutgoingCommand(string $command, array $context = []): void
    {
        Log::info('Videcom outgoing command', array_merge([
            'mode' => $this->mode,
            'base_url' => $this->baseUrl,
            'airline_code' => $this->config['airline_code'] ?? null,
            'command' => $command,
        ], $context));
    }

    /**
     * Extract RunVRSCommandResult from SOAP response.
     */
    protected function parseSoapResponse(string $soapResponse): string
    {
        $xml = simplexml_load_string($soapResponse);

        if ($xml) {
            $results = $xml->xpath('//*[local-name()="RunVRSCommandResult"]');

            if (! empty($results)) {
                return html_entity_decode((string) $results[0], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        if (preg_match('/<RunVRSCommandResult(?:\s[^>]*)?>(.*?)<\/RunVRSCommandResult>/s', $soapResponse, $matches) === 1) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        if (preg_match('/<\w+:RunVRSCommandResult(?:\s[^>]*)?>(.*?)<\/\w+:RunVRSCommandResult>/s', $soapResponse, $matches) === 1) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        if (preg_match('/<[^>]*RunVRSCommandResult(?:\s[^>]*)?>(.*?)<\/[^>]*RunVRSCommandResult>/s', $soapResponse, $matches) === 1) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $soapResponse;
    }
}
