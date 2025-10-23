#include "ButtonHandler.h"

void ButtonHandler::begin() {
  for (int i = 0; i < 4; i++) {
    pinMode(buttons[i].pin, INPUT_PULLUP);
    buttons[i].lastState = digitalRead(buttons[i].pin);
  }
}

ButtonHandler::Event ButtonHandler::readEvent() {
  for (int i = 0; i < 4; i++) {
    bool reading = digitalRead(buttons[i].pin);
    if (reading != buttons[i].lastState) {
      buttons[i].lastDebounce = millis();
    }
    if ((millis() - buttons[i].lastDebounce) > DEBOUNCE_DELAY) {
      if (reading != buttons[i].lastState) {
        buttons[i].lastState = reading;
        if (reading == LOW) {
          return static_cast<Event>(i);
        }
      }
    }
  }
  return Event::NONE;
}