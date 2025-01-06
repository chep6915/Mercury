<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchTelegramMessages extends Command
{
    protected $signature = 'telegram:fetch';
    protected $description = 'Fetch messages from Telegram bot';

    private array $userPreferences = []; // 用户语言偏好

    public array $languageNames = [
        'ja' => '日文',
        'ko' => '韩文',
        'en' => '英文',
        'ar' => '阿拉伯语',
        'tr' => '土耳其语',
    ];

    public function handle()
    {
        $token = env('TG_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/getUpdates";

        $lastUpdateId = 0;

        while (true) {
            $isCallBack = false;
            $response = file_get_contents("{$url}?offset=" . ($lastUpdateId + 1));
            $data = json_decode($response, true);
            Log::info('===' . json_encode($data));
            if (!empty($data['result'])) {
                foreach ($data['result'] as $message) {
                    Log::info('message=' . json_encode($message, JSON_PRETTY_PRINT));
                    $lastUpdateId = $message['update_id'];

                    if (isset($message['callback_query'])) {
                        Log::info('is_call_back');
                        $isCallBack = true;
                    } else {
                        Log::info('not_call_back');
                    }

                    if ($isCallBack) {
                        Log::info('is_call_back_query');
                        $chatId = $message['callback_query']['message']['chat']['id'] ?? null;
                        $callbackData = $message['callback_query']['data'] ?? null;
                        $text = $message['callback_query']['message']['text'] ?? '';
                    } else {
                        Log::info('not call_back_query data fetching ');
                        Log::info('===' . json_encode($message, JSON_PRETTY_PRINT));
                        $chatId = $message['message']['chat']['id'] ?? null;
                        $text = $message['message']['text'] ?? '';
                        $messageId = $message['message']['message_id'] ?? null;
                    }

                    Log::info('chatId=' . $chatId);
                    // 確認 chatId 是否為空
                    if (!$chatId) {
                        Log::warning("收到的消息中沒有 chatId");
                        continue;
                    }

                    if ($isCallBack) {
                        if ($callbackData && strpos($callbackData, 'lang_') === 0) {
                            $this->setLanguagePreference($chatId, substr($callbackData, 5)); // 提取语言代码
                        }
                    } else {
                        if ($text === '/lang') {
                            $this->sendLanguageOptions($chatId);
                        } else if (str_starts_with($text, '/lang')) {
                            $this->setSpecificLanguage($chatId, substr($text, 6));
                        } else {
                            $this->processMessage($chatId, $text, $messageId);
                        }
                    }
                }
            }

            sleep(1);
        }
    }

    private function sendLanguageOptions($chatId)
    {
        $token = env('TG_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $languages = [
            '日文' => 'ja',
            '韩文' => 'ko',
            '英文' => 'en',
            '阿拉伯语' => 'ar',
            '土耳其语' => 'tr',
        ];

        $keyboard = [];
        foreach ($languages as $name => $code) {
            $keyboard[] = [['text' => $name, 'callback_data' => 'lang_' . $code]];
        }

        $message = [
            'chat_id' => $chatId,
            'text' => '请选择翻译目标语言：',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ];

        $this->execCurl($url, $message);
    }

    private function setLanguagePreference($chatId, $langCode)
    {
        Log::info('choosing langCode:' . $langCode);
        // 设置用户语言偏好
        $this->userPreferences[$chatId] = $langCode;

        $token = env('TG_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        Log::info('chatId=' . $chatId);
        Log::info('语言已更新为：' . $this->getLanguageName($langCode));
        $response = [
            'chat_id' => $chatId,
            'text' => '语言已更新为：' . $this->getLanguageName($langCode),
        ];

        $this->execCurl($url, $response);
    }

    private function getLanguageName($langCode)
    {
        return $this->languageNames[$langCode] ?? '未知语言';
    }

    private function processMessage($chatId, $text, $messageId)
    {
        if (empty($this->userPreferences[$chatId])) {
            $this->sendLanguageOptions($chatId);
        } else {
            $token = env('TG_TOKEN');
            $url = "https://api.telegram.org/bot{$token}/sendMessage";

            // 检查用户是否已经选择语言
            $userTargetLang = $this->userPreferences[$chatId] ?? 'zh-CN'; // 默认中文

            $originalLanguage = $this->detectLanguage($text);
            Log::info('detected language=' . $originalLanguage);

            if ($originalLanguage == $userTargetLang) {
                // 翻译目标成简体中文
                $userTargetLang = 'zh-CN';
            }

            $translatedText = $this->translateFromGoogle($text, $userTargetLang);
            Log::info($translatedText);

            $response = [
                'chat_id' => $chatId,
                'text' => $translatedText,
                'reply_to_message_id' => $messageId,
            ];

            $this->execCurl($url, $response);
        }
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
            Log::info($e->getFile() . $e->getLine());
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
            Log::info('originalLang=' . $originalLang);
            $params['sl'] = $originalLang; // 翻译前的语言
        }

        $response = $client->get($url, ['query' => $params]);
        $result = json_decode($response->getBody(), true);

        return $result[0][0][0] ?? $text;
    }

    private function translateFromGoogle($text, $targetLanguage)
    {
        Log::info('translateFromGoogle');
        $apiKey = env('GOOGLE_API_KEY');
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

    private function setSpecificLanguage($chatId, $langCode)
    {
        // 设置用户语言偏好
        Log::info('choosing langCode:' . $langCode);
        $token = env('TG_TOKEN');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        Log::info('chatId=' . $chatId);
        Log::info('语言已更新为：' . $this->getLanguageName($langCode));

        if (!isset($this->languageNames[$langCode])) {
            $message = '不支援该语言代码:' . $langCode;
        } else {
            $this->userPreferences[$chatId] = $langCode;
            $message = '语言已更新为：' . $this->getLanguageName($langCode);
        }

        $response = [
            'chat_id' => $chatId,
            'text' => $message,
        ];

        $this->execCurl($url, $response);
    }

    private function detectLanguage($text)
    {
        $client = new Client();
        $url = 'https://translation.googleapis.com/language/translate/v2/detect';
        $apiKey = env('GOOGLE_API_KEY');

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
}
