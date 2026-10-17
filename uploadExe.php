<?php
// 1. 设置网页编码
header('Content-Type: text/html; charset=utf-8');
$upload_dir = __DIR__ . '/files/';
// 如果 files 目录不存在，自动创建它（并赋予读写权限）
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
// 配置排除项（隐藏不需要显示的文件）
$exclude_files = ['uploadExe.php', 'clipboard.txt', '.', '..'];
$current_dir = __DIR__;
$clipboard_service_url = 'http://127.0.0.1:50321/api/clipboard/set';
$clipboard_service_timeout = 2;

function ConvertWindowsStringToUtf8($value) {
    if (!is_string($value) || $value === '') {
        return '';
    }

    $converted_value = @iconv('GBK', 'UTF-8//IGNORE', $value);
    if ($converted_value !== false && $converted_value !== '') {
        return $converted_value;
    }

    return $value;
}

function CallLocalClipboardService($clipboard_type, $text_value = '', $file_path_list = []) {
    global $clipboard_service_url, $clipboard_service_timeout;

    $payload = [
        'ClipboardType' => $clipboard_type,
        'TextValue' => $text_value,
        'FilePathList' => array_values($file_path_list),
        'RequestSource' => 'UploadExePhp'
    ];

    $json_body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json_body === false) {
        return ['success' => false, 'msg' => '剪切板请求序列化失败'];
    }

    $response_body = false;
    if (function_exists('curl_init')) {
        $curl_handle = curl_init($clipboard_service_url);
        curl_setopt_array($curl_handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => $json_body,
            CURLOPT_CONNECTTIMEOUT => $clipboard_service_timeout,
            CURLOPT_TIMEOUT => $clipboard_service_timeout
        ]);
        $response_body = curl_exec($curl_handle);

        if ($response_body === false) {
            $curl_error = curl_error($curl_handle);
            curl_close($curl_handle);
            return ['success' => false, 'msg' => '本地服务请求失败：' . $curl_error];
        }

        curl_close($curl_handle);
    } else {
        $stream_context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\n",
                'content' => $json_body,
                'timeout' => $clipboard_service_timeout,
                'ignore_errors' => true
            ]
        ]);
        $response_body = @file_get_contents($clipboard_service_url, false, $stream_context);

        if ($response_body === false) {
            return ['success' => false, 'msg' => '本地服务不可用'];
        }
    }

    $response_data = json_decode($response_body, true);
    if (!is_array($response_data)) {
        return ['success' => false, 'msg' => '本地服务返回格式错误'];
    }

    return [
        'success' => !empty($response_data['success']),
        'msg' => $response_data['message'] ?? '本地服务未返回消息'
    ];
}

// ====== A. 处理文本同步 ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text'])) {
    $text = $_POST['text'] ?? '';
    file_put_contents($current_dir . '/clipboard.txt', $text);
    $clipboard_result = CallLocalClipboardService('Text', $text);
    $response_msg = $clipboard_result['success']
        ? '已同步文字，服务器剪切板已更新'
        : '已同步文字，但服务器剪切板更新失败：' . $clipboard_result['msg'];
    // echo json_encode(['success' => true, 'msg' => '已同步文字']);
    echo json_encode(['success' => true, 'msg' => $response_msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== B. 处理文件上传 ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $originalName = $file['name'];
        
        // 核心：使用 iconv 转码为 GBK 适配 Windows 存盘
        $saveName = iconv("UTF-8", "GBK//IGNORE", $originalName);
		// 【改动 2】保存路径指向 files 目录
        $targetPath =$upload_dir . $saveName;
		
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $clipboard_target_path = realpath($targetPath);
            if ($clipboard_target_path === false) {
                $clipboard_target_path = $targetPath;
            }
            $clipboard_target_path = ConvertWindowsStringToUtf8($clipboard_target_path);
            $clipboard_result = CallLocalClipboardService('FileList', '', [$clipboard_target_path]);
            $response_msg = $clipboard_result['success']
                ? '上传成功，服务器剪切板已更新'
                : '上传成功，但服务器剪切板更新失败：' . $clipboard_result['msg'];
            // echo json_encode(['success' => true, 'msg' => '上传成功']);
            echo json_encode(['success' => true, 'msg' => $response_msg], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'msg' => '保存失败，请检查权限']);
        }
    } else {
        echo json_encode(['success' => false, 'msg' => '上传错误代码：' . $file['error']]);
    }
    exit;
}

// ====== C. 处理文件删除 ======
if (isset($_GET['delete'])) {
    $delNameUTF8 = $_GET['delete'];
    $delNameGBK = iconv("UTF-8", "GBK//IGNORE", $delNameUTF8);
    // 【改动 3】删除路径指向 files 目录
    $delPath =$upload_dir . $delNameGBK;
	
    if (file_exists($delPath) && !in_array($delNameUTF8, $exclude_files)) {
        unlink($delPath);
    }
    header("Location: index.php"); // 执行完刷新页面
    exit;
}

// ====== D. 获取文件列表 ======
$fileList = [];
if ($handle = opendir($upload_dir)) { // 【改动 4】扫描 files 目录
    while (false !== ($entry = readdir($handle))) {
        // 将 GBK 乱码转回 UTF-8 供网页正常显示中文
        $utf8Name = iconv("GBK", "UTF-8//IGNORE", $entry);
        
        // 【改动 5】判断路径指向 files 目录
        if (!in_array($utf8Name,$exclude_files) && !is_dir($upload_dir .$entry)) {
            $fileList[] = [
                'name' => $utf8Name,
                'size' => round(filesize($upload_dir .$entry) / 1024, 1) . ' KB',
                // 下载链接前面加上 files/ 路径
                'url'  => 'files/' . rawurlencode($utf8Name) 
            ];
        }
    }
    closedir($handle);
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>云传工具</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        h3 { margin: 0 0 15px 0; font-size: 16px; color: #555; border-left: 4px solid #007bff; padding-left: 10px; }
        
        #textBox { width: 100%; height: 120px; padding: 12px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; outline: none; transition: 0.2s; }
        #textBox:focus { border-color: #007bff; }
        
        .btn { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 8px; margin-top: 10px; cursor: pointer; font-size: 15px; }
        .btn:hover { background: #0069d9; }

        /* 上传区域样式 */
        #drop-area { height: 100px; border: 2px dashed #ccc; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #888; cursor: pointer; background: #fafafa; font-size: 14px; transition: 0.2s; }
        #drop-area:hover { border-color: #007bff; background: #f0f7ff; color: #007bff; }
        #drop-area.active { border-color: #007bff; background: #f0f7ff; color: #007bff; }

        /* 列表样式 */
        .file-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .file-info { flex: 1; text-decoration: none; color: #007bff; font-size: 14px; word-break: break-all; }
        .file-size { color: #999; font-size: 12px; margin-left: 10px; white-space: nowrap; }
        .del-link { color: #ff4d4f; font-size: 12px; margin-left: 15px; text-decoration: none; padding: 4px 8px; }

        /* 弹窗样式 */
        #toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #333; color: white; padding: 8px 20px; border-radius: 20px; display: none; z-index: 9999; font-size: 14px; }

        /* 上传进度条 */
        .upload-progress { display: none; margin-top: 12px; }
        .upload-progress-track { width: 100%; height: 12px; background: #e9ecef; border-radius: 999px; overflow: hidden; }
        .upload-progress-bar { width: 0%; height: 100%; background: linear-gradient(90deg, #007bff, #4da3ff); transition: width 0.2s ease; }
        .upload-progress-text { margin-top: 8px; font-size: 13px; color: #666; text-align: center; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h3>文本同步</h3>
        <textarea id="textBox" placeholder="在这里输入文字..."><?php echo htmlspecialchars(@file_get_contents($current_dir.'/clipboard.txt')); ?></textarea>
        <button class="btn" onclick="saveText()">保存文字</button>
    </div>

    <div class="card">
        <h3>文件传输</h3>
        <input type="file" id="fileInput" style="display: none;">
        <div id="drop-area" onclick="document.getElementById('fileInput').click()">
            点此选择文件 或 拖拽至此处 / Ctrl+V粘贴
        </div>
        <div id="uploadProgress" class="upload-progress">
            <div class="upload-progress-track">
                <div id="uploadProgressBar" class="upload-progress-bar"></div>
            </div>
            <div id="uploadProgressText" class="upload-progress-text">准备上传...</div>
        </div>
    </div>

    <div class="card">
        <h3>下载列表</h3>
        <?php foreach ($fileList as $f): ?>
        <div class="file-item">
            <a class="file-info" href="<?php echo $f['url']; ?>" download>
                <?php echo $f['name']; ?>
                <span class="file-size"><?php echo $f['size']; ?></span>
            </a>
            <a class="del-link" href="?delete=<?php echo urlencode($f['name']); ?>" onclick="return confirm('确定删除？')">删除</a>
        </div>
        <?php endforeach; ?>
        <?php if(empty($fileList)) echo '<p style="text-align:center;color:#ccc;font-size:13px;margin:0;">库中无文件</p>'; ?>
    </div>
</div>

<div id="toast"></div>

<script>
const toast = document.getElementById('toast');
const fileInput = document.getElementById('fileInput');
const dropArea = document.getElementById('drop-area');
const UploadProgress = document.getElementById('uploadProgress');
const UploadProgressBar = document.getElementById('uploadProgressBar');
const UploadProgressText = document.getElementById('uploadProgressText');
let IsUploading = false;

function showMsg(msg, reload = false) {
    toast.innerText = msg;
    toast.style.display = 'block';
    setTimeout(() => {
        toast.style.display = 'none';
        if(reload) location.reload();
    }, 1200);
}

// 文本保存功能
function saveText() {
    const text = document.getElementById('textBox').value;
    const fd = new FormData();
    fd.append('text', text);
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json()).then(res => showMsg(res.msg));
}

function SetUploadProgress(ProgressValue, ProgressTextValue) {
    UploadProgress.style.display = 'block';
    UploadProgressBar.style.width = `${ProgressValue}%`;
    UploadProgressText.innerText = ProgressTextValue;
}

function ResetUploadProgress() {
    UploadProgress.style.display = 'none';
    UploadProgressBar.style.width = '0%';
    UploadProgressText.innerText = '准备上传...';
}

// 文件上传核心逻辑
// function uploadFile(file) {
//     if(!file) return;
//     const fd = new FormData();
//     fd.append('file', file);
//     showMsg('上传中...');
//     fetch('', { method: 'POST', body: fd })
//     .then(r => r.json()).then(res => showMsg(res.msg, res.success))
//     .catch(() => showMsg('请求失败'));
// }

function UploadFile(file) {
    if (!file || IsUploading) return;

    const FormDataValue = new FormData();
    const Request = new XMLHttpRequest();

    IsUploading = true;
    FormDataValue.append('file', file);
    SetUploadProgress(0, `正在上传 ${file.name} (0%)`);
    showMsg('上传中...');

    Request.upload.onprogress = event => {
        if (!event.lengthComputable) {
            SetUploadProgress(0, `正在上传 ${file.name}...`);
            return;
        }

        const ProgressValue = Math.min(100, Math.round((event.loaded / event.total) * 100));
        SetUploadProgress(ProgressValue, `正在上传 ${file.name} (${ProgressValue}%)`);
    };

    Request.onreadystatechange = () => {
        if (Request.readyState !== 4) return;

        IsUploading = false;

        if (Request.status >= 200 && Request.status < 300) {
            SetUploadProgress(100, `上传完成 ${file.name} (100%)`);

            try {
                const ResponseValue = JSON.parse(Request.responseText);
                showMsg(ResponseValue.msg, ResponseValue.success);
                setTimeout(() => {
                    ResetUploadProgress();
                }, ResponseValue.success ? 800 : 1500);
            } catch (error) {
                SetUploadProgress(0, `上传完成但返回解析失败: ${file.name}`);
                showMsg('返回结果解析失败');
                setTimeout(() => {
                    ResetUploadProgress();
                }, 1500);
            }
            return;
        }

        SetUploadProgress(0, `上传失败: ${file.name}`);
        showMsg('请求失败');
        setTimeout(() => {
            ResetUploadProgress();
        }, 1500);
    };

    Request.onerror = () => {
        IsUploading = false;
        SetUploadProgress(0, `上传失败: ${file.name}`);
        showMsg('请求失败');
        setTimeout(() => {
            ResetUploadProgress();
        }, 1500);
    };

    Request.open('POST', '', true);
    Request.send(FormDataValue);
}

// 监听点击选择文件
fileInput.onchange = () => {
    UploadFile(fileInput.files[0]);
    fileInput.value = ''; // 清空选择，允许重复上传同名文件
};

// 监听拖拽事件
dropArea.ondragover = e => { e.preventDefault(); dropArea.classList.add('active'); };
dropArea.ondragleave = () => { dropArea.classList.remove('active'); };
dropArea.ondrop = e => {
    e.preventDefault();
    dropArea.classList.remove('active');
    UploadFile(e.dataTransfer.files[0]);
};

// 全局Ctrl+V粘贴文件
document.addEventListener('paste', e => {
    if (document.activeElement === document.getElementById('textBox')) return;
    const files = e.clipboardData?.files;
    if (files && files.length > 0) {
        e.preventDefault();
        dropArea.classList.add('active');
        UploadFile(files[0]);
        setTimeout(() => dropArea.classList.remove('active'), 1500);
    }
});
</script>
</body>
</html>
