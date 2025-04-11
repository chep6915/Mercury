<?php

use App\Http\Enums\Model\TelegramGroupConfigEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_group_configs', function (Blueprint $table) {
            $table->id(); // 主鍵，自增 ID
            $table->bigInteger('chat_id')->unique(); // Telegram 聊天室ID，唯一
            $table->unsignedSmallInteger('translate_model_id')->default(TelegramGroupConfigEnum::TRANSLATE_MODEL_CHATGPT);
            $table->string('translate_target_language'); // 目標翻譯語言，例如 'en', 'ja'
            $table->timestamps(); // 創建和更新時間戳
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_group_configs');
    }
};
