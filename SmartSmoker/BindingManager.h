#ifndef BINDING_MANAGER_H
#define BINDING_MANAGER_H

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include <esp_task_wdt.h>
#include "constants.h"
#include "certs.h"

// Forward declarations
class SystemState;
class ProgramManager;
class AutoUpdateClient;

// Constants for endpoints and intervals
static constexpr const char* BIND_REQUEST_ENDPOINT = "/api/bind-request.php";
static constexpr const char* BIND_RESULT_ENDPOINT = "/api/bind-result.php";
static constexpr const char* FILE_LIST_ENDPOINT = "/api/file-list.php";
static constexpr const char* UNBIND_ENDPOINT = "/api/unbind.php";
static constexpr const char* FILE_RECEIVED_ENDPOINT = "/api/file-received.php";

static constexpr unsigned long BIND_RESULT_POLL_INTERVAL = 5000;
static constexpr unsigned long BIND_RESULT_TIMEOUT = 60000;
static constexpr unsigned long FILE_CHECK_INTERVAL = 300000;

static constexpr const char* INTERNET_CHECK_URL = "https://crcerror.ru/api/bind-request.php";
static constexpr int INTERNET_CHECK_TIMEOUT = 5000;

/**
 * Structure to store binding state
 */
struct BindingState {
    String uuid;           // Device UUID
    String website;        // Website URL (e.g., https://crcerror.ru)
    String api_token;      // API token for authentication
    String username;       // Username from website (stored for display purposes)
    bool bound;            // Binding status (true = bound, false = not bound)
    String timestamp;      // Last update timestamp (milliseconds since boot)
};

/**
 * State of the non-blocking binding process
 */
enum class BindingProcessState {
    IDLE,
    WAITING_RESULT,
    COMPLETED,
    FAILED
};

/**
 * BindingManager class
 * Manages device binding process through pull-model architecture
 * Handles binding initiation, polling for results, file checking, and unbinding
 */
class BindingManager {
public:
    /**
     * Constructor
     */
    BindingManager();

    /**
     * Initialize the BindingManager
     * Loads binding state from LittleFS
     * @return true if initialization successful, false otherwise
     */
    bool begin();

    /**
     * Check internet access availability
     * Performs HTTPS GET request to verify connectivity
     * @return true if internet is accessible, false otherwise
     */
    bool checkInternetAccess();

    /**
     * Initiate device binding process
     * Sends POST request to bind_request.php with UUID, login, password
     * Polls bind_result.php for result
     * @param login User login credentials
     * @param password User password credentials
     * @return true if binding successful, false otherwise
     */
    bool initiateBinding(const String& login, const String& password);

    /**
     * Check for available files from website
     * Sends GET request to file_list.php with UUID and API token
     * Downloads and saves files to LittleFS
     * Handles unbound flag and 401 Unauthorized responses
     * @return true if check successful, false otherwise
     */
    bool checkForFiles();

    /**
     * Unbind device from website
     * Sends POST request to unbind.php with UUID and API token
     * Clears local binding state
     * @param force If true, skip server request and unbind locally only
     * @return true if unbinding successful, false otherwise
     */
    bool unbind(bool force = false);

    /**
     * Load binding state from LittleFS (binding.json)
     * @return true if load successful, false otherwise
     */
    bool loadBindingState();

    /**
     * Save binding state to LittleFS (binding.json)
     * @return true if save successful, false otherwise
     */
    bool saveBindingState();

    /**
     * Get current binding status
     * @return true if device is bound, false otherwise
     */
    bool isBound() const;

    /**
     * Get internet availability status
     * @return true if internet is available, false otherwise
     */
    bool isInternetAvailable() const;

    /**
     * Get device UUID
     * @return Device UUID string
     */
    String getUUID() const;

    /**
     * Get API token
     * @return API token string
     */
    String getAPIToken() const;

    /**
     * Get username
     * @return Username string
     */
    String getUsername() const;

    /**
     * Get binding timestamp
     * @return Timestamp string (milliseconds since boot when bound)
     */
    String getTimestamp() const;

    /**
     * Set device UUID
     * @param uuid Device UUID
     */
    void setUUID(const String& uuid);

    /**
     * Set SystemState pointer for status message updates
     * @param state Pointer to SystemState
     */
    void setSystemState(SystemState* state) { systemState = state; }

    /**
     * Set ProgramManager pointer for program list reload
     * @param pm Pointer to ProgramManager
     */
    void setProgramManager(ProgramManager* pm) { programManager = pm; }

    /**
     * Set AutoUpdateClient pointer for update checks
     * @param client Pointer to AutoUpdateClient
     */
    void setAutoUpdateClient(AutoUpdateClient* client) { autoUpdateClient = client; }

    /**
     * Update non-blocking binding process state machine
     * Should be called in main loop() while binding is in progress
     */
    void updateBindingProcess();

    /**
     * Get current binding process state
     * @return Current BindingProcessState
     */
    BindingProcessState getBindingProcessState() const { return bindingProcessState; }

    /**
     * Start periodic file polling
     * Checks for files every FILE_CHECK_INTERVAL milliseconds
     */
    void startFilePolling();

    /**
     * Stop periodic file polling
     */
    void stopFilePolling();

    /**
     * Update periodic file polling
     * Should be called in main loop()
     */
    void updateFilePolling();

private:
    // Binding state
    BindingState state;

    // SystemState pointer for status message updates
    SystemState* systemState = nullptr;

    // ProgramManager pointer for triggering program list reload
    ProgramManager* programManager = nullptr;

    // AutoUpdateClient pointer for triggering update checks
    AutoUpdateClient* autoUpdateClient = nullptr;

    // Internet availability flag
    bool internetAvailable;

    // File polling state
    bool filePollingActive;
    unsigned long lastFileCheckTime;

    // Non-blocking binding process state
    BindingProcessState bindingProcessState = BindingProcessState::IDLE;
    String bindingRequestId;
    unsigned long bindingStartTime = 0;
    unsigned long bindingLastPollTime = 0;
    String pendingLogin;

    // WiFi клиент для HTTPS — аллоцируется лениво
    WiFiClientSecure* wifiClient = nullptr;
    
    void ensureTLS() {
        if (!wifiClient) {
            wifiClient = new WiFiClientSecure();
            wifiClient->setCACert(ROOT_CA_CERT);
            wifiClient->setHandshakeTimeout(5);  // 5 секунд макс на TLS handshake
            Serial.println("[BindingManager] TLS initialized (lazy)");
        }
        esp_task_wdt_reset();  // TLS handshake может занять время
    }
    
    // Освобождаем TLS после использования, чтобы не держать два mbedTLS контекста
    void releaseTLS() {
        if (wifiClient) {
            delete wifiClient;
            wifiClient = nullptr;
        }
    }

    /**
     * Download a file from URL and save to LittleFS
     * @param url File URL
     * @param filename Local filename to save
     * @return true if download successful, false otherwise
     */
    bool downloadFile(const String& url, const String& filename);

    /**
     * Send HTTP POST request
     * @param endpoint API endpoint path
     * @param payload JSON payload
     * @param response Response string (output)
     * @return HTTP status code
     */
    int sendPostRequest(const String& endpoint, const String& payload, String& response);

    /**
     * Send HTTP GET request
     * @param endpoint API endpoint path with query parameters
     * @param response Response string (output)
     * @return HTTP status code
     */
    int sendGetRequest(const String& endpoint, String& response);

    /**
     * Clear binding state
     * Sets bound to false and clears api_token
     */
    void clearBindingState();

    /**
     * Show message in web interface
     * @param message Message to display
     */
    void showMessage(const String& message);
};

#endif // BINDING_MANAGER_H
