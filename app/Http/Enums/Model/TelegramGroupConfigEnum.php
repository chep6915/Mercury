<?php

namespace App\Http\Enums\Model;

class TelegramGroupConfigEnum
{
    const TRANSLATE_MODEL_GOOGLE_TRANSLATE = 1;
    const TRANSLATE_MODEL_CHATGPT = 2;

    const TRANSLATE_MODEL = [
        self::TRANSLATE_MODEL_GOOGLE_TRANSLATE => 'Google Translate',
        self::TRANSLATE_MODEL_CHATGPT => 'Chat GPT'
    ];

}
