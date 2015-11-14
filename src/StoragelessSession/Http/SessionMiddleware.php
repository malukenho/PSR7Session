<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare(strict_types=1);

namespace StoragelessSession\Http;

use DateTime;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\SetCookie;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\ValidationData;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use StoragelessSession\Session\DefaultSessionData;
use StoragelessSession\Session\SessionInterface;
use Zend\Stratigility\MiddlewareInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    const SESSION_CLAIM           = 'session-data';
    const SESSION_ATTRIBUTE       = 'session';
    const DEFAULT_COOKIE          = 'slsession';
    const DEFAULT_REFRESH_PERCENT = 10;

    /**
     * @var Signer
     */
    private $signer;

    /**
     * @var string
     */
    private $signatureKey;

    /**
     * @var string
     */
    private $verificationKey;

    /**
     * @var int
     */
    private $expirationTime;

    /**
     * @var int
     */
    private $refreshPercent;

    /**
     * @var Parser
     */
    private $tokenParser;

    /**
     * @var SetCookie
     */
    private $defaultCookie;

    /**
     * @param Signer    $signer
     * @param string    $signatureKey
     * @param string    $verificationKey
     * @param SetCookie $defaultCookie
     * @param Parser    $tokenParser
     * @param int       $expirationTime
     * @param int       $refreshPercent
     */
    public function __construct(
        Signer $signer,
        string $signatureKey,
        string $verificationKey,
        SetCookie $defaultCookie,
        Parser $tokenParser,
        int $expirationTime,
        int $refreshPercent = self::DEFAULT_REFRESH_PERCENT
    ) {
        $this->signer          = $signer;
        $this->signatureKey    = $signatureKey;
        $this->verificationKey = $verificationKey;
        $this->tokenParser     = $tokenParser;
        $this->defaultCookie   = clone $defaultCookie;
        $this->expirationTime  = $expirationTime;
        $this->refreshPercent  = $refreshPercent;
    }

    /**
     * This constructor simplifies instantiation when using HTTPS (REQUIRED!) and symmetric key encription
     *
     * @param string $symmetricKey
     * @param int    $expirationTime
     *
     * @return self
     */
    public static function fromSymmetricKeyDefaults(string $symmetricKey, int $expirationTime) : SessionMiddleware
    {
        return new self(
            new Signer\Hmac\Sha256(),
            $symmetricKey,
            $symmetricKey,
            SetCookie::create(self::DEFAULT_COOKIE)
                ->withSecure(true)
                ->withHttpOnly(true),
            new Parser(),
            $expirationTime
        );
    }

    /**
     * This constructor simplifies instantiation when using HTTPS (REQUIRED!) and asymmetric key encription
     * based on RSA keys
     *
     * @param string $privateRsaKey
     * @param string $publicRsaKey
     * @param int    $expirationTime
     *
     * @return self
     */
    public static function fromAsymmetricKeyDefaults(
        string $privateRsaKey,
        string $publicRsaKey,
        int $expirationTime
    ) : SessionMiddleware {
        return new self(
            new Signer\Rsa\Sha256(),
            $privateRsaKey,
            $publicRsaKey,
            SetCookie::create(self::DEFAULT_COOKIE)
                ->withSecure(true)
                ->withHttpOnly(true),
            new Parser(),
            $expirationTime
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     */
    public function __invoke(Request $request, Response $response, callable $out = null) : Response
    {
        $sessionContainer = $this->extractSessionContainer($this->parseToken($request));

        if (null !== $out) {
            $response = $out($request->withAttribute(self::SESSION_ATTRIBUTE, $sessionContainer), $response);
        }

        return $this->appendToken($sessionContainer, $response);
    }

    /**
     * Extract the token from the given request object
     *
     * @param Request $request
     *
     * @return Token|null
     */
    private function parseToken(Request $request)
    {
        $cookies   = $request->getCookieParams();
        $cookieName = $this->defaultCookie->getName();

        if (! isset($cookies[$cookieName])) {
            return null;
        }

        try {
            $token = $this->tokenParser->parse($cookies[$cookieName]);
        } catch (\InvalidArgumentException $invalidToken) {
            return null;
        }

        if (! $this->validateToken($token)) {
            return null;
        }

        return $token;
    }

    /**
     * @param Token $token
     *
     * @return bool
     */
    private function validateToken(Token $token) : bool
    {
        try {
            return $token->verify($this->signer, $this->verificationKey) && $token->validate(new ValidationData());
        } catch (\BadMethodCallException $invalidToken) {
            return false;
        }
    }

    /**
     * @param Token|null $token
     *
     * @return SessionInterface
     */
    public function extractSessionContainer(Token $token = null) : SessionInterface
    {
        if ($token) {
            $claims     = $token->getClaims();
            $issuedAt   = $claims['iat'] ? $claims['iat']->getValue() : null;
            $expiration = $claims['exp'] ? $claims['exp']->getValue() : null;
            $percent    = $expiration + ($issuedAt * $this->refreshPercent / 100);

            if ($percent >= (new DateTime())->getTimestamp()) {
                $this->defaultCookie->withExpires(new \DateTime('+10 minutes'));
            }
        }

        return $token
            ? DefaultSessionData::fromDecodedTokenData($token->getClaim(self::SESSION_CLAIM) ?? new \stdClass())
            : DefaultSessionData::newEmptySession();
    }

    /**
     * @param SessionInterface $sessionContainer
     * @param Response         $response
     *
     * @return Response
     *
     * @throws \InvalidArgumentException
     */
    private function appendToken(SessionInterface $sessionContainer, Response $response) : Response
    {
        if ($sessionContainer->isEmpty() && $sessionContainer->hasChanged()) {
            return FigResponseCookies::set($response, $this->getExpirationCookie());
        }

        if (! $sessionContainer->hasChanged()) {
            return $response;
        }

        return FigResponseCookies::set($response, $this->getTokenCookie($sessionContainer));
    }

    /**
     * @param SessionInterface $sessionContainer
     *
     * @return SetCookie
     */
    private function getTokenCookie(SessionInterface $sessionContainer) : SetCookie
    {
        $timestamp = (new \DateTime())->getTimestamp();

        return $this
            ->defaultCookie
            ->withValue(
                (new Builder())
                    ->setIssuedAt($timestamp)
                    ->setExpiration($timestamp + $this->expirationTime)
                    ->set(self::SESSION_CLAIM, $sessionContainer)
                    ->sign($this->signer, $this->signatureKey)
                    ->getToken()
            )
            ->withExpires($timestamp + $this->expirationTime);
    }

    /**
     * @return SetCookie
     */
    private function getExpirationCookie() : SetCookie
    {
        return $this
            ->defaultCookie
            ->withValue(null)
            ->withExpires((new \DateTime('-30 day'))->getTimestamp());
    }
}
