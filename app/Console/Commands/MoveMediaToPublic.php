<?php

namespace App\Console\Commands;

use App\Core\Schema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-off move of uploads from storage/app/public (the old storage:link setup) into public/storage,
 * and rewrites saved image links to this server's APP_URL, e.g. after moving from localhost to the live domain.
 * Safe to run again: files already moved are skipped and links already on APP_URL are left alone.
 */
class MoveMediaToPublic extends Command
{
    protected $signature = 'media:to-public {--from-url=http://localhost:8000 : the old address saved in image links}';
    protected $description = 'Move uploaded images into public/storage and point saved links at APP_URL';

    public function handle(): int
    {
        $old = storage_path('app/public');
        $new = public_path('storage');
        // The old setup made public/storage a link to storage/app/public; replace it with a real folder.
        if (is_link($new) || (file_exists($new) && realpath($new) !== realpath(dirname($new)) . DIRECTORY_SEPARATOR . 'storage')) {
            @unlink($new) || @rmdir($new);
            $this->info('Removed the old public/storage link.');
        }
        File::ensureDirectoryExists($new);
        $moved = 0;
        if (is_dir($old)) {
            foreach (File::allFiles($old) as $f) {
                if ($f->getFilename() === '.gitignore') continue;
                $to = $new . DIRECTORY_SEPARATOR . $f->getRelativePathname();
                if (file_exists($to)) continue;
                File::ensureDirectoryExists(dirname($to));
                File::copy($f->getPathname(), $to);
                $moved++;
            }
        }
        $this->info("Copied $moved file(s) into public/storage.");

        $from = rtrim((string) $this->option('from-url'), '/') . '/storage/';
        $to = rtrim((string) config('app.url'), '/') . '/storage/';
        $links = 0;
        if ($from !== $to) {
            foreach (array_keys(Schema::tables()) as $table) {
                foreach (Schema::columns($table) as $col => $type) {
                    if (!in_array($type, ['s', 't'], true) || !str_ends_with($col, '_url')) continue;
                    $links += DB::table($table)->where($col, 'like', $from . '%')
                        ->update([$col => DB::raw('REPLACE(`' . $col . '`, ' . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')')]);
                }
            }
        }
        $this->info("Updated $links saved image link(s) to $to");
        return self::SUCCESS;
    }
}
