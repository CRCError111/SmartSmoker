#pragma once

#include <stdint.h>

// Compile-time menu table for the settings menu.
// Defines all 4 sections and 10 items as described in the design document.

struct MenuItem {
    const char* label;
    enum class Type : uint8_t { BOOL, NUMERIC, DATETIME, ACTION } type;
    int32_t minVal;
    int32_t maxVal;
    int32_t step;
    const char* unit;
};

struct MenuSection {
    const char* label;
    const MenuItem* items;
    uint8_t itemCount;
};

// ─── Section 0: Дата и время ─────────────────────────────────────────────────

static const MenuItem SECTION_DATETIME_ITEMS[] = {
    { "Установить дату/время", MenuItem::Type::DATETIME, 0,   0,  0, nullptr },
    { "UTC-смещение",          MenuItem::Type::NUMERIC,  -12, 14, 1, "ч"     },
};

// ─── Section 1: Оборудование ─────────────────────────────────────────────────

static const MenuItem SECTION_HARDWARE_ITEMS[] = {
    { "Поджигатель",         MenuItem::Type::BOOL, 0, 0, 0, nullptr },
    { "Датчик дыма NTC",     MenuItem::Type::BOOL, 0, 0, 0, nullptr },
    { "Датчик продукта NTC", MenuItem::Type::BOOL, 0, 0, 0, nullptr },
};

// ─── Section 2: Параметры работы ─────────────────────────────────────────────

static const MenuItem SECTION_PARAMS_ITEMS[] = {
    { "Гистерезис",           MenuItem::Type::NUMERIC, 1,  10,   1,  "°C" },
    { "Интервал телеметрии",  MenuItem::Type::NUMERIC, 30, 3600, 10, "с"  },
    { "Яркость дисплея",      MenuItem::Type::NUMERIC, 10, 100,  10, "%"  },
};

// ─── Section 3: Система ──────────────────────────────────────────────────────

static const MenuItem SECTION_SYSTEM_ITEMS[] = {
    { "Сброс настроек", MenuItem::Type::ACTION, 0, 0, 0, nullptr },
    { "Перезагрузка",   MenuItem::Type::ACTION, 0, 0, 0, nullptr },
};

// ─── Top-level menu table ─────────────────────────────────────────────────────

static const MenuSection MENU_SECTIONS[] = {
    { "Дата и время",      SECTION_DATETIME_ITEMS, 2 },
    { "Оборудование",      SECTION_HARDWARE_ITEMS, 3 },
    { "Параметры работы",  SECTION_PARAMS_ITEMS,   3 },
    { "Система",           SECTION_SYSTEM_ITEMS,   2 },
};

static constexpr uint8_t MENU_SECTION_COUNT = 4;
