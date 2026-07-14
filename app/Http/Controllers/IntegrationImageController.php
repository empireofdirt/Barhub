<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Отдаёт картинку в формате JPEG для Eventicious: их API принимает только
 * image/jpeg и image/png, а сайт хранит webp/avif. Конвертируем по требованию
 * и кэшируем результат на диске.
 */
final class IntegrationImageController extends Controller
{
    public function show(Image $image): BinaryFileResponse
    {
        $disk = Storage::disk('public');

        // В БД webp хранится полным URL (Storage::url), а диску нужен
        // относительный путь — вырезаем часть после /storage/.
        $source = $this->relativePath($image->webp);

        abort_unless($source && $disk->exists($source), 404);

        $jpgPath = "integration-jpg/{$image->id}.jpg";
        $sourcePath = $disk->path($source);

        $stale = !$disk->exists($jpgPath)
            || filemtime($sourcePath) > filemtime($disk->path($jpgPath));

        if ($stale) {
            $disk->makeDirectory('integration-jpg');

            $manager = new ImageManager(new Driver());
            $manager->read($sourcePath)
                ->toJpeg(85)
                ->save($disk->path($jpgPath));
        }

        return response()->file($disk->path($jpgPath), [
            'Content-Type' => 'image/jpeg',
        ]);
    }

    /**
     * Приводит хранимое значение (полный URL вида
     * https://host/storage/uploads/...) к пути относительно public-диска.
     */
    private function relativePath(?string $stored): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $path = parse_url($stored, PHP_URL_PATH) ?: $stored;

        return ltrim(Str::after($path, '/storage/'), '/');
    }
}
