<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('larena_layout_artifacts')) {
            Schema::create('larena_layout_artifacts', static function (Blueprint $table): void {
                $table->string('scope_ref', 128);
                $table->string('artifact_id', 120);
                $table->string('kind', 16);
                $table->unsignedBigInteger('current_revision');
                $table->unsignedBigInteger('published_revision')->nullable();
                $table->longText('current_json');
                $table->char('semantic_hash', 64);
                $table->string('updated_by', 160);
                $table->primary(['scope_ref', 'artifact_id'], 'layout_artifacts_primary');
            });
        }

        if (! Schema::hasTable('larena_layout_artifact_versions')) {
            Schema::create('larena_layout_artifact_versions', static function (Blueprint $table): void {
                $table->string('scope_ref', 128);
                $table->string('artifact_id', 120);
                $table->unsignedBigInteger('revision');
                $table->string('kind', 16);
                $table->longText('document_json');
                $table->char('semantic_hash', 64);
                $table->string('changed_by', 160);
                $table->primary(['scope_ref', 'artifact_id', 'revision'], 'layout_artifact_versions_primary');
            });
        }

        if (! Schema::hasTable('larena_layout_artifact_links')) {
            Schema::create('larena_layout_artifact_links', static function (Blueprint $table): void {
                $table->string('scope_ref', 128);
                $table->string('parent_artifact_id', 120);
                $table->unsignedBigInteger('parent_revision');
                $table->string('instance_id', 120);
                $table->string('child_artifact_id', 120);
                $table->unsignedBigInteger('child_revision');
                $table->string('slot_name', 80);
                $table->integer('sort_order');
                $table->boolean('enabled');
                $table->primary(
                    ['scope_ref', 'parent_artifact_id', 'parent_revision', 'instance_id'],
                    'layout_artifact_links_primary',
                );
                $table->index(
                    ['scope_ref', 'child_artifact_id'],
                    'layout_artifact_links_child',
                );
            });
        }

        if (! Schema::hasTable('larena_layout_compiled_snapshots')) {
            Schema::create('larena_layout_compiled_snapshots', static function (Blueprint $table): void {
                $table->string('scope_ref', 128);
                $table->string('page_id', 120);
                $table->string('snapshot_digest', 71);
                $table->string('source_revision', 200);
                $table->longText('snapshot_json');
                $table->string('created_by', 160);
                $table->primary(
                    ['scope_ref', 'page_id', 'snapshot_digest'],
                    'layout_compiled_snapshots_primary',
                );
            });
        }

        if (! Schema::hasTable('larena_layout_active_snapshots')) {
            Schema::create('larena_layout_active_snapshots', static function (Blueprint $table): void {
                $table->string('scope_ref', 128);
                $table->string('page_id', 120);
                $table->unsignedBigInteger('activation_revision');
                $table->string('snapshot_digest', 71);
                $table->string('activated_by', 160);
                $table->primary(['scope_ref', 'page_id'], 'layout_active_snapshots_primary');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_layout_active_snapshots');
        Schema::dropIfExists('larena_layout_compiled_snapshots');
        Schema::dropIfExists('larena_layout_artifact_links');
        Schema::dropIfExists('larena_layout_artifact_versions');
        Schema::dropIfExists('larena_layout_artifacts');
    }
};

