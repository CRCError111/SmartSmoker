#pragma once
#include <Arduino.h>
#include "Config.h"

class ButtonHandler {
public:
  enum class Event { NONE = -1, UP = 0, DOWN = 1, OK = 2, BACK = 3 };
  void begin();
  Event readEvent();

private:
  struct Button {
    uint8_t pin;
    bool lastState;
    unsigned long lastDebounce;
  };
  Button buttons[4] = {
    {PIN_BTN_UP,    HIGH, 0},
    {PIN_BTN_DOWN,  HIGH, 0},
    {PIN_BTN_OK,    HIGH, 0},
    {PIN_BTN_BACK,  HIGH, 0}
  };
  static constexpr unsigned long DEBOUNCE_DELAY = 50;
};