/**
 * Менеджер TFT-дисплея ST7789V 320x240 (LovyanGFX)
 *
 * @file DisplayManager.h
 * @version 3.0 - ST7789V / LovyanGFX
 *
 * Все публичные сигнатуры методов сохранены — ButtonManager и SmartSmoker.ino
 * не требуют изменений.
 */

#ifndef DISPLAY_MANAGER_H
#define DISPLAY_MANAGER_H

#include <Arduino.h>
#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include <time.h>
#include "pins.h"
#include "constants.h"
#include "SystemState.h"
#include "ProgramStructures.h"
#include "SettingsMenuDefs.h"
#include "SettingsManager.h"

// =====================================================
// LGFX CONFIG — ST7789V 2.4" 320x240 SPI
// Для перехода на ST7796S 480x320 — заменить только этот класс.
// =====================================================
class LGFX_ST7789V : public lgfx::LGFX_Device {
    lgfx::Panel_ST7789  _panel_instance;
    lgfx::Bus_SPI       _bus_instance;
    lgfx::Light_PWM     _light_instance;

public:
    LGFX_ST7789V() {
        {
            auto cfg = _bus_instance.config();
            cfg.spi_host   = VSPI_HOST;
            cfg.freq_write = 40000000;
            cfg.pin_mosi   = TFT_MOSI;
            cfg.pin_miso   = TFT_MISO;
            cfg.pin_sclk   = TFT_SCK;
            cfg.pin_dc     = TFT_DC;
            cfg.spi_3wire  = false;
            _bus_instance.config(cfg);
            _panel_instance.setBus(&_bus_instance);
        }
        {
            auto cfg = _panel_instance.config();
            cfg.pin_cs     = TFT_CS;
            cfg.pin_rst    = TFT_RST;
            cfg.pin_busy   = -1;
            cfg.panel_width  = 240;
            cfg.panel_height = 320;
            cfg.offset_x     = 0;
            cfg.offset_y     = 0;
            cfg.offset_rotation = 1;  // landscape: 320 wide, 240 tall
            cfg.dummy_read_pixel = 8;
            cfg.dummy_read_bits  = 1;
            cfg.readable         = true;
            cfg.invert           = true;
            cfg.rgb_order        = false;
            cfg.dlen_16bit       = false;
            cfg.bus_shared       = false;
            _panel_instance.config(cfg);
        }
        {
            auto cfg = _light_instance.config();
            cfg.pin_bl      = TFT_BL;
            cfg.invert      = false;
            cfg.freq        = 44100;
            cfg.pwm_channel = 7;
            _light_instance.config(cfg);
            _panel_instance.setLight(&_light_instance);
        }
        setPanel(&_panel_instance);
    }
};

// =====================================================
// ЦВЕТОВАЯ ПАЛИТРА (RGB565)
// =====================================================
static constexpr uint16_t COL_BG        = 0x0000;  // Чёрный фон
static constexpr uint16_t COL_HEADER    = 0x1082;  // Тёмно-серый заголовок
static constexpr uint16_t COL_ACCENT    = 0xFD20;  // Оранжевый акцент
static constexpr uint16_t COL_OK        = 0x07E0;  // Зелёный — норма
static constexpr uint16_t COL_WARN      = 0xFFE0;  // Жёлтый — предупреждение
static constexpr uint16_t COL_ERR       = 0xF800;  // Красный — ошибка / аварийная остановка
static constexpr uint16_t COL_TEXT      = 0xFFFF;  // Белый — основной текст
static constexpr uint16_t COL_SUBTEXT   = 0xC618;  // Светло-серый — вторичный текст
static constexpr uint16_t COL_CLOUD_ON  = 0x07E0;  // Зелёный — облако подключено
static constexpr uint16_t COL_CLOUD_OFF = 0x8410;  // Серый — облако отключено


// =====================================================
// КЛАСС DisplayManager
// =====================================================
class DisplayManager {
private:
    LGFX_ST7789V _display;
    uint16_t     _w           = 320;
    uint16_t     _h           = 240;
    bool         _initialized = false;

    // ── Вспомогательные методы компоновки ────────────────────────────────────
    uint16_t _cx()      const { return _w / 2; }
    uint16_t _cy()      const { return _h / 2; }
    uint16_t _headerH() const { return _h / 8; }   // ~30 px при 240
    uint16_t _footerH() const { return _h / 3; }   // ~80 px при 240

    // ── Цвет датчика по значению ──────────────────────────────────────────────
    uint16_t _sensorColor(float val, float warnLow, float warnHigh,
                          float errLow, float errHigh) const {
        if (isnan(val))                          return COL_ERR;
        if (val < errLow  || val > errHigh)      return COL_ERR;
        if (val < warnLow || val > warnHigh)     return COL_WARN;
        return COL_OK;
    }

    // ── Вспомогательный метод: центрированный текст ───────────────────────────
    void _drawCenteredStr(const char* str, uint16_t y, uint16_t color,
                          uint8_t fontSize = 2) {
        _display.setTextColor(color);
        _display.setTextSize(fontSize);
        int16_t tw = _display.textWidth(str);
        _display.setCursor((_w - tw) / 2, y);
        _display.print(str);
    }

    // ── Заголовочная полоса ───────────────────────────────────────────────────
    void _drawHeader(const char* title, bool wifiOk, bool cloudOk, bool bound) {
        uint16_t hh = _headerH();
        _display.fillRect(0, 0, _w, hh, COL_HEADER);
        _display.setTextColor(COL_TEXT);
        _display.setTextSize(2);
        _display.setCursor(4, (hh - 16) / 2);
        _display.print(title);

        // Иконки справа
        uint16_t ix = _w - 60;
        uint16_t iy = (hh - 16) / 2;
        _display.setTextSize(1);

        if (!wifiOk) {
            _display.setTextColor(COL_ERR);
            _display.setCursor(ix, iy);
            _display.print("OFFLINE");
        } else {
            _display.setTextColor(COL_OK);
            _display.setCursor(ix, iy);
            _display.print("WiFi");

            _display.setTextColor(bound && cloudOk ? COL_CLOUD_ON : COL_CLOUD_OFF);
            _display.setCursor(ix + 36, iy);
            _display.print(bound ? "C" : "-");
        }
    }

    // ── Горизонтальный прогресс-бар ───────────────────────────────────────────
    void _drawProgressBar(uint16_t x, uint16_t y, uint16_t w, uint16_t h,
                          uint8_t pct, uint16_t color) {
        _display.drawRect(x, y, w, h, COL_SUBTEXT);
        uint16_t fill = (uint16_t)((uint32_t)pct * (w - 2) / 100);
        if (fill > 0) {
            _display.fillRect(x + 1, y + 1, fill, h - 2, color);
        }
    }

    // ── Форматирование времени HH:MM:SS ───────────────────────────────────────
    void _formatTime(char* buf, size_t sz, unsigned long secs) {
        unsigned long h = secs / 3600;
        unsigned long m = (secs % 3600) / 60;
        unsigned long s = secs % 60;
        snprintf(buf, sz, "%02lu:%02lu:%02lu", h, m, s);
    }

public:
    // ── init() ────────────────────────────────────────────────────────────────
    bool init() {
        Serial.println("Initializing ST7789V display (LovyanGFX)...");

        if (!_display.init()) {
            Serial.println("✗ Display initialization failed!");
            return false;
        }

        _w = _display.width();
        _h = _display.height();
        _display.setRotation(1);  // landscape
        _w = _display.width();
        _h = _display.height();

        _display.fillScreen(COL_BG);
        _initialized = true;

        Serial.printf("✓ Display initialized: %dx%d\n", _w, _h);
        return true;
    }

    // ── showBootScreen() ──────────────────────────────────────────────────────
    void showBootScreen() {
        if (!_initialized) return;

        _display.fillScreen(COL_BG);

        // Логотип
        _drawCenteredStr("Smart Smoker", _cy() - 30, COL_ACCENT, 3);

        // Версия прошивки
        char verBuf[32];
        snprintf(verBuf, sizeof(verBuf), "v%s", FIRMWARE_VERSION);
        _drawCenteredStr(verBuf, _cy() + 10, COL_SUBTEXT, 2);

        // Анимированный прогресс-бар
        uint16_t bx = _w / 8;
        uint16_t bw = _w * 3 / 4;
        uint16_t by = _cy() + 50;
        _drawProgressBar(bx, by, bw, 12, 80, COL_ACCENT);

        _drawCenteredStr("Initializing...", by + 18, COL_SUBTEXT, 1);
    }

    // ── showMainScreen() ──────────────────────────────────────────────────────
    void showMainScreen(const SystemState& state) {
        if (!_initialized) return;

        _display.fillScreen(COL_BG);

        bool wifiOk  = state.wifiConnected;
        bool cloudOk = state.cloudConnected;
        bool bound   = state.deviceBound;

        _drawHeader(state.deviceName.isEmpty() ? "Smart Smoker" : state.deviceName.c_str(),
                    wifiOk, cloudOk, bound);

        // ── Сетка датчиков 2x2 ────────────────────────────────────────────────
        uint16_t hh   = _headerH();
        uint16_t fh   = _footerH();
        uint16_t gridY = hh + 4;
        uint16_t gridH = _h - hh - fh - 4;
        uint16_t cw   = _w / 2;
        uint16_t ch   = gridH / 2;

        struct SensorCell {
            const char* label;
            float       value;
            const char* unit;
            float wL, wH, eL, eH;
        } cells[4] = {
            { "Камера",   state.tempChamber,  "\xC2\xB0""C",  10, 120, -10, 150 },
            { "Дым",      state.tempSmoke,    "\xC2\xB0""C",  10, 150, -10, 200 },
            { "Продукт",  state.tempProduct,  "\xC2\xB0""C",  10, 100, -10, 120 },
            { "Влажность",state.humidity,     "%",            10,  95,   0, 100 },
        };

        for (uint8_t i = 0; i < 4; i++) {
            uint16_t cx = (i % 2) * cw;
            uint16_t cy = gridY + (i / 2) * ch;
            uint16_t col = _sensorColor(cells[i].value,
                                        cells[i].wL, cells[i].wH,
                                        cells[i].eL, cells[i].eH);

            // Метка
            _display.setTextColor(COL_SUBTEXT);
            _display.setTextSize(1);
            _display.setCursor(cx + 4, cy + 4);
            _display.print(cells[i].label);

            // Значение
            char vbuf[16];
            if (isnan(cells[i].value)) {
                snprintf(vbuf, sizeof(vbuf), "--");
            } else {
                snprintf(vbuf, sizeof(vbuf), "%.1f%s", cells[i].value, cells[i].unit);
            }
            _display.setTextColor(col);
            _display.setTextSize(2);
            _display.setCursor(cx + 4, cy + 18);
            _display.print(vbuf);

            // Разделитель
            if (i % 2 == 0) {
                _display.drawFastVLine(cw, gridY, gridH, COL_HEADER);
            }
        }
        _display.drawFastHLine(0, gridY + ch, _w, COL_HEADER);

        // ── Нижняя панель ─────────────────────────────────────────────────────
        uint16_t fy = _h - fh;
        _display.fillRect(0, fy, _w, fh, COL_HEADER);

        if (state.mode == SystemState::Mode::RUNNING && state.currentProgram) {
            String pName = state.currentProgram->name;
            if (pName.length() > 22) pName = pName.substring(0, 19) + "...";

            _display.setTextColor(COL_TEXT);
            _display.setTextSize(2);
            _display.setCursor(4, fy + 4);
            _display.print(pName);

            if (state.currentStepIndex < state.currentProgram->steps.size()) {
                uint32_t stepDurSec = state.currentProgram->steps[state.currentStepIndex].durationMinutes * 60;
                uint32_t elapsed    = state.getStepRunTime();
                uint8_t  pct        = (stepDurSec > 0) ? (uint8_t)min(100UL, elapsed * 100 / stepDurSec) : 0;

                char stepBuf[32];
                snprintf(stepBuf, sizeof(stepBuf), "Шаг %d/%d",
                         state.currentStepIndex + 1,
                         (int)state.currentProgram->steps.size());
                _display.setTextColor(COL_SUBTEXT);
                _display.setTextSize(1);
                _display.setCursor(4, fy + 28);
                _display.print(stepBuf);

                _drawProgressBar(4, fy + 42, _w - 8, 10, pct, COL_ACCENT);
            }
        } else {
            _display.setTextColor(COL_SUBTEXT);
            _display.setTextSize(2);
            _display.setCursor(4, fy + 4);
            _display.print("IDLE");
            _display.setTextSize(1);
            _display.setCursor(4, fy + 28);
            _display.print("Нажмите OK для запуска");
        }
    }

    // ── showProgramRunning() ──────────────────────────────────────────────────
    void showProgramRunning(const SystemState& state) {
        if (!_initialized) return;
        if (!state.currentProgram) return;

        _display.fillScreen(COL_BG);

        // Заголовок — название программы
        String pName = state.currentProgram->name;
        if (pName.length() > 20) pName = pName.substring(0, 17) + "...";
        _drawHeader(pName.c_str(), state.wifiConnected, state.cloudConnected, state.deviceBound);

        uint16_t hh = _headerH();
        uint16_t y  = hh + 8;

        if (state.currentStepIndex >= state.currentProgram->steps.size()) return;
        const auto& step = state.currentProgram->steps[state.currentStepIndex];

        // Название шага
        _display.setTextColor(COL_TEXT);
        _display.setTextSize(2);
        _display.setCursor(4, y);
        String sName = step.stepName;
        if (sName.length() > 18) sName = sName.substring(0, 15) + "...";
        _display.print(sName);
        y += 24;

        // Индекс шага
        char stepBuf[24];
        snprintf(stepBuf, sizeof(stepBuf), "Шаг %d из %d",
                 state.currentStepIndex + 1,
                 (int)state.currentProgram->steps.size());
        _display.setTextColor(COL_SUBTEXT);
        _display.setTextSize(1);
        _display.setCursor(4, y);
        _display.print(stepBuf);
        y += 16;

        // Целевая и текущая температура
        char tgtBuf[20], curBuf[20];
        snprintf(tgtBuf, sizeof(tgtBuf), "Цель: %.1f\xC2\xB0""C", step.targetTemp);
        if (!isnan(state.tempChamber)) {
            snprintf(curBuf, sizeof(curBuf), "Факт: %.1f\xC2\xB0""C", state.tempChamber);
        } else {
            snprintf(curBuf, sizeof(curBuf), "Факт: --");
        }

        float diff = isnan(state.tempChamber) ? 999.0f : fabsf(state.tempChamber - step.targetTemp);
        uint16_t tempCol = (diff <= step.hysteresis) ? COL_OK : COL_ERR;

        _display.setTextSize(2);
        _display.setTextColor(COL_TEXT);
        _display.setCursor(4, y);
        _display.print(tgtBuf);
        _display.setTextColor(tempCol);
        _display.setCursor(_cx() + 4, y);
        _display.print(curBuf);
        y += 28;

        // Прогресс-бар времени шага
        uint32_t stepDurSec = step.durationMinutes * 60;
        uint32_t elapsed    = state.getStepRunTime();
        uint8_t  pct        = (stepDurSec > 0) ? (uint8_t)min(100UL, elapsed * 100 / stepDurSec) : 0;
        _drawProgressBar(4, y, _w - 8, 14, pct, COL_ACCENT);
        y += 20;

        // Оставшееся время
        uint32_t remaining = (elapsed < stepDurSec) ? (stepDurSec - elapsed) : 0;
        char timeBuf[12];
        _formatTime(timeBuf, sizeof(timeBuf), remaining);
        _display.setTextColor(COL_SUBTEXT);
        _display.setTextSize(1);
        _display.setCursor(4, y);
        _display.print("Осталось: ");
        _display.setTextColor(COL_TEXT);
        _display.print(timeBuf);
        y += 16;

        // Иконки актуаторов
        struct ActIcon { const char* label; bool on; } acts[] = {
            { "ТЭН",  state.heaterOn },
            { "Дым",  state.smokePWM > 0 },
            { "Вент", state.fanInternalOn },
        };
        uint16_t ix = 4;
        for (auto& a : acts) {
            uint16_t col = a.on ? COL_OK : COL_SUBTEXT;
            _display.setTextColor(col);
            _display.setTextSize(1);
            _display.setCursor(ix, y);
            _display.print(a.label);
            ix += 50;
        }
    }

    // ── showProgramMenu() ─────────────────────────────────────────────────────
    void showProgramMenu(const SystemState& state, const std::vector<String>& programNames) {
        if (!_initialized) return;

        _display.fillScreen(COL_BG);
        _drawHeader("Выбор программы", state.wifiConnected, state.cloudConnected, state.deviceBound);

        uint16_t hh       = _headerH();
        uint16_t rowH     = 32;
        uint16_t visCount = (_h - hh) / rowH;
        int      total    = (int)programNames.size();
        int      start    = max(0, state.menuIndex - (int)(visCount / 2));
        if (start + (int)visCount > total) start = max(0, total - (int)visCount);

        for (int i = start; i < min(total, start + (int)visCount); i++) {
            uint16_t ry  = hh + (i - start) * rowH;
            bool     sel = (i == state.menuIndex);

            if (sel) {
                _display.fillRect(0, ry, _w - 8, rowH - 2, COL_ACCENT);
                _display.setTextColor(COL_BG);
            } else {
                _display.setTextColor(COL_TEXT);
            }

            String name = programNames[i];
            if (name.length() > 22) name = name.substring(0, 19) + "...";
            _display.setTextSize(2);
            _display.setCursor(4, ry + 6);
            _display.print(name);
        }

        // Полоса прокрутки
        if (total > (int)visCount) {
            uint16_t trackH = _h - hh;
            uint16_t thumbH = max(10, (int)(trackH * visCount / total));
            uint16_t thumbY = hh + (uint16_t)((uint32_t)start * (trackH - thumbH) / max(1, total - (int)visCount));
            _display.fillRect(_w - 6, hh, 6, trackH, COL_HEADER);
            _display.fillRect(_w - 6, thumbY, 6, thumbH, COL_ACCENT);
        }
    }

    // ── showSettingsMenu() ────────────────────────────────────────────────────
    void showSettingsMenu(const SystemState& state) {
        if (!_initialized) return;

        const SettingsMenuState& sm = state.settingsMenu;
        _display.fillScreen(COL_BG);

        switch (sm.level) {

            // ── Список разделов ───────────────────────────────────────────────
            case SettingsMenuState::Level::SECTION_LIST: {
                _drawHeader("Настройки", state.wifiConnected, state.cloudConnected, state.deviceBound);
                uint16_t hh   = _headerH();
                uint16_t rowH = 32;
                uint8_t  vis  = (_h - hh) / rowH;

                for (uint8_t i = 0; i < MENU_SECTION_COUNT; i++) {
                    if (i < sm.scrollOffset || i >= sm.scrollOffset + vis) continue;
                    uint16_t ry  = hh + (i - sm.scrollOffset) * rowH;
                    bool     sel = (i == sm.sectionIndex);

                    if (sel) {
                        _display.fillRect(0, ry, _w - 8, rowH - 2, COL_ACCENT);
                        _display.setTextColor(COL_BG);
                    } else {
                        _display.setTextColor(COL_TEXT);
                    }
                    _display.setTextSize(2);
                    _display.setCursor(8, ry + 6);
                    _display.print(MENU_SECTIONS[i].label);
                }
                break;
            }

            // ── Список пунктов раздела ────────────────────────────────────────
            case SettingsMenuState::Level::ITEM_LIST: {
                const MenuSection& sec = MENU_SECTIONS[sm.sectionIndex];
                _drawHeader(sec.label, state.wifiConnected, state.cloudConnected, state.deviceBound);

                uint16_t hh   = _headerH();
                uint16_t rowH = 30;
                uint8_t  vis  = (_h - hh) / rowH;

                for (uint8_t i = 0; i < sec.itemCount; i++) {
                    if (i < sm.scrollOffset || i >= sm.scrollOffset + vis) continue;
                    uint16_t ry  = hh + (i - sm.scrollOffset) * rowH;
                    bool     sel = (i == sm.itemIndex);

                    if (sel) {
                        _display.fillRect(0, ry, _w - 8, rowH - 2, COL_ACCENT);
                        _display.setTextColor(COL_BG);
                    } else {
                        _display.setTextColor(COL_TEXT);
                    }
                    _display.setTextSize(1);
                    _display.setCursor(8, ry + 8);
                    _display.print(sec.items[i].label);

                    // Значение справа для BOOL
                    if (sec.items[i].type == MenuItem::Type::BOOL) {
                        bool val = false;
                        if (sm.sectionIndex == 1) {
                            if (i == 0) val = settingsManager.igniterEnabled;
                            else if (i == 1) val = settingsManager.ntcSmokeEnabled;
                            else if (i == 2) val = settingsManager.ntcProductEnabled;
                        }
                        const char* vstr = val ? "Вкл" : "Выкл";
                        int16_t vw = _display.textWidth(vstr);
                        _display.setCursor(_w - 8 - vw, ry + 8);
                        _display.print(vstr);
                    }
                }

                // Полоса прокрутки
                if (sec.itemCount > vis) {
                    uint16_t trackH = _h - hh;
                    uint16_t thumbH = max(10, (int)(trackH * vis / sec.itemCount));
                    uint16_t thumbY = hh + (uint16_t)((uint32_t)sm.scrollOffset * (trackH - thumbH) / max(1, (int)sec.itemCount - (int)vis));
                    _display.fillRect(_w - 6, hh, 6, trackH, COL_HEADER);
                    _display.fillRect(_w - 6, thumbY, 6, thumbH, COL_ACCENT);
                }
                break;
            }

            // ── Редактирование числа ──────────────────────────────────────────
            case SettingsMenuState::Level::NUMERIC_EDIT: {
                const MenuItem& item = MENU_SECTIONS[sm.sectionIndex].items[sm.itemIndex];
                _drawHeader(item.label, state.wifiConnected, state.cloudConnected, state.deviceBound);

                uint16_t hh = _headerH();

                // Большое значение по центру
                char valBuf[24];
                if (item.unit && item.unit[0] != '\0') {
                    snprintf(valBuf, sizeof(valBuf), "%ld %s", (long)sm.editValue, item.unit);
                } else {
                    snprintf(valBuf, sizeof(valBuf), "%ld", (long)sm.editValue);
                }
                _drawCenteredStr(valBuf, hh + 30, COL_ACCENT, 4);

                // Диапазон
                char rangeBuf[32];
                snprintf(rangeBuf, sizeof(rangeBuf), "%ld — %ld", (long)sm.editMin, (long)sm.editMax);
                _drawCenteredStr(rangeBuf, hh + 90, COL_SUBTEXT, 1);

                // Подсказки кнопок
                _drawCenteredStr("UP/DN: изм   OK: ок   MN: отм", _h - 20, COL_SUBTEXT, 1);
                break;
            }

            // ── Редактирование даты/времени ───────────────────────────────────
            case SettingsMenuState::Level::DATETIME_EDIT: {
                _drawHeader("Дата и время", state.wifiConnected, state.cloudConnected, state.deviceBound);
                uint16_t hh = _headerH();
                const struct tm& dt = sm.dtBuf;

                char dateBuf[16], timeBuf[10];
                snprintf(dateBuf, sizeof(dateBuf), "%04d-%02d-%02d",
                         dt.tm_year + 1900, dt.tm_mon + 1, dt.tm_mday);
                snprintf(timeBuf, sizeof(timeBuf), "%02d:%02d",
                         dt.tm_hour, dt.tm_min);

                // Дата
                _display.setTextSize(3);
                _display.setTextColor(sm.dtField < 3 ? COL_ACCENT : COL_TEXT);
                _drawCenteredStr(dateBuf, hh + 20, sm.dtField < 3 ? COL_ACCENT : COL_TEXT, 3);

                // Время
                _drawCenteredStr(timeBuf, hh + 70, sm.dtField >= 3 ? COL_ACCENT : COL_TEXT, 3);

                _drawCenteredStr("OK: след   MN: пред/отм", _h - 20, COL_SUBTEXT, 1);
                break;
            }

            // ── Диалог подтверждения ──────────────────────────────────────────
            case SettingsMenuState::Level::CONFIRM_DIALOG: {
                const MenuItem& item = MENU_SECTIONS[sm.sectionIndex].items[sm.itemIndex];
                _drawHeader("Подтверждение", state.wifiConnected, state.cloudConnected, state.deviceBound);

                uint16_t hh = _headerH();
                _drawCenteredStr(item.label, hh + 20, COL_TEXT, 2);

                // Кнопки Да / Нет
                uint16_t btnW = 100, btnH = 40;
                uint16_t btnY = _cy() + 10;

                // Да
                uint16_t yesX = _cx() - btnW - 10;
                _display.fillRect(yesX, btnY, btnW, btnH,
                                  sm.confirmYes ? COL_ACCENT : COL_HEADER);
                _display.setTextColor(sm.confirmYes ? COL_BG : COL_TEXT);
                _display.setTextSize(2);
                _display.setCursor(yesX + (btnW - _display.textWidth("Да")) / 2, btnY + 10);
                _display.print("Да");

                // Нет
                uint16_t noX = _cx() + 10;
                _display.fillRect(noX, btnY, btnW, btnH,
                                  !sm.confirmYes ? COL_ACCENT : COL_HEADER);
                _display.setTextColor(!sm.confirmYes ? COL_BG : COL_TEXT);
                _display.setCursor(noX + (btnW - _display.textWidth("Нет")) / 2, btnY + 10);
                _display.print("Нет");

                _drawCenteredStr("OK: выбрать   MN: отмена", _h - 20, COL_SUBTEXT, 1);
                break;
            }

            default:
                break;
        }
    }

    // ── showEmergencyStop() ───────────────────────────────────────────────────
    void showEmergencyStop(const String& reason) {
        if (!_initialized) return;

        _display.fillScreen(COL_ERR);

        _drawCenteredStr("! АВАРИЙНАЯ ОСТАНОВКА !", _cy() - 40, COL_TEXT, 2);

        String shortReason = reason;
        if (shortReason.length() > 28) shortReason = shortReason.substring(0, 25) + "...";
        _drawCenteredStr(shortReason.c_str(), _cy(), COL_TEXT, 2);

        _drawCenteredStr("Нажмите OK для сброса", _cy() + 50, COL_TEXT, 1);
    }

    // ── showOTAUpdate() ───────────────────────────────────────────────────────
    void showOTAUpdate(int progress, const String& error = "") {
        if (!_initialized) return;

        _display.fillScreen(COL_BG);
        _drawCenteredStr("OTA Обновление", 20, COL_ACCENT, 2);

        if (!error.isEmpty()) {
            _drawCenteredStr("Ошибка:", _cy() - 20, COL_ERR, 2);
            String shortErr = error;
            if (shortErr.length() > 28) shortErr = shortErr.substring(0, 25) + "...";
            _drawCenteredStr(shortErr.c_str(), _cy() + 10, COL_ERR, 1);
        } else {
            char pctBuf[12];
            snprintf(pctBuf, sizeof(pctBuf), "%d%%", progress);
            _drawCenteredStr(pctBuf, _cy() - 30, COL_TEXT, 3);

            _drawProgressBar(20, _cy() + 10, _w - 40, 20, (uint8_t)progress, COL_ACCENT);
            _drawCenteredStr("Пожалуйста, подождите...", _cy() + 50, COL_SUBTEXT, 1);
        }
    }

    // ── handlePowerSaving() ───────────────────────────────────────────────────
    void handlePowerSaving(SystemState& state, unsigned long currentTime) {
        if (!_initialized) return;

        unsigned long idle = currentTime - state.lastInteraction;

        if (idle >= DISPLAY_TIMEOUT) {
            // Полное выключение подсветки
            _display.setBrightness(0);
            state.displaySleeping = true;
        } else if (idle >= DISPLAY_DIM_TIME) {
            // Затемнение
            uint8_t dimVal = (uint8_t)map(DISPLAY_DIM_BRIGHTNESS, 0, 100, 0, 255);
            _display.setBrightness(dimVal);
            state.displaySleeping = false;
        } else {
            // Полная яркость
            _display.setBrightness(255);
            state.displaySleeping = false;
        }
    }

    void wakeUp(SystemState& state) {
        if (!_initialized) return;
        _display.setBrightness(255);
        state.displaySleeping = false;
        state.updateInteraction();
    }

    // ── Экраны розжига (совместимость с ButtonManager) ────────────────────────
    void showIgniterActive(uint8_t attempt, uint8_t maxAttempts) {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawCenteredStr("Розжиг...", _cy() - 30, COL_ACCENT, 3);
        if (maxAttempts > 1) {
            char buf[24];
            snprintf(buf, sizeof(buf), "Попытка %d/%d", attempt, maxAttempts);
            _drawCenteredStr(buf, _cy() + 10, COL_SUBTEXT, 2);
        }
    }

    void showIgniterSuccess() {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawCenteredStr("Розжиг успешен!", _cy() - 20, COL_OK, 2);
        _drawCenteredStr("Дымогенератор запущен", _cy() + 10, COL_SUBTEXT, 1);
    }

    void showIgniterTimeout() {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawCenteredStr("Нет ответа.", _cy() - 40, COL_WARN, 2);
        _drawCenteredStr("Подтвердите розжиг", _cy() - 10, COL_TEXT, 2);
        _drawCenteredStr("OK - продолжить", _cy() + 30, COL_OK, 1);
        _drawCenteredStr("MENU - остановить", _cy() + 46, COL_ERR, 1);
    }

    void showSmokeIgnitionWait(const SystemState& state) {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawCenteredStr("Подожгите щепу!", _cy() - 40, COL_WARN, 2);
        _drawCenteredStr("Компрессор запущен", _cy() - 10, COL_SUBTEXT, 1);

        if (state.smokeIgnitionStartTime > 0) {
            unsigned long elapsed  = (millis() - state.smokeIgnitionStartTime) / 1000;
            unsigned long timeout  = SystemState::SMOKE_IGNITION_TIMEOUT_MS / 1000;
            unsigned long remaining = (elapsed < timeout) ? (timeout - elapsed) : 0;
            char buf[24];
            unsigned long m = remaining / 60;
            unsigned long s = remaining % 60;
            snprintf(buf, sizeof(buf), "Осталось: %lu:%02lu", m, s);
            _drawCenteredStr(buf, _cy() + 20, COL_TEXT, 2);
        }
        _drawCenteredStr("OK - готово", _cy() + 60, COL_OK, 1);
    }

    // ── Вспомогательные экраны ────────────────────────────────────────────────
    void showSystemInfo(const SystemState& state) {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawHeader("Информация", state.wifiConnected, state.cloudConnected, state.deviceBound);

        uint16_t hh = _headerH();
        uint16_t y  = hh + 8;
        _display.setTextSize(1);

        auto row = [&](const char* label, const String& val) {
            _display.setTextColor(COL_SUBTEXT);
            _display.setCursor(4, y);
            _display.print(label);
            _display.setTextColor(COL_TEXT);
            _display.print(val);
            y += 18;
        };

        row("Прошивка: ", state.firmwareVersion);
        row("WiFi: ", state.wifiConnected ? state.ssid : "Не подключен");
        row("Облако: ", state.deviceBound ? "Привязано" : "Не привязано");

        unsigned long uptimeSec = millis() / 1000;
        char upBuf[24];
        snprintf(upBuf, sizeof(upBuf), "%luч %luм", uptimeSec / 3600, (uptimeSec % 3600) / 60);
        row("Работа: ", String(upBuf));
    }

    void showCloudBindScreen(const SystemState& state, const String& deviceId) {
        if (!_initialized) return;
        _display.fillScreen(COL_BG);
        _drawHeader("Привязка к облаку", state.wifiConnected, state.cloudConnected, state.deviceBound);

        uint16_t hh = _headerH();
        if (state.deviceBound) {
            _drawCenteredStr("Статус: Привязано", hh + 20, COL_OK, 2);
            _display.setTextColor(COL_SUBTEXT);
            _display.setTextSize(1);
            _display.setCursor(4, hh + 60);
            _display.print("ID: ");
            _display.print(deviceId.substring(0, 32));
        } else {
            _drawCenteredStr("Статус: Не привязано", hh + 20, COL_WARN, 2);
            _drawCenteredStr("Используйте веб-интерфейс", hh + 60, COL_SUBTEXT, 1);
        }
    }

    // ── updateDisplay() — диспетчер ───────────────────────────────────────────
    void updateDisplay(const SystemState& state) {
        if (!_initialized) return;

        // Приоритет 1: экраны розжига
        switch (state.igniterDisplayState) {
            case SystemState::IgniterDisplayState::ACTIVE:
                showIgniterActive(state.igniterAttempt,
                                  IGNITER_RETRY_ENABLED ? IGNITER_MAX_RETRIES + 1 : 1);
                return;
            case SystemState::IgniterDisplayState::SUCCESS:
                showIgniterSuccess();
                return;
            case SystemState::IgniterDisplayState::TIMEOUT:
                showIgniterTimeout();
                return;
            default:
                break;
        }

        // Приоритет 2: ручное ожидание розжига
        if (state.mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
            showSmokeIgnitionWait(state);
            return;
        }

        switch (state.displayMode) {
            case SystemState::DisplayMode::MAIN_SCREEN:
                showMainScreen(state);
                break;
            case SystemState::DisplayMode::PROGRAM_SELECTION:
                showMainScreen(state);  // fallback; вызывающий код использует showProgramMenu()
                break;
            case SystemState::DisplayMode::PROGRAM_RUNNING:
                showProgramRunning(state);
                break;
            case SystemState::DisplayMode::WIFI_SETUP:
            case SystemState::DisplayMode::BIND_DEVICE:
                showCloudBindScreen(state, state.deviceId);
                break;
            case SystemState::DisplayMode::SETTINGS_MENU:
                showSettingsMenu(state);
                break;
            case SystemState::DisplayMode::SYSTEM_INFO:
                showSystemInfo(state);
                break;
            case SystemState::DisplayMode::EMERGENCY_STOP:
                showEmergencyStop(state.emergencyReason);
                break;
            default:
                showMainScreen(state);
                break;
        }
    }

    // ── applyBrightness() — совместимость ─────────────────────────────────────
    void applyBrightness(uint8_t pct) {
        if (!_initialized) return;
        _display.setBrightness((uint8_t)map(pct, 0, 100, 0, 255));
    }
};

#endif // DISPLAY_MANAGER_H
