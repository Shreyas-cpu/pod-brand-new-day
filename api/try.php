<!DOCTYPE html>
<html>
<head>
    <title>Gemini Lite Chatbot Sandbox</title>
    <style>
        body {
            background-color: #121212; color: #e0e0e0; font-family: system-ui, sans-serif;
            margin: 0; padding: 2rem; max-width: 900px; margin: 0 auto;
        }
        .section {
            background: #1e1e1e; padding: 1.5rem; border-radius: 8px;
            border: 1px solid #333; margin-bottom: 2rem;
        }
        h2 { border-bottom: 1px solid #333; padding-bottom: 0.5rem; font-size: 1.2rem; margin-top: 0; }
        label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: #a1a1aa; }
        input, select, button, textarea {
            width: 100%; box-sizing: border-box; background: #2d2d2d; color: #fff;
            border: 1px solid #444; padding: 10px; border-radius: 4px; margin-bottom: 1rem;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #66b3ff; }
        button { background: #2a5a8a; cursor: pointer; font-weight: bold; border: none; }
        button:hover { background: #316ba6; }
        button:disabled { background: #444; cursor: not-allowed; color: #888; }
        
        #chatBox {
            height: 400px; overflow-y: auto; background: #121212; 
            border: 1px solid #333; padding: 1rem; border-radius: 4px;
            display: flex; flex-direction: column; gap: 1rem;
        }
        .msg { max-width: 80%; padding: 10px 15px; border-radius: 8px; line-height: 1.4; }
        .msg.user { background: #2a5a8a; align-self: flex-end; }
        .msg.bot { background: #333; align-self: flex-start; }
        .msg img { max-width: 200px; border-radius: 4px; margin-bottom: 10px; display: block; }
        
        .input-row { display: flex; gap: 10px; align-items: flex-end; }
        .input-row textarea { flex: 1; resize: none; margin-bottom: 0; height: 50px; }
        .input-row button { width: auto; height: 50px; padding: 0 20px; margin-bottom: 0; }
        .file-upload-btn { background: #444; color: #fff; width: auto; height: 50px; line-height: 30px; text-align: center; cursor: pointer; border-radius: 4px; padding: 0 15px; border: 1px solid #555; }
        .file-upload-btn:hover { background: #555; }
        #imageInput { display: none; }
    </style>
</head>
<body>

    <h1 style="text-align:center;">Gemini Lite Chatbot Sandbox</h1>

    <!-- SECTION 1: Settings -->
    <div class="section">
        <h2>1. API Settings</h2>
        <div style="display: flex; gap: 20px;">
            <div style="flex: 2;">
                <label>Gemini API Key:</label>
                <input type="password" id="apiKey" placeholder="AIzaSy..." required>
            </div>
            <div style="flex: 1;">
                <label>Select Model:</label>
                <select id="modelSelect">
                    <option value="auto">Auto Mode (Auto-shift on limits)</option>
                    <option value="gemini-3.5-flash-lite">Gemini 3.5 Flash Lite (Recommended)</option>
                    <option value="gemini-3.5-flash">Gemini 3.5 Flash</option>
                    <option value="gemini-2.5-flash">Gemini 2.5 Flash</option>
                    <option value="gemini-1.5-flash">Gemini 1.5 Flash</option>
                </select>
            </div>
        </div>
    </div>

    <!-- SECTION 2: Chat -->
    <div class="section">
        <h2>2. Chat Interface</h2>
        <div id="chatBox">
            <div class="msg bot">Hello! Paste your API key above and let's chat. You can also upload images!</div>
        </div>
        
        <div style="margin-top: 15px;" id="previewContainer"></div>
        
        <div class="input-row" style="margin-top: 15px;">
            <label class="file-upload-btn">
                + Image
                <input type="file" id="imageInput" accept="image/*" onchange="previewImage(event)">
            </label>
            <textarea id="promptInput" placeholder="Ask something about the image..."></textarea>
            <button id="sendBtn" onclick="sendMessage()">Send</button>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", async () => {
            const urlParams = new URLSearchParams(window.location.search);
            let apiKey = urlParams.get('api_key');
            let model = urlParams.get('model');
            
            if (!apiKey) {
                try {
                    const res = await fetch('/api/config.php');
                    const data = await res.json();
                    if (data.gemini_api_key) {
                        apiKey = data.gemini_api_key;
                    }
                } catch(e) {}
            }
            
            if (apiKey) {
                document.getElementById('apiKey').value = apiKey;
            }
            if (model) {
                document.getElementById('modelSelect').value = model;
            }
        });

        let currentImageBase64 = null;
        let currentMimeType = null;

        const toBase64 = file => new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
        });

        async function previewImage(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const b64 = await toBase64(file);
            currentMimeType = file.type;
            
            // Strip data URI scheme
            currentImageBase64 = b64.substring(b64.indexOf(',') + 1);
            
            document.getElementById('previewContainer').innerHTML = 
                `<div style="display:inline-block; position:relative;">
                    <img src="${b64}" style="max-height: 100px; border-radius: 4px; border: 1px solid #555;">
                    <button onclick="clearImage()" style="position:absolute; top:-10px; right:-10px; width:24px; height:24px; padding:0; border-radius:12px; background:red; color:white; font-size:12px; border: none; cursor: pointer;">X</button>
                </div>`;
        }

        function clearImage() {
            currentImageBase64 = null;
            currentMimeType = null;
            document.getElementById('imageInput').value = '';
            document.getElementById('previewContainer').innerHTML = '';
        }

        function appendMessage(sender, text, b64Image = null) {
            const chatBox = document.getElementById('chatBox');
            const msgDiv = document.createElement('div');
            msgDiv.className = 'msg ' + sender;
            
            let html = '';
            if (b64Image) {
                html += `<img src="data:image/jpeg;base64,${b64Image}">`;
            }
            if (text) {
                const safeText = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, "<br>");
                html += `<div>${safeText}</div>`;
            }
            
            msgDiv.innerHTML = html;
            chatBox.appendChild(msgDiv);
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        async function sendMessage() {
            const apiKey = document.getElementById('apiKey').value.trim();
            const model = document.getElementById('modelSelect').value;
            const prompt = document.getElementById('promptInput').value.trim();
            
            if (!apiKey) {
                alert("Please enter your Gemini API Key in Section 1.");
                return;
            }
            if (!prompt && !currentImageBase64) {
                return; // nothing to send
            }

            // Append user message
            appendMessage('user', prompt, currentImageBase64);
            
            // Prepare payload for Gemini REST API
            const parts = [];
            if (prompt) parts.push({ text: prompt });
            if (currentImageBase64) {
                parts.push({
                    inline_data: {
                        mime_type: currentMimeType || "image/jpeg",
                        data: currentImageBase64
                    }
                });
            }

            const payload = {
                contents: [{ parts: parts }]
            };

            // Clear inputs
            document.getElementById('promptInput').value = '';
            clearImage();

            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;
            sendBtn.innerText = '...';

            const modelsToTry = model === 'auto' 
                ? ["gemini-3.5-flash-lite", "gemini-3.5-flash", "gemini-2.5-flash", "gemini-1.5-flash"] 
                : [model];
            
            let success = false;
            let lastError = null;

            for (const m of modelsToTry) {
                try {
                    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${m}:generateContent?key=${apiKey}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    
                    const data = await response.json();
                    
                    if (response.ok) {
                        const reply = data.candidates[0].content.parts[0].text;
                        const prefix = model === 'auto' ? `*[Auto-selected ${m}]*\n\n` : '';
                        appendMessage('bot', prefix + reply);
                        success = true;
                        break;
                    } else {
                        lastError = data.error ? data.error.message : JSON.stringify(data);
                        console.warn(`${m} failed:`, lastError);
                    }
                } catch (err) {
                    lastError = err.message;
                    console.warn(`${m} network error:`, lastError);
                }
            }
            
            if (!success) {
                appendMessage('bot', `**Error**: All attempted models failed. Last error: ${lastError}`);
            }

            sendBtn.disabled = false;
            sendBtn.innerText = 'Send';
        }
    </script>
</body>
</html>
