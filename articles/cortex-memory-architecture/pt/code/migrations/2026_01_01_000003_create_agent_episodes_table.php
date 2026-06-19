<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @package Database\Migrations\V2\Agent
 * @author  Eduardo Costa Nkuansambu
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_episodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('session_id')->index();
            $table->string('status', 32)->default('pending_compression')->index();
            // Raw data antes da compressão (apagado após CompressEpisodeJob)
            $table->longText('raw_data')->nullable();
            // Summary comprimido após processamento assíncrono
            $table->text('summary')->nullable();
            // Embedding gerado pelo serviço Python (JSON array de floats)
            $table->longText('embedding')->nullable();
            $table->string('version', 8)->default('v2')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_episodes');
    }
};
