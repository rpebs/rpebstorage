<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Label;
use App\Models\StorageAccount;
use App\Models\StorageProvider;
use App\Models\User;
use App\Models\VirtualFile;
use App\Models\VirtualFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StarAndLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private StorageAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $provider = StorageProvider::create([
            'id' => 'gdrive',
            'name' => 'Google Drive',
            'driver_class' => \App\Services\Storage\Drivers\GoogleDriveDriver::class,
        ]);

        $this->account = StorageAccount::create([
            'user_id' => $this->user->id,
            'storage_provider_id' => $provider->id,
            'alias' => 'Test GDrive',
            'credentials' => ['access_token' => 'fake'],
            'status' => AccountStatus::Active,
        ]);
    }

    public function test_can_toggle_star_on_file(): void
    {
        $file = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'document.pdf',
            'remote_ref' => 'ref-1',
            'size' => 1024,
            'is_starred' => false,
        ]);

        $res = $this->actingAs($this->user)
            ->postJson(route('files.star', $file));

        $res->assertOk();
        $res->assertJson(['is_starred' => true]);
        $this->assertTrue($file->fresh()->is_starred);

        // Toggle back
        $res2 = $this->actingAs($this->user)
            ->postJson(route('files.star', $file));

        $res2->assertOk();
        $res2->assertJson(['is_starred' => false]);
        $this->assertFalse($file->fresh()->is_starred);
    }

    public function test_can_toggle_star_on_folder(): void
    {
        $folder = VirtualFolder::create([
            'user_id' => $this->user->id,
            'name' => 'Proyek Rahasia',
            'is_starred' => false,
        ]);

        $res = $this->actingAs($this->user)
            ->postJson(route('files.folders.star', $folder));

        $res->assertOk();
        $res->assertJson(['is_starred' => true]);
        $this->assertTrue($folder->fresh()->is_starred);
    }

    public function test_filter_starred_returns_starred_items_across_folders(): void
    {
        $folder = VirtualFolder::create([
            'user_id' => $this->user->id,
            'name' => 'Subfolder',
            'is_starred' => true,
        ]);

        $starredFile = VirtualFile::create([
            'user_id' => $this->user->id,
            'virtual_folder_id' => $folder->id,
            'storage_account_id' => $this->account->id,
            'name' => 'starred_doc.pdf',
            'remote_ref' => 'ref-star',
            'size' => 1024,
            'is_starred' => true,
        ]);

        $unstarredFile = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'regular_doc.pdf',
            'remote_ref' => 'ref-reg',
            'size' => 1024,
            'is_starred' => false,
        ]);

        $res = $this->actingAs($this->user)
            ->get(route('files.index', ['filter' => 'starred']));

        $res->assertOk();
        $page = $res->original->getData()['page'];
        $files = collect($page['props']['files']);
        $folders = collect($page['props']['folders']);

        $this->assertTrue($files->contains('id', $starredFile->id));
        $this->assertFalse($files->contains('id', $unstarredFile->id));
        $this->assertTrue($folders->contains('id', $folder->id));
    }

    public function test_can_crud_labels(): void
    {
        // 1. Create
        $res = $this->actingAs($this->user)->postJson(route('labels.store'), [
            'name' => 'Pajak',
            'color' => 'amber',
        ]);
        $res->assertCreated();
        $labelId = $res->json('label.id');

        $this->assertDatabaseHas('labels', [
            'id' => $labelId,
            'name' => 'Pajak',
            'color' => 'amber',
            'user_id' => $this->user->id,
        ]);

        // 2. Update
        $label = Label::find($labelId);
        $resUpdate = $this->actingAs($this->user)->patchJson(route('labels.update', $label), [
            'name' => 'Pajak 2026',
            'color' => 'rose',
        ]);
        $resUpdate->assertOk();
        $this->assertEquals('Pajak 2026', $label->fresh()->name);
        $this->assertEquals('rose', $label->fresh()->color);

        // 3. Delete
        $resDelete = $this->actingAs($this->user)->deleteJson(route('labels.destroy', $label));
        $resDelete->assertOk();
        $this->assertDatabaseMissing('labels', ['id' => $labelId]);
    }

    public function test_can_seed_default_labels(): void
    {
        $res = $this->actingAs($this->user)->postJson(route('labels.defaults'));
        $res->assertOk();

        $this->assertDatabaseHas('labels', ['name' => 'Pribadi', 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('labels', ['name' => 'Pajak', 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('labels', ['name' => 'Kerja', 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('labels', ['name' => 'Backup', 'user_id' => $this->user->id]);
    }

    public function test_can_assign_labels_to_file_and_filter(): void
    {
        $label = Label::create([
            'user_id' => $this->user->id,
            'name' => 'Kerja',
            'color' => 'blue',
        ]);

        $file = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'kontrak.docx',
            'remote_ref' => 'ref-k',
            'size' => 2048,
        ]);

        $res = $this->actingAs($this->user)->patchJson(route('files.labels', $file), [
            'label_ids' => [$label->id],
        ]);
        $res->assertOk();

        $this->assertTrue($file->fresh()->labels->contains('id', $label->id));

        // Filter by label
        $filterRes = $this->actingAs($this->user)->get(route('files.index', ['label' => $label->id]));
        $filterRes->assertOk();
        $page = $filterRes->original->getData()['page'];
        $files = collect($page['props']['files']);
        $this->assertTrue($files->contains('id', $file->id));
    }

    public function test_bulk_star_and_bulk_labels(): void
    {
        $f1 = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'f1.txt',
            'remote_ref' => 'r1',
            'size' => 10,
        ]);
        $f2 = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'f2.txt',
            'remote_ref' => 'r2',
            'size' => 20,
        ]);
        $folder = VirtualFolder::create([
            'user_id' => $this->user->id,
            'name' => 'DocFolder',
        ]);

        $label = Label::create([
            'user_id' => $this->user->id,
            'name' => 'Pribadi',
            'color' => 'teal',
        ]);

        // Bulk Star
        $resStar = $this->actingAs($this->user)->postJson(route('files.bulk.star'), [
            'file_ids' => [$f1->id, $f2->id],
            'folder_ids' => [$folder->id],
            'is_starred' => true,
        ]);
        $resStar->assertOk();
        $this->assertTrue($f1->fresh()->is_starred);
        $this->assertTrue($f2->fresh()->is_starred);
        $this->assertTrue($folder->fresh()->is_starred);

        // Bulk Attach Label
        $resLabel = $this->actingAs($this->user)->postJson(route('files.bulk.labels'), [
            'file_ids' => [$f1->id, $f2->id],
            'folder_ids' => [$folder->id],
            'label_ids' => [$label->id],
            'action' => 'attach',
        ]);
        $resLabel->assertOk();
        $this->assertTrue($f1->fresh()->labels->contains('id', $label->id));
        $this->assertTrue($f2->fresh()->labels->contains('id', $label->id));
        $this->assertTrue($folder->fresh()->labels->contains('id', $label->id));
    }

    public function test_user_cannot_access_or_assign_other_users_labels_or_files(): void
    {
        $otherUser = User::factory()->create();
        $otherLabel = Label::create([
            'user_id' => $otherUser->id,
            'name' => 'Other Label',
            'color' => 'red',
        ]);

        $file = VirtualFile::create([
            'user_id' => $this->user->id,
            'storage_account_id' => $this->account->id,
            'name' => 'my_file.txt',
            'remote_ref' => 'my-ref',
            'size' => 10,
        ]);

        // Attempt to assign other user's label
        $res = $this->actingAs($this->user)->patchJson(route('files.labels', $file), [
            'label_ids' => [$otherLabel->id],
        ]);
        $res->assertStatus(422);

        // Attempt to toggle star on other user's file
        $otherFile = VirtualFile::create([
            'user_id' => $otherUser->id,
            'storage_account_id' => $this->account->id,
            'name' => 'other_file.txt',
            'remote_ref' => 'other-ref',
            'size' => 10,
        ]);

        $resStar = $this->actingAs($this->user)->postJson(route('files.star', $otherFile));
        $resStar->assertNotFound();
    }
}
