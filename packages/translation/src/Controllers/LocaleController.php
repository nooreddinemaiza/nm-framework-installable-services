<?php

declare(strict_types=1);

namespace NM\Services\Translation\Controllers;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Translation\LocaleManager;
use NM\Services\Translation\Services\TranslationServiceState;

final class LocaleController
{
    public function __construct(
        private readonly LocaleManager $locales,
        private readonly SessionInterface $session,
        private readonly TranslationServiceState $serviceState,
    ) {
    }

    public function update(Request $request): Response
    {
        $this->serviceState->assertEnabled();
        $token = $request->input('csrf_token');
        if (!is_string($token) || !$this->session->verifyCsrfToken($token)) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $locale = $request->input('locale');
        if (!is_string($locale) || !$this->locales->isSupported($locale)) {
            return new Response('Locale non supportée.', 422);
        }

        $this->locales->set($locale);

        $returnTo = $request->input('return_to', '/');
        if (!is_string($returnTo)
            || !str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $returnTo) === 1) {
            $returnTo = '/';
        }

        return Response::redirect($returnTo, 303);
    }
}
