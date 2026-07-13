cmake_minimum_required(VERSION 3.16)

# ローカル環境設定（コンパイラ・Qtパスなど）のインポート
if(EXISTS "${CMAKE_CURRENT_SOURCE_DIR}/local_env.cmake")
    include("${CMAKE_CURRENT_SOURCE_DIR}/local_env.cmake")
endif()

project(TaskFlow-Client VERSION 1.0.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Qt 6 の Core, Widgets, Sql, Network モジュールを検索
find_package(Qt6 COMPONENTS Core Widgets Sql Network REQUIRED)

# 実行ターゲット定義
add_executable(TaskFlow-Client
    src/main.cpp
    src/common/crypto_util.h
    src/common/crypto_util.cpp
    src/database/db_manager.h
    src/database/db_manager.cpp
    src/database/local_storage.h
    src/database/local_storage.cpp
    src/network/network_manager.h
    src/network/network_manager.cpp
    src/network/sync_client.h
    src/network/sync_client.cpp
    src/scheduler/scheduler.h
    src/scheduler/scheduler.cpp
    src/sync/sync_engine.h
    src/sync/sync_engine.cpp
    src/ui/mainwindow.h
    src/ui/mainwindow.cpp
    src/ui/mainwindow.ui
    src/ui/conflict_dialog.h
    src/ui/conflict_dialog.cpp
    src/ui/conflict_dialog.ui
)

# Qt の自動MOC/UIC/RCC設定
set_target_properties(TaskFlow-Client PROPERTIES
    AUTOMOC ON
    AUTOUIC ON
    AUTORCC ON
)

# インクルードパスの設定
target_include_directories(TaskFlow-Client PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/include
    ${CMAKE_CURRENT_SOURCE_DIR}/src
)

# バージョン情報の定義
target_compile_definitions(TaskFlow-Client PRIVATE
    APP_VERSION="${PROJECT_VERSION}"
)

# リンクライブラリディレクトリの設定 (相対パス)
target_link_directories(TaskFlow-Client PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/thirdparty/transcipher
)

# Qt Core/Widgets/Sql/Network と TransCipher 動的ライブラリとのリンク
# ※TransCipher-Dist のライブラリ名は libTransCipher.dll / libTransCipher.dll.a
target_link_libraries(TaskFlow-Client PRIVATE
    Qt6::Core
    Qt6::Widgets
    Qt6::Sql
    Qt6::Network
    libTransCipher
)

# Windows環境用: ビルド後に出力先フォルダへ libTransCipher.dll を自動コピーする
if(WIN32)
    add_custom_command(TARGET TaskFlow-Client POST_BUILD
        COMMAND ${CMAKE_COMMAND} -E copy_if_different
        "${CMAKE_CURRENT_SOURCE_DIR}/thirdparty/transcipher/libTransCipher.dll"
        $<TARGET_FILE_DIR:TaskFlow-Client>
    )
endif()

# --- 自動テスト (QTest) の追加 ---
find_package(Qt6 COMPONENTS Test REQUIRED)

add_executable(TaskFlow-Client-Test
    tests/test_storage.cpp
    src/common/crypto_util.h
    src/common/crypto_util.cpp
    src/database/db_manager.h
    src/database/db_manager.cpp
    src/database/local_storage.h
    src/database/local_storage.cpp
    src/scheduler/scheduler.h
    src/scheduler/scheduler.cpp
    src/network/network_manager.h
    src/network/network_manager.cpp
    src/network/sync_client.h
    src/network/sync_client.cpp
    src/sync/sync_engine.h
    src/sync/sync_engine.cpp
)

set_target_properties(TaskFlow-Client-Test PROPERTIES
    AUTOMOC ON
)

target_include_directories(TaskFlow-Client-Test PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/include
    ${CMAKE_CURRENT_SOURCE_DIR}/src
)

# バージョン情報の定義
target_compile_definitions(TaskFlow-Client-Test PRIVATE
    APP_VERSION="${PROJECT_VERSION}"
)

target_link_directories(TaskFlow-Client-Test PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/thirdparty/transcipher
)

target_link_libraries(TaskFlow-Client-Test PRIVATE
    Qt6::Core
    Qt6::Sql
    Qt6::Network
    Qt6::Test
    libTransCipher
)

if(WIN32)
    add_custom_command(TARGET TaskFlow-Client-Test POST_BUILD
        COMMAND ${CMAKE_COMMAND} -E copy_if_different
        "${CMAKE_CURRENT_SOURCE_DIR}/thirdparty/transcipher/libTransCipher.dll"
        $<TARGET_FILE_DIR:TaskFlow-Client-Test>
    )
endif()
