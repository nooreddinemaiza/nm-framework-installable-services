<?php

declare(strict_types=1);

namespace NM\Services\Messaging\Controllers;

use App\Helpers\NavbarFactory;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Role;
use App\Models\User;
use App\Services\WebsocketSettingsService;
use Core\Exceptions\Http\FailedProcessingException;
use Core\Exceptions\Http\GateException;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\InputErrorException;
use Core\Exceptions\InvalidCredentianlsException;
use Core\Http\JsonResponse;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SessionInterface;
use Core\Http\UploadedFile;
use Core\Security\CsrfTokenManager;
use Core\View\ViewFactory;
use Core\Support\Date as SupportDate;
use InvalidArgumentException;
use NM\Services\Messaging\Models\Conversation;
use NM\Services\Messaging\Models\ConversationGroupPolicy;
use NM\Services\Messaging\Models\ConversationMessage;
use NM\Services\Messaging\Models\ConversationMessageReceipt;
use NM\Services\Messaging\Models\ConversationPolicy;
use NM\Services\Messaging\Models\ConversationUser;
use PDOException;
use RuntimeException;
use Throwable;

final class ConversationController extends Controller
{
    private const MAX_IMAGE_BYTES = 512000;
    private const MAX_DEFAULT_IMAGE_BYTES = 2097152;
    private const MAX_MESSAGE_CHARS = 4000;
    private const DEFAULT_MEDIA_IMAGE = 'conversation-media.png';
    private const ADMIN_FILTER_KEYS = ['search', 'type', 'status', 'kind', 'conversation_id', 'date_from', 'date_to'];
    public function __construct(
        private readonly ViewFactory $factory,
        private readonly SessionInterface $session,
    ) {
        $this->csrfManager = new CsrfTokenManager($session, 'csrf_token');
    }
    public function adminList(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $user = $this->requireModerator();
            $this->purgeExpiredConversations();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: [...self::ADMIN_FILTER_KEYS, 'sort_by', 'sort_dir', 'page', 'per_page'],
            )->toArray();
            $filters = $this->normalizeAdminFilters($body);
            [$page, $perPage] = $this->pageParams($body);

            $total = (int) $this->adminConversationsQuery($user, $filters, false)->count();
            $meta = $this->pageMeta($page, $perPage, $total);

            $sortMap = [
                'id' => 'c.id',
                'subject' => 'c.subject',
                'status' => 'c.status',
                'messages' => 'c.message_count',
                'expires_at' => 'c.expires_at',
                'created_at' => 'c.created_at',
            ];
            [$sortBy, $dir] = $this->sortParams($body, $sortMap, 'c.created_at', 'DESC');

            $conversations = $this->adminConversationsQuery($user, $filters, true)
                ->orderBy($sortBy, $dir)
                ->limit($perPage)
                ->offset(($meta['current_page'] - 1) * $perPage)
                ->get();

            $rows = [];
            foreach ($conversations as $conversation) {
                if (!$conversation instanceof Conversation) {
                    continue;
                }
                $expiresAt = $conversation->getAttribute('expires_at');
                $rows[] = [
                    'id' => (int) $conversation->getKey(),
                    'subject' => (string) ($conversation->getAttribute('subject') ?? ''),
                    'is_group' => (bool) $conversation->getAttribute('is_group'),
                    'status' => (string) $conversation->getAttribute('status'),
                    'preserved' => $conversation->getAttribute('preserved_at') !== null,
                    'message_count' => (int) $conversation->getAttribute('message_count'),
                    'message_limit' => (int) $conversation->getAttribute('message_limit'),
                    'participants' => count($conversation->participantIds()),
                    'can_control' => $this->canControlConversation($user, $conversation),
                    'can_preserve' => $this->canDoAction($user, $conversation, 'preserve'),
                    'can_suspend' => $this->canDoAction($user, $conversation, 'terminate'),
                    'can_delete' => $this->canDoAction($user, $conversation, 'delete'),
                    'created_at' => (string) $conversation->getAttribute('created_at'),
                    'created_at_human' => SupportDate::diffForHumans($conversation->getAttribute('created_at')),
                    'expires_at' => $expiresAt ? SupportDate::diffForHumans($expiresAt) : '—',
                ];
            }

            return $this->jsonResponse(['success' => true, 'data' => $rows, 'meta' => $meta]);
        });
    }
    public function adminBulkAction(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $user = $this->requireModerator();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: ['ids', 'action'],
                rules: [
                    'ids' => 'required|array|min:1',
                    'action' => 'required|in:preserve,terminate,delete',
                ]
            )->toArray();

            $ids = $body['ids'] ?? null;
            $action = (string) ($body['action'] ?? '');
            if (!is_array($ids) || $ids === [] || count($ids) > 200) {
                throw new InvalidArgumentException('Sélectionnez entre 1 et 200 conversations.');
            }
            if (!in_array($action, ['preserve', 'terminate', 'delete'], true)) {
                throw new InvalidArgumentException('Action de conversation invalide.');
            }

            $done = 0;
            $skipped = 0;
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                $conversation = $id > 0 ? Conversation::find($id) : null;
                if (!$conversation instanceof Conversation) {
                    $skipped++;
                    continue;
                }
                $allowed = $allowed = $this->canDoAction($user, $conversation, $action);
                if (!$allowed) {
                    $skipped++;
                    continue;
                }
                if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
                    $this->terminateConversation($conversation);
                }

                if ($action === 'preserve') {
                    if (!$conversation->isActive() && !$conversation->isPreserved()) {
                        $skipped++;
                        continue;
                    }
                    $conversation->update(['preserved_at' => $conversation->getAttribute('preserved_at') ?? date('Y-m-d H:i:s')]);
                    $this->preserveConversationMedia($conversation);
                } elseif ($action === 'terminate') {
                    if (!$conversation->isActive()) {
                        $skipped++;
                        continue;
                    }
                    $this->terminateConversation($conversation);
                } else { // delete
                    if ($conversation->isActive()) {
                        $skipped++;
                        continue;
                    }
                    $this->deleteConversation($conversation);
                }
                $done++;
            }

            return $this->jsonResponse([
                'success' => true,
                'done' => $done,
                'skipped' => $skipped,
                'message' => $done . ' conversation(s) traitée(s)' . ($skipped > 0 ? ', ' . $skipped . ' ignorée(s).' : '.'),
            ]);
        });
    }
    public function adminStats(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $this->requireSuperAdmin();

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'conversations_total' => (int) Conversation::table()->count(),
                    'conversations_active' => (int) Conversation::table()->where('status', '=', 'active')->count(),
                    'conversations_preserved' => (int) Conversation::table()->whereNotNull('preserved_at')->count(),
                    'messages_total' => (int) ConversationMessage::table()->count(),
                    'messages_images' => (int) ConversationMessage::table()->where('message_type', '=', 'image')->count(),
                    'messages_today' => (int) ConversationMessage::table()
                        ->where('created_at', '>=', date('Y-m-d') . ' 00:00:00')->count(),
                ],
            ]);
        });
    }
    public function adminMessages(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $this->requireSuperAdmin();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: [...self::ADMIN_FILTER_KEYS, 'sort_by', 'sort_dir', 'page', 'per_page'],
            )->toArray();

            $filters = $this->normalizeAdminFilters($body);
            [$page, $perPage] = $this->pageParams($body);

            $total = (int) $this->adminMessagesQuery($filters, false)->count();
            $meta = $this->pageMeta($page, $perPage, $total);

            $sortMap = [
                'id' => 'm.id',
                'sender' => 'u.name',
                'conversation' => 'c.id',
                'type' => 'm.message_type',
                'created_at' => 'm.created_at',
            ];
            [$sortBy, $dir] = $this->sortParams($body, $sortMap, 'm.id', 'DESC');

            $messages = $this->adminMessagesQuery($filters, true)
                ->orderBy($sortBy, $dir)
                ->limit($perPage)
                ->offset(($meta['current_page'] - 1) * $perPage)
                ->get();

            $participants = $this->participantLabels(array_values(array_unique(array_map(
                static fn(ConversationMessage $m): int => (int) $m->getAttribute('conversation_id'),
                $messages
            ))));

            $rows = [];
            foreach ($messages as $message) {
                $id = (int) $message->getKey();
                $conversationId = (int) $message->getAttribute('conversation_id');
                $isImage = $message->getAttribute('message_type') === 'image';
                $available = $isImage
                    && $message->getAttribute('media_deleted_at') === null
                    && $message->getAttribute('media_path') !== null;
                $text = (string) $message->getAttribute('body');
                $subject = (string) ($message->getAttribute('subject') ?? '');
                $isGroup = (bool) $message->getAttribute('is_group');

                $rows[] = [
                    'id' => $id,
                    'conversation_id' => $conversationId,
                    'conversation_label' => $subject !== '' ? $subject : ($isGroup ? 'Groupe #' . $conversationId : 'Privée #' . $conversationId),
                    'is_group' => $isGroup,
                    'conversation_status' => (string) $message->getAttribute('conversation_status'),
                    'conversation_preserved' => $message->getAttribute('preserved_at') !== null,
                    'participants' => $participants[$conversationId] ?? '',
                    'sender_id' => (int) $message->getAttribute('sender_id'),
                    'sender_name' => (string) ($message->getAttribute('sender_name') ?? 'Utilisateur supprimé'),
                    'sender_username' => (string) ($message->getAttribute('sender_username') ?? ''),
                    'type' => $isImage ? 'image' : 'text',
                    'body' => $text,
                    'preview' => mb_strlen($text) > 160 ? mb_substr($text, 0, 160) . '…' : $text,
                    'media_available' => $available,
                    'media_preserved' => (bool) $message->getAttribute('media_preserved'),
                    'media_url' => $available ? '/dashboard/conversations/admin/messages/media?id=' . $id : null,
                    'created_at' => (string) $message->getAttribute('created_at'),
                    'created_at_human' => SupportDate::diffForHumans($message->getAttribute('created_at')),
                ];
            }

            return $this->jsonResponse(['success' => true, 'data' => $rows, 'meta' => $meta]);
        });
    }
    public function adminMessageMedia(Request $request): Response
    {
        $this->requireSuperAdmin();
        if (!$request->query('id')) {
            return new JsonResponse([
                'data' => [],
                'sucess' => false
            ]);
        }
        $message = ConversationMessage::find($this->validatedId($request->query('id')));
        if (
            !$message instanceof ConversationMessage
            || $message->getAttribute('message_type') !== 'image'
            || $message->getAttribute('media_deleted_at') !== null
            || (string) ($message->getAttribute('media_path') ?? '') === ''
        ) {
            return $this->defaultMediaResponse();
        }
        $absolute = $this->mediaDirectory((int) $message->getAttribute('conversation_id'))
            . DIRECTORY_SEPARATOR . basename((string) $message->getAttribute('media_path'));
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;
        if ($contents === false) {
            return $this->defaultMediaResponse();
        }

        return new Response($contents, 200, [
            'Content-Type' => (string) $message->getAttribute('media_mime_type'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
    public function adminRemoveMessage(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $this->requireSuperAdmin();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: ['id'],
                rules: [
                    'id' => 'required|integer|min:1'
                ]
            )->toArray();

            if ($this->deleteMessages([$this->validatedId($body['id'] ?? null)]) === 0) {
                throw new NotFoundHttpException('Message introuvable.');
            }

            return $this->jsonResponse(['success' => true, 'deleted' => 1, 'message' => 'Le message a été supprimé.']);
        });
    }
    public function adminRemoveMessages(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $this->requireSuperAdmin();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: ['ids'],
                rules: [
                    'ids' => 'required|array|min:1'
                ]
            )->toArray();

            $ids = $body['ids'] ?? null;
            if (!is_array($ids) || $ids === [] || count($ids) > 500) {
                throw new InvalidArgumentException('Sélectionnez entre 1 et 500 messages.');
            }
            $deleted = $this->deleteMessages($ids);

            return $this->jsonResponse([
                'success' => true,
                'deleted' => $deleted,
                'message' => $deleted . ' message(s) supprimé(s).',
            ]);
        });
    }
    /**
     * DELETE /dashboard/conversations/admin/messages/remove-all  { expected_total, ...filtres }
     * Supprime TOUS les messages correspondant aux filtres envoyés (sans filtre = toute la plateforme).
     * `expected_total` = nombre affiché à l'écran au moment de la confirmation : si le total a changé
     * entre-temps, l'opération est refusée.
     */
    public function adminRemoveAllMessages(Request $request): Response
    {
        return $this->adminJson(function () use ($request): Response {
            $this->requireSuperAdmin();

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: [...self::ADMIN_FILTER_KEYS, 'expected_total'],
            )->toArray();

            $filters = $this->normalizeAdminFilters($body);

            $expected = filter_var($body['expected_total'] ?? null, FILTER_VALIDATE_INT);
            $total = (int) $this->adminMessagesQuery($filters, false)->count();
            if ($expected === false || $expected !== $total) {
                throw new InvalidArgumentException('Le nombre de messages a changé. Actualisez la liste avant de recommencer.');
            }

            $deleted = 0;
            for ($i = 0; $i < 1000; $i++) {
                $ids = array_map('intval', $this->adminMessagesQuery($filters, false)
                    ->select('m.id')
                    ->limit(200)
                    ->pluck('m.id'));
                if ($ids === []) {
                    break;
                }
                $batch = $this->deleteMessages($ids);
                if ($batch === 0) {
                    break; // garde-fou : évite une boucle infinie si rien ne peut être supprimé
                }
                $deleted += $batch;
            }

            return $this->jsonResponse([
                'success' => true,
                'deleted' => $deleted,
                'message' => $deleted . ' message(s) supprimé(s).',
            ]);
        });
    }

    public function index(Request $request): Response|string
    {
        $user = $this->requireUser();
        $this->purgeExpiredConversations();
        $this->purgeExpiredMedia();
        if ($request->wantsJson()) {
            if (!$this->can($user, 'conversations.participate')) {
                return $this->jsonResponse(['success' => true, 'data' => []]);
            }
            return $this->jsonResponse(['success' => true, 'data' => $this->conversationRows($user)]);
        }

        $permissions = $user->effectivePermissions('string');
        $isModerator = $user->highestRoleLevel() >= User::MODERATOR_LEVEL;
        $isSuperAdmin = $user->isSuper();

        return $this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Ma messagerie',
            'csrf_token' => $this->csrfManager->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user),
            'admin_permissions' => $permissions,
            'content_name' => 'dashboard.conversations',
            'activeNavTab' => 'my-conversations',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Ma messagerie']],
            'isModerator' => $isModerator,
            'canManageConversations' => $isModerator && $user->hasPermission('conversations.management'),
            'isSuperAdmin' => $isSuperAdmin,
            'currentUserId' => (int) $user->getKey(),
            'canStart' => $this->can($user, 'conversations.start'),
            'canParticipate' => $this->can($user, 'conversations.participate'),
        ]);
    }

    public function adminIndex(Request $request): Response
    {
        try {
            $user = $this->requireModerator();

            return new Response($this->factory->renderWithLayout('app', 'dashboard', [
                'title' => 'Gestion des conversations',
                'csrf_token' => $this->csrfManager->generateToken(),
                'user' => $user->toArray(),
                'navelements' => NavbarFactory::navbar($user),
                'admin_permissions' => $user->effectivePermissions('string'),
                'content_name' => 'dashboard.conversation-management',
                'activeNavTab' => 'conversations',
                'isSuperAdmin' => $user->isSuper(),
                'canConfigure' => $user->isSuper(),
                'showHome' => true,
                'breadcrumbs' => [['label' => 'Gestion des conversations']],
                'canPreserve' => $this->can($user, 'conversations.preserve'),
                'canSuspend' => $this->can($user, 'conversations.suspend'),
                'canDelete' => $this->can($user, 'conversations.delete'),
            ]));
        } catch (InputErrorException | InvalidCredentianlsException | FailedProcessingException | GateException $e) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $e->getErrors() ?: $e->getMessage(),
            ]);
        }
    }
    public function discoverUsers(Request $request): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.start');
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        if (mb_strlen($query) < 2) {
            return $this->jsonResponse(['success' => true, 'data' => []]);
        }

        $users = User::table('users as u')
            ->select('u.id', 'u.name', 'u.username', 'u.profile_image')
            ->where('u.is_active', '=', 1)
            ->where('u.id', '!=', (int) $user->getKey())
            ->where(function ($filter) use ($query): void {
                $filter->where('u.name', 'LIKE', '%' . $query . '%')
                    ->orWhere('u.username', 'LIKE', '%' . $query . '%');
            })
            ->orderBy('u.name', 'ASC')
            ->limit(20)
            ->get();
        $existingConversations = [];
        if ($users !== []) {
            $candidateIds = array_map(
                static fn(User $candidate): int => (int) $candidate->getKey(),
                $users
            );
            foreach (
                Conversation::table('conversations as c')
                    ->join('conversation_user as mine', 'mine.conversation_id', '=', 'c.id')
                    ->join('conversation_user as other', 'other.conversation_id', '=', 'c.id')
                    ->where('mine.user_id', '=', (int) $user->getKey())
                    ->whereNull('mine.left_at')
                    ->whereIn('other.user_id', $candidateIds)
                    ->whereNull('other.left_at')
                    ->where('c.is_group', '=', 0)
                    ->where('c.status', '=', 'active')
                    ->select('other.user_id as other_user_id', 'c.id as conversation_id')
                    ->orderBy('c.updated_at', 'DESC')
                    ->get() as $conversation
            ) {
                $otherId = (int) $conversation->getAttribute('other_user_id');
                $existingConversations[$otherId] ??= (int) $conversation->getAttribute('conversation_id');
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => array_map(static fn(User $candidate): array => [
                'id' => (int) $candidate->getKey(),
                'name' => (string) $candidate->getAttribute('name'),
                'username' => (string) $candidate->getAttribute('username'),
                'profile_image' => (string) ($candidate->getAttribute('profile_image') ?: 'default.png'),
                'conversation_id' => $existingConversations[(int) $candidate->getKey()] ?? null,
            ], $users),
        ]);
    }

    public function groups(Request $request): Response
    {
        $user = $this->requireUser();
        // $this->purgeExpiredConversations();
        // $this->purgeExpiredMedia();
        $userId = (int) $user->getKey();
        $isSuper = $user->isSuper();

        $query = Group::table('groups as g')
            ->leftJoin('group_user as gu', function ($join) use ($user): void {
                $join->on('gu.group_id', '=', 'g.id')
                    ->where('gu.user_id', '=', (int) $user->getKey());
            });
        if (!$user->isSuper()) {
            $query->where(function ($scope) use ($user): void {
                $scope->whereNotNull('gu.user_id')
                    ->orWhere('g.owner_id', '=', (int) $user->getKey());
            });
        }
        $groups = $query
            ->select('g.id', 'g.name', 'g.slug', 'g.owner_id', 'gu.role_in_group')
            ->orderBy('g.name', 'ASC')
            ->limit(500)
            ->get();

        $groupIds = array_map(static fn(Group $group): int => (int) $group->getKey(), $groups);
        $activeConversations = [];
        if ($groupIds !== []) {
            foreach (
                Conversation::table()
                    ->whereIn('group_id', $groupIds)
                    ->where('status', '=', 'active')
                    ->select('id', 'group_id')
                    ->orderBy('created_at', 'DESC')
                    ->get() as $conversation
            ) {
                $groupId = (int) $conversation->getAttribute('group_id');
                $activeConversations[$groupId] ??= (int) $conversation->getKey();
            }
        }

        $data = [];
        foreach ($groups as $group) {
            $groupId = (int) $group->getKey();
            $data[] = [
                'id' => $groupId,
                'name' => (string) $group->getAttribute('name'),
                'slug' => (string) $group->getAttribute('slug'),
                'can_start' => $this->can($user, 'conversations.start') && (
                    $isSuper
                    || (int) $group->getAttribute('owner_id') === $userId
                    || $group->getAttribute('role_in_group') === GroupUser::ROLE_ADMIN
                ),
                'conversation_id' => $activeConversations[$groupId] ?? null,
            ];
        }
        return $this->jsonResponse(['success' => true, 'data' => $data]);
    }

    public function open(Request $request): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.start');
        try {
            $body = $request->isJson() ? $request->json() : $request->all();
            $rules = [
                'user_id' => 'numeric|min:1',
                'group_id' => 'numeric|min:1',
                'subject' => 'string|max:200',
            ];

            $body = $this->validatePost(
                data: $body,
                only: ['user_id', 'group_id', 'subject'],
                rules: $rules
            )->toArray();

            if (isset($body['group_id'])) {
                $conversation = $this->openGroup($user, $body);
            } else {
                $conversation = $this->openDirect($user, $body);
            }

            return $this->jsonResponse([
                'success' => true,
                'data' => ['id' => (int) $conversation->getKey()],
            ]);
        } catch (Throwable $exception) {
            return $this->jsonResponse(['success' => false, 'errors' => $exception->getMessage()], 422);
        }
    }

    public function messages(Request $request): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.participate');
        try {
            try {
                $conversationId = $this->validatedId($request->query('conversation_id'));
            } catch (InvalidArgumentException $exception) {
                return $this->jsonResponse(['success' => false, 'errors' => $exception->getMessage()], 422);
            }
            $conversation = Conversation::find($conversationId);
            if (!$conversation instanceof Conversation && !$user->isSuper() || !$this->canAccessConversation($user, $conversation)) {
                throw new NotFoundHttpException("Conversatoion introuvable!");
            }

            $this->purgeExpiredMedia();
            if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
                $this->terminateConversation($conversation);
            }
            if (!$conversation->isActive()) {
                if (!$conversation->isPreserved()) {
                    return $this->jsonResponse([
                        'success' => true,
                        'data' => [],
                        'finished' => true,
                        'preserved' => false,
                    ]);
                }
            }

            $beforeId = null;
            if ($request->query('before_id') !== null) {
                try {
                    $beforeId = $this->validatedId($request->query('before_id'));
                } catch (InvalidArgumentException $exception) {
                    return $this->jsonResponse(['success' => false, 'errors' => $exception->getMessage()], 422);
                }
            }
            $rows = ConversationMessage::forConversation($conversationId, 101, $beforeId);
            $hasMore = count($rows) > 100;
            if ($hasMore) {
                array_shift($rows);
            }
            $data = [];
            foreach ($rows as $message) {
                $row = $this->messageRow($message, $user);
                $row['created_at'] = SupportDate::diffForHumans($message->getAttribute('created_at'));
                $data[] = $row;
            }
            return $this->jsonResponse([
                'success' => true,
                'data' => $data,
                'has_more' => $hasMore,
                'before_id' => $data[0]['id'] ?? null,
                'conversation' => [
                    'id' => $conversationId,
                    'subject' => (string) ($conversation->getAttribute('subject') ?? ''),
                    'label' => $this->conversationLabel($conversation, (int) $user->getKey()),
                    'is_member' => $conversation->isParticipant((int) $user->getKey()),
                    'is_group' => (bool) $conversation->getAttribute('is_group'),
                    'message_count' => (int) $conversation->getAttribute('message_count'),
                    'message_limit' => (int) $conversation->getAttribute('message_limit'),
                    'expires_at' => $conversation->getAttribute('expires_at'),
                    'finished' => !$conversation->isActive(),
                    'preserved' => $conversation->isPreserved(),
                    'can_preserve' => $this->canDoAction($user, $conversation, 'preserve'),
                    'can_suspend' => $this->canDoAction($user, $conversation, 'terminate'),
                    'can_delete' => $this->canDoAction($user, $conversation, 'delete'),
                ],
            ]);
        } catch (InputErrorException | InvalidCredentianlsException | FailedProcessingException | NotFoundHttpException $e) {
            $errors = [
                "success" => false,
            ];
            $errors['errors'] = $e->getErrors() ? $e->getErrors() : $e->getMessage();
            return $this->jsonResponse(
                $errors
            );
        }
    }
    public function send(Request $request): Response
    {
        try {
            $user = $this->requireUser();
            $this->requirePermission($user, 'conversations.participate');

            $body = $this->validatePost(
                data: $request->isJson() ? $request->json() : $request->all(),
                only: ['conversation_id', 'body'],
                rules: [
                    'conversation_id' => 'required|integer|min:1',
                    'body' => 'string|min:1',
                ]
            )->toArray();
            $conversation = Conversation::find($this->validatedId($body['conversation_id'] ?? null));
            if (!$conversation instanceof Conversation || !$this->canAccessConversation($user, $conversation)) {
                throw new NotFoundHttpException("Conversatoion introuvable!");
            }
            if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
                $this->terminateConversation($conversation);
            }

            if (!$conversation->isActive()) {
                return $this->jsonResponse([
                    'success' => false,
                    'errors' => 'Cette conversation est terminée.',
                ], 409);
            }

            $upload = $request->file('image');
            if (is_array($upload)) {
                throw new InvalidArgumentException('Une seule image peut être envoyée à la fois.');
            }
            $recipientIds = [];
            if ($upload instanceof UploadedFile) {
                $message = $this->sendImage($conversation, $user, $upload, $recipientIds);
            } else {
                $message = $this->sendText($conversation, $user, (string) ($body['body'] ?? ''), $recipientIds);
            }

            $messageRow = $this->messageRow($message, $user);
            $this->publishConversationMessage($conversation, $messageRow, $recipientIds);

            return $this->jsonResponse(['success' => true, 'data' => $messageRow], 201);
        } catch (Throwable $exception) {
            return $this->errorResponse(exception: $exception);
        }
    }
    private function errorResponse(\Throwable $exception)
    {
        return $this->jsonResponse([
            'success' => false,
            'errors' => $exception instanceof InputErrorException
                ? ($exception->getErrors() ?: $exception->getMessage())
                : ($exception instanceof InvalidCredentianlsException
                    ? ($exception->getErrors() ?: $exception->getMessage())
                    : $exception->getMessage()),
        ], 422);
    }
    public function unreadCount(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->can($user, 'conversations.participate')) {
            return $this->jsonResponse(['success' => true, 'count' => 0]);
        }
        $unread = ConversationMessageReceipt::unreadReceiptCount((int) $user->getKey());

        return $this->jsonResponse(['success' => true, 'count' => $unread]);
    }

    /**
     * Menu déroulant de la messagerie : une ligne PAR CONVERSATION (5 max),
     * avec son dernier message non lu et son nombre de non lus, + total global.
     */
    public function inbox(): Response
    {
        $user = $this->requireUser();
        $userId = (int) $user->getKey();
        if (!$this->can($user, 'conversations.participate')) {
            return $this->jsonResponse(['success' => true, 'count' => 0, 'data' => [], 'unreadCount' => 0]);
        }
        // On récupère large (déjà triés du plus récent au plus ancien) puis on regroupe.
        $rows = ConversationMessageReceipt::latestUnread($userId, 100);

        $latest = [];            // conversation_id => dernière ligne non lue
        $conversationCount = 0;  // conversations distinctes rencontrées
        foreach ($rows as $row) {
            $conversationId = (int) $row->getAttribute('conversation_id');
            if (!isset($latest[$conversationId])) {
                $conversationCount++;
                if (count($latest) < 5) {
                    $latest[$conversationId] = $row;
                }
            }
        }

        $counts = ConversationMessageReceipt::unreadCountsByConversation($userId, array_keys($latest));

        $items = [];
        foreach ($latest as $conversationId => $row) {
            $isGroup = (bool) $row->getAttribute('is_group');
            $isImage = $row->getAttribute('message_type') === 'image';
            $sender = (string) $row->getAttribute('sender_name');
            $body = trim((string) $row->getAttribute('body'));

            $items[] = [
                'id' => $conversationId,
                'conversation_id' => $conversationId,
                'message_id' => (int) $row->getKey(),
                'is_group' => $isGroup,
                // Direct : l'interlocuteur. Groupe : le sujet (l'expéditeur est dans `sender`).
                'label' => $isGroup ? ((string) $row->getAttribute('subject') ?: 'Groupe') : $sender,
                'sender' => $sender,
                'type' => $isImage ? 'image' : 'text',
                'preview' => $isImage
                    ? 'Une image'
                    : (mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body),
                'unread' => max(1, (int) ($counts[$conversationId] ?? 1)),
                'time' => SupportDate::diffForHumans($row->getAttribute('created_at')),
            ];
        }

        return $this->jsonResponse([
            'success' => true,
            'data' => $items,
            'unreadCount' => ConversationMessageReceipt::unreadReceiptCount($userId),
            // Conversations non lues non affichées (approximatif si > 100 messages non lus)
            'moreConversations' => max(0, $conversationCount - count($items)),
        ]);
    }

    /**
     * Marque TOUTES les conversations de l'utilisateur comme lues.
     */
    public function markAllRead(): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.participate');
        $userId = (int) $user->getKey();

        $conversationIds = ConversationMessageReceipt::unreadConversationIds($userId);
        foreach ($conversationIds as $conversationId) {
            $this->markConversationRead($conversationId, $userId);
        }

        return $this->jsonResponse(['success' => true, 'updated' => count($conversationIds)]);
    }

    /**
     * REMPLACE markRead() : même comportement, la logique est extraite
     * dans markConversationRead() pour être réutilisée par markAllRead().
     */
    public function markRead(Request $request): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.participate');
        $body = $this->validatePost(
            data: $request->isJson() ? $request->json() : $request->all(),
            only: ['conversation_id'],
            rules: [
                'conversation_id' => 'required|integer|min:1',
            ]
        )->toArray();
        $conversation = Conversation::find($this->validatedId($body['conversation_id'] ?? null));
        if (!$conversation instanceof Conversation || !$this->canAccessConversation($user, $conversation)) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }

        $this->markConversationRead((int) $conversation->getKey(), (int) $user->getKey());

        return $this->jsonResponse(['success' => true]);
    }

    private function markConversationRead(int $conversationId, int $userId): void
    {
        $now = date('Y-m-d H:i:s');

        ConversationUser::where('conversation_id', '=', $conversationId)
            ->where('user_id', '=', $userId)
            ->update(['last_read_at' => $now]);

        $unreadMessageIds = array_map(
            'intval',
            ConversationMessageReceipt::table('conversation_message_receipts as r')
                ->join('messages as m', 'm.id', '=', 'r.message_id')
                ->where('r.user_id', '=', $userId)
                ->whereNull('r.seen_at')
                ->where('m.conversation_id', '=', $conversationId)
                ->pluck('r.message_id')
        );

        if ($unreadMessageIds === []) {
            return;
        }

        ConversationMessageReceipt::where('user_id', '=', $userId)
            ->whereIn('message_id', $unreadMessageIds)
            ->whereNull('seen_at')
            ->update(['seen_at' => $now]);

        $images = ConversationMessage::table()->whereIn('id', $unreadMessageIds)
            ->where('message_type', '=', 'image')
            ->get();
        foreach ($images as $message) {
            $this->expireMediaIfNeeded($message);
        }
    }

    public function media(Request $request): Response
    {
        $user = $this->requireUser();
        $this->requirePermission($user, 'conversations.participate');
        $message = ConversationMessage::find($this->validatedId($request->query('id')));
        if (
            !$message instanceof ConversationMessage
            || $message->getAttribute('message_type') !== 'image'
        ) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
        $conversation = Conversation::find((int) $message->getAttribute('conversation_id'));
        if (!$conversation instanceof Conversation || !$this->canAccessConversation($user, $conversation)) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
        if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
            $this->terminateConversation($conversation);
        }
        $this->expireMediaIfNeeded($message);
        if (!$conversation->isActive()) {
            if (!$conversation->isPreserved()) {
                return $this->defaultMediaResponse();
            }
        }

        $path = (string) ($message->getAttribute('media_path') ?? '');
        if ($path === '' || $message->getAttribute('media_deleted_at') !== null) {
            return $this->defaultMediaResponse();
        }
        $absolute = $this->mediaDirectory((int) $conversation->getKey()) . DIRECTORY_SEPARATOR . basename($path);
        if (!is_file($absolute)) {
            return $this->defaultMediaResponse();
        }
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw new RuntimeException('Impossible de lire l’image de la conversation.');
        }

        if ((int) $message->getAttribute('sender_id') !== (int) $user->getKey()) {
            $receipt = ConversationMessageReceipt::where('message_id', '=', (int) $message->getKey())
                ->where('user_id', '=', (int) $user->getKey())
                ->first();
            if ($receipt !== null && $receipt->getAttribute('seen_at') === null) {
                $receipt->update(['seen_at' => date('Y-m-d H:i:s')]);
            }
        }

        return new Response($contents, 200, [
            'Content-Type' => (string) $message->getAttribute('media_mime_type'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function defaultMedia(Request $request): Response
    {
        $this->requireUser();

        return $this->defaultMediaResponse();
    }

    public function preserveMedia(Request $request): Response
    {
        $user = $this->requireUser();
        $body = $this->validatePost(
            data: $request->isJson() ? $request->json() : $request->all(),
            only: ['message_id'],
            rules: [
                'message_id' => 'required|integer|min:1',
            ]
        )->toArray();
        $message = ConversationMessage::find($this->validatedId($body['message_id'] ?? null));
        if (!$message instanceof ConversationMessage) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
        $conversation = Conversation::find((int) $message->getAttribute('conversation_id'));
        if (!$conversation instanceof Conversation) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
        $this->assertCanPreserve($user, $conversation);
        $this->expireMediaIfNeeded($message);
        if ($message->getAttribute('message_type') !== 'image' || $message->getAttribute('media_deleted_at') !== null) {
            throw new InvalidArgumentException('Cette image a déjà été supprimée ou ne peut pas être conservée.');
        }

        $message->update(['media_preserved' => 1]);
        return $this->jsonResponse(['success' => true]);
    }

    public function finish(Request $request): Response
    {
        $user = $this->requireUser();

        $body = $request->isJson() ? $request->json() : $request->all();
        $rules = [
            'conversation_id' => 'required|integer|min:1',
            'action' => 'required|string|in:preserve,terminate,delete',
        ];

        $body = $this->validatePost(
            data: $body,
            only: ['conversation_id', 'action'],
            rules: $rules
        )->toArray();

        $conversation = Conversation::find($this->validatedId($body['conversation_id'] ?? null));
        if (
            !$conversation instanceof Conversation
            || !$this->canAccessConversation($user, $conversation)
        ) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
        $action = (string) ($body['action'] ?? (!empty($body['preserve']) ? 'preserve' : 'terminate'));
        $this->assertCanDoAction($user, $conversation, $action);
        if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
            $this->terminateConversation($conversation);
        }

        return $this->applyConversationAction($conversation, $action);
    }

    public function adminAction(Request $request): Response
    {
        try {
            $user = $this->requireModerator();
            $body = $request->isJson() ? $request->json() : $request->all();
            $rules = [
                'conversation_id' => 'required|integer|min:1',
                'action' => 'required|string|in:preserve,terminate,delete',
            ];

            $body = $this->validatePost(
                data: $body,
                only: ['conversation_id', 'action'],
                rules: $rules
            )->toArray();
            $conversation = Conversation::find($this->validatedId($body['conversation_id'] ?? null));
            if (!$conversation instanceof Conversation) {
                throw new NotFoundHttpException("Conversatoion introuvable!");
            }
            $action = (string) ($body['action'] ?? '');
            $this->assertCanDoAction($user, $conversation, $action);
            if ($conversation->getAttribute('status') === 'active' && !$conversation->isActive()) {
                $this->terminateConversation($conversation);
            }

            return $this->applyConversationAction($conversation, $action);
        } catch (InputErrorException | InvalidCredentianlsException | NotFoundHttpException | GateException $e) {
            $errors = [
                "success" => false,
            ];
            $errors['errors'] = $e->getErrors() ? $e->getErrors() : $e->getMessage();
            return $this->jsonResponse(
                $errors
            );
        }
    }

    public function policies(Request $request): Response|string
    {
        $user = $this->requireSuperAdmin();
        $roles = Role::table()->orderBy('level', 'DESC')->get();
        if ($request->isMethod('POST')) {
            try {

                $body = $request->isJson() ? $request->json() : $request->all();
                $rules = [
                    'policies' => 'array',
                    'group_policy' => 'array|min:1',
                ];

                $body = $this->validatePost(
                    data: $body,
                    only: ['policies', 'group_policy'],
                    rules: $rules
                )->toArray();

                return $this->savePolicies($roles, $body);
            } catch (Throwable $exception) {
                return $this->errorResponse($exception);
            }
        }

        $policyRows = ConversationPolicy::directRows();

        return $this->factory->renderWithLayout('app', 'dashboard', [
            'title' => 'Paramètres des conversations',
            'csrf_token' => $this->csrfManager->generateToken(),
            'user' => $user->toArray(),
            'navelements' => NavbarFactory::navbar($user),
            'admin_permissions' => $user->effectivePermissions('string'),
            'content_name' => 'dashboard.conversation-policies',
            'roles' => array_map(static fn(Role $role): array => [
                'id' => (int) $role->getKey(),
                'name' => $role->getName(),
                'slug' => $role->getSlug(),
            ], $roles),
            'policies' => array_map(static fn(array $row): array => [
                'sr_id' => (int) $row['sr_id'],
                'rr_id' => (int) $row['rr_id'],
                'starter_role_name' => (string) $row['starter_role_name'],
                'recipient_role_name' => (string) $row['recipient_role_name'],
                ...ConversationPolicy::normalize($row),
            ], $policyRows),
            'policyRoles' => array_map(static fn(Role $role): array => [
                'id' => (int) $role->getKey(),
                'name' => $role->getName(),
            ], $roles),
            'groupPolicy' => ConversationPolicy::adminDefaults(),
            'activeNavTab' => 'conversations',
            'showHome' => true,
            'breadcrumbs' => [['label' => 'Paramètres des conversations']],
        ]);
    }

    public function uploadDefaultMedia(Request $request): Response
    {
        $this->requireSuperAdmin();
        $token = $request->input('csrf_token');
        if (!is_string($token) || !$this->csrfManager->validateToken($token)) {
            return $this->jsonResponse(['success' => false, 'errors' => 'Le jeton CSRF est invalide ou expiré. Actualisez la page.'], 422);
        }

        $upload = $request->file('image');
        if (is_array($upload) || !$upload instanceof UploadedFile || !$upload->isValid()) {
            return $this->jsonResponse(['success' => false, 'errors' => 'Sélectionnez une image valide.'], 422);
        }
        if ($upload->getSize() <= 0 || $upload->getSize() > self::MAX_DEFAULT_IMAGE_BYTES) {
            return $this->jsonResponse(['success' => false, 'errors' => 'L’image de remplacement doit faire au maximum 2 Mo.'], 422);
        }

        $tmpPath = $upload->getTmpPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        $imageInfo = @getimagesize($tmpPath);
        if (
            !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $imageInfo === false
            || ($imageInfo['mime'] ?? '') !== $mime
            || ($imageInfo[0] ?? 0) < 1
            || ($imageInfo[1] ?? 0) < 1
            || ($imageInfo[0] ?? 0) > 4096
            || ($imageInfo[1] ?? 0) > 4096
        ) {
            return $this->jsonResponse(['success' => false, 'errors' => 'Choisissez une image JPEG, PNG ou WebP valide de 4096 px maximum.'], 422);
        }

        $extension = match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
        };
        $filename = 'default_' . bin2hex(random_bytes(24)) . $extension;
        $directory = $this->defaultMediaDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire de l’image de remplacement.');
        }
        $path = $upload->move($directory, $filename);

        $policy = ConversationGroupPolicy::find(1);
        $oldFilename = $policy instanceof ConversationGroupPolicy
            ? (string) ($policy->getAttribute('default_media_path') ?? '')
            : '';
        try {
            if ($policy instanceof ConversationGroupPolicy) {
                $policy->update(['default_media_path' => $filename]);
            } else {
                ConversationGroupPolicy::create([
                    'id' => 1,
                    'message_limit' => 500,
                    'lifetime_minutes' => 43200,
                    'image_streak_limit' => 3,
                    'direct_message_limit' => 100,
                    'direct_lifetime_minutes' => 10080,
                    'direct_image_streak_limit' => 3,
                    'media_retention_minutes' => 1440,
                    'default_media_path' => $filename,
                ]);
            }
        } catch (Throwable $exception) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('L’enregistrement a échoué et le nouveau fichier n’a pas pu être supprimé.', 0, $exception);
            }
            throw $exception;
        }

        if ($this->isManagedDefaultMediaName($oldFilename)) {
            $oldPath = $directory . DIRECTORY_SEPARATOR . $oldFilename;
            if (is_file($oldPath) && !unlink($oldPath)) {
                throw new RuntimeException('L’image a été mise à jour, mais l’ancienne copie n’a pas pu être supprimée.');
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => 'L’image de remplacement a été mise à jour.',
        ]);
    }

    private function openDirect(User $user, array $body): Conversation
    {
        $target = User::find($this->validatedId($body['user_id'] ?? null));
        if (
            !$target instanceof User
            || !$target->is_active()
            || (int) $target->getKey() === (int) $user->getKey()
        ) {
            throw new InvalidArgumentException('Choisissez un autre compte actif.');
        }

        $participantIds = [(int) $user->getKey(), (int) $target->getKey()];
        sort($participantIds);
        $directKey = implode(':', $participantIds);
        $existing = Conversation::where('direct_key', '=', $directKey)
            ->where('status', '=', 'active')
            ->first();
        if ($existing instanceof Conversation) {
            return $existing;
        }

        $starterRole = $this->highestRole($user);
        $recipientRole = $this->highestRole($target);
        $policy = ConversationPolicy::directForRoles(
            (int) $starterRole->getKey(),
            (int) $recipientRole->getKey()
        );

        $conversation = null;
        try {
            Conversation::table()->transactionOrThrow(function () use (&$conversation, $user, $target, $policy, $directKey): void {
                $createdAt = date('Y-m-d H:i:s');
                $conversation = Conversation::create([
                    'is_group' => 0,
                    'direct_key' => $directKey,
                    'created_by' => (int) $user->getKey(),
                    'status' => 'active',
                    'message_limit' => $policy['message_limit'],
                    'lifetime_minutes' => $policy['lifetime_minutes'],
                    'image_streak_limit' => $policy['image_streak_limit'],
                    'media_retention_minutes' => ConversationPolicy::mediaRetentionMinutes(),
                    'expires_at' => date('Y-m-d H:i:s', time() + $policy['lifetime_minutes'] * 60),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $this->addParticipant($conversation, (int) $user->getKey());
                $this->addParticipant($conversation, (int) $target->getKey());
            });
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                $existing = Conversation::where('direct_key', '=', $directKey)
                    ->where('status', '=', 'active')
                    ->first();
                if ($existing instanceof Conversation) {
                    return $existing;
                }
            }
            throw $exception;
        }

        if (!$conversation instanceof Conversation) {
            throw new RuntimeException('La conversation n’a pas pu être créée.');
        }
        return $conversation;
    }

    private function openGroup(User $user, array $body): ?Conversation
    {
        $group = Group::find($this->validatedId($body['group_id']));
        if (!$group instanceof Group || (!$user->isSuper() && !$group->hasMember($user) && !$group->isOwnedBy($user))) {
            throw new InvalidArgumentException('Vous devez appartenir au groupe pour démarrer une conversation.');
        }
        if (!$group->isAdmin($user) && !$user->isSuper()) {
            throw new InvalidArgumentException('Seuls les administrateurs du groupe peuvent démarrer sa conversation.');
        }

        $participantIds = GroupUser::userIdsForGroup((int) $group->getKey());
        if ($group->getAttribute('owner_id') !== null) {
            $participantIds[] = (int) $group->getAttribute('owner_id');
        }
        $participantIds[] = (int) $user->getKey();
        $participantIds = array_values(array_unique($participantIds));
        $participantIds = array_values(array_filter($participantIds, static function (int $id): bool {
            $member = User::find($id);
            return $member instanceof User && $member->is_active();
        }));
        if (count($participantIds) < 2) {
            throw new InvalidArgumentException('Le groupe doit contenir au moins deux membres actifs.');
        }

        $existing = Conversation::where('group_id', '=', (int) $group->getKey())
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'DESC')
            ->first();
        if ($existing instanceof Conversation && $this->canAccessConversation($user, $existing)) {
            return $existing;
        }

        $policy = ConversationPolicy::groupDefaults();
        $conversation = null;
        Conversation::table()->transactionOrThrow(function () use (&$conversation, $user, $group, $participantIds, $policy, $body): void {
            $createdAt = date('Y-m-d H:i:s');
            $subject = trim((string) ($body['subject'] ?? ''));
            $conversation = Conversation::create([
                'subject' => $subject === '' ? $group->getName() : mb_substr($subject, 0, 120),
                'is_group' => 1,
                'group_id' => (int) $group->getKey(),
                'created_by' => (int) $user->getKey(),
                'status' => 'active',
                'message_limit' => $policy['message_limit'],
                'lifetime_minutes' => $policy['lifetime_minutes'],
                'image_streak_limit' => $policy['image_streak_limit'],
                'media_retention_minutes' => ConversationPolicy::mediaRetentionMinutes(),
                'expires_at' => date('Y-m-d H:i:s', time() + $policy['lifetime_minutes'] * 60),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            foreach ($participantIds as $participantId) {
                $this->addParticipant($conversation, $participantId);
            }
        });
        return $conversation;
    }

    private function sendText(
        Conversation $conversation,
        User $user,
        string $body,
        array &$recipientIds
    ): ?ConversationMessage {
        $text = trim(strip_tags($body));
        if ($text === '' || mb_strlen($text) > self::MAX_MESSAGE_CHARS) {
            throw new InvalidArgumentException('Le message doit contenir entre 1 et 4000 caractères.');
        }
        $message = null;
        Conversation::table()->transactionOrThrow(function () use (&$message, &$recipientIds, $conversation, $user, $text): void {
            $conversationId = (int) $conversation->getKey();
            $messageLimit = (int) $conversation->getAttribute('message_limit');
            $affected = Conversation::where('id', '=', $conversationId)
                ->where('status', '=', 'active')
                ->where('message_count', '<', $messageLimit)
                ->increment('message_count');
            if ($affected !== 1) {
                throw new InvalidArgumentException('La conversation a atteint sa limite de messages.');
            }
            $message = ConversationMessage::create([
                'conversation_id' => $conversationId,
                'sender_id' => (int) $user->getKey(),
                'body' => $text,
                'message_type' => 'text',
            ]);
            Conversation::where('id', '=', $conversationId)
                ->update(['last_message_id' => (int) $message->getKey()]);
            $recipientIds = $this->createReceipts($conversation, $message, (int) $user->getKey());
        });

        return $message;
    }

    private function sendImage(
        Conversation $conversation,
        User $user,
        UploadedFile $upload,
        array &$recipientIds
    ): ?ConversationMessage {
        if (!$upload->isValid() || $upload->getSize() <= 0 || $upload->getSize() >= self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('L’image doit faire moins de 500 Ko.');
        }
        $tmpPath = $upload->getTmpPath();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $imageInfo = @getimagesize($tmpPath);
        if (
            !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $imageInfo === false
            || ($imageInfo['mime'] ?? '') !== $mime
            || ($imageInfo[0] ?? 0) < 1
            || ($imageInfo[1] ?? 0) < 1
            || ($imageInfo[0] ?? 0) > 4096
            || ($imageInfo[1] ?? 0) > 4096
        ) {
            throw new InvalidArgumentException('Seules les images JPEG, PNG ou WebP valides de 4096 px maximum sont acceptées.');
        }

        $streakLimit = (int) $conversation->getAttribute('image_streak_limit', 3);
        $mediaRetentionMinutes = max(
            1,
            (int) $conversation->getAttribute('media_retention_minutes', ConversationPolicy::mediaRetentionMinutes())
        );
        $mediaExpiresAt = date('Y-m-d H:i:s', time() + $mediaRetentionMinutes * 60);
        $id = (int) $conversation->getKey();
        $filename = bin2hex(random_bytes(24)) . match ($mime) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
        };
        $directory = $this->mediaDirectory($id);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire média de la conversation.');
        }
        $upload->move($directory, $filename);

        $message = null;
        try {
            Conversation::table()->transactionOrThrow(function () use (&$message, &$recipientIds, $conversation, $user, $filename, $mime, $upload, $streakLimit, $directory, $mediaExpiresAt): void {
                $conversationId = (int) $conversation->getKey();
                $affected = Conversation::where('id', '=', $conversationId)
                    ->where('status', '=', 'active')
                    ->where('message_count', '<', (int) $conversation->getAttribute('message_limit'))
                    ->where('image_streak', '<', $streakLimit)
                    ->increment('message_count');
                if ($affected !== 1) {
                    throw new InvalidArgumentException(
                        'La limite de messages consécutifs contenant une image est atteinte.'
                    );
                }
                Conversation::where('id', '=', $conversationId)->increment('image_streak');
                $message = ConversationMessage::create([
                    'conversation_id' => $conversationId,
                    'sender_id' => (int) $user->getKey(),
                    'body' => '',
                    'message_type' => 'image',
                    'media_path' => $filename,
                    'media_mime_type' => $mime,
                    'media_size' => $upload->getSize(),
                    'media_preserved' => 0,
                    'media_expires_at' => $mediaExpiresAt,
                ]);
                Conversation::where('id', '=', $conversationId)
                    ->update(['last_message_id' => (int) $message->getKey()]);
                $recipientIds = $this->createReceipts($conversation, $message, (int) $user->getKey());
            });
        } catch (Throwable $exception) {
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Envoi échoué et le fichier temporaire n’a pas pu être supprimé.', 0, $exception);
            }
            throw $exception;
        }

        return $message;
    }

    /**
     * @return list<int>
     */
    private function createReceipts(Conversation $conversation, ConversationMessage $message, int $senderId): array
    {
        $participantIds = array_values(array_filter(
            $conversation->participantIds(),
            static fn(int $participantId): bool => $participantId !== $senderId
        ));
        if ($participantIds === []) {
            return [];
        }

        $recipientIds = array_values(array_unique(array_map(
            'intval',
            User::table()
                ->whereIn('id', $participantIds)
                ->where('is_active', '=', 1)
                ->pluck('id')
        )));
        if ($recipientIds === []) {
            return [];
        }

        $createdAt = date('Y-m-d H:i:s');
        $receipts = [];
        foreach ($recipientIds as $recipientId) {
            $receipts[] = [
                'message_id' => (int) $message->getKey(),
                'user_id' => $recipientId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        ConversationMessageReceipt::table()->insertMany($receipts);

        return $recipientIds;
    }

    /**
     * @param array<string, mixed> $message
     * @param list<int> $recipientIds
     */
    private function publishConversationMessage(Conversation $conversation, array $message, array $recipientIds): void
    {
        if (!(new WebsocketSettingsService(app()->environment()))->isAvailable()) {
            return;
        }

        $channels = $conversation->realtimeChannels($recipientIds);
        if ($channels === []) {
            return;
        }

        // Le payload est désormais partagé : aucun champ propre à l'expéditeur.
        unset($message['can_preserve']);

        $payload = json_encode([
            'type' => 'message',
            'dedupe_id' => (int) $message['id'],
            'data' => [
                'conversation_id' => (int) $conversation->getKey(),
                'sender_id' => (int) $message['sender_id'],
                'message' => $message,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        app()->get('logCaster')->publishMany($channels, $payload);
    }
    private function expireMediaIfNeeded(ConversationMessage $message): void
    {
        if (
            $message->getAttribute('message_type') !== 'image'
            || $message->getAttribute('media_path') === null
            || (bool) $message->getAttribute('media_preserved')
            || $message->getAttribute('media_deleted_at') !== null
        ) {
            return;
        }
        $conversation = Conversation::find((int) $message->getAttribute('conversation_id'));
        if ($conversation instanceof Conversation && $conversation->isPreserved()) {
            return;
        }

        $expiresAt = $message->getAttribute('media_expires_at');
        if ($expiresAt === null && $conversation instanceof Conversation) {
            $createdAt = strtotime((string) $message->getAttribute('created_at'));
            if ($createdAt !== false) {
                $retentionMinutes = max(
                    1,
                    (int) $conversation->getAttribute('media_retention_minutes', ConversationPolicy::mediaRetentionMinutes())
                );
                $expiresAt = date('Y-m-d H:i:s', $createdAt + $retentionMinutes * 60);
            }
        }
        $expirationTimestamp = $expiresAt === null ? false : strtotime((string) $expiresAt);
        if ($expirationTimestamp !== false && $expirationTimestamp <= time()) {
            $this->deleteMessageMedia($message);
        }
    }

    private function purgeExpiredMedia(): void
    {
        $messageIds = ConversationMessage::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereNotNull('m.media_path')
            ->whereNull('m.media_deleted_at')
            ->where('m.media_preserved', '=', 0)
            ->whereNull('c.preserved_at')
            ->whereNotNull('m.media_expires_at')
            ->where('m.media_expires_at', '<=', date('Y-m-d H:i:s'))
            ->select('m.id')
            ->orderBy('m.media_expires_at', 'ASC')
            ->limit(100)
            ->pluck('m.id');

        foreach (array_map('intval', $messageIds) as $messageId) {
            $message = ConversationMessage::find($messageId);
            if ($message instanceof ConversationMessage) {
                $this->expireMediaIfNeeded($message);
            }
        }

        $legacyMessageIds = ConversationMessage::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereNotNull('m.media_path')
            ->whereNull('m.media_deleted_at')
            ->where('m.media_preserved', '=', 0)
            ->whereNull('c.preserved_at')
            ->whereNull('m.media_expires_at')
            ->select('m.id')
            ->orderBy('m.created_at', 'ASC')
            ->limit(100)
            ->pluck('m.id');

        foreach (array_map('intval', $legacyMessageIds) as $messageId) {
            $message = ConversationMessage::find($messageId);
            if ($message instanceof ConversationMessage) {
                $this->expireMediaIfNeeded($message);
            }
        }
    }

    private function deleteMessageMedia(ConversationMessage $message): void
    {
        if ($message->getAttribute('media_path') !== null) {
            $conversationId = (int) $message->getAttribute('conversation_id');
            $path = $this->mediaDirectory($conversationId) . DIRECTORY_SEPARATOR
                . basename((string) $message->getAttribute('media_path'));
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Impossible de supprimer le média expiré.');
            }
        }
        $message->update([
            'media_path' => null,
            'media_deleted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function applyConversationAction(Conversation $conversation, string $action): Response
    {
        if ($action === 'preserve') {
            if (!$conversation->isActive() && !$conversation->isPreserved()) {
                return $this->jsonResponse([
                    'success' => false,
                    'errors' => 'Une conversation terminée sans préservation ne peut pas être restaurée.',
                ], 409);
            }
            $conversation->update(['preserved_at' => $conversation->getAttribute('preserved_at') ?? date('Y-m-d H:i:s')]);
            $this->preserveConversationMedia($conversation);

            return $this->jsonResponse(['success' => true, 'preserved' => true, 'finished' => !$conversation->isActive()]);
        }

        if ($action === 'terminate' || $action === 'finish') {
            $this->terminateConversation($conversation);

            return $this->jsonResponse([
                'success' => true,
                'preserved' => $conversation->isPreserved(),
                'finished' => true,
            ]);
        }

        if ($action === 'delete') {
            if ($conversation->isActive()) {
                return $this->jsonResponse([
                    'success' => false,
                    'errors' => 'Terminez la conversation avant de la supprimer définitivement.',
                ], 409);
            }
            $this->deleteConversation($conversation);

            return $this->jsonResponse(['success' => true, 'deleted' => true]);
        }

        throw new InvalidArgumentException('Action de conversation invalide.');
    }

    private function canControlConversation(User $user, Conversation $conversation): bool
    {
        if ($user->isSuper()) {
            return true; //le super-admin gère toutes les conversations, même sans y participer
        }
        $userId = (int) $user->getKey();
        if (!$conversation->isParticipant($userId)) {
            return false;
        }

        $groupId = $conversation->getAttribute('group_id');
        if ($groupId !== null) {
            $group = Group::find((int) $groupId);

            return $group instanceof Group && $group->isAdmin($user);
        }

        foreach ($conversation->participantIds() as $participantId) {
            if ($participantId === $userId) {
                continue;
            }
            $other = User::find($participantId);
            if ($other instanceof User) {
                return $user->highestRoleLevel() >= $other->highestRoleLevel();
            }
        }

        return false;
    }

    private function assertCanControlConversation(User $user, Conversation $conversation): void
    {
        if (!$this->canControlConversation($user, $conversation)) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }
    }

    private function terminateConversation(Conversation $conversation): void
    {
        if (!$conversation->isPreserved()) {
            $directory = $this->mediaDirectory((int) $conversation->getKey());
            if (is_dir($directory)) {
                $this->removeDirectory($directory);
            }
        }
        $conversationId = (int) $conversation->getKey();

        Conversation::table()->transactionOrThrow(function () use ($conversation, $conversationId): void {
            if (!$conversation->isPreserved()) {
                $messageIds = ConversationMessage::where('conversation_id', '=', $conversationId)
                    ->pluck('id');
                if ($messageIds !== []) {
                    ConversationMessageReceipt::table()
                        ->whereIn('message_id', array_map('intval', $messageIds))
                        ->forceDelete();
                    ConversationMessage::table()
                        ->whereIn('id', array_map('intval', $messageIds))
                        ->forceDelete();
                }
            }
            $conversation->update([
                'status' => 'finished',
                'finished_at' => $conversation->getAttribute('finished_at') ?: date('Y-m-d H:i:s'),
                'direct_key' => null,
                'last_message_id' => null,
            ]);
        });
    }

    private function deleteConversation(Conversation $conversation): void
    {
        $conversationId = (int) $conversation->getKey();
        $directory = $this->mediaDirectory($conversationId);
        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        Conversation::table()->transactionOrThrow(function () use ($conversation, $conversationId): void {
            $messageIds = ConversationMessage::where('conversation_id', '=', $conversationId)->pluck('id');
            if ($messageIds !== []) {
                ConversationMessageReceipt::table()
                    ->whereIn('message_id', array_map('intval', $messageIds))
                    ->forceDelete();
            }
            ConversationMessage::where('conversation_id', '=', $conversationId)->forceDelete();
            ConversationUser::where('conversation_id', '=', $conversationId)->forceDelete();
            $conversation->forceDelete();
        });
    }

    private function purgeExpiredConversations(): void
    {
        $expired = Conversation::where('status', '=', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', date('Y-m-d H:i:s'))
            ->limit(100)
            ->get();
        foreach ($expired as $conversation) {
            $this->terminateConversation($conversation);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversationRows(User $user): array
    {
        $userId = (int) $user->getKey();
        $conversations = $this->userConversations($userId);
        if ($conversations === []) {
            return [];
        }

        $conversationIds = array_map(
            static fn(Conversation $c): int => (int) $c->getKey(),
            $conversations
        );

        // Participants + noms : une requête pour tous
        $participantsByConversation = [];
        $allIds = [];
        foreach ($conversations as $conversation) {
            $ids = $conversation->participantIds();
            $participantsByConversation[(int) $conversation->getKey()] = $ids;
            $allIds = array_merge($allIds, $ids);
        }
        $names = [];
        foreach (
            User::table()->whereIn('id', array_values(array_unique($allIds)))
                ->select('id', 'name')->get() as $u
        ) {
            $names[(int) $u->getKey()] = (string) $u->getAttribute('name');
        }

        // Non-lus : une requête
        $unread = ConversationMessageReceipt::unreadCountsByConversation($userId, $conversationIds);

        // Derniers messages : une requête
        $lastIds = array_values(array_filter(array_map(
            static fn(Conversation $c): int => (int) $c->getAttribute('last_message_id'),
            $conversations
        )));
        $latest = [];
        if ($lastIds !== []) {
            foreach (ConversationMessage::table()->whereIn('id', $lastIds)->get() as $m) {
                $latest[(int) $m->getAttribute('conversation_id')] = $m;
            }
        }

        $rows = [];
        foreach ($conversations as $conversation) {
            $id = (int) $conversation->getKey();
            $participantNames = [];
            $otherNames = [];
            foreach ($participantsByConversation[$id] as $participantId) {
                if (!isset($names[$participantId])) {
                    continue;
                }
                $participantNames[] = $names[$participantId];
                if ($participantId !== $userId) {
                    $otherNames[] = $names[$participantId];
                }
            }
            $last = $latest[$id] ?? null;

            $rows[] = [
                'id' => $id,
                'label' => $conversation->getAttribute('is_group')
                    ? (string) $conversation->getAttribute('subject')
                    : ($otherNames[0] ?? 'Utilisateur supprimé'),
                'participant_names' => $participantNames,
                'participants' => count($participantsByConversation[$id]),
                'is_group' => (bool) $conversation->getAttribute('is_group'),
                'unread' => $unread[$id] ?? 0,
                'latest_message' => $last === null ? ''
                    : ($last->getAttribute('message_type') === 'image'
                        ? 'Image'
                        : mb_substr((string) $last->getAttribute('body'), 0, 100)),
                'updated_at' => (string) ($last?->getAttribute('created_at') ?? $conversation->getAttribute('created_at')),
                'active' => $conversation->isActive(),
                'preserved' => $conversation->isPreserved(),
                'status' => (string) $conversation->getAttribute('status'),
                'can_manage' => $this->canControlConversation($user, $conversation),
                'can_control' => $this->canControlConversation($user, $conversation),
                'can_preserve' => $this->canDoAction($user, $conversation, 'preserve'),
                'can_suspend' => $this->canDoAction($user, $conversation, 'terminate'),
                'can_delete' => $this->canDoAction($user, $conversation, 'delete'),
                'expires_at' => $conversation->getAttribute('expires_at'),
            ];
        }

        return $rows;
    }
    /**
     * @return list<Conversation>
     */
    private function userConversations(int $userId): array
    {
        $directConversationIds = ConversationUser::table('conversation_user as cu')
            ->join('conversations as c', 'c.id', '=', 'cu.conversation_id')
            ->where('cu.user_id', '=', $userId)
            ->whereNull('cu.left_at')
            ->where('c.is_group', '=', 0)
            ->pluck('cu.conversation_id');
        $groupIds = GroupUser::groupIdsForUser($userId);
        $groupIds = array_merge(
            $groupIds,
            array_map('intval', Group::where('owner_id', '=', $userId)->pluck('id'))
        );
        $groupIds = array_values(array_unique($groupIds));

        if ($directConversationIds === [] && $groupIds === []) {
            return [];
        }

        $query = Conversation::table();
        if ($directConversationIds !== []) {
            $query->whereIn('id', array_map('intval', $directConversationIds));
        }
        if ($groupIds !== []) {
            if ($directConversationIds === []) {
                $query->whereIn('group_id', $groupIds);
            } else {
                $query->orWhereIn('group_id', $groupIds);
            }
        }

        $conversations = $query
            ->orderBy('updated_at', 'DESC')
            ->limit(100)
            ->get();

        return $conversations;
    }
    /**
     * @return array<string, mixed>
     */
    private function messageRow(ConversationMessage $message, User $viewer): array
    {
        $isImage = $message->getAttribute('message_type') === 'image';
        $available = $isImage && $message->getAttribute('media_deleted_at') === null
            && $message->getAttribute('media_path') !== null;
        $senderName = (string) $message->getAttribute('sender_name', '');
        if ($senderName === '' && (int) $message->getAttribute('sender_id') === (int) $viewer->getKey()) {
            $senderName = (string) $viewer->getAttribute('name');
        }
        return [
            'id' => (int) $message->getKey(),
            'sender_id' => (int) $message->getAttribute('sender_id'),
            'sender_name' => $senderName,
            'body' => (string) $message->getAttribute('body'),
            'type' => $isImage ? 'image' : 'text',
            'media_url' => $isImage ? '/dashboard/conversations/media?id=' . (int) $message->getKey() : null,
            'media_removed' => $isImage && !$available,
            'media_preserved' => (bool) $message->getAttribute('media_preserved'),
            'can_preserve' => $available && $this->canPreserveMessage($viewer, $message),
            'created_at' => SupportDate::diffForHumans($message->getAttribute('created_at')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function adminConversationRows(User $user): array
    {
        $query = Conversation::table('conversations as c')
            ->select(
                'c.id',
                'c.subject',
                'c.is_group',
                'c.group_id',
                'c.created_by',
                'c.status',
                'c.message_count',
                'c.message_limit',
                'c.created_at',
                'c.finished_at',
                'c.preserved_at',
                'c.expires_at',
            )
            ->orderBy('c.created_at', 'DESC')
            ->limit(200);
        if (!$user->isSuper()) {
            $userId = (int) $user->getKey();
            $query->leftJoin('conversation_user as cu', function ($join) use ($userId): void {
                $join->on('cu.conversation_id', '=', 'c.id')
                    ->where('cu.user_id', '=', $userId)
                    ->whereRaw('cu.left_at IS NULL');
            })
                ->leftJoin('groups as g', 'g.id', '=', 'c.group_id')
                ->leftJoin('group_user as gu', function ($join) use ($userId): void {
                    $join->on('gu.group_id', '=', 'c.group_id')
                        ->where('gu.user_id', '=', $userId);
                })
                ->where(function ($scope) use ($userId): void {
                    $scope->whereNotNull('cu.user_id')
                        ->orWhereNotNull('gu.user_id')
                        ->orWhere('g.owner_id', '=', $userId);
                });
        }

        $rows = [];
        foreach ($query->get() as $conversation) {
            $conversationModel = Conversation::find((int) $conversation->getKey());
            if (!$conversationModel instanceof Conversation) {
                continue;
            }
            $rows[] = [
                'id' => (int) $conversation->getKey(),
                'subject' => (string) ($conversation->getAttribute('subject') ?? ''),
                'is_group' => (bool) $conversation->getAttribute('is_group'),
                'status' => (string) $conversation->getAttribute('status'),
                'message_count' => (int) $conversation->getAttribute('message_count'),
                'message_limit' => (int) $conversation->getAttribute('message_limit'),
                'participants' => count($conversationModel->participantIds()),
                'preserved' => $conversation->getAttribute('preserved_at') !== null,
                'can_control' => $this->canControlConversation($user, $conversationModel),
                'can_preserve' => $this->canPreserveConversation($user, $conversationModel),
                'created_at' => SupportDate::diffForHumans($conversation->getAttribute('created_at')),
                'expires_at' => SupportDate::diffForHumans($conversation->getAttribute('expires_at')),
            ];
        }

        return $rows;
    }

    private function savePolicies(array $roles, array $body): Response
    {
        $roleIds = array_map(static fn(Role $role): int => (int) $role->getKey(), $roles);
        $policies = $body['policies'] ?? null;
        $groupPolicy = $body['group_policy'] ?? null;
        if (!is_array($policies) || !is_array($groupPolicy) || count($policies) > 1000) {
            throw new InvalidArgumentException('La liste des règles de conversation est invalide.');
        }
        $normalized = [];
        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                throw new InvalidArgumentException('Une règle de conversation est invalide.');
            }
            $starterId = $this->validatedId($policy['sr_id'] ?? null);
            $recipientId = $this->validatedId($policy['rr_id'] ?? null);
            if (!in_array($starterId, $roleIds, true) || !in_array($recipientId, $roleIds, true)) {
                throw new InvalidArgumentException('La règle référence un rôle inconnu.');
            }
            $key = $starterId . ':' . $recipientId;
            if (isset($normalized[$key])) {
                throw new InvalidArgumentException('La matrice contient une règle en double.');
            }
            $normalized[$key] = [
                'sr_id' => $starterId,
                'rr_id' => $recipientId,
                ...$this->validatePolicyValues($policy),
            ];
        }
        $groupValues = $this->validatePolicyValues($groupPolicy);
        $fallbackValues = $this->validatePolicyValues($groupPolicy['fallback'] ?? []);
        $groupValues += [
            'direct_message_limit' => $fallbackValues['message_limit'],
            'direct_lifetime_minutes' => $fallbackValues['lifetime_minutes'],
            'direct_image_streak_limit' => $fallbackValues['image_streak_limit'],
            'media_retention_minutes' => $this->validateMediaRetentionMinutes($groupPolicy['media_retention_minutes'] ?? null),
        ];

        Conversation::table()->transactionOrThrow(function () use ($normalized, $groupValues): void {
            ConversationPolicy::table()->forceDeleteAll(true);
            foreach ($normalized as $attributes) {
                ConversationPolicy::create($attributes);
            }
            $group = ConversationGroupPolicy::find(1);
            if ($group instanceof ConversationGroupPolicy) {
                $group->update($groupValues);
            } else {
                ConversationGroupPolicy::create(['id' => 1, ...$groupValues]);
            }
        });

        return $this->jsonResponse(['success' => true, 'message' => 'Les paramètres de conversation ont été enregistrés.']);
    }

    private function validatePolicyValues(array $policy): array
    {
        $messageLimit = filter_var($policy['message_limit'] ?? null, FILTER_VALIDATE_INT);
        $lifetime = filter_var($policy['lifetime_minutes'] ?? null, FILTER_VALIDATE_INT);
        $imageLimit = filter_var($policy['image_streak_limit'] ?? null, FILTER_VALIDATE_INT);
        if ($messageLimit === false || $messageLimit < 1 || $messageLimit > 10000) {
            throw new InvalidArgumentException('La limite de messages doit être comprise entre 1 et 10000.');
        }
        if ($lifetime === false || $lifetime < 60 || $lifetime > 525600) {
            throw new InvalidArgumentException('La durée doit être comprise entre 60 et 525600 minutes.');
        }
        if ($imageLimit === false || $imageLimit < 1 || $imageLimit > 20) {
            throw new InvalidArgumentException('La limite d’images consécutives doit être comprise entre 1 et 20.');
        }

        return [
            'message_limit' => $messageLimit,
            'lifetime_minutes' => $lifetime,
            'image_streak_limit' => $imageLimit,
        ];
    }

    private function validateMediaRetentionMinutes(mixed $value): int
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1 || $minutes > 525600) {
            throw new InvalidArgumentException('La durée de conservation des images doit être comprise entre 1 et 525600 minutes.');
        }

        return $minutes;
    }

    private function assertCanPreserve(User $user, Conversation $conversation): void
    {
        if (!$this->canPreserveConversation($user, $conversation)) {
            throw new GateException();
        }
    }

    private function canPreserveConversation(User $user, Conversation $conversation): bool
    {
        if (!$this->can($user, 'conversations.preserve')) {
            return false;
        }
        $groupId = $conversation->getAttribute('group_id');
        if ($groupId !== null) {
            $group = Group::find((int) $groupId);

            return $group instanceof Group && $group->isAdmin($user);
        }

        return $conversation->isParticipant((int) $user->getKey())
            && (
                $user->isSuper()
                || $user->highestRoleLevel() >= User::MODERATOR_LEVEL
            );
    }

    private function canPreserveMessage(User $user, ConversationMessage $message): bool
    {
        if ($user->isSuper()) {
            return true;
        }
        if (!$this->can($user, 'conversations.preserve')) {
            return false;
        }
        $conversation = Conversation::find((int) $message->getAttribute('conversation_id'));
        if (!$conversation instanceof Conversation) {
            return false;
        }
        if ($conversation->getAttribute('group_id') !== null) {
            $group = Group::find((int) $conversation->getAttribute('group_id'));

            return $group instanceof Group && $group->isAdmin($user);
        }

        return $conversation->isParticipant((int) $user->getKey())
            && (
                $user->isSuper()
                || $user->highestRoleLevel() >= User::MODERATOR_LEVEL
            );
    }

    private function preserveConversationMedia(Conversation $conversation): void
    {
        $messages = ConversationMessage::where('conversation_id', '=', (int) $conversation->getKey())
            ->where('message_type', '=', 'image')
            ->whereNotNull('media_path')
            ->get();
        foreach ($messages as $message) {
            $message->update(['media_preserved' => 1]);
        }
    }

    private function requireUser(): User
    {
        $userId = $this->session->getUserId();
        $user = $userId === null ? null : User::find($userId);
        if (!$user instanceof User || !$user->is_active()) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }

        return $user;
    }

    private function requireModerator(): User
    {
        $user = $this->requireUser();
        if (
            $user->highestRoleLevel() < User::MODERATOR_LEVEL
            || !$user->hasPermission('conversations.management')
        ) {
            throw new GateException();
        }
        return $user;
    }

    private function requireSuperAdmin(): User
    {
        $user = $this->requireUser();
        if (!$user->isSuper()) {
            throw new NotFoundHttpException("Conversatoion introuvable!");
        }

        return $user;
    }

    private function highestRole(User $user): Role
    {
        return $user->highestRole()
            ?? Role::findBySlug(User::MEMBER_SLUG)
            ?? throw new RuntimeException('Le rôle membre est introuvable.');
    }

    private function addParticipant(Conversation $conversation, int $userId): void
    {
        ConversationUser::create([
            'conversation_id' => (int) $conversation->getKey(),
            'user_id' => $userId,
            'joined_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function mediaDirectory(int $conversationId): string
    {
        return app()->storagePath('files' . DIRECTORY_SEPARATOR . 'conversations' . DIRECTORY_SEPARATOR . 'id_' . $conversationId);
    }

    private function defaultMediaDirectory(): string
    {
        return app()->storagePath('files' . DIRECTORY_SEPARATOR . 'conversations' . DIRECTORY_SEPARATOR . 'default');
    }

    private function isManagedDefaultMediaName(string $filename): bool
    {
        return preg_match('/^default_[a-f0-9]{48}\.(?:jpg|png|webp)$/', $filename) === 1;
    }

    private function defaultMediaResponse(): Response
    {
        $policy = ConversationGroupPolicy::find(1);
        $configuredName = (string) ($policy?->getAttribute('default_media_path') ?? '');
        if ($this->isManagedDefaultMediaName($configuredName)) {
            $path = $this->defaultMediaDirectory() . DIRECTORY_SEPARATOR . $configuredName;
        } else {
            $definition = app()->get(\Core\Services\IndependentServiceRegistry::class)->get('messaging');
            $resources = $definition?->componentPath('resources');
            if ($resources === null) {
                throw new RuntimeException('Les ressources du service de messagerie sont introuvables.');
            }
            $path = $resources . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
                . 'images' . DIRECTORY_SEPARATOR . self::DEFAULT_MEDIA_IMAGE;
        }
        if (!is_file($path)) {
            throw new RuntimeException('Le média de remplacement de conversation est introuvable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Impossible de lire le média de remplacement de conversation.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Le média de remplacement de conversation a un format invalide.');
        }

        return new Response($contents, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) {
                if (!unlink($entry->getPathname())) {
                    throw new RuntimeException('Impossible de supprimer un lien média de la conversation.');
                }
            } elseif ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } elseif (!unlink($entry->getPathname())) {
                throw new RuntimeException('Impossible de supprimer le dossier média de la conversation.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Impossible de supprimer le dossier média de la conversation.');
        }
    }

    private function validatedBody(Request $request, array $allowed): array
    {
        $body = $request->isJson() ? $request->json() : $request->all();
        // $token = $body['csrf_token'] ?? null;
        // if (!is_string($token) || !$this->csrfManager->validateToken($token)) {
        //     $this->throwCsrf();
        // }
        return array_intersect_key($body, array_flip($allowed));
    }

    private function throwCsrf(): never
    {
        throw new InvalidArgumentException('Le jeton CSRF est invalide ou expiré. Actualisez la page.');
    }

    private function validatedId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Identifiant invalide.');
        }
        return $id;
    }

    //Helpers pour admin

    private function adminJson(callable $callback): Response
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponse(['success' => false, 'errors' => $e->getMessage()], 422);
        } catch (InputErrorException | InvalidCredentianlsException | FailedProcessingException | NotFoundHttpException | GateException $e) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $e->getErrors() ?: ($e->getMessage() ?: 'Action non autorisée.'),
            ]);
        }
    }

    /** @return array{search:string,type:string,status:string,kind:string,conversation_id:?int,date_from:?string,date_to:?string} */
    private function normalizeAdminFilters(array $body): array
    {
        $text = static fn(mixed $v): string => is_string($v) ? $v : '';
        $date = static fn(mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $type = $text($body['type'] ?? null);
        $status = $text($body['status'] ?? null);
        $kind = $text($body['kind'] ?? null);
        $conversationId = filter_var($body['conversation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'search' => mb_substr(trim($text($body['search'] ?? null)), 0, 80),
            'type' => in_array($type, ['text', 'image'], true) ? $type : 'all',
            'status' => in_array($status, ['active', 'finished', 'preserved'], true) ? $status : 'all',
            'kind' => in_array($kind, ['direct', 'group'], true) ? $kind : 'all',
            'conversation_id' => $conversationId === false ? null : $conversationId,
            'date_from' => $date($body['date_from'] ?? null),
            'date_to' => $date($body['date_to'] ?? null),
        ];
    }

    /** @return array{0:int,1:int} [page, perPage] */
    private function pageParams(array $body): array
    {
        $perPage = (int) ($body['per_page'] ?? 10);
        if (!in_array($perPage, [5, 10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        return [max(1, (int) ($body['page'] ?? 1)), $perPage];
    }

    /** @return array{current_page:int,per_page:int,last_page:int,total:int} */
    private function pageMeta(int $page, int $perPage, int $total): array
    {
        $last = max(1, (int) ceil($total / $perPage));

        return ['current_page' => min($page, $last), 'per_page' => $perPage, 'last_page' => $last, 'total' => $total];
    }

    /** @return array{0:string,1:string} [colonne SQL, ASC|DESC] — liste blanche stricte. */
    private function sortParams(array $body, array $map, string $defaultColumn, string $defaultDir): array
    {
        $key = is_string($body['sort_by'] ?? null) ? $body['sort_by'] : '';
        $dirRaw = is_string($body['sort_dir'] ?? null) ? strtoupper($body['sort_dir']) : $defaultDir;

        return [$map[$key] ?? $defaultColumn, $dirRaw === 'ASC' ? 'ASC' : 'DESC'];
    }

    private function applyDateRange($query, string $column, array $filters): void
    {
        if ($filters['date_from'] !== null) {
            $query->where($column, '>=', $filters['date_from'] . ' 00:00:00');
        }
        if ($filters['date_to'] !== null) {
            $query->where($column, '<=', $filters['date_to'] . ' 23:59:59');
        }
    }

    private function adminConversationsQuery(User $user, array $filters, bool $withSelect)
    {
        $query = Conversation::table('conversations as c');
        if ($withSelect) {
            $query->select(
                'c.id',
                'c.subject',
                'c.is_group',
                'c.group_id',
                'c.created_by',
                'c.status',
                'c.message_count',
                'c.message_limit',
                'c.created_at',
                'c.finished_at',
                'c.preserved_at',
                'c.expires_at'
            );
        }
        if (!$user->isSuper()) {
            $userId = (int) $user->getKey();
            $query->leftJoin('conversation_user as cu', function ($join) use ($userId): void {
                $join->on('cu.conversation_id', '=', 'c.id')
                    ->where('cu.user_id', '=', $userId)
                    ->whereRaw('cu.left_at IS NULL');
            })
                ->leftJoin('groups as g', 'g.id', '=', 'c.group_id')
                ->leftJoin('group_user as gu', function ($join) use ($userId): void {
                    $join->on('gu.group_id', '=', 'c.group_id')
                        ->where('gu.user_id', '=', $userId);
                })
                ->where(function ($scope) use ($userId): void {
                    $scope->whereNotNull('cu.user_id')
                        ->orWhereNotNull('gu.user_id')
                        ->orWhere('g.owner_id', '=', $userId);
                });
        }

        if ($filters['search'] !== '') {
            $like = '%' . $filters['search'] . '%';
            $search = $filters['search'];
            $query->where(function ($w) use ($like, $search): void {
                $w->where('c.subject', 'LIKE', $like);
                if (ctype_digit($search)) {
                    $w->orWhere('c.id', '=', (int) $search);
                }
            });
        }
        if ($filters['status'] === 'active' || $filters['status'] === 'finished') {
            $query->where('c.status', '=', $filters['status']);
        } elseif ($filters['status'] === 'preserved') {
            $query->whereNotNull('c.preserved_at');
        }
        if ($filters['kind'] !== 'all') {
            $query->where('c.is_group', '=', $filters['kind'] === 'group' ? 1 : 0);
        }
        $this->applyDateRange($query, 'c.created_at', $filters);

        return $query;
    }

    private function adminMessagesQuery(array $filters, bool $withSelect)
    {
        $query = ConversationMessage::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.sender_id');
        if ($withSelect) {
            $query->select(
                'm.id',
                'm.conversation_id',
                'm.sender_id',
                'm.body',
                'm.message_type',
                'm.media_path',
                'm.media_deleted_at',
                'm.media_preserved',
                'm.media_mime_type',
                'm.created_at',
                'u.name as sender_name',
                'u.username as sender_username',
                'c.subject',
                'c.is_group',
                'c.status as conversation_status',
                'c.preserved_at'
            );
        }

        if ($filters['search'] !== '') {
            $like = '%' . $filters['search'] . '%';
            $search = $filters['search'];
            $query->where(function ($w) use ($like, $search): void {
                $w->where('m.body', 'LIKE', $like)
                    ->orWhere('u.name', 'LIKE', $like)
                    ->orWhere('u.username', 'LIKE', $like)
                    ->orWhere('c.subject', 'LIKE', $like);
                if (ctype_digit($search)) {
                    $w->orWhere('m.id', '=', (int) $search)
                        ->orWhere('m.conversation_id', '=', (int) $search);
                }
            });
        }
        if ($filters['type'] !== 'all') {
            $query->where('m.message_type', '=', $filters['type']);
        }
        if ($filters['kind'] !== 'all') {
            $query->where('c.is_group', '=', $filters['kind'] === 'group' ? 1 : 0);
        }
        if ($filters['conversation_id'] !== null) {
            $query->where('m.conversation_id', '=', $filters['conversation_id']);
        }
        $this->applyDateRange($query, 'm.created_at', $filters);

        return $query;
    }

    /** @param list<int> $conversationIds @return array<int,string> "Alice, Bob +3" par conversation (1 requête). */
    private function participantLabels(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $names = [];
        foreach (
            ConversationUser::table('conversation_user as cu')
                ->join('users as u', 'u.id', '=', 'cu.user_id')
                ->whereIn('cu.conversation_id', $conversationIds)
                ->select('cu.conversation_id', 'u.name as participant_name')
                ->orderBy('u.name', 'ASC')
                ->get() as $row
        ) {
            $names[(int) $row->getAttribute('conversation_id')][] = (string) $row->getAttribute('participant_name');
        }

        return array_map(static function (array $list): string {
            $label = implode(', ', array_slice($list, 0, 4));

            return count($list) > 4 ? $label . ' +' . (count($list) - 4) : $label;
        }, $names);
    }

    /**
     * Suppression définitive de messages (reçus + fichier image + last_message_id).
     * Les fichiers sont supprimés APRÈS le commit : si la transaction échoue, rien n'est perdu.
     * `message_count` n'est volontairement PAS décrémenté (c'est un quota de volume de la conversation).
     *
     * @param array<int|string> $ids
     */
    private function deleteMessages(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        $deleted = 0;

        foreach (array_chunk($ids, 200) as $chunk) {
            $messages = ConversationMessage::table()->whereIn('id', $chunk)->get();
            if ($messages === []) {
                continue;
            }
            $messageIds = [];
            $conversationIds = [];
            $files = [];
            foreach ($messages as $message) {
                $messageIds[] = (int) $message->getKey();
                $conversationId = (int) $message->getAttribute('conversation_id');
                $conversationIds[$conversationId] = true;
                $path = (string) ($message->getAttribute('media_path') ?? '');
                if ($path !== '') {
                    $files[] = $this->mediaDirectory($conversationId) . DIRECTORY_SEPARATOR . basename($path);
                }
            }

            Conversation::table()->transactionOrThrow(function () use ($messageIds, $conversationIds): void {
                ConversationMessageReceipt::table()->whereIn('message_id', $messageIds)->forceDelete();
                ConversationMessage::table()->whereIn('id', $messageIds)->forceDelete();
                foreach (array_keys($conversationIds) as $conversationId) {
                    $last = ConversationMessage::where('conversation_id', '=', $conversationId)
                        ->orderBy('id', 'DESC')
                        ->first();
                    Conversation::where('id', '=', $conversationId)->update([
                        'last_message_id' => $last instanceof ConversationMessage ? (int) $last->getKey() : null,
                    ]);
                }
            });

            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $deleted += count($messageIds);
        }

        return $deleted;
    }
    private function can(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    private function requirePermission(User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new GateException();
        }
    }

    private function permissionForAction(string $action): string
    {
        return match ($action) {
            'preserve' => 'conversations.preserve',
            'terminate', 'finish' => 'conversations.suspend',
            'delete' => 'conversations.delete',
            default => throw new InvalidArgumentException('Action de conversation invalide.'),
        };
    }

    private function canDoAction(User $user, Conversation $conversation, string $action): bool
    {
        if (!$this->can($user, $this->permissionForAction($action))) {
            return false;
        }

        return $action === 'preserve'
            ? $this->canPreserveConversation($user, $conversation)
            : $this->canControlConversation($user, $conversation);
    }

    private function assertCanDoAction(User $user, Conversation $conversation, string $action): void
    {
        if (!$this->canDoAction($user, $conversation, $action)) {
            throw new GateException();
        }
    }
    private function canAccessConversation(User $user, Conversation $conversation): bool
    {
        return $user->isSuper() || $conversation->isParticipant((int) $user->getKey());
    }

    private function conversationLabel(Conversation $conversation, int $viewerId): string
    {
        if ($conversation->getAttribute('is_group')) {
            return (string) ($conversation->getAttribute('subject') ?? '');
        }
        $names = [];
        foreach ($conversation->participantIds() as $participantId) {
            if ($participantId === $viewerId) {
                continue;
            }
            $participant = User::find($participantId);
            if ($participant instanceof User) {
                $names[] = (string) $participant->getAttribute('name');
            }
        }

        return $names === [] ? 'Utilisateur supprimé' : implode(' & ', $names);
    }
}
