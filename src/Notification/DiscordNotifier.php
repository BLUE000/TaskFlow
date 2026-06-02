<?php
namespace Notification;

use RuntimeException;

/**
 * DiscordNotifier - Discord Webhook 宛てに通知を送信するクラス
 */
class DiscordNotifier {
    /**
     * Discord Webhook を使用して予定の追加・編集を通知する
     * 
     * @param array $schedule 予定データ (復号済みの平文データ)
     * @param string $webhookUrl 送信先の Discord Webhook URL
     * @return bool 送信結果
     */
    public static function notify(array $schedule, string $webhookUrl): bool {
        if (empty($webhookUrl) || empty($schedule['title'])) {
            return false;
        }

        // 送信内容 (Embed) の組み立て
        $typeLabel = [
            'simple' => '簡易予定',
            'period' => '期間予定',
            'task' => '個人タスク'
        ][$schedule['type'] ?? 'simple'] ?? '予定';

        $statusLabel = [
            'todo' => '未着手 (todo)',
            'doing' => '進行中 (doing)',
            'done' => '完了 (done)'
        ][$schedule['status'] ?? 'todo'] ?? '不明';

        $periodStr = '日時未設定';
        if (!empty($schedule['start_at'])) {
            $periodStr = $schedule['start_at'];
            if (!empty($schedule['end_at'])) {
                $periodStr .= ' 〜 ' . $schedule['end_at'];
            }
        }

        $color = 3447003; // デフォルトはブルー (simple)
        if ($schedule['type'] === 'period') {
            $color = 11032055; // パープル
        } elseif ($schedule['type'] === 'task') {
            $color = $schedule['status'] === 'doing' ? 16349462 : 1060825; // オレンジ (doing) または グリーン (todo/done)
        }

        $fields = [
            [
                'name' => '予定名',
                'value' => $schedule['title'],
                'inline' => false
            ],
            [
                'name' => '日時',
                'value' => $periodStr,
                'inline' => false
            ],
            [
                'name' => '区分',
                'value' => $typeLabel,
                'inline' => true
            ]
        ];

        if ($schedule['type'] === 'task') {
            $fields[] = [
                'name' => '状況',
                'value' => $statusLabel,
                'inline' => true
            ];
            $fields[] = [
                'name' => '負荷(重み)',
                'value' => ($schedule['weight'] ?? 0.0) . ' 時間',
                'inline' => true
            ];
        }

        if (!empty($schedule['description'])) {
            $fields[] = [
                'name' => '詳細メモ',
                'value' => $schedule['description'],
                'inline' => false
            ];
        }

        $payload = [
            'embeds' => [
                [
                    'title' => "🔔 TaskFlow 予定更新通知",
                    'color' => $color,
                    'fields' => $fields,
                    'timestamp' => date('c')
                ]
            ]
        ];

        return self::sendRequest($webhookUrl, $payload);
    }

    /**
     * cURL を使用して Discord API へ POST リクエストを送信する
     */
    private static function sendRequest(string $url, array $payload): bool {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        $jsonData = json_encode($payload);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ]);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Discord Webhook は正常時 204 No Content を返す
        if ($httpCode === 204 || $httpCode === 200) {
            return true;
        }

        error_log("Discord Webhook notification failed. HTTP Status Code: " . $httpCode);
        return false;
    }
}
