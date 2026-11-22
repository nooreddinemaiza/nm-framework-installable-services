<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog;

use App\Models\User;
use NM\Services\InventoryService\Models\CatalogProduct;
use NM\Services\InventoryService\Models\CatalogProductMedia;
use NM\Services\InventoryService\Models\InventoryRecord;
use Core\Database\Connection;
use Core\Exceptions\Http\NotFoundHttpException;
use Core\Exceptions\Http\UnprocessableEntityException;
use Core\Filesystem\FileInterface;
use Core\Filesystem\FileReaderInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\UploadedFile;
use Core\Support\Asset\AssetConfig;
use Core\Support\Asset\AssetManager;
use Core\Support\Asset\AssetServer;
use Core\Support\Asset\Exception\AssetForbiddenException;
use Core\Support\Data\Data;

final class CatalogMediaService
{
    private const MAX_VIDEO_BYTES = 10 * 1024 * 1024;
    private const VIDEO_MIME_EXTENSIONS = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FileInterface $files,
        private readonly FileReaderInterface $reader,
    ) {
        $this->files->setPath('catalog_media', 'storage/files/catalog-media');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forProduct(int $productId): array
    {
        $rows = CatalogProductMedia::table('catalog_product_media as pm')
            ->leftJoin('media as m', 'm.id', '=', 'pm.media_id')
            ->where('pm.product_id', '=', $productId)
            ->select(
                'pm.id',
                'pm.media_type',
                'pm.external_url',
                'pm.caption',
                'm.id as media_id',
                'm.path',
                'm.original_name',
                'm.mime_type',
                'm.size_in_bytes',
            )
            ->orderBy('pm.sort_order')
            ->orderBy('pm.id')
            ->get();
        $rows = array_map(static fn(CatalogProductMedia $media): array => $media->toArray(), $rows);
        foreach ($rows as &$row) {
            $row['url'] = $row['external_url'] !== null
                ? (string) $row['external_url']
                : '/product-media/' . (int) $row['media_id'];
        }
        unset($row);

        return $rows;
    }

    public function addExternal(
        int $productId,
        string $mediaType,
        string $url,
        string $caption,
        User $actor,
    ): void {
        $this->authorize($actor);
        $url = trim($url);
        $this->validateProduct($productId);
        $this->validateDescription($mediaType, $caption);
        $input = Data::create(['url' => $url]);
        $errors = $input->validate(['url' => 'required|string|url']);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        $parts = parse_url($url);
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])) {
            throw new UnprocessableEntityException(['url' => ['Le lien du média doit être une URL HTTPS valide.']]);
        }

        $now = date('Y-m-d H:i:s');
        CatalogProductMedia::query()->insert([
            'product_id' => $productId,
            'media_id' => null,
            'media_type' => $mediaType,
            'external_url' => $url,
            'caption' => $this->nullableCaption($caption),
            'sort_order' => $this->nextOrder($productId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function addUpload(int $productId, UploadedFile $file, string $caption, User $actor): void
    {
        $this->authorize($actor);
        $this->validateProduct($productId);
        $this->validateDescription('image', $caption);
        if (!$file->isValid() || $file->getSize() <= 0) {
            throw new UnprocessableEntityException([
                'file' => ['Le fichier média est vide ou n’a pas été téléversé correctement.'],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getTmpPath());
        if (!is_string($mime)) {
            throw new UnprocessableEntityException(['file' => ['Le type réel du fichier média ne peut pas être détecté.']]);
        }
        $mediaType = str_starts_with($mime, 'image/') ? 'image' : 'video';
        $this->validateDescription($mediaType, $caption);
        $filename = bin2hex(random_bytes(24));
        $assetServer = new AssetServer($this->files, $this->reader, AssetConfig::default());

        try {
            if ($mediaType === 'image') {
                if (!extension_loaded('gd')) {
                    throw new UnprocessableEntityException([
                        'file' => ['L’extension PHP GD doit être activée pour téléverser des images.'],
                    ]);
                }
                $info = $assetServer->storeImage($file, 'catalog_media', $filename);
            } else {
                $extension = self::VIDEO_MIME_EXTENSIONS[$mime] ?? null;
                if ($extension === null || $file->getSize() > self::MAX_VIDEO_BYTES) {
                    throw new UnprocessableEntityException([
                        'file' => ['Les vidéos doivent être au format MP4 ou WebM et ne pas dépasser 10 Mo.'],
                    ]);
                }
                $filename .= '.' . $extension;
                $info = $assetServer->store($file, 'catalog_media', $filename);
            }
        } catch (AssetForbiddenException $exception) {
            throw $exception;
        }

        $storedName = (string) ($info['stored_name'] ?? '');
        try {
            $this->connection->transaction(function () use ($productId, $file, $mime, $mediaType, $caption, $storedName): void {
                $now = date('Y-m-d H:i:s');
                $mediaId = InventoryRecord::table('media')->insertGetId([
                    'mediable_type' => 'catalog_product',
                    'mediable_id' => $productId,
                    'disk' => 'catalog_media',
                    'path' => $storedName,
                    'original_name' => $this->safeOriginalName($file->getOriginalName()),
                    'mime_type' => $mime,
                    'size_in_bytes' => $this->files->getSize('catalog_media', $storedName),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                CatalogProductMedia::query()->insert([
                    'product_id' => $productId,
                    'media_id' => $mediaId,
                    'media_type' => $mediaType,
                    'external_url' => null,
                    'caption' => $this->nullableCaption($caption),
                    'sort_order' => $this->nextOrder($productId),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->files->delete('catalog_media', $storedName);
            throw $exception;
        }
    }

    public function delete(int $mediaId, int $productId, User $actor): void
    {
        $this->authorize($actor);
        $row = CatalogProductMedia::table('catalog_product_media as pm')
            ->leftJoin('media as m', 'm.id', '=', 'pm.media_id')
            ->where('pm.id', '=', $mediaId)
            ->where('pm.product_id', '=', $productId)
            ->select('pm.id', 'm.id as media_id', 'm.path')
            ->first();
        if ($row === null) {
            throw new NotFoundHttpException('Ce média est introuvable.');
        }
        $row = $row->toArray();

        $this->connection->transaction(function () use ($mediaId, $row): void {
            CatalogProductMedia::query()->where('id', '=', $mediaId)->delete();
            if ($row['media_id'] !== null) {
                InventoryRecord::table('media')->where('id', '=', (int) $row['media_id'])->delete();
                $this->connection->afterCommit(function () use ($row): void {
                    $this->files->delete('catalog_media', (string) $row['path']);
                });
            }
        });
    }

    public function deleteForProduct(int $productId): void
    {
        $rows = CatalogProductMedia::table('catalog_product_media as pm')
            ->join('media as m', 'm.id', '=', 'pm.media_id')
            ->where('pm.product_id', '=', $productId)
            ->select('m.id', 'm.path')
            ->get();
        $rows = array_map(static fn(CatalogProductMedia $media): array => $media->toArray(), $rows);
        CatalogProductMedia::query()->where('product_id', '=', $productId)->delete();
        foreach ($rows as $row) {
            InventoryRecord::table('media')->where('id', '=', (int) $row['id'])->delete();
            $this->connection->afterCommit(function () use ($row): void {
                $this->files->delete('catalog_media', (string) $row['path']);
            });
        }
    }

    public function respondPublicMedia(Request $request, int $mediaId): Response
    {
        $row = CatalogProductMedia::table('catalog_product_media as pm')
            ->join('media as m', 'm.id', '=', 'pm.media_id')
            ->join(CatalogProduct::getTable() . ' as p', 'p.id', '=', 'pm.product_id')
            ->where('m.id', '=', $mediaId)
            ->where('m.disk', '=', 'catalog_media')
            ->where('m.mediable_type', '=', 'catalog_product')
            ->whereColumn('m.mediable_id', '=', 'p.id')
            ->where('p.status', '=', 'active')
            ->whereNull('pm.external_url')
            ->select('m.path')
            ->first();
        $filename = $row === null ? '' : (string) $row->path;
        if ($filename === '' || basename($filename) !== $filename || !$this->files->exists('catalog_media', $filename)) {
            throw new NotFoundHttpException();
        }

        return AssetManager::create($this->files, $this->reader)
            ->respond($request, 'catalog_media', $filename);
    }

    private function validateProduct(int $productId): void
    {
        if ($productId <= 0 || !CatalogProduct::query()->where('id', '=', $productId)->exists()) {
            throw new NotFoundHttpException('Ce produit est introuvable.');
        }
    }

    private function validateDescription(string $mediaType, string $caption): void
    {
        $input = Data::create(['media_type' => $mediaType, 'caption' => $caption]);
        $errors = $input->validate([
            'media_type' => 'required|in:image,video',
            'caption' => 'nullable|string',
        ]);
        if ($errors !== []) {
            throw new UnprocessableEntityException($errors);
        }
        if (function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') > 500 : strlen($caption) > 500) {
            throw new UnprocessableEntityException(['caption' => ['La légende ne peut pas dépasser 500 caractères.']]);
        }
    }

    private function nullableCaption(string $caption): ?string
    {
        $caption = trim($caption);

        return $caption !== '' ? $caption : null;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'media';
        }

        return substr($name, 0, 255);
    }

    private function nextOrder(int $productId): int
    {
        $result = CatalogProductMedia::query()
            ->where('product_id', '=', $productId)
            ->selectRaw('COALESCE(MAX(sort_order), -1) + 1 AS next_order')
            ->first();

        return (int) ($result?->next_order ?? 0);
    }

    private function authorize(User $actor): void
    {
        if (!$actor->isSuper() && !$actor->hasPermission('catalog.media.upload')) {
            throw new \Core\Exceptions\Http\GateException();
        }
    }
}
