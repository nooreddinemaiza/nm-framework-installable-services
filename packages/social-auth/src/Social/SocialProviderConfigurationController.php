<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use App\Models\User;
use Core\Database\Connection;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Security\CsrfTokenManager;
use Core\Services\Registry\IndependentServiceManager;
use Throwable;

final class SocialProviderConfigurationController
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly Connection $connection,
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
            if (!in_array('social-auth', $this->services->enabledIds(), true)) {
                throw new \RuntimeException('Le service de connexion sociale doit être installé et activé.');
            }

            $settings = [];
            $errors = [];
            $appUrl = rtrim((string) app()->env('APP_URL', ''), '/');

            foreach (SocialProviderCatalog::PROVIDERS as $id => $definition) {
                $prefix = 'SOCIAL_' . strtoupper($id) . '_';
                $enabled = $input[$id . '_enabled'] ?? null;
                $clientId = $input[$id . '_client_id'] ?? null;
                $clientSecret = $input[$id . '_client_secret'] ?? '';
                $redirectUri = $input[$id . '_redirect_uri'] ?? null;

                if (!is_string($enabled) || !in_array($enabled, ['0', '1'], true)) {
                    $errors[] = $definition['label'] . ' : état d’activation invalide.';
                    continue;
                }
                if (!is_string($clientId) || !is_string($clientSecret) || !is_string($redirectUri)) {
                    $errors[] = $definition['label'] . ' : configuration invalide.';
                    continue;
                }

                $clientId = trim($clientId);
                $redirectUri = trim($redirectUri);
                if (strlen($clientId) > 512 || strlen($clientSecret) > 4096 || strlen($redirectUri) > 2048) {
                    $errors[] = $definition['label'] . ' : une valeur dépasse la longueur autorisée.';
                    continue;
                }
                if (preg_match('/[\r\n\0]/', $clientSecret) === 1) {
                    $errors[] = $definition['label'] . ' : le Client secret contient des caractères non autorisés.';
                    continue;
                }

                if ($redirectUri !== '' && !$this->isHttpUrl($redirectUri)) {
                    $errors[] = $definition['label'] . ' : l’URL de callback doit être une URL HTTP(S) absolue valide.';
                    continue;
                }

                if ($enabled === '1') {
                    $effectiveSecret = $clientSecret !== ''
                        ? $clientSecret
                        : (string) environment()->get($prefix . 'CLIENT_SECRET', '');
                    $effectiveRedirect = $redirectUri !== ''
                        ? $redirectUri
                        : (string) app()->env(
                            $prefix . 'REDIRECT_URI',
                            $appUrl . '/connexion/social/' . $id . '/callback'
                        );
                    if ($clientId === '') {
                        $errors[] = $definition['label'] . ' : renseignez le Client ID avant l’activation.';
                        continue;
                    }
                    if ($effectiveSecret === '') {
                        $errors[] = $definition['label'] . ' : le Client secret est délivré par la plateforme, pas généré par le framework. Créez une application OAuth chez le fournisseur puis saisissez son secret dans ce formulaire.';
                        continue;
                    }
                    if (!$this->isHttpUrl($effectiveRedirect)) {
                        $errors[] = $definition['label'] . ' : configurez une URL de callback absolue valide.';
                        continue;
                    }
                    $redirectUri = $effectiveRedirect;
                }

                $settings[$id] = [
                    'enabled' => $enabled === '1',
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                ];
            }

            if ($errors !== []) {
                throw new \InvalidArgumentException(implode(' ', $errors));
            }

            $environmentSecrets = [];
            foreach (SocialProviderCatalog::PROVIDERS as $id => $_definition) {
                $clientSecret = $input[$id . '_client_secret'] ?? '';
                if (is_string($clientSecret) && $clientSecret !== '') {
                    $environmentSecrets['SOCIAL_' . strtoupper($id) . '_CLIENT_SECRET'] = $clientSecret;
                }
            }
            if ($environmentSecrets !== [] && !environment()->merge($environmentSecrets)->save()) {
                throw new \RuntimeException('Impossible d’enregistrer les secrets dans le fichier d’environnement du serveur.');
            }

            (new SocialProviderSettingsStore($this->connection))->save($settings);
            $this->flash('La configuration des fournisseurs sociaux a été enregistrée.', 'success');
        } catch (Throwable $exception) {
            $this->flash($exception->getMessage(), 'danger');
        }

        return Response::redirect('/dashboard/settings/services');
    }

    private function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
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
