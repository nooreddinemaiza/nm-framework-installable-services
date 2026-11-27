<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Controllers;

use App\Models\User;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\Services\Registry\IndependentServiceManager;
use NM\Services\Quotes\Quotes\QuoteConfigurationStore;
use Throwable;

final class QuoteConfigurationController
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly QuoteConfigurationStore $configuration,
        private readonly IndependentServiceManager $services,
    ) {
    }

    public function save(Request $request): Response
    {
        $this->requireSuperAdmin();
        $csrf = new CsrfTokenManager($this->session);
        $input = $request->request->all();
        if (!$csrf->validateToken($input['csrf_token'] ?? null)) {
            $this->flash('Le jeton de sécurité est invalide. Actualisez la page puis réessayez.', 'danger');

            return Response::redirect('/dashboard/settings/services');
        }

        try {
            if (!in_array('quotes', $this->services->enabledIds(), true)) {
                throw new \RuntimeException('Le service Devis doit être installé et activé.');
            }
            unset($input['csrf_token']);
            $this->configuration->save($input);
            $this->flash('La configuration des devis a été enregistrée.', 'success');
        } catch (Throwable $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/settings/services');
    }

    private function requireSuperAdmin(): void
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (!$user instanceof User || !$user->is_active() || !$user->isSuper()) {
            throw new NotFoundHttpException();
        }
    }

    private function flash(string $message, string $variant): void
    {
        $this->session->addFlash('service_configuration', $message);
        $this->session->addFlash('service_configuration_variant', $variant);
    }
}
