<?php

use App\Jobs\TranslateLanguages;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('quando uma traducao e despachada ela entra na fila do horizon', function () {
    Queue::fake();

    // Criamos um utilizador para simular o administrador
    $admin = User::factory()->create();

    $translation = Translation::create([
        'key' => 'welcome_message',
        'label' => 'Mensagem de Boas-Vindas',
    ]);

    // Disparar o Job
    TranslateLanguages::dispatch($translation->id, $admin->id);

    // ───► MUDANÇA AQUI: de assertDispatched para assertPushed ◄───
    Queue::assertPushed(TranslateLanguages::class, function ($job) use ($translation, $admin) {
        return $job->translationId === $translation->id && $job->adminId === $admin->id;
    });
});

test('o job de traducao executa a sua lógica de integracao', function () {
    $admin = User::factory()->create();

    $translation = Translation::create([
        'key' => 'button_save',
        'label' => 'Botão Guardar',
    ]);

    $job = new TranslateLanguages($translation->id, $admin->id);

    $job->handle();

    $this->assertDatabaseHas('translations', [
        'id' => $translation->id,
    ]);
});