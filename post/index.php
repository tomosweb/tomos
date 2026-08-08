<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

if (empty($_SESSION['tomos_post_token'])) {
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];
$warnings = [];
$uploadResult = null;
$withdrawTarget = null;
$withdrawResult = null;
$trashResult = null;
$editableSearchResult = null;
$editableQuery = '';
$editablePage = 1;
$activeSection = normalizeSection((string) ($_GET['section'] ?? 'upload'));
$submissionId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['submission_id'] ?? '')
    : Tomos\PostSubmissionGuard::issueId();

if ($config === []) {
    renderPage('Tomos Post', $config, ['config.php が見つかりません。先にsetupを完了してください。'], [], [], null, null, null, null, null, '', true, $activeSection, $submissionId);
    exit;
}

if (empty($config['features']['post'])) {
    renderPage('Tomos Post', $config, ['Tomos Post は現在無効です。'], [], [], null, null, null, null, null, '', true, $activeSection, $submissionId);
    exit;
}

$postPasswordHash = (string) ($config['security']['post_password_hash'] ?? '');
if ($postPasswordHash === '') {
    renderPage('Tomos Post', $config, ['管理用合言葉が設定されていません。setupを確認するか、/post/reset/ で再発行してください。'], [], [], null, null, null, null, null, '', true, $activeSection, $submissionId);
    exit;
}

$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
$authRemember->restoreSession();

$postApi = (string) ($_GET['post_api'] ?? '');
if ($postApi !== '' && !class_exists(Tomos\PostUploadCapabilities::class)) {
    jsonResponse(['ok' => false, 'message' => 'Tomosの更新ファイルが揃っていません。配布ファイルをすべてアップロードしてください。'], 503);
}
if ($postApi === 'capabilities' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(['ok' => true, 'capabilities' => Tomos\PostUploadCapabilities::current()]);
}
if ($postApi !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        jsonResponse(['ok' => false, 'message' => 'Tomosの更新中です。完了してからもう一度投稿してください。'], 503);
    }
    if (isPostRequestTooLarge()) {
        jsonResponse(['ok' => false, 'capacity' => true, 'message' => 'この画像は、現在の公開先で扱える容量を超えています。別の画像を選んでください。'], 413);
    }
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) $_SESSION['tomos_post_token'], $token)) {
        jsonResponse(['ok' => false, 'message' => '投稿画面の有効期限が切れました。画面を再読み込みしてください。'], 403);
    }
    if (!class_exists(Tomos\PostImageUploadSessionStore::class)) {
        jsonResponse(['ok' => false, 'message' => 'Tomosの更新ファイルが揃っていません。配布ファイルをすべてアップロードしてください。'], 503);
    }
    $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
    $sessionStore = new Tomos\PostImageUploadSessionStore($cacheDir);
    if ($postApi === 'start') {
        $authWarnings = [];
        $authError = authenticatePostRequest($authRemember, $config, $rootDir, $postPasswordHash, $authWarnings);
        if ($authError !== '') {
            jsonResponse(['ok' => false, 'message' => $authError], strpos($authError, '15分間') !== false ? 429 : 403);
        }
        $expected = json_decode((string) ($_POST['expected_images'] ?? '[]'), true);
        if (!is_array($expected)) {
            jsonResponse(['ok' => false, 'message' => '投稿する画像を確認できませんでした。'], 400);
        }
        if (count($expected) > Tomos\PostUploadCapabilities::MAX_IMAGES) {
            jsonResponse([
                'ok' => false,
                'message' => '画像は最大' . Tomos\PostUploadCapabilities::MAX_IMAGES . '枚まで選択できます。',
            ], 400);
        }
        $record = $sessionStore->create(session_id(), $expected, $submissionId);
        if ($record === null) {
            jsonResponse(['ok' => false, 'message' => '投稿の準備を開始できませんでした。'], 400);
        }
        jsonResponse(['ok' => true, 'upload_session_id' => $record['id'], 'capabilities' => Tomos\PostUploadCapabilities::current()]);
    }
    if ($postApi === 'image') {
        if (empty($_SESSION['tomos_post_authenticated'])) {
            jsonResponse(['ok' => false, 'message' => '投稿の認証情報を確認できませんでした。'], 403);
        }
        $capabilities = Tomos\PostUploadCapabilities::current();
        $result = $sessionStore->receive(
            (string) ($_POST['upload_session_id'] ?? ''),
            session_id(),
            (string) ($_POST['image_name'] ?? ''),
            $_FILES['image_file'] ?? [],
            (int) $capabilities['effective_image_max_bytes'],
            (int) ($_POST['chunk_index'] ?? 0),
            (int) ($_POST['chunk_count'] ?? 1),
            (int) ($_POST['total_size'] ?? 0),
            $submissionId
        );
        jsonResponse($result, !empty($result['ok']) ? 200 : 400);
    }
    if ($postApi === 'cancel') {
        $deleted = $sessionStore->deleteOwned((string) ($_POST['upload_session_id'] ?? ''), session_id(), $submissionId);
        jsonResponse(['ok' => $deleted]);
    }
    jsonResponse(['ok' => false, 'message' => '投稿処理を確認できませんでした。'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'upload');
    $activeSection = sectionForAction($action);
    $token = (string) ($_POST['_token'] ?? '');
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度操作してください。';
    } elseif (isPostRequestTooLarge()) {
        $errors[] = '送信したファイルを受け付けられませんでした。画面を再読み込みして、もう一度お試しください。';
    } elseif (!hash_equals((string) $_SESSION['tomos_post_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } elseif ($action === 'search_editable_markdown') {
        $editableQuery = trim((string) ($_POST['edit_query'] ?? ''));
        $editablePage = max(1, (int) ($_POST['edit_page'] ?? 1));
        $authError = authenticatePostRequest($authRemember, $config, $rootDir, $postPasswordHash, $warnings);
        if ($authError !== '') {
            $errors[] = $authError;
        } else {
            $editableSearchResult = searchEditableMarkdown($config, $rootDir, $editableQuery, $editablePage);
            if (empty($editableSearchResult['ok'])) {
                $errors[] = (string) ($editableSearchResult['error'] ?? '原稿を検索できませんでした。');
            }
        }
    } elseif ($action === 'download_editable_markdown') {
        $authError = authenticatePostRequest($authRemember, $config, $rootDir, $postPasswordHash, $warnings);
        if ($authError !== '') {
            $errors[] = $authError;
        } else {
            $downloadError = sendEditableMarkdownDownload($config, $rootDir, (string) ($_POST['content_path'] ?? ''));
            if ($downloadError !== '') {
                $errors[] = $downloadError;
            }
        }
    } elseif ($action === 'download_basic_page') {
        $authError = authenticatePostRequest($authRemember, $config, $rootDir, $postPasswordHash, $warnings);
        if ($authError !== '') {
            $errors[] = $authError;
        } else {
            $downloadError = sendBasicPageDownload($config, $rootDir, (string) ($_POST['page'] ?? ''));
            if ($downloadError !== '') {
                $errors[] = $downloadError;
            }
        }
    } elseif ($action === 'cancel_upload') {
        $upload = new Tomos\PostUpload($config, $rootDir);
        $upload->cancelTemp((string) ($_POST['temp_id'] ?? ''), session_id());
        $messages[] = '投稿をやめました。既存ページは変更していません。';
    } elseif ($action === 'resolve_withdraw') {
        try {
            $resolver = new Tomos\PostContentResolver($config, $rootDir);
            $withdrawTarget = $resolver->resolve((string) ($_POST['withdraw_url'] ?? ''), (string) ($_POST['withdraw_path'] ?? ''));
            if (!$withdrawTarget->ok) {
                $errors = array_merge($errors, $withdrawTarget->errors);
            }
        } catch (RuntimeException $exception) {
            $errors[] = 'content/ フォルダを確認できませんでした。';
        }
    } elseif ($action === 'logout') {
        $authRemember->forgetCurrentBrowser();
        $messages[] = 'このブラウザの認証を解除しました。';
    } else {
        $authError = authenticatePostRequest($authRemember, $config, $rootDir, $postPasswordHash, $warnings);
        if ($authError !== '') {
            $errors[] = $authError;
        } else {
            $submissionGuard = null;
            $submissionActions = ['upload', 'finalize_staged_upload', 'update_upload', 'rename_upload', 'resolve_editable_upload', 'create_editable_upload'];
            if (in_array($action, $submissionActions, true)) {
                $submissionGuard = new Tomos\PostSubmissionGuard($config, $rootDir);
                $guardResult = $submissionGuard->acquire($submissionId);
                if (!$guardResult->allowed) {
                    $errors[] = $guardResult->message;
                    if ($guardResult->message === Tomos\PostSubmissionGuard::DUPLICATE_MESSAGE) {
                        $submissionId = Tomos\PostSubmissionGuard::issueId();
                    }
                }
            }
            if ($errors === []) {
            if ($action === 'site_settings_auth') {
                header('Location: ' . Tomos\Security::publicUrl('/post/settings/', (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''))));
                exit;
            }
            if ($action === 'theme_auth') {
                header('Location: ' . Tomos\Security::publicUrl('/post/theme/', (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''))));
                exit;
            }
            if ($action === 'analytics_update') {
                [$newConfig, $updateErrors] = Tomos\AnalyticsConfigWriter::update($config, (string) ($_POST['ga4_measurement_id'] ?? ''));
                if ($updateErrors !== []) {
                    $errors = array_merge($errors, $updateErrors);
                } elseif (!Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
                    $errors[] = 'config.php を更新できませんでした。';
                } else {
                    $config = $newConfig;
                    $cache = new Tomos\HtmlCache((string) ($config['paths']['cache_dir'] ?? ($rootDir . DIRECTORY_SEPARATOR . 'cache')), true);
                    if (!$cache->clearGenerated()) {
                        $warnings[] = 'HTMLキャッシュを削除できませんでした。表示が古い場合は cache/html/ を確認してください。';
                    }
                    $messages[] = Tomos\Ga4::measurementId($config) === ''
                        ? 'Google Analytics 4による計測を無効にしました。'
                        : 'Google Analytics 4の測定IDを更新しました。';
                    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                }
            } elseif ($action === 'finalize_staged_upload') {
                $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
                $sessionStore = new Tomos\PostImageUploadSessionStore($cacheDir);
                $uploadSessionId = (string) ($_POST['upload_session_id'] ?? '');
                $stagedPaths = $sessionStore->readyImages($uploadSessionId, session_id(), $submissionId);
                if ($stagedPaths === null) {
                    $errors[] = '必要な画像の送信が完了していません。もう一度投稿してください。';
                } else {
                    $stagedFiles = stagedImageFiles($stagedPaths);
                    $upload = new Tomos\PostUpload($config, $rootDir);
                    $omittedImages = is_array($_POST['omit_images'] ?? null) ? $_POST['omit_images'] : [];
                    $uploadResult = $upload->handle($_FILES['markdown_file'] ?? [], (string) ($_POST['folder'] ?? ''), '', session_id(), $stagedFiles, $omittedImages, true, $submissionId);
                    if ($uploadResult->ok) {
                        $sessionStore->deleteOwned($uploadSessionId, session_id(), $submissionId);
                        $messages[] = uploadSuccessMessage($uploadResult);
                        $warnings = array_merge($warnings, $uploadResult->warnings);
                        $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                    } elseif ($uploadResult->conflict) {
                        $sessionStore->deleteOwned($uploadSessionId, session_id(), $submissionId);
                    } else {
                        $errors = array_merge($errors, $uploadResult->errors);
                        $warnings = array_merge($warnings, $uploadResult->warnings);
                    }
                }
            } elseif ($action === 'resolve_editable_upload') {
                $upload = new Tomos\PostUpload($config, $rootDir);
                $tempId = (string) ($_POST['temp_id'] ?? '');
                $conflictAction = (string) ($_POST['conflict_action'] ?? 'proceed');
                if ($conflictAction === 'cancel') {
                    $upload->cancelTemp($tempId, session_id());
                    $messages[] = '更新を中止しました。サーバー上の原稿は変更していません。';
                } else {
                    $uploadResult = $upload->updateEditableFromTemp(
                        $tempId,
                        (string) ($_POST['editable_mode'] ?? ''),
                        $conflictAction === 'overwrite',
                        session_id(),
                        $submissionId
                    );
                    if ($uploadResult->ok) {
                        $messages[] = uploadSuccessMessage($uploadResult);
                        $warnings = array_merge($warnings, $uploadResult->warnings);
                        $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errors = array_merge($errors, $uploadResult->errors);
                    }
                }
            } elseif ($action === 'create_editable_upload') {
                $upload = new Tomos\PostUpload($config, $rootDir);
                $uploadResult = $upload->createEditableFromTemp((string) ($_POST['temp_id'] ?? ''), session_id(), $submissionId);
                if ($uploadResult->ok) {
                    $messages[] = uploadSuccessMessage($uploadResult);
                    $warnings = array_merge($warnings, $uploadResult->warnings);
                    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                } else {
                    $errors = array_merge($errors, $uploadResult->errors);
                }
            } elseif ($action === 'update_upload') {
                $upload = new Tomos\PostUpload($config, $rootDir);
                $uploadResult = $upload->updateFromTemp((string) ($_POST['temp_id'] ?? ''), session_id(), $submissionId);
                    if ($uploadResult->ok) {
                        $messages[] = uploadSuccessMessage($uploadResult);
                    $warnings = array_merge($warnings, $uploadResult->warnings);
                    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                } else {
                    $errors = array_merge($errors, $uploadResult->errors);
                }
            } elseif ($action === 'rename_upload') {
                $upload = new Tomos\PostUpload($config, $rootDir);
                $tempId = (string) ($_POST['temp_id'] ?? '');
                $uploadResult = $upload->createRenamedFromTemp($tempId, (string) ($_POST['new_file_name'] ?? ''), session_id(), $submissionId);
                if ($uploadResult->ok) {
                    $messages[] = '新しいページとして投稿しました。';
                    $warnings = array_merge($warnings, $uploadResult->warnings);
                    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                } else {
                    $errors = array_merge($errors, $uploadResult->errors);
                    $record = $upload->loadTemp($tempId, session_id());
                    if ($record instanceof Tomos\PostUploadTempRecord) {
                        $uploadResult = uploadConflictResultFromRecord($record);
                    }
                }
            } elseif ($action === 'withdraw') {
                try {
                    $resolver = new Tomos\PostContentResolver($config, $rootDir);
                    $withdrawTarget = $resolver->resolveContentPath((string) ($_POST['content_path'] ?? ''));
                } catch (RuntimeException $exception) {
                    $withdrawTarget = null;
                    $errors[] = 'content/ フォルダを確認できませんでした。';
                }
                if ($withdrawTarget instanceof Tomos\PostContentResolveResult && !$withdrawTarget->ok) {
                    $errors = array_merge($errors, $withdrawTarget->errors);
                } elseif ($errors === [] && $withdrawTarget instanceof Tomos\PostContentResolveResult) {
                    $withdraw = new Tomos\PostWithdraw($config, $rootDir);
                    $withdrawResult = $withdraw->withdraw($withdrawTarget->contentPath);
                    if ($withdrawResult->ok) {
                        $messages[] = '投稿を取り下げました。';
                        $warnings = array_merge($warnings, $withdrawResult->warnings);
                        $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errors = array_merge($errors, $withdrawResult->errors);
                    }
                }
            } elseif ($action === 'clear_trash') {
                if ((string) ($_POST['confirm_clear'] ?? '') !== 'DELETE') {
                    $errors[] = '完全削除する場合は、確認欄に「DELETE」と入力してください。';
                } else {
                    $manager = new Tomos\TrashManager($rootDir);
                    $trashResult = $manager->clear();
                    if ($trashResult->ok) {
                        $messages[] = $trashResult->deletedCount > 0 ? '取り下げ済みファイルを完全に削除しました。' : '削除対象はありません。';
                        $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errors = array_merge($errors, $trashResult->errors);
                    }
                }
            } else {
                if (hasPostedImages($_FILES['image_files'] ?? [])) {
                    $errors[] = 'このブラウザでは画像を安全に順番送信できません。別の最新ブラウザでお試しください。';
                } else {
                    $upload = new Tomos\PostUpload($config, $rootDir);
                    $omittedImages = is_array($_POST['omit_images'] ?? null) ? $_POST['omit_images'] : [];
                    $uploadResult = $upload->handle($_FILES['markdown_file'] ?? [], (string) ($_POST['folder'] ?? ''), '', session_id(), [], $omittedImages, false, $submissionId);
                    if ($uploadResult->ok) {
                        $messages[] = uploadSuccessMessage($uploadResult);
                        $warnings = array_merge($warnings, $uploadResult->warnings);
                        $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                    } else {
                        $errors = array_merge($errors, $uploadResult->errors);
                        $warnings = array_merge($warnings, $uploadResult->warnings);
                    }
                }
            }
            if ($submissionGuard instanceof Tomos\PostSubmissionGuard) {
                if ($uploadResult instanceof Tomos\PostUploadResult && $uploadResult->ok && !$submissionGuard->markCompleted()) {
                    $warnings[] = '投稿は完了しましたが、二重送信防止の完了記録を保存できませんでした。';
                }
                $submissionGuard->release();
                if ($uploadResult instanceof Tomos\PostUploadResult && $uploadResult->ok) {
                    $submissionId = Tomos\PostSubmissionGuard::issueId();
                }
            }
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $activeSection === 'manage'
    && !empty($_SESSION['tomos_post_authenticated'])
) {
    $editableQuery = trim((string) ($_GET['edit_query'] ?? ''));
    $editablePage = max(1, (int) ($_GET['edit_page'] ?? 1));
    if ($editableQuery !== '') {
        $editableSearchResult = searchEditableMarkdown($config, $rootDir, $editableQuery, $editablePage);
        if (empty($editableSearchResult['ok'])) {
            $errors[] = (string) ($editableSearchResult['error'] ?? '原稿を検索できませんでした。');
        }
    }
}

function isPostRequestTooLarge(): bool
{
    $contentLength = filter_input(INPUT_SERVER, 'CONTENT_LENGTH', FILTER_VALIDATE_INT);
    if (!is_int($contentLength) || $contentLength <= 0 || $_POST !== [] || $_FILES !== []) {
        return false;
    }

    $limit = Tomos\PostUploadCapabilities::iniBytes((string) ini_get('post_max_size'));
    return $limit > 0 && $contentLength > $limit;
}

renderPage('Tomos Post', $config, $errors, $messages, $warnings, $uploadResult, $withdrawTarget, $withdrawResult, $trashResult, $editableSearchResult, $editableQuery, false, $activeSection, $submissionId);

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function authenticatePostRequest(
    Tomos\PostAuthRememberToken $rememberToken,
    array $config,
    string $rootDir,
    string $postPasswordHash,
    array &$warnings
): string {
    if ($rememberToken->restoreSession()) {
        return '';
    }

    $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, clientIp());
    $limit = $rateLimiter->checkAuthAllowed();
    if (!$limit->allowed) {
        return $limit->message;
    }

    if (!Tomos\PostPassword::verify((string) ($_POST['post_password'] ?? ''), $postPasswordHash)) {
        $rateLimiter->recordFailure();
        return '管理用合言葉が正しくありません。';
    }

    $rateLimiter->clearFailures();
    $_SESSION['tomos_post_authenticated'] = true;
    if ((string) ($_POST['remember_post_auth'] ?? '') === '1' && !$rememberToken->rememberCurrentBrowser()) {
        $warnings[] = '認証には成功しましたが、このブラウザに30日間の認証情報を保存できませんでした。';
    }
    return '';
}

function renderAuthenticationFields(string $id, string $ariaLabel = ''): void
{
    if (!empty($_SESSION['tomos_post_authenticated'])) {
        return;
    }

    if ($ariaLabel === '') {
        echo '<label for="' . e($id) . '">管理用合言葉</label>';
    }
    $aria = $ariaLabel !== '' ? ' aria-label="' . e($ariaLabel) . '" placeholder="管理用合言葉"' : '';
    echo '<input id="' . e($id) . '" type="password" name="post_password" autocomplete="current-password"' . $aria . '>';
    echo '<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>';
}

function sendBasicPageDownload(array $config, string $rootDir, string $page): string
{
    $download = Tomos\PostBasicPage::download($config, $rootDir, $page);
    if (!$download->ok) {
        return $download->error;
    }

    $size = @filesize($download->file);
    if ($size === false || !is_readable($download->file)) {
        return '対象ファイルを読み込めません。Tomosのファイル構成を確認してください。';
    }

    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $download->downloadName . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    readfile($download->file);
    exit;
}

function searchEditableMarkdown(array $config, string $rootDir, string $query, int $page): array
{
    try {
        return (new Tomos\PostEditableMarkdown($config, $rootDir))->search($query, $page, 30);
    } catch (Throwable $exception) {
        return [
            'ok' => false,
            'error' => '原稿の検索情報を準備できませんでした。Tomosのファイル構成を確認してください。',
        ];
    }
}

function sendEditableMarkdownDownload(array $config, string $rootDir, string $contentPath): string
{
    try {
        $download = (new Tomos\PostEditableMarkdown($config, $rootDir))->download($contentPath);
    } catch (Throwable $exception) {
        return '編集用Markdownを生成できませんでした。';
    }

    if (empty($download['ok'])) {
        return (string) ($download['error'] ?? '編集用Markdownを生成できませんでした。');
    }

    $content = (string) ($download['content'] ?? '');
    $downloadName = (string) ($download['download_name'] ?? 'download.md');
    $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?? 'download.md';
    if ($fallbackName === '' || $fallbackName === '.md') {
        $fallbackName = 'download.md';
    }

    header('Content-Type: text/markdown; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' . $fallbackName
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
    );
    header('Content-Length: ' . (string) strlen($content));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

function uploadSuccessMessage(Tomos\PostUploadResult $result): string
{
    if ($result->operation === 'editable_draft') {
        return '下書きを保存しました。';
    }
    if ($result->operation === 'editable_publish') {
        return '記事を公開しました。';
    }
    if ($result->operation === 'editable_new') {
        return '新しい記事として投稿しました。元の原稿は変更していません。';
    }
    if ($result->operation === 'editable_update') {
        return '記事を更新しました。';
    }

    $type = Tomos\PostBasicPage::typeFromFileName($result->savedFileName);
    if ($type === Tomos\PostBasicPage::HOME) {
        return 'トップページを更新しました。';
    }
    if ($type === Tomos\PostBasicPage::ABOUT) {
        return 'Aboutページを更新しました。';
    }

    return $result->operation === 'update' ? '記事を更新しました。' : '記事を公開しました。';
}

/** @param array<string,string> $paths */
function stagedImageFiles(array $paths): array
{
    $files = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($paths as $name => $path) {
        $files['name'][] = $name;
        $files['type'][] = '';
        $files['tmp_name'][] = $path;
        $files['error'][] = UPLOAD_ERR_OK;
        $files['size'][] = is_file($path) ? (int) filesize($path) : 0;
    }
    return $files;
}

function hasPostedImages(array $files): bool
{
    $errors = $files['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($errors)) {
        foreach ($errors as $error) {
            if ((int) $error !== UPLOAD_ERR_NO_FILE) return true;
        }
        return false;
    }
    return (int) $errors !== UPLOAD_ERR_NO_FILE;
}

function renderPage(
    string $title,
    array $config,
    array $errors,
    array $messages,
    array $warnings,
    ?Tomos\PostUploadResult $uploadResult,
    ?Tomos\PostContentResolveResult $withdrawTarget,
    ?Tomos\PostWithdrawResult $withdrawResult,
    ?Tomos\TrashClearResult $trashResult,
    ?array $editableSearchResult,
    string $editableQuery,
    bool $disabled,
    string $activeSection,
    string $submissionId
): void {
    header('Content-Type: text/html; charset=utf-8');
    $token = (string) ($_SESSION['tomos_post_token'] ?? '');
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $continueUrl = Tomos\Security::publicUrl('/post/', $publicBasePath);
    $displayUrl = $uploadResult instanceof Tomos\PostUploadResult
        ? ($uploadResult->absoluteUrl !== '' ? $uploadResult->absoluteUrl : Tomos\Security::publicUrl($uploadResult->internalUrl, $publicBasePath))
        : '';
    $trashSummary = trashSummary();

    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>
:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-input:#fff;--tomos-text:#2f2f2f;--tomos-muted:#6b6b6b;--tomos-placeholder:#747470;--tomos-border:#d9d6cf;--tomos-border-soft:#e7e3dc;--tomos-border-hover:#cfcbc3;--tomos-accent:#a44a1d;--tomos-primary:#9a431c;--tomos-primary-hover:#853919;--tomos-primary-active:#713018;--tomos-primary-disabled:#c7b6ad;--tomos-danger:#b4382e;--tomos-danger-hover:#982e27;--tomos-danger-active:#7e261f;--tomos-danger-text:#8a2e26;--tomos-notice-bg:#fbf4e8;--tomos-notice-text:#6f4b1d;--tomos-notice-border:#e5c998;--tomos-error-bg:#f8ecea;--tomos-error-border:#d9a39e;--tomos-info-bg:#f7f7f4;--tomos-code-bg:#f1f1ee;--tomos-code-text:#555;--tomos-button-hover:#f7f5f0;--tomos-button-active:#efece6;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}
html,body{width:100%;overflow-x:hidden}
body{background:var(--tomos-bg);box-sizing:border-box;color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:860px;padding:28px}
h1{color:var(--tomos-text);font-size:1.8rem;margin:0 0 0.5rem}h2{border-top:1px solid var(--tomos-border-soft);color:var(--tomos-text);font-size:1.2rem;margin:2rem 0 1rem;padding-top:1.5rem}h3{color:var(--tomos-text);font-size:1rem;margin:1.4rem 0 0.4rem}
label{color:var(--tomos-text);display:block;font-weight:700;margin:1rem 0 0.35rem}input[type=password],input[type=text],input[type=file]{background:var(--tomos-input);border:1px solid var(--tomos-border);border-radius:6px;box-sizing:border-box;color:var(--tomos-text);font:inherit;font-size:16px;padding:0.65rem;width:100%}input::placeholder{color:var(--tomos-placeholder);opacity:1}input[type=password]:focus,input[type=text]:focus,input[type=file]:focus{border-color:var(--tomos-accent);box-shadow:0 0 0 3px rgba(164,74,29,0.12);outline:none}input[type=file]::file-selector-button{background:var(--tomos-button-hover);border:1px solid var(--tomos-border);border-radius:5px;color:var(--tomos-text);font:inherit;font-weight:700;margin-right:0.75rem;padding:0.45rem 0.75rem}
.hint{color:var(--tomos-muted);font-size:0.95rem}.notice{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.errors{background:var(--tomos-error-bg);border:1px solid var(--tomos-error-border);border-radius:6px;color:var(--tomos-danger-text);padding:1rem}.success{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}
.actions{display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:1.5rem}button,.button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:0.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active,.button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible,.button:focus-visible,.nav a:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}button:disabled,.button[aria-disabled="true"]{background:var(--tomos-primary-disabled);border-color:var(--tomos-primary-disabled);color:#fff}button.danger{background:var(--tomos-danger);border-color:var(--tomos-danger)}button.danger:hover{background:var(--tomos-danger-hover);border-color:var(--tomos-danger-hover)}button.danger:active{background:var(--tomos-danger-active);border-color:var(--tomos-danger-active)}button.danger:focus-visible{outline:3px solid rgba(180,56,46,0.25);outline-offset:2px}button.secondary,.button.secondary{background:var(--tomos-input);color:var(--tomos-text);border-color:var(--tomos-border)}button.secondary:hover,.button.secondary:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}button.secondary:active,.button.secondary:active{background:var(--tomos-button-active)}button.danger.secondary{background:var(--tomos-input);color:var(--tomos-danger-text);border-color:var(--tomos-error-border)}button.danger.secondary:hover{background:var(--tomos-error-bg);border-color:var(--tomos-error-border)}
code{background:var(--tomos-code-bg);border-radius:4px;color:var(--tomos-code-text);padding:0.1rem 0.25rem;overflow-wrap:anywhere;word-break:break-word}.result{background:var(--tomos-info-bg);border:1px solid #e2e1dd;border-radius:6px;color:var(--tomos-text);padding:1rem}.result a{overflow-wrap:anywhere;word-break:break-word}.grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr))}.grid>*{min-width:0}.nav{display:flex;flex-wrap:wrap;gap:0.5rem;margin:1rem 0}.nav a{background:var(--tomos-input);border:1px solid var(--tomos-border);border-radius:999px;color:var(--tomos-text);padding:0.35rem 0.75rem;text-decoration:none}.nav a:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}.meta p{margin:0.35rem 0;min-width:0}
.image-status-list{list-style:none;margin:0.75rem 0;padding:0}.image-status-item{border-top:1px solid var(--tomos-border-soft);padding:0.75rem 0}.image-status-item:first-child{border-top:0}.image-status-line{align-items:center;display:flex;gap:0.6rem;justify-content:space-between}.image-status-ok{color:#2f6131;font-weight:700}.image-status-missing,.image-match-warning{color:var(--tomos-danger-text);font-weight:700}.image-omit-label,.remember-auth{align-items:flex-start;display:flex;font-weight:400;gap:0.5rem;margin:0.65rem 0}.image-omit-label input,.remember-auth input{margin-top:0.35rem}.auth-actions{align-items:center;display:flex;justify-content:flex-end;margin:-0.25rem 0 1rem}.auth-actions form{margin:0}
.nav a[aria-current="page"]{background:var(--tomos-accent);border-color:var(--tomos-accent);color:#fff;font-weight:700}.nav a[aria-current="page"]:hover{background:var(--tomos-accent);border-color:var(--tomos-accent)}.section{margin-top:1.5rem}.basic-page{border:1px solid var(--tomos-border-soft);border-radius:6px;padding:1rem}.basic-page h3{margin-top:0}.inline-form{margin:0}.inline-form input[type=password]{min-width:min(260px,100%)}.result-download{border-top:1px solid var(--tomos-border-soft);margin-top:1.5rem;padding-top:1.5rem}.result-download .inline-form{align-items:center;display:flex;flex-wrap:wrap;gap:0.6rem}.result-download input[type=password]{flex:1 1 260px;width:auto}.result-download button{flex:0 1 auto}.editable-results{display:grid;gap:1rem;margin-top:1rem}.editable-result{border:1px solid var(--tomos-border-soft);border-radius:6px;padding:1rem}.editable-result h3{margin:0.35rem 0}.editable-status{color:var(--tomos-accent);font-weight:700;margin:0}.pager{align-items:center;display:flex;flex-wrap:wrap;gap:0.75rem;justify-content:space-between;margin-top:1rem}.pager p{margin:0}
@media (max-width:560px){body{padding:16px 10px}.wrap{padding:20px 16px}.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.nav a{align-items:center;display:flex;justify-content:center;min-height:44px;padding:0.45rem 0.6rem;text-align:center}.actions button,.actions .button{box-sizing:border-box;min-height:44px;max-width:100%}}
</style></head><body><main class="wrap">';

    echo '<h1>' . e($title) . '</h1>';
    echo '<p class="hint">Tomos Writeなどで作成したMarkdownファイルをTomosに投稿し、必要に応じて投稿済みページをWeb上から外します。</p>';
    renderSectionNav($activeSection, $publicBasePath);
    if (!empty($_SESSION['tomos_post_authenticated'])) {
        echo '<div class="auth-actions"><form method="post" action="">';
        echo '<input type="hidden" name="action" value="logout">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<button class="secondary" type="submit">このブラウザの認証を解除</button>';
        echo '</form></div>';
    }

    if ($disabled) {
        renderMessages($errors, $messages, $warnings);
        echo '</main></body></html>';
        return;
    }

    if (empty($_SESSION['tomos_post_authenticated'])) {
    $securityUrl = Tomos\Security::publicUrl('/post/security/', $publicBasePath);
    echo '<div class="actions">';
    if (passkeyLoginAvailable($config, dirname(__DIR__))) {
        $passkeyUrl = Tomos\Security::publicUrl('/post/passkey/login/', $publicBasePath);
        echo '<a class="button" href="' . e($passkeyUrl) . '">パスキーで開く</a>';
    }
    echo '<a class="button secondary" href="' . e($securityUrl) . '">合言葉を忘れた場合</a>';
    echo '</div>';
}

    echo '<div class="section">';
    renderMessages($errors, $messages, $warnings);
    if ($activeSection === 'manage') {
        renderEditableMarkdownSection($token, $config, $editableQuery, $editableSearchResult);
        renderWithdrawResult($errors, $withdrawResult, $continueUrl);
        renderWithdrawSection($token, $withdrawTarget);
        renderTrashSection($token, $trashSummary);
    } elseif ($activeSection === 'settings') {
        renderSiteSettingsSection($token, $config);
        renderThemeSettingsSection($token, $config);
        renderAnalyticsSettingsSection($token, $config);
        renderUpdateSettingsSection($config);
    } else {
        renderUploadResult($errors, $uploadResult, $displayUrl, $continueUrl, $token);
        renderUploadConflict($uploadResult, $token, $displayUrl, $submissionId);
        renderUploadForm($token, $config, $submissionId);
    }
    echo '</div>';

    echo <<<'HTML'
<script>
document.querySelectorAll("form[data-submission-form]").forEach((form) => {
  form.addEventListener("submit", () => {
    const button = form.querySelector('button[type="submit"]');
    if (!button || button.disabled) return;
    button.disabled = true;
    button.textContent = "投稿中…";
  });
});
</script>
HTML;
    echo '</main></body></html>';
}

function renderSectionNav(string $activeSection, string $publicBasePath): void
{
    $items = [
        'upload' => '投稿',
        'manage' => '記事管理',
        'settings' => 'サイト設定',
    ];

    echo '<nav class="nav" aria-label="Tomos Postの操作">';
    foreach ($items as $section => $label) {
        $url = Tomos\Security::publicUrl('/post/?section=' . $section, $publicBasePath);
        $current = $section === $activeSection ? ' aria-current="page"' : '';
        echo '<a href="' . e($url) . '"' . $current . '>' . e($label) . '</a>';
    }
    $securityUrl = Tomos\Security::publicUrl('/post/security/', $publicBasePath);
echo '<a href="' . e($securityUrl) . '">セキュリティ</a>';
echo '</nav>';
}

function passkeyLoginAvailable(array $config, string $rootDir): bool
{
    if (PHP_VERSION_ID < 80000 || $config === []) {
        return false;
    }

    $vendor = rtrim($rootDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'core'
        . DIRECTORY_SEPARATOR . 'webauthn'
        . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($vendor)) {
        return false;
    }
    require_once $vendor;

    try {
        $environment = new Tomos\PasskeyEnvironment($config);
        if (!$environment->isAvailable()) {
            return false;
        }

        $rpId = $environment->rpId();
        if ($rpId === '') {
            return false;
        }

        $store = new Tomos\PasskeyCredentialStore($config, $rootDir);
        foreach ($store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') === $rpId) {
                return true;
            }
        }
    } catch (Throwable $exception) {
        return false;
    }

    return false;
}

function renderUpdateSettingsSection(array $config): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $updateUrl = Tomos\Security::publicUrl('/update/', $publicBasePath);
    echo '<h2 id="tomos-update">Tomos本体の更新</h2>';
    echo '<p class="hint">署名済みの更新ZIPを使い、設定・記事・画像を変えずにTomos本体を更新します。</p>';
    echo '<p><a class="button secondary" href="' . e($updateUrl) . '">Tomos Updateを開く</a></p>';
}

function renderMessages(array $errors, array $messages, array $warnings): void
{
    if ($errors !== []) {
        echo '<div class="errors"><strong>処理できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }

    if ($errors === [] && $messages !== []) {
        echo '<div class="success"><ul>';
        foreach ($messages as $message) {
            echo '<li>' . e((string) $message) . '</li>';
        }
        echo '</ul></div>';
    }

    if ($errors === [] && $warnings !== []) {
        echo '<div class="notice"><strong>注意</strong><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . e((string) $warning) . '</li>';
        }
        echo '</ul></div>';
    }
}

function renderUploadResult(array $errors, ?Tomos\PostUploadResult $result, string $displayUrl, string $continueUrl, string $token): void
{
    if ($errors !== [] || !($result instanceof Tomos\PostUploadResult) || !$result->ok) {
        return;
    }

    echo '<div class="result">';
    if ($result->originalFileName !== '' && $result->savedFileName !== '' && $result->originalFileName !== $result->savedFileName) {
        echo '<div class="notice">';
        echo '<p>ファイル名に使用できない文字が含まれていたため、安全な名前に変更しました。</p>';
        echo '<p><strong>元のファイル名:</strong><br><code>' . e($result->originalFileName) . '</code></p>';
        echo '<p><strong>保存されたファイル名:</strong><br><code>' . e($result->savedFileName) . '</code></p>';
        echo '</div>';
    }
    $basicType = Tomos\PostBasicPage::isProtectedContentPath($result->contentPath)
        ? Tomos\PostBasicPage::typeFromFileName($result->contentPath)
        : '';
    if ($basicType === '') {
        echo '<p><strong>保存先:</strong><br><code>content/' . e($result->contentPath) . '</code></p>';
    }
    if ($result->imageCount > 0) {
        echo '<p><strong>画像:</strong><br>' . e((string) $result->imageCount) . '点を保存しました。</p>';
    }
    $isDraftSave = $result->operation === 'editable_draft';
    if (!$isDraftSave) {
        echo '<p><strong>公開URL:</strong><br><a href="' . e($displayUrl) . '">' . e($displayUrl) . '</a></p>';
    }
    echo '<div class="actions">';
    if (!$isDraftSave) {
        echo '<a class="button" href="' . e($displayUrl) . '">公開ページを確認</a>';
    }
    echo '<a class="button secondary" href="' . e($continueUrl) . '">続けて投稿する</a></div>';
    if ($basicType !== '') {
        echo '<div class="result-download">';
        renderBasicPageDownloadButton($token, $basicType, Tomos\PostBasicPage::canonicalContentPath($basicType) . 'の最新版をダウンロード', true);
        echo '</div>';
    }
    echo '</div>';
}

function renderUploadConflict(?Tomos\PostUploadResult $result, string $token, string $displayUrl, string $submissionId): void
{
    if (!($result instanceof Tomos\PostUploadResult) || !$result->conflict || $result->tempId === '') {
        return;
    }
    if (strpos($result->operation, 'editable_') === 0) {
        renderEditableUploadConfirmation($result, $token, $displayUrl, $submissionId);
        return;
    }

    $suggested = $result->suggestedFileName !== '' ? $result->suggestedFileName : suggestFileName($result->savedFileName);
    $basicType = Tomos\PostBasicPage::typeFromFileName($result->savedFileName);
    $isBasicPage = $basicType !== '';

    echo '<div class="result meta" id="post-upload-conflict">';
    echo '<h2>' . ($isBasicPage ? e(Tomos\PostBasicPage::label($basicType)) . 'を上書きします' : '同じ保存先に、すでにページがあります') . '</h2>';
    echo '<p>' . ($isBasicPage ? '現在のページを同じURLのまま更新します。よろしいですか。' : '現在のページを同じURLのまま更新するか、ファイル名を変更して新しいページとして投稿できます。') . '</p>';
    echo '<div class="grid">';
    echo '<div>';
    echo '<h3>現在のページ</h3>';
    echo '<p><strong>タイトル:</strong><br>' . e($result->existingTitle !== '' ? $result->existingTitle : '（タイトルなし）') . '</p>';
    if (!$isBasicPage) {
        echo '<p><strong>保存先:</strong><br><code>content/' . e($result->contentPath) . '</code></p>';
    }
    echo '<p><strong>公開URL:</strong><br><a href="' . e($displayUrl) . '" target="_blank" rel="noopener noreferrer">' . e($displayUrl) . '</a></p>';
    echo '</div>';
    echo '<div>';
    echo '<h3>今回投稿する内容</h3>';
    echo '<p><strong>タイトル:</strong><br>' . e($result->newTitle !== '' ? $result->newTitle : '（タイトルなし）') . '</p>';
    echo '<p><strong>投稿予定ファイル名:</strong><br><code>' . e($result->savedFileName) . '</code></p>';
    if ($result->imageCount > 0) {
        echo '<p><strong>画像:</strong><br>' . e((string) $result->imageCount) . '点</p>';
    }
    echo '<p><strong>一時保存の有効期限:</strong><br>' . e($result->expiresAt !== '' ? $result->expiresAt : '30分') . '</p>';
    echo '</div>';
    echo '</div>';

    echo '<form method="post" action="" data-submission-form>';
    echo '<input type="hidden" name="action" value="update_upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
    echo '<input type="hidden" name="submission_id" value="' . e($submissionId) . '">';
    renderAuthenticationFields('update_password');
    echo '<p class="hint">現在の公開ページを、同じURLのまま新しい内容に更新します。確認画面表示後に対象ファイルが変更されていた場合は中止します。</p>';
    echo '<div class="actions"><button type="submit">' . ($isBasicPage ? e(Tomos\PostBasicPage::label($basicType)) . 'を更新する' : 'このページを更新する') . '</button></div>';
    echo '</form>';

    if (!$isBasicPage) {
        echo '<form method="post" action="" data-submission-form>';
    echo '<input type="hidden" name="action" value="rename_upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
    echo '<input type="hidden" name="submission_id" value="' . e($submissionId) . '">';
    echo '<label for="new_file_name">新しいファイル名</label>';
    echo '<input id="new_file_name" type="text" name="new_file_name" value="' . e($suggested) . '" data-current-url="' . e($displayUrl) . '">';
    echo '<p class="hint">現在のページを残し、別のURLで新しいページとして公開します。</p>';
    echo '<p><strong>新しい公開URL:</strong><br><a id="new-public-url" href="' . e($displayUrl) . '">' . e($displayUrl) . '</a></p>';
    renderAuthenticationFields('rename_password');
    echo '<div class="actions"><button type="submit">ファイル名を変更して新しいページとして投稿する</button></div>';
        echo '</form>';
    }

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="cancel_upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
    echo '<p class="hint">何も変更せず、投稿画面へ戻ります。</p>';
    echo '<div class="actions"><button class="danger secondary" type="submit">投稿をやめる</button></div>';
    echo '</form>';
    echo '</div>';

    echo <<<'HTML'
<script>
(() => {
  const input = document.getElementById("new_file_name");
  const output = document.getElementById("new-public-url");
  if (!input || !output) return;

  const updateUrl = () => {
    const currentUrl = input.getAttribute("data-current-url") || "";
    const fileName = input.value.trim().replace(/\.(md|markdown|txt)$/i, "");
    if (currentUrl === "" || fileName === "") {
      output.textContent = "";
      output.removeAttribute("href");
      return;
    }

    try {
      const url = new URL(currentUrl, window.location.href);
      const parts = url.pathname.replace(/\/$/, "").split("/");
      parts[parts.length - 1] = encodeURIComponent(fileName);
      url.pathname = parts.join("/") || "/";
      output.href = url.href;
      output.textContent = url.href;
    } catch (error) {
      output.textContent = currentUrl;
      output.href = currentUrl;
    }
  };

  input.addEventListener("input", updateUrl);
  updateUrl();
})();
</script>
HTML;
}

function renderEditableUploadConfirmation(Tomos\PostUploadResult $result, string $token, string $displayUrl, string $submissionId): void
{
    $isFixedPage = Tomos\PostBasicPage::isProtectedContentPath($result->sourcePath);
    echo '<div class="result meta" id="post-upload-conflict">';

    if ($result->destinationChanged) {
        echo '<h2>保存先が変更されています</h2>';
        echo '<p>元の原稿は残したまま、新しい原稿として投稿します。</p>';
        echo '<p><strong>元の保存先:</strong><br><code>content/' . e($result->sourcePath) . '</code></p>';
        echo '<p><strong>新しい保存先:</strong><br><code>content/' . e($result->contentPath) . '</code></p>';
        echo '<p><strong>新しい公開URL:</strong><br><a href="' . e($displayUrl) . '" target="_blank" rel="noopener noreferrer">' . e($displayUrl) . '</a></p>';
        if ($result->hasRelativeImages) {
            echo '<div class="notice"><p>保存先を変更すると、既存画像の相対パスが参照できなくなる場合があります。元の原稿と画像は変更されません。</p></div>';
        }
        echo '<form method="post" action="" data-submission-form>';
        echo '<input type="hidden" name="action" value="create_editable_upload">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
        echo '<input type="hidden" name="submission_id" value="' . e($submissionId) . '">';
        renderAuthenticationFields('editable_new_password');
        echo '<div class="actions"><button type="submit">新しい記事として投稿する</button></div>';
        echo '</form>';
        renderEditableCancelForm($result, $token);
        echo '</div>';
        return;
    }

    if ($result->sourceConflict) {
        echo '<h2>サーバー上の原稿が変更されています</h2>';
        echo '<div class="notice"><p>この原稿は、ダウンロード後にサーバー上で変更されています。</p>';
        echo '<p>このまま更新すると、現在の内容を上書きします。記事管理から最新版をダウンロードし直すことをおすすめします。</p></div>';
    } else {
        echo '<h2>' . ($isFixedPage ? '固定ページを更新します' : '編集した原稿を更新します') . '</h2>';
        echo '<p>ダウンロード後にサーバー上の原稿が変更されていないことを確認しました。</p>';
    }

    echo '<p><strong>保存先:</strong><br><code>content/' . e($result->contentPath) . '</code></p>';
    echo '<p><strong>公開URL:</strong><br><a href="' . e($displayUrl) . '" target="_blank" rel="noopener noreferrer">' . e($displayUrl) . '</a></p>';
    echo '<p><strong>一時保存の有効期限:</strong><br>' . e($result->expiresAt !== '' ? $result->expiresAt : '30分') . '</p>';

    echo '<form method="post" action="" data-submission-form>';
    echo '<input type="hidden" name="action" value="resolve_editable_upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
    echo '<input type="hidden" name="submission_id" value="' . e($submissionId) . '">';
    if ($result->sourceConflict) {
        echo '<fieldset><legend>競合時の操作</legend>';
        echo '<label><input type="radio" name="conflict_action" value="cancel" checked> 更新を中止する</label>';
        echo '<label><input type="radio" name="conflict_action" value="overwrite"> 現在の内容で上書きする</label>';
        echo '</fieldset>';
    } else {
        echo '<input type="hidden" name="conflict_action" value="proceed">';
    }

    if ($result->sourceStatus === 'draft') {
        echo '<fieldset><legend>下書きの操作</legend>';
        echo '<label><input type="radio" name="editable_mode" value="draft" checked> 下書きのまま保存</label>';
        echo '<label><input type="radio" name="editable_mode" value="publish"> 公開する</label>';
        echo '</fieldset>';
    } else {
        echo '<input type="hidden" name="editable_mode" value="published">';
    }
    renderAuthenticationFields('editable_update_password');
    $buttonLabel = $result->sourceConflict
        ? '選択した操作を実行する'
        : ($result->sourceStatus === 'draft'
            ? '選択した方法で保存する'
            : ($isFixedPage ? '固定ページを更新する' : '公開中の記事を更新する'));
    echo '<div class="actions"><button type="submit">' . e($buttonLabel) . '</button></div>';
    echo '</form>';
    renderEditableCancelForm($result, $token);
    echo '</div>';
}

function renderEditableCancelForm(Tomos\PostUploadResult $result, string $token): void
{
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="cancel_upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="temp_id" value="' . e($result->tempId) . '">';
    echo '<p class="hint">何も変更せず、投稿画面へ戻ります。</p>';
    echo '<div class="actions"><button class="danger secondary" type="submit">投稿をやめる</button></div>';
    echo '</form>';
}

function renderWithdrawResult(array $errors, ?Tomos\PostWithdrawResult $result, string $continueUrl): void
{
    if ($errors !== [] || !($result instanceof Tomos\PostWithdrawResult) || !$result->ok) {
        return;
    }

    echo '<div class="result">';
    echo '<p>Markdownファイルは完全削除せず、取り下げ済みとして保管しました。</p>';
    echo '<p><strong>取り下げたページ:</strong><br><code>' . e($result->fromPath) . '</code></p>';
    echo '<p>一覧・検索・タグへの反映のため、インデックスキャッシュを更新対象にしました。</p>';
    echo '<p><a class="button" href="' . e($continueUrl) . '">Tomos Postに戻る</a></p>';
    echo '</div>';
}

function renderUploadForm(string $token, array $config, string $submissionId): void
{
    echo '<h2 id="post-upload">1. Markdownを投稿する</h2>';
    echo '<p class="hint">通常記事、index.md、about.mdを同じフォームから投稿できます。</p>';
    echo '<form id="post-upload-form" method="post" action="" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="upload">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="submission_id" value="' . e($submissionId) . '">';
    renderAuthenticationFields('post_password');
    echo '<label for="markdown_file">投稿するファイル</label>';
    echo '<input id="markdown_file" type="file" name="markdown_file" accept=".md,.markdown,.txt,text/markdown,text/plain">';
    echo '<p id="page-type-notice" class="hint" role="status" aria-live="polite">ファイルを選択すると投稿対象を表示します。</p>';
    echo '<label for="image_files">画像を選ぶ</label>';
    echo '<input id="image_files" type="file" name="image_files[]" accept="image/*,.jpg,.jpeg,.png,.webp,.gif" multiple>';
    echo '<p class="hint">Markdownに画像がある場合は、Tomos Writeで使った元画像を選んでください。画像内容から自動で照合します。</p>';
    echo '<div id="image-frontmatter-notice" class="hint" hidden></div>';
    echo '<div id="image-match-status" class="result" hidden></div>';
    echo '<p id="image-processing-status" class="hint" role="status" aria-live="polite" hidden></p>';
    echo '<label for="folder">保存先フォルダ</label>';
    echo '<input id="folder" type="text" name="folder" placeholder="例: diary">';
    echo '<p class="hint">空欄の場合は <code>content/</code> 直下に保存します。存在しないフォルダは新しく作成します。Markdownに保存先情報がある場合は自動で反映します。</p>';
    echo '<p id="folder-frontmatter-notice" class="hint" hidden></p>';
    echo '<div class="notice"><p>投稿できるファイルは <code>.md</code> / <code>.markdown</code> / <code>.txt</code> です。同じ保存先にページがある場合は、更新するか、別のファイル名で新しいページとして投稿するかを選べます。</p></div>';
    echo '<div class="actions"><button id="post-upload-submit" type="submit">公開する</button></div>';
    echo '</form>';
    renderBasicPagesSection($token, $config);
    echo <<<'HTML'
<script>
(() => {
  const fileInput = document.getElementById("markdown_file");
  const folderInput = document.getElementById("folder");
  const notice = document.getElementById("folder-frontmatter-notice");
  const imageNotice = document.getElementById("image-frontmatter-notice");
  const imageMatchStatus = document.getElementById("image-match-status");
  const imageInput = document.getElementById("image_files");
  const form = document.getElementById("post-upload-form");
  const submitButton = document.getElementById("post-upload-submit");
  const processingStatus = document.getElementById("image-processing-status");
  const pageTypeNotice = document.getElementById("page-type-notice");
  if (!fileInput || !folderInput || !notice || !imageNotice || !imageMatchStatus || !imageInput || !form || !submitButton || !processingStatus || !pageTypeNotice || typeof FileReader === "undefined") return;

  const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
  let effectiveImageMaxBytes = MAX_IMAGE_BYTES;
  let requiredImages = [];
  const selectedImages = new Map();
  const supportsFileTransfer = (() => {
    if (typeof DataTransfer === "undefined") return false;
    try {
      return new DataTransfer() instanceof DataTransfer;
    } catch (error) {
      return false;
    }
  })();
  let unmatchedImageCount = 0;
  let oversizedImageCount = 0;
  let formatMismatchImageCount = 0;
  let editableReupload = false;
  let editableSourcePath = "";
  let imageSelectionTask = Promise.resolve();
  let submitting = false;
  let activeUploadSessionId = "";
  let finalizingUpload = false;
  let capabilitiesError = "";
  const capabilitiesTask = fetch("?post_api=capabilities", { credentials: "same-origin", cache: "no-store" })
    .then(async (response) => {
      let payload = null;
      try {
        payload = await response.json();
      } catch (error) {
        payload = null;
      }
      if (!response.ok || !payload || !payload.ok) {
        throw new Error(payload && payload.message ? payload.message : "Tomosの投稿機能を確認できませんでした。");
      }
      return payload;
    })
    .then((payload) => {
      const value = Number(payload && payload.capabilities && payload.capabilities.effective_image_max_bytes);
      if (Number.isFinite(value) && value > 0) effectiveImageMaxBytes = Math.min(MAX_IMAGE_BYTES, value);
    })
    .catch((error) => {
      capabilitiesError = error && error.message ? error.message : "Tomosの投稿機能を確認できませんでした。";
    });

  const setNotice = (message, isWarning = false) => {
    notice.hidden = message === "";
    notice.textContent = message;
    notice.style.color = isWarning ? "var(--tomos-danger-text)" : "";
  };

  const selectedPageType = () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return "";
    if (file.name === "index.md" && (!editableReupload || editableSourcePath === "index.md")) return "home";
    if (file.name === "about.md" && (!editableReupload || editableSourcePath === "about.md")) return "about";
    return "article";
  };

  const updatePageSelection = () => {
    const type = selectedPageType();
    const imageCount = imageInput.files ? imageInput.files.length : 0;
    const imageText = imageCount > 0 ? ` 画像${imageCount}点を同時にアップロードします。` : "";
    folderInput.disabled = type === "home" || type === "about";
    if (type === "home") {
      pageTypeNotice.textContent = editableReupload
        ? `編集したトップページの更新内容を確認します。${imageText}`
        : `トップページを更新します。現在のindex.mdは上書きされます。${imageText}`;
      setNotice("トップページはサイト直下へ保存されます。保存先フォルダの指定は不要です。");
      submitButton.textContent = editableReupload ? "更新内容を確認する" : "トップページを更新する";
    } else if (type === "about") {
      pageTypeNotice.textContent = editableReupload
        ? `編集したAboutページの更新内容を確認します。${imageText}`
        : `Aboutページを更新します。現在のabout.mdは上書きされます。${imageText}`;
      setNotice("Aboutページはサイト直下へ保存されます。保存先フォルダの指定は不要です。");
      submitButton.textContent = editableReupload ? "更新内容を確認する" : "Aboutページを更新する";
    } else if (type === "article") {
      pageTypeNotice.textContent = editableReupload
        ? `編集済み原稿の保存先と競合状態を確認します。${imageText}`
        : `記事として投稿します。${imageText}`;
      submitButton.textContent = editableReupload ? "更新内容を確認する" : "公開する";
    } else {
      pageTypeNotice.textContent = "ファイルを選択すると投稿対象を表示します。";
      submitButton.textContent = "公開する";
    }
  };

  const normalizeFolder = (value) => value
    .trim()
    .replace(/\\/g, "/")
    .replace(/\/+/g, "/")
    .replace(/^\/+/, "")
    .replace(/\/+$/, "");

  const unquoteScalar = (value) => {
    const trimmed = value.trim();
    if (
      (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
      (trimmed.startsWith("'") && trimmed.endsWith("'"))
    ) {
      return trimmed.slice(1, -1);
    }
    return trimmed;
  };

  const isUnsafeFolder = (value) => {
    const trimmed = value.trim();
    const normalized = normalizeFolder(value);
    return /[\x00-\x1F\x7F]/.test(trimmed)
      || trimmed.startsWith("/")
      || /^[A-Za-z][A-Za-z0-9+.-]*:\/\//.test(trimmed)
      || /^[A-Za-z]:/.test(trimmed)
      || /(^|\/)\.\.?($|\/)/.test(normalized);
  };

  const extractFolder = (markdown) => {
    const lines = markdown.replace(/\r\n?/g, "\n").split("\n");
    if ((lines[0] || "").trim() !== "---") return null;
    for (let index = 1; index < lines.length; index += 1) {
      const line = lines[index] || "";
      if (line.trim() === "---") return null;
      if (line.startsWith("folder:")) return unquoteScalar(line.slice(7));
    }
    return null;
  };

  const extractSourceMetadata = (markdown) => {
    const keys = [
      "tomos_asset_base_url",
      "tomos_source_path",
      "tomos_source_hash",
      "tomos_source_status",
    ];
    const values = {};
    const lines = markdown.replace(/\r\n?/g, "\n").split("\n");
    if ((lines[0] || "").trim() !== "---") return { count: 0, complete: false, values };

    for (let index = 1; index < lines.length; index += 1) {
      const line = lines[index] || "";
      if (line.trim() === "---") break;
      const match = line.match(/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/);
      if (!match || !keys.includes(match[1])) continue;
      values[match[1]] = unquoteScalar(match[2]);
    }

    const count = keys.filter((key) => Object.prototype.hasOwnProperty.call(values, key)).length;
    return {
      count,
      complete: count === keys.length && keys.every((key) => String(values[key] || "").trim() !== ""),
      values,
    };
  };

  const escapeHtml = (value) => value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

  const extractImages = (markdown) => {
    const images = [];
    const pattern = /!\[([^\]\n]*)\]\(images\/(tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))\)/giu;
    let match;
    while ((match = pattern.exec(markdown)) !== null) {
      const fileName = match[2].toLowerCase();
      if (!images.some((image) => image.fileName === fileName)) {
        images.push({ label: match[1] || "画像", fileName });
      }
    }
    return images;
  };

  const omittedImageNames = () => new Set(
    Array.from(imageMatchStatus.querySelectorAll('input[name="omit_images[]"]:checked'))
      .map((input) => input.value.toLowerCase())
  );

  const renderImageMatches = (preservedOmissions = omittedImageNames()) => {
    if (requiredImages.length === 0) {
      imageMatchStatus.hidden = true;
      imageMatchStatus.innerHTML = "";
      return;
    }

    const rows = requiredImages.map((image) => {
      const selected = selectedImages.has(image.fileName);
      const selectedFile = selected ? selectedImages.get(image.fileName) : null;
      const omitted = preservedOmissions.has(image.fileName);
      const status = selected
        ? '<span class="image-status-ok">選択済み</span>'
        : editableReupload
          ? '<span>既存画像は選択不要</span>'
        : omitted
          ? '<span>掲載しません</span>'
          : '<span class="image-status-missing">画像が必要です</span>';
      const omission = selected || editableReupload ? "" : [
        '<label class="image-omit-label">',
        `<input type="checkbox" name="omit_images[]" value="${escapeHtml(image.fileName)}"${omitted ? " checked" : ""}>`,
        '<span>この画像の掲載をやめる<br><small>投稿用のMarkdownだけから画像記述を外します。</small></span>',
        '</label>',
      ].join("");
      const fileNames = selectedFile
        ? `<small>元画像: <code>${escapeHtml(selectedFile.name)}</code><br>公開用: <code>${escapeHtml(image.fileName)}</code></small>`
        : `<small>公開用: <code>${escapeHtml(image.fileName)}</code></small>`;
      return [
        '<li class="image-status-item">',
        `<div class="image-status-line"><span><strong>${escapeHtml(image.label)}</strong><br>${fileNames}</span>${status}</div>`,
        omission,
        '</li>',
      ].join("");
    });

    const unmatched = unmatchedImageCount > 0
      ? `<p class="image-match-warning">Markdownと一致しない画像が${unmatchedImageCount}点ありました。</p>`
      : "";
    const oversized = oversizedImageCount > 0
      ? `<p class="image-match-warning">現在の公開先で扱える容量を超える画像が${oversizedImageCount}点ありました。別の画像を選んでください。</p>`
      : "";
    const formatMismatch = formatMismatchImageCount > 0
      ? `<p class="image-match-warning">拡張子と画像データの形式が一致しない画像が${formatMismatchImageCount}点ありました。元画像を確認してください。</p>`
      : "";
    imageMatchStatus.hidden = false;
    imageMatchStatus.innerHTML = [
      '<strong>投稿する画像</strong>',
      '<ul class="image-status-list">',
      ...rows,
      '</ul>',
      oversized,
      formatMismatch,
      unmatched,
      editableReupload
        ? '<p class="hint">既存画像は選択不要です。Tomos Writeで新しく追加した画像だけを選んでください。</p>'
        : '<p class="hint">不足している画像を追加で選ぶか、掲載をやめる画像を指定してください。</p>',
    ].join("");
    imageMatchStatus.querySelectorAll('input[name="omit_images[]"]').forEach((input) => {
      input.addEventListener("change", () => renderImageMatches(omittedImageNames()));
    });
  };

  fileInput.addEventListener("change", () => {
    setNotice("");
    folderInput.disabled = false;
    editableReupload = false;
    editableSourcePath = "";
    updatePageSelection();
    imageNotice.hidden = true;
    imageNotice.textContent = "";
    requiredImages = [];
    selectedImages.clear();
    unmatchedImageCount = 0;
    oversizedImageCount = 0;
    formatMismatchImageCount = 0;
    imageInput.value = "";
    renderImageMatches(new Set());
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.addEventListener("load", () => {
      const markdown = String(reader.result || "");
      const images = extractImages(markdown);
      requiredImages = images;
      if (images.length > 0) {
        imageNotice.hidden = false;
        imageNotice.textContent = `このMarkdownには画像が${images.length}点あります。Tomos Writeで使った元画像を選んでください。`;
        renderImageMatches(new Set());
      }

      const sourceMetadata = extractSourceMetadata(markdown);
      if (sourceMetadata.count > 0) {
        if (!sourceMetadata.complete) {
          setNotice("編集元の情報が不足しています。Tomos Postから原稿をもう一度ダウンロードしてください。", true);
          return;
        }

        editableReupload = true;
        editableSourcePath = String(sourceMetadata.values.tomos_source_path || "").replace(/\\/g, "/");
        if (images.length > 0) {
          imageNotice.hidden = false;
          imageNotice.textContent = `既存画像は選択不要です。Tomos Writeで新しく追加した画像だけを選んでください。`;
          renderImageMatches(new Set());
        }
        updatePageSelection();

        if (selectedPageType() !== "home" && selectedPageType() !== "about") {
          const sourcePath = editableSourcePath;
          const slash = sourcePath.lastIndexOf("/");
          const sourceFolder = slash >= 0 ? sourcePath.slice(0, slash) : "";
          if (isUnsafeFolder(sourceFolder)) {
            setNotice("編集元の保存先情報を使用できません。記事管理から原稿をもう一度ダウンロードしてください。", true);
            return;
          }
          folderInput.value = normalizeFolder(sourceFolder);
          setNotice("編集元の保存先を反映しました。変更すると、元の原稿を残して新しい記事として投稿します。");
        }
        return;
      }

      if (selectedPageType() === "home" || selectedPageType() === "about") return;
      const folder = extractFolder(markdown);
      if (folder === null || folder.trim() === "") return;
      if (isUnsafeFolder(folder)) {
        setNotice("Markdown内の保存先情報を使用できません。保存先フォルダを確認してください。", true);
        return;
      }
      folderInput.value = normalizeFolder(folder);
      setNotice("Markdown内の保存先情報を反映しました。必要に応じて変更できます。");
    });
    reader.readAsText(file);
  });

  const extensionForFile = (file) => {
    const match = file.name.toLowerCase().match(/\.([a-z0-9]+)$/);
    const extension = match ? match[1] : "";
    return extension === "jpeg" ? "jpg" : extension;
  };

  const fileTypeMatchesExtension = (file) => {
    if (!file.type) return true;
    const extension = extensionForFile(file);
    const expectedTypes = {
      jpg: "image/jpeg",
      png: "image/png",
      gif: "image/gif",
      webp: "image/webp",
    };
    return !expectedTypes[extension] || expectedTypes[extension] === file.type.toLowerCase();
  };

  const sha256Prefix = async (file) => {
    if (!window.crypto || !window.crypto.subtle) return "";
    const digest = await window.crypto.subtle.digest("SHA-256", await file.arrayBuffer());
    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("").slice(0, 16);
  };

  const managedNameForFile = async (file) => {
    const extension = extensionForFile(file);
    if (!["jpg", "png", "webp", "gif"].includes(extension)) return "";
    const prefix = await sha256Prefix(file);
    if (prefix === "") return "";
    const candidate = `tms-${prefix}.${extension}`;
    const expected = requiredImages.find((image) => {
      const normalized = image.fileName.toLowerCase().replace(/\.jpeg$/, ".jpg");
      return normalized === candidate;
    });
    return expected ? expected.fileName.toLowerCase() : candidate;
  };

  const syncSelectedInput = () => {
    if (!supportsFileTransfer) return;
    const transfer = new DataTransfer();
    selectedImages.forEach((file) => transfer.items.add(file));
    imageInput.files = transfer.files;
  };

  imageInput.addEventListener("change", () => {
    updatePageSelection();
    const addedFiles = Array.from(imageInput.files || []);
    imageSelectionTask = (async () => {
      await capabilitiesTask;
      processingStatus.hidden = false;
      processingStatus.textContent = "選択した画像を確認しています。";
      let unmatched = 0;
      let oversized = 0;
      let formatMismatch = 0;
      if (!supportsFileTransfer) {
        selectedImages.clear();
        unmatchedImageCount = 0;
      }
      for (const file of addedFiles) {
        if (file.size > MAX_IMAGE_BYTES) {
          oversized += 1;
          continue;
        }
        if (!fileTypeMatchesExtension(file)) {
          formatMismatch += 1;
          continue;
        }
        try {
          const managedName = await managedNameForFile(file);
          if (managedName !== "" && requiredImages.some((image) => image.fileName === managedName)) {
            selectedImages.set(managedName, file);
          } else {
            unmatched += 1;
          }
        } catch (error) {
          unmatched += 1;
        }
      }
      unmatchedImageCount = unmatched;
      oversizedImageCount = oversized;
      formatMismatchImageCount = formatMismatch;
      syncSelectedInput();
      renderImageMatches();
      processingStatus.textContent = formatMismatch > 0
        ? `拡張子と画像データの形式が一致しない画像が${formatMismatch}点あります。元画像を確認してください。`
        : oversized > 0
        ? `現在の公開先で扱える容量を超える画像が${oversized}点あります。別の画像を選んでください。`
        : "画像の照合が完了しました。";
      processingStatus.style.color = oversized > 0 || formatMismatch > 0 ? "var(--tomos-danger-text)" : "";
    })();
  });

  const apiRequest = async (endpoint, data) => {
    const response = await fetch(`?post_api=${endpoint}`, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      cache: "no-store",
    });
    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }
    if (!response.ok || !payload || !payload.ok) {
      const failure = new Error(payload && payload.message ? payload.message : "画像の送信を完了できませんでした。通信状態を確認して、もう一度お試しください。");
      failure.capacity = Boolean(payload && payload.capacity);
      throw failure;
    }
    return payload;
  };

  const appendCommonApiFields = (data) => {
    const tokenInput = form.querySelector('input[name="_token"]');
    const submissionInput = form.querySelector('input[name="submission_id"]');
    data.append("_token", tokenInput ? tokenInput.value : "");
    data.append("submission_id", submissionInput ? submissionInput.value : "");
  };

  form.addEventListener("submit", async (event) => {
    if (submitting) {
      event.preventDefault();
      processingStatus.hidden = false;
      processingStatus.textContent = "投稿処理中です。完了するまでそのままお待ちください。";
      return;
    }

    event.preventDefault();
    await imageSelectionTask;

    const omitted = omittedImageNames();
    const missing = editableReupload
      ? []
      : requiredImages.filter((image) => !selectedImages.has(image.fileName) && !omitted.has(image.fileName));
    if (missing.length > 0) {
      processingStatus.hidden = false;
      processingStatus.textContent = `画像が${missing.length}点不足しています。画像を追加で選ぶか、掲載をやめる画像を指定してください。`;
      processingStatus.style.color = "var(--tomos-danger-text)";
      renderImageMatches(omitted);
      imageMatchStatus.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }

    const files = Array.from(selectedImages.entries()).filter(([imageName]) => !omitted.has(imageName));
    if (files.length === 0) {
      submitting = true;
      submitButton.disabled = true;
      submitButton.textContent = "投稿中…";
      form.submit();
      return;
    }
    if (capabilitiesError !== "") {
      processingStatus.hidden = false;
      processingStatus.style.color = "var(--tomos-danger-text)";
      processingStatus.textContent = capabilitiesError;
      return;
    }

    submitting = true;
    submitButton.disabled = true;
    submitButton.textContent = "投稿中…";
    processingStatus.hidden = false;
    processingStatus.style.color = "";
    processingStatus.textContent = "投稿の準備中です。";

    let uploadSessionId = "";
    try {
      const startData = new FormData();
      appendCommonApiFields(startData);
      const passwordInput = form.querySelector('input[name="post_password"]');
      const rememberInput = form.querySelector('input[name="remember_post_auth"]');
      startData.append("post_password", passwordInput ? passwordInput.value : "");
      startData.append("remember_post_auth", rememberInput && rememberInput.checked ? "1" : "");
      startData.append("expected_images", JSON.stringify(files.map(([imageName]) => imageName)));
      const started = await apiRequest("start", startData);
      uploadSessionId = started.upload_session_id;
      activeUploadSessionId = uploadSessionId;
      const serverMax = Number(started.capabilities && started.capabilities.effective_image_max_bytes);
      const chunkSize = Number.isFinite(serverMax) && serverMax > 131072
        ? Math.min(MAX_IMAGE_BYTES, serverMax - 65536)
        : 1048576;

      for (let index = 0; index < files.length; index += 1) {
        const [imageName, file] = files[index];
        const chunkCount = Math.max(1, Math.ceil(file.size / chunkSize));
        for (let chunkIndex = 0; chunkIndex < chunkCount; chunkIndex += 1) {
          const chunk = file.slice(chunkIndex * chunkSize, Math.min(file.size, (chunkIndex + 1) * chunkSize), file.type);
          let sent = false;
          for (let attempt = 0; attempt < 2 && !sent; attempt += 1) {
            processingStatus.textContent = attempt === 0
              ? `画像を送信しています ${index + 1} / ${files.length}`
              : `画像を再送しています ${index + 1} / ${files.length}`;
            const imageData = new FormData();
            appendCommonApiFields(imageData);
            imageData.append("upload_session_id", uploadSessionId);
            imageData.append("image_name", imageName);
            imageData.append("chunk_index", String(chunkIndex));
            imageData.append("chunk_count", String(chunkCount));
            imageData.append("total_size", String(file.size));
            imageData.append("image_file", chunk, file.name);
            try {
              await apiRequest("image", imageData);
              sent = true;
            } catch (error) {
              if (error.capacity || attempt === 1) throw error;
            }
          }
        }
      }

      processingStatus.textContent = "公開準備中です。";
      imageInput.disabled = true;
      const actionInput = document.createElement("input");
      actionInput.type = "hidden";
      actionInput.name = "action";
      actionInput.value = "finalize_staged_upload";
      form.appendChild(actionInput);
      const sessionInput = document.createElement("input");
      sessionInput.type = "hidden";
      sessionInput.name = "upload_session_id";
      sessionInput.value = uploadSessionId;
      form.appendChild(sessionInput);
      processingStatus.textContent = "公開しています。";
      finalizingUpload = true;
      form.submit();
    } catch (error) {
      if (uploadSessionId !== "") {
        const cancelData = new FormData();
        appendCommonApiFields(cancelData);
        cancelData.append("upload_session_id", uploadSessionId);
        fetch("?post_api=cancel", { method: "POST", body: cancelData, credentials: "same-origin", keepalive: true }).catch(() => {});
      }
      submitting = false;
      activeUploadSessionId = "";
      submitButton.disabled = false;
      submitButton.textContent = "公開する";
      processingStatus.style.color = "var(--tomos-danger-text)";
      processingStatus.textContent = error && error.message
        ? error.message
        : "画像の送信を完了できませんでした。通信状態を確認して、もう一度お試しください。";
    }
  });

  window.addEventListener("beforeunload", () => {
    if (activeUploadSessionId === "" || finalizingUpload || typeof navigator.sendBeacon !== "function") return;
    const cancelData = new FormData();
    appendCommonApiFields(cancelData);
    cancelData.append("upload_session_id", activeUploadSessionId);
    navigator.sendBeacon("?post_api=cancel", cancelData);
  });
})();
</script>
HTML;
}

function renderBasicPagesSection(string $token, array $config): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $homeUrl = Tomos\Security::publicUrl(Tomos\PostBasicPage::internalUrl(Tomos\PostBasicPage::HOME), $publicBasePath);
    $aboutUrl = Tomos\Security::publicUrl(Tomos\PostBasicPage::internalUrl(Tomos\PostBasicPage::ABOUT), $publicBasePath);

    echo '<h2 id="basic-pages">2. サイトの基本ページを更新する</h2>';
    echo '<div class="notice"><p>トップページやAboutページを変更する場合は、現在のMarkdownをダウンロードして編集し、ファイル名を変更せずに上の投稿フォームから再投稿してください。</p><p>画像を追加または変更する場合は、Markdownと一緒に画像も選択してください。</p></div>';
    echo '<div class="grid">';
    echo '<section class="basic-page"><h3>トップページ</h3>';
    renderBasicPageDownloadButton($token, Tomos\PostBasicPage::HOME, 'index.mdをダウンロード');
    echo '<p><a class="button secondary" href="' . e($homeUrl) . '" target="_blank" rel="noopener noreferrer">トップページを確認</a></p></section>';
    echo '<section class="basic-page"><h3>Aboutページ</h3>';
    renderBasicPageDownloadButton($token, Tomos\PostBasicPage::ABOUT, 'about.mdをダウンロード');
    echo '<p><a class="button secondary" href="' . e($aboutUrl) . '" target="_blank" rel="noopener noreferrer">Aboutページを確認</a></p></section>';
    echo '</div>';
}

function renderBasicPageDownloadButton(string $token, string $type, string $label, bool $compact = false): void
{
    echo '<form class="inline-form" method="post" action="">';
    echo '<input type="hidden" name="action" value="download_basic_page">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="page" value="' . e($type) . '">';
    renderAuthenticationFields('basic_page_password_' . $type, $label . '用の管理用合言葉');
    if ($compact) {
        echo '<button class="secondary" type="submit">' . e($label) . '</button>';
    } else {
        echo '<div class="actions"><button type="submit">' . e($label) . '</button></div>';
    }
    echo '</form>';
}

function renderEditableMarkdownSection(string $token, array $config, string $query, ?array $result): void
{
    echo '<h2 id="editable-markdown">原稿を編集する</h2>';
    echo '<p class="hint">公開中の記事、下書き、固定ページを検索し、Tomos Writeで編集するためのMarkdownをダウンロードします。</p>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="search_editable_markdown">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<label for="edit_query">タイトル・URL・保存先で検索</label>';
    echo '<input id="edit_query" type="text" name="edit_query" value="' . e($query) . '" maxlength="200" autocomplete="off">';
    echo '<p class="hint">検索語を入力した場合だけ、最大30件ずつ表示します。</p>';
    renderAuthenticationFields('editable_search_password');
    echo '<div class="actions"><button type="submit">原稿を検索する</button></div>';
    echo '</form>';

    if ($query === '' || !is_array($result) || empty($result['ok'])) {
        return;
    }

    $items = is_array($result['items'] ?? null) ? $result['items'] : [];
    $total = (int) ($result['total'] ?? 0);
    if ($items === []) {
        echo '<div class="result"><p>該当する原稿はありません。</p></div>';
        return;
    }

    echo '<p class="hint">' . e((string) $total) . '件見つかりました。</p>';
    echo '<div class="editable-results">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $status = (string) ($item['status'] ?? 'published');
        $statusLabel = $status === 'draft' ? '下書き' : ($status === 'fixed' ? '固定ページ' : '公開中');
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            $title = (string) ($item['filename'] ?? '（タイトルなし）');
        }
        $location = $status === 'draft'
            ? (string) ($item['path'] ?? '')
            : (string) ($item['url'] ?? '');
        $mtime = (int) ($item['mtime'] ?? 0);
        $updated = $mtime > 0 ? formatEditableTimestamp($mtime, $config) : '不明';

        echo '<article class="editable-result">';
        echo '<p class="editable-status">' . e($statusLabel) . '</p>';
        echo '<h3>' . e($title) . '</h3>';
        echo '<p><code>' . e($location) . '</code></p>';
        echo '<p class="hint">更新：' . e($updated) . '</p>';
        echo '<form class="inline-form" method="post" action="">';
        echo '<input type="hidden" name="action" value="download_editable_markdown">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<input type="hidden" name="content_path" value="' . e((string) ($item['path'] ?? '')) . '">';
        echo '<div class="actions"><button class="secondary" type="submit">編集用Markdownをダウンロード</button></div>';
        echo '</form>';
        echo '</article>';
    }
    echo '</div>';

    $page = (int) ($result['page'] ?? 1);
    $totalPages = (int) ($result['total_pages'] ?? 0);
    if ($totalPages <= 1) {
        return;
    }

    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    echo '<nav class="pager" aria-label="原稿検索結果のページ">';
    if ($page > 1) {
        echo '<a class="button secondary" href="' . e(editableSearchPageUrl($publicBasePath, $query, $page - 1)) . '">前の30件</a>';
    } else {
        echo '<span></span>';
    }
    echo '<p>' . e((string) $page) . ' / ' . e((string) $totalPages) . 'ページ</p>';
    if ($page < $totalPages) {
        echo '<a class="button secondary" href="' . e(editableSearchPageUrl($publicBasePath, $query, $page + 1)) . '">次の30件</a>';
    } else {
        echo '<span></span>';
    }
    echo '</nav>';
}

function editableSearchPageUrl(string $publicBasePath, string $query, int $page): string
{
    return Tomos\Security::publicUrl('/post/', $publicBasePath)
        . '?' . http_build_query([
            'section' => 'manage',
            'edit_query' => $query,
            'edit_page' => max(1, $page),
        ], '', '&', PHP_QUERY_RFC3986);
}

function formatEditableTimestamp(int $timestamp, array $config): string
{
    try {
        $timezone = new DateTimeZone((string) ($config['site']['timezone'] ?? 'Asia/Tokyo'));
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y年n月j日 H:i');
    } catch (Throwable $exception) {
        return date('Y年n月j日 H:i', $timestamp);
    }
}

function renderWithdrawSection(string $token, ?Tomos\PostContentResolveResult $target): void
{
    echo '<h2 id="post-withdraw">投稿を取り下げる</h2>';
    echo '<p class="hint">公開済みページをWebから外します。Markdownファイルは取り下げ済みとして保管されます。</p>';

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="resolve_withdraw">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<label for="withdraw_url">公開URL</label>';
    echo '<input id="withdraw_url" type="text" name="withdraw_url" placeholder="https://example.com/tomos/diary/2026-07-07">';
    echo '<p class="hint">通常はこちらにTomos内の公開URLを入力してください。URLの <code>?</code> 以降や <code>#</code> 以降は無視します。</p>';
    echo '<label for="withdraw_path">content内パス</label>';
    echo '<input id="withdraw_path" type="text" name="withdraw_path" placeholder="例: diary/2026-07-07.md">';
    echo '<p class="hint">分かる場合だけ入力します。公開URLとcontent内パスは、どちらか一方だけを入力してください。</p>';
    echo '<div class="actions"><button type="submit">取り下げ対象を確認する</button></div>';
    echo '</form>';

    if (!($target instanceof Tomos\PostContentResolveResult) || !$target->ok) {
        return;
    }

    echo '<div class="result meta">';
    echo '<h3>確認結果</h3>';
    echo '<p><strong>タイトル:</strong><br>' . e($target->title !== '' ? $target->title : '（タイトルなし）') . '</p>';
    echo '<p><strong>公開日:</strong><br>' . e($target->date !== '' ? $target->date : '（未設定）') . '</p>';
    echo '<p><strong>更新日:</strong><br>' . e($target->updated !== '' ? $target->updated : '（未設定）') . '</p>';
    echo '<p><strong>保存先:</strong><br><code>content/' . e($target->contentPath) . '</code></p>';
    echo '<p><strong>公開URL:</strong><br><a href="' . e($target->absoluteUrl) . '" target="_blank" rel="noopener noreferrer">' . e($target->absoluteUrl) . '</a></p>';
    echo '<p><a class="button" href="' . e($target->absoluteUrl) . '" target="_blank" rel="noopener noreferrer">別タブでページを確認する</a></p>';
    echo '<div class="notice"><p>この投稿を取り下げると、Web上では表示されなくなります。Markdownファイルは完全削除せず、取り下げ済みとして保管します。</p></div>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="withdraw">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="content_path" value="' . e($target->contentPath) . '">';
    renderAuthenticationFields('withdraw_password');
    echo '<div class="actions"><button class="danger" type="submit">この投稿を取り下げる</button></div>';
    echo '</form>';
    echo '</div>';
}

function renderTrashSection(string $token, array $summary): void
{
    echo '<h2 id="post-trash">取り下げ済みを削除</h2>';
    echo '<p class="hint">取り下げたMarkdownファイルを完全に削除します。この操作は元に戻せません。</p>';
    echo '<div class="grid">';
    echo '<div class="result"><strong>取り下げ済みファイル</strong><br>' . e((string) ($summary['count'] ?? 0)) . '件</div>';
    echo '<div class="result"><strong>推定容量</strong><br>' . e((string) ($summary['size'] ?? '0B')) . '</div>';
    echo '</div>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="clear_trash">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    renderAuthenticationFields('trash_password');
    echo '<label for="confirm_clear">確認入力</label>';
    echo '<input id="confirm_clear" type="text" name="confirm_clear" placeholder="DELETE" autocapitalize="characters" spellcheck="false">';
    echo '<p class="hint">完全に削除する場合は、確認欄に「DELETE」と入力してください。</p>';
    echo '<div class="actions"><button class="danger" type="submit">完全削除する</button></div>';
    echo '</form>';
}

function renderThemeSettingsSection(string $token, array $config): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $themeName = (string) ($config['theme']['name'] ?? 'tomos-minimal');

    echo '<h2 id="theme-settings">テーマ設定</h2>';
    echo '<p class="hint">公開サイトの見た目を切り替えます。テーマの追加や編集はこの画面ではできません。</p>';
    echo '<div class="result meta">';
    echo '<p><strong>現在のテーマ:</strong><br><code>' . e(themeDisplayName($config, $themeName)) . '</code></p>';

    if (!empty($_SESSION['tomos_post_authenticated'])) {
        echo '<p><a class="button" href="' . e($themeUrl) . '">テーマを切り替える</a></p>';
    } else {
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="theme_auth">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        renderAuthenticationFields('theme_password');
        echo '<div class="actions"><button type="submit">テーマを切り替える</button></div>';
        echo '</form>';
    }

    echo '</div>';
}

function renderSiteSettingsSection(string $token, array $config): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $settingsUrl = Tomos\Security::publicUrl('/post/settings/', $publicBasePath);

    echo '<h2 id="site-information-settings">サイト情報・RSS・Sitemap</h2>';
    echo '<p class="hint">サイト名、説明、タイムゾーン、RSS、Sitemapを変更します。サイトURLや記事表示件数はこの画面では変更しません。</p>';
    echo '<div class="result">';

    if (!empty($_SESSION['tomos_post_authenticated'])) {
        echo '<p><a class="button" href="' . e($settingsUrl) . '">サイト設定を開く</a></p>';
    } else {
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="site_settings_auth">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        renderAuthenticationFields('site_settings_password');
        echo '<div class="actions"><button type="submit">サイト設定を開く</button></div>';
        echo '</form>';
    }

    echo '</div>';
}

function renderAnalyticsSettingsSection(string $token, array $config): void
{
    $measurementId = (string) ($config['analytics']['ga4_measurement_id'] ?? '');

    echo '<h2 id="analytics-settings">Google Analytics 4設定</h2>';
    echo '<p class="hint">Google Analytics 4を利用する場合は、<code>G-</code>から始まる測定IDだけを設定します。タグ全体やJavaScriptは入力しません。</p>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="analytics_update">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<label for="ga4_measurement_id">GA4測定ID（任意）</label>';
    echo '<input id="ga4_measurement_id" type="text" name="ga4_measurement_id" value="' . e($measurementId) . '" placeholder="G-XXXXXXXXXX" autocomplete="off" autocapitalize="characters" spellcheck="false">';
    echo '<p class="hint">空欄で保存すると、公開ページからGoogleタグを削除します。テーマを変更してもこの設定は維持されます。</p>';
    renderAuthenticationFields('analytics_password');
    echo '<div class="actions"><button type="submit">設定を保存する</button></div>';
    echo '</form>';
}

function trashSummary(): array
{
    if (!class_exists('Tomos\\TrashManager')) {
        return ['count' => 0, 'size' => '0B'];
    }

    try {
        $summary = (new Tomos\TrashManager(dirname(__DIR__)))->summary();
        return [
            'count' => $summary->count,
            'size' => Tomos\TrashManager::formatBytes($summary->bytes),
        ];
    } catch (Throwable $exception) {
        return ['count' => 0, 'size' => '0B'];
    }
}

function normalizeSection(string $section): string
{
    if (in_array($section, ['withdraw', 'trash'], true)) {
        return 'manage';
    }
    if (in_array($section, ['theme', 'analytics'], true)) {
        return 'settings';
    }

    return in_array($section, ['upload', 'manage', 'settings'], true) ? $section : 'upload';
}

function sectionForAction(string $action): string
{
    if (in_array($action, ['search_editable_markdown', 'download_editable_markdown', 'resolve_withdraw', 'withdraw'], true)) {
        return 'manage';
    }
    if ($action === 'clear_trash') {
        return 'manage';
    }
    if (in_array($action, ['site_settings_auth', 'theme_auth'], true)) {
        return 'settings';
    }
    if ($action === 'analytics_update') {
        return 'settings';
    }

    return 'upload';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function themeDisplayName(array $config, string $themeName): string
{
    $themesDir = rtrim(
        (string) ($config['paths']['theme_dir'] ?? dirname(__DIR__) . '/themes'),
        DIRECTORY_SEPARATOR
    );

    $themeJsonPath = $themesDir
        . DIRECTORY_SEPARATOR
        . $themeName
        . DIRECTORY_SEPARATOR
        . 'theme.json';

    if (!is_file($themeJsonPath)) {
        return $themeName;
    }

    $decoded = json_decode((string) file_get_contents($themeJsonPath), true);
    if (!is_array($decoded)) {
        return $themeName;
    }

    $displayName = trim((string) ($decoded['display_name'] ?? ''));

    return $displayName !== '' ? $displayName : $themeName;
}

function uploadConflictResultFromRecord(Tomos\PostUploadTempRecord $record): Tomos\PostUploadResult
{
    $meta = $record->meta;
    return new Tomos\PostUploadResult(
        false,
        [],
        [],
        (string) ($meta['content_path'] ?? ''),
        (string) ($meta['internal_url'] ?? ''),
        (string) ($meta['absolute_url'] ?? ''),
        (string) ($meta['original_file_name'] ?? ''),
        (string) ($meta['saved_file_name'] ?? ''),
        true,
        $record->id,
        (string) ($meta['existing_title'] ?? ''),
        (string) ($meta['new_title'] ?? ''),
        date('Y-m-d H:i', (int) ($meta['expires_at'] ?? 0)),
        'conflict',
        suggestFileName((string) ($meta['saved_file_name'] ?? 'post.md')),
        (int) ($meta['image_count'] ?? count($record->imagePaths))
    );
}

function suggestFileName(string $fileName): string
{
    $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'md';
    $stem = pathinfo($fileName, PATHINFO_FILENAME);
    if ($stem === '') {
        $stem = 'post';
    }

    return $stem . '-2.' . $extension;
}

function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return $ip;
    }

    return 'unknown';
}
