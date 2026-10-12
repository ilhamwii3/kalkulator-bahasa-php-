# Kalkulator Modern (PHP)

Kalkulator sederhana berbahasa **PHP murni** dengan tema gelap bernuansa ungu-biru.
Tanpa JavaScript, tanpa database, tanpa framework, dan tanpa library eksternal.

## Fitur

- Penjumlahan (+), pengurangan (−), perkalian (×), pembagian (÷)
- Persentase (%) dan bilangan desimal
- Ubah positif/negatif (±)
- Hapus satu karakter (⌫) dan hapus semua (AC)
- Operasi berantai, contoh: `10 + 5 × 2`
- Pesan kesalahan untuk pembagian dengan nol, input kosong, dan input tidak valid
- Tampilan responsif untuk HP, tablet, dan komputer

## Aturan perhitungan

1. Perkalian (×) dan pembagian (÷) dihitung lebih dulu.
2. Penjumlahan (+) dan pengurangan (−) dihitung setelahnya.
3. Operasi yang setara dihitung dari kiri ke kanan.

Contoh: `10 + 5 × 2 = 20`, bukan 30.

Tombol `%` mengubah **angka yang sedang diketik** menjadi seperseratus
(misalnya `50` lalu `%` menjadi `0.5`).

## Cara menjalankan dengan XAMPP

1. Pasang XAMPP dari situs resmi apachefriends.org.
2. Buat folder `calculator-php` di dalam folder `htdocs` XAMPP
   (biasanya `C:\xampp\htdocs\`).
3. Salin `index.php`, `style.css`, `.gitignore`, dan `README.md` ke folder itu.
4. Buka XAMPP Control Panel, lalu klik **Start** pada **Apache**.
5. Buka browser dan kunjungi: `http://localhost/calculator-php/`

## Struktur project

```
calculator-php/
├── index.php    (tampilan dan logika PHP)
├── style.css    (desain dan responsivitas)
├── .gitignore
└── README.md
```

## Keamanan

- Input dikirim dengan metode POST.
- Tidak memakai `eval()`.
- Semua data divalidasi ulang di server pada setiap permintaan.
- Semua teks yang ditampilkan di-escape dengan `htmlspecialchars()`.
- Aplikasi tetap berjalan tanpa JavaScript dan tanpa session.

## Batasan

- Angka maksimal 15 digit.
- Hasil dibulatkan hingga 12 angka di belakang koma.
- Karena memakai POST, browser dapat menampilkan peringatan kirim ulang
  formulir jika halaman di-refresh.

## Catatan GitHub Pages

GitHub Pages hanya menampilkan file statis dan **tidak menjalankan PHP**.
Untuk mengakses kalkulator secara online, gunakan hosting yang mendukung PHP.
