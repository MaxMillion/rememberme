<?php

namespace Birke\Rememberme;

use Birke\Rememberme\Token\DefaultToken;
use Birke\Rememberme\Token\TokenInterface;

class Authenticator
{
    /**
     * @var string
     */
    protected $cookieName = "PHP_REMEMBERME";

    /**
     * @var Cookie
     */
    protected $cookie;

    /**
     * @var Storage\StorageInterface
     */
    protected $storage;

    /**
     * @var Token\TokenInterface
     */
    protected $tokenGenerator;

    /**
     * Number of seconds in the future the cookie and storage will expire (defaults to 1 week)
     * @var int
     */
    protected $expireTime = 604800;

    /**
     * If the return from the storage was Birke\Rememberme\Storage\StorageInterface::TRIPLET_INVALID,
     * this is set to true
     * @var bool
     */
    protected $lastLoginTokenWasInvalid = false;

    /**
     * If the login token was invalid, delete all login tokens of this user
     * @var bool
     */
    protected $cleanStoredTokensOnInvalidResult = true;

    /**
     * Always clean expired tokens of users when login is called.
     *
     * Disabled by default for performance reasons, but useful for
     * hosted systems that can't run periodic scripts.
     *
     * @var bool
     */
    protected $cleanExpiredTokensOnLogin = false;

    /**
     * Additional salt to add more entropy when the tokens are stored as hashes.
     * @var string
     */
    protected $salt = "";

    /**
     * @param Storage\StorageInterface $storage
     * @param TokenInterface $tokenGenerator
     */
    public function __construct(Storage\StorageInterface $storage, TokenInterface $tokenGenerator = null)
    {
        $this->storage = $storage;
        $this->cookie = new Cookie();
        if ( is_null($tokenGenerator) ) {
            $tokenGenerator = new DefaultToken();
        }
        $this->tokenGenerator = $tokenGenerator;
    }

    /**
     * Check Credentials from cookie. Returns false if login was not successful, credential string if it was successful
     * @return bool|string
     */
    public function login()
    {
        $cookieValues = $this->getCookieValues();

        if (!$cookieValues) {
            return false;
        }

        $loginResult = false;
        if ($this->cleanExpiredTokensOnLogin) {
            $this->storage->cleanExpiredTokens(time() - $this->expireTime);
        }

        switch ($this->storage->findTriplet($cookieValues[0], $cookieValues[1] . $this->salt, $cookieValues[2] . $this->salt)) {

            case Storage\StorageInterface::TRIPLET_FOUND:
                $expire = time() + $this->expireTime;
                $newToken = $this->tokenGenerator->createToken();
                $this->storage->replaceTriplet($cookieValues[0], $newToken . $this->salt, $cookieValues[2] . $this->salt, $expire);
                $this->cookie->setCookie($this->cookieName, implode("|", array($cookieValues[0], $newToken, $cookieValues[2])), $expire);
                $loginResult = $cookieValues[0];
                break;

            case Storage\StorageInterface::TRIPLET_INVALID:
                $this->cookie->setCookie($this->cookieName, "", time() - $this->expireTime);
                $this->lastLoginTokenWasInvalid = true;

                if ($this->cleanStoredTokensOnInvalidResult) {
                    $this->storage->cleanAllTriplets($cookieValues[0]);
                }

                break;
        }
        return $loginResult;
    }

    /**
     * @return bool
     */
    public function cookieIsValid()
    {
        $cookieValues = $this->getCookieValues();

        if (!$cookieValues) {
            return false;
        }

        $state = $this->storage->findTriplet($cookieValues[0], $cookieValues[1] . $this->salt, $cookieValues[2] . $this->salt);
        return $state == Storage\StorageInterface::TRIPLET_FOUND;
    }

    /**
     * @param $credential
     * @return $this
     */
    public function createCookie($credential)
    {
        $newToken = $this->tokenGenerator->createToken();
        $newPersistentToken = $this->tokenGenerator->createToken();

        $expire = time() + $this->expireTime;

        $this->storage->storeTriplet($credential, $newToken . $this->salt, $newPersistentToken . $this->salt, $expire);
        $this->cookie->setCookie($this->cookieName, implode("|", array($credential, $newToken, $newPersistentToken)), $expire);

        return $this;
    }

    /**
     * Expire the rememberme cookie, unset $_COOKIE[$this->cookieName] value and
     * remove current login triplet from storage.
     * @return boolean
     */
    public function clearCookie()
    {
        if (empty($_COOKIE[$this->cookieName])) {
            return false;
        }

        $cookieValues = $this->getCookieValues();

        $this->cookie->setCookie($this->cookieName, "", time() - $this->expireTime);

        unset($_COOKIE[$this->cookieName]);

        if (count($cookieValues) < 3) {
            return false;
        }

        $this->storage->cleanTriplet($cookieValues[0], $cookieValues[2] . $this->salt);

        return true;
    }

    protected function expireIfNeeded()
    {

    }

    /**
     * @return string
     */
    public function getCookieName()
    {
        return $this->cookieName;
    }

    /**
     * @param $name
     * @return $this
     */
    public function setCookieName($name)
    {
        $this->cookieName = $name;
        return $this;
    }

    /**
     * @param Cookie $cookie
     * @return $this
     */
    public function setCookie(Cookie $cookie)
    {
        $this->cookie = $cookie;
        return $this;
    }

    /**
     * @return bool
     */
    public function loginTokenWasInvalid()
    {
        return $this->lastLoginTokenWasInvalid;
    }

    /**
     * @return Cookie
     */
    public function getCookie()
    {
        return $this->cookie;
    }

    /**
     * @param $state
     * @return Authenticator
     */
    public function setCleanStoredTokensOnInvalidResult($state)
    {
        $this->cleanStoredTokensOnInvalidResult = $state;
        return $this;
    }

    /**
     * @return bool
     */
    public function getCleanStoredTokensOnInvalidResult()
    {
        return $this->cleanStoredTokensOnInvalidResult;
    }
    
    /**
     * @return array
     */
    protected function getCookieValues()
    {
        // Cookie was not sent with incoming request
        if (empty($_COOKIE[$this->cookieName])) {
            return array();
        }

        $cookieValues = explode("|", $_COOKIE[$this->cookieName], 3);

        if (count($cookieValues) < 3) {
            return array();
        }

        return $cookieValues;
    }

    /**
     * Return how many seconds in the future that the cookie will expire
     * @return int
     */
    public function getExpireTime()
    {
        return $this->expireTime;
    }

    /**
     * @param int $expireTime How many seconds in the future the cookie will expire
     *
     * Default is 604800 (1 week)
     *
     * @return Authenticator
     */
    public function setExpireTime($expireTime)
    {
        $this->expireTime = $expireTime;

        return $this;
    }

    /**
     *
     * @return string
     */
    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * The salt is additional information that is added to the tokens to make
     * them more unique and secure. The salt is not stored in the cookie and
     * should not be saved in the storage.
     *
     * For example, to bind a token to an IP address use $_SERVER['REMOTE_ADDR'].
     * To bind a token to the browser (user agent), use $_SERVER['HTTP_USER_AGENT].
     * You could also use a long random string that is unique to your application.
     * @param string $salt
     */
    public function setSalt($salt)
    {
        $this->salt = $salt;
    }

    /**
     * @return boolean
     */
    public function isCleanExpiredTokensOnLogin()
    {
        return $this->cleanExpiredTokensOnLogin;
    }

    /**
     * @param boolean $cleanExpiredTokensOnLogin
     */
    public function setCleanExpiredTokensOnLogin($cleanExpiredTokensOnLogin)
    {
        $this->cleanExpiredTokensOnLogin = $cleanExpiredTokensOnLogin;
    }
}
