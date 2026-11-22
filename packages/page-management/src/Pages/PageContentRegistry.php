<?php

declare(strict_types=1);

namespace NM\Services\PageManagement\Pages;

use InvalidArgumentException;
use NM\Services\PageManagement\Services\RichTextSanitizer;

final class PageContentRegistry
{
    /** @var array<string, array{name: string, category: string, validate: callable, render: callable}> */
    private array $blocks = [];

    /** @var array<string, array{name: string, description: string, category: string, view: ?string}> */
    private array $layouts = [];

    /** @var array<string, array<string, mixed>> */
    private array $developerTemplates = [];

    /** @var list<array<string, mixed>> */
    private array $sectionExamples = [];

    /** @var array<string, array{name: string, description: string}> */
    private array $sections = [];

    public function __construct()
    {
        $this->registerLayout('default', 'Default', 'Conteneur standard.');
        $this->registerLayout('full_width', 'Full Width', 'Contenu sur toute la largeur.');
        $this->registerLayout('sidebar_left', 'Sidebar Left', 'Barre latérale à gauche.');
        $this->registerLayout('sidebar_right', 'Sidebar Right', 'Barre latérale à droite.');
        $this->registerLayout('blank', 'Blank', 'Sans structure commune imposée.');

        foreach (['content', 'header', 'footer', 'announcement', 'hero', 'sidebar', 'custom'] as $type) {
            $this->registerSection($type, ucfirst(str_replace('_', ' ', $type)));
        }

        $this->registerLayout('shelf', 'Étagère', 'Palette menthe, enseigne orange et sections sur planche.');
        $this->registerNativeBlocks();
        $this->registerShelfBlocks();
        $this->registerShelfExamples();
    }

    public function registerLayout(string $id, string $name, string $description = '', ?string $view = null): void
    {
        $this->assertIdentifier($id);
        $this->layouts[$id] = compact('name', 'description', 'view');
    }

    public function hasLayout(string $id): bool
    {
        return isset($this->layouts[$id]);
    }

    /** @return array<string, array{name: string, description: string, category: string, view: ?string}> */
    public function layouts(): array
    {
        return $this->layouts;
    }

    public function registerSection(string $type, string $name, string $description = ''): void
    {
        $this->assertIdentifier($type);
        $this->sections[$type] = ['name' => $name, 'description' => $description];
    }

    public function hasSection(string $type): bool
    {
        return isset($this->sections[$type]);
    }

    /** @return array<string, array{name: string, description: string}> */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $validator
     * @param callable(array<string, mixed>, array<string, mixed>): string $renderer
     */
    public function registerBlock(
        string $type,
        string $name,
        string $category,
        callable $validator,
        callable $renderer,
    ): void {
        $this->assertIdentifier($type);
        $this->blocks[$type] = [
            'name' => $name,
            'category' => $category,
            'validate' => $validator,
            'render' => $renderer,
        ];
    }

    public function hasBlock(string $type): bool
    {
        return isset($this->blocks[$type]);
    }

    /** @return array<string, array{name: string, category: string}> */
    public function blocks(): array
    {
        $result = [];
        foreach ($this->blocks as $type => $block) {
            $result[$type] = ['name' => $block['name'], 'category' => $block['category']];
        }

        return $result;
    }

    /** @param array<string, mixed> $configuration
     *  @return array<string, mixed>
     */
    public function validateBlock(string $type, array $configuration): array
    {
        $block = $this->blocks[$type] ?? throw new InvalidArgumentException("Type de bloc inconnu : {$type}.");
        $validated = ($block['validate'])($configuration);
        if (!is_array($validated)) {
            throw new InvalidArgumentException("La validation du bloc {$type} doit retourner un tableau.");
        }

        return $validated;
    }

    /** @param array<string, mixed> $configuration
     *  @param array<string, mixed> $context
     */
    public function renderBlock(string $type, array $configuration, array $context = []): string
    {
        $block = $this->blocks[$type] ?? throw new InvalidArgumentException("Type de bloc inconnu : {$type}.");

        return (string) ($block['render'])($this->validateBlock($type, $configuration), $context);
    }

    /**
     * @param array<string, mixed> $template
     */
    public function registerDeveloperTemplate(string $key, array $template): void
    {
        $this->assertIdentifier($key);
        foreach (['name', 'structure'] as $required) {
            if (!isset($template[$required])) {
                throw new InvalidArgumentException("Le template développeur doit définir {$required}.");
            }
        }
        if (!is_string($template['name']) || trim($template['name']) === '' || !is_array($template['structure'])) {
            throw new InvalidArgumentException('Les métadonnées du template développeur sont invalides.');
        }
        $this->developerTemplates[$key] = [
            'key' => $key,
            'name' => trim($template['name']),
            'description' => (string) ($template['description'] ?? ''),
            'category' => (string) ($template['category'] ?? ''),
            'structure' => $template['structure'],
            'settings' => is_array($template['settings'] ?? null) ? $template['settings'] : [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function developerTemplates(): array
    {
        return $this->developerTemplates;
    }

    /** @return list<array<string, mixed>> */
    public function sectionExamples(): array
    {
        return $this->sectionExamples;
    }

    /** @return array<string, mixed>|null */
    public function developerTemplate(string $key): ?array
    {
        return $this->developerTemplates[$key] ?? null;
    }

    private function registerNativeBlocks(): void
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->registerBlock('rich_text', 'Rich Text', 'content',
            static function (array $config): array {
                $html = $config['html'] ?? '';
                if (!is_string($html)) {
                    throw new InvalidArgumentException('Le contenu Rich Text doit être du texte.');
                }

                return ['html' => RichTextSanitizer::sanitize($html)];
            },
            static fn(array $config): string => (string) $config['html']);

        $this->registerBlock('heading', 'Heading', 'content',
            static function (array $config): array {
                $text = self::stringValue($config['text'] ?? '');
                $level = (int) ($config['level'] ?? 2);
                if ($text === '' || !in_array($level, [1, 2, 3, 4, 5, 6], true)) {
                    throw new InvalidArgumentException('Le bloc Heading requiert un texte et un niveau de 1 à 6.');
                }

                return ['text' => $text, 'level' => $level];
            },
            static fn(array $config): string => '<h' . $config['level'] . '>' . $escape($config['text']) . '</h' . $config['level'] . '>');

        $this->registerBlock('image', 'Image', 'media',
            static fn(array $config): array => [
                'url' => self::safeUrl($config['url'] ?? ''),
                'alt' => self::stringValue($config['alt'] ?? ''),
                'caption' => self::stringValue($config['caption'] ?? ''),
            ],
            static fn(array $config): string => '<figure><img src="' . $escape($config['url']) . '" alt="' . $escape($config['alt']) . '">'
                . ($config['caption'] !== '' ? '<figcaption>' . $escape($config['caption']) . '</figcaption>' : '')
                . '</figure>');

        $this->registerBlock('gallery', 'Gallery', 'media',
            static function (array $config): array {
                $images = $config['images'] ?? [];
                if (!is_array($images) || count($images) > 100) {
                    throw new InvalidArgumentException('La galerie doit contenir au plus 100 images.');
                }
                $normalized = [];
                foreach ($images as $image) {
                    if (!is_array($image)) {
                        throw new InvalidArgumentException('Chaque image de la galerie doit être un objet.');
                    }
                    $normalized[] = [
                        'url' => self::safeUrl($image['url'] ?? ''),
                        'alt' => self::stringValue($image['alt'] ?? ''),
                    ];
                }

                return ['images' => $normalized];
            },
            static fn(array $config): string => '<div class="page-gallery">' . implode('', array_map(
                static fn(array $image): string => '<img src="' . $escape($image['url']) . '" alt="' . $escape($image['alt']) . '">',
                $config['images']
            )) . '</div>');

        $this->registerBlock('video', 'Video', 'media',
            static function (array $config): array {
                $url = self::safeUrl($config['url'] ?? '');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (!in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
                    throw new InvalidArgumentException('La vidéo doit provenir de YouTube ou Vimeo.');
                }

                return ['url' => $url, 'title' => self::stringValue($config['title'] ?? '')];
            },
            static fn(array $config): string => '<a href="' . $escape($config['url']) . '" rel="noopener noreferrer">'
                . $escape($config['title'] !== '' ? $config['title'] : 'Voir la vidéo') . '</a>');

        $this->registerBlock('button', 'Button', 'actions',
            static fn(array $config): array => [
                'label' => self::requiredString($config['label'] ?? '', 'Le libellé du bouton est requis.'),
                'url' => self::safeUrl($config['url'] ?? ''),
                'target' => ($config['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            ],
            static fn(array $config): string => '<a class="page-button" href="' . $escape($config['url']) . '"'
                . ($config['target'] === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '')
                . '>' . $escape($config['label']) . '</a>');

        $this->registerBlock('call_to_action', 'Call to Action', 'actions',
            static fn(array $config): array => [
                'title' => self::requiredString($config['title'] ?? '', 'Le titre du bloc d’appel à l’action est requis.'),
                'text' => self::stringValue($config['text'] ?? ''),
                'label' => self::stringValue($config['label'] ?? ''),
                'url' => isset($config['url']) ? self::safeUrl($config['url']) : '',
            ],
            static fn(array $config): string => '<section class="page-cta"><h2>' . $escape($config['title']) . '</h2><p>'
                . $escape($config['text']) . '</p>' . ($config['label'] !== '' ? '<a href="' . $escape($config['url']) . '">'
                    . $escape($config['label']) . '</a>' : '') . '</section>');

        $this->registerBlock('columns', 'Columns', 'layout',
            static function (array $config): array {
                $columns = $config['columns'] ?? [];
                if (!is_array($columns) || count($columns) < 1 || count($columns) > 4) {
                    throw new InvalidArgumentException('Le bloc Columns requiert de 1 à 4 colonnes.');
                }

                return ['columns' => array_map(static fn(mixed $column): string =>
                    self::stringValue(is_array($column) ? ($column['text'] ?? '') : $column), $columns)];
            },
            static fn(array $config): string => '<div class="page-columns">' . implode('', array_map(
                static fn(string $column): string => '<div>' . $escape($column) . '</div>',
                $config['columns']
            )) . '</div>');

        $this->registerBlock('separator', 'Separator', 'layout',
            static fn(array $config): array => [],
            static fn(): string => '<hr>');

        $this->registerBlock('spacer', 'Spacer', 'layout',
            static function (array $config): array {
                $height = (int) ($config['height'] ?? 32);
                if ($height < 0 || $height > 500) {
                    throw new InvalidArgumentException('La hauteur du spacer doit être entre 0 et 500 pixels.');
                }

                return ['height' => $height];
            },
            static fn(array $config): string => '<div aria-hidden="true" style="height:' . $config['height'] . 'px"></div>');

        $this->registerBlock('banner', 'Banner', 'media',
            static fn(array $config): array => [
                'text' => self::requiredString($config['text'] ?? '', 'Le texte de la bannière est requis.'),
                'image' => isset($config['image']) ? self::safeUrl($config['image']) : '',
            ],
            static fn(array $config): string => '<section class="page-banner">'
                . ($config['image'] !== '' ? '<img src="' . $escape($config['image']) . '" alt="">' : '')
                . '<p>' . $escape($config['text']) . '</p></section>');

        $this->registerBlock('html', 'HTML', 'content',
            static function (array $config): array {
                $html = $config['html'] ?? '';
                if (!is_string($html)) {
                    throw new InvalidArgumentException('Le HTML doit être une chaîne.');
                }

                return ['html' => RichTextSanitizer::sanitize($html, rejectInvalidInlineImages: true)];
            },
            static fn(array $config): string => (string) $config['html']);
    }

    private function registerShelfBlocks(): void
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $this->registerBlock('shelf_hero', 'Étagère · enseigne', 'Style Étagère',
            static function (array $config): array {
                $label = self::stringValue($config['button_label'] ?? '');
                $url = $label === '' ? '' : self::safeUrl($config['url'] ?? '');

                return [
                    'title' => self::requiredString($config['title'] ?? '', 'Le titre de l’enseigne est requis.'),
                    'description' => self::stringValue($config['description'] ?? ''),
                    'button_label' => $label,
                    'url' => $url,
                ];
            },
            static fn(array $config): string => '<header class="space-y-5">'
                . '<div class="page-shelf-sign w-fit max-w-full">'
                . '<div class="flex justify-between px-8" aria-hidden="true"><span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span><span class="h-12 w-px bg-[#10302c] dark:bg-[#8fb8ad]"></span></div>'
                . '<h1 class="break-words rounded-xl bg-[#ff7a1a] px-6 py-4 text-4xl font-extrabold leading-none text-[#10302c] shadow-[0_6px_0_#10302c] [font-stretch:80%] dark:shadow-[0_6px_0_#3a5f59] sm:px-8 sm:text-7xl">'
                . $escape($config['title']) . '</h1></div>'
                . ($config['description'] !== ''
                    ? '<p class="max-w-2xl text-base leading-7">' . $escape($config['description']) . '</p>'
                    : '')
                . ($config['button_label'] !== ''
                    ? '<a class="inline-flex min-h-11 items-center justify-center rounded-md bg-[#ff7a1a] px-5 text-sm font-bold text-[#10302c] shadow-[0_4px_0_#10302c] transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ff7a1a] focus-visible:ring-offset-2 focus-visible:ring-offset-[#cfe8dc] dark:shadow-[0_4px_0_#3a5f59] dark:focus-visible:ring-offset-[#0c1c1a]" href="'
                        . $escape($config['url']) . '">' . $escape($config['button_label']) . '</a>'
                    : '')
                . '</header>');

        $this->registerBlock('shelf_panel', 'Étagère · panneau de lecture', 'Style Étagère',
            static fn(array $config): array => [
                'title' => self::requiredString($config['title'] ?? '', 'Le titre du panneau est requis.'),
                'text' => self::requiredString($config['text'] ?? '', 'Le texte du panneau est requis.'),
            ],
            static fn(array $config): string => '<article class="rounded-md bg-white p-6 shadow-[0_6px_0_#10302c] dark:bg-[#143632] dark:shadow-[0_6px_0_#3a5f59] sm:p-8">'
                . '<h2 class="text-3xl font-extrabold [font-stretch:80%] sm:text-4xl">' . $escape($config['title']) . '</h2>'
                . '<p class="mt-4 text-base leading-7">' . $escape($config['text']) . '</p></article>');

        $this->registerBlock('shelf_row', 'Étagère · rangée de cartes', 'Style Étagère',
            static function (array $config): array {
                $items = $config['items'] ?? [];
                if (!is_array($items) || count($items) < 1 || count($items) > 5) {
                    throw new InvalidArgumentException('Une rangée Étagère doit contenir de 1 à 5 cartes.');
                }

                $normalized = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new InvalidArgumentException('Chaque carte Étagère doit être un objet.');
                    }
                    $normalized[] = [
                        'title' => self::requiredString($item['title'] ?? '', 'Chaque carte doit avoir un titre.'),
                        'text' => self::stringValue($item['text'] ?? ''),
                    ];
                }

                return [
                    'label' => self::stringValue($config['label'] ?? 'Cartes de présentation'),
                    'items' => $normalized,
                ];
            },
            static function (array $config) use ($escape): string {
                $cards = [];
                foreach ($config['items'] as $index => $item) {
                    $tone = $index % 2 === 0
                        ? 'bg-[#ff7a1a] text-[#10302c]'
                        : 'bg-[#10302c] text-[#cfe8dc] dark:bg-[#cfe8dc] dark:text-[#0c1c1a]';
                    $cards[] = '<li class="w-52 shrink-0">'
                        . '<article class="flex min-h-44 flex-col justify-between rounded-t-md p-4 ' . $tone . '">'
                        . '<span class="text-sm font-bold">À personnaliser</span>'
                        . '<h3 class="mt-6 break-words text-3xl font-extrabold leading-none [font-stretch:70%]">'
                        . $escape($item['title']) . '</h3>'
                        . ($item['text'] !== '' ? '<p class="mt-3 text-sm leading-6">' . $escape($item['text']) . '</p>' : '')
                        . '</article></li>';
                }

                return '<div class="-mx-4 overflow-x-auto pb-3 [scrollbar-width:thin] sm:mx-0" role="region" tabindex="0" aria-label="'
                    . $escape($config['label']) . '"><div class="w-max min-w-full border-b-[14px] border-[#10302c] px-4 dark:border-[#3a5f59] sm:px-0">'
                    . '<ul class="flex items-end gap-3">' . implode('', $cards) . '</ul></div></div>';
            });
    }

    private function registerShelfExamples(): void
    {
        $this->sectionExamples = [
            [
                'key' => 'shelf-sign',
                'name' => 'Enseigne d’accueil',
                'description' => 'Un grand titre orange pour présenter votre page.',
                'type' => 'hero',
                'settings' => [],
                'blocks' => [[
                    'type' => 'shelf_hero',
                    'configuration' => [
                        'title' => 'Bienvenue chez vous',
                        'description' => 'Présentez ici votre activité en une phrase simple.',
                    ],
                ]],
            ],
            [
                'key' => 'shelf-cards',
                'name' => 'Rangée sur une planche',
                'description' => 'Jusqu’à cinq cartes pour présenter vos offres ou rubriques.',
                'type' => 'content',
                'settings' => [],
                'blocks' => [[
                    'type' => 'shelf_row',
                    'configuration' => [
                        'label' => 'Rubriques de présentation',
                        'items' => [
                            ['title' => 'Votre offre', 'text' => 'Décrivez ce que vous proposez.'],
                            ['title' => 'Votre équipe', 'text' => 'Présentez les personnes derrière le projet.'],
                            ['title' => 'Vos valeurs', 'text' => 'Partagez ce qui vous tient à cœur.'],
                        ],
                    ],
                ]],
            ],
            [
                'key' => 'shelf-panel',
                'name' => 'Panneau d’information',
                'description' => 'Un espace clair pour raconter une histoire ou donner des détails.',
                'type' => 'content',
                'settings' => [],
                'blocks' => [[
                    'type' => 'shelf_panel',
                    'configuration' => [
                        'title' => 'À propos de votre projet',
                        'text' => 'Remplacez ce texte par une information utile à vos visiteurs.',
                    ],
                ]],
            ],
        ];

        $this->registerDeveloperTemplate('shelf-home', [
            'name' => 'Accueil — boutique Étagère',
            'description' => 'Une enseigne, une rangée de cartes et un panneau pour présenter votre activité.',
            'category' => 'Style Étagère',
            'settings' => ['layout' => 'shelf'],
            'structure' => ['sections' => [
                [
                    'name' => 'Enseigne d’accueil',
                    'type' => 'hero',
                    'blocks' => [[
                        'type' => 'shelf_hero',
                        'configuration' => [
                            'title' => 'Bienvenue chez vous',
                            'description' => 'Présentez votre activité et ce que vos visiteurs trouveront ici.',
                        ],
                    ]],
                ],
                [
                    'name' => 'Vos rubriques',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_row',
                        'configuration' => [
                            'label' => 'Rubriques de présentation',
                            'items' => [
                                ['title' => 'Votre offre', 'text' => 'Présentez vos produits ou services.'],
                                ['title' => 'Votre équipe', 'text' => 'Faites découvrir les personnes derrière le projet.'],
                                ['title' => 'Vos valeurs', 'text' => 'Partagez ce qui vous distingue.'],
                            ],
                        ],
                    ]],
                ],
                [
                    'name' => 'Notre histoire',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_panel',
                        'configuration' => [
                            'title' => 'Une histoire à raconter',
                            'text' => 'Remplacez ce texte par quelques lignes sur votre activité, son origine ou ses engagements.',
                        ],
                    ]],
                ],
            ]],
        ]);

        $this->registerDeveloperTemplate('shelf-about', [
            'name' => 'Présentation — votre histoire',
            'description' => 'Des exemples de contenu pour présenter votre équipe, votre démarche et vos engagements.',
            'category' => 'Style Étagère',
            'settings' => ['layout' => 'shelf'],
            'structure' => ['sections' => [
                [
                    'name' => 'Enseigne de présentation',
                    'type' => 'hero',
                    'blocks' => [[
                        'type' => 'shelf_hero',
                        'configuration' => [
                            'title' => 'Qui sommes-nous ?',
                            'description' => 'Résumez ici votre activité et les personnes qui la font vivre.',
                        ],
                    ]],
                ],
                [
                    'name' => 'Notre histoire',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_panel',
                        'configuration' => [
                            'title' => 'Le début de votre histoire',
                            'text' => 'Expliquez comment votre projet est né et à qui il s’adresse.',
                        ],
                    ]],
                ],
                [
                    'name' => 'Nos engagements',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_row',
                        'configuration' => [
                            'label' => 'Vos engagements',
                            'items' => [
                                ['title' => 'Votre savoir-faire', 'text' => 'Décrivez votre expérience.'],
                                ['title' => 'Votre démarche', 'text' => 'Expliquez votre façon de travailler.'],
                                ['title' => 'Votre équipe', 'text' => 'Présentez les personnes qui vous accompagnent.'],
                            ],
                        ],
                    ]],
                ],
            ]],
        ]);

        $this->registerDeveloperTemplate('shelf-contact', [
            'name' => 'Contact — informations pratiques',
            'description' => 'Un gabarit pour indiquer clairement comment vous joindre et où vous trouver.',
            'category' => 'Style Étagère',
            'settings' => ['layout' => 'shelf'],
            'structure' => ['sections' => [
                [
                    'name' => 'Enseigne de contact',
                    'type' => 'hero',
                    'blocks' => [[
                        'type' => 'shelf_hero',
                        'configuration' => [
                            'title' => 'Nous contacter',
                            'description' => 'Ajoutez une courte phrase pour inviter vos visiteurs à vous écrire.',
                        ],
                    ]],
                ],
                [
                    'name' => 'Informations pratiques',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_panel',
                        'configuration' => [
                            'title' => 'Comment nous joindre ?',
                            'text' => 'Indiquez ici votre adresse e-mail, votre numéro de téléphone ou vos horaires.',
                        ],
                    ]],
                ],
                [
                    'name' => 'Coordonnées',
                    'type' => 'content',
                    'blocks' => [[
                        'type' => 'shelf_row',
                        'configuration' => [
                            'label' => 'Vos coordonnées',
                            'items' => [
                                ['title' => 'E-mail', 'text' => 'Ajoutez votre adresse de contact.'],
                                ['title' => 'Téléphone', 'text' => 'Ajoutez votre numéro de téléphone.'],
                                ['title' => 'Adresse', 'text' => 'Ajoutez votre adresse ou votre zone d’activité.'],
                            ],
                        ],
                    ]],
                ],
            ]],
        ]);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $identifier)) {
            throw new InvalidArgumentException('Identifiant de page invalide.');
        }
    }

    private static function stringValue(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException('Une valeur textuelle est attendue.');
        }

        return trim((string) $value);
    }

    private static function requiredString(mixed $value, string $message): string
    {
        $string = self::stringValue($value);
        if ($string === '') {
            throw new InvalidArgumentException($message);
        }

        return $string;
    }

    private static function safeUrl(mixed $value): string
    {
        $url = self::requiredString($value, 'Une URL est requise.');
        $parts = parse_url($url);
        $local = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\');
        $external = is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
        if (!$local && !$external) {
            throw new InvalidArgumentException('URL invalide.');
        }

        return $url;
    }
}
