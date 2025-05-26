<?php

namespace App\Console\Commands;

use App\Http\Enums\Model\TelegramGroupConfigEnum;
use App\Models\TelegramGroupConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class FetchTelegramMessages extends Command
{
    protected $signature = 'telegram:fetch';
    protected $description = 'Fetch messages from Telegram bot';

    public array $languageNames = [
        'ja' => '日文',
        'ko' => '韩文',
        'en' => '英文',
        'ar' => '阿拉伯语',
        'tr' => '土耳其语',
    ];

    public function handle()
    {
        $token = config('telegram.translate.bot_token');
        $url = "https://api.telegram.org/bot{$token}/getUpdates";

        $lastUpdateId = $this->getLastUpdateId($token) ?? 0;

        while (true) {
            $response = $this->getContentTelegram($url, $lastUpdateId);
            $data = json_decode($response, true);
            foreach (($data['result'] ?? []) as $message) {
                $lastUpdateId = $this->setLastUpdateId($token, $message['update_id']);

                // 处理回调
                if (isset($message['callback_query'])) {
                    $this->processCallBack($message);
                    continue;
                }

                $chatId = $message['message']['chat']['id'] ?? null;
                $text = $message['message']['text'] ?? '';
                $messageId = $message['message']['message_id'] ?? null;

                // 確認 chatId 是否為空
                if (!$chatId) {
                    Log::warning("收到的消息中沒有 chatId");
                    continue;
                }

                if (!$this->checkWhiteChatId($chatId)) {
                    Log::warning("chatId: {$chatId} 并非在白名单内");
                    continue;
                }

                if ($text === '/model') {
                    $this->sendModelOptions($chatId);
                } else if ($text === '/lang') {
                    $this->sendLanguageOptions($chatId);
                } else if ($text === '/chatId') {
                    $this->sendChatId($chatId);
                } else {
                    $telegramGroupConfig = $this->getTelegramGroupConfig($chatId);

                    if (empty($telegramGroupConfig)) {
                        $this->sendLanguageOptions($chatId);
                        continue;
                    }

                    $channelTargetLang = $telegramGroupConfig['translate_target_language'];
                    $channelTranslateModel = $telegramGroupConfig['translate_model_id'];

                    if ($channelTranslateModel == TelegramGroupConfigEnum::TRANSLATE_MODEL_GOOGLE_TRANSLATE) {
                        $transText = $this->googleTrans($channelTargetLang, $text);
                    } else if ($channelTranslateModel == TelegramGroupConfigEnum::TRANSLATE_MODEL_CHATGPT) {
                        $transText = $this->chatGPTTrans($channelTargetLang, $text);
                    } else {
                        $transText = '';
                    }

                    $this->sendTelegram([
                        'chat_id' => $chatId,
                        'text' => $transText,
                        'reply_to_message_id' => $messageId,
                    ]);
                }
            }
            sleep(1);
        }
    }

    private function processCallBack(array $message)
    {
        $chatId = $message['callback_query']['message']['chat']['id'] ?? null;
        $callbackData = $message['callback_query']['data'] ?? null;

        // 確認 chatId 是否為空
        if (!$chatId) {
            Log::warning("收到的callback消息中沒有 chatId " . json_encode($message, JSON_PRETTY_PRINT));
            return;
        }

        if (!$this->checkWhiteChatId($chatId)) {
            Log::warning("chatId: {$chatId} 并非在白名单内");
            return;
        }

        if (!$callbackData) {
            Log::warning("收到的callback消息中data為空 " . json_encode($message, JSON_PRETTY_PRINT));
            return;
        }

        if (str_starts_with($callbackData, 'lang_')) {
            $langCode = substr($callbackData, 5);
            $this->setTelegramGroupConfig($chatId, ['translate_target_language' => $langCode]);
        } else if (str_starts_with($callbackData, 'model_')) {
            $modelId = substr($callbackData, 6);
            $this->setTelegramGroupConfig($chatId, ['translate_model_id' => $modelId]);
        }

    }

    private function sendLanguageOptions(int $chatId)
    {
        $languages = [
            'ja' => '日文',
            'ko' => '韩文',
            'en' => '英文',
            'ar' => '阿拉伯语',
            'tr' => '土耳其语',
        ];

        $keyboard = [];
        foreach ($languages as $code => $name) {
            $keyboard[] = [['text' => $name, 'callback_data' => 'lang_' . $code]];
        }

        $this->sendTelegram([
            'chat_id' => $chatId,
            'text' => '请选择翻译目标语言：',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function sendChatId(int $chatId): void
    {
        $this->sendTelegram([
            'chat_id' => $chatId,
            'text' => 'ChatId：' . $chatId,
        ]);
    }

    private function sendModelOptions(int $chatId)
    {
        $model = [
            1 => 'GoogleTranslate[Google翻譯]',
            2 => 'ChatGPT[ChatGPT]',
        ];

        $keyboard = [];
        foreach ($model as $id => $modelName) {
            $keyboard[] = [['text' => $modelName, 'callback_data' => 'model_' . $id]];
        }

        $this->sendTelegram([
            'chat_id' => $chatId,
            'text' => '请选择翻译翻譯模組：',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    private function getLanguageName($langCode)
    {
        return $this->languageNames[$langCode] ?? '未知语言';
    }

    private function googleTrans(string $channelTargetLang, $text)
    {
        $originalLanguage = $this->googleDetectLanguage($text);

        if ($originalLanguage == $channelTargetLang) {
            // 翻译目标成简体中文
            $channelTargetLang = 'zh-CN';
        }

        return $this->translateFromGoogle($text, $channelTargetLang);
    }

    private function chatGPTTrans(string $channelTargetLang, $text)
    {
        // 驗證輸入
        if (empty($text) || empty($channelTargetLang)) {
            Log::warning('chatGPTTrans: Empty text or target language', [
                'text' => $text,
                'target_lang' => $channelTargetLang,
            ]);
            return $text;
        }

        // 獲取 OpenAI API 密鑰
        $apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (!$apiKey) {
            Log::error('chatGPTTrans: OpenAI API key not configured');
            return $text;
        }

        // 語言映射表
        $languageMap = [
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'en' => 'English',
            'ar' => 'Arabic',
            'tr' => 'Turkish',
        ];

        // 檢查 channelTargetLang 是否有效
        if (!isset($languageMap[$channelTargetLang])) {
            Log::warning('chatGPTTrans: Unsupported target language', [
                'target_lang' => $channelTargetLang,
            ]);
            return $text;
        }

        $targetLangName = $languageMap[$channelTargetLang]; // 例如 'Japanese'

        // 檢測輸入文本的語言
        $isSimplifiedChinese = $this->isSimplifiedChinese($text); // 新增：檢測是否為簡體中文
        $isTargetLangText = $this->isTextInLanguage($text, $channelTargetLang);

        // 決定翻譯方向
        $sourceLang = 'Simplified Chinese'; // 默認假設原文是簡體中文
        $destLang = $targetLangName; // 目標語言

        if ($isSimplifiedChinese) {
            // 如果輸入是簡體中文，則翻譯成目標語言
            $sourceLang = 'Simplified Chinese';
            $destLang = $targetLangName;
        } elseif ($isTargetLangText) {
            // 如果輸入是目標語言，則翻譯成簡體中文
            $sourceLang = $targetLangName;
            $destLang = 'Simplified Chinese';
        } else {
            // 如果無法確定語言，假設是簡體中文，翻譯成目標語言
            Log::warning('chatGPTTrans: Unable to detect language, assuming Simplified Chinese', [
                'text' => $text,
                'target_lang' => $channelTargetLang,
            ]);
        }

        // 檢查緩存
        $cacheKey = "translation:{$sourceLang}:{$destLang}:" . md5($text);
        $cachedTranslation = Redis::get($cacheKey);
        if ($cachedTranslation) {
            Log::info('chatGPTTrans: Translation retrieved from cache', [
                'original' => $text,
                'source_lang' => $sourceLang,
                'target_lang' => $destLang,
                'translated' => $cachedTranslation,
            ]);
            return $cachedTranslation;
        }

        // 構造翻譯提示（prompt）
        $prompt = "Translate the following text from {$sourceLang} to {$destLang}: \"{$text}\"";

        // 重試邏輯
        $maxRetries = 3;
        $retryDelay = 2000; // 初始延遲 2 秒

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // 創建 Guzzle Client 實例
                $client = new Client([
                    'base_uri' => 'https://api.openai.com',
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ],
                ]);

                // 發送 POST 請求到 OpenAI API
                $response = $client->post('/v1/chat/completions', [
                    'json' => [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => [
                            ['role' => 'system', 'content' => 'You are a professional translator.'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'max_tokens' => 1000,
                        'temperature' => 0.3,
                    ],
                ]);

                // 解析響應
                $data = json_decode($response->getBody()->getContents(), true);
                $translatedText = $data['choices'][0]['message']['content'] ?? null;

                if ($translatedText) {
                    // 存入緩存，設置 24 小時過期
                    Redis::set($cacheKey, $translatedText, 'EX', 86400);
                    Log::info('chatGPTTrans: Translation successful', [
                        'original' => $text,
                        'source_lang' => $sourceLang,
                        'target_lang' => $destLang,
                        'translated' => $translatedText,
                    ]);
                    return $translatedText;
                } else {
                    Log::warning('chatGPTTrans: No translation found in response', [
                        'response' => $data,
                    ]);
                    return $text;
                }
            } catch (RequestException $e) {
                $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
                $errorBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();

                // 檢查是否為 429 錯誤（配額或速率限制）
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    Log::warning('chatGPTTrans: Rate limit exceeded, retrying...', [
                        'attempt' => $attempt,
                        'delay' => $retryDelay,
                    ]);
                    usleep($retryDelay * 1000); // 延遲（微秒）
                    $retryDelay *= 2; // 指數退避
                    continue;
                }

                Log::error('chatGPTTrans: OpenAI API request failed', [
                    'status' => $statusCode,
                    'body' => $errorBody,
                ]);
                return $text;
            } catch (\Exception $e) {
                Log::error('chatGPTTrans: Translation failed', [
                    'text' => $text,
                    'target_lang' => $channelTargetLang,
                    'error' => $e->getMessage(),
                ]);
                return $text;
            }
        }

        // 如果重試失敗，返回原文本
        Log::error('chatGPTTrans: Max retries reached, translation failed');
        return $text;
    }

    /**
     * 檢測文本是否為簡體中文（基於字符範圍）
     * @param string $text 輸入文本
     * @return bool
     */
    private function isSimplifiedChinese(string $text): bool
    {
        // 簡體中文常用漢字範圍（4E00-9FAF）
        // 排除日文平假名和片假名，僅檢查是否包含漢字且不包含日文特有字符
        $hasChinese = preg_match('/[\x{4E00}-\x{9FAF}]/u', $text) === 1;
        $hasJapaneseHiragana = preg_match('/[\x{3040}-\x{309F}]/u', $text) === 1;
        $hasJapaneseKatakana = preg_match('/[\x{30A0}-\x{30FF}]/u', $text) === 1;

        // 如果包含漢字且不包含日文平假名或片假名，則認為是簡體中文
        return $hasChinese && !$hasJapaneseHiragana && !$hasJapaneseKatakana;
    }

    /**
     * 檢測文本是否為指定語言（基於字符範圍）
     * @param string $text 輸入文本
     * @param string $language 目標語言
     * @return bool
     */
    private function isTextInLanguage(string $text, string $language): bool
    {
        switch (strtolower($language)) {
            case 'ja':
                // 日文（平假名、片假名）
                // 僅檢查平假名和片假名，避免與簡體中文的漢字混淆
                return preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text) === 1;
            case 'ko':
                // 韓文（諺文）
                return preg_match('/[\x{AC00}-\x{D7AF}]/u', $text) === 1;
            case 'en':
                // 英文（僅字母）
                return preg_match('/^[A-Za-z\s]+$/', $text) === 1;
            case 'ar':
                // 阿拉伯文
                return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
            case 'tr':
                // 土耳其文（包含特定字符）
                return preg_match('/[A-Za-z\sğüşöçİı]+$/u', $text) === 1;
            default:
                return false;
        }
    }

    private function setChatChannelConfig(int $chatId, array $config): void
    {
        TelegramGroupConfig::updateOrCreate(
        // 第一個參數：用於查找記錄的條件
            ['chat_id' => $chatId],
            // 第二個參數：要更新或創建的數據
            $config
        );
        $redisKey = "Telegram:GroupConfig:{$chatId}";
        Redis::del($redisKey);
    }

    private function getTelegramGroupConfig(int $chatId)
    {
        // 检查頻道是否已经选择语言
        $redisKey = "Telegram:GroupConfig:{$chatId}";
        $config = (array)@json_decode(Redis::get($redisKey), true);
        if (empty($config)) {
            $config = Optional(TelegramGroupConfig::where('chat_id', $chatId)->first())->toArray();
            Redis::set($redisKey, json_encode($config));
        }
        return $config;
    }

    private function sendTelegram(array $message = [])
    {
        $token = config('telegram.translate.bot_token');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $this->execCurl($url, $message);
    }

    /**
     * @param string $url
     * @param array $message
     * @return void
     */
    public function execCurl(string $url, array $message): void
    {
        try {
            file_get_contents($url . '?' . http_build_query($message));
        } catch (\Exception $e) {
            Log::info(json_encode([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'Line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]));
        }
    }

    /**
     * @param string $url
     * @param int $lastUpdateId
     * @return false|string
     */
    public function getContentTelegram(string $url, int $lastUpdateId): string|false
    {
        try {
            return file_get_contents("{$url}?offset=" . ($lastUpdateId + 1));
        } catch (\Exception $e) {
            Log::info(json_encode([
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'Line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]));
            return '';
        }
    }

    private function translate($text, $targetLang, $originalLang = '')
    {
        $client = new Client();
        $url = 'https://translate.googleapis.com/translate_a/single';
        $params = [
            'client' => 'gtx',
            'dt' => 't',
            'sl' => 'auto',
            'tl' => $targetLang,
            'q' => $text,
            'autoDetect' => true,
        ];

        if (!empty($originalLang)) {
//            Log::info('originalLang=' . $originalLang);
            $params['sl'] = $originalLang; // 翻译前的语言
        }

        $response = $client->get($url, ['query' => $params]);
        $result = json_decode($response->getBody(), true);

        return $result[0][0][0] ?? $text;
    }

    private function translateFromGoogle($text, $targetLanguage)
    {
        $apiKey = config('services.Google.api_key');
        $client = new Client();
        $url = 'https://translation.googleapis.com/language/translate/v2';

        $response = $client->post($url, [
            'query' => [
                'key' => $apiKey,
            ],
            'json' => [
                'q' => $text,
                'target' => $targetLanguage,
                'format' => 'text',
            ],
        ]);

        $result = json_decode($response->getBody(), true);
        return $result['data']['translations'][0]['translatedText'] ?? null;
    }

    private function setTelegramGroupConfig(int $chatId, array $config = [])
    {
        $this->setChatChannelConfig($chatId, $config);
        if (isset($config['translate_target_language'])) {
            $langCode = $config['translate_target_language'];
            $message = '语言已更新为：' . $this->getLanguageName($langCode);
        } else if (isset($config['translate_model_id'])) {
            $modelId = $config['translate_model_id'];
            $message = '翻译模组已更新为：' . TelegramGroupConfigEnum::TRANSLATE_MODEL[$modelId];
        }


        $this->sendTelegram([
            'chat_id' => $chatId,
            'text' => $message,
        ]);
    }

    private function googleDetectLanguage($text)
    {
        $client = new Client();
        $url = 'https://translation.googleapis.com/language/translate/v2/detect';
        $apiKey = config('services.Google.api_key');

        $response = $client->post($url, [
            'query' => [
                'key' => $apiKey,
            ],
            'json' => [
                'q' => $text,
            ],
        ]);

        $result = json_decode($response->getBody(), true);
        return $result['data']['detections'][0][0]['language'] ?? null;
    }

    private function setLastUpdateId($botToken, $lastUpdateId)
    {
        $redisKey = "Telegram:LastUpdate:{$botToken}";
        Redis::set($redisKey, $lastUpdateId);
        return $lastUpdateId;
    }

    private function getLastUpdateId($botToken)
    {
        $redisKey = "Telegram:LastUpdate:{$botToken}";
        return Redis::get($redisKey);
    }

    private function checkWhiteChatId(int $chatId): bool
    {
        $whiteChatIds = explode(',', config('telegram.translate.white_chat_ids'));
        if (in_array($chatId, $whiteChatIds)) {
            return true;
        }
        return false;
    }
}
