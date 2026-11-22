<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Controllers;

use Core\Exceptions\Http\FailedProcessingException;
use Core\Exceptions\InputErrorException;
use Core\Exceptions\InvalidCredentianlsException;
use Core\Http\JsonResponse;
use Core\Http\RedirectResponse;
use Core\Http\Response;
use Core\Security\CsrfTokenManager;
use Core\Http\SessionInterface;
use Core\Support\Data\Data;

abstract class Controller
{
    protected CsrfTokenManager $csrfManager;
    protected array $data = [];
    protected const TOKEN_NAME = 'csrf_token';
    public function redirect(string $url, int $statusCode = 302, array $headers = [], string $prefix = '/dashboard/'): Response
    {
        return new RedirectResponse($prefix . $url, $statusCode, $headers);
    }
    protected function register_csrf(SessionInterface $session, ?string $tokenType = null): void
    {
        if (!isset($this->csrfManager)) {
            $this->csrfManager = new CsrfTokenManager($session, self::TOKEN_NAME);
        }
        if ($tokenType) {
            switch ($tokenType) {
                case 'token':
                    $this->data['csrf_token'] = $this->csrfManager->generateToken();
                    break;
                case 'metaTag':
                    $this->data['csrf_token'] = $this->csrfManager->getMetaTag();
                    break;

                default:
                    $this->data['csrf_token'] = $this->csrfManager->getInputField();
                    break;
            }
        }
    }
    protected function validatePost(
        array|Data $data,
        array $only = [],
        array $rules = [],
        array $labels = [],
        bool $csrf_validate = true,
        bool $noEmpty = false
    ): Data {
        if (!is_array($data) && !($data instanceof Data)) {
            throw new FailedProcessingException('Impossible de valider les données, le type passé est inconnu: [' . gettype($data) . ']');
        }
        if (is_array($data)) {
            $data = Data::create($data);
        }
        if ($only) {
            $data = $data->only($only);
        }

        $data = $data->sanitize();
        if ($noEmpty)
            $data = $data->removeEmpty()
                ->removeNulls();

        if ($labels)
            Data::addLabel($labels);
        if ($rules) {
            $errors = $data->validate($rules);
            if ($errors) {
                throw new InputErrorException(errors: $errors);
            }
        }

        if ($csrf_validate && $data->has(self::TOKEN_NAME)) {
            $verified = $this->csrfManager->validateToken($data[self::TOKEN_NAME]);
            if (!$verified) {
                throw new InvalidCredentianlsException(errors: [
                    "Le jeton CSRF est invalide ou a expiré. Veuillez actualiser la page et réessayer."
                ]);
            }
        }
        return $data;
    }

    protected function jsonResponse(
        mixed $data,
        int $status = 200,
        array $headers = []
    ) {
        return new JsonResponse($data, $status, $headers);
    }
}
