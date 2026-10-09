<?php
/**
 * Google OAuth 2.0 Identity Service
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * Provides cryptographic verification and identity extraction for Google Sign-In.
 * strictly conforms to OpenID Connect specification using Google API Client for PHP.
 */

if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../config/config.php';
}

if (!class_exists('Google\Client')) {
    $autoload = ROOT_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        throw new RuntimeException("Composer dependencies not found. Please run 'composer require google/apiclient'.");
    }
}

class GoogleAuthService
{
    private \Google\Client $client;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private array $scopes;

    /**
     * Initialize Google OAuth Client with project configuration
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $config = require ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'google.php';
        }

        $this->clientId     = (string)($config['client_id'] ?? '');
        $this->clientSecret = (string)($config['client_secret'] ?? '');
        $this->redirectUri  = (string)($config['redirect_uri'] ?? '');
        $this->scopes       = (array)($config['scopes'] ?? ['openid', 'email', 'profile']);

        $this->client = new \Google\Client();
        $this->client->setClientId($this->clientId);
        $this->client->setClientSecret($this->clientSecret);
        $this->client->setRedirectUri($this->redirectUri);

        foreach ($this->scopes as $scope) {
            $this->client->addScope($scope);
        }

        // Enforce account selection prompt and online access
        $this->client->setPrompt('select_account');
        $this->client->setAccessType('online');
    }

    /**
     * Check whether Client ID and Secret have been configured with non-placeholder values
     */
    public function isConfigured(): bool
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            return false;
        }
        if (strpos($this->clientId, 'YOUR_') === 0 || strpos($this->clientSecret, 'YOUR_') === 0) {
            return false;
        }
        return true;
    }

    /**
     * Get underlying Google\Client instance
     */
    public function getClient(): \Google\Client
    {
        return $this->client;
    }

    /**
     * Generate the secure Google authorization URL with CSRF anti-tamper state
     */
    public function createAuthUrl(?string $state = null): string
    {
        if ($state !== null) {
            $this->client->setState($state);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for access and ID tokens
     *
     * @param string $code
     * @return array
     * @throws RuntimeException
     */
    public function exchangeCode(string $code): array
    {
        if (empty($code)) {
            throw new InvalidArgumentException("Authorization code cannot be empty.");
        }

        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (!is_array($token) || isset($token['error'])) {
            $errorDesc = is_array($token) ? ($token['error_description'] ?? $token['error'] ?? 'Unknown error') : 'Token request failed';
            throw new RuntimeException("Failed to exchange authorization code: " . htmlspecialchars($errorDesc, ENT_QUOTES, 'UTF-8'));
        }

        return $token;
    }

    /**
     * Cryptographically verify the Google ID token and extract user claims.
     * Validates:
     *  - RSA Signature via Google's public JWKS certificates
     *  - Issuer (iss: accounts.google.com or https://accounts.google.com)
     *  - Audience / Client ID (aud matches configured Client ID)
     *  - Expiration timestamp (exp > current time)
     *  - Subject identifier (sub is present and immutable)
     *
     * @param string $idToken
     * @return array Verified identity payload (sub, email, name, picture, etc.)
     * @throws RuntimeException
     */
    public function verifyAndExtractIdToken(string $idToken): array
    {
        if (empty($idToken)) {
            throw new InvalidArgumentException("ID token cannot be empty.");
        }

        // Verify cryptographic signature and claims using Google's certs
        $payload = $this->client->verifyIdToken($idToken);

        if (!$payload || !is_array($payload)) {
            throw new RuntimeException("Google ID token verification failed. Signature is invalid or payload is corrupted.");
        }

        // 1. Verify Issuer
        $iss = $payload['iss'] ?? '';
        $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
        if (!in_array($iss, $validIssuers, true)) {
            throw new RuntimeException("Google ID token issuer mismatch: '{$iss}'.");
        }

        // 2. Verify Audience (Client ID)
        $aud = $payload['aud'] ?? '';
        if ($aud !== $this->clientId) {
            throw new RuntimeException("Google ID token audience mismatch.");
        }

        // 3. Verify Expiration
        $exp = (int)($payload['exp'] ?? 0);
        // Allow up to 30s clock skew
        if ($exp < (time() - 30)) {
            throw new RuntimeException("Google ID token has expired.");
        }

        // 4. Verify Subject (stable unique Google ID)
        $sub = trim((string)($payload['sub'] ?? ''));
        if (empty($sub)) {
            throw new RuntimeException("Google ID token missing subject (sub) identifier.");
        }

        // Extract safe profile claims (do not trust unverified browser inputs)
        $email = strtolower(trim((string)($payload['email'] ?? '')));
        $emailVerified = (bool)($payload['email_verified'] ?? false);
        $name = trim((string)($payload['name'] ?? ''));
        $picture = trim((string)($payload['picture'] ?? ''));

        return [
            'sub'            => $sub,
            'email'          => $email,
            'email_verified' => $emailVerified,
            'name'           => $name,
            'given_name'     => trim((string)($payload['given_name'] ?? '')),
            'family_name'    => trim((string)($payload['family_name'] ?? '')),
            'picture'        => $picture,
        ];
    }

    /**
     * Unified handler to exchange authorization code and verify identity payload
     */
    public function handleOAuthCallback(string $code): array
    {
        $token = $this->exchangeCode($code);

        $idToken = $token['id_token'] ?? null;
        if (empty($idToken)) {
            throw new RuntimeException("No ID token returned by Google OAuth server.");
        }

        return $this->verifyAndExtractIdToken($idToken);
    }
}
