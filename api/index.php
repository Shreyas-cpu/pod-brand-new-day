<!DOCTYPE html>
<html>
<head>
    <title>POD System Dashboard</title>
    <style>
        body {
            background-color: #121212; color: #e0e0e0; font-family: system-ui, -apple-system, sans-serif;
            margin: 0; padding: 2rem;
        }
        .container {
            display: flex; gap: 2rem; justify-content: space-between;
        }
        .column {
            flex: 1; background: #1e1e1e; padding: 1.5rem; border-radius: 8px;
            border: 1px solid #333;
        }
        h2 { border-bottom: 1px solid #333; padding-bottom: 0.5rem; font-size: 1.2rem; margin-top: 0; }
        label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: #a1a1aa; }
        input, button {
            width: 100%; box-sizing: border-box; background: #2d2d2d; color: #fff;
            border: 1px solid #444; padding: 10px; border-radius: 4px; margin-bottom: 1rem;
        }
        input:focus { outline: none; border-color: #66b3ff; }
        button { background: #2a5a8a; cursor: pointer; font-weight: bold; border: none; }
        button:hover { background: #316ba6; }
        pre, code { background: #121212 !important; border: 1px solid #333; padding: 1rem; border-radius: 4px; overflow-x: auto; color: #9cdcfe;}
    </style>
</head>
<body>
    <h1 style="text-align:center; margin-bottom: 2rem;">POD Checking System Dashboard</h1>
    <div class="container">
        <!-- Col 1 -->
        <div class="column">
            <h2>1. Configuration</h2>
            <p style="font-size: 0.85rem; color: #a1a1aa;">Set up your Gemini API key to power the system.</p>
            <label>LLM (Gemini) API Key:</label>
            <input type="password" id="geminiKey" placeholder="AIzaSy...">
            <button onclick="saveGeminiKey()">Save API Key</button>
            <div id="configStatus" style="color: #4caf50; font-size: 0.9rem;"></div>
        </div>

        <!-- Col 2 -->
        <div class="column">
            <h2>2. System API Details</h2>
            <p style="font-size: 0.85rem; color: #a1a1aa;">Use these details to integrate the POD checker into your other projects.</p>
            <label>Bridge API Key:</label>
            <div style="display:flex; gap: 10px;">
                <input type="text" id="bridgeKey" readonly placeholder="Not generated yet">
                <button onclick="generateBridgeKey()" style="width: auto;">Generate</button>
            </div>
            
            <button onclick="copyApiDetails()">Copy API Details</button>
            
            <label>API Usage Example:</label>
            <pre id="apiDocs">
curl -X POST http://localhost:8000/api/check.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <span id="docBridgeKey">YOUR_BRIDGE_KEY</span>" \
  -d '{"image_url": "https://example.com/invoice.jpg"}'
            </pre>
        </div>

        <!-- Col 3 -->
        <div class="column">
            <h2>3. Testing Phase</h2>
            <p style="font-size: 0.85rem; color: #a1a1aa;">Test the POD validation logic.</p>
            <label>Image URL:</label>
            <input type="text" id="testImageUrl" placeholder="https://example.com/pod.jpg">
            
            <div style="text-align: center; margin-bottom: 1rem; font-size: 0.85rem; color: #a1a1aa;">OR</div>
            
            <label>Upload Image:</label>
            <input type="file" id="testImageFile" accept="image/*">
            
            <button onclick="testApi()">Test System</button>
            
            <label>Result:</label>
            <pre id="testResult" style="min-height: 100px;">Waiting for test...</pre>
        </div>
    </div>

    <script>
        async function loadConfig() {
            const res = await fetch('/api/config.php');
            const data = await res.json();
            if (data.gemini_api_key) document.getElementById('geminiKey').value = data.gemini_api_key;
            if (data.bridge_api_key) {
                document.getElementById('bridgeKey').value = data.bridge_api_key;
                document.getElementById('docBridgeKey').innerText = data.bridge_api_key;
            }
        }

        async function saveGeminiKey() {
            const key = document.getElementById('geminiKey').value;
            const res = await fetch('/api/config.php', {
                method: 'POST', body: JSON.stringify({ gemini_api_key: key })
            });
            document.getElementById('configStatus').innerText = "Saved successfully!";
            setTimeout(() => document.getElementById('configStatus').innerText = "", 2000);
        }

        async function generateBridgeKey() {
            if (document.getElementById('bridgeKey').value !== '') {
                alert("Bridge API key is already generated and fixed for this system.");
                return;
            }
            const res = await fetch('/api/config.php', {
                method: 'POST', body: JSON.stringify({ generate_bridge_key: true })
            });
            const data = await res.json();
            document.getElementById('bridgeKey').value = data.bridge_api_key;
            document.getElementById('docBridgeKey').innerText = data.bridge_api_key;
        }

        function copyApiDetails() {
            const text = document.getElementById('apiDocs').innerText;
            navigator.clipboard.writeText(text);
            alert("API Details copied to clipboard!");
        }

        const toBase64 = file => new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
        });

        async function testApi() {
            const resultDiv = document.getElementById('testResult');
            resultDiv.innerText = "Checking...";
            
            const imageUrl = document.getElementById('testImageUrl').value;
            const imageFile = document.getElementById('testImageFile').files[0];
            const bridgeKey = document.getElementById('bridgeKey').value;
            
            if (!bridgeKey) {
                resultDiv.innerText = "Error: Please generate a Bridge API Key first.";
                return;
            }
            if (!imageUrl && !imageFile) {
                resultDiv.innerText = "Error: Please provide an image URL or upload a file.";
                return;
            }

            let payload = {};
            if (imageFile) {
                try { payload.image_base64 = await toBase64(imageFile); }
                catch (e) { resultDiv.innerText = "Error reading file."; return; }
            } else {
                payload.image_url = imageUrl;
            }

            try {
                const response = await fetch('/api/check.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + bridgeKey
                    },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                resultDiv.innerText = JSON.stringify(data, null, 2);
            } catch (err) {
                resultDiv.innerText = "Error: " + err;
            }
        }

        loadConfig();
    </script>
</body>
</html>
