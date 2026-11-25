<?php

use App\Models\MedicineRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('takes a medicine request with prescription files and keeps the files private', function () {
    Storage::fake('local');
    $res = $this->post(V1.'/medicine-requests', [
        'name' => 'Rahim', 'phone' => '+880 1712-345678', 'address' => 'Mirpur 10', 'medicines' => 'Napa 500 x 20',
        'files' => [UploadedFile::fake()->image('rx.jpg'), UploadedFile::fake()->create('rx.pdf', 300, 'application/pdf')],
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.files', 2);

    $r = MedicineRequest::firstOrFail();
    expect($res->json('data.number'))->toBe($r->number)->toStartWith('MR-')->and($r->phone)->toBe('01712345678');
    Storage::disk('local')->assertExists($r->files[0]['path']);
    expect($r->files[0]['path'])->toStartWith('medicine-requests/');

    // Staff see it, open a file by a signed link, and mark it handled.
    $manager = posUser('Manager');
    $this->actingAs($manager)->getJson('/api/pos/v1/orders/requests')->assertOk()->assertJsonPath('requests.0.number', $r->number)
        ->assertJsonPath('requests.0.files.1.name', 'rx.pdf')->assertJsonPath('counts.new', 1);
    $url = $this->actingAs($manager)->getJson("/api/pos/v1/orders/requests/{$r->id}/files/0")->assertOk()->json('url');
    $this->get($url)->assertOk();
    $this->get("/api/pos/v1/medicine-requests/{$r->id}/files/0")->assertForbidden(); // unsigned
    $this->actingAs($manager)->putJson("/api/pos/v1/orders/requests/{$r->id}", ['status' => 'contacted', 'staff_note' => 'Called, will order'])
        ->assertOk()->assertJsonPath('status', 'contacted')->assertJsonPath('handledBy', $manager->name);
    $this->actingAs(posUser('Inventory'))->getJson('/api/pos/v1/orders/requests')->assertForbidden();
});

it('needs medicine names or a file, a valid phone, and safe file types', function () {
    Storage::fake('local');
    $send = fn (array $d) => $this->post(V1.'/medicine-requests', $d + ['name' => 'Rahim', 'phone' => '01712345678'], ['Accept' => 'application/json']);
    $send([])->assertJsonValidationErrors('medicines');
    $send(['medicines' => 'Napa', 'phone' => '123'])->assertJsonValidationErrors('phone');
    $send(['files' => [UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')]])->assertJsonValidationErrors('files.0');
    $send(['files' => array_fill(0, 6, UploadedFile::fake()->image('a.jpg'))])->assertJsonValidationErrors('files');
    $send(['files' => [UploadedFile::fake()->image('only-photo.jpg')]])->assertCreated();
});
