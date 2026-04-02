#include "BindingManager.h"
#include "SystemState.h"
#include "StorageManager.h"
#include "ProgramManager.h"
#include "AutoUpdateClient.h"
#include <time.h>

// Constructor
BindingManager::BindingManager() 
    : internetAvailable(false), 
      filePollingActive(false), 
      lastFileCheckTime(0) {
    Serial.println("[BindingManager] Constructor called");
    // TLS инициализируется лениво при первом HTTP-запросе (ensureTLS)
}

// Initialize the BindingManager
bool BindingManager::begin() {
    // Load binding state from file
    if (!loadBindingState()) {
        Serial.println("No existing binding state found, creating new state");
        // Initialize with default values
        state.uuid = "";
        state.website = CLOUD_BASE_URL;
        state.api_token = "";
        state.bound = false;
        state.timestamp = "";
        return true;
    }
    
    Serial.println("BindingManager initialized successfully");
    return true;
}

// Check internet access availability
bool BindingManager::checkInternetAccess() {
    Serial.println("========================================");
    Serial.println("[Internet Check] Starting connectivity check");
    Serial.printf("[Internet Check] Target URL: %s\n", INTERNET_CHECK_URL);
    Serial.printf("[Internet Check] Timeout: %d ms\n", INTERNET_CHECK_TIMEOUT);
    Serial.printf("[Internet Check] WiFi Status: %d\n", WiFi.status());
    Serial.printf("[Internet Check] WiFi RSSI: %d dBm\n", WiFi.RSSI());
    Serial.printf("[Internet Check] Local IP: %s\n", WiFi.localIP().toString().c_str());
    
    HTTPClient http;
    
    // Begin HTTPS connection
    ensureTLS();
    Serial.println("[Internet Check] Calling http.begin()...");
    if (!http.begin(*wifiClient, INTERNET_CHECK_URL)) {
        Serial.println("[Internet Check] ❌ ERROR: Failed to begin HTTP connection");
        internetAvailable = false;
        return false;
    }
    Serial.println("[Internet Check] ✓ http.begin() successful");
    
    http.setTimeout(INTERNET_CHECK_TIMEOUT);
    
    Serial.println("[Internet Check] Sending GET request...");
    int httpCode = http.GET();
    
    Serial.printf("[Internet Check] Response code: %d\n", httpCode);
    
    // Check for HTTPClient errors (negative codes)
    if (httpCode < 0) {
        Serial.printf("[Internet Check] ❌ ERROR: HTTP request failed\n");
        Serial.printf("[Internet Check] Error code: %d\n", httpCode);
        Serial.printf("[Internet Check] Error string: %s\n", http.errorToString(httpCode).c_str());
        http.end();
        internetAvailable = false;
        Serial.println("========================================");
        releaseTLS();
        return false;
    }
    
    http.end();
    
    // Accept 200 OK, 301/302 redirects, 400 Bad Request (means server is responding) as valid responses
    if (httpCode == 200 || httpCode == 301 || httpCode == 302 || httpCode == 400) {
        Serial.println("[Internet Check] ✓ SUCCESS: Internet access AVAILABLE");
        internetAvailable = true;
        Serial.println("========================================");
        releaseTLS();
        return true;
    } else {
        Serial.printf("[Internet Check] ❌ FAIL: Unexpected HTTP code: %d\n", httpCode);
        internetAvailable = false;
        Serial.println("========================================");
        releaseTLS();
        return false;
    }
}

// Initiate device binding process
bool BindingManager::initiateBinding(const String& login, const String& password) {
    Serial.println("========================================");
    Serial.println("[Binding] Initiating device binding...");
    Serial.printf("[Binding] Login: %s\n", login.c_str());
    Serial.printf("[Binding] Password length: %d\n", password.length());
    Serial.printf("[Binding] UUID: %s\n", state.uuid.c_str());
    Serial.printf("[Binding] Internet available: %s\n", internetAvailable ? "YES" : "NO");
    
    // Check internet availability
    if (!internetAvailable) {
        Serial.println("[Binding] ❌ ERROR: Cannot initiate binding - Internet not available");
        showMessage("Интернет недоступен. Невозможно привязать устройство.");
        Serial.println("========================================");
        return false;
    }
    
    // Check if UUID is set
    if (state.uuid.isEmpty()) {
        Serial.println("Cannot initiate binding: UUID not set");
        showMessage("Ошибка: UUID устройства не установлен");
        return false;
    }
    
    // Prepare POST payload for bind_request.php
    JsonDocument doc;
    doc["uuid"] = state.uuid;
    doc["login"] = login;
    doc["password"] = password;
    
    String payload;
    serializeJson(doc, payload);
    
    // Send POST request to bind_request.php
    String response;
    int httpCode = sendPostRequest(BIND_REQUEST_ENDPOINT, payload, response);
    
    if (httpCode != 200) {
        Serial.printf("Binding request failed with HTTP code: %d\n", httpCode);
        showMessage("Ошибка привязки: не удалось отправить запрос");
        return false;
    }
    
    // Parse response to get request_id
    JsonDocument responseDoc;
    DeserializationError error = deserializeJson(responseDoc, response);
    
    if (error) {
        Serial.printf("Failed to parse bind_request response: %s\n", error.c_str());
        showMessage("Ошибка привязки: неверный ответ сервера");
        return false;
    }
    
    if (!responseDoc["success"].as<bool>()) {
        String message = responseDoc["message"] | "Unknown error";
        Serial.printf("Binding request rejected: %s\n", message.c_str());
        showMessage("Ошибка привязки: " + message);
        return false;
    }
    
    String requestId = responseDoc["request_id"] | "";
    if (requestId.isEmpty()) {
        Serial.println("No request_id in response");
        showMessage("Ошибка привязки: отсутствует ID запроса");
        return false;
    }
    
    Serial.printf("Binding request created with ID: %s\n", requestId.c_str());
    showMessage("Запрос привязки отправлен. Ожидание обработки...");
    
    // Set up non-blocking state machine for polling
    bindingRequestId = requestId;
    pendingLogin = login;
    bindingStartTime = millis();
    bindingLastPollTime = 0;  // Force immediate first poll
    bindingProcessState = BindingProcessState::WAITING_RESULT;
    
    Serial.println("[Binding] Non-blocking binding initiated, transitioning to WAITING_RESULT");
    Serial.println("========================================");
    return true;
}

// Update non-blocking binding process state machine
void BindingManager::updateBindingProcess() {
    if (bindingProcessState != BindingProcessState::WAITING_RESULT) return;
    
    // Check timeout
    if (millis() - bindingStartTime > BIND_RESULT_TIMEOUT) {
        Serial.println("[Binding] Binding timeout");
        showMessage("Таймаут привязки: результат не получен");
        bindingProcessState = BindingProcessState::FAILED;
        return;
    }
    
    // Poll at interval
    if (millis() - bindingLastPollTime < BIND_RESULT_POLL_INTERVAL) return;
    bindingLastPollTime = millis();
    
    // Poll bind-result.php
    String endpoint = String(BIND_RESULT_ENDPOINT) + "?request_id=" + bindingRequestId;
    String response;
    int httpCode = sendGetRequest(endpoint, response);
    
    if (httpCode != 200) {
        Serial.printf("[Binding] Failed to poll bind_result: HTTP %d\n", httpCode);
        return;
    }
    
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, response);
    if (error) {
        Serial.printf("[Binding] Failed to parse bind_result response: %s\n", error.c_str());
        return;
    }
    
    String status = doc["status"] | "";
    
    if (status == "completed") {
        state.api_token = doc["api_token"] | "";
        
        if (state.api_token.isEmpty()) {
            Serial.println("[Binding] Binding completed but no API token received");
            showMessage("Ошибка привязки: токен не получен");
            bindingProcessState = BindingProcessState::FAILED;
            return;
        }
        
        state.bound = true;
        state.username = pendingLogin;
        struct tm timeinfo;
        if (getLocalTime(&timeinfo)) {
            char buf[32];
            strftime(buf, sizeof(buf), "%Y-%m-%dT%H:%M:%S", &timeinfo);
            state.timestamp = String(buf);
        } else {
            state.timestamp = "Дата неизвестна";
        }
        
        saveBindingState();
        
        // Synchronize cloud.json with binding.json
        if (systemState) {
            systemState->apiToken = state.api_token;
            systemState->deviceId = state.uuid;
            systemState->deviceBound = true;
            
            StorageManager storageManager;
            if (!storageManager.saveCloudSettings(*systemState)) {
                Serial.println("[Binding] WARNING: Failed to sync cloud.json");
            } else {
                Serial.println("[Binding] ✓ cloud.json synchronized with API token");
            }
        }
        
        startFilePolling();
        bindingProcessState = BindingProcessState::COMPLETED;
        Serial.println("[Binding] Binding completed successfully");
        showMessage("Устройство успешно привязано!");
        
    } else if (status == "bound") {
        // Alternative success status from server
        state.uuid = doc["uuid"] | state.uuid;
        state.api_token = doc["api_token"] | state.api_token;
        state.bound = true;
        state.username = pendingLogin;
        struct tm timeinfo2;
        if (getLocalTime(&timeinfo2)) {
            char buf2[32];
            strftime(buf2, sizeof(buf2), "%Y-%m-%dT%H:%M:%S", &timeinfo2);
            state.timestamp = String(buf2);
        } else {
            state.timestamp = "Дата неизвестна";
        }
        
        saveBindingState();
        
        // Synchronize cloud.json with binding.json
        if (systemState) {
            systemState->apiToken = state.api_token;
            systemState->deviceId = state.uuid;
            systemState->deviceBound = true;
            
            StorageManager storageManager;
            if (!storageManager.saveCloudSettings(*systemState)) {
                Serial.println("[Binding] WARNING: Failed to sync cloud.json");
            } else {
                Serial.println("[Binding] ✓ cloud.json synchronized with API token");
            }
        }
        
        startFilePolling();
        bindingProcessState = BindingProcessState::COMPLETED;
        Serial.println("[Binding] Binding completed successfully");
        showMessage("Устройство успешно привязано!");
        
    } else if (status == "failed" || status == "expired") {
        String message = doc["message"] | "Unknown error";
        Serial.printf("[Binding] Binding failed: %s\n", message.c_str());
        showMessage("Ошибка привязки: " + message);
        bindingProcessState = BindingProcessState::FAILED;
        
    } else if (status == "pending") {
        Serial.println("[Binding] Binding still pending...");
        // Keep WAITING_RESULT state, will poll again after interval
        
    } else {
        Serial.printf("[Binding] Unknown binding status: %s\n", status.c_str());
    }
}

// Check for available files from website
bool BindingManager::checkForFiles() {
    Serial.println("Checking for files...");
    
    // Check internet availability
    if (!internetAvailable) {
        Serial.println("Cannot check files: Internet not available");
        return false;
    }
    
    // Check if device is bound
    if (!state.bound) {
        Serial.println("Cannot check files: Device not bound");
        return false;
    }
    
    // Check if API token is set
    if (state.api_token.isEmpty()) {
        Serial.println("Cannot check files: API token not set");
        return false;
    }
    
    // Send GET request to file_list.php
    String endpoint = String(FILE_LIST_ENDPOINT) + 
                      "?uuid=" + state.uuid + 
                      "&api_token=" + state.api_token;
    
    String response;
    int httpCode = sendGetRequest(endpoint, response);
    
    // Handle 401 Unauthorized - device unbound or token invalid
    if (httpCode == 401) {
        Serial.println("Unauthorized (401) - device unbound or token invalid");
        clearBindingState();
        saveBindingState();
        stopFilePolling();
        showMessage("Устройство отвязано. Требуется повторная привязка.");
        return false;
    }
    
    if (httpCode != 200) {
        Serial.printf("Failed to check files: HTTP %d\n", httpCode);
        return false;
    }
    
    // Parse JSON response
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, response);
    
    if (error) {
        Serial.printf("Failed to parse file_list response: %s\n", error.c_str());
        return false;
    }
    
    // Check unbound flag
    if (doc["unbound"].as<bool>() == true) {
        Serial.println("Device unbound from website");
        clearBindingState();
        saveBindingState();
        stopFilePolling();
        showMessage("Устройство отвязано с сайта");
        return false;
    }
    
    // Process files array
    JsonArray files = doc["files"];
    
    if (files.size() == 0) {
        Serial.println("No files available");
        return true;
    }
    
    Serial.printf("Found %d files to download\n", files.size());
    
    // Download each file
    for (JsonObject file : files) {
        String fileId = file["file_id"] | "";
        String filename = file["filename"] | "";
        String url = file["url"] | "";
        
        if (filename.isEmpty() || url.isEmpty()) {
            Serial.println("Skipping file with missing filename or URL");
            continue;
        }
        
        Serial.printf("Downloading file: %s\n", filename.c_str());
        
        if (downloadFile(url, filename)) {
            Serial.printf("Successfully downloaded: %s\n", filename.c_str());
            
            // Confirm file delivery to server
            JsonDocument confirmDoc;
            confirmDoc["uuid"] = state.uuid;
            confirmDoc["api_token"] = state.api_token;
            confirmDoc["file_name"] = filename;
            String confirmPayload;
            serializeJson(confirmDoc, confirmPayload);
            String confirmResponse;
            sendPostRequest(String(FILE_RECEIVED_ENDPOINT), confirmPayload, confirmResponse);
        } else {
            Serial.printf("Failed to download: %s\n", filename.c_str());
        }
    }
    
    return true;
}

// Unbind device from website
bool BindingManager::unbind(bool force) {
    Serial.println("Unbinding device...");
    Serial.printf("[Unbind] Force mode: %s\n", force ? "YES" : "NO");
    
    // Check if device is bound
    if (!state.bound) {
        Serial.println("Device is not bound");
        showMessage("Устройство не привязано");
        return false;
    }
    
    // If force mode is enabled, skip server request and unbind locally
    if (force) {
        Serial.println("[Unbind] Force mode enabled - skipping server request");
        clearBindingState();
        saveBindingState();
        stopFilePolling();
        showMessage("Устройство отвязано локально (принудительно)");
        Serial.println("[Unbind] Device unbound locally (forced)");
        return true;
    }
    
    // Check internet availability
    if (!internetAvailable) {
        Serial.println("Cannot unbind: Internet not available");
        showMessage("Интернет недоступен. Невозможно отвязать устройство.");
        return false;
    }
    
    // Prepare POST payload for unbind.php
    JsonDocument doc;
    doc["uuid"] = state.uuid;
    doc["api_token"] = state.api_token;
    
    String payload;
    serializeJson(doc, payload);
    
    // Send POST request to unbind.php
    String response;
    int httpCode = sendPostRequest(UNBIND_ENDPOINT, payload, response);
    
    if (httpCode == 401) {
        Serial.println("Unauthorized (401) - token invalid");
        // Clear local state anyway
        clearBindingState();
        saveBindingState();
        stopFilePolling();
        showMessage("Устройство отвязано (токен недействителен)");
        return true;
    }
    
    if (httpCode != 200) {
        Serial.printf("Unbind request failed with HTTP code: %d\n", httpCode);
        showMessage("Ошибка отвязки: не удалось отправить запрос");
        return false;
    }
    
    // Parse response
    JsonDocument responseDoc;
    DeserializationError error = deserializeJson(responseDoc, response);
    
    if (error) {
        Serial.printf("Failed to parse unbind response: %s\n", error.c_str());
        // Clear local state anyway
        clearBindingState();
        saveBindingState();
        stopFilePolling();
        showMessage("Устройство отвязано (ошибка парсинга ответа)");
        return true;
    }
    
    if (!responseDoc["success"].as<bool>()) {
        String message = responseDoc["message"] | "Unknown error";
        Serial.printf("Unbind request rejected: %s\n", message.c_str());
        showMessage("Ошибка отвязки: " + message);
        return false;
    }
    
    // Clear local binding state
    clearBindingState();
    saveBindingState();
    stopFilePolling();
    
    Serial.println("Device unbound successfully");
    showMessage("Устройство отвязано");
    
    return true;
}

// Load binding state from LittleFS
bool BindingManager::loadBindingState() {
    Serial.println("Loading binding state...");
    
    File file = LittleFS.open("/binding.json", "r");
    if (!file) {
        Serial.println("binding.json not found");
        return false;
    }
    
    String content = file.readString();
    file.close();
    
    if (content.isEmpty()) {
        Serial.println("binding.json is empty");
        return false;
    }
    
    // Parse JSON
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, content);
    
    if (error) {
        Serial.printf("Failed to parse binding.json: %s\n", error.c_str());
        return false;
    }
    
    // Load state from JSON
    state.uuid = doc["uuid"] | "";
    state.website = doc["website"] | CLOUD_BASE_URL;
    state.api_token = doc["api_token"] | "";
    state.username = doc["username"] | "";
    state.bound = doc["bound"] | false;
    state.timestamp = doc["timestamp"] | "";
    
    Serial.printf("Loaded binding state: UUID=%s, bound=%d\n", 
                  state.uuid.c_str(), state.bound);
    
    return true;
}

// Save binding state to LittleFS
bool BindingManager::saveBindingState() {
    Serial.println("Saving binding state...");
    
    // Create JSON document
    JsonDocument doc;
    doc["uuid"] = state.uuid;
    doc["website"] = state.website;
    doc["api_token"] = state.api_token;
    doc["username"] = state.username;
    doc["bound"] = state.bound;
    doc["timestamp"] = state.timestamp;
    
    // Serialize to string
    String content;
    serializeJson(doc, content);
    
    // Write to file
    File file = LittleFS.open("/binding.json", "w");
    if (!file) {
        Serial.println("Failed to open binding.json for writing");
        return false;
    }
    
    size_t bytesWritten = file.print(content);
    file.close();
    
    if (bytesWritten == 0) {
        Serial.println("Failed to write to binding.json");
        return false;
    }
    
    Serial.printf("Saved binding state: %d bytes\n", bytesWritten);
    return true;
}

// Get current binding status
bool BindingManager::isBound() const {
    return state.bound;
}

// Get internet availability status
bool BindingManager::isInternetAvailable() const {
    return internetAvailable;
}

// Get device UUID
String BindingManager::getUUID() const {
    return state.uuid;
}

// Get API token
String BindingManager::getAPIToken() const {
    return state.api_token;
}

// Get username
String BindingManager::getUsername() const {
    return state.username;
}

// Get binding timestamp
String BindingManager::getTimestamp() const {
    return state.timestamp;
}

// Set device UUID
void BindingManager::setUUID(const String& uuid) {
    state.uuid = uuid;
    Serial.printf("UUID set to: %s\n", uuid.c_str());
}

// Start periodic file polling
void BindingManager::startFilePolling() {
    if (!state.bound) {
        Serial.println("Cannot start file polling: Device not bound");
        return;
    }
    
    if (!internetAvailable) {
        Serial.println("Cannot start file polling: Internet not available");
        return;
    }
    
    filePollingActive = true;
    lastFileCheckTime = millis();
    Serial.println("File polling started");
}

// Stop periodic file polling
void BindingManager::stopFilePolling() {
    filePollingActive = false;
    Serial.println("File polling stopped");
}

// Update periodic file polling
void BindingManager::updateFilePolling() {
    if (!filePollingActive) {
        return;
    }
    
    if (!state.bound || !internetAvailable) {
        stopFilePolling();
        return;
    }
    
    unsigned long currentTime = millis();
    
    // Check if it's time to poll for files
    if (currentTime - lastFileCheckTime >= FILE_CHECK_INTERVAL) {
        lastFileCheckTime = currentTime;
        checkForFiles();
        
        // Trigger update check if AutoUpdateClient is available
        if (autoUpdateClient) {
            autoUpdateClient->update();
        }
    }
}

// Download a file from URL and save to LittleFS
bool BindingManager::downloadFile(const String& url, const String& filename) {
    Serial.printf("Downloading file from: %s\n", url.c_str());
    
    ensureTLS();
    HTTPClient http;
    http.begin(*wifiClient, url);
    
    int httpCode = http.GET();
    
    if (httpCode != 200) {
        Serial.printf("Download failed: HTTP %d\n", httpCode);
        http.end();
        releaseTLS();
        return false;
    }
    
    // Get file size
    int fileSize = http.getSize();
    Serial.printf("File size: %d bytes\n", fileSize);
    
    // Warn if Content-Length header is missing
    if (fileSize == -1) {
        Serial.println("Warning: Content-Length header missing, reading until stream ends");
    }
    
    // Determine file path based on filename pattern
    String filepath;
    if (filename.startsWith("program_") && filename.endsWith(".json")) {
        // Program files go to /programs/ directory
        // Create /programs directory if it doesn't exist
        if (!LittleFS.exists("/programs")) {
            LittleFS.mkdir("/programs");
            Serial.println("Created /programs directory");
        }
        filepath = "/programs/" + filename;
    } else {
        // Other files go to root directory
        filepath = "/" + filename;
    }
    
    Serial.printf("Saving to: %s\n", filepath.c_str());
    
    // Open file for writing
    File file = LittleFS.open(filepath, "w");
    
    if (!file) {
        Serial.printf("Failed to open file for writing: %s\n", filepath.c_str());
        http.end();
        return false;
    }
    
    // Get stream
    WiFiClient* stream = http.getStreamPtr();
    
    // Download file in chunks
    uint8_t buffer[128];
    int bytesRead = 0;
    unsigned long lastDataTime = millis();
    int noDataIterations = 0;
    const int MAX_NO_DATA_ITERATIONS = 50;  // Exit after 50 iterations with no data
    const unsigned long TIMEOUT_MS = 5000;   // 5 second timeout when no data
    
    // Fixed loop condition: exit gracefully when fileSize is -1 and stream ends
    while (http.connected() && (fileSize == -1 || bytesRead < fileSize)) {
        size_t availableSize = stream->available();
        
        if (availableSize) {
            int readSize = stream->readBytes(buffer, 
                                            ((availableSize > sizeof(buffer)) ? 
                                             sizeof(buffer) : availableSize));
            
            file.write(buffer, readSize);
            bytesRead += readSize;
            lastDataTime = millis();
            noDataIterations = 0;  // Reset counter when data is received
        } else {
            // No data available
            noDataIterations++;
            
            // Check for timeout when fileSize is -1 (missing Content-Length)
            if (fileSize == -1) {
                unsigned long timeSinceLastData = millis() - lastDataTime;
                
                // Exit if no data for TIMEOUT_MS milliseconds
                if (timeSinceLastData >= TIMEOUT_MS) {
                    Serial.printf("Timeout: No data received for %lu ms, assuming stream ended\n", timeSinceLastData);
                    break;
                }
                
                // Exit if too many consecutive iterations with no data
                if (noDataIterations >= MAX_NO_DATA_ITERATIONS) {
                    Serial.printf("Stream ended: No data for %d iterations\n", noDataIterations);
                    break;
                }
            }
        }
        
        delay(1);
    }
    
    file.close();
    http.end();
    
    Serial.printf("Downloaded %d bytes to %s\n", bytesRead, filepath.c_str());
    
    // Trigger program list reload if this is a program file
    if (filename.startsWith("program_") && filename.endsWith(".json") && filepath.startsWith("/programs/")) {
        if (programManager != nullptr) {
            Serial.println("[BindingManager] Program file downloaded, reloading program list...");
            programManager->loadProgramsFromStorage();
            Serial.println("[BindingManager] Program list reloaded successfully");
        } else {
            Serial.println("[BindingManager] WARNING: ProgramManager not set, cannot reload program list");
        }
    }
    
    releaseTLS();
    return true;
}

// Send HTTP POST request
int BindingManager::sendPostRequest(const String& endpoint, 
                                    const String& payload, 
                                    String& response) {
    String url = String(CLOUD_BASE_URL) + endpoint;
    
    Serial.println("[HTTP POST] ========================================");
    Serial.printf("[HTTP POST] URL: %s\n", url.c_str());
    Serial.printf("[HTTP POST] Payload: %s\n", payload.c_str());
    
    HTTPClient http;
    
    ensureTLS();
    Serial.println("[HTTP POST] Calling http.begin()...");
    if (!http.begin(*wifiClient, url)) {
        Serial.println("[HTTP POST] ❌ ERROR: Failed to begin HTTP connection");
        return -1;
    }
    Serial.println("[HTTP POST] ✓ http.begin() successful");
    
    http.addHeader("Content-Type", "application/json");
    Serial.println("[HTTP POST] Header added: Content-Type: application/json");
    
    Serial.println("[HTTP POST] Sending POST request...");
    int httpCode = http.POST(payload);
    
    Serial.printf("[HTTP POST] Response code: %d\n", httpCode);
    
    if (httpCode < 0) {
        Serial.printf("[HTTP POST] ❌ ERROR: Request failed\n");
        Serial.printf("[HTTP POST] Error code: %d\n", httpCode);
        Serial.printf("[HTTP POST] Error string: %s\n", http.errorToString(httpCode).c_str());
    } else if (httpCode > 0) {
        response = http.getString();
        Serial.printf("[HTTP POST] Response body length: %d bytes\n", response.length());
        Serial.printf("[HTTP POST] Response body: %s\n", response.c_str());
    }
    
    http.end();
    Serial.println("[HTTP POST] ========================================");
    releaseTLS();
    return httpCode;
}

// Send HTTP GET request
int BindingManager::sendGetRequest(const String& endpoint, String& response) {
    String url = String(CLOUD_BASE_URL) + endpoint;
    
    Serial.println("[HTTP GET] ========================================");
    Serial.printf("[HTTP GET] URL: %s\n", url.c_str());
    
    HTTPClient http;
    
    ensureTLS();
    Serial.println("[HTTP GET] Calling http.begin()...");
    if (!http.begin(*wifiClient, url)) {
        Serial.println("[HTTP GET] ❌ ERROR: Failed to begin HTTP connection");
        return -1;
    }
    Serial.println("[HTTP GET] ✓ http.begin() successful");
    
    Serial.println("[HTTP GET] Sending GET request...");
    int httpCode = http.GET();
    
    Serial.printf("[HTTP GET] Response code: %d\n", httpCode);
    
    if (httpCode < 0) {
        Serial.printf("[HTTP GET] ❌ ERROR: Request failed\n");
        Serial.printf("[HTTP GET] Error code: %d\n", httpCode);
        Serial.printf("[HTTP GET] Error string: %s\n", http.errorToString(httpCode).c_str());
    } else if (httpCode > 0) {
        response = http.getString();
        Serial.printf("[HTTP GET] Response body length: %d bytes\n", response.length());
        Serial.printf("[HTTP GET] Response body: %s\n", response.c_str());
    }
    
    http.end();
    Serial.println("[HTTP GET] ========================================");
    releaseTLS();
    return httpCode;
}

// Clear binding state
void BindingManager::clearBindingState() {
    state.api_token = "";
    state.username = "";
    state.bound = false;
    state.timestamp = "";
    Serial.println("Binding state cleared");
}

// Show message in web interface
void BindingManager::showMessage(const String& message) {
    Serial.printf("MESSAGE: %s\n", message.c_str());
    if (systemState) {
        systemState->lastStatusMessage = message;
    }
}
