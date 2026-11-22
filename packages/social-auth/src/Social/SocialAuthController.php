<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Exceptions\SocialAuthException;
use App\Models\LoginLog;
use App\Services\Authentication;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Middleware\AuthMiddleware;
use Core\Security\Auth\AuthManager;

final class SocialAuthController
{
    private const PROFILE_URL = '/dashboard/account/profile';

    public function __construct(
        private readonly SocialAuthService $service,
        private readonly AuthManager $auth,
        private readonly SessionInterface $session,
        private readonly Authentication $authentication,
    ) {
    }

    /** GET /connexion/social/{provider} : démarre la connexion. */
    public function start(Request $request, string $provider): Response
    {
        if ($this->auth->guard('session')->check()) {
            return Response::redirect('/dashboard');
        }
        try {
            return Response::redirect($this->service->begin($provider, SocialStateStore::MODE_LOGIN));
        } catch (SocialAuthException $e) {
            return $this->fail($e, $request, $provider, '/connexion');
        }
    }

    /**
     * GET /connexion/social/{provider}/callback : protégé par `state`
     * (pas de jeton CSRF de formulaire sur ce flux).
     */
    public function callback(Request $request, string $provider): Response
    {
        $currentId = $this->auth->guard('session')->id();
        try {
            $result = $this->service->complete($provider, $request->query->all(), $currentId);
        } catch (SocialAuthException $e) {
            return $this->fail($e, $request, $provider, $currentId === null ? '/connexion' : self::PROFILE_URL, $currentId === null);
        }

        if (!$result->openedSession()) {
            if ($result->requiresTwoFactor()) {
                LoginLog::record(
                    userId: (int) $result->userId,
                    email: $result->email,
                    successful: false,
                    failureReason: 'two_factor_pending',
                    ipAddress: $request->getClientIp() ?? '0.0.0.0',
                    userAgent: $request->header('User-Agent'),
                );

                return $this->authentication->beginTwoFactorChallenge($result->userId, $request);
            }

            $this->flash('Fournisseur associé à votre compte.', 'success');

            return Response::redirect(self::PROFILE_URL . '?a1=1&a2=4');
        }

        LoginLog::record(
            userId: (int) $result->userId,
            email: $result->email,
            successful: true,
            failureReason: null,
            ipAddress: $request->getClientIp() ?? '0.0.0.0',
            userAgent: $request->header('User-Agent'),
        );

        return Response::redirect($this->intendedUrl() ?? '/dashboard');
    }

    /** POST (CSRF) : démarre l'association d'un fournisseur au compte connecté. */
    public function link(Request $request, string $provider): Response
    {
        $userId = $this->auth->guard('session')->id();
        try {
            if ($userId === null) {
                throw new SocialAuthException(SocialAuthException::LINK_SESSION_MISMATCH);
            }

            return Response::redirect($this->service->begin($provider, SocialStateStore::MODE_LINK, $userId), 303);
        } catch (SocialAuthException $e) {
            return $this->fail($e, $request, $provider, self::PROFILE_URL, false);
        }
    }

    /** POST (CSRF) : dissocie un fournisseur du compte connecté. */
    public function unlink(Request $request, string $provider): Response
    {
        $userId = $this->auth->guard('session')->id();
        try {
            if ($userId === null) {
                throw new SocialAuthException(SocialAuthException::LINK_SESSION_MISMATCH);
            }
            $this->service->unlink($userId, $provider);
            $this->flash('Fournisseur dissocié.', 'success');

            return Response::redirect(self::PROFILE_URL . '?a1=1&a2=4', 303);
        } catch (SocialAuthException $e) {
            return $this->fail($e, $request, $provider, self::PROFILE_URL, false);
        }
    }

    private function fail(SocialAuthException $e, Request $request, string $provider, string $to, bool $log = true): Response
    {
        if ($log) {
            LoginLog::record(
                userId: null,
                email: '',
                successful: false,
                failureReason: substr('social_' . $e->reason . ':' . preg_replace('/[^a-z0-9_-]/', '', $provider), 0, 100),
                ipAddress: $request->getClientIp() ?? '0.0.0.0',
                userAgent: $request->header('User-Agent'),
            );
        }
        $this->flash($e->publicMessage(), 'error');

        return Response::redirect($to);
    }

    private function flash(string $message, string $variant): void
    {
        $this->session->addFlash('message', $message);
        $this->session->addFlash('alertVariant', $variant);
    }

    /** Même règle anti-redirection ouverte que Authentication : chemin interne uniquement. */
    private function intendedUrl(): ?string
    {
        $url = $this->session->get(AuthMiddleware::INTENDED_URL_SESSION_KEY);
        $this->session->delete(AuthMiddleware::INTENDED_URL_SESSION_KEY);

        if (!is_string($url) || $url === '' || $url[0] !== '/' || (isset($url[1]) && ($url[1] === '/' || $url[1] === '\\'))) {
            return null;
        }

        return $url;
    }
}
