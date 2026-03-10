<?php
session_start();
function build_folder_options($base_path) {
$folders = [];
if (is_dir($base_path)) {
$dirIterator = new RecursiveDirectoryIterator($base_path, FilesystemIterator::SKIP_DOTS);
$iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $pathInfo) {
if ($pathInfo->isDir()) {
$relative = substr($pathInfo->getPathname(), strlen($base_path));
if ($relative !== '') {
$name = ltrim($relative, '/');
$folders[$name] = $relative;
}
}
}
}
$sorted_folders = $folders;
ksort($sorted_folders, SORT_NATURAL | SORT_FLAG_CASE);
$folders = ['Cartella Principale' => '/'] + $sorted_folders;
$folders['Altro (specifica)'] = 'custom';
return $folders;
}
$attivita = $_GET['attivita'] ?? ($_POST['attivita'] ?? 'ristorantemimmo1');
$attivita_dirs = array_filter(glob('/root/push/*'), 'is_dir');
$attivita_dirs = array_values(array_filter($attivita_dirs, function($dir) {
return basename($dir) !== 'BACKUP_FUNZIONANTE_01032026';
}));
if (!in_array($attivita, array_map('basename', $attivita_dirs), true) && !empty($attivita_dirs)) {
$attivita = basename($attivita_dirs[0]);
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'folders') {
$attivita_ajax = $_GET['attivita'] ?? '';
$base_path_ajax = '/root/push/' . $attivita_ajax;
$folders_ajax = build_folder_options($base_path_ajax);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
'folders' => $folders_ajax,
'base_path' => $base_path_ajax
]);
exit;
}
if (isset($_POST['clear_server_cache'])) {
$cacheMessage = "OPcache non disponibile.";
if (function_exists('opcache_reset')) {
opcache_reset();
clearstatcache();
$cacheMessage = "Cache PHP (OPcache) svuotata correttamente. Ora il server usa gli ultimi file caricati.";
} else {
$cacheMessage = "OPcache non attiva sul server.";
}
}
$base_path = '/root/push/' . $attivita;
$folders = build_folder_options($base_path);
$message = '';
$success = false;
$upload_notice = '';
$last_uploaded_name = '';
$current_folder = isset($_POST['target_folder']) ? $_POST['target_folder'] : '';
if ($current_folder === '') {
foreach ($folders as $path) {
if ($path !== 'custom') {
$current_folder = $path;
break;
}
}
}
if ($current_folder === '') {
$current_folder = '/';
}
if (isset($_POST['custom_path']) && !empty($_POST['custom_path'])) {
$current_folder = $_POST['custom_path'];
}
$upload_dir = rtrim($base_path, '/') . '/' . ltrim($current_folder, '/');
if (!is_dir($upload_dir)) {
mkdir($upload_dir, 0755, true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
$uploaded_files = [];
$errors = [];
foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
$file_name = basename($_FILES['files']['name'][$key]);
$file_size = $_FILES['files']['size'][$key];
$target_path = $upload_dir . '/' . $file_name;
if (move_uploaded_file($tmp_name, $target_path)) {
chmod($target_path, 0644);
$uploaded_files[] = $file_name . ' (' . round($file_size/1024, 2) . ' KB)';
$last_uploaded_name = $file_name;
} else {
$errors[] = "Errore caricamento $file_name";
}
}
}
if (!empty($uploaded_files)) {
$success = true;
$message = "✅ File caricati con successo in <code>$current_folder</code>:<br>" . implode('<br>', $uploaded_files);
$upload_notice = '✅ File caricato: <strong>' . htmlspecialchars($last_uploaded_name) . '</strong> — ' . date('H:i');
}
if (!empty($errors)) {
$message .= "<br>⚠️ Errori:<br>" . implode('<br>', $errors);
}
}
if (isset($_GET['delete']) && isset($_GET['folder'])) {
$file_to_delete = basename($_GET['delete']);
$folder = $_GET['folder'];
$delete_path = $base_path . $folder . '/' . $file_to_delete;
if (file_exists($delete_path) && unlink($delete_path)) {
$message = "✅ File <code>$file_to_delete</code> eliminato con successo!";
$success = true;
} else {
$message = "❌ Errore nell'eliminazione del file!";
}
}
if (isset($_POST['create_folder']) && !empty($_POST['new_folder_name'])) {
$new_folder_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['new_folder_name']);
$new_folder_path = $upload_dir . '/' . $new_folder_name;
if (!is_dir($new_folder_path)) {
if (mkdir($new_folder_path, 0755, true)) {
$message = "✅ Cartella <code>$new_folder_name</code> creata con successo!";
$success = true;
} else {
$message = "❌ Errore nella creazione della cartella!";
}
} else {
$message = "⚠️ La cartella esiste già!";
}
}
$existing_files = [];
if (is_dir($upload_dir)) {
$items = scandir($upload_dir);
foreach ($items as $item) {
if ($item !== '.' && $item !== '..') {
$full_path = $upload_dir . '/' . $item;
$is_dir = is_dir($full_path);
$existing_files[] = [
'name' => $item,
'size' => $is_dir ? 0 : filesize($full_path),
'is_dir' => $is_dir,
'modified' => date('d/m/Y H:i', filemtime($full_path)),
'web_path' => str_replace($base_path, '', $full_path)
];
}
}
}
usort($existing_files, function($a, $b) {
if ($a['is_dir'] === $b['is_dir']) {
return strcmp($a['name'], $b['name']);
}
return $b['is_dir'] - $a['is_dir'];
});
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Uploader VPS</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f2f2f2;margin:0;padding:10px;}
.wrap{max-width:900px;margin:auto;background:#fff;border:1px solid #ddd;border-radius:10px;padding:18px;}
.top-select{margin-bottom:12px;}
h1{margin:0 0 10px;font-size:22px;}
.msg{padding:10px;border-radius:6px;margin:10px 0;font-size:14px;}
.ok{background:#d4edda;color:#155724;border-left:4px solid #28a745;}
.err{background:#f8d7da;color:#721c24;border-left:4px solid #dc3545;}
label{font-weight:bold;display:block;margin:8px 0 4px;}
select,input[type=text]{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;}
input[type=file]{width:100%;padding:10px;border-radius:8px;background:#2d7ff9;color:#fff;border:0;font-weight:600;}
input[type=file]::file-selector-button{background:#1b5fd6;color:#fff;border:0;padding:8px 12px;border-radius:6px;margin-right:10px;cursor:pointer;font-weight:600;}
.path{background:#eef5ff;padding:8px;border-radius:6px;font-family:monospace;margin-top:6px;}
.upload{border:2px dashed #888;border-radius:8px;padding:18px;text-align:center;margin:15px 0;background:#fafafa;}
.upload-notice{margin-top:10px;background:#e7f1ff;color:#0b3d91;padding:8px 10px;border-radius:6px;font-size:14px;font-weight:600;}
.file-selected{margin-top:10px;padding:10px 12px;background:#eef2f7;border-radius:8px;font-size:18px;font-weight:600;color:#222;word-break:break-all;overflow-wrap:anywhere;}
.file-selected-line{margin-top:8px;font-size:18px;font-weight:600;color:#111;word-break:break-all;overflow-wrap:anywhere;}
button{cursor:pointer;border:none;border-radius:6px;padding:10px 16px;font-weight:bold;}
.primary{background:#2d7ff9;color:#fff;width:100%;}
.danger{background:#d9534f;color:#fff;}
.list{margin-top:15px;border-top:1px solid #eee;}
.row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f1f1;font-size:14px;}
.row .name{flex:1;word-break:break-all;}
.row small{color:#666;}
.create{margin-top:10px;display:flex;gap:8px;}
.create input{flex:1;}
.back{display:inline-block;margin-top:10px;color:#fff;background:#6c757d;padding:8px 12px;border-radius:6px;text-decoration:none;}
@media(max-width:600px){.row{flex-direction:column;align-items:flex-start;gap:4px;}}
</style>
</head>
<body>
<div class="wrap">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="attivita" value="<?php echo htmlspecialchars($attivita); ?>">
<div class="top-select">
<label for="attivitaSelect">Seleziona Attività</label>
<select id="attivitaSelect">
<?php foreach ($attivita_dirs as $dir): $name = basename($dir); ?>
<option value="<?php echo htmlspecialchars($name); ?>" <?php echo $name === $attivita ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
<?php endforeach; ?>
</select>
<label>Cartella di destinazione</label>
<select name="target_folder" id="targetFolder" onchange="toggleCustom()">
<?php foreach ($folders as $name => $path): ?>
<option value="<?php echo $path; ?>" <?php echo $current_folder === $path ? 'selected' : ''; ?>><?php echo $name; ?></option>
<?php endforeach; ?>
</select>
<input type="text" id="customPathInput" name="custom_path" placeholder="/PRENOTAZIONI/nuova-cartella" value="<?php echo !in_array($current_folder, $folders) ? $current_folder : ''; ?>" style="display:none;">
<div class="path" style="font-size:20px; font-weight:bold; color:red;">Path: <span id="pathValue"><?php echo $base_path . $current_folder; ?></span></div>
</div>
<h1>Uploader VPS</h1>
<?php if ($message): ?>
<div class="msg <?php echo $success ? 'ok' : 'err'; ?>"><?php echo $message; ?></div>
<?php endif; ?>
<div class="upload">
<input type="file" name="files[]" id="fileInput" multiple>
<p style="margin:8px 0;">Scegli o trascina file qui</p>
<div id="filesList" class="file-selected">Nessun file selezionato</div>
<div id="selectedFileLine" class="file-selected-line"></div>
<?php if (!empty($upload_notice)): ?>
<div class="upload-notice"><?php echo $upload_notice; ?></div>
<?php endif; ?>
</div>
<button class="primary" id="uploadBtn" type="submit" disabled>Carica</button>
</form>
<form method="post" style="margin-top:10px;">
<input type="hidden" name="attivita" value="<?php echo htmlspecialchars($attivita); ?>">
<button class="danger" type="submit" name="clear_server_cache">Pulisci cache server</button>
</form>
<?php if (!empty($cacheMessage)): ?>
<div class="msg ok"><?php echo htmlspecialchars($cacheMessage); ?></div>
<?php endif; ?>
<?php if (!empty($existing_files)): ?>
<div class="list">
<?php foreach ($existing_files as $file): ?>
<div class="row">
<div class="name"><?php echo $file['is_dir'] ? '📁' : '📄'; ?> <?php echo htmlspecialchars($file['name']); ?></div>
<small><?php echo $file['is_dir'] ? 'dir' : round($file['size']/1024,2).' KB'; ?> • <?php echo $file['modified']; ?></small>
<?php if (!$file['is_dir']): ?>
<a class="danger btn" style="padding:6px 10px;text-decoration:none;" href="?delete=<?php echo urlencode($file['name']); ?>&folder=<?php echo urlencode($current_folder); ?>&attivita=<?php echo urlencode($attivita); ?>" onclick="return confirm('Eliminare <?php echo htmlspecialchars($file['name']); ?>?')">Elimina</a>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<form method="POST" class="create">
<input type="hidden" name="attivita" value="<?php echo htmlspecialchars($attivita); ?>">
<input type="text" name="new_folder_name" placeholder="nuova-cartella" pattern="[a-zA-Z0-9_-]+" required>
<button type="submit" name="create_folder">Crea</button>
</form>
<a class="back" href="dashboard.php">← Dashboard</a>
</div>
<script>
const attSel=document.getElementById('attivitaSelect');
const sel=document.getElementById('targetFolder');
const custom=document.getElementById('customPathInput');
const fi=document.getElementById('fileInput');
const btn=document.getElementById('uploadBtn');
const list=document.getElementById('filesList');
const selectedLine=document.getElementById('selectedFileLine');
const pathValue=document.getElementById('pathValue');
const attivitaHidden=document.querySelector('input[name="attivita"]');
let basePath=<?php echo json_encode($base_path); ?>;

function toggleCustom(){
if(sel.value==='custom'){
custom.style.display='block';
custom.required=true;
}else{
custom.style.display='none';
custom.required=false;
}
}
function showFiles(files){
list.innerHTML='';
if(!files||!files.length){
list.textContent='Nessun file selezionato';
selectedLine.textContent='';
btn.disabled=true;
return;
}
btn.disabled=false;
const names=Array.from(files).map(f=>f.name);
Array.from(files).forEach(f=>{
const div=document.createElement('div');
div.textContent=`${f.name} (${(f.size/1024).toFixed(1)} KB)`;
list.appendChild(div);
});
if(names.length===1){
selectedLine.textContent=`File selezionato: ${names[0]}`;
}else{
selectedLine.textContent=`File selezionati (${names.length}): ${names.join(', ')}`;
}
}
function refreshFolders(attivita){
if(!attivita){return;}
fetch(`?ajax=folders&attivita=${encodeURIComponent(attivita)}`)
.then(r=>r.json())
.then(data=>{
const folders=data.folders||{};
basePath=data.base_path||'';
sel.innerHTML='';
Object.keys(folders).forEach(name=>{
const option=document.createElement('option');
option.value=folders[name];
option.textContent=name;
sel.appendChild(option);
});
if (Object.prototype.hasOwnProperty.call(folders, 'Cartella Principale')) {
sel.value='/';
} else if (sel.options.length) {
sel.value=sel.options[0].value;
}
toggleCustom();
if(attivitaHidden){attivitaHidden.value=attivita;}
pathValue.textContent=basePath + sel.value;
if (sel.value !== 'custom') {
custom.value='';
}
})
.catch(()=>{});
}

attSel.addEventListener('change',e=>{
refreshFolders(e.target.value);
});
sel.addEventListener('change',()=>{
toggleCustom();
if (sel.value === 'custom') {
pathValue.textContent=basePath + (custom.value || '');
} else {
pathValue.textContent=basePath + sel.value;
}
});
custom.addEventListener('input',()=>{
if (sel.value === 'custom') {
pathValue.textContent=basePath + custom.value;
}
});

fi.addEventListener('change',e=>showFiles(e.target.files));
['dragover','dragleave','drop'].forEach(ev=>fi.parentNode.addEventListener(ev,e=>{
e.preventDefault();
e.stopPropagation();
if(ev==='drop'){
fi.files=e.dataTransfer.files;
showFiles(e.dataTransfer.files);
}
}));
toggleCustom();
</script>
</body>
</html>
