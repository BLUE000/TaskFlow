<?php
namespace Holiday;

use RuntimeException;
use InvalidArgumentException;

/**
 * HolidayParser - 内閣府の祝日CSVファイルを解析するクラス
 */
class HolidayParser {
    /**
     * 祝日CSVファイルを解析して、[日付文字列 => 祝日名] の連想配列を取得する
     * 
     * @param string $filePath 祝日CSVファイルのパス
     * @return array [YYYY-MM-DD => 祝日名] 形式の連想配列
     * @throws RuntimeException ファイル読み込みエラーが発生した場合
     * @throws InvalidArgumentException 無効なファイル形式の場合
     */
    public static function parse(string $filePath): array {
        // ファイルパスの安全な検証 (ディレクトリトラバーサル対策)
        // 実在するファイルであること、および読み込み可能であることを検証
        $realPath = realpath($filePath);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException("Target holiday CSV file does not exist or is not readable.");
        }

        $fileHandle = fopen($realPath, 'r');
        if ($fileHandle === false) {
            throw new RuntimeException("Failed to open holiday CSV file.");
        }

        $holidays = [];

        // ヘッダー行をスキップ（内閣府CSVは1行目が「国民の祝日について」などの説明行）
        // 実際のデータが始まる行まで適切に読み込む
        $isFirst = true;

        while (($row = fgetcsv($fileHandle, 1000, ",")) !== false) {
            // 行が空、または項目数が不足している場合はスキップ
            if (empty($row) || count($row) < 2) {
                continue;
            }

            // 内閣府のCSVは Shift-JIS (CP932) のため、UTF-8に変換
            $dateCol = mb_convert_encoding(trim($row[0]), 'UTF-8', 'SJIS-win');
            $nameCol = mb_convert_encoding(trim($row[1]), 'UTF-8', 'SJIS-win');

            // ヘッダー説明行（文字が含まれているなど日付形式でないもの）をスキップ
            // 期待する日付形式は YYYY/M/D または YYYY-M-D
            $dateTime = self::parseDateString($dateCol);
            if ($dateTime === null) {
                continue;
            }

            // YYYY-MM-DD 形式に統一して連想配列へ格納
            $formattedDate = $dateTime->format('Y-m-d');
            $holidays[$formattedDate] = $nameCol;
        }

        fclose($fileHandle);
        return $holidays;
    }

    /**
     * 文字列からDateTimeオブジェクトへのパースを試みる
     * 
     * @param string $dateStr 日付文字列
     * @return \DateTime|null パース成功時はDateTimeオブジェクト、失敗時はnull
     */
    private static function parseDateString(string $dateStr): ?\DateTime {
        // "YYYY/m/d" 形式をパース
        $dt = \DateTime::createFromFormat('Y/m/d', $dateStr);
        if ($dt && $dt->format('Y/m/d') === $dateStr) {
            return $dt;
        }

        // "Y-m-d" 形式をパース
        $dt = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($dt && $dt->format('Y-m-d') === $dateStr) {
            return $dt;
        }

        return null;
    }
}
