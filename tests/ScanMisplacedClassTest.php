<?php

use LaravelArchitect\Architect\Scanner\ProjectScanner;
use LaravelArchitect\Architect\Scanner\Recommender;

function fakeProject(array $files): string
{
    $base = sys_get_temp_dir().'/architect_'.uniqid();

    foreach ($files as $key => $value) {
        [$path, $content] = is_int($key) ? [$value, null] : [$key, $value];
        $full = $base.'/'.$path;
        is_dir(dirname($full)) || mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content ?? (str_ends_with($path, '.php') ? "<?php\n" : ''));
    }

    return $base;
}

it('recommends moving PaymongoRepository into the existing Payment folder with a reason', function () {
    $base = fakeProject([
        'app/Repositories/PaymongoRepository.php' => <<<'PHP'
        <?php
        namespace App\Repositories;
        use App\Models\Payment;
        use Illuminate\Support\Facades\Http;
        class PaymongoRepository
        {
            public function createPayment(array $data) { Http::post('https://api.paymongo.com/v1/payments', $data); return Payment::create($data); }
            public function markAsPaid(int $id) { return Payment::whereKey($id)->update(['paid' => true]); }
        }
        PHP,
        'app/Payment/PaymentService.php',
    ]);

    $scan = (new ProjectScanner($base, null, [
        'Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']],
    ]))->scan();

    expect($scan['misplaced'])->toHaveCount(1);
    expect($scan['misplaced'][0])
        ->target->toBe('app/Payment/Repositories/PaymongoRepository.php')
        ->reason->toBe('PaymongoRepository reads and writes Payment model through createPayment() and markAsPaid() and calls api.paymongo.com, so it is part of your Paymongo payment gateway. Payment already holds PaymentService, so keep them together.');

    $recs = (new Recommender())->fromScan($scan);
    expect($recs[0]['message'])->toContain('Move PaymongoRepository into the Payment folder');
    expect($recs[0]['reason'])->toContain('api.paymongo.com');
});

it('uses a domain folder that lives inside the layer folder', function () {
    $base = fakeProject(['app/Repositories/PaymongoRepository.php', 'app/Repositories/Payment/.gitkeep']);

    $scan = (new ProjectScanner($base, null, [
        'Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']],
    ]))->scan();

    expect($scan['misplaced'][0]['target'])->toBe('app/Repositories/Payment/PaymongoRepository.php');
});

it('ignores classes already inside a domain folder or unrelated to any domain', function () {
    $base = fakeProject([
        'app/Repositories/Payment/PaymongoRepository.php',
        'app/Repositories/UserRepository.php',
        'app/Payment/.gitkeep',
    ]);

    $scan = (new ProjectScanner($base, null, [
        'Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']],
    ]))->scan();

    expect($scan['misplaced'])->toBe([]);
});

it('deep scan finds domain classes outside their layer folder, shallow scan does not', function () {
    $domains = ['Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']]];
    $base = fakeProject([
        'app/Helpers/PaymongoService.php',
        'app/Repositories/Gateways/PaymongoRepository.php',
        'app/Payment/.gitkeep',
    ]);

    $shallow = (new ProjectScanner($base, null, $domains))->scan();
    expect($shallow['misplaced'])->toBe([]);

    $deep = (new ProjectScanner($base, null, $domains))->scan([], [], true);
    expect(array_column($deep['misplaced'], 'file'))->toBe([
        'app/Repositories/Gateways/PaymongoRepository.php',
        'app/Helpers/PaymongoService.php',
    ]);
});

it('limits the scan to the requested layer', function () {
    $domains = ['Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']]];
    $base = fakeProject([
        'app/Repositories/PaymongoRepository.php',
        'app/Services/PaymongoService.php',
        'app/Payment/.gitkeep',
    ]);

    $scan = (new ProjectScanner($base, null, $domains))->scan([], ['repositories']);

    expect(array_column($scan['misplaced'], 'class'))->toBe(['PaymongoRepository']);
    expect($scan['scope']['layers'])->toBe(['repositories']);
});

it('discovers domains from existing folders without any configuration', function () {
    $base = fakeProject([
        'app/Customer/Repositories/CustomerDetailsRepository.php',
        'app/Repositories/CustomerAccountRepository.php',
        'app/Services/CustomerAccount.php',
        'app/Repositories/UserRepository.php',
        'app/Http/Controllers/CustomerController.php',
    ]);

    $scan = (new ProjectScanner($base))->scan();

    expect(array_column($scan['misplaced'], 'target', 'file'))->toBe([
        'app/Repositories/CustomerAccountRepository.php' => 'app/Customer/Repositories/CustomerAccountRepository.php',
        'app/Services/CustomerAccount.php' => 'app/Customer/Services/CustomerAccount.php',
    ]);
    expect($scan['misplaced'][0]['reason'])
        ->toContain('CustomerAccountRepository reads and writes customer account data')
        ->toContain('Customer already holds CustomerDetailsRepository');
});

it('gives a different reason for each class based on what it does', function () {
    $base = fakeProject([
        'app/Repositories/ShopOptionsRepository.php' => <<<'PHP'
        <?php
        namespace App\Repositories;
        use App\Models\ShopOption;
        class ShopOptionsRepository
        {
            public function getOptions() { return ShopOption::all(); }
            public function updateOptions(array $o) { return ShopOption::upsert($o, ['key']); }
        }
        PHP,
        'app/Repositories/PaymongoRepository.php' => <<<'PHP'
        <?php
        namespace App\Repositories;
        use Illuminate\Support\Facades\DB;
        class PaymongoRepository
        {
            public function record(array $d) { return DB::table('paymongo_transactions')->insert($d); }
        }
        PHP,
        'app/Shop/ShopService.php',
        'app/Payment/PaymentService.php',
    ]);

    $scan = (new ProjectScanner($base, null, [
        'Payment' => ['label' => 'payment gateway', 'keywords' => ['paymongo']],
    ]))->scan();

    $reasons = array_column($scan['misplaced'], 'reason', 'class');

    expect($reasons['ShopOptionsRepository'])
        ->toContain('ShopOption model')
        ->toContain('getOptions() and updateOptions()')
        ->toContain('Shop already holds ShopService')
        ->not->toContain('payment');

    expect($reasons['PaymongoRepository'])
        ->toContain('`paymongo_transactions` table')
        ->toContain('record()')
        ->toContain('Paymongo payment gateway')
        ->toContain('Payment already holds PaymentService');

    expect($reasons['ShopOptionsRepository'])->not->toBe($reasons['PaymongoRepository']);
});
