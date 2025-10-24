#pragma once
#include <Arduino.h>
#include <memory>
#include "ProgramTypes.h"

class SystemState {
public:
  enum class NetworkMode { AP, STA };
  enum class SystemMode { IDLE, RUNNING };

  static SystemState& getInstance();

  NetworkMode networkMode = NetworkMode::AP;
  String ssid = "";
  String ip = "0.0.0.0";
  SystemMode mode = SystemMode::IDLE;
  std::unique_ptr<SmokingProgram> currentProgram = nullptr;
  size_t currentStepIndex = 0;
  unsigned long stepStartTime = 0;
  unsigned long programStartTime = 0;
  bool waitingForTemp = false;
  bool emergencyStop = false;

  float tempChamber = 0.0f;
  float tempSmoke = 0.0f;
  float tempProduct = 0.0f;
  float humidity = 0.0f;

  bool heaterOn = false;
  int smokePWM = 0;
  int fanPWM = 0; // ← новое

private:
  SystemState() = default;
};

// SystemState.h — в самом конце файла
inline const std::vector<SmokingProgram> builtInPrograms = {
  {"Рыба холодное копчение", {{25, 70, 240, 2, true, false, -1, 50}, {30, 65, 360, 2, true, false, -1, 50}}, true},
  {"Рыба горячее копчение", {{50, 60, 30, 2, true, false, -1, 50}, {70, 50, 60, 2, true, false, -1, 50}}, true},
  {"Мясо холодное копчение", {{22, 75, 480, 2, true, false, -1, 50}, {28, 70, 720, 2, true, false, -1, 50}}, true},
  {"Мясо горячее копчение", {{45, 60, 20, 2, true, false, -1, 50}, {65, 50, 90, 2, true, false, -1, 50}}, true}
};