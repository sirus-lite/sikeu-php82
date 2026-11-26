{{--
    Tautan ke halaman lain yang menghormati hak akses: link (wire:navigate) bila user boleh membuka route tujuan
    (App\Support\AksesHalaman — sama dengan menu & penjaga URL), selain itu teks biasa — supaya peringatan di laporan
    tidak menautkan ke halaman 403 untuk pembaca seperti Direksi.

    <x-tautan-halaman route="buku-besar.periode" :params="['tahun' => 2026]" class="font-medium underline">Tarik Data SIRUS</x-tautan-halaman>
--}}
@props(['route', 'params' => []])

@if (\App\Support\AksesHalaman::boleh(auth()->user(), $route))
    <a href="{{ route($route, $params) }}" wire:navigate {{ $attributes }}>{{ $slot }}</a>
@else
    <span class="font-medium">{{ $slot }}</span>
@endif
