# TaskFlow 基本設計書 (Basic Design)

本システムは、PHP 8.1 / MySQL 8.0 で構成されたWebアプリケーションと、C++ / Qt 6 で構成されたデスクトップGUIアプリケーションが、データベースを共有・同期して動作するハイブリッド構成です。
データの暗号化および難読化には `TransCipher` ライブラリ（C++版/PHP版）を使用し、機密データを保護します。

---

## 1. システム構成とデータフロー

```mermaid
graph TD
    subgraph Web Server (MySQL 8.0)
        PHP[PHP 8.1 API/Web]
        DB_Remote[(MySQL 8.0 Remote DB)]
        TransPHP[TransCipher PHP]
    end
    
    subgraph Local Client (C++ / Qt 6)
        QtGUI[Qt6 GUI App]
        DB_Local[(SQLite 3 Local DB)]
        TransCPP[TransCipher C++ DLL]
    end

    PHP <-->|PDO| DB_Remote
    PHP <-->|データ保護委譲| TransPHP
    
    QtGUI <-->|QSQLITE| DB_Local
    QtGUI <-->|DLL Link| TransCPP
    
    QtGUI -.->|MySQL同期 (インターネット経由)| DB_Remote
```

* **常時オンライン (Web側)**: 一般閲覧者向けのカレンダー/ガントチャート表示、および管理者のWebログイン操作は、常にリモートDB（MySQL）の最新データを参照します。
* **オフライン対応 (C++デスクトップ側)**: インターネット未接続時でもローカルの SQLite 3 からデータを読み書き可能。オンライン復帰時にリモートDBと同期を行います。
* **データ保護**: ローカルDB（SQLite）内のキャッシュおよびリモートDB（MySQL）内の機密データは、常に `TransCipher` で暗号化された状態で保存されます。

---

## 2. データベース設計 (共通スキーマ)

MySQL 8.0 と SQLite 3 でデータ構造の整合性を保つため、共通のテーブルスキーマを定義します。
オフラインで生成されたデータとオンラインで生成されたデータが衝突しないように、すべての主キーに **UUID (VARCHAR(36))** を採用します。

### 2.1. `schedules` テーブル (予定・タスク情報)
すべての予定（簡易予定、期間予定、個人タスク）をこのテーブルで一元管理します。予定の「種類」は `type` カラムで判別します。

| カラム名 | データ型 | NULL | キー | デフォルト値 | 説明 |
| :--- | :--- | :---: | :---: | :--- | :--- |
| `id` | VARCHAR(36) | NO | PK | - | UUID (データの一意識別子) |
| `title` | TEXT | NO | - | - | 予定のタイトル (**TransCipherで暗号化**) |
| `description` | TEXT | YES | - | NULL | 予定の詳細・メモ (**TransCipherで暗号化**) |
| `type` | VARCHAR(20) | NO | - | 'simple' | 予定の種類 (`simple`: 簡易, `period`: 期間, `task`: 個人タスク) |
| `start_at` | DATETIME | YES | - | NULL | 開始日時 (期間予定・タスク用) |
| `end_at` | DATETIME | YES | - | NULL | 終了日時 (期間予定・タスク用) |
| `status` | VARCHAR(20) | NO | - | 'todo' | 進捗ステータス (タスク用: `todo`, `doing`, `done`) |
| `priority` | INT | NO | - | 0 | 優先度 (タスク用: 数値指定) |
| `weight` | DOUBLE | NO | - | 0.0 | 重み/所要時間 (タスク用: 単位は時間) |
| `time_slot_id` | VARCHAR(36) | YES | FK | NULL | 割り当てられた作業時間枠のID (`time_slots.id` への参照) |
| `category` | VARCHAR(50) | NO | - | 'general' | フィルタ用のカテゴリ/タグ |
| `is_public` | TINYINT(1) | NO | - | 0 | 公開フラグ (1: 公開, 0: 非公開) |
| `discord_webhook_id` | VARCHAR(36) | YES | FK | NULL | 通知先のDiscord Webhook ID (`webhooks.id` への参照) |
| `created_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 作成日時 |
| `updated_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 最終更新日時（同期判定に使用） |
| `is_deleted` | TINYINT(1) | NO | - | 0 | 論理削除フラグ（同期時に削除を伝播させるため物理削除はしない） |

### 2.2. `time_slots` テーブル (作業時間枠の定義)
1日の活動時間枠（例: 午前枠、午後枠）を定義します。

| カラム名 | データ型 | NULL | キー | デフォルト値 | 説明 |
| :--- | :--- | :---: | :---: | :--- | :--- |
| `id` | VARCHAR(36) | NO | PK | - | UUID |
| `name` | VARCHAR(100) | NO | - | - | 時間枠の名前 (例: "午前枠", "午後枠") |
| `start_time` | TIME | NO | - | - | 開始時間 (例: "10:00:00") |
| `end_time` | TIME | NO | - | - | 終了時間 (例: "13:00:00") |
| `limit_hours` | DOUBLE | NO | - | 0.0 | 許容作業時間 (オーバーフロー警告の判定基準値。単位: 時間) |
| `created_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 作成日時 |
| `updated_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 最終更新日時 |
| `is_deleted` | TINYINT(1) | NO | - | 0 | 論理削除フラグ |

### 2.3. `webhooks` テーブル (Discord Webhook 設定)

| カラム名 | データ型 | NULL | キー | デフォルト値 | 説明 |
| :--- | :--- | :---: | :---: | :--- | :--- |
| `id` | VARCHAR(36) | NO | PK | - | UUID |
| `name` | VARCHAR(100) | NO | - | - | Webhook名 (例: "仕事用", "プライベート用") |
| `url` | TEXT | NO | - | - | Discord Webhook URL (**TransCipherで暗号化**) |
| `created_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 作成日時 |
| `updated_at` | DATETIME | NO | - | CURRENT_TIMESTAMP | 最終更新日時 |
| `is_deleted` | TINYINT(1) | NO | - | 0 | 論理削除フラグ |

---

## 3. SQLite 3 ローカル管理用メタテーブル

C++アプリ側のローカルDB（SQLite）でのみ、最終同期のタイミングや状態を管理するために以下のメタテーブルを定義します。

### 3.1. `sync_metadata` テーブル

| カラム名 | データ型 | NULL | キー | 説明 |
| :--- | :--- | :---: | :---: | :--- |
| `key` | VARCHAR(50) | NO | PK | 設定キー (例: `last_synced_at`) |
| `value` | VARCHAR(100) | NO | - | 設定値 (例: 最終同期日時 "YYYY-MM-DD HH:MM:SS") |

* **同期の判定**: SQLite内のレコードのうち、`updated_at` が `sync_metadata` の `last_synced_at` よりも大きいレコードは、前回の同期以降にローカルで追加または編集（あるいは論理削除）された「未同期レコード」と判断します。

---

## 4. 同期プロトコルと競合解決

### 4.1. 双方向同期フロー
C++アプリがオンラインになり同期をトリガー（起動時または手動実行）した際、以下の手順で処理を行います。

```
[同期開始]
   │
   ├─① リモート(MySQL)から「最終同期日時(last_synced_at)」以降に更新されたレコードを全取得 (プル)
   │
   ├─② ローカル(SQLite)内の「最終同期日時」以降に更新されたレコードを抽出 (プッシュ対象)
   │
   ├─③ 競合(衝突)の判定
   │   │
   │   ├─◆ 競合なし (ローカルのみ更新、またはリモートのみ更新)
   │   │   └─ 自動的にマージ (リモート更新をローカルに上書き、またはローカル更新をリモートに送信)
   │   │
   │   └─◆ 競合あり (同じUUIDのレコードが、ローカルとリモートの双方で last_synced_at 以降に更新されている場合)
   │       └─ 該当レコードを一時保留し、「競合解決ダイアログ」を表示
   │
   ├─④ ユーザーによる手動競合解決
   │   └─ 「ローカル側を採用」「Web(リモート)側を採用」「その場で再編集」を選択
   │
   ├─⑤ すべてのデータ書き込み（リモート・ローカルの更新）
   │
   └─⑥ ローカルの `last_synced_at` を現在のサーバー時刻に更新
[同期完了]
```

### 4.2. 競合（衝突）の判定基準
ローカルの最終同期時刻を $T_{sync}$ とします。同一UUIDのレコードにおいて、以下の両方が成立した場合に **「競合（衝突）」** と判定します。
* ローカルレコードの最終更新時刻 $L_{up} > T_{sync}$
* リモートレコードの最終更新時刻 $R_{up} > T_{sync}$

競合が検出されたレコードは、ユーザーが解決するまで同期処理を一時サスペンド（またはそのデータのみをスキップして競合解決を促すダイアログを表示）します。

---

## 5. データ保護設計 (TransCipher の適用)

### 5.1. 暗号化対象フィールド
* `schedules.title` (予定タイトル)
* `schedules.description` (予定詳細)
* `webhooks.url` (Discord Webhook URL)

### 5.2. 暗号化・復号用共通インターフェース設計
アプリケーションコードが `TransCipher` の内部仕様（バージョンごとのヘッダーサイズの差分など）に依存しないよう、またDB保存時のテキスト処理を共通化するために、暗号化・復号処理をラップする **`CryptoUtil`** インターフェースを定義します。

* **データの永続化形式**: `TransCipher` の出力はバイナリ形式（Version 2 は 64バイトのヘッダーを含みます。以前のVersion 1のヘッダー仕様等との差分があります）です。データベースの `TEXT` カラムに安全に保存するため、暗号化後のバイナリデータを **Base64エンコード** した文字列として格納します。復号時はBase64デコードしてから復号関数へ渡します。
* **メリット**: アプリケーションロジックはヘッダーサイズやバイナリ変換を意識することなく、透過的に「文字列の暗号化・復号」を行えます。また、将来的な `TransCipher` のバージョンアップ時もこのラッパー層の修正のみで対応可能です。

#### C++ 側呼び出し I/F (`CryptoUtil` クラス)
`Qt 6` 環境で、`QString` と `QByteArray` の変換および `TransCipher` の呼び出しを抽象化します。

```cpp
class CryptoUtil {
public:
    // 秘密鍵を設定して初期化
    static void init(const QString& secretKey);

    // テキストを暗号化してBase64文字列で返す
    static QString encrypt(const QString& plainText);

    // Base64暗号化文字列を復号して平文テキストで返す
    static QString decrypt(const QString& cipherTextBase64);
};
```
※ 内部実装では `CipherEngine::encrypt(data, key, AesMode::Mandatory)` と `CipherEngine::decrypt(data, key)` を呼び出し、結果オブジェクト（`CipherResult`）を適切にハンドリングします。

#### PHP 側呼び出し I/F (`CryptoUtil` クラス)
PHP環境において、APIサーバー接続設定と `TransCipher` 呼び出しを抽象化します。

```php
class CryptoUtil {
    // 秘密鍵とAPI接続用の初期設定
    public static function init(string $secretKey, string $apiToken = null, string $apiUrl = null): void;

    // テキストを暗号化してBase64文字列で返す
    public static function encrypt(string $plainText): ?string;

    // Base64暗号化文字列を復号して平文テキストで返す
    public static function decrypt(string $cipherTextBase64): ?string;
}
```
※ 内部実装では `CipherEngine::configure` による接続情報設定と、`CipherEngine::encrypt($data, $key, RecipeManager::MODE_MANDATORY, "2.0")` の呼び出しを行います。

---

## 6. 画面・UI構成案

### 6.1. Web表示側（PHP + HTML/CSS/JS） - 一般閲覧・簡易管理
* **カレンダー表示（月/週）**: 公開予定のみを祝日CSV情報とともに表示。時間枠ごとのタスク所要時間が限界値（`limit_hours`）を超えた場合、カレンダー上の時間枠が警告色でハイライトされます。
* **ガントチャート表示**: 期間予定・タスクの進行状況をタイムライン表示。
* **管理者用ダッシュボード（ログイン要）**: 簡易CRUD（予定の追加・編集）、一括公開/非公開切り替え、時間枠設定、Discord Webhook設定。

### 6.2. デスクトップアプリ側（C++ / Qt 6） - 管理者操作特化
* **メイン画面**: 予定の追加・編集・削除、およびカレンダー表示。
* **同期ステータス ＆ 競合解決画面**:
  * 同期進行インジケータ、オンライン/オフライン切り替え。
  * **競合解決ダイアログ**: 同一データで更新の衝突があった際、左右に「ローカルの更新内容」「Web(サーバー)側の更新内容」を配置して比較し、どちらを優先するかをユーザーが選択・マージできる専用インターフェース。

---

## 7. モジュール分割・独立設計原則

機能の追加・変更・削除を安全かつ容易に行うため、本システムはPHP（Web側）およびC++（デスクトップ側）の双方で、強結合を避けた**「疎結合・高内聚（高コヒージョン）なモジュール分割」**を徹底します。

### 7.1. PHP (Web側) のモジュール分割方針
各機能をクラスやネームスペースによって物理的・論理的に独立させます。

1. **データアクセス層 (Repository)**:
   * DB（MySQL）接続やSQLの発行は各リポジトリクラス（`ScheduleRepository`, `TimeSlotRepository` 等）に閉じ込め、ビジネスロジックやUI層からSQLを直接隠蔽します。
2. **暗号化層 (Security/Crypto)**:
   * `TransCipher` に依存する暗号化処理は `CryptoUtil` サービスに限定します。他のモジュールは `TransCipher` の存在を意識せず、`CryptoUtil::encrypt/decrypt` を呼ぶだけとします。
3. **通知モジュール (Notification)**:
   * Discord通知ロジックを `DiscordNotifier` としてクラス化し、予定登録ロジックから分離します。将来的に Slack やメールなどの通知先を追加する場合は、共通インターフェースを実装する形で容易に拡張可能です。
4. **祝日CSVパーサー (Holiday)**:
   * 祝日CSVの解析ロジックを `HolidayParser` として分離し、カレンダー描画ロジックから独立させます。

### 7.2. C++ (Qt 6) のモジュール分割方針
Qtのシグナル・スロット機能および設計パターンを活用し、各モジュール間の結びつきを最小限にします。

1. **ドメインモデル (Models)**:
   * `Task`, `TimeSlot` 等のデータ型は純粋なドメイン知識のみを持ち、DB保存や暗号化のロジックは内包しません。
2. **ストレージ層 (Database)**:
   * SQLite接続とMySQL接続をそれぞれ `LocalDatabase` / `RemoteDatabase` クラスとして実装し、データのCRUD操作を抽象化します。
3. **同期エンジン (SyncEngine)**:
   * 同期の状態遷移、プッシュ/プルフロー、競合の検出は `SyncEngine` クラスが独立して制御します。UI（ダイアログ）と直接結びつかず、競合検知時はシグナルを発行してUIへ解決を要請します。
4. **計算・スケジューリングエンジン (Scheduler)**:
   * 時間枠ごとの負荷計算や警告判定を行う `ScheduleCalculator` を分離します。UIのカレンダー描画ロジックやデータベース処理に依存せず、純粋なデータ入力に対して計算結果を返すモジュールとします。
5. **UI表示層 (Views)**:
   * GUI部品（ウィンドウ、ダイアログ等）はUI表示とユーザー入力の検知に徹します。ビジネスロジックはシグナルを介してコントローラーや各エンジンに委譲します。
