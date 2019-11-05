<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

use Lcobucci\JWT\{Builder,Parser,ValidationData,Token};
use Lcobucci\JWT\Signer\Hmac\Sha256;

DrnACL::CreateSysACL(ACL_SYS_TOKEN,
                     [ Group::ADMINS => ACCESS_CREATE | ACCESS_READ | ACCESS_UPDATE,
                       Group::GURUS  => ACCESS_READ
                     ] );

/********************************************************************
 *
 *  JWT class
 *
 ********************************************************************/

/**
 *  This implements JSON Web Tokens. See \ref api_authentication for details on
 *  how this is used in Doreen.
 */
abstract class JWT
{
    // Standard claims
    const CLAIM_ID = 'jti';
    const CLAIM_SUBJECT = 'sub';
    const CLAIM_ISSUER = 'iss';
    const CLAIM_AUDIENCE = 'aud';

    // Doreen claims
    const DRN_TYPE = 'drt';
    const DRN_PARENT_ID = 'drp';

    //TODO better type management
    // Doreen token types
    const TYPE_API_CLIENT = 'upw'; // also referred to as parent token.
    const TYPE_USER_GRANT = 'usr';

    const API_AUDIENCE = '/api';

    const FRONTEND_UID = 0;

    private static $key;
    private static $tokenType = 'bearer';
    private static $signer;
    /**
     *  Array of recognized token types. The key is the identifier for the token
     *  type and the value contains an array with two values, required claims
     *  in a "claims" key and a validation method as "validator".
     *
     *  The validation method should either throw when the token is invalid or
     *  add new rules for the JWT validator.
     *
     *  @var array
     */
    private static $types = [];

    /**
     *  Get the host of the current installation. Used to identify the issuer
     *  and the allowed usage scope for the token.
     *
     *  @return string
     */
    private static function GetHost()
        : string
    {
        /* This should give us the hostname from $_SERVER, which should not be empty.
         * If it is however, or we get called from the CLI and have no cached hostname,
         * then bad things happen, so better throw here than have errors later.
         */
        if (!($hostname = Globals::GetHostname()))
            throw new DrnException("Error in JWT backend: hostname must not be NULL");

        return $hostname.Globals::$rootpage;
    }

    /**
     *  Lazy singleton factory for the SHA256 token signer.
     *
     *  @return Sha256
     */
    private static function GetSigner()
        : Sha256
    {
        if (!isset(self::$signer))
            self::$signer = new Sha256();
        return self::$signer;
    }

    /**
     *  Fetch the signing secret.
     *
     *  @return string
     */
    private static function GetKey()
        : string
    {
        if (!isset(self::$key))
            if (!(self::$key = GlobalConfig::Get(GlobalConfig::KEY_JWT_SECRET)))
                throw new DrnException("Cannot find JWT secret in global config, try running cli.php regenerate-jwt?");
        return self::$key;
    }

    /**
     *  Update the signing secret.
     */
    public static function UpdateKey(string $secret)
    {
        GlobalConfig::Set(GlobalConfig::KEY_JWT_SECRET, $secret);
        self::$key = $secret;
    }

    private static $frontendToken;
    /**
     *  Fetch the JWT parent token for the frontend.
     *
     *  @return Token
     */
    private static function GetFrontendToken()
        : Token
    {
        if (!isset(self::$frontendToken))
        {
            $parser = new Parser();
            self::$frontendToken = $parser->parse(GlobalConfig::Get(GlobalConfig::KEY_JWT_FRONTEND_TOKEN));
        }
        return self::$frontendToken;
    }

    /**
     *  Get the parent token ID for a user.
     *
     *  @return string
     */
    protected static function GetIDForUser(int $userID)
        : string
    {
        if ($userID === self::FRONTEND_UID)
            return self::GetFrontendToken()->getClaim(self::CLAIM_ID);

        if (!($oUser = User::Find($userID)))
            throw new DrnException('No user with the given UID');

        return $oUser->getExtraValue(User::JWT_KEY);
    }

    /**
     *  Ensure the ID of a parent token is allowed.
     *  Currently doesn't check against user IDs and just returns true.
     *
     *  @return bool
     */
    protected static function ValidTokenID(string $tokenID)
        : bool
    {
        if ($tokenID === self::GetFrontendToken()->getClaim(self::CLAIM_ID))
            return TRUE;

        //TODO actually check the ID. Not really concerned about it right now.
        return TRUE;
    }

    /**
     *  Check registered with the parent token type. Ensures the ID is still
     *  the current token ID for the user.
     */
    private static function ValidateAPIClient(Token $token,
                                              ValidationData $validationData,
                                              string $audience)
    {
        $validationData->setId(self::GetIDForUser((int)$token->getClaim(self::CLAIM_SUBJECT)));
    }

    /**
     *  Check registered with the user grant token type. Ensures the audience
     *  for the token matches the API audience for this install and that the
     *  id of the parent is still valid.
     */
    private static function ValidateUserGrant(Token $token,
                                              ValidationData $validationData,
                                              string $audience)
    {
        if ($audience !== self::API_AUDIENCE)
            throw new DrnException('Invalid audience '.Format::UTF8Quote($audience)." for user grant JWT");
        if (!self::ValidTokenID($token->getClaim(self::DRN_PARENT_ID)))
            throw new DrnException("User grant parent JWT ID is not recognized");
    }

    /**
     *  Register a new token type. Returns whether the type was registerd.
     *
     *  @return bool
     */
    public static function RegisterType(string $name,               //<! in: Name of the type to register
                                        callable $validator,        //<! in: Validation function. Gets the Token and ValidationData instances as params.
                                        array $extraClaims = [])    //<! in: Type-specific required claims. Claims are checked before running the validator.
        : bool
    {
        if (isset(self::$types[$name]))
            return FALSE;

        self::$types[$name] = [
            'validator' => $validator,
            'claims' => $extraClaims
        ];
        return TRUE;
    }

    /**
     *  Initialize the default token types.
     */
    public static function Init()
    {
        self::RegisterType(self::TYPE_API_CLIENT,
                           [ self::class, 'ValidateAPIClient' ]);
        self::RegisterType(self::TYPE_USER_GRANT,
                           [ self::class, 'ValidateUserGrant' ],
                           [ self::DRN_PARENT_ID ]);
    }

    /**
     *  Check if types have been registered and thus tokens can be validated.
     *
     *  @return bool
     */
    public static function IsInited()
        : bool
    {
        return count(self::$types) > 0;
    }

    /**
     *  Generate a JWT token. Fails when called from a non-HTTPS connection.
     *  Sets issuer, audience, id, subject, issued at, not before and the type claim.
     *  When passed in, the expiration claim is set and all key-value pairs from
     *  extraInfo are set as claims.
     *  Lastly the token is signed using the secret of this installation. The
     *  token should no longer be modified after that, else the signature becomes
     *  invalid.
     *
     *  @return Token
     */
    public static function GetToken(int $userId,                //!< in: ID of the user this token is associated with.
                                    string $type,               //!< in: internal type this token represents
                                    string $audience = '',      //!< in: URL base this token gives access to
                                    int $lifetime = 86400,      //!< in: lifetime of the token in seconds. If 0 the token never expires.
                                    array $extraInfo = NULL)    //!< in: additional data to store on the token
        : Token
    {
        if (    php_sapi_name() != 'cli'
             && !WebApp::isHTTPS()
             && !Globals::IsLocalhost()
             && !WebApp::IsFromPublicIp()
           )
            throw new DrnException("JWT tokens can only be delivered over HTTPS");

        if (!array_key_exists($type, self::$types))
            throw new DrnException("Cannot create JWT of unknown type ".$type);

        if (!empty(self::$types[$type]['claims']))
            foreach (self::$types[$type]['claims'] as $claim)
                if (!array_key_exists($claim, $extraInfo))
                    throw new DrnException("Missing claim ".$claim." to create JWT of type ".$type);

        $host = self::GetHost();
        $issueTime = time();
        $builder = new Builder();
        $builder->setIssuer($host)
                ->setAudience($host.$audience)
                // set a unique ID so we could in theory revoke individual tokens
                ->setId(bin2hex(random_bytes(16)))
                ->setSubject($userId)
                ->setIssuedAt($issueTime)
                ->setNotBefore($issueTime)
                ->set(self::DRN_TYPE, $type);

        if ($lifetime !== 0)
            $builder->setExpiration(time() + $lifetime);

        if (isset($extraInfo))
            foreach ($extraInfo as $key => $value)
                $builder->set($key, $value);

        $builder->sign(self::GetSigner(),
                       self::GetKey());
        return $builder->getToken();
    }

    /**
     *  Check a JWT on its validity. Runs checks on the default claims and
     *  executes the validator for the token type. Throws if something with the
     *  token is wrong.
     *
     *  @return Token
     */
    public static function VerifyToken(string $tokenString,     //!< in: raw JWT
                                       string $type,            //!< in: internal type of the token
                                       string $audience = '')   //!< in: URL base the token should give access to
        : Token
    {
        if (!array_key_exists($type, self::$types))
            throw new DrnException('Invalid JWT type');

        $host = self::GetHost();
        $fVerifyIssuer = !WebApp::IsFromPublicIp();
        $validationData = new ValidationData();
        if ($fVerifyIssuer)
        {
            $validationData->setIssuer($host);
            $validationData->setAudience($host.$audience);
        }

        $tokenParser = new Parser();
        $token = $tokenParser->parse($tokenString);
        $tokenType = $token->getClaim(self::DRN_TYPE);

        if ($tokenType !== $type)
            throw new DrnException('Provided JWT does not match given type');

        $requiredClaims = self::$types[$type]['claims'];
        if (!empty($requiredClaims))
        {
            foreach ($requiredClaims as $claim)
            {
                if (!$token->hasClaim($claim))
                    throw new DrnException("Token is not valid for the given type, missing ".$claim);
            }
        }

        // Validator for type should throw specific errors if invalid.
        $pfn = self::$types[$type]['validator'];
        $pfn($token, $validationData, $audience);

        if (!$token->verify(self::GetSigner(), self::GetKey()))
            throw new DrnException("JWT signature not valid");

        if (!$token->validate($validationData))
        {
            if ($fVerifyIssuer)
            {
                if ($token->getClaim(self::CLAIM_ISSUER) != $host)
                    throw new DrnException("Claim issuer ".Format::UTF8Quote($token->getClaim(self::CLAIM_ISSUER))
                                           ." does not match expected issuer "
                                           .Format::UTF8Quote($host)
                                           ." in JWT");
                if ($token->getClaim(self::CLAIM_AUDIENCE) != $host.$audience)
                    throw new DrnException(Format::UTF8Quote($token->getClaim(self::CLAIM_AUDIENCE))
                                           ." does not match expected audience "
                                           .Format::UTF8Quote($host.$audience)." in JWT");
            }
            throw new DrnException("token not valid at this time (either expired or not yet valid)");
        }

        return $token;
    }

    /**
     *  Extracts the JWT token from the Authorization header. Throws if no
     *  token is found, else returns the token as string.
     */
    public static function GetFromHeader()
        : string
    {
        $headers = getallheaders();
        if (!array_key_exists('Authorization', $headers))
            throw new DrnException("No authorization provided");

        $value = explode(' ', $headers['Authorization']);
        if (count($value) === 2 && strtolower($value[0]) === self::$tokenType)
            return $value[1];

        throw new DrnException("No valid authorization header provided");
    }

    /**
     *  Generates an authorization token for the given user based on the given
     *  API token.
     *  Throws if the API token is not valid, or when the user that the API token
     *  belongs to is not allowed to generate tokens.
     *  Else the token is returned.
     */
    public static function GetUserAuth(string $clientID,        //<! in: API token to generate token with
                                       int $userID,             //<! in: ID of user that should be authorized
                                       int $lifetime = 86400,   //<! in: Lifetime of the token. Infinite if 0
                                       array $data = [])        //<! in: Additional claims to store in the token
        : Token
    {
        try
        {
            $parentToken = self::VerifyToken($clientID, self::TYPE_API_CLIENT);
        }
        catch(DrnException $e)
        {
            throw new DrnException('Invalid parent token given: '.$e->getMessage());
        }

        $parentUser = $parentToken->getClaim(self::CLAIM_SUBJECT);
        if (    ($parentUser != self::FRONTEND_UID)
             && ((DrnACL::Find(ACL_SYS_TOKEN)->getUserAccess(User::Find($parentUser)) & ACCESS_CREATE) != ACCESS_CREATE))
            throw new DrnException('API token not allowed to generate a token');

        return self::GetToken($userID,
                              self::TYPE_USER_GRANT,
                              self::API_AUDIENCE,
                              $lifetime,
                              [ self::DRN_PARENT_ID => $parentToken->getClaim(self::CLAIM_ID) ] + $data);
    }
}
