<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social;

use NM\Services\SocialAuth\Social\Providers\FacebookProvider;
use NM\Services\SocialAuth\Social\Providers\GitHubProvider;
use NM\Services\SocialAuth\Social\Providers\GoogleProvider;
use NM\Services\SocialAuth\Social\Providers\MicrosoftProvider;

final class SocialProviderCatalog
{
    /**
     * @var array<string, array{
     *   class: class-string,
     *   label: string,
     *   pkce: bool,
     *   credentials_url: string,
     *   secret_help: string
     * }>
     */
    public const PROVIDERS = [
        'google' => [
            'class' => GoogleProvider::class,
            'label' => 'Google',
            'pkce' => true,
            'credentials_url' => 'https://console.cloud.google.com/apis/credentials',
            'secret_help' => 'Dans Google Cloud, créez un identifiant client OAuth de type application Web. Le secret figure dans cet identifiant.',
        ],
        'microsoft' => [
            'class' => MicrosoftProvider::class,
            'label' => 'Microsoft',
            'pkce' => true,
            'credentials_url' => 'https://entra.microsoft.com/',
            'secret_help' => 'Dans Microsoft Entra, ouvrez Inscriptions d’applications, puis Certificats et secrets pour créer un nouveau secret client.',
        ],
        'github' => [
            'class' => GitHubProvider::class,
            'label' => 'GitHub',
            'pkce' => true,
            'credentials_url' => 'https://github.com/settings/developers',
            'secret_help' => 'Dans Developer settings, créez une OAuth App puis générez son Client secret.',
        ],
        'facebook' => [
            'class' => FacebookProvider::class,
            'label' => 'Facebook',
            'pkce' => false,
            'credentials_url' => 'https://developers.facebook.com/apps/',
            'secret_help' => 'Dans Meta for Developers, créez une application avec Facebook Login ; le secret se trouve dans ses paramètres de base.',
        ],
    ];
}
