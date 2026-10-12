<?php
/**
 * Kalkulator Modern - PHP murni (tanpa JavaScript, tanpa database).
 *
 * Cara kerja singkat:
 * 1. Setiap tombol adalah tombol submit pada form (metode POST).
 * 2. Keadaan kalkulator disimpan di input tersembunyi "state" (JSON),
 *    lalu divalidasi ulang di server pada setiap permintaan.
 * 3. Perhitungan dilakukan oleh fungsi PHP biasa, tanpa eval().
 *
 * Aturan perhitungan: perkalian (×) dan pembagian (÷) dihitung lebih dulu
 * daripada penjumlahan (+) dan pengurangan (−). Contoh: 10 + 5 × 2 = 20.
 */

declare(strict_types=1);

// ---------------------------------------------------------------
// Konstanta
// ---------------------------------------------------------------
const MAX_DIGITS = 15;   // maksimal digit pada satu angka
const MAX_TOKENS = 40;   // maksimal elemen (angka + operator) dalam ekspresi

// Nama tombol operator => simbol yang dipakai di perhitungan
const OPERATORS = [
    'add' => '+',
    'sub' => '-',
    'mul' => '×',
    'div' => '÷',
];

// Tombol lain yang diizinkan selain angka 0-9
const OTHER_KEYS = ['clear', 'del', 'dot', 'neg', 'pct', 'equals'];

/** Kesalahan perhitungan (pembagian nol, hasil terlalu besar, dll). */
class CalcException extends Exception
{
}

// ---------------------------------------------------------------
// Fungsi bantu
// ---------------------------------------------------------------

/** Mengamankan teks sebelum ditampilkan di HTML. */
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/** Keadaan awal kalkulator. */
function defaultState(): array
{
    return [
        'tokens'  => [],    // contoh: ["10", "+", "5", "×"]
        'current' => '',    // angka yang sedang diketik
        'result'  => null,  // hasil setelah menekan "=" (null jika belum)
        'hist'    => '',    // teks ekspresi terakhir untuk ditampilkan
    ];
}

/** Mengubah angka desimal menjadi teks rapi (tanpa notasi ilmiah). */
function formatNumber(float $number): string
{
    if (!is_finite($number) || abs($number) >= 1e15) {
        throw new CalcException('Hasil terlalu besar untuk ditampilkan.');
    }

    $text = sprintf('%.12F', $number);
    $text = rtrim(rtrim($text, '0'), '.');

    if ($text === '' || $text === '-0') {
        $text = '0';
    }
    return $text;
}

/** Jumlah digit pada teks angka (tanda minus dan titik tidak dihitung). */
function countDigits(string $text): int
{
    return strlen(str_replace(['-', '.'], '', $text));
}

/** Simbol operator untuk ditampilkan (minus memakai tanda "−"). */
function displayOperator(string $symbol): string
{
    return $symbol === '-' ? '−' : $symbol;
}

/** Menyusun teks ekspresi, misalnya: 10 + (-5) × 2 */
function formatExpression(array $items): string
{
    $parts = [];
    foreach ($items as $index => $item) {
        if ($index % 2 === 0) {
            $isNegative = ($item !== '' && $item[0] === '-');
            $parts[] = $isNegative ? '(' . $item . ')' : $item;
        } else {
            $parts[] = displayOperator($item);
        }
    }
    return implode(' ', $parts);
}

/** Memeriksa apakah teks adalah angka valid (token ekspresi / hasil). */
function isValidNumber(string $text): bool
{
    return preg_match('/^-?\d{1,15}(\.\d{1,15})?$/', $text) === 1;
}

/** Memeriksa angka yang sedang diketik (boleh berakhiran titik, mis. "5."). */
function isValidCurrent(string $text): bool
{
    return $text === '' || preg_match('/^-?\d{1,15}(\.\d{0,15})?$/', $text) === 1;
}

// ---------------------------------------------------------------
// Membaca dan memvalidasi keadaan dari form (data tidak boleh dipercaya)
// ---------------------------------------------------------------
function loadState($raw, string &$problem): array
{
    if (!is_string($raw) || $raw === '') {
        return defaultState();
    }

    $invalid = 'Data tidak valid, kalkulator direset.';

    if (strlen($raw) > 3000) {
        $problem = $invalid;
        return defaultState();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $problem = $invalid;
        return defaultState();
    }

    $tokens  = $data['tokens']  ?? null;
    $current = $data['current'] ?? null;
    $result  = $data['result']  ?? null;
    $hist    = $data['hist']    ?? '';

    // Validasi tokens: pasangan [angka, operator], jadi jumlahnya genap.
    if (!is_array($tokens) || count($tokens) > MAX_TOKENS || count($tokens) % 2 !== 0) {
        $problem = $invalid;
        return defaultState();
    }
    $tokens = array_values($tokens);
    foreach ($tokens as $index => $token) {
        if (!is_string($token)) {
            $problem = $invalid;
            return defaultState();
        }
        if ($index % 2 === 0) {
            if (!isValidNumber($token)) {
                $problem = $invalid;
                return defaultState();
            }
        } elseif (!in_array($token, array_values(OPERATORS), true)) {
            $problem = $invalid;
            return defaultState();
        }
    }

    if (!is_string($current) || !isValidCurrent($current)) {
        $problem = $invalid;
        return defaultState();
    }

    if ($result !== null && (!is_string($result) || !isValidNumber($result))) {
        $problem = $invalid;
        return defaultState();
    }

    if (!is_string($hist) || strlen($hist) > 600
        || preg_match('/^[0-9.\-+×÷−() ]*$/u', $hist) !== 1) {
        $problem = $invalid;
        return defaultState();
    }

    return [
        'tokens'  => $tokens,
        'current' => $current,
        'result'  => $result,
        'hist'    => $hist,
    ];
}

// ---------------------------------------------------------------
// Perhitungan matematika (tanpa eval)
// ---------------------------------------------------------------

/**
 * Menghitung daftar [angka, operator, angka, ...].
 * Langkah 1: kerjakan × dan ÷ dari kiri ke kanan.
 * Langkah 2: kerjakan + dan − dari kiri ke kanan.
 */
function calculate(array $items): float
{
    $stack = [(float) $items[0]];

    for ($i = 1; $i < count($items); $i += 2) {
        $operator = $items[$i];
        $number   = (float) $items[$i + 1];
        $last     = count($stack) - 1;

        if ($operator === '×') {
            $stack[$last] = multiply((float) $stack[$last], $number);
        } elseif ($operator === '÷') {
            $stack[$last] = divide((float) $stack[$last], $number);
        } else {
            $stack[] = $operator;
            $stack[] = $number;
        }
    }

    $total = (float) $stack[0];
    for ($i = 1; $i < count($stack); $i += 2) {
        $total = ($stack[$i] === '+')
            ? add($total, (float) $stack[$i + 1])
            : subtract($total, (float) $stack[$i + 1]);
    }

    return $total;
}

function add(float $a, float $b): float
{
    return $a + $b;
}

function subtract(float $a, float $b): float
{
    return $a - $b;
}

function multiply(float $a, float $b): float
{
    return $a * $b;
}

function divide(float $a, float $b): float
{
    if ($b === 0.0) {
        throw new CalcException('Tidak bisa membagi dengan nol.');
    }
    return $a / $b;
}

// ---------------------------------------------------------------
// Logika tombol
// ---------------------------------------------------------------

/** Memindahkan hasil terakhir menjadi angka yang sedang diketik. */
function resultToCurrent(array $s): array
{
    return [
        'tokens'  => [],
        'current' => (string) $s['result'],
        'result'  => null,
        'hist'    => '',
    ];
}

/**
 * Memproses satu tombol dan mengembalikan keadaan baru.
 * Pesan kesalahan (jika ada) dikirim lewat parameter $error.
 */
function pressKey(array $s, string $key, string &$error): array
{
    $finished = ($s['result'] !== null);

    if ($key === 'clear') {
        return defaultState();
    }

    // Tombol angka 0-9
    if (ctype_digit($key)) {
        if ($finished) {
            $s = defaultState();
        }
        if ($s['current'] === '0') {
            $s['current'] = $key;
        } elseif ($s['current'] === '-0') {
            $s['current'] = '-' . $key;
        } elseif (countDigits($s['current']) >= MAX_DIGITS) {
            $error = 'Maksimal ' . MAX_DIGITS . ' digit.';
        } else {
            $s['current'] .= $key;
        }
        return $s;
    }

    switch ($key) {
        case 'dot':
            if ($finished) {
                $s = defaultState();
            }
            if ($s['current'] === '') {
                $s['current'] = '0.';
            } elseif (strpos($s['current'], '.') === false) {
                if (countDigits($s['current']) >= MAX_DIGITS) {
                    $error = 'Maksimal ' . MAX_DIGITS . ' digit.';
                } else {
                    $s['current'] .= '.';
                }
            } else {
                $error = 'Angka sudah memiliki titik desimal.';
            }
            return $s;

        case 'neg': // ubah positif/negatif
            if ($finished) {
                $s = resultToCurrent($s);
            }
            if ($s['current'] === '') {
                $s['current'] = '-0';
            } elseif ($s['current'][0] === '-') {
                $s['current'] = substr($s['current'], 1);
            } else {
                $s['current'] = '-' . $s['current'];
            }
            return $s;

        case 'pct': // persentase: angka yang diketik dibagi 100
            if ($finished) {
                $s = resultToCurrent($s);
            }
            if ($s['current'] === '') {
                $error = 'Masukkan angka terlebih dahulu.';
                return $s;
            }
            $s['current'] = formatNumber(((float) $s['current']) / 100);
            return $s;

        case 'del': // hapus satu karakter
            if ($finished) {
                return defaultState();
            }
            if ($s['current'] !== '') {
                $s['current'] = substr($s['current'], 0, -1);
                if ($s['current'] === '-') {
                    $s['current'] = '';
                }
            } elseif (count($s['tokens']) > 0) {
                array_pop($s['tokens']);                       // buang operator
                $s['current'] = (string) array_pop($s['tokens']); // angka bisa diedit lagi
            } else {
                $error = 'Tidak ada yang bisa dihapus.';
            }
            return $s;

        case 'add':
        case 'sub':
        case 'mul':
        case 'div':
            $symbol = OPERATORS[$key];
            if ($finished) {
                $s = resultToCurrent($s);
            }
            if ($s['current'] !== '') {
                if (count($s['tokens']) >= MAX_TOKENS) {
                    $error = 'Ekspresi terlalu panjang.';
                    return $s;
                }
                $s['tokens'][] = formatNumber((float) $s['current']);
                $s['tokens'][] = $symbol;
                $s['current']  = '';
            } elseif (count($s['tokens']) > 0) {
                // Operator ditekan dua kali: ganti operator terakhir.
                $s['tokens'][count($s['tokens']) - 1] = $symbol;
            } else {
                $error = 'Masukkan angka terlebih dahulu.';
            }
            return $s;

        case 'equals':
            if ($finished) {
                return $s; // sudah ada hasil, tidak ada yang dihitung lagi
            }
            $items = $s['tokens'];
            if ($s['current'] !== '') {
                $items[] = formatNumber((float) $s['current']);
            }
            if (count($items) === 0) {
                $error = 'Input kosong. Masukkan angka terlebih dahulu.';
                return $s;
            }
            if (count($items) % 2 === 0) {
                $error = 'Ekspresi belum lengkap. Masukkan angka setelah operator.';
                return $s;
            }
            try {
                $resultText = formatNumber(calculate($items));
            } catch (CalcException $ex) {
                $error = $ex->getMessage();
                return defaultState();
            }
            return [
                'tokens'  => [],
                'current' => '',
                'result'  => $resultText,
                'hist'    => formatExpression($items),
            ];
    }

    return $s;
}

/** Menentukan teks baris kecil (ekspresi) dan baris besar (angka utama). */
function buildDisplay(array $s): array
{
    if ($s['result'] !== null) {
        return [$s['hist'] . ' =', $s['result']];
    }

    $items = $s['tokens'];
    if ($s['current'] !== '') {
        $items[] = $s['current'];
    }
    $expression = formatExpression($items);

    if ($s['current'] !== '') {
        $main = $s['current'];
    } elseif (count($s['tokens']) > 0) {
        $main = $s['tokens'][count($s['tokens']) - 2]; // angka terakhir sebelum operator
    } else {
        $main = '0';
    }

    return [$expression, $main];
}

// ---------------------------------------------------------------
// Memproses permintaan
// ---------------------------------------------------------------
$error = '';
$state = defaultState();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $stateProblem = '';
    $state = loadState($_POST['state'] ?? '', $stateProblem);

    $key      = $_POST['key'] ?? '';
    $validKey = is_string($key)
        && (preg_match('/^[0-9]$/', $key) === 1
            || array_key_exists($key, OPERATORS)
            || in_array($key, OTHER_KEYS, true));

    if ($stateProblem !== '') {
        $error = $stateProblem;
    } elseif (!$validKey) {
        $error = 'Tombol tidak valid.';
    } else {
        $state = pressKey($state, $key, $error);
    }
}

[$expressionText, $mainText] = buildDisplay($state);

// Susunan tombol: [label, nilai, kelas CSS, label aksesibilitas]
$buttons = [
    ['AC', 'clear',  'fn',     'Hapus semua'],
    ['±',  'neg',    'fn',     'Ubah positif atau negatif'],
    ['%',  'pct',    'fn',     'Persen'],
    ['÷',  'div',    'op',     'Bagi'],
    ['7',  '7',      'num',    '7'],
    ['8',  '8',      'num',    '8'],
    ['9',  '9',      'num',    '9'],
    ['×',  'mul',    'op',     'Kali'],
    ['4',  '4',      'num',    '4'],
    ['5',  '5',      'num',    '5'],
    ['6',  '6',      'num',    '6'],
    ['−',  'sub',    'op',     'Kurang'],
    ['1',  '1',      'num',    '1'],
    ['2',  '2',      'num',    '2'],
    ['3',  '3',      'num',    '3'],
    ['+',  'add',    'op',     'Tambah'],
    ['⌫',  'del',    'fn',     'Hapus satu karakter'],
    ['0',  '0',      'num',    '0'],
    ['.',  'dot',    'num',    'Desimal'],
    ['=',  'equals', 'equals', 'Sama dengan'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kalkulator Modern PHP</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="calculator">
        <form method="post" action="index.php" autocomplete="off">
            <input type="hidden" name="state" value="<?= e(json_encode($state)) ?>">

            <div class="display" aria-live="polite">
                <div class="expression"><?= e($expressionText) ?></div>
                <div class="value"><?= e($mainText) ?></div>
                <?php if ($error !== ''): ?>
                    <p class="message" role="alert"><?= e($error) ?></p>
                <?php endif; ?>
            </div>

            <div class="keys">
                <?php foreach ($buttons as [$label, $value, $class, $aria]): ?>
                    <button type="submit" name="key" value="<?= e($value) ?>"
                            class="key <?= e($class) ?>" aria-label="<?= e($aria) ?>">
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    </main>
</body>
</html>
