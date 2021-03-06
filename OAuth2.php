<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\authclient;

use Yii;
use yii\helpers\Json;
use yii\web\HttpException;

/**
 * OAuth2 serves as a client for the OAuth 2 flow.
 *
 * In oder to acquire access token perform following sequence:
 *
 * ```php
 * use yii\authclient\OAuth2;
 *
 * // assuming class MyAuthClient extends OAuth2
 * $oauthClient = new MyAuthClient();
 * $url = $oauthClient->buildAuthUrl(); // Build authorization URL
 * Yii::$app->getResponse()->redirect($url); // Redirect to authorization URL.
 * // After user returns at our site:
 * $code = $_GET['code'];
 * $accessToken = $oauthClient->fetchAccessToken($code); // Get access token
 * ```
 *
 * @see http://oauth.net/2/
 * @see https://tools.ietf.org/html/rfc6749
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
abstract class OAuth2 extends BaseOAuth
{
    /**
     * @var string protocol version.
     */
    public $version = '2.0';
    /**
     * @var string OAuth client ID.
     */
    public $clientId;
    /**
     * @var string OAuth client secret.
     */
    public $clientSecret;
    /**
     * @var string token request URL endpoint.
     */
    public $tokenUrl;
    /**
     * @var bool whether to use and validate auth 'state' parameter in authentication flow.
     * If enabled - the opaque value will be generated and applied to auth URL to maintain
     * state between the request and callback. The authorization server includes this value,
     * when redirecting the user-agent back to the client.
     * The option is used for preventing cross-site request forgery.
     * @since 2.1
     */
    public $validateAuthState = true;


    /**
     * Composes user authorization URL.
     * @param array $params additional auth GET params.
     * @return string authorization URL.
     */
    public function buildAuthUrl(array $params = [])
    {
        $defaultParams = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->getReturnUrl(),
            'xoauth_displayname' => Yii::$app->name,
        ];
        if (!empty($this->scope)) {
            $defaultParams['scope'] = $this->scope;
        }

        if ($this->validateAuthState) {
            $authState = $this->generateAuthState();
            $this->setState('authState', $authState);
            $defaultParams['state'] = $authState;
        }

        return $this->composeUrl($this->authUrl, array_merge($defaultParams, $params));
    }

    /**
     * Fetches access token from authorization code.
     * @param string $authCode authorization code, usually comes at $_GET['code'].
     * @param array $params additional request params.
     * @return OAuthToken access token.
     * @throws HttpException on invalid auth state in case [[enableStateValidation]] is enabled.
     */
    public function fetchAccessToken($authCode, array $params = [])
    {
        if ($this->validateAuthState) {
            $authState = $this->getState('authState');
            if (!isset($_REQUEST['state']) || empty($authState) || strcmp($_REQUEST['state'], $authState) !== 0) {
                throw new HttpException(400, 'Invalid auth state parameter.');
            } else {
                $this->removeState('authState');
            }
        }

        $defaultParams = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $authCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->getReturnUrl(),
        ];

        $request = $this->createRequest()
            ->setMethod('POST')
            ->setUrl($this->tokenUrl)
            ->setData(array_merge($defaultParams, $params));

        $response = $this->sendRequest($request);

        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }

    /**
     * @inheritdoc
     */
    public function applyAccessTokenToRequest($request, $accessToken)
    {
        $data = $request->getData();
        $data['access_token'] = $accessToken->getToken();
        $request->setData($data);
    }

    /**
     * Gets new auth token to replace expired one.
     * @param OAuthToken $token expired auth token.
     * @return OAuthToken new auth token.
     */
    public function refreshAccessToken(OAuthToken $token)
    {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token'
        ];
        $params = array_merge($token->getParams(), $params);

        $request = $this->createRequest()
            ->setMethod('POST')
            ->setUrl($this->tokenUrl)
            ->setData($params);

        $response = $this->sendRequest($request);

        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }

    /**
     * Composes default [[returnUrl]] value.
     * @return string return URL.
     */
    protected function defaultReturnUrl()
    {
        $params = $_GET;
        unset($params['code']);
        unset($params['state']);
        $params[0] = Yii::$app->controller->getRoute();

        return Yii::$app->getUrlManager()->createAbsoluteUrl($params);
    }

    /**
     * Generates the auth state value.
     * @return string auth state value.
     * @since 2.1
     */
    protected function generateAuthState()
    {
        $baseString = get_class($this) . '-' . time();
        if (Yii::$app->has('session')) {
            $baseString .= '-' . Yii::$app->session->getId();
        }
        return hash('sha256', uniqid($baseString, true));
    }

    /**
     * Creates token from its configuration.
     * @param array $tokenConfig token configuration.
     * @return OAuthToken token instance.
     */
    protected function createToken(array $tokenConfig = [])
    {
        $tokenConfig['tokenParamKey'] = 'access_token';

        return parent::createToken($tokenConfig);
    }

    /**
     * Authenticate OAuth client directly at the provider without third party (user) involved,
     * using 'client_credentials' grant type.
     * @see http://tools.ietf.org/html/rfc6749#section-4.4
     * @param array $params additional request params.
     * @return OAuthToken access token.
     * @since 2.1.0
     */
    public function authenticateClient($params = [])
    {
        $defaultParams = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ];

        if (!empty($this->scope)) {
            $defaultParams['scope'] = $this->scope;
        }

        $request = $this->createRequest()
            ->setMethod('POST')
            ->setUrl($this->tokenUrl)
            ->setData(array_merge($defaultParams, $params));

        $response = $this->sendRequest($request);

        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }

    /**
     * Authenticates user directly by 'username/password' pair, using 'password' grant type.
     * @see https://tools.ietf.org/html/rfc6749#section-4.3
     * @param string $username user name.
     * @param string $password user password.
     * @param array $params additional request params.
     * @return OAuthToken access token.
     * @since 2.1.0
     */
    public function authenticateUser($username, $password, $params = [])
    {
        $defaultParams = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
        ];

        if (!empty($this->scope)) {
            $defaultParams['scope'] = $this->scope;
        }

        $request = $this->createRequest()
            ->setMethod('POST')
            ->setUrl($this->tokenUrl)
            ->setData(array_merge($defaultParams, $params));

        $response = $this->sendRequest($request);

        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }

    /**
     * Authenticates client using JSON Web Token (JWT).
     * Note: do not forget to setup [[signatureMethod]] in your OAuth client configuration to make this method function correctly.
     * @see https://tools.ietf.org/html/rfc7515
     * @param array $header JWS header parameters.
     * @param array $payload JWS payload (message or claim-set)
     * @param array $params additional request params.
     * @return OAuthToken access token.
     * @since 2.1.3
     */
    public function authenticateJwt($header = [], $payload = [], $params = [])
    {
        $signatureMethod = $this->getSignatureMethod();

        $header = array_merge([
            'typ' => 'JWT'
        ], $header);
        if (!isset($header['alg'])) {
            $signatureName = $signatureMethod->getName();
            if (preg_match('/^([a-z])[a-z]*\-([a-z])[a-z]*([0-9]+)$/is', $signatureName, $matches)) {
                // convert 'RSA-SHA256' to 'RS256' :
                $signatureName = $matches[1] . $matches[2] . $matches[3];
            }
            $header['alg'] = $signatureName;
        }

        $payload = array_merge([
            'iss' => $this->clientId,
            'scope' => $this->scope,
            'aud' => $this->tokenUrl,
            'iat' => time(),
        ], $payload);
        if (!isset($payload['exp'])) {
            $payload['exp'] = $payload['iat'] + 3600;
        }

        $signatureBaseString = base64_encode(Json::encode($header)) . '.' . base64_encode(Json::encode($payload));
        $signature = $signatureMethod->generateSignature($signatureBaseString, '');

        $assertion = $signatureBaseString . '.' . $signature;

        $request = $this->createRequest()
            ->setMethod('POST')
            ->setUrl($this->tokenUrl)
            ->setData(array_merge([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ], $params));

        $response = $this->sendRequest($request);

        $token = $this->createToken(['params' => $response]);
        $this->setAccessToken($token);

        return $token;
    }
}