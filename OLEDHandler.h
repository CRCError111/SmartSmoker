#pragma once
#include <Arduino.h>
#include <U8g2lib.h>
#include "Config.h"
#include "ProgramTypes.h"
#include <vector>
#include <memory>

enum class UIScreen { MAIN, PROGRAM_LIST, CONFIRM_START, CONFIRM_STOP };

class OLEDHandler {
public:
  OLEDHandler();
  void begin();
  void update();
  void handleButtonEvent(int event);
  std::vector<std::unique_ptr<SmokingProgram>> availablePrograms;
  void refreshPrograms();

private:
  void drawMain();
  void drawProgramList();
  void drawConfirm(const char* text);

  U8G2_SH1106_128X64_NONAME_F_HW_I2C u8g2;
  bool displayInitialized = false;
  unsigned long lastUpdate = 0;
  UIScreen currentScreen = UIScreen::MAIN;
  size_t selectedProgramIndex = 0;
  const char* confirmText = nullptr;
};