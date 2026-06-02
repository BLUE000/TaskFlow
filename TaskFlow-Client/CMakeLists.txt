cmake_minimum_required(VERSION 3.16)
project(TaskFlow-Client LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Qt 6 の Core モジュールを検索
find_package(Qt6 COMPONENTS Core REQUIRED)

# 実行ターゲット定義
add_executable(TaskFlow-Client
    src/main.cpp
    src/common/crypto_util.h
    src/common/crypto_util.cpp
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

# リンクライブラリディレクトリの設定 (相対パス)
target_link_directories(TaskFlow-Client PRIVATE
    ${CMAKE_CURRENT_SOURCE_DIR}/thirdparty/transcipher
)

# Qt Core と TransCipher 動的ライブラリとのリンク
# ※TransCipher-Dist のライブラリ名は libTransCipher.dll / libTransCipher.dll.a
target_link_libraries(TaskFlow-Client PRIVATE
    Qt6::Core
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
