<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Shared\Net;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives the demo cafés' dishes real photos from Wikimedia Commons (free licences), with author and licence recorded.
 * Photos are downloaded once into public/storage/demo-menu and re-linked on every run, so after
 * `migrate:fresh --seed` just run `php artisan menu:demo-photos` again. Real cafés upload their own photos.
 */
class DemoMenuPhotos extends Command
{
    protected $signature = 'menu:demo-photos {--refresh : download again even if a photo is already saved}';
    protected $description = 'Attach free-licence stock photos (Wikimedia Commons) to the demo menu items';

    /** Dish name => Commons search terms. */
    private const QUERIES = [
        'Bun Maska' => 'bun maska', 'Butter Tea' => 'butter tea', 'Cheese Fries' => 'cheese fries', 'Cheese Maggi' => 'File:Maggi Bowl.jpg',
        'Chicken Fried Rice' => 'chicken fried rice', 'Chicken Momo' => 'chicken momo', 'Chicken Nuggets (6)' => 'chicken nuggets', 'Chicken Roll' => 'kolkata chicken roll',
        'Chicken Steamed Momo' => 'steamed momo dumplings', 'Chicken Thali' => 'thali chicken curry', 'Chicken Thukpa' => 'thukpa', 'Chicken Zinger Burger' => 'File:Fried chicken burger .jpg',
        'Chilli Chicken' => 'chilli chicken', 'Chocolate Brownie' => 'chocolate brownie', 'Cold Coffee' => 'cold coffee', 'Date Pancake' => 'pancake',
        'Egg Fried Rice' => 'egg fried rice', 'Egg Roll' => 'egg roll kolkata', 'Egg Wrap' => 'egg roll wrap', 'Filter Coffee' => 'south indian filter coffee',
        'Fish Thali' => 'bengali fish thali', 'Fresh Lime Soda' => 'lime soda', 'Ginger Chai' => 'ginger tea cup', 'Green Tea' => 'green tea cup',
        'Gulab Jamun' => 'gulab jamun', 'Honey Chilli Potato' => 'honey chilli potato', 'Kolkata Chicken Biryani' => 'kolkata biryani', 'Kulhad Chai' => 'kulhad chai',
        'Lemon Iced Tea' => 'iced tea lemon', 'Lemon Tea' => 'lemon tea', 'Manchow Soup' => 'manchow soup', 'Masala Chai' => 'masala chai',
        'Masala Maggi' => 'maggi noodles', 'Matcha Latte' => 'matcha latte', 'Mishti Doi' => 'mishti doi', 'Mutton Biryani' => 'mutton biryani',
        'Oreo Shake' => 'oreo milkshake', 'Paneer Chilli' => 'chilli paneer', 'Paneer Fried Momo' => 'fried momo', 'Paneer Roll' => 'paneer roll',
        'Paneer Tikka Burger' => 'paneer burger', 'Paneer Wrap' => 'paneer wrap', 'Peri Peri Fries' => 'french fries', 'Plain Maggi' => 'maggi instant noodles',
        'Pork Momo' => 'pork momo', 'Rasgulla (2)' => 'rasgulla', 'Strawberry Shake' => 'File:Strawberry milk shake (cropped).jpg', 'Veg Biryani' => 'vegetable biryani',
        'Veg Crunch Burger' => 'veggie burger', 'Veg Hakka Noodles' => 'hakka noodles', 'Veg Puff' => 'vegetable puff pastry', 'Veg Sandwich' => 'vegetable sandwich',
        'Veg Steamed Momo' => 'vegetable momo', 'Veg Thali' => 'vegetarian thali', 'Veg Thukpa' => 'thukpa noodle soup',
    ];

    private const ALLOWED = ['cc0', 'cc by', 'cc-by', 'public domain', 'pd'];

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $creditsFile = 'demo-menu/credits.json';
        $credits = $disk->exists($creditsFile) ? json_decode($disk->get($creditsFile), true) : [];
        $names = DB::table('items')->whereNull('deleted_at')->distinct()->pluck('name');
        $linked = 0;
        foreach ($names as $name) {
            $slug = Str::slug($name);
            $path = "demo-menu/$slug.jpg";
            if ($this->option('refresh') || !$disk->exists($path)) {
                $photo = $this->find(self::QUERIES[$name] ?? $name);
                if (!$photo) {
                    $this->warn("No free photo found for $name");
                    continue;
                }
                $bytes = Net::http(30)->get($photo['url'])->body();
                $disk->put($path, $bytes);
                $credits[$name] = $photo['credit'];
                usleep(250000); // be gentle with Commons
            }
            $url = rtrim(config('app.url'), '/') . '/storage/' . $path;
            // Demo cafés only: a real café's own uploads are never replaced.
            $linked += DB::table('items')->where('name', $name)->whereIn('tenant_id', ['tn_chai_adda', 'tn_momo_mahal', 'tn_brew_bros', 'tn_tiffin_tales'])
                ->where(fn ($q) => $q->whereNull('photo_url')->orWhere('photo_url', 'like', '%/storage/demo-menu/%'))->update(['photo_url' => $url]);
            $this->line("✓ $name");
        }
        $disk->put($creditsFile, json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->writeCreditsDoc($credits);
        $this->info("Linked photos to $linked menu items. Credits: public/storage/$creditsFile and public/storage/demo-menu/photo-credits.md");
        return self::SUCCESS;
    }

    /** First freely licensed photo on Commons for the search terms (or an exact "File:" title), as a 640 px wide JPEG. */
    private function find(string $query): ?array
    {
        $by = str_starts_with($query, 'File:') ? ['titles' => $query] : ['generator' => 'search', 'gsrnamespace' => 6, 'gsrsearch' => "$query filetype:bitmap", 'gsrlimit' => 10];
        $res = Net::http()->get('https://commons.wikimedia.org/w/api.php', $by + [
            'action' => 'query', 'format' => 'json', 'prop' => 'imageinfo', 'iiprop' => 'url|mime|extmetadata', 'iiurlwidth' => 640,
        ])->json();
        $pages = $res['query']['pages'] ?? [];
        uasort($pages, fn ($a, $b) => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));
        foreach ($pages as $p) {
            $ii = $p['imageinfo'][0] ?? null;
            if (!$ii || !in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png'], true)) continue;
            $meta = $ii['extmetadata'] ?? [];
            $licence = strip_tags($meta['LicenseShortName']['value'] ?? '');
            if (!collect(self::ALLOWED)->contains(fn ($a) => str_starts_with(strtolower($licence), $a))) continue;
            return ['url' => $ii['thumburl'] ?? $ii['url'], 'credit' => [
                'title' => $p['title'], 'author' => trim(strip_tags($meta['Artist']['value'] ?? 'Unknown')), 'licence' => $licence,
                'licence_url' => $meta['LicenseUrl']['value'] ?? null, 'source' => $ii['descriptionurl'] ?? null,
            ]];
        }
        return null;
    }

    private function writeCreditsDoc(array $credits): void
    {
        ksort($credits);
        $md = "# Demo menu photo credits\n\nPhotos of the demo cafés' dishes come from Wikimedia Commons under free licences. Real cafés upload their own photos.\n\n| Dish | Photo | Author | Licence |\n| --- | --- | --- | --- |\n";
        foreach ($credits as $dish => $c) {
            $md .= sprintf("| %s | [%s](%s) | %s | %s |\n", $dish, str_replace('File:', '', $c['title']), $c['source'], str_replace('|', '/', $c['author']), $c['licence_url'] ? "[{$c['licence']}]({$c['licence_url']})" : $c['licence']);
        }
        file_put_contents(public_path('storage/demo-menu/photo-credits.md'), $md);
    }
}
