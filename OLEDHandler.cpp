#include "OLEDHandler.h"
#include "Config.h"
#include "SystemState.h"

// === Внешние ссылки ===
extern std::vector<SmokingProgram> loadUserPrograms();
extern SystemState& systemState;

OLEDHandler::OLEDHandler()
  : u8g2(U8G2_R0, U8X8_PIN_NONE, I2C_SCL, I2C_SDA) {
}

void OLEDHandler::refreshPrograms() {
  availablePrograms.clear();
  for (const auto& p : builtInPrograms) {
    availablePrograms.push_back(std::make_unique<SmokingProgram>(p));
  }
  auto userProgs = loadUserPrograms();
  for (const auto& p : userProgs) {
    availablePrograms.push_back(std::make_unique<SmokingProgram>(p));
  }
}

void OLEDHandler::begin() {
  u8g2.begin();
  u8g2.setFont(u8g2_font_6x12_tf);
  u8g2.setFontRefHeightExtendedText();
  u8g2.setDrawColor(1);
  u8g2.setFontPosTop();
  displayInitialized = true;
  refreshPrograms();

  u8g2.clearBuffer();
  u8g2.setCursor(0, 0);
  u8g2.print("SmartSmoker");
  u8g2.setCursor(0, 16);
  u8g2.print("Starting...");
  u8g2.sendBuffer();
  delay(1000);
}

void OLEDHandler::update() {
  if (!displayInitialized || millis() - lastUpdate < 200) return;
  lastUpdate = millis();
  u8g2.clearBuffer();

  switch (currentScreen) {
    case UIScreen::MAIN: drawMain(); break;
    case UIScreen::PROGRAM_LIST: drawProgramList(); break;
    case UIScreen::CONFIRM_START:
    case UIScreen::CONFIRM_STOP:
      drawConfirm(confirmText);
      break;
  }

  u8g2.sendBuffer();
}

void OLEDHandler::drawMain() {
  if (systemState.networkMode == SystemState::NetworkMode::STA) {
    u8g2.setCursor(0, 0);
    u8g2.printf("STA:%s", systemState.ssid.c_str());
  } else {
    u8g2.setCursor(0, 0);
    u8g2.printf("AP:%s", systemState.ssid.c_str());
  }

  u8g2.setCursor(0, 16);
  u8g2.printf("T:%.0f/%.0f/%.0f", systemState.tempChamber, systemState.tempSmoke, systemState.tempProduct);
  u8g2.setCursor(0, 28);
  u8g2.printf("H:%.0f%%", systemState.humidity);
  u8g2.setCursor(0, 40);
  u8g2.printf("TEN:%s DYM:%d%%", systemState.heaterOn ? "ON" : "OFF", systemState.smokePWM);

  u8g2.setCursor(0, 52);
  if (systemState.mode == SystemState::SystemMode::RUNNING && systemState.currentProgram) {
    u8g2.printf("> %s", systemState.currentProgram->name.c_str());
    u8g2.setCursor(0, 64);
    u8g2.printf("Этап %d", (int)systemState.currentStepIndex + 1);
  } else {
    u8g2.print("OK - программы");
  }
}

void OLEDHandler::drawProgramList() {
  size_t start = (selectedProgramIndex > 3) ? selectedProgramIndex - 3 : 0;
  for (size_t i = 0; i < 5 && (start + i) < availablePrograms.size(); i++) {
    size_t idx = start + i;
    u8g2.setCursor(0, i * 12);
    if (idx == selectedProgramIndex) u8g2.print("> ");
    u8g2.print(availablePrograms[idx]->name.c_str());
  }
}

void OLEDHandler::drawConfirm(const char* text) {
  u8g2.setFont(u8g2_font_8x13_tf);
  u8g2.setCursor(0, 0);
  u8g2.print(text);
  u8g2.setFont(u8g2_font_6x12_tf);
  u8g2.setCursor(0, 20);
  u8g2.print("OK - да");
  u8g2.setCursor(0, 32);
  u8g2.print("Назад - нет");
}

void OLEDHandler::handleButtonEvent(int event) {
  if (currentScreen == UIScreen::CONFIRM_STOP) {
    if (event == 2) {
      systemState.emergencyStop = true;
      systemState.mode = SystemState::SystemMode::IDLE;
      digitalWrite(PIN_HEATER_SSR, LOW);
      analogWrite(PIN_SMOKE_MOSFET, 0);
      currentScreen = UIScreen::MAIN;
    } else if (event == 3) {
      currentScreen = UIScreen::MAIN;
    }
    return;
  }

  if (currentScreen == UIScreen::CONFIRM_START) {
    if (event == 2) {
      if (selectedProgramIndex < availablePrograms.size()) {
        systemState.mode = SystemState::SystemMode::RUNNING;
        systemState.currentProgram = std::make_unique<SmokingProgram>(*availablePrograms[selectedProgramIndex]);
        systemState.currentStepIndex = 0;
        systemState.programStartTime = millis();
        systemState.stepStartTime = 0;
        systemState.waitingForTemp = false;
        systemState.emergencyStop = false;
      }
      currentScreen = UIScreen::MAIN;
    } else if (event == 3) {
      currentScreen = UIScreen::PROGRAM_LIST;
    }
    return;
  }

  if (currentScreen == UIScreen::PROGRAM_LIST) {
    if (event == 0 && selectedProgramIndex > 0) selectedProgramIndex--;
    else if (event == 1 && selectedProgramIndex < availablePrograms.size() - 1) selectedProgramIndex++;
    else if (event == 2) { confirmText = "Запустить?"; currentScreen = UIScreen::CONFIRM_START; }
    else if (event == 3) { currentScreen = UIScreen::MAIN; }
    return;
  }

  if (event == 2) {
    refreshPrograms();
    selectedProgramIndex = 0;
    currentScreen = UIScreen::PROGRAM_LIST;
  } else if (event == 3 && systemState.mode == SystemState::SystemMode::RUNNING) {
    confirmText = "Остановить?";
    currentScreen = UIScreen::CONFIRM_STOP;
  }
}