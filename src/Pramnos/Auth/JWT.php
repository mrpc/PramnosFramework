<?php
namespace Pramnos\Auth;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;

/**
 * JSON Web Token implementation, based on web-token/jwt-framework
 * But maintaining compatibility with the original API
 *
 * @category Authentication
 * @package  Authentication_JWT
 * @license  MIT
 */

class ExpiredException extends \Exception {}

class JWT
{
    /**
     * When checking nbf, iat or expiration times,
     * we want to provide some extra leeway time to
     * account for clock skew.
     */
    public static $leeway = 0;

    /**
     * List of supported signing algorithms.
     * The first element in each array is the algorithm type.
     * The second element in each array is the specific algorithm.
     * This maintains compatibility with the original implementation.
     */
    public static $supported_algs = [
        // HMAC algorithms
        'HS256' => ['hash_hmac', 'SHA256'],
        'HS384' => ['hash_hmac', 'SHA384'],
        'HS512' => ['hash_hmac', 'SHA512'],
        
        // RSA algorithms 
        'RS256' => ['openssl', 'SHA256'],
        'RS384' => ['openssl', 'SHA384'],
        'RS512' => ['openssl', 'SHA512'],
        
        // ECDSA algorithms
        'ES256' => ['openssl', 'SHA256'],
        'ES384' => ['openssl', 'SHA384'],
        'ES512' => ['openssl', 'SHA512'],
        
        // EdDSA algorithms
        'EdDSA' => ['openssl', 'EdDSA'],
        
        // RSAPSS algorithms
        'PS256' => ['openssl', 'SHA256'],
        'PS384' => ['openssl', 'SHA384'],
        'PS512' => ['openssl', 'SHA512'],
    ];

    /**
     * Decodes a JWT string into a PHP object.
     *
     * @param string      $jwt           The JWT
     * @param string|Array|null $key     The secret key, or map of keys
     * @param Array       $allowed_algs  List of supported verification algorithms
     *
     * @return object      The JWT's payload as a PHP object
     *
     * @throws \DomainException              Algorithm was not provided
     * @throws \UnexpectedValueException     Provided JWT was invalid
     * @throws \Exception                    Provided JWT was invalid because the signature verification failed
     * @throws \UnexpectedValueException     Provided JWT is trying to be used before it's eligible as defined by 'nbf'
     * @throws \UnexpectedValueException     Provided JWT is trying to be used before it's been created as defined by 'iat'
     * @throws ExpiredException             Provided JWT has since expired, as defined by the 'exp' claim
     */
    public static function decode($jwt, $key = null, $allowed_algs = array())
    {
        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new \UnexpectedValueException('Wrong number of segments');
        }
        list($headb64, $bodyb64, $cryptob64) = $tks;
        
        // Decode header
        $headerJson = \Jose\Component\Core\Util\Base64Url::decode($headb64);
        $header = json_decode($headerJson);
        if ($header === null) {
            throw new \UnexpectedValueException('Invalid header encoding');
        }
        
        // Decode payload
        $payloadJson = \Jose\Component\Core\Util\Base64Url::decode($bodyb64);
        $payload = json_decode($payloadJson);
        if ($payload === null) {
            throw new \UnexpectedValueException('Invalid claims encoding');
        }
        
        if (isset($key)) {
            if (empty($header->alg)) {
                throw new \DomainException('Empty algorithm');
            }
            if (empty(self::$supported_algs[$header->alg])) {
                throw new \DomainException('Algorithm not supported');
            }
            if (!is_array($allowed_algs) || !in_array($header->alg, $allowed_algs)) {
                throw new \DomainException('Algorithm not allowed');
            }
            
            // Handle key selection if array
            $actualKey = $key;
            if (is_array($key) || $key instanceof \ArrayAccess) {
                if (isset($header->kid)) {
                    $actualKey = $key[$header->kid] ?? null;
                    if ($actualKey === null) {
                        throw new \DomainException('Key ID not found');
                    }
                } else {
                    throw new \DomainException('"kid" empty, unable to lookup correct key');
                }
            }

            // Verify signature using web-token library
            if (!self::verifyWithWebToken($jwt, $actualKey, $header->alg)) {
                throw new \Exception('Signature verification failed');
            }

            // Check if the nbf if it is defined. This is the time that the
            // token can actually be used. If it's not yet that time, abort.
            if (isset($payload->nbf) && $payload->nbf > (time() + self::$leeway)) {
                throw new \UnexpectedValueException(
                    'Cannot handle token prior to ' . date(\DateTime::ISO8601, $payload->nbf)
                );
            }

            // Check that this token has been created before 'now'. This prevents
            // using tokens that have been created for later use (and haven't
            // correctly used the nbf claim).
            if (isset($payload->iat) && $payload->iat > (time() + self::$leeway)) {
                throw new \UnexpectedValueException(
                    'Cannot handle token prior to ' . date(\DateTime::ISO8601, $payload->iat)
                );
            }

            // Check if this token has expired.
            if (isset($payload->exp) && (time() - self::$leeway) >= $payload->exp) {
                throw new ExpiredException('Expired token');
            }
        }

        return $payload;
    }

    /**
     * Converts and signs a PHP object or array into a JWT string.
     *
     * @param object|array $payload PHP object or array
     * @param string       $key     The secret key
     * @param string       $alg     The signing algorithm. Supported
     *                              algorithms are 'HS256', 'HS384' and 'HS512'
     *
     * @return string      A signed JWT
     */
    public static function encode($payload, $key, $alg = 'HS256', $keyId = null)
    {
        // Using web-token/jwt-framework for encoding
        return self::encodeWithWebToken($payload, $key, $alg, $keyId);
    }

    /**
     * Sign a string with a given key and algorithm.
     *
     * @param string $msg          The message to sign
     * @param string|resource $key The secret key
     * @param string $alg       The signing algorithm.
     *
     * @return string          An encrypted message
     * @throws \DomainException Unsupported algorithm was specified
     */
    public static function sign($msg, $key, $alg = 'HS256')
    {
        if (empty(self::$supported_algs[$alg])) {
            throw new \DomainException('Algorithm not supported');
        }
        list($function, $algorithm) = self::$supported_algs[$alg];
        switch($function) {
            case 'hash_hmac':
                return hash_hmac($algorithm, $msg, $key ?? '', true);
            case 'openssl':
                $signature = '';
                $success = openssl_sign($msg, $signature, $key, $algorithm);
                if (!$success) {
                    throw new \DomainException("OpenSSL unable to sign data");
                } else {
                    return $signature;
                }
        }
    }

    /**
     * Using web-token to verify JWT
     * 
     * @param string $jwt Complete JWT string
     * @param string|resource $key Key for verification
     * @param string $alg Algorithm
     * @return bool
     */
    private static function verifyWithWebToken($jwt, $key, $alg)
    {
        try {
            // Create algorithm manager with the requested algorithm
            $algorithmManager = new AlgorithmManager(self::getAlgorithmsByName($alg));
            
            // Create the verifier
            $jwsVerifier = new JWSVerifier($algorithmManager);
            
            // Create serializer manager
            $serializerManager = new JWSSerializerManager([
                new CompactSerializer(),
            ]);
            
            // Load the token
            $jws = $serializerManager->unserialize($jwt);
            
            // Create the appropriate key for verification
            $jwk = self::createJWKFromKey($key, $alg);
            
            // Verify
            return $jwsVerifier->verifyWithKey($jws, $jwk, 0);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Using web-token to encode JWT
     * 
     * @param object|array $payload Data to encode
     * @param string|resource $key Key for signing
     * @param string $alg Algorithm
     * @param string|null $keyId Key ID if needed
     * @return string
     */
    private static function encodeWithWebToken($payload, $key, $alg = 'HS256', $keyId = null)
    {
        // Create algorithm manager
        $algorithmManager = new AlgorithmManager(self::getAlgorithmsByName($alg));
        
        // Create the JWS Builder
        $jwsBuilder = new JWSBuilder($algorithmManager);
        
        // Prepare header
        $header = ['typ' => 'JWT', 'alg' => $alg];
        if ($keyId !== null) {
            $header['kid'] = $keyId;
        }
        
        // Create the JWK for signing
        $jwk = self::createJWKFromKey($key, $alg);
        
        // Prepare payload
        $payloadString = json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \DomainException('Unable to encode payload to JSON: ' . json_last_error_msg());
        }
        
        // Create the JWS
        $jws = $jwsBuilder
            ->create()
            ->withPayload($payloadString)
            ->addSignature($jwk, $header)
            ->build();
            
        // Serialize the token to get the compact representation (JWT)
        $serializer = new CompactSerializer();
        return $serializer->serialize($jws, 0);
    }
    
    /**
     * Create a JWK from a string key or resource
     * 
     * @param string|resource $key
     * @param string $alg
     * @return JWK
     */
    private static function createJWKFromKey($key, $alg)
    {
        $isSymmetric = in_array($alg, ['HS256', 'HS384', 'HS512']);
        
        if ($isSymmetric) {
            // For HMAC algorithms, use simple key
            return new JWK([
                'kty' => 'oct',
                'k' => \Jose\Component\Core\Util\Base64Url::encode($key),
            ]);
        } else if (in_array($alg, ['ES256', 'ES384', 'ES512'])) {
            // For ECDSA algorithms
            if (is_resource($key) || ($key instanceof \OpenSSLAsymmetricKey)) {
                // Convert OpenSSL resource to PEM
                $details = openssl_pkey_get_details($key);
                if ($details === false) {
                    throw new \DomainException('Unable to get key details');
                }
                $key = $details['key'];
            }
            
            return JWK::createFromPem($key);
        } else if ($alg === 'EdDSA') {
            // For EdDSA algorithms
            if (is_resource($key) || ($key instanceof \OpenSSLAsymmetricKey)) {
                // Convert OpenSSL resource to PEM
                $details = openssl_pkey_get_details($key);
                if ($details === false) {
                    throw new \DomainException('Unable to get key details');
                }
                $key = $details['key'];
            }
            
            return JWK::createFromPem($key);
        } else {
            // For RSA and RSAPSS algorithms
            if (is_resource($key) || ($key instanceof \OpenSSLAsymmetricKey)) {
                // Convert OpenSSL resource to PEM
                $details = openssl_pkey_get_details($key);
                if ($details === false) {
                    throw new \DomainException('Unable to get key details');
                }
                $key = $details['key'];
            }
            
            return JWK::createFromPem($key);
        }
    }
    
    /**
     * Get web-token algorithm instances by name
     * 
     * @param string $alg Algorithm name
     * @return array Array with algorithm instance
     * @throws \DomainException If algorithm not supported
     */
    private static function getAlgorithmsByName($alg)
    {
        switch ($alg) {
            // HMAC algorithms
            case 'HS256':
                return [new HS256()];
            case 'HS384':
                return [new HS384()];
            case 'HS512':
                return [new HS512()];
                
            // RSA algorithms
            case 'RS256':
                return [new RS256()];
            case 'RS384':
                return [new \Jose\Component\Signature\Algorithm\RS384()];
            case 'RS512':
                return [new \Jose\Component\Signature\Algorithm\RS512()];
                
            // ECDSA algorithms
            case 'ES256':
                return [new \Jose\Component\Signature\Algorithm\ES256()];
            case 'ES384':
                return [new \Jose\Component\Signature\Algorithm\ES384()];
            case 'ES512':
                return [new \Jose\Component\Signature\Algorithm\ES512()];
                
            // EdDSA algorithms
            case 'EdDSA':
                return [new \Jose\Component\Signature\Algorithm\EdDSA()];
                
            // RSAPSS algorithms
            case 'PS256':
                return [new \Jose\Component\Signature\Algorithm\PS256()];
            case 'PS384':
                return [new \Jose\Component\Signature\Algorithm\PS384()];
            case 'PS512':
                return [new \Jose\Component\Signature\Algorithm\PS512()];
                
            default:
                throw new \DomainException('Algorithm not supported: ' . $alg);
        }
    }
}