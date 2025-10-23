#pragma once
#include <Arduino.h>
#include <vector>

struct ProgramStep {
  int targetTemp = 30;
  int targetHumidity = 70;
  int durationMinutes = 60;
  int hysteresis = 2;
  bool waitForTemp = true;
  int compressorPWM = -1; // -1 = Auto
};

struct SmokingProgram {
  String name;
  std::vector<ProgramStep> steps;
  bool isBuiltIn = false;
};