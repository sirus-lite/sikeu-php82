<?php

namespace App\Http\Middleware;

use App\Support\AksesHalaman;
use App\Support\SinkronAksesOtomatis;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga halaman grup route 'menu': user hanya boleh membuka route yang centang "Lihat"-nya dimiliki salah satu
 * role-nya (keputusan App\Support\AksesHalaman). Route di grup ini yang tak didefinisikan di berkas program DITOLAK
 * (deny-by-default) — halaman baru wajib didaftarkan di app/Akses/Program/<Kelompok>/.
 * Didaftarkan juga sebagai persistent middleware Livewire (AppServiceProvider) — ikut memeriksa aksi Livewire,
 * jadi hak yang dicabut di Role Control langsung berlaku untuk tab yang masih terbuka.
 */
class CekAksesMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sesudah deploy yang mengubah definisi hak akses, permission baru dibuat otomatis (sekali, request pertama).
        SinkronAksesOtomatis::pastikan();

        $route = (string) $request->route()?->getName();
        if (!AksesHalaman::boleh($request->user(), $route)) {
            if ($route !== '' && !AksesHalaman::dikenal($route)) {
                Log::warning('Route grup menu tanpa definisi hak akses — daftarkan di app/Akses/Program', ['route' => $route]);
            }
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
