<?php

use App\Http\Controllers\AgentDashboardController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AdminRecordingController;
use App\Http\Controllers\AdminSupervisionController;
use App\Http\Controllers\AgentPresenceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\PhoneCallController;
use App\Http\Controllers\PbxRecordingController;
use App\Http\Controllers\SipSessionController;
use App\Http\Controllers\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/entrar');

Route::get('/entrar', [SipSessionController::class, 'create'])->name('phone.login');
Route::post('/entrar', [SipSessionController::class, 'store'])->name('phone.login.store');
Route::post('/sair', [SipSessionController::class, 'destroy'])->name('phone.logout');
Route::middleware('sip.session')->group(function () {
    Route::get('/telefone', AgentDashboardController::class)->name('phone.dashboard');
    Route::get('/telefone/historico', [AgentDashboardController::class, 'history'])->name('phone.history');
    Route::get('/telefone/agenda', [AppointmentController::class, 'index'])->name('phone.appointments.index');
    Route::post('/telefone/agenda', [AppointmentController::class, 'store'])->name('phone.appointments.store');
    Route::patch('/telefone/agenda/{appointment}', [AppointmentController::class, 'update'])->name('phone.appointments.update');
    Route::delete('/telefone/agenda/{appointment}', [AppointmentController::class, 'destroy'])->name('phone.appointments.destroy');
    Route::post('/telefone/chamadas', [PhoneCallController::class, 'store'])->name('phone.calls.store');
    Route::patch('/telefone/chamadas/{phoneCall}', [PhoneCallController::class, 'update'])->name('phone.calls.update');
    Route::post('/telefone/chamadas/{phoneCall}/gravacao', [PhoneCallController::class, 'uploadRecording'])->name('phone.calls.recording.store');
    Route::get('/telefone/chamadas/{phoneCall}/gravacao', [PhoneCallController::class, 'recording'])->name('phone.calls.recording');
    Route::get('/telefone/historico/{callRecord}/gravacao', PbxRecordingController::class)->name('phone.call-records.recording');
    Route::post('/telefone/presenca', [AgentPresenceController::class, 'heartbeat'])->name('phone.presence.heartbeat');
    Route::post('/telefone/pausa', [AgentPresenceController::class, 'pause'])->name('phone.presence.pause');
    Route::delete('/telefone/pausa', [AgentPresenceController::class, 'resume'])->name('phone.presence.resume');
});

Route::prefix('administracao')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/entrar', fn () => redirect()->route('phone.login'))->name('login');
        Route::post('/entrar', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/primeiro-acesso', [PasswordChangeController::class, 'edit'])->name('password.change.edit');
        Route::put('/primeiro-acesso', [PasswordChangeController::class, 'update'])->name('password.change.update');
        Route::post('/sair', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });

    Route::middleware(['auth', 'password.changed', 'operation.admin'])->group(function () {
        Route::get('/gravacoes', [AdminRecordingController::class, 'index'])->name('admin.recordings.index');
        Route::get('/gravacoes/{recording}/ouvir', [AdminRecordingController::class, 'play'])->name('admin.recordings.play');
        Route::get('/acompanhamento', [AdminSupervisionController::class, 'index'])->name('admin.supervision.index');
        Route::get('/acompanhamento/agentes', [AdminSupervisionController::class, 'agents'])->name('admin.supervision.agents');
        Route::get('/acompanhamento/ramais/{extension}/dia', [AdminSupervisionController::class, 'daily'])->name('admin.supervision.daily');
        Route::post('/acompanhamento/ramais/{extension}', [AdminSupervisionController::class, 'supervise'])->name('admin.supervision.start');
        Route::patch('/acompanhamento/sessoes/{supervisionSession}', [AdminSupervisionController::class, 'finish'])->name('admin.supervision.finish');
    });

    Route::middleware(['auth', 'password.changed', 'superadmin'])->group(function () {
        Route::get('/', [SuperAdminController::class, 'index'])->name('admin.index');
        Route::post('/pausas', [AdminSupervisionController::class, 'storePause'])->name('admin.pauses.store');
        Route::put('/pausas/{pauseReason}', [AdminSupervisionController::class, 'updatePause'])->name('admin.pauses.update');
        Route::delete('/pausas/{pauseReason}', [AdminSupervisionController::class, 'destroyPause'])->name('admin.pauses.destroy');
        Route::post('/empresas', [SuperAdminController::class, 'storeTenant'])->name('admin.tenants.store');
        Route::put('/empresas/{tenant}', [SuperAdminController::class, 'updateTenant'])->name('admin.tenants.update');
        Route::delete('/empresas/{tenant}', [SuperAdminController::class, 'destroyTenant'])->name('admin.tenants.destroy');
        Route::post('/empresas/{tenant}/rotas', [SuperAdminController::class, 'attachTrunk'])->name('admin.tenants.trunks.store');
        Route::delete('/empresas/{tenant}/rotas/{trunk}', [SuperAdminController::class, 'detachTrunk'])->name('admin.tenants.trunks.destroy');
        Route::post('/rotas', [SuperAdminController::class, 'storeTrunk'])->name('admin.trunks.store');
        Route::put('/rotas/{trunk}', [SuperAdminController::class, 'updateTrunk'])->name('admin.trunks.update');
        Route::delete('/rotas/{trunk}', [SuperAdminController::class, 'destroyTrunk'])->name('admin.trunks.destroy');
        Route::post('/rotas/{trunk}/testar', [SuperAdminController::class, 'testTrunk'])->name('admin.trunks.test');
        Route::post('/usuarios', [SuperAdminController::class, 'storeUser'])->name('admin.users.store');
        Route::put('/ramais/{extension}', [SuperAdminController::class, 'updateExtension'])->name('admin.extensions.update');
        Route::delete('/ramais/{extension}', [SuperAdminController::class, 'destroyExtension'])->name('admin.extensions.destroy');
    });
});
