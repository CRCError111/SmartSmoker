/**
 * Веб-страница для OTA обновлений
 * 
 * @file OTAWebPage.h
 * @version 1.0
 */

#ifndef OTA_WEB_PAGE_H
#define OTA_WEB_PAGE_H

#include <Arduino.h>

const char OTA_UPDATE_PAGE[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OTA Update</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#667eea;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.container{background:#fff;border-radius:10px;padding:30px;max-width:500px;width:100%}
h1{color:#333;margin-bottom:20px}
.info{background:#f0f0f0;padding:10px;margin-bottom:20px;border-radius:5px;font-size:14px}
input[type="file"]{display:block;width:100%;margin-bottom:20px;padding:10px;border:2px dashed #667eea;border-radius:5px}
.btn{width:100%;padding:15px;border:none;border-radius:5px;background:#667eea;color:#fff;font-size:16px;cursor:pointer}
.btn:disabled{opacity:0.5}
.progress{display:none;margin-top:20px}
.progress.show{display:block}
.bar{width:100%;height:30px;background:#f0f0f0;border-radius:15px;overflow:hidden}
.fill{height:100%;background:#667eea;width:0%;transition:width 0.3s;text-align:center;color:#fff;line-height:30px}
.alert{padding:10px;margin-bottom:20px;border-radius:5px;display:none}
.alert.show{display:block}
.error{background:#f8d7da;color:#721c24}
.success{background:#d4edda;color:#155724}
</style>
</head>
<body>
<div class="container">
<h1>OTA Update</h1>
<div class="info">
<div>Version: %FIRMWARE_VERSION%</div>
<div>Device: %DEVICE_ID%</div>
<div>Free: %FREE_SPACE% KB</div>
</div>
<div class="alert error" id="err"></div>
<div class="alert success" id="ok"></div>
<input type="file" id="file" accept=".bin">
<button class="btn" id="btn" disabled>Upload</button>
<div class="progress" id="prog">
<div class="bar"><div class="fill" id="fill">0%</div></div>
</div>
</div>
<script>
const f=document.getElementById('file'),b=document.getElementById('btn'),p=document.getElementById('prog'),
fill=document.getElementById('fill'),e=document.getElementById('err'),ok=document.getElementById('ok');
let file;
f.onchange=()=>{file=f.files[0];b.disabled=!file||!file.name.endsWith('.bin')};
b.onclick=async()=>{
if(!file)return;
b.disabled=true;p.classList.add('show');e.classList.remove('show');ok.classList.remove('show');
const fd=new FormData();fd.append('firmware',file);
const xhr=new XMLHttpRequest();
xhr.upload.onprogress=ev=>{if(ev.lengthComputable){
const pc=Math.round(ev.loaded/ev.total*100);
fill.style.width=pc+'%';fill.textContent=pc+'%'}};
xhr.onload=()=>{if(xhr.status==200){
ok.textContent='Success! Rebooting...';ok.classList.add('show');
setTimeout(()=>location.href='/',5000)}else{
e.textContent='Error: '+xhr.statusText;e.classList.add('show');b.disabled=false}};
xhr.onerror=()=>{e.textContent='Connection error';e.classList.add('show');b.disabled=false};
xhr.open('POST','/update');xhr.send(fd)};
</script>
</body>
</html>
)rawliteral";

#endif // OTA_WEB_PAGE_H
